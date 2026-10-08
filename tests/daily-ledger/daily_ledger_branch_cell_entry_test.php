<?php

declare(strict_types=1);

/**
 * Daily Ledger — S10 branch-cell entry (Commissary Daily Sheet).
 *
 * Each branch cell is an editable entry point that records a real delivery:
 *   - a first entry is a plain quantity and mints exactly one commissary -> branch
 *     delivery (origin_id set, or a commissary-filtered sheet blanks the cell);
 *   - editing an existing entry requires Type + Reason Code, copied verbatim from
 *     the cashier withdrawal modal, and appends ANOTHER signed delivery so the
 *     original is never overwritten and the sheet's bottom log reads
 *     original entry -> correction.
 *
 * A sheet entry needs no DR; the cashier receive path still does. Tenant 207.
 */

ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-branch-cell-entry', TestHarness::MODE_INTEGRATION, 'localhost');
ob_end_clean();
$h->allowLogLines('disyl.compile.phases');

$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/helpers.php';
require_once $base . '/modules/daily-ledger/handlers-deliveries.php';
require_once $base . '/modules/daily-ledger/handlers.php';

$h->fingerprint('modules/daily-ledger/handlers.php');
$h->fingerprint('templates/modules/daily-ledger/admin/commissary.disyl');
$h->fingerprint('templates/modules/daily-ledger/cashier/modal_patch.disyl');

app()->tenant()->setTenantId(207);
$ctx = modulePushContext('daily-ledger');
if (!$ctx) {
    fwrite(STDERR, "daily-ledger module context unavailable\n");
    exit(1);
}
$db = $ctx->db();

$commissaryId = 99001;
$branchA = 99002;
$branchB = 99003;
$productA = 99001;
$productB = 99002;
$date = '2020-04-01';
$user = ['id' => 1, 'sub' => 'admin:1', 'role' => 'admin', 'source' => 'daily-ledger', 'name' => 'Test Admin'];

$cleanup = static function () use ($db, $commissaryId, $branchA, $branchB, $productA, $productB): void {
    $branchIds = "{$commissaryId},{$branchA},{$branchB}";
    $productIds = "{$productA},{$productB}";
    $db->execute("DELETE FROM dl_integrity_notification_recipients WHERE notification_id IN (SELECT id FROM dl_integrity_notifications WHERE branch_id IN ({$branchIds}))");
    $db->execute("DELETE FROM dl_integrity_notifications WHERE branch_id IN ({$branchIds})");
    // Movements reference products and branches with RESTRICT, so they must go first.
    $db->execute("DELETE FROM dl_production_movements WHERE destination_branch_id IN ({$branchIds}) OR product_id IN ({$productIds})");
    $db->execute("DELETE FROM audit_logs WHERE branch_id IN ({$branchIds})");
    $db->execute("DELETE FROM dl_production_runs WHERE product_id IN ({$productIds}) OR destination_branch_id IN ({$branchIds})");
    $db->execute("DELETE FROM dl_branch_receivings WHERE branch_id IN ({$branchIds}) OR delivery_id IN (SELECT id FROM dl_deliveries WHERE origin_id IN ({$branchIds}) OR destination_id IN ({$branchIds}))");
    $db->execute("DELETE FROM dl_delivery_items WHERE delivery_id IN (SELECT id FROM dl_deliveries WHERE origin_id IN ({$branchIds}) OR destination_id IN ({$branchIds}))");
    $db->execute("DELETE FROM dl_deliveries WHERE origin_id IN ({$branchIds}) OR destination_id IN ({$branchIds})");
    $db->execute("DELETE FROM dl_commissary_product_ledger WHERE commissary_branch_id IN ({$branchIds})");
    $db->execute("DELETE FROM dl_branch_products WHERE branch_id IN ({$branchIds}) OR product_id IN ({$productIds})");
    $db->execute("DELETE FROM dl_products WHERE id IN ({$productIds})");
    $db->execute("DELETE FROM dl_branches WHERE id IN ({$branchIds})");
};
$cleanup();

$h->section('Static: cell trigger, modal and shared vocabulary');
$tpl = (string)file_get_contents($base . '/templates/modules/daily-ledger/admin/commissary.disyl');
$cashierModal = (string)file_get_contents($base . '/templates/modules/daily-ledger/cashier/modal_patch.disyl');

