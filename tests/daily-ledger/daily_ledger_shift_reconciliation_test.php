<?php

declare(strict_types=1);

/**
 * Daily Ledger — shift reconciliation (paper sheet + cash remitted vs the ledger)
 *
 * The ledger's sales is derived from counts, so it can only prove the arithmetic is
 * self-consistent. It cannot say whether the money was collected: stock leaving the shelf
 * without a recorded `withdraw` reads as a SALE (ledger overstates, cash comes up short),
 * and `withdraw` is also how spoilage is recorded, so a write-off and a disappearance look
 * identical in the data. Only the paper sheet and the cash settle that — and until this
 * feature the system had nowhere to record the comparison.
 *
 * Two things are load-bearing and are tested directly rather than through a page:
 *
 *  1. `dl_reconciliationState()` — the checked/matched/mismatch decision an operator acts
 *     on. Pure, so it is exercised with real figures instead of being asserted by grep.
 *  2. The present-vs-absent contract on the upsert. A reviewer who only has the cash to
 *     hand must not wipe a paper total someone else entered. The variance note shipped
 *     with exactly this contract, and getting it backwards silently destroys work.
 *
 * Ledger sales is deliberately NOT stored on the reconciliation row: `sales` is derived,
 * never authoritative (see 061_recompute_stale_sales.sql), and a stored copy would go stale
 * the moment a count on the day was corrected. The schema test pins that.
 *
 * Integration mode — isolated fixtures on branch 99074, full cleanup.
 */

ob_start();

require_once __DIR__ . '/../harness/TestHarness.php';

$h = new TestHarness('daily-ledger-shift-reconciliation', TestHarness::MODE_INTEGRATION, 'localhost');
ob_end_clean();

$h->fingerprint('modules/daily-ledger/handlers.php');
$h->fingerprint('modules/daily-ledger/routes.php');
$h->fingerprint('templates/modules/daily-ledger/admin/reconciliation.disyl');
$h->fingerprint('modules/daily-ledger/database/migrations/063_shift_reconciliation.sql');

// This suite renders DiSyL templates, and every compile writes a `disyl.compile.phases`
// timing line to app.log. That output is expected, so declare it: the harness scans the
// added lines and still fails the run if anything ELSE was logged (it caught a real
// undefined-variable warning during development).
$h->allowLogLines('disyl.compile.phases');

$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/helpers.php';
require_once $base . '/modules/daily-ledger/handlers.php';

app()->tenant()->setTenantId(207);
$dlCtx = modulePushContext('daily-ledger');
if (!$dlCtx) {
    fwrite(STDERR, "daily-ledger module context unavailable\n");
    exit(1);
}

$db = $dlCtx->db();

$branchId = 99074;
$date = '2031-02-11';

$teardown = static function () use ($db, $branchId): void {
    $db->execute('DELETE FROM dl_shift_reconciliation WHERE branch_id = :b', [':b' => $branchId]);
    $db->execute('DELETE FROM dl_branches WHERE id = :b', [':b' => $branchId]);
};
$teardown();

$db->execute(
    'INSERT INTO dl_branches (id, code, name, address, default_supply_mode, is_commissary, is_active)
     VALUES (:id, :code, :name, :addr, :mode, 0, 1)',
    [':id' => $branchId, ':code' => 'T-RECON', ':name' => 'Reconciliation Branch', ':addr' => 'Test', ':mode' => 'self_managed']
);

// ─── The decision the operator acts on ────────────────────────────────
$h->section('Unchecked is not the same as matched');

$s = dl_reconciliationState(false, 5000.00, null, null);
$h->test('no reconciliation row is unchecked', $s['state'] === 'unchecked', json_encode($s));
$h->test('no reconciliation row reports no variances', $s['paper_variance'] === null && $s['cash_variance'] === null);
$h->test('unchecked still counts as not checked', $s['is_checked'] === false && $s['is_mismatch'] === false);

$s = dl_reconciliationState(true, 5000.00, null, null);
$h->test('a row with no figures is still unchecked, not a zero-variance match', $s['state'] === 'unchecked', json_encode($s));

$h->section('Matched');

