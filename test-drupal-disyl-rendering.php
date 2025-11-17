<?php
/**
 * Test DiSyL Rendering for Drupal Phoenix
 * 
 * Run this to test if DiSyL is rendering correctly
 */

// Set up paths
$kernel_path = __DIR__ . '/kernel/DiSyL';
$template_path = __DIR__ . '/instances/dpl-now-drupal/themes/phoenix/disyl/components/slider.disyl';

// Load DiSyL classes
require_once $kernel_path . '/Token.php';
require_once $kernel_path . '/Lexer.php';
require_once $kernel_path . '/ParserError.php';
require_once $kernel_path . '/Grammar.php';
require_once $kernel_path . '/ComponentRegistry.php';
require_once $kernel_path . '/ManifestLoader.php';
require_once $kernel_path . '/ModularManifestLoader.php';
require_once $kernel_path . '/Parser.php';
require_once $kernel_path . '/Compiler.php';
require_once $kernel_path . '/Renderers/BaseRenderer.php';
require_once $kernel_path . '/Renderers/DrupalRenderer.php';
require_once $kernel_path . '/Engine.php';

// Initialize ModularManifestLoader
\IkabudKernel\Core\DiSyL\ModularManifestLoader::init('full', 'drupal');

// Create engine and renderer
$engine = new \IkabudKernel\Core\DiSyL\Engine();
$renderer = new \IkabudKernel\Core\DiSyL\Renderers\DrupalRenderer();

// Create test context
$context = [
    'site' => [
        'name' => 'Test Site',
        'theme_url' => '/themes/phoenix',
        'base_url' => 'http://genesis.test',
    ],
];

// Read template
$template_content = file_get_contents($template_path);

echo "=== DiSyL Rendering Test ===\n\n";
echo "Template: $template_path\n\n";
echo "Context:\n";
print_r($context);
echo "\n";

try {
    // Render
    $output = $engine->compileAndRender($template_content, $renderer, $context);
    
    echo "=== RENDERED OUTPUT ===\n";
    echo $output;
    echo "\n\n";
    
    // Check if images are in output
    if (strpos($output, 'slide-1.png') !== false) {
        echo "✅ Image paths found in output\n";
    } else {
        echo "❌ Image paths NOT found in output\n";
    }
    
    // Check if theme_url was interpolated
    if (strpos($output, '{site.theme_url}') !== false) {
        echo "❌ Variables NOT interpolated (still has {site.theme_url})\n";
    } else {
        echo "✅ Variables interpolated\n";
    }
    
    // Check if esc_url filter was applied
    if (strpos($output, '/themes/phoenix/assets/images/slide-1.png') !== false) {
        echo "✅ Full image path found: /themes/phoenix/assets/images/slide-1.png\n";
    } else {
        echo "❌ Full image path NOT found\n";
    }
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "Trace:\n" . $e->getTraceAsString() . "\n";
}
