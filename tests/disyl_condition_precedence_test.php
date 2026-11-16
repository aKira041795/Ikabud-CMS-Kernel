<?php
/**
 * DiSyL condition semantics — boolean precedence and HTML entities in expressions.
 *
 * Two defects measured 2026-11-16 on the Daily Ledger (Miputak, 11 Nov 2026):
 *
 * 1. The interpreted pipeline split a condition on `&&` BEFORE `||`, and split it
 *    without regard to parentheses. The ledger's Close PM guard
 *      (shift == 'PM' || (single_shift_mode && shift == 'AM')) && day_status == 'open' && !reference_only
 *    therefore evaluated FALSE on a PM shift with an open day — the client could not
 *    close a day they had just reopened. `&&` also binds tighter than `||`, so an
 *    AND-first split inverts the documented precedence.
 *
 * 2. `{if notice.finding_count &gt; 1}` (an HTML entity inside an expression) compiled to
 *    a BITWISE `&` against the string `gt; 1`. That threw `Unsupported operand types:
 *    int & string` at RENDER time, so TemplateEngine silently re-rendered the entire
 *    page on the interpreted pipeline (where defect 1 then hid the button). A single
 *    `&gt;` demoted the biggest screen in the module to legacy rendering, permanently
 *    and invisibly.
 *
 * Both pipelines must agree on both counts, so every case is asserted twice.
 */

require_once __DIR__ . '/../bootstrap.php';

use Ikabud\Kernel\DiSyL\TemplateEngine;
use Ikabud\Kernel\DiSyL\Compiler\TemplateCache;
use Ikabud\Kernel\DiSyL\ExpressionSource;
use Ikabud\Kernel\DiSyL\v4\RenderContext;

$pass = 0;
$fail = 0;

function check(string $desc, string $expected, string $actual): void
{
    global $pass, $fail;
    $expected = trim($expected);
    $actual = trim($actual);
    if ($expected === $actual) {
        echo "  ✓ {$desc}\n";
        $pass++;
        return;
    }
    echo "  ✗ {$desc}\n";
    echo "    Expected: " . json_encode($expected) . "\n";
    echo "    Actual:   " . json_encode($actual) . "\n";
    $fail++;
}

$tmpRoot = sys_get_temp_dir() . '/disyl_cond_' . getmypid();
@mkdir($tmpRoot . '/cache/compiled', 0777, true);
@mkdir($tmpRoot . '/templates', 0777, true);

$engine = new TemplateEngine($tmpRoot . '/templates', $tmpRoot . '/cache');
$cache = new TemplateCache($tmpRoot . '/cache/compiled', true);

/** Render the same source through both pipelines and return [interpreted, compiled, errors]. */
function bothWays(TemplateEngine $engine, TemplateCache $cache, string $source, array $ctx, string $name): array
{
    $interpreted = $engine->renderString($source, $ctx);

    $errors = [];
    $template = $cache->compileSource($source, $name);
    $template->setErrorHandler(static function (string $error) use (&$errors): void {
        $errors[] = $error;
    });
    try {
        $compiled = $template->executeWithInheritance(new RenderContext($ctx));
    } catch (\Throwable $e) {
        $compiled = 'THREW: ' . $e->getMessage();
    }

    return [$interpreted, $compiled, $errors];
}

// ── The ledger guard, verbatim ───────────────────────────────────────────────

echo "── Close PM guard (the reported defect) " . str_repeat('─', 26) . "\n";

$guard = "{if (shift == 'PM' || (single_shift_mode && shift == 'AM')) && day_status == 'open' && !reference_only}SHOWN{else}HIDDEN{/if}";

$matrix = [
    'PM shift, open day, branch role'      => [['shift' => 'PM', 'single_shift_mode' => false, 'day_status' => 'open', 'reference_only' => false], 'SHOWN'],
    'PM shift, open day, reference only'   => [['shift' => 'PM', 'single_shift_mode' => false, 'day_status' => 'open', 'reference_only' => true], 'HIDDEN'],
    'PM shift, closed day'                 => [['shift' => 'PM', 'single_shift_mode' => false, 'day_status' => 'closed', 'reference_only' => false], 'HIDDEN'],
    'AM shift, two-shift branch'           => [['shift' => 'AM', 'single_shift_mode' => false, 'day_status' => 'open', 'reference_only' => false], 'HIDDEN'],
    'AM shift, single-shift branch'        => [['shift' => 'AM', 'single_shift_mode' => true, 'day_status' => 'open', 'reference_only' => false], 'SHOWN'],
];

