<?php
declare(strict_types=1);

/**
 * Manages revision requests and revised manuscript submissions.
 */
class AcademicThesisRevisionService
{
    private string $tenantId;
    private EvaluationCaseRepository $caseRepo;
    private ManuscriptVersionRepository $manuscriptRepo;
    private RevisionRequestRepository $revisionRepo;
    private AuditEventRepository $auditRepo;

    public function __construct(string $tenantId)
    {
        $this->tenantId = $tenantId;
        $this->caseRepo = new EvaluationCaseRepository($tenantId);
        $this->manuscriptRepo = new ManuscriptVersionRepository($tenantId);
        $this->revisionRepo = new RevisionRequestRepository($tenantId);
        $this->auditRepo = new AuditEventRepository($tenantId);
    }

    public function createRequest(int $caseId, array $data): array
    {
        $case = $this->caseRepo->findById($caseId);
        if (!$case) {
            return ['ok' => false, 'error' => 'Evaluation case not found'];
        }

        if (empty($data['instruction'])) {
            return ['ok' => false, 'error' => 'instruction is required'];
        }

        $revisionId = $this->revisionRepo->create([
            'evaluation_case_id' => $caseId,
            'source_stage_id' => $data['source_stage_id'] ?? null,
            'manuscript_version_id' => $data['manuscript_version_id'] ?? $case['active_manuscript_version_id'] ?? null,
            'category' => $data['category'] ?? 'other',
            'severity' => $data['severity'] ?? 'minor',
            'instruction' => $data['instruction'],
            'evidence_reference' => $data['evidence_reference'] ?? null,
            'assigned_to' => $data['assigned_to'] ?? null,
            'status' => 'open',
            'due_at' => $data['due_at'] ?? null,
            'created_by' => (int)($data['created_by'] ?? 0),
        ]);

        $this->auditRepo->record([
            'case_id' => $caseId,
            'actor_id' => (int)($data['created_by'] ?? 0),
            'action' => 'revision_requested',
            'after_state' => [
                'revision_id' => $revisionId,
                'category' => $data['category'] ?? 'other',
                'severity' => $data['severity'] ?? 'minor',
            ],
        ]);

        $revision = $this->revisionRepo->findById($revisionId);
        return ['ok' => true, 'data' => $revision];
    }

    public function resolve(int $revisionId, array $data): array
    {
        $revision = $this->revisionRepo->findById($revisionId);
        if (!$revision) {
            return ['ok' => false, 'error' => 'Revision request not found'];
        }

        $resolvedVersionId = (int)($data['resolved_in_version_id'] ?? 0);
        if (!$resolvedVersionId) {
            return ['ok' => false, 'error' => 'resolved_in_version_id required'];
        }

        $this->revisionRepo->resolve($revisionId, $resolvedVersionId);

        $this->auditRepo->record([
            'case_id' => (int)$revision['evaluation_case_id'],
            'actor_id' => (int)($data['resolved_by'] ?? 0),
            'action' => 'revision_resolved',
            'after_state' => ['revision_id' => $revisionId, 'resolved_in_version' => $resolvedVersionId],
        ]);

        return ['ok' => true, 'message' => 'Revision resolved'];
    }

    public function submitRevision(int $caseId, array $data): array
    {
        $caseService = new AcademicThesisEvaluationCaseService($this->tenantId);
        $data['submission_note'] = $data['submission_note'] ?? 'Revised manuscript submission';
        return $caseService->submitManuscript($caseId, $data);
    }
}
