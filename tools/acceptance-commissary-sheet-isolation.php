<?php

declare(strict_types=1);

/**
 * Acceptance criterion: a cashier entry must never be emitted to a production
 * (commissary) branch's own cashier sheet.
 *
 * OWNER, 2026-10-13, verbatim:
 *   "cashier entry is leaked to the production commissary. example, rizal
 *    cashier entry also is emitted to rizal commissary. this should not happen.
 *    review and fix from root cause"
 *
 * MEASURED (tenant 207, the real Rizal data this criterion was written from):
 *   1. Branch 9's cashier recorded a pullout batch (11 withdrawal rows,
 *      2026-10-09 22:33:24). The pullout-return flow auto-received the return at
 *      RIZAL-COMMIS (18) via the BRANCH receiving machinery, and its ledger
 *      effect (`dl_applyLedgerDelta(18, ..., 'addtl')`) wrote 11 rows into the
 *      COMMISSARY's branch-side sheet — encoded by branch 9's cashiers, addtl
 *      sum 119 = exactly the batch. Nobody at the commissary acted; nobody at
 *      the commissary uses that surface (every row on branch 18's sheet comes
 *      from branches 8/9's cashiers).
 *   2. Branch 9's cashier captured a paper DR with source = RIZAL-COMMIS
 *      (delivery 439, `[captured-from-paper-dr]`, origin stored as
 *      origin_type='branch' + origin_id=18). The capture wrote a `withdraw`
 *      onto the commissary's branch-side sheet AND credited nothing into the
 *      commissary ledger (the effects path only applies to origin_type
 *      'commissary' or consignee), so the same dispatch that left the
 *      commissary was invisible in `dispatched_qty`.
 *
 * THE RULE THIS PINS (already stated in the module itself, dl_processProduction
 * Movement): "Do NOT update addtl directly — commissary production is tracked in
 * dl_commissary_product_ledger." A production site's stock lives in the
 * commissary ledger; its branch-side cashier sheet must not be written by
 * another branch's flow.
 *
 * WHAT MUST REMAIN TRUE (controls, green before and after):
 *   - the source branch's own sheet keeps its withdraw; the receiving branch
 *     keeps its addtl; the commissary ledger keeps the three-way return credit
 *     (produced/wastage) and gains the dispatch credit for coerced captures.
 *
 * Both seams the live app uses are covered:
 *   - BEHAVIOUR is exercised through the offline queue workers
 *     (dl_offlineApplyWithdrawal / dl_offlineApplyReceivePaperDr), which now
 *     delegate to the SAME helpers the online handlers call
 *     (dl_persistPulloutReturnToCommissary / dl_normalizePaperDrOriginType);
 *   - the ONLINE handlers are php://input-only and cannot be driven in-process
 *     (php://input is empty in the CLI SAPI - measured on this PHP 8.5.11), so
 *     PARITY is pinned instead: both files must call the shared helpers and the
 *     leaking code must be gone from both.
 *
 * Usage: php tools/acceptance-commissary-sheet-isolation.php
 * Proven RED against the unfixed tree (see the S13-era run in the commit trail):
 * both leaks reproduced end to end through the offline seam.
 */

$base = dirname(__DIR__);
require_once $base . '/src/helpers/cli-bootstrap.php';

$_SERVER['HTTP_HOST'] = 'baronledger.test';
$_SERVER['SERVER_NAME'] = 'baronledger.test';
$_SERVER['REQUEST_URI'] = '/daily-ledger/admin/commissary';

$app = kernelCliBootstrap($base);
$app->tenant()->setTenantId(207);
$app->templates()->enableCompiledMode(false);

require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/helpers.php';
require_once $base . '/modules/daily-ledger/handlers-offline.php';
require_once $base . '/modules/daily-ledger/handlers.php';
modulePushContext('daily-ledger');
$db = module()->db();

$reds = 0;
$check = static function (string $id, string $what, bool $ok, string $detail) use (&$reds): void {
    if (!$ok) {
        $reds++;
    }
    printf("  [%s] %-62s %s\n", $ok ? 'PASS' : 'RED ', $what, $ok ? '' : '<-- not satisfied');
    printf("      %s\n", $detail);
};

