<?php
/**
 * Settings, run status, a lock for background jobs, and the upgrade from 1.x.
 *
 * Everything the plugin keeps outside post meta lives in three options with
 * the lbc_ prefix: lbc_settings (what you chose), lbc_status (what last
 * happened) and lbc_version (which version's data is in the database).
 */

defined( 'ABSPATH' ) || exit;

const LBC_SETTINGS      = 'lbc_settings';
const LBC_STATUS        = 'lbc_status';
const LBC_CRON_IMPORT   = 'lbc_import';
const LBC_CRON_BACKFILL = 'lbc_backfill';

/** How often to check the feed => label. Every key but "off" is a core cron schedule. */
const LBC_FREQUENCIES = [
	'hourly'     => 'Every hour',
	'twicedaily' => 'Twice a day',
	'daily'      => 'Once a day',
	'weekly'     => 'Once a week',
	'off'        => 'Never (import by hand)',
];

/** Settings with every key present and every value the right type. */
function lbc_settings(): array {
	$saved = get_option( LBC_SETTINGS, [] );
	return lbc_normalize_settings( is_array( $saved ) ? $saved : [] );
}

/** Coerce stored or migrated values into valid settings; anything invalid falls back to the default. */
function lbc_normalize_settings( array $values ): array {
	$values   = array_map( fn( $value ) => is_scalar( $value ) ? $value : '', $values );
	$username = lbc_clean_username( (string) ( $values['username'] ?? '' ) );
	$key      = trim( (string) ( $values['tmdb_api_key'] ?? '' ) );
	$freq     = (string) ( $values['frequency'] ?? 'daily' );
	$start    = (string) ( $values['start_date'] ?? '' );

	return [
		'username'     => $username ?? '',
		'tmdb_api_key' => lbc_valid_tmdb_key( $key ) ? $key : '',
		'frequency'    => isset( LBC_FREQUENCIES[ $freq ] ) ? $freq : 'daily',
		'draft'        => ! empty( $values['draft'] ),
		'start_date'   => lbc_valid_date( $start ) ? $start : '',
		'author'       => max( 0, (int) ( $values['author'] ?? 0 ) ),
	];
}

/**
 * A Letterboxd username from what was typed: the name itself, "@name", or a
 * profile URL. Null when it can't be a username.
 */
function lbc_clean_username( string $input ): ?string {
	$input = trim( $input );
	if ( $input === '' ) {
		return '';
	}
	if ( preg_match( '~letterboxd\.com/([^/?#]+)~i', $input, $m ) ) {
		$input = $m[1];
	}
	$input = ltrim( $input, '@' );
	// Letterboxd usernames are letters, numbers and underscores.
	return preg_match( '/^\w{1,40}$/', $input ) ? strtolower( $input ) : null;
}

/** A TMDB v3 API key (32 hex characters) or v4 read access token (a JWT). */
function lbc_valid_tmdb_key( string $key ): bool {
	return (bool) preg_match( '/^(?:[a-f0-9]{32}|eyJ[\w-]+\.[\w-]+\.[\w-]+)$/i', $key );
}

/** True for a real calendar date written as YYYY-MM-DD. */
function lbc_valid_date( string $date ): bool {
	return (bool) preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m ) && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
}

/** The user new movies are credited to: the one chosen in settings, else the first administrator. */
function lbc_author_id(): int {
	$author = lbc_settings()['author'];
	if ( $author && get_userdata( $author ) ) {
		return $author;
	}
	$admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'fields' => 'ID' ] );
	return (int) ( $admins[0] ?? 0 );
}

/**
 * What last happened: last_import (time), last_result (summary array),
 * last_error and last_error_time. Not autoloaded; only the settings page and
 * the jobs read it. (The background job keeps its progress in its own option.)
 */
function lbc_status(): array {
	// Read from the database every time: a long run must not write back a copy it read a minute ago.
	wp_cache_delete( LBC_STATUS, 'options' );
	$status = get_option( LBC_STATUS, [] );
	return is_array( $status ) ? $status : [];
}

function lbc_update_status( array $changes ): void {
	update_option( LBC_STATUS, array_merge( lbc_status(), $changes ), false );
}

/** Note a failed run for the settings page, and hand the error back. */
function lbc_record_error( WP_Error $error ): WP_Error {
	lbc_update_status( [ 'last_error' => $error->get_error_message(), 'last_error_time' => time() ] );
	return $error;
}

