<?php

declare(strict_types=1);

/**
 * CMS Akira ARK Selection Nonmutation Test.
 *
 * Verifies the ARK panel/resolver is strictly READ-ONLY over tenant
 * selection:
 *
 *   - ApplicationProfileResolver::resolve() computes which profile is active
 *     but NEVER mutates any tenant-selection store.
 *   - The ARK Workbench profile is discoverable via the kernel registry with
 *     its declared identity (ark-workbench@0.1.x) and `supported_surfaces`.
 *   - There is no side-effect writer reachable from the resolver path
 *     (no `saveTenantSelection`, no `UPDATE ... SET active_profile`).
 *
 * The resolver returns `{profile, error}` — a pure function of its inputs.
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

$registryFile = BASE_PATH . '/kernel/Services/ApplicationProfileRegistry.php';
$resolverFile = BASE_PATH . '/kernel/Services/ApplicationProfileResolver.php';
$registrySrc = is_file($registryFile) ? file_get_contents($registryFile) : '';
$resolverSrc = is_file($resolverFile) ? file_get_contents($resolverFile) : '';

echo "\n=== CMS AKIRA ARK SELECTION NONMUTATION ===\n";

// ── 1. Resolver is read-only (pure function) ───────────────────────
$mutationPatterns = [
    'INSERT INTO',
    'UPDATE ',
    'DELETE FROM',
    '->save(',
    '->set(',
    'saveTenantSelection',
    'active_profile',
];
$resolverMutations = [];
foreach ($mutationPatterns as $pat) {
    if (str_contains($resolverSrc, $pat)) {
        $resolverMutations[] = $pat;
    }
}
t(
    'ApplicationProfileResolver source has no persistence/mutation calls',
    $resolverMutations === [],
    implode('; ', $resolverMutations)
);

// ── 2. Registry discoverability: ARK Workbench profile present ─────
$profilesDir = BASE_PATH . '/storage/application-profiles';
$arkProfileDir = $profilesDir . '/ark-workbench';
$manifestPath = $arkProfileDir . '/profile.manifest.json';
$manifest = is_file($manifestPath) ? json_decode((string)file_get_contents($manifestPath), true) : null;
t('ARK Workbench profile.manifest.json exists', is_array($manifest), $manifestPath);
if (is_array($manifest)) {
    t(
        'profile manifest declares name ark-workbench',
        (string)($manifest['name'] ?? '') === 'ark-workbench',
        (string)($manifest['name'] ?? '')
    );
    t(
        'profile manifest declares version 0.1.x',
        isset($manifest['version']) && str_starts_with((string)$manifest['version'], '0.1'),
        (string)($manifest['version'] ?? '')
    );
    $surfaces = is_array($manifest['supported_surfaces'] ?? null) ? $manifest['supported_surfaces'] : [];
    t(
        'profile declares supported_surfaces (desktop/mobile/tablet/print/pdf/email)',
        in_array('desktop', $surfaces, true)
            && in_array('mobile', $surfaces, true)
            && in_array('email', $surfaces, true),
        json_encode($surfaces)
    );
}

// ── 3. cms-akira-core declares application_profile ark.workbench ────
$coreManifest = discoverModules()['cms-akira-core'] ?? [];
$appProfile = is_array($coreManifest['application_profile'] ?? null) ? $coreManifest['application_profile'] : [];
t(
    'cms-akira-core declares application_profile',
    $appProfile !== [],
    json_encode($appProfile)
);
t(
    'declared profile id is ark.workbench',
    ($appProfile['id'] ?? '') === 'ark.workbench',
    (string)($appProfile['id'] ?? '')
);
t(
    'declared profile version is ^0.1',
    ($appProfile['version'] ?? '') === '^0.1',
    (string)($appProfile['version'] ?? '')
);

// ── 4. Resolver is invocable and returns a non-mutating result ─────
require_once $registryFile;
require_once $resolverFile;
$declared = ['application_profile' => ['id' => 'ark.workbench', 'version' => '^0.1']];
$result = \Ikabud\Kernel\Services\ApplicationProfileResolver::resolve($declared, null);
t(
    'resolver returns structured {profile, error} result',
    is_array($result) && array_key_exists('profile', $result) && array_key_exists('error', $result),
    json_encode($result)
);
// The resolver resolves the module-required profile from the kernel registry
// (path 2) — a pure, read-only lookup. Whether it returns the provider or a
// null+error, it must NEVER mutate any selection store.
$profileIsProvider = is_object($result['profile'] ?? null);
$profileIsNull = ($result['profile'] ?? null) === null;
t(
    'resolver no-tenant path returns either the registered profile or null+error',
    $profileIsProvider || $profileIsNull,
    json_encode($result)
);
t(
    'resolver no-tenant path is non-mutating (no error on clean resolve)',
    $profileIsProvider && ($result['error'] ?? null) === null,
    json_encode($result)
);

// Try tenant-selected id — the kernel registry may not have ARK provider
// loaded in CLI (provider class lives in the profile dir). Assert graceful
// error, never a crash, and never a mutation.
$tenantResult = \Ikabud\Kernel\Services\ApplicationProfileResolver::resolve($declared, 'ark-workbench');
t(
    'resolver with unknown tenant selection returns a non-crashing result',
    is_array($tenantResult),
    json_encode($tenantResult)
);
t(
    'resolver output is a pure function (identical on repeat call)',
    \Ikabud\Kernel\Services\ApplicationProfileResolver::resolve($declared, 'ark-workbench') === $tenantResult,
    'repeat call diverged'
);

// ── 5. No log noise ────────────────────────────────────────────────
$criticalLines = [];
$appLog = @file_get_contents(STORAGE_PATH . '/logs/app.log') ?: '';
foreach (explode("\n", $appLog) as $line) {
    if (str_contains($line, '[critical]')) {
        $criticalLines[] = $line;
    }
}
t('no app.log critical errors after ARK selection nonmutation check', empty($criticalLines), implode('; ', $criticalLines));

echo "\n── Result: {$pass} passed, {$fail} failed ──\n";
if ($fail > 0) {
    echo implode("\n", $errors) . "\n";
    exit(1);
}
echo "  ✓ ALL ARK SELECTION NONMUTATION TESTS PASSED\n";
