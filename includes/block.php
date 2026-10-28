<?php
/**
 * The Movie Grid block (letterboxd-connect/movie-grid): posters with title,
 * date watched, director, rating and links, as a grid or a list, optionally
 * paged. Rendered on the server (blocks/movie-grid/render.php); the markup
 * and attributes are the same as 1.x, so saved blocks and their styling
 * carry over.
 */

defined( 'ABSPATH' ) || exit;

function lbc_register_block(): void {
	register_block_type( dirname( LBC_FILE ) . '/blocks/movie-grid' );
}
add_action( 'init', 'lbc_register_block' );

/** Block attributes clamped to what the controls offer; saved content can't ask for more. */
function lbc_grid_attributes( array $attributes ): array {
	// Saved content and the block renderer's REST route can hold anything: floats past PHP_INT_MAX, arrays.
	$value = fn( string $key ) => is_scalar( $attributes[ $key ] ?? null ) ? $attributes[ $key ] : null;
	$int   = fn( string $key, int $default, int $min, int $max ) => (int) min( $max, max( $min, is_numeric( $value( $key ) ) ? (float) $value( $key ) : $default ) );
	return [
		'number'            => $int( 'number', 12, 1, 100 ),
		'perPage'           => $int( 'perPage', 12, 1, 100 ),
		'columns'           => $int( 'columns', 3, 1, 6 ),
		'showAll'           => (bool) $value( 'showAll' ),
		'orderby'           => in_array( $value( 'orderby' ), [ 'watch_date', 'title', 'release_year' ], true ) ? $value( 'orderby' ) : 'watch_date',
		'order'             => strtoupper( (string) $value( 'order' ) ) === 'ASC' ? 'ASC' : 'DESC',
		'displayMode'       => $value( 'displayMode' ) === 'list' ? 'list' : 'cards',
		'showDirector'      => (bool) ( $value( 'showDirector' ) ?? true ),
		'showRating'        => (bool) ( $value( 'showRating' ) ?? true ),
		'showStreamingLink' => (bool) ( $value( 'showStreamingLink' ) ?? true ),
		'showExternalLinks' => (bool) ( $value( 'showExternalLinks' ) ?? true ),
	];
}

/** Which page of movies: /page/2/ (paged), or on a static front page, page. */
function lbc_grid_page(): int {
	return max( 1, (int) get_query_var( 'paged' ), is_front_page() ? (int) get_query_var( 'page' ) : 0 );
}

/** The movies to show. Paged by the page's own /page/2/ when "show all" is on. */
function lbc_grid_query( array $a ): WP_Query {
	return new WP_Query( lbc_grid_query_args( $a, $a['showAll'] ? lbc_grid_page() : 1 ) );
}

function lbc_grid_query_args( array $a, int $paged ): array {
	$args = [
		'post_type'           => 'movie',
		'post_status'         => 'publish',
		'posts_per_page'      => $a['showAll'] ? $a['perPage'] : $a['number'],
		'paged'               => $paged,
		'no_found_rows'       => ! $a['showAll'],
		'ignore_sticky_posts' => true,
	];
	switch ( $a['orderby'] ) {
		case 'title':
			$args['orderby'] = [ 'title' => $a['order'], 'ID' => $a['order'] ];
			break;
		case 'release_year':
			$args['meta_query'] = [ 'year' => [ 'key' => 'movie_year', 'type' => 'NUMERIC', 'compare' => 'EXISTS' ] ];
			$args['orderby']    = [ 'year' => $a['order'], 'title' => 'ASC', 'ID' => 'ASC' ];
			break;
		default:
			$args['meta_query'] = [ 'watched' => [ 'key' => 'watch_date', 'compare' => 'EXISTS' ] ];
			$args['orderby']    = [ 'watched' => $a['order'], 'date' => $a['order'], 'ID' => $a['order'] ];
	}
	return $args;
}

/**
 * A page with a paged ("show all") Movie Grid: /page/1/ redirects to the
 * page itself, and a page past the grid's last is "not found" rather than
 * an empty grid. Pages without a paged grid are left to WordPress.
 */
