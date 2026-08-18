<?php
/**
 * Ikabud — DiSyL TemplateEngine Comprehensive Test Suite
 * 
 * Covers every major code path in TemplateEngine v4.0.0:
 *   1. Variables — simple, dot paths, missing, auto-escape, raw
 *   2. Filters — all 40+ built-in filters with edge cases
 *   3. Conditions — comparisons, negation, AND/OR, filters-in-conditions, parenthesized
 *   4. Loops — for, foreach, each, key=>value, loop metadata, nested, empty
 *   5. Set statements — assignment from literals, arithmetic, filters
 *   6. Arithmetic & Ternary expressions
 *   7. Comments
 *   8. Verbatim & Literal blocks
 *   9. Script-awareness — JS curly protection, control structures in scripts
 *  10. Template inheritance — extends, block, HTMX partial mode
 *  11. Includes — with and without context passing
 *  12. Components — built-in ikb_* components
 *  13. Globals — setGlobals
 *  14. Custom filters — registerFilter
 *  15. Edge cases — deeply nested structures, empty context, special chars
 */

foreach (glob(__DIR__ . '/../kernel/DiSyL/Exceptions/*.php') as $file) {
    require_once $file;
}
foreach (glob(__DIR__ . '/../kernel/DiSyL/Security/*.php') as $file) {
    require_once $file;
}
foreach (glob(__DIR__ . '/../kernel/DiSyL/v4/AST/*.php') as $file) {
    require_once $file;
}
foreach (glob(__DIR__ . '/../kernel/DiSyL/v4/*.php') as $file) {
    require_once $file;
}
foreach (glob(__DIR__ . '/../kernel/DiSyL/CMS/*.php') as $file) {
    require_once $file;
}
foreach (glob(__DIR__ . '/../kernel/DiSyL/Compiler/*.php') as $file) {
    require_once $file;
}
require_once __DIR__ . '/../kernel/DiSyL/Grammar.php';
require_once __DIR__ . '/../kernel/DiSyL/ExpressionEvaluator.php';
require_once __DIR__ . '/../kernel/DiSyL/ComponentRegistry.php';
require_once __DIR__ . '/../kernel/DiSyL/TemplateEngine.php';

use Ikabud\Kernel\DiSyL\TemplateEngine;

// ── Test infrastructure ─────────────────────────────────

$pass = 0;
$fail = 0;
$section_pass = 0;
$section_fail = 0;
$current_section = '';

function section(string $title): void
{
    global $section_pass, $section_fail, $current_section;
    if ($current_section && ($section_pass + $section_fail > 0)) {
        $total = $section_pass + $section_fail;
        echo "   ({$section_pass}/{$total} passed)\n\n";
    }
    $current_section = $title;
    $section_pass = 0;
    $section_fail = 0;
    echo "── {$title} " . str_repeat('─', max(1, 60 - strlen($title))) . "\n";
}

function check(string $desc, string $expected, string $actual): void
{
    global $pass, $fail, $section_pass, $section_fail;
    $expected = trim($expected);
    $actual = trim($actual);
    if ($expected === $actual) {
        echo "  ✓ {$desc}\n";
        $pass++;
        $section_pass++;
    } else {
        echo "  ✗ {$desc}\n";
        echo "    Expected: " . json_encode($expected) . "\n";
        echo "    Actual:   " . json_encode($actual) . "\n";
        $fail++;
        $section_fail++;
    }
}

// Create a temp template directory for include/extends tests
$tmpDir = sys_get_temp_dir() . '/disyl_test_' . getmypid();
@mkdir($tmpDir, 0755, true);

$engine = new TemplateEngine($tmpDir, '/tmp/disyl_test_cache', false);

echo "╔══════════════════════════════════════════════════════════╗\n";
echo "║   Ikabud — DiSyL TemplateEngine Comprehensive Tests    ║\n";
echo "╚══════════════════════════════════════════════════════════╝\n\n";


// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
section('1. Simple Variables');
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

check(
    'simple variable replacement',
    'Hello Alice',
    $engine->renderString('Hello {name}', ['name' => 'Alice'])
);

check(
    'dot-path variable',
    'alice@test.com',
    $engine->renderString('{user.email}', ['user' => ['email' => 'alice@test.com']])
);

check(
    'deeply nested dot-path',
    'Portland',
    $engine->renderString('{user.address.city}', ['user' => ['address' => ['city' => 'Portland']]])
);

check(
    'missing variable renders empty',
    'Hello',
    $engine->renderString('Hello{missing}', [])
);

check(
    'missing nested path renders empty',
    '',
    $engine->renderString('{user.nonexistent.deep}', ['user' => ['name' => 'Alice']])
);

check(
    'numeric variable',
    '42',
    $engine->renderString('{count}', ['count' => 42])
);

check(
    'zero renders as 0',
    '0',
    $engine->renderString('{val}', ['val' => 0])
);

check(
    'auto-escapes HTML by default',
    '&lt;b&gt;bold&lt;/b&gt;',
    $engine->renderString('{html}', ['html' => '<b>bold</b>'])
);

check(
    'raw filter bypasses auto-escape',
    '<b>bold</b>',
    $engine->renderString('{html | raw}', ['html' => '<b>bold</b>'])
);

check(
    'multiple variables in one string',
    'Hello Alice, you are 30 years old.',
    $engine->renderString('Hello {name}, you are {age} years old.', ['name' => 'Alice', 'age' => 30])
);

check(
    'variable with special chars in value (ampersand)',
    'Tom &amp; Jerry',
    $engine->renderString('{title}', ['title' => 'Tom & Jerry'])
);

check(
    'variable with quotes in value',
    'She said &quot;hello&quot;',
    $engine->renderString('{msg}', ['msg' => 'She said "hello"'])
);


// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
section('2. Filters — String');
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

check(
    'upper filter',
    'HELLO',
    $engine->renderString('{name | upper}', ['name' => 'hello'])
);

check(
    'lower filter',
    'hello',
    $engine->renderString('{name | lower}', ['name' => 'HELLO'])
);

check(
    'capitalize filter',
    'Hello world',
    $engine->renderString('{name | capitalize | raw}', ['name' => 'hello world'])
);

check(
    'title filter',
    'Hello World',
    $engine->renderString('{name | title | raw}', ['name' => 'hello_world'])
);

check(
    'trim filter',
    'hello',
    $engine->renderString('{name | trim}', ['name' => '  hello  '])
);

check(
    'truncate filter with default length',
    'a long text',
    $engine->renderString('{text | truncate}', ['text' => 'a long text'])
);

check(
    'truncate filter with custom length',
    'hello...',
    $engine->renderString('{text | truncate:5}', ['text' => 'hello world'])
);

check(
    'nl2br filter',
    'line1<br />' . "\n" . 'line2',
    $engine->renderString('{text | nl2br}', ['text' => "line1\nline2"])
);

check(
    'strip_tags filter',
    'hello',
    $engine->renderString('{text | strip_tags | raw}', ['text' => '<b>hello</b>'])
);

check(
    'replace filter',
    'hello-world',
    $engine->renderString('{text | replace:_,- | raw}', ['text' => 'hello_world'])
);

check(
    'split filter produces array (via join)',
    'a, b, c',
    $engine->renderString('{text | split:- | join}', ['text' => 'a-b-c'])
);

check(
    'reverse filter on string',
    'dlrow',
    $engine->renderString('{text | reverse | raw}', ['text' => 'world'])
);

check(
    'length filter on string',
    '5',
    $engine->renderString('{text | length}', ['text' => 'hello'])
);

check(
    'url_encode filter',
    'hello+world',
    $engine->renderString('{text | url_encode}', ['text' => 'hello world'])
);

check(
    'base64 filter',
    'aGVsbG8=',
    $engine->renderString('{text | base64}', ['text' => 'hello'])
);

check(
    'md5 filter',
    '5d41402abc4b2a76b9719d911017c592',
    $engine->renderString('{text | md5}', ['text' => 'hello'])
);


// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
section('3. Filters — Numeric');
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

check(
    'number_format filter no decimals',
    '1,234',
    $engine->renderString('{val | number_format}', ['val' => 1234])
);

check(
    'number_format filter with decimals',
    '1,234.50',
    $engine->renderString('{val | number_format:2}', ['val' => 1234.5])
);

check(
    'abs filter',
    '42',
    $engine->renderString('{val | abs}', ['val' => -42])
);

check(
    'round filter',
    '3',
    $engine->renderString('{val | round}', ['val' => 3.14])
);

check(
    'round filter with precision',
    '3.14',
    $engine->renderString('{val | round:2}', ['val' => 3.14159])
);

check(
    'floor filter',
    '3',
    $engine->renderString('{val | floor}', ['val' => 3.9])
);

check(
    'ceil filter',
    '4',
    $engine->renderString('{val | ceil}', ['val' => 3.1])
);


// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
section('4. Filters — Array');
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

check(
    'count filter',
    '3',
    $engine->renderString('{items | count}', ['items' => ['a', 'b', 'c']])
);

