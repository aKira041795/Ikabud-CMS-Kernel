<?php

declare(strict_types=1);

/**
 * One-off repair: remove the historical artifacts of the "cashier entry emitted
 * to the production commissary" defect (fixed in code 2026-10-13, commit
 * 82294520) from EXISTING data — the live site ran the leaking code until the
 * fix is deployed, so its tables carry the artifacts.
 *
 * WHAT IT REPAIRS (each class proven exactly attributable before anything moves):
 *
 *  A. Phantom branch-side ledger rows on production sites. The pullout-return
 *     flow auto-received the return at the commissary, and the branch receiving
 *     machinery wrote dl_daily_ledger `addtl` on the COMMISSARY. Every such row
 *     is proven attributable: per (commissary, product, date), the rows' addtl
 *     SUM equals the return deliveries' item sum, and their withdraw SUM equals
 *     the paper-capture deliveries' item sum. A group that does not match BOTH
 *     exactly is REPORTED AND LEFT UNTOUCHED (never a blind pattern delete).
 *
 *  B. Paper captures stored as origin_type='branch' with a production site as
 *     the origin. The fixed code stores them as 'commissary' and credits the
 *     departure through the delivery-effects path; these historical rows were
 *     left uncredited (dispatched_qty understated). Repair: normalise the origin
 *     type, then apply the module's OWN effect path (idempotent: item-keyed
 *     effects), so the projection matches the departure derivation again.
 *
 *  C. Auto-received artefact receivings at production sites (count_basis
 *     'copied') plus their 'receiving-count-*' integrity notifications — the
 *     receipt nobody at the production site ever made.
 *
 * WHAT IT DELIBERATELY DOES NOT TOUCH:
 *  - The return deliveries themselves (proof in Pullouts) and the three-way
 *    commissary-ledger credit (produced/wastage) — that accounting is correct.
 *  - Unmatched ledger rows on production sites (none exist on the audited
 *    dumps; a future one is data to investigate, not to delete).
 *  - Audit rows: the append-only trail keeps the original writes; this repair
 *    ADDS its own audit summary instead of rewriting history.
 *  - The wider pre-existing projection drift (legacy NULL-shift buckets,
 *    negative on-hand rows) — out of scope; dl_reconcileCommissaryDispatch()
 *    exists to scope that separately.
 *
 * USAGE
 *   php tools/repair-commissary-sheet-leak-2026-10-13.php            # dry run (default)
 *   php tools/repair-commissary-sheet-leak-2026-10-13.php --apply    # execute
 *   php tools/repair-commissary-sheet-leak-2026-10-13.php --tenant=203 --apply
 *     (--tenant accepts an id, tenant_key, or domain; bare default 207 = LOCAL dev)
 *
 * Idempotent: re-running after --apply finds nothing to do.
 */

$base = dirname(__DIR__);
require_once $base . '/src/helpers/cli-bootstrap.php';

$apply = in_array('--apply', $argv, true);
$tenantSelector = '207'; // LOCAL dev default — pass --tenant=<id|key|domain> elsewhere
foreach ($argv as $arg) {
    if (preg_match('/^--tenant=(.+)$/', $arg, $m)) {
        $tenantSelector = trim($m[1]);
    }
}

$_SERVER['HTTP_HOST'] = 'baronledger.test';
$_SERVER['SERVER_NAME'] = 'baronledger.test';

$app = kernelCliBootstrap($base);

// Resolve and VERIFY the tenant target before touching any module table.
// Bare default = the LOCAL dev tenant id; running that id against a live
// control plane silently targets whatever tenant holds it THERE (measured
// 2026-10-13: live baronledger is tenant 203, while live id 207 was a
// different tenant whose database has no dl_* tables — the raw 42S02 a wrong
// id produces). --tenant accepts an id, a tenant_key, or a domain.
$resolveTenant = static function ($app, string $selector): ?int {
    if (ctype_digit($selector)) {
        return ((int)$selector) > 0 ? (int)$selector : null;
    }
    try {
        $ctrl = $app->controlDb();
        $stmt = $ctrl->prepare('SELECT id FROM kernel_tenants WHERE tenant_key = :sel LIMIT 1');
        $stmt->execute([':sel' => $selector]);
        $id = (int)($stmt->fetchColumn() ?: 0);
        if ($id > 0) return $id;
        $stmt = $ctrl->prepare('SELECT tenant_id FROM kernel_tenant_domains WHERE domain = :sel LIMIT 1');
        $stmt->execute([':sel' => $selector]);
        $id = (int)($stmt->fetchColumn() ?: 0);
        return $id > 0 ? $id : null;
    } catch (\Throwable $e) {
        return null;
    }
};
$tenantId = $resolveTenant($app, $tenantSelector);
if ($tenantId === null) {
    fwrite(STDERR, "Tenant '{$tenantSelector}' could not be resolved (id, tenant_key or domain).\n"
        . "  List tenants with: php ikabud tenant:list\n");
    exit(1);
}
$app->tenant()->setTenantId($tenantId);

