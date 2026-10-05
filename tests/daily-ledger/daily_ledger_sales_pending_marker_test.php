<?php

declare(strict_types=1);

/**
 * Sales view — every row that is counted must be visibly marked, and the two
 * non-official states must read differently.
 *
 * Owner request (2026-10-05):
 *   "at admin view when viewing a specific date for sales ... a visual note on pending
 *    cells where ending is missing ... on multi dates, show an orange cell on cells with
 *    pending and show as tool tip dates with pending sales data"
 *
 * WHY THIS EXISTS — measured on the tree at dispatch, not inferred:
 *   dl_daily_ledger holds 942 rows with bal_end IS NULL. Those rows ARE counted in the
 *   view's "Provisional (pending ending / unfinalized PM)" footer total (provisional_units
 *   = 2976 rows), yet they carry NO badge at all, because the template re-derives its own
 *   condition:
 *       bal_end !== null && shift == 'PM' && shift_status != 'finalized'
 *   which can never be true when the ending is missing. The row query and the totals come
 *   from two different code paths: the totals come from dl_reportSalesData(), which uses
 *   the canonical dl_rowIsProvisional(); the rows come from the separate list query at
 *   handlers.php ~L10625, which fetches ss.status but no status_label — so the template is
 *   left to re-implement the rule. That is a third copy of it, and a label/total pair that
 *   can silently disagree — the same class of defect as the bucket restatement fixed in
 *   d919de46.
 *
 * ACCEPTANCE — the render section FAILS on the base tree (no marker is emitted for a
 * pending-ending row and no pending-dates summary exists).
 *
 * Asserted OUTCOMES, deliberately not mechanisms: the rendered HTML must contain a marker
 * on the pending row, and a summary naming the pending dates. Which function produced the
 * string is an implementation detail.
 *
 * The colour alone is asserted to be insufficient on purpose: this view is printable
 * (block head has @media print and a Print button), and tooltips do not survive print or
 * touch, so the marker must carry TEXT and an accessible label.
 */
ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-sales-pending-marker', TestHarness::MODE_INTEGRATION, 'baronledger.test');
ob_end_clean();
$h->allowLogLines('disyl.compile.phases');

$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/helpers.php';
require_once $base . '/modules/daily-ledger/helpers/reporting.php';
// handlers.php defines dlModuleSettings(), which the layout calls during render.
require_once $base . '/modules/daily-ledger/handlers.php';
app()->tenant()->setTenantId(207);
$ctx = modulePushContext('daily-ledger');
$db = $ctx->db();

$PENDING_ROW_DATE = '2031-03-02';
$FINALIZED_ROW_DATE = '2031-03-03';

// ---------------------------------------------------------------------------
// Section A — the rendered view marks a pending-ending row and summarises the dates
// ---------------------------------------------------------------------------

$rows = [
    [
        'ledger_date' => $FINALIZED_ROW_DATE, 'shift' => 'AM', 'branch_name' => 'Marker Branch',
        'product_name' => 'Marker Product A', 'sku' => 'MRK-A', 'beg_bal' => 10, 'addtl' => 0,
        'withdraw' => 2, 'bal_end' => 8, 'sales' => 0, 'price_snapshot' => 25.0, 'amount' => 0.0,
        'shift_status' => 'finalized', 'status_label' => 'official',
    ],
    [
        // The case the owner is describing: no ending has been entered at all.
        'ledger_date' => $PENDING_ROW_DATE, 'shift' => 'AM', 'branch_name' => 'Marker Branch',
        'product_name' => 'Marker Product B', 'sku' => 'MRK-B', 'beg_bal' => 10, 'addtl' => 0,
        'withdraw' => 4, 'bal_end' => null, 'sales' => 6, 'price_snapshot' => 25.0, 'amount' => 150.0,
        'shift_status' => 'finalized', 'status_label' => 'pending ending',
    ],
    [
        // Counted but not certified — ending present, PM shift never finalized.
        'ledger_date' => $PENDING_ROW_DATE, 'shift' => 'PM', 'branch_name' => 'Marker Branch',
        'product_name' => 'Marker Product C', 'sku' => 'MRK-C', 'beg_bal' => 5, 'addtl' => 1,
        'withdraw' => 3, 'bal_end' => 3, 'sales' => 0, 'price_snapshot' => 10.0, 'amount' => 0.0,
        'shift_status' => 'open', 'status_label' => 'provisional',
    ],
];