$h->test(
    'AC1 branch cell renders as a trigger and keeps its branch id',
    str_contains($tpl, 'production-branch-trigger')
    && str_contains($tpl, 'class="text-right production-branch-cell" data-branch-id="{cell.branch_id}"')
    && str_contains($tpl, 'onclick="openBranchCellModal({row.product_id}, {cell.branch_id})"')
    && str_contains($tpl, 'class="production-branch-value"')
);
$h->test(
    'AC2 modal preselects product and shows the branch read-only',
    str_contains($tpl, 'id="branch-cell-product"')
    && str_contains($tpl, 'id="branch-cell-branch-id"')
    && str_contains($tpl, 'id="branch-cell-branch-name"')
    && str_contains($tpl, 'readonly')
    && str_contains($tpl, 'productEl.value = String(productId)')
);
$h->test(
    'AC4 the modal requires Type and Reason only when the cell has an entry',
    str_contains($tpl, 'id="branch-cell-edit-fields"')
    && str_contains($tpl, "data-exists=\"{if cell.has_entry}1{else}0{/if}\"")
    && str_contains($tpl, 'Type is required to change a recorded entry.')
    && str_contains($tpl, 'Reason code is required to change a recorded entry.')
);
$h->test(
    'correction vocabulary is copied from the cashier modal, not invented',
    str_contains($tpl, '<option value="correction">Correction — wrong entry</option>')
    && str_contains($tpl, '<option value="encoder_omission">Encoder omission (nothing lost)</option>')
    && str_contains($cashierModal, '<option value="correction">Correction — W/Draw (wrong entry)</option>')
    && str_contains($cashierModal, '<option value="encoder_omission">Encoder omission (nothing lost)</option>')
    && substr_count($tpl, '<option value="manual_adjustment">') === 2
    && str_contains($tpl, 'id="branch-cell-custom-reason-wrap"')
);
$h->test(
    'signed quantity rule is explained inline and negatives are accepted',
    str_contains($tpl, 'put a minus sign first')
    && str_contains($tpl, '-3')
    && str_contains($tpl, 'id="branch-cell-qty"')
    && str_contains($tpl, 'qty === 0')
);
$h->test(
    'bottom log exposes branch and type for the correction trail',
    str_contains($tpl, '<th>Branch</th>')
    && str_contains($tpl, '<th>Type</th>')
    && str_contains($tpl, 'entry.source != \'branch_entry\'')
);

// AC12 is a regression guard on the untouched tenant: render the real sheet BEFORE
// the synthetic fixtures exist, so 174 rows / 10 columns is the true baseline.
$runtimeShape = static function (): array {
    $harness = __DIR__ . '/daily_ledger_branch_cell_entry_runtime_harness.php';
    $output = [];
    $code = 0;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harness) . ' 2>/dev/null', $output, $code);
    if ($code !== 0) {
        return [];
    }
    $decoded = json_decode(implode("\n", $output), true);
    return is_array($decoded) ? $decoded : [];
};
$shape = $runtimeShape();
// Derive the expected sheet shape from the same live data the handler reads,
// instead of hard-coding the current tenant counts (174 x 10). The all filter
// uses the first active commissary (by name) as the product source and the
// template excludes that source commissary from the branch columns.
$sheetSourceId = (int)($db->query('SELECT id FROM dl_branches WHERE is_commissary = 1 AND is_active = 1 ORDER BY name ASC LIMIT 1')->fetchColumn() ?: 0);
$expectedRows = count(dl_fetchProductionSheetProducts($db, $sheetSourceId));
$expectedCols = (int)$db->query('SELECT COUNT(*) FROM dl_branches WHERE is_active = 1')->fetchColumn();
if ($sheetSourceId > 0) {
    $expectedCols--;
}
$h->test(
    'AC12 runtime sheet shape matches the live product set and active branch columns',
    $expectedRows > 0
    && $expectedCols > 0
    && ($shape['rows'] ?? -1) === $expectedRows
    && ($shape['branch_headers'] ?? -1) === $expectedCols
    && ($shape['branch_cells'] ?? -1) === $expectedRows * $expectedCols
    && ($shape['branch_triggers'] ?? -1) === $expectedRows * $expectedCols,
    'got=' . json_encode($shape) . ' expected rows=' . $expectedRows . ' cols=' . $expectedCols
);

