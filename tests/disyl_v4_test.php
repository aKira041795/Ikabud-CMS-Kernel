<?php
/**
 * DiSyL v4.0.0 Feature Tests
 * 
 * Tests all new/fixed features:
 * 1. {verbatim} block — truly inert
 * 2. {literal} inside {foreach} — no longer leaks ___LITERAL___
 * 3. {if}/{foreach} inside <script> — full control structures in JS
 * 4. |json filter — raw output, no HTML-escaping
 * 5. |default filter — handles null from nested paths
 */

require_once __DIR__ . '/../kernel/DiSyL/TemplateEngine.php';

use Ikabud\Kernel\DiSyL\TemplateEngine;

$engine = new TemplateEngine(__DIR__ . '/../templates', '/tmp/disyl_test_cache', false);

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
    } else {
        echo "  ✗ {$desc}\n";
        echo "    Expected: " . json_encode($expected) . "\n";
        echo "    Actual:   " . json_encode($actual) . "\n";
        $fail++;
    }
}

echo "╔══════════════════════════════════════════════════════╗\n";
echo "║   DiSyL v4.0.0 — ENGINE FEATURE TESTS                 ║\n";
echo "╚══════════════════════════════════════════════════════╝\n\n";

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
echo "── 1. {verbatim} block ───────────────────────────────\n";
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

check(
    'verbatim protects everything from processing',
    '{if true}not processed{/if} {name}',
    $engine->renderString('{verbatim}{if true}not processed{/if} {name}{/verbatim}', ['name' => 'Alice'])
);

check(
    'verbatim inside foreach stays inert',
    'A:{foreach items as x}{x}{/foreach} B:{foreach items as x}{x}{/foreach}',
    $engine->renderString(
        '{foreach items as item}{item.label}:{verbatim}{foreach items as x}{x}{/foreach}{/verbatim} {/foreach}',
        ['items' => [['label' => 'A'], ['label' => 'B']]]
    )
);

check(
    'content outside verbatim still compiles',
    'Hello World {raw content here}',
    $engine->renderString('Hello {name} {verbatim}{raw content here}{/verbatim}', ['name' => 'World'])
);

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
echo "\n── 2. {literal} inside loops ────────────────────────────\n";
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

check(
    'literal inside foreach produces correct JS braces',
    '{ label: "Red" },{ label: "Blue" },',
    $engine->renderString(
        '{foreach colors as c}{literal}{ {/literal}label: "{c.name}" {literal}}{/literal},{/foreach}',
        ['colors' => [['name' => 'Red'], ['name' => 'Blue']]]
    )
);

check(
    'no ___LITERAL___ leaks in output',
    '0',
    (string) substr_count(
        $engine->renderString(
            '{foreach items as i}{literal}{{/literal}x{literal}}{/literal}{/foreach}',
            ['items' => [1, 2]]
        ),
        '___LITERAL_'
    )
);

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
echo "\n── 3. Control structures inside <script> ────────────────\n";
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

// Test {if} in script
$result = $engine->renderString(
    '<script>var x = {if show}"visible"{else}"hidden"{/if};</script>',
    ['show' => true]
);
check(
    '{if} works in <script> (true branch)',
    '<script>var x = "visible";</script>',
    $result
);

$result = $engine->renderString(
    '<script>var x = {if show}"visible"{else}"hidden"{/if};</script>',
    ['show' => false]
);
check(
    '{if} works in <script> (false branch)',
    '<script>var x = "hidden";</script>',
    $result
);

// Test {foreach} in script with JS object literals
$result = $engine->renderString(
    '<script>var items = [{foreach list as item}{ name: "{item.name}", id: {item.id} },{/foreach}];</script>',
    ['list' => [['name' => 'Foo', 'id' => 1], ['name' => 'Bar', 'id' => 2]]]
);
check(
    '{foreach} with JS objects in <script>',
    '<script>var items = [{ name: "Foo", id: 1 },{ name: "Bar", id: 2 },];</script>',
    $result
);

// Test variables still resolve in script
$result = $engine->renderString(
    '<script>var base = "{base_url}"; var count = {total};</script>',
    ['base_url' => '/app', 'total' => 42]
);
check(
    'Variables resolve in <script>',
    '<script>var base = "/app"; var count = 42;</script>',
    $result
);

// Test JS arrow functions and template literals are preserved
$result = $engine->renderString(
    '<script>var fn = function(x) { return x * 2; }; var obj = { a: 1, b: {count} };</script>',
    ['count' => 5]
);
check(
    'JS functions and objects preserved in <script>',
    '<script>var fn = function(x) { return x * 2; }; var obj = { a: 1, b: 5 };</script>',
    $result
);

