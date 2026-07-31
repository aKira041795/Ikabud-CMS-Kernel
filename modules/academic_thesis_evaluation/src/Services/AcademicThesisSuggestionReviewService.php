<?php
declare(strict_types=1);

class AcademicThesisSuggestionReviewService
{
    private string $tenantId;
    private AissEvidenceSnapshotRepository $snapshotRepo;
    private EvidenceSuggestionRepository $suggestionRepo;
    private AuditEventRepository $auditRepo;

    private const STATUSES = ['pending', 'accepted', 'edited', 'dismissed', 'converted_to_revision'];

    public function __construct(string $tenantId)
    {
        $this->tenantId = $tenantId;
        $this->snapshotRepo = new AissEvidenceSnapshotRepository($tenantId);
        $this->suggestionRepo = new EvidenceSuggestionRepository($tenantId);
        $this->auditRepo = new AuditEventRepository($tenantId);
    }

    public function review(int $snapshotId, array $data, ?int $expectedCaseId = null): array
    {
        $snapshot = $this->snapshotRepo->findById($snapshotId);
        if (!$snapshot) {
            return ['ok' => false, 'error' => 'Evidence snapshot not found'];
        }
        if ((int)($data['reviewer_id'] ?? 0) <= 0) {
            return ['ok' => false, 'error' => 'reviewer_id is required'];
        }
        if ($expectedCaseId !== null && (int)$snapshot['evaluation_case_id'] !== $expectedCaseId) {
            return ['ok' => false, 'error' => 'Evidence snapshot does not belong to this case'];
        }

        $status = (string)($data['reviewer_status'] ?? 'pending');
        if (!in_array($status, self::STATUSES, true)) {
            return ['ok' => false, 'error' => 'Invalid suggestion reviewer_status'];
        }
        if ($status !== 'pending' && trim((string)($data['reviewer_reason'] ?? '')) === '') {
            return ['ok' => false, 'error' => 'reviewer_reason is required for suggestion disposition'];
        }
        if ($status === 'edited' && trim((string)($data['reviewer_rationale'] ?? '')) === '') {
            return ['ok' => false, 'error' => 'reviewer_rationale is required when editing a suggestion'];
        }
        if ($status === 'converted_to_revision' && (int)($data['revision_request_id'] ?? 0) <= 0) {
            return ['ok' => false, 'error' => 'revision_request_id is required when converting a suggestion to a revision'];
        }

        $machine = is_array($data['machine_suggestion'] ?? null) ? $data['machine_suggestion'] : [];
        $suggestionKey = trim((string)($machine['suggestion_key'] ?? $data['suggestion_key'] ?? ''));
        $machineRationale = trim((string)($machine['rationale'] ?? $data['machine_rationale'] ?? ''));
        if ($suggestionKey === '' || $machineRationale === '') {
            return ['ok' => false, 'error' => 'machine suggestion key and rationale are required'];
        }
        $suggestionId = $this->suggestionRepo->create([
            'evaluation_case_id' => (int)$snapshot['evaluation_case_id'],
            'evidence_snapshot_id' => $snapshotId,
            'machine_suggestion_id' => $machine['id'] ?? ($data['machine_suggestion_id'] ?? null),
            'suggestion_key' => $suggestionKey,
            'machine_category' => (string)($machine['category'] ?? $data['machine_category'] ?? 'reviewer_attention'),
            'machine_priority' => (string)($machine['priority'] ?? $data['machine_priority'] ?? 'medium'),
            'machine_action' => (string)($machine['reviewer_action'] ?? $data['machine_action'] ?? 'verify'),
            'machine_title' => (string)($machine['title'] ?? $data['machine_title'] ?? 'Reviewer suggestion'),
            'machine_rationale' => $machineRationale,
            'reviewer_status' => $status,
            'reviewer_title' => $data['reviewer_title'] ?? null,
            'reviewer_rationale' => $data['reviewer_rationale'] ?? null,
            'reviewer_reason' => $data['reviewer_reason'] ?? null,
            'rubric_criterion_id' => $data['rubric_criterion_id'] ?? null,
            'revision_request_id' => $data['revision_request_id'] ?? null,
            'reviewer_id' => (int)($data['reviewer_id'] ?? 0),
            'version' => 1,
        ]);

        $this->auditRepo->record([
            'case_id' => (int)$snapshot['evaluation_case_id'],
            'actor_id' => (int)($data['reviewer_id'] ?? 0),
            'action' => 'aiss_suggestion_reviewed',
            'after_state' => [
                'suggestion_review_id' => $suggestionId,
                'reviewer_status' => $status,
                'rubric_criterion_id' => $data['rubric_criterion_id'] ?? null,
                'revision_request_id' => $data['revision_request_id'] ?? null,
            ],
        ]);

        return ['ok' => true, 'data' => ['suggestion_review_id' => $suggestionId]];
    }

    public function listForCase(int $caseId): array
    {
        return ['ok' => true, 'data' => $this->suggestionRepo->findByCaseId($caseId)];
    }
}
