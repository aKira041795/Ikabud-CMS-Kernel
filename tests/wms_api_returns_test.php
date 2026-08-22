<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────
// WMS API Returns — live-schema contract regression test
//
// Guards the WMS API return handlers (wmsApiReturnCreate / wmsApiReturnProcess
// in handlers/60-api-advanced.php) against the LIVE wms_returns /
// wms_return_items schema. Migration 007's CREATE TABLE IF NOT EXISTS never
// upgraded the pre-existing tables, so the live schema uses reference_number /
// qty_returned / qty_restocked / location_id (NOT return_number / quantity /
// disposition) and wms_returns.status is enum(pending, inspecting, restocked,
// disposed, cancelled). Each handler statement is executed in a rolled-back
// transaction so a schema mismatch fails deterministically without side effects.
// ─────────────────────────────────────────────────────────────────────────

$_SERVER['HTTP_HOST'] = 'cmsnew.test';
$_SERVER['REQUEST_URI'] = '/api/v1/wms/returns';

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../modules/wms/helpers.php';
require_once __DIR__ . '/../modules/wms/handlers/00-bootstrap.php';
require_once __DIR__ . '/../modules/wms/handlers/60-api-advanced.php';

$pass = 0;
$fail = 0;
$errors = [];
$fixture = ['warehouse' => 0, 'location' => 0, 'product' => 0];

function wt(string $label, bool $ok, string $detail = ''): void
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

function wtExec(PDO $db, string $sql, array $params = []): void
{
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
}

function wtTry(string $label, PDO $db, string $sql, array $params): void
{
    $db->beginTransaction();
    try {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $db->rollBack();
        wt($label, true);
    } catch (\Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $state = $e instanceof \PDOException ? ($e->errorInfo[0] ?? '') : '';
        wt($label, false, '[' . $state . '] ' . preg_replace('/\s+/', ' ', $e->getMessage()));
    }
}

echo "\n=== WMS API RETURNS — LIVE SCHEMA CONTRACT ===\n\n";

$db = app()->db();

// Fixture: warehouse + active location + product.
$suffix = substr(bin2hex(random_bytes(4)), 0, 6);
wtExec($db, 'INSERT INTO wms_warehouses (code, name, is_active, created_at, updated_at) VALUES (?, ?, 1, NOW(), NOW())', ['RT-' . strtoupper($suffix), 'Returns Test ' . $suffix]);
$fixture['warehouse'] = (int)$db->lastInsertId();
wtExec($db, 'INSERT INTO wms_locations (warehouse_id, parent_id, code, name, type, capacity, capacity_unit, sort_order, is_active, is_staging, created_at, updated_at) VALUES (?, NULL, ?, ?, ?, NULL, NULL, 0, 1, 0, NOW(), NOW())', [$fixture['warehouse'], 'RTL-' . strtoupper($suffix), 'Returns Loc ' . $suffix, 'bin']);
$fixture['location'] = (int)$db->lastInsertId();
wtExec($db, 'INSERT INTO wms_products (sku, barcode, name, unit, product_type, is_batch_tracked, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 0, 1, NOW(), NOW())', ['WMS-RT-' . strtoupper($suffix), 'WMS-RT-' . strtoupper($suffix), 'Returns Product ' . $suffix, 'pcs', 'physical']);
$fixture['product'] = (int)$db->lastInsertId();

$wid = $fixture['warehouse'];
$lid = $fixture['location'];
$pid = $fixture['product'];

