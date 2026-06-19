<?php

declare(strict_types=1);

/**
 * DiSyL v4.0 Compiler — Unit Tests
 *
 * Tests the compiled template path end-to-end:
 *   Source → Parser → AST → TemplateCompiler → PHP class → execute → output
 *
 * Run from repo root: php tests/disyl_v4_compiler_test.php
 */

require_once __DIR__ . '/../kernel/DiSyL/TemplateEngine.php';
require_once __DIR__ . '/../kernel/DiSyL/Compiler/TemplateCache.php';

use Ikabud\Kernel\DiSyL\TemplateEngine;

$engine = new TemplateEngine(__DIR__ . '/../templates', '/tmp/disyl_compiler_test', false);
$engine->enableCompiledMode(true);

$pass = 0;
$fail = 0;

function check(string $desc, string $expected, string $actual): void
{
    global $pass, $fail;
    $expected = trim($expected);
    $actual = trim($actual);
    if ($expected === $actual) {
        echo "  \033[32m✓\033[0m {$desc}\n";
        $pass++;
    } else {
        echo "  \033[31m✗\033[0m {$desc}\n";
        echo "    Expected: " . json_encode($expected) . "\n";
        echo "    Actual:   " . json_encode($actual) . "\n";
        $fail++;
    }
}

echo "╔══════════════════════════════════════════════════════╗\n";
echo "║   DiSyL v4 COMPILER — UNIT TESTS                     ║\n";
echo "╚══════════════════════════════════════════════════════╝\n\n";

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
echo "── 1. Variable output ────────────────────────────────\n";

check('simple variable', 'Hello',
    $engine->renderString('{name}', ['name' => 'Hello']));
check('nested property', 'Alice',
    $engine->renderString('{user.name}', ['user' => ['name' => 'Alice']]));
check('default filter null', 'Guest',
    $engine->renderString('{name|default:"Guest"}', ['name' => null]));
check('default filter empty', 'Guest',
    $engine->renderString('{name|default:"Guest"}', ['name' => '']));
check('auto-escaping', '&lt;b&gt;text&lt;/b&gt;',
    $engine->renderString('{html}', ['html' => '<b>text</b>']));
check('raw filter', '<b>text</b>',
    $engine->renderString('{html|raw}', ['html' => '<b>text</b>']));

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
echo "── 2. Filters ────────────────────────────────────────\n";

check('upper', 'HELLO',
    $engine->renderString('{name|upper}', ['name' => 'hello']));
check('lower', 'hello',
    $engine->renderString('{name|lower}', ['name' => 'HELLO']));
check('truncate positional', 'abc...',
    $engine->renderString('{s|truncate:3}', ['s' => 'abcdefgh']));
check('truncate named', 'abc...',
    $engine->renderString('{s|truncate:length=3}', ['s' => 'abcdefgh']));
check('date filter', '2026-06-19',
    $engine->renderString('{d|date:"Y-m-d"}', ['d' => '2026-06-19']));
check('chain filters', 'HEL...',
    $engine->renderString('{s|upper|truncate:3}', ['s' => 'hello world']));

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
echo "── 3. Control structures ─────────────────────────────\n";

check('if/else true', 'yes',
    $engine->renderString('{if x}yes{else}no{/if}', ['x' => true]));
check('if/else false', 'no',
    $engine->renderString('{if x}yes{else}no{/if}', ['x' => false]));
check('if/elseif', 'maybe',
    $engine->renderString('{if a}x{elseif b}maybe{else}z{/if}', ['a' => false, 'b' => true]));
check('for loop', 'ABC',
    $engine->renderString('{for item in items}{item}{/for}', ['items' => ['A', 'B', 'C']]));
check('for/empty', 'none',
    $engine->renderString('{for item in items}{item}{empty}none{/for}', ['items' => []]));

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
echo "── 4. {match} pattern matching ───────────────────────\n";

check('match first', 'Green',
    $engine->renderString('{match s}{when "a"}Green{/when}{when "b"}Blue{/when}{else}Gray{/match}', ['s' => 'a']));
check('match second', 'Blue',
    $engine->renderString('{match s}{when "a"}Green{/when}{when "b"}Blue{/when}{else}Gray{/match}', ['s' => 'b']));
check('match else', 'Gray',
    $engine->renderString('{match s}{when "a"}Green{/when}{when "b"}Blue{/when}{else}Gray{/match}', ['s' => 'c']));
