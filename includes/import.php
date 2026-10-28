<?php
/**
 * Importing the feed: on a schedule (WP-Cron) or from "Import now".
 *
 * A run adds a post for each diary entry the site doesn't have yet, and fills
 * in anything missing on the ones it does. New posts get their poster and
 * TMDB details straight away; whatever fails is retried by the background job
 * (backfill.php). A run stops starting new posts after LBC_TIME_BUDGET
 * seconds and picks up the rest a minute later, so it never runs into PHP's
 * time limit.
 */

defined( 'ABSPATH' ) || exit;

const LBC_TIME_BUDGET = 40;

/** Keep the cron event in step with the settings: on, at the chosen frequency, or off. */
function lbc_schedule_import(): void {
	$settings = lbc_settings();
	$event    = wp_get_scheduled_event( LBC_CRON_IMPORT );
	// wp_clear_scheduled_hook() leaves a pending one-off "continue" run (it has an argument) alone.
	if ( $settings['frequency'] === 'off' || $settings['username'] === '' ) {
		if ( $event ) {
			wp_clear_scheduled_hook( LBC_CRON_IMPORT );
		}
		return;
	}
	if ( $event && $event->schedule === $settings['frequency'] ) {
		return;
	}
	wp_clear_scheduled_hook( LBC_CRON_IMPORT );
	wp_schedule_event( time() + MINUTE_IN_SECONDS, $settings['frequency'], LBC_CRON_IMPORT );
}
add_action( 'update_option_' . LBC_SETTINGS, 'lbc_schedule_import', 10, 0 );
add_action( 'add_option_' . LBC_SETTINGS, 'lbc_schedule_import', 10, 0 );
// Puts the event back if something cleared it (a cron cleanup plugin, a restored database).
add_action( 'admin_init', 'lbc_schedule_import' );

add_action( LBC_CRON_IMPORT, 'lbc_run_import', 10, 0 );

/**
 * Fetch the feed and import it.
 *
 * @return array|WP_Error The run's summary (see lbc_import_entries()).
 */
function lbc_run_import(): array|WP_Error {
	$settings = lbc_settings();
	if ( $settings['username'] === '' ) {
		return lbc_record_error( new WP_Error( 'lbc_no_username', 'Add your Letterboxd username in Settings → Letterboxd Connect.' ) );
	}
	if ( ! lbc_lock( 'import' ) ) {
		return new WP_Error( 'lbc_busy', 'An import is already running. Try again in a few minutes.' );
	}
	try {
		lbc_raise_limits();
		$entries = lbc_fetch_feed( $settings['username'] );
		if ( is_wp_error( $entries ) ) {
			return lbc_record_error( $entries );
		}
		$had     = (int) ( wp_count_posts( 'movie' )->publish ?? 0 );
		$summary = lbc_import_entries( $entries, $settings );
		// Every entry new, on a site that has movies: older entries may have left the feed unseen.
		$gap = $had && count( $entries ) >= 40 && $summary['created'] && ! $summary['updated'] && ! $summary['skipped']
			? sprintf( 'Every entry in the feed was new, so entries logged before %s may have been missed (the feed only holds the last 50). Import your Letterboxd export to fill the gap.', mysql2date( get_option( 'date_format' ), $entries[0]['watched'] ) )
			: '';
		lbc_update_status( [ 'last_import' => time(), 'last_result' => $summary, 'last_error' => '', 'last_warning' => $gap ] );
		if ( $summary['more'] ) {
			// The argument keeps this one-off run from clashing with the recurring event.
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, LBC_CRON_IMPORT, [ 'continue' ] );
		}
		return $summary;
	} finally {
		lbc_unlock( 'import' );
	}
}

/**
 * Import entries from the feed or a CSV file.
 *
 * @param array[] $entries  See feed.php for the shape.
 * @param bool    $create   False to only update posts that exist.
 * @param bool    $details  False to leave posters and TMDB to the background job (CSV imports).
 * @return array{created: int, updated: int, skipped: int, failed: int, missing: int, more: bool, errors: string[]}
 */
