<?php

declare(strict_types=1);

/**
 * Received (purchased) stock at a hub — the missing inflow.
 *
 * WHY THIS EXISTS. On tenant 207 at 2026-11-20, 56 of the 128 products carrying a negative hub
 * balance had NEVER had a single production entry: they are bought-in goods. The hub ledger has one
 * inflow column and, until this feature, no writer for a purchase — so dispatching an item that was
 * bought rather than made debited a balance nothing could ever credit, and carry-forward kept it
 * negative forever. `direct_purchase` had existed as a supply classification since migration 023
 * with no writer at all.
 *
 * The assertions that matter are the two at the ends:
 *   - before a receipt, dl_commissaryOpeningPositionMissing() says the product has no opening — the
 *     exact condition that refuses the first outbound;
 *   - after the receipt, that same dispatch is ACCEPTED and the balance moves truthfully.
 * The middle section pins the documentary requirements, because a receipt with no supplier reference
 * is an adjustment wearing a receipt's name.
 */

ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-commissary-receipt', TestHarness::MODE_INTEGRATION, 'baronledger.test');
ob_end_clean();

$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/helpers.php';
require_once $base . '/modules/daily-ledger/helpers/reporting.php';
require_once $base . '/modules/daily-ledger/handlers.php';
app()->tenant()->setTenantId(207);
$ctx = modulePushContext('daily-ledger');
$db = $ctx->db();

$hub = 99771;
$plainBranch = 99772;
$boughtIn = 99773;   // assigned to the hub, never produced, no ledger history
$loose = 99774;      // active product NOT assigned to the hub
$day = '2033-04-11';

$cleanup = static function () use ($db, $hub, $plainBranch, $boughtIn, $loose): void {
    $db->prepare('DELETE FROM dl_production_movements WHERE destination_branch_id IN (?, ?)')->execute([$hub, $plainBranch]);
    // The returned-items inventory (migration 113) outlives a ledger cleanup, and its client_op_id
    // is UNIQUE — so a leftover row makes the next run look like a DUPLICATE request. It must be
    // cleared with everything else.
    $db->prepare('DELETE FROM dl_commissary_returned_items WHERE commissary_branch_id IN (?, ?)')->execute([$hub, $plainBranch]);
    $db->prepare('DELETE FROM dl_commissary_product_ledger WHERE commissary_branch_id IN (?, ?)')->execute([$hub, $plainBranch]);
    $db->prepare('DELETE FROM dl_ledger_shift_status WHERE branch_id IN (?, ?)')->execute([$hub, $plainBranch]);
    $db->prepare('DELETE FROM dl_ledger_day_status WHERE branch_id IN (?, ?)')->execute([$hub, $plainBranch]);
    $db->prepare('DELETE FROM audit_logs WHERE branch_id IN (?, ?) AND action IN (?, ?)')->execute([$hub, $plainBranch, 'commissary_stock_received', 'commissary_return_recorded']);
    $db->prepare('DELETE FROM dl_user_branches WHERE user_id IN (1, 2, 3, 99779)')->execute();
    $db->prepare('DELETE FROM dl_users WHERE id = 99779')->execute();
    $db->prepare('DELETE FROM dl_branch_products WHERE branch_id IN (?, ?) OR product_id IN (?, ?)')->execute([$hub, $plainBranch, $boughtIn, $loose]);
    $db->prepare('DELETE FROM dl_products WHERE id IN (?, ?)')->execute([$boughtIn, $loose]);
    $db->prepare('DELETE FROM dl_branches WHERE id IN (?, ?)')->execute([$hub, $plainBranch]);
};

