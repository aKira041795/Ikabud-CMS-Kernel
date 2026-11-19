<?php

declare(strict_types=1);

/**
 * Daily Ledger — a sender may correct a dispatch it already sent, until the branch receives it.
 *
 * OWNER DIRECTION (2026-11-19):
 *   "if accidentally dispatched, production user can edit the dispatched DR and add/correct.
 *    - not editable if already received by cashier at branch
 *    - in this case, cashier can add missing products based from paper DR, admin as per principle,
 *      always can
 *    - add an 'edit dispatch' beside the 'new delivery' button"
 *
 * WHY THIS EXISTS: the only corrections available before it were a VOID (admin or supervisor, and it
 * reverses both ledgers) and the cashier-side edit-by-DR, which looks a delivery up by DESTINATION.
 * A production in-charge who dispatched the wrong quantity had no path at all, so the load stood
 * wrong in the branch's incoming list until the office noticed.
 *
 * Each assertion names what breaks if it regresses:
 * - correcting by DR alone hits the WRONG ROW when two senders record the same DR at one
 *   destination (measured 2026-10-22, DR 434) - so the sender passes the delivery id and the engine
 *   verifies it instead of searching;
 * - correcting a RECEIVED dispatch rewrites the branch's own evidence of what arrived;
 * - letting a PIC correct another commissary's dispatch crosses the origin boundary;
 * - an on-hand-only quantity cap refuses the very quantity printed on the paper DR, because the
 *   dispatched stock has already left;
 * - a correction that silently drops an absent line would erase it (the engine zeroes what the
 *   payload omits);
 * - a receiver-side write from the sender's path contradicts the count the cashier actually made.
 */

ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-dispatch-edit', TestHarness::MODE_INTEGRATION, 'baronledger.test');
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
$h->fingerprint('modules/daily-ledger/handlers-deliveries.php');
$h->fingerprint('modules/daily-ledger/helpers.php');
$h->fingerprint('modules/daily-ledger/routes.php');
$h->fingerprint('templates/modules/daily-ledger/admin/commissary.disyl');
$h->fingerprint('tests/daily-ledger/daily_ledger_dispatch_edit_harness.php');

app()->tenant()->setTenantId(207);
$ctx = modulePushContext('daily-ledger');
$db = $ctx->db();

$routes = (string) file_get_contents($base . '/modules/daily-ledger/routes.php');
$handlersSrc = (string) file_get_contents($base . '/modules/daily-ledger/handlers.php');
$helpersSrc = (string) file_get_contents($base . '/modules/daily-ledger/helpers.php');
$deliveriesSrc = (string) file_get_contents($base . '/modules/daily-ledger/handlers-deliveries.php');
$commissaryTpl = (string) file_get_contents($base . '/templates/modules/daily-ledger/admin/commissary.disyl');

// ---------------------------------------------------------------- wiring

$h->section('the correction surface exists and the PIC can reach it');

$h->test(
    'the route is registered',
    str_contains($routes, "/daily-ledger/api/v1/commissary/dispatch-edit'")
        && str_contains($routes, 'daily-ledger:apiEditCommissaryDispatch'),
    'the endpoint would 404 and the button would look broken'
);

$h->test(
    'the handler exists',
    function_exists('apiEditCommissaryDispatch'),
    'a route naming a missing handler answers 500'
);

$h->test(
    'the production in-charge route allowlist admits the correction POST',
    // The narrowing is an authorization boundary, not a convenience: an unlisted path is refused
    // here even when the handler itself lists the role.
    (bool) preg_match(
        "/\\\$path === '\\/daily-ledger\\/api\\/v1\\/commissary\\/dispatch-edit'/",
        $handlersSrc
    ),
    'the PIC would get 403 on the only surface built for them'
);

$h->test(
    'the PIC may also read the deliveries list the picker is built from',
    str_contains($helpersSrc, "'/daily-ledger/api/v1/deliveries',"),
    'the Edit Dispatch panel would open empty with a 403 behind it'
);