$renderError = '';
$html = '';
try {
    ob_start();
    $html = dlRender('modules/daily-ledger/admin/sales.disyl', [
        'page_title' => 'Sales Summary', 'user_name' => 'Tester', 'user_role' => 'admin',
        'current_page' => 'sales', 'base_url' => '/daily-ledger', 'dl_token' => 'x',
        'date_from' => $PENDING_ROW_DATE, 'date_to' => $FINALIZED_ROW_DATE,
        'branch_id' => null, 'branches' => [['id' => 99075, 'code' => 'T-MRK', 'name' => 'Marker Branch']],
        'search' => '', 'filter_shift' => '',
        'business_date_label' => '2031-03-03', 'operating_timezone' => 'Asia/Manila',
        'operating_region' => 'PH', 'close_of_day_time' => '23:59',
        'pos_enabled' => false, 'sales_source_label' => 'Stock-derived (manual ledger)',
        'pos_reconciliation' => null,
        'sales_rows' => $rows, 'sales_total_matching' => 3, 'sales_shown' => 3,
        'sales_row_limit' => 500,
        'grand_units' => 0, 'grand_amount' => 0.0,
        'provisional_units' => 6, 'provisional_amount' => 150.0,
        // Supplier of the summary line. The handler derives this from the SAME labelled rows.
        'pending_dates' => [$PENDING_ROW_DATE, $PENDING_ROW_DATE],
    ]);
    ob_end_clean();
} catch (\Throwable $e) {
    ob_end_clean();
    $renderError = get_class($e) . ': ' . $e->getMessage();
}

$h->test('the sales view renders at all', $renderError === '' && $html !== '', $renderError);

