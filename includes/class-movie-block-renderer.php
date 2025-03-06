<?php
/**
 * Class to handle Movie Block registration and integration
 *
 * @package LetterboxdToWordPress
 * @since 1.0.0
 */

declare(strict_types=1);

// Prevent direct access
if (!defined("ABSPATH")) {
    exit();
}

class Letterboxd_Movie_Block_Renderer {
    private function debug_log($message) {
        letterboxd_debug_log($message, "Movie_Block_Renderer");
    }

    /**
     * Block-related constants
     */
    private const BLOCK_NAME = "letterboxd-wordpress/movie-grid";
    private const CACHE_GROUP = "letterboxd_blocks";
    private const CACHE_DURATION = 3600; // 1 hour
    private const MOVIE_POSTER_SIZE = "movie-poster";

    /**
     * Default block attributes
     */
    private const DEFAULT_ATTRIBUTES = [
        "number" => [
            "type" => "number",
            "default" => 12,
        ],
        "orderby" => [
            "type" => "string",
            "default" => "watch_date",
        ],
        "order" => [
            "type" => "string",
            "default" => "DESC",
        ],
    ];

    /**
     * Initialize the block functionality
     */
    public function __construct() {
        $this->setup_hooks();
    }

    /**
     * Set up WordPress hooks
     */
    private function setup_hooks(): void {
        // Register custom image size
        add_action("after_setup_theme", [$this, "register_movie_poster_size"]);
        add_filter("image_size_names_choose", [
            $this,
            "add_movie_poster_to_image_sizes",
        ]);

        // Registration hooks
        add_action("init", [$this, "register_block"]);
        add_action("rest_api_init", [$this, "register_rest_route"]);

        // Asset handling
        if (is_admin()) {
            add_action("enqueue_block_editor_assets", [
                $this,
                "enqueue_editor_assets",
            ]);
        }
        add_action("enqueue_block_assets", [$this, "enqueue_block_assets"]);

        // Cache clearing
        add_action("save_post_movie", [$this, "clear_block_cache"]);
        add_action("deleted_post", [$this, "clear_block_cache"]);
        add_action("edited_movie_genre", [$this, "clear_taxonomy_cache"]);
        add_action("edited_movie_year", [$this, "clear_taxonomy_cache"]);
    }

    public function register_rest_route(): void {
        register_rest_route("letterboxd-wordpress/v1", "/render-movie-grid", [
            "methods" => "GET",
            "callback" => [$this, "render_block"],
            "permission_callback" => [$this, "check_block_permissions"],
            "args" => [
                "number" => [
                    "validate_callback" => function ($param) {
                        return is_numeric($param) &&
                            $param > 0 &&
                            $param <= 100;
                    },
                ],
                "orderby" => [
                    "validate_callback" => function ($param) {
                        return in_array($param, [
                            "title",
                            "watch_date",
                            "release_year",
                        ]);
                    },
                ],
                "order" => [
                    "validate_callback" => function ($param) {
                        return in_array(strtoupper($param), ["ASC", "DESC"]);
                    },
                ],
            ],
        ]);
    }

    public function check_block_permissions(): bool {
        return current_user_can("edit_posts");
    }

    /**
     * Register the movie grid block
     */
    public function register_block(): void {
        if (!function_exists("register_block_type")) {
            return;
        }

        register_block_type("letterboxd-wordpress/movie-grid", [
            "api_version" => 2,
            "editor_script" => "letterboxd-blocks",
            "editor_style" => "letterboxd-blocks-editor",
            "style" => "letterboxd-movie-grid",
            "render_callback" => [$this, "render_block"],
            "attributes" => [
                "number" => [
                    "type" => "number",
                    "default" => 12,
                ],
                "showAll" => [
                    "type" => "boolean",
                    "default" => false,
                ],
                "perPage" => [
                    "type" => "number",
                    "default" => 12,
                ],
                "orderby" => [
                    "type" => "string",
                    "default" => "watch_date",
                ],
                "order" => [
                    "type" => "string",
                    "default" => "DESC",
                ],
                "columns" => [
                    "type" => "number",
                    "default" => 3,
                ],
                "displayMode" => [
                    "type" => "string",
                    "default" => "cards",
                ],
                // Display options
                "showDirector" => [
                    "type" => "boolean",
                    "default" => true,
                ],
                "showRating" => [
                    "type" => "boolean",
                    "default" => true,
                ],
                "showStreamingLink" => [
                    "type" => "boolean",
                    "default" => true,
                ],
                "showExternalLinks" => [
                    "type" => "boolean",
                    "default" => true,
                ],
            ],
        ]);
    }

