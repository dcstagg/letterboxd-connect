<?php
/**
 * Settings → Letterboxd Connect: the settings form, what's happened so far,
 * and buttons to import now, fill in missing details, or import an export.
 *
 * Settings go through options.php (the Settings API). The buttons post to
 * admin-post.php; each checks a nonce and manage_options, does its work, and
 * redirects back with a message for that user.
 */

defined( 'ABSPATH' ) || exit;

const LBC_PAGE = 'letterboxd-connect';

function lbc_admin_menu(): void {
	add_options_page( 'Letterboxd Connect', 'Letterboxd Connect', 'manage_options', LBC_PAGE, 'lbc_settings_page' );
}
add_action( 'admin_menu', 'lbc_admin_menu' );

function lbc_register_setting(): void {
	register_setting( 'lbc', LBC_SETTINGS, [
		'type'              => 'array',
		'sanitize_callback' => 'lbc_sanitize_settings_input',
		'show_in_rest'      => false,
	] );
}
add_action( 'admin_init', 'lbc_register_setting' );

/** "Settings" link on the Plugins screen. */
function lbc_plugin_action_links( array $links ): array {
	array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=' . LBC_PAGE ) ) . '">Settings</a>' );
	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( LBC_FILE ), 'lbc_plugin_action_links' );

/**
 * Check and clean what was submitted. A blank API key field keeps the saved
 * key (the field never shows it); a new username or key is tried out, and a
 * problem is reported without throwing the value away.
 */
function lbc_sanitize_settings_input( $input ): array {
	$input = is_array( $input ) ? $input : []; // options.php has already unslashed it.
	$input = array_map( fn( $value ) => is_scalar( $value ) ? $value : '', $input ); // A field sent as an array is treated as empty.

	// Already clean: WordPress sanitizing this function's own result a second
	// time (it does when the option is first created), or the plugin saving
	// settings itself. Form input never matches: its values are all strings.
	if ( $input === lbc_normalize_settings( $input ) ) {
		return $input;
	}
	$current = lbc_settings();

	$username = lbc_clean_username( (string) ( $input['username'] ?? '' ) );
	if ( $username === null ) {
		add_settings_error( LBC_SETTINGS, 'lbc_username', 'That isn’t a Letterboxd username (letters, numbers and underscores). The old one was kept.' );
		$username = $current['username'];
	} elseif ( $username !== '' && $username !== $current['username'] ) {
		$feed = lbc_fetch_feed( $username );
		if ( is_wp_error( $feed ) && $feed->get_error_code() === 'lbc_feed_not_found' ) {
			add_settings_error( LBC_SETTINGS, 'lbc_username_check', sprintf( 'Letterboxd has no member called “%s”, so the username wasn’t changed.', $username ) );
			$username = $current['username'];
		} elseif ( is_wp_error( $feed ) ) {
			add_settings_error( LBC_SETTINGS, 'lbc_username_check', 'Saved, but Letterboxd couldn’t be reached to check the username: ' . $feed->get_error_message(), 'warning' );
		}
	}

	$key = trim( (string) ( $input['tmdb_api_key'] ?? '' ) );
	if ( ! empty( $input['remove_tmdb_api_key'] ) ) {
		$key = '';
	} elseif ( $key === '' ) {
		$key = $current['tmdb_api_key'];
	} elseif ( ! lbc_valid_tmdb_key( $key ) ) {
		add_settings_error( LBC_SETTINGS, 'lbc_key', 'That doesn’t look like a TMDB API key (32 letters and numbers) or read access token. The old one was kept.' );
		$key = $current['tmdb_api_key'];
	} elseif ( $key !== $current['tmdb_api_key'] ) {
		$check = lbc_tmdb_check_key( $key );
		if ( is_wp_error( $check ) && $check->get_error_code() === 'lbc_tmdb_bad_key' ) {
			add_settings_error( LBC_SETTINGS, 'lbc_key_check', 'TMDB didn’t accept that key, so the old one was kept.' );
			$key = $current['tmdb_api_key'];
		} elseif ( is_wp_error( $check ) ) {
			add_settings_error( LBC_SETTINGS, 'lbc_key_check', 'Saved, but TMDB couldn’t be reached to check the key: ' . $check->get_error_message(), 'warning' );
		}
	}

	$start = trim( (string) ( $input['start_date'] ?? '' ) );
	if ( $start !== '' && ! lbc_valid_date( $start ) ) {
		add_settings_error( LBC_SETTINGS, 'lbc_start', 'The start date wasn’t a date, so it was cleared.' );
		$start = '';
	}

	$author = (int) ( $input['author'] ?? 0 );
	if ( $author && ! user_can( $author, 'edit_posts' ) ) {
		$author = $current['author'];
	}

	return lbc_normalize_settings( [
		'username'     => $username,
		'tmdb_api_key' => $key,
		'frequency'    => (string) ( $input['frequency'] ?? 'daily' ),
		'draft'        => ! empty( $input['draft'] ),
		'start_date'   => $start,
		'author'       => $author,
	] );
}