function lbc_grid_paging_status(): void {
	if ( ! is_singular() ) {
		return;
	}
	$requested = (int) get_query_var( 'paged' ) ?: ( is_front_page() ? (int) get_query_var( 'page' ) : 0 );
	if ( $requested < 1 ) {
		return;
	}
	$grids = array_filter( array_map( 'lbc_grid_attributes', lbc_find_grids( parse_blocks( (string) get_post_field( 'post_content', get_queried_object_id(), 'raw' ) ) ) ), fn( $a ) => $a['showAll'] );
	if ( ! $grids ) {
		return;
	}
	if ( $requested === 1 ) {
		wp_safe_redirect( get_permalink( get_queried_object_id() ), 301 );
		exit;
	}
	foreach ( $grids as $a ) {
		$found = new WP_Query( array_merge( lbc_grid_query_args( $a, 1 ), [ 'fields' => 'ids', 'posts_per_page' => 1 ] ) );
		if ( $requested <= (int) ceil( $found->found_posts / $a['perPage'] ) ) {
			return;
		}
	}
	global $wp_query;
	$wp_query->set_404();
	status_header( 404 );
	nocache_headers();
}
// After redirect_canonical(), which would otherwise go looking for a page to redirect a 404 to.
add_action( 'template_redirect', 'lbc_grid_paging_status', 11 );

/** @return array[] The attributes of each Movie Grid in these blocks, nested ones included. */
function lbc_find_grids( array $blocks ): array {
	$found = [];
	foreach ( $blocks as $block ) {
		if ( ( $block['blockName'] ?? '' ) === 'letterboxd-connect/movie-grid' ) {
			$found[] = (array) ( $block['attrs'] ?? [] );
		}
		if ( ! empty( $block['innerBlocks'] ) ) {
			$found = array_merge( $found, lbc_find_grids( $block['innerBlocks'] ) );
		}
	}
	return $found;
}

function lbc_render_movie_grid( array $attributes ): string {
	static $count = 0;
	$a     = lbc_grid_attributes( $attributes );
	$query = lbc_grid_query( $a );
	$list  = $a['displayMode'] === 'list';
	// Page links jump back to the grid, not the top of the page.
	$id    = 'movie-grid' . ( ++$count > 1 ? '-' . $count : '' );

	// Cached pages showing a grid are cleared when any movie changes (lbc_purge_grids()).
	do_action( 'litespeed_tag_add', 'lbc_movies' );

	$html = '';
	if ( $query->have_posts() ) {
		update_post_thumbnail_cache( $query );
		$html .= $list ? '<div class="movie-list">' : sprintf( '<div class="movie-grid columns-%d">', $a['columns'] );
		foreach ( $query->posts as $post ) {
			$html .= lbc_render_movie_item( $post, $a );
		}
		$html .= '</div>';
	} else {
		$html .= '<p class="no-movies">No movies found.</p>';
	}

	if ( $a['showAll'] ) {
		$html .= wp_is_serving_rest_request()
			? '<div class="pagination-note">Pagination will appear on the front end</div>'
			: lbc_render_pagination( $query, $id );
	}

	$wrapper = get_block_wrapper_attributes( [
		'id'                => $id,
		'data-columns'      => (string) $a['columns'],
		'data-display-mode' => $a['displayMode'],
	] );
	return sprintf( '<div %s>%s</div>', $wrapper, $html );
}

