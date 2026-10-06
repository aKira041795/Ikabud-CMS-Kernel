<?php

declare(strict_types=1);

/**
 * Daily Ledger — per-branch product visibility, SLICE A (safety core).
 *
 * Proves the removal guard and the write-side assignment validation for the
 * branch-product feature. Every fixture is synthetic (branch/product ids in the
 * 997xx range) and removed in finally; real branch 8 is never touched.
 *
 * Cases G1-G11 from the slice contract. G8/G9 are the discriminating cases:
 * on the base tree the ledger write is ACCEPTED and creates a row; here it must
 * be refused with PRODUCT_NOT_ASSIGNED and create nothing.
 *
 * Tenant 207 (baronledger).
 */

ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-branch-product-visibility', TestHarness::MODE_INTEGRATION, 'baronledger.test');
ob_end_clean();
$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/helpers.php';
require_once $base . '/modules/daily-ledger/handlers-deliveries.php';
require_once $base . '/modules/daily-ledger/handlers.php';
$h->fingerprint('modules/daily-ledger/handlers.php');
$h->fingerprint('modules/daily-ledger/handlers-offline.php');
$h->fingerprint('modules/daily-ledger/helpers.php');
$h->fingerprint('modules/daily-ledger/database/migrations/077_branch_product_assignment_mode.sql');
$h->fingerprint('modules/daily-ledger/module.json');
$h->fingerprint('tests/daily-ledger/daily_ledger_branch_product_visibility_harness.php');
$h->allowLogLines('disyl.compile.phases');
app()->tenant()->setTenantId(207);
// Raw tenant PDO for the one read-only schema probe. The module guard forbids
// information_schema once the module context is active, so probe FIRST.
$rawDb = app()->dbForTenant(207);
$hasAssignmentMode = (bool)$rawDb->query(
    "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dl_products' AND COLUMN_NAME = 'assignment_mode'"
)->fetchColumn();
$ctx = modulePushContext('daily-ledger');
$db = $ctx->db();

// A real daily-ledger actor for the in-process audit rows.
$_COOKIE[dlCookieName()] = app()->jwt()->generate([
    'sub' => 'admin:1',
    'id' => 1,
    'username' => 'visibility-harness',
    'name' => 'Visibility Harness',
    'role' => 'admin',
    'source' => 'daily-ledger',
]);

$store = 99701;
$comm = 99702;
$prodCashier = 99701;
$prodCommissary = 99702;
$prodGlobal = 99703;
$prodAll = 99704;
$prodSpecific = 99705;
$productIds = [$prodCashier, $prodCommissary, $prodGlobal, $prodAll, $prodSpecific];
$createdBranchIds = [];
$payloadFile = tempnam(sys_get_temp_dir(), 'dl-vis-');

$today = dl_businessDate();
$openPrior = (new \DateTimeImmutable($today))->modify('-3 days')->format('Y-m-d');
$closedDay = (new \DateTimeImmutable($today))->modify('-1 day')->format('Y-m-d');

$guardAvailable = function_exists('dl_branchProductUnassignmentBlockers')
    && function_exists('dl_productDeactivationBlockers')
    && function_exists('dl_setBranchProductActive')
    && function_exists('dl_setProductActive');

$countTenant = static function () use ($db): array {
    return [
        'branches' => (int)$db->query('SELECT COUNT(*) FROM dl_branches')->fetchColumn(),
        'products' => (int)$db->query('SELECT COUNT(*) FROM dl_products')->fetchColumn(),
        'branch_products' => (int)$db->query('SELECT COUNT(*) FROM dl_branch_products')->fetchColumn(),
        'daily_ledger' => (int)$db->query('SELECT COUNT(*) FROM dl_daily_ledger')->fetchColumn(),
        'commissary_ledger' => (int)$db->query('SELECT COUNT(*) FROM dl_commissary_product_ledger')->fetchColumn(),
        'withdrawals' => (int)$db->query('SELECT COUNT(*) FROM dl_cashier_withdrawals')->fetchColumn(),
        'movements' => (int)$db->query('SELECT COUNT(*) FROM dl_production_movements')->fetchColumn(),
    ];
};