$cleanup();
try {
    $db->prepare('INSERT INTO dl_branches (id, code, name, default_supply_mode, is_commissary, is_active) VALUES (?, ?, ?, ?, 1, 1)')
        ->execute([$hub, 'RCPT-HUB', 'Receipt Commissary', 'self_managed']);
    $db->prepare('INSERT INTO dl_branches (id, code, name, default_supply_mode, is_commissary, is_active) VALUES (?, ?, ?, ?, 0, 1)')
        ->execute([$plainBranch, 'RCPT-BRN', 'Receipt Plain Branch', 'commissary_supplied']);
    $db->prepare('INSERT INTO dl_products (id, sku, name, current_price, sort_order, is_active) VALUES (?, ?, ?, 28, 0, 1)')
        ->execute([$boughtIn, 'RCPT-SOFTDRINK', 'SOFTDRINKS: MISMO @28']);
    $db->prepare('INSERT INTO dl_products (id, sku, name, current_price, sort_order, is_active) VALUES (?, ?, ?, 10, 0, 1)')
        ->execute([$loose, 'RCPT-LOOSE', 'Not Assigned Product']);
    $db->prepare('INSERT INTO dl_branch_products (branch_id, product_id, is_active) VALUES (?, ?, 1)')->execute([$hub, $boughtIn]);
    // The production-in-charge is scoped by dl_user_branches, which FKs to dl_users, so the PIC path
    // is only real when a real user row and assignment exist. Without them the actor has an empty
    // scope and the route is unreachable for the very role it is meant to serve.
    $db->prepare('INSERT INTO dl_users (id, username, password_hash, full_name, role) VALUES (99779, ?, ?, ?, ?)')
        ->execute(['rcpt-pic-test', 'x', 'Receipt PIC (test)', 'production_in_charge']);
    $db->prepare('INSERT INTO dl_user_branches (user_id, branch_id) VALUES (99779, ?)')->execute([$hub]);

    $admin = ['id' => 1, 'username' => 'receipt-admin', 'name' => 'Receipt Admin', 'role' => 'admin', 'source' => 'daily-ledger'];
    $pic = ['id' => 99779, 'username' => 'rcpt-pic-test', 'name' => 'Receipt PIC', 'role' => 'production_in_charge', 'source' => 'daily-ledger'];
    $cashier = ['id' => 3, 'username' => 'receipt-cashier', 'name' => 'Receipt Cashier', 'role' => 'cashier', 'source' => 'daily-ledger'];

    // ── Section 1: the gap is real before the receipt ───────────────────────────────────────
    $h->section('Before a receipt, the product genuinely has no opening position');
    $h->test(
        'the hub ledger has no row for this bought-in product at all',
        dl_commissaryOpeningPositionMissing($db, $hub, $boughtIn, $day) === true,
        'this is the condition that refuses the first outbound dispatch - it is the negative-stock origin'
    );

    $refusedFirst = false;
    try {
        dl_applyCommissaryProductLedgerDelta($db, $hub, $boughtIn, $day, 0, 20, 1, 0, false, 'AM');
    } catch (Throwable $e) {
        $refusedFirst = str_contains($e->getMessage(), 'No opening stock is recorded');
    }
    $h->test(
        'dispatching it first is REFUSED (the guard is doing its job)',
        $refusedFirst,
        'without a receipt, the only way in is a negative balance - the defect this feature closes'
    );

    // ── Section 2: record the receipt ──────────────────────────────────────────────────────
    $h->section('Recording a return (pullout)');
    $receipt = dl_recordCommissaryReceipt($pic, [
        'commissary_branch_id' => $hub,
        'product_id' => $boughtIn,
        'date' => $day,
        'shift' => 'AM',
        'quantity' => 50,
        'reference' => 'PULLOUT-8891',
        'supplier' => 'Branch Obay',
        'remarks' => 'returned 07:40',
        'idempotency_key' => 'rcpt-test-1',
    ]);
    $h->test(
        'the return goes into the rework inventory, not into stock',
        // Owner direction 2026-11-21: "a returned product does not add to the qty of stocks. you
        // cannot redeliver a returned product for reworking." This endpoint used to credit
        // produced_qty — reporting a returned item as newly manufactured AND making it dispatchable.
        // It now writes dl_commissary_returned_items and touches no ledger column at all.
        ($receipt['ok'] ?? false) === true
            && ($receipt['status'] ?? '') === 'awaiting_rework'
            && (int)($receipt['quantity'] ?? 0) === 50
            && (int)($receipt['awaiting_rework_qty'] ?? 0) === 50,
        json_encode($receipt)
    );
    $h->test(
        'no stock movement is written for a return',
        // A movement row carries a quantity; putting a return there would place units into stock
        // movement reporting that no balance contains.
        (int)$db->query(
            "SELECT COUNT(*) FROM dl_production_movements WHERE dr_number = 'PULLOUT-8891'"
        )->fetchColumn() === 0,
        'a return is not a stock movement'
    );
    $h->test(
        'no ledger row is created for a return',
        (int)$db->query(
            "SELECT COUNT(*) FROM dl_commissary_product_ledger
              WHERE commissary_branch_id = {$hub} AND product_id = {$boughtIn} AND ledger_date = '{$day}'"
        )->fetchColumn() === 0,
        'the holding inventory is deliberately outside the ledger'
    );

    $audit = $db->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'commissary_return_recorded' AND branch_id = " . $hub)->fetchColumn();
    $h->test('the return is audited', (int)$audit === 1, 'audit rows=' . $audit);

    // ── Section 3: a return must NOT make the product dispatchable ────────────────────────
    $h->section('A returned item does not authorise a dispatch');
    $h->test(
        'the product STILL reads as missing an opening position',
        dl_commissaryOpeningPositionMissing($db, $hub, $boughtIn, $day) === true,
        'this is the owner rule in one line: returned stock cannot be re-delivered, so it must not '
        . 'turn an outbound movement from a phantom into a dispatch'
    );
    $dispatchRefused = null;
    try {
        dl_applyCommissaryProductLedgerDelta($db, $hub, $boughtIn, $day, 0, 20, 1, 0, true, 'AM');
    } catch (Throwable $e) {
        $dispatchRefused = $e->getMessage();
    }
    $h->test(
        'dispatching a returned product is still refused',
        $dispatchRefused !== null && str_contains((string)$dispatchRefused, 'No opening stock is recorded'),
        'the refusal is the point: ' . (string)$dispatchRefused
    );

    // ── Section 4: retry safety ───────────────────────────────────────────────────────────
    $h->section('A replayed request must not record the return twice');
    $replay = dl_recordCommissaryReceipt($pic, [
        'commissary_branch_id' => $hub,
        'product_id' => $boughtIn,
        'date' => $day,
        'shift' => 'AM',
        'quantity' => 50,
        'reference' => 'PULLOUT-8891',
        'idempotency_key' => 'rcpt-test-1',
    ]);
    $heldNow = (int)$db->query(
        "SELECT COALESCE(SUM(quantity),0) FROM dl_commissary_returned_items
          WHERE commissary_branch_id = {$hub} AND product_id = {$boughtIn} AND status = 'awaiting_rework'"
    )->fetchColumn();
    $h->test(
        'the replay is reported as a duplicate and the pile is unchanged',
        ($replay['duplicate'] ?? false) === true && $heldNow === 50,
        'duplicate=' . var_export($replay['duplicate'] ?? null, true) . ' awaiting=' . $heldNow . ' (expected 50)'
    );

    // ── Section 5: documentary requirements ──────────────────────────────────────────────
    $h->section('What the receipt refuses');
    $expectRefusal = static function (array $payload, array $actor, string $needle) use ($db, $hub, $boughtIn, $day): array {
        try {
            dl_recordCommissaryReceipt($actor, $payload + [
                'commissary_branch_id' => $hub, 'product_id' => $boughtIn, 'date' => $day, 'shift' => 'AM', 'quantity' => 5,
            ]);
            return [false, 'accepted - it should have been refused'];
        } catch (Throwable $e) {
            return [str_contains($e->getMessage(), $needle), $e->getMessage()];
        }
    };
    [$okNoRef, $msgNoRef] = $expectRefusal(['reference' => ''], $pic, 'supplier document reference');
    $h->test('a receipt with no supplier document is refused', $okNoRef, $msgNoRef);

    [$okZero, $msgZero] = $expectRefusal(['reference' => 'X-1', 'quantity' => 0], $pic, 'a quantity above zero');
    $h->test('a receipt for zero units is refused', $okZero, $msgZero);

    [$okCashier, $msgCashier] = $expectRefusal(['reference' => 'X-1'], $cashier, 'office or the production-in-charge');
    $h->test('a cashier cannot record received stock', $okCashier, $msgCashier);

    try {
        dl_recordCommissaryReceipt($pic, [
            'commissary_branch_id' => $plainBranch, 'product_id' => $boughtIn, 'date' => $day,
            'shift' => 'AM', 'quantity' => 5, 'reference' => 'X-1',
        ]);
        $h->test('a non-commissary branch is refused', false, 'accepted a branch that is not a commissary');
    } catch (Throwable $e) {
        $h->test('a non-commissary branch is refused', str_contains($e->getMessage(), 'active commissary'), $e->getMessage());
    }

    try {
        dl_recordCommissaryReceipt($pic, [
            'commissary_branch_id' => $hub, 'product_id' => $loose, 'date' => $day,
            'shift' => 'AM', 'quantity' => 5, 'reference' => 'X-1',
        ]);
        $h->test('a product not assigned to the hub is refused', false, 'accepted an unassigned product');
    } catch (Throwable $e) {
        $h->test('a product not assigned to the hub is refused', str_contains($e->getMessage(), 'not assigned to this commissary'), $e->getMessage());
    }
} finally {
    $cleanup();
}

$h->done();
