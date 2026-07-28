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
    $stats['csrf_token'] = app()->csrfToken() ?? '';
    $stats['processed_now'] = isset($_GET['processed']) ? (int)$_GET['processed'] : null;
    $stats['failed_now'] = isset($_GET['failed']) ? (int)$_GET['failed'] : null;

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
    $submissions = array_map(static function (array $row): array {
        $row['submission_id'] = $row['id'] ?? $row['submission_id'] ?? 0;
        return $row;
    }, $repo->search($institutionId, $status, $search, $page, $perPage));
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
        'csrf_token' => app()->csrfToken() ?? '',
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
    $submission['submission_id'] = $submission['id'] ?? $submissionId;

    $matchRepo = new \AcademicSimilarityMatchRepository($tenantId);
    $matches = $matchRepo->findBySubmissionId($submissionId);
    $report = \AcademicSimilarityReportService::getForSubmission($tenantId, $submissionId);
    $internetRun = null;
    try {
        $internetRun = (new \AcademicSimilarityInternetCheckService($tenantId))->latestRun($submissionId);
    } catch (\Throwable $e) {
        write_log('Failed to load internet check status for submission ' . $submissionId . ': ' . $e->getMessage());
    }

    // Read redirect-feedback query params set by apiProcessSubmission / apiRunInternetCheck
    $internetCheckQuery = (string)($_GET['internet_check'] ?? '');
    $processQuery = (string)($_GET['process'] ?? '');

    echo $ctx->render('academic_similarity/submissions/detail', [
        'submission' => $submission,
        'matches' => $matches,
        'report' => $report,
        'internet_run' => $internetRun ?? [],
        'internet_check_query' => $internetCheckQuery,
        'process_query' => $processQuery,
        'internet_check_enabled' => (academic_similarity_get_settings($tenantId)['internet_check_enabled'] ?? '0') === '1',
        'active_nav' => 'submissions',
        'csrf_token' => app()->csrfToken() ?? '',
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
    $search = trim((string)($_GET['search'] ?? ''));
    $type = trim((string)($_GET['type'] ?? ''));
    $collectionId = (int)($_GET['collection_id'] ?? 0);

    $repo = new \AcademicSimilaritySourceRepository($tenantId);
    $sources = array_map(static function (array $row): array {
        $row['source_id'] = $row['id'] ?? $row['source_id'] ?? 0;
        $row['author_name'] = $row['author'] ?? $row['author_name'] ?? '';
        $row['collection_name'] = $row['collection_name'] ?? '';
        return $row;
    }, $repo->search($search, 1, 50, $type, $collectionId));

    $collectionRepo = new \AcademicSimilarityCollectionRepository($tenantId);
    $collections = array_map(static function (array $row): array {
        $row['collection_id'] = $row['id'] ?? $row['collection_id'] ?? 0;
        $row['collection_name'] = $row['name'] ?? $row['collection_name'] ?? '';
        return $row;
    }, $collectionRepo->search('', 1, 100));

    echo $ctx->render('academic_similarity/sources/index', [
        'sources' => $sources,
        'search_query' => $search,
        'current_type' => $type,
        'current_collection_id' => $collectionId,
        'collections' => $collections,
        'active_nav' => 'sources',
    ]);
}

function pageSourceForm(array $params = []): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); echo 'Module context unavailable'; return; }
    academic_similarity_require_admin($ctx);

    $tenantId = (string)(app()->tenant()->current() ?? '');
    $sourceId = (int)($params['id'] ?? ($_GET['id'] ?? 0));
    $source = null;
    if ($sourceId > 0) {
        $source = (new \AcademicSimilaritySourceRepository($tenantId))->findById($sourceId);
        if (!$source) {
            http_response_code(404);
            echo $ctx->render('academic_similarity/not_found', ['resource' => 'Source']);
            return;
        }
    }

    $collectionRepo = new \AcademicSimilarityCollectionRepository($tenantId);
    $collections = array_map(static function (array $row): array {
        $row['collection_id'] = $row['id'] ?? $row['collection_id'] ?? 0;
        $row['collection_name'] = $row['name'] ?? $row['collection_name'] ?? '';
        return $row;
    }, $collectionRepo->search('', 1, 100));

    echo $ctx->render('academic_similarity/sources/form', [
        'source' => $source ?? [],
        'collections' => $collections,
        'csrf_token' => app()->csrfToken() ?? '',
        'active_nav' => 'sources',
        'error' => (string)($_GET['error'] ?? ''),
    ]);
}

function pageCollections(array $params = []): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); echo 'Module context unavailable'; return; }
    academic_similarity_require_admin($ctx);

    $tenantId = (string)(app()->tenant()->current() ?? '');
    $repo = new \AcademicSimilarityCollectionRepository($tenantId);
    $db = academic_similarity_db();
    $collections = array_map(static function (array $row) use ($db, $tenantId): array {
        $row['collection_id'] = $row['id'] ?? $row['collection_id'] ?? 0;
        $row['collection_name'] = $row['name'] ?? $row['collection_name'] ?? '';
        // Count sources in this collection
        $stmt = $db->prepare("SELECT COUNT(*) FROM ac_similarity_sources WHERE collection_id = :cid AND tenant_id = :tid");
        $stmt->execute([':cid' => $row['collection_id'], ':tid' => $tenantId]);
        $row['source_count'] = (int)$stmt->fetchColumn();
        return $row;
    }, $repo->search('', 1, 50));

    echo $ctx->render('academic_similarity/collections/index', [
        'collections' => $collections,
        'active_nav' => 'collections',
        'csrf_token' => app()->csrfToken() ?? '',
    ]);
}

