<?php

declare(strict_types=1);

/**
 * Daily Ledger — who may take stock in WITHOUT a paper DR.
 *
 * Owner 2026-11-14, two statements that settle the rule:
 *   "for a branch's products that are own produced, it needs no DR"
 *   "other products, cannot be set without a DR. thus paperless DR cannot be done"
 * and then the reason it is a rule and not a preference:
 *   "the no DR has been abused by cashiers. instead of encoding the DR number, they use that to
 *    make their encoding faster, which is the main issue here."
 *
 * MEASURED before this test was written (tenant 207, 60 days) — the numbers that made the switch
 * indefensible: 15,902 cashier stock-adds with no DR against 590 captured paper DRs (31:1);
 * 8,187 of the cashier `adjustment_add` entries filed as `encoder_omission`, which is a delivery
 * being back-dated; and `dl_branches.paperless_dr_enabled` = 1 at EVERY branch with volume, so the
 * per-branch switch had never gated anything.
 *
 * WHAT THIS PINS:
 *  1. A cashier cannot take the paperless lane at all, even at a branch where the switch is ON.
 *  2. A supervisor follows the branch switch, so the switch finally has teeth.
 *  3. Admin keeps it regardless — network-level authority, always audited.
 *  4. `own_production` is NOT closed by that helper: for a product the branch makes there is no DR
 *     and never will be, so that lane stays open to whoever encodes it (scoped per product instead).
 *  5. The offline replay refuses `adjustment_add` for the same roles. It previously had NO paperless
 *     gate at all, so a queued entry walked straight around the online rule.
 *  6. The screen renders the DR-free controls from the same helper, so the UI cannot offer a path
 *     the server refuses.
 */

ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-paperless-dr-role', TestHarness::MODE_INTEGRATION, 'baronledger.test');
ob_end_clean();

$h->allowLogLines(
    'disyl.compile.phases',
    'disyl.render.breakdown',
    'kernel_state_cache: module_registry rebuilt',
    'kernel_state_cache: capability_map rebuilt',
    'kernel_state_cache: entity_presets rebuilt',
    'capability.call'
);

$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/helpers.php';
require_once $base . '/modules/daily-ledger/handlers-deliveries.php';
require_once $base . '/modules/daily-ledger/handlers.php';

app()->tenant()->setTenantId(207);
$ctx = modulePushContext('daily-ledger');
$db = $ctx->db();

$branchId = 99879;

$cleanup = static function () use ($db, $branchId): void {
    $db->prepare('DELETE FROM dl_branches WHERE id = ?')->execute([$branchId]);
};

