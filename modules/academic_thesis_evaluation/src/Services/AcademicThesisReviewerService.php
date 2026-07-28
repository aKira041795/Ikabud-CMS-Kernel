<?php
declare(strict_types=1);

/**
 * Manages reviewer assignments and submissions.
 */
class AcademicThesisReviewerService
{
    private string $tenantId;
    private ReviewerAssignmentRepository $assignmentRepo;
    private AuditEventRepository $auditRepo;

    public function __construct(string $tenantId)
    {
        $this->tenantId = $tenantId;
        $this->assignmentRepo = new ReviewerAssignmentRepository($tenantId);
        $this->auditRepo = new AuditEventRepository($tenantId);
    }

    public function assign(int $caseId, array $data): array
    {
        $caseRepo = new EvaluationCaseRepository($this->tenantId);
        $case = $caseRepo->findById($caseId);
        if (!$case) {
            return ['ok' => false, 'error' => 'Evaluation case not found'];
        }

        $assignmentId = $this->assignmentRepo->create([
            'evaluation_case_id' => $caseId,
            'stage_id' => $data['stage_id'] ?? null,
            'reviewer_id' => (int)$data['reviewer_id'],
            'reviewer_role' => $data['reviewer_role'],
            'assignment_type' => $data['assignment_type'] ?? 'primary',
            'status' => 'pending',
        ]);

        $this->auditRepo->record([
            'case_id' => $caseId,
            'actor_id' => (int)($data['actor_id'] ?? 0),
            'action' => 'reviewer_assigned',
            'after_state' => ['reviewer_id' => $data['reviewer_id'], 'role' => $data['reviewer_role']],
        ]);

        $assignment = $this->assignmentRepo->findById($assignmentId);
        return ['ok' => true, 'data' => $assignment];
    }

    public function accept(int $assignmentId, int $reviewerId): array
    {
        $assignment = $this->assignmentRepo->findById($assignmentId);
        if (!$assignment) {
            return ['ok' => false, 'error' => 'Assignment not found'];
        }

        if ((int)$assignment['reviewer_id'] !== $reviewerId) {
            return ['ok' => false, 'error' => 'Only the assigned reviewer can accept'];
        }

        $this->assignmentRepo->accept($assignmentId);

        $this->auditRepo->record([
            'case_id' => (int)$assignment['evaluation_case_id'],
            'actor_id' => $reviewerId,
            'actor_role' => $assignment['reviewer_role'],
            'action' => 'reviewer_accepted',
            'after_state' => ['status' => 'accepted'],
        ]);

        return ['ok' => true, 'message' => 'Assignment accepted'];
    }

    public function submitFindings(int $assignmentId, array $data): array
    {
        $assignment = $this->assignmentRepo->findById($assignmentId);
        if (!$assignment) {
            return ['ok' => false, 'error' => 'Assignment not found'];
        }

        $this->assignmentRepo->complete($assignmentId);

        $this->auditRepo->record([
            'case_id' => (int)$assignment['evaluation_case_id'],
            'actor_id' => (int)$assignment['reviewer_id'],
            'actor_role' => $assignment['reviewer_role'],
            'action' => 'reviewer_submitted',
        ]);

        return ['ok' => true, 'message' => 'Findings submitted'];
    }
}
