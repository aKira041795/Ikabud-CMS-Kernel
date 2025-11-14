<?php
/**
 * Test {if} component with {ikb_query}
 */

require_once __DIR__ . '/vendor/autoload.php';

use IkabudKernel\Core\DiSyL\Engine;
use IkabudKernel\Core\DiSyL\Renderers\WordPressRenderer;
use IkabudKernel\CMS\Adapters\WordPressAdapter;

// Test template with {ikb_query} and {if}
$template = <<<'DISYL'
<h1>Test {ikb_query} with {if}</h1>

{ikb_query type="post" limit=3}
    <div class="post">
        <h2>{item.title}</h2>
        {if condition="item.thumbnail"}
            <img src="{item.thumbnail}" alt="{item.title}" />
        {/if}
        {if condition="item.missing_field"}
            <p>This should NOT appear</p>
        {/if}
    </div>
{/ikb_query}
DISYL;

echo "=== Testing {ikb_query} with {if} ===\n\n";

// Mock WordPress functions
if (!function_exists('esc_url')) {
    function esc_url($url) { return htmlspecialchars($url, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('esc_attr')) {
    function esc_attr($text) { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('esc_html')) {
    function esc_html($text) { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('apply_filters')) {
    function apply_filters($tag, $value) { return $value; }
}

// Create engine
$engine = new Engine();

// Compile template
echo "1. Compiling template...\n";
$ast = $engine->compile($template);

echo "2. AST structure:\n";
echo "   - Type: " . $ast['type'] . "\n";
echo "   - Children count: " . count($ast['children']) . "\n";

// Find ikb_query node
foreach ($ast['children'] as $i => $child) {
    if ($child['type'] === 'tag' && $child['name'] === 'ikb_query') {
        echo "   - Found ikb_query at index $i\n";
        echo "   - ikb_query children count: " . count($child['children']) . "\n";
        
        // Check first child
        if (!empty($child['children'])) {
            $firstChild = $child['children'][0];
            echo "   - First child type: " . $firstChild['type'] . "\n";
            if ($firstChild['type'] === 'tag') {
                echo "   - First child name: " . $firstChild['name'] . "\n";
                
                // Look for {if} inside
                foreach ($child['children'] as $j => $queryChild) {
                    if ($queryChild['type'] === 'tag' && $queryChild['name'] === 'if') {
                        echo "   - Found {if} at query child index $j\n";
                        echo "   - {if} condition: " . json_encode($queryChild['attrs']) . "\n";
                    }
                }
            }
        }
    }
}

// Create mock WordPress adapter
class MockWordPressAdapter extends WordPressAdapter {
    public function __construct() {
        // Don't call parent constructor
    }
    
    public function buildContext(): array {
        return [];
    }
}

// Create renderer with mock query
class TestWordPressRenderer extends \IkabudKernel\Core\DiSyL\Renderers\WordPressRenderer {
    public function __construct($cms) {
        parent::__construct($cms);
    }
    
    // Override ikb_query to return test data
    protected function renderIkbQuery(array $node, array $attrs, array $children): string {
        echo "\n[DEBUG] renderIkbQuery called\n";
        echo "[DEBUG] Children count: " . count($children) . "\n";
        
        // Mock posts
        $posts = [
            [
                'id' => 1,
                'title' => 'Post with thumbnail',
                'thumbnail' => 'https://example.com/image1.jpg',
            ],
            [
                'id' => 2,
                'title' => 'Post without thumbnail',
                'thumbnail' => null,
            ],
            [
                'id' => 3,
                'title' => 'Another post with thumbnail',
                'thumbnail' => 'https://example.com/image3.jpg',
            ],
        ];
        
        $html = '';
        $originalContext = $this->context;
        
        foreach ($posts as $post) {
            echo "[DEBUG] Processing post: " . $post['title'] . "\n";
            echo "[DEBUG] Thumbnail: " . ($post['thumbnail'] ?? 'NULL') . "\n";
            
            // Set item context
            $this->context['item'] = $post;
            
            echo "[DEBUG] Context keys: " . implode(', ', array_keys($this->context)) . "\n";
            echo "[DEBUG] item.thumbnail value: " . ($this->context['item']['thumbnail'] ?? 'NULL') . "\n";
            
            // Render children
            $childHtml = $this->renderChildren($children);
            echo "[DEBUG] Child HTML length: " . strlen($childHtml) . "\n";
            
            $html .= $childHtml;
        }
        
        $this->context = $originalContext;
        
        return $html;
    }
}

$cms = new MockWordPressAdapter();
$renderer = new TestWordPressRenderer($cms);

echo "\n3. Rendering...\n";
$html = $renderer->render($ast, ['site' => ['name' => 'Test Site']]);

echo "\n4. Rendered HTML:\n";
echo "---\n";
echo $html;
echo "\n---\n";

echo "\n5. Analysis:\n";
$imageCount = substr_count($html, '<img');
echo "   - Image tags found: $imageCount (expected: 2)\n";

if ($imageCount === 2) {
    echo "   ✅ {if} conditions working correctly\n";
} else {
    echo "   ❌ {if} conditions NOT working correctly\n";
}

echo "\nDone!\n";
