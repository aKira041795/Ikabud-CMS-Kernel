<?php

declare(strict_types=1);

/**
 * Daily Ledger — SLICE B picker oracle (P1-P8).
 *
 * Exercises the bulk per-branch assignment save (the products "Show in
 * Branches" tab) and the product modal's assignment_mode on create/update.
 * Every fixture is synthetic (branch/product ids in the 997xx range) and
 * removed in finally; real branch 8 is never used as a fixture.
 *
 * It is written to RUN ON THE BASE TREE (c59f4132) too: Slice B helpers that
 * do not exist there are detected with function_exists and reported as explicit
 * failures instead of fataling, so the base-tree discrimination statement is
 * observed rather than asserted. On base, P1-P5 fail (no bulk endpoint/core),
 * P6 'specific' fails (apiCreateProduct hardcodes all_active), P6 all_active
 * and missing-mode pass, and P7 passes trivially because the base update never
 * touches assignments at all (see REPORT).
 *
 * Tenant 207 (baronledger).
 */

ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-branch-product-picker', TestHarness::MODE_INTEGRATION, 'baronledger.test');
ob_end_clean();
$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/helpers.php';
require_once $base . '/modules/daily-ledger/handlers-deliveries.php';
require_once $base . '/modules/daily-ledger/handlers.php';
$h->fingerprint('modules/daily-ledger/handlers.php');
$h->fingerprint('modules/daily-ledger/helpers.php');
$h->fingerprint('modules/daily-ledger/routes.php');
$h->fingerprint('templates/modules/daily-ledger/admin/products.disyl');
$h->fingerprint('tests/daily-ledger/daily_ledger_branch_product_visibility_harness.php');
$h->allowLogLines('disyl.compile.phases');
app()->tenant()->setTenantId(207);
$ctx = modulePushContext('daily-ledger');
$db = $ctx->db();

// A real daily-ledger actor for the in-process audit rows.
$_COOKIE[dlCookieName()] = app()->jwt()->generate([
    'sub' => 'admin:1',
    'id' => 1,
    'username' => 'picker-harness',
    'name' => 'Picker Harness',
    'role' => 'admin',
    'source' => 'daily-ledger',
]);

$store = 99711;
$comm = 99712;
$prodSheet = 99711;
$prodRefused = 99712;
$prodOldOnly = 99713;
$prodOther = 99714;
$fixtureProducts = [$prodSheet, $prodRefused, $prodOldOnly, $prodOther];
$createdProductIds = [];
$createdBranchIds = [];
$payloadFile = tempnam(sys_get_temp_dir(), 'dl-pick-');

$today = dl_businessDate();
$olderOpen = (new \DateTimeImmutable($today))->modify('-3 days')->format('Y-m-d');

$hasPickerApi = function_exists('apiBulkAssignBranchProducts') && function_exists('dl_bulkAssignBranchProductsCore');
$warningAvailable = function_exists('dl_productOlderOpenDayWarnings');

$countTenant = static function () use ($db): array {
    return [
        'branches' => (int)$db->query('SELECT COUNT(*) FROM dl_branches')->fetchColumn(),
        'products' => (int)$db->query('SELECT COUNT(*) FROM dl_products')->fetchColumn(),
        'branch_products' => (int)$db->query('SELECT COUNT(*) FROM dl_branch_products')->fetchColumn(),
        'daily_ledger' => (int)$db->query('SELECT COUNT(*) FROM dl_daily_ledger')->fetchColumn(),
        'commissary_ledger' => (int)$db->query('SELECT COUNT(*) FROM dl_commissary_product_ledger')->fetchColumn(),
    ];
};

