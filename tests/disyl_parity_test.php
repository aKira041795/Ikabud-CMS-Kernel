<?php
/**
 * DiSyL Interpreted ↔ Compiled Parity Test Suite
 *
 * Renders every template through BOTH the interpreted (TemplateEngine::renderString)
 * and compiled (Parser → TemplateCompiler → CompiledTemplate::execute) pipelines,
 * then asserts identical output.
 *
 * This is the single most important test for long-term stability: if the two
 * paths ever diverge (escaping, null handling, loop metadata, filter behaviour)
 * this test will catch it immediately.
 */

require_once __DIR__ . '/../bootstrap.php';

use Ikabud\Kernel\DiSyL\TemplateEngine;
use Ikabud\Kernel\DiSyL\Compiler\TemplateCache;
use Ikabud\Kernel\DiSyL\v4\RenderContext;

// ── Test infrastructure ─────────────────────────────────

$pass = 0;
$fail = 0;
$known_divergences = 0;
$section_pass = 0;
$section_fail = 0;
$section_known = 0;
$current_section = '';

/**
 * Known divergences between interpreted and compiled paths.
 * Each entry: description => reason.
 * These are tracked explicitly — not hidden.  Fix the interpreted engine
 * or adjust the compiled engine when addressing them.
 */
$KNOWN_DIVERGENCES = [
    // All previous divergences resolved — this array should stay empty.
    // If you add an entry here, file a tracking issue.
];

function section(string $title): void
{
    global $section_pass, $section_fail, $section_known, $current_section;
    if ($current_section && ($section_pass + $section_fail + $section_known > 0)) {
        $total = $section_pass + $section_fail + $section_known;
        $extra = $section_known > 0 ? ", {$section_known} known" : '';
        echo "   ({$section_pass}/{$total} passed{$extra})\n\n";
    }
    $current_section = $title;
    $section_pass = 0;
    $section_fail = 0;
    $section_known = 0;
    echo "── {$title} " . str_repeat('─', max(1, 60 - strlen($title))) . "\n";
}

function check(string $desc, string $expected, string $actual): void
{
    global $pass, $fail, $known_divergences, $section_pass, $section_fail, $section_known, $KNOWN_DIVERGENCES;
    $expected = trim($expected);
    $actual = trim($actual);
    if ($expected === $actual) {
        echo "  ✓ {$desc}\n";
        $pass++;
        $section_pass++;
    } elseif (isset($KNOWN_DIVERGENCES[$desc])) {
        echo "  ⚠ {$desc} [KNOWN DIVERGENCE]\n";
        echo "    Interpreted: " . json_encode($expected) . "\n";
        echo "    Compiled:    " . json_encode($actual) . "\n";
        echo "    Reason: {$KNOWN_DIVERGENCES[$desc]}\n";
        $known_divergences++;
        $section_known++;
    } else {
        echo "  ✗ {$desc}\n";
        echo "    Expected: " . json_encode($expected) . "\n";
        echo "    Actual:   " . json_encode($actual) . "\n";
        $fail++;
        $section_fail++;
    }
}

/**
 * Render a template source through the interpreted pipeline.
 */
function interpreted(TemplateEngine $engine, string $source, array $ctx): string
{
    return $engine->renderString($source, $ctx);
}

/**
 * Render a template source through the compiled pipeline.
 */
function compiled(
    TemplateCache $cache,
    string $source,
    array $ctx,
    string $name = 'parity',
    ?array &$errors = null
): string {
    global $parityTemplateDir;

    $errors = [];
    $loader = null;
    $loader = static function (string $templateName) use ($cache, &$loader, &$errors, $parityTemplateDir) {
        $path = $parityTemplateDir . '/' . (str_ends_with($templateName, '.disyl')
            ? $templateName
            : $templateName . '.disyl');
        $loaded = $cache->get($path);
        $loaded->setTemplateLoader($loader);
        $loaded->setErrorHandler(static function (string $error) use (&$errors): void {
            $errors[] = $error;
        });
        return $loaded;
    };

    $template = $cache->compileSource($source, $name);
    $template->setTemplateLoader($loader);
    $template->setErrorHandler(static function (string $error) use (&$errors): void {
        $errors[] = $error;
    });

    // Mirror TemplateEngine's currently guarded compiled inheritance handoff.
    $renderContext = new RenderContext($ctx);
    $result = $template->executeRaw($renderContext);
    $maxExtendsDepth = 10;
    while ($renderContext->getParentTemplate() !== null && $maxExtendsDepth-- > 0) {
        $parentName = $renderContext->getParentTemplate();
        $renderContext->setParentTemplate(null);
        $parent = $loader($parentName);
        $result = $parent->executeRaw($renderContext);
    }

    return $result;
}

/**
 * Assert that both paths produce the same output for a given template + context.
 */
function writeParityTemplates(array $templates): void
{
    global $parityTemplateDir;
    foreach ($templates as $name => $source) {
        $path = $parityTemplateDir . '/' . (str_ends_with($name, '.disyl') ? $name : $name . '.disyl');
        @mkdir(dirname($path), 0755, true);
        file_put_contents($path, $source);
    }
}

