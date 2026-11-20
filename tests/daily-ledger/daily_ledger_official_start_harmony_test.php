<?php

declare(strict_types=1);

/**
 * Daily Ledger — official data start: one rule, applied everywhere.
 *
 * OWNER CRITERION 2026-11-20: "we must ensure the effect in the systems data processing so that
 * everything is in harmony, synced, honest and stable."
 *
 * The failure this guards against is NOT a wrong number on one screen - it is two screens disagreeing
 * about the same boundary. Before this test the module applied the boundary per-branch on the summary
 * screens but still SITE-WIDE on the carry suggestions, the variances default view and the Overview
 * lifetime totals, so a branch that went official later could still have a beginning seeded from its own
 * training period.
 *
 * The test deliberately does NOT read or write the site-wide setting: the module DB guard rightly refuses
 * tenant_module_settings from module code, and the per-branch rule must be provable without it.
 *
 * Each assertion names what breaks if it regresses:
 * - a branch's own date must WIN over the site-wide one in BOTH directions (an established branch must
 *   not lose official history because a newer branch joined later);
 * - an unset own date must INHERIT, not mean "no boundary";
 * - a fleet-wide window must take the EARLIEST start in scope, never the latest, or it hides history;
 * - a single-branch window must use THAT branch's date exactly;
 * - the carry floor must follow the branch, or openings get seeded across its boundary.
 *
 * Fixtures: branches 99901-99903, product 99811, cleaned up in finally.
 */

ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-official-start-harmony', TestHarness::MODE_INTEGRATION, 'baronledger.test');
ob_end_clean();
$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/handlers.php';

$db = app()->db();
$ctx = module('daily-ledger');
if ($ctx) {
    kernel_request_context_set('_activeModuleContext', $ctx);
    app()->setActiveModule('daily-ledger');
    \Ikabud\Kernel\Database\KernelPDO::setActiveModule('daily-ledger');
}

