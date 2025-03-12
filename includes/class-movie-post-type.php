<?php
/**
 * Handles the Movie custom post type registration and management
 *
 * @package letterboxd-connect
 * @since 1.0.0
 */

declare(strict_types=1);

// Prevent direct access
if (!defined("ABSPATH")) {
    exit();
}

/**
 * Movie post type handler class
 */
class Letterboxd_Movie_Post_Type {
    private function debug_log($message) {
        letterboxd_debug_log($message, "Movie_Post_Type");
    }

    /**
     * Post type and taxonomy constants
     */
    private const POST_TYPE = "movie";
    private const YEAR_TAXONOMY = "movie_year";
    private const CACHE_GROUP = "letterboxd_movies";

    /**
     * Cached capabilities
     */
    private array $capabilities;

    /**
     * Meta fields configuration
     */
    private const META_FIELDS = [
        "letterboxd_url" => [
            "type" => "string",
            "description" => "Letterboxd movie URL",
            "sanitize_callback" => "esc_url_raw",
        ],
        "poster_url" => [
            "type" => "string",
            "description" => "Movie poster URL",
            "sanitize_callback" => "esc_url_raw",
        ],
        "director" => [
            "type" => "string",
            "description" => "Movie director(s)",
            "sanitize_callback" => "sanitize_text_field",
        ],
    ];

    /**
     * Initialize the class and set its properties
     */
    public function __construct() {
        $this->setup_hooks();
        $this->setup_capabilities();
        $this->map_post_type_capabilities();
    }

    /**
     * Set up WordPress hooks
     */
    private function setup_hooks(): void {
        // Registration hooks
        add_action("init", [$this, "register_post_type"]);
        add_action("init", [$this, "register_taxonomies"]);
        add_action("init", [$this, "register_meta_fields"]);

        // Admin customization
        add_action("admin_head", [$this, "add_admin_styles"]);
        add_filter("enter_title_here", [$this, "modify_title_placeholder"]);
        add_filter("post_updated_messages", [
            $this,
            "customize_updated_messages",
        ]);

        // Query modifications
        add_action("pre_get_posts", [$this, "modify_archive_query"]);

        // REST API customization
        add_action("rest_api_init", [$this, "register_rest_fields"]);

        // Flush rewrite rules only once after activation
        add_action("after_switch_theme", "flush_rewrite_rules");
    }

    /**
     * Set up custom capabilities
     */
    private function setup_capabilities(): void {
        $this->capabilities = [
            "edit_post" => "edit_movie",
            "edit_posts" => "edit_movies",
            "edit_others_posts" => "edit_others_movies",
            "publish_posts" => "publish_movies",
            "read_post" => "read_movie",
            "read_private_posts" => "read_private_movies",
            "delete_post" => "delete_movie",
        ];
    }

    /**
     * Register the Movie post type
     */
    public function register_post_type(): void {
        $args = [
            "public" => true,
            "label" => __("Movies", "letterboxd-connect"),
            "labels" => $this->get_labels(),
            "supports" => [
                "title",
                "editor",
                "thumbnail",
                "excerpt",
                "custom-fields",
                "revisions",
            ],
            "menu_icon" => "dashicons-video-alt2",
            "has_archive" => true,
            "rewrite" => [
                "slug" => "movies",
                "with_front" => true,
                "feeds" => true,
            ],
            "show_in_rest" => true,
            "template" => [
                [
                    "core/paragraph",
                    [
                        "placeholder" => __(
                            "Add movie description...",
                            "letterboxd-connect"
                        ),
                    ],
                ],
            ],
            "capability_type" => "movie",
            "capabilities" => $this->capabilities,
            "map_meta_cap" => true,
            "hierarchical" => false,
            "menu_position" => 5,
            "taxonomies" => [self::YEAR_TAXONOMY],
            "show_in_nav_menus" => true,
            "show_in_admin_bar" => true,
            "query_var" => true,
        ];

        register_post_type(self::POST_TYPE, $args);
    }

