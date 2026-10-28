<?php
/**
 * The Movie post type (/movies/), its Year taxonomy (/movie-year/2024/), the
 * meta fields an imported movie carries, and the Movies admin list.
 *
 * Meta read by the site's theme: letterboxd_url, watch_date (YYYY-MM-DD),
 * movie_year, movie_rating ("3.5", out of 5) and director. The rest is for
 * this plugin: letterboxd_guid (the diary entry's ID, for matching), tmdb_id,
 * imdb_id, poster_url, streaming_link and the tmdb_* details.
 */

defined( 'ABSPATH' ) || exit;

function lbc_register_post_type(): void {
	register_post_type( 'movie', [
		'labels'        => [
			'name'                  => 'Movies',
			'singular_name'         => 'Movie',
			'add_new_item'          => 'Add New Movie',
			'edit_item'             => 'Edit Movie',
			'new_item'              => 'New Movie',
			'view_item'             => 'View Movie',
			'view_items'            => 'View Movies',
			'search_items'          => 'Search Movies',
			'not_found'             => 'No movies found',
			'not_found_in_trash'    => 'No movies found in Trash',
			'all_items'             => 'All Movies',
			'archives'              => 'Movie Archives',
			'featured_image'        => 'Movie Poster',
			'set_featured_image'    => 'Set movie poster',
			'remove_featured_image' => 'Remove movie poster',
			'use_featured_image'    => 'Use as movie poster',
		],
		'public'        => true,
		'has_archive'   => true,
		'rewrite'       => [ 'slug' => 'movies', 'with_front' => true, 'feeds' => true ],
		'show_in_rest'  => true,
		'menu_icon'     => 'dashicons-video-alt2',
		'menu_position' => 5,
		'supports'      => [ 'title', 'editor', 'thumbnail', 'excerpt', 'custom-fields', 'revisions' ],
		'taxonomies'    => [ 'movie_year' ],
	] );
}
add_action( 'init', 'lbc_register_post_type' );

/** Release years, kept in step with the movie_year field by the importer. */
function lbc_register_taxonomy(): void {
	register_taxonomy( 'movie_year', 'movie', [
		'labels'            => [
			'name'          => 'Years',
			'singular_name' => 'Year',
			'search_items'  => 'Search Years',
			'all_items'     => 'All Years',
			'edit_item'     => 'Edit Year',
			'update_item'   => 'Update Year',
			'add_new_item'  => 'Add New Year',
			'new_item_name' => 'New Year',
		],
		'public'            => true,
		'show_in_rest'      => true,
		'show_admin_column' => true,
		'rewrite'           => [ 'slug' => 'movie-year' ],
	] );
}
add_action( 'init', 'lbc_register_taxonomy' );

/** meta key => sanitize callback. All single strings, editable in the block editor. */
function lbc_meta_fields(): array {
	$url    = fn( $value ) => esc_url_raw( trim( (string) $value ) );
	$digits = fn( $value ) => preg_match( '/^\d+$/', trim( (string) $value ) ) ? trim( (string) $value ) : '';
	return [
		'letterboxd_url'  => $url,
		'letterboxd_guid' => fn( $value ) => sanitize_text_field( (string) $value ),
		'watch_date'      => fn( $value ) => lbc_valid_date( trim( (string) $value ) ) ? trim( (string) $value ) : '',
		'movie_year'      => fn( $value ) => preg_match( '/^\d{4}$/', trim( (string) $value ) ) ? trim( (string) $value ) : '',
		'movie_rating'    => fn( $value ) => lbc_normalize_rating( (string) $value ),
		'director'        => fn( $value ) => sanitize_text_field( (string) $value ),
		'tmdb_id'         => $digits,
		'imdb_id'         => fn( $value ) => preg_match( '/^tt\d+$/', trim( (string) $value ) ) ? trim( (string) $value ) : '',
		'poster_url'      => $url,
		'streaming_link'  => $url,
	];
}

function lbc_register_meta(): void {
	foreach ( lbc_meta_fields() as $key => $sanitize ) {
		register_post_meta( 'movie', $key, [
			'type'              => 'string',
			'single'            => true,
			'default'           => '',
			'show_in_rest'      => true,
			'sanitize_callback' => $sanitize,
			'auth_callback'     => fn( $allowed, $meta_key, $post_id ) => current_user_can( 'edit_post', $post_id ),
		] );
	}
}
add_action( 'init', 'lbc_register_meta' );

