<?php
/**
 * Test how {if} and {ikb_query} in text are parsed
 */

require_once __DIR__ . '/vendor/autoload.php';

use IkabudKernel\Core\DiSyL\Engine;

$template = '<h1>Test {if} and {ikb_query} in text</h1>';

$engine = new Engine();
$ast = $engine->compile($template);

function printAST($node, $indent = 0) {
    $prefix = str_repeat('  ', $indent);
    
    if (isset($node['type'])) {
        echo $prefix . "- type: " . $node['type'];
        
        if ($node['type'] === 'tag') {
            echo ", name: " . $node['name'];
        } elseif ($node['type'] === 'text') {
            echo ", value: \"" . $node['value'] . "\"";
        } elseif ($node['type'] === 'expression') {
            echo ", value: " . $node['value'];
        }
        
        echo "\n";
        
        if (isset($node['children']) && is_array($node['children'])) {
            foreach ($node['children'] as $child) {
                printAST($child, $indent + 1);
            }
        }
    }
}

echo "Template: $template\n\n";
echo "AST:\n";
printAST($ast);
