<?php
declare(strict_types=1);

/**
 * Acceptance criterion: forecast result count + bounded paging (viewer surface W13 / chair D7).
 *
 * Source: docs/daily-ledger/VIEWER-SURFACE-ASSESSMENT-PLAN-2026-10-12.md §4 F17 (426 KB / 454 rows,
 * no paging, no "showing X of Y") and §11 D7:
 *   "454 default rows in 426 KB is a more serious usability problem than the unfilled sort link."
 *   Add a result count and pagination or an explicit "show more". APPLY FILTERS AND SORT BEFORE
 *   PAGINATION (never paginate first and sort each page); never sort client-side over only the
 *   visible page. Changing page preserves filters + sort.
 *
 * The full ordered list is computed HERE through the public helper (dl_forecastRows keeps its
 * full-list contract; the handler slices) and compared to what the page renders, so these checks
 * discriminate "sorted, then sliced" from "sliced, then sorted" - not merely "a pager exists".
 *
 * Usage: php tools/acceptance-forecast-paging.php
 */

$base = dirname(__DIR__);
require_once $base . '/src/helpers/cli-bootstrap.php';

$_SERVER['HTTP_HOST'] = 'baronledger.test';
$_SERVER['SERVER_NAME'] = 'baronledger.test';
$_SERVER['REQUEST_URI'] = '/daily-ledger/admin/forecast';

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
    'sub' => 'viewer:21', 'id' => 21, 'username' => 'paging-acc',
    'name' => 'Paging Acceptance', 'role' => 'viewer', 'source' => 'daily-ledger',
]);
$_COOKIE[dlCookieName()] = $tokens['token'];

$user = dlCurrentUser(['admin', 'supervisor', 'auditor', 'viewer']);
$db = module()->db();

$inputBase = ['date_from' => '2026-09-28', 'date_to' => '2026-10-11', 'window' => '14', 'target_date' => '2026-10-12'];
$mkFilters = static function (array $input) use ($user): array {
    $f = dl_reportFilters($input, $user);
    $f['empty_rows_mode'] = dl_forecastEmptyRowsMode($input['empty_rows'] ?? null);
    $f['sort_dir'] = dl_overviewNormalizeSortDir($input['sort_dir'] ?? null);

    return $f;
};
$render = static function (array $params): string {
    $_GET = $params;
    $_REQUEST = $params;
    ob_start();
    try {
        handleAdminForecast();
    } catch (Throwable $e) {
        echo 'THREW: ' . $e->getMessage();
    }

    return (string)ob_get_clean();
};
// Rendered rows as branch|product|shift keys, matching the order the table shows them in.
$rowsOf = static function (string $html): array {
    $out = [];
    if (preg_match_all('/<tr\b[^>]*>.*?<\/tr>/is', $html, $trs)) {
        foreach ($trs[0] as $frag) {
            if (preg_match_all('/<strong>(.*?)<\/strong>/s', $frag, $st)
                && count($st[1]) >= 2
                && preg_match('/<td>(AM|PM)<\/td>/', $frag, $sh)) {
                $out[] = trim(html_entity_decode($st[1][0], ENT_QUOTES | ENT_HTML5)) . '|'
                    . trim(html_entity_decode($st[1][1], ENT_QUOTES | ENT_HTML5)) . '|' . $sh[1];
            }
        }
    }

    return $out;
};
$keysOf = static function (array $rows): array {
    return array_map(static fn(array $r): string => $r['branch_name'] . '|' . $r['product_name'] . '|' . $r['shift'], $rows);
};

// The full expected populations, straight through the public helper the page reads.
$fullDesc = dl_forecastRows($db, $mkFilters($inputBase), '2026-10-12', 14);
$keysDesc = $keysOf($fullDesc);
$total = count($keysDesc);

$page1 = $render($inputBase);
$rows1 = $rowsOf($page1);
$n1 = count($rows1);

// ── F1: page one is BOUNDED and the total still exists ───────────────────────────────────────────
$c1 = !str_contains($page1, 'THREW:') && $n1 >= 1 && $n1 <= 200 && $n1 < $total;
$check('F1', 'page one is bounded (<=200 rows) while the full list is larger', $c1,
    sprintf('renders: %s; rows on page one: %d (today the page renders all %d); bound 200',
        !str_contains($page1, 'THREW:') ? 'yes' : 'NO: ' . substr($page1, 0, 120), $n1, $total));

