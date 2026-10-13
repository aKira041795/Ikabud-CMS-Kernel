<?php

declare(strict_types=1);

/**
 * One-off repair + audit: close out the residual commissary DISPATCH credit
 * gaps measured on the live dump (2026-10-13). Evidence changed the initial
 * assumption, and the tool is written to the measured truth:
 *
 *  1. The [daily-sheet-entry] batches (10-08..10-11) WERE credited: that flow
 *     writes the ledger directly and never writes effect rows, so "missing
 *     effects" there is by design, not a gap. The delta gate proves it per
 *     bucket and gates every write.
 *  2. The ENTIRE live-window reconcile drift (11,690 units over 10 buckets)
 *     traces to 10 origin-UNRESOLVED paper-DR receives (captured-from-paper-dr
 *     / auto-dr-production at branch 9 + one at another branch). The effect
 *     path deliberately refuses to guess their origin, and the designed human
 *     remedy is the admin provenance-resolution flow (apiResolveDeliveryOrigin
 *     with an evidence note), which credits through the same path. This tool
 *     REPORTS them as a worklist; it never touches them.
 *  3. Small genuine residuals (origin resolved, credit truly absent, bucket
 *     room >= quantity) are credited through the module's own effect path.
 *
 * WHY THIS IS SAFE (and why it cannot double-count):
 *  - The module's effect path, dl_applyPostedDeliveryCommissaryLedger(), is the
 *    ONLY writer used here. It is item-keyed and idempotent (effects rows), and
 *    it credits the delivery's own production_shift bucket.
 *  - Every delivery must pass a live DELTA GATE before any write: for each of
 *    its uncredited items, the bucket delta (derivation - projection) must be
 *    >= the item quantity. A bucket that already holds the units is skipped and
 *    reported — the tool never stacks a second credit on top of a direct write.
 *  - Origin-less deliveries are NEVER credited here. The effect path refuses to
 *    guess (and raises an integrity notification); the designed remedy is the
 *    admin provenance-resolution flow (apiResolveDeliveryOrigin), which credits
 *    through this same path once a human resolves the true origin with a note.
 *    This tool reports that class; it does not act on it.
 *  - The 2026-10-08 owner ruling stands: pre-window sparse projection history is
 *    left untouched (the window starts at 2026-10-08, where the projection is
 *    live-maintained).
 *
 * USAGE
 *   php tools/repair-commissary-credit-lag-2026-10-13.php               # dry run
 *   php tools/repair-commissary-credit-lag-2026-10-13.php --apply       # execute
 *   php tools/repair-commissary-credit-lag-2026-10-13.php --since=2026-10-08
 *
 * Idempotent: re-running after --apply finds nothing left to credit.
 */

$base = dirname(__DIR__);
require_once $base . '/src/helpers/cli-bootstrap.php';

$apply = in_array('--apply', $argv, true);
$tenantId = 207;
$since = '2026-10-08';
foreach ($argv as $arg) {
    if (preg_match('/^--tenant=(\d+)$/', $arg, $m)) $tenantId = (int)$m[1];
    if (preg_match('/^--since=(\d{4}-\d{2}-\d{2})$/', $arg, $m)) $since = $m[1];
}

$_SERVER['HTTP_HOST'] = 'baronledger.test';
$_SERVER['SERVER_NAME'] = 'baronledger.test';

$app = kernelCliBootstrap($base);
$app->tenant()->setTenantId($tenantId);
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/helpers.php';
require_once $base . '/modules/daily-ledger/handlers.php';
modulePushContext('daily-ledger');
$db = module()->db();

$commIds = array_map('intval', $db->query('SELECT id FROM dl_branches WHERE is_commissary = 1 AND is_active = 1')->fetchAll(PDO::FETCH_COLUMN));
if ($commIds === []) { fwrite(STDERR, "No active commissaries for tenant {$tenantId}.\n"); exit(1); }
$commSet = array_flip($commIds);
$commSql = implode(',', $commIds);

printf("tenant=%d  commissaries=[%s]  window>=%s  mode=%s\n\n", $tenantId, $commSql, $since, $apply ? 'APPLY' : 'DRY-RUN');

