<?php
/**
 * Plugin Name: Letterboxd Connect
 * Plugin URI: https://letterboxdconnect.com
 * Description: Connect your Letterboxd film diary to WordPress by automatically importing and displaying your watched movies, enhanced by TMDB metadata in customizable grid layouts
 * Version: 1.0
 * Author: David Stagg
 * Author URI: https://davidstagg.com
 * License: GPL v3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 */

// Prevent direct access
if (!defined("ABSPATH")) {
    if (headers_sent()) {
        die("Direct access not permitted.");
    } else {
        header("HTTP/1.0 403 Forbidden");
        die("Direct access not permitted.");
    }
}

/**
 * Conditional debug logging
 *
 * @param string $message The message to log
 * @param string $component Optional component name
 */
function letterboxd_debug_log($message, $component = "") {
    if (defined("WP_DEBUG") && WP_DEBUG) {
        $prefix = empty($component)
            ? "Letterboxd: "
            : "Letterboxd {$component}: ";
        error_log($prefix . $message);
    }
}

/**
 * Check if WordPress version is compatible with plugin requirements
 *
 * @return bool Whether WordPress is compatible
 */
function letterboxd_check_wp_compatibility() {
    global $wp_version;
    $is_compatible = version_compare($wp_version, "5.6", ">=");

    if (!$is_compatible) {
        add_action("admin_notices", function () {
            echo '<div class="error"><p>';
            esc_html_e(
                "Letterboxd Connect requires WordPress version 5.6 or higher.",
                "letterboxd-connect"
            );
            echo "</p></div>";
        });
    }

    return $is_compatible;
}

// Check compatibility before loading the rest of the plugin
if (!letterboxd_check_wp_compatibility()) {
    return;
}

// Define plugin constants
if (!defined("LETTERBOXD_PLUGIN_FILE")) {
    define("LETTERBOXD_PLUGIN_FILE", __FILE__);
}
define("LETTERBOXD_PLUGIN_DIR", plugin_dir_path(__FILE__));
define("LETTERBOXD_PLUGIN_URL", plugin_dir_url(__FILE__));
define("LETTERBOXD_VERSION", "1.0.0");

// Load traits
require_once LETTERBOXD_PLUGIN_DIR .
    "includes/traits/trait-letterboxd-validation.php";
require_once LETTERBOXD_PLUGIN_DIR .
    "includes/traits/trait-letterboxd-error-handling.php";
require_once LETTERBOXD_PLUGIN_DIR .
    "includes/traits/trait-letterboxd-security.php";

// Load interfaces and services first
require_once LETTERBOXD_PLUGIN_DIR .
    "includes/interfaces/interface-letterboxd-api-service.php";
require_once LETTERBOXD_PLUGIN_DIR .
    "includes/services/class-letterboxd-api-service.php";

// Autoload classes
spl_autoload_register(function ($class_name) {
    // Only process classes with the Letterboxd prefix
    if (strpos($class_name, "Letterboxd_") !== 0) {
        return;
    }

    $class_files = [
        "Letterboxd_Movie_Post_Type" => "includes/class-movie-post-type.php",
        "Letterboxd_Importer" => "includes/class-letterboxd-importer.php",
        "Letterboxd_Movie_Block_Renderer" =>
            "includes/class-movie-block-renderer.php",
        "Letterboxd_Settings_Manager" => "includes/class-settings-manager.php",
        "Letterboxd_Auto_Import" => "includes/class-auto-import.php",
        "Letterboxd_TMDB_Handler" => "includes/class-tmdb-handler.php"
    ];

    if (isset($class_files[$class_name])) {
        require_once LETTERBOXD_PLUGIN_DIR . $class_files[$class_name];
    }
});

/**
 * Main plugin class
 */
class Letterboxd_To_WordPress {
    /**
     * Plugin instance (Singleton pattern)
     *
     * @var Letterboxd_To_WordPress
     */
    private static $instance = null;

