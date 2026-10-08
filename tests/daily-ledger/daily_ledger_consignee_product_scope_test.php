<?php

declare(strict_types=1);

/**
 * Daily Ledger — Slice 7 consignee product-scope oracle.
 *
 * Asserts the OUTCOMES the contract requires after the product modal's
 * assignment control was extended to cover consignees:
 *   A  the column exists and defaults to all_active
 *   B  a missing mode coerces to all_active
 *   C  a garbage mode coerces to all_active (never to the destructive specific)
 *   D  'specific' assigns exactly the ticked consignees and deactivates the rest
 *   E  PIN — 'all_active' is ADDITIVE: never decreases the pair count, reaches
 *      every active consignee, and keeps a pre-existing consignee AND branch pair
 *      (defends the single dangerous outcome: editing a product silently wiping
 *      its pairs)
 *   F  PIN — the consignee path never touches dl_branch_products
 *   G  PIN — an old payload with no consignee keys leaves the branch side
 *      byte-identical (defends backward compatibility for existing callers)
 *   R5 a NEW consignee receives every active 'all_active' product, and a
 *      'specific' product is NEVER auto-assigned to it
 *   R6 the consignee sheet data path reflects the new assignment rows
 *
 * Every fixture is synthetic and removed in finally; the final pin proves the
 * tenant tables are back to their pre-test counts.
 *
 * Handler calls run in a child process because $ctx->json() exits the process;
 * an in-process call would silently false-pass (rc=0, no assertions run). The
 * helper calls return arrays and are safely in-process, exactly like the gate.
 */

ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-consignee-product-scope', TestHarness::MODE_INTEGRATION, 'baronledger.test');
ob_end_clean();

$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/helpers.php';
require_once $base . '/modules/daily-ledger/handlers-deliveries.php';
require_once $base . '/modules/daily-ledger/handlers.php';
foreach ([
    'modules/daily-ledger/handlers.php',
    'modules/daily-ledger/module.json',
    'modules/daily-ledger/database/migrations/085_add_consignee_assignment_mode.sql',
    'templates/modules/daily-ledger/admin/products.disyl',
    'tests/daily-ledger/daily_ledger_consignee_scope_harness.php',
] as $file) {
    $h->fingerprint($file);
}
$h->allowLogLines('kernel_state_cache: module_registry rebuilt', 'kernel_state_cache: capability_map rebuilt', 'capability.call', 'disyl.compile.phases');

app()->tenant()->setTenantId(207);
$ctx = modulePushContext('daily-ledger');
$db = $ctx->db();

// ─── Fixtures (synthetic ids; never real branch 8 / real consignee) ────────
$comm        = 99611; // active commissary branch owning the fixture consignees
$store       = 99612; // active non-commissary branch for the branch-side pins
$cKeep       = 99621;
$cT1         = 99622;
$cT2         = 99623;
$cUnticked   = 99624;
$cSheet      = 99626;
$pHelper     = 99631;
$pAll        = 99632;
$pSpec       = 99633;
$pInactive   = 99634;
$pSheet      = 99635;
$createdProductIds = [];
$createdConsigneeIds = [];
$payloadFile = tempnam(sys_get_temp_dir(), 'dl-consignee-scope-');
$harness = __DIR__ . '/daily_ledger_consignee_scope_harness.php';
$today = dl_businessDate();

$allFixtureConsigneeIds = static function () use (&$createdConsigneeIds, $cKeep, $cT1, $cT2, $cUnticked, $cSheet): array {
    return array_values(array_unique(array_merge([$cKeep, $cT1, $cT2, $cUnticked, $cSheet], $createdConsigneeIds)));
};
$allFixtureProductIds = static function () use (&$createdProductIds, $pHelper, $pAll, $pSpec, $pInactive, $pSheet): array {
    return array_values(array_unique(array_merge([$pHelper, $pAll, $pSpec, $pInactive, $pSheet], $createdProductIds)));
};

