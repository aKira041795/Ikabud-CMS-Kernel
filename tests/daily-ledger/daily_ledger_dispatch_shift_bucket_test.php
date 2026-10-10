<?php

declare(strict_types=1);

/**
 * A posted delivery's commissary dispatch MUST land in the delivery's own shift bucket.
 *
 * The defect this pins (measured on tenant 207, 2026-10-09, RIZAL-COMMIS1): the dispatch was applied
 * with no shift, so it landed in the legacy NULL bucket where the AM/PM-keyed Daily Sheet cannot see
 * it — while the same physical dispatch was also recorded in its shift bucket. Double count, negative
 * remainings, and an ending that could never reconcile. 3,754 dispatched had no movement behind them.
 *
 * The MUST-REFUSE case is the point: asserting only "AM is non-zero" would pass on the OLD code too,
 * because AM was written by the other path. The discriminating assertion is that the NULL bucket for
 * this product/date gains NOTHING.
 */
ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-dispatch-shift-bucket', TestHarness::MODE_INTEGRATION, 'baronledger.test');
ob_end_clean();

$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/helpers.php';
require_once $base . '/modules/daily-ledger/handlers-deliveries.php';
require_once $base . '/modules/daily-ledger/handlers.php';
$h->fingerprint('modules/daily-ledger/handlers.php');

app()->tenant()->setTenantId(207);
$ctx = modulePushContext('daily-ledger');
$db = $ctx->db();

$commissary = 99671;
$destBranch = 99672;
$product    = 99673;
$delivery   = 99674;
$date       = '2098-08-20';
$qty        = 7;

$cleanup = static function () use ($db, $commissary, $destBranch, $product, $delivery): void {
    $db->execute("DELETE FROM dl_delivery_ledger_effects WHERE delivery_id = {$delivery}");
    $db->execute("DELETE FROM dl_delivery_items WHERE delivery_id = {$delivery}");
    $db->execute("DELETE FROM dl_deliveries WHERE id = {$delivery}");
    $db->execute("DELETE FROM dl_commissary_product_ledger WHERE commissary_branch_id = {$commissary} OR product_id = {$product}");
    $db->execute("DELETE FROM dl_branch_products WHERE branch_id IN ({$commissary},{$destBranch}) OR product_id = {$product}");
    $db->execute("DELETE FROM dl_products WHERE id = {$product}");
    $db->execute("DELETE FROM dl_branches WHERE id IN ({$commissary},{$destBranch})");
};
$cleanup();

/** Reads the dispatched qty for one shift bucket; NULL and '' kept strictly apart. */
$dispatched = static function (string $bucket) use ($db, $commissary, $product, $date): int {
    $sql = $bucket === 'NULL'
        ? 'SELECT COALESCE(SUM(dispatched_qty),0) FROM dl_commissary_product_ledger
            WHERE commissary_branch_id = :c AND product_id = :p AND ledger_date = :d AND shift IS NULL'
        : 'SELECT COALESCE(SUM(dispatched_qty),0) FROM dl_commissary_product_ledger
            WHERE commissary_branch_id = :c AND product_id = :p AND ledger_date = :d AND shift = :s';
    $stmt = $db->prepare($sql);
    $args = [':c' => $commissary, ':p' => $product, ':d' => $date];
    if ($bucket !== 'NULL') { $args[':s'] = $bucket; }
    $stmt->execute($args);
    return (int)$stmt->fetchColumn();
};

try {
    $db->prepare('INSERT INTO dl_branches (id, code, name, is_commissary, is_active) VALUES (?, ?, ?, 1, 1)')
        ->execute([$commissary, 'DSB-C', 'DSB Commissary']);
    $db->prepare('INSERT INTO dl_branches (id, code, name, is_commissary, is_active) VALUES (?, ?, ?, 0, 1)')
        ->execute([$destBranch, 'DSB-D', 'DSB Destination']);
    $db->prepare('INSERT INTO dl_products (id, sku, name, current_price, is_active) VALUES (?, ?, ?, 10.00, 1)')
        ->execute([$product, 'DSB-P', 'DSB Product']);
    $db->prepare('INSERT INTO dl_branch_products (branch_id, product_id, is_active) VALUES (?, ?, 1)')
        ->execute([$commissary, $product]);
    // A POSTED commissary -> branch delivery carrying an explicit AM shift.
    $db->prepare('INSERT INTO dl_deliveries (id, delivery_date, production_shift, origin_type, origin_id, destination_type, destination_id, status)
                  VALUES (?, ?, "AM", "commissary", ?, "branch", ?, "posted")')
        ->execute([$delivery, $date, $commissary, $destBranch]);
    $db->prepare('INSERT INTO dl_delivery_items (delivery_id, product_id, quantity) VALUES (?, ?, ?)')
        ->execute([$delivery, $product, $qty]);

    $beforeAm = $dispatched('AM');
    $beforeNull = $dispatched('NULL');
    $beforePm = $dispatched('PM');

    $result = dl_applyPostedDeliveryCommissaryLedger($db, $delivery, 0);

    $afterAm = $dispatched('AM');
    $afterNull = $dispatched('NULL');
    $afterPm = $dispatched('PM');

    $h->section('A posted AM delivery debits the AM bucket');

    $h->test('the effect applied', ($result['status'] ?? '') === 'applied', json_encode($result));
    $h->test(
        'the AM bucket gained exactly the dispatched quantity',
        $afterAm - $beforeAm === $qty,
        "AM {$beforeAm} -> {$afterAm} (expected +{$qty})"
    );

    // MUST-REFUSE: this is what the old code failed. A NULL write here is the double count.
    $h->test(
        'MUST-REFUSE: the legacy NULL bucket gained NOTHING',
        $afterNull === $beforeNull,
        "NULL {$beforeNull} -> {$afterNull} — a gain here is the double count this fix removes"
    );

    $h->test('the PM bucket was not touched', $afterPm === $beforePm, "PM {$beforePm} -> {$afterPm}");
} finally {
    $cleanup();
}

$h->done();
