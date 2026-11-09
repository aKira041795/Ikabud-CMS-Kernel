<?php

declare(strict_types=1);

/**
 * Daily Ledger — a branch may not change ROLE (ordinary branch <-> commissary) mid-flight.
 *
 * Why this exists (2026-11-09): `is_commissary` was editable from the Edit Branch modal and from
 * nowhere else, written straight into that handler's own UPDATE, with NO guard of any kind. An
 * admin could convert a branch to a commissary (or the reverse) at any moment — including while
 * dispatches addressed to it were still awaiting receipt, which strands them — and could demote a
 * commissary that other branches and consignees still pointed at, orphaning their supply line.
 *
 * The fix routes `is_commissary` through `dl_applyBranchFlags()`, the single shared applier, and
 * folds the guard into the UPDATE's WHERE clause so the check and the write cannot be separated
 * (same shape as the existing single-shift R7 rule).
 *
 * Each assertion names the damage if it regresses:
 * - guard missing            -> a role flip strands in-flight deliveries
 * - mid-day flip allowed     -> the branch's own day is split across two stock models
 * - demotion allowed         -> branches/consignees keep a dangling assigned_commissary_id
 * - refusals without reason  -> the operator gets a control that silently does nothing
 */

ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-branch-role-guard', TestHarness::MODE_INTEGRATION, 'baronledger.test');
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

// Random fixture IDs, refusing to touch anything that already exists.
$clean   = 1881000100 + random_int(1, 400);
$dirty   = $clean + 500;
$commi   = $clean + 900;
$child   = $clean + 1300;
$date    = dl_businessDate();

// Self-heal first. An earlier run that aborted inside its restore leaves these fixed CODES behind
// (the ids are random each run, the codes are not), and stale fixtures are worse than none.
foreach ([
    "DELETE FROM dl_daily_ledger WHERE branch_id IN (SELECT id FROM dl_branches WHERE code LIKE 'RG-%')",
    "DELETE FROM dl_consignees WHERE assigned_commissary_id IN (SELECT id FROM dl_branches WHERE code LIKE 'RG-%')",
    "DELETE FROM audit_logs WHERE module = 'daily-ledger' AND branch_id IN (SELECT id FROM dl_branches WHERE code LIKE 'RG-%')",
    "DELETE FROM dl_branches WHERE code LIKE 'RG-%'",
] as $healSql) {
    $db->prepare($healSql)->execute();
}

$snapshot = $db->prepare('SELECT COUNT(*) FROM dl_branches WHERE id IN (?, ?, ?, ?)');
$snapshot->execute([$clean, $dirty, $commi, $child]);
if ((int) $snapshot->fetchColumn() > 0) {
    throw new RuntimeException('Random fixture branch IDs unexpectedly exist; refusing to touch them.');
}

$productId = (int) $db->query('SELECT id FROM dl_products WHERE is_active = 1 ORDER BY id LIMIT 1')->fetchColumn();

$cleanup = static function () use ($db, $clean, $dirty, $commi, $child, $productId): void {
    $ids = [$clean, $dirty, $commi, $child];
    $in  = implode(',', array_map('intval', $ids));
    // ModuleDB has no exec() - a restore that fatals leaves live fixtures behind, which is
    // exactly how a probe once corrupted this tenant's dev data. Use prepare/execute only.
    foreach ([
        'DELETE FROM dl_daily_ledger WHERE branch_id IN (' . $in . ')',
        'DELETE FROM dl_consignees WHERE assigned_commissary_id IN (' . $in . ')',
        "DELETE FROM audit_logs WHERE module = 'daily-ledger' AND branch_id IN (" . $in . ')',
        'DELETE FROM dl_branches WHERE id IN (' . $in . ')',
    ] as $sql) {
        $db->prepare($sql)->execute();
    }
};

