<?php

declare(strict_types=1);

/**
 * Daily Ledger — a paper DR SEALS the Daily Sheet load; it never sends the goods twice.
 *
 * OWNER WORKFLOW (2026-10-30): "usually, the production in-charge encodes the data at
 * daily sheets per branch then does the dispatch." Owner decision on the contract:
 * "an existing DR means the dispatch is done" and "if any qty discrepancy, none blocker
 * and flagged for admin to investigate and resolve".
 *
 * MEASURED BEFORE THIS FIX: every Daily Sheet cell entry is already a posted delivery
 * with its commissary `dispatched_qty` debit (handlers.php:5083). The dispatch that
 * followed created a SECOND delivery for the same goods, debited the commissary again,
 * and both rows were receivable at the branch. `dl_dailySheetCellQuantity()` sums every
 * commissary-origin row, so the cell then displayed the load twice.
 *
 * Each assertion names what breaks if it regresses:
 * - sealing instead of inserting: one delivery per load, one stock movement;
 * - a repeated DR answered with 422: the operator is blocked on work already done;
 * - a disagreement answered with 422: the variance never reaches an admin;
 * - no provenance stamp: the sealed load never appears in the review queue;
 * - the sender-scoped duplicate query keeping no date: an old day's DR matches.
 */

ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-dispatch-dr-seal', TestHarness::MODE_INTEGRATION, 'baronledger.test');
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
$h->fingerprint('modules/daily-ledger/handlers.php');
$h->fingerprint('modules/daily-ledger/handlers-deliveries.php');
$h->fingerprint('templates/modules/daily-ledger/admin/commissary.disyl');
$h->fingerprint('templates/modules/daily-ledger/cashier/dispatch_modal.disyl');
$h->fingerprint('tests/daily-ledger/daily_ledger_dispatch_dr_seal_harness.php');

app()->tenant()->setTenantId(207);
$ctx = modulePushContext('daily-ledger');
$db = $ctx->db();

$handlers = (string) file_get_contents($base . '/modules/daily-ledger/handlers.php');
$deliveriesSrc = (string) file_get_contents($base . '/modules/daily-ledger/handlers-deliveries.php');
$commissaryTpl = (string) file_get_contents($base . '/templates/modules/daily-ledger/admin/commissary.disyl');
$dispatchTpl = (string) file_get_contents($base . '/templates/modules/daily-ledger/cashier/dispatch_modal.disyl');

$fnBody = static function (string $src, string $fn): string {
    $start = strpos($src, 'function ' . $fn . '(');
    if ($start === false) {
        return '';
    }
    $next = strpos($src, "\nfunction ", $start + 10);
    return substr($src, $start, ($next === false ? strlen($src) : $next) - $start);
};

// ---------------------------------------------------------------- fixture

$comm = 99801;
$dest1 = 99802;
$dest2 = 99803;
$dest3 = 99804;
$dest4 = 99805;
$product = 99801;
$encoder = 99801;
$date = dl_businessDate();
$payloadFile = tempnam(sys_get_temp_dir(), 'dl-seal-');
$harness = __DIR__ . '/daily_ledger_dispatch_dr_seal_harness.php';
$previousFormal = dlModuleSettings(true)['formal_delivery_workflow_enabled'] ?? '0';

$runDispatch = static function (array $body, string $role = 'admin') use ($payloadFile, $harness, $encoder): array {
    file_put_contents($payloadFile, json_encode($body, JSON_THROW_ON_ERROR));
    $lines = [];
    $exit = 0;
    exec(
        escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harness) . ' dispatch '
        . escapeshellarg($payloadFile) . ' ' . $encoder . ' ' . escapeshellarg($role) . ' 2>&1',
        $lines,
        $exit
    );
    $raw = implode("\n", $lines);
    $status = null;
    if (preg_match('/\n__HTTP_STATUS__=(\d+)\s*$/', $raw, $m)) {
        $status = (int) $m[1];
        $raw = (string) preg_replace('/\n__HTTP_STATUS__=\d+\s*$/', '', $raw);
    }
    return ['exit' => $exit, 'status' => $status, 'body' => json_decode($raw, true), 'raw' => $raw];
};

