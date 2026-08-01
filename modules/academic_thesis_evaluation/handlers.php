<?php
declare(strict_types=1);

/**
 * Academic Thesis Evaluation — HTTP handlers.
 * Thin layer: validate auth/CSRF, delegate to services, render or return JSON.
 */

require_once __DIR__ . '/helpers.php';

// ── Auth helpers ─────────────────────────────────────────────────

function ate_require_admin(\Ikabud\Kernel\Contracts\ModuleContext $ctx): array
{
    return $ctx->requireAnyRole('admin', 'administrator', 'graduate_coordinator', 'graduate_dean');
}

function ate_require_reviewer(\Ikabud\Kernel\Contracts\ModuleContext $ctx): array
{
    return $ctx->requireAnyRole('admin', 'administrator', 'graduate_coordinator', 'panel_chair', 'panel_member', 'integrity_reviewer', 'methodologist', 'statistician');
}

function ate_require_student(\Ikabud\Kernel\Contracts\ModuleContext $ctx): array
{
    return $ctx->requireAnyRole('admin', 'student');
}

function ate_tenant_id(): string
{
    return (string)(app()->tenant()->current() ?? '');
}

// ── Admin page handlers ──────────────────────────────────────────

function pageDashboard(array $params = []): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); echo 'Module context unavailable'; return; }
    $user = ate_require_admin($ctx);

    $tenantId = ate_tenant_id();
    $caseRepo = new \EvaluationCaseRepository($tenantId);
    $stats = $caseRepo->dashboardStats();

    echo ate_render('admin/dashboard', [
        'stats' => $stats,
        'active_nav' => 'dashboard',
    ]);
}

function pageCases(array $params = []): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); echo 'Module context unavailable'; return; }
    $user = ate_require_admin($ctx);

    $tenantId = ate_tenant_id();
    $caseRepo = new \EvaluationCaseRepository($tenantId);
    $status = (string)($_GET['status'] ?? '');
    $search = (string)($_GET['search'] ?? '');
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = 25;

    $cases = $caseRepo->search(['status' => $status, 'search' => $search], $page, $perPage);
    $total = $caseRepo->count(['status' => $status, 'search' => $search]);

    echo ate_render('admin/cases/index', [
        'cases' => $cases,
        'total' => $total,
        'page' => $page,
        'per_page' => $perPage,
        'current_status' => $status,
        'search_query' => $search,
        'active_nav' => 'cases',
    ]);
}

function pageCaseDetail(array $params = []): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); echo 'Module context unavailable'; return; }
    $user = ate_require_admin($ctx);

    $tenantId = ate_tenant_id();
    $caseId = (int)($params['id'] ?? 0);

    $caseRepo = new \EvaluationCaseRepository($tenantId);
    $case = $caseRepo->findById($caseId);
    if (!$case) {
        http_response_code(404);
        echo ate_render('not_found', ['resource' => 'Evaluation Case']);
        return;
    }

    $stageRepo = new \EvaluationStageRepository($tenantId);
    $stages = $stageRepo->findByCaseId($caseId);

    $manuscriptRepo = new \ManuscriptVersionRepository($tenantId);
    $manuscripts = $manuscriptRepo->findByCaseId($caseId);

    $assignmentRepo = new \ReviewerAssignmentRepository($tenantId);
    $assignments = $assignmentRepo->findByCaseId($caseId);

    echo ate_render('admin/cases/detail', [
        'case' => $case,
        'stages' => $stages,
        'manuscripts' => $manuscripts,
        'assignments' => $assignments,
        'active_nav' => 'cases',
    ]);
}

