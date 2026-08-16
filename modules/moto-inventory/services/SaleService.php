<?php

declare(strict_types=1);

/**
 * Moto Inventory — SaleService
 *
 * Server-authoritative sale completion, five-minute undo, and privileged
 * void. Completing a sale atomically writes the sale, its items, stock
 * movements, balance changes, audit entry, and event inside one transaction.
 * Repeated idempotency keys return the original result. Void/undo create
 * compensating movements (never delete the original sale) and are blocked
 * after the first reversal.
 */
final class SaleService
{
    /**
     * Complete a sale. $lines is a list of ['product_id'=>int,'qty'=>float].
     *
     * @throws \RuntimeException on insufficient stock (unless $allowOverride)
     * @throws \InvalidArgumentException on malformed input
     * @return array{sale_id:int, sale_ref:string, total:float, cost:float, profit:float, items:array, receipt:array}
     */
    public static function complete(array $ctx, int $branchId, array $lines, ?string $customer = null, ?string $idempotencyKey = null, bool $allowOverride = false): array
    {
        $branchId = moto_require_write_branch($ctx, $branchId);
        // Capability/direct callers receive the same authorization boundary
        // as the HTTP handler; a boolean argument is never authority.
        $allowOverride = $allowOverride && moto_has_permission('moto_inventory.manage', $ctx['user'] ?? null);
        $tenantId = (int)$ctx['tenant_id'];
        $db = moto_db($tenantId);

        $request = [
            'branch_id' => $branchId, 'lines' => $lines, 'customer' => $customer, 'allow_override' => $allowOverride,
        ];
        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $cached = moto_idem_fetch($ctx, $idempotencyKey, 'sale.complete', $request, $branchId);
            if ($cached !== null) {
                return $cached;
            }
        }

        if (!is_array($lines) || count($lines) === 0) {
            throw new \InvalidArgumentException('Cart is empty');
        }

        $settings = moto_inventory_settings();
        $settingAllowsNegative = !empty($settings['allow_negative_stock']);
        $overrideUsed = false;

