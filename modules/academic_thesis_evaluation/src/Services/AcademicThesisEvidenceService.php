<?php
declare(strict_types=1);

/**
 * Manages AISS evidence review decisions — human interpretation of machine output.
 */
class AcademicThesisEvidenceService
{
    private string $tenantId;
    private AissEvidenceSnapshotRepository $snapshotRepo;
    private EvidenceReviewDecisionRepository $decisionRepo;
    private AuditEventRepository $auditRepo;

    public function __construct(string $tenantId)
    {
        $this->tenantId = $tenantId;
        $this->snapshotRepo = new AissEvidenceSnapshotRepository($tenantId);
        $this->decisionRepo = new EvidenceReviewDecisionRepository($tenantId);
        $this->auditRepo = new AuditEventRepository($tenantId);
    }

    public function recordReview(int $snapshotId, array $data): array
    {
        $snapshot = $this->snapshotRepo->findById($snapshotId);
        if (!$snapshot) {
            return ['ok' => false, 'error' => 'Evidence snapshot not found'];
        }

        if (empty($data['reviewer_id'])) {
            return ['ok' => false, 'error' => 'reviewer_id is required'];
        }

        $decisionId = $this->decisionRepo->create([
            'evaluation_case_id' => (int)$snapshot['evaluation_case_id'],
            'evidence_snapshot_id' => $snapshotId,
            'match_id' => $data['match_id'] ?? null,
            'machine_relationship' => $data['machine_relationship'] ?? null,
            'reviewer_relationship' => $data['reviewer_relationship'] ?? null,
            'reviewer_action' => $data['reviewer_action'] ?? 'acknowledged',
            'reviewer_reason' => $data['reviewer_reason'] ?? null,
            'reviewer_id' => (int)$data['reviewer_id'],
        ]);

        $this->auditRepo->record([
            'case_id' => (int)$snapshot['evaluation_case_id'],
            'actor_id' => (int)$data['reviewer_id'],
            'action' => 'evidence_reclassified',
            'after_state' => [
                'machine_relationship' => $data['machine_relationship'] ?? null,
                'reviewer_relationship' => $data['reviewer_relationship'] ?? null,
                'action' => $data['reviewer_action'] ?? 'acknowledged',
            ],
        ]);

        return ['ok' => true, 'data' => ['decision_id' => $decisionId]];
    }
}
