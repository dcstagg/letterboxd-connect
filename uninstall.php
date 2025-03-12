<?php
/**
 * Uninstall script for Letterboxd Connect plugin
 * 
 * This file will be called automatically when the plugin is uninstalled through WordPress.
 * It cleans up all plugin-related data from the database.
 *
 * @package letterboxd-connect
 */

// Exit if not called by WordPress during uninstall
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Define plugin constants if not already defined
if (!defined('LETTERBOXD_VERSION')) {
    define('LETTERBOXD_VERSION', '1.0.0');
}

/**
 * Main cleanup class
 */
class Letterboxd_Uninstaller {

    private function validate_uninstall_context(): bool {
        if (!defined('WP_UNINSTALL_PLUGIN')) {
            return false;
        }
        if (!current_user_can('activate_plugins')) {
            return false;
        }
        return true;
    }
    
    /**
     * Plugin options and transients
     */
    private const OPTIONS = [
        // Main plugin options
        'letterboxd_wordpress_options',
        'letterboxd_auto_import_options',
        
        // Statistics and logs
        'letterboxd_last_import',
        'letterboxd_last_check',
        'letterboxd_last_import_date',
        'letterboxd_imported_count',
        'letterboxd_last_error',
        'letterboxd_import_log',
        'letterboxd_error_log'
    ];

    /**
     * Transient prefixes to clean
     */
    private const TRANSIENT_PREFIXES = [
        'letterboxd_import_lock',
        'letterboxd_cache',
        'letterboxd_block'
    ];

    /**
     * Post meta keys
     */
    private const POST_META_KEYS = [
        'letterboxd_url',
        'watch_date',
        'poster_url'
    ];

    /**
     * Run the uninstaller
     */
    public static function uninstall(): void {
        $uninstaller = new self();
        
        // Perform cleanup in specific order
        $uninstaller->remove_attachments();
        $uninstaller->remove_post_type();
        $uninstaller->remove_taxonomies();
        $uninstaller->remove_options();
        $uninstaller->remove_transients();
        $uninstaller->cleanup_uploads();
        $uninstaller->clear_caches();
    }