$cleanup = static function () use ($db, $comm, $store, &$createdProductIds, &$createdConsigneeIds, $allFixtureConsigneeIds, $allFixtureProductIds): void {
    $cids = implode(',', array_map('intval', $allFixtureConsigneeIds()));
    $pids = implode(',', array_map('intval', $allFixtureProductIds()));
    $db->execute("DELETE FROM audit_logs WHERE module = 'daily-ledger' AND (branch_id IN ({$comm},{$store}) OR (entity_type = 'product' AND entity_id IN ({$pids})) OR (entity_type = 'dl_consignees' AND entity_id IN ({$cids})) OR (entity_type = 'dl_consignee_products' AND (SUBSTRING_INDEX(entity_id, '-', 1) IN ({$cids}) OR SUBSTRING_INDEX(entity_id, '-', -1) IN ({$pids}))))");
    $db->execute("DELETE FROM dl_consignee_ledger WHERE consignee_id IN ({$cids}) OR product_id IN ({$pids})");
    $db->execute("DELETE FROM dl_consignee_products WHERE consignee_id IN ({$cids}) OR product_id IN ({$pids})");
    $db->execute("DELETE FROM dl_branch_products WHERE branch_id IN ({$comm},{$store}) OR product_id IN ({$pids})");
    $db->execute("DELETE FROM dl_consignees WHERE id IN ({$cids})");
    $db->execute("DELETE FROM dl_products WHERE id IN ({$pids})");
    $db->execute("DELETE FROM dl_branches WHERE id IN ({$comm},{$store})");
};

$snapshotCounts = static function () use ($db): array {
    return [
        'branches' => (int)$db->query('SELECT COUNT(*) FROM dl_branches')->fetchColumn(),
        'products' => (int)$db->query('SELECT COUNT(*) FROM dl_products')->fetchColumn(),
        'consignees' => (int)$db->query('SELECT COUNT(*) FROM dl_consignees')->fetchColumn(),
        'branch_products' => (int)$db->query('SELECT COUNT(*) FROM dl_branch_products')->fetchColumn(),
        'consignee_products' => (int)$db->query('SELECT COUNT(*) FROM dl_consignee_products')->fetchColumn(),
        'consignee_ledger' => (int)$db->query('SELECT COUNT(*) FROM dl_consignee_ledger')->fetchColumn(),
        'audit_logs' => (int)$db->query("SELECT COUNT(*) FROM audit_logs WHERE module = 'daily-ledger'")->fetchColumn(),
    ];
};

$activeConsigneePairs = static function (int $productId) use ($db): array {
    $s = $db->prepare('SELECT consignee_id FROM dl_consignee_products WHERE product_id = ? AND is_active = 1 ORDER BY consignee_id');
    $s->execute([$productId]);
    return array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
};
$branchPairs = static function (int $productId) use ($db): array {
    $s = $db->prepare('SELECT branch_id, is_active FROM dl_branch_products WHERE product_id = ? ORDER BY branch_id');
    $s->execute([$productId]);
    return $s->fetchAll(PDO::FETCH_ASSOC);
};
$rowValue = static function (string $sql, array $bind = []) use ($db) {
    $s = $db->prepare($sql);
    $s->execute($bind);
    return $s->fetchColumn();
};

$run = static function (string $mode, array $body) use ($payloadFile, $harness): array {
    file_put_contents($payloadFile, json_encode(['role' => 'admin', 'body' => $body], JSON_THROW_ON_ERROR));
    $out = [];
    $code = 0;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harness) . ' ' . escapeshellarg($mode) . ' ' . escapeshellarg($payloadFile) . ' 2>&1', $out, $code);
    $raw = implode("\n", $out);
    return ['code' => $code, 'body' => json_decode($raw, true), 'raw' => $raw];
};

$cleanup();
$countsBefore = $snapshotCounts();