/** The movie archive: 24 a page, newest first. */
function lbc_archive_query( WP_Query $query ): void {
	if ( ! is_admin() && $query->is_main_query() && $query->is_post_type_archive( 'movie' ) ) {
		$query->set( 'posts_per_page', 24 );
		$query->set( 'orderby', 'date' );
		$query->set( 'order', 'DESC' );
	}
}
add_action( 'pre_get_posts', 'lbc_archive_query' );

/**
 * Posters are shown at 2:3 by the Movie Grid block. The crop is only made for
 * images attached to a movie, not for every upload on the site.
 */
function lbc_register_image_size(): void {
	add_image_size( 'movie-poster', 600, 900, true );
}
add_action( 'after_setup_theme', 'lbc_register_image_size' );

function lbc_limit_poster_size( array $sizes, $metadata, $attachment_id ): array {
	$attachment_id = (int) $attachment_id;
	if ( isset( $sizes['movie-poster'] ) && get_post_type( wp_get_post_parent_id( $attachment_id ) ) !== 'movie' ) {
		unset( $sizes['movie-poster'] );
	}
	return $sizes;
}
add_filter( 'intermediate_image_sizes_advanced', 'lbc_limit_poster_size', 10, 3 );
// WordPress 7.1 can make the sizes in the browser, from this list instead.
add_filter( 'wp_get_missing_image_subsizes', 'lbc_limit_poster_size', 10, 3 );

/** Movies admin list: when it was watched, the rating and the director. */
function lbc_admin_columns( array $columns ): array {
	$new = [];
	foreach ( $columns as $key => $label ) {
		$new[ $key ] = $label;
		if ( $key === 'title' ) {
			$new['lbc_watched']  = 'Watched';
			$new['lbc_rating']   = 'Rating';
			$new['lbc_director'] = 'Director';
		}
	}
	return $new;
}
add_filter( 'manage_movie_posts_columns', 'lbc_admin_columns' );

function lbc_admin_column( string $column, int $post_id ): void {
	switch ( $column ) {
		case 'lbc_watched':
			$date = get_post_meta( $post_id, 'watch_date', true );
			echo $date ? esc_html( mysql2date( get_option( 'date_format' ), $date ) ) : '&mdash;';
			break;
		case 'lbc_rating':
			$rating = get_post_meta( $post_id, 'movie_rating', true );
			echo $rating !== '' ? esc_html( lbc_rating_stars( $rating ) ) : '&mdash;';
			break;
		case 'lbc_director':
			echo esc_html( get_post_meta( $post_id, 'director', true ) ?: '—' );
			break;
	}
}
add_action( 'manage_movie_posts_custom_column', 'lbc_admin_column', 10, 2 );

function lbc_sortable_columns( array $columns ): array {
	return $columns + [ 'lbc_watched' => 'lbc_watched', 'lbc_rating' => 'lbc_rating' ];
}
add_filter( 'manage_edit-movie_sortable_columns', 'lbc_sortable_columns' );

/** Sorting the Movies list by those columns, with movies that have no value last either way. */
function lbc_sort_admin_list( array $clauses, WP_Query $query ): array {
	global $wpdb;
	$orderby = $query->get( 'orderby' );
	if ( ! is_admin() || ! $query->is_main_query() || $query->get( 'post_type' ) !== 'movie' || ! in_array( $orderby, [ 'lbc_watched', 'lbc_rating' ], true ) ) {
		return $clauses;
	}
	$key   = $orderby === 'lbc_watched' ? 'watch_date' : 'movie_rating';
	$value = $wpdb->prepare( "( SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = {$wpdb->posts}.ID AND meta_key = %s LIMIT 1 )", $key );
	$order = strtoupper( (string) $query->get( 'order' ) ) === 'ASC' ? 'ASC' : 'DESC';
	$sort  = $key === 'movie_rating' ? "CAST( $value AS DECIMAL(3,1) )" : $value;

	$clauses['orderby'] = "COALESCE( $value, '' ) = '', $sort $order, {$wpdb->posts}.ID $order";
	return $clauses;
}
add_filter( 'posts_clauses', 'lbc_sort_admin_list', 10, 2 );

/**
 * A rating as a number of stars out of 5, written the way Letterboxd stores
 * it ("3.5", "4"), from a number, Letterboxd's stars ("★★★½") or a feed value
 * ("4.0"). Empty when there's no rating or it isn't one.
 */
