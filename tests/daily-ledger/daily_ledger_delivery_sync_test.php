<?php

declare(strict_types=1);

/**
 * Daily Ledger — an admin quantity correction by DR lands on BOTH branches.
 *
 * Owner direction 2026-11-16 (client concern #4, Sir Ryan): when the two sides
 * of a delivery disagree (e.g. 45 sent, 25 actually received), the office
 * changes the quantity ONCE against the DR number and both ledgers move — the
 * sender's withdrawal and the receiver's additional — so nobody has to open the
 * other branch to check whether it changed.
 *
 *   AC1  dl_correctDeliveryByDr(sync_destination = true) moves the delivery item,
 *        the origin dispatched quantity AND the receiving counted quantity.
 *   AC2  the destination branch's addtl is moved by the difference.
 *   AC3  the sent-vs-received variance raised by the receiving is settled by the
 *        correction (accept_production) with the correction reason recorded.
 *   AC4  the branch path (sync_destination = false) still leaves the cashier's
 *        count untouched and raises a variance — the evidence rule is preserved.
 *   AC5  only the admin HTTP path sets the flag.
 *
 * Tenant 207. Every fixture is synthetic and removed in finally.
 */

ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-delivery-sync', TestHarness::MODE_INTEGRATION, 'baronledger.test');
ob_end_clean();

$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/helpers.php';
require_once $base . '/modules/daily-ledger/handlers-deliveries.php';
require_once $base . '/modules/daily-ledger/handlers.php';

$h->fingerprint('modules/daily-ledger/handlers-deliveries.php');
$h->fingerprint('templates/modules/daily-ledger/cashier/edit_delivery_modal.disyl');

app()->tenant()->setTenantId(207);
$ctx = modulePushContext('daily-ledger');
if (!$ctx) {
    fwrite(STDERR, "daily-ledger module context unavailable\n");
    exit(1);
}
$db = $ctx->db();

$commissary = 99881;
$destination = 99882;
$product = 99881;
$productNew = 99883;
$deliveryAdmin = 998881;
$deliveryBranch = 998882;
$deliveryGap = 998883;
$date = '2035-06-10';
$dateBranch = '2035-06-11';
$drAdmin = 'SYNC-DR-ADMIN';
$drBranch = 'SYNC-DR-BRANCH';

$cleanup = static function () use ($db, $commissary, $destination, $product, $productNew, $deliveryAdmin, $deliveryBranch, $deliveryGap, $date, $dateBranch): void {
    $branches = "{$commissary},{$destination}";
    $deliveries = "{$deliveryAdmin},{$deliveryBranch},{$deliveryGap}";
    $dates = "'{$date}','{$dateBranch}'";
    $db->execute("DELETE FROM dl_integrity_notification_recipients WHERE notification_id IN (SELECT id FROM dl_integrity_notifications WHERE branch_id IN ({$branches}) OR aggregate_key LIKE 'delivery-variance-%')");
    $db->execute("DELETE FROM dl_integrity_notifications WHERE branch_id IN ({$branches}) OR aggregate_key LIKE 'delivery-variance-%'");
    $db->execute("DELETE FROM audit_logs WHERE module='daily-ledger' AND branch_id IN ({$branches})");
    $db->execute("DELETE FROM dl_delivery_variance_flags WHERE delivery_id IN ({$deliveries})");
    $db->execute("DELETE FROM dl_variance_flags WHERE branch_id IN ({$branches}) OR product_id IN ({$product},{$productNew})");
    $db->execute("DELETE FROM dl_branch_receiving_items WHERE receiving_id IN (SELECT id FROM dl_branch_receivings WHERE branch_id IN ({$branches}))");
    $db->execute("DELETE FROM dl_branch_receivings WHERE branch_id IN ({$branches}) OR delivery_id IN ({$deliveries})");
    $db->execute("DELETE FROM dl_delivery_ledger_effects WHERE delivery_id IN ({$deliveries})");
    $db->execute("DELETE FROM dl_delivery_items WHERE delivery_id IN ({$deliveries})");
    $db->execute("DELETE FROM dl_deliveries WHERE id IN ({$deliveries}) OR destination_id IN ({$branches}) OR origin_id IN ({$branches})");
    $db->execute("DELETE FROM dl_ledger_shift_status WHERE branch_id IN ({$branches}) AND ledger_date IN ({$dates})");
    $db->execute("DELETE FROM dl_ledger_day_status WHERE branch_id IN ({$branches}) AND ledger_date IN ({$dates})");
    $db->execute("DELETE FROM dl_daily_ledger WHERE branch_id IN ({$branches})");
    $db->execute("DELETE FROM dl_commissary_product_ledger WHERE commissary_branch_id IN ({$branches})");
    $db->execute("DELETE FROM dl_branch_products WHERE branch_id IN ({$branches}) OR product_id IN ({$product},{$productNew})");
    $db->execute("DELETE FROM dl_products WHERE id IN ({$product},{$productNew})");
    $db->execute("DELETE FROM dl_branches WHERE id IN ({$branches})");
};

