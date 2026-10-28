<?php
/**
 * The background job that fills in movies missing a poster, director or IMDb
 * ID: after an import where TMDB or a poster download failed, after a CSV
 * import (its rows carry neither), or from "Fill in missing details".
 *
 * It works through the movies in batches, LBC_TIME_BUDGET seconds at a time,
 * scheduling itself again until none are left. Each movie is stamped when
 * it's checked (_lbc_details_checked), so one TMDB can't help with isn't
 * retried every run: automatic runs skip movies checked in the last 30 days;
 * "Fill in missing details" retries them all. If TMDB is having trouble,
 * the job stops without stamping and tries again later. A poster that won't
 * download for a reason that may pass (no connection, a server error) is
 * tried again an hour after the rest, up to LBC_POSTER_ROUNDS times, so one
 * dead link can't hold up the other movies. Each batch books a later run
 * before it starts, so a batch that dies (out of memory resizing a poster,
 * say) doesn't end the job.
 *
 * Its progress is kept in its own option (lbc_backfill), with a generation
 * number: queueing the job while a batch runs bumps it, and that batch then
 * adds what it did to the new state instead of saving over it.
 */

defined( 'ABSPATH' ) || exit;

const LBC_BACKFILL = 'lbc_backfill';

/** How many more times the job tries posters that failed to download. */
const LBC_POSTER_ROUNDS = 3;

/** True for an error that says "try later", not "this movie can't be helped". */
function lbc_temporary_error( WP_Error $error ): bool {
	$code = (string) $error->get_error_code();
	if ( in_array( $code, [ 'lbc_tmdb_unreachable', 'lbc_tmdb_http', 'lbc_tmdb_invalid', 'http_request_failed' ], true ) ) {
		return true;
	}
	// download_url() calls every failed download "http_404"; the real status is in its data.
	$data   = $error->get_error_data( $code );
	$status = is_array( $data ) ? (int) ( $data['code'] ?? 0 ) : 0;
	return $code === 'http_404' && ( $status === 429 || $status >= 500 );
}

/**
 * The job's progress: generation, cutoff, started, checked, fixed, finished,
 * error, retry (movies whose posters to try again) and round. Read fresh.
 */
function lbc_backfill_state(): array {
	wp_cache_delete( LBC_BACKFILL, 'options' );
	$state = get_option( LBC_BACKFILL, [] );
	return is_array( $state ) ? $state : [];
}

/**
 * Start the job, or (with $everything) restart it to retry every incomplete
 * movie, including ones checked recently. Queued while it's running, it
 * carries on and looks again for movies added since.
 */
function lbc_queue_backfill( bool $everything = false ): void {
	$state  = lbc_backfill_state();
	$update = [
		'generation' => (int) ( $state['generation'] ?? 0 ) + 1,
		'finished'   => 0,
	];
	if ( $everything || ! $state || ! empty( $state['finished'] ) ) {
		$update += [
			'cutoff'  => $everything ? time() : time() - 30 * DAY_IN_SECONDS,
			'started' => time(),
			'checked' => 0,
			'fixed'   => 0,
			'error'   => '',
			'retry'   => [],
			'round'   => 0,
		];
	}
	update_option( LBC_BACKFILL, array_merge( $state, $update ), false );
	if ( ! wp_next_scheduled( LBC_CRON_BACKFILL ) ) {
		wp_schedule_single_event( time() + 10, LBC_CRON_BACKFILL );
	}
}

add_action( LBC_CRON_BACKFILL, 'lbc_run_backfill', 10, 0 );

/** A new TMDB key: everything missing details is worth another look, however recently checked. */
function lbc_backfill_on_new_key( $old, $new ): void {
	$old_key = is_array( $old ) ? (string) ( $old['tmdb_api_key'] ?? '' ) : '';
	$new_key = is_array( $new ) ? (string) ( $new['tmdb_api_key'] ?? '' ) : '';
	if ( $new_key !== '' && $new_key !== $old_key ) {
		lbc_queue_backfill( true );
	}
}
add_action( 'update_option_' . LBC_SETTINGS, 'lbc_backfill_on_new_key', 10, 2 );
add_action( 'add_option_' . LBC_SETTINGS, fn( $option, $value ) => lbc_backfill_on_new_key( [], $value ), 10, 2 );

