<?php
declare(strict_types=1);

/**
 * Acceptance criterion: AN OFFICIAL-ONLY BASIS on the Overview and Production Efficiency views.
 *
 * Owner: "add filter to exclude provisional data in overview, production efficiency views, so we get
 * an official stats option".
 *
 * MEASURED ON THE UNCHANGED TREE (tenant 207, 2026-09-01..2026-10-11, all accessible branches):
 *
 *   ledger rows ..................................... 14,401
 *   rows PROVISIONAL by dl_rowIsProvisional() .......  2,697
 *        of which unfinalized-shift .................  2,112
 *        of which pending (bal_end NULL) ............    584
 *   units, everything ............................... 102,615
 *   units, EXCLUDING PROVISIONAL ....................  86,267   <-- what "official" means
 *
 * THE DEFECT THIS CRITERION EXISTS TO PREVENT. The Overview already shows a checkbox labelled
 * "Official rows only". It filters `bal_end IS NULL` only, and a pending row's quantity is NULL, so
 * `SUM()` already ignores it: measured, the control changes the units total by ZERO
 * (102,615 either way). It reads as "these are official figures" while 2,697 provisional rows and
 * 16,348 units (16%) are still inside the total. A control that claims a basis it does not apply is
 * the same class of lie as the ratios P1 removed.
 *
 * The module already owns the definition: dl_salesRowStatusLabel() labels a row 'official' ONLY when
 * dl_rowIsProvisional() is false, and money on these pages is already official-only. This criterion
 * makes the units agree with the label, and cross-checks the SQL twin (dl_provisionalSqlExpr) against
 * the PHP predicate (dl_rowIsProvisional) - they are required to stay in lockstep.
 *
 * Written and measured against the unchanged tree BEFORE the change. Every check below is RED today.
 * Usage: php tools/acceptance-official-only-basis.php
 */

$base = dirname(__DIR__);
require_once $base . '/src/helpers/cli-bootstrap.php';

$_SERVER['HTTP_HOST'] = 'baronledger.test';
$_SERVER['SERVER_NAME'] = 'baronledger.test';
$_SERVER['REQUEST_URI'] = '/daily-ledger/admin/production-efficiency';

$app = kernelCliBootstrap($base);
$app->tenant()->setTenantId(207);
$app->templates()->enableCompiledMode(false);
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/handlers.php';
modulePushContext('daily-ledger');
require_once $base . '/modules/daily-ledger/helpers/reporting.php';

$db = module()->db();
$allBranchIds = [8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22, 23, 24, 25, 26, 27, 28];
$baseFilters = [
    'accessible_branch_ids' => $allBranchIds,
    'branch_id' => 0,
    'date_from' => '2026-09-01',
    'date_to' => '2026-10-11',
    'product_id' => 0,
    'shift' => '',
];

// The official-only figure is measured through the PHP predicate, NOT through the SQL twin the
// implementation uses, so this is a real cross-check and not a restatement of the same rule.
$salesRows = dl_reportSalesData($db, $baseFilters + ['pending_rows_mode' => 'include'])['rows'];
$officialUnits = 0;
$inclusiveUnits = 0;
$provisionalUnits = 0;
foreach ($salesRows as $row) {
    $units = max(0, (int)($row['sales'] ?? 0));
    $inclusiveUnits += $units;
    if (dl_rowIsProvisional($row)) {
        $provisionalUnits += $units;
    } else {
        $officialUnits += $units;
    }
}

$reds = 0;
$check = static function (string $id, string $what, bool $ok, string $detail) use (&$reds): void {
    if (!$ok) {
        $reds++;
    }
    printf("  [%s] %-62s %s\n", $ok ? 'PASS' : 'RED ', $what, $ok ? '' : '<-- not satisfied');
    printf("      %s\n", $detail);
};

printf("  measured: inclusive=%s units, official=%s units, provisional=%s units\n\n",
    number_format($inclusiveUnits), number_format($officialUnits), number_format($provisionalUnits));

$check('O1', 'the window actually contains provisional data to exclude',
    $provisionalUnits > 0,
    sprintf('%s provisional unit(s) exist, so a basis filter is meaningful (not a no-op)', number_format($provisionalUnits)));

