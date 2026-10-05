<?php

declare(strict_types=1);

/**
 * The admin Sales view must show the WHOLE sheet.
 *
 * Owner requirement (2026-10-05): "as long as admin can see all rows, no problem there."
 *
 * WHY THIS EXISTS — measured, not inferred:
 *   The cashier sheet (dl_fetchCashierLedgerRows) is PRODUCT-DRIVEN
 *   (FROM dl_products ... LEFT JOIN dl_daily_ledger), so every active product appears. The admin
 *   Sales page (handleAdminSales) was ROW-DRIVEN (FROM dl_daily_ledger JOIN dl_products), so a
 *   product whose carry was refused at zero has no row on the next shift and is invisible to the
 *   admin. On branch 8 / 2026-10-05 PM the two sheets disagreed 178 products vs 88 rows.
 *
 * The fix must not invent data, and must not turn "no movement" into a false pending alarm:
 *   - a product with NO ledger row has NO recorded values, so it contributes ZERO money/units and
 *     is labelled "no record", a state DISTINCT from a genuine pending cell (a row exists, bears
 *     activity, and bal_end IS NULL);
 *   - the official/provisional totals are byte-identical before and after.
 *
 * The oracle uses a private fixture branch so it can assert exact totals, then proves the count
 * matches the cashier sheet and that nothing is written back.
 */
ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-admin-sales-full-sheet', TestHarness::MODE_INTEGRATION, 'baronledger.test');
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

$BRANCH = 99230;            // branch with a mix of recorded and no-record products
$LONELY_BRANCH = 99231;     // branch whose only product has no ledger row at all
$DATE = '2032-05-10';
$P_OFFICIAL = 99230;        // AM finalized, ending -> official sales 5 / 50
$P_PENDING = 99231;         // AM finalized shift but no ending -> genuine pending
$P_NORECORD = 99232;        // no ledger row at all -> "no record"
$P_PROVISIONAL = 99233;     // PM open, ending -> provisional sales 10 / 100
$P_LONELY = 99234;          // no ledger row on the lonely branch

$cleanup = static function () use ($db, $BRANCH, $LONELY_BRANCH, $P_OFFICIAL, $P_PENDING, $P_NORECORD, $P_PROVISIONAL, $P_LONELY): void {
    $db->execute('DELETE FROM dl_daily_ledger WHERE branch_id IN (' . $BRANCH . ',' . $LONELY_BRANCH . ')');
    $db->execute('DELETE FROM dl_ledger_shift_status WHERE branch_id IN (' . $BRANCH . ',' . $LONELY_BRANCH . ')');
    $db->execute('DELETE FROM dl_branch_products WHERE branch_id IN (' . $BRANCH . ',' . $LONELY_BRANCH . ')');
    $db->execute('DELETE FROM dl_branches WHERE id IN (' . $BRANCH . ',' . $LONELY_BRANCH . ')');
    $db->execute('DELETE FROM dl_products WHERE id IN (' . $P_OFFICIAL . ',' . $P_PENDING . ',' . $P_NORECORD . ',' . $P_PROVISIONAL . ',' . $P_LONELY . ')');
};

$cleanup();