$cleanup = static function () use ($db, &$createdBranchIds, $productIds, $store, $comm): void {
    $branches = implode(',', array_map('intval', array_merge([$store, $comm], $createdBranchIds)));
    $pids = implode(',', array_map('intval', $productIds));
    $db->execute("DELETE FROM dl_variance_flags WHERE branch_id IN ({$branches}) OR product_id IN ({$pids})");
    $db->execute("DELETE FROM dl_daily_ledger WHERE branch_id IN ({$branches}) OR product_id IN ({$pids})");
    $db->execute("DELETE FROM dl_commissary_product_ledger WHERE commissary_branch_id IN ({$branches}) OR product_id IN ({$pids})");
    $db->execute("DELETE FROM dl_cashier_withdrawals WHERE branch_id IN ({$branches}) OR product_id IN ({$pids})");
    $db->execute("DELETE FROM dl_production_movements WHERE destination_branch_id IN ({$branches}) OR product_id IN ({$pids})");
    $db->execute("DELETE FROM dl_ledger_shift_status WHERE branch_id IN ({$branches})");
    $db->execute("DELETE FROM dl_ledger_day_status WHERE branch_id IN ({$branches})");
    $db->execute("DELETE FROM audit_logs WHERE module='daily-ledger' AND (branch_id IN ({$branches}) OR (entity_type='product' AND entity_id IN ({$pids})))");
    $db->execute("DELETE FROM dl_branch_products WHERE branch_id IN ({$branches}) OR product_id IN ({$pids})");
    $db->execute("DELETE FROM dl_products WHERE id IN ({$pids})");
    $db->execute("DELETE FROM dl_branches WHERE id IN ({$branches})");
};

$runApi = static function (string $mode, string $role, array $body = [], array $get = []) use ($payloadFile): array {
    file_put_contents($payloadFile, json_encode(['role' => $role, 'body' => $body, 'get' => $get], JSON_THROW_ON_ERROR));
    $out = [];
    $code = 0;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/daily_ledger_branch_product_visibility_harness.php') . ' ' . escapeshellarg($mode) . ' ' . escapeshellarg($payloadFile) . ' 2>/dev/null', $out, $code);
    return ['code' => $code, 'body' => json_decode(implode("\n", $out), true), 'raw' => implode("\n", $out)];
};

$activatePair = static function (int $branchId, int $productId) use ($db): void {
    $db->prepare('INSERT INTO dl_branch_products (branch_id, product_id, is_active) VALUES (?,?,1) ON DUPLICATE KEY UPDATE is_active = 1')
        ->execute([$branchId, $productId]);
};
$hidePair = static function (int $branchId, int $productId) use ($db): void {
    $db->prepare('INSERT INTO dl_branch_products (branch_id, product_id, is_active) VALUES (?,?,0) ON DUPLICATE KEY UPDATE is_active = 0')
        ->execute([$branchId, $productId]);
};
$pairActive = static function (int $branchId, int $productId) use ($db): int {
    return (int)$db->query("SELECT is_active FROM dl_branch_products WHERE branch_id = {$branchId} AND product_id = {$productId}")->fetchColumn();
};
$ledgerRowCount = static function (string $where) use ($db): int {
    return (int)$db->query("SELECT COUNT(*) FROM dl_daily_ledger WHERE {$where}")->fetchColumn();
};
$resetLedgers = static function () use ($db, $store, $comm, $productIds): void {
    $branches = "{$store},{$comm}";
    $pids = implode(',', array_map('intval', $productIds));
    $db->execute("DELETE FROM dl_daily_ledger WHERE branch_id IN ({$branches}) OR product_id IN ({$pids})");
    $db->execute("DELETE FROM dl_commissary_product_ledger WHERE commissary_branch_id IN ({$branches}) OR product_id IN ({$pids})");
    $db->execute("DELETE FROM dl_variance_flags WHERE branch_id IN ({$branches}) OR product_id IN ({$pids})");
    $db->execute("DELETE FROM dl_production_movements WHERE destination_branch_id IN ({$branches}) OR product_id IN ({$pids})");
    $db->execute("DELETE FROM dl_cashier_withdrawals WHERE branch_id IN ({$branches}) OR product_id IN ({$pids})");
    $db->execute("DELETE FROM dl_ledger_day_status WHERE branch_id IN ({$branches})");
    $db->execute("DELETE FROM dl_ledger_shift_status WHERE branch_id IN ({$branches})");
};
$setDayStatus = static function (int $branchId, string $date, string $status) use ($db): void {
    $db->prepare('INSERT INTO dl_ledger_day_status (branch_id, ledger_date, status) VALUES (?,?,?) ON DUPLICATE KEY UPDATE status = VALUES(status)')
        ->execute([$branchId, $date, $status]);
};
$hasBlocker = static function (array $blockers, string $date, string $shift, string $ledger): bool {
    foreach ($blockers as $b) {
        if (($b['date'] ?? null) === $date && (string)($b['shift'] ?? '') === $shift && ($b['ledger'] ?? null) === $ledger) {
            return true;
        }
    }
    return false;
};

$cleanup();
$tenantBefore = $countTenant();

