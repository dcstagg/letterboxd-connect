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

class Letterboxd_Settings_Manager
{
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
    private const PROGRESS_TTL_RUNNING  = DAY_IN_SECONDS;
    private const PROGRESS_TTL_COMPLETE = 30 * MINUTE_IN_SECONDS;

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
    public function __construct(LetterboxdApiServiceInterface $api_service)
    {
        $this->api_service = $api_service;
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
        LetterboxdApiServiceInterface $api_service,
    ) {
        if (null === self::$instance) {
            self::$instance = new self($api_service);
        }
        return self::$instance;
    }

    /**
     * Set up WordPress hooks
     */
    private function setup_hooks(): void
    {
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
        add_action("admin_post_letterboxd_csv_import", [
            $this,
            "handle_csv_import",
        ]);
        add_action("wp_ajax_letterboxd_tmdb_progress", [
            $this,
            "ajax_tmdb_progress",
        ]);

        // Add plugin action links
        add_filter(
            "plugin_action_links_" . plugin_basename(LETTERBOXD_PLUGIN_FILE),
            [$this, "add_plugin_action_links"],
        );

        // Add custom column hooks for the 'movie' post type
        add_filter("manage_movie_posts_columns", [$this, "add_movie_columns"]);
        add_action(
            "manage_movie_posts_custom_column",
            [$this, "display_movie_columns"],
            10,
            2,
        );

        add_action("admin_post_update_tmdb_data", [
            $this,
            "handle_tmdb_data_update",
        ]);
        add_action("admin_init", [$this, "handle_tmdb_auth_callback"]);
    }

    /**
     * Register a new REST route
     *
     * @param string $route Route to register
     * @param array $args Route arguments
     */
    private function register_route(string $route, array $args): void
    {
        register_rest_route(self::REST_NAMESPACE, $route, $args);
    }

