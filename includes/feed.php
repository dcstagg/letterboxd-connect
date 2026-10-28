<?php
/**
 * The Letterboxd RSS feed: fetching it and turning each diary entry into a
 * plain array (an "entry"), the same shape the CSV import produces.
 *
 * An entry: guid, url, title, year, watched (YYYY-MM-DD), published (Unix
 * time, 0 if unknown), rating ("3.5" or ""), tmdb_id, poster (URL), content
 * (block markup) and legacy_dates (dates 1.x may have stored for it, used
 * only to recognise and correct posts it imported).
 */

defined( 'ABSPATH' ) || exit;

/** Feeds hold the last 50 diary entries plus lists; a few hundred KB at most. */
const LBC_FEED_MAX_BYTES = 5 * MB_IN_BYTES;

function lbc_feed_url( string $username ): string {
	return 'https://letterboxd.com/' . rawurlencode( $username ) . '/rss/';
}

/** @return array[]|WP_Error The feed's diary entries, oldest first. */
function lbc_fetch_feed( string $username ): array|WP_Error {
	$response = wp_safe_remote_get( lbc_feed_url( $username ), [
		'timeout'             => 20,
		'redirection'         => 3,
		'limit_response_size' => LBC_FEED_MAX_BYTES,
		'user-agent'          => 'WordPress/' . get_bloginfo( 'version' ) . '; Letterboxd-Connect/' . LBC_VERSION . '; ' . home_url( '/' ),
		'headers'             => [ 'Accept' => 'application/rss+xml, application/xml;q=0.9, */*;q=0.5' ],
	] );
	if ( is_wp_error( $response ) ) {
		return new WP_Error( 'lbc_feed_unreachable', 'Couldn’t reach Letterboxd: ' . $response->get_error_message() );
	}
	$code = (int) wp_remote_retrieve_response_code( $response );
	if ( $code === 404 ) {
		return new WP_Error( 'lbc_feed_not_found', sprintf( 'Letterboxd has no member called “%s”.', $username ) );
	}
	if ( $code !== 200 ) {
		return new WP_Error( 'lbc_feed_http', sprintf( 'Letterboxd answered with HTTP %d. Try again later.', $code ) );
	}
	return lbc_parse_feed( (string) wp_remote_retrieve_body( $response ) );
}

/** @return array[]|WP_Error Diary entries in the feed (lists are skipped), oldest first. */
function lbc_parse_feed( string $xml ): array|WP_Error {
	$xml = trim( $xml );
	if ( $xml === '' ) {
		return new WP_Error( 'lbc_feed_empty', 'Letterboxd sent an empty feed.' );
	}
	if ( ! function_exists( 'simplexml_load_string' ) ) {
		return new WP_Error( 'lbc_no_simplexml', 'This server’s PHP is missing the SimpleXML extension, which reading the feed needs.' );
	}
	$previous = libxml_use_internal_errors( true );
	// No LIBXML_NOENT: entities are never expanded, and LIBXML_NONET blocks network access.
	$doc = simplexml_load_string( $xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA );
	libxml_clear_errors();
	libxml_use_internal_errors( $previous );
	if ( ! $doc || ! isset( $doc->channel ) ) {
		return new WP_Error( 'lbc_feed_invalid', 'Letterboxd’s feed couldn’t be read (it isn’t valid RSS).' );
	}

	$entries = [];
	$items   = 0;
	$undated = 0;
	foreach ( $doc->channel->item as $item ) {
		// Lists are in the feed too, and are skipped without counting against it.
		$items += str_starts_with( trim( (string) $item->guid ), 'letterboxd-list-' ) ? 0 : 1;
		$entry  = lbc_parse_feed_item( $item );
		if ( $entry ) {
			$entries[] = $entry;
			$undated  += lbc_valid_date( trim( (string) $item->children( 'https://letterboxd.com' )->watchedDate ) ) ? 0 : 1;
		}
	}
	// Failing loudly beats importing nothing, or importing with the wrong dates, without a word.
	if ( $items && ! $entries ) {
		return new WP_Error( 'lbc_feed_format', sprintf( 'The feed has %d items but none could be read as diary entries. If you’ve logged films lately, Letterboxd may have changed its feed.', $items ) );
	}
	if ( count( $entries ) >= 4 && $undated > count( $entries ) / 2 ) {
		return new WP_Error( 'lbc_feed_format', 'Most entries in the feed have no watched date, so Letterboxd may have changed its feed. Nothing was imported.' );
	}
	usort( $entries, fn( $a, $b ) => [ $a['watched'], $a['published'] ] <=> [ $b['watched'], $b['published'] ] );
	return $entries;
}