$passed = 0;
$failed = 0;
function chkStart(string $label, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    if ($ok) { $passed++; echo "  PASS  {$label}\n"; return; }
    $failed++; echo "  FAIL  {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

$A = 99901;          // no own date -> inherits the site-wide setting
$B = 99902;          // own date LATER than the training row
$C = 99903;          // own date EARLIER, so the same row is inside its official period
$productId = 99811;
$trainingDate = '2026-10-31';
$boundaryDate = '2026-11-01';
$global = dl_officialDataStart();

$cleanup = function () use ($db, $A, $B, $C, $productId): void {
    $ids = "{$A},{$B},{$C}";
    $db->exec("DELETE FROM dl_commissary_product_ledger WHERE commissary_branch_id IN ({$ids})");
    $db->exec("DELETE FROM dl_daily_ledger WHERE branch_id IN ({$ids})");
    $db->exec("DELETE FROM dl_branches WHERE id IN ({$ids})");
    $db->exec("DELETE FROM dl_products WHERE id = {$productId}");
};
$cleanup();

$insB = $db->prepare(
    'INSERT INTO dl_branches (id, code, name, default_supply_mode, is_commissary, is_active, official_data_start)
     VALUES (:id, :code, :name, \'commissary_supplied\', 1, 1, :official)'
);
$insB->execute([':id' => $A, ':code' => 'ZZA99901', ':name' => 'ZZ BRANCH INHERIT', ':official' => null]);
$insB->execute([':id' => $B, ':code' => 'ZZB99902', ':name' => 'ZZ BRANCH LATER', ':official' => $boundaryDate]);
$insB->execute([':id' => $C, ':code' => 'ZZC99903', ':name' => 'ZZ BRANCH EARLIER', ':official' => '2026-09-01']);

$catId = (int)$db->query('SELECT id FROM dl_product_categories ORDER BY id LIMIT 1')->fetchColumn();
$db->prepare(
    'INSERT INTO dl_products (id, name, sku, category_id, product_category, is_active, sort_order)
     VALUES (:id, :n, :s, :c, \'cake\', 1, 999)'
)->execute([':id' => $productId, ':n' => 'ZZ HARMONY CAKE', ':s' => 'ZZ99811', ':c' => $catId]);

try {
    echo "official data start — one rule everywhere\n";
    echo "  (site-wide setting currently: " . ($global ?? 'unset') . ")\n";

    // ── the rule itself ──────────────────────────────────────────────────────────────────
    chkStart(
        'a branch with no own date INHERITS the site-wide date',
        dl_branchOfficialStart($A) === $global,
        'resolved=' . var_export(dl_branchOfficialStart($A), true)
    );
    chkStart(
        'a branch may set a LATER date of its own',
        dl_branchOfficialStart($B) === $boundaryDate,
        (string)dl_branchOfficialStart($B)
    );
    chkStart(
        'a branch may set an EARLIER date of its own (own date wins both ways)',
        dl_branchOfficialStart($C) === '2026-09-01',
        (string)dl_branchOfficialStart($C)
    );

    // ── fleet vs single-branch windows ────────────────────────────────────────────────────
    chkStart(
        'a single-branch window uses THAT branch\'s date exactly',
        dl_effectiveStartForView([$A, $B, $C], $B) === $boundaryDate,
        (string)dl_effectiveStartForView([$A, $B, $C], $B)
    );
    chkStart(
        'a fleet window takes the EARLIEST start in scope, never the latest',
        dl_effectiveStartForBranches([$B, $C]) === '2026-09-01',
        (string)dl_effectiveStartForBranches([$B, $C])
    );

    // ── the SQL expression used by per-row queries ───────────────────────────────────────
    $expr = dl_officialStartSqlExpr('b');
    chkStart(
        'the per-row SQL expression is a COALESCE over the branch column',
        str_contains($expr, 'COALESCE(b.official_data_start'),
        $expr
    );

    // ── the carry floor follows the BRANCH ───────────────────────────────────────────────
    // A training-period row on the COMMISSARY ledger (this is the table the suggestion reads; a
    // dl_daily_ledger fixture would have made both assertions below pass vacuously at 0 — caught by the
    // first run of this test): 40 counted on hand the day before B's own start of 2026-11-01.
    $insLedger = $db->prepare(
        'INSERT INTO dl_commissary_product_ledger
            (commissary_branch_id, product_id, ledger_date, shift, beg_qty, produced_qty, dispatched_qty, wastage_qty, actual_end_qty)
         VALUES (:b, :p, :d, \'AM\', 40, 0, 0, 0, 40)'
    );
    $insLedger->execute([':b' => $B, ':p' => $productId, ':d' => $trainingDate]);
    $suggestionB = dl_suggestCommissaryBeginning($db, $B, $productId, $boundaryDate);
    chkStart(
        'the carry floor follows the BRANCH boundary (no opening seeded from its training period)',
        $suggestionB === 0,
        'suggestion=' . $suggestionB
    );

    // The SAME row is inside branch C's official period (it starts 2026-09-01), so it must count.
    $insLedger->execute([':b' => $C, ':p' => $productId, ':d' => $trainingDate]);
    $suggestionC = dl_suggestCommissaryBeginning($db, $C, $productId, $boundaryDate);
    chkStart(
        'a branch whose own start is earlier still carries from that history',
        $suggestionC === 40,
        'suggestion=' . $suggestionC
    );

    // ── date validation (the 500 this replaced) ──────────────────────────────────────────
    chkStart('an impossible date is rejected before MySQL sees it', dl_isRealDate('2026-13-45') === false);
    chkStart('February 30 is rejected', dl_isRealDate('2026-02-30') === false);
    chkStart('a real leap day is accepted', dl_isRealDate('2028-02-29') === true);
    chkStart('a real date is accepted', dl_isRealDate('2026-11-01') === true);
} finally {
    $cleanup();
}

$total = $passed + $failed;
echo "\n  {$passed}/{$total} passed\n";
$h->done($failed === 0);
exit($failed === 0 ? 0 : 1);
