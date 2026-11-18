<?php

declare(strict_types=1);

/**
 * Daily Ledger — a corrected DR line reaches the PRODUCTION DASHBOARD.
 *
 * Owner report 2026-11-17: "we missed at production dashboard the notification text about the
 * corrected dr item by the receiving branch".
 *
 * The correction flow already raised a dl_integrity_notifications finding addressed to the
 * commissary, and that finding DID appear on /daily-ledger/admin/variances. It never appeared on
 * /daily-ledger/admin/commissary - the page the production in-charge actually lands on - because
 * the only server-rendered surface there was dl_pendingDeliveryIssues(), which reads
 * dl_variance_flags, i.e. QUANTITY mismatches with a receiver remark. A wrong-item correction is
 * not a variance row. So the notice existed, was addressed correctly, and was invisible where the
 * person who has to act on it works.
 *
 * WHAT THIS PINS, against the database rather than the template source:
 *  1. The correction IS returned for the commissary branch that produced the delivery.
 *  2. It is NOT returned for another branch — the notice follows the branch whose figures moved.
 *  3. The old variance queue does NOT carry it, which is exactly why the second surface was needed.
 *     A test that only grepped the template would have called the previous code correct.
 *  4. Truncation is honest: the list is capped, but the TOTAL is reported, so a limit can never
 *     read as "that is everything".
 *  5. The text production needs is intact: which delivery, wrong -> correct, quantity, and the
 *     instruction not to adjust the commissary by hand.
 */

ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-production-notice', TestHarness::MODE_INTEGRATION, 'baronledger.test');
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

$commissaryId = 99891;
// The branch that RECEIVED and corrected the line. Owner 2026-11-17: "Rizal Commis is the
// production dispatch" - the commissary sends, the receiving branch corrects. These are two
// different branches and the notice must never confuse them.
$receivingBranchId = 99892;
$unrelatedBranchId = 99893;

$cleanup = static function () use ($db, $commissaryId, $receivingBranchId, $unrelatedBranchId): void {
    $ids = [$commissaryId, $receivingBranchId, $unrelatedBranchId];
    $db->prepare('DELETE FROM dl_integrity_notifications WHERE branch_id IN (?, ?, ?)')->execute($ids);
    $db->prepare('DELETE FROM dl_variance_flags WHERE branch_id IN (?, ?, ?)')->execute($ids);
    $db->prepare('DELETE FROM dl_deliveries WHERE origin_id IN (?, ?, ?) OR destination_id IN (?, ?, ?)')
        ->execute([...$ids, ...$ids]);
    $db->prepare('DELETE FROM dl_branches WHERE id IN (?, ?, ?)')->execute($ids);
};

