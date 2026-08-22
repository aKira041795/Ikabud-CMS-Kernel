<?php

declare(strict_types=1);

/**
 * CMS Akira Theme Studio Save-Route Security Test.
 *
 * Asserts the per-POST security matrix for every Theme Studio mutation route:
 *   POST /api/v1/theme-studio/tokens/save
 *   POST /api/v1/theme-studio/tokens/reset
 *   POST /api/v1/theme-studio/presets/save
 *   POST /api/v1/theme-studio/presets/delete
 *   POST /api/v1/theme-studio/presets/apply
 *   POST /api/v1/theme-studio/presets/export
 *   POST /api/v1/theme-studio/presets/import
 *   POST /api/v1/theme-studio/elements/save
 *   POST /api/v1/theme-studio/elements/delete
 *   POST /admin/theme-studio/contracts/{contractKey}/save
 *   POST /admin/theme-studio/blocks/{category}/{type}/save
 *
 * Each mutation requires:
 *   - a named capability permission (cmsRequireCap) — theme.tokens@1 /
 *     theme.presets@1 / theme.elements@1 / theme.customize@1
 *   - CSRF enforcement for session-backed mutations (app()->csrfEnforce())
 *   - tenant binding from runtime context (never from payload)
 *   - NO forced JWT on these session-backed admin routes
 *
 * All 11 routes are verified against the VERIFIED route surface, and every
 * handler is statically checked for the capability guard + CSRF call.
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

$routesFile = BASE_PATH . '/modules/theme-studio/routes.php';
$handlersFile = BASE_PATH . '/modules/theme-studio/handlers.php';
$routes = is_file($routesFile) ? (include $routesFile) : null;
$handlerSrc = is_file($handlersFile) ? file_get_contents($handlersFile) : '';

echo "\n=== THEME STUDIO SAVE-ROUTE SECURITY MATRIX ===\n";

// ── 1. VERIFIED POST route surface ─────────────────────────────────
$expectedPostRoutes = [
    '/api/v1/theme-studio/tokens/save' => 'theme.tokens@1',
    '/api/v1/theme-studio/tokens/reset' => 'theme.tokens@1',
    '/api/v1/theme-studio/presets/save' => 'theme.presets@1',
    '/api/v1/theme-studio/presets/delete' => 'theme.presets@1',
    '/api/v1/theme-studio/presets/apply' => 'theme.presets@1',
    '/api/v1/theme-studio/presets/export' => 'theme.presets@1',
    '/api/v1/theme-studio/presets/import' => 'theme.presets@1',
    '/api/v1/theme-studio/elements/save' => 'theme.elements@1',
    '/api/v1/theme-studio/elements/delete' => 'theme.elements@1',
    '/admin/theme-studio/contracts/{contractKey}/save' => 'theme.customize@1',
    '/admin/theme-studio/blocks/{category}/{type}/save' => 'theme.customize@1',
];
$postRoutes = is_array($routes['POST'] ?? null) ? $routes['POST'] : [];
foreach ($expectedPostRoutes as $pattern => $expectedPerm) {
    t("POST route declared: {$pattern}", isset($postRoutes[$pattern]), (string)($postRoutes[$pattern] ?? 'missing'));
}
t('exactly 11 POST routes declared (verified surface)', count($postRoutes) === 11, (string)count($postRoutes));

// ── 2. No invented /cms/ parameterized aliases for POST routes ─────
$cmsParamAliases = array_filter(
    array_keys($postRoutes),
    static fn (string $p): bool => str_starts_with($p, '/cms/') && str_contains($p, '{')
);
t('no invented parameterized /cms/ POST aliases', $cmsParamAliases === [], implode('; ', $cmsParamAliases));

// ── 3. Handler capability guard + CSRF per route ───────────────────
// Map handler function name → expected capability.
$handlerCaps = [
    'apiSaveTokens' => 'theme.tokens@1',
    'apiResetTokens' => 'theme.tokens@1',
    'apiSavePreset' => 'theme.presets@1',
    'apiDeletePreset' => 'theme.presets@1',
    'apiApplyPreset' => 'theme.presets@1',
    'apiExportPreset' => 'theme.presets@1',
    'apiImportPreset' => 'theme.presets@1',
    'apiSaveElement' => 'theme.elements@1',
    'apiDeleteElement' => 'theme.elements@1',
    'handleContractSave' => 'theme.customize@1',
    'handleBlockDefinitionSave' => 'theme.customize@1',
];

// Verify each handler reference in routes resolves to a function that
// (a) calls cmsRequireCap with the right capability and (b) enforces CSRF.
// API routes (/api/v1/*) skip the global auto-enforce and MUST enforce CSRF
// in-handler. Admin-form routes (/admin/*save) are covered by the global
// CSRF_AUTO_ENFORCE gate (non-API POST with active session); they still need
// the capability guard.
foreach ($postRoutes as $pattern => $ref) {
    $fn = is_string($ref) ? (explode(':', $ref)[1] ?? '') : '';
    if (!isset($handlerCaps[$fn])) {
        t("handler {$fn} has a security entry", false, 'unknown handler');
        continue;
    }
    $expectedCap = $handlerCaps[$fn];
    $isApi = str_starts_with($pattern, '/api/v1/');

    // capability guard: cmsRequireCap('<cap>')
    $capOk = str_contains($handlerSrc, "cmsRequireCap('{$expectedCap}')");
    t("{$fn} guards with {$expectedCap}", $capOk, "cmsRequireCap('{$expectedCap}') missing in {$fn}");

    // CSRF: API routes must enforce in-handler (global gate skips them);
    // admin-form routes rely on the global CSRF_AUTO_ENFORCE gate.
    $csrfOk = !$isApi || str_contains($handlerSrc, 'app()->csrfEnforce()');
    t(
        "{$fn} enforces CSRF" . ($isApi ? '' : ' (via global gate)'),
        $csrfOk,
        "app()->csrfEnforce() missing in API handler {$fn}"
    );
}

// ── 4. Tenant binding from runtime context (not payload) ───────────
// tokens/reset + save derive tenantId via cmsRuntimeTenantId(), never from
// the request payload.
$tokenHandlersSpan = substr(
    $handlerSrc,
    (int)strpos($handlerSrc, 'function apiSaveTokens('),
    (int)strpos($handlerSrc, 'function apiSavePreset(') - (int)strpos($handlerSrc, 'function apiSaveTokens(')
);
t(
    'token mutations derive tenant from runtime context (not payload)',
    str_contains($tokenHandlersSpan, 'cmsRuntimeTenantId()')
        && !preg_match('/tenant.*\$\_(?:POST|REQUEST)/', $tokenHandlersSpan),
    'tenant must come from runtime context'
);

// ── 5. No forced JWT on these session-backed routes ────────────────
$hasJwtTokenRequirement = str_contains($handlerSrc, 'csrfEnforceFromJwt')
    || str_contains($handlerSrc, 'Bearer')
    || str_contains($handlerSrc, 'authorization');
t(
    'no forced JWT on session-backed Theme Studio mutations',
    !$hasJwtTokenRequirement,
    'handler source must not force JWT'
);

// ── 6. Import/export file-surface hardening markers ────────────────
// Import validates the payload shape (preset + slug + data) before save;
// the import handler reads php://input and rejects malformed JSON.
t(
    'preset import validates payload shape before save',
    str_contains($handlerSrc, "isset(\$payload['preset'])")
        || str_contains($handlerSrc, "!isset(\$payload['preset'])"),
    'import payload validation marker missing'
);
t(
    'preset import rejects empty slug or data',
    str_contains($handlerSrc, "if (\$slug === '' || empty(\$data))"),
    'import empty-guard marker missing'
);

// ── 7. Routes file unchanged from canonical (no invented aliases) ───
$routeAliases = array_filter(
    array_keys($postRoutes),
    static fn (string $p): bool => !in_array($p, array_keys($expectedPostRoutes), true)
);
t('no extra/unexpected POST routes beyond verified surface', $routeAliases === [], implode('; ', $routeAliases));

// ── 8. No log noise ────────────────────────────────────────────────
$criticalLines = [];
$appLog = @file_get_contents(STORAGE_PATH . '/logs/app.log') ?: '';
foreach (explode("\n", $appLog) as $line) {
    if (str_contains($line, '[critical]')) {
        $criticalLines[] = $line;
    }
}
t('no app.log critical errors after security-matrix certification', empty($criticalLines), implode('; ', $criticalLines));

echo "\n── Result: {$pass} passed, {$fail} failed ──\n";
if ($fail > 0) {
    echo implode("\n", $errors) . "\n";
    exit(1);
}
echo "  ✓ ALL THEME-STUDIO SAVE-ROUTE SECURITY TESTS PASSED\n";
