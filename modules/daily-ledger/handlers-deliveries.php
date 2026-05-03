<?php

declare(strict_types=1);

/**
 * Daily Ledger — Supply / Pricing / Delivery / Receiving / Selling-Account helpers + handlers.
 *
 * Implements Phases A–F of the Daily Ledger inventory spec:
 *   A. Branch supply mode + per-product supply rules
 *   B. Formal deliveries + branch receivings with draft/posted/voided status
 *   C. Reason-coded cashier withdrawals  (handled in apiSaveCashierWithdrawals)
 *   D. Price groups + product_prices (with effective windows)
 *   E. Selling accounts + selling-account ledger
 *   F. Branch consolidated summary + delivery variance flags
 *
 * Loaded from handlers.php via require_once.
 */

// ─── Phase A: Supply-source resolution ────────────────────────────────────

function dl_resolveProductSupplySource(int $branchId, int $productId): array
{
    $ctx = module();
    if (!$ctx) {
        return ['source' => 'self_managed', 'source_id' => null, 'origin' => 'fallback'];
    }

    $stmt = $ctx->db()->prepare(
        'SELECT supply_source_type, source_id
           FROM dl_branch_product_supply_rules
          WHERE branch_id = :b AND product_id = :p AND is_active = 1
          LIMIT 1'
    );
    $stmt->execute([':b' => $branchId, ':p' => $productId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        return [
            'source' => (string)$row['supply_source_type'],
            'source_id' => $row['source_id'] !== null ? (int)$row['source_id'] : null,
            'origin' => 'override',
        ];
    }

    $bStmt = $ctx->db()->prepare(
        'SELECT default_supply_mode, assigned_commissary_id FROM dl_branches WHERE id = :b LIMIT 1'
    );
    $bStmt->execute([':b' => $branchId]);
    $branch = $bStmt->fetch(PDO::FETCH_ASSOC);
    if (!$branch) {
        return ['source' => 'self_managed', 'source_id' => null, 'origin' => 'fallback'];
    }

    $mode = (string)$branch['default_supply_mode'];
    $assigned = $branch['assigned_commissary_id'] !== null ? (int)$branch['assigned_commissary_id'] : null;

    $source = match ($mode) {
        'commissary_supplied' => 'commissary',
        'self_managed'        => 'local_production',
        'hybrid'              => 'commissary',
        default               => 'manual',
    };

    return ['source' => $source, 'source_id' => $assigned, 'origin' => 'branch_default', 'mode' => $mode];
}

// ─── Phase D: Price-group resolution ──────────────────────────────────────

function dl_defaultPriceGroupId(): ?int
{
    $ctx = module();
    if (!$ctx) {
        return null;
    }
    static $cached = null;
    if ($cached !== null) {
        return $cached ?: null;
    }
    $row = $ctx->db()->query('SELECT id FROM dl_price_groups WHERE is_default = 1 AND is_active = 1 LIMIT 1')
        ->fetch(PDO::FETCH_ASSOC);
    $cached = $row ? (int)$row['id'] : 0;
    return $cached ?: null;
}

function dl_branchPriceGroupId(int $branchId): ?int
{
    $ctx = module();
    if (!$ctx || $branchId <= 0) {
        return dl_defaultPriceGroupId();
    }

    static $cache = [];
    if (array_key_exists($branchId, $cache)) {
        return $cache[$branchId];
    }

    $stmt = $ctx->db()->prepare('SELECT price_group_id FROM dl_branches WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $branchId]);
    $value = $stmt->fetchColumn();
    $cache[$branchId] = ($value !== false && $value !== null) ? (int)$value : dl_defaultPriceGroupId();
    return $cache[$branchId];
}

function dl_resolveBranchProductPrice(int $branchId, int $productId, ?string $atDate = null): float
{
    return dl_resolveProductPrice($productId, dl_branchPriceGroupId($branchId), $atDate);
}

function dl_resolveProductPrice(int $productId, ?int $priceGroupId = null, ?string $atDate = null): float
{
    $ctx = module();
    if (!$ctx) {
        return 0.0;
    }

    $atDate = $atDate ?: date('Y-m-d');
    $priceGroupId = $priceGroupId ?: dl_defaultPriceGroupId();

        if (dl_arePriceGroupsEnabled() && $priceGroupId !== null) {
        $stmt = $ctx->db()->prepare(
            'SELECT selling_price FROM dl_product_prices
              WHERE product_id = :p AND price_group_id = :g AND is_active = 1
                AND effective_from <= :d1
                AND (effective_to IS NULL OR effective_to >= :d2)
              ORDER BY effective_from DESC
              LIMIT 1'
        );
        $stmt->execute([':p' => $productId, ':g' => $priceGroupId, ':d1' => $atDate, ':d2' => $atDate]);
        $price = $stmt->fetchColumn();
        if ($price !== false && $price !== null) {
            return (float)$price;
        }
    }

    $stmt = $ctx->db()->prepare('SELECT current_price FROM dl_products WHERE id = :p');
    $stmt->execute([':p' => $productId]);
    return (float)($stmt->fetchColumn() ?: 0.0);
}

// ─── Phase B: Delivery + Receiving handlers ───────────────────────────────

function dl_normalizeDeliveryItems(array $items): array
{
    $clean = [];
    foreach ($items as $i) {
        if (!is_array($i)) continue;
        $pid = (int)($i['product_id'] ?? 0);
        $qty = (int)($i['quantity'] ?? 0);
        if ($pid <= 0 || $qty <= 0) continue;
        $clean[] = [
            'product_id' => $pid,
            'quantity' => $qty,
            'unit' => trim((string)($i['unit'] ?? 'pcs')) ?: 'pcs',
            'unit_cost_snapshot' => isset($i['unit_cost']) ? (float)$i['unit_cost'] : null,
            'remarks' => isset($i['remarks']) ? (string)$i['remarks'] : null,
        ];
    }
    return $clean;
}

function dl_isFormalDeliveryEnabled(): bool
{
    $settings = dlModuleSettings();
    return dl_settingToBool($settings['formal_delivery_workflow_enabled'] ?? false);
}

function dl_autoCommissaryDeliveryRemark(): string
{
    return '[auto-commissary-run]';
}

function dl_findAutoCommissaryDelivery(\Ikabud\Kernel\Contracts\DatabaseContract $db, int $branchId, string $deliveryDate, string $drNumber): ?array
{
    $stmt = $db->prepare(
        'SELECT *
           FROM dl_deliveries
          WHERE origin_type = :origin_type
            AND destination_type = :destination_type
            AND destination_id = :destination_id
            AND delivery_date = :delivery_date
            AND dr_number = :dr_number
            AND remarks = :remarks
            AND status <> "voided"
          ORDER BY id DESC
          LIMIT 1'
    );
    $stmt->execute([
        ':origin_type' => 'commissary',
        ':destination_type' => 'branch',
        ':destination_id' => $branchId,
        ':delivery_date' => $deliveryDate,
        ':dr_number' => $drNumber,
        ':remarks' => dl_autoCommissaryDeliveryRemark(),
    ]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function dl_deliveryHasActiveReceivings(\Ikabud\Kernel\Contracts\DatabaseContract $db, int $deliveryId): bool
{
    $stmt = $db->prepare(
        'SELECT COUNT(*)
           FROM dl_branch_receivings
          WHERE delivery_id = :delivery_id
            AND status <> "voided"'
    );
    $stmt->execute([':delivery_id' => $deliveryId]);
    return (int)$stmt->fetchColumn() > 0;
}

function dl_deliveryItemsMatchDesired(\Ikabud\Kernel\Contracts\DatabaseContract $db, int $deliveryId, array $desiredItems): bool
{
    $stmt = $db->prepare(
        'SELECT product_id, SUM(quantity) AS quantity
           FROM dl_delivery_items
          WHERE delivery_id = :delivery_id
          GROUP BY product_id'
    );
    $stmt->execute([':delivery_id' => $deliveryId]);

    $existing = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $existing[(int)$row['product_id']] = (int)$row['quantity'];
    }

    $desired = [];
    foreach ($desiredItems as $item) {
        $desired[(int)$item['product_id']] = (int)$item['quantity'];
    }

    ksort($existing);
    ksort($desired);

    return $existing === $desired;
}

function dl_collectCommissaryRunDeliveryItems(\Ikabud\Kernel\Contracts\DatabaseContract $db, string $deliveryDate, int $branchId, string $drNumber): array
{
    $stmt = $db->prepare(
        'SELECT product_id, SUM(yield_qty) AS quantity
           FROM dl_production_runs
          WHERE ledger_date = :ledger_date
            AND destination_branch_id = :destination_branch_id
            AND dr_number = :dr_number
            AND yield_qty > 0
          GROUP BY product_id'
    );
    $stmt->execute([
        ':ledger_date' => $deliveryDate,
        ':destination_branch_id' => $branchId,
        ':dr_number' => $drNumber,
    ]);

    $priceGroupId = dl_defaultPriceGroupId();
    $items = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $productId = (int)$row['product_id'];
        $quantity = (int)$row['quantity'];
        if ($productId <= 0 || $quantity <= 0) {
            continue;
        }
        $items[] = [
            'product_id' => $productId,
            'quantity' => $quantity,
            'unit' => 'pcs',
            'unit_cost_snapshot' => null,
            'price_snapshot' => dl_resolveProductPrice($productId, $priceGroupId, $deliveryDate),
            'price_group_id' => $priceGroupId,
            'remarks' => null,
        ];
    }

    return $items;
}

function dl_syncAutoCommissaryDeliveryFromRuns(\Ikabud\Kernel\Contracts\DatabaseContract $db, string $deliveryDate, int $branchId, string $drNumber, int $actorId): ?int
{
    if ($branchId <= 0 || trim($drNumber) === '') {
        return null;
    }

    $desiredItems = dl_collectCommissaryRunDeliveryItems($db, $deliveryDate, $branchId, $drNumber);
    $existing = dl_findAutoCommissaryDelivery($db, $branchId, $deliveryDate, $drNumber);

    if ($desiredItems === []) {
        if ($existing) {
            $deliveryId = (int)$existing['id'];
            if (dl_deliveryHasActiveReceivings($db, $deliveryId)) {
                throw new RuntimeException('Cannot remove or reduce a delivery that already has a receiving. Void the receiving first.');
            }
            $db->prepare(
                'UPDATE dl_deliveries
                    SET status = "voided",
                        voided_by = :actor,
                        voided_at = NOW()
                  WHERE id = :id'
            )->execute([
                ':actor' => $actorId > 0 ? $actorId : null,
                ':id' => $deliveryId,
            ]);
            dl_auditLog('update_delivery', $branchId, 'dl_deliveries', (string)$deliveryId, [
                'status' => $existing['status'] ?? 'posted',
                'dr_number' => $drNumber,
            ], [
                'status' => 'voided',
                'dr_number' => $drNumber,
                'source' => 'auto_commissary_run',
            ]);
        }
        return null;
    }

    $deliveryId = 0;
    if ($existing) {
        $deliveryId = (int)$existing['id'];
        if (dl_deliveryHasActiveReceivings($db, $deliveryId)) {
            if (!dl_deliveryItemsMatchDesired($db, $deliveryId, $desiredItems)) {
                throw new RuntimeException('This delivery already has a receiving. Update the receiving workflow instead of changing the usage rows.');
            }
            return $deliveryId;
        }

        $db->prepare(
            'UPDATE dl_deliveries
                SET status = "posted",
                    posted_by = :actor,
                    posted_at = NOW(),
                    remarks = :remarks
              WHERE id = :id'
        )->execute([
            ':actor' => $actorId > 0 ? $actorId : null,
            ':remarks' => dl_autoCommissaryDeliveryRemark(),
            ':id' => $deliveryId,
        ]);
        $db->prepare('DELETE FROM dl_delivery_items WHERE delivery_id = :delivery_id')
            ->execute([':delivery_id' => $deliveryId]);

        dl_auditLog('update_delivery', $branchId, 'dl_deliveries', (string)$deliveryId, null, [
            'dr_number' => $drNumber,
            'status' => 'posted',
            'source' => 'auto_commissary_run',
            'items' => count($desiredItems),
        ]);
    } else {
        $stmt = $db->prepare(
            'INSERT INTO dl_deliveries
                (origin_type, origin_id, destination_type, destination_id, dr_number,
                 delivery_date, status, created_by, posted_by, posted_at, remarks)
             VALUES (:origin_type, NULL, :destination_type, :destination_id, :dr_number,
                     :delivery_date, "posted", :created_by, :posted_by, NOW(), :remarks)'
        );
        $stmt->execute([
            ':origin_type' => 'commissary',
            ':destination_type' => 'branch',
            ':destination_id' => $branchId,
            ':dr_number' => $drNumber,
            ':delivery_date' => $deliveryDate,
            ':created_by' => $actorId > 0 ? $actorId : null,
            ':posted_by' => $actorId > 0 ? $actorId : null,
            ':remarks' => dl_autoCommissaryDeliveryRemark(),
        ]);
        $deliveryId = (int)$db->lastInsertId();

        dl_auditLog('create_delivery', $branchId, 'dl_deliveries', (string)$deliveryId, null, [
            'dr_number' => $drNumber,
            'status' => 'posted',
            'source' => 'auto_commissary_run',
            'items' => count($desiredItems),
        ]);
    }

    $itemStmt = $db->prepare(
        'INSERT INTO dl_delivery_items
            (delivery_id, product_id, quantity, unit, unit_cost_snapshot, price_snapshot, price_group_id, remarks)
         VALUES (:delivery_id, :product_id, :quantity, :unit, :unit_cost_snapshot, :price_snapshot, :price_group_id, :remarks)'
    );
    foreach ($desiredItems as $item) {
        $itemStmt->execute([
            ':delivery_id' => $deliveryId,
            ':product_id' => $item['product_id'],
            ':quantity' => $item['quantity'],
            ':unit' => $item['unit'],
            ':unit_cost_snapshot' => $item['unit_cost_snapshot'],
            ':price_snapshot' => $item['price_snapshot'],
            ':price_group_id' => $item['price_group_id'],
            ':remarks' => $item['remarks'],
        ]);
    }

    return $deliveryId;
}

function dl_acceptFormalDelivery(\Ikabud\Kernel\Contracts\DatabaseContract $db, int $branchId, int $deliveryId, int $userId, string $receiveDate, ?array $partialQtys = null): int
{
    $headStmt = $db->prepare(
        'SELECT *
           FROM dl_deliveries
          WHERE id = :id
            AND destination_type = "branch"
            AND destination_id = :branch_id
            AND status = "posted"
          LIMIT 1'
    );
    $headStmt->execute([':id' => $deliveryId, ':branch_id' => $branchId]);
    $head = $headStmt->fetch(PDO::FETCH_ASSOC);
    if (!$head) {
        throw new RuntimeException('Posted delivery not found for this branch.');
    }

    if (dl_deliveryHasActiveReceivings($db, $deliveryId)) {
        throw new RuntimeException('This delivery already has a receiving.');
    }

    $itemsStmt = $db->prepare(
        'SELECT id, product_id, quantity, unit, unit_cost_snapshot, price_snapshot, remarks
           FROM dl_delivery_items
          WHERE delivery_id = :delivery_id'
    );
    $itemsStmt->execute([':delivery_id' => $deliveryId]);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if ($items === []) {
        throw new RuntimeException('Delivery has no items to receive.');
    }

    // Validate partial quantities when provided.
    if ($partialQtys !== null) {
        foreach ($items as $item) {
            $pid = (int)$item['product_id'];
            if (isset($partialQtys[$pid])) {
                $given = (int)$partialQtys[$pid];
                if ($given < 0 || $given > (int)$item['quantity']) {
                    throw new RuntimeException(
                        "Partial qty {$given} for product {$pid} exceeds delivery qty {$item['quantity']}."
                    );
                }
            }
        }
    }

    $rcvStmt = $db->prepare(
        'INSERT INTO dl_branch_receivings
            (branch_id, origin_type, origin_id, delivery_id, dr_number,
             received_by, received_at, received_ledger_date, status, posted_by, posted_at, remarks)
         VALUES (:branch_id, :origin_type, :origin_id, :delivery_id, :dr_number,
                 :received_by, NOW(), :receive_date, "posted", :posted_by, NOW(), :remarks)'
    );
    $rcvStmt->execute([
        ':branch_id' => $branchId,
        ':origin_type' => (string)$head['origin_type'],
        ':origin_id' => $head['origin_id'] !== null ? (int)$head['origin_id'] : null,
        ':delivery_id' => $deliveryId,
        ':dr_number' => $head['dr_number'],
        ':received_by' => $userId > 0 ? $userId : null,
        ':receive_date' => $receiveDate,
        ':posted_by' => $userId > 0 ? $userId : null,
        ':remarks' => $head['remarks'],
    ]);
    $receivingId = (int)$db->lastInsertId();

    $rcvItemStmt = $db->prepare(
        'INSERT INTO dl_branch_receiving_items
            (receiving_id, delivery_item_id, product_id, quantity_received, unit,
             unit_cost_snapshot, selling_price_snapshot, remarks)
         VALUES (:receiving_id, :delivery_item_id, :product_id, :quantity_received, :unit,
                 :unit_cost_snapshot, :selling_price_snapshot, :remarks)'
    );
    foreach ($items as $item) {
        $pid = (int)$item['product_id'];
        $quantity = $partialQtys !== null && isset($partialQtys[$pid])
            ? (int)$partialQtys[$pid]
            : (int)$item['quantity'];
        $rcvItemStmt->execute([
            ':receiving_id' => $receivingId,
            ':delivery_item_id' => (int)$item['id'],
            ':product_id' => $pid,
            ':quantity_received' => $quantity,
            ':unit' => (string)$item['unit'],
            ':unit_cost_snapshot' => $item['unit_cost_snapshot'] !== null ? (float)$item['unit_cost_snapshot'] : null,
            ':selling_price_snapshot' => $item['price_snapshot'] !== null ? (float)$item['price_snapshot'] : null,
            ':remarks' => $item['remarks'],
        ]);
        if ($quantity > 0) {
            dl_applyLedgerDelta($branchId, $pid, $receiveDate, $quantity, $userId, 'addtl');
        }
    }

    dl_recordReceivingVariances($receivingId);
    dl_auditLog('create_receiving', $branchId, 'dl_branch_receivings', (string)$receivingId, null, [
        'delivery_id' => $deliveryId,
        'status' => 'posted',
        'dr_number' => $head['dr_number'],
        'items' => count($items),
    ]);

    return $receivingId;
}

function dl_areSellingAccountsEnabled(): bool
{
    $settings = dlModuleSettings();
    return dl_settingToBool($settings['selling_accounts_enabled'] ?? false);
}

function dl_arePriceGroupsEnabled(): bool
{
    $settings = dlModuleSettings();
    return dl_settingToBool($settings['price_groups_enabled'] ?? true);
}

function apiCreateDelivery(array $params = []): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); return; }
    $user = dlCurrentUser();
    $userId = (int)($user['sub'] ?? 0);
    $input = (array)json_decode(file_get_contents('php://input'), true);

    $originType = (string)($input['origin_type'] ?? '');
    $originId   = isset($input['origin_id']) ? (int)$input['origin_id'] : null;
    $destType   = (string)($input['destination_type'] ?? '');
    $destId     = isset($input['destination_id']) ? (int)$input['destination_id'] : null;
    $drNumber   = trim((string)($input['dr_number'] ?? '')) ?: null;
    $delivDate  = (string)($input['delivery_date'] ?? date('Y-m-d'));
    $remarks    = trim((string)($input['remarks'] ?? '')) ?: null;
    $items      = dl_normalizeDeliveryItems((array)($input['items'] ?? []));

    $allowedOrigins = ['commissary','branch','supplier','manual'];
    $allowedDests   = ['branch','selling_account','own_account','reseller','customer','event','wastage','internal_use','adjustment'];
    if (!in_array($originType, $allowedOrigins, true) || !in_array($destType, $allowedDests, true)) {
        $ctx->json(['ok' => false, 'error' => 'Invalid origin or destination type'], 422);
        return;
    }
    if (count($items) === 0) {
        $ctx->json(['ok' => false, 'error' => 'At least one item is required'], 422);
        return;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $delivDate)) {
        $ctx->json(['ok' => false, 'error' => 'Invalid delivery_date'], 422);
        return;
    }
    if ($destType === 'branch' && !dl_isFormalDeliveryEnabled()) {
        $ctx->json(['ok' => false, 'error' => 'Formal Delivery Workflow is disabled for branch deliveries.'], 403);
        return;
    }
    if ($destType === 'selling_account' && !dl_areSellingAccountsEnabled()) {
        $ctx->json(['ok' => false, 'error' => 'Selling Accounts feature is disabled.'], 403);
        return;
    }

    $priceGroupId = null;
    if (dl_arePriceGroupsEnabled() && $destType === 'selling_account' && $destId) {
        $stmt = $ctx->db()->prepare('SELECT price_group_id FROM dl_selling_accounts WHERE id = :id');
        $stmt->execute([':id' => $destId]);
        $pgId = $stmt->fetchColumn();
        $priceGroupId = $pgId !== false && $pgId !== null ? (int)$pgId : null;
    }
    if (dl_arePriceGroupsEnabled() && $priceGroupId === null) {
        $priceGroupId = dl_defaultPriceGroupId();
    }

    $ctx->db()->beginTransaction();
    try {
        $ins = $ctx->db()->prepare(
            'INSERT INTO dl_deliveries
                (origin_type, origin_id, destination_type, destination_id, dr_number,
                 delivery_date, status, created_by, remarks)
             VALUES (:ot, :oid, :dt, :did, :dr, :dd, "draft", :uid, :rmk)'
        );
        $ins->execute([
            ':ot' => $originType, ':oid' => $originId,
            ':dt' => $destType,   ':did' => $destId,
            ':dr' => $drNumber,   ':dd' => $delivDate,
            ':uid' => $userId ?: null, ':rmk' => $remarks,
        ]);
        $deliveryId = (int)$ctx->db()->lastInsertId();

        $insItem = $ctx->db()->prepare(
            'INSERT INTO dl_delivery_items
                (delivery_id, product_id, quantity, unit, unit_cost_snapshot, price_snapshot, price_group_id, remarks)
             VALUES (:d, :p, :q, :u, :uc, :ps, :pg, :rmk)'
        );
        foreach ($items as $it) {
            $price = dl_resolveProductPrice($it['product_id'], $priceGroupId, $delivDate);
            $insItem->execute([
                ':d' => $deliveryId, ':p' => $it['product_id'], ':q' => $it['quantity'],
                ':u' => $it['unit'], ':uc' => $it['unit_cost_snapshot'],
                ':ps' => $price, ':pg' => $priceGroupId, ':rmk' => $it['remarks'],
            ]);
        }

        $ctx->db()->commit();
        dl_auditLog('delivery_created', $originType === 'branch' ? $originId : null,
            'dl_deliveries', (string)$deliveryId, null,
            ['destination_type' => $destType, 'destination_id' => $destId, 'items' => count($items)]);
        $ctx->json(['ok' => true, 'delivery_id' => $deliveryId, 'status' => 'draft']);
    } catch (\Throwable $e) {
        $ctx->db()->rollBack();
        $ctx->log('apiCreateDelivery: ' . $e->getMessage(), 'error');
        $ctx->json(['ok' => false, 'error' => 'Database error'], 500);
    }
}

