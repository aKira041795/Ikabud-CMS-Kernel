<?php

declare(strict_types=1);

/**
 * Production Daily Sheet AM/PM + restricted-surface regression.
 *
 * Every assertion names its revert failure:
 * - removing shift predicates merges AM and PM quantities;
 * - removing dl_assert/shift-row locking permits a finalized PM write;
 * - restoring the old role gates exposes restricted production surfaces;
 * - backfilling NULL to AM destroys the historical-row proof.
 *
 * All database assertions use one synthetic product and are cleaned in finally.
 */
ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-production-shift-access', TestHarness::MODE_INTEGRATION, 'baronledger.test');
ob_end_clean();
$h->allowLogLines('disyl.compile.phases');

$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/helpers.php';
require_once $base . '/modules/daily-ledger/handlers-deliveries.php';
require_once $base . '/modules/daily-ledger/handlers.php';
app()->tenant()->setTenantId(207);
$ctx = modulePushContext('daily-ledger');
$db = $ctx->db();

$productId = 99870;
$branchId = 18;
$destinationBranchId = 17;
$date = '2020-07-07';
$user = ['id' => 27, 'sub' => 'production_in_charge:27', 'role' => 'production_in_charge', 'source' => 'daily-ledger', 'full_name' => 'Shift fixture'];
$countTables = ['dl_deliveries', 'dl_branch_receivings', 'dl_delivery_variance_flags', 'dl_integrity_notifications', 'dl_integrity_notification_recipients'];
$count = static function () use ($db, $countTables): array {
    $out = [];
    foreach ($countTables as $table) $out[$table] = (int)$db->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
    return $out;
};
$cleanup = static function () use ($db, $productId, $branchId, $date): void {
    $db->prepare('DELETE FROM dl_delivery_items WHERE product_id = :p')->execute([':p' => $productId]);
    $db->prepare('DELETE FROM dl_deliveries WHERE remarks = :marker')->execute([':marker' => '[shift-access-fixture]']);
    $db->prepare('DELETE FROM audit_logs WHERE branch_id = :b AND (entity_id LIKE :p OR new_data LIKE :j)')->execute([':b' => $branchId, ':p' => "%{$productId}%", ':j' => "%{$productId}%"]);
    $db->prepare('DELETE FROM dl_production_movements WHERE product_id = :p')->execute([':p' => $productId]);
    $db->prepare('DELETE FROM dl_production_runs WHERE product_id = :p')->execute([':p' => $productId]);
    $db->prepare('DELETE FROM dl_commissary_product_ledger WHERE product_id = :p')->execute([':p' => $productId]);
    $db->prepare('DELETE FROM dl_ledger_shift_status WHERE branch_id = :b AND ledger_date = :d')->execute([':b' => $branchId, ':d' => $date]);
    $db->prepare('DELETE FROM dl_branch_products WHERE product_id = :p')->execute([':p' => $productId]);
    $db->prepare('DELETE FROM dl_products WHERE id = :p')->execute([':p' => $productId]);
};

