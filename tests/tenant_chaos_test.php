<?php

/**
 * Multi-tenant chaos regression tests.
 *
 * Tests four failure scenarios specific to the multi-tenant architecture:
 *   A. Cross-tenant JWT is rejected when multi-tenancy is enabled.
 *   B. Same-tenant JWT passes when multi-tenancy is enabled.
 *   C. Cross-tenant JWT resolves when multi-tenancy is disabled (guard skipped).
 *   D. Module settings rows are isolated by tenant_id at the data layer.
 *
 * Run: php tests/tenant_chaos_test.php
 */

declare(strict_types=1);

$_SERVER['HTTP_HOST'] = 'applicationos.test';
$_SERVER['REQUEST_URI'] = '/';

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';

$pass   = 0;
$fail   = 0;
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

function heading(string $label): void
{
    echo "\n── {$label} ──\n";
}

function setPrivateProperty(object $obj, string $property, mixed $value): void
{
    $ref = new ReflectionProperty($obj, $property);
    $ref->setAccessible(true);
    $ref->setValue($obj, $value);
}

function getPrivateProperty(object $obj, string $property): mixed
{
    $ref = new ReflectionProperty($obj, $property);
    $ref->setAccessible(true);
    return $ref->getValue($obj);
}

file_put_contents(STORAGE_PATH . '/logs/app.log', '');
file_put_contents(STORAGE_PATH . '/logs/error.log', '');

$app      = app();
$resolver = $app->tenant();
$db       = $app->db();

// ── Snapshot original state ──────────────────────────────────────────────────
$originalConfig = getPrivateProperty($app, 'config');

$originalResolver = [
    'enabled'           => getPrivateProperty($resolver, 'enabled'),
    'strategy'          => getPrivateProperty($resolver, 'strategy'),
    'default'           => getPrivateProperty($resolver, 'default'),
    'resolvedTenantId'  => getPrivateProperty($resolver, 'resolvedTenantId'),
    'resolved'          => getPrivateProperty($resolver, 'resolved'),
];

$originalAuthHeader  = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
$originalCurrentUser = getPrivateProperty($app, 'currentUser');

// ─────────────────────────────────────────────────────────────────────────────
// SCENARIO A — Cross-tenant JWT is rejected when multi-tenancy is enabled
// ─────────────────────────────────────────────────────────────────────────────
heading('Scenario A: Cross-tenant JWT rejected (multi-tenant enabled)');