/** Puts an unfinished job's run back if something cleared it (deactivation, a cron cleanup plugin, a restored database). */
function lbc_resume_backfill(): void {
	$state = lbc_backfill_state();
	if ( $state && empty( $state['finished'] ) && ! wp_next_scheduled( LBC_CRON_BACKFILL ) && ! lbc_locked( 'backfill' ) ) {
		wp_schedule_single_event( time() + 10, LBC_CRON_BACKFILL );
	}
}
add_action( 'admin_init', 'lbc_resume_backfill' );

function lbc_run_backfill(): void {
	if ( ! lbc_lock( 'backfill' ) ) {
		return; // The run holding the lock schedules the next batch.
	}
	$retry_in = 30;
	try {
		$state = lbc_backfill_state();
		if ( ! $state || ! empty( $state['finished'] ) ) {
			return;
		}
		// The next batch, booked now in case this one dies. After the lock's 10 minutes are up.
		wp_clear_scheduled_hook( LBC_CRON_BACKFILL );
		wp_schedule_single_event( time() + 11 * MINUTE_IN_SECONDS, LBC_CRON_BACKFILL );

		lbc_raise_limits();
		$started = microtime( true );
		$has_key = lbc_settings()['tmdb_api_key'] !== '';
		$done    = [ 'checked' => 0, 'fixed' => 0 ];
		$misses  = []; // Movies TMDB said it doesn't have, in a row.
		$sure    = false; // TMDB found a film this run, so its "not found"s are real.
		$retry   = array_map( 'intval', (array) ( $state['retry'] ?? [] ) );

		while ( empty( $state['finished'] ) && microtime( true ) - $started < LBC_TIME_BUDGET ) {
			$ids = lbc_incomplete_movies( (int) $state['cutoff'], 10 );
			if ( ! $ids && $retry && (int) ( $state['round'] ?? 0 ) < LBC_POSTER_ROUNDS ) {
				// Everything else is done: posters that failed get another go in an hour.
				foreach ( $retry as $post_id ) {
					delete_post_meta( $post_id, '_lbc_details_checked' );
				}
				$state['round']  = (int) ( $state['round'] ?? 0 ) + 1;
				$state['error']  = sprintf( _n( '%d poster couldn’t be downloaded. Trying again in an hour.', '%d posters couldn’t be downloaded. Trying again in an hour.', count( $retry ), 'letterboxd-connect' ), count( $retry ) );
				$retry           = [];
				$retry_in        = HOUR_IN_SECONDS;
				break;
			}
			if ( ! $ids ) {
				$state['finished'] = time();
				break;
			}
			foreach ( $ids as $post_id ) {
				// Stamped first, so a movie that crashes the run isn't picked again next batch.
				update_post_meta( $post_id, '_lbc_details_checked', time() );
				$needs_tmdb = get_post_meta( $post_id, 'director', true ) === ''
					|| get_post_meta( $post_id, 'imdb_id', true ) === ''
					// TMDB's poster is the fallback when Letterboxd's won't download.
					|| ( lbc_needs_poster( $post_id ) && get_post_meta( $post_id, 'tmdb_poster_path', true ) === '' );
				$result     = $has_key && $needs_tmdb ? lbc_fill_from_tmdb( $post_id ) : true;
				if ( is_wp_error( $result ) && $result->get_error_code() === 'lbc_tmdb_bad_key' ) {
					// Every other movie would fail the same way.
					$state['error']    = $result->get_error_message();
					$state['finished'] = time();
					break 2;
				}
				if ( is_wp_error( $result ) && in_array( $result->get_error_code(), [ 'lbc_tmdb_not_found', 'lbc_tmdb_no_match' ], true ) ) {
					$misses[] = $post_id;
				} elseif ( $result === true && $has_key && $needs_tmdb ) {
					$misses = [];
					$sure   = true;
				}
				if ( count( $misses ) >= 5 && ! $sure ) {
					// Five films in a row TMDB doesn't have: make sure it can still find one it does.
					$check = lbc_tmdb_movie( 550 );
					if ( is_wp_error( $check ) ) {
						foreach ( $misses as $missed ) {
							delete_post_meta( $missed, '_lbc_details_checked' );
						}
						if ( lbc_temporary_error( $check ) ) {
							$result = $check; // A rate limit or an outage, not a change: the 15-minute pause below.
						} else {
							$state['error'] = 'TMDB isn’t finding films it has (' . $check->get_error_message() . ') Its API may have changed. Trying again in an hour.';
							$retry_in       = HOUR_IN_SECONDS;
							break 2;
						}
					} else {
						$sure = true;
					}
				}
				if ( is_wp_error( $result ) && lbc_temporary_error( $result ) ) {
					// TMDB is having trouble: unstamp this one and come back later.
					delete_post_meta( $post_id, '_lbc_details_checked' );
					$state['error'] = $result->get_error_message() . ' Trying again in 15 minutes.';
					$retry_in       = 15 * MINUTE_IN_SECONDS;
					break 2;
				}
				$poster = lbc_attach_poster( $post_id );
				if ( is_wp_error( $poster ) && lbc_temporary_error( $poster ) && ! in_array( $post_id, $retry, true ) ) {
					$retry[] = $post_id;
				}
				$state['checked']++;
				$done['checked']++;
				if ( ! lbc_movie_incomplete( $post_id ) ) {
					$state['fixed']++;
					$done['fixed']++;
				}
				if ( microtime( true ) - $started >= LBC_TIME_BUDGET ) {
					break 2;
				}
			}
			$state['error'] = '';
		}

		$state['retry'] = $retry;
		$now            = lbc_backfill_state();
		if ( (int) ( $now['generation'] ?? 0 ) !== (int) ( $state['generation'] ?? 0 ) ) {
			// Queued again while this batch ran: carry on from the new state,
			// with this batch's work added unless it was a restart.
			if ( (int) ( $now['started'] ?? 0 ) === (int) ( $state['started'] ?? 0 ) ) {
				$now['checked'] = (int) ( $now['checked'] ?? 0 ) + $done['checked'];
				$now['fixed']   = (int) ( $now['fixed'] ?? 0 ) + $done['fixed'];
				$now['error']   = $state['error'];
				$now['retry']   = array_values( array_unique( array_merge( (array) ( $now['retry'] ?? [] ), $retry ) ) );
				update_option( LBC_BACKFILL, $now, false );
			}
			$state['finished'] = 0;
			$retry_in          = 10;
		} else {
			update_option( LBC_BACKFILL, $state, false );
		}
		wp_clear_scheduled_hook( LBC_CRON_BACKFILL );
		if ( empty( $state['finished'] ) ) {
			wp_schedule_single_event( time() + $retry_in, LBC_CRON_BACKFILL );
		}
	} finally {
		lbc_unlock( 'backfill' );
	}
}

