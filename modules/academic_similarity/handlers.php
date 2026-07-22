<?php
declare(strict_types=1);

/**
 * Academic Similarity — HTTP handlers.
 * Thin layer: validate auth/CSRF, delegate to services, render or return JSON.
 */

require_once __DIR__ . '/helpers.php';

function academic_similarity_require_admin(\Ikabud\Kernel\Contracts\ModuleContext $ctx): array
{
    return $ctx->requireAnyRole('admin', 'administrator');
}

// ── Admin page handlers ──────────────────────────────────────────

function pageDashboard(array $params = []): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); echo 'Module context unavailable'; return; }
    academic_similarity_require_admin($ctx);

    $tenantId = (string)(app()->tenant()->current() ?? '');
    $stats = academic_similarity_dashboard_stats($tenantId);

    echo $ctx->render('academic_similarity/dashboard', $stats);
}

function pageSubmissions(array $params = []): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); echo 'Module context unavailable'; return; }
    academic_similarity_require_admin($ctx);

    $tenantId = (string)(app()->tenant()->current() ?? '');
    $institutionId = (int)($_GET['institution_id'] ?? 0);
    $status = (string)($_GET['status'] ?? '');
    $search = (string)($_GET['search'] ?? '');
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = 25;

    $repo = new \AcademicSimilaritySubmissionRepository($tenantId);
    $submissions = $repo->search($institutionId, $status, $search, $page, $perPage);
    $total = $repo->count($institutionId, $status, $search);

    echo $ctx->render('academic_similarity/submissions/index', [
        'submissions' => $submissions,
        'total' => $total,
        'page' => $page,
        'per_page' => $perPage,
        'current_status' => $status,
        'search_query' => $search,
        'institution_id' => $institutionId,
        'active_nav' => 'submissions',
    ]);
}

function pageSubmissionDetail(array $params = []): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); echo 'Module context unavailable'; return; }
    academic_similarity_require_admin($ctx);

    $tenantId = (string)(app()->tenant()->current() ?? '');
    $submissionId = (int)($params['id'] ?? 0);

    $repo = new \AcademicSimilaritySubmissionRepository($tenantId);
    $submission = $repo->findById($submissionId);
    if (!$submission) {
        http_response_code(404);
        echo $ctx->render('academic_similarity/not_found', ['resource' => 'Submission']);
        return;
    }

    $matchRepo = new \AcademicSimilarityMatchRepository($tenantId);
    $matches = $matchRepo->findBySubmissionId($submissionId);
    $report = \AcademicSimilarityReportService::getForSubmission($tenantId, $submissionId);

    echo $ctx->render('academic_similarity/submissions/detail', [
        'submission' => $submission,
        'matches' => $matches,
        'report' => $report,
        'active_nav' => 'submissions',
    ]);
}

function pageSubmissionUpload(array $params = []): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); echo 'Module context unavailable'; return; }
    academic_similarity_require_admin($ctx);

    $tenantId = (string)(app()->tenant()->current() ?? '');
    $submissionId = (int)($params['id'] ?? 0);
    $institutionId = (int)($_GET['institution_id'] ?? 0);

    echo $ctx->render('academic_similarity/submissions/upload', [
        'submission_id' => $submissionId,
        'institution_id' => $institutionId,
        'active_nav' => 'submissions',
    ]);
}

function pageSources(array $params = []): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); echo 'Module context unavailable'; return; }
    academic_similarity_require_admin($ctx);

    $tenantId = (string)(app()->tenant()->current() ?? '');
    $repo = new \AcademicSimilaritySourceRepository($tenantId);
    $sources = $repo->search('', 1, 50);

    echo $ctx->render('academic_similarity/sources/index', [
        'sources' => $sources,
        'active_nav' => 'sources',
    ]);
}