function pageCollectionForm(array $params = []): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); echo 'Module context unavailable'; return; }
    academic_similarity_require_admin($ctx);

    $tenantId = (string)(app()->tenant()->current() ?? '');
    $collectionId = (int)($params['id'] ?? ($_GET['id'] ?? 0));
    $collection = null;
    if ($collectionId > 0) {
        $collection = (new \AcademicSimilarityCollectionRepository($tenantId))->findById($collectionId);
        if (!$collection) {
            http_response_code(404);
            echo $ctx->render('academic_similarity/not_found', ['resource' => 'Collection']);
            return;
        }
        $collection['collection_id'] = $collection['id'] ?? $collectionId;
        $collection['collection_name'] = $collection['name'] ?? '';
    }

    echo $ctx->render('academic_similarity/collections/form', [
        'collection' => $collection ?? [],
        'csrf_token' => app()->csrfToken() ?? '',
        'active_nav' => 'collections',
        'error' => (string)($_GET['error'] ?? ''),
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
    $report['report_id'] = $report['id'] ?? $reportId;
    $report['submission_title'] = $submission['submission_title'] ?? 'Untitled submission';
    $report['author_name'] = $submission['author_name'] ?? '';
    $report['raw_similarity_score'] = $report['raw_score'] ?? null;
    $report['adjusted_similarity_score'] = $report['adjusted_score'] ?? null;
    $report['weighted_raw_score'] = $report['weighted_raw_score'] ?? null;
    $report['weighted_adjusted_score'] = $report['weighted_adjusted_score'] ?? null;
    $report['match_count'] = $report['total_matches'] ?? count($matches);
    $report['source_count'] = count(array_unique(array_map(static fn(array $m): int => (int)($m['source_id'] ?? 0), $matches)));
    $report['word_count'] = $submission['word_count'] ?? $report['total_eligible_words'] ?? 0;
    $report['summary'] = $report['summary'] ?? '';

    // Build source cache for titles (must be BEFORE source breakdown)
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

    // Build source breakdown with names
    $scoringService = new \AcademicSimilarityScoringService($tenantId);
    $matchResults = [];
    foreach ($matches as $match) {
        $evidence = $matchRepo->getEvidence((int)$match['id']);
        $matchResults[] = new AcademicSimilarityMatchResult([
            'submission_id' => (int)$match['submission_id'],
            'source_id' => (int)$match['source_id'],
            'match_type' => $match['match_type'],
            'confidence' => (float)$match['match_confidence'],
            'matched_word_count' => (int)$match['matched_word_count'],
            'submission_word_range_start' => (int)$match['submission_word_range_start'],
            'submission_word_range_end' => (int)$match['submission_word_range_end'],
            'source_word_range_start' => (int)$match['source_word_range_start'],
            'source_word_range_end' => (int)$match['source_word_range_end'],
            'segment_match_count' => (int)$match['segment_match_count'],
            'evidence' => $evidence,
        ]);
    }
    $sourceBreakdown = $scoringService->buildSourceBreakdown($matchResults);
    // Attach source names
    foreach ($sourceBreakdown as &$sb) {
        $sid = $sb['source_id'];
        $sb['source_name'] = $sourceCache[$sid]['title'] ?? $sourceCache[$sid]['name'] ?? "Source #{$sid}";
        $totalWords = $report['word_count'] > 0 ? $report['word_count'] : 1;
        $sb['percentage'] = round(($sb['matched_words'] / $totalWords) * 100, 1);
    }
    unset($sb);
    $report['source_breakdown'] = $sourceBreakdown;

    // Build evidence map
    $evidenceMap = [];
    foreach ($matches as $match) {
        $evidenceMap[(int)$match['id']] = $matchRepo->getEvidence((int)$match['id']);
    }

    $internetBySource = [];
    try {
        $internetBySource = (new \AcademicSimilarityInternetSourceRepository($tenantId))->findBySourceIds(array_keys($sourceCache));
    } catch (\Throwable $e) {
        write_log('Failed to load internet provenance for report ' . $reportId . ': ' . $e->getMessage());
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
    foreach ($matchedPassages as &$passage) {
        $sourceId = (int)($passage['source_id'] ?? 0);
        if ($sourceId > 0 && isset($internetBySource[$sourceId])) {
            $passage['source_origin'] = 'internet';
            $passage['source_url'] = $internetBySource[$sourceId]['source_url'] ?? '';
            $passage['retrieved_at'] = $internetBySource[$sourceId]['retrieved_at'] ?? '';
        } else {
            $passage['source_origin'] = 'local';
        }
    }
    unset($passage);

    // Re-calculate scores via ScoringService for separated score families
    $scoreResult = $scoringService->calculateScore($submissionId);

    echo $ctx->render('academic_similarity/reports/detail', [
        'report'               => $report,
        'submission'           => $submission,
        'matches'              => $matches,
        'matched_passages'     => $matchedPassages,
        'highlighted_html'     => $highlightedHtml,
        'highlight_stats'      => $highlightStats,
        'highlight_legend'     => $legend,
        'source_panels'        => $sourcePanels,
        'internet_sources'     => array_values($internetBySource),
        'evidence_by_match'    => $evidenceMap,
        'report_ai_narrative'  => $report['report_ai_narrative'] ?? null,
        'score_families'       => [
            'raw_score'                 => $report['raw_similarity_score'],
            'adjusted_score'            => $report['adjusted_similarity_score'],
            'textual_raw'               => $scoreResult['textual_overlap_score'] ?? $scoreResult['raw_score'] ?? $report['raw_similarity_score'],
            'textual_adjusted'          => $scoreResult['adjusted_score'] ?? $report['adjusted_similarity_score'],
            'semantic_resemblance'      => $scoreResult['semantic_resemblance_score'] ?? 0,
            'reviewer_attention_level'  => $scoreResult['reviewer_attention_level'] ?? ['level' => 'none', 'label' => 'None', 'reasons' => []],
            'semantic_strong_relationships' => $scoreResult['semantic_strong_relationships'] ?? 0,
            'semantic_weak_relationships'   => $scoreResult['semantic_weak_relationships'] ?? 0,
            '_experimental'             => true,
        ],
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
    foreach (['processing', 'reports', 'sources', 'semantic', 'internet', 'cms'] as $section) {
        if (str_contains($path, '/settings/' . $section)) {
            $settingsSection = $section;
            break;
        }
    }
    $semantic = new \AcademicSimilaritySemanticService($tenantId);
    $semanticStatus = $semantic->isAvailable();

    $flashError = '';
    if (isset($_SESSION['_kernel_flash']['error']) && is_array($_SESSION['_kernel_flash']['error'])) {
        $flashError = implode(' ', $_SESSION['_kernel_flash']['error']);
        $_SESSION['_kernel_flash']['error'] = [];
    }

    echo $ctx->render('academic_similarity/settings', [
        'settings' => $settings,
        'settings_section' => $settingsSection,
        'semantic_status' => $semanticStatus,
        'active_nav' => 'settings',
        'flash_error' => $flashError,
        'saved' => (int)($_GET['saved'] ?? 0),
    ]);
}

function apiSemanticHealth(array $params = []): void
{
    header('Content-Type: application/json');

    $tenantId = (string)(app()->tenant()->current() ?? '');
    try {
        $semantic = new \AcademicSimilaritySemanticService($tenantId);
        $institutionId = (int)($_GET['institution_id'] ?? 0);
        $result = $semantic->isAvailable($institutionId);
        echo json_encode([
            'ok' => true,
            'available' => $result['ok'] ?? false,
            'gates' => $result['gates'] ?? [],
            'error' => $result['error'] ?? null,
        ]);
    } catch (\Throwable $e) {
        write_log('Semantic health check failed: ' . $e->getMessage());
        echo json_encode(['ok' => false, 'available' => false, 'error' => 'Health check failed: ' . $e->getMessage()]);
    }
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
    if ((int)($input['institution_id'] ?? 0) <= 0) {
        $stmt = academic_similarity_db()->prepare("SELECT id FROM ac_similarity_institutions WHERE tenant_id = :tid AND is_active = 1 ORDER BY id ASC LIMIT 1");
        $stmt->execute([':tid' => $tenantId]);
        $input['institution_id'] = (int)($stmt->fetchColumn() ?: 0);
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

        // Auto-process after creation
        try {
            $submissionId = (int)($result['submission_id'] ?? 0);
            if ($submissionId > 0) {
                $pipeline = new \AcademicSimilarityPipelineService($tenantId);
                $pipeline->processSubmission($submissionId);
            }
        } catch (\Throwable $pe) {
            write_log('Auto-processing failed for submission #' . ($result['submission_id'] ?? 0) . ': ' . $pe->getMessage());
        }
    } catch (\Throwable $e) {
        write_log('Submission creation failed: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Internal error creating submission']);
    }
}

function apiProcessSubmission(array $params = []): void
{
    $accept = (string)($_SERVER['HTTP_ACCEPT'] ?? '');
    $wantsJson = str_contains($accept, 'application/json');
    if ($wantsJson) {
        header('Content-Type: application/json');
    }
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        if ($wantsJson) {
            echo json_encode(['ok' => false, 'error' => 'Module context unavailable']);
        } else {
            echo 'Module context unavailable';
        }
        return;
    }
    academic_similarity_require_admin($ctx);
    app()->csrfEnforce();

    $tenantId = (string)(app()->tenant()->current() ?? '');
    $submissionId = (int)($params['id'] ?? 0);

    try {
        $pipeline = new \AcademicSimilarityPipelineService($tenantId);
        $result = $pipeline->processSubmission($submissionId);
        if (!$wantsJson) {
            $status = ($result['ok'] ?? false) ? 'processed' : 'failed';
            header('Location: /admin/academic-similarity/submissions/' . $submissionId . '?process=' . $status);
            return;
        }
        echo json_encode($result);
    } catch (\Throwable $e) {
        write_log('Processing failed: ' . $e->getMessage());
        http_response_code(500);
        if ($wantsJson) {
            echo json_encode(['ok' => false, 'error' => 'Internal error processing submission']);
        } else {
            header('Location: /admin/academic-similarity/submissions/' . $submissionId . '?process=failed');
        }
    }
}

function apiRunInternetCheck(array $params = []): void
{
    $accept = (string)($_SERVER['HTTP_ACCEPT'] ?? '');
    $wantsJson = str_contains($accept, 'application/json');
    if ($wantsJson) {
        header('Content-Type: application/json');
    }
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        echo $wantsJson ? json_encode(['ok' => false, 'error' => 'Module context unavailable']) : 'Module context unavailable';
        return;
    }
    academic_similarity_require_admin($ctx);
    app()->csrfEnforce();

    $tenantId = (string)(app()->tenant()->current() ?? '');
    $submissionId = (int)($params['id'] ?? 0);
    $forceSync = isset($_GET['force_sync']);

    try {
        $service = new \AcademicSimilarityInternetCheckService($tenantId);

        if ($forceSync) {
            $result = $service->runForSubmission($submissionId, true);
        } else {
            $result = $service->dispatchAsync($submissionId);
        }

        if (!$wantsJson) {
            $status = (string)($result['status'] ?? 'unknown');
            header('Location: /admin/academic-similarity/submissions/' . $submissionId . '?internet_check=' . rawurlencode($status));
            return;
        }
        echo json_encode($result);
    } catch (\Throwable $e) {
        write_log('Internet check failed: ' . $e->getMessage());
        http_response_code(500);
        if ($wantsJson) {
            echo json_encode(['ok' => false, 'error' => 'Internet check failed']);
        } else {
            header('Location: /admin/academic-similarity/submissions/' . $submissionId . '?internet_check=failed');
        }
    }
}

/**
 * API: Return status of the latest internet check run for a submission.
 * GET /api/v1/academic-similarity/submissions/{id}/internet-check-status
 */
function apiInternetCheckStatus(array $params = []): void
{
    header('Content-Type: application/json');
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Module context unavailable']);
        return;
    }
    academic_similarity_require_admin($ctx);
    // Read-only GET endpoint — no CSRF enforcement needed

    $tenantId = (string)(app()->tenant()->current() ?? '');
    $submissionId = (int)($params['id'] ?? 0);

    try {
        $service = new \AcademicSimilarityInternetCheckService($tenantId);
        $latest = $service->latestRun($submissionId);
        if ($latest === null) {
            echo json_encode(['ok' => true, 'status' => 'none', 'submission_id' => $submissionId]);
            return;
        }
        echo json_encode([
            'ok' => true,
            'status' => $latest['status'] ?? 'unknown',
            'submission_id' => $submissionId,
            'query_count' => (int)($latest['query_count'] ?? 0),
            'candidate_count' => (int)($latest['candidate_count'] ?? 0),
            'imported_count' => (int)($latest['imported_count'] ?? 0),
            'disclosure' => $latest['disclosure'] ?? '',
            'error_message' => $latest['error_message'] ?? '',
            'started_at' => $latest['started_at'] ?? null,
            'completed_at' => $latest['completed_at'] ?? null,
        ]);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Failed to fetch internet check status']);
    }
}

