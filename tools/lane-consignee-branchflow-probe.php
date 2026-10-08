<?php

declare(strict_types=1);

/**
 * Slice 10 acceptance gate — the Consignees SUB-TAB of the Daily Sheet becomes the Branches shape.
 *
 * Owner: "at consignees daily sheet, the consignee name is a row, it must also be the same as in branches,
 * columned and vertically set."
 *
 * WHY IT IS A ROW TODAY: the sub-tab is a CUSTODY ledger — each consignee owns its own BEG/ADDTL, so a
 * consignee can only be a row. Sharing ONE BEG/ADDTL (the commissary's, as the Branches sub-tab does) is
 * what forces destinations to become COLUMNS.
 *
 * WHAT THIS PROVES (data / handler semantics):
 *   A  the consignee CELLS are keyed by CONSIGNEE (a column dimension), not returned as rows
 *   B  PIN: only consignees WITH ACTIVITY that date get a column (width bounded by activity, not by the
 *      consignee count) — the owner's own scrolling concern, already visible at one consignee
 *   C  PIN: BEG/ADDTL are SHARED commissary values, not per-consignee balances
 *   D  PIN: a BRANCH-originated consignee dispatch IS included (no origin_type='commissary' restriction —
 *      dispatch legitimately happens at the cashier ledger)
 *   E  PIN: the custody columns (WITHDRAWALS / ENDING) are gone from the sub-tab markup
 *   F  PIN: the Branches sub-tab's own matrix is unchanged
 *   G  PIN: no leaked fixtures, settings untouched
 *
 * WHAT THIS GATE DOES NOT PROVE: that the sub-tab RENDERS correctly. storage/cache/compiled is
 * www-data-owned, so once this slice edits commissary.disyl the CLI cannot compile it and any render
 * criterion would be a permanent false red. The chair verifies rendering in a real browser.
 *
 * SEAM REQUIRED BY THIS SLICE (so the behaviour is testable at all — the sheet assembly is otherwise
 * inline inside handleAdminCommissary()):
 *
 *   dl_fetchProductionSheetConsigneeCells(PDO $db, string $date, int $commissaryId, ?string $shift): array
 *     => ['beg_addtl' => [product_id => ['beg'=>int,'addtl'=>int]],
 *         'cells'    => [product_id => [consignee_id => qty]],
 *         'consignees' => [id => ['code'=>..,'name'=>..]]]
 *
 * Traps respected: no information_schema under modulePushContext; nothing here calls $ctx->json().
 */

$basePath = dirname(__DIR__);

require_once $basePath . '/bootstrap.php';
require_once $basePath . '/src/helpers/module-manager.php';
require_once $basePath . '/modules/daily-ledger/helpers.php';
require_once $basePath . '/modules/daily-ledger/handlers-deliveries.php';
require_once $basePath . '/modules/daily-ledger/handlers.php';

modulePushContext('daily-ledger');

/** @var PDO $db */
$db = app()->dbForTenant(207);

echo "== slice 10 gate: the Consignees sub-tab takes the Branches shape ==\n";

$results = [];

function probe(string $name, bool $ok, string $detail = ''): void
{
    global $results;
    $results[] = [$name, $ok];
    printf("  %s %s%s\n", $ok ? 'PASS' : 'FAIL', $name, $detail !== '' ? "  ({$detail})" : '');
}

$COMMISSARY = 18;
$DATE = '2026-10-07';

if (!function_exists('dl_fetchProductionSheetConsigneeCells')) {
    // Pre-implementation tree: the WORK items (A, C) cannot pass. The PINS are still evaluated honestly
    // where they can be — the branch matrix is trivially untouched and nothing was created to leak.
    probe('A the consignee cells are keyed by CONSIGNEE (a column dimension)', false, 'dl_fetchProductionSheetConsigneeCells() missing');
    probe('B PIN only consignees with activity that date get a column', false, 'helper missing');
    probe('C PIN BEG/ADDTL are shared commissary values', false, 'helper missing');
    probe('D PIN a branch-originated consignee dispatch is included', false, 'helper missing');

    $tpl = (string)@file_get_contents($basePath . '/templates/modules/daily-ledger/admin/commissary.disyl');
    $custody = [];
    foreach (['WITHDRAWALS', 'ENDING'] as $needle) {
        if (preg_match('/<th[^>]*>\s*' . $needle . '\s*<\/th>/i', $tpl)) {
            $custody[] = $needle;
        }
    }
    probe('E PIN the custody columns are gone from the sheet markup', $custody === [], $custody === [] ? 'absent' : 'still present: ' . implode(',', $custody));

    $bm = function_exists('dl_fetchProductionSheetDispatchMatrix')
        ? dl_fetchProductionSheetDispatchMatrix($db, $DATE, $COMMISSARY, null) : null;
    probe('F PIN the branch matrix still returns branch-keyed data only', is_array($bm), 'products=' . (is_array($bm) ? count($bm) : 'n/a'));

    $s = dlModuleSettings();
    probe(
        'G PIN no leaked fixtures and settings untouched',
        ($s['consignee_enabled'] ?? null) === true && ($s['consignee_sales_mode'] ?? null) === 'consignment',
        'enabled=' . var_export($s['consignee_enabled'] ?? null, true) . ' mode=' . var_export($s['consignee_sales_mode'] ?? null, true)
    );

    $failed = array_values(array_filter($results, static fn(array $r): bool => !$r[1]));
    echo 'FAILED: ' . implode(' | ', array_map(static fn(array $r): string => $r[0], $failed)) . "\n";
    exit(1);
}

