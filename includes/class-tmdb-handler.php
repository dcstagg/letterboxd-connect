<?php
/**
 * Handles interactions with The Movie Database API with improved efficiency
 *
 * @package letterboxd-wordpress
 * @since 1.1.0
 */

declare(strict_types=1);

// Prevent direct access
if (!defined("ABSPATH")) {
    exit();
}

class Letterboxd_TMDB_Handler {
    private function debug_log($message) {
        letterboxd_debug_log($message, "TMDB_Handler");
    }

    /**
     * API constants
     */
    private const API_URL = "https://api.themoviedb.org/3";
    private const CACHE_GROUP = "letterboxd_tmdb";
    private const CACHE_DURATION = 604800; // 1 week
    private const STREAMING_CACHE_DURATION = 172800; // 2 days (streaming data changes more frequently)
    private const RATE_LIMIT_KEY = "tmdb_rate_limit";
    private const RATE_LIMIT_PERIOD = 1; // 1 second
    private const RATE_LIMIT_REQUESTS = 3; // Max 3 requests per second (TMDB's rate limit)

    /**
     * @var string API key
     */
    private string $api_key;

    /**
     * @var string|null Session ID
     */
    private ?string $session_id;

    /**
     * Class constructor
     */
    public function __construct() {
        $this->load_api_credentials();
    }

    /**
     * Load API credentials from options
     */
    private function load_api_credentials(): void {
        $options = get_option("letterboxd_wordpress_advanced_options", []);
        $this->api_key = $options["tmdb_api_key"] ?? "";
        $this->session_id = $options["tmdb_session_id"] ?? null;
    }

    /**
     * Check if API key is configured
     *
     * @return bool Whether API key is configured
     */
    public function is_api_key_configured(): bool {
        return !empty($this->api_key);
    }

    /**
     * Rate limit API requests to avoid hitting TMDB limits
     *
     * @return bool True if request should proceed, false if it should wait
     */
    private function check_rate_limit(): bool {
        $rate_data = get_transient(self::RATE_LIMIT_KEY);

        if (!$rate_data) {
            $rate_data = [
                "count" => 1,
                "timestamp" => time(),
            ];
            set_transient(
                self::RATE_LIMIT_KEY,
                $rate_data,
                self::RATE_LIMIT_PERIOD
            );
            return true;
        }

        $current_time = time();
        $elapsed = $current_time - $rate_data["timestamp"];

        // If period has elapsed, reset the counter
        if ($elapsed >= self::RATE_LIMIT_PERIOD) {
            $rate_data = [
                "count" => 1,
                "timestamp" => $current_time,
            ];
            set_transient(
                self::RATE_LIMIT_KEY,
                $rate_data,
                self::RATE_LIMIT_PERIOD
            );
            return true;
        }

        // If we've reached the limit, enforce waiting
        if ($rate_data["count"] >= self::RATE_LIMIT_REQUESTS) {
            return false;
        }

        // Increment the counter
        $rate_data["count"]++;
        set_transient(
            self::RATE_LIMIT_KEY,
            $rate_data,
            self::RATE_LIMIT_PERIOD - $elapsed
        );
        return true;
    }

