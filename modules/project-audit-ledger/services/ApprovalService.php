<?php

declare(strict_types=1);

/**
 * Centralized approval service — single point of decision for all entities.
 *
 * Flow: Entity submitted → pal_approvals record created (pending)
 *       → Reviewer calls decide() → pal_approvals updated + entity status updated
 *       → Post-approval side effects (stock movements, cost updates)
 *       → Domain event fired → Audit trail recorded
 */
class palApprovalService
{
    private Ikabud\Kernel\Contracts\ModuleDB $db;
    private int $tenantId;
    private int $userId;

    private const TABLES = [
        'expense'            => 'pal_expenses',
        'purchase'           => 'pal_purchases',
        'issuance'           => 'pal_material_issuances',
        'collection'         => 'pal_collections',
        'fabrication_payment' => 'pal_fabrication_payments',
        'cash_advance'       => 'pal_cash_advances',
        'mobilization'       => 'pal_mobilization_requests',
    ];

    private const STATUS_MAP = [
        'approved' => 'approved',
        'rejected' => 'rejected',
        'returned' => 'returned',
    ];

    // Tables whose ENUM includes 'returned' — all others map 'returned' → 'rejected'
    private const RETURN_CAPABLE = ['expense'];

    private const EVENT_PREFIX = [
        'expense'            => 'pal.expense',
        'purchase'           => 'pal.purchase',
        'issuance'           => 'pal.inventory',
        'collection'         => 'pal.collection',
        'fabrication_payment' => 'pal.fabrication.payment',
        'cash_advance'       => 'pal.cash_advance',
        'mobilization'       => 'pal.mobilization',
    ];

    public function __construct(Ikabud\Kernel\Contracts\ModuleDB $db, int $tenantId, int $userId)
    {
        $this->db = $db;
        $this->tenantId = $tenantId;
        $this->userId = $userId;
    }

