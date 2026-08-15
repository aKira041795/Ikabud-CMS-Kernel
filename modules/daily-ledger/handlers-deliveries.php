<?php

declare(strict_types=1);

/**
 * Daily Ledger — Supply / Pricing / Delivery / Receiving helpers + handlers.
 *
 * Implements Phases A–D,F of the Daily Ledger inventory spec:
 *   A. Branch supply mode + per-product supply rules
 *   B. Formal deliveries + branch receivings with draft/posted/voided status
 *   C. Reason-coded cashier withdrawals  (handled in apiSaveCashierWithdrawals)
 *   D. Price groups + product_prices (with effective windows)
 *   F. Branch consolidated summary + delivery variance flags
 *
 * Note: Phase E (Selling accounts) removed — see commit cc5f07e.
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

/**
 * Canonical same-location internal-release eligibility resolver.
 *
 * A branch is eligible to release its own production into its own storefront
 * ledger WITHOUT a Delivery Receipt (internal release) when ALL of the
 * following are server-verified (never browser-supplied):
 *
 *   1. The destination branch exists and is active (is_active = 1).
 *   2. The destination branch is a commissary (is_commissary = 1) — i.e. the
 *      branch both produces and retails on the same location.
 *   3. The product's supply source resolves to local production for that
 *      branch (branch default self_managed, or a per-product local_production
 *      override), and does NOT resolve to a distinct supplying commissary.
 *      A self-referencing assigned commissary (assigned_commissary_id == self)
 *      and "no assignment" are both accepted canonical configurations.
 *
 * Returns a structured decision:
 *   - same_location      : bool  — true when the DR may be omitted.
 *   - source_branch_id   : ?int  — the producing commissary branch (the branch
 *                                  itself for same-location releases).
 *   - reason             : ?string — rejection reason when not eligible.
 *   - branch             : ?array — raw branch row (presentation metadata).
 *   - supply             : ?array — resolved supply decision.
 */
function dl_resolveSameLocationEligibility(int $destinationBranchId, int $productId): array
{
    $decision = [
        'same_location'    => false,
        'source_branch_id' => null,
        'reason'           => null,
        'branch'           => null,
        'supply'           => null,
    ];

    if ($destinationBranchId <= 0 || $productId <= 0) {
        $decision['reason'] = 'missing_destination_or_product';
        return $decision;
    }

    $ctx = module();
    if (!$ctx) {
        $decision['reason'] = 'module_context_unavailable';
        return $decision;
    }

    $stmt = $ctx->db()->prepare(
        'SELECT id, name, is_active, is_commissary, default_supply_mode, assigned_commissary_id
           FROM dl_branches
          WHERE id = :id
          LIMIT 1'
    );
    $stmt->execute([':id' => $destinationBranchId]);
    $branch = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if (!$branch) {
        $decision['reason'] = 'branch_not_found';
        return $decision;
    }
    $decision['branch'] = $branch;

    if ((int)($branch['is_active'] ?? 0) !== 1) {
        $decision['reason'] = 'branch_inactive';
        return $decision;
    }
    if ((int)($branch['is_commissary'] ?? 0) !== 1) {
        $decision['reason'] = 'not_commissary';
        return $decision;
    }

    $supply = dl_resolveProductSupplySource($destinationBranchId, $productId);
    $decision['supply'] = $supply;

    $source = (string)($supply['source'] ?? '');
    $sourceId = isset($supply['source_id']) && $supply['source_id'] !== null
        ? (int)$supply['source_id']
        : null;

    if ($source === 'local_production') {
        // Self-managed. Accept "no assignment" or a self-referencing assignment.
        if ($sourceId !== null && $sourceId !== $destinationBranchId) {
            $decision['reason'] = 'distinct_supplying_commissary';
            return $decision;
        }
        $decision['same_location'] = true;
        $decision['source_branch_id'] = $destinationBranchId;
        return $decision;
    }

    if ($source === 'commissary' && $sourceId !== null && $sourceId === $destinationBranchId) {
        // Self-referencing assigned commissary (co-located config).
        $decision['same_location'] = true;
        $decision['source_branch_id'] = $destinationBranchId;
        return $decision;
    }

    $decision['reason'] = $source === 'commissary' ? 'supplied_by_distinct_commissary' : 'supplied_externally';
    return $decision;
}

/**
 * Batch same-location eligibility map for one branch across many products.
 *
 * Produces the same decisions as dl_resolveSameLocationEligibility() per
 * product, but loads the branch row + all per-product supply rules ONCE so the
 * Usage page does not issue N×3 queries when rendering the product grid.
 *
 * Returns ['branch_id', 'branch' => ?array, 'eligible' => bool, 'products' => [pid => bool]].
 */
function dl_buildSameLocationEligibilityMap(\Ikabud\Kernel\Contracts\DatabaseContract $db, int $branchId, array $productIds): array
{
    $map = ['branch_id' => $branchId, 'branch' => null, 'eligible' => false, 'products' => []];
    $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds), static fn($id): bool => $id > 0)));
    if ($branchId <= 0 || $productIds === []) {
        return $map;
    }

    $bStmt = $db->prepare(
        'SELECT id, name, is_active, is_commissary, default_supply_mode, assigned_commissary_id
           FROM dl_branches WHERE id = :id LIMIT 1'
    );
    $bStmt->execute([':id' => $branchId]);
    $branch = $bStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$branch || (int)($branch['is_active'] ?? 0) !== 1 || (int)($branch['is_commissary'] ?? 0) !== 1) {
        foreach ($productIds as $pid) {
            $map['products'][$pid] = false;
        }
        return $map;
    }
    $map['branch'] = [
        'id' => (int)$branch['id'],
        'name' => (string)$branch['name'],
        'is_active' => true,
        'is_commissary' => true,
        'default_supply_mode' => (string)($branch['default_supply_mode'] ?? ''),
    ];

    // Load all active per-product supply rules for the branch in one query.
    $rules = [];
    $pPlaceholders = implode(',', array_fill(0, count($productIds), '?'));
    $rStmt = $db->prepare(
        "SELECT product_id, supply_source_type, source_id
           FROM dl_branch_product_supply_rules
          WHERE branch_id = ? AND product_id IN ({$pPlaceholders}) AND is_active = 1"
    );
    $rStmt->execute(array_merge([$branchId], $productIds));
    foreach ($rStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $rules[(int)$row['product_id']] = [
            'source' => (string)$row['supply_source_type'],
            'source_id' => $row['source_id'] !== null ? (int)$row['source_id'] : null,
        ];
    }

    $mode = (string)($branch['default_supply_mode'] ?? '');
    $assigned = $branch['assigned_commissary_id'] !== null ? (int)$branch['assigned_commissary_id'] : null;
    $defaultSource = match ($mode) {
        'commissary_supplied' => 'commissary',
        'self_managed'        => 'local_production',
        'hybrid'              => 'commissary',
        default               => 'manual',
    };

    foreach ($productIds as $pid) {
        $rule = $rules[$pid] ?? null;
        $source = $rule ? $rule['source'] : $defaultSource;
        $sourceId = $rule ? $rule['source_id'] : $assigned;

        $ok = false;
        if ($source === 'local_production') {
            $ok = ($sourceId === null || $sourceId === $branchId);
        } elseif ($source === 'commissary' && $sourceId !== null && $sourceId === $branchId) {
            $ok = true;
        }
        $map['products'][$pid] = $ok;
        if ($ok) {
            $map['eligible'] = true;
        }
    }

    return $map;
}

// ─── Phase B: Delivery + Receiving handlers ───────────────────────────────

function dl_deliveryBranchAuthorized(array $user, string $type, ?int $branchId): bool
{
    if (!in_array($type, ['branch', 'commissary'], true)) {
        return true;
    }
    if ($branchId === null || $branchId <= 0) {
        return false;
    }
    return in_array($branchId, dl_accessibleBranchIds($user), true);
}

function dl_deliveryRecordAuthorized(array $user, array $delivery): bool
{
    $originType = (string)($delivery['origin_type'] ?? '');
    $destinationType = (string)($delivery['destination_type'] ?? '');
    $hasBranchScope = in_array($originType, ['branch', 'commissary'], true)
        || $destinationType === 'branch';

    if (!$hasBranchScope && (string)($user['role'] ?? '') !== 'admin') {
        return false;
    }

    return dl_deliveryBranchAuthorized(
        $user,
        $originType,
        isset($delivery['origin_id']) ? (int)$delivery['origin_id'] : null
    ) && dl_deliveryBranchAuthorized(
        $user,
        $destinationType,
        isset($delivery['destination_id']) ? (int)$delivery['destination_id'] : null
    );
}

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

function dl_cashierDispatchRemark(): string
{
    return '[cashier-dispatch]';
}

function dl_paperDrCaptureRemark(): string
{
    return '[captured-from-paper-dr]';
}

function dl_isPaperDrCapturedDelivery(array $delivery): bool
{
    return (string)($delivery['remarks'] ?? '') === dl_paperDrCaptureRemark();
}