/**
 * Kernel job handler: process internet check asynchronously.
 * Registered via kernelDispatchJob('academic-similarity:academicSimilarityInternetCheckHandler', ...)
 */
function academicSimilarityInternetCheckHandler(array $payload): void
{
    $submissionId = (int)($payload['submission_id'] ?? 0);
    $tenantId = (string)($payload['tenant_id'] ?? '');

    if ($submissionId <= 0 || $tenantId === '') {
        write_log('academicSimilarityInternetCheckHandler: invalid payload', 'error', ['payload' => $payload]);
        return;
    }

    $service = new \AcademicSimilarityInternetCheckService($tenantId);
    $result = $service->runForSubmission($submissionId, true);

    // On success, dispatch a re-match job so new internet sources are compared
    if (($result['status'] ?? '') === 'completed' || ($result['status'] ?? '') === 'completed_partial') {
        try {
            $pipeline = new \AcademicSimilarityPipelineService($tenantId);
            $pipeline->runRecheckFromInternet($submissionId);
        } catch (\Throwable $e) {
            write_log('academicSimilarityInternetCheckHandler: re-match failed for submission #' . $submissionId . ': ' . $e->getMessage(), 'warning');
        }
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
        $service = new \AcademicSimilaritySubmissionService($tenantId);
        $service->delete($submissionId);
        echo json_encode(['ok' => true, 'message' => 'Submission deleted']);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Internal error deleting submission']);
    }
}