// ── F2: the page STATES the total ("showing X of Y") ─────────────────────────────────────────────
$card = '';
$cs = strpos($page1, 'Suggested Production');
if ($cs !== false) {
    $card = substr($page1, $cs);
}
$ofTotal = false;
$ofMatch = [];
if (preg_match('/\d+\s*(?:[–\-]\s*\d+\s*)?of\s+' . preg_quote((string)$total, '/') . '\b/u', $card, $ofMatch)) {
    $ofTotal = true;
}
$check('F2', sprintf('the page states "X of %d" (total visible, not just the page)', $total), $ofTotal,
    sprintf('found: %s; the number stated must be the FULL list (%d), not the page size (%d)',
        $ofTotal ? 'yes' : 'NO "of ' . $total . '" statement anywhere in the table card - the count currently cannot distinguish page from total',
        $total, $n1));

// ── F3: sort/filter BEFORE slicing - page one IS the head of the full sorted list ────────────────
$c3 = $rows1 !== [] && $rows1 === array_slice($keysDesc, 0, $n1);
$check('F3', 'page one equals the head of the full sorted list (sort, then slice)', $c3,
    sprintf('rows match the first %d of the expected %d-row ordering: %s; first rendered key: %s',
        $n1, $total, $c3 ? 'yes' : 'NO', $rows1[0] ?? '(none)'));

// ── F4: page two continues the SAME ordered list ─────────────────────────────────────────────────
$page2 = $render($inputBase + ['page' => '2']);
$rows2 = $rowsOf($page2);
$expected2Count = min($n1, max(0, $total - $n1));
$c4 = !str_contains($page2, 'THREW:')
    && count($rows2) === $expected2Count
    && $rows2 === array_slice($keysDesc, $n1, count($rows2));
$check('F4', 'page two is the NEXT slice of the same ordered list', $c4,
    sprintf('page two rows: %d (expected %d); equals the following slice: %s',
        count($rows2), $expected2Count, $c4 ? 'yes' : 'NO'));

// ── F5/F6 use the dense asc+include population (788 rows on tenant 207) ─────────────────────────
$includeAscInput = $inputBase + ['empty_rows' => 'include', 'sort_dir' => 'asc'];
$fullIncl = dl_forecastRows($db, $mkFilters($includeAscInput), '2026-10-12', 14);
$keysIncl = $keysOf($fullIncl);
$totalIncl = count($keysIncl);
$pageD = $render($includeAscInput);
$rowsD = $rowsOf($pageD);
$nD = count($rowsD);

// ── F6: that population renders as a bounded head of its own ordering ────────────────────────────
$boundedD = $nD >= 1 && $nD <= 200 && $nD < $totalIncl;
$headD = $rowsD !== [] && $rowsD === array_slice($keysIncl, 0, $nD);
$c6 = !str_contains($pageD, 'THREW:') && $boundedD && $headD;
$check('F6', 'asc + include population: page one is the bounded head of that same ordering', $c6,
    sprintf('rows: %d of %d (bounded and < total: %s); head-of-list match: %s',
        $nD, $totalIncl, $boundedD ? 'yes' : 'NO', $headD ? 'yes' : 'NO'));