function dl_postDeliveryToSellingAccount(int $deliveryId): void
{
    $ctx = module();
    if (!$ctx) return;

    $h = $ctx->db()->prepare('SELECT * FROM dl_deliveries WHERE id = :id');
    $h->execute([':id' => $deliveryId]);
    $delivery = $h->fetch(PDO::FETCH_ASSOC);
    if (!$delivery || $delivery['destination_type'] !== 'selling_account' || !$delivery['destination_id']) {
        return;
    }
    $accId = (int)$delivery['destination_id'];
    $date  = (string)$delivery['delivery_date'];

    $items = $ctx->db()->prepare('SELECT * FROM dl_delivery_items WHERE delivery_id = :id');
    $items->execute([':id' => $deliveryId]);
    $rows = $items->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $check = $ctx->db()->prepare(
        'SELECT id, delivered_qty FROM dl_selling_account_ledger
          WHERE selling_account_id = :a AND product_id = :p AND ledger_date = :d FOR UPDATE'
    );
    $upd = $ctx->db()->prepare(
        'UPDATE dl_selling_account_ledger
            SET delivered_qty = delivered_qty + :q,
                price_snapshot = :ps,
                sold_qty = beg_qty + (delivered_qty + :q2) - return_qty - end_qty,
                gross_amount = (beg_qty + (delivered_qty + :q3) - return_qty - end_qty) * :ps2
          WHERE id = :id'
    );
    $ins = $ctx->db()->prepare(
        'INSERT INTO dl_selling_account_ledger
            (selling_account_id, product_id, ledger_date, price_snapshot, delivered_qty, sold_qty, gross_amount)
         VALUES (:a, :p, :d, :ps, :q, 0 - 0, 0)'
    );
    foreach ($rows as $r) {
        $pid = (int)$r['product_id'];
        $qty = (int)$r['quantity'];
        $ps  = (float)$r['price_snapshot'];
        $check->execute([':a' => $accId, ':p' => $pid, ':d' => $date]);
        $ex = $check->fetch(PDO::FETCH_ASSOC);
        if ($ex) {
            $upd->execute([
                ':q' => $qty, ':q2' => $qty, ':q3' => $qty,
                ':ps' => $ps, ':ps2' => $ps, ':id' => (int)$ex['id'],
            ]);
        } else {
            $ins->execute([
                ':a' => $accId, ':p' => $pid, ':d' => $date,
                ':ps' => $ps, ':q' => $qty,
            ]);
            // Recompute sold_qty/gross via update path for the new row.
            $check->execute([':a' => $accId, ':p' => $pid, ':d' => $date]);
            $newRow = $check->fetch(PDO::FETCH_ASSOC);
            if ($newRow) {
                $ctx->db()->prepare(
                    'UPDATE dl_selling_account_ledger
                        SET sold_qty = beg_qty + delivered_qty - return_qty - end_qty,
                            gross_amount = (beg_qty + delivered_qty - return_qty - end_qty) * price_snapshot
                      WHERE id = :id'
                )->execute([':id' => (int)$newRow['id']]);
            }
        }
    }
}

