<?php

declare(strict_types=1);

/**
 * Daily Ledger — a branch must be TOLD when someone outside it changes a finished day.
 *
 * Owner report 2026-11-14, in the branch's own words (Cebuano, translated): "There's a branch, for
 * example Rizal — they are puzzled because something suddenly adds or withdraws on their ledger when
 * they had not touched anything. I scrambled to edit."
 *
 * MEASURED CAUSE (tenant 207): an admin carries NO branch assignment (`dl_user_branches` is empty for
 * both admins), so a back-dated write onto a closed day was accepted silently. Branch 9's 3 Nov
 * ledger took 28 `adjustment_add` rows, netting -1,828 pcs, plus corrections — entered on 9 Nov
 * between 01:07 and 01:25, six days after the day ended. From the counter: stock moving on a day
 * they had already counted and signed, with no notice anywhere the branch could see.
 *
 * WHAT THIS PINS:
 *  1. A past day changed by an actor who is not assigned to that branch raises ONE notice.
 *  2. Repeated edits by the same actor on the same day collapse into that notice (count increments),
 *     so a batch of 28 rows is one line, not 28.
 *  3. The notice NAMES the movement — product and signed quantity — because "some change happened"
 *     does not answer the question the branch is asking.
 *  4. The branch's own cashiers are recipients: they are the desk that gets asked why stock moved.
 *  5. Today's date raises nothing — that is just the day being worked on.
 *  6. An actor assigned to the branch raises nothing — a supervisor fixing their own past day is not
 *     a mystery to their own branch.
 *  7. The finding type is in the schema (migration 102) and the sheet renders the notice.
 */

ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-backdated-edit-notice', TestHarness::MODE_INTEGRATION, 'baronledger.test');
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

$branchId = 99779;
$productId = 99789;
$outsiderId = 99791;   // an admin-like account with NO branch assignment
$insiderId = 99792;    // a supervisor assigned to the branch
$cashierId = 99793;    // the branch cashier who must be told
$otherCashierId = 99794; // a cashier of a DIFFERENT branch, who must not be told

$cleanup = static function () use ($db, $branchId, $productId, $outsiderId, $insiderId, $cashierId, $otherCashierId): void {
    $ids = [$outsiderId, $insiderId, $cashierId, $otherCashierId];
    $in = implode(',', $ids);
    $run = static function (string $sql) use ($db): void { $db->prepare($sql)->execute(); };
    $run("DELETE FROM dl_integrity_notification_recipients WHERE user_id IN ({$in})");
    $run("DELETE FROM dl_integrity_notifications WHERE aggregate_key LIKE 'backdated_ledger_edit:{$branchId}:%'");
    $run("DELETE FROM dl_user_branches WHERE user_id IN ({$in})");
    $run("DELETE FROM dl_users WHERE id IN ({$in})");
    $run("DELETE FROM dl_branch_products WHERE branch_id = {$branchId} OR product_id = {$productId}");
    $run("DELETE FROM dl_products WHERE id = {$productId}");
    $run("DELETE FROM dl_branches WHERE id = {$branchId}");
};

