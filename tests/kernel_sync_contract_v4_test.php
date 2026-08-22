<?php

declare(strict_types=1);

/**
 * Kernel sync contract v4 — focused tests for the ikabudsix kernel sync:
 * companion predicate + kernel-admin access, base-DB isolation, migration
 * ownership gate, canonical auth_owned resolver, daily-ledger 019 retirement,
 * and TenantResolver fail-closed status semantics.
 *
 * Run: php tests/kernel_sync_contract_v4_test.php
 */

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'applicationos.test';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/';

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';

use Ikabud\Kernel\TenantResolver;

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

echo "=== Kernel sync contract v4 ===\n";

// ── 1. isDeclaredKernelCompanion (on-disk manifest, canonical) ────────────
echo "\n-- Companion predicate --\n";
t('gui-settings is declared companion', isDeclaredKernelCompanion('gui-settings') === true);
t('cms is NOT a declared companion', isDeclaredKernelCompanion('cms') === false);
t('daily-ledger is NOT a declared companion', isDeclaredKernelCompanion('daily-ledger') === false);
t('unknown module is not a companion', isDeclaredKernelCompanion('does-not-exist') === false);
t('empty module id is not a companion', isDeclaredKernelCompanion('') === false);

// ── 2. kernelAdminAccessGranted fail-closed matrix ─────────────────────────
echo "\n-- Kernel admin access predicate --\n";
$kernelAdmin = ['source' => 'kernel', 'role' => 'admin', 'id' => 1, 'tenant_id' => null];
$moduleAdmin = ['source' => 'cms', 'role' => 'admin', 'id' => 2];
$kernelViewer = ['source' => 'kernel', 'role' => 'viewer', 'id' => 1];
$kernelSuper = ['source' => 'kernel', 'role' => 'superadmin', 'id' => 1];

// The deployed strategy is control_host, so a tenant binding must be
// established for the granted path when multi-tenancy is enabled.
$resolver = app()->tenant();
$resolver->reset();
if ($resolver->isEnabled()) {
    $resolver->setTenantId(1);
}
t('gui-settings accessible to kernel admin', kernelAdminAccessGranted($kernelAdmin, 'gui-settings') === true);
t('non-companion denied for kernel admin', kernelAdminAccessGranted($kernelAdmin, 'cms') === false);
t('non-companion denied even with legacy allow_kernel_admin', kernelAdminAccessGranted($kernelAdmin, 'ai') === false);
t('module-issued admin denied', kernelAdminAccessGranted($moduleAdmin, 'gui-settings') === false);
t('kernel viewer denied', kernelAdminAccessGranted($kernelViewer, 'gui-settings') === false);
t('kernel superadmin denied (admin role only)', kernelAdminAccessGranted($kernelSuper, 'gui-settings') === false);
t('empty user denied', kernelAdminAccessGranted([], 'gui-settings') === false);
t('unknown module denied', kernelAdminAccessGranted($kernelAdmin, 'nope') === false);

// Fail closed when multi-tenant and no tenant binding resolves.
if ($resolver->isEnabled()) {
    $resolver->reset();
    t('kernel admin without tenant binding denied when multi-tenant', kernelAdminAccessGranted($kernelAdmin, 'gui-settings') === false);
    $resolver->setTenantId(1);
}

// ── 3. Base-DB isolation (config-based + connected identity) ───────────────
echo "\n-- Base-DB isolation --\n";
$baseDb = (string) app()->config('database.database', '');
$baseHost = (string) app()->config('database.host', 'localhost');
$basePort = (string) app()->config('database.port', '3306');

if (function_exists('tenantConnectionResolvesToBaseDb')) {
    // Only meaningful when multi_tenant is enabled; skip config-sensitive
    // assertions when the base DB name is empty.
    if ($baseDb !== '') {
        t('exact base DB config resolves to base', tenantConnectionResolvesToBaseDb([
            'driver' => (string) app()->config('database.driver', 'mysql'),
            'host' => $baseHost,
            'port' => $basePort,
            'db_name' => $baseDb,
        ]) === true);
        t('localhost/127.0.0.1 alias cannot bypass', tenantConnectionResolvesToBaseDb([
            'driver' => 'mysql',
            'host' => $baseHost === '127.0.0.1' ? 'localhost' : '127.0.0.1',
            'port' => $basePort,
            'db_name' => $baseDb,
        ]) === true);
        $reject = tenantRejectBaseDbConnection([
            'driver' => 'mysql',
            'host' => $baseHost,
            'port' => $basePort,
            'db_name' => $baseDb,
        ]);
        t('base-DB rejection fail-closed', empty($reject['ok']) && isset($reject['error']));
    } else {
        t('base-DB config present for test', false, 'database.database is empty — cannot run isolation assertions');
    }

    t('distinct db_name does not resolve to base', tenantConnectionResolvesToBaseDb([
        'driver' => 'mysql',
        'host' => $baseHost,
        'port' => $basePort,
        'db_name' => 'tenant_does_not_match_base_xyz',
    ]) === false);
}

