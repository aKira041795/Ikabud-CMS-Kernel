<?php

declare(strict_types=1);

/**
 * Bakeshop — Stocktake Service
 *
 * Physical inventory counting with review/post workflow.
 * Variance does not affect inventory until posted.
 *
 * States: draft -> counted -> reviewed -> posted | cancelled
 */

class BakeshopStocktakeService
{
    private BakeshopInventoryLedgerService $ledger;
    private ?BakeshopOperationalPeriodService $periods;

    public function __construct(?BakeshopInventoryLedgerService $ledger = null, ?BakeshopOperationalPeriodService $periods = null)
    {
        $this->ledger = $ledger ?? new BakeshopInventoryLedgerService();
        $this->periods = $periods;
    }

    /**
     * Create a draft stocktake session and populate expected quantities from the ledger.
     */
    public function createDraft(int $branchId, string $stocktakeDate, ?int $userId = null): array
    {
        $db = bakeshopDb();
        $db->beginTransaction();
        try {
            $stmt = $db->prepare(
                'INSERT INTO bakeshop_stocktake_sessions (branch_id, stocktake_date, created_by, created_at)
                 VALUES (:bid, :dt, :cb, NOW())'
            );
            $stmt->execute([':bid' => $branchId, ':dt' => $stocktakeDate, ':cb' => $userId]);
            $sessionId = (int)$db->lastInsertId();

            // Populate with all active ingredients at this branch
            $ingredients = $db->query(
                'SELECT id FROM bakeshop_ingredients WHERE is_active = 1'
            )->fetchAll(\PDO::FETCH_COLUMN);

            $ins = $db->prepare(
                'INSERT INTO bakeshop_stocktake_items (session_id, ingredient_id, expected_qty, unit_id)
                 VALUES (:sid, :iid, :eq, (SELECT COALESCE(default_unit_id, 0) FROM bakeshop_ingredients WHERE id = :iid2))'
            );
            foreach ($ingredients as $iid) {
                $balance = $this->ledger->getBalance($branchId, (int)$iid);
                $ins->execute([
                    ':sid' => $sessionId,
                    ':iid' => (int)$iid,
                    ':eq' => $balance,
                    ':iid2' => (int)$iid,
                ]);
            }

            $db->commit();
            return $this->findById($sessionId);
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    /**
     * Record a counted quantity for an item.
     */
    public function recordCount(int $sessionId, int $itemId, float $countedQty, int $expectedVersion): array
    {
        $db = bakeshopDb();
        $upd = $db->prepare(
            "UPDATE bakeshop_stocktake_items SET counted_qty = :cq, version = version + 1 WHERE id = :id AND version = :ver"
        );
        $upd->execute([':cq' => $countedQty, ':id' => $itemId, ':ver' => $expectedVersion]);
        if ($upd->rowCount() === 0) throw new \RuntimeException('Stale version on stocktake item.');
        return $this->findById($sessionId);
    }

    /**
     * Mark a session as counted (all items recorded).
     */
    public function markCounted(int $sessionId, int $expectedVersion, ?int $userId = null): array
    {
        $db = bakeshopDb();
        $db->beginTransaction();
        try {
            $stmt = $db->prepare('SELECT * FROM bakeshop_stocktake_sessions WHERE id = :id FOR UPDATE');
            $stmt->execute([':id' => $sessionId]);
            $session = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$session) throw new \RuntimeException('Stocktake session not found.');
            if ($session['status'] !== 'draft') {
                throw new \RuntimeException("Cannot count session in status '{$session['status']}'.");
            }
            if ((int)$session['version'] !== $expectedVersion) {
                throw new \RuntimeException('Stale version.');
            }

            $upd = $db->prepare(
                "UPDATE bakeshop_stocktake_sessions SET status = 'counted', counted_at = NOW(), counted_by = :uid, version = version + 1 WHERE id = :id AND version = :ver"
            );
            $upd->execute([':id' => $sessionId, ':ver' => $expectedVersion, ':uid' => $userId]);
            if ($upd->rowCount() === 0) throw new \RuntimeException('Concurrent modification.');
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
        return $this->findById($sessionId);
    }

    /**
     * Review a counted session (approve variances).
     */
    public function markReviewed(int $sessionId, int $expectedVersion, ?int $userId = null): array
    {
        $db = bakeshopDb();
        $db->beginTransaction();
        try {
            $stmt = $db->prepare('SELECT * FROM bakeshop_stocktake_sessions WHERE id = :id FOR UPDATE');
            $stmt->execute([':id' => $sessionId]);
            $session = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$session) throw new \RuntimeException('Stocktake session not found.');
            if ($session['status'] !== 'counted') {
                throw new \RuntimeException("Cannot review session in status '{$session['status']}'.");
            }
            if ((int)$session['version'] !== $expectedVersion) {
                throw new \RuntimeException('Stale version.');
            }

            $upd = $db->prepare(
                "UPDATE bakeshop_stocktake_sessions SET status = 'reviewed', reviewed_at = NOW(), reviewed_by = :uid, version = version + 1 WHERE id = :id AND version = :ver"
            );
            $upd->execute([':id' => $sessionId, ':ver' => $expectedVersion, ':uid' => $userId]);
            if ($upd->rowCount() === 0) throw new \RuntimeException('Concurrent modification.');
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
        return $this->findById($sessionId);
    }

    /**
     * Post a reviewed session: record adjustment movements for each variance.
     */
    public function post(int $sessionId, int $expectedVersion, ?int $userId = null): array
    {
        $db = bakeshopDb();
        $db->beginTransaction();
        try {
            $stmt = $db->prepare('SELECT * FROM bakeshop_stocktake_sessions WHERE id = :id FOR UPDATE');
            $stmt->execute([':id' => $sessionId]);
            $session = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$session) throw new \RuntimeException('Stocktake session not found.');
            if ($session['status'] !== 'reviewed') {
                throw new \RuntimeException("Cannot post session in status '{$session['status']}'.");
            }
            if ((int)$session['version'] !== $expectedVersion) {
                throw new \RuntimeException('Stale version.');
            }

            // Period-close guard
            if ($this->periods !== null) {
                $this->periods->requireDateOpen((int)$session['branch_id'], $session['stocktake_date']);
            }

            $upd = $db->prepare(
                "UPDATE bakeshop_stocktake_sessions SET status = 'posted', posted_at = NOW(), posted_by = :uid, version = version + 1 WHERE id = :id AND version = :ver"
            );
            $upd->execute([':id' => $sessionId, ':ver' => $expectedVersion, ':uid' => $userId]);
            if ($upd->rowCount() === 0) throw new \RuntimeException('Concurrent modification.');

            // Fetch items to record movements
            $iStmt = $db->prepare('SELECT * FROM bakeshop_stocktake_items WHERE session_id = :sid');
            $iStmt->execute([':sid' => $sessionId]);
            $items = $iStmt->fetchAll(\PDO::FETCH_ASSOC);

            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }

        // Record adjustment movements for each variance (after tx)
        foreach ($items as $item) {
            $variance = (float)$item['variance_qty'];
            if (abs($variance) < 0.001) continue; // Skip zero-variance items

            $this->ledger->recordMovement([
                'branch_id' => (int)$session['branch_id'],
                'ingredient_id' => (int)$item['ingredient_id'],
                'movement_type' => 'adjustment',
                'reference_type' => 'stocktake',
                'reference_id' => $sessionId,
                'qty' => $variance,
                'unit_id' => (int)$item['unit_id'],
                'description' => 'Stocktake #' . $sessionId . ' variance: counted ' . (float)$item['counted_qty'] . ' vs expected ' . (float)$item['expected_qty'],
                'created_by' => $userId,
            ]);
        }

        return $this->findById($sessionId);
    }

    public function getVersion(int $id): int
    {
        $stmt = bakeshopDb()->prepare('SELECT version FROM bakeshop_stocktake_sessions WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return (int)$stmt->fetchColumn();
    }

    private function findById(int $id): array
    {
        $stmt = bakeshopDb()->prepare('SELECT * FROM bakeshop_stocktake_sessions WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $session = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        if (!empty($session)) {
            $iStmt = bakeshopDb()->prepare('SELECT * FROM bakeshop_stocktake_items WHERE session_id = :sid');
            $iStmt->execute([':sid' => $id]);
            $session['items'] = $iStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        }
        return $session;
    }
}
