<?php
/**
 * Handles the import of movies from Letterboxd
 *
 * @package letterboxd-wordpress
 * @since 1.0.0
 */

declare(strict_types=1);

// Prevent direct access
if (!defined("ABSPATH")) {
    exit();
}

class Letterboxd_Importer {
    use LetterboxdValidation;
    use LetterboxdErrorHandling;
    use LetterboxdSecurity;

    private function debug_log($message) {
        letterboxd_debug_log($message, "Importer");
    }

    /**
     * Cache and import constants
     */
    private const CACHE_GROUP = "letterboxd_to_wp_import";
    private const FEED_CACHE_DURATION = 3600; // 1 hour
    private const IMPORT_LOCK_DURATION = 300; // 5 minutes
    private const MAX_FEED_SIZE = 10485760; // 10MB
    private const REQUEST_TIMEOUT = 30; // 30 seconds

    /**
     * Feed URL format
     */
    private const FEED_URL_FORMAT = "https://letterboxd.com/%s/rss/";

    /**
     * Import response structure
     */
    private const IMPORT_RESPONSE = [
        "imported" => 0,
        "status" => "",
        "message" => ""
    ];

    /**
     * @var Letterboxd_Movie_Post_Type
     */
    private Letterboxd_Movie_Post_Type $post_type;

    /**
     * @var Letterboxd_TMDB_Handler
     */
    private ?Letterboxd_TMDB_Handler $tmdb_handler = null;

    /**
     * Class constructor
     *
     * @param Letterboxd_Movie_Post_Type $post_type Movie post type handler
     */
    public function __construct(Letterboxd_Movie_Post_Type $post_type) {
        $this->post_type = $post_type;
        $this->tmdb_handler = new Letterboxd_TMDB_Handler();
    }

    /**
     * Import movies from Letterboxd feed
     *
     * @param array $options Import options
     * @return array Import results
     */
    /**
     * Import movies from Letterboxd feed
     */
    public function import_movies(
        array $options,
        bool $automated = true
    ): array {
        try {
            if (!$this->can_import()) {
                throw new Exception(
                    __(
                        "Another import is currently running.",
                        "letterboxd-wordpress"
                    )
                );
            }

            // Only validate request if not automated
            if (!$automated) {
                $this->validate_import_request($options);
            }

            $this->set_import_lock();

            $username =
                $options["username"] ??
                (get_option("letterboxd_wordpress_options")["username"] ?? "");
            if (empty($username)) {
                throw new Exception(
                    __(
                        "No Letterboxd username configured.",
                        "letterboxd-wordpress"
                    )
                );
            }

            $feed_items = $this->fetch_feed($username);
            letterboxd_debug_log(
                "Fetched " . count($feed_items) . " items from feed"
            );

            $result = $this->process_feed_items($feed_items, $options);

            update_option("letterboxd_last_import", time());

            $this->clear_import_lock();
            return $result;
        } catch (Exception $e) {
            letterboxd_debug_log("Import error: " . $e->getMessage());
            $this->handle_import_error($e, $options);
            return $this->get_error_response($e->getMessage());
        }
    }

    /**
     * Set import lock
     */
    private function set_import_lock(): void {
        wp_cache_set(
            "import_lock",
            time(),
            self::CACHE_GROUP,
            self::IMPORT_LOCK_DURATION
        );
    }

    /**
     * Check if import can run
     */
    private function can_import(): bool {
        $lock_time = wp_cache_get("import_lock", self::CACHE_GROUP);
        if (!$lock_time) {
            return true;
        }
        // Clear stale lock after 5 minutes
        if (time() - $lock_time > 300) {
            wp_cache_delete("import_lock", self::CACHE_GROUP);
            return true;
        }
        return false;
    }

    /**
     * Clear import lock
     */
    private function clear_import_lock(): void {
        wp_cache_delete("import_lock", self::CACHE_GROUP);
    }

