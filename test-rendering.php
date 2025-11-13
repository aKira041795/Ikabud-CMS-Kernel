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
require 'kernel/DiSyL/Renderers/BaseRenderer.php';
require 'kernel/DiSyL/Renderers/NativeRenderer.php';
require 'cms/CMSInterface.php';
require 'cms/Adapters/NativeAdapter.php';

use IkabudKernel\Core\DiSyL\{Lexer, Parser, Compiler};
use IkabudKernel\CMS\Adapters\NativeAdapter;

echo "=== DiSyL Rendering Test ===" . PHP_EOL . PHP_EOL;

$lexer = new Lexer();
$parser = new Parser();
$compiler = new Compiler();

// Test 1: Simple section rendering
echo "Test 1: Simple Section" . PHP_EOL;
$template1 = '{ikb_section type="hero" title="Welcome" bg="#f0f0f0"}
    {ikb_text size="xl" weight="bold"}Hello World{/ikb_text}
{/ikb_section}';

$tokens = $lexer->tokenize($template1);
$ast = $parser->parse($tokens);
$compiled = $compiler->compile($ast);

// Mock Native CMS
$cms = new class extends NativeAdapter {
    public function __construct() {
        // Skip initialization for testing
    }
};

$html = $cms->renderDisyl($compiled);
echo "HTML Output:" . PHP_EOL;
echo $html . PHP_EOL;
echo PHP_EOL;

// Test 2: Card grid
echo "Test 2: Card Grid" . PHP_EOL;
$template2 = '{ikb_section type="content"}
    {ikb_block cols=3 gap=2}
        {ikb_card title="Card 1" variant="elevated" /}
        {ikb_card title="Card 2" variant="outlined" /}
        {ikb_card title="Card 3" variant="default" /}
    {/ikb_block}
{/ikb_section}';

$tokens = $lexer->tokenize($template2);
$ast = $parser->parse($tokens);
$compiled = $compiler->compile($ast);

$html = $cms->renderDisyl($compiled);
echo "HTML Output:" . PHP_EOL;
echo $html . PHP_EOL;
echo PHP_EOL;

// Test 3: Image rendering
echo "Test 3: Image" . PHP_EOL;
$template3 = '{ikb_image src="logo.png" alt="Logo" width=200 height=100 lazy=true}';

$tokens = $lexer->tokenize($template3);
$ast = $parser->parse($tokens);
$compiled = $compiler->compile($ast);

$html = $cms->renderDisyl($compiled);
echo "HTML Output:" . PHP_EOL;
echo $html . PHP_EOL;
echo PHP_EOL;

// Test 4: Container with text
echo "Test 4: Container with Text" . PHP_EOL;
$template4 = '{ikb_container width="lg" center=true}
    {ikb_text size="2xl" weight="bold" align="center"}
        Welcome to Our Site
    {/ikb_text}
    {ikb_text size="lg" align="center" color="#666"}
        Discover amazing content
    {/ikb_text}
{/ikb_container}';

$tokens = $lexer->tokenize($template4);
$ast = $parser->parse($tokens);
$compiled = $compiler->compile($ast);

$html = $cms->renderDisyl($compiled);
echo "HTML Output:" . PHP_EOL;
echo $html . PHP_EOL;
echo PHP_EOL;

// Test 5: Complex real-world template
echo "Test 5: Real-World Template" . PHP_EOL;
$template5 = '{ikb_section type="hero" bg="#333" padding="large"}
    {ikb_container width="xl"}
        {ikb_text size="2xl" weight="bold" align="center" color="#fff"}
            Welcome to Ikabud
        {/ikb_text}
    {/ikb_container}
{/ikb_section}

{ikb_section type="content"}
    {ikb_container width="lg"}
        {ikb_block cols=3 gap=2}
            {ikb_card title="Feature 1" variant="elevated"}
                {ikb_text}Amazing feature description{/ikb_text}
            {/ikb_card}
            {ikb_card title="Feature 2" variant="elevated"}
                {ikb_text}Another great feature{/ikb_text}
            {/ikb_card}
            {ikb_card title="Feature 3" variant="elevated"}
                {ikb_text}One more feature{/ikb_text}
            {/ikb_card}
        {/ikb_block}
    {/ikb_container}
{/ikb_section}';

$tokens = $lexer->tokenize($template5);
$ast = $parser->parse($tokens);
$compiled = $compiler->compile($ast);

$html = $cms->renderDisyl($compiled);
echo "HTML Output (first 500 chars):" . PHP_EOL;
echo substr($html, 0, 500) . '...' . PHP_EOL;
echo PHP_EOL;
echo "Total HTML length: " . strlen($html) . " bytes" . PHP_EOL;

echo PHP_EOL;
echo "=== All Rendering Tests Complete ===" . PHP_EOL;
