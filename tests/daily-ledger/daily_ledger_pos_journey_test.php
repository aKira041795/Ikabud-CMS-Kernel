<?php

declare(strict_types=1);

/**
 * Daily Ledger — POS end-to-end journey (sell → close shift → close day) on a POS day
 *
 * Written because "POS is wired" had only been proved at the close contract. This walks the flow the
 * operator actually performs, with POS switched on:
 *
 *   1. a POS sale completes through dl_pos_checkout (the same service the HTTP endpoint calls);
 *   2. replaying the same client_operation_key does NOT double the sale (idempotency);
 *   3. the POS totals count it once;
 *   4. while the day is open and the shift is not finalized a cashier's ledger write is allowed
 *      (the gate every add-stock / receive / adjust path calls: dl_assertShiftMutable);
 *   5. the cutoff closer finalizes the shift and closes the day;
 *   6. after the close POS refuses to sell into that date (DAY_CLOSED) and the ledger write is
 *      refused for the finalized shift;
 *   7. an admin reopen unlocks the edit again — the cashier keeps the ability to correct stock.
 *
 * Owner constraint 2026-11-16: "also retain the ability of cashiers to add/receive/adjust stocks."
 *
 * Fixtures live on 2025-12-30 / branch 20 (outside the imported data range) and are removed; the
 * tenant's pos_enabled setting is captured and restored.
 */

ob_start();

require_once __DIR__ . '/../harness/TestHarness.php';

$h = new TestHarness('daily-ledger-pos-journey', TestHarness::MODE_INTEGRATION, 'baronledger.test');

ob_end_clean();

foreach ([
    'modules/daily-ledger/handlers.php',
    'modules/daily-ledger/handlers-pos.php',
] as $file) {
    $h->fingerprint($file);
}

$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/helpers.php';
require_once $base . '/modules/daily-ledger/handlers.php';
require_once $base . '/modules/daily-ledger/handlers-pos.php';

$tenantId = 207;
$branchId = 20;
$date = '2025-12-30';

app()->tenant()->setTenantId($tenantId);
$ctx = modulePushContext('daily-ledger');
if (!$ctx) {
    fwrite(STDERR, "daily-ledger module context unavailable\n");
    exit(1);
}
$db = $ctx->db();

$originalSettings = readTenantModuleSettingsForTenant('daily-ledger', $tenantId);

$refreshSettings = static function (): array {
    invalidateTenantModuleSettingsCache();
    preloadAllTenantModuleSettings();
    return dlModuleSettings(true);
};

$cleanup = static function () use ($db, $branchId, $date): void {
    foreach (['dl_daily_ledger', 'dl_ledger_day_status', 'dl_ledger_shift_status', 'dl_sales_day_modes', 'dl_variance_flags'] as $t) {
        $db->prepare("DELETE FROM {$t} WHERE branch_id = :b AND ledger_date = :d")->execute([':b' => $branchId, ':d' => $date]);
    }
    // POS sales + their lines/events (FK order: events, items, sales).
    $saleIds = $db->prepare("SELECT id FROM dl_pos_sales WHERE branch_id = :b AND ledger_date = :d");
    $saleIds->execute([':b' => $branchId, ':d' => $date]);
    $ids = array_map('intval', $saleIds->fetchAll(PDO::FETCH_COLUMN) ?: []);
    if ($ids !== []) {
        $in = implode(',', $ids);
        foreach (['dl_pos_payments', 'dl_pos_sale_events', 'dl_pos_sale_items'] as $t) {
            $db->prepare("DELETE FROM {$t} WHERE sale_id IN ({$in})")->execute();
        }
        $db->prepare("DELETE FROM dl_pos_sales WHERE id IN ({$in})")->execute();
    }
    $db->prepare("DELETE FROM audit_logs WHERE module = 'daily-ledger' AND (entity_id LIKE :e OR entity_id IN ('" . (string)$branchId . "-" . (string)$date . "'))")
        ->execute([':e' => "{$branchId}-{$date}%"]);
    $db->prepare("DELETE FROM dl_integrity_notifications WHERE aggregate_key LIKE :k")
        ->execute([':k' => "%{$branchId}-{$date}%"]);
};

$cashier = ['id' => 22, 'name' => 'Noah Omamalin', 'role' => 'cashier', 'shift' => 'PM'];

