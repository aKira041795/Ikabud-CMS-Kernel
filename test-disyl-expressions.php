<?php
/**
 * DiSyL Expression Interpolation Test
 * 
 * Tests expression interpolation in text nodes
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "=== DiSyL Expression Interpolation Test ===\n\n";

// Load DiSyL engine
require_once __DIR__ . '/kernel/DiSyL/Token.php';
require_once __DIR__ . '/kernel/DiSyL/Exceptions/LexerException.php';
require_once __DIR__ . '/kernel/DiSyL/Exceptions/ParserException.php';
require_once __DIR__ . '/kernel/DiSyL/Exceptions/CompilerException.php';
require_once __DIR__ . '/kernel/DiSyL/Lexer.php';
require_once __DIR__ . '/kernel/DiSyL/Parser.php';
require_once __DIR__ . '/kernel/DiSyL/Grammar.php';
require_once __DIR__ . '/kernel/DiSyL/ComponentRegistry.php';
require_once __DIR__ . '/kernel/DiSyL/Compiler.php';
require_once __DIR__ . '/kernel/DiSyL/Renderers/BaseRenderer.php';

use IkabudKernel\Core\DiSyL\{Lexer, Parser, Compiler};

// Test template with expressions
$template = <<<'DISYL'
{ikb_section type="content"}
    {ikb_text size="lg"}Post Title: {title}{/ikb_text}
    {ikb_text size="sm"}Excerpt: {excerpt}{/ikb_text}
    {ikb_text size="sm"}Nested: {item.title}{/ikb_text}
{/ikb_section}
DISYL;

echo "1. Compiling template...\n";
$lexer = new Lexer();
$parser = new Parser();
$compiler = new Compiler();

$tokens = $lexer->tokenize($template);
$ast = $parser->parse($tokens);
$compiled = $compiler->compile($ast);
echo "   ✓ Compilation successful\n\n";

echo "2. Testing renderer with context data...\n";

// Create test renderer
class TestRenderer extends IkabudKernel\Core\DiSyL\Renderers\BaseRenderer
{
    protected function initializeCMS(): void {}
    
    protected function renderIkbSection(array $node, array $attrs, array $children): string
    {
        return "<section>\n" . $this->renderChildren($children) . "</section>\n";
    }
    
    protected function renderIkbText(array $node, array $attrs, array $children): string
    {
        $size = $attrs['size'] ?? 'md';
        return "<div class=\"text-{$size}\">" . $this->renderChildren($children) . "</div>\n";
    }
}

// Test context with data
$context = [
    'title' => 'My Awesome Blog Post',
    'excerpt' => 'This is a short excerpt of the post content.',
    'item' => [
        'title' => 'Nested Item Title'
    ]
];

$renderer = new TestRenderer();
$output = $renderer->render($compiled, $context);

echo "   Context data:\n";
echo "     - title: {$context['title']}\n";
echo "     - excerpt: {$context['excerpt']}\n";
echo "     - item.title: {$context['item']['title']}\n\n";

echo "   Rendered output:\n";
echo "---BEGIN OUTPUT---\n";
echo $output;
echo "---END OUTPUT---\n\n";

echo "3. Verifying expression interpolation...\n";
if (strpos($output, 'My Awesome Blog Post') !== false) {
    echo "   ✓ {title} interpolated correctly\n";
} else {
    echo "   ✗ {title} NOT interpolated\n";
}

if (strpos($output, 'This is a short excerpt') !== false) {
    echo "   ✓ {excerpt} interpolated correctly\n";
} else {
    echo "   ✗ {excerpt} NOT interpolated\n";
}

if (strpos($output, 'Nested Item Title') !== false) {
    echo "   ✓ {item.title} interpolated correctly\n";
} else {
    echo "   ✗ {item.title} NOT interpolated\n";
}

echo "\n=== Test Complete ===\n";