function pageCollections(array $params = []): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); echo 'Module context unavailable'; return; }
    academic_similarity_require_admin($ctx);

    $tenantId = (string)(app()->tenant()->current() ?? '');
    $repo = new \AcademicSimilarityCollectionRepository($tenantId);
    $collections = $repo->search('', 1, 50);

    echo $ctx->render('academic_similarity/collections/index', [
        'collections' => $collections,
        'active_nav' => 'collections',
    ]);
}

function pageReports(array $params = []): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); echo 'Module context unavailable'; return; }
    academic_similarity_require_admin($ctx);

    $tenantId = (string)(app()->tenant()->current() ?? '');
    $submissionId = (int)($_GET['submission_id'] ?? 0);
    $search = trim((string)($_GET['search'] ?? ''));
    $sort = (string)($_GET['sort'] ?? 'newest');
    if (!in_array($sort, ['newest', 'oldest', 'score_high', 'score_low'], true)) {
        $sort = 'newest';
    }

    $reportService = new \AcademicSimilarityReportService($tenantId);
    $reports = $reportService->search($submissionId, 1, 50, $search, $sort);
    $reportStats = $reportService->stats($submissionId, $search);

    echo $ctx->render('academic_similarity/reports/index', [
        'reports' => $reports,
        'report_stats' => $reportStats,
        'search_query' => $search,
        'current_sort' => $sort,
        'submission_id' => $submissionId,
        'active_nav' => 'reports',
    ]);
}

function pageReportDetail(array $params = []): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); echo 'Module context unavailable'; return; }
    academic_similarity_require_admin($ctx);

    $tenantId = (string)(app()->tenant()->current() ?? '');
    $reportId = (int)($params['id'] ?? 0);

    $reportService = new \AcademicSimilarityReportService($tenantId);
    $report = $reportService->findById($reportId);
    if (!$report) {
        http_response_code(404);
        echo $ctx->render('academic_similarity/not_found', ['resource' => 'Report']);
        return;
    }

    $submissionId = (int)$report['submission_id'];
    $submissionRepo = new \AcademicSimilaritySubmissionRepository($tenantId);
    $submission = $submissionRepo->findById($submissionId);

    $matchRepo = new \AcademicSimilarityMatchRepository($tenantId);
    $matches = $matchRepo->findBySubmissionId($submissionId);

    // Build evidence map
    $evidenceMap = [];
    foreach ($matches as $match) {
        $evidenceMap[(int)$match['id']] = $matchRepo->getEvidence((int)$match['id']);
    }

    // Build source cache for titles
    $sourceRepo = new \AcademicSimilaritySourceRepository($tenantId);
    $sourceCache = [];
    foreach ($matches as $match) {
        $sid = (int)$match['source_id'];
        if ($sid > 0 && !isset($sourceCache[$sid])) {
            $src = $sourceRepo->findById($sid);
            if ($src) {
                $sourceCache[$sid] = $src;
            }
        }
    }

    // Load text for highlighted rendering
    $submissionText = '';
    $sourceTexts = [];
    try {
        $tvStmt = academic_similarity_db()->prepare(
            "SELECT extracted_text FROM ac_similarity_text_versions WHERE submission_id = :sid AND tenant_id = :tid AND text_type = 'submission' ORDER BY id DESC LIMIT 1"
        );
        $tvStmt->execute([':sid' => $submissionId, ':tid' => $tenantId]);
        $tv = $tvStmt->fetch(\PDO::FETCH_ASSOC);
        $submissionText = $tv['extracted_text'] ?? '';

        // Load source texts
        foreach ($sourceCache as $sid => $src) {
            $sStmt = academic_similarity_db()->prepare(
                "SELECT extracted_text FROM ac_similarity_text_versions WHERE source_id = :sid AND tenant_id = :tid AND text_type = 'source' ORDER BY id DESC LIMIT 1"
            );
            $sStmt->execute([':sid' => $sid, ':tid' => $tenantId]);
            $sTv = $sStmt->fetch(\PDO::FETCH_ASSOC);
            if ($sTv) {
                $sourceTexts[$sid] = $sTv['extracted_text'];
            }
        }
    } catch (\Throwable $e) {
        write_log('Failed to load text versions for report ' . $reportId . ': ' . $e->getMessage());
    }

    // Build highlights
    $highlightService = new \AcademicSimilarityHighlightService($tenantId);
    $highlightData = $highlightService->buildSpans($submissionId, $matches, $evidenceMap, $submission, $sourceCache);
    $spans = $highlightData['spans'];
    $highlightStats = $highlightData['stats'];
    $legend = $highlightData['legend'];

    // Render highlighted submission text
    $highlightedHtml = $highlightService->renderHighlightedText($submissionText, $spans);

    // Render source panels
    $sourcePanels = $highlightService->renderSourcePanels($spans, $sourceTexts);

    // Build matched_passages for template
    $matchedPassages = $highlightService->assembleMatchedPassages($spans, $matches, $evidenceMap);

    echo $ctx->render('academic_similarity/reports/detail', [
        'report'               => $report,
        'submission'           => $submission,
        'matches'              => $matches,
        'matched_passages'     => $matchedPassages,
        'highlighted_html'     => $highlightedHtml,
        'highlight_stats'      => $highlightStats,
        'highlight_legend'     => $legend,
        'source_panels'        => $sourcePanels,
        'evidence_by_match'    => $evidenceMap,
        'active_nav'           => 'reports',
    ]);
}