/**
 * On the dashboard, Plugins and Movies screens: no username yet, the last
 * import failed or found a gap, or imports have stopped happening.
 */
function lbc_admin_notice(): void {
	$screen = get_current_screen();
	if ( ! $screen || ! current_user_can( 'manage_options' ) || ! in_array( $screen->id, [ 'dashboard', 'plugins', 'edit-movie' ], true ) ) {
		return;
	}
	$problem = lbc_import_problem();
	if ( $problem === '' ) {
		return;
	}
	printf(
		'<div class="notice notice-warning"><p>Letterboxd Connect: %s <a href="%s">Settings</a></p></div>',
		esc_html( $problem ),
		esc_url( admin_url( 'options-general.php?page=' . LBC_PAGE ) )
	);
}
add_action( 'admin_notices', 'lbc_admin_notice' );

/** What's wrong with importing, in a sentence, or ''. */
function lbc_import_problem(): string {
	$settings = lbc_settings();
	$status   = lbc_status();
	if ( $settings['username'] === '' ) {
		return 'add your Letterboxd username to start importing your diary.';
	}
	if ( ! empty( $status['last_error'] ) ) {
		return 'the last import didn’t work: ' . $status['last_error'];
	}
	if ( ! empty( $status['last_warning'] ) ) {
		return $status['last_warning'];
	}
	$interval = (int) ( wp_get_schedules()[ $settings['frequency'] ]['interval'] ?? 0 );
	$last     = (int) ( $status['last_import'] ?? 0 );
	if ( $interval && $last && $last < time() - 3 * $interval - HOUR_IN_SECONDS ) {
		return sprintf( 'nothing has been imported since %s. WP-Cron may not be running: it only runs when someone visits a page that isn’t cached. A server cron job calling wp-cron.php fixes that.', wp_date( get_option( 'date_format' ), $last ) );
	}
	return '';
}

/** A message to show this user on the settings page after a redirect. */
function lbc_flash( string $message, string $type = 'success' ): void {
	set_transient( 'lbc_notice_' . get_current_user_id(), [ 'message' => $message, 'type' => $type ], 5 * MINUTE_IN_SECONDS );
}

/** Buttons are for administrators only. */
function lbc_require_admin(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'You’re not allowed to do that.', 403 );
	}
}

function lbc_back_to_settings(): never {
	wp_safe_redirect( admin_url( 'options-general.php?page=' . LBC_PAGE ) );
	exit;
}

function lbc_handle_import_now(): void {
	lbc_require_admin();
	check_admin_referer( 'lbc_import_now', 'lbc_import_now_nonce' );
	$result = lbc_run_import();
	if ( is_wp_error( $result ) ) {
		lbc_flash( $result->get_error_message(), 'error' );
	} else {
		lbc_flash( lbc_summary_text( $result ) . ( $result['errors'] ? ' ' . implode( ' ', $result['errors'] ) : '' ), $result['failed'] ? 'warning' : 'success' );
	}
	lbc_back_to_settings();
}
add_action( 'admin_post_lbc_import_now', 'lbc_handle_import_now' );

function lbc_handle_backfill(): void {
	lbc_require_admin();
	check_admin_referer( 'lbc_backfill', 'lbc_backfill_nonce' );
	lbc_queue_backfill( true );
	lbc_flash( 'Filling in missing posters and details in the background. This page shows the progress.' );
	lbc_back_to_settings();
}
add_action( 'admin_post_lbc_backfill', 'lbc_handle_backfill' );

function lbc_handle_forget_deleted(): void {
	lbc_require_admin();
	check_admin_referer( 'lbc_forget_deleted', 'lbc_forget_deleted_nonce' );
	delete_option( 'lbc_deleted' );
	lbc_flash( 'Done. Movies you deleted will be imported again if they’re in the feed or an export.' );
	lbc_back_to_settings();
}
add_action( 'admin_post_lbc_forget_deleted', 'lbc_handle_forget_deleted' );