function dl_findPaperCapturedCommissaryDelivery(
    \Ikabud\Kernel\Contracts\DatabaseContract $db,
    int $branchId,
    string $deliveryDate,
    string $drNumber
): ?array {
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
        ':remarks' => dl_paperDrCaptureRemark(),
    ]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
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
    $existingAuto = dl_findAutoCommissaryDelivery($db, $branchId, $deliveryDate, $drNumber);
    $existingPaper = dl_findPaperCapturedCommissaryDelivery($db, $branchId, $deliveryDate, $drNumber);

    if ($desiredItems === []) {
        if ($existingAuto) {
            $deliveryId = (int)$existingAuto['id'];
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
                'status' => $existingAuto['status'] ?? 'posted',
                'dr_number' => $drNumber,
            ], [
                'status' => 'voided',
                'dr_number' => $drNumber,
                'source' => 'auto_commissary_run',
            ]);
        }
        if ($existingPaper) {
            return (int)$existingPaper['id'];
        }
        return null;
    }

    if ($existingPaper) {
        $deliveryId = (int)$existingPaper['id'];
        if (!dl_deliveryItemsMatchDesired($db, $deliveryId, $desiredItems)) {
            throw new RuntimeException('Paper DR delivery already exists with different items. Review and reconcile the exception instead of creating a duplicate delivery.');
        }
        return $deliveryId;
    }

    $deliveryId = 0;
    if ($existingAuto) {
        $deliveryId = (int)$existingAuto['id'];
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
        // Resolve commissary for proper origin attribution
        $commissaryBranchId = null;
        foreach ($desiredItems as $item) {
            $supply = dl_resolveProductSupplySource($branchId, (int)$item['product_id']);
            if ($supply['source'] === 'commissary' && $supply['source_id'] !== null) {
                $commissaryBranchId = (int)$supply['source_id'];
                break; // use the first commissary found — all items should share the same supply source
            }
        }

        $stmt = $db->prepare(
            'INSERT INTO dl_deliveries
                (origin_type, origin_id, destination_type, destination_id, dr_number,
                 delivery_date, status, created_by, posted_by, posted_at, remarks)
             VALUES (:origin_type, :origin_id, :destination_type, :destination_id, :dr_number,
                     :delivery_date, "posted", :created_by, :posted_by, NOW(), :remarks)'
        );
        $stmt->execute([
            ':origin_type' => 'commissary',
            ':origin_id' => $commissaryBranchId,
            ':destination_type' => 'branch',
            ':destination_id' => $branchId,
            ':dr_number' => $drNumber,
            ':delivery_date' => $deliveryDate,
            ':created_by' => $actorId > 0 ? $actorId : null,
            ':posted_by' => $actorId > 0 ? $actorId : null,
            ':remarks' => dl_autoCommissaryDeliveryRemark(),
        ]);
        $deliveryId = (int)$db->lastInsertId();

        // Credit commissary production and debit dispatch for each item
        if ($commissaryBranchId !== null) {
            foreach ($desiredItems as $item) {
                $pid = (int)$item['product_id'];
                $qty = (int)$item['quantity'];
                dl_applyCommissaryProductLedgerDelta($db, $commissaryBranchId, $pid, $deliveryDate, $qty, $qty, $actorId);
            }
        }

        dl_auditLog('create_delivery', $branchId, 'dl_deliveries', (string)$deliveryId, null, [
            'dr_number' => $drNumber,
            'status' => 'posted',
            'source' => 'auto_commissary_run',
            'items' => count($desiredItems),
            'commissary_branch_id' => $commissaryBranchId,
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

function dl_acceptFormalDelivery(\Ikabud\Kernel\Contracts\DatabaseContract $db, int $branchId, int $deliveryId, int $userId, string $receiveDate, ?array $partialQtys = null, string $shift = 'AM'): int
{
    $shift = ($shift === 'PM') ? 'PM' : 'AM';
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
            dl_applyLedgerDelta($branchId, $pid, $receiveDate, $quantity, $userId, 'addtl', $shift);
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

function apiCreateDelivery(array $params = []): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); return; }
    $user = dlCurrentUser(['admin', 'supervisor']);
    $userId = dl_getActorUserId($user);
    $input = (array)json_decode(file_get_contents('php://input'), true);
    $idempotencyKey = trim((string)($input['idempotency_key'] ?? ''));

    $originType = (string)($input['origin_type'] ?? '');
    $originId   = isset($input['origin_id']) ? (int)$input['origin_id'] : null;
    $destType   = (string)($input['destination_type'] ?? '');
    $destId     = isset($input['destination_id']) ? (int)$input['destination_id'] : null;
    $workflowMode = trim((string)($input['workflow_mode'] ?? ''));
    $recoveryReason = trim((string)($input['recovery_reason'] ?? ''));
    $drNumber   = trim((string)($input['dr_number'] ?? '')) ?: null;
    $delivDate  = (string)($input['delivery_date'] ?? date('Y-m-d'));
    $remarks    = trim((string)($input['remarks'] ?? '')) ?: null;
    $items      = dl_normalizeDeliveryItems((array)($input['items'] ?? []));

    $allowedOrigins = ['commissary','branch','supplier','manual'];
    $allowedDests   = array_values(array_map(static fn(array $row): string => (string)$row['value'], dlDeliveryDestinationTypeOptions()));
    if (!in_array($originType, $allowedOrigins, true) || !in_array($destType, $allowedDests, true)) {
        $ctx->json(['ok' => false, 'error' => 'Invalid origin or destination type'], 422);
        return;
    }
    if (!dl_deliveryBranchAuthorized($user, $originType, $originId)
        || !dl_deliveryBranchAuthorized($user, $destType, $destId)) {
        $ctx->json(['ok' => false, 'error' => 'Branch not authorized'], 403);
        return;
    }
    if (!in_array($originType, ['branch', 'commissary'], true)
        && $destType !== 'branch'
        && (string)($user['role'] ?? '') !== 'admin') {
        $ctx->json(['ok' => false, 'error' => 'Branch-scoped delivery required'], 403);
        return;
    }
    $idempotencyScope = 'create_delivery:' . $userId;
    if ($idempotencyKey !== '') {
        $cachedResponse = dl_loadIdempotentResponse($idempotencyScope, $idempotencyKey);
        if (is_array($cachedResponse)) {
            $ctx->json($cachedResponse);
            return;
        }
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
    if ($destType === 'branch' && $workflowMode !== 'exception_recovery') {
        $ctx->json(['ok' => false, 'error' => 'Branch deliveries should normally be encoded from Send to Branch and Receive Stock. Use this admin page only for recovery or special cases.'], 422);
        return;
    }
    if ($destType === 'branch' && $recoveryReason === '') {
        $ctx->json(['ok' => false, 'error' => 'Explain why this branch delivery is being recorded here so the recovery trail stays clear.'], 422);
        return;
    }
    if ($destType === 'branch') {
        $remarks = $remarks !== null && $remarks !== ''
            ? 'Admin recovery reason: ' . $recoveryReason . "\n" . $remarks
            : 'Admin recovery reason: ' . $recoveryReason;
    }

    $priceGroupId = null;
    if (dl_arePriceGroupsEnabled() && $priceGroupId === null) {
        $priceGroupId = $destType === 'branch' && $destId ? dl_branchPriceGroupId($destId) : dl_defaultPriceGroupId();
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
            [
                'destination_type' => $destType,
                'destination_id' => $destId,
                'items' => count($items),
                'workflow_mode' => $workflowMode !== '' ? $workflowMode : 'direct_record',
                'recovery_reason' => $recoveryReason !== '' ? $recoveryReason : null,
            ]);
        $response = ['ok' => true, 'delivery_id' => $deliveryId, 'status' => 'draft'];
        dl_storeIdempotentResponse($idempotencyScope, $idempotencyKey, $response);
        $ctx->json($response);
    } catch (\Throwable $e) {
        if ($ctx->db()->inTransaction()) {
            $ctx->db()->rollBack();
        }
        $ctx->log('apiCreateDelivery: ' . $e->getMessage(), 'error');
        $ctx->json(['ok' => false, 'error' => 'Database error'], 500);
    }
}

function apiPostDelivery(array $params = []): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); return; }
    $user = dlCurrentUser(['admin', 'supervisor']);
    $userId = dl_getActorUserId($user);
    $input = (array)json_decode(file_get_contents('php://input'), true);
    $deliveryId = (int)($input['delivery_id'] ?? 0);
    if ($deliveryId <= 0) { $ctx->json(['ok' => false, 'error' => 'delivery_id required'], 422); return; }

    $ctx->db()->beginTransaction();
    try {
        $stmt = $ctx->db()->prepare('SELECT * FROM dl_deliveries WHERE id = :id FOR UPDATE');
        $stmt->execute([':id' => $deliveryId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) { throw new \RuntimeException('Delivery not found'); }
        if (!dl_deliveryRecordAuthorized($user, $row)) {
            $ctx->db()->rollBack();
            $ctx->json(['ok' => false, 'error' => 'Branch not authorized'], 403);
            return;
        }
        if ($row['status'] !== 'draft') { throw new \RuntimeException('Only draft deliveries can be posted'); }
        if ((string)$row['destination_type'] === 'branch' && !dl_isFormalDeliveryEnabled()) {
            throw new \RuntimeException('Formal Delivery Workflow is disabled for branch deliveries.');
        }
        $priceGroupId = null;
        if ((string)$row['destination_type'] === 'branch' && (int)$row['destination_id'] > 0) {
            $priceGroupId = dl_branchPriceGroupId((int)$row['destination_id']);
        }
        if ($priceGroupId === null && dl_arePriceGroupsEnabled()) {
            $priceGroupId = dl_defaultPriceGroupId();
        }

        $itemStmt = $ctx->db()->prepare('SELECT id, product_id FROM dl_delivery_items WHERE delivery_id = :id');
        $itemStmt->execute([':id' => $deliveryId]);
        $itemUpdate = $ctx->db()->prepare(
            'UPDATE dl_delivery_items
                SET price_snapshot = :price_snapshot,
                    price_group_id = :price_group_id
              WHERE id = :id'
        );
        foreach ($itemStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $item) {
            $freshPrice = dl_resolveProductPrice((int)$item['product_id'], $priceGroupId, (string)$row['delivery_date']);
            $itemUpdate->execute([
                ':price_snapshot' => $freshPrice,
                ':price_group_id' => $priceGroupId,
                ':id' => (int)$item['id'],
            ]);
        }

        $postStmt = $ctx->db()->prepare(
            'UPDATE dl_deliveries
                SET status = "posted", posted_by = :u, posted_at = NOW()
              WHERE id = :id
                AND status = "draft"
                AND NOT EXISTS (
                    SELECT 1
                      FROM dl_branch_receivings br
                     WHERE br.delivery_id = :receiving_delivery_id
                       AND br.status <> "voided"
                )'
        );
        $postStmt->execute([
            ':u' => $userId > 0 ? $userId : null,
            ':id' => $deliveryId,
            ':receiving_delivery_id' => $deliveryId,
        ]);
        if ($postStmt->rowCount() !== 1) {
            throw new \RuntimeException('Delivery cannot be posted because it is no longer draft or already has an active receiving.');
        }

        $ctx->db()->commit();
        dl_auditLog('delivery_posted', $row['origin_type'] === 'branch' ? (int)$row['origin_id'] : null,
            'dl_deliveries', (string)$deliveryId, ['status' => 'draft'], ['status' => 'posted']);
        $ctx->json(['ok' => true]);
    } catch (\Throwable $e) {
        $ctx->db()->rollBack();
        $ctx->log('apiPostDelivery: ' . $e->getMessage(), 'error', [
            'delivery_id' => $deliveryId,
            'user_sub' => $user['sub'] ?? null,
        ]);
        $ctx->json(['ok' => false, 'error' => $e->getMessage()], 400);
    }
}