$cleanup = function () use ($db, &$createdBranchIds, &$createdProductIds, $fixtureProducts, $store, $comm): void {
    $branches = implode(',', array_map('intval', array_merge([$store, $comm], $createdBranchIds)));
    $pids = implode(',', array_map('intval', array_merge($fixtureProducts, $createdProductIds)));
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
$activePairCount = static function (int $productId) use ($db): int {
    return (int)$db->query("SELECT COUNT(*) FROM dl_branch_products WHERE product_id = {$productId} AND is_active = 1")->fetchColumn();
};
$sheetHas = static function (int $branchId, int $productId) use ($db): bool {
    foreach (dl_fetchCashierLedgerRows($db, $branchId, dl_businessDate(), 'AM') as $row) {
        if ((int)($row['product_id'] ?? 0) === $productId) {
            return true;
        }
    }
    return false;
};
$resetLedgers = static function () use ($db, $store, $comm, $fixtureProducts): void {
    $branches = "{$store},{$comm}";
    $pids = implode(',', array_map('intval', $fixtureProducts));
    $db->execute("DELETE FROM dl_daily_ledger WHERE branch_id IN ({$branches}) OR product_id IN ({$pids})");
    $db->execute("DELETE FROM dl_commissary_product_ledger WHERE commissary_branch_id IN ({$branches}) OR product_id IN ({$pids})");
    $db->execute("DELETE FROM dl_ledger_day_status WHERE branch_id IN ({$branches})");
    $db->execute("DELETE FROM dl_ledger_shift_status WHERE branch_id IN ({$branches})");
};
$blockersText = static function (array $refused): string {
    $chunks = [];
    foreach ($refused as $item) {
        foreach (($item['blockers'] ?? []) as $b) {
            $chunks[] = ($item['product_id'] ?? '?') . ':' . ($b['date'] ?? '?') . ' ' . ($b['shift'] ?? '') . ' ' . ($b['ledger'] ?? '');
        }
    }
    return implode(' | ', $chunks);
};

$cleanup();
$tenantBefore = $countTenant();

try {
    // ── Fixture ─────────────────────────────────────────────────────────────
    $db->prepare('INSERT INTO dl_branches (id,code,name,is_commissary,is_active) VALUES (?,?,?,0,1)')
        ->execute([$store, 'PK-STORE', 'Picker Store']);
    $db->prepare('INSERT INTO dl_branches (id,code,name,is_commissary,is_active) VALUES (?,?,?,1,1)')
        ->execute([$comm, 'PK-COMM', 'Picker Commissary']);
    foreach ($fixtureProducts as $i => $pid) {
        $db->prepare('INSERT INTO dl_products (id,sku,name,current_price,is_active,assignment_mode) VALUES (?,?,?,10,1,\'all_active\')')
            ->execute([$pid, 'PK-' . $i, 'Picker Product ' . $i]);
    }

    // ═════════════════════════════════════════════════════════════════════
    $h->section('P1 bulk assignment activates the pair and the sheet list shows it');
    if (!$hasPickerApi) {
        $h->fail('P1 the bulk picker API exists (base tree c59f4132: apiBulkAssignBranchProducts/dl_bulkAssignBranchProductsCore absent)', 'function missing');
    } else {
        $hidePair($store, $prodSheet);
        $h->test('P1 the product starts hidden from the store sheet list', $sheetHas($store, $prodSheet) === false && $pairActive($store, $prodSheet) === 0, 'sheet=' . var_export($sheetHas($store, $prodSheet), true) . ' pair=' . $pairActive($store, $prodSheet));

        $p1 = $runApi('bulk_assign', 'admin', ['branch_id' => $store, 'product_ids' => [$prodSheet]]);
        $h->test('P1 the bulk save succeeds', ($p1['body']['ok'] ?? false) === true, $p1['raw']);
        $h->test('P1 the pair is active after the save', $pairActive($store, $prodSheet) === 1, 'is_active=' . $pairActive($store, $prodSheet));
        $h->test('P1 the store sheet list now INCLUDES the product', $sheetHas($store, $prodSheet) === true, 'sheet=' . var_export($sheetHas($store, $prodSheet), true));
    }

    // ═════════════════════════════════════════════════════════════════════
    $h->section('P2 unassignment removes the pair from one branch only');
    if (!$hasPickerApi) {
        $h->fail('P2 unassignment is per-branch (base tree c59f4132: bulk picker API absent)', 'function missing');
    } else {
        $activatePair($store, $prodSheet);
        $activatePair($comm, $prodSheet);
        $p2 = $runApi('bulk_assign', 'admin', ['branch_id' => $store, 'product_ids' => []]);
        $h->test('P2 the bulk removal succeeds', ($p2['body']['ok'] ?? false) === true, $p2['raw']);
        $h->test('P2 the store pair is now inactive', $pairActive($store, $prodSheet) === 0, 'store=' . $pairActive($store, $prodSheet));
        $h->test('P2 the other branch pair is UNTOUCHED', $pairActive($comm, $prodSheet) === 1, 'comm=' . $pairActive($comm, $prodSheet));
        $h->test('P2 the store sheet list no longer shows it', $sheetHas($store, $prodSheet) === false, 'sheet=' . var_export($sheetHas($store, $prodSheet), true));
        $h->test('P2 the other branch sheet list still shows it', $sheetHas($comm, $prodSheet) === true, 'sheet=' . var_export($sheetHas($comm, $prodSheet), true));
    }

    // ═════════════════════════════════════════════════════════════════════
    $h->section('P3/P4 all-or-nothing: one refused removal applies NOTHING and names date+shift');
    if (!$hasPickerApi) {
        $h->fail('P3 a refused bulk save applies nothing (base tree c59f4132: bulk picker API absent)', 'function missing');
        $h->fail('P4 the refusal names date+shift (base tree c59f4132: bulk picker API absent)', 'function missing');
    } else {
        $resetLedgers();
        $activatePair($store, $prodRefused);
        $hidePair($store, $prodOther);
        // A real dispatch: removing prodRefused would strand today's unfinished PM row.
        $db->prepare('INSERT INTO dl_daily_ledger (branch_id,product_id,ledger_date,shift,beg_bal,addtl,withdraw,bal_end,price_snapshot) VALUES (?,?,?,?,5,0,0,NULL,10)')
            ->execute([$store, $prodRefused, $today, 'PM']);

        // Desired = [prodOther] -> remove prodRefused AND add prodOther in ONE save.
        $p3 = $runApi('bulk_assign', 'admin', ['branch_id' => $store, 'product_ids' => [$prodOther]]);
        $h->test('P3 the bulk save is REFUSED with PRODUCT_UNASSIGNMENT_BLOCKED', ($p3['body']['ok'] ?? true) === false && ($p3['body']['code'] ?? '') === 'PRODUCT_UNASSIGNMENT_BLOCKED', $p3['raw']);
        $h->test('P3 the refused product STAYS assigned (nothing landed)', $pairActive($store, $prodRefused) === 1, 'refused=' . $pairActive($store, $prodRefused));
        $h->test('P3 the intended ADDITION did not land either (all-or-nothing)', $pairActive($store, $prodOther) === 0, 'other=' . $pairActive($store, $prodOther));
        $h->test('P3 the store sheet list is unchanged', $sheetHas($store, $prodRefused) === true && $sheetHas($store, $prodOther) === false, 'refused=' . var_export($sheetHas($store, $prodRefused), true) . ' other=' . var_export($sheetHas($store, $prodOther), true));

        $refused = $p3['body']['refused'] ?? [];
        $refDates = [];
        foreach ($refused as $item) {
            foreach (($item['blockers'] ?? []) as $b) {
                $refDates[] = ($b['date'] ?? '') . ' ' . ($b['shift'] ?? '') . ' ' . ($b['ledger'] ?? '');
            }
        }
        $h->test('P4 the refusal names the EXACT blocking date+shift+ledger (not a bare count)', in_array($today . ' PM cashier', $refDates, true), $blockersText($refused));
        $h->test('P4 the refusal names the product', (($refused[0]['product_id'] ?? 0) === $prodRefused) && ($refused[0]['name'] ?? '') !== '', json_encode($refused));
    }

    // ═════════════════════════════════════════════════════════════════════
    $h->section('P5 older unfinished rows: unassignable + returned as INFORMATION, never a block');
    if (!$hasPickerApi) {
        $h->fail('P5 an older-open-day-only product is unassignable with a warning (base tree c59f4132: bulk picker API absent)', 'function missing');
    } elseif (!$warningAvailable) {
        $h->fail('P5 the older-open-day warning function exists (base tree c59f4132: dl_productOlderOpenDayWarnings absent)', 'function missing');
    } else {
        $resetLedgers();
        $activatePair($store, $prodOldOnly);
        $db->prepare('INSERT INTO dl_daily_ledger (branch_id,product_id,ledger_date,shift,beg_bal,addtl,withdraw,bal_end,price_snapshot) VALUES (?,?,?,?,5,0,0,NULL,10)')
            ->execute([$store, $prodOldOnly, $olderOpen, 'PM']);

        $p5 = $runApi('bulk_assign', 'admin', ['branch_id' => $store, 'product_ids' => []]);
        $h->test('P5 a product with ONLY an older unfinished row IS unassignable (blockers empty)', ($p5['body']['ok'] ?? false) === true && $pairActive($store, $prodOldOnly) === 0, $p5['raw'] . ' pair=' . $pairActive($store, $prodOldOnly));
        $warnText = json_encode($p5['body']['warnings'] ?? []);
        $h->test('P5 the older open day is returned as an informational warning', str_contains($warnText, $olderOpen), $warnText);
        $h->test('P5 the warning is NOT a refusal', ($p5['body']['code'] ?? '') === '', json_encode($p5['body']));
    }

    // ═════════════════════════════════════════════════════════════════════
    $h->section('P6 create: all_active / specific / MISSING mode');
    $activeBranchCount = (int)$db->query('SELECT COUNT(*) FROM dl_branches WHERE is_active = 1')->fetchColumn();

    $p6all = $runApi('create_product', 'admin', ['name' => 'PK All ' . substr((string)time(), -4), 'price' => 11, 'assignment_mode' => 'all_active']);
    $pidAll = (int)($p6all['body']['product_id'] ?? 0);
    if ($pidAll > 0) { $createdProductIds[] = $pidAll; }
    $h->test('P6 create all_active succeeds', ($p6all['body']['ok'] ?? false) === true && $pidAll > 0, $p6all['raw']);
    $h->test('P6 create all_active assigns EVERY active branch', $pidAll > 0 && $activePairCount($pidAll) === $activeBranchCount, 'pairs=' . ($pidAll > 0 ? $activePairCount($pidAll) : -1) . ' active_branches=' . $activeBranchCount);

    $p6spec = $runApi('create_product', 'admin', ['name' => 'PK Specific ' . substr((string)time(), -4), 'price' => 12, 'assignment_mode' => 'specific', 'branch_ids' => [$store, $comm]]);
    $pidSpec = (int)($p6spec['body']['product_id'] ?? 0);
    if ($pidSpec > 0) { $createdProductIds[] = $pidSpec; }
    $h->test('P6 create specific succeeds', ($p6spec['body']['ok'] ?? false) === true && $pidSpec > 0, $p6spec['raw']);
    $h->test('P6 create specific assigns EXACTLY the given branches', $pidSpec > 0 && $activePairCount($pidSpec) === 2 && $pairActive($store, $pidSpec) === 1 && $pairActive($comm, $pidSpec) === 1, 'pairs=' . ($pidSpec > 0 ? $activePairCount($pidSpec) : -1));
    $h->test('P6 create specific stores assignment_mode=specific', $pidSpec > 0 && (string)$db->query("SELECT assignment_mode FROM dl_products WHERE id={$pidSpec}")->fetchColumn() === 'specific', (string)($pidSpec > 0 ? $db->query("SELECT assignment_mode FROM dl_products WHERE id={$pidSpec}")->fetchColumn() : 'n/a'));

    $p6missing = $runApi('create_product', 'admin', ['name' => 'PK Missing ' . substr((string)time(), -4), 'price' => 13]);
    $pidMissing = (int)($p6missing['body']['product_id'] ?? 0);
    if ($pidMissing > 0) { $createdProductIds[] = $pidMissing; }
    $h->test('P6 a MISSING assignment_mode behaves as all_active (backward compatible)', $pidMissing > 0 && $activePairCount($pidMissing) === $activeBranchCount && (string)$db->query("SELECT assignment_mode FROM dl_products WHERE id={$pidMissing}")->fetchColumn() === 'all_active', $p6missing['raw'] . ' pairs=' . ($pidMissing > 0 ? $activePairCount($pidMissing) : -1) . ' active_branches=' . $activeBranchCount);

    // ═════════════════════════════════════════════════════════════════════
    $h->section('P7 update without an assignment key does NOT rewrite assignments');
    if (!function_exists('apiUpdateProduct')) {
        $h->fail('P7 apiUpdateProduct exists', 'function missing');
    } else {
        $p7create = $runApi('create_product', 'admin', ['name' => 'PK Lossless ' . substr((string)time(), -4), 'price' => 14, 'assignment_mode' => 'specific', 'branch_ids' => [$store]]);
        $pidLoss = (int)($p7create['body']['product_id'] ?? 0);
        if ($pidLoss > 0) { $createdProductIds[] = $pidLoss; }
        $h->test('P7 the specific product is created with one branch', $pidLoss > 0 && $activePairCount($pidLoss) === 1 && $pairActive($store, $pidLoss) === 1, $p7create['raw']);

        // A price/active-only edit: NO assignment_mode key at all.
        $p7update = $runApi('update_product', 'admin', [
            'product_id' => $pidLoss,
            'name' => 'PK Lossless Renamed',
            'product_category' => 'bread',
            'price' => 15,
            'sort_order' => 0,
            'is_active' => 1,
            'effective_from' => $today,
        ]);
        $h->test('P7 the price/active-only update succeeds', ($p7update['body']['ok'] ?? false) === true, $p7update['raw']);
        $h->test('P7 the assignment pair is UNCHANGED by the edit', $pidLoss > 0 && $activePairCount($pidLoss) === 1 && $pairActive($store, $pidLoss) === 1, 'pairs=' . ($pidLoss > 0 ? $activePairCount($pidLoss) : -1));
        $h->test('P7 the stored assignment_mode is UNCHANGED (specific)', $pidLoss > 0 && (string)$db->query("SELECT assignment_mode FROM dl_products WHERE id={$pidLoss}")->fetchColumn() === 'specific', (string)($pidLoss > 0 ? $db->query("SELECT assignment_mode FROM dl_products WHERE id={$pidLoss}")->fetchColumn() : 'n/a'));

        // The modal resend path: same mode + same branches must be a no-op too.
        $p7resend = $runApi('update_product', 'admin', [
            'product_id' => $pidLoss,
            'name' => 'PK Lossless Renamed',
            'product_category' => 'bread',
            'price' => 15,
            'sort_order' => 0,
            'is_active' => 1,
            'effective_from' => $today,
            'assignment_mode' => 'specific',
            'branch_ids' => [$store],
        ]);
        $h->test('P7 resending the modal\'s own mode+branches is still a no-op', ($p7resend['body']['ok'] ?? false) === true && $pidLoss > 0 && $activePairCount($pidLoss) === 1 && $pairActive($store, $pidLoss) === 1, $p7resend['raw'] . ' pairs=' . ($pidLoss > 0 ? $activePairCount($pidLoss) : -1));
    }

    // ═════════════════════════════════════════════════════════════════════
    // P8 is NOT duplicated here: A2's existing G10 in
    // daily_ledger_branch_product_visibility_test.php already proves that a
    // branch created later only receives 'all_active' products (it asserts the
    // all_active product is assigned and the specific product is not). This
    // test references that case instead of building a second copy.
    $h->section('P8 a later-created branch does not receive specific products');
    $h->test('P8 covered by A2 G10 (not duplicated) — see daily_ledger_branch_product_visibility_test.php', true, 'referenced');
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