$h->test(
    'the Edit Dispatch button sits beside New Delivery',
    str_contains($commissaryTpl, 'id="edit-dispatch-btn"')
        && str_contains($commissaryTpl, 'openDispatchPicker()')
        && strpos($commissaryTpl, 'openDispatchEntry()') < strpos($commissaryTpl, 'openDispatchPicker()'),
    'the owner asked for it beside that button specifically - a mid-page control is a different feature'
);

$h->test(
    'the correction never re-decides boxes',
    str_contains($commissaryTpl, 'var boxSlots = isEdit ? [] :'),
    'every save would add a second box line to the same delivery'
);

// ── A received dispatch is visible to the sender, but not editable ──────────────────────────
// Owner direction 2026-11-19: "PIC dispatch modal allows user to see what was added by receiving
// branch but cannot edit". The settled state was a DISABLED Edit button, which hid the one thing
// production has to see - what the branch received and what the cashier added from the paper DR.
$h->test(
    'a received dispatch opens read-only instead of refusing to open',
    str_contains($commissaryTpl, "button.textContent = readOnly ? 'View' : 'Edit';")
        && str_contains($commissaryTpl, 'openDispatchEditor(row, readOnly)'),
    'the sender would be locked out of the evidence of what the branch received'
);

$h->test(
    'the received view offers no save button and no reason field',
    str_contains($commissaryTpl, "submitBtn.style.display = isReadOnly ? 'none' : '';")
        && str_contains($commissaryTpl, "reasonGroup.style.display = isReadOnly ? 'none' : '';"),
    'a visible-but-inert Save invites the operator to hunt for what would enable it'
);

$h->test(
    'the received view names the lines the branch added after dispatch',
    str_contains($commissaryTpl, 'id="dispatch-edit-branch-additions"')
        && str_contains($commissaryTpl, 'item.added_after_dispatch'),
    'production could see the quantities but not that the cashier had added a missed item'
);

$h->test(
    'the delivery list marks a line inserted by a later correction',
    // dl_correctDeliveryByDr() stamps inserted rows with this remark; the flag is derived from it
    // rather than from a second source of truth that could drift.
    str_contains($deliveriesSrc, "'added_after_dispatch' =>")
        && str_contains($deliveriesSrc, "=== 'edited-by-dr'"),
    'the read-only view would have nothing to label, and a wrong marker would mislabel an '
        . 'ordinary line as one the branch added'
);

// Owner rule: "admin, as per principle, always can". Admin therefore KEEPS Edit on a received
// dispatch - but an admin silently moving a branch's counted stock is the failure this guards.
// Measured 2026-11-19: the first read-only build shipped the admin path with no warning at all,
// and the owner met it as "received dispatches are still editable?".
$h->test(
    'admin keeps Edit on a received dispatch and everyone else gets View',
    str_contains($commissaryTpl, "var readOnly = received && DL_ROLE !== 'admin';"),
    'either the admin exception or the read-only rule for the other roles would be lost'
);

$h->test(
    'an admin correcting a received dispatch is told it moves the branch count',
    str_contains($commissaryTpl, "Already received - saving also moves the branch")
        && str_contains($commissaryTpl, 'Save this correction to an already-received dispatch?')
        && str_contains($commissaryTpl, 'Yes, save and move the branch count')
        && str_contains($commissaryTpl, 'received: !!delivery.has_receiving,'),
    'the office could restate a branch\'s received stock without being told that is what the '
        . 'save does - and the allow-all can be silently lost while still "allowed"'
);

// ---------------------------------------------------------------- fixture

$comm = 99701;        // the sender
$commOther = 99702;   // a commissary that is NOT this user's
$dest = 99703;
$product = 99701;
$pic = 99701;
$admin = 99702;
$date = dl_businessDate();
$payloadFile = tempnam(sys_get_temp_dir(), 'dl-dispatch-edit-');
$harness = __DIR__ . '/daily_ledger_dispatch_edit_harness.php';

