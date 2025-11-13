<?php
/**
 * Test DiSyL Theme Rendering
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "=== DiSyL Theme Test ===\n\n";

// Load WordPress
require_once __DIR__ . '/wp-load.php';

echo "WordPress loaded: " . (defined('ABSPATH') ? 'YES' : 'NO') . "\n";
echo "Active theme: " . wp_get_theme()->get('Name') . "\n\n";

// Try to render a simple template
echo "Testing disyl_render_template function...\n";

if (function_exists('disyl_render_template')) {
    echo "✅ Function exists\n\n";
    
    try {
        echo "Rendering home template...\n";
        $output = disyl_render_template('home');
        
        if (empty($output)) {
            echo "❌ Output is empty\n";
        } else {
            echo "✅ Output generated (" . strlen($output) . " bytes)\n";
            echo "\nFirst 500 characters:\n";
            echo substr($output, 0, 500) . "\n";
        }
    } catch (Exception $e) {
        echo "❌ Error: " . $e->getMessage() . "\n";
        echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    }
} else {
    echo "❌ Function does not exist\n";
    echo "Theme functions.php may not be loaded\n";
}