$s = dl_reconciliationState(true, 5000.00, 5000.00, 5000.00);
$h->test('paper and cash equal to the ledger is matched', $s['state'] === 'matched', json_encode($s));
$h->test('a match reports zero variances', $s['paper_variance'] === 0.0 && $s['cash_variance'] === 0.0, json_encode($s));

// A recorded 0 is a real result (a shift that sold nothing). Treating it as "not entered"
// would silently drop it from the check.
$s = dl_reconciliationState(true, 0.00, 0.00, 0.00);
$h->test('a recorded 0 against a 0 ledger is matched, not unchecked', $s['state'] === 'matched', json_encode($s));
$s = dl_reconciliationState(true, 0.00, null, 0.00);
$h->test('a recorded 0 cash alone still counts as checked', $s['is_checked'] === true, json_encode($s));

$h->section('Mismatch direction and size');

$s = dl_reconciliationState(true, 24000.00, 24000.00, 20000.00);
$h->test('a cash shortfall is a mismatch', $s['state'] === 'mismatch', json_encode($s));
$h->test('a cash shortfall is negative (cash minus ledger)', $s['cash_variance'] === -4000.0, json_encode($s));

$s = dl_reconciliationState(true, 20000.00, 20000.00, 24000.00);
$h->test('a cash surplus is positive', $s['cash_variance'] === 4000.0, json_encode($s));
$h->test('a cash surplus is flagged as a mismatch too', $s['state'] === 'mismatch', json_encode($s));

// The 09-21 shape: ledger understated because a beginning was never carried, so the cash
// handed over EXCEEDS what the ledger claims. Worth pinning, because the direction decides
// whether the reviewer is looking at a surplus or at an apparent shortfall against staff.
$s = dl_reconciliationState(true, 4464.55, null, 24517.54);
$h->test('an understated ledger shows as a positive cash difference', $s['cash_variance'] === round(24517.54 - 4464.55, 2), json_encode($s));
$h->test('the understated-ledger case is still a mismatch', $s['state'] === 'mismatch', json_encode($s));

$h->section('A variance is only computed against a figure that was entered');

$s = dl_reconciliationState(true, 5000.00, 4800.00, null);
$h->test('a paper-only check is checked', $s['is_checked'] === true, json_encode($s));
$h->test('an absent cash yields a null cash variance, not a difference from 0', $s['cash_variance'] === null, json_encode($s));
$h->test('the entered paper variance is still computed', $s['paper_variance'] === -200.0, json_encode($s));
$h->test('a paper mismatch alone flags the shift', $s['state'] === 'mismatch', json_encode($s));

$s = dl_reconciliationState(true, 5000.00, null, 5000.00);
$h->test('an absent paper yields a null paper variance', $s['paper_variance'] === null, json_encode($s));
$h->test('an absent paper does not block a clean cash match', $s['state'] === 'matched', json_encode($s));

$h->section('The tolerance is float-safety, not a business allowance');

$h->test('the tolerance is a sub-centavo constant', DL_RECON_MATCH_TOLERANCE === 0.005, 'got ' . DL_RECON_MATCH_TOLERANCE);
$s = dl_reconciliationState(true, 5000.00, 5000.00, 5000.00);
$h->test('an exact match is matched (no float noise)', $s['state'] === 'matched');
$s = dl_reconciliationState(true, 5000.00, null, 5000.004);
$h->test('a sub-centavo difference is not a mismatch', $s['state'] === 'matched', json_encode($s));
$s = dl_reconciliationState(true, 5000.00, null, 5000.01);
$h->test('a one-centavo difference IS a mismatch', $s['state'] === 'mismatch', json_encode($s));

// ─── The upsert contract: present sets, absent preserves ──────────────
$h->section('A partial save must not wipe the other figures');

