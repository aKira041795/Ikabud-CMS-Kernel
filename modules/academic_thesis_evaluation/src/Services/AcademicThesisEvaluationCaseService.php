<?php
declare(strict_types=1);

/**
 * Handles evaluation case lifecycle: creation, manuscript submission, state queries.
 */
class AcademicThesisEvaluationCaseService
{
    private string $tenantId;
    private EvaluationCaseRepository $caseRepo;
    private ManuscriptVersionRepository $manuscriptRepo;
    private EvaluationStageRepository $stageRepo;
    private AuditEventRepository $auditRepo;

    public function __construct(string $tenantId)
    {
        $this->tenantId = $tenantId;
        $this->caseRepo = new EvaluationCaseRepository($tenantId);
        $this->manuscriptRepo = new ManuscriptVersionRepository($tenantId);
        $this->stageRepo = new EvaluationStageRepository($tenantId);
        $this->auditRepo = new AuditEventRepository($tenantId);
    }

    public function create(array $data): array
    {
        $required = ['profile_code', 'title', 'submission_owner_id'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                return ['ok' => false, 'error' => "{$field} is required"];
            }
        }

        // Resolve profile
        $profileRepo = new EvaluationProfileRepository($this->tenantId);
        $profile = $profileRepo->findByCode($data['profile_code']);
        if (!$profile) {
            return ['ok' => false, 'error' => "Profile not found: {$data['profile_code']}"];
        }

        $caseData = [
            'profile_id' => (int)$profile['id'],
            'submission_owner_id' => (int)$data['submission_owner_id'],
            'student_number' => $data['student_number'] ?? null,
            'program_id' => $data['program_id'] ?? null,
            'title' => $data['title'],
            'research_category' => $data['research_category'] ?? null,
            'thesis_type' => $data['thesis_type'] ?? $profile['degree_level'],
            'current_stage' => 'submission',
            'status' => 'submitted',
            'adviser_id' => $data['adviser_id'] ?? null,
            'panel_chair_id' => $data['panel_chair_id'] ?? null,
            'ethics_approval_ref' => $data['ethics_approval_ref'] ?? null,
        ];

        $caseId = $this->caseRepo->create($caseData);

        // Create initial submission stage
        $this->stageRepo->create([
            'evaluation_case_id' => $caseId,
            'stage_code' => 'submission',
            'stage_order' => 0,
            'status' => 'active',
            'assigned_role' => 'student',
            'opened_at' => date('Y-m-d H:i:s'),
        ]);

        // Audit
        $this->auditRepo->record([
            'case_id' => $caseId,
            'actor_id' => (int)$data['submission_owner_id'],
            'actor_role' => 'student',
            'action' => 'case_created',
            'after_state' => ['title' => $data['title'], 'profile_code' => $data['profile_code']],
        ]);

        $created = $this->caseRepo->findById($caseId);
        return ['ok' => true, 'data' => $created];
    }

    public function submitManuscript(int $caseId, array $data): array
    {
        $case = $this->caseRepo->findById($caseId);
        if (!$case) {
            return ['ok' => false, 'error' => 'Evaluation case not found'];
        }

        if (empty($data['file_reference'])) {
            return ['ok' => false, 'error' => 'file_reference is required'];
        }

        $nextVersion = $this->manuscriptRepo->getLatestVersionNumber($caseId) + 1;
        $isRevision = $nextVersion > 1;

        $manuscriptData = [
            'evaluation_case_id' => $caseId,
            'version_number' => $nextVersion,
            'file_reference' => $data['file_reference'],
            'file_hash' => $data['file_hash'] ?? '',
            'word_count' => $data['word_count'] ?? null,
            'submitted_by' => (int)($data['submitted_by'] ?? $data['submission_owner_id'] ?? 0),
            'submission_note' => $data['submission_note'] ?? null,
            'is_revision' => $isRevision ? 1 : 0,
        ];

        $versionId = $this->manuscriptRepo->create($manuscriptData);

        // Set as active manuscript version
        $this->caseRepo->setActiveManuscript($caseId, $versionId);

        // Audit
        $this->auditRepo->record([
            'case_id' => $caseId,
            'actor_id' => $manuscriptData['submitted_by'],
            'action' => $isRevision ? 'manuscript_uploaded' : 'manuscript_uploaded',
            'after_state' => ['version' => $nextVersion, 'file_hash' => $data['file_hash'] ?? ''],
        ]);

        // Auto-generate AISS if configured
        $settings = ate_get_settings($this->tenantId);
        if ($settings['auto_generate_aiss_on_submit'] === '1') {
            try {
                $adapter = new AcademicThesisAissAdapter($this->tenantId);
                $adapter->generateSnapshot($caseId, $manuscriptData['submitted_by']);
            } catch (\Throwable $e) {
                if (function_exists('write_log')) {
                    write_log("ATE: Auto AISS generation failed for case {$caseId}: " . $e->getMessage(), 'warning');
                }
            }
        }

        $version = $this->manuscriptRepo->findById($versionId);
        return ['ok' => true, 'data' => $version];
    }
}
