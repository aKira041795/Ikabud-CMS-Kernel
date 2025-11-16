<?php
/**
 * Plugin Name: Ikabud DiSyL Integration
 * Description: Core DiSyL integration for WordPress - handles dual-domain architecture, hook captures, and rendering
 * Version: 1.0.0
 * Author: Ikabud Kernel
 * Requires: Ikabud Kernel with DiSyL engine
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Filter admin_url to use current domain on frontend
 * Prevents CORS issues with AJAX requests from plugins like WPForms
 * 
 * This is Ikabud Kernel-specific for dual-domain architecture:
 * - Frontend: domain.test (e.g., brutus.test)
 * - Backend: backend.domain.test (e.g., backend.brutus.test)
 */
function ikabud_disyl_fix_admin_url_for_frontend($url, $path, $blog_id) {
    // Only filter on frontend (not in admin)
    if (!is_admin()) {
        $current_domain = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
        $backend_domain = get_option('siteurl');
        
        // Replace backend domain with current domain
        if ($backend_domain) {
            $url = str_replace($backend_domain, $current_domain, $url);
        }
    }
    return $url;
}
add_filter('admin_url', 'ikabud_disyl_fix_admin_url_for_frontend', 10, 3);

/**
 * Output buffer to rewrite backend URLs in final HTML
 * This catches URLs that bypass WordPress filters
 */
function ikabud_disyl_start_output_buffer() {
    if (!is_admin()) {
        ob_start('ikabud_disyl_rewrite_backend_urls');
    }
}
add_action('template_redirect', 'ikabud_disyl_start_output_buffer', 1);

function ikabud_disyl_rewrite_backend_urls($html) {
    $backend_domain = get_option('siteurl');
    $current_domain = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
    
    if ($backend_domain && $current_domain) {
        // Replace all instances of backend domain with current domain
        $html = str_replace($backend_domain, $current_domain, $html);
    }
    
    return $html;
}

/**
 * Add ajaxurl to frontend for plugins like WPForms
 * WordPress only provides this in admin by default
 * 
 * IMPORTANT: Use current domain instead of backend domain to avoid CORS
 */
function ikabud_disyl_add_ajaxurl_to_frontend() {
    // Use current domain for AJAX to avoid cross-origin issues
    $current_domain = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
    $ajax_url = $current_domain . '/wp-admin/admin-ajax.php';
    
    echo '<script type="text/javascript">
        var ajaxurl = "' . esc_js($ajax_url) . '";
    </script>';
}
add_action('wp_head', 'ikabud_disyl_add_ajaxurl_to_frontend');

/**
 * Capture wp_head() output for DiSyL templates
 * This allows DiSyL to inject WordPress head content into templates
 */
function ikabud_disyl_capture_wp_head() {
    ob_start();
    wp_head();
    return ob_get_clean();
}

/**
 * Capture wp_footer() output for DiSyL templates
 * This allows DiSyL to inject WordPress footer content into templates
 */
function ikabud_disyl_capture_wp_footer() {
    ob_start();
    wp_footer();
    return ob_get_clean();
}

/**
 * Main DiSyL rendering function
 * Integrates WordPress template hierarchy with DiSyL engine
 */