function pageEvidenceReview(array $params = []): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); echo 'Module context unavailable'; return; }
    $user = ate_require_reviewer($ctx);

    $tenantId = ate_tenant_id();
    $caseId = (int)($params['id'] ?? 0);

    $caseRepo = new \EvaluationCaseRepository($tenantId);
    $case = $caseRepo->findById($caseId);
    if (!$case) {
        http_response_code(404);
        echo ate_render('not_found', ['resource' => 'Evaluation Case']);
        return;
    }

    $snapshotRepo = new \AissEvidenceSnapshotRepository($tenantId);
    $snapshots = $snapshotRepo->findByCaseId($caseId);
    foreach ($snapshots as &$snapshot) {
        foreach (['textual_result', 'maturity_metadata', 'capability_warnings'] as $field) {
            if (!is_string($snapshot[$field] ?? null) || $snapshot[$field] === '') {
                continue;
            }
            $decoded = json_decode($snapshot[$field], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $snapshot[$field] = $decoded;
            }
        }
    }
    unset($snapshot);

    $decisionRepo = new \EvidenceReviewDecisionRepository($tenantId);
    $decisions = $decisionRepo->findByCaseId($caseId);

    $suggestionService = new \AcademicThesisSuggestionReviewService($tenantId);
    $suggestionReviews = $suggestionService->listForCase($caseId);

    // Rubric criteria for the case: the rubric template code mirrors the profile
    // code, so reviewers can link a suggestion to a criterion without changing scores.
    $rubricCriteria = [];
    try {
        $rubricResult = (new \AcademicThesisRubricService($tenantId))->getByCode((string)($case['profile_code'] ?? ''));
        if (!empty($rubricResult['ok'])) {
            $rubricCriteria = $rubricResult['data']['criteria'] ?? [];
        }
    } catch (\Throwable $e) {
        write_log('Failed to load rubric criteria for evidence page: ' . $e->getMessage());
    }

    $revisions = (new \RevisionRequestRepository($tenantId))->findByCaseId($caseId);

    echo ate_render('admin/cases/evidence', [
        'case' => $case,
        'snapshots' => $snapshots,
        'decisions' => $decisions,
        'suggestion_reviews' => $suggestionReviews['data'] ?? [],
        'rubric_criteria' => $rubricCriteria,
        'revisions' => $revisions,
        'active_nav' => 'cases',
    ]);
}

function pageRubrics(array $params = []): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); echo 'Module context unavailable'; return; }
    $user = ate_require_reviewer($ctx);

    $tenantId = ate_tenant_id();
    $caseId = (int)($params['id'] ?? 0);

    $caseRepo = new \EvaluationCaseRepository($tenantId);
    $case = $caseRepo->findById($caseId);
    if (!$case) {
        http_response_code(404);
        echo ate_render('not_found', ['resource' => 'Evaluation Case']);
        return;
    }

    $rubricService = new \AcademicThesisRubricService($tenantId);
    $rubricResult = $rubricService->getSummary($caseId);

    echo ate_render('admin/cases/rubrics', [
        'case' => $case,
        'rubric_summary' => $rubricResult['ok'] ? ($rubricResult['data'] ?? []) : [],
        'active_nav' => 'cases',
    ]);
}

function pageRevisions(array $params = []): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); echo 'Module context unavailable'; return; }
    $user = ate_require_admin($ctx);

    $tenantId = ate_tenant_id();
    $caseId = (int)($params['id'] ?? 0);

    $caseRepo = new \EvaluationCaseRepository($tenantId);
    $case = $caseRepo->findById($caseId);
    if (!$case) {
        http_response_code(404);
        echo ate_render('not_found', ['resource' => 'Evaluation Case']);
        return;
    }

    $revisionRepo = new \RevisionRequestRepository($tenantId);
    $revisions = $revisionRepo->findByCaseId($caseId);

    echo ate_render('admin/cases/revisions', [
        'case' => $case,
        'revisions' => $revisions,
        'active_nav' => 'cases',
    ]);
}

function pageProfiles(array $params = []): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); echo 'Module context unavailable'; return; }
    $user = ate_require_admin($ctx);

    $tenantId = ate_tenant_id();
    $profileRepo = new \EvaluationProfileRepository($tenantId);
    $profiles = $profileRepo->all();

    echo ate_render('admin/profiles/index', [
        'profiles' => $profiles,
        'active_nav' => 'profiles',
    ]);
}

function pageProfileDetail(array $params = []): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); echo 'Module context unavailable'; return; }
    $user = ate_require_admin($ctx);

    $tenantId = ate_tenant_id();
    $profileId = (int)($params['id'] ?? 0);

    $profileRepo = new \EvaluationProfileRepository($tenantId);
    $profile = $profileRepo->findById($profileId);
    if (!$profile) {
        http_response_code(404);
        echo ate_render('not_found', ['resource' => 'Evaluation Profile']);
        return;
    }

    // Parse workflow definition for display
    $workflow = json_decode($profile['workflow_definition'] ?? '{}', true);

    echo ate_render('admin/profiles/detail', [
        'profile' => $profile,
        'workflow' => $workflow,
        'active_nav' => 'profiles',
    ]);
}