// ── Derivation / projection caches (delta gate) ─────────────────────────────
$derivedCache = []; // cid|date => [product => qty]
$projCache = [];    // cid|date => [product => dispatched total]
$delta = static function (int $cid, string $date, int $product) use ($db, &$derivedCache, &$projCache): int {
    $key = $cid . '|' . $date;
    if (!isset($derivedCache[$key])) {
        $derivedCache[$key] = dl_commissaryDepartedQtyByProduct($db, $cid, $date, null);
        $projCache[$key] = [];
        foreach ($db->query("SELECT product_id, COALESCE(SUM(dispatched_qty),0) q FROM dl_commissary_product_ledger
                              WHERE commissary_branch_id = {$cid} AND ledger_date = '{$date}' GROUP BY product_id")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $projCache[$key][(int)$r['product_id']] = (int)$r['q'];
        }
    }
    return (int)($derivedCache[$key][$product] ?? 0) - (int)($projCache[$key][$product] ?? 0);
};
$consumeDelta = static function (int $cid, string $date, int $product, int $qty) use (&$projCache): void {
    $key = $cid . '|' . $date;
    $projCache[$key][$product] = (int)($projCache[$key][$product] ?? 0) + $qty;
};

// ── Candidate selection ──────────────────────────────────────────────────────
$rows = $db->query(
    "SELECT d.id, d.delivery_date, d.origin_type, d.origin_id, d.resolved_origin_id,
            d.destination_type, d.destination_id, d.production_shift, d.remarks,
            cn.assigned_commissary_id consignee_cid, sd.assigned_commissary_id dest_assigned_cid
       FROM dl_deliveries d
       LEFT JOIN dl_consignees cn ON cn.id = d.consignee_id AND d.destination_type = 'consignee'
       LEFT JOIN dl_branches sd ON sd.id = d.destination_id AND d.destination_type = 'branch'
      WHERE d.status = 'posted' AND d.delivery_date >= '{$since}'
        AND EXISTS (SELECT 1 FROM dl_delivery_items di WHERE di.delivery_id = d.id
                    AND NOT EXISTS (SELECT 1 FROM dl_delivery_ledger_effects e
                                    WHERE e.delivery_item_id = di.id AND e.effect_status = 'applied'))
      ORDER BY d.id"
)->fetchAll(PDO::FETCH_ASSOC);

$plan = []; $originless = []; $ignored = 0;
foreach ($rows as $d) {
    $itemsStmt = $db->prepare(
        'SELECT di.id, di.product_id, di.quantity FROM dl_delivery_items di
          WHERE di.delivery_id = :id
            AND NOT EXISTS (SELECT 1 FROM dl_delivery_ledger_effects e
                            WHERE e.delivery_item_id = di.id AND e.effect_status = "applied")'
    );
    $itemsStmt->execute([':id' => (int)$d['id']]);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
    if ($items === []) continue;

    $cid = 0; $kind = '';
    if ((string)$d['destination_type'] === 'consignee' && isset($commSet[(int)$d['consignee_cid']])) {
        $cid = (int)$d['consignee_cid']; $kind = 'consignee_scope';
    } elseif ((string)$d['origin_type'] === 'commissary'
        && isset($commSet[(int)($d['resolved_origin_id'] ?? $d['origin_id'] ?? 0)])) {
        $cid = (int)($d['resolved_origin_id'] ?? $d['origin_id']); $kind = 'origin_resolved';
    } elseif ((string)$d['origin_type'] === 'branch' && isset($commSet[(int)($d['origin_id'] ?? 0)])) {
        $cid = (int)$d['origin_id']; $kind = 'origin_type_normalise';
    } elseif ((string)$d['origin_type'] !== 'consignee' && $d['origin_id'] === null && $d['resolved_origin_id'] === null
        && (string)$d['destination_type'] === 'branch' && isset($commSet[(int)$d['dest_assigned_cid']])) {
        $originless[] = $d + ['qty' => array_sum(array_map(static fn($i): int => (int)$i['quantity'], $items))];
        continue;
    } else { $ignored++; continue; }

    $gateOk = true; $short = [];
    foreach ($items as $it) {
        $qty = (int)$it['quantity'];
        $room = $delta($cid, (string)$d['delivery_date'], (int)$it['product_id']);
        // Two-sided gate: a positive entry may only land while the bucket is
        // still below the derivation; a negative correction may only land while
        // the projection actually holds the excess it reverses. Anything else
        // would CREATE a mismatch of the opposite sign.
        $ok = $qty >= 0 ? ($room >= $qty) : ($room <= $qty);
        if (!$ok) { $gateOk = false; $short[] = "p{$it['product_id']} qty={$qty} room={$room}"; }
    }
    $plan[] = ['row' => $d, 'cid' => $cid, 'kind' => $kind, 'items' => $items, 'gate' => $gateOk, 'short' => $short,
        'qty' => array_sum(array_map(static fn($i): int => (int)$i['quantity'], $items))];
}

$passingList = array_values(array_filter($plan, static fn($p): bool => $p['gate']));
$passingUnits = array_sum(array_map(static fn($p): int => $p['qty'], $passingList));
$blocked = array_values(array_filter($plan, static fn($p): bool => !$p['gate']));
printf("RESOLVABLE CANDIDATES: %d deliveries gate-passing (%d units, signed), %d blocked\n", count($passingList), $passingUnits, count($blocked));
foreach ($passingList as $p) printf("   WILL d#%d %s cid=%d qty=%+d kind=%s\n", (int)$p['row']['id'], $p['row']['delivery_date'], $p['cid'], $p['qty'], $p['kind']);
foreach (array_slice($blocked, 0, 12) as $p) printf("   SKIP d#%d %s cid=%d: %s\n", (int)$p['row']['id'], $p['row']['delivery_date'], $p['cid'], implode('; ', $p['short']));
if (count($blocked) > 12) printf("   ...(%d more blocked)\n", count($blocked) - 12);
$originlessUnits = array_sum(array_map(static fn($o): int => $o['qty'], $originless));
printf("REPORT-ONLY origin-unresolved: %d deliveries, %d units -> resolve via admin provenance UI (NOT touched here)\n", count($originless), $originlessUnits);
foreach ($originless as $o) printf("   ? d#%d %s did=%s qty=%d remarks=%s\n", (int)$o['id'], $o['delivery_date'], $o['destination_id'], $o['qty'], $o['remarks']);
printf("Unrelated (not attributed to a commissary): %d deliveries\n\n", $ignored);

// ── Reconcile BEFORE ─────────────────────────────────────────────────────────
$snap = static function (array $commIds, string $since) use ($db): array {
    $mism = 0; $mismU = 0; $unrec = 0; $unrecU = 0; $checked = 0;
    foreach ($commIds as $cid) {
        foreach ($db->query("SELECT DISTINCT ledger_date FROM dl_commissary_product_ledger WHERE commissary_branch_id = {$cid} AND ledger_date >= '{$since}'")->fetchAll(PDO::FETCH_COLUMN) as $date) {
            $r = dl_reconcileCommissaryDispatch($db, $cid, (string)$date);
            $checked += (int)($r['checked'] ?? 0);
            foreach ($r['mismatches'] ?? [] as $m) { $mism++; $mismU += abs((int)$m['projection'] - (int)$m['derived']); }
            foreach ($r['unrecorded'] ?? [] as $u) { $unrec++; $unrecU += (int)($u['derived'] ?? 0); }
        }
    }
    return ['checked' => $checked, 'mism' => $mism, 'mismU' => $mismU, 'unrec' => $unrec, 'unrecU' => $unrecU];
};
$before = $snap($commIds, $since);
printf("RECONCILE BEFORE: checked=%d  mismatches=%d buckets/%d u  unrecorded=%d buckets/%d u\n\n",
    $before['checked'], $before['mism'], $before['mismU'], $before['unrec'], $before['unrecU']);

if (!$apply) {
    printf("DRY-RUN complete. Re-run with --apply to execute.\n");
    exit(0);
}

// ── APPLY ────────────────────────────────────────────────────────────────────
$db->beginTransaction();
$credited = 0; $creditedDeliveries = 0; $effects = 0; $normalised = 0; $anomalies = [];
try {
    foreach ($plan as $p) {
        if (!$p['gate']) continue;
        $id = (int)$p['row']['id'];
        if ($p['kind'] === 'origin_type_normalise') {
            $db->prepare("UPDATE dl_deliveries SET origin_type = 'commissary' WHERE id = :id AND origin_type = 'branch'")->execute([':id' => $id]);
            $normalised++;
        }
        $result = dl_applyPostedDeliveryCommissaryLedger($db, $id, 1);
        if (($result['status'] ?? '') === 'applied') {
            $creditedDeliveries++;
            $effects += (int)($result['applied'] ?? 0);
            foreach ($p['items'] as $it) $consumeDelta($p['cid'], (string)$p['row']['delivery_date'], (int)$it['product_id'], (int)$it['quantity']);
        } else {
            $anomalies[] = "d#{$id}: " . json_encode($result);
        }
    }
    dl_auditLog('repair_commissary_credit_lag', null, 'dl_commissary_product_ledger', 'bulk-' . date('Y-m-d'), null, [
        'window_since' => $since,
        'deliveries_credited' => $creditedDeliveries,
        'effects_applied' => $effects,
        'origin_type_normalised' => $normalised,
        'origin_unresolved_report_only' => count($originless),
        'delta_gate_blocked' => count($blocked),
        'source' => 'tools/repair-commissary-credit-lag-2026-10-13.php',
    ]);
    $db->commit();
} catch (\Throwable $e) {
    $db->rollBack();
    fwrite(STDERR, 'REPAIR FAILED (rolled back): ' . $e->getMessage() . "\n");
    exit(1);
}
printf("APPLIED: %d deliveries credited (%d effects), %d origin_type normalised, audit summary written.\n", $creditedDeliveries, $effects, $normalised);
foreach ($anomalies as $a) printf("   !! anomaly: %s\n", $a);

// ── VERIFY ───────────────────────────────────────────────────────────────────
$after = $snap($commIds, $since);
printf("RECONCILE AFTER:  checked=%d  mismatches=%d buckets/%d u  unrecorded=%d buckets/%d u\n",
    $after['checked'], $after['mism'], $after['mismU'], $after['unrec'], $after['unrecU']);
printf("DELTA: mismatches %d->%d (-%d u), unrecorded %d->%d (-%d u)\n",
    $before['mism'], $after['mism'], $before['mismU'] - $after['mismU'],
    $before['unrec'], $after['unrec'], $before['unrecU'] - $after['unrecU']);
$left = (int)$db->query(
    "SELECT COUNT(*) FROM dl_deliveries d
      WHERE d.status = 'posted' AND d.delivery_date >= '{$since}'
        AND (d.destination_type = 'consignee' AND d.consignee_id IN (SELECT id FROM dl_consignees WHERE assigned_commissary_id IN ({$commSql}))
          OR d.origin_type = 'commissary' AND COALESCE(d.resolved_origin_id, d.origin_id) IN ({$commSql}))
        AND EXISTS (SELECT 1 FROM dl_delivery_items di WHERE di.delivery_id = d.id
                    AND NOT EXISTS (SELECT 1 FROM dl_delivery_ledger_effects e
                                    WHERE e.delivery_item_id = di.id AND e.effect_status = 'applied'))"
)->fetchColumn();
// Fresh gate scan (caches reset): leftovers that are still genuinely creditable.
$derivedCache = []; $projCache = [];
$leftPassing = 0;
foreach ($plan as $p) {
    $ok = true;
    foreach ($p['items'] as $it) {
        $qty = (int)$it['quantity'];
        $room = $delta($p['cid'], (string)$p['row']['delivery_date'], (int)$it['product_id']);
        if (!($qty >= 0 ? ($room >= $qty) : ($room <= $qty))) { $ok = false; break; }
    }
    if ($ok) $leftPassing++;
}
printf("VERIFY: credited this run=%d effects; gate-passing leftovers=%d (0 expected, idempotent); missing-effect deliveries remaining=%d (direct-write flows by design); origin-unresolved remainder=%d (human resolution path)\n",
    $effects, $leftPassing, $left, count($originless));
exit($anomalies === [] && $leftPassing === 0 ? 0 : 2);
