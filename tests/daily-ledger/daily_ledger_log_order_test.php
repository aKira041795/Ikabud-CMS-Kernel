<?php

declare(strict_types=1);

/**
 * Daily Ledger — evidence-log chronological and equal-second ordering.
 *
 * Tenant 207. Fixture ids are isolated and every row is removed in finally.
 * Huge document ids recreate the cross-table id-scale mismatch without
 * depending on the tenant's current AUTO_INCREMENT values. Rows are inserted
 * normally and their primary keys are then moved, so fixture cleanup does not
 * leave either AUTO_INCREMENT counter at an artificial two-billion value.
 */

ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-log-order', TestHarness::MODE_INTEGRATION, 'baronledger.test');
ob_end_clean();

$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/helpers.php';
require_once $base . '/modules/daily-ledger/handlers-deliveries.php';
require_once $base . '/modules/daily-ledger/handlers.php';

$h->fingerprint('modules/daily-ledger/handlers.php');

app()->tenant()->setTenantId(207);
$ctx = modulePushContext('daily-ledger');
if (!$ctx) {
    fwrite(STDERR, "daily-ledger module context unavailable\n");
    exit(1);
}
$db = $ctx->db();

$commissaryId = 99701;
$branchId = 99702;
$productId = 99701;
$date = '2020-09-17';
$deliveryIds = [996000, 996001, 996002];
$receivingId = 996100;

$cleanup = static function () use (
    $db,
    $commissaryId,
    $branchId,
    $productId,
    $deliveryIds,
    $receivingId
): void {
    $deliveryIdList = implode(',', array_map('intval', $deliveryIds));
    $db->execute("DELETE FROM dl_branch_receiving_items WHERE receiving_id = {$receivingId}");
    $db->execute("DELETE FROM dl_branch_receivings WHERE id = {$receivingId} OR branch_id IN ({$commissaryId},{$branchId})");
    $db->execute("DELETE FROM dl_delivery_items WHERE delivery_id IN ({$deliveryIdList})");
    $db->execute("DELETE FROM dl_deliveries WHERE id IN ({$deliveryIdList})");
    $db->execute("DELETE FROM dl_production_movements WHERE destination_branch_id = {$commissaryId} OR product_id = {$productId}");
    $db->execute("DELETE FROM audit_logs WHERE branch_id = {$commissaryId} AND entity_id LIKE '{$commissaryId}-%-2020-09-17'");
    $db->execute("DELETE FROM dl_branch_products WHERE branch_id IN ({$commissaryId},{$branchId}) OR product_id = {$productId}");
    $db->execute("DELETE FROM dl_products WHERE id = {$productId}");
    $db->execute("DELETE FROM dl_branches WHERE id IN ({$commissaryId},{$branchId})");
};
$cleanup();

$insertAudit = static function (string $when, string $field, int $value) use ($db, $commissaryId, $productId, $date): int {
    $old = json_encode(['field' => $field, 'value' => $value - 1]);
    $new = json_encode([
        'field' => $field,
        'value' => $value,
        'product_id' => $productId,
        'product_name' => 'Log Order Product',
        'ledger_date' => $date,
    ]);
    $db->prepare(
        'INSERT INTO audit_logs
            (module, actor_module_user_id, actor_source, branch_id, action, entity_type, entity_id, old_data, new_data, created_at)
         VALUES ("daily-ledger", 27, "daily-ledger", ?, "production_ledger_change", "dl_commissary_product_ledger", ?, ?, ?, ?)'
    )->execute([$commissaryId, $commissaryId . '-' . $productId . '-' . $date, $old, $new, $when]);
    return (int)$db->lastInsertId();
};