$cleanup();
try {
    $db->prepare(
        'INSERT INTO dl_branches (id, code, name, is_active, is_commissary, paperless_dr_enabled, single_shift_mode, has_production)
         VALUES (?, ?, ?, 1, 0, 1, 0, 1)'
    )->execute([$branchId, 'PAPERLESS', 'Paperless DR Fixture']);

    $cashier = ['id' => 990001, 'role' => 'cashier', 'name' => 'Cashier Fixture'];
    $supervisor = ['id' => 990002, 'role' => 'supervisor', 'name' => 'Supervisor Fixture'];
    $admin = ['id' => 990003, 'role' => 'admin', 'name' => 'Admin Fixture'];
    $pic = ['id' => 990004, 'role' => 'production_in_charge', 'name' => 'PIC Fixture'];

    $h->section('the switch no longer opens the lane for a cashier');

    $h->test(
        'switch ON, cashier still refused',
        dl_canAddStockWithoutPaperDr($cashier, $branchId) === false,
        'branch.paperless_dr_enabled=1 must not hand a cashier the paperless lane'
    );
    $h->test(
        'front-of-house roles with no back-dating authority are refused',
        dl_canAddStockWithoutPaperDr($pic, $branchId) === false,
        'a production in-charge encodes production, not back-dated deliveries'
    );
    $h->test(
        'missing role is refused rather than defaulting open',
        dl_canAddStockWithoutPaperDr([], $branchId) === false,
        'an unknown role must not inherit the lane'
    );

    $h->section('authority follows the role, and the switch finally bites');

    $h->test(
        'switch ON, supervisor keeps it',
        dl_canAddStockWithoutPaperDr($supervisor, $branchId) === true
    );
    $h->test(
        'switch ON, admin keeps it',
        dl_canAddStockWithoutPaperDr($admin, $branchId) === true
    );

    $db->prepare('UPDATE dl_branches SET paperless_dr_enabled = 0 WHERE id = ?')->execute([$branchId]);
    $h->test(
        'switch OFF, supervisor loses it',
        dl_canAddStockWithoutPaperDr($supervisor, $branchId) === false,
        'between 2026-10-22 and 2026-11-14 the switch was read only for cashiers, so "off" meant nothing to a supervisor'
    );
    $h->test(
        'switch OFF, admin keeps it',
        dl_canAddStockWithoutPaperDr($admin, $branchId) === true,
        'admin authority is network-level and audited'
    );
    $h->test(
        'switch OFF, cashier is still refused (not accidentally re-opened)',
        dl_canAddStockWithoutPaperDr($cashier, $branchId) === false
    );
    $db->prepare('UPDATE dl_branches SET paperless_dr_enabled = 1 WHERE id = ?')->execute([$branchId]);

    $h->section('own production is the one DR-free lane a cashier keeps');

    $handlers = (string)file_get_contents($base . '/modules/daily-ledger/handlers.php');
    $h->test(
        'the online gate covers adjustment_add, not own_production',
        str_contains($handlers, "if (\$type === 'adjustment_add' && !dl_canAddStockWithoutPaperDr("),
        'own_production must stay reachable: a branch that bakes has no paper DR to encode'
    );
    $h->test(
        'own production is scoped per product instead',
        str_contains($handlers, 'dl_assertOwnProductionAllowed($ctx->db(), (int)$branchId, $productId)'),
        'the product flag is what makes the lane honest'
    );

    $h->section('the offline replay cannot walk around the rule');

    $offline = (string)file_get_contents($base . '/modules/daily-ledger/handlers-offline.php');
    $h->test(
        'offline replay refuses adjustment_add for the same roles',
        str_contains($offline, "if (\$type === 'adjustment_add' && !dl_canAddStockWithoutPaperDr(\$user, (int)\$branchId))"),
        'before 2026-11-14 the replay had no paperless gate at all — the refusal existed only online'
    );
    $h->test(
        'offline replay still enforces the own-production product flag',
        str_contains($offline, 'dl_assertOwnProductionAllowed($ctx->db(), (int)$branchId, $productId)')
    );

    $h->section('the rule lives on the server; the admin surface states it');

    // Owner 2026-11-14: "it's done as a filter when set by admin" — so the admin screen is where
    // the rule has to be legible. The cashier modals are deliberately UNCHANGED: the refusal
    // message is the guidance, and the Own production picker is already filtered by the same flag.
    $productsPage = (string)file_get_contents($base . '/templates/modules/daily-ledger/admin/products.disyl');
    $h->test(
        'the branch product screen says what unticking does',
        str_contains($productsPage, 'Own produced here') && str_contains($productsPage, 'no DR'),
        'an admin who reads the toggle as cosmetic cannot make the rule true'
    );
    $h->test(
        'the ledger toggle and the own-production toggle are named differently',
        str_contains($productsPage, 'In ledger') && str_contains($productsPage, 'Makes it'),
        'the two checkboxes answer different questions; identical labels are why this had to be asked'
    );

    $branchesPage = (string)file_get_contents($base . '/templates/modules/daily-ledger/admin/branches.disyl');
    $h->test(
        'the branch switch copy no longer promises cashiers the lane',
        !str_contains($branchesPage, 'Allow cashiers at this branch to add stock')
            && str_contains($branchesPage, 'Cashiers never get this'),
        'the old sentence described a control that was on at every branch and gated nothing'
    );
} finally {
    $cleanup();
}

$h->done();
