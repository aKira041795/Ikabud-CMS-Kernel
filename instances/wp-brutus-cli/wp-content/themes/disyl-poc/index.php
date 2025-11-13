<?php
/**
 * Main Template File
 * 
 * Routes to appropriate DiSyL template based on WordPress context
 */

// CRITICAL DEBUG: Output immediately to test if file is loaded
echo "<!-- DiSyL Theme index.php loaded -->\n";
echo "<!DOCTYPE html><html><head><title>DiSyL Test</title></head><body>";
echo "<h1>DiSyL Theme is Loading</h1>";

// Debug: Check if function exists
if (!function_exists('disyl_render_template')) {
    die('<p style="color:red;">ERROR: disyl_render_template function not found. Theme functions.php may not be loaded.</p></body></html>');
}

// Debug: Determine which template to use
$template_name = 'home'; // default

if (is_home() || is_front_page()) {
    $template_name = 'home';
} elseif (is_single()) {
    $template_name = 'single';
} elseif (is_archive() || is_category() || is_tag()) {
    $template_name = 'archive';
} elseif (is_page()) {
    $template_name = 'page';
} elseif (is_search()) {
    $template_name = 'search';
} elseif (is_404()) {
    $template_name = '404';
}

// Debug output
error_log('DiSyL Theme: Rendering template: ' . $template_name);

// Render template
try {
    $output = disyl_render_template($template_name);
    
    if (empty($output)) {
        die('ERROR: Template rendered but output is empty. Template: ' . $template_name);
    }
    
    echo $output;
} catch (Exception $e) {
    die('ERROR: Exception during rendering: ' . $e->getMessage() . '<br>Template: ' . $template_name);
}
