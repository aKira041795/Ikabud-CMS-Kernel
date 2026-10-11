<?php
declare(strict_types=1);

/**
 * Acceptance criterion: the Health view's PERIOD and COMPARISON.
 *
 * Owner's review comment, verbatim (2026-10-11):
 *   "period must be a date range, if we need to compare, the visually show how."
 *
 * Grounded in what was actually built:
 *   - the period is ONE free-text input: <input name="period" value="{period_value}"
 *     placeholder="YYYY-MM-DD or YYYY-MM-DD to YYYY-MM-DD"> - the owner must TYPE a range in a
 *     specific format rather than pick two dates.
 *   - the comparison is the card title "Trend vs previous period" plus a text value. It says THAT it
 *     compared, but nothing shows WHAT it compared against or by how much.
 *
 * Chair-written and measured against the unchanged tree BEFORE dispatch. Must be RED, or it cannot
 * discriminate. Tracked in tools/ (not .ai/, which is gitignored and therefore unprovable).
 *
 * Usage: php tools/acceptance-health-period-comparison.php
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
    'sub' => 'viewer:21', 'id' => 21, 'username' => 'period-acceptance',
    'name' => 'Period Acceptance', 'role' => 'viewer', 'source' => 'daily-ledger',
]);
$_COOKIE[dlCookieName()] = $tokens['token'];

$reds = 0;
$check = static function (string $id, string $what, bool $ok, string $detail) use (&$reds): void {
    if (!$ok) {
        $reds++;
    }
    printf("  [%s] %-58s %s\n", $ok ? 'PASS' : 'RED ', $what, $ok ? '' : '<-- not satisfied');
    printf("      %s\n", $detail);
};

$_GET = ['scope' => 'ALL'];
$_REQUEST = $_GET;
ob_start();
handleAdminOverview();
$html = (string)ob_get_clean();

$formEnd = strpos($html, '</form>');
$form = $formEnd === false ? '' : substr($html, 0, $formEnd);
$analytics = $formEnd === false ? $html : substr($html, $formEnd + 7);
$analyticsText = (string)preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($analytics), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

// --- P1: the period is a RANGE of two date fields, not typed free text --------------------------
preg_match_all('/<input[^>]*type="date"[^>]*>/i', $form, $dateFields);
$hasFrom = (bool)preg_match('/<input[^>]*name="date_from"[^>]*>/i', $form);
$hasTo = (bool)preg_match('/<input[^>]*name="date_to"[^>]*>/i', $form);
$typedRange = (bool)preg_match('/name="period"[^>]*placeholder="[^"]*to[^"]*"/i', $form);
$check('P1', 'period is two date fields (from + to), not a typed range', count($dateFields[0] ?? []) >= 2 && $hasFrom && $hasTo,
    sprintf('type="date" fields=%d date_from=%s date_to=%s | still asks the user to TYPE a range: %s',
        count($dateFields[0] ?? []), $hasFrom ? 'yes' : 'NO', $hasTo ? 'yes' : 'NO', $typedRange ? 'YES' : 'no'));

// --- P2: the comparison shows WHAT it compares against, when there IS a comparison ---------------
// This check was stale. It rendered the DEFAULT period, which is today, and today is all-pending -
// so there is no honest comparison and the page correctly shows no baseline. That is the behaviour
// the honesty repair introduced (Sol's S1), not a regression. It now renders a period that IS
// comparable, using the same fixture the repair's own test adopted: fully finalized in both windows.
$comparableHtml = '';
if (preg_match('#/daily-ledger/admin/overview#', (string)($_SERVER['REQUEST_URI'] ?? ''))) {
    // re-render with an explicit comparable range
    $_GET = ['scope' => 'ALL', 'period' => '2026-09-15 to 2026-09-28'];
    $_REQUEST = $_GET;
    ob_start();
    handleAdminOverview();
    $comparableHtml = (string)ob_get_clean();
}
$comparableText = (string)preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($comparableHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
$namesBaseline = (bool)preg_match('/\b(previous period|prior period|preceding|vs\.? previous|compared (to|with) (the )?previous|compared with)\b/i', $comparableText);
$showsBaselineDates = (bool)preg_match('/\b\d{4}-\d{2}-\d{2}\b[^.]{0,40}\b\d{4}-\d{2}-\d{2}\b/', $comparableText);
$check('P2', 'a comparable period names its baseline and shows its dates', $namesBaseline && $showsBaselineDates,
    sprintf('names a baseline=%s shows baseline dates=%s (rendered 2026-09-15 to 2026-09-28, finalized in both windows)',
        $namesBaseline ? 'yes' : 'NO', $showsBaselineDates ? 'yes' : 'NO'));

// --- P3: the comparison is shown VISUALLY, when one is honestly available -----------------------
// "visually show how" - a signed delta or a directional indicator. Measured on the comparable
// fixture, so an absent delta on an uncomparable default period is not counted against it.
$hasDeltaValue = (bool)preg_match('/(\+|-|▲|▼|↑|↓)\s*[\d.,]+%|[\d.,]+\s*%\s*(up|down|higher|lower|increase|decrease)/i', $comparableText);
$hasVisualCue = (bool)preg_match('/class="[^"]*(delta|trend-up|trend-down|arrow|indicator|spark|bar)[^"]*"/i', $comparableHtml);
$check('P3', 'a comparable period shows a signed delta and/or a visual indicator', $hasDeltaValue && $hasVisualCue,
    sprintf('signed/percentage delta in text=%s visual delta element=%s (on the comparable fixture)',
        $hasDeltaValue ? 'yes' : 'NO', $hasVisualCue ? 'yes' : 'NO'));

// --- P6: an honest non-comparable period states WHY, instead of a fake delta --------------------
// The counterpart to P2/P3, and the reason they were wrong: today is all-pending, so the page must
// say so rather than compute a percentage from an unfinished baseline.
$statesReason = (bool)preg_match('/not yet comparable|no sales recorded|pending finalization|no baseline/i', $analyticsText);
$noFakeDelta = !preg_match('/(\+|-)\s*[\d.,]+%/', $analyticsText);
$check('P6', 'an uncomparable period states why, without a fabricated delta', $statesReason && $noFakeDelta,
    sprintf('states the reason=%s, and shows no signed percentage=%s', $statesReason ? 'yes' : 'NO', $noFakeDelta ? 'yes' : 'NO'));

// --- P4: regression guard - three controls plus the two dates stay "not complicated" ------------
preg_match_all('/<(select|input|button)\b/i', $form, $m);
$controls = count($m[1] ?? []);
$check('P4', 'primary bar stays small (Area + Branch + 2 dates + Apply)', $controls <= 6,
    sprintf('%d form control element(s) in the bar; a range adds one field over the current single typed period, so 6 is the ceiling that keeps "not complicated" true', $controls));

// --- P5: regression guard - the Health view still answers its five questions ---------------------
$check('P5', 'health verdict and opportunity survive the period change', (bool)preg_match('/\b(healthy|attention|at risk|on track|declining|stable|improving)\b/i', $analyticsText)
        && (bool)preg_match('/\b(opportunit|maximi|improve|headroom|underperform|behind|attention)\w*/i', $analyticsText),
    'verdict and opportunity language both present in analytics');

printf("\n  => %d RED. %s\n", $reds, $reds > 0
    ? 'The criterion discriminates: this is the work.'
    : 'WARNING: every criterion passes - it cannot discriminate a change.');
exit($reds > 0 ? 1 : 0);
