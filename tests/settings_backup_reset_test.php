<?php

declare(strict_types=1);

/**
 * Integration test: Settings Backup + Data Reset "exclude users".
 *
 * Verifies for BOTH attendance-wage and project-audit-ledger:
 *   1. The tenant-scoped backup context builds and ModuleBackupService::generate()
 *      produces a valid data-only SQL file (DELETE + INSERT + FK guards).
 *   2. Full data reset honors the `keep_users` option (users preserved when true,
 *      deleted when false) — executed inside a transaction and rolled back.
 *
 * Run from repo root: php tests/settings_backup_reset_test.php
 */

$basePath = dirname(__DIR__);
require_once $basePath . '/bootstrap.php';
require_once $basePath . '/src/helpers/module-manager.php';

$errors = [];
$passed = 0;
$total  = 0;

function test(string $name, bool $condition, string $detail = ''): void {
    global $total, $passed, $errors;
    $total++;
    if ($condition) { $passed++; echo "  ✅ {$name}\n"; }
    else { $errors[] = "{$name}: {$detail}"; echo "  ❌ {$name}: {$detail}\n"; }
}

echo "Settings Backup + Reset (exclude users) — Integration Test\n";
echo str_repeat('=', 60) . "\n\n";

use Ikabud\Kernel\Services\ModuleBackupService;

// ── Resolve tenant IDs from the control plane ──
function tenantIdForEntryModule(string $entryModuleId): int {
    try {
        $tid = (int)app()->controlDb()->query(
            "SELECT id FROM kernel_tenants WHERE entry_module_id = " . app()->controlDb()->quote($entryModuleId) . " AND status = 'active' ORDER BY id LIMIT 1"
        )->fetchColumn();
        return $tid > 0 ? $tid : 0;
    } catch (Throwable $e) {
        return 0;
    }
}

function requireHandlerFile(string $path): void {
    if (is_file($path)) { require_once $path; }
}

// Load module handler files that define the functions under test.
requireHandlerFile($basePath . '/modules/attendance-wage/helpers.php');
requireHandlerFile($basePath . '/modules/attendance-wage/handlers/95-api-settings.php');
requireHandlerFile($basePath . '/modules/project-audit-ledger/helpers.php');
requireHandlerFile($basePath . '/modules/project-audit-ledger/handlers/70-settings.php');

$awTid = tenantIdForEntryModule('attendance-wage');
$palTid = tenantIdForEntryModule('project-audit-ledger');
test('Resolved attendance-wage tenant id', $awTid > 0, 'got: ' . $awTid);
test('Resolved project-audit-ledger tenant id', $palTid > 0, 'got: ' . $palTid);

// ── Backup: attendance-wage ──
echo "\nBackup — attendance-wage\n";
if ($awTid > 0 && function_exists('awBackupCtx')) {
    try {
        app()->tenant()->setTenantId($awTid);
        $res = ModuleBackupService::generate(awBackupCtx(), '', 'integration-test', [
            'download_path' => aw_backupDownloadPath(),
            'retention_days' => 30,
            'event'          => 'attendance_wage.backup.created',
            'by_user'        => 1,
        ]);
        test('Backup generated file', is_file(STORAGE_PATH . '/backups/attendance-wage/' . $res['file_name']), 'file: ' . ($res['file_name'] ?? '?'));
        $contents = (string)@file_get_contents(STORAGE_PATH . '/backups/attendance-wage/' . $res['file_name']);
        test('Backup is data-only SQL (DELETE+INSERT+FK guards)',
            str_contains($contents, 'SET FOREIGN_KEY_CHECKS=0;')
            && str_contains($contents, 'DELETE FROM `attendance_records`;')
            && str_contains($contents, 'INSERT INTO `attendance_records`'),
            'first 300 chars: ' . substr($contents, 0, 300));
        test('Backup lists total_rows', $res['total_rows'] >= 0, 'total_rows: ' . ($res['total_rows'] ?? '?'));
        $list = ModuleBackupService::list('attendance-wage', aw_backupDownloadPath());
        test('Backup appears in list', count($list) >= 1, 'count: ' . count($list));
        test('Backup download_url is routable', str_contains($list[0]['download_url'] ?? '', '/api/v1/wage/settings/backup/download'), 'url: ' . ($list[0]['download_url'] ?? '?'));
    } catch (Throwable $e) {
        test('Backup generate ran without exception', false, $e->getMessage());
    }
} else {
    test('awBackupCtx available', false, 'function missing or tenant unresolved');
}