try {
    // ── Fixture ─────────────────────────────────────────────────────────────
    $db->prepare('INSERT INTO dl_branches (id,code,name,is_commissary,is_active) VALUES (?,?,?,0,1)')
        ->execute([$store, 'BV-STORE', 'BV Store']);
    $db->prepare('INSERT INTO dl_branches (id,code,name,is_commissary,is_active) VALUES (?,?,?,1,1)')
        ->execute([$comm, 'BV-COMM', 'BV Commissary']);
    $db->prepare('INSERT INTO dl_products (id,sku,name,current_price,is_active) VALUES (?,?,?,10,1)')
        ->execute([$prodCashier, 'BV-CASH', 'BV Cashier Product']);
    $db->prepare('INSERT INTO dl_products (id,sku,name,current_price,is_active) VALUES (?,?,?,10,1)')
        ->execute([$prodCommissary, 'BV-COMM-P', 'BV Commissary Product']);
    $db->prepare('INSERT INTO dl_products (id,sku,name,current_price,is_active) VALUES (?,?,?,10,1)')
        ->execute([$prodGlobal, 'BV-GLOBAL', 'BV Global Product']);
    $db->prepare('INSERT INTO dl_products (id,sku,name,current_price,is_active) VALUES (?,?,?,10,1)')
        ->execute([$prodAll, 'BV-ALL', 'BV All-Active Product']);
    $db->prepare('INSERT INTO dl_products (id,sku,name,current_price,is_active) VALUES (?,?,?,10,1)')
        ->execute([$prodSpecific, 'BV-SPECIFIC', 'BV Specific Product']);
    if ($hasAssignmentMode) {
        $db->prepare("UPDATE dl_products SET assignment_mode = 'all_active' WHERE id = ?")->execute([$prodAll]);
        $db->prepare("UPDATE dl_products SET assignment_mode = 'specific' WHERE id = ?")->execute([$prodSpecific]);
    }
    $activatePair($store, $prodCashier);
    $activatePair($store, $prodGlobal);
    $activatePair($store, $prodAll);
    $activatePair($store, $prodSpecific);
    $activatePair($comm, $prodCommissary);

    // ═════════════════════════════════════════════════════════════════════
    // G1-G5: the cashier-ledger removal guard
    // ═════════════════════════════════════════════════════════════════════
    $h->section('G1-G5 removal guard — cashier ledger predicate');

    // G1: activity-bearing, ending-less row on an OPEN prior day -> REFUSED.
    $resetLedgers();
    $activatePair($store, $prodCashier);
    $setDayStatus($store, $openPrior, 'open');
    $db->prepare('INSERT INTO dl_daily_ledger (branch_id,product_id,ledger_date,shift,beg_bal,addtl,withdraw,bal_end,price_snapshot) VALUES (?,?,?,?,5,0,0,NULL,10)')
        ->execute([$store, $prodCashier, $openPrior, 'PM']);
    if (!$guardAvailable) {
        $h->fail('G1 hidden pair with an activity-bearing ending-less row on an open day is refused (pre-slice base tree: guard function absent)', 'guard missing');
    } else {
        $g1before = $pairActive($store, $prodCashier);
        $g1result = dl_setBranchProductActive($db, $store, $prodCashier, false, 1);
        $g1blockers = $g1result['blockers'] ?? [];
        $h->test('G1 unassignment is REFUSED while the open-day row has no ending', ($g1result['ok'] ?? true) === false && ($g1result['code'] ?? '') === 'PRODUCT_UNASSIGNMENT_BLOCKED', json_encode($g1result));
        $h->test('G1 the blocker list names the exact date+shift (not just a count)', $hasBlocker($g1blockers, $openPrior, 'PM', 'cashier'), json_encode($g1blockers));
        $h->test('G1 the pair stays active after the refusal', $pairActive($store, $prodCashier) === 1 && $g1before === 1, 'is_active=' . $pairActive($store, $prodCashier));
        $g1audit = $db->query("SELECT new_data FROM audit_logs WHERE module='daily-ledger' AND action='branch_product_unassignment_refused' AND entity_id='{$store}-{$prodCashier}' ORDER BY id DESC LIMIT 1")->fetchColumn();
        $g1auditData = is_string($g1audit) ? json_decode($g1audit, true) : null;
        $h->test('G1 the refused attempt is audited with refused=true and the blockers', is_array($g1auditData) && ($g1auditData['refused'] ?? null) === true && !empty($g1auditData['blockers']), (string)$g1audit);

        // G2: the same shape on a CLOSED day does not block.
        $resetLedgers();
        $activatePair($store, $prodCashier);
        $setDayStatus($store, $closedDay, 'closed');
        $db->prepare('INSERT INTO dl_daily_ledger (branch_id,product_id,ledger_date,shift,beg_bal,addtl,withdraw,bal_end,price_snapshot) VALUES (?,?,?,?,5,0,0,NULL,10)')
            ->execute([$store, $prodCashier, $closedDay, 'PM']);
        $g2result = dl_setBranchProductActive($db, $store, $prodCashier, false, 1);
        $h->test('G2 a CLOSED day never blocks (must be unassignable immediately)', ($g2result['ok'] ?? false) === true && $pairActive($store, $prodCashier) === 0, json_encode($g2result) . ' is_active=' . $pairActive($store, $prodCashier));

        // G3: no activity -> does not block.
        $resetLedgers();
        $activatePair($store, $prodCashier);
        $setDayStatus($store, $openPrior, 'open');
        $db->prepare('INSERT INTO dl_daily_ledger (branch_id,product_id,ledger_date,shift,beg_bal,addtl,withdraw,bal_end,price_snapshot) VALUES (?,?,?,?,0,0,0,NULL,10)')
            ->execute([$store, $prodCashier, $openPrior, 'AM']);
        $g3result = dl_setBranchProductActive($db, $store, $prodCashier, false, 1);
        $h->test('G3 an ending-less row with ZERO activity does not block', ($g3result['ok'] ?? false) === true && $pairActive($store, $prodCashier) === 0, json_encode($g3result));

        // G4: ending present -> does not block.
        $resetLedgers();
        $activatePair($store, $prodCashier);
        $setDayStatus($store, $openPrior, 'open');
        $db->prepare('INSERT INTO dl_daily_ledger (branch_id,product_id,ledger_date,shift,beg_bal,addtl,withdraw,bal_end,price_snapshot) VALUES (?,?,?,?,5,0,0,5,10)')
            ->execute([$store, $prodCashier, $openPrior, 'PM']);
        $g4result = dl_setBranchProductActive($db, $store, $prodCashier, false, 1);
        $h->test('G4 an activity-bearing row with a recorded ending does not block', ($g4result['ok'] ?? false) === true && $pairActive($store, $prodCashier) === 0, json_encode($g4result));

        // G5: entering the missing ending unblocks the identical unassignment.
        $resetLedgers();
        $activatePair($store, $prodCashier);
        $setDayStatus($store, $openPrior, 'open');
        $db->prepare('INSERT INTO dl_daily_ledger (branch_id,product_id,ledger_date,shift,beg_bal,addtl,withdraw,bal_end,price_snapshot) VALUES (?,?,?,?,5,0,0,NULL,10)')
            ->execute([$store, $prodCashier, $openPrior, 'PM']);
        $db->prepare('UPDATE dl_daily_ledger SET bal_end = 5 WHERE branch_id=? AND product_id=? AND ledger_date=? AND shift=?')
            ->execute([$store, $prodCashier, $openPrior, 'PM']);
        $g5result = dl_setBranchProductActive($db, $store, $prodCashier, false, 1);
        $h->test('G5 the identical unassignment SUCCEEDS once the missing ending is entered', ($g5result['ok'] ?? false) === true && $pairActive($store, $prodCashier) === 0, json_encode($g5result));
    }

    // ═════════════════════════════════════════════════════════════════════
    // G6: the same five cases for the commissary ledger predicate
    // ═════════════════════════════════════════════════════════════════════
    $h->section('G6 removal guard — commissary ledger predicate');
    if (!$guardAvailable) {
        $h->fail('G6 commissary-ledger removal guard (pre-slice base tree: guard function absent)', 'guard missing');
    } else {
        // G6.1 open day, actual_end_qty NULL, activity -> refused.
        $resetLedgers();
        $activatePair($comm, $prodCommissary);
        $setDayStatus($comm, $openPrior, 'open');
        $db->prepare('INSERT INTO dl_commissary_product_ledger (commissary_branch_id,product_id,ledger_date,shift,beg_qty,produced_qty,dispatched_qty,wastage_qty,actual_end_qty) VALUES (?,?,?,?,5,0,0,0,NULL)')
            ->execute([$comm, $prodCommissary, $openPrior, 'PM']);
        $c1 = dl_setBranchProductActive($db, $comm, $prodCommissary, false, 1);
        $h->test('G6a open-day commissary row with activity and no actual ending is REFUSED', ($c1['ok'] ?? true) === false && ($c1['code'] ?? '') === 'PRODUCT_UNASSIGNMENT_BLOCKED' && $hasBlocker($c1['blockers'] ?? [], $openPrior, 'PM', 'commissary'), json_encode($c1));

        // G6.2 closed day -> not blocked.
        $resetLedgers();
        $activatePair($comm, $prodCommissary);
        $setDayStatus($comm, $closedDay, 'closed');
        $db->prepare('INSERT INTO dl_commissary_product_ledger (commissary_branch_id,product_id,ledger_date,shift,beg_qty,produced_qty,dispatched_qty,wastage_qty,actual_end_qty) VALUES (?,?,?,?,5,0,0,0,NULL)')
            ->execute([$comm, $prodCommissary, $closedDay, 'PM']);
        $c2 = dl_setBranchProductActive($db, $comm, $prodCommissary, false, 1);
        $h->test('G6b the commissary CLOSED day never blocks', ($c2['ok'] ?? false) === true, json_encode($c2));

        // G6.3 zero activity -> not blocked.
        $resetLedgers();
        $activatePair($comm, $prodCommissary);
        $setDayStatus($comm, $openPrior, 'open');
        $db->prepare('INSERT INTO dl_commissary_product_ledger (commissary_branch_id,product_id,ledger_date,shift,beg_qty,produced_qty,dispatched_qty,wastage_qty,actual_end_qty) VALUES (?,?,?,?,0,0,0,0,NULL)')
            ->execute([$comm, $prodCommissary, $openPrior, 'AM']);
        $c3 = dl_setBranchProductActive($db, $comm, $prodCommissary, false, 1);
        $h->test('G6c the commissary zero-activity row does not block', ($c3['ok'] ?? false) === true, json_encode($c3));

        // G6.4 ending present -> not blocked.
        $resetLedgers();
        $activatePair($comm, $prodCommissary);
        $setDayStatus($comm, $openPrior, 'open');
        $db->prepare('INSERT INTO dl_commissary_product_ledger (commissary_branch_id,product_id,ledger_date,shift,beg_qty,produced_qty,dispatched_qty,wastage_qty,actual_end_qty) VALUES (?,?,?,?,5,0,0,0,5)')
            ->execute([$comm, $prodCommissary, $openPrior, 'PM']);
        $c4 = dl_setBranchProductActive($db, $comm, $prodCommissary, false, 1);
        $h->test('G6d the commissary row with an actual ending does not block', ($c4['ok'] ?? false) === true, json_encode($c4));

        // G6.5 after entering the ending -> succeeds.
        $resetLedgers();
        $activatePair($comm, $prodCommissary);
        $setDayStatus($comm, $openPrior, 'open');
        $db->prepare('INSERT INTO dl_commissary_product_ledger (commissary_branch_id,product_id,ledger_date,shift,beg_qty,produced_qty,dispatched_qty,wastage_qty,actual_end_qty) VALUES (?,?,?,?,5,0,0,0,NULL)')
            ->execute([$comm, $prodCommissary, $openPrior, 'PM']);
        $db->prepare('UPDATE dl_commissary_product_ledger SET actual_end_qty = 5 WHERE commissary_branch_id=? AND product_id=? AND ledger_date=? AND shift=?')
            ->execute([$comm, $prodCommissary, $openPrior, 'PM']);
        $c5 = dl_setBranchProductActive($db, $comm, $prodCommissary, false, 1);
        $h->test('G6e the commissary unassignment SUCCEEDS after the ending is entered', ($c5['ok'] ?? false) === true, json_encode($c5));
    }

    // ═════════════════════════════════════════════════════════════════════
    // G7: global deactivation uses the SAME check across all branches
    // ═════════════════════════════════════════════════════════════════════
    $h->section('G7 global deactivation guard');
    if (!$guardAvailable) {
        $h->fail('G7 global deactivation is refused for the same conditions and permitted once completed (pre-slice base tree: guard function absent)', 'guard missing');
    } else {
        $resetLedgers();
        $activatePair($store, $prodGlobal);
        // Give the pair a real branch-product link so the write guard does not confuse the phases.
        $setDayStatus($store, $openPrior, 'open');
        $db->prepare('INSERT INTO dl_daily_ledger (branch_id,product_id,ledger_date,shift,beg_bal,addtl,withdraw,bal_end,price_snapshot) VALUES (?,?,?,?,5,0,0,NULL,10)')
            ->execute([$store, $prodGlobal, $openPrior, 'PM']);
        $g7refused = dl_setProductActive($db, $prodGlobal, false, 1);
        $stillActive = (int)$db->query("SELECT is_active FROM dl_products WHERE id={$prodGlobal}")->fetchColumn();
        $h->test('G7 global deactivation is REFUSED while an actionable unfinished row exists', ($g7refused['ok'] ?? true) === false && ($g7refused['code'] ?? '') === 'PRODUCT_DEACTIVATION_BLOCKED' && $stillActive === 1, json_encode($g7refused));
        $g7blockers = $g7refused['blockers'] ?? [];
        $h->test('G7 the global guard returns the blocker date+shift', $hasBlocker($g7blockers, $openPrior, 'PM', 'cashier'), json_encode($g7blockers));

        // The real product-update endpoint must refuse too (this is the actual write path).
        $g7api = $runApi('update_product', 'admin', [
            'product_id' => $prodGlobal,
            'name' => 'BV Global Product',
            'product_category' => 'bread',
            'price' => 10,
            'sort_order' => 0,
            'is_active' => 0,
            'effective_from' => $today,
        ]);
        $h->test('G7 the real apiUpdateProduct endpoint refuses the deactivation with a machine code', ($g7api['body']['ok'] ?? true) === false && ($g7api['body']['code'] ?? '') === 'PRODUCT_DEACTIVATION_BLOCKED' && (int)$db->query("SELECT is_active FROM dl_products WHERE id={$prodGlobal}")->fetchColumn() === 1, $g7api['raw']);
        $g7audit = $db->query("SELECT actor_module_user_id, new_data FROM audit_logs WHERE module='daily-ledger' AND action='product_deactivation_refused' AND entity_type='product' AND entity_id='{$prodGlobal}' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];
        $g7auditData = isset($g7audit['new_data']) ? json_decode((string)$g7audit['new_data'], true) : null;
        $h->test('G7 the refused endpoint attempt is audited with actor, refused=true and blockers', is_array($g7auditData) && ($g7auditData['refused'] ?? null) === true && !empty($g7auditData['blockers']) && (int)($g7audit['actor_module_user_id'] ?? 0) > 0, json_encode($g7audit));

        // After the ending is entered the deactivation is permitted.
        $db->prepare('UPDATE dl_daily_ledger SET bal_end = 5 WHERE branch_id=? AND product_id=? AND ledger_date=? AND shift=?')
            ->execute([$store, $prodGlobal, $openPrior, 'PM']);
        $g7ok = dl_setProductActive($db, $prodGlobal, true, 1); // normalise to active first
        $g7ok = dl_setProductActive($db, $prodGlobal, false, 1);
        $h->test('G7 after the missing ending is entered the deactivation SUCCEEDS', ($g7ok['ok'] ?? false) === true && (int)$db->query("SELECT is_active FROM dl_products WHERE id={$prodGlobal}")->fetchColumn() === 0, json_encode($g7ok));
        dl_setProductActive($db, $prodGlobal, true, 1);
    }

    // ═════════════════════════════════════════════════════════════════════
    // G8/G9: write-side assignment validation (the discriminating cases)
    // ═════════════════════════════════════════════════════════════════════
    $h->section('G8/G9 write-side assignment validation — PRODUCT_NOT_ASSIGNED');
    $resetLedgers();
    $hidePair($store, $prodCashier);

    // G8: cashier batch save.
    $g8 = $runApi('batch', 'admin', [
        'branch_id' => $store,
        'date' => $today,
        'shift' => 'AM',
        'rows' => [['product_id' => $prodCashier, 'beg_bal' => 3]],
    ]);
    $g8rows = $ledgerRowCount("branch_id={$store} AND product_id={$prodCashier} AND ledger_date='{$today}' AND shift='AM'");
    $h->test('G8 cashier batch save is REJECTED with PRODUCT_NOT_ASSIGNED', ($g8['body']['ok'] ?? true) === false && ($g8['body']['code'] ?? '') === 'PRODUCT_NOT_ASSIGNED', $g8['raw']);
    $h->test('G8 the rejection names the product and the branch', str_contains((string)($g8['body']['error'] ?? ''), 'BV Cashier Product') && str_contains((string)($g8['body']['error'] ?? ''), 'BV Store'), (string)($g8['body']['error'] ?? ''));
    $h->test('G8 NO ledger row is created for the hidden pair (base tree accepts the write)', $g8rows === 0, "rows={$g8rows}");

    // G9a: cashier single-field save.
    $g9field = $runApi('field', 'admin', [
        'branch_id' => $store,
        'product_id' => $prodCashier,
        'field' => 'beg_bal',
        'value' => 4,
        'date' => $today,
        'shift' => 'AM',
    ]);
    $g9fieldRows = $ledgerRowCount("branch_id={$store} AND product_id={$prodCashier} AND ledger_date='{$today}' AND shift='AM'");
    $h->test('G9a cashier single-field save is REJECTED with PRODUCT_NOT_ASSIGNED and writes nothing', ($g9field['body']['code'] ?? '') === 'PRODUCT_NOT_ASSIGNED' && $g9fieldRows === 0, $g9field['raw'] . " rows={$g9fieldRows}");

    // G9b: offline replay worker (direct; the replay loop calls this worker).
    $offlineUser = ['id' => 1, 'sub' => 'admin:1', 'role' => 'admin', 'source' => 'daily-ledger', 'username' => 'offline-harness', 'name' => 'Offline Harness'];
    $offlineThrew = false;
    $offlineCode = null;
    try {
        dl_offlineApplyLedgerSave($offlineUser, ['payload' => [
            'branch_id' => $store,
            'product_id' => $prodCashier,
            'field' => 'beg_bal',
            'value' => 6,
            'date' => $today,
            'shift' => 'AM',
        ]]);
    } catch (\Throwable $e) {
        $offlineThrew = true;
        $offlineCode = class_exists('DlProductNotAssignedException') && $e instanceof DlProductNotAssignedException ? $e->errorCode() : null;
    }
    $offlineRows = $ledgerRowCount("branch_id={$store} AND product_id={$prodCashier} AND ledger_date='{$today}' AND shift='AM'");
    $h->test('G9b offline replay save is REJECTED with PRODUCT_NOT_ASSIGNED and writes nothing', $offlineThrew && $offlineCode === 'PRODUCT_NOT_ASSIGNED' && $offlineRows === 0, "threw=" . var_export($offlineThrew, true) . " code=" . var_export($offlineCode, true) . " rows={$offlineRows}");

    // G9b2: offline replay withdrawal worker (the offline path for withdrawals).
    $offWithdrawThrew = false;
    $offWithdrawCode = null;
    try {
        dl_offlineApplyWithdrawal($offlineUser, ['payload' => [
            'branch_id' => $store,
            'date' => $today,
            'shift' => 'AM',
            'header' => ['withdrawal_type' => 'charge', 'reason_code' => 'manual_adjustment'],
            'lines' => [['product_id' => $prodCashier, 'quantity' => 1, 'unit' => 'pcs']],
        ]]);
    } catch (\Throwable $e) {
        $offWithdrawThrew = true;
        $offWithdrawCode = class_exists('DlProductNotAssignedException') && $e instanceof DlProductNotAssignedException ? $e->errorCode() : null;
    }
    $offWithdrawRows = (int)$db->query("SELECT COUNT(*) FROM dl_cashier_withdrawals WHERE branch_id={$store} AND product_id={$prodCashier}")->fetchColumn();
    $h->test('G9b2 offline replay withdrawal is REJECTED with PRODUCT_NOT_ASSIGNED and writes nothing', $offWithdrawThrew && $offWithdrawCode === 'PRODUCT_NOT_ASSIGNED' && $offWithdrawRows === 0, "threw=" . var_export($offWithdrawThrew, true) . " code=" . var_export($offWithdrawCode, true) . " rows={$offWithdrawRows}");

    // G9c: cashier withdrawal lines.
    $g9withdraw = $runApi('withdrawal', 'admin', [
        'branch_id' => $store,
        'date' => $today,
        'shift' => 'AM',
        'header' => ['withdrawal_type' => 'charge', 'reason_code' => 'manual_adjustment'],
        'lines' => [['product_id' => $prodCashier, 'quantity' => 1, 'unit' => 'pcs']],
    ]);
    $withdrawalRows = (int)$db->query("SELECT COUNT(*) FROM dl_cashier_withdrawals WHERE branch_id={$store} AND product_id={$prodCashier}")->fetchColumn();
    $h->test('G9c cashier withdrawal is REJECTED with PRODUCT_NOT_ASSIGNED and writes no cashier row', ($g9withdraw['body']['code'] ?? '') === 'PRODUCT_NOT_ASSIGNED' && $withdrawalRows === 0, $g9withdraw['raw'] . " rows={$withdrawalRows}");

    // G9d: production movement (output worker).
    $prodUser = ['id' => 1, 'sub' => 'admin:1', 'role' => 'admin', 'source' => 'daily-ledger', 'username' => 'prod-harness', 'name' => 'Prod Harness'];
    $moveThrew = false;
    $moveCode = null;
    try {
        dl_processProductionMovement($prodUser, 'output', [
            'destination_branch_id' => $store,
            'product_id' => $prodCashier,
            'quantity' => 1,
            'ledger_date' => $today,
            'shift' => 'AM',
            'flow_mode' => 'legacy',
        ]);
    } catch (\Throwable $e) {
        $moveThrew = true;
        $moveCode = class_exists('DlProductNotAssignedException') && $e instanceof DlProductNotAssignedException ? $e->errorCode() : null;
    }
    $movementRows = (int)$db->query("SELECT COUNT(*) FROM dl_production_movements WHERE destination_branch_id={$store} AND product_id={$prodCashier}")->fetchColumn();
    $h->test('G9d production movement is REJECTED with PRODUCT_NOT_ASSIGNED and writes no movement', $moveThrew && $moveCode === 'PRODUCT_NOT_ASSIGNED' && $movementRows === 0, "threw=" . var_export($moveThrew, true) . " code=" . var_export($moveCode, true) . " rows={$movementRows}");

    // Re-activate the cashier pair so the no-op proof is a genuine normal write.
    $activatePair($store, $prodCashier);

    // ═════════════════════════════════════════════════════════════════════
    // G10: apiCreateBranch honours assignment_mode
    // ═════════════════════════════════════════════════════════════════════
    $h->section('G10 apiCreateBranch assignment_mode');
    if (!$hasAssignmentMode) {
        $h->fail('G10 apiCreateBranch does not assign a specific product and does assign an all_active one (assignment_mode column absent)', 'schema missing');
    } else {
        $branchCode = 'BV-NEW-' . substr((string)time(), -6);
        $g10 = $runApi('create_branch', 'admin', [
            'code' => $branchCode,
            'name' => 'BV New Branch',
            'is_commissary' => 0,
        ]);
        $newBranchId = (int)($g10['body']['branch_id'] ?? 0);
        if ($newBranchId > 0) {
            $createdBranchIds[] = $newBranchId;
        }
        $allRow = (int)$db->query("SELECT COUNT(*) FROM dl_branch_products WHERE branch_id={$newBranchId} AND product_id={$prodAll} AND is_active=1")->fetchColumn();
        $specificRow = (int)$db->query("SELECT COUNT(*) FROM dl_branch_products WHERE branch_id={$newBranchId} AND product_id={$prodSpecific} AND is_active=1")->fetchColumn();
        $h->test('G10 apiCreateBranch assigns the all_active product to the new branch', ($g10['body']['ok'] ?? false) === true && $newBranchId > 0 && $allRow === 1, $g10['raw'] . " allRow={$allRow}");
        $h->test('G10 apiCreateBranch does NOT assign the specific product to the new branch', $specificRow === 0, "specificRow={$specificRow}");
    }

    // ═════════════════════════════════════════════════════════════════════
    // G11: NO-OP proof on the real invariant (every pair active)
    // ═════════════════════════════════════════════════════════════════════
    $h->section('G11 no-op proof — normal writes still succeed with every pair active');
    $resetLedgers();
    $activatePair($store, $prodCashier);

    $g11batch = $runApi('batch', 'admin', [
        'branch_id' => $store,
        'date' => $today,
        'shift' => 'AM',
        'rows' => [['product_id' => $prodCashier, 'beg_bal' => 7, 'bal_end' => 7]],
    ]);
    $g11row = $db->query("SELECT beg_bal, bal_end FROM dl_daily_ledger WHERE branch_id={$store} AND product_id={$prodCashier} AND ledger_date='{$today}' AND shift='AM'")->fetch(PDO::FETCH_ASSOC) ?: [];
    $h->test('G11 a normal cashier batch save still SUCCEEDS unchanged', ($g11batch['body']['ok'] ?? false) === true && (int)($g11row['beg_bal'] ?? -1) === 7 && (int)($g11row['bal_end'] ?? -1) === 7, $g11batch['raw'] . ' row=' . json_encode($g11row));

    $resetLedgers();
    $activatePair($store, $prodCashier);
    $g11move = null;
    $g11moveError = null;
    try {
        $g11move = dl_processProductionMovement($prodUser, 'output', [
            'destination_branch_id' => $store,
            'product_id' => $prodCashier,
            'quantity' => 2,
            'ledger_date' => $today,
            'shift' => 'AM',
            'flow_mode' => 'legacy',
            'client_op_id' => 'bv-noop-' . substr((string)time(), -6),
        ]);
    } catch (\Throwable $e) {
        $g11moveError = $e->getMessage();
    }
    $g11moveRow = (int)$db->query("SELECT COUNT(*) FROM dl_production_movements WHERE destination_branch_id={$store} AND product_id={$prodCashier}")->fetchColumn();
    $h->test('G11 a normal production movement still SUCCEEDS unchanged', is_array($g11move) && !empty($g11move['movement_id']) && $g11moveRow === 1, json_encode($g11move) . ' error=' . (string)$g11moveError . " rows={$g11moveRow}");
} finally {
    $cleanup();
    if (is_file($payloadFile)) {
        unlink($payloadFile);
    }
}

$tenantAfter = $countTenant();
$h->section('Tenant datum queried before and after');
$h->test(
    'all fixture tables are back to their pre-test counts (cleanup removes every synthetic row)',
    $tenantAfter === $tenantBefore,
    'before=' . json_encode($tenantBefore) . ' after=' . json_encode($tenantAfter)
);
echo '  measured tenant counts: before=' . json_encode($tenantBefore) . ' after=' . json_encode($tenantAfter) . PHP_EOL;

$h->done();