    /**
     * Make an API request with rate limiting
     *
     * @param string $endpoint API endpoint
     * @param array $query_args Query arguments
     * @return array|WP_Error Response data or error
     */
    private function make_api_request(
        string $endpoint,
        array $query_args = []
    ): array|WP_Error {
        if (!$this->is_api_key_configured()) {
            return new WP_Error(
                "tmdb_api_not_configured",
                __("TMDB API key is not configured.", "letterboxd-wordpress")
            );
        }

        // Add API key to query args
        $query_args["api_key"] = $this->api_key;

        // Build full URL
        $url = self::API_URL . $endpoint;
        $url = add_query_arg($query_args, $url);

        // Apply rate limiting with retry logic
        $max_retries = 3;
        $retry_count = 0;

        while ($retry_count < $max_retries) {
            if ($this->check_rate_limit()) {
                $response = wp_remote_get($url, [
                    "timeout" => 30,
                    "headers" => [
                        "Accept" => "application/json",
                    ],
                ]);

                if (is_wp_error($response)) {
                    letterboxd_debug_log(
                        "TMDB API Error: " . $response->get_error_message()
                    );
                    return $response;
                }

                $status_code = wp_remote_retrieve_response_code($response);

                // Handle rate limiting response
                if ($status_code === 429) {
                    $retry_after = (int) wp_remote_retrieve_header(
                        $response,
                        "retry-after"
                    );
                    $retry_after = max(1, $retry_after); // Ensure at least 1 second
                    sleep($retry_after);
                    $retry_count++;
                    continue;
                }

                if ($status_code !== 200) {
                    $body = wp_remote_retrieve_body($response);
                    $data = json_decode($body, true);
                    $error_message = $data["status_message"] ?? "Unknown error";

                    letterboxd_debug_log(
                        "TMDB API Error ({$status_code}): {$error_message}"
                    );

                    return new WP_Error(
                        "tmdb_api_error_" . $status_code,
                        sprintf(
                            __("TMDB API error: %s", "letterboxd-wordpress"),
                            $error_message
                        ),
                        ["status" => $status_code]
                    );
                }

                $data = json_decode(wp_remote_retrieve_body($response), true);

                if (empty($data) || !is_array($data)) {
                    return new WP_Error(
                        "tmdb_invalid_response",
                        __(
                            "Invalid response from TMDB API.",
                            "letterboxd-wordpress"
                        )
                    );
                }

                return $data;
            } else {
                // Wait for rate limit to reset
                sleep(self::RATE_LIMIT_PERIOD);
                $retry_count++;
            }
        }

        return new WP_Error(
            "tmdb_rate_limit_exceeded",
            __(
                "TMDB API rate limit exceeded after retries.",
                "letterboxd-wordpress"
            )
        );
    }

    /**
     * Get movie details from TMDB
     *
     * @param int $movie_id TMDB movie ID
     * @return array|WP_Error Movie data or error
     */
    public function get_movie_details(int $movie_id): array|WP_Error {
        $cache_key = "movie_" . $movie_id;
        $cached_data = wp_cache_get($cache_key, self::CACHE_GROUP);

        if ($cached_data !== false) {
            return $cached_data;
        }

        $response = $this->make_api_request("/movie/{$movie_id}", [
            "append_to_response" => "credits,release_dates,images,external_ids",
        ]);

        if (!is_wp_error($response)) {
            wp_cache_set(
                $cache_key,
                $response,
                self::CACHE_GROUP,
                self::CACHE_DURATION
            );
        }

        return $response;
    }

    /**
     * Batch fetch movie details for multiple movies
     *
     * @param array $movie_ids Array of TMDB movie IDs
     * @return array Array of movie data indexed by movie ID
     */
    public function batch_get_movie_details(array $movie_ids): array {
        $results = [];
        $ids_to_fetch = [];

        // Check cache first
        foreach ($movie_ids as $movie_id) {
            $cache_key = "movie_" . $movie_id;
            $cached_data = wp_cache_get($cache_key, self::CACHE_GROUP);

            if ($cached_data !== false) {
                $results[$movie_id] = $cached_data;
            } else {
                $ids_to_fetch[] = $movie_id;
            }
        }

        // Fetch uncached items
        foreach ($ids_to_fetch as $movie_id) {
            $data = $this->get_movie_details($movie_id);

            if (!is_wp_error($data)) {
                $results[$movie_id] = $data;
            } else {
                $results[$movie_id] = $data;
            }

            // Small delay between requests to respect rate limits
            if (count($ids_to_fetch) > 1) {
                usleep(300000); // 300ms
            }
        }

        return $results;
    }

    /**
     * Get streaming providers for a movie
     *
     * @param int $movie_id TMDB movie ID
     * @param string $region Region code (ISO 3166-1 alpha-2 code)
     * @return array|WP_Error Streaming providers data or error
     */
    public function get_streaming_providers(
        int $movie_id,
        string $region = "US"
    ): array|WP_Error {
        $cache_key = "streaming_" . $movie_id . "_" . $region;
        $cached_data = wp_cache_get($cache_key, self::CACHE_GROUP);

        if ($cached_data !== false) {
            return $cached_data;
        }

        $response = $this->make_api_request(
            "/movie/{$movie_id}/watch/providers"
        );

        if (is_wp_error($response)) {
            return $response;
        }

        // Extract providers for the requested region
        $providers = [];
        if (!empty($response["results"][$region])) {
            $providers = $response["results"][$region];
        }

        wp_cache_set(
            $cache_key,
            $providers,
            self::CACHE_GROUP,
            self::STREAMING_CACHE_DURATION
        );

        return $providers;
    }