try {
    // Four fixture branches: a clean one, one with a ledger row today, a commissary, and a child
    // branch pointing at that commissary.
    $ins = $db->prepare(
        'INSERT INTO dl_branches (id, code, name, default_supply_mode, is_commissary, is_active, assigned_commissary_id)
         VALUES (:id, :code, :name, "commissary_supplied", :is_commissary, 1, :ac)'
    );
    $ins->execute([':id' => $clean, ':code' => 'RG-CLEAN', ':name' => 'RoleGuard Clean', ':is_commissary' => 0, ':ac' => null]);
    $ins->execute([':id' => $dirty, ':code' => 'RG-DIRTY', ':name' => 'RoleGuard Dirty', ':is_commissary' => 0, ':ac' => null]);
    $ins->execute([':id' => $commi, ':code' => 'RG-COMMI', ':name' => 'RoleGuard Commi', ':is_commissary' => 1, ':ac' => null]);
    $ins->execute([':id' => $child, ':code' => 'RG-CHILD', ':name' => 'RoleGuard Child', ':is_commissary' => 0, ':ac' => $commi]);

    if ($productId > 0) {
        $db->prepare(
            'INSERT INTO dl_daily_ledger (branch_id, product_id, ledger_date, shift, price_snapshot, beg_bal, addtl, withdraw)
             VALUES (:b, :p, :d, "AM", 0, 0, 1, 0)'
        )->execute([':b' => $dirty, ':p' => $productId, ':d' => $date]);
    }

    $h->section('the shared applier owns is_commissary');

    $src = (string) file_get_contents($base . '/modules/daily-ledger/handlers.php');
    $h->test(
        "'is_commissary' is an accepted switch on dl_applyBranchFlags()",
        (bool) preg_match("/\\\$allowed\s*=\s*\[[^\]]*'is_commissary'/", $src),
        'the Edit form would bypass the guard again'
    );
    $h->test(
        'the Edit handler no longer writes is_commissary in its own UPDATE',
        !str_contains($src, "is_commissary = :ic"),
        'two write paths for one switch is how the guard was missed the first time'
    );
    $h->test(
        'the guard refuses on in-flight deliveries',
        str_contains($src, 'dl_ic_move') && str_contains($src, 'dl_branch_receivings dl_ic_rcv'),
        'a dispatch awaiting receipt would be stranded by a role flip'
    );

    $h->section('behaviour');

    // T1 — a clean branch may be promoted.
    $r1 = dl_applyBranchFlags($db, $clean, ['is_commissary' => 1]);
    $h->test(
        'clean branch CAN be promoted to commissary',
        !empty($r1['ok']) && !empty($r1['changed']),
        json_encode($r1)
    );

    // T2 — a branch that already started the day may NOT be promoted, and must say why.
    $r2 = dl_applyBranchFlags($db, $dirty, ['is_commissary' => 1]);
    $h->test(
        'branch with ledger rows today CANNOT be promoted',
        empty($r2['ok']),
        json_encode($r2)
    );
    $h->test(
        'the refusal names the business day (actionable, not a silent no-op)',
        empty($r2['ok']) && str_contains(strtolower((string) ($r2['error'] ?? '')), 'ledger rows'),
        (string) ($r2['error'] ?? '(no error)')
    );

    $stillBranch = $db->prepare('SELECT is_commissary FROM dl_branches WHERE id = :id');
    $stillBranch->execute([':id' => $dirty]);
    $h->test(
        'the refused branch is unchanged in the database',
        (int) $stillBranch->fetchColumn() === 0,
        'a refused guard must not half-apply'
    );

    // T3 — demoting a commissary that still supplies a branch is refused.
    $r3 = dl_applyBranchFlags($db, $commi, ['is_commissary' => 0]);
    $h->test(
        'commissary with a dependent branch CANNOT be demoted',
        empty($r3['ok']),
        json_encode($r3)
    );
    $h->test(
        'the demotion refusal names the dependants',
        empty($r3['ok']) && str_contains(strtolower((string) ($r3['error'] ?? '')), 'supplies'),
        (string) ($r3['error'] ?? '(no error)')
    );

    // T4 — an unchanged value is not a change (no write, no audit row).
    $r4 = dl_applyBranchFlags($db, $clean, ['is_commissary' => 1]);
    $h->test(
        're-asserting the current value is a no-op, not a refusal',
        !empty($r4['ok']) && empty($r4['changed']),
        json_encode($r4)
    );

    // T5 — the change is audited with before/after, including the role.
    $audit = $db->prepare("SELECT COUNT(*) FROM audit_logs WHERE module = 'daily-ledger' AND action = 'branch_flags_update' AND branch_id = :b");
    $audit->execute([':b' => $clean]);
    $h->test(
        'the role change writes a branch_flags_update audit row',
        (int) $audit->fetchColumn() > 0,
        'an unaudited role change is not reconstructable'
    );
} finally {
    $cleanup();
}

$h->done();
