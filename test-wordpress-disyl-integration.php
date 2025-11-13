<?php
/**
 * WordPress DiSyL Integration Test
 * 
 * Tests DiSyL rendering with WordPress-like data
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "=== WordPress DiSyL Integration Test ===\n\n";

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

// Simulate the home-simple.disyl template
$template = <<<'DISYL'
{ikb_section type="hero" padding="large"}
    {ikb_container width="xl"}
        {ikb_text size="2xl" weight="bold" align="center"}
            Welcome to Brutus Blog
        {/ikb_text}
        {ikb_text size="lg" align="center"}
            Exploring technology, design, and innovation
        {/ikb_text}
    {/ikb_container}
{/ikb_section}

{ikb_section type="content" padding="large"}
    {ikb_container width="lg"}
        {ikb_text size="xl" weight="bold" align="center"}
            Latest Posts
        {/ikb_text}
        
        {ikb_query type="post" limit="3"}
            {ikb_card}
                {ikb_text size="lg"}{item.title}{/ikb_text}
                {ikb_text size="sm"}{item.excerpt}{/ikb_text}
            {/ikb_card}
        {/ikb_query}
    {/ikb_container}
{/ikb_section}
DISYL;

echo "1. Compiling template...\n";
$lexer = new Lexer();
$parser = new Parser();
$compiler = new Compiler();

$tokens = $lexer->tokenize($template);
$ast = $parser->parse($tokens);
$compiled = $compiler->compile($ast);

echo "   ✓ Compilation successful\n";
echo "   Warnings: " . count($compiled['metadata']['warnings'] ?? []) . "\n";
if (!empty($compiled['metadata']['warnings'])) {
    foreach ($compiled['metadata']['warnings'] as $warning) {
        echo "     - $warning\n";
    }
}
echo "\n";

echo "2. Creating mock WordPress renderer...\n";

// Mock WordPress renderer with query simulation
class MockWordPressRenderer extends IkabudKernel\Core\DiSyL\Renderers\BaseRenderer
{
    protected function initializeCMS(): void {}
    
    protected function renderIkbSection(array $node, array $attrs, array $children): string
    {
        $type = $attrs['type'] ?? 'content';
        $padding = $attrs['padding'] ?? 'normal';
        return "<section class=\"ikb-section ikb-section-{$type}\" data-padding=\"{$padding}\">\n" . 
               $this->renderChildren($children) . 
               "</section>\n";
    }
    
    protected function renderIkbContainer(array $node, array $attrs, array $children): string
    {
        $width = $attrs['width'] ?? 'lg';
        return "<div class=\"ikb-container ikb-container-{$width}\">\n" . 
               $this->renderChildren($children) . 
               "</div>\n";
    }
    
    protected function renderIkbText(array $node, array $attrs, array $children): string
    {
        $size = $attrs['size'] ?? 'md';
        $weight = $attrs['weight'] ?? 'normal';
        $align = $attrs['align'] ?? 'left';
        return "<div class=\"ikb-text\" data-size=\"{$size}\" data-weight=\"{$weight}\" data-align=\"{$align}\">" . 
               $this->renderChildren($children) . 
               "</div>\n";
    }
    
    protected function renderIkbCard(array $node, array $attrs, array $children): string
    {
        return "<div class=\"ikb-card\">\n" . 
               $this->renderChildren($children) . 
               "</div>\n";
    }
    
    protected function renderIkbQuery(array $node, array $attrs, array $children): string
    {
        $type = $attrs['type'] ?? 'post';
        $limit = $attrs['limit'] ?? 10;
        
        // Simulate WordPress posts
        $mockPosts = [
            [
                'id' => 1,
                'title' => 'Getting Started with DiSyL',
                'excerpt' => 'Learn how to build modern WordPress themes with DiSyL template language.',
                'url' => '/post/getting-started-with-disyl',
                'date' => '2024-01-15'
            ],
            [
                'id' => 2,
                'title' => 'Advanced DiSyL Patterns',
                'excerpt' => 'Explore advanced patterns and best practices for DiSyL development.',
                'url' => '/post/advanced-disyl-patterns',
                'date' => '2024-01-20'
            ],
            [
                'id' => 3,
                'title' => 'DiSyL vs Traditional PHP Templates',
                'excerpt' => 'Compare DiSyL with traditional PHP templating approaches.',
                'url' => '/post/disyl-vs-php-templates',
                'date' => '2024-01-25'
            ]
        ];
        
        $html = '';
        $originalContext = $this->context;
        
        foreach (array_slice($mockPosts, 0, $limit) as $post) {
            $this->context['item'] = $post;
            $html .= $this->renderChildren($children);
        }
        
        $this->context = $originalContext;
        
        return $html;
    }
}

$renderer = new MockWordPressRenderer();
$output = $renderer->render($compiled);

echo "   ✓ Renderer created\n\n";

echo "3. Rendering template...\n";
echo "   Output length: " . strlen($output) . " bytes\n\n";

echo "4. Rendered HTML:\n";
echo "---BEGIN OUTPUT---\n";
echo $output;
echo "---END OUTPUT---\n\n";

echo "5. Verifying output...\n";
$checks = [
    'Welcome to Brutus Blog' => 'Hero title',
    'Exploring technology' => 'Hero subtitle',
    'Latest Posts' => 'Section title',
    'Getting Started with DiSyL' => 'Post 1 title',
    'Advanced DiSyL Patterns' => 'Post 2 title',
    'DiSyL vs Traditional PHP Templates' => 'Post 3 title',
    'ikb-section-hero' => 'Hero section class',
    'ikb-section-content' => 'Content section class',
    'ikb-card' => 'Card component'
];

$passed = 0;
$failed = 0;

foreach ($checks as $needle => $description) {
    if (strpos($output, $needle) !== false) {
        echo "   ✓ {$description}\n";
        $passed++;
    } else {
        echo "   ✗ {$description} NOT FOUND\n";
        $failed++;
    }
}

echo "\n";
echo "Results: {$passed} passed, {$failed} failed\n";

if ($failed === 0) {
    echo "\n✅ All tests passed! DiSyL WordPress integration is working correctly.\n";
} else {
    echo "\n⚠️  Some tests failed. Review the output above.\n";
}

echo "\n=== Test Complete ===\n";
