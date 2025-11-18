<?php
/**
 * Test the default filter with nested expressions
 */

require_once __DIR__ . '/kernel/DiSyL/Token.php';
require_once __DIR__ . '/kernel/DiSyL/Lexer.php';
require_once __DIR__ . '/kernel/DiSyL/ParserError.php';
require_once __DIR__ . '/kernel/DiSyL/Grammar.php';
require_once __DIR__ . '/kernel/DiSyL/ComponentRegistry.php';
require_once __DIR__ . '/kernel/DiSyL/ManifestLoader.php';
require_once __DIR__ . '/kernel/DiSyL/ModularManifestLoader.php';
require_once __DIR__ . '/kernel/DiSyL/Parser.php';
require_once __DIR__ . '/kernel/DiSyL/Compiler.php';
require_once __DIR__ . '/kernel/DiSyL/Renderers/BaseRenderer.php';
require_once __DIR__ . '/kernel/DiSyL/Renderers/DrupalRenderer.php';
require_once __DIR__ . '/kernel/DiSyL/Engine.php';

// Initialize ModularManifestLoader
\IkabudKernel\Core\DiSyL\ModularManifestLoader::init('full', 'drupal');

// Create engine and renderer
$engine = new \IkabudKernel\Core\DiSyL\Engine();
$renderer = new \IkabudKernel\Core\DiSyL\Renderers\DrupalRenderer();

// Test context
$context = [
    'node' => [
        'author' => 'John Doe',
        'author_id' => 123,
    ],
    'author_name' => '', // Empty, should fall back to node.author
];

// Test template
$template = '{author_name | default:"{node.author}" | escape}';

echo "Template: $template\n";
echo "Context: " . json_encode($context, JSON_PRETTY_PRINT) . "\n\n";

try {
    // Compile first, then render
    $ast = $engine->compile($template, $context);
    $output = $engine->render($ast, $renderer, $context);
    echo "Output: $output\n";
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
}