    /**
     * Remove all attachments associated with movie posts
     */
    private function remove_attachments(): void {
        global $wpdb;
        
        // Get all movie post IDs
        $movie_ids = get_posts([
            'post_type' => 'movie',
            'numberposts' => -1,
            'post_status' => 'any',
            'fields' => 'ids'
        ]);
        
        if (empty($movie_ids)) {
            return;
        }
        
        // Get all featured images from these posts
        $thumbnail_ids = [];
        foreach ($movie_ids as $post_id) {
            $thumbnail_id = get_post_thumbnail_id($post_id);
            if ($thumbnail_id) {
                $thumbnail_ids[] = $thumbnail_id;
            }
        }
        
        // Get all attachments that are attached to movie posts
        $placeholders = implode(',', array_fill(0, count($movie_ids), '%d'));
        $attached_attachments = $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} 
            WHERE post_type = 'attachment' 
            AND post_parent IN ($placeholders)",
            $movie_ids
        ));
        
        // Merge unique attachment IDs
        $all_attachments = array_unique(array_merge($thumbnail_ids, $attached_attachments));
        
        // Delete each attachment and ensure metadata is removed
        foreach ($all_attachments as $attachment_id) {
            // Get the file paths before deleting the attachment
            $file_data = get_post_meta($attachment_id, '_wp_attached_file', true);
            $metadata = get_post_meta($attachment_id, '_wp_attachment_metadata', true);
            
            // Delete the attachment (should remove the post and postmeta)
            wp_delete_attachment($attachment_id, true);
            
            // Extra safety: manually delete any potentially orphaned metadata
            $wpdb->delete(
                $wpdb->postmeta,
                [
                    'post_id' => $attachment_id,
                    'meta_key' => '_wp_attached_file'
                ]
            );
            
            $wpdb->delete(
                $wpdb->postmeta,
                [
                    'post_id' => $attachment_id,
                    'meta_key' => '_wp_attachment_metadata'
                ]
            );
        }
        
        // Final cleanup: search for any orphaned attachment metadata
        $wpdb->query("
            DELETE FROM {$wpdb->postmeta}
            WHERE meta_key IN ('_wp_attached_file', '_wp_attachment_metadata')
            AND post_id NOT IN (
                SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment'
            )
        ");
    }

    /**
     * Remove all movie posts, revisions and associated meta
     */
    private function remove_post_type(): void {
        global $wpdb;

        // First, get all revisions associated with movie posts
        $movie_ids = get_posts([
            'post_type' => 'movie',
            'numberposts' => -1,
            'post_status' => 'any',
            'fields' => 'ids'
        ]);
        
        if (!empty($movie_ids)) {
            // Get all revisions for movie posts
            $placeholders = implode(',', array_fill(0, count($movie_ids), '%d'));
            $revisions = $wpdb->get_col($wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} 
                WHERE post_type = 'revision' 
                AND post_parent IN ($placeholders)",
                $movie_ids
            ));
            
            // Delete revision meta
            if (!empty($revisions)) {
                $rev_placeholders = implode(',', array_fill(0, count($revisions), '%d'));
                $wpdb->query($wpdb->prepare(
                    "DELETE FROM {$wpdb->postmeta} WHERE post_id IN ($rev_placeholders)",
                    $revisions
                ));
                
                // Delete revisions
                $wpdb->query($wpdb->prepare(
                    "DELETE FROM {$wpdb->posts} WHERE ID IN ($rev_placeholders)",
                    $revisions
                ));
            }
            
            // Remove post meta from movie posts
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$wpdb->postmeta} WHERE post_id IN ($placeholders)",
                $movie_ids
            ));
            
            // Remove movie posts
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$wpdb->posts} WHERE ID IN ($placeholders)",
                $movie_ids
            ));
            
            // Also get any remaining movie posts (in case the above missed any)
            $wpdb->query("DELETE FROM {$wpdb->posts} WHERE post_type = 'movie'");
            
            // Clean up any orphaned meta
            $wpdb->query("
                DELETE pm FROM {$wpdb->postmeta} pm
                LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                WHERE p.ID IS NULL
            ");
        }
    }

    /**
     * Remove movie taxonomies and terms
     */
    private function remove_taxonomies(): void {
        global $wpdb;

        $taxonomies = ['movie_year'];

        foreach ($taxonomies as $taxonomy) {
            // Get all terms for this taxonomy
            $terms = get_terms([
                'taxonomy' => $taxonomy,
                'hide_empty' => false,
                'fields' => 'ids'
            ]);

            if (!is_wp_error($terms) && !empty($terms)) {
                // Remove term relationships
                $placeholders = implode(',', array_fill(0, count($terms), '%d'));
                $query = "DELETE FROM {$wpdb->term_relationships} WHERE term_taxonomy_id IN ($placeholders)";
                // Prepare SQL with variable number of placeholders
                $prepared = call_user_func_array([$wpdb, 'prepare'], array_merge([$query], $terms));
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $prepared is already prepared above
                $wpdb->query($prepared);
            
                // Remove term taxonomy
                $query = "DELETE FROM {$wpdb->term_taxonomy} WHERE term_id IN ($placeholders)";
                // Prepare SQL with variable number of placeholders
                $prepared = call_user_func_array([$wpdb, 'prepare'], array_merge([$query], $terms));
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $prepared is already prepared above
                $wpdb->query($prepared);
            
                // Remove terms
                $query = "DELETE FROM {$wpdb->terms} WHERE term_id IN ($placeholders)";
                // Prepare SQL with variable number of placeholders
                $prepared = call_user_func_array([$wpdb, 'prepare'], array_merge([$query], $terms));
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $prepared is already prepared above
                $wpdb->query($prepared);
            }
        }
    }

    /**
     * Remove all plugin options
     */
    private function remove_options(): void {
        foreach (self::OPTIONS as $option) {
            delete_option($option);
        }
    }

    /**
     * Remove all plugin transients
     */
    private function remove_transients(): void {
        global $wpdb;
    
        // Remove known transients by prefix
        foreach (self::TRANSIENT_PREFIXES as $prefix) {
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$wpdb->options} 
                WHERE option_name LIKE %s OR option_name LIKE %s",
                "_transient_{$prefix}%",
                "_transient_timeout_{$prefix}%"
            ));
        }
    }

    /**
     * Clean up uploaded files and directories
     */
    private function cleanup_uploads(): void {
        // Get WordPress upload directory
        $upload_dir = wp_upload_dir();
        
        $directories = [
            $upload_dir['basedir'] . '/letterboxd-temp',
            $upload_dir['basedir'] . '/letterboxd-posters'
        ];
    
        // Remove each directory and its contents
        foreach ($directories as $dir) {
            if (is_dir($dir)) {
                $this->remove_directory($dir);
            }
        }
    }

    /**
     * Recursively remove a directory and its contents
     */
    private function remove_directory(string $dir): void {
        if (is_dir($dir)) {
            $objects = scandir($dir);
            
            // Initialize filesystem
            global $wp_filesystem;
            if (empty($wp_filesystem)) {
                require_once(ABSPATH . '/wp-admin/includes/file.php');
                WP_Filesystem();
            }
            
            foreach ($objects as $object) {
                if ($object !== '.' && $object !== '..') {
                    $path = $dir . '/' . $object;
                    if (is_dir($path)) {
                        $this->remove_directory($path);
                    } else {
                        $wp_filesystem->delete($path);
                    }
                }
            }
            $wp_filesystem->rmdir($dir);
        }
    }

    /**
     * Clear various WordPress caches
     */
    private function clear_caches(): void {
        // Clear object cache if external cache is used
        wp_cache_flush();
        
        // Clear rewrite rules
        delete_option('rewrite_rules');
        
        // Clear block cache
        if (function_exists('wp_cache_delete_multiple')) {
            wp_cache_delete_multiple(['letterboxd_block_patterns', 'letterboxd_block_cache']);
        }
    }
}

// Run the uninstaller
Letterboxd_Uninstaller::uninstall();