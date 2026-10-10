<?php
declare(strict_types=1);

/**
 * Regression: {set} statement forms, and the script/style brace-protection passes.
 *
 * Why these two together: as of 2026-10-10 both were rewritten for performance and BOTH rewrites were
 * intended to be output-neutral, which is exactly the kind of change that needs its semantics pinned.
 * - processSetStatements() stopped doing `substr($content, $i)` per character and now jumps between
 *   braces (it was O(n^2): 16.26 ms of a 19.37 ms render of modules/cms/public/home.disyl).
 * - compileScriptBody()/compileStyleBody() stopped appending one array element per character
 *   (26,081 chunks to serve 308 braces) and now copy runs between braces.
 *
 * The output differential over all 555 templates is the stronger check and it passed, but it only covers
 * syntax the repository actually uses. These cases pin the SHAPES: compound assignment, postfix ++/--,
 * the {var = expr} shorthand without the `set` keyword, typed assignment, and braces nested inside
 * JavaScript objects, which is what the marker pass exists to protect.
 *
 * READ THIS BEFORE TREATING IT AS PROOF OF THE REFACTOR: this test passes 30/30 against BOTH the
 * pre-refactor and post-refactor engine. That is expected, not a defect — the refactor's entire claim is
 * that behaviour is unchanged, so no behaviour test can discriminate it. What proves the refactor is the
 * 555-template byte-comparison against the committed baseline. This file exists to go RED if a FUTURE
 * change alters any of these shapes; it is regression protection, not evidence for that change.
 *
 *   php tests/disyl_set_and_brace_pass_test.php
 */

require __DIR__ . '/../bootstrap.php';

use Ikabud\Kernel\DiSyL\TemplateEngine;

$cacheRoot = sys_get_temp_dir() . '/disyl-set-brace-cache-' . getmypid();
@mkdir($cacheRoot, 0777, true);

$_ENV['APP_TIMING_LOGS'] = 'true';
$_ENV['APP_TIMING_THRESHOLD_MS'] = '0';

$passed = 0;
$failed = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    if ($ok) {
        $passed++;
        echo "  ✓ {$label}\n";
        return;
    }
    $failed++;
    echo "  ✗ {$label}" . ($detail !== '' ? ": {$detail}" : '') . "\n";
}

$engine = new TemplateEngine(TEMPLATES_PATH, $cacheRoot, true);
$engine->enableCompiledMode(true);

/** Render a template source string through the interpreted pipeline. */
function renderSource(string $cacheRoot, string $source, array $context = [], string $label = 'case'): array
{
    $engine = new TemplateEngine(TEMPLATES_PATH, $cacheRoot . '/' . md5($source . $label), true);
    $engine->enableCompiledMode(true);
    try {
        return ['out' => $engine->renderString($source, $context), 'err' => ''];
    } catch (Throwable $e) {
        return ['out' => '', 'err' => $e->getMessage()];
    }
}

echo "=== {set} statement forms ===\n";

// Note on output form: in the main template body a variable is emitted as {name}. The {$name} form is
// script-context syntax (see the script cases below) and is NOT interpolated in the body — so these cases
// use {name}. Getting that wrong makes every case here fail for a reason that has nothing to do with {set}.
$setCases = [
    // label                                   source                                          context                 expected
    ['plain assignment',                       '{set x = 1}{x}',                               [],                     '1'],
    ['self-reference in the same document',    '{set x = 1}{set x = x + 2}{x}',                [],                     '3'],
    ['string value',                           '{set s = "a"}{s}',                            [],                     'a'],
    ['typed assignment ({set x: string = ..})', '{set x: string = "hi"}{x}',                  [],                     'hi'],
    ['compound +=',                            '{set c = 5}{set c += 3}{c}',                   [],                     '8'],
    ['compound -=',                            '{set d = 9}{set d -= 4}{d}',                   [],                     '5'],
    ['compound *=',                            '{set m = 3}{set m *= 2}{m}',                   [],                     '6'],
    ['postfix ++',                             '{set i = 1}{set i++}{i}',                      [],                     '2'],
    ['value derived from another variable',    '{set v = 2}{set w = v * 3}{w}',                [],                     '6'],
    ['shorthand {var = expr} (no `set`)',      'x{set none = 1}{y = 7}{y}z',                   [],                     'x7z'],
    ['set then conditional',                   '{set v = 1}{if v == 1}yes{/if}',               [],                     'yes'],
    ['empty context falls back',               '{set t = 0}{t}',                               [],                     '0'],
    ['no braces at all is passed through',     'no braces at all',                             [],                     'no braces at all'],
    ['a literal block keeps its braces',       '{set z = 1}tail{literal} {x} {/literal}end',   [],                     'tail {x} end'],
    ['filters on a set value',                 '{set u = "abc"}{u|upper}',                    [],                     'ABC'],
];

foreach ($setCases as [$label, $source, $context, $expected]) {
    $r = renderSource($cacheRoot, $source, $context, $label);
    $ok = $r['err'] === '' && $r['out'] === $expected;
    check($label, $ok, $r['err'] !== '' ? 'THREW: ' . $r['err'] : 'got ' . var_export($r['out'], true) . ', want ' . var_export($expected, true));
}

