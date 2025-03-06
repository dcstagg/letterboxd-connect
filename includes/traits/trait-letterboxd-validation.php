<?php
/**
 * Validation functionality for Letterboxd plugin
 *
 * @package letterboxd-wordpress
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

trait LetterboxdValidation {
    /**
     * Enhanced username validation
     * 
     * @param string $username The username to validate
     * @return bool|WP_Error Returns true if valid, WP_Error if invalid
     */
    private function validate_letterboxd_username($username) {
        // Remove any whitespace
        $username = trim($username);

        // Basic checks
        if (empty($username)) {
            return new WP_Error(
                'invalid_username',
                __('Username cannot be empty.', 'letterboxd-wordpress')
            );
        }

        // Letterboxd username rules:
        // - Must be between 2 and 15 characters
        // - Can only contain letters, numbers, hyphens
        // - Cannot start or end with a hyphen
        if (strlen($username) < 2 || strlen($username) > 15) {
            return new WP_Error(
                'invalid_username_length',
                __('Username must be between 2 and 15 characters.', 'letterboxd-wordpress')
            );
        }

        if (!preg_match('/^[a-zA-Z0-9](?:[a-zA-Z0-9-]*[a-zA-Z0-9])?$/', $username)) {
            return new WP_Error(
                'invalid_username_format',
                __('Username can only contain letters, numbers, and hyphens, and cannot start or end with a hyphen.', 'letterboxd-wordpress')
            );
        }

        // Additional checks for username
        if (preg_match('/--/', $username)) {
            return new WP_Error(
                'invalid_username_format',
                __('Username cannot contain consecutive hyphens.', 'letterboxd-wordpress')
            );
        }

        if (preg_match('/[A-Z]/', $username)) {
            return new WP_Error(
                'invalid_username_case',
                __('Username must be lowercase.', 'letterboxd-wordpress')
            );
        }

        return true;
    }

    /**
     * Enhanced date validation
     * 
     * @param string $date The date to validate
     * @return bool|WP_Error Returns true if valid, WP_Error if invalid
     */
    private function validate_date($date) {
        if (empty($date)) {
            return true;
        }

        // Check format
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return new WP_Error(
                'invalid_date_format',
                __('Date must be in YYYY-MM-DD format.', 'letterboxd-wordpress')
            );
        }

        // Validate date components
        $parts = explode('-', $date);
        if (!checkdate($parts[1], $parts[2], $parts[0])) {
            return new WP_Error(
                'invalid_date',
                __('The provided date is not valid.', 'letterboxd-wordpress')
            );
        }

        // Check if date is not in future
        if (strtotime($date) > time()) {
            return new WP_Error(
                'future_date',
                __('The date cannot be in the future.', 'letterboxd-wordpress')
            );
        }

        return true;
    }

    /**
     * Validate import limit
     * 
     * @param int $limit The limit to validate
     * @return bool|WP_Error Returns true if valid, WP_Error if invalid
     */
    private function validate_import_limit($limit) {
        $limit = intval($limit);
        if ($limit < 1 || $limit > 100) {
            return new WP_Error(
                'invalid_import_limit',
                __('Import limit must be between 1 and 100.', 'letterboxd-wordpress')
            );
        }

        return true;
    }
}