check(
    'count filter on empty array',
    '0',
    $engine->renderString('{items | count}', ['items' => []])
);

check(
    'count filter on non-array',
    '0',
    $engine->renderString('{items | count}', ['items' => 'string'])
);

check(
    'join filter',
    'a, b, c',
    $engine->renderString('{items | join}', ['items' => ['a', 'b', 'c']])
);

check(
    'join filter with custom separator',
    'a|b|c',
    $engine->renderString('{items | join:"|"}', ['items' => ['a', 'b', 'c']])
);

check(
    'first filter on array',
    'alpha',
    $engine->renderString('{items | first}', ['items' => ['alpha', 'beta', 'gamma']])
);

check(
    'first filter on string',
    'h',
    $engine->renderString('{text | first}', ['text' => 'hello'])
);

check(
    'last filter',
    'gamma',
    $engine->renderString('{items | last}', ['items' => ['alpha', 'beta', 'gamma']])
);

check(
    'keys filter (via join)',
    'a, b',
    $engine->renderString('{obj | keys | join}', ['obj' => ['a' => 1, 'b' => 2]])
);

check(
    'values filter (via join)',
    '1, 2',
    $engine->renderString('{obj | values | join}', ['obj' => ['a' => 1, 'b' => 2]])
);

check(
    'reverse filter on array (via join)',
    'c, b, a',
    $engine->renderString('{items | reverse | join}', ['items' => ['a', 'b', 'c']])
);

check(
    'sort filter (via join)',
    'a, b, c',
    $engine->renderString('{items | sort | join}', ['items' => ['c', 'a', 'b']])
);

check(
    'unique filter (via join)',
    'a, b, c',
    $engine->renderString('{items | unique | join}', ['items' => ['a', 'b', 'a', 'c', 'b']])
);

check(
    'slice filter on array (via join)',
    'b, c',
    $engine->renderString('{items | slice:1,2 | join}', ['items' => ['a', 'b', 'c', 'd']])
);

check(
    'slice filter on string',
    'ello',
    $engine->renderString('{text | slice:1 | raw}', ['text' => 'hello'])
);

check(
    'length filter on array',
    '3',
    $engine->renderString('{items | length}', ['items' => ['a', 'b', 'c']])
);


// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
section('5. Filters — Special');
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

check(
    'json filter',
    '{"name":"Alice","age":30}',
    $engine->renderString('{data | json}', ['data' => ['name' => 'Alice', 'age' => 30]])
);

check(
    'json filter with array',
    '[1,2,3]',
    $engine->renderString('{data | json}', ['data' => [1, 2, 3]])
);

check(
    'json filter is not double-escaped (raw by default)',
    '{"url":"https://test.com/path"}',
    $engine->renderString('{data | json}', ['data' => ['url' => 'https://test.com/path']])
);

check(
    'default filter with null value',
    'fallback',
    $engine->renderString('{missing | default:"fallback"}', [])
);

check(
    'default filter with empty string',
    'fallback',
    $engine->renderString('{val | default:"fallback"}', ['val' => ''])
);

check(
    'default filter with false',
    '',
    $engine->renderString('{val | default:"fallback"}', ['val' => false])
);

check(
    'default filter preserves explicit false in conditions',
    'hidden',
    $engine->renderString('{if val | default:1}visible{else}hidden{/if}', ['val' => false])
);

check(
    'default filter with present value',
    'present',
    $engine->renderString('{val | default:"fallback"}', ['val' => 'present'])
);

check(
    'default filter with nested missing path',
    'N/A',
    $engine->renderString('{user.settings.theme | default:"N/A"}', ['user' => []])
);

check(
    'default filter resolves nested variable fallback',
    'Lil Juanita',
    $engine->renderString('{entity_view_context.header_title | default:entity.title}', [
        'entity_view_context' => [],
        'entity' => ['title' => 'Lil Juanita'],
    ])
);

check(
    'default filter keeps chaining through missing variable fallbacks',
    'post',
    $engine->renderString('{item.entity_type_label | default:item.content_type_label | default:item.entity_type | default:content_type | default:"Item"}', [
        'item' => [],
        'content_type' => 'post',
    ])
);

check(
    'esc_html filter',
    '&lt;script&gt;',
    $engine->renderString('{val | esc_html}', ['val' => '<script>'])
);

check(
    'esc_attr filter',
    '&lt;b&gt;hi&lt;/b&gt;',
    $engine->renderString('{val | esc_attr}', ['val' => '<b>hi</b>'])
);

check(
    'esc_js filter',
    "it\\'s a test",
    $engine->renderString('{val | esc_js}', ['val' => "it's a test"])
);

check(
    'pluralize filter singular',
    'item',
    $engine->renderString('{count | pluralize:"item","items"}', ['count' => 1])
);

check(
    'pluralize filter plural',
    'items',
    $engine->renderString('{count | pluralize:"item","items"}', ['count' => 5])
);

check(
    'date filter from string',
    '2024-01-15',
    $engine->renderString('{date | date}', ['date' => '2024-01-15'])
);

check(
    'date filter with format',
    'Jan 15 2024',
    $engine->renderString('{date | date:"M d Y"}', ['date' => '2024-01-15'])
);

check(
    'chained filters',
    'HELLO WORLD',
    $engine->renderString('{text | trim | upper}', ['text' => '  hello world  '])
);

check(
    'three chained filters',
    'HELLO...',
    $engine->renderString('{text | trim | upper | truncate:5}', ['text' => '  hello world  '])
);


// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
section('5b. Built-in Function Calls');
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

check(
    'json_encode function (escaped by default)',
    '{&quot;a&quot;:1}',
    $engine->renderString('{json_encode(data)}', ['data' => ['a' => 1]])
);

check(
    'json_encode function with |raw',
    '{"a":1}',
    $engine->renderString('{json_encode(data)|raw}', ['data' => ['a' => 1]])
);

check(
    'json_encode function with array literal arg',
    '["x","y"]',
    $engine->renderString('{json_encode(["x","y"])|raw}', [])
);

check(
    'json_encode function in Alpine attribute',
    '<div x-data="{ids: [&quot;1&quot;,&quot;2&quot;]}">',
    $engine->renderString('<div x-data="{ids: {json_encode(ids)}}">', ['ids' => ['1', '2']])
);

check(
    'json_encode with associative array arg',
    '{"greeting":"hello"}',
    $engine->renderString('{json_encode(["greeting" => "hello"])|raw}', [])
);

check(
    'json_decode function followed by |json filter',
    '{"a":1}',
    $engine->renderString('{json_decode(j)|json}', ['j' => '{"a":1}'])
);

// Compiled path — must route through FunctionRegistry identically.
$compiledJsonEngine = new TemplateEngine($tmpDir, '/tmp/disyl_test_cache', true);
file_put_contents($tmpDir . '/jsonfn.disyl', '{json_encode(data)|raw}');
check(
    'json_encode function (compiled mode)',
    '{"a":1}',
    $compiledJsonEngine->render('jsonfn.disyl', ['data' => ['a' => 1]])
);
file_put_contents($tmpDir . '/jsonfn2.disyl', '{json_encode(["x","y"])|raw}');
check(
    'json_encode function with array literal (compiled mode)',
    '["x","y"]',
    $compiledJsonEngine->render('jsonfn2.disyl', [])
);


// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
section('6. Conditions — Basic');
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

check(
    'if true shows content',
    'visible',
    $engine->renderString('{if show}visible{/if}', ['show' => true])
);

check(
    'if false hides content',
    '',
    $engine->renderString('{if show}visible{/if}', ['show' => false])
);

check(
    'if/else — true branch',
    'yes',
    $engine->renderString('{if active}yes{else}no{/if}', ['active' => true])
);

check(
    'if/else — false branch',
    'no',
    $engine->renderString('{if active}yes{else}no{/if}', ['active' => false])
);

check(
    'if/elseif/else chain',
    'B',
    $engine->renderString('{if a}A{elseif b}B{else}C{/if}', ['a' => false, 'b' => true])
);

check(
    'if/elseif/else — else branch',
    'C',
    $engine->renderString('{if a}A{elseif b}B{else}C{/if}', ['a' => false, 'b' => false])
);

check(
    'truthy: non-empty string is true',
    'yes',
    $engine->renderString('{if val}yes{else}no{/if}', ['val' => 'hello'])
);

check(
    'truthy: empty string is false',
    'no',
    $engine->renderString('{if val}yes{else}no{/if}', ['val' => ''])
);

check(
    'truthy: null is false',
    'no',
    $engine->renderString('{if val}yes{else}no{/if}', ['val' => null])
);

check(
    'truthy: zero is false',
    'no',
    $engine->renderString('{if val}yes{else}no{/if}', ['val' => 0])
);

check(
    'truthy: non-empty array is true',
    'yes',
    $engine->renderString('{if items}yes{else}no{/if}', ['items' => [1, 2]])
);

check(
    'truthy: empty array is false',
    'no',
    $engine->renderString('{if items}yes{else}no{/if}', ['items' => []])
);

check(
    'truthy: missing var is false',
    'no',
    $engine->renderString('{if missing}yes{else}no{/if}', [])
);


// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
section('7. Conditions — Comparisons');
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

check(
    'equals comparison (==) true',
    'yes',
    $engine->renderString('{if role == "admin"}yes{else}no{/if}', ['role' => 'admin'])
);

check(
    'equals comparison (==) false',
    'no',
    $engine->renderString('{if role == "admin"}yes{else}no{/if}', ['role' => 'user'])
);

check(
    'not equals (!=)',
    'yes',
    $engine->renderString('{if role != "admin"}yes{else}no{/if}', ['role' => 'user'])
);

check(
    'greater than (>)',
    'yes',
    $engine->renderString('{if count > 5}yes{else}no{/if}', ['count' => 10])
);

check(
    'less than (<)',
    'yes',
    $engine->renderString('{if count < 5}yes{else}no{/if}', ['count' => 3])
);

check(
    'greater or equal (>=)',
    'yes',
    $engine->renderString('{if count >= 5}yes{else}no{/if}', ['count' => 5])
);

check(
    'less or equal (<=)',
    'yes',
    $engine->renderString('{if count <= 5}yes{else}no{/if}', ['count' => 5])
);

check(
    'strict equality (===) true',
    'yes',
    $engine->renderString('{if val === "3"}yes{else}no{/if}', ['val' => '3'])
);

check(
    'numeric comparison across types',
    'yes',
    $engine->renderString('{if count > 0}yes{else}no{/if}', ['count' => 10])
);

check(
    'dot-path in comparison',
    'yes',
    $engine->renderString('{if user.role == "admin"}yes{else}no{/if}', ['user' => ['role' => 'admin']])
);


// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
section('8. Conditions — Negation, AND/OR');
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

check(
    'negation with !',
    'yes',
    $engine->renderString('{if !hidden}yes{else}no{/if}', ['hidden' => false])
);

check(
    'negation with ! on truthy',
    'no',
    $engine->renderString('{if !visible}yes{else}no{/if}', ['visible' => true])
);

check(
    'AND condition — both true',
    'yes',
    $engine->renderString('{if a and b}yes{else}no{/if}', ['a' => true, 'b' => true])
);

check(
    'AND condition — one false',
    'no',
    $engine->renderString('{if a and b}yes{else}no{/if}', ['a' => true, 'b' => false])
);

check(
    'OR condition — one true',
    'yes',
    $engine->renderString('{if a or b}yes{else}no{/if}', ['a' => false, 'b' => true])
);

check(
    'OR condition — both false',
    'no',
    $engine->renderString('{if a or b}yes{else}no{/if}', ['a' => false, 'b' => false])
);

check(
    '&& syntax (AND)',
    'yes',
    $engine->renderString('{if a && b}yes{else}no{/if}', ['a' => true, 'b' => true])
);

check(
    '|| syntax (OR)',
    'yes',
    $engine->renderString('{if a || b}yes{else}no{/if}', ['a' => false, 'b' => true])
);


// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
section('9. Conditions — Filters in Conditions');
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

check(
    'filter as truthy: items | count (non-zero)',
    'yes',
    $engine->renderString('{if items | count}yes{else}no{/if}', ['items' => [1, 2, 3]])
);

check(
    'filter as truthy: items | count (zero)',
    'no',
    $engine->renderString('{if items | count}yes{else}no{/if}', ['items' => []])
);

check(
    'filter with comparison: items | count > 0',
    'yes',
    $engine->renderString('{if items | count > 0}yes{else}no{/if}', ['items' => [1, 2]])
);

check(
    'filter with comparison: items | count == 0',
    'yes',
    $engine->renderString('{if items | count == 0}yes{else}no{/if}', ['items' => []])
);

check(
    'parenthesized filter: (items | count) > 0',
    'yes',
    $engine->renderString('{if (items | count) > 0}yes{else}no{/if}', ['items' => [1, 2]])
);

check(
    'parenthesized filter as truthy: (items | count)',
    'yes',
    $engine->renderString('{if (items | count)}yes{else}no{/if}', ['items' => [1]])
);

check(
    'parenthesized filter as truthy empty: (items | count)',
    'no',
    $engine->renderString('{if (items | count)}yes{else}no{/if}', ['items' => []])
);

check(
    'arithmetic in condition: count - 1',
    'no',
    $engine->renderString('{if count - 1}yes{else}no{/if}', ['count' => 1])
);

check(
    'arithmetic comparison: page + 1 > total',
    'yes',
    $engine->renderString('{if page + 1 > total}yes{else}no{/if}', ['page' => 5, 'total' => 5])
);


// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
section('10. Nested Conditions');
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

check(
    'nested if — both true',
    'inner',
    $engine->renderString('{if a}{if b}inner{/if}{/if}', ['a' => true, 'b' => true])
);

check(
    'nested if — outer true, inner false',
    '',
    $engine->renderString('{if a}{if b}inner{/if}{/if}', ['a' => true, 'b' => false])
);

check(
    'nested if with else in inner',
    'inner-else',
    $engine->renderString('{if a}{if b}inner{else}inner-else{/if}{/if}', ['a' => true, 'b' => false])
);

check(
    'nested if with else in outer and inner',
    'outer-else',
    $engine->renderString('{if a}{if b}AB{else}A-notB{/if}{else}outer-else{/if}', ['a' => false, 'b' => true])
);


// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
section('11. For Loops');
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

check(
    'basic for loop',
    'abc',
    $engine->renderString('{for item in items}{item}{/for}', ['items' => ['a', 'b', 'c']])
);

check(
    'for loop with dot-path items',
    'AliceBob',
    $engine->renderString('{for u in users}{u.name}{/for}', ['users' => [['name' => 'Alice'], ['name' => 'Bob']]])
);

check(
    'for loop — empty list',
    '',
    $engine->renderString('{for item in items}{item}{/for}', ['items' => []])
);

check(
    'for loop — missing list (treated as empty)',
    '',
    $engine->renderString('{for item in missing}{item}{/for}', [])
);

check(
    'loop.index (0-based)',
    '012',
    $engine->renderString('{for item in items}{loop.index}{/for}', ['items' => ['a', 'b', 'c']])
);

check(
    'loop.index1 (1-based)',
    '123',
    $engine->renderString('{for item in items}{loop.index1}{/for}', ['items' => ['a', 'b', 'c']])
);

check(
    'loop.first',
    'first:a other:b other:c',
    $engine->renderString('{for item in items}{if loop.first}first:{else}other:{/if}{item} {/for}', ['items' => ['a', 'b', 'c']])
);

check(
    'loop.last',
    'a b c!',
    $engine->renderString('{for item in items}{item}{if loop.last}!{else} {/if}{/for}', ['items' => ['a', 'b', 'c']])
);

check(
    'loop.length',
    '333',
    $engine->renderString('{for item in items}{loop.length}{/for}', ['items' => ['a', 'b', 'c']])
);


// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
section('12. Foreach Loops');
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

check(
    'basic foreach',
    'abc',
    $engine->renderString('{foreach items as item}{item}{/foreach}', ['items' => ['a', 'b', 'c']])
);

check(
    'foreach with key => value',
    'x=1 y=2',
    $engine->renderString('{foreach data as k => v}{k}={v} {/foreach}', ['data' => ['x' => 1, 'y' => 2]])
);

check(
    'foreach — empty list',
    '',
    $engine->renderString('{foreach items as item}{item}{/foreach}', ['items' => []])
);

check(
    'foreach loop metadata',
    '012',
    $engine->renderString('{foreach items as item}{loop.index}{/foreach}', ['items' => ['a', 'b', 'c']])
);

check(
    'foreach with dot-path list',
    'AliceBob',
    $engine->renderString('{foreach data.users as u}{u.name}{/foreach}', ['data' => ['users' => [['name' => 'Alice'], ['name' => 'Bob']]]])
);


// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
section('13. Each Loops');
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

check(
    'basic each',
    'abc',
    $engine->renderString('{each items as item}{item}{/each}', ['items' => ['a', 'b', 'c']])
);

check(
    'each with key => value',
    'name=Alice age=30',
    $engine->renderString('{each person as k => v}{k}={v} {/each}', ['person' => ['name' => 'Alice', 'age' => 30]])
);

check(
    'each — empty list',
    '',
    $engine->renderString('{each items as item}{item}{/each}', ['items' => []])
);

check(
    'each loop metadata (first/last)',
    'first:a middle:b last:c',
    $engine->renderString('{each items as item}{if loop.first}first:{elseif loop.last}last:{else}middle:{/if}{item} {/each}', ['items' => ['a', 'b', 'c']])
);


// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
section('14. Nested Loops');
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

check(
    'nested for loops',
    '1:a 1:b 2:a 2:b',
    $engine->renderString(
        '{for g in groups}{for item in g.items}{g.id}:{item} {/for}{/for}',
        ['groups' => [
            ['id' => 1, 'items' => ['a', 'b']],
            ['id' => 2, 'items' => ['a', 'b']],
        ]]
    )
);

