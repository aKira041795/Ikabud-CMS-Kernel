<?php
declare(strict_types=1);

/**
 * Workflow engine — validates and executes stage transitions.
 */
class EvaluationWorkflowEngine
{
    private string $tenantId;
    private EvaluationCaseRepository $caseRepo;
    private EvaluationStageRepository $stageRepo;
    private AuditEventRepository $auditRepo;

    public function __construct(string $tenantId)
    {
        $this->tenantId = $tenantId;
        $this->caseRepo = new EvaluationCaseRepository($tenantId);
        $this->stageRepo = new EvaluationStageRepository($tenantId);
        $this->auditRepo = new AuditEventRepository($tenantId);
    }

    public function transition(int $caseId, string $targetStage, int $actorId, string $reason = '', array $context = []): TransitionResult
    {
        $case = $this->caseRepo->findById($caseId);
        if (!$case) {
            return TransitionResult::failure('Evaluation case not found');
        }

        $currentStage = $case['current_stage'];
        $profile = $this->loadProfile($case['profile_id']);
        if (!$profile) {
            return TransitionResult::failure('Evaluation profile not found or has no workflow definition');
        }

        $currentStageDef = $profile->getStage($currentStage);
        if (!$currentStageDef) {
            return TransitionResult::failure("Unknown current stage: {$currentStage}");
        }

        $targetStageDef = $profile->getStage($targetStage);
        if (!$targetStageDef) {
            return TransitionResult::failure("Unknown target stage: {$targetStage}");
        }

        // Check if transition is allowed
        if (!$currentStageDef->canTransitionTo($targetStage)) {
            $allowed = $profile->getAllowedTransitions($currentStage);
            return TransitionResult::failure(
                "Cannot transition from '{$currentStage}' to '{$targetStage}'. Allowed: " . implode(', ', $allowed)
            );
        }

        // Complete current stage
        $currentStageInstance = $this->stageRepo->findByCaseAndCode($caseId, $currentStage);
        $outcome = $this->determineOutcome($currentStageDef, $targetStage);
        if ($currentStageInstance) {
            $this->stageRepo->complete(
                (int)$currentStageInstance['id'],
                $outcome,
                $actorId,
                $reason
            );
        }

        // Determine stage order
        $stageOrder = count($this->stageRepo->findByCaseId($caseId));

        // Create new stage instance
        $this->stageRepo->create([
            'evaluation_case_id' => $caseId,
            'stage_code' => $targetStage,
            'stage_order' => $stageOrder,
            'status' => 'active',
            'assigned_role' => $targetStageDef->role,
            'opened_at' => date('Y-m-d H:i:s'),
            'notes' => $reason ?: null,
        ]);

        // Update case
        $newStatus = $targetStageDef->terminal ? 'completed' : 'in_review';
        $this->caseRepo->updateStage($caseId, $targetStage, $newStatus);

        // If terminal, set completed_at
        if ($targetStageDef->terminal) {
            $this->caseRepo->updateStage($caseId, $targetStage, 'completed');
            // Also update completed_at - use direct query since updateStage doesn't set it
            $db = ate_db($this->tenantId);
            $db->prepare("UPDATE ate_evaluation_cases SET completed_at = NOW() WHERE id = :id AND tenant_id = :tid")
               ->execute([':id' => $caseId, ':tid' => $this->tenantId]);
        }

        // Audit
        $this->auditRepo->record([
            'case_id' => $caseId,
            'actor_id' => $actorId,
            'action' => 'stage_transitioned',
            'before_state' => ['stage' => $currentStage],
            'after_state' => ['stage' => $targetStage],
            'reason' => $reason,
        ]);

        return TransitionResult::success($targetStage, $newStatus, "Transitioned to {$targetStage}", [
            'case_id' => $caseId,
            'previous_stage' => $currentStage,
            'outcome' => $outcome,
        ]);
    }

    private function loadProfile(int $profileId): ?WorkflowProfile
    {
        $profileRepo = new EvaluationProfileRepository($this->tenantId);
        $profile = $profileRepo->findById($profileId);
        if (!$profile || empty($profile['workflow_definition'])) {
            return null;
        }
        $def = json_decode($profile['workflow_definition'], true);
        if (!is_array($def)) {
            return null;
        }
        return new WorkflowProfile($def);
    }

    private function determineOutcome(StageDefinition $currentStage, string $targetStage): string
    {
        // If target is in next array, it's a direct transition
        if (in_array($targetStage, $currentStage->next, true)) {
            return 'transitioned';
        }
        // Otherwise find which outcome maps to targetStage
        foreach ($currentStage->outcomes as $outcome => $stage) {
            if ($stage === $targetStage) {
                return $outcome;
            }
        }
        return 'transitioned';
    }
}