function lbc_import_entries( array $entries, array $settings, bool $create = true, bool $details = true ): array {
	$started  = microtime( true );
	$index    = LBC_Movie_Index::load();
	$summary  = [ 'created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0, 'missing' => 0, 'more' => false, 'errors' => [] ];
	$backfill = false;
	// Each entry's post, if it has one. Two entries can't share a post; matches
	// whose ratings agree are made first, so same-day rewatches pair up right.
	$taken   = [];
	$matches = [];
	foreach ( [ true, false ] as $strict ) {
		foreach ( $entries as $i => $entry ) {
			if ( ! isset( $matches[ $i ] ) ) {
				$post_id = $index->find( $entry, $taken, $strict );
				if ( $post_id ) {
					$matches[ $i ]     = $post_id;
					$taken[ $post_id ] = true;
				}
			}
		}
	}

	foreach ( $entries as $i => $entry ) {
		$post_id = $matches[ $i ] ?? 0;
		if ( $post_id ) {
			lbc_fill_movie( $post_id, $entry, $index ) ? $summary['updated']++ : $summary['skipped']++;
			continue;
		}
		if ( ! $create ) {
			$summary['missing']++; // Not on the site, and not to be added.
			continue;
		}
		if ( $settings['start_date'] !== '' && $entry['watched'] < $settings['start_date'] ) {
			$summary['skipped']++;
			continue;
		}
		if ( microtime( true ) - $started > LBC_TIME_BUDGET ) {
			$summary['more'] = true;
			break;
		}

		$post_id = lbc_create_movie( $entry, $settings );
		if ( is_wp_error( $post_id ) ) {
			$summary['failed']++;
			$summary['errors'][] = $entry['title'] . ': ' . $post_id->get_error_message();
			continue;
		}
		$summary['created']++;
		$index->add( $post_id, [
			'status'  => $settings['draft'] ? 'draft' : 'publish',
			'title'   => $entry['title'],
			'year'    => $entry['year'],
			'watched' => $entry['watched'],
			'rating'  => $entry['rating'],
			'guid'    => $entry['guid'],
			'url'     => $entry['url'],
			'dated'   => true,
			'source'  => lbc_entry_source( $entry ),
		] );

		if ( $details ) {
			// TMDB first: it supplies a poster when Letterboxd's is missing.
			if ( $settings['tmdb_api_key'] !== '' ) {
				lbc_fill_from_tmdb( $post_id );
			}
			lbc_attach_poster( $post_id );
		}
		if ( lbc_movie_incomplete( $post_id ) ) {
			$backfill = true;
		}
	}

	if ( $backfill ) {
		lbc_queue_backfill();
	}
	$summary['errors'] = array_slice( $summary['errors'], 0, 10 );
	return $summary;
}

/** "Added 3 movies, updated 2." for notices and the status box. */
function lbc_summary_text( array $summary ): string {
	$parts = [];
	if ( ! empty( $summary['created'] ) ) {
		$parts[] = sprintf( _n( 'added %d movie', 'added %d movies', $summary['created'], 'letterboxd-connect' ), $summary['created'] );
	}
	if ( ! empty( $summary['updated'] ) ) {
		$parts[] = sprintf( 'updated %d', $summary['updated'] );
	}
	if ( ! empty( $summary['failed'] ) ) {
		$parts[] = sprintf( '%d failed', $summary['failed'] );
	}
	$text = $parts ? ucfirst( implode( ', ', $parts ) ) . '.' : 'Nothing new.';
	if ( ! empty( $summary['more'] ) ) {
		$text .= ' The rest will be added in a minute.';
	}
	return $text;
}

/** More time and memory for a long run, where the host allows it. */
function lbc_raise_limits(): void {
	wp_raise_memory_limit( 'admin' );
	if ( function_exists( 'set_time_limit' ) ) {
		set_time_limit( 300 );
	}
}