// ── F5: a page control exists on a multi-page result, carries the whole state, and FOLLOWING it
//        lands on the next slice (end-to-end - a hand-built link that drops a param cannot pass) ──
$pagerAnchors = [];
if (preg_match_all('/<a\b[^>]*href="([^"]*)"[^>]*>/i', $pageD, $aa)) {
    foreach ($aa[1] as $h) {
        $h = html_entity_decode($h, ENT_QUOTES | ENT_HTML5);
        if (str_contains($h, 'page=')) {
            $pagerAnchors[] = $h;
        }
    }
}
$stateOk = false;
$stateDetail = '';
if ($pagerAnchors === []) {
    $stateDetail = sprintf('no page= anchor on a %d-row result - paging is unreachable', $totalIncl);
} else {
    $carryOk = true;
    foreach ($pagerAnchors as $h) {
        foreach ($includeAscInput as $k => $v) {
            if (!str_contains($h, (string)$k . '=' . (string)$v)) {
                $carryOk = false;
            }
        }
    }
    $nextHref = null;
    $nextParams = null;
    foreach ($pagerAnchors as $h) {
        parse_str((string)parse_url($h, PHP_URL_QUERY), $q);
        if (($q['page'] ?? '') !== '' && (string)$q['page'] !== '1') {
            $nextHref = $h;
            $nextParams = $q;
            break;
        }
    }
    $followOk = false;
    $followDetail = 'no page!=1 anchor found';
    if ($nextParams !== null) {
        $pageD2 = $render($nextParams);
        $rowsD2 = $rowsOf($pageD2);
        $expectedD2 = array_slice($keysIncl, $nD, count($rowsD2));
        $countOk = count($rowsD2) === min($nD, max(0, $totalIncl - $nD));
        $followOk = !str_contains($pageD2, 'THREW:') && $rowsD2 !== [] && $rowsD2 === $expectedD2 && $countOk;
        $followDetail = sprintf('following %s -> %d row(s), next-slice match: %s', $nextHref, count($rowsD2), $followOk ? 'yes' : 'NO');
    }
    // Product facet: if the pager renders in a product-filtered view, it must carry the product too.
    $counts = [];
    foreach ($fullIncl as $r) {
        $pid = (int)$r['product_id'];
        $counts[$pid] = ($counts[$pid] ?? 0) + 1;
    }
    arsort($counts);
    $maxP = (string)(int)array_key_first($counts);
    $pageE = $render($includeAscInput + ['product_id' => $maxP]);
    $prodOk = true;
    $prodSeen = false;
    if (preg_match_all('/<a\b[^>]*href="([^"]*)"[^>]*>/i', $pageE, $ea)) {
        foreach ($ea[1] as $h) {
            $h = html_entity_decode($h, ENT_QUOTES | ENT_HTML5);
            if (str_contains($h, 'page=')) {
                $prodSeen = true;
                if (!str_contains($h, 'product_id=' . $maxP)) {
                    $prodOk = false;
                }
            }
        }
    }
    $stateOk = $carryOk && $followOk && $prodOk;
    $stateDetail = sprintf('pager anchors: %d; every current param present: %s; %s; product filter carried%s: %s',
        count($pagerAnchors), $carryOk ? 'yes' : 'NO', $followDetail,
        $prodSeen ? '' : ' (that view fits one page - not exercised)', $prodOk ? 'yes' : 'NO');
}
$check('F5', 'paging preserves the full state and following a page lands on the right slice', $stateOk, $stateDetail);

// ── F7: an absurd page number cannot break the page ──────────────────────────────────────────────
$page999 = $render($inputBase + ['page' => '999']);
$rows999 = $rowsOf($page999);
$lastSlice = array_slice($keysDesc, max(0, $total - count($rows999)));
$c7 = !str_contains($page999, 'THREW:')
    && ($rows999 === [] || $rows999 === $lastSlice);
$check('F7', 'page=999: renders (empty page or clamped to the last page), never a fatal', $c7,
    sprintf('rows: %d; empty-or-clamped: %s', count($rows999), $c7 ? 'yes' : 'NO'));

// ── F8: a non-numeric page falls back to page one ────────────────────────────────────────────────
$pageAbc = $render($inputBase + ['page' => 'abc']);
$rowsAbc = $rowsOf($pageAbc);
$c8 = !str_contains($pageAbc, 'THREW:') && $rowsAbc === array_slice($keysDesc, 0, count($rowsAbc)) && count($rowsAbc) === $n1;
$check('F8', 'page=abc falls back to page one (deterministic default)', $c8,
    sprintf('rows: %d (page one has %d); is page one: %s', count($rowsAbc), $n1, $c8 ? 'yes' : 'NO'));

printf("\n  => %d RED. %s\n", $reds, $reds > 0
    ? 'The criterion discriminates: this is the work.'
    : 'WARNING: every criterion passes - it cannot discriminate a change.');
exit($reds > 0 ? 1 : 0);