function apiVoidDelivery(array $params = []): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); return; }
    $user = dlCurrentUser(['admin','supervisor']);
    $userId = dl_getActorUserId($user);
    $input = (array)json_decode(file_get_contents('php://input'), true);
    $deliveryId = (int)($input['delivery_id'] ?? 0);
    $reason = trim((string)($input['reason'] ?? ''));
    if ($deliveryId <= 0) { $ctx->json(['ok' => false, 'error' => 'delivery_id required'], 422); return; }

    $ctx->db()->beginTransaction();
    try {
        $stmt = $ctx->db()->prepare('SELECT id, origin_type, origin_id, destination_type, destination_id, delivery_date, remarks, status FROM dl_deliveries WHERE id = :id FOR UPDATE');
        $stmt->execute([':id' => $deliveryId]);
        $delivery = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$delivery) {
            $ctx->db()->rollBack();
            $ctx->json(['ok' => false, 'error' => 'Delivery not found'], 404);
            return;
        }
        if (!dl_deliveryRecordAuthorized($user, $delivery)) {
            $ctx->db()->rollBack();
            $ctx->json(['ok' => false, 'error' => 'Branch not authorized'], 403);
            return;
        }
        $status = (string)($delivery['status'] ?? '');
        if ($status === 'voided') {
            $ctx->db()->commit();
            $ctx->json(['ok' => true]);
            return;
        }

        if ((string)($delivery['destination_type'] ?? '') === 'branch' && dl_deliveryHasActiveReceivings($ctx->db(), $deliveryId)) {
            $ctx->db()->rollBack();
            $ctx->json(['ok' => false, 'error' => 'Cannot void a delivery that already has a receiving. Void the receiving first.'], 422);
            return;
        }

        $shouldReverseOriginWithdraw = (string)($delivery['origin_type'] ?? '') === 'branch'
            && (int)($delivery['origin_id'] ?? 0) > 0
            && in_array((string)($delivery['remarks'] ?? ''), [dl_cashierDispatchRemark(), dl_paperDrCaptureRemark()], true);

        if ($shouldReverseOriginWithdraw) {
            $itemsStmt = $ctx->db()->prepare('SELECT product_id, quantity FROM dl_delivery_items WHERE delivery_id = :id');
            $itemsStmt->execute([':id' => $deliveryId]);
            foreach ($itemsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $item) {
                dl_applyLedgerDelta(
                    (int)$delivery['origin_id'],
                    (int)$item['product_id'],
                    (string)$delivery['delivery_date'],
                    -((int)$item['quantity']),
                    $userId,
                    'withdraw'
                );
            }
        }

        $ctx->db()->prepare(
            'UPDATE dl_deliveries SET status = "voided", voided_by = :u, voided_at = NOW() WHERE id = :id AND status <> "voided"'
        )->execute([':u' => $userId ?: null, ':id' => $deliveryId]);

        $ctx->db()->commit();
        dl_auditLog('delivery_voided', null, 'dl_deliveries', (string)$deliveryId,
            ['status' => $status], ['status' => 'voided'], $reason ?: null);
        $ctx->json(['ok' => true]);
    } catch (\Throwable $e) {
        if ($ctx->db()->inTransaction()) {
            $ctx->db()->rollBack();
        }
        $ctx->log('apiVoidDelivery: ' . $e->getMessage(), 'error', [
            'delivery_id' => $deliveryId,
            'user_sub' => $user['sub'] ?? null,
        ]);
        $ctx->json(['ok' => false, 'error' => 'Database error'], 500);
    }
}

function apiReviewDeliveryProvenance(array $params = []): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); return; }

    $user = dlCurrentUser(['admin', 'supervisor', 'production_in_charge']);
    $role = (string)($user['role'] ?? '');
    $reviewerId = dl_getActorUserId($user);
    $input = (array)json_decode(file_get_contents('php://input'), true);
    $deliveryId = (int)($input['delivery_id'] ?? 0);
    $action = trim((string)($input['action'] ?? ''));
    $note = trim((string)($input['note'] ?? ''));

    if ($deliveryId <= 0 || !in_array($action, ['accepted', 'discrepant', 'reopen'], true)) {
        $ctx->json(['ok' => false, 'error' => 'Invalid paper DR check request'], 422);
        return;
    }
    if ($role === 'production_in_charge' && $action !== 'accepted') {
        $ctx->json(['ok' => false, 'error' => 'Production In Charge can only verify paper DR exceptions.'], 403);
        return;
    }

    $stmt = $ctx->db()->prepare(
        'SELECT id, origin_type, origin_id, destination_type, destination_id,
                remarks, provenance_status, provenance_review_note
           FROM dl_deliveries
          WHERE id = :id
          LIMIT 1'
    );
    $stmt->execute([':id' => $deliveryId]);
    $delivery = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$delivery) {
        $ctx->json(['ok' => false, 'error' => 'Delivery not found'], 404);
        return;
    }
    if (!dl_deliveryRecordAuthorized($user, $delivery)) {
        $ctx->json(['ok' => false, 'error' => 'Branch not authorized'], 403);
        return;
    }
    if (!dl_isPaperDrCapturedDelivery($delivery)) {
        $ctx->json(['ok' => false, 'error' => 'Only captured paper-DR deliveries can be checked here.'], 422);
        return;
    }

    $newStatus = $action === 'reopen' ? 'paper_dr_pending' : $action;
    $bind = [
        ':status' => $newStatus,
        ':id' => $deliveryId,
    ];
    $sql = 'UPDATE dl_deliveries
               SET provenance_status = :status';
    if ($action === 'reopen') {
        $sql .= ', provenance_reviewed_by = NULL, provenance_reviewed_at = NULL, provenance_review_note = NULL';
    } else {
        $sql .= ', provenance_reviewed_by = :reviewed_by, provenance_reviewed_at = NOW(), provenance_review_note = :note';
        $bind[':reviewed_by'] = $reviewerId > 0 ? $reviewerId : null;
        $bind[':note'] = $note !== '' ? $note : null;
    }
    $sql .= ' WHERE id = :id';

    $ctx->db()->prepare($sql)->execute($bind);

    dl_auditLog('review_delivery_provenance', (int)($delivery['destination_id'] ?? 0) ?: null, 'dl_deliveries', (string)$deliveryId, [
        'provenance_status' => (string)($delivery['provenance_status'] ?? 'none'),
        'provenance_review_note' => (string)($delivery['provenance_review_note'] ?? ''),
    ], [
        'provenance_status' => $newStatus,
        'provenance_review_note' => $action === 'reopen' ? '' : $note,
        'review_action' => $action,
        'reviewed_by_role' => $role,
    ]);

    $ctx->json(['ok' => true, 'provenance_status' => $newStatus]);
}

