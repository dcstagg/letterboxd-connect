<?php
/**
 * Error handling functionality for Letterboxd plugin
 *
 * @package LetterboxdToWordPress
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

trait LetterboxdErrorHandling {
    
    /**
     * @var array Stores errors that occur during processing
     */
    private $errors = [];

    /**
     * Enhanced error logging with severity levels
     */
    private function log_error($message, $severity = self::SEVERITY_ERROR, $context = []): void {
        $error = [
            'timestamp' => current_time('mysql'),
            'severity' => $severity,
            'message' => $message,
            'context' => $context
        ];
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $error['debug'] = [
                'memory_usage' => memory_get_usage(true),
                'backtrace' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3)
            ];
        }
        
        $this->errors[] = $error;
        
        // Keep only last 50 errors
        if (count($this->errors) > 50) {
            array_shift($this->errors);
        }
        
        update_option('letterboxd_error_log', $this->errors);
    }

    /**
     * Get all errors that occurred during processing
     * 
     * @return array Array of errors
     */
    public function get_errors() {
        return $this->errors;
    }

    /**
     * Clear all stored errors
     */
    public function clear_errors() {
        $this->errors = [];
        delete_option('letterboxd_error_log');
    }

    /**
     * Display admin notices for errors
     */
    public function display_admin_notices() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $screen = get_current_screen();
        if ($screen->id !== 'settings_page_letterboxd-wordpress') {
            return;
        }

        $errors = $this->get_errors();
        foreach ($errors as $error) {
            $class = 'notice notice-' . ($error['severity'] === 'error' ? 'error' : 'warning');
            printf(
                '<div class="%1$s"><p>%2$s</p></div>',
                esc_attr($class),
                esc_html($error['message'])
            );
        }
    }
}