function apiPostDelivery(array $params = []): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); return; }
    $user = dlCurrentUser();
    $userId = (int)($user['sub'] ?? 0);
    $input = (array)json_decode(file_get_contents('php://input'), true);
    $deliveryId = (int)($input['delivery_id'] ?? 0);
    if ($deliveryId <= 0) { $ctx->json(['ok' => false, 'error' => 'delivery_id required'], 422); return; }

    $ctx->db()->beginTransaction();
    try {
        $stmt = $ctx->db()->prepare('SELECT * FROM dl_deliveries WHERE id = :id FOR UPDATE');
        $stmt->execute([':id' => $deliveryId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) { throw new \RuntimeException('Delivery not found'); }
        if ($row['status'] !== 'draft') { throw new \RuntimeException('Only draft deliveries can be posted'); }
        if ((string)$row['destination_type'] === 'branch' && !dl_isFormalDeliveryEnabled()) {
            throw new \RuntimeException('Formal Delivery Workflow is disabled for branch deliveries.');
        }
        if ((string)$row['destination_type'] === 'selling_account' && !dl_areSellingAccountsEnabled()) {
            throw new \RuntimeException('Selling Accounts feature is disabled.');
        }

        $ctx->db()->prepare(
            'UPDATE dl_deliveries SET status = "posted", posted_by = :u, posted_at = NOW() WHERE id = :id'
        )->execute([':u' => $userId ?: null, ':id' => $deliveryId]);

        if ($row['destination_type'] === 'selling_account') {
            dl_postDeliveryToSellingAccount($deliveryId);
        }

        $ctx->db()->commit();
        dl_auditLog('delivery_posted', $row['origin_type'] === 'branch' ? (int)$row['origin_id'] : null,
            'dl_deliveries', (string)$deliveryId, ['status' => 'draft'], ['status' => 'posted']);
        $ctx->json(['ok' => true]);
    } catch (\Throwable $e) {
        $ctx->db()->rollBack();
        $ctx->json(['ok' => false, 'error' => $e->getMessage()], 400);
    }
}

function apiVoidDelivery(array $params = []): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); return; }
    $user = dlCurrentUser(['admin','supervisor']);
    $userId = (int)($user['sub'] ?? 0);
    $input = (array)json_decode(file_get_contents('php://input'), true);
    $deliveryId = (int)($input['delivery_id'] ?? 0);
    $reason = trim((string)($input['reason'] ?? ''));
    if ($deliveryId <= 0) { $ctx->json(['ok' => false, 'error' => 'delivery_id required'], 422); return; }

    try {
        $stmt = $ctx->db()->prepare('SELECT status FROM dl_deliveries WHERE id = :id');
        $stmt->execute([':id' => $deliveryId]);
        $status = $stmt->fetchColumn();
        if ($status === false) { $ctx->json(['ok' => false, 'error' => 'Delivery not found'], 404); return; }
        if ($status === 'voided') { $ctx->json(['ok' => true]); return; }

        $ctx->db()->prepare(
            'UPDATE dl_deliveries SET status = "voided", voided_by = :u, voided_at = NOW() WHERE id = :id'
        )->execute([':u' => $userId ?: null, ':id' => $deliveryId]);

        dl_auditLog('delivery_voided', null, 'dl_deliveries', (string)$deliveryId,
            ['status' => $status], ['status' => 'voided'], $reason ?: null);
        $ctx->json(['ok' => true]);
    } catch (\Throwable $e) {
        $ctx->json(['ok' => false, 'error' => 'Database error'], 500);
    }
}