function parity(
    string $desc,
    TemplateEngine $engine,
    TemplateCache $cache,
    string $source,
    array $ctx,
    array $dependencies = [],
    bool $compareDiagnostics = false
): void {
    global $parityTemplateDir;

    writeParityTemplates($dependencies);

    $interpretedException = null;
    try {
        $interpretedResult = interpreted($engine, $source, $ctx);
    } catch (Throwable $e) {
        $interpretedException = get_class($e) . ': ' . $e->getMessage();
        $interpretedResult = '[exception] ' . $interpretedException;
    }
    $interpretedErrors = $engine->getErrors();

    $compiledErrors = [];
    $compiledException = null;
    try {
        $compiledResult = compiled($cache, $source, $ctx, $desc, $compiledErrors);
    } catch (Throwable $e) {
        $compiledException = get_class($e) . ': ' . $e->getMessage();
        $compiledResult = '[exception] ' . $compiledException;
    }

    $outputMatches = trim($interpretedResult) === trim($compiledResult);
    check($desc, $interpretedResult, $compiledResult);

    $diagnosticsMatch = true;
    if ($compareDiagnostics) {
        $interpretedDiagnostics = json_encode([
            'exception' => $interpretedException,
            'errors' => $interpretedErrors,
        ], JSON_UNESCAPED_SLASHES);
        $compiledDiagnostics = json_encode([
            'exception' => $compiledException,
            'errors' => $compiledErrors,
        ], JSON_UNESCAPED_SLASHES);
        $diagnosticsMatch = $interpretedDiagnostics === $compiledDiagnostics;
        check($desc . ' diagnostics', $interpretedDiagnostics, $compiledDiagnostics);
    }

    if (!$outputMatches || !$diagnosticsMatch) {
        echo '    Minimal source: ' . json_encode(
            ['inline' => $source] + $dependencies,
            JSON_UNESCAPED_SLASHES
        ) . "\n";
        echo '    Interpreted output: ' . json_encode($interpretedResult) . "\n";
        echo '    Compiled output:    ' . json_encode($compiledResult) . "\n";
        if ($compareDiagnostics) {
            echo '    Interpreted diagnostics: ' . $interpretedDiagnostics . "\n";
            echo '    Compiled diagnostics:    ' . $compiledDiagnostics . "\n";
        }
    }
}

// ── Bootstrap engines ───────────────────────────────────

$tmpDir = sys_get_temp_dir() . '/disyl_parity_test_' . getmypid();
@mkdir($tmpDir . '/templates', 0755, true);
@mkdir($tmpDir . '/cache/compiled', 0755, true);

$parityTemplateDir = $tmpDir . '/templates';
$engine = new TemplateEngine($parityTemplateDir, $tmpDir . '/cache');
$cache  = new TemplateCache($tmpDir . '/cache/compiled', true);

echo "DiSyL Interpreted ↔ Compiled Parity Test Suite\n";
echo "================================================\n\n";

// ─────────────────────────────────────────────────────────
// 1. Simple variables & auto-escaping
// ─────────────────────────────────────────────────────────
section('1. Simple variables & auto-escaping');

parity('plain text passthrough', $engine, $cache,
    'Hello world', []);

parity('simple variable', $engine, $cache,
    'Hello {name}!', ['name' => 'World']);

parity('HTML auto-escaping', $engine, $cache,
    '{content}', ['content' => '<b>bold</b>']);

parity('raw output with |raw filter', $engine, $cache,
    '{content|raw}', ['content' => '<b>bold</b>']);

parity('multiple variables', $engine, $cache,
    '{first} {last}', ['first' => 'John', 'last' => 'Doe']);

parity('missing variable yields empty', $engine, $cache,
    'Hello {missing}!', []);

parity('numeric variable', $engine, $cache,
    'Count: {n}', ['n' => 42]);

parity('boolean true renders as 1', $engine, $cache,
    '{flag}', ['flag' => true]);

parity('null renders as empty', $engine, $cache,
    '[{val}]', ['val' => null]);

// ─────────────────────────────────────────────────────────
// 2. Dot-path property access
// ─────────────────────────────────────────────────────────
section('2. Dot-path property access');

parity('nested array access', $engine, $cache,
    '{user.name}', ['user' => ['name' => 'Alice']]);

parity('deep nesting', $engine, $cache,
    '{a.b.c}', ['a' => ['b' => ['c' => 'deep']]]);

parity('missing nested key', $engine, $cache,
    '[{user.email}]', ['user' => ['name' => 'Alice']]);

// ─────────────────────────────────────────────────────────
// 3. Filters
// ─────────────────────────────────────────────────────────
section('3. Filters');

parity('upper filter', $engine, $cache,
    '{name|upper}', ['name' => 'hello']);

parity('lower filter', $engine, $cache,
    '{name|lower}', ['name' => 'HELLO']);

parity('capitalize filter', $engine, $cache,
    '{name|capitalize}', ['name' => 'hello world']);

parity('trim filter', $engine, $cache,
    '[{val|trim}]', ['val' => '  spaced  ']);

parity('length filter on string', $engine, $cache,
    '{name|length}', ['name' => 'hello']);

parity('length filter on array', $engine, $cache,
    '{items|length}', ['items' => [1, 2, 3]]);

parity('default filter on null', $engine, $cache,
    '{val|default:"fallback"}', ['val' => null]);

parity('default filter with value', $engine, $cache,
    '{val|default:"fallback"}', ['val' => 'real']);

parity('replace filter', $engine, $cache,
    '{text|replace:"world":"earth"}', ['text' => 'hello world']);

parity('truncate filter', $engine, $cache,
    '{text|truncate:10}', ['text' => 'This is a long string that needs truncating']);

parity('nl2br filter', $engine, $cache,
    '{text|nl2br|raw}', ['text' => "line1\nline2"]);

parity('json filter', $engine, $cache,
    '{data|json}', ['data' => ['a' => 1, 'b' => 2]]);

parity('chained filters', $engine, $cache,
    '{name|trim|upper}', ['name' => '  hello  ']);

parity('number_format filter', $engine, $cache,
    '{price|number_format:2}', ['price' => 1234.5]);

