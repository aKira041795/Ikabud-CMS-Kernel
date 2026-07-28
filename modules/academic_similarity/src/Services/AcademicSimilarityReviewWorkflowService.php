<?php
declare(strict_types=1);

/**
 * AISS — Reviewer Workflow Service (Phase 4)
 *
 * Manages the human-review workflow lifecycle for evidence evaluation.
 *
 * Workflow states:
 *   submitted → evidence_generated → reviewer_screening → student_clarification
 *   → academic_review → resolved
 *
 * There is no automatic "plagiarized" state. For every serious outcome,
 * the system requires: human reviewer, selected evidence, rationale,
 * timestamp, policy reference, and optional second reviewer.
 *
 * Reviewer actions:
 *   - confirm_relationship   — Agree with machine classification
 *   - reclassify_evidence    — Change evidence type
 *   - exclude_common_knowledge — Mark as common disciplinary knowledge
 *   - mark_standard_method   — Mark as standard procedure
 *   - identify_quotation     — Mark as legitimate quotation
 *   - flag_insufficient_attribution — Flag for follow-up
 *   - request_revision       — Request student revision
 *   - refer_formal_review    — Refer for formal academic review
 *   - leave_note             — Leave explanatory note
 *
 * @see AcademicSimilarityEvidenceTaxonomy for classification values
 */

class AcademicSimilarityReviewWorkflowService
{
    private string $tenantId;
    private \Ikabud\Kernel\Contracts\ModuleDB $db;

    /** Allowed reviewer actions */
    public const ACTIONS = [
        'confirm_relationship',
        'reclassify_evidence',
        'exclude_common_knowledge',
        'mark_standard_method',
        'identify_quotation',
        'flag_insufficient_attribution',
        'request_revision',
        'refer_formal_review',
        'leave_note',
    ];

    /** Workflow states */
    public const STATE_SUBMITTED           = 'submitted';
    public const STATE_EVIDENCE_GENERATED  = 'evidence_generated';
    public const STATE_REVIEWER_SCREENING  = 'reviewer_screening';
    public const STATE_STUDENT_CLARIFICATION = 'student_clarification';
    public const STATE_ACADEMIC_REVIEW     = 'academic_review';
    public const STATE_RESOLVED            = 'resolved';

    public const STATES = [
        self::STATE_SUBMITTED,
        self::STATE_EVIDENCE_GENERATED,
        self::STATE_REVIEWER_SCREENING,
        self::STATE_STUDENT_CLARIFICATION,
        self::STATE_ACADEMIC_REVIEW,
        self::STATE_RESOLVED,
    ];

    public function __construct(string $tenantId)
    {
        $this->tenantId = $tenantId;
        $this->db = academic_similarity_db();
    }

    /**
     * Perform a reviewer action on a match.
     *
     * @param int $matchId The match ID
     * @param string $action The action to perform (see self::ACTIONS)
     * @param int $userId The reviewer user ID
     * @param string $reason Reason for the action
     * @param array|null $classification Optional new classification (for reclassify actions)
     * @return array{ok: bool, match?: array, error?: string}
     */
    public function performAction(int $matchId, string $action, int $userId, string $reason, ?array $classification = null): array
    {
        if (!in_array($action, self::ACTIONS, true)) {
            return ['ok' => false, 'error' => "Invalid action: {$action}. Allowed: " . implode(', ', self::ACTIONS)];
        }

        if ($reason === '') {
            return ['ok' => false, 'error' => 'A reason is required for all reviewer actions'];
        }

        // Load the match
        $stmt = $this->db->prepare(
            'SELECT m.*, s.submission_title, s.status AS submission_status
             FROM ac_similarity_matches m
             JOIN ac_similarity_submissions s ON s.id = m.submission_id AND s.tenant_id = m.tenant_id
             WHERE m.id = :id AND m.tenant_id = :tid'
        );
        $stmt->execute([':id' => $matchId, ':tid' => $this->tenantId]);
        $match = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$match) {
            return ['ok' => false, 'error' => 'Match not found'];
        }

        $this->db->beginTransaction();
        try {
            // Apply the action
            $updateData = $this->buildActionUpdate($action, $userId, $reason, $classification, $match);

            // Update the match record
            if (!empty($updateData['columns'])) {
                $sets = [];
                $params = [':id' => $matchId, ':tid' => $this->tenantId];
                foreach ($updateData['columns'] as $col => $val) {
                    $sets[] = "{$col} = :{$col}";
                    $params[":{$col}"] = $val;
                }
                $sql = 'UPDATE ac_similarity_matches SET ' . implode(', ', $sets) . ' WHERE id = :id AND tenant_id = :tid';
                $this->db->prepare($sql)->execute($params);
            }

            // Update submission workflow state if needed
            if (isset($updateData['new_submission_state'])) {
                $this->updateSubmissionWorkflowState(
                    (int)$match['submission_id'],
                    $updateData['new_submission_state']
                );
            }

            // Record audit event
            $this->recordAudit($matchId, $action, $userId, $reason, $updateData);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            write_log('Review workflow action failed: ' . $e->getMessage(), 'error', [
                'match_id' => $matchId, 'action' => $action, 'user_id' => $userId,
            ]);
            return ['ok' => false, 'error' => 'Review action failed: ' . $e->getMessage()];
        }

        // Re-fetch updated match
        $stmt = $this->db->prepare('SELECT * FROM ac_similarity_matches WHERE id = :id AND tenant_id = :tid');
        $stmt->execute([':id' => $matchId, ':tid' => $this->tenantId]);
        $updated = $stmt->fetch(\PDO::FETCH_ASSOC);