function apiListDeliveries(array $params = []): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); return; }
    $user = dlCurrentUser(['admin', 'supervisor', 'production_in_charge']);
    $authResult = dl_authorizeBranch($user, $_GET);
    if ($authResult['branch_id'] < 0) {
        $ctx->json(['ok' => false, 'error' => 'Branch not authorized'], 403);
        return;
    }
    $accessibleBranchIds = $authResult['accessible'];
    $status = (string)($_GET['status'] ?? '');
    $destType = (string)($_GET['destination_type'] ?? '');
    $destId   = isset($_GET['destination_id']) ? (int)$_GET['destination_id'] : 0;
    $branchFilterId = isset($_GET['branch_id']) ? (int)$_GET['branch_id'] : 0;
    $dateFrom = trim((string)($_GET['date_from'] ?? ''));
    $dateTo = trim((string)($_GET['date_to'] ?? ''));
    $provenanceStatus = (string)($_GET['provenance_status'] ?? '');

    if ($dateFrom !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
        $ctx->json(['ok' => false, 'error' => 'Invalid from date.'], 422);
        return;
    }
    if ($dateTo !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
        $ctx->json(['ok' => false, 'error' => 'Invalid to date.'], 422);
        return;
    }
    if ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo) {
        [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
    }

    $where = [];
    $bind = [];

    // Admins own the tenant-wide operational view; other roles are branch-scoped.
    if ((string)($user['role'] ?? '') !== 'admin') {
        if (count($accessibleBranchIds) === 0) {
            $where[] = '1 = 0';
        } else {
            $originPlaceholders = [];
            $destinationPlaceholders = [];
            foreach (array_values($accessibleBranchIds) as $index => $accessibleBranchId) {
                $originKey = ':origin_branch_' . $index;
                $destinationKey = ':destination_branch_' . $index;
                $originPlaceholders[] = $originKey;
                $destinationPlaceholders[] = $destinationKey;
                $bind[$originKey] = (int)$accessibleBranchId;
                $bind[$destinationKey] = (int)$accessibleBranchId;
            }
            $where[] = "((d.origin_type IN ('branch', 'commissary') AND d.origin_id IN ("
                . implode(',', $originPlaceholders)
                . ")) OR (d.destination_type = 'branch' AND d.destination_id IN ("
                . implode(',', $destinationPlaceholders)
                . ')))';
        }
    }

    $hasReceivingSql = 'EXISTS (
        SELECT 1
          FROM dl_branch_receivings br
         WHERE br.delivery_id = d.id
           AND br.status <> "voided"
    )';
    if ($status === 'received') {
        $where[] = $hasReceivingSql;
    } elseif ($status === 'posted') {
        $where[] = 'd.status = :s';
        $where[] = 'NOT ' . $hasReceivingSql;
        $bind[':s'] = 'posted';
    } elseif ($status !== '') {
        $where[] = 'd.status = :s';
        $bind[':s'] = $status;
    }
    $allowedDestinationTypes = array_values(array_map(static fn(array $row): string => (string)$row['value'], dlDeliveryDestinationTypeOptions()));
    if ($destType !== '') {
        if (!in_array($destType, $allowedDestinationTypes, true)) {
            $ctx->json(['ok' => false, 'error' => 'Invalid destination type.'], 422);
            return;
        }
        $where[] = 'd.destination_type = :dt';
        $bind[':dt'] = $destType;
    }
    if ($destId > 0) { $where[] = 'd.destination_id = :did'; $bind[':did'] = $destId; }
    if ($branchFilterId > 0) {
        if ((string)($user['role'] ?? '') !== 'admin' && !in_array($branchFilterId, $accessibleBranchIds, true)) {
            $ctx->json(['ok' => false, 'error' => 'Branch not authorized'], 403);
            return;
        }
        $where[] = '((d.origin_type IN ("branch", "commissary") AND d.origin_id = :filter_branch_id)
                     OR (d.destination_type = "branch" AND d.destination_id = :filter_branch_id_destination))';
        $bind[':filter_branch_id'] = $branchFilterId;
        $bind[':filter_branch_id_destination'] = $branchFilterId;
    }
    if ($dateFrom !== '') {
        $where[] = 'd.delivery_date >= :date_from';
        $bind[':date_from'] = $dateFrom;
    }
    if ($dateTo !== '') {
        $where[] = 'd.delivery_date <= :date_to';
        $bind[':date_to'] = $dateTo;
    }
    if (in_array($provenanceStatus, ['paper_dr_pending', 'accepted', 'discrepant'], true)) {
        $where[] = 'd.provenance_status = :ps';
        $bind[':ps'] = $provenanceStatus;
    }
    $sql = 'SELECT d.id, d.origin_type, d.origin_id, d.destination_type, d.destination_id, d.dr_number,
                   d.delivery_date,
                   CASE WHEN ' . $hasReceivingSql . ' THEN "received" ELSE d.status END AS status,
                   d.status AS delivery_status,
                   CASE WHEN ' . $hasReceivingSql . ' THEN 1 ELSE 0 END AS has_receiving,
                   d.created_at, d.posted_at, d.voided_at,
                   d.remarks, d.provenance_status, d.provenance_reviewed_at, d.provenance_review_note,
                   CASE WHEN d.remarks = :paper_dr_remark THEN 1 ELSE 0 END AS is_paper_dr_exception,
                   ru.username AS provenance_reviewer_name,
                   CASE
                       WHEN d.origin_type = "commissary" THEN "Commissary"
                       WHEN d.origin_type = "branch" THEN COALESCE(ob.name, CONCAT("Branch #", d.origin_id))
                       WHEN d.origin_id IS NOT NULL AND d.origin_id > 0 THEN CONCAT(REPLACE(d.origin_type, "_", " "), " #", d.origin_id)
                       ELSE REPLACE(d.origin_type, "_", " ")
                   END AS origin_label,
                   CASE
                       WHEN d.destination_type = "branch" THEN COALESCE(db.name, CONCAT("Branch #", d.destination_id))
                       WHEN d.destination_id IS NOT NULL AND d.destination_id > 0 THEN CONCAT(REPLACE(d.destination_type, "_", " "), " #", d.destination_id)
                       ELSE REPLACE(d.destination_type, "_", " ")
                   END AS destination_label
              FROM dl_deliveries d
              LEFT JOIN dl_branches ob ON ob.id = d.origin_id AND d.origin_type = "branch"
              LEFT JOIN dl_branches db ON db.id = d.destination_id AND d.destination_type = "branch"
              LEFT JOIN dl_users ru ON ru.id = d.provenance_reviewed_by'
         . (count($where) ? ' WHERE ' . implode(' AND ', $where) : '')
         . ' ORDER BY d.delivery_date DESC, d.id DESC LIMIT 200';
    $stmt = $ctx->db()->prepare($sql);
    $bind[':paper_dr_remark'] = dl_paperDrCaptureRemark();
    $stmt->execute($bind);
    $deliveries = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($deliveries as &$deliveryRow) {
        $statusMeta = dlDeliveryStatusMeta((string)($deliveryRow['status'] ?? ''));
        $deliveryRow['status_label'] = $statusMeta['label'];
        $deliveryRow['status_badge_classes'] = $statusMeta['badge_classes'];
        $provenanceMeta = dlDeliveryProvenanceStatusMeta((string)($deliveryRow['provenance_status'] ?? ''));
        $deliveryRow['provenance_status_label'] = $provenanceMeta['label'];
        $deliveryRow['provenance_status_badge_classes'] = $provenanceMeta['badge_classes'];
    }
    unset($deliveryRow);
    $ctx->json(['ok' => true, 'deliveries' => $deliveries]);
}

function apiGetDeliveryReceivingDetail(array $params = []): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); return; }
    $user = dlCurrentUser(['admin', 'supervisor', 'production_in_charge']);
    $deliveryId = (int)($_GET['delivery_id'] ?? 0);
    if ($deliveryId <= 0) { $ctx->json(['ok' => false, 'error' => 'delivery_id required'], 422); return; }

    $deliveryStmt = $ctx->db()->prepare(
        'SELECT origin_type, origin_id, destination_type, destination_id
           FROM dl_deliveries
          WHERE id = :id
          LIMIT 1'
    );
    $deliveryStmt->execute([':id' => $deliveryId]);
    $delivery = $deliveryStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$delivery) {
        $ctx->json(['ok' => false, 'error' => 'Delivery not found'], 404);
        return;
    }
    if (!dl_deliveryRecordAuthorized($user, $delivery)) {
        $ctx->json(['ok' => false, 'error' => 'Branch not authorized'], 403);
        return;
    }

    // Get the latest non-voided receiving for this delivery
    $rcvStmt = $ctx->db()->prepare(
        'SELECT br.id, br.status, br.received_ledger_date, br.posted_at,
                du.username AS received_by_name
         FROM dl_branch_receivings br
         LEFT JOIN dl_users du ON du.id = br.posted_by
         WHERE br.delivery_id = :did AND br.status <> \'voided\'
         ORDER BY br.id DESC LIMIT 1'
    );
    $rcvStmt->execute([':did' => $deliveryId]);
    $rcv = $rcvStmt->fetch(PDO::FETCH_ASSOC);

    if (!$rcv) {
        $ctx->json(['ok' => true, 'receiving' => null, 'items' => []]);
        return;
    }

    $itemStmt = $ctx->db()->prepare(
        'SELECT p.name AS product_name,
                di.quantity AS sent_qty,
                COALESCE(ri.quantity_received, di.quantity) AS received_qty,
                COALESCE(ri.quantity_received, di.quantity) - di.quantity AS variance
         FROM dl_delivery_items di
         INNER JOIN dl_products p ON p.id = di.product_id
         LEFT JOIN dl_branch_receiving_items ri
             ON ri.delivery_item_id = di.id AND ri.receiving_id = :rcv
         WHERE di.delivery_id = :did
         ORDER BY p.name'
    );
    $itemStmt->execute([':did' => $deliveryId, ':rcv' => (int)$rcv['id']]);
    $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($items as &$item) {
        $item['sent_qty']     = (int)$item['sent_qty'];
        $item['received_qty'] = (int)$item['received_qty'];
        $item['variance']     = (int)$item['variance'];
    }
    unset($item);

    $ctx->json(['ok' => true, 'receiving' => $rcv, 'items' => $items]);
}

