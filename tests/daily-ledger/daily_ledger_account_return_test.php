<?php

declare(strict_types=1);

/**
 * Daily Ledger — an account (consignee) return may credit branch stock, atomically.
 *
 * Client concern #2 as restated 2026-11-10: accounts must be usable as a stock SOURCE in
 * "where stock came from", with a DR, and restricted to the branch's own supply line ("Rizal only,
 * not other branches"). Owner directive: "allow cashiers". Then: "take the simplest path but
 * elegantly done".
 *
 * THE DESIGN DECIDED (and why it is the simple one)
 *   No migration, no `dl_deliveries.origin_type` change, no new table. An account return that must
 *   enter a branch is TWO writes - the account's outstanding balance falls, the branch ledger
 *   rises - and the existing code already offers the seam for making them atomic:
 *   dl_recordConsigneeReturn() takes `$ownsTransaction = !$db->inTransaction()`, so a caller that
 *   opens the transaction keeps commit control.
 *
 * The damage if this regresses:
 * - if the branch credit runs outside the transaction, a failure leaves the account debited with
 *   the goods never returned to the branch — the same class as concern #8's phantom stock;
 * - if the supply-line check is dropped, an account from another commissary can pump stock into a
 *   branch it has no relationship with;
 * - if the DR requirement is dropped, branch inventory rises with no document.
 */

ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-account-return', TestHarness::MODE_INTEGRATION, 'baronledger.test');
ob_end_clean();

$h->allowLogLines(
    'disyl.compile.phases',
    'disyl.render.breakdown',
    'kernel_state_cache: module_registry rebuilt',
    'kernel_state_cache: capability_map rebuilt',
    'kernel_state_cache: entity_presets rebuilt'
);

$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/helpers.php';
require_once $base . '/modules/daily-ledger/handlers-deliveries.php';
require_once $base . '/modules/daily-ledger/handlers.php';

app()->tenant()->setTenantId(207);
$ctx = modulePushContext('daily-ledger');
$db = $ctx->db();

$tpl = (string) file_get_contents($base . '/modules/daily-ledger/handlers-deliveries.php');
$core = (string) file_get_contents($base . '/modules/daily-ledger/handlers.php');

/** Extract one function body so assertions cannot leak into neighbouring functions. */
$bodyOf = static function (string $src, string $fn): string {
    $start = strpos($src, 'function ' . $fn . '(');
    if ($start === false) {
        return '';
    }
    $next = strpos($src, "\nfunction ", $start + 10);
    return substr($src, $start, ($next === false ? strlen($src) : $next) - $start);
};
$returnBody  = $bodyOf($tpl, 'apiRecordConsigneeReturn');
$correctBody = $bodyOf($tpl, 'apiCorrectConsigneeDeliveryDiscrepancy');
$writerBody  = $bodyOf($core, 'dl_recordConsigneeReturn');

$h->section('the endpoint is wired as designed');
$h->test(
    'cashiers may record an account return',
    str_contains($returnBody, "dlCurrentUser(['admin', 'supervisor', 'cashier'])"),
    'owner directive 2026-11-10: "allow cashiers"'
);
$h->test(
    'the office-only correction path did NOT gain cashier',
    $correctBody !== '' && !str_contains($correctBody, "'cashier'"),
    'client #3: only the office may correct a delivery quantity'
);
$h->test(
    'a DR is required when the return enters a branch',
    str_contains($tpl, 'Delivery Receipt number is required when returned stock enters a branch'),
    'branch inventory must not rise without a document'
);
$h->test(
    'an account may only return to the branch that SUPPLIED it',
    str_contains($returnBody, 'SELECT assigned_commissary_id FROM dl_consignees')
        && str_contains($returnBody, '(int)$supplying !== $branchId')
        && str_contains($returnBody, 'is not supplied by this branch'),
    'a supply-LINE comparison was wrong both ways on live data: RIZAL-COMMIS carries assigned_commissary_id NULL so it was rejected, and ordinary Dipolog branches on the same line were admitted'
);
/*
 * THE SPOILAGE FIX. A return used to adjust only the account, and the dispatch report valued it as
 * a write-off, so goods physically back on the shelf never reached branch inventory. The credit now
 * lives in the ONE custody writer so every caller inherits it, and the endpoint must NOT add a
 * second one - that would double the stock the moment the UI starts using it.
 */