$seedDelivery = static function (int $deliveryId, string $dr, string $ledgerDate) use ($db, $commissary, $destination, $product): void {
    $db->prepare(
        'INSERT INTO dl_deliveries
            (id, origin_type, origin_id, destination_type, destination_id, dr_number, delivery_date, production_shift, status, receipt_required)
         VALUES (?, "commissary", ?, "branch", ?, ?, ?, "AM", "posted", 1)'
    )->execute([$deliveryId, $commissary, $destination, $dr, $ledgerDate]);
    $db->prepare('INSERT INTO dl_delivery_items (delivery_id, product_id, quantity, unit, price_snapshot) VALUES (?,?,45,"pcs",10)')
        ->execute([$deliveryId, $product]);
};

$cleanup();

try {
    $db->prepare('INSERT INTO dl_branches (id, code, name, is_commissary, is_active) VALUES (?,?,?,1,1)')
        ->execute([$commissary, 'SYNC-COMM', 'Sync Commissary']);
    $db->prepare('INSERT INTO dl_branches (id, code, name, is_commissary, assigned_commissary_id, default_supply_mode, is_active) VALUES (?,?,?,0,?,"commissary_supplied",1)')
        ->execute([$destination, 'SYNC-DEST', 'Sync Destination', $commissary]);
    $db->prepare('INSERT INTO dl_products (id, sku, name, current_price, is_active) VALUES (?,?,?,10,1)')
        ->execute([$product, 'SYNC-P', 'Sync Product']);
    $db->prepare('INSERT INTO dl_products (id, sku, name, current_price, is_active) VALUES (?,?,?,10,1)')
        ->execute([$productNew, 'SYNC-P2', 'Sync Product Two']);
    $db->prepare('INSERT INTO dl_branch_products (branch_id, product_id, is_active) VALUES (?,?,1)')->execute([$commissary, $product]);
    $db->prepare('INSERT INTO dl_branch_products (branch_id, product_id, is_active) VALUES (?,?,1)')->execute([$destination, $product]);
    $db->prepare('INSERT INTO dl_branch_products (branch_id, product_id, is_active) VALUES (?,?,1)')->execute([$commissary, $productNew]);
    $db->prepare('INSERT INTO dl_branch_products (branch_id, product_id, is_active) VALUES (?,?,1)')->execute([$destination, $productNew]);

    // Both deliveries start at 45 sent. The admin fixture counted 10 (the
    // delivery difference the office will correct); the branch fixture counted 25.
    $seedDelivery($deliveryAdmin, $drAdmin, $date);
    $seedDelivery($deliveryBranch, $drBranch, $dateBranch);
    $seedDelivery($deliveryGap, 'SYNC-DR-GAP', $date);
    $receivingAdmin = dl_acceptFormalDelivery($db, $destination, $deliveryAdmin, 1, $date, [$product => 10], 'AM');
    $receivingBranch = dl_acceptFormalDelivery($db, $destination, $deliveryBranch, 1, $dateBranch, [$product => 25], 'AM');
    // Counted 0 of the first product and 1 of the second. The zero line is then
    // removed, reproducing the real gap: a receiving that exists, a delivery line
    // that exists, and NO receiving line for it.
    $receivingGap = dl_acceptFormalDelivery($db, $destination, $deliveryGap, 1, $date, [$product => 0, $productNew => 1], 'AM');
    $db->prepare('DELETE FROM dl_branch_receiving_items WHERE receiving_id = ? AND product_id = ?')->execute([$receivingGap, $product]);
    $h->test('fixtures produced three receivings', $receivingAdmin > 0 && $receivingBranch > 0 && $receivingGap > 0);
    $h->test('the gap fixture really has no receiving line for the product',
        (int)$db->query("SELECT COUNT(*) FROM dl_branch_receiving_items WHERE receiving_id = {$receivingGap} AND product_id = {$product}")->fetchColumn() === 0);

    // The commissary dispatched 45 for each fixture (a real posted dispatch would
    // carry this row; seed it so a correction has something to land on).
    $db->prepare(
        'INSERT INTO dl_commissary_product_ledger
            (commissary_branch_id, product_id, ledger_date, shift, beg_qty, produced_qty, dispatched_qty, actual_end_qty)
         VALUES (?,?,?,"AM",45,45,45,0)'
    )->execute([$commissary, $product, $date]);
    $db->prepare(
        'INSERT INTO dl_commissary_product_ledger
            (commissary_branch_id, product_id, ledger_date, shift, beg_qty, produced_qty, dispatched_qty, actual_end_qty)
         VALUES (?,?,?,"AM",45,45,45,0)'
    )->execute([$commissary, $product, $dateBranch]);

    $openVariance = static function (int $deliveryId) use ($db, $product): array {
        return $db->query("SELECT id, resolution_status, resolution_choice, sent_qty, received_qty, review_note
                             FROM dl_variance_flags
                            WHERE kind = 'delivery' AND delivery_id = {$deliveryId} AND product_id = {$product}
                            LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];
    };
    $beforeAdmin = $openVariance($deliveryAdmin);
    $h->test('the admin fixture has a sent-vs-received variance (45 vs 10)', (int)($beforeAdmin['sent_qty'] ?? 0) === 45 && (int)($beforeAdmin['received_qty'] ?? 0) === 10);

    // ─── AC1/AC2/AC3 — the ADMIN correction moves both sides ───────────
    $h->section('Admin correction by DR — both branches move');

    $result = dl_correctDeliveryByDr($db, [
        'branch_id' => $destination,
        'dr_number' => $drAdmin,
        'reason' => 'Actual received 25, DR said 45',
        'desired' => [$product => 25],
        'role' => 'admin',
        'actor_id' => 1,
        'business_date' => $date,
        'has_override' => true,
        'shift' => 'AM',
        'sync_destination' => true,
    ]);

    $h->test('the admin correction succeeds', !empty($result['ok']), (string)($result['error'] ?? ''));
    $h->test('the result reports one synchronised receiving line', (int)($result['synced_received'] ?? 0) === 1);

    $itemQty = (int)$db->query("SELECT quantity FROM dl_delivery_items WHERE delivery_id = {$deliveryAdmin} AND product_id = {$product}")->fetchColumn();
    $h->test('the delivery item now reads 25', $itemQty === 25, 'qty=' . $itemQty);

    $dispatched = (int)$db->query("SELECT dispatched_qty FROM dl_commissary_product_ledger WHERE commissary_branch_id = {$commissary} AND product_id = {$product} AND ledger_date = '{$date}' AND shift = 'AM'")->fetchColumn();
    $h->test('the sender dispatched quantity moved 45 -> 25', $dispatched === 25, 'dispatched=' . $dispatched);

    $counted = (int)$db->query("SELECT quantity_received FROM dl_branch_receiving_items WHERE receiving_id = {$receivingAdmin} AND product_id = {$product}")->fetchColumn();
    $h->test('the receiver counted quantity reads 25', $counted === 25, 'counted=' . $counted);

    $addtl = (int)$db->query("SELECT addtl FROM dl_daily_ledger WHERE branch_id = {$destination} AND product_id = {$product} AND ledger_date = '{$date}' AND shift = 'AM'")->fetchColumn();
    $h->test('the destination addtl moved with the delivered quantity (10 -> 25)', $addtl === 25, 'addtl=' . $addtl);

    $afterAdmin = $openVariance($deliveryAdmin);
    $h->test('the sent-vs-received variance is settled as accepted production', ($afterAdmin['resolution_status'] ?? '') === 'corrected' && ($afterAdmin['resolution_choice'] ?? '') === 'accept_production');
    $h->test('the variance now reads 25 sent / 25 received', (int)($afterAdmin['sent_qty'] ?? 0) === 25 && (int)($afterAdmin['received_qty'] ?? 0) === 25);
    $h->test('the variance records the correction reason', str_contains((string)($afterAdmin['review_note'] ?? ''), 'Actual received 25'));

    // ─── A product the receiver never counted ─────────────────────────
    // The admin adds a missed item to the same delivery. The receiver has no line
    // for it, so the sync must CREATE one and credit the destination: returning
    // "not synced" would report success while the two ledgers silently diverged.
    $h->section('Admin adds a missing item — the receiver line is created');

    $addResult = dl_correctDeliveryByDr($db, [
        'branch_id' => $destination,
        'dr_number' => $drAdmin,
        'reason' => 'Item arrived but was not on the DR',
        'desired' => [$product => 25, $productNew => 5],
        'role' => 'admin',
        'actor_id' => 1,
        'business_date' => $date,
        'has_override' => true,
        'shift' => 'AM',
        'sync_destination' => true,
    ]);
    $h->test('the correction accepts the added item', !empty($addResult['ok']), (string)($addResult['error'] ?? ''));
    // Both submitted products are synchronised: the unchanged line is re-stated and
    // the new line is created. The count is 2, not 1 — that is the idempotent rule.
    $h->test('the result reports both synchronised receiving lines', (int)($addResult['synced_received'] ?? 0) === 2);

    $newItemQty = (int)$db->query("SELECT quantity FROM dl_delivery_items WHERE delivery_id = {$deliveryAdmin} AND product_id = {$productNew}")->fetchColumn();
    $h->test('the new delivery item reads 5', $newItemQty === 5, 'qty=' . $newItemQty);
    $newCounted = (int)$db->query("SELECT quantity_received FROM dl_branch_receiving_items WHERE receiving_id = {$receivingAdmin} AND product_id = {$productNew}")->fetchColumn();
    $h->test('a receiving line was created with 5', $newCounted === 5, 'counted=' . $newCounted);
    $newAddtl = (int)$db->query("SELECT addtl FROM dl_daily_ledger WHERE branch_id = {$destination} AND product_id = {$productNew} AND ledger_date = '{$date}' AND shift = 'AM'")->fetchColumn();
    $h->test('the destination addtl for the new item is 5, not 0', $newAddtl === 5, 'addtl=' . $newAddtl);
    $newOpenFlag = (int)$db->query("SELECT COUNT(*) FROM dl_variance_flags WHERE kind = 'delivery' AND delivery_id = {$deliveryAdmin} AND product_id = {$productNew}")->fetchColumn();
    $h->test('no phantom variance is left behind for the new item', $newOpenFlag === 0);

    // ─── Unchanged sent quantity, but no receiving line at all ─────────
    // The delivery already says 45; the receiver only counted the other product.
    // Saving the SAME quantity must still create the line and credit the branch.
    $h->section('Unchanged quantity — the missing receiver line is still created');

    $gapResult = dl_correctDeliveryByDr($db, [
        'branch_id' => $destination,
        'dr_number' => 'SYNC-DR-GAP',
        'reason' => 'Branch never counted this line',
        'desired' => [$product => 45],
        'role' => 'admin',
        'actor_id' => 1,
        'business_date' => $date,
        'has_override' => true,
        'shift' => 'AM',
        'sync_destination' => true,
    ]);
    $h->test('an unchanged-quantity correction still succeeds', !empty($gapResult['ok']), (string)($gapResult['error'] ?? ''));
    $h->test('the unchanged correction reports the synchronised line', (int)($gapResult['synced_received'] ?? 0) === 1);
    $gapLine = $db->query("SELECT quantity_received, selling_price_snapshot FROM dl_branch_receiving_items WHERE receiving_id = {$receivingGap} AND product_id = {$product}")->fetch(PDO::FETCH_ASSOC) ?: [];
    $h->test('the missing receiving line was created with the sent 45', (int)($gapLine['quantity_received'] ?? 0) === 45, json_encode($gapLine));
    $h->test('the created line keeps the delivery price snapshot, not zero', abs((float)($gapLine['selling_price_snapshot'] ?? 0) - 10.0) < 0.01, json_encode($gapLine));
    $gapAddtl = (int)$db->query("SELECT addtl FROM dl_daily_ledger WHERE branch_id = {$destination} AND product_id = {$product} AND ledger_date = '{$date}' AND shift = 'AM'")->fetchColumn();
    // 25 from the admin fixture + 45 from the gap fixture, no double count.
    $h->test('the destination addtl moved by the created line exactly once', $gapAddtl === 70, 'addtl=' . $gapAddtl);

    // ─── AC4 — a branch correction keeps the count as evidence ─────────
    $h->section('Branch correction — the cashier\'s count is evidence');

    $branchResult = dl_correctDeliveryByDr($db, [
        'branch_id' => $destination,
        'dr_number' => $drBranch,
        'reason' => 'Missed item on the paper DR',
        'desired' => [$product => 40],
        'role' => 'cashier',
        'actor_id' => 1,
        'business_date' => $dateBranch,
        'has_override' => true,
        'shift' => 'AM',
        'sync_destination' => false,
    ]);
    $h->test('the branch correction succeeds', !empty($branchResult['ok']), (string)($branchResult['error'] ?? ''));
    $h->test('nothing was synchronised for the branch path', (int)($branchResult['synced_received'] ?? 0) === 0);

    $branchCounted = (int)$db->query("SELECT quantity_received FROM dl_branch_receiving_items WHERE receiving_id = {$receivingBranch} AND product_id = {$product}")->fetchColumn();
    $h->test('the cashier\'s counted quantity is untouched (25)', $branchCounted === 25, 'counted=' . $branchCounted);

    $branchAddtl = (int)$db->query("SELECT addtl FROM dl_daily_ledger WHERE branch_id = {$destination} AND product_id = {$product} AND ledger_date = '{$dateBranch}' AND shift = 'AM'")->fetchColumn();
    $h->test('the branch path does not move the destination addtl (25)', $branchAddtl === 25, 'addtl=' . $branchAddtl);

    $branchVariance = $openVariance($deliveryBranch);
    $h->test('the branch path keeps a sent-vs-received variance (40 vs 25)', (int)($branchVariance['sent_qty'] ?? 0) === 40 && (int)($branchVariance['received_qty'] ?? 0) === 25);
    $h->test('the branch-path variance is still open', ($branchVariance['resolution_status'] ?? '') === 'unreviewed');

    // ─── AC5 — only the admin HTTP path sets the flag ──────────────────
    $h->section('Only an admin correction synchronises the receiver');

    $src = (string)file_get_contents($base . '/modules/daily-ledger/handlers-deliveries.php');
    $h->test('the API sets sync_destination for the admin role only', str_contains($src, "'sync_destination' => (\$role === 'admin')"));
    $h->test('the service defaults to NOT moving the receiver', str_contains($src, "\$syncDestination = (bool)(\$args['sync_destination'] ?? false);"));
    $h->test('the receiving sync explains itself in the audit trail', str_contains($src, "'receiving_synced_to_delivery'"));
} finally {
    $cleanup();
}