function apiCreateSource(array $params = []): void
{
    $accept = (string)($_SERVER['HTTP_ACCEPT'] ?? '');
    $wantsJson = str_contains($accept, 'application/json');
    if ($wantsJson) {
        header('Content-Type: application/json');
    }
    $ctx = module();
    if (!$ctx) { http_response_code(500); echo $wantsJson ? json_encode(['ok' => false, 'error' => 'Module context unavailable']) : 'Module context unavailable'; return; }
    academic_similarity_require_admin($ctx);
    app()->csrfEnforce();

    $tenantId = (string)(app()->tenant()->current() ?? '');
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    if (isset($_FILES['file']) && is_array($_FILES['file']) && ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        $input['file'] = $_FILES['file'];
    }

    if ((int)($input['institution_id'] ?? 0) <= 0) {
        $stmt = academic_similarity_db()->prepare("SELECT id FROM ac_similarity_institutions WHERE tenant_id = :tid AND is_active = 1 ORDER BY id ASC LIMIT 1");
        $stmt->execute([':tid' => $tenantId]);
        $input['institution_id'] = (int)($stmt->fetchColumn() ?: 0);
    }

    try {
        $service = new \AcademicSimilaritySourceService($tenantId);
        $result = $service->create($input);
        if (!$result['ok']) {
            if (!$wantsJson) {
                http_response_code(302);
                header('Location: /admin/academic-similarity/sources/new?error=' . rawurlencode((string)($result['error'] ?? 'Source creation failed')));
                return;
            }
            http_response_code(422);
            echo json_encode($result);
            return;
        }
        if (!$wantsJson) {
            http_response_code(302);
            header('Location: /admin/academic-similarity/sources?created=1');
            return;
        }
        http_response_code(201);
        echo json_encode($result);
    } catch (\Throwable $e) {
        write_log('Source creation failed: ' . $e->getMessage());
        if (!$wantsJson) {
            http_response_code(302);
            header('Location: /admin/academic-similarity/sources/new?error=' . rawurlencode('Internal error creating source'));
            return;
        }
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
    $accept = (string)($_SERVER['HTTP_ACCEPT'] ?? '');
    $wantsJson = str_contains($accept, 'application/json');
    if ($wantsJson) {
        header('Content-Type: application/json');
    }
    $ctx = module();
    if (!$ctx) { http_response_code(500); echo $wantsJson ? json_encode(['ok' => false, 'error' => 'Module context unavailable']) : 'Module context unavailable'; return; }
    academic_similarity_require_admin($ctx);
    app()->csrfEnforce();

    $tenantId = (string)(app()->tenant()->current() ?? '');
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    if ((int)($input['institution_id'] ?? 0) <= 0) {
        $stmt = academic_similarity_db()->prepare("SELECT id FROM ac_similarity_institutions WHERE tenant_id = :tid AND is_active = 1 ORDER BY id ASC LIMIT 1");
        $stmt->execute([':tid' => $tenantId]);
        $input['institution_id'] = (int)($stmt->fetchColumn() ?: 0);
    }

    try {
        $repo = new \AcademicSimilarityCollectionRepository($tenantId);
        $id = $repo->create($input['name'] ?? '', $input['description'] ?? '', (int)($input['institution_id'] ?? 0));
        if (!$wantsJson) {
            http_response_code(302);
            header('Location: /admin/academic-similarity/collections?created=1');
            return;
        }
        http_response_code(201);
        echo json_encode(['ok' => true, 'id' => $id]);
    } catch (\Throwable $e) {
        if (!$wantsJson) {
            http_response_code(302);
            header('Location: /admin/academic-similarity/collections/new?error=' . rawurlencode($e->getMessage()));
            return;
        }
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

function apiReviewWorkflowAction(array $params = []): void
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
        $service = new AcademicSimilarityReviewWorkflowService($tenantId);
        $result = $service->performAction(
            $matchId,
            $input['action'] ?? '',
            (int)($input['user_id'] ?? 0),
            $input['reason'] ?? '',
            $input['classification'] ?? null
        );
        echo json_encode($result);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
}

function apiContextAnalyze(array $params = []): void
{
    header('Content-Type: application/json');
    $ctx = module();
    if (!$ctx) { http_response_code(500); echo json_encode(['ok' => false, 'error' => 'Module context unavailable']); return; }
    academic_similarity_require_admin($ctx);
    app()->csrfEnforce();

    $tenantId = (string)(app()->tenant()->current() ?? '');
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

    try {
        $service = new AcademicSimilarityContextAnalysisService($tenantId);
        $result = $service->analyze($input);
        echo json_encode($result);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
}

function apiCitationAnalyze(array $params = []): void
{
    header('Content-Type: application/json');
    $ctx = module();
    if (!$ctx) { http_response_code(500); echo json_encode(['ok' => false, 'error' => 'Module context unavailable']); return; }
    academic_similarity_require_admin($ctx);
    app()->csrfEnforce();

    $tenantId = (string)(app()->tenant()->current() ?? '');
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

    try {
        $service = new AcademicSimilarityCitationAnalysisService($tenantId);
        $result = $service->analyzePassage(
            $input['submission_passage'] ?? '',
            $input['bibliography_text'] ?? null
        );
        echo json_encode($result);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
}

function apiScholarshipProfile(array $params = []): void
{
    header('Content-Type: application/json');
    $ctx = module();
    if (!$ctx) { http_response_code(500); echo json_encode(['ok' => false, 'error' => 'Module context unavailable']); return; }
    academic_similarity_require_admin($ctx);

    $tenantId = (string)(app()->tenant()->current() ?? '');
    $submissionId = (int)($params['id'] ?? 0);

    try {
        $service = new AcademicSimilarityScholarshipProfileService($tenantId);
        $result = $service->generateProfile($submissionId);
        echo json_encode($result);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
}

function apiLineageGraph(array $params = []): void
{
    header('Content-Type: application/json');
    $ctx = module();
    if (!$ctx) { http_response_code(500); echo json_encode(['ok' => false, 'error' => 'Module context unavailable']); return; }
    academic_similarity_require_admin($ctx);

    $tenantId = (string)(app()->tenant()->current() ?? '');
    $submissionId = (int)($params['id'] ?? 0);

    try {
        $service = new AcademicSimilarityKnowledgeLineageService($tenantId);
        $result = $service->buildGraph($submissionId);
        echo json_encode($result);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
}

function apiLineageMermaid(array $params = []): void
{
    header('Content-Type: text/plain; charset=utf-8');
    $ctx = module();
    if (!$ctx) { http_response_code(500); echo 'Module context unavailable'; return; }
    academic_similarity_require_admin($ctx);

    $tenantId = (string)(app()->tenant()->current() ?? '');
    $submissionId = (int)($params['id'] ?? 0);

    try {
        $service = new AcademicSimilarityKnowledgeLineageService($tenantId);
        echo $service->renderMermaid($submissionId);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo 'Error generating lineage graph: ' . $e->getMessage();
    }
}

function apiSaveSettings(array $params = []): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); echo json_encode(['ok' => false, 'error' => 'Module context unavailable']); return; }
    academic_similarity_require_admin($ctx);

    $isJson = str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
    if ($isJson) {
        header('Content-Type: application/json');
    }

    // Validate CSRF token
    app()->csrfEnforce();

    $tenantId = (string)(app()->tenant()->current() ?? '');
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

    foreach ([
        'enabled', 'exact_match_enabled', 'near_match_enabled', 'semantic_match_enabled',
        'semantic_health_visible', 'cms_public_submission_enabled', 'cms_builder_block_enabled',
        'report_include_highlights', 'report_include_source_breakdown', 'auto_generate_reports',
        'notify_on_completion', 'internet_check_enabled', 'internet_check_auto_run_when_no_sources',
        'internet_check_allow_full_document_query', 'internet_check_store_retrieved_text',
        'public_results_enabled', 'public_results_show_scores', 'public_results_show_match_count',
        'public_results_show_report_links', 'public_results_allow_anonymous',
        'public_report_workspace_enabled', 'public_report_download_enabled',
        'public_report_show_raw_score', 'public_report_show_source_names',
        'public_report_show_full_document',
    ] as $checkboxKey) {
        if (!array_key_exists($checkboxKey, $input)) {
            $input[$checkboxKey] = '0';
        }
    }

    try {
        academic_similarity_save_settings($tenantId, $input);
        if (function_exists('write_log')) {
            write_log('AISS settings saved', 'debug', ['tenant_id' => $tenantId, 'keys' => array_keys($input)]);
        }
        if (!$isJson) {
            $section = preg_replace('/[^a-z_]/', '', (string)($input['settings_section'] ?? ''));
            $validSections = ['processing', 'reports', 'sources', 'semantic', 'internet', 'cms'];
            $target = '/admin/academic-similarity/settings';
            if (in_array($section, $validSections, true)) {
                $target .= '/' . $section;
            }
            header('Location: ' . $target . '?saved=1');
            exit;
        }
        echo json_encode(['ok' => true, 'message' => 'Settings saved']);
    } catch (\Throwable $e) {
        if (function_exists('write_log')) {
            write_log('AISS settings save failed: ' . $e->getMessage(), 'error', ['tenant_id' => $tenantId]);
        }
        if ($isJson) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        } else {
            $_SESSION['_kernel_flash']['error'][] = 'Save failed: ' . $e->getMessage();
            header('Location: /admin/academic-similarity/settings');
            exit;
        }
    }
}

function apiPublicSubmit(array $params = []): void
{
    header('Content-Type: application/json');
    app()->csrfEnforce();

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

    // Attach submitter identity if logged in
    $submitterUserId = \AcademicSimilarityUserResultService::getCurrentUserId();
    if ($submitterUserId > 0) {
        $input['submitter_user_id'] = $submitterUserId;
        $input['submitter_source'] = \AcademicSimilarityUserResultService::getCurrentUserSource();
        // If the user is logged in but didn't provide author_name, use their name
        if (empty($input['author_name'])) {
            $user = \AcademicSimilarityUserResultService::getCurrentUser();
            if ($user !== null) {
                $input['author_name'] = (string)($user['name'] ?? $user['username'] ?? '');
            }
        }
    } else {
        // Anonymous submissions are not supported — reject
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Authentication required to submit documents']);
        return;
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

        // Auto-process the submission right after creation
        try {
            $pipeline = new \AcademicSimilarityPipelineService($tenantId);
            $processResult = $pipeline->processSubmission((int)($result['submission_id'] ?? 0));
            $result['processing'] = $processResult['ok'] ?? false;
            if (!empty($processResult['status'])) {
                $result['processed_status'] = $processResult['status'];
            }
        } catch (\Throwable $pe) {
            write_log('Public submission auto-processing failed: ' . $pe->getMessage());
            $result['processing'] = false;
            $result['processing_error'] = 'Processing will complete shortly.';
        }

        // Return success — omit internal user ID
        echo json_encode($result);
    } catch (\Throwable $e) {
        write_log('Public submission failed: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Internal error processing submission']);
    }
}

function apiPublicResults(array $params = []): void
{
    header('Content-Type: application/json');

    $tenantId = (string)(app()->tenant()->current() ?? '');
    $submitterUserId = \AcademicSimilarityUserResultService::getCurrentUserId();

    if ($submitterUserId <= 0) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Authentication required to view results']);
        return;
    }

    $settings = academic_similarity_get_settings($tenantId);
    if (($settings['public_results_enabled'] ?? '1') !== '1') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Public results are disabled']);
        return;
    }

    $limit = max(1, min(50, (int)($settings['public_results_recent_limit'] ?? 10)));

    try {
        $service = new \AcademicSimilarityUserResultService($tenantId);
        $stats = $service->getSummaryStats($submitterUserId);
        $recent = $service->getRecentSubmissions($submitterUserId, $limit);

        $showScores = ($settings['public_results_show_scores'] ?? '1') === '1';
        $showMatchCount = ($settings['public_results_show_match_count'] ?? '1') === '1';
        $showReportLinks = ($settings['public_results_show_report_links'] ?? '1') === '1';

        // Strip admin-only fields from recent results
        $safeRecent = [];
        foreach ($recent as $row) {
            $entry = [
                'id' => (int)$row['id'],
                'submission_title' => $row['submission_title'] ?? '',
                'status' => $row['status'] ?? 'pending',
                'submitted_at' => $row['submitted_at'] ?? '',
                'processed_at' => $row['processed_at'] ?? null,
                'word_count' => (int)($row['word_count'] ?? 0),
            ];
            if ($showScores) {
                $entry['raw_similarity_score'] = $row['raw_similarity_score'] !== null ? (float)$row['raw_similarity_score'] : null;
                $entry['adjusted_similarity_score'] = $row['adjusted_similarity_score'] !== null ? (float)$row['adjusted_similarity_score'] : null;
            }
            if ($showMatchCount) {
                $entry['matched_word_count'] = (int)($row['matched_word_count'] ?? 0);
                $entry['total_eligible_words'] = (int)($row['total_eligible_words'] ?? 0);
            }
            if ($showReportLinks && !empty($row['report_id'])) {
                $entry['report_id'] = (int)$row['report_id'];
            }
            if ($row['status'] === 'failed') {
                $entry['error'] = 'Processing encountered an issue. Contact support if this persists.';
            }
            $safeRecent[] = $entry;
        }

        echo json_encode([
            'ok' => true,
            'stats' => $stats,
            'recent' => $safeRecent,
            'show_scores' => $showScores,
            'show_match_count' => $showMatchCount,
            'show_report_links' => $showReportLinks,
        ]);
    } catch (\Throwable $e) {
        write_log('Public results failed: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Failed to load results']);
    }
}