function ikabud_disyl_render($template) {
    // Only process if DiSyL engine is available
    if (!class_exists('\\IkabudKernel\\Core\\DiSyL\\Lexer')) {
        return $template;
    }
    
    // Check if current theme supports DiSyL
    if (!current_theme_supports('ikabud-disyl')) {
        return $template;
    }
    
    // Map WordPress template hierarchy to DiSyL templates
    $disyl_template = null;
    
    if (is_front_page()) {
        $disyl_template = 'home.disyl';
    } elseif (is_home()) {
        $disyl_template = 'blog.disyl';
    } elseif (is_single()) {
        $disyl_template = 'single.disyl';
    } elseif (is_page()) {
        $disyl_template = 'page.disyl';
    } elseif (is_category()) {
        $disyl_template = 'category.disyl';
    } elseif (is_tag()) {
        $disyl_template = 'tag.disyl';
    } elseif (is_archive()) {
        $disyl_template = 'archive.disyl';
    } elseif (is_search()) {
        $disyl_template = 'search.disyl';
    } elseif (is_404()) {
        $disyl_template = '404.disyl';
    }
    
    // Allow themes to override template selection
    $disyl_template = apply_filters('ikabud_disyl_template', $disyl_template);
    
    if (!$disyl_template) {
        return $template;
    }
    
    $disyl_path = get_template_directory() . '/disyl/' . $disyl_template;
    
    if (!file_exists($disyl_path)) {
        return $template;
    }
    
    try {
        // Load DiSyL classes
        $lexer = new \IkabudKernel\Core\DiSyL\Lexer();
        $parser = new \IkabudKernel\Core\DiSyL\Parser();
        $compiler = new \IkabudKernel\Core\DiSyL\Compiler();
        $renderer = new \IkabudKernel\Core\DiSyL\Renderers\WordPressRenderer();
        
        // Read template
        $template_content = file_get_contents($disyl_path);
        
        // Process DiSyL
        $tokens = $lexer->tokenize($template_content);
        $ast = $parser->parse($tokens);
        $compiled = $compiler->compile($ast);
        
        // Build context - start with base context
        $context = ikabud_disyl_build_base_context();
        
        // Allow themes to extend context
        $context = apply_filters('ikabud_disyl_context', $context);
        
        // Render
        $html = $renderer->render($compiled, $context);
        
        // Rewrite backend URLs to current domain (fix CORS issues)
        $backend_domain = get_option('siteurl');
        $current_domain = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
        if ($backend_domain && $current_domain) {
            $html = str_replace($backend_domain, $current_domain, $html);
        }
        
        // Output
        echo $html;
        
        return false; // Prevent WordPress from loading default template
        
    } catch (\Exception $e) {
        error_log('Ikabud DiSyL Error: ' . $e->getMessage());
        return $template; // Fallback to default template
    }
}
add_filter('template_include', 'ikabud_disyl_render', 99);

/**
 * Build base DiSyL context with WordPress data
 * Themes can extend this via 'ikabud_disyl_context' filter
 */
function ikabud_disyl_build_base_context() {
    global $post, $wp_query;
    
    $context = array(
        'site' => array(
            'name' => get_bloginfo('name'),
            'description' => get_bloginfo('description'),
            'url' => home_url(),
            'theme_url' => get_template_directory_uri(),
            'charset' => get_bloginfo('charset'),
            'language' => get_bloginfo('language'),
        ),
        'user' => array(
            'logged_in' => is_user_logged_in(),
            'id' => get_current_user_id(),
            'name' => wp_get_current_user()->display_name,
        ),
        'query' => array(
            'is_home' => is_home(),
            'is_front_page' => is_front_page(),
            'is_single' => is_single(),
            'is_page' => is_page(),
            'is_archive' => is_archive(),
            'is_search' => is_search(),
            'is_404' => is_404(),
            'search_query' => get_search_query(),
            'found_posts' => $wp_query->found_posts,
        ),
    );
    
    // Add post data if available
    if ($post) {
        // CRITICAL: Process content FIRST to allow shortcodes to enqueue scripts
        $processed_content = apply_filters('the_content', $post->post_content);
        
        // NOW capture wp_head and wp_footer AFTER content processing
        // This ensures WPForms and other plugins can enqueue their scripts
        $context['wp_head'] = ikabud_disyl_capture_wp_head();
        $context['wp_footer'] = ikabud_disyl_capture_wp_footer();
        
        $context['post'] = array(
            'id' => $post->ID,
            'title' => get_the_title(),
            'content' => $processed_content,
            'excerpt' => get_the_excerpt(),
            'date' => get_the_date(),
            'author' => get_the_author(),
            'author_id' => $post->post_author,
            'author_url' => get_author_posts_url($post->post_author),
            'author_avatar' => get_avatar_url($post->post_author),
            'url' => get_permalink(),
            'thumbnail' => get_the_post_thumbnail_url($post, 'full'),
            'categories' => wp_get_post_categories($post->ID, array('fields' => 'names')),
            'tags' => wp_get_post_tags($post->ID, array('fields' => 'names')),
            'comment_count' => $post->comment_count,
            'comments_open' => comments_open(),
        );
    }
    
    // Add pagination data
    $current_page = max(1, get_query_var('paged'));
    $total_pages = $wp_query->max_num_pages;
    
    $context['pagination'] = array(
        'current_page' => $current_page,
        'total_pages' => $total_pages,
        'prev_url' => get_previous_posts_page_link(),
        'next_url' => get_next_posts_page_link(),
        'has_prev' => $current_page > 1,
        'has_next' => $current_page < $total_pages,
    );
    
    // Fallback: If wp_head/wp_footer not set yet (no post), capture them now
    if (!isset($context['wp_head'])) {
        $context['wp_head'] = ikabud_disyl_capture_wp_head();
    }
    if (!isset($context['wp_footer'])) {
        $context['wp_footer'] = ikabud_disyl_capture_wp_footer();
    }
    
    return $context;
}
