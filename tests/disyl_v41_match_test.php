<?php

declare(strict_types=1);

/**
 * DiSyL 4.1 — pattern-matching ({match}/{when}/{default}/{guard}) tests.
 *
 * Verifies the new control structure added in kernel 4.1.
 */

require_once __DIR__ . '/../bootstrap.php';

use Ikabud\Kernel\DiSyL\TemplateEngine;

$tmpDir = sys_get_temp_dir() . '/disyl-v41-match-' . uniqid('', true);
@mkdir($tmpDir . '/cache', 0777, true);

$engine = new TemplateEngine($tmpDir, $tmpDir . '/cache', false);

$pass = 0;
$fail = 0;
$failures = [];

function assert_render(
    TemplateEngine $engine,
    string $label,
    string $template,
    array $context,
    string $expected
): void {
    global $pass, $fail, $failures;
    $actual = trim($engine->renderString($template, $context));
    $expected = trim($expected);
    if ($actual === $expected) {
        $pass++;
        echo "  PASS: {$label}\n";
        return;
    }
    $fail++;
    $failures[] = $label;
    echo "  FAIL: {$label}\n";
    echo "    expected: " . var_export($expected, true) . "\n";
    echo "    actual:   " . var_export($actual, true) . "\n";
}

// 1. Single matching {when} with one literal pattern
assert_render(
    $engine,
    '1. single string pattern matches',
    "{match status}{when 'paid'}OK{default}OTHER{/match}",
    ['status' => 'paid'],
    'OK'
);

// 2. Multi-pattern {when}
assert_render(
    $engine,
    '2. multi pattern {when} matches second pattern',
    "{match status}{when 'paid', 'shipped'}OK{default}OTHER{/match}",
    ['status' => 'shipped'],
    'OK'
);

// 3. {default} fallback when nothing matches
assert_render(
    $engine,
    '3. {default} falls through',
    "{match status}{when 'paid'}OK{default}OTHER{/match}",
    ['status' => 'refunded'],
    'OTHER'
);

// 4. No match + no {default} → empty
assert_render(
    $engine,
    '4. no arms match, no default → empty',
    "{match status}{when 'paid'}OK{/match}",
    ['status' => 'refunded'],
    ''
);

// 5. Boolean / null / numeric patterns
assert_render(
    $engine,
    '5a. true literal pattern',
    '{match flag}{when true}YES{when false}NO{/match}',
    ['flag' => true],
    'YES'
);
assert_render(
    $engine,
    '5b. null literal pattern distinguished from false',
    '{match val}{when null}NULL{when false}FALSE{when 0}ZERO{default}OTHER{/match}',
    ['val' => null],
    'NULL'
);
assert_render(
    $engine,
    '5c. numeric pattern',
    '{match val}{when 42}FORTY-TWO{default}OTHER{/match}',
    ['val' => 42],
    'FORTY-TWO'
);
assert_render(
    $engine,
    '5d. wildcard _ always matches',
    "{match status}{when 'paid'}OK{when _}WILD{/match}",
    ['status' => 'anything'],
    'WILD'
);

// 6. {guard} blocks an otherwise-matching arm
assert_render(
    $engine,
    '6a. guard true permits arm',
    "{match status}{when 'refunded' guard partial}PARTIAL{when 'refunded'}FULL{/match}",
    ['status' => 'refunded', 'partial' => true],
    'PARTIAL'
);
assert_render(
    $engine,
    '6b. guard false skips to next arm',
    "{match status}{when 'refunded' guard partial}PARTIAL{when 'refunded'}FULL{/match}",
    ['status' => 'refunded', 'partial' => false],
    'FULL'
);

// 7. Nested {match} inside {match}
assert_render(
    $engine,
    '7. nested {match}',
    "{match outer}{when 'a'}{match inner}{when 'x'}AX{when 'y'}AY{/match}{when 'b'}B{/match}",
    ['outer' => 'a', 'inner' => 'y'],
    'AY'
);

// 8. {match} inside {if} chosen branch
assert_render(
    $engine,
    '8. {match} inside {if} branch',
    "{if show}{match status}{when 'paid'}OK{default}NO{/match}{/if}",
    ['show' => true, 'status' => 'paid'],
    'OK'
);

// 9. Identifier pattern resolved from context
assert_render(
    $engine,
    '9. identifier pattern resolved from context',
    "{match status}{when expected}EQ{default}NEQ{/match}",
    ['status' => 'shipped', 'expected' => 'shipped'],
    'EQ'
);

// 10. Variable interpolation inside arm body
assert_render(
    $engine,
    '10. arm body interpolates variables',
    "{match status}{when 'paid'}Order #{order_id} settled{default}other{/match}",
    ['status' => 'paid', 'order_id' => 123],
    'Order #123 settled'
);

echo "\n  PASS: {$pass}  FAIL: {$fail}\n";

if ($fail > 0) {
    echo "  FAILURES:\n";
    foreach ($failures as $f) {
        echo "    - {$f}\n";
    }
    exit(1);
}
exit(0);
