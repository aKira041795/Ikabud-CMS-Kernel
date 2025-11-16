#!/usr/bin/env php
<?php
/**
 * Test DiSyL Filter Processing
 */

require_once __DIR__ . '/vendor/autoload.php';

use IkabudKernel\Core\DiSyL\Engine;
use IkabudKernel\Core\DiSyL\Renderers\JoomlaRenderer;

echo "=== DiSyL Filter Test ===\n\n";

// Simple template with filters
$template = <<<'DISYL'
{!-- Test Template --}
<div>
    <h1>{title | esc_html}</h1>
    <p>{content | strip_tags | truncate:length=50}</p>
    <a href="{url | esc_url}">Link</a>
</div>
DISYL;

echo "Template:\n";
echo $template . "\n\n";

// Create context
$context = [
    'title' => '<script>alert("xss")</script>Test Title',
    'content' => '<p>This is a long piece of content that should be truncated to 50 characters</p>',
    'url' => 'https://example.com/page?param=value'
];

echo "Context:\n";
print_r($context);
echo "\n";

try {
    // Create engine and renderer
    $engine = new Engine();
    $renderer = new JoomlaRenderer();
    
    echo "Compiling template...\n";
    $ast = $engine->compile($template);
    
    echo "AST:\n";
    print_r($ast);
    echo "\n";
    
    echo "Rendering...\n";
    $html = $renderer->render($ast, $context);
    
    echo "Result:\n";
    echo $html . "\n\n";
    
    echo "✅ Test completed successfully!\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "Trace:\n" . $e->getTraceAsString() . "\n";
}
