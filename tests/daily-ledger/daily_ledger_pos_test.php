<?php

declare(strict_types=1);

/**
 * Daily Ledger — POS Test Suite
 *
 * Covers the pure-money helpers, the branch-day mode state machine, refund
 * validation, fallback segment math, idempotency request hashing, and
 * source-level wiring checks (routes registered, permissions declared,
 * migration idempotency markers). DB-backed flows degrade gracefully in CLI.
 */

ob_start();

require_once __DIR__ . '/../harness/TestHarness.php';

$h = new TestHarness('daily-ledger-pos', TestHarness::MODE_INTEGRATION, 'localhost');
ob_end_clean();

$h->fingerprint('modules/daily-ledger/handlers-pos.php');
$h->fingerprint('modules/daily-ledger/handlers.php');
$h->fingerprint('modules/daily-ledger/helpers.php');
$h->fingerprint('modules/daily-ledger/module.json');

$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/helpers.php';
require_once $base . '/modules/daily-ledger/helpers/entity-views.php';
require_once $base . '/modules/daily-ledger/handlers-deliveries.php';
require_once $base . '/modules/daily-ledger/handlers.php';

// ─── Money: cents parsing ───────────────────────────────────────
$h->section('POS Money — cents parsing');

$h->test('toCents "10.50" → 1050', dl_pos_toCents('10.50') === 1050);
$h->test('toCents 10.5 → 1050', dl_pos_toCents(10.5) === 1050);
$h->test('toCents 0 → 0', dl_pos_toCents(0) === 0);
$h->test('toCents "0.005" rounds half-up → 1', dl_pos_toCents('0.005') === 1);
$h->test('toCents "abc" → null', dl_pos_toCents('abc') === null);
$h->test('toCents "" → null', dl_pos_toCents('') === null);
$h->test('toCents null → null', dl_pos_toCents(null) === null);
$h->test('toCents int 7 → 700', dl_pos_toCents(7) === 700);

$h->test('formatCents 1050 → "10.50"', dl_pos_formatCents(1050) === '10.50');
$h->test('formatCents 0 → "0.00"', dl_pos_formatCents(0) === '0.00');
$h->test('formatCents -250 → "-2.50"', dl_pos_formatCents(-250) === '-2.50');
$h->test('centsToFloat 1050 → 10.5', dl_pos_centsToFloat(1050) === 10.5);

// ─── Money: line + order totals ─────────────────────────────────
$h->section('POS Money — line and order totals');

$h->test('line total 3 × 1000 = 3000', dl_pos_lineTotalCents(3, 1000) === 3000);
$h->test('line total with discount', dl_pos_lineTotalCents(2, 1000, 500) === 1500);
$h->test('line total floors at zero', dl_pos_lineTotalCents(1, 100, 500) === 0);

$threw = false;
try { dl_pos_lineTotalCents(0, 100); } catch (\InvalidArgumentException $e) { $threw = true; }
$h->test('zero quantity rejected', $threw);
$threw = false;
try { dl_pos_lineTotalCents(-1, 100); } catch (\InvalidArgumentException $e) { $threw = true; }
$h->test('negative quantity rejected', $threw);
$threw = false;
try { dl_pos_lineTotalCents(1, -100); } catch (\InvalidArgumentException $e) { $threw = true; }
$h->test('negative unit price rejected', $threw);
$threw = false;
try { dl_pos_lineTotalCents(1, 100, -5); } catch (\InvalidArgumentException $e) { $threw = true; }
$h->test('negative line discount rejected', $threw);

$totals = dl_pos_orderTotals([
    ['quantity' => 2, 'unit_price_cents' => 1000, 'line_discount_cents' => 0, 'tax_cents' => 0],
    ['quantity' => 1, 'unit_price_cents' => 500, 'line_discount_cents' => 100, 'tax_cents' => 50],
], 200);
$h->test('order subtotal = 2400', $totals['subtotal'] === 2400);
$h->test('order discount = 300 (line 100 + order 200)', $totals['discount'] === 300);
$h->test('order tax = 50', $totals['tax'] === 50);
$h->test('order total = 2400 - 200 + 50 = 2250', $totals['total'] === 2250);