parity('reverse filter on string', $engine, $cache,
    '{text|reverse}', ['text' => 'hello']);

parity('join filter', $engine, $cache,
    '{items|join:", "}', ['items' => ['a', 'b', 'c']]);

parity('keys filter', $engine, $cache,
    '{data|keys|join:", "}', ['data' => ['x' => 1, 'y' => 2]]);

parity('values filter', $engine, $cache,
    '{data|values|join:", "}', ['data' => ['x' => 1, 'y' => 2]]);

parity('first filter', $engine, $cache,
    '{items|first}', ['items' => ['alpha', 'beta', 'gamma']]);

parity('last filter', $engine, $cache,
    '{items|last}', ['items' => ['alpha', 'beta', 'gamma']]);

parity('sort filter', $engine, $cache,
    '{items|sort|join:","}', ['items' => [3, 1, 2]]);

parity('unique filter', $engine, $cache,
    '{items|unique|join:","}', ['items' => [1, 2, 2, 3, 1]]);

parity('slice filter', $engine, $cache,
    '{items|slice:1:2|join:","}', ['items' => ['a', 'b', 'c', 'd']]);

parity('split filter', $engine, $cache,
    '{text|split:","|join:" "}', ['text' => 'a,b,c']);

parity('abs filter', $engine, $cache,
    '{n|abs}', ['n' => -42]);

parity('round filter', $engine, $cache,
    '{n|round:1}', ['n' => 3.456]);

parity('url_encode filter', $engine, $cache,
    '{text|url_encode}', ['text' => 'hello world&foo=bar']);

parity('striptags filter', $engine, $cache,
    '{html|striptags}', ['html' => '<p>Hello <b>World</b></p>']);

parity('wordcount filter', $engine, $cache,
    '{text|wordcount}', ['text' => 'one two three four']);

parity('md5 filter', $engine, $cache,
    '{text|md5}', ['text' => 'test']);

parity('base64 filter', $engine, $cache,
    '{text|base64}', ['text' => 'hello']);

parity('repeat filter', $engine, $cache,
    '{text|repeat:3}', ['text' => 'ab']);

parity('pad_left filter', $engine, $cache,
    '{n|pad_left:5:"0"}', ['n' => '42']);

parity('pad_right filter', $engine, $cache,
    '{n|pad_right:5:"0"}', ['n' => '42']);

// ─────────────────────────────────────────────────────────
// 4. Conditions
// ─────────────────────────────────────────────────────────
section('4. Conditions (if / elseif / else)');

parity('if true', $engine, $cache,
    '{if show}visible{/if}', ['show' => true]);

parity('if false', $engine, $cache,
    '{if show}visible{/if}', ['show' => false]);

parity('if/else — true branch', $engine, $cache,
    '{if ok}yes{else}no{/if}', ['ok' => true]);

parity('if/else — false branch', $engine, $cache,
    '{if ok}yes{else}no{/if}', ['ok' => false]);

parity('if with string equality', $engine, $cache,
    '{if color == "red"}RED{else}OTHER{/if}', ['color' => 'red']);

parity('if with numeric comparison', $engine, $cache,
    '{if count > 5}big{else}small{/if}', ['count' => 10]);

parity('negation with not', $engine, $cache,
    '{if not hidden}shown{/if}', ['hidden' => false]);

parity('if with AND', $engine, $cache,
    '{if a and b}both{else}nope{/if}', ['a' => true, 'b' => true]);

parity('if with OR', $engine, $cache,
    '{if a or b}either{else}neither{/if}', ['a' => false, 'b' => true]);

parity('truthy: empty string is false', $engine, $cache,
    '{if val}yes{else}no{/if}', ['val' => '']);

parity('truthy: empty array is false', $engine, $cache,
    '{if items}yes{else}no{/if}', ['items' => []]);

parity('truthy: zero is false', $engine, $cache,
    '{if n}yes{else}no{/if}', ['n' => 0]);

parity('truthy: null is false', $engine, $cache,
    '{if val}yes{else}no{/if}', ['val' => null]);

parity('truthy: non-empty string is true', $engine, $cache,
    '{if val}yes{else}no{/if}', ['val' => 'x']);

parity('truthy: non-empty array is true', $engine, $cache,
    '{if items}yes{else}no{/if}', ['items' => [1]]);

parity('if != comparison', $engine, $cache,
    '{if status != "active"}inactive{else}active{/if}', ['status' => 'pending']);

parity('if with <=', $engine, $cache,
    '{if x <= 5}small{else}big{/if}', ['x' => 5]);

parity('if with >=', $engine, $cache,
    '{if x >= 10}big{else}small{/if}', ['x' => 10]);

// ─────────────────────────────────────────────────────────
// 5. Loops (for / foreach / each)
// ─────────────────────────────────────────────────────────
section('5. Loops');

parity('for...in loop', $engine, $cache,
    '{for item in items}{item} {/for}', ['items' => ['a', 'b', 'c']]);

parity('foreach loop', $engine, $cache,
    '{foreach items as item}{item},{/foreach}', ['items' => [1, 2, 3]]);

parity('each loop', $engine, $cache,
    '{each items as item}{item};{/each}', ['items' => ['x', 'y']]);

parity('loop with empty array', $engine, $cache,
    '{for item in items}{item}{else}empty{/for}', ['items' => []]);

parity('loop.first / loop.last', $engine, $cache,
    '{for item in items}{if loop.first}[{/if}{item}{if loop.last}]{/if}{/for}',
    ['items' => ['a', 'b', 'c']]);

parity('loop.index', $engine, $cache,
    '{for item in items}{loop.index}:{item} {/for}',
    ['items' => ['a', 'b', 'c']]);

