<?php

declare(strict_types=1);

/**
 * Theme-customizer DiSyL regression test.
 *
 * Regression: JS statement blocks ({let ...}, {const ...}, {var ...}) inside
 * Alpine @input/@click attribute handlers were stripped to '' by the
 * interpreted DiSyL pipeline when the block contained arithmetic or function
 * calls (e.g. {let c=_mobileBgHex;...parseInt(...)...}). The stripped handler
 * left dangling `if(...)` JS that Alpine rejected with "Unexpected token '}'".
 *
 * Fixed at engine level in TemplateEngine::isProcessableTemplateExpression()
 * (JS statement keyword guard). This test proves the actual admin
 * theme-customizer template still carries its full JS handlers through the
 * interpreted pipeline.
 */

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../modules/cms/helpers.php';
require_once __DIR__ . '/../modules/cms/handlers.php';

$pass = 0;
$fail = 0;
$errors = [];

function t(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $errors;
    if ($ok) {
        $pass++;
        echo "  ✓ {$label}\n";
    } else {
        $fail++;
        $errors[] = $label . ($detail !== '' ? ': ' . $detail : '');
        echo "  ✗ {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

file_put_contents(STORAGE_PATH . '/logs/app.log', '');
file_put_contents(STORAGE_PATH . '/logs/error.log', '');

$templatePath = BASE_PATH . '/templates/modules/cms/admin/theme-customizer.disyl';
$source = file_get_contents($templatePath) ?: '';
$engine = app()->templates();

echo "\n=== THEME-EDITOR DISYL JS HANDLER CONTRACT ===\n";

// 1. Source contract: the template must carry the full JS handlers
t(
    'source has mobile bg hex @input with full let/parseInt handler',
    str_contains($source, "@input=\"if(/^#[0-9a-fA-F]{6}$/.test(_mobileBgHex)){let c=_mobileBgHex;let r=parseInt(c.slice(1,3),16),g=parseInt(c.slice(3,5),16),b=parseInt(c.slice(5,7),16);headerSettings.mobile_bg_color='rgba('+r+','+g+','+b+','+(_mobileBgOpacity/100)+')';}\"")
        && str_contains($source, '@input="let c=_mobileBgHex;let r=parseInt(c.slice(1,3),16),g=parseInt(c.slice(3,5),16),b=parseInt(c.slice(5,7),16);headerSettings.mobile_bg_color=\'rgba(\'+r+\',\'+g+\',\'+b+\',\'+(_mobileBgOpacity/100)+\')\';"'),
    substr($source, 0, 200)
);
t(
    'source has mobile bg opacity @input with fallback let handler',
    str_contains($source, '@input="let c=_mobileBgHex||\'#ffffff\';let r=parseInt(c.slice(1,3),16),g=parseInt(c.slice(3,5),16),b=parseInt(c.slice(5,7),16);headerSettings.mobile_bg_color=\'rgba(\'+r+\',\'+g+\',\'+b+\',\'+(_mobileBgOpacity/100)+\')\';"'),
    'opacity handler missing'
);

// 2. Render contract: run the exact handler fragments through the interpreted
//    pipeline (the path theme-customizer uses — it is NOT compiled-eligible)
$samples = [
    'mobile hex input @input',
    '<input type="text" @input="if(/^#[0-9a-fA-F]{6}$/.test(_mobileBgHex)){let c=_mobileBgHex;let r=parseInt(c.slice(1,3),16),g=parseInt(c.slice(3,5),16),b=parseInt(c.slice(5,7),16);headerSettings.mobile_bg_color=\'rgba(\'+r+\',\'+g+\',\'+b+\',\'+(_mobileBgOpacity/100)+\')\';}">',
    'mobile color input @input',
    '<input type="color" @input="let c=_mobileBgHex;let r=parseInt(c.slice(1,3),16),g=parseInt(c.slice(3,5),16),b=parseInt(c.slice(5,7),16);headerSettings.mobile_bg_color=\'rgba(\'+r+\',\'+g+\',\'+b+\',\'+(_mobileBgOpacity/100)+\')\';">',
    'opacity range @input with fallback',
    '<input type="range" @input="let c=_mobileBgHex||\'#ffffff\';let r=parseInt(c.slice(1,3),16),g=parseInt(c.slice(3,5),16),b=parseInt(c.slice(5,7),16);headerSettings.mobile_bg_color=\'rgba(\'+r+\',\'+g+\',\'+b+\',\'+(_mobileBgOpacity/100)+\')\';">',
];

foreach (array_chunk($samples, 2) as $chunk) {
    [$label, $fragment] = $chunk;
    $rendered = $engine->renderString($fragment, []);
    t(
        'interpreted render preserves ' . $label . ' JS let block',
        str_contains($rendered, 'let c=_mobileBgHex')
            && str_contains($rendered, 'parseInt(c.slice(1,3),16)')
            && str_contains($rendered, "headerSettings.mobile_bg_color='rgba('")
            && str_contains($rendered, '_mobileBgOpacity/100'),
        $rendered
    );
}

// 3. Ensure no stray DiSyL errors were emitted for the samples
$engineErrors = $engine->getErrors();
t(
    'no DiSyL errors emitted during JS handler render',
    empty($engineErrors),
    implode(' | ', $engineErrors)
);

// 4. Full-file render (interpreted path, real template source) must not crash
//    and must not emit critical engine errors. Full context is not available
//    here, but renderString on the raw source exercises the exact pipeline.
$fullRendered = $engine->renderString($source, [
    'customizer_scope' => 'site',
    'customizer_scope_base' => 'site',
    'customizer_title' => 'Theme Customizer',
    'customizer_intro' => '',
    'customizer_workspaces' => [],
    'customizer_notice' => '',
]);
t(
    'full theme-customizer source renders without engine exception',
    is_string($fullRendered) && $fullRendered !== '',
    substr((string)$fullRendered, 0, 300)
);

// Log noise check: no new critical/error entries from this test pass
$criticalLines = [];
$unexpectedErrorLines = [];
$appLog = @file_get_contents(STORAGE_PATH . '/logs/app.log') ?: '';
foreach (explode("\n", $appLog) as $line) {
    if (str_contains($line, '[critical]')) {
        $criticalLines[] = $line;
    }
    if (str_contains($line, 'PHP Fatal') || str_contains($line, 'PHP Parse error')) {
        $unexpectedErrorLines[] = $line;
    }
}
t('no app.log critical errors after theme-editor render pass', empty($criticalLines), implode('; ', $criticalLines));
t('no PHP fatal/parse errors after theme-editor render pass', empty($unexpectedErrorLines), implode('; ', $unexpectedErrorLines));

echo "\n── Result: {$pass} passed, {$fail} failed ──\n";
if ($fail > 0) {
    echo implode("\n", $errors) . "\n";
    exit(1);
}
echo "  ✓ ALL THEME-EDITOR CONTRACT TESTS PASSED\n";
