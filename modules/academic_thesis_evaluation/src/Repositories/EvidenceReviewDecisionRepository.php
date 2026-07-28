<?php
declare(strict_types=1);

class EvidenceReviewDecisionRepository
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
            "SELECT * FROM ate_evidence_review_decisions WHERE evaluation_case_id = :cid AND tenant_id = :tid ORDER BY confirmed_at DESC"
        );
        $stmt->execute([':cid' => $caseId, ':tid' => $this->tenantId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function findBySnapshotId(int $snapshotId): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM ate_evidence_review_decisions WHERE evidence_snapshot_id = :sid AND tenant_id = :tid ORDER BY confirmed_at DESC"
        );
        $stmt->execute([':sid' => $snapshotId, ':tid' => $this->tenantId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function create(array $data): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO ate_evidence_review_decisions (tenant_id, evaluation_case_id, evidence_snapshot_id, match_id, machine_relationship, reviewer_relationship, reviewer_action, reviewer_reason, reviewer_id, confirmed_at)
            VALUES (:tid, :case_id, :snapshot_id, :match_id, :machine_rel, :reviewer_rel, :action, :reason, :reviewer_id, NOW())
        ");
        $stmt->execute([
            ':tid' => $this->tenantId,
            ':case_id' => (int)$data['evaluation_case_id'],
            ':snapshot_id' => (int)$data['evidence_snapshot_id'],
            ':match_id' => $data['match_id'] ?? null,
            ':machine_rel' => $data['machine_relationship'] ?? null,
            ':reviewer_rel' => $data['reviewer_relationship'] ?? null,
            ':action' => $data['reviewer_action'] ?? 'acknowledged',
            ':reason' => $data['reviewer_reason'] ?? null,
            ':reviewer_id' => (int)$data['reviewer_id'],
        ]);
        return (int)$this->db->lastInsertId();
    }
}