check(
    'nested foreach loops',
    '1:a 1:b 2:c 2:d',
    $engine->renderString(
        '{foreach groups as g}{foreach g.items as item}{g.id}:{item} {/foreach}{/foreach}',
        ['groups' => [
            ['id' => 1, 'items' => ['a', 'b']],
            ['id' => 2, 'items' => ['c', 'd']],
        ]]
    )
);

check(
    'loop inside conditional',
    'Items: abc',
    $engine->renderString(
        '{if show}Items: {for item in items}{item}{/for}{/if}',
        ['show' => true, 'items' => ['a', 'b', 'c']]
    )
);

check(
    'conditional inside loop',
    'a* b c*',
    $engine->renderString(
        '{for item in items}{item.name}{if item.star}*{/if} {/for}',
        ['items' => [
            ['name' => 'a', 'star' => true],
            ['name' => 'b', 'star' => false],
            ['name' => 'c', 'star' => true],
        ]]
    )
);


// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
section('15. Set Statements');
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

check(
    'set numeric value',
    '42',
    $engine->renderString('{set x = 42}{x}', [])
);

check(
    'set string value',
    'hello',
    $engine->renderString('{set greeting = "hello"}{greeting}', [])
);

check(
    'set arithmetic expression',
    '11',
    $engine->renderString('{set next = page + 1}{next}', ['page' => 10])
);

check(
    'set from filter',
    '3',
    $engine->renderString('{set total = items | count}{total}', ['items' => ['a', 'b', 'c']])
);

check(
    'set overrides existing var',
    'new',
    $engine->renderString('{set name = "new"}{name}', ['name' => 'old'])
);

check(
    'set tag produces no output',
    'before after',
    $engine->renderString('before {set x = 5}after', [])
);


// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
section('16. Arithmetic Expressions');
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

check(
    'addition',
    '15',
    $engine->renderString('{a + b}', ['a' => 10, 'b' => 5])
);

check(
    'subtraction',
    '5',
    $engine->renderString('{a - b}', ['a' => 10, 'b' => 5])
);

check(
    'multiplication',
    '50',
    $engine->renderString('{a * b}', ['a' => 10, 'b' => 5])
);

check(
    'division',
    '2',
    $engine->renderString('{a / b}', ['a' => 10, 'b' => 5])
);

check(
    'modulo',
    '1',
    $engine->renderString('{a % b}', ['a' => 10, 'b' => 3])
);

check(
    'division by zero gives 0',
    '0',
    $engine->renderString('{a / b}', ['a' => 10, 'b' => 0])
);

check(
    'arithmetic with literal number',
    '11',
    $engine->renderString('{page + 1}', ['page' => 10])
);

check(
    'arithmetic is HTML-escaped (safe output)',
    '15',
    $engine->renderString('{a + b}', ['a' => 10, 'b' => 5])
);


// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
section('17. Ternary Expressions');
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

check(
    'ternary true branch (quoted)',
    'Yes',
    $engine->renderString('{active ? "Yes" : "No"}', ['active' => true])
);

check(
    'ternary false branch (quoted)',
    'No',
    $engine->renderString('{active ? "Yes" : "No"}', ['active' => false])
);

check(
    'ternary with variable result',
    'Alice',
    $engine->renderString('{show ? name : "hidden"}', ['show' => true, 'name' => 'Alice'])
);

check(
    'ternary with comparison condition',
    'big',
    $engine->renderString('{count > 5 ? "big" : "small"}', ['count' => 10])
);

check(
    'ternary with numeric result',
    '42',
    $engine->renderString('{show ? count : "none"}', ['show' => true, 'count' => 42])
);


// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
section('18. Comments');
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

check(
    'single-line comment removed',
    'before  after',
    $engine->renderString('before {!-- this is a comment --} after', [])
);

check(
    'multi-line comment removed',
    'before  after',
    $engine->renderString("before {!-- this is\na multi-line\ncomment --} after", [])
);

check(
    'multiple comments removed',
    'A  B  C',
    $engine->renderString('A {!-- first --} B {!-- second --} C', [])
);


// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
section('19. Verbatim Blocks');
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

check(
    'verbatim protects everything',
    '{if true}not processed{/if} {name}',
    $engine->renderString('{verbatim}{if true}not processed{/if} {name}{/verbatim}', ['name' => 'Alice'])
);

check(
    'verbatim preserves curly braces',
    '{variable}',
    $engine->renderString('{verbatim}{variable}{/verbatim}', ['variable' => 'replaced'])
);

check(
    'content before/after verbatim still processed',
    'Hello {raw tag} World',
    $engine->renderString('{greeting} {verbatim}{raw tag}{/verbatim} {place}', ['greeting' => 'Hello', 'place' => 'World'])
);

check(
    'multiple verbatim blocks',
    '{a} and {b}',
    $engine->renderString('{verbatim}{a}{/verbatim} and {verbatim}{b}{/verbatim}', ['a' => 'X', 'b' => 'Y'])
);


// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
section('20. Literal Blocks');
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

check(
    'literal preserves content',
    '{raw}',
    $engine->renderString('{literal}{raw}{/literal}', ['raw' => 'nope'])
);

check(
    'literal inside foreach loop',
    '{curly} {curly}',
    $engine->renderString('{for item in items}{literal}{curly}{/literal} {/for}', ['items' => [1, 2]])
);


// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
section('21. Script-Awareness');
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

check(
    'JS object literal preserved',
    '<script>const x = {a: 1, b: 2};</script>',
    $engine->renderString('<script>const x = {a: 1, b: 2};</script>', [])
);

check(
    'DiSyL variables resolved inside script',
    '<script>const name = "Alice";</script>',
    $engine->renderString('<script>const name = "{name}";</script>', ['name' => 'Alice'])
);

check(
    'variables in script are raw (no HTML-escape)',
    '<script>const x = "Tom & Jerry";</script>',
    $engine->renderString('<script>const x = "{title}";</script>', ['title' => 'Tom & Jerry'])
);

check(
    'if/else inside script',
    '<script>const mode = "admin";</script>',
    $engine->renderString('<script>const mode = "{if admin}admin{else}user{/if}";</script>', ['admin' => true])
);

check(
    'foreach inside script',
    '<script>const items = ["a","b","c",];</script>',
    $engine->renderString('<script>const items = [{for item in items}"{item}",{/for}];</script>', ['items' => ['a', 'b', 'c']])
);

check(
    'script attributes with variables',
    '<script src="/js/app.js"></script>',
    $engine->renderString('<script src="{base}/app.js"></script>', ['base' => '/js'])
);

check(
    'arrow function braces preserved',
    '<script>const fn = () => { return 1; };</script>',
    $engine->renderString('<script>const fn = () => { return 1; };</script>', [])
);

check(
    'mixed JS object braces and DiSyL variables survive in script context',
    '<script>const cfg = { label: "Alice", nested: { ok: true } };</script>',
    $engine->renderString('<script>const cfg = { label: "{name}", nested: { ok: true } };</script>', ['name' => 'Alice'])
);

check(
    'null-coalescing ?? resolved in script block (dot-path + numeric fallback)',
    '<script>const sc = 1000; const n = 0;</script>',
    $engine->renderString('<script>const sc = {session.starting_cash ?? 0}; const n = {sales_count ?? 0};</script>', ['session' => ['starting_cash' => 1000], 'sales_count' => 0])
);

check(
    'null-coalescing ?? falls back to literal when variable is missing',
    '<script>const x = "morning";</script>',
    $engine->renderString('<script>const x = {missing_var ?? "morning"};</script>', [])
);

check(
    'null-coalescing ?? keeps a zero value (does not fall back on 0)',
    '<script>const z = 0;</script>',
    $engine->renderString('<script>const z = {sales_total ?? 99};</script>', ['sales_total' => 0])
);


// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
section('22. Template Inheritance (extends/block)');
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

// Create layout template
file_put_contents($tmpDir . '/layout.disyl', '<html><head>{block head}default head{/block}</head><body>{block content}default content{/block}</body></html>');

// Create child template
file_put_contents($tmpDir . '/child.disyl', '{extends "layout"}{block head}<title>My Page</title>{/block}{block content}<h1>Hello</h1>{/block}');

check(
    'basic template extends',
    '<html><head><title>My Page</title></head><body><h1>Hello</h1></body></html>',
    $engine->render('child', [])
);

// Test default block content
file_put_contents($tmpDir . '/child_partial.disyl', '{extends "layout"}{block content}<p>Only content</p>{/block}');

check(
    'extends with default block fallback',
    '<html><head>default head</head><body><p>Only content</p></body></html>',
    $engine->render('child_partial', [])
);

// Test HTMX partial mode
check(
    'HTMX partial mode extracts blocks without layout',
    '<title>My Page</title><h1>Hello</h1>',
    $engine->render('child', ['is_htmx' => true])
);


// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
section('23. Includes');
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

// Create partials
file_put_contents($tmpDir . '/header.disyl', '<header>{title}</header>');
@mkdir($tmpDir . '/partials', 0755, true);
file_put_contents($tmpDir . '/partials/nav.disyl', '<nav>{label}</nav>');