// ── 4. Migration ownership gate ────────────────────────────────────────────
echo "\n-- Migration ownership gate --\n";
if (function_exists('tenantMigrationOwnershipPreflight')) {
    $r = tenantMigrationOwnershipPreflight('ALTER TABLE audit_logs ADD COLUMN x INT;', 'daily-ledger');
    t('static ALTER on audit_logs blocked', empty($r['ok']));

    $r2 = tenantMigrationOwnershipPreflight('CREATE TABLE users (id INT);', 'bakeshop');
    t('CREATE TABLE users blocked', empty($r2['ok']));

    $r3 = tenantMigrationOwnershipPreflight("PREPARE s FROM \"ALTER TABLE audit_logs ADD COLUMN x INT\"; EXECUTE s;", 'bakeshop');
    t('dynamic SQL referencing audit_logs blocked', empty($r3['ok']));

    $r4 = tenantMigrationOwnershipPreflight('ALTER TABLE dl_users ADD COLUMN z INT;', 'daily-ledger');
    t('module-owned dl_users ALTER allowed', !empty($r4['ok']));

    $bakeshop002 = (string)file_get_contents(__DIR__ . '/../modules/bakeshop/database/migrations/002_bakeshop_delivery_source.sql');
    $r5 = tenantMigrationOwnershipPreflight($bakeshop002, 'bakeshop');
    t('bakeshop 002 (module-owned dynamic ALTER) allowed', !empty($r5['ok']));

    $r6 = tenantMigrationOwnershipPreflight('ALTER TABLE workflow_runs ADD COLUMN y INT;', 'bakeshop');
    t('workflow_* table blocked', empty($r6['ok']));

    $r7 = tenantMigrationOwnershipPreflight('DROP TABLE tenant_module_settings;', 'bakeshop');
    t('tenant_module_settings blocked', empty($r7['ok']));

    // Kernel artifacts are exempt.
    $r8 = tenantMigrationOwnershipPreflight('ALTER TABLE audit_logs ADD COLUMN x INT;', '_kernel');
    t('kernel artifacts exempt from gate', !empty($r8['ok']));
}

// ── 5. Canonical auth_owned on-disk resolver ───────────────────────────────
echo "\n-- Canonical auth_owned resolver --\n";
if (function_exists('kernelAuthOwnedSpecFromDisk')) {
    $spec = kernelAuthOwnedSpecFromDisk('daily-ledger');
    t('daily-ledger auth_owned spec resolves from disk', is_array($spec) && ($spec['users_table'] ?? '') === 'dl_users');
    t('daily-ledger default_admin_role in admin_roles', is_array($spec) && in_array($spec['default_admin_role'] ?? '', $spec['admin_roles'] ?? [], true));
    t('unknown module has no spec', kernelAuthOwnedSpecFromDisk('nope') === null);
}

// ── 6. Daily-ledger 019 retirement (tracked no-op) ─────────────────────────
echo "\n-- daily-ledger 019 retirement --\n";
$dl019 = (string)file_get_contents(__DIR__ . '/../modules/daily-ledger/database/migrations/019_audit_logs_actor_columns.sql');
t('019 is a no-op (no ALTER TABLE)', stripos($dl019, 'ALTER TABLE') === false);
if (function_exists('tenantMigrationOwnershipPreflight')) {
    $r = tenantMigrationOwnershipPreflight($dl019, 'daily-ledger');
    t('019 no-op passes ownership gate', !empty($r['ok']));
}

// ── 7. TenantResolver fail-closed status semantics ─────────────────────────
echo "\n-- TenantResolver status semantics --\n";
// tenantIsActive must be a bool (never null) and false for non-active.
$reflect = new ReflectionMethod(TenantResolver::class, 'tenantIsActive');
t('tenantIsActive returns bool (fail-closed, no null)', (string)$reflect->getReturnType() === 'bool');

// Control-DB-level check on a real row when possible.
try {
    $controlPdo = app()->controlDb();
    $exists = (bool)$controlPdo->query('SELECT 1 FROM kernel_tenants LIMIT 1')->fetchColumn();
    if ($exists) {
        // Insert a throwaway pending tenant, verify fail-closed, then remove it.
        $key = 'v4test_pending_' . time();
        $controlPdo->prepare('INSERT INTO kernel_tenants (tenant_key, status, entry_module_id) VALUES (:k, :s, :e)')
            ->execute([':k' => $key, ':s' => 'pending', ':e' => 'daily-ledger']);
        $tid = (int)$controlPdo->lastInsertId();

        t('pending tenant is NOT active (fail-closed)', TenantResolver::tenantIsActive($tid) === false);

        $controlPdo->prepare('UPDATE kernel_tenants SET status = :s WHERE id = :id')->execute([':s' => 'active', ':id' => $tid]);
        t('active tenant IS active', TenantResolver::tenantIsActive($tid) === true);

        $controlPdo->prepare('UPDATE kernel_tenants SET status = :s WHERE id = :id')->execute([':s' => 'suspended', ':id' => $tid]);
        t('suspended tenant is NOT active (fail-closed)', TenantResolver::tenantIsActive($tid) === false);

        $controlPdo->prepare('DELETE FROM kernel_tenants WHERE id = :id')->execute([':id' => $tid]);
    }
} catch (Throwable $e) {
    t('tenantIsActive control-DB probe', false, $e->getMessage());
}

echo "\n";
echo "══════════════════════════════════════════════════\n";
echo "  PASS: {$pass}  FAIL: {$fail}\n";
echo "══════════════════════════════════════════════════\n";

if ($fail > 0) {
    echo "\nFailures:\n";
    foreach ($errors as $e) {
        echo "  - {$e}\n";
    }
    exit(1);
}
exit(0);