$h->test(
    'the endpoint does not credit the branch itself',
    !str_contains($returnBody, 'dl_applyLedgerDelta('),
    'the writer already does it; a second credit here would double the branch stock'
);
$h->test(
    'the ONE custody writer credits the supplying branch',
    str_contains($writerBody, "dl_applyLedgerDelta((int)\$sourceBranchId, \$productId, \$ledgerDate, \$delta, \$actorId, 'addtl', \$shift)"),
    'so the admin dispatch report and the cashier return panel cannot drift apart'
);
$h->test(
    'spoilage stays a separate, real fact',
    str_contains($core, "'spoilage' => 'Spoilage'"),
    'a return is stock back on the shelf; spoiled goods are a withdrawal with reason_code spoilage/damage'
);

$h->section('the panel is fed by what the account actually holds');
$custodyBody = $bodyOf($tpl, 'apiConsigneeCustody');
$h->test('cashiers can read the custody list', str_contains($custodyBody, "dlCurrentUser(['admin', 'supervisor', 'cashier'])")); 
$h->test(
    'the account list is scoped to the branch that supplies them',
    str_contains($custodyBody, 'c.assigned_commissary_id = :b'),
    'a branch must never be offered accounts it did not supply'
);
$h->test(
    'only stock still held is offered',
    substr_count($custodyBody, 'HAVING SUM(l.addtl - l.withdraw) > 0') >= 2,
    'both the account totals and the line detail must exclude settled rows'
);
$h->test(
    'a locked shift is shown before the cashier types',
    str_contains($custodyBody, 'dl_shiftIsFinalized') && str_contains($custodyBody, "'locked' =>"),
    'the write refuses a finalized shift, so the dead end must be visible up front'
);

$h->section('atomicity — the property the whole design rests on');
$branch   = 1882000100 + random_int(1, 400);
$product  = (int) $db->query('SELECT id FROM dl_products WHERE is_active = 1 ORDER BY id LIMIT 1')->fetchColumn();
$date     = dl_businessDate();

// Self-heal by fixed code in case an earlier run aborted inside its restore.
foreach ([
    "DELETE FROM dl_daily_ledger WHERE branch_id IN (SELECT id FROM dl_branches WHERE code LIKE 'AR-%')",
    "DELETE FROM audit_logs WHERE module = 'daily-ledger' AND branch_id IN (SELECT id FROM dl_branches WHERE code LIKE 'AR-%')",
    "DELETE FROM dl_branches WHERE code LIKE 'AR-%'",
] as $heal) {
    $db->prepare($heal)->execute();
}

try {
    $db->prepare(
        'INSERT INTO dl_branches (id, code, name, default_supply_mode, is_commissary, is_active) VALUES (:id, :c, :n, "commissary_supplied", 0, 1)'
    )->execute([':id' => $branch, ':c' => 'AR-TEST', ':n' => 'Account Return Test']);

    $read = static function () use ($db, $branch, $product, $date): int {
        $s = $db->prepare('SELECT COALESCE(SUM(addtl),0) FROM dl_daily_ledger WHERE branch_id = :b AND product_id = :p AND ledger_date = :d');
        $s->execute([':b' => $branch, ':p' => $product, ':d' => $date]);
        return (int) $s->fetchColumn();
    };

    // 1. The branch credit JOINS an already-open transaction rather than committing its own.
    $db->beginTransaction();
    dl_applyLedgerDelta($branch, $product, $date, 5, 1, 'addtl', 'AM');
    $db->rollBack();
    $h->test(
        'a rolled-back branch credit leaves addtl untouched',
        $read() === 0,
        'read ' . $read() . ' - if this is 5 the writer commits independently and the pair is NOT atomic'
    );

    // 2. Committed, it lands exactly once.
    $db->beginTransaction();
    dl_applyLedgerDelta($branch, $product, $date, 5, 1, 'addtl', 'AM');
    $db->commit();
    $h->test('a committed branch credit lands exactly once', $read() === 5, 'addtl=' . $read());

    // 3. It is attributable - the point of concern #1.
    $audit = $db->prepare("SELECT COUNT(*) FROM audit_logs WHERE module='daily-ledger' AND action='ledger_delta_applied' AND branch_id = :b");
    $audit->execute([':b' => $branch]);
    $h->test('the credit is attributable in Activity', (int) $audit->fetchColumn() >= 1, 'no ledger_delta_applied row for the branch');
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    foreach ([
        "DELETE FROM dl_daily_ledger WHERE branch_id IN (SELECT id FROM dl_branches WHERE code LIKE 'AR-%')",
        "DELETE FROM audit_logs WHERE module = 'daily-ledger' AND branch_id IN (SELECT id FROM dl_branches WHERE code LIKE 'AR-%')",
        "DELETE FROM dl_branches WHERE code LIKE 'AR-%'",
    ] as $clean) {
        $db->prepare($clean)->execute();
    }
}