// ── Fixtures: throwaway branches + products, fully removed in cleanup ──────────
$comm = 99884;          // fixture production site (commissary)
$src  = 99885;          // fixture cashier branch, supplied by $comm
$date = '2019-06-06';   // throwaway business date
$shift = 'AM';
$pAoff = 998831;        // pullout return (shared flow, via the offline seam)
$pBoff = 998833;        // paper-DR capture (shared flow, via the offline seam)
$allProducts = [$pAoff, $pBoff];
$admin = ['id' => 1, 'role' => 'admin', 'source' => 'daily-ledger'];

$cleanup = static function () use ($db, $comm, $src, $allProducts): void {
    $pidList = implode(',', array_map('intval', $allProducts));
    $ids = array_column(
        $db->query("SELECT id FROM dl_deliveries WHERE origin_id IN ({$comm},{$src}) OR destination_id IN ({$comm},{$src})")->fetchAll(PDO::FETCH_ASSOC) ?: [],
        'id'
    );
    $idList = $ids === [] ? '0' : implode(',', array_map('intval', $ids));
    $rcvIds = array_column(
        $db->query("SELECT id FROM dl_branch_receivings WHERE branch_id IN ({$comm},{$src}) OR delivery_id IN ({$idList})")->fetchAll(PDO::FETCH_ASSOC) ?: [],
        'id'
    );
    $rcvList = $rcvIds === [] ? '0' : implode(',', array_map('intval', $rcvIds));
    // audit_logs.entity_id is VARCHAR: comparing it to bare numbers coerces the
    // column to DOUBLE and trips MySQL 1292 on values like '18-22-2026-09-29'.
    // Quote the lists there; keep numeric lists for integer columns.
    $idListSql = $ids === [] ? "'0'" : implode(',', array_map(static fn($i): string => "'" . (int)$i . "'", $ids));
    $rcvListSql = $rcvIds === [] ? "'0'" : implode(',', array_map(static fn($i): string => "'" . (int)$i . "'", $rcvIds));
    $db->execute("DELETE FROM dl_delivery_ledger_effects WHERE delivery_id IN ({$idList}) OR product_id IN ({$pidList})");
    $db->execute("DELETE FROM dl_branch_receiving_items WHERE receiving_id IN ({$rcvList}) OR product_id IN ({$pidList})");
    $db->execute("DELETE FROM dl_branch_receivings WHERE id IN ({$rcvList}) OR branch_id IN ({$comm},{$src})");
    $db->execute("DELETE FROM dl_integrity_notifications WHERE aggregate_key IN (" . ($rcvIds === [] ? "'x'" : implode(',', array_map(static fn($i): string => "'receiving-count-" . (int)$i . "'", $rcvIds))) . ")");
    $db->execute("DELETE FROM dl_delivery_items WHERE delivery_id IN ({$idList}) OR product_id IN ({$pidList})");
    $db->execute("DELETE FROM dl_deliveries WHERE id IN ({$idList}) OR origin_id IN ({$comm},{$src}) OR destination_id IN ({$comm},{$src})");
    $db->execute("DELETE FROM dl_cashier_withdrawals WHERE branch_id IN ({$comm},{$src}) OR target_branch_id IN ({$comm},{$src})");
    $db->execute("DELETE FROM dl_daily_ledger WHERE branch_id IN ({$comm},{$src})");
    $db->execute("DELETE FROM dl_commissary_product_ledger WHERE commissary_branch_id IN ({$comm},{$src})");
    $db->execute("DELETE FROM audit_logs WHERE (module = 'daily-ledger' AND branch_id IN ({$comm},{$src}))"
        . " OR (entity_type = 'dl_deliveries' AND entity_id IN ({$idListSql}))"
        . " OR (entity_type = 'dl_branch_receivings' AND entity_id IN ({$rcvListSql}))");
    $db->execute("DELETE FROM dl_branch_products WHERE branch_id IN ({$comm},{$src}) OR product_id IN ({$pidList})");
    $db->execute("DELETE FROM dl_products WHERE id IN ({$pidList})");
    $db->execute("DELETE FROM dl_branches WHERE id IN ({$comm},{$src})");
};

