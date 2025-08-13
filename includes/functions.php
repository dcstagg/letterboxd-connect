<?php
/**
 * Helper functions for the Letterboxd Connect plugin
 *
 * @package letterboxd-connect
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Register Letterboxd block category
 */
function letterboxd_register_block_category($categories, $post) {
     return array_merge($categories, [
         [
             'slug' => 'letterboxd-blocks',
             'title' => __('Letterboxd', 'letterboxd-connect'),
             'icon' => 'video-alt2'
         ]
     ]);
 }
 if (version_compare(get_bloginfo('version'), '5.8', '>=')) {
     add_filter('block_categories_all', 'letterboxd_register_block_category', 10, 2);
 } else {
     add_filter('block_categories', 'letterboxd_register_block_category', 10, 2);
 }

/**
* Register block assets and scripts
*/
function letterboxd_register_block_assets() {
    if (!function_exists('register_block_type')) {
        return;
    }
    
    $plugin_dir = plugin_dir_path(LETTERBOXD_PLUGIN_FILE);
    $plugin_url = plugin_dir_url(LETTERBOXD_PLUGIN_FILE);
    
    if (!file_exists($plugin_dir . 'js/movie-block.js')) {
        // letterboxd_debug_log('Movie block JS file not found at: ' . $plugin_dir . 'js/movie-block.js', 'Block_Assets');
        return;
    }
    
    wp_register_script(
        'letterboxd-blocks',
        $plugin_url . 'js/movie-block.js',
        [
            'wp-blocks',
            'wp-element',
            'wp-editor',
            'wp-components',
            'wp-i18n',
            'wp-block-editor',
            'wp-server-side-render',
            'lodash'
        ],
        LETTERBOXD_VERSION,
        true
    );
    
    wp_localize_script('letterboxd-blocks', 'letterboxdBlockData', [
        'pluginUrl' => $plugin_url
    ]);
}

/**
 * Clean up any temporary files created during import
 */
function letterboxd_cleanup_temp_files() {
    $upload_dir = wp_upload_dir();
    $temp_dir = $upload_dir['basedir'] . '/letterboxd-temp';
    
    if (is_dir($temp_dir)) {
        // Initialize filesystem
        global $wp_filesystem;
        if (empty($wp_filesystem)) {
            require_once(ABSPATH . '/wp-admin/includes/file.php');
            WP_Filesystem();
        }
        
        $files = glob($temp_dir . '/*');
        foreach ($files as $file) {
            if (is_file($file) && time() - filemtime($file) > 3600) {
                $wp_filesystem->delete($file);
            }
        }
    }
    
}