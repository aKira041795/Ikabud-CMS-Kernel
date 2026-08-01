<?php
declare(strict_types=1);

/**
 * Academic Thesis Evaluation — helpers, capability handlers, and bootstrap.
 */

define('ATE_DEFAULTS_VERSION', '001');

// ── Auto-load module services ────────────────────────────────────
(function (): void {
    $base = __DIR__ . '/src';
    $files = [
        '/Contracts/WorkflowProfile.php',
        '/ValueObjects/TransitionResult.php',
        '/ValueObjects/StageDefinition.php',
        '/Policies/ThesisTenantPolicy.php',
        '/Repositories/EvaluationProfileRepository.php',
        '/Repositories/EvaluationCaseRepository.php',
        '/Repositories/ManuscriptVersionRepository.php',
        '/Repositories/EvaluationStageRepository.php',
        '/Repositories/ReviewerAssignmentRepository.php',
        '/Repositories/RubricTemplateRepository.php',
        '/Repositories/RubricResponseRepository.php',
        '/Repositories/AissEvidenceSnapshotRepository.php',
        '/Repositories/EvidenceReviewDecisionRepository.php',
        '/Repositories/EvidenceSuggestionRepository.php',
        '/Repositories/RevisionRequestRepository.php',
        '/Repositories/FinalDispositionRepository.php',
        '/Repositories/AuditEventRepository.php',
        '/Services/EvaluationWorkflowEngine.php',
        '/Services/AcademicThesisEvaluationCaseService.php',
        '/Services/AcademicThesisWorkflowService.php',
        '/Services/AcademicThesisProfileService.php',
        '/Services/AcademicThesisRubricService.php',
        '/Services/AcademicThesisReviewerService.php',
        '/Services/AcademicThesisEvidenceService.php',
        '/Services/AcademicThesisSuggestionReviewService.php',
        '/Services/AcademicThesisAissAdapter.php',
        '/Services/AcademicThesisRevisionService.php',
        '/Services/AcademicThesisDispositionService.php',
        '/Services/AcademicThesisReportService.php',
    ];
    foreach ($files as $file) {
        $path = $base . $file;
        if (file_exists($path)) {
            require_once $path;
        }
    }
})();

// ── Render helper ────────────────────────────────────────────────
function ate_render(string $template, array $data = []): string
{
    $data += [
        'active_nav' => '',
        'csrf_token' => app()->csrfToken() ?? '',
    ];
    return app()->render('modules/academic-thesis-evaluation/' . ltrim($template, '/'), $data);
}

