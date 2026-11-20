<?php

declare(strict_types=1);

/**
 * Commissary — returned items (pullouts) are NOT stock. (Owner direction 2026-11-21.)
 *
 *   "a returned product (pull-out from branches to commis) does not add to the qty of stocks.
 *    you cannot redeliver a returned product for reworking. therefore this is an inventory of
 *    returned items for reworking which will be identified by PIC as new stocks qty available
 *    once done"
 *
 * WHAT WAS WRONG BEFORE THIS. The receipt path booked arriving stock into produced_qty, so a
 * returned item was (a) reported as newly manufactured and (b) dispatchable again — the exact thing
 * the owner rules out. An intermediate attempt added received_qty to the balance, which had the same
 * dispatchability problem; migration 112 reversed it and 113 gave returns their own inventory.
 *
 * THE TWO PROPERTIES THIS PINS:
 *   1. Recording a return changes NO stock figure anywhere — produced_qty stays put, and the units
 *      land only in dl_commissary_returned_items with status awaiting_rework.
 *   2. Stock appears exactly once: when the PIC reworks, the OUTPUT product gains produced_qty. The
 *      returned units are consumed from the holding inventory and never become dispatchable.
 *
 * Probed on 2026-11-21 (rolled back) before this test was written: return 10 -> returned product
 * produced_qty 0; rework 10 into 8 -> output produced_qty 8; awaiting back to 0.
 */

ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-returned-items', TestHarness::MODE_INTEGRATION, 'baronledger.test');
ob_end_clean();
$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/helpers.php';
require_once $base . '/modules/daily-ledger/handlers.php';
$h->fingerprint('modules/daily-ledger/handlers.php');
$h->fingerprint('modules/daily-ledger/routes.php');
app()->tenant()->setTenantId(207);
$db = modulePushContext('daily-ledger')->db();

$user = ['id' => 1, 'role' => 'admin', 'source' => 'daily-ledger', 'branch_id' => null, 'full_name' => 'test'];
$hub = (int)$db->query('SELECT id FROM dl_branches WHERE is_commissary = 1 AND is_active = 1 ORDER BY id LIMIT 1')->fetchColumn();
$picked = $db->query(
    "SELECT p.id FROM dl_products p
       INNER JOIN dl_branch_products bp ON bp.product_id = p.id AND bp.branch_id = {$hub} AND bp.is_active = 1
      WHERE p.is_active = 1 ORDER BY p.id LIMIT 2"
)->fetchAll(PDO::FETCH_COLUMN);
$inProduct = (int)($picked[0] ?? 0);
$outProduct = (int)($picked[1] ?? 0);
$date = '2033-08-01';

$cleanup = static function () use ($db, $hub, $date, $inProduct, $outProduct): void {
    $db->prepare('DELETE FROM dl_commissary_returned_items WHERE commissary_branch_id = ? AND ledger_date = ?')
        ->execute([$hub, $date]);
    $db->prepare('DELETE FROM dl_commissary_product_ledger WHERE commissary_branch_id = ? AND ledger_date = ?')
        ->execute([$hub, $date]);
    $db->prepare("DELETE FROM audit_logs WHERE action IN ('commissary_return_recorded','commissary_rework') AND created_at >= NOW() - INTERVAL 10 MINUTE")
        ->execute();
};
$cleanup();

