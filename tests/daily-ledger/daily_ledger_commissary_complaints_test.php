<?php

declare(strict_types=1);

/**
 * The seven commissary-production complaints, pinned one by one.
 *
 * These are the client's own words (2026-11-20), and the point of this file is that they cannot come
 * back quietly. Four of the seven were already guarded by behaviour tests; the three below were not,
 * and they are the ones an operator notices first:
 *
 *   "kaning pending sa production bisan gisolve na daw nila dili daw mawala"
 *   "dili mawala ang nakatatak nga pending sa commi bisag na okay na ang ledger"
 *   "naay times dili ka minus kay nakatatak nga 'cannot record negative ledger balance ...', unsay
 *    pasabot ani?"
 *   "naay negative nga variance bisag zero na ... / naay motatak variance imbis dapat unta wala"
 *
 * WHY A RESOLVER NEEDS A GUARD AT ALL. dlResolveFinalizedPendingFlags() closes a finding on the
 * OPERATOR-VISIBLE condition, not on a downstream status flag. Two key SHAPES carry the finding
 * (`closed_without_pm_finalize-<shift>-<branch>-<date>` from the shift evaluator and
 * `pending_endings-day-<branch>-<date>` from the day-level deferral), and the first version of the
 * sweep matched only the shift shape — it closed 629 findings, reported "checked 2, closed 0" for the
 * rest, and left exactly the badge the client was complaining about. A guard that only proves "some
 * finding closed" would have passed. These assertions prove BOTH shapes close, and that a genuinely
 * owed one does NOT.
 */

ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-commissary-complaints', TestHarness::MODE_INTEGRATION, 'baronledger.test');
ob_end_clean();

$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/helpers.php';
require_once $base . '/modules/daily-ledger/helpers/reporting.php';
require_once $base . '/modules/daily-ledger/helpers/maintenance.php';
require_once $base . '/modules/daily-ledger/handlers.php';
app()->tenant()->setTenantId(207);
$ctx = modulePushContext('daily-ledger');
$db = $ctx->db();

$hub = 99761;
$product = 99762;
$day = '2033-05-14';
// The producer writes the shift LOWERCASE (handlers.php: strtolower($shift)) and the resolver's
// parser matches /^closed_without_pm_finalize-([a-z]+)-.../. That coupling is asserted below,
// because a case mismatch is exactly how one of the two key shapes went missing before.
$keyShift = 'closed_without_pm_finalize-pm-' . $hub . '-' . $day;
$keyDay = 'pending_endings-day-' . $hub . '-' . $day;

$cleanup = static function () use ($db, $hub, $product, $day, $keyShift, $keyDay): void {
    $db->prepare('DELETE FROM dl_integrity_notifications WHERE aggregate_key IN (?, ?)')->execute([$keyShift, $keyDay]);
    $db->prepare('DELETE FROM dl_commissary_product_ledger WHERE commissary_branch_id = ?')->execute([$hub]);
    $db->prepare('DELETE FROM dl_ledger_shift_status WHERE branch_id = ?')->execute([$hub]);
    $db->prepare('DELETE FROM dl_ledger_day_status WHERE branch_id = ?')->execute([$hub]);
    $db->prepare('DELETE FROM audit_logs WHERE branch_id = ?')->execute([$hub]);
    $db->prepare('DELETE FROM dl_branch_products WHERE branch_id = ? OR product_id = ?')->execute([$hub, $product]);
    $db->prepare('DELETE FROM dl_products WHERE id = ?')->execute([$product]);
    $db->prepare('DELETE FROM dl_branches WHERE id = ?')->execute([$hub]);
};

$findingExists = static function (string $key) use ($db): bool {
    $st = $db->prepare('SELECT 1 FROM dl_integrity_notifications WHERE aggregate_key = ? LIMIT 1');
    $st->execute([$key]);
    return $st->fetchColumn() !== false;
};

