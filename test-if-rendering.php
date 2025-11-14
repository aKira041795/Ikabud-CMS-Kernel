<?php
/**
 * Test {if} component rendering
 */

require_once __DIR__ . '/vendor/autoload.php';

use IkabudKernel\Core\DiSyL\Engine;
use IkabudKernel\Core\DiSyL\Renderers\WordPressRenderer;
use IkabudKernel\CMS\Adapters\WordPressAdapter;

// Simple test template with {if}
$template = <<<'DISYL'
<h1>Test {if} Component</h1>

{if condition="show_message"}
    <p>This message should appear!</p>
{/if}

{if condition="hidden_message"}
    <p>This should NOT appear!</p>
{/if}

<p>Context value: {site.name}</p>
DISYL;

echo "=== Testing {if} Component Rendering ===\n\n";

// Create engine
$engine = new Engine();

// Compile template
echo "1. Compiling template...\n";
$ast = $engine->compile($template);

echo "2. AST structure:\n";
echo "   - Type: " . $ast['type'] . "\n";
echo "   - Children count: " . count($ast['children']) . "\n";

// Show first few children
foreach (array_slice($ast['children'], 0, 5) as $i => $child) {
    echo "   - Child $i: type=" . $child['type'];
    if ($child['type'] === 'tag') {
        echo ", name=" . $child['name'];
        if (isset($child['attrs'])) {
            echo ", attrs=" . json_encode($child['attrs']);
        }
    }
    echo "\n";
}

// Create context
$context = [
    'show_message' => true,
    'hidden_message' => false,
    'site' => [
        'name' => 'Test Site'
    ]
];

echo "\n3. Context:\n";
echo json_encode($context, JSON_PRETTY_PRINT) . "\n";

// Create renderer (without WordPress)
echo "\n4. Creating renderer...\n";

// We need to mock WordPress adapter
class MockWordPressAdapter extends WordPressAdapter {
    public function __construct() {
        // Don't call parent constructor
    }
    
    public function buildContext(): array {
        return [];
    }
}

$cms = new MockWordPressAdapter();
$renderer = new WordPressRenderer($cms);

echo "5. Rendering...\n";
$html = $renderer->render($ast, $context);

echo "\n6. Rendered HTML:\n";
echo "---\n";
echo $html;
echo "\n---\n";

echo "\n7. Analysis:\n";
if (strpos($html, 'This message should appear!') !== false) {
    echo "   ✅ First {if} block rendered correctly\n";
} else {
    echo "   ❌ First {if} block NOT rendered\n";
}

if (strpos($html, 'This should NOT appear!') === false) {
    echo "   ✅ Second {if} block correctly hidden\n";
} else {
    echo "   ❌ Second {if} block incorrectly rendered\n";
}

if (strpos($html, 'Test Site') !== false) {
    echo "   ✅ Context interpolation working\n";
} else {
    echo "   ❌ Context interpolation NOT working\n";
}

echo "\nDone!\n";
