<?php
/**
 * Importing a Letterboxd export (Settings → Import & Export → Export your
 * data on letterboxd.com): the ZIP, or one CSV from it.
 *
 * The feed only holds the last 50 diary entries; the export has all of them.
 * By default it only repairs what's already on the site: watch dates 1.x got
 * wrong and missing ratings. Ticking "add missing entries" also creates posts
 * for diary entries the site doesn't have; their posters and TMDB details
 * are filled in afterwards by the background job.
 *
 * Files used: diary.csv (or watched.csv when there's no diary), reviews.csv
 * for review text, ratings.csv for ratings of films whose diary entries
 * have none.
 */

defined( 'ABSPATH' ) || exit;

/** Largest file accepted, and most CSV read out of a ZIP in all (guards against zip bombs). */
const LBC_CSV_MAX_BYTES = 20 * MB_IN_BYTES;

/** Most rows read from one CSV: a lifetime of diary entries, with room to spare, that fits in memory. */
const LBC_CSV_MAX_ROWS = 50000;

/**
 * @param string $path   The uploaded file.
 * @param bool   $create Also add diary entries the site doesn't have.
 * @return array|WP_Error The import summary, plus ratings_filled and read (entries in the file).
 */
function lbc_import_export_file( string $path, bool $create ): array|WP_Error {
	lbc_raise_limits();
	$tables = lbc_read_export( $path );
	if ( is_wp_error( $tables ) ) {
		return $tables;
	}
	if ( ! lbc_lock( 'import' ) ) {
		return new WP_Error( 'lbc_busy', 'An import is already running. Try again in a few minutes.' );
	}
	try {
		$entries = lbc_entries_from_export( $tables );
		if ( ! $entries && empty( $tables['ratings'] ) ) {
			return new WP_Error( 'lbc_csv_empty', 'That file has no diary entries or ratings in it.' );
		}
		// The start date in settings is for the feed; an export is imported whole.
		$settings                   = array_merge( lbc_settings(), [ 'start_date' => '' ] );
		$summary                    = lbc_import_entries( $entries, $settings, $create, false );
		$summary['ratings_filled']  = lbc_fill_ratings( $tables['ratings'] ?? [] );
		$summary['read']            = count( $entries );
		// The export is what the "entries may have been missed" warning asks for.
		if ( $create ) {
			lbc_update_status( [ 'last_warning' => '' ] );
		}
		return $summary;
	} finally {
		lbc_unlock( 'import' );
	}
}

/**
 * The export's tables, keyed diary / reviews / ratings / watched, each a list
 * of rows keyed by lower-case column name.
 *
 * @return array<string, array[]>|WP_Error
 */
function lbc_read_export( string $path ): array|WP_Error {
	$size = (int) @filesize( $path );
	if ( $size <= 0 ) {
		return new WP_Error( 'lbc_csv_unreadable', 'The uploaded file is empty or couldn’t be read.' );
	}
	if ( $size > LBC_CSV_MAX_BYTES ) {
		return new WP_Error( 'lbc_csv_too_big', 'That file is too big (20 MB at most).' );
	}
	$handle = fopen( $path, 'rb' );
	$magic  = $handle ? (string) fread( $handle, 4 ) : '';
	if ( $handle ) {
		fclose( $handle );
	}

	$tables = [];
	if ( $magic === "PK\x03\x04" ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'lbc_csv_no_zip', 'This server can’t open ZIP files. Upload diary.csv from the export instead.' );
		}
		$zip = new ZipArchive();
		if ( $zip->open( $path, ZipArchive::RDONLY ) !== true ) {
			return new WP_Error( 'lbc_csv_bad_zip', 'That ZIP file couldn’t be opened.' );
		}
		$found = []; // type => [ depth, index ]
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$stat  = $zip->statIndex( $i );
			$parts = explode( '/', strtolower( (string) ( $stat['name'] ?? '' ) ) );
			$base  = (string) array_pop( $parts );
			// deleted/, likes/ and lists/ hold CSVs with the same names; __MACOSX/ holds Finder junk.
			if ( array_intersect( $parts, [ 'deleted', 'likes', 'lists', 'orphaned', '__macosx' ] ) || ! preg_match( '/^(diary|reviews|ratings|watched)\.csv$/', $base, $m ) ) {
				continue;
			}
			// The export's own files are at the top; someone re-zipping may have put them in a folder.
			if ( ! isset( $found[ $m[1] ] ) || count( $parts ) < $found[ $m[1] ][0] ) {
				$found[ $m[1] ] = [ count( $parts ), $i ];
			}
		}
		$budget = LBC_CSV_MAX_BYTES;
		foreach ( $found as $type => [ , $i ] ) {
			// Read at most what's left of the budget, whatever size the ZIP claims.
			$text    = (string) $zip->getFromIndex( $i, $budget + 1 );
			$budget -= strlen( $text );
			if ( $budget < 0 ) {
				$zip->close();
				return new WP_Error( 'lbc_csv_too_big', 'The CSV files in that ZIP are too big (20 MB at most in all). Upload diary.csv on its own instead.' );
			}
			$tables[ $type ] = lbc_parse_csv( $text );
			unset( $text );
		}
		$zip->close();
	} else {
		$rows = lbc_parse_csv( (string) file_get_contents( $path ) );
		$type = lbc_csv_type( $rows[0] ?? [] );
		if ( ! $type ) {
			return new WP_Error( 'lbc_csv_unknown', 'That doesn’t look like a Letterboxd export: it needs Name, Year and Date columns.' );
		}
		$tables[ $type ] = $rows;
	}
	if ( ! $tables ) {
		return new WP_Error( 'lbc_csv_nothing', 'No diary.csv, watched.csv or ratings.csv found in that ZIP.' );
	}
	foreach ( $tables as $type => $rows ) {
		if ( count( $rows ) > LBC_CSV_MAX_ROWS ) {
			return new WP_Error( 'lbc_csv_too_big', sprintf( '%1$s.csv has more than %2$s rows, more than this can import at once.', $type, number_format_i18n( LBC_CSV_MAX_ROWS ) ) );
		}
	}
	return $tables;
}