$threw = false;
try { dl_pos_orderTotals([]); } catch (\InvalidArgumentException $e) { $threw = true; }
$h->test('empty cart rejected', $threw);
$threw = false;
try { dl_pos_orderTotals([['quantity' => 1, 'unit_price_cents' => 100]], -1); } catch (\InvalidArgumentException $e) { $threw = true; }
$h->test('negative order discount rejected', $threw);

// ─── Money: tender allocation + change ──────────────────────────
$h->section('POS Money — tender allocation');

$h->test('change 500 - 300 = 200', dl_pos_computeChangeCents(500, 300) === 200);
$h->test('change never negative', dl_pos_computeChangeCents(100, 300) === 0);

$alloc = dl_pos_allocatePayments([['method' => 'cash', 'tendered_cents' => 1000]], 750, ['cash']);
$h->test('cash applied = 750', $alloc[0]['applied_cents'] === 750);
$h->test('cash change = 250', $alloc[0]['change_cents'] === 250);

$threw = false;
try { dl_pos_allocatePayments([['method' => 'cash', 'tendered_cents' => 100]], 750, ['cash']); } catch (\InvalidArgumentException $e) { $threw = true; }
$h->test('insufficient cash rejected', $threw);

$threw = false;
try { dl_pos_allocatePayments([['method' => 'check', 'tendered_cents' => 100]], 100, ['cash']); } catch (\InvalidArgumentException $e) { $threw = true; }
$h->test('disallowed tender rejected', $threw);

$alloc = dl_pos_allocatePayments([
    ['method' => 'gcash', 'tendered_cents' => 500],
    ['method' => 'cash', 'tendered_cents' => 1000],
], 1200, ['cash', 'gcash']);
$h->test('split tender: gcash applied 500', $alloc[0]['applied_cents'] === 500);
$h->test('split tender: cash applied 700', $alloc[1]['applied_cents'] === 700);
$h->test('split tender: cash change 300', $alloc[1]['change_cents'] === 300);

$threw = false;
try { dl_pos_allocatePayments([['method' => 'gcash', 'tendered_cents' => 2000]], 1000, ['gcash']); } catch (\InvalidArgumentException $e) { $threw = true; }
$h->test('non-cash over-tender rejected', $threw);

$threw = false;
try { dl_pos_allocatePayments([], 100, ['cash']); } catch (\InvalidArgumentException $e) { $threw = true; }
$h->test('no payments rejected', $threw);

// ─── Money: tender setting parsing ──────────────────────────────
$h->section('POS Settings — allowed tenders');

$h->test('default "cash" parses to [cash]', dl_pos_parseAllowedTenders('cash') === ['cash']);
$h->test('"cash, gcash" parses to [cash, gcash]', dl_pos_parseAllowedTenders('cash, gcash') === ['cash', 'gcash']);
$h->test('empty string falls back to [cash]', dl_pos_parseAllowedTenders('') === ['cash']);
$h->test('non-array falls back to [cash]', dl_pos_parseAllowedTenders(null) === ['cash']);
$h->test('invalid entries filtered', dl_pos_parseAllowedTenders('cash,!!bad!!,card') === ['cash', 'card']);
$h->test('duplicates removed', dl_pos_parseAllowedTenders('cash,cash') === ['cash']);

// ─── Mode state machine ─────────────────────────────────────────
$h->section('POS Mode State Machine');

$h->test('normalize "pos" → pos', dl_pos_normalizeMode('pos') === 'pos');
$h->test('normalize "POS" → pos', dl_pos_normalizeMode('POS') === 'pos');
$h->test('normalize "bogus" → null', dl_pos_normalizeMode('bogus') === null);

// No recorded mode yet
$h->test('undecided → manual allowed', dl_pos_modeSelectionError(null, 'manual', false, false) === null);
$h->test('undecided → pos allowed', dl_pos_modeSelectionError(null, 'pos', false, false) === null);
$h->test('undecided + POS activity → manual rejected', dl_pos_modeSelectionError(null, 'manual', false, true) === 'MODE_LOCKED_POS_ACTIVITY');
$h->test('undecided + manual activity → pos rejected', dl_pos_modeSelectionError(null, 'pos', true, false) === 'MODE_LOCKED_MANUAL_ACTIVITY');
$h->test('undecided → fallback rejected (needs checkpoint)', dl_pos_modeSelectionError(null, 'fallback', false, false) === 'FALLBACK_REQUIRES_CHECKPOINT');

