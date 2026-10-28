<?php
/**
 * Deleting the plugin removes its settings, status and background jobs.
 * Movies and their posters stay: they're the site's content, and the theme
 * shows them whether or not this plugin is installed.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

foreach ( [ 'lbc_settings', 'lbc_status', 'lbc_version', 'lbc_backfill', 'lbc_deleted' ] as $option ) {
	delete_option( $option );
}
// Locks (lbc_lock_*) and per-user notices (lbc_notice_*).
$names = $wpdb->get_col( $wpdb->prepare(
	"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
	$wpdb->esc_like( 'lbc_lock_' ) . '%',
	$wpdb->esc_like( '_transient_lbc_notice_' ) . '%',
	$wpdb->esc_like( '_transient_timeout_lbc_notice_' ) . '%'
) );
foreach ( $names as $name ) {
	delete_option( $name );
}

wp_unschedule_hook( 'lbc_import' );
wp_unschedule_hook( 'lbc_backfill' );

// The background job's "last checked" stamps.
delete_post_meta_by_key( '_lbc_details_checked' );