foreach ($matrix as $label => [$ctx, $expected]) {
    [$interpreted, $compiled] = bothWays($engine, $cache, $guard, $ctx, 'guard');
    check("interpreted: {$label}", $expected, $interpreted);
    check("compiled:    {$label}", $expected, $compiled);
}

// ── Operator precedence, stated plainly ──────────────────────────────────────

echo "\n── Boolean precedence " . str_repeat('─', 44) . "\n";

$precedence = [
    // AND binds tighter than OR: a || (b && c)
    ['a || b && c',    ['a' => true,  'b' => false, 'c' => false], 'YES'],
    ['a || b && c',    ['a' => false, 'b' => true,  'c' => true],  'YES'],
    ['a || b && c',    ['a' => false, 'b' => true,  'c' => false], 'NO'],
    // Parentheses win over precedence
    ['(a || b) && c',  ['a' => true,  'b' => false, 'c' => false], 'NO'],
    ['(a || b) && c',  ['a' => false, 'b' => true,  'c' => true],  'YES'],
    // An OR nested inside an AND group must not swallow the whole expression
    ['a && (b || c)',  ['a' => true,  'b' => false, 'c' => true],  'YES'],
    ['a && (b || c)',  ['a' => false, 'b' => true,  'c' => true],  'NO'],
    // A quoted `or`/`and` is data, not an operator
    ["word == 'or' && b", ['word' => 'or', 'b' => true],           'YES'],
];

foreach ($precedence as [$expr, $ctx, $expected]) {
    $source = "{if {$expr}}YES{else}NO{/if}";
    [$interpreted, $compiled] = bothWays($engine, $cache, $source, $ctx, 'precedence');
    check("interpreted: {$expr}  " . json_encode($ctx), $expected, $interpreted);
    check("compiled:    {$expr}  " . json_encode($ctx), $expected, $compiled);
}

// ── HTML entities inside expressions ────────────────────────────────────────

echo "\n── Entities in expressions " . str_repeat('─', 38) . "\n";

$entityCases = [
    ['{if n &gt; 1}BIG{else}SMALL{/if}', ['n' => 3], 'BIG'],
    ['{if n &gt; 1}BIG{else}SMALL{/if}', ['n' => 1], 'SMALL'],
    ['{if n &lt; 1}UNDER{else}NOT{/if}', ['n' => 0], 'UNDER'],
    ['{if a &amp;&amp; b}YES{else}NO{/if}', ['a' => true, 'b' => true], 'YES'],
    ['{if a &amp;&amp; b}YES{else}NO{/if}', ['a' => true, 'b' => false], 'NO'],
];

foreach ($entityCases as [$source, $ctx, $expected]) {
    [$interpreted, $compiled, $errors] = bothWays($engine, $cache, $source, $ctx, 'entities');
    check("interpreted: {$source}", $expected, $interpreted);
    check("compiled:    {$source}", $expected, $compiled);
    check("compiled does not fall back: {$source}", 'none', $errors === [] ? 'none' : implode('; ', $errors));
}

// A quoted entity is DATA — decoding it would double-escape or silently rewrite a label.
check(
    'quoted entity is left alone',
    "'A &amp; B'",
    ExpressionSource::decodeEntities("'A &amp; B'")
);
check(
    'unquoted entity becomes the operator',
    'a > 1',
    ExpressionSource::decodeEntities('a &gt; 1')
);
check(
    'plain expression untouched',
    'a > 1 && b',
    ExpressionSource::decodeEntities('a > 1 && b')
);

// ── Summary ─────────────────────────────────────────────────────────────────

$total = $pass + $fail;
echo "\n" . str_repeat('─', 70) . "\n";
echo "  {$pass}/{$total} passed" . ($fail > 0 ? ", {$fail} FAILED" : '') . "\n";

$dir = sys_get_temp_dir() . '/disyl_cond_' . getmypid();
foreach (glob($dir . '/*.php') ?: [] as $f) {
    @unlink($f);
}
@rmdir($dir);

exit($fail > 0 ? 1 : 0);
