<?php
/**
 * Plugin Name: Ikabud Cache Invalidation
 * Description: Automatically clears cache when content is updated
 * Version: 1.0.0
 * Author: Ikabud Kernel
 */

// Get instance ID from environment
$instance_id = defined('IKABUD_INSTANCE_ID') ? IKABUD_INSTANCE_ID : null;

if (!$instance_id) {
    return; // Can't clear cache without instance ID
}

// Initialize cache
require_once dirname(dirname(dirname(__FILE__))) . '/kernel/Cache.php';
$ikabud_cache = new \IkabudKernel\Core\Cache();

/**
 * Clear cache when posts are published or updated
 */
add_action('save_post', function($post_id, $post, $update) use ($ikabud_cache, $instance_id) {
    // Don't clear cache for autosaves or revisions
    if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
        return;
    }
    
    // Only clear for published posts
    if ($post->post_status !== 'publish') {
        return;
    }
    
    // Clear entire instance cache (simple approach)
    $ikabud_cache->clear($instance_id);
    
    // Log cache clear
    error_log("Ikabud Cache: Cleared cache for instance {$instance_id} after post {$post_id} update");
}, 10, 3);

/**
 * Clear cache when posts are deleted
 */
add_action('delete_post', function($post_id) use ($ikabud_cache, $instance_id) {
    $ikabud_cache->clear($instance_id);
    error_log("Ikabud Cache: Cleared cache for instance {$instance_id} after post {$post_id} deletion");
});

/**
 * Clear cache when comments are posted/updated/deleted
 */
add_action('comment_post', function($comment_id, $approved) use ($ikabud_cache, $instance_id) {
    if ($approved === 1) {
        $ikabud_cache->clear($instance_id);
        error_log("Ikabud Cache: Cleared cache for instance {$instance_id} after comment {$comment_id} posted");
    }
}, 10, 2);

add_action('edit_comment', function($comment_id) use ($ikabud_cache, $instance_id) {
    $ikabud_cache->clear($instance_id);
    error_log("Ikabud Cache: Cleared cache for instance {$instance_id} after comment {$comment_id} edited");
});

add_action('delete_comment', function($comment_id) use ($ikabud_cache, $instance_id) {
    $ikabud_cache->clear($instance_id);
    error_log("Ikabud Cache: Cleared cache for instance {$instance_id} after comment {$comment_id} deleted");
});

/**
 * Clear cache when theme is switched
 */
add_action('switch_theme', function($new_name, $new_theme) use ($ikabud_cache, $instance_id) {
    $ikabud_cache->clear($instance_id);
    error_log("Ikabud Cache: Cleared cache for instance {$instance_id} after theme switch to {$new_name}");
}, 10, 2);

/**
 * Clear cache when widgets are updated
 */
add_action('update_option_sidebars_widgets', function($old_value, $value) use ($ikabud_cache, $instance_id) {
    $ikabud_cache->clear($instance_id);
    error_log("Ikabud Cache: Cleared cache for instance {$instance_id} after widgets updated");
}, 10, 2);

/**
 * Clear cache when menus are updated
 */
add_action('wp_update_nav_menu', function($menu_id) use ($ikabud_cache, $instance_id) {
    $ikabud_cache->clear($instance_id);
    error_log("Ikabud Cache: Cleared cache for instance {$instance_id} after menu {$menu_id} updated");
});

/**
 * Clear cache when options are updated (site title, tagline, etc.)
 */
add_action('update_option_blogname', function() use ($ikabud_cache, $instance_id) {
    $ikabud_cache->clear($instance_id);
    error_log("Ikabud Cache: Cleared cache for instance {$instance_id} after site title updated");
});

add_action('update_option_blogdescription', function() use ($ikabud_cache, $instance_id) {
    $ikabud_cache->clear($instance_id);
    error_log("Ikabud Cache: Cleared cache for instance {$instance_id} after site tagline updated");
});

/**
 * Add admin bar menu for manual cache clearing
 */
add_action('admin_bar_menu', function($wp_admin_bar) use ($ikabud_cache, $instance_id) {
    if (!current_user_can('manage_options')) {
        return;
    }
    
    $wp_admin_bar->add_node([
        'id' => 'ikabud-clear-cache',
        'title' => '🗑️ Clear Cache',
        'href' => wp_nonce_url(admin_url('admin-post.php?action=ikabud_clear_cache'), 'ikabud_clear_cache'),
        'meta' => [
            'title' => 'Clear Ikabud Cache'
        ]
    ]);
}, 100);

/**
 * Handle manual cache clear from admin bar
 */
add_action('admin_post_ikabud_clear_cache', function() use ($ikabud_cache, $instance_id) {
    check_admin_referer('ikabud_clear_cache');
    
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }
    
    $ikabud_cache->clear($instance_id);
    
    wp_redirect(wp_get_referer() ?: admin_url());
    exit;
});

/**
 * Show cache status in admin dashboard
 */
add_action('admin_notices', function() use ($ikabud_cache, $instance_id) {
    if (!current_user_can('manage_options')) {
        return;
    }
    
    // Only show on dashboard
    $screen = get_current_screen();
    if ($screen->id !== 'dashboard') {
        return;
    }
    
    $size = $ikabud_cache->getSize($instance_id);
    
    echo '<div class="notice notice-info">';
    echo '<p><strong>Ikabud Cache Status:</strong> ';
    echo $size['files'] . ' cached pages, ' . $size['size_mb'] . ' MB';
    echo ' | <a href="' . wp_nonce_url(admin_url('admin-post.php?action=ikabud_clear_cache'), 'ikabud_clear_cache') . '">Clear Cache</a>';
    echo '</p>';
    echo '</div>';
});
