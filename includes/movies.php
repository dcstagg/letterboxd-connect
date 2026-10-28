<?php
/**
 * Movie posts from diary entries: finding the post an entry already has,
 * creating one when it has none, and filling in what's missing.
 *
 * Rules that keep imports from damaging the site:
 * - A post that exists is never rewritten. Only empty fields are filled, and
 *   a watch date 1.x got wrong is corrected. Edits made by hand stay.
 * - A post's watch date counts as right ("dated") once 2.0 has made the post
 *   or matched an entry to it (it has a guid, or _lbc_dated). Only posts
 *   that aren't dated yet, from 1.x, are matched by the dates 1.x stored.
 * - A movie in the trash counts as existing, so deleting one sticks.
 * - Each diary entry is one post. Rewatches are separate entries.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Every movie post, indexed the ways an entry can be matched to it. Built
 * once per run with two queries, so a 1,000-row CSV doesn't cost 1,000
 * lookups. Movies deleted for good are in it too (see lbc_remember_deleted()),
 * with negative IDs, so they aren't imported again.
 */
final class LBC_Movie_Index {

	/** @var array<int, array{status: string, title: string, year: string, watched: string, rating: string, guid: string, url: string, dated: bool}> */
	private array $movies = [];

	/** @var array<string, int> guid key => post ID */
	private array $by_guid = [];

	/** @var array<string, int> "url|date" => post ID */
	private array $by_url_date = [];

	/** @var array<string, int[]> "title key|year" => post IDs */
	private array $by_title_year = [];

	/** @var array<string, int[]> title key => post IDs, any year */
	private array $by_title = [];

	public static function load(): self {
		global $wpdb;
		$index = new self();
		$rows  = $wpdb->get_results(
			"SELECT p.ID, p.post_title, p.post_status,
				MAX( CASE WHEN m.meta_key = 'letterboxd_guid' THEN m.meta_value END ) AS guid,
				MAX( CASE WHEN m.meta_key = 'letterboxd_url' THEN m.meta_value END ) AS url,
				MAX( CASE WHEN m.meta_key = 'watch_date' THEN m.meta_value END ) AS watched,
				MAX( CASE WHEN m.meta_key = 'movie_year' THEN m.meta_value END ) AS year,
				MAX( CASE WHEN m.meta_key = 'movie_rating' THEN m.meta_value END ) AS rating,
				MAX( CASE WHEN m.meta_key = '_lbc_dated' THEN m.meta_value END ) AS dated,
				MAX( CASE WHEN m.meta_key = '_lbc_source' THEN m.meta_value END ) AS source
			FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key IN ( 'letterboxd_guid', 'letterboxd_url', 'watch_date', 'movie_year', 'movie_rating', '_lbc_dated', '_lbc_source' )
			WHERE p.post_type = 'movie' AND p.post_status NOT IN ( 'auto-draft', 'inherit' )
			GROUP BY p.ID"
		);
		foreach ( (array) $rows as $row ) {
			$index->add( (int) $row->ID, [
				'status'  => (string) $row->post_status,
				'title'   => (string) $row->post_title,
				'year'    => (string) $row->year,
				'watched' => (string) $row->watched,
				'rating'  => (string) $row->rating,
				'guid'    => (string) $row->guid,
				'url'     => (string) $row->url,
				'dated'   => (string) $row->guid !== '' || (string) $row->dated !== '',
				'source'  => lbc_source_array( maybe_unserialize( (string) $row->source ) ),
			] );
		}
		foreach ( lbc_deleted_movies() as $i => $movie ) {
			$dated = (bool) ( $movie['dated'] ?? ( ( $movie['guid'] ?? '' ) !== '' ) );
			$index->add( -1 - $i, array_merge( $movie, [ 'status' => 'deleted', 'rating' => '', 'dated' => $dated, 'source' => lbc_source_array( $movie['source'] ?? [] ) ] ) );
		}
		return $index;
	}