    /**
     * Batch get streaming providers for multiple movies
     *
     * @param array $movie_ids Array of TMDB movie IDs
     * @param string $region Region code
     * @return array Streaming providers data indexed by movie ID
     */
    public function batch_get_streaming_providers(
        array $movie_ids,
        string $region = "US"
    ): array {
        $results = [];
        $ids_to_fetch = [];

        // Check cache first
        foreach ($movie_ids as $movie_id) {
            $cache_key = "streaming_" . $movie_id . "_" . $region;
            $cached_data = wp_cache_get($cache_key, self::CACHE_GROUP);

            if ($cached_data !== false) {
                $results[$movie_id] = $cached_data;
            } else {
                $ids_to_fetch[] = $movie_id;
            }
        }

        // Fetch uncached items
        foreach ($ids_to_fetch as $movie_id) {
            $providers = $this->get_streaming_providers($movie_id, $region);

            if (!is_wp_error($providers)) {
                $results[$movie_id] = $providers;
            } else {
                $results[$movie_id] = [];
            }

            // Small delay between requests to respect rate limits
            if (count($ids_to_fetch) > 1) {
                usleep(300000); // 300ms
            }
        }

        return $results;
    }

    /**
     * Process and format streaming provider data
     *
     * @param array $providers Raw provider data from TMDB
     * @return array Formatted provider data
     */
    public function format_streaming_providers(array $providers): array {
        $formatted = [
            "flatrate" => [],
            "rent" => [],
            "buy" => [],
            "free" => [],
            "ads" => [],
            "link" => $providers["link"] ?? "",
        ];

        $categories = ["flatrate", "rent", "buy", "free", "ads"];

        foreach ($categories as $category) {
            if (
                !empty($providers[$category]) &&
                is_array($providers[$category])
            ) {
                foreach ($providers[$category] as $provider) {
                    $formatted[$category][] = [
                        "provider_id" => $provider["provider_id"] ?? 0,
                        "provider_name" => $provider["provider_name"] ?? "",
                        "logo_path" => !empty($provider["logo_path"])
                            ? $this->get_image_url(
                                $provider["logo_path"],
                                "w92"
                            )
                            : "",
                    ];
                }
            }
        }

        return $formatted;
    }

    /**
     * Get a slug for the provider based on its name
     *
     * @param string $provider_name The provider's full name
     * @return string A slugified version of the provider name
     */
    private function get_provider_slug(string $provider_name): string {
        // Convert to lowercase and replace spaces with dashes
        $slug = strtolower(str_replace(" ", "-", $provider_name));

        // Remove any non-alphanumeric characters except dashes
        $slug = preg_replace("/[^a-z0-9-]/", "", $slug);

        // Remove multiple consecutive dashes
        $slug = preg_replace("/-+/", "-", $slug);

        return $slug;
    }

    /**
     * Create a URL-friendly slug from a movie title
     *
     * @param string $title Movie title
     * @return string URL-friendly slug
     */
    private function create_title_slug(string $title): string {
        // Remove special characters
        $slug = preg_replace("/[^\p{L}\p{N}\s-]/u", "", $title);
        // Replace spaces with dashes
        $slug = preg_replace("/\s+/", "-", $slug);
        // Convert to lowercase
        $slug = strtolower($slug);
        // Remove consecutive dashes
        $slug = preg_replace("/-+/", "-", $slug);
        // Trim dashes from beginning and end
        $slug = trim($slug, "-");

        return $slug;
    }

    /**
     * Get external IDs for a movie (IMDb, etc.)
     *
     * @param int $movie_id TMDB movie ID
     * @return array|WP_Error External IDs or error
     */
    public function get_external_ids(int $movie_id): array|WP_Error {
        $cache_key = "external_ids_" . $movie_id;
        $cached_data = wp_cache_get($cache_key, self::CACHE_GROUP);

        if ($cached_data !== false) {
            return $cached_data;
        }

        $response = $this->make_api_request("/movie/{$movie_id}/external_ids");

        if (!is_wp_error($response)) {
            wp_cache_set(
                $cache_key,
                $response,
                self::CACHE_GROUP,
                self::CACHE_DURATION
            );
        }

        return $response;
    }

