<?php

declare(strict_types=1);

/**
 * Daily Ledger — a production correction preserves the cashier's count and
 * results only in a resolvable variance (B4).
 *
 * Two halves, both owner-ruled:
 *   1. Path A (dl_correctDeliveryByDr) and Path B (a Daily Sheet correction)
 *      never edit the cashier's receiving rows. They raise an unreviewed
 *      delivery sent-vs-received variance through the existing flow instead.
 *   2. An admin, and only an admin, later makes an explicit choice: accept
 *      production (correct the count, preserving the original as evidence) or
 *      keep the count as evidence. Both record actor/time/note, both are
 *      editable afterwards with prior revisions in the audit log.
 *
 * Tenant 207 (baronledger). Every fixture is synthetic and removed in finally.
 */

ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-preserve-cashier-variance', TestHarness::MODE_INTEGRATION, 'baronledger.test');
ob_end_clean();
$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/helpers.php';
require_once $base . '/modules/daily-ledger/handlers-deliveries.php';
require_once $base . '/modules/daily-ledger/handlers.php';
$h->fingerprint('modules/daily-ledger/handlers.php');
$h->fingerprint('modules/daily-ledger/handlers-deliveries.php');
$h->fingerprint('modules/daily-ledger/database/migrations/069_preserve_cashier_variance.sql');
$h->fingerprint('templates/modules/daily-ledger/admin/variances.disyl');
$h->fingerprint('tests/daily-ledger/daily_ledger_preserve_cashier_variance_harness.php');
$h->allowLogLines('disyl.compile.phases');
app()->tenant()->setTenantId(207);
$ctx = modulePushContext('daily-ledger');
$db = $ctx->db();

$commissary = 99601;
$destination = 99602;
$product = 99601;
$deliveryA = 996801;
$deliveryB = 996802;
$cellDr = 'PC-CELL-1';
$date = '2034-06-15';
$cellDate = '2034-06-16';
$payloadFile = tempnam(sys_get_temp_dir(), 'dl-preserve-');

$countTenant = static function () use ($db): array {
    return [
        'deliveries' => (int)$db->query('SELECT COUNT(*) FROM dl_deliveries')->fetchColumn(),
        'receivings' => (int)$db->query('SELECT COUNT(*) FROM dl_branch_receivings')->fetchColumn(),
        'delivery_variance_flags' => (int)$db->query('SELECT COUNT(*) FROM dl_delivery_variance_flags')->fetchColumn(),
        'notifications' => (int)$db->query('SELECT COUNT(*) FROM dl_integrity_notifications')->fetchColumn(),
        'recipients' => (int)$db->query('SELECT COUNT(*) FROM dl_integrity_notification_recipients')->fetchColumn(),
    ];
};

$cleanup = static function () use ($db, $commissary, $destination, $product, $deliveryA, $deliveryB, $date, $cellDate): void {
    $branchIds = "{$commissary},{$destination}";
    $deliveryIds = "{$deliveryA},{$deliveryB}";
    $dates = "'{$date}','{$cellDate}'";
    $db->execute("DELETE FROM dl_integrity_notification_recipients WHERE notification_id IN (SELECT id FROM dl_integrity_notifications WHERE branch_id IN ({$branchIds}) OR aggregate_key LIKE 'delivery-variance-%')");
    $db->execute("DELETE FROM dl_integrity_notifications WHERE branch_id IN ({$branchIds}) OR aggregate_key LIKE 'delivery-variance-%'");
    $db->execute("DELETE FROM audit_logs WHERE module='daily-ledger' AND branch_id IN ({$branchIds})");
    $db->execute("DELETE FROM dl_delivery_variance_flags WHERE delivery_id IN ({$deliveryIds}) OR product_id = {$product}");
    $db->execute("DELETE FROM dl_delivery_ledger_effects WHERE delivery_id IN ({$deliveryIds})");
    $db->execute("DELETE FROM dl_branch_receivings WHERE branch_id IN ({$branchIds})");
    $db->execute("DELETE FROM dl_delivery_items WHERE delivery_id IN ({$deliveryIds}) OR product_id = {$product}");
    $db->execute("DELETE FROM dl_deliveries WHERE id IN ({$deliveryIds}) OR destination_id IN ({$branchIds}) OR origin_id IN ({$branchIds})");
    $db->execute("DELETE FROM dl_variance_flags WHERE branch_id IN ({$branchIds}) OR product_id = {$product}");
    $db->execute("DELETE FROM dl_ledger_shift_status WHERE branch_id IN ({$branchIds}) AND ledger_date IN ({$dates})");
    $db->execute("DELETE FROM dl_daily_ledger WHERE branch_id IN ({$branchIds}) AND product_id = {$product} AND ledger_date IN ({$dates})");
    $db->execute("DELETE FROM dl_commissary_product_ledger WHERE commissary_branch_id IN ({$branchIds}) AND product_id = {$product} AND ledger_date IN ({$dates})");
    $db->execute("DELETE FROM dl_branch_products WHERE branch_id IN ({$branchIds}) AND product_id = {$product}");
    $db->execute("DELETE FROM dl_products WHERE id = {$product}");
    $db->execute("DELETE FROM dl_branches WHERE id IN ({$branchIds})");
};
$cleanup();

