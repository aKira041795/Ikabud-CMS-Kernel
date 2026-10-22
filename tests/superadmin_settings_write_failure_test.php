<?php

declare(strict_types=1);

/**
 * Source guard for the superadmin module-settings write path.
 *
 * The handler exits, so it cannot be called directly in-process; these assertions
 * slice its body out of the file and protect the failure-reporting contract that
 * was broken on the live site:
 *
 *   tenant 203's DB connection is rejected (base_db_rejected), the read silently
 *   falls back to manifest defaults, the save returns false, and the handler still
 *   answered {"ok":true} -- a superadmin saw "Settings saved" with nothing persisted
 *   (app.log 2026-10-21 14:06:38 / 14:07:47, 209 rejections per page load).
 *
 * Every assertion below fails if the guard is removed, so this test is not vacuous.
 */

require __DIR__ . '/../bootstrap.php';

$pass = 0;
$fail = 0;
$errors = [];

function t(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $errors;

    if ($ok) {
        $pass++;
        echo "  ✓ {$label}\n";
        return;
    }

    $fail++;
    $errors[] = $label . ($detail !== '' ? ': ' . $detail : '');
    echo "  ✗ {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

echo "\n=== SUPERADMIN SETTINGS WRITE FAILURE GUARD ===\n\n";

$handlerPath = __DIR__ . '/../src/http/superadmin-handlers.php';
$source = (string)@file_get_contents($handlerPath);
t('superadmin handlers file is readable', $source !== '', $handlerPath);

$start = strpos($source, 'function kernelHandleApiSuperadminUpdateModuleSettings');
$end = $start === false ? false : strpos($source, 'function kernelHandleApiSuperadminPerf', $start);
$body = ($start !== false && $end !== false && $end > $start) ? substr($source, $start, $end - $start) : '';
t('update-settings handler body located', $body !== '', 'could not slice the handler out of the source');

// ── 1. Unreachable tenant DB refuses the write ────────────────────────────
$refusal = '/dbForTenant\(\$saTenantId\)\s*===\s*null\).{0,600}?http_response_code\(500\).{0,400}?\'ok\'\s*=>\s*false/s';
t(
    'unreachable tenant DB returns 500 with ok:false',
    preg_match($refusal, $body) === 1,
    'expected a dbForTenant() === null check followed by http_response_code(500) and ok => false'
);

// ── 2. The refusal happens before the read that falls back to defaults ────
$refusalPos = strpos($body, 'dbForTenant($saTenantId) === null');
$discoverPos = strpos($body, '$allMods = discoverModules()');
t(
    'refusal precedes the settings read/module discovery',
    $refusalPos !== false && $discoverPos !== false && $refusalPos < $discoverPos,
    'refusalPos=' . var_export($refusalPos, true) . ' discoverPos=' . var_export($discoverPos, true)
);

// ── 3. The save result is checked, not discarded ──────────────────────────
t(
    'saveTenantModuleSettingsForTenant() result is tested',
    preg_match('/!\s*saveTenantModuleSettingsForTenant\(\s*\$modId\s*,\s*\$saTenantId\s*,\s*\$newSettings\s*\)/', $body) === 1,
    'the returned bool must gate the response'
);
t(
    'no unchecked save statement remains',
    preg_match('/^[ \t]*saveTenantModuleSettingsForTenant\(/m', $body) !== 1,
    'a bare save call still discards its return value'
);

// ── 4. A failed save is reported as a failure ────────────────────────────
$failResponse = '/!\s*saveTenantModuleSettingsForTenant\(.{0,400}?http_response_code\(500\).{0,300}?\'ok\'\s*=>\s*false/s';
t(
    'failed save returns 500 with ok:false',
    preg_match($failResponse, $body) === 1,
    'expected the failed-save branch to emit 500 + ok => false'
);

// ── 5. Anti-vacuity: the success path still exists ───────────────────────
t(
    'success response is still emitted for a real save',
    preg_match('/\'ok\'\s*=>\s*true\s*,\s*\'module_id\'\s*=>\s*\$modId/s', $body) === 1,
    'the handler must still report success when the write succeeds'
);

// ── 6. The UI can render the failure (contract used by the template) ─────
$templatePath = __DIR__ . '/../templates/pages/superadmin-settings.disyl';
$template = (string)@file_get_contents($templatePath);
t(
    'settings template surfaces res.error on failure',
    str_contains($template, 'res.error'),
    $templatePath
);

echo "\n══════════════════════════════════════════════════\n";
echo "  PASS: {$pass}  FAIL: {$fail}\n";
echo "══════════════════════════════════════════════════\n";

if ($errors !== []) {
    echo "\nFailed tests:\n";
    foreach ($errors as $error) {
        echo "  - {$error}\n";
    }
    exit(1);
}

exit(0);
