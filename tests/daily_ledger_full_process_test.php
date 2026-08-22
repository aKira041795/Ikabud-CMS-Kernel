<?php

declare(strict_types=1);

/**
 * Daily Ledger — Full Process Stress Test
 *
 * User: Ledger-Admin (role: admin, id: 1) on baroninventory.test
 *
 * Exercises every major subsystem end-to-end:
 *   S0. Auth identity + feature-flag contract
 *   S1. Pricing groups — price resolution (regular + mall)
 *   S2. Usage / commissary production runs (DR-backed branch output)
 *   S3. Formal delivery auto-sync from production runs
 *   S4. Delivery posting + branch receiving with variance
 *   S5. Cashier withdrawals — all six reason types
 *   S6. Selling accounts — delivery → ledger formula
 *   S7. Branch consolidated summary
 *
 * No HTTP — calls helpers directly after module bootstrap.
 * Creates uniquely-suffixed rows; cleans up in finally block.
 */

$_SERVER['HTTP_HOST']  = $_SERVER['HTTP_HOST']  ?? 'baronledger.test';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/daily-ledger/admin/usage';
$_SERVER['REQUEST_METHOD'] = 'GET';

ini_set('display_errors', '1');
error_reporting(E_ALL);

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../modules/daily-ledger/helpers.php';
require_once __DIR__ . '/../modules/daily-ledger/handlers.php';
require_once __DIR__ . '/../modules/daily-ledger/handlers-deliveries.php';

// ─── counters ──────────────────────────────────────────────────────────────
$pass   = 0;
$fail   = 0;
$errors = [];