function pageRubricTemplates(array $params = []): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); echo 'Module context unavailable'; return; }
    $user = ate_require_admin($ctx);

    $tenantId = ate_tenant_id();
    $templateRepo = new \RubricTemplateRepository($tenantId);
    $templates = $templateRepo->all();

    echo ate_render('admin/rubrics/index', [
        'templates' => $templates,
        'active_nav' => 'rubrics',
    ]);
}

function pageRubricTemplateDetail(array $params = []): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); echo 'Module context unavailable'; return; }
    $user = ate_require_admin($ctx);

    $tenantId = ate_tenant_id();
    $templateId = (int)($params['id'] ?? 0);

    $templateRepo = new \RubricTemplateRepository($tenantId);
    $template = $templateRepo->findById($templateId);
    if (!$template) {
        http_response_code(404);
        echo ate_render('not_found', ['resource' => 'Rubric Template']);
        return;
    }

    $criteria = $templateRepo->getCriteria($templateId);

    echo ate_render('admin/rubrics/detail', [
        'template' => $template,
        'criteria' => $criteria,
        'active_nav' => 'rubrics',
    ]);
}

function pageSettings(array $params = []): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); echo 'Module context unavailable'; return; }
    $user = ate_require_admin($ctx);

    $tenantId = ate_tenant_id();
    $settings = ate_get_settings($tenantId);

    echo ate_render('admin/settings', [
        'settings' => $settings,
        'active_nav' => 'settings',
    ]);
}

// ── Student page handlers ────────────────────────────────────────

function pageStudentDashboard(array $params = []): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); echo 'Module context unavailable'; return; }
    $user = ate_require_student($ctx);

    $tenantId = ate_tenant_id();
    $caseRepo = new \EvaluationCaseRepository($tenantId);
    $cases = $caseRepo->findByOwner((int)($user['id'] ?? 0));

    echo ate_render('student/dashboard', [
        'cases' => $cases,
        'active_nav' => 'dashboard',
    ]);
}

function pageStudentSubmit(array $params = []): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); echo 'Module context unavailable'; return; }
    $user = ate_require_student($ctx);

    $tenantId = ate_tenant_id();
    $profileRepo = new \EvaluationProfileRepository($tenantId);
    $profiles = $profileRepo->allActive();

    echo ate_render('student/submit', [
        'profiles' => $profiles,
        'active_nav' => 'submit',
    ]);
}

function pageStudentCaseDetail(array $params = []): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); echo 'Module context unavailable'; return; }
    $user = ate_require_student($ctx);

    $tenantId = ate_tenant_id();
    $caseId = (int)($params['id'] ?? 0);

    $caseRepo = new \EvaluationCaseRepository($tenantId);
    $case = $caseRepo->findById($caseId);
    if (!$case || (int)$case['submission_owner_id'] !== (int)($user['id'] ?? 0)) {
        http_response_code(404);
        echo ate_render('not_found', ['resource' => 'Evaluation Case']);
        return;
    }

    $stageRepo = new \EvaluationStageRepository($tenantId);
    $stages = $stageRepo->findByCaseId($caseId);

    $revisionRepo = new \RevisionRequestRepository($tenantId);
    $revisions = $revisionRepo->findByCaseId($caseId);

    echo ate_render('student/case_detail', [
        'case' => $case,
        'stages' => $stages,
        'revisions' => $revisions,
        'active_nav' => 'dashboard',
    ]);
}

function pageStudentRevisions(array $params = []): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); echo 'Module context unavailable'; return; }
    $user = ate_require_student($ctx);

    $tenantId = ate_tenant_id();
    $caseId = (int)($params['id'] ?? 0);

    $caseRepo = new \EvaluationCaseRepository($tenantId);
    $case = $caseRepo->findById($caseId);
    if (!$case || (int)$case['submission_owner_id'] !== (int)($user['id'] ?? 0)) {
        http_response_code(404);
        echo ate_render('not_found', ['resource' => 'Evaluation Case']);
        return;
    }

    $revisionRepo = new \RevisionRequestRepository($tenantId);
    $revisions = $revisionRepo->findByCaseId($caseId);

    $manuscriptRepo = new \ManuscriptVersionRepository($tenantId);
    $manuscripts = $manuscriptRepo->findByCaseId($caseId);

    echo ate_render('student/revisions', [
        'case' => $case,
        'revisions' => $revisions,
        'manuscripts' => $manuscripts,
        'active_nav' => 'dashboard',
    ]);
}