/** One <item> as an entry, or null for lists and anything else that isn't a watched film. */
function lbc_parse_feed_item( SimpleXMLElement $item ): ?array {
	$lb    = $item->children( 'https://letterboxd.com' );
	$tmdb  = $item->children( 'https://themoviedb.org' );
	$title = lbc_clean_title( (string) $lb->filmTitle );
	if ( $title === '' ) {
		return null; // Not a film, or a title that's nothing but markup.
	}

	$published = strtotime( trim( (string) $item->pubDate ) ) ?: 0;
	$watched   = trim( (string) $lb->watchedDate );
	if ( ! lbc_valid_date( $watched ) ) {
		if ( ! $published ) {
			return null;
		}
		$watched = wp_date( 'Y-m-d', $published );
	}
	$year        = trim( (string) $lb->filmYear );
	$tmdb_id     = trim( (string) $tmdb->movieId );
	$description = (string) $item->description;

	return [
		'guid'         => sanitize_text_field( trim( (string) $item->guid ) ),
		'url'          => lbc_clean_url( (string) $item->link ),
		'title'        => $title,
		'year'         => preg_match( '/^\d{4}$/', $year ) ? $year : '',
		'watched'      => $watched,
		'published'    => $published,
		'rating'       => lbc_normalize_rating( (string) $lb->memberRating ),
		'tmdb_id'      => preg_match( '/^\d+$/', $tmdb_id ) ? $tmdb_id : '',
		'poster'       => lbc_poster_from_html( $description ),
		'content'      => lbc_blocks_from_html( $description ),
		// 1.x stored the UTC date the entry was logged, not the day it was watched.
		'legacy_dates' => $published ? [ gmdate( 'Y-m-d', $published ) ] : [],
	];
}

/**
 * An http(s) URL with a host, or "" for anything else. No DNS lookup here:
 * wp_safe_remote_get() and download_url() check where a URL points when it's
 * fetched.
 */
function lbc_clean_url( string $url ): string {
	$url = esc_url_raw( trim( $url ), [ 'http', 'https' ] );
	return $url !== '' && wp_parse_url( $url, PHP_URL_HOST ) ? $url : '';
}

/**
 * A title as plain text: entities decoded, tags and line breaks removed.
 * (Not sanitize_text_field(), which would also take "%Ca" out of "100%Cafe".)
 */
function lbc_clean_title( string $title ): string {
	$title = wp_check_invalid_utf8( html_entity_decode( $title, ENT_QUOTES | ENT_HTML5, 'UTF-8' ), true );
	// A "<" that doesn't start a tag ("<3") is kept, as sanitize_text_field() would.
	$title = str_replace( '&lt;', '<', wp_strip_all_tags( wp_pre_kses_less_than( $title ) ) );
	return trim( (string) preg_replace( '/\s+/u', ' ', $title ) );
}