$runEdit = static function (array $body, string $role, int $actorId) use ($payloadFile, $harness): array {
    file_put_contents($payloadFile, json_encode($body, JSON_THROW_ON_ERROR));
    $lines = [];
    $exit = 0;
    exec(
        escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harness) . ' '
        . escapeshellarg($payloadFile) . ' ' . $actorId . ' ' . escapeshellarg($role) . ' 2>&1',
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

$cleanup = static function () use ($db, $comm, $commOther, $dest, $product, $pic, $admin): void {
    $branchIds = [$comm, $commOther, $dest];
    $in = implode(',', $branchIds);
    $ids = $db->query(
        "SELECT id FROM dl_deliveries WHERE origin_id IN ({$in}) OR destination_id IN ({$in})"
    )->fetchAll(PDO::FETCH_COLUMN) ?: [];
    if ($ids !== []) {
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $db->prepare("DELETE FROM dl_branch_receiving_items WHERE receiving_id IN (SELECT id FROM dl_branch_receivings WHERE delivery_id IN ($marks))")->execute($ids);
        $db->prepare("DELETE FROM dl_branch_receivings WHERE delivery_id IN ($marks)")->execute($ids);
        $db->prepare("DELETE FROM dl_delivery_ledger_effects WHERE delivery_id IN ($marks)")->execute($ids);
        $db->prepare("DELETE FROM dl_delivery_items WHERE delivery_id IN ($marks)")->execute($ids);
        $db->prepare("DELETE FROM dl_deliveries WHERE id IN ($marks)")->execute($ids);
    }
    $db->prepare("DELETE FROM dl_integrity_notification_recipients WHERE notification_id IN (SELECT id FROM dl_integrity_notifications WHERE branch_id IN ({$in}))")->execute();
    $db->prepare("DELETE FROM dl_integrity_notifications WHERE branch_id IN ({$in})")->execute();
    $db->prepare("DELETE FROM audit_logs WHERE module = 'daily-ledger' AND branch_id IN ({$in})")->execute();
    $db->prepare('DELETE FROM dl_commissary_product_ledger WHERE commissary_branch_id IN (?, ?) OR product_id = ?')
        ->execute([$comm, $commOther, $product]);
    $db->prepare("DELETE FROM dl_daily_ledger WHERE branch_id IN ({$in}) OR product_id = ?")->execute([$product]);
    $db->prepare("DELETE FROM dl_variance_flags WHERE branch_id IN ({$in})")->execute();
    $db->prepare("DELETE FROM dl_ledger_shift_status WHERE branch_id IN ({$in})")->execute();
    $db->prepare("DELETE FROM dl_ledger_day_status WHERE branch_id IN ({$in})")->execute();
    $db->prepare("DELETE FROM dl_branch_products WHERE branch_id IN ({$in}) OR product_id = ?")->execute([$product]);
    $db->prepare('DELETE FROM dl_user_branches WHERE user_id IN (?, ?)')->execute([$pic, $admin]);
    $db->prepare('DELETE FROM dl_users WHERE id IN (?, ?)')->execute([$pic, $admin]);
    $db->prepare('DELETE FROM dl_products WHERE id = ?')->execute([$product]);
    $db->prepare("DELETE FROM dl_branches WHERE id IN ({$in})")->execute();
};

$cleanup();

$createDelivery = static function (int $originId, string $dr, int $qty) use ($db, $dest, $product, $date): int {
    $db->prepare(
        'INSERT INTO dl_deliveries (origin_type, origin_id, destination_type, destination_id,
                                    dr_number, delivery_date, production_shift, status, created_by,
                                    created_by_name_snapshot, posted_at, remarks)
         VALUES ("commissary", ?, "branch", ?, ?, ?, "AM", "posted", NULL, "Edit Fixture", NOW(), ?)'
    )->execute([$originId, $dest, $dr, $date, dl_paperDrCaptureRemark()]);
    $deliveryId = (int) $db->lastInsertId();
    $db->prepare(
        'INSERT INTO dl_delivery_items (delivery_id, product_id, quantity, unit) VALUES (?, ?, ?, "pcs")'
    )->execute([$deliveryId, $product, $qty]);

    // A real dispatch always leaves a commissary ledger row behind: the output it spent and the
    // dispatched figure it recorded. The correction engine reverses against THAT row, so a fixture
    // without it asks the engine to reverse stock that was never produced (and it refuses).
    $find = $db->prepare(
        'SELECT id FROM dl_commissary_product_ledger
          WHERE commissary_branch_id = ? AND product_id = ? AND ledger_date = ? AND shift = "AM" LIMIT 1'
    );
    $find->execute([$originId, $product, $date]);
    $ledgerId = (int) ($find->fetchColumn() ?: 0);
    if ($ledgerId > 0) {
        $db->prepare(
            'UPDATE dl_commissary_product_ledger
                SET produced_qty = produced_qty + ?, dispatched_qty = dispatched_qty + ?
              WHERE id = ?'
        )->execute([$qty + 20, $qty, $ledgerId]);
    } else {
        $db->prepare(
            'INSERT INTO dl_commissary_product_ledger
                (commissary_branch_id, product_id, ledger_date, shift, beg_qty, produced_qty, dispatched_qty)
             VALUES (?, ?, ?, "AM", 0, ?, ?)'
        )->execute([$originId, $product, $date, $qty + 20, $qty]);
    }

    return $deliveryId;
};