    /**
     * Validate import request and options
     */
    private function validate_import_request(array $options): void {
        if (
            !$this->verify_request(
                "letterboxd_import_nonce",
                "letterboxd_import_action"
            )
        ) {
            throw new Exception(
                __("Security check failed", "letterboxd-wordpress")
            );
        }

        $username_validation = $this->validate_letterboxd_username(
            $options["username"]
        );
        if (is_wp_error($username_validation)) {
            throw new Exception($username_validation->get_error_message());
        }

        if (!empty($options["start_date"])) {
            $date_validation = $this->validate_date($options["start_date"]);
            if (is_wp_error($date_validation)) {
                throw new Exception($date_validation->get_error_message());
            }
        }
    }

    /**
     * Fetch and parse Letterboxd RSS feed based on username
     */
    private function fetch_feed(string $username): array {
        $cache_key = "feed_" . md5($username);
        $feed_items = wp_cache_get($cache_key, self::CACHE_GROUP);

        if ($feed_items === false) {
            $feed_url = sprintf(self::FEED_URL_FORMAT, urlencode($username));
            $response = $this->fetch_feed_content($feed_url);
            $feed_items = $this->parse_feed_content($response);

            wp_cache_set(
                $cache_key,
                $feed_items,
                self::CACHE_GROUP,
                self::FEED_CACHE_DURATION
            );
        }

        return $feed_items;
    }

    /**
     * Fetch feed content of user's Letterboxd feed with error handling
     */
    private function fetch_feed_content(string $url): string {
        letterboxd_debug_log("Attempting to fetch feed from: " . $url);
        $response = wp_remote_get($url, [
            "timeout" => self::REQUEST_TIMEOUT,
            "user-agent" => "WordPress/Letterboxd-Importer-Plugin",
            "headers" => ["Accept" => "application/rss+xml"],
            "sslverify" => true
        ]);

        if (is_wp_error($response)) {
            throw new Exception(
                sprintf(
                    __(
                        "Failed to fetch Letterboxd feed: %s",
                        "letterboxd-wordpress"
                    ),
                    $response->get_error_message()
                )
            );
        }

        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code !== 200) {
            throw new Exception(
                sprintf(
                    __(
                        "Failed to fetch Letterboxd feed: HTTP %d",
                        "letterboxd-wordpress"
                    ),
                    $status_code
                )
            );
        }

        $content = wp_remote_retrieve_body($response);

        letterboxd_debug_log(
            "Fetched feed content: " . substr($content, 0, 500)
        );

        if (empty($content)) {
            throw new Exception(
                __("Empty feed content received", "letterboxd-wordpress")
            );
        }

        if (strlen($content) > self::MAX_FEED_SIZE) {
            throw new Exception(
                __(
                    "Feed content exceeds maximum size limit",
                    "letterboxd-wordpress"
                )
            );
        }