/** The first image in a feed item's description: Letterboxd's poster. */
function lbc_poster_from_html( string $html ): string {
	if ( ! preg_match( '/<img\b[^>]*\bsrc\s*=\s*(["\'])(.*?)\1/i', $html, $m ) ) {
		return '';
	}
	return lbc_clean_url( html_entity_decode( $m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
}

/**
 * A feed item's description as block markup. Letterboxd sends the poster in
 * its own paragraph, then the review (or "Watched on …") as paragraphs; the
 * poster paragraph is dropped (it becomes the featured image) and each other
 * paragraph becomes a Paragraph block. Anything else (a blockquote, say) is
 * kept as a Custom HTML block, so nothing in a review is lost.
 *
 * The HTML is parsed, then written out again by lbc_html_from_node(), which
 * leaves out comments (they could be block delimiters, <!-- wp:… -->, that
 * would add blocks to the post) and elements whose content isn't text to
 * show, and escapes every attribute and text node. kses then has only plain,
 * well-formed markup to filter.
 */
function lbc_blocks_from_html( string $html ): string {
	$html = trim( $html );
	if ( $html === '' ) {
		return '';
	}
	if ( ! class_exists( Dom\HTMLDocument::class ) ) {
		return lbc_blocks_from_html_simple( $html );
	}
	$doc  = Dom\HTMLDocument::createFromString( '<!DOCTYPE html><html><body>' . $html . '</body></html>', LIBXML_NOERROR, 'UTF-8' );
	$body = $doc->body;
	if ( ! $body ) {
		return '';
	}

	$blocks = [];
	$loose  = ''; // Text and inline elements outside any paragraph.
	$flush  = function () use ( &$loose, &$blocks ) {
		if ( trim( wp_strip_all_tags( $loose ) ) !== '' ) {
			$blocks[] = lbc_paragraph_block( trim( $loose ) );
		}
		$loose = '';
	};

	foreach ( $body->childNodes as $node ) {
		if ( $node instanceof Dom\Element ) {
			$tag = strtolower( $node->localName );
			if ( $tag === 'p' ) {
				$flush();
				if ( $node->querySelector( 'img' ) && trim( $node->textContent ) === '' ) {
					continue; // The poster.
				}
				$inner = lbc_html_from_nodes( $node->childNodes );
				if ( trim( wp_strip_all_tags( $inner ) ) !== '' ) {
					$blocks[] = lbc_paragraph_block( trim( $inner ) );
				}
				continue;
			}
			if ( in_array( $tag, [ 'blockquote', 'ul', 'ol', 'pre', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'div', 'table', 'figure', 'hr' ], true ) ) {
				$flush();
				$markup = trim( wp_kses_post( lbc_html_from_node( $node ) ) );
				if ( $markup !== '' ) {
					$blocks[] = "<!-- wp:html -->\n" . $markup . "\n<!-- /wp:html -->";
				}
				continue;
			}
			if ( $tag === 'img' ) {
				continue;
			}
		}
		$loose .= lbc_html_from_node( $node );
	}
	$flush();

	return implode( "\n", $blocks );
}

/** Elements left out with everything in them: not review text, or not text at all. */
const LBC_DROPPED_ELEMENTS = [ 'script', 'style', 'template', 'noscript', 'noembed', 'noframes', 'title', 'textarea', 'xmp', 'plaintext', 'iframe', 'object', 'embed', 'svg', 'math', 'head', 'meta', 'link', 'base', 'form', 'input', 'button', 'select' ];

/** Elements with no closing tag. */
const LBC_VOID_ELEMENTS = [ 'area', 'br', 'col', 'hr', 'img', 'source', 'track', 'wbr' ];

function lbc_html_from_nodes( iterable $nodes ): string {
	$html = '';
	foreach ( $nodes as $node ) {
		$html .= lbc_html_from_node( $node );
	}
	return $html;
}

/** One parsed node as HTML: text escaped, attributes quoted and escaped, comments and the like left out. */
function lbc_html_from_node( Dom\Node $node ): string {
	if ( $node instanceof Dom\Text ) {
		return htmlspecialchars( $node->textContent, ENT_NOQUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8' );
	}
	if ( ! $node instanceof Dom\Element ) {
		return ''; // Comments, processing instructions.
	}
	$tag = strtolower( $node->localName );
	if ( in_array( $tag, LBC_DROPPED_ELEMENTS, true ) || ! preg_match( '/^[a-z][a-z0-9]*$/', $tag ) ) {
		return '';
	}
	$attributes = '';
	foreach ( $node->attributes as $attribute ) {
		$name = strtolower( $attribute->name );
		if ( preg_match( '/^[a-z][a-z0-9_-]*$/', $name ) ) {
			$attributes .= sprintf( ' %s="%s"', $name, htmlspecialchars( $attribute->value, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8' ) );
		}
	}
	if ( in_array( $tag, LBC_VOID_ELEMENTS, true ) ) {
		return "<$tag$attributes>";
	}
	return "<$tag$attributes>" . lbc_html_from_nodes( $node->childNodes ) . "</$tag>";
}

/**
 * Review text typed into Letterboxd, as the export has it: plain text with
 * line breaks, where Letterboxd allows <b>, <i>, <a href> and <blockquote>.
 * Escaped first, so "x<y" or "</3" stay as written, then those tags are let
 * back in.
 */
function lbc_html_from_review_text( string $text ): string {
	// Entities already in it ("&amp;", "&eacute;") are kept: Letterboxd shows them as characters.
	$html = htmlspecialchars( $text, ENT_NOQUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8', false );
	$html  = (string) preg_replace( '~&lt;(/?)(b|i|u|s|em|strong|strike|blockquote)&gt;~i', '<$1$2>', $html );
	$links = 0;
	$html  = (string) preg_replace_callback(
		// <a href="…">, with single or double quotes and any other attributes (dropped).
		'~&lt;a\s[^\n]*?\bhref\s*=\s*(["\'])(https?://[^"\'\s<>]+)\1[^\n]*?&gt;~i',
		function ( $m ) use ( &$links ) {
			$links++;
			return '<a href="' . esc_url( html_entity_decode( $m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) . '">';
		},
		$html
	);
	// As many </a> as there are links let back in; any others stay text.
	$html = (string) preg_replace( '~&lt;/a&gt;~i', '</a>', $html, $links );
	return wpautop( $html );
}

/**
 * The same, without PHP's DOM extension (should a host lack it): each <p>
 * that isn't just the poster becomes a Paragraph block.
 */
function lbc_blocks_from_html_simple( string $html ): string {
	// Comments, including the bogus ones an HTML parser makes of "<!x>", "</3>" and a "<" before a question mark.
	$html   = (string) preg_replace( '~<!--.*?(?:-->|$)|<[!?][^>]*>?|</[^a-z>][^>]*>?~is', '', $html );
	$blocks = [];
	if ( preg_match_all( '~<p\b[^>]*>(.*?)</p>~is', $html, $matches ) ) {
		foreach ( $matches[1] as $inner ) {
			if ( trim( wp_strip_all_tags( $inner ) ) !== '' ) {
				$blocks[] = lbc_paragraph_block( trim( $inner ) );
			}
		}
	} elseif ( trim( wp_strip_all_tags( $html ) ) !== '' ) {
		$blocks[] = lbc_paragraph_block( trim( $html ) );
	}
	return implode( "\n", $blocks );
}

/** A Paragraph block around already-parsed inline HTML. */
function lbc_paragraph_block( string $inner_html ): string {
	return "<!-- wp:paragraph -->\n<p>" . wp_kses_post( $inner_html ) . "</p>\n<!-- /wp:paragraph -->";
}

/** "Watched on Saturday August 9, 2025." – what Letterboxd writes for an entry with no review. */
function lbc_watched_on_blocks( string $date ): string {
	$time = strtotime( $date . ' 12:00:00' );
	return $time ? lbc_paragraph_block( esc_html( 'Watched on ' . gmdate( 'l F j, Y', $time ) . '.' ) ) : '';
}