function apiPublicReportSummary(array $params = []): void
{
    header('Content-Type: application/json');

    $tenantId = (string)(app()->tenant()->current() ?? '');
    $submissionId = (int)($params['submission_id'] ?? 0);
    $submitterUserId = \AcademicSimilarityUserResultService::getCurrentUserId();

    if ($submitterUserId <= 0) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Authentication required']);
        return;
    }

    if ($submissionId <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid submission ID']);
        return;
    }

    try {
        $service = new \AcademicSimilarityUserResultService($tenantId);
        $summary = $service->getReportSummary($submissionId, $submitterUserId);

        if ($summary === null) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Report not found or access denied']);
            return;
        }

        // Build safe output
        $settings = academic_similarity_get_settings($tenantId);
        $showScores = ($settings['public_results_show_scores'] ?? '1') === '1';

        $output = [
            'ok' => true,
            'submission' => [
                'id' => (int)$summary['id'],
                'submission_title' => $summary['submission_title'] ?? '',
                'author_name' => $summary['author_name'] ?? '',
                'status' => $summary['status'] ?? '',
                'submitted_at' => $summary['submitted_at'] ?? '',
                'processed_at' => $summary['processed_at'] ?? null,
                'word_count' => (int)($summary['word_count'] ?? 0),
            ],
        ];

        if ($showScores) {
            $output['submission']['raw_similarity_score'] = $summary['raw_similarity_score'] !== null ? (float)$summary['raw_similarity_score'] : null;
            $output['submission']['adjusted_similarity_score'] = $summary['adjusted_similarity_score'] !== null ? (float)$summary['adjusted_similarity_score'] : null;
            $output['submission']['matched_word_count'] = (int)($summary['matched_word_count'] ?? 0);
            $output['submission']['total_eligible_words'] = (int)($summary['total_eligible_words'] ?? 0);
        }

        if (!empty($summary['report_id'])) {
            $output['report'] = [
                'id' => (int)$summary['report_id'],
                'generated_at' => $summary['report_generated_at'] ?? null,
                'total_matches' => (int)($summary['total_matches'] ?? 0),
            ];
            if ($showScores) {
                $output['report']['raw_score'] = $summary['raw_score'] !== null ? (float)$summary['raw_score'] : null;
                $output['report']['adjusted_score'] = $summary['report_adjusted_score'] !== null ? (float)$summary['report_adjusted_score'] : null;
            }
        }

        echo json_encode($output);
    } catch (\Throwable $e) {
        write_log('Public report summary failed: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Failed to load report']);
    }
}