    /**
     * Get directors from movie credits
     *
     * @param array $movie_data Movie data from TMDB
     * @return array List of directors
     */
    public function get_directors(array $movie_data): array {
        if (empty($movie_data["credits"]["crew"])) {
            return [];
        }

        $directors = [];
        foreach ($movie_data["credits"]["crew"] as $crew_member) {
            if ($crew_member["job"] === "Director") {
                $directors[] = $crew_member["name"];
            }
        }

        return $directors;
    }

    /**
     * Extract relevant movie metadata
     *
     * @param array $movie_data Movie data from TMDB
     * @param string $region Region code for streaming providers
     * @return array Extracted metadata
     */
    public function extract_movie_metadata(
        array $movie_data,
        string $region = "US"
    ): array {
        $metadata = [];

        // Basic movie information
        $metadata["tmdb_title"] = $movie_data["title"] ?? "";
        $metadata["tmdb_original_title"] = $movie_data["original_title"] ?? "";
        $metadata["tmdb_overview"] = $movie_data["overview"] ?? "";
        $metadata["tmdb_release_date"] = $movie_data["release_date"] ?? "";
        $metadata["tmdb_id"] = $movie_data["id"] ?? 0; // Store the TMDB ID consistently

        // Images
        if (!empty($movie_data["poster_path"])) {
            $metadata["tmdb_poster_path"] = $movie_data["poster_path"];
        }
        if (!empty($movie_data["backdrop_path"])) {
            $metadata["tmdb_backdrop_path"] = $movie_data["backdrop_path"];
        }

        // Directors (from credits)
        $directors = $this->get_directors($movie_data);
        if (!empty($directors)) {
            $metadata["director"] = implode(", ", $directors);
        }

        // Extract external IDs
        if (!empty($movie_data["external_ids"])) {
            if (!empty($movie_data["external_ids"]["imdb_id"])) {
                $metadata["imdb_id"] = $movie_data["external_ids"]["imdb_id"];
            }
        } elseif (!empty($movie_data["id"])) {
            // If we don't have external IDs in the current data, fetch them separately
            $external_ids = $this->get_external_ids($movie_data["id"]);
            if (
                !is_wp_error($external_ids) &&
                !empty($external_ids["imdb_id"])
            ) {
                $metadata["imdb_id"] = $external_ids["imdb_id"];
            }
        }

        // Genres
        if (!empty($movie_data["genres"])) {
            $genres = array_map(function ($genre) {
                return $genre["name"];
            }, $movie_data["genres"]);

            $metadata["tmdb_genres"] = implode(", ", $genres);
        }

        // Get streaming providers if TMDB ID is available
        if (!empty($movie_data["id"])) {
            $providers = $this->get_streaming_providers(
                $movie_data["id"],
                $region
            );
            if (!is_wp_error($providers) && !empty($providers)) {
                $metadata["streaming_providers"] = wp_json_encode(
                    $this->format_streaming_providers($providers)
                );
                $metadata["streaming_link"] = $providers["link"] ?? "";
                $metadata["streaming_providers_updated"] = current_time(
                    "mysql"
                );
            }
        }

        return $metadata;
    }