$db->execute('INSERT INTO dl_branches (id, code, name, is_active) VALUES (' . $BRANCH . ", 'T-FULL', 'Full Sheet Branch', 1)");
$db->execute('INSERT INTO dl_branches (id, code, name, is_active) VALUES (' . $LONELY_BRANCH . ", 'T-LONE', 'Lonely Branch', 1)");
$product = $db->prepare('INSERT INTO dl_products (id, sku, name, product_category, current_price, sort_order, is_active) VALUES (:id, :sku, :name, :cat, :price, 0, 1)');
$product->execute([':id' => $P_OFFICIAL, ':sku' => 'FULL-OFF', ':name' => 'Full Official', ':cat' => 'bread', ':price' => 10]);
$product->execute([':id' => $P_PENDING, ':sku' => 'FULL-PEND', ':name' => 'Full Pending', ':cat' => 'bread', ':price' => 10]);
$product->execute([':id' => $P_NORECORD, ':sku' => 'FULL-NONE', ':name' => 'Full NoRecord', ':cat' => 'cake', ':price' => 10]);
$product->execute([':id' => $P_PROVISIONAL, ':sku' => 'FULL-PROV', ':name' => 'Full Provisional', ':cat' => 'cake', ':price' => 10]);
$product->execute([':id' => $P_LONELY, ':sku' => 'FULL-LONE', ':name' => 'Full Lonely', ':cat' => 'bread', ':price' => 10]);
$link = $db->prepare('INSERT INTO dl_branch_products (branch_id, product_id, is_active) VALUES (:b, :p, 1)');
foreach ([$P_OFFICIAL, $P_PENDING, $P_NORECORD, $P_PROVISIONAL] as $pid) {
    $link->execute([':b' => $BRANCH, ':p' => $pid]);
}
$link->execute([':b' => $LONELY_BRANCH, ':p' => $P_LONELY]);

$ledger = $db->prepare('INSERT INTO dl_daily_ledger (branch_id, product_id, ledger_date, shift, price_snapshot, beg_bal, addtl, withdraw, bal_end, sales) VALUES (:b, :p, :d, :s, 10, :beg, 0, 0, :end, :sales)');
// Official: 10 - 0 - 5 = 5 units -> 50.
$ledger->execute([':b' => $BRANCH, ':p' => $P_OFFICIAL, ':d' => $DATE, ':s' => 'AM', ':beg' => 10, ':end' => 5, ':sales' => 5]);
// Genuine pending: activity (beg 10) but ending missing. It is a row, so it is a gap.
$ledger->execute([':b' => $BRANCH, ':p' => $P_PENDING, ':d' => $DATE, ':s' => 'AM', ':beg' => 10, ':end' => null, ':sales' => null]);
// Provisional: 20 - 0 - 10 = 10 units -> 100, on an unfinalized PM shift.
$ledger->execute([':b' => $BRANCH, ':p' => $P_PROVISIONAL, ':d' => $DATE, ':s' => 'PM', ':beg' => 20, ':end' => 10, ':sales' => 10]);
// AM finalized so P_OFFICIAL is official; there is deliberately no PM shift-status row.
$db->execute("INSERT INTO dl_ledger_shift_status (branch_id, ledger_date, shift, status) VALUES (" . $BRANCH . ", '" . $DATE . "', 'AM', 'finalized')");

$renderSales = static function (array $get) use ($db): string {
    $tokens = dl_generateAuthTokens([
        'sub' => 'admin:1', 'id' => 1, 'username' => 'full-sheet-admin', 'name' => 'Full Sheet Admin',
        'role' => 'admin', 'source' => 'daily-ledger',
    ]);
    $_COOKIE[dlCookieName()] = $tokens['token'];
    $_GET = $get;
    $_SERVER['REQUEST_URI'] = '/daily-ledger/admin/sales?' . http_build_query($get);
    $_SERVER['REQUEST_METHOD'] = 'GET';
    ob_start();
    handleAdminSales();
    return (string)ob_get_clean();
};

$extract = static function (string $html): array {
    $matching = 0;
    if (preg_match('#<span>Sales Data</span>\s*<span class="text-sm text-muted">\s*(?:showing the newest \d+ of )?([\d,]+) rows#s', $html, $m)) {
        $matching = (int)str_replace(',', '', $m[1]);
    }
    $officialUnits = null;
    $officialAmount = null;
    if (preg_match('#Official Total:</td>\s*<td[^>]*>(\d+)</td>\s*<td></td>\s*<td[^>]*>PHP\s*([\d,\.]+)#is', $html, $m)) {
        $officialUnits = (int)$m[1];
        $officialAmount = (float)str_replace(',', '', $m[2]);
    }
    $pendingUnits = 0;
    if (preg_match('#Pending \(not counted yet\):\s*(\d+)\s*units#i', $html, $m)) {
        $pendingUnits = (int)$m[1];
    }
    return [
        'matching' => $matching,
        'official_units' => $officialUnits,
        'official_amount' => $officialAmount,
        'pending_units' => $pendingUnits,
        'pending_banner' => (bool)preg_match('#date\(s\) have pending data#', $html),
    ];
};