$itemQty = static function (int $deliveryId) use ($db, $product): int {
    $stmt = $db->prepare('SELECT COALESCE(SUM(quantity), 0) FROM dl_delivery_items WHERE delivery_id = ? AND product_id = ?');
    $stmt->execute([$deliveryId, $product]);
    return (int) $stmt->fetchColumn();
};

$dispatchedQty = static function (int $commissaryId) use ($db, $product, $date): int {
    $stmt = $db->prepare(
        'SELECT COALESCE(SUM(dispatched_qty), 0) FROM dl_commissary_product_ledger
          WHERE commissary_branch_id = :c AND product_id = :p AND ledger_date = :d'
    );
    $stmt->execute([':c' => $commissaryId, ':p' => $product, ':d' => $date]);
    return (int) $stmt->fetchColumn();
};

$receive = static function (int $deliveryId) use ($db, $dest, $comm, $date, $pic): int {
    $db->prepare(
        'INSERT INTO dl_branch_receivings
            (branch_id, origin_type, origin_id, delivery_id, dr_number, received_by, received_at,
             received_ledger_date, received_shift, status, posted_at, count_basis)
         VALUES (?, "commissary", ?, ?, "EDIT-RCV", ?, NOW(), ?, "AM", "posted", NOW(), "independently_counted")'
    )->execute([$dest, $comm, $deliveryId, $pic, $date]);
    return (int) $db->lastInsertId();
};

$db->prepare(
    'INSERT INTO dl_branches (id, code, name, assigned_commissary_id, is_commissary, is_active)
     VALUES (?, ?, ?, NULL, 1, 1), (?, ?, ?, NULL, 1, 1), (?, ?, ?, ?, 0, 1)'
)->execute([
    $comm, 'EDIT-COM-99701', 'Edit Fixture Commissary',
    $commOther, 'EDIT-COM-99702', 'Edit Fixture Other Commissary',
    $dest, 'EDIT-D1-99703', 'Edit Fixture Branch', $comm,
]);
$db->prepare('INSERT INTO dl_products (id, sku, name, current_price, is_active) VALUES (?, ?, ?, 12, 1)')
    ->execute([$product, 'EDIT-P-99701', 'Edit Fixture Cake']);
