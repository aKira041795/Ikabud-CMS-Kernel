<?php

declare(strict_types=1);

/**
 * Bakeshop — Inventory Adjustment Service
 *
 * Manages the adjustment lifecycle with inventory ledger integration.
 *
 * States: draft -> posted -> voided
 *
 * On posting: adjustment movements are recorded in the inventory ledger.
 * On void: compensating movements reverse the original adjustment.
 */

class BakeshopInventoryAdjustmentService
{
    private BakeshopInventoryLedgerService $ledger;
    private ?BakeshopOperationalPeriodService $periods;

    public function __construct(?BakeshopInventoryLedgerService $ledger = null, ?BakeshopOperationalPeriodService $periods = null)
    {
        $this->ledger = $ledger ?? new BakeshopInventoryLedgerService();
        $this->periods = $periods;
    }

    /**
     * Create a draft adjustment. No ledger impact.
     */
    public function createDraft(array $input): array
    {
        return bakeshopAdjustmentCreate($input);
    }

    /**
     * Post an adjustment: set status=posted, record movement in ledger.
     */
    public function post(int $adjustmentId, int $expectedVersion, ?int $userId = null): array
    {
        $db = bakeshopDb();

        $db->beginTransaction();
        try {
            $stmt = $db->prepare(
                'SELECT a.id, a.status, a.version, a.branch_id, a.ingredient_id, a.qty, a.unit_id
                 FROM bakeshop_inventory_adjustments a WHERE a.id = :id FOR UPDATE'
            );
            $stmt->execute([':id' => $adjustmentId]);
            $adj = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$adj) {
                throw new \RuntimeException('Adjustment not found.');
            }
            if ($adj['status'] !== 'draft') {
                throw new \RuntimeException("Cannot post adjustment in status '{$adj['status']}'.");
            }
            if ((int)$adj['version'] !== $expectedVersion) {
                throw new \RuntimeException('Stale version: expected ' . $expectedVersion . ', current ' . $adj['version']);
            }

            // Period-close guard: reject posting to closed periods
            if ($this->periods !== null) {
                $dateOnly = substr((string)($adj['adjustment_date'] ?? ''), 0, 10);
                if ($dateOnly !== '') {
                    $this->periods->requireDateOpen((int)$adj['branch_id'], $dateOnly);
                }
            }

            // Negative-stock guard for deduction adjustments
            $adjQty = (float)$adj['qty'];
            if ($adjQty < 0) {
                $balance = $this->ledger->getBalance((int)$adj['branch_id'], (int)$adj['ingredient_id']);
                if ($balance + $adjQty < 0) {
                    throw new \RuntimeException(
                        'Insufficient stock: ingredient #' . (int)$adj['ingredient_id']
                        . ' has balance ' . number_format($balance, 2)
                        . ' but adjustment deducts ' . number_format(abs($adjQty), 2)
                    );
                }
            }

            $upd = $db->prepare(
                "UPDATE bakeshop_inventory_adjustments SET status = 'posted', version = version + 1 WHERE id = :id AND version = :ver"
            );
            $upd->execute([':id' => $adjustmentId, ':ver' => $expectedVersion]);
            if ($upd->rowCount() === 0) {
                throw new \RuntimeException('Concurrent modification detected on adjustment ' . $adjustmentId);
            }

            // Record movement in ledger
            $this->ledger->recordMovement([
                'branch_id' => (int)$adj['branch_id'],
                'ingredient_id' => (int)$adj['ingredient_id'],
                'movement_type' => 'adjustment',
                'reference_type' => 'adjustment',
                'reference_id' => $adjustmentId,
                'qty' => (float)$adj['qty'],
                'unit_id' => (int)$adj['unit_id'],
                'description' => 'Adjustment #' . $adjustmentId,
                'created_by' => $userId,
            ]);

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        return $this->findById($adjustmentId);
    }

    /**
     * Void a posted adjustment: set status=voided, record compensating movement.
     */
    public function void(int $adjustmentId, string $reason, int $expectedVersion, ?int $userId = null): array
    {
        $db = bakeshopDb();

        $db->beginTransaction();
        try {
            $stmt = $db->prepare(
                'SELECT id, status, version FROM bakeshop_inventory_adjustments WHERE id = :id FOR UPDATE'
            );
            $stmt->execute([':id' => $adjustmentId]);
            $adj = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$adj) {
                throw new \RuntimeException('Adjustment not found.');
            }
            if ($adj['status'] !== 'posted') {
                throw new \RuntimeException("Cannot void adjustment in status '{$adj['status']}'.");
            }
            if ((int)$adj['version'] !== $expectedVersion) {
                throw new \RuntimeException('Stale version: expected ' . $expectedVersion . ', current ' . $adj['version']);
            }

            $upd = $db->prepare(
                "UPDATE bakeshop_inventory_adjustments SET status = 'voided', voided_at = NOW(), voided_by = :uid, void_reason = :reason, version = version + 1 WHERE id = :id AND version = :ver"
            );
            $upd->execute([
                ':id' => $adjustmentId, ':ver' => $expectedVersion,
                ':reason' => $reason, ':uid' => $userId,
            ]);
            if ($upd->rowCount() === 0) {
                throw new \RuntimeException('Concurrent modification detected on adjustment ' . $adjustmentId);
            }

            $this->ledger->recordVoid('adjustment', $adjustmentId, $reason, $userId);

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        return $this->findById($adjustmentId);
    }

    public function getVersion(int $adjustmentId): int
    {
        $stmt = bakeshopDb()->prepare('SELECT version FROM bakeshop_inventory_adjustments WHERE id = :id');
        $stmt->execute([':id' => $adjustmentId]);
        return (int)$stmt->fetchColumn();
    }

    private function findById(int $id): array
    {
        $stmt = bakeshopDb()->prepare(
            'SELECT * FROM bakeshop_inventory_adjustments WHERE id = :id'
        );
        $stmt->execute([':id' => $id]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
    }
}
