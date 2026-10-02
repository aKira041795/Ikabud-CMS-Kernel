#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Daily Ledger — end-to-end production journey browser fixture (tenant 207).
 *
 * Seeds a self-contained commissary / two destination branches / three products
 * / a production user (unbound, so the AM→PM toggle works) plus the
 * contract-required PM-bound production user / one cashier per destination
 * branch / one admin.  None of the existing tenant-207 accounts are touched.
 * A single historical delivery variance with NULL shifts is seeded so the admin
 * variance page's explicit "not recorded" rendering can be read in the browser.
 *
 * State (the pre-existing formal-delivery setting) is persisted between the
 * setup and cleanup CLI processes, and every fixture row is deleted on cleanup.
 *
 * Usage: php tests/daily-ledger/daily_ledger_production_journey_browser_fixture.php setup|cleanup
 *
 * Prints one machine-readable line on setup:
 *   JOURNEY_FIXTURE={"date":"YYYY-MM-DD","commissary":...,"branch_one":...}
 */

$basePath = dirname(__DIR__, 2);
require_once $basePath . '/src/helpers/cli-bootstrap.php';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/cli/daily-ledger/production-journey-fixture';
$app = kernelCliBootstrap($basePath);
$mode = $argv[1] ?? '';

$commissaryId = 98901;
$branchOneId  = 98902;
$branchTwoId  = 98903;
$productOneId = 98901;
$productTwoId = 98902;
$productThreeId = 98903;

$productionUserId = 98901;   // unbound — drives the AM→PM toggle journey
$productionPmUserId = 98902; // PM-shift-bound (contract seed requirement)
$cashierOneId = 98903;
$cashierTwoId = 98904;
$adminId      = 98905;

$password = 'Journey!207Pass';
$stateFile = STORAGE_PATH . '/daily-ledger-production-journey-browser-state.json';

$app->tenant()->setTenantId(207);
require_once $basePath . '/src/helpers/module-manager.php';
require_once $basePath . '/modules/daily-ledger/helpers.php';
require_once $basePath . '/modules/daily-ledger/handlers-deliveries.php';
require_once $basePath . '/modules/daily-ledger/handlers.php';
$context = modulePushContext('daily-ledger');
if (!$context) {
    fwrite(STDERR, "Daily Ledger module context unavailable.\n");
    exit(1);
}
$db = $context->db();
$date = dl_businessDate();

