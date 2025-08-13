<?php
/**
 * Class for handling automated imports from Letterboxd
 *
 * @package letterboxd-connect
 * @since 1.0.0
 */

// Prevent direct access
if (!defined("ABSPATH")) {
    exit();
}

class Letterboxd_Auto_Import {
    private function debug_log($message) {
        letterboxd_debug_log($message, "Auto_Import");
    }

    /**
     * Plugin settings and constants
     */
    private const OPTION_NAME = "letterboxd_auto_import_options";
    private const LOG_LIMIT = 50;
    private const HOOK_NAME = "letterboxd_check_and_import";
    private const CACHE_GROUP = "letterboxd_auto_import";
    private const CACHE_DURATION = 3600; // 1 hour
    private const IMPORT_LOCK_DURATION = 300; // 5 minutes

    /**
     * Available schedule intervals
     */
    private const SCHEDULES = [
        "disabled" => "Disabled",
        "hourly" => [
            "interval" => 3600,
            "display" => "Once Hourly"
        ],
        "twicedaily" => [
            "interval" => 43200,
            "display" => "Twice Daily"
        ],
        "daily" => [
            "interval" => 86400,
            "display" => "Once Daily"
        ],
        "weekly" => [
            "interval" => 604800,
            "display" => "Once Weekly"
        ]
    ];

    /**
     * Instance of the main plugin class
     *
     * @var Letterboxd_To_WordPress
     */
    private Letterboxd_To_WordPress $main_plugin;

    /**
     * Cached plugin options
     *
     * @var array
     */
    private array $cached_options;

    /**
     * Initialize the class with better option handling
     *
     * @param Letterboxd_To_WordPress $main_plugin Main plugin instance
     */
    public function __construct(Letterboxd_To_WordPress $main_plugin) {
        $this->main_plugin = $main_plugin;

        // Only register hooks ONCE
        static $hooks_registered = false;

        if (!$hooks_registered) {
            $this->setup_hooks();
            $hooks_registered = true;
        }

        // Only load options when needed based on current context
        $is_settings_page =
            is_admin() &&
            isset($_GET["page"]) &&
            $_GET["page"] === "letterboxd-connect";
        $is_cron_run = defined("DOING_CRON") && DOING_CRON;
        $is_rest_request = defined("REST_REQUEST") && REST_REQUEST;

        // Only load options when needed (settings page, cron, or REST API call)
        if ($is_settings_page || $is_cron_run || $is_rest_request) {
            $this->load_options();
        } else {
            // Otherwise just set defaults without DB query
            $this->cached_options = [
                "frequency" => "daily",
                "notifications" => false
            ];
        }
    }

    /**
     * Set up WordPress hooks and filters
     */
    private function setup_hooks(): void {
        // Add custom cron schedules - needed everywhere
        add_filter("cron_schedules", [$this, "add_custom_cron_intervals"]);

        // Import action - needed for functionality
        add_action(self::HOOK_NAME, [$this, "letterboxd_check_and_import"]);

        // Schedule transient cleanup - add this line
        $this->schedule_transient_cleanup();

        // Only add admin-specific hooks when in admin
        if (is_admin()) {
            // Check if we're on our settings page
            $is_settings_page =
                isset($_GET["page"]) &&
                $_GET["page"] === "letterboxd-connect";

            // Only register these hooks on our settings page
            if ($is_settings_page) {
                add_action("admin_init", [
                    $this,
                    "register_auto_import_settings"
                ]);
                add_action("admin_notices", [$this, "display_import_notices"]);
            }

            // This hook is needed for initialization
            add_action("letterboxd_auto_import_options", [
                $this,
                "initialize_schedule"
            ]);
        }
    }

    /**
     * Load and cache plugin options with defaults
     *
     * @param bool $force Force reload from database
     */
    private function load_options(bool $force = false): void {
        // Use static caching to prevent multiple DB queries in same request
        static $options_loaded = false;
        static $cached_options_static = null;

        if (!$force && $options_loaded && $cached_options_static !== null) {
            $this->cached_options = $cached_options_static;
            return;
        }

        $defaults = [
            "frequency" => "daily",
            "notifications" => false
        ];

        $saved_options = get_option("letterboxd_auto_import_options", []);
        //letterboxd_debug_log('Loading auto import options from DB: ' . print_r($saved_options, true));

        $this->cached_options = wp_parse_args($saved_options, $defaults);

        // Save to static cache
        $cached_options_static = $this->cached_options;
        $options_loaded = true;
    }

