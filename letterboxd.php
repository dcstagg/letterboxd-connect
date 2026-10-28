<?php
/**
 * Plugin Name: Letterboxd Connect
 * Plugin URI: https://dcstagg.github.io/letterboxd-connect/
 * Description: Imports your Letterboxd diary into a Movies post type, fills in director, IMDb and poster from TMDB, and shows it with a Movie Grid block.
 * Version: 2.0.6
 * Requires at least: 6.6
 * Requires PHP: 8.4
 * Author: David Stagg
 * Author URI: https://davidstagg.com
 * License: GPL v3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: letterboxd-connect
 * Update URI: https://github.com/dcstagg/letterboxd-connect
 */

defined( 'ABSPATH' ) || exit;

const LBC_VERSION = '2.0.6';
const LBC_FILE    = __FILE__;

// WordPress checks "Requires PHP" on activation, but not when the files are
// replaced under an active plugin (a deploy), so check again before loading.
if ( PHP_VERSION_ID < 80400 ) {
	add_action( 'admin_notices', function () {
		echo '<div class="notice notice-error"><p>Letterboxd Connect needs PHP 8.4 or newer, so it is switched off. This site runs PHP ' . esc_html( PHP_VERSION ) . '.</p></div>';
	} );
	return;
}

require __DIR__ . '/includes/settings.php';
require __DIR__ . '/includes/post-type.php';
require __DIR__ . '/includes/feed.php';
require __DIR__ . '/includes/tmdb.php';
require __DIR__ . '/includes/movies.php';
require __DIR__ . '/includes/import.php';
require __DIR__ . '/includes/csv.php';
require __DIR__ . '/includes/backfill.php';
require __DIR__ . '/includes/block.php';
if ( is_admin() ) {
	require __DIR__ . '/includes/admin.php';
}

/** The post type and taxonomy need fresh rewrite rules, or /movies/ 404s. */
function lbc_activate() {
	lbc_register_post_type();
	lbc_register_taxonomy();
	lbc_maybe_upgrade();
	lbc_schedule_import();
	lbc_resume_backfill();
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'lbc_activate' );

/** Stop the background jobs; movies, settings and the schedule choice stay. */
function lbc_deactivate() {
	wp_unschedule_hook( LBC_CRON_IMPORT );
	wp_unschedule_hook( LBC_CRON_BACKFILL );
	// Unregister first, or the flush keeps the /movies/ rules.
	unregister_post_type( 'movie' );
	unregister_taxonomy( 'movie_year' );
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'lbc_deactivate' );
