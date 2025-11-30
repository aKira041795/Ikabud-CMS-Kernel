<?php
/**
 * Main Template File
 * 
 * DiSyL template loader for Test
 * 
 * @package test
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Determine which template to load
$template = 'home';

if (is_singular('post')) {
    $template = 'single';
} elseif (is_page()) {
    $template = 'page';
} elseif (is_archive()) {
    $template = 'archive';
} elseif (is_category()) {
    $template = 'category';
} elseif (is_search()) {
    $template = 'search';
} elseif (is_404()) {
    $template = '404';
} elseif (is_home()) {
    $template = 'blog';
}

// Load DiSyL template
$template_file = get_template_directory() . '/disyl/' . $template . '.disyl';

if (file_exists($template_file)) {
    // Use Ikabud DiSyL renderer if available
    if (function_exists('ikabud_render_disyl')) {
        echo ikabud_render_disyl($template_file);
    } else {
        // Fallback: include raw template (for development)
        include $template_file;
    }
} else {
    // Fallback to basic WordPress loop
    get_header();
    
    if (have_posts()) {
        while (have_posts()) {
            the_post();
            the_content();
        }
    }
    
    get_footer();
}
