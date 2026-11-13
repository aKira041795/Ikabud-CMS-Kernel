<?php

declare(strict_types=1);

/**
 * Daily Ledger — one explanation PER SHORT LINE in the Receive Stock modal.
 *
 * Owner direction, 2026-11-13:
 *   "when an item has a variance upon receiving (cashier set the actual qty received), show the text
 *    field immediately below the item"
 *   "so, multiple variances found, each item with the text field showing"
 *
 * Before this, a DR with three short lines carried ONE box at the bottom of the delivery asking
 * "What is the issue with this delivery?", so the cashier described line 2 thirty rows below line 2
 * and the office could not tell which product the words were about. The field now belongs to the
 * line, and the payload carries {delivery_item_id: note}.
 *
 * WHAT THIS PINS:
 *  1. Each short line's explanation lands on ITS OWN product's variance evidence.
 *  2. A line that matched the DR carries no explanation (nothing was invented for it).
 *  3. Two short lines of the SAME product collapse into the one flag that product has, and their
 *     words are BOTH kept - joined, never one silently winning.
 *  4. An over-long explanation is clamped, not rejected: losing a receipt because a reason ran long
 *     would punish the cashier for using the feature.
 *  5. The modal renders a field per item (`displayRows`/`hasItemVariance`/`row.type === 'note'`) and
 *     sends `delivery_issue_notes`, so a later redesign cannot quietly return to one box per delivery.
 */

ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-receive-item-notes', TestHarness::MODE_INTEGRATION, 'baronledger.test');
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

$branchId = 99872;
$p = ['dup' => 99881, 'match' => 99882, 'solo' => 99883, 'long' => 99884];
$date = dl_businessDate();
$receivingIds = [];

$cleanup = static function () use ($db, $branchId, $p): void {
    $ids = array_values($p);
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $db->prepare("DELETE FROM dl_integrity_notification_recipients WHERE notification_id IN (SELECT id FROM dl_integrity_notifications WHERE branch_id = ?)")
        ->execute([$branchId]);
    $db->prepare('DELETE FROM dl_integrity_notifications WHERE branch_id = ?')->execute([$branchId]);
    $db->prepare("DELETE FROM audit_logs WHERE module = 'daily-ledger' AND branch_id = ?")->execute([$branchId]);
    $db->prepare('DELETE FROM dl_variance_flags WHERE branch_id = ? OR product_id IN (' . $marks . ')')
        ->execute(array_merge([$branchId], $ids));
    $db->prepare('DELETE FROM dl_delivery_variance_flags WHERE product_id IN (' . $marks . ')')->execute($ids);
    $db->prepare('DELETE FROM dl_branch_receiving_items WHERE product_id IN (' . $marks . ')')->execute($ids);
    $db->prepare('DELETE FROM dl_branch_receivings WHERE branch_id = ?')->execute([$branchId]);
    $db->prepare('DELETE FROM dl_delivery_items WHERE product_id IN (' . $marks . ')')->execute($ids);
    $db->prepare('DELETE FROM dl_deliveries WHERE destination_type = "branch" AND destination_id = ?')->execute([$branchId]);
    $db->prepare('DELETE FROM dl_daily_ledger WHERE branch_id = ? OR product_id IN (' . $marks . ')')
        ->execute(array_merge([$branchId], $ids));
    $db->prepare('DELETE FROM dl_branch_products WHERE branch_id = ? OR product_id IN (' . $marks . ')')
        ->execute(array_merge([$branchId], $ids));
    $db->prepare('DELETE FROM dl_products WHERE id IN (' . $marks . ')')->execute($ids);
    $db->prepare('DELETE FROM dl_branches WHERE id = ?')->execute([$branchId]);
};

