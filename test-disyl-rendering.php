<?php
/**
 * DiSyL Rendering Debug Script
 * 
 * Tests the complete DiSyL pipeline to identify where output is lost
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "=== DiSyL Rendering Debug Test ===\n\n";

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

use IkabudKernel\Core\DiSyL\{Lexer, Parser, Compiler};

// Simple test template
$template = <<<'DISYL'
{ikb_section type="hero" padding="large"}
    {ikb_container width="xl"}
        {ikb_text size="2xl" weight="bold" align="center"}
            Welcome to Test
        {/ikb_text}
    {/ikb_container}
{/ikb_section}
DISYL;

echo "1. Testing Lexer...\n";
$lexer = new Lexer();
$tokens = $lexer->tokenize($template);
echo "   ✓ Tokens generated: " . count($tokens) . "\n";
echo "   First 5 tokens:\n";
foreach (array_slice($tokens, 0, 5) as $i => $token) {
    echo "     [$i] {$token->type}: " . substr($token->value, 0, 30) . "\n";
}
echo "\n";

echo "2. Testing Parser...\n";
$parser = new Parser();
$ast = $parser->parse($tokens);
echo "   ✓ AST generated\n";
echo "   AST type: {$ast['type']}\n";
echo "   Children count: " . count($ast['children'] ?? []) . "\n";
echo "   AST structure:\n";
echo json_encode($ast, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n";

echo "3. Testing Compiler...\n";
$compiler = new Compiler();
$compiled = $compiler->compile($ast);
echo "   ✓ Compilation successful\n";
echo "   Compilation time: " . ($compiled['metadata']['compilation_time_ms'] ?? 'N/A') . "ms\n";
echo "   Errors: " . count($compiled['metadata']['errors'] ?? []) . "\n";
echo "   Warnings: " . count($compiled['metadata']['warnings'] ?? []) . "\n";
if (!empty($compiled['metadata']['warnings'])) {
    echo "   Warnings:\n";
    foreach ($compiled['metadata']['warnings'] as $warning) {
        echo "     - $warning\n";
    }
}
echo "\n";

echo "4. Testing Renderer (without WordPress)...\n";
require_once __DIR__ . '/kernel/DiSyL/Renderers/BaseRenderer.php';

// Create a minimal test renderer
class TestRenderer extends IkabudKernel\Core\DiSyL\Renderers\BaseRenderer
{
    protected function initializeCMS(): void
    {
        // No CMS initialization needed for test
    }
    
    protected function renderIkbSection(array $node, array $attrs, array $children): string
    {
        $type = $attrs['type'] ?? 'content';
        $padding = $attrs['padding'] ?? 'normal';
        
        $html = "<section class=\"ikb-section ikb-section-{$type}\" data-padding=\"{$padding}\">\n";
        $html .= $this->renderChildren($children);
        $html .= "</section>\n";
        
        return $html;
    }
    
    protected function renderIkbContainer(array $node, array $attrs, array $children): string
    {
        $width = $attrs['width'] ?? 'lg';
        
        $html = "<div class=\"ikb-container ikb-container-{$width}\">\n";
        $html .= $this->renderChildren($children);
        $html .= "</div>\n";
        
        return $html;
    }
    
    protected function renderIkbText(array $node, array $attrs, array $children): string
    {
        $size = $attrs['size'] ?? 'md';
        $weight = $attrs['weight'] ?? 'normal';
        $align = $attrs['align'] ?? 'left';
        
        $html = "<div class=\"ikb-text\" data-size=\"{$size}\" data-weight=\"{$weight}\" data-align=\"{$align}\">\n";
        $html .= $this->renderChildren($children);
        $html .= "</div>\n";
        
        return $html;
    }
}

$renderer = new TestRenderer();
$output = $renderer->render($compiled);

echo "   ✓ Rendering successful\n";
echo "   Output length: " . strlen($output) . " bytes\n";
echo "   Output preview:\n";
echo "---BEGIN OUTPUT---\n";
echo $output;
echo "---END OUTPUT---\n\n";

echo "5. Analyzing output...\n";
if (empty($output)) {
    echo "   ✗ ERROR: Output is empty!\n";
    echo "   This indicates the renderer is not producing any HTML.\n";
} elseif (strlen($output) < 50) {
    echo "   ⚠ WARNING: Output is very short (< 50 bytes)\n";
} else {
    echo "   ✓ Output looks good\n";
}

echo "\n=== Test Complete ===\n";
