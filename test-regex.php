<?php

$text = 'About {author_name | default:"{node.author}" | esc_html}';

echo "Testing regex pattern matching:\n";
echo "Text: $text\n\n";

// Test different patterns
$patterns = [
    'Current (broken)' => '/\{([a-zA-Z0-9_.]+)\s*\|([^}]*(?:\{[^}]*\}[^}]*)*)\}/',
    'Greedy approach' => '/\{([a-zA-Z0-9_.]+)\s*\|(.*)\}/U',
    'Balanced braces' => '/\{([a-zA-Z0-9_.]+)\s*\|((?:[^{}]|\{[^}]*\})*)\}/',
];

foreach ($patterns as $name => $pattern) {
    echo "--- Testing: $name ---\n";
    echo "Pattern: $pattern\n";
    
    if (preg_match($pattern, $text, $matches)) {
        echo "✓ Matched!\n";
        echo "  Full: " . $matches[0] . "\n";
        echo "  Expr: " . $matches[1] . "\n";
        echo "  Filters: " . $matches[2] . "\n";
    } else {
        echo "✗ No match\n";
    }
    echo "\n";
}
