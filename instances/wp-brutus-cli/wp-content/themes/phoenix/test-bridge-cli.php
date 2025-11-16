<?php
/**
 * CLI Test for Phoenix Bridge
 * 
 * Run: php test-bridge-cli.php
 */

// Set up minimal environment
define('ABSPATH', dirname(dirname(dirname(__DIR__))) . '/');

// Mock WordPress functions for CLI testing
if (!function_exists('get_template_directory')) {
    function get_template_directory() {
        return __DIR__;
    }
}
if (!function_exists('get_theme_mod')) {
    function get_theme_mod($name, $default = false) {
        return $default;
    }
}
if (!function_exists('add_filter')) {
    function add_filter($hook, $callback, $priority = 10) {
        return true;
    }
}
if (!function_exists('add_action')) {
    function add_action($hook, $callback, $priority = 10) {
        return true;
    }
}

// Load theme files directly
require_once __DIR__ . '/includes/class-phoenix-manifest.php';
require_once __DIR__ . '/includes/class-phoenix-customizer.php';
require_once __DIR__ . '/includes/class-phoenix-component-bridge.php';
require_once __DIR__ . '/includes/phoenix-template-functions.php';

echo "=== Phoenix Bridge CLI Test ===\n\n";

// Test 1: Classes loaded
echo "1. CLASSES LOADED\n";
echo "   Phoenix_Manifest: " . (class_exists('Phoenix_Manifest') ? '✅ YES' : '❌ NO') . "\n";
echo "   Phoenix_Customizer: " . (class_exists('Phoenix_Customizer') ? '✅ YES' : '❌ NO') . "\n";
echo "   Phoenix_Component_Bridge: " . (class_exists('Phoenix_Component_Bridge') ? '✅ YES' : '❌ NO') . "\n";
echo "\n";

// Test 2: Manifest loading
echo "2. MANIFEST LOADING\n";
try {
    $manifest = Phoenix_Manifest::get_instance();
    $components = $manifest->get_components();
    echo "   Components loaded: " . count($components) . "\n";
    echo "   Components: " . implode(', ', array_keys($components)) . "\n";
    
    $sections = $manifest->get_customizer_sections();
    echo "   Customizer sections: " . count($sections) . "\n";
    echo "   Sections: " . implode(', ', array_keys($sections)) . "\n";
    echo "   ✅ Manifest loaded successfully\n";
} catch (Exception $e) {
    echo "   ❌ Error: " . $e->getMessage() . "\n";
}
echo "\n";

// Test 3: Component props
echo "3. COMPONENT PROPS\n";
if (isset($manifest)) {
    foreach (['header', 'footer', 'slider', 'sidebar', 'comments'] as $comp_id) {
        $comp = $manifest->get_component($comp_id);
        if ($comp) {
            $props = $comp['props'] ?? [];
            echo "   {$comp_id}: " . count($props) . " props\n";
        }
    }
}
echo "\n";

// Test 4: Customizer props
echo "4. CUSTOMIZER-ENABLED PROPS\n";
if (isset($manifest)) {
    $customizer_props = $manifest->get_customizer_props();
    echo "   Total customizer props: " . count($customizer_props) . "\n";
    echo "   First 5 props:\n";
    $count = 0;
    foreach ($customizer_props as $prop_id => $prop) {
        if ($count++ >= 5) break;
        echo "      - {$prop_id} ({$prop['type']})\n";
    }
}
echo "\n";

// Test 5: Widget areas
echo "5. WIDGET AREAS\n";
if (isset($manifest)) {
    $widget_areas = $manifest->get_widget_areas();
    echo "   Total widget areas: " . count($widget_areas) . "\n";
    foreach ($widget_areas as $area_id => $area) {
        $visibility = $area['customizer']['visibility_control'] ?? false;
        echo "      - {$area_id}: " . ($visibility ? '✅ visibility control' : '❌ no control') . "\n";
    }
}
echo "\n";

// Test 6: File structure
echo "6. FILE STRUCTURE\n";
$files = [
    'manifest.json' => file_exists(__DIR__ . '/manifest.json'),
    'includes/class-phoenix-manifest.php' => file_exists(__DIR__ . '/includes/class-phoenix-manifest.php'),
    'includes/class-phoenix-customizer.php' => file_exists(__DIR__ . '/includes/class-phoenix-customizer.php'),
    'includes/class-phoenix-component-bridge.php' => file_exists(__DIR__ . '/includes/class-phoenix-component-bridge.php'),
    'includes/phoenix-template-functions.php' => file_exists(__DIR__ . '/includes/phoenix-template-functions.php'),
    'disyl/home.disyl' => file_exists(__DIR__ . '/disyl/home.disyl'),
    'disyl/components/header.disyl' => file_exists(__DIR__ . '/disyl/components/header.disyl'),
    'disyl/components/footer.disyl' => file_exists(__DIR__ . '/disyl/components/footer.disyl'),
];

foreach ($files as $file => $exists) {
    echo "   " . ($exists ? '✅' : '❌') . " {$file}\n";
}
echo "\n";

// Final summary
echo "=== SUMMARY ===\n";
$all_good = class_exists('Phoenix_Manifest') && 
            class_exists('Phoenix_Customizer') && 
            class_exists('Phoenix_Component_Bridge') &&
            isset($manifest) &&
            count($components ?? []) > 0;

if ($all_good) {
    echo "✅ Bridge is working correctly!\n";
    echo "\nNext steps:\n";
    echo "1. Access WordPress Customizer: Appearance → Customize\n";
    echo "2. Look for these sections:\n";
    echo "   - Hero Section\n";
    echo "   - Colors & Branding\n";
    echo "   - Typography\n";
    echo "   - Header Settings\n";
    echo "   - Footer Settings\n";
    echo "   - Slider Settings\n";
    echo "   - Layout Settings\n";
    echo "   - Widget Areas\n";
    echo "3. Change settings and see them reflect on the frontend\n";
} else {
    echo "❌ Bridge has issues - check errors above\n";
}
echo "\n";