    /**
     * Enqueue editor-specific assets
     */
    public function enqueue_editor_assets(): void {
        // Enqueue block script with dependencies
        wp_enqueue_script(
            "letterboxd-movie-block",
            plugins_url("js/movie-block.js", LETTERBOXD_PLUGIN_FILE),
            [
                "wp-blocks",
                "wp-element",
                "wp-editor",
                "wp-components",
                "wp-i18n",
                "wp-block-editor",
                "wp-server-side-render", // Added this dependency to match block.js
            ],
            LETTERBOXD_VERSION,
            true
        );

        // Add dynamic data for the editor
        wp_localize_script(
            "letterboxd-movie-block",
            "letterboxdMovieBlock",
            $this->get_editor_data()
        );

        // Enqueue editor styles
        wp_enqueue_style(
            "letterboxd-movie-block-editor",
            plugins_url("css/movie-block-editor.css", LETTERBOXD_PLUGIN_FILE),
            [],
            LETTERBOXD_VERSION
        );
    }

    /**
     * Get cached editor data
     */
    private function get_editor_data(): array {
        $cache_key = "editor_data";
        $data = wp_cache_get($cache_key, self::CACHE_GROUP);

        if ($data === false) {
            $data = [
                "pluginUrl" => plugins_url("", LETTERBOXD_PLUGIN_FILE),
            ];
            wp_cache_set(
                $cache_key,
                $data,
                self::CACHE_GROUP,
                self::CACHE_DURATION
            );
        }

        return $data;
    }

    /**
     * Enqueue block assets for both editor and front-end
     */
    public function enqueue_block_assets(): void {
        if (has_block(self::BLOCK_NAME)) {
            wp_enqueue_style(
                "letterboxd-movie-grid",
                plugins_url("css/movie-grid.css", LETTERBOXD_PLUGIN_FILE),
                ["wp-components"],
                LETTERBOXD_VERSION
            );
        }
    }

