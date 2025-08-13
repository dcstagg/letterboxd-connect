<?php
/**
 * Handles the import of movies from Letterboxd
 *
 * @package letterboxd-connect
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
                        "letterboxd-connect"
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
                        "letterboxd-connect"
                    )
                );
            }

            $feed_items = $this->fetch_feed($username);
            // letterboxd_debug_log( "Fetched " . count($feed_items) . " items from feed" );

            $result = $this->process_feed_items($feed_items, $options);

            update_option("letterboxd_last_import", time());

            $this->clear_import_lock();
            return $result;
        } catch (Exception $e) {
            // letterboxd_debug_log("Import error: " . $e->getMessage());
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
                esc_html__("Security check failed", "letterboxd-connect")
            );
        }

        $username_validation = $this->validate_letterboxd_username(
            $options["username"]
        );
        if (is_wp_error($username_validation)) {
            throw new Exception(esc_html($username_validation->get_error_message()));
        }

        if (!empty($options["start_date"])) {
            $date_validation = $this->validate_date($options["start_date"]);
            if (is_wp_error($date_validation)) {
                throw new Exception(esc_html($date_validation->get_error_message()));
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
        // letterboxd_debug_log("Attempting to fetch feed from: " . $url);
        $response = wp_remote_get($url, [
            "timeout" => self::REQUEST_TIMEOUT,
            "user-agent" => "WordPress/Letterboxd-Importer-Plugin",
            "headers" => ["Accept" => "application/rss+xml"],
            "sslverify" => true
        ]);

        if (is_wp_error($response)) {
            throw new Exception(
                sprintf(
                    // translators: %s: Error message
                    esc_html__(
                        "Failed to fetch Letterboxd feed: %s",
                        "letterboxd-connect"
                    ),
                    esc_html($response->get_error_message())
                )
            );
        }

        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code !== 200) {
            throw new Exception(
                sprintf(
                    // translators: %d: HTTP status code
                    esc_html__(
                        "Failed to fetch Letterboxd feed: HTTP %d",
                        "letterboxd-connect"
                    ),
                    intval($status_code)
                )
            );
        }

        $content = wp_remote_retrieve_body($response);

        // letterboxd_debug_log( "Fetched feed content: " . substr($content, 0, 500) );

        if (empty($content)) {
            throw new Exception(
                esc_html__("Empty feed content received", "letterboxd-connect")
            );
        }

        if (strlen($content) > self::MAX_FEED_SIZE) {
            throw new Exception(
                esc_html__(
                    "Feed content exceeds maximum size limit",
                    "letterboxd-connect"
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
        // if ($debug) { letterboxd_debug_log("Starting feed parse"); }
        $reader = new XMLReader();
        try {
            if (
                !$reader->XML(
                    $content,
                    "UTF-8",
                    LIBXML_NOERROR | LIBXML_NOWARNING
                )
            ) {
                // if ($debug) { letterboxd_debug_log("Failed to parse XML content"); }
                throw new Exception("Failed to parse feed content");
            }
            $items = [];
            $current_item = null;
            $current_tag = "";

            while ($reader->read()) {
                if ($reader->nodeType === XMLReader::ELEMENT) {
                    if ($reader->name === "item") {
                        // letterboxd_debug_log("Found new item element");
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
                    // letterboxd_debug_log( "Completed item: " . ($current_item["title"] ?? "unknown") );

                    // Extract poster URL from description
                    if (!empty($current_item["description"])) {
                        // letterboxd_debug_log( "Description content: " . $current_item["description"] );

                        // Updated regex pattern to match Letterboxd's image structure
                        if (
                            preg_match(
                                '/<img[^>]+src=[\'"]([^\'"]+\.(?:jpg|jpeg|png|gif)(?:\?[^\'"]*)?)[\'"][^>]*>/i',
                                $current_item["description"],
                                $matches
                            )
                        ) {
                            $current_item["poster_url"] = $matches[1];
                            // letterboxd_debug_log( "Found poster URL: " . $current_item["poster_url"] );
                        } else {
                            // letterboxd_debug_log( "No poster URL found with pattern match" );
                            // letterboxd_debug_log( "Description content: " . $current_item["description"] );
                        }
                    }

                    //letterboxd_debug_log('Completed item: ' . print_r($current_item, true));

                    // Exclude items that do not have a <tmdb:movieId>
                    if (empty($current_item["tmdb_movieId"])) {
                        // letterboxd_debug_log( "Excluding item (Missing tmdb:movieId): " . $current_item["title"] );
                        $current_item = null;
                        continue;
                    }

                    $items[] = $current_item;
                    $current_item = null;
                }
            }
            return $items;
        } catch (Exception $e) {
            // letterboxd_debug_log("Feed parsing error: " . $e->getMessage());
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
                /* translators: %d: Number of imported movies */
                __(
                    "Successfully imported %d new movies.",
                    "letterboxd-connect"
                ),
                $imported
            )
        ];
    }

    /**
     * Import or update a single movie from Letterboxd feed.
     *
     * @param array $item    Parsed feed item (with 'link', 'pubDate', etc.).
     * @param array $options Import options (username, draft_status, etc.).
     * @return bool          True if a new post was created, false otherwise.
     */
    private function import_movie(array $item, array $options): bool {
        // Try to find an existing post by its Letterboxd URL (stored in meta `letterboxd_url`)
        $link = trim($item['link'] ?? '');
        if ($link === '') {
            // Build a stable fallback for CSV rows that lack a Letterboxd URI
            $title   = (string)($item['title'] ?? '');
            $pubDate = (string)($item['pubDate'] ?? '');
            $link = 'csv://' . md5($title . '|' . $pubDate);
            $item['link'] = $link;
        }
        
        $existing = $this->post_type->get_movie_by_letterboxd_url($link);
    
        // Prepare all the data for wp_insert_post / wp_update_post
        $movie_data = $this->prepare_movie_data($item, $options);
    
        if ($existing) {
            // We have an existing post—see what actually changed
            $updates      = [ 'ID' => $existing->ID ];
            $needs_update = false;
    
            // 1) Post date
            if (
                ! empty( $movie_data['post_date'] )
                && $existing->post_date !== $movie_data['post_date']
            ) {
                $updates['post_date']     = $movie_data['post_date'];
                $updates['post_date_gmt'] = $movie_data['post_date_gmt'];
                $needs_update            = true;
            }
    
            // 2) Content
            if ( $existing->post_content !== $movie_data['post_content'] ) {
                $updates['post_content'] = $movie_data['post_content'];
                $needs_update           = true;
            }
    
            // 3) Status (draft vs publish)
            if ( $existing->post_status !== $movie_data['post_status'] ) {
                $updates['post_status'] = $movie_data['post_status'];
                $needs_update          = true;
            }
    
            // If anything changed, update the post
            if ( $needs_update ) {
                wp_update_post( $updates );
            }
    
            // 4) Always refresh meta & terms so watchedDate, rating, genres, etc. stay in sync
            $this->set_movie_meta( $existing->ID, $item );
            $this->set_movie_terms( $existing->ID, $item );
    
            // Return false to signal “no new post created”
            return false;
        }
    
        // No existing post—insert a brand-new one
        $post_id = wp_insert_post( $movie_data );
        if ( is_wp_error( $post_id ) || $post_id <= 0 ) {
            return false;
        }
    
        // Save meta and terms, and enrich with TMDB if available
        $this->set_movie_meta( $post_id, $item );
        $this->set_movie_terms( $post_id, $item );
        if ( ! empty( $item['tmdb_movieId'] ) ) {
            $this->enrich_with_tmdb_data( $post_id, $item['tmdb_movieId'] );
        }
    
        return true;
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
        // letterboxd_debug_log(sprintf("Movie title parsing failed: %s", $title));

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
        // Require a title, but allow empty description (common in CSV imports)
        if (empty($item["title"])) {
            throw new Exception(
                esc_html__("Missing required movie title", "letterboxd-connect")
            );
        }
    
        // Parse title components once and cache result
        $parsed = $this->parse_movie_title_and_rating($item["title"]);
    
        // Convert description to blocks
        $block_content = $this->convert_to_blocks((string)($item["description"] ?? ''));
    
        // Prepare all meta data
        $meta_input = [
            "letterboxd_url" => $item["link"] ?? "",
            "watch_date"     => !empty($item["pubDate"]) ? gmdate("Y-m-d", strtotime($item["pubDate"])) : "",
            "poster_url"     => $item["poster_url"] ?? "",
            "movie_rating"   => $parsed["rating"],
            "movie_year"     => $parsed["year"],
            "tmdb_movie_id"  => $item["tmdb_movieId"] ?? "",
            "letterboxd_key" => $this->normalize_letterboxd_key($item["link"] ?? '', $parsed['title'], $parsed['year']),
        ];
    
        return [
            'post_title'     => sanitize_text_field( $parsed['title'] ),
            'post_content'   => $block_content,
            'post_status'    => ! empty( $options['draft_status'] ) ? 'draft' : 'publish',
            'post_type'      => 'movie',
            'post_date'      => gmdate( 'Y-m-d H:i:s', strtotime( $item['pubDate'] ) ),
            'post_date_gmt'  => gmdate( 'Y-m-d H:i:s', strtotime( $item['pubDate'] ) ),
            'meta_input'     => array_filter( $meta_input ),
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
                            ? gmdate("Y-m-d", strtotime($item["pubDate"]))
                            : "",
                        "poster_url" => $poster_url,
                        "movie_rating" => $item["rating"] ?? "",
                        "tmdb_movie_id" => $item["tmdb_movieId"] ?? "",
                        "letterboxd_key" => $this->normalize_letterboxd_key($item["link"] ?? '', $this->parse_movie_title_and_rating((string)($item['title'] ?? ''))['title'] ?? '', $item['filmYear'] ?? ''),

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
            // letterboxd_debug_log(
            //     sprintf(
            //         "Error setting movie meta for post %d: %s",
            //         $post_id,
            //         $e->getMessage()
            //     )
            // );
            // Let the calling function handle the error based on context.
            throw $e;
        }
    }
    
    /**
     * Build a stable, comparable key for a movie.
     * - If a URL is present:
     *     - boxd.it/<code> → "boxd:<code>"
     *     - letterboxd.com/.../film/<slug>/ → "film:<slug>"
     *     - otherwise host+path (lowercased, no trailing slash)
     * - If URL is missing, fallback to title+year → "ty:<title>|<year>"
     */
    private function normalize_letterboxd_key(string $uri = '', string $title = '', string $year = ''): string {
        $uri = trim((string)$uri);
    
        // Prefer URL-based keys when possible
        if ($uri !== '' && filter_var($uri, FILTER_VALIDATE_URL)) {
            $parts = wp_parse_url($uri);
            $host  = strtolower((string)($parts['host'] ?? ''));
            $host  = preg_replace('/^www\./', '', $host);
            $path  = strtolower((string)($parts['path'] ?? ''));
            $path  = rtrim($path, '/');
    
            if ($host === 'boxd.it') {
                $code = trim($path, '/');
                if ($code !== '') {
                    return 'boxd:' . $code;
                }
            }
    
            if ($host === 'letterboxd.com') {
                if (preg_match('#/film/([^/]+)/?#', $path, $m)) {
                    return 'film:' . $m[1];
                }
            }
    
            // Generic fallback: host + path
            if ($host !== '') {
                return $host . $path;
            }
        }
    
        // Fallback: Title + Year
        $t = strtolower(trim((string)$title));
        $y = preg_replace('/\D+/', '', (string)$year);
        if ($t !== '' && $y !== '') {
            return 'ty:' . $t . '|' . $y;
        }
    
        return '';
    }
    
    /**
     * Find an existing "movie" post by a Letterboxd link.
     * Robust across:
     *  - short links like https://boxd.it/abcd
     *  - long links like https://letterboxd.com/.../film/fight-club/
     *  - minor URL variants (http/https, with/without www, trailing slash)
     * Also backfills `letterboxd_key` meta on the matched post for faster future lookups.
     *
     * @return WP_Post|null
     */
    private function find_existing_movie_by_link(string $link): ?WP_Post {
        global $wpdb;
    
        $link = trim((string)$link);
        if ($link === '' || !filter_var($link, FILTER_VALIDATE_URL)) {
            return null;
        }
    
        // 1) Try the normalized key first (fastest once posts have letterboxd_key)
        $normKey = $this->normalize_letterboxd_key($link);
        if ($normKey !== '') {
            $post_id = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "
                    SELECT p.ID
                    FROM {$wpdb->postmeta} pm
                    INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                    WHERE pm.meta_key = 'letterboxd_key'
                      AND pm.meta_value = %s
                      AND p.post_type = 'movie'
                      AND p.post_status IN ('publish','draft','pending','future','private')
                    LIMIT 1
                    ",
                    $normKey
                )
            );
            if ($post_id > 0) {
                return get_post($post_id) ?: null;
            }
        }
    
        // Build a few URL candidates (exact matches with/without www and trailing slash)
        $parts = wp_parse_url($link);
        $host  = strtolower((string)($parts['host'] ?? ''));
        $host  = preg_replace('/^www\./', '', $host);
        $path  = (string)($parts['path'] ?? '');
        $path  = '/' . ltrim($path, '/');          // ensure leading slash
        $pathNoSlash = rtrim($path, '/');
        $scheme = 'https://';
    
        $candidates = [];
        // original
        $candidates[] = $link;
        // no trailing slash / with trailing slash
        $candidates[] = $scheme . $host . $pathNoSlash;
        $candidates[] = $scheme . 'www.' . $host . $pathNoSlash;
        $candidates[] = $scheme . $host . $pathNoSlash . '/';
        $candidates[] = $scheme . 'www.' . $host . $pathNoSlash . '/';
    
        // 2) Exact match on letterboxd_url (covers old posts created before we had letterboxd_key)
        $placeholders = implode(',', array_fill(0, count($candidates), '%s'));
        $post_id = (int) $wpdb->get_var(
            $wpdb->prepare(
                "
                SELECT p.ID
                FROM {$wpdb->postmeta} pm
                INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                WHERE pm.meta_key = 'letterboxd_url'
                  AND pm.meta_value IN ($placeholders)
                  AND p.post_type = 'movie'
                  AND p.post_status IN ('publish','draft','pending','future','private')
                LIMIT 1
                ",
                ...$candidates
            )
        );
        if ($post_id > 0) {
            // Backfill the normalized key for speed next time.
            if ($normKey !== '' && !metadata_exists('post', $post_id, 'letterboxd_key')) {
                update_post_meta($post_id, 'letterboxd_key', $normKey);
            }
            return get_post($post_id) ?: null;
        }
    
        // 3) If this is a film page, try a LIKE match on the slug
        //    (helps when DB only stored the long URL variant with extra path bits)
        $slug = '';
        if ($host === 'letterboxd.com' && preg_match('#/film/([^/]+)/?#i', strtolower($path), $m)) {
            $slug = $m[1];
        }
        if ($slug !== '') {
            $like = '%' . $wpdb->esc_like('/film/' . $slug . '/') . '%';
            $post_id = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "
                    SELECT p.ID
                    FROM {$wpdb->postmeta} pm
                    INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                    WHERE pm.meta_key = 'letterboxd_url'
                      AND pm.meta_value LIKE %s
                      AND p.post_type = 'movie'
                      AND p.post_status IN ('publish','draft','pending','future','private')
                    LIMIT 1
                    ",
                    $like
                )
            );
            if ($post_id > 0) {
                if ($normKey !== '' && !metadata_exists('post', $post_id, 'letterboxd_key')) {
                    update_post_meta($post_id, 'letterboxd_key', $normKey);
                }
                return get_post($post_id) ?: null;
            }
        }
    
        // 4) Nothing found
        return null;
    }

    /**
     * Prefetch comparable keys to dedupe fast in memory.
     * Returns:
     *  - ['url_keys' => ['film:slug' => true, 'boxd:abcd' => true, ...],
     *     'title_year_keys' => ['ty:title|year' => true, ...]]
     */
    private function prefetch_existing_keys(): array {
        global $wpdb;
    
        // 1) URL-based keys from meta 'letterboxd_url'
        $sql = "
            SELECT pm.meta_value
            FROM {$wpdb->postmeta} pm
            INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
            WHERE pm.meta_key = 'letterboxd_url'
              AND p.post_type = 'movie'
              AND p.post_status IN ('publish','draft','pending','future','private')
        ";
        $rows = (array) $wpdb->get_col($sql);
        $url_keys = [];
        foreach ($rows as $raw) {
            $key = $this->normalize_letterboxd_key((string)$raw);
            if ($key !== '') { $url_keys[$key] = true; }
        }
    
        // 2) Title+Year keys from post_title + meta 'movie_year'
        $sql2 = "
            SELECT LOWER(p.post_title) AS t, pm.meta_value AS y
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm
                   ON pm.post_id = p.ID AND pm.meta_key = 'movie_year'
            WHERE p.post_type = 'movie'
              AND p.post_status IN ('publish','draft','pending','future','private')
        ";
        $rows2 = (array) $wpdb->get_results($sql2, ARRAY_A);
        $ty_keys = [];
        foreach ($rows2 as $r) {
            $t = trim((string)($r['t'] ?? ''));
            $y = preg_replace('/\D+/', '', (string)($r['y'] ?? ''));
            if ($t !== '' && $y !== '') {
                $ty_keys['ty:' . $t . '|' . $y] = true;
            }
        }
    
        return [
            'url_keys'        => $url_keys,
            'title_year_keys' => $ty_keys,
        ];
    }
    
    /**
     * Import all watched/diary movies from a Letterboxd CSV (or ZIP containing it).
     *
     * Supports both header variants:
     *  - watched.csv:  Title, Year, Your Rating, Date Watched, Review, Letterboxd URI
     *  - diary.csv:    Name,  Year, (Rating),  Date,         (Review), Letterboxd URI
     *
     * @param string $file_path Path to uploaded .csv or .zip file (tmp path has no extension).
     * @param array  $options   Existing import options.
     * @return array            ['imported' => int]
     * @throws Exception        On read/parse errors.
     */
    public function import_from_csv(string $file_path, array $options): array
    {
        // --- 0) Detect if the tmp file is actually a ZIP by signature, not by extension ---
        $magic = @file_get_contents($file_path, false, null, 0, 8);
        if ($magic === false) {
            throw new Exception(__('Could not read uploaded file.', 'letterboxd-connect'));
        }
        $is_zip = (
            strncmp($magic, "PK\x03\x04", 4) === 0 || // local file header
            strncmp($magic, "PK\x05\x06", 4) === 0 || // empty archive end
            strncmp($magic, "PK\x07\x08", 4) === 0    // spanned/split
        );
    
        // We'll normalize everything to a UTF-8 CSV text blob in $raw.
        $raw = '';
    
        if ($is_zip) {
            // --- 1) Open the ZIP and pick the right CSV entry (watched/diary), skipping __MACOSX and AppleDouble ---
            if (!class_exists('ZipArchive')) {
                throw new Exception(__('Cannot import ZIP: PHP zip extension is not available.', 'letterboxd-connect'));
            }
    
            $zip = new ZipArchive();
            if ($zip->open($file_path) !== true) {
                throw new Exception(__('Could not open ZIP archive.', 'letterboxd-connect'));
            }
    
            $candidates = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (!$name || substr($name, -1) === '/') {
                    continue; // directory
                }
                $base = basename($name);
                $baseLower = strtolower($base);
    
                // Skip macOS junk
                if (str_starts_with($name, '__MACOSX/') || str_starts_with($base, '._')) {
                    continue;
                }
    
                // Only consider watched/diary CSVs
                if ($baseLower === 'watched.csv' || $baseLower === 'diary.csv' || preg_match('/(watched|diary).*\.csv$/i', $baseLower)) {
                    $stat = $zip->statIndex($i);
                    $size = isset($stat['size']) ? (int) $stat['size'] : 0;
                    $candidates[] = [
                        'index' => $i,
                        'name'  => $name,
                        'base'  => $baseLower,
                        'size'  => $size,
                        'exact' => (int)($baseLower === 'watched.csv' || $baseLower === 'diary.csv'),
                    ];
                }
            }
    
            if (!$candidates) {
                $zip->close();
                throw new Exception(__('CSV not found in ZIP (expected watched.csv or diary.csv).', 'letterboxd-connect'));
            }
    
            // Prefer exact filename matches, then largest file size
            usort($candidates, static function ($a, $b) {
                if ($a['exact'] !== $b['exact']) {
                    return $b['exact'] <=> $a['exact'];
                }
                return $b['size'] <=> $a['size'];
            });
    
            $chosen = $candidates[0];
            // letterboxd_debug_log(sprintf('ZIP CSV selected: %s (size: %d bytes)', $chosen['name'], $chosen['size']));
    
            $stream = $zip->getStream($zip->getNameIndex($chosen['index']));
            if (!$stream) {
                $zip->close();
                throw new Exception(__('Could not open CSV entry from ZIP.', 'letterboxd-connect'));
            }
    
            $raw = stream_get_contents($stream);
            fclose($stream);
            $zip->close();
    
        } else {
            // --- 2) Not a ZIP → read the whole file as text (CSV) ---
            $raw = file_get_contents($file_path);
            if ($raw === false) {
                throw new Exception(__('Could not read CSV file.', 'letterboxd-connect'));
            }
        }
    
        // --- 3) Normalize to UTF-8 text and newlines, strip BOMs ---
        $raw = str_replace(["\r\n", "\r"], "\n", (string) $raw);
    
        // Re-encode if UTF-16/UTF-32 or has BOM
        $startsWith = static function (string $s, string $prefix): bool {
            return strncmp($s, $prefix, strlen($prefix)) === 0;
        };
    
        if ($startsWith($raw, "\xFF\xFE")) {               // UTF-16LE BOM
            $raw = (string) @iconv('UTF-16LE', 'UTF-8//IGNORE', $raw);
        } elseif ($startsWith($raw, "\xFE\xFF")) {         // UTF-16BE BOM
            $raw = (string) @iconv('UTF-16BE', 'UTF-8//IGNORE', $raw);
        } elseif ($startsWith($raw, "\xEF\xBB\xBF")) {     // UTF-8 BOM
            $raw = substr($raw, 3);
        } elseif (strpos($raw, "\x00") !== false) {
            // Heuristic: NUL bytes → try UTF-16 decodes
            $try = @iconv('UTF-16LE', 'UTF-8//IGNORE', $raw);
            if ($try !== false && $try !== '') {
                $raw = $try;
            } else {
                $try = @iconv('UTF-16BE', 'UTF-8//IGNORE', $raw);
                if ($try !== false && $try !== '') {
                    $raw = $try;
                }
            }
        }
    
        // --- 4) Handle optional "sep=" directive and detect delimiter ---
        $delimiter = ',';
        if (preg_match('/^(?:sep=)(.+)\s*$/mi', $raw, $m)) {
            $d = trim($m[1]);
            if ($d !== '' && strlen($d) === 1) {
                $delimiter = $d;
            }
            // remove the sep= line (first occurrence)
            $raw = preg_replace('/^sep=.*\n/mi', '', $raw, 1);
        }
        if (!isset($m[1])) {
            $first_lines = implode("\n", array_slice(explode("\n", $raw), 0, 5));
            $cands = [
                ','  => substr_count($first_lines, ','),
                ';'  => substr_count($first_lines, ';'),
                "\t" => substr_count($first_lines, "\t"),
                '|'  => substr_count($first_lines, '|'),
            ];
            arsort($cands);
            $best = key($cands);
            if (!empty($best)) {
                $delimiter = $best;
            }
        }
    
        // --- 5) Recreate a CSV stream from normalized UTF-8 text ---
        $fp = fopen('php://temp', 'r+');
        if (!$fp) {
            throw new Exception(__('Unable to allocate CSV buffer.', 'letterboxd-connect'));
        }
        fwrite($fp, $raw);
        rewind($fp);
    
        // --- 6) Read & normalize header ---
        $header = fgetcsv($fp, 0, $delimiter);
        if (!is_array($header)) {
            // Helpful debug: first bytes if header couldn’t be read
            // letterboxd_debug_log('CSV header could not be parsed; first 256 bytes: ' . bin2hex(substr($raw, 0, 256)));
            fclose($fp);
            throw new Exception(__('Invalid CSV format.', 'letterboxd-connect'));
        }
    
        $normalize_header = static function ($h): string {
            $s = (string) $h;
            // Remove BOM, zero-width, NBSP; be resilient to invalid UTF-8
            $clean = @preg_replace('/\x{FEFF}|\x{200B}|\x{00A0}/u', '', $s);
            if ($clean === null) {
                $s     = (string) @iconv('UTF-8', 'UTF-8//IGNORE', $s);
                $clean = @preg_replace('/\x{FEFF}|\x{200B}|\x{00A0}/u', '', $s);
            }
            $s = (string) ($clean ?? $s);
            $s = strtolower(trim($s));
            $s = preg_replace('/\s+/', ' ', $s);
            // normalize fancy dashes to hyphen
            $s = str_replace(['–', '—'], '-', $s);
            return $s;
        };
    
        $header_norm = array_map($normalize_header, $header);
        // letterboxd_debug_log('CSV header normalized: ' . wp_json_encode($header_norm));
    
        // --- 7) Map Letterboxd header variants to canonical keys ---
        $map = [
            'title'         => ['title', 'name', 'film', 'movie'],
            'year'          => ['year', 'release year'],
            'date'          => ['date watched', 'date', 'watched date'],
            'rating'        => ['your rating', 'rating', 'diary rating'],
            'review'        => ['review', 'review text', 'diary entry', 'diary'],
            'uri'           => ['letterboxd uri', 'letterboxd url', 'letterboxd link', 'uri'],
            'tmdb_movie_id' => ['tmdb movie id', 'tmdb id'],
        ];
        $index = [];
        foreach ($map as $canon => $aliases) {
            $index[$canon] = null;
            foreach ($aliases as $alias) {
                $pos = array_search($alias, $header_norm, true);
                if ($pos !== false) { $index[$canon] = $pos; break; }
            }
        }
        // letterboxd_debug_log('CSV header index map: ' . wp_json_encode($index));
    
        // --- 8) Parse rows (pad/truncate to header length), build items, import ---
        $imported            = 0;
        $skipped_existing    = 0;
        $skipped_duplicates  = 0;
        $line                = 1;
        $expected            = count($header);
        
        // Prefetch existing comparable keys
        $existing            = $this->prefetch_existing_keys();
        $existing_url_set    = $existing['url_keys'];
        $existing_ty_set     = $existing['title_year_keys'];
        
        // Dedupe within this single CSV
        $seen_url_keys = [];
        $seen_ty_keys  = [];
        
        while (($row = fgetcsv($fp, 0, $delimiter)) !== false) {
            $line++;
        
            if ($row === [null] || empty(array_filter($row, static fn($v) => $v !== null && $v !== '' && $v !== false))) {
                continue;
            }
        
            $actual = count($row);
            if ($actual !== $expected) {
                if ($actual < $expected) {
                    $row = array_pad($row, $expected, '');
                    // letterboxd_debug_log(sprintf('Padded CSV row %d from %d to %d.', $line, $actual, $expected));
                } else {
                    $row = array_slice($row, 0, $expected);
                    // letterboxd_debug_log(sprintf('Truncated CSV row %d from %d to %d.', $line, $actual, $expected));
                }
            }
        
            $get = static function (string $key) use ($row, $index): string {
                $i = $index[$key];
                return ($i !== null && array_key_exists($i, $row)) ? trim((string)$row[$i]) : '';
            };
        
            $title = $get('title');
            $year  = $get('year');
            $date  = $get('date');
            $uri   = $get('uri');
        
            if ($title === '' || $date === '') {
                // letterboxd_debug_log('Skipping row due to missing Title/Date: ' . wp_json_encode([
                //     'Date' => $date, 'Title' => $title, 'Year' => $year, 'Letterboxd URI' => $uri
                // ]));
                continue;
            }
        
            // Build comparable keys (URL-based and Title+Year)
            $urlKey = $this->normalize_letterboxd_key($uri);
            $tyKey  = $this->normalize_letterboxd_key('', $title, $year);
        
            // Skip if we already have this movie in DB
            if (($urlKey !== '' && isset($existing_url_set[$urlKey])) ||
                ($tyKey  !== '' && isset($existing_ty_set[$tyKey]))) {
                $skipped_existing++;
                continue;
            }
        
            // Skip duplicates within this CSV run
            if (($urlKey !== '' && isset($seen_url_keys[$urlKey])) ||
                ($tyKey  !== '' && isset($seen_ty_keys[$tyKey]))) {
                $skipped_duplicates++;
                continue;
            }
            if ($urlKey !== '') { $seen_url_keys[$urlKey] = true; }
            if ($tyKey  !== '') { $seen_ty_keys[$tyKey]  = true; }
        
            $ts = strtotime($date);
            if (!$ts) {
                // letterboxd_debug_log('Skipping row due to unparseable date: ' . $date);
                continue;
            }
        
            $rating = $get('rating');
            $review = $get('review');
            $tmdbId = $get('tmdb_movie_id');
        
            $ratingSuffix = $rating !== '' ? ' - ' . $rating : '';
            $item = [
                'link'         => $uri,
                'title'        => "{$title}, {$year}{$ratingSuffix}",
                'pubDate'      => gmdate('r', $ts),
                'description'  => $review,
                'poster_url'   => '',
                'filmYear'     => $year,
                'tmdb_movieId' => $tmdbId,
                'rating'       => $rating
            ];
        
            if ($this->import_movie($item, $options)) {
                $imported++;
            }
        }
        
        fclose($fp);
        return [
            'imported'           => $imported,
            'skipped_existing'   => $skipped_existing,
            'skipped_duplicates' => $skipped_duplicates,
        ];

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
            // letterboxd_debug_log( "Cannot import poster: Invalid post ID " . $post_id );
            return;
        }

        // Include required WordPress files for media handling.
        require_once ABSPATH . "wp-admin/includes/file.php";
        require_once ABSPATH . "wp-admin/includes/media.php";
        require_once ABSPATH . "wp-admin/includes/image.php";

        // Download the remote image to a temporary file.
        $tmp_file = download_url($poster_url, 30); // 30 seconds timeout.
        if (is_wp_error($tmp_file)) {
            // letterboxd_debug_log( "Failed to download poster: " . $tmp_file->get_error_message() );
            return;
        }

        // Prepare a file array similar to a $_FILES entry.
        $file_array = [];
        // Extract a filename from the URL. Fallback to a default if needed.
        $parsed_url = wp_parse_url($poster_url, PHP_URL_PATH);
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
            // letterboxd_debug_log( "Failed to sideload image: " . $attach_id->get_error_message() );
            // Cleanup temporary file if needed.
            global $wp_filesystem;
            if (empty($wp_filesystem)) {
                require_once(ABSPATH . '/wp-admin/includes/file.php');
                WP_Filesystem();
            }
            $wp_filesystem->delete($file_array["tmp_name"]);
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
            // letterboxd_debug_log( "Failed to generate attachment metadata: " . $attach_data->get_error_message() );
            return;
        }

        $update_result = wp_update_attachment_metadata(
            $attach_id,
            $attach_data
        );
        if (is_wp_error($update_result)) {
            // letterboxd_debug_log( "Failed to update attachment metadata: " . $update_result->get_error_message() );
        }

        // Set the sideloaded image as the featured image.
        if (!set_post_thumbnail($post_id, $attach_id)) {
            // letterboxd_debug_log( "Failed to set featured image for post ID: " . $post_id );
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
            // letterboxd_debug_log( "TMDB enrichment skipped: API key not configured." );
            return;
        }

        // Skip if movie ID is missing or invalid
        if (empty($tmdb_movie_id) || !is_numeric($tmdb_movie_id)) {
            // letterboxd_debug_log( "TMDB enrichment skipped: Invalid movie ID: " . $tmdb_movie_id );
            return;
        }

        $movie_id = intval($tmdb_movie_id);

        // Check if we already have complete TMDB data for this post
        $has_tmdb_data = get_post_meta($post_id, "director", true);
        if (!empty($has_tmdb_data)) {
            // letterboxd_debug_log( "TMDB enrichment skipped: Movie already has TMDB data" );
            return;
        }

        try {
            // Fetch movie details from TMDB
            $movie_data = $this->tmdb_handler->get_movie_details($movie_id);

            if (is_wp_error($movie_data)) {
                // letterboxd_debug_log( "TMDB API error: " . $movie_data->get_error_message() );
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
            
            // letterboxd_debug_log( "TMDB enrichment complete for movie: " . get_the_title($post_id) );
        } catch (Exception $e) {
            // letterboxd_debug_log("TMDB enrichment error: " . $e->getMessage());
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