function apiCreateReceiving(array $params = []): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); return; }
    if (!dl_isFormalDeliveryEnabled()) {
        $ctx->json(['ok' => false, 'error' => 'Formal Delivery Workflow is disabled.'], 403);
        return;
    }
    $user = dlCurrentUser(['admin', 'supervisor', 'production_in_charge']);
    $userId = dl_getActorUserId($user);
    $input = (array)json_decode(file_get_contents('php://input'), true);

    $authResult = dl_authorizeBranch($user, $input);
    if ($authResult['branch_id'] < 0) {
        $ctx->json(['ok' => false, 'error' => 'Branch not authorized'], 403);
        return;
    }
    $branchId = $authResult['branch_id'];
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
        if (!dl_deliveryRecordAuthorized($user, $del)
            || (string)($del['destination_type'] ?? '') !== 'branch'
            || (int)($del['destination_id'] ?? 0) !== $branchId) {
            $ctx->json(['ok' => false, 'error' => 'Delivery is not authorized for this branch'], 403);
            return;
        }
        if ((string)($del['status'] ?? '') !== 'posted') {
            $ctx->json(['ok' => false, 'error' => 'Only posted deliveries can be received'], 422);
            return;
        }
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
    $user = dlCurrentUser(['admin', 'supervisor', 'production_in_charge']);
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
        if (!in_array((int)$head['branch_id'], dl_accessibleBranchIds($user), true)) {
            $ctx->db()->rollBack();
            $ctx->json(['ok' => false, 'error' => 'Branch not authorized'], 403);
            return;
        }
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
        if (!in_array((int)$head['branch_id'], dl_accessibleBranchIds($user), true)) {
            $ctx->db()->rollBack();
            $ctx->json(['ok' => false, 'error' => 'Branch not authorized'], 403);
            return;
        }
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
    $user = dlCurrentUser(['admin', 'supervisor', 'production_in_charge']);
    $authResult = dl_authorizeBranch($user, $_GET);
    if ($authResult['branch_id'] < 0) {
        $ctx->json(['ok' => false, 'error' => 'Branch not authorized'], 403);
        return;
    }
    $branchId = $authResult['branch_id'];
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

// ─── Move a delivery to the correct destination branch ──────────────────

/**
 * Move a posted stock delivery (and its receiving) to the correct branch.
 *
 * Stable, auditable correction for the "DR was received at the wrong branch"
 * case: the wrong branch's `addtl` is reversed, the wrong receiving is voided
 * (kept as append-only history), the delivery's destination is updated, and a
 * fresh receiving is created on the correct branch (applying its `addtl`).
 * Derived sales are recomputed on both branches. Admin/supervisor only —
 * the caller must be authorized for BOTH branches.
 *
 * $args: delivery_id, target_branch_id, reason, role, actor_id, business_date,
 *        has_override, accessible (int[] branch ids).
 * Returns ['ok'=>bool,'status'=>int,'error'=>?string,'delivery_id'=>?int,
 *          'receiving_id'=>?int,'moved_addtl'=>bool].
 */
function dl_moveDeliveryToBranch($db, array $args): array
{
    $deliveryId = (int)($args['delivery_id'] ?? 0);
    $targetBranchId = (int)($args['target_branch_id'] ?? 0);
    $reason = trim((string)($args['reason'] ?? ''));
    $role = (string)($args['role'] ?? '');
    $actorId = (int)($args['actor_id'] ?? 0);
    $businessDate = (string)($args['business_date'] ?? date('Y-m-d'));
    $hasOverride = (bool)($args['has_override'] ?? false);
    $accessible = is_array($args['accessible'] ?? null) ? array_map('intval', $args['accessible']) : [];

    if ($deliveryId <= 0 || $targetBranchId <= 0 || $reason === '') {
        return ['ok' => false, 'status' => 422, 'error' => 'delivery_id, target_branch_id and reason are required.'];
    }

    $db->beginTransaction();
    try {
        $stmt = $db->prepare(
            'SELECT * FROM dl_deliveries
              WHERE id = :id AND status <> "voided"
              LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([':id' => $deliveryId]);
        $delivery = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($delivery)) {
            $db->rollBack();
            return ['ok' => false, 'status' => 404, 'error' => 'Delivery not found.'];
        }
        if ((string)$delivery['status'] !== 'posted') {
            $db->rollBack();
            return ['ok' => false, 'status' => 409, 'error' => 'Only posted deliveries can be moved.'];
        }
        if ((string)$delivery['destination_type'] !== 'branch' || (int)$delivery['destination_id'] <= 0) {
            $db->rollBack();
            return ['ok' => false, 'status' => 422, 'error' => 'Only deliveries to a branch can be moved.'];
        }
        $wrongBranchId = (int)$delivery['destination_id'];
        if ($targetBranchId === $wrongBranchId) {
            $db->rollBack();
            return ['ok' => false, 'status' => 422, 'error' => 'The delivery already points to that branch.'];
        }
        // Authority over both branches is required (admins: all; supervisors: assigned).
        if (!in_array($wrongBranchId, $accessible, true) || !in_array($targetBranchId, $accessible, true)) {
            $db->rollBack();
            return ['ok' => false, 'status' => 403, 'error' => 'You must be authorized for both the wrong and the correct branch.'];
        }
        // Target must be an active branch.
        $targetStmt = $db->prepare('SELECT id FROM dl_branches WHERE id = :id AND is_active = 1 LIMIT 1');
        $targetStmt->execute([':id' => $targetBranchId]);
        if ((int)$targetStmt->fetchColumn() <= 0) {
            $db->rollBack();
            return ['ok' => false, 'status' => 422, 'error' => 'Target branch is not active.'];
        }

        // Active receiving on the wrong branch (if any).
        $rcvStmt = $db->prepare(
            'SELECT * FROM dl_branch_receivings
              WHERE delivery_id = :did AND status <> "voided" ORDER BY id DESC LIMIT 1'
        );
        $rcvStmt->execute([':did' => $deliveryId]);
        $activeReceiving = $rcvStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        $receiveDate = is_array($activeReceiving)
            ? (string)$activeReceiving['received_ledger_date']
            : (string)$delivery['delivery_date'];

        // Day checks: both branches' relevant days must be open unless override.
        if (dl_getDayStatus($wrongBranchId, $receiveDate) === 'closed' && !$hasOverride) {
            $db->rollBack();
            return ['ok' => false, 'status' => 409, 'error' => 'The wrong branch day is closed.'];
        }
        if (dl_getDayStatus($targetBranchId, $receiveDate) === 'closed' && !$hasOverride) {
            $db->rollBack();
            return ['ok' => false, 'status' => 409, 'error' => 'The target branch day is closed.'];
        }

        $movedAddtl = false;
        if (is_array($activeReceiving)) {
            // Reverse the wrong branch's received stock.
            $rcvItemsStmt = $db->prepare('SELECT product_id, quantity_received FROM dl_branch_receiving_items WHERE receiving_id = :rid');
            $rcvItemsStmt->execute([':rid' => (int)$activeReceiving['id']]);
            foreach ($rcvItemsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $it) {
                $qty = (int)$it['quantity_received'];
                if ($qty > 0) {
                    dl_applyLedgerDelta($wrongBranchId, (int)$it['product_id'], $receiveDate, -$qty, $actorId, 'addtl');
                    dl_recomputeSales($wrongBranchId, (int)$it['product_id'], $receiveDate, max(0, $actorId));
                }
            }
            // Void the wrong receiving (append-only history; status flip only).
            $db->prepare(
                'UPDATE dl_branch_receivings SET status = "voided", voided_by = :u, voided_at = NOW() WHERE id = :rid AND status <> "voided"'
            )->execute([':u' => $actorId > 0 ? $actorId : null, ':rid' => (int)$activeReceiving['id']]);
            $movedAddtl = true;
        }

        // Repoint the delivery to the correct branch.
        $db->prepare(
            'UPDATE dl_deliveries SET destination_id = :tbid, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
        )->execute([':tbid' => $targetBranchId, ':id' => $deliveryId]);

        // Create the receiving on the correct branch (applies addtl + variance flags).
        $newReceivingId = 0;
        if ($movedAddtl) {
            $newReceivingId = dl_acceptFormalDelivery($db, $targetBranchId, $deliveryId, $actorId, $receiveDate, null);
        }

        dl_auditLog('delivery_destination_changed', $wrongBranchId, 'dl_deliveries', (string)$deliveryId, [
            'destination_type' => 'branch',
            'destination_id' => $wrongBranchId,
        ], null, $reason);
        dl_auditLog('delivery_destination_changed', $targetBranchId, 'dl_deliveries', (string)$deliveryId, null, [
            'destination_type' => 'branch',
            'destination_id' => $targetBranchId,
            'wrong_branch_id' => $wrongBranchId,
            'receiving_id' => $newReceivingId ?: null,
        ], $reason);

        $db->commit();
        return [
            'ok' => true,
            'status' => 200,
            'error' => null,
            'delivery_id' => $deliveryId,
            'receiving_id' => $newReceivingId ?: null,
            'moved_addtl' => $movedAddtl,
            'wrong_branch_id' => $wrongBranchId,
            'target_branch_id' => $targetBranchId,
        ];
    } catch (\Throwable $e) {
        if ($db->inTransaction()) { $db->rollBack(); }
        write_log('daily-ledger delivery move failed: ' . $e->getMessage(), 'error', ['delivery_id' => $deliveryId]);
        return ['ok' => false, 'status' => 400, 'error' => $e->getMessage()];
    }
}

/**
 * POST /daily-ledger/api/v1/admin/deliveries/change-destination
 *
 * Admin/supervisor only: correct a delivery received at the wrong branch.
 * The actor must be authorized for both the wrong and the correct branch
 * (which structurally excludes single-branch cashiers). Reason required.
 */
