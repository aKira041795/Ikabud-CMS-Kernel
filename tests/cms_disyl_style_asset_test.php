<?php
declare(strict_types=1);

require_once __DIR__ . '/../kernel/DiSyL/TemplateEngine.php';

use Ikabud\Kernel\DiSyL\TemplateEngine;

$fixtureDir = sys_get_temp_dir() . '/cms_disyl_style_asset_' . getmypid();
$cacheDir = $fixtureDir . '/cache';
@mkdir($cacheDir, 0755, true);
file_put_contents($fixtureDir . '/style-fragment.disyl', '.included { color: {tone}; }');

$engine = new TemplateEngine($fixtureDir, $cacheDir, false);
$passed = 0;
$failed = 0;

$assertSame = static function (string $label, string $expected, string $actual) use (&$passed, &$failed): void {
    if ($actual === $expected) {
        $passed++;
        echo "PASS: {$label}\n";
        return;
    }

    $failed++;
    echo "FAIL: {$label}\n";
    echo '  expected: ' . json_encode($expected, JSON_UNESCAPED_SLASHES) . "\n";
    echo '  actual:   ' . json_encode($actual, JSON_UNESCAPED_SLASHES) . "\n";
};

$pureCssBody = str_repeat(".native-storefront{color:red;background:#fff}\n", 600);
$pureCssTemplate = '<style>' . $pureCssBody . '</style>';
$assertSame('pure CSS style body is byte-identical', $pureCssTemplate, $engine->renderString($pureCssTemplate));

$iterations = 25;
$startedAt = hrtime(true);
for ($i = 0; $i < $iterations; $i++) {
    $output = $engine->renderString($pureCssTemplate);
    if ($output !== $pureCssTemplate) {
        $failed++;
        echo "FAIL: timed pure CSS output changed\n";
        break;
    }
}
$millisecondsPerCall = ((hrtime(true) - $startedAt) / 1_000_000) / $iterations;
echo sprintf(
    "ENGINE_SPEED: bytes=%d iterations=%d baseline_ms_per_call=113.47 after_ms_per_call=%.3f threshold_ms=10.00\n",
    strlen($pureCssBody),
    $iterations,
    $millisecondsPerCall
);
if ($millisecondsPerCall < 10.0) {
    $passed++;
    echo "PASS: engine speed is below 10 ms/call\n";
} else {
    $failed++;
    echo "FAIL: engine speed is not below 10 ms/call\n";
}

$assertSame(
    'style if/else interpolation',
    '<style>.flag { color: green; }</style>',
    $engine->renderString('<style>{if enabled}.flag { color: green; }{else}.flag { color: red; }{/if}</style>', ['enabled' => true])
);
$assertSame(
    'style set and variable interpolation',
    '<style>.set { color: blue; }</style>',
    $engine->renderString('<style>{set shade = "blue"}.set { color: {shade}; }</style>')
);
$assertSame(
    'style include interpolation',
    '<style>.included { color: purple; }</style>',
    $engine->renderString('<style>{include "style-fragment.disyl"}</style>', ['tone' => 'purple'])
);
$assertSame(
    'script variable and filter interpolation',
    '<script>const plain = "Ada"; const filtered = "ADA";</script>',
    $engine->renderString('<script>const plain = "{name}"; const filtered = "{name|upper}";</script>', ['name' => 'Ada'])
);
$assertSame(
    'script null-coalescing fallback interpolation',
    '<script>const count = 0;</script>',
    $engine->renderString('<script>const count = {missing ?? 0};</script>')
);

// New coverage: processScriptVariables() passes 2 (ternary) and 3 (arithmetic)
// must resolve inside <script>/<style> bodies, not only at the top level.
$newAssertions = 0;

// A. Pass 3 (arithmetic) resolves inside a script body.
$newAssertions++;
$assertSame(
    'script arithmetic interpolation',
    '<script>var t=4;</script>',
    $engine->renderString('<script>var t={count + 1};</script>', ['count' => 3])
);

// A. Pass 3 (arithmetic) resolves inside a style body.
$newAssertions++;
$assertSame(
    'style arithmetic interpolation',
    '<style>.a{color:red}15</style>',
    $engine->renderString('<style>.a{color:red}{w + 10}</style>', ['w' => 5])
);

// B. Pass 2 (ternary) resolves inside a script body.
$newAssertions++;
$assertSame(
    'script ternary interpolation',
    '<script>var t=1;</script>',
    $engine->renderString('<script>var t={flag ? 1 : 0};</script>', ['flag' => true])
);

// C. Pre-existing engine limitation: a DiSyL tag nested inside a CSS declaration
//    block is unresolved on the pre-change engine as well (the style protector treats
//    the outer `{width:` as a DiSyL opening). A future fix is expected to change this test.
$newAssertions++;
$assertSame(
    'style nested declaration limitation is unchanged',
    '<style>.a{width:{w + 10}px}</style>',
    $engine->renderString('<style>.a{width:{w + 10}px}</style>', ['w' => 5])
);

// C. Pre-existing engine limitation: `{$var}` in a script body is unresolved on the
//    pre-change engine as well (pass 4 uses `(?<!\$)` with a capture that cannot start
//    with `$`). A future fix is expected to change this test.
$newAssertions++;
$assertSame(
    'script dollar-prefixed variable limitation is unchanged',
    '<script>var t={$v};</script>',
    $engine->renderString('<script>var t={$v};</script>', ['v' => 99])
);

// E. Fast-path correctness precondition: a body containing none of the pipeline's
//    constructs is returned byte-identical even when it is CSS that could look templated.
$newAssertions++;
$assertSame(
    'construct-free style body is byte-identical',
    '<style>.a{color:red;background:#fff}</style>',
    $engine->renderString('<style>.a{color:red;background:#fff}</style>')
);

echo "NEW_ASSERTIONS: {$newAssertions}\n";

@unlink($fixtureDir . '/style-fragment.disyl');
@rmdir($cacheDir);
@rmdir($fixtureDir);

echo "SUMMARY: passed={$passed} failed={$failed}\n";
exit($failed === 0 ? 0 : 1);