    /**
     * Render the movie grid block
     */
    public function render_block(array $attributes): string {
        // Determine context to differentiate editor preview from front-end
        $is_rest = defined("REST_REQUEST") && REST_REQUEST;
        $context = $is_rest ? "edit" : "front";

        // Get current page if we're showing all with pagination
        $paged = 1;
        if ($attributes["showAll"] ?? false) {
            $paged = get_query_var("paged") ? get_query_var("paged") : 1;
        }

        $cache_key =
            "block_" .
            md5(serialize($attributes)) .
            "_" .
            $context .
            "_page_" .
            $paged;
        $output = wp_cache_get($cache_key, self::CACHE_GROUP);

        if ($output === false) {
            $query = $this->get_movies_query($attributes, $context, $paged);

            // Get attributes with defaults
            $columns = isset($attributes["columns"])
                ? intval($attributes["columns"])
                : 3;
            $display_mode = isset($attributes["displayMode"])
                ? sanitize_text_field($attributes["displayMode"])
                : "cards";
            $show_all = isset($attributes["showAll"])
                ? (bool) $attributes["showAll"]
                : false;

            // Add display options to render attributes
            $render_options = [
                "layout" => $display_mode === "list" ? "list" : "grid",
                "columns" => $columns,
                "showDirector" => $attributes["showDirector"] ?? true,
                "showRating" => $attributes["showRating"] ?? true,
                "showStreamingLink" => $attributes["showStreamingLink"] ?? true,
                "showExternalLinks" => $attributes["showExternalLinks"] ?? true,
                "showPagination" => $show_all,
            ];

            // Use the unified render_movie_collection method with display options
            $grid = $this->render_movie_collection($query, $render_options);

            $pagination = "";
            $pagination_note = "";
            // Only show pagination note if it's a REST request AND showAll is true
            if ($is_rest && $show_all) {
                $pagination_note =
                    '<div class="pagination-note">' .
                    __(
                        "Pagination will appear on the front end",
                        "letterboxd-wordpress"
                    ) .
                    "</div>";
            } elseif ($show_all && !$is_rest) {
                $pagination = $this->render_pagination($query);
            }

            if ($context === "edit") {
                // In the editor context
                $output = sprintf(
                    '<div class="editor-preview" data-columns="%d" data-display-mode="%s">%s%s</div>',
                    $columns,
                    esc_attr($display_mode),
                    $grid,
                    $pagination_note
                );
            } else {
                // Front-end context
                $output = sprintf(
                    '<div class="wp-block-letterboxd-wordpress-movie-grid" data-columns="%d" data-display-mode="%s">%s%s</div>',
                    $columns,
                    esc_attr($display_mode),
                    $grid,
                    $pagination
                );
            }

            // Cache editor content for less time than front-end content
            $cache_duration = $context === "edit" ? 300 : self::CACHE_DURATION;
            wp_cache_set(
                $cache_key,
                $output,
                self::CACHE_GROUP,
                $cache_duration
            );
        }

        return $output;
    }

    /**
     * Get movies query based on block attributes
     *
     * @param array $attributes Block attributes from the editor
     * @return WP_Query Query object with movies
     */
    private function get_movies_query(
        array $attributes,
        string $context = "front",
        int $paged = 1
    ): WP_Query {
        // Skip cache for admin/editing context when debugging
        $use_cache = !($context === "edit" && defined("WP_DEBUG") && WP_DEBUG);
        $cache_key =
            "movie_query_" .
            md5(serialize($attributes)) .
            "_" .
            $context .
            "_page_" .
            $paged;
        if ($use_cache) {
            $cached_query = wp_cache_get($cache_key, self::CACHE_GROUP);
            if ($cached_query !== false) {
                return $cached_query;
            }
        }

        $show_all = isset($attributes["showAll"])
            ? (bool) $attributes["showAll"]
            : false;
        $per_page = isset($attributes["perPage"])
            ? intval($attributes["perPage"])
            : 12;

        // Base query arguments
        $args = [
            "post_type" => "movie",
            "order" =>
                $attributes["order"] ??
                self::DEFAULT_ATTRIBUTES["order"]["default"],

            // Set posts_per_page based on showAll setting
            "posts_per_page" => $show_all
                ? $per_page
                : $attributes["number"] ??
                    self::DEFAULT_ATTRIBUTES["number"]["default"],

            // Add pagination if showing all
            "paged" => $show_all ? $paged : 1,

            // Performance optimization flags - disable if showing all
            "no_found_rows" => !$show_all,
            "update_post_term_cache" => true,
            "update_post_meta_cache" => true,
        ];

        // Define filter callback variables for later removal
        $orderby_filter = null;
        $join_filter = null;

        // Handle different ordering options
        switch (
            $attributes["orderby"] ??
            self::DEFAULT_ATTRIBUTES["orderby"]["default"]
        ) {
            case "title":
                $args["orderby"] = "title";
                break;

            case "release_year":
                // Join with terms table and order by term name
                $args["tax_query"] = [
                    [
                        "taxonomy" => "movie_year",
                        "operator" => "EXISTS",
                    ],
                ];
                $args["orderby"] = "terms";
                $args["order"] = $attributes["order"];

                // Define filter callbacks and assign them to variables
                $orderby_filter = function ($orderby, $query) use ($args) {
                    global $wpdb;
                    return "{$wpdb->terms}.name " . $args["order"];
                };
                $join_filter = function ($join, $query) {
                    global $wpdb;
                    $join .= " LEFT JOIN {$wpdb->term_relationships} tr ON {$wpdb->posts}.ID = tr.object_id";
                    $join .= " LEFT JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id AND tt.taxonomy = 'movie_year'";
                    $join .= " LEFT JOIN {$wpdb->terms} ON tt.term_id = {$wpdb->terms}.term_id";
                    return $join;
                };

                add_filter("posts_orderby", $orderby_filter, 10, 2);
                add_filter("posts_join", $join_filter, 10, 2);
                break;

            case "watch_date":
            default:
                $args["meta_key"] = "watch_date";
                $args["orderby"] = "meta_value";
        }

        // Create the query
        $query = new WP_Query($args);

        // Remove the filters if they were added
        if (($attributes["orderby"] ?? "") === "release_year") {
            if ($orderby_filter !== null) {
                remove_filter("posts_orderby", $orderby_filter, 10);
            }
            if ($join_filter !== null) {
                remove_filter("posts_join", $join_filter, 10);
            }
        }

        // Cache the query
        wp_cache_set(
            $cache_key,
            $query,
            self::CACHE_GROUP,
            self::CACHE_DURATION
        );

        return $query;
    }