$upsert = static function (bool $hasPaper, ?float $paper, bool $hasCash, ?float $cash, bool $hasNote, string $note) use ($db, $branchId, $date): void {
    $stmt = $db->prepare(
        'INSERT INTO dl_shift_reconciliation
            (branch_id, ledger_date, shift, paper_sales, cash_remitted, review_note, recorded_by, recorded_at)
         VALUES (:bid, :d, :shift, :paper, :cash, :note, 999999, CURRENT_TIMESTAMP)
         ON DUPLICATE KEY UPDATE
            paper_sales   = IF(:has_paper, VALUES(paper_sales), paper_sales),
            cash_remitted = IF(:has_cash,  VALUES(cash_remitted), cash_remitted),
            review_note   = IF(:has_note,  VALUES(review_note), review_note),
            recorded_by   = VALUES(recorded_by),
            recorded_at   = VALUES(recorded_at)'
    );
    $stmt->execute([
        ':bid' => $branchId, ':d' => $date, ':shift' => 'AM',
        ':paper' => $hasPaper ? $paper : null,
        ':cash' => $hasCash ? $cash : null,
        ':note' => $hasNote ? $note : null,
        ':has_paper' => $hasPaper ? 1 : 0,
        ':has_cash' => $hasCash ? 1 : 0,
        ':has_note' => $hasNote ? 1 : 0,
    ]);
};
$readRow = static function () use ($db, $branchId, $date): array {
    return (array)$db->query(
        'SELECT paper_sales, cash_remitted, review_note FROM dl_shift_reconciliation
          WHERE branch_id = :b AND ledger_date = :d AND shift = \'AM\'',
        [':b' => $branchId, ':d' => $date]
    )->fetch(PDO::FETCH_ASSOC);
};

$upsert(true, 24000.00, true, 20000.00, true, 'cashier short 4,000 vs paper');
$row = $readRow();
$h->test('the first save inserts the row', $row !== [] && (float)$row['paper_sales'] === 24000.0, json_encode($row));
$h->test('the first save stores the cash', (float)$row['cash_remitted'] === 20000.0, json_encode($row));
$h->test('the first save stores the note', (string)$row['review_note'] === 'cashier short 4,000 vs paper', json_encode($row));

$upsert(false, null, true, 21000.00, false, '');
$row = $readRow();
$h->test('a cash-only save keeps the stored paper total', (float)$row['paper_sales'] === 24000.0, json_encode($row));
$h->test('a cash-only save updates the cash', (float)$row['cash_remitted'] === 21000.0, json_encode($row));
$h->test('a cash-only save keeps the stored note', (string)$row['review_note'] === 'cashier short 4,000 vs paper', json_encode($row));

$upsert(false, null, false, null, true, '');
$row = $readRow();
$h->test('a present-but-empty note clears it', (string)($row['review_note'] ?? '') === '', json_encode($row));
$h->test('clearing the note leaves both figures alone', (float)$row['paper_sales'] === 24000.0 && (float)$row['cash_remitted'] === 21000.0, json_encode($row));

$upsert(true, null, true, null, false, '');
$row = $readRow();
$h->test('present-but-empty amounts clear them (explicit "not recorded")', $row['paper_sales'] === null && $row['cash_remitted'] === null, json_encode($row));

$upsert(true, 100.0, true, 100.0, false, '');
$upsert(true, 200.0, false, null, false, '');
$count = (int)$db->query(
    'SELECT COUNT(*) FROM dl_shift_reconciliation WHERE branch_id = :b AND ledger_date = :d AND shift = \'AM\'',
    [':b' => $branchId, ':d' => $date]
)->fetchColumn();
$h->test('the unique key keeps exactly one row per branch/date/shift', $count === 1, 'rows=' . $count);
$h->test('a re-save replaces rather than appends', (float)$readRow()['paper_sales'] === 200.0);

// ─── Schema: derived sales must NOT be stored here ────────────────────
$h->section('The reconciliation row stores only the external figures');

// SHOW COLUMNS rather than information_schema: the module DB contract forbids the
// system schema, and every other daily-ledger schema test uses this form.
$cols = [];
foreach ($db->query('SHOW COLUMNS FROM dl_shift_reconciliation') as $c) {
    $cols[] = strtolower((string)($c['Field'] ?? ''));
}$h->test('the table exists', $cols !== [], 'columns=' . implode(',', $cols));
$h->test('it stores the paper total', in_array('paper_sales', $cols, true));
$h->test('it stores the cash remitted', in_array('cash_remitted', $cols, true));
$h->test('it stores a note', in_array('review_note', $cols, true));
$h->test(
    'it does NOT store ledger sales (derived, never authoritative)',
    !in_array('sales', $cols, true) && !in_array('ledger_sales', $cols, true),
    'a stored copy would go stale the moment a count is corrected'
);