function apiListDeliveries(array $params = []): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); return; }
    dlCurrentUser();
    $status = (string)($_GET['status'] ?? '');
    $destType = (string)($_GET['destination_type'] ?? '');
    $destId   = isset($_GET['destination_id']) ? (int)$_GET['destination_id'] : 0;

    $where = [];
    $bind = [];
    if ($status !== '') { $where[] = 'status = :s'; $bind[':s'] = $status; }
    if ($destType !== '') { $where[] = 'destination_type = :dt'; $bind[':dt'] = $destType; }
    if ($destId > 0) { $where[] = 'destination_id = :did'; $bind[':did'] = $destId; }
    $sql = 'SELECT id, origin_type, origin_id, destination_type, destination_id, dr_number,
                   delivery_date, status, created_at, posted_at, voided_at
              FROM dl_deliveries'
         . (count($where) ? ' WHERE ' . implode(' AND ', $where) : '')
         . ' ORDER BY delivery_date DESC, id DESC LIMIT 200';
    $stmt = $ctx->db()->prepare($sql);
    $stmt->execute($bind);
    $ctx->json(['ok' => true, 'deliveries' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []]);
}

function apiCreateReceiving(array $params = []): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); return; }
    if (!dl_isFormalDeliveryEnabled()) {
        $ctx->json(['ok' => false, 'error' => 'Formal Delivery Workflow is disabled.'], 403);
        return;
    }
    $user = dlCurrentUser();
    $userId = (int)($user['sub'] ?? 0);
    $input = (array)json_decode(file_get_contents('php://input'), true);

    $branchId = dl_resolveLedgerBranchId($user, $input);
    if ($branchId <= 0) { $ctx->json(['ok' => false, 'error' => 'Missing branch'], 422); return; }

    $deliveryId = isset($input['delivery_id']) ? (int)$input['delivery_id'] : null;
    $originType = $deliveryId ? '' : (string)($input['origin_type'] ?? 'manual_adjustment');
    $originId   = isset($input['origin_id']) ? (int)$input['origin_id'] : null;
    $drNumber   = trim((string)($input['dr_number'] ?? '')) ?: null;
    $rcvDate    = (string)($input['received_ledger_date'] ?? dl_businessDate());
    $remarks    = trim((string)($input['remarks'] ?? '')) ?: null;
    $items      = (array)($input['items'] ?? []);

    if ($deliveryId) {
        $h = $ctx->db()->prepare('SELECT * FROM dl_deliveries WHERE id = :id');
        $h->execute([':id' => $deliveryId]);
        $del = $h->fetch(PDO::FETCH_ASSOC);
        if (!$del) { $ctx->json(['ok' => false, 'error' => 'Delivery not found'], 404); return; }
        $originType = (string)$del['origin_type'];
        $originId   = $del['origin_id'] !== null ? (int)$del['origin_id'] : null;
        $drNumber   = $del['dr_number'] ?: $drNumber;
        if (count($items) === 0) {
            $diStmt = $ctx->db()->prepare('SELECT id, product_id, quantity, unit, price_snapshot FROM dl_delivery_items WHERE delivery_id = :d');
            $diStmt->execute([':d' => $deliveryId]);
            foreach ($diStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $di) {
                $items[] = [
                    'delivery_item_id' => (int)$di['id'],
                    'product_id' => (int)$di['product_id'],
                    'quantity_received' => (int)$di['quantity'],
                    'unit' => $di['unit'],
                    'selling_price_snapshot' => (float)$di['price_snapshot'],
                ];
            }
        }
    }

    $clean = [];
    foreach ($items as $i) {
        if (!is_array($i)) continue;
        $pid = (int)($i['product_id'] ?? 0);
        $qty = (int)($i['quantity_received'] ?? $i['quantity'] ?? 0);
        if ($pid <= 0 || $qty <= 0) continue;
        $clean[] = [
            'delivery_item_id' => isset($i['delivery_item_id']) ? (int)$i['delivery_item_id'] : null,
            'product_id' => $pid,
            'quantity_received' => $qty,
            'unit' => trim((string)($i['unit'] ?? 'pcs')) ?: 'pcs',
            'selling_price_snapshot' => isset($i['selling_price_snapshot']) ? (float)$i['selling_price_snapshot'] : dl_resolveProductPrice($pid, null, $rcvDate),
            'unit_cost_snapshot' => isset($i['unit_cost_snapshot']) ? (float)$i['unit_cost_snapshot'] : null,
            'remarks' => isset($i['remarks']) ? (string)$i['remarks'] : null,
        ];
    }
    if (count($clean) === 0) { $ctx->json(['ok' => false, 'error' => 'No items'], 422); return; }

    $ctx->db()->beginTransaction();
    try {
        $ins = $ctx->db()->prepare(
            'INSERT INTO dl_branch_receivings
                (branch_id, origin_type, origin_id, delivery_id, dr_number,
                 received_by, received_at, received_ledger_date, status, remarks)
             VALUES (:b, :ot, :oid, :did, :dr, :rb, NOW(), :rd, "draft", :rmk)'
        );
        $ins->execute([
            ':b' => $branchId, ':ot' => $originType, ':oid' => $originId,
            ':did' => $deliveryId, ':dr' => $drNumber,
            ':rb' => $userId ?: null, ':rd' => $rcvDate, ':rmk' => $remarks,
        ]);
        $rcvId = (int)$ctx->db()->lastInsertId();

        $insItem = $ctx->db()->prepare(
            'INSERT INTO dl_branch_receiving_items
                (receiving_id, delivery_item_id, product_id, quantity_received, unit,
                 unit_cost_snapshot, selling_price_snapshot, remarks)
             VALUES (:r, :di, :p, :q, :u, :uc, :sp, :rmk)'
        );
        foreach ($clean as $it) {
            $insItem->execute([
                ':r' => $rcvId, ':di' => $it['delivery_item_id'],
                ':p' => $it['product_id'], ':q' => $it['quantity_received'],
                ':u' => $it['unit'], ':uc' => $it['unit_cost_snapshot'],
                ':sp' => $it['selling_price_snapshot'], ':rmk' => $it['remarks'],
            ]);
        }
        $ctx->db()->commit();
        dl_auditLog('receiving_created', $branchId, 'dl_branch_receivings', (string)$rcvId, null,
            ['delivery_id' => $deliveryId, 'items' => count($clean)]);
        $ctx->json(['ok' => true, 'receiving_id' => $rcvId, 'status' => 'draft']);
    } catch (\Throwable $e) {
        $ctx->db()->rollBack();
        $ctx->log('apiCreateReceiving: ' . $e->getMessage(), 'error');
        $ctx->json(['ok' => false, 'error' => 'Database error'], 500);
    }
}

function dl_recordReceivingVariances(int $receivingId): void
{
    $ctx = module();
    if (!$ctx) return;

    $r = $ctx->db()->prepare('SELECT delivery_id FROM dl_branch_receivings WHERE id = :id');
    $r->execute([':id' => $receivingId]);
    $deliveryId = $r->fetchColumn();
    if (!$deliveryId) return;
    $deliveryId = (int)$deliveryId;

    $sql = 'SELECT di.product_id, COALESCE(di.quantity, 0) AS sent_qty,
                   COALESCE(SUM(ri.quantity_received), 0) AS received_qty
              FROM dl_delivery_items di
              LEFT JOIN dl_branch_receiving_items ri
                ON ri.delivery_item_id = di.id AND ri.receiving_id = :r
             WHERE di.delivery_id = :d
             GROUP BY di.id, di.product_id, di.quantity';
    $stmt = $ctx->db()->prepare($sql);
    $stmt->execute([':r' => $receivingId, ':d' => $deliveryId]);
    $upsert = $ctx->db()->prepare(
        'INSERT INTO dl_delivery_variance_flags
            (delivery_id, receiving_id, product_id, sent_qty, received_qty, variance)
         VALUES (:d, :r, :p, :s, :rcv, :v)
         ON DUPLICATE KEY UPDATE
            receiving_id = VALUES(receiving_id),
            sent_qty = VALUES(sent_qty),
            received_qty = VALUES(received_qty),
            variance = VALUES(variance)'
    );
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $sent = (int)$row['sent_qty'];
        $rcv  = (int)$row['received_qty'];
        $var  = $rcv - $sent;
        if ($var === 0) continue;
        $upsert->execute([
            ':d' => $deliveryId, ':r' => $receivingId,
            ':p' => (int)$row['product_id'], ':s' => $sent, ':rcv' => $rcv, ':v' => $var,
        ]);
    }
}