function lbc_handle_csv(): void {
	lbc_require_admin();
	check_admin_referer( 'lbc_csv', 'lbc_csv_nonce' );
	$file = $_FILES['lbc_file'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- checked below; only tmp_name and error are used.
	if ( ! is_array( $file ) || (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) !== UPLOAD_ERR_OK || ! is_uploaded_file( (string) $file['tmp_name'] ) ) {
		$error = (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE );
		lbc_flash( match ( $error ) {
			UPLOAD_ERR_NO_FILE                       => 'Choose the export ZIP (or a CSV from it) first.',
			UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'That file is bigger than this server accepts. Upload diary.csv on its own instead.',
			default                                  => 'The upload didn’t work. Try again.',
		}, 'error' );
		lbc_back_to_settings();
	}

	$result = lbc_import_export_file( (string) $file['tmp_name'], ! empty( $_POST['lbc_create'] ) );
	if ( is_wp_error( $result ) ) {
		lbc_flash( $result->get_error_message(), 'error' );
		lbc_back_to_settings();
	}
	$message = sprintf( _n( 'Read %s diary entry.', 'Read %s diary entries.', $result['read'], 'letterboxd-connect' ), number_format_i18n( $result['read'] ) ) . ' ' . lbc_summary_text( $result );
	if ( $result['ratings_filled'] ) {
		$message .= sprintf( ' Gave %d movies their rating.', $result['ratings_filled'] );
	}
	if ( $result['more'] ) {
		$message = str_replace( 'The rest will be added in a minute.', 'Upload the file again to add the rest.', $message );
	}
	if ( $result['created'] ) {
		$message .= ' Posters and details for the new movies are being filled in in the background.';
	}
	if ( $result['missing'] ) {
		$message .= ' ' . sprintf( _n( '%s entry isn’t on the site; tick “Also add diary entries” to add it.', '%s entries aren’t on the site; tick “Also add diary entries” to add them.', $result['missing'], 'letterboxd-connect' ), number_format_i18n( $result['missing'] ) );
	}
	lbc_flash( $message . ( $result['errors'] ? ' ' . implode( ' ', $result['errors'] ) : '' ), $result['failed'] ? 'warning' : 'success' );
	lbc_back_to_settings();
}
add_action( 'admin_post_lbc_csv', 'lbc_handle_csv' );

function lbc_settings_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$settings = lbc_settings();
	$status   = lbc_status();
	$notice   = get_transient( 'lbc_notice_' . get_current_user_id() );
	if ( $notice ) {
		delete_transient( 'lbc_notice_' . get_current_user_id() );
	}
	$action = admin_url( 'admin-post.php' );
	?>
	<div class="wrap">
		<h1>Letterboxd Connect</h1>

		<?php if ( is_array( $notice ) ) { ?>
			<div class="notice notice-<?php echo esc_attr( in_array( $notice['type'], [ 'success', 'warning', 'error' ], true ) ? $notice['type'] : 'info' ); ?> is-dismissible"><p><?php echo esc_html( (string) $notice['message'] ); ?></p></div>
		<?php } ?>

		<?php lbc_status_box( $settings, $status ); ?>

		<form method="post" action="options.php">
			<?php settings_fields( 'lbc' ); ?>
			<h2>Settings</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="lbc-username">Letterboxd username</label></th>
					<td>
						<input type="text" id="lbc-username" name="<?php echo esc_attr( LBC_SETTINGS ); ?>[username]" value="<?php echo esc_attr( $settings['username'] ); ?>" class="regular-text" autocomplete="off" spellcheck="false">
						<p class="description">Your diary’s feed is read from <code>letterboxd.com/<?php echo esc_html( $settings['username'] ?: 'username' ); ?>/rss/</code>.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="lbc-key">TMDB API key</label></th>
					<td>
						<input type="password" id="lbc-key" name="<?php echo esc_attr( LBC_SETTINGS ); ?>[tmdb_api_key]" value="" class="regular-text" autocomplete="new-password" placeholder="<?php echo esc_attr( $settings['tmdb_api_key'] !== '' ? 'Saved (•••• ' . substr( $settings['tmdb_api_key'], -4 ) . '). Leave blank to keep it.' : '' ); ?>">
						<?php if ( $settings['tmdb_api_key'] !== '' ) { ?>
							<label><input type="checkbox" name="<?php echo esc_attr( LBC_SETTINGS ); ?>[remove_tmdb_api_key]" value="1"> Remove the saved key</label>
						<?php } ?>
						<p class="description">For director, IMDb link, genres and missing posters. Get one free at <a href="https://www.themoviedb.org/settings/api" target="_blank" rel="noopener noreferrer">themoviedb.org/settings/api</a> (the API key or the read access token).</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="lbc-frequency">Check for new entries</label></th>
					<td>
						<select id="lbc-frequency" name="<?php echo esc_attr( LBC_SETTINGS ); ?>[frequency]">
							<?php foreach ( LBC_FREQUENCIES as $value => $label ) { ?>
								<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $settings['frequency'], $value ); ?>><?php echo esc_html( $label ); ?></option>
							<?php } ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="lbc-start">Only entries watched on or after</label></th>
					<td>
						<input type="date" id="lbc-start" name="<?php echo esc_attr( LBC_SETTINGS ); ?>[start_date]" value="<?php echo esc_attr( $settings['start_date'] ); ?>">
						<p class="description">Leave empty to import everything in the feed. Doesn’t affect movies already on the site.</p>
					</td>
				</tr>
				<tr>
					<th scope="row">New movies</th>
					<td>
						<label><input type="checkbox" name="<?php echo esc_attr( LBC_SETTINGS ); ?>[draft]" value="1" <?php checked( $settings['draft'] ); ?>> Save as drafts to publish by hand</label>
						<p>
							<label for="lbc-author">Credit them to</label>
							<?php
							wp_dropdown_users( [
								'name'       => LBC_SETTINGS . '[author]',
								'id'         => 'lbc-author',
								'selected'   => lbc_author_id(),
								'capability' => [ 'edit_posts' ],
							] );
							?>
						</p>
					</td>
				</tr>
			</table>
			<?php submit_button( 'Save Settings' ); ?>
		</form>

		<hr>
		<h2>Import a Letterboxd export</h2>
		<p>The feed only has your last 50 diary entries. Your export (letterboxd.com → Settings → Import &amp; Export → Export your data) has all of them. Importing it corrects watch dates and fills in missing ratings on movies already here.</p>
		<form method="post" action="<?php echo esc_url( $action ); ?>" enctype="multipart/form-data">
			<input type="hidden" name="action" value="lbc_csv">
			<?php wp_nonce_field( 'lbc_csv', 'lbc_csv_nonce' ); ?>
			<p><label for="lbc-file">Export ZIP, or diary.csv from it</label><br>
				<input type="file" id="lbc-file" name="lbc_file" accept=".zip,.csv,application/zip,text/csv" required></p>
			<p><label><input type="checkbox" name="lbc_create" value="1"> Also add diary entries that aren’t on the site yet</label></p>
			<?php if ( $settings['tmdb_api_key'] === '' ) { ?>
				<p class="description">The export has no posters: movies it adds get theirs from TMDB, so add a TMDB API key first.</p>
			<?php } ?>
			<?php submit_button( 'Import Export File', 'secondary', 'lbc_csv_submit', false ); ?>
		</form>

		<hr>
		<p class="description">
			Letterboxd Connect <?php echo esc_html( LBC_VERSION ); ?> ·
			<a href="https://dcstagg.github.io/letterboxd-connect/" target="_blank" rel="noopener noreferrer">Website and changelog</a>.
			Not affiliated with Letterboxd. Film details and posters from <a href="https://www.themoviedb.org" target="_blank" rel="noopener noreferrer">TMDB</a>:
			this product uses the TMDB API but is not endorsed or certified by TMDB.
		</p>
	</div>
	<?php
}