	/** Add or refresh one post (after it's created or changed). */
	public function add( int $post_id, array $movie ): void {
		$movie = array_merge( $this->movies[ $post_id ] ?? [ 'status' => 'publish', 'title' => '', 'year' => '', 'watched' => '', 'rating' => '', 'guid' => '', 'url' => '', 'dated' => false, 'source' => [] ], $movie );
		$this->movies[ $post_id ] = $movie;
		$guid = lbc_guid_key( $movie['guid'] );
		if ( $guid !== '' ) {
			$this->by_guid[ $guid ] = $post_id;
		}
		if ( $movie['url'] !== '' && $movie['watched'] !== '' ) {
			$this->by_url_date[ self::url_key( $movie['url'] ) . '|' . $movie['watched'] ] = $post_id;
		}
		// Under its title and year now, and as Letterboxd had them (before a hand edit or TMDB's year).
		$names = [ [ $movie['title'], $movie['year'] ] ];
		if ( isset( $movie['source']['title'] ) ) {
			$names[] = [ $movie['source']['title'], $movie['source']['year'] ?? '' ];
		}
		foreach ( $names as [ $title, $year ] ) {
			$key = lbc_title_key( (string) $title );
			self::put( $this->by_title_year, "$key|$year", $post_id );
			self::put( $this->by_title, $key, $post_id );
		}
	}

	private static function put( array &$list, string $key, int $post_id ): void {
		if ( ! in_array( $post_id, $list[ $key ] ?? [], true ) ) {
			$list[ $key ][] = $post_id;
		}
	}

	public function get( int $post_id ): ?array {
		return $this->movies[ $post_id ] ?? null;
	}

	/**
	 * The post an entry already has, or 0 (negative for a movie deleted for
	 * good). Tried in order of certainty: the diary entry's ID; its URL and
	 * date; then title, year and date (how a CSV row finds a post the feed
	 * made, and the reverse). Each tries the day watched first, then the
	 * dates 1.x may have stored, against 1.x posts only, so a rewatch a day
	 * later can't take another watch's place.
	 *
	 * @param array<int, true> $taken  Posts already matched to another entry in
	 *                                 this run: a same-day rewatch is a new post.
	 * @param bool             $strict Matches by title and date also need the
	 *                                 same rating (or none on one side), so
	 *                                 same-day rewatches pair up right.
	 */
	public function find( array $entry, array $taken = [], bool $strict = false ): int {
		$guid = lbc_guid_key( $entry['guid'] );
		if ( $guid !== '' && isset( $this->by_guid[ $guid ] ) ) {
			return $this->by_guid[ $guid ];
		}
		$short_url  = self::short_url( $entry['url'] );
		$same_entry = function ( int $id ) use ( $guid, $short_url ): bool {
			$movie = $this->movies[ $id ];
			// A different diary entry ID, or a different boxd.it link (each diary
			// entry in an export has its own), is a different watch.
			if ( $guid !== '' && $movie['guid'] !== '' && lbc_guid_key( $movie['guid'] ) !== $guid ) {
				return false;
			}
			$other = self::short_url( $movie['url'] );
			return $short_url === '' || $other === '' || $short_url === $other;
		};

		$watched = $entry['watched'];
		$legacy  = array_values( array_diff( array_unique( array_filter( $entry['legacy_dates'] ) ), [ $watched ] ) );
		$fits    = fn( int $id, string $date ) => ! isset( $taken[ $id ] ) && $same_entry( $id ) && ( $date === $watched || ! $this->movies[ $id ]['dated'] );
		// The day it was watched as the post has it, or as Letterboxd had it (before a hand edit).
		$on      = fn( int $id, string $date ) => $this->movies[ $id ]['watched'] === $date || ( $date === $watched && ( $this->movies[ $id ]['source']['watched'] ?? '' ) === $date );

		if ( $entry['url'] !== '' ) {
			foreach ( array_merge( [ $watched ], $legacy ) as $date ) {
				$id = $this->by_url_date[ self::url_key( $entry['url'] ) . '|' . $date ] ?? 0;
				// The post's date may have been corrected since it was indexed under this one.
				if ( $id && $on( $id, $date ) && $fits( $id, $date ) ) {
					return $id;
				}
			}
		}
		$films  = $this->with_title( $entry['title'], $entry['year'] );
		$rating = fn( int $id ) => ! $strict || $entry['rating'] === '' || $this->movies[ $id ]['rating'] === '' || $this->movies[ $id ]['rating'] === $entry['rating'];
		foreach ( array_merge( [ $watched ], $legacy ) as $date ) {
			foreach ( $films as $id ) {
				if ( $on( $id, $date ) && $fits( $id, $date ) && $rating( $id ) ) {
					return $id;
				}
			}
		}
		return 0;
	}