function apiPostReceiving(array $params = []): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); return; }
    if (!dl_isFormalDeliveryEnabled()) {
        $ctx->json(['ok' => false, 'error' => 'Formal Delivery Workflow is disabled.'], 403);
        return;
    }
    $user = dlCurrentUser();
    $userId = (int)($user['sub'] ?? 0);
    $input = (array)json_decode(file_get_contents('php://input'), true);
    $rcvId = (int)($input['receiving_id'] ?? 0);
    if ($rcvId <= 0) { $ctx->json(['ok' => false, 'error' => 'receiving_id required'], 422); return; }

    $ctx->db()->beginTransaction();
    try {
        $stmt = $ctx->db()->prepare('SELECT * FROM dl_branch_receivings WHERE id = :id FOR UPDATE');
        $stmt->execute([':id' => $rcvId]);
        $head = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$head) { throw new \RuntimeException('Receiving not found'); }
        if ($head['status'] !== 'draft') { throw new \RuntimeException('Only draft receivings can be posted'); }

        $branchId = (int)$head['branch_id'];
        $rcvDate  = (string)$head['received_ledger_date'];

        $itemsStmt = $ctx->db()->prepare('SELECT product_id, quantity_received FROM dl_branch_receiving_items WHERE receiving_id = :r');
        $itemsStmt->execute([':r' => $rcvId]);
        $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($items as $it) {
            dl_applyLedgerDelta($branchId, (int)$it['product_id'], $rcvDate, (int)$it['quantity_received'], $userId, 'addtl');
        }

        $ctx->db()->prepare(
            'UPDATE dl_branch_receivings SET status = "posted", posted_by = :u, posted_at = NOW() WHERE id = :id'
        )->execute([':u' => $userId ?: null, ':id' => $rcvId]);

        if (!empty($head['delivery_id'])) {
            dl_recordReceivingVariances($rcvId);
        }

        $ctx->db()->commit();
        dl_auditLog('receiving_posted', $branchId, 'dl_branch_receivings', (string)$rcvId,
            ['status' => 'draft'], ['status' => 'posted']);
        $ctx->json(['ok' => true]);
    } catch (\Throwable $e) {
        $ctx->db()->rollBack();
        $ctx->log('apiPostReceiving: ' . $e->getMessage(), 'error');
        $ctx->json(['ok' => false, 'error' => $e->getMessage()], 400);
    }
}

function apiVoidReceiving(array $params = []): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); return; }
    $user = dlCurrentUser(['admin','supervisor']);
    $userId = (int)($user['sub'] ?? 0);
    $input = (array)json_decode(file_get_contents('php://input'), true);
    $rcvId = (int)($input['receiving_id'] ?? 0);
    $reason = trim((string)($input['reason'] ?? ''));
    if ($rcvId <= 0) { $ctx->json(['ok' => false, 'error' => 'receiving_id required'], 422); return; }

    $ctx->db()->beginTransaction();
    try {
        $stmt = $ctx->db()->prepare('SELECT * FROM dl_branch_receivings WHERE id = :id FOR UPDATE');
        $stmt->execute([':id' => $rcvId]);
        $head = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$head) { throw new \RuntimeException('Receiving not found'); }
        if ($head['status'] === 'voided') { $ctx->db()->commit(); $ctx->json(['ok' => true]); return; }

        if ($head['status'] === 'posted') {
            $branchId = (int)$head['branch_id'];
            $rcvDate  = (string)$head['received_ledger_date'];
            $itemsStmt = $ctx->db()->prepare('SELECT product_id, quantity_received FROM dl_branch_receiving_items WHERE receiving_id = :r');
            $itemsStmt->execute([':r' => $rcvId]);
            foreach ($itemsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $it) {
                dl_applyLedgerDelta($branchId, (int)$it['product_id'], $rcvDate, -((int)$it['quantity_received']), $userId, 'addtl');
            }
        }

        $ctx->db()->prepare(
            'UPDATE dl_branch_receivings SET status = "voided", voided_by = :u, voided_at = NOW() WHERE id = :id'
        )->execute([':u' => $userId ?: null, ':id' => $rcvId]);

        $ctx->db()->commit();
        dl_auditLog('receiving_voided', (int)$head['branch_id'], 'dl_branch_receivings', (string)$rcvId,
            ['status' => $head['status']], ['status' => 'voided'], $reason ?: null);
        $ctx->json(['ok' => true]);
    } catch (\Throwable $e) {
        $ctx->db()->rollBack();
        $ctx->json(['ok' => false, 'error' => $e->getMessage()], 400);
    }
}

function apiListReceivings(array $params = []): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); return; }
    $user = dlCurrentUser();
    $branchId = dl_resolveLedgerBranchId($user, $_GET);
    $status = (string)($_GET['status'] ?? '');

    $where = [];
    $bind  = [];
    if ($branchId > 0) { $where[] = 'branch_id = :b'; $bind[':b'] = $branchId; }
    if ($status !== '') { $where[] = 'status = :s'; $bind[':s'] = $status; }
    $sql = 'SELECT id, branch_id, origin_type, origin_id, delivery_id, dr_number,
                   received_ledger_date, status, posted_at, voided_at, created_at
              FROM dl_branch_receivings'
         . (count($where) ? ' WHERE ' . implode(' AND ', $where) : '')
         . ' ORDER BY received_ledger_date DESC, id DESC LIMIT 200';
    $stmt = $ctx->db()->prepare($sql);
    $stmt->execute($bind);
    $ctx->json(['ok' => true, 'receivings' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []]);
}

// ─── Phase A: Branch product supply rules ────────────────────────────────

function apiBranchProductSupplyRuleUpsert(array $params = []): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); return; }
    $user = dlCurrentUser(['admin','supervisor']);
    $userId = (int)($user['sub'] ?? 0);
    $input = (array)json_decode(file_get_contents('php://input'), true);

    $branchId = (int)($input['branch_id'] ?? 0);
    $productId = (int)($input['product_id'] ?? 0);
    $sourceType = (string)($input['supply_source_type'] ?? '');
    $sourceId = isset($input['source_id']) ? (int)$input['source_id'] : null;
    $isActive = isset($input['is_active']) ? (int)(bool)$input['is_active'] : 1;

    if ($branchId <= 0 || $productId <= 0) { $ctx->json(['ok' => false, 'error' => 'branch_id and product_id required'], 422); return; }
    if (!in_array($sourceType, ['commissary','local_production','direct_purchase','manual'], true)) {
        $ctx->json(['ok' => false, 'error' => 'Invalid supply_source_type'], 422);
        return;
    }

    try {
        $stmt = $ctx->db()->prepare('SELECT id, supply_source_type, source_id FROM dl_branch_product_supply_rules WHERE branch_id = :b AND product_id = :p');
        $stmt->execute([':b' => $branchId, ':p' => $productId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $ctx->db()->prepare(
                'UPDATE dl_branch_product_supply_rules
                    SET supply_source_type = :t, source_id = :sid, is_active = :a
                  WHERE id = :id'
            )->execute([':t' => $sourceType, ':sid' => $sourceId, ':a' => $isActive, ':id' => (int)$existing['id']]);
            dl_auditLog('product_supply_source_changed', $branchId, 'dl_branch_product_supply_rules',
                (string)$existing['id'], $existing,
                ['supply_source_type' => $sourceType, 'source_id' => $sourceId, 'is_active' => $isActive]);
        } else {
            $ctx->db()->prepare(
                'INSERT INTO dl_branch_product_supply_rules
                    (branch_id, product_id, supply_source_type, source_id, is_active, created_by)
                 VALUES (:b, :p, :t, :sid, :a, :u)'
            )->execute([':b' => $branchId, ':p' => $productId, ':t' => $sourceType, ':sid' => $sourceId, ':a' => $isActive, ':u' => $userId ?: null]);
            $newId = (int)$ctx->db()->lastInsertId();
            dl_auditLog('product_supply_source_changed', $branchId, 'dl_branch_product_supply_rules',
                (string)$newId, null,
                ['supply_source_type' => $sourceType, 'source_id' => $sourceId, 'is_active' => $isActive]);
        }
        $ctx->json(['ok' => true]);
    } catch (\Throwable $e) {
        $ctx->log('apiBranchProductSupplyRuleUpsert: ' . $e->getMessage(), 'error');
        $ctx->json(['ok' => false, 'error' => 'Database error'], 500);
    }
}

function apiBranchProductSupplyRuleList(array $params = []): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); return; }
    dlCurrentUser();
    $branchId = (int)($_GET['branch_id'] ?? 0);
    if ($branchId <= 0) { $ctx->json(['ok' => true, 'rules' => []]); return; }

    $stmt = $ctx->db()->prepare(
        'SELECT r.id, r.product_id, p.name AS product_name, r.supply_source_type, r.source_id, r.is_active
           FROM dl_branch_product_supply_rules r
           INNER JOIN dl_products p ON p.id = r.product_id
          WHERE r.branch_id = :b
          ORDER BY p.sort_order, p.name'
    );
    $stmt->execute([':b' => $branchId]);
    $ctx->json(['ok' => true, 'rules' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []]);
}

// ─── Phase D: Price-group handlers ───────────────────────────────────────

function apiPriceGroupList(array $params = []): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); return; }
    dlCurrentUser();
    $stmt = $ctx->db()->query('SELECT id, name, type, is_default, is_active FROM dl_price_groups ORDER BY is_default DESC, name');
    $ctx->json(['ok' => true, 'groups' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []]);
}

function apiPriceGroupCreate(array $params = []): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); return; }
    if (!dl_arePriceGroupsEnabled()) {
        $ctx->json(['ok' => false, 'error' => 'Price Groups feature is disabled.'], 403);
        return;
    }
    dlCurrentUser(['admin']);
    $input = (array)json_decode(file_get_contents('php://input'), true);
    $name = trim((string)($input['name'] ?? ''));
    $type = (string)($input['type'] ?? 'other');
    $allowed = ['branch','mall','reseller','event','wholesale','kiosk','other'];
    if ($name === '') { $ctx->json(['ok' => false, 'error' => 'name required'], 422); return; }
    if (!in_array($type, $allowed, true)) { $ctx->json(['ok' => false, 'error' => 'Invalid type'], 422); return; }

    try {
        $ctx->db()->prepare('INSERT INTO dl_price_groups (name, type) VALUES (:n, :t)')
            ->execute([':n' => $name, ':t' => $type]);
        $id = (int)$ctx->db()->lastInsertId();
        dl_auditLog('price_group_created', null, 'dl_price_groups', (string)$id, null, ['name' => $name, 'type' => $type]);
        $ctx->json(['ok' => true, 'id' => $id]);
    } catch (\Throwable $e) {
        $ctx->json(['ok' => false, 'error' => 'Failed (duplicate name?)'], 409);
    }
}