$h->section('a return puts the stock back on the branch shelf');
/*
 * The client-visible outcome of the spoilage fix, stated as an outcome and not a mechanism:
 * the account owes 10, gives 4 back, so the branch holds 4 MORE than before the return. Before the
 * fix that number stayed at 0 while the account's debt fell - the bread was back on the shelf and
 * nowhere in branch inventory.
 */
$consignee = 1882000200 + random_int(1, 400);
try {
    $db->prepare(
        'INSERT INTO dl_branches (id, code, name, default_supply_mode, is_commissary, is_active) VALUES (:id, "AR-SUP", "Account Return Supply", "commissary_supplied", 0, 1)'
    )->execute([':id' => $branch]);
    $db->prepare(
        'INSERT INTO dl_consignees (id, code, name, assigned_commissary_id, is_active) VALUES (:id, "AR-ACC", "Account Return Test", :b, 1)'
    )->execute([':id' => $consignee, ':b' => $branch]);
    // Custody of 10 as if 10 had been dispatched to the account earlier today.
    $db->prepare(
        'INSERT INTO dl_consignee_ledger (consignee_id, product_id, ledger_date, shift, price_snapshot, addtl, withdraw, encoded_by, updated_by)
         VALUES (:c, :p, :d, "AM", 0, 10, 0, 1, 1)'
    )->execute([':c' => $consignee, ':p' => $product, ':d' => $date]);

    $branchAddtl = static function () use ($db, $branch, $product, $date): int {
        $s = $db->prepare('SELECT COALESCE(SUM(addtl),0) FROM dl_daily_ledger WHERE branch_id = :b AND product_id = :p AND ledger_date = :d AND shift = "AM"');
        $s->execute([':b' => $branch, ':p' => $product, ':d' => $date]);
        return (int) $s->fetchColumn();
    };
    $custody = static function () use ($db, $consignee, $product, $date): int {
        $s = $db->prepare('SELECT COALESCE(SUM(addtl - withdraw),0) FROM dl_consignee_ledger WHERE consignee_id = :c AND product_id = :p AND ledger_date = :d AND shift = "AM"');
        $s->execute([':c' => $consignee, ':p' => $product, ':d' => $date]);
        return (int) $s->fetchColumn();
    };

    $db->beginTransaction();
    dl_recordConsigneeReturn($db, $consignee, $product, $date, 'AM', 4, 1);
    $db->commit();
    $h->test('the branch gains the returned pieces', $branchAddtl() === 4, 'branch addtl=' . $branchAddtl() . ', expected 4');
    $h->test('the account stops holding them', $custody() === 6, 'custody=' . $custody() . ', expected 6');

    // A mistyped total, corrected DOWN from 4 to 1, must come off the branch symmetrically.
    $db->beginTransaction();
    dl_recordConsigneeReturn($db, $consignee, $product, $date, 'AM', 1, 1);
    $db->commit();
    $h->test('lowering a mistyped return takes the stock back off', $branchAddtl() === 1, 'branch addtl=' . $branchAddtl() . ', expected 1');
    $h->test('the corrected custody is right too', $custody() === 9, 'custody=' . $custody() . ', expected 9');
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    foreach ([
        "DELETE FROM dl_consignee_ledger_effects WHERE consignee_id IN (SELECT id FROM dl_consignees WHERE code = 'AR-ACC')",
        "DELETE FROM dl_consignee_ledger WHERE consignee_id IN (SELECT id FROM dl_consignees WHERE code = 'AR-ACC')",
        "DELETE FROM dl_consignees WHERE code = 'AR-ACC'",
        "DELETE FROM dl_daily_ledger WHERE branch_id IN (SELECT id FROM dl_branches WHERE code LIKE 'AR-%')",
        "DELETE FROM audit_logs WHERE module = 'daily-ledger' AND branch_id IN (SELECT id FROM dl_branches WHERE code LIKE 'AR-%')",
        "DELETE FROM dl_branches WHERE code LIKE 'AR-%'",
    ] as $clean) {
        $db->prepare($clean)->execute();
    }
}

$h->done();
