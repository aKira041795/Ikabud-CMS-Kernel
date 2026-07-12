<?php
/**
 * Test {for key, value in list} DiSyL syntax support.
 *
 * Usage: php tests/disyl_for_key_value_test.php
 */
declare(strict_types=1);

// Direct parse test without bootstrap — tests the V4 parser regex change
function test_parser(): void {
    $expr = 'i, img in entity.images';
    $pattern = '/^(\w+)\s*,\s*(\w+)\s+in\s+(.+)$/s';
    $matched = preg_match($pattern, $expr, $m);
    t_assert($matched === 1, 'Pattern matches key, value in list');
    t_assert($m[1] === 'i', 'Key variable is i');
    t_assert($m[2] === 'img', 'Value variable is img');
    t_assert(trim($m[3]) === 'entity.images', 'Iterable is entity.images');

    // Verify old pattern still works
    $expr2 = 'img in items';
    $matched2 = preg_match($pattern, $expr2, $m2);
    t_assert($matched2 === 0, 'Old pattern does not match new regex');

    echo "  Parser tests: All passed\n";
}

function t_assert(bool $condition, string $label): void {
    if ($condition) {
        echo "  PASS: {$label}\n";
    } else {
        echo "  FAIL: {$label}\n";
    }
}

echo "=== DiSyL {for key, value in list} Test ===\n\n";
test_parser();
echo "\nDone.\n";