    /**
     * Get the labels for the post type
     */
    private function get_labels(): array {
        return [
            "name" => _x(
                "Movies",
                "Post type general name",
                "letterboxd-connect"
            ),
            "singular_name" => _x(
                "Movie",
                "Post type singular name",
                "letterboxd-connect"
            ),
            "menu_name" => _x(
                "Movies",
                "Admin Menu text",
                "letterboxd-connect"
            ),
            "name_admin_bar" => _x(
                "Movie",
                "Add New on Toolbar",
                "letterboxd-connect"
            ),
            "add_new" => _x("Add New", "movie", "letterboxd-connect"),
            "add_new_item" => __("Add New Movie", "letterboxd-connect"),
            "edit_item" => __("Edit Movie", "letterboxd-connect"),
            "new_item" => __("New Movie", "letterboxd-connect"),
            "view_item" => __("View Movie", "letterboxd-connect"),
            "view_items" => __("View Movies", "letterboxd-connect"),
            "search_items" => __("Search Movies", "letterboxd-connect"),
            "not_found" => __("No movies found", "letterboxd-connect"),
            "not_found_in_trash" => __(
                "No movies found in Trash",
                "letterboxd-connect"
            ),
            "parent_item_colon" => __("Parent Movie:", "letterboxd-connect"),
            "all_items" => __("All Movies", "letterboxd-connect"),
            "archives" => __("Movie Archives", "letterboxd-connect"),
            "attributes" => __("Movie Attributes", "letterboxd-connect"),
            "insert_into_item" => __(
                "Insert into movie",
                "letterboxd-connect"
            ),
            "uploaded_to_this_item" => __(
                "Uploaded to this movie",
                "letterboxd-connect"
            ),
            "featured_image" => __("Movie Poster", "letterboxd-connect"),
            "set_featured_image" => __(
                "Set movie poster",
                "letterboxd-connect"
            ),
            "remove_featured_image" => __(
                "Remove movie poster",
                "letterboxd-connect"
            ),
            "use_featured_image" => __(
                "Use as movie poster",
                "letterboxd-connect"
            ),
            "filter_items_list" => __(
                "Filter movies list",
                "letterboxd-connect"
            ),
            "items_list_navigation" => __(
                "Movies list navigation",
                "letterboxd-connect"
            ),
            "items_list" => __("Movies list", "letterboxd-connect"),
        ];
    }

    /**
     * Map movie capabilities to appropriate roles
     */
    private function map_post_type_capabilities(): void {
        // Get the administrator role
        $admin = get_role("administrator");
        if (!$admin) {
            return;
        }

        // Core post type capabilities
        $capabilities = [
            "edit_movie",
            "edit_movies",
            "edit_others_movies",
            "publish_movies",
            "read_movie",
            "read_private_movies",
            "delete_movie",
            "delete_movies",
            "delete_others_movies",
            "delete_published_movies",
            "delete_private_movies",
            "edit_published_movies",
            "edit_private_movies",
        ];

        // Add each capability to the administrator role
        foreach ($capabilities as $cap) {
            $admin->add_cap($cap);
        }

        // Editor role should also have these capabilities
        $editor = get_role("editor");
        if ($editor) {
            foreach ($capabilities as $cap) {
                $editor->add_cap($cap);
            }
        }
    }

    /**
     * Remove capabilities on deactivation
     */
    private function remove_post_type_capabilities(): void {
        $roles = ["administrator", "editor"];
        $capabilities = [
            "edit_movie",
            "edit_movies",
            "edit_others_movies",
            "publish_movies",
            "read_movie",
            "read_private_movies",
            "delete_movie",
            "delete_movies",
            "delete_others_movies",
            "delete_published_movies",
            "delete_private_movies",
            "edit_published_movies",
            "edit_private_movies",
        ];

        foreach ($roles as $role_name) {
            $role = get_role($role_name);
            if ($role) {
                foreach ($capabilities as $cap) {
                    $role->remove_cap($cap);
                }
            }
        }
    }

