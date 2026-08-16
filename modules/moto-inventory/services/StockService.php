<?php

declare(strict_types=1);

/**
 * Moto Inventory — StockService
 *
 * Append-only movement ledger + transactional on-hand balance. The balance
 * on moto_products is a cache that is always updated in the SAME transaction
 * that appends the movement. Products are row-locked (SELECT ... FOR UPDATE)
 * during sale/void/adjustment so concurrent transactions cannot oversell or
 * double-reverse. History is never deleted — compensating movements are the
 * only way stock returns.
 */
final class StockService
{
    public const TYPE_SALE        = 'sale';
    public const TYPE_SALE_VOID   = 'sale_void';
    public const TYPE_IMPORT      = 'import';
    public const TYPE_ADJUSTMENT  = 'adjustment';
    public const TYPE_RESTORE     = 'restore';
    public const TYPE_UNDO        = 'undo';

    /**
     * Lock a product row for update within the current transaction.
     *
     * @throws \RuntimeException when the product is not found in the branch.
     */
    public static function lockProductRow(\Ikabud\Kernel\Contracts\ModuleDB $db, int $tenantId, int $branchId, int $productId): array
    {
        $stmt = $db->prepare(
            'SELECT id, qty_on_hand FROM moto_products
             WHERE tenant_id = :tid AND id = :id AND branch_id = :bid
             FOR UPDATE'
        );
        $stmt->execute([':tid' => $tenantId, ':id' => $productId, ':bid' => $branchId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new \RuntimeException('Product not found in branch');
        }

        return $row;
    }

    /**
     * Insert one append-only movement row. Caller owns the transaction.
     */
    public static function insertMovement(\Ikabud\Kernel\Contracts\ModuleDB $db, array $input): int
    {
        $stmt = $db->prepare(
            'INSERT INTO moto_stock_movements
                (tenant_id, branch_id, product_id, movement_type, quantity, prev_qty, new_qty,
                 reference_type, reference_id, idempotency_key, reason, actor_user_id, actor_name)
             VALUES
                (:tid, :bid, :pid, :mtype, :qty, :prev, :new, :rtype, :rid, :idem, :reason, :uid, :actor)'
        );
        $stmt->execute([
            ':tid'    => (int)$input['tenant_id'],
            ':bid'    => (int)$input['branch_id'],
            ':pid'    => (int)$input['product_id'],
            ':mtype'  => (string)$input['movement_type'],
            ':qty'    => (float)$input['quantity'],
            ':prev'   => $input['prev_qty'],
            ':new'    => $input['new_qty'],
            ':rtype'  => $input['reference_type'] ?? null,
            ':rid'    => $input['reference_id'] ?? null,
            ':idem'   => $input['idempotency_key'] ?? null,
            ':reason' => $input['reason'] ?? null,
            ':uid'    => $input['actor_user_id'] ?? null,
            ':actor'  => $input['actor_name'] ?? null,
        ]);

        return (int)$db->lastInsertId();
    }

    /**
     * Apply a signed quantity delta to a product's balance and append a
     * movement. MUST be called inside an open transaction (caller is
     * responsible for begin/commit/rollback). Locks the product row.
     *
     * @param bool $allowNegative When true, negative balances are permitted
     *                            (cashier override / explicit setting).
     * @return array{product_id:int, prev_qty:float, new_qty:float, quantity:float, movement_id:int, negative_override:bool}
     */
    public static function applyDelta(
        \Ikabud\Kernel\Contracts\ModuleDB $db,
        array $ctx,
        int $branchId,
        int $productId,
        float $delta,
        string $movementType,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $reason = null,
        ?string $idempotencyKey = null,
        bool $allowNegative = false
    ): array {
        $tenantId = (int)$ctx['tenant_id'];
        $row = self::lockProductRow($db, $tenantId, $branchId, $productId);

        $prevQty = (float)$row['qty_on_hand'];
        $newQty = round($prevQty + $delta, 4);
        $negative = $newQty < 0;

        $settings = moto_inventory_settings();
        $settingAllowsNegative = !empty($settings['allow_negative_stock']);
        if ($negative && !$allowNegative && !$settingAllowsNegative) {
            throw new \RuntimeException('Insufficient stock');
        }

        $stmt = $db->prepare(
            'UPDATE moto_products SET qty_on_hand = :qty WHERE tenant_id = :tid AND id = :id AND branch_id = :bid'
        );
        $stmt->execute([':qty' => $newQty, ':tid' => $tenantId, ':id' => $productId, ':bid' => $branchId]);

        $movementId = self::insertMovement($db, [
            'tenant_id'       => $tenantId,
            'branch_id'       => $branchId,
            'product_id'      => $productId,
            'movement_type'   => $movementType,
            'quantity'        => $delta,
            'prev_qty'        => $prevQty,
            'new_qty'         => $newQty,
            'reference_type'  => $referenceType,
            'reference_id'    => $referenceId,
            'idempotency_key' => $idempotencyKey,
            'reason'          => $reason,
            'actor_user_id'   => (int)($ctx['user_id'] ?? 0) ?: null,
            'actor_name'      => (string)($ctx['actor_name'] ?? ''),
        ]);

        return [
            'product_id'       => $productId,
            'prev_qty'         => $prevQty,
            'new_qty'          => $newQty,
            'quantity'         => $delta,
            'movement_id'      => $movementId,
            'negative_override'=> $negative,
        ];
    }

    /**
     * Public stock adjustment (reason required). Owns its own transaction
     * and is repeat-safe via an idempotency key.
     *
     * @return array{product_id:int, prev_qty:float, new_qty:float, quantity:float, movement_id:int}
     */
    public static function adjust(
        array $ctx,
        int $branchId,
        int $productId,
        float $delta,
        string $reason,
        ?string $idempotencyKey = null
    ): array {
        $branchId = moto_require_write_branch($ctx, $branchId);
        $tenantId = (int)$ctx['tenant_id'];
        $db = moto_db($tenantId);

        $request = [
            'branch_id' => $branchId, 'product_id' => $productId, 'delta' => $delta, 'reason' => $reason,
        ];
        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $cached = moto_idem_fetch($ctx, $idempotencyKey, 'stock.adjust', $request, $branchId);
            if ($cached !== null) {
                return $cached;
            }
        }

        if ($delta == 0) {
            throw new \InvalidArgumentException('Adjustment quantity cannot be zero');
        }
        if (trim((string)$reason) === '') {
            throw new \InvalidArgumentException('A reason is required for stock adjustments');
        }
        $product = CatalogService::productById($ctx, $productId, $branchId);
        if ($product === null) {
            throw new \InvalidArgumentException('Product not found in branch');
        }

        $db->beginTransaction();
        try {
            // Claim the idempotency key before touching stock; a concurrent
            // retry cannot both adjust (it waits for and returns our response).
            if ($idempotencyKey !== null && $idempotencyKey !== '') {
                if (!moto_idem_claim($db, $ctx, $idempotencyKey, 'stock.adjust', $request, $branchId)) {
                    $db->rollBack();
                    return moto_idem_wait_fetch($ctx, $idempotencyKey, 'stock.adjust', $request, $branchId);
                }
            }

            $result = self::applyDelta(
                $db, $ctx, $branchId, $productId, $delta,
                self::TYPE_ADJUSTMENT,
                'stock_adjustment', null,
                trim((string)$reason),
                $idempotencyKey
            );
            unset($result['negative_override']);

            // Audit + idempotency response commit atomically with the movement.
            moto_audit($ctx, 'moto_inventory.stock.adjusted', 'moto_product', (string)$productId, null, [
                'branch_id' => $branchId, 'delta' => $delta, 'reason' => $reason,
                'prev_qty' => $result['prev_qty'], 'new_qty' => $result['new_qty'],
            ], $branchId, $idempotencyKey, $db);
            if ($idempotencyKey !== null && $idempotencyKey !== '') {
                moto_idem_complete($db, $ctx, $idempotencyKey, 'stock.adjust', $result, $branchId);
            }

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        // Events are post-commit and best-effort (moto_emit_event never throws).
        moto_emit_event('moto_inventory.stock.adjusted', [
            'tenant_id' => $tenantId, 'branch_id' => $branchId, 'product_id' => $productId,
            'delta' => $delta, 'new_qty' => $result['new_qty'],
        ]);

        return $result;
    }

    /**
     * Movement ledger query (append-only history).
     */
    public static function movements(array $ctx, array $filters = []): array
    {
        $db = moto_db((int)$ctx['tenant_id']);
        $tenantId = (int)$ctx['tenant_id'];

        $where = ['m.tenant_id = :tid'];
        $params = [':tid' => $tenantId];

        if (!empty($filters['branch_id']) && (int)$filters['branch_id'] > 0) {
            $where[] = 'm.branch_id = :bid';
            $params[':bid'] = (int)$filters['branch_id'];
        }
        if (!empty($filters['product_id'])) {
            $where[] = 'm.product_id = :pid';
            $params[':pid'] = (int)$filters['product_id'];
        }
        if (!empty($filters['movement_type'])) {
            $where[] = 'm.movement_type = :mt';
            $params[':mt'] = (string)$filters['movement_type'];
        }
        if (!empty($filters['from'])) {
            $where[] = 'm.created_at >= :from';
            $params[':from'] = (string)$filters['from'] . ' 00:00:00';
        }
        if (!empty($filters['to'])) {
            $where[] = 'm.created_at <= :to';
            $params[':to'] = (string)$filters['to'] . ' 23:59:59';
        }

        $limit = max(1, min(250, (int)($filters['limit'] ?? 100)));
        $whereSql = implode(' AND ', $where);

        $stmt = $db->query(
            "SELECT m.id, m.branch_id, m.product_id, m.movement_type, m.quantity, m.prev_qty, m.new_qty,
                    m.reference_type, m.reference_id, m.reason, m.actor_name, m.created_at,
                    p.part_number, p.description, b.name AS brand
             FROM moto_stock_movements m
             JOIN moto_products p ON p.id = m.product_id
             JOIN moto_brands b ON b.id = p.brand_id
             WHERE {$whereSql}
             ORDER BY m.id DESC
             LIMIT {$limit}",
            $params
        );

        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Balance query for stock value reporting.
     */
    public static function balances(array $ctx, array $filters = []): array
    {
        $db = moto_db((int)$ctx['tenant_id']);
        $tenantId = (int)$ctx['tenant_id'];

        $where = ['p.tenant_id = :tid AND p.archived = 0'];
        $params = [':tid' => $tenantId];

        if (!empty($filters['branch_id']) && (int)$filters['branch_id'] > 0) {
            $where[] = 'p.branch_id = :bid';
            $params[':bid'] = (int)$filters['branch_id'];
        }
        if (!empty($filters['brand_id'])) {
            $where[] = 'p.brand_id = :brand';
            $params[':brand'] = (int)$filters['brand_id'];
        }

        $whereSql = implode(' AND ', $where);
        $row = $db->query(
            "SELECT COUNT(*) AS part_count,
                    COALESCE(SUM(p.qty_on_hand), 0) AS units_on_hand,
                    COALESCE(SUM(p.qty_on_hand * p.cost), 0) AS stock_value_cost,
                    COALESCE(SUM(p.qty_on_hand * p.price), 0) AS stock_value_retail
             FROM moto_products p
             WHERE {$whereSql}",
            $params
        )->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? $row : ['part_count' => 0, 'units_on_hand' => 0, 'stock_value_cost' => 0, 'stock_value_retail' => 0];
    }
}