$rowForSku = static function (string $html, string $sku): string {
    if (preg_match_all('#<tr\b.*?</tr>#is', $html, $trs)) {
        foreach ($trs[0] as $tr) {
            if (str_contains($tr, '>' . $sku . '<')) {
                return $tr;
            }
        }
    }
    return '';
};

// ---------------------------------------------------------------------------
// A. Every active product appears, matching the cashier sheet's product count
// ---------------------------------------------------------------------------
$h->section('A — the whole sheet');

$htmlNoShift = $renderSales(['date_from' => $DATE, 'date_to' => $DATE, 'branch_id' => (string)$BRANCH]);
$cashierRows = dl_fetchCashierLedgerRows($db, $BRANCH, $DATE, 'AM');
$admin = $extract($htmlNoShift);
$h->test(
    'the admin Sales view lists every active product of the branch',
    $admin['matching'] === 4,
    'matching=' . var_export($admin['matching'], true)
);
$h->test(
    'the admin Sales view matches the cashier sheet product count',
    $admin['matching'] === count($cashierRows),
    'admin=' . $admin['matching'] . ' cashier=' . count($cashierRows)
);
foreach (['FULL-OFF', 'FULL-PEND', 'FULL-NONE', 'FULL-PROV'] as $sku) {
    $h->test("product {$sku} is visible in the admin Sales view", $rowForSku($htmlNoShift, $sku) !== '');
}

// ---------------------------------------------------------------------------
// B. "No record" is visibly distinct from a genuine pending cell
// ---------------------------------------------------------------------------
$h->section('B — no record vs genuine pending');

$noRecordRow = $rowForSku($htmlNoShift, 'FULL-NONE');
$pendingRow = $rowForSku($htmlNoShift, 'FULL-PEND');
$h->test(
    'the no-record row carries its own "No record" marker',
    $noRecordRow !== '' && str_contains($noRecordRow, 'aria-label="No record"'),
    substr(preg_replace('/\s+/', ' ', strip_tags($noRecordRow)), 0, 220)
);
$h->test(
    'the no-record row is NOT flagged pending',
    $noRecordRow !== '' && !str_contains($noRecordRow, 'aria-label="Pending count"')
        && !str_contains($noRecordRow, 'aria-label="Provisional"'),
    substr(preg_replace('/\s+/', ' ', strip_tags($noRecordRow)), 0, 220)
);
$h->test(
    'the genuine pending row still carries the amber pending marker',
    $pendingRow !== '' && str_contains($pendingRow, 'aria-label="Pending count"'),
    substr(preg_replace('/\s+/', ' ', strip_tags($pendingRow)), 0, 220)
);
$h->test(
    'the two states render differently (no exchangeable labels)',
    $noRecordRow !== '' && $pendingRow !== ''
        && stripos(strip_tags($pendingRow), 'no record') === false,
    'pending row: ' . substr(preg_replace('/\s+/', ' ', strip_tags($pendingRow)), 0, 220)
);
$h->test(
    'the no-record row shows no fabricated movement or ending',
    $noRecordRow !== ''
        && !preg_match('#<td class="text-right">\s*(?!—)[^<\s]+#', $noRecordRow),
    'a numeric-looking cell leaked into: ' . substr(preg_replace('/\s+/', ' ', strip_tags($noRecordRow)), 0, 220)
);