	/**
	 * Posts of this film (any watch, including deleted ones): with this title
	 * and year, or with no year, or any year when $year is ''.
	 *
	 * @return int[]
	 */
	public function with_title( string $title, string $year ): array {
		$key = lbc_title_key( $title );
		if ( $year === '' ) {
			return $this->by_title[ $key ] ?? [];
		}
		return array_values( array_unique( array_merge( $this->by_title_year[ "$key|$year" ] ?? [], $this->by_title_year[ "$key|" ] ?? [] ) ) );
	}

	/** letterboxd.com URLs compared without scheme, "www." or trailing slash. */
	private static function url_key( string $url ): string {
		$url = strtolower( trim( $url ) );
		$url = (string) preg_replace( '~^https?://(www\.)?~', '', $url );
		return rtrim( $url, '/' );
	}

	/** A boxd.it link's key, or '' for any other URL. */
	private static function short_url( string $url ): string {
		$url = self::url_key( $url );
		return str_starts_with( $url, 'boxd.it/' ) ? $url : '';
	}
}

/**
 * A diary entry's ID without its prefix: Letterboxd's guid is
 * "letterboxd-watch-123" until the entry gets a review, then
 * "letterboxd-review-123".
 */
function lbc_guid_key( string $guid ): string {
	return (string) preg_replace( '/^letterboxd-(?:watch|review|entry)-/', '', trim( $guid ) );
}

/**
 * What Letterboxd last said about a movie (_lbc_source): its title, year and
 * day watched, its rating, and its review's text (as lbc_text_hash()), plus
 * the post content as the plugin last wrote it (markup, lbc_markup_hash()). Kept
 * so that an import can tell a change made on Letterboxd, which it copies to
 * the post, from an edit made by hand, which it leaves alone; and so a movie
 * renamed by hand is still found by its Letterboxd title.
 */
function lbc_source_array( $value ): array {
	return is_array( $value ) ? array_map( 'strval', array_intersect_key( $value, array_flip( [ 'title', 'year', 'watched', 'rating', 'text', 'markup' ] ) ) ) : [];
}

function lbc_entry_source( array $entry ): array {
	return [
		'title'   => $entry['title'],
		'year'    => $entry['year'],
		'watched' => $entry['watched'],
		'rating'  => $entry['rating'],
		'text'    => lbc_text_hash( lbc_entry_content( $entry ) ),
	];
}

/** The post content for an entry: its review, or "Watched on …". */
function lbc_entry_content( array $entry ): string {
	return $entry['content'] !== '' ? $entry['content'] : lbc_watched_on_blocks( $entry['watched'] );
}

/** The post content as written, to tell whether it was edited at all (an image added counts). */
function lbc_markup_hash( string $content ): string {
	return md5( trim( (string) preg_replace( '/\s+/u', ' ', $content ) ) );
}