        $db->beginTransaction();
        try {
            // Claim the idempotency key atomically before any business write.
            // The unique (tenant, branch, key, operation) constraint guarantees
            // exactly one request may write; concurrent retries wait for and
            // receive that request's committed response instead of racing.
            if ($idempotencyKey !== null && $idempotencyKey !== '') {
                if (!moto_idem_claim($db, $ctx, $idempotencyKey, 'sale.complete', $request, $branchId)) {
                    $db->rollBack();
                    return moto_idem_wait_fetch($ctx, $idempotencyKey, 'sale.complete', $request, $branchId);
                }
            }

            $cart = [];
            $total = 0.0;
            $totalCost = 0.0;

            foreach ($lines as $line) {
                if (!is_array($line)) {
                    throw new \InvalidArgumentException('Invalid cart line');
                }
                $productId = (int)($line['product_id'] ?? 0);
                $qty = moto_qty($line['qty'] ?? 0);
                if ($productId <= 0) {
                    throw new \InvalidArgumentException('Cart line is missing a product');
                }
                if ($qty <= 0) {
                    throw new \InvalidArgumentException('Cart line quantity must be positive');
                }
                if (isset($line['product_id']) && array_key_exists('branch_id', $line)
                    && (int)($line['branch_id'] ?? 0) !== $branchId) {
                    throw new \InvalidArgumentException('Cross-branch products cannot be sold together');
                }

                $product = self::lockProductFull($db, $tenantId, $branchId, $productId);
                if ((int)$product['archived']) {
                    throw new \InvalidArgumentException('Archived product cannot be sold');
                }

                // Price is server-authoritative. Client-provided line prices
                // are display data only and must not permit cashier tampering.
                $price = moto_money_float($product['price']);
                $cost = moto_money_float($product['cost']);
                $lineTotal = round($price * $qty, 2);

                $stockBefore = (float)$product['qty_on_hand'];
                $stockAfter = round($stockBefore - $qty, 4);
                if ($stockAfter < 0 && !$settingAllowsNegative && !$allowOverride) {
                    throw new \RuntimeException(
                        'Insufficient stock for ' . $product['part_number'] . ' (' . $stockBefore . ' available)'
                    );
                }
                if ($stockAfter < 0) {
                    $overrideUsed = true;
                }

                $total += $lineTotal;
                $totalCost += round($cost * $qty, 2);
                $cart[] = [
                    'product_id'   => $productId,
                    'part_number'  => (string)$product['part_number'],
                    'description'  => (string)$product['description'],
                    'brand_name'   => (string)$product['brand'],
                    'qty'          => $qty,
                    'price'        => $price,
                    'cost'         => $cost,
                    'line_total'   => $lineTotal,
                    'stock_before' => $stockBefore,
                    'stock_after'  => $stockAfter,
                ];
            }

            $total = round($total, 2);
            $totalCost = round($totalCost, 2);
            $profit = round($total - $totalCost, 2);

            $saleRef = self::generateSaleRef();
            $undoWindow = max(0, (int)($settings['undo_window_minutes'] ?? 5));

            $stmt = $db->prepare(
                'INSERT INTO moto_sales
                    (tenant_id, branch_id, sale_ref, total, cost, profit, customer, override_flag, status, undo_deadline, idempotency_key, created_by, created_by_name)
                 VALUES (:tid, :bid, :ref, :total, :cost, :profit, :cust, :ovr, :status, :deadline, :idem, :uid, :actor)'
            );
            $stmt->execute([
                ':tid'      => $tenantId,
                ':bid'      => $branchId,
                ':ref'      => $saleRef,
                ':total'    => $total,
                ':cost'     => $totalCost,
                ':profit'   => $profit,
                ':cust'     => $customer !== null && trim($customer) !== '' ? substr(trim($customer), 0, 191) : null,
                ':ovr'      => $overrideUsed ? 1 : 0,
                ':status'   => 'completed',
                ':deadline' => $undoWindow > 0 ? date('Y-m-d H:i:s', time() + $undoWindow * 60) : null,
                ':idem'     => $idempotencyKey !== null ? $idempotencyKey : (string)$saleRef,
                ':uid'      => (int)($ctx['user_id'] ?? 0) ?: null,
                ':actor'    => (string)($ctx['actor_name'] ?? ''),
            ]);
            $saleId = (int)$db->lastInsertId();

            // Sale items
            $itemStmt = $db->prepare(
                'INSERT INTO moto_sale_items
                    (tenant_id, sale_id, product_id, branch_id, part_number, description, brand_name, qty, price, cost, line_total, stock_before, stock_after)
                 VALUES (:tid, :sid, :pid, :bid, :part, :desc, :brand, :qty, :price, :cost, :lt, :sb, :sa)'
            );
            foreach ($cart as $item) {
                $itemStmt->execute([
                    ':tid'    => $tenantId,
                    ':sid'    => $saleId,
                    ':pid'    => $item['product_id'],
                    ':bid'    => $branchId,
                    ':part'   => $item['part_number'],
                    ':desc'   => $item['description'],
                    ':brand'  => $item['brand_name'],
                    ':qty'    => $item['qty'],
                    ':price'  => $item['price'],
                    ':cost'   => $item['cost'],
                    ':lt'     => $item['line_total'],
                    ':sb'     => $item['stock_before'],
                    ':sa'     => $item['stock_after'],
                ]);
            }

            // Stock movements + balances
            foreach ($cart as $item) {
                StockService::applyDelta(
                    $db, $ctx, $branchId, $item['product_id'], -$item['qty'],
                    StockService::TYPE_SALE,
                    'moto_sale', $saleId,
                    'SALE:' . $saleRef,
                    $idempotencyKey !== null ? $idempotencyKey : $saleRef,
                    $allowOverride
                );
            }

            $result = [
                'sale_id' => $saleId,
                'sale_ref' => $saleRef,
                'total' => $total,
                'cost' => $totalCost,
                'profit' => $profit,
                'override' => $overrideUsed,
                'items' => array_map(static function (array $i): array {
                    return [
                        'product_id' => $i['product_id'], 'part_number' => $i['part_number'],
                        'description' => $i['description'], 'brand' => $i['brand_name'],
                        'qty' => $i['qty'], 'price' => $i['price'], 'line_total' => $i['line_total'],
                    ];
                }, $cart),
                'receipt' => [
                    'sale_ref' => $saleRef,
                    'date' => date('Y-m-d H:i:s'),
                    'customer' => $customer !== null && trim($customer) !== '' ? trim($customer) : null,
                    'total' => $total,
                    'items' => count($cart),
                ],
            ];

            // Audit and the idempotency response are written INSIDE the same
            // transaction as the sale, so they commit (or roll back) atomically
            // with it. A failed audit can never leave a committed sale behind,
            // and a retry always finds the recorded response.
            moto_audit($ctx, 'moto_inventory.sale.completed', 'moto_sale', (string)$saleId, null, [
                'branch_id' => $branchId, 'sale_ref' => $saleRef, 'total' => $total, 'override' => $overrideUsed, 'customer' => $customer,
            ], $branchId, $idempotencyKey !== null ? $idempotencyKey : $saleRef, $db);
            if ($idempotencyKey !== null && $idempotencyKey !== '') {
                moto_idem_complete($db, $ctx, $idempotencyKey, 'sale.complete', $result, $branchId);
            }

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        // Events are post-commit and best-effort (moto_emit_event never throws).
        moto_emit_event('moto_inventory.sale.completed', [
            'tenant_id' => $tenantId, 'branch_id' => $branchId, 'sale_id' => $saleId,
            'sale_ref' => $saleRef, 'total' => $total, 'profit' => $profit,
        ]);

        return $result;
    }

