<?php
declare(strict_types=1);

class EvidenceSuggestionRepository
{
    private \Ikabud\Kernel\Contracts\ModuleDB $db;
    private string $tenantId;

    public function __construct(string $tenantId)
    {
        $this->tenantId = $tenantId;
        $this->db = ate_db($tenantId);
    }

    public function findByCaseId(int $caseId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM ate_evidence_suggestion_reviews WHERE tenant_id = :tid AND evaluation_case_id = :cid ORDER BY updated_at DESC, id DESC');
        $stmt->execute([':tid' => $this->tenantId, ':cid' => $caseId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function create(array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO ate_evidence_suggestion_reviews
             (tenant_id, evaluation_case_id, evidence_snapshot_id, machine_suggestion_id, suggestion_key,
              machine_category, machine_priority, machine_action, machine_title, machine_rationale,
              reviewer_status, reviewer_title, reviewer_rationale, reviewer_reason, rubric_criterion_id,
              revision_request_id, reviewer_id, version)
             VALUES
             (:tid, :case_id, :snapshot_id, :machine_id, :suggestion_key,
              :category, :priority, :action, :title, :rationale,
              :status, :reviewer_title, :reviewer_rationale, :reason, :criterion_id,
              :revision_id, :reviewer_id, :version)'
        );
        $stmt->execute([
            ':tid' => $this->tenantId,
            ':case_id' => (int)$data['evaluation_case_id'],
            ':snapshot_id' => (int)$data['evidence_snapshot_id'],
            ':machine_id' => $data['machine_suggestion_id'] ?? null,
            ':suggestion_key' => (string)$data['suggestion_key'],
            ':category' => (string)$data['machine_category'],
            ':priority' => (string)($data['machine_priority'] ?? 'medium'),
            ':action' => (string)$data['machine_action'],
            ':title' => (string)$data['machine_title'],
            ':rationale' => (string)$data['machine_rationale'],
            ':status' => (string)($data['reviewer_status'] ?? 'pending'),
            ':reviewer_title' => $data['reviewer_title'] ?? null,
            ':reviewer_rationale' => $data['reviewer_rationale'] ?? null,
            ':reason' => $data['reviewer_reason'] ?? null,
            ':criterion_id' => $data['rubric_criterion_id'] ?? null,
            ':revision_id' => $data['revision_request_id'] ?? null,
            ':reviewer_id' => (int)$data['reviewer_id'],
            ':version' => (int)($data['version'] ?? 1),
        ]);
        return (int)$this->db->lastInsertId();
    }
}
