<?php
/**
 * Test home.disyl template parsing
 */

require_once __DIR__ . '/vendor/autoload.php';

use IkabudKernel\Core\DiSyL\Engine;

$templatePath = __DIR__ . '/instances/wp-brutus-cli/wp-content/themes/disyl-poc/disyl/home.disyl';

if (!file_exists($templatePath)) {
    die("Template not found: $templatePath\n");
}

$template = file_get_contents($templatePath);

echo "Template length: " . strlen($template) . " bytes\n\n";

$engine = new Engine();
$ast = $engine->compile($template);

echo "AST children count: " . count($ast['children']) . "\n\n";

// Show first 10 children
echo "First 10 children:\n";
foreach (array_slice($ast['children'], 0, 10) as $i => $child) {
    echo "  $i. type=" . $child['type'];
    if ($child['type'] === 'tag') {
        echo ", name=" . $child['name'];
        if (!empty($child['attrs'])) {
            echo ", attrs=" . json_encode($child['attrs']);
        }
        if (isset($child['children'])) {
            echo ", children=" . count($child['children']);
        }
    } elseif ($child['type'] === 'text') {
        $preview = substr(trim($child['value']), 0, 40);
        if ($preview) {
            echo ", value=\"" . $preview . "...\"";
        }
    }
    echo "\n";
}

// Look for ikb_section
echo "\nSearching for ikb_section tags:\n";
$sectionCount = 0;
foreach ($ast['children'] as $i => $child) {
    if ($child['type'] === 'tag' && $child['name'] === 'ikb_section') {
        $sectionCount++;
        echo "  Found at index $i, children=" . count($child['children'] ?? []) . "\n";
    }
}
echo "Total ikb_section tags: $sectionCount\n";

// Look for ikb_query
echo "\nSearching for ikb_query tags:\n";
$queryCount = 0;
function searchForQuery($node, $path = '') {
    global $queryCount;
    if (isset($node['type']) && $node['type'] === 'tag' && $node['name'] === 'ikb_query') {
        $queryCount++;
        echo "  Found at $path, attrs=" . json_encode($node['attrs'] ?? []) . "\n";
    }
    if (isset($node['children'])) {
        foreach ($node['children'] as $i => $child) {
            searchForQuery($child, $path . "[$i]");
        }
    }
}

foreach ($ast['children'] as $i => $child) {
    searchForQuery($child, "children[$i]");
}
echo "Total ikb_query tags: $queryCount\n";
