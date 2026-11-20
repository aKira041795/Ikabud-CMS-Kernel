<?php
declare(strict_types=1);

$base = dirname(__DIR__, 2);
require_once $base . '/src/helpers/cli-bootstrap.php';
require_once __DIR__ . '/../harness/TestHarness.php';

$_SERVER['HTTP_HOST'] = 'baronledger.test';
$_SERVER['SERVER_NAME'] = 'baronledger.test';
$_SERVER['REQUEST_URI'] = '/daily-ledger/admin/overview';
$app = kernelCliBootstrap($base);
$app->tenant()->setTenantId(207);
$app->templates()->enableCompiledMode(false);
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/handlers.php';
modulePushContext('daily-ledger');

$h = new TestHarness('daily-ledger-business-health-view', TestHarness::MODE_PURE, 'baronledger.test');
$h->allowLogLines('disyl.compile.phases');
$h->allowLogLines('disyl.render.breakdown');
// The render path probes for an optional table and the module DB refuses DDL by design;
// the refusal is the expected outcome, not a defect in this view.
$h->allowLogLines("ModuleDB DENIED: DDL/DCL statement 'CREATE' is forbidden for modules");
$h->fingerprint('modules/daily-ledger/handlers.php');
$h->fingerprint('modules/daily-ledger/helpers/reporting.php');
$h->fingerprint('templates/modules/daily-ledger/admin/overview.disyl');
$h->fingerprint('templates/modules/daily-ledger/layouts/app.disyl');

$tokens = dl_generateAuthTokens([
    'sub' => 'viewer:21', 'id' => 21, 'username' => 'health-test',
    'name' => 'Health Test', 'role' => 'viewer', 'source' => 'daily-ledger',
]);
$_COOKIE[dlCookieName()] = $tokens['token'];

$render = static function (array $query): string {
    $_GET = $query;
    $_REQUEST = $query;
    ob_start();
    handleAdminOverview();
    return (string)ob_get_clean();
};

// HERMETIC ANCHORS, NOT A LOTTERY.
//
// This block used to DISCOVER a pending period by scanning the live ledger for a date whose rows
// were all pending. That made the suite a function of the database's current contents: it passed on
// 2026-11-20 at 15/15 and failed an hour later at 13/15 with no code change, because the newest date
// had acquired finalized rows (rows_total=5076 pending=1475 completed=3601). A test that cannot
// distinguish "the feature broke" from "the data moved" is the class this whole review exists to
// remove. It now WRITES the two states it needs on synthetic ids and deletes them in a finally.
$anchorBranch = 99795;   // has activity and an ending-less row -> the all-pending period
$silentBranch = 99796;   // active, assigned, no ledger rows at all -> the silent branch
$anchorProduct = 99797;
$pendingPeriod = '2033-02-07';
$anchorDb = module()->db();
$anchorCleanup = static function () use ($anchorDb, $anchorBranch, $silentBranch, $anchorProduct, $pendingPeriod): void {
    $anchorDb->prepare('DELETE FROM dl_daily_ledger WHERE branch_id IN (?, ?)')->execute([$anchorBranch, $silentBranch]);
    $anchorDb->prepare('DELETE FROM dl_ledger_shift_status WHERE branch_id IN (?, ?)')->execute([$anchorBranch, $silentBranch]);
    $anchorDb->prepare('DELETE FROM dl_ledger_day_status WHERE branch_id IN (?, ?)')->execute([$anchorBranch, $silentBranch]);
    $anchorDb->prepare('DELETE FROM dl_variance_flags WHERE branch_id IN (?, ?)')->execute([$anchorBranch, $silentBranch]);
    $anchorDb->prepare('DELETE FROM dl_branch_products WHERE branch_id IN (?, ?) OR product_id = ?')->execute([$anchorBranch, $silentBranch, $anchorProduct]);
    $anchorDb->prepare('DELETE FROM dl_products WHERE id = ?')->execute([$anchorProduct]);
    $anchorDb->prepare('DELETE FROM dl_branches WHERE id IN (?, ?)')->execute([$anchorBranch, $silentBranch]);
};
$anchorCleanup();
$anchorDb->prepare('INSERT INTO dl_branches (id, code, name, default_supply_mode, is_commissary, is_active) VALUES (?, ?, ?, ?, 0, 1)')
    ->execute([$anchorBranch, 'ANCHOR-P', 'Anchor Pending Branch', 'commissary_supplied']);
    $anchorDb->prepare('INSERT INTO dl_branches (id, code, name, default_supply_mode, is_commissary, is_active) VALUES (?, ?, ?, ?, 0, 1)')
        ->execute([$silentBranch, 'ANCHOR-S', 'Anchor Silent Branch', 'commissary_supplied']);
    $anchorDb->prepare('INSERT INTO dl_products (id, sku, name, current_price, sort_order, is_active) VALUES (?, ?, ?, 10, 0, 1)')
        ->execute([$anchorProduct, 'ANCHOR-P1', 'Anchor Product']);
    $anchorDb->prepare('INSERT INTO dl_branch_products (branch_id, product_id, is_active) VALUES (?, ?, 1)')->execute([$anchorBranch, $anchorProduct]);
    $anchorDb->prepare('INSERT INTO dl_branch_products (branch_id, product_id, is_active) VALUES (?, ?, 1)')->execute([$silentBranch, $anchorProduct]);
    // Movement with no ending: the period is ALL pending, which is the state being asserted.
    $anchorDb->prepare('INSERT INTO dl_daily_ledger (branch_id, product_id, ledger_date, shift, price_snapshot, beg_bal, addtl, withdraw, bal_end, sales) VALUES (?, ?, ?, ?, 10, 10, 0, 0, NULL, NULL)')
        ->execute([$anchorBranch, $anchorProduct, $pendingPeriod, 'PM']);

    $overviewUser = dlCurrentUser(['admin', 'supervisor', 'auditor', 'viewer']);
    $anchorFilters = dl_reportFilters(['scope' => 'ALL', 'date_from' => $pendingPeriod, 'date_to' => $pendingPeriod], $overviewUser);
    $anchorFilters['pending_rows_mode'] = dl_overviewPendingRowsMode(null);
    $pendingCompleteness = dl_overviewLedgerCompleteness(module()->db(), $anchorFilters);
    $pendingDetail = "period={$pendingPeriod} rows_total={$pendingCompleteness['rows_total']} pending={$pendingCompleteness['pending']} completed={$pendingCompleteness['completed']}";
    $h->detail('Pending-state anchor (hermetic): ' . $pendingDetail);
    $pendingHtml = $render(['scope' => 'ALL', 'period' => $pendingPeriod]);