if ($renderError === '') {
    // Isolate the row block for the pending row, so a marker printed in a legend or the
    // footer cannot satisfy the assertion on its behalf.
    $pendingRowHtml = '';
    if (preg_match_all('#<tr\b.*?</tr>#is', $html, $trs)) {
        foreach ($trs[0] as $tr) {
            if (str_contains($tr, 'MRK-B')) {
                $pendingRowHtml = $tr;
                break;
            }
        }
    }

    $h->test('the pending-ending row is present in the rendered table', $pendingRowHtml !== '',
        'no <tr> containing MRK-B was rendered');

    // THE OWNER'S REQUEST: a row whose ending is missing must say so, visibly.
    $h->test('the row with a missing ending carries a visible pending marker',
        $pendingRowHtml !== '' && stripos($pendingRowHtml, 'pending') !== false,
        'row HTML: ' . substr(preg_replace('/\s+/', ' ', strip_tags($pendingRowHtml)), 0, 200));

    // It must read differently from "counted but not certified", or the two states blur
    // together again — the ambiguity the totals fix removed.
    $h->test('a missing ending and an unfinalized shift are labelled differently',
        stripos($pendingRowHtml, 'pending count') !== false
        && stripos($html, 'provisional') !== false,
        'expected "pending count" on the pending row and "provisional" somewhere for the unfinalized PM row');

    // bal_end renders as an em dash while sales renders the DERIVED settled value. A bare
    // 0 next to a missing ending reads like a counted zero, so the Sales cell must flag itself.
    //
    // Density decision (owner, 2026-10-05): ONE badge per row, on the shift cell. The Sales cell
    // flags its figure through the CELL's own tint and title/aria-label, not by repeating the
    // badge text - two badges per row read as noise across a 70-row table.
    $badges = preg_match_all('/aria-label="(?:Pending count|Provisional)"/', $pendingRowHtml);
    $h->test('a pending row carries exactly one marker badge, not two', $badges === 1,
        'found ' . $badges . ' marker badge(s) in the row');

    // The attribute must be on a <td> itself, so the badge's own <span> cannot satisfy it.
    $cellFlagged = preg_match(
        '#<td\b[^>]*(?:title|aria-label)="[^"]*(?:pending|provisional)[^"]*"#i',
        $pendingRowHtml
    ) === 1;
    $h->test('the Sales cell flags its figure as not final via the cell itself', $cellFlagged,
        'no title/aria-label on a <td> in the pending row');

    // Accessible label: colour alone is not a marker, and this view is printed.
    $h->test('the marker carries an accessible label (title or aria-label), not colour alone',
        $pendingRowHtml !== '' && (stripos($pendingRowHtml, 'title=') !== false
            || stripos($pendingRowHtml, 'aria-label') !== false),
        'no title/aria-label attribute on the pending row');

    // Multi-date: the pending dates must be named, so the operator does not have to hunt
    // tooltips to find where the data is incomplete.
    //
    // It must be the SUMMARY that names the date, not merely "pending" somewhere plus the date
    // somewhere. An earlier revision of this assertion asserted exactly that and PASSED on the
    // unfixed template — the footer already reads "Provisional (pending ending / unfinalized
    // PM)" and the Date cell already renders the date. A guard that cannot fail is worse than
    // no guard, because it is trusted. Verified by reverting only sales.disyl to base.
    $h->test('a range view names the dates that have pending data',
        (bool)preg_match(
            '/\d+\s+date\(s\)\s+have pending data:.*?' . preg_quote($PENDING_ROW_DATE, '/') . '/si',
            $html
        ),
        'no summary block naming ' . $PENDING_ROW_DATE);

    // The official row must NOT be marked, or the marker is noise and gets ignored.
    $officialRowHtml = '';
    if (preg_match_all('#<tr\b.*?</tr>#is', $html, $trs2)) {
        foreach ($trs2[0] as $tr) {
            if (str_contains($tr, 'MRK-A')) {
                $officialRowHtml = $tr;
                break;
            }
        }
    }
    $h->test('an official row is not marked pending', $officialRowHtml !== ''
        && stripos(strip_tags($officialRowHtml), 'pending') === false,
        'official row HTML: ' . substr(preg_replace('/\s+/', ' ', strip_tags($officialRowHtml)), 0, 200));
}

// ---------------------------------------------------------------------------
// Section B — the row query must carry the canonical label, not leave the template to
// re-derive the rule. This is the gap that produced the 942 unmarked rows.
// ---------------------------------------------------------------------------

$labellerExists = function_exists('dl_salesRowStatusLabel');
$h->test('a single canonical row labeller exists for sales rows', $labellerExists,
    'dl_salesRowStatusLabel() not found — the rule is still duplicated in the template');

