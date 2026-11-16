<?php

declare(strict_types=1);

/**
 * Daily Ledger — a CASHIER account can sell (POS) and record stock, and keeps that ability
 *
 * Owner directive 2026-11-16: "test with a cashier account when set" + "retain the ability of
 * cashiers to add/receive/adjust stocks."
 *
 * Everything runs through the real HTTP handlers in a child process, with the module's own role
 * cookie forged for an ACTUAL cashier (user 19, cashier-miputak, PM) — so the role gate, the branch
 * authorization and the shift-immutability guard are exercised as the operator meets them:
 *
 *   pos      POST /api/v1/pos/checkout                     — sell
 *   field    POST /api/v1/cashier/ledger/save              — add / reduce stock on the sheet
 *   receive  POST /api/v1/cashier/ledger/receive-delivery  — receive a delivery
 *
 * Why a temporary branch: POS only ever operates on the SERVER business date (a client may not
 * supply it), so this cannot run on a past date, and it must not rewrite a real branch's live day.
 * A throwaway branch — created here, deleted at the end — with the cashier assigned to it gives a
 * genuine role journey with no footprint on client data.
 *
 * Phase 1 (day open, PM open): cashier sells, adds stock, reduces stock, receives a delivery.
 * Phase 2 (PM signed): the sheet refuses, and POS refuses too — a sale may not land in a shift the
 *          ledger already signed.
 * Phase 3 (admin reopen): the cashier can record stock again.
 */

ob_start();

require_once __DIR__ . '/../harness/TestHarness.php';

$h = new TestHarness('daily-ledger-cashier-pos-stock', TestHarness::MODE_INTEGRATION, 'baronledger.test');

ob_end_clean();

foreach ([
    'modules/daily-ledger/handlers.php',
    'modules/daily-ledger/handlers-pos.php',
    'tests/daily-ledger/daily_ledger_cashier_api_harness.php',
] as $file) {
    $h->fingerprint($file);
}

$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/helpers.php';
require_once $base . '/modules/daily-ledger/handlers.php';
require_once $base . '/modules/daily-ledger/handlers-pos.php';

$tenantId = 207;
$cashierId = 0; // created in setup: a real cashier account, isolated to the throwaway branch

app()->tenant()->setTenantId($tenantId);
$ctx = modulePushContext('daily-ledger');
if (!$ctx) {
    fwrite(STDERR, "daily-ledger module context unavailable\n");
    exit(1);
}
$db = $ctx->db();
$date = dl_businessDate();
$harness = __DIR__ . '/daily_ledger_cashier_api_harness.php';
$originalSettings = readTenantModuleSettingsForTenant('daily-ledger', $tenantId);

$refreshSettings = static function (): array {
    invalidateTenantModuleSettingsCache();
    preloadAllTenantModuleSettings();
    return dlModuleSettings(true);
};

$asCashier = static function (string $mode, array $body) use ($harness, &$cashierId): array {
    $payload = [
        'user' => ['id' => $cashierId, 'role' => 'cashier', 'shift' => 'PM', 'username' => 'cashier-miputak', 'name' => 'Noah Omamalin'],
        'get' => [],
        'body' => $body,
    ];
    $file = tempnam(sys_get_temp_dir(), 'dlcashier');
    file_put_contents($file, json_encode($payload));
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harness) . ' ' . escapeshellarg($mode) . ' ' . escapeshellarg($file) . ' 2>&1';
    exec($cmd, $out, $code);
    @unlink($file);
    $raw = implode("\n", $out);
    $json = json_decode($raw, true);
    return [is_array($json) ? $json : [], $code, $raw];
};

$productId = (int)$db->query("SELECT id FROM dl_products WHERE is_active = 1 ORDER BY id LIMIT 1")->fetchColumn();
$branchId = 0;