// Test nested if inside foreach in script
$result = $engine->renderString(
    '<script>var data = [{foreach items as item}{ label: "{item.name}"{if item.active}, active: true{/if} },{/foreach}];</script>',
    ['items' => [
        ['name' => 'A', 'active' => true],
        ['name' => 'B', 'active' => false],
        ['name' => 'C', 'active' => true],
    ]]
);
check(
    'Nested {if} inside {foreach} inside <script>',
    '<script>var data = [{ label: "A", active: true },{ label: "B" },{ label: "C", active: true },];</script>',
    $result
);

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
echo "\n── 4. |json filter ─────────────────────────────────────\n";
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

// In script context: json should not HTML-escape
$result = $engine->renderString(
    '<script>var config = {settings | json};</script>',
    ['settings' => ['color' => '#2563eb', 'name' => 'Test "App"']]
);
check(
    '|json in <script> outputs raw JSON (no &quot;)',
    '<script>var config = {"color":"#2563eb","name":"Test \"App\""};</script>',
    $result
);

// json filter in HTML context should also not double-escape (it's in hasEscapeFilter)
$result = $engine->renderString(
    '<div data-config=\'{data | json}\'></div>',
    ['data' => ['key' => 'value']]
);
check(
    '|json in HTML attribute outputs clean JSON',
    '<div data-config=\'{"key":"value"}\'></div>',
    $result
);

// Array to JSON
$result = $engine->renderString(
    '<script>var ids = {ids | json};</script>',
    ['ids' => [1, 2, 3]]
);
check(
    '|json with arrays',
    '<script>var ids = [1,2,3];</script>',
    $result
);

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
echo "\n── 5. |default filter with nested paths ─────────────────\n";
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

check(
    'default works when nested path is null',
    'Fallback',
    $engine->renderString("{gui.missing_key | default:'Fallback'}", ['gui' => []])
);

check(
    'default not used when value exists',
    'Real Value',
    $engine->renderString("{gui.app_name | default:'Fallback'}", ['gui' => ['app_name' => 'Real Value']])
);

check(
    'default works when parent key missing entirely',
    'Default App',
    $engine->renderString("{nonexistent.key | default:'Default App'}", [])
);

check(
    'default with empty string value uses fallback',
    'Fallback',
    $engine->renderString("{val | default:'Fallback'}", ['val' => ''])
);

check(
    'default with zero does NOT use fallback (0 is valid)',
    '0',
    $engine->renderString("{val | default:'Fallback'}", ['val' => 0])
);

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
echo "\n── 6. Backward compatibility ────────────────────────────\n";
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

// Make sure existing features still work
check(
    'Simple variable output',
    'Hello World',
    $engine->renderString('Hello {name}', ['name' => 'World'])
);

check(
    'Nested dot path',
    'admin',
    $engine->renderString('{user.role}', ['user' => ['role' => 'admin']])
);

check(
    '{if}/{else} still works',
    'yes',
    $engine->renderString('{if active}yes{else}no{/if}', ['active' => true])
);

check(
    '{foreach} still works',
    '1,2,3,',
    $engine->renderString('{foreach items as i}{i},{/foreach}', ['items' => [1, 2, 3]])
);

check(
    'loop.index1 in foreach',
    '1.2.3.',
    $engine->renderString('{foreach items as i}{loop.index1}.{/foreach}', ['items' => ['a', 'b', 'c']])
);

check(
    'Ternary expression',
    'Yes',
    $engine->renderString("{active ? 'Yes' : 'No'}", ['active' => true])
);

check(
    'Arithmetic expression',
    '11',
    $engine->renderString('{page + 1}', ['page' => 10])
);

check(
    '{set} assignment',
    '42',
    $engine->renderString('{set x = 42}{x}', [])
);

check(
    '|upper filter',
    'HELLO',
    $engine->renderString('{name | upper}', ['name' => 'hello'])
);

check(
    '|count filter',
    '3',
    $engine->renderString('{items | count}', ['items' => [1, 2, 3]])
);

check(
    'Auto-escape HTML in variables',
    '&lt;b&gt;bold&lt;/b&gt;',
    $engine->renderString('{text}', ['text' => '<b>bold</b>'])
);

check(
    '|raw bypasses auto-escape',
    '<b>bold</b>',
    $engine->renderString('{text | raw}', ['text' => '<b>bold</b>'])
);

check(
    'Existing <script> variable resolution',
    '<script>var x = "hello";</script>',
    $engine->renderString('<script>var x = "{name}";</script>', ['name' => 'hello'])
);

echo "\n╔══════════════════════════════════════════════════════╗\n";
printf("║  RESULTS:  %d PASSED  |  %d FAILED                     ║\n", $pass, $fail);
echo "╚══════════════════════════════════════════════════════╝\n";

exit($fail > 0 ? 1 : 0);