function fp(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $errors;
    if ($ok) {
        $pass++;
        echo "  ✓  {$label}\n";
    } else {
        $fail++;
        $errors[] = $label . ($detail !== '' ? ': ' . $detail : '');
        echo "  ✗  {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

// Clear logs before run
@file_put_contents(STORAGE_PATH . '/logs/app.log', '');
@file_put_contents(STORAGE_PATH . '/logs/error.log', '');

echo "\n╔══════════════════════════════════════════════════════════╗\n";
echo "║  DAILY LEDGER — FULL PROCESS STRESS TEST                ║\n";
echo "║  Actor: Ledger-Admin (admin, id=1)                      ║\n";
echo "╚══════════════════════════════════════════════════════════╝\n\n";

// ─── bootstrap module context ─────────────────────────────────────────────
$ctx = modulePushContext('daily-ledger');
if (!$ctx) {
    fwrite(STDERR, "FATAL: daily-ledger module context unavailable\n");
    exit(1);
}
$pdo = $ctx->db();

// Synthetic Ledger-Admin user (matches real row: id=1, role=admin)
$adminUser = [
    'id'     => 1,
    'sub'    => 'admin:1',
    'role'   => 'admin',
    'source' => 'daily-ledger',
    'username' => 'Ledger-Admin',
];

$suffix = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
$today  = date('Y-m-d');

// Tracking lists for teardown
$trackedBranchIds    = [];
$trackedProductIds   = [];
$trackedPriceGroupIds = [];
$trackedDeliveryIds  = [];
$trackedReceivingIds = [];
$trackedAccountIds   = [];
$trackedRunIds       = [];
$trackedMovementIds  = [];
$retailBranchId      = 0;
$commissaryBranchId  = 0;

try {
// ═══════════════════════════════════════════════════════════════════
try {
    // ═══════════════════════════════════════════════════════════════════
    // S0 — AUTH IDENTITY + FEATURE FLAGS
    // ═══════════════════════════════════════════════════════════════════
    echo "── S0: Auth identity + feature flags ──\n";

    $userRow = $pdo->prepare('SELECT id, username, role FROM dl_users WHERE id = 1 AND is_active = 1 LIMIT 1');
    $userRow->execute();
    $ledgerAdmin = $userRow->fetch(PDO::FETCH_ASSOC);

    fp('Ledger-Admin user exists in dl_users',
        $ledgerAdmin !== false && (string)($ledgerAdmin['username'] ?? '') === 'Ledger-Admin');
    fp('Ledger-Admin role is admin',
        (string)($ledgerAdmin['role'] ?? '') === 'admin');

    $features = dl_featureSettings();
    fp('price_groups_enabled = ON',      !empty($features['price_groups_enabled']));
    fp('formal_delivery_workflow_enabled = ON', !empty($features['formal_delivery_workflow_enabled']));
    fp('selling_accounts_enabled = ON',  !empty($features['selling_accounts_enabled']));
    fp('production_output_enabled = ON', !empty($features['production_output_enabled']));

    fp('dl_canManageFeatureActivation(admin) = true',
        dl_canManageFeatureActivation($adminUser));
    fp('dl_isKernelAdmin(daily-ledger admin) = false',
        !dl_isKernelAdmin($adminUser),
        'daily-ledger admins are not kernel admins');
    fp('dl_roleHasPermission admin ledger.override',
        dl_roleHasPermission('admin', 'ledger.override'));
    fp('dl_roleHasPermission admin production.override',
        dl_roleHasPermission('admin', 'production.override'));

    $actorId = dl_getActorUserId($adminUser);
    fp('dl_getActorUserId returns 1 for Ledger-Admin',
        $actorId === 1, "got {$actorId}");

    // ═══════════════════════════════════════════════════════════════════
    // S1 — PRICING GROUPS
    // ═══════════════════════════════════════════════════════════════════
    echo "\n── S1: Pricing groups ──\n";

    // Self-ensure a default price group exists. The tenant DB may not carry
    // the migration seed ("Regular Branch Pricing") — create it idempotently
    // so the default-group resolution contract is exercised deterministically.
    $defaultGroupId = dl_defaultPriceGroupId();
    if ($defaultGroupId === null || $defaultGroupId <= 0) {
        $pdo->prepare('INSERT INTO dl_price_groups (name, type, is_default, is_active) VALUES (:n, "branch", 1, 1)')
            ->execute([':n' => 'Regular Branch Pricing-' . $suffix]);
        $defaultGroupId = (int)$pdo->lastInsertId();
        $trackedPriceGroupIds[] = $defaultGroupId;
    }
    fp('default price group exists', $defaultGroupId !== null && $defaultGroupId > 0,
        "got " . var_export($defaultGroupId, true));

    // Create a "Stress-Mall" price group for this test run
    $pdo->prepare('INSERT INTO dl_price_groups (name, type) VALUES (:n, "mall")')
        ->execute([':n' => 'Stress-Mall-' . $suffix]);
    $stressMallGroupId = (int)$pdo->lastInsertId();
    $trackedPriceGroupIds[] = $stressMallGroupId;
    fp('create stress-mall price group', $stressMallGroupId > 0);

    // Seed the two test products (PANDESAL @ 30.00, MONAY @ 25.00) instead of
    // assuming fixed fixture ids 10/11 — the base DB may not carry them.
    foreach ([
        ['sku' => 'PANDESAL-' . $suffix, 'name' => 'PANDESAL ' . $suffix, 'price' => 30.00],
        ['sku' => 'MONAY-' . $suffix,    'name' => 'MONAY ' . $suffix,    'price' => 25.00],
    ] as $idx => $prod) {
        $pdo->prepare(
            'INSERT INTO dl_products (sku, name, current_price, sort_order, is_active)
             VALUES (:sku, :n, :p, :so, 1)'
        )->execute([
            ':sku' => $prod['sku'],
            ':n'   => $prod['name'],
            ':p'   => $prod['price'],
            ':so'  => $idx,
        ]);
        $trackedProductIds[] = (int)$pdo->lastInsertId();
    }
    $productAId = $trackedProductIds[0]; // PANDESAL — current_price 30.00
    $productBId = $trackedProductIds[1]; // MONAY    — current_price 25.00

    // Assign mall-specific price: PANDESAL @ 45.00 for the stress-mall group
    $pdo->prepare(
        'INSERT INTO dl_product_prices (product_id, price_group_id, selling_price, effective_from, is_active)
         VALUES (:p, :g, 45.00, "2020-01-01", 1)
         ON DUPLICATE KEY UPDATE selling_price = 45.00, is_active = 1'
    )->execute([':p' => $productAId, ':g' => $stressMallGroupId]);

    $priceDefault = dl_resolveProductPrice($productAId, $defaultGroupId, $today);
    $priceMall    = dl_resolveProductPrice($productAId, $stressMallGroupId, $today);
    $priceFallback = dl_resolveProductPrice($productBId, $stressMallGroupId, $today);

    fp('PANDESAL resolves to current_price 30.00 via default group',
        abs($priceDefault - 30.00) < 0.001, "got {$priceDefault}");
    fp('PANDESAL resolves to 45.00 via stress-mall group',
        abs($priceMall - 45.00) < 0.001, "got {$priceMall}");
    fp('MONAY falls back to current_price 25.00 (unmapped in stress-mall)',
        abs($priceFallback - 25.00) < 0.001, "got {$priceFallback}");

    // Verify price_groups_enabled gate: temporarily disable and confirm fallback
    // (use dl_resolveProductPrice directly; it checks the flag)
    // We do NOT disable in DB — we test by calling the function while it reads live settings.
    // Since feature is ON, both paths should return actual prices.
    $priceGroupsOn = dl_arePriceGroupsEnabled();
    fp('dl_arePriceGroupsEnabled() = true (live flag)', $priceGroupsOn);

    // ═══════════════════════════════════════════════════════════════════
    // S2 — COMMISSARY BRANCH + USAGE / PRODUCTION RUNS
    // ═══════════════════════════════════════════════════════════════════
    echo "\n── S2: Commissary branch + production runs (usage) ──\n";

    // Create a commissary branch for this test
    $pdo->prepare(
        'INSERT INTO dl_branches (code, name, address, is_active, default_supply_mode, is_commissary)
         VALUES (:c, :n, "Stress Test Commissary", 1, "self_managed", 1)'
    )->execute([':c' => 'SCMSY-' . $suffix, ':n' => 'Stress Commissary ' . $suffix]);
    $commissaryBranchId = (int)$pdo->lastInsertId();
    $trackedBranchIds[] = $commissaryBranchId;
    fp('commissary branch created', $commissaryBranchId > 0);

    // Create a retail branch assigned to this commissary
    $pdo->prepare(
        'INSERT INTO dl_branches (code, name, address, is_active, default_supply_mode, assigned_commissary_id, is_commissary)
         VALUES (:c, :n, "Stress Retail Addr", 1, "commissary_supplied", :ac, 0)'
    )->execute([':c' => 'SRTL-' . $suffix, ':n' => 'Stress Retail ' . $suffix, ':ac' => $commissaryBranchId]);
    $retailBranchId = (int)$pdo->lastInsertId();
    $trackedBranchIds[] = $retailBranchId;
    fp('retail branch (commissary-supplied) created', $retailBranchId > 0);

    // Supply source resolution
    $src = dl_resolveProductSupplySource($retailBranchId, $productAId);
    fp('retail branch supply resolves to commissary by default',
        ($src['source'] ?? '') === 'commissary' && (int)($src['source_id'] ?? 0) === $commissaryBranchId,
        json_encode($src));

    // Enter 3 production runs directed at retailBranchId, DR# = STRESS-DR-001-{suffix}
    $drStress = 'STRESS-DR-001-' . $suffix;

    $runInsert = $pdo->prepare(
        'INSERT INTO dl_production_runs
            (ledger_date, product_id, baker_name, run_type, primary_input_qty, primary_input_type,
             yield_qty, dr_number, destination_branch_id, recorded_by)
         VALUES (:d, :pid, :baker, "regular", :iqty, "kilo", :yield, :dr, :dest, :actor)'
    );
    // Run 1: PANDESAL, 5 kilo → 100 pcs
    $runInsert->execute([
        ':d'     => $today, ':pid'   => $productAId,
        ':baker' => 'Maria-' . $suffix, ':iqty' => 5.0,
        ':yield' => 100, ':dr'    => $drStress,
        ':dest'  => $retailBranchId, ':actor' => $actorId,
    ]);
    $run1Id = (int)$pdo->lastInsertId();
    $trackedRunIds[] = $run1Id;
    fp('production run 1: PANDESAL 100 pcs → retail branch', $run1Id > 0);

    // Run 2: MONAY, 3 kilo → 60 pcs
    $runInsert->execute([
        ':d'     => $today, ':pid'   => $productBId,
        ':baker' => 'Maria-' . $suffix, ':iqty' => 3.0,
        ':yield' => 60, ':dr'    => $drStress,
        ':dest'  => $retailBranchId, ':actor' => $actorId,
    ]);
    $run2Id = (int)$pdo->lastInsertId();
    $trackedRunIds[] = $run2Id;
    fp('production run 2: MONAY 60 pcs → retail branch', $run2Id > 0);

    // Also enter a "keep-in-commissary" run (no destination branch)
    $runInsert->execute([
        ':d'     => $today, ':pid'   => $productAId,
        ':baker' => 'Pedro-' . $suffix, ':iqty' => 2.0,
        ':yield' => 40, ':dr'    => null,
        ':dest'  => null, ':actor' => $actorId,
    ]);
    $run3Id = (int)$pdo->lastInsertId();
    $trackedRunIds[] = $run3Id;
    fp('production run 3: PANDESAL 40 pcs keep-in-commissary (no dest)', $run3Id > 0);

    // Validate stored runs
    $runChk = $pdo->prepare('SELECT COUNT(*) FROM dl_production_runs WHERE destination_branch_id = :dest AND ledger_date = :d AND dr_number = :dr');
    $runChk->execute([':dest' => $retailBranchId, ':d' => $today, ':dr' => $drStress]);
    fp('both branch-directed runs exist in dl_production_runs', (int)$runChk->fetchColumn() === 2);

    // ═══════════════════════════════════════════════════════════════════
    // S3 — FORMAL DELIVERY AUTO-SYNC
    // ═══════════════════════════════════════════════════════════════════
    echo "\n── S3: Formal delivery auto-sync from production runs ──\n";

    $deliveryId = dl_syncAutoCommissaryDeliveryFromRuns($pdo, $today, $retailBranchId, $drStress, $actorId);
    fp('formal delivery auto-created from runs', $deliveryId !== null && $deliveryId > 0,
        "deliveryId=" . var_export($deliveryId, true));
    if ($deliveryId) {
        $trackedDeliveryIds[] = $deliveryId;
    }

    // Verify delivery header
    $delHdr = $pdo->prepare('SELECT origin_type, destination_type, destination_id, status, dr_number FROM dl_deliveries WHERE id = :id');
    $delHdr->execute([':id' => $deliveryId]);
    $dh = $delHdr->fetch(PDO::FETCH_ASSOC);

    fp('delivery origin_type = commissary', ($dh['origin_type'] ?? '') === 'commissary');
    fp('delivery destination_type = branch', ($dh['destination_type'] ?? '') === 'branch');
    fp('delivery destination_id = retail branch', (int)($dh['destination_id'] ?? 0) === $retailBranchId);
    fp('delivery status = posted', ($dh['status'] ?? '') === 'posted');
    fp('delivery DR number matches', ($dh['dr_number'] ?? '') === $drStress);

    // Verify delivery items: PANDESAL=100, MONAY=60
    $delItems = $pdo->prepare('SELECT product_id, quantity FROM dl_delivery_items WHERE delivery_id = :id ORDER BY product_id');
    $delItems->execute([':id' => $deliveryId]);
    $itemRows = $delItems->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $itemMap = array_column($itemRows, 'quantity', 'product_id');

    fp('delivery item PANDESAL qty=100', (int)($itemMap[$productAId] ?? 0) === 100,
        "got " . ($itemMap[$productAId] ?? 'missing'));
    fp('delivery item MONAY qty=60', (int)($itemMap[$productBId] ?? 0) === 60,
        "got " . ($itemMap[$productBId] ?? 'missing'));

    // Re-sync (idempotency: calling again with same runs should return same delivery, no duplicate)
    $deliveryId2 = dl_syncAutoCommissaryDeliveryFromRuns($pdo, $today, $retailBranchId, $drStress, $actorId);
    fp('re-sync returns same delivery id (idempotent)',
        $deliveryId2 === $deliveryId, "first={$deliveryId} second={$deliveryId2}");

    $delCnt = $pdo->prepare('SELECT COUNT(*) FROM dl_deliveries WHERE destination_id = :dest AND dr_number = :dr AND delivery_date = :d');
    $delCnt->execute([':dest' => $retailBranchId, ':dr' => $drStress, ':d' => $today]);
    fp('no duplicate delivery created by re-sync', (int)$delCnt->fetchColumn() === 1);

    // ═══════════════════════════════════════════════════════════════════
    // S4 — BRANCH RECEIVING + VARIANCE
    // ═══════════════════════════════════════════════════════════════════
    echo "\n── S4: Branch receiving + variance flag ──\n";

    // Cashier receives 95 of PANDESAL (short 5), all 60 of MONAY
    $rcvId = dl_acceptFormalDelivery($pdo, $retailBranchId, $deliveryId, $actorId, $today);
    fp('dl_acceptFormalDelivery returns receiving id > 0',
        $rcvId > 0, "got {$rcvId}");
    if ($rcvId > 0) {
        $trackedReceivingIds[] = $rcvId;
    }

    // Check dl_branch_receivings header
    $rcvHdr = $pdo->prepare(
        'SELECT branch_id, delivery_id, status, dr_number FROM dl_branch_receivings WHERE id = :id'
    );
    $rcvHdr->execute([':id' => $rcvId]);
    $rh = $rcvHdr->fetch(PDO::FETCH_ASSOC);

    fp('receiving branch_id matches retail branch', (int)($rh['branch_id'] ?? 0) === $retailBranchId);
    fp('receiving delivery_id matches', (int)($rh['delivery_id'] ?? 0) === $deliveryId);

    // Check dl_daily_ledger addtl was updated by dl_acceptFormalDelivery
    $checkLedger = function(int $branchId, int $productId, string $date) use ($pdo): array {
        $stmt = $pdo->prepare(
            'SELECT addtl, withdraw, beg_bal, bal_end, sales
               FROM dl_daily_ledger
              WHERE branch_id = :b AND product_id = :p AND ledger_date = :d'
        );
        $stmt->execute([':b' => $branchId, ':p' => $productId, ':d' => $date]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    };

    $ledA = $checkLedger($retailBranchId, $productAId, $today);
    $ledB = $checkLedger($retailBranchId, $productBId, $today);

    fp('branch ledger addtl for PANDESAL = 100 (full delivery qty)',
        (int)($ledA['addtl'] ?? -1) === 100,
        "got " . ($ledA['addtl'] ?? 'missing'));
    fp('branch ledger addtl for MONAY = 60',
        (int)($ledB['addtl'] ?? -1) === 60,
        "got " . ($ledB['addtl'] ?? 'missing'));

    // ═══════════════════════════════════════════════════════════════════
    // S5 — CASHIER WITHDRAWALS (all reason types)
    // ═══════════════════════════════════════════════════════════════════
    echo "\n── S5: Cashier withdrawals — all reason types ──\n";

    $reasonTypes = [
        ['spoilage',          2, 'PANDESAL spoilage'],
        ['sampling',          1, 'PANDESAL sampling tray'],
        ['staff_meal',        3, 'Staff meal PANDESAL'],
        ['damage',            1, 'Damaged on transit'],
        ['donation',          2, 'Donated to community'],
        ['manual_adjustment', 1, 'Correction entry'],
    ];

    $withdrawalIds = [];
    $totalWithdrawnPandesal = 0;

    foreach ($reasonTypes as [$reason, $qty, $note]) {
        $dedupHash = dl_withdrawalDedupHash(
            $retailBranchId,
            $productAId,
            $today,
            'charge',
            $reason,
            null,
            null,
            null,
            $qty,
            null
        );
        $pdo->prepare(
            'INSERT INTO dl_cashier_withdrawals
                (branch_id, product_id, ledger_date, withdrawal_type, reason_code, quantity, encoded_by, dedup_hash)
             VALUES (:b, :p, :d, "charge", :rc, :qty, :enc, :dh)'
        )->execute([
            ':b'   => $retailBranchId,
            ':p'   => $productAId,
            ':d'   => $today,
            ':rc'  => $reason,
            ':qty' => $qty,
            ':enc' => $actorId,
            ':dh'  => $dedupHash,
        ]);
        $wid = (int)$pdo->lastInsertId();
        $withdrawalIds[] = $wid;
        $totalWithdrawnPandesal += $qty;

        // Verify reason_code persisted
        $rcRow = $pdo->prepare('SELECT reason_code, quantity FROM dl_cashier_withdrawals WHERE id = :id');
        $rcRow->execute([':id' => $wid]);
        $rcData = $rcRow->fetch(PDO::FETCH_ASSOC);

        fp("withdrawal reason_code={$reason} qty={$qty} persists correctly",
            ($rcData['reason_code'] ?? '') === $reason && (int)($rcData['quantity'] ?? 0) === $qty,
            json_encode($rcData));
    }
    fp('all 6 withdrawal reason types created', count($withdrawalIds) === 6);

    // Apply ledger delta for total withdrawals (mirrors apiSaveCashierWithdrawals delta path)
    dl_applyLedgerDelta($retailBranchId, $productAId, $today, $totalWithdrawnPandesal, $actorId, 'withdraw');
    $ledAAfterWd = $checkLedger($retailBranchId, $productAId, $today);
    fp("ledger withdraw column = {$totalWithdrawnPandesal} after all withdrawals",
        (int)($ledAAfterWd['withdraw'] ?? -1) === $totalWithdrawnPandesal,
        "got " . ($ledAAfterWd['withdraw'] ?? 'missing'));

    // Record the end-of-day count (bal_end = 0) so sales becomes deterministic.
    // Under the nullable-endings design, an uncounted row (bal_end NULL) keeps
    // sales pending (NULL) and is excluded from official aggregates.
    $pdo->prepare(
        'UPDATE dl_daily_ledger SET bal_end = 0 WHERE branch_id = :b AND product_id = :p AND ledger_date = :d'
    )->execute([':b' => $retailBranchId, ':p' => $productAId, ':d' => $today]);
    dl_recomputeSales($retailBranchId, $productAId, $today, max(0, $actorId), 'AM');
    $ledAAfterWd = $checkLedger($retailBranchId, $productAId, $today);

    // Sales should auto-recompute: beg(0) + addtl(100) - withdraw(10) - bal_end(0) = 90
    $expectedSales = max(0,
        (int)($ledAAfterWd['beg_bal'] ?? 0) +
        (int)($ledAAfterWd['addtl'] ?? 0) -
        (int)($ledAAfterWd['withdraw'] ?? 0) -
        (int)($ledAAfterWd['bal_end'] ?? 0)
    );
    fp("sales auto-recomputed after withdrawal ({$expectedSales} expected)",
        (int)($ledAAfterWd['sales'] ?? -1) === $expectedSales,
        "got sales=" . ($ledAAfterWd['sales'] ?? 'missing'));

    // Record end-of-day count for MONAY as well so the S7 consolidated summary
    // includes its regular sales (beg 0 + addtl 60 - withdraw 0 - bal_end 0).
    $pdo->prepare(
        'UPDATE dl_daily_ledger SET bal_end = 0 WHERE branch_id = :b AND product_id = :p AND ledger_date = :d'
    )->execute([':b' => $retailBranchId, ':p' => $productBId, ':d' => $today]);
    dl_recomputeSales($retailBranchId, $productBId, $today, max(0, $actorId), 'AM');

    // Also do a "charge" type withdrawal for MONAY (inter-branch transfer type)
    $transferDedupHash = dl_withdrawalDedupHash(
        $retailBranchId,
        $productBId,
        $today,
        'transfer',
        null,
        null,
        null,
        $commissaryBranchId,
        5,
        null
    );
    $pdo->prepare(
        'INSERT INTO dl_cashier_withdrawals
            (branch_id, product_id, ledger_date, withdrawal_type, reason_code,
             target_branch_id, quantity, encoded_by, dedup_hash)
         VALUES (:b, :p, :d, "transfer", NULL, :tb, :qty, :enc, :dh)'
    )->execute([
        ':b'   => $retailBranchId, ':p'   => $productBId,
        ':d'   => $today, ':tb'  => $commissaryBranchId,
        ':qty' => 5, ':enc' => $actorId, ':dh' => $transferDedupHash,
    ]);
    $transferWid = (int)$pdo->lastInsertId();
    $withdrawalIds[] = $transferWid;
    fp('transfer withdrawal created (target_branch_id set)', $transferWid > 0);

    // ═══════════════════════════════════════════════════════════════════
    // S6 — SELLING ACCOUNTS
    // ═══════════════════════════════════════════════════════════════════
    echo "\n── S6: Selling accounts ──\n";

    fp('dl_areSellingAccountsEnabled() = true', dl_areSellingAccountsEnabled());

    // Create a mall selling account linked to retail branch with stress-mall price group
    $pdo->prepare(
        'INSERT INTO dl_selling_accounts
            (code, name, account_type, assigned_branch_id, supply_source_type, supply_source_id,
             price_group_id, ledger_type, is_active)
         VALUES (:c, :n, "mall", :b, "commissary", :sid, :pg, "mall_account", 1)'
    )->execute([
        ':c'  => 'SMALL-' . $suffix,
        ':n'  => 'Stress Mall ' . $suffix,
        ':b'  => $retailBranchId,
        ':sid' => $commissaryBranchId,
        ':pg' => $stressMallGroupId,
    ]);
    $sellingAccountId = (int)$pdo->lastInsertId();
    $trackedAccountIds[] = $sellingAccountId;
    fp('selling account created', $sellingAccountId > 0);

    // Create a draft delivery to this selling account: 50 PANDESAL @ 45.00
    $pdo->prepare(
        'INSERT INTO dl_deliveries
            (origin_type, origin_id, destination_type, destination_id,
             dr_number, delivery_date, status, created_by)
         VALUES ("commissary", :oid, "selling_account", :did,
                 :dr, :dd, "draft", :actor)'
    )->execute([
        ':oid'   => $commissaryBranchId,
        ':did'   => $sellingAccountId,
        ':dr'    => 'SA-DR-' . $suffix,
        ':dd'    => $today,
        ':actor' => $actorId,
    ]);
    $saDeliveryId = (int)$pdo->lastInsertId();
    $trackedDeliveryIds[] = $saDeliveryId;
    fp('selling-account delivery created (draft)', $saDeliveryId > 0);

    $pdo->prepare(
        'INSERT INTO dl_delivery_items
            (delivery_id, product_id, quantity, unit, price_snapshot, price_group_id)
         VALUES (:d, :p, 50, "pcs", 45.00, :g)'
    )->execute([':d' => $saDeliveryId, ':p' => $productAId, ':g' => $stressMallGroupId]);

    // Post it (mirrors apiPostDelivery → dl_postDeliveryToSellingAccount)
    $pdo->prepare('UPDATE dl_deliveries SET status = "posted", posted_at = NOW(), posted_by = :actor WHERE id = :id')
        ->execute([':actor' => $actorId, ':id' => $saDeliveryId]);
    dl_postDeliveryToSellingAccount($saDeliveryId);

    // Verify selling account ledger
    $saLedRow = $pdo->prepare(
        'SELECT beg_qty, delivered_qty, sold_qty, gross_amount
           FROM dl_selling_account_ledger
          WHERE selling_account_id = :a AND product_id = :p AND ledger_date = :d'
    );
    $saLedRow->execute([':a' => $sellingAccountId, ':p' => $productAId, ':d' => $today]);
    $saLed = $saLedRow->fetch(PDO::FETCH_ASSOC);

    fp('selling account ledger row created',  $saLed !== false);
    fp('selling account delivered_qty = 50',  (int)($saLed['delivered_qty'] ?? -1) === 50);
    fp('selling account initial sold_qty = 50 (beg=0, ret=0, end=0)',
        (int)($saLed['sold_qty'] ?? -1) === 50,
        json_encode($saLed));
    fp('selling account gross_amount = 2250.00 (50 × 45.00)',
        abs((float)($saLed['gross_amount'] ?? 0) - 2250.00) < 0.001,
        "got " . ($saLed['gross_amount'] ?? 'missing'));

    // Simulate end-of-day count: 12 remaining
    $pdo->prepare(
        'UPDATE dl_selling_account_ledger
            SET end_qty = 12,
                sold_qty = beg_qty + delivered_qty - return_qty - end_qty,
                gross_amount = (beg_qty + delivered_qty - return_qty - end_qty) * price_snapshot
          WHERE selling_account_id = :a AND product_id = :p AND ledger_date = :d'
    )->execute([':a' => $sellingAccountId, ':p' => $productAId, ':d' => $today]);

    $saLedRow->execute([':a' => $sellingAccountId, ':p' => $productAId, ':d' => $today]);
    $saLed2 = $saLedRow->fetch(PDO::FETCH_ASSOC);

    fp('end_qty=12 → sold_qty recomputes to 38',
        (int)($saLed2['sold_qty'] ?? -1) === 38,
        json_encode($saLed2));
    fp('end_qty=12 → gross_amount = 1710.00 (38 × 45.00)',
        abs((float)($saLed2['gross_amount'] ?? 0) - 1710.00) < 0.001,
        "got " . ($saLed2['gross_amount'] ?? 'missing'));

    // Close the selling account day
    $pdo->prepare(
        'INSERT INTO dl_selling_account_day_status
            (selling_account_id, ledger_date, status, closed_by)
         VALUES (:a, :d, "closed", :uid)
         ON DUPLICATE KEY UPDATE status = "closed", closed_by = :uid2'
    )->execute([':a' => $sellingAccountId, ':d' => $today, ':uid' => $actorId, ':uid2' => $actorId]);
    $dayStatus = $pdo->prepare('SELECT status FROM dl_selling_account_day_status WHERE selling_account_id = :a AND ledger_date = :d');
    $dayStatus->execute([':a' => $sellingAccountId, ':d' => $today]);
    fp('selling account day closed successfully',
        (string)($dayStatus->fetchColumn() ?: '') === 'closed');

    // ═══════════════════════════════════════════════════════════════════
    // S7 — BRANCH CONSOLIDATED SUMMARY
    // ═══════════════════════════════════════════════════════════════════
    echo "\n── S7: Branch consolidated summary ──\n";

    $summary = dl_branchConsolidatedSummary($retailBranchId, $today);

    fp('consolidated summary returned (array)', is_array($summary));

    // Regular: PANDESAL beg=0, addtl=100, withdraw=10, bal_end=0 → sales=90 × 30.00 = 2700
    //          MONAY   beg=0, addtl=60,  withdraw=0,  bal_end=0 → sales=60 × 25.00 = 1500
    //          Expected regular_sales ≈ 4200.00
    $expectedRegularSales = 90 * 30.00 + 60 * 25.00; // 2700 + 1500 = 4200

    fp("regular_sales ≈ {$expectedRegularSales} (PANDESAL 90×30 + MONAY 60×25)",
        abs((float)($summary['regular_sales'] ?? 0) - $expectedRegularSales) < 1.0,
        "got " . json_encode($summary['regular_sales'] ?? null));

    // Selling accounts total should include the stress mall account gross = 1710
    $saTotal = (float)($summary['selling_accounts_total'] ?? 0);
    fp('consolidated selling_accounts_total includes stress mall (≥1710)',
        $saTotal >= 1710.00,
        "got {$saTotal}");

    // Stress-mall account should appear in the accounts list
    $saList = $summary['selling_accounts'] ?? [];
    $foundSa = false;
    foreach ($saList as $sa) {
        if ((int)($sa['id'] ?? 0) === $sellingAccountId) {
            $foundSa = true;
            break;
        }
    }
    fp('stress-mall selling account appears in consolidated list', $foundSa);

    // ═══════════════════════════════════════════════════════════════════
    // EXTRA — Production output movement (withdrawal from commissary)
    // ═══════════════════════════════════════════════════════════════════
    echo "\n── Extra: Production withdrawal movement ──\n";

    // Simulate raw-material withdrawal from commissary (type=withdrawal).
    // With the formal delivery workflow enabled, a production withdrawal must
    // reference a matching branch delivery for the same DR — so create one
    // first, mirroring the handler's contract.
    $wdDr = 'STRESS-WD-' . $suffix;
    $pdo->prepare(
        'INSERT INTO dl_deliveries
            (origin_type, origin_id, destination_type, destination_id, dr_number, delivery_date, status, posted_at)
         VALUES ("commissary", :orig, "branch", :dest, :dr, :d, "posted", NOW())'
    )->execute([
        ':orig' => $commissaryBranchId,
        ':dest' => $commissaryBranchId,
        ':dr'   => $wdDr,
        ':d'    => $today,
    ]);
    $wdDeliveryId = (int)$pdo->lastInsertId();
    $trackedDeliveryIds[] = $wdDeliveryId;
    $pdo->prepare(
        'INSERT INTO dl_delivery_items (delivery_id, product_id, quantity, unit, price_snapshot)
         VALUES (:d, :p, 20, "pcs", 30.00)'
    )->execute([':d' => $wdDeliveryId, ':p' => $productAId]);

    $wInput = [
        'destination_branch_id' => $commissaryBranchId,
        'product_id'            => $productAId,
        'quantity'              => 20,
        'ledger_date'           => $today,
        'flow_mode'             => 'production',
        'reason'                => '',
        'dr_number'             => $wdDr,
        'client_op_id'          => 'stress-wd-' . $suffix,
    ];
    $wResult = dl_processProductionMovement($adminUser, 'withdrawal', $wInput);
    if ((int)($wResult['movement_id'] ?? 0) > 0) {
        $trackedMovementIds[] = (int)$wResult['movement_id'];
    }
    fp('production withdrawal recorded',
        isset($wResult['movement_id']) && $wResult['movement_id'] > 0,
        json_encode($wResult));
    fp('withdrawal movement_type = withdrawal',
        ($wResult['movement_type'] ?? '') === 'withdrawal');
    fp('withdrawal ledger_column = withdraw',
        ($wResult['ledger_column'] ?? '') === 'withdraw');
    fp('withdrawal resulting_withdraw = 20',
        (int)($wResult['resulting_withdraw'] ?? -1) === 20,
        "got " . json_encode($wResult['resulting_withdraw'] ?? null));

    // ─── Also do a production OUTPUT movement ──────────────────────────
    $oInput = [
        'destination_branch_id' => $retailBranchId,
        'product_id'            => $productBId,
        'quantity'              => 15,
        'ledger_date'           => $today,
        'flow_mode'             => 'production',
        'reason'                => '',
        'dr_number'             => 'EXTRA-OUT-' . $suffix,
        'client_op_id'          => 'stress-out-' . $suffix,
    ];
    $oResult = dl_processProductionMovement($adminUser, 'output', $oInput);
    if ((int)($oResult['movement_id'] ?? 0) > 0) {
        $trackedMovementIds[] = (int)$oResult['movement_id'];
    }
    if ((int)($oResult['delivery_id'] ?? 0) > 0) {
        $trackedDeliveryIds[] = (int)$oResult['delivery_id'];
    }
    fp('production output movement recorded',
        isset($oResult['movement_id']) && $oResult['movement_id'] > 0,
        json_encode($oResult));
    fp('output ledger_column = addtl',
        ($oResult['ledger_column'] ?? '') === 'addtl');
    // With the formal delivery workflow enabled, a production output creates a
    // delivery record instead of directly bumping the branch addtl — the branch
    // receives addtl when it accepts that delivery via Receive Stock.
    fp('output creates a formal delivery for the extra quantity',
        (int)($oResult['delivery_id'] ?? 0) > 0,
        json_encode($oResult));
    fp('output resulting_addtl reflects delivery-based flow',
        (int)($oResult['resulting_addtl'] ?? -1) === 0,
        "got " . json_encode($oResult['resulting_addtl'] ?? null));

    // ═══════════════════════════════════════════════════════════════════
    // S8 — NEGATIVE PATHS (void, duplicate, reject, partial-receive)
    // ═══════════════════════════════════════════════════════════════════
    echo "\n── S8: Negative paths ──\n";

    // 8a — double-accept: dl_acceptFormalDelivery on an already-received delivery throws
    $caughtDouble = false;
    try {
        dl_acceptFormalDelivery($pdo, $retailBranchId, $deliveryId, $actorId, $today);
    } catch (\Throwable $ex) {
        $caughtDouble = str_contains($ex->getMessage(), 'already has a receiving');
    }
    fp('double-accept throws "already has a receiving"', $caughtDouble);

    // 8b — partial-receive: create a fresh delivery with 50 PANDESAL, accept only 35
    $pdo->prepare(
        'INSERT INTO dl_deliveries
            (origin_type, origin_id, destination_type, destination_id, dr_number, delivery_date, status, posted_at)
         VALUES ("commissary", :orig, "branch", :dest, :dr, :d, "posted", NOW())'
    )->execute([
        ':orig' => $commissaryBranchId,
        ':dest' => $retailBranchId,
        ':dr'   => 'STRESS-PARTIAL-' . $suffix,
        ':d'    => $today,
    ]);
    $partialDelivId = (int)$pdo->lastInsertId();
    $trackedDeliveryIds[] = $partialDelivId;

    $pdo->prepare(
        'INSERT INTO dl_delivery_items (delivery_id, product_id, quantity, unit, price_snapshot)
         VALUES (:d, :p, 50, "pcs", 30.00)'
    )->execute([':d' => $partialDelivId, ':p' => $productAId]);

    $partialRcvId = dl_acceptFormalDelivery(
        $pdo, $retailBranchId, $partialDelivId, $actorId, $today,
        [$productAId => 35]   // only 35 of 50
    );
    $trackedReceivingIds[] = $partialRcvId;
    fp('partial receive returns receiving id > 0', $partialRcvId > 0);

    // Variance flag should record sent=50, received=35, variance=-15
    $pvRow = $pdo->prepare(
        'SELECT sent_qty, received_qty, variance FROM dl_delivery_variance_flags
          WHERE delivery_id = :d AND product_id = :p'
    );
    $pvRow->execute([':d' => $partialDelivId, ':p' => $productAId]);
    $pvFlag = $pvRow->fetch(PDO::FETCH_ASSOC);
    fp('partial receive: variance flag sent_qty=50', $pvFlag !== false && (int)$pvFlag['sent_qty'] === 50);
    fp('partial receive: variance flag received_qty=35', $pvFlag !== false && (int)$pvFlag['received_qty'] === 35);
    fp('partial receive: variance=-15', $pvFlag !== false && (int)$pvFlag['variance'] === -15);

    // 8c — out-of-range partial qty throws
    $caughtRange = false;
    $pdo->prepare(
        'INSERT INTO dl_deliveries
            (origin_type, origin_id, destination_type, destination_id, dr_number, delivery_date, status, posted_at)
         VALUES ("commissary", :orig, "branch", :dest, :dr, :d, "posted", NOW())'
    )->execute([
        ':orig' => $commissaryBranchId,
        ':dest' => $retailBranchId,
        ':dr'   => 'STRESS-BADQTY-' . $suffix,
        ':d'    => $today,
    ]);
    $badQtyDelivId = (int)$pdo->lastInsertId();
    $trackedDeliveryIds[] = $badQtyDelivId;
    $pdo->prepare(
        'INSERT INTO dl_delivery_items (delivery_id, product_id, quantity, unit, price_snapshot)
         VALUES (:d, :p, 10, "pcs", 30.00)'
    )->execute([':d' => $badQtyDelivId, ':p' => $productAId]);
    try {
        dl_acceptFormalDelivery($pdo, $retailBranchId, $badQtyDelivId, $actorId, $today, [$productAId => 999]);
    } catch (\Throwable $ex) {
        $caughtRange = str_contains($ex->getMessage(), 'exceeds delivery qty');
    }
    fp('partial qty > delivery qty throws validation error', $caughtRange);

    // 8d — void delivery: create+post a delivery, void it, assert status=voided
    $pdo->prepare(
        'INSERT INTO dl_deliveries
            (origin_type, origin_id, destination_type, destination_id, dr_number, delivery_date, status, posted_at)
         VALUES ("commissary", :orig, "branch", :dest, :dr, :d, "posted", NOW())'
    )->execute([
        ':orig' => $commissaryBranchId,
        ':dest' => $retailBranchId,
        ':dr'   => 'STRESS-VOID-' . $suffix,
        ':d'    => $today,
    ]);
    $voidDelivId = (int)$pdo->lastInsertId();
    $trackedDeliveryIds[] = $voidDelivId;
    $pdo->prepare(
        'INSERT INTO dl_delivery_items (delivery_id, product_id, quantity, unit, price_snapshot)
         VALUES (:d, :p, 20, "pcs", 30.00)'
    )->execute([':d' => $voidDelivId, ':p' => $productAId]);

    // Void via direct update (mirrors apiVoidDelivery logic for posted+unreceived)
    $pdo->prepare(
        'UPDATE dl_deliveries SET status="voided", voided_at=NOW() WHERE id=:id AND status="posted"'
    )->execute([':id' => $voidDelivId]);
    $voidedStatus = $pdo->prepare('SELECT status FROM dl_deliveries WHERE id=:id');
    $voidedStatus->execute([':id' => $voidDelivId]);
    fp('voided delivery status = voided', (string)$voidedStatus->fetchColumn() === 'voided');

    // 8e — reject: trying to accept a voided delivery throws
    $caughtVoided = false;
    try {
        dl_acceptFormalDelivery($pdo, $retailBranchId, $voidDelivId, $actorId, $today);
    } catch (\Throwable $ex) {
        $caughtVoided = str_contains($ex->getMessage(), 'Posted delivery not found');
    }
    fp('accepting a voided delivery throws "Posted delivery not found"', $caughtVoided);

    // ═══════════════════════════════════════════════════════════════════
    // ERROR LOG SANITY CHECK
    // ═══════════════════════════════════════════════════════════════════
    echo "\n── Error log sanity ──\n";
    $errLog = is_file(STORAGE_PATH . '/logs/error.log')
        ? (string)@file_get_contents(STORAGE_PATH . '/logs/error.log')
        : '';
    fp('no PHP errors in error.log', trim($errLog) === '', substr($errLog, 0, 300));

} catch (\Throwable $e) {
    $fail++;
    $errors[] = 'UNCAUGHT EXCEPTION: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine();
    echo "\n  ✗  UNCAUGHT EXCEPTION: " . $e->getMessage() . "\n";
    echo "     at " . $e->getFile() . ':' . $e->getLine() . "\n";
}

} finally {
    // ─── Teardown (best-effort) ────────────────────────────────────────
    echo "\n── Teardown ──\n";

    // Remove cashier withdrawals for our retail branch on today (by branch + date to cover all)
    $pdo->prepare(
        'DELETE FROM dl_cashier_withdrawals WHERE branch_id IN (:b1, :b2) AND ledger_date = :d'
    )->execute([':b1' => $retailBranchId ?? 0, ':b2' => $commissaryBranchId ?? 0, ':d' => $today]);

    // Remove production movements created by stress tests
    foreach (['stress-wd-' . $suffix, 'stress-out-' . $suffix] as $coid) {
        $pdo->prepare('DELETE FROM dl_production_movements WHERE client_op_id = :coid')->execute([':coid' => $coid]);
    }
    foreach ($trackedMovementIds as $mid) {
        $pdo->prepare('DELETE FROM dl_production_movements WHERE id = :id')->execute([':id' => $mid]);
    }
    // Also remove the bridge auto-reverse movements
    $pdo->prepare('DELETE FROM dl_production_movements WHERE created_by_id = :uid AND flow_mode = "commissary" AND source_payload LIKE "%stress%"')
        ->execute([':uid' => $actorId]);

    // Remove production runs
    foreach ($trackedRunIds as $rid) {
        $pdo->prepare('DELETE FROM dl_production_runs WHERE id = :id')->execute([':id' => $rid]);
    }

    // Remove selling account state
    foreach ($trackedAccountIds as $aid) {
        $pdo->prepare('DELETE FROM dl_selling_account_ledger WHERE selling_account_id = :id')->execute([':id' => $aid]);
        $pdo->prepare('DELETE FROM dl_selling_account_day_status WHERE selling_account_id = :id')->execute([':id' => $aid]);
        $pdo->prepare('DELETE FROM dl_selling_accounts WHERE id = :id')->execute([':id' => $aid]);
    }

    // Remove receivings
    foreach ($trackedReceivingIds as $rid) {
        $pdo->prepare('DELETE FROM dl_branch_receiving_items WHERE receiving_id = :id')->execute([':id' => $rid]);
        $pdo->prepare('DELETE FROM dl_branch_receivings WHERE id = :id')->execute([':id' => $rid]);
    }

    // Remove deliveries (cascades items via FK)
    foreach ($trackedDeliveryIds as $did) {
        $pdo->prepare('DELETE FROM dl_delivery_variance_flags WHERE delivery_id = :id')->execute([':id' => $did]);
        $pdo->prepare('DELETE FROM dl_delivery_items WHERE delivery_id = :id')->execute([':id' => $did]);
        $pdo->prepare('DELETE FROM dl_deliveries WHERE id = :id')->execute([':id' => $did]);
    }

    // Backstop cleanup by stress suffix in case any auto-created ids were not tracked.
    $stressLike = '%' . $suffix;
    $pdo->prepare(
        'DELETE rii
           FROM dl_branch_receiving_items rii
           INNER JOIN dl_branch_receivings r ON r.id = rii.receiving_id
          WHERE r.dr_number LIKE :suffix'
    )->execute([':suffix' => $stressLike]);
    $pdo->prepare('DELETE FROM dl_branch_receivings WHERE dr_number LIKE :suffix')
        ->execute([':suffix' => $stressLike]);
    $pdo->prepare(
        'DELETE vf
           FROM dl_delivery_variance_flags vf
           INNER JOIN dl_deliveries d ON d.id = vf.delivery_id
          WHERE d.dr_number LIKE :suffix'
    )->execute([':suffix' => $stressLike]);
    $pdo->prepare(
        'DELETE di
           FROM dl_delivery_items di
           INNER JOIN dl_deliveries d ON d.id = di.delivery_id
          WHERE d.dr_number LIKE :suffix'
    )->execute([':suffix' => $stressLike]);
    $pdo->prepare('DELETE FROM dl_deliveries WHERE dr_number LIKE :suffix')
        ->execute([':suffix' => $stressLike]);
    $pdo->prepare('DELETE FROM audit_logs WHERE module = "daily-ledger" AND (CAST(new_data AS CHAR) LIKE :like_new OR CAST(old_data AS CHAR) LIKE :like_old OR CAST(metadata_json AS CHAR) LIKE :like_meta)')
        ->execute([
            ':like_new' => '%' . $suffix . '%',
            ':like_old' => '%' . $suffix . '%',
            ':like_meta' => '%' . $suffix . '%',
        ]);

    // Remove daily ledger rows for our test branches
    foreach ($trackedBranchIds as $bid) {
        $pdo->prepare('DELETE FROM dl_daily_ledger WHERE branch_id = :id')->execute([':id' => $bid]);
        $pdo->prepare('DELETE FROM dl_production_movements WHERE destination_branch_id = :id')->execute([':id' => $bid]);
    }

    // Remove product price rows created for stress price group
    $pdo->prepare('DELETE FROM dl_product_prices WHERE price_group_id IN (' . implode(',', array_map('intval', $trackedPriceGroupIds ?: [0])) . ')')->execute();

    // Remove self-seeded products (dependent commissary ledger + prices first,
    // then the products)
    if ($trackedProductIds !== []) {
        $productIds = implode(',', array_map('intval', $trackedProductIds));
        $pdo->prepare('DELETE FROM dl_commissary_product_ledger WHERE product_id IN (' . $productIds . ')')->execute();
        $pdo->prepare('DELETE FROM dl_product_prices WHERE product_id IN (' . $productIds . ')')->execute();
        $pdo->prepare('DELETE FROM dl_products WHERE id IN (' . $productIds . ')')->execute();
    }

    // Remove price groups
    foreach ($trackedPriceGroupIds as $pgid) {
        $pdo->prepare('DELETE FROM dl_price_groups WHERE id = :id')->execute([':id' => $pgid]);
    }

    // Remove branches (branches have no FKs to worry about after cleaning movements/ledger)
    foreach (array_reverse($trackedBranchIds) as $bid) {
        $pdo->prepare('DELETE FROM dl_branch_product_supply_rules WHERE branch_id = :id')->execute([':id' => $bid]);
        $pdo->prepare('DELETE FROM dl_user_branches WHERE branch_id = :id')->execute([':id' => $bid]);
        $pdo->prepare('DELETE FROM dl_branches WHERE id = :id')->execute([':id' => $bid]);
    }

    echo "  teardown complete\n";
}

// ─── Final report ─────────────────────────────────────────────────────────
$total = $pass + $fail;
echo "\n╔══════════════════════════════════════════════════════════╗\n";
printf("║  RESULT  PASS: %-3d  FAIL: %-3d  TOTAL: %-3d             ║\n", $pass, $fail, $total);
echo "╚══════════════════════════════════════════════════════════╝\n\n";

if ($fail > 0) {
    echo "FAILED ASSERTIONS:\n";
    foreach ($errors as $e) {
        echo "  - {$e}\n";
    }
    exit(1);
}
exit(0);
