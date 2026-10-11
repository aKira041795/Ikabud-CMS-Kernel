<?php
declare(strict_types=1);

/**
 * Acceptance criterion: the Business HEALTH view, built for the business owner's five questions.
 *
 *   "how is the business doing. is it healthy, why? where can i maximize operations? how?"
 *
 * Written and MEASURED RED by the chair BEFORE dispatch. It must be RED on the unchanged tree, or it
 * cannot discriminate the change. Tracked in tools/ (NOT .ai/, which is gitignored - an untracked
 * criterion cannot be proven unmodified, and that was a review finding on 2026-10-11).
 *
 * Read-only: renders and parses. Writes no business data.
 *
 * Usage: php tools/acceptance-business-health-view.php
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

$tokens = dl_generateAuthTokens([
    'sub' => 'viewer:21', 'id' => 21, 'username' => 'health-acceptance',
    'name' => 'Health Acceptance', 'role' => 'viewer', 'source' => 'daily-ledger',
]);
$_COOKIE[dlCookieName()] = $tokens['token'];

$reds = 0;
$check = static function (string $id, string $what, bool $ok, string $detail) use (&$reds): void {
    if (!$ok) {
        $reds++;
    }
    printf("  [%s] %-62s %s\n", $ok ? 'PASS' : 'RED ', $what, $ok ? '' : '<-- not satisfied');
    printf("      %s\n", $detail);
};

$_GET = ['scope' => 'ALL'];
$_REQUEST = $_GET;
ob_start();
handleAdminOverview();
$html = (string)ob_get_clean();
$text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
$text = (string)preg_replace('/\s+/', ' ', $text);

// The analytics region excludes the filter form, so a dropdown-only change cannot satisfy anything.
$formEnd = strpos($html, '</form>');
$analytics = $formEnd === false ? $html : substr($html, $formEnd + 7);
$analyticsText = (string)preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($analytics), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

// --- A1: an explicit verdict, not just numbers --------------------------------------------------
// A verdict names a state AND is supported by a signal the system actually computes.
$hasVerdict = (bool)preg_match('/\b(healthy|attention|at risk|needs attention|on track|declining|stable|improving)\b/i', $analyticsText);
$namesSignal = (bool)preg_match('/\b(variance|pending|not yet finalized|unfinalized|vs (last|prior|previous)|compared with)\b/i', $analyticsText);
$check('A1', 'page states a health VERDICT supported by a named signal', $hasVerdict && $namesSignal,
    sprintf('verdict=%s signal=%s | first 240 chars of analytics: %s', $hasVerdict ? 'yes' : 'NO', $namesSignal ? 'yes' : 'NO', substr($analyticsText, 0, 240)));

// --- A2: attribution - WHY, with a location -----------------------------------------------------
$branchNames = array_map('trim', array_column(
    module()->db()->query('SELECT name FROM dl_branches WHERE is_active = 1')->fetchAll(PDO::FETCH_ASSOC) ?: [], 'name'
));
$namedInAnalytics = [];
foreach ($branchNames as $bn) {
    if ($bn !== '' && mb_stripos($analyticsText, $bn) !== false) {
        $namedInAnalytics[] = $bn;
    }
}
$check('A2', 'verdict is attributed to a named area or branch', $hasVerdict && $namedInAnalytics !== [],
    sprintf('%d branch name(s) appear in analytics: %s', count($namedInAnalytics), implode(', ', array_slice($namedInAnalytics, 0, 6))));

// --- A3: an opportunity, ranked, with a metric --------------------------------------------------
$hasOpportunity = (bool)preg_match('/\b(opportunit|maximi|improve|headroom|underperform|behind|attention)\w*/i', $analyticsText);
$check('A3', 'names where to improve, with a metric to judge it on', $hasOpportunity,
    sprintf('opportunity language present=%s', $hasOpportunity ? 'yes' : 'NO'));

// --- A4: actionable - it links somewhere --------------------------------------------------------
$linksToDetail = (bool)preg_match('/(href|data-branch-id|onclick)[^>]{0,120}(branch|variance|reports)/i', $analytics);
$check('A4', 'the opportunity is navigable to its detail', $linksToDetail,
    sprintf('detail link/affordance in analytics=%s', $linksToDetail ? 'yes' : 'NO'));

// A7: not complicated - the primary bar holds the scope controls and the date range.
// Re-anchored 2026-10-11: the owner asked for a date RANGE, so the old single period
// input becomes From + To. The pre-range ceiling of 4 (a836fc2d, before the owner's
// comment 0cd79ce9) is the stale side; the ceiling moves with the added picker while
// still refusing any other control.
// Counted from the filter form only, because relocated controls must still exist elsewhere.
$formHtml = $formEnd === false ? '' : substr($html, 0, $formEnd);
preg_match_all('/<(select|input|button)\b/i', $formHtml, $m);
$controlCount = count($m[1] ?? []);
// The form always contains a submit; allow Area + Branch + From + To + submit = 5 elements.
$check('A7', 'primary filter bar holds only Area, Branch, the date range (+submit)', $controlCount <= 5,
    sprintf('%d form control element(s) found; the owner\'s own URL carried 11 parameters (forecast_window, forecast_period, sort_by, sort_dir, chart_limit, pending_rows x2 on top of scope/date/branch)', $controlCount));

// --- A8: product detail belongs to the Products view, not here ----------------------------------
$productBlocks = 0;
foreach (['Top Products', 'Top products', 'Pareto', 'per-branch', 'Top Saleable'] as $needle) {
    if (str_contains($analyticsText, $needle)) {
        $productBlocks++;
    }
}
$check('A8', 'product-level blocks are NOT on the health view', $productBlocks === 0,
    sprintf('%d product-level section(s) still on this page (they belong to the separate Products view)', $productBlocks));

// --- A5: regression guard - completeness must stay distinct from no-activity --------------------
$db = module()->db();
$today = (string)$db->query('SELECT CURDATE()')->fetchColumn();
$stmt = $db->prepare('SELECT COUNT(*) r, SUM(CASE WHEN bal_end IS NULL THEN 1 ELSE 0 END) p FROM dl_daily_ledger WHERE ledger_date = :d');
$stmt->execute([':d' => $today]);
$row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
$pendingToday = (int)($row['p'] ?? 0);
$claimsNoSales = str_contains($text, 'No sales recorded for this period');
$check('A5', 'a day with pending activity is not reported as "no sales"', $pendingToday === 0 || !$claimsNoSales,
    sprintf('today=%s pending=%d rows=%d | still claims no sales=%s', $today, $pendingToday, (int)($row['r'] ?? 0), $claimsNoSales ? 'YES' : 'no'));

printf("\n  => %d RED. %s\n", $reds, $reds > 0
    ? 'The criterion discriminates: this is the work.'
    : 'WARNING: every criterion passes - it cannot discriminate a change.');
exit($reds > 0 ? 1 : 0);