parity('loop.length', $engine, $cache,
    '{for item in items}{loop.length}{/for}',
    ['items' => ['a', 'b', 'c']]);

parity('nested loops', $engine, $cache,
    '{for row in rows}{for cell in row}{cell}{/for}|{/for}',
    ['rows' => [['a', 'b'], ['c', 'd']]]);

parity('loop over object properties', $engine, $cache,
    '{for item in items}{item},{/for}',
    ['items' => ['x' => 10, 'y' => 20]]);

// ─────────────────────────────────────────────────────────
// 6. Set statements
// ─────────────────────────────────────────────────────────
section('6. Set statements');

parity('set a string', $engine, $cache,
    '{set greeting = "hello"}{greeting}', []);

parity('set a number', $engine, $cache,
    '{set x = 42}{x}', []);

parity('set from variable', $engine, $cache,
    '{set copy = original}{copy}', ['original' => 'value']);

// ─────────────────────────────────────────────────────────
// 7. Arithmetic & ternary expressions
// ─────────────────────────────────────────────────────────
section('7. Arithmetic & ternary');

parity('addition', $engine, $cache,
    '{a + b}', ['a' => 3, 'b' => 4]);

parity('subtraction', $engine, $cache,
    '{a - b}', ['a' => 10, 'b' => 3]);

parity('multiplication', $engine, $cache,
    '{a * b}', ['a' => 6, 'b' => 7]);

parity('division', $engine, $cache,
    '{a / b}', ['a' => 10, 'b' => 2]);

parity('modulo', $engine, $cache,
    '{a % b}', ['a' => 10, 'b' => 3]);

parity('division by zero safe', $engine, $cache,
    '{a / b}', ['a' => 10, 'b' => 0]);

// ─────────────────────────────────────────────────────────
// 8. Comments
// ─────────────────────────────────────────────────────────
section('8. Comments');

parity('bang-dash comment', $engine, $cache,
    'before{!-- hidden --}after', []);

parity('star comment', $engine, $cache,
    'before{* hidden *}after', []);

parity('comment with variable syntax inside', $engine, $cache,
    'ok{!-- {name} --}ok', ['name' => 'should not appear']);

// ─────────────────────────────────────────────────────────
// 9. Verbatim & literal blocks
// ─────────────────────────────────────────────────────────
section('9. Verbatim & literal blocks');

parity('verbatim preserves syntax', $engine, $cache,
    '{verbatim}{name} {if x}y{/if}{/verbatim}', ['name' => 'ignored']);

parity('literal preserves curlies', $engine, $cache,
    '{literal}function() { return {a: 1}; }{/literal}', []);

// ─────────────────────────────────────────────────────────
// 10. Mixed / nested structures
// ─────────────────────────────────────────────────────────
section('10. Mixed / nested structures');

parity('loop with conditional', $engine, $cache,
    '{for item in items}{if item > 2}{item}{/if}{/for}',
    ['items' => [1, 2, 3, 4]]);

parity('conditional with filter', $engine, $cache,
    '{if name|length > 3}long{else}short{/if}',
    ['name' => 'hello']);

parity('variable in loop with filter', $engine, $cache,
    '{for item in items}{item|upper} {/for}',
    ['items' => ['hello', 'world']]);

parity('set then use in condition', $engine, $cache,
    '{set flag = true}{if flag}yes{else}no{/if}', []);

parity('nested if inside if', $engine, $cache,
    '{if a}{if b}both{else}only-a{/if}{else}neither{/if}',
    ['a' => true, 'b' => false]);

// ─────────────────────────────────────────────────────────
// 11. Escaping edge cases
// ─────────────────────────────────────────────────────────
section('11. Escaping edge cases');

parity('ampersand escaping', $engine, $cache,
    '{val}', ['val' => 'a&b']);

parity('quote escaping', $engine, $cache,
    '{val}', ['val' => 'say "hello"']);

parity('single quote escaping', $engine, $cache,
    '{val}', ['val' => "it's"]);

parity('angle bracket escaping', $engine, $cache,
    '{val}', ['val' => '3 < 5 > 2']);

parity('XSS script escaping', $engine, $cache,
    '{val}', ['val' => '<script>alert("xss")</script>']);

// ─────────────────────────────────────────────────────────
// 12. Script/style safe output (compiled pipeline)
// ─────────────────────────────────────────────────────────
section('12. Script/style safe output (compiled pipeline)');

$rawValue = "A&B<C>D'E\"F";
$scriptEscape = static fn(string $value): string => strtr($value, [
    '\\' => '\\u005C', '<' => '\\u003C', '>' => '\\u003E',
    '&' => '\\u0026', "'" => '\\u0027', '"' => '\\u0022',
]);
$scriptEscapedValue = $scriptEscape($rawValue);

// Script/style values are escaped by default in both pipelines.
parity('script body emits script-escaped value', $engine, $cache,
    '<script>const value = "{value}";</script>', ['value' => $rawValue]);

parity('style body emits script-escaped value', $engine, $cache,
    '<style>.x { content: "{value}"; }</style>', ['value' => $rawValue]);

parity('script body escaped inside {if} true branch', $engine, $cache,
    '<script>{if flag}const value = "{value}";{else}const value = "none";{/if}</script>',
    ['value' => $rawValue, 'flag' => true]);

parity('script body escaped inside {if} false branch', $engine, $cache,
    '<script>{if flag}const value = "none";{else}const value = "{value}";{/if}</script>',
    ['value' => $rawValue, 'flag' => false]);

parity('style body escaped inside {if}', $engine, $cache,
    '<style>{if flag}.x { content: "{value}"; }{/if}</style>',
    ['value' => $rawValue, 'flag' => true]);