    /**
     * Register taxonomies for the Movie post type with proper capability mapping
     */
    public function register_taxonomies(): void {
        register_taxonomy(self::YEAR_TAXONOMY, self::POST_TYPE, [
            "label" => __("Years", "letterboxd-connect"),
            "labels" => $this->get_year_labels(),
            "hierarchical" => false,
            "show_in_rest" => true,
            "show_admin_column" => true,
            "query_var" => true,
            "rewrite" => ["slug" => "movie-year"],
            "show_in_nav_menus" => true,
            "public" => true,
            "capabilities" => [
                "manage_terms" => "manage_movie_years",
                "edit_terms" => "edit_movie_years",
                "delete_terms" => "delete_movie_years",
                "assign_terms" => "assign_movie_years",
            ],
            "meta_box_cb" => "post_tags_meta_box",
        ]);

        $this->map_taxonomy_capabilities();
    }

    /**
     * Map taxonomy capabilities to appropriate roles
     */
    private function map_taxonomy_capabilities(): void {
        $admin = get_role("administrator");
        $editor = get_role("editor");

        if ($admin) {
            $admin->add_cap("manage_movie_years");
            $admin->add_cap("edit_movie_years");
            $admin->add_cap("delete_movie_years");
            $admin->add_cap("assign_movie_years");
        }

        if ($editor) {
            //if we wanted to assign different caps for editors
        }
    }

    /**
     * Remove taxonomy capabilities on plugin deactivation
     */
    public function remove_taxonomy_capabilities(): void {
        $roles = ["administrator", "editor"];
        $capabilities = [
            "manage_movie_years",
            "edit_movie_years",
            "delete_movie_years",
            "assign_movie_years",
        ];

        foreach ($roles as $role_name) {
            $role = get_role($role_name);
            if ($role) {
                foreach ($capabilities as $cap) {
                    $role->remove_cap($cap);
                }
            }
        }
    }

    public function cleanup_taxonomy_terms(): void {
        $terms = get_terms([
            "taxonomy" => self::YEAR_TAXONOMY,
            "hide_empty" => false,
            "fields" => "ids",
        ]);

        if (!is_wp_error($terms) && !empty($terms)) {
            foreach ($terms as $term_id) {
                $count = get_objects_in_term($term_id, self::YEAR_TAXONOMY);
                if (empty($count) || is_wp_error($count)) {
                    wp_delete_term($term_id, self::YEAR_TAXONOMY);
                }
            }
        }
    }

    /**
     * Check if user can manage movie taxonomies
     *
     * @param string $taxonomy_name The taxonomy to check
     * @return bool Whether the user can manage the taxonomy
     */
    public function can_manage_taxonomy(string $taxonomy_name): bool {
        $taxonomy = get_taxonomy($taxonomy_name);
        if (!$taxonomy) {
            return false;
        }

        return current_user_can($taxonomy->cap->manage_terms);
    }

    /**
     * Check if user can assign taxonomy terms
     *
     * @param string $taxonomy_name The taxonomy to check
     * @return bool Whether the user can assign terms
     */
    public function can_assign_taxonomy(string $taxonomy_name): bool {
        $taxonomy = get_taxonomy($taxonomy_name);
        if (!$taxonomy) {
            return false;
        }

        return current_user_can($taxonomy->cap->assign_terms);
    }

