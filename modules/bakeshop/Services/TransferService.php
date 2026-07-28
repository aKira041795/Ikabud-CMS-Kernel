<?php

declare(strict_types=1);

/**
 * Bakeshop — Transfer Service
 *
 * Balanced inter-branch ingredient transfers.
 *
 * States: draft -> dispatched -> received | cancelled
 *
 * Dispatch: removes stock from source branch (transfer_out movement).
 * Receive: adds stock to destination branch (transfer_in movement).
 * Both legs use the same normalized quantity so the transfer is balanced.
 */

class BakeshopTransferService
{
    private BakeshopInventoryLedgerService $ledger;
    private ?BakeshopOperationalPeriodService $periods;

    public function __construct(?BakeshopInventoryLedgerService $ledger = null, ?BakeshopOperationalPeriodService $periods = null)
    {
        $this->ledger = $ledger ?? new BakeshopInventoryLedgerService();
        $this->periods = $periods;
    }

    /**
     * Create a draft transfer. No ledger impact.
     */
    public function createDraft(array $input): array
    {
        $db = bakeshopDb();
        $branchId = (int)($input['branch_id'] ?? 0);
        $destBranchId = (int)($input['destination_branch_id'] ?? 0);
        $transferDate = trim((string)($input['transfer_date'] ?? date('Y-m-d')));

        if ($branchId === $destBranchId) {
            throw new \InvalidArgumentException('Source and destination branches must be different.');
        }
        if ($branchId <= 0 || $destBranchId <= 0) {
            throw new \InvalidArgumentException('Valid branch IDs are required.');
        }

        $db->beginTransaction();
        try {
            $stmt = $db->prepare(
                'INSERT INTO bakeshop_transfers (branch_id, destination_branch_id, transfer_date, notes, created_by, created_at)
                 VALUES (:bid, :dest, :dt, :notes, :cb, NOW())'
            );
            $stmt->execute([
                ':bid' => $branchId,
                ':dest' => $destBranchId,
                ':dt' => $transferDate,
                ':notes' => trim((string)($input['notes'] ?? '')),
                ':cb' => isset($input['created_by']) ? (int)$input['created_by'] : null,
            ]);
            $id = (int)$db->lastInsertId();
            $db->commit();
            return $this->findById($id);
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    /**
     * Add an item to a draft transfer.
     */
    public function addItem(int $transferId, int $ingredientId, float $qty, int $unitId, ?float $unitCost = null): array
    {
        $db = bakeshopDb();
        $db->prepare(
            'INSERT INTO bakeshop_transfer_items (transfer_id, ingredient_id, qty, unit_id, unit_cost, line_amount)
             VALUES (:tid, :iid, :qty, :uid, :uc, :la)'
        )->execute([
            ':tid' => $transferId,
            ':iid' => $ingredientId,
            ':qty' => $qty,
            ':uid' => $unitId,
            ':uc' => $unitCost,
            ':la' => $unitCost !== null ? round($qty * $unitCost, 2) : null,
        ]);
        return $this->findById($transferId);
    }

    /**
     * Dispatch a transfer: validate stock, record transfer_out movement.
     */
    public function dispatch(int $transferId, int $expectedVersion, ?int $userId = null): array
    {
        $db = bakeshopDb();
        $db->beginTransaction();
        try {
            $stmt = $db->prepare(
                'SELECT t.*, ti.id AS item_id, ti.ingredient_id, ti.qty, ti.unit_id, ti.unit_cost
                 FROM bakeshop_transfers t
                 INNER JOIN bakeshop_transfer_items ti ON ti.transfer_id = t.id
                 WHERE t.id = :id FOR UPDATE'
            );
            $stmt->execute([':id' => $transferId]);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            if (empty($rows)) throw new \RuntimeException('Transfer not found.');
            $transfer = $rows[0]; // header data from first row

            if ($transfer['status'] !== 'draft') {
                throw new \RuntimeException("Cannot dispatch transfer in status '{$transfer['status']}'.");
            }
            if ((int)$transfer['version'] !== $expectedVersion) {
                throw new \RuntimeException('Stale version.');
            }

            // Period-close guard
            if ($this->periods !== null) {
                $this->periods->requireDateOpen((int)$transfer['branch_id'], $transfer['transfer_date']);
            }

            // Check stock sufficiency for each item
            foreach ($rows as $row) {
                $balance = $this->ledger->getBalance((int)$transfer['branch_id'], (int)$row['ingredient_id']);
                $qty = (float)$row['qty'];
                if ($balance - $qty < 0) {
                    throw new \RuntimeException(
                        'Insufficient stock of ingredient #' . (int)$row['ingredient_id']
                        . ' at source branch: balance ' . number_format($balance, 2)
                        . ', transfer qty ' . number_format($qty, 2)
                    );
                }
            }

            // Update status
            $upd = $db->prepare(
                "UPDATE bakeshop_transfers SET status = 'dispatched', version = version + 1 WHERE id = :id AND version = :ver"
            );
            $upd->execute([':id' => $transferId, ':ver' => $expectedVersion]);
            if ($upd->rowCount() === 0) throw new \RuntimeException('Concurrent modification.');

            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }

        // Record transfer_out movements (after tx: source branch deduction)
        foreach ($rows as $row) {
            $this->ledger->recordMovement([
                'branch_id' => (int)$transfer['branch_id'],
                'ingredient_id' => (int)$row['ingredient_id'],
                'movement_type' => 'transfer_out',
                'reference_type' => 'transfer',
                'reference_id' => $transferId,
                'qty' => -(float)$row['qty'],
                'unit_id' => (int)$row['unit_id'],
                'unit_cost' => $row['unit_cost'] !== null ? (float)$row['unit_cost'] : null,
                'description' => 'Transfer #' . $transferId . ' to branch #' . (int)$transfer['destination_branch_id'],
                'created_by' => $userId,
            ]);
        }

        return $this->findById($transferId);
    }

    /**
     * Receive a dispatched transfer: record transfer_in movement at destination.
     */
    public function receive(int $transferId, int $expectedVersion, ?int $userId = null): array
    {
        $db = bakeshopDb();
        $db->beginTransaction();
        try {
            $stmt = $db->prepare(
                'SELECT t.*, ti.id AS item_id, ti.ingredient_id, ti.qty, ti.unit_id, ti.unit_cost
                 FROM bakeshop_transfers t
                 INNER JOIN bakeshop_transfer_items ti ON ti.transfer_id = t.id
                 WHERE t.id = :id FOR UPDATE'
            );
            $stmt->execute([':id' => $transferId]);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            if (empty($rows)) throw new \RuntimeException('Transfer not found.');
            $transfer = $rows[0];

            if ($transfer['status'] !== 'dispatched') {
                throw new \RuntimeException("Cannot receive transfer in status '{$transfer['status']}'.");
            }
            if ((int)$transfer['version'] !== $expectedVersion) {
                throw new \RuntimeException('Stale version.');
            }

            // Period-close guard at destination
            if ($this->periods !== null) {
                $this->periods->requireDateOpen((int)$transfer['destination_branch_id'], $transfer['transfer_date']);
            }

            $upd = $db->prepare(
                "UPDATE bakeshop_transfers SET status = 'received', received_at = NOW(), received_by = :uid, version = version + 1 WHERE id = :id AND version = :ver"
            );
            $upd->execute([':id' => $transferId, ':ver' => $expectedVersion, ':uid' => $userId]);
            if ($upd->rowCount() === 0) throw new \RuntimeException('Concurrent modification.');

            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }

        // Record transfer_in movements at destination
        foreach ($rows as $row) {
            $this->ledger->recordMovement([
                'branch_id' => (int)$transfer['destination_branch_id'],
                'ingredient_id' => (int)$row['ingredient_id'],
                'movement_type' => 'transfer_in',
                'reference_type' => 'transfer',
                'reference_id' => $transferId,
                'qty' => (float)$row['qty'],
                'unit_id' => (int)$row['unit_id'],
                'unit_cost' => $row['unit_cost'] !== null ? (float)$row['unit_cost'] : null,
                'description' => 'Transfer #' . $transferId . ' from branch #' . (int)$transfer['branch_id'],
                'created_by' => $userId,
            ]);
        }

        return $this->findById($transferId);
    }

    /**
     * Cancel a draft/dispatched transfer.
     */
    public function cancel(int $transferId, string $reason, int $expectedVersion, ?int $userId = null): array
    {
        $db = bakeshopDb();
        $db->beginTransaction();
        try {
            $stmt = $db->prepare('SELECT * FROM bakeshop_transfers WHERE id = :id FOR UPDATE');
            $stmt->execute([':id' => $transferId]);
            $transfer = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$transfer) throw new \RuntimeException('Transfer not found.');
            if (!in_array($transfer['status'], ['draft', 'dispatched'], true)) {
                throw new \RuntimeException("Cannot cancel transfer in status '{$transfer['status']}'.");
            }
            if ((int)$transfer['version'] !== $expectedVersion) {
                throw new \RuntimeException('Stale version.');
            }

            $upd = $db->prepare(
                "UPDATE bakeshop_transfers SET status = 'cancelled', cancelled_at = NOW(), cancelled_by = :uid, cancel_reason = :reason, version = version + 1 WHERE id = :id AND version = :ver"
            );
            $upd->execute([':id' => $transferId, ':ver' => $expectedVersion, ':uid' => $userId, ':reason' => $reason]);
            if ($upd->rowCount() === 0) throw new \RuntimeException('Concurrent modification.');

            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }

        // If dispatched, reverse the transfer_out movements
        if ($transfer['status'] === 'dispatched') {
            $this->ledger->recordVoid('transfer', $transferId, $reason, $userId);
        }

        return $this->findById($transferId);
    }

    public function getVersion(int $id): int
    {
        $stmt = bakeshopDb()->prepare('SELECT version FROM bakeshop_transfers WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return (int)$stmt->fetchColumn();
    }

    private function findById(int $id): array
    {
        $stmt = bakeshopDb()->prepare('SELECT * FROM bakeshop_transfers WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $transfer = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        if (!empty($transfer)) {
            $iStmt = bakeshopDb()->prepare('SELECT * FROM bakeshop_transfer_items WHERE transfer_id = :tid');
            $iStmt->execute([':tid' => $id]);
            $transfer['items'] = $iStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        }
        return $transfer;
    }
}