// Identity BEFORE the first dl_ query — the failing run printed no banner at
// all because that query died first.
$boundDb = '(unknown)';
try {
    $boundDb = (string)($app->db()->query('SELECT DATABASE()')->fetchColumn() ?: '(unnamed)');
} catch (\Throwable $e) {
    $boundDb = '(connection failed: ' . $e->getMessage() . ')';
}
printf("target: tenant=%d database=%s mode=%s\n", $tenantId, $boundDb, $apply ? 'APPLY' : 'DRY-RUN');

require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/helpers.php';
require_once $base . '/modules/daily-ledger/handlers.php';
modulePushContext('daily-ledger');
$db = module()->db();

// Preflight: refuse a database without the module's tables instead of dying in
// a PDOException stack eight frames deep. Near-certain cause: wrong --tenant.
if ($db->query("SHOW TABLES LIKE 'dl_branches'")->fetchColumn() === false) {
    fwrite(STDERR, "Refusing to run: database '{$boundDb}' has no daily-ledger tables.\n"
        . "Pass the tenant this site actually uses (id | tenant_key | domain):\n"
        . "  php ikabud tenant:list\n"
        . "  php ikabud tenant:verify <id>\n");
    exit(1);
}

$commIds = array_map('intval', $db->query('SELECT id FROM dl_branches WHERE is_commissary = 1')->fetchAll(PDO::FETCH_COLUMN));
if ($commIds === []) {
    fwrite(STDERR, "No commissary branches found for tenant {$tenantId}.\n");
    exit(1);
}
$commSql = implode(',', $commIds);

printf("tenant=%d  commissaries=[%s]  mode=%s\n\n", $tenantId, $commSql, $apply ? 'APPLY' : 'DRY-RUN');

// ── Expected item sums, grouped by (product, date) ────────────────────────────
$expectedA = []; // return deliveries -> the addtl rows they leaked
$rowsA = $db->query(
    "SELECT di.product_id, d.delivery_date, SUM(di.quantity) q
       FROM dl_deliveries d
       INNER JOIN dl_delivery_items di ON di.delivery_id = d.id
      WHERE d.remarks LIKE '%[cashier-pullout-return%' AND d.destination_id IN ({$commSql})
      GROUP BY di.product_id, d.delivery_date"
)->fetchAll(PDO::FETCH_ASSOC);
foreach ($rowsA as $r) {
    $expectedA[(int)$r['product_id'] . '|' . $r['delivery_date']] = (int)$r['q'];
}

$expectedB = []; // captures stored as branch-origin -> the withdraw rows they leaked
$captures = $db->query(
    "SELECT d.id, d.delivery_date, d.production_shift
       FROM dl_deliveries d
      WHERE d.origin_type = 'branch' AND d.origin_id IN ({$commSql})
      ORDER BY d.id"
)->fetchAll(PDO::FETCH_ASSOC);
foreach ($captures as $c) {
    $items = $db->prepare('SELECT product_id, SUM(quantity) q FROM dl_delivery_items WHERE delivery_id = :id GROUP BY product_id');
    $items->execute([':id' => (int)$c['id']]);
    foreach ($items->fetchAll(PDO::FETCH_ASSOC) as $it) {
        $key = (int)$it['product_id'] . '|' . $c['delivery_date'];
        $expectedB[$key] = ($expectedB[$key] ?? 0) + (int)$it['q'];
    }
}

// ── Actual rows on production sites, grouped ─────────────────────────────────
$groups = [];
$rowsG = $db->query(
    "SELECT branch_id, product_id, ledger_date, SUM(addtl) addtl, SUM(withdraw) withdraw, GROUP_CONCAT(id) ids
       FROM dl_daily_ledger
      WHERE branch_id IN ({$commSql})
      GROUP BY branch_id, product_id, ledger_date"
)->fetchAll(PDO::FETCH_ASSOC);
foreach ($rowsG as $g) {
    $groups[(int)$g['product_id'] . '|' . $g['ledger_date']] = $g;
}

$matchedIds = [];
$unmatched = [];
foreach ($groups as $key => $g) {
    $addtlOk = (int)$g['addtl'] === (int)($expectedA[$key] ?? 0);
    $withdrawOk = (int)$g['withdraw'] === (int)($expectedB[$key] ?? 0);
    if ($addtlOk && $withdrawOk) {
        foreach (explode(',', (string)$g['ids']) as $id) {
            $matchedIds[] = (int)$id;
        }
    } else {
        $unmatched[] = $key . ' addtl=' . (int)$g['addtl'] . '(expect ' . (int)($expectedA[$key] ?? 0) . ') withdraw=' . (int)$g['withdraw'] . '(expect ' . (int)($expectedB[$key] ?? 0) . ')';
    }
}
$orphanKeys = array_diff(array_keys($expectedA + $expectedB), array_keys($groups));

printf("A. phantom branch-sheet rows on production sites\n");
printf("   groups matched exactly: %d (rows: %d)\n", count($groups) - count($unmatched), count($matchedIds));
foreach ($unmatched as $u) {
    printf("   !! UNMATCHED (left untouched): %s\n", $u);
}
foreach ($orphanKeys as $k) {
    printf("   !! expected items with NO sheet rows (already clean?): %s\n", $k);
}

