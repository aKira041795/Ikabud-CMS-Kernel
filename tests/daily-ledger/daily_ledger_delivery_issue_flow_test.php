<?php

declare(strict_types=1);

/**
 * Daily Ledger — concern #3's decision rules.
 *
 * The client's model, verbatim 2026-11-10:
 *   "the variance can be logged at receiving but not necessarily finalized pending admin
 *    verification and approval ... no need makausab ang branch ... need lang naay correcting area
 *    both branch ug need naay remarks if unsay nahitabo, then kami ra diri sa office ang makausab."
 *
 * Owner direction on top of it:
 *   "the re-receive must be resolved intelligently by the system, supervisors may not necessarily
 *    resolve as it purely an admin task but can be allowed at permissions at admin settings".
 *
 * WHAT THIS PINS, and why each one exists:
 *  1. A variance is CREATED pending (unreviewed). Logged at receiving, not finalised there.
 *  2. A re-raise that changes NOTHING preserves the office's decision. This is the owner's
 *     "intelligently": the old code reset unconditionally, so a settled exception silently dropped
 *     out of the approved set. There is no ordinary re-receive (the system refuses one), so the only
 *     thing that re-enters the writer is a production correction to the sent quantity.
 *  3. A re-raise that CHANGES the figures reopens it, and says why in the audit trail.
 *  4. Resolution is the permission 'variance.resolve' - admin by default, grantable to supervisors
 *     from admin settings, and NOT held by cashiers or production.
 *  5. A branch remark is words only: the write touches remark columns and nothing else.
 *
 * The behaviour is exercised against ONE real delivery variance, snapshotted first and restored
 * exactly afterwards, so no fixture has to imitate the real row shape.
 */

ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-delivery-issue-flow', TestHarness::MODE_INTEGRATION, 'baronledger.test');
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

$h->section('resolution is a permission, not a role guess');
/*
 * Assert the DEFAULTS and the GATE, not dl_roleHasPermission() directly: that lookup merges whatever
 * matrix a tenant has stored, so it reports the live configuration rather than the shipped rule. The
 * rule the client cares about is "admin decides, branches do not", and that is carried by two things
 * this suite pins separately - the default grant, and the gate that always lets an admin through
 * regardless of what a stored matrix says.
 */
$defaults = dl_defaultRolePermissions();
$h->test(
    'the shipped default grants resolution to admin',
    in_array('variance.resolve', $defaults['admin'] ?? [], true),
    'the office is the default holder of the decision'
);
$h->test(
    'the shipped default does NOT grant it to supervisor',
    !in_array('variance.resolve', $defaults['supervisor'] ?? [], true),
    'owner: "purely an admin task ... can be allowed at permissions at admin settings" - off unless granted'
);
foreach (['cashier', 'production_in_charge', 'auditor', 'viewer'] as $role) {
    $h->test(
        "the shipped default does not grant it to {$role}",
        !in_array('variance.resolve', $defaults[$role] ?? [], true),
        'a branch records words; it never decides'
    );
}
$h->test(
    'the permission is registered so admin settings can grant it',
    in_array('variance.resolve', dl_allPermissionActions(), true),
    'an unregistered action cannot be ticked in the permissions matrix'
);

$h->section('a branch remark is words only');
$core = (string) file_get_contents($base . '/modules/daily-ledger/handlers.php');
$remarkStart = strpos($core, 'function apiSaveDeliveryVarianceRemark');
$remarkBody = substr($core, $remarkStart, 9000);
$h->test(
    'the remark writer sets only remark columns',
    preg_match('/UPDATE dl_variance_flags SET \'\s*\.\s*\$prefix\s*\.\s*\'_remark = :remark/', $remarkBody) === 1
        || (str_contains($remarkBody, "_remark = :remark") && str_contains($remarkBody, "_remarked_at = CURRENT_TIMESTAMP")),
    'a remark must not finalise, correct or re-price anything'
);
$h->test(
    'the remark writer never assigns resolution_status',
    !preg_match('/SET[^;]*resolution_status\s*=/s', substr($remarkBody, 0, 4000)),
    'resolution is the office decision; a branch explanation cannot become one'
);

$h->section('logged at receiving, finalised by the office');
/*
 * Exercise the real writer on one real row. Snapshot everything the two writes can touch, then
 * restore byte-exactly, so this suite leaves the data as it found it.
 */
$flag = $db->query(
    "SELECT vf.id, vf.delivery_id, vf.product_id, vf.sent_qty, vf.received_qty,
            COALESCE(vf.receiving_id, 0) AS receiving_id
       FROM dl_variance_flags vf
      WHERE vf.kind = 'delivery' AND vf.delivery_id IS NOT NULL
      ORDER BY vf.id DESC LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);

if (!$flag) {
    $h->test('a delivery variance exists to exercise', false, 'no delivery variance found in this tenant');
    $h->done();
    exit;
}

$flagId = (int)$flag['id'];
$snapshot = $db->prepare('SELECT * FROM dl_variance_flags WHERE id = :id');
$snapshot->execute([':id' => $flagId]);
$before = $snapshot->fetch(PDO::FETCH_ASSOC) ?: [];
$maxAudit = (int)$db->query('SELECT COALESCE(MAX(id),0) FROM audit_logs')->fetchColumn();