$cleanup = static function () use ($db, &$branchId, &$cashierId): void {
    if ($branchId <= 0) {
        return;
    }
    foreach (['dl_daily_ledger', 'dl_ledger_day_status', 'dl_ledger_shift_status', 'dl_sales_day_modes', 'dl_variance_flags', 'dl_cashier_withdrawals', 'dl_branch_products', 'dl_user_branches'] as $t) {
        $db->prepare("DELETE FROM {$t} WHERE branch_id = :b")->execute([':b' => $branchId]);
    }
    $saleIds = $db->prepare('SELECT id FROM dl_pos_sales WHERE branch_id = :b');
    $saleIds->execute([':b' => $branchId]);
    $ids = array_map('intval', $saleIds->fetchAll(PDO::FETCH_COLUMN) ?: []);
    if ($ids !== []) {
        $in = implode(',', $ids);
        foreach (['dl_pos_payments', 'dl_pos_sale_events', 'dl_pos_sale_items'] as $t) {
            $db->prepare("DELETE FROM {$t} WHERE sale_id IN ({$in})")->execute();
        }
        $db->prepare("DELETE FROM dl_pos_sales WHERE id IN ({$in})")->execute();
    }
    $db->prepare("DELETE FROM audit_logs WHERE module = 'daily-ledger' AND entity_id LIKE :e")->execute([':e' => "{$branchId}-%"]);
    $db->prepare('DELETE FROM dl_branches WHERE id = :b')->execute([':b' => $branchId]);
    if ($cashierId > 0) {
        $db->prepare("DELETE FROM dl_users WHERE username = :u")->execute([':u' => 'zz-cashier-' . $branchId]);
    }
};