function downloadReport(array $params = []): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); echo 'Module context unavailable'; return; }
    academic_similarity_require_admin($ctx);

    $tenantId = (string)(app()->tenant()->current() ?? '');
    $reportId = (int)($params['id'] ?? 0);

    try {
        $reportService = new \AcademicSimilarityReportService($tenantId);
        $reportService->download($reportId);
    } catch (\Throwable $e) {
        write_log('Report download failed: ' . $e->getMessage());
        http_response_code(500);
        echo 'Failed to download report';
    }
}

function pageSettings(array $params = []): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); echo 'Module context unavailable'; return; }
    academic_similarity_require_admin($ctx);

    $tenantId = (string)(app()->tenant()->current() ?? '');
    $settings = academic_similarity_get_settings($tenantId);
    $path = (string)($_SERVER['REQUEST_URI'] ?? '');
    $settingsSection = 'all';
    foreach (['processing', 'reports', 'sources', 'semantic', 'cms'] as $section) {
        if (str_contains($path, '/settings/' . $section)) {
            $settingsSection = $section;
            break;
        }
    }
    $semantic = new \AcademicSimilaritySemanticService($tenantId);
    $semanticStatus = $semantic->isAvailable();

    echo $ctx->render('academic_similarity/settings', [
        'settings' => $settings,
        'settings_section' => $settingsSection,
        'semantic_status' => $semanticStatus,
        'active_nav' => 'settings',
    ]);
}

// ── API handlers ─────────────────────────────────────────────────

function apiCreateSubmission(array $params = []): void
{
    header('Content-Type: application/json');
    $ctx = module();
    if (!$ctx) { http_response_code(500); echo json_encode(['ok' => false, 'error' => 'Module context unavailable']); return; }
    academic_similarity_require_admin($ctx);
    app()->csrfEnforce();

    $tenantId = (string)(app()->tenant()->current() ?? '');
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    if (isset($_FILES['file']) && is_array($_FILES['file']) && ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        $input['file'] = $_FILES['file'];
    }

    try {
        $service = new \AcademicSimilaritySubmissionService($tenantId);
        $result = $service->create($input);
        if (!$result['ok']) {
            http_response_code(422);
            echo json_encode($result);
            return;
        }
        http_response_code(201);
        echo json_encode($result);
    } catch (\Throwable $e) {
        write_log('Submission creation failed: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Internal error creating submission']);
    }
}

