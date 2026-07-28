<?php
declare(strict_types=1);

/**
 * Workflow service — convenience wrapper around the workflow engine for common operations.
 */
class AcademicThesisWorkflowService
{
    private string $tenantId;
    private EvaluationWorkflowEngine $engine;

    public function __construct(string $tenantId)
    {
        $this->tenantId = $tenantId;
        $this->engine = new EvaluationWorkflowEngine($tenantId);
    }

    public function advanceToNextStage(int $caseId, string $outcome, int $actorId, string $reason = ''): TransitionResult
    {
        $caseRepo = new EvaluationCaseRepository($this->tenantId);
        $case = $caseRepo->findById($caseId);
        if (!$case) {
            return TransitionResult::failure('Case not found');
        }

        $profileService = new AcademicThesisProfileService($this->tenantId);
        $profile = $profileService->loadWorkflowProfile($case['profile_code'] ?? '');
        if (!$profile) {
            return TransitionResult::failure('Workflow profile not found');
        }

        $currentStage = $profile->getStage($case['current_stage']);
        if (!$currentStage) {
            return TransitionResult::failure('Unknown current stage');
        }

        $targetStage = $currentStage->outcomeTarget($outcome);
        if (!$targetStage) {
            // Try next array
            $allowed = $currentStage->next;
            if (!empty($allowed)) {
                $targetStage = $allowed[0];
            }
        }

        if (!$targetStage) {
            return TransitionResult::failure("No target stage for outcome '{$outcome}'");
        }

        return $this->engine->transition($caseId, $targetStage, $actorId, $reason);
    }
}