/** Which export file a CSV is, from its columns. */
function lbc_csv_type( array $row ): string {
	if ( ! isset( $row['name'], $row['year'], $row['date'] ) ) {
		return '';
	}
	return match ( true ) {
		array_key_exists( 'review', $row )       => 'reviews',
		array_key_exists( 'watched date', $row ) => 'diary',
		array_key_exists( 'rating', $row )       => 'ratings',
		default                                  => 'watched',
	};
}

/** The columns used from any export file; the rest aren't kept, to save memory. */
const LBC_CSV_COLUMNS = [ 'date', 'name', 'year', 'letterboxd uri', 'rating', 'watched date', 'review' ];

/**
 * CSV text as rows keyed by lower-case header (only LBC_CSV_COLUMNS).
 * Handles a UTF-8 byte order mark, Windows line endings, blank lines before
 * the header, quoted fields with line breaks (reviews), and fields in
 * Windows-1252 rather than UTF-8. Stops after LBC_CSV_MAX_ROWS + 1 rows.
 *
 * @return array[]
 */
function lbc_parse_csv( string $text ): array {
	if ( str_starts_with( $text, "\xEF\xBB\xBF" ) ) {
		$text = substr( $text, 3 );
	}
	$stream = fopen( 'php://temp', 'r+' );
	fwrite( $stream, $text );
	unset( $text );
	rewind( $stream );

	$utf8 = fn( $value ) => trim( mb_check_encoding( (string) $value, 'UTF-8' ) ? (string) $value : mb_convert_encoding( (string) $value, 'UTF-8', 'Windows-1252' ) );
	$rows = [];
	do {
		$header = fgetcsv( $stream, null, ',', '"', '' );
	} while ( $header === [ null ] );
	if ( is_array( $header ) ) {
		$header = array_map( fn( $column ) => strtolower( $utf8( $column ) ), $header );
		$keep   = array_intersect( $header, LBC_CSV_COLUMNS ); // position => column
		while ( count( $rows ) <= LBC_CSV_MAX_ROWS && ( $row = fgetcsv( $stream, null, ',', '"', '' ) ) !== false ) {
			if ( $row === [ null ] ) {
				continue; // Blank line.
			}
			$values = [];
			foreach ( $keep as $position => $column ) {
				$values[ $column ] = $utf8( $row[ $position ] ?? '' );
			}
			$rows[] = $values;
		}
	}
	fclose( $stream );
	return $rows;
}

/**
 * Diary entries from the export: diary.csv rows (with review text from
 * reviews.csv), or watched.csv rows when there's no diary.
 *
 * @return array[] Entries, oldest first (see feed.php for the shape).
 */