// ── API handlers ─────────────────────────────────────────────────

function apiCreateCase(array $params = []): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); echo json_encode(['ok' => false, 'error' => 'Module context unavailable']); return; }
    $user = ate_require_student($ctx);

    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $input['submission_owner_id'] = (int)($user['id'] ?? 0);
    $input['_tenant_id'] = ate_tenant_id();

    $service = new \AcademicThesisEvaluationCaseService(ate_tenant_id());
    $result = $service->create($input);

    header('Content-Type: application/json');
    echo json_encode($result);
}

function apiGetCase(array $params = []): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); echo json_encode(['ok' => false, 'error' => 'Module context unavailable']); return; }
    $user = ate_require_admin($ctx);

    $tenantId = ate_tenant_id();
    $caseId = (int)($params['id'] ?? 0);

    $caseRepo = new \EvaluationCaseRepository($tenantId);
    $case = $caseRepo->findById($caseId);

    header('Content-Type: application/json');
    if (!$case) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Case not found']);
        return;
    }
    echo json_encode(['ok' => true, 'data' => $case]);
}

function apiTransitionCase(array $params = []): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); echo json_encode(['ok' => false, 'error' => 'Module context unavailable']); return; }
    $user = ate_require_admin($ctx);

    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $caseId = (int)($params['id'] ?? 0);

    if (empty($input['target_stage'])) {
        header('Content-Type: application/json');
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'target_stage required']);
        return;
    }

    $engine = new \EvaluationWorkflowEngine(ate_tenant_id());
    $result = $engine->transition(
        $caseId,
        $input['target_stage'],
        (int)($user['id'] ?? 0),
        $input['reason'] ?? '',
        $input['context'] ?? []
    );

    header('Content-Type: application/json');
    echo json_encode($result->toArray());
}

function apiSubmitManuscript(array $params = []): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); echo json_encode(['ok' => false, 'error' => 'Module context unavailable']); return; }
    $user = ate_require_student($ctx);

    $caseId = (int)($params['id'] ?? 0);
    // Accept both multipart form data and JSON
    $input = !empty($_POST) ? $_POST : (json_decode(file_get_contents('php://input'), true) ?: []);
    // For file uploads, $_FILES is also available

    $payload = array_merge($input, [
        '_tenant_id' => ate_tenant_id(),
        'submitted_by' => (int)($user['id'] ?? 0),
    ]);

    $service = new \AcademicThesisEvaluationCaseService(ate_tenant_id());
    $result = $service->submitManuscript($caseId, $payload);

    header('Content-Type: application/json');
    echo json_encode($result);
}

function apiGetManuscripts(array $params = []): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); echo json_encode(['ok' => false, 'error' => 'Module context unavailable']); return; }
    $user = ate_require_admin($ctx);

    $tenantId = ate_tenant_id();
    $caseId = (int)($params['id'] ?? 0);

    $repo = new \ManuscriptVersionRepository($tenantId);
    $versions = $repo->findByCaseId($caseId);

    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'data' => $versions]);
}

function apiGenerateAissAnalysis(array $params = []): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); echo json_encode(['ok' => false, 'error' => 'Module context unavailable']); return; }
    $user = ate_require_reviewer($ctx);

    $caseId = (int)($params['id'] ?? 0);
    $adapter = new \AcademicThesisAissAdapter(ate_tenant_id());
    $result = $adapter->generateSnapshot($caseId, (int)($user['id'] ?? 0));

    header('Content-Type: application/json');
    echo json_encode($result);
}

function apiGetEvidence(array $params = []): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); echo json_encode(['ok' => false, 'error' => 'Module context unavailable']); return; }
    $user = ate_require_reviewer($ctx);

    $tenantId = ate_tenant_id();
    $caseId = (int)($params['id'] ?? 0);

    $snapshotRepo = new \AissEvidenceSnapshotRepository($tenantId);
    $snapshots = $snapshotRepo->findByCaseId($caseId);

    $decisionRepo = new \EvidenceReviewDecisionRepository($tenantId);
    $decisions = $decisionRepo->findByCaseId($caseId);
    $suggestionService = new \AcademicThesisSuggestionReviewService($tenantId);
    $suggestions = $suggestionService->listForCase($caseId);

    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'data' => ['snapshots' => $snapshots, 'decisions' => $decisions, 'suggestion_reviews' => $suggestions['data'] ?? []]]);
}