// ── DB helper ────────────────────────────────────────────────────
function ate_db(?string $tenantId = null): \Ikabud\Kernel\Contracts\ModuleDB
{
    if (function_exists('module') && module() !== null) {
        try {
            return module()->db();
        } catch (\Throwable $e) {
            // Fall through
        }
    }
    // CLI / test fallback — resolve tenant key/domain to numeric ID if needed
    $rawDb = app()->db();
    if ($tenantId !== null && !is_numeric($tenantId)) {
        // Try to resolve by tenant key or domain
        $stmt = $rawDb->prepare('SELECT t.id FROM kernel_tenants t LEFT JOIN kernel_tenant_domains d ON d.tenant_id = t.id WHERE t.tenant_key = :key OR d.domain = :domain LIMIT 1');
        $stmt->execute([':key' => $tenantId, ':domain' => $tenantId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row && !empty($row['id'])) {
            $tenantId = (string)$row['id'];
        }
    }
    if ($tenantId !== null) {
        $numericId = (int)$tenantId;
        if ($numericId > 0) {
            $tenantDb = app()->dbForTenant($numericId);
            if ($tenantDb === null) {
                throw new \RuntimeException("Cannot resolve database for tenant {$tenantId}");
            }
            $rawDb = $tenantDb;
        }
    }
    $owns = [
        'ate_evaluation_profiles', 'ate_evaluation_cases', 'ate_manuscript_versions',
        'ate_workflow_stages', 'ate_reviewer_assignments', 'ate_rubric_templates',
        'ate_rubric_criteria', 'ate_rubric_responses', 'ate_aiss_evidence_snapshots',
        'ate_evidence_review_decisions', 'ate_evidence_suggestion_reviews',
        'ate_revision_requests', 'ate_final_dispositions', 'ate_audit_events', 'ate_settings',
    ];
    return new \Ikabud\Kernel\Contracts\ModuleDB($rawDb, 'academic-thesis-evaluation', $owns, []);
}

// ── Settings ─────────────────────────────────────────────────────
function ate_get_settings(string $tenantId): array
{
    $defaults = [
        'default_retention_policy' => 'retained_institutionally',
        'max_manuscript_size_mb' => '50',
        'allowed_manuscript_extensions' => 'pdf,docx,doc,txt',
        'require_ethics_clearance' => '1',
        'require_adviser_endorsement' => '1',
        'auto_generate_aiss_on_submit' => '0',
        'aiss_integration_enabled' => '0',
        '_defaults_version' => ATE_DEFAULTS_VERSION,
    ];

    $db = ate_db($tenantId);
    $stmt = $db->prepare("SELECT setting_key, setting_value FROM ate_settings WHERE tenant_id = :tid");
    $stmt->execute([':tid' => $tenantId]);
    $stored = [];
    while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
        $stored[$row['setting_key']] = $row['setting_value'];
    }

    $settings = array_merge($defaults, $stored);

    // Auto-reconcile stale defaults
    $storedVersion = (string)($stored['_defaults_version'] ?? '000');
    if ($storedVersion !== ATE_DEFAULTS_VERSION) {
        $db->prepare("INSERT INTO ate_settings (tenant_id, setting_key, setting_value, updated_at)
            VALUES (:tid, '_defaults_version', :ver, NOW())
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()")
            ->execute([':tid' => $tenantId, ':ver' => ATE_DEFAULTS_VERSION]);

        // Merge in any new defaults that don't exist in stored settings
        foreach ($defaults as $key => $value) {
            if (!array_key_exists($key, $stored)) {
                $db->prepare("INSERT INTO ate_settings (tenant_id, setting_key, setting_value, updated_at)
                    VALUES (:tid, :key, :val, NOW())
                    ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()")
                    ->execute([':tid' => $tenantId, ':key' => $key, ':val' => (string)$value]);
            }
        }
        $settings = array_merge($defaults, $stored);
    }

    return $settings;
}

function ate_save_settings(string $tenantId, array $input): void
{
    $allowed = [
        'default_retention_policy', 'max_manuscript_size_mb',
        'allowed_manuscript_extensions', 'require_ethics_clearance',
        'require_adviser_endorsement', 'auto_generate_aiss_on_submit',
        'aiss_integration_enabled',
        '_defaults_version',
    ];

    $db = ate_db($tenantId);
    $stmt = $db->prepare("
        INSERT INTO ate_settings (tenant_id, setting_key, setting_value, updated_at)
        VALUES (:tid, :key, :val, NOW())
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()
    ");

    foreach ($input as $key => $value) {
        if (!in_array($key, $allowed, true)) {
            continue;
        }
        $stmt->execute([':tid' => $tenantId, ':key' => $key, ':val' => (string)$value]);
    }
}

// ── Capability handlers ──────────────────────────────────────────
function academic_thesis_evaluation_capability_handlers(): array
{
    return [
        'academic_thesis_evaluation.case.create@1'       => 'ate_cap_case_create',
        'academic_thesis_evaluation.case.get@1'          => 'ate_cap_case_get',
        'academic_thesis_evaluation.case.list@1'         => 'ate_cap_case_list',
        'academic_thesis_evaluation.case.transition@1'   => 'ate_cap_case_transition',
        'academic_thesis_evaluation.manuscript.submit@1' => 'ate_cap_manuscript_submit',
        'academic_thesis_evaluation.manuscript.revise@1' => 'ate_cap_manuscript_revise',
        'academic_thesis_evaluation.manuscript.history@1' => 'ate_cap_manuscript_history',
        'academic_thesis_evaluation.reviewer.assign@1'   => 'ate_cap_reviewer_assign',
        'academic_thesis_evaluation.reviewer.accept@1'   => 'ate_cap_reviewer_accept',
        'academic_thesis_evaluation.reviewer.submit@1'   => 'ate_cap_reviewer_submit',
        'academic_thesis_evaluation.rubric.get@1'        => 'ate_cap_rubric_get',
        'academic_thesis_evaluation.rubric.score@1'      => 'ate_cap_rubric_score',
        'academic_thesis_evaluation.rubric.summary@1'    => 'ate_cap_rubric_summary',
        'academic_thesis_evaluation.evidence.generate@1' => 'ate_cap_evidence_generate',
        'academic_thesis_evaluation.evidence.review@1'   => 'ate_cap_evidence_review',
        'academic_thesis_evaluation.evidence.snapshot@1' => 'ate_cap_evidence_snapshot',
        'academic_thesis_evaluation.revision.request@1'  => 'ate_cap_revision_request',
        'academic_thesis_evaluation.revision.resolve@1'  => 'ate_cap_revision_resolve',
        'academic_thesis_evaluation.disposition.issue@1' => 'ate_cap_disposition_issue',
        'academic_thesis_evaluation.workflow.advance@1' => 'ate_cap_workflow_advance',
    ];
}

// ── Capability handler implementations ───────────────────────────

function ate_cap_case_create(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    if (!is_array($payload)) {
        return ['ok' => false, 'error' => 'Invalid payload'];
    }
    $tenantId = $payload['_tenant_id'] ?? (string)(app()->tenant()->current() ?? '');
    $service = new \AcademicThesisEvaluationCaseService($tenantId);
    return $service->create($payload);
}

function ate_cap_case_get(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    if (!is_array($payload) || empty($payload['case_id'])) {
        return ['ok' => false, 'error' => 'case_id required'];
    }
    $tenantId = $payload['_tenant_id'] ?? (string)(app()->tenant()->current() ?? '');
    $repo = new \EvaluationCaseRepository($tenantId);
    $case = $repo->findById((int)$payload['case_id']);
    if (!$case) {
        return ['ok' => false, 'error' => 'Case not found'];
    }
    return ['ok' => true, 'data' => $case];
}

function ate_cap_case_list(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    $tenantId = $payload['_tenant_id'] ?? (string)(app()->tenant()->current() ?? '');
    $repo = new \EvaluationCaseRepository($tenantId);
    $filters = is_array($payload) ? $payload : [];
    $cases = $repo->search($filters);
    return ['ok' => true, 'data' => $cases];
}

function ate_cap_case_transition(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    if (!is_array($payload) || empty($payload['case_id']) || empty($payload['target_stage'])) {
        return ['ok' => false, 'error' => 'case_id and target_stage required'];
    }
    $tenantId = $payload['_tenant_id'] ?? (string)(app()->tenant()->current() ?? '');
    $engine = new \EvaluationWorkflowEngine($tenantId);
    return $engine->transition(
        (int)$payload['case_id'],
        $payload['target_stage'],
        (int)($payload['actor_id'] ?? 0),
        $payload['reason'] ?? '',
        $payload['context'] ?? []
    )->toArray();
}

function ate_cap_manuscript_submit(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    if (!is_array($payload) || empty($payload['case_id'])) {
        return ['ok' => false, 'error' => 'case_id required'];
    }
    $tenantId = $payload['_tenant_id'] ?? (string)(app()->tenant()->current() ?? '');
    $service = new \AcademicThesisEvaluationCaseService($tenantId);
    return $service->submitManuscript((int)$payload['case_id'], $payload);
}

function ate_cap_manuscript_revise(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    if (!is_array($payload) || empty($payload['case_id'])) {
        return ['ok' => false, 'error' => 'case_id required'];
    }
    $tenantId = $payload['_tenant_id'] ?? (string)(app()->tenant()->current() ?? '');
    $service = new \AcademicThesisRevisionService($tenantId);
    return $service->submitRevision((int)$payload['case_id'], $payload);
}

function ate_cap_manuscript_history(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    if (!is_array($payload) || empty($payload['case_id'])) {
        return ['ok' => false, 'error' => 'case_id required'];
    }
    $tenantId = $payload['_tenant_id'] ?? (string)(app()->tenant()->current() ?? '');
    $repo = new \ManuscriptVersionRepository($tenantId);
    $versions = $repo->findByCaseId((int)$payload['case_id']);
    return ['ok' => true, 'data' => $versions];
}

function ate_cap_reviewer_assign(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    if (!is_array($payload) || empty($payload['case_id']) || empty($payload['reviewer_id']) || empty($payload['reviewer_role'])) {
        return ['ok' => false, 'error' => 'case_id, reviewer_id, and reviewer_role required'];
    }
    $tenantId = $payload['_tenant_id'] ?? (string)(app()->tenant()->current() ?? '');
    $service = new \AcademicThesisReviewerService($tenantId);
    return $service->assign((int)$payload['case_id'], $payload);
}

function ate_cap_reviewer_accept(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    if (!is_array($payload) || empty($payload['assignment_id'])) {
        return ['ok' => false, 'error' => 'assignment_id required'];
    }
    $tenantId = $payload['_tenant_id'] ?? (string)(app()->tenant()->current() ?? '');
    $service = new \AcademicThesisReviewerService($tenantId);
    return $service->accept((int)$payload['assignment_id'], (int)($payload['reviewer_id'] ?? 0));
}

function ate_cap_reviewer_submit(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    if (!is_array($payload) || empty($payload['assignment_id'])) {
        return ['ok' => false, 'error' => 'assignment_id required'];
    }
    $tenantId = $payload['_tenant_id'] ?? (string)(app()->tenant()->current() ?? '');
    $service = new \AcademicThesisReviewerService($tenantId);
    return $service->submitFindings((int)$payload['assignment_id'], $payload);
}

function ate_cap_rubric_get(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    if (!is_array($payload) || empty($payload['rubric_code'])) {
        return ['ok' => false, 'error' => 'rubric_code required'];
    }
    $tenantId = $payload['_tenant_id'] ?? (string)(app()->tenant()->current() ?? '');
    $service = new \AcademicThesisRubricService($tenantId);
    return $service->getByCode($payload['rubric_code']);
}

function ate_cap_rubric_score(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    if (!is_array($payload) || empty($payload['case_id']) || empty($payload['assignment_id'])) {
        return ['ok' => false, 'error' => 'case_id and assignment_id required'];
    }
    $tenantId = $payload['_tenant_id'] ?? (string)(app()->tenant()->current() ?? '');
    $service = new \AcademicThesisRubricService($tenantId);
    return $service->submitScores((int)$payload['case_id'], (int)$payload['assignment_id'], $payload);
}

function ate_cap_rubric_summary(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    if (!is_array($payload) || empty($payload['case_id'])) {
        return ['ok' => false, 'error' => 'case_id required'];
    }
    $tenantId = $payload['_tenant_id'] ?? (string)(app()->tenant()->current() ?? '');
    $service = new \AcademicThesisRubricService($tenantId);
    return $service->getSummary((int)$payload['case_id']);
}

function ate_cap_evidence_generate(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    if (!is_array($payload) || empty($payload['case_id'])) {
        return ['ok' => false, 'error' => 'case_id required'];
    }
    $tenantId = $payload['_tenant_id'] ?? (string)(app()->tenant()->current() ?? '');
    $adapter = new \AcademicThesisAissAdapter($tenantId);
    return $adapter->generateSnapshot((int)$payload['case_id'], (int)($payload['actor_id'] ?? 0));
}

function ate_cap_evidence_review(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    if (!is_array($payload) || empty($payload['snapshot_id']) || empty($payload['reviewer_id'])) {
        return ['ok' => false, 'error' => 'snapshot_id and reviewer_id required'];
    }
    $tenantId = $payload['_tenant_id'] ?? (string)(app()->tenant()->current() ?? '');
    $service = new \AcademicThesisEvidenceService($tenantId);
    return $service->recordReview((int)$payload['snapshot_id'], $payload);
}

function ate_cap_evidence_snapshot(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    if (!is_array($payload) || empty($payload['snapshot_id'])) {
        return ['ok' => false, 'error' => 'snapshot_id required'];
    }
    $tenantId = $payload['_tenant_id'] ?? (string)(app()->tenant()->current() ?? '');
    $repo = new \AissEvidenceSnapshotRepository($tenantId);
    $snapshot = $repo->findById((int)$payload['snapshot_id']);
    if (!$snapshot) {
        return ['ok' => false, 'error' => 'Snapshot not found'];
    }
    return ['ok' => true, 'data' => $snapshot];
}

function ate_cap_revision_request(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    if (!is_array($payload) || empty($payload['case_id']) || empty($payload['instruction'])) {
        return ['ok' => false, 'error' => 'case_id and instruction required'];
    }
    $tenantId = $payload['_tenant_id'] ?? (string)(app()->tenant()->current() ?? '');
    $service = new \AcademicThesisRevisionService($tenantId);
    return $service->createRequest((int)$payload['case_id'], $payload);
}

function ate_cap_revision_resolve(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    if (!is_array($payload) || empty($payload['revision_id'])) {
        return ['ok' => false, 'error' => 'revision_id required'];
    }
    $tenantId = $payload['_tenant_id'] ?? (string)(app()->tenant()->current() ?? '');
    $service = new \AcademicThesisRevisionService($tenantId);
    return $service->resolve((int)$payload['revision_id'], $payload);
}

function ate_cap_disposition_issue(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    if (!is_array($payload) || empty($payload['case_id']) || empty($payload['status'])) {
        return ['ok' => false, 'error' => 'case_id and status required'];
    }
    $tenantId = $payload['_tenant_id'] ?? (string)(app()->tenant()->current() ?? '');
    $service = new \AcademicThesisDispositionService($tenantId);
    return $service->issue((int)$payload['case_id'], $payload);
}

function ate_cap_workflow_advance(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    if (!is_array($payload) || empty($payload['case_id']) || empty($payload['outcome'])) {
        return ['ok' => false, 'error' => 'case_id and outcome required'];
    }
    $tenantId = $payload['_tenant_id'] ?? (string)(app()->tenant()->current() ?? '');
    $service = new \AcademicThesisWorkflowService($tenantId);
    $result = $service->advanceToNextStage(
        (int)$payload['case_id'],
        $payload['outcome'],
        (int)($payload['actor_id'] ?? 0),
        $payload['reason'] ?? ''
    );
    return $result->toArray();
}