    /**
     * Get plugin instance (Singleton pattern)
     *
     * @return Letterboxd_To_WordPress
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * @var Letterboxd_Movie_Post_Type
     */
    private $post_type = null;

    /**
     * @var Letterboxd_Importer
     */
    public $importer = null;

    /**
     * @var Letterboxd_Movie_Block_Renderer
     */
    private $block_renderer = null;

    /**
     * @var Letterboxd_Settings_Manager
     */
    private $settings = null;

    /**
     * @var Letterboxd_Auto_Import
     */
    private $auto_import = null;

    /**
     * @var LetterboxdApiServiceInterface
     */
    private $api_service = null;

    /**
     * Initialize the plugin
     */
    public function __construct() {
        // Initialize API service first
        $this->api_service = new LetterboxdApiService();

        // Initialize components with dependencies
        $this->post_type = new Letterboxd_Movie_Post_Type();
        $this->importer = new Letterboxd_Importer($this->post_type);
        $this->block_renderer = new Letterboxd_Movie_Block_Renderer();
        $this->settings = new Letterboxd_Settings_Manager($this->api_service);
        $this->auto_import = new Letterboxd_Auto_Import($this);

        // Register all hooks
        $this->register_hooks();
    }

    /**
     * Register all hooks for plugin components
     */
    private function register_hooks(): void {
        // Register activation/deactivation hooks
        register_activation_hook(__FILE__, [$this, "activate"]);
        register_deactivation_hook(__FILE__, [$this, "deactivate"]);

        // Register admin notices
        add_action("admin_notices", [$this, "display_username_notice"]);
    }

    // Add this new method
    public function ensure_block_renderer_initialized() {
        // initialize the block renderer if it's not already initialized
        $this->get_block_renderer();
    }

    // Prevent cloning
    public function __clone() {}

    // Prevent wakeup
    public function __wakeup() {}

    /**
     * Set up public property access to maintain compatibility
     */
    public function setup_property_access() {
        // For public property access (keeps backward compatibility)
        // Make the importer property work as expected for external code
        if ($this->importer === null) {
            $this->importer = $this->get_importer();
        }
    }

    /**
     * Get API service with lazy loading
     *
     * @return LetterboxdApiServiceInterface
     */
    private function get_api_service() {
        if (null === $this->api_service) {
            $this->api_service = new LetterboxdApiService();
        }
        return $this->api_service;
    }

    /**
     * Get post type handler with lazy loading
     *
     * @return Letterboxd_Movie_Post_Type
     */
    private function get_post_type() {
        if (null === $this->post_type) {
            $this->post_type = new Letterboxd_Movie_Post_Type();
        }
        return $this->post_type;
    }

    /**
     * Get importer with lazy loading
     *
     * @return Letterboxd_Importer
     */
    private function get_importer() {
        if (null === $this->importer) {
            $this->importer = new Letterboxd_Importer($this->get_post_type());
        }
        return $this->importer;
    }

    /**
     * Get block renderer with lazy loading
     *
     * @return Letterboxd_Movie_Block_Renderer
     */
    private function get_block_renderer() {
        if (null === $this->block_renderer) {
            $this->block_renderer = new Letterboxd_Movie_Block_Renderer();
        }
        return $this->block_renderer;
    }

    /**
     * Get settings manager with lazy loading
     *
     * @return Letterboxd_Settings_Manager
     */
    private function get_settings() {
        if (null === $this->settings) {
            $this->settings = new Letterboxd_Settings_Manager(
                $this->get_api_service()
            );
        }
        return $this->settings;
    }

    /**
     * Get auto import handler with lazy loading
     *
     * @return Letterboxd_Auto_Import
     */
    private function get_auto_import() {
        if (null === $this->auto_import) {
            $this->auto_import = new Letterboxd_Auto_Import($this);
        }
        return $this->auto_import;
    }

