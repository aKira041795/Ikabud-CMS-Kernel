<?php
require 'vendor/autoload.php';
require 'kernel/DiSyL/Token.php';
require 'kernel/DiSyL/Exceptions/LexerException.php';
require 'kernel/DiSyL/Exceptions/ParserException.php';
require 'kernel/DiSyL/Exceptions/CompilerException.php';
require 'kernel/DiSyL/Lexer.php';
require 'kernel/DiSyL/Parser.php';
require 'kernel/DiSyL/Grammar.php';
require 'kernel/DiSyL/ComponentRegistry.php';
require 'kernel/DiSyL/Compiler.php';

use IkabudKernel\Core\DiSyL\Lexer;
use IkabudKernel\Core\DiSyL\Parser;
use IkabudKernel\Core\DiSyL\Compiler;

echo "=== DiSyL Full Pipeline Test ===" . PHP_EOL . PHP_EOL;

$lexer = new Lexer();
$parser = new Parser();
$compiler = new Compiler();

// Test 1: Simple template
echo "Test 1: Simple Template" . PHP_EOL;
$template1 = '{ikb_section type="hero" title="Welcome"}';

$tokens = $lexer->tokenize($template1);
echo "Tokens: " . count($tokens) . PHP_EOL;

$ast = $parser->parse($tokens);
echo "AST children: " . count($ast['children']) . PHP_EOL;

$compiled = $compiler->compile($ast);
echo "Compiled successfully: " . ($compiler->hasErrors() ? 'NO' : 'YES') . PHP_EOL;
echo "Compilation time: " . number_format($compiled['metadata']['compilation_time_ms'], 2) . "ms" . PHP_EOL;

$section = $compiled['children'][0];
echo "Section type: " . $section['attrs']['type'] . PHP_EOL;
echo "Section bg (default): " . $section['attrs']['bg'] . PHP_EOL;
echo "Section padding (default): " . $section['attrs']['padding'] . PHP_EOL;

echo PHP_EOL;

// Test 2: Complex nested template
echo "Test 2: Complex Nested Template" . PHP_EOL;
$template2 = '
{ikb_section type="content"}
    {ikb_block cols=3 gap=2}
        {ikb_card title="Card 1" variant="elevated" /}
        {ikb_card title="Card 2" variant="outlined" /}
        {ikb_card title="Card 3" variant="default" /}
    {/ikb_block}
{/ikb_section}
';

$tokens = $lexer->tokenize($template2);
$ast = $parser->parse($tokens);
$compiled = $compiler->compile($ast);

echo "Compiled successfully: " . ($compiler->hasErrors() ? 'NO' : 'YES') . PHP_EOL;
echo "Errors: " . count($compiler->getErrors()) . PHP_EOL;
echo "Warnings: " . count($compiler->getWarnings()) . PHP_EOL;
echo "Compilation time: " . number_format($compiled['metadata']['compilation_time_ms'], 2) . "ms" . PHP_EOL;

$section = $compiled['children'][0];
echo "Section type: " . $section['attrs']['type'] . PHP_EOL;

// Find block
$block = null;
foreach ($section['children'] as $child) {
    if ($child['type'] === 'tag' && $child['name'] === 'ikb_block') {
        $block = $child;
        break;
    }
}

if ($block) {
    echo "Block cols: " . $block['attrs']['cols'] . PHP_EOL;
    echo "Block gap: " . $block['attrs']['gap'] . PHP_EOL;
    echo "Block align (default): " . $block['attrs']['align'] . PHP_EOL;
    
    $cards = array_filter($block['children'], fn($c) => $c['type'] === 'tag' && $c['name'] === 'ikb_card');
    echo "Cards found: " . count($cards) . PHP_EOL;
}

echo PHP_EOL;

// Test 3: Validation errors
echo "Test 3: Validation Errors" . PHP_EOL;
$template3 = '{ikb_section type="invalid-type"}';

$tokens = $lexer->tokenize($template3);
$ast = $parser->parse($tokens);
$compiled = $compiler->compile($ast);

echo "Has errors: " . ($compiler->hasErrors() ? 'YES' : 'NO') . PHP_EOL;
echo "Error count: " . count($compiler->getErrors()) . PHP_EOL;

if ($compiler->hasErrors()) {
    foreach ($compiler->getErrors() as $error) {
        echo "  - " . $error['message'] . PHP_EOL;
    }
}

echo PHP_EOL;

// Test 4: Unknown component warning
echo "Test 4: Unknown Component Warning" . PHP_EOL;
$template4 = '{custom_unknown_component}';

$tokens = $lexer->tokenize($template4);
$ast = $parser->parse($tokens);
$compiled = $compiler->compile($ast);

echo "Has warnings: " . ($compiler->hasWarnings() ? 'YES' : 'NO') . PHP_EOL;
echo "Warning count: " . count($compiler->getWarnings()) . PHP_EOL;

if ($compiler->hasWarnings()) {
    foreach ($compiler->getWarnings() as $warning) {
        echo "  - " . $warning['message'] . PHP_EOL;
    }
}

echo PHP_EOL;

// Test 5: Real-world template
echo "Test 5: Real-World Template" . PHP_EOL;
$template5 = '
{ikb_section type="hero" bg="#f0f0f0"}
    {ikb_container width="lg"}
        {ikb_text size="2xl" weight="bold"}Welcome{/ikb_text}
    {/ikb_container}
{/ikb_section}

{ikb_section type="content"}
    {ikb_query type="post" limit=6}
        {ikb_card title="{item.title}" link="{item.url}" /}
    {/ikb_query}
{/ikb_section}
';

$startTime = microtime(true);
$tokens = $lexer->tokenize($template5);
$ast = $parser->parse($tokens);
$compiled = $compiler->compile($ast);
$totalTime = (microtime(true) - $startTime) * 1000;

echo "Total pipeline time: " . number_format($totalTime, 2) . "ms" . PHP_EOL;
echo "Compiled successfully: " . ($compiler->hasErrors() ? 'NO' : 'YES') . PHP_EOL;
echo "Root children: " . count($compiled['children']) . PHP_EOL;

$sections = array_filter($compiled['children'], fn($c) => $c['type'] === 'tag' && $c['name'] === 'ikb_section');
echo "Sections found: " . count($sections) . PHP_EOL;

echo PHP_EOL;
echo "=== All Tests Complete ===" . PHP_EOL;
