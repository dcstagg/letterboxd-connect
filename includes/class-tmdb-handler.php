<?php
/**
 * Handles interactions with The Movie Database API with improved efficiency
 *
 * @package letterboxd-connect
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
     * Make an API request with rate limiting + verbose, sanitized logging.
     *
     * @param string $endpoint   API endpoint (e.g. "/movie/550").
     * @param array  $query_args Query arguments.
     * @return array|WP_Error    Response data or error.
     */
    private function make_api_request(
        string $endpoint,
        array $query_args = []
    ): array|WP_Error {
        if (!$this->is_api_key_configured()) {
            // letterboxd_debug_log('[API] No API key configured');
            return new WP_Error(
                'tmdb_api_not_configured',
                __('TMDB API key is not configured.', 'letterboxd-connect')
            );
        }

        // Add API key to query args
        $query_args['api_key'] = $this->api_key;

        // Build full and sanitized URLs
        $url      = self::API_URL . $endpoint;
        $url      = add_query_arg($query_args, $url);
        $safe_url = remove_query_arg('api_key', $url);

        // Apply rate limiting with retry logic
        $max_retries = 3;
        $retry_count = 0;

        // letterboxd_debug_log("[API] GET {$safe_url}");

        while ($retry_count < $max_retries) {
            if ($this->check_rate_limit()) {
                $response = wp_remote_get($url, [
                    'timeout' => 30,
                    'headers' => [
                        'Accept'     => 'application/json',
                        'User-Agent' => 'Letterboxd-Connect/1.0; WordPress',
                    ],
                ]);

                // if (is_wp_error($response)) {
                //     letterboxd_debug_log('[API] WP_Error: ' . $response->get_error_message());
                //     return $response;
                // }

                $status_code = wp_remote_retrieve_response_code($response);
                $body        = wp_remote_retrieve_body($response);
                $excerpt     = substr((string) $body, 0, 200);

                // Log status + short body excerpt (safe)
                // letterboxd_debug_log("[API] {$status_code} for {$safe_url} body: {$excerpt}");

                // Handle rate limiting response
                if ($status_code === 429) {
                    $retry_after = (int) wp_remote_retrieve_header($response, 'retry-after');
                    $retry_after = max(1, $retry_after); // Ensure at least 1 second
                    // letterboxd_debug_log("[API] 429 rate limited. retry-after={$retry_after}s");
                    sleep($retry_after);
                    $retry_count++;
                    continue;
                }

                if ($status_code !== 200) {
                    $data          = json_decode($body ?: '', true);
                    $error_message = $data['status_message'] ?? 'Unknown error';
                    return new WP_Error(
                        'tmdb_api_error_' . $status_code,
                        sprintf(__('TMDB API error: %s', 'letterboxd-connect'), $error_message),
                        ['status' => $status_code]
                    );
                }

                $data = json_decode($body ?: '', true);

                if (empty($data) || !is_array($data)) {
                    // letterboxd_debug_log('[API] Invalid JSON payload');
                    return new WP_Error(
                        'tmdb_invalid_response',
                        __('Invalid response from TMDB API.', 'letterboxd-connect')
                    );
                }

                return $data;
            } else {
                // Wait for local rate limiter window to reset
                // letterboxd_debug_log('[API] Local rate limiter pause 1s');
                sleep(self::RATE_LIMIT_PERIOD);
                $retry_count++;
            }
        }

        // letterboxd_debug_log('[API] Rate limit exceeded after retries');
        return new WP_Error(
            'tmdb_rate_limit_exceeded',
            __('TMDB API rate limit exceeded after retries.', 'letterboxd-connect')
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
            if (($crew_member["job"] ?? '') === "Director") {
                $directors[] = $crew_member["name"];
            }
        }

        return $directors;
    }

    /**
     * Try to resolve a TMDB ID for a post.
     * Order of attempts:
     *  1) If post has an IMDb ID, resolve via /find (most reliable).
     *  2) Search by normalized title + year (and a few title variants / alt titles, with ±1 year tolerance).
     * Returns 0 if nothing found.
     */
    public function resolve_tmdb_id_for_post(int $post_id, string $region = 'US'): int {
        // 0) Already have a TMDB id?
        $existing = (int) (get_post_meta($post_id, 'tmdb_id', true) ?: get_post_meta($post_id, 'tmdb_movie_id', true));
        if ($existing) {
            return $existing;
        }

        // 1) Try IMDb -> TMDB mapping if available
        $imdb_meta_keys = (array) apply_filters('letterboxd_tmdb_imdb_meta_keys', ['imdb_id', 'wpcf-imdb-id', 'movie_imdb_id']);
        foreach ($imdb_meta_keys as $k) {
            $imdb = trim((string) get_post_meta($post_id, $k, true));
            if ($imdb) {
                $id = $this->tmdb_find_by_imdb($imdb);
                // letterboxd_debug_log("[META] IMDb lookup for post {$post_id} ({$imdb}) => tmdb_id=" . (int)$id);
                if ($id) {
                    update_post_meta($post_id, 'tmdb_id', (int)$id);
                    return (int)$id;
                }
            }
        }

        // 2) Build search inputs
        [$title, $year, $altTitles] = $this->extract_search_inputs_for_post($post_id);

        // If year is still unknown, try a deeper guess from other fields
        if (is_null($year)) {
            $guessed = $this->guess_release_year($post_id, $title);
            if ($guessed) { $year = $guessed; }
        }

        // letterboxd_debug_log("[META] No tmdb_id for post {$post_id}. Searching: title='{$title}' year=" . (int)$year);

        // Allow site owners to inject extra candidate titles
        $altTitles = apply_filters('letterboxd_tmdb_alt_titles', $altTitles, $post_id);

        // Include a few title variants (strip subtitles, parentheses, etc.)
        $candidates = array_merge([$title], $this->title_variants($title), $altTitles);
        $candidates = array_values(array_unique(array_filter(array_map('trim', $candidates))));

        $yearCandidates = array_unique(array_filter([$year, $year ? $year - 1 : null, $year ? $year + 1 : null, null], static fn($v) => $v !== null));

        foreach ($candidates as $q) {
            foreach ($yearCandidates as $y) {
                $id = $this->search_movie_id($q, $y);
                // letterboxd_debug_log("[META] Search result for post {$post_id} (q='{$q}' y=" . (is_null($y) ? 'null' : $y) . "): tmdb_id={$id}");
                if ($id) {
                    update_post_meta($post_id, 'tmdb_id', (int)$id);
                    return (int)$id;
                }
            }
        }

        return 0;
    }

    /**
     * Public helper kept for batch code.
     * Returns TMDB id or 0.
     */
    public function search_movie_id(string $query, ?int $year = null): int {
        $query = $this->normalize_query_string($query);
        if ($query === '') { return 0; }

        $resp = $this->tmdb_search_movie($query, $year);
        if (is_wp_error($resp)) {
            // letterboxd_debug_log('[META] TMDB search error: ' . $resp->get_error_message());
            return 0;
        }

        $id = $this->choose_best_search_match($resp['results'] ?? [], $query, $year);
        return (int) ($id ?: 0);
    }

    /**
     * Decide which search result is the best match for the given title/year.
     * Returns TMDB id or null.
     */
    private function choose_best_search_match(array $results, string $originalQuery, ?int $year): ?int {
        if (empty($results)) { return null; }

        $qNorm = $this->normalize_title($originalQuery);

        $best = null;
        $bestScore = -INF;

        foreach ($results as $row) {
            if (empty($row['id']) || empty($row['title'])) { continue; }

            $title = (string)$row['title'];
            $alt   = (string)($row['original_title'] ?? '');
            $yearFromTMDB = null;
            if (!empty($row['release_date']) && preg_match('/^\d{4}/', (string)$row['release_date'], $m)) {
                $yearFromTMDB = (int)$m[0];
            }

            $tNorm  = $this->normalize_title($title);
            $oNorm  = $alt ? $this->normalize_title($alt) : '';

            // Base score on normalized title similarity
            $score = 0.0;

            if ($tNorm === $qNorm || ($oNorm && $oNorm === $qNorm)) {
                $score = 100.0;
            } elseif (str_starts_with($tNorm, $qNorm) || ($oNorm && str_starts_with($oNorm, $qNorm))) {
                $score = 85.0;
            } else {
                // fuzzy-ish: inverse of levenshtein distance capped
                $dist = levenshtein($qNorm, $tNorm);
                $len  = max(strlen($qNorm), 1);
                $sim  = max(0.0, 1.0 - min($dist, $len) / $len); // 0..1
                $score = 60.0 * $sim;
            }

            // Year bonus/penalty
            if (!is_null($year)) {
                if ($yearFromTMDB === $year) {
                    $score += 15.0;
                } elseif ($yearFromTMDB && abs($yearFromTMDB - $year) === 1) {
                    $score += 7.5;
                } elseif ($yearFromTMDB) {
                    $score -= min(15.0, 3.0 * abs($yearFromTMDB - $year));
                }
            }

            // Popularity helps break ties (scaled down)
            if (isset($row['popularity'])) {
                $score += min(5.0, (float)$row['popularity'] / 50.0);
            }

            // letterboxd_debug_log(sprintf('[META] Candidate "%s" (id=%d y=%s) scored %.1f', $title, (int)$row['id'], $yearFromTMDB ?? '—', $score));

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = (int)$row['id'];
            }
        }

        // Require a reasonable score to avoid random mismatches
        if ($best !== null && $bestScore >= 55.0) {
            return $best;
        }
        return null;
    }

    /**
     * Normalize a title purely for comparison: lowercase, strip accents & punctuation.
     */
    private function normalize_title(string $s): string {
        $s = $this->normalize_query_string($s);
        $s = remove_accents($s);
        $s = strtolower($s);
        // Keep letters and numbers, turn everything else into space
        $s = preg_replace('/[^a-z0-9]+/u', ' ', $s);
        $s = trim(preg_replace('/\s+/', ' ', $s));
        return $s;
    }

    /** Generate query variants to improve match odds. */
    private function title_variants(string $title): array {
        $variants = [];
        $variants[] = $title;

        // Remove subtitles after colon or dash
        if (strpos($title, ':') !== false) {
            $variants[] = trim(preg_replace('/:.+$/u', '', $title));
        }
        if (strpos($title, '-') !== false) {
            $variants[] = trim(preg_replace('/-.+$/u', '', $title));
        }

        // Strip parentheses content (e.g., director’s cut)
        $variants[] = trim(preg_replace('/\s*\(.*?\)\s*/u', ' ', $title));

        // Lowercased variant
        $variants[] = mb_strtolower($title, 'UTF-8');

        // Remove leading articles
        $variants[] = preg_replace('/^(the|a|an)\s+/i', '', $title);

        // De-duplicate & drop empties
        $variants = array_values(array_unique(array_filter(array_map('trim', $variants))));
        return $variants;
    }

    /** Try several places to get a plausible release year. */
    private function guess_release_year(int $post_id, string $raw_title): ?int {
        // 1) Known meta keys (overrideable)
        $meta_keys = (array) apply_filters('letterboxd_tmdb_year_meta_keys', [
            'release_year',
            'year',
            'wpcf-release_year',
            'wpcf-year',
            'movie_year',
            'letterboxd_year',
            'tmdb_release_date', // will parse below if YYYY-MM-DD
        ]);
        foreach ($meta_keys as $key) {
            $val = get_post_meta($post_id, $key, true);
            if ($val) {
                if (preg_match('/\b(19|20)\d{2}\b/', (string) $val, $m)) {
                    return (int) $m[0];
                }
            }
        }

        // 2) Parse a year out of the title if present, e.g. "Birth (2004)"
        if (preg_match('/\b(19|20)\d{2}\b/', $raw_title, $m)) {
            return (int) $m[0];
        }

        // 3) Look in excerpt / content / slug
        $post = get_post($post_id);
        if ($post) {
            $candidates = [$post->post_excerpt, $post->post_content, $post->post_name];
            foreach ($candidates as $text) {
                if ($text && preg_match('/\b(19|20)\d{2}\b/', $text, $m)) {
                    return (int) $m[0];
                }
            }
        }

        // 4) As a last resort, try the Letterboxd date meta if you store it
        $lb_date = get_post_meta($post_id, 'letterboxd_watched_date', true);
        if ($lb_date && preg_match('/\b(19|20)\d{2}\b/', (string) $lb_date, $m)) {
            return (int) $m[0];
        }

        return null;
    }

    /**
     * Call TMDB /search/movie using make_api_request().
     * Returns array or WP_Error.
     */
    private function tmdb_search_movie(string $query, ?int $year = null, string $language = 'en-US') {
        $params = [
            'query'         => $query,
            'include_adult' => true,
            'language'      => $language,
            'page'          => 1,
        ];
        if (!empty($year)) {
            // TMDB prefers this for search narrowing
            $params['primary_release_year'] = (int)$year;
        }

        $url = '/search/movie';
        // letterboxd_debug_log('[API] SEARCH ' . $url . ' q=' . $query . ' y=' . (int)$year);
        return $this->make_api_request($url, $params);
    }

    /** Resolve via /find when we have an IMDb ID. */
    private function tmdb_find_by_imdb(string $imdb_id): ?int {
        // normalize e.g. "tt1234567"
        $imdb_id = trim($imdb_id);
        if (!preg_match('/^tt\d+$/', $imdb_id)) {
            return null;
        }

        $data = $this->make_api_request('/find/' . rawurlencode($imdb_id), [
            'external_source' => 'imdb_id',
            'language'        => 'en-US',
        ]);
        if (is_wp_error($data) || !is_array($data)) {
            return null;
        }
        $movies = (array) ($data['movie_results'] ?? []);
        if (!empty($movies) && !empty($movies[0]['id'])) {
            return (int) $movies[0]['id'];
        }
        return null;
    }

    /**
     * Collect the best guess for title/year + a few alternates from post meta.
     * @return array [title, year|null, altTitles[]]
     */
    private function extract_search_inputs_for_post(int $post_id): array {
        // Raw title (decode HTML entities & curly quotes -> ASCII)
        $rawTitle = get_the_title($post_id) ?: '';
        $title = $this->normalize_query_string($rawTitle);

        // Try to pull a year from common meta keys or "(YYYY)" in the title
        $year = 0;
        $metaKeys = [
            'release_year', 'year', 'wpcf-year', 'letterboxd_year',
            'tmdb_release_date', 'release_date',
        ];
        foreach ($metaKeys as $k) {
            $v = get_post_meta($post_id, $k, true);
            if (!empty($v)) {
                if (preg_match('/^\s*(\d{4})\b/', (string)$v, $m)) { $year = (int)$m[1]; break; }
                if (preg_match('/\b(\d{4})\b/', (string)$v, $m)) { $year = (int)$m[1]; break; }
            }
        }
        if (!$year && preg_match('/\((\d{4})\)/', (string)$rawTitle, $m)) {
            $year = (int)$m[1];
        }
        if (!$year) { $year = 0; } // keep null-ish semantics below

        // Alternate titles from meta (adjust to your site’s keys as needed)
        $alts = array_filter([
            get_post_meta($post_id, 'original_title', true),
            get_post_meta($post_id, 'alt_title', true),
            get_post_meta($post_id, 'wpcf-original-title', true),
            get_post_meta($post_id, 'letterboxd_title', true),
            get_post_meta($post_id, 'imdb_title', true),
        ]);

        // Also consider a de-slugged post_name if it looks useful
        $slug = get_post_field('post_name', $post_id);
        if ($slug && !preg_match('/^\d+$/', $slug)) {
            $alts[] = ucwords(trim(str_replace('-', ' ', $slug)));
        }

        // Clean alt titles for querying
        $altClean = array_values(array_unique(array_map([$this, 'normalize_query_string'], $alts)));

        return [$title, $year ?: null, $altClean];
    }

    /**
     * Normalize a title string for TMDB query (decode entities & simplify quotes),
     * but keep punctuation so TMDB search still benefits from it.
     */
    private function normalize_query_string(string $s): string {
        $s = wp_specialchars_decode($s, ENT_QUOTES);
        $s = html_entity_decode($s, ENT_QUOTES, 'UTF-8');
        $map = [
            '’' => "'", '‘' => "'", '´' => "'", '`' => "'",
            '“' => '"', '”' => '"', '–' => '-', '—' => '-',
            '&' => 'and',
        ];
        $s = strtr($s, $map);
        // Collapse whitespace
        $s = preg_replace('/\s+/u', ' ', $s);
        return trim($s);
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
     * Ensure tmdb_id exists (search if missing), fetch details in batches,
     * and write all extracted TMDB meta to each post.
     */
    public function batch_update_movie_metadata(array $post_ids, string $region = 'US'): array {
        $results = ['updated' => 0, 'failed' => 0, 'skipped' => 0, 'errors' => []];

        // letterboxd_debug_log('[META] Starting batch_update_movie_metadata for ' . count($post_ids) . ' posts; region=' . $region);

        if (!$this->is_api_key_configured()) {
            $msg = '[META] Abort: TMDB API key is not configured.';
            // letterboxd_debug_log($msg);
            return ['updated' => 0, 'failed' => count($post_ids), 'skipped' => 0, 'errors' => [$msg]];
        }

        // Allow sites to define additional meta keys that may already contain the TMDB ID.
        $id_meta_keys = (array) apply_filters('letterboxd_tmdb_id_meta_keys', ['tmdb_id', 'tmdb_movie_id']);

        // In-batch cache to avoid re-searching the same title/year repeatedly.
        $search_cache = [];

        // 1) Map posts -> tmdb_id (find or backfill)
        $post_to_tmdb = [];
        foreach ($post_ids as $post_id) {
            // Look for an existing TMDB ID in any of the allowed keys.
            $tmdb_id = 0;
            foreach ($id_meta_keys as $key) {
                $val = (int) get_post_meta($post_id, $key, true);
                if ($val) { $tmdb_id = $val; break; }
            }

            if (!$tmdb_id) {
                // Build a stable cache key so we don't hit TMDB for identical queries in this batch.
                $raw_title = get_the_title($post_id) ?: '';
                $cache_key = md5($raw_title . '|' . (string) $post_id); // include post_id to be conservative

                if (isset($search_cache[$cache_key])) {
                    $tmdb_id = (int) $search_cache[$cache_key];
                    // letterboxd_debug_log("[META] Cache hit for post {$post_id}: tmdb_id={$tmdb_id}");
                } else {
                    // letterboxd_debug_log("[META] No tmdb_id for post {$post_id}. Attempting resolve_tmdb_id_for_post...");
                    $tmdb_id = (int) $this->resolve_tmdb_id_for_post($post_id, $region);
                    // letterboxd_debug_log("[META] Resolver result for post {$post_id}: tmdb_id={$tmdb_id}");
                    $search_cache[$cache_key] = $tmdb_id;
                }

                if ($tmdb_id) {
                    // Persist to the primary key and mirror to alternates.
                    update_post_meta($post_id, 'tmdb_id', $tmdb_id);
                    foreach ($id_meta_keys as $mirror_key) {
                        if ($mirror_key !== 'tmdb_id') {
                            update_post_meta($post_id, $mirror_key, $tmdb_id);
                        }
                    }
                    // Nicety: record where it came from + timestamp
                    update_post_meta($post_id, 'tmdb_id_source', 'search');
                    update_post_meta($post_id, 'tmdb_last_sync', current_time('mysql'));
                }
            } else {
                // letterboxd_debug_log("[META] Found existing tmdb_id={$tmdb_id} for post {$post_id}");
                // Nicety: mark provenance if not already set
                if (!get_post_meta($post_id, 'tmdb_id_source', true)) {
                    update_post_meta($post_id, 'tmdb_id_source', 'existing');
                }
            }

            if ($tmdb_id) {
                $post_to_tmdb[$post_id] = (int) $tmdb_id;
            } else {
                $results['skipped']++;
            }
        }

        // letterboxd_debug_log('[META] Posts with TMDB IDs: ' . count($post_to_tmdb) . '; skipped (no id): ' . $results['skipped']);
        if (!$post_to_tmdb) { return $results; }

        // 2) Fetch details in chunks and write meta
        $tmdb_ids = array_values(array_unique(array_map('intval', array_values($post_to_tmdb))));
        $chunks   = array_chunk($tmdb_ids, 5);

        foreach ($chunks as $chunk) {
            // letterboxd_debug_log('[META] Fetching details for TMDB IDs: ' . implode(',', $chunk));
            $details_map = $this->batch_get_movie_details($chunk);

            foreach ($details_map as $tmdb_id => $movie_data) {
                $post_ids_for_tmdb = array_keys($post_to_tmdb, (int) $tmdb_id, true);

                if (is_wp_error($movie_data)) {
                    $msg = "[META] Details error for TMDB {$tmdb_id}: " . $movie_data->get_error_message();
                    $results['errors'][] = $msg;
                    // letterboxd_debug_log($msg);
                    foreach ($post_ids_for_tmdb as $pid) { $results['failed']++; }
                    continue;
                }

                // letterboxd_debug_log("[META] Got details for TMDB {$tmdb_id}; extracting metadata");
                $meta = $this->extract_movie_metadata($movie_data, $region);
                // letterboxd_debug_log('[META] Extracted keys: ' . implode(',', array_keys($meta)));

                foreach ($post_ids_for_tmdb as $pid) {
                    try {
                        foreach ($meta as $k => $v) {
                            update_post_meta($pid, $k, $v);
                        }
                        // Update sync time (nicety) even when ID was pre-existing.
                        update_post_meta($pid, 'tmdb_last_sync', current_time('mysql'));

                        // probe read-back
                        $probe = get_post_meta($pid, 'tmdb_title', true);
                        // letterboxd_debug_log("[META] Wrote meta for post {$pid}; tmdb_title='" . (string) $probe . "'");
                        $results['updated']++;
                    } catch (\Throwable $e) {
                        $results['failed']++;
                        $msg = "Meta write error for post {$pid} (TMDB {$tmdb_id}): " . $e->getMessage();
                        $results['errors'][] = $msg;
                        // letterboxd_debug_log('[META] ' . $msg);
                    }
                }
            }

            if (count($chunks) > 1) {
                // letterboxd_debug_log('[META] Sleep 500ms between chunks');
                usleep(500000);
            }
        }

        // letterboxd_debug_log("[META] Done. updated={$results['updated']} failed={$results['failed']} skipped={$results['skipped']}");
        return $results;
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
            // letterboxd_debug_log( "TMDB API key not configured for streaming provider update" );
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
                $tmdb_id = (int) $tmdb_id;
                $tmdb_ids[] = $tmdb_id;
                if (!isset($post_tmdb_map[$tmdb_id])) {
                    $post_tmdb_map[$tmdb_id] = [];
                }
                $post_tmdb_map[$tmdb_id][] = $post_id;
            } else {
                $results["failed"]++;
                // letterboxd_debug_log("No TMDB ID for post {$post_id}");
            }
        }

        if (empty($tmdb_ids)) {
            return $results;
        }

        try {
            // Process in chunks to avoid timeouts (max 5 at a time)
            // De-duplicate TMDB IDs so we don't fetch the same ID multiple times.
            $unique_tmdb_ids  = array_values(array_unique(array_map('intval', $tmdb_ids)));
            $tmdb_id_chunks   = array_chunk($unique_tmdb_ids, 5);
        
            foreach ($tmdb_id_chunks as $chunk) {
                // Batch get streaming providers
                $providers_data = $this->batch_get_streaming_providers($chunk, $region);
        
                // Update post meta for each movie
                foreach ($providers_data as $tmdb_id => $providers) {
                    $tmdb_id = (int) $tmdb_id;
        
                    // Map may contain multiple posts for the same TMDB ID
                    $post_ids_for_tmdb = $post_tmdb_map[$tmdb_id] ?? [];
                    if (empty($post_ids_for_tmdb)) {
                        $results['failed']++;
                        continue;
                    }
        
                    if (empty($providers) || is_wp_error($providers)) {
                        $results['failed'] += count($post_ids_for_tmdb);
                        $error_msg = is_wp_error($providers)
                            ? $providers->get_error_message()
                            : "No providers for TMDB ID {$tmdb_id}";
                        $results['errors'][] = $error_msg;
                        // letterboxd_debug_log($error_msg);
                        continue;
                    }
        
                    // Format once per TMDB ID then write to all mapped posts
                    try {
                        $formatted_providers = $this->format_streaming_providers($providers);
                    } catch (Exception $e) {
                        $results['failed'] += count($post_ids_for_tmdb);
                        $error_msg = "Formatting providers failed for TMDB ID {$tmdb_id}: " . $e->getMessage();
                        $results['errors'][] = $error_msg;
                        // letterboxd_debug_log($error_msg);
                        continue;
                    }
        
                    foreach ($post_ids_for_tmdb as $post_id) {
                        try {
                            update_post_meta(
                                $post_id,
                                'streaming_providers',
                                wp_json_encode($formatted_providers)
                            );
                            update_post_meta(
                                $post_id,
                                'streaming_link',
                                $providers['link'] ?? ''
                            );
                            update_post_meta(
                                $post_id,
                                'streaming_providers_updated',
                                current_time('mysql')
                            );
        
                            $results['updated']++;
                        } catch (Exception $e) {
                            $results['failed']++;
                            $error_msg = "Error processing TMDB ID {$tmdb_id} for post {$post_id}: " . $e->getMessage();
                            $results['errors'][] = $error_msg;
                            // letterboxd_debug_log($error_msg);
                        }
                    }
                }
        
                // Small pause between chunks to avoid overloading the server
                if (count($tmdb_id_chunks) > 1) {
                    usleep(500000); // 500ms pause
                }
            }
        } catch (Exception $e) {
            // letterboxd_debug_log("Exception during batch update: " . $e->get_message());
            $results["errors"][] = "Exception during batch update: " . $e->getMessage();
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