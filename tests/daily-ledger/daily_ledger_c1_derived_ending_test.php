<?php

declare(strict_types=1);

/**
 * C1 — a DERIVED ending is never a count.
 *
 * dl_rowIsProvisional() opens with the C1 clause: a row whose end_source is a ladder rung
 * ('derived-from-movements' / 'zero-forced') is PROVISIONAL, whatever the shift status says,
 * until an admin verifies it. That clause is the entire reason end_source exists.
 *
 * The clause is enforced in the settle UI's own queries and (since 2026-10-05) in the dashboard
 * and consolidated aggregates, which now call dl_provisionalSqlExpr(). But the clause is BLIND
 * wherever the row is fetched without the column:
 *
 *   - dl_reportSalesData() SELECTs dl.bal_end and ss.status but NOT dl.end_source
 *     (helpers/reporting.php ~L223-226), so dl_rowIsProvisional() receives a row with no
 *     end_source and C1 can never fire;
 *   - the admin sales LIST query does the same (handlers.php ~L10627), so the row BADGE is blind
 *     too.
 *
 * WHY IT IS REACHABLE, not theoretical: settle is only allowed while a shift is unfinalized, so
 * immediately after a settle the shift-status clause keeps the row provisional by accident. But
 * settling FILLS the endings, which makes the PM shift COMPLETE, and
 * dl_maybeAutoFinalizeCommissaryPmShift() finalizes a complete PM shift on the next RENDER. From
 * that moment the row has a finalized shift and a derived, UNVERIFIED ending - and a reporting
 * path that cannot see C1 counts it OFFICIAL. The dashboard, which can see C1, still counts it
 * provisional. The two disagree, and the settled ending silently becomes revenue.
 *
 * ACCEPTANCE - fails on the base tree (both the label and the bucket assertion).
 */
ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-c1-derived-ending', TestHarness::MODE_INTEGRATION, 'baronledger.test');
ob_end_clean();
$h->allowLogLines('disyl.compile.phases');

$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/helpers.php';
require_once $base . '/modules/daily-ledger/helpers/reporting.php';
// handlers.php defines dl_ledgerSalesQuantitySql(), which dl_reportSalesData() builds on.
require_once $base . '/modules/daily-ledger/handlers.php';
app()->tenant()->setTenantId(207);
$ctx = modulePushContext('daily-ledger');
$db = $ctx->db();

$BRANCH = 8;
$DATE = '2031-03-20';
$PRODUCT = 22;
$SHIFT = 'PM';

$cleanup = static function () use ($db, $BRANCH, $DATE): void {
    $db->execute('DELETE FROM dl_daily_ledger WHERE branch_id = :b AND ledger_date = :d',
        [':b' => $BRANCH, ':d' => $DATE]);
    $db->execute('DELETE FROM dl_ledger_shift_status WHERE branch_id = :b AND ledger_date = :d',
        [':b' => $BRANCH, ':d' => $DATE]);
};

$cleanup();

$productOk = (int)$db->query('SELECT COUNT(*) FROM dl_products WHERE id = ' . (int)$PRODUCT)->fetchColumn() > 0;
$h->test('the fixture product exists', $productOk, 'dl_products id=' . $PRODUCT . ' missing');

if ($productOk) {
    // A SETTLED ending: an ending IS recorded (so it is not "pending"), and end_source names the
    // ladder rung (so C1 applies). The shift is FINALIZED, which is the reachable state after
    // settle fills the endings and the render-time auto-finalize completes the PM shift.
    //
    // beg 20 + addtl 100 - withdraw 5 - bal_end 100 = sales 15, so the row carries a NON-ZERO
    // amount. That matters: with a zero amount the bucket assertion below would pass even while
    // the row was mis-bucketed, i.e. it would not discriminate.
    $db->execute(
        'INSERT INTO dl_daily_ledger (branch_id, product_id, ledger_date, shift, price_snapshot,
                                      beg_bal, addtl, withdraw, bal_end, end_source)
         VALUES (:b, :p, :d, :s, 10, 20, 100, 5, 100, :src)',
        [':b' => $BRANCH, ':p' => $PRODUCT, ':d' => $DATE, ':s' => $SHIFT, ':src' => 'derived-from-movements']
    );
    $db->execute(
        'INSERT INTO dl_ledger_shift_status (branch_id, ledger_date, shift, status, finalized_at)
         VALUES (:b, :d, :s, :st, NOW())
         ON DUPLICATE KEY UPDATE status = VALUES(status), finalized_at = VALUES(finalized_at)',
        [':b' => $BRANCH, ':d' => $DATE, ':s' => $SHIFT, ':st' => 'finalized']
    );

    $filters = [
        'accessible_branch_ids' => [$BRANCH],
        'date_from' => $DATE,
        'date_to' => $DATE,
        'branch_id' => $BRANCH,
        'product_id' => 0,
        'shift' => '',
        'search' => '',
        'pending_rows_mode' => 'include',
    ];

    $data = dl_reportSalesData($db, $filters);
    $row = null;
    foreach ($data['rows'] as $candidate) {
        if ((int)($candidate['product_id'] ?? 0) === $PRODUCT) {
            $row = $candidate;
            break;
        }
    }

    $h->test('the settled fixture row is returned by the report', is_array($row),
        'rows returned: ' . count($data['rows']));

    if (is_array($row)) {
        // The predicate needs the column. If the SELECT omits it, C1 cannot fire - assert the
        // key first so a failure here names the cause rather than the symptom.
        $h->test('the report row carries end_source, so C1 can be evaluated',
            array_key_exists('end_source', $row),
            'keys: ' . implode(', ', array_keys($row)));

        $h->test('C1: a derived, unverified ending is PROVISIONAL even on a finalized shift',
            ($row['status_label'] ?? null) === 'provisional',
            'status_label=' . var_export($row['status_label'] ?? null, true)
            . ' (official means the derived ending is being counted as revenue)');

        // And it must be TOTALLED the same way it is labelled.
        $h->test('C1: the derived ending is excluded from the official total',
            (float)($data['totals']['official_amount'] ?? 0) === 0.0,
            'official_amount=' . var_export($data['totals']['official_amount'] ?? null, true));
    }

    // The badge path uses the same predicate, so its query must carry the column too.
    $handlerSrc = (string)file_get_contents($base . '/modules/daily-ledger/handlers.php');
    $listSelect = '';
    if (preg_match('/\$listStmt = \$ctx->db\(\)->prepare\((.*?)LIMIT/s', $handlerSrc, $m)) {
        $listSelect = $m[1];
    }
    $h->test('the sales LIST query carries end_source, so the row badge can see C1',
        $listSelect !== '' && str_contains($listSelect, 'end_source'),
        'the list SELECT does not include end_source, so a settled row would be badged official');

    $cleanup();
}

$h->test('fixtures are fully removed',
    (int)$db->query('SELECT COUNT(*) FROM dl_daily_ledger WHERE ledger_date = \'' . $DATE . '\'')->fetchColumn() === 0
    && (int)$db->query('SELECT COUNT(*) FROM dl_ledger_shift_status WHERE ledger_date = \'' . $DATE . '\'')->fetchColumn() === 0);

$h->done();