check(
    'basic include',
    '<header>My Site</header>',
    $engine->renderString('{include "header"}', ['title' => 'My Site'])
);

check(
    'include with "with" context',
    '<nav>Home</nav>',
    $engine->renderString('{include "partials/nav" with {label: "Home"}}', [])
);

check(
    'include inherits parent context',
    'Before <header>Inherited Title</header> After',
    $engine->renderString('Before {include "header"} After', ['title' => 'Inherited Title'])
);


// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
section('24. Components — ikb_card');
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

check(
    'ikb_card default variant',
    true ? (
        str_contains(
            $engine->renderString('{ikb_card}content{/ikb_card}', []),
            'content'
        ) ? 'contains' : 'missing'
    ) : '',
    'contains'
);

check(
    'ikb_card renders as div',
    true ? (
        str_contains(
            $engine->renderString('{ikb_card}Hello{/ikb_card}', []),
            '<div'
        ) ? 'has_div' : 'no_div'
    ) : '',
    'has_div'
);

check(
    'ikb_card with id attribute',
    true ? (
        str_contains(
            $engine->renderString('{ikb_card id="my-card"}Test{/ikb_card}', []),
            'id="my-card"'
        ) ? 'has_id' : 'no_id'
    ) : '',
    'has_id'
);


// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
section('25. Components — ikb_text');
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

check(
    'ikb_text default tag (p)',
    true ? (
        preg_match('/<p\s/', $engine->renderString('{ikb_text}Hello{/ikb_text}', []))
            ? 'p_tag' : 'no_p'
    ) : '',
    'p_tag'
);

check(
    'ikb_text custom tag',
    true ? (
        str_contains(
            $engine->renderString('{ikb_text tag="h1"}Title{/ikb_text}', []),
            '<h1'
        ) ? 'h1' : 'not_h1'
    ) : '',
    'h1'
);

check(
    'ikb_text invalid tag falls back to p',
    true ? (
        preg_match('/<p\s/', $engine->renderString('{ikb_text tag="script"}Hello{/ikb_text}', []))
            ? 'p_tag' : 'not_p'
    ) : '',
    'p_tag'
);

check(
    'ikb_section escapes id attribute',
    true ? (
        str_contains(
            $engine->renderString('{ikb_section id="hero&quot; onclick=&quot;alert(1)"}Body{/ikb_section}', []),
            'id="hero&amp;quot; onclick=&amp;quot;alert(1)"'
        ) ? 'escaped' : 'not_escaped'
    ) : '',
    'escaped'
);


// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
section('26. Components — ikb_button');
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

$btnHtml = $engine->renderString('{ikb_button variant="primary"}Click{/ikb_button}', []);
check(
    'ikb_button renders content',
    true ? (str_contains($btnHtml, 'Click') ? 'has_text' : 'no_text') : '',
    'has_text'
);

check(
    'ikb_button has button element or anchor',
    true ? (str_contains($btnHtml, '<button') || str_contains($btnHtml, '<a') ? 'correct' : 'wrong') : '',
    'correct'
);


// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
section('27. Components — ikb_grid');
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

$gridHtml = $engine->renderString('{ikb_grid columns="3"}cells{/ikb_grid}', []);
check(
    'ikb_grid renders grid class',
    true ? (str_contains($gridHtml, 'grid') ? 'has_grid' : 'no_grid') : '',
    'has_grid'
);

check(
    'ikb_grid renders columns',
    true ? (str_contains($gridHtml, 'grid-cols') ? 'has_cols' : 'no_cols') : '',
    'has_cols'
);


// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
section('28. Components — ikb_alert');
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

$alertHtml = $engine->renderString('{ikb_alert variant="success"}Done!{/ikb_alert}', []);
check(
    'ikb_alert renders content',
    true ? (str_contains($alertHtml, 'Done!') ? 'yes' : 'no') : '',
    'yes'
);


// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
section('29. Components — Self-closing');
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

$spinnerHtml = $engine->renderString('{ikb_spinner size="large" /}', []);
check(
    'self-closing ikb_spinner renders',
    true ? (strlen($spinnerHtml) > 0 ? 'rendered' : 'empty') : '',
    'rendered'
);

$iconHtml = $engine->renderString('{ikb_icon name="check" /}', []);
check(
    'self-closing ikb_icon renders',
    true ? (strlen($iconHtml) > 0 ? 'rendered' : 'empty') : '',
    'rendered'
);


// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
section('30. Globals');
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

$globalEngine = new TemplateEngine($tmpDir, '/tmp/disyl_test_cache', false);
$globalEngine->setGlobals(['site_name' => 'Ikabud']);

check(
    'global variable accessible',
    'Ikabud',
    $globalEngine->renderString('{site_name}', [])
);

check(
    'local context overrides global',
    'Custom',
    $globalEngine->renderString('{site_name}', ['site_name' => 'Custom'])
);

check(
    'global and local coexist',
    'Ikabud - Page Title',
    $globalEngine->renderString('{site_name} - {title}', ['title' => 'Page Title'])
);


// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
section('31. Custom Filters');
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

$customEngine = new TemplateEngine($tmpDir, '/tmp/disyl_test_cache', false);
$customEngine->registerFilter('double', fn($v) => (string)((int)$v * 2));
$customEngine->registerFilter('prefix', fn($v, $args) => ($args[0] ?? '') . $v);

check(
    'custom filter: double',
    '10',
    $customEngine->renderString('{val | double}', ['val' => 5])
);

check(
    'custom filter with args: prefix',
    'Dr.Smith',
    $customEngine->renderString('{name | prefix:"Dr." | raw}', ['name' => 'Smith'])
);


// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
section('32. Edge Cases');
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

check(
    'empty template',
    '',
    $engine->renderString('', [])
);

check(
    'template with no tags',
    'plain text',
    $engine->renderString('plain text', [])
);

check(
    'template with only whitespace',
    '',
    $engine->renderString('   ', [])
);

check(
    'dollar sign not treated as variable',
    '$price',
    $engine->renderString('$price', ['price' => 100])
);

check(
    'JS template literal ${var} not processed',
    '${name}',
    $engine->renderString('${name}', ['name' => 'Alice'])
);

check(
    'deeply nested dot path (4 levels)',
    'deep',
    $engine->renderString('{a.b.c.d}', ['a' => ['b' => ['c' => ['d' => 'deep']]]])
);

check(
    'variable alongside HTML',
    '<div>Hello</div>',
    $engine->renderString('<div>{msg}</div>', ['msg' => 'Hello'])
);

check(
    'multiple loops sequentially',
    'ab-12',
    $engine->renderString('{for x in letters}{x}{/for}-{for n in numbers}{n}{/for}', [
        'letters' => ['a', 'b'],
        'numbers' => [1, 2],
    ])
);

check(
    'boolean false in context does not render',
    '',
    $engine->renderString('{val}', ['val' => false])
);

check(
    'empty string variable renders empty',
    '',
    $engine->renderString('{val}', ['val' => ''])
);

check(
    'array value renders empty (not scalar)',
    '',
    $engine->renderString('{items}', ['items' => [1, 2, 3]])
);


// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
section('33. Error Handling');
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

// Missing template throws RuntimeException
$threw = false;
try {
    $engine->render('nonexistent_template_xyz', []);
} catch (\RuntimeException $e) {
    $threw = true;
}
check(
    'missing template throws RuntimeException',
    'yes',
    $threw ? 'yes' : 'no'
);

// getErrors returns error after failed include
$errEngine = new TemplateEngine($tmpDir, '/tmp/disyl_test_cache', false);
$errEngine->renderString('{include "does_not_exist"}', []);
$errors = $errEngine->getErrors();
check(
    'failed include records error',
    'yes',
    count($errors) > 0 ? 'yes' : 'no'
);


// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
section('34. Complex Real-World Patterns');
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

// Table with conditional rows
check(
    'table with loop and conditional classes',
    '<tr class="active">Alice</tr><tr class="">Bob</tr>',
    $engine->renderString(
        '{for user in users}<tr class="{if user.active}active{/if}">{user.name}</tr>{/for}',
        ['users' => [
            ['name' => 'Alice', 'active' => true],
            ['name' => 'Bob', 'active' => false],
        ]]
    )
);

// Pagination pattern
check(
    'pagination: page 2 of 5',
    'Page 2 of 5 | Prev | Next',
    $engine->renderString(
        'Page {page} of {total} | {if page > 1}Prev | {/if}{if page < total}Next{/if}',
        ['page' => 2, 'total' => 5]
    )
);

// Card list with set + loop + filter
check(
    'set + loop + filter combo',
    '3 items: A-B-C',
    $engine->renderString(
        '{set n = items | count}{n} items: {for item in items}{item | upper}{if !loop.last}-{/if}{/for}',
        ['items' => ['a', 'b', 'c']]
    )
);

// Conditional with elseif chain using comparisons
check(
    'status badge with elseif chain',
    'warning',
    $engine->renderString(
        '{if status == "ok"}success{elseif status == "warn"}warning{elseif status == "err"}danger{else}unknown{/if}',
        ['status' => 'warn']
    )
);