    /**
     * Render pagination for movie grid
     *
     * @param WP_Query $query The WordPress query object
     * @return string HTML pagination markup
     */
    private function render_pagination(WP_Query $query): string {
        if (!$query->max_num_pages || $query->max_num_pages <= 1) {
            return "";
        }

        $big = 999999999; // Need an unlikely integer
        $pages = paginate_links([
            "base" => str_replace(
                (string) $big,
                "%#%",
                esc_url(get_pagenum_link($big))
            ),
            "format" => "?paged=%#%",
            "current" => max(1, get_query_var("paged")),
            "total" => $query->max_num_pages,
            "type" => "array",
            "prev_text" => __("&laquo; Previous", "letterboxd-wordpress"),
            "next_text" => __("Next &raquo;", "letterboxd-wordpress"),
        ]);

        if (is_array($pages)) {
            $pagination =
                '<nav class="movie-grid-pagination" aria-label="' .
                __("Movies navigation", "letterboxd-wordpress") .
                '">';
            $pagination .= '<ul class="page-numbers">';

            foreach ($pages as $page) {
                $pagination .= "<li>" . $page . "</li>";
            }

            $pagination .= "</ul>";
            $pagination .= "</nav>";

            return $pagination;
        }

        return "";
    }

    /**
     * Render a collection of movies in either grid or list format
     *
     * @param WP_Query $query      The WordPress query with movie posts
     * @param array    $attributes Display attributes (layout, columns, etc.)
     * @return string              The rendered HTML
     */
    private function render_movie_collection(
        WP_Query $query,
        array $attributes
    ): string {
        ob_start();

        // Layout type (grid or list)
        $layout = isset($attributes["layout"]) ? $attributes["layout"] : "grid";

        if ($query->have_posts()) {
            // Container class based on layout
            $container_class = $layout === "list" ? "movie-list" : "movie-grid";

            // Add columns attribute for grid layout
            if ($layout === "grid") {
                $columns = isset($attributes["columns"])
                    ? intval($attributes["columns"])
                    : 3;
                printf(
                    '<div class="%s columns-%s">',
                    esc_attr($container_class),
                    esc_attr($columns)
                );
            } else {
                printf('<div class="%s">', esc_attr($container_class));
            }

            // Extract display options
            $display_options = [
                "showDirector" => $attributes["showDirector"] ?? true,
                "showRating" => $attributes["showRating"] ?? true,
                "showStreamingLink" => $attributes["showStreamingLink"] ?? true,
                "showExternalLinks" => $attributes["showExternalLinks"] ?? true,
            ];

            // Render each movie item
            while ($query->have_posts()) {
                $query->the_post();
                $this->render_movie_item(
                    get_the_ID(),
                    $layout,
                    $display_options
                );
            }

            echo "</div>";
        } else {
            echo '<p class="no-movies">' .
                esc_html__("No movies found.", "letterboxd-wordpress") .
                "</p>";
        }

        wp_reset_postdata();
        return ob_get_clean();
    }