function lbc_normalize_rating( string $value ): string {
	$value = trim( $value );
	if ( $value === '' ) {
		return '';
	}
	if ( preg_match( '~^(\d+(?:[.,]\d+)?)\s*/\s*(5|10)$~', $value, $m ) ) {
		// "4/5", "8/10".
		$rating = (float) str_replace( ',', '.', $m[1] ) * 5 / (int) $m[2];
	} elseif ( preg_match( '/^\d+(?:[.,]\d+)?$/', $value ) ) {
		$rating = (float) str_replace( ',', '.', $value );
	} else {
		$rating = mb_substr_count( $value, '★' ) + ( str_contains( $value, '½' ) ? 0.5 : 0 );
	}
	// Letterboxd ratings are half stars from 0.5 to 5.
	$rating = round( $rating * 2 ) / 2;
	if ( $rating < 0.5 || $rating > 5 ) {
		return '';
	}
	return rtrim( rtrim( number_format( $rating, 1, '.', '' ), '0' ), '.' );
}

/** "3.5" => "★★★½". */
function lbc_rating_stars( string $rating ): string {
	$rating = min( 5, max( 0, (float) $rating ) );
	return str_repeat( '★', (int) floor( $rating ) ) . ( fmod( $rating, 1 ) >= 0.5 ? '½' : '' );
}

/**
 * Edits made by hand, kept consistent: the post date follows the day
 * watched (the theme lists movies by post date, the grid by day watched),
 * the Year taxonomy follows the year field, and a poster removed on purpose
 * isn't added back by the background job.
 */
function lbc_movie_meta_changed( $meta_ids, $post_id, $key, $value ): void {
	$post_id = (int) $post_id;
	if ( ! in_array( $key, [ 'watch_date', 'movie_year', '_thumbnail_id' ], true ) || get_post_type( $post_id ) !== 'movie' || lbc_deleting_post( $post_id ) ) {
		return;
	}
	$deleted = doing_action( 'deleted_post_meta' );
	if ( $key === '_thumbnail_id' ) {
		$deleted ? update_post_meta( $post_id, '_lbc_no_poster', '1' ) : delete_post_meta( $post_id, '_lbc_no_poster' );
		return;
	}
	if ( $key === 'movie_year' ) {
		$year = (string) $value;
		wp_set_object_terms( $post_id, $deleted || ! preg_match( '/^\d{4}$/', $year ) ? [] : [ $year ], 'movie_year' );
		return;
	}
	if ( ! $deleted && lbc_valid_date( (string) $value ) ) {
		lbc_date_post_as_watched( $post_id, (string) $value );
	}
}
add_action( 'added_post_meta', 'lbc_movie_meta_changed', 10, 4 );
add_action( 'updated_post_meta', 'lbc_movie_meta_changed', 10, 4 );
add_action( 'deleted_post_meta', 'lbc_movie_meta_changed', 10, 4 );

/** True while a post is being deleted for good: its meta going isn't an edit. */
function lbc_deleting_post( int $post_id, ?bool $set = null ): bool {
	static $deleting = [];
	if ( $set !== null ) {
		$deleting[ $post_id ] = $set;
	}
	return ! empty( $deleting[ $post_id ] );
}
add_action( 'before_delete_post', fn( $post_id ) => lbc_deleting_post( (int) $post_id, true ) );
add_action( 'deleted_post', fn( $post_id ) => lbc_deleting_post( (int) $post_id, false ) );

/**
 * Move a movie's post date to the day it was watched, keeping its time of
 * day, never into the future. Written straight to the database rather than
 * with wp_update_post(), which would run the post through kses again (in a
 * cron run, with no user, that turns "&" in titles into "&amp;") and could
 * publish a scheduled post. Scheduled posts keep their date.
 */
function lbc_date_post_as_watched( int $post_id, string $watched ): void {
	global $wpdb;
	$post = get_post( $post_id );
	if ( ! $post || substr( $post->post_date, 0, 10 ) === $watched || in_array( $post->post_status, [ 'auto-draft', 'trash', 'future' ], true ) ) {
		return;
	}
	$gmt = min( get_gmt_from_date( $watched . substr( $post->post_date, 10 ) ), gmdate( 'Y-m-d H:i:s' ) );
	$wpdb->update( $wpdb->posts, [ 'post_date' => get_date_from_gmt( $gmt ), 'post_date_gmt' => $gmt ], [ 'ID' => $post_id ] );
	lbc_movie_changed( $post_id );
}

/** A movie saved without a day watched (added by hand) gets its post date's day, so it shows in Movie Grids. */
function lbc_fill_watch_date( int $post_id, WP_Post $post ): void {
	if ( $post->post_type === 'movie' && in_array( $post->post_status, [ 'publish', 'private', 'draft', 'pending' ], true ) && get_post_meta( $post_id, 'watch_date', true ) === '' ) {
		update_post_meta( $post_id, 'watch_date', substr( $post->post_date, 0, 10 ) );
	}
}
add_action( 'wp_after_insert_post', 'lbc_fill_watch_date', 10, 2 );