$cleanup();
try {
    $db->prepare(
        'INSERT INTO dl_branches (id, code, name, is_active, is_commissary, paperless_dr_enabled, single_shift_mode, has_production)
         VALUES (?, ?, ?, 1, 0, 1, 0, 0)'
    )->execute([$branchId, 'NOTE-99872', 'Receive Note Fixture']);

    $insertProduct = $db->prepare(
        'INSERT INTO dl_products (id, sku, name, current_price, product_category, is_active)
         VALUES (?, ?, ?, 1, ?, 1)'
    );
    foreach ($p as $key => $id) {
        $insertProduct->execute([$id, 'NOTE-' . strtoupper($key) . '-' . $id, 'Note ' . ucfirst($key) . ' Fixture', 'bread']);
        $db->prepare('INSERT INTO dl_branch_products (branch_id, product_id, is_active) VALUES (?, ?, 1)')
            ->execute([$branchId, $id]);
    }

    // One DR carrying five lines: the same product twice (so the join rule is exercised), a line that
    // matches, a plain short line, and a short line whose reason runs past the clamp.
    $db->prepare(
        'INSERT INTO dl_deliveries (origin_type, origin_id, destination_type, destination_id, dr_number, delivery_date, status, posted_at)
         VALUES ("commissary", NULL, "branch", ?, "DR-NOTE-99872", ?, "posted", NOW())'
    )->execute([$branchId, $date]);
    $deliveryId = (int)$db->lastInsertId();

    $insertItem = $db->prepare(
        'INSERT INTO dl_delivery_items (delivery_id, product_id, quantity, unit, price_snapshot)
         VALUES (?, ?, ?, "pcs", 1)'
    );
    $lines = [];
    foreach ([['dup', 5], ['dup', 2], ['match', 4], ['solo', 3], ['long', 3]] as $spec) {
        $insertItem->execute([$deliveryId, $p[$spec[0]], $spec[1]]);
        $lines[] = ['id' => (int)$db->lastInsertId(), 'key' => $spec[0], 'sent' => $spec[1]];
    }

    $counts = [];
    $notes = [];
    $counted = ['dup' => [3, 1], 'match' => [4], 'solo' => [1], 'long' => [2]];
    $seen = ['dup' => 0];
    $longNote = str_repeat('x', DL_VARIANCE_NOTE_MAX + 120);
    foreach ($lines as $line) {
        $idx = $seen[$line['key']] ?? 0;
        $counts[$line['id']] = $counted[$line['key']][$idx];
        $seen[$line['key']] = $idx + 1;
        if ($line['key'] === 'dup') {
            $notes[$line['id']] = $idx === 0 ? 'unang linya kulang' : 'ikaduhang linya kulang';
        } elseif ($line['key'] === 'solo') {
            $notes[$line['id']] = 'wala naabot ang truck';
        } elseif ($line['key'] === 'long') {
            $notes[$line['id']] = $longNote;
        }
        // 'match' deliberately carries NO note: its count equals the DR.
    }

    $receivingId = dl_acceptFormalDeliveryByItem($db, $branchId, $deliveryId, 0, $date, $counts, 'AM', '', $notes);
    $receivingIds[] = $receivingId;

    $h->test('the receive posted', $receivingId > 0, 'receiving id ' . $receivingId);

    $flagStmt = $db->prepare(
        "SELECT product_id, sent_qty, received_qty, variance, receiver_remark
           FROM dl_variance_flags
          WHERE receiving_id = :rcv AND kind = 'delivery'
          ORDER BY product_id"
    );
    $flagStmt->execute([':rcv' => $receivingId]);
    $flags = [];
    foreach ($flagStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $flags[(int)$row['product_id']] = $row;
    }

    $h->section('each short line explains itself');

    $h->test(
        'a short line stores its OWN explanation on its own product',
        (string)($flags[$p['solo']]['receiver_remark'] ?? '') === 'wala naabot ang truck',
        json_encode($flags[$p['solo']] ?? null)
    );
    $h->test(
        'the line that matched the DR carries no explanation at all',
        !isset($flags[$p['match']]),
        'a matched line must not sprout a variance: ' . json_encode($flags[$p['match']] ?? null)
    );
    $h->test(
        'two short lines of the same product keep BOTH explanations',
        (string)($flags[$p['dup']]['receiver_remark'] ?? '') === 'unang linya kulang / ikaduhang linya kulang',
        json_encode($flags[$p['dup']] ?? null)
    );
    $h->test(
        'an over-long explanation is clamped, never rejected',
        strlen((string)($flags[$p['long']]['receiver_remark'] ?? '')) === DL_VARIANCE_NOTE_MAX,
        'stored length ' . strlen((string)($flags[$p['long']]['receiver_remark'] ?? ''))
    );

    $h->section('the modal owns one field per short item');
    $receiveTemplate = (string)file_get_contents($base . '/templates/modules/daily-ledger/cashier/receive_modal.disyl');
    $h->test(
        'the modal builds one render row per item and one note row per variance',
        str_contains($receiveTemplate, 'get displayRows()')
            && str_contains($receiveTemplate, 'hasItemVariance(g, it)')
            && str_contains($receiveTemplate, "row.type === 'note'"),
        'the per-item field was replaced by a delivery-level box again'
    );
    $h->test(
        'the modal labels the field with the item it belongs to',
        str_contains($receiveTemplate, 'What is the issue with <span x-text="row.it.product_name"></span>?')
            && !str_contains($receiveTemplate, 'What is the issue with this delivery?'),
        'the label no longer names the line, so several shortages read as one'
    );
    $h->test(
        'the modal sends notes keyed by the item they were typed under',
        str_contains($receiveTemplate, 'payload.delivery_issue_notes = itemNotes')
            && str_contains($receiveTemplate, 'notesSelf.issueNote(g, it)')
            && !str_contains($receiveTemplate, 'issueNotes[g.group_key]'),
        'the payload must stay per item, not per delivery'
    );
    $handlersSource = (string)file_get_contents($base . '/modules/daily-ledger/handlers.php');
    $h->test(
        'the API accepts per-item notes and applies them per product',
        str_contains($handlersSource, "input['delivery_issue_notes']")
            && str_contains($handlersSource, 'array $remarksByItem = []')
            && str_contains($handlersSource, 'AND product_id = :pid'),
        'the server must keep the note on the product the line belongs to'
    );
} finally {
    $cleanup();
}

$h->done();