// Idempotent reselect
$h->test('manual → manual allowed (idempotent)', dl_pos_modeSelectionError('manual', 'manual', true, false) === null);
$h->test('pos → pos allowed (idempotent)', dl_pos_modeSelectionError('pos', 'pos', false, true) === null);

// Transitions
$h->test('manual → pos allowed before manual activity', dl_pos_modeSelectionError('manual', 'pos', false, false) === null);
$h->test('manual → pos rejected after manual activity', dl_pos_modeSelectionError('manual', 'pos', true, false) === 'MODE_LOCKED_MANUAL_ACTIVITY');
$h->test('pos → manual rejected (fallback checkpoint required)', dl_pos_modeSelectionError('pos', 'manual', false, true) === 'MODE_SWITCH_REQUIRES_FALLBACK');
$h->test('fallback is final (manual)', dl_pos_modeSelectionError('fallback', 'manual', true, true) === 'MODE_FALLBACK_FINAL');
$h->test('fallback is final (pos)', dl_pos_modeSelectionError('fallback', 'pos', true, true) === 'MODE_FALLBACK_FINAL');
$h->test('fallback is final (fallback)', dl_pos_modeSelectionError('fallback', 'fallback', true, true) === 'FALLBACK_REQUIRES_CHECKPOINT');

// ─── Fallback segment math ──────────────────────────────────────
$h->section('POS Fallback Segment Math');

$h->test('segment qty: 10 + 0 - 0 - 4 = 6', dl_pos_computeFallbackSegmentQty(10, 0, 0, 4) === 6);
$h->test('segment qty with post-checkpoint addtl delta', dl_pos_computeFallbackSegmentQty(10, 5, 0, 8) === 7);
$h->test('segment qty with post-checkpoint withdraw delta', dl_pos_computeFallbackSegmentQty(10, 0, 3, 4) === 3);
$h->test('segment qty floors at zero', dl_pos_computeFallbackSegmentQty(2, 0, 5, 10) === 0);
$h->test('segment qty: untouched product = checkpoint - end', dl_pos_computeFallbackSegmentQty(20, 0, 0, 20) === 0);

// ─── Refund validation ──────────────────────────────────────────
$h->section('POS Refund Validation');

$original = [1 => 5, 2 => 2];
$h->test('valid partial refund passes', dl_pos_validateRefundLines($original, [], [['product_id' => 1, 'quantity' => 2]]) === null);
$h->test('valid full refund passes', dl_pos_validateRefundLines($original, [], [['product_id' => 1, 'quantity' => 5], ['product_id' => 2, 'quantity' => 2]]) === null);
$h->test('over-refund rejected', dl_pos_validateRefundLines($original, [], [['product_id' => 1, 'quantity' => 6]]) !== null);
$h->test('cumulative over-refund rejected', dl_pos_validateRefundLines($original, [1 => 4], [['product_id' => 1, 'quantity' => 2]]) !== null);
$h->test('refund of non-original product rejected', dl_pos_validateRefundLines($original, [], [['product_id' => 99, 'quantity' => 1]]) !== null);
$h->test('empty refund rejected', dl_pos_validateRefundLines($original, [], []) !== null);
$h->test('zero-quantity refund rejected', dl_pos_validateRefundLines($original, [], [['product_id' => 1, 'quantity' => 0]]) !== null);
$h->test('negative-quantity refund rejected', dl_pos_validateRefundLines($original, [], [['product_id' => 1, 'quantity' => -1]]) !== null);
$h->test('duplicate lines merged before check', dl_pos_validateRefundLines($original, [], [['product_id' => 1, 'quantity' => 3], ['product_id' => 1, 'quantity' => 3]]) !== null);

// ─── Idempotency request hash ───────────────────────────────────
$h->section('POS Idempotency Hash');

