<?php
declare(strict_types=1);

/**
 * Acceptance criterion: Business Health honesty & wiring (viewer surface W3/W4/W5/W6 + D4b/W15a).
 *
 * Source: docs/daily-ledger/VIEWER-SURFACE-ASSESSMENT-PLAN-2026-10-12.md §4 (F5-F7, F10-F11),
 * §6 (W3-W6, W15), §11 chair rulings (D4, D4b, D5).
 *
 * Calibrated on the real tenant-207 copy, rendered as role=viewer, 2026-10-12:
 *   R0 default (today)        -> coverage 0 recorded / 22 silent; verdict cause = CLOSURE
 *                                ("Bagting is open or has no close status")
 *   R2 (2026-10-11..10-11)    -> cause = PENDING ("103 ledger ending(s) are pending; Rizal has 103")
 *   R4 (branch 8, 10-05..10-11) -> sales finalized (headline renders); forecast target = 2026-10-12
 *   R5 (all, 09-28..10-11)    -> coverage 7 recorded / 15 silent (multi-branch coverage shape)
 *
 * RED-now facts this pins:
 *   F5  'Detailed sales analysis' sits in the header, beside the all-periods lifetime headline,
 *       and opens a 0-row report for today - the label promises nothing about its period.
 *   F6  the verdict-card action 'Inspect {branch}' ALWAYS links to Variances, whatever the cause
 *       (R0's cause is a closure; R2's is pending endings).
 *   F7  operator instructions leak onto a read-only surface: 'Resolve what blocks the verdict',
 *       'Finalize pending endings, inspect variance evidence...' (also 'Complete pending endings'
 *       as the opportunity title, handler-side).
 *   F10 'Detailed forecast' carries NO scope/branch/target context.
 *   F11 the verdict claims health ('Healthy'/'Needs attention') for what is ledger-control state.
 *   D4b the sales headline names no branch coverage; the verdict never cites coverage.
 *
 * Outcome-level: every check reads the RENDERED handler as a viewer, not the template source.
 *
 * Usage: php tools/acceptance-viewer-overview-honesty.php
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
    'sub' => 'viewer:21', 'id' => 21, 'username' => 'overview-acc',
    'name' => 'Overview Acceptance', 'role' => 'viewer', 'source' => 'daily-ledger',
]);
$_COOKIE[dlCookieName()] = $tokens['token'];

$render = static function (array $params = []): string {
    $_GET = $params;
    $_REQUEST = $params;
    ob_start();
    try {
        handleAdminOverview();
    } catch (Throwable $e) {
        echo 'THREW: ' . $e->getMessage();
    }

    return (string)ob_get_clean();
};
$slice = static function (string $html, string $from, string $to): string {
    $s = strpos($html, $from);
    if ($s === false) {
        return '';
    }
    $e = strpos($html, $to, $s + strlen($from));

    return $e === false ? substr($html, $s) : substr($html, $s, $e - $s);
};
$anchorsOf = static function (string $html): array {
    $out = [];
    if (preg_match_all('/<a\b[^>]*href="([^"]*)"[^>]*>(.*?)<\/a>/is', $html, $m, PREG_SET_ORDER)) {
        foreach ($m as $a) {
            $out[] = ['href' => html_entity_decode($a[1], ENT_QUOTES | ENT_HTML5), 'text' => trim(preg_replace('/\s+/', ' ', strip_tags($a[2])))];
        }
    }

    return $out;
};
$periodInHref = static function (string $href, string $from, string $to): bool {
    parse_str((string)parse_url($href, PHP_URL_QUERY), $q);

    return ($q['date_from'] ?? '') === $from && ($q['date_to'] ?? '') === $to;
};

$r0 = $render();
$r2 = $render(['date_from' => '2026-10-11', 'date_to' => '2026-10-11']);
$r4 = $render(['date_from' => '2026-10-05', 'date_to' => '2026-10-11', 'branch_id' => '8']);
$r5 = $render(['date_from' => '2026-09-28', 'date_to' => '2026-10-11']);

foreach (['R0' => $r0, 'R2' => $r2, 'R4' => $r4, 'R5' => $r5] as $n => $h) {
    if (str_contains($h, 'THREW:') || $h === '') {
        $check($n . '-render', 'render completes without a fatal', false, 'THREW or empty: ' . substr($h, 0, 140));
    }
}

// ── H1 (W5/D4): the verdict section is renamed to Ledger Control Status ─────────────────────────
$marker2 = '';
if (preg_match('/>2 · ([^<]+)</u', $r0, $m)) {
    $marker2 = trim($m[1]);
}
$h1 = $marker2 !== ''
    && preg_match('/ledger control status/i', $marker2)
    && !preg_match('/healthy/i', $marker2);
$check('H1', 'section label reads "Ledger Control Status", not a health claim', $h1,
    sprintf('rendered marker: "%s"', $marker2 !== '' ? $marker2 : 'NOT FOUND (expected the "2 · ..." section label)'));

// ── H2 (D4b + W15): the verdict uses ledger-control vocabulary and cites coverage ───────────────
$vet0 = $slice($r0, '>2 · ', '>3 · ');
$vet2 = $slice($r2, '>2 · ', '>3 · ');
$h2detail = [];
$h2ok = true;
foreach (['R0' => $vet0, 'R2' => $vet2] as $n => $vet) {
    $noOldVocab = !preg_match('/\bHealthy\b/i', $vet) && !preg_match('/Needs attention/i', $vet);
    $hasNewVocab = (bool)preg_match('/\b(Complete|Attention required|Incomplete|Unknown)\b/i', $vet);
    $citesCoverage = (bool)preg_match('/(\d+\s+of\s+\d+\s+branch|coverage)/i', $vet);
    if (!$noOldVocab || !$hasNewVocab || !$citesCoverage) {
        $h2ok = false;
    }
    $h2detail[] = sprintf('%s: old vocab gone=%s; one of Complete/Attention required/Incomplete/Unknown=%s; coverage cited=%s',
        $n, $noOldVocab ? 'yes' : 'NO', $hasNewVocab ? 'yes' : 'NO', $citesCoverage ? 'yes' : 'NO');
}
$check('H2', 'verdict vocabulary replaced AND coverage cited beside it', $h2ok, implode(' | ', $h2detail));

// ── H3 (W3/F6): the verdict action routes to the named cause, labelled View branch details ──────
$hrefsIn = static function (string $html): array {
    $out = [];
    if (preg_match_all('/href="([^"]*)"/i', $html, $m)) {
        foreach ($m[1] as $h) {
            $out[] = html_entity_decode($h, ENT_QUOTES | ENT_HTML5);
        }
    }

    return $out;
};
$vet0Hrefs = $hrefsIn($vet0);
$vet2Hrefs = $hrefsIn($vet2);
$r0Routed = false;
foreach ($vet0Hrefs as $h) {
    if (str_contains($h, 'branch-summary')) {
        $r0Routed = true;
    }
}
$r2Routed = false;
foreach ($vet2Hrefs as $h) {
    if (str_contains($h, 'data-integrity')) {
        $r2Routed = true;
    }
}
$noVariancesInVerdict = true;
foreach (array_merge($vet0Hrefs, $vet2Hrefs) as $h) {
    if (str_contains($h, '/reports/variances') || (str_contains($h, 'variances') && str_contains($h, 'reports'))) {
        $noVariancesInVerdict = false;
    }
}
$labelsOk = str_contains($vet0, 'View branch details') && str_contains($vet2, 'View branch details')
    && !preg_match('/\bInspect\b/', $vet0) && !preg_match('/\bInspect\b/', $vet2);
$check('H3', 'verdict action: closure -> branch-summary, pending -> data-integrity, labelled "View branch details"',
    $r0Routed && $r2Routed && $noVariancesInVerdict && $labelsOk,
    sprintf('R0 (closure cause) links branch-summary: %s; R2 (pending cause) links data-integrity: %s; no stray Variances href in either verdict card: %s; label renamed: %s',
        $r0Routed ? 'yes' : 'NO', $r2Routed ? 'yes' : 'NO', $noVariancesInVerdict ? 'yes' : 'NO', $labelsOk ? 'yes' : 'NO'));

// ── H4 (W4/D5): section 5 states the condition, it does not instruct the operator ───────────────
$sec5 = $slice($r0, '5 · ', 'Aggregate business forecast');
$h4 = $sec5 !== ''
    && !str_contains($sec5, 'Resolve what blocks')
    && !preg_match('/\bFinalize\b/', $sec5)
    && !preg_match('/Complete pending/i', $sec5)
    && str_contains($sec5, 'Issues affecting ledger completion');
$check('H4', 'section 5: "Issues affecting ledger completion", no operator instructions', $h4,
    sprintf('old title gone=%s; no "Finalize" verb=%s; no "Complete pending"=%s; new descriptive title present=%s',
        !str_contains($sec5, 'Resolve what blocks') ? 'yes' : 'NO',
        !preg_match('/\bFinalize\b/', $sec5) ? 'yes' : 'NO',
        !preg_match('/Complete pending/i', $sec5) ? 'yes' : 'NO',
        str_contains($sec5, 'Issues affecting ledger completion') ? 'yes' : 'NO'));

// ── H5 (W3/F5): the sales-report entry point states its period and left the lifetime header ─────
$headerBlock = $slice($r0, 'class="flex items-center justify-between mb-6"', 'id="dl-all-periods-summary"');
$headerHasSalesLink = (bool)preg_match('#/admin/reports/sales#', $headerBlock);
$salesAnchors = [];
foreach ($anchorsOf($r0) as $a) {
    if (str_contains($a['text'], 'View sales report')) {
        $salesAnchors[] = $a;
    }
}
$salesWithPeriod = false;
foreach ($salesAnchors as $a) {
    if (str_contains($a['href'], '/admin/reports/sales') && $periodInHref($a['href'], '2026-10-12', '2026-10-12')) {
        $salesWithPeriod = true;
    }
}
$h5 = !str_contains($r0, 'Detailed sales analysis') && !$headerHasSalesLink && $salesWithPeriod;
$check('H5', 'sales-report control renamed + period-matched, no longer beside the lifetime headline', $h5,
    sprintf('old label gone=%s; header no longer links to the sales report=%s; a "View sales report" control carries the page period (2026-10-12)=%s',
        !str_contains($r0, 'Detailed sales analysis') ? 'yes' : 'NO',
        !$headerHasSalesLink ? 'yes' : 'NO',
        $salesWithPeriod ? 'yes' : 'NO'));

// ── H6 (W6/F10): the forecast drill-down carries scope, branch and the derived target date ──────
$target = '';
if (preg_match('/target (\d{4}-\d{2}-\d{2})/', $r4, $m)) {
    $target = $m[1];
}
$forecastAnchors = [];
foreach ($anchorsOf($r4) as $a) {
    if (str_contains($a['text'], 'View production forecast')) {
        $forecastAnchors[] = $a;
    }
}
$forecastCtx = false;
foreach ($forecastAnchors as $a) {
    parse_str((string)parse_url($a['href'], PHP_URL_QUERY), $q);
    if (str_contains($a['href'], '/admin/forecast')
        && ($q['branch_id'] ?? '') === '8'
        && ($q['target_date'] ?? '') === $target
        && array_key_exists('scope', $q)) {
        $forecastCtx = true;
    }
}
$h6 = $forecastCtx && !str_contains($r4, 'Detailed forecast');
$check('H6', 'forecast link renamed + carries scope, branch_id=8 and target_date=' . ($target ?: '?'), $h6,
    sprintf('context-carrying "View production forecast" anchor found=%s (needs scope, branch_id=8, target_date=%s - the same target the page\'s own aggregate forecast shows); old "Detailed forecast" label gone=%s',
        $forecastCtx ? 'yes' : 'NO', $target !== '' ? $target : '?', !str_contains($r4, 'Detailed forecast') ? 'yes' : 'NO'));

// ── H7 (W15a/D4b): the aggregate names how many branches recorded ───────────────────────────────
$grossWindow = '';
$gs = strpos($r5, '>Gross sales<');
if ($gs !== false) {
    $grossWindow = substr($r5, $gs, 1600);
}
$branchWindow = '';
$bs = strpos($r5, '>Branches in scope<');
if ($bs !== false) {
    $branchWindow = substr($r5, $bs, 700);
}
$h7 = (bool)preg_match('/\d+\s+of\s+\d+\s+branch/i', $grossWindow . ' ' . $branchWindow);
$check('H7', 'multi-branch aggregate states "N of M branches" (coverage beside the numbers)', $h7,
    sprintf('coverage sentence within the gross-sales card or the branches card: %s (rendered coverage on this state: 7 recorded / 15 silent)',
        $h7 ? 'present' : 'ABSENT - the aggregate still reads as if every branch contributed'));

// ── H8 (D5): the opportunity title describes the condition, it does not instruct ────────────────
$sec4 = $slice($r2, '4 · ', '5 · ');
$h8 = $sec4 !== ''
    && !preg_match('/Complete pending/i', $sec4)
    && (bool)preg_match('/(awaiting|yet to be|remaining|outstanding|unfinalized)/i', $sec4);
$check('H8', 'opportunity title describes the pending condition instead of instructing', $h8,
    sprintf('no "Complete pending endings": %s; descriptive wording present (awaiting/remaining/...): %s',
        !preg_match('/Complete pending/i', $sec4) ? 'yes' : 'NO',
        preg_match('/(awaiting|yet to be|remaining|outstanding|unfinalized)/i', $sec4) ? 'yes' : 'NO'));

printf("\n  => %d RED. %s\n", $reds, $reds > 0
    ? 'The criterion discriminates: this is the work.'
    : 'WARNING: every criterion passes - it cannot discriminate a change.');
exit($reds > 0 ? 1 : 0);