    /**
     * Get year taxonomy labels
     */
    private function get_year_labels(): array {
        return [
            "name" => _x(
                "Years",
                "taxonomy general name",
                "letterboxd-connect"
            ),
            "singular_name" => _x(
                "Year",
                "taxonomy singular name",
                "letterboxd-connect"
            ),
            "search_items" => __("Search Years", "letterboxd-connect"),
            "all_items" => __("All Years", "letterboxd-connect"),
            "edit_item" => __("Edit Year", "letterboxd-connect"),
            "update_item" => __("Update Year", "letterboxd-connect"),
            "add_new_item" => __("Add New Year", "letterboxd-connect"),
            "new_item_name" => __("New Year", "letterboxd-connect"),
            "menu_name" => __("Years", "letterboxd-connect"),
        ];
    }

    /**
     * Example of secure taxonomy term assignment
     */
    public function set_movie_terms(
        int $post_id,
        array $terms,
        string $taxonomy
    ): bool {
        // Check if user can assign terms
        if (!$this->can_assign_taxonomy($taxonomy)) {
            return false;
        }

        // Sanitize and validate terms
        $sanitized_terms = array_map("absint", $terms);

        // Set terms
        $result = wp_set_object_terms($post_id, $sanitized_terms, $taxonomy);

        return !is_wp_error($result);
    }

    /**
     * Example of secure taxonomy term creation
     */
    public function create_movie_term(
        string $name,
        string $taxonomy,
        array $args = []
    ): int|WP_Error {
        // Check if user can manage taxonomy
        if (!$this->can_manage_taxonomy($taxonomy)) {
            return new WP_Error(
                "insufficient_permissions",
                __(
                    "You do not have permission to create terms",
                    "letterboxd-connect"
                )
            );
        }

        // Sanitize term name
        $name = sanitize_text_field($name);

        // Create term
        $result = wp_insert_term($name, $taxonomy, $args);

        return is_wp_error($result) ? $result : $result["term_id"];
    }

    /**
     * Example of secure taxonomy term deletion
     */
    public function delete_movie_term(int $term_id, string $taxonomy): bool {
        // Check if user can manage taxonomy
        if (!$this->can_manage_taxonomy($taxonomy)) {
            return false;
        }

        // Delete term
        $result = wp_delete_term($term_id, $taxonomy);

        return !is_wp_error($result) && $result !== false;
    }

    /**
     * Example of secure taxonomy term update
     */
    public function update_movie_term(
        int $term_id,
        string $taxonomy,
        array $args
    ): bool {
        // Check if user can manage taxonomy
        if (!$this->can_manage_taxonomy($taxonomy)) {
            return false;
        }

        // Update term
        $result = wp_update_term($term_id, $taxonomy, $args);

        return !is_wp_error($result);
    }

    /**
     * Example of secure taxonomy term retrieval
     */
    public function get_movie_terms(int $post_id, string $taxonomy): array {
        $terms = wp_get_object_terms($post_id, $taxonomy, [
            "fields" => "all",
        ]);

        return is_wp_error($terms) ? [] : $terms;
    }

    /**
     * Register meta fields for the Movie post type
     */
    public function register_meta_fields(): void {
        foreach (self::META_FIELDS as $field => $config) {
            register_post_meta(self::POST_TYPE, $field, [
                "type" => $config["type"],
                "description" => $config["description"],
                "single" => true,
                "show_in_rest" => true,
                "sanitize_callback" => $config["sanitize_callback"],
                "auth_callback" => [$this, "meta_auth_callback"],
            ]);
        }
    }

    /**
     * Authorization callback for meta fields
     */
    public function meta_auth_callback(): bool {
        return current_user_can("edit_posts");
    }

    /**
     * Add custom admin styles
     */
    public function add_admin_styles(): void {
        global $post_type;
        if ($post_type === self::POST_TYPE) {
            echo '<style>
                .wp-admin.post-type-movie .page-title-action { margin-left: 0.5rem; }
                .wp-admin.post-type-movie #postcustom .inside { max-height: 25rem; overflow-y: auto; }
            </style>';
        }
    }

