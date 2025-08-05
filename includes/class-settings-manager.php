<?php
/**
 * Handles all plugin settings and admin interface functionality
 *
 * @since 1.0.0
 */

// Prevent direct access
if (!defined("ABSPATH")) {
    exit();
}

class Letterboxd_Settings_Manager {
    use LetterboxdSecurity;

    /**
     * Class instance for singleton pattern
     *
     * @var Letterboxd_Settings_Manager
     */
    private static $instance = null;

    private function debug_log($message) {
        letterboxd_debug_log($message, "Settings_Manager");
    }

    /**
     * Plugin settings and constants
     */
    private const OPTION_NAME = "letterboxd_wordpress_options";
    private const OPTION_GROUP = "letterboxd_wordpress_options_group";
    private const MENU_SLUG = "letterboxd-connect";
    private const NONCE_ACTION = "letterboxd_settings_action";
    private const NONCE_NAME = "letterboxd_settings_nonce";
    private const AJAX_ACTION = "letterboxd_ajax_action";
    private const REST_NAMESPACE = "letterboxd-connect/v1";
    private const ADVANCED_OPTION_NAME = "letterboxd_wordpress_advanced_options";

    /**
     * Letterboxd username constraints
     */
    private const USERNAME_MIN_LENGTH = 2;
    private const USERNAME_MAX_LENGTH = 15;
    private const USERNAME_PATTERN = '/^[a-z0-9][a-z0-9-]*[a-z0-9]$/';

    private const SETTINGS_FIELDS = [
        "username" => [
            "label" => "Letterboxd Username",
            "callback" => "render_username_field",
        ],
        "start_date" => [
            "label" => "Start Date",
            "callback" => "render_start_date_field",
        ],
        "draft_status" => [
            "label" => "Import as Draft",
            "callback" => "render_draft_status_field",
        ],
    ];

    /**
     * Advanced settings fields
     */
    private const ADVANCED_SETTINGS_FIELDS = [
        "tmdb_api_key" => [
            "label" => "TMDB API Key",
            "callback" => "render_tmdb_api_key_field",
        ],
    ];

    /**
     * Default settings values
     */
    private const DEFAULT_OPTIONS = [
        "username" => "",
        "start_date" => "",
        "draft_status" => false,
    ];

    /**
     * Default advanced settings values
     */
    private const DEFAULT_ADVANCED_OPTIONS = [
        "tmdb_api_key" => "",
        "tmdb_session_id" => "",
    ];

    /**
     * Stored plugin options
     *
     * @var array
     */
    private array $options;

    /**
     * API Service for external operations
     *
     * @var LetterboxdApiServiceInterface
     */
    private LetterboxdApiServiceInterface $api_service;

    /**
     * Stored advanced plugin options
     *
     * @var array
     */
    private array $advanced_options;

    /**
     * Sanitize and validate all options
     *
     * @param array $options Options to sanitize
     * @return array Sanitized options
     */
    private array $validation_errors = [];
    private array $validated_data = [];

    /**
     * Track if hooks have been setup
     *
     * @var bool
     */
    private static $hooks_setup = false;

    /**
     * Track if REST routes have been registered
     *
     * @var bool
     */
    private static $rest_routes_registered = false;

    /**
     * Initialize the settings manager
     *
     * @param LetterboxdApiServiceInterface $api_service API service for external operations
     */
    public function __construct(LetterboxdApiServiceInterface $api_service) {
        $this->api_service = $api_service;
        // Check if we're on the settings page with a disconnect flag
        if (is_admin() && isset($_GET['page']) && $_GET['page'] === self::MENU_SLUG) {
            if (isset($_GET['tmdb_disconnected'])) {
                wp_cache_delete(self::ADVANCED_OPTION_NAME, 'options');
            }
        }
        $this->load_options();
        $this->setup_hooks();
    }

    /**
     * Get class instance (singleton pattern)
     *
     * @param LetterboxdApiServiceInterface $api_service API service for external operations
     * @return Letterboxd_Settings_Manager
     */
    public static function get_instance(
        LetterboxdApiServiceInterface $api_service
    ) {
        if (null === self::$instance) {
            self::$instance = new self($api_service);
        }
        return self::$instance;
    }

    /**
     * Handle TMDB Disconnections from the API
     * 
     */
    public function handle_tmdb_disconnect(): void {
        // Verify nonce
        if (
            !isset($_POST['_wpnonce']) ||
            !wp_verify_nonce($_POST['_wpnonce'], 'letterboxd_disconnect_tmdb')
        ) {
            wp_die(__('Security check failed.', 'letterboxd-connect'));
        }

        // Verify permissions
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to do this.', 'letterboxd-connect'));
        }

        // Get current options directly from database (not cached)
        $options = get_option(self::ADVANCED_OPTION_NAME, []);
        
        // Remove the session ID
        unset($options['tmdb_session_id']);
        
        // Update the database - use false for autoload to ensure it's saved
        delete_option(self::ADVANCED_OPTION_NAME); // First delete to ensure clean save
        add_option(self::ADVANCED_OPTION_NAME, $options, '', 'no'); // Then add fresh
        
        // Clear any WordPress object cache
        wp_cache_delete(self::ADVANCED_OPTION_NAME, 'options');
        
