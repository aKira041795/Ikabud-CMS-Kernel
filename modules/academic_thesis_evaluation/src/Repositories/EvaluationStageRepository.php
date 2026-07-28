<?php
declare(strict_types=1);

class EvaluationStageRepository
{
    private \Ikabud\Kernel\Contracts\ModuleDB $db;
    private string $tenantId;

    public function __construct(string $tenantId)
    {
        $this->tenantId = $tenantId;
        $this->db = ate_db($this->tenantId);
    }

    public function findByCaseId(int $caseId): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM ate_workflow_stages WHERE evaluation_case_id = :cid AND tenant_id = :tid ORDER BY stage_order ASC"
        );
        $stmt->execute([':cid' => $caseId, ':tid' => $this->tenantId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function findByCaseAndCode(int $caseId, string $stageCode): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM ate_workflow_stages WHERE evaluation_case_id = :cid AND stage_code = :code AND tenant_id = :tid ORDER BY created_at DESC LIMIT 1"
        );
        $stmt->execute([':cid' => $caseId, ':code' => $stageCode, ':tid' => $this->tenantId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function create(array $data): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO ate_workflow_stages (tenant_id, evaluation_case_id, stage_code, stage_order, status, assigned_role, opened_at, due_at, notes, created_at, updated_at)
            VALUES (:tid, :case_id, :stage_code, :stage_order, :status, :assigned_role, :opened_at, :due_at, :notes, NOW(), NOW())
        ");
        $stmt->execute([
            ':tid' => $this->tenantId,
            ':case_id' => (int)$data['evaluation_case_id'],
            ':stage_code' => $data['stage_code'],
            ':stage_order' => (int)($data['stage_order'] ?? 0),
            ':status' => $data['status'] ?? 'active',
            ':assigned_role' => $data['assigned_role'] ?? null,
            ':opened_at' => $data['opened_at'] ?? date('Y-m-d H:i:s'),
            ':due_at' => $data['due_at'] ?? null,
            ':notes' => $data['notes'] ?? null,
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function complete(int $stageId, string $outcome, int $completedBy, ?string $notes = null): void
    {
        $stmt = $this->db->prepare("
            UPDATE ate_workflow_stages SET status = 'completed', outcome = :outcome, completed_at = NOW(), completed_by = :by, notes = CONCAT(COALESCE(notes,''), '\n', COALESCE(:notes,''))
            WHERE id = :id AND tenant_id = :tid
        ");
        $stmt->execute([
            ':outcome' => $outcome,
            ':by' => $completedBy,
            ':notes' => $notes,
            ':id' => $stageId,
            ':tid' => $this->tenantId,
        ]);
    }
}
