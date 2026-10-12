<?php
declare(strict_types=1);

/**
 * Acceptance criterion: branch drill-downs are visibly clickable, and the silent-branch list is
 * collapsed behind a count (viewer surface W10 / findings F13+F14, chair-standard DoD #1).
 *
 * Source: docs/daily-ledger/VIEWER-SURFACE-ASSESSMENT-PLAN-2026-10-12.md §4 F13/F14, §6 W10.
 *   F13 (VERIFIED): branch-name links render as plain indigo text (`text-sm font-semibold
 *        text-indigo-700`) - a weak affordance for the page's main drill-down.
 *   F14 (VERIFIED): on the default view "0 recorded activity · 22 silent" - the no-activity list
 *        can dominate the page and carries no information for most rows.
 *   W10: make drill-downs visibly clickable; collapse the silent-branch list behind a count.
 *
 * Rendering as role=viewer, tenant 207:
 *   R0 default (today)            -> 0 recorded / 22 silent
 *   R5 all, 2026-09-28..10-11     -> 7 recorded / 15 silent
 * Strings pinned by tests/daily-ledger/business_health_view_test.php must survive the collapse:
 * 'Branches with no recorded activity', 'not ranked', 'No ledger rows; no performance conclusion
 * is made.'
 *
 * Usage: php tools/acceptance-viewer-branch-affordance.php
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
    'sub' => 'viewer:21', 'id' => 21, 'username' => 'afford-acc',
    'name' => 'Affordance Acceptance', 'role' => 'viewer', 'source' => 'daily-ledger',
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
// Branch drill-down anchors: links back to this page scoped by branch_id.
$branchAnchorsOf = static function (string $html): array {
    $out = [];
    if (preg_match_all('/<a\b([^>]*)>(.*?)<\/a>/is', $html, $m, PREG_SET_ORDER)) {
        foreach ($m as $a) {
            if (preg_match('/href="([^"]*)"/i', $a[1], $hm)) {
                $href = html_entity_decode($hm[1], ENT_QUOTES | ENT_HTML5);
                if (str_contains($href, '/admin/overview') && preg_match('/branch_id=\d+/', $href)) {
                    preg_match('/class="([^"]*)"/i', $a[1], $cm);
                    $out[] = ['href' => $href, 'class' => $cm[1] ?? '', 'pos' => strpos($html, $a[0])];
                }
            }
        }
    }

    return $out;
};
$silentCountOf = static function (string $html): int {
    return preg_match('/(\d+) silent/', $html, $m) ? (int)$m[1] : -1;
};
$regionOf = static function (string $html): string {
    $s = strpos($html, 'Branches with no recorded activity');
    if ($s === false) {
        return '';
    }
    $e = strpos($html, '5 · How?', $s);

    return $e === false ? substr($html, $s) : substr($html, $s, $e - $s);
};

$r0 = $render();
$r5 = $render(['date_from' => '2026-09-28', 'date_to' => '2026-10-11']);
foreach (['R0' => $r0, 'R5' => $r5] as $n => $h) {
    if (str_contains($h, 'THREW:') || $h === '') {
        $check($n . '-render', 'render completes without a fatal', false, 'THREW or empty');
    }
}

// ── B1 (F13): every branch drill-down carries a visible clickable affordance ─────────────────────
$b1ok = true;
$b1detail = [];
foreach (['R0' => $r0, 'R5' => $r5] as $n => $h) {
    $anchors = $branchAnchorsOf($h);
    $plain = 0;
    foreach ($anchors as $a) {
        if (!preg_match('/(underline|btn-|hover:)/i', $a['class'])) {
            $plain++;
        }
    }
    if ($anchors === [] || $plain > 0) {
        $b1ok = false;
    }
    $b1detail[] = sprintf('%s: %d branch link(s), %d without an affordance signal (needs underline/btn-/hover:)', $n, count($anchors), $plain);
}
$check('B1', 'branch drill-downs are visibly clickable (not plain coloured text)', $b1ok, implode(' | ', $b1detail));

// ── B2 (F14): the silent-branch list is collapsed behind a count ─────────────────────────────────
$b2ok = true;
$b2detail = [];
foreach (['R0' => $r0, 'R5' => $r5] as $n => $h) {
    $region = $regionOf($h);
    $silent = $silentCountOf($h);
    $detailsOpen = preg_match('/<details\b([^>]*)>/i', $region, $dm) ? $dm[0] : '';
    $closed = $detailsOpen !== '' && !preg_match('/\sopen[\s>]/i', $detailsOpen);
    $summaryOk = false;
    if (preg_match('/<summary\b[^>]*>(.*?)<\/summary>/is', $region, $sm)) {
        $summaryText = trim(preg_replace('/\s+/', ' ', strip_tags($sm[1])));
        $summaryOk = $silent > 0
            && str_contains($summaryText, (string)$silent)
            && (bool)preg_match('/no recorded activity|no rows|silent/i', $summaryText);
    }
    // All silent drill-downs must remain reachable, but only INSIDE the collapse.
    $anchors = $branchAnchorsOf($region);
    $inside = 0;
    $outside = 0;
    $detailsPos = $detailsOpen !== '' ? strpos($region, $detailsOpen) : -1;
    foreach ($anchors as $a) {
        if ($detailsPos !== -1 && $a['pos'] > $detailsPos) {
            $inside++;
        } else {
            $outside++;
        }
    }
    $ok = $closed && $summaryOk && $inside >= $silent && $silent > 0 && $outside === 0;
    if (!$ok) {
        $b2ok = false;
    }
    $b2detail[] = sprintf('%s: silent=%d; closed <details>=%s; summary states the count=%s; branch links inside=%d, exposed outside=%d (must be 0)',
        $n, $silent, $closed ? 'yes' : 'NO', $summaryOk ? 'yes' : 'NO', $inside, $outside);
}
$check('B2', 'silent list collapsed behind a count (closed <details>, all links inside)', $b2ok, implode(' | ', $b2detail));

// ── B3 (regression): the drill-downs still carry their viewing context ───────────────────────────
$b3ok = true;
$b3detail = [];
foreach (['R0' => $r0, 'R5' => $r5] as $n => $h) {
    $anchors = $branchAnchorsOf($h);
    $noCtx = 0;
    foreach ($anchors as $a) {
        if (!str_contains($a['href'], 'date_from=') || !str_contains($a['href'], 'date_to=')) {
            $noCtx++;
        }
    }
    if ($anchors === [] || $noCtx > 0) {
        $b3ok = false;
    }
    $b3detail[] = sprintf('%s: %d drill-down(s), %d missing the period params', $n, count($anchors), $noCtx);
}
$check('B3', 'drill-downs keep the period context (date_from/date_to in every href)', $b3ok, implode(' | ', $b3detail));

printf("\n  => %d RED. %s\n", $reds, $reds > 0
    ? 'The criterion discriminates: this is the work.'
    : 'WARNING: every criterion passes - it cannot discriminate a change.');
exit($reds > 0 ? 1 : 0);
