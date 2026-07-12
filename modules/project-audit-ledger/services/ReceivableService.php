<?php

declare(strict_types=1);

/**
 * Receivable Service — Manages expected payments (receivables).
 *
 * A receivable represents an amount owed by a client for an invoice.
 * It is created when an invoice is issued and is settled when payments
 * are received and allocated to it.
 *
 * This separates the concept of "money expected" (receivable) from
 * "money received" (payment/collection), which were previously conflated
 * in the pal_collections table.
 *
 * Lifecycle:
 *   Invoice issued → Receivable created (status: pending)
 *   Payment received → allocated to receivable (status: partial or settled)
 *   Full payment → status: settled
 *   Past due → status: overdue
 *   Cancelled/voided → status: cancelled/voided
 */
class palReceivableService
{
    private Ikabud\Kernel\Contracts\ModuleDB $db;
    private int $tenantId;
    private int $userId;

    public function __construct(Ikabud\Kernel\Contracts\ModuleDB $db, int $tenantId, int $userId)
    {
        $this->db = $db;
        $this->tenantId = $tenantId;
        $this->userId = $userId;
    }

    /**
     * Create a receivable from an invoice.
     *
     * @param int $saleId
     * @param int|null $projectId
     * @param int|null $clientId
     * @param float $amount Total amount due
     * @param string $dueDate Due date (Y-m-d)
     * @param string $type Receivable type
     * @param int|null $installmentNumber For installment schedules
     * @return int New receivable ID
     */
    public function createFromInvoice(
        int $saleId,
        ?int $projectId,
        ?int $clientId,
        float $amount,
        string $dueDate,
        string $type = 'full',
        ?int $installmentNumber = null,
    ): int {
        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM pal_receivables WHERE tenant_id = :tid");
        $countStmt->execute([':tid' => $this->tenantId]);
        $prefix = 'RCV';
        $rcvNum = $prefix . '-' . date('Ymd') . '-' . str_pad((string)((int)$countStmt->fetchColumn() + 1), 4, '0', STR_PAD_LEFT);

        $stmt = $this->db->prepare(
            "INSERT INTO pal_receivables
                (tenant_id, receivable_number, sales_id, project_id, client_id,
                 due_date, amount, receivable_type, installment_number, status, created_by)
             VALUES (:t, :rn, :si, :pj, :cl, :dd, :amt, :rt, :inst, 'pending', :cb)"
        );
        $stmt->execute([
            ':t' => $this->tenantId,
            ':rn' => $rcvNum,
            ':si' => $saleId,
            ':pj' => $projectId,
            ':cl' => $clientId,
            ':dd' => $dueDate,
            ':amt' => $amount,
            ':rt' => $type,
            ':inst' => $installmentNumber,
            ':cb' => $this->userId,
        ]);
        $newId = (int)$this->db->lastInsertId();

        palAudit('pal.receivable.created', $this->userId, 'pal_receivables', (string)$newId,
            null, ['sales_id' => $saleId, 'amount' => $amount, 'type' => $type]);

        return $newId;
    }

