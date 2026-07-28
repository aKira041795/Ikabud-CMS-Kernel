<?php

declare(strict_types=1);

/**
 * Bakeshop — Inventory Ledger Service
 *
 * Immutable append-only movement ledger for ingredient inventory.
 * All posted operational events create movement rows.
 * Movements are never updated or deleted — corrections create compensating entries.
 *
 * This is the source of truth for on-hand and available quantities.
 * Legacy bakeshop_products.stock_qty and inline SQL aggregates are replaced by
 * queries against bakeshop_inventory_movements.
 *
 * @mysql57-compat: All queries use MySQL 5.7 syntax (no window functions, no CTEs).
 */

class BakeshopInventoryLedgerService
{
    private \Ikabud\Kernel\Contracts\ModuleDB $db;
    private int $tenantId;

    public function __construct(?\Ikabud\Kernel\Contracts\ModuleDB $db = null)
    {
        $this->db = $db ?? bakeshopDb();
        $this->tenantId = (int)(app()->tenant()->current() ?? 0);
    }

    /**
     * Record a new inventory movement.
     * Returns the movement ID on success.
     *
     * @throws \RuntimeException if the movement cannot be recorded
     */
    public function recordMovement(array $params): int
    {
        $required = ['branch_id', 'ingredient_id', 'movement_type', 'reference_type', 'reference_id', 'qty', 'unit_id'];
        foreach ($required as $field) {
            if (!isset($params[$field])) {
                throw new \InvalidArgumentException("Missing required field: {$field}");
            }
        }

        $allowedTypes = ['receipt', 'production_issue', 'production_output', 'transfer_out', 'transfer_in', 'adjustment', 'void'];
        if (!in_array($params['movement_type'], $allowedTypes, true)) {
            throw new \InvalidArgumentException("Invalid movement_type: {$params['movement_type']}");
        }

        $stmt = $this->db->prepare(
            'INSERT INTO bakeshop_inventory_movements
             (tenant_id, branch_id, ingredient_id, movement_type, reference_type, reference_id,
              qty, unit_id, unit_cost, total_cost, description, created_by, created_at)
             VALUES (:tid, :bid, :iid, :mt, :rt, :rid, :qty, :uid, :uc, :tc, :desc, :cb, NOW())'
        );
        $stmt->execute([
            ':tid' => $this->tenantId,
            ':bid' => (int)$params['branch_id'],
            ':iid' => (int)$params['ingredient_id'],
            ':mt' => $params['movement_type'],
            ':rt' => $params['reference_type'],
            ':rid' => (int)$params['reference_id'],
            ':qty' => (float)$params['qty'],
            ':uid' => (int)$params['unit_id'],
            ':uc' => isset($params['unit_cost']) ? (float)$params['unit_cost'] : null,
            ':tc' => isset($params['total_cost']) ? (float)$params['total_cost'] : null,
            ':desc' => $params['description'] ?? null,
            ':cb' => isset($params['created_by']) ? (int)$params['created_by'] : null,
        ]);

        return (int)$this->db->lastInsertId();
    }

    /**
     * Record movements for a delivery posting.
     * Each delivery item becomes one receipt movement (positive qty).
     */
    public function recordDeliveryPosting(int $deliveryId, array $items, ?int $userId = null): void
    {
        foreach ($items as $item) {
            $this->recordMovement([
                'branch_id' => (int)$item['branch_id'],
                'ingredient_id' => (int)$item['ingredient_id'],
                'movement_type' => 'receipt',
                'reference_type' => 'delivery',
                'reference_id' => $deliveryId,
                'qty' => (float)$item['qty'],
                'unit_id' => (int)$item['unit_id'],
                'unit_cost' => isset($item['unit_cost']) ? (float)$item['unit_cost'] : null,
                'total_cost' => isset($item['unit_cost']) ? round((float)$item['qty'] * (float)$item['unit_cost'], 2) : null,
                'description' => 'Delivery #' . $deliveryId . ' — ' . ($item['ingredient_name'] ?? ''),
                'created_by' => $userId,
            ]);
        }
    }

    /**
     * Record movements for a production completion.
     * Creates production_issue movements for consumed ingredients (negative qty)
     * and production_output movement for the finished product.
     */
    public function recordProductionCompletion(int $runId, array $items, float $qtyProduced, int $productId, ?int $userId = null): void
    {
        // Ingredient consumption (negative quantities)
        foreach ($items as $item) {
            $this->recordMovement([
                'branch_id' => (int)$item['branch_id'],
                'ingredient_id' => (int)$item['ingredient_id'],
                'movement_type' => 'production_issue',
                'reference_type' => 'production',
                'reference_id' => $runId,
                'qty' => -abs((float)$item['qty_used']),
                'unit_id' => (int)$item['unit_id'],
                'description' => 'Production #' . $runId . ' — ' . ($item['ingredient_name'] ?? ''),
                'created_by' => $userId,
            ]);
        }

        // Finished product output (positive quantity — product treated as ingredient-equivalent)
        $this->recordMovement([
            'branch_id' => (int)$items[0]['branch_id'],
            'ingredient_id' => $productId,
            'movement_type' => 'production_output',
            'reference_type' => 'production',
            'reference_id' => $runId,
            'qty' => $qtyProduced,
            'unit_id' => (int)$items[0]['unit_id'],
            'description' => 'Production output #' . $runId,
            'created_by' => $userId,
        ]);
    }