function apiGetSuggestionReviews(array $params = []): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); echo json_encode(['ok' => false, 'error' => 'Module context unavailable']); return; }
    $user = ate_require_reviewer($ctx);

    $service = new \AcademicThesisSuggestionReviewService(ate_tenant_id());
    $result = $service->listForCase((int)($params['id'] ?? 0));

    header('Content-Type: application/json');
    echo json_encode($result);
}

function apiReviewEvidence(array $params = []): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); echo json_encode(['ok' => false, 'error' => 'Module context unavailable']); return; }
    $user = ate_require_reviewer($ctx);
    app()->csrfEnforce();

    $caseId = (int)($params['id'] ?? 0);
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $input['reviewer_id'] = (int)($user['id'] ?? 0);

    // Accept snapshot_id from input, or fall back to latest snapshot for this case
    $snapshotId = (int)($input['snapshot_id'] ?? 0);
    if (!$snapshotId) {
        $snapRepo = new \AissEvidenceSnapshotRepository(ate_tenant_id());
        $snapshots = $snapRepo->findByCaseId($caseId);
        $snapshotId = !empty($snapshots) ? (int)$snapshots[0]['id'] : 0;
    }
    if (!$snapshotId) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'No evidence snapshot found. Generate AISS analysis first.']);
        return;
    }

    $service = new \AcademicThesisEvidenceService(ate_tenant_id());
    $result = $service->recordReview($snapshotId, $input, $caseId);

    header('Content-Type: application/json');
    echo json_encode($result);
}

function apiReviewSuggestion(array $params = []): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); echo json_encode(['ok' => false, 'error' => 'Module context unavailable']); return; }
    $user = ate_require_reviewer($ctx);
    app()->csrfEnforce();

    $caseId = (int)($params['id'] ?? 0);
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $input['reviewer_id'] = (int)($user['id'] ?? 0);

    $snapshotId = (int)($input['snapshot_id'] ?? 0);
    if (!$snapshotId) {
        $snapRepo = new \AissEvidenceSnapshotRepository(ate_tenant_id());
        $snapshots = $snapRepo->findByCaseId($caseId);
        $snapshotId = !empty($snapshots) ? (int)$snapshots[0]['id'] : 0;
    }
    if (!$snapshotId) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'No evidence snapshot found. Generate AISS analysis first.']);
        return;
    }

    $service = new \AcademicThesisSuggestionReviewService(ate_tenant_id());
    $result = $service->review($snapshotId, $input, $caseId);

    header('Content-Type: application/json');
    echo json_encode($result);
}

function apiAssignReviewer(array $params = []): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); echo json_encode(['ok' => false, 'error' => 'Module context unavailable']); return; }
    $user = ate_require_admin($ctx);

    $caseId = (int)($params['id'] ?? 0);
    $input = json_decode(file_get_contents('php://input'), true) ?: [];

    if (empty($input['reviewer_id']) || empty($input['reviewer_role'])) {
        header('Content-Type: application/json');
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'reviewer_id and reviewer_role required']);
        return;
    }

    $service = new \AcademicThesisReviewerService(ate_tenant_id());
    $result = $service->assign($caseId, $input);

    header('Content-Type: application/json');
    echo json_encode($result);
}

function apiAcceptAssignment(array $params = []): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); echo json_encode(['ok' => false, 'error' => 'Module context unavailable']); return; }
    $user = ate_require_reviewer($ctx);

    $assignmentId = (int)($params['assignment_id'] ?? 0);
    $service = new \AcademicThesisReviewerService(ate_tenant_id());
    $result = $service->accept($assignmentId, (int)($user['id'] ?? 0));

    header('Content-Type: application/json');
    echo json_encode($result);
}

function apiSubmitRubricResponses(array $params = []): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); echo json_encode(['ok' => false, 'error' => 'Module context unavailable']); return; }
    $user = ate_require_reviewer($ctx);

    $caseId = (int)($params['id'] ?? 0);
    $input = json_decode(file_get_contents('php://input'), true) ?: [];

    if (empty($input['assignment_id']) || empty($input['responses'])) {
        header('Content-Type: application/json');
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'assignment_id and responses required']);
        return;
    }

    $service = new \AcademicThesisRubricService(ate_tenant_id());
    $result = $service->submitScores($caseId, (int)$input['assignment_id'], $input);

    header('Content-Type: application/json');
    echo json_encode($result);
}