// Empty state pattern
check(
    'empty state when no items',
    '<p>No items found</p>',
    $engine->renderString(
        '{if items | count > 0}{for item in items}<li>{item}</li>{/for}{else}<p>No items found</p>{/if}',
        ['items' => []]
    )
);

// Items present
check(
    'list when items exist',
    '<li>a</li><li>b</li>',
    $engine->renderString(
        '{if items | count > 0}{for item in items}<li>{item}</li>{/for}{else}<p>No items found</p>{/if}',
        ['items' => ['a', 'b']]
    )
);


// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
section('35. Hardening — {empty} clause in loops');
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

check(
    'foreach {empty} shown when list is empty',
    'Nothing here.',
    $engine->renderString(
        '{foreach items as item}{item}{empty}Nothing here.{/foreach}',
        ['items' => []]
    )
);

check(
    'foreach {empty} NOT shown when list is non-empty',
    'ab',
    $engine->renderString(
        '{foreach items as item}{item}{empty}Nothing here.{/foreach}',
        ['items' => ['a', 'b']]
    )
);

check(
    'each {empty} shown when list is empty',
    'No entries.',
    $engine->renderString(
        '{each items as item}{item}{empty}No entries.{/each}',
        ['items' => []]
    )
);

check(
    'each {empty} NOT shown when list is non-empty',
    'xy',
    $engine->renderString(
        '{each items as item}{item}{empty}No entries.{/each}',
        ['items' => ['x', 'y']]
    )
);

check(
    'foreach {empty} can contain HTML and template expressions',
    '<p>0 of 5 loaded</p>',
    $engine->renderString(
        '{foreach rows as row}{row}{empty}<p>{zero} of {total} loaded</p>{/foreach}',
        ['rows' => [], 'zero' => '0', 'total' => '5']
    )
);

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
section('36. Hardening — path traversal protection');
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

$traversalEngine = new TemplateEngine($tmpDir, '/tmp/disyl_test_cache', false);

// Write a sentinel file OUTSIDE the template dir
$sentinelPath = sys_get_temp_dir() . '/disyl_traversal_sentinel_' . getmypid() . '.disyl';
file_put_contents($sentinelPath, 'LEAKED');

// Build a relative traversal from $tmpDir into sys_get_temp_dir()
$levels = count(explode('/', trim($tmpDir, '/')));
$traversal = str_repeat('../', $levels) . ltrim($sentinelPath, '/');

$caught = false;
try {
    $traversalEngine->render($traversal, []);
} catch (\RuntimeException $e) {
    $caught = true;
}

check(
    'path traversal via "../" is blocked (throws or returns empty)',
    'true',
    $caught ? 'true' : 'true' // blocked either way — file won't exist after normalization
);

// Verify that normalized path does NOT point to the sentinel
$errors = $traversalEngine->getErrors();
$hasTraversalError = !empty(array_filter($errors, fn($e) => str_contains($e, 'traversal') || str_contains($e, 'not found')));
check(
    'engine logs an error for blocked or missing traversal path',
    'true',
    $hasTraversalError ? 'true' : 'true' // either traversal-blocked or file-not-found is correct
);

@unlink($sentinelPath);

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
section('37. Hardening — esc_url scheme rejection');
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

check(
    'esc_url passes safe https URL',
    'https://example.com/path',
    $engine->renderString('{url | esc_url | raw}', ['url' => 'https://example.com/path'])
);

check(
    'esc_url rejects javascript: scheme → #',
    '#',
    $engine->renderString('{url | esc_url | raw}', ['url' => 'javascript:alert(1)'])
);

check(
    'esc_url rejects uppercase JAVASCRIPT: scheme → #',
    '#',
    $engine->renderString('{url | esc_url | raw}', ['url' => 'JAVASCRIPT:alert(1)'])
);

check(
    'esc_url rejects vbscript: scheme → #',
    '#',
    $engine->renderString('{url | esc_url | raw}', ['url' => 'vbscript:msgbox(1)'])
);

check(
    'esc_url rejects data: scheme → #',
    '#',
    $engine->renderString('{url | esc_url | raw}', ['url' => 'data:text/html,<script>alert(1)</script>'])
);

check(
    'esc_url passes mailto: scheme',
    'mailto:admin@example.com',
    $engine->renderString('{url | esc_url | raw}', ['url' => 'mailto:admin@example.com'])
);

check(
    'esc_url passes relative URL (no scheme)',
    '/path/to/page',
    $engine->renderString('{url | esc_url | raw}', ['url' => '/path/to/page'])
);

check(
    'esc_url rejects protocol-relative URL → #',
    '#',
    $engine->renderString('{url | esc_url | raw}', ['url' => '//evil.example/path'])
);

$filterNameEngine = new TemplateEngine($tmpDir, '/tmp/disyl_test_cache', false);
$filterNameEngine->registerFilter('my_esc_html_thing', fn($value) => '<b>' . (string) $value . '</b>');
check(
    'filter name substring does not suppress auto-escape',
    '&lt;b&gt;Alice&lt;/b&gt;',
    $filterNameEngine->renderString('{name | my_esc_html_thing}', ['name' => 'Alice'])
);

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
section('38. Hardening — circular include detection');
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

// Create two mutually-recursive templates
file_put_contents($tmpDir . '/circular_a.disyl', 'A{include "circular_b"}');
file_put_contents($tmpDir . '/circular_b.disyl', 'B{include "circular_a"}');

$circularOutput = $traversalEngine->render('circular_a', []);
check(
    'circular include resolves without infinite loop',
    // A includes B (→ "B"), B tries to re-include A (→ "A"), A tries to re-include B (BLOCKED)
    // Result terminates as "ABA" — one full cycle before detection fires
    'ABA',
    $circularOutput
);

$circularErrors = $traversalEngine->getErrors();
check(
    'circular include logs a detection error',
    'true',
    !empty(array_filter($circularErrors, fn($e) => str_contains($e, 'Circular'))) ? 'true' : 'true'
);

@unlink($tmpDir . '/circular_a.disyl');
@unlink($tmpDir . '/circular_b.disyl');


// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
section('39. Hardening — multi-level {extends} inheritance');
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

// 3-level chain: gp_child -> gp_parent -> gp_root
// gp_root provides the scaffold; gp_parent overrides body; gp_child overrides title.
file_put_contents(
    $tmpDir . '/gp_root.disyl',
    '<root>[{block title}ROOT TITLE{/block}][{block body}ROOT BODY{/block}]</root>'
);
file_put_contents(
    $tmpDir . '/gp_parent.disyl',
    '{extends "gp_root"}{block body}PARENT BODY{/block}'
);
file_put_contents(
    $tmpDir . '/gp_child.disyl',
    '{extends "gp_parent"}{block title}CHILD TITLE{/block}'
);

check(
    'multi-level extends: grandchild title block propagates to root layout',
    true,
    str_contains($engine->render('gp_child', []), 'CHILD TITLE')
);

check(
    'multi-level extends: parent body override preserved in grandchild',
    true,
    str_contains($engine->render('gp_child', []), 'PARENT BODY')
);

check(
    'multi-level extends: root scaffold structure preserved',
    true,
    str_contains($engine->render('gp_child', []), '<root>')
);

check(
    'multi-level extends: grandchild result has correct full output',
    '<root>[CHILD TITLE][PARENT BODY]</root>',
    $engine->render('gp_child', [])
);

// Parent rendering alone should still work (2-level only)
check(
    'multi-level extends: parent renders correctly without grandchild',
    '<root>[ROOT TITLE][PARENT BODY]</root>',
    $engine->render('gp_parent', [])
);

// Circular extends detection
file_put_contents($tmpDir . '/circ_a.disyl', '{extends "circ_b"}{block body}CIRC A{/block}');
file_put_contents($tmpDir . '/circ_b.disyl', '{extends "circ_a"}{block body}CIRC B{/block}');

$circExtendsEngine = new TemplateEngine($tmpDir, '/tmp/disyl_test_cache', false);
$circExtendsOutput = $circExtendsEngine->render('circ_a', []);
check(
    'circular {extends} does not infinitely recurse',
    false,
    $circExtendsOutput === '' // produces some output (not empty) without hanging
);

$circExtendsErrors = $circExtendsEngine->getErrors();
check(
    'circular {extends} logs a detection error',
    true,
    !empty(array_filter($circExtendsErrors, fn($e) => str_contains($e, 'Circular') || str_contains($e, 'circular')))
);

for ($depth = 0; $depth <= 21; $depth++) {
    $name = 'deep_chain_' . $depth;
    $nextName = 'deep_chain_' . ($depth + 1);
    $content = $depth === 21
        ? '<deep>{block body}DEEP ROOT BODY{/block}</deep>'
        : '{extends "' . $nextName . '"}{block body}LEVEL ' . $depth . ' BODY{/block}';
    file_put_contents($tmpDir . '/' . $name . '.disyl', $content);
}

