<?php
declare(strict_types=1);

/**
 * Acceptance criterion: catalogue-summary and efficiency list controls (viewer surface W2/W7/W8).
 *
 * Source: docs/daily-ledger/VIEWER-SURFACE-ASSESSMENT-PLAN-2026-10-12.md §4 F4/F8/F9, §6 W2/W7/W8,
 * chair rulings §11 (D1/D2 one active apply model per page).
 *
 * RED-now facts (verified 2026-10-12):
 *   F4  Products Overview carries THREE sorting mechanisms: the Rank-by + Order selects, a prose
 *       'Build it from the least instead' link, and a clickable 'Cumulative share' column heading.
 *       Both links emit sort_by=cumulative, which is NOT a cumulative sort (it maps to amount) and
 *       discards a selected Rank-by choice. W2: exactly the two selects; cumulative explained in text.
 *   F8  the Products Overview empty state says '...or include pending rows' - that control does not
 *       exist on this page (the handler forces pending_rows_mode = exclude, handlers.php:13003).
 *   F9  Production Efficiency's Movement select auto-submits (onchange="this.form.submit()") while
 *       every sibling field waits for the form's Apply button - two apply models on one page, and
 *       the immediate submit silently applies any other unapplied change (chair D1/D2 failure mode).
 *
 * Outcome-level: rendered handlers as role=viewer on tenant 207; no template-source greps except
 * where noted.
 *
 * Usage: php tools/acceptance-viewer-products-pe-controls.php
 */

$base = dirname(__DIR__);
require_once $base . '/src/helpers/cli-bootstrap.php';

$_SERVER['HTTP_HOST'] = 'baronledger.test';
$_SERVER['SERVER_NAME'] = 'baronledger.test';

$app = kernelCliBootstrap($base);
$app->tenant()->setTenantId(207);
$app->templates()->enableCompiledMode(false);
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/handlers.php';
modulePushContext('daily-ledger');
require_once $base . '/modules/daily-ledger/helpers/reporting.php';

$reds = 0;
$check = static function (string $id, string $what, bool $ok, string $detail) use (&$reds): void {
    if (!$ok) {
        $reds++;
    }
    printf("  [%s] %-64s %s\n", $ok ? 'PASS' : 'RED ', $what, $ok ? '' : '<-- not satisfied');
    printf("      %s\n", $detail);
};

$tokens = dl_generateAuthTokens([
    'sub' => 'viewer:21', 'id' => 21, 'username' => 'controls-acc',
    'name' => 'Controls Acceptance', 'role' => 'viewer', 'source' => 'daily-ledger',
]);
$_COOKIE[dlCookieName()] = $tokens['token'];

$render = static function (string $uri, string $fn, array $params = []): string {
    $_SERVER['REQUEST_URI'] = $uri;
    $_GET = $params;
    $_REQUEST = $params;
    ob_start();
    try {
        $fn();
    } catch (Throwable $e) {
        echo 'THREW: ' . $e->getMessage();
    }

    return (string)ob_get_clean();
};

$ppUri = '/daily-ledger/admin/products/performance';
$peUri = '/daily-ledger/admin/production-efficiency';

// ── C1 (W2/F4): the catalogue ranking has exactly the Rank-by + Order controls ───────────────────
$pp = $render($ppUri, 'handleAdminProductsPerformance', ['scope' => 'ALL', 'date_from' => '2026-09-28', 'date_to' => '2026-10-11']);
$ppForm = '';
if (preg_match_all('/<form\b.*?<\/form>/is', $pp, $forms)) {
    foreach ($forms[0] as $form) {
        if (str_contains($form, 'name="sort_by"')) {
            $ppForm = $form;
            break;
        }
    }
}
$hasRankBy = $ppForm !== '' && str_contains($ppForm, 'name="sort_by"');
$hasOrder = $ppForm !== '' && str_contains($ppForm, 'name="sort_dir"');
$cumulativeHref = (bool)preg_match('/href="[^"]*sort_by=/i', $pp);
$cumulativeEmitted = str_contains($pp, 'sort_by=cumulative');
$proseLink = str_contains($pp, 'Build it from the');
$c1 = !str_contains($pp, 'THREW:') && $hasRankBy && $hasOrder && !$cumulativeHref && !$cumulativeEmitted && !$proseLink;
$check('C1', 'catalogue ranking: one mechanism (Rank by + Order selects), no cumulative links', $c1,
    sprintf('renders: %s; Rank-by select: %s; Order select: %s; any href emitting sort state: %s; sort_by=cumulative on the page: %s; "Build it from the ..." prose link: %s',
        !str_contains($pp, 'THREW:') ? 'yes' : 'NO: ' . substr($pp, 0, 120),
        $hasRankBy ? 'yes' : 'NO', $hasOrder ? 'yes' : 'NO',
        $cumulativeHref ? 'STILL PRESENT' : 'none',
        $cumulativeEmitted ? 'STILL PRESENT' : 'none',
        $proseLink ? 'STILL PRESENT' : 'none'));

