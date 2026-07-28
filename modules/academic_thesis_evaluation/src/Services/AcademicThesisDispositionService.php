<?php
declare(strict_types=1);

/**
 * Manages final disposition issuance with authority enforcement.
 */
class AcademicThesisDispositionService
{
    private string $tenantId;
    private EvaluationCaseRepository $caseRepo;
    private FinalDispositionRepository $dispositionRepo;
    private RevisionRequestRepository $revisionRepo;
    private AuditEventRepository $auditRepo;

    /**
     * Roles authorized to issue final dispositions.
     */
    private const AUTHORIZED_ROLES = ['graduate_dean', 'dean', 'director', 'admin'];

    /**
     * Valid disposition statuses.
     */
    private const VALID_STATUSES = [
        'approved',
        'approved_with_minor_revisions',
        'approved_with_major_revisions',
        'resubmission_required',
        'deferred',
        'referred_for_formal_integrity_review',
        'not_approved',
        'withdrawn',
    ];

    public function __construct(string $tenantId)
    {
        $this->tenantId = $tenantId;
        $this->caseRepo = new EvaluationCaseRepository($tenantId);
        $this->dispositionRepo = new FinalDispositionRepository($tenantId);
        $this->revisionRepo = new RevisionRequestRepository($tenantId);
        $this->auditRepo = new AuditEventRepository($tenantId);
    }

    public function issue(int $caseId, array $data): array
    {
        $case = $this->caseRepo->findById($caseId);
        if (!$case) {
            return ['ok' => false, 'error' => 'Evaluation case not found'];
        }

        // Authority check
        $authorityRole = $data['authority_role'] ?? '';
        if (!in_array($authorityRole, self::AUTHORIZED_ROLES, true)) {
            return ['ok' => false, 'error' => "Role '{$authorityRole}' is not authorized to issue disposition. Required: " . implode(', ', self::AUTHORIZED_ROLES)];
        }

        // Status validation
        $status = $data['status'] ?? '';
        if (!in_array($status, self::VALID_STATUSES, true)) {
            return ['ok' => false, 'error' => "Invalid disposition status: '{$status}'. Valid: " . implode(', ', self::VALID_STATUSES)];
        }

        // For approval statuses, check no unresolved critical revision items
        $approvalStatuses = ['approved', 'approved_with_minor_revisions', 'approved_with_major_revisions'];
        if (in_array($status, $approvalStatuses, true)) {
            $unresolved = $this->revisionRepo->countUnresolved($caseId);
            // Only block if there are unresolved *critical* items — for now, block all unresolved
            if ($unresolved > 0 && $status === 'approved') {
                return ['ok' => false, 'error' => "Cannot approve: {$unresolved} unresolved revision items remain"];
            }
        }

        // Check if disposition already exists
        $existing = $this->dispositionRepo->findByCaseId($caseId);
        if ($existing) {
            return ['ok' => false, 'error' => 'Disposition already issued for this case'];
        }

        $dispositionId = $this->dispositionRepo->create([
            'evaluation_case_id' => $caseId,
            'status' => $status,
            'decision_summary' => $data['decision_summary'] ?? null,
            'conditions' => $data['conditions'] ?? null,
            'effective_date' => $data['effective_date'] ?? date('Y-m-d'),
            'decided_by' => (int)($data['decided_by'] ?? 0),
            'authority_role' => $authorityRole,
        ]);

        // Update case status
        $this->caseRepo->updateStage($caseId, $case['current_stage'], 'completed');

        $this->auditRepo->record([
            'case_id' => $caseId,
            'actor_id' => (int)($data['decided_by'] ?? 0),
            'actor_role' => $authorityRole,
            'action' => 'disposition_issued',
            'after_state' => ['status' => $status, 'disposition_id' => $dispositionId],
        ]);

        return ['ok' => true, 'data' => ['disposition_id' => $dispositionId, 'status' => $status]];
    }
}
