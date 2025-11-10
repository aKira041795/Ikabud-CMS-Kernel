<?php
/**
 * Plugin Name: Ikabud Smart Cache Invalidation
 * Description: Granular cache invalidation using tags and patterns
 * Version: 2.0.0
 * Author: Ikabud Kernel
 */

// Get instance ID from environment
$instance_id = defined('IKABUD_INSTANCE_ID') ? IKABUD_INSTANCE_ID : null;

if (!$instance_id) {
    return; // Can't clear cache without instance ID
}

// Initialize cache
$kernel_path = defined('IKABUD_KERNEL_PATH') ? IKABUD_KERNEL_PATH : dirname(dirname(dirname(dirname(__FILE__)))) . '/kernel';
require_once $kernel_path . '/Cache.php';
$ikabud_cache = new \IkabudKernel\Core\Cache();

/**
 * Get tags for a post
 */
function ikabud_get_post_tags($post_id, $post) {
    $tags = [
        'post-' . $post_id,
        'post-type-' . $post->post_type,
    ];
    
    // Add category tags
    $categories = get_the_category($post_id);
    foreach ($categories as $category) {
        $tags[] = 'category-' . $category->term_id;
    }
    
    // Add post tag tags
    $post_tags = get_the_tags($post_id);
    if ($post_tags) {
        foreach ($post_tags as $tag) {
            $tags[] = 'tag-' . $tag->term_id;
        }
    }
    
    // Add author tag
    $tags[] = 'author-' . $post->post_author;
    
    // Add date-based tags
    $tags[] = 'year-' . get_the_date('Y', $post_id);
    $tags[] = 'month-' . get_the_date('Y-m', $post_id);
    
    return $tags;
}

/**
 * Get dependency URLs for a post
 */
function ikabud_get_post_dependencies($post_id, $post) {
    $dependencies = [
        '/',  // Homepage
        '/page/1/',  // First page of blog
    ];
    
    // Add category archives
    $categories = get_the_category($post_id);
    foreach ($categories as $category) {
        $dependencies[] = get_category_link($category->term_id);
    }
    
    // Add tag archives
    $post_tags = get_the_tags($post_id);
    if ($post_tags) {
        foreach ($post_tags as $tag) {
            $dependencies[] = get_tag_link($tag->term_id);
        }
    }
    
    // Add author archive
    $dependencies[] = get_author_posts_url($post->post_author);
    
    // Add date archives
    $dependencies[] = get_year_link(get_the_date('Y', $post_id));
    $dependencies[] = get_month_link(get_the_date('Y', $post_id), get_the_date('m', $post_id));
    
    return $dependencies;
}

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
    
    // Get tags for this post
    $tags = ikabud_get_post_tags($post_id, $post);
    
    // Clear cache by tags (granular approach)
    $cleared = $ikabud_cache->clearByTags($instance_id, $tags);
    
    // Also clear the post's permalink and dependencies
    $post_url = get_permalink($post_id);
    $dependencies = ikabud_get_post_dependencies($post_id, $post);
    $cleared += $ikabud_cache->clearWithDependencies($instance_id, $post_url, $dependencies);
    
    // Log cache clear
    error_log("Ikabud Cache: Cleared $cleared files for post {$post_id} using tags: " . implode(', ', $tags));
}, 10, 3);

/**
 * Clear cache when posts are deleted
 */
add_action('delete_post', function($post_id) use ($ikabud_cache, $instance_id) {
    $post = get_post($post_id);
    if (!$post) return;
    
    $tags = ikabud_get_post_tags($post_id, $post);
    $cleared = $ikabud_cache->clearByTags($instance_id, $tags);
    
    error_log("Ikabud Cache: Cleared $cleared files after post {$post_id} deletion");
});

/**
 * Clear cache when comments are posted/updated/deleted
 */
add_action('comment_post', function($comment_id, $approved) use ($ikabud_cache, $instance_id) {
    if ($approved === 1) {
        $comment = get_comment($comment_id);
        $post_id = $comment->comment_post_ID;
        
        // Clear only the post page, not entire site
        $tags = ['post-' . $post_id];
        $cleared = $ikabud_cache->clearByTags($instance_id, $tags);
        
        error_log("Ikabud Cache: Cleared $cleared files after comment {$comment_id} posted");
    }
}, 10, 2);

