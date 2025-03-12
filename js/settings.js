/**
 * Letterboxd Connect Settings Page JavaScript
 */

jQuery(document).ready(($) => {
	// Cache frequently used selectors and constants
	const SELECTORS = {
		startDate: '#start_date',
		usernameField: 'input[name="letterboxd_wordpress_options[username]"]',
		usernameMessage: '#username-validation-message',
		settingsForm: '#letterboxd-settings-form',
		settingsMessage: '#settings-update-message',
		importDetails: '#last-import-details',
		runImportTrigger: '#letterboxd_run_import_trigger',
		// Auto-import selectors
		autoImportFrequency: 'select[name="letterboxd_auto_import_options[frequency]"]',
		autoImportNotifications: 'input[name="letterboxd_auto_import_options[notifications]"]',
		// TMDB API selectors
		tmdbApiKeyField: '#tmdb_api_key',
		validateTmdbButton: '#validate-tmdb-api',
		tmdbValidationResult: '#tmdb-api-validation-result',
		tmdbAuthorizeButton: '#tmdb-authorize-button',
		tmdbAuthStatus: '#tmdb-auth-status'
	};
	
	const CLASSES = {
		notice: {
			error: 'notice-error',
			success: 'notice-success',
			info: 'notice-info',
			hidden: 'hidden'
		}
	};

	const UPDATE_INTERVAL = 30000; // 30 seconds
	const USERNAME_DEBOUNCE = 500; // 500ms debounce for username validation

	// Validate required settings
	if (typeof letterboxdSettings === 'undefined') {
		console.error('Letterboxd settings not found');
		return;
	}

	const { apiRoot, apiNonce, restNamespace } = letterboxdSettings;
		
	if (!apiRoot || !apiNonce) {
		console.error('Required Letterboxd API settings missing');
		return;
	}
	
	// Define default messages
	const messages = {
		errorValidating: "Error validating username.",
		savingSettings: "Saving settings...",
		settingsSaved: "Settings saved successfully.",
		errorSaving: "Error saving settings.",
		lastImport: "Last Import",
		totalImported: "Total Imported",
		nextCheck: "Next Check",
		lastError: "Last Error",
		// TMDB API messages
		testingConnection: "Testing connection...",
		enterApiKey: "Please enter an API key first",
		connectionSuccess: "API connection successful!",
		connectionError: "Error connecting to TMDB API"
	};
	
	function initializeComponents() {
		initializeDatepicker();
		setupUsernameValidation();
		setupTmdbApiValidation();
		setupFormSubmission();
		initializeStatusUpdates();
		setupTmdbAuth();
		setupTmdbUpdateButton();
	}
	
	function initializeDatepicker() {
		$(SELECTORS.startDate).datepicker({
			dateFormat: 'yy-mm-dd',
			maxDate: new Date(),
			changeMonth: true,
			changeYear: true
		});
	}
	
	
	function setupTmdbAuth() {
		const authorizeButton = $(SELECTORS.tmdbAuthorizeButton);
		const authStatusDiv = $(SELECTORS.tmdbAuthStatus);
		
		authorizeButton.on('click', function(e) {
			e.preventDefault();
		
			authStatusDiv.html('<span>Creating authorization request...</span>');
			
			wp.apiFetch({
				path: `${restNamespace}/tmdb-create-request-token`,
				method: 'GET'
			}).then(response => {
				if (response.success && response.auth_url) {
					// Redirect user to TMDB authorization page
					window.open(response.auth_url, "_self");
				} else {
					updateMessage(
						authStatusDiv, 
						response.message || 'Failed to create authorization request.',
						CLASSES.notice.error
					);
				}
			}).catch(error => {
				updateMessage(
					authStatusDiv,
					error.message || 'An error occurred during authorization.',
					CLASSES.notice.error
				);
			});
		});
		
		// Handle callback from TMDB authorization
		if (typeof letterboxdTmdbAuth !== 'undefined' && letterboxdTmdbAuth.request_token) {
			authStatusDiv.html('<span>Creating session...</span>');
			
			wp.apiFetch({
				path: `${restNamespace}/tmdb-create-session`,
				method: 'POST',
				data: {
					request_token: letterboxdTmdbAuth.request_token
				}
			}).then(response => {
				if (response.success) {
					updateMessage(
						authStatusDiv,
						'TMDB authentication successful!',
						CLASSES.notice.success
					);
					
					// Reload page after a delay to show current auth status
					setTimeout(() => window.location.reload(), 2000);
				} else {
					updateMessage(
						authStatusDiv,
						response.message || 'Failed to create session.',
						CLASSES.notice.error
					);
				}
			}).catch(error => {
				updateMessage(
					authStatusDiv,
					error.message || 'An error occurred during session creation.',
					CLASSES.notice.error
				);
			});
		}
	}

	function setupUsernameValidation() {
		let debounceTimer;
		const usernameField = $(SELECTORS.usernameField);
		const messageDiv = $(SELECTORS.usernameMessage);

		usernameField.on('input', function() {
			const username = this.value;
			clearTimeout(debounceTimer);

			if (username) {
				debounceTimer = setTimeout(() => validateUsername(username, messageDiv), USERNAME_DEBOUNCE);
			} else {
				messageDiv.fadeOut();
			}
		});
	}

	function validateUsername(username, messageDiv) {
		wp.apiFetch({
			path: `${restNamespace}/validate-username?username=${encodeURIComponent(username)}`,
			method: 'GET'
		}).then(response => {
			updateMessage(
				messageDiv,
				response.message,
				response.success ? CLASSES.notice.success : CLASSES.notice.error
			);
		}).catch(error => {
			updateMessage(messageDiv, messages.errorValidating, CLASSES.notice.error);
		});
	}
	
	function setupTmdbApiValidation() {
		const validateButton = $(SELECTORS.validateTmdbButton);
		const resultSpan = $(SELECTORS.tmdbValidationResult);

		validateButton.on('click', function() {
			const apiKey = $(SELECTORS.tmdbApiKeyField).val();
			
			if (!apiKey) {
				updateMessage(resultSpan, messages.enterApiKey, CLASSES.notice.error);
				return;
			}
			
			resultSpan.html(`<span>${messages.testingConnection}</span>`);
			
			wp.apiFetch({
				path: `${restNamespace}/validate-tmdb-api?api_key=${encodeURIComponent(apiKey)}`,
				method: 'GET'
			}).then(response => {
				updateMessage(
					resultSpan,
					response.message,
					response.success ? CLASSES.notice.success : CLASSES.notice.error
				);
			}).catch(error => {
				console.error('API validation error:', error);
				updateMessage(
					resultSpan,
					error.message || messages.connectionError,
					CLASSES.notice.error
				);
			});
		});
	}
	
	function setupTmdbUpdateButton() {
		$('.tmdb-bulk-update-section a.button').on('click', function(e) {
			
			// Disable button and add spinner
			const $button = $(this);
			$button.addClass('disabled').css('pointer-events', 'none');
			$button.prepend('<span class="spinner is-active"></span>');
			
			// Add status text
			$button.parent().append('<p class="update-status">Update in progress, please wait...</p>');
		});
	}

	function setupFormSubmission() {
		const form = $(SELECTORS.settingsForm);
		const messageDiv = $(SELECTORS.settingsMessage);
	
		form.on('submit', function(e) {
			e.preventDefault();
			const submitButton = form.find(':submit');
			submitButton.prop('disabled', true);
			
			updateMessage(messageDiv, messages.savingSettings, CLASSES.notice.info);
			
			// Get current tab
			const currentTab = window.location.href.indexOf('tab=advanced') > -1 ? 'advanced' : 'general';
			
			// Create data object with required fields
			const data = {};
			
			// Always include username (required field)
			data.username = letterboxdSettings.settings.username || '';
			
			// Include other fields
			if (currentTab === 'general') {
				// Get from form for general tab
				const formData = new FormData(this);
				data.start_date = formData.get('letterboxd_wordpress_options[start_date]');
				data.draft_status = formData.get('letterboxd_wordpress_options[draft_status]') === '1';
				
				// Get the checkbox value explicitly - be very explicit about the '1' value
				const runImportChecked = formData.get('letterboxd_run_import_trigger') === '1';
				data.run_import_trigger = runImportChecked ? '1' : '0';
				
				data.letterboxd_auto_import_options = {
					frequency: formData.get('letterboxd_auto_import_options[frequency]') || 'daily',
					notifications: formData.get('letterboxd_auto_import_options[notifications]') === '1'
				};
			} else {
				// Use existing values for general tab fields
				data.start_date = letterboxdSettings.settings.start_date || '';
				data.draft_status = letterboxdSettings.settings.draft_status || false;
				
				// IMPORTANT: Always explicitly set run_import_trigger to '0' for advanced tab
				data.run_import_trigger = '0';
			}
			
			// Get TMDB API key regardless of tab
			data.tmdb_api_key = $(SELECTORS.tmdbApiKeyField).val();
			
			wp.apiFetch({
				path: `${restNamespace}/settings`,
				method: 'POST',
				data: data
			}).then(response => {
				updateMessage(
					messageDiv,
					response.message,
					response.success ? CLASSES.notice.success : CLASSES.notice.error
				);
		
				if (response.success) {
					// Reload to show updated status
					setTimeout(() => window.location.reload(), 1000);
				}
			}).catch(error => {
				updateMessage(
					messageDiv, 
					error.message || messages.errorSaving, 
					CLASSES.notice.error
				);
			}).finally(() => {
				submitButton.prop('disabled', false);
			});
		});
	}

	function initializeStatusUpdates() {
		updateImportStatus();
		setInterval(updateImportStatus, UPDATE_INTERVAL);
	}

	function updateImportStatus() {
		wp.apiFetch({
			path: `${restNamespace}/import-status`,
			method: 'GET'
		}).then(response => {
			if (response.success) {
				updateStatusDisplay(response);
			}
		}).catch(error => {
			console.error('Failed to fetch import status:', error);
		});
	}

	function updateStatusDisplay(status) {
		const details = [];
		
		if (status.last_import) {
			details.push(`<p>${messages.lastImport}: ${formatTimestamp(status.last_import)}</p>`);
		}
		if (status.imported_count) {
			details.push(`<p>${messages.totalImported}: ${status.imported_count}</p>`);
		}
		if (status.next_check) {
			details.push(`<p>${messages.nextCheck}: ${formatTimestamp(status.next_check)}</p>`);
		}
		if (status.last_error) {
			details.push(`<p class="error">${messages.lastError}: ${status.last_error}</p>`);
		}
	
		$(SELECTORS.importDetails).html(details.join(''));
	}

	function formatTimestamp(timestamp) {
		return new Date(timestamp * 1000).toLocaleString();
	}

	function updateMessage(div, message, className) {
		div
			.removeClass(`${CLASSES.notice.error} ${CLASSES.notice.success} ${CLASSES.notice.info} ${CLASSES.notice.hidden}`)
			.addClass(className)
			.html(`<p>${message}</p>`)
			.fadeIn();
	}
	
	initializeComponents();
});