$removeData = static function () use ($db, $commissaryId, $branchOneId, $branchTwoId, $productOneId, $productTwoId, $productThreeId, $productionUserId, $productionPmUserId, $cashierOneId, $cashierTwoId, $adminId): void {
    $branches = "{$commissaryId},{$branchOneId},{$branchTwoId}";
    $products = "{$productOneId},{$productTwoId},{$productThreeId}";
    $users = "{$productionUserId},{$productionPmUserId},{$cashierOneId},{$cashierTwoId},{$adminId}";
    // Delivery-scoped children first (receiving items are FK'd to delivery items).
    $db->execute("DELETE FROM dl_integrity_notification_recipients WHERE notification_id IN (SELECT id FROM dl_integrity_notifications WHERE branch_id IN ({$branches}) OR aggregate_key LIKE 'delivery-variance-%')");
    $db->execute("DELETE FROM dl_integrity_notifications WHERE branch_id IN ({$branches}) OR aggregate_key LIKE 'delivery-variance-%'");
    $db->execute("DELETE FROM dl_delivery_variance_flags WHERE delivery_id IN (SELECT id FROM dl_deliveries WHERE origin_id IN ({$branches}) OR destination_id IN ({$branches})) OR product_id IN ({$products})");
    $db->execute("DELETE FROM dl_variance_flags WHERE branch_id IN ({$branches}) OR product_id IN ({$products})");
    $db->execute("DELETE FROM dl_delivery_ledger_effects WHERE delivery_id IN (SELECT id FROM dl_deliveries WHERE origin_id IN ({$branches}) OR destination_id IN ({$branches}))");
    $db->execute("DELETE FROM dl_branch_receiving_items WHERE receiving_id IN (SELECT id FROM dl_branch_receivings WHERE branch_id IN ({$branches})) OR product_id IN ({$products})");
    $db->execute("DELETE FROM dl_branch_receivings WHERE branch_id IN ({$branches}) OR delivery_id IN (SELECT id FROM dl_deliveries WHERE origin_id IN ({$branches}) OR destination_id IN ({$branches}))");
    $db->execute("DELETE FROM dl_delivery_items WHERE delivery_id IN (SELECT id FROM dl_deliveries WHERE origin_id IN ({$branches}) OR destination_id IN ({$branches})) OR product_id IN ({$products})");
    $db->execute("DELETE FROM dl_deliveries WHERE origin_id IN ({$branches}) OR destination_id IN ({$branches})");
    $db->execute("DELETE FROM dl_daily_ledger WHERE branch_id IN ({$branches}) OR product_id IN ({$products})");
    $db->execute("DELETE FROM dl_commissary_product_ledger WHERE commissary_branch_id IN ({$branches}) OR product_id IN ({$products})");
    $db->execute("DELETE FROM dl_production_movements WHERE destination_branch_id IN ({$branches}) OR product_id IN ({$products})");
    $db->execute("DELETE FROM dl_production_runs WHERE destination_branch_id IN ({$branches}) OR product_id IN ({$products})");
    $db->execute("DELETE FROM dl_ledger_shift_status WHERE branch_id IN ({$branches})");
    $db->execute("DELETE FROM dl_ledger_day_status WHERE branch_id IN ({$branches})");
    $db->execute("DELETE FROM audit_logs WHERE module = 'daily-ledger' AND branch_id IN ({$branches})");
    $db->execute("DELETE FROM dl_branch_products WHERE branch_id IN ({$branches}) OR product_id IN ({$products})");
    $db->execute("DELETE FROM dl_user_branches WHERE user_id IN ({$users}) OR branch_id IN ({$branches})");
    $db->execute("DELETE FROM dl_users WHERE id IN ({$users})");
    $db->execute("DELETE FROM dl_products WHERE id IN ({$products})");
    $db->execute("DELETE FROM dl_branches WHERE id IN ({$branches})");
};

// Restore the exact typed setting value, or delete it when it was absent.
$deleteTenantSettings = static function (array $keys) use ($app): void {
    if ($keys === []) {
        return;
    }
    \Ikabud\Kernel\Database\KernelPDO::kernelEscalationEnter();
    try {
        $stmt = $app->db()->prepare(
            'DELETE FROM ' . moduleTenantSettingsTable()
            . ' WHERE tenant_id = ? AND module_id = ? AND setting_key = ?'
        );
        foreach ($keys as $key) {
            $stmt->execute([207, 'daily-ledger', (string)$key]);
        }
    } finally {
        \Ikabud\Kernel\Database\KernelPDO::kernelEscalationLeave();
        if (function_exists('invalidateTenantModuleSettingsCache')) {
            invalidateTenantModuleSettingsCache();
        }
    }
};

$restoreSettings = static function () use ($stateFile, $deleteTenantSettings): void {
    $keys = ['formal_delivery_workflow_enabled'];
    $decoded = [];
    if (is_file($stateFile)) {
        $decoded = json_decode((string)file_get_contents($stateFile), true);
        @unlink($stateFile);
    }
    if (!is_array($decoded)) {
        $decoded = [];
    }
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
    echo "Production journey fixture removed.\n";
    exit(0);
}
if ($mode !== 'setup') {
    fwrite(STDERR, "Usage: php tests/daily-ledger/daily_ledger_production_journey_browser_fixture.php setup|cleanup\n");
    exit(2);
}

$removeData();