    /**
     * Record compensating movements for a voided document.
     * Reverses all original movements by creating opposite-signed entries.
     */
    public function recordVoid(string $referenceType, int $referenceId, string $reason, ?int $userId = null): void
    {
        // Fetch original movements
        $stmt = $this->db->prepare(
            'SELECT id, branch_id, ingredient_id, movement_type, qty, unit_id, unit_cost
             FROM bakeshop_inventory_movements
             WHERE reference_type = :rt AND reference_id = :rid
             ORDER BY id ASC'
        );
        $stmt->execute([':rt' => $referenceType, ':rid' => $referenceId]);
        $originals = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($originals as $orig) {
            $this->recordMovement([
                'branch_id' => $orig['branch_id'],
                'ingredient_id' => $orig['ingredient_id'],
                'movement_type' => 'void',
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'qty' => -(float)$orig['qty'], // Opposite sign
                'unit_id' => $orig['unit_id'],
                'unit_cost' => $orig['unit_cost'] !== null ? (float)$orig['unit_cost'] : null,
                'description' => 'Void — ' . $reason,
                'created_by' => $userId,
            ]);
        }
    }

    /**
     * Get current balance for a specific ingredient at a branch.
     * Sums all non-void movements. Returns 0 if no movements exist.
     */
    public function getBalance(int $branchId, int $ingredientId): float
    {
        $stmt = $this->db->prepare(
            'SELECT COALESCE(SUM(qty), 0) FROM bakeshop_inventory_movements
             WHERE branch_id = :bid AND ingredient_id = :iid'
        );
        $stmt->execute([':bid' => $branchId, ':iid' => $ingredientId]);
        return (float)$stmt->fetchColumn();
    }

    /**
     * Get all movements for a specific ingredient at a branch, ordered by date.
     */
    public function getMovements(int $branchId, int $ingredientId, int $limit = 100, int $offset = 0): array
    {
        $stmt = $this->db->prepare(
            'SELECT m.*, u.code AS unit_code
             FROM bakeshop_inventory_movements m
             LEFT JOIN bakeshop_units u ON u.id = m.unit_id
             WHERE m.branch_id = :bid AND m.ingredient_id = :iid
             ORDER BY m.created_at DESC, m.id DESC
             LIMIT :lim OFFSET :off'
        );
        $stmt->bindValue(':bid', $branchId, \PDO::PARAM_INT);
        $stmt->bindValue(':iid', $ingredientId, \PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Reconcile ledger totals against a known expected quantity.
     * Returns the difference (ledger - expected). Zero means in sync.
     */
    public function reconcile(int $branchId, int $ingredientId, float $expectedQty): float
    {
        return $this->getBalance($branchId, $ingredientId) - $expectedQty;
    }

    /**
     * Get total movements by type for a branch within a date range.
     */
    public function getMovementsByType(int $branchId, string $startDate, string $endDate): array
    {
        $stmt = $this->db->prepare(
            'SELECT movement_type, COUNT(*) AS cnt, SUM(qty) AS total_qty
             FROM bakeshop_inventory_movements
             WHERE branch_id = :bid
               AND created_at >= :start AND created_at < :end + INTERVAL 1 DAY
             GROUP BY movement_type
             ORDER BY movement_type'
        );
        $stmt->execute([
            ':bid' => $branchId,
            ':start' => $startDate,
            ':end' => $endDate,
        ]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Get next document number for a branch/type/year.
     * Atomically increments the counter.
     */
    public function nextDocumentNumber(int $branchId, string $docType): string
    {
        $year = (int)date('Y');

        $this->db->prepare(
            'INSERT INTO bakeshop_document_numbers (branch_id, doc_type, year, next_number)
             VALUES (:bid, :dt, :yr, 2)
             ON DUPLICATE KEY UPDATE next_number = next_number + 1'
        )->execute([
            ':bid' => $branchId,
            ':dt' => $docType,
            ':yr' => $year,
        ]);

        $stmt = $this->db->prepare(
            'SELECT next_number - 1 AS num FROM bakeshop_document_numbers
             WHERE branch_id = :bid AND doc_type = :dt AND year = :yr'
        );
        $stmt->execute([':bid' => $branchId, ':dt' => $docType, ':yr' => $year]);
        $num = (int)$stmt->fetchColumn();

        return sprintf('%s-%s-%04d', strtoupper($docType), date('y'), $num);
    }
}