// ── B: capture deliveries to normalise + credit ──────────────────────────────
$needCredit = [];
foreach ($captures as $c) {
    $has = (int)$db->query("SELECT COUNT(*) FROM dl_delivery_ledger_effects WHERE delivery_id = " . (int)$c['id'])->fetchColumn();
    if ($has === 0) {
        $needCredit[] = (int)$c['id'];
    }
}
printf("\nB. capture deliveries stored as branch-origin: %d total, %d without effects (to normalise + credit)\n", count($captures), count($needCredit));

// ── C: artefact receivings + notifications ───────────────────────────────────
$artifactReceivings = array_map('intval', $db->query(
    "SELECT r.id FROM dl_branch_receivings r
      INNER JOIN dl_deliveries d ON d.id = r.delivery_id
     WHERE d.remarks LIKE '%[cashier-pullout-return%' AND r.branch_id IN ({$commSql})"
)->fetchAll(PDO::FETCH_COLUMN));
printf("\nC. artefact receivings at production sites: %d\n", count($artifactReceivings));

if (!$apply) {
    printf("\nDRY-RUN complete. Re-run with --apply to execute.\n");
    exit(0);
}

// ── APPLY ────────────────────────────────────────────────────────────────────
$db->beginTransaction();
try {
    $deleted = 0;
    if ($matchedIds !== []) {
        foreach (array_chunk($matchedIds, 500) as $chunk) {
            $delStmt = $db->prepare('DELETE FROM dl_daily_ledger WHERE id IN (' . implode(',', array_map('intval', $chunk)) . ')');
            $delStmt->execute();
            $deleted += $delStmt->rowCount();
        }
    }

    $credited = 0;
    foreach ($needCredit as $deliveryId) {
        $db->prepare("UPDATE dl_deliveries SET origin_type = 'commissary' WHERE id = :id AND origin_type = 'branch'")->execute([':id' => $deliveryId]);
        $result = dl_applyPostedDeliveryCommissaryLedger($db, $deliveryId, 1);
        if (($result['status'] ?? '') === 'applied') {
            $credited += (int)($result['applied'] ?? 0);
        } else {
            printf("   !! credit for delivery %d returned: %s\n", $deliveryId, json_encode($result));
        }
    }

    $rcvRemoved = 0;
    foreach ($artifactReceivings as $rid) {
        $db->prepare('DELETE FROM dl_branch_receiving_items WHERE receiving_id = :id')->execute([':id' => $rid]);
        $db->prepare('DELETE FROM dl_integrity_notification_recipients WHERE notification_id IN (SELECT id FROM dl_integrity_notifications WHERE aggregate_key = :k)')
            ->execute([':k' => 'receiving-count-' . $rid]);
        $db->prepare('DELETE FROM dl_integrity_notifications WHERE aggregate_key = :k')->execute([':k' => 'receiving-count-' . $rid]);
        $db->prepare('DELETE FROM dl_branch_receivings WHERE id = :id')->execute([':id' => $rid]);
        $rcvRemoved++;
    }

    dl_auditLog('repair_commissary_sheet_leak', null, 'dl_daily_ledger', 'bulk-2026-10-13', null, [
        'phantom_rows_deleted' => $deleted,
        'captures_normalised_and_credited' => count($needCredit),
        'effects_applied' => $credited,
        'artifact_receivings_removed' => $rcvRemoved,
        'unmatched_groups_left' => count($unmatched),
        'expected_keys_without_rows' => count($orphanKeys),
        'source' => 'tools/repair-commissary-sheet-leak-2026-10-13.php',
    ]);
    $db->commit();
    printf("\nAPPLIED: phantom rows deleted=%d, captures credited=%d (%d effects), receivings removed=%d; audit summary written.\n", $deleted, count($needCredit), $credited, $rcvRemoved);
} catch (\Throwable $e) {
    $db->rollBack();
    fwrite(STDERR, 'REPAIR FAILED (rolled back): ' . $e->getMessage() . "\n");
    exit(1);
}

// ── VERIFY ───────────────────────────────────────────────────────────────────
$left = (int)$db->query("SELECT COUNT(*) FROM dl_daily_ledger WHERE branch_id IN ({$commSql})")->fetchColumn();
$leftRcv = (int)$db->query("SELECT COUNT(*) FROM dl_branch_receivings WHERE branch_id IN ({$commSql})")->fetchColumn();
$leftEffects = (int)$db->query("SELECT COUNT(*) FROM dl_delivery_ledger_effects WHERE delivery_id IN (" . (count($captures) ? implode(',', array_map(static fn($c): int => (int)$c['id'], $captures)) : '0') . ")")->fetchColumn();
printf("VERIFY: production-site sheet rows remaining=%d (left untouched only if unmatched); receivings remaining=%d; effects for the normalised captures=%d\n", $left, $leftRcv, $leftEffects);
exit($left === 0 && $leftRcv === 0 ? 0 : 2);