function apiGetRubricSummary(array $params = []): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); echo json_encode(['ok' => false, 'error' => 'Module context unavailable']); return; }
    $user = ate_require_reviewer($ctx);

    $caseId = (int)($params['id'] ?? 0);
    $service = new \AcademicThesisRubricService(ate_tenant_id());
    $result = $service->getSummary($caseId);

    header('Content-Type: application/json');
    echo json_encode($result);
}

function apiCreateRevisionRequest(array $params = []): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); echo json_encode(['ok' => false, 'error' => 'Module context unavailable']); return; }
    $user = ate_require_reviewer($ctx);

    $caseId = (int)($params['id'] ?? 0);
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $input['created_by'] = (int)($user['id'] ?? 0);

    $service = new \AcademicThesisRevisionService(ate_tenant_id());
    $result = $service->createRequest($caseId, $input);

    header('Content-Type: application/json');
    echo json_encode($result);
}

function apiResolveRevision(array $params = []): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); echo json_encode(['ok' => false, 'error' => 'Module context unavailable']); return; }
    $user = ate_require_student($ctx);

    $revisionId = (int)($params['revision_id'] ?? 0);
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $input['resolved_by'] = (int)($user['id'] ?? 0);

    $service = new \AcademicThesisRevisionService(ate_tenant_id());
    $result = $service->resolve($revisionId, $input);

    header('Content-Type: application/json');
    echo json_encode($result);
}

function apiGetRevisions(array $params = []): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); echo json_encode(['ok' => false, 'error' => 'Module context unavailable']); return; }
    $user = ate_require_admin($ctx);

    $tenantId = ate_tenant_id();
    $caseId = (int)($params['id'] ?? 0);

    $repo = new \RevisionRequestRepository($tenantId);
    $revisions = $repo->findByCaseId($caseId);

    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'data' => $revisions]);
}

function apiIssueDisposition(array $params = []): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); echo json_encode(['ok' => false, 'error' => 'Module context unavailable']); return; }
    $user = ate_require_admin($ctx);

    $caseId = (int)($params['id'] ?? 0);
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $input['decided_by'] = (int)($user['id'] ?? 0);
    $input['authority_role'] = $user['role'] ?? 'admin';

    $service = new \AcademicThesisDispositionService(ate_tenant_id());
    $result = $service->issue($caseId, $input);

    header('Content-Type: application/json');
    echo json_encode($result);
}

function apiGetReport(array $params = []): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); echo json_encode(['ok' => false, 'error' => 'Module context unavailable']); return; }
    $user = ate_require_admin($ctx);

    $caseId = (int)($params['id'] ?? 0);
    $service = new \AcademicThesisReportService(ate_tenant_id());
    $result = $service->generateEvaluationReport($caseId);

    header('Content-Type: application/json');
    echo json_encode($result);
}

function apiListProfiles(array $params = []): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); echo json_encode(['ok' => false, 'error' => 'Module context unavailable']); return; }
    $user = ate_require_admin($ctx);

    $tenantId = ate_tenant_id();
    $repo = new \EvaluationProfileRepository($tenantId);
    $profiles = $repo->allActive();

    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'data' => $profiles]);
}

function apiGetRubric(array $params = []): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); echo json_encode(['ok' => false, 'error' => 'Module context unavailable']); return; }
    $user = ate_require_admin($ctx);

    $code = (string)($params['code'] ?? '');
    $service = new \AcademicThesisRubricService(ate_tenant_id());
    $result = $service->getByCode($code);

    header('Content-Type: application/json');
    echo json_encode($result);
}

function apiCreateProfile(array $params = []): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); echo json_encode(['ok' => false, 'error' => 'Module context unavailable']); return; }
    $user = ate_require_admin($ctx);

    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $input['created_by'] = (int)($user['id'] ?? 0);

    $service = new \AcademicThesisProfileService(ate_tenant_id());
    $result = $service->create($input);

    header('Content-Type: application/json');
    echo json_encode($result);
}

function apiSaveSettings(array $params = []): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); echo json_encode(['ok' => false, 'error' => 'Module context unavailable']); return; }
    $user = ate_require_admin($ctx);

    $input = $_POST ?: json_decode(file_get_contents('php://input'), true) ?: [];
    $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['csrf_token'] ?? '');
    if (!app()->validateCsrfToken($csrfToken)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
        return;
    }
    ate_save_settings(ate_tenant_id(), $input);

    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'message' => 'Settings saved']);
}