    /**
     * Add custom cron schedules
     *
     * @param array $schedules Existing cron schedules
     * @return array Modified cron schedules
     */
    public function add_custom_cron_intervals(array $schedules): array {
        foreach (self::SCHEDULES as $name => $config) {
            if ($name !== "disabled" && !isset($schedules[$name])) {
                $schedules[$name] = $config;
            }
        }
        return $schedules;
    }

    /**
     * Register auto-import settings
     */
    public function register_auto_import_settings(): void {
        if (!current_user_can("manage_options")) {
            return;
        }

        add_settings_section(
            "letterboxd_auto_import_section",
            __("Auto-Import Settings", "letterboxd-connect"),
            [$this, "render_settings_section"],
            "letterboxd-connect"
        );

        $this->register_settings_fields();
        
        function letterboxd_sanitize_options($input) {
            $sanitized = [];
            
            // Basic array structure validation
            if (!is_array($input)) {
                return [];
            }
            
            // Sanitize enabled setting (boolean)
            if (isset($input['enabled'])) {
                $sanitized['enabled'] = (bool) $input['enabled'];
            }
            
            // Sanitize frequency (string)
            if (isset($input['frequency'])) {
                $sanitized['frequency'] = sanitize_text_field($input['frequency']);
            }
            
            // Sanitize email notification (boolean)
            if (isset($input['email_notification'])) {
                $sanitized['email_notification'] = (bool) $input['email_notification'];
            }
            
            // Sanitize start date (string)
            if (isset($input['start_date'])) {
                $sanitized['start_date'] = sanitize_text_field($input['start_date']);
            }
            
            // Sanitize username (string)
            if (isset($input['username'])) {
                $sanitized['username'] = sanitize_text_field($input['username']);
            }
            
            // Sanitize TMDB API key (string)
            if (isset($input['tmdb_api_key'])) {
                $sanitized['tmdb_api_key'] = sanitize_text_field($input['tmdb_api_key']);
            }
            
            return $sanitized;
        }
        
        // Register settings
        add_filter('sanitize_option_letterboxd_auto_import_options', 'letterboxd_sanitize_options');
        
        // And modify your register_setting:
        register_setting(
            "letterboxd_wordpress_options",
            "letterboxd_auto_import_options",
            array(
                "type" => "object",
                "show_in_rest" => false,
                "default" => array()
            )
        );
    }

    /**
     * Register individual settings fields
     */
    private function register_settings_fields(): void {
        $fields = [
            "frequency" => [
                "title" => __("Check for New Movies", "letterboxd-connect"),
                "callback" => "render_frequency_field"
            ],
            "notifications" => [
                "title" => __("Email Notifications", "letterboxd-connect"),
                "callback" => "render_notifications_field"
            ],
            "status" => [
                "title" => __("Import Status", "letterboxd-connect"),
                "callback" => "render_status_field"
            ]
        ];

        foreach ($fields as $id => $field) {
            add_settings_field(
                "letterboxd_auto_import_{$id}",
                $field["title"],
                [$this, $field["callback"]],
                "letterboxd-connect",
                "letterboxd_auto_import_section"
            );
        }
    }

    /**
     * Render settings section description
     */
    public function render_settings_section(): void {
        echo "<p>" .
            esc_html__(
                "Configure how often the plugin should check for new movies in your Letterboxd feed.",
                "letterboxd-connect"
            ) .
            "</p>";
    }

    /**
     * Render frequency field
     */
    public function render_frequency_field(): void {
        $current = $this->cached_options["frequency"] ?? "daily"; // Add default

        echo '<select name="letterboxd_auto_import_options[frequency]">'; // Update name attribute
        foreach (self::SCHEDULES as $value => $config) {
            printf(
                '<option value="%s" %s>%s</option>',
                esc_attr($value),
                selected($current, $value, false),
                esc_html($config["display"] ?? $config)
            );
        }
        echo "</select>";
    }

