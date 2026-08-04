<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/src/helpers/manifest-validation.php';

$passed = 0;
$failed = 0;

$assert = static function (bool $condition, string $label) use (&$passed, &$failed): void {
    if ($condition) {
        $passed++;
        echo "PASS: {$label}\n";
        return;
    }

    $failed++;
    echo "FAIL: {$label}\n";
};

$base = [
    'id' => 'policy-fixture',
    'name' => 'Policy Fixture',
    'version' => '1.0.0',
    'routes' => false,
    'capabilities' => [
        'exposes' => [],
        'depends' => [],
    ],
];

$withDependencyOverreach = $base;
$withDependencyOverreach['capabilities']['depends'] = ['kernel.auth.authenticate@1'];
$overreachPath = sys_get_temp_dir() . '/ikabud-manifest-arch-' . bin2hex(random_bytes(6)) . '.json';
file_put_contents($overreachPath, json_encode($withDependencyOverreach, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
$overreachResult = validateModuleManifestForArchitectureV1($overreachPath);

$overreachDiagnostics = $overreachResult['diagnostics'] ?? [];
$hasOverreachRule = false;
foreach ($overreachDiagnostics as $diagnostic) {
    if (($diagnostic['rule'] ?? '') === 'manifest.arch.depends.kernel-auth-authenticate' && ($diagnostic['severity'] ?? '') === 'cert_blocker') {
        $hasOverreachRule = true;
        break;
    }
}

$assert($overreachResult['ok'] === true, 'architecture policy overreach keeps ok=true (no fatal)');
$assert($overreachResult['certifiable'] === false, 'architecture policy overreach marks certifiable=false');
$assert($hasOverreachRule, 'architecture policy emits dependency overreach cert blocker');

$withAuthOwnedMissingColumns = $base;
$withAuthOwnedMissingColumns['auth_owned'] = [
    'users_table' => 'sample_users',
    'username_column' => 'username',
    'email_column' => 'email',
    'password_column' => 'password_hash',
    'name_column' => 'display_name',
    'active_column' => 'is_active',
    'admin_roles' => ['administrator'],
    'default_admin_role' => 'administrator',
];

$authMissingPath = sys_get_temp_dir() . '/ikabud-manifest-arch-' . bin2hex(random_bytes(6)) . '.json';
file_put_contents($authMissingPath, json_encode($withAuthOwnedMissingColumns, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
$authMissingResult = validateModuleManifestForArchitectureV1($authMissingPath);

$missingIdColumn = false;
$missingRoleColumn = false;
foreach (($authMissingResult['diagnostics'] ?? []) as $diagnostic) {
    if (($diagnostic['rule'] ?? '') === 'manifest.arch.auth-owned.id-column' && ($diagnostic['severity'] ?? '') === 'cert_blocker') {
        $missingIdColumn = true;
    }
    if (($diagnostic['rule'] ?? '') === 'manifest.arch.auth-owned.role-column' && ($diagnostic['severity'] ?? '') === 'cert_blocker') {
        $missingRoleColumn = true;
    }
}

$assert($missingIdColumn, 'architecture policy requires auth_owned.id_column');
$assert($missingRoleColumn, 'architecture policy requires auth_owned.role_column');
$assert($authMissingResult['certifiable'] === false, 'missing auth_owned columns are non-certifiable');

$withAuthOwnedComplete = $withAuthOwnedMissingColumns;
$withAuthOwnedComplete['auth_owned']['id_column'] = 'user_id';
$withAuthOwnedComplete['auth_owned']['role_column'] = 'role';

$authCompletePath = sys_get_temp_dir() . '/ikabud-manifest-arch-' . bin2hex(random_bytes(6)) . '.json';
file_put_contents($authCompletePath, json_encode($withAuthOwnedComplete, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
$authCompleteResult = validateModuleManifestForArchitectureV1($authCompletePath);

$hasAuthOwnedBlockers = false;
foreach (($authCompleteResult['diagnostics'] ?? []) as $diagnostic) {
    if (($diagnostic['rule'] ?? '') === 'manifest.arch.auth-owned.id-column' || ($diagnostic['rule'] ?? '') === 'manifest.arch.auth-owned.role-column') {
        $hasAuthOwnedBlockers = true;
        break;
    }
}

$assert($hasAuthOwnedBlockers === false, 'complete auth_owned columns clear architecture blockers');

@unlink($authMissingPath);
@unlink($authCompletePath);
@unlink($overreachPath);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