function apiProcessSubmission(array $params = []): void
{
    header('Content-Type: application/json');
    $ctx = module();
    if (!$ctx) { http_response_code(500); echo json_encode(['ok' => false, 'error' => 'Module context unavailable']); return; }
    academic_similarity_require_admin($ctx);
    app()->csrfEnforce();

    $tenantId = (string)(app()->tenant()->current() ?? '');
    $submissionId = (int)($params['id'] ?? 0);

    try {
        $pipeline = new \AcademicSimilarityPipelineService($tenantId);
        $result = $pipeline->processSubmission($submissionId);
        echo json_encode($result);
    } catch (\Throwable $e) {
        write_log('Processing failed: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Internal error processing submission']);
    }
}

function apiDeleteSubmission(array $params = []): void
{
    header('Content-Type: application/json');
    $ctx = module();
    if (!$ctx) { http_response_code(500); echo json_encode(['ok' => false, 'error' => 'Module context unavailable']); return; }
    academic_similarity_require_admin($ctx);
    app()->csrfEnforce();

    $tenantId = (string)(app()->tenant()->current() ?? '');
    $submissionId = (int)($params['id'] ?? 0);

    try {
        $repo = new \AcademicSimilaritySubmissionRepository($tenantId);
        $repo->delete($submissionId);
        echo json_encode(['ok' => true, 'message' => 'Submission deleted']);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Internal error deleting submission']);
    }
}

function apiCreateSource(array $params = []): void
{
    header('Content-Type: application/json');
    $ctx = module();
    if (!$ctx) { http_response_code(500); echo json_encode(['ok' => false, 'error' => 'Module context unavailable']); return; }
    academic_similarity_require_admin($ctx);
    app()->csrfEnforce();

    $tenantId = (string)(app()->tenant()->current() ?? '');
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

    try {
        $service = new \AcademicSimilaritySourceService($tenantId);
        $result = $service->create($input);
        if (!$result['ok']) {
            http_response_code(422);
            echo json_encode($result);
            return;
        }
        http_response_code(201);
        echo json_encode($result);
    } catch (\Throwable $e) {
        write_log('Source creation failed: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Internal error creating source']);
    }
}

function apiReindexSource(array $params = []): void
{
    header('Content-Type: application/json');
    $ctx = module();
    if (!$ctx) { http_response_code(500); echo json_encode(['ok' => false, 'error' => 'Module context unavailable']); return; }
    academic_similarity_require_admin($ctx);
    app()->csrfEnforce();

    $tenantId = (string)(app()->tenant()->current() ?? '');
    $sourceId = (int)($params['id'] ?? 0);

    $service = new \AcademicSimilaritySourceService($tenantId);
    $result = $service->reindex($sourceId);
    echo json_encode($result);
}

function apiDeleteSource(array $params = []): void
{
    header('Content-Type: application/json');
    $ctx = module();
    if (!$ctx) { http_response_code(500); echo json_encode(['ok' => false, 'error' => 'Module context unavailable']); return; }
    academic_similarity_require_admin($ctx);
    app()->csrfEnforce();

    $tenantId = (string)(app()->tenant()->current() ?? '');

    try {
        $repo = new \AcademicSimilaritySourceRepository($tenantId);
        $repo->delete((int)($params['id'] ?? 0));
        echo json_encode(['ok' => true, 'message' => 'Source deleted']);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Internal error deleting source']);
    }
}