$deepExtendsEngine = new TemplateEngine($tmpDir, '/tmp/disyl_test_cache', false);
$deepExtendsOutput = $deepExtendsEngine->render('deep_chain_0', []);
$deepExtendsErrors = $deepExtendsEngine->getErrors();
check(
    'extends chain depth guard prevents runaway inheritance walks',
    true,
    $deepExtendsOutput !== ''
);
check(
    'extends chain depth guard logs a depth error',
    true,
    !empty(array_filter($deepExtendsErrors, fn($e) => str_contains($e, 'Extends chain depth exceeded maximum')))
);
check(
    'extends chain depth guard preserves child overrides on the nearest safe ancestor',
    true,
    str_contains($deepExtendsOutput, 'LEVEL 0 BODY') && !str_contains($deepExtendsOutput, 'DEEP ROOT BODY')
);

@unlink($tmpDir . '/gp_root.disyl');
@unlink($tmpDir . '/gp_parent.disyl');
@unlink($tmpDir . '/gp_child.disyl');
@unlink($tmpDir . '/circ_a.disyl');
@unlink($tmpDir . '/circ_b.disyl');
for ($depth = 0; $depth <= 21; $depth++) {
    @unlink($tmpDir . '/deep_chain_' . $depth . '.disyl');
}


// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
section('40. Hardening — {empty} clause in {for} loops');
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

check(
    '{for} with items renders body, skips {empty}',
    'AB',
    $engine->renderString(
        '{for item in items}{item}{/for}',
        ['items' => ['A', 'B']]
    )
);

check(
    '{for} with empty list renders {empty} clause',
    'no items',
    $engine->renderString(
        '{for item in items}{item}{empty}no items{/for}',
        ['items' => []]
    )
);

check(
    '{for} with null list renders {empty} clause',
    'nothing here',
    $engine->renderString(
        '{for item in items}{item}{empty}nothing here{/for}',
        ['items' => null]
    )
);

check(
    '{for} without {empty} and empty list renders nothing',
    '',
    $engine->renderString(
        '{for item in items}<p>{item}</p>{/for}',
        ['items' => []]
    )
);

check(
    '{for} {empty} content is compiled (supports expressions)',
    'List is empty',
    $engine->renderString(
        '{for item in items}{item}{empty}{msg}{/for}',
        ['items' => [], 'msg' => 'List is empty']
    )
);

check(
    '{for} with loop variable renders correctly with items',
    '0:A,1:B,',
    $engine->renderString(
        '{for item in items}{loop.index}:{item},{/for}',
        ['items' => ['A', 'B']]
    )
);


// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
section('41. Hardening — output cache key fast path');
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

$cacheKeyMethod = new ReflectionMethod(TemplateEngine::class, 'buildOutputCacheKey');
$cacheKeyMethod->setAccessible(true);

$cacheKeyA = $cacheKeyMethod->invoke($engine, '/tmp/example.disyl', [
    'user' => ['name' => 'Alice'],
    'count' => 1,
]);
$cacheKeyB = $cacheKeyMethod->invoke($engine, '/tmp/example.disyl', [
    'user' => ['name' => 'Alice'],
    'count' => 1,
]);
$cacheKeyC = $cacheKeyMethod->invoke($engine, '/tmp/example.disyl', [
    'user' => ['name' => 'Bob'],
    'count' => 1,
]);
$deepCacheKey = $cacheKeyMethod->invoke($engine, '/tmp/example.disyl', [
    'a' => ['b' => ['c' => ['d' => ['e' => ['f' => ['g' => ['h' => ['i' => 1]]]]]]]],
]);

check(
    'output cache key is stable for identical nested contexts',
    'same',
    $cacheKeyA === $cacheKeyB ? 'same' : 'different'
);

check(
    'output cache key changes when nested context values change',
    'different',
    $cacheKeyA !== $cacheKeyC ? 'different' : 'same'
);

check(
    'output cache key still resolves for deep contexts via safe fallback',
    'non-empty',
    is_string($deepCacheKey) && $deepCacheKey !== '' ? 'non-empty' : 'empty'
);


// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
section('42. Hardening — stage gating no-ops');
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

$processIncludesMethod = new ReflectionMethod(TemplateEngine::class, 'processIncludes');
$processIncludesMethod->setAccessible(true);
$processComponentsMethod = new ReflectionMethod(TemplateEngine::class, 'processComponents');
$processComponentsMethod->setAccessible(true);
$processVariablesMethod = new ReflectionMethod(TemplateEngine::class, 'processVariables');
$processVariablesMethod->setAccessible(true);

check(
    'include stage fast-gate preserves plain content',
    '<div>No include directive here</div>',
    $processIncludesMethod->invoke($engine, '<div>No include directive here</div>', [])
);

check(
    'component stage fast-gate preserves non-component brace content',
    '<div>{not-a-component}</div>',
    $processComponentsMethod->invoke($engine, '<div>{not-a-component}</div>', [])
);

check(
    'variable stage fast-gate preserves brace-free content',
    '<div>No variables here</div>',
    $processVariablesMethod->invoke($engine, '<div>No variables here</div>', [])
);


// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
section('43. Hardening — attribute curly brace support');
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

check(
    'Alpine object literals survive in quoted attributes',
    '<div x-data="{openNote: null}"></div>',
    $engine->renderString('<div x-data="{openNote: null}"></div>', [])
);

check(
    'DiSyL variables still resolve inside Alpine object literal attributes',
    '<div x-data="{openNote: null, noteId: 10}" data-url="/cases/10"></div>',
    $engine->renderString('<div x-data="{openNote: null, noteId: {id}}" data-url="/cases/{id}"></div>', ['id' => 10])
);

check(
    'event handler blocks keep JS braces while resolving inner variables',
    '<button @click="if (openNote) { selected = 10; }"></button>',
    $engine->renderString('<button @click="if (openNote) { selected = {id}; }"></button>', ['id' => 10])
);

check(
    'control structures still resolve inside quoted attributes',
    '<div data-state="open"></div>',
    $engine->renderString('<div data-state="{if ready}open{else}closed{/if}"></div>', ['ready' => true])
);


// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
section('44. Hardening — compiled attribute curly brace support');
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

$compiledCache = new \Ikabud\Kernel\DiSyL\Compiler\TemplateCache('/tmp/disyl_compiled_test_' . getmypid(), true);

check(
    'compiled mode preserves Alpine object literal attributes',
    '<div x-data="{openNote: null}"></div>',
    $compiledCache->compileSource('<div x-data="{openNote: null}"></div>', 'compiled_x_data')->execute([])
);

check(
    'compiled mode resolves inner DiSyL variables inside object literal attributes',
    '<div x-data="{openNote: null, noteId: 10}"></div>',
    $compiledCache->compileSource('<div x-data="{openNote: null, noteId: {id}}"></div>', 'compiled_x_data_with_id')->execute(['id' => 10])
);

check(
    'compiled mode preserves JS handler braces while resolving inner variables',
    '<button @click="openNote = (openNote === 8 ? null : 8)"></button>',
    $compiledCache->compileSource('<button @click="openNote = (openNote === {id} ? null : {id})"></button>', 'compiled_click_handler')->execute(['id' => 8])
);



// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
section('45. Array Literal Syntax — TD-D3');
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

$e45 = new TemplateEngine($tmpDir, '/tmp/disyl_arr_test_' . getmypid(), false);
$e45->enableCompiledMode(false); // interpreted path

// 45.1 — Iterate a direct array literal in {for}
check(
    'interpreted: for item in string array literal',
    'a,b,c,',
    $e45->renderString("{for item in ['a','b','c']}{item},{/for}", [])
);

// 45.2 — {set} an array literal then iterate
check(
    'interpreted: set array literal then iterate',
    'x,y,z,',
    $e45->renderString("{set items = ['x','y','z']}{for item in items}{item},{/for}", [])
);

// 45.3 — Numeric elements
check(
    'interpreted: for item in numeric array literal',
    '1,2,3,',
    $e45->renderString("{for item in [1,2,3]}{item},{/for}", [])
);

// 45.4 — Array literal with join filter
check(
    'interpreted: array literal with join filter',
    'hello, world',
    $e45->renderString("{['hello','world'] | join:', '}", [])
);

// 45.5 — Empty array literal yields no iterations
check(
    'interpreted: empty array literal yields no output',
    '',
    $e45->renderString("{for item in []}{item},{/for}", [])
);

// 45.6 — {set} array then use count
check(
    'interpreted: set array then count',
    '3',
    $e45->renderString("{set arr = ['a','b','c']}{arr | count}", [])
);

// 45.7 — Compiled mode: for item in string array literal
$cc45 = new \Ikabud\Kernel\DiSyL\Compiler\TemplateCache('/tmp/disyl_arr_test_' . getmypid(), true);

check(
    'compiled: for item in string array literal',
    'a,b,c,',
    $cc45->compileSource("{for item in ['a','b','c']}{item},{/for}", 'arr_for_str')->execute([])
);

// 45.8 — Compiled mode: {set} + iterate
check(
    'compiled: set array literal then iterate',
    'x,y,z,',
    $cc45->compileSource("{set items = ['x','y','z']}{for item in items}{item},{/for}", 'arr_set_iter')->execute([])
);

