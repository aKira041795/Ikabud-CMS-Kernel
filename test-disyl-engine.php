<?php
/**
 * Test DiSyL Engine Components
 */

require_once __DIR__ . '/kernel/DiSyL/Token.php';
require_once __DIR__ . '/kernel/DiSyL/Exceptions/LexerException.php';
require_once __DIR__ . '/kernel/DiSyL/Exceptions/ParserException.php';
require_once __DIR__ . '/kernel/DiSyL/Exceptions/CompilerException.php';
require_once __DIR__ . '/kernel/DiSyL/Lexer.php';
require_once __DIR__ . '/kernel/DiSyL/Parser.php';
require_once __DIR__ . '/kernel/DiSyL/Grammar.php';
require_once __DIR__ . '/kernel/DiSyL/ComponentRegistry.php';
require_once __DIR__ . '/kernel/DiSyL/Compiler.php';

use IkabudKernel\Core\DiSyL\Lexer;
use IkabudKernel\Core\DiSyL\Parser;
use IkabudKernel\Core\DiSyL\Compiler;

echo "=== DiSyL Engine Test ===\n\n";

// Test 1: Simple DiSyL template
$template = '{ikb_text size="xl"}Hello World{/ikb_text}';

echo "1. Testing Lexer...\n";
try {
    $lexer = new Lexer();
    $tokens = $lexer->tokenize($template);
    echo "   ✅ Lexer: " . count($tokens) . " tokens generated\n";
    echo "   Tokens: " . json_encode(array_map(fn($t) => $t->type, $tokens)) . "\n\n";
} catch (Exception $e) {
    echo "   ❌ Lexer Error: " . $e->getMessage() . "\n\n";
    exit(1);
}

echo "2. Testing Parser...\n";
try {
    $parser = new Parser();
    $ast = $parser->parse($tokens);
    echo "   ✅ Parser: AST generated\n";
    echo "   AST: " . json_encode($ast, JSON_PRETTY_PRINT) . "\n\n";
} catch (Exception $e) {
    echo "   ❌ Parser Error: " . $e->getMessage() . "\n\n";
    exit(1);
}

echo "3. Testing Compiler...\n";
try {
    $compiler = new Compiler();
    $compiled = $compiler->compile($ast);
    echo "   ✅ Compiler: Validation passed\n";
    echo "   Compiled: " . json_encode($compiled, JSON_PRETTY_PRINT) . "\n\n";
} catch (Exception $e) {
    echo "   ❌ Compiler Error: " . $e->getMessage() . "\n\n";
    exit(1);
}

// Test 2: Complex template with query
$complexTemplate = <<<'DISYL'
{ikb_section type="hero"}
    {ikb_text size="2xl"}Welcome{/ikb_text}
    {ikb_query type="posts" limit="3"}
        {ikb_card}
            {ikb_text size="lg"}{title}{/ikb_text}
        {/ikb_card}
    {/ikb_query}
{/ikb_section}
DISYL;

echo "4. Testing Complex Template...\n";
try {
    $tokens = $lexer->tokenize($complexTemplate);
    $ast = $parser->parse($tokens);
    $compiled = $compiler->compile($ast);
    echo "   ✅ Complex template compiled successfully\n";
    echo "   Components found: ";
    
    $components = [];
    function findComponents($node, &$components) {
        if (isset($node['type']) && $node['type'] === 'tag') {
            $components[] = $node['name'];
        }
        if (isset($node['children'])) {
            foreach ($node['children'] as $child) {
                findComponents($child, $components);
            }
        }
    }
    
    findComponents($compiled, $components);
    echo implode(', ', array_unique($components)) . "\n\n";
    
} catch (Exception $e) {
    echo "   ❌ Complex Template Error: " . $e->getMessage() . "\n\n";
    exit(1);
}

echo "=== All Tests Passed ✅ ===\n";
echo "\nDiSyL Engine is working correctly!\n";
echo "Lexer → Parser → Compiler pipeline is functional.\n";
