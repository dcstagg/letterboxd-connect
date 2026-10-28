<?php
/**
 * TMDB (themoviedb.org): director, IMDb ID, genres, release date, a poster
 * when Letterboxd's is missing, and the "where to watch" page. Needs an API
 * key from https://www.themoviedb.org/settings/api (v3 key or v4 token).
 */

defined( 'ABSPATH' ) || exit;

const LBC_TMDB_API = 'https://api.themoviedb.org/3';

/** The country whose streaming services the "Watch" link lists. */
function lbc_tmdb_region(): string {
	$region = strtoupper( (string) apply_filters( 'lbc_tmdb_region', 'US' ) );
	return preg_match( '/^[A-Z]{2}$/', $region ) ? $region : 'US';
}

/**
 * GET an API path. A v4 token goes in the Authorization header; a v3 key has
 * to go in the query string, so URLs are never logged or shown.
 *
 * @return array|WP_Error The decoded JSON.
 */
function lbc_tmdb_get( string $path, array $query = [], ?string $key = null ): array|WP_Error {
	$key ??= lbc_settings()['tmdb_api_key'];
	if ( $key === '' ) {
		return new WP_Error( 'lbc_tmdb_no_key', 'Add a TMDB API key to fill in movie details.' );
	}
	$headers = [ 'Accept' => 'application/json' ];
	if ( str_starts_with( $key, 'eyJ' ) ) {
		$headers['Authorization'] = 'Bearer ' . $key;
	} else {
		$query['api_key'] = $key;
	}
	$url = LBC_TMDB_API . $path . ( $query ? '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 ) : '' );

	for ( $attempt = 1; ; $attempt++ ) {
		$response = wp_safe_remote_get( $url, [
			'timeout'             => 10,
			'headers'             => $headers,
			'limit_response_size' => 2 * MB_IN_BYTES,
			'user-agent'          => 'Letterboxd-Connect/' . LBC_VERSION . '; ' . home_url( '/' ),
		] );
		$code = (int) wp_remote_retrieve_response_code( $response );
		// Rate limited: wait as asked (briefly) and try again, twice at most.
		if ( $code === 429 && $attempt < 3 ) {
			sleep( min( 5, max( 1, (int) wp_remote_retrieve_header( $response, 'retry-after' ) ) ) );
			continue;
		}
		break;
	}

	if ( is_wp_error( $response ) ) {
		// cURL messages don't include the URL, so the key can't leak here.
		return new WP_Error( 'lbc_tmdb_unreachable', 'Couldn’t reach TMDB: ' . $response->get_error_message() );
	}
	if ( $code === 401 ) {
		return new WP_Error( 'lbc_tmdb_bad_key', 'TMDB didn’t accept the API key. Check it in Settings → Letterboxd Connect.' );
	}
	if ( $code === 404 ) {
		return new WP_Error( 'lbc_tmdb_not_found', 'TMDB has no such movie.' );
	}
	if ( $code !== 200 ) {
		return new WP_Error( 'lbc_tmdb_http', sprintf( 'TMDB answered with HTTP %d.', $code ) );
	}
	$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
	return is_array( $data ) ? $data : new WP_Error( 'lbc_tmdb_invalid', 'TMDB sent something that isn’t JSON.' );
}

/** True if TMDB accepts the key; otherwise the error saying why. */
function lbc_tmdb_check_key( string $key ): true|WP_Error {
	$result = lbc_tmdb_get( '/configuration', [], $key );
	return is_wp_error( $result ) ? $result : true;
}

/**
 * Details for one movie, in a single request: credits (for the director),
 * external IDs (IMDb) and watch providers.
 */
function lbc_tmdb_movie( int $tmdb_id ): array|WP_Error {
	return lbc_tmdb_get( '/movie/' . $tmdb_id, [ 'append_to_response' => 'credits,external_ids,watch/providers' ] );
}

/**
 * The meta a movie gets from TMDB details. Only non-empty values.
 *
 * @return array<string, string>
 */
function lbc_tmdb_meta( array $movie ): array {
	$directors = [];
	foreach ( (array) ( $movie['credits']['crew'] ?? [] ) as $person ) {
		if ( is_array( $person ) && ( $person['job'] ?? '' ) === 'Director' && ! empty( $person['name'] ) ) {
			$directors[] = sanitize_text_field( (string) $person['name'] );
		}
	}
	$genres = [];
	foreach ( (array) ( $movie['genres'] ?? [] ) as $genre ) {
		if ( is_array( $genre ) && ! empty( $genre['name'] ) ) {
			$genres[] = sanitize_text_field( (string) $genre['name'] );
		}
	}
	$release = (string) ( $movie['release_date'] ?? '' );
	$imdb    = (string) ( $movie['external_ids']['imdb_id'] ?? $movie['imdb_id'] ?? '' );
	$poster  = (string) ( $movie['poster_path'] ?? '' );
	$watch   = $movie['watch/providers']['results'][ lbc_tmdb_region() ] ?? null;

	return array_filter( [
		'tmdb_id'           => isset( $movie['id'] ) && is_numeric( $movie['id'] ) ? (string) (int) $movie['id'] : '',
		'tmdb_title'        => sanitize_text_field( (string) ( $movie['title'] ?? '' ) ),
		'tmdb_release_date' => lbc_valid_date( $release ) ? $release : '',
		'tmdb_genres'       => implode( ', ', array_unique( $genres ) ),
		'tmdb_poster_path'  => preg_match( '~^/[\w.-]+$~', $poster ) ? $poster : '',
		'director'          => implode( ', ', array_unique( $directors ) ),
		'imdb_id'           => preg_match( '/^tt\d+$/', $imdb ) ? $imdb : '',
		'movie_year'        => lbc_valid_date( $release ) ? substr( $release, 0, 4 ) : '',
		// Only when the film streams, rents or sells somewhere in the region.
		'streaming_link'    => is_array( $watch ) && array_intersect( [ 'flatrate', 'free', 'ads', 'rent', 'buy' ], array_keys( $watch ) ) ? lbc_tmdb_watch_link( (string) ( $watch['link'] ?? '' ) ) : '',
	], 'strlen' );
}

/** TMDB's "where to watch" page, and only that: the link is shown on the site. */
function lbc_tmdb_watch_link( string $url ): string {
	$url  = lbc_clean_url( $url );
	$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
	return $host === 'themoviedb.org' || str_ends_with( $host, '.themoviedb.org' ) ? $url : '';
}

/** A poster URL for a TMDB poster path. */
function lbc_tmdb_poster_url( string $path ): string {
	return $path !== '' ? 'https://image.tmdb.org/t/p/w780' . $path : '';
}

/**
 * Find a movie's TMDB ID from its title and year (CSV rows don't carry one).
 * Only an exact title match within a year of the release counts (or, with
 * no year, the only film with that title); a wrong match would put another
 * film's director and poster on the post.
 *
 * @return int|WP_Error 0 when there's no confident match.
 */
function lbc_tmdb_search( string $title, string $year ): int|WP_Error {
	$query = [ 'query' => $title, 'include_adult' => 'false' ];
	if ( $year !== '' ) {
		$query['year'] = $year;
	}
	$found = lbc_tmdb_get( '/search/movie', $query );
	if ( is_wp_error( $found ) ) {
		return $found;
	}
	// The year filter is on the primary release; retry without it for films dated differently.
	if ( $year !== '' && empty( $found['results'] ) ) {
		$found = lbc_tmdb_get( '/search/movie', [ 'query' => $title, 'include_adult' => 'false' ] );
		if ( is_wp_error( $found ) ) {
			return $found;
		}
	}

	$wanted  = lbc_title_key( $title );
	$matches = [];
	foreach ( (array) ( $found['results'] ?? [] ) as $result ) {
		if ( ! is_array( $result ) || empty( $result['id'] ) ) {
			continue;
		}
		$titles = [ lbc_title_key( (string) ( $result['title'] ?? '' ) ), lbc_title_key( (string) ( $result['original_title'] ?? '' ) ) ];
		if ( ! in_array( $wanted, $titles, true ) ) {
			continue;
		}
		$released = (int) substr( (string) ( $result['release_date'] ?? '' ), 0, 4 );
		if ( $year !== '' && $released && abs( $released - (int) $year ) <= 1 ) {
			return (int) $result['id'];
		}
		$matches[] = (int) $result['id'];
	}
	// With no year, only a title no other film shares (there are a dozen "Halloween"s).
	return $year === '' && count( $matches ) === 1 ? $matches[0] : 0;
}

/** A title reduced for comparison: "The Devil’s Rejects" and "the devils rejects" match. */
function lbc_title_key( string $title ): string {
	$title = html_entity_decode( $title, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	$title = remove_accents( mb_strtolower( $title, 'UTF-8' ) );
	$title = str_replace( '&', 'and', $title );
	$key   = (string) preg_replace( '/[^\p{L}\p{N}]+/u', '', $title );
	// A title of only symbols or emoji ("…") keeps them, so such films don't all match each other.
	return $key !== '' ? $key : (string) preg_replace( '/\s+/u', '', $title );
}
