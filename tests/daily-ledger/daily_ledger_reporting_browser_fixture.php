#!/usr/bin/env php
<?php

declare(strict_types=1);

$basePath = dirname(__DIR__, 2);
require_once $basePath . '/src/helpers/cli-bootstrap.php';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/cli/daily-ledger/browser-fixture';
$app = kernelCliBootstrap($basePath);
$mode = $argv[1] ?? '';
$userId = 99202;
$branchId = 99202;
$productId = 99202;
$date = '2031-03-15';

if ($mode === 'host') {
    $stmt = $app->controlDb()->prepare('SELECT domain FROM kernel_tenant_domains WHERE tenant_id = ? ORDER BY domain');
    $stmt->execute([207]);
    echo implode(PHP_EOL, $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []) . PHP_EOL;
    exit(0);
}
$app->tenant()->setTenantId(207);
require_once $basePath . '/src/helpers/module-manager.php';
$context = modulePushContext('daily-ledger');
if (!$context) {
    fwrite(STDERR, "Daily Ledger module context unavailable.\n");
    exit(1);
}
$db = $context->db();

$cleanup = static function () use ($db, $userId, $branchId, $productId, $basePath): void {
    foreach (glob($basePath . '/storage/report-archive/*.json') ?: [] as $metaPath) {
        $meta = json_decode((string)file_get_contents($metaPath), true);
        if (!is_array($meta) || (int)($meta['generated_by_id'] ?? 0) !== $userId) continue;
        $archiveId = (string)($meta['id'] ?? basename($metaPath, '.json'));
        $db->prepare("DELETE FROM audit_logs WHERE module = 'daily-ledger' AND action = 'report_export' AND entity_id = ?")->execute([$archiveId]);
        if (is_file((string)($meta['file'] ?? ''))) @unlink((string)$meta['file']);
        @unlink($metaPath);
    }
    $db->execute('DELETE FROM dl_ledger_shift_status WHERE branch_id = :id', [':id' => $branchId]);
    $db->execute('DELETE FROM dl_daily_ledger WHERE branch_id = :id', [':id' => $branchId]);
    $db->execute('DELETE FROM dl_user_branches WHERE user_id = :id OR branch_id = :branch', [':id' => $userId, ':branch' => $branchId]);
    $db->execute('DELETE FROM dl_branch_products WHERE branch_id = :id', [':id' => $branchId]);
    $db->execute('DELETE FROM dl_users WHERE id = :id', [':id' => $userId]);
    $db->execute('DELETE FROM dl_branches WHERE id = :id', [':id' => $branchId]);
    $db->execute('DELETE FROM dl_products WHERE id = :id', [':id' => $productId]);
};

if ($mode === 'cleanup') {
    $cleanup();
    echo "Browser fixture removed.\n";
    exit(0);
}
if ($mode !== 'setup') {
    fwrite(STDERR, "Usage: php tests/daily-ledger/daily_ledger_reporting_browser_fixture.php setup|cleanup\n");
    exit(2);
}

$cleanup();
$db->execute('INSERT INTO dl_branches (id, code, name, is_active) VALUES (:id, :code, :name, 1)', [':id' => $branchId, ':code' => 'BROWSER', ':name' => 'Browser Report Branch']);
$db->execute('INSERT INTO dl_products (id, sku, name, current_price, sort_order, is_active) VALUES (:id, :sku, :name, 25, 0, 1)', [':id' => $productId, ':sku' => 'BROWSER-1', ':name' => 'Browser Report Bread']);
$db->execute('INSERT INTO dl_branch_products (branch_id, product_id, is_active) VALUES (:branch, :product, 1)', [':branch' => $branchId, ':product' => $productId]);
$db->execute('INSERT INTO dl_users (id, username, password_hash, full_name, role, is_active) VALUES (:id, :username, :password, :name, :role, 1)', [
    ':id' => $userId,
    ':username' => 'browser-report-admin',
    ':password' => password_hash('BrowserReport!2031', PASSWORD_BCRYPT),
    ':name' => 'Browser Report Admin',
    ':role' => 'admin',
]);
$db->execute("INSERT INTO dl_daily_ledger (branch_id, product_id, ledger_date, shift, price_snapshot, beg_bal, addtl, withdraw, bal_end, sales) VALUES (:branch, :product, :date, 'AM', 25, 10, 0, 0, 4, 6)", [':branch' => $branchId, ':product' => $productId, ':date' => $date]);
echo "Browser fixture ready.\n";