$lines = [['product_id' => 2, 'quantity' => 1], ['product_id' => 1, 'quantity' => 3]];
$payments = [['method' => 'cash', 'tendered_cents' => 5000]];
$hashA = dl_pos_requestHash($lines, $payments);
$h->test('hash is 64-char sha256', (bool)preg_match('/^[0-9a-f]{64}$/', $hashA));
$h->test('hash is order-independent for lines', dl_pos_requestHash(array_reverse($lines), $payments) === $hashA);
$h->test('hash changes with quantity', dl_pos_requestHash([['product_id' => 1, 'quantity' => 4]], $payments) !== $hashA);
$h->test('hash changes with tender', dl_pos_requestHash($lines, [['method' => 'cash', 'tendered_cents' => 5001]]) !== $hashA);

// ─── Permission wiring ──────────────────────────────────────────
$h->section('POS Permissions');

$actions = dl_allPermissionActions();
foreach (['pos.sell', 'pos.void', 'pos.refund', 'pos.fallback', 'pos.report'] as $perm) {
    $h->test("permission {$perm} registered", in_array($perm, $actions, true));
}
$defaults = dl_defaultRolePermissions();
$h->test('cashier default: pos.sell only', ($defaults['cashier'] ?? []) === ['pos.sell']);
$h->test('admin default: all POS permissions', count(array_intersect(['pos.sell', 'pos.void', 'pos.refund', 'pos.fallback', 'pos.report'], $defaults['admin'] ?? [])) === 5);
$h->test('supervisor default: all POS permissions', count(array_intersect(['pos.sell', 'pos.void', 'pos.refund', 'pos.fallback', 'pos.report'], $defaults['supervisor'] ?? [])) === 5);
$h->test('auditor default: no POS permissions', ($defaults['auditor'] ?? []) === []);
$h->test('viewer default: no POS permissions', ($defaults['viewer'] ?? []) === []);

$h->test('cashier can sell by default', dl_pos_userCan(['role' => 'cashier'], 'pos.sell') === true);
$h->test('cashier cannot void', dl_pos_userCan(['role' => 'cashier'], 'pos.void') === false);
$h->test('cashier cannot refund', dl_pos_userCan(['role' => 'cashier'], 'pos.refund') === false);
$h->test('cashier cannot fallback', dl_pos_userCan(['role' => 'cashier'], 'pos.fallback') === false);
$h->test('cashier cannot report', dl_pos_userCan(['role' => 'cashier'], 'pos.report') === false);
$h->test('supervisor can void by default', dl_pos_userCan(['role' => 'supervisor'], 'pos.void') === true);
$h->test('admin can fallback by default', dl_pos_userCan(['role' => 'admin'], 'pos.fallback') === true);
$h->test('production_in_charge cannot sell', dl_pos_userCan(['role' => 'production_in_charge'], 'pos.sell') === false);
$h->test('auditor cannot report', dl_pos_userCan(['role' => 'auditor'], 'pos.report') === false);
$h->test('unknown permission denied', dl_pos_userCan(['role' => 'admin'], 'pos.bogus') === false);
$h->test('delivery.edit registered in actions', in_array('delivery.edit', $actions, true));
$h->test('admin can edit delivery by DR', dl_canEditDeliveryByDr(['role' => 'admin']) === true);
$h->test('supervisor can edit delivery by DR', dl_canEditDeliveryByDr(['role' => 'supervisor']) === true);
$h->test('cashier cannot edit delivery by DR by default', dl_canEditDeliveryByDr(['role' => 'cashier']) === false);

// ─── Feature flag ───────────────────────────────────────────────
$h->section('POS Feature Flag');

$h->test('dl_isPosEnabled returns bool', is_bool(dl_isPosEnabled()));
$h->test('feature settings include pos_enabled', array_key_exists('pos_enabled', dl_featureSettings()));
$h->test('layout flags include feature_pos', array_key_exists('feature_pos', dl_layoutFlags()));
$h->test('allowed tenders returns list', is_array(dl_pos_allowedTenders()) && dl_pos_allowedTenders() !== []);

// ─── Manifest ownership ─────────────────────────────────────────
$h->section('POS Manifest Ownership');