    /**
     * Render notifications field
     */
    public function render_notifications_field(): void {
        printf(
            '<input type="checkbox" name="letterboxd_auto_import_options[notifications]" %s value="1">
            <span class="description">%s</span>',
            checked($this->cached_options["notifications"], true, false),
            esc_html__(
                "Send email notifications when new movies are imported",
                "letterboxd-connect"
            )
        );
    }

    /**
     * Render import status field
     */
    public function render_status_field(): void {
        // Check if the cron has run at least once
        $last_check = get_option("letterboxd_last_check");
        if (!$last_check) {
            echo "<p>" .
                esc_html__(
                    "The import will run for the first time after the settings are configured.",
                    "letterboxd-connect"
                ) .
                "</p>";
            return;
        }

        $current_time = time();

        // Define the statuses you want to display
        $statuses = [
            "Last checked" => "letterboxd_last_check",
            "Last import" => "letterboxd_last_import"
        ];

        echo '<div class="letterboxd-status-wrap">';

        // Loop over the statuses and render each if available
        foreach ($statuses as $label => $option) {
            $timestamp = get_option($option);
            if ($timestamp) {
                printf(
                    '<p class="time-wrap"><span class="status-label">%s:</span> <span class="status-time">%s</span></p>',
                    esc_html($label),
                    esc_html(
                        sprintf(
                            /* translators: %s: Human-readable time difference */
                            __('%s ago', 'letterboxd-connect'),
                            human_time_diff($timestamp, $current_time)
                        )
                    )
                );
            }
        }

        // Render the next check scheduled time, if available
        $next_check = wp_next_scheduled(self::HOOK_NAME);
        if ($next_check) {
            printf(
                '<p class="time-wrap"><span class="status-label">%s:</span> <span class="status-time">%s</span></p>',
                esc_html__("Next check scheduled", "letterboxd-connect"),
                esc_html(
                    human_time_diff($current_time, $next_check) .
                        " " .
                        __("from now", "letterboxd-connect")
                )
            );
        }

        echo "</div>";
    }

    /**
     * Initialize or reset schedule based on settings
     */
    public function initialize_schedule(): void {
        // Clear existing schedule
        wp_clear_scheduled_hook(self::HOOK_NAME);

        $frequency = $this->cached_options["frequency"];
        if ($frequency !== "disabled" && isset(self::SCHEDULES[$frequency])) {
            $last_check = get_option("letterboxd_last_check");
            $interval = self::SCHEDULES[$frequency]["interval"];

            // Calculate next check time based on last check plus interval
            $next_check = $last_check ? $last_check + $interval : time();

            wp_schedule_event($next_check, $frequency, self::HOOK_NAME);
        }

        $this->load_options();
    }

    /**
     * Update import schedule with improved error handling
     */
    public function update_import_schedule(string $interval): bool {
        //letterboxd_debug_log( "update_import_schedule called with interval: " . $interval );

        // Validate the interval
        $valid_intervals = array_keys(self::SCHEDULES);
        if (!in_array($interval, $valid_intervals)) {
            //letterboxd_debug_log("Invalid interval: " . $interval);
            return false;
        }

        // Clear the existing schedule
        $hook_name = self::HOOK_NAME;
        if (wp_next_scheduled($hook_name)) {
            //letterboxd_debug_log( "Clearing existing schedule for " . $hook_name );
            wp_clear_scheduled_hook($hook_name);
        }

        if (!empty($interval) && $interval !== "disabled") {
            //letterboxd_debug_log( "Setting up new schedule with interval: " . $interval );

            // Update the cached options first
            $this->cached_options["frequency"] = $interval;

            // Calculate a future time based on the interval
            $schedules = wp_get_schedules();
            $interval_seconds = $schedules[$interval]["interval"] ?? 86400; // Default to daily
            $timestamp = time() + $interval_seconds; // Schedule for the next interval

            // Schedule the event
            $schedule_result = wp_schedule_event(
                $timestamp,
                $interval,
                $hook_name
            );
            // letterboxd_debug_log(
            //     "wp_schedule_event result: " .
            //         ($schedule_result !== false ? "Success" : "Failed")
            // );

            // Only update options if scheduling was successful
            if ($schedule_result !== false) {
                // Update the auto import options
                update_option(
                    "letterboxd_auto_import_options",
                    $this->cached_options
                );

                // Update the import interval for compatibility
                update_option("letterboxd_import_interval", $interval);

                return true;
            }
        }

        return false;
    }

