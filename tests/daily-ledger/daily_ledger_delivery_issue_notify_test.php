<?php

declare(strict_types=1);

/**
 * Daily Ledger — a short delivery reaches the production in-charge who sent it.
 *
 * Owner direction, 2026-11-13:
 *   "when there's a variance from cashier receiving stocks with the reason, the production in-charge
 *    is notified on the dashboard for easy viewing ... with the items having variances shown and
 *    where the production in-charge can also write the responses or reasons for an admin resolution".
 *
 * The pieces existed separately and never met: the receiving branch wrote a reason into
 * dl_variance_flags.receiver_remark, and a queue endpoint listed those rows for the SENDING branch -
 * but no notification was ever addressed to that branch's production_in_charge, and the role's
 * landing page showed nothing about it. The commissaries that originate most discrepancies run
 * production_in_charge users with no cashiers or supervisors (measured: RIZAL-COMMIS1 482 flags,
 * PAG-COMMISARY1 381), so "someone at that branch was told" was false for the only people there.
 *
 * WHAT THIS PINS:
 *  1. A receive with a per-line reason fills the SENDER's pending-issue queue, with the reason and
 *     the real figures.
 *  2. The production_in_charge assigned to the SENDING branch is a notification recipient.
 *  3. A production_in_charge at a DIFFERENT branch is NOT a recipient — the notice follows the
 *     branch that has to answer, not the role in general.
 *  4. The receiving branch's own queue stays empty: this is the sender's work list.
 *  5. The sheet (that role's landing page) receives the issue list, and the notice opens the same
 *     modal the header badge uses instead of a second implementation.
 */

ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-delivery-issue-notify', TestHarness::MODE_INTEGRATION, 'baronledger.test');
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

$senderBranchId = 99875;
$receiverBranchId = 99876;
$productId = 99885;
$senderPicId = 99875;
$otherPicId = 99876;
$date = dl_businessDate();

$cleanup = static function () use ($db, $senderBranchId, $receiverBranchId, $productId, $senderPicId, $otherPicId): void {
    $db->prepare('DELETE FROM dl_integrity_notification_recipients WHERE user_id IN (?, ?)')->execute([$senderPicId, $otherPicId]);
    $db->prepare('DELETE FROM dl_integrity_notifications WHERE branch_id IN (?, ?)')->execute([$senderBranchId, $receiverBranchId]);
    $db->prepare("DELETE FROM audit_logs WHERE module = 'daily-ledger' AND branch_id IN (?, ?)")->execute([$senderBranchId, $receiverBranchId]);
    $db->prepare('DELETE FROM dl_variance_flags WHERE branch_id IN (?, ?) OR product_id = ?')->execute([$senderBranchId, $receiverBranchId, $productId]);
    $db->prepare('DELETE FROM dl_delivery_variance_flags WHERE product_id = ?')->execute([$productId]);
    $db->prepare('DELETE FROM dl_branch_receiving_items WHERE product_id = ?')->execute([$productId]);
    $db->prepare('DELETE FROM dl_branch_receivings WHERE branch_id IN (?, ?)')->execute([$senderBranchId, $receiverBranchId]);
    $db->prepare('DELETE FROM dl_delivery_items WHERE product_id = ?')->execute([$productId]);
    $db->prepare('DELETE FROM dl_deliveries WHERE destination_id IN (?, ?)')->execute([$senderBranchId, $receiverBranchId]);
    $db->prepare('DELETE FROM dl_daily_ledger WHERE branch_id IN (?, ?) OR product_id = ?')->execute([$senderBranchId, $receiverBranchId, $productId]);
    $db->prepare('DELETE FROM dl_branch_products WHERE branch_id IN (?, ?) OR product_id = ?')->execute([$senderBranchId, $receiverBranchId, $productId]);
    $db->prepare('DELETE FROM dl_products WHERE id = ?')->execute([$productId]);
    $db->prepare('DELETE FROM dl_user_branches WHERE user_id IN (?, ?)')->execute([$senderPicId, $otherPicId]);
    $db->prepare('DELETE FROM dl_users WHERE id IN (?, ?)')->execute([$senderPicId, $otherPicId]);
    $db->prepare('DELETE FROM dl_branches WHERE id IN (?, ?)')->execute([$senderBranchId, $receiverBranchId]);
};