check('match wildcard', 'Any',
    $engine->renderString('{match s}{when "a"}A{/when}{when _}Any{/when}{/match}', ['s' => 'xyz']));
check('match multi', 'Hit',
    $engine->renderString('{match s}{when "a","b","c"}Hit{/when}{else}Miss{/match}', ['s' => 'b']));

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
echo "── 5. {set} assignment ───────────────────────────────\n";

check('set simple', '42',
    $engine->renderString('{set x = 42}{x}', []));
check('set typed', 'Alice',
    $engine->renderString('{set name: string = "Alice"}{name}', []));
check('set literal union valid', 'open',
    $engine->renderString('{set s: "open"|"closed" = "open"}{s}', []));
check('set literal union invalid', 'open',
    $engine->renderString('{set s: "open"|"closed" = "bogus"}{s}', []));

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
echo "── 6. {macro} user-defined ───────────────────────────\n";

check('macro basic', 'Hello, World!',
    $engine->renderString('{macro g(n)}Hello, {n}!{/macro}{call g("World")}', []));
check('macro default param', 'Info',
    $engine->renderString('{macro box(title="Info")}{title}{/macro}{call box}', []));
check('macro with context', 'Alice-Admin',
    $engine->renderString('{macro u(name,role)}{name}-{role}{/macro}{call u(uname,urole)}', ['uname' => 'Alice', 'urole' => 'Admin']));
check('macro multiple calls', 'A,B,C',
    $engine->renderString('{macro item(n)}{n}{/macro}{call item("A")},{call item("B")},{call item("C")}', []));

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
echo "── 7. {await} sync path ──────────────────────────────\n";

check('await then', 'Hello, World!',
    $engine->renderString('{await name}{then}Hello, {value}!{/then}{loading}Wait{/loading}{/await}', ['name' => 'World']));
check('await body', 'Got: OK',
    $engine->renderString('{await result}Got: {result}{loading}Loading{/loading}{catch}e{/catch}{/await}', ['result' => 'OK']));

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
echo "── 8. {verbatim} / {literal} ─────────────────────────\n";

check('verbatim inert', '{if x}y{/if}',
    $engine->renderString('{verbatim}{if x}y{/if}{/verbatim}', ['x' => true]));
check('literal inert', '{name}',
    $engine->renderString('{literal}{name}{/literal}', ['name' => 'Alice']));

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
echo "── 9. Expressions ────────────────────────────────────\n";

check('arithmetic add', '5',
    $engine->renderString('{a + b}', ['a' => 2, 'b' => 3]));
check('arithmetic multiply', '6',
    $engine->renderString('{a * b}', ['a' => 2, 'b' => 3]));
check('ternary true', 'Yes',
    $engine->renderString('{x ? "Yes" : "No"}', ['x' => true]));
check('ternary false', 'No',
    $engine->renderString('{x ? "Yes" : "No"}', ['x' => false]));

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
echo "── 10. Compiled mode output matches interpreted ──────\n";

// Test that compiled and interpreted paths produce identical output
$testCases = [
    ['{name}', ['name' => 'Alice']],
    ['{if x}yes{else}no{/if}', ['x' => true]],
    ['{for i in items}{i}{/for}', ['items' => [1, 2, 3]]],
    ['{match s}{when "a"}A{/when}{else}Z{/match}', ['s' => 'a']],
];

foreach ($testCases as $i => [$template, $ctx]) {
    $engine2 = new TemplateEngine(__DIR__ . '/../templates', '/tmp/disyl_compiler_test_' . $i, false);
    $engine2->enableCompiledMode(true);
    $compiled = $engine2->renderString($template, $ctx);

    $engine3 = new TemplateEngine(__DIR__ . '/../templates', '/tmp/disyl_compiler_test_i' . $i, false);
    $engine3->enableCompiledMode(false);
    $interpreted = $engine3->renderString($template, $ctx);

    check("compiled vs interpreted #{$i}", $interpreted, $compiled);
}

echo "\n╔══════════════════════════════════════════════════════╗\n";
printf("║  RESULTS:  %2d PASSED  |  %2d FAILED                     ║\n", $pass, $fail);
echo "╚══════════════════════════════════════════════════════╝\n";

exit($fail > 0 ? 1 : 0);