function apiPriceGroupUpdate(array $params = []): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); return; }
    if (!dl_arePriceGroupsEnabled()) {
        $ctx->json(['ok' => false, 'error' => 'Price Groups feature is disabled.'], 403);
        return;
    }
    dlCurrentUser(['admin']);
    $input = (array)json_decode(file_get_contents('php://input'), true);
    $id = (int)($input['id'] ?? 0);
    $name = trim((string)($input['name'] ?? ''));
    $isActive = isset($input['is_active']) ? (int)(bool)$input['is_active'] : 1;
    if ($id <= 0 || $name === '') { $ctx->json(['ok' => false, 'error' => 'id and name required'], 422); return; }

    try {
        $ctx->db()->prepare('UPDATE dl_price_groups SET name = :n, is_active = :a WHERE id = :id')
            ->execute([':n' => $name, ':a' => $isActive, ':id' => $id]);
        dl_auditLog('price_group_changed', null, 'dl_price_groups', (string)$id, null, ['name' => $name, 'is_active' => $isActive]);
        $ctx->json(['ok' => true]);
    } catch (\Throwable $e) {
        $ctx->json(['ok' => false, 'error' => 'Database error'], 500);
    }
}

function apiProductPriceUpsert(array $params = []): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); return; }
    if (!dl_arePriceGroupsEnabled()) {
        $ctx->json(['ok' => false, 'error' => 'Price Groups feature is disabled.'], 403);
        return;
    }
    $user = dlCurrentUser(['admin']);
    $userId = (int)($user['sub'] ?? 0);
    $input = (array)json_decode(file_get_contents('php://input'), true);
    $productId = (int)($input['product_id'] ?? 0);
    $groupId = (int)($input['price_group_id'] ?? 0);
    $price = (float)($input['selling_price'] ?? 0);
    $effectiveFrom = (string)($input['effective_from'] ?? date('Y-m-d'));
    $effectiveTo = isset($input['effective_to']) && $input['effective_to'] !== '' ? (string)$input['effective_to'] : null;
    if ($productId <= 0 || $groupId <= 0) { $ctx->json(['ok' => false, 'error' => 'product_id and price_group_id required'], 422); return; }

    try {
        $ctx->db()->prepare(
            'INSERT INTO dl_product_prices (product_id, price_group_id, selling_price, effective_from, effective_to, created_by)
             VALUES (:p, :g, :sp, :ef, :et, :u)
             ON DUPLICATE KEY UPDATE selling_price = VALUES(selling_price), effective_to = VALUES(effective_to), is_active = 1'
        )->execute([
            ':p' => $productId, ':g' => $groupId, ':sp' => $price,
            ':ef' => $effectiveFrom, ':et' => $effectiveTo, ':u' => $userId ?: null,
        ]);
        dl_auditLog('product_price_changed', null, 'dl_product_prices', "$productId:$groupId:$effectiveFrom",
            null, ['selling_price' => $price, 'effective_from' => $effectiveFrom, 'effective_to' => $effectiveTo]);
        $ctx->json(['ok' => true]);
    } catch (\Throwable $e) {
        $ctx->json(['ok' => false, 'error' => 'Database error'], 500);
    }
}

function apiProductPriceList(array $params = []): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); return; }
    dlCurrentUser();
    $productId = (int)($_GET['product_id'] ?? 0);
    $groupId = (int)($_GET['price_group_id'] ?? 0);
    $where = ['is_active = 1'];
    $bind = [];
    if ($productId > 0) { $where[] = 'product_id = :p'; $bind[':p'] = $productId; }
    if ($groupId > 0)   { $where[] = 'price_group_id = :g'; $bind[':g'] = $groupId; }
    $sql = 'SELECT id, product_id, price_group_id, selling_price, effective_from, effective_to
              FROM dl_product_prices WHERE ' . implode(' AND ', $where)
         . ' ORDER BY product_id, price_group_id, effective_from DESC';
    $stmt = $ctx->db()->prepare($sql);
    $stmt->execute($bind);
    $ctx->json(['ok' => true, 'prices' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []]);
}

// ─── Phase E: Selling-account handlers ───────────────────────────────────

function apiSellingAccountList(array $params = []): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); return; }
    dlCurrentUser();
    $branchId = (int)($_GET['branch_id'] ?? 0);
    $where = [];
    $bind = [];
    if ($branchId > 0) { $where[] = 'assigned_branch_id = :b'; $bind[':b'] = $branchId; }
    $sql = 'SELECT id, code, name, account_type, assigned_branch_id, supply_source_type, supply_source_id,
                   price_group_id, ledger_type, is_active
              FROM dl_selling_accounts'
         . (count($where) ? ' WHERE ' . implode(' AND ', $where) : '')
         . ' ORDER BY name';
    $stmt = $ctx->db()->prepare($sql);
    $stmt->execute($bind);
    $ctx->json(['ok' => true, 'accounts' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []]);
}

function apiSellingAccountCreate(array $params = []): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); return; }
    if (!dl_areSellingAccountsEnabled()) {
        $ctx->json(['ok' => false, 'error' => 'Selling Accounts feature is disabled.'], 403);
        return;
    }
    dlCurrentUser(['admin']);
    $input = (array)json_decode(file_get_contents('php://input'), true);
    $code = strtoupper(trim((string)($input['code'] ?? '')));
    $name = trim((string)($input['name'] ?? ''));
    $type = (string)($input['account_type'] ?? 'other');
    $branchId = isset($input['assigned_branch_id']) ? (int)$input['assigned_branch_id'] : null;
    $supplyType = (string)($input['supply_source_type'] ?? 'commissary');
    $supplyId = isset($input['supply_source_id']) ? (int)$input['supply_source_id'] : null;
    $priceGroupId = isset($input['price_group_id']) ? (int)$input['price_group_id'] : null;
    $ledgerType = (string)($input['ledger_type'] ?? 'mall_account');

    $allowedTypes = ['mall','kiosk','event_booth','bazaar','school_canteen','reseller','direct_customer','other'];
    $allowedSupply = ['commissary','branch','supplier','manual'];
    if ($code === '' || $name === '') { $ctx->json(['ok' => false, 'error' => 'code and name required'], 422); return; }
    if (!in_array($type, $allowedTypes, true)) { $ctx->json(['ok' => false, 'error' => 'Invalid account_type'], 422); return; }
    if (!in_array($supplyType, $allowedSupply, true)) { $ctx->json(['ok' => false, 'error' => 'Invalid supply_source_type'], 422); return; }

    try {
        $ctx->db()->prepare(
            'INSERT INTO dl_selling_accounts
                (code, name, account_type, assigned_branch_id, supply_source_type, supply_source_id,
                 price_group_id, ledger_type)
             VALUES (:c, :n, :t, :b, :st, :sid, :pg, :lt)'
        )->execute([
            ':c' => $code, ':n' => $name, ':t' => $type, ':b' => $branchId,
            ':st' => $supplyType, ':sid' => $supplyId, ':pg' => $priceGroupId, ':lt' => $ledgerType,
        ]);
        $id = (int)$ctx->db()->lastInsertId();
        dl_auditLog('selling_account_created', $branchId, 'dl_selling_accounts', (string)$id, null,
            ['code' => $code, 'name' => $name, 'account_type' => $type]);
        $ctx->json(['ok' => true, 'id' => $id]);
    } catch (\Throwable $e) {
        $ctx->json(['ok' => false, 'error' => 'Failed (duplicate code?)'], 409);
    }
}

function apiSellingAccountUpdate(array $params = []): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); return; }
    if (!dl_areSellingAccountsEnabled()) {
        $ctx->json(['ok' => false, 'error' => 'Selling Accounts feature is disabled.'], 403);
        return;
    }
    dlCurrentUser(['admin']);
    $input = (array)json_decode(file_get_contents('php://input'), true);
    $id = (int)($input['id'] ?? 0);
    $name = trim((string)($input['name'] ?? ''));
    $type = (string)($input['account_type'] ?? 'other');
    $branchId = isset($input['assigned_branch_id']) ? (int)$input['assigned_branch_id'] : null;
    $supplyType = (string)($input['supply_source_type'] ?? 'commissary');
    $supplyId = isset($input['supply_source_id']) ? (int)$input['supply_source_id'] : null;
    $priceGroupId = isset($input['price_group_id']) ? (int)$input['price_group_id'] : null;
    $isActive = isset($input['is_active']) ? (int)(bool)$input['is_active'] : 1;
    if ($id <= 0 || $name === '') { $ctx->json(['ok' => false, 'error' => 'id and name required'], 422); return; }

    try {
        $ctx->db()->prepare(
            'UPDATE dl_selling_accounts
                SET name = :n, account_type = :t, assigned_branch_id = :b,
                    supply_source_type = :st, supply_source_id = :sid,
                    price_group_id = :pg, is_active = :a
              WHERE id = :id'
        )->execute([
            ':n' => $name, ':t' => $type, ':b' => $branchId,
            ':st' => $supplyType, ':sid' => $supplyId, ':pg' => $priceGroupId,
            ':a' => $isActive, ':id' => $id,
        ]);
        dl_auditLog('selling_account_changed', $branchId, 'dl_selling_accounts', (string)$id, null,
            ['name' => $name, 'account_type' => $type, 'is_active' => $isActive]);
        $ctx->json(['ok' => true]);
    } catch (\Throwable $e) {
        $ctx->json(['ok' => false, 'error' => 'Database error'], 500);
    }
}