    /**
     * Allocate a payment (collection) to a receivable.
     * Updates both the receivable amount_paid and the collection's payment allocation.
     *
     * @param int $receivableId
     * @param int $collectionId
     * @param float $amount Amount to allocate
     * @throws InvalidArgumentException if allocation exceeds outstanding balance
     */
    public function allocatePayment(int $receivableId, int $collectionId, float $amount): void
    {
        $rcv = $this->get($receivableId);
        if ($rcv === null) {
            throw new InvalidArgumentException('Receivable not found.');
        }

        $outstanding = (float)$rcv['outstanding'];
        if ($amount <= 0) {
            throw new InvalidArgumentException('Allocation amount must be positive.');
        }
        if ($amount > $outstanding) {
            throw new InvalidArgumentException(
                "Allocation amount ({$amount}) exceeds outstanding balance ({$outstanding})."
            );
        }

        $this->db->beginTransaction();
        try {
            // Insert allocation record
            $allocStmt = $this->db->prepare(
                "INSERT INTO pal_receivable_payments (tenant_id, receivable_id, collection_id, amount)
                 VALUES (:t, :ri, :ci, :amt)"
            );
            $allocStmt->execute([
                ':t' => $this->tenantId,
                ':ri' => $receivableId,
                ':ci' => $collectionId,
                ':amt' => $amount,
            ]);

            // Update receivable amount_paid
            $this->db->prepare(
                "UPDATE pal_receivables SET amount_paid = amount_paid + :amt, version = version + 1
                 WHERE id = :id AND tenant_id = :tid"
            )->execute([':amt' => $amount, ':id' => $receivableId, ':tid' => $this->tenantId]);

            // Update status based on new outstanding
            $newPaid = (float)$rcv['amount_paid'] + $amount;
            $total = (float)$rcv['amount'];
            $newStatus = $newPaid >= $total ? 'settled' : 'partial';
            $this->db->prepare(
                "UPDATE pal_receivables SET status = :st WHERE id = :id AND tenant_id = :tid"
            )->execute([':st' => $newStatus, ':id' => $receivableId, ':tid' => $this->tenantId]);

            $this->db->commit();

            palAudit('pal.receivable.payment_allocated', $this->userId, 'pal_receivable_payments', null,
                null, ['receivable_id' => $receivableId, 'collection_id' => $collectionId, 'amount' => $amount]);
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Get receivable by ID.
     */
    public function get(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM pal_receivables WHERE id = :id AND tenant_id = :tid");
        $stmt->execute([':id' => $id, ':tid' => $this->tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * List receivables for a sale.
     */
    public function listForSale(int $saleId): array
    {
        $stmt = $this->db->prepare(
            "SELECT r.*, COALESCE(SUM(rp.amount), 0) AS allocated
             FROM pal_receivables r
             LEFT JOIN pal_receivable_payments rp ON r.id = rp.receivable_id
             WHERE r.sales_id = :si AND r.tenant_id = :tid
             GROUP BY r.id
             ORDER BY r.due_date ASC"
        );
        $stmt->execute([':si' => $saleId, ':tid' => $this->tenantId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get outstanding receivables (not fully settled).
     */
    public function listOutstanding(int $limit = 100): array
    {
        $stmt = $this->db->prepare(
            "SELECT r.*, COALESCE(s.invoice_number, s.sales_number) AS invoice_ref,
                    p.title AS project_title, c.name AS client_name
             FROM pal_receivables r
             LEFT JOIN pal_sales s ON r.sales_id = s.id
             LEFT JOIN pal_projects p ON r.project_id = p.id
             LEFT JOIN pal_clients c ON r.client_id = c.id
             WHERE r.tenant_id = :tid AND r.status IN ('pending', 'partial', 'overdue')
             ORDER BY r.due_date ASC
             LIMIT :lim"
        );
        $stmt->bindValue(':tid', $this->tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Mark overdue receivables (past due date, not settled/cancelled/voided).
     */
    public function markOverdue(): int
    {
        $stmt = $this->db->prepare(
            "UPDATE pal_receivables SET status = 'overdue'
             WHERE tenant_id = :tid AND status IN ('pending', 'partial')
               AND due_date < CURDATE()"
        );
        $stmt->execute([':tid' => $this->tenantId]);
        return $stmt->rowCount();
    }

    /**
     * Void a receivable.
     */
    public function void(int $id, string $reason): void
    {
        $rcv = $this->get($id);
        if ($rcv === null) {
            throw new InvalidArgumentException('Receivable not found.');
        }
        if ((float)$rcv['amount_paid'] > 0) {
            throw new InvalidArgumentException('Cannot void a receivable that has payments allocated.');
        }

        $this->db->prepare(
            "UPDATE pal_receivables SET status = 'voided', voided_by = :vb, voided_at = NOW(), void_reason = :vr, version = version + 1
             WHERE id = :id AND tenant_id = :tid"
        )->execute([':vb' => $this->userId, ':vr' => $reason, ':id' => $id, ':tid' => $this->tenantId]);
    }

    /**
     * Get total outstanding receivable amount for a client.
     */
    public function clientOutstanding(int $clientId): float
    {
        $stmt = $this->db->prepare(
            "SELECT COALESCE(SUM(amount - amount_paid), 0) FROM pal_receivables
             WHERE tenant_id = :tid AND client_id = :cid AND status IN ('pending', 'partial', 'overdue')"
        );
        $stmt->execute([':tid' => $this->tenantId, ':cid' => $clientId]);
        return (float)$stmt->fetchColumn();
    }
}
