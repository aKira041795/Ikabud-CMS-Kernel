<?php

declare(strict_types=1);

/**
 * CMS Akira Route-Authz Matrix Test.
 *
 * Asserts the frozen route-specific authorization matrix for CMS Akira and
 * Theme Studio administrative routes. The matrix is route-specific (no
 * blanket JWT), with these dimensions per route:
 *   - session-backed admin AJAX/form mutations → authenticated tenant +
 *     named permission + CSRF
 *   - no forced JWT on session-backed Theme Studio routes
 *   - CMS builder API mutations → named capability + CSRF
 *   - authorized callers only (no wildcard broadening)
 *
 * This test statically verifies the route→handler→guard wiring for the
 * canonical CMS + Theme Studio mutation surfaces.
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

echo "\n=== CMS AKIRA ROUTE-AUTHZ MATRIX ===\n";

// ── 1. Theme Studio POST matrix (session-backed, no JWT) ───────────
$tsRoutes = include BASE_PATH . '/modules/theme-studio/routes.php';
$tsHandlers = file_get_contents(BASE_PATH . '/modules/theme-studio/handlers.php') ?: '';
$tsPost = is_array($tsRoutes['POST'] ?? null) ? $tsRoutes['POST'] : [];

// All 11 POST routes: capability-guarded + CSRF-enforced, no JWT.
// API routes (/api/v1/*) skip the global auto-enforce, so they MUST enforce
// CSRF in-handler. Admin-form routes (/admin/*save) are covered by the global
// CSRF_AUTO_ENFORCE gate (non-API POST with session), so in-handler CSRF is
// optional defense-in-depth — they still require the capability guard.
$allGuarded = true;
$allApiCsrf = true;
$missingGuards = [];
$missingCsrf = [];
foreach ($tsPost as $pattern => $ref) {
    $fn = is_string($ref) ? (explode(':', $ref)[1] ?? '') : '';
    $isApi = str_starts_with($pattern, '/api/v1/');
    // Every handler calls cmsRequireCap
    $hasCap = (bool)preg_match('/function ' . preg_quote($fn, '/') . '\([^)]*\)[^{]*\{[^{]*cmsRequireCap\(/s', $tsHandlers);
    $hasCsrf = (bool)preg_match('/function ' . preg_quote($fn, '/') . '\([^)]*\)[^{]*\{[^{]*cmsRequireCap\([^)]*\);[^{]*app\(\)->csrfEnforce\(\)/s', $tsHandlers);
    if (!$hasCap) { $allGuarded = false; $missingGuards[] = $pattern; }
    if ($isApi && !$hasCsrf) { $allApiCsrf = false; $missingCsrf[] = $pattern; }
}
t(
    'every Theme Studio POST route is capability-guarded',
    $allGuarded,
    implode('; ', $missingGuards)
);
t(
    'every Theme Studio API POST route enforces CSRF in-handler',
    $allApiCsrf,
    implode('; ', $missingCsrf)
);
t(
    'Theme Studio routes are session-backed (no forced JWT)',
    !str_contains($tsHandlers, 'csrfEnforceFromJwt'),
    'handler source must not force JWT'
);

// ── 2. Theme Studio mutation capability coverage ───────────────────
$tsManifest = json_decode((string)file_get_contents(BASE_PATH . '/modules/theme-studio/module.json'), true);
$tsExposes = is_array($tsManifest['capabilities']['exposes'] ?? null) ? $tsManifest['capabilities']['exposes'] : [];
$tsCapIds = array_map(static fn ($c) => (string)($c['id'] ?? ''), $tsExposes);
foreach (['theme.customize@1', 'theme.tokens@1', 'theme.presets@1', 'theme.elements@1', 'theme.token.apply@1'] as $cap) {
    t("Theme Studio exposes {$cap}", in_array($cap, $tsCapIds, true), json_encode($tsCapIds));
}

// ── 3. CMS builder API mutations: named capability + CSRF ──────────
$cmsHandlers = file_get_contents(BASE_PATH . '/modules/cms/handlers.php') ?: '';
// Read the API builder handlers file
$builderHandlers = '';
foreach (['20-api-builder', '84-extensions'] as $hf) {
    $p = BASE_PATH . "/modules/cms/handlers/{$hf}.php";
    if (is_file($p)) { $builderHandlers .= file_get_contents($p) ?: ''; }
}
$builderCsrfCount = substr_count($builderHandlers, 'app()->csrfEnforce()');
t('CMS API builder handlers enforce CSRF', $builderCsrfCount >= 5, "count={$builderCsrfCount}");

// ── 4. CMS content capability caller policy (no wildcard) ──────────
$cmsManifest = json_decode((string)file_get_contents(BASE_PATH . '/modules/cms/module.json'), true);
$policy = is_array($cmsManifest['capabilities']['policy']['capabilities'] ?? null)
    ? $cmsManifest['capabilities']['policy']['capabilities']
    : [];
$policyViolations = [];
$contentCaps = ['cms.content.get@1', 'cms.content.list@1', 'cms.content.create@1', 'cms.content.update@1'];
foreach ($contentCaps as $capId) {
    $callers = is_array($policy[$capId]['allow_callers'] ?? null) ? $policy[$capId]['allow_callers'] : [];
    foreach ($callers as $c) {
        if (str_contains($c, '*')) {
            $policyViolations[] = $capId . " → {$c}";
        }
    }
    // cms-akira-core must be an explicit caller on all four
    if (!in_array('cms-akira-core', $callers, true)) {
        $policyViolations[] = $capId . ' missing cms-akira-core';
    }
}
t(
    'cms.content.*@1 caller policy has no wildcard and includes cms-akira-core',
    $policyViolations === [],
    implode('; ', $policyViolations)
);

// ── 5. No log noise ────────────────────────────────────────────────
$criticalLines = [];
$appLog = @file_get_contents(STORAGE_PATH . '/logs/app.log') ?: '';
foreach (explode("\n", $appLog) as $line) {
    if (str_contains($line, '[critical]')) {
        $criticalLines[] = $line;
    }
}
t('no app.log critical errors after route-authz certification', empty($criticalLines), implode('; ', $criticalLines));

echo "\n── Result: {$pass} passed, {$fail} failed ──\n";
if ($fail > 0) {
    echo implode("\n", $errors) . "\n";
    exit(1);
}
echo "  ✓ ALL ROUTE-AUTHZ MATRIX TESTS PASSED\n";