    /**
     * Modify the title placeholder
     */
    public function modify_title_placeholder(string $title): string {
        global $post_type;
        if ($post_type === self::POST_TYPE) {
            return __("Enter movie title", "letterboxd-connect");
        }
        return $title;
    }

    /**
     * Customize updated messages
     */
    public function customize_updated_messages(array $messages): array {
        global $post;

        $messages[self::POST_TYPE] = [
            0 => "", // Unused. Messages start at index 1.
            1 => __("Movie updated.", "letterboxd-connect"),
            2 => __("Custom field updated.", "letterboxd-connect"),
            3 => __("Custom field deleted.", "letterboxd-connect"),
            4 => __("Movie updated.", "letterboxd-connect"),
            5 => isset($_GET["revision"])
                ? sprintf(
                    /* translators: %s: revision title */
                    __(
                        "Movie restored to revision from %s",
                        "letterboxd-connect"
                    ),
                    wp_post_revision_title((int) $_GET["revision"], false)
                )
                : false,
            6 => __("Movie published.", "letterboxd-connect"),
            7 => __("Movie saved.", "letterboxd-connect"),
            8 => __("Movie submitted.", "letterboxd-connect"),
            9 => sprintf(
                /* translators: %s: scheduled movie post time */
                __("Movie scheduled for: %s.", "letterboxd-connect"),
                date_i18n(
                    __("M j, Y @ G:i", "letterboxd-connect"),
                    strtotime($post->post_date)
                )
            ),
            10 => __("Movie draft updated.", "letterboxd-connect"),
        ];

        return $messages;
    }

    /**
     * Modify archive query
     *
     * @param WP_Query $query The WordPress query object
     */
    public function modify_archive_query(WP_Query $query): void {
        if (is_admin() || !$query->is_main_query()) {
            return;
        }

        if ($query->is_post_type_archive(self::POST_TYPE)) {
            $query->set("posts_per_page", 24);
            $query->set("orderby", "date");
            $query->set("order", "DESC");
        }
    }

    /**
     * Register REST API fields
     */
    public function register_rest_fields(): void {
        register_rest_field(self::POST_TYPE, "movie_meta", [
            "get_callback" => [$this, "get_movie_meta"],
            "update_callback" => [$this, "update_movie_meta"],
            "schema" => [
                "description" => __("Movie metadata", "letterboxd-connect"),
                "type" => "object",
                "properties" => $this->get_meta_schema_properties(),
            ],
        ]);
    }

    /**
     * Get meta schema properties
     */
    private function get_meta_schema_properties(): array {
        $properties = [];

        foreach (self::META_FIELDS as $field => $config) {
            $properties[$field] = [
                "type" => $config["type"],
                "description" => $config["description"],
                "context" => ["view", "edit"],
                "arg_options" => [
                    "sanitize_callback" => $config["sanitize_callback"],
                ],
            ];
        }

        return $properties;
    }

    /**
     * Get movie meta for REST API
     *
     * @param array $post Array of post data
     * @return array Movie meta data
     */
    public function get_movie_meta(array $post): array {
        $meta = [];

        foreach (array_keys(self::META_FIELDS) as $field) {
            $meta[$field] = get_post_meta($post["id"], $field, true);
        }

        return $meta;
    }