        return ['ok' => true, 'match' => $updated];
    }

    /**
     * Build the column updates and new state for a reviewer action.
     */
    private function buildActionUpdate(string $action, int $userId, string $reason, ?array $classification, array $match): array
    {
        $columns = [
            'reviewed_by' => $userId,
            'reviewed_at' => date('Y-m-d H:i:s'),
            'reviewer_reason' => $reason,
        ];

        $newSubmissionState = null;

        switch ($action) {
            case 'confirm_relationship':
                $columns['reviewer_decision'] = 'confirmed';
                $columns['reviewer_classification'] = $match['match_type'];
                break;

            case 'reclassify_evidence':
                $newType = $classification['evidence_type'] ?? null;
                if ($newType !== null && AcademicSimilarityEvidenceTaxonomy::isValidEvidenceType($newType)) {
                    $columns['reviewer_decision'] = 'reclassified';
                    $columns['reviewer_classification'] = $newType;
                    $columns['context_relationship'] = $classification['context_relationship'] ?? $match['context_relationship'];
                    $columns['scholarly_relationship'] = $classification['scholarly_relationship'] ?? $match['scholarly_relationship'];
                    $columns['attribution_status'] = $classification['attribution_status'] ?? $match['attribution_status'];
                }
                break;

            case 'exclude_common_knowledge':
                $columns['reviewer_decision'] = 'excluded_common_knowledge';
                $columns['is_excluded'] = 1;
                $columns['scholarly_relationship'] = AcademicSimilarityEvidenceTaxonomy::SCHOLARLY_COMMON_KNOWLEDGE;
                $columns['exclusion_reason'] = 'Common disciplinary knowledge';
                break;

            case 'mark_standard_method':
                $columns['reviewer_decision'] = 'standard_method';
                $columns['scholarly_relationship'] = AcademicSimilarityEvidenceTaxonomy::SCHOLARLY_STANDARD_METHOD;
                break;

            case 'identify_quotation':
                $columns['reviewer_decision'] = 'identified_quotation';
                $columns['reviewer_classification'] = AcademicSimilarityEvidenceTaxonomy::EVIDENCE_TYPE_QUOTATION;
                $columns['attribution_status'] = AcademicSimilarityEvidenceTaxonomy::ATTRIBUTION_PRESENT_SUPPORTED;
                break;

            case 'flag_insufficient_attribution':
                $columns['reviewer_decision'] = 'flagged_insufficient_attribution';
                $columns['attribution_status'] = AcademicSimilarityEvidenceTaxonomy::ATTRIBUTION_PRESENT_INCOMPLETE;
                $newSubmissionState = self::STATE_REVIEWER_SCREENING;
                break;

            case 'request_revision':
                $columns['reviewer_decision'] = 'revision_requested';
                $newSubmissionState = self::STATE_STUDENT_CLARIFICATION;
                break;

            case 'refer_formal_review':
                $columns['reviewer_decision'] = 'referred_formal_review';
                $newSubmissionState = self::STATE_ACADEMIC_REVIEW;
                break;

            case 'leave_note':
                $columns['reviewer_decision'] = 'note_left';
                break;
        }

        return [
            'columns' => $columns,
            'new_submission_state' => $newSubmissionState,
        ];
    }

    /**
     * Update the submission's workflow state.
     */
    private function updateSubmissionWorkflowState(int $submissionId, string $newState): void
    {
        if (!in_array($newState, self::STATES, true)) {
            return;
        }

        $sql = "UPDATE ac_similarity_submissions SET workflow_state = :state WHERE id = :id AND tenant_id = :tid";
        $this->db->prepare($sql)->execute([
            ':state' => $newState,
            ':id' => $submissionId,
            ':tid' => $this->tenantId,
        ]);
    }

    /**
     * Get the workflow history for a submission.
     */
    public function getWorkflowHistory(int $submissionId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, submission_id, match_id, action, user_id, reason, old_classification, new_classification, created_at
             FROM ac_similarity_reviews
             WHERE submission_id = :sid AND tenant_id = :tid
             ORDER BY created_at ASC'
        );
        $stmt->execute([':sid' => $submissionId, ':tid' => $this->tenantId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Get the current workflow state for a submission.
     */
    public function getSubmissionWorkflowState(int $submissionId): string
    {
        $stmt = $this->db->prepare(
            'SELECT workflow_state FROM ac_similarity_submissions WHERE id = :id AND tenant_id = :tid'
        );
        $stmt->execute([':id' => $submissionId, ':tid' => $this->tenantId]);
        return (string)($stmt->fetchColumn() ?: self::STATE_SUBMITTED);
    }

    /**
     * Record an audit event for the review action.
     */
    private function recordAudit(int $matchId, string $action, int $userId, string $reason, array $updateData): void
    {
        try {
            $auditRepo = new AcademicSimilarityAuditRepository($this->tenantId);
            $auditRepo->record(
                'review.action',
                $userId,
                'system',
                'match',
                $matchId,
                "Review action '{$action}': {$reason}",
                [
                    'action' => $action,
                    'reason' => $reason,
                    'columns' => $updateData['columns'] ?? [],
                    'new_state' => $updateData['new_submission_state'] ?? null,
                ]
            );
        } catch (\Throwable $e) {
            write_log('Failed to record review audit: ' . $e->getMessage());
        }
    }
}