echo "\n=== script body: JavaScript braces are host syntax, not DiSyL ===\n";

$scriptCases = [
    ['single object literal preserved',   '<script>const o = {a: 1};</script>',                       [],              '<script>const o = {a: 1};</script>'],
    ['nested object literals preserved',  '<script>const o = {a: 1, b: {c: 2}};</script>',             [],              '<script>const o = {a: 1, b: {c: 2}};</script>'],
    ['block body preserved',              '<script>function f() { return 1; }</script>',              [],              '<script>function f() { return 1; }</script>'],
    ['newline-separated JS braces',       "<script>if (x) {\n  y();\n}</script>",                     [],              "<script>if (x) {\n  y();\n}</script>"],
    ['DiSyL variable inside a script',    '<script>const n = {count};</script>',                      ['count' => 5],  '<script>const n = 5;</script>'],
    ['null-coalescing inside a script',   '<script>const n = {missing ?? 0};</script>',                [],              '<script>const n = 0;</script>'],
    ['object literal beside a variable',  '<script>const o = {a: {count}, b: 2};</script>',            ['count' => 7],  '<script>const o = {a: 7, b: 2};</script>'],
];

foreach ($scriptCases as [$label, $source, $context, $expected]) {
    $r = renderSource($cacheRoot, $source, $context, $label);
    $ok = $r['err'] === '' && $r['out'] === $expected;
    check($label, $ok, $r['err'] !== '' ? 'THREW: ' . $r['err'] : 'got ' . var_export($r['out'], true) . ', want ' . var_export($expected, true));
}

echo "\n=== style body: CSS braces are host syntax, not DiSyL ===\n";

$styleCases = [
    ['css rule preserved',                '<style>.a{color:red}</style>',                    [],                  '<style>.a{color:red}</style>'],
    ['media query with nested braces',    '<style>@media (min-width:700px){.a{color:red}}</style>', [],            '<style>@media (min-width:700px){.a{color:red}}</style>'],
    ['id selector and nested rule',       '<style>#runner{grid-template-columns:1fr}</style>', [],                '<style>#runner{grid-template-columns:1fr}</style>'],
    ['DiSyL variable inside a style',     '<style>.a{color:{accent}}</style>',               ['accent' => '#fff'], '<style>.a{color:#fff}</style>'],
];

foreach ($styleCases as [$label, $source, $context, $expected]) {
    $r = renderSource($cacheRoot, $source, $context, $label);
    $ok = $r['err'] === '' && $r['out'] === $expected;
    check($label, $ok, $r['err'] !== '' ? 'THREW: ' . $r['err'] : 'got ' . var_export($r['out'], true) . ', want ' . var_export($expected, true));
}

echo "\n=== scale: many braces must not change the result ===\n";

// 200 object literals plus one interpolation. This is the shape that made the old per-character loop
// expensive, so it also guards the rewrite against an off-by-one at a run boundary.
$many = '<script>';
for ($i = 0; $i < 200; $i++) {
    $many .= "const o{$i} = {a: {$i}, b: {c: {$i}}};";
}
$many .= 'const total = {count};</script>';
$r = renderSource($cacheRoot, $many, ['count' => 42], 'many');
$expectedMany = str_replace('{count}', '42', $many);
check('200 object literals + one interpolation', $r['err'] === '' && $r['out'] === $expectedMany,
    $r['err'] !== '' ? 'THREW: ' . $r['err'] : 'output differed from source');
check('brace count preserved at scale',
    substr_count($r['out'], '{') === substr_count($expectedMany, '{') && substr_count($r['out'], '}') === substr_count($expectedMany, '}'),
    'braces ' . substr_count($r['out'], '{') . '/' . substr_count($r['out'], '}') . ' vs expected ' . substr_count($expectedMany, '{') . '/' . substr_count($expectedMany, '}'));

// Long literal run with no braces: exercises the run-copy path and the end-of-body tail.
$longRun = '<script>' . str_repeat('var pad = "x";', 500) . 'const t = {count};</script>';
$r = renderSource($cacheRoot, $longRun, ['count' => 3], 'longrun');
check('long brace-free run preserved', $r['err'] === '' && $r['out'] === str_replace('{count}', '3', $longRun),
    $r['err'] !== '' ? 'THREW: ' . $r['err'] : 'output differed');

// Trailing text after the last brace must survive (the loop breaks and appends the tail).
$tail = '{set v = 1}after{literal}{notatag}{/literal}'; 
$r = renderSource($cacheRoot, $tail, [], 'tail');
check('text after the final brace is preserved', $r['err'] === '' && $r['out'] === 'after{notatag}',
    $r['err'] !== '' ? 'THREW: ' . $r['err'] : 'got ' . var_export($r['out'], true));

// Cleanup
if (is_dir($cacheRoot)) {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($cacheRoot, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($cacheRoot);
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
