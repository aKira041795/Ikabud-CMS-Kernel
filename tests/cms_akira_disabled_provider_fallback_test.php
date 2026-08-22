<?php

declare(strict_types=1);

/**
 * CMS Akira Disabled-Provider Fallback Test.
 *
 * Verifies the documented fallback behavior for Akira providers when the
 * underlying capability provider is disabled or fails:
 *
 *   - `akira.theme.resolve@1` (cms-akira-theme): primary path resolves from
 *     CMS (`resolved_from: cms`); on failure it returns `resolved_from:
 *     fallback` with a safe default theme key. This is a SELECTION-FAILURE
 *     output, never a provider mode (mode stays `first` per manifest).
 *   - The fallback must NOT reintroduce direct foreign helper/SQL access.
 */

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';

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

$helpersFile = BASE_PATH . '/modules/cms-akira/cms-akira-theme/helpers.php';
$helpersSrc = is_file($helpersFile) ? file_get_contents($helpersFile) : '';

echo "\n=== CMS AKIRA DISABLED-PROVIDER FALLBACK ===\n";

// ── 1. Fallback branch exists and returns resolved_from: fallback ───
t(
    'theme resolver has a fallback branch',
    str_contains($helpersSrc, "'resolved_from' => 'fallback'"),
    'resolved_from fallback marker missing'
);
t(
    'fallback uses safe default theme key',
    str_contains($helpersSrc, "'akira-default'"),
    'fallback default theme key missing'
);
t(
    'fallback returns ok:true with provider cms-akira-theme',
    str_contains($helpersSrc, "'provider' => 'cms-akira-theme'"),
    'fallback provider marker missing'
);

// ── 2. Fallback does NOT reintroduce foreign helpers/SQL ────────────
$forbiddenPatterns = [
    'cmsDb()',
    'FROM cms_themes',
    'FROM cms_',
    'cmsResolveSeoTitle',
    'cmsGetMenus',
    'cmsResolveUploadUrl',
    'readCmsSettings',
    'cmsGetMenuItemsTree',
];
$breaches = [];
foreach ($forbiddenPatterns as $pat) {
    if (str_contains($helpersSrc, $pat)) {
        $breaches[] = $pat;
    }
}
t(
    'theme fallback path contains no direct CMS helper/SQL access',
    $breaches === [],
    implode('; ', $breaches)
);

// ── 3. Fallback is an output of selection failure, not a provider mode ─
$manifest = discoverModules()['cms-akira-theme'] ?? [];
$exposes = is_array($manifest['capabilities']['exposes'] ?? null) ? $manifest['capabilities']['exposes'] : [];
$themeModes = [];
foreach ($exposes as $cap) {
    if ((string)($cap['id'] ?? '') === 'akira.theme.resolve@1') {
        $themeModes = is_array($cap['modes'] ?? null) ? array_values(array_map('strval', $cap['modes'])) : [];
    }
}
t(
    'provider mode remains first (fallback is selection-failure output only)',
    in_array('first', $themeModes, true) && !in_array('fallback', $themeModes, true),
    json_encode($themeModes)
);

// ── 4. Invoke the handler directly and verify fallback output ───────
// Load module helpers then call the capability handler with a payload that
// forces the failure path (cmsThemeRuntimeDiagnostics unavailable in CLI).
if (function_exists('loadModuleHelpers')) {
    loadModuleHelpers($manifest);
}
$handlerRef = null;
$modulePrefix = preg_replace('/[^a-z0-9]+/i', '_', 'cms-akira-theme');
$exportFn = $modulePrefix . '_capability_handlers';
if (function_exists($exportFn)) {
    $map = $exportFn();
    $handlerRef = is_array($map) ? ($map['akira.theme.resolve@1'] ?? null) : null;
}
if (is_callable($handlerRef)) {
    $result = $handlerRef(['theme' => ''], 'akira.theme.resolve@1', 'test');
    $isOk = is_array($result) && !empty($result['ok']);
    $resolvedFrom = is_array($result['data'] ?? null) ? ($result['data']['resolved_from'] ?? '') : '';
    $themeKey = is_array($result['data'] ?? null) ? ($result['data']['theme'] ?? '') : '';
    t(
        'handler invoked returns a structured result',
        is_array($result),
        is_array($result) ? '' : json_encode($result)
    );
    t(
        'invoked handler returns ok:true (graceful fallback)',
        $isOk,
        json_encode($result)
    );
    t(
        'invoked fallback marks resolved_from: fallback',
        $resolvedFrom === 'fallback',
        (string)$resolvedFrom
    );
    t(
        'invoked fallback theme is akira-default',
        $themeKey === 'akira-default',
        (string)$themeKey
    );
} else {
    t('theme capability handler is invocable', false, 'handler not resolved');
}

// ── 5. No log noise ────────────────────────────────────────────────
$criticalLines = [];
$appLog = @file_get_contents(STORAGE_PATH . '/logs/app.log') ?: '';
foreach (explode("\n", $appLog) as $line) {
    if (str_contains($line, '[critical]')) {
        $criticalLines[] = $line;
    }
}
t('no app.log critical errors after disabled-provider fallback check', empty($criticalLines), implode('; ', $criticalLines));

echo "\n── Result: {$pass} passed, {$fail} failed ──\n";
if ($fail > 0) {
    echo implode("\n", $errors) . "\n";
    exit(1);
}
echo "  ✓ ALL DISABLED-PROVIDER FALLBACK TESTS PASSED\n";
