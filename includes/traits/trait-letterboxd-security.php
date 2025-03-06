<?php
/**
 * Security measures for Letterboxd plugin
 *
 * @package LetterboxdToWordPress
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

trait LetterboxdSecurity {
    /**
     * Verify nonce and user capabilities with additional security checks
     */
    private function verify_request($nonce_name, $action) {

        // rate limiting
        if ($this->is_rate_limited()) {
            return new WP_Error(
                'rate_limit_exceeded',
                __('Too many requests. Please try again later.', 'letterboxd-wordpress')
            );
        }

        // Check user capabilities first
        if (!current_user_can('manage_options')) {
            return new WP_Error(
                'insufficient_permissions',
                __('You do not have permission to perform this action.', 'letterboxd-wordpress')
            );
        }

        // Verify request method
        if (!isset($_SERVER['REQUEST_METHOD']) || 
            !in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'])) {
            return new WP_Error(
                'invalid_request_method',
                __('Invalid request method.', 'letterboxd-wordpress')
            );
        }

        // Check referer
        if (!check_admin_referer($action, $nonce_name)) {
            return new WP_Error(
                'invalid_nonce',
                __('Security check failed. Please refresh the page and try again.', 'letterboxd-wordpress')
            );
        }

        return true;
    }

    /**
     * Verify AJAX nonce
     *
     * @throws Exception If nonce verification fails
     */
    private function verify_ajax_nonce(): void {
        if (!check_ajax_referer('letterboxd_ajax_action', 'nonce', false)) {
            throw new Exception(
                __('Invalid security token. Please refresh the page.', 'letterboxd-wordpress')
            );
        }

        // Additional AJAX security checks
        if (!wp_doing_ajax()) {
            throw new Exception(
                __('Invalid request method.', 'letterboxd-wordpress')
            );
        }
    }

    /**
     * Enhanced sanitization and validation of options
     */
    private function sanitize_options($options) {
        $sanitized = [];

        // Username
        if (isset($options['username'])) {
            $username = sanitize_text_field($options['username']);
            $validation = $this->validate_letterboxd_username($username);
            if (!is_wp_error($validation)) {
                $sanitized['username'] = $username;
            }
        }

        // Start date
        if (isset($options['start_date'])) {
            $date = sanitize_text_field($options['start_date']);
            $validation = $this->validate_date($date);
            if (!is_wp_error($validation)) {
                $sanitized['start_date'] = $date;
            }
        }

        // Draft status
        $sanitized['draft_status'] = isset($options['draft_status']);

        // Import limit
        if (isset($options['import_limit'])) {
            $limit = absint($options['import_limit']);
            $validation = $this->validate_import_limit($limit);
            if (!is_wp_error($validation)) {
                $sanitized['import_limit'] = $limit;
            }
        }
        
        if (isset($options['email'])) {
            $sanitized['email'] = sanitize_email($options['email']);
        }

        if (isset($options['url'])) {
            $sanitized['url'] = esc_url_raw($options['url']);
        }

        // Validate and sanitize arrays
        if (isset($options['settings']) && is_array($options['settings'])) {
            $sanitized['settings'] = array_map('sanitize_text_field', $options['settings']);
        }

        return $sanitized;
    }
    
    /**
     * Check if request is rate limited
     */
    private function is_rate_limited(): bool {
        // Skip rate limiting in debugging environment
        if (defined('WP_DEBUG') && WP_DEBUG) {
            return false;
        }
        
        $rate_key = 'letterboxd_rate_limit';
        $rate_window = 60; // 1 minute window
        $max_requests = 10; // Max requests per window
        
        $current_requests = (int) get_transient($rate_key);
        
        if ($current_requests >= $max_requests) {
            return true;
        }
        
        if ($current_requests === 0) {
            set_transient($rate_key, 1, $rate_window);
        } else {
            set_transient($rate_key, $current_requests + 1, $rate_window);
        }
        
        return false;
    }
    
}