// Negative: implicit escaping must still apply outside script/style bodies.
parity('plain HTML expression remains escaped', $engine, $cache,
    '<div>{value}</div>', ['value' => $rawValue]);

parity('script opening-tag attribute remains escaped', $engine, $cache,
    '<script src="{value}/app.js"></script>', ['value' => 'a&b']);

parity('style opening-tag attribute remains escaped', $engine, $cache,
    '<style data-x="{value}">.x{color:red}</style>', ['value' => $rawValue]);

// Explicit filters keep their prior behavior.
parity('explicit raw filter unchanged in HTML', $engine, $cache,
    '<div>{value|raw}</div>', ['value' => $rawValue]);

parity('explicit esc_html filter unchanged in HTML', $engine, $cache,
    '<div>{value|esc_html}</div>', ['value' => $rawValue]);

parity('filter in script body is escaped by default', $engine, $cache,
    '<script>const value = "{value|upper}";</script>', ['value' => 'a&b']);

// Explicit escape/raw filters keep their meaning inside script/style bodies.
parity('explicit esc_html filter escapes in script body', $engine, $cache,
    '<script>const value = "{value|esc_html}";</script>', ['value' => $rawValue]);
parity('explicit esc_html filter escapes in style body', $engine, $cache,
    '<style>.x { content: "{value|esc_html}"; }</style>', ['value' => $rawValue]);
parity('explicit raw filter stays raw in script body', $engine, $cache,
    '<script>const value = "{value|raw}";</script>', ['value' => $rawValue]);
parity('explicit raw filter stays raw in style body', $engine, $cache,
    '<style>.x { content: "{value|raw}"; }</style>', ['value' => $rawValue]);

// JS/CSS braces must continue to survive script/style extraction.
parity('JS object braces preserved in script', $engine, $cache,
    '<script>const cfg = { label: "{value}", nested: { ok: true } };</script>', ['value' => $rawValue]);

parity('CSS rule braces preserved in style', $engine, $cache,
    '<style>body { color: red; } .x { content: "{value}"; }</style>', ['value' => $rawValue]);

// Inert content (comments / {verbatim}) must not be able to fabricate a
// script/style boundary: the interpreted engine strips those regions before
// extracting script/style bodies, so an expression between two inert pieces
// of markup is ordinary HTML and must stay escaped.
parity('comment fake script boundary does not suppress HTML escaping', $engine, $cache,
    '{!-- <script> --}<div>{value}</div>{!-- </script> --}', ['value' => $rawValue]);
parity('comment fake style boundary does not suppress HTML escaping', $engine, $cache,
    '{!-- <style> --}<div>{value}</div>{!-- </style> --}', ['value' => $rawValue]);
parity('verbatim fake script boundary does not suppress HTML escaping', $engine, $cache,
    '{verbatim}<script>{/verbatim}<div>{value}</div>{verbatim}</script>{/verbatim}', ['value' => $rawValue]);
parity('verbatim fake style boundary does not suppress HTML escaping', $engine, $cache,
    '{verbatim}<style>{/verbatim}<div>{value}</div>{verbatim}</style>{/verbatim}', ['value' => $rawValue]);

// Direct compiled-path assertions (do not rely on the interpreted engine).
$escapedValue = htmlspecialchars($rawValue, ENT_QUOTES, 'UTF-8');
check('compiled script body emits escaped bytes',
    '<script>const value = "' . $scriptEscapedValue . '";</script>',
    compiled($cache, '<script>const value = "{value}";</script>', ['value' => $rawValue], 'direct_script_raw'));

check('compiled script opening-tag attribute is escaped',
    '<script src="a&amp;b/app.js"></script>',
    compiled($cache, '<script src="{value}/app.js"></script>', ['value' => 'a&b'], 'direct_attr_escaped'));

check('compiled style opening-tag attribute is escaped',
    '<style data-x="' . $escapedValue . '">.x{color:red}</style>',
    compiled($cache, '<style data-x="{value}">.x{color:red}</style>', ['value' => $rawValue], 'direct_style_attr_escaped'));

check('compiled comment fake script boundary stays escaped',
    '<div>' . $escapedValue . '</div>',
    compiled($cache, '{!-- <script> --}<div>{value}</div>{!-- </script> --}', ['value' => $rawValue], 'direct_comment_fake_script'));

check('compiled comment fake style boundary stays escaped',
    '<div>' . $escapedValue . '</div>',
    compiled($cache, '{!-- <style> --}<div>{value}</div>{!-- </style> --}', ['value' => $rawValue], 'direct_comment_fake_style'));

check('compiled verbatim fake script boundary stays escaped',
    '<script><div>' . $escapedValue . '</div></script>',
    compiled($cache, '{verbatim}<script>{/verbatim}<div>{value}</div>{verbatim}</script>{/verbatim}', ['value' => $rawValue], 'direct_verbatim_fake_script'));

check('compiled verbatim fake style boundary stays escaped',
    '<style><div>' . $escapedValue . '</div></style>',
    compiled($cache, '{verbatim}<style>{/verbatim}<div>{value}</div>{verbatim}</style>{/verbatim}', ['value' => $rawValue], 'direct_verbatim_fake_style'));

// Nested output nodes under a loop must inherit script escaping.
check('compiled loop body in script emits escaped bytes',
    '<script>const v = "' . $scriptEscape('A&B') . '";const v = "' . $scriptEscape("C'D") . '";</script>',
    compiled($cache, '<script>{for item in items}const v = "{item}";{/for}</script>', ['items' => ['A&B', "C'D"]], 'direct_loop_raw'));