    public function pendingList(): array
    {
        $stmt = $this->db->prepare(
            "SELECT a.*, u.full_name AS submitter_name
             FROM pal_approvals a
             LEFT JOIN pal_users u ON a.submitted_by = u.id
             WHERE a.tenant_id = :tid AND a.decision = 'pending'
             ORDER BY a.submitted_at ASC"
        );
        $stmt->execute([':tid' => $this->tenantId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Decide on a pending approval — the only way to approve/reject entities.
     */
    public function decide(int $approvalId, string $decision, string $remarks = ''): void
    {
        if (!in_array($decision, ['approved', 'rejected', 'returned'], true)) {
            throw new InvalidArgumentException('Decision must be approved, rejected, or returned.');
        }

        $stmt = $this->db->prepare(
            "SELECT * FROM pal_approvals WHERE id = :id AND tenant_id = :tid AND decision = 'pending'"
        );
        $stmt->execute([':id' => $approvalId, ':tid' => $this->tenantId]);
        $approval = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$approval) {
            throw new InvalidArgumentException('Approval not found or already decided.');
        }

        // Self-approval check
        $settings = palSettings();
        $allowSelf = ($settings['allow_self_approval'] ?? '0') === '1';
        if (!$allowSelf && (int)$approval['submitted_by'] === $this->userId) {
            throw new DomainException('Self-approval is not allowed.');
        }

        $entityType = $approval['entity_type'];
        $entityId = (int)$approval['entity_id'];
        // 'returned' only valid for tables whose ENUM includes it — others fall back to 'rejected'
        $newStatus = ($decision === 'returned' && !in_array($entityType, self::RETURN_CAPABLE, true))
            ? 'rejected'
            : (self::STATUS_MAP[$decision] ?? 'pending');

        $this->db->beginTransaction();
        try {
            // 1. Update approval record
            $upd = $this->db->prepare(
                "UPDATE pal_approvals SET decision = :dec, reviewer_id = :rv,
                 decision_date = NOW(), remarks = :rm WHERE id = :id"
            );
            $upd->execute([
                ':dec' => $decision, ':rv' => $this->userId,
                ':rm' => $remarks, ':id' => $approvalId,
            ]);

            // 2. Update target entity status
            $table = self::TABLES[$entityType] ?? null;
            if ($table) {
                $eUpd = $this->db->prepare(
                    "UPDATE {$table} SET status = :st WHERE id = :eid AND tenant_id = :tid"
                );
                $eUpd->execute([':st' => $newStatus, ':eid' => $entityId, ':tid' => $this->tenantId]);
            }

            // 3. Post-approval side effects (stock movements, cost updates)
            if ($decision === 'approved') {
                $this->executePostApproval($entityType, $entityId);
            }

            $this->db->commit();

            // 4. Fire domain event
            $prefix = self::EVENT_PREFIX[$entityType] ?? 'pal.approval';
            palFireEvent("{$prefix}.{$decision}", [
                'approval_id' => $approvalId, 'entity_type' => $entityType,
                'entity_id' => $entityId, 'decision' => $decision,
                'reviewer_id' => $this->userId, 'tenant_id' => $this->tenantId,
            ]);

            // 5. Audit
            palAudit("pal.approval.{$decision}", $this->userId, $entityType,
                (string)$entityId,
                ['status' => $approval['previous_status']],
                ['status' => $newStatus, 'decision' => $decision, 'remarks' => $remarks]
            );
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    private function executePostApproval(string $entityType, int $entityId): void
    {
        match ($entityType) {
            'purchase' => $this->processPurchaseApproval($entityId),
            'issuance' => $this->processIssuanceApproval($entityId),
            'collection' => $this->processCollectionApproval($entityId),
            'fabrication_payment' => $this->processFabricationPaymentApproval($entityId),
            default => null,
        };
    }

    private function processCollectionApproval(int $collectionId): void
    {
        $cStmt = $this->db->prepare(
            "SELECT c.*, s.net_amount, COALESCE(appr.total_approved, 0) AS already_approved
             FROM pal_collections c
             JOIN pal_sales s ON c.sales_id = s.id
             LEFT JOIN (
                 SELECT sales_id, COALESCE(SUM(amount), 0) AS total_approved
                 FROM pal_collections WHERE status = 'approved' AND id != :cid2
                 GROUP BY sales_id
             ) appr ON c.sales_id = appr.sales_id
             WHERE c.id = :cid AND c.tenant_id = :tid"
        );
        $cStmt->execute([':cid' => $collectionId, ':cid2' => $collectionId, ':tid' => $this->tenantId]);
        $col = $cStmt->fetch(PDO::FETCH_ASSOC);
        if (!$col) return;

        $newCollected = (float)$col['already_approved'] + (float)$col['amount'];
        $newStatus = $newCollected >= (float)$col['net_amount'] ? 'paid' : 'partially_paid';
        $this->db->prepare("UPDATE pal_sales SET status = :st WHERE id = :id AND tenant_id = :tid")
             ->execute([':st' => $newStatus, ':id' => $col['sales_id'], ':tid' => $this->tenantId]);

        // Update weekly due paid amount if linked
        if (!empty($col['weekly_due_id'])) {
            $this->db->prepare("UPDATE pal_fabrication_weekly_dues SET paid_amount = paid_amount + :amt WHERE id = :id AND tenant_id = :tid")
                 ->execute([':amt' => $col['amount'], ':id' => $col['weekly_due_id'], ':tid' => $this->tenantId]);
        }
    }

    private function processFabricationPaymentApproval(int $paymentId): void
    {
        $pStmt = $this->db->prepare("SELECT * FROM pal_fabrication_payments WHERE id = :id AND tenant_id = :tid");
        $pStmt->execute([':id' => $paymentId, ':tid' => $this->tenantId]);
        $pay = $pStmt->fetch(PDO::FETCH_ASSOC);
        if (!$pay) return;

        if (!empty($pay['weekly_due_id'])) {
            $this->db->prepare("UPDATE pal_fabrication_weekly_dues SET paid_amount = paid_amount + :amt WHERE id = :id AND tenant_id = :tid")
                 ->execute([':amt' => $pay['amount'], ':id' => $pay['weekly_due_id'], ':tid' => $this->tenantId]);
        }

        palFireEvent('pal.fabrication.payment_approved', [
            'payment_id' => $paymentId,
            'project_id' => (int)($pay['project_id'] ?? 0),
            'amount' => (float)($pay['amount'] ?? 0),
        ]);
    }

    private function processPurchaseApproval(int $purchaseId): void
    {
        $stmt = $this->db->prepare(
            "SELECT pi.*, m.current_avg_cost
             FROM pal_purchase_items pi JOIN pal_materials m ON pi.material_id = m.id
             WHERE pi.purchase_id = :pid AND pi.tenant_id = :tid"
        );
        $stmt->execute([':pid' => $purchaseId, ':tid' => $this->tenantId]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $pStmt = $this->db->prepare("SELECT purchase_number FROM pal_purchases WHERE id = :id AND tenant_id = :tid");
        $pStmt->execute([':id' => $purchaseId, ':tid' => $this->tenantId]);
        $purchase = $pStmt->fetch(PDO::FETCH_ASSOC);
        $num = $purchase['purchase_number'] ?? '';

        foreach ($items as $item) {
            $qty = (float)$item['quantity'];
            $unitCost = (float)$item['unit_cost'];
            $totalCost = $qty * $unitCost;
            $mid = (int)$item['material_id'];

            $bStmt = $this->db->prepare(
                "SELECT COALESCE(quantity,0), COALESCE(avg_cost,0) FROM pal_inventory_balances WHERE material_id=:mid AND tenant_id=:tid"
            );
            $bStmt->execute([':mid' => $mid, ':tid' => $this->tenantId]);
            $bRow = $bStmt->fetch(PDO::FETCH_NUM);
            $cq = $bRow ? (float)$bRow[0] : 0;
            $ca = $bRow ? (float)$bRow[1] : (float)$item['current_avg_cost'];
            $na = ($cq + $qty) > 0 ? round((($cq * $ca) + ($qty * $unitCost)) / ($cq + $qty), 2) : $unitCost;

            $mv = $this->db->prepare("INSERT INTO pal_inventory_movements (tenant_id, material_id, movement_type, reference_type, reference_id, quantity, unit_cost, total_cost, description, created_by) VALUES (:t,:m,'stock_in','purchase',:rid,:qty,:uc,:tc,:desc,:cb)");
            $mv->execute([':t' => $this->tenantId, ':m' => $mid, ':rid' => $purchaseId, ':qty' => $qty, ':uc' => $unitCost, ':tc' => $totalCost, ':desc' => "Stock in from purchase #{$num}", ':cb' => $this->userId]);

            $ib = $this->db->prepare("INSERT INTO pal_inventory_balances (tenant_id, material_id, quantity, avg_cost) VALUES (:t,:m,:qty,:ac) ON DUPLICATE KEY UPDATE quantity=quantity+:qty2, avg_cost=:ac2");
            $ib->execute([':t' => $this->tenantId, ':m' => $mid, ':qty' => $qty, ':ac' => $na, ':qty2' => $qty, ':ac2' => $na]);

            $this->db->prepare("UPDATE pal_materials SET current_avg_cost=:ac WHERE id=:id AND tenant_id=:tid")->execute([':ac' => $na, ':id' => $mid, ':tid' => $this->tenantId]);
        }

        palFireEvent('pal.inventory.stocked_in', [
            'purchase_id' => $purchaseId,
            'items' => $items,
        ]);
    }

    private function processIssuanceApproval(int $issuanceId): void
    {
        $iss = $this->db->prepare("SELECT * FROM pal_material_issuances WHERE id=:id AND tenant_id=:tid");
        $iss->execute([':id' => $issuanceId, ':tid' => $this->tenantId]);
        $issuance = $iss->fetch(PDO::FETCH_ASSOC);
        if (!$issuance) return;

        $items = $this->db->prepare("SELECT mii.*, m.current_avg_cost FROM pal_material_issuance_items mii JOIN pal_materials m ON mii.material_id=m.id WHERE mii.issuance_id=:iid AND mii.tenant_id=:tid");
        $items->execute([':iid' => $issuanceId, ':tid' => $this->tenantId]);

        foreach ($items->fetchAll(PDO::FETCH_ASSOC) as $item) {
            $qty = (float)$item['requested_qty'];
            $uc = (float)($item['unit_cost'] ?: $item['current_avg_cost']);
            $tc = $qty * $uc;

            $this->db->prepare("UPDATE pal_material_issuance_items SET approved_qty=:q, issued_qty=:q, unit_cost=:uc WHERE id=:id")->execute([':q' => $qty, ':uc' => $uc, ':id' => $item['id']]);
            $this->db->prepare("INSERT INTO pal_inventory_movements (tenant_id,material_id,movement_type,reference_type,reference_id,project_id,quantity,unit_cost,total_cost,description,created_by) VALUES (:t,:m,'issuance','issuance',:rid,:pj,:qty,:uc,:tc,:desc,:cb)")->execute([':t' => $this->tenantId, ':m' => $item['material_id'], ':rid' => $issuanceId, ':pj' => $issuance['project_id'], ':qty' => -$qty, ':uc' => $uc, ':tc' => $tc, ':desc' => "Material issuance #{$issuance['issuance_number']}", ':cb' => $this->userId]);
            $this->db->prepare("UPDATE pal_inventory_balances SET quantity=quantity-:qty WHERE material_id=:mid AND tenant_id=:tid")->execute([':qty' => $qty, ':mid' => $item['material_id'], ':tid' => $this->tenantId]);
        }

        $this->db->prepare("UPDATE pal_material_issuances SET status='fully_issued' WHERE id=:id AND tenant_id=:tid")->execute([':id' => $issuanceId, ':tid' => $this->tenantId]);

        palFireEvent('pal.inventory.material_issued', [
            'issuance_id' => $issuanceId,
            'project_id' => (int)($issuance['project_id'] ?? 0),
            'issuance_number' => $issuance['issuance_number'] ?? '',
        ]);
    }
}