try {
    saveTenantModuleSettingsForTenant('daily-ledger', $tenantId, ['pos_enabled' => '1']);
    $refreshSettings();
    $cleanup();

    $h->section('Setup: a POS day with a priced product');

    $h->test('POS is enabled for the run', dl_isPosEnabled() === true);
    $db->prepare("INSERT INTO dl_sales_day_modes (branch_id, ledger_date, mode, status) VALUES (:b, :d, 'pos', 'active')")
        ->execute([':b' => $branchId, ':d' => $date]);
    $catalog = dl_pos_branchProducts($db, $branchId, $date);
    $h->test('the branch has a POS product to sell', $catalog !== []);
    $product = reset($catalog);
    $productId = (int)$product['product_id'];
    $unitCents = (int)$product['unit_price_cents'];
    $h->test('the product carries a real price', $unitCents > 0);

    // ── 1-3: sell, idempotency, totals ────────────────────────────────────
    $h->section('Sale');

    $qty = 2;
    $dueCents = $unitCents * $qty;
    $args = [
        'branch_id' => $branchId,
        'ledger_date' => $date,
        'cashier' => $cashier,
        'lines' => [['product_id' => $productId, 'quantity' => $qty, 'unit_price_cents' => $unitCents, 'line_discount_cents' => 0, 'tax_cents' => 0]],
        'payments' => [['method' => 'cash', 'tendered_cents' => $dueCents]],
        'client_operation_key' => 'journey-' . $date . '-1',
    ];
    $sale = dl_pos_checkout($db, $args);
    $h->test('the POS sale completes', ($sale['ok'] ?? false) === true);
    $saleId = (int)($sale['sale']['id'] ?? 0);
    $h->test('the completed sale is returned with its id', $saleId > 0);
    $h->test('the sale is stored as completed', (string)($sale['sale']['status'] ?? '') === 'completed');

    $replay = dl_pos_checkout($db, $args);
    $salesCount = (int)$db->query("SELECT COUNT(*) FROM dl_pos_sales WHERE branch_id = {$branchId} AND ledger_date = '{$date}'")->fetchColumn();
    $h->test('replaying the same client_operation_key does not create a second sale', $salesCount === 1);

    $totals = dl_pos_netTotals($db, $branchId, $date);
    $h->test('the POS totals count the sale once', (int)$totals['qty'] === $qty && (int)$totals['amount_cents'] === $dueCents);

    // ── 4: cashiers keep their stock-capture ability ──────────────────────
    $h->section('Cashier can still record stock while the day is open');

    $writeBlocked = static function () use ($db, $branchId, $date): bool {
        try {
            dl_assertShiftMutable($db, $branchId, $date, 'PM');
            return false;
        } catch (\RuntimeException $e) {
            return $e->getCode() === 403;
        }
    };
    $h->test('an open shift accepts an add-stock / receive / adjust write', $writeBlocked() === false);

    // ── 5: the cutoff closer finalizes the shift, then closes the day ─────
    $h->section('Close');

    $outcome = dl_autoCloseBranchDayAt($ctx, $branchId, $date, dl_closeOfDaySettings(), 20, true);
    $h->test("the cutoff closer closes the POS day (got '{$outcome}')", $outcome === 'closed');
    $shiftStatus = (string)$db->query("SELECT status FROM dl_ledger_shift_status WHERE branch_id = {$branchId} AND ledger_date = '{$date}' AND shift = 'PM'")->fetchColumn();
    $h->test('the PM shift is finalized', $shiftStatus === 'finalized');

    // ── 6: after the close, nothing may be sold or adjusted into it ───────
    $h->section('After the close');

    $lateArgs = $args;
    $lateArgs['client_operation_key'] = 'journey-' . $date . '-2';
    $lateSale = dl_pos_checkout($db, $lateArgs);
    $h->test('POS refuses a new sale into the closed date', ($lateSale['code'] ?? '') === 'DAY_CLOSED');
    $h->test('the finalized shift refuses the stock write', $writeBlocked() === true);

    // ── 7: an admin reopen restores the cashier's ability ────────────────
    $h->section('Admin reopen restores stock capture');

    dl_reopenDayService($db, $branchId, $date, 20);
    $h->test('the reopened shift accepts the stock write again', $writeBlocked() === false);
    $h->test('the day is open again', (string)$db->query("SELECT status FROM dl_ledger_day_status WHERE branch_id = {$branchId} AND ledger_date = '{$date}'")->fetchColumn() === 'open');
} finally {
    $cleanup();
    saveTenantModuleSettingsForTenant('daily-ledger', $tenantId, ['pos_enabled' => (string)($originalSettings['pos_enabled'] ?? '0')]);
    $refreshSettings();
}

$h->done();