try {
    $cfgA = $originalConfig;
    $cfgA['app']['multi_tenant']['enabled'] = true;
    setPrivateProperty($app, 'config', $cfgA);

    // Mint a token claiming tenant_id = 1
    $tokenA = $app->jwt()->generate([
        'sub'       => 'chaos-user-a',
        'role'      => 'admin',
        'tenant_id' => 1,
        'source'    => 'cms',
    ]);

    // Resolver reports current tenant = 2 (different tenant)
    $resolver->setTenantId(2);
    setPrivateProperty($resolver, 'enabled', true);

    // Present the token as an Authorization header
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $tokenA;

    // Reset currentUser to force re-resolution from the token
    setPrivateProperty($app, 'currentUser', null);
    setPrivateProperty($app, 'resolvingCurrentUser', false);

    $userA = $app->user();

    t(
        'cross-tenant JWT returns null when tenant_id in token != current tenant',
        $userA === null,
        $userA !== null ? 'user was: ' . json_encode($userA) : 'null as expected'
    );
} finally {
    setPrivateProperty($app, 'config', $originalConfig);
    setPrivateProperty($app, 'currentUser', $originalCurrentUser);
    setPrivateProperty($app, 'resolvingCurrentUser', false);
    $resolver->setTenantId($originalResolver['resolvedTenantId']);
    setPrivateProperty($resolver, 'enabled', $originalResolver['enabled']);
    if ($originalAuthHeader === null) {
        unset($_SERVER['HTTP_AUTHORIZATION']);
    } else {
        $_SERVER['HTTP_AUTHORIZATION'] = $originalAuthHeader;
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// SCENARIO B — Same-tenant JWT passes when multi-tenancy is enabled
// ─────────────────────────────────────────────────────────────────────────────
heading('Scenario B: Same-tenant JWT accepted (multi-tenant enabled)');

try {
    $cfgB = $originalConfig;
    $cfgB['app']['multi_tenant']['enabled'] = true;
    setPrivateProperty($app, 'config', $cfgB);

    // Mint a token claiming tenant_id = 5
    $tokenB = $app->jwt()->generate([
        'sub'       => 'chaos-user-b',
        'role'      => 'admin',
        'tenant_id' => 5,
        'source'    => 'cms',
    ]);

    // Resolver also reports tenant = 5 (same tenant)
    $resolver->setTenantId(5);
    setPrivateProperty($resolver, 'enabled', true);

    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $tokenB;
    setPrivateProperty($app, 'currentUser', null);
    setPrivateProperty($app, 'resolvingCurrentUser', false);

    $userB = $app->user();

    t(
        'same-tenant JWT resolves user successfully',
        $userB !== null && ($userB['sub'] ?? '') === 'chaos-user-b',
        $userB !== null ? json_encode(['sub' => $userB['sub'] ?? null, 'tenant_id' => $userB['tenant_id'] ?? null]) : 'null'
    );
    t(
        'same-tenant user carries expected tenant_id',
        ($userB['tenant_id'] ?? null) === 5,
        json_encode($userB['tenant_id'] ?? null)
    );
} finally {
    setPrivateProperty($app, 'config', $originalConfig);
    setPrivateProperty($app, 'currentUser', $originalCurrentUser);
    setPrivateProperty($app, 'resolvingCurrentUser', false);
    $resolver->setTenantId($originalResolver['resolvedTenantId']);
    setPrivateProperty($resolver, 'enabled', $originalResolver['enabled']);
    if ($originalAuthHeader === null) {
        unset($_SERVER['HTTP_AUTHORIZATION']);
    } else {
        $_SERVER['HTTP_AUTHORIZATION'] = $originalAuthHeader;
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// SCENARIO C — Cross-tenant JWT is NOT rejected when multi-tenancy is disabled
// ─────────────────────────────────────────────────────────────────────────────
heading('Scenario C: Cross-tenant JWT ignored when multi-tenant disabled');

try {
    $cfgC = $originalConfig;
    $cfgC['app']['multi_tenant']['enabled'] = false;
    setPrivateProperty($app, 'config', $cfgC);

    // Mint a token with a "foreign" tenant_id = 99
    $tokenC = $app->jwt()->generate([
        'sub'       => 'chaos-user-c',
        'role'      => 'admin',
        'tenant_id' => 99,
        'source'    => 'cms',
    ]);

    // Resolver reports a different tenant = 1 — should NOT trigger rejection
    $resolver->setTenantId(1);
    setPrivateProperty($resolver, 'enabled', true);

    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $tokenC;
    setPrivateProperty($app, 'currentUser', null);
    setPrivateProperty($app, 'resolvingCurrentUser', false);

    $userC = $app->user();

    t(
        'cross-tenant JWT resolves when multi-tenancy is disabled (check bypassed)',
        $userC !== null && ($userC['sub'] ?? '') === 'chaos-user-c',
        $userC !== null ? json_encode(['sub' => $userC['sub'] ?? null]) : 'null'
    );
} finally {
    setPrivateProperty($app, 'config', $originalConfig);
    setPrivateProperty($app, 'currentUser', $originalCurrentUser);
    setPrivateProperty($app, 'resolvingCurrentUser', false);
    $resolver->setTenantId($originalResolver['resolvedTenantId']);
    setPrivateProperty($resolver, 'enabled', $originalResolver['enabled']);
    if ($originalAuthHeader === null) {
        unset($_SERVER['HTTP_AUTHORIZATION']);
    } else {
        $_SERVER['HTTP_AUTHORIZATION'] = $originalAuthHeader;
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// SCENARIO D — Module settings rows are isolated by tenant_id at data layer
// ─────────────────────────────────────────────────────────────────────────────
heading('Scenario D: Module settings isolation at tenant_id data boundary');

$chaosModuleId = '_chaos_test_' . bin2hex(random_bytes(4));
$chaosTidA     = 99991;
$chaosTidB     = 99992;

try {
    // Ensure the settings table exists
    \Ikabud\Kernel\Database\KernelPDO::kernelEscalationEnter();
    $tableOk = moduleTenantSettingsEnsureTable($db);
    \Ikabud\Kernel\Database\KernelPDO::kernelEscalationLeave();

    t('tenant module settings table is available', $tableOk);

    if ($tableOk) {
        $table = moduleTenantSettingsTable();

        // Write distinct values for two different tenant IDs into the base DB.
        // We use direct SQL to sidestep the app-level tenant resolver.
        $stmtIns = $db->prepare(
            "INSERT INTO {$table} (tenant_id, module_id, setting_key, setting_value, created_at, updated_at) "
            . 'VALUES (:tid, :mid, :skey, :sval, NOW(), NOW()) '
            . 'ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()'
        );

        \Ikabud\Kernel\Database\KernelPDO::kernelEscalationEnter();
        $stmtIns->execute([':tid' => $chaosTidA, ':mid' => $chaosModuleId, ':skey' => 'color', ':sval' => '"red"']);
        $stmtIns->execute([':tid' => $chaosTidB, ':mid' => $chaosModuleId, ':skey' => 'color', ':sval' => '"blue"']);
        \Ikabud\Kernel\Database\KernelPDO::kernelEscalationLeave();

        // Read back via the module-manager function (uses tenant_id in WHERE clause)
        $settingsA = _readTenantModuleSettingsSingle($chaosModuleId, $chaosTidA);
        $settingsB = _readTenantModuleSettingsSingle($chaosModuleId, $chaosTidB);

        t(
            'tenant A settings returns only tenant A value',
            ($settingsA['color'] ?? null) === 'red',
            json_encode($settingsA)
        );
        t(
            'tenant B settings returns only tenant B value',
            ($settingsB['color'] ?? null) === 'blue',
            json_encode($settingsB)
        );
        t(
            'tenant A does not bleed into tenant B',
            ($settingsA['color'] ?? null) !== ($settingsB['color'] ?? null),
            'A=' . json_encode($settingsA['color'] ?? null) . ' B=' . json_encode($settingsB['color'] ?? null)
        );
    }
} finally {
    // Clean up chaos rows
    try {
        \Ikabud\Kernel\Database\KernelPDO::kernelEscalationEnter();
        $table = moduleTenantSettingsTable();
        $stmtClean = $db->prepare(
            "DELETE FROM {$table} WHERE module_id = :mid AND tenant_id IN (:tA, :tB)"
        );
        $stmtClean->execute([':mid' => $chaosModuleId, ':tA' => $chaosTidA, ':tB' => $chaosTidB]);
        \Ikabud\Kernel\Database\KernelPDO::kernelEscalationLeave();
    } catch (Throwable) {
        // best effort
    }
}

// ── Summary ──────────────────────────────────────────────────────────────────
echo "\n════════════════════════════════════════════════════\n";
echo "  Results: {$pass} passed, {$fail} failed\n";
if ($fail > 0) {
    echo "\n  Failures:\n";
    foreach ($errors as $error) {
        echo "    ✗ {$error}\n";
    }
}
echo "════════════════════════════════════════════════════\n\n";

exit($fail > 0 ? 1 : 0);
