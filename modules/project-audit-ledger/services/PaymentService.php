<?php

declare(strict_types=1);

/**
 * Payment Service — Manages actual money received (payments/collections).
 *
 * A payment represents money actually received from a client, as opposed
 * to a receivable which represents money expected. Payments are recorded
 * in pal_collections and allocated to one or more receivables via
 * pal_receivable_payments.
 *
 * This separates "money received" from "money expected" — a distinction
 * that was previously blurred by auto-creating "pending" collections at
 * invoice time.
 */
class palPaymentService
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
     * Record a payment (collection) against a sale.
     * Status is always 'pending' — allocation happens only on approve().
     * For trusted import workflows, use recordAndApprove().
     *
     * @param int $saleId
     * @param int|null $projectId
     * @param int|null $clientId
     * @param float $amount Payment amount
     * @param string $paymentMethod cash, check, bank_transfer, gcash
     * @param string $paymentDate Y-m-d
     * @param string|null $referenceNumber Check/ref number
     * @param string|null $notes
     * @return int Collection ID
     */
    public function record(
        int $saleId,
        ?int $projectId,
        ?int $clientId,
        float $amount,
        string $paymentMethod = 'cash',
        string $paymentDate = '',
        ?string $referenceNumber = null,
        ?string $notes = null,
    ): int {
        if ($amount <= 0) {
            throw new InvalidArgumentException('Payment amount must be positive.');
        }

        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM pal_collections WHERE tenant_id = :tid");
        $countStmt->execute([':tid' => $this->tenantId]);
        $prefix = (function_exists('palSettings') ? (palSettings()['collection_prefix'] ?? 'COL') : 'COL');
        $collNum = $prefix . '-' . date('Ymd') . '-' . str_pad((string)((int)$countStmt->fetchColumn() + 1), 4, '0', STR_PAD_LEFT);

        $stmt = $this->db->prepare(
            "INSERT INTO pal_collections
                (tenant_id, collection_number, sales_id, project_id, client_id,
                 payment_date, amount, payment_method, reference_number, notes,
                 received_by, status, created_by)
             VALUES (:t, :cn, :si, :pj, :cl, :pd, :amt, :pm, :ref, :no, :rb, 'pending', :cb)"
        );
        $stmt->execute([
            ':t' => $this->tenantId,
            ':cn' => $collNum,
            ':si' => $saleId,
            ':pj' => $projectId,
            ':cl' => $clientId,
            ':pd' => $paymentDate ?: date('Y-m-d'),
            ':amt' => $amount,
            ':pm' => $paymentMethod,
            ':ref' => $referenceNumber,
            ':no' => $notes,
            ':rb' => $this->userId,
            ':cb' => $this->userId,
        ]);
        $collectionId = (int)$this->db->lastInsertId();

        palAudit('pal.payment.recorded', $this->userId, 'pal_collections', (string)$collectionId,
            null, ['sales_id' => $saleId, 'amount' => $amount, 'method' => $paymentMethod]);

        return $collectionId;
    }

    /**
     * Record AND approve in one atomic transaction.
     * For trusted import or cash-counter workflows where the payment
     * is immediately considered received.
     *
     * @return int Collection ID
     */
    public function recordAndApprove(
        int $saleId,
        ?int $projectId,
        ?int $clientId,
        float $amount,
        string $paymentMethod = 'cash',
        string $paymentDate = '',
        ?string $referenceNumber = null,
        ?string $notes = null,
    ): int {
        $this->db->beginTransaction();
        try {
            // Record within the transaction
            $countStmt = $this->db->prepare("SELECT COUNT(*) FROM pal_collections WHERE tenant_id = :tid");
            $countStmt->execute([':tid' => $this->tenantId]);
            $prefix = (function_exists('palSettings') ? (palSettings()['collection_prefix'] ?? 'COL') : 'COL');
            $collNum = $prefix . '-' . date('Ymd') . '-' . str_pad((string)((int)$countStmt->fetchColumn() + 1), 4, '0', STR_PAD_LEFT);

            $stmt = $this->db->prepare(
                "INSERT INTO pal_collections
                    (tenant_id, collection_number, sales_id, project_id, client_id,
                     payment_date, amount, payment_method, reference_number, notes,
                     received_by, status, created_by)
                 VALUES (:t, :cn, :si, :pj, :cl, :pd, :amt, :pm, :ref, :no, :rb, 'pending', :cb)"
            );
            $stmt->execute([
                ':t' => $this->tenantId,
                ':cn' => $collNum,
                ':si' => $saleId,
                ':pj' => $projectId,
                ':cl' => $clientId,
                ':pd' => $paymentDate ?: date('Y-m-d'),
                ':amt' => $amount,
                ':pm' => $paymentMethod,
                ':ref' => $referenceNumber,
                ':no' => $notes,
                ':rb' => $this->userId,
                ':cb' => $this->userId,
            ]);
            $collectionId = (int)$this->db->lastInsertId();

            // Approve within the same transaction (lock + allocate)
            $this->approveWithinTransaction($collectionId);

            $this->db->commit();
            return $collectionId;
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Approve a pending payment.
     * Locks the collection, allocates to receivables, updates sale status.
     * Allocates only upon approval — never on pending record.
     */
    public function approve(int $collectionId): void
    {
        $this->db->beginTransaction();
        try {
            $this->approveWithinTransaction($collectionId);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Approve within an existing transaction. Caller owns commit/rollback.
     */
    private function approveWithinTransaction(int $collectionId): void
    {
        // Lock the collection row
        $lockStmt = $this->db->prepare(
            "SELECT id, sales_id, amount, status FROM pal_collections
             WHERE id = :id AND tenant_id = :tid FOR UPDATE"
        );
        $lockStmt->execute([':id' => $collectionId, ':tid' => $this->tenantId]);
        $coll = $lockStmt->fetch(PDO::FETCH_ASSOC);

        if (!$coll) {
            throw new InvalidArgumentException('Collection not found.');
        }
        if ($coll['status'] !== 'pending') {
            throw new InvalidArgumentException('Collection is not in pending status.');
        }

        // Update collection status to approved
        $this->db->prepare(
            "UPDATE pal_collections SET status = 'approved', approved_by = :ab, approved_at = NOW()
             WHERE id = :id AND tenant_id = :tid"
        )->execute([':ab' => $this->userId, ':id' => $collectionId, ':tid' => $this->tenantId]);

        // Check for overpayment: payment exceeds total outstanding receivables
        $totalOutstandingStmt = $this->db->prepare(
            "SELECT COALESCE(SUM(outstanding), 0) FROM pal_receivables
             WHERE tenant_id = :tid AND sales_id = :si AND status IN ('pending', 'partial', 'overdue')"
        );
        $totalOutstandingStmt->execute([':tid' => $this->tenantId, ':si' => $coll['sales_id']]);
        $totalOutstanding = (float)$totalOutstandingStmt->fetchColumn();
        $paymentAmount = (float)$coll['amount'];

        if ($paymentAmount > $totalOutstanding) {
            throw new InvalidArgumentException(
                "Payment amount ({$paymentAmount}) exceeds total outstanding receivables ({$totalOutstanding}). "
                . "Overpayment is not supported. Adjust the payment amount or create a credit note."
            );
        }

        // Allocate to receivables (within same transaction)
        $this->allocatePaymentWithinTransaction((int)$coll['id'], (int)$coll['sales_id'], (float)$coll['amount']);

        // Update sale collection status
        $this->updateSaleCollectionStatus((int)$coll['sales_id']);

        palAudit('pal.payment.approved', $this->userId, 'pal_collections', (string)$collectionId,
            null, ['sales_id' => $coll['sales_id'], 'amount' => $coll['amount']]);
    }

    /**
     * Reject a pending payment.
     */
    public function reject(int $collectionId, string $reason = ''): void
    {
        $stmt = $this->db->prepare(
            "UPDATE pal_collections SET status = 'rejected' WHERE id = :id AND tenant_id = :tid AND status = 'pending'"
        );
        $stmt->execute([':id' => $collectionId, ':tid' => $this->tenantId]);

        if ($stmt->rowCount() === 0) {
            throw new InvalidArgumentException('Collection not found or already processed.');
        }

        palAudit('pal.payment.rejected', $this->userId, 'pal_collections', (string)$collectionId,
            null, ['reason' => $reason]);
    }

    /**
     * Allocate a payment to receivables within an existing transaction.
     * Calls ReceivableService::allocatePaymentWithinTransaction() so no
     * nested transaction occurs.
     */
    private function allocatePaymentWithinTransaction(int $collectionId, int $saleId, float $amount): void
    {
        $rcv = new palReceivableService($this->db, $this->tenantId, $this->userId);

        // Get unpaid receivables for this sale, ordered by due date
        $stmt = $this->db->prepare(
            "SELECT id, outstanding FROM pal_receivables
             WHERE tenant_id = :tid AND sales_id = :si AND status IN ('pending', 'partial', 'overdue')
             ORDER BY due_date ASC"
        );
        $stmt->execute([':tid' => $this->tenantId, ':si' => $saleId]);
        $receivables = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $remaining = $amount;
        foreach ($receivables as $rcvRow) {
            if ($remaining <= 0) break;

            $outstanding = (float)$rcvRow['outstanding'];
            $allocAmount = min($remaining, $outstanding);

            if ($allocAmount > 0) {
                $rcv->allocatePaymentWithinTransaction((int)$rcvRow['id'], $collectionId, $allocAmount);
                $remaining -= $allocAmount;
            }
        }
    }

    /**
     * Update the sale's collection/payment status based on total collections.
     */
    private function updateSaleCollectionStatus(int $saleId): void
    {
        $saleStmt = $this->db->prepare(
            "SELECT net_amount FROM pal_sales WHERE id = :id AND tenant_id = :tid"
        );
        $saleStmt->execute([':id' => $saleId, ':tid' => $this->tenantId]);
        $sale = $saleStmt->fetch(PDO::FETCH_ASSOC);
        if (!$sale) return;

        $netAmount = (float)$sale['net_amount'];

        $collStmt = $this->db->prepare(
            "SELECT COALESCE(SUM(c.amount), 0) FROM pal_collections c
             WHERE c.sales_id = :si AND c.tenant_id = :tid AND c.status = 'approved'"
        );
        $collStmt->execute([':si' => $saleId, ':tid' => $this->tenantId]);
        $totalCollected = (float)$collStmt->fetchColumn();

        $newStatus = 'issued';
        if ($totalCollected >= $netAmount && $netAmount > 0) {
            $newStatus = 'paid';
        } elseif ($totalCollected > 0) {
            $newStatus = 'partially_paid';
        }

        // Check overdue
        $dueStmt = $this->db->prepare("SELECT due_date FROM pal_sales WHERE id = :id");
        $dueStmt->execute([':id' => $saleId]);
        $dueDate = $dueStmt->fetchColumn();
        if ($dueDate && $dueDate < date('Y-m-d') && $newStatus !== 'paid') {
            $newStatus = 'overdue';
        }

        $this->db->prepare(
            "UPDATE pal_sales SET status = :st, version = version + 1 WHERE id = :id AND tenant_id = :tid"
        )->execute([':st' => $newStatus, ':id' => $saleId, ':tid' => $this->tenantId]);
    }

    /**
     * Get payment by ID.
     */
    public function get(int $id): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT c.*, u.full_name AS received_by_name
             FROM pal_collections c
             LEFT JOIN pal_users u ON c.received_by = u.id
             WHERE c.id = :id AND c.tenant_id = :tid"
        );
        $stmt->execute([':id' => $id, ':tid' => $this->tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * List payments for a sale.
     */
    public function listForSale(int $saleId): array
    {
        $stmt = $this->db->prepare(
            "SELECT c.*, u.full_name AS received_by_name
             FROM pal_collections c
             LEFT JOIN pal_users u ON c.received_by = u.id
             WHERE c.sales_id = :si AND c.tenant_id = :tid
             ORDER BY c.created_at DESC"
        );
        $stmt->execute([':si' => $saleId, ':tid' => $this->tenantId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