// Negative: a `>` inside a quoted opening-tag attribute must not be treated as
// the tag boundary, so an attribute expression stays escaped even though a body
// expression in the same tag is raw. Values include both quote characters.
check('compiled script attr with > keeps expression escaped',
    '<script data-x="x>' . $escapedValue . '">ok</script>',
    compiled($cache, '<script data-x="x>{value}">ok</script>', ['value' => $rawValue], 'direct_script_attr_gt'));
check('compiled style attr with > keeps expression escaped',
    "<style data-x='x>" . $escapedValue . "'>.a{color:red}</style>",
    compiled($cache, "<style data-x='x>{value}'>.a{color:red}</style>", ['value' => $rawValue], 'direct_style_attr_gt'));
check('compiled script attr with > still escapes body',
    '<script data-x="x>y">' . $scriptEscapedValue . '</script>',
    compiled($cache, '<script data-x="x>y">{value}</script>', ['value' => $rawValue], 'direct_script_attr_gt_body'));
check('compiled explicit esc_html filter escapes in script body',
    '<script>const value = "' . $escapedValue . '";</script>',
    compiled($cache, '<script>const value = "{value|esc_html}";</script>', ['value' => $rawValue], 'direct_script_esc_html'));
check('compiled explicit esc_html filter escapes in style body',
    '<style>.x { content: "' . $escapedValue . '"; }</style>',
    compiled($cache, '<style>.x { content: "{value|esc_html}"; }</style>', ['value' => $rawValue], 'direct_style_esc_html'));
check('compiled explicit raw filter stays raw in script body',
    '<script>const value = "' . $rawValue . '";</script>',
    compiled($cache, '<script>const value = "{value|raw}";</script>', ['value' => $rawValue], 'direct_script_raw_filter'));

// ─────────────────────────────────────────────────────────
// 13. E2E compiled-mode render() through TemplateEngine
// ─────────────────────────────────────────────────────────
section('13. E2E TemplateEngine::render() compiled fast path');

$e2eTemplateDir = $tmpDir . '/e2e_templates';
$e2eCacheDir = $tmpDir . '/e2e_cache';
@mkdir($e2eTemplateDir, 0755, true);
@mkdir($e2eCacheDir . '/compiled', 0755, true);

$e2eSource = '<script>const value = "{value}";</script>'
    . '<div>{value}</div>'
    . '<script src="{base}/app.js"></script>'
    . '<style>.x { content: "{value}"; }</style>';
file_put_contents($e2eTemplateDir . '/script_raw.disyl', $e2eSource);

$e2eCtx = ['value' => $rawValue, 'base' => 'a&b'];

$e2eCompiledEngine = new TemplateEngine($e2eTemplateDir, $e2eCacheDir);
$e2eCompiledEngine->enableCompiledMode(true);
$e2eCompiledOut = $e2eCompiledEngine->render('script_raw', $e2eCtx);

$e2eInterpretedEngine = new TemplateEngine($e2eTemplateDir, $e2eCacheDir . '/interp');
$e2eInterpretedEngine->enableCompiledMode(false);
$e2eInterpretedOut = $e2eInterpretedEngine->render('script_raw', $e2eCtx);

check('e2e compiled render matches interpreted', $e2eInterpretedOut, $e2eCompiledOut);
check('e2e compiled render is active', 'yes', $e2eCompiledEngine->isCompiledMode() ? 'yes' : 'no');
check('e2e compiled cache file written', 'yes',
    glob($e2eCacheDir . '/compiled/Template_*.php') ? 'yes' : 'no');

$fallbackErrors = array_values(array_filter(
    $e2eCompiledEngine->getErrors(),
    static fn(string $e): bool => stripos($e, 'Compiled render failed') !== false
));
check('e2e compiled render without fallback errors', 'none', $fallbackErrors === [] ? 'none' : implode(' | ', $fallbackErrors));

check('e2e script body escaped bytes survived render()',
    'yes',
    str_contains($e2eCompiledOut, '<script>const value = "' . $scriptEscapedValue . '";</script>') ? 'yes' : 'no');

check('e2e style body escaped bytes survived render()',
    'yes',
    str_contains($e2eCompiledOut, '<style>.x { content: "' . $scriptEscapedValue . '"; }</style>') ? 'yes' : 'no');

check('e2e surrounding HTML expression stayed escaped',
    'yes',
    str_contains($e2eCompiledOut, '<div>' . $escapedValue . '</div>') ? 'yes' : 'no');

check('e2e opening-tag attribute stayed escaped',
    'yes',
    str_contains($e2eCompiledOut, '<script src="a&amp;b/app.js"></script>') ? 'yes' : 'no');

$e2eExpected = '<script>const value = "' . $scriptEscapedValue . '";</script>'
    . '<div>' . $escapedValue . '</div>'
    . '<script src="a&amp;b/app.js"></script>'
    . '<style>.x { content: "' . $scriptEscapedValue . '"; }</style>';
check('e2e compiled render equals explicit expected output', $e2eExpected, $e2eCompiledOut);

// Negative e2e: a `>` inside a quoted opening-tag attribute must not flip the
// attribute expression to script output. Both script and style tags, with
// values that contain both quote characters, verify entity escaping in the
// attribute while the adjacent body expression uses script escaping.
$e2eAttrSource = '<script data-x="x>{value}">const v = "{value}";</script>'
    . "<style data-x='x>{value}'>.x { content: \"{value}\"; }</style>"
    . '<div>{value}</div>';
file_put_contents($e2eTemplateDir . '/script_attr_gt.disyl', $e2eAttrSource);