function apiCreateCollection(array $params = []): void
{
    header('Content-Type: application/json');
    $ctx = module();
    if (!$ctx) { http_response_code(500); echo json_encode(['ok' => false, 'error' => 'Module context unavailable']); return; }
    academic_similarity_require_admin($ctx);
    app()->csrfEnforce();

    $tenantId = (string)(app()->tenant()->current() ?? '');
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

    try {
        $repo = new \AcademicSimilarityCollectionRepository($tenantId);
        $id = $repo->create($input['name'] ?? '', $input['description'] ?? '', (int)($input['institution_id'] ?? 0));
        http_response_code(201);
        echo json_encode(['ok' => true, 'id' => $id]);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
}

function apiDeleteCollection(array $params = []): void
{
    header('Content-Type: application/json');
    $ctx = module();
    if (!$ctx) { http_response_code(500); echo json_encode(['ok' => false, 'error' => 'Module context unavailable']); return; }
    academic_similarity_require_admin($ctx);
    app()->csrfEnforce();

    $tenantId = (string)(app()->tenant()->current() ?? '');

    try {
        $repo = new \AcademicSimilarityCollectionRepository($tenantId);
        $repo->delete((int)($params['id'] ?? 0));
        echo json_encode(['ok' => true, 'message' => 'Collection deleted']);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Internal error deleting collection']);
    }
}

function apiExcludeMatch(array $params = []): void
{
    header('Content-Type: application/json');
    $ctx = module();
    if (!$ctx) { http_response_code(500); echo json_encode(['ok' => false, 'error' => 'Module context unavailable']); return; }
    academic_similarity_require_admin($ctx);
    app()->csrfEnforce();

    $tenantId = (string)(app()->tenant()->current() ?? '');
    $matchId = (int)($params['match_id'] ?? 0);
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

    try {
        $service = new \AcademicSimilarityReviewService($tenantId);
        $result = $service->excludeMatch($matchId, $input['reason'] ?? '', $input['note'] ?? '');
        echo json_encode($result);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
}

function apiSaveSettings(array $params = []): void
{
    header('Content-Type: application/json');
    $ctx = module();
    if (!$ctx) { http_response_code(500); echo json_encode(['ok' => false, 'error' => 'Module context unavailable']); return; }
    academic_similarity_require_admin($ctx);
    app()->csrfEnforce();

    $tenantId = (string)(app()->tenant()->current() ?? '');
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    foreach ([
        'enabled', 'exact_match_enabled', 'near_match_enabled', 'semantic_match_enabled',
        'semantic_health_visible', 'cms_public_submission_enabled', 'cms_builder_block_enabled',
        'report_include_highlights', 'report_include_source_breakdown', 'auto_generate_reports',
        'notify_on_completion',
    ] as $checkboxKey) {
        if (!array_key_exists($checkboxKey, $input)) {
            $input[$checkboxKey] = '0';
        }
    }

    try {
        academic_similarity_save_settings($tenantId, $input);
        $accept = (string)($_SERVER['HTTP_ACCEPT'] ?? '');
        if (!str_contains($accept, 'application/json')) {
            header('Location: /admin/academic-similarity/settings?saved=1');
            return;
        }
        echo json_encode(['ok' => true, 'message' => 'Settings saved']);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
}

function apiPublicSubmit(array $params = []): void
{
    header('Content-Type: application/json');

    $tenantId = (string)(app()->tenant()->current() ?? '');

    $input = $_POST;

    // Handle file upload
    if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
        $input['file'] = $_FILES['file'];
    }

    $pastedText = trim((string)($input['pasted_text'] ?? ''));
    if ($pastedText !== '' && empty($input['file'])) {
        $input['source_type'] = 'pasted';
    } elseif (($input['source_type'] ?? '') === '') {
        $input['source_type'] = !empty($input['file']) ? 'upload' : 'pasted';
    }

    try {
        $institutionId = (int)($input['institution_id'] ?? 0);
        if ($institutionId <= 0) {
            $db = academic_similarity_db();
            $stmt = $db->prepare("SELECT id FROM ac_similarity_institutions WHERE tenant_id = :tid AND is_active = 1 ORDER BY id ASC LIMIT 1");
            $stmt->execute([':tid' => $tenantId]);
            $institutionId = (int)($stmt->fetchColumn() ?: 0);
        }
        if ($institutionId <= 0) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'No active Similarity institution is configured for public submissions']);
            return;
        }
        $service = new \AcademicSimilaritySubmissionService($tenantId);
        $input['institution_id'] = $institutionId;
        $result = $service->create($input);
        if (!$result['ok']) {
            http_response_code(422);
            echo json_encode($result);
            return;
        }
        http_response_code(201);
        echo json_encode($result);
    } catch (\Throwable $e) {
        write_log('Public submission failed: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Internal error processing submission']);
    }
}
