<?php

declare(strict_types=1);

/**
 * A provisional money figure must not surface on any admin surface.
 *
 * Owner directive (2026-10-05):
 *   "the provisional sales amount should not surface again. what the admin sees is the actual,
 *    correct amount thus pending sales are not included. my point is, provisional sales amount
 *    confuses accounting."
 *
 * THE OFFICIAL TOTALS ARE ALREADY CORRECT and this test does not ask for any arithmetic change:
 * dl_reportSalesData() already buckets by the canonical predicate, so grand_amount excludes
 * provisional. The defect is purely that a SECOND money figure is put in front of the admin -
 * a "Provisional ... PHP X" line - which reads as revenue and invites double counting. The fix is
 * to stop DISPLAYING a provisional amount, while keeping the classification (the settlement
 * workflow and the official totals depend on it) and the row-level markers.
 *
 * WHAT IS DELIBERATELY KEPT: provisional UNITS, relabelled as not-yet-counted rather than as
 * sales. Units are not money and they tell an operator how much is outstanding.
 *
 * ACCEPTANCE - the first assertion FAILS on the base tree, where the footer renders
 * "Provisional (pending ending / unfinalized PM): 6 PHP 150.00".
 */
ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-pending-not-monetised', TestHarness::MODE_INTEGRATION, 'baronledger.test');
ob_end_clean();
$h->allowLogLines('disyl.compile.phases');

$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/helpers.php';
require_once $base . '/modules/daily-ledger/helpers/reporting.php';
require_once $base . '/modules/daily-ledger/handlers.php';
app()->tenant()->setTenantId(207);
$ctx = modulePushContext('daily-ledger');
$db = $ctx->db();

$PENDING_DATE = '2031-03-02';
$FINALIZED_DATE = '2031-03-03';

$rows = [
    [
        'ledger_date' => $FINALIZED_DATE, 'shift' => 'AM', 'branch_name' => 'Money Branch',
        'product_name' => 'Counted Product', 'sku' => 'MON-A', 'beg_bal' => 10, 'addtl' => 0,
        'withdraw' => 2, 'bal_end' => 8, 'sales' => 0, 'price_snapshot' => 25.0, 'amount' => 0.0,
        'shift_status' => 'finalized', 'status_label' => 'official',
    ],
    [
        'ledger_date' => $PENDING_DATE, 'shift' => 'AM', 'branch_name' => 'Money Branch',
        'product_name' => 'Uncounted Product', 'sku' => 'MON-B', 'beg_bal' => 10, 'addtl' => 0,
        'withdraw' => 4, 'bal_end' => null, 'sales' => null, 'price_snapshot' => 25.0, 'amount' => null,
        'shift_status' => 'finalized', 'status_label' => 'pending ending',
    ],
];

$renderError = '';
$html = '';
try {
    ob_start();
    $html = dlRender('modules/daily-ledger/admin/sales.disyl', [
        'page_title' => 'Sales Summary', 'user_name' => 'Tester', 'user_role' => 'admin',
        'current_page' => 'sales', 'base_url' => '/daily-ledger', 'dl_token' => 'x',
        'date_from' => $PENDING_DATE, 'date_to' => $FINALIZED_DATE,
        'branch_id' => null, 'branches' => [['id' => 99076, 'code' => 'T-MON', 'name' => 'Money Branch']],
        'search' => '', 'filter_shift' => '',
        'business_date_label' => '2031-03-03', 'operating_timezone' => 'Asia/Manila',
        'operating_region' => 'PH', 'close_of_day_time' => '23:59',
        'pos_enabled' => false, 'sales_source_label' => 'Stock-derived (manual ledger)',
        'pos_reconciliation' => null,
        'sales_rows' => $rows, 'sales_total_matching' => 2, 'sales_shown' => 2,
        'sales_row_limit' => 500,
        'grand_units' => 42, 'grand_amount' => 1234.56,
        // A provisional money figure is supplied to the view. Base renders it; the fix must not.
        'provisional_units' => 6, 'provisional_amount' => 150.00,
        'pending_dates' => [$PENDING_DATE],
    ]);
    ob_end_clean();
} catch (\Throwable $e) {
    ob_end_clean();
    $renderError = get_class($e) . ': ' . $e->getMessage();
}

$h->test('the sales view renders at all', $renderError === '' && $html !== '', $renderError);

$flat = preg_replace('/\s+/', ' ', strip_tags($html));