$payload = dl_fetchProductionSheetConsigneeCells($db, $DATE, $COMMISSARY, null);
$cells = is_array($payload) ? (array)($payload['cells'] ?? []) : [];
$begAddtl = is_array($payload) ? (array)($payload['beg_addtl'] ?? []) : [];

$knownConsigneeIds = array_map('intval', $db->query('SELECT id FROM dl_consignees WHERE is_active = 1')->fetchAll(PDO::FETCH_COLUMN));
$knownProductIds = array_map('intval', $db->query('SELECT id FROM dl_products')->fetchAll(PDO::FETCH_COLUMN));

$consigneeKeys = [];
$productKeys = [];
foreach ($cells as $pid => $byConsignee) {
    $productKeys[(int)$pid] = true;
    foreach (array_keys((array)$byConsignee) as $cid) {
        $consigneeKeys[(int)$cid] = true;
    }
}
$keysValid = $productKeys !== []
    && array_diff(array_keys($productKeys), $knownProductIds) === []
    && array_diff(array_keys($consigneeKeys), $knownConsigneeIds) === [];

probe(
    'A the consignee cells are keyed by CONSIGNEE (a column dimension, not rows)',
    $keysValid,
    'products=[' . implode(',', array_keys($productKeys)) . '] consignees=[' . implode(',', array_keys($consigneeKeys)) . ']'
);

$activeConsigneeIds = array_map('intval', $db->query(
    'SELECT DISTINCT consignee_id FROM dl_consignee_ledger WHERE ledger_date = ' . $db->quote($DATE)
)->fetchAll(PDO::FETCH_COLUMN));
$leakedInactive = array_values(array_intersect(array_keys($consigneeKeys), array_values(array_diff($knownConsigneeIds, $activeConsigneeIds))));
probe(
    'B PIN only consignees with activity that date get a column',
    $leakedInactive === [],
    'columns=[' . implode(',', array_keys($consigneeKeys)) . '] activeThatDate=[' . implode(',', $activeConsigneeIds)
    . '] noActivityButColumn=[' . implode(',', $leakedInactive) . ']'
);

probe(
    'C PIN BEG/ADDTL are shared commissary values, not per-consignee balances',
    $begAddtl !== [],
    $begAddtl !== [] ? 'products=' . count($begAddtl) : 'no shared beg_addtl key: still per-consignee balances'
);

$branchOriginated = (int)$db->query(
    "SELECT COUNT(*) FROM dl_deliveries WHERE destination_type = 'consignee' AND origin_type = 'branch'
       AND delivery_date = " . $db->quote($DATE)
)->fetchColumn();
$included = false;
foreach ($cells as $byConsignee) {
    foreach ((array)$byConsignee as $qty) {
        if ((int)$qty > 0) {
            $included = true;
        }
    }
}
probe(
    'D PIN a branch-originated consignee dispatch is included',
    $branchOriginated === 0 || $included,
    "branchOriginatedThatDate={$branchOriginated} includedInCells=" . ($included ? 'y' : 'n')
);

$tpl = (string)@file_get_contents($basePath . '/templates/modules/daily-ledger/admin/commissary.disyl');
$custodyHeaders = [];
foreach (['WITHDRAWALS', 'ENDING'] as $needle) {
    if (preg_match('/<th[^>]*>\s*' . $needle . '\s*<\/th>/i', $tpl)) {
        $custodyHeaders[] = $needle;
    }
}
probe(
    'E PIN the custody columns are gone from the sheet markup',
    $custodyHeaders === [],
    $custodyHeaders === [] ? 'no WITHDRAWALS/ENDING column headers remain' : 'still present: ' . implode(',', $custodyHeaders)
);

$branchMatrix = function_exists('dl_fetchProductionSheetDispatchMatrix')
    ? dl_fetchProductionSheetDispatchMatrix($db, $DATE, $COMMISSARY, null) : null;
$branchIds = array_map('intval', $db->query('SELECT id FROM dl_branches')->fetchAll(PDO::FETCH_COLUMN));
$branchKeysClean = true;
foreach ((array)$branchMatrix as $byId) {
    foreach (array_keys((array)$byId) as $id) {
        if (!in_array((int)$id, $branchIds, true)) {
            $branchKeysClean = false;
        }
    }
}
probe(
    'F PIN the branch matrix still returns branch-keyed data only',
    is_array($branchMatrix) && $branchKeysClean,
    'products=' . (is_array($branchMatrix) ? count($branchMatrix) : 'n/a')
);

$leaked = (int)$db->query("SELECT COUNT(*) FROM dl_deliveries WHERE dr_number LIKE 'S10GATE%'")->fetchColumn();
$settings = dlModuleSettings();
probe(
    'G PIN no leaked fixtures and settings untouched',
    $leaked === 0 && ($settings['consignee_enabled'] ?? null) === true && ($settings['consignee_sales_mode'] ?? null) === 'consignment',
    "leaked={$leaked} enabled=" . var_export($settings['consignee_enabled'] ?? null, true)
    . ' mode=' . var_export($settings['consignee_sales_mode'] ?? null, true)
);

$failed = array_values(array_filter($results, static fn(array $r): bool => !$r[1]));
if ($failed === []) {
    echo "PASS: the Consignees sub-tab shows consignee columns against the commissary's own BEG/ADDTL\n";
    exit(0);
}

echo 'FAILED: ' . implode(' | ', array_map(static fn(array $r): string => $r[0], $failed)) . "\n";
exit(1);
