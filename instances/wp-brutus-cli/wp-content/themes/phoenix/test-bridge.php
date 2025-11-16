<?php
/**
 * Test Bridge Integration
 * 
 * Run this file to test if the bridge is working
 * Access via: /wp-content/themes/phoenix/test-bridge.php
 */

// Load WordPress
// Try multiple possible paths
$possible_paths = [
    dirname(dirname(dirname(dirname(__DIR__)))) . '/wp-load.php',
    dirname(dirname(dirname(__DIR__))) . '/wp-load.php',
    __DIR__ . '/../../../../../wp-load.php',
];

$wp_load_path = null;
foreach ($possible_paths as $path) {
    if (file_exists($path)) {
        $wp_load_path = $path;
        break;
    }
}

if (!$wp_load_path) {
    echo "<h1>WordPress Not Found</h1>";
    echo "<p>Tried paths:</p><ul>";
    foreach ($possible_paths as $path) {
        echo "<li>" . htmlspecialchars($path) . "</li>";
    }
    echo "</ul>";
    echo "<p><strong>Current directory:</strong> " . __DIR__ . "</p>";
    echo "<p><strong>Access this file via web browser:</strong> http://your-domain/wp-content/themes/phoenix/test-bridge.php</p>";
    die();
}

require_once($wp_load_path);

// Check if bridge classes are loaded
echo "<h1>Phoenix Bridge Test</h1>\n";

echo "<h2>1. Classes Loaded</h2>\n";
echo "Phoenix_Manifest: " . (class_exists('Phoenix_Manifest') ? '✅ YES' : '❌ NO') . "<br>\n";
echo "Phoenix_Customizer: " . (class_exists('Phoenix_Customizer') ? '✅ YES' : '❌ NO') . "<br>\n";
echo "Phoenix_Component_Bridge: " . (class_exists('Phoenix_Component_Bridge') ? '✅ YES' : '❌ NO') . "<br>\n";

echo "<h2>2. Manifest Data</h2>\n";
if (class_exists('Phoenix_Manifest')) {
    $manifest = Phoenix_Manifest::get_instance();
    $components = $manifest->get_components();
    echo "Components loaded: " . count($components) . "<br>\n";
    echo "Components: " . implode(', ', array_keys($components)) . "<br>\n";
    
    $sections = $manifest->get_customizer_sections();
    echo "Customizer sections: " . count($sections) . "<br>\n";
    echo "Sections: " . implode(', ', array_keys($sections)) . "<br>\n";
}

echo "<h2>3. Theme Mod Values</h2>\n";
echo "Hero Title: <strong>" . get_theme_mod('phoenix_hero_title', 'NOT SET') . "</strong><br>\n";
echo "Hero Subtitle: <strong>" . get_theme_mod('phoenix_hero_subtitle', 'NOT SET') . "</strong><br>\n";
echo "Primary Color: <strong>" . get_theme_mod('phoenix_primary_color', 'NOT SET') . "</strong><br>\n";

echo "<h2>4. DiSyL Context Test</h2>\n";
// Simulate DiSyL context filter
$test_context = apply_filters('ikabud_disyl_context', []);

echo "Context has 'components': " . (isset($test_context['components']) ? '✅ YES' : '❌ NO') . "<br>\n";
echo "Context has 'theme': " . (isset($test_context['theme']) ? '✅ YES' : '❌ NO') . "<br>\n";
echo "Context has 'widget_visibility': " . (isset($test_context['widget_visibility']) ? '✅ YES' : '❌ NO') . "<br>\n";

if (isset($test_context['theme']['hero'])) {
    echo "<br><strong>Hero values in context:</strong><br>\n";
    echo "Title: " . ($test_context['theme']['hero']['title'] ?? 'NOT SET') . "<br>\n";
    echo "Subtitle: " . ($test_context['theme']['hero']['subtitle'] ?? 'NOT SET') . "<br>\n";
}

if (isset($test_context['components'])) {
    echo "<br><strong>Components in context:</strong><br>\n";
    foreach ($test_context['components'] as $comp_id => $props) {
        echo "- {$comp_id}: " . count($props) . " props<br>\n";
    }
}

echo "<h2>5. Customizer Controls Test</h2>\n";
echo "To test customizer controls, go to: <a href='/wp-admin/customize.php' target='_blank'>Appearance → Customize</a><br>\n";
echo "Look for these sections:<br>\n";
echo "- Hero Section<br>\n";
echo "- Colors & Branding<br>\n";
echo "- Typography<br>\n";
echo "- Header Settings<br>\n";
echo "- Footer Settings<br>\n";
echo "- Slider Settings<br>\n";
echo "- Layout Settings<br>\n";
echo "- Widget Areas<br>\n";

echo "<h2>Status</h2>\n";
$all_good = class_exists('Phoenix_Manifest') && 
            class_exists('Phoenix_Customizer') && 
            class_exists('Phoenix_Component_Bridge') &&
            isset($test_context['theme']) &&
            isset($test_context['components']);

if ($all_good) {
    echo "<p style='color: green; font-size: 20px; font-weight: bold;'>✅ Bridge is working!</p>\n";
    echo "<p>Now go to <a href='/wp-admin/customize.php'>Customizer</a> and change the Hero Title, then refresh the homepage to see it update.</p>\n";
} else {
    echo "<p style='color: red; font-size: 20px; font-weight: bold;'>❌ Bridge has issues</p>\n";
    echo "<p>Check the debug output above to see what's missing.</p>\n";
}