try {
    // ─── Setup ─────────────────────────────────────────────────────────
    $db->prepare('INSERT INTO dl_branches (id, code, name, is_commissary, is_active) VALUES (?,?,?,?,1)')->execute([$comm, 'S7O-COM', 'Slice 7 Commissary', 1]);
    $db->prepare('INSERT INTO dl_branches (id, code, name, is_commissary, is_active) VALUES (?,?,?,?,1)')->execute([$store, 'S7O-STORE', 'Slice 7 Store', 0]);
    $consignee = $db->prepare('INSERT INTO dl_consignees (id, code, name, assigned_commissary_id, is_active) VALUES (?,?,?,?,1)');
    foreach ([[$cKeep, 'S7O-KEEP'], [$cT1, 'S7O-T1'], [$cT2, 'S7O-T2'], [$cUnticked, 'S7O-UNTICKED'], [$cSheet, 'S7O-SHEET']] as $row) {
        $consignee->execute([$row[0], $row[1], 'S7O ' . $row[1], $comm]);
    }
    $product = $db->prepare('INSERT INTO dl_products (id, sku, name, current_price, is_active, assignment_mode, consignee_assignment_mode) VALUES (?,?,?,?,?,?,?)');
    $product->execute([$pHelper, 'S7O-HELPER', 'S7O Helper', 10, 1, 'all_active', 'all_active']);
    $product->execute([$pAll, 'S7O-ALL', 'S7O All', 10, 1, 'all_active', 'all_active']);
    $product->execute([$pSpec, 'S7O-SPEC', 'S7O Spec', 10, 1, 'all_active', 'specific']);
    $product->execute([$pInactive, 'S7O-INACTIVE', 'S7O Inactive', 10, 0, 'all_active', 'all_active']);
    $product->execute([$pSheet, 'S7O-SHEET-P', 'S7O Sheet Product', 10, 1, 'all_active', 'all_active']);

    // ═══════════════════════════════════════════════════════════════════
    $h->section('A column contract');
    $col = null;
    foreach ($db->query('SHOW COLUMNS FROM dl_products')->fetchAll(PDO::FETCH_ASSOC) as $c) {
        if (strcasecmp((string)$c['Field'], 'consignee_assignment_mode') === 0) { $col = $c; break; }
    }
    $h->test(
        'A discriminating: dl_products.consignee_assignment_mode is ENUM(all_active,specific) NOT NULL default all_active',
        $col !== null
            && (string)($col['Default'] ?? '') === 'all_active'
            && (string)($col['Null'] ?? '') === 'NO'
            && stripos((string)($col['Type'] ?? ''), 'enum') === 0
            && str_contains((string)$col['Type'], "'all_active'")
            && str_contains((string)$col['Type'], "'specific'"),
        $col === null ? 'column absent' : json_encode($col)
    );

    // ═══════════════════════════════════════════════════════════════════
    $h->section('B/C normalizer');
    $h->test(
        'B discriminating: a missing consignee_assignment_mode coerces to all_active',
        dl_normalizeConsigneeAssignmentMode([])[0] === 'all_active',
        var_export(dl_normalizeConsigneeAssignmentMode([])[0], true)
    );
    $h->test(
        'C discriminating: a garbage mode coerces to all_active, never to the destructive specific',
        dl_normalizeConsigneeAssignmentMode(['consignee_assignment_mode' => 'garbage', 'consignee_ids' => [$cT1]])[0] === 'all_active',
        var_export(dl_normalizeConsigneeAssignmentMode(['consignee_assignment_mode' => 'garbage', 'consignee_ids' => [$cT1]])[0], true)
    );
    $h->test(
        'C2 discriminating: normalizer keeps positive unique ids and drops zero/negative/unknown',
        dl_normalizeConsigneeAssignmentMode(['consignee_assignment_mode' => 'specific', 'consignee_ids' => [$cT1, $cT1, 0, -5, 'abc']])[1] === [$cT1],
        json_encode(dl_normalizeConsigneeAssignmentMode(['consignee_assignment_mode' => 'specific', 'consignee_ids' => [$cT1, $cT1, 0, -5, 'abc']])[1])
    );

    // ═══════════════════════════════════════════════════════════════════
    $h->section('D/E/F helper outcomes');
    $db->prepare('INSERT INTO dl_consignee_products (consignee_id, product_id, is_active) VALUES (?,?,1)')->execute([$cKeep, $pHelper]);
    $db->prepare('INSERT INTO dl_branch_products (branch_id, product_id, is_active) VALUES (?,?,1)')->execute([$store, $pHelper]);

    $activeConsigneeCount = (int)$db->query('SELECT COUNT(*) FROM dl_consignees WHERE is_active = 1')->fetchColumn();
    $helperBranchBefore = $branchPairs($pHelper);
    $pairsBeforeHelper = count($activeConsigneePairs($pHelper));

    $applyAll = dl_applyProductConsigneeAssignmentMode($db, $pHelper, 'all_active', [], null);
    $pairsAfterHelper = $activeConsigneePairs($pHelper);
    $keepStillThere = in_array($cKeep, $pairsAfterHelper, true);
    $helperBranchAfterAll = $branchPairs($pHelper);
    $h->test(
        'E PIN all_active is additive: never decreases pairs, reaches every active consignee, keeps the pre-existing pair',
        ($applyAll['ok'] ?? false) === true
            && count($pairsAfterHelper) >= $pairsBeforeHelper
            && count($pairsAfterHelper) === $activeConsigneeCount
            && $keepStillThere,
        "before={$pairsBeforeHelper} after=" . count($pairsAfterHelper) . " activeConsignees={$activeConsigneeCount} preExistingKept=" . ($keepStillThere ? 'yes' : 'NO')
    );
    $h->test(
        'F PIN the consignee path never touches dl_branch_products (all_active)',
        $helperBranchAfterAll === $helperBranchBefore,
        'before=' . json_encode($helperBranchBefore) . ' after=' . json_encode($helperBranchAfterAll)
    );

    $applySpec = dl_applyProductConsigneeAssignmentMode($db, $pHelper, 'specific', [$cT1, $cT2], null);
    $specPairs = $activeConsigneePairs($pHelper);
    $wantSpec = [$cT1, $cT2];
    sort($specPairs);
    sort($wantSpec);
    $untickedOff = !in_array($cUnticked, $specPairs, true);
    $helperBranchAfterSpec = $branchPairs($pHelper);
    $h->test(
        'D discriminating: specific assigns exactly the ticked consignees and deactivates the unticked one',
        ($applySpec['ok'] ?? false) === true && $specPairs === $wantSpec && $untickedOff,
        'assigned=[' . implode(',', $specPairs) . '] want=[' . implode(',', $wantSpec) . '] untickedDeactivated=' . ($untickedOff ? 'yes' : 'NO')
    );
    $h->test(
        'F PIN the consignee path never touches dl_branch_products (specific)',
        $helperBranchAfterSpec === $helperBranchBefore,
        'before=' . json_encode($helperBranchBefore) . ' after=' . json_encode($helperBranchAfterSpec)
    );

    // ═══════════════════════════════════════════════════════════════════
    $h->section('R3 apiUpdateProduct + create path');
    $stamp = substr((string)time(), -5);
    $created = $run('create_product', [
        'name' => 'S7O API ' . $stamp,
        'price' => 9,
        'assignment_mode' => 'specific',
        'branch_ids' => [$store],
        'consignee_assignment_mode' => 'specific',
        'consignee_ids' => [$cUnticked],
    ]);
    $pid = (int)($created['body']['product_id'] ?? 0);
    if ($pid > 0) { $createdProductIds[] = $pid; }
    $createdMode = $pid > 0 ? (string)$rowValue('SELECT consignee_assignment_mode FROM dl_products WHERE id = ?', [$pid]) : '';
    $createdPairs = $pid > 0 ? $activeConsigneePairs($pid) : [];
    $h->test(
        'R3a discriminating: create persists consignee_assignment_mode=specific and exactly the ticked consignee',
        ($created['body']['ok'] ?? false) === true && $createdMode === 'specific' && $createdPairs === [$cUnticked],
        'ok=' . var_export($created['body']['ok'] ?? null, true) . ' mode=' . $createdMode . ' pairs=' . json_encode($createdPairs) . ' raw=' . $created['raw']
    );

    $updated = $run('update_product', [
        'product_id' => $pid,
        'name' => 'S7O API ' . $stamp,
        'product_category' => 'bread',
        'price' => 9,
        'effective_from' => $today,
        'sort_order' => 0,
        'is_active' => 1,
        'assignment_mode' => 'specific',
        'branch_ids' => [$store],
        'consignee_assignment_mode' => 'specific',
        'consignee_ids' => [$cT1, $cT2],
    ]);
    $updatedPairs = $pid > 0 ? $activeConsigneePairs($pid) : [];
    sort($updatedPairs);
    $wantUpdated = [$cT1, $cT2];
    sort($wantUpdated);
    $branchUpdated = $pid > 0 ? $branchPairs($pid) : [];
    $h->test(
        'R3b discriminating: specific update assigns exactly the ticked consignees and deactivates the rest',
        ($updated['body']['ok'] ?? false) === true && $updatedPairs === $wantUpdated && !in_array($cUnticked, $updatedPairs, true),
        'pairs=[' . implode(',', $updatedPairs) . '] raw=' . $updated['raw']
    );
    $h->test(
        'F PIN the API consignee path never touches dl_branch_products',
        count($branchUpdated) === 1 && (int)$branchUpdated[0]['branch_id'] === $store && (int)$branchUpdated[0]['is_active'] === 1,
        json_encode($branchUpdated)
    );

    $pairsBeforeAllApi = count($updatedPairs);
    $apiAll = $run('update_product', [
        'product_id' => $pid,
        'name' => 'S7O API ' . $stamp,
        'product_category' => 'bread',
        'price' => 9,
        'effective_from' => $today,
        'sort_order' => 0,
        'is_active' => 1,
        'assignment_mode' => 'specific',
        'branch_ids' => [$store],
        'consignee_assignment_mode' => 'all_active',
        'consignee_ids' => [],
    ]);
    $pairsAfterAllApi = $activeConsigneePairs($pid);
    $h->test(
        'E API PIN all_active is additive and reaches every active consignee',
        ($apiAll['body']['ok'] ?? false) === true
            && count($pairsAfterAllApi) >= $pairsBeforeAllApi
            && count($pairsAfterAllApi) === (int)$db->query('SELECT COUNT(*) FROM dl_consignees WHERE is_active = 1')->fetchColumn()
            && in_array($cUnticked, $pairsAfterAllApi, true),
        "before={$pairsBeforeAllApi} after=" . count($pairsAfterAllApi) . ' raw=' . $apiAll['raw']
    );

    // ═══════════════════════════════════════════════════════════════════
    $h->section('R7 old payload leaves the branch side byte-identical');
    $createdOld = $run('create_product', [
        'name' => 'S7O OLD ' . $stamp,
        'price' => 10,
        'assignment_mode' => 'specific',
        'branch_ids' => [$store],
        'consignee_assignment_mode' => 'specific',
        'consignee_ids' => [$cT1],
    ]);
    $pidOld = (int)($createdOld['body']['product_id'] ?? 0);
    if ($pidOld > 0) { $createdProductIds[] = $pidOld; }
    $oldBranchBefore = $pidOld > 0 ? $branchPairs($pidOld) : [];
    $oldConsBefore = $pidOld > 0 ? $activeConsigneePairs($pidOld) : [];
    $oldBranchModeBefore = $pidOld > 0 ? (string)$rowValue('SELECT assignment_mode FROM dl_products WHERE id = ?', [$pidOld]) : '';
    $oldConsModeBefore = $pidOld > 0 ? (string)$rowValue('SELECT consignee_assignment_mode FROM dl_products WHERE id = ?', [$pidOld]) : '';

    $oldPayload = $run('update_product', [
        'product_id' => $pidOld,
        'name' => 'S7O OLD renamed ' . $stamp,
        'product_category' => 'bread',
        'price' => 11,
        'effective_from' => $today,
        'sort_order' => 0,
        'is_active' => 1,
        // Deliberately NO assignment_mode / branch_ids / consignee keys: an
        // older or external caller's payload.
    ]);
    $oldBranchAfter = $pidOld > 0 ? $branchPairs($pidOld) : [];
    $oldConsAfter = $pidOld > 0 ? $activeConsigneePairs($pidOld) : [];
    $oldBranchModeAfter = $pidOld > 0 ? (string)$rowValue('SELECT assignment_mode FROM dl_products WHERE id = ?', [$pidOld]) : '';
    $oldConsModeAfter = $pidOld > 0 ? (string)$rowValue('SELECT consignee_assignment_mode FROM dl_products WHERE id = ?', [$pidOld]) : '';
    $h->test(
        'R7 PIN an old payload with no consignee keys leaves the branch side byte-identical',
        ($oldPayload['body']['ok'] ?? false) === true
            && $oldBranchAfter === $oldBranchBefore
            && $oldBranchModeAfter === $oldBranchModeBefore
            && $oldConsAfter === $oldConsBefore
            && $oldConsModeAfter === $oldConsModeBefore,
        'branch ' . json_encode($oldBranchBefore) . ' -> ' . json_encode($oldBranchAfter)
        . ' branchMode=' . $oldBranchModeBefore . '->' . $oldBranchModeAfter
        . ' consMode=' . $oldConsModeBefore . '->' . $oldConsModeAfter . ' raw=' . $oldPayload['raw']
    );

    // ═══════════════════════════════════════════════════════════════════
    // R4 read path — inspection-only: storage/cache/compiled is www-data-owned,
    // so the CLI cannot render products.disyl and a render check would be a
    // permanent false red. The browser is verified by the chair.
    // ═══════════════════════════════════════════════════════════════════
    $h->section('R4 modal read path (inspection-only)');
    $handlerSource = (string)file_get_contents($base . '/modules/daily-ledger/handlers.php');
    $templateSource = (string)file_get_contents($base . '/templates/modules/daily-ledger/admin/products.disyl');
    $subqueryCount = substr_count($handlerSource, 'AS assigned_consignee_ids');
    $h->test(
        'R4 discriminating: every modal read path selects assigned_consignee_ids, and the template emits the consignee list with {foreach consignees as c} under the relabelled single mode control',
        $subqueryCount >= 3
            && str_contains($templateSource, '{foreach consignees as c}')
            && substr_count($templateSource, '{foreach consignees as c}') >= 3
            && str_contains($templateSource, 'All active branches and consignees')
            && str_contains($templateSource, 'Only selected branches and consignees')
            && str_contains($templateSource, "p.assigned_consignee_ids")
            && str_contains($templateSource, "p.consignee_assignment_mode")
            && str_contains($templateSource, 'add-consignee-check')
            && str_contains($templateSource, 'edit-consignee-check')
            && str_contains($templateSource, "consignee_ids:"),
        'assigned_consignee_ids subqueries=' . $subqueryCount . ' foreachConsignees=' . substr_count($templateSource, '{foreach consignees as c}')
    );

    // ═══════════════════════════════════════════════════════════════════
    $h->section('R5 new consignee lazy materialisation');
    $lateCode = 'S7O-LATE-' . $stamp;
    $saved = $run('save_consignee', [
        'code' => $lateCode,
        'name' => 'S7O Late',
        'area' => '',
        'address' => '',
        'assigned_commissary_id' => $comm,
        'is_active' => 1,
    ]);
    $lateId = (int)($saved['body']['consignee_id'] ?? 0);
    if ($lateId > 0) { $createdConsigneeIds[] = $lateId; }
    $lateAllPair = $lateId > 0 ? (int)$rowValue('SELECT is_active FROM dl_consignee_products WHERE consignee_id = ? AND product_id = ?', [$lateId, $pAll]) : -1;
    $lateSpecPair = $lateId > 0 ? (int)$rowValue('SELECT COUNT(*) FROM dl_consignee_products WHERE consignee_id = ? AND product_id = ?', [$lateId, $pSpec]) : -1;
    $lateInactivePair = $lateId > 0 ? (int)$rowValue('SELECT COUNT(*) FROM dl_consignee_products WHERE consignee_id = ? AND product_id = ?', [$lateId, $pInactive]) : -1;
    $h->test(
        'R5 discriminating: a NEW consignee receives every active all_active product',
        ($saved['body']['ok'] ?? false) === true && $lateId > 0 && $lateAllPair === 1,
        'lateId=' . $lateId . ' allPair=' . $lateAllPair . ' raw=' . $saved['raw']
    );
    $h->test(
        'R5 PIN a specific product is NEVER auto-assigned to a consignee created later (and inactive all_active products are skipped)',
        $lateSpecPair === 0 && $lateInactivePair === 0,
        "specificPair={$lateSpecPair} inactivePair={$lateInactivePair}"
    );

    // ═══════════════════════════════════════════════════════════════════
    $h->section('R6 sheet reflects the new assignment rows');
    dl_setConsigneeProductActive($db, $cSheet, $pSheet, true, null);
    $db->prepare('INSERT INTO dl_consignee_ledger (consignee_id, product_id, ledger_date, shift, price_snapshot, beg_bal, addtl, withdraw) VALUES (?,?,?,?,?,?,?,?)')
       ->execute([$cSheet, $pSheet, $today, 'AM', 10.00, 0, 3, 0]);
    $sheetRows = dl_fetchConsigneeSheetRows($db, $today, $comm, 'AM');
    $sheetShows = false;
    foreach ($sheetRows as $row) {
        if ((int)$row['consignee_id'] === $cSheet && (int)$row['product_id'] === $pSheet) { $sheetShows = true; break; }
    }
    $activePairCountForCommissary = (int)$rowValue(
        'SELECT COUNT(*) FROM dl_consignee_products cp INNER JOIN dl_consignees c ON c.id = cp.consignee_id AND c.is_active = 1 INNER JOIN dl_products p ON p.id = cp.product_id AND p.is_active = 1 WHERE c.assigned_commissary_id = ? AND cp.is_active = 1',
        [$comm]
    );
    $h->test(
        'R6 discriminating: the consignee sheet data path and the assignment count both reflect the new active pair',
        $sheetShows && $activePairCountForCommissary >= 1,
        'sheetShows=' . ($sheetShows ? 'yes' : 'NO') . " commissaryActivePairs={$activePairCountForCommissary}"
    );
} finally {
    $cleanup();
    if (is_file($payloadFile)) {
        @unlink($payloadFile);
    }
}

// ─── Cleanup proof ─────────────────────────────────────────────────────
$h->section('G cleanup proof');
$countsAfter = $snapshotCounts();
$remaining = (int)$db->query('SELECT COUNT(*) FROM dl_consignees WHERE id IN (' . implode(',', array_map('intval', $allFixtureConsigneeIds())) . ')')->fetchColumn()
    + (int)$db->query('SELECT COUNT(*) FROM dl_products WHERE id IN (' . implode(',', array_map('intval', $allFixtureProductIds())) . ')')->fetchColumn()
    + (int)$db->query('SELECT COUNT(*) FROM dl_branches WHERE id IN (' . $comm . ',' . $store . ')')->fetchColumn();
$h->test(
    'G PIN every fixture row is cleaned up and the tenant counts are back to the pre-test baseline',
    $remaining === 0 && $countsAfter === $countsBefore,
    'remaining=' . $remaining . ' before=' . json_encode($countsBefore) . ' after=' . json_encode($countsAfter)
);

$h->done();