    /**
     * Get movie poster HTML with appropriate size parameter
     */
    private function get_movie_poster(
        int $post_id,
        string $size = "movie-poster"
    ): string {
        if (!has_post_thumbnail($post_id)) {
            // Return placeholder if no thumbnail
            return '<div class="movie-poster movie-poster-placeholder"></div>';
        }

        $title = get_the_title($post_id);
        return sprintf(
            '<div class="movie-poster">%s</div>',
            get_the_post_thumbnail($post_id, $size, [
                "alt" => sprintf(
                    __("Movie poster for %s", "letterboxd-wordpress"),
                    esc_attr($title)
                ),
            ])
        );
    }

    /**
     * Get movie title HTML
     */
    private function get_movie_title(int $post_id): string {
        return sprintf(
            '<h3 class="movie-title">%s</h3>',
            esc_html(get_the_title($post_id))
        );
    }

    /**
     * Render an individual movie item (card or list item)
     *
     * @param int    $post_id The movie post ID
     * @param string $layout  The layout type ('grid' or 'list')
     */
    private function render_movie_item(
        int $post_id,
        string $layout = "grid",
        array $display_options = []
    ): void {
        // Set default display options if not provided
        $display_options = wp_parse_args($display_options, [
            "showDirector" => true,
            "showRating" => true,
            "showStreamingLink" => true,
            "showExternalLinks" => true,
        ]);

        // Get all needed meta data once
        $meta_data = [
            "director" => get_post_meta($post_id, "director", true),
            "watch_date" => get_post_meta($post_id, "watch_date", true),
            "rating" => get_post_meta($post_id, "movie_rating", true),
            "imdb_id" => get_post_meta($post_id, "imdb_id", true),
            "year" => $this->get_movie_year($post_id),
            "streaming_link" => get_post_meta($post_id, "streaming_link", true),
        ];

        // Format watch date if available
        $date_watched_html = "";
        if (!empty($meta_data["watch_date"])) {
            $formatted_date = date_i18n(
                get_option("date_format"),
                strtotime($meta_data["watch_date"])
            );
            $date_watched_html = sprintf(
                '<p class="watch-date">%s</p>',
                esc_html($formatted_date)
            );
        }

        // Format rating if available and option is enabled
        $rating_html = "";
        if ($display_options["showRating"] && !empty($meta_data["rating"])) {
            $rating_html = sprintf(
                '<p class="movie-rating">%s</p>',
                esc_html($meta_data["rating"])
            );
        }

        // Format director if available and option is enabled
        $director_html = "";
        if (
            $display_options["showDirector"] &&
            !empty($meta_data["director"])
        ) {
            $director_html = sprintf(
                '<p class="movie-director">%s</p>',
                esc_html($meta_data["director"])
            );
        }

        // Format streaming link if available and option is enabled
        $streaming_link_html = "";
        if (
            $display_options["showStreamingLink"] &&
            !empty($meta_data["streaming_link"])
        ) {
            $streaming_link_html = sprintf(
                '<p class="watch-link"><a href="%s" target="_blank" rel="noopener noreferrer">Where to Watch &raquo;</a></p>',
                esc_url($meta_data["streaming_link"])
            );
        }

        // Get external links HTML if option is enabled
        $external_links_html = "";
        if ($display_options["showExternalLinks"]) {
            $external_links_html = $this->get_external_links_html(
                $post_id,
                $meta_data["imdb_id"],
                $meta_data["year"]
            );
        }

        // Check if we have either streaming link or external links to show
        $links_wrapper = "";
        if (!empty($streaming_link_html) || !empty($external_links_html)) {
            $links_wrapper = sprintf(
                '<div class="movie-links">%s%s</div>',
                $streaming_link_html,
                $external_links_html
            );
        }

        // Render based on layout type
        if ($layout === "grid") {
            // Grid card layout
            printf(
                '<div class="movie-card movie-item">
                    %s
                    <div class="movie-details">
                        %s
                        <div class="movie-meta">
                            %s
                            %s
                            %s
                        </div>
                        %s
                    </div>
                </div>',
                $this->get_movie_poster($post_id, "movie-poster"),
                $this->get_movie_title($post_id),
                $date_watched_html,
                $director_html,
                $rating_html,
                $links_wrapper
            );
        } else {
            // List item layout
            printf(
                '<div class="movie-list-item movie-item">
                    %s
                    <div class="movie-details">
                        %s
                        <div class="movie-meta">
                            %s
                            %s
                            %s
                        </div>
                        %s
                    </div>
                </div>',
                $this->get_movie_poster($post_id, "movie-poster"),
                $this->get_movie_title($post_id),
                $date_watched_html,
                $director_html,
                $rating_html,
                $links_wrapper
            );
        }
    }