$e2eAttrEngine = new TemplateEngine($e2eTemplateDir, $e2eCacheDir);
$e2eAttrEngine->enableCompiledMode(true);
$e2eAttrOut = $e2eAttrEngine->render('script_attr_gt', ['value' => $rawValue]);

$e2eAttrExpected = '<script data-x="x>' . $escapedValue . '">const v = "' . $scriptEscapedValue . '";</script>'
    . "<style data-x='x>" . $escapedValue . "'>.x { content: \"" . $scriptEscapedValue . "\"; }</style>"
    . '<div>' . $escapedValue . '</div>';
check('e2e attr with > stays escaped while body is script-escaped', $e2eAttrExpected, $e2eAttrOut);

$e2eAttrFallback = array_values(array_filter(
    $e2eAttrEngine->getErrors(),
    static fn(string $e): bool => stripos($e, 'Compiled render failed') !== false
));
check('e2e attr with > render has no fallback errors', 'none',
    $e2eAttrFallback === [] ? 'none' : implode(' | ', $e2eAttrFallback));

// ─────────────────────────────────────────────────────────
// 14. compileSource cache identity + cleanup versioning
// ─────────────────────────────────────────────────────────
section('14. compileSource cache identity + cleanup versioning');

$cleanupDir = $tmpDir . '/cleanup_cache';
$cleanupCache = new TemplateCache($cleanupDir, true);
$cleanupSource = '<script>const value = "{value}";</script>';
$cleanupVersion = \Ikabud\Kernel\DiSyL\Compiler\TemplateCompiler::COMPILER_VERSION;

check('compiled source executes before cleanup',
    '<script>const value = "' . $scriptEscape('A&B') . '";</script>',
    $cleanupCache->compileSource($cleanupSource, 'cleanup_probe')->execute(['value' => 'A&B']));

$cleanupFiles = glob($cleanupDir . '/Template_cleanup_probe_*.php') ?: [];
check('source cache file exists after compileSource', 'yes', $cleanupFiles !== [] ? 'yes' : 'no');

$cleanupHasVersionTag = $cleanupFiles !== []
    && str_contains(basename($cleanupFiles[0]), '_v' . $cleanupVersion . '_');
check('source cache filename carries current compiler version tag', 'yes',
    $cleanupHasVersionTag ? 'yes' : 'no');

$staleCachePath = $cleanupDir . '/Template_cleanup_probe_v' . ($cleanupVersion - 1) . '_deadbeef.php';
file_put_contents($staleCachePath, "<?php\n// stale old-version cache\n");

$cleanupRemoved = $cleanupCache->cleanup(0);
check('cleanup removes stale-version cache', 'yes', is_file($staleCachePath) ? 'no' : 'yes');
check('cleanup removes only the stale-version file', '1', (string)$cleanupRemoved);
check('cleanup retains current-version source cache', 'yes',
    $cleanupFiles !== [] && is_file($cleanupFiles[0]) ? 'yes' : 'no');

// A fresh cache instance re-resolves the source after cleanup; the retained
// current-version file must still render the correct output.
$cleanupCacheAfter = new TemplateCache($cleanupDir, true);
check('current-version source cache remains usable after cleanup',
    '<script>const value = "' . $scriptEscape('A&B') . '";</script>',
    $cleanupCacheAfter->compileSource($cleanupSource, 'cleanup_probe')->execute(['value' => 'A&B']));

// ─────────────────────────────────────────────────────────
// 15. Synthetic {extends} inheritance parity
// ─────────────────────────────────────────────────────────
section('15. Synthetic {extends} inheritance parity');

$singleLayout = 'L[{block "body"}layout default{/block}]';
$singleChild = '{extends "extends_single_layout"}{block "body"}child{/block}';
writeParityTemplates(['extends_single_layout' => $singleLayout]);
check('extends reference: single override wins', 'L[child]',
    interpreted($engine, $singleChild, []));
parity('extends single-level one overridden block', $engine, $cache,
    $singleChild, [], ['extends_single_layout' => $singleLayout]);

$severalLayout = 'H:{block "head"}head default{/block}|B:{block "body"}body default{/block}|F:{block "foot"}foot default{/block}';
$severalChild = '{extends "extends_several_layout"}{block "head"}child head{/block}{block "foot"}child foot{/block}';
writeParityTemplates(['extends_several_layout' => $severalLayout]);
check('extends reference: overridden and default blocks',
    'H:child head|B:body default|F:child foot',
    interpreted($engine, $severalChild, []));
parity('extends several blocks preserve unoverridden default', $engine, $cache,
    $severalChild, [], ['extends_several_layout' => $severalLayout]);

$multiRoot = 'H:{block "head"}root head{/block}|B:{block "body"}root body{/block}|F:{block "foot"}root foot{/block}';
$multiMiddle = '{extends "extends_multi_root"}{block "body"}middle body{/block}{block "foot"}middle foot{/block}';
$multiChild = '{extends "extends_multi_middle"}{block "body"}child body{/block}';
writeParityTemplates([
    'extends_multi_middle' => $multiMiddle,
    'extends_multi_root' => $multiRoot,
]);
check('extends reference: nearest definitions win across levels',
    'H:root head|B:child body|F:middle foot',
    interpreted($engine, $multiChild, []));
parity('extends multi-level nearest-safe-ancestor preservation', $engine, $cache,
    $multiChild, [], [
        'extends_multi_middle' => $multiMiddle,
        'extends_multi_root' => $multiRoot,
    ]);

$logicLayout = 'Result:{block "body"}none{/block}';
$logicChild = '{extends "extends_logic_layout"}{block "body"}{if show}{name|upper}:{for item in items}{item|upper},{/for}{else}hidden{/if}{/block}';
writeParityTemplates(['extends_logic_layout' => $logicLayout]);
check('extends reference: override evaluates control flow variables and filters',
    'Result:ADA:A,B,',
    interpreted($engine, $logicChild, ['show' => true, 'name' => 'ada', 'items' => ['a', 'b']]));