// ── C2 (W2): the cumulative column is still EXPLAINED in text (removal must not over-delete) ────
$explains = (bool)preg_match('/Cumulative share/i', $pp) && (bool)preg_match('/running total/i', $pp);
$check('C2', 'the cumulative column keeps its explanation (text, not a control)', $explains,
    sprintf('column header text present: %s; explainer ("running total ...") present: %s',
        preg_match('/Cumulative share/i', $pp) ? 'yes' : 'NO',
        preg_match('/running total/i', $pp) ? 'yes' : 'NO'));

// ── C3 (W7/F8): the empty state does not name a control that does not exist ──────────────────────
$ppEmpty = $render($ppUri, 'handleAdminProductsPerformance', ['scope' => 'ALL', 'date_from' => '2020-01-01', 'date_to' => '2020-01-02']);
$emptyRenders = str_contains($ppEmpty, 'No finalized product sales');
$c3 = $emptyRenders && !str_contains($ppEmpty, 'include pending rows');
$check('C3', 'empty state: no "include pending rows" (that control is not on this page)', $c3,
    sprintf('empty state renders: %s; stale phrase "include pending rows": %s',
        $emptyRenders ? 'yes' : 'NO', str_contains($ppEmpty, 'include pending rows') ? 'STILL PRESENT' : 'gone'));

// ── C4 (W8/F9): Production Efficiency has ONE apply model ────────────────────────────────────────
$pe = $render($peUri, 'handleAdminProductionEfficiency', ['date_from' => '2026-09-28', 'date_to' => '2026-10-11']);
$peForm = '';
if (preg_match_all('/<form\b.*?<\/form>/is', $pe, $forms)) {
    foreach ($forms[0] as $form) {
        if (str_contains($form, 'name="movement_view"')) {
            $peForm = $form;
            break;
        }
    }
}
$autoSubmit = str_contains($pe, 'this.form.submit(');
$hasApply = $peForm !== '' && (bool)preg_match('/<button[^>]*type="submit"[^>]*>/i', $peForm);
$movementSelect = $peForm !== '' && str_contains($peForm, 'name="movement_view"');
$hasThreeOptions = $peForm !== '' && str_contains($peForm, 'value="production"') && str_contains($peForm, 'value="pullout"') && str_contains($peForm, 'value="all"');
$c4 = !str_contains($pe, 'THREW:') && !$autoSubmit && $hasApply && $movementSelect && $hasThreeOptions;
$check('C4', 'PE: Movement waits for Apply (no auto-submit), Apply button present', $c4,
    sprintf('renders: %s; auto-submit onchange: %s; Apply submit button in the same form: %s; Movement select with all 3 options: %s',
        !str_contains($pe, 'THREW:') ? 'yes' : 'NO: ' . substr($pe, 0, 120),
        $autoSubmit ? 'STILL PRESENT' : 'gone',
        $hasApply ? 'yes' : 'NO',
        ($movementSelect && $hasThreeOptions) ? 'yes' : 'NO'));

// ── C5 (W8 regression): the Movement control still round-trips through the form ──────────────────
$pePullout = $render($peUri, 'handleAdminProductionEfficiency', ['date_from' => '2026-09-28', 'date_to' => '2026-10-11', 'movement_view' => 'pullout']);
$echoOk = (bool)preg_match('/value="pullout"[^>]*\bselected\b/i', $pePullout);
$check('C5', 'PE: movement_view=pullout round-trips (the control still works via submit)', !str_contains($pePullout, 'THREW:') && $echoOk,
    sprintf('renders: %s; pullout option selected: %s', !str_contains($pePullout, 'THREW:') ? 'yes' : 'NO', $echoOk ? 'yes' : 'NO'));

printf("\n  => %d RED. %s\n", $reds, $reds > 0
    ? 'The criterion discriminates: this is the work.'
    : 'WARNING: every criterion passes - it cannot discriminate a change.');
exit($reds > 0 ? 1 : 0);