    /**
     * Update movie meta via REST API
     *
     * @param array $meta Array of meta values to update
     * @param WP_Post $post Post object
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    public function update_movie_meta(array $meta, WP_Post $post) {
        if (!current_user_can("edit_post", $post->ID)) {
            return new WP_Error(
                "rest_cannot_update",
                __(
                    "Sorry, you are not allowed to update this post.",
                    "letterboxd-connect"
                ),
                ["status" => rest_authorization_required_code()]
            );
        }

        $updated = true;
        foreach ($meta as $key => $value) {
            if (isset(self::META_FIELDS[$key])) {
                $sanitize_callback =
                    self::META_FIELDS[$key]["sanitize_callback"];
                $sanitized_value = is_array($sanitize_callback)
                    ? call_user_func($sanitize_callback, $value)
                    : call_user_func($sanitize_callback, $value);

                $updated =
                    $updated &&
                    update_post_meta($post->ID, $key, $sanitized_value);
            }
        }

        return $updated;
    }

    /**
     * Find and attach the movie poster to the post
     */
    public function set_movie_poster(int $post_id, string $url): bool {
        if (!current_user_can("edit_post", $post_id)) {
            return false;
        }

        $upload_dir = wp_upload_dir();
        $filename = basename($url);
        $file = wp_remote_get($url);

        if (is_wp_error($file)) {
            letterboxd_debug_log(
                "Failed to fetch poster: " . $file->get_error_message()
            );
            return false;
        }

        $image_data = wp_remote_retrieve_body($file);
        $filepath = $upload_dir["path"] . "/" . $filename;

        file_put_contents($filepath, $image_data);

        $wp_filetype = wp_check_filetype($filename, null);
        $attachment = [
            "post_mime_type" => $wp_filetype["type"],
            "post_title" => sanitize_file_name($filename),
            "post_content" => "",
            "post_status" => "inherit",
        ];

        $attach_id = wp_insert_attachment($attachment, $filepath, $post_id);
        if ($attach_id === 0) {
            letterboxd_debug_log("Failed to create attachment for poster");
            return false;
        }

        require_once ABSPATH . "wp-admin/includes/image.php";
        $attach_data = wp_generate_attachment_metadata($attach_id, $filepath);
        wp_update_attachment_metadata($attach_id, $attach_data);
        set_post_thumbnail($post_id, $attach_id);

        return true;
    }

    /**
     * Get movie by Letterboxd URL
     *
     * @param string $url Letterboxd URL
     * @return WP_Post|null Post object if found, null otherwise
     */
    public function get_movie_by_letterboxd_url(string $url): ?WP_Post {
        $posts = get_posts([
            "post_type" => self::POST_TYPE,
            "meta_key" => "letterboxd_url",
            "meta_value" => esc_url_raw($url),
            "posts_per_page" => 1,
            "post_status" => "any",
        ]);

        return !empty($posts) ? $posts[0] : null;
    }

    /**
     * Check if a movie exists by its Letterboxd URL
     *
     * @param string $url Letterboxd URL
     * @return bool Whether the movie exists
     */
    public function movie_exists(string $url): bool {
        return $this->get_movie_by_letterboxd_url($url) !== null;
    }

    /**
     * Get movies by year
     *
     * @param int $year Year to filter by
     * @param array $args Additional query arguments
     * @return array Array of WP_Post objects
     */
    public function get_movies_by_year(int $year, array $args = []): array {
        $default_args = [
            "post_type" => self::POST_TYPE,
            "posts_per_page" => -1,
            "tax_query" => [
                [
                    "taxonomy" => self::YEAR_TAXONOMY,
                    "field" => "slug",
                    "terms" => (string) $year,
                ],
            ],
        ];

        $query_args = wp_parse_args($args, $default_args);
        return get_posts($query_args);
    }

    /**
     * Get latest movie
     *
     * @return WP_Post|null Latest movie post or null if none exists
     */
    private function get_latest_movie(): ?WP_Post {
        $latest = get_posts([
            "post_type" => self::POST_TYPE,
            "posts_per_page" => 1,
            "orderby" => "date",
            "order" => "DESC",
        ]);

        return !empty($latest) ? $latest[0] : null;
    }

    /**
     * Update the activation method to include capability mapping
     */
    public function activate(): void {
        $this->map_post_type_capabilities();
        $this->map_taxonomy_capabilities();
    }

    /**
     * Update the deactivation method to remove capabilities
     */
    public function deactivate(): void {
        $this->remove_post_type_capabilities();
        $this->remove_taxonomy_capabilities();
    }
}