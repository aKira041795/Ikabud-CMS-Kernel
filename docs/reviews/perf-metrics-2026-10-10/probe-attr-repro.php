<?php
declare(strict_types=1);

/**
 * Minimal-reproduction hunt for the theme-customizer compiled-render failure.
 *
 * Observed: modules/cms/admin/theme-customizer.disyl (371 KB) throws on the compiled path with
 * "Unsupported operand types: string + null" and falls back to the interpreted pipeline (987.85 ms).
 * The generated PHP showed JavaScript from an Alpine @input attribute compiled as DiSyL arithmetic,
 * e.g.  'rgba(' + $ctx->get('r') .
 *
 * NOT established: WHY the compiler compiled it. Candidate source lines are 1605, 1607, 1612 and 2024.
 * 1605/1612/2024 carry no braces at all; 1607 contains {6} inside a JS regex AND a {let c=...;} block.
 * A {...} scanner should not touch a brace-less attribute, so the trigger is not obvious and must be
 * measured rather than guessed.
 *
 * Method: render each candidate form as its own tiny template through the compiled path and report
 * whether it throws or falls back. Whichever form fails is the minimal reproduction.
 *
 * Read-only with respect to the repo: writes only into a temp dir it creates.
 *
 *   php docs/reviews/perf-metrics-2026-10-10/probe-attr-repro.php
 */

require __DIR__ . '/../../../bootstrap.php';

use Ikabud\Kernel\DiSyL\TemplateEngine;

$tmp = rtrim(sys_get_temp_dir(), '/') . '/ikabud-attr-repro';
@mkdir($tmp, 0777, true);
@mkdir($tmp . '/cache', 0777, true);

// Verified against the 2026-10-10 measurements: the escape() wrapper, attribute quoting, and the
// JS identifiers are the ones in the generated artifact.
$cases = [
    'A: bare + in attribute, no braces' =>
        '<div><input x-model="_mobileBgHex" @input="let c=_mobileBgHex;let r=parseInt(c.slice(1,3),16);headerSettings.mobile_bg_color=\'rgba(\'+r+\')\';">',

    'B: + in attribute inside a JS brace block' =>
        '<div><input @input="if(/^#[0-9a-fA-F]{6}$/.test(_mobileBgHex)){let c=_mobileBgHex;let r=parseInt(c.slice(1,3),16);headerSettings.mobile_bg_color=\'rgba(\'+r+\')\';}">',

    'C: {6} regex quantifier only, no JS block' =>
        '<div><input @input="if(/^#[0-9a-fA-F]{6}$/.test(_mobileBgHex)){return 1;}">',

    'D: braces in a NON-script attribute' =>
        '<div><span data-x="{ let c=1; let r=\'rgba(\'+r+\')\'; }"></span>',

    'E: the real 1605 attribute, full width' =>
        '<div><input type="color" class="cz-color-input" x-model="_mobileBgHex" @input="let c=_mobileBgHex;let r=parseInt(c.slice(1,3),16),g=parseInt(c.slice(3,5),16),b=parseInt(c.slice(5,7),16);headerSettings.mobile_bg_color=\'rgba(\'+r+\',\'+g+\',\'+b+\',\'+(_mobileBgOpacity/100)+\')\';">',

    'F: control — plain diSYL tag, must still compile' =>
        '<div><span>{known}</span></div>',
];

echo "Attribute-context reproduction hunt — PHP " . PHP_VERSION . PHP_EOL;
echo str_repeat('-', 100) . PHP_EOL;

foreach ($cases as $label => $src) {
    $name = 'case_' . substr(md5($label), 0, 8) . '.disyl';
    file_put_contents($tmp . '/' . $name, $src);

    $engine = new TemplateEngine($tmp, $tmp . '/cache', true);
    $engine->enableCompiledMode(true);

    $verdict = 'ok';
    $out = '';
    try {
        $out = $engine->render($name, ['known' => 'K']);
    } catch (Throwable $e) {
        $verdict = 'THREW: ' . substr($e->getMessage(), 0, 60);
    }

    // Shapes that indicate the compiler consumed the attribute as an expression rather than
    // leaving it as markup: a generated $ctx->get( for a JS identifier, or arithmetic concatenation.
    $signs = [];
    if (str_contains($out, 'rgba(') && !str_contains($out, '\'rgba(\'+')) {
        $signs[] = 'attribute was transformed';
    }
    if (preg_match('/ctx->get\(\'(r|g|b|_mobileBgOpacity)\'\)/', $out)) {
        $signs[] = 'JS identifier became a context lookup';
    }

    printf("%-46s %-34s %s\n", $label, $verdict, $signs ? '<< ' . implode(', ', $signs) : '');
    if (str_contains($verdict, 'THREW')) {
        printf("%-46s output: %s\n", '', substr(str_replace("\n", ' ', $out), 0, 90));
    }
}

echo PHP_EOL . 'The case that THREWs (or shows a $ctx->get for a JS identifier) is the minimal' . PHP_EOL;
echo 'reproduction. If none do, the trigger is context-dependent and needs the surrounding document,' . PHP_EOL;
echo 'which is itself a finding: it would mean the bug needs more than one construct to appear.' . PHP_EOL;