$deliveryCount = static function (int $destinationId) use ($db, $comm): int {
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM dl_deliveries
          WHERE origin_type = "commissary" AND origin_id = :cid
            AND destination_id = :did AND status <> "voided"'
    );
    $stmt->execute([':cid' => $comm, ':did' => $destinationId]);
    return (int) $stmt->fetchColumn();
};

$sentQty = static function (int $destinationId) use ($db): int {
    $stmt = $db->prepare(
        'SELECT COALESCE(SUM(di.quantity), 0)
           FROM dl_deliveries d
           INNER JOIN dl_delivery_items di ON di.delivery_id = d.id
          WHERE d.destination_id = :did AND d.status <> "voided"'
    );
    $stmt->execute([':did' => $destinationId]);
    return (int) $stmt->fetchColumn();
};

$dispatchedQty = static function () use ($db, $comm, $product, $date): int {
    $stmt = $db->prepare(
        'SELECT COALESCE(dispatched_qty, 0) FROM dl_commissary_product_ledger
          WHERE commissary_branch_id = :cid AND product_id = :pid AND ledger_date = :d AND shift = "AM"
          LIMIT 1'
    );
    $stmt->execute([':cid' => $comm, ':pid' => $product, ':d' => $date]);
    return (int) ($stmt->fetchColumn() ?: 0);
};