    /**
     * Register custom movie poster image size
     */
    public function register_movie_poster_size(): void {
        // Register a 2:3 aspect ratio image size (600px width × 900px height)
        add_image_size(self::MOVIE_POSTER_SIZE, 600, 900, true);
    }

    /**
     * Add custom movie poster size to admin UI
     */
    public function add_movie_poster_to_image_sizes(array $sizes): array {
        return array_merge($sizes, [
            self::MOVIE_POSTER_SIZE => __(
                "Movie Poster (2:3)",
                "letterboxd-wordpress"
            ),
        ]);
    }

    /**
     * Get movie year from taxonomy
     */
    private function get_movie_year(int $post_id): string {
        $terms = wp_get_object_terms($post_id, "movie_year");
        if (!empty($terms) && !is_wp_error($terms)) {
            return $terms[0]->name;
        }
        return "";
    }

    /**
     * Generate HTML for external links (IMDb and Rotten Tomatoes)
     */
    private function get_external_links_html(
        int $post_id,
        string $imdb_id = "",
        string $year = "",
        bool $is_list_view = false
    ): string {
        if (empty($imdb_id) && empty($year)) {
            return "";
        }

        $links = [];
        $title = get_the_title($post_id);

        // Create IMDb link if ID exists
        if (!empty($imdb_id)) {
            $imdb_url = "https://www.imdb.com/title/{$imdb_id}/";
            $links[] = sprintf(
                '<a href="%s" target="_blank" class="external-link imdb-link" title="View on IMDb">IMDb</a>',
                esc_url($imdb_url)
            );
        }

        // Create Rotten Tomatoes link if we have a title and year
        if (!empty($title) && !empty($year)) {
            $rt_search_query = urlencode(trim($title) . " " . trim($year));
            $rt_url = "https://www.rottentomatoes.com/search?search={$rt_search_query}";
            $links[] = sprintf(
                '<a href="%s" target="_blank" class="external-link rt-link" title="Search on Rotten Tomatoes">RT</a>',
                esc_url($rt_url)
            );
        }
        if (empty($links)) {
            return "";
        }
        return sprintf(
            '<div class="external-links-list">%s</div>',
            implode(" ", $links)
        );
    }