$runApi = static function (string $mode, string $role, array $body = [], array $get = []) use ($payloadFile): array {
    file_put_contents($payloadFile, json_encode(['role' => $role, 'body' => $body, 'get' => $get], JSON_THROW_ON_ERROR));
    $out = [];
    $code = 0;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/daily_ledger_preserve_cashier_variance_harness.php') . ' ' . escapeshellarg($mode) . ' ' . escapeshellarg($payloadFile) . ' 2>/dev/null', $out, $code);
    return ['code' => $code, 'body' => json_decode(implode("\n", $out), true), 'raw' => implode("\n", $out)];
};

$receivingDump = static function (int $receivingId) use ($db): array {
    $head = $db->query("SELECT * FROM dl_branch_receivings WHERE id = {$receivingId}")->fetch(PDO::FETCH_ASSOC) ?: [];
    $items = $db->query("SELECT * FROM dl_branch_receiving_items WHERE receiving_id = {$receivingId} ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    return ['head' => $head, 'items' => $items];
};
$emit = static function (string $label, $data): void {
    echo '    [measured] ' . $label . ' = ' . json_encode($data) . PHP_EOL;
};

$tenantBefore = $countTenant();

try {
    $db->prepare('INSERT INTO dl_branches (id,code,name,is_commissary,is_active) VALUES (?,?,?,1,1)')->execute([$commissary, 'PC-COMM', 'PC Commissary']);
    $db->prepare('INSERT INTO dl_branches (id,code,name,is_commissary,assigned_commissary_id,default_supply_mode,is_active) VALUES (?,?,?,0,?,"commissary_supplied",1)')->execute([$destination, 'PC-DEST', 'PC Destination', $commissary]);
    $db->prepare('INSERT INTO dl_products (id,sku,name,current_price,is_active) VALUES (?,?,?,10,1)')->execute([$product, 'PC-P', 'PC Product']);
    $db->prepare('INSERT INTO dl_branch_products (branch_id,product_id,is_active) VALUES (?,?,1)')->execute([$commissary, $product]);
    // G2: the Daily Sheet delivery path requires the destination to carry the product too.
    $db->prepare('INSERT INTO dl_branch_products (branch_id,product_id,is_active) VALUES (?,?,1)')->execute([$destination, $product]);
    $db->prepare('INSERT INTO dl_commissary_product_ledger (commissary_branch_id,product_id,ledger_date,beg_qty,produced_qty,dispatched_qty,actual_end_qty) VALUES (?,?,?,30,30,20,40)')->execute([$commissary, $product, $date]);

    $itemA = 0;
    $itemB = 0;
    foreach ([[$deliveryA, 'PC-DR-A', &$itemA], [$deliveryB, 'PC-DR-B', &$itemB]] as $spec) {
        $db->prepare('INSERT INTO dl_deliveries (id,origin_type,origin_id,destination_type,destination_id,dr_number,delivery_date,status,receipt_required) VALUES (?,"commissary",?,"branch",?,?,?,"posted",1)')
            ->execute([$spec[0], $commissary, $destination, $spec[1], $date]);
        $db->prepare('INSERT INTO dl_delivery_items (delivery_id,product_id,quantity,unit,price_snapshot) VALUES (?,?,10,"pcs",10)')
            ->execute([$spec[0], $product]);
        $spec[2] = (int)$db->lastInsertId();
    }
    $receivingA = dl_acceptFormalDelivery($db, $destination, $deliveryA, 1, $date, [$product => 10], 'AM');
    $receivingB = dl_acceptFormalDelivery($db, $destination, $deliveryB, 1, $date, [$product => 10], 'AM');

    // ── Path A: production corrects both dispatches 10 -> 4 ────────────────
    $dumpA_before = $receivingDump($receivingA);
    $dumpB_before = $receivingDump($receivingB);
    $ledgerBefore = (int)$db->query("SELECT addtl FROM dl_daily_ledger WHERE branch_id={$destination} AND product_id={$product} AND ledger_date='{$date}' AND shift='AM'")->fetchColumn();

    $correctA = dl_correctDeliveryByDr($db, [
        'branch_id' => $destination, 'dr_number' => 'PC-DR-A', 'reason' => 'production corrected to 4',
        'desired' => [$product => 4], 'role' => 'admin', 'actor_id' => 1, 'business_date' => $date,
        'has_override' => true, 'shift' => 'AM',
    ]);
    $correctB = dl_correctDeliveryByDr($db, [
        'branch_id' => $destination, 'dr_number' => 'PC-DR-B', 'reason' => 'production corrected to 4',
        'desired' => [$product => 4], 'role' => 'admin', 'actor_id' => 1, 'business_date' => $date,
        'has_override' => true, 'shift' => 'AM',
    ]);

    $dumpA_after = $receivingDump($receivingA);
    $dumpB_after = $receivingDump($receivingB);
    $emit('receiving-A BEFORE', $dumpA_before);
    $emit('receiving-A AFTER', $dumpA_after);
    $ledgerAfter = (int)$db->query("SELECT addtl FROM dl_daily_ledger WHERE branch_id={$destination} AND product_id={$product} AND ledger_date='{$date}' AND shift='AM'")->fetchColumn();
    $sentA = (int)$db->query("SELECT quantity FROM dl_delivery_items WHERE id={$itemA}")->fetchColumn();
    $dispatched = (int)$db->query("SELECT dispatched_qty FROM dl_commissary_product_ledger WHERE commissary_branch_id={$commissary} AND product_id={$product} AND ledger_date='{$date}'")->fetchColumn();

    $flagA = $db->query("SELECT * FROM dl_variance_flags WHERE kind='delivery' AND delivery_id={$deliveryA} AND product_id={$product}")->fetch(PDO::FETCH_ASSOC) ?: [];
    $flagB = $db->query("SELECT * FROM dl_variance_flags WHERE kind='delivery' AND delivery_id={$deliveryB} AND product_id={$product}")->fetch(PDO::FETCH_ASSOC) ?: [];
    $flagAId = (int)($flagA['id'] ?? 0);
    $flagBId = (int)($flagB['id'] ?? 0);
    $noticeA = $db->query("SELECT n.id, r.user_id, r.notified_at, r.seen_at FROM dl_integrity_notifications n JOIN dl_integrity_notification_recipients r ON r.notification_id=n.id WHERE n.aggregate_key='delivery-variance-{$flagAId}' ORDER BY r.user_id LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];

    $emit('variance-A row', $flagA);
    $emit('notification-A recipient', $noticeA);
    $emit('dispatch sent A / commissary dispatched', ['sent' => $sentA, 'dispatched' => $dispatched, 'branch_addtl' => $ledgerAfter]);

    $h->section('Path A: the production correction preserves the cashier count');
    $h->test('the cashier receiving header and items are byte-identical before/after the correction (revert edits them)', $dumpA_before === $dumpA_after && $dumpB_before === $dumpB_after, json_encode(['before' => $dumpA_before, 'after' => $dumpA_after]));
    $h->test('the corrected dispatch quantity is 4 and the commissary dispatched reflects 20-12=8 (revert leaves 10/20)', !empty($correctA['ok']) && !empty($correctB['ok']) && $sentA === 4 && $dispatched === 8, json_encode([$correctA, $correctB, 'sent' => $sentA, 'dispatched' => $dispatched]));
    $h->test('the destination branch ledger is NOT moved: addtl stays 20 (revert moves it to 8)', $ledgerBefore === 20 && $ledgerAfter === 20, "before={$ledgerBefore} after={$ledgerAfter}");
    $h->test('an unreviewed delivery variance exists with sent 4 / received 10 (revert raises none)', ($flagA['sent_qty'] ?? null) === 4 && ($flagA['received_qty'] ?? null) === 10 && ($flagA['variance'] ?? null) === 6 && ($flagA['resolution_status'] ?? '') === 'unreviewed' && ($flagA['original_counted_qty'] ?? null) === 10, json_encode($flagA));
    $h->test('both corrected deliveries each carry their own unreviewed variance', $flagAId > 0 && $flagBId > 0 && $flagAId !== $flagBId && ($flagB['sent_qty'] ?? null) === 4 && ($flagB['received_qty'] ?? null) === 10, json_encode($flagB));
    $h->test('a recorded notification is addressed to an admin recipient with a notified timestamp (revert no notification)', (int)($noticeA['user_id'] ?? 0) > 0 && ($noticeA['notified_at'] ?? null) !== null, json_encode($noticeA));

    // ── The two admin outcomes, from the same starting fixture ─────────────
    $h->section('The acceptance decision is an explicit, admin-only choice');
    $directShortcut = $runApi('variance', 'admin', ['variance_id' => $flagAId, 'status' => 'corrected', 'review_note' => 'should be refused']);
    $supervisorDecide = $runApi('decide', 'supervisor', ['variance_id' => $flagAId, 'choice' => 'accept_production', 'review_note' => 'supervisor must not']);
    $flagAAtSupervisor = (string)$db->query("SELECT resolution_status FROM dl_variance_flags WHERE id={$flagAId}")->fetchColumn();
    $unreviewedDecide = $runApi('decide', 'admin', ['variance_id' => $flagAId, 'choice' => 'accept_production', 'review_note' => 'too early']);
    $investigateA = $runApi('variance', 'supervisor', ['variance_id' => $flagAId, 'status' => 'investigated', 'review_note' => 'checked the production sheet']);

    $h->test('generic endpoint refuses unreviewed -> corrected for a delivery variance (revert settles it with no choice)', (int)($directShortcut['body']['status'] ?? 0) === 409 && ($directShortcut['body']['ok'] ?? null) === false && str_contains((string)($directShortcut['body']['error'] ?? ''), 'admin decision'), $directShortcut['raw']);
    $h->test('a supervisor is refused the acceptance decision entirely (revert lets a non-admin decide)', ($supervisorDecide['body']['ok'] ?? null) !== true && $flagAAtSupervisor === 'unreviewed', $supervisorDecide['raw']);
    $h->test('the decision is refused while the finding is still unreviewed (revert resolves before investigation)', (int)($unreviewedDecide['body']['status'] ?? 0) === 409 && str_contains((string)($unreviewedDecide['body']['error'] ?? ''), 'investigated'), $unreviewedDecide['raw']);
    $h->test('a supervisor may still investigate (the unreviewed->investigated gate is preserved)', !empty($investigateA['body']['ok']), $investigateA['raw']);

    // The resolve surface must name the choice while the finding is awaiting it.
    $variancesPage = $runApi('variances-page', 'admin', [], ['branch_id' => $destination, 'date_from' => $date, 'date_to' => $date]);
    $flagANoticeSeen = $db->query("SELECT r.seen_at FROM dl_integrity_notification_recipients r JOIN dl_integrity_notifications n ON n.id=r.notification_id WHERE n.aggregate_key='delivery-variance-{$flagAId}' AND r.user_id=1")->fetchColumn();
    $auditorPage = $runApi('variances-page', 'auditor', [], ['branch_id' => $destination, 'date_from' => $date, 'date_to' => $date]);
    $h->test('the resolve page names both choices and shows sent vs counted (revert silently applies production)', str_contains($variancesPage['raw'], 'Accept production') && str_contains($variancesPage['raw'], 'Keep as evidence') && str_contains($variancesPage['raw'], 'Sent 4') && str_contains($variancesPage['raw'], 'cashier counted 10') && str_contains($variancesPage['raw'], 'onclick="decideDeliveryVariance('), 'len=' . strlen($variancesPage['raw']));
    $h->test('the resolve page links the delivery variance back to the Production Delivery Audit (revert strands the investigation)', str_contains($variancesPage['raw'], '/admin/trace?') && str_contains($variancesPage['raw'], 'variance_id=' . $flagAId), 'variance_id=' . $flagAId);
    $h->test('an auditor can read the resolve page but is told only an admin may decide (revert lets an auditor decide)', str_contains($auditorPage['raw'], 'Only an admin may make the acceptance decision') && !str_contains($auditorPage['raw'], 'onclick="decideDeliveryVariance('), 'auditor page len=' . strlen($auditorPage['raw']));
    $h->test('rendering the resolve page records a real seen state on the notification (revert leaves it unseen)', $flagANoticeSeen !== false && $flagANoticeSeen !== null, 'seen_at=' . (string)$flagANoticeSeen);

    $acceptA = $runApi('decide', 'admin', ['variance_id' => $flagAId, 'choice' => 'accept_production', 'review_note' => 'accepted against the signed production sheet']);
    $flagAAfterAccept = $db->query("SELECT resolution_status, resolution_choice, original_counted_qty, reviewed_by, reviewed_at, review_note FROM dl_variance_flags WHERE id={$flagAId}")->fetch(PDO::FETCH_ASSOC) ?: [];
    $countA = (int)$db->query("SELECT COALESCE(SUM(quantity_received),0) FROM dl_branch_receiving_items WHERE receiving_id={$receivingA}")->fetchColumn();
    $ledgerAfterAccept = (int)$db->query("SELECT addtl FROM dl_daily_ledger WHERE branch_id={$destination} AND product_id={$product} AND ledger_date='{$date}' AND shift='AM'")->fetchColumn();

    $investigateB = $runApi('variance', 'admin', ['variance_id' => $flagBId, 'status' => 'investigated', 'review_note' => 'checked']);
    $keepB = $runApi('decide', 'admin', ['variance_id' => $flagBId, 'choice' => 'keep_as_evidence', 'review_note' => 'the physical count stands as evidence']);
    $flagBAfterKeep = $db->query("SELECT resolution_status, resolution_choice, original_counted_qty, review_note FROM dl_variance_flags WHERE id={$flagBId}")->fetch(PDO::FETCH_ASSOC) ?: [];
    $dumpBAfterKeep = $receivingDump($receivingB);

    $emit('accept production -> variance-A', $flagAAfterAccept);
    $emit('accept production -> receiving-A total', $countA);
    $h->test('accept production sets the cashier count to 4 and stores actor/time/note (revert silently applies production)', !empty($acceptA['body']['ok']) && $countA === 4 && ($flagAAfterAccept['resolution_status'] ?? '') === 'corrected' && ($flagAAfterAccept['resolution_choice'] ?? '') === 'accept_production' && (int)($flagAAfterAccept['reviewed_by'] ?? 0) === 1 && ($flagAAfterAccept['reviewed_at'] ?? null) !== null && ($flagAAfterAccept['review_note'] ?? '') === 'accepted against the signed production sheet', json_encode([$acceptA['body'], $flagAAfterAccept, 'count' => $countA]));
    $h->test('the original counted 10 survives on the flag after accept production (revert destroys the evidence)', (int)($flagAAfterAccept['original_counted_qty'] ?? 0) === 10, json_encode($flagAAfterAccept));
    $h->test('accept production moves the branch ledger from 20 to 14 to match the corrected count', $ledgerAfterAccept === 14, "addtl={$ledgerAfterAccept}");
    $emit('keep as evidence -> variance-B', $flagBAfterKeep);
    $emit('keep as evidence -> receiving-B', $dumpBAfterKeep);
    $h->test('keep as evidence leaves the cashier count at 10 and closes on that basis (revert overwrites it)', !empty($keepB['body']['ok']) && ($flagBAfterKeep['resolution_choice'] ?? '') === 'keep_as_evidence' && ($flagBAfterKeep['resolution_status'] ?? '') === 'corrected' && (int)($flagBAfterKeep['original_counted_qty'] ?? 0) === 10 && $dumpBAfterKeep === $dumpB_before, json_encode([$keepB['body'], $flagBAfterKeep]));

    // ── Editable afterwards, prior revisions retained ──────────────────────
    $h->section('Both choices are editable with prior revisions retained');
    $reopenA = $runApi('variance', 'admin', ['variance_id' => $flagAId, 'status' => 'investigated', 'review_note' => 'reopened to reconsider']);
    $keepA = $runApi('decide', 'admin', ['variance_id' => $flagAId, 'choice' => 'keep_as_evidence', 'review_note' => 'changed my mind: keep the count']);
    $flagAChanged = $db->query("SELECT resolution_status, resolution_choice, original_counted_qty, review_note FROM dl_variance_flags WHERE id={$flagAId}")->fetch(PDO::FETCH_ASSOC) ?: [];
    $countAChanged = (int)$db->query("SELECT COALESCE(SUM(quantity_received),0) FROM dl_branch_receiving_items WHERE receiving_id={$receivingA}")->fetchColumn();
    $ledgerAChanged = (int)$db->query("SELECT addtl FROM dl_daily_ledger WHERE branch_id={$destination} AND product_id={$product} AND ledger_date='{$date}' AND shift='AM'")->fetchColumn();
    $revisions = (int)$db->query("SELECT COUNT(*) FROM audit_logs WHERE module='daily-ledger' AND action='delivery_variance_resolution' AND entity_type='dl_variance_flags' AND entity_id='{$flagAId}'")->fetchColumn();

    $h->test('reopen (corrected -> investigated) is allowed and the decision can be changed (revert erases the choice)', !empty($reopenA['body']['ok']) && !empty($keepA['body']['ok']) && ($flagAChanged['resolution_choice'] ?? '') === 'keep_as_evidence' && ($flagAChanged['review_note'] ?? '') === 'changed my mind: keep the count', json_encode([$reopenA['body'], $keepA['body'], $flagAChanged]));
    $h->test('changing to keep-as-evidence restores the preserved original count of 10 and the ledger back to 20', $countAChanged === 10 && $ledgerAChanged === 20, "count={$countAChanged} addtl={$ledgerAChanged}");
    $h->test('both resolution revisions are retained in the audit log (revert keeps only the latest)', $revisions === 2, "revisions={$revisions}");

    // ── Path B: a Daily Sheet correction is stored non-receivable ──────────
    $h->section('Path B: a Daily Sheet correction never waits on a cashier');
    $cellFirst = dl_recordDailySheetBranchEntry(['id' => 1, 'sub' => 'admin:1', 'role' => 'admin', 'source' => 'daily-ledger', 'name' => 'Test Admin'], [
        'date' => $cellDate, 'commissary_branch_id' => $commissary, 'destination_branch_id' => $destination,
        'product_id' => $product, 'quantity' => 10, 'submission_id' => 'pc-cell-first',
    ]);
    $cellDeliveryId = (int)$cellFirst['delivery_id'];
    $cellItemId = (int)$cellFirst['item_id'];
    $cellReceiving = dl_acceptFormalDelivery($db, $destination, $cellDeliveryId, 1, $cellDate, [$product => 10], 'AM');
    $cellBefore = $receivingDump($cellReceiving);

    $cellCorrection = dl_recordDailySheetBranchEntry(['id' => 1, 'sub' => 'admin:1', 'role' => 'admin', 'source' => 'daily-ledger', 'name' => 'Test Admin'], [
        'date' => $cellDate, 'commissary_branch_id' => $commissary, 'destination_branch_id' => $destination,
        'product_id' => $product, 'quantity' => -6, 'type' => 'correction', 'reason_code' => 'encoder_omission',
        'submission_id' => 'pc-cell-correction',
    ]);
    $correctionDeliveryId = (int)$cellCorrection['delivery_id'];
    $correctionReceipt = $db->query("SELECT receipt_required FROM dl_deliveries WHERE id={$correctionDeliveryId}")->fetchColumn();
    $cellAfter = $receivingDump($cellReceiving);
    $incoming = $runApi('incoming', 'cashier', [], ['branch_id' => $destination]);
    $correctionInIncoming = false;
    foreach (($incoming['body']['deliveries'] ?? []) as $group) {
        foreach (($group['delivery_ids'] ?? []) as $did) {
            if ((int)$did === $correctionDeliveryId) { $correctionInIncoming = true; }
        }
    }
    $cellEffective = dl_dailySheetCellQuantity($db, $cellDate, $commissary, $product, $destination);
    $cellFlag = $db->query("SELECT * FROM dl_variance_flags WHERE kind='delivery' AND delivery_id={$cellDeliveryId} AND product_id={$product}")->fetch(PDO::FETCH_ASSOC) ?: [];

    $emit('cell correction delivery', ['delivery_id' => $correctionDeliveryId, 'receipt_required' => $correctionReceipt, 'effective' => $cellEffective]);
    $emit('cell variance row', $cellFlag);
    $h->test('a Daily Sheet correction is stored non-receivable (revert stores it as a receivable dispatch)', (string)$correctionReceipt === '0' && $correctionDeliveryId !== $cellDeliveryId, 'receipt_required=' . var_export($correctionReceipt, true));
    $handlersSource = (string)file_get_contents($base . '/modules/daily-ledger/handlers.php');
    $receiptRequiredFilters = substr_count($handlersSource, 'COALESCE(d.receipt_required, 1) = 1');
    $h->test('the correction does not appear in the cashier receive surface (revert offers it, blocking the cashier)', $correctionInIncoming === false && !empty($correctionDeliveryId), json_encode($incoming['body']['deliveries'] ?? []));
    $h->test('the cashier pending count and the receive surface both filter on the stored receivability flag (revert counts the correction)', $receiptRequiredFilters >= 2, 'filters=' . $receiptRequiredFilters);
    $h->test('the correction raises the same unreviewed variance, received 10 vs effective sent 4 (revert raises none)', ($cellFlag['sent_qty'] ?? null) === 4 && ($cellFlag['received_qty'] ?? null) === 10 && ($cellFlag['resolution_status'] ?? '') === 'unreviewed' && $cellEffective === 4, json_encode($cellFlag));
    $h->test('the cashier receiving rows are byte-identical after the Daily Sheet correction (revert edits them)', $cellBefore === $cellAfter, json_encode(['before' => $cellBefore, 'after' => $cellAfter]));

    // ── Correction to zero settles ─────────────────────────────────────────
    $h->section('A correction to zero reaches a settled, usable state');
    $zeroCorrection = dl_recordDailySheetBranchEntry(['id' => 1, 'sub' => 'admin:1', 'role' => 'admin', 'source' => 'daily-ledger', 'name' => 'Test Admin'], [
        'date' => $cellDate, 'commissary_branch_id' => $commissary, 'destination_branch_id' => $destination,
        'product_id' => $product, 'quantity' => -4, 'type' => 'correction', 'reason_code' => 'encoder_omission',
        'submission_id' => 'pc-cell-correction-zero',
    ]);
    $zeroDeliveryId = (int)$zeroCorrection['delivery_id'];
    $zeroReceipt = $db->query("SELECT receipt_required FROM dl_deliveries WHERE id={$zeroDeliveryId}")->fetchColumn();
    $zeroEffective = dl_dailySheetCellQuantity($db, $cellDate, $commissary, $product, $destination);
    $zeroFlag = $db->query("SELECT * FROM dl_variance_flags WHERE kind='delivery' AND delivery_id={$cellDeliveryId} AND product_id={$product}")->fetch(PDO::FETCH_ASSOC) ?: [];
    $emit('zero correction', ['delivery_id' => $zeroDeliveryId, 'receipt_required' => $zeroReceipt, 'effective' => $zeroEffective, 'variance' => $zeroFlag]);
    $h->test('a correction to an effective zero is non-receivable, leaves the cell at 0, and still surfaces a resolvable variance (revert leaves it pending forever)', (string)$zeroReceipt === '0' && $zeroEffective === 0 && ($zeroFlag['sent_qty'] ?? null) === 0 && ($zeroFlag['received_qty'] ?? null) === 10 && ($zeroFlag['resolution_status'] ?? '') !== '', "receipt={$zeroReceipt} effective={$zeroEffective} flag=" . json_encode($zeroFlag));
} finally {
    $cleanup();
    if (is_file($payloadFile)) { unlink($payloadFile); }
}

$tenantAfter = $countTenant();

$h->section('Tenant datum queried before and after');
$h->test('delivery / receiving / delivery-variance / notification / recipient counts are identical before and after (removing fixture cleanup makes this fail)', $tenantAfter === $tenantBefore, 'before=' . json_encode($tenantBefore) . ' after=' . json_encode($tenantAfter));
echo '  measured tenant counts: before=' . json_encode($tenantBefore) . ' after=' . json_encode($tenantAfter) . PHP_EOL;

$h->done();
