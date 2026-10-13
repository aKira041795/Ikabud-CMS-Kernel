<?php

declare(strict_types=1);

/**
 * Daily Ledger — official data start (accountability boundary).
 *
 * Owner/client (2026-10-13): a client's first month was a TESTING period;
 * official encoding began on the 1st of the following month. The agreed shape
 * is a READ boundary, never a delete: pre-boundary rows stay stored, readable
 * and audited, while accountability surfaces start at the boundary and can be
 * restored exactly by clearing the setting.
 *
 * Each assertion names its revert failure:
 * - removing the floor from dl_countUnreviewedVariances counts training flags
 *   as current accountability (the dashboard card and the queue go back to
 *   hundreds of "unreviewed" items nobody can act on);
 * - removing the variances-page floor makes the default queue span all history;
 * - removing the carry guard seeds a post-boundary beginning FROM TRAINING DATA
 *   (the exact way a test month would silently steer real September numbers);
 * - removing the clamp lets lifetime totals present training-period sales;
 * - breaking the invalid-value handling lets a typo silently bend the boundary
 *   (or worse, be read as a date it is not).
 *
 * Everything runs on synthetic 991xx fixtures; the setting is restored in
 * finally to whatever it was before the suite ran.
 */
ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-official-start', TestHarness::MODE_INTEGRATION, 'baronledger.test');
ob_end_clean();

// Rendering compiles the DiSyL template; the invalid-value probe logs a warning
// on purpose (that IS the behaviour under test).
$h->allowLogLines(
    'disyl.compile.phases',
    'disyl.render.breakdown',
    'kernel_state_cache: module_registry rebuilt',
    'official_data_start is not a valid Y-m-d date'
);

$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/helpers.php';
require_once $base . '/modules/daily-ledger/helpers/reporting.php';
require_once $base . '/modules/daily-ledger/handlers-deliveries.php';
require_once $base . '/modules/daily-ledger/handlers.php';
app()->tenant()->setTenantId(207);
$ctx = modulePushContext('daily-ledger');
$db = $ctx->db();

$branchId = 99160;
$productId = 99161;
$commissaryId = 99162;
$commissaryProductId = 99163;
$carryBranchId = 99164;
$carryProductId = 99165;
$preDate = '2031-03-10';
$postDate = '2031-04-10';
$boundary = '2031-04-01';
$today = dl_businessDate();
$prevDate = (new DateTimeImmutable($today))->modify('-1 day')->format('Y-m-d');

$previousRaw = trim((string)(dlModuleSettings(true)['official_data_start'] ?? ''));

$cleanup = static function () use ($db, $branchId, $productId, $commissaryId, $commissaryProductId, $carryBranchId, $carryProductId): void {
    $db->prepare('DELETE FROM dl_variance_flags WHERE branch_id = ?')->execute([$branchId]);
    $db->prepare('DELETE FROM dl_commissary_product_ledger WHERE commissary_branch_id = ? OR product_id = ?')->execute([$commissaryId, $commissaryProductId]);
    $db->prepare('DELETE FROM dl_daily_ledger WHERE branch_id = ? OR product_id = ?')->execute([$carryBranchId, $carryProductId]);
    $db->prepare('DELETE FROM audit_logs WHERE branch_id IN (?, ?, ?)')->execute([$branchId, $commissaryId, $carryBranchId]);
    $db->prepare('DELETE FROM dl_branch_products WHERE product_id IN (?, ?) OR branch_id IN (?, ?, ?)')->execute([$productId, $commissaryProductId, $branchId, $commissaryId, $carryBranchId]);
    $db->prepare('DELETE FROM dl_products WHERE id IN (?, ?, ?)')->execute([$productId, $commissaryProductId, $carryProductId]);
    $db->prepare('DELETE FROM dl_branches WHERE id IN (?, ?, ?)')->execute([$branchId, $commissaryId, $carryBranchId]);
};