// ---------------------------------------------------------------------------
// C/D. Totals unchanged; the added rows contribute zero
// ---------------------------------------------------------------------------
$h->section('C/D — totals are unchanged and the extra rows contribute zero');

// The row-driven baseline for the SAME filter, computed with the pre-change query shape. This is
// the "before" that the totals must be byte-identical to.
$baselineStmt = $db->prepare(
    "SELECT COUNT(*) AS row_count,
            COALESCE(SUM(CASE WHEN (" . dl_provisionalSqlExpr('dl', 'ss') . ") THEN 0 ELSE (" . dl_ledgerSalesQuantitySql('dl') . ") END), 0) AS official_units,
            COALESCE(SUM(CASE WHEN (" . dl_provisionalSqlExpr('dl', 'ss') . ") THEN 0 ELSE (" . dl_ledgerSalesAmountSql('dl') . ") END), 0) AS official_amount,
            COALESCE(SUM(CASE WHEN (" . dl_provisionalSqlExpr('dl', 'ss') . ") THEN (" . dl_ledgerSalesQuantitySql('dl') . ") ELSE 0 END), 0) AS provisional_units,
            COALESCE(SUM(CASE WHEN (" . dl_provisionalSqlExpr('dl', 'ss') . ") THEN (" . dl_ledgerSalesAmountSql('dl') . ") ELSE 0 END), 0) AS provisional_amount
       FROM dl_daily_ledger dl
       INNER JOIN dl_products p ON p.id = dl.product_id
       INNER JOIN dl_branches b ON b.id = dl.branch_id
       LEFT JOIN dl_ledger_shift_status ss ON ss.branch_id = dl.branch_id AND ss.ledger_date = dl.ledger_date AND ss.shift = dl.shift COLLATE utf8mb4_unicode_ci
      WHERE dl.branch_id = ? AND dl.ledger_date BETWEEN ? AND ?"
);
$baselineStmt->execute([$BRANCH, $DATE, $DATE]);
$baseline = $baselineStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$h->test(
    'the row-driven baseline has the real totals (5 official, 10 provisional)',
    (int)($baseline['official_units'] ?? -1) === 5 && (float)($baseline['official_amount'] ?? -1) === 50.0
        && (int)($baseline['provisional_units'] ?? -1) === 10
        && (float)($baseline['provisional_amount'] ?? -1) === 100.0
        && (int)($baseline['row_count'] ?? -1) === 3,
    json_encode($baseline)
);
$h->test(
    'the rendered official totals equal the row-driven baseline',
    $admin['official_units'] === (int)$baseline['official_units']
        && $admin['official_amount'] === (float)$baseline['official_amount'],
    'rendered=' . json_encode($admin) . ' baseline=' . json_encode($baseline)
);
$h->test(
    'the rendered pending units equal the row-driven baseline',
    $admin['pending_units'] === (int)$baseline['provisional_units'],
    'rendered=' . $admin['pending_units'] . ' baseline=' . $baseline['provisional_units']
);
$h->test(
    'the no-record product contributes no official amount',
    $rowForSku($htmlNoShift, 'FULL-NONE') !== ''
        && !str_contains(strip_tags($rowForSku($htmlNoShift, 'FULL-NONE')), '50'),
    'unexpected money on the no-record row'
);
// The no-record row shows a dash in the Amount column and a dash for every movement.
$h->test(
    'the no-record row renders dashes, not zeros or money',
    substr_count($noRecordRow, '—') >= 7,
    'dashes=' . substr_count($noRecordRow, '—')
);

// ---------------------------------------------------------------------------
// E. The existing filters still constrain the result
// ---------------------------------------------------------------------------
$h->section('E — branch / shift / search filters');