try {
    // Mark it as the office already decided, with a note, as a settled exception would be.
    $db->prepare(
        "UPDATE dl_variance_flags
            SET resolution_status = 'corrected', is_reviewed = 1, resolution_choice = 'accept_production',
                review_note = 'ZZTEST settled by the office', reviewed_by = 1, reviewed_at = NOW()
          WHERE id = :id"
    )->execute([':id' => $flagId]);

    // 2. Same figures raised again -> the decision must survive untouched.
    dl_raiseDeliveryVariance(
        $db, (int)$flag['delivery_id'], (int)$flag['receiving_id'], (int)$flag['product_id'],
        (int)$flag['sent_qty'], (int)$flag['received_qty'], 1
    );
    $afterSame = $db->prepare('SELECT resolution_status, review_note, is_reviewed FROM dl_variance_flags WHERE id = :id');
    $afterSame->execute([':id' => $flagId]);
    $same = $afterSame->fetch(PDO::FETCH_ASSOC) ?: [];
    $h->test(
        'an unchanged re-raise leaves the office decision standing',
        (string)($same['resolution_status'] ?? '') === 'corrected'
            && (string)($same['review_note'] ?? '') === 'ZZTEST settled by the office'
            && (int)($same['is_reviewed'] ?? 0) === 1,
        'got status=' . ($same['resolution_status'] ?? 'null')
            . ' note=' . ($same['review_note'] ?? 'null')
            . ' - the old unconditional reset dropped settled exceptions out of the approved set'
    );

    // 3. Figures genuinely changed -> reopen, and log both sides of the change.
    $changedReceived = (int)$flag['received_qty'] + 1;
    dl_raiseDeliveryVariance(
        $db, (int)$flag['delivery_id'], (int)$flag['receiving_id'], (int)$flag['product_id'],
        (int)$flag['sent_qty'], $changedReceived, 1
    );
    $afterChanged = $db->prepare('SELECT resolution_status, review_note, received_qty FROM dl_variance_flags WHERE id = :id');
    $afterChanged->execute([':id' => $flagId]);
    $changed = $afterChanged->fetch(PDO::FETCH_ASSOC) ?: [];
    $h->test(
        'a figure change reopens it for re-review',
        (string)($changed['resolution_status'] ?? '') === 'unreviewed',
        'got ' . ($changed['resolution_status'] ?? 'null') . ' - a decision made on other numbers must be re-made'
    );
    $h->test(
        'reopening clears the stale decision note',
        trim((string)($changed['review_note'] ?? '')) === '',
        'a note about the old figures must not ride along with the new ones'
    );

    $audit = $db->prepare("SELECT COUNT(*) FROM audit_logs WHERE id > :m AND action = 'delivery_variance_reopened' AND entity_id = :e");
    $audit->execute([':m' => $maxAudit, ':e' => (string)$flagId]);
    $h->test(
        'the reopen is explained in the audit trail',
        (int)$audit->fetchColumn() >= 1,
        'the office must be able to see WHY something came back'
    );

    // The two-sided branch remarks are not the office note and must never be touched by a re-raise.
    $remarks = $db->prepare('SELECT sender_remark, receiver_remark FROM dl_variance_flags WHERE id = :id');
    $remarks->execute([':id' => $flagId]);
    $now = $remarks->fetch(PDO::FETCH_ASSOC) ?: [];
    $h->test(
        'a re-raise never wipes what the branches said',
        (string)($now['sender_remark'] ?? '') === (string)($before['sender_remark'] ?? '')
            && (string)($now['receiver_remark'] ?? '') === (string)($before['receiver_remark'] ?? ''),
        'the branches\' words are evidence, not office state'
    );
} finally {
    // Restore the row exactly as it was found, then drop the audit rows this suite created.
    $sets = [];
    $binds = [':id' => $flagId];
    foreach ($before as $col => $value) {
        if ($col === 'id') { continue; }
        $sets[] = "`{$col}` = :{$col}";
        $binds[":{$col}"] = $value;
    }
    if ($sets !== []) {
        $db->prepare('UPDATE dl_variance_flags SET ' . implode(', ', $sets) . ' WHERE id = :id')->execute($binds);
    }
    $db->prepare('DELETE FROM audit_logs WHERE id > :m AND action = :a')->execute([':m' => $maxAudit, ':a' => 'delivery_variance_reopened']);
}

$h->section('no branch path can finalise a variance');
$select = strpos($core, 'function handleAdminVariances');
$handlerBody = $select === false ? '' : substr($core, $select, 40000);
$h->test(
    'the office page reports resolution as a permission flag',
    str_contains($core, "dl_roleHasPermission(\$role, 'variance.resolve')"),
    'the branch surface must be gated on the permission, not on a hardcoded role pair'
);
$h->test(
    'an admin reaches the decision regardless of the stored matrix',
    str_contains($core, "in_array(\$role, ['admin', 'superadmin'], true) || dl_roleHasPermission(\$role, 'variance.resolve')"),
    'a tenant whose saved matrix predates the permission must not lock the office out of its own decision'
);

$h->done();