    /**
     * Check feed and import new movies
     */
    public function letterboxd_check_and_import(): void {
        // Check if this is being called right after settings update
        if (get_transient("letterboxd_settings_just_updated")) {
            //letterboxd_debug_log( "Skipping auto-import because settings were just updated" );
            delete_transient("letterboxd_settings_just_updated");
            return;
        }

        $lock_key = self::CACHE_GROUP . "_import_lock";

        // Check if another import is running
        if (get_transient($lock_key)) {
            //letterboxd_debug_log( "Skipping import - another process is running" );
            return;
        }

        // Set lock to prevent parallel imports
        set_transient($lock_key, true, self::IMPORT_LOCK_DURATION);

        try {
            //letterboxd_debug_log("Starting scheduled import");
            $this->update_last_check();
            $importer = $this->main_plugin->importer;
            $settings = get_option("letterboxd_wordpress_options", []);
            $result = $importer->import_movies($settings, true);

            if ($result && $result["imported"] > 0) {
                $this->handle_successful_import($result);
            }
            $this->log_import_result($result);
        } catch (Exception $e) {
            $this->handle_import_error($e);
        } finally {
            // Always clear the transient lock after processing
            delete_transient($lock_key);
            //letterboxd_debug_log("Import completed and lock cleared");
        }
    }

    /**
     * Check if import should run (prevents parallel imports)
     */
    private function should_run_import(): bool {
        $lock_key = self::CACHE_GROUP . "_import_lock";
        if (get_transient($lock_key)) {
            return false;
        }
        set_transient($lock_key, true, self::IMPORT_LOCK_DURATION);
        return true;
    }

    /**
     * Update last check timestamp
     */
    private function update_last_check(): void {
        $now = time();
        update_option("letterboxd_last_check", $now);
        wp_cache_set(
            "last_check",
            $now,
            self::CACHE_GROUP,
            self::CACHE_DURATION
        );
    }

    /**
     * Handle successful import
     */
    private function handle_successful_import(array $result): void {
        $now = time();
        update_option("letterboxd_last_import", $now);
        update_option("letterboxd_last_import_date", current_time("mysql"));

        wp_cache_set(
            "last_import",
            $now,
            self::CACHE_GROUP,
            self::CACHE_DURATION
        );

        if ($this->cached_options["notifications"]) {
            $this->send_notification($result);
        }
    }

    /**
     * Handle import error
     */
    private function handle_import_error(Exception $e): void {
        $result = [
            "imported" => 0,
            "status" => "error",
            "message" => $e->getMessage()
        ];

        $this->log_import_result($result);
        // letterboxd_debug_log(
        //     sprintf(
        //         "Letterboxd import error: %s in %s:%d",
        //         $e->getMessage(),
        //         $e->getFile(),
        //         $e->getLine()
        //     )
        // );

        // Clear the lock and clean up any stale transients
        delete_transient(self::CACHE_GROUP . "_import_lock");
        $this->cleanup_stale_transients();
    }

    /**
     * Send notification email
     */
    private function send_notification(array $result): void {
        $site_name = get_bloginfo("name");
        $admin_email = get_option("admin_email");
    
        // translators: %s: Website name
        $subject = sprintf(
            "[%s] %s",
            $site_name,
            // translators: This is the email subject for new movie imports
            __("New movies imported from Letterboxd", "letterboxd-connect")
        );
        
        // translators: %d is the number of imported movies
        $message = sprintf(
            // translators: %d is the number of imported movies
            __(
                "The Letterboxd importer has just imported %d new movie(s) to your website.",
                "letterboxd-connect"
            ),
            $result["imported"]
        );
    
        wp_mail($admin_email, $subject, $message);
    }

    /**
     * Log import results
     */
    private function log_import_result(array $result): void {
        $log = get_option("letterboxd_import_log", []);

        array_unshift($log, [
            "timestamp" => current_time("mysql"),
            "imported" => $result["imported"],
            "status" => $result["status"],
            "message" => $result["message"]
        ]);

        $log = array_slice($log, 0, self::LOG_LIMIT);
        update_option("letterboxd_import_log", $log);
        wp_cache_set(
            "import_log",
            $log,
            self::CACHE_GROUP,
            self::CACHE_DURATION
        );
    }