if ($renderError === '') {
    // THE DIRECTIVE. A money figure must never sit next to the word provisional. Checked on the
    // tag-stripped text so it cannot be dodged by wrapping the amount in another element.
    $h->test('no provisional money figure is shown on the sales view',
        !preg_match('/provisional[^.]{0,120}?PHP\s*[\d,]/i', $flat),
        'found: ' . (preg_match('/provisional[^.]{0,120}?PHP\s*[\d,]{0,20}/i', $flat, $mm) ? $mm[0] : ''));

    // The authoritative amount must still be there - removing the wrong figure would be a
    // different bug. The template renders it with |number_format (0 dp), so 1234.56 -> 1,235.
    $h->test('the official total is still shown with its amount',
        preg_match('/official total/i', $flat) === 1 && preg_match('/PHP\s*1,23\d/', $flat) === 1,
        'expected the official amount near "Official Total" in: ' . substr($flat, 0, 400));

    // Removing the money must not remove the SIGNAL. The marker is how the operator knows the
    // figures above are incomplete.
    $pendingRowHtml = '';
    if (preg_match_all('#<tr\b.*?</tr>#is', $html, $trs)) {
        foreach ($trs[0] as $tr) {
            if (str_contains($tr, 'MON-B')) {
                $pendingRowHtml = $tr;
                break;
            }
        }
    }
    $h->test('the pending row is still visibly marked', stripos(strip_tags($pendingRowHtml), 'pending') !== false,
        'row: ' . substr(preg_replace('/\s+/', ' ', strip_tags($pendingRowHtml)), 0, 160));

    // Density decision: ONE marker per row, on the shift cell. The sales cell carries the tint and
    // an accessible label instead of repeating the badge text.
    $badgeCount = $pendingRowHtml === '' ? 0 : preg_match_all('/aria-label="(Pending count|Provisional)"/', $pendingRowHtml);
    $h->test('a pending row carries exactly one marker badge, not two', $badgeCount === 1,
        'found ' . $badgeCount . ' marker badge(s) in the row');

    // The sales cell must still flag its figure as not final, accessibly.
    $salesCellMarked = false;
    if ($pendingRowHtml !== '' && preg_match_all('#<td\b[^>]*>(.*?)</td>#is', $pendingRowHtml, $tds)) {
        foreach ($tds[1] as $cell) {
            if (preg_match('/aria-label=|title=/i', $cell) && stripos($cell, 'aria-label="Pending count"') === false) {
                $salesCellMarked = true;
                break;
            }
        }
    }
    $h->test('the sales cell still flags its figure as not final (tint/label, not a second badge)',
        $salesCellMarked, 'no accessible not-final flag on a non-badge cell');

    // An uncounted figure must read as deliberate, not as a rendering failure.
    $salesCellText = '';
    if ($pendingRowHtml !== '' && preg_match_all('#<td\b[^>]*class="[^"]*text-right[^"]*"[^>]*>(.*?)</td>#is', $pendingRowHtml, $sr)) {
        // right-aligned cells in order: beg, addtl, withdraw, bal_end, sales, price, amount
        $salesCellText = trim(preg_replace('/\s+/', ' ', strip_tags($sr[1][4] ?? '')));
    }
    $h->test('an uncounted figures cell shows an explicit dash rather than nothing',
        $salesCellText === '—',
        'sales cell text: "' . $salesCellText . '"');
}

// ---------------------------------------------------------------------------
// The other admin surfaces. Rendered checks need their own handlers; the invariant that matters
// is that no template can surface the figure, so assert over the whole template tree.
// ---------------------------------------------------------------------------

$offenders = [];
foreach (['dashboard', 'reports'] as $tpl) {
    $p = $base . '/templates/modules/daily-ledger/admin/' . $tpl . '.disyl';
    if (!is_file($p)) {
        continue;
    }
    $src = (string)file_get_contents($p);
    if (preg_match('/provisional_amount/', $src)) {
        $offenders[] = $tpl . '.disyl';
    }
}
$h->test('no admin template surfaces a provisional money figure', $offenders === [],
    'still referencing provisional_amount: ' . implode(', ', $offenders));

// A catch-all over the tree, so a surface added later cannot reintroduce it unnoticed.
$treeOffenders = [];
foreach (glob($base . '/templates/modules/daily-ledger/{admin,cashier}/*.disyl', GLOB_BRACE) ?: [] as $p) {
    if (preg_match('/provisional_amount/', (string)file_get_contents($p))) {
        $treeOffenders[] = basename($p);
    }
}
$h->test('no daily-ledger template anywhere surfaces a provisional money figure', $treeOffenders === [],
    'still referencing provisional_amount: ' . implode(', ', $treeOffenders));

// The classification itself must survive: the settlement workflow and the official totals depend
// on it. Removing the DISPLAY of a provisional amount must not remove the BUCKET.
$reporting = (string)file_get_contents($base . '/modules/daily-ledger/helpers/reporting.php');
$h->test('the provisional bucket is still computed (only its display was removed)',
    str_contains($reporting, 'provisional_amount'), 'the bucket was deleted from reporting.php');

$h->test('no daily-ledger row was created by this suite',
    (int)$db->query('SELECT COUNT(*) FROM dl_daily_ledger WHERE ledger_date LIKE \'2031-03-%\'')
        ->fetchColumn() === 0);

$h->done();