function apiSellingAccountLedger(array $params = []): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); return; }
    if (!dl_areSellingAccountsEnabled()) {
        $ctx->json(['ok' => false, 'error' => 'Selling Accounts feature is disabled.'], 403);
        return;
    }
    dlCurrentUser();
    $accId = (int)($_GET['selling_account_id'] ?? 0);
    $date  = (string)($_GET['date'] ?? date('Y-m-d'));
    if ($accId <= 0) { $ctx->json(['ok' => false, 'error' => 'selling_account_id required'], 422); return; }

    $stmt = $ctx->db()->prepare(
        'SELECT l.product_id, p.name AS product_name, l.price_snapshot,
                l.beg_qty, l.delivered_qty, l.return_qty, l.end_qty, l.sold_qty, l.gross_amount
           FROM dl_selling_account_ledger l
           INNER JOIN dl_products p ON p.id = l.product_id
          WHERE l.selling_account_id = :a AND l.ledger_date = :d
          ORDER BY p.sort_order, p.name'
    );
    $stmt->execute([':a' => $accId, ':d' => $date]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $statusStmt = $ctx->db()->prepare('SELECT status FROM dl_selling_account_day_status WHERE selling_account_id = :a AND ledger_date = :d');
    $statusStmt->execute([':a' => $accId, ':d' => $date]);
    $status = (string)($statusStmt->fetchColumn() ?: 'open');

    $ctx->json(['ok' => true, 'rows' => $rows, 'status' => $status, 'ledger_date' => $date]);
}

function apiSellingAccountSaveField(array $params = []): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); return; }
    if (!dl_areSellingAccountsEnabled()) {
        $ctx->json(['ok' => false, 'error' => 'Selling Accounts feature is disabled.'], 403);
        return;
    }
    $user = dlCurrentUser();
    $userId = (int)($user['sub'] ?? 0);
    $input = (array)json_decode(file_get_contents('php://input'), true);
    $accId = (int)($input['selling_account_id'] ?? 0);
    $productId = (int)($input['product_id'] ?? 0);
    $date = (string)($input['date'] ?? date('Y-m-d'));
    $field = (string)($input['field'] ?? '');
    $value = (int)($input['value'] ?? 0);

    $allowed = ['beg_qty','return_qty','end_qty'];
    if ($accId <= 0 || $productId <= 0 || !in_array($field, $allowed, true)) {
        $ctx->json(['ok' => false, 'error' => 'Invalid input'], 422);
        return;
    }

    $statusStmt = $ctx->db()->prepare('SELECT status FROM dl_selling_account_day_status WHERE selling_account_id = :a AND ledger_date = :d');
    $statusStmt->execute([':a' => $accId, ':d' => $date]);
    if ((string)$statusStmt->fetchColumn() === 'closed' && (string)($user['role'] ?? '') === 'cashier') {
        $ctx->json(['ok' => false, 'error' => 'Day is closed'], 403); return;
    }

    $price = dl_resolveProductPrice($productId, null, $date);
    $accStmt = $ctx->db()->prepare('SELECT price_group_id FROM dl_selling_accounts WHERE id = :a');
    $accStmt->execute([':a' => $accId]);
    $accPgId = $accStmt->fetchColumn();
    if ($accPgId !== false && $accPgId !== null) {
        $price = dl_resolveProductPrice($productId, (int)$accPgId, $date);
    }

    $ctx->db()->beginTransaction();
    try {
        $check = $ctx->db()->prepare(
            'SELECT id FROM dl_selling_account_ledger
              WHERE selling_account_id = :a AND product_id = :p AND ledger_date = :d FOR UPDATE'
        );
        $check->execute([':a' => $accId, ':p' => $productId, ':d' => $date]);
        $row = $check->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $ins = $ctx->db()->prepare(
                'INSERT INTO dl_selling_account_ledger
                    (selling_account_id, product_id, ledger_date, price_snapshot,
                     beg_qty, delivered_qty, return_qty, end_qty, encoded_by, updated_by)
                 VALUES (:a, :p, :d, :ps, 0, 0, 0, 0, :u, :u2)'
            );
            $ins->execute([':a' => $accId, ':p' => $productId, ':d' => $date, ':ps' => $price, ':u' => $userId ?: null, ':u2' => $userId ?: null]);
            $check->execute([':a' => $accId, ':p' => $productId, ':d' => $date]);
            $row = $check->fetch(PDO::FETCH_ASSOC);
        }

        $upd = $ctx->db()->prepare(
            "UPDATE dl_selling_account_ledger
                SET {$field} = :v, price_snapshot = :ps, updated_by = :u,
                    sold_qty = beg_qty + delivered_qty - return_qty - end_qty,
                    gross_amount = (beg_qty + delivered_qty - return_qty - end_qty) * :ps2
              WHERE id = :id"
        );
        // Replace target field then recompute sold/gross from latest row.
        $ctx->db()->prepare("UPDATE dl_selling_account_ledger SET {$field} = :v, price_snapshot = :ps, updated_by = :u WHERE id = :id")
            ->execute([':v' => $value, ':ps' => $price, ':u' => $userId ?: null, ':id' => (int)$row['id']]);
        $ctx->db()->prepare(
            'UPDATE dl_selling_account_ledger
                SET sold_qty = beg_qty + delivered_qty - return_qty - end_qty,
                    gross_amount = (beg_qty + delivered_qty - return_qty - end_qty) * price_snapshot
              WHERE id = :id'
        )->execute([':id' => (int)$row['id']]);

        $ctx->db()->commit();
        dl_auditLog('selling_account_ledger_update', null, 'dl_selling_account_ledger',
            $accId . ':' . $productId . ':' . $date, null, [$field => $value]);
        $ctx->json(['ok' => true]);
    } catch (\Throwable $e) {
        $ctx->db()->rollBack();
        $ctx->log('apiSellingAccountSaveField: ' . $e->getMessage(), 'error');
        $ctx->json(['ok' => false, 'error' => 'Database error'], 500);
    }
}

function apiSellingAccountCloseDay(array $params = []): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); return; }
    if (!dl_areSellingAccountsEnabled()) {
        $ctx->json(['ok' => false, 'error' => 'Selling Accounts feature is disabled.'], 403);
        return;
    }
    $user = dlCurrentUser(['supervisor','admin']);
    $userId = (int)($user['sub'] ?? 0);
    $input = (array)json_decode(file_get_contents('php://input'), true);
    $accId = (int)($input['selling_account_id'] ?? 0);
    $date  = (string)($input['date'] ?? date('Y-m-d'));
    if ($accId <= 0) { $ctx->json(['ok' => false, 'error' => 'selling_account_id required'], 422); return; }

    try {
        $ctx->db()->prepare(
            'INSERT INTO dl_selling_account_day_status (selling_account_id, ledger_date, status, closed_by, closed_at)
             VALUES (:a, :d, "closed", :u, NOW())
             ON DUPLICATE KEY UPDATE status = "closed", closed_by = :u2, closed_at = NOW()'
        )->execute([':a' => $accId, ':d' => $date, ':u' => $userId ?: null, ':u2' => $userId ?: null]);
        dl_auditLog('selling_account_day_closed', null, 'dl_selling_account_day_status', "$accId:$date");
        $ctx->json(['ok' => true]);
    } catch (\Throwable $e) {
        $ctx->json(['ok' => false, 'error' => 'Database error'], 500);
    }
}

function apiSellingAccountReopenDay(array $params = []): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); return; }
    if (!dl_areSellingAccountsEnabled()) {
        $ctx->json(['ok' => false, 'error' => 'Selling Accounts feature is disabled.'], 403);
        return;
    }
    $user = dlCurrentUser(['admin']);
    $userId = (int)($user['sub'] ?? 0);
    $input = (array)json_decode(file_get_contents('php://input'), true);
    $accId = (int)($input['selling_account_id'] ?? 0);
    $date  = (string)($input['date'] ?? date('Y-m-d'));
    if ($accId <= 0) { $ctx->json(['ok' => false, 'error' => 'selling_account_id required'], 422); return; }

    try {
        $ctx->db()->prepare(
            'UPDATE dl_selling_account_day_status
                SET status = "open", reopened_by = :u, reopened_at = NOW()
              WHERE selling_account_id = :a AND ledger_date = :d'
        )->execute([':u' => $userId ?: null, ':a' => $accId, ':d' => $date]);
        dl_auditLog('selling_account_day_reopened', null, 'dl_selling_account_day_status', "$accId:$date");
        $ctx->json(['ok' => true]);
    } catch (\Throwable $e) {
        $ctx->json(['ok' => false, 'error' => 'Database error'], 500);
    }
}

// ─── Phase F: Branch consolidated summary ────────────────────────────────

function dl_branchConsolidatedSummary(int $branchId, string $date): array
{
    $ctx = module();
    if (!$ctx) {
        return ['regular_sales' => 0.0, 'regular_qty' => 0, 'selling_accounts' => [], 'total_sales' => 0.0];
    }

    $regStmt = $ctx->db()->prepare(
        'SELECT COALESCE(SUM(sales),0) AS qty, COALESCE(SUM(sales * price_snapshot),0) AS amt
           FROM dl_daily_ledger WHERE branch_id = :b AND ledger_date = :d'
    );
    $regStmt->execute([':b' => $branchId, ':d' => $date]);
    $reg = $regStmt->fetch(PDO::FETCH_ASSOC) ?: ['qty' => 0, 'amt' => 0];

    if (!dl_areSellingAccountsEnabled()) {
        return [
            'branch_id' => $branchId,
            'date' => $date,
            'regular_sales' => (float)$reg['amt'],
            'regular_qty' => (int)$reg['qty'],
            'selling_accounts' => [],
            'selling_accounts_total' => 0.0,
            'total_sales' => (float)$reg['amt'],
        ];
    }

    $accStmt = $ctx->db()->prepare(
        'SELECT a.id, a.name, a.account_type,
                COALESCE(SUM(l.sold_qty), 0) AS sold_qty,
                COALESCE(SUM(l.gross_amount), 0) AS gross_amount
           FROM dl_selling_accounts a
           LEFT JOIN dl_selling_account_ledger l
             ON l.selling_account_id = a.id AND l.ledger_date = :d
          WHERE a.assigned_branch_id = :b AND a.is_active = 1
          GROUP BY a.id, a.name, a.account_type
          ORDER BY a.name'
    );
    $accStmt->execute([':b' => $branchId, ':d' => $date]);
    $accounts = $accStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $accountsTotal = 0.0;
    foreach ($accounts as $a) {
        $accountsTotal += (float)$a['gross_amount'];
    }

    return [
        'branch_id' => $branchId,
        'date' => $date,
        'regular_sales' => (float)$reg['amt'],
        'regular_qty' => (int)$reg['qty'],
        'selling_accounts' => $accounts,
        'selling_accounts_total' => $accountsTotal,
        'total_sales' => (float)$reg['amt'] + $accountsTotal,
    ];
}