$cleanup();
try {
    $insertBranch = $db->prepare(
        'INSERT INTO dl_branches (id, code, name, is_active, is_commissary, paperless_dr_enabled, single_shift_mode, has_production)
         VALUES (?, ?, ?, 1, ?, 1, 0, 1)'
    );
    $insertBranch->execute([$commissaryId, 'NOTE-COMM', 'Notice Fixture Commissary', 1]);
    $insertBranch->execute([$receivingBranchId, 'NOTE-RECV', 'Notice Receiving Branch', 0]);
    $insertBranch->execute([$unrelatedBranchId, 'NOTE-OTHER', 'Notice Unrelated Branch', 0]);

    // A real delivery FROM the commissary TO the receiving branch, so the corrector can be resolved
    // from destination_id rather than guessed.
    $db->prepare(
        'INSERT INTO dl_deliveries (origin_type, origin_id, destination_type, destination_id, dr_number, delivery_date, status, posted_at)
         VALUES ("commissary", ?, "branch", ?, "DR-NOTE-99891", ?, "posted", NOW())'
    )->execute([$commissaryId, $receivingBranchId, dl_businessDate()]);
    $deliveryId = (int)$db->lastInsertId();

    $insertFinding = $db->prepare(
        "INSERT INTO dl_integrity_notifications
            (aggregate_key, finding_type, branch_id, entity_type, entity_id, title, detail, finding_count)
         VALUES (?, 'delivery_item_substituted', ?, 'dl_deliveries', ?, ?, ?, ?)"
    );
    $detail = 'Delivery #' . $deliveryId . ': PUTO CHIZ BIG -> LECHON SMALL x 3. Reason: nabali ang na-load.'
        . ' Production figures were updated automatically; do not adjust the commissary by hand.';

    $h->section('the correction is visible to the commissary that produced it');
    $insertFinding->execute([
        'fixture-substitution-1', $commissaryId, $deliveryId,
        'Dispatch corrected at the receiving branch', $detail, 1,
    ]);
    $result = dl_recentProductionNotices($db, $commissaryId, 5);
    $h->test(
        'a wrong-item correction is returned for the producing branch',
        count($result['rows']) === 1 && (int)$result['total'] === 1,
        'rows=' . count($result['rows']) . ' total=' . (int)$result['total']
    );
    $h->test(
        'the notice carries the delivery, both products, the quantity and the do-not-adjust rule',
        count($result['rows']) === 1
            && str_contains((string)$result['rows'][0]['detail'], '#' . $deliveryId)
            && str_contains((string)$result['rows'][0]['detail'], 'PUTO CHIZ BIG')
            && str_contains((string)$result['rows'][0]['detail'], 'LECHON SMALL')
            && str_contains((string)$result['rows'][0]['detail'], 'x 3')
            && str_contains((string)$result['rows'][0]['detail'], 'do not adjust the commissary by hand'),
        (string)($result['rows'][0]['detail'] ?? '')
    );
    // Owner 2026-11-17: "notification must be detailed as to which branch the correction was done,
    // time and date aside from the current text", followed by the correction "Rizal Commis is the
    // production dispatch" - RIZAL-COMMIS SENDS the delivery, so it is NOT the branch that
    // corrected it. Naming branch_id here (the notified branch) answers the wrong question.
    $h->test(
        'the dashboard names the RECEIVING branch as the one that corrected the line',
        count($result['rows']) === 1
            && str_contains((string)$result['rows'][0]['meta_label'], 'Notice Receiving Branch')
            && str_contains((string)$result['rows'][0]['meta_label'], 'NOTE-RECV'),
        (string)($result['rows'][0]['meta_label'] ?? '')
    );
    $h->test(
        'the producing commissary is never named as the corrector',
        count($result['rows']) === 1
            && !str_contains((string)$result['rows'][0]['meta_label'], 'Notice Fixture Commissary')
            && !str_contains((string)$result['rows'][0]['meta_label'], 'NOTE-COMM'),
        'the sender is the notified branch, not the correcting one: '
            . (string)($result['rows'][0]['meta_label'] ?? '')
    );
    $h->test(
        'the dashboard shows the date and time of the correction',
        count($result['rows']) === 1
            && (bool)preg_match('/\d{4}-\d{2}-\d{2} \d{2}:\d{2}/', (string)$result['rows'][0]['meta_label'])
            && (bool)preg_match('/\d{4}-\d{2}-\d{2} \d{2}:\d{2}/', (string)$result['rows'][0]['raised_label']),
        'expected a YYYY-MM-DD HH:MM stamp in meta_label/raised_label: '
            . (string)($result['rows'][0]['meta_label'] ?? '')
    );

    $h->section('the notice follows the branch whose figures moved');
    $otherResult = dl_recentProductionNotices($db, $unrelatedBranchId, 5);
    $h->test(
        'another branch does not receive the producing branch correction',
        $otherResult['rows'] === [] && (int)$otherResult['total'] === 0,
        json_encode($otherResult)
    );
    $h->test(
        'the RECEIVING branch is not shown the notice it caused - it belongs to production',
        dl_recentProductionNotices($db, $receivingBranchId, 5)['rows'] === [],
        'the corrector must not be told about their own correction'
    );

    $h->section('the heading names the branch, when that is unambiguous');
    $single = dl_recentProductionNotices($db, $commissaryId, 5);
    $h->test(
        'with one corrector the heading names it',
        (int)$single['total'] === 1
            && str_contains((string)$single['heading'], 'A dispatched line was corrected at ')
            && str_contains((string)$single['heading'], 'Notice Receiving Branch (NOTE-RECV)'),
        (string)$single['heading']
    );

    $h->section('the variance queue does not carry it - which is why the second surface exists');
    $pending = dl_pendingDeliveryIssues($db, $commissaryId, 50);
    $h->test(
        'a corrected line is NOT in the quantity-variance queue',
        $pending === [],
        'the old surface read dl_variance_flags; if it carried the correction this test is pointless: '
            . json_encode($pending)
    );
    $h->test(
        'the two readers answer different questions',
        $pending === [] && count(dl_recentProductionNotices($db, $commissaryId, 5)['rows']) === 1,
        'variance queue must stay empty while the correction notice is present'
    );

    $h->section('a capped list must never look like the whole list');
    // Extra corrections against the SAME real delivery, because the corrector is resolved from it.
    for ($i = 2; $i <= 7; $i++) {
        $insertFinding->execute([
            'fixture-substitution-' . $i, $commissaryId, $deliveryId,
            'Dispatch corrected at the receiving branch', $detail, 1,
        ]);
    }
    $capped = dl_recentProductionNotices($db, $commissaryId, 5);
    $h->test(
        'seven corrections cap at five rows but report the true total',
        count($capped['rows']) === 5 && (int)$capped['total'] === 7,
        'rows=' . count($capped['rows']) . ' total=' . (int)$capped['total']
    );
    $h->test(
        'the cap keeps the most recent corrections, not the oldest',
        count($capped['rows']) === 5
            && (int)$capped['rows'][0]['id'] > (int)$capped['rows'][4]['id'],
        'expected newest-first ordering'
    );

    $h->section('a mixed list never names one branch as if it were the only one');
    // A second delivery to a DIFFERENT branch. Naming one branch across a mixed list would be false,
    // so the heading must fall back to the general wording while each row keeps its own branch.
    $db->prepare(
        'INSERT INTO dl_deliveries (origin_type, origin_id, destination_type, destination_id, dr_number, delivery_date, status, posted_at)
         VALUES ("commissary", ?, "branch", ?, "DR-NOTE-99892", ?, "posted", NOW())'
    )->execute([$commissaryId, $unrelatedBranchId, dl_businessDate()]);
    $secondDeliveryId = (int)$db->lastInsertId();
    $insertFinding->execute([
        'fixture-substitution-mixed', $commissaryId, $secondDeliveryId,
        'Dispatch corrected at the receiving branch', $detail, 1,
    ]);
    $mixed = dl_recentProductionNotices($db, $commissaryId, 5);
    $h->test(
        'two correcting branches produce a general heading',
        str_contains((string)$mixed['heading'], 'receiving branches')
            && !str_contains((string)$mixed['heading'], 'NOTE-RECV')
            && !str_contains((string)$mixed['heading'], 'NOTE-OTHER'),
        (string)$mixed['heading']
    );
    $h->test(
        'the rows still name their own branch when the heading cannot',
        count($mixed['rows']) >= 2
            && str_contains(implode(' | ', array_column($mixed['rows'], 'meta_label')), 'NOTE-RECV')
            && str_contains(implode(' | ', array_column($mixed['rows'], 'meta_label')), 'NOTE-OTHER'),
        implode(' | ', array_column($mixed['rows'], 'meta_label'))
    );

    $h->section('an unreachable branch reports nothing instead of guessing');
    $none = dl_recentProductionNotices($db, 0, 5);
    $h->test(
        'no branch means no notices',
        $none['rows'] === [] && (int)$none['total'] === 0,
        json_encode($none)
    );

    $h->section('the landing page renders the notices the handler passes');
    $sheetTemplate = (string)file_get_contents($base . '/templates/modules/daily-ledger/admin/commissary.disyl');
    $h->test(
        'the sheet iterates production_notices and states the truncation honestly',
        str_contains($sheetTemplate, '{foreach production_notices as notice}')
            && str_contains($sheetTemplate, '{notice.detail}')
            && str_contains($sheetTemplate, '{if production_notice_truncated}'),
        'the panel would otherwise be dead markup fed by nothing'
    );
    $handlersSource = (string)file_get_contents($base . '/modules/daily-ledger/handlers.php');
    $h->test(
        'the handler and the helper share one reader, so the count cannot drift from the list',
        substr_count($handlersSource, 'function dl_recentProductionNotices(') === 1
            && str_contains($handlersSource, "'production_notice_total' => \$productionNotices['total']"),
        'reader copies: ' . substr_count($handlersSource, 'function dl_recentProductionNotices(')
    );
} finally {
    $cleanup();
}

$h->done();