function lbc_render_movie_item( WP_Post $post, array $a ): string {
	$title    = get_the_title( $post );
	// Plain text for attributes and the search link (get_the_title() adds entities like &#8217;).
	$plain    = html_entity_decode( wp_strip_all_tags( $title ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	$watched  = (string) get_post_meta( $post->ID, 'watch_date', true );
	$director = (string) get_post_meta( $post->ID, 'director', true );
	$rating   = (string) get_post_meta( $post->ID, 'movie_rating', true );
	$year     = (string) get_post_meta( $post->ID, 'movie_year', true );
	$imdb     = (string) get_post_meta( $post->ID, 'imdb_id', true );
	$stream   = (string) get_post_meta( $post->ID, 'streaming_link', true );

	// The title is right below, so the poster needs no alt text of its own. Grids sit
	// below the fold here, so posters load lazily; sizes follows the column count.
	$poster = has_post_thumbnail( $post )
		? '<div class="movie-poster">' . get_the_post_thumbnail( $post, 'movie-poster', [
			'alt'           => '',
			'loading'       => 'lazy',
			'fetchpriority' => 'low',
			'sizes'         => lbc_poster_sizes( $a ),
		] ) . '</div>'
		: '<div class="movie-poster movie-poster-placeholder"></div>';

	$meta = '';
	if ( lbc_valid_date( $watched ) ) {
		$meta .= '<p class="watch-date">' . esc_html( mysql2date( get_option( 'date_format' ), $watched ) ) . '</p>';
	}
	if ( $a['showDirector'] && $director !== '' ) {
		$meta .= '<p class="movie-director">' . esc_html( $director ) . '</p>';
	}
	if ( $a['showRating'] && $rating !== '' ) {
		$meta .= '<p class="movie-rating"><span aria-hidden="true">' . esc_html( lbc_rating_stars( $rating ) ) . '</span><span class="screen-reader-text">' . esc_html( sprintf( 'Rated %s out of 5', $rating ) ) . '</span></p>';
	}

	// Each link says which film it's for and that it opens a new tab, for screen readers.
	$link  = fn( string $url, string $class, string $text, string $hidden ) => sprintf(
		'<a href="%s" target="_blank" rel="noopener noreferrer" class="external-link %s">%s<span class="screen-reader-text"> %s (opens in a new tab)</span></a>',
		esc_url( $url ),
		esc_attr( $class ),
		esc_html( $text ),
		esc_html( $hidden )
	);
	$links = [];
	if ( $a['showStreamingLink'] && $stream !== '' ) {
		$links[] = $link( $stream, 'watch-link', 'Watch', $plain );
	}
	if ( $a['showExternalLinks'] ) {
		if ( preg_match( '/^tt\d+$/', $imdb ) ) {
			$links[] = $link( 'https://www.imdb.com/title/' . $imdb . '/', 'imdb-link', 'IMDb', $plain . ' on IMDb' );
		}
		if ( $year !== '' ) {
			$links[] = $link( 'https://www.rottentomatoes.com/search?search=' . rawurlencode( $plain . ' ' . $year ), 'rt-link', 'RT', $plain . ' on Rotten Tomatoes' );
		}
	}

	return sprintf(
		'<div class="%s movie-item">%s<div class="movie-details"><h3 class="movie-title">%s</h3><div class="movie-meta">%s</div>%s</div></div>',
		$a['displayMode'] === 'list' ? 'movie-list-item' : 'movie-card',
		$poster,
		esc_html( wp_strip_all_tags( $title ) ),
		$meta,
		$links ? '<div class="movie-links">' . implode( ' ', $links ) . '</div>' : ''
	);
}

/** How wide a poster shows, for the browser to pick a file from srcset (see style.css). */
function lbc_poster_sizes( array $a ): string {
	if ( $a['displayMode'] === 'list' ) {
		return '(max-width: 767px) 128px, 192px';
	}
	if ( $a['columns'] === 1 ) {
		return '(max-width: 767px) 100vw, 800px';
	}
	return sprintf( '(max-width: 767px) 50vw, %dpx', (int) ceil( 800 / $a['columns'] ) );
}

/** Any change to a movie (save, trash, delete, new poster or details) clears cached pages with a grid. */
function lbc_purge_grids( int $post_id, WP_Post $post ): void {
	if ( $post->post_type === 'movie' ) {
		do_action( 'litespeed_purge', 'lbc_movies' );
	}
}
add_action( 'clean_post_cache', 'lbc_purge_grids', 10, 2 );

function lbc_render_pagination( WP_Query $query, string $id ): string {
	if ( $query->max_num_pages <= 1 ) {
		return '';
	}
	// paginate_links()' own base and format link page 1 to the page itself, not /page/1/.
	$pages = paginate_links( [
		'current'   => lbc_grid_page(),
		'total'     => (int) $query->max_num_pages,
		'type'      => 'array',
		'prev_text' => '&laquo; Previous',
		'next_text' => 'Next &raquo;',
		'add_fragment' => '#' . $id,
	] );
	if ( ! is_array( $pages ) ) {
		return '';
	}
	return '<nav class="movie-grid-pagination" aria-label="Movie pages"><ul class="page-numbers"><li>' . implode( '</li><li>', $pages ) . '</li></ul></nav>';
}
