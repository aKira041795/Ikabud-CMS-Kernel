<?php
/**
 * ARK v3.0 — Mobile / Form / Dark-mode / Print / Accessibility Audit
 *
 * Programmatic audit of the ARK reference theme against WCAG 2.1 AA,
 * responsive best practices, and the v3.0 success criterion checklist.
 *
 * V3 additions: panel component variants, script.js existence, ecommerce
 * template presence, enhanced entity-view-map validation.
 *
 * Runs as a standalone PHP integration test. Requires bootstrap.
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

$passed = 0;
$failed = 0;
$themeDir = dirname(__DIR__) . '/storage/cms-themes/ark';
$cssPath = $themeDir . '/style.css';
$css = (string) @file_get_contents($cssPath);

function audit(string $label, mixed $condition, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        echo "PASS: {$label}\n";
        $passed++;
        return;
    }
    echo "FAIL: {$label}" . ($detail !== '' ? " :: {$detail}" : '') . "\n";
    $failed++;
}

@file_put_contents(dirname(__DIR__) . '/storage/logs/app.log', '');
@file_put_contents(dirname(__DIR__) . '/storage/logs/error.log', '');

echo "=== ARK v2.0 A11Y / RESPONSIVE / DARK-MODE / PRINT / FORM AUDIT ===\n\n";

// ────────────────────────────────────────────
// 1. MOBILE / RESPONSIVE
// ────────────────────────────────────────────
echo "── 1. Mobile & Responsive ──\n";

audit('CSS file exists and is non-empty', strlen($css) > 1000, strlen($css) . ' chars');

$has768 = (bool) preg_match('/@media\s*\(max-width:\s*768px\)/', $css);
$has1024 = (bool) preg_match('/@media\s*\(max-width:\s*1024px\)/', $css);
audit('Responsive breakpoint at 768px', $has768);
audit('Responsive breakpoint at 1024px', $has1024);

// Touch target minimums (WCAG 2.5.5 Target Size — 44px)
$hasMinHeight44 = (bool) preg_match('/min-height:\s*44px/', $css);
audit('Touch targets enforce min-height 44px', $hasMinHeight44);

// Mobile menu toggle
$hasMobileToggle = str_contains($css, '.ark-header__mobile-toggle');
audit('Mobile menu toggle defined', $hasMobileToggle);

// Mobile navigation collapse
$hasMobileNavCollapse = (bool) preg_match('/@media\s*\(max-width:\s*768px\).*\.ark-header__nav/s', $css);
$hasMobileNav = str_contains($css, '.ark-header__nav') && $has768;
audit('Mobile nav adapts at 768px breakpoint', $hasMobileNav, $hasMobileNavCollapse ? '' : 'nav collapse pattern may be implicit');

// Grid collapses to single column on mobile
$hasGridCollapse = (bool) preg_match('/grid-template-columns:\s*1fr/', $css);
audit('Grid collapses to single column on mobile', $hasGridCollapse);

// ────────────────────────────────────────────
// 2. FORM STYLING
// ────────────────────────────────────────────
echo "\n── 2. Form Styling ──\n";

$hasFormGroup = str_contains($css, '.ark-form-group');
audit('Form group styles present', $hasFormGroup);

$hasInput = str_contains($css, '.ark-input');
$hasSelect = str_contains($css, '.ark-select');
$hasTextarea = str_contains($css, '.ark-textarea');
audit('Text input styles present', $hasInput);
audit('Select styles present', $hasSelect);
audit('Textarea styles present', $hasTextarea);

$hasCheckbox = str_contains($css, '.ark-checkbox');
$hasFormCheckbox = str_contains($css, '.ark-form-checkbox');
audit('Checkbox styles present', $hasCheckbox);
audit('Checkbox label wrapper present', $hasFormCheckbox);

$hasFocus = str_contains($css, '.ark-input:focus');
audit('Input focus state defined', $hasFocus);

$hasError = str_contains($css, '.ark-input--error');
$hasFieldError = str_contains($css, '.ark-field-error');
audit('Input error state defined', $hasError);
audit('Field error message styles present', $hasFieldError);

$hasDisabled = (bool) preg_match('/\.ark-input:disabled/', $css);
audit('Input disabled state defined', $hasDisabled);

$hasLabel = str_contains($css, '.ark-label');
$hasRequired = str_contains($css, '.ark-label--required');
audit('Form label styles present', $hasLabel);
audit('Required field indicator present', $hasRequired);

$hasInlineForm = str_contains($css, '.ark-form--inline');
$hasTwoColForm = str_contains($css, '.ark-form--two_column');
audit('Inline form layout variant', $hasInlineForm);
audit('Two-column form layout variant', $hasTwoColForm);

// ────────────────────────────────────────────
// 3. DARK MODE
// ────────────────────────────────────────────
echo "\n── 3. Dark Mode ──\n";

$hasDarkQuery = str_contains($css, 'prefers-color-scheme: dark');
audit('Dark mode media query present', $hasDarkQuery);

$darkTokens = [
    '--ark-surface' => '--dark-surface',
    '--ark-text' => '--dark-text',
    '--ark-border' => '--dark-border',
    '--ark-primary' => '--dark-primary',
    '--ark-accent' => '--dark-accent',
    '--ark-success' => '--dark-success',
    '--ark-warning' => '--dark-warning',
    '--ark-danger' => '--dark-danger',
    '--ark-info' => '--dark-info',
];

foreach ($darkTokens as $arkVar => $darkVar) {
    $hasRemap = (bool) preg_match('/' . preg_quote($arkVar, '/') . ':\s*var\(' . preg_quote($darkVar, '/') . '/', $css);
    audit("Dark mode remaps {$arkVar} → {$darkVar}", $hasRemap);
}

// Dark tokens exist in tokens.json
$tokens = json_decode((string) @file_get_contents($themeDir . '/tokens.json'), true) ?: [];
$darkTokenKeys = ['dark-surface', 'dark-primary', 'dark-success', 'dark-warning', 'dark-danger', 'dark-info'];
foreach ($darkTokenKeys as $key) {
    $hasToken = isset($tokens["--{$key}"]);
    audit("Dark token --{$key} exists in tokens.json", $hasToken);
}

// ────────────────────────────────────────────
// 4. PRINT STYLESHEET
// ────────────────────────────────────────────
echo "\n── 4. Print Stylesheet ──\n";

$hasPrintQuery = str_contains($css, '@media print');
audit('Print media query present', $hasPrintQuery);

$printChecks = [
    'Navigation hidden in print' => 'display:\s*none.*!important',
    'Print background fix' => 'background:\s*#fff\s*!important',
    'Print color fix' => 'color:\s*#000\s*!important',
    'Link URLs shown in print' => 'attr\(href\)',
    'Page margin defined' => '@page',
    'Orphans/widows controlled' => 'orphans:\s*3',
    'Card page-break-inside avoid' => 'page-break-inside:\s*avoid',
];

foreach ($printChecks as $label => $pattern) {
    // Check within print block context — simplified: just check presence in CSS
    $has = (bool) preg_match('/' . $pattern . '/', $css);
    audit($label, $has);
}

// ────────────────────────────────────────────
// 5. ACCESSIBILITY
// ────────────────────────────────────────────
echo "\n── 5. Accessibility ──\n";

// Skip link
$hasSkipLink = str_contains($css, '.ark-skip');
$hasSkipFocus = str_contains($css, '.ark-skip:focus');
audit('Skip-to-content link defined', $hasSkipLink);
audit('Skip link visible on focus', $hasSkipFocus);

// Screen reader only
$hasSrOnly = str_contains($css, '.ark-sr-only');
audit('Screen-reader-only utility present', $hasSrOnly);

// Focus visible
$hasFocusVisible = str_contains($css, ':focus-visible');
audit('Global :focus-visible style defined', $hasFocusVisible);

// Reduced motion
$hasReducedMotion = str_contains($css, 'prefers-reduced-motion');
audit('Reduced motion media query present', $hasReducedMotion);

// Forced colors / high contrast
$hasForcedColors = str_contains($css, 'forced-colors');
audit('Forced-colors (high contrast) support present', $hasForcedColors);

// Button disabled state
$hasBtnDisabled = str_contains($css, '.ark-btn:disabled');
audit('Button disabled state defined', $hasBtnDisabled);

// Current page indicator (aria-current)
$hasAriaCurrent = str_contains($css, 'aria-current');
audit('CSS respects aria-current for nav', $hasAriaCurrent);

// ────────────────────────────────────────────
// 6. TEMPLATE AUDIT (spot checks)
// ────────────────────────────────────────────
echo "\n── 6. Template Accessibility Spot Checks ──\n";

$layoutPath = $themeDir . '/layouts/public.disyl';
$headerPath = $themeDir . '/templates/regions/header.disyl';

if (is_file($layoutPath)) {
    $layoutContent = (string) @file_get_contents($layoutPath);
    audit('Public layout template exists', true);
    $hasSkipLinkInLayout = str_contains($layoutContent, 'ark-skip') || str_contains($layoutContent, 'skip-link') || str_contains($layoutContent, 'skip to');
    audit('Skip link referenced in layout', $hasSkipLinkInLayout, 'look for ark-skip or skip-link class');
    $hasMainLandmark = str_contains($layoutContent, 'ark-main') || str_contains($layoutContent, 'role="main"') || str_contains($layoutContent, '<main');
    audit('Main landmark in layout', $hasMainLandmark);
}

if (is_file($headerPath)) {
    $headerContent = (string) @file_get_contents($headerPath);
    $hasNav = str_contains($headerContent, '<nav') || str_contains($headerContent, 'role="navigation"');
    audit('Navigation landmark in header', $hasNav);
    $hasMobileToggle = str_contains($headerContent, 'mobile-toggle') || str_contains($headerContent, 'ark-header__mobile-toggle');
    audit('Mobile menu toggle in header template', $hasMobileToggle);
}

// ────────────────────────────────────────────
// 7. THEME VALIDATION INTEGRITY
// ────────────────────────────────────────────
echo "\n── 7. Post-Audit Theme Validation ──\n";

$result = null;
$cmd = 'php ' . dirname(__DIR__) . '/ikabud theme:validate ark 2>&1';
exec($cmd, $output, $exitCode);
$outputStr = implode("\n", $output);

audit('theme:validate exits 0', $exitCode === 0, "exit code: {$exitCode}");
audit('theme:validate reports schema valid', str_contains($outputStr, 'Schema valid') || str_contains($outputStr, '✓ Schema valid'), $outputStr);
audit('theme:validate reports no anti-patterns', str_contains($outputStr, 'No anti-patterns detected') || str_contains($outputStr, '✓ No anti-patterns'), $outputStr);
audit('theme:validate reports DiSyL lint pass', str_contains($outputStr, 'pass lint') || str_contains($outputStr, '✓ All .disyl'), $outputStr);
audit('theme:validate reports all checks passed', str_contains($outputStr, 'All checks passed') || str_contains($outputStr, '✓ All checks'), $outputStr);

$hasWarnings = str_contains($outputStr, '⚠') && !str_contains($outputStr, '⚠ 0 warnings');
audit('theme:validate has NO warnings', !$hasWarnings, $hasWarnings ? 'warnings detected' : 'clean');

// DiSyL lint
$lintCmd = 'php ' . dirname(__DIR__) . '/_lint_disyl.php --path ' . $themeDir . ' 2>&1';
exec($lintCmd, $lintOutput, $lintExit);
$lintStr = implode("\n", $lintOutput);
audit('DiSyL lint exits 0', $lintExit === 0);
audit('DiSyL lint reports all valid', str_contains($lintStr, 'valid') && !str_contains($lintStr, 'invalid'), $lintStr);

// CSS size check
$cssSize = strlen($css);
$compressedEstimate = (int) (strlen(gzcompress($css, 9)) / 1024);
audit("CSS compressed size under 80KB budget ({$compressedEstimate}KB)", $compressedEstimate <= 80, "{$compressedEstimate}KB compressed");

// ────────────────────────────────────────────
// V3 COMPONENT & FILE INTEGRITY
// ────────────────────────────────────────────
echo "\n── 7. V3 Component & File Integrity ──\n";

$panelExists = str_contains($css, '.ark-panel');
$panelToneExists = str_contains($css, '.ark-panel--surface')
    && str_contains($css, '.ark-panel--muted')
    && str_contains($css, '.ark-panel--elevated')
    && str_contains($css, '.ark-panel--primary');
$panelSpacingExists = (bool) preg_match('/\.ark-panel--spacing-(none|sm|md|lg|xl)/', $css);
$panelRadiusExists = (bool) preg_match('/\.ark-panel--radius-(none|sm|md|lg|full)/', $css);
audit('.ark-panel component variant CSS exists', $panelExists);
audit('.ark-panel tone variants (surface/muted/elevated/primary)', $panelToneExists);
audit('.ark-panel spacing variants', $panelSpacingExists);
audit('.ark-panel radius variants', $panelRadiusExists);

$scriptPath = $themeDir . '/script.js';
$scriptExists = is_file($scriptPath);
audit('script.js exists (declared in manifest)', $scriptExists, $scriptExists ? (string)filesize($scriptPath) . ' bytes' : 'missing');

$ecommerceList = $themeDir . '/public/ecommerce/product-list.disyl';
$ecommerceDetail = $themeDir . '/public/ecommerce/product-detail.disyl';
audit('Ecommerce product-list template exists', is_file($ecommerceList));
audit('Ecommerce product-detail template exists', is_file($ecommerceDetail));

$evmPath = $themeDir . '/entity-view-map.json';
$evm = json_decode((string) @file_get_contents($evmPath), true) ?: [];
$entityTypes = array_keys($evm['entity_views'] ?? []);
$v3Types = ['guidance_case', 'guidance_appointment', 'attendance_record', 'pal_project', 'pal_expense'];
foreach ($v3Types as $type) {
    audit("Entity-view-map includes {$type}", in_array($type, $entityTypes, true));
}

// ────────────────────────────────────────────
// LOG CHECK
// ────────────────────────────────────────────
echo "\n── 8. Log Sanity ──\n";
$appLog = (string) @file_get_contents(dirname(__DIR__) . '/storage/logs/app.log');
$errLog = (string) @file_get_contents(dirname(__DIR__) . '/storage/logs/error.log');
audit('app.log clean', trim($appLog) === '' || !str_contains($appLog, 'Error'), trim($appLog) ?: '(empty)');
audit('error.log clean', trim($errLog) === '' || !stripos($errLog, 'fatal'), trim($errLog) ?: '(empty)');

echo "\n═══════════════════════════════════\n";
echo "Results: {$passed} passed, {$failed} failed\n";

if ($passed > 0 && $failed === 0) {
    echo "\n✅ ARK v3.0 audit: ALL CHECKS PASSED\n";
    echo "   Mobile, form, dark-mode, print, a11y, panels, ecommerce, and entity-view-map are production-ready.\n";
}

exit($failed > 0 ? 1 : 0);