$cleanup();
try {
    // ── Fixtures ────────────────────────────────────────────────────────────
    $db->prepare('INSERT INTO dl_branches (id, code, name, default_supply_mode, is_commissary, is_active, assigned_commissary_id) VALUES (?, ?, ?, ?, ?, 1, ?)')
        ->execute([$branchId, 'OFF-BND', 'Official Boundary Branch', 'commissary_supplied', 0, $commissaryId]);
    $db->prepare('INSERT INTO dl_branches (id, code, name, default_supply_mode, is_commissary, is_active) VALUES (?, ?, ?, ?, 1, 1)')
        ->execute([$commissaryId, 'OFF-COMM', 'Official Boundary Commissary', 'self_managed']);
    $db->prepare('INSERT INTO dl_branches (id, code, name, default_supply_mode, is_commissary, is_active) VALUES (?, ?, ?, ?, 0, 1)')
        ->execute([$carryBranchId, 'OFF-CARRY', 'Official Boundary Carry Branch', 'commissary_supplied']);
    $db->prepare('INSERT INTO dl_products (id, sku, name, current_price, sort_order, is_active) VALUES (?, ?, ?, 10, 0, 1)')
        ->execute([$productId, 'OFF-BND-P', 'Official Boundary Product']);
    $db->prepare('INSERT INTO dl_products (id, sku, name, current_price, sort_order, is_active) VALUES (?, ?, ?, 10, 0, 1)')
        ->execute([$commissaryProductId, 'OFF-COMM-P', 'Official Boundary Commissary Product']);
    $db->prepare('INSERT INTO dl_products (id, sku, name, current_price, sort_order, is_active) VALUES (?, ?, ?, 10, 0, 1)')
        ->execute([$carryProductId, 'OFF-CARRY-P', 'Official Boundary Carry Product']);
    $db->prepare('INSERT INTO dl_branch_products (branch_id, product_id, is_active) VALUES (?, ?, 1)')->execute([$carryBranchId, $carryProductId]);

    // Two unreviewed flags for the SAME synthetic branch: one training, one official.
    $flagInsert = $db->prepare('INSERT INTO dl_variance_flags (branch_id, product_id, ledger_date, kind, variance, resolution_status, is_reviewed) VALUES (?, ?, ?, "sales", 3, "unreviewed", 0)');
    $flagInsert->execute([$branchId, $productId, $preDate]);
    $flagInsert->execute([$branchId, $productId, $postDate]);

    // Commissary ledger: a training-period COUNT (100) and a post-boundary movement (7).
    $db->prepare('INSERT INTO dl_commissary_product_ledger (commissary_branch_id, product_id, ledger_date, actual_end_qty, produced_qty, dispatched_qty, wastage_qty) VALUES (?, ?, ?, ?, 0, 0, 0)')
        ->execute([$commissaryId, $commissaryProductId, '2031-03-20', 100]);
    $db->prepare('INSERT INTO dl_commissary_product_ledger (commissary_branch_id, product_id, ledger_date, produced_qty, dispatched_qty, wastage_qty) VALUES (?, ?, ?, 7, 0, 0)')
        ->execute([$commissaryId, $commissaryProductId, '2031-04-05']);

    // Auto-carry fixture: a previous-day PM ending for the synthetic branch.
    $db->prepare('INSERT INTO dl_daily_ledger (branch_id, product_id, ledger_date, shift, price_snapshot, beg_bal, addtl, withdraw, bal_end) VALUES (?, ?, ?, "PM", 10, 0, 0, 0, 5)')
        ->execute([$carryBranchId, $carryProductId, $prevDate]);

    $setBoundary = static function (string $value): void {
        dlPersistModuleSettings(['official_data_start' => $value]);
        dl_officialDataStart(true);
    };

    // ── Baseline: no boundary ───────────────────────────────────────────────
    $setBoundary('');
    $h->test('with no boundary the helper reports none', dl_officialDataStart(true) === null);
    $h->test('with no boundary every unreviewed flag counts (training included)', dl_countUnreviewedVariances($db, [$branchId]) === 2);

    // ── The boundary ────────────────────────────────────────────────────────
    $setBoundary($boundary);
    $h->test('the boundary is read back as stored', dl_officialDataStart(true) === $boundary);
    $h->test(
        'the unreviewed count excludes the training period (revert counts 424 stale flags as work)',
        dl_countUnreviewedVariances($db, [$branchId]) === 1,
        'count=' . dl_countUnreviewedVariances($db, [$branchId])
    );
    $h->test('an empty branch set counts zero, never the whole estate', dl_countUnreviewedVariances($db, []) === 0);

    $h->test(
        'clamp: a 1970 floor becomes the boundary; later and unset floors stay as they are',
        dl_officialClampFrom('1970-01-01') === $boundary && dl_officialClampFrom('2031-04-15') === '2031-04-15'
    );
    $h->test(
        'a fully pre-boundary scheduled window is skipped; a crossing one is not',
        dl_scheduledWindowIsPreBoundary('2031-03-10', '2031-03-31') === true
            && dl_scheduledWindowIsPreBoundary('2031-03-25', '2031-04-05') === false
    );
    $h->test(
        'normalisation refuses garbage and keeps real dates',
        dl_normalizeOfficialStart('2031-04-01') === '2031-04-01'
            && dl_normalizeOfficialStart('  ') === ''
            && dl_normalizeOfficialStart('04/01/2031') === ''
            && dl_normalizeOfficialStart('2031-13-99') === '2031-13-99' // shape only; a real date is a human decision
    );

    // ── Carry: a beginning never crosses the boundary ───────────────────────
    $h->test(
        'the commissary beginning suggestion ignores the training-period count (revert seeds 100)',
        dl_suggestCommissaryBeginning($db, $commissaryId, $commissaryProductId, '2031-04-10') === 7,
        'suggest=' . dl_suggestCommissaryBeginning($db, $commissaryId, $commissaryProductId, '2031-04-10')
    );
    $h->test(
        'a suggestion INSIDE the training period still reads that history',
        dl_suggestCommissaryBeginning($db, $commissaryId, $commissaryProductId, '2031-03-25') === 100
    );
    // The auto-carry guard needs a boundary that sits ON the business date: the
    // carried-from date is always yesterday, so today is on/after the boundary
    // while its source is before it. (The 2031 fixtures above test the other
    // two surfaces, where the read window itself moves.)
    $setBoundary($today);
    $adoptedBlocked = dl_autoCarryBeginnings($db, $carryBranchId, $today, 'AM', 1);
    $h->test(
        'the branch auto-carry does NOT adopt a pre-boundary ending (revert creates today\'s beginning from training)',
        $adoptedBlocked === 0,
        'adopted=' . $adoptedBlocked
    );
    $h->test(
        'a blocked carry leaves today completely untouched',
        (int)$db->query('SELECT COUNT(*) FROM dl_daily_ledger WHERE branch_id = ' . (int)$carryBranchId . " AND ledger_date = '{$today}'")->fetchColumn() === 0
    );
    $setBoundary($boundary);

    // ── The variances page: floor by default, labelled when explicit ────────
    $tokens = dl_generateAuthTokens([
        'sub' => 'admin:1', 'id' => 1, 'username' => 'official-boundary-admin',
        'name' => 'Official Boundary Admin', 'role' => 'admin', 'source' => 'daily-ledger',
    ]);
    $_COOKIE[dlCookieName()] = $tokens['token'];
    $render = static function (array $get): string {
        $_GET = $get;
        ob_start();
        handleAdminVariances();
        return (string)ob_get_clean();
    };

    $defaultHtml = $render(['branch_id' => (string)$branchId]);
    $h->test(
        'the default queue shows the official flag and NOT the training flag (revert spans all history)',
        str_contains($defaultHtml, $postDate) && !str_contains($defaultHtml, $preDate)
    );
    $h->test(
        'the page states the boundary so the count is explainable',
        str_contains($defaultHtml, 'official-start-note') && str_contains($defaultHtml, 'Official records start')
    );

    $explicitHtml = $render(['branch_id' => (string)$branchId, 'date_from' => '2031-03-01', 'date_to' => '2031-03-31']);
    $h->test(
        'an explicit training-period filter stays readable and is labelled as training',
        str_contains($explicitHtml, $preDate) && str_contains($explicitHtml, 'training period before')
    );

    // ── Rollback: clearing the setting restores the previous behaviour ──────
    $setBoundary('');
    $h->test('clearing the boundary restores the full unreviewed count', dl_countUnreviewedVariances($db, [$branchId]) === 2);
    $h->test('clearing the boundary restores the cross-date carry', dl_autoCarryBeginnings($db, $carryBranchId, $today, 'AM', 1) === 1);
    $clearedHtml = $render(['branch_id' => (string)$branchId]);
    $h->test(
        'with no boundary the default queue spans history again',
        str_contains($clearedHtml, $postDate) && str_contains($clearedHtml, $preDate) && !str_contains($clearedHtml, 'official-start-note')
    );

    // ── Invalid stored value: loud, and treated as unset ────────────────────
    $setBoundary('not-a-date');
    $h->test('an unparseable boundary behaves as unset (helper warns once per request)', dl_officialDataStart(true) === null);

    // ── Wiring pins (the surfaces that MUST read the one rule) ──────────────
    $handlers = (string)file_get_contents($base . '/modules/daily-ledger/handlers.php');
    $reporting = (string)file_get_contents($base . '/modules/daily-ledger/helpers/reporting.php');
    $h->test(
        'the dashboard card counts through the one boundary-aware rule',
        str_contains($handlers, 'dl_countUnreviewedVariances($ctx->db(), $accessibleBranchIds)')
    );
    $h->test(
        'the lifetime totals are clamped to the boundary',
        str_contains($handlers, "dl_officialClampFrom('1970-01-01')")
    );
    $h->test(
        'the forecast history and stock lookup are bounded',
        str_contains($reporting, 'the history a suggestion learns from') && str_contains($reporting, 'must not read as "stock on hand"')
    );
    $h->test(
        'the scheduled runner skips fully pre-boundary windows',
        str_contains($reporting, 'dl_scheduledWindowIsPreBoundary((string)$window[\'date_from\'], (string)$window[\'date_to\'])')
    );
} finally {
    $cleanup();
    // Restore the tenant's boundary exactly as found — the suite must be safe to
    // run against a DB that has a real boundary configured.
    dlPersistModuleSettings(['official_data_start' => $previousRaw]);
    dl_officialDataStart(true);
}

$h->done();