    /**
     * Register REST API routes
     */
    public function register_rest_routes(): void
    {
        // Only register routes once
        if (self::$rest_routes_registered) {
            return;
        }

        self::$rest_routes_registered = true;

        // Only log if API service is missing - an actual issue
        if (!isset($this->api_service)) {
            // letterboxd_debug_log( "ERROR: API Service not available when registering REST routes", );
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
        WP_REST_Request $request,
    ): WP_REST_Response {
        $api_key = $request->get_param("api_key");
        $validation_result = $this->api_service->checkTmdbApiKey($api_key);

        if (is_wp_error($validation_result)) {
            return new WP_REST_Response(
                [
                    "success" => false,
                    "message" => $validation_result->get_error_message(),
                ],
                400,
            );
        }

        return new WP_REST_Response(
            [
                "success" => true,
                "message" => __("API key is valid.", "letterboxd-connect"),
            ],
            200,
        );
    }

    /**
     * Check if TMDB authentication is complete
     *
     * @return bool True if authenticated
     */
    private function is_tmdb_authenticated(): bool
    {
        return !empty($this->advanced_options["tmdb_api_key"]) &&
            !empty($this->advanced_options["tmdb_session_id"]);
    }

    /**
     * Handle the authorization callback from TMDB
     */
    public function handle_tmdb_auth_callback(): void
    {
        // Store flag for script enqueuing
        if (isset($_GET["tmdb_auth"]) && $_GET["tmdb_auth"] === "callback") {
            $request_token = get_transient("letterboxd_tmdb_request_token");
            if (!empty($request_token)) {
                // Store for later use in enqueue_admin_assets
                set_transient(
                    "letterboxd_tmdb_auth_callback",
                    $request_token,
                    HOUR_IN_SECONDS,
                );
            } else {
                add_settings_error(
                    "letterboxd_messages",
                    "tmdb_auth_error",
                    __(
                        "Authentication session expired. Please try again.",
                        "letterboxd-connect",
                    ),
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
    public function update_settings(WP_REST_Request $request): WP_REST_Response
    {
        if (!current_user_can("manage_options")) {
            return new WP_REST_Response(
                [
                    "success" => false,
                    "message" => __(
                        "Insufficient permissions",
                        "letterboxd-connect",
                    ),
                ],
                403,
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
                $posted_data["tmdb_api_key"],
            );
            update_option(self::ADVANCED_OPTION_NAME, $advanced_settings);
        }

        // Save auto-import settings
        if (isset($posted_data["letterboxd_auto_import_options"])) {
            $auto_import_settings = [
                "frequency" => isset(
                    $posted_data["letterboxd_auto_import_options"]["frequency"],
                )
                    ? sanitize_text_field(
                        $posted_data["letterboxd_auto_import_options"][
                            "frequency"
                        ],
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
                    true,
                );

                // Also update the import interval option for compatibility
                update_option(
                    "letterboxd_import_interval",
                    $auto_import_settings["frequency"],
                    true,
                );

                set_transient("letterboxd_settings_just_updated", true, 60); // 60 seconds

                // Only update the schedule if the settings were successfully saved
                if ($update_result) {
                    $auto_import = new Letterboxd_Auto_Import(
                        Letterboxd_To_WordPress::get_instance(),
                    );
                    $auto_import->update_import_schedule(
                        $auto_import_settings["frequency"],
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
            // letterboxd_debug_log( "Manual import explicitly requested - running now", );

            // Use a do_action with a priority to ensure it runs after all settings are saved
            add_action(
                "shutdown",
                function () {
                    // letterboxd_debug_log("Running import on shutdown hook");
                    do_action("letterboxd_check_and_import");
                },
                999,
            );
        }

        return new WP_REST_Response(
            [
                "success" => true,
                "message" => __(
                    "Settings saved successfully.",
                    "letterboxd-connect",
                ),
            ],
            200,
        );
    }

    /**
     * Validate Letterboxd username
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function validate_username(
        WP_REST_Request $request,
    ): WP_REST_Response {
        $username = $request->get_param("username");
        $validation_result = $this->validate_letterboxd_username($username);

        if (is_wp_error($validation_result)) {
            return new WP_REST_Response(
                [
                    "success" => false,
                    "message" => $validation_result->get_error_message(),
                ],
                400,
            );
        }

        return new WP_REST_Response(
            [
                "success" => true,
                "message" => __("Username is valid.", "letterboxd-connect"),
            ],
            200,
        );
    }

    /**
     * Get import status for REST API
     *
     * @return WP_REST_Response
     */
    public function get_import_status(): WP_REST_Response
    {
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
            200,
        );
    }

    /**
     * Load and cache plugin options
     */
    private function load_options(): void
    {
        $saved_options = get_option(self::OPTION_NAME, []);
        $this->options = wp_parse_args($saved_options, self::DEFAULT_OPTIONS);

        $saved_advanced_options = get_option(self::ADVANCED_OPTION_NAME, []);
        $this->advanced_options = wp_parse_args(
            $saved_advanced_options,
            self::DEFAULT_ADVANCED_OPTIONS,
        );
    }

    /**
     * Add settings page to WordPress admin
     */
    public function add_settings_page(): void
    {
        add_options_page(
            __("Letterboxd Connect Settings", "letterboxd-connect"),
            __("Letterboxd Connect", "letterboxd-connect"),
            "manage_options",
            self::MENU_SLUG,
            [$this, "render_settings_page"],
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
        string $page,
    ): void {
        foreach ($fields as $field => $data) {
            add_settings_field(
                $field,
                $data["label"],
                [$this, $data["callback"]],
                $page,
                $section,
            );
        }
    }

    /**
     * Register plugin settings
     */
    public function register_settings(): void
    {
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
            self::MENU_SLUG,
        );

        add_settings_section(
            "letterboxd_wordpress_advanced",
            __("Advanced Settings", "letterboxd-connect"),
            [$this, "render_advanced_settings_description"],
            self::MENU_SLUG . "_advanced",
        );

        // Register fields
        $this->register_settings_field(
            "letterboxd_wordpress_main",
            self::SETTINGS_FIELDS,
            self::MENU_SLUG,
        );
        $this->register_settings_field(
            "letterboxd_wordpress_advanced",
            self::ADVANCED_SETTINGS_FIELDS,
            self::MENU_SLUG . "_advanced",
        );
    }

    /**
     * Render advanced settings description
     */
    public function render_advanced_settings_description(): void
    {
        ?>
        <p>
            <?php esc_html_e(
                "Configure advanced settings for enhancing your Letterboxd imports with additional data sources.",
                "letterboxd-connect",
            ); ?>
        </p>
        <?php
    }

    public function ajax_tmdb_progress() {
        if ( ! current_user_can('manage_options') ) {
            wp_send_json_error(['message' => 'forbidden'], 403);
        }
    
        check_ajax_referer('letterboxd_tmdb_progress');
    
        // Never let caches get in the way of polling responses
        nocache_headers();
    
        $raw = get_transient('letterboxd_tmdb_update_results');
        if ( ! is_array($raw) ) {
            $raw = [];
        }
    
        $total = (int) ($raw['total_posts']     ?? 0);
        $done  = (int) ($raw['total_processed'] ?? 0);
    
        $percent = $total > 0 ? (int) round(($done / $total) * 100) : 0;
        if ($percent > 100) { $percent = 100; } // just in case

        $status = (string) ($raw['status'] ?? 'running');
        if ($status === 'done' || $status === 'finished' || $status === 'success') {
            $status = 'complete';
        }
    
        $payload = [
            'status'          => $status,
            'total_posts'     => $total,
            'total_processed' => $done,
            'percent'         => $percent,
        ];
        
        if (isset($raw['last_updated'])) {
            $payload['last_updated'] = (int) $raw['last_updated'];
        }
        if (isset($raw['started_at'])) {
            $payload['started_at'] = (int) $raw['started_at'];
        }
        if (isset($raw['run_id'])) {
            $payload['run_id'] = (string) $raw['run_id'];
        }
        
        if (isset($raw['summary'])) {
            $payload['summary'] = (string) $raw['summary']; // pass-through for the notice
        }
    
        wp_send_json_success($payload);
    }


    /**
     * Handle the CSV/ZIP upload from the CSV Import tab.
     * Persists a single notice via a plugin-scoped transient and redirects.
     */
    public function handle_csv_import(): void {
        // Simple guard against accidental double-invocation in the same request.
        static $running = false;
        if ($running) {
            return;
        }
        $running = true;

        if (!current_user_can("manage_options")) {
            wp_die(
                __(
                    "You do not have permission to do that.",
                    "letterboxd-connect",
                ),
            );
        }

        check_admin_referer(self::NONCE_ACTION, self::NONCE_NAME);

        $notice = null;

        if (empty($_FILES["letterboxd_csv_file"]["tmp_name"])) {
            // Build an error notice (don't call add_settings_error here).
            $notice = [
                [
                    "setting" => self::MENU_SLUG,
                    "code" => "csv_import_no_file",
                    "message" => __(
                        "No file was uploaded.",
                        "letterboxd-connect",
                    ),
                    "type" => "error",
                ],
            ];
        } else {
            $tmp_path = $_FILES["letterboxd_csv_file"]["tmp_name"];

            try {
                $post_type_handler = new Letterboxd_Movie_Post_Type();
                $importer = new Letterboxd_Importer($post_type_handler);
                $options = get_option(self::OPTION_NAME, []);

                $result = $importer->import_from_csv($tmp_path, $options);

                $imported = (int) ($result["imported"] ?? 0);
                $skipped_existing = (int) ($result["skipped_existing"] ?? 0);
                $skipped_duplicates =
                    (int) ($result["skipped_duplicates"] ?? 0);

                $msg_parts = [];
                $msg_parts[] = sprintf(
                    _n(
                        "Imported %d movie.",
                        "Imported %d movies.",
                        $imported,
                        "letterboxd-connect",
                    ),
                    $imported,
                );
                $msg_parts[] = sprintf(
                    _n(
                        "Skipped %d existing.",
                        "Skipped %d existing.",
                        $skipped_existing,
                        "letterboxd-connect",
                    ),
                    $skipped_existing,
                );
                $msg_parts[] = sprintf(
                    _n(
                        "Skipped %d duplicates in file.",
                        "Skipped %d duplicates in file.",
                        $skipped_duplicates,
                        "letterboxd-connect",
                    ),
                    $skipped_duplicates,
                );

                $notice = [
                    [
                        "setting" => self::MENU_SLUG,
                        "code" => "csv_import",
                        "message" => implode(" ", array_filter($msg_parts)),
                        "type" => "updated", // WP renders this as a green success notice
                    ],
                ];
            } catch (Exception $e) {
                $notice = [
                    [
                        "setting" => self::MENU_SLUG,
                        "code" => "csv_import_error",
                        "message" => esc_html($e->getMessage()),
                        "type" => "error",
                    ],
                ];
            }
        }

        // Persist exactly one copy of our notice for the next request.
        if ($notice) {
            set_transient("letterboxd_last_notice", $notice, 60);
        }

        // Avoid “headers already sent”
        while (ob_get_level()) {
            ob_end_clean();
        }

        // Redirect back to CSV tab (no settings-updated param to prevent dupes)
        wp_redirect(
            add_query_arg(
                [
                    "page" => self::MENU_SLUG,
                    "tab" => "csv_import",
                ],
                admin_url("options-general.php"),
            ),
        );
        exit();
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
                "letterboxd-connect",
            ),
            esc_url("https://developer.themoviedb.org/docs/getting-started"),
            esc_html__("Get one here", "letterboxd-connect"),
        );
    }
    
    private function build_admin_notice_url(string $message, string $type = 'updated'): string {
        return add_query_arg([
            'page'            => self::MENU_SLUG,
            'tab'             => 'advanced',
            'tmdb_notice'     => rawurlencode($message),
            'tmdb_notice_type'=> $type,
        ], admin_url('options-general.php'));
    }
    
    /**
     * Centralized completion: mark progress complete (consistent TTL), clear legacy counters, redirect.
     */
    private function finish_and_redirect(
        string $progress_key,
        string $proc_total_key,
        string $succ_total_key,
        string $fail_total_key,
        array  $progress,
        string $summary
    ): void {
        $progress['status']       = 'complete';
        $progress['last_updated'] = time();
        $progress['summary']      = $summary; // <— new
        
        set_transient($progress_key, $progress, self::PROGRESS_TTL_COMPLETE);
        
        // Clear legacy counters
        delete_transient($proc_total_key);
        delete_transient($succ_total_key);
        delete_transient($fail_total_key);
        
        // End the background request quietly (no redirect needed for AJAX UX)
        while (ob_get_level()) { ob_end_clean(); }
        status_header(204);
        exit();
    }
    
    /**
     * Render settings page content with tabs
     */
    public function render_settings_page(): void {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // Check for any leftover transients (just for logging)
        $raw_notice = get_transient('letterboxd_last_notice');
        if ($raw_notice) {
            delete_transient('letterboxd_last_notice'); // Clean it up
        }
        
        $active_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'general';
        include_once plugin_dir_path(__FILE__) . 'templates/settings-page.php';
    }

    /**
     * Render the TMDB update button with progress tracking
     */
    public function render_update_tmdb_button(): void {
        $url = wp_nonce_url(
            admin_url('admin-post.php?action=update_tmdb_data'),
            'update_tmdb_data'
        );
    
        echo '<div class="tmdb-update-section">';
        printf(
            '<a href="%s" class="button button-secondary tmdb-update-button">%s</a>',
            esc_url($url),
            esc_html__('Update TMDB Data for All Movies', 'letterboxd-connect')
        );
    
        echo '<p class="description">' .
            esc_html__(
                'Fetch and update TMDB data for all existing movies. This process runs in batches to prevent timeouts.',
                'letterboxd-connect'
            ) .
            '</p>';
    
        // Progress UI
        echo '<div id="tmdb-update-progress" class="tmdb-update-progress" style="display:none; margin-top: 10px;">';
        echo   '<div class="tmdb-progress-bar"><div class="tmdb-progress-inner"></div></div>';
        echo   '<p class="tmdb-progress-status"><span class="tmdb-progress-count">0</span>%</p>';
        echo '</div>';
    
        // Single status line we toggle (no extra DOM gets appended elsewhere)
        echo '<p id="tmdb-progress-message" class="description" style="display:none;">' .
             esc_html__( 'Update in progress, please wait…', 'letterboxd-connect' ) .
             '</p>';
    
        echo '</div>'; // .tmdb-update-section
    
        // Minimal inline styles
        echo '<style>
          .tmdb-progress-bar{height:20px;background:#fff;border-radius:4px;overflow:hidden;margin-bottom:10px}
          .tmdb-progress-inner{height:100%;background:#0073aa;width:0%;transition:width .3s ease}
          .tmdb-progress-status{font-size:14px;color:#555}
          .tmdb-update-button.processing{pointer-events:none;opacity:.7}
        </style>';
    
        // Attach the JS AFTER 'letterboxd-settings'
        ob_start(); ?>
        (function () {
          if (window.__lcTmdbWired) return; window.__lcTmdbWired = true;
    
          // Dismissible notice fallback
          document.addEventListener('click', function (e) {
            const btn = e.target.closest('.notice.is-dismissible .notice-dismiss');
            if (btn) btn.closest('.notice')?.remove();
          });
    
          const UPDATE_BASE    = <?php echo wp_json_encode( admin_url('admin-post.php') ); ?>;
          const UPDATE_NONCE   = <?php echo wp_json_encode( wp_create_nonce('update_tmdb_data') ); ?>;
          const UPDATE_URL     = UPDATE_BASE + '?action=update_tmdb_data&_wpnonce=' + encodeURIComponent(UPDATE_NONCE);
          const AJAX_URL       = <?php echo wp_json_encode( admin_url('admin-ajax.php') ); ?>;
          const PROGRESS_NONCE = <?php echo wp_json_encode( wp_create_nonce('letterboxd_tmdb_progress') ); ?>;
    
          window.ajaxurl = window.ajaxurl || AJAX_URL;
    
          function ready(fn){ if (document.readyState !== 'loading') fn(); else document.addEventListener('DOMContentLoaded', fn); }
    
          ready(function () {
            const section       = document.querySelector('.tmdb-update-section');
            const updateBtn     = section?.querySelector('.tmdb-update-button');
            const progressWrap  = section?.querySelector('#tmdb-update-progress');
            const barInner      = section?.querySelector('.tmdb-progress-inner');
            const countEl       = section?.querySelector('.tmdb-progress-count');
            const progressMsg   = section?.querySelector('#tmdb-progress-message');
            const initialBtnText= updateBtn ? updateBtn.textContent : '';
    
            function setProgress(pct){
              const c = Math.max(0, Math.min(100, pct|0));
              if (barInner) barInner.style.width = c + '%';
              if (countEl)  countEl.textContent  = c;
            }
    
            function injectNotice(type, text){
              const host = document.getElementById('letterboxd-settings-container') || document.querySelector('.wrap') || document.body;
              const notice = document.createElement('div');
              notice.className = 'notice notice-' + type + ' is-dismissible';
              notice.setAttribute('role', 'alert');
              notice.innerHTML =
                '<p>' + text + '</p>' +
                '<button type="button" class="notice-dismiss"><span class="screen-reader-text"><?php echo esc_js( __( 'Dismiss this notice.', 'letterboxd-connect' ) ); ?></span></button>';
              host.prepend(notice);
            }
    
            function pollProgress(intervalHandle, runStartedAt){
              const body = new URLSearchParams({ action:'letterboxd_tmdb_progress', _ajax_nonce: PROGRESS_NONCE });
    
              fetch(ajaxurl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body
              })
              .then(r => r.json())
              .then(payload => {
                if (!payload || !payload.success) return;
                const d = payload.data || {};
    
                const skew = 5;
                if (d.last_updated && runStartedAt && (d.last_updated + skew) < runStartedAt) return;
    
                const pct = ('percent' in d)
                  ? d.percent
                  : Math.round(((d.total_processed || 0) / Math.max(1, (d.total_posts || 0))) * 100);
    
                setProgress(pct);
    
                if (d.status === 'error') {
                  clearInterval(intervalHandle);
                  setProgress(0);
    
                  // restore button + remove spinner
                  if (updateBtn){
                    updateBtn.textContent = initialBtnText || <?php echo wp_json_encode( __('Update TMDB Data for All Movies','letterboxd-connect') ); ?>;
                    updateBtn.classList.remove('processing');
                    updateBtn.removeAttribute('aria-busy');
                    updateBtn.disabled = false;
                    const sp = updateBtn.querySelector('.spinner'); if (sp) sp.remove();
                  }
    
                  if (progressWrap) progressWrap.style.display = 'none';
                  if (progressMsg)  progressMsg.style.display  = 'none';
    
                  injectNotice('error', d.summary || <?php echo wp_json_encode( __('TMDB update failed.','letterboxd-connect') ); ?>);
                  return;
                }
    
                if (d.status === 'done' || d.status === 'complete' || pct >= 100) {
                  clearInterval(intervalHandle);
                  setProgress(100);
    
                  // restore button + remove spinner
                  if (updateBtn){
                    updateBtn.textContent = initialBtnText || <?php echo wp_json_encode( __('Update TMDB Data for All Movies','letterboxd-connect') ); ?>;
                    updateBtn.classList.remove('processing');
                    updateBtn.removeAttribute('aria-busy');
                    updateBtn.disabled = false;
                    const sp = updateBtn.querySelector('.spinner'); if (sp) sp.remove();
                    updateBtn.focus();
                  }
    
                  setTimeout(() => { if (progressWrap) progressWrap.style.display = 'none'; }, 200);
                  if (progressMsg) progressMsg.style.display = 'none';
    
                  injectNotice('success', d.summary || <?php echo wp_json_encode( __('TMDB update completed!','letterboxd-connect') ); ?>);
                  return;
                }
              })
              .catch(() => {});
            }
    
            if (updateBtn && progressWrap){
              updateBtn.addEventListener('click', function(e){
                if (!confirm(<?php echo wp_json_encode( __('This process may take several minutes depending on your number of movies. Continue?', 'letterboxd-connect') ); ?>)) return;
                e.preventDefault();
    
                // remove any legacy message that might still be in the DOM from old JS
                document.querySelectorAll('.tmdb-update-section .update-status').forEach(n => n.remove());
    
                // show UI + status
                progressWrap.style.display = 'block';
                if (progressMsg) progressMsg.style.display = 'block';
    
                // button busy state + spinner
                updateBtn.classList.add('processing');
                updateBtn.setAttribute('aria-busy', 'true');
                updateBtn.disabled = true;
                updateBtn.textContent = <?php echo wp_json_encode( __('Processing...','letterboxd-connect') ); ?>;
    
                let spinner = updateBtn.querySelector('.spinner');
                if (!spinner) {
                  spinner = document.createElement('span');
                  spinner.className = 'spinner is-active';
                  updateBtn.prepend(spinner);
                }
    
                setProgress(0);
                const runStartedAt = Math.floor(Date.now()/1000);
    
                fetch(UPDATE_URL, { credentials: 'same-origin' }).catch(() => {});
                let handle;
                setTimeout(() => {
                  handle = setInterval(() => pollProgress(handle, runStartedAt), 1200);
                  pollProgress(handle, runStartedAt);
                }, 1200);
              });
            }
          });
        })();
        <?php
        $inline = ob_get_clean();
        wp_add_inline_script('letterboxd-settings', $inline, 'after');
    }

    /**
     * Handle TMDB data update request (stable total + offset paging, no cache flush)
     */
    public function handle_tmdb_data_update(): void {
        
        $nonce = '';
        if (isset($_GET['_wpnonce'])) {
            $nonce = (string) $_GET['_wpnonce'];
        } elseif (isset($_GET['amp;_wpnonce'])) {
            $nonce = (string) $_GET['amp;_wpnonce'];
            // if (method_exists($this, 'letterboxd_debug_log')) {
            //  $this->letterboxd_debug_log("[SECURITY] Received 'amp;_wpnonce' param; proceeding but URL encoding is wrong upstream.");
            // }
        } elseif (!empty($_SERVER['QUERY_STRING'])) {
            if (preg_match('/(?:^|&)(?:amp;)?_wpnonce=([^&]+)/', (string) $_SERVER['QUERY_STRING'], $m)) {
                $nonce = $m[1];
            }
        }
    
        if (empty($nonce) || !wp_verify_nonce($nonce, 'update_tmdb_data')) {
            wp_die(esc_html__('Security check failed.', 'letterboxd-connect'));
        }
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to perform this action.', 'letterboxd-connect'));
        }
        nocache_headers();
    
        $batch_size = isset($_GET['batch_size']) ? (int) $_GET['batch_size'] : 20;
        $batch_size = max(5, min(50, $batch_size));
        $batch      = isset($_GET['batch']) ? max(1, (int) $_GET['batch']) : 1;
        $force_new  = isset($_GET['force']) && (string) $_GET['force'] === '1';
    
        $post_type     = apply_filters('letterboxd_tmdb_post_type', 'movie');
        $post_statuses = apply_filters('letterboxd_tmdb_post_statuses', ['publish']);
    
        // $this->letterboxd_debug_log("[BATCH] Starting batch={$batch} size={$batch_size} post_type={$post_type} statuses=" . implode(',', (array) $post_statuses));
    
        $progress_key  = 'letterboxd_tmdb_update_results';
        $proc_total_key= 'letterboxd_tmdb_update_processed';
        $succ_total_key= 'letterboxd_tmdb_update_success';
        $fail_total_key= 'letterboxd_tmdb_update_failed';
    
        // --- TMDB handler + region (check BEFORE touching transients) ---
        $tmdb_handler = new Letterboxd_TMDB_Handler();
        if (!$tmdb_handler->is_api_key_configured()) {
            $error_message = __('TMDB API key is not configured.', 'letterboxd-connect');
        
            // Write an error state the poller can surface
            set_transient('letterboxd_tmdb_update_results', [
                'status'          => 'error',
                'summary'         => $error_message,
                'total_posts'     => 0,
                'total_processed' => 0,
                'last_updated'    => time(),
            ], self::PROGRESS_TTL_COMPLETE);
        
            while (ob_get_level()) { ob_end_clean(); }
            status_header(400);
            echo esc_html($error_message);
            exit();
        }
        $options = get_option('letterboxd_wordpress_advanced_options', []);
        $region  = $options['streaming_region'] ?? 'US';

        $progress = get_transient($progress_key);
        $is_new_run = false;
        if ($batch === 1) {
            $is_new_run = $force_new || !is_array($progress) || (($progress['status'] ?? '') === 'complete');
        }

        if (!is_array($progress) || $is_new_run) {
            // Fresh run: count posts once
            $all_ids = get_posts([
                'post_type'              => $post_type,
                'post_status'            => $post_statuses,
                'fields'                 => 'ids',
                'posts_per_page'         => -1,
                'orderby'                => 'date',
                'order'                  => 'DESC',
                'no_found_rows'          => true,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
                'suppress_filters'       => true,
            ]);
            $total_posts = is_array($all_ids) ? count($all_ids) : 0;
    
            // Reset legacy counters only when starting a genuine new run
            delete_transient($proc_total_key);
            delete_transient($succ_total_key);
            delete_transient($fail_total_key);
    
            $progress = [
                'run_id'          => function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : uniqid('tmdb_', true),
                'status'          => 'running',
                'updated'         => 0,
                'failed'          => 0,
                'total_processed' => 0,
                'total_posts'     => $total_posts,
                'batch'           => 1,
                'batch_size'      => $batch_size,
                'started_at'      => time(),
                'last_updated'    => time(),
            ];
            set_transient($progress_key, $progress, self::PROGRESS_TTL_RUNNING);
            // $this->letterboxd_debug_log("[PROGRESS] Initialized progress for batch 1; total_posts={$total_posts}");
        } else {
            $total_posts = (int) ($progress['total_posts'] ?? 0);
        }
    
        // Legacy counters (optional elsewhere)
        $processed_total = (int) (get_transient($proc_total_key) ?: 0);
        $updated_total   = (int) (get_transient($succ_total_key) ?: 0);
        $failed_total    = (int) (get_transient($fail_total_key) ?: 0);
    
        $offset = ($batch - 1) * $batch_size;
    
        // --- Finish early if past end or no posts ---
        if ($total_posts === 0 || $offset >= $total_posts) {
            $final      = is_array($progress) ? $progress : [];
            $processed  = (int) ($final['total_processed'] ?? $processed_total);
            $updated    = (int) ($final['updated']         ?? $updated_total);
            $failed     = (int) ($final['failed']          ?? $failed_total);
            $total      = (int) ($final['total_posts']     ?? $total_posts);
            $started_at = (int) ($final['started_at']      ?? time());
            $finished   = time();
            $duration   = max(0, $finished - $started_at);
    
            $h = floor($duration / 3600);
            $m = floor(($duration % 3600) / 60);
            $s = $duration % 60;
            $dur_str = $h ? sprintf('%dh %02dm %02ds', $h, $m, $s) : ($m ? sprintf('%dm %02ds', $m, $s) : sprintf('%ds', $s));
            $pct = $total > 0 ? (int) round(($processed / $total) * 100) : 0;
    
            $summary = sprintf(__('TMDB update completed in %1$s — %2$d%% (%3$d/%4$d). Updated: %5$d • Failed: %6$d', 'letterboxd-connect'),
                $dur_str, $pct, $processed, $total, $updated, $failed
            );
    
            $final['last_updated'] = $finished;
            $this->finish_and_redirect($progress_key, $proc_total_key, $succ_total_key, $fail_total_key, $final, $summary);
        }
    
        // --- Query current slice ---
        $query = new WP_Query([
            'post_type'              => $post_type,
            'post_status'            => $post_statuses,
            'posts_per_page'         => $batch_size,
            'offset'                 => $offset,
            'orderby'                => 'date',
            'order'                  => 'DESC',
            'fields'                 => 'ids',
            'no_found_rows'          => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
            'suppress_filters'       => true,
            'ignore_sticky_posts'    => true,
        ]);
        $post_ids = $query->posts ?: [];
        // $this->letterboxd_debug_log("[BATCH] Slice offset={$offset} size={$batch_size} -> got " . count($post_ids) . " IDs");
    
        // Empty slice but not past end? Skip forward.
        if (empty($post_ids)) {
            // $this->letterboxd_debug_log("[BATCH] Empty slice but not past end; jumping to next batch.");
            $next_url = add_query_arg([
                'action'     => 'update_tmdb_data',
                '_wpnonce'   => wp_create_nonce('update_tmdb_data'),
                'batch'      => $batch + 1,
                'batch_size' => $batch_size,
                'run_id'     => $progress['run_id'] ?? '',
            ], admin_url('admin-post.php'));
            while (ob_get_level()) { ob_end_clean(); }
            wp_safe_redirect($next_url);
            exit();
        }
    
        // --- Work ---
        // letterboxd_debug_log(sprintf('Starting TMDB data update batch %d with %d movies', $batch, count($post_ids)));
    
        // $this->letterboxd_debug_log('[BATCH] Calling batch_update_movie_metadata...');
        $metaRes = $tmdb_handler->batch_update_movie_metadata($post_ids, $region);
        // $this->letterboxd_debug_log('[BATCH] metaRes: ' . wp_json_encode($metaRes));
    
        // $this->letterboxd_debug_log('[BATCH] Calling batch_update_streaming_providers...');
        $provRes = $tmdb_handler->batch_update_streaming_providers($post_ids, $region);
        // $this->letterboxd_debug_log('[BATCH] provRes: ' . wp_json_encode($provRes));
    
        // Verify first ID
        $verify_id         = $post_ids[0];
        $verify_tmdb_id    = get_post_meta($verify_id, 'tmdb_id', true);
        $verify_tmdb_title = get_post_meta($verify_id, 'tmdb_title', true);
        // $this->letterboxd_debug_log(sprintf('[VERIFY] Post %d read-back: tmdb_id=%s, tmdb_title=%s',
        //     $verify_id,
        //     $verify_tmdb_id !== '' ? $verify_tmdb_id : '(empty)',
        //     $verify_tmdb_title !== '' ? $verify_tmdb_title : '(empty)'
        // ));
    
        // Aggregate results
        $updated_this = (int) ($metaRes['updated'] ?? 0) + (int) ($provRes['updated'] ?? 0);
        $failed_this  = (int) ($metaRes['failed']  ?? 0) + (int) ($provRes['failed']  ?? 0);
    
        // Legacy counters
        $processed_total += count($post_ids);
        $updated_total   += $updated_this;
        $failed_total    += $failed_this;
        set_transient($proc_total_key, $processed_total, self::PROGRESS_TTL_RUNNING);
        set_transient($succ_total_key, $updated_total,   self::PROGRESS_TTL_RUNNING);
        set_transient($fail_total_key, $failed_total,    self::PROGRESS_TTL_RUNNING);
    
        // Update authoritative progress
        $progress                 = get_transient($progress_key) ?: $progress; // keep existing fields
        $progress['status']       = 'running';
        $progress['updated']      = (int) ($progress['updated'] ?? 0) + $updated_this;
        $progress['failed']       = (int) ($progress['failed']  ?? 0) + $failed_this;
        $progress['total_processed'] = min($offset + count($post_ids), (int) $progress['total_posts']);
        $progress['batch']        = $batch;
        $progress['batch_size']   = $batch_size;
        $progress['last_updated'] = time();
        set_transient($progress_key, $progress, self::PROGRESS_TTL_RUNNING);
        // $this->letterboxd_debug_log('[PROGRESS] Updated progress: ' . wp_json_encode($progress));
    
        // More to do?
        $processed_so_far = $offset + count($post_ids);
        $has_more = $processed_so_far < (int) $progress['total_posts'];
    
        if ($has_more) {
            $next_url = add_query_arg([
                'action'     => 'update_tmdb_data',
                '_wpnonce'   => wp_create_nonce('update_tmdb_data'),
                'batch'      => $batch + 1,
                'batch_size' => $batch_size,
                'run_id'     => $progress['run_id'] ?? '',
            ], admin_url('admin-post.php'));
            // $this->letterboxd_debug_log("[BATCH] Redirecting to next batch: {$next_url}");
            while (ob_get_level()) { ob_end_clean(); }
            wp_safe_redirect($next_url);
            exit();
        }
    
        // --- Finished (single path) ---
        $finished   = time();
        $processed  = (int) $progress['total_processed'];
        $updated    = (int) $progress['updated'];
        $failed     = (int) $progress['failed'];
        $total      = (int) $progress['total_posts'];
        $started_at = (int) ($progress['started_at'] ?? $finished);
        $duration   = max(0, $finished - $started_at);
    
        $h = floor($duration / 3600);
        $m = floor(($duration % 3600) / 60);
        $s = $duration % 60;
        $dur_str = $h ? sprintf('%dh %02dm %02ds', $h, $m, $s) : ($m ? sprintf('%dm %02ds', $m, $s) : sprintf('%ds', $s));
        $pct = $total > 0 ? (int) round(($processed / $total) * 100) : 0;
    
        $summary = sprintf(
            __('TMDB update completed in %1$s — %2$d%% (%3$d/%4$d). Updated: %5$d • Failed: %6$d', 'letterboxd-connect'),
            $dur_str, $pct, $processed, $total, $updated, $failed
        );
    
        $this->finish_and_redirect($progress_key, $proc_total_key, $succ_total_key, $fail_total_key, $progress, $summary);

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
        
        wp_enqueue_style(
            'letterboxd-admin',
            $plugin_url . 'css/admin.css',
            [],
            LETTERBOXD_VERSION,
            'all'
        );
        
        // Let dependencies pull in the core handles automatically
        wp_enqueue_script(
          'letterboxd-settings',
          $plugin_url . 'js/settings.js',
          [
            'jquery',
            'jquery-ui-core',
            'jquery-ui-datepicker',
            'common',
            'wp-api-fetch',
            'wp-i18n',
            'wp-util',
          ],
          LETTERBOXD_VERSION,
          true
        );

        
        wp_enqueue_media();

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
            $settings_data,
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
    private function validateAndSanitize(array $input): array
    {
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
    public function sanitize_options(array $input): array
    {
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
                $e->getMessage(),
            );
            return $this->options;
        }
    }

    /**
     * Sanitize advanced options (gracefully handles null input).
     *
     * @param array|null $input Advanced options to sanitize, or null if none posted.
     * @return array Sanitized advanced options.
     */
    public function sanitize_advanced_options($input): array
    {
        // If nothing was posted for advanced options, start from existing defaults
        $existing = get_option(
            self::ADVANCED_OPTION_NAME,
            self::DEFAULT_ADVANCED_OPTIONS,
        );
        $input = is_array($input) ? $input : [];

        // Sanitize only the fields we know about
        $sanitized = [
            "tmdb_api_key" => isset($input["tmdb_api_key"])
                ? sanitize_text_field($input["tmdb_api_key"])
                : $existing["tmdb_api_key"] ?? "",
            "tmdb_session_id" => $existing["tmdb_session_id"] ?? "",
        ];

        return $sanitized;
    }

    /**
     * Render settings description
     */
    public function render_settings_description(): void
    {
        ?>
        <p>
            <?php esc_html_e(
                "Configure your Letterboxd import settings below. Make sure to save your settings before importing.",
                "letterboxd-connect",
            ); ?>
        </p>
        <?php
    }

    /**
     * Render username field
     */
    public function render_username_field(): void
    {
        printf(
            '<input type="text" name="%s[username]" value="%s" class="regular-text" required>
            <p class="description">%s</p>',
            esc_attr(self::OPTION_NAME),
            esc_attr($this->options["username"]),
            esc_html__("Your Letterboxd username", "letterboxd-connect"),
        );
    }

    /**
     * Render start date field
     */
    public function render_start_date_field(): void
    {
        printf(
            '<input type="date" id="start_date" name="%s[start_date]" value="%s" class="regular-text" max="%s">
            <p class="description">%s</p>',
            esc_attr(self::OPTION_NAME),
            esc_attr($this->options["start_date"]),
            esc_attr(gmdate("Y-m-d")),
            esc_html__(
                "Optional: Only import movies watched after this date",
                "letterboxd-connect",
            ),
        );
    }

    /**
     * Render draft status field
     */
    public function render_draft_status_field(): void
    {
        printf(
            '<input type="checkbox" name="%s[draft_status]" value="1" %s>
            <span class="description">%s</span>',
            esc_attr(self::OPTION_NAME),
            checked($this->options["draft_status"], true, false),
            esc_html__(
                "Save imported movies as drafts instead of publishing immediately",
                "letterboxd-connect",
            ),
        );
    }

    /**
     * This method is now simplified and only used as a fallback
     * for any old code that might still call it
     */
    public function run_import_if_flagged(): void
    {
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
    public function add_plugin_action_links(array $links): array
    {
        $settings_link = sprintf(
            '<a href="%s">%s</a>',
            admin_url("options-general.php?page=" . self::MENU_SLUG),
            __("Settings", "letterboxd-connect"),
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
    public function add_movie_columns(array $columns): array
    {
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
                        "letterboxd-connect",
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
    public function display_movie_columns(string $column, int $post_id): void
    {
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
    private function validate_letterboxd_username($username)
    {
        // Check if username is empty
        if (empty($username)) {
            return new WP_Error(
                "invalid_username",
                __("Username cannot be empty.", "letterboxd-connect"),
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
                        "letterboxd-connect",
                    ),
                    self::USERNAME_MIN_LENGTH,
                    self::USERNAME_MAX_LENGTH,
                ),
            );
        }

        // Check username format
        if (!preg_match(self::USERNAME_PATTERN, $username)) {
            return new WP_Error(
                "invalid_username",
                __(
                    "Username can only contain lowercase letters, numbers, and hyphens. It cannot start or end with a hyphen.",
                    "letterboxd-connect",
                ),
            );
        }

        return true;
    }

    /**
     * Method to create TMDB request token (stub implementation)
     *
     * @return WP_REST_Response
     */
    public function create_tmdb_request_token()
    {
        return new WP_REST_Response([
            "success" => true,
            "request_token" => "sample_token",
        ]);
    }

    /**
     * Method to create TMDB session (stub implementation)
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function create_tmdb_session(WP_REST_Request $request)
    {
        return new WP_REST_Response([
            "success" => true,
            "session_id" => "sample_session_id",
        ]);
    }
}