    /**
     * Batch update streaming providers for movies
     *
     * @param array $post_ids Array of WordPress post IDs
     * @param string $region Region code
     * @return array Results with updated count and errors
     */
    public function batch_update_streaming_providers(
        array $post_ids,
        string $region = "US"
    ): array {
        $results = [
            "updated" => 0,
            "failed" => 0,
            "errors" => [],
        ];

        if (!$this->is_api_key_configured()) {
            letterboxd_debug_log(
                "TMDB API key not configured for streaming provider update"
            );
            return [
                "updated" => 0,
                "failed" => count($post_ids),
                "errors" => ["TMDB API key is not configured."],
            ];
        }

        // Get TMDB IDs for all posts - check both possible meta keys
        $tmdb_ids = [];
        $post_tmdb_map = [];

        foreach ($post_ids as $post_id) {
            // Check both possible meta key names
            $tmdb_id = get_post_meta($post_id, "tmdb_id", true);
            if (empty($tmdb_id)) {
                $tmdb_id = get_post_meta($post_id, "tmdb_movie_id", true);
            }

            if (!empty($tmdb_id)) {
                $tmdb_ids[] = (int) $tmdb_id;
                $post_tmdb_map[(int) $tmdb_id] = $post_id;
            } else {
                $results["failed"]++;
                letterboxd_debug_log("No TMDB ID for post {$post_id}");
            }
        }

        if (empty($tmdb_ids)) {
            return $results;
        }

        try {
            // Process in chunks to avoid timeouts (max 5 at a time)
            $tmdb_id_chunks = array_chunk($tmdb_ids, 5);

            foreach ($tmdb_id_chunks as $chunk) {
                // Batch get streaming providers
                $providers_data = $this->batch_get_streaming_providers(
                    $chunk,
                    $region
                );

                // Update post meta for each movie
                foreach ($providers_data as $tmdb_id => $providers) {
                    $post_id = $post_tmdb_map[$tmdb_id] ?? 0;
                    if (!$post_id) {
                        $results["failed"]++;
                        continue;
                    }

                    if (empty($providers) || is_wp_error($providers)) {
                        $results["failed"]++;
                        $error_msg = is_wp_error($providers)
                            ? $providers->get_error_message()
                            : "No providers for TMDB ID {$tmdb_id}";
                        $results["errors"][] = $error_msg;
                        letterboxd_debug_log($error_msg);
                        continue;
                    }

                    try {
                        $formatted_providers = $this->format_streaming_providers(
                            $providers
                        );

                        // Update post meta
                        update_post_meta(
                            $post_id,
                            "streaming_providers",
                            wp_json_encode($formatted_providers)
                        );
                        update_post_meta(
                            $post_id,
                            "streaming_link",
                            $providers["link"] ?? ""
                        );
                        update_post_meta(
                            $post_id,
                            "streaming_providers_updated",
                            current_time("mysql")
                        );

                        $results["updated"]++;
                    } catch (Exception $e) {
                        $results["failed"]++;
                        $error_msg =
                            "Error processing TMDB ID {$tmdb_id}: " .
                            $e->getMessage();
                        $results["errors"][] = $error_msg;
                        letterboxd_debug_log($error_msg);
                    }
                }

                // Small pause between chunks to avoid overloading the server
                if (count($tmdb_id_chunks) > 1) {
                    usleep(500000); // 500ms pause
                }
            }
        } catch (Exception $e) {
            letterboxd_debug_log(
                "Exception during batch update: " . $e->getMessage()
            );
            $results["errors"][] =
                "Exception during batch update: " . $e->getMessage();
        }

        return $results;
    }

    /**
     * Generate IMDb URL from IMDb ID
     *
     * @param string $imdb_id IMDb ID
     * @return string IMDb URL
     */
    public function get_imdb_url(string $imdb_id): string {
        return "https://www.imdb.com/title/{$imdb_id}/";
    }

    /**
     * Generate Rotten Tomatoes search URL based on movie title and year
     *
     * @param string $title Movie title
     * @param string $year Release year
     * @return string Rotten Tomatoes search URL
     */
    public function get_rotten_tomatoes_url(
        string $title,
        string $year
    ): string {
        $search_query = urlencode(trim($title) . " " . trim($year));
        return "https://www.rottentomatoes.com/search?search={$search_query}";
    }

    /**
     * Get image URL with size
     *
     * @param string $path Image path from TMDB
     * @param string $size Image size (w500, original, etc.)
     * @return string Full image URL
     */
    public function get_image_url(string $path, string $size = "w500"): string {
        return "https://image.tmdb.org/t/p/{$size}{$path}";
    }

    /**
     * Clear streaming provider cache for a region
     *
     * @param string $region Region code
     * @return bool Success
     */
    public function clear_streaming_cache(string $region = ""): bool {
        global $wpdb;

        if (wp_using_ext_object_cache()) {
            // For external object cache, we need to selectively delete
            // This implementation is simplified and may need customization based on actual cache setup
            if (empty($region)) {
                wp_cache_flush();
            } else {
                // You'd need a way to iterate over all cache keys matching a pattern
                // This is a simplification since pattern-based deletion depends on the cache implementation
                $pattern = "streaming_*_" . $region;
                // Implementation depends on your cache system
            }
            return true;
        } else {
            // For WordPress transients
            $pattern = empty($region)
                ? "%_streaming_%"
                : "%_streaming_%" . $region . "%";

            return $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                    "_transient_" . self::CACHE_GROUP . $pattern
                )
            ) !== false;
        }
    }
}