try {
    // wmsApiReturnCreate header insert — live schema (reference_number + meta).
    wtTry(
        'create header: reference_number/meta columns',
        $db,
        'INSERT INTO wms_returns (reference_number, order_id, customer_name, warehouse_id, status, reason, received_at, notes, meta, created_by, created_at, updated_at)
         VALUES (:rn, :oid, :cn, :wid, :status, :reason, NOW(), :notes, :meta, :uid, NOW(), NOW())',
        [':rn' => 'RMA-' . $suffix, ':oid' => null, ':cn' => 'Test', ':wid' => $wid, ':status' => 'pending',
         ':reason' => null, ':notes' => null, ':meta' => '{"return_type":"customer","customer_email":"t@t"}', ':uid' => 1]
    );

    // wmsApiReturnCreate item insert — live schema (location_id NOT NULL).
    wtTry(
        'create item: qty_returned/location_id/condition columns',
        $db,
        'INSERT INTO wms_return_items (return_id, product_id, location_id, batch_id, qty_returned, `condition`, notes)
         VALUES (:rid, :pid, :lid, :bid, :qty, :cond, :notes)',
        [':rid' => 1, ':pid' => $pid, ':lid' => $lid, ':bid' => null, ':qty' => 1.0, ':cond' => 'good', ':notes' => null]
    );

    // wmsApiReturnProcess approve/reject — status only (enum-safe mapping).
    wtTry(
        'process approve: enum-safe status update',
        $db,
        'UPDATE wms_returns SET status = :status WHERE id = :id',
        [':status' => 'inspecting', ':id' => 0]
    );
    wtTry(
        'process reject: enum-safe status update',
        $db,
        'UPDATE wms_returns SET status = :status WHERE id = :id',
        [':status' => 'cancelled', ':id' => 0]
    );

    // wmsApiReturnProcess restock — stock upsert + movement + item restock cols.
    wtTry(
        'restock: wms_stock insert',
        $db,
        'INSERT INTO wms_stock (product_id, warehouse_id, location_id, batch_id, qty_on_hand, last_movement_at)
         VALUES (:pid, :wid, :lid, :bid, :qty, NOW())',
        [':pid' => $pid, ':wid' => $wid, ':lid' => $lid, ':bid' => null, ':qty' => 2.0]
    );
    wtTry(
        'restock: wms_stock_movements return_in insert',
        $db,
        'INSERT INTO wms_stock_movements (product_id, warehouse_id, from_location_id, to_location_id, batch_id, movement_type, quantity, prev_qty_on_hand, new_qty_on_hand, reference_type, reference_id, notes, created_by)
         VALUES (:pid, :wid, :fl, :tl, :bid, :type, :qty, :prev, :new, :rtype, :rid, :notes, :uid)',
        [':pid' => $pid, ':wid' => $wid, ':fl' => null, ':tl' => $lid, ':bid' => null, ':type' => 'return_in',
         ':qty' => 2.0, ':prev' => 0.0, ':new' => 2.0, ':rtype' => 'return', ':rid' => 1, ':notes' => 'Return restock', ':uid' => 1]
    );
    wtTry(
        'restock: wms_return_items qty_restocked/restock_movement_id update',
        $db,
        'UPDATE wms_return_items SET qty_restocked = :q, restock_movement_id = :mid WHERE id = :id',
        [':q' => 2.0, ':mid' => 3, ':id' => 0]
    );

    // Handler functions must resolve (routes reference them).
    wt('handlers defined (wmsApiReturnCreate/Process)', function_exists('wmsApiReturnCreate') && function_exists('wmsApiReturnProcess'));
} finally {
    wtExec($db, 'DELETE FROM wms_products WHERE id = ?', [$pid]);
    wtExec($db, 'DELETE FROM wms_locations WHERE id = ?', [$lid]);
    wtExec($db, 'DELETE FROM wms_warehouses WHERE id = ?', [$wid]);
}

$appLog = @file_get_contents(STORAGE_PATH . '/logs/app.log') ?: '';
$errorLog = @file_get_contents(STORAGE_PATH . '/logs/error.log') ?: '';
wt('no app.log critical errors', !str_contains($appLog, '[critical]'), trim($appLog));
wt('no PHP errors in error.log', trim($errorLog) === '', trim($errorLog));

echo "\n══════════════════════════════════════════════════\n";
echo "  PASS: {$pass}  FAIL: {$fail}\n";
echo "══════════════════════════════════════════════════\n";

if ($errors !== []) {
    echo "\nFailed tests:\n";
    foreach ($errors as $error) {
        echo '  - ' . $error . "\n";
    }
}

exit($fail > 0 ? 1 : 0);