function apiChangeDeliveryDestination(array $params = []): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); return; }
    $user = dlCurrentUser(['admin', 'supervisor']);
    $role = (string)($user['role'] ?? '');

    $input = (array)json_decode(file_get_contents('php://input'), true);
    $deliveryId = (int)($input['delivery_id'] ?? 0);
    $targetBranchId = (int)($input['target_branch_id'] ?? 0);
    $reason = trim((string)($input['reason'] ?? ''));
    if ($deliveryId <= 0 || $targetBranchId <= 0) {
        $ctx->json(['ok' => false, 'error' => 'delivery_id and target_branch_id are required.'], 422);
        return;
    }
    if ($reason === '') {
        $ctx->json(['ok' => false, 'error' => 'A reason is required.'], 422);
        return;
    }
    if (mb_strlen($reason) > 255) {
        $ctx->json(['ok' => false, 'error' => 'Reason must be 255 characters or fewer.'], 422);
        return;
    }

    $result = dl_moveDeliveryToBranch($ctx->db(), [
        'delivery_id' => $deliveryId,
        'target_branch_id' => $targetBranchId,
        'reason' => $reason,
        'role' => $role,
        'actor_id' => dl_getActorUserId($user),
        'business_date' => dl_businessDate(),
        'has_override' => dl_roleHasPermission($role, 'ledger.override'),
        'accessible' => dl_accessibleBranchIds($user),
    ]);

    if (!$result['ok']) {
        $ctx->json(['ok' => false, 'error' => $result['error']], (int)$result['status']);
        return;
    }
    $ctx->json([
        'ok' => true,
        'delivery_id' => (int)$result['delivery_id'],
        'receiving_id' => $result['receiving_id'] ? (int)$result['receiving_id'] : null,
        'wrong_branch_id' => (int)$result['wrong_branch_id'],
        'target_branch_id' => (int)$result['target_branch_id'],
        'moved_addtl' => (bool)$result['moved_addtl'],
    ]);
}

// ─── Edit stock delivery by DR (cashier correction) ─────────────────────

/**
 * Permission gate for editing stock deliveries by DR. Supervisors and admins
 * are always allowed; cashiers require the `delivery.edit` grant configured in
 * Admin Settings → Override Permissions.
 */
function dl_canEditDeliveryByDr(array $user): bool
{
    $role = (string)($user['role'] ?? '');
    if (in_array($role, ['supervisor', 'admin'], true)) {
        return true;
    }
    return dl_roleHasPermission($role, 'delivery.edit');
}

/**
 * Correct a paper-DR stock delivery (missed items, qty correction).
 *
 * Pure transactional service — no HTTP. Adjusts delivery items, the active
 * receiving's items, and the origin `withdraw` / destination `addtl` ledger
 * deltas together, then recomputes derived sales. Caller supplies the
 * authorization/date context; the service re-checks the domain rules
 * (posted delivery, open day, cashier same-day).
 *
 * $args: branch_id, dr_number, reason, desired (pid => qty), role,
 *        actor_id, business_date, has_override.
 * Returns ['ok'=>bool,'status'=>int,'error'=>?string,'delivery_id'=>?int,
 *          'item_count'=>int,'updated'=>int,'removed'=>int].
 */
function dl_correctDeliveryByDr($db, array $args): array
{
    $branchId = (int)($args['branch_id'] ?? 0);
    $drNumber = trim((string)($args['dr_number'] ?? ''));
    $reason = trim((string)($args['reason'] ?? ''));
    $desired = is_array($args['desired'] ?? null) ? $args['desired'] : [];
    $role = (string)($args['role'] ?? '');
    $actorId = (int)($args['actor_id'] ?? 0);
    $businessDate = (string)($args['business_date'] ?? date('Y-m-d'));
    $hasOverride = (bool)($args['has_override'] ?? false);
    $shift = (($args['shift'] ?? 'AM') === 'PM') ? 'PM' : 'AM';

    if ($branchId <= 0 || $drNumber === '' || $reason === '') {
        return ['ok' => false, 'status' => 422, 'error' => 'branch_id, dr_number and reason are required.'];
    }
    if ($desired === []) {
        return ['ok' => false, 'status' => 422, 'error' => 'At least one valid product is required.'];
    }

    $db->beginTransaction();
    try {
        $stmt = $db->prepare(
            'SELECT * FROM dl_deliveries
              WHERE destination_type = "branch" AND destination_id = :bid AND dr_number = :dr
                AND status <> "voided"
              ORDER BY id DESC LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([':bid' => $branchId, ':dr' => $drNumber]);
        $delivery = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($delivery)) {
            $db->rollBack();
            return ['ok' => false, 'status' => 404, 'error' => 'No stock delivery found for this DR number.'];
        }
        if ((string)$delivery['status'] !== 'posted') {
            $db->rollBack();
            return ['ok' => false, 'status' => 409, 'error' => 'Only posted deliveries can be edited.'];
        }
        $deliveryDate = (string)$delivery['delivery_date'];
        if ($role === 'cashier' && $deliveryDate !== $businessDate) {
            $db->rollBack();
            return ['ok' => false, 'status' => 403, 'error' => 'Reference only — cashiers can only edit today\'s delivery.'];
        }

        $rcvStmt = $db->prepare(
            'SELECT * FROM dl_branch_receivings
              WHERE delivery_id = :did AND status <> "voided" ORDER BY id DESC LIMIT 1'
        );
        $rcvStmt->execute([':did' => (int)$delivery['id']]);
        $activeReceiving = $rcvStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        $receiveDate = is_array($activeReceiving)
            ? (string)$activeReceiving['received_ledger_date']
            : $deliveryDate;
        if (dl_getDayStatus($branchId, $receiveDate) === 'closed' && !$hasOverride) {
            $db->rollBack();
            return ['ok' => false, 'status' => 409, 'error' => 'Day is closed for this delivery.'];
        }

        $itemsStmt = $db->prepare('SELECT * FROM dl_delivery_items WHERE delivery_id = :did');
        $itemsStmt->execute([':did' => (int)$delivery['id']]);
        $existingItems = [];
        foreach ($itemsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $existingItems[(int)$row['product_id']] = $row;
        }

        $existingReceived = [];
        if (is_array($activeReceiving)) {
            $rcvItemsStmt = $db->prepare('SELECT * FROM dl_branch_receiving_items WHERE receiving_id = :rid');
            $rcvItemsStmt->execute([':rid' => (int)$activeReceiving['id']]);
            foreach ($rcvItemsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $existingReceived[(int)$row['product_id']] = $row;
            }
        }

        $priceGroupId = dl_defaultPriceGroupId();
        $originBranchId = ((string)$delivery['origin_type'] === 'branch' && (int)$delivery['origin_id'] > 0)
            ? (int)$delivery['origin_id'] : 0;

        $insItem = $db->prepare(
            'INSERT INTO dl_delivery_items
                (delivery_id, product_id, quantity, unit, unit_cost_snapshot, price_snapshot, price_group_id, remarks)
             VALUES (:did, :pid, :qty, "pcs", NULL, :price, :pg, :remarks)'
        );
        $updItem = $db->prepare(
            'UPDATE dl_delivery_items SET quantity = :qty WHERE id = :id'
        );
        $delItem = $db->prepare('DELETE FROM dl_delivery_items WHERE id = :id');

        $rcvUpdItem = null;
        $rcvDelItem = null;
        $rcvInsItem = null;
        if (is_array($activeReceiving)) {
            $rcvUpdItem = $db->prepare(
                'UPDATE dl_branch_receiving_items SET quantity_received = :qty WHERE id = :id'
            );
            $rcvDelItem = $db->prepare('DELETE FROM dl_branch_receiving_items WHERE id = :id');
            $rcvInsItem = $db->prepare(
                'INSERT INTO dl_branch_receiving_items
                    (receiving_id, delivery_item_id, product_id, quantity_received, unit, unit_cost_snapshot, selling_price_snapshot, remarks)
                 VALUES (:rid, :diid, :pid, :qty, "pcs", NULL, :price, :remarks)'
            );
        }

        $updated = [];
        $removed = [];
        foreach ($desired as $pid => $qty) {
            $pid = (int)$pid;
            $qty = max(0, (int)$qty);
            $oldQty = (int)($existingItems[$pid]['quantity'] ?? 0);
            $delta = $qty - $oldQty;

            if ($qty > 0) {
                if (isset($existingItems[$pid])) {
                    $updItem->execute([':qty' => $qty, ':id' => (int)$existingItems[$pid]['id']]);
                    $deliveryItemId = (int)$existingItems[$pid]['id'];
                } else {
                    $insItem->execute([
                        ':did' => (int)$delivery['id'], ':pid' => $pid, ':qty' => $qty,
                        ':price' => dl_resolveProductPrice($pid, $priceGroupId, $deliveryDate),
                        ':pg' => $priceGroupId, ':remarks' => 'edited-by-dr',
                    ]);
                    $deliveryItemId = (int)$db->lastInsertId();
                }
                if (is_array($activeReceiving)) {
                    if (isset($existingReceived[$pid])) {
                        $rcvUpdItem->execute([':qty' => $qty, ':id' => (int)$existingReceived[$pid]['id']]);
                    } else {
                        $rcvInsItem->execute([
                            ':rid' => (int)$activeReceiving['id'], ':diid' => $deliveryItemId, ':pid' => $pid,
                            ':qty' => $qty, ':price' => dl_resolveProductPrice($pid, $priceGroupId, $receiveDate),
                            ':remarks' => 'edited-by-dr',
                        ]);
                    }
                }
            } else {
                if (isset($existingItems[$pid])) {
                    $delItem->execute([':id' => (int)$existingItems[$pid]['id']]);
                }
                if (isset($existingReceived[$pid])) {
                    $rcvDelItem->execute([':id' => (int)$existingReceived[$pid]['id']]);
                }
            }

            if ($delta !== 0) {
                if ($originBranchId > 0) {
                    dl_applyLedgerDelta($originBranchId, $pid, $deliveryDate, $delta, $actorId, 'withdraw', $shift);
                }
                if (is_array($activeReceiving)) {
                    dl_applyLedgerDelta($branchId, $pid, $receiveDate, $delta, $actorId, 'addtl', $shift);
                }
                $updated[$pid] = ['old' => $oldQty, 'new' => $qty];
            }
        }

        // Remove delivery items that were dropped entirely from the corrected list.
        foreach (array_keys($existingItems) as $pid) {
            $pid = (int)$pid;
            if (array_key_exists($pid, $desired)) {
                continue;
            }
            $oldQty = (int)$existingItems[$pid]['quantity'];
            $delItem->execute([':id' => (int)$existingItems[$pid]['id']]);
            if (isset($existingReceived[$pid])) {
                $rcvDelItem->execute([':id' => (int)$existingReceived[$pid]['id']]);
            }
            $removed[$pid] = $oldQty;
            if ($oldQty !== 0) {
                if ($originBranchId > 0) {
                    dl_applyLedgerDelta($originBranchId, $pid, $deliveryDate, -$oldQty, $actorId, 'withdraw', $shift);
                }
                if (is_array($activeReceiving)) {
                    dl_applyLedgerDelta($branchId, $pid, $receiveDate, -$oldQty, $actorId, 'addtl', $shift);
                }
            }
        }

        foreach ($updated as $pid => $_) {
            if ($originBranchId > 0) { dl_recomputeSales($originBranchId, $pid, $deliveryDate, max(0, $actorId), $shift); }
            if (is_array($activeReceiving)) { dl_recomputeSales($branchId, $pid, $receiveDate, max(0, $actorId), $shift); }
        }
        foreach ($removed as $pid => $_) {
            if ($originBranchId > 0) { dl_recomputeSales($originBranchId, $pid, $deliveryDate, max(0, $actorId), $shift); }
            if (is_array($activeReceiving)) { dl_recomputeSales($branchId, $pid, $receiveDate, max(0, $actorId), $shift); }
        }

        dl_auditLog('edit_delivery_by_dr', $branchId, 'dl_deliveries', (string)$delivery['id'], null, [
            'dr_number' => $drNumber,
            'origin_branch_id' => $originBranchId ?: null,
            'shift' => $shift,
            'updated' => $updated,
            'removed' => $removed,
            'edited_by_role' => $role,
        ], $reason);

        $db->commit();
        return [
            'ok' => true,
            'status' => 200,
            'error' => null,
            'delivery_id' => (int)$delivery['id'],
            'item_count' => count($desired),
            'updated' => count($updated),
            'removed' => count($removed),
        ];
    } catch (\Throwable $e) {
        if ($db->inTransaction()) { $db->rollBack(); }
        write_log('daily-ledger delivery edit by DR failed: ' . $e->getMessage(), 'error', ['dr_number' => $drNumber]);
        return ['ok' => false, 'status' => 400, 'error' => $e->getMessage()];
    }
}