        return $content;
    }

    /**
     * Parse feed content using XMLReader for memory efficiency
     */
    private function parse_feed_content(string $content): array {
        $debug = defined("WP_DEBUG") && WP_DEBUG;
        if ($debug) {
            letterboxd_debug_log("Starting feed parse");
        }
        $reader = new XMLReader();
        try {
            if (
                !$reader->XML(
                    $content,
                    "UTF-8",
                    LIBXML_NOERROR | LIBXML_NOWARNING
                )
            ) {
                if ($debug) {
                    letterboxd_debug_log("Failed to parse XML content");
                }
                throw new Exception("Failed to parse feed content");
            }
            $items = [];
            $current_item = null;
            $current_tag = "";

            while ($reader->read()) {
                if ($reader->nodeType === XMLReader::ELEMENT) {
                    if ($reader->name === "item") {
                        letterboxd_debug_log("Found new item element");
                        $current_item = [
                            "title" => "",
                            "link" => "",
                            "pubDate" => "",
                            "description" => "",
                            "filmYear" => "",
                            "poster_url" => ""
                        ];
                    } elseif ($current_item !== null) {
                        $current_tag = $reader->name;
                        if ($current_tag === "letterboxd:filmYear") {
                            $current_item["filmYear"] = $reader->readString();
                            continue;
                        } elseif ($current_tag === "tmdb:movieId") {
                            //check feed for a movieID to ensure that we're only pulling over movies and not lists
                            $current_item[
                                "tmdb_movieId"
                            ] = $reader->readString();
                            continue;
                        }
                    }
                } elseif (
                    ($reader->nodeType === XMLReader::TEXT ||
                        $reader->nodeType === XMLReader::CDATA) &&
                    $current_item !== null
                ) {
                    $this->update_current_item(
                        $current_item,
                        $current_tag,
                        $reader->value
                    );
                } elseif (
                    $reader->nodeType === XMLReader::END_ELEMENT &&
                    $reader->name === "item"
                ) {
                    letterboxd_debug_log(
                        "Completed item: " .
                            ($current_item["title"] ?? "unknown")
                    );

                    // Extract poster URL from description
                    if (!empty($current_item["description"])) {
                        letterboxd_debug_log(
                            "Description content: " .
                                $current_item["description"]
                        );

                        // Updated regex pattern to match Letterboxd's image structure
                        if (
                            preg_match(
                                '/<img[^>]+src=[\'"]([^\'"]+\.(?:jpg|jpeg|png|gif)(?:\?[^\'"]*)?)[\'"][^>]*>/i',
                                $current_item["description"],
                                $matches
                            )
                        ) {
                            $current_item["poster_url"] = $matches[1];
                            letterboxd_debug_log(
                                "Found poster URL: " .
                                    $current_item["poster_url"]
                            );
                        } else {
                            letterboxd_debug_log(
                                "No poster URL found with pattern match"
                            );
                            letterboxd_debug_log(
                                "Description content: " .
                                    $current_item["description"]
                            );
                        }
                    }

                    //letterboxd_debug_log('Completed item: ' . print_r($current_item, true));

                    // Exclude items that do not have a <tmdb:movieId>
                    if (empty($current_item["tmdb_movieId"])) {
                        letterboxd_debug_log(
                            "Excluding item (Missing tmdb:movieId): " .
                                $current_item["title"]
                        );
                        $current_item = null;
                        continue;
                    }

                    $items[] = $current_item;
                    $current_item = null;
                }
            }
            return $items;
        } catch (Exception $e) {
            letterboxd_debug_log("Feed parsing error: " . $e->getMessage());
            throw $e;
        } finally {
            $reader->close();
        }
    }

    /**
     * Update current item data
     */
    private function update_current_item(
        array &$item,
        string $tag,
        string $value
    ): void {
        // Create a static property for allowed tags
        static $allowed_tags = [
            "title" => true,
            "link" => true,
            "pubDate" => true,
            "description" => true,
            "letterboxd:filmYear" => true
        ];
        if (isset($allowed_tags[$tag])) {
            $value = trim($value);
            if (substr($value, 0, 9) === "<![CDATA[") {
                $value = substr($value, 9, -3);
            }
            if ($tag === "letterboxd:filmYear") {
                $item["filmYear"] = $value;
            } else {
                $item[$tag] = isset($item[$tag])
                    ? $item[$tag] . $value
                    : $value;
            }
        }
    }

    /**
     * Process feed items
     */
    private function process_feed_items(
        array $feed_items,
        array $options
    ): array {
        $start_date = !empty($options["start_date"])
            ? strtotime($options["start_date"])
            : 0;
        $imported = 0;

        foreach ($feed_items as $item) {
            $pub_date = strtotime($item["pubDate"]);
            if ($start_date && $pub_date < $start_date) {
                continue;
            }

            if ($this->import_movie($item, $options)) {
                $imported++;
            }
        }

        return [
            "imported" => $imported,
            "status" => "success",
            "message" => sprintf(
                __(
                    "Successfully imported %d new movies.",
                    "letterboxd-wordpress"
                ),
                $imported
            )
        ];
    }

    /**
     * Import individual movie.
     */
    private function import_movie(array $item, array $options): bool {
        if ($this->post_type->movie_exists($item["link"])) {
            return false;
        }

        $movie_data = $this->prepare_movie_data($item, $options);
        $post_id = wp_insert_post($movie_data);

        if (!is_wp_error($post_id) && $post_id > 0) {
            // Ensure the $item array contains the parsed rating for consistency.
            $parsed = $this->parse_movie_title_and_rating($item["title"]);
            $item["rating"] = $parsed["rating"];

            $this->set_movie_meta($post_id, $item);
            $this->set_movie_terms($post_id, $item);

            // Add TMDB data enrichment here
            if (!empty($item["tmdb_movieId"])) {
                $this->enrich_with_tmdb_data($post_id, $item["tmdb_movieId"]);
            }

            return true;
        }

        return false;
    }

    /**
     * Parse the movie title string into its components using named capture groups.
     * Optimized for better performance and reliability.
     *
     * @param string $title The raw title string from the feed.
     * @return array{title: string, year: string, rating: string} Structured movie data
     */
    private function parse_movie_title_and_rating(string $title): array {
        // Use named capture groups and a stricter year pattern
        $pattern =
            '/^(?P<title>.*),\s*(?P<year>\d{4})(?:\s*-\s*(?P<rating>.*))?$/u';

        if (preg_match($pattern, $title, $matches)) {
            // Use array_filter to remove empty matches while preserving keys
            $matches = array_filter(
                $matches,
                "is_string",
                ARRAY_FILTER_USE_KEY
            );

            return [
                "title" => trim($matches["title"] ?? ""),
                "year" => trim($matches["year"] ?? ""),
                "rating" => isset($matches["rating"])
                    ? trim($matches["rating"])
                    : ""
            ];
        }

        // Log parsing failure for monitoring
        letterboxd_debug_log(sprintf("Movie title parsing failed: %s", $title));

        return [
            "title" => $title,
            "year" => "",
            "rating" => ""
        ];
    }

    /**
     * Extracted images from the feed content.
     *
     * @var array
     */
    private array $extracted_images = [];

    /**
     * Convert movie description to Gutenberg paragraph blocks and extract images.
     *
     * @param string $content Raw HTML content from Letterboxd.
     * @return string Content formatted as Gutenberg paragraph blocks.
     */
    private function convert_to_blocks(string $content): string {
        $blocks = [];
        // Clear any previously extracted images.
        $this->extracted_images = [];

        // Use regex to extract all <p>...</p> blocks.
        if (preg_match_all("/<p\b[^>]*>.*?<\/p>/is", $content, $matches)) {
            foreach ($matches[0] as $pBlock) {
                // Check if the <p> block contains an <img> tag.
                if (strpos($pBlock, "<img") !== false) {
                    // Extract all image URLs from this paragraph.
                    if (
                        preg_match_all(
                            '/<img[^>]+src=[\'"]([^\'"]+)[\'"][^>]*>/i',
                            $pBlock,
                            $imgMatches
                        )
                    ) {
                        foreach ($imgMatches[1] as $imgUrl) {
                            $this->extracted_images[] = esc_url($imgUrl);
                        }
                    }
                    // Skip this paragraph entirely.
                    continue;
                }

                // Otherwise, preserve the paragraph as is.
                $blocks[] = "<!-- wp:paragraph -->";
                $blocks[] = $pBlock;
                $blocks[] = "<!-- /wp:paragraph -->";
            }
        } else {
            // If no <p> tags are found, fall back to a simple wrap.
            $blocks[] = "<!-- wp:paragraph -->";
            $blocks[] = "<p>" . wp_kses_post($content) . "</p>";
            $blocks[] = "<!-- /wp:paragraph -->";
        }

        return implode("\n", $blocks);
    }

    /**
     * Prepare movie post data with Gutenberg block support
     *
     * @param array $item Raw movie data
     * @param array $options Processing options
     * @return array Prepared post data
     */
    private function prepare_movie_data(array $item, array $options): array {
        // Early validation of required fields
        if (empty($item["title"]) || empty($item["description"])) {
            throw new Exception(
                __("Missing required movie data fields", "letterboxd-wordpress")
            );
        }

        // Parse title components once and cache result
        $parsed = $this->parse_movie_title_and_rating($item["title"]);

        // Convert description to blocks
        $block_content = $this->convert_to_blocks($item["description"]);

        // Prepare all meta data
        $meta_input = [
            "letterboxd_url" => $item["link"] ?? "",
            "watch_date" => !empty($item["pubDate"])
                ? date("Y-m-d", strtotime($item["pubDate"]))
                : "",
            "poster_url" => $item["poster_url"] ?? "",
            "movie_rating" => $parsed["rating"],
            "movie_year" => $parsed["year"],
            "tmdb_movie_id" => $item["tmdb_movieId"] ?? ""
        ];

        // Return complete post data including meta_input
        return [
            "post_title" => sanitize_text_field($parsed["title"]),
            "post_content" => $block_content,
            "post_status" => !empty($options["draft_status"])
                ? "draft"
                : "publish",
            "post_type" => "movie",
            "meta_input" => array_filter($meta_input) // Remove empty values
        ];
    }

    /**
     * Set movie meta data using optimized bulk operations.
     *
     * @param int $post_id Post ID.
     * @param array $item Movie data.
     */
    private function set_movie_meta(int $post_id, array $item): void {
        try {
            // Validate post exists.
            if (!get_post($post_id)) {
                throw new Exception(sprintf("Invalid post ID: %d", $post_id));
            }

            $poster_url = $item["poster_url"] ?? "";

            // Use wp_update_post for bulk meta update.
            $update_result = wp_update_post(
                [
                    "ID" => $post_id,
                    "meta_input" => [
                        "letterboxd_url" => $item["link"] ?? "",
                        "watch_date" => !empty($item["pubDate"])
                            ? date("Y-m-d", strtotime($item["pubDate"]))
                            : "",
                        "poster_url" => $poster_url,
                        "movie_rating" => $item["rating"] ?? "",
                        "tmdb_movie_id" => $item["tmdb_movieId"] ?? "" // Added TMDB movie ID
                    ]
                ],
                true
            ); // Return WP_Error on failure.

            if (is_wp_error($update_result)) {
                throw new Exception($update_result->get_error_message());
            }

            // Handle poster import if URL exists and no featured image is set.
            if (!empty($poster_url) && !has_post_thumbnail($post_id)) {
                $this->handle_poster_import($post_id, $poster_url);
            }
        } catch (Exception $e) {
            letterboxd_debug_log(
                sprintf(
                    "Error setting movie meta for post %d: %s",
                    $post_id,
                    $e->getMessage()
                )
            );
            // Let the calling function handle the error based on context.
            throw $e;
        }
    }

    /**
     * Handling image import and featured image setting using media_handle_sideload()
     */
    private function handle_poster_import(
        int $post_id,
        string $poster_url
    ): void {
        // Check if the post exists
        if (!get_post($post_id)) {
            letterboxd_debug_log(
                "Cannot import poster: Invalid post ID " . $post_id
            );
            return;
        }

        // Include required WordPress files for media handling.
        require_once ABSPATH . "wp-admin/includes/file.php";
        require_once ABSPATH . "wp-admin/includes/media.php";
        require_once ABSPATH . "wp-admin/includes/image.php";

        // Download the remote image to a temporary file.
        $tmp_file = download_url($poster_url, 30); // 30 seconds timeout.
        if (is_wp_error($tmp_file)) {
            letterboxd_debug_log(
                "Failed to download poster: " . $tmp_file->get_error_message()
            );
            return;
        }

        // Prepare a file array similar to a $_FILES entry.
        $file_array = [];
        // Extract a filename from the URL. Fallback to a default if needed.
        $parsed_url = parse_url($poster_url, PHP_URL_PATH);
        $file_array["name"] = basename($parsed_url);
        if (empty($file_array["name"])) {
            $file_array["name"] = "poster.jpg";
        }
        $file_array["tmp_name"] = $tmp_file;

        // Get movie title and watch date for metadata.
        $movie_title = get_the_title($post_id);
        $watch_date = get_post_meta($post_id, "watch_date", true);

        // Sideload the image and attach it to the post.
        $attach_id = media_handle_sideload($file_array, $post_id, $movie_title);
        if (is_wp_error($attach_id)) {
            letterboxd_debug_log(
                "Failed to sideload image: " . $attach_id->get_error_message()
            );
            // Cleanup temporary file if needed.
            @unlink($file_array["tmp_name"]);
            return;
        }

        // Build an appropriate alt text.
        if (!empty($watch_date) && strtotime($watch_date)) {
            $formatted_date = date_i18n(
                get_option("date_format"),
                strtotime($watch_date)
            );
            $alt_text = sprintf(
                "I watched %s on %s",
                $movie_title,
                $formatted_date
            );
        } else {
            $alt_text = sprintf("I watched %s", $movie_title);
        }
        update_post_meta($attach_id, "_wp_attachment_image_alt", $alt_text);

        // Generate and update attachment metadata.
        $file_path = get_attached_file($attach_id);
        $attach_data = wp_generate_attachment_metadata($attach_id, $file_path);
        if (is_wp_error($attach_data)) {
            letterboxd_debug_log(
                "Failed to generate attachment metadata: " .
                    $attach_data->get_error_message()
            );
            return;
        }

        $update_result = wp_update_attachment_metadata(
            $attach_id,
            $attach_data
        );
        if (is_wp_error($update_result)) {
            letterboxd_debug_log(
                "Failed to update attachment metadata: " .
                    $update_result->get_error_message()
            );
        }

        // Set the sideloaded image as the featured image.
        if (!set_post_thumbnail($post_id, $attach_id)) {
            letterboxd_debug_log(
                "Failed to set featured image for post ID: " . $post_id
            );
        }
    }

    /**
     * Set movie terms
     */
    private function set_movie_terms(int $post_id, array $item): void {
        if (!empty($item["filmYear"])) {
            $year = sanitize_text_field($item["filmYear"]);
            $result = wp_set_object_terms($post_id, $year, "movie_year");
        }
    }

    private function enrich_with_tmdb_data(
        int $post_id,
        string $tmdb_movie_id
    ): void {
        // Skip if TMDB API is not configured
        if (!$this->tmdb_handler->is_api_key_configured()) {
            letterboxd_debug_log(
                "TMDB enrichment skipped: API key not configured."
            );
            return;
        }

        // Skip if movie ID is missing or invalid
        if (empty($tmdb_movie_id) || !is_numeric($tmdb_movie_id)) {
            letterboxd_debug_log(
                "TMDB enrichment skipped: Invalid movie ID: " . $tmdb_movie_id
            );
            return;
        }

        $movie_id = intval($tmdb_movie_id);

        // Check if we already have complete TMDB data for this post
        $has_tmdb_data = get_post_meta($post_id, "director", true);
        if (!empty($has_tmdb_data)) {
            letterboxd_debug_log(
                "TMDB enrichment skipped: Movie already has TMDB data"
            );
            return;
        }

        try {
            // Fetch movie details from TMDB
            $movie_data = $this->tmdb_handler->get_movie_details($movie_id);

            if (is_wp_error($movie_data)) {
                letterboxd_debug_log(
                    "TMDB API error: " . $movie_data->get_error_message()
                );
                return;
            }

            // Extract and save metadata
            $metadata = $this->tmdb_handler->extract_movie_metadata(
                $movie_data
            );

            foreach ($metadata as $meta_key => $meta_value) {
                if (!empty($meta_value)) {
                    update_post_meta($post_id, $meta_key, $meta_value);
                }
            }

            letterboxd_debug_log(
                "TMDB enrichment complete for movie: " . get_the_title($post_id)
            );
        } catch (Exception $e) {
            letterboxd_debug_log("TMDB enrichment error: " . $e->getMessage());
        }
    }

    /**
     * Handle import error
     */
    private function handle_import_error(Exception $e, array $options): void {
        $this->clear_import_lock();
        $this->log_error("Import failed: " . $e->getMessage(), "error", [
            "username" => $options["username"] ?? "",
            "exception" => $e->getMessage()
        ]);
    }

    /**
     * Get error response
     */
    private function get_error_response(string $message): array {
        return array_merge(self::IMPORT_RESPONSE, [
            "status" => "error",
            "message" => $message
        ]);
    }
}