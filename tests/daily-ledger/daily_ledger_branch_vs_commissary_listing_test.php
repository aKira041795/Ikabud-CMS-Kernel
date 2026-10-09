<?php

declare(strict_types=1);

/**
 * Does turning a product on/off for a BRANCH vs the COMMISSARY work independently?
 *
 * Owner: "see if turning on/off products in a branch vs the commissary now works. meaning each
 * handles its own listing of chosen products."
 *
 * Two different things are being asked, and they have different answers:
 *
 *   A. SEPARATE entities -- branch 8 Miputak (is_commissary=0) and branch 18 RIZAL-COMMIS
 *      (is_commissary=1). These are different branch_ids, so they own different dl_branch_products
 *      rows and MUST be independent. This is the case the owner is asking about.
 *
 *   B. ONE entity that is BOTH -- per ADR-005 a commissary is a branch flagged is_commissary=1, so a
 *      single branch_id feeds both its cashier list and its production sheet from ONE pair row.
 *      Branch 18 is such an entity, and hiding there necessarily affects both of its own surfaces.
 *      That is the documented limit, not a defect, and it is asserted here so the boundary is
 *      visible rather than assumed.
 *
 * The write path is dl_setBranchProductActive(), the same function the admin picker and the
 * branch self-management screen call, so this tests what the operator actually does.
 *
 * LIVE DATA: pairs are snapshotted and restored in a finally block, and the restore is verified.
 */
ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-branch-vs-commissary-listing', TestHarness::MODE_INTEGRATION, 'baronledger.test');
ob_end_clean();
$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/helpers.php';
require_once $base . '/modules/daily-ledger/handlers-deliveries.php';
require_once $base . '/modules/daily-ledger/handlers.php';

app()->tenant()->setTenantId(207);
$ctx = modulePushContext('daily-ledger');
$db = $ctx->db();

$BRANCH = 8;        // Miputak, retail
$COMMISSARY = 18;   // RIZAL-COMMIS, commissary
$PRODUCT = 13;      // CHEESE STREUSEL -- active pair at both, no ledger activity today
$ACTOR = 20;

$date = dl_businessDate();
$shift = 'AM';

/** Product ids the cashier list offers for a branch. */
$cashierProducts = static function (int $branchId) use ($db, $date, $shift): array {
    $rows = dl_fetchCashierLedgerRows($db, $branchId, $date, $shift);
    $ids = [];
    foreach ($rows as $r) {
        $id = (int)($r['product_id'] ?? $r['id'] ?? 0);
        if ($id > 0) { $ids[$id] = true; }
    }
    return array_keys($ids);
};

/** Product ids the production sheet offers for a commissary. */
$sheetProducts = static function (int $branchId) use ($db): array {
    $ids = [];
    foreach (dl_fetchProductionSheetProducts($db, $branchId) as $r) {
        $id = (int)($r['id'] ?? $r['product_id'] ?? 0);
        if ($id > 0) { $ids[$id] = true; }
    }
    return array_keys($ids);
};

$pairState = static function (int $branchId) use ($db): ?int {
    $stmt = $db->prepare('SELECT is_active FROM dl_branch_products WHERE branch_id = :b AND product_id = :p LIMIT 1');
    $stmt->execute([':b' => $branchId, ':p' => 13]);
    $v = $stmt->fetchColumn();
    return $v === false ? null : (int)$v;
};

$snapshot = ['branch' => $pairState($BRANCH), 'commissary' => $pairState($COMMISSARY)];
echo 'snapshot: branch8=' . var_export($snapshot['branch'], true)
    . ' commissary18=' . var_export($snapshot['commissary'], true) . "\n";