$db->prepare(
    'INSERT INTO dl_branch_products (branch_id, product_id, is_active) VALUES (?, ?, 1), (?, ?, 1), (?, ?, 1)'
)->execute([$comm, $product, $commOther, $product, $dest, $product]);
$db->prepare(
    'INSERT INTO dl_users (id, username, password_hash, full_name, role, shift, is_active)
     VALUES (?, ?, "fixture", "Edit Fixture PIC", "production_in_charge", "AM", 1),
            (?, ?, "fixture", "Edit Fixture Admin", "admin", "AM", 1)'
)->execute([$pic, 'edit-pic-99701', $admin, 'edit-admin-99702']);
$db->prepare('INSERT INTO dl_user_branches (user_id, branch_id) VALUES (?, ?)')->execute([$pic, $comm]);

try {
    // ------------------------------------------------------------ sender corrects before receipt

    $h->section('the sender corrects its own unreceived dispatch');

    $deliveryA = $createDelivery($comm, 'EDIT-99801', 10);
    $edit = $runEdit([
        'delivery_id' => $deliveryA,
        'reason' => 'wrong quantity typed at dispatch',
        'items' => [['product_id' => $product, 'quantity' => 7]],
    ], 'production_in_charge', $pic);

    $h->test(
        'the production in-charge may correct its own dispatch (not 403, not 409)',
        ($edit['status'] ?? 0) === 200 && ($edit['body']['ok'] ?? false) === true,
        'the whole feature would be unreachable for the role it was requested for: ' . $edit['raw']
    );
    $h->test(
        'the delivered quantity is the corrected one',
        $itemQty($deliveryA) === 7,
        'the dispatch would still stand at the wrong quantity'
    );
    $h->test(
        'the commissary dispatched figure follows the correction',
        $dispatchedQty($comm) === 7,
        'the sheet would report 10 dispatched while the branch is sent 7'
    );
    $h->test(
        'the correction is attributed and explained in the audit trail',
        (static function () use ($db, $deliveryA): bool {
            $stmt = $db->prepare(
                "SELECT new_data FROM audit_logs
                  WHERE module = 'daily-ledger' AND action = 'edit_delivery_by_dr'
                    AND entity_type = 'dl_deliveries' AND entity_id = ?
                  ORDER BY id DESC LIMIT 1"
            );
            $stmt->execute([(string)$deliveryA]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return is_array($row) && str_contains((string)($row['new_data'] ?? ''), 'production_in_charge');
        })(),
        'nobody could say who changed a dispatched load or why'
    );

    // ------------------------------------------------------------ one DR, two senders

    $h->section('the exact delivery is corrected, not the newest row with that DR');

    $deliveryOther = $createDelivery($commOther, 'EDIT-99801', 4);
    $editFirst = $runEdit([
        'delivery_id' => $deliveryA,
        'reason' => 'still the sender correcting its own row',
        'items' => [['product_id' => $product, 'quantity' => 6]],
    ], 'production_in_charge', $pic);
    $h->test(
        'the sender corrects its own row while another sender holds the same DR',
        ($editFirst['status'] ?? 0) === 200,
        'the correction was refused by a DR collision the operator cannot control: ' . $editFirst['raw']
    );
    $h->test(
        'the other sender\'s delivery is untouched',
        $itemQty($deliveryA) === 6 && $itemQty($deliveryOther) === 4,
        'a correction aimed at one delivery silently rewrote a different sender\'s dispatch'
    );

    // ------------------------------------------------------------ wrong commissary

    $h->section('a sender cannot reach another commissary\'s dispatch');

    $editForeign = $runEdit([
        'delivery_id' => $deliveryOther,
        'reason' => 'not mine',
        'items' => [['product_id' => $product, 'quantity' => 1]],
    ], 'production_in_charge', $pic);
    $h->test(
        'another commissary\'s dispatch is refused',
        ($editForeign['status'] ?? 0) === 403,
        'the origin boundary is open: any production in-charge could rewrite any commissary\'s load: ' . $editForeign['raw']
    );
    $h->test(
        'the refused correction changed nothing',
        $itemQty($deliveryOther) === 4,
        'a refused request still wrote to the database'
    );

    // ------------------------------------------------------------ received: the hard line

    $h->section('a received dispatch is the branch\'s evidence, not the sender\'s draft');

    $receivingId = $receive($deliveryA);
    $editReceived = $runEdit([
        'delivery_id' => $deliveryA,
        'reason' => 'trying to correct after receipt',
        'items' => [['product_id' => $product, 'quantity' => 2]],
    ], 'production_in_charge', $pic);
    $h->test(
        'the sender is refused once the branch has received the DR',
        ($editReceived['status'] ?? 0) === 409
            && (string) ($editReceived['body']['code'] ?? '') === 'ALREADY_RECEIVED',
        'the sender could rewrite a receipt the cashier already counted: ' . $editReceived['raw']
    );
    $h->test(
        'the received quantity is left exactly as the branch counted it',
        $itemQty($deliveryA) === 6,
        'a refused correction still changed the delivered figure'
    );

    $editAdmin = $runEdit([
        'delivery_id' => $deliveryA,
        'reason' => 'office correction after receipt',
        'items' => [['product_id' => $product, 'quantity' => 5]],
    ], 'admin', $admin);
    $h->test(
        'an admin may still correct it ("admin, as per principle, always can")',
        ($editAdmin['status'] ?? 0) === 200 && $itemQty($deliveryA) === 5,
        'the owner\'s stated admin exception does not work: ' . $editAdmin['raw']
    );
    $h->test(
        'the admin correction moves the receiving side instead of only the sent side',
        (static function () use ($db, $receivingId, $product): bool {
            $stmt = $db->prepare('SELECT COALESCE(SUM(quantity_received), 0) FROM dl_branch_receiving_items WHERE receiving_id = ? AND product_id = ?');
            $stmt->execute([$receivingId, $product]);
            return (int) $stmt->fetchColumn() === 5;
        })(),
        'the two branches would disagree after an office correction'
    );

    // ------------------------------------------------------------ payload completeness

    $h->section('a line the sheet no longer shows is still corrected, never dropped');

    $deliveryB = $createDelivery($comm, 'EDIT-99802', 9);
    $editZero = $runEdit([
        'delivery_id' => $deliveryB,
        'reason' => 'line was never loaded',
        'items' => [['product_id' => $product, 'quantity' => 0]],
    ], 'production_in_charge', $pic);
    $h->test(
        'an explicit zero zeroes the line and says so',
        ($editZero['status'] ?? 0) === 200
            && ($editZero['body']['zeroed'] ?? false) === true
            && $itemQty($deliveryB) === 0,
        'the operator would think the dispatch vanished when it still stands on record: ' . $editZero['raw']
    );
    $h->test(
        'the emptied line is kept, not deleted, so its audit link survives',
        (static function () use ($db, $deliveryB, $product): bool {
            $stmt = $db->prepare('SELECT COUNT(*) FROM dl_delivery_items WHERE delivery_id = ? AND product_id = ?');
            $stmt->execute([$deliveryB, $product]);
            return (int) $stmt->fetchColumn() === 1;
        })(),
        'a deleted item drops the receiving link (ON DELETE SET NULL) and rewrites evidence'
    );

    $h->section('the correction is refused without a reason');

    $editNoReason = $runEdit([
        'delivery_id' => $deliveryB,
        'reason' => '',
        'items' => [['product_id' => $product, 'quantity' => 3]],
    ], 'production_in_charge', $pic);
    $h->test(
        'a missing reason is refused before anything is written',
        ($editNoReason['status'] ?? 0) === 422 && $itemQty($deliveryB) === 0,
        'corrections could be made with no explanation on record: ' . $editNoReason['raw']
    );
} finally {
    $cleanup();
    if (is_file($payloadFile)) {
        unlink($payloadFile);
    }
}

$h->done();
