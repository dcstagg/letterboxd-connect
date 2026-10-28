<?php
/**
 * Updates from GitHub, in the Plugins screen like any other plugin's.
 *
 * The plugin's "Update URI" header (github.com/dcstagg/letterboxd-connect)
 * tells WordPress not to ask wordpress.org about it, and to ask
 * update_plugins_github.com instead, which is answered here. Each release
 * publishes update.json on the plugin's website (bin/release.sh): the
 * version, its ZIP on GitHub Releases, and its changelog. That file is
 * fetched at most twice a day (an hour after a failure), and only a ZIP from
 * this repo's own releases, for that version, is ever offered.
 */

defined( 'ABSPATH' ) || exit;

const LBC_UPDATE_MANIFEST = 'https://dcstagg.github.io/letterboxd-connect/update.json';
const LBC_UPDATE_RELEASES = 'https://github.com/dcstagg/letterboxd-connect/releases/download/';
const LBC_UPDATE_CACHE    = 'lbc_update_manifest';

/** The latest release as update.json describes it, or null if it can't be had. */
function lbc_update_manifest(): ?array {
	$cached = get_site_transient( LBC_UPDATE_CACHE );
	if ( is_array( $cached ) && array_key_exists( 'manifest', $cached ) ) {
		return $cached['manifest'];
	}
	$response = wp_safe_remote_get( apply_filters( 'lbc_update_manifest_url', LBC_UPDATE_MANIFEST ), [
		'timeout' => 10,
		'headers' => [ 'Accept' => 'application/json' ],
	] );
	$manifest = null;
	if ( ! is_wp_error( $response ) && (int) wp_remote_retrieve_response_code( $response ) === 200 ) {
		$manifest = lbc_valid_manifest( json_decode( (string) wp_remote_retrieve_body( $response ), true ) );
	}
	set_site_transient( LBC_UPDATE_CACHE, [ 'manifest' => $manifest ], $manifest ? 12 * HOUR_IN_SECONDS : HOUR_IN_SECONDS );
	return $manifest;
}

/** update.json's fields, checked, or null if anything essential is missing or odd. */
function lbc_valid_manifest( $data ): ?array {
	if ( ! is_array( $data ) ) {
		return null;
	}
	$text    = fn( string $key ) => is_scalar( $data[ $key ] ?? null ) ? trim( (string) $data[ $key ] ) : '';
	$version = $text( 'version' );
	$package = $text( 'package' );
	if ( ! preg_match( '/^\d+(?:\.\d+){1,3}$/', $version ) || $package !== LBC_UPDATE_RELEASES . 'v' . $version . '/letterboxd-connect.zip' ) {
		return null;
	}
	$wp_version = fn( string $key ) => preg_match( '/^\d+(?:\.\d+){0,2}$/', $text( $key ) ) ? $text( $key ) : '';
	return [
		'version'      => $version,
		'package'      => $package,
		'homepage'     => 'https://dcstagg.github.io/letterboxd-connect/',
		'requires'     => $wp_version( 'requires' ),
		'requires_php' => $wp_version( 'requires_php' ),
		'tested'       => $wp_version( 'tested' ),
		'released'     => lbc_valid_date( $text( 'released' ) ) ? $text( 'released' ) : '',
		'changelog'    => $text( 'changelog' ),
	];
}

/**
 * "Tested up to 7.1" means every 7.1.x, as on wordpress.org; WordPress itself
 * compares full versions, and would call 7.1.3 untested.
 */
function lbc_tested_up_to( string $tested ): string {
	$site = (string) get_bloginfo( 'version' );
	return preg_match( '/^\d+\.\d+$/', $tested ) && str_starts_with( $site, $tested . '.' ) ? $site : $tested;
}

/** Offer the latest release to WordPress's update check. */
function lbc_update_offer( $update, $plugin_data, $plugin_file ) {
	if ( $plugin_file !== plugin_basename( LBC_FILE ) ) {
		return $update;
	}
	$manifest = lbc_update_manifest();
	if ( ! $manifest ) {
		return $update;
	}
	// WordPress compares the version itself, and shows the update only if it's newer.
	return [
		'id'           => (string) ( $plugin_data['UpdateURI'] ?? '' ),
		'slug'         => 'letterboxd-connect',
		'version'      => $manifest['version'],
		'url'          => $manifest['homepage'],
		'package'      => $manifest['package'],
		'requires'     => $manifest['requires'],
		'requires_php' => $manifest['requires_php'],
		'tested'       => lbc_tested_up_to( $manifest['tested'] ),
	];
}
add_filter( 'update_plugins_github.com', 'lbc_update_offer', 10, 3 );

