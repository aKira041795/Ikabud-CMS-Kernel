<?php
declare(strict_types=1);

/**
 * MINIMAL REPRODUCTION — CSS inside <style> is parsed as a DiSyL expression.
 *
 * Mechanism, read out of the generated artifact for the smallest failing template
 * (modules/harpp/runners.disyl, 1119 B). Template_runners_v16_b940fd95.php generated:
 *
 *   $output .= (string)(('display:flex;flex' - 'direction:column;gap:.5rem'));
 *   $output .= (string)((((('display:inline' - 'block;border') - 'radius:2px;...') - 'size:.75rem') ...
 *
 * The hyphens in CSS PROPERTY NAMES are being turned into DiSyL SUBTRACTION operators. PHP then throws
 * "Unsupported operand types: string - string", the compiled render fails, and the engine silently
 * re-renders on the interpreted pipeline at roughly 10x the cost.
 *
 * The same class explains all 7 fallbacks: runners/deploy/settings (string - string) are CSS hyphens,
 * and theme-customizer (string + null) is JavaScript concatenation. Both are operator characters in a
 * non-DiSyL context being evaluated as DiSyL.
 *
 * This file finds the SMALLEST form that reproduces, because the fix has to be aimed at the real trigger
 * and not at a symptom. Case 3 is the control: a <style> rule with no hyphen must keep working.
 *
 *   php docs/reviews/perf-metrics-2026-10-10/probe-css-minimal.php
 */

require __DIR__ . '/../../../bootstrap.php';

use Ikabud\Kernel\DiSyL\TemplateEngine;

$tmp = rtrim(sys_get_temp_dir(), '/') . '/ikabud-css-minimal';
@mkdir($tmp, 0777, true);
@mkdir($tmp . '/cache', 0777, true);

// A minimal parent so {block} inheritance can be exercised: the real failing template puts its
// <style> inside {block head}, and isolated <style> bodies are protected. This isolates whether the
// {block} wrapper is what bypasses that protection.
file_put_contents(
    $tmp . '/_layout.disyl',
    '<html><head>{block head}{/block}</head><body>{block content}{/block}</body></html>'
);

$cases = [
    'BLOCK+EXTENDS: styled head, hyphenated property' =>
        '{extends "_layout.disyl"}{block head}<style>.x{flex-direction:column}</style>{/block}'
        . '{block content}<p>ok</p>{/block}',

    'BLOCK+EXTENDS: CONTROL, no hyphen in style' =>
        '{extends "_layout.disyl"}{block head}<style>.x{color:red}</style>{/block}'
        . '{block content}<p>ok</p>{/block}',

    'BLOCK+EXTENDS: CONTROL, hyphenated text in content' =>
        '{extends "_layout.disyl"}{block head}{/block}'
        . '{block content}<p>some-hyphenated-text</p>{/block}',

    'no extends, style body with hyphenated property' =>
        '<style>.x{flex-direction:column}</style>',

    'CSS rule braces, bare hyphenated words' =>
        '<style>.x{a-b}</style>',

    'CONTROL: plain markup, hyphenated text' =>
        '<div class="x">some-hyphenated-text</div>',
];

echo "Minimal reproduction hunt — CSS hyphens evaluated as subtraction" . PHP_EOL;
echo str_repeat('-', 104) . PHP_EOL;

foreach ($cases as $label => $src) {
    $name = 'case_' . substr(md5($label), 0, 8) . '.disyl';
    file_put_contents($tmp . '/' . $name, $src);

    $engine = new TemplateEngine($tmp, $tmp . '/cache', true);
    $engine->enableCompiledMode(true);

    $verdict = 'ok';
    $out = '';
    try {
        $out = $engine->render($name, []);
    } catch (Throwable $e) {
        $verdict = 'THREW';
    }

    $bad = preg_match('/\'\s*-\s*\'/', $out) === 1 || preg_match('/js-|" - "|\' - \'/', $out) === 1;
    printf("%-52s %-8s %s%s\n", $label, $verdict, substr(str_replace("\n", ' ', $out), 0, 44), $bad ? '  << SUBTRACTION' : '');
}

echo PHP_EOL . 'A case that THREWs or shows a subtraction between CSS fragments is the minimal' . PHP_EOL;
echo 'reproduction. The controls (3, 4, 7) must stay ok - if a control breaks, the probe is wrong,' . PHP_EOL;
echo 'not the engine, and any conclusion drawn from it would be worthless.' . PHP_EOL;