        // Redirect with success parameter
        wp_redirect(admin_url('options-general.php?page=letterboxd-connect&tab=advanced&tmdb_disconnected=true'));
        exit;
    }


    /**
     * Set up WordPress hooks
     */
    private function setup_hooks(): void {
        // Only set up hooks once to prevent duplicates
        if (self::$hooks_setup) {
            return;
        }
        self::$hooks_setup = true;

        // Admin menu and settings
        add_action("admin_menu", [$this, "add_settings_page"]);
        add_action("admin_init", [$this, "register_settings"]);
        add_action("admin_enqueue_scripts", [$this, "enqueue_admin_assets"]);
        add_action("rest_api_init", [$this, "register_rest_routes"]);

        // Add plugin action links
        add_filter(
            "plugin_action_links_" . plugin_basename(LETTERBOXD_PLUGIN_FILE),
            [$this, "add_plugin_action_links"]
        );

        // Add custom column hooks for the 'movie' post type
        add_filter("manage_movie_posts_columns", [$this, "add_movie_columns"]);
        add_action(
            "manage_movie_posts_custom_column",
            [$this, "display_movie_columns"],
            10,
            2
        );

        add_action("admin_post_update_tmdb_data", [
            $this,
            "handle_tmdb_data_update",
        ]);
        add_action("admin_init", [$this, "handle_tmdb_auth_callback"]);
        
        add_action('admin_post_letterboxd_disconnect_tmdb', [$this, 'handle_tmdb_disconnect']);
    }

    /**
     * Register a new REST route
     *
     * @param string $route Route to register
     * @param array $args Route arguments
     */
    private function register_route(string $route, array $args): void {
        register_rest_route(self::REST_NAMESPACE, $route, $args);
    }

    /**
     * Register REST API routes
     */
    public function register_rest_routes(): void {
        // Only register routes once
        if (self::$rest_routes_registered) {
            return;
        }

        self::$rest_routes_registered = true;

        // Only log if API service is missing - an actual issue
        if (!isset($this->api_service)) {
            letterboxd_debug_log(
                "ERROR: API Service not available when registering REST routes"
            );
            return;
        }

        $this->register_route("/settings", [
            "methods" => "POST",
            "callback" => [$this, "update_settings"],
            "permission_callback" => fn() => current_user_can("manage_options"),
            "args" => [
                "username" => [
                    "required" => true,
                    "sanitize_callback" => "sanitize_text_field",
                ],
                "start_date" => [
                    "sanitize_callback" => "sanitize_text_field",
                ],
                "draft_status" => [
                    "sanitize_callback" => "rest_sanitize_boolean",
                ],
                "run_import_trigger" => [
                    "sanitize_callback" => "sanitize_text_field",
                    "default" => "0",
                ],
                "tmdb_api_key" => [
                    "sanitize_callback" => "sanitize_text_field",
                ],
            ],
        ]);

        $this->register_route("/tmdb-create-request-token", [
            "methods" => "GET",
            "callback" => [$this, "create_tmdb_request_token"],
            "permission_callback" => fn() => current_user_can("manage_options"),
        ]);

        $this->register_route("/tmdb-create-session", [
            "methods" => "POST",
            "callback" => [$this, "create_tmdb_session"],
            "permission_callback" => fn() => current_user_can("manage_options"),
            "args" => [
                "request_token" => [
                    "required" => true,
                    "sanitize_callback" => "sanitize_text_field",
                ],
            ],
        ]);

        $this->register_route("/validate-username", [
            "methods" => "GET",
            "callback" => [$this, "validate_username"],
            "permission_callback" => fn() => current_user_can("manage_options"),
            "args" => [
                "username" => [
                    "required" => true,
                    "sanitize_callback" => "sanitize_text_field",
                ],
            ],
        ]);

        $this->register_route("/validate-tmdb-api", [
            "methods" => "GET",
            "callback" => [$this, "validate_tmdb_api"],
            "permission_callback" => fn() => current_user_can("manage_options"),
            "args" => [
                "api_key" => [
                    "required" => true,
                    "sanitize_callback" => "sanitize_text_field",
                ],
            ],
        ]);

        $this->register_route("/import-status", [
            "methods" => "GET",
            "callback" => [$this, "get_import_status"],
            "permission_callback" => fn() => current_user_can("manage_options"),
        ]);
    }

    /**
     * Validate TMDB API key
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function validate_tmdb_api(
        WP_REST_Request $request
    ): WP_REST_Response {
        $api_key = $request->get_param("api_key");
        $validation_result = $this->api_service->checkTmdbApiKey($api_key);

        if (is_wp_error($validation_result)) {
            return new WP_REST_Response(
                [
                    "success" => false,
                    "message" => $validation_result->get_error_message(),
                ],
                400
            );
        }

        return new WP_REST_Response(
            [
                "success" => true,
                "message" => __("API key is valid.", "letterboxd-connect"),
            ],
            200
        );
    }

    /**
     * Check if TMDB authentication is complete
     *
     * @return bool True if authenticated
     */
    private function is_tmdb_authenticated(): bool {
        return !empty($this->advanced_options["tmdb_api_key"]) &&
            !empty($this->advanced_options["tmdb_session_id"]);
    }

    /**
     * Handle the authorization callback from TMDB
     */
    public function handle_tmdb_auth_callback(): void {
        // Store flag for script enqueuing
        if (isset($_GET["tmdb_auth"]) && $_GET["tmdb_auth"] === "callback") {
            $request_token = get_transient("letterboxd_tmdb_request_token");
            if (!empty($request_token)) {
                // Store for later use in enqueue_admin_assets
                set_transient(
                    "letterboxd_tmdb_auth_callback",
                    $request_token,
                    HOUR_IN_SECONDS
                );
            } else {
                add_settings_error(
                    "letterboxd_messages",
                    "tmdb_auth_error",
                    __(
                        "Authentication session expired. Please try again.",
                        "letterboxd-connect"
                    )
                );
            }
        }
    }

    /**
     * Update settings via REST API
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function update_settings(
        WP_REST_Request $request
    ): WP_REST_Response {
        if (!current_user_can("manage_options")) {
            return new WP_REST_Response(
                [
                    "success" => false,
                    "message" => __(
                        "Insufficient permissions",
                        "letterboxd-connect"
                    ),
                ],
                403
            );
        }

        $posted_data = $request->get_params();
        //letterboxd_debug_log('update_settings POST data: ' . print_r($posted_data, true));

        // First, make sure any old flag is cleared to prevent accidental imports
        delete_option("letterboxd_run_import_flag");

        // Save main settings
        $main_settings = $this->sanitize_options([
            "username" => $posted_data["username"] ?? "",
            "start_date" => $posted_data["start_date"] ?? "",
            "draft_status" => !empty($posted_data["draft_status"]),
        ]);
        update_option(self::OPTION_NAME, $main_settings);

        // Save advanced settings if present
        if (isset($posted_data["tmdb_api_key"])) {
            // Get existing options to preserve tmdb_session_id
            $advanced_settings = get_option(self::ADVANCED_OPTION_NAME, []);
            $advanced_settings["tmdb_api_key"] = sanitize_text_field(
                $posted_data["tmdb_api_key"]
            );
            update_option(self::ADVANCED_OPTION_NAME, $advanced_settings);
        }

        // Save auto-import settings
        if (isset($posted_data["letterboxd_auto_import_options"])) {
            $auto_import_settings = [
                "frequency" => isset(
                    $posted_data["letterboxd_auto_import_options"]["frequency"]
                )
                    ? sanitize_text_field(
                        $posted_data["letterboxd_auto_import_options"][
                            "frequency"
                        ]
                    )
                    : "daily",
                "notifications" => !empty(
                    $posted_data["letterboxd_auto_import_options"][
                        "notifications"
                    ]
                ),
            ];

            // First, verify we're not recreating the same value
            $existing = get_option("letterboxd_auto_import_options", []);
            if ($existing != $auto_import_settings) {
                // Use true for autoload parameter
                $update_result = update_option(
                    "letterboxd_auto_import_options",
                    $auto_import_settings,
                    true
                );

                // Also update the import interval option for compatibility
                update_option(
                    "letterboxd_import_interval",
                    $auto_import_settings["frequency"],
                    true
                );

                set_transient("letterboxd_settings_just_updated", true, 60); // 60 seconds

                // Only update the schedule if the settings were successfully saved
                if ($update_result) {
                    $auto_import = new Letterboxd_Auto_Import(
                        Letterboxd_To_WordPress::get_instance()
                    );
                    $auto_import->update_import_schedule(
                        $auto_import_settings["frequency"]
                    );
                }
            }
        }

        // IMPORTANT: Check the explicit value - use strict === comparison
        $run_import =
            isset($posted_data["run_import_trigger"]) &&
            $posted_data["run_import_trigger"] === "1";

        // Only run an import if explicitly requested
        if ($run_import) {
            letterboxd_debug_log(
                "Manual import explicitly requested - running now"
            );

            // Use a do_action with a priority to ensure it runs after all settings are saved
            add_action(
                "shutdown",
                function () {
                    letterboxd_debug_log("Running import on shutdown hook");
                    do_action("letterboxd_check_and_import");
                },
                999
            );
        }

        return new WP_REST_Response(
            [
                "success" => true,
                "message" => __(
                    "Settings saved successfully.",
                    "letterboxd-connect"
                ),
            ],
            200
        );
    }

    /**
     * Validate Letterboxd username
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function validate_username(
        WP_REST_Request $request
    ): WP_REST_Response {
        $username = $request->get_param("username");
        $validation_result = $this->validate_letterboxd_username($username);

        if (is_wp_error($validation_result)) {
            return new WP_REST_Response(
                [
                    "success" => false,
                    "message" => $validation_result->get_error_message(),
                ],
                400
            );
        }

        return new WP_REST_Response(
            [
                "success" => true,
                "message" => __("Username is valid.", "letterboxd-connect"),
            ],
            200
        );
    }

    /**
     * Get import status for REST API
     *
     * @return WP_REST_Response
     */
    public function get_import_status(): WP_REST_Response {
        $interval = get_option("letterboxd_import_interval", "hourly");
        $last_import = get_option("letterboxd_last_import", 0);

        $schedules = wp_get_schedules();
        $interval_seconds = isset($schedules[$interval])
            ? $schedules[$interval]["interval"]
            : 3600;

        $next_check = $last_import + $interval_seconds;

        return new WP_REST_Response(
            [
                "success" => true,
                "last_import" => $last_import,
                "next_check" => $next_check,
                "imported_count" => get_option("letterboxd_imported_count", 0),
            ],
            200
        );
    }

    /**
     * Load and cache plugin options
     */
    private function load_options(): void {
        // Force refresh if we just disconnected
        $force_refresh = isset($_GET['tmdb_disconnected']) && $_GET['tmdb_disconnected'] === 'true';
        
        if ($force_refresh) {
            // Clear any cached values
            wp_cache_delete(self::OPTION_NAME, 'options');
            wp_cache_delete(self::ADVANCED_OPTION_NAME, 'options');
        }
        
        $saved_options = get_option(self::OPTION_NAME, []);
        $this->options = wp_parse_args($saved_options, self::DEFAULT_OPTIONS);

        $saved_advanced_options = get_option(self::ADVANCED_OPTION_NAME, []);
        $this->advanced_options = wp_parse_args(
            $saved_advanced_options,
            self::DEFAULT_ADVANCED_OPTIONS
        );
    }

    /**
     * Add settings page to WordPress admin
     */
    public function add_settings_page(): void {
        add_options_page(
            __("Letterboxd Connect Settings", "letterboxd-connect"),
            __("Letterboxd Connect", "letterboxd-connect"),
            "manage_options",
            self::MENU_SLUG,
            [$this, "render_settings_page"]
        );
    }

    /**
     * Register settings fields for a section
     *
     * @param string $section Section ID
     * @param array $fields Fields to register
     * @param string $page Page slug
     */
    private function register_settings_field(
        string $section,
        array $fields,
        string $page
    ): void {
        foreach ($fields as $field => $data) {
            add_settings_field(
                $field,
                $data["label"],
                [$this, $data["callback"]],
                $page,
                $section,
                ['label_for' => $field]
            );
        }
    }

    /**
     * Register plugin settings
     */
    public function register_settings(): void {
        // Register settings
        register_setting(self::OPTION_GROUP, self::OPTION_NAME, [
            "type" => "object",
            "default" => self::DEFAULT_OPTIONS,
            "sanitize_callback" => [$this, "sanitize_options"],
        ]);

        // Register advanced settings
        register_setting(self::OPTION_GROUP, self::ADVANCED_OPTION_NAME, [
            "type" => "object",
            "default" => self::DEFAULT_ADVANCED_OPTIONS,
            "sanitize_callback" => [$this, "sanitize_advanced_options"],
        ]);

        add_settings_section(
            "letterboxd_wordpress_main",
            __("Main Settings", "letterboxd-connect"),
            [$this, "render_settings_description"],
            self::MENU_SLUG
        );

        add_settings_section(
            "letterboxd_wordpress_advanced",
            __("Advanced Settings", "letterboxd-connect"),
            [$this, "render_advanced_settings_description"],
            self::MENU_SLUG . "_advanced"
        );

        // Register fields
        $this->register_settings_field(
            "letterboxd_wordpress_main",
            self::SETTINGS_FIELDS,
            self::MENU_SLUG
        );
        $this->register_settings_field(
            "letterboxd_wordpress_advanced",
            self::ADVANCED_SETTINGS_FIELDS,
            self::MENU_SLUG . "_advanced"
        );
    }

    /**
     * Render settings page content with tabs
     */
    public function render_settings_page(): void {
        if (!current_user_can("manage_options")) {
            return;
        }

        // Refresh options from database so the session removal is reflected
        $this->advanced_options = get_option(self::ADVANCED_OPTION_NAME, []);
        $this->options = get_option(self::OPTION_NAME, []);

        $active_tab = isset($_GET["tab"]) ? sanitize_key($_GET["tab"]) : "general";

        include_once plugin_dir_path(__FILE__) . 'templates/settings-page.php';
    }


    /**
     * Render advanced settings description
     */
    public function render_advanced_settings_description(): void {
        ?>
        <p>
            <?php esc_html_e(
                "Configure advanced settings for enhancing your Letterboxd imports with additional data sources.",
                "letterboxd-connect"
            ); ?>
        </p>
        <?php
    }

    /**
     * Render TMDB API key field
     */
    public function render_tmdb_api_key_field(): void {
        printf(
            '<div class="api-key-wrapper">
                <div class="key-button">
                    <input type="text" name="%s[tmdb_api_key]" id="tmdb_api_key" value="%s" class="regular-text">
                    <button type="button" id="validate-tmdb-api" class="button button-secondary">%s</button>
                </div>
                <div class="helper-description">
                    <p class="description">%s <a href="%s" target="_blank">%s</a></p>
                </div>
            </div>
            <span id="tmdb-api-validation-result"></span>',
            esc_attr(self::ADVANCED_OPTION_NAME),
            esc_attr($this->advanced_options["tmdb_api_key"]),
            esc_html__("Test Connection", "letterboxd-connect"),
            esc_html__(
                "Your API key from The Movie Database:",
                "letterboxd-connect"
            ),
            esc_url("https://developer.themoviedb.org/docs/getting-started"),
            esc_html__("Get one here", "letterboxd-connect")
        );
    }

    /**
     * Render the TMDB update button with progress tracking
     */
    public function render_update_tmdb_button(): void {
        $url = wp_nonce_url(
            admin_url("admin-post.php?action=update_tmdb_data"),
            "update_tmdb_data"
        );

        echo '<div class="tmdb-update-section">';
        printf(
            '<a href="%s" class="button button-secondary tmdb-update-button">%s</a>',
            esc_url($url),
            esc_html__(
                "Update TMDB Data for All Movies",
                "letterboxd-connect"
            )
        );

        echo '<p class="description">' .
            esc_html__(
                "Fetch and update TMDB data for all existing movies. This process runs in batches to prevent timeouts.",
                "letterboxd-connect"
            ) .
            "</p>";

        // Add progress bar container
        echo '<div id="tmdb-update-progress" class="tmdb-update-progress" style="display:none; margin-top: 10px;">';
        echo '<div class="tmdb-progress-bar"><div class="tmdb-progress-inner"></div></div>';
        echo '<p class="tmdb-progress-status">' .
            esc_html__("Processing...", "letterboxd-connect") .
            ' <span class="tmdb-progress-count">0</span>%</p>';
        echo "</div>";

        echo "</div>";

        // Add inline styles for progress bar
        echo '<style>
            .tmdb-progress-bar {
                height: 20px;
                background-color: #f0f0f0;
                border-radius: 4px;
                overflow: hidden;
                margin-bottom: 10px;
            }
            .tmdb-progress-inner {
                height: 100%;
                background-color: #0073aa;
                width: 0%;
                transition: width 0.3s ease;
            }
            .tmdb-progress-status {
                font-size: 14px;
                color: #555;
            }
            .tmdb-update-button.processing {
                pointer-events: none;
                opacity: 0.7;
            }
        </style>';
        // Add inline JavaScript for progress tracking
        ?>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            const updateButton = document.querySelector('.tmdb-update-button');
            const progressDiv = document.getElementById('tmdb-update-progress');
            const progressBar = document.querySelector('.tmdb-progress-inner');
            const progressCount = document.querySelector('.tmdb-progress-count');
            
            if (updateButton && progressDiv) {
                updateButton.addEventListener('click', function(e) {
                    if (confirm('<?php echo esc_js(
                        __(
                            "This process may take several minutes depending on your number of movies. Continue?",
                            "letterboxd-connect"
                        )
                    ); ?>')) {
                        e.preventDefault();
                        
                        // Show progress bar and disable button
                        progressDiv.style.display = 'block';
                        updateButton.classList.add('processing');
                        updateButton.innerHTML = '<?php echo esc_js(
                            __("Processing...", "letterboxd-connect")
                        ); ?>';
                        
                        // Function to update progress through AJAX
                        const updateProgress = (currentBatch = 1) => {
                            // Make AJAX request to start the process
                            const url = e.target.href;
                            
                            // Add batch parameter to URL if this isn't the first batch
                            const batchUrl = currentBatch > 1 
                                ? url + '&batch=' + currentBatch 
                                : url;
                            
                            fetch(batchUrl)
                                .then(response => {
                                    // Check if redirected to the next batch
                                    const redirectUrl = response.url;
                                    
                                    if (redirectUrl.includes('batch=')) {
                                        // Extract batch number
                                        const batchMatch = redirectUrl.match(/batch=(\d+)/);
                                        if (batchMatch && batchMatch[1]) {
                                            const nextBatch = parseInt(batchMatch[1]);
                                            
                                            // Update progress
                                            const progress = Math.min(90, (nextBatch - 1) * 10); // Estimate progress
                                            progressBar.style.width = progress + '%';
                                            progressCount.textContent = progress;
                                            
                                            // Continue with next batch
                                            updateProgress(nextBatch);
                                        }
                                    } else if (redirectUrl.includes('updated=true')) {
                                        // Process completed
                                        progressBar.style.width = '100%';
                                        progressCount.textContent = '100';
                                        
                                        // Reload page after short delay
                                        setTimeout(() => {
                                            window.location.reload();
                                        }, 1000);
                                    } else {
                                        // Something went wrong, reload
                                        window.location.reload();
                                    }
                                })
                                .catch(error => {
                                    console.error('Error updating TMDB data:', error);
                                    alert('<?php echo esc_js(
                                        __(
                                            "An error occurred. Please try again.",
                                            "letterboxd-connect"
                                        )
                                    ); ?>');
                                    window.location.reload();
                                });
                        };
                        
                        // Start the process
                        updateProgress();
                    }
                });
            }
        });
        </script>
        <?php
    }

    /**
     * Handle TMDB data update request
     */
    /**
     * Handle TMDB data update request
     */
    public function handle_tmdb_data_update(): void {
        // Verify nonce
        if (
            !isset($_GET["_wpnonce"]) ||
            !wp_verify_nonce($_GET["_wpnonce"], "update_tmdb_data")
        ) {
            wp_die(esc_html__("Security check failed.", "letterboxd-connect"));
        }

        // Verify permissions
        if (!current_user_can("manage_options")) {
            wp_die(
                esc_html__(
                    "You do not have sufficient permissions to perform this action.",
                    "letterboxd-connect"
                )
            );
        }

        // Tracking variables for the entire process
        $processed_total =
            (int) get_transient("letterboxd_tmdb_update_processed") ?: 0;
        $updated_total =
            (int) get_transient("letterboxd_tmdb_update_success") ?: 0;
        $failed_total =
            (int) get_transient("letterboxd_tmdb_update_failed") ?: 0;

        // Get TMDB handler
        $tmdb_handler = new Letterboxd_TMDB_Handler();

        // Check if API key is configured
        if (!$tmdb_handler->is_api_key_configured()) {
            add_settings_error(
                "letterboxd_messages",
                "tmdb_api_missing",
                __("TMDB API key is not configured.", "letterboxd-connect")
            );
            set_transient("settings_errors", get_settings_errors(), 30);
            wp_redirect(
                admin_url(
                    "options-general.php?page=letterboxd-connect&tab=advanced&error=api_missing"
                )
            );
            exit();
        }

        // Get batch size from request or use default
        $batch_size = isset($_GET["batch_size"])
            ? (int) $_GET["batch_size"]
            : 20;
        $batch_size = max(5, min(50, $batch_size)); // Ensure between 5-50

        // Get batch number
        $batch = isset($_GET["batch"]) ? (int) $_GET["batch"] : 1;

        // Get advanced options for region
        $options = get_option("letterboxd_wordpress_advanced_options", []);
        $region = $options["streaming_region"] ?? "US";

        // Get movies for this batch - use small memory limit
        $args = [
            "post_type" => "movie",
            "posts_per_page" => $batch_size,
            "paged" => $batch,
            "orderby" => "date",
            "order" => "DESC",
            "fields" => "ids", // Only get IDs for efficiency
            "no_found_rows" => true, // Don't calculate found rows for better performance
            "update_post_meta_cache" => false, // Don't include post meta in query
            "update_post_term_cache" => false, // Don't include term cache in query
        ];

        $query = new WP_Query($args);
        $post_ids = $query->posts;
        $found_posts = $query->found_posts;

        // Process the batch
        if (!empty($post_ids)) {
            // Log start of operation
            letterboxd_debug_log(
                sprintf(
                    "Starting TMDB data update batch %d with %d movies",
                    $batch,
                    count($post_ids)
                )
            );

            // Use batch update method
            $results = $tmdb_handler->batch_update_streaming_providers(
                $post_ids,
                $region
            );

            // Update tracking totals
            $processed_total += count($post_ids);
            $updated_total += $results["updated"];
            $failed_total += $results["failed"];

            // Store updated counts
            set_transient(
                "letterboxd_tmdb_update_processed",
                $processed_total,
                DAY_IN_SECONDS
            );
            set_transient(
                "letterboxd_tmdb_update_success",
                $updated_total,
                DAY_IN_SECONDS
            );
            set_transient(
                "letterboxd_tmdb_update_failed",
                $failed_total,
                DAY_IN_SECONDS
            );

            // Store results in transient for display
            $prev_results = get_transient("letterboxd_tmdb_update_results") ?: [
                "updated" => 0,
                "failed" => 0,
                "total_processed" => 0,
                "total_posts" => $query->found_posts,
            ];

            $updated_results = [
                "updated" => $prev_results["updated"] + $results["updated"],
                "failed" => $prev_results["failed"] + $results["failed"],
                "total_processed" =>
                    $prev_results["total_processed"] + count($post_ids),
                "total_posts" => $query->found_posts,
            ];

            set_transient(
                "letterboxd_tmdb_update_results",
                $updated_results,
                DAY_IN_SECONDS
            );

            // Check if there are more items to process
            if (
                $updated_results["total_processed"] <
                $updated_results["total_posts"]
            ) {
                // Explicitly clean up to free memory
                $query = null;
                $post_ids = null;
                $results = null;
                $prev_results = null;
                $updated_results = null;

                if (function_exists("wp_cache_flush")) {
                    wp_cache_flush();
                }

                // Redirect to next batch
                $next_batch_url = add_query_arg(
                    [
                        "action" => "update_tmdb_data",
                        "_wpnonce" => wp_create_nonce("update_tmdb_data"),
                        "batch" => $batch + 1,
                        "batch_size" => $batch_size,
                    ],
                    admin_url("admin-post.php")
                );

                wp_redirect($next_batch_url);
                exit();
            }
        }

        // Process completed - prepare summary message
        $message = sprintf(
            /* translators: 1: Number of processed items, 2: Number of updated items, 3: Number of failed items */
            __(
                "TMDB data update completed. Processed: %1\$d, Updated: %2\$d, Failed: %3\$d",
                "letterboxd-connect"
            ),
            $processed_total,
            $updated_total,
            $failed_total
        );

        // Clear the transients used for tracking
        delete_transient("letterboxd_tmdb_update_processed");
        delete_transient("letterboxd_tmdb_update_success");
        delete_transient("letterboxd_tmdb_update_failed");
        delete_transient("letterboxd_tmdb_update_results");

        // Add message and redirect back to settings
        add_settings_error(
            "letterboxd_messages",
            "tmdb_update_complete",
            $message,
            "success"
        );

        set_transient("settings_errors", get_settings_errors(), 30);
        wp_redirect(
            admin_url(
                "options-general.php?page=letterboxd-connect&tab=advanced&updated=true"
            )
        );
        exit();
    }

    /**
     * Enqueue admin scripts and styles with proper dependencies
     */
    public function enqueue_admin_assets(string $hook): void {
        // Only load on our settings page
        if ("settings_page_" . self::MENU_SLUG !== $hook) {
            return;
        }

        $plugin_url = plugin_dir_url(LETTERBOXD_PLUGIN_FILE);

        // jQuery UI styles first (dependency for admin styles)
        wp_enqueue_style(
            "letterboxd-jquery-ui",
            plugin_dir_url(__FILE__) . 'assets/css/jquery-ui.min.css',
            [],
            "1.12.1"
        );

        // Admin styles
        wp_enqueue_style(
            "letterboxd-admin",
            $plugin_url . "css/admin.css",
            ["letterboxd-jquery-ui"],
            LETTERBOXD_VERSION
        );

        // Core scripts
        wp_enqueue_script("jquery");
        wp_enqueue_script("jquery-ui-core");
        wp_enqueue_script("jquery-ui-datepicker");
        wp_enqueue_script("jquery-ui-tabs");
        wp_enqueue_media();

        // Settings script
        wp_enqueue_script(
            "letterboxd-settings",
            $plugin_url . "js/settings.js",
            [
                "jquery",
                "jquery-ui-core",
                "jquery-ui-datepicker",
                "jquery-ui-tabs",
                "wp-api-fetch",
                "wp-i18n",
                "wp-util",
            ],
            LETTERBOXD_VERSION,
            true
        );

        $auto_import_options = get_option("letterboxd_auto_import_options", [
            "frequency" => "daily",
            "notifications" => false,
        ]);

        // Prepare script data
        $token = get_transient("letterboxd_tmdb_auth_callback");

        // Main settings data
        $settings_data = [
            "ajaxUrl" => admin_url("admin-ajax.php"),
            "apiRoot" => esc_url_raw(rest_url()),
            "apiNonce" => wp_create_nonce("wp_rest"),
            "restNamespace" => self::REST_NAMESPACE,
            "nonce" => wp_create_nonce(self::AJAX_ACTION),
            "maxUploadSize" => wp_max_upload_size(),
            "dateFormat" => get_option("date_format"),
            "settings" => [
                "username" => $this->options["username"] ?? "",
                "start_date" => $this->options["start_date"] ?? "",
                "draft_status" => $this->options["draft_status"] ?? false,
                "tmdb_api_key" => $this->advanced_options["tmdb_api_key"] ?? "",
                "auto_import" => $auto_import_options,
            ],
        ];

        // Localize scripts
        wp_localize_script(
            "letterboxd-settings",
            "letterboxdSettings",
            $settings_data
        );

        if (!empty($token)) {
            wp_localize_script("letterboxd-settings", "letterboxdTmdbAuth", [
                "request_token" => $token,
            ]);
            delete_transient("letterboxd_tmdb_auth_callback");
        }
    }

    /**
     * Validate and sanitize input data
     *
     * @param array $input The input data to validate and sanitize
     * @return array The validated and sanitized data
     * @throws \Exception If validation fails
     */
    private function validateAndSanitize(array $input): array {
        $validated = [];
        $errors = [];

        // Username validation
        if (isset($input["username"])) {
            $username = sanitize_text_field($input["username"]);
            $validation = $this->api_service->validateUsername($username);

            if (!is_wp_error($validation)) {
                $validated["username"] = $username;
            } else {
                $errors[] = $validation->get_error_message();
            }
        }

        // Date validation
        if (isset($input["start_date"])) {
            $date = sanitize_text_field($input["start_date"]);
            if (empty($date) || $this->api_service->validateDate($date)) {
                $validated["start_date"] = $date;
            } else {
                $errors[] = __("Invalid date format.", "letterboxd-connect");
            }
        }

        // Draft status validation
        $validated["draft_status"] = !empty($input["draft_status"]);

        if (!empty($errors)) {
            throw new \Exception(esc_html(implode(" ", $errors)));
        }

        return $validated;
    }

    /**
     * Sanitize main options
     *
     * @param array $input Options to sanitize
     * @return array Sanitized options
     */
    public function sanitize_options(array $input): array {
        try {
            // Just sanitize and return the options
            return [
                "username" => isset($input["username"])
                    ? sanitize_text_field($input["username"])
                    : "",
                "start_date" => isset($input["start_date"])
                    ? sanitize_text_field($input["start_date"])
                    : "",
                "draft_status" => !empty($input["draft_status"]),
            ];
        } catch (\Exception $e) {
            add_settings_error(
                "letterboxd_messages",
                "validation_error",
                $e->getMessage()
            );
            return $this->options;
        }
    }

    /**
     * Sanitize advanced options
     *
     * @param array $input Advanced options to sanitize
     * @return array Sanitized advanced options
     */
    public function sanitize_advanced_options(array $input): array {
        $sanitized = [];

        if (isset($input["tmdb_api_key"])) {
            $sanitized["tmdb_api_key"] = sanitize_text_field(
                $input["tmdb_api_key"]
            );
        }

        // Preserve other advanced options
        $existing = get_option(self::ADVANCED_OPTION_NAME, []);
        if (isset($existing["tmdb_session_id"])) {
            $sanitized["tmdb_session_id"] = $existing["tmdb_session_id"];
        }

        return $sanitized;
    }

    /**
     * Render settings description
     */
    public function render_settings_description(): void {
        ?>
        <p>
            <?php esc_html_e(
                "Configure your Letterboxd import settings below. Make sure to save your settings before importing.",
                "letterboxd-connect"
            ); ?>
        </p>
        <?php
    }

    /**
     * Render username field
     */
    public function render_username_field($args): void {
        $field = $args['label_for'] ?? 'username';
        printf(
            '<input type="text" id="%1$s" name="%2$s[%1$s]" value="%3$s" class="regular-text" required>
            <p class="description">%4$s</p>',
            esc_attr($field),
            esc_attr(self::OPTION_NAME),
            esc_attr($this->options[$field] ?? ''),
            esc_html__("Your Letterboxd username", "letterboxd-connect")
        );
    }

    /**
     * Render start date field
     */
    public function render_start_date_field(): void {
        printf(
            '<input type="date" id="start_date" name="%s[start_date]" value="%s" class="regular-text" max="%s">
            <p class="description">%s</p>',
            esc_attr(self::OPTION_NAME),
            esc_attr($this->options["start_date"]),
            esc_attr(gmdate("Y-m-d")),
            esc_html__(
                "Optional: Only import movies watched after this date",
                "letterboxd-connect"
            )
        );
    }

    /**
     * Render draft status field
     */
    public function render_draft_status_field(): void {
        printf(
            '<input type="checkbox" name="%s[draft_status]" value="1" %s>
            <span class="description">%s</span>',
            esc_attr(self::OPTION_NAME),
            checked($this->options["draft_status"], true, false),
            esc_html__(
                "Save imported movies as drafts instead of publishing immediately",
                "letterboxd-connect"
            )
        );
    }

    /**
     * This method is now simplified and only used as a fallback
     * for any old code that might still call it
     */
    public function run_import_if_flagged(): void {
        $flag = get_option("letterboxd_run_import_flag", false);

        // This method should no longer be used directly - the import should be triggered
        // only from the update_settings method when the checkbox is checked
        if (!$flag) {
            return;
        }

        do_action("letterboxd_check_and_import");

        // Always clean up
        delete_option("letterboxd_run_import_flag");
    }

    /**
     * Adds a "Settings" link next to the Deactivate link on the plugins screen.
     *
     * @param array $links Existing plugin action links.
     * @return array Modified links including our Settings link.
     */
    public function add_plugin_action_links(array $links): array {
        $settings_link = sprintf(
            '<a href="%s">%s</a>',
            admin_url("options-general.php?page=" . self::MENU_SLUG),
            __("Settings", "letterboxd-connect")
        );
        // Prepend the link so it appears first
        array_unshift($links, $settings_link);
        return $links;
    }

    /**
     * Adds custom columns to the movie list.
     *
     * @param array $columns The existing columns.
     * @return array Modified columns including the new columns.
     */
    public function add_movie_columns(array $columns): array {
        $new_columns = [];
        foreach ($columns as $key => $value) {
            $new_columns[$key] = $value;
            if ($key === "title") {
                // Insert after the Title column
                $new_columns["rating"] = __("Rating", "letterboxd-connect");

                // Only add director column if TMDB is authenticated
                if ($this->is_tmdb_authenticated()) {
                    $new_columns["director"] = __(
                        "Director",
                        "letterboxd-connect"
                    );
                }
            }
        }
        return $new_columns;
    }

    /**
     * Displays values in custom columns.
     *
     * @param string $column  The name of the column to display.
     * @param int    $post_id The current post ID.
     */
    public function display_movie_columns(string $column, int $post_id): void {
        switch ($column) {
            case "rating":
                $rating = get_post_meta($post_id, "movie_rating", true);
                echo $rating
                    ? esc_html($rating)
                    : esc_html__("No Rating", "letterboxd-connect");
                break;

            case "director":
                $director = get_post_meta($post_id, "director", true);
                echo $director ? esc_html($director) : "—";
                break;
        }
    }

    /**
     * Validate a Letterboxd username
     *
     * @param string $username Username to validate
     * @return true|WP_Error True if valid, WP_Error if not
     */
    private function validate_letterboxd_username($username) {
        // Check if username is empty
        if (empty($username)) {
            return new WP_Error(
                "invalid_username",
                __("Username cannot be empty.", "letterboxd-connect")
            );
        }

        // Check username length
        if (
            strlen($username) < self::USERNAME_MIN_LENGTH ||
            strlen($username) > self::USERNAME_MAX_LENGTH
        ) {
            return new WP_Error(
                "invalid_username",
                sprintf(
                    /* translators: 1: Minimum username length, 2: Maximum username length */
                    __(
                        "Username must be between %1\$d and %2\$d characters.",
                        "letterboxd-connect"
                    ),
                    self::USERNAME_MIN_LENGTH,
                    self::USERNAME_MAX_LENGTH
                )
            );
        }

        // Check username format
        if (!preg_match(self::USERNAME_PATTERN, $username)) {
            return new WP_Error(
                "invalid_username",
                __(
                    "Username can only contain lowercase letters, numbers, and hyphens. It cannot start or end with a hyphen.",
                    "letterboxd-connect"
                )
            );
        }

        return true;
    }

    /**
     * Method to create TMDB request token
     *
     * @return WP_REST_Response
     */
    public function create_tmdb_request_token() {
        $api_key = $this->advanced_options['tmdb_api_key'] ?? '';

        if (empty($api_key)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => __('TMDB API key is missing.', 'letterboxd-connect'),
            ], 400);
        }

        $result = $this->api_service->createTmdbRequestToken($api_key);

        if (is_wp_error($result)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => $result->get_error_message(),
            ], 400);
        }

        $request_token = $result['request_token'];

        // Store the token temporarily for later use
        set_transient('letterboxd_tmdb_request_token', $request_token, 15 * MINUTE_IN_SECONDS);

        // ✅ Build the redirect URI to return to your plugin with the necessary flag
        $redirect_url = add_query_arg([
            'page' => 'letterboxd-connect',
            'tab' => 'advanced',
            'tmdb_auth' => 'callback',
        ], admin_url('options-general.php'));

        // ✅ Build full TMDB authorization URL
        $auth_url = "https://www.themoviedb.org/authenticate/{$request_token}?redirect_to=" . urlencode($redirect_url);

        return new WP_REST_Response([
            'success' => true,
            'request_token' => $request_token,
            'auth_url' => $auth_url,
        ]);
    }


    /**
     * Method to create TMDB session
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function create_tmdb_session(WP_REST_Request $request): WP_REST_Response {
        $request_token = $request->get_param('request_token');
        $api_key = $this->advanced_options['tmdb_api_key'] ?? '';

        if (empty($api_key)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => __('TMDB API key is missing.', 'letterboxd-connect'),
            ], 400);
        }

        $result = $this->api_service->createTmdbSession($api_key, $request_token);

        if (is_wp_error($result)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => $result->get_error_message(),
            ], 400);
        }

        // Optional: Save session ID to advanced options
        $this->advanced_options['tmdb_session_id'] = $result['session_id'];
        update_option(self::ADVANCED_OPTION_NAME, $this->advanced_options);

        // ✅ Stop the reload loop
        delete_transient('letterboxd_tmdb_auth_callback');

        return new WP_REST_Response([
            'success' => true,
            'session_id' => $result['session_id'],
        ]);
    }

}