$left = (int)$db->query("SELECT COUNT(*) FROM dl_deliveries WHERE id IN ({$deliveryAdmin},{$deliveryBranch})")->fetchColumn();
$h->test('cleanup removed the delivery fixtures', $left === 0);

// ── The reply-to-a-delivery-issue control must never be offered to someone who cannot use it ──
// Found 2026-11-21 while answering a real notice on the commissary page: logged in as
// SHIELA_BAINA · ADMIN, the banner said "1 waiting for your answer" and the reply box was right
// there, but the POST returned 403 ("This page is not open to your role") because `admin` was not
// in the endpoint's role list. After adding admin, a SECOND guard still refused — the endpoint
// requires a dl_user_branches row for the sending side, because a reply is that branch's own words.
// The role gate was genuinely wrong; the assignment gate is right. So the UI now withholds the
// composer and states the reason, instead of offering a control that fails.
$h->section('answering a delivery issue is offered only where it can succeed');
$handlerSrc = (string)file_get_contents($base . '/modules/daily-ledger/handlers.php');
$commissaryTpl = (string)file_get_contents($base . '/templates/modules/daily-ledger/admin/commissary.disyl');
$h->test(
    'an admin may answer a delivery issue',
    str_contains($handlerSrc, "dlCurrentUser(['cashier', 'supervisor', 'production_in_charge', 'admin'])"),
    'admin is the office; it must not be refused by the endpoint that renders a control to it'
);
$h->test(
    'the endpoint still requires the actor to be assigned to that side of the delivery',
    str_contains($handlerSrc, 'Only a user assigned to the branch on this side may write this remark'),
    'a reply represents the branch\'s own words — that provenance guard is deliberate and must stay'
);
$h->test(
    'the page computes whether the current user can answer for this hub',
    str_contains($handlerSrc, "'can_answer_delivery_issues' => \$canAnswerDeliveryIssues")
        && str_contains($handlerSrc, 'SELECT 1 FROM dl_user_branches WHERE user_id = :u AND branch_id = :b'),
    'without it the view cannot know whether to render the composer'
);
$h->test(
    'the composer is withheld, with the reason, when the user cannot answer',
    str_contains($commissaryTpl, '{if can_answer_delivery_issues}')
        && str_contains($commissaryTpl, 'Only a user assigned to this commissary can answer this')
        && str_contains($commissaryTpl, '{else}'),
    'a reply box that 403s on submit is the 2026-11-09 dead-button defect'
);

$h->done();