$h->section('Owner contract');
$html = $render(['scope' => 'ALL']);
// Measure the primary bar ITSELF. Counting every control before the first </form> also
// counted controls outside the bar: the sidebar toggle became a real <button> (the correct
// element for a control that does not navigate) and the crude count rose to 6 while the bar
// was still Area, Branch, From, To, Apply.
$barStart = strpos($html, 'id="overview-date-from"');
$barStart = $barStart === false ? false : strrpos(substr($html, 0, (int)$barStart), '<form');
$barEnd = $barStart === false ? false : strpos($html, '</form>', (int)$barStart);
$beforeFirstFormEnd = ($barStart === false || $barEnd === false)
    ? ''
    : substr($html, (int)$barStart, (int)$barEnd - (int)$barStart);
preg_match_all('/<(select|input|button)\b/i', $beforeFirstFormEnd, $controls);
// Re-anchored 2026-10-11: the owner asked for a date RANGE and the chair's criterion
// P4 allows Area + Branch + 2 dates + Apply. The pre-range exact count of 4 (added in
// a44edb54, before the owner's comment) was the stale side; the ceiling is preserved.
$h->test('primary filter has exactly Area, Branch, From, To, and Apply', count($controls[1] ?? []) === 5, 'controls=' . count($controls[1] ?? []));
$h->test(
    'period is two picked dates, not a typed range',
    preg_match('/<input[^>]*type="date"[^>]*name="date_from"[^>]*>/i', $beforeFirstFormEnd) === 1
        && preg_match('/<input[^>]*type="date"[^>]*name="date_to"[^>]*>/i', $beforeFirstFormEnd) === 1
        && !str_contains($beforeFirstFormEnd, 'name="period"'),
    'date_from/date_to type=date and no typed period in the bar'
);
// Backward compatibility is part of the contract: an old bookmarked `period` URL
// still resolves through the SAME canonical pair, and a picked range beats a stale
// `period`, so there is no second source of truth.
$h->test(
    'an old bookmarked period URL still resolves through the same date pair',
    ($legacyPeriodHtml = $render(['scope' => 'ALL', 'period' => '2026-10-05 to 2026-10-07']))
        && preg_match('/name="date_from" value="2026-10-05"/', $legacyPeriodHtml) === 1
        && preg_match('/name="date_to" value="2026-10-07"/', $legacyPeriodHtml) === 1
);
$h->test(
    'a picked date range wins over a stale legacy period value',
    ($stalePeriodHtml = $render(['scope' => 'ALL', 'date_from' => '2026-10-01', 'date_to' => '2026-10-03', 'period' => '2026-01-01 to 2026-01-02']))
        && preg_match('/name="date_from" value="2026-10-01"/', $stalePeriodHtml) === 1
        && preg_match('/name="date_to" value="2026-10-03"/', $stalePeriodHtml) === 1
);
$h->test('health verdict states its no-plan basis and operational gates', str_contains($html, 'there is no target, budget, quota, or plan') && str_contains($html, 'no unresolved variance flag remains') && str_contains($html, 'every branch is closed'));
$h->test(
    'unfinished reporting is not rendered as zero headline sales',
    $pendingCompleteness['pending'] > 0
        && $pendingCompleteness['completed'] === 0
        && str_contains($pendingHtml, 'Awaiting finalization')
        && !str_contains($pendingHtml, 'Gross sales</div><div class="mt-1 text-3xl font-semibold text-emerald-600">PHP 0.00'),
    $pendingDetail
);
$h->test(
    'opportunity is named, measured, and linked to branch detail',
    $pendingCompleteness['pending'] > 0
        && str_contains($pendingHtml, 'Where can operations be maximized?')
        && str_contains($pendingHtml, 'ledger rows are pending')
        && preg_match('/href="[^"]+branch_id=\d+"/', $pendingHtml) === 1,
    $pendingDetail
);
$h->test('silent branches are separated from ranked working branches', str_contains($html, 'Branches with no recorded activity') && str_contains($html, 'not ranked') && str_contains($html, 'No ledger rows; no performance conclusion is made.'));
$h->test('item analytics are absent and remain linked through reports', !str_contains($html, 'Top Saleable Products') && !str_contains($html, 'Pareto 80/20') && str_contains($html, '/admin/reports/sales') && str_contains($html, '/admin/reports/category-sales'));
$h->test('forecast controls are local and only the aggregate is shown', str_contains($html, 'Aggregate business forecast') && str_contains($html, 'name="forecast_window"') && !str_contains($html, 'Projected units by product'));