$cleanup();
try {
    $db->execute(
        'INSERT INTO dl_branches (id, code, name, address, default_supply_mode, is_commissary, is_active)
         VALUES (:id, :code, :name, :addr, :mode, :comm, 1)',
        [':id' => $comm, ':code' => 'ACCISO-COMM', ':name' => 'Acciso Commissary', ':addr' => 'Test', ':mode' => 'self_managed', ':comm' => 1]
    );
    $db->execute(
        'INSERT INTO dl_branches (id, code, name, address, default_supply_mode, assigned_commissary_id, is_commissary, is_active)
         VALUES (:id, :code, :name, :addr, :mode, :assigned, 0, 1)',
        [':id' => $src, ':code' => 'ACCISO-SRC', ':name' => 'Acciso Source Branch', ':addr' => 'Test', ':mode' => 'commissary_supplied', ':assigned' => $comm]
    );
    foreach ($allProducts as $pid) {
        $db->execute(
            'INSERT INTO dl_products (id, sku, name, current_price, sort_order, is_active) VALUES (:id, :sku, :name, 25.0, 0, 1)',
            [':id' => $pid, ':sku' => 'ACCISO-' . $pid, ':name' => 'Acciso Product ' . $pid]
        );
        foreach ([$src, $comm] as $bid) {
            $db->execute('INSERT INTO dl_branch_products (branch_id, product_id, is_active) VALUES (:b, :p, 1)', [':b' => $bid, ':p' => $pid]);
        }
    }

    $check('P0', 'fixtures present and the formal-delivery flow is enabled', (function () use ($db, $comm, $src): bool {
        $n = (int)$db->query("SELECT COUNT(*) FROM dl_branches WHERE id IN ({$comm},{$src})")->fetchColumn();
        return $n === 2 && dl_isFormalDeliveryEnabled();
    })(), 'branches=' . (int)$db->query("SELECT COUNT(*) FROM dl_branches WHERE id IN ({$comm},{$src})")->fetchColumn() . '; formal_delivery=' . (dl_isFormalDeliveryEnabled() ? 'on' : 'OFF (premise broken)'));

    // ── Parity of the two seams (the online handlers are php://input-only and
    // cannot be driven in-process; they must delegate to the SAME helpers the
    // offline workers call, and must no longer carry the leaking code). ───────
    $handlersSrc = (string)file_get_contents($base . '/modules/daily-ledger/handlers.php');
    $offlineSrc = (string)file_get_contents($base . '/modules/daily-ledger/handlers-offline.php');
    $check('P1', 'both seams persist returns through ONE shared helper',
        substr_count($handlersSrc, 'dl_persistPulloutReturnToCommissary(') === 2
            && substr_count($offlineSrc, 'dl_persistPulloutReturnToCommissary(') === 1,
        'handlers.php occurrences (definition + call): ' . substr_count($handlersSrc, 'dl_persistPulloutReturnToCommissary(') . ' (must be 2); handlers-offline.php calls: ' . substr_count($offlineSrc, 'dl_persistPulloutReturnToCommissary(') . ' (must be 1)'
    );
    $check('P2', 'the auto-receive-at-commissary code is gone from both seams',
        !str_contains($handlersSrc, '$returnReceivingId') && !str_contains($offlineSrc, '$returnReceivingId'),
        'handlers.php still references the receiving id: ' . (str_contains($handlersSrc, '$returnReceivingId') ? 'YES' : 'no') . '; handlers-offline.php: ' . (str_contains($offlineSrc, '$returnReceivingId') ? 'YES' : 'no')
    );
    $check('P3', 'both paper-DR implementations normalize a production-site source',
        substr_count($handlersSrc, 'dl_normalizePaperDrOriginType(') === 2
            && substr_count($offlineSrc, 'dl_normalizePaperDrOriginType(') === 1,
        'handlers.php occurrences (definition + call): ' . substr_count($handlersSrc, 'dl_normalizePaperDrOriginType(') . ' (must be 2); handlers-offline.php calls: ' . substr_count($offlineSrc, 'dl_normalizePaperDrOriginType(') . ' (must be 1)'
    );

    // ── A: pullout return to the commissary ────────────────────────────────
    $commSheetCount = static fn (int $pid): int => (int)$db->query(
        "SELECT COUNT(*) FROM dl_daily_ledger WHERE branch_id = {$comm} AND product_id = {$pid} AND ledger_date = '{$date}'"
    )->fetchColumn();
    $srcWithdraw = static fn (int $pid): int => (int)$db->query(
        "SELECT COALESCE(SUM(withdraw), 0) FROM dl_daily_ledger WHERE branch_id = {$src} AND product_id = {$pid} AND ledger_date = '{$date}'"
    )->fetchColumn();
    $srcAddtl = static fn (int $pid): int => (int)$db->query(
        "SELECT COALESCE(SUM(addtl), 0) FROM dl_daily_ledger WHERE branch_id = {$src} AND product_id = {$pid} AND ledger_date = '{$date}'"
    )->fetchColumn();
    $commProduced = static fn (int $pid): int => (int)$db->query(
        "SELECT COALESCE(SUM(produced_qty), 0) FROM dl_commissary_product_ledger WHERE commissary_branch_id = {$comm} AND product_id = {$pid} AND ledger_date = '{$date}'"
    )->fetchColumn();
    $commDispatched = static fn (int $pid): int => (int)$db->query(
        "SELECT COALESCE(SUM(dispatched_qty), 0) FROM dl_commissary_product_ledger WHERE commissary_branch_id = {$comm} AND product_id = {$pid} AND ledger_date = '{$date}'"
    )->fetchColumn();
    $returnDelivery = static fn (int $pid): ?array => ($db->query(
        "SELECT d.id, d.status, d.receipt_required FROM dl_deliveries d
          INNER JOIN dl_delivery_items di ON di.delivery_id = d.id
         WHERE d.origin_id = {$src} AND d.destination_id = {$comm} AND d.delivery_date = '{$date}'
           AND d.remarks LIKE '%[cashier-pullout-return%' AND di.product_id = {$pid}
         ORDER BY d.id DESC LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC) ?: null);
    $receivingsFor = static fn (int $deliveryId): int => (int)$db->query(
        "SELECT COUNT(*) FROM dl_branch_receivings WHERE delivery_id = {$deliveryId}"
    )->fetchColumn();
    $captureDelivery = static fn (int $pid): ?array => ($db->query(
        "SELECT d.id, d.origin_type FROM dl_deliveries d
          INNER JOIN dl_delivery_items di ON di.delivery_id = d.id
         WHERE d.destination_id = {$src} AND d.delivery_date = '{$date}' AND di.product_id = {$pid}
         ORDER BY d.id DESC LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC) ?: null);
    $effectsFor = static fn (int $deliveryId): int => (int)$db->query(
        "SELECT COUNT(*) FROM dl_delivery_ledger_effects WHERE delivery_id = {$deliveryId} AND effect_status = 'applied'"
    )->fetchColumn();

    // ── A: pullout return to the commissary ────────────────────────────────────
    // The return's accounting: branch withdraw (source) + three-way commissary
    // ledger credit. NOT a receiving at the production site, and NOT a write into
    // the production site's branch-side sheet.
    $pullBody = static fn (int $pid): array => [
        'branch_id' => $src,
        'date' => $date,
        'shift' => $shift,
        'header' => [
            'withdrawal_type' => 'pullout',
            'reason_code' => 'manual_adjustment',
            'target_branch_id' => $comm,
            'dr_number' => 'ACCISO-PULL-' . $pid,
        ],
        'lines' => [['product_id' => $pid, 'quantity' => 7, 'unit' => 'pcs']],
    ];

    $offlineA = dl_offlineApplyWithdrawal($admin, ['type' => 'withdrawal', 'payload' => $pullBody($pAoff)]);

    foreach ([['shared flow (offline seam)', $pAoff, $offlineA]] as [$seam, $pid, $result]) {
        $del = $returnDelivery($pid);
        $ok = is_array($result) && !empty($result['ok']);
        $check('A0', "{$seam}: the pullout saved (premise)", $ok,
            'response=' . substr(json_encode($result['body'] ?? $result), 0, 120));
        $check('A1', "{$seam}: the commissary's sheet gained NOTHING from the return", $commSheetCount($pid) === 0,
            'dl_daily_ledger rows for the commissary: ' . $commSheetCount($pid) . ' (must be 0 - a production site has no branch-side sheet writes)');
        $check('A2', "{$seam}: no receiving was invented at the production site", $del !== null && $receivingsFor((int)$del['id']) === 0,
            $del === null ? 'return delivery not found at all' : ('receivings for the return delivery: ' . $receivingsFor((int)$del['id']) . ' (must be 0)'));
        $check('A3', "{$seam}: the return delivery is proof-only (receipt_required = 0)", $del !== null && $del['receipt_required'] !== null && (int)$del['receipt_required'] === 0,
            $del === null ? 'return delivery not found at all' : ('status=' . $del['status'] . '; receipt_required=' . var_export($del['receipt_required'], true) . ' (must be 0 - never a pending incoming receipt)'));
        $check('A4', "{$seam}: intended accounting kept (source withdraw + commissary produced)", $srcWithdraw($pid) === 7 && $commProduced($pid) === 7,
            'source withdraw=' . $srcWithdraw($pid) . ' (7); commissary produced=' . $commProduced($pid) . ' (7)');
    }

    // ── B: paper-DR capture whose selected source IS the production site ───────
    // The client sends origin_type='branch' with a production-site id. It must be
    // stored and routed as a commissary source: the departure belongs to the
    // commissary ledger (dispatched_qty via the effects path), never to the
    // production site's branch-side sheet.
    $paperBody = static fn (int $pid): array => [
        'branch_id' => $src,
        'origin_type' => 'branch',
        'origin_id' => $comm,
        'dr_number' => 'ACCISO-PAPER-' . $pid,
        'delivery_date' => $date,
        'receive_date' => $date,
        'production_shift' => $shift,
        'shift' => $shift,
        'items' => [['product_id' => $pid, 'quantity' => 5, 'unit' => 'pcs']],
    ];

    $offlineB = dl_offlineApplyReceivePaperDr($admin, ['type' => 'receive_paper_dr', 'payload' => $paperBody($pBoff)]);

    foreach ([['shared flow (offline seam)', $pBoff, $offlineB]] as [$seam, $pid, $result]) {
        $del = $captureDelivery($pid);
        $ok = is_array($result) && !empty($result['ok']);
        $check('B0', "{$seam}: the capture saved (premise)", $ok,
            'response=' . substr(json_encode($result['body'] ?? $result), 0, 120));
        $check('B1', "{$seam}: the commissary's sheet gained no withdraw from the capture", $commSheetCount($pid) === 0,
            'dl_daily_ledger rows for the commissary: ' . $commSheetCount($pid) . ' (must be 0 - the cashier captured a RECEIVE; nothing may leave a mark on the production sheet)');
        $check('B2', "{$seam}: the receiving branch still received (control)", $srcAddtl($pid) === 5,
            'source branch addtl=' . $srcAddtl($pid) . ' (must be 5 - Receive Stock still works)');
        $check('B3', "{$seam}: the departure is credited in the COMMISSARY ledger", $del !== null && $commDispatched($pid) === 5 && $effectsFor((int)$del['id']) === 1,
            $del === null ? 'delivery not found at all' : ('origin_type=' . $del['origin_type'] . ' (must be commissary); commissary dispatched=' . $commDispatched($pid) . ' (5); applied effects=' . $effectsFor((int)$del['id']) . ' (1)'));
    }
} finally {
    $cleanup();
    $left = (int)$db->query("SELECT COUNT(*) FROM dl_daily_ledger WHERE branch_id = {$comm}")->fetchColumn()
        + (int)$db->query("SELECT COUNT(*) FROM dl_commissary_product_ledger WHERE commissary_branch_id = {$comm}")->fetchColumn()
        + (int)$db->query("SELECT COUNT(*) FROM dl_deliveries WHERE origin_id IN ({$comm},{$src}) OR destination_id IN ({$comm},{$src})")->fetchColumn()
        + (int)$db->query("SELECT COUNT(*) FROM dl_branches WHERE id IN ({$comm},{$src})")->fetchColumn();
    $check('C1', 'fixtures fully removed (a leaked row would poison the next run)', $left === 0, "leftover rows: {$left}");
}

if ($reds > 0) {
    printf("\n  => %d RED. The criterion discriminates: this is the work.\n", $reds);
    exit(1);
}
printf("\n  => 0 RED. WARNING: every criterion passes - it cannot discriminate a change.\n");
exit(0);