try {
    $db->prepare('INSERT INTO dl_branches (id, code, name, default_supply_mode, assigned_commissary_id, is_commissary, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)')
        ->execute([$commissaryId, 'LOG-ORDER-C', 'Log Order Commissary', 'self_managed', null, 1]);
    $db->prepare('INSERT INTO dl_branches (id, code, name, default_supply_mode, assigned_commissary_id, is_commissary, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)')
        ->execute([$branchId, 'LOG-ORDER-B', 'Log Order Branch', 'commissary_supplied', $commissaryId, 0]);
    $db->prepare('INSERT INTO dl_products (id, sku, name, product_category, sort_order, is_active) VALUES (?, ?, ?, "cake", 1, 1)')
        ->execute([$productId, 'LOG-ORDER-P', 'Log Order Product']);
    $db->prepare('INSERT INTO dl_branch_products (branch_id, product_id, is_active) VALUES (?, ?, 1)')
        ->execute([$commissaryId, $productId]);

    // Exact regression: the four source families have interleaved timestamps.
    // A ~996000-id SENT event is one minute earlier than a small-id audit event;
    // the old timestamp*1000+id key moves SENT out of chronological order.
    $db->prepare(
        'INSERT INTO dl_deliveries
            (delivery_date, origin_type, origin_id, destination_type, destination_id, dr_number, status, created_by, posted_by, posted_at)
         VALUES (?, "commissary", ?, "branch", ?, "DR-ORDER-EARLY", "posted", 27, 27, ?)'
    )->execute([$date, $commissaryId, $branchId, $date . ' 08:00:00']);
    $insertedDeliveryId = (int)$db->lastInsertId();
    $db->prepare('UPDATE dl_deliveries SET id = ? WHERE id = ?')->execute([$deliveryIds[0], $insertedDeliveryId]);
    $db->prepare('INSERT INTO dl_delivery_items (delivery_id, product_id, quantity, unit, price_snapshot) VALUES (?, ?, 3, "pcs", 0)')
        ->execute([$deliveryIds[0], $productId]);
    $regressionAuditId = $insertAudit($date . ' 08:01:00', 'actual_end_qty', 4);

    // All four source families share a second. Source rank defines the reading
    // order, and two SENT rows prove the source id is the final tie-breaker.
    $db->prepare(
        'INSERT INTO dl_production_movements
            (movement_uuid, movement_type, flow_mode, destination_branch_id, product_id, ledger_date, quantity, override_reason, source_payload, created_by_id, created_by_role, created_at)
         VALUES (?, "output", "production", ?, ?, ?, 1, "order fixture", ?, 27, "production_in_charge", ?)'
    )->execute([
        '00000000-0000-0000-0000-000000099701',
        $commissaryId,
        $productId,
        $date,
        json_encode(['before_produced' => 0, 'after_produced' => 1]),
        $date . ' 07:59:00',
    ]);
    $insertAudit($date . ' 09:00:00', 'beg_qty', 2);

    foreach ([$deliveryIds[1], $deliveryIds[2]] as $index => $deliveryId) {
        $db->prepare(
            'INSERT INTO dl_deliveries
                (delivery_date, origin_type, origin_id, destination_type, destination_id, dr_number, status, created_by, posted_by, posted_at)
             VALUES (?, "commissary", ?, "branch", ?, ?, "posted", 27, 27, ?)'
        )->execute([$date, $commissaryId, $branchId, 'DR-ORDER-SAME-' . ($index + 1), $date . ' 09:00:00']);
        $insertedDeliveryId = (int)$db->lastInsertId();
        $db->prepare('UPDATE dl_deliveries SET id = ? WHERE id = ?')->execute([$deliveryId, $insertedDeliveryId]);
        $db->prepare('INSERT INTO dl_delivery_items (delivery_id, product_id, quantity, unit, price_snapshot) VALUES (?, ?, 1, "pcs", 0)')
            ->execute([$deliveryId, $productId]);
    }

    $firstSameSecondItem = $db->prepare('SELECT id FROM dl_delivery_items WHERE delivery_id = ?');
    $firstSameSecondItem->execute([$deliveryIds[1]]);
    $deliveryItemId = (int)$firstSameSecondItem->fetchColumn();
    $db->prepare(
        'INSERT INTO dl_branch_receivings
            (branch_id, origin_type, origin_id, delivery_id, dr_number, received_by, received_at, received_ledger_date, status, posted_by, posted_at)
         VALUES (?, "commissary", ?, ?, "DR-ORDER-SAME-1", 27, ?, ?, "posted", 27, ?)'
    )->execute([$branchId, $commissaryId, $deliveryIds[1], $date . ' 09:00:00', $date, $date . ' 09:00:00']);
    $insertedReceivingId = (int)$db->lastInsertId();
    $db->prepare('UPDATE dl_branch_receivings SET id = ? WHERE id = ?')->execute([$receivingId, $insertedReceivingId]);
    $db->prepare('INSERT INTO dl_branch_receiving_items (receiving_id, delivery_item_id, product_id, quantity_received, unit) VALUES (?, ?, ?, 1, "pcs")')
        ->execute([$receivingId, $deliveryItemId, $productId]);

    $first = dl_fetchProductionLedgerLog($db, $commissaryId, $date);
    $second = dl_fetchProductionLedgerLog($db, $commissaryId, $date);

    $ids = array_column($first, 'id');
    $earlySentPosition = array_search('sent-' . $deliveryIds[0], $ids, true);
    $laterAuditPosition = array_search('audit-' . $regressionAuditId, $ids, true);
    $h->test(
        'huge-id SENT stays before the later small-id audit (revert reverses these rows)',
        $earlySentPosition !== false
        && $laterAuditPosition !== false
        && $earlySentPosition < $laterAuditPosition,
        json_encode($ids)
    );

    $sameSecondIds = static function (array $log) use ($date): array {
        return array_values(array_column(array_filter(
            $log,
            static fn(array $row): bool => $row['when'] === $date . ' 09:00:00'
        ), 'id'));
    };
    $sameSecondSources = static function (array $log) use ($date): array {
        return array_values(array_column(array_filter(
            $log,
            static fn(array $row): bool => $row['when'] === $date . ' 09:00:00'
        ), 'source'));
    };
    $expectedSources = ['edit', 'send', 'send', 'receiving'];
    $h->test(
        'equal-second order is source-rank then id on both consecutive calls',
        $sameSecondSources($first) === $expectedSources
        && $sameSecondSources($second) === $expectedSources
        && $sameSecondIds($first) === $sameSecondIds($second),
        json_encode($sameSecondSources($first))
    );

    $times = array_map(static fn(array $row): int => (int)strtotime((string)$row['when']), $first);
    $sortedTimes = $times;
    sort($sortedTimes, SORT_NUMERIC);
    $sources = array_values(array_unique(array_column($first, 'source')));
    $movementPosition = array_search('movement', array_column($first, 'source'), true);
    $h->test(
        'fixture four-source trail is non-decreasing and interleaved huge-id SENT is in place',
        $times === $sortedTimes
        && count(array_intersect(['movement', 'edit', 'send', 'receiving'], $sources)) === 4
        && $movementPosition !== false
        && $earlySentPosition === $movementPosition + 1
        && $laterAuditPosition === $earlySentPosition + 1,
        json_encode(array_map(
            static fn(array $row): string => $row['when'] . ' ' . $row['id'],
            $first
        ))
    );
} finally {
    $cleanup();
}

$h->done();