$originalRaw = getModuleSettings('daily-ledger');
$snapshot = ['values' => [], 'absent' => []];
foreach (['formal_delivery_workflow_enabled'] as $key) {
    if (array_key_exists($key, $originalRaw)) {
        $snapshot['values'][$key] = $originalRaw[$key];
    } else {
        $snapshot['absent'][] = $key;
    }
}
if (!is_file($stateFile)) {
    @file_put_contents($stateFile, json_encode($snapshot, JSON_UNESCAPED_SLASHES));
}
saveModuleSettings('daily-ledger', ['formal_delivery_workflow_enabled' => '1']);
if (function_exists('invalidateTenantModuleSettingsCache')) {
    invalidateTenantModuleSettingsCache();
}

// ── Branches ──────────────────────────────────────────────────────────────
$db->execute(
    'INSERT INTO dl_branches (id, code, name, is_commissary, assigned_commissary_id, default_supply_mode, sort_order, is_active)
     VALUES (:id, :code, :name, 1, NULL, "self_managed", 1, 1)',
    [':id' => $commissaryId, ':code' => 'JRN-COMM', ':name' => 'Journey Commissary']
);
foreach ([[$branchOneId, 'JRN-B1', 'Journey Branch One'], [$branchTwoId, 'JRN-B2', 'Journey Branch Two']] as [$id, $code, $name]) {
    $db->execute(
        'INSERT INTO dl_branches (id, code, name, is_commissary, assigned_commissary_id, default_supply_mode, sort_order, is_active)
         VALUES (:id, :code, :name, 0, :comm, "commissary_supplied", 2, 1)',
        [':id' => $id, ':code' => $code, ':name' => $name, ':comm' => $commissaryId]
    );
}

// ── Products + per-branch linkage ─────────────────────────────────────────
$products = [
    [$productOneId, 'JRN-P1', 'Journey Product One', '10.00', 'bread'],
    [$productTwoId, 'JRN-P2', 'Journey Product Two', '5.00', 'bread'],
    [$productThreeId, 'JRN-P3', 'Journey Product Three', '7.00', 'cake'],
];
foreach ($products as [$pid, $sku, $name, $price, $category]) {
    $db->execute(
        'INSERT INTO dl_products (id, sku, name, product_category, current_price, sort_order, is_active)
         VALUES (:id, :sku, :name, :cat, :price, :sort, 1)',
        [':id' => $pid, ':sku' => $sku, ':name' => $name, ':cat' => $category, ':price' => $price, ':sort' => $pid]
    );
    foreach ([$commissaryId, $branchOneId, $branchTwoId] as $bid) {
        $db->execute(
            'INSERT INTO dl_branch_products (branch_id, product_id, is_active) VALUES (:b, :p, 1)',
            [':b' => $bid, ':p' => $pid]
        );
    }
}

// ── Users ─────────────────────────────────────────────────────────────────
$hash = password_hash($password, PASSWORD_BCRYPT);
$users = [
    [$productionUserId, 'browser-journey-prod', 'Journey Producer', 'production_in_charge', null],
    [$productionPmUserId, 'browser-journey-prod-pm', 'Journey PM Producer', 'production_in_charge', 'PM'],
    [$cashierOneId, 'browser-journey-cashier-1', 'Journey Cashier One', 'cashier', null],
    [$cashierTwoId, 'browser-journey-cashier-2', 'Journey Cashier Two', 'cashier', null],
    [$adminId, 'browser-journey-admin', 'Journey Admin', 'admin', null],
];
foreach ($users as [$id, $username, $fullName, $role, $shift]) {
    $db->execute(
        'INSERT INTO dl_users (id, username, password_hash, full_name, role, shift, is_active)
         VALUES (:id, :u, :p, :n, :r, :s, 1)',
        [':id' => $id, ':u' => $username, ':p' => $hash, ':n' => $fullName, ':r' => $role, ':s' => $shift]
    );
}
$db->execute('INSERT INTO dl_user_branches (user_id, branch_id) VALUES (:u, :b)', [':u' => $productionUserId, ':b' => $commissaryId]);
$db->execute('INSERT INTO dl_user_branches (user_id, branch_id) VALUES (:u, :b)', [':u' => $productionPmUserId, ':b' => $commissaryId]);
$db->execute('INSERT INTO dl_user_branches (user_id, branch_id) VALUES (:u, :b)', [':u' => $cashierOneId, ':b' => $branchOneId]);
$db->execute('INSERT INTO dl_user_branches (user_id, branch_id) VALUES (:u, :b)', [':u' => $cashierTwoId, ':b' => $branchTwoId]);