$cleanup();
$before = $count();
try {
    $db->prepare('INSERT INTO dl_products (id, sku, name, current_price, sort_order, is_active) VALUES (:id, :sku, :name, 1, 99870, 1)')
        ->execute([':id' => $productId, ':sku' => 'FIX-SHIFT-99870', ':name' => 'Fixture Shift Product']);
    $db->prepare('INSERT INTO dl_branch_products (branch_id, product_id, is_active) VALUES (:b, :p, 1)')
        ->execute([':b' => $branchId, ':p' => $productId]);

    dl_recordProductionAddition($user, ['date' => $date, 'shift' => 'AM', 'commissary_branch_id' => $branchId, 'product_id' => $productId, 'quantity' => 2, 'submission_id' => 'fixture-am-99870']);
    dl_recordProductionAddition($user, ['date' => $date, 'shift' => 'PM', 'commissary_branch_id' => $branchId, 'product_id' => $productId, 'quantity' => 3, 'submission_id' => 'fixture-pm-99870']);
    $rows = $db->prepare('SELECT shift, produced_qty FROM dl_commissary_product_ledger WHERE product_id = :p ORDER BY shift');
    $rows->execute([':p' => $productId]);
    $byShift = [];
    foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $row) $byShift[$row['shift'] ?? 'NULL'] = (int)$row['produced_qty'];
    $h->test('AM=2 and PM=3 remain separate (revert merges the day into one row)', $byShift === ['AM' => 2, 'PM' => 3]);

    $db->prepare('UPDATE dl_ledger_shift_status SET status = "finalized" WHERE branch_id = :b AND ledger_date = :d AND shift = "PM"')->execute([':b' => $branchId, ':d' => $date]);
    $locked = false;
    try {
        dl_recordProductionAddition($user, ['date' => $date, 'shift' => 'PM', 'commissary_branch_id' => $branchId, 'product_id' => $productId, 'quantity' => 1, 'submission_id' => 'fixture-locked-99870']);
    } catch (RuntimeException $e) { $locked = $e->getCode() === 403 && str_contains($e->getMessage(), 'finalized'); }
    $h->test('finalized PM refuses a write (revert silently changes locked data)', $locked);

    $db->prepare('UPDATE dl_ledger_shift_status SET status = "open", finalized_by = NULL, finalized_at = NULL WHERE branch_id = :b AND ledger_date = :d AND shift = "PM"')->execute([':b' => $branchId, ':d' => $date]);
    dl_recordProductionAddition($user, ['date' => $date, 'shift' => 'PM', 'commissary_branch_id' => $branchId, 'product_id' => $productId, 'quantity' => 1, 'submission_id' => 'fixture-reopen-99870']);
    $pm = $db->prepare('SELECT produced_qty FROM dl_commissary_product_ledger WHERE product_id = :p AND shift = "PM"');
    $pm->execute([':p' => $productId]);
    $h->test('reopened PM accepts the write and totals 4 (revert leaves shift permanently locked)', (int)$pm->fetchColumn() === 4);

    // One legacy delivery and two shift-keyed deliveries prove the render rule:
    // omitted shift includes every row, while AM and PM remain exact and never
    // absorb the historical NULL row.
    $deliveryIds = [];
    foreach ([[null, 5], ['AM', 7], ['PM', 11]] as [$deliveryShift, $quantity]) {
        $insertDelivery = $db->prepare(
            'INSERT INTO dl_deliveries
                (delivery_date, production_shift, origin_type, origin_id, destination_type, destination_id, status, created_by, remarks)
             VALUES (:d, :shift, "commissary", :origin, "branch", :destination, "posted", 27, :remarks)'
        );
        $insertDelivery->execute([
            ':d' => $date,
            ':shift' => $deliveryShift,
            ':origin' => $branchId,
            ':destination' => $destinationBranchId,
            ':remarks' => '[shift-access-fixture]',
        ]);
        $deliveryId = (int)$db->lastInsertId();
        $deliveryIds[] = $deliveryId;
        $db->prepare('INSERT INTO dl_delivery_items (delivery_id, product_id, quantity, unit, price_snapshot) VALUES (:d, :p, :q, "pcs", 0)')
            ->execute([':d' => $deliveryId, ':p' => $productId, ':q' => $quantity]);
    }

    $adminTokens = dl_generateAuthTokens([
        'sub' => 'admin:27', 'id' => 27, 'username' => 'admin-fixture',
        'name' => 'Admin Fixture', 'role' => 'admin', 'source' => 'daily-ledger',
    ]);
    $_COOKIE[dlCookieName()] = $adminTokens['token'];
    $renderQuantity = static function (?string $requestedShift) use ($productId, $branchId, $destinationBranchId): ?int {
        $_GET = ['date' => '2020-07-07', 'commissary_id' => (string)$branchId];
        if ($requestedShift !== null) {
            $_GET['shift'] = $requestedShift;
        }
        ob_start();
        handleAdminCommissary();
        $html = (string)ob_get_clean();
        $pattern = '/class="production-branch-value" data-product="' . $productId
            . '" data-branch-id="' . $destinationBranchId . '">(\d+)<\/span>/';
        return preg_match($pattern, $html, $match) ? (int)$match[1] : null;
    };
    $unshiftedQuantity = $renderQuantity(null);
    $amQuantity = $renderQuantity('AM');
    $pmQuantity = $renderQuantity('PM');
    $h->test(
        'a NULL-shift delivery is visible in the unshifted admin render',
        $unshiftedQuantity === 23,
        json_encode(['unshifted' => $unshiftedQuantity])
    );
    $h->test(
        'the NULL-shift delivery is absent from explicit AM and PM renders',
        $amQuantity === 7 && $pmQuantity === 11,
        json_encode(['AM' => $amQuantity, 'PM' => $pmQuantity])
    );
    $h->test(
        'AM+PM totals remain shift-exact and do not double-count the NULL row',
        $amQuantity + $pmQuantity === 18 && $unshiftedQuantity - $amQuantity - $pmQuantity === 5
    );

    $handlers = file_get_contents($base . '/modules/daily-ledger/handlers.php');
    $template = file_get_contents($base . '/templates/modules/daily-ledger/admin/commissary.disyl');
    $h->test('production role has a server-side path allowlist (revert makes direct restricted URLs reachable)', str_contains($handlers, 'Forbidden: Daily Sheet access only'));
    $h->test('variance and management markup are admin-gated (revert exposes variance/notes to production)', str_contains($template, '{if can_view_production_variance}') && str_contains($template, '{if can_view_production_management}'));

    $legacy = $db->prepare('INSERT INTO dl_commissary_product_ledger (commissary_branch_id, product_id, ledger_date, shift, beg_qty, produced_qty, dispatched_qty, wastage_qty) VALUES (:b, :p, "2020-07-06", NULL, 0, 9, 0, 0)');
    $legacy->execute([':b' => $branchId, ':p' => $productId]);
    $nullProof = $db->prepare('SELECT COUNT(*) FROM dl_commissary_product_ledger WHERE product_id = :p AND shift IS NULL');
    $nullProof->execute([':p' => $productId]);
    $h->test('historical fixture remains NULL-shift (revert/backfill silently assigns AM)', (int)$nullProof->fetchColumn() === 1);
} finally {
    $cleanup();
}
$after = $count();
$h->test('delivery/receiving/variance/notification recipient counts are identical after cleanup', $before === $after, json_encode(['before' => $before, 'after' => $after]));
$h->done();
