<div class="wrap">
	<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
	
	<h2 class="nav-tab-wrapper">
		<a href="?page=<?php echo esc_attr( self::MENU_SLUG ); ?>&tab=general" class="nav-tab <?php echo esc_attr( $active_tab === 'general' ? 'nav-tab-active' : '' ); ?>">
			<?php esc_html_e( 'General Settings', 'letterboxd-connect' ); ?>
		</a>
		<a href="?page=<?php echo esc_attr( self::MENU_SLUG ); ?>&tab=advanced" class="nav-tab <?php echo esc_attr( $active_tab === 'advanced' ? 'nav-tab-active' : '' ); ?>">
			<?php esc_html_e( 'Advanced Settings', 'letterboxd-connect' ); ?>
		</a>
		<a href="?page=<?php echo esc_attr( self::MENU_SLUG ); ?>&tab=csv_import" class="nav-tab <?php echo esc_attr( $active_tab === 'csv_import' ? 'nav-tab-active' : '' ); ?>">
			<?php esc_html_e( 'CSV Import', 'letterboxd-connect' ); ?>
		</a>
	</h2>

	<div id="letterboxd-settings-container">

		<?php if ( $active_tab === 'csv_import' ): ?>

			<form
				id="letterboxd-csv-import-form"
				action="<?php echo esc_url( admin_url( 'admin-post.php?action=letterboxd_csv_import' ) ); ?>"
				method="post"
				enctype="multipart/form-data"
			>
				<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>

				<table class="form-table">
					<tr>
						<th scope="row">
							<label for="letterboxd_csv_file">
								<?php _e( 'Letterboxd export', 'letterboxd-connect' ); ?>
							</label>
						</th>
						<td>
							<input
								type="file"
								name="letterboxd_csv_file"
								id="letterboxd_csv_file"
								accept=".zip,.csv"
								required
							>
							<p class="description">
								<?php _e( 'Upload your Letterboxd export: either the ZIP or the watched/diary CSV.', 'letterboxd-connect' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Import from CSV', 'letterboxd-connect' ) ); ?>
			</form>

		<?php else: ?>

			<form id="letterboxd-settings-form" action="options.php" method="post">
				<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>

				<?php if ( $active_tab === 'general' ): ?>
					<?php
					settings_fields( self::OPTION_GROUP );
					do_settings_sections( self::MENU_SLUG );
					?>
					<div class="import-after-save">
						<label for="letterboxd_run_import_trigger">
							<input type="checkbox" id="letterboxd_run_import_trigger" name="letterboxd_run_import_trigger" value="1">
							<span class="description"><?php esc_html_e( 'Run an import after save', 'letterboxd-connect' ); ?></span>
						</label>
					</div>
				<?php else: ?>
					<?php
					settings_fields( self::OPTION_GROUP );
					do_settings_sections( self::MENU_SLUG . '_advanced' );
					?>
					<?php $this->render_update_tmdb_button(); ?>
				<?php endif; ?>

				<?php submit_button( __( 'Save Settings', 'letterboxd-connect' ), 'primary', 'save-settings' ); ?>
			</form>

		<?php endif; ?>

	</div>
</div>