    /**
     * Display admin notices for import status
     */
    public function display_import_notices(): void {
        if (!$this->should_display_notices()) {
            return;
        }

        $log =
            wp_cache_get("import_log", self::CACHE_GROUP) ?:
            get_option("letterboxd_import_log", []);

        if (!empty($log) && $log[0]["status"] === "error") {
            printf(
                '<div class="notice notice-error is-dismissible"><p>%s: %s</p></div>',
                esc_html__(
                    "Last import attempt failed",
                    "letterboxd-connect"
                ),
                esc_html($log[0]["message"])
            );
        }
    }

    /**
     * Check if notices should be displayed
     */
    private function should_display_notices(): bool {
        if (!current_user_can("manage_options")) {
            return false;
        }

        $screen = get_current_screen();
        return $screen && $screen->id === "settings_page_letterboxd-connect";
    }

    /**
     * Sanitize options before saving
     */
    public function sanitize_options(array $options): array {
        $sanitized = [
            "frequency" => $this->sanitize_frequency(
                $options["frequency"] ?? "daily"
            ),
            "notifications" => !empty($options["notifications"])
        ];

        do_action("letterboxd_auto_import_options"); // Add this line
        return $sanitized;
    }

    /**
     * Sanitize frequency setting
     */
    private function sanitize_frequency(string $frequency): string {
        return isset(self::SCHEDULES[$frequency]) ? $frequency : "daily";
    }

    /**
     * Clean up stale transients related to the import process
     *
     * @param bool $force_cleanup Whether to force cleanup of all plugin transients
     * @return int Number of transients removed
     */
    private function cleanup_stale_transients(
        bool $force_cleanup = false
    ): int {
        global $wpdb;
        $count = 0;

        // Define patterns for transients to clean up
        $patterns = [
            // Lock transients should always be checked
            self::CACHE_GROUP . "_import_lock"
        ];

        // Add more patterns if we're doing a force cleanup
        if ($force_cleanup) {
            $patterns[] = self::CACHE_GROUP . "_%"; // All auto-import transients
        }

        foreach ($patterns as $pattern) {
            // Get transients matching the pattern
            $transients = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT option_name FROM $wpdb->options 
                WHERE option_name LIKE %s OR option_name LIKE %s",
                    "_transient_" . $pattern,
                    "_transient_timeout_" . $pattern
                )
            );

            // For each matching transient
            foreach ($transients as $transient) {
                // Extract the transient name without the _transient_ prefix
                $transient_name = str_replace(
                    ["_transient_", "_transient_timeout_"],
                    "",
                    $transient
                );

                // For lock transients, only delete if they're older than the import lock duration
                if (strpos($transient_name, "_import_lock") !== false) {
                    $transient_value = get_transient($transient_name);

                    // If the transient exists (not expired) and we're not force cleaning
                    if ($transient_value !== false && !$force_cleanup) {
                        $timeout = get_option(
                            "_transient_timeout_" . $transient_name
                        );

                        // Only delete if the transient is older than import lock duration + 60 seconds buffer
                        if (
                            !$timeout ||
                            time() > $timeout - self::IMPORT_LOCK_DURATION + 60
                        ) {
                            delete_transient($transient_name);
                            $count++;
                        }
                    } else {
                        // Transient is already expired or we're force cleaning
                        delete_transient($transient_name);
                        $count++;
                    }
                } elseif ($force_cleanup) {
                    // For other transients, only delete if we're doing a force cleanup
                    delete_transient($transient_name);
                    $count++;
                }
            }
        }

        // letterboxd_debug_log("Cleaned up {$count} stale transients");
        return $count;
    }

    /**
     * Schedule periodic cleanup of all plugin transients
     */
    private function schedule_transient_cleanup(): void {
        if (!wp_next_scheduled("letterboxd_cleanup_transients")) {
            wp_schedule_event(time(), "daily", "letterboxd_cleanup_transients");
        }

        add_action("letterboxd_cleanup_transients", [
            $this,
            "perform_scheduled_cleanup"
        ]);
    }

    /**
     * Execute scheduled cleanup of all plugin transients
     */
    public function perform_scheduled_cleanup(): void {
        $this->cleanup_stale_transients(true);
    }
}