    /**
     * Undo the latest completed sale the current user created in a branch,
     * within the configured undo window.
     */
    public static function undoLatest(array $ctx, int $branchId): array
    {
        $branchId = moto_require_write_branch($ctx, $branchId);
        $db = moto_db((int)$ctx['tenant_id']);
        $tenantId = (int)$ctx['tenant_id'];
        $userId = (int)($ctx['user_id'] ?? 0);

        $stmt = $db->prepare(
            'SELECT * FROM moto_sales
             WHERE tenant_id = :tid AND branch_id = :bid AND status = :status AND created_by = :uid
             ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([':tid' => $tenantId, ':bid' => $branchId, ':status' => 'completed', ':uid' => $userId]);
        $sale = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($sale)) {
            throw new \RuntimeException('Nothing to undo');
        }

        $deadline = $sale['undo_deadline'];
        if ($deadline !== null && strtotime((string)$deadline) < time()) {
            throw new \RuntimeException('Undo window has passed. Use Void from History instead.');
        }

        return self::reverseSale($ctx, $branchId, (int)$sale['id'], StockService::TYPE_UNDO, 'Undo latest sale');
    }

    /**
     * Privileged void of any completed sale (requires moto_inventory.void).
     */
    public static function void(array $ctx, int $branchId, int $saleId): array
    {
        $branchId = moto_require_write_branch($ctx, $branchId);
        moto_require_permission($ctx, 'moto_inventory.void');

        return self::reverseSale($ctx, $branchId, $saleId, StockService::TYPE_SALE_VOID, 'Void sale');
    }