// ── Backup: project-audit-ledger ──
echo "\nBackup — project-audit-ledger\n";
if ($palTid > 0 && function_exists('palBackupCtx')) {
    try {
        app()->tenant()->setTenantId($palTid);
        $res = ModuleBackupService::generate(palBackupCtx(), 'pal_', 'integration-test', [
            'download_path' => pal_backupDownloadPath(),
            'retention_days' => 30,
            'event'          => 'project_audit_ledger.backup.created',
            'by_user'        => 1,
        ]);
        test('Backup generated file', is_file(STORAGE_PATH . '/backups/project-audit-ledger/' . $res['file_name']), 'file: ' . ($res['file_name'] ?? '?'));
        $contents = (string)@file_get_contents(STORAGE_PATH . '/backups/project-audit-ledger/' . $res['file_name']);
        test('Backup is data-only SQL (DELETE+INSERT+FK guards)',
            str_contains($contents, 'SET FOREIGN_KEY_CHECKS=0;')
            && str_contains($contents, 'DELETE FROM `pal_projects`;')
            && str_contains($contents, 'INSERT INTO `pal_projects`'),
            'first 300 chars: ' . substr($contents, 0, 300));
        $list = ModuleBackupService::list('project-audit-ledger', pal_backupDownloadPath());
        test('Backup appears in list', count($list) >= 1, 'count: ' . count($list));
    } catch (Throwable $e) {
        test('Backup generate ran without exception', false, $e->getMessage());
    }
} else {
    test('palBackupCtx available', false, 'function missing or tenant unresolved');
}

// ── Reset exclude-users: attendance-wage (transaction + rollback) ──
echo "\nReset — attendance-wage (keep_users)\n";
if ($awTid > 0 && function_exists('awResetTenantData')) {
    try {
        $db = aw_db();
        $uid = 1; // arbitrary admin id; only used as the excluded id
        $before = (int)$db->query('SELECT COUNT(*) FROM attendance_wage_users')->fetchColumn();

        // keepUsers=true → users preserved
        $db->beginTransaction();
        awResetTenantData($db, (string)$awTid, $uid, array_keys(awResetGroups()), true, true);
        $afterKeep = (int)$db->query('SELECT COUNT(*) FROM attendance_wage_users')->fetchColumn();
        $db->rollBack();

        // keepUsers=false → non-admin users deleted. Insert a throwaway user so
        // the assertion is meaningful even when only the admin exists.
        $db->beginTransaction();
        $db->prepare("INSERT INTO attendance_wage_users (username, email, password_hash, full_name, role, is_active) VALUES ('tmp_reset_user', 'tmp@example.test', '" . password_hash('x', PASSWORD_BCRYPT) . "', 'Tmp', 'employee', 1)")
            ->execute();
        $withTmp = (int)$db->query('SELECT COUNT(*) FROM attendance_wage_users')->fetchColumn();
        awResetTenantData($db, (string)$awTid, $uid, array_keys(awResetGroups()), true, false);
        $afterDelete = (int)$db->query('SELECT COUNT(*) FROM attendance_wage_users')->fetchColumn();
        $db->rollBack();

        test('keep_users=true preserves user accounts', $afterKeep === $before, "before={$before} afterKeep={$afterKeep}");
        test('keep_users=false deletes non-admin users', $afterDelete === $before, "withTmp={$withTmp} afterDelete={$afterDelete} expected={$before}");
    } catch (Throwable $e) {
        test('AW reset keep_users ran without exception', false, $e->getMessage());
    }
} else {
    test('awResetTenantData available', false, 'function missing or tenant unresolved');
}

// ── Reset exclude-users: project-audit-ledger (transaction + rollback) ──
echo "\nReset — project-audit-ledger (keep_users)\n";
if ($palTid > 0 && function_exists('palResetTenantData')) {
    try {
        $db = palDb();
        $uid = 1;
        $before = (int)$db->query('SELECT COUNT(*) FROM pal_users')->fetchColumn();

        $db->beginTransaction();
        palResetTenantData($db, $palTid, $uid, array_keys(palResetGroups()), true, true);
        $afterKeep = (int)$db->query('SELECT COUNT(*) FROM pal_users')->fetchColumn();
        $db->rollBack();

        $db->beginTransaction();
        palResetTenantData($db, $palTid, $uid, array_keys(palResetGroups()), true, false);
        $afterDelete = (int)$db->query('SELECT COUNT(*) FROM pal_users')->fetchColumn();
        $db->rollBack();

        test('keep_users=true preserves user accounts', $afterKeep === $before, "before={$before} afterKeep={$afterKeep}");
        test('keep_users=false deletes non-admin users', $afterDelete < $before, "before={$before} afterDelete={$afterDelete}");
    } catch (Throwable $e) {
        test('PAL reset keep_users ran without exception', false, $e->getMessage());
    }
} else {
    test('palResetTenantData available', false, 'function missing or tenant unresolved');
}

echo "\n" . str_repeat('=', 60) . "\n";
echo "Result: " . ($passed === $total ? 'PASS' : 'FAIL') . "  ({$passed}/{$total})\n";
if (!empty($errors)) {
    echo "\nErrors:\n" . implode("\n", $errors) . "\n";
}
exit($passed === $total ? 0 : 1);
