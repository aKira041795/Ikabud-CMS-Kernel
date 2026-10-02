#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Daily Ledger — Reports browser fixture (tenant 207).
 *
 * Seeds the admin/viewer accounts, one active branch and one active product the
 * authenticated reporting Playwright spec filters on, plus a single official AM
 * ledger row whose stock-derived sales equal 6 units:
 *
 *     sales = beg_bal + addtl - withdraw - bal_end = 10 + 0 - 0 - 4 = 6
 *
 * The branch code is BROWSER so the governed export filename carries the
 * "browser" branch label the spec's download assertion expects.
 *
 * Exports performed by the spec archive a real .pdf/.csv plus .json metadata and
 * write a report_export audit row. The pre-test archive listing and audit id are
 * snapshotted into state, so cleanup removes only what the test itself created
 * and never the tenant's pre-existing report history. State is persisted between
 * the setup and cleanup CLI processes; every fixture DB row is deleted on
 * cleanup.
 *
 * Usage: php tests/daily-ledger/daily_ledger_reporting_browser_fixture.php setup|cleanup
 */

$basePath = dirname(__DIR__, 2);
require_once $basePath . '/src/helpers/cli-bootstrap.php';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/cli/daily-ledger/reporting-browser-fixture';
$app = kernelCliBootstrap($basePath);
$mode = $argv[1] ?? '';

$branchId   = 99202;
$productId  = 99202;
$adminId    = 99201;
$viewerId   = 99203;
$ledgerDate = '2031-03-15';
$password   = 'BrowserReport!2031';
$stateFile  = STORAGE_PATH . '/daily-ledger-reporting-browser-state.json';
$archiveDir = STORAGE_PATH . '/report-archive';

$app->tenant()->setTenantId(207);
require_once $basePath . '/src/helpers/module-manager.php';
require_once $basePath . '/modules/daily-ledger/handlers.php';
$context = modulePushContext('daily-ledger');
if (!$context) {
    fwrite(STDERR, "Daily Ledger module context unavailable.\n");
    exit(1);
}
$db = $context->db();

$listArchiveFiles = static function () use ($archiveDir): array {
    $files = is_dir($archiveDir) ? glob($archiveDir . '/*') : [];
    $names = array_map('basename', $files ?: []);
    sort($names);
    return $names;
};

// Delete every archive file that was not present in the pre-test snapshot,
// keeping the tenant's historical archives untouched.
$removeNewArchives = static function (array $snapshot) use ($archiveDir, $listArchiveFiles): void {
    $keep = array_flip($snapshot);
    foreach ($listArchiveFiles() as $name) {
        if (!isset($keep[$name])) {
            @unlink($archiveDir . '/' . $name);
        }
    }
};

$removeData = static function () use ($db, $branchId, $productId, $adminId, $viewerId): void {
    $db->execute('DELETE FROM dl_daily_ledger WHERE branch_id = ? OR product_id = ?', [$branchId, $productId]);
    $db->execute('DELETE FROM dl_ledger_shift_status WHERE branch_id = ?', [$branchId]);
    $db->execute('DELETE FROM dl_ledger_day_status WHERE branch_id = ?', [$branchId]);
    $db->execute('DELETE FROM dl_branch_products WHERE branch_id = ? OR product_id = ?', [$branchId, $productId]);
    $db->execute('DELETE FROM dl_user_branches WHERE user_id IN (?, ?) OR branch_id = ?', [$adminId, $viewerId, $branchId]);
    $db->execute('DELETE FROM dl_users WHERE id IN (?, ?)', [$adminId, $viewerId]);
    $db->execute('DELETE FROM dl_products WHERE id = ?', [$productId]);
    $db->execute('DELETE FROM dl_branches WHERE id = ?', [$branchId]);
};

if ($mode === 'cleanup') {
    $removeData();
    $snapshot = null;
    if (is_file($stateFile)) {
        $decoded = json_decode((string)file_get_contents($stateFile), true);
        @unlink($stateFile);
        if (is_array($decoded)) {
            $snapshot = $decoded;
        }
    }
    if (is_array($snapshot)) {
        $removeNewArchives(is_array($snapshot['archives'] ?? null) ? $snapshot['archives'] : []);
        $db->execute(
            "DELETE FROM audit_logs WHERE module = 'daily-ledger' AND action = 'report_export' AND id > ?",
            [(int)($snapshot['audit_max_id'] ?? 0)]
        );
    }
    echo "Reporting browser fixture removed.\n";
    exit(0);
}
if ($mode !== 'setup') {
    fwrite(STDERR, "Usage: php tests/daily-ledger/daily_ledger_reporting_browser_fixture.php setup|cleanup\n");
    exit(2);
}

// If a prior run crashed before cleanup, roll back the archives it added before
// taking a fresh snapshot, so the next cleanup cannot delete the wrong files.
if (is_file($stateFile)) {
    $previous = json_decode((string)file_get_contents($stateFile), true);
    @unlink($stateFile);
    if (is_array($previous)) {
        $removeNewArchives(is_array($previous['archives'] ?? null) ? $previous['archives'] : []);
    }
}

$removeData();

$snapshot = [
    'archives' => $listArchiveFiles(),
    'audit_max_id' => (int)$db->query('SELECT COALESCE(MAX(id), 0) FROM audit_logs')->fetchColumn(),
];
@file_put_contents($stateFile, json_encode($snapshot, JSON_UNESCAPED_SLASHES));

$db->execute('INSERT INTO dl_branches (id, code, name, is_active) VALUES (:id, :code, :name, 1)', [
    ':id' => $branchId,
    ':code' => 'BROWSER',
    ':name' => 'Browser Report Branch',
]);
$db->execute('INSERT INTO dl_products (id, sku, name, current_price, sort_order, is_active) VALUES (:id, :sku, :name, 10, 0, 1)', [
    ':id' => $productId,
    ':sku' => 'BRB-P1',
    ':name' => 'Browser Report Bread',
]);
$db->execute('INSERT INTO dl_branch_products (branch_id, product_id, is_active) VALUES (:b, :p, 1)', [
    ':b' => $branchId,
    ':p' => $productId,
]);

$hash = password_hash($password, PASSWORD_BCRYPT);
$db->execute('INSERT INTO dl_users (id, username, password_hash, full_name, role, is_active) VALUES (:id, :u, :p, :n, :r, 1)', [
    ':id' => $adminId,
    ':u' => 'browser-report-admin',
    ':p' => $hash,
    ':n' => 'Browser Report Admin',
    ':r' => 'admin',
]);
$db->execute('INSERT INTO dl_users (id, username, password_hash, full_name, role, is_active) VALUES (:id, :u, :p, :n, :r, 1)', [
    ':id' => $viewerId,
    ':u' => 'browser-report-viewer',
    ':p' => $hash,
    ':n' => 'Browser Report Viewer',
    ':r' => 'viewer',
]);

// One official AM row: sales 10 + 0 - 0 - 4 = 6, amount 6 * 10.00 = 60.00.
$db->execute(
    'INSERT INTO dl_daily_ledger (branch_id, product_id, ledger_date, shift, price_snapshot, beg_bal, addtl, withdraw, bal_end)
     VALUES (:b, :p, :d, :s, :price, :beg, 0, 0, :end)',
    [
        ':b' => $branchId,
        ':p' => $productId,
        ':d' => $ledgerDate,
        ':s' => 'AM',
        ':price' => 10,
        ':beg' => 10,
        ':end' => 4,
    ]
);

echo "Reporting browser fixture ready.\n";