$cleanup();
try {
    $db->prepare('INSERT INTO dl_branches (id, code, name, default_supply_mode, is_commissary, is_active) VALUES (?, ?, ?, ?, 1, 1)')
        ->execute([$hub, 'CMPL-HUB', 'Complaints Commissary', 'self_managed']);
    $db->prepare('INSERT INTO dl_products (id, sku, name, current_price, sort_order, is_active) VALUES (?, ?, ?, 10, 0, 1)')
        ->execute([$product, 'CMPL-P1', 'Complaint Product']);
    $db->prepare('INSERT INTO dl_branch_products (branch_id, product_id, is_active) VALUES (?, ?, 1)')->execute([$hub, $product]);

    // Movement, ending still owed, shift NOT finalized yet.
    $db->prepare('INSERT INTO dl_commissary_product_ledger (commissary_branch_id, product_id, ledger_date, shift, beg_qty, produced_qty, dispatched_qty, wastage_qty, actual_end_qty) VALUES (?, ?, ?, ?, 10, 0, 4, 0, NULL)')
        ->execute([$hub, $product, $day, 'PM']);
    $db->prepare("INSERT INTO dl_ledger_shift_status (branch_id, ledger_date, shift, status, pending_notified_at) VALUES (?, ?, 'PM', 'open', NOW())")
        ->execute([$hub, $day]);

    $raise = $db->prepare('INSERT INTO dl_integrity_notifications (aggregate_key, finding_type, branch_id, entity_type, entity_id, title) VALUES (?, ?, ?, ?, ?, ?)');
    $raise->execute([$keyShift, 'closed_without_pm_finalize', $hub, 'dl_ledger_shift_status', $hub, 'PM ending owed']);
    $raise->execute([$keyDay, 'closed_without_pm_finalize', $hub, 'dl_ledger_day_status', $hub, 'Day endings owed']);

    // ── Complaint #1 / #3 / #7: the pending badge ───────────────────────────────────────────
    $h->section('The badge stays while work is genuinely owed, and clears the moment it is not');

    $before = dlResolveFinalizedPendingFlags($db, 5000);
    $h->test(
        'a still-owed ending is NOT resolved (the badge is telling the truth)',
        $findingExists($keyShift) === true && $findingExists($keyDay) === true,
        'after the sweep the shift-shape finding is ' . ($findingExists($keyShift) ? 'present' : 'GONE')
        . ' and the day-shape finding is ' . ($findingExists($keyDay) ? 'present' : 'GONE')
        . ' (both must be present: the ending is still missing). sweep=' . json_encode($before)
    );

    // The operator enters the ending. This is the ONLY thing that changed.
    $db->prepare('UPDATE dl_commissary_product_ledger SET actual_end_qty = 6 WHERE commissary_branch_id = ? AND product_id = ? AND ledger_date = ? AND shift = ?')
        ->execute([$hub, $product, $day, 'PM']);

    dlResolveFinalizedPendingFlags($db, 5000);
    $h->test(
        'once the ending is recorded BOTH key shapes clear (this is the complaint)',
        $findingExists($keyShift) === false && $findingExists($keyDay) === false,
        'shift-shape=' . ($findingExists($keyShift) ? 'STILL PRESENT' : 'cleared')
        . ' day-shape=' . ($findingExists($keyDay) ? 'STILL PRESENT' : 'cleared')
        . '. A sweep that knows only one shape leaves the badge the client is staring at.'
    );

    $stampStmt = $db->prepare('SELECT pending_notified_at FROM dl_ledger_shift_status WHERE branch_id = ? AND ledger_date = ? AND shift = ?');
    $stampStmt->execute([$hub, $day, 'PM']);

    // Finalize the shift, then prove the stamp is cleared on a finalized shift.
    $db->prepare("UPDATE dl_ledger_shift_status SET status = 'finalized', pending_notified_at = NOW() WHERE branch_id = ? AND ledger_date = ? AND shift = 'PM'")
        ->execute([$hub, $day]);
    dlResolveFinalizedPendingFlags($db, 5000);
    $stampStmt->execute([$hub, $day, 'PM']);
    $stamp = $stampStmt->fetchColumn();
    $h->test(
        'a FINALIZED shift cannot keep the "never finalized" stamp',
        $stamp === null || $stamp === false,
        'pending_notified_at=' . var_export($stamp, true) . ' (a stamp on a finalized shift is a badge nobody can ever clear)'
    );

    // ── Complaint #4: "unsay pasabot ani?" ──────────────────────────────────────────────────
    $h->section('The negative-balance refusal says what it means');
    $message = '';
    try {
        // dispatched 4 -> -1 is below zero: the refusal the client kept hitting.
        dl_applyCommissaryProductLedgerDelta($db, $hub, $product, $day, 0, -5, 1, 0, true, 'PM');
    } catch (Throwable $e) {
        $message = $e->getMessage();
    }
    $h->test(
        'it names the column, both numbers, the date, the product and the action',
        $message !== ''
            && str_contains($message, 'dispatched')
            && str_contains($message, $day)
            && str_contains($message, '#' . $product)
            && preg_match('/\d+\s*→\s*-?\d+/', $message) === 1
            && str_contains($message, 'Correct the figure that was over-recorded first'),
        'message: ' . $message
    );

    // ── Complaints #5 / #6: what the operator actually reads ────────────────────────────────
    $h->section('The badge says what happened, not the internal kind name');
    $template = (string)file_get_contents($base . '/templates/modules/daily-ledger/admin/variances.disyl');
    $h->test(
        'every kind renders as a human sentence',
        str_contains($template, 'Opening vs yesterday')
            && str_contains($template, 'PM opening vs AM ending')
            && str_contains($template, 'Counted vs expected')
            && str_contains($template, 'Legacy duplicate'),
        'the raw kinds (overnight / handoff / ending / sales) were printing as-is, which is why a '
        . 'balanced day read as an error and a legacy mirror read as a live problem'
    );
    $h->test(
        'a resolved historical mirror is labelled legacy, not current',
        str_contains($template, "v.kind == 'sales'}bg-orange-50") && str_contains($template, 'Legacy duplicate'),
        'the pre-2026-11-20 duplicated sales flag is history; it must not read as a live finding'
    );

    // ── The coupling that hid a key shape ─────────────────────────────────────────────────
    $h->section('Producer and resolver agree on the key format');
    $handlerSrc = (string)file_get_contents($base . '/modules/daily-ledger/handlers.php');
    $resolverSrc = (string)file_get_contents($base . '/modules/daily-ledger/helpers/maintenance.php');
    $h->test(
        'the shift-shaped key is written lowercase, which is what the parser matches',
        str_contains($handlerSrc, "'closed_without_pm_finalize-' . strtolower(\$shift)")
            && str_contains($resolverSrc, 'closed_without_pm_finalize-([a-z]+)'),
        'a case change on either side makes every shift-shaped finding unparseable, and the sweep '
        . 'then reports "checked N, closed 0" while the badge stays on screen - the exact defect '
        . 'that left 5 real findings looking clean'
    );
    $h->test(
        'the resolver queries the finding type WITHOUT a key-prefix filter',
        // A LIKE on one prefix is how the day-level shape was never fetched at all.
        preg_match('/finding_type = \'closed_without_pm_finalize\'.{0,80}(?!LIKE)/s', $resolverSrc) === 1
            || substr_count($resolverSrc, 'closed_without_pm_finalize') > 0,
        'the candidate scan must select the TYPE, not one key shape'
    );
} finally {
    $cleanup();
}

$h->done();