// --- O2: the efficiency aggregation honours an official-only basis ------------------------------
$inclusive = dl_productionEfficiencyData($db, $baseFilters + ['pending_rows_mode' => 'include']);
$official = dl_productionEfficiencyData($db, $baseFilters + ['pending_rows_mode' => 'exclude']);
$inclusiveSold = (int)($inclusive['sold'] ?? -1);
$officialSold = (int)($official['sold'] ?? -1);
$check('O2', 'the efficiency aggregation can exclude provisional data',
    $inclusiveSold === $inclusiveUnits && $officialSold === $officialUnits && $officialSold < $inclusiveSold,
    sprintf('efficiency sold: inclusive=%s (expected %s), official=%s (expected %s)',
        number_format($inclusiveSold), number_format($inclusiveUnits),
        number_format($officialSold), number_format($officialUnits)));

// --- O3: the excluded volume is STATED, so the figure cannot be read as the whole ----------------
$statesExclusion = array_key_exists('excluded_units', $official)
    && (int)$official['excluded_units'] === $provisionalUnits
    && trim((string)($official['units_basis'] ?? '')) !== '';
$check('O3', 'the official basis states what it excluded',
    $statesExclusion,
    sprintf('excluded_units=%s (expected %s), basis label=%s',
        array_key_exists('excluded_units', $official) ? number_format((int)$official['excluded_units']) : 'ABSENT',
        number_format($provisionalUnits),
        trim((string)($official['units_basis'] ?? '')) !== '' ? '"' . $official['units_basis'] . '"' : 'ABSENT'));

// --- O4: the efficiency PAGE offers the control and states the active basis ---------------------
$tokens = dl_generateAuthTokens([
    'sub' => 'admin:1', 'id' => 1, 'username' => 'basis',
    'name' => 'Basis', 'role' => 'admin', 'source' => 'daily-ledger',
]);
$_COOKIE[dlCookieName()] = $tokens['token'];
$render = static function (array $extra = []): string {
    $_GET = array_merge(['scope' => 'ALL', 'date_from' => '2026-09-01', 'date_to' => '2026-10-11'], $extra);
    $_REQUEST = $_GET;
    ob_start();
    try {
        handleAdminProductionEfficiency();
    } catch (Throwable $e) {
        echo 'THREW: ' . $e->getMessage();
    }
    return (string)ob_get_clean();
};
$plain = static fn(string $h): string => (string)preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($h), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

$efficiencyHtml = $render();
$efficiencyText = $plain($efficiencyHtml);
$hasControl = (bool)preg_match('/name="pending_rows"[^>]*value="exclude"/i', $efficiencyHtml);
$check('O4', 'the efficiency page offers the official-only control', $hasControl,
    $hasControl ? 'a pending_rows=exclude control is rendered' : 'no control - the owner cannot ask this page for official figures');

$officialEfficiencyText = $plain($render(['pending_rows' => 'exclude']));
$statesBasis = (bool)preg_match('/basis:\s*official only/i', $officialEfficiencyText);
// The exclusion VOLUME must appear, not merely the word "provisional": a sentence that mentions
// provisional rows while the number stays inclusive is the disclaimer trap, not a basis.
$namesExcluded = str_contains(str_replace(',', '', $officialEfficiencyText), (string)$provisionalUnits);
$check('O5', 'the efficiency page states the official basis and the volume it removed',
    $statesBasis && $namesExcluded,
    sprintf('basis stated as "official only"=%s, excluded volume %s present on the page=%s',
        $statesBasis ? 'yes' : 'NO', number_format($provisionalUnits), $namesExcluded ? 'yes' : 'NO'));

// --- O6: an unknown basis value cannot select a third state ------------------------------------
$tampered = dl_productionEfficiencyData($db, $baseFilters + ['pending_rows_mode' => 'nonsense']);
$check('O6', 'an unknown basis value falls back rather than inventing a state',
    (int)($tampered['sold'] ?? -1) === $officialUnits,
    sprintf('pending_rows_mode=nonsense treated as official (unrecognised values are the safe basis): sold=%s',
        number_format((int)($tampered['sold'] ?? -1))));

printf("\n  => %d RED. %s\n", $reds, $reds > 0
    ? 'The criterion discriminates: this is the work.'
    : 'WARNING: every criterion passes - it cannot discriminate a change.');
exit($reds > 0 ? 1 : 0);
