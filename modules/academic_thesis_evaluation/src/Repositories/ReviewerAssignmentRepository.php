<?php
declare(strict_types=1);

class ReviewerAssignmentRepository
{
    private \Ikabud\Kernel\Contracts\ModuleDB $db;
    private string $tenantId;

    public function __construct(string $tenantId)
    {
        $this->tenantId = $tenantId;
        $this->db = ate_db($this->tenantId);
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM ate_reviewer_assignments WHERE id = :id AND tenant_id = :tid"
        );
        $stmt->execute([':id' => $id, ':tid' => $this->tenantId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findByCaseId(int $caseId): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM ate_reviewer_assignments WHERE evaluation_case_id = :cid AND tenant_id = :tid ORDER BY assigned_at DESC"
        );
        $stmt->execute([':cid' => $caseId, ':tid' => $this->tenantId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function create(array $data): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO ate_reviewer_assignments (tenant_id, evaluation_case_id, stage_id, reviewer_id, reviewer_role, assignment_type, status, assigned_at)
            VALUES (:tid, :case_id, :stage_id, :reviewer_id, :reviewer_role, :assignment_type, :status, NOW())
        ");
        $stmt->execute([
            ':tid' => $this->tenantId,
            ':case_id' => (int)$data['evaluation_case_id'],
            ':stage_id' => $data['stage_id'] ?? null,
            ':reviewer_id' => (int)$data['reviewer_id'],
            ':reviewer_role' => $data['reviewer_role'],
            ':assignment_type' => $data['assignment_type'] ?? 'primary',
            ':status' => $data['status'] ?? 'pending',
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function accept(int $assignmentId): void
    {
        $stmt = $this->db->prepare("
            UPDATE ate_reviewer_assignments SET status = 'accepted', accepted_at = NOW()
            WHERE id = :id AND tenant_id = :tid
        ");
        $stmt->execute([':id' => $assignmentId, ':tid' => $this->tenantId]);
    }

    public function complete(int $assignmentId): void
    {
        $stmt = $this->db->prepare("
            UPDATE ate_reviewer_assignments SET status = 'completed', completed_at = NOW()
            WHERE id = :id AND tenant_id = :tid
        ");
        $stmt->execute([':id' => $assignmentId, ':tid' => $this->tenantId]);
    }
}