$manifest = json_decode((string)file_get_contents($base . '/modules/daily-ledger/module.json'), true);
$owns = $manifest['owns_tables'] ?? [];
foreach (['dl_sales_day_modes', 'dl_pos_sales', 'dl_pos_sale_items', 'dl_pos_payments', 'dl_pos_sale_events', 'dl_pos_fallback_checkpoints', 'dl_pos_fallback_checkpoint_items'] as $table) {
    $h->test("owns {$table}", in_array($table, $owns, true));
}
$migrations = $manifest['migrations'] ?? [];
$h->test('migration 042 registered', in_array('database/migrations/042_pos_sales_and_day_modes.sql', $migrations, true));
$h->test('migration 042 file exists', is_file($base . '/modules/daily-ledger/database/migrations/042_pos_sales_and_day_modes.sql'));
$settingKeys = array_column($manifest['settings_fields'] ?? [], 'key');
$h->test('pos_enabled setting declared', in_array('pos_enabled', $settingKeys, true));
$h->test('pos_allowed_tenders setting declared', in_array('pos_allowed_tenders', $settingKeys, true));
$posField = null;
foreach ($manifest['settings_fields'] ?? [] as $f) {
    if (($f['key'] ?? '') === 'pos_enabled') { $posField = $f; }
}
$h->test('pos_enabled defaults to off', (string)($posField['default'] ?? '') === '0');

// ─── Migration schema markers ───────────────────────────────────
$h->section('POS Migration Schema');

$sql = (string)file_get_contents($base . '/modules/daily-ledger/database/migrations/042_pos_sales_and_day_modes.sql');
foreach (['dl_sales_day_modes', 'dl_pos_sales', 'dl_pos_sale_items', 'dl_pos_payments', 'dl_pos_sale_events', 'dl_pos_fallback_checkpoints', 'dl_pos_fallback_checkpoint_items'] as $table) {
    $h->test("{$table} uses CREATE TABLE IF NOT EXISTS", str_contains($sql, "CREATE TABLE IF NOT EXISTS {$table}"));
}
$h->test('all tables InnoDB', substr_count($sql, 'ENGINE=InnoDB') === 7);
$h->test('day modes unique (branch_id, ledger_date)', str_contains($sql, 'uq_dl_sdm_branch_date'));
$h->test('client operation idempotency key', str_contains($sql, 'uq_dl_pos_client_op'));
$h->test('receipt uniqueness per branch', str_contains($sql, 'uq_dl_pos_receipt'));
$h->test('checkpoint unique per branch-day', str_contains($sql, 'uq_dl_pos_checkpoint'));
$h->test('sale uuid unique', str_contains($sql, 'uq_dl_pos_sale_uuid'));
$h->test('money stored as integer cents', str_contains($sql, 'total_cents BIGINT'));
$h->test('no window functions', !str_contains($sql, 'OVER('));
$h->test('no CTEs', !preg_match('/\bWITH\b\s+\w+\s+AS\s*\(/i', $sql));
$h->test('FK checks wrapped', str_contains($sql, 'SET FOREIGN_KEY_CHECKS = 0') && str_contains($sql, 'SET FOREIGN_KEY_CHECKS = 1'));

// ─── Route wiring ───────────────────────────────────────────────
$h->section('POS Route Wiring');

$routes = include $base . '/modules/daily-ledger/routes.php';
$get = $routes['GET'] ?? [];
$post = $routes['POST'] ?? [];

$expectedGet = [
    '/daily-ledger/pos' => 'daily-ledger:handleCashierPos',
    '/daily-ledger/pos/receipt' => 'daily-ledger:handlePosReceipt',
    '/daily-ledger/admin/pos-sales' => 'daily-ledger:handleAdminPosSales',
    '/daily-ledger/admin/pos-sales/export' => 'daily-ledger:handleAdminPosSalesExport',
    '/daily-ledger/api/v1/pos/state' => 'daily-ledger:apiPosState',
    '/daily-ledger/api/v1/pos/sales' => 'daily-ledger:apiPosSalesList',
    '/daily-ledger/api/v1/pos/sales/detail' => 'daily-ledger:apiPosSaleDetail',
];
foreach ($expectedGet as $path => $handler) {
    $h->test("GET {$path}", ($get[$path] ?? '') === $handler);
}
$expectedPost = [
    '/daily-ledger/api/v1/pos/mode/select' => 'daily-ledger:apiPosSelectMode',
    '/daily-ledger/api/v1/pos/cart/save' => 'daily-ledger:apiPosSaveCart',
    '/daily-ledger/api/v1/pos/cart/abandon' => 'daily-ledger:apiPosAbandonCart',
    '/daily-ledger/api/v1/pos/checkout' => 'daily-ledger:apiPosCheckout',
    '/daily-ledger/api/v1/pos/sales/void' => 'daily-ledger:apiPosVoidSale',
    '/daily-ledger/api/v1/pos/sales/refund' => 'daily-ledger:apiPosRefundSale',
    '/daily-ledger/api/v1/pos/fallback' => 'daily-ledger:apiPosFallbackCheckpoint',
];
foreach ($expectedPost as $path => $handler) {
    $h->test("POST {$path}", ($post[$path] ?? '') === $handler);
}