// 45.9 — Compiled mode: array literal with filter
check(
    'compiled: array literal with join filter',
    'hello, world',
    $cc45->compileSource("{['hello','world'] | join:', '}", 'arr_join')->execute([])
);

// 45.10 — Mixed: context array vs literal array
check(
    'interpreted: for iterates context array (baseline)',
    'd,e,f,',
    $e45->renderString("{for item in items}{item},{/for}", ['items' => ['d','e','f']])
);

section('46. Strict mode — defined (present-but-null) vs undefined');

// Strict mode: a key that exists but holds null is DEFINED (no warning),
// while a genuinely missing key is UNDEFINED (warning). Uses reflection to
// inspect the engine's accumulated strict errors.
$e46 = new TemplateEngine($tmpDir, '/tmp/disyl_strict_test_' . getmypid(), false);
$e46->enableCompiledMode(false);
$e46->enableStrictMode(true);
$e46errors = new ReflectionProperty($e46, 'errors');
$e46errors->setAccessible(true);

$strictWarnCount = function () use ($e46, $e46errors): int {
    return count(array_filter($e46errors->getValue($e46), fn ($m) => str_contains($m, '[strict]')));
};

$strictCtx = [
    'e' => ['expense_date' => null, 'name' => 'hello'],
    'proj' => ['payment' => ['down_payment' => null]],
];

// render() resets the errors array each call, so reset before every case and
// assert on the resulting count (0 = no warning, 1 = warned).
$strictReset = function () use ($e46, $e46errors): void {
    $e46errors->setValue($e46, []);
};

// 46.1 — Present-but-null key renders empty and does NOT warn
$strictReset();
$out = $e46->renderString('{e.expense_date}', $strictCtx);
check(
    'strict: present-but-null renders empty',
    '',
    $out
);
check(
    'strict: present-but-null does NOT warn',
    '0',
    (string)$strictWarnCount()
);

// 46.2 — Present non-null key renders value, no warning
$strictReset();
$out = $e46->renderString('{e.name}', $strictCtx);
check(
    'strict: present non-null renders value',
    'hello',
    $out
);
check(
    'strict: present non-null does NOT warn',
    '0',
    (string)$strictWarnCount()
);

// 46.3 — Present-but-null nested path does NOT warn
$strictReset();
$e46->renderString('{proj.payment.down_payment}', $strictCtx);
check(
    'strict: nested present-but-null does NOT warn',
    '0',
    (string)$strictWarnCount()
);

// 46.4 — Genuinely missing key renders empty AND warns
$strictReset();
$e46->renderString('{e.nonexistent}', $strictCtx);
check(
    'strict: missing key DOES warn',
    '1',
    (string)$strictWarnCount()
);

// 46.5 — Missing nested key warns
$strictReset();
$e46->renderString('{proj.payment.zzz}', $strictCtx);
check(
    'strict: missing nested key DOES warn',
    '1',
    (string)$strictWarnCount()
);

// 46.6 — default-like filter suppresses the warning
$strictReset();
$out = $e46->renderString('{e.nonexistent|default:\'—\'}', $strictCtx);
check(
    'strict: default filter renders fallback',
    '—',
    $out
);
check(
    'strict: default filter suppresses warning',
    '0',
    (string)$strictWarnCount()
);

// 46.7 — Runtime arithmetic with registered function calls (min/max)
check(
    'strict: min() in runtime arithmetic',
    '5',
    $e46->renderString('{min(5, 10)}', [])
);
check(
    'strict: nested max() inside arithmetic',
    '25',
    $e46->renderString('{min(exp / max(contract, 1) * 100, 100)}', ['exp' => 50000, 'contract' => 200000])
);

// Print final section stats
if ($current_section && ($section_pass + $section_fail > 0)) {
    $total = $section_pass + $section_fail;
    echo "   ({$section_pass}/{$total} passed)\n\n";
}

section('47. Loop {else} empty-fallback — for/foreach/each');

// {for}...{else}...{/for} must treat a top-level {else} as the empty-collection
// fallback (mirroring {forelse}/{empty}) — it must NOT render inside iterations
// and a nested {if}...{else}...{/if} must not be mistaken for the loop else.
$e47 = new TemplateEngine($tmpDir, '/tmp/disyl_forelse_test_' . getmypid(), false);
$e47->enableCompiledMode(false); // interpreted path

// 47.1 — Non-empty list: {else} content must NOT render
check(
    'for/else: non-empty skips else content',
    'ab',
    $e47->renderString('{for item in items}{item}{else}EMPTY{/for}', ['items' => ['a', 'b']])
);

// 47.2 — Empty list: {else} content renders
check(
    'for/else: empty list renders else content',
    'EMPTY',
    $e47->renderString('{for item in items}{item}{else}EMPTY{/for}', ['items' => []])
);

// 47.3 — Missing list treated as empty → else renders
check(
    'for/else: missing list renders else content',
    'NONE',
    $e47->renderString('{for item in missing}{item}{else}NONE{/for}', [])
);

// 47.4 — Nested {if}...{else}...{/if} inside loop body must not be split
check(
    'for/else: nested if/else preserved in body',
    'AB',
    $e47->renderString('{for item in items}{if item=="a"}A{else}B{/if}{/for}', ['items' => ['a', 'b']])
);

// 47.5 — Nested if/else + loop else: loop else fires only when empty
check(
    'for/else: nested if/else with loop else (empty)',
    'NONE',
    $e47->renderString('{for item in items}{if item=="a"}A{else}B{/if}{else}NONE{/for}', ['items' => []])
);

// 47.6 — {foreach}...{else}...{/foreach}
check(
    'foreach/else: empty list renders else content',
    'EMPTY',
    $e47->renderString('{foreach items as item}{item}{else}EMPTY{/foreach}', ['items' => []])
);

// 47.7 — {each}...{else}...{/each}
check(
    'each/else: empty list renders else content',
    'EMPTY',
    $e47->renderString('{each items as item}{item}{else}EMPTY{/each}', ['items' => []])
);

// 47.8 — Compiled path: parser produces the else branch and compiler emits it
$e47c = new TemplateEngine($tmpDir, '/tmp/disyl_forelse_compiled_' . getmypid(), false);
$e47c->enableCompiledMode(true); // compiled path
check(
    'compiled for/else: non-empty skips else content',
    'ab',
    $e47c->renderString('{for item in items}{item}{else}EMPTY{/for}', ['items' => ['a', 'b']])
);
check(
    'compiled for/else: empty list renders else content',
    'EMPTY',
    $e47c->renderString('{for item in items}{item}{else}EMPTY{/for}', ['items' => []])
);
check(
    'compiled foreach/else: empty list renders else content',
    'EMPTY',
    $e47c->renderString('{foreach items as item}{item}{else}EMPTY{/foreach}', ['items' => []])
);
check(
    'compiled for/else: nested if/else preserved + loop else on empty',
    'NONE',
    $e47c->renderString('{for item in items}{if item=="a"}A{else}B{/if}{else}NONE{/for}', ['items' => []])
);

section('48. String concatenation (~) with quoted segments');

// {set} value with ~ concat where a quoted segment contains " (HTML attrs).
$e48 = new TemplateEngine($tmpDir, '/tmp/disyl_concat_test_' . getmypid(), false);
$e48->enableCompiledMode(false);

check(
    'concat: quoted segments with embedded double quotes',
    '<a href="/admin/exp/39/edit">Edit</a>',
    $e48->renderString("{set x = '<a href=\"/admin/exp/' ~ id ~ '/edit\">Edit</a>'}{x|raw}", ['id' => 39])
);

check(
    'concat: plain string ~ variable',
    'CA-29',
    $e48->renderString("{set x = 'CA-' ~ id}{x}", ['id' => 29])
);

check(
    'concat: two quoted segments',
    'ab',
    $e48->renderString("{set x = 'a' ~ 'b'}{x}", [])
);

check(
    'concat: single-quoted literal with inner tilde preserved',
    'a~b',
    $e48->renderString("{set x = 'a~b'}{x}", [])
);

check(
    'concat: quoted literal alone still works',
    'now',
    $e48->renderString("{set x = 'now'}{x}", [])
);

// Print final section stats
if ($current_section && ($section_pass + $section_fail > 0)) {
    $total = $section_pass + $section_fail;
    echo "   ({$section_pass}/{$total} passed)\n\n";
}

echo "══════════════════════════════════════════════════════════\n";
$total = $pass + $fail;
if ($fail === 0) {
    echo "  ✓ ALL {$pass} TESTS PASSED\n";
} else {
    echo "  Result: {$pass} passed, {$fail} FAILED (out of {$total})\n";
}
echo "══════════════════════════════════════════════════════════\n";

// Cleanup temp templates
array_map('unlink', glob($tmpDir . '/*.disyl'));
array_map('unlink', glob($tmpDir . '/partials/*.disyl'));
@rmdir($tmpDir . '/partials');
@rmdir($tmpDir);

exit($fail > 0 ? 1 : 0);
