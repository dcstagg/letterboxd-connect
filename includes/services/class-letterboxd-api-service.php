<?php
/**
 * Letterboxd API Service implementation
 *
 * @package LetterboxdToWordPress
 * @since 1.1.0
 */
class LetterboxdApiService implements LetterboxdApiServiceInterface {
	/**
	 * Validate date format and range
	 *
	 * @param string $date Date string to validate
	 * @return bool Whether the date is valid
	 */
	public function validateDate(string $date): bool {
		if (empty($date)) {
			return true;
		}

		$timestamp = strtotime($date);
		return $timestamp !== false &&
			$timestamp <= time() &&
			preg_match('/^\d{4}-\d{2}-\d{2}$/', $date);
	}

	/**
	 * Username constraints
	 */
	public const USERNAME_MIN_LENGTH = 2;
	public const USERNAME_MAX_LENGTH = 15;
	public const USERNAME_PATTERN = '/^[a-z0-9][a-z0-9-]*[a-z0-9]$/';

	/**
	 * Validates a Letterboxd username
	 *
	 * @param string $username The username to validate
	 * @return bool|WP_Error True if valid, WP_Error if invalid
	 */
	public function validateUsername(string $username): bool|WP_Error {
		$username = trim($username);

		if (empty($username)) {
			return new WP_Error(
				"empty_username",
				__("Username cannot be empty.", "letterboxd-wordpress")
			);
		}

		if (
			strlen($username) < self::USERNAME_MIN_LENGTH ||
			strlen($username) > self::USERNAME_MAX_LENGTH
		) {
			return new WP_Error(
				"invalid_length",
				sprintf(
					__(
						"Username must be between %d and %d characters.",
						"letterboxd-wordpress"
					),
					self::USERNAME_MIN_LENGTH,
					self::USERNAME_MAX_LENGTH
				)
			);
		}

		if (!preg_match(self::USERNAME_PATTERN, $username)) {
			return new WP_Error(
				"invalid_format",
				__(
					"Username can only contain lowercase letters, numbers, and hyphens. It cannot start or end with a hyphen.",
					"letterboxd-wordpress"
				)
			);
		}

		return true;
	}

	/**
	 * Checks if a TMDB API key is valid
	 *
	 * @param string $api_key The API key to validate
	 * @return bool|WP_Error True if valid, WP_Error if invalid
	 */
	public function checkTmdbApiKey(string $api_key): bool|WP_Error {
		if (empty($api_key)) {
			return new WP_Error(
				"empty_api_key",
				__("API key cannot be empty.", "letterboxd-wordpress")
			);
		}

		$response = wp_remote_get(
			"https://api.themoviedb.org/3/configuration?api_key=" . $api_key,
			[
				"timeout" => 15,
				"sslverify" => true,
			]
		);

		if (is_wp_error($response)) {
			return $response;
		}

		$response_code = wp_remote_retrieve_response_code($response);

		if ($response_code !== 200) {
			$body = wp_remote_retrieve_body($response);
			$data = json_decode($body, true);
			$error_message = isset($data["status_message"])
				? $data["status_message"]
				: "Unknown error";

			return new WP_Error(
				"tmdb_api_error",
				sprintf(
					__("API error (%d): %s", "letterboxd-wordpress"),
					$response_code,
					$error_message
				)
			);
		}

		return true;
	}

	/**
	 * Creates a TMDB request token
	 *
	 * @param string $api_key TMDB API key
	 * @return array|WP_Error Response data or error
	 */
	public function createTmdbRequestToken(string $api_key): array|WP_Error {
		if (empty($api_key)) {
			return new WP_Error(
				"empty_api_key",
				__("API key is not configured.", "letterboxd-wordpress")
			);
		}

		$response = wp_remote_get(
			"https://api.themoviedb.org/3/authentication/token/new?api_key={$api_key}",
			["timeout" => 15]
		);

		if (is_wp_error($response)) {
			return $response;
		}

		$body = json_decode(wp_remote_retrieve_body($response), true);

		if (empty($body["success"]) || empty($body["request_token"])) {
			return new WP_Error(
				"tmdb_token_error",
				$body["status_message"] ??
					__(
						"Failed to create request token.",
						"letterboxd-wordpress"
					)
			);
		}

		return [
			"success" => true,
			"request_token" => $body["request_token"],
		];
	}

	/**
	 * Creates a TMDB session with an authorized request token
	 *
	 * @param string $api_key TMDB API key
	 * @param string $request_token Authorized request token
	 * @return array|WP_Error Response data or error
	 */
	public function createTmdbSession(
		string $api_key,
		string $request_token
	): array|WP_Error {
		if (empty($api_key)) {
			return new WP_Error(
				"empty_api_key",
				__("API key is not configured.", "letterboxd-wordpress")
			);
		}

		$response = wp_remote_post(
			"https://api.themoviedb.org/3/authentication/session/new?api_key={$api_key}",
			[
				"body" => json_encode(["request_token" => $request_token]),
				"headers" => ["Content-Type" => "application/json"],
				"timeout" => 15,
			]
		);

		if (is_wp_error($response)) {
			return $response;
		}

		$body = json_decode(wp_remote_retrieve_body($response), true);

		if (empty($body["success"]) || empty($body["session_id"])) {
			return new WP_Error(
				"tmdb_session_error",
				$body["status_message"] ??
					__("Failed to create session.", "letterboxd-wordpress")
			);
		}

		return [
			"success" => true,
			"session_id" => $body["session_id"],
		];
	}
}