<?php
declare(strict_types=1);

class RevisionRequestRepository
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
            "SELECT * FROM ate_revision_requests WHERE id = :id AND tenant_id = :tid"
        );
        $stmt->execute([':id' => $id, ':tid' => $this->tenantId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findByCaseId(int $caseId): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM ate_revision_requests WHERE evaluation_case_id = :cid AND tenant_id = :tid ORDER BY created_at DESC"
        );
        $stmt->execute([':cid' => $caseId, ':tid' => $this->tenantId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function countUnresolved(int $caseId): int
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM ate_revision_requests WHERE evaluation_case_id = :cid AND tenant_id = :tid AND status IN ('open', 'in_progress')"
        );
        $stmt->execute([':cid' => $caseId, ':tid' => $this->tenantId]);
        return (int)$stmt->fetchColumn();
    }

    public function create(array $data): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO ate_revision_requests (tenant_id, evaluation_case_id, source_stage_id, manuscript_version_id, category, severity, instruction, evidence_reference, assigned_to, status, due_at, created_by, created_at, updated_at)
            VALUES (:tid, :case_id, :stage_id, :manuscript_id, :category, :severity, :instruction, :evidence_ref, :assigned_to, :status, :due_at, :created_by, NOW(), NOW())
        ");
        $stmt->execute([
            ':tid' => $this->tenantId,
            ':case_id' => (int)$data['evaluation_case_id'],
            ':stage_id' => $data['source_stage_id'] ?? null,
            ':manuscript_id' => $data['manuscript_version_id'] ?? null,
            ':category' => $data['category'] ?? 'other',
            ':severity' => $data['severity'] ?? 'minor',
            ':instruction' => $data['instruction'],
            ':evidence_ref' => isset($data['evidence_reference']) ? json_encode($data['evidence_reference']) : null,
            ':assigned_to' => $data['assigned_to'] ?? null,
            ':status' => $data['status'] ?? 'open',
            ':due_at' => $data['due_at'] ?? null,
            ':created_by' => (int)$data['created_by'],
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function resolve(int $revisionId, int $resolvedInVersionId): void
    {
        $stmt = $this->db->prepare("
            UPDATE ate_revision_requests SET status = 'resolved', resolved_in_version_id = :vid, resolved_at = NOW(), updated_at = NOW()
            WHERE id = :id AND tenant_id = :tid
        ");
        $stmt->execute([':vid' => $resolvedInVersionId, ':id' => $revisionId, ':tid' => $this->tenantId]);
    }
}
