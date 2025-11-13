<?php
/**
 * Check if DiSyL theme is active
 */

require_once __DIR__ . '/wp-load.php';

echo "=== WordPress Theme Check ===\n\n";

$current_theme = wp_get_theme();
echo "Active Theme: " . $current_theme->get('Name') . "\n";
echo "Theme Directory: " . $current_theme->get_stylesheet_directory() . "\n";
echo "Template: " . $current_theme->get_template() . "\n";
echo "Stylesheet: " . $current_theme->get_stylesheet() . "\n\n";

echo "Theme Files:\n";
$theme_dir = $current_theme->get_stylesheet_directory();
echo "- index.php: " . (file_exists($theme_dir . '/index.php') ? 'EXISTS' : 'MISSING') . "\n";
echo "- functions.php: " . (file_exists($theme_dir . '/functions.php') ? 'EXISTS' : 'MISSING') . "\n";
echo "- style.css: " . (file_exists($theme_dir . '/style.css') ? 'EXISTS' : 'MISSING') . "\n\n";

echo "DiSyL Function: " . (function_exists('disyl_render_template') ? 'EXISTS' : 'MISSING') . "\n\n";

// Try to get homepage URL
echo "Site URL: " . get_site_url() . "\n";
echo "Home URL: " . get_home_url() . "\n";