/**
 * Take a named lock, so a cron run and a click on "Import now" can't import
 * the same entries twice. INSERT IGNORE is atomic, unlike add_option(). A lock
 * older than $ttl seconds belonged to a run that died, and can be taken over.
 */
function lbc_lock( string $name, int $ttl = 600 ): bool {
	global $wpdb;
	$option  = 'lbc_lock_' . $name;
	$expires = time() + $ttl;

	$taken = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')", $option, $expires ) );
	if ( ! $taken ) {
		$held_until = (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option ) );
		if ( $held_until > time() ) {
			return false;
		}
		// Stale: take it over, unless another request just did.
		$taken = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $expires, $option, $held_until ) );
	}
	wp_cache_delete( $option, 'options' );
	return (bool) $taken;
}

function lbc_unlock( string $name ): void {
	global $wpdb;
	$wpdb->delete( $wpdb->options, [ 'option_name' => 'lbc_lock_' . $name ] );
	wp_cache_delete( 'lbc_lock_' . $name, 'options' );
}

/** True while a lock is held (for the settings page). */
function lbc_locked( string $name ): bool {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'lbc_lock_' . $name ) ) > time();
}

/**
 * Bring the database up to this version. Runs on init rather than only on
 * activation, because a deploy replaces the files without reactivating.
 */
