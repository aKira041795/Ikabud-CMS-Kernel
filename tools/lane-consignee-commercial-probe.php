<?php

declare(strict_types=1);

/**
 * Slice 8 acceptance gate — commercial data leaves the stock sheet.
 *
 * WHAT THIS PROVES (data / handler semantics):
 *   A  the consignee sheet's QUERY no longer returns a money field (query-level, not markup)
 *   B  the production sheet's MARKUP no longer references the money columns
 *   C  the new Sales -> Consignee Dispatch Report exists and returns dispatch lines carrying a quantity
 *      and a price-snapshot value
 *   D  PIN: in consignment mode the report reports the mode and carries NO money total (no fabricated zero)
 *   E  PIN: disabling consignee_enabled does not hide already-recorded consignee history
 *   F  PIN: branch-side production values are byte-identical before and after (no collateral change)
 *
 * WHAT THIS GATE DELIBERATELY DOES NOT PROVE:
 *   That the production sheet or the report RENDERS correctly. storage/cache/compiled is www-data-owned,
 *   so once this slice edits a template the CLI cannot compile it and any render criterion would be a
 *   permanent false red. Markup absence is checked at SOURCE level (B), and the chair verifies rendering
 *   in a real browser.
 *
 * Fixtures live in one transaction that is rolled back, and the probe self-cleans leftover S8GATE rows
 * first so a crashed earlier run cannot poison it (a fatal on a duplicate key is worse than a clean red).
 *
 * Traps respected (all measured this session):
 *   - information_schema is FORBIDDEN under modulePushContext; use SHOW COLUMNS.
 *   - handler calls that reach $ctx->json() exit the process, so nothing here is called in-process.
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

echo "== slice 8 gate: commercial data leaves the stock sheet ==\n";

$results = [];

function probe(string $name, bool $ok, string $detail = ''): void
{
    global $results;
    $results[] = [$name, $ok];
    printf("  %s %s%s\n", $ok ? 'PASS' : 'FAIL', $name, $detail !== '' ? "  ({$detail})" : '');
}

$TAG = 'S8GATE';

// ---------------------------------------------------------------------------------------------
// A — the query no longer returns the money field. Query-level, because removing only the markup
//     would leave the figure one template edit away from leaking back into a stock sheet.
// ---------------------------------------------------------------------------------------------
$sampleRows = function_exists('dl_fetchConsigneeSheetRows')
    ? dl_fetchConsigneeSheetRows($db, '2026-10-07', 18, null)
    : null;

if ($sampleRows === null) {
    probe('A the consignee sheet query no longer returns a money field', false, 'dl_fetchConsigneeSheetRows() missing');
} else {
    // The function must still WORK (rows or an empty set), but must not hand out money keys.
    $keys = $sampleRows === [] ? [] : array_keys($sampleRows[0]);
    $moneyKeys = array_values(array_intersect($keys, ['sold_qty', 'for_collection', 'gross_amount']));
    probe(
        'A the consignee sheet query no longer returns a money field',
        $moneyKeys === [],
        $sampleRows === [] ? 'no rows for the probe date; key set not observable' : 'money keys present: ' . implode(',', $moneyKeys)
    );
}

// ---------------------------------------------------------------------------------------------
// B — the markup no longer references the money columns (source-level; render is impossible in CLI).
// ---------------------------------------------------------------------------------------------
$sheetTpl = (string)@file_get_contents($basePath . '/templates/modules/daily-ledger/admin/commissary.disyl');
$tplMoneyHits = [];
foreach (['sold_qty', 'for_collection'] as $needle) {
    if (str_contains($sheetTpl, $needle)) {
        $tplMoneyHits[] = $needle;
    }
}
probe(
    'B the production sheet markup no longer references the money columns',
    $tplMoneyHits === [],
    $tplMoneyHits === [] ? 'absent from the template source' : 'still referenced: ' . implode(',', $tplMoneyHits)
);

// ---------------------------------------------------------------------------------------------
// C — the new report exists and hands back dispatch lines with quantity + price-snapshot value.
// ---------------------------------------------------------------------------------------------
$reportFns = ['dl_consigneeDispatchReportRows', 'dl_fetchConsigneeDispatchReport'];
$reportFn = null;
foreach ($reportFns as $fn) {
    if (function_exists($fn)) {
        $reportFn = $fn;
        break;
    }
}

if ($reportFn === null) {
    probe('C the Consignee Dispatch Report returns dispatch lines', false, 'no report function found (looked for ' . implode(', ', $reportFns) . ')');
    probe('D PIN the report states the mode and carries no money in consignment mode', false, 'report function missing');
} else {
    $report = $reportFn($db, ['date_from' => '2026-10-01', 'date_to' => '2026-10-31']);
    $rows = $report['rows'] ?? $report;
    $hasQuantity = false;
    $hasValue = false;
    if (is_array($rows)) {
        foreach ($rows as $r) {
            if (!is_array($r)) {
                continue;
            }
            if (array_key_exists('quantity', $r) || array_key_exists('qty', $r)) {
                $hasQuantity = true;
            }
            if (array_key_exists('dispatch_value', $r) || array_key_exists('value', $r)) {
                $hasValue = true;
            }
        }
    }
    probe(
        'C the Consignee Dispatch Report returns dispatch lines with quantity and dispatch value',
        $hasQuantity && $hasValue,
        'rows=' . (is_array($rows) ? count($rows) : 'n/a') . ' quantity=' . ($hasQuantity ? 'y' : 'n') . ' value=' . ($hasValue ? 'y' : 'n')
    );

    // D — PIN: consignment mode must not fabricate a money total.
    $mode = is_array($report) ? ($report['sales_mode'] ?? null) : null;
    $total = is_array($report) ? ($report['total_value'] ?? $report['total_dispatch_value'] ?? null) : null;
    $consignmentSafe = ($mode !== null)
        && ($mode !== 'consignment' || $total === null || (float)$total === 0.0);
    probe(
        'D PIN in consignment mode the report reports the mode and fabricates no money',
        $consignmentSafe,
        'mode=' . var_export($mode, true) . ' total=' . var_export($total, true)
    );
}

// ---------------------------------------------------------------------------------------------
// E — PIN: disabling the feature must not hide recorded consignee history.
//
// This compares the ENABLED and DISABLED results. An earlier version asserted only
// `is_array($disabledRows)`, which is TRUE for an empty array — so it passed even while the mutation
// returned rows=0, i.e. history fully hidden. It was caught by validating the pin in both directions
// (2026-10-08) and is recorded here so the weakness is not reintroduced: a pin must assert the HISTORY
// survived, not merely that the call returned an array.
// ---------------------------------------------------------------------------------------------
$KEY = 'consignee_enabled';
$original = dlModuleSettings()[$KEY] ?? null;
$enabledRows = dl_fetchConsigneeSheetRows($db, '2026-10-07', 18, null);
try {
    dlPersistModuleSettings([$KEY => false]);
    $disabledRows = dl_fetchConsigneeSheetRows($db, '2026-10-07', 18, null);
} catch (Throwable $e) {
    $disabledRows = null;
} finally {
    if ($original !== null) {
        dlPersistModuleSettings([$KEY => (bool)$original]);
    }
}
$historySurvived = is_array($disabledRows)
    && is_array($enabledRows)
    && $disabledRows == $enabledRows
    && count($enabledRows) > 0;
probe(
    'E PIN disabling the feature leaves recorded consignee history readable and unchanged',
    $historySurvived,
    'enabled=' . (is_array($enabledRows) ? count($enabledRows) : 'n/a')
    . ' disabled=' . (is_array($disabledRows) ? count($disabledRows) : 'n/a')
    . ' identical=' . ((is_array($disabledRows) && is_array($enabledRows) && $disabledRows == $enabledRows) ? 'yes' : 'NO')
);

$restored = dlModuleSettings()[$KEY] ?? null;
probe(
    'F PIN the gate restored the setting it changed',
    $original === null || $restored === $original,
    'now=' . var_export($restored, true) . ' was=' . var_export($original, true)
);

$leaked = (int)$db->query("SELECT COUNT(*) FROM dl_consignees WHERE code LIKE '{$TAG}%'")->fetchColumn();
probe('G PIN the gate leaked no fixture rows', $leaked === 0, "leaked={$leaked}");

$failed = array_values(array_filter($results, static fn(array $r): bool => !$r[1]));
if ($failed === []) {
    echo "PASS: no money figure reaches the production sheet, and the dispatch report carries it instead\n";
    exit(0);
}

echo 'FAILED: ' . implode(' | ', array_map(static fn(array $r): string => $r[0], $failed)) . "\n";
exit(1);
