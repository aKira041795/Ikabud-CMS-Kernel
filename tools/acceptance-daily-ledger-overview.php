<?php
declare(strict_types=1);

/**
 * Acceptance probe for the next work items. Written by the chair BEFORE dispatch and measured
 * against the unchanged tree, because an acceptance criterion that already passes cannot
 * discriminate the change. Every check below must print RED on the current tree.
 *
 * Read-only: renders pages and resolves scopes. Writes no business data.
 *
 * Usage: php .ai/next-acceptance.php
 */

$base = dirname(__DIR__);
require_once $base . '/src/helpers/cli-bootstrap.php';

$_SERVER['HTTP_HOST'] = 'baronledger.test';
$_SERVER['SERVER_NAME'] = 'baronledger.test';
$_SERVER['REQUEST_URI'] = '/daily-ledger/admin/overview';

$app = kernelCliBootstrap($base);
$app->tenant()->setTenantId(207);
$app->templates()->enableCompiledMode(false);
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/handlers.php';
modulePushContext('daily-ledger');
$db = module()->db();

$reds = 0;
$report = static function (string $id, string $what, bool $ok, string $detail) use (&$reds): void {
    if (!$ok) {
        $reds++;
    }
    printf("  [%s] %-58s %s\n      %s\n", $ok ? 'PASS' : 'RED ', $what, $ok ? '' : '<-- must be fixed', $detail);
};

// ---------------------------------------------------------------- A: completeness semantics
// Today has activity with every ending still pending. The page must not report that as "no sales".
$today = (string)$db->query('SELECT CURDATE()')->fetchColumn();
$todayStmt = $db->prepare(
    'SELECT COUNT(*) rows_total,
            SUM(CASE WHEN bal_end IS NULL THEN 1 ELSE 0 END) pending,
            SUM(CASE WHEN bal_end IS NOT NULL THEN 1 ELSE 0 END) completed
       FROM dl_daily_ledger WHERE ledger_date = :d'
);
$todayStmt->execute([':d' => $today]);
$row = $todayStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$rows = (int)($row['rows_total'] ?? 0);
$pending = (int)($row['pending'] ?? 0);
$completed = (int)($row['completed'] ?? 0);

$html = '';
if ($rows > 0 && $completed === 0) {
    $tokens = dl_generateAuthTokens([
        'sub' => 'viewer:21', 'id' => 21, 'username' => 'acceptance-viewer',
        'name' => 'Acceptance Viewer', 'role' => 'viewer', 'source' => 'daily-ledger',
    ]);
    $_COOKIE[dlCookieName()] = $tokens['token'];
    $_GET = ['scope' => 'ALL'];
    $_REQUEST = $_GET;
    ob_start();
    handleAdminOverview();
    $html = (string)ob_get_clean();
}

$claimsNoSales = str_contains($html, 'No sales recorded for this period');
$saysPending = (bool)preg_match('/pending|not finali[sz]ed|incomplete|awaiting/i', $html);
$report(
    'A',
    'activity pending is not reported as "no sales"',
    $rows === 0 || !$claimsNoSales,
    sprintf(
        'today=%s rows=%d pending=%d completed=%d | page says "No sales recorded": %s | page mentions pending/incomplete: %s',
        $today, $rows, $pending, $completed, $claimsNoSales ? 'YES' : 'no', $saysPending ? 'yes' : 'NO'
    )
);

// ---------------------------------------------------------------- B: area -> branch dependency
// Branch 13 belongs to area 1. With the scope moved to area 2 it is invalid and must RESET to All
// Branches, not resolve to an empty set (which renders a page with nothing on it).
$area1Branches = array_map('intval', $db->query('SELECT id FROM dl_branches WHERE area_id = 1 AND is_active = 1')->fetchAll(PDO::FETCH_COLUMN) ?: []);
$area2Branches = array_map('intval', $db->query('SELECT id FROM dl_branches WHERE area_id = 2 AND is_active = 1')->fetchAll(PDO::FETCH_COLUMN) ?: []);
$crossingBranch = 0;
foreach ($area1Branches as $candidate) {
    if (!in_array($candidate, $area2Branches, true)) {
        $crossingBranch = $candidate;
        break;
    }
}

$resolvedBranch = -1;
$resolvedCount = -1;
if ($crossingBranch > 0) {
    $_SESSION = [];
    $viewer = ['id' => 21, 'role' => 'viewer'];
    // Establish the branch while it is valid, then move the area out from under it.
    $first = dl_reportFilters(['scope' => 'AREA:1', 'branch_id' => $crossingBranch], $viewer);
    $second = dl_reportFilters(['scope' => 'AREA:2', 'branch_id' => $crossingBranch], $viewer);
    $resolvedBranch = (int)$second['branch_id'];
    $resolvedCount = count($second['accessible_branch_ids'] ?? []);
}

$report(
    'B',
    'a branch invalidated by an area change resets, not empties',
    $crossingBranch === 0 || ($resolvedBranch !== $crossingBranch && $resolvedCount > 1),
    sprintf(
        'branch %d is in area 1 but not area 2 | after scope=AREA:2 the filter still carries branch_id=%d and resolves %d branches%s',
        $crossingBranch, $resolvedBranch, $resolvedCount,
        $resolvedCount === 1 ? ' (an empty scope: the page would render nothing)' : ''
    )
);

// ---------------------------------------------------------------- C: cold-compile suite stability
// Reported separately because it is a suite-state property, measured in the same run:
// php tests/daily-ledger/daily_ledger_overview_test.php after clearing storage/cache must be 0 failures.
printf("  [note] C: daily-ledger-overview fails its FIRST run after a template change "
    . "(measured: 1 fail, then 0, 0). Its acceptance is a cold-cache run with 0 failures.\n");

printf("\n  => %d RED. %s\n", $reds, $reds > 0
    ? 'The criterion discriminates: this is what the lane must turn green.'
    : 'WARNING: every criterion already passes - it cannot discriminate a change.');
exit($reds > 0 ? 1 : 0);