add_action('edit_comment', function($comment_id) use ($ikabud_cache, $instance_id) {
    $comment = get_comment($comment_id);
    $post_id = $comment->comment_post_ID;
    
    $tags = ['post-' . $post_id];
    $cleared = $ikabud_cache->clearByTags($instance_id, $tags);
    
    error_log("Ikabud Cache: Cleared $cleared files after comment {$comment_id} edited");
});

add_action('delete_comment', function($comment_id) use ($ikabud_cache, $instance_id) {
    $comment = get_comment($comment_id);
    $post_id = $comment->comment_post_ID;
    
    $tags = ['post-' . $post_id];
    $cleared = $ikabud_cache->clearByTags($instance_id, $tags);
    
    error_log("Ikabud Cache: Cleared $cleared files after comment {$comment_id} deleted");
});

/**
 * Clear cache when categories/tags are updated
 */
add_action('edited_term', function($term_id, $tt_id, $taxonomy) use ($ikabud_cache, $instance_id) {
    if ($taxonomy === 'category') {
        $cleared = $ikabud_cache->clearByTag($instance_id, 'category-' . $term_id);
        error_log("Ikabud Cache: Cleared $cleared files for category {$term_id}");
    } elseif ($taxonomy === 'post_tag') {
        $cleared = $ikabud_cache->clearByTag($instance_id, 'tag-' . $term_id);
        error_log("Ikabud Cache: Cleared $cleared files for tag {$term_id}");
    }
}, 10, 3);

/**
 * Clear cache when theme is switched
 */
add_action('switch_theme', function($new_name, $new_theme) use ($ikabud_cache, $instance_id) {
    $ikabud_cache->clear($instance_id);
    error_log("Ikabud Cache: Cleared all cache after theme switch to {$new_name}");
}, 10, 2);

/**
 * Clear cache when widgets are updated
 */
add_action('update_option_sidebars_widgets', function($old_value, $value) use ($ikabud_cache, $instance_id) {
    $ikabud_cache->clear($instance_id);
    error_log("Ikabud Cache: Cleared all cache after widgets updated");
}, 10, 2);

/**
 * Clear cache when menus are updated
 */
add_action('wp_update_nav_menu', function($menu_id) use ($ikabud_cache, $instance_id) {
    $ikabud_cache->clear($instance_id);
    error_log("Ikabud Cache: Cleared all cache after menu {$menu_id} updated");
});

/**
 * Clear cache when options are updated (site title, tagline, etc.)
 */
add_action('update_option_blogname', function() use ($ikabud_cache, $instance_id) {
    $ikabud_cache->clear($instance_id);
    error_log("Ikabud Cache: Cleared all cache after site title updated");
});

add_action('update_option_blogdescription', function() use ($ikabud_cache, $instance_id) {
    $ikabud_cache->clear($instance_id);
    error_log("Ikabud Cache: Cleared all cache after site tagline updated");
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
    
    // Add submenu for clearing current page only
    global $wp;
    $current_url = home_url($wp->request);
    $wp_admin_bar->add_node([
        'parent' => 'ikabud-clear-cache',
        'id' => 'ikabud-clear-current',
        'title' => 'Clear Current Page',
        'href' => wp_nonce_url(admin_url('admin-post.php?action=ikabud_clear_current&url=' . urlencode($current_url)), 'ikabud_clear_current'),
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
 * Handle clearing current page only
 */
add_action('admin_post_ikabud_clear_current', function() use ($ikabud_cache, $instance_id) {
    check_admin_referer('ikabud_clear_current');
    
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }
    
    $url = isset($_GET['url']) ? $_GET['url'] : '/';
    $cleared = $ikabud_cache->clearWithDependencies($instance_id, $url, []);
    
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
    echo '<p><strong>⚡ Ikabud Smart Cache:</strong> ';
    echo $size['files'] . ' cached pages, ' . $size['size_mb'] . ' MB';
    echo ' | <a href="' . wp_nonce_url(admin_url('admin-post.php?action=ikabud_clear_cache'), 'ikabud_clear_cache') . '">Clear All Cache</a>';
    echo '</p>';
    echo '</div>';
});
