<?php
/**
 * Interface for Letterboxd API service
 * 
 * @package LetterboxdToWordPress
 * @since 1.1.0
 */
interface LetterboxdApiServiceInterface {
	// In interface-letterboxd-api-service.php
	/**
	 * Validates a date format
	 * 
	 * @param string $date Date string to validate
	 * @return bool Whether the date is valid
	 */
	public function validateDate(string $date): bool;
	
	/**
	 * Validates a Letterboxd username
	 * 
	 * @param string $username The username to validate
	 * @return bool|WP_Error True if valid, WP_Error if invalid
	 */
	public function validateUsername(string $username): bool|WP_Error;
	
	/**
	 * Checks if a TMDB API key is valid
	 * 
	 * @param string $api_key The API key to validate
	 * @return bool|WP_Error True if valid, WP_Error if invalid
	 */
	public function checkTmdbApiKey(string $api_key): bool|WP_Error;
	
	/**
	 * Creates a TMDB request token
	 * 
	 * @param string $api_key TMDB API key
	 * @return array|WP_Error Response data or error
	 */
	public function createTmdbRequestToken(string $api_key): array|WP_Error;
	
	/**
	 * Creates a TMDB session with an authorized request token
	 * 
	 * @param string $api_key TMDB API key
	 * @param string $request_token Authorized request token
	 * @return array|WP_Error Response data or error
	 */
	public function createTmdbSession(string $api_key, string $request_token): array|WP_Error;
}