try {
    saveTenantModuleSettingsForTenant('daily-ledger', $tenantId, ['pos_enabled' => '1']);
    $refreshSettings();

    $h->section('Setup: throwaway branch assigned to the cashier, POS on');

    $h->test('POS is enabled for the run', dl_isPosEnabled() === true);

    $db->prepare(
        "INSERT INTO dl_branches (code, name, is_active, is_commissary, sort_order)
         VALUES ('ZZTEST', 'ZZ Cashier Journey', 1, 0, 999)"
    )->execute();
    $branchId = (int)$db->lastInsertId();
    $h->test('a temporary branch exists', $branchId > 0);

    // A real cashier account (dl_users row), not a forged id: dl_getUserBranchId() reads the first
    // dl_user_branches row for the user, so borrowing an existing cashier would silently keep their
    // real branch.
    $db->prepare(
        "INSERT INTO dl_users (username, email, password_hash, full_name, role, shift, is_active)
         VALUES (:u, :e, :p, 'ZZ Cashier', 'cashier', 'PM', 1)"
    )->execute([':u' => 'zz-cashier-' . $branchId, ':e' => 'zz-cashier-' . $branchId . '@example.invalid', ':p' => password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT)]);
    $cashierId = (int)$db->lastInsertId();
    $h->test('a real cashier account is created for the run', $cashierId > 0);

    $db->prepare('INSERT INTO dl_user_branches (user_id, branch_id) VALUES (:u, :b)')
        ->execute([':u' => $cashierId, ':b' => $branchId]);
    $db->prepare('INSERT INTO dl_branch_products (branch_id, product_id, is_active) VALUES (:b, :p, 1)')
        ->execute([':b' => $branchId, ':p' => $productId]);
    $db->prepare("INSERT INTO dl_sales_day_modes (branch_id, ledger_date, mode, status) VALUES (:b, :d, 'pos', 'active')")
        ->execute([':b' => $branchId, ':d' => $date]);

    $catalog = dl_pos_branchProducts($db, $branchId, $date);
    $product = $catalog === [] ? null : reset($catalog);
    $h->test('the branch has a priced POS product', $product !== null && (int)$product['unit_price_cents'] > 0);
    $unitCents = $product === null ? 0 : (int)$product['unit_price_cents'];

    $db->prepare(
        "INSERT INTO dl_cashier_withdrawals (branch_id, product_id, ledger_date, shift, withdrawal_type, quantity, unit, target_branch_id, encoded_by, created_at)
         VALUES (:b, :p, :d, 'PM', 'delivery', 3, 'pcs', :b2, :uid, NOW())"
    )->execute([':b' => $branchId, ':p' => $productId, ':d' => $date, ':b2' => $branchId, ':uid' => $cashierId]);
    $deliveryId = (int)$db->lastInsertId();
    $h->test('a delivery row exists to receive', $deliveryId > 0);

    // ── Phase 1 ───────────────────────────────────────────────────────────
    $h->section('Phase 1 — day open, PM open: the cashier sells and records stock');

    [$pos, , $posRaw] = $asCashier('pos', [
        'branch_id' => $branchId,
        'lines' => [['product_id' => $productId, 'quantity' => 1, 'unit_price_cents' => $unitCents, 'line_discount_cents' => 0, 'tax_cents' => 0]],
        'payments' => [['method' => 'cash', 'tendered_cents' => $unitCents]],
        'client_operation_key' => 'cashier-pos-' . $branchId,
    ]);
    $h->test('cashier: POS sale ok (' . (string)($pos['code'] ?? trim(substr($posRaw, 0, 50))) . ')', ($pos['ok'] ?? false) === true);
    $h->test(
        'cashier: the sale is stored as completed',
        (string)$db->query("SELECT status FROM dl_pos_sales WHERE branch_id = {$branchId} AND ledger_date = '{$date}' LIMIT 1")->fetchColumn() === 'completed'
    );

    [$add, , $addRaw] = $asCashier('field', [
        'branch_id' => $branchId, 'date' => $date, 'product_id' => $productId, 'field' => 'addtl', 'value' => 5, 'shift' => 'PM',
    ]);
    $h->test('cashier: ADDS stock on the sheet (' . trim(substr($addRaw, 0, 50)) . ')', ($add['ok'] ?? false) === true);

    [$reduce, , $reduceRaw] = $asCashier('field', [
        'branch_id' => $branchId, 'date' => $date, 'product_id' => $productId, 'field' => 'withdraw', 'value' => 2, 'shift' => 'PM',
    ]);
    $h->test('cashier: REDUCES stock on the sheet (' . trim(substr($reduceRaw, 0, 50)) . ')', ($reduce['ok'] ?? false) === true);

    [$receive, , $receiveRaw] = $asCashier('receive', [
        'branch_id' => $branchId, 'withdrawal_ids' => [$deliveryId], 'production_shift' => 'PM',
    ]);
    $h->test('cashier: RECEIVES a delivery (' . trim(substr($receiveRaw, 0, 50)) . ')', ($receive['ok'] ?? false) === true);

    // The cashier closes the shift the way the screen does: record the ending, then sign it.
    [$ending, , $endingRaw] = $asCashier('field', [
        'branch_id' => $branchId, 'date' => $date, 'product_id' => $productId, 'field' => 'bal_end', 'value' => 2, 'shift' => 'PM',
    ]);
    $h->test('cashier: records the ENDING count (' . trim(substr($endingRaw, 0, 50)) . ')', ($ending['ok'] ?? false) === true);

    // ── Phase 2 ───────────────────────────────────────────────────────────
    $h->section('Phase 2 — PM signed: writes stop, POS stops');

    $sign = dl_finalizeDayEndingShiftCore($db, $branchId, $date, 'PM', $cashierId, 'manual');
    $h->test('the shift signs once the counts are complete', ($sign['finalized'] ?? false) === true);

    // The child process resolves its own context; prove it sees the signed shift before asserting
    // that the writes are refused (otherwise a refusal would prove nothing about the guard).
    [$who] = $asCashier('whoami', ['branch_id' => $branchId, 'date' => $date, 'shift' => 'PM']);
    $h->test('the cashier request context sees the PM shift as finalized', (string)($who['shift_status'] ?? '') === 'finalized');

    [$addLocked, , $addLockedRaw] = $asCashier('field', [
        'branch_id' => $branchId, 'date' => $date, 'product_id' => $productId, 'field' => 'addtl', 'value' => 6, 'shift' => 'PM',
    ]);
    $h->test('cashier: sheet write refused once the shift is signed (' . trim(substr($addLockedRaw, 0, 50)) . ')', ($addLocked['ok'] ?? true) === false);

    [$posLocked, , $posLockedRaw] = $asCashier('pos', [
        'branch_id' => $branchId,
        'lines' => [['product_id' => $productId, 'quantity' => 1, 'unit_price_cents' => $unitCents, 'line_discount_cents' => 0, 'tax_cents' => 0]],
        'payments' => [['method' => 'cash', 'tendered_cents' => $unitCents]],
        'client_operation_key' => 'cashier-pos2-' . $branchId,
    ]);
    $h->test('cashier: POS refuses a sale into a signed shift (' . (string)($posLocked['code'] ?? trim(substr($posLockedRaw, 0, 40))) . ')', ($posLocked['code'] ?? '') === 'SHIFT_FINALIZED');

    // ── Phase 3 ───────────────────────────────────────────────────────────
    $h->section('Phase 3 — admin reopen: the cashier can correct stock again');

    dl_reopenDayService($db, $branchId, $date, 20);

    [$addAgain, , $addAgainRaw] = $asCashier('field', [
        'branch_id' => $branchId, 'date' => $date, 'product_id' => $productId, 'field' => 'addtl', 'value' => 7, 'shift' => 'PM',
    ]);
    $h->test('cashier: stock capture allowed again after the reopen (' . trim(substr($addAgainRaw, 0, 50)) . ')', ($addAgain['ok'] ?? false) === true);
} finally {
    $cleanup();
    saveTenantModuleSettingsForTenant('daily-ledger', $tenantId, ['pos_enabled' => (string)($originalSettings['pos_enabled'] ?? '0')]);
    $refreshSettings();
}

$h->done();