function apiPublicReportViewer(array $params = []): void
{
    header('Content-Type: application/json');

    $tenantId = (string)(app()->tenant()->current() ?? '');
    $submissionId = (int)($params['submission_id'] ?? 0);
    $submitterUserId = \AcademicSimilarityUserResultService::getCurrentUserId();

    if ($submitterUserId <= 0) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Authentication required']);
        return;
    }

    if ($submissionId <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid submission ID']);
        return;
    }

    try {
        $settings = academic_similarity_get_settings($tenantId);
        $showScores = ($settings['public_results_show_scores'] ?? '1') === '1';
        $showSourceNames = ($settings['public_report_show_source_names'] ?? '1') === '1';
        $showFullDocument = ($settings['public_report_show_full_document'] ?? '1') === '1';
        $viewService = new \AcademicSimilarityPublicReportViewService($tenantId);
        $view = $viewService->getView($submissionId, $submitterUserId, $showSourceNames);

        if ($view === null) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Submission not found or access denied']);
            return;
        }

        // Apply settings-based visibility filters
        $safeView = [
            'ok' => true,
            'submission' => $view['submission'],
            'analysis' => $showScores ? $view['analysis'] : [
                'status' => $view['submission']['status'],
                'match_count' => $view['analysis']['match_count'],
                'source_count' => $view['analysis']['source_count'],
            ],
            'highlights' => [
                'highlighted_html' => $showFullDocument ? ($view['highlights']['highlighted_html'] ?? '') : '',
                'highlight_legend' => $view['highlights']['highlight_legend'],
                'highlight_stats' => $view['highlights']['highlight_stats'],
                'source_panels' => $showSourceNames ? $view['highlights']['source_panels'] : array_map(function (array $p): array {
                    $p['title'] = 'Matched Source';
                    return $p;
                }, $view['highlights']['source_panels']),
                'matched_passages' => $view['highlights']['matched_passages'],
            ],
            'report' => $view['report'],
            'download' => $view['download'],
        ];

        echo json_encode($safeView);
    } catch (\Throwable $e) {
        write_log('Public report viewer failed: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Failed to load report viewer']);
    }
}