// ─── Source wiring: POS never touches ledger stock fields ──────
$h->section('POS Stock-Field Isolation');

$posSource = (string)file_get_contents($base . '/modules/daily-ledger/handlers-pos.php');
$h->test('handlers-pos.php loaded by handlers.php', str_contains((string)file_get_contents($base . '/modules/daily-ledger/handlers.php'), "handlers-pos.php"));
// POS must never write the manual ledger source columns.
$writesLedger = (bool)preg_match('/(INSERT INTO\s+dl_daily_ledger|UPDATE\s+dl_daily_ledger)/i', $posSource);
$h->test('POS never writes dl_daily_ledger', !$writesLedger);
$h->test('POS never references addtl/withdraw/bal_end as writes', !preg_match('/(addtl|withdraw|bal_end)\s*=/', $posSource));
$h->test('POS reads ledger for reconciliation only', str_contains($posSource, 'FROM dl_daily_ledger'));

// ─── Handler functions defined ──────────────────────────────────
$h->section('POS Handler Definitions');

foreach ([
    'apiPosState', 'apiPosSelectMode', 'apiPosSaveCart', 'apiPosAbandonCart',
    'apiPosCheckout', 'apiPosVoidSale', 'apiPosRefundSale', 'apiPosFallbackCheckpoint',
    'apiPosSalesList', 'apiPosSaleDetail',
    'handleCashierPos', 'handlePosReceipt', 'handleAdminPosSales', 'handleAdminPosSalesExport',
] as $fn) {
    $h->test("function {$fn}() defined", function_exists($fn));
}

// ─── Receipt payload shape ──────────────────────────────────────
$h->section('POS Receipt Payload');

$fakeSale = [
    'id' => 7, 'sale_uuid' => 'uuid-1', 'sale_kind' => 'sale', 'status' => 'completed',
    'receipt_no' => 'R1-20260812-0001', 'branch_id' => 1, 'branch_name' => 'Main',
    'ledger_date' => '2026-08-12', 'cashier_name' => 'Cashier One',
    'subtotal_cents' => 1500, 'discount_cents' => 100, 'tax_cents' => 50, 'total_cents' => 1450,
    'completed_at' => '2026-08-12 10:00:00',
    'items' => [['product_id' => 1, 'product_name' => 'Bread', 'sku' => 'BBS-0001', 'quantity' => 2, 'unit_price_cents' => 750, 'line_total_cents' => 1500]],
    'payments' => [['tender_method' => 'cash', 'amount_tendered_cents' => 2000, 'amount_applied_cents' => 1450, 'change_cents' => 550]],
];
$payload = dl_pos_receiptPayload($fakeSale);
$h->test('receipt total is float 14.50', $payload['total'] === 14.50);
$h->test('receipt item unit price 7.50', $payload['items'][0]['unit_price'] === 7.50);
$h->test('receipt payment change 5.50', $payload['payments'][0]['change'] === 5.50);
$h->test('receipt number preserved', $payload['receipt_no'] === 'R1-20260812-0001');
$h->test('receipt exposes no raw cents fields', !array_key_exists('total_cents', $payload));

// ─── Regression guards (found in developer review) ─────────────
$h->section('POS Review Regression Guards');

// P0: dl_pos_selectMode must persist a mode transition on an existing row
// (previously returned ok=true without writing — a false-success bug).
$h->test(
    'mode transition persists via UPDATE on existing row',
    (bool)preg_match(
        '/UPDATE\s+dl_sales_day_modes\s+SET\s+mode\s*=\s*:m.*WHERE\s+id\s*=\s*:id/si',
        $posSource
    )
);
$h->test('mode transition guards version before update', str_contains($posSource, 'VERSION_CONFLICT'));

