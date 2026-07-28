<?php

declare(strict_types=1);

/**
 * Bakeshop — Production Execution Service
 *
 * Manages the production-run lifecycle with inventory ledger integration.
 *
 * States: draft -> released -> in_progress -> completed -> voided
 *
 * On completion: ingredient consumption and product-output movements are
 * recorded in the inventory ledger.
 */

class BakeshopProductionExecutionService
{
    private BakeshopInventoryLedgerService $ledger;
    private ?BakeshopOperationalPeriodService $periods;

    public function __construct(?BakeshopInventoryLedgerService $ledger = null, ?BakeshopOperationalPeriodService $periods = null)
    {
        $this->ledger = $ledger ?? new BakeshopInventoryLedgerService();
        $this->periods = $periods;
    }

    /**
     * Create a draft production run. No ledger impact.
     */
    public function createDraft(array $input): array
    {
        return bakeshopProductionCreate($input);
    }

    /**
     * Complete a production run: set status=completed, record movements.
     * Checks period-close before allowing completion.
     */
    public function complete(int $runId, array $items, float $qtyProduced, int $productId, int $expectedVersion, string $producedAt, ?int $userId = null): array
    {
        $db = bakeshopDb();

        $db->beginTransaction();
        try {
            $stmt = $db->prepare(
                'SELECT id, status, version, branch_id FROM bakeshop_production_runs WHERE id = :id FOR UPDATE'
            );
            $stmt->execute([':id' => $runId]);
            $run = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$run) {
                throw new \RuntimeException('Production run not found.');
            }
            $status = $run['status'] ?? 'draft';
            if (!in_array($status, ['draft', 'released', 'in_progress'], true)) {
                throw new \RuntimeException("Cannot complete production run in status '{$status}'.");
            }
            if ((int)$run['version'] !== $expectedVersion) {
                throw new \RuntimeException('Stale version: expected ' . $expectedVersion . ', current ' . $run['version']);
            }

            // Period-close guard: reject backdated posting to closed periods
            if ($this->periods !== null) {
                $dateOnly = substr($producedAt, 0, 10);
                $this->periods->requireDateOpen((int)$run['branch_id'], $dateOnly);
            }

            // Negative-stock guard: check ingredient balances before completing.
            // For each consumption item, verify the ledger balance is sufficient
            // to cover the deduction after all prior items in this batch.
            foreach ($items as $item) {
                $balance = $this->ledger->getBalance((int)$run['branch_id'], (int)$item['ingredient_id']);
                $deduction = abs((float)($item['qty_used'] ?? 0));
                if ($balance - $deduction < 0) {
                    throw new \RuntimeException(
                        'Insufficient ingredient stock: ingredient #' . (int)$item['ingredient_id']
                        . ' has balance ' . number_format($balance, 2)
                        . ' but production requires ' . number_format($deduction, 2)
                    );
                }
            }

            $upd = $db->prepare(
                "UPDATE bakeshop_production_runs SET status = 'completed', version = version + 1 WHERE id = :id AND version = :ver"
            );
            $upd->execute([':id' => $runId, ':ver' => $expectedVersion]);
            if ($upd->rowCount() === 0) {
                throw new \RuntimeException('Concurrent modification detected on production run ' . $runId);
            }

            // Record movements in ledger
            $this->ledger->recordProductionCompletion($runId, $items, $qtyProduced, $productId, $userId);

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        return bakeshopProductionFindById($runId, true) ?? [];
    }

    /**
     * Void a production run: set status=voided, record compensating movements.
     */
    public function void(int $runId, string $reason, int $expectedVersion, ?int $userId = null): array
    {
        $db = bakeshopDb();

        $db->beginTransaction();
        try {
            $stmt = $db->prepare(
                'SELECT id, status, version FROM bakeshop_production_runs WHERE id = :id FOR UPDATE'
            );
            $stmt->execute([':id' => $runId]);
            $run = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$run) {
                throw new \RuntimeException('Production run not found.');
            }
            if ($run['status'] !== 'completed') {
                throw new \RuntimeException("Cannot void production run in status '{$run['status']}'.");
            }
            if ((int)$run['version'] !== $expectedVersion) {
                throw new \RuntimeException('Stale version: expected ' . $expectedVersion . ', current ' . $run['version']);
            }

            $upd = $db->prepare(
                "UPDATE bakeshop_production_runs SET status = 'voided', voided_at = NOW(), voided_by = :uid, void_reason = :reason, version = version + 1 WHERE id = :id AND version = :ver"
            );
            $upd->execute([
                ':id' => $runId, ':ver' => $expectedVersion,
                ':reason' => $reason, ':uid' => $userId,
            ]);
            if ($upd->rowCount() === 0) {
                throw new \RuntimeException('Concurrent modification detected on production run ' . $runId);
            }

            // Record compensating movements
            $this->ledger->recordVoid('production', $runId, $reason, $userId);

            if (function_exists('app') && app()->events()) {
                app()->events()->fire('bakeshop.production.voided', [
                    'id' => $runId, 'reason' => $reason,
                ]);
            }

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        return bakeshopProductionFindById($runId, true) ?? [];
    }

    public function getVersion(int $runId): int
    {
        $stmt = bakeshopDb()->prepare('SELECT version FROM bakeshop_production_runs WHERE id = :id');
        $stmt->execute([':id' => $runId]);
        return (int)$stmt->fetchColumn();
    }
}