if ($labellerExists) {
    $cases = [
        'missing ending -> pending ending' => [
            ['bal_end' => null, 'shift' => 'AM', 'shift_status' => 'finalized'], 'pending ending'],
        'unfinalized PM -> provisional' => [
            ['bal_end' => 5, 'shift' => 'PM', 'shift_status' => 'open'], 'provisional'],
        'finalized -> official' => [
            ['bal_end' => 5, 'shift' => 'PM', 'shift_status' => 'finalized'], 'official'],
        'no shift row, AM -> official (history preserved)' => [
            ['bal_end' => 5, 'shift' => 'AM', 'shift_status' => null], 'official'],
        'no shift row, PM -> provisional' => [
            ['bal_end' => 5, 'shift' => 'PM', 'shift_status' => null], 'provisional'],
    ];
    foreach ($cases as $label => $pair) {
        $got = dl_salesRowStatusLabel($pair[0]);
        $h->test('labeller: ' . $label, $got === $pair[1], 'got ' . var_export($got, true));
    }

    // The labeller must agree with the reporting predicate, or the row marker and the
    // footer total can disagree again — the defect this test exists to prevent.
    $agree = true;
    $disagreeRow = [];
    foreach ($db->query("SELECT dl.bal_end, dl.shift, ss.status AS shift_status
                         FROM dl_daily_ledger dl
                         LEFT JOIN dl_ledger_shift_status ss
                                ON ss.branch_id = dl.branch_id
                               AND ss.ledger_date = dl.ledger_date
                               AND ss.shift = dl.shift COLLATE utf8mb4_unicode_ci
                         LIMIT 2000") as $row) {
        $fromLabeller = dl_salesRowStatusLabel($row);
        $fromPredicate = dl_rowIsProvisional($row) ? 'provisional' : 'official';
        if ($row['bal_end'] === null) {
            $fromPredicate = 'pending ending';
        }
        if ($fromLabeller !== $fromPredicate) {
            $agree = false;
            $disagreeRow = $row + ['labeller' => $fromLabeller, 'predicate' => $fromPredicate];
            break;
        }
    }
    $h->test('the row labeller agrees with dl_rowIsProvisional() on live rows', $agree,
        json_encode($disagreeRow));
}

// The template must consume the label rather than re-implement the rule. Modelled on the
// source assertion a971e41c added for its two add paths, because a half-fix that covers
// only one path is invisible in production.
$tplPath = $base . '/templates/modules/daily-ledger/admin/sales.disyl';
$tpl = (string)file_get_contents($tplPath);
$h->test('the sales template renders the marker from status_label',
    str_contains($tpl, 'status_label'),
    'template does not reference status_label — it is still re-deriving the rule');
$h->test('the superseded hand-written condition is gone from the template',
    strpos($tpl, "row.shift_status != 'finalized'") === false,
    'the old narrow condition is still present in sales.disyl');

// The handler's list path must annotate every row it sends to the template.
$handlerSrc = (string)file_get_contents($base . '/modules/daily-ledger/handlers.php');
$h->test('the sales list path annotates rows with the canonical label',
    str_contains($handlerSrc, 'dl_salesRowStatusLabel'),
    'handlers.php does not call dl_salesRowStatusLabel() — rows reach the view unlabelled');

// ---------------------------------------------------------------------------
// Section C — permanence guard: the SQL copy of the predicate must agree with the PHP one
// ---------------------------------------------------------------------------
// Measured divergence on 2026-10-05 was 0 (2976 vs 2976), because AM shift rows are only
// ever 'finalized' or absent, so the SQL's extra "shift = 'PM'" guard is currently inert.
// It is inert by luck of the data, not by construction — this pins it.
$divergent = (int)$db->query("SELECT COUNT(*) FROM dl_daily_ledger dl
    LEFT JOIN dl_ledger_shift_status ss
           ON ss.branch_id = dl.branch_id AND ss.ledger_date = dl.ledger_date
          AND ss.shift = dl.shift COLLATE utf8mb4_unicode_ci
    WHERE dl.bal_end IS NOT NULL AND dl.shift <> 'PM'
      AND ss.status IS NOT NULL AND ss.status <> 'finalized'")->fetchColumn();
$h->test('the SQL provisional expression still agrees with dl_rowIsProvisional()', $divergent === 0,
    $divergent . ' row(s) where PHP says provisional and the SQL expression says official');

// ---------------------------------------------------------------------------
// Section D — no fixture rows were created; nothing to clean up.
// ---------------------------------------------------------------------------
$h->test('no daily-ledger row was created by this suite',
    (int)$db->query('SELECT COUNT(*) FROM dl_daily_ledger WHERE ledger_date LIKE \'2031-03-%\'')
        ->fetchColumn() === 0);

$h->done();