function apiPublicReportDownload(array $params = []): void
{
    $tenantId = (string)(app()->tenant()->current() ?? '');
    $submissionId = (int)($params['submission_id'] ?? 0);
    $submitterUserId = \AcademicSimilarityUserResultService::getCurrentUserId();

    if ($submitterUserId <= 0) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Authentication required']);
        return;
    }

    if ($submissionId <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid submission ID']);
        return;
    }

    try {
        $settings = academic_similarity_get_settings($tenantId);
        if (($settings['public_report_download_enabled'] ?? '1') !== '1') {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Report download is disabled']);
            return;
        }
        $showSourceNames = ($settings['public_report_show_source_names'] ?? '1') === '1';

        $viewService = new \AcademicSimilarityPublicReportViewService($tenantId);
        $view = $viewService->getView($submissionId, $submitterUserId, $showSourceNames);

        if ($view === null) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Submission not found or access denied']);
            return;
        }

        if (!$view['download']['can_download']) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Report not yet available for this submission']);
            return;
        }

        // Build safe public report using the report generator with redacted admin details
        $generator = new \AcademicSimilarityReportGenerator($tenantId);
        $reportData = $generator->generate($submissionId);

        if (!$reportData['ok']) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Failed to generate report']);
            return;
        }

        // Redact admin-only source details from the report
        if (!$showSourceNames && isset($reportData['report']['source_breakdown'])) {
            foreach ($reportData['report']['source_breakdown'] as &$sb) {
                if (isset($sb['source'])) {
                    $sb['source']['title'] = 'Matched Source';
                    $sb['source']['author'] = '';
                }
            }
            unset($sb);
        }

        // Build highlight data for the HTML report
        $submissionRepo = new \AcademicSimilaritySubmissionRepository($tenantId);
        $matchRepo = new \AcademicSimilarityMatchRepository($tenantId);
        $submission = $submissionRepo->findById($submissionId);
        $matches = $matchRepo->findBySubmissionId($submissionId);

        // Build source cache and evidence map for highlights
        $sourceRepo = new \AcademicSimilaritySourceRepository($tenantId);
        $sourceCache = [];
        foreach ($matches as $match) {
            $sid = (int)($match['source_id'] ?? 0);
            if ($sid > 0 && !isset($sourceCache[$sid])) {
                $src = $sourceRepo->findById($sid);
                if ($src) {
                    if (!$showSourceNames) {
                        $src['title'] = 'Matched Source';
                        $src['author'] = '';
                    }
                    $sourceCache[$sid] = $src;
                }
            }
        }
        $evidenceMap = [];
        foreach ($matches as $match) {
            $evidenceMap[(int)$match['id']] = $matchRepo->getEvidence((int)$match['id']);
        }

        // Load text for highlights
        $submissionText = '';
        $sourceTexts = [];
        $db = academic_similarity_db();
        $tvStmt = $db->prepare(
            "SELECT extracted_text FROM ac_similarity_text_versions WHERE submission_id = :sid AND tenant_id = :tid AND text_type = 'submission' ORDER BY id DESC LIMIT 1"
        );
        $tvStmt->execute([':sid' => $submissionId, ':tid' => $tenantId]);
        $tv = $tvStmt->fetch(\PDO::FETCH_ASSOC);
        $submissionText = $tv['extracted_text'] ?? '';

        foreach ($sourceCache as $sid => $src) {
            $sStmt = $db->prepare(
                "SELECT extracted_text FROM ac_similarity_text_versions WHERE source_id = :sid AND tenant_id = :tid AND text_type = 'source' ORDER BY id DESC LIMIT 1"
            );
            $sStmt->execute([':sid' => $sid, ':tid' => $tenantId]);
            $sTv = $sStmt->fetch(\PDO::FETCH_ASSOC);
            if ($sTv) {
                $sourceTexts[$sid] = $sTv['extracted_text'];
            }
        }

        $highlightService = new \AcademicSimilarityHighlightService($tenantId);
        $highlightData = $highlightService->buildSpans($submissionId, $matches, $evidenceMap, $submission, $sourceCache);
        $spans = $highlightData['spans'];
        $highlightedHtml = $highlightService->renderHighlightedText($submissionText, $spans);
        $sourcePanels = $highlightService->renderSourcePanels($spans, $sourceTexts);

        $html = $generator->buildHtml($reportData['report'], [
            'legend' => $highlightData['legend'],
            'highlighted_html' => $highlightedHtml,
            'source_panels' => $sourcePanels,
        ]);

        // Override source titles in HTML if hidden
        if (!$showSourceNames) {
            $html = preg_replace('/Source #\d+/', 'Matched Source', $html);
        }

        header('Content-Type: text/html; charset=utf-8');
        header('Content-Disposition: attachment; filename="similarity-report-' . $submissionId . '.html"');
        echo $html;
    } catch (\Throwable $e) {
        write_log('Public report download failed: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Failed to download report']);
    }
}