function lbc_entries_from_export( array $tables ): array {
	// Each review once: by the diary entry's link, or else by film and day,
	// in order, so same-day rewatches don't all get the same review.
	$by_uri  = [];
	$by_day  = [];
	foreach ( $tables['reviews'] ?? [] as $i => $row ) {
		$watched = lbc_valid_date( $row['watched date'] ?? '' ) ? $row['watched date'] : ( $row['date'] ?? '' );
		if ( ( $row['letterboxd uri'] ?? '' ) !== '' ) {
			$by_uri[ $row['letterboxd uri'] ] = $i;
		}
		$by_day[ lbc_title_key( $row['name'] ?? '' ) . '|' . ( $row['year'] ?? '' ) . '|' . $watched ][] = $i;
	}
	$used   = [];
	$review = function ( array $row, string $title, string $year, string $watched ) use ( $tables, $by_uri, &$by_day, &$used ): string {
		$i = $by_uri[ $row['letterboxd uri'] ?? '' ] ?? null;
		if ( $i === null || isset( $used[ $i ] ) ) {
			$i = null;
			foreach ( $by_day[ lbc_title_key( $title ) . '|' . $year . '|' . $watched ] ?? [] as $candidate ) {
				if ( ! isset( $used[ $candidate ] ) ) {
					$i = $candidate;
					break;
				}
			}
		}
		if ( $i === null ) {
			return '';
		}
		$used[ $i ] = true;
		return (string) ( $tables['reviews'][ $i ]['review'] ?? '' );
	};

	// A diary is the full record; watched.csv (one row per film, no dates watched) is the fallback.
	$rows    = $tables['diary'] ?? $tables['reviews'] ?? $tables['watched'] ?? [];
	$diary   = isset( $tables['diary'] ) || isset( $tables['reviews'] );
	$entries = [];
	foreach ( $rows as $row ) {
		$title  = lbc_clean_title( (string) ( $row['name'] ?? '' ) );
		$logged = (string) ( $row['date'] ?? '' );
		if ( $title === '' || ! lbc_valid_date( $logged ) ) {
			continue;
		}
		$watched = lbc_valid_date( $row['watched date'] ?? '' ) ? $row['watched date'] : $logged;
		$year    = preg_match( '/^\d{4}$/', $row['year'] ?? '' ) ? $row['year'] : '';
		$text    = isset( $tables['diary'] ) ? $review( $row, $title, $year, $watched ) : (string) ( $row['review'] ?? '' );

		$entries[] = [
			'guid'         => '',
			'url'          => lbc_clean_url( (string) ( $row['letterboxd uri'] ?? '' ) ),
			'title'        => $title,
			'year'         => $year,
			'watched'      => $watched,
			'published'    => 0,
			'rating'       => lbc_normalize_rating( (string) ( $row['rating'] ?? '' ) ),
			'tmdb_id'      => '',
			'poster'       => '',
			'content'      => $text !== '' ? lbc_blocks_from_html( lbc_html_from_review_text( $text ) ) : '',
			// 1.x stored the day logged (CSV imports) or the UTC day logged, which can be a day later (feed imports).
			// watched.csv has no day watched to correct them to.
			'legacy_dates' => $diary ? [ $logged, gmdate( 'Y-m-d', strtotime( $logged . ' +1 day' ) ) ] : [],
		];
	}
	usort( $entries, fn( $a, $b ) => $a['watched'] <=> $b['watched'] );
	return $entries;
}

/**
 * Give movies with no rating the film's rating from ratings.csv.
 *
 * @return int How many movies got one.
 */
function lbc_fill_ratings( array $rows ): int {
	if ( ! $rows ) {
		return 0;
	}
	$index  = LBC_Movie_Index::load();
	$filled = 0;
	foreach ( $rows as $row ) {
		$rating = lbc_normalize_rating( (string) ( $row['rating'] ?? '' ) );
		if ( $rating === '' ) {
			continue;
		}
		foreach ( $index->with_title( lbc_clean_title( (string) ( $row['name'] ?? '' ) ), (string) ( $row['year'] ?? '' ) ) as $post_id ) {
			$movie = $index->get( $post_id );
			if ( $post_id > 0 && $movie && $movie['rating'] === '' && $movie['status'] !== 'trash' ) {
				update_post_meta( $post_id, 'movie_rating', $rating );
				$index->add( $post_id, [ 'rating' => $rating ] );
				lbc_movie_changed( $post_id );
				$filled++;
			}
		}
	}
	return $filled;
}
