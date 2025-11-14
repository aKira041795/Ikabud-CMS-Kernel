<?php
/**
 * Debug AST structure
 */

require_once __DIR__ . '/vendor/autoload.php';

use IkabudKernel\Core\DiSyL\Engine;

$template = <<<'DISYL'
{ikb_query type="post" limit=3}
    <div class="post">
        <h2>{item.title}</h2>
        {if condition="item.thumbnail"}
            <img src="{item.thumbnail}" />
        {/if}
    </div>
{/ikb_query}
DISYL;

$engine = new Engine();
$ast = $engine->compile($template);

function printAST($node, $indent = 0) {
    $prefix = str_repeat('  ', $indent);
    
    if (isset($node['type'])) {
        echo $prefix . "- type: " . $node['type'];
        
        if ($node['type'] === 'tag') {
            echo ", name: " . $node['name'];
            if (!empty($node['attrs'])) {
                echo ", attrs: " . json_encode($node['attrs']);
            }
        } elseif ($node['type'] === 'text') {
            $preview = substr(trim($node['value']), 0, 50);
            if ($preview) {
                echo ", value: \"" . $preview . "...\"";
            }
        } elseif ($node['type'] === 'expression') {
            echo ", value: " . $node['value'];
        }
        
        echo "\n";
        
        if (isset($node['children']) && is_array($node['children'])) {
            echo $prefix . "  children (" . count($node['children']) . "):\n";
            foreach ($node['children'] as $child) {
                printAST($child, $indent + 2);
            }
        }
    } else {
        echo $prefix . "- (unknown structure)\n";
    }
}

echo "=== AST Structure ===\n\n";
printAST($ast);