/** What's happened: last import, what's scheduled, movies missing details, and the buttons. */
function lbc_status_box( array $settings, array $status ): void {
	$action     = admin_url( 'admin-post.php' );
	$format     = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
	$next       = wp_next_scheduled( LBC_CRON_IMPORT );
	$incomplete = count( lbc_incomplete_movies( PHP_INT_MAX, -1 ) );
	$backfill   = lbc_backfill_state();
	$total      = (int) ( wp_count_posts( 'movie' )->publish ?? 0 );
	$deleted    = count( lbc_deleted_movies() );
	// WP-Cron runs when someone visits; on a cached, quiet site it can fall behind.
	$late       = fn( $time ) => $time && $time < time() - 10 * MINUTE_IN_SECONDS ? ' (overdue: WP-Cron may not be running)' : '';
	?>
	<div class="card" style="max-width: 48rem;">
		<h2 class="title">Status</h2>
		<p><?php echo esc_html( sprintf( _n( '%s published movie.', '%s published movies.', $total, 'letterboxd-connect' ), number_format_i18n( $total ) ) ); ?></p>
		<ul>
			<li>Last import:
				<?php
				if ( ! empty( $status['last_import'] ) ) {
					echo esc_html( wp_date( $format, (int) $status['last_import'] ) );
					if ( ! empty( $status['last_result'] ) && is_array( $status['last_result'] ) ) {
						echo ' — ' . esc_html( lbc_summary_text( $status['last_result'] ) );
					}
				} else {
					echo 'never';
				}
				?>
			</li>
			<?php if ( ! empty( $status['last_warning'] ) ) { ?>
				<li><strong>Warning:</strong> <?php echo esc_html( $status['last_warning'] ); ?></li>
			<?php } ?>
			<?php if ( ! empty( $status['last_error'] ) ) { ?>
				<li><strong>Last problem</strong> (<?php echo esc_html( wp_date( $format, (int) ( $status['last_error_time'] ?? 0 ) ) ); ?>): <?php echo esc_html( $status['last_error'] ); ?></li>
			<?php } ?>
			<li>Next check:
				<?php
				if ( $settings['username'] === '' ) {
					echo 'add your username below';
				} elseif ( $settings['frequency'] === 'off' ) {
					echo 'off (import by hand)';
				} elseif ( $next ) {
					echo esc_html( wp_date( $format, $next ) . $late( $next ) );
				} else {
					echo 'not scheduled yet';
				}
				?>
			</li>
			<?php if ( lbc_locked( 'import' ) ) { ?>
				<li>An import is running. Reload this page in a minute to see how it went.</li>
			<?php } ?>
			<li>Missing a poster, director or IMDb link: <?php echo esc_html( number_format_i18n( $incomplete ) ); ?>
				<?php if ( $backfill && empty( $backfill['finished'] ) ) { ?>
					— filling in now (<?php echo esc_html( number_format_i18n( (int) ( $backfill['checked'] ?? 0 ) ) ); ?> checked so far)<?php echo esc_html( $late( wp_next_scheduled( LBC_CRON_BACKFILL ) ) ); ?><?php echo ! empty( $backfill['error'] ) ? ' — ' . esc_html( $backfill['error'] ) : ''; ?>
				<?php } elseif ( ! empty( $backfill['error'] ) ) { ?>
					— last attempt stopped: <?php echo esc_html( $backfill['error'] ); ?>
				<?php } ?>
				<?php if ( $incomplete && $settings['tmdb_api_key'] === '' ) { ?>
					— add a TMDB API key to fill in directors and IMDb links
				<?php } ?>
			</li>
			<?php if ( $deleted ) { ?>
				<li><?php echo esc_html( sprintf( _n( '%s movie you deleted won’t be imported again.', '%s movies you deleted won’t be imported again.', $deleted, 'letterboxd-connect' ), number_format_i18n( $deleted ) ) ); ?>
					<form method="post" action="<?php echo esc_url( $action ); ?>" style="display: inline;">
						<input type="hidden" name="action" value="lbc_forget_deleted">
						<?php wp_nonce_field( 'lbc_forget_deleted', 'lbc_forget_deleted_nonce' ); ?>
						<?php submit_button( 'Forget Them', 'small', 'lbc_forget_deleted_submit', false ); ?>
					</form>
				</li>
			<?php } ?>
		</ul>
		<div>
			<form method="post" action="<?php echo esc_url( $action ); ?>" style="display: inline;">
				<input type="hidden" name="action" value="lbc_import_now">
				<?php wp_nonce_field( 'lbc_import_now', 'lbc_import_now_nonce' ); ?>
				<?php submit_button( 'Import Now', 'primary', 'lbc_import_now_submit', false, $settings['username'] === '' || lbc_locked( 'import' ) ? [ 'disabled' => 'disabled' ] : [] ); ?>
			</form>
			<form method="post" action="<?php echo esc_url( $action ); ?>" style="display: inline;">
				<input type="hidden" name="action" value="lbc_backfill">
				<?php wp_nonce_field( 'lbc_backfill', 'lbc_backfill_nonce' ); ?>
				<?php submit_button( 'Fill In Missing Details', 'secondary', 'lbc_backfill_submit', false, $incomplete ? [] : [ 'disabled' => 'disabled' ] ); ?>
			</form>
		</div>
	</div>
	<?php
}
