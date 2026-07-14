<?php

declare(strict_types=1);

class palFabricationService
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

    public function createAllocation(array $data): int
    {
        $projectId = isset($data['project_id']) ? (int)$data['project_id'] : 0;
        if ($projectId <= 0) {
            throw new InvalidArgumentException('Project ID is required.');
        }
        $pStmt = $this->db->prepare("SELECT contract_amount, fabrication_alloc_pct, fabrication_alloc_basis FROM pal_projects WHERE id = :id AND tenant_id = :tid");
        $pStmt->execute([':id' => $projectId, ':tid' => $this->tenantId]);
        $project = $pStmt->fetch(PDO::FETCH_ASSOC);
        if (!$project) throw new InvalidArgumentException('Project not found.');

        $contract = (float)$project['contract_amount'];
        $allocPct = (float)($project['fabrication_alloc_pct'] ?? 0);
        $fabBudget = round($contract * $allocPct / 100, 2);

        // CA dispensed amount — user enters this, must be ≤ remaining budget
        $dispenseAmount = (float)($data['approved_amount'] ?? 0);
        if ($dispenseAmount <= 0) {
            throw new InvalidArgumentException('CA dispense amount must be greater than zero.');
        }

        // Check against remaining budget (budget - already dispensed)
        $existing = $this->db->prepare("SELECT COALESCE(SUM(approved_amount), 0) FROM pal_fabrication_allocations WHERE project_id = :pid AND tenant_id = :tid");
        $existing->execute([':pid' => $projectId, ':tid' => $this->tenantId]);
        $alreadyDispensed = (float)$existing->fetchColumn();
        $remaining = $fabBudget - $alreadyDispensed;

        if ($dispenseAmount > $remaining) {
            throw new InvalidArgumentException("CA dispense amount ({$dispenseAmount}) exceeds remaining fabrication budget ({$remaining}). Budget: {$fabBudget}, already dispensed: {$alreadyDispensed}.");
        }

        $basis = $project['fabrication_alloc_basis'] ?? 'expenses';

        // Validate allocation percentage range
        if ($allocPct < 0 || $allocPct > 100) {
            throw new InvalidArgumentException('Fabrication allocation percentage must be between 0 and 100.');
        }

        // Each dispense is a separate row — no UNIQUE constraint issue
        $stmt = $this->db->prepare("
            INSERT INTO pal_fabrication_allocations (tenant_id, project_id, alloc_basis, alloc_percentage, base_amount, calculated_amount, approved_amount, approval_reason, approved_by, status, created_by)
            VALUES (:t, :pj, :ab, :ap, :ba, :ca, :aa, :ar, :aby, 'approved', :cb)
        ");
        $stmt->execute([
            ':t' => $this->tenantId,
            ':pj' => $projectId,
            ':ab' => $basis,
            ':ap' => $allocPct,
            ':ba' => $contract,
            ':ca' => $fabBudget,
            ':aa' => $dispenseAmount,
            ':ar' => $data['approval_reason'] ?? null,
            ':aby' => $this->userId,
            ':cb' => $this->userId,
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function generateWeeklyDues(int $allocationId, array $weeks): void
    {
        $aStmt = $this->db->prepare("SELECT * FROM pal_fabrication_allocations WHERE id = :id AND tenant_id = :tid");
        $aStmt->execute([':id' => $allocationId, ':tid' => $this->tenantId]);
        $alloc = $aStmt->fetch(PDO::FETCH_ASSOC);
        if (!$alloc) throw new InvalidArgumentException('Allocation not found.');

        $totalAmount = (float)(($alloc['approved_amount'] ?? $alloc['calculated_amount']));
        $weekCount = count($weeks);
        $perWeek = $weekCount > 0 ? round($totalAmount / $weekCount, 2) : 0;

        $this->db->beginTransaction();
        try {
            $ins = $this->db->prepare("INSERT INTO pal_fabrication_weekly_dues (tenant_id, project_id, allocation_id, week_number, week_start, week_end, due_amount, due_date, status) VALUES (:t, :pj, :aid, :wn, :ws, :we, :da, :dd, 'pending')");
            foreach ($weeks as $i => $week) {
                // Last week absorbs rounding remainder so totals reconcile
                $isLast = ($i === $weekCount - 1);
                $amount = $isLast ? round($totalAmount - ($perWeek * ($weekCount - 1)), 2) : $perWeek;
                $ins->execute([':t' => $this->tenantId, ':pj' => $alloc['project_id'], ':aid' => $allocationId, ':wn' => $i + 1, ':ws' => $week['start'], ':we' => $week['end'], ':da' => $amount, ':dd' => $week['due_date'] ?? $week['end']]);
            }
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function recordPayment(array $data): int
    {
        // Validate amount
        if ((float)($data['amount'] ?? 0) <= 0) {
            throw new InvalidArgumentException('Payment amount must be greater than zero.');
        }

        $this->db->beginTransaction();
        try {
            $cStmt = $this->db->prepare("SELECT COUNT(*) FROM pal_fabrication_payments WHERE tenant_id = :tid");
            $cStmt->execute([':tid' => $this->tenantId]);
            $num = 'FP-' . date('Ymd') . '-' . str_pad((string)((int)$cStmt->fetchColumn() + 1), 4, '0', STR_PAD_LEFT);

            $stmt = $this->db->prepare("INSERT INTO pal_fabrication_payments (tenant_id, payment_number, project_id, weekly_due_id, team_lead_id, payment_date, amount, payment_method, reference_number, notes, status, submitted_by, created_by) VALUES (:t, :num, :pj, :wd, :tl, :pd, :amt, :pm, :ref, :no, 'pending', :sb, :cb)");
            $stmt->execute([':t' => $this->tenantId, ':num' => $num, ':pj' => (int)$data['project_id'], ':wd' => !empty($data['weekly_due_id']) ? (int)$data['weekly_due_id'] : null, ':tl' => !empty($data['team_lead_id']) ? (int)$data['team_lead_id'] : null, ':pd' => $data['payment_date'] ?? date('Y-m-d'), ':amt' => $data['amount'] ?? 0, ':pm' => $data['payment_method'] ?? null, ':ref' => $data['reference_number'] ?? null, ':no' => $data['notes'] ?? null, ':sb' => $this->userId, ':cb' => $this->userId]);

            // Capture lastInsertId BEFORE commit — it resets after commit in MySQL/PDO
            $id = (int)$this->db->lastInsertId();
            $this->db->commit();
            return $id;
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function submitPayment(int $id): int
    {
        $pStmt = $this->db->prepare("SELECT * FROM pal_fabrication_payments WHERE id = :id AND tenant_id = :tid");
        $pStmt->execute([':id' => $id, ':tid' => $this->tenantId]);
        $pay = $pStmt->fetch(PDO::FETCH_ASSOC);
        if (!$pay) throw new InvalidArgumentException('Payment not found.');
        if ($pay['status'] !== 'pending') throw new InvalidArgumentException('Only pending payments can be submitted.');

        $this->db->prepare("UPDATE pal_fabrication_payments SET status = 'pending_approval', version = version + 1 WHERE id = :id")
             ->execute([':id' => $id]);

        $approvalId = palCreateApproval('fabrication_payment', $id, $this->userId, $pay['status'], 'pending_approval');
        palAudit('pal.fabrication.payment_submitted', $this->userId, 'pal_fabrication_payments', (string)$id,
            ['status' => $pay['status']], ['status' => 'pending_approval']);
        palFireEvent('pal.fabrication.payment_submitted', ['payment_id' => $id, 'approval_id' => $approvalId]);
        return $approvalId;
    }

    public function updateAllocation(int $id, array $data): void
    {
        $fields = [];
        $params = [':id' => $id, ':tid' => $this->tenantId];
        $mappings = ['alloc_percentage', 'approved_amount', 'approval_reason', 'status'];
        foreach ($mappings as $f) {
            if (array_key_exists($f, $data)) {
                $fields[] = "`{$f}` = :{$f}";
                // Use strict check so zero values (e.g. alloc_percentage=0) are preserved
                $params[":{$f}"] = ($data[$f] !== '' && $data[$f] !== null) ? $data[$f] : null;
            }
        }
        if (empty($fields)) return;

        // Recalculate if percentage or approved amount changed
        if (array_key_exists('alloc_percentage', $data) || array_key_exists('approved_amount', $data)) {
            $a = $this->db->prepare("SELECT fa.*, p.contract_amount FROM pal_fabrication_allocations fa JOIN pal_projects p ON fa.project_id = p.id WHERE fa.id = :id AND fa.tenant_id = :tid");
            $a->execute([':id' => $id, ':tid' => $this->tenantId]);
            $row = $a->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $pct = (float)($data['alloc_percentage'] ?? $row['alloc_percentage']);
                $base = (float)$row['contract_amount'];
                $fields[] = 'calculated_amount = :calc';
                $params[':calc'] = round($base * ($pct / 100), 2);
            }
        }

        $fields[] = 'version = version + 1';
        $sql = 'UPDATE pal_fabrication_allocations SET ' . implode(', ', $fields) . ' WHERE id = :id AND tenant_id = :tid';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
    }
}