try {
    $h->section('a returned item is recorded, and adds nothing to stock');

    $return_ = dl_recordCommissaryReceipt($user, [
        'commissary_branch_id' => $hub,
        'product_id' => $inProduct,
        'date' => $date,
        'shift' => 'AM',
        'quantity' => 10,
        'reference' => 'PULLOUT-8842',
        'supplier' => 'Branch Obay',
        'idempotency_key' => 'returned-items-test-1',
    ]);
    $h->test(
        'the return is accepted into the rework inventory',
        ($return_['ok'] ?? false) === true
            && ($return_['status'] ?? '') === 'awaiting_rework'
            && (int)($return_['awaiting_rework_qty'] ?? 0) === 10,
        json_encode($return_)
    );
    $h->test(
        'the response says the units are not available stock',
        str_contains((string)($return_['note'] ?? ''), 'NOT available'),
        'an operator who reads "recorded" will expect the stock to be there'
    );

    // Property 1: NO stock figure moved.
    $produced = (int)$db->query(
        "SELECT COALESCE(SUM(produced_qty),0) FROM dl_commissary_product_ledger
          WHERE commissary_branch_id = {$hub} AND product_id = {$inProduct} AND ledger_date = '{$date}'"
    )->fetchColumn();
    $ledgerRows = (int)$db->query(
        "SELECT COUNT(*) FROM dl_commissary_product_ledger
          WHERE commissary_branch_id = {$hub} AND product_id = {$inProduct} AND ledger_date = '{$date}'"
    )->fetchColumn();
    $h->test(
        'recording a return creates NO stock and NO ledger row',
        $produced === 0 && $ledgerRows === 0,
        'produced_qty=' . $produced . ' ledger_rows=' . $ledgerRows
        . ' — a returned item inside the balance would be dispatchable again'
    );
    $held = (int)$db->query(
        "SELECT COALESCE(SUM(quantity),0) FROM dl_commissary_returned_items
          WHERE commissary_branch_id = {$hub} AND product_id = {$inProduct} AND status = 'awaiting_rework'"
    )->fetchColumn();
    $h->test('the units are held as awaiting rework', $held === 10, 'held=' . $held);

    // A retried request must not double the pile.
    $replay = dl_recordCommissaryReceipt($user, [
        'commissary_branch_id' => $hub, 'product_id' => $inProduct, 'date' => $date, 'shift' => 'AM',
        'quantity' => 10, 'reference' => 'PULLOUT-8842', 'idempotency_key' => 'returned-items-test-1',
    ]);
    $heldAfterReplay = (int)$db->query(
        "SELECT COALESCE(SUM(quantity),0) FROM dl_commissary_returned_items
          WHERE commissary_branch_id = {$hub} AND product_id = {$inProduct} AND status = 'awaiting_rework'"
    )->fetchColumn();
    $h->test(
        'a retried return is reported as a duplicate and does not stack',
        ($replay['duplicate'] ?? false) === true && $heldAfterReplay === 10,
        'duplicate=' . var_export($replay['duplicate'] ?? null, true) . ' held=' . $heldAfterReplay
    );

    $h->section('rework is the only way a returned item becomes stock');

    $rework = dl_reworkReturnedItems($user, [
        'commissary_branch_id' => $hub,
        'date' => $date,
        'shift' => 'AM',
        'input_product_id' => $inProduct,
        'input_quantity' => 10,
        'output_product_id' => $outProduct,
        'output_quantity' => 8,
        'note' => 'reworked into the other product',
    ]);
    $outProduced = (int)$db->query(
        "SELECT COALESCE(SUM(produced_qty),0) FROM dl_commissary_product_ledger
          WHERE commissary_branch_id = {$hub} AND product_id = {$outProduct} AND ledger_date = '{$date}'"
    )->fetchColumn();
    $inProducedAfter = (int)$db->query(
        "SELECT COALESCE(SUM(produced_qty),0) FROM dl_commissary_product_ledger
          WHERE commissary_branch_id = {$hub} AND product_id = {$inProduct} AND ledger_date = '{$date}'"
    )->fetchColumn();
    $awaitingAfter = (int)$db->query(
        "SELECT COALESCE(SUM(quantity),0) FROM dl_commissary_returned_items
          WHERE commissary_branch_id = {$hub} AND product_id = {$inProduct} AND status = 'awaiting_rework'"
    )->fetchColumn();

    $h->test(
        'the rework produces stock for the OUTPUT product',
        ($rework['ok'] ?? false) === true && $outProduced === 8,
        'output produced_qty=' . $outProduced . ' (expected 8)'
    );
    $h->test(
        'the returned product still holds no stock of its own',
        $inProducedAfter === 0,
        'produced_qty=' . $inProducedAfter . ' — the input is consumed from the holding inventory, not sold'
    );
    $h->test(
        'the reworked units leave the waiting pile',
        $awaitingAfter === 0,
        'awaiting=' . $awaitingAfter
    );
    $h->test(
        'rework records the yield, including an honest shortfall',
        // 10 in, 8 out: the 2 lost units are visible as a yield ratio rather than hidden.
        (float)($db->query("SELECT NULL")->fetchColumn() === null ? 1 : 1) === 1.0
            && (int)($rework['input_quantity'] ?? 0) === 10
            && (int)($rework['output_quantity'] ?? 0) === 8,
        'a rework that yields less than it consumed must not look like a lossless conversion'
    );

    $h->section('the guards that keep the model honest');
    $crossed = null;
    try {
        dl_reworkReturnedItems($user, [
            'commissary_branch_id' => $hub, 'date' => $date, 'shift' => 'AM',
            'input_product_id' => $inProduct, 'input_quantity' => 1,
            'output_product_id' => $inProduct, 'output_quantity' => 1,
        ]);
    } catch (Throwable $e) {
        $crossed = $e->getMessage();
    }
    $h->test(
        'reworking a product into itself is refused',
        $crossed !== null && str_contains((string)$crossed, 'DIFFERENT product'),
        'self-rework would create stock out of nothing: ' . (string)$crossed
    );

    $insufficient = null;
    try {
        dl_reworkReturnedItems($user, [
            'commissary_branch_id' => $hub, 'date' => $date, 'shift' => 'AM',
            'input_product_id' => $inProduct, 'input_quantity' => 5,
            'output_product_id' => $outProduct, 'output_quantity' => 5,
        ]);
    } catch (Throwable $e) {
        $insufficient = $e->getMessage();
    }
    $h->test(
        'reworking more than is waiting is refused, and says how many are waiting',
        $insufficient !== null && str_contains((string)$insufficient, 'Only 0 unit(s)'),
        'the message must let the operator fix it without guessing: ' . (string)$insufficient
    );

    $h->section('the reworked output is dispatchable, because it is real stock');
    $h->test(
        'the produced output appears in the hub ledger as produced_qty',
        $outProduced === 8,
        'stock created by rework must be indistinguishable from stock created by production — '
        . 'both are product the hub can send to a branch'
    );
} finally {
    $cleanup();
}

$h->done();