function apiProcessAllPending(array $params = []): void
{
    $accept = (string)($_SERVER['HTTP_ACCEPT'] ?? '');
    $wantsJson = str_contains($accept, 'application/json');
    if ($wantsJson) {
        header('Content-Type: application/json');
    }
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        if ($wantsJson) {
            echo json_encode(['ok' => false, 'error' => 'Module context unavailable']);
        } else {
            echo 'Module context unavailable';
        }
        return;
    }
    academic_similarity_require_admin($ctx);
    app()->csrfEnforce();

    $tenantId = (string)(app()->tenant()->current() ?? '');

    try {
        $db = academic_similarity_db();
        $stmt = $db->prepare(
            "SELECT id FROM ac_similarity_submissions WHERE tenant_id = :tid AND (status = 'pending' OR status = 'processing') ORDER BY created_at ASC"
        );
        $stmt->execute([':tid' => $tenantId]);
        $pending = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if (empty($pending)) {
            if (!$wantsJson) {
                header('Location: /admin/academic-similarity?processed=0&failed=0');
                return;
            }
            echo json_encode(['ok' => true, 'processed' => 0, 'failed' => 0, 'message' => 'No pending submissions']);
            return;
        }

        $pipeline = new \AcademicSimilarityPipelineService($tenantId);
        $processed = 0;
        $failed = 0;
        $errors = [];

        foreach ($pending as $row) {
            $id = (int)$row['id'];
            try {
                $result = $pipeline->processSubmission($id);
                if ($result['ok'] ?? false) {
                    $processed++;
                } else {
                    $failed++;
                    $errors[] = ['id' => $id, 'error' => $result['error'] ?? 'Unknown error'];
                }
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = ['id' => $id, 'error' => $e->getMessage()];
            }
        }

        if (!$wantsJson) {
            header('Location: /admin/academic-similarity?processed=' . $processed . '&failed=' . $failed);
            return;
        }

        echo json_encode([
            'ok' => true,
            'processed' => $processed,
            'failed' => $failed,
            'total' => count($pending),
            'errors' => $errors,
        ]);
    } catch (\Throwable $e) {
        write_log('Process all pending failed: ' . $e->getMessage());
        http_response_code(500);
        if ($wantsJson) {
            echo json_encode(['ok' => false, 'error' => 'Failed to process pending submissions']);
        } else {
            header('Location: /admin/academic-similarity?processed=0&failed=1');
        }
    }
}