/** GET /daily-ledger/api/v1/cashier/ledger/delivery-detail — current items of a DR delivery. */
function apiGetDeliveryByDrForEdit(array $params = []): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); return; }
    $user = dlCurrentUser(['cashier', 'supervisor', 'admin']);
    if (!dl_canEditDeliveryByDr($user)) {
        $ctx->json(['ok' => false, 'error' => 'You are not authorized to edit stock deliveries by DR.'], 403);
        return;
    }
    $authResult = dl_authorizeBranch($user, $_GET);
    if ($authResult['branch_id'] < 0) {
        $ctx->json(['ok' => false, 'error' => 'Branch not authorized'], 403);
        return;
    }
    $branchId = (int)$authResult['branch_id'];
    $drNumber = trim((string)($_GET['dr_number'] ?? ''));
    if ($branchId <= 0 || $drNumber === '') {
        $ctx->json(['ok' => false, 'error' => 'branch and dr_number are required.'], 422);
        return;
    }

    $stmt = $ctx->db()->prepare(
        'SELECT d.id, d.dr_number, d.delivery_date, d.origin_type, d.origin_id, d.status
           FROM dl_deliveries d
          WHERE d.destination_type = "branch" AND d.destination_id = :bid AND d.dr_number = :dr
            AND d.status <> "voided"
          ORDER BY d.id DESC LIMIT 1'
    );
    $stmt->execute([':bid' => $branchId, ':dr' => $drNumber]);
    $delivery = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($delivery)) {
        $ctx->json(['ok' => false, 'error' => 'No stock delivery found for this DR number.'], 404);
        return;
    }

    $itemsStmt = $ctx->db()->prepare(
        'SELECT di.product_id, p.name AS product_name, p.sku, di.quantity
           FROM dl_delivery_items di
           INNER JOIN dl_products p ON p.id = di.product_id
          WHERE di.delivery_id = :did
          ORDER BY p.name'
    );
    $itemsStmt->execute([':did' => (int)$delivery['id']]);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($items as &$item) {
        $item['product_id'] = (int)$item['product_id'];
        $item['quantity'] = (int)$item['quantity'];
    }
    unset($item);

    $ctx->json(['ok' => true, 'delivery' => $delivery, 'items' => $items]);
}

/**
 * POST /daily-ledger/api/v1/cashier/ledger/delivery-edit
 *
 * Corrects a paper-DR stock delivery (missed items, qty correction).
 * Transactionally adjusts delivery items, the active receiving's items, and
 * the origin `withdraw` / destination `addtl` ledger deltas, then recomputes
 * derived sales. Cashiers may only correct the current business date; the day
 * must be open unless the actor holds ledger.override. A reason is required.
 */
function apiEditDeliveryByDr(array $params = []): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); return; }
    $user = dlCurrentUser(['cashier', 'supervisor', 'admin']);
    $role = (string)($user['role'] ?? '');
    if (!dl_canEditDeliveryByDr($user)) {
        $ctx->json(['ok' => false, 'error' => 'You are not authorized to edit stock deliveries by DR.'], 403);
        return;
    }

    $input = (array)json_decode(file_get_contents('php://input'), true);
    $authResult = dl_authorizeBranch($user, $input);
    if ($authResult['branch_id'] < 0) {
        $ctx->json(['ok' => false, 'error' => 'Branch not authorized'], 403);
        return;
    }
    $branchId = (int)$authResult['branch_id'];
    if ($branchId <= 0) {
        $ctx->json(['ok' => false, 'error' => 'No branch assigned.'], 422);
        return;
    }
    $drNumber = trim((string)($input['dr_number'] ?? ''));
    $reason = trim((string)($input['reason'] ?? ''));
    $rawItems = is_array($input['items'] ?? null) ? $input['items'] : [];
    if ($drNumber === '') {
        $ctx->json(['ok' => false, 'error' => 'DR number is required.'], 422);
        return;
    }
    if ($reason === '') {
        $ctx->json(['ok' => false, 'error' => 'A correction reason is required.'], 422);
        return;
    }
    if (mb_strlen($reason) > 255) {
        $ctx->json(['ok' => false, 'error' => 'Reason must be 255 characters or fewer.'], 422);
        return;
    }
    if ($rawItems === []) {
        $ctx->json(['ok' => false, 'error' => 'At least one item is required.'], 422);
        return;
    }

    $actorId = dl_getActorUserId($user);
    $businessDate = dl_businessDate();
    $shiftResolved = dl_resolveLedgerShift($user, $input);
    $shift = $shiftResolved['shift'];

    // Merge duplicate product lines; quantities may be 0 to remove a line.
    $desired = [];
    foreach ($rawItems as $i) {
        if (!is_array($i)) { continue; }
        $pid = (int)($i['product_id'] ?? 0);
        $qty = (int)($i['quantity'] ?? 0);
        if ($pid <= 0) { continue; }
        if ($qty < 0) {
            $ctx->json(['ok' => false, 'error' => 'Item quantities cannot be negative.'], 422);
            return;
        }
        $desired[$pid] = ($desired[$pid] ?? 0) + $qty;
    }
    if ($desired === []) {
        $ctx->json(['ok' => false, 'error' => 'At least one valid product is required.'], 422);
        return;
    }

    $result = dl_correctDeliveryByDr($ctx->db(), [
        'branch_id' => $branchId,
        'dr_number' => $drNumber,
        'reason' => $reason,
        'desired' => $desired,
        'role' => $role,
        'actor_id' => $actorId,
        'business_date' => $businessDate,
        'has_override' => dl_roleHasPermission($role, 'ledger.override'),
        'shift' => $shift,
    ]);

    if (!$result['ok']) {
        $ctx->json(['ok' => false, 'error' => $result['error']], (int)$result['status']);
        return;
    }
    $ctx->json([
        'ok' => true,
        'delivery_id' => (int)$result['delivery_id'],
        'item_count' => (int)$result['item_count'],
        'updated' => (int)$result['updated'],
        'removed' => (int)$result['removed'],
    ]);
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
    $authResult = dl_authorizeBranch($user, ['branch_id' => $branchId]);
    if ($authResult['branch_id'] < 0) {
        $ctx->json(['ok' => false, 'error' => 'Branch not authorized'], 403);
        return;
    }
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
    $user = dlCurrentUser(['admin', 'supervisor']);
    $branchId = (int)($_GET['branch_id'] ?? 0);
    if ($branchId <= 0) { $ctx->json(['ok' => true, 'rules' => []]); return; }
    $authResult = dl_authorizeBranch($user, ['branch_id' => $branchId]);
    if ($authResult['branch_id'] < 0) {
        $ctx->json(['ok' => false, 'error' => 'Branch not authorized'], 403);
        return;
    }

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
    dlCurrentUser(['admin', 'supervisor']);
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
    dlCurrentUser(['admin', 'supervisor']);
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