/** "View version details": the release's changelog, in WordPress's plugin details window. */
function lbc_plugin_information( $result, $action, $args ) {
	if ( $action !== 'plugin_information' || ( $args->slug ?? '' ) !== 'letterboxd-connect' ) {
		return $result;
	}
	$manifest = lbc_update_manifest();
	if ( ! $manifest ) {
		return $result;
	}
	return (object) [
		'name'          => 'Letterboxd Connect',
		'slug'          => 'letterboxd-connect',
		'version'       => $manifest['version'],
		'author'        => '<a href="https://davidstagg.com">David Stagg</a>',
		'homepage'      => $manifest['homepage'],
		'requires'      => $manifest['requires'],
		'requires_php'  => $manifest['requires_php'],
		'tested'        => lbc_tested_up_to( $manifest['tested'] ),
		'last_updated'  => $manifest['released'],
		'download_link' => $manifest['package'],
		'sections'      => [
			'description' => '<p>Imports your Letterboxd diary into a Movies section on your site, fills in director, IMDb and poster from TMDB, and shows it with a Movie Grid block.</p><p><a href="' . esc_url( $manifest['homepage'] ) . '" target="_blank" rel="noopener noreferrer">Website and full changelog</a></p>',
			'changelog'   => lbc_changelog_html( $manifest['changelog'] ),
		],
	];
}
add_filter( 'plugins_api', 'lbc_plugin_information', 10, 3 );

/** A changelog section ("- item" lines, `code`, **bold**, [links](https://…)) as HTML. */
function lbc_changelog_html( string $markdown ): string {
	$inline = function ( string $text ): string {
		$html = esc_html( $text );
		$html = (string) preg_replace( '/`([^`]+)`/', '<code>$1</code>', $html );
		$html = (string) preg_replace( '/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $html );
		return (string) preg_replace_callback(
			'/\[([^\]]+)\]\((https:\/\/[^)\s]+)\)/',
			fn( $m ) => '<a href="' . esc_url( html_entity_decode( $m[2], ENT_QUOTES ) ) . '" target="_blank" rel="noopener noreferrer">' . $m[1] . '</a>',
			$html
		);
	};
	$html = '';
	$list = false;
	foreach ( preg_split( '/\R/', $markdown ) as $line ) {
		if ( str_starts_with( $line, '- ' ) ) {
			$html .= ( $list ? '' : '<ul>' ) . '<li>' . $inline( substr( $line, 2 ) ) . '</li>';
			$list  = true;
			continue;
		}
		if ( $list ) {
			$html .= '</ul>';
			$list  = false;
		}
		if ( trim( $line ) !== '' ) {
			$html .= '<p>' . $inline( $line ) . '</p>';
		}
	}
	return wp_kses_post( $html . ( $list ? '</ul>' : '' ) );
}

/** "Check again" on Dashboard → Updates asks GitHub again, not the cached answer. */
function lbc_update_force_check(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: only clears a cache.
	if ( ! empty( $_GET['force-check'] ) && current_user_can( 'update_plugins' ) ) {
		delete_site_transient( LBC_UPDATE_CACHE );
	}
}
add_action( 'load-update-core.php', 'lbc_update_force_check' );

/** After updating to the latest, don't keep offering it from the cache. */
function lbc_update_done( $upgrader, array $hook_extra ): void {
	if ( ( $hook_extra['type'] ?? '' ) === 'plugin' && in_array( plugin_basename( LBC_FILE ), (array) ( $hook_extra['plugins'] ?? [ $hook_extra['plugin'] ?? '' ] ), true ) ) {
		delete_site_transient( LBC_UPDATE_CACHE );
	}
}
add_action( 'upgrader_process_complete', 'lbc_update_done', 10, 2 );