// P0: ModuleDB table-access parser rejects derived tables in FROM
// (comma-split misreads `s.id` as an undeclared table). netTotals must
// aggregate in PHP, not via a derived table.
$h->test('netTotals has no derived table in FROM', !preg_match('/FROM\s*\(/s', $posSource));
$h->test('netTotals still reads dl_pos_sales', str_contains($posSource, 'FROM dl_pos_sales s'));

// P0: stock-derived summary must alias dl_daily_ledger as `dl` so the
// derived-sales SQL (dl.beg_bal …) resolves.
$h->test('stock-derived summary aliases dl_daily_ledger', str_contains($posSource, 'FROM dl_daily_ledger dl'));

// P1: refund must never exceed the original sale's remaining unrefunded amount.
$h->test('refund amount capped to unrefunded balance', str_contains($posSource, 'REFUND_EXCEEDS_AMOUNT'));

// P1: a draft cart may only be completed by its owning cashier.
$h->test('draft completion requires owning cashier', str_contains($posSource, 'CART_OWNER_CONFLICT'));

// P1 (developer review): a draft saved via cart/save has no request_hash, so the
// draft-upgrade branch must be evaluated BEFORE the idempotency hash comparison.
// Otherwise completing a saved draft always returns IDEMPOTENCY_CONFLICT.
$draftBranchPos = strpos($posSource, "status'] === 'draft'");
$hashConflictPos = strpos($posSource, 'IDEMPOTENCY_CONFLICT');
$h->test('draft-upgrade branch precedes idempotency hash conflict', $draftBranchPos !== false && $hashConflictPos !== false && $draftBranchPos < $hashConflictPos);

// Stock-field isolation (POS never writes manual ledger source columns).
$h->test('POS has no INSERT/UPDATE on dl_daily_ledger', !preg_match('/(INSERT INTO\s+dl_daily_ledger|UPDATE\s+dl_daily_ledger)/i', $posSource));

// ─── POS shift alignment (shift-period model) ────────────────────
$h->section('POS Shift Alignment');

// Each POS sale is tagged with the cashier's shift so admin reports can split
// AM vs PM, mirroring the manual ledger. A shift-bound cashier is forced to
// their assigned shift.
$h->test('checkout resolves shift via helper', (bool)preg_match('/function dl_pos_checkout[\s\S]*?dl_resolveLedgerShift\(\$cashier, \$args\)/', $posSource));
$h->test('checkout completed INSERT stores shift', str_contains($posSource, "cashier_id, shift, receipt_no"));
$h->test('checkout draft-upgrade UPDATE stores shift', (bool)preg_match('/UPDATE dl_pos_sales[\s\S]*?shift = :shift/s', $posSource));
$h->test('cart save draft INSERT stores shift', str_contains($posSource, "cashier_id, shift, receipt_no, status"));
$h->test('refund inherits original sale shift', str_contains($posSource, "':shift' => (\$sale['shift'] ?? null) ?: null"));
$h->test('querySales selects shift', str_contains($posSource, 's.total_cents, s.shift,'));
$h->test('querySales filters by shift', str_contains($posSource, "AND s.shift = ?"));
$h->test('fallback snapshot aggregates shift rows', (bool)preg_match('/SELECT product_id, shift, addtl, withdraw FROM dl_daily_ledger/s', $posSource));
$h->test('fallback snapshot sums addtl/withdraw across shifts', str_contains($posSource, "\$ledgerRows[\$pid]['addtl'] = (\$ledgerRows[\$pid]['addtl'] ?? 0) + (int)\$row['addtl']"));
$h->test('POS page resolves shift via helper', (bool)preg_match('/function handleCashierPos[\s\S]*?dl_resolveLedgerShift\(\$user, \$input\)/', $posSource));
$h->test('POS page passes shift_locked to template', str_contains($posSource, "'shift_locked' => \$shiftBound,"));
$h->test('POS CSV export includes shift column', str_contains($posSource, "'Cashier', 'Shift', 'Kind'"));
$h->test('receipt payload exposes shift', str_contains($posSource, "'shift' => (string)(\$sale['shift'] ?? ''),"));

$h->done();