$htmlAm = $renderSales(['date_from' => $DATE, 'date_to' => $DATE, 'branch_id' => (string)$BRANCH, 'shift' => 'AM']);
$htmlPm = $renderSales(['date_from' => $DATE, 'date_to' => $DATE, 'branch_id' => (string)$BRANCH, 'shift' => 'PM']);
// The sheet is product-driven under EITHER shift, so every active product still has a row; the
// shift filter changes each product's STATE (recorded vs no record), not its presence.
$amOff = $rowForSku($htmlAm, 'FULL-OFF');
$amPend = $rowForSku($htmlAm, 'FULL-PEND');
$amProv = $rowForSku($htmlAm, 'FULL-PROV');
$h->test(
    'the AM filter keeps the real AM rows and renders the PM-only product as no record',
    $amOff !== '' && !str_contains($amOff, 'aria-label="No record"')
        && !str_contains($amOff, 'aria-label="Pending count"') && !str_contains($amOff, 'aria-label="Provisional"')
        && $amPend !== '' && str_contains($amPend, 'aria-label="Pending count"')
        && $amProv !== '' && str_contains($amProv, 'aria-label="No record"')
        && str_contains($htmlAm, 'FULL-NONE')
);
$pmOff = $rowForSku($htmlPm, 'FULL-OFF');
$pmPend = $rowForSku($htmlPm, 'FULL-PEND');
$pmProv = $rowForSku($htmlPm, 'FULL-PROV');
$h->test(
    'the PM filter keeps the provisional PM row and renders the AM-only products as no record',
    $pmProv !== '' && str_contains($pmProv, 'aria-label="Provisional"')
        && $pmOff !== '' && str_contains($pmOff, 'aria-label="No record"')
        && $pmPend !== '' && str_contains($pmPend, 'aria-label="No record"')
);
$h->test(
    'the shift filter scopes the totals (AM official 5/50, no provisional)',
    ($am = $extract($htmlAm)) && $am['official_units'] === 5 && $am['official_amount'] === 50.0
        && $am['pending_units'] === 0,
    json_encode($extract($htmlAm))
);
$h->test(
    'the shift filter scopes the totals (PM provisional 10/100, no official)',
    ($pm = $extract($htmlPm)) && $pm['official_units'] === 0 && $pm['pending_units'] === 10,
    json_encode($extract($htmlPm))
);
$htmlLonely = $renderSales(['date_from' => $DATE, 'date_to' => $DATE, 'branch_id' => (string)$LONELY_BRANCH]);
$h->test(
    'the branch filter does not leak the other branch',
    str_contains($htmlLonely, 'FULL-LONE') && !str_contains($htmlLonely, 'FULL-OFF') && !str_contains($htmlLonely, 'FULL-NONE')
);
$htmlSearch = $renderSales(['date_from' => $DATE, 'date_to' => $DATE, 'branch_id' => (string)$BRANCH, 'q' => 'FULL-NONE']);
$h->test(
    'the search filter keeps only the matching product',
    str_contains($htmlSearch, 'FULL-NONE') && !str_contains($htmlSearch, 'FULL-OFF') && !str_contains($htmlSearch, 'FULL-PEND')
);

// A product filter (the report's product_id) still constrains dl_reportSalesData, which is the
// ledger record; assert it here so the "product filter still works" requirement is pinned.
$productFiltered = dl_reportSalesData($db, [
    'date_from' => $DATE, 'date_to' => $DATE, 'branch_id' => $BRANCH,
    'product_id' => $P_OFFICIAL, 'shift' => '', 'accessible_branch_ids' => [$BRANCH],
    'pending_rows_mode' => 'include',
]);
$h->test(
    'the report product filter constrains the ledger record to one product',
    count($productFiltered['rows']) === 1 && (int)$productFiltered['rows'][0]['product_id'] === $P_OFFICIAL
);

// ---------------------------------------------------------------------------
// No false pending: a branch of only no-record products must not show the banner
// ---------------------------------------------------------------------------
$h->section('No false pending');