/**
 * Movies (not in the trash) missing a poster, director or IMDb ID that
 * weren't checked since $cutoff, newest first.
 *
 * @param int $limit -1 for all.
 * @return int[]
 */
function lbc_incomplete_movies( int $cutoff, int $limit ): array {
	$query = new WP_Query( [
		'post_type'              => 'movie',
		'post_status'            => [ 'publish', 'future', 'draft', 'pending', 'private' ],
		'fields'                 => 'ids',
		'posts_per_page'         => $limit,
		'orderby'                => 'ID',
		'order'                  => 'DESC',
		'no_found_rows'          => true,
		'update_post_meta_cache' => false,
		'update_post_term_cache' => false,
		'suppress_filters'       => true,
		'meta_query'             => [
			'relation' => 'AND',
			[
				'relation' => 'OR',
				[
					'relation' => 'AND',
					[ 'key' => '_thumbnail_id', 'compare' => 'NOT EXISTS' ],
					[ 'key' => '_lbc_no_poster', 'compare' => 'NOT EXISTS' ], // Removed by hand.
				],
				[ 'key' => 'director', 'compare' => 'NOT EXISTS' ],
				[ 'key' => 'director', 'value' => '' ],
				[ 'key' => 'imdb_id', 'compare' => 'NOT EXISTS' ],
				[ 'key' => 'imdb_id', 'value' => '' ],
			],
			[
				'relation' => 'OR',
				[ 'key' => '_lbc_details_checked', 'compare' => 'NOT EXISTS' ],
				[ 'key' => '_lbc_details_checked', 'value' => $cutoff, 'compare' => '<', 'type' => 'NUMERIC' ],
			],
		],
	] );
	return array_map( 'intval', $query->posts );
}