    /**
     * Plugin activation
     */
    public function activate() {
        // Create necessary directories in one operation
        $upload_dir = wp_upload_dir();
        $base_dir = $upload_dir["basedir"];

        $dirs = [
            $base_dir . "/letterboxd-temp",
            $base_dir . "/letterboxd-posters"
        ];

        foreach ($dirs as $dir) {
            if (!file_exists($dir)) {
                wp_mkdir_p($dir);
            }
        }

        // Set up the auto-import schedule.
        if (!isset($this->auto_import)) {
            $this->auto_import = $this->get_auto_import();
        }

        if (method_exists($this->auto_import, "initialize_schedule")) {
            $this->auto_import->initialize_schedule();
        }

        // Flush rewrite rules.
        flush_rewrite_rules();
    }

    /**
     * Plugin deactivation
     */
    public function deactivate(): void {
        // Clear scheduled events
        wp_clear_scheduled_hook("letterboxd_check_and_import");

        // Also clear the transient cleanup schedule
        wp_clear_scheduled_hook("letterboxd_cleanup_transients");

        // Clean up specific transients by type
        $transients_to_delete = [
            // Importer transients
            "letterboxd_to_wp_import_import_lock",
            "letterboxd_to_wp_import_feed_",

            // Auto-import transients
            "letterboxd_auto_import_import_lock",
            "letterboxd_rate_limit",

            // Block renderer transients
            "letterboxd_blocks_editor_data",
            "letterboxd_blocks_block_",
            "letterboxd_blocks_movie_query_",

            // TMDB Handler transients
            "letterboxd_tmdb_movie_",
            "letterboxd_tmdb_streaming_",
            "letterboxd_tmdb_external_ids_",
            "tmdb_rate_limit",
            "letterboxd_tmdb_request_token",
            "letterboxd_tmdb_auth_callback",

            // Debug transient
            "letterboxd_created_transients"
        ];

        global $wpdb;

        // Process each transient type with both direct and pattern-based deletion
        foreach ($transients_to_delete as $transient_base) {
            // For exact matches (simple transients)
            delete_transient($transient_base);

            // For pattern-based matches (transients with dynamic parts)
            $like_pattern = $wpdb->esc_like($transient_base) . "%";
            $transient_keys = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT option_name FROM {$wpdb->options} 
                    WHERE option_name LIKE %s 
                    OR option_name LIKE %s",
                    "_transient_" . $like_pattern,
                    "_transient_timeout_" . $like_pattern
                )
            );

            foreach ($transient_keys as $key) {
                $clean_name = str_replace(
                    ["_transient_", "_transient_timeout_"],
                    "",
                    $key
                );
                delete_transient($clean_name);
            }
        }

        // Flush rewrite rules
        flush_rewrite_rules();
    }

    /**
     * Display admin notice for missing username
     */
    public function display_username_notice() {
        // Get the saved options - use static caching
        static $options = null;
        if (null === $options) {
            $options = get_option("letterboxd_wordpress_options", []);
        }

        $username = isset($options["username"]) ? $options["username"] : "";

        // Only show notice if username isn't set
        if (empty($username)) {
            $settings_url = admin_url(
                "options-general.php?page=letterboxd-connect"
            ); ?>
            <div class="notice notice-warning is-dismissible">
                <p>
                    <?php
                    printf(
                        wp_kses(
                            // translators: %s is the URL to the settings page
                            __(
                                'Please <a href="%s">set your Letterboxd username</a> to start using the Letterboxd Connect plugin',
                                "letterboxd-connect"
                            ),
                            ['a' => ['href' => []]]
                        ),
                        esc_url($settings_url)
                    ); ?>
                </p>
            </div>
            <?php
        }
    }
}

// Initialize plugin
$letterboxd_to_wordpress = Letterboxd_To_WordPress::get_instance();

// Include helper functions
require_once LETTERBOXD_PLUGIN_DIR . "includes/functions.php";