// ── Historical NULL-shift delivery variance (proves the explicit "not recorded") ──
$histDr = 'JRN-HIST-' . $branchTwoId;
$db->execute(
    'INSERT INTO dl_deliveries (origin_type, origin_id, resolved_origin_id, destination_type, destination_id, dr_number,
        delivery_date, production_shift, status, created_by, posted_by, posted_at, remarks, receipt_required)
     VALUES ("commissary", NULL, NULL, "branch", :dest, :dr, :d, NULL, "posted", :created, :posted, NOW(), "[journey-historical]", 0)',
    [':dest' => $branchTwoId, ':dr' => $histDr, ':d' => $date, ':created' => $adminId, ':posted' => $adminId]
);
$histDeliveryId = (int)$db->lastInsertId();
$db->execute(
    'INSERT INTO dl_delivery_items (delivery_id, product_id, quantity, unit, price_snapshot) VALUES (:d, :p, 10, "pcs", 10)',
    [':d' => $histDeliveryId, ':p' => $productOneId]
);
$histDeliveryItemId = (int)$db->lastInsertId();
$db->execute(
    'INSERT INTO dl_branch_receivings (branch_id, origin_type, origin_id, delivery_id, dr_number, received_by, received_at,
        received_ledger_date, received_shift, status, posted_by, posted_at, remarks, count_basis)
     VALUES (:b, "commissary", NULL, :d, :dr, :recv, NOW(), :date, NULL, "posted", :posted, NOW(), "[journey-historical]", "independently_counted")',
    [':b' => $branchTwoId, ':d' => $histDeliveryId, ':dr' => $histDr, ':recv' => $adminId, ':posted' => $adminId, ':date' => $date]
);
$histReceivingId = (int)$db->lastInsertId();
$db->execute(
    'INSERT INTO dl_branch_receiving_items (receiving_id, delivery_item_id, product_id, quantity_received, unit)
     VALUES (:r, :di, :p, 8, "pcs")',
    [':r' => $histReceivingId, ':di' => $histDeliveryItemId, ':p' => $productOneId]
);
$db->execute(
    'INSERT INTO dl_variance_flags (branch_id, product_id, ledger_date, kind, shift, delivery_id, receiving_id,
        sent_qty, received_qty, original_counted_qty, counted_by, expected_end_bal, recorded_end_bal, variance, resolution_status, is_reviewed)
     VALUES (:b, :p, :d, "delivery", NULL, :delivery, :rcv, 10, 8, 8, :u, 10, 8, -2, "unreviewed", 0)',
    [':b' => $branchTwoId, ':p' => $productOneId, ':d' => $date, ':delivery' => $histDeliveryId, ':rcv' => $histReceivingId, ':u' => $adminId]
);

echo "JOURNEY_FIXTURE=" . json_encode([
    'date' => $date,
    'commissary' => $commissaryId,
    'branch_one' => $branchOneId,
    'branch_two' => $branchTwoId,
    'product_one' => $productOneId,
    'product_two' => $productTwoId,
    'product_three' => $productThreeId,
    'production_user' => 'browser-journey-prod',
    'production_pm_user' => 'browser-journey-prod-pm',
    'cashier_one' => 'browser-journey-cashier-1',
    'cashier_two' => 'browser-journey-cashier-2',
    'admin' => 'browser-journey-admin',
    'hist_dr' => $histDr,
], JSON_UNESCAPED_SLASHES) . "\n";
echo "Production journey fixture ready.\n";