/** A review reduced to its words, to tell whether Letterboxd's changed: markup and spacing don't count. */
function lbc_text_hash( string $content ): string {
	$text = html_entity_decode( wp_strip_all_tags( $content ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	return md5( trim( (string) preg_replace( '/\s+/u', ' ', $text ) ) );
}

/**
 * Movies deleted for good, remembered (by entry ID, URL, title, year and
 * date) so the next import doesn't bring them back. Trashed movies need no
 * record: they're still in the database.
 *
 * @return array[]
 */
function lbc_deleted_movies(): array {
	$deleted = get_option( 'lbc_deleted', [] );
	return is_array( $deleted ) ? array_values( array_filter( $deleted, 'is_array' ) ) : [];
}

function lbc_remember_deleted( int $post_id, WP_Post $post ): void {
	if ( $post->post_type !== 'movie' || in_array( $post->post_status, [ 'auto-draft', 'inherit' ], true ) ) {
		return;
	}
	$deleted   = lbc_deleted_movies();
	$deleted[] = [
		'guid'    => (string) get_post_meta( $post_id, 'letterboxd_guid', true ),
		'url'     => (string) get_post_meta( $post_id, 'letterboxd_url', true ),
		'title'   => (string) $post->post_title,
		'year'    => (string) get_post_meta( $post_id, 'movie_year', true ),
		'watched' => (string) get_post_meta( $post_id, 'watch_date', true ),
		'dated'   => get_post_meta( $post_id, 'letterboxd_guid', true ) !== '' || get_post_meta( $post_id, '_lbc_dated', true ) !== '',
		'source'  => lbc_source_array( get_post_meta( $post_id, '_lbc_source', true ) ),
	];
	// A few thousand is plenty; the oldest go first.
	update_option( 'lbc_deleted', array_slice( $deleted, -5000 ), false );
}
add_action( 'before_delete_post', 'lbc_remember_deleted', 10, 2 );

/**
 * Create the post for a diary entry: published (or draft, per settings),
 * dated the day it was watched, with its rating, year and Letterboxd link.
 * Poster and TMDB details are added after; if those fail the post still
 * stands and the background job tries again.
 *
 * @return int|WP_Error The new post's ID.
 */
function lbc_create_movie( array $entry, array $settings ): int|WP_Error {
	$post_date_gmt = lbc_entry_post_date_gmt( $entry );
	$meta          = array_filter( [
		'letterboxd_guid' => $entry['guid'],
		'letterboxd_url'  => $entry['url'],
		'watch_date'      => $entry['watched'],
		'movie_year'      => $entry['year'],
		'movie_rating'    => $entry['rating'],
		'tmdb_id'         => $entry['tmdb_id'],
		'poster_url'      => $entry['poster'],
		'_lbc_dated'      => '1',
	], 'strlen' );
	$meta['_lbc_source'] = lbc_entry_source( $entry );

	$postarr = [
		'post_type'     => 'movie',
		'post_status'   => $settings['draft'] ? 'draft' : 'publish',
		'post_title'    => $entry['title'],
		'post_content'  => lbc_entry_content( $entry ),
		'post_author'   => lbc_author_id(),
		'post_date'     => get_date_from_gmt( $post_date_gmt ),
		'post_date_gmt' => $post_date_gmt,
		'meta_input'    => $meta,
	];

	// The title and content were sanitised when the entry was built. kses
	// (on when cron runs with no user) would turn "&" in titles into "&amp;".
	$kses = has_filter( 'content_save_pre', 'wp_filter_post_kses' );
	if ( $kses ) {
		kses_remove_filters();
	}
	$post_id = wp_insert_post( wp_slash( $postarr ), true );
	if ( $kses ) {
		kses_init_filters();
	}
	if ( is_wp_error( $post_id ) ) {
		return $post_id;
	}
	if ( $entry['year'] !== '' ) {
		wp_set_object_terms( $post_id, [ $entry['year'] ], 'movie_year' );
	}
	// The content as saved (filters may have changed it), so later edits to it can be told apart.
	update_post_meta( $post_id, '_lbc_source', wp_slash( $meta['_lbc_source'] + [ 'markup' => lbc_markup_hash( (string) get_post_field( 'post_content', $post_id, 'raw' ) ) ] ) );
	return $post_id;
}

/**
 * When the post is dated: the day it was watched, at the time of day it was
 * logged (in the site's timezone), or noon. Never in the future, which would
 * schedule the post instead of publishing it.
 */
function lbc_entry_post_date_gmt( array $entry ): string {
	$time = '12:00:00';
	if ( $entry['published'] ) {
		$time = wp_date( 'H:i:s', $entry['published'] );
	}
	$gmt = get_gmt_from_date( $entry['watched'] . ' ' . $time );
	return min( $gmt, gmdate( 'Y-m-d H:i:s' ) );
}

/**
 * Fill in what an existing post is missing from an entry. Returns the fields
 * changed. Never touches title, content, status or dates, except to correct
 * a watch date 1.x stored wrongly (the UTC day it was logged). Afterwards
 * the post is dated: matched to an entry, its watch date is known right.
 *
 * @return string[] Meta keys changed.
 */
function lbc_fill_movie( int $post_id, array $entry, LBC_Movie_Index $index ): array {
	$movie = $index->get( $post_id );
	if ( $post_id < 1 || ! $movie || in_array( $movie['status'], [ 'trash', 'deleted' ], true ) ) {
		return [];
	}
	$changes = [];
	if ( $movie['guid'] === '' && $entry['guid'] !== '' ) {
		$changes['letterboxd_guid'] = $entry['guid'];
	}
	if ( $movie['url'] === '' && $entry['url'] !== '' ) {
		$changes['letterboxd_url'] = $entry['url'];
	}
	if ( $movie['rating'] === '' && $entry['rating'] !== '' ) {
		$changes['movie_rating'] = $entry['rating'];
	}
	if ( $movie['year'] === '' && $entry['year'] !== '' ) {
		$changes['movie_year'] = $entry['year'];
	}
	if ( $movie['watched'] === '' || ( ! $movie['dated'] && $movie['watched'] !== $entry['watched'] && in_array( $movie['watched'], $entry['legacy_dates'], true ) ) ) {
		$changes['watch_date'] = $entry['watched'];
	}
	if ( $entry['tmdb_id'] !== '' && get_post_meta( $post_id, 'tmdb_id', true ) === '' ) {
		$changes['tmdb_id'] = $entry['tmdb_id'];
	}
	if ( $entry['poster'] !== '' && get_post_meta( $post_id, 'poster_url', true ) === '' ) {
		$changes['poster_url'] = $entry['poster'];
	}
	if ( ! $movie['dated'] ) {
		update_post_meta( $post_id, '_lbc_dated', '1' );
		$index->add( $post_id, [ 'dated' => true ] );
	}
	if ( ! $changes ) {
		return lbc_sync_movie( $post_id, $entry, $index );
	}

	foreach ( $changes as $key => $value ) {
		update_post_meta( $post_id, $key, wp_slash( $value ) );
	}
	if ( isset( $changes['movie_year'] ) ) {
		wp_set_object_terms( $post_id, [ $changes['movie_year'] ], 'movie_year' );
	}
	$indexed = [];
	foreach ( [ 'letterboxd_guid' => 'guid', 'letterboxd_url' => 'url', 'movie_rating' => 'rating', 'movie_year' => 'year', 'watch_date' => 'watched' ] as $key => $field ) {
		if ( isset( $changes[ $key ] ) ) {
			$indexed[ $field ] = $changes[ $key ];
		}
	}
	$index->add( $post_id, $indexed );
	lbc_movie_changed( $post_id );
	return array_merge( array_keys( $changes ), lbc_sync_movie( $post_id, $entry, $index ) );
}

/**
 * Bring a post up to date with a diary entry changed on Letterboxd since it
 * was imported (a review written or edited, a rating changed, the date
 * corrected), field by field, where the post still has what Letterboxd had
 * before. A field edited by hand is left alone. Only for entries matched by
 * their diary entry ID, so it's certainly the same entry.
 *
 * @return string[] What changed: rating, watched, text.
 */
function lbc_sync_movie( int $post_id, array $entry, LBC_Movie_Index $index ): array {
	$movie = $index->get( $post_id );
	$post  = $post_id > 0 ? get_post( $post_id ) : null;
	if ( ! $movie || ! $post || $entry['guid'] === '' || lbc_guid_key( $movie['guid'] ) !== lbc_guid_key( $entry['guid'] ) || in_array( $movie['status'], [ 'trash', 'deleted' ], true ) ) {
		return [];
	}
	$source  = $movie['source'];
	$wanted  = lbc_entry_source( $entry );
	$markup  = lbc_markup_hash( $post->post_content );
	$current = [ 'rating' => $movie['rating'], 'watched' => $movie['watched'], 'text' => lbc_text_hash( $post->post_content ) ];
	// Untouched since the plugin wrote it: the whole content when that's known, else its words.
	$untouched = [
		'rating'  => $current['rating'] === ( $source['rating'] ?? null ),
		'watched' => $current['watched'] === ( $source['watched'] ?? null ),
		'text'    => isset( $source['markup'] ) ? $markup === $source['markup'] : $current['text'] === ( $source['text'] ?? null ),
	];
	$changed = [];
	foreach ( $current as $field => $value ) {
		if ( $untouched[ $field ] && $value !== $wanted[ $field ] ) {
			$changed[] = $field;
		}
		// Letterboxd's latest is always what's remembered, once the field is followed at all
		// (a field that doesn't match yet, on a post imported before this was kept, isn't).
		if ( isset( $source[ $field ] ) || $value === $wanted[ $field ] ) {
			$source[ $field ] = $wanted[ $field ];
		}
	}
	if ( ! isset( $source['markup'] ) && $current['text'] === $wanted['text'] ) {
		$source['markup'] = $markup;
	}
	$source += [ 'title' => $wanted['title'], 'year' => $wanted['year'] ];

	if ( in_array( 'rating', $changed, true ) ) {
		$entry['rating'] === '' ? delete_post_meta( $post_id, 'movie_rating' ) : update_post_meta( $post_id, 'movie_rating', $entry['rating'] );
	}
	$postarr = [ 'ID' => $post_id ];
	if ( in_array( 'text', $changed, true ) ) {
		$postarr['post_content'] = lbc_entry_content( $entry );
	}
	if ( in_array( 'watched', $changed, true ) ) {
		update_post_meta( $post_id, 'watch_date', $entry['watched'] );
		$gmt      = lbc_entry_post_date_gmt( $entry );
		$postarr += [ 'post_date' => get_date_from_gmt( $gmt ), 'post_date_gmt' => $gmt, 'edit_date' => true ];
	}
	if ( count( $postarr ) > 1 ) {
		$kses = has_filter( 'content_save_pre', 'wp_filter_post_kses' );
		if ( $kses ) {
			kses_remove_filters();
		}
		wp_update_post( wp_slash( $postarr ) );
		if ( $kses ) {
			kses_init_filters();
		}
		if ( isset( $postarr['post_content'] ) ) {
			$source['markup'] = lbc_markup_hash( (string) get_post_field( 'post_content', $post_id, 'raw' ) );
		}
	}
	if ( $source !== $movie['source'] ) {
		update_post_meta( $post_id, '_lbc_source', wp_slash( $source ) );
	}
	$index->add( $post_id, [
		'source'  => $source,
		'rating'  => in_array( 'rating', $changed, true ) ? $entry['rating'] : $movie['rating'],
		'watched' => in_array( 'watched', $changed, true ) ? $entry['watched'] : $movie['watched'],
	] );
	if ( $changed ) {
		lbc_movie_changed( $post_id );
	}
	return $changed;
}

/**
 * Pages showing the movie are cached (LiteSpeed, the theme's REST cache);
 * meta changes don't clear them on their own.
 */
function lbc_movie_changed( int $post_id ): void {
	clean_post_cache( $post_id );
	do_action( 'litespeed_purge_post', $post_id );
}

/**
 * Give a movie its poster: Letterboxd's if the entry had one, TMDB's if
 * there's none or it won't download.
 *
 * @return true|WP_Error True if it has one now (or already had).
 */
function lbc_attach_poster( int $post_id ): true|WP_Error {
	if ( ! lbc_needs_poster( $post_id ) ) {
		return true;
	}
	// A rewatch shares the first watch's poster rather than downloading it again.
	$shared = lbc_poster_of_same_film( $post_id );
	$urls   = array_filter( [
		(string) get_post_meta( $post_id, 'poster_url', true ),
		lbc_tmdb_poster_url( (string) get_post_meta( $post_id, 'tmdb_poster_path', true ) ),
	] );
	if ( ! $shared && ! $urls ) {
		return new WP_Error( 'lbc_no_poster', 'No poster to use.' );
	}
	// An import and the background job can reach the same new movie at once.
	if ( ! lbc_lock( 'poster_' . $post_id, 120 ) ) {
		return new WP_Error( 'lbc_busy', 'Its poster is being added already.' );
	}
	try {
		wp_cache_delete( $post_id, 'post_meta' );
		if ( has_post_thumbnail( $post_id ) ) {
			return true;
		}
		if ( $shared && set_post_thumbnail( $post_id, $shared ) ) {
			lbc_movie_changed( $post_id );
			return true;
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$title         = (string) get_post_field( 'post_title', $post_id, 'raw' );
		$attachment_id = new WP_Error( 'lbc_no_poster', 'No poster to use.' );
		foreach ( $urls as $url ) {
			$attachment_id = lbc_sideload_poster( $url, $post_id, $title );
			if ( ! is_wp_error( $attachment_id ) ) {
				break;
			}
		}
		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}
		$year    = (string) get_post_meta( $post_id, 'movie_year', true );
		$alt     = $year !== '' ? sprintf( 'Poster for %s (%s)', $title, $year ) : sprintf( 'Poster for %s', $title );
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', wp_slash( sanitize_text_field( $alt ) ) );
		set_post_thumbnail( $post_id, $attachment_id );
	} finally {
		lbc_unlock( 'poster_' . $post_id );
	}
	lbc_movie_changed( $post_id );
	return true;
}

/** @return int|WP_Error The new attachment's ID. */
function lbc_sideload_poster( string $url, int $post_id, string $title ): int|WP_Error {
	$tmp = download_url( $url, 20 );
	if ( is_wp_error( $tmp ) ) {
		return $tmp;
	}
	$ext  = strtolower( pathinfo( (string) wp_parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
	$file = [
		// The real type is checked from the file itself (and the name fixed) by media_handle_sideload().
		'name'     => sanitize_file_name( ( sanitize_title( $title ) ?: 'movie' ) . '-poster.' . ( in_array( $ext, [ 'jpg', 'jpeg', 'png', 'webp', 'avif', 'gif' ], true ) ? $ext : 'jpg' ) ),
		'tmp_name' => $tmp,
	];
	$attachment_id = media_handle_sideload( $file, $post_id, $title );
	if ( is_wp_error( $attachment_id ) ) {
		wp_delete_file( $tmp );
	}
	return $attachment_id;
}

/**
 * Fill a movie's empty fields from TMDB, finding its TMDB ID by title and
 * year first if it has none. Never replaces a value that's already there.
 *
 * @return true|WP_Error
 */
function lbc_fill_from_tmdb( int $post_id ): true|WP_Error {
	$tmdb_id = (int) get_post_meta( $post_id, 'tmdb_id', true );
	if ( ! $tmdb_id ) {
		// The raw title: get_the_title() adds "Private: " and curly quotes.
		$found = lbc_tmdb_search( html_entity_decode( (string) get_post_field( 'post_title', $post_id, 'raw' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ), (string) get_post_meta( $post_id, 'movie_year', true ) );
		if ( is_wp_error( $found ) ) {
			return $found;
		}
		if ( ! $found ) {
			return new WP_Error( 'lbc_tmdb_no_match', 'TMDB has no film with exactly this title and year.' );
		}
		$tmdb_id = $found;
	}

	$movie = lbc_tmdb_movie( $tmdb_id );
	if ( is_wp_error( $movie ) ) {
		return $movie;
	}
	$changed = false;
	foreach ( lbc_tmdb_meta( $movie ) as $key => $value ) {
		if ( (string) get_post_meta( $post_id, $key, true ) === '' ) {
			update_post_meta( $post_id, $key, wp_slash( $value ) );
			$changed = true;
			if ( $key === 'movie_year' ) {
				wp_set_object_terms( $post_id, [ $value ], 'movie_year' );
			}
		}
	}
	if ( $changed ) {
		lbc_movie_changed( $post_id );
	}
	return true;
}

/** True if a movie still lacks something TMDB or a poster could give it. */
function lbc_movie_incomplete( int $post_id ): bool {
	return lbc_needs_poster( $post_id ) || get_post_meta( $post_id, 'director', true ) === '' || get_post_meta( $post_id, 'imdb_id', true ) === '';
}

/** No poster, and not because one was removed by hand (_lbc_no_poster, see post-type.php). */
function lbc_needs_poster( int $post_id ): bool {
	return ! has_post_thumbnail( $post_id ) && get_post_meta( $post_id, '_lbc_no_poster', true ) === '';
}

/** @return int The poster of another watch of the same film (same TMDB ID or poster), or 0. */
function lbc_poster_of_same_film( int $post_id ): int {
	$same = array_filter( [
		'tmdb_id'    => (string) get_post_meta( $post_id, 'tmdb_id', true ),
		'poster_url' => (string) get_post_meta( $post_id, 'poster_url', true ),
	], 'strlen' );
	if ( ! $same ) {
		return 0;
	}
	$clauses = [ 'relation' => 'OR' ];
	foreach ( $same as $key => $value ) {
		$clauses[] = [ 'key' => $key, 'value' => $value ];
	}
	$ids = get_posts( [
		'post_type'        => 'movie',
		'post_status'      => 'any',
		'post__not_in'     => [ $post_id ],
		'fields'           => 'ids',
		'posts_per_page'   => 1,
		'no_found_rows'    => true,
		'suppress_filters' => true,
		'meta_query'       => [ 'relation' => 'AND', $clauses, [ 'key' => '_thumbnail_id', 'compare' => 'EXISTS' ] ],
	] );
	$attachment_id = $ids ? (int) get_post_thumbnail_id( $ids[0] ) : 0;
	return $attachment_id && wp_attachment_is_image( $attachment_id ) ? $attachment_id : 0;
}
