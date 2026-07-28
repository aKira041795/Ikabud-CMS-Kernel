<?php
declare(strict_types=1);

/**
 * Manages evaluation profiles — CRUD for workflow definitions.
 */
class AcademicThesisProfileService
{
    private string $tenantId;
    private EvaluationProfileRepository $profileRepo;
    private AuditEventRepository $auditRepo;

    public function __construct(string $tenantId)
    {
        $this->tenantId = $tenantId;
        $this->profileRepo = new EvaluationProfileRepository($tenantId);
        $this->auditRepo = new AuditEventRepository($tenantId);
    }

    public function create(array $data): array
    {
        if (empty($data['code']) || empty($data['name'])) {
            return ['ok' => false, 'error' => 'code and name are required'];
        }

        // Load workflow definition from config if not provided
        $workflowDef = $data['workflow_definition'] ?? null;
        if (!$workflowDef) {
            $configPath = __DIR__ . '/../../config/' . $data['code'] . '.json';
            if (file_exists($configPath)) {
                $workflowDef = file_get_contents($configPath);
            }
        }

        $profileId = $this->profileRepo->create([
            'code' => $data['code'],
            'name' => $data['name'],
            'degree_level' => $data['degree_level'] ?? '',
            'version' => $data['version'] ?? '1.0',
            'description' => $data['description'] ?? null,
            'status' => $data['status'] ?? 'active',
            'workflow_definition' => $workflowDef,
            'rubric_definition' => $data['rubric_definition'] ?? null,
            'policy_reference' => $data['policy_reference'] ?? null,
            'created_by' => $data['created_by'] ?? null,
        ]);

        $this->auditRepo->record([
            'actor_id' => (int)($data['created_by'] ?? 0),
            'action' => 'profile_created',
            'after_state' => ['code' => $data['code'], 'id' => $profileId],
        ]);

        $profile = $this->profileRepo->findById($profileId);
        return ['ok' => true, 'data' => $profile];
    }

    public function loadWorkflowProfile(string $profileCode): ?WorkflowProfile
    {
        $profile = $this->profileRepo->findByCode($profileCode);
        if (!$profile || empty($profile['workflow_definition'])) {
            // Try loading from config file
            $configPath = __DIR__ . '/../../config/' . $profileCode . '.json';
            if (file_exists($configPath)) {
                $def = json_decode(file_get_contents($configPath), true);
                if (is_array($def)) {
                    return new WorkflowProfile($def);
                }
            }
            return null;
        }
        $def = json_decode($profile['workflow_definition'], true);
        if (!is_array($def)) {
            return null;
        }
        return new WorkflowProfile($def);
    }
}