$cleanup();
try {
    $db->prepare('INSERT INTO dl_branches (id, code, name, is_active, is_commissary, paperless_dr_enabled, single_shift_mode, has_production) VALUES (?,?,?,1,0,1,0,1)')
       ->execute([$branchId, 'NOTICEFIX', 'Notice Fixture Branch']);
    $db->prepare('INSERT INTO dl_products (id, sku, name, current_price, product_category, is_active) VALUES (?,?,?,1,?,1)')
       ->execute([$productId, 'NOTICEFIX-1', 'Pandesal Notice Fixture', 'bread']);
    $mkUser = static function (int $id, string $username, string $fullName, string $role) use ($db): void {
        $db->prepare('INSERT INTO dl_users (id, username, full_name, role, is_active, password_hash) VALUES (?,?,?,?,1,?)')
           ->execute([$id, $username, $fullName, $role, password_hash('fixture-only', PASSWORD_DEFAULT)]);
    };
    $mkUser($outsiderId, 'notice_admin', 'Notice Outsider', 'admin');
    $mkUser($insiderId, 'notice_sup', 'Notice Insider', 'supervisor');
    $mkUser($cashierId, 'notice_cashier', 'Notice Cashier', 'cashier');
    $mkUser($otherCashierId, 'notice_other_cashier', 'Other Branch Cashier', 'cashier');
    $db->prepare('INSERT INTO dl_user_branches (user_id, branch_id) VALUES (?,?)')->execute([$insiderId, $branchId]);
    $db->prepare('INSERT INTO dl_user_branches (user_id, branch_id) VALUES (?,?)')->execute([$cashierId, $branchId]);

    $outsider = ['id' => $outsiderId, 'role' => 'admin', 'full_name' => 'Notice Outsider', 'name' => 'Notice Outsider'];
    $insider = ['id' => $insiderId, 'role' => 'supervisor', 'full_name' => 'Notice Insider', 'name' => 'Notice Insider'];
    $pastDate = date('Y-m-d', strtotime('-6 days'));
    $today = dl_businessDate();
    $totals = [['product_id' => $productId, 'field' => 'withdraw', 'result' => -120, 'total' => 120]];

    $countNotices = static function () use ($db, $branchId): int {
        return (int)$db->query("SELECT COUNT(*) FROM dl_integrity_notifications WHERE aggregate_key LIKE 'backdated_ledger_edit:{$branchId}:%'")->fetchColumn();
    };

    $h->section('a finished day changed from outside the branch is announced');

    $h->test('no notice exists before the edit', $countNotices() === 0);
    dl_noteForeignBackdatedEdit($db, $branchId, $pastDate, $outsider, 'correction', $totals, 'manual_adjustment');
    $h->test('the past-day edit raises a notice', $countNotices() === 1, 'this is the silence the branch reported');

    $notice = $db->query("SELECT * FROM dl_integrity_notifications WHERE aggregate_key LIKE 'backdated_ledger_edit:{$branchId}:%' LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];
    $h->test(
        'the notice names the product and the signed quantity',
        str_contains((string)($notice['detail'] ?? ''), 'Pandesal Notice Fixture') && str_contains((string)($notice['detail'] ?? ''), '−120'),
        '"some change happened" does not answer the branch question; the movement must be named'
    );
    $h->test(
        'the notice names who did it and which day',
        str_contains((string)($notice['title'] ?? ''), 'Notice Outsider') && str_contains((string)($notice['detail'] ?? ''), 'admin')
    );

    $h->section('the branch itself is told');

    $recipients = array_map('intval', $db->query(
        'SELECT user_id FROM dl_integrity_notification_recipients WHERE notification_id = ' . (int)$notice['id']
    )->fetchAll(PDO::FETCH_COLUMN));
    $h->test(
        'the branch cashier receives it',
        in_array($cashierId, $recipients, true),
        'the counter is the desk that gets asked why their stock changed'
    );
    $h->test(
        'a cashier of ANOTHER branch does not receive it',
        !in_array($otherCashierId, $recipients, true),
        'a notice about one branch\'s finished day must not land in another branch\'s inbox'
    );

    $h->section('a batch collapses into one line');

    dl_noteForeignBackdatedEdit($db, $branchId, $pastDate, $outsider, 'correction', $totals, 'manual_adjustment');
    dl_noteForeignBackdatedEdit($db, $branchId, $pastDate, $outsider, 'correction', $totals, 'manual_adjustment');
    $after = $db->query("SELECT finding_count FROM dl_integrity_notifications WHERE aggregate_key LIKE 'backdated_ledger_edit:{$branchId}:%' LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];
    $h->test('still one notice', $countNotices() === 1, '28 rows in one night must read as one line, not 28');
    $h->test('its count records how many edits landed', (int)($after['finding_count'] ?? 0) === 3);

    $h->section('the notice stays quiet where it should');

    $before = $countNotices();
    dl_noteForeignBackdatedEdit($db, $branchId, $today, $outsider, 'correction', $totals, 'manual_adjustment');
    $h->test('today is not a "finished day"', $countNotices() === $before, 'work on the current day is not a mystery');

    dl_noteForeignBackdatedEdit($db, $branchId, $pastDate, $insider, 'correction', $totals, 'manual_adjustment');
    $h->test(
        'the branch supervisor editing their own past day raises nothing',
        $countNotices() === $before,
        'telling a branch about its own click is noise'
    );

    $h->section('the schema and the sheet carry it');

    $findingTypes = $db->query("SHOW COLUMNS FROM dl_integrity_notifications LIKE 'finding_type'")->fetch(PDO::FETCH_ASSOC) ?: [];
    $h->test(
        'the finding type exists in the deployed schema',
        str_contains((string)($findingTypes['Type'] ?? ''), 'ledger_backdated_edit'),
        'migration 102 must have run before the notice can be stored'
    );

    $sheet = (string)file_get_contents($base . '/templates/modules/daily-ledger/cashier/ledger.disyl');
    $h->test(
        'the cashier sheet renders the notice',
        str_contains($sheet, 'data-backdated-notices') && str_contains($sheet, 'backdated_notices'),
        'a notice nobody can see does not fix the confusion'
    );

    $handler = (string)file_get_contents($base . '/modules/daily-ledger/handlers.php');
    $offline = (string)file_get_contents($base . '/modules/daily-ledger/handlers-offline.php');
    $h->test(
        'both write paths raise it',
        str_contains($handler, 'dl_noteForeignBackdatedEdit($ctx->db()') && str_contains($offline, 'dl_noteForeignBackdatedEdit($ctx->db()'),
        'the offline replay has walked around online rules before'
    );
} finally {
    $cleanup();
}

$h->done();