function lbc_maybe_upgrade(): void {
	$installed = get_option( 'lbc_version' );
	if ( $installed === LBC_VERSION ) {
		return;
	}
	if ( ! lbc_lock( 'upgrade', 300 ) ) {
		return; // Another request is upgrading.
	}
	try {
		// Another request may have finished upgrading since this one looked.
		wp_cache_delete( 'lbc_version', 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		$installed = get_option( 'lbc_version' );
		if ( $installed === LBC_VERSION ) {
			return;
		}
		if ( ! $installed ) {
			lbc_migrate_from_1x();
		} elseif ( version_compare( (string) $installed, '2.0.5', '<' ) ) {
			// A title saved with "&amp;" after the 1.x migration had run.
			foreach ( lbc_repair_titles() as $post_id ) {
				clean_post_cache( $post_id );
			}
		}
		update_option( 'lbc_version', LBC_VERSION );
		lbc_schedule_import();
	} finally {
		lbc_unlock( 'upgrade' );
	}
}
add_action( 'init', 'lbc_maybe_upgrade', 20 );

/**
 * 1.x kept its settings in several letterboxd_* options, scheduled its own
 * cron hooks, gave roles custom movie capabilities, and stored some data in
 * shapes 2.0 fixes. Every step is safe to run again.
 */
function lbc_migrate_from_1x(): void {
	global $wpdb;

	// Settings.
	$main     = get_option( 'letterboxd_wordpress_options', [] );
	$advanced = get_option( 'letterboxd_wordpress_advanced_options', [] );
	$auto     = get_option( 'letterboxd_auto_import_options', [] );
	$main     = is_array( $main ) ? $main : [];
	$advanced = is_array( $advanced ) ? $advanced : [];
	$auto     = is_array( $auto ) ? $auto : [];
	if ( get_option( LBC_SETTINGS ) === false ) {
		$frequency = (string) ( $auto['frequency'] ?? 'daily' );
		add_option( LBC_SETTINGS, lbc_normalize_settings( [
			'username'     => $main['username'] ?? '',
			'tmdb_api_key' => $advanced['tmdb_api_key'] ?? '',
			'frequency'    => $frequency === 'disabled' ? 'off' : $frequency,
			'draft'        => ! empty( $main['draft_status'] ),
			'start_date'   => $main['start_date'] ?? '',
		] ), '', false );
		$last = (int) get_option( 'letterboxd_last_import', 0 );
		if ( $last > 0 ) {
			lbc_update_status( [ 'last_import' => $last ] );
		}
	}

	// 1.x options and transients all start with letterboxd_, plus one tmdb_rate_limit transient.
	$prefix = $wpdb->esc_like( 'letterboxd_' ) . '%';
	$names  = $wpdb->get_col( $wpdb->prepare(
		"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
		$prefix,
		$wpdb->esc_like( '_transient_letterboxd_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_letterboxd_' ) . '%'
	) );
	foreach ( $names as $name ) {
		delete_option( $name );
	}
	delete_transient( 'tmdb_rate_limit' );

	// 1.x cron hooks (wp_unschedule_hook clears them whatever their arguments).
	foreach ( (array) _get_cron_array() as $hooks ) {
		foreach ( array_keys( (array) $hooks ) as $hook ) {
			if ( str_starts_with( (string) $hook, 'letterboxd_' ) ) {
				wp_unschedule_hook( $hook );
			}
		}
	}

	// 1.x gave roles its own movie capabilities; 2.0 uses the normal post ones.
	$caps = [ 'edit_movie', 'edit_movies', 'edit_others_movies', 'publish_movies', 'read_movie', 'read_private_movies', 'delete_movie', 'delete_movies', 'delete_others_movies', 'delete_published_movies', 'delete_private_movies', 'edit_published_movies', 'edit_private_movies', 'manage_movie_years', 'edit_movie_years', 'delete_movie_years', 'assign_movie_years' ];
	foreach ( wp_roles()->role_objects as $role ) {
		foreach ( $caps as $cap ) {
			if ( $role->has_cap( $cap ) ) {
				$role->remove_cap( $cap );
			}
		}
	}

	// Data 1.x stored in shapes 2.0 fixes.
	$changed = [];

	// Ratings: stars ("★★★½") or decorated text become numbers ("3.5").
	foreach ( $wpdb->get_results( "SELECT meta_id, post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = 'movie_rating' AND meta_value <> ''" ) as $row ) {
		$rating = lbc_normalize_rating( $row->meta_value );
		if ( $rating !== $row->meta_value ) {
			$rating === '' ? delete_metadata_by_mid( 'post', (int) $row->meta_id ) : update_metadata_by_mid( 'post', (int) $row->meta_id, $rating );
			$changed[] = (int) $row->post_id;
		}
	}

	// The TMDB ID lived under two keys; tmdb_id is the one 2.0 reads.
	$missing = $wpdb->get_results( "SELECT m.post_id, m.meta_value FROM {$wpdb->postmeta} m WHERE m.meta_key = 'tmdb_movie_id' AND m.meta_value REGEXP '^[0-9]+$' AND NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} t WHERE t.post_id = m.post_id AND t.meta_key = 'tmdb_id' AND t.meta_value <> '' AND t.meta_value <> '0' )" );
	foreach ( $missing as $row ) {
		update_post_meta( (int) $row->post_id, 'tmdb_id', $row->meta_value );
		$changed[] = (int) $row->post_id;
	}

	array_push( $changed, ...lbc_repair_titles() );

	// Movies imported by cron had no author.
	$author = lbc_author_id();
	if ( $author ) {
		$orphans = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'movie' AND post_author = 0" );
		if ( $orphans ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->posts} SET post_author = %d WHERE post_type = 'movie' AND post_author = 0", $author ) );
			array_push( $changed, ...array_map( 'intval', $orphans ) );
		}
	}

	foreach ( array_unique( $changed ) as $post_id ) {
		clean_post_cache( $post_id );
	}

	// 1.x left some movies without a poster or director; fill them in the background.
	lbc_queue_backfill();
}

/**
 * Titles saved through kses (a cron run has no user, so kses is on) have
 * "&amp;" for "&", or "&amp;amp;" if saved twice. Decoded back, but never
 * as far as a real "<" or ">" (themes print titles unescaped). Safe to run
 * again.
 *
 * @return int[] The movies fixed.
 */
function lbc_repair_titles(): array {
	global $wpdb;
	$fixed = [];
	foreach ( $wpdb->get_results( "SELECT ID, post_title FROM {$wpdb->posts} WHERE post_type = 'movie' AND post_title LIKE '%&%;%'" ) as $row ) {
		// Saved through kses more than once, it can be "&amp;amp;": decoded until it stops changing.
		$title = $row->post_title;
		for ( $i = 0; $i < 5; $i++ ) {
			$decoded = html_entity_decode( $title, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			if ( $decoded === $title || preg_match( '/[<>]/', $decoded ) ) {
				break;
			}
			$title = $decoded;
		}
		if ( $title !== $row->post_title ) {
			$wpdb->update( $wpdb->posts, [ 'post_title' => $title ], [ 'ID' => $row->ID ] );
			$fixed[] = (int) $row->ID;
		}
	}
	return $fixed;
}