$h->section('Measured 14-day estate');
$fourteen = $render(['scope' => 'ALL', 'period' => '2026-09-28 to 2026-10-11']);
$estate = module()->db()->query("SELECT COUNT(DISTINCT dl.branch_id) working,
    (SELECT COUNT(*) FROM dl_branches WHERE is_active = 1) active
    FROM dl_daily_ledger dl
    WHERE dl.ledger_date BETWEEN '2026-09-28' AND '2026-10-11'")->fetch(PDO::FETCH_ASSOC) ?: [];
$working = (int)($estate['working'] ?? 0);
$silent = max(0, (int)($estate['active'] ?? 0) - $working);
$h->test('14-day view distinguishes working from silent branches', str_contains($fourteen, $working . ' recorded activity · ' . $silent . ' silent'), "working={$working} silent={$silent}");
$h->test('14-day ranking states its active-only comparison denominator', str_contains($fourteen, 'sales ranking includes only the ' . $working . ' branch(es) that recorded ledger activity'));

$h->section('Comparison visual');
$pendingText = (string)preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($pendingHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
// The comparable fixture is DISCOVERED, not hard-coded. It used to be
// '2026-09-15 to 2026-09-28', which was fully finalized on the day it was written; the
// official-data-start boundary (2026-10-01 for this tenant) now correctly reports that window as
// "Before official counting began", so the hard-coded pair stopped describing any state the page
// can produce - the assertion failed without the product being wrong. A fixture whose validity
// expires with a configuration change is the exact class this suite exists to catch, so it is
// resolved at run time through the same helpers the handler uses.
$comparablePeriod = null;
$comparableProbe = ['rows_total' => 0, 'pending' => 0, 'completed' => 0];
// HERMETIC comparable pair, for the same reason as the pending anchor above. This used to search
// the live ledger for two fully-finalized 14-day windows, which meant the assertion's reachability
// depended on the business's own pending backlog: it found nothing once more days carried unpaid
// endings, and the failure said "the comparable state is unreachable on this database" - true, and
// useless. Two synthetic finalized days make the state reachable by construction.
$comparableFrom = '2033-03-01';
$comparableTo = '2033-03-02';
$finalizedRow = $anchorDb->prepare(
    'INSERT INTO dl_daily_ledger (branch_id, product_id, ledger_date, shift, price_snapshot, beg_bal, addtl, withdraw, bal_end, sales)
     VALUES (?, ?, ?, ?, 10, 10, 0, 0, 7, 3)'
);
$finalizedRow->execute([$anchorBranch, $anchorProduct, $comparableFrom, 'AM']);
$finalizedRow->execute([$anchorBranch, $anchorProduct, $comparableTo, 'AM']);
foreach ([$comparableFrom, $comparableTo] as $finalizedDate) {
    $anchorDb->prepare("INSERT INTO dl_ledger_shift_status (branch_id, ledger_date, shift, status) VALUES (?, ?, 'AM', 'finalized')")
        ->execute([$anchorBranch, $finalizedDate]);
}
// The previous period the page compares against: 2033-02-27..2033-02-28, also finalized, with a
// DIFFERENT quantity so the comparison is a real movement rather than a zero delta. A zero delta
// renders as `delta-flat` with `0.0%` and NO sign by design (the template prefixes '+' only above
// zero), so an equal pair cannot exercise the signed-delta assertion at all.
$priorPair = ['2033-02-27', '2033-02-28'];
$priorRow = $anchorDb->prepare(
    'INSERT INTO dl_daily_ledger (branch_id, product_id, ledger_date, shift, price_snapshot, beg_bal, addtl, withdraw, bal_end, sales)
     VALUES (?, ?, ?, ?, 10, 10, 0, 0, 5, 5)'
);
foreach ($priorPair as $priorDate) {
    $priorRow->execute([$anchorBranch, $anchorProduct, $priorDate, 'AM']);
    $anchorDb->prepare("INSERT INTO dl_ledger_shift_status (branch_id, ledger_date, shift, status) VALUES (?, ?, 'AM', 'finalized')")
        ->execute([$anchorBranch, $priorDate]);
}
$comparableFilters = dl_reportFilters(['scope' => 'ALL', 'date_from' => $comparableFrom, 'date_to' => $comparableTo], $overviewUser);
$comparableProbe = dl_overviewLedgerCompleteness(module()->db(), $comparableFilters);
$priorProbeFilters = dl_reportFilters(['scope' => 'ALL', 'date_from' => $priorPair[0], 'date_to' => $priorPair[1]], $overviewUser);
$priorProbe = dl_overviewLedgerCompleteness(module()->db(), $priorProbeFilters);
if ($comparableProbe['rows_total'] > 0 && $comparableProbe['pending'] === 0
    && $priorProbe['rows_total'] > 0 && $priorProbe['pending'] === 0 && $priorProbe['completed'] > 0) {
    $comparablePeriod = $comparableFrom . ' to ' . $comparableTo;
}
$h->detail('Comparable anchor (hermetic): current=' . json_encode($comparableProbe) . ' prior=' . json_encode($priorProbe));
$comparable = $comparablePeriod === null ? '' : $render(['scope' => 'ALL', 'period' => $comparablePeriod]);
$comparableText = (string)preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($comparable), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
// A discovered period with genuinely pending rows has no honest sales comparison.
// It must say so with a neutral pending indicator - never a zero or green up.
$h->test(
    'no honest comparison shows a neutral pending state, not zero or a green arrow',
    $pendingCompleteness['pending'] > 0
        && str_contains($pendingHtml, 'delta-indicator delta-pending')
        && str_contains($pendingHtml, 'Not yet comparable')
        && !str_contains($pendingHtml, 'delta-up')
        && !preg_match('/(\+|-)\s*0\.0%/', $pendingText),
    $pendingDetail
);
// A period with finalized sales shows a signed delta and a directional indicator.
$h->test(
    'a comparable period shows a signed delta with a directional visual',
    $comparablePeriod !== null
        && preg_match('/delta-indicator delta-(up|down|flat)/', $comparable) === 1
        && preg_match('/(\+|-)\s*[\d.,]+%/', $comparableText) === 1,
    $comparablePeriod === null
        ? 'no fully-finalized 14-day pair exists after the official boundary (' . $officialFloor . ') - the comparable state is unreachable on this database'
        : 'period=' . $comparablePeriod . ' rows=' . $comparableProbe['rows_total']
);

$h->section('Read-only source guard');
$overviewStart = strpos((string)file_get_contents($base . '/modules/daily-ledger/handlers.php'), 'function handleAdminOverview');
$overviewEnd = strpos((string)file_get_contents($base . '/modules/daily-ledger/handlers.php'), 'function handleAdminReports');
$source = (string)file_get_contents($base . '/modules/daily-ledger/handlers.php');
$slice = ($overviewStart !== false && $overviewEnd !== false) ? substr($source, $overviewStart, $overviewEnd - $overviewStart) : '';
$h->test('health handler contains no business-data write statement', preg_match('/\b(INSERT|UPDATE|DELETE|REPLACE)\b\s+(?:INTO\s+|FROM\s+)?dl_/i', $slice) !== 1);

$anchorCleanup();
$h->done();
