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

$h->section('Owner contract');
$html = $render(['scope' => 'ALL']);
$formEnd = strpos($html, '</form>');
$beforeFirstFormEnd = $formEnd === false ? '' : substr($html, 0, $formEnd);
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
$h->test('health verdict states its no-plan basis and operational gates', str_contains($html, 'there is no target, budget, quota, or plan') && str_contains($html, 'no unresolved variance flag remains') && str_contains($html, 'every branch is closed'));
$h->test('unfinished reporting is not rendered as zero headline sales', str_contains($html, 'Awaiting finalization') && !str_contains($html, 'Gross sales</div><div class="mt-1 text-3xl font-semibold text-emerald-600">PHP 0.00'));
$h->test('opportunity is named, measured, and linked to branch detail', str_contains($html, 'Where can operations be maximized?') && str_contains($html, 'ledger rows are pending') && preg_match('/href="[^"]+branch_id=\d+"/', $html) === 1);
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
$liveText = (string)preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
$fourteenText = (string)preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($fourteen), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
// The live default period has zero completed rows, so no honest sales comparison
// exists. It must say so with a neutral pending indicator - never a zero or green up.
$h->test(
    'no honest comparison shows a neutral pending state, not zero or a green arrow',
    str_contains($html, 'delta-indicator delta-pending')
        && str_contains($html, 'Not yet comparable')
        && !str_contains($html, 'delta-up')
        && !preg_match('/(\+|-)\s*0\.0%/', $liveText)
);
// A period with finalized sales shows a signed delta and a directional indicator.
$h->test(
    'a comparable period shows a signed delta with a directional visual',
    preg_match('/delta-indicator delta-(up|down|flat)/', $fourteen) === 1
        && preg_match('/(\+|-)\s*[\d.,]+%/', $fourteenText) === 1
);

$h->section('Read-only source guard');
$overviewStart = strpos((string)file_get_contents($base . '/modules/daily-ledger/handlers.php'), 'function handleAdminOverview');
$overviewEnd = strpos((string)file_get_contents($base . '/modules/daily-ledger/handlers.php'), 'function handleAdminReports');
$source = (string)file_get_contents($base . '/modules/daily-ledger/handlers.php');
$slice = ($overviewStart !== false && $overviewEnd !== false) ? substr($source, $overviewStart, $overviewEnd - $overviewStart) : '';
$h->test('health handler contains no business-data write statement', preg_match('/\b(INSERT|UPDATE|DELETE|REPLACE)\b\s+(?:INTO\s+|FROM\s+)?dl_/i', $slice) !== 1);

$h->done();