$lonely = $extract($htmlLonely);
$h->test(
    'a no-record-only branch renders exactly one row with no pending banner',
    $lonely['matching'] === 1 && $lonely['pending_banner'] === false
        && $lonely['official_units'] === 0 && $lonely['pending_units'] === 0,
    json_encode($lonely)
);
$lonelyRow = $rowForSku($htmlLonely, 'FULL-LONE');
$h->test(
    'the only row is labelled no record, never pending',
    $lonelyRow !== '' && str_contains($lonelyRow, 'aria-label="No record"')
        && !str_contains($lonelyRow, 'aria-label="Pending count"'),
    substr(preg_replace('/\s+/', ' ', strip_tags($lonelyRow)), 0, 200)
);

// ---------------------------------------------------------------------------
// F. Read-only: no ledger row appears, and bal_end is still NULL
// ---------------------------------------------------------------------------
$h->section('F — nothing is written back');

$h->test(
    'no ledger row was created for the no-record product',
    (int)$db->query('SELECT COUNT(*) FROM dl_daily_ledger WHERE branch_id = ' . $BRANCH . ' AND product_id = ' . $P_NORECORD . " AND ledger_date = '" . $DATE . "'")->fetchColumn() === 0
);
$h->test(
    "the pending product's bal_end is still NULL after rendering",
    (int)$db->query('SELECT COUNT(*) FROM dl_daily_ledger WHERE branch_id = ' . $BRANCH . ' AND product_id = ' . $P_PENDING . " AND bal_end IS NULL")->fetchColumn() === 1
);
$h->test(
    'the lonely branch has no ledger rows at all',
    (int)$db->query('SELECT COUNT(*) FROM dl_daily_ledger WHERE branch_id = ' . $LONELY_BRANCH)->fetchColumn() === 0
);

// ---------------------------------------------------------------------------
// The canonical labeller: the fourth state is additive, the other three are untouched
// ---------------------------------------------------------------------------
$h->section('The canonical row labeller');

$h->test('dl_salesRowStatusLabel exists', function_exists('dl_salesRowStatusLabel'));
$cases = [
    'no record beats a NULL ending' => [
        ['has_ledger_row' => 0, 'bal_end' => null, 'shift' => 'AM', 'shift_status' => 'finalized'], 'no record'],
    'a real row with a NULL ending is still pending ending' => [
        ['has_ledger_row' => 1, 'bal_end' => null, 'shift' => 'AM', 'shift_status' => 'finalized'], 'pending ending'],
    'an unfinalized PM row is still provisional' => [
        ['has_ledger_row' => 1, 'bal_end' => 5, 'shift' => 'PM', 'shift_status' => 'open'], 'provisional'],
    'a finalized row is still official' => [
        ['has_ledger_row' => 1, 'bal_end' => 5, 'shift' => 'PM', 'shift_status' => 'finalized'], 'official'],
    'a row without the key is labelled exactly as before' => [
        ['bal_end' => 5, 'shift' => 'AM', 'shift_status' => null], 'official'],
];
foreach ($cases as $label => $pair) {
    $got = dl_salesRowStatusLabel($pair[0]);
    $h->test('labeller: ' . $label, $got === $pair[1], 'got ' . var_export($got, true));
}

// ---------------------------------------------------------------------------
// Determinism: the same render produces byte-identical metrics three times
// ---------------------------------------------------------------------------
$h->section('Determinism (3x)');

$runs = [];
for ($i = 0; $i < 3; $i++) {
    $runs[] = $extract($renderSales(['date_from' => $DATE, 'date_to' => $DATE, 'branch_id' => (string)$BRANCH]));
}
$h->test(
    'three renders produce identical metrics',
    $runs[0] === $runs[1] && $runs[1] === $runs[2],
    json_encode($runs)
);
$h->test(
    'three renders agree with the cashier product count',
    $runs[0]['matching'] === count(dl_fetchCashierLedgerRows($db, $BRANCH, $DATE, 'AM')),
    json_encode($runs[0])
);

$cleanup();

$h->done();
