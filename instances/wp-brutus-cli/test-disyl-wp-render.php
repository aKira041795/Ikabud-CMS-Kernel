<?php
/**
 * Test DiSyL WordPress Rendering
 */

// Load WordPress
require_once __DIR__ . '/wp-load.php';

// Load DiSyL engine
$kernel_path = dirname(dirname(__DIR__));
require_once $kernel_path . '/kernel/DiSyL/Token.php';
require_once $kernel_path . '/kernel/DiSyL/Exceptions/LexerException.php';
require_once $kernel_path . '/kernel/DiSyL/Exceptions/ParserException.php';
require_once $kernel_path . '/kernel/DiSyL/Exceptions/CompilerException.php';
require_once $kernel_path . '/kernel/DiSyL/Lexer.php';
require_once $kernel_path . '/kernel/DiSyL/Parser.php';
require_once $kernel_path . '/kernel/DiSyL/Grammar.php';
require_once $kernel_path . '/kernel/DiSyL/ComponentRegistry.php';
require_once $kernel_path . '/kernel/DiSyL/Compiler.php';
require_once $kernel_path . '/kernel/DiSyL/Renderers/BaseRenderer.php';
require_once $kernel_path . '/kernel/DiSyL/Renderers/WordPressRenderer.php';
require_once $kernel_path . '/cms/CMSInterface.php';
require_once $kernel_path . '/cms/Adapters/WordPressAdapter.php';

use IkabudKernel\Core\DiSyL\Lexer;
use IkabudKernel\Core\DiSyL\Parser;
use IkabudKernel\Core\DiSyL\Compiler;
use IkabudKernel\CMS\Adapters\WordPressAdapter;

echo "=== DiSyL WordPress Rendering Test ===\n\n";

// Test 1: Simple text rendering
$template1 = '{ikb_text size="xl"}Hello World{/ikb_text}';

echo "1. Testing Simple Text Rendering...\n";
try {
    $lexer = new Lexer();
    $parser = new Parser();
    $compiler = new Compiler();
    
    $tokens = $lexer->tokenize($template1);
    $ast = $parser->parse($tokens);
    $compiled = $compiler->compile($ast);
    
    $adapter = new WordPressAdapter(ABSPATH);
    $html = $adapter->renderDisyl($compiled);
    
    echo "   ✅ Rendered HTML:\n";
    echo "   " . $html . "\n\n";
} catch (Exception $e) {
    echo "   ❌ Error: " . $e->getMessage() . "\n\n";
}

// Test 2: Query rendering
$template2 = <<<'DISYL'
{ikb_query type="posts" limit="3"}
    {ikb_text size="lg"}{title}{/ikb_text}
{/ikb_query}
DISYL;

echo "2. Testing Query Rendering...\n";
try {
    $tokens = $lexer->tokenize($template2);
    $ast = $parser->parse($tokens);
    $compiled = $compiler->compile($ast);
    
    $html = $adapter->renderDisyl($compiled);
    
    echo "   ✅ Rendered HTML:\n";
    echo "   " . substr($html, 0, 500) . "...\n\n";
    
    // Count how many posts were rendered
    $postCount = substr_count($html, '<h');
    echo "   Posts rendered: $postCount\n\n";
    
} catch (Exception $e) {
    echo "   ❌ Error: " . $e->getMessage() . "\n\n";
}

// Test 3: Section rendering
$template3 = <<<'DISYL'
{ikb_section type="hero"}
    {ikb_text size="2xl"}Welcome to {site_name}{/ikb_text}
{/ikb_section}
DISYL;

echo "3. Testing Section Rendering...\n";
try {
    $tokens = $lexer->tokenize($template3);
    $ast = $parser->parse($tokens);
    $compiled = $compiler->compile($ast);
    
    $html = $adapter->renderDisyl($compiled);
    
    echo "   ✅ Rendered HTML:\n";
    echo "   " . $html . "\n\n";
    
} catch (Exception $e) {
    echo "   ❌ Error: " . $e->getMessage() . "\n\n";
}

echo "=== Test Complete ===\n";