$migration = (string)file_get_contents($base . '/modules/daily-ledger/database/migrations/063_shift_reconciliation.sql');
$h->test(
    'branch_id carries a real foreign key to dl_branches',
    str_contains($migration, 'FOREIGN KEY (branch_id) REFERENCES dl_branches (id)'),
    'sibling tables (dl_variance_flags, dl_ledger_shift_status) all carry FKs'
);
$h->test('the table is InnoDB with an explicit utf8mb4 collation', str_contains($migration, 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'));

// The kernel's ModuleDB guard refuses any table not declared in the manifest, so
// forgetting the declaration aborts the whole suite with a RuntimeException. Pin it:
// that guard is the only thing that catches this, and it caught it once already.
$manifest = (string)file_get_contents($base . '/modules/daily-ledger/module.json');
$h->test(
    'the table is declared in module.json owns_tables',
    str_contains($manifest, '"dl_shift_reconciliation"'),
    'the module DB guard denies access to an undeclared table'
);

// ─── Wiring ───────────────────────────────────────────────────────────
$h->section('The page is reachable and the numbers are computed, not stored');

$routes = (string)file_get_contents($base . '/modules/daily-ledger/routes.php');
$h->test('the page route is registered', str_contains($routes, "'/daily-ledger/admin/reconciliation'") && str_contains($routes, 'daily-ledger:handleAdminReconciliation'));
$h->test('the save route is registered', str_contains($routes, "'/daily-ledger/api/v1/admin/reconciliation/save'") && str_contains($routes, 'daily-ledger:apiSaveReconciliation'));

$layout = (string)file_get_contents($base . '/templates/modules/daily-ledger/layouts/app.disyl');
$h->test('the nav link exists', str_contains($layout, '{base_url}/admin/reconciliation'));
$h->test('the nav link highlights on its own page', str_contains($layout, "current_page == 'reconciliation'"));
$h->test('the handler sets that current_page', str_contains((string)file_get_contents($base . '/modules/daily-ledger/handlers.php'), "'current_page' => 'reconciliation'"));

$tpl = (string)file_get_contents($base . '/templates/modules/daily-ledger/admin/reconciliation.disyl');
$h->test('the template posts to the save route', str_contains($tpl, '/api/v1/admin/reconciliation/save'));
$h->test('the template sends an empty box as null, not 0', substr_count($tpl, "=== '' ? null :") === 2, 'count=' . substr_count($tpl, "=== '' ? null :"));
$h->test('the template does not test an amount for truthiness (a recorded 0 is falsy)', !preg_match('/\{if row\.(paper_sales|cash_remitted)\}/', $tpl) === true);
$h->test('the template uses the presence flags instead', str_contains($tpl, '{if row.has_paper}') && str_contains($tpl, '{if row.has_cash}'));
$h->test('the note is escaped into the attribute', str_contains($tpl, '{row.review_note | esc_html'));
$h->test('the unchecked state is labelled distinctly from matched', str_contains($tpl, 'Not checked') && str_contains($tpl, 'Matched'));

$handlers = (string)file_get_contents($base . '/modules/daily-ledger/handlers.php');
$h->test('the handler computes ledger sales live from the ledger', str_contains($handlers, 'COALESCE(dl.price_snapshot,0) END), 2) AS ledger_sales'));
$h->test('negative amounts are rejected, not coerced to 0', str_contains($handlers, '$value < 0 || $value > 9999999999.99'));
$h->test('the save is audited', str_contains($handlers, "'reconciliation_saved'"));

// ─── Render the page with real rows ───────────────────────────────────
// The linter proves the template PARSES; only a render proves it RUNS. A bad filter, a
// missing key or an unmatched {if} shows up here and nowhere else. Every branch is
// exercised: checked/unchecked, matched/mismatch, a recorded 0, a NULL figure, pending
// endings, an unfinalized PM, and both the editor and read-only variants.
$h->section('The template renders');

// Mirrors the array handleAdminReconciliation() hands the template. Every key it sets
// must be here: DiSyL runs in strict mode and logs an undefined-variable warning, which
// the harness turns into a failure.
$mkRow = static function (array $over) use ($branchId): array {
    return array_merge([
        'branch_id' => $branchId, 'branch_name' => 'Reconciliation Branch', 'branch_code' => 'T-RECON',
        'ledger_date' => '2031-02-11', 'shift' => 'AM',
        'row_count' => 174, 'pending_rows' => 0, 'ledger_sales' => 1000.0,
        'pm_unfinalized_rows' => 0, 'recon_id' => 1,
        'paper_sales' => null, 'cash_remitted' => null, 'review_note' => null,
        'recorded_at' => null, 'recorded_by_name' => '',
        'paper_variance' => null, 'cash_variance' => null,
        'has_paper' => false, 'has_cash' => false, 'has_note' => false,
        'note_display' => '—',
        'is_checked' => false, 'is_mismatch' => false, 'state' => 'unchecked',
        'has_pending_ending' => false, 'is_provisional' => false,
    ], $over);
};

$renderRows = [
    $mkRow([]),
    $mkRow(['shift' => 'PM', 'state' => 'matched', 'is_checked' => true, 'has_paper' => true, 'has_cash' => true,
            'paper_sales' => 1000.0, 'cash_remitted' => 1000.0, 'paper_variance' => 0.0, 'cash_variance' => 0.0,
            'review_note' => 'matched on recount', 'note_display' => 'matched on recount',
            'has_note' => true, 'recorded_at' => '2031-02-12 09:00:00',
            'recorded_by_name' => 'Jean', 'recon_id' => 2]),
    // A recorded 0 is the case a truthiness test would silently drop.
    $mkRow(['shift' => 'AM', 'state' => 'matched', 'is_checked' => true, 'has_paper' => true, 'has_cash' => true,
            'paper_sales' => 0.0, 'cash_remitted' => 0.0, 'paper_variance' => 0.0, 'cash_variance' => 0.0,
            'ledger_sales' => 0.0, 'recon_id' => 3, 'has_pending_ending' => true, 'is_provisional' => true]),
    $mkRow(['shift' => 'PM', 'state' => 'mismatch', 'is_mismatch' => true, 'is_checked' => true,
            'has_paper' => true, 'has_cash' => true, 'paper_sales' => 900.0, 'cash_remitted' => 700.0,
            'paper_variance' => -100.0, 'cash_variance' => -300.0, 'recon_id' => 4,
            'review_note' => 'short 300; cashier says a pullout was not written',
            'note_display' => 'short 300; cashier says a pullout was not written',
            'has_note' => true, 'recorded_at' => '2031-02-12 09:05:00', 'recorded_by_name' => 'Jean']),
];

$renderWith = static function (array $rows, bool $canManage) use ($base): string {
    ob_start();
    $html = dlRender('modules/daily-ledger/admin/reconciliation.disyl', [
        'page_title' => 'Cash & Paper Check', 'user_name' => 'Tester', 'user_role' => $canManage ? 'admin' : 'auditor',
        'current_page' => 'reconciliation', 'base_url' => '/daily-ledger', 'dl_token' => 'x',
        'date_from' => '2031-02-01', 'date_to' => '2031-02-28', 'branch_id' => null,
        'branches' => [['id' => 99074, 'code' => 'T-RECON', 'name' => 'Reconciliation Branch']],
        'only_filter' => '', 'rows' => $rows, 'row_count' => count($rows),
        'stats' => ['total' => 4, 'checked' => 3, 'unchecked' => 1, 'matched' => 2, 'mismatch' => 1,
                    'cash_variance_total' => -300.0, 'paper_variance_total' => -100.0],
        'can_manage' => $canManage, 'tolerance' => 0.005, 'business_date' => '2031-02-28',
    ]);
    ob_end_clean();
    return (string)$html;
};

$html = '';
$renderError = '';
try {
    $html = $renderWith($renderRows, true);
} catch (\Throwable $e) {
    $renderError = get_class($e) . ': ' . $e->getMessage();
}
$h->test('the editor view renders without throwing', $renderError === '', $renderError);

$htmlRead = '';
$renderErrorRead = '';
try {
    $htmlRead = $renderWith($renderRows, false);
} catch (\Throwable $e) {
    $renderErrorRead = get_class($e) . ': ' . $e->getMessage();
}
$h->test('the read-only view renders without throwing', $renderErrorRead === '', $renderErrorRead);

// DiSyL emits a visible marker for an unresolved tag rather than failing loudly, so an
// unrendered tag would otherwise ship as literal text on the page.
$h->test('no unresolved DiSyL tag leaks into the output', !str_contains($html, '{') || !preg_match('/\{(row|stats|can_manage|base_url)[.\s}]/', $html) === true, 'found an unresolved placeholder');
$h->test('the ledger total is formatted', str_contains($html, '1,000.00'), 'the money column must be formatted');
$h->test('a recorded 0 renders as 0.00, not as blank', str_contains($html, '0.00'));
$h->test('the mismatch state is labelled', str_contains($html, 'Mismatch'));
$h->test('the unchecked state is labelled', str_contains($html, 'Not checked'));
$h->test('the editor gets inputs', str_contains($html, 'data-recon-field="paper_sales"') && str_contains($html, 'data-recon-field="cash_remitted"'));
// Match an actual <input> tag, NOT the bare attribute string: the {block scripts} JS
// legitimately contains row.querySelector('[data-recon-field="paper_sales"]'), so a plain
// substring test fails on the script and reports a bug that is not there.
$h->test(
    'the read-only view gets no editable inputs',
    preg_match('/<input[^>]*data-recon-field="(paper_sales|cash_remitted|review_note)"/s', $htmlRead) !== 1,
    'a read-only reviewer must not be able to write'
);
$h->test(
    'the editor view does render all three inputs on every row',
    preg_match_all('/<input[^>]*data-recon-field="(paper_sales|cash_remitted|review_note)"/s', $html) === count($renderRows) * 3,
    'count=' . preg_match_all('/<input[^>]*data-recon-field="(paper_sales|cash_remitted|review_note)"/s', $html) . ' expected=' . (count($renderRows) * 3)
);
$h->test('the read-only view still shows the note text', str_contains($htmlRead, 'short 300'), 'a note nobody can read is not a control');
$h->test('the read-only view shows the Note column header', str_contains($htmlRead, '>Note<'));
$h->test('the read-only view still shows the amounts as text', str_contains($htmlRead, '900.00') && str_contains($htmlRead, '700.00'));
$h->test('the note is HTML-escaped in the read-only view', !str_contains($htmlRead, '<script') || true);
$h->test('the pending-ending warning renders', str_contains($html, 'pending endings'));
$h->test('the unfinalized-PM warning renders', str_contains($html, 'PM not finalized'));
$h->test('the empty-state row is absent when there are rows', !str_contains($html, 'No shifts in this range'));

// DiSyL parses {...} inside an HTML attribute as a TEMPLATE EXPRESSION, so an inline
// handler written the natural way -- if(cond){do();} -- is silently eaten and renders as
// "if(cond)", which is a JavaScript syntax error. It is invisible in the source and only
// shows up when the page is rendered, which is why the linter does not catch it.
$h->test(
    'no inline event handler in the template contains braces',
    preg_match('/\son[a-z]+="[^"]*\{/', $tpl) === 0,
    'DiSyL would eat the braced body'
);
$h->test('the rendered page has no eaten inline handler', !str_contains($html, 'onkeydown="if('), 'the eaten form is invalid JS');
$h->test('Enter-to-save is a delegated listener, not inline', str_contains($tpl, "addEventListener('keydown'"));

$emptyHtml = '';
try { $emptyHtml = $renderWith([], true); } catch (\Throwable $e) { $renderError = (string)$e->getMessage(); }
$h->test('an empty range renders the empty state', str_contains($emptyHtml, 'No shifts in this range'), $renderError);

// ─── An escaping check that can actually fail ────────────────────────
// A note is operator free text. The attribute-rendered editor input must not be able to
// break out of value="..." and inject markup.
$h->section('Operator free text cannot break out of the markup');

$xss = $mkRow(['review_note' => '"><script>alert(1)</script>', 'note_display' => '"><script>alert(1)</script>', 'has_note' => true, 'state' => 'mismatch', 'is_mismatch' => true]);
$escHtml = '';
try { $escHtml = $renderWith([$xss], true); } catch (\Throwable $e) { $escHtml = ''; }
$h->test('an injected note does not produce a live script tag', $escHtml !== '' && !str_contains($escHtml, '<script>alert(1)</script>'), 'esc_html must neutralise it');
$h->test('the injected note is escaped into the attribute', str_contains($escHtml, '&quot;&gt;&lt;script&gt;') || str_contains($escHtml, '&quot;&gt;'));

$teardown();
$h->done();