    /**
     * Get streaming providers HTML for a movie
     *
     * @param int $post_id Movie post ID
     * @return string HTML for streaming providers
     */
    private function get_streaming_providers_html(int $post_id): string {
        if (!class_exists("Letterboxd_TMDB_Handler")) {
            return "";
        }

        $providers_json = get_post_meta($post_id, "streaming_providers", true);

        if (empty($providers_json)) {
            return "";
        }

        $providers = json_decode($providers_json, true);
        if (empty($providers) || json_last_error() !== JSON_ERROR_NONE) {
            return "";
        }

        // Get movie information needed for direct links
        $tmdb_id = get_post_meta($post_id, "tmdb_id", true);
        if (empty($tmdb_id)) {
            $tmdb_id = get_post_meta($post_id, "tmdb_movie_id", true);
        }
        $movie_title = get_the_title($post_id);
        $imdb_id = get_post_meta($post_id, "imdb_id", true);

        // Initialize TMDB handler for direct links
        $tmdb_handler = new Letterboxd_TMDB_Handler();

        $output = '<div class="movie-streaming">';

        // Category labels for display
        $category_labels = [
            "flatrate" => __("Stream", "letterboxd-wordpress"),
            "rent" => __("Rent", "letterboxd-wordpress"),
            "buy" => __("Buy", "letterboxd-wordpress"),
            "free" => __("Free", "letterboxd-wordpress"),
            "ads" => __("Free with Ads", "letterboxd-wordpress"),
        ];

        // Loop through provider categories
        // for now, only show stream, free, or ads
        foreach (
            ["flatrate", "free", "ads" /*, 'rent', 'buy' */]
            as $category
        ) {
            if (!empty($providers[$category])) {
                $output .= sprintf(
                    '<div class="streaming-category %s">
                        <h4 class="category-title">%s</h4>
                        <div class="providers">',
                    esc_attr("category-" . $category),
                    esc_html($category_labels[$category])
                );

                // Loop through providers in this category
                foreach ($providers[$category] as $provider) {
                    //Uncomment to show logos instead of names
                    //Logos are ugly, so I'm leaving them out for now
                    //
                    // if (!empty($provider['logo_path'])) {
                    //     // Create a logo item
                    //     $output .= sprintf(
                    //         '<div class="provider-logo" title="%s">
                    //             <img src="%s" alt="%s" width="50" height="50">
                    //         </div>',
                    //         sprintf(esc_attr__('Available on %s', 'letterboxd-wordpress'), esc_attr($provider['provider_name'])),
                    //         esc_url($provider['logo_path']),
                    //         esc_attr($provider['provider_name'])
                    //     );
                    // } else {
                    $output .= sprintf(
                        '<div class="provider-name">%s</div>',
                        esc_html($provider["provider_name"])
                    );
                    // }closing 'if' for logos
                }

                $output .= "</div></div>";
            }
        }

        $output .= "</div>";

        return $output;
    }

    /**
     * Clear block cache when a movie post is updated
     *
     * @param int $post_id Post ID that was changed
     */
    public function clear_block_cache($post_id = 0): void {
        // Always clear editor data
        wp_cache_delete("editor_data", self::CACHE_GROUP);

        // If a specific post was updated, try to clear only related caches
        if ($post_id > 0 && get_post_type($post_id) === "movie") {
            // Clear any cache with this post ID in the key
            $this->delete_block_cache_pattern("*" . $post_id . "*");

            // For broader changes, also clear recent pages
            $this->delete_block_cache_pattern("*_page_1*");
        } else {
            // If we can't determine what changed, clear all block caches
            $this->delete_block_cache_pattern();
        }
    }

    /**
     * Clear taxonomy cache when a taxonomy term is edited
     */
    public function clear_taxonomy_cache(): void {
        // Clear editor data
        wp_cache_delete("editor_data", self::CACHE_GROUP);

        // Clear first page caches which are most likely to show the change
        $this->delete_block_cache_pattern("*_page_1*");
    }

    /**
     * Delete block cache entries by pattern
     *
     * @param string $pattern Cache key pattern to match
     */
    private function delete_block_cache_pattern(
        string $pattern = "block_*"
    ): void {
        if (wp_using_ext_object_cache()) {
            wp_cache_flush();
        } else {
            global $wpdb;
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                    "_transient_" . self::CACHE_GROUP . "_" . $pattern
                )
            );
        }
    }
}