function apiBranchConsolidatedSummary(array $params = []): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); return; }
    $user = dlCurrentUser();
    $branchId = dl_resolveLedgerBranchId($user, $_GET);
    $date = (string)($_GET['date'] ?? date('Y-m-d'));
    if ($branchId <= 0) { $ctx->json(['ok' => false, 'error' => 'branch_id required'], 422); return; }
    $ctx->json(['ok' => true, 'summary' => dl_branchConsolidatedSummary($branchId, $date)]);
}

// ─── Admin Page Handlers (Phase B/D/E UI) ─────────────────────────────

function dl_layoutFlags(): array
{
    $s = dlModuleSettings();
    return [
        'feature_formal_delivery'   => dl_settingToBool($s['formal_delivery_workflow_enabled'] ?? false),
        'feature_selling_accounts'  => dl_settingToBool($s['selling_accounts_enabled'] ?? false),
        'feature_price_groups'      => dl_settingToBool($s['price_groups_enabled'] ?? true),
    ];
}

function handleAdminDeliveries(array $params = []): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); echo 'Module context unavailable'; return; }
    $user = dlCurrentUser(['admin', 'supervisor']);

    $branches = $ctx->db()->query('SELECT id, code, name, is_commissary FROM dl_branches WHERE is_active = 1 ORDER BY name')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $accounts = [];
    if (dl_areSellingAccountsEnabled()) {
        $accounts = $ctx->db()->query('SELECT id, name, account_type, assigned_branch_id FROM dl_selling_accounts WHERE is_active = 1 ORDER BY name')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
    $products = $ctx->db()->query('SELECT id, sku, name FROM dl_products WHERE is_active = 1 ORDER BY name LIMIT 500')->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $role = (string)($user['role'] ?? '');
    $userName = (string)($user['name'] ?? $user['full_name'] ?? $user['username'] ?? 'User');

    echo dlRender('modules/daily-ledger/admin/deliveries.disyl', array_merge(dl_layoutFlags(), [
        'page_title'   => 'Deliveries',
        'user_name'    => $userName,
        'user_role'    => $role,
        'current_page' => 'deliveries',
        'base_url'     => dlGetBaseUrl(),
        'dl_token'     => (string)kernelCookie(dlCookieName(), ''),
        'branches'     => $branches,
        'selling_accounts' => $accounts,
        'products'     => $products,
        'formal_delivery_enabled' => dl_isFormalDeliveryEnabled(),
    ]));
}

function handleAdminPriceGroups(array $params = []): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); echo 'Module context unavailable'; return; }
    $user = dlCurrentUser(['admin']);
    $baseUrl = dlGetBaseUrl();

    $groups = $ctx->db()->query(
        'SELECT pg.id,
                pg.name,
                pg.type,
                pg.is_default,
                pg.is_active,
                pg.created_at,
                COALESCE(branch_usage.branch_count, 0) AS branch_count,
                COALESCE(account_usage.selling_account_count, 0) AS selling_account_count
             FROM dl_price_groups pg
             LEFT JOIN (
                SELECT price_group_id, COUNT(*) AS branch_count
                  FROM dl_branches
                 WHERE price_group_id IS NOT NULL AND is_active = 1
                 GROUP BY price_group_id
             ) AS branch_usage ON branch_usage.price_group_id = pg.id
             LEFT JOIN (
                SELECT price_group_id, COUNT(*) AS selling_account_count
                  FROM dl_selling_accounts
                 WHERE price_group_id IS NOT NULL AND is_active = 1
                 GROUP BY price_group_id
             ) AS account_usage ON account_usage.price_group_id = pg.id
            ORDER BY pg.is_default DESC, pg.name'
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $branchAssignments = [];
    $branchRows = $ctx->db()->query(
        'SELECT id, price_group_id, code, name
           FROM dl_branches
          WHERE price_group_id IS NOT NULL AND is_active = 1
          ORDER BY name'
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($branchRows as $row) {
        $groupId = (int)($row['price_group_id'] ?? 0);
        if ($groupId <= 0) {
            continue;
        }
        $searchToken = trim((string)($row['code'] ?? '')) !== ''
            ? (string)$row['code']
            : (string)$row['name'];
        $branchAssignments[$groupId][] = [
            'label' => trim((string)($row['code'] ?? '')) !== ''
                ? (string)$row['code'] . ' - ' . (string)$row['name']
                : (string)$row['name'],
            'url' => $baseUrl . '/admin/branches?price_group_id=' . $groupId . '&q=' . rawurlencode($searchToken),
        ];
    }

    $sellingAccountAssignments = [];
    $accountRows = $ctx->db()->query(
        'SELECT id, price_group_id, code, name
           FROM dl_selling_accounts
          WHERE price_group_id IS NOT NULL AND is_active = 1
          ORDER BY name'
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($accountRows as $row) {
        $groupId = (int)($row['price_group_id'] ?? 0);
        if ($groupId <= 0) {
            continue;
        }
        $searchToken = trim((string)($row['code'] ?? '')) !== ''
            ? (string)$row['code']
            : (string)$row['name'];
        $sellingAccountAssignments[$groupId][] = [
            'label' => trim((string)($row['code'] ?? '')) !== ''
                ? (string)$row['code'] . ' - ' . (string)$row['name']
                : (string)$row['name'],
            'url' => $baseUrl . '/admin/selling-accounts?price_group_id=' . $groupId . '&q=' . rawurlencode($searchToken),
        ];
    }

    foreach ($groups as &$group) {
        $groupId = (int)($group['id'] ?? 0);
        $group['branch_assignments_json'] = json_encode(
            array_values($branchAssignments[$groupId] ?? []),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ) ?: '[]';
        $group['selling_account_assignments_json'] = json_encode(
            array_values($sellingAccountAssignments[$groupId] ?? []),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ) ?: '[]';
    }
    unset($group);

    $products = $ctx->db()->query('SELECT id, sku, name FROM dl_products WHERE is_active = 1 ORDER BY name')->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $role = (string)($user['role'] ?? '');
    $userName = (string)($user['name'] ?? $user['full_name'] ?? $user['username'] ?? 'User');

    echo dlRender('modules/daily-ledger/admin/price-groups.disyl', array_merge(dl_layoutFlags(), [
        'page_title'   => 'Price Groups',
        'user_name'    => $userName,
        'user_role'    => $role,
        'current_page' => 'price-groups',
        'base_url'     => dlGetBaseUrl(),
        'dl_token'     => (string)kernelCookie(dlCookieName(), ''),
        'price_groups' => $groups,
        'products'     => $products,
    ]));
}

function handleAdminSellingAccounts(array $params = []): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); echo 'Module context unavailable'; return; }
    $user = dlCurrentUser(['admin', 'supervisor']);

    $input = $ctx->input();
    $search = trim((string)($input['q'] ?? ''));
    $selectedPriceGroupId = isset($input['price_group_id']) && $input['price_group_id'] !== ''
        ? (int)$input['price_group_id'] : 0;

    $sql =
        'SELECT a.id, a.code, a.name, a.account_type, a.assigned_branch_id, a.supply_source_type,
                a.supply_source_id, a.price_group_id, a.ledger_type, a.is_active, a.created_at,
                b.name AS branch_name, pg.name AS price_group_name
           FROM dl_selling_accounts a
           LEFT JOIN dl_branches b ON b.id = a.assigned_branch_id
           LEFT JOIN dl_price_groups pg ON pg.id = a.price_group_id
          WHERE 1=1';
    $bind = [];
    if ($selectedPriceGroupId > 0) {
        $sql .= ' AND a.price_group_id = :price_group_id';
        $bind[':price_group_id'] = $selectedPriceGroupId;
    }
    if ($search !== '') {
        $sql .= ' AND (a.name LIKE :q OR a.code LIKE :q2)';
        $bind[':q'] = "%{$search}%";
        $bind[':q2'] = "%{$search}%";
    }
    $sql .= ' ORDER BY a.is_active DESC, a.name';
    $stmt = $ctx->db()->prepare($sql);
    $stmt->execute($bind);
    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $branches = $ctx->db()->query('SELECT id, code, name FROM dl_branches WHERE is_active = 1 ORDER BY name')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $priceGroups = $ctx->db()->query('SELECT id, name, is_default FROM dl_price_groups ORDER BY is_default DESC, name')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $selectedPriceGroupName = null;
    foreach ($priceGroups as $priceGroup) {
        if ((int)($priceGroup['id'] ?? 0) === $selectedPriceGroupId) {
            $selectedPriceGroupName = (string)($priceGroup['name'] ?? '');
            break;
        }
    }

    $role = (string)($user['role'] ?? '');
    $userName = (string)($user['name'] ?? $user['full_name'] ?? $user['username'] ?? 'User');

    echo dlRender('modules/daily-ledger/admin/selling-accounts.disyl', array_merge(dl_layoutFlags(), [
        'page_title'   => 'Selling Accounts',
        'user_name'    => $userName,
        'user_role'    => $role,
        'current_page' => 'selling-accounts',
        'base_url'     => dlGetBaseUrl(),
        'dl_token'     => (string)kernelCookie(dlCookieName(), ''),
        'accounts'     => $accounts,
        'branches'     => $branches,
        'price_groups' => $priceGroups,
        'selected_price_group_id' => $selectedPriceGroupId,
        'selected_price_group_name' => $selectedPriceGroupName,
        'search' => $search,
        'selling_accounts_enabled' => dl_areSellingAccountsEnabled(),
    ]));
}
