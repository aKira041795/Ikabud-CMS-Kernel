#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Daily Ledger — Business Overview browser fixture (tenant 207).
 *
 * Seeds deterministic multi-branch sales plus viewer/cashier accounts for the
 * authenticated overview Playwright spec, pins role_permissions and the
 * net-sales setting, and restores both on cleanup. State (original settings)
 * is persisted between the setup and cleanup CLI processes.
 *
 * Usage: php tests/daily-ledger/daily_ledger_overview_browser_fixture.php setup|cleanup
 */

$basePath = dirname(__DIR__, 2);
require_once $basePath . '/src/helpers/cli-bootstrap.php';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/cli/daily-ledger/overview-browser-fixture';
$app = kernelCliBootstrap($basePath);
$mode = $argv[1] ?? '';

$branchAlpha = 99351;
$branchBeta = 99352;
$productP1 = 99351;
$productP2 = 99352;
$productP3 = 99353;
$viewerId = 99351;
$cashierId = 99352;
$stateFile = STORAGE_PATH . '/daily-ledger-overview-browser-state.json';

$app->tenant()->setTenantId(207);
require_once $basePath . '/src/helpers/module-manager.php';
require_once $basePath . '/modules/daily-ledger/handlers.php';
$context = modulePushContext('daily-ledger');
if (!$context) {
    fwrite(STDERR, "Daily Ledger module context unavailable.\n");
    exit(1);
}
$db = $context->db();

$removeData = static function () use ($db, $branchAlpha, $branchBeta, $productP1, $productP2, $productP3, $viewerId, $cashierId): void {
    $db->execute('DELETE FROM dl_ledger_day_status WHERE branch_id IN (?, ?)', [$branchAlpha, $branchBeta]);
    $db->execute('DELETE FROM dl_ledger_shift_status WHERE branch_id IN (?, ?)', [$branchAlpha, $branchBeta]);
    $db->execute('DELETE FROM dl_daily_ledger WHERE branch_id IN (?, ?)', [$branchAlpha, $branchBeta]);
    $db->execute('DELETE FROM dl_user_branches WHERE user_id IN (?, ?) OR branch_id IN (?, ?)', [$viewerId, $cashierId, $branchAlpha, $branchBeta]);
    $db->execute('DELETE FROM dl_branch_products WHERE branch_id IN (?, ?)', [$branchAlpha, $branchBeta]);
    $db->execute('DELETE FROM dl_users WHERE id IN (?, ?)', [$viewerId, $cashierId]);
    $db->execute('DELETE FROM dl_branches WHERE id IN (?, ?)', [$branchAlpha, $branchBeta]);
    $db->execute('DELETE FROM dl_products WHERE id IN (?, ?, ?)', [$productP1, $productP2, $productP3]);
};

// Restore exact typed values. A key that was absent before the fixture ran must
// be removed from tenant storage, never replaced with a manifest default.
$deleteTenantSettings = static function (array $keys) use ($app): void {
    if ($keys === []) {
        return;
    }
    \Ikabud\Kernel\Database\KernelPDO::kernelEscalationEnter();
    try {
        $deleteStmt = $app->db()->prepare(
            'DELETE FROM ' . moduleTenantSettingsTable()
            . ' WHERE tenant_id = ? AND module_id = ? AND setting_key = ?'
        );
        foreach ($keys as $key) {
            $deleteStmt->execute([207, 'daily-ledger', (string)$key]);
        }
    } finally {
        \Ikabud\Kernel\Database\KernelPDO::kernelEscalationLeave();
        if (function_exists('invalidateTenantModuleSettingsCache')) {
            invalidateTenantModuleSettingsCache();
        }
    }
};

$restoreSettings = static function () use ($stateFile, $deleteTenantSettings): void {
    $keys = ['net_sales_deduction_percent', 'role_permissions'];
    $decoded = [];
    if (is_file($stateFile)) {
        $decoded = json_decode((string)file_get_contents($stateFile), true);
        @unlink($stateFile);
    }
    if (!is_array($decoded)) {
        $decoded = [];
    }
    // New format records typed values plus an explicit absent list. The legacy
    // flat format is treated as values for every key it contains.
    $values = is_array($decoded['values'] ?? null) ? $decoded['values'] : $decoded;
    $absent = is_array($decoded['absent'] ?? null) ? array_map('strval', $decoded['absent']) : [];

    $toSave = [];
    $toDelete = [];
    foreach ($keys as $key) {
        if (in_array($key, $absent, true)) {
            $toDelete[] = $key;
            continue;
        }
        if (array_key_exists($key, $values)) {
            // Preserve the original PHP type so an array-valued setting is not
            // flattened into the string "Array" on restore.
            $toSave[$key] = $values[$key];
            continue;
        }
        $toDelete[] = $key;
    }
    if ($toDelete !== []) {
        $deleteTenantSettings($toDelete);
    }
    if ($toSave !== []) {
        saveModuleSettings('daily-ledger', $toSave);
    }
    if (function_exists('invalidateTenantModuleSettingsCache')) {
        invalidateTenantModuleSettingsCache();
    }
};

if ($mode === 'cleanup') {
    $removeData();
    $restoreSettings();
    echo "Overview browser fixture removed.\n";
    exit(0);
}
if ($mode !== 'setup') {
    fwrite(STDERR, "Usage: php tests/daily-ledger/daily_ledger_overview_browser_fixture.php setup|cleanup\n");
    exit(2);
}