try {
    // Baseline: both surfaces must currently offer the product.
    $h->test(
        'baseline: branch 8 offers product 13 on its cashier list',
        in_array($PRODUCT, $cashierProducts($BRANCH), true),
        'cashier list for branch 8 contains ' . $PRODUCT
    );
    $h->test(
        'baseline: commissary 18 offers product 13 on its production sheet',
        in_array($PRODUCT, $sheetProducts($COMMISSARY), true),
        'sheet for branch 18 contains ' . $PRODUCT
    );

    // ── A1: hide at the BRANCH ───────────────────────────────────────────────
    dl_setBranchProductActive($db, $BRANCH, $PRODUCT, false, $ACTOR);

    $branchList = $cashierProducts($BRANCH);
    $commList = $sheetProducts($COMMISSARY);
    $h->test(
        'A1 hiding at the BRANCH removes it from that branch\'s cashier list',
        !in_array($PRODUCT, $branchList, true),
        'branch 8 list has ' . count($branchList) . ' products, contains 13: '
            . (in_array($PRODUCT, $branchList, true) ? 'yes' : 'no')
    );
    $h->test(
        'A1 INDEPENDENCE: the COMMISSARY sheet is unaffected by a branch hide',
        in_array($PRODUCT, $commList, true),
        'branch 18 sheet has ' . count($commList) . ' products, contains 13: '
            . (in_array($PRODUCT, $commList, true) ? 'yes' : 'no')
    );

    // ── A2: hide at the COMMISSARY, with the branch back on ──────────────────
    dl_setBranchProductActive($db, $BRANCH, $PRODUCT, true, $ACTOR);
    dl_setBranchProductActive($db, $COMMISSARY, $PRODUCT, false, $ACTOR);

    $branchList2 = $cashierProducts($BRANCH);
    $commList2 = $sheetProducts($COMMISSARY);
    $h->test(
        'A2 hiding at the COMMISSARY removes it from that commissary\'s production sheet',
        !in_array($PRODUCT, $commList2, true),
        'branch 18 sheet has ' . count($commList2) . ' products, contains 13: '
            . (in_array($PRODUCT, $commList2, true) ? 'yes' : 'no')
    );
    $h->test(
        'A2 INDEPENDENCE: the BRANCH cashier list is unaffected by a commissary hide',
        in_array($PRODUCT, $branchList2, true),
        'branch 8 list has ' . count($branchList2) . ' products, contains 13: '
            . (in_array($PRODUCT, $branchList2, true) ? 'yes' : 'no')
    );

    // ── B: one entity that is BOTH surfaces (ADR-005 boundary) ───────────────
    // Branch 18 is is_commissary=1, so its own cashier list and its own production sheet read the
    // SAME pair row. Hiding there must therefore show up on both of ITS surfaces. Asserted so the
    // limit is demonstrated, not assumed.
    $comm19Cashier = $cashierProducts($COMMISSARY);
    $h->test(
        'B (ADR-005 limit): a hide at a DUAL-ROLE branch also clears its own cashier list',
        !in_array($PRODUCT, $comm19Cashier, true),
        'branch 18 offers the product on its own cashier list: '
            . (in_array($PRODUCT, $comm19Cashier, true) ? 'yes' : 'no')
            . ' -- one branch_id = one pair row, read by both surfaces'
    );
} finally {
    // Restore exactly what was there before.
    if ($snapshot['branch'] !== null) {
        dl_setBranchProductActive($db, $BRANCH, $PRODUCT, $snapshot['branch'] === 1, $ACTOR);
    }
    if ($snapshot['commissary'] !== null) {
        dl_setBranchProductActive($db, $COMMISSARY, $PRODUCT, $snapshot['commissary'] === 1, $ACTOR);
    }

    $after = ['branch' => $pairState($BRANCH), 'commissary' => $pairState($COMMISSARY)];
    echo 'restored: branch8=' . var_export($after['branch'], true)
        . ' commissary18=' . var_export($after['commissary'], true) . "\n";
    $h->test(
        'pairs restored to their snapshot values (no lasting change to live data)',
        $after === $snapshot,
        'before=' . json_encode($snapshot) . ' after=' . json_encode($after)
    );
}

$h->done();