$drRow = static function (string $drNumber) use ($db): array {
    $stmt = $db->prepare(
        'SELECT id, dr_number, provenance_status, provenance_review_note, production_shift,
                created_by_name_snapshot, receipt_required, remarks
           FROM dl_deliveries
          WHERE dr_number = :dr AND status <> "voided"
          ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute([':dr' => $drNumber]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
};

$cleanup = static function () use ($db, $comm, $dest1, $dest2, $dest3, $dest4, $product, $encoder): void {
    $branchIds = [$comm, $dest1, $dest2, $dest3, $dest4];
    $in = implode(',', $branchIds);
    $ids = $db->query(
        "SELECT id FROM dl_deliveries
          WHERE origin_id IN ({$in}) OR destination_id IN ({$in})"
    )->fetchAll(PDO::FETCH_COLUMN) ?: [];
    if ($ids !== []) {
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $db->prepare("DELETE FROM dl_delivery_ledger_effects WHERE delivery_id IN ($marks)")->execute($ids);
        $db->prepare("DELETE FROM dl_delivery_items WHERE delivery_id IN ($marks)")->execute($ids);
        $db->prepare("DELETE FROM dl_deliveries WHERE id IN ($marks)")->execute($ids);
    }
    $db->prepare("DELETE FROM dl_integrity_notification_recipients
                   WHERE notification_id IN (SELECT id FROM dl_integrity_notifications WHERE branch_id IN ({$in}))")->execute();
    $db->prepare("DELETE FROM dl_integrity_notifications WHERE branch_id IN ({$in})")->execute();
    $db->prepare("DELETE FROM audit_logs WHERE module = 'daily-ledger' AND branch_id IN ({$in})")->execute();
    $db->prepare('DELETE FROM dl_commissary_product_ledger WHERE commissary_branch_id = ? OR product_id = ?')
        ->execute([$comm, $product]);
    $db->prepare("DELETE FROM dl_daily_ledger WHERE branch_id IN ({$in}) OR product_id = ?")->execute([$product]);
    $db->prepare("DELETE FROM dl_ledger_shift_status WHERE branch_id IN ({$in})")->execute();
    $db->prepare("DELETE FROM dl_ledger_day_status WHERE branch_id IN ({$in})")->execute();
    $db->prepare("DELETE FROM dl_branch_products WHERE branch_id IN ({$in}) OR product_id = ?")->execute([$product]);
    $db->prepare('DELETE FROM dl_user_branches WHERE user_id = ?')->execute([$encoder]);
    $db->prepare('DELETE FROM dl_users WHERE id = ?')->execute([$encoder]);
    $db->prepare('DELETE FROM dl_products WHERE id = ?')->execute([$product]);
    $db->prepare("DELETE FROM dl_branches WHERE id IN ({$in})")->execute();
};

$cleanup();
dlPersistModuleSettings(['formal_delivery_workflow_enabled' => '1']);
try {
    $db->prepare(
        'INSERT INTO dl_branches (id, code, name, assigned_commissary_id, is_commissary, is_active)
         VALUES (?, ?, ?, NULL, 1, 1), (?, ?, ?, ?, 0, 1), (?, ?, ?, ?, 0, 1), (?, ?, ?, ?, 0, 1), (?, ?, ?, ?, 0, 1)'
    )->execute([
        $comm, 'SEAL-COM-99801', 'Seal Fixture Commissary',
        $dest1, 'SEAL-D1-99801', 'Seal Fixture Branch One', $comm,
        $dest2, 'SEAL-D2-99801', 'Seal Fixture Branch Two', $comm,
        $dest3, 'SEAL-D3-99801', 'Seal Fixture Branch Three', $comm,
        $dest4, 'SEAL-D4-99801', 'Seal Fixture Branch Four', $comm,
    ]);
    $db->prepare(
        'INSERT INTO dl_products (id, sku, name, current_price, is_active) VALUES (?, ?, ?, 12, 1)'
    )->execute([$product, 'SEAL-P-99801', 'Seal Fixture Product']);
    $db->prepare(
        'INSERT INTO dl_branch_products (branch_id, product_id, is_active)
         VALUES (?, ?, 1), (?, ?, 1), (?, ?, 1), (?, ?, 1), (?, ?, 1)'
    )->execute([$comm, $product, $dest1, $product, $dest2, $product, $dest3, $product, $dest4, $product]);
    $db->prepare(
        'INSERT INTO dl_users (id, username, password_hash, full_name, role, shift, is_active)
         VALUES (?, ?, "fixture", "Seal Fixture Encoder", "production_in_charge", "AM", 1)'
    )->execute([$encoder, 'seal-encoder-99801']);
    $db->prepare('INSERT INTO dl_user_branches (user_id, branch_id) VALUES (?, ?)')->execute([$encoder, $comm]);
    // Beginning stock so a real dispatch could pass the balance check. The seal must
    // work even AFTER the sheet spent it, which the later assertions prove.
    $db->prepare(
        'INSERT INTO dl_commissary_product_ledger (commissary_branch_id, product_id, ledger_date, shift, beg_qty)
         VALUES (?, ?, ?, "AM", 100)'
    )->execute([$comm, $product, $date]);

    // ------------------------------------------------------------ sheet first

    $h->section('the sheet already moved the stock');

    $sheet = $runDispatch([
        'sheet_entry' => true,
        'ledger_date' => $date,
        'shift' => 'AM',
        'destination_branch_id' => $dest1,
        'commissary_branch_id' => $comm,
        'items' => [['commissary_branch_id' => $comm, 'product_id' => $product, 'quantity' => 10]],
        'submission_id' => 'seal-sheet-99801',
    ], 'production_in_charge');
    $h->test(
        'the Daily Sheet cell records a real delivery and debits the commissary',
        ($sheet['body']['ok'] ?? false) === true
            && $deliveryCount($dest1) === 1
            && $dispatchedQty() === 10,
        'the baseline the seal depends on is not what the sheet actually does: ' . $sheet['raw']
    );

    // ------------------------------------------------------------ seal, not re-send

    $h->section('the paper DR seals the load instead of sending it again');

    $seal = $runDispatch([
        'ledger_date' => $date,
        'dr_number' => 'SEAL-99801',
        'production_shift' => 'AM',
        'destination_branch_id' => $dest1,
        'items' => [['commissary_branch_id' => $comm, 'product_id' => $product, 'quantity' => 10]],
    ]);
    $sealed = $drRow('SEAL-99801');
    $h->test(
        'the dispatch reports the seal as done',
        ($seal['body']['ok'] ?? false) === true
            && ($seal['body']['adopted'] ?? false) === true
            && ($seal['body']['replayed'] ?? false) === false
            && ($seal['status'] ?? 0) === 200,
        'the normal sheet-first workflow would still be answered with an error: ' . $seal['raw']
    );
    $h->test(
        'no second delivery and no second stock movement',
        $deliveryCount($dest1) === 1 && $dispatchedQty() === 10 && $sentQty($dest1) === 10,
        'the load is counted twice, which is the defect this change exists to remove'
    );
    $h->test(
        'the load now carries the paper DR and is queued for provenance review',
        (string) ($sealed['dr_number'] ?? '') === 'SEAL-99801'
            && (string) ($sealed['provenance_status'] ?? '') === 'paper_dr_pending'
            && trim((string) ($sealed['created_by_name_snapshot'] ?? '')) !== ''
            && (string) ($sealed['production_shift'] ?? '') === 'AM',
        'the document would be recorded but invisible to the review queue'
    );

    // ------------------------------------------------------------ replay is done

    $h->section('a repeated DR is done, not a blocker');

    $replay = $runDispatch([
        'ledger_date' => $date,
        'dr_number' => 'SEAL-99801',
        'production_shift' => 'AM',
        'destination_branch_id' => $dest1,
        'items' => [['commissary_branch_id' => $comm, 'product_id' => $product, 'quantity' => 10]],
    ]);
    $h->test(
        'an identical repeat returns ok/replayed and writes nothing',
        ($replay['body']['ok'] ?? false) === true
            && ($replay['body']['replayed'] ?? false) === true
            && (string) ($replay['body']['difference'] ?? 'x') === ''
            && $deliveryCount($dest1) === 1
            && $dispatchedQty() === 10,
        'the operator is blocked on work that is already recorded'
    );

    $mismatch = $runDispatch([
        'ledger_date' => $date,
        'dr_number' => 'SEAL-99801',
        'production_shift' => 'AM',
        'destination_branch_id' => $dest1,
        'items' => [['commissary_branch_id' => $comm, 'product_id' => $product, 'quantity' => 12]],
    ]);
    $flagged = $drRow('SEAL-99801');
    $h->test(
        'a quantity disagreement is still ok and names both numbers',
        ($mismatch['body']['ok'] ?? false) === true
            && ($mismatch['body']['replayed'] ?? false) === true
            && str_contains((string) ($mismatch['body']['difference'] ?? ''), '10')
            && str_contains((string) ($mismatch['body']['difference'] ?? ''), '12')
            && (int) ($mismatch['status'] ?? 0) === 200,
        'a discrepancy that blocks the operator never reaches an admin'
    );
    $h->test(
        'the disagreement moves no stock and is flagged for an admin',
        $deliveryCount($dest1) === 1
            && $dispatchedQty() === 10
            && (string) ($flagged['provenance_status'] ?? '') === 'discrepant'
            && str_contains((string) ($flagged['provenance_review_note'] ?? ''), '12'),
        'the variance would be lost, or silently applied as stock: ' . json_encode($flagged) . ' / ' . $mismatch['raw']
    );

    // ------------------------------------------------------------ first record wins

    $h->section('no sheet load: the dispatch is the first record');

    $create = $runDispatch([
        'ledger_date' => $date,
        'dr_number' => 'SEAL-2-99801',
        'production_shift' => 'AM',
        'destination_branch_id' => $dest2,
        'items' => [['commissary_branch_id' => $comm, 'product_id' => $product, 'quantity' => 5]],
    ]);
    $created = $drRow('SEAL-2-99801');
    $h->test(
        'a destination with no sheet entry still gets a delivery',
        ($create['body']['ok'] ?? false) === true
            && ($create['body']['adopted'] ?? false) === false
            && $deliveryCount($dest2) === 1
            && (string) ($created['dr_number'] ?? '') === 'SEAL-2-99801'
            && $dispatchedQty() === 15,
        'a DR arriving before any sheet entry would record nothing: ' . $create['raw']
    );

    // ------------------------------------------------------------ adoption with a difference

    $runDispatch([
        'sheet_entry' => true,
        'ledger_date' => $date,
        'shift' => 'AM',
        'destination_branch_id' => $dest3,
        'commissary_branch_id' => $comm,
        'items' => [['commissary_branch_id' => $comm, 'product_id' => $product, 'quantity' => 4]],
        'submission_id' => 'seal-sheet-99803',
    ], 'production_in_charge');
    $adoptDiff = $runDispatch([
        'ledger_date' => $date,
        'dr_number' => 'SEAL-3-99801',
        'production_shift' => 'AM',
        'destination_branch_id' => $dest3,
        'items' => [['commissary_branch_id' => $comm, 'product_id' => $product, 'quantity' => 6]],
    ]);
    $sealedDiff = $drRow('SEAL-3-99801');
    $h->test(
        'a differing seal is done, flags the variance, and moves only what the sheet moved',
        ($adoptDiff['body']['ok'] ?? false) === true
            && ($adoptDiff['body']['adopted'] ?? false) === true
            && str_contains((string) ($adoptDiff['body']['difference'] ?? ''), '6')
            && $deliveryCount($dest3) === 1
            && $dispatchedQty() === 19
            && (string) ($sealedDiff['provenance_status'] ?? '') === 'discrepant',
        'the dispatch claim would be applied as a stock movement behind the sheet'
    );

    // ------------------------------------------------------------ a stale day does not match

    $otherDate = date('Y-m-d', strtotime($date . ' -1 day'));
    $stale = $db->prepare(
        'SELECT COUNT(*) FROM dl_deliveries WHERE dr_number = :dr AND delivery_date <> :d AND status <> "voided"'
    );
    $stale->execute([':dr' => 'SEAL-99801', ':d' => $date]);
    $h->test(
        'the source-scoped duplicate lookup is date-scoped',
        (int) $stale->fetchColumn() === 0
            && str_contains($fnBody($handlers, 'apiCreateCashierDispatch'), 'AND delivery_date = :delivery_date'),
        'the same DR entered on another day would replay an old delivery'
    );

    // ------------------------------------------------------------ cashier lane first

    $h->section('the cashier desk may own the DR');

    $h->test(
        'lane classification reads the row both desks write',
        dl_deliveryLane(['remarks' => '[captured-from-paper-dr]', 'destination_type' => 'branch']) === 'cashier'
            && dl_deliveryLane(['remarks' => '[cashier-dispatch]', 'destination_type' => 'branch']) === 'cashier'
            && dl_deliveryLane(['remarks' => 'anything', 'destination_type' => 'consignee']) === 'cashier'
            && dl_deliveryLane(['remarks' => '[commissary-dispatch]', 'destination_type' => 'branch']) === 'production'
            && dl_deliveryLane(['remarks' => '[daily-sheet-entry] new', 'destination_type' => 'branch']) === 'production'
            && dl_deliveryLane(['remarks' => '[auto-commissary-run]', 'destination_type' => 'branch']) === 'production'
            && dl_deliveryLane(['remarks' => '', 'destination_type' => 'branch']) === 'other',
        'a lane the code cannot read is a lane whose precedence cannot be enforced'
    );

    $notificationCount = static function (int $deliveryId) use ($db): int {
        $stmt = $db->prepare('SELECT COUNT(*) FROM dl_integrity_notifications WHERE aggregate_key = :k');
        $stmt->execute([':k' => 'cashier-dr-first-' . $deliveryId]);
        return (int) $stmt->fetchColumn();
    };

    $insertCashierDr = static function (string $drNumber, int $qty) use ($db, $dest2, $dest4, $date, $product, $encoder): int {
        $db->prepare(
            'INSERT INTO dl_deliveries
                (origin_type, origin_id, destination_type, destination_id, dr_number, delivery_date,
                 production_shift, receipt_required, status, created_by, posted_at, remarks)
             VALUES ("branch", ?, "branch", ?, ?, ?, "AM", 1, "posted", ?, NOW(), "[cashier-dispatch]")'
        )->execute([$dest2, $dest4, $drNumber, $date, $encoder]);
        $id = (int) $db->lastInsertId();
        $db->prepare(
            'INSERT INTO dl_delivery_items (delivery_id, product_id, quantity, unit, price_snapshot)
             VALUES (?, ?, ?, "pcs", 0)'
        )->execute([$id, $product, $qty]);
        return $id;
    };

    $cashierDr = 'SEAL-CASH-99801';
    $cashierDeliveryId = $insertCashierDr($cashierDr, 9);

    $cashierFirst = $runDispatch([
        'ledger_date' => $date,
        'dr_number' => $cashierDr,
        'production_shift' => 'AM',
        'destination_branch_id' => $dest4,
        'items' => [['commissary_branch_id' => $comm, 'product_id' => $product, 'quantity' => 11]],
    ]);
    $cashierRow = $drRow($cashierDr);
    $h->test(
        'production is told the cashier owns the DR and writes nothing',
        ($cashierFirst['body']['ok'] ?? false) === true
            && ($cashierFirst['body']['replayed'] ?? false) === true
            && (string) ($cashierFirst['body']['lane'] ?? '') === 'cashier'
            && str_contains((string) ($cashierFirst['body']['note'] ?? ''), 'cashier desk recorded this DR first')
            && $deliveryCount($dest4) === 0
            && $dispatchedQty() === 19,
        'the production lane would apply a second stock movement over the cashier record: ' . json_encode($cashierFirst['body'])
    );
    $h->test(
        'the disagreement is preserved on the record for the admin',
        (string) ($cashierRow['provenance_status'] ?? '') === 'discrepant'
            && str_contains((string) ($cashierRow['provenance_review_note'] ?? ''), '11')
            && str_contains((string) ($cashierRow['provenance_review_note'] ?? ''), '9'),
        'the numbers would vanish once the operator dismisses the toast: ' . json_encode($cashierRow)
    );
    $h->test(
        'a durable finding names the cashier-first rule',
        $notificationCount($cashierDeliveryId) === 1,
        'no admin surface would say the production dispatch was not applied'
    );

    $repeatCashier = $runDispatch([
        'ledger_date' => $date,
        'dr_number' => $cashierDr,
        'production_shift' => 'AM',
        'destination_branch_id' => $dest4,
        'items' => [['commissary_branch_id' => $comm, 'product_id' => $product, 'quantity' => 11]],
    ]);
    $h->test(
        'a repeated attempt refreshes the finding instead of appending another',
        $notificationCount($cashierDeliveryId) === 1
            && ($repeatCashier['body']['replayed'] ?? false) === true,
        'the admin queue would grow one row per retry'
    );

    // Owner: "production daily sheets data is respected and will be used to flag qty
    // discrepancies". With a sheet load present, the SHEET is the yardstick.
    $runDispatch([
        'sheet_entry' => true,
        'ledger_date' => $date,
        'shift' => 'AM',
        'destination_branch_id' => $dest4,
        'commissary_branch_id' => $comm,
        'items' => [['commissary_branch_id' => $comm, 'product_id' => $product, 'quantity' => 8]],
        'submission_id' => 'seal-sheet-cash-99801',
    ], 'production_in_charge');

    $sheetDr = 'SEAL-CASH-SHEET-99801';
    $sheetCashierId = $insertCashierDr($sheetDr, 6);
    $sheetCompare = $runDispatch([
        'ledger_date' => $date,
        'dr_number' => $sheetDr,
        'production_shift' => 'AM',
        'destination_branch_id' => $dest4,
        'items' => [['commissary_branch_id' => $comm, 'product_id' => $product, 'quantity' => 6]],
    ]);
    $sheetCashierRow = $drRow($sheetDr);
    $h->test(
        'the Daily Sheet is the yardstick, not the re-typed dispatch',
        ($sheetCompare['body']['replayed'] ?? false) === true
            && str_contains((string) ($sheetCompare['body']['difference'] ?? ''), 'Daily Sheet')
            && str_contains((string) ($sheetCompare['body']['difference'] ?? ''), '8'),
        'the sheet figures would be ignored and only the dispatch compared: ' . json_encode($sheetCompare['body'])
    );
    $h->test(
        'the sheet disagreement is preserved on the cashier record',
        (string) ($sheetCashierRow['provenance_status'] ?? '') === 'discrepant'
            && str_contains((string) ($sheetCashierRow['provenance_review_note'] ?? ''), 'Daily Sheet')
            && $notificationCount($sheetCashierId) === 1,
        'the admin would see a flag with no yardstick behind it: ' . json_encode($sheetCashierRow)
    );

    $matchDr = 'SEAL-CASH-MATCH-99801';
    $matchCashierId = $insertCashierDr($matchDr, 8);
    $matchDispatch = $runDispatch([
        'ledger_date' => $date,
        'dr_number' => $matchDr,
        'production_shift' => 'AM',
        'destination_branch_id' => $dest4,
        'items' => [['commissary_branch_id' => $comm, 'product_id' => $product, 'quantity' => 8]],
    ]);
    $h->test(
        'matching figures raise no discrepancy but still record the precedence',
        ($matchDispatch['body']['replayed'] ?? false) === true
            && (string) ($matchDispatch['body']['difference'] ?? 'x') === ''
            && (string) ($drRow($matchDr)['provenance_status'] ?? '') === 'none'
            && $notificationCount($matchCashierId) === 1,
        'a matching load would either be flagged as a disagreement or stay invisible'
    );

    $productionReplay = $runDispatch([
        'ledger_date' => $date,
        'dr_number' => 'SEAL-2-99801',
        'production_shift' => 'AM',
        'destination_branch_id' => $dest2,
        'items' => [['commissary_branch_id' => $comm, 'product_id' => $product, 'quantity' => 5]],
    ]);
    $h->test(
        'a production-owned DR is not reported as a cashier conflict',
        (string) ($productionReplay['body']['lane'] ?? '') === 'production'
            && $notificationCount((int) $drRow('SEAL-2-99801')['id']) === 0,
        'the cashier-first finding would fire on every ordinary repeat'
    );
} finally {
    $cleanup();
    dlPersistModuleSettings(['formal_delivery_workflow_enabled' => $previousFormal]);
    @unlink($payloadFile);
}

// ---------------------------------------------------------------- contract wiring

$h->section('the contract is wired where it can regress');

$dispatchBody = $fnBody($handlers, 'apiCommissaryDispatch');
$cashierBody = $fnBody($handlers, 'apiCreateCashierDispatch');

$h->test(
    'the production dispatch answers a known DR with done, not a refusal',
    str_contains($dispatchBody, "'replayed' => true")
        && !str_contains($dispatchBody, 'already exists for this branch on'),
    'the operator is sent back to a record they cannot change'
);
$h->test(
    'the production dispatch seals an existing sheet load',
    str_contains($dispatchBody, 'dl_findDailySheetLoadForSeal(')
        && str_contains($dispatchBody, 'dl_sealDailySheetLoadWithDr(')
        && str_contains($dispatchBody, "'adopted' => true"),
    'sheet-first would keep double-counting the load'
);
$h->test(
    'the dispatch compares against every row carrying the DR',
    str_contains($dispatchBody, 'dl_deliveryItemQuantitiesAtDr('),
    'a multi-cell load would report its other products as missing'
);
$h->test(
    'the cashier dispatch treats a known DR as done too',
    str_contains($cashierBody, "'replayed' => true")
        && !str_contains($cashierBody, 'This paper DR already exists in the system.'),
    'the same event would still be a blocker on the other screen'
);
$h->test(
    'the seal helpers exist and are shared',
    function_exists('dl_findDailySheetLoadForSeal')
        && function_exists('dl_sealDailySheetLoadWithDr')
        && function_exists('dl_deliveryItemQuantitiesAtDr')
        && function_exists('dl_isDailySheetSealedDelivery'),
    'a caller would hit an undefined function'
);
$h->test(
    'a sealed sheet load is reviewable like a paper capture',
    str_contains($fnBody($deliveriesSrc, 'dl_isProvenanceReviewEligible'), 'dl_isDailySheetSealedDelivery')
        && str_contains($deliveriesSrc, ':sheet_sealed_prefix'),
    'the DR is recorded but no screen ever shows it for review'
);
$h->test(
    'the operator is told what happened',
    str_contains($commissaryTpl, 'sealed onto the load already on the sheet')
        && str_contains($commissaryTpl, 'flagged for an admin to resolve')
        && str_contains($dispatchTpl, 'Already recorded'),
    'the same success toast would describe two different outcomes'
);

$remaining = [
    'branches' => (int) $db->query("SELECT COUNT(*) FROM dl_branches WHERE id IN ({$comm},{$dest1},{$dest2},{$dest3})")->fetchColumn(),
    'products' => (int) $db->query("SELECT COUNT(*) FROM dl_products WHERE id = {$product}")->fetchColumn(),
    'users' => (int) $db->query("SELECT COUNT(*) FROM dl_users WHERE id = {$encoder}")->fetchColumn(),
    'deliveries' => (int) $db->query("SELECT COUNT(*) FROM dl_deliveries WHERE origin_id = {$comm} OR destination_id IN ({$dest1},{$dest2},{$dest3})")->fetchColumn(),
    'ledger' => (int) $db->query("SELECT COUNT(*) FROM dl_commissary_product_ledger WHERE commissary_branch_id = {$comm}")->fetchColumn(),
];
$h->test(
    'fixture cleanup pin: every private-id row is removed and settings restored',
    array_sum($remaining) === 0
        && (string) (dlModuleSettings(true)['formal_delivery_workflow_enabled'] ?? '') === (string) $previousFormal,
    json_encode($remaining)
);

$h->done();