    /**
     * Reverse a completed sale: restore stock once, mark voided, never delete.
     */
    private static function reverseSale(array $ctx, int $branchId, int $saleId, string $movementType, string $reason): array
    {
        $db = moto_db((int)$ctx['tenant_id']);
        $tenantId = (int)$ctx['tenant_id'];

        $db->beginTransaction();
        try {
            // Serialize reversals. Without this lock, two concurrent voids can
            // both observe "completed" and each restore the stock.
            $stmt = $db->prepare(
                'SELECT * FROM moto_sales
                 WHERE tenant_id = :tid AND branch_id = :bid AND id = :id
                 LIMIT 1 FOR UPDATE'
            );
            $stmt->execute([':tid' => $tenantId, ':bid' => $branchId, ':id' => $saleId]);
            $sale = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!is_array($sale)) {
                throw new \InvalidArgumentException('Sale not found');
            }
            if ($sale['status'] !== 'completed') {
                throw new \RuntimeException('Sale is already voided');
            }

            $items = $db->prepare('SELECT * FROM moto_sale_items WHERE tenant_id = :tid AND sale_id = :sid');
            $items->execute([':tid' => $tenantId, ':sid' => $saleId]);
            foreach ($items->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $item) {
                StockService::applyDelta(
                    $db, $ctx, $branchId, (int)$item['product_id'], (float)$item['qty'],
                    $movementType,
                    'moto_sale', $saleId,
                    $reason . ':' . $sale['sale_ref'],
                    null,
                    true // restoring stock must never be blocked by a negative rule
                );
            }

            $db->prepare(
                'UPDATE moto_sales SET status = :status, voided_at = :vat, voided_by = :vby, voided_by_name = :vname, undo_deadline = NULL
                 WHERE tenant_id = :tid AND id = :id'
            )->execute([
                ':status' => 'voided',
                ':vat'    => date('Y-m-d H:i:s'),
                ':vby'    => (int)($ctx['user_id'] ?? 0) ?: null,
                ':vname'  => (string)($ctx['actor_name'] ?? ''),
                ':tid'    => $tenantId,
                ':id'     => $saleId,
            ]);

            $result = [
                'sale_id'   => $saleId,
                'sale_ref'  => $sale['sale_ref'],
                'status'    => 'voided',
                'voided_at' => date('Y-m-d H:i:s'),
            ];

            // Audit is atomic with the reversal: it commits (or rolls back)
            // together with the compensating movements and the status flip.
            moto_audit($ctx, 'moto_inventory.sale.voided', 'moto_sale', (string)$saleId, null, [
                'branch_id' => $branchId, 'sale_ref' => $sale['sale_ref'], 'total' => (float)$sale['total'],
            ], $branchId, $sale['idempotency_key'] ?: null, $db);

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        // Events are post-commit and best-effort (moto_emit_event never throws).
        moto_emit_event('moto_inventory.sale.voided', [
            'tenant_id' => $tenantId, 'branch_id' => $branchId, 'sale_id' => $saleId,
            'sale_ref' => $sale['sale_ref'], 'total' => (float)$sale['total'],
        ]);

        return $result;
    }

    /**
     * Sale history (completed + voided), paginated.
     */
    public static function history(array $ctx, array $filters = []): array
    {
        $db = moto_db((int)$ctx['tenant_id']);
        $tenantId = (int)$ctx['tenant_id'];

        $where = ['s.tenant_id = :tid'];
        $params = [':tid' => $tenantId];

        if (!empty($filters['branch_id']) && (int)$filters['branch_id'] > 0) {
            $where[] = 's.branch_id = :bid';
            $params[':bid'] = (int)$filters['branch_id'];
        }
        if (!empty($filters['from'])) {
            $where[] = 's.created_at >= :from';
            $params[':from'] = (string)$filters['from'] . ' 00:00:00';
        }
        if (!empty($filters['to'])) {
            $where[] = 's.created_at <= :to';
            $params[':to'] = (string)$filters['to'] . ' 23:59:59';
        }
        if (isset($filters['status']) && in_array($filters['status'], ['completed', 'voided'], true)) {
            $where[] = 's.status = :status';
            $params[':status'] = $filters['status'];
        }

        $perPage = max(1, min(250, (int)($filters['per_page'] ?? 50)));
        $page = max(1, (int)($filters['page'] ?? 1));
        $offset = ($page - 1) * $perPage;
        $whereSql = implode(' AND ', $where);

        $total = (int)$db->query("SELECT COUNT(*) FROM moto_sales s WHERE {$whereSql}", $params)->fetchColumn();

        $stmt = $db->query(
            "SELECT s.id, s.sale_ref, s.total, s.cost, s.profit, s.customer, s.override_flag, s.status,
                    s.voided_at, s.voided_by_name, s.created_by, s.created_by_name, s.created_at, s.branch_id,
                    b.name AS branch_name,
                    (SELECT COUNT(*) FROM moto_sale_items si WHERE si.sale_id = s.id) AS item_count,
                    (SELECT GROUP_CONCAT(CONCAT(si2.description, ' x', CAST(si2.qty AS CHAR)) SEPARATOR ', ')
                        FROM moto_sale_items si2 WHERE si2.sale_id = s.id ORDER BY si2.id LIMIT 20) AS item_summary
             FROM moto_sales s
             JOIN moto_branches b ON b.id = s.branch_id
             WHERE {$whereSql}
             ORDER BY s.id DESC
             LIMIT {$perPage} OFFSET {$offset}",
            $params
        );

        return [
            'rows'     => $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [],
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
            'pages'    => max(1, (int)ceil($total / $perPage)),
        ];
    }

