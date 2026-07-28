<?php
declare(strict_types=1);

class RubricResponseRepository
{
    private \Ikabud\Kernel\Contracts\ModuleDB $db;
    private string $tenantId;

    public function __construct(string $tenantId)
    {
        $this->tenantId = $tenantId;
        $this->db = ate_db($this->tenantId);
    }

    public function findByAssignment(int $assignmentId): array
    {
        $stmt = $this->db->prepare(
            "SELECT r.*, c.label AS criterion_label, c.weight AS criterion_weight, c.score_min, c.score_max
             FROM ate_rubric_responses r
             JOIN ate_rubric_criteria c ON r.criterion_id = c.id
             WHERE r.reviewer_assignment_id = :aid AND r.tenant_id = :tid
             ORDER BY c.sort_order ASC"
        );
        $stmt->execute([':aid' => $assignmentId, ':tid' => $this->tenantId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function findByCaseId(int $caseId): array
    {
        $stmt = $this->db->prepare(
            "SELECT r.*, c.label AS criterion_label, c.weight AS criterion_weight, ra.reviewer_id, ra.reviewer_role
             FROM ate_rubric_responses r
             JOIN ate_rubric_criteria c ON r.criterion_id = c.id
             JOIN ate_reviewer_assignments ra ON r.reviewer_assignment_id = ra.id
             WHERE r.evaluation_case_id = :cid AND r.tenant_id = :tid
             ORDER BY ra.reviewer_role, c.sort_order ASC"
        );
        $stmt->execute([':cid' => $caseId, ':tid' => $this->tenantId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function upsert(int $caseId, int $assignmentId, int $criterionId, array $data): void
    {
        $manuscriptVersionId = $data['manuscript_version_id'] ?? null;
        $stmt = $this->db->prepare("
            INSERT INTO ate_rubric_responses (tenant_id, evaluation_case_id, manuscript_version_id, reviewer_assignment_id, criterion_id, score, comment, evidence_reference, created_at, updated_at)
            VALUES (:tid, :case_id, :manuscript_id, :assignment_id, :criterion_id, :score, :comment, :evidence_ref, NOW(), NOW())
            ON DUPLICATE KEY UPDATE score = VALUES(score), comment = VALUES(comment), evidence_reference = VALUES(evidence_reference), updated_at = NOW()
        ");
        $stmt->execute([
            ':tid' => $this->tenantId,
            ':case_id' => $caseId,
            ':manuscript_id' => $manuscriptVersionId,
            ':assignment_id' => $assignmentId,
            ':criterion_id' => $criterionId,
            ':score' => $data['score'] ?? null,
            ':comment' => $data['comment'] ?? null,
            ':evidence_ref' => isset($data['evidence_reference']) ? json_encode($data['evidence_reference']) : null,
        ]);
    }
}