$h->section('Core: first entry is a real delivery');
try {
    $db->prepare('INSERT INTO dl_branches (id, code, name, default_supply_mode, is_commissary, is_active) VALUES (?, ?, ?, ?, ?, 1)')
        ->execute([$commissaryId, 'S10-COMM', 'S10 Commissary', 'self_managed', 1]);
    $db->prepare('INSERT INTO dl_branches (id, code, name, default_supply_mode, is_commissary, is_active) VALUES (?, ?, ?, ?, ?, 1)')
        ->execute([$branchA, 'S10-A', 'S10 Branch A', 'commissary_supplied', 0]);
    $db->prepare('INSERT INTO dl_branches (id, code, name, default_supply_mode, is_commissary, is_active) VALUES (?, ?, ?, ?, ?, 1)')
        ->execute([$branchB, 'S10-B', 'S10 Branch B', 'commissary_supplied', 0]);
    $db->prepare('INSERT INTO dl_products (id, sku, name, product_category, sort_order, is_active) VALUES (?, ?, ?, ?, ?, 1)')
        ->execute([$productA, 'S10-A', 'S10 Zulu', 'cake', 20]);
    $db->prepare('INSERT INTO dl_products (id, sku, name, product_category, sort_order, is_active) VALUES (?, ?, ?, ?, ?, 1)')
        ->execute([$productB, 'S10-B', 'S10 Alpha', 'bread', 10]);
    $db->prepare('INSERT INTO dl_branch_products (branch_id, product_id, is_active) VALUES (?, ?, ?)')
        ->execute([$commissaryId, $productA, 1]);
    $db->prepare('INSERT INTO dl_branch_products (branch_id, product_id, is_active) VALUES (?, ?, ?)')
        ->execute([$commissaryId, $productB, 1]);
    // G2: the destination store must carry the product for a sheet delivery.
    $db->prepare('INSERT INTO dl_branch_products (branch_id, product_id, is_active) VALUES (?, ?, ?)')
        ->execute([$branchA, $productA, 1]);

    $first = dl_recordDailySheetBranchEntry($user, [
        'date' => $date,
        'commissary_branch_id' => $commissaryId,
        'destination_branch_id' => $branchA,
        'product_id' => $productA,
        'quantity' => 10,
        'submission_id' => 's10-first',
    ]);
    $h->test('AC3 first entry creates one delivery', !empty($first['delivery_id']) && empty($first['has_previous_entry']));
    $delivery = $db->prepare('SELECT * FROM dl_deliveries WHERE id = :id');
    $delivery->execute([':id' => (int)$first['delivery_id']]);
    $deliveryRow = $delivery->fetch(PDO::FETCH_ASSOC) ?: [];
    $h->test(
        'AC3 delivery is commissary -> branch with origin_id set and no DR',
        ($deliveryRow['origin_type'] ?? '') === 'commissary'
        && (int)($deliveryRow['origin_id'] ?? 0) === $commissaryId
        && ($deliveryRow['destination_type'] ?? '') === 'branch'
        && (int)($deliveryRow['destination_id'] ?? 0) === $branchA
        && $deliveryRow['dr_number'] === null
        && ($deliveryRow['status'] ?? '') === 'posted'
    );
    $items = $db->prepare('SELECT product_id, quantity FROM dl_delivery_items WHERE delivery_id = :id');
    $items->execute([':id' => (int)$first['delivery_id']]);
    $itemRows = $items->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $h->test(
        'AC3 first entry creates exactly one item for that product',
        count($itemRows) === 1 && (int)$itemRows[0]['product_id'] === $productA && (int)$itemRows[0]['quantity'] === 10
    );
    $matrix = dl_fetchProductionSheetDispatchMatrix($db, $date, $commissaryId);
    $h->test('AC9 commissary-filtered matrix shows the entry (origin_id present)', (int)($matrix[$productA][$branchA] ?? 0) === 10);
    $h->test('AC10 a sheet entry succeeds with no DR', $deliveryRow['dr_number'] === null);

    $h->section('Core: editing is append-only, signed and requires type + reason');
    $beforeCount = (int)$db->query("SELECT COUNT(*) FROM dl_deliveries WHERE origin_id = {$commissaryId}")->fetchColumn();
    $refusedType = false;
    try {
        dl_recordDailySheetBranchEntry($user, [
            'date' => $date,
            'commissary_branch_id' => $commissaryId,
            'destination_branch_id' => $branchA,
            'product_id' => $productA,
            'quantity' => -3,
            'reason_code' => 'encoder_omission',
            'submission_id' => 's10-missing-type',
        ]);
    } catch (\RuntimeException $e) {
        $refusedType = str_contains($e->getMessage(), 'Type is required');
    }
    $h->test('AC4 editing without Type is refused', $refusedType);
    $refusedReason = false;
    try {
        dl_recordDailySheetBranchEntry($user, [
            'date' => $date,
            'commissary_branch_id' => $commissaryId,
            'destination_branch_id' => $branchA,
            'product_id' => $productA,
            'quantity' => -3,
            'type' => 'correction',
            'submission_id' => 's10-missing-reason',
        ]);
    } catch (\RuntimeException $e) {
        $refusedReason = str_contains($e->getMessage(), 'Reason code is required');
    }
    $h->test('AC4 editing without Reason is refused', $refusedReason);
    $h->test(
        'AC4 nothing is written on the refused edits',
        (int)$db->query("SELECT COUNT(*) FROM dl_deliveries WHERE origin_id = {$commissaryId}")->fetchColumn() === $beforeCount
    );

    $correction = dl_recordDailySheetBranchEntry($user, [
        'date' => $date,
        'commissary_branch_id' => $commissaryId,
        'destination_branch_id' => $branchA,
        'product_id' => $productA,
        'quantity' => -3,
        'type' => 'correction',
        'reason_code' => 'encoder_omission',
        'submission_id' => 's10-correction-minus-3',
    ]);
    $matrix = dl_fetchProductionSheetDispatchMatrix($db, $date, $commissaryId);
    $h->test('AC5 a negative correction decreases the cell by exactly N', (int)($matrix[$productA][$branchA] ?? 0) === 7);
    $h->test('AC6 the original entry delivery and item are still present',
        (int)$db->query("SELECT COUNT(*) FROM dl_delivery_items WHERE delivery_id = " . (int)$first['delivery_id'] . " AND quantity = 10")->fetchColumn() === 1
    );
    $h->test('AC6 the correction is a separate appended delivery',
        (int)$correction['delivery_id'] !== (int)$first['delivery_id']
        && (int)$db->query("SELECT COUNT(*) FROM dl_deliveries WHERE origin_id = {$commissaryId}")->fetchColumn() === $beforeCount + 1
    );

    $positive = dl_recordDailySheetBranchEntry($user, [
        'date' => $date,
        'commissary_branch_id' => $commissaryId,
        'destination_branch_id' => $branchA,
        'product_id' => $productA,
        'quantity' => 5,
        'type' => 'correction',
        'reason_code' => 'manual_adjustment',
        'submission_id' => 's10-correction-plus-5',
    ]);
    $matrix = dl_fetchProductionSheetDispatchMatrix($db, $date, $commissaryId);
    $h->test('AC5 a positive entry increases the cell by exactly N', (int)($matrix[$productA][$branchA] ?? 0) === 12);
    $h->test(
        'a custom reason is required when Reason is Other',
        (function () use ($user, $date, $commissaryId, $branchA, $productA): bool {
            try {
                dl_recordDailySheetBranchEntry($user, [
                    'date' => $date, 'commissary_branch_id' => $commissaryId, 'destination_branch_id' => $branchA,
                    'product_id' => $productA, 'quantity' => 1, 'type' => 'correction', 'reason_code' => 'other',
                    'submission_id' => 's10-other-blank',
                ]);
            } catch (\RuntimeException $e) {
                return str_contains($e->getMessage(), 'custom reason');
            }
            return false;
        })()
    );
    $h->test(
        'an identical submission is deduped, not written twice',
        (function () use ($user, $date, $commissaryId, $branchA, $productA, $db): bool {
            $countBefore = (int)$db->query("SELECT COUNT(*) FROM dl_deliveries WHERE origin_id = {$commissaryId}")->fetchColumn();
            $replay = dl_recordDailySheetBranchEntry($user, [
                'date' => $date, 'commissary_branch_id' => $commissaryId, 'destination_branch_id' => $branchA,
                'product_id' => $productA, 'quantity' => 5, 'type' => 'correction', 'reason_code' => 'manual_adjustment',
                'submission_id' => 's10-correction-plus-5',
            ]);
            $countAfter = (int)$db->query("SELECT COUNT(*) FROM dl_deliveries WHERE origin_id = {$commissaryId}")->fetchColumn();
            return !empty($replay['duplicate']) && $countBefore === $countAfter;
        })()
    );

    $h->section('Core: the bottom log carries the correction trail');
    $log = dl_fetchProductionLedgerLog($db, $commissaryId, $date);
    $branchLog = null;
    foreach ($log as $entry) {
        if ($entry['source'] === 'branch_entry' && (int)$entry['signed_qty'] === -3) {
            $branchLog = $entry;
            break;
        }
    }
    $h->test(
        'AC7 the adjustment is logged with signed amount, type, reason and user',
        $branchLog !== null
        && (int)$branchLog['signed_qty'] === -3
        && $branchLog['signed_display'] === '-3'
        && $branchLog['adjustment_type'] === 'correction'
        && $branchLog['reason_code'] === 'encoder_omission'
        && $branchLog['who'] !== ''
        && (int)$branchLog['branch_id'] === $branchA
        && $branchLog['branch_name'] === 'S10 Branch A'
        && $branchLog['before_display'] === '10'
        && $branchLog['after_display'] === '7'
        && $branchLog['source'] === 'branch_entry'
    );
    $omissionAudit = $db->prepare(
        "SELECT new_data FROM audit_logs
          WHERE module = 'daily-ledger' AND action = 'production_ledger_change'
            AND branch_id = :cb
            AND (new_data LIKE '%\"submission_id\":\"s10-correction-minus-3\"%'
                 OR new_data LIKE '%\"submission_id\": \"s10-correction-minus-3\"%')
          ORDER BY id DESC LIMIT 1"
    );
    $omissionAudit->execute([':cb' => $commissaryId]);
    $omissionData = json_decode((string)$omissionAudit->fetchColumn(), true) ?: [];
    $h->test(
        'AC8 encoder_omission records no liable person and charges nobody',
        ($omissionData['reason_code'] ?? '') === 'encoder_omission'
        && ($omissionData['liable_user_id'] ?? null) === null
    );

    $h->section('AC11 the cashier receive path still requires a DR');
    $handlers = (string)file_get_contents($base . '/modules/daily-ledger/handlers.php');
    $h->test(
        'cashier paper-DR receive refuses an empty DR unless the server auto-mints one',
        str_contains($handlers, "\$drNumber = trim((string)(\$input['dr_number'] ?? ''));")
        && str_contains($handlers, 'if ($drNumber === \'\') {')
        && str_contains($handlers, "if (\$autoDr) {")
        && str_contains($handlers, "} else {\n            \$ctx->json(['ok' => false, 'error' => 'Paper DR number is required.'], 422);")
    );
    $h->test(
        'cashier source dispatch refuses an empty DR',
        str_contains($handlers, "if (\$drNumber === '') {\n        \$ctx->json(['ok' => false, 'error' => 'Paper DR number is required.'], 422);")
    );
    $emptyDrRefused = false;
    try {
        dl_upsertCommissaryOutputDeliveryItem($db, $branchB, $productB, $date, 4, '', 0, 0, $commissaryId);
    } catch (\RuntimeException $e) {
        $emptyDrRefused = str_contains($e->getMessage(), 'DR number');
    }
    $h->test('formal output delivery still rejects an empty DR by default', $emptyDrRefused);
    $sheetDeliveryId = dl_upsertCommissaryOutputDeliveryItem($db, $branchB, $productB, $date, 4, '', 0, 0, $commissaryId, true);
    $sheetDelivery = $db->prepare('SELECT origin_id, destination_id, dr_number FROM dl_deliveries WHERE id = :id');
    $sheetDelivery->execute([':id' => $sheetDeliveryId]);
    $sheetDeliveryRow = $sheetDelivery->fetch(PDO::FETCH_ASSOC) ?: [];
    $h->test(
        'the explicit sheet-entry exception allows an empty DR through the same guard',
        (int)$sheetDeliveryId > 0
        && (int)($sheetDeliveryRow['origin_id'] ?? 0) === $commissaryId
        && $sheetDeliveryRow['dr_number'] === null
    );

    $h->section('AC12 existing sheet shape intact');
    // The ORDER BY itself is proved behaviourally by daily_ledger_branch_order,
    // so this keeps only the structural template invariant that cannot be seen
    // from a rendered column list.
    $h->test(
        'the sheet excludes only the source commissary once',
        substr_count($tpl, '{if cell.branch_id != sheet_source_branch_id}') === 1
    );
} finally {
    $cleanup();
}

$h->test(
    'fixture cleanup leaves only the suite\'s own ledger and movement rows absent',
    (int)$db->query("SELECT COUNT(*) FROM dl_commissary_product_ledger WHERE commissary_branch_id IN ({$commissaryId},{$branchA},{$branchB}) OR product_id IN ({$productA},{$productB})")->fetchColumn() === 0
    && (int)$db->query("SELECT COUNT(*) FROM dl_production_runs WHERE destination_branch_id IN ({$commissaryId},{$branchA},{$branchB}) OR product_id IN ({$productA},{$productB})")->fetchColumn() === 0
    && (int)$db->query("SELECT COUNT(*) FROM dl_production_movements WHERE destination_branch_id IN ({$commissaryId},{$branchA},{$branchB}) OR product_id IN ({$productA},{$productB})")->fetchColumn() === 0
);
$h->done();
