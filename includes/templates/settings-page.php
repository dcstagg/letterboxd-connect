<div class="wrap">
	<h1><?php echo esc_html(get_admin_page_title()); ?></h1>
	
	<h2 class="nav-tab-wrapper">
		<a href="?page=<?php echo esc_attr(self::MENU_SLUG); ?>&tab=general" class="nav-tab <?php echo esc_attr($active_tab === "general" ? "nav-tab-active" : ""); ?>">
			<?php esc_html_e(
				"General Settings",
				"letterboxd-connect"
			); ?>
		</a>
		<a href="?page=<?php echo esc_attr(self::MENU_SLUG); ?>&tab=advanced" class="nav-tab <?php echo esc_attr($active_tab === "advanced" ? "nav-tab-active" : ""); ?>">
			<?php esc_html_e(
				"Advanced Settings",
				"letterboxd-connect"
			); ?>
		</a>
	</h2>
	
	<div id="letterboxd-settings-container">
		<form id="letterboxd-settings-form" action="options.php" method="post">
			<?php wp_nonce_field(
				self::NONCE_ACTION,
				self::NONCE_NAME
			); ?>
			
			<div id="tab-content-container">
				<?php if ($active_tab === "general"): ?>
					<!-- General Settings Tab -->
					<div id="general-settings" class="tab-content active">
						<?php
						settings_fields(self::OPTION_GROUP);
						do_settings_sections(self::MENU_SLUG);
						?>
					</div>
				<?php else: ?>
					<!-- Advanced Settings Tab -->
					<div id="advanced-settings" class="tab-content active">
						<?php
						settings_fields(self::OPTION_GROUP);
						do_settings_sections(
							self::MENU_SLUG . "_advanced"
						);
						?>
						
						<input type="hidden" name="username" value="<?php echo esc_attr($this->options["username"] ); ?>">
						<input type="hidden" name="start_date" value="<?php echo esc_attr($this->options["start_date"]); ?>">
						<input type="hidden" name="draft_status" value="<?php echo esc_attr($this->options["draft_status"] ? "1" : "0"); ?>">
						
						<?php if (
							empty(
								$this->advanced_options[
									"tmdb_session_id"
								]
							)
						): ?>
						<div class="tmdb-auth-section">
							<h3><?php esc_html_e(
								"TMDB Authorization",
								"letterboxd-connect"
							); ?></h3>
							<p><?php esc_html_e(
								"Authorize this plugin to access your TMDB account for enhanced functionality.",
								"letterboxd-connect"
							); ?></p>
							
							<button type="button" id="tmdb-authorize-button" class="button button-secondary">
								<?php esc_html_e(
									"Authorize with TMDB",
									"letterboxd-connect"
								); ?>
							</button>
							<div id="tmdb-auth-status"></div>
						</div>
						<?php else: ?>
						<div class="tmdb-auth-section">
							<h3><?php esc_html_e(
								"TMDB Authentication",
								"letterboxd-connect"
							); ?></h3>
							<div class="notice notice-success inline">
								<p><?php esc_html_e(
									"Successfully authenticated with TMDB",
									"letterboxd-connect"
								); ?></p>
							</div>
						</div>
						<?php endif; ?>
					</div>
				<?php endif; ?>
			</div>
			
			<div id="username-validation-message" class="notice hidden"></div>
			<div id="settings-update-message" class="notice hidden"></div>
			
			<?php if ($active_tab === "advanced"): ?>
				<!-- In the Advanced Settings Tab, after the TMDB API key section -->
				<div class="tmdb-bulk-update-section">
					<h3><?php esc_html_e(
						"Update TMDB Data",
						"letterboxd-connect"
					); ?></h3>
					<p><?php esc_html_e(
						"Update movie metadata from TMDB for all your existing movies.",
						"letterboxd-connect"
					); ?></p>
					<?php $this->render_update_tmdb_button(); ?>
				</div>
			<?php endif; ?>
			
			<?php if ($active_tab === "general"): ?>
			<div class="import-after-save">
				<label for="letterboxd_run_import_trigger">
					<input type="checkbox" id="letterboxd_run_import_trigger" name="letterboxd_run_import_trigger" value="1">
					<span class="description"><?php esc_html_e(
						"Run an import after save",
						"letterboxd-connect"
					); ?></span>
				</label>
			</div>
			<?php endif; ?>
			
			<?php submit_button(
				__("Save Settings", "letterboxd-connect"),
				"primary",
				"save-settings"
			); ?>
		</form>
	</div>
</div>