$cleanup();
try {
    $insertBranch = $db->prepare(
        'INSERT INTO dl_branches (id, code, name, is_active, is_commissary, paperless_dr_enabled, single_shift_mode, has_production)
         VALUES (?, ?, ?, 1, ?, 1, 0, 1)'
    );
    $insertBranch->execute([$senderBranchId, 'ISSUE-SEND', 'Issue Sender Commissary', 1]);
    $insertBranch->execute([$receiverBranchId, 'ISSUE-RECV', 'Issue Receiving Branch', 0]);

    $db->prepare(
        'INSERT INTO dl_products (id, sku, name, current_price, product_category, is_active)
         VALUES (?, ?, ?, 1, ?, 1)'
    )->execute([$productId, 'ISSUE-99885', 'Pandesal Fixture', 'bread']);

    $insertUser = $db->prepare(
        'INSERT INTO dl_users (id, username, password_hash, full_name, role, is_active)
         VALUES (?, ?, ?, ?, ?, 1)'
    );
    $insertUser->execute([$senderPicId, 'fixture-sender-pic', password_hash('x', PASSWORD_DEFAULT), 'Sender PIC', 'production_in_charge']);
    $insertUser->execute([$otherPicId, 'fixture-other-pic', password_hash('x', PASSWORD_DEFAULT), 'Other PIC', 'production_in_charge']);
    $db->prepare('INSERT INTO dl_user_branches (user_id, branch_id) VALUES (?, ?)')->execute([$senderPicId, $senderBranchId]);
    $db->prepare('INSERT INTO dl_user_branches (user_id, branch_id) VALUES (?, ?)')->execute([$otherPicId, $receiverBranchId]);
    $db->prepare('INSERT INTO dl_branch_products (branch_id, product_id, is_active) VALUES (?, ?, 1)')->execute([$senderBranchId, $productId]);
    $db->prepare('INSERT INTO dl_branch_products (branch_id, product_id, is_active) VALUES (?, ?, 1)')->execute([$receiverBranchId, $productId]);

    // A posted dispatch from the sender's commissary, then a short receipt with a reason.
    $db->prepare(
        'INSERT INTO dl_deliveries (origin_type, origin_id, destination_type, destination_id, dr_number, delivery_date, status, posted_at)
         VALUES ("commissary", ?, "branch", ?, "DR-ISSUE-99875", ?, "posted", NOW())'
    )->execute([$senderBranchId, $receiverBranchId, $date]);
    $deliveryId = (int)$db->lastInsertId();
    $db->prepare(
        'INSERT INTO dl_delivery_items (delivery_id, product_id, quantity, unit, price_snapshot)
         VALUES (?, ?, 5, "pcs", 1)'
    )->execute([$deliveryId, $productId]);
    $itemId = (int)$db->lastInsertId();

    dl_acceptFormalDeliveryByItem(
        $db, $receiverBranchId, $deliveryId, 0, $date,
        [$itemId => 3], 'AM', '', [$itemId => '2 packs kulang pag-abot']
    );

    $h->section('the sending branch gets a work list, with the reason');
    $senderQueue = dl_pendingDeliveryIssues($db, $senderBranchId);
    $h->test(
        'the short line is queued for the branch that sent it',
        count($senderQueue) === 1
            && (int)$senderQueue[0]['sent_qty'] === 5
            && (int)$senderQueue[0]['received_qty'] === 3
            && (int)$senderQueue[0]['short_by'] === 2,
        json_encode($senderQueue)
    );
    $h->test(
        'the queue carries the reason the cashier typed, not just the quantity',
        (string)($senderQueue[0]['receiver_remark'] ?? '') === '2 packs kulang pag-abot',
        json_encode($senderQueue[0]['receiver_remark'] ?? null)
    );
    $h->test(
        'the receiving branch has no sender-side queue of its own',
        dl_pendingDeliveryIssues($db, $receiverBranchId) === [],
        json_encode(dl_pendingDeliveryIssues($db, $receiverBranchId))
    );

    $h->section('the person who has to answer is told');
    $recipientStmt = $db->prepare(
        'SELECT n.title, n.detail FROM dl_integrity_notification_recipients r
           JOIN dl_integrity_notifications n ON n.id = r.notification_id
          WHERE r.user_id = :u'
    );
    $recipientStmt->execute([':u' => $senderPicId]);
    $senderNotices = $recipientStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $recipientStmt->execute([':u' => $otherPicId]);
    $otherNotices = $recipientStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $h->test(
        'the production in-charge of the SENDING branch is notified',
        count($senderNotices) === 1,
        json_encode($senderNotices)
    );
    $h->test(
        'the notice names the item, both figures and the reason given',
        count($senderNotices) === 1
            && str_contains((string)$senderNotices[0]['detail'], 'Pandesal Fixture')
            && str_contains((string)$senderNotices[0]['detail'], 'sent 5')
            && str_contains((string)$senderNotices[0]['detail'], 'counted 3')
            && str_contains((string)$senderNotices[0]['detail'], '2 packs kulang pag-abot'),
        json_encode($senderNotices[0]['detail'] ?? null)
    );
    $h->test(
        'a production in-charge at ANOTHER branch is not addressed',
        $otherNotices === [],
        'the notice must follow the branch that has to answer: ' . json_encode($otherNotices)
    );

    $h->section('the landing page shows it, and points at the one modal');
    $sheetTemplate = (string)file_get_contents($base . '/templates/modules/daily-ledger/admin/commissary.disyl');
    $h->test(
        'the sheet renders the pending issues the handler passes',
        str_contains($sheetTemplate, '{foreach pending_delivery_issues as issue}')
            && str_contains($sheetTemplate, 'Open the variance notices')
            && str_contains($sheetTemplate, '{issue.receiver_remark}')
    );
    $h->test(
        'the notice opens the existing issues modal rather than a second one',
        str_contains($sheetTemplate, "window.dispatchEvent(new CustomEvent('dl-open-delivery-issues'))")
            && str_contains($sheetTemplate, '@dl-open-delivery-issues.window="open = true"')
    );
    // The overlay MUST leave the header. The header carries backdrop-blur, which makes it a stacking
    // context and a containing block for position:fixed, so a "full screen" overlay left inside it
    // lays itself out within the header with the page painted over it - the modal was open in the DOM
    // and invisible, and the button looked dead (owner report 2026-11-13). Alpine also only teleports
    // the CHILDREN OF A TEMPLATE: x-teleport on a plain <div> survives in the HTML and moves nothing,
    // which is how the first fix failed. Both halves are pinned here.
    $h->test(
        'the issues overlay is teleported out of the blurred header, through a template',
        str_contains($sheetTemplate, '<template x-teleport="body">')
            && !preg_match('/<div[^>]*\sx-teleport=/', $sheetTemplate),
        'x-teleport on a <div> is silently ignored; it must be on a <template>'
    );
    $h->test(
        'the server-rendered notice is removed once its queue is empty',
        str_contains($sheetTemplate, "getElementById('dl-pending-issues-notice')")
            && str_contains($sheetTemplate, 'id="dl-pending-issues-notice"')
    );
    $handlersSource = (string)file_get_contents($base . '/modules/daily-ledger/handlers.php');
    $h->test(
        'handler and API share one queue reader',
        // The awaiting-reply predicate must exist in exactly ONE place. A second copy would let the
        // red badge and the notice list drift apart, which is how "3 waiting" ends up above 2 rows.
        substr_count($handlersSource, "vf.sender_remark IS NULL OR vf.sender_remark = ''") === 1
            && substr_count($handlersSource, 'function dl_pendingDeliveryIssues(') === 1,
        'queue predicate copies: ' . substr_count($handlersSource, "vf.sender_remark IS NULL OR vf.sender_remark = ''")
    );
} finally {
    $cleanup();
}

$h->done();