$removeData();

$originalRaw = getModuleSettings('daily-ledger');
$snapshot = ['values' => [], 'absent' => []];
foreach (['net_sales_deduction_percent', 'role_permissions'] as $key) {
    if (array_key_exists($key, $originalRaw)) {
        $snapshot['values'][$key] = $originalRaw[$key];
    } else {
        $snapshot['absent'][] = $key;
    }
}
// Preserve the first snapshot if a prior run crashed before cleanup, so the
// true pre-test settings are restored rather than the fixture's own values.
if (!is_file($stateFile)) {
    @file_put_contents($stateFile, json_encode($snapshot, JSON_UNESCAPED_SLASHES));
}

$db->execute('INSERT INTO dl_branches (id, code, name, is_active) VALUES (:id, :code, :name, 1)', [':id' => $branchAlpha, ':code' => 'OVA', ':name' => 'Overview Alpha']);
$db->execute('INSERT INTO dl_branches (id, code, name, is_active) VALUES (:id, :code, :name, 1)', [':id' => $branchBeta, ':code' => 'OVB', ':name' => 'Overview Beta']);
$db->execute('INSERT INTO dl_products (id, sku, name, current_price, sort_order, is_active) VALUES (:id, :sku, :name, 10, 0, 1)', [':id' => $productP1, ':sku' => 'OVB-P1', ':name' => 'Overview P1']);
$db->execute('INSERT INTO dl_products (id, sku, name, current_price, sort_order, is_active) VALUES (:id, :sku, :name, 5, 0, 1)', [':id' => $productP2, ':sku' => 'OVB-P2', ':name' => 'Overview P2']);
$db->execute('INSERT INTO dl_products (id, sku, name, current_price, sort_order, is_active) VALUES (:id, :sku, :name, 20, 0, 1)', [':id' => $productP3, ':sku' => 'OVB-P3', ':name' => 'Overview P3']);
foreach ([[$productP1, $branchAlpha], [$productP2, $branchAlpha], [$productP2, $branchBeta], [$productP3, $branchBeta]] as [$pid, $bid]) {
    $db->execute('INSERT INTO dl_branch_products (branch_id, product_id, is_active) VALUES (:b, :p, 1)', [':b' => $bid, ':p' => $pid]);
}
$db->execute('INSERT INTO dl_users (id, username, password_hash, full_name, role, is_active) VALUES (:id, :u, :p, :n, :r, 1)', [
    ':id' => $viewerId,
    ':u' => 'browser-overview-viewer',
    ':p' => password_hash('BrowserOverview!2031', PASSWORD_BCRYPT),
    ':n' => 'Browser Overview Viewer',
    ':r' => 'viewer',
]);
$db->execute('INSERT INTO dl_users (id, username, password_hash, full_name, role, is_active) VALUES (:id, :u, :p, :n, :r, 1)', [
    ':id' => $cashierId,
    ':u' => 'browser-overview-cashier',
    ':p' => password_hash('BrowserOverview!2031', PASSWORD_BCRYPT),
    ':n' => 'Browser Overview Cashier',
    ':r' => 'cashier',
]);
$db->execute('INSERT INTO dl_user_branches (user_id, branch_id) VALUES (:u, :b)', [':u' => $cashierId, ':b' => $branchAlpha]);

$ledger = $db->prepare('INSERT INTO dl_daily_ledger (branch_id, product_id, ledger_date, shift, price_snapshot, beg_bal, addtl, withdraw, bal_end) VALUES (:b, :p, :d, :s, :price, :beg, 0, 0, :end)');
// Alpha: P1 4+6=10 units @10 = 100; P2 10 units @5 = 50.
$ledger->execute([':b' => $branchAlpha, ':p' => $productP1, ':d' => '2031-05-01', ':s' => 'AM', ':price' => 10, ':beg' => 10, ':end' => 6]);
$ledger->execute([':b' => $branchAlpha, ':p' => $productP1, ':d' => '2031-05-02', ':s' => 'AM', ':price' => 10, ':beg' => 10, ':end' => 4]);
$ledger->execute([':b' => $branchAlpha, ':p' => $productP2, ':d' => '2031-05-01', ':s' => 'AM', ':price' => 5, ':beg' => 10, ':end' => 0]);
// Beta: P3 15 units @20 = 300; P2 5 units @5 = 25.
$ledger->execute([':b' => $branchBeta, ':p' => $productP3, ':d' => '2031-05-01', ':s' => 'AM', ':price' => 20, ':beg' => 20, ':end' => 5]);
$ledger->execute([':b' => $branchBeta, ':p' => $productP2, ':d' => '2031-05-02', ':s' => 'AM', ':price' => 5, ':beg' => 10, ':end' => 5]);

dlModuleSettings(true);
saveModuleSettings('daily-ledger', [
    'net_sales_deduction_percent' => '10',
    'role_permissions' => json_encode(dl_defaultRolePermissions(), JSON_UNESCAPED_SLASHES),
]);
if (function_exists('invalidateTenantModuleSettingsCache')) {
    invalidateTenantModuleSettingsCache();
}

echo "Overview browser fixture ready.\n";