function apiBranchSearch(array $params = []): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); return; }
    dlCurrentUser(['admin']);
    $q = trim((string)($_GET['q'] ?? ''));
    if ($q === '') { $ctx->json(['ok' => true, 'branches' => []]); return; }
    $like = '%' . $q . '%';
    $stmt = $ctx->db()->prepare(
        'SELECT id, code, name FROM dl_branches WHERE is_active = 1 AND (name LIKE :q OR code LIKE :q2) ORDER BY name LIMIT 15'
    );
    $stmt->execute([':q' => $like, ':q2' => $like]);
    $ctx->json(['ok' => true, 'branches' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []]);
}

function apiPriceGroupAssignBranch(array $params = []): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); return; }
    if (!dl_arePriceGroupsEnabled()) {
        $ctx->json(['ok' => false, 'error' => 'Price Groups feature is disabled.'], 403);
        return;
    }
    dlCurrentUser(['admin']);
    $input = (array)json_decode(file_get_contents('php://input'), true);
    $groupId = (int)($input['price_group_id'] ?? 0);
    $branchId = (int)($input['branch_id'] ?? 0);
    if ($groupId <= 0 || $branchId <= 0) {
        $ctx->json(['ok' => false, 'error' => 'price_group_id and branch_id required'], 422);
        return;
    }

    try {
        $ctx->db()->prepare('UPDATE dl_branches SET price_group_id = :pg WHERE id = :id AND is_active = 1')
            ->execute([':pg' => $groupId, ':id' => $branchId]);
        $infoStmt = $ctx->db()->prepare('SELECT code, name FROM dl_branches WHERE id = :id');
        $infoStmt->execute([':id' => $branchId]);
        $info = $infoStmt->fetch(PDO::FETCH_ASSOC);
        dl_auditLog('price_group_branch_assigned', $branchId, 'dl_branches', (string)$branchId,
            null, ['price_group_id' => $groupId]);
        $ctx->json(['ok' => true, 'code' => $info['code'] ?? '', 'label' => ($info['code'] ?? '') ? ($info['code'] . ' - ' . $info['name']) : ($info['name'] ?? '')]);
    } catch (\Throwable $e) {
        $ctx->json(['ok' => false, 'error' => 'Database error'], 500);
    }
}

// ─── Phase F: Branch consolidated summary ────────────────────────────────

function dl_branchConsolidatedSummary(int $branchId, string $date): array
{
    $ctx = module();
    if (!$ctx) {
        return ['regular_sales' => 0.0, 'regular_qty' => 0, 'total_sales' => 0.0];
    }

    $salesExpr = dl_ledgerSalesQuantitySql('dl');
    $amountExpr = dl_ledgerSalesAmountSql('dl');
    // Official totals exclude provisional rows: uncounted endings (bal_end NULL)
    // and unfinalized manual PM rows must never inflate the official summary.
    $provisional = '(dl.bal_end IS NULL OR (dl.shift = \'PM\' AND (ss.status IS NULL OR ss.status <> \'finalized\')))';
    $regStmt = $ctx->db()->prepare(
        'SELECT COALESCE(SUM(CASE WHEN ' . $provisional . ' THEN 0 ELSE ' . $salesExpr . ' END),0) AS qty,
                COALESCE(SUM(CASE WHEN ' . $provisional . ' THEN 0 ELSE ' . $amountExpr . ' END),0) AS amt,
                COALESCE(SUM(CASE WHEN ' . $provisional . ' THEN ' . $salesExpr . ' ELSE 0 END),0) AS provisional_qty,
                COALESCE(SUM(CASE WHEN ' . $provisional . ' THEN ' . $amountExpr . ' ELSE 0 END),0) AS provisional_amt
           FROM dl_daily_ledger dl
           LEFT JOIN dl_ledger_shift_status ss ON ss.branch_id = dl.branch_id AND ss.ledger_date = dl.ledger_date AND ss.shift = dl.shift COLLATE utf8mb4_unicode_ci
          WHERE dl.branch_id = :b AND dl.ledger_date = :d'
    );
    $regStmt->execute([':b' => $branchId, ':d' => $date]);
    $reg = $regStmt->fetch(PDO::FETCH_ASSOC) ?: ['qty' => 0, 'amt' => 0, 'provisional_qty' => 0, 'provisional_amt' => 0];

    return [
        'branch_id' => $branchId,
        'date' => $date,
        'regular_sales' => (float)$reg['amt'],
        'regular_qty' => (int)$reg['qty'],
        'provisional_sales' => (float)$reg['provisional_amt'],
        'provisional_qty' => (int)$reg['provisional_qty'],
        'total_sales' => (float)$reg['amt'],
    ];
}

function apiBranchConsolidatedSummary(array $params = []): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); return; }
    $user = dlCurrentUser(['admin', 'supervisor', 'production_in_charge']);
    $authResult = dl_authorizeBranch($user, $_GET);
    if ($authResult['branch_id'] < 0) {
        $ctx->json(['ok' => false, 'error' => 'Branch not authorized'], 403);
        return;
    }
    $branchId = $authResult['branch_id'];
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
        'feature_price_groups'      => dl_settingToBool($s['price_groups_enabled'] ?? true),
        'feature_pos'               => dl_settingToBool($s['pos_enabled'] ?? false),
    ];
}

function handleAdminDeliveries(array $params = []): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); echo 'Module context unavailable'; return; }
    $user = dlCurrentUser(['admin', 'supervisor', 'production_in_charge']);
    $input = $ctx->input();

    $accessibleBranchIds = dl_accessibleBranchIds($user);
    if (count($accessibleBranchIds) === 0) { $accessibleBranchIds = [0]; }
    $branchPlaceholders = implode(',', array_fill(0, count($accessibleBranchIds), '?'));
    $branches = $ctx->db()->prepare("SELECT id, code, name, is_commissary FROM dl_branches WHERE is_active = 1 AND id IN ({$branchPlaceholders}) ORDER BY name");
    $branches->execute($accessibleBranchIds);
    $branches = $branches->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $products = $ctx->db()->query('SELECT id, sku, name FROM dl_products WHERE is_active = 1 ORDER BY name LIMIT 500')->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $role = (string)($user['role'] ?? '');
    $userName = (string)($user['name'] ?? $user['full_name'] ?? $user['username'] ?? 'User');
    $branchFilterId = isset($input['branch_id']) ? (int)$input['branch_id'] : 0;
    if ($branchFilterId > 0 && !in_array($branchFilterId, $accessibleBranchIds, true) && $role !== 'admin') {
        $branchFilterId = 0;
    }
    $dateFrom = trim((string)($input['date_from'] ?? ''));
    $dateTo = trim((string)($input['date_to'] ?? ''));
    if ($dateFrom !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
        $dateFrom = '';
    }
    if ($dateTo !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
        $dateTo = '';
    }
    if ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo) {
        [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
    }

    echo dlRender('modules/daily-ledger/admin/deliveries.disyl', array_merge(dl_layoutFlags(), [
        'page_title'   => 'Transfer Records',
        'user_name'    => $userName,
        'user_role'    => $role,
        'current_page' => 'deliveries',
        'base_url'     => dlGetBaseUrl(),
        'dl_token'     => (string)kernelCookie(dlCookieName(), ''),
        'branches'     => $branches,
        'products'     => $products,
        'delivery_status_options' => dlDeliveryStatusOptions(),
        'delivery_destination_type_options' => dlDeliveryDestinationTypeOptions(),
        'delivery_provenance_status_options' => dlDeliveryProvenanceStatusOptions(),
        'delivery_filter_branch_id' => $branchFilterId,
        'delivery_filter_date_from' => $dateFrom,
        'delivery_filter_date_to' => $dateTo,
        'formal_delivery_enabled' => dl_isFormalDeliveryEnabled(),
        'can_create_delivery_docs' => in_array($role, ['admin', 'supervisor'], true),
        'can_review_delivery_provenance' => in_array($role, ['admin', 'supervisor', 'production_in_charge'], true),
        'can_mark_delivery_discrepant' => in_array($role, ['admin', 'supervisor'], true),
        'can_change_destination' => in_array($role, ['admin', 'supervisor'], true),
        'branches_json' => json_encode(array_map(static function (array $b): array {
            return ['id' => (int)$b['id'], 'name' => (string)$b['name'], 'code' => (string)($b['code'] ?? '')];
        }, $branches), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP),
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
                COALESCE(branch_usage.branch_count, 0) AS branch_count
             FROM dl_price_groups pg
             LEFT JOIN (
                SELECT price_group_id, COUNT(*) AS branch_count
                  FROM dl_branches
                 WHERE price_group_id IS NOT NULL AND is_active = 1
                 GROUP BY price_group_id
             ) AS branch_usage ON branch_usage.price_group_id = pg.id
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

    foreach ($groups as &$group) {
        $groupId = (int)($group['id'] ?? 0);
        $group['branch_assignments_json'] = json_encode(
            array_values($branchAssignments[$groupId] ?? []),
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
