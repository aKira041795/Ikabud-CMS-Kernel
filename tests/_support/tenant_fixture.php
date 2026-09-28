<?php

declare(strict_types=1);

$GLOBALS['test_tenant_fixtures'] ??= [];

/**
 * Make a synthetic tenant resolvable in CLI and activate one module for it.
 *
 * The control-plane HTTP writer deliberately rejects the application's primary
 * database (correctly, for production isolation), so this test fixture mirrors
 * its encrypted connection-row format while pointing at the suite's shared DB.
 */
function ensureTestTenant(int $tenantId, string $moduleId): void
{
    if ($tenantId <= 0 || $moduleId === '') {
        throw new InvalidArgumentException('A positive tenant id and module id are required.');
    }

    $control = app()->controlDb();
    $tenant = $control->prepare('SELECT id FROM kernel_tenants WHERE id = ?');
    $tenant->execute([$tenantId]);
    $createdTenant = $tenant->fetchColumn() === false;
    if ($createdTenant) {
        $insert = $control->prepare('INSERT INTO kernel_tenants (id, tenant_key, status) VALUES (?, ?, ?)');
        $insert->execute([$tenantId, 'test-fixture-' . $tenantId, 'active']);
    }

    $connection = $control->prepare('SELECT tenant_id FROM kernel_tenant_db_connections WHERE tenant_id = ?');
    $connection->execute([$tenantId]);
    $createdConnection = $connection->fetchColumn() === false;
    if ($createdConnection) {
        $password = (string) ($_ENV['DB_PASSWORD'] ?? '');
        $encrypted = (new \Ikabud\Kernel\Crypto())->encryptString($password);
        $insert = $control->prepare(
            'INSERT INTO kernel_tenant_db_connections '
            . '(tenant_id, db_driver, db_host, db_port, db_name, db_user, db_pass, db_charset, db_pass_ciphertext, db_pass_iv, db_pass_tag) '
            . 'VALUES (?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, ?)'
        );
        $insert->execute([
            $tenantId,
            (string) ($_ENV['DB_CONNECTION'] ?? 'mysql'),
            (string) ($_ENV['DB_HOST'] ?? 'localhost'),
            (string) ($_ENV['DB_PORT'] ?? '3306'),
            (string) ($_ENV['DB_DATABASE'] ?? ''),
            (string) ($_ENV['DB_USERNAME'] ?? ''),
            (string) ($_ENV['DB_CHARSET'] ?? 'utf8mb4'),
            $encrypted['ciphertext'],
            $encrypted['iv'],
            $encrypted['tag'],
        ]);
    }

    // Clear any earlier negative lookup and prove that the shared DB is usable.
    if (app()->reconnectDbForTenant($tenantId) === null) {
        throw new RuntimeException("Test tenant {$tenantId} is not database-resolvable.");
    }

    $settings = app()->dbForTenant($tenantId)->prepare('SELECT 1 FROM tenant_module_settings WHERE tenant_id = ? AND module_id = ? LIMIT 1');
    $settings->execute([$tenantId, $moduleId]);
    $createdSettings = $settings->fetchColumn() === false;
    if (!saveTenantModuleSettingsForTenant($moduleId, $tenantId, ['_module_enabled' => true])) {
        throw new RuntimeException("Could not activate {$moduleId} for test tenant {$tenantId}.");
    }
    invalidateTenantModuleSettingsCache();
    if (!moduleIsActive($moduleId, $tenantId)) {
        throw new RuntimeException("Test module {$moduleId} is not active for tenant {$tenantId}.");
    }

    $prior = $GLOBALS['test_tenant_fixtures'][$tenantId] ?? null;
    $GLOBALS['test_tenant_fixtures'][$tenantId] = [
        'createdTenant' => ($prior['createdTenant'] ?? false) || $createdTenant,
        'createdConnection' => ($prior['createdConnection'] ?? false) || $createdConnection,
        'createdSettings' => array_merge($prior['createdSettings'] ?? [], [$moduleId => $createdSettings]),
    ];
}

function cleanupTestTenant(int $tenantId): void
{
    $fixture = $GLOBALS['test_tenant_fixtures'][$tenantId] ?? null;
    if (!is_array($fixture)) {
        return;
    }

    $db = app()->dbForTenant($tenantId);
    foreach ($fixture['createdSettings'] as $moduleId => $created) {
        if ($created) {
            $delete = $db?->prepare('DELETE FROM tenant_module_settings WHERE tenant_id = ? AND module_id = ?');
            $delete?->execute([$tenantId, $moduleId]);
        }
    }
    if ($fixture['createdConnection']) {
        $delete = app()->controlDb()->prepare('DELETE FROM kernel_tenant_db_connections WHERE tenant_id = ?');
        $delete->execute([$tenantId]);
    }
    if ($fixture['createdTenant']) {
        $delete = app()->controlDb()->prepare('DELETE FROM kernel_tenants WHERE id = ?');
        $delete->execute([$tenantId]);
    }
    unset($GLOBALS['test_tenant_fixtures'][$tenantId]);
    invalidateTenantModuleSettingsCache();
}
