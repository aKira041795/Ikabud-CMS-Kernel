<?php
declare(strict_types=1);

/**
 * Acceptance criterion: the forecast's sort control - ONE mechanism, inside the form, carrying the
 * whole filter state (viewer surface assessment W1/W14; chair rulings D1/D2, 2026-10-12).
 *
 * RED-now facts (verified 2026-10-12, doc docs/daily-ledger/VIEWER-SURFACE-ASSESSMENT-PLAN-2026-10-12.md):
 *   F1 the two sort <a> links omit product_id -> clicking a direction CLEARS a selected product filter
 *   F2 the filter form has no sort_dir control -> pressing the form's submit silently resets the sort
 *   F3 the inactive direction renders as `btn btn-sm` with NO background -> it looks disabled
 *
 * Ruling (doc §11 D1/D2): ONE sort_dir control INSIDE the existing filter form; DELETE the two
 * hand-written links; ONE apply model per page (no control that submits while another has an
 * unapplied change); the apply button is labelled "Apply filters"; the server keeps an allowlisted
 * deterministic default (asc|desc, else desc). Stability: equal quantities already tie-break on
 * branch/product/shift (reporting.php dl_forecastSortByQty) - claimed below as a regression, not as
 * this lane's work.
 *
 * This asserts OUTCOMES against the RENDERED handler (the control the page actually ships), not the
 * template source. The ruled mechanism (select inside the form) IS encoded deliberately - the design
 * was settled by the ruling, not left open.
 *
 * Usage: php tools/acceptance-forecast-sort-control.php
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

// Render as the role the owner reviews with: a viewer (business owner, read-only).
$tokens = dl_generateAuthTokens([
    'sub' => 'viewer:21', 'id' => 21, 'username' => 'forecast-acc',
    'name' => 'Forecast Acceptance', 'role' => 'viewer', 'source' => 'daily-ledger',
]);
$_COOKIE[dlCookieName()] = $tokens['token'];

$render = static function (array $extra = []): string {
    $_GET = array_merge([
        'date_from' => '2026-09-28', 'date_to' => '2026-10-11',
        'window' => '14', 'target_date' => '2026-10-12',
    ], $extra);
    $_REQUEST = $_GET;
    ob_start();
    try {
        handleAdminForecast();
    } catch (Throwable $e) {
        echo 'THREW: ' . $e->getMessage();
    }

    return (string)ob_get_clean();
};

// The filter form is the one that carries target_date. Everything below is scoped to it, so a
// control that exists somewhere ELSE on the page cannot satisfy these checks by accident.
$filterFormOf = static function (string $html): string {
    if (!preg_match_all('/<form\b.*?<\/form>/is', $html, $forms)) {
        return '';
    }
    foreach ($forms[0] as $form) {
        if (str_contains($form, 'name="target_date"')) {
            return $form;
        }
    }

    return '';
};
$selectBlockOf = static function (string $html, string $name): string {
    return preg_match('/<select[^>]*name="' . preg_quote($name, '/') . '".*?<\/select>/is', $html, $m) ? $m[0] : '';
};
$rowFragments = static function (string $html): array {
    $rows = [];
    if (preg_match_all('/<tr\b[^>]*>.*?<\/tr>/is', $html, $trs)) {
        foreach ($trs[0] as $frag) {
            if (preg_match_all('/<strong>(.*?)<\/strong>/s', $frag, $strongs) && count($strongs[1]) >= 2) {
                // Column order is fixed in the table head: <strong>branch</strong>, then <strong>product</strong>.
                $rows[] = ['branch' => trim($strongs[1][0]), 'product' => trim($strongs[1][1])];
            }
        }
    }

    return $rows;
};

$pageDefault = $render();
$formDefault = $filterFormOf($pageDefault);

// ── A1: the sort direction IS a control inside the filter form, and its state is echoed ──────────
$sortSelect = $selectBlockOf($formDefault, 'sort_dir');
$hasBothOptions = str_contains($sortSelect, 'value="desc"') && str_contains($sortSelect, 'value="asc"');
$descSelected = (bool)preg_match('/value="desc"[^>]*\bselected\b/i', $sortSelect);
$ascNotSelected = !preg_match('/value="asc"[^>]*\bselected\b/i', $sortSelect);
$check('A1', 'a sort_dir control lives INSIDE the filter form, default shows desc',
    $sortSelect !== '' && $hasBothOptions && $descSelected && $ascNotSelected,
    sprintf('select[name=sort_dir] in the filter form: %s; offers asc+desc: %s; untouched page shows desc selected: %s',
        $sortSelect === '' ? 'ABSENT (the links are still the only sort UI)' : 'present',
        $hasBothOptions ? 'yes' : 'NO',
        $descSelected && $ascNotSelected ? 'yes' : 'NO (nothing tells the operator which order is in force)'));

// ── A2: requesting asc is reflected by the SAME control (state echo, not a second mechanism) ─────
$pageAsc = $render(['sort_dir' => 'asc']);
$formAsc = $filterFormOf($pageAsc);
$sortSelectAsc = $selectBlockOf($formAsc, 'sort_dir');
$ascSelected = (bool)preg_match('/value="asc"[^>]*\bselected\b/i', $sortSelectAsc);
$descNotSelected = !preg_match('/value="desc"[^>]*\bselected\b/i', $sortSelectAsc);
$check('A2', 'render with sort_dir=asc -> the control shows asc and only asc',
    $ascSelected && $descNotSelected,
    sprintf('asc selected: %s; desc not selected: %s; control: %s',
        $ascSelected ? 'yes' : 'NO', $descNotSelected ? 'yes' : 'NO',
        $sortSelectAsc === '' ? 'ABSENT' : 'present'));

// ── A3: ONE mechanism - the sort TOGGLES as links are gone (they were the bug) ──────────────────
// RE-ANCHORED 2026-10-12 (viewer W13/D7): the forecast table is paged now, and pager links
// legitimately carry sort_dir as STATE. What must not return is a SORT-ACTION link: the removed
// toggles rendered as <a class="btn btn-sm ...">Highest first</a>/<Lowest first>, with NO
// background when inactive (F3). Those are what this checks for; a state-carrying href is fine.
$toggleAnchor = 0;
if (preg_match_all('/<a\b[^>]*>(.*?)<\/a>/is', $pageDefault, $am)) {
    foreach ($am[1] as $label) {
        if (preg_match('/^\s*(Highest|Lowest) first/i', trim(strip_tags($label)))) {
            $toggleAnchor++;
        }
    }
}
$unfilledBtnSm = 0;
if (preg_match_all('/<a\b[^>]*class="[^"]*btn-sm[^"]*"[^>]*>/i', $pageDefault, $sm)) {
    foreach ($sm[0] as $anchor) {
        if (!preg_match('/btn-(primary|accent)/i', $anchor)) {
            $unfilledBtnSm++;
        }
    }
}
$check('A3', 'no sort-TOGGLE links remain (the sort is the select; no background-less btn-sm anchor)',
    $toggleAnchor === 0 && $unfilledBtnSm === 0,
    sprintf('anchors labelled "Highest/Lowest first" (the removed toggle controls): %d; <a> with btn-sm but NO filled background (the "looks disabled" control): %d',
        $toggleAnchor, $unfilledBtnSm));

// ── A4: the form carries the whole state, and nothing inside it submits on its own ───────────────
$carried = [];
foreach (['sort_dir', 'product_id', 'empty_rows', 'target_date', 'window', 'branch_id'] as $ctrl) {
    $carried[$ctrl] = str_contains($formDefault, 'name="' . $ctrl . '"');
}
$autoSubmits = (bool)preg_match('/this\.form\.submit\(\)/i', $formDefault);
$missingControls = array_keys(array_filter($carried, static fn(bool $v): bool => !$v));
$check('A4', 'the form carries sort_dir AND the filters; no control auto-submits',
    $missingControls === [] && !$autoSubmits,
    sprintf('missing controls: %s; auto-submit onchange: %s',
        $missingControls === [] ? 'none' : implode(', ', $missingControls),
        $autoSubmits ? 'PRESENT (an immediate submit would also apply unapplied changes)' : 'none'));

// ── A5: the apply button says what it does ───────────────────────────────────────────────────────
$hasApplyLabel = (bool)preg_match('/>\s*Apply filters\s*</i', $formDefault);
$hasRecalculate = (bool)preg_match('/Recalculate/i', $pageDefault);
$check('A5', 'the apply button is labelled "Apply filters" (not "Recalculate")',
    $hasApplyLabel && !$hasRecalculate,
    sprintf('"Apply filters" inside the form: %s; stale "Recalculate" text anywhere: %s',
        $hasApplyLabel ? 'yes' : 'NO', $hasRecalculate ? 'STILL PRESENT' : 'none'));

// ── A6: the round-trip a real operator makes - pick a product, change direction, apply ───────────
// Take a product that MEASURABLY renders today, find its id through the page's own dropdown, then
// assert that applying {product, asc} keeps BOTH: the direction control and the product filter.
// RE-ANCHORED 2026-10-12 (viewer W13/D7): the table is paged, so "the filtered page renders as many
// rows as the unfiltered page holds for it" is no longer an observable. The stable observables are:
// every rendered row IS that product, and the page's STATED total equals the filtered population
// size computed through the same helper the page reads.
$defaultRows = $rowFragments($pageDefault);
$productSelectDefault = $selectBlockOf($formDefault, 'product_id');
$options = [];
if (preg_match_all('/<option value="(\d+)"[^>]*>(.*?)<\/option>/s', $productSelectDefault, $opt, PREG_SET_ORDER)) {
    foreach ($opt as $o) {
        $options[trim(html_entity_decode($o[2], ENT_QUOTES | ENT_HTML5))] = (int)$o[1];
    }
}
$targetProduct = $defaultRows !== [] ? $defaultRows[0]['product'] : '';
$targetId = $options[html_entity_decode($targetProduct, ENT_QUOTES | ENT_HTML5)] ?? 0;
$user = dlCurrentUser(['admin', 'supervisor', 'auditor', 'viewer']);
$filtersFor = static function (array $extra) use ($user): array {
    $input = array_merge(['date_from' => '2026-09-28', 'date_to' => '2026-10-11', 'window' => '14', 'target_date' => '2026-10-12'], $extra);
    $f = dl_reportFilters($input, $user);
    $f['empty_rows_mode'] = dl_forecastEmptyRowsMode($input['empty_rows'] ?? null);
    $f['sort_dir'] = dl_overviewNormalizeSortDir($input['sort_dir'] ?? null);

    return $f;
};
$expectedCount = ($targetId > 0)
    ? count(dl_forecastRows(module()->db(), $filtersFor(['product_id' => (string)$targetId]), '2026-10-12', 14))
    : 0;
$roundTripOk = false;
$roundTripDetail = 'could not derive a product with rows - criterion inconclusive';
if ($targetProduct !== '' && $targetId > 0 && $expectedCount > 0) {
    $pageProductAsc = $render(['product_id' => (string)$targetId, 'sort_dir' => 'asc']);
    $formProductAsc = $filterFormOf($pageProductAsc);
    $productSelected = (bool)preg_match('/value="' . $targetId . '"[^>]*\bselected\b/i', $selectBlockOf($formProductAsc, 'product_id'));
    $ascStill = (bool)preg_match('/value="asc"[^>]*\bselected\b/i', $selectBlockOf($formProductAsc, 'sort_dir'));
    $filteredRows = $rowFragments($pageProductAsc);
    $allAreProduct = $filteredRows !== [];
    foreach ($filteredRows as $r) {
        if ($r['product'] !== $targetProduct) {
            $allAreProduct = false;
            break;
        }
    }
    $statedTotal = preg_match('/of\s+(\d+)\s+results?/i', $pageProductAsc, $tm) ? (int)$tm[1] : -1;
    $roundTripOk = $productSelected && $ascStill && $allAreProduct
        && $filteredRows !== [] && $statedTotal === $expectedCount;
    $roundTripDetail = sprintf('product "%s" (id %d): option stays selected=%s; asc still shown=%s; every rendered row is that product=%s; stated total=%d, expected=%d',
        $targetProduct, $targetId, $productSelected ? 'yes' : 'NO', $ascStill ? 'yes' : 'NO',
        $allAreProduct ? 'yes' : 'NO', $statedTotal, $expectedCount);
}
$check('A6', 'apply {product, asc} keeps BOTH the selected product and the direction', $roundTripOk, $roundTripDetail);

// ── A7: an invalid direction cannot break the page; the server default is deterministic ──────────
$pageInvalid = $render(['sort_dir' => 'sideways;DROP']);
$formInvalid = $filterFormOf($pageInvalid);
$invalidRenders = !str_contains($pageInvalid, 'THREW:');
$invalidDesc = (bool)preg_match('/value="desc"[^>]*\bselected\b/i', $selectBlockOf($formInvalid, 'sort_dir'));
$invalidCount = count($rowFragments($pageInvalid));
$check('A7', 'sort_dir=sideways -> page renders, falls back to desc, same rows',
    $invalidRenders && $invalidDesc && $invalidCount === count($defaultRows),
    sprintf('renders without a fatal: %s; falls back to desc: %s; rows %d vs %d on the untouched page',
        $invalidRenders ? 'yes' : 'NO: ' . substr($pageInvalid, 0, 120), $invalidDesc ? 'yes' : 'NO',
        $invalidCount, count($defaultRows)));

printf("\n  => %d RED. %s\n", $reds, $reds > 0
    ? 'The criterion discriminates: this is the work.'
    : 'WARNING: every criterion passes - it cannot discriminate a change.');
exit($reds > 0 ? 1 : 0);