    public static function saleById(array $ctx, int $saleId, ?int $branchId = null): ?array
    {
        $db = moto_db((int)$ctx['tenant_id']);
        $where = 's.tenant_id = :tid AND s.id = :id';
        $params = [':tid' => (int)$ctx['tenant_id'], ':id' => $saleId];
        if ($branchId !== null) {
            $where .= ' AND s.branch_id = :bid';
            $params[':bid'] = $branchId;
        }
        $stmt = $db->prepare(
            "SELECT s.*, b.name AS branch_name FROM moto_sales s JOIN moto_branches b ON b.id = s.branch_id WHERE {$where} LIMIT 1"
        );
        $stmt->execute($params);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $items = $db->prepare('SELECT * FROM moto_sale_items WHERE tenant_id = :tid AND sale_id = :sid ORDER BY id');
        $items->execute([':tid' => (int)$ctx['tenant_id'], ':sid' => $saleId]);
        $row['items'] = $items->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        return $row;
    }

    /**
     * Profit report. Voided sales are excluded from totals.
     *
     * @return array{range:string, sales_count:int, revenue:float, cost:float, profit:float, by_staff:array}
     */
    public static function profit(array $ctx, array $filters = []): array
    {
        $db = moto_db((int)$ctx['tenant_id']);
        $tenantId = (int)$ctx['tenant_id'];

        $where = ['s.tenant_id = :tid AND s.status = :status'];
        $params = [':tid' => $tenantId, ':status' => 'completed'];

        if (!empty($filters['branch_id']) && (int)$filters['branch_id'] > 0) {
            $where[] = 's.branch_id = :bid';
            $params[':bid'] = (int)$filters['branch_id'];
        }
        if (!empty($filters['from'])) {
            $where[] = 's.created_at >= :from';
            $params[':from'] = (string)$filters['from'] . ' 00:00:00';
        }
        if (!empty($filters['to'])) {
            $where[] = 's.created_at <= :to';
            $params[':to'] = (string)$filters['to'] . ' 23:59:59';
        }
        $whereSql = implode(' AND ', $where);

        $summary = $db->query(
            "SELECT COUNT(*) AS sales_count,
                    COALESCE(SUM(s.total), 0) AS revenue,
                    COALESCE(SUM(s.cost), 0) AS cost,
                    COALESCE(SUM(s.profit), 0) AS profit
             FROM moto_sales s
             WHERE {$whereSql}",
            $params
        )->fetch(\PDO::FETCH_ASSOC);

        $byStaff = $db->query(
            "SELECT COALESCE(NULLIF(s.created_by_name, ''), 'Unknown') AS staff,
                    COUNT(*) AS sales_count,
                    COALESCE(SUM(s.total), 0) AS revenue,
                    COALESCE(SUM(s.profit), 0) AS profit
             FROM moto_sales s
             WHERE {$whereSql}
             GROUP BY staff
             ORDER BY profit DESC",
            $params
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        return [
            'range'       => (string)($filters['range'] ?? 'custom'),
            'sales_count' => (int)($summary['sales_count'] ?? 0),
            'revenue'     => (float)($summary['revenue'] ?? 0),
            'cost'        => (float)($summary['cost'] ?? 0),
            'profit'      => (float)($summary['profit'] ?? 0),
            'by_staff'    => $byStaff,
        ];
    }

    /**
     * Lock and return the full product row within the current transaction.
     */
    private static function lockProductFull(\Ikabud\Kernel\Contracts\ModuleDB $db, int $tenantId, int $branchId, int $productId): array
    {
        $stmt = $db->prepare(
            "SELECT p.*, b.name AS brand FROM moto_products p
             JOIN moto_brands b ON b.id = p.brand_id
             WHERE p.tenant_id = :tid AND p.id = :id AND p.branch_id = :bid
             FOR UPDATE"
        );
        $stmt->execute([':tid' => $tenantId, ':id' => $productId, ':bid' => $branchId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new \InvalidArgumentException('Product not found in branch');
        }

        return $row;
    }

    private static function generateSaleRef(): string
    {
        return 'S-' . date('Ymd-His') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
    }
}