parity('extends override with control flow variables and filters', $engine, $cache,
    $logicChild, ['show' => true, 'name' => 'ada', 'items' => ['a', 'b']],
    ['extends_logic_layout' => $logicLayout]);

$defaultLayout = 'A:{block "a"}layout A{/block}|B:{block "b"}layout B{/block}';
$defaultChild = '{extends "extends_default_layout"}{block "a"}child A{/block}';
writeParityTemplates(['extends_default_layout' => $defaultLayout]);
check('extends reference: never-overridden layout block renders',
    'A:child A|B:layout B', interpreted($engine, $defaultChild, []));
parity('extends layout block never overridden', $engine, $cache,
    $defaultChild, [], ['extends_default_layout' => $defaultLayout]);

$cycleA = '{extends "extends_cycle_b"}A';
$cycleB = '{extends "extends_cycle_a"}B';
parity('extends cycle A to B to A', $engine, $cache,
    $cycleA, [], [
        'extends_cycle_a' => $cycleA,
        'extends_cycle_b' => $cycleB,
    ], true);

$selfExtends = '{extends "extends_self"}SELF';
parity('extends self-cycle', $engine, $cache,
    $selfExtends, [], ['extends_self' => $selfExtends], true);

$deepDependencies = [];
for ($i = 1; $i <= 21; $i++) {
    $name = sprintf('extends_depth_%02d', $i);
    $deepDependencies[$name] = $i === 21
        ? 'DEPTH-21'
        : '{extends "' . sprintf('extends_depth_%02d', $i + 1) . '"}DEPTH-' . $i;
}
$deepChild = '{extends "extends_depth_01"}DEPTH-00';
parity('extends chain exercises depth limit', $engine, $cache,
    $deepChild, [], $deepDependencies, true);

$missingChild = '{extends "extends_parent_missing"}{block "body"}orphan body{/block}';
parity('extends missing parent', $engine, $cache,
    $missingChild, [], [], true);

$includeLayout = '<layout>{block "body"}layout default{/block}</layout>';
$includedChild = '{extends "extends_include_layout"}{block "body"}included child{/block}';
$includeSource = 'before:{include "extends_included_child"}:after';
writeParityTemplates([
    'extends_included_child' => $includedChild,
    'extends_include_layout' => $includeLayout,
]);
check('extends reference: inheritance inside included file is allowed',
    'before:<layout>included child</layout>:after',
    interpreted($engine, $includeSource, []));
parity('extends inside included file', $engine, $cache,
    $includeSource, [], [
        'extends_included_child' => $includedChild,
        'extends_include_layout' => $includeLayout,
    ]);

// ─────────────────────────────────────────────────────────
// Summary
// ─────────────────────────────────────────────────────────

// Close last section
if ($current_section && ($section_pass + $section_fail + $section_known > 0)) {
    $total = $section_pass + $section_fail + $section_known;
    $extra = $section_known > 0 ? ", {$section_known} known" : '';
    echo "   ({$section_pass}/{$total} passed{$extra})\n\n";
}

echo "════════════════════════════════════════════════════════════\n";
$total = $pass + $fail + $known_divergences;
echo "PARITY RESULTS: {$pass}/{$total} passed";
if ($known_divergences > 0) {
    echo ", {$known_divergences} known divergence(s)";
}
if ($fail > 0) {
    echo " — {$fail} NEW DIVERGENCE(S) DETECTED";
}
echo "\n";
echo "════════════════════════════════════════════════════════════\n";

// Cleanup temp dir
$cleanFiles = glob($tmpDir . '/cache/compiled/*.php');
foreach ($cleanFiles ?: [] as $f) { @unlink($f); }
@rmdir($tmpDir . '/cache/compiled');
@rmdir($tmpDir . '/cache');
@rmdir($tmpDir . '/templates');
@rmdir($tmpDir);

$cleanE2e = glob($e2eCacheDir . '/compiled/*.php');
foreach ($cleanE2e ?: [] as $f) { @unlink($f); }
$cleanE2eInterp = glob($e2eCacheDir . '/interp/compiled/*.php');
foreach ($cleanE2eInterp ?: [] as $f) { @unlink($f); }
$cleanE2eElig = glob($e2eCacheDir . '/disyl-extends/*.json');
foreach ($cleanE2eElig ?: [] as $f) { @unlink($f); }
@unlink($e2eTemplateDir . '/script_raw.disyl');
@unlink($e2eTemplateDir . '/script_attr_gt.disyl');
@rmdir($e2eCacheDir . '/compiled');
@rmdir($e2eCacheDir . '/interp/compiled');
@rmdir($e2eCacheDir . '/interp');
@rmdir($e2eCacheDir . '/disyl-extends');
@rmdir($e2eCacheDir);
@rmdir($e2eTemplateDir);
@rmdir($tmpDir);

$cleanCleanupCache = glob($cleanupDir . '/*.php');
foreach ($cleanCleanupCache ?: [] as $f) { @unlink($f); }
@rmdir($cleanupDir);

// Remove any inheritance fixtures and lock/cache files left under the suite's
// process-specific temp root.
$removeTree = static function (string $path) use (&$removeTree): void {
    if (!is_dir($path)) {
        @unlink($path);
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            $removeTree($path . '/' . $entry);
        }
    }
    @rmdir($path);
};
$removeTree($tmpDir);

exit($fail > 0 ? 1 : 0);  // known divergences don't fail the suite
