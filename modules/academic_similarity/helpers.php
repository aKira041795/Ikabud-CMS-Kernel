<?php
declare(strict_types=1);

/**
 * Academic Similarity — helpers, capability handlers, and bootstrap.
 *
 * Settings default version — bump this whenever the $defaults array in
 * academic_similarity_get_settings() changes meaningfully. The migration
 * 008_academic_similarity_reconcile_settings.sql stores this version in
 * each tenant's `_defaults_version` setting so stale tenants can be
 * detected automatically.
 */
define('ACADEMIC_SIMILARITY_DEFAULTS_VERSION', '009');

// ── Auto-load module services ────────────────────────────────────
(function (): void {
    $base = __DIR__ . '/src';
    $files = [
        '/Repositories/AcademicSimilarityInstitutionRepository.php',
        '/Repositories/AcademicSimilaritySubmissionRepository.php',
        '/Repositories/AcademicSimilaritySourceRepository.php',
        '/Repositories/AcademicSimilarityCollectionRepository.php',
        '/Repositories/AcademicSimilarityMatchRepository.php',
        '/Repositories/AcademicSimilarityReportRepository.php',
        '/Repositories/AcademicSimilarityAuditRepository.php',
        '/Repositories/AcademicSimilarityProcessingJobRepository.php',
        '/Repositories/AcademicSimilarityUsageCounterRepository.php',
        '/Repositories/AcademicSimilarityInternetSearchRunRepository.php',
        '/Repositories/AcademicSimilarityInternetSourceRepository.php',
        '/Services/AcademicSimilaritySubmissionService.php',
        '/Services/AcademicSimilarityPipelineService.php',
        '/Services/AcademicSimilaritySourceService.php',
        '/Services/AcademicSimilarityReportService.php',
        '/Services/AcademicSimilarityReviewService.php',
        '/Services/AcademicSimilarityReviewWorkflowService.php',
        '/Services/AcademicSimilarityScholarshipProfileService.php',
        '/Services/AcademicSimilarityKnowledgeLineageService.php',
        '/Services/AcademicSimilarityNormalizationService.php',
        '/Services/AcademicSimilarityFingerprintService.php',
        '/Services/AcademicSimilarityMatchingService.php',
        '/Services/AcademicSimilarityScoringService.php',
        '/Services/AcademicSimilarityQuotaService.php',
        '/Services/AcademicSimilaritySemanticService.php',
        '/Services/AcademicSimilarityContextAnalysisService.php',
        '/Services/AcademicSimilarityCitationAnalysisService.php',
        '/Services/AcademicSimilarityInternetDiscoveryService.php',
        '/Services/AcademicSimilarityInternetSourceIngestionService.php',
        '/Services/AcademicSimilarityInternetCheckService.php',
        '/ValueObjects/AcademicSimilarityNormalizedText.php',
        '/ValueObjects/AcademicSimilaritySegment.php',
        '/ValueObjects/AcademicSimilarityFingerprint.php',
        '/ValueObjects/AcademicSimilarityMatchResult.php',
        '/ValueObjects/AcademicSimilarityHighlightSpan.php',
        '/ValueObjects/AcademicSimilarityEvidenceTaxonomy.php',
        '/Services/AcademicSimilarityHighlightService.php',
        '/Services/AcademicSimilarityUserResultService.php',
        '/Services/AcademicSimilarityPublicReportViewService.php',
        '/Jobs/AcademicSimilarityProcessJob.php',
        '/Reports/AcademicSimilarityReportGenerator.php',
        '/Policies/AcademicSimilarityTenantPolicy.php',
        '/Policies/AcademicSimilarityQuotaPolicy.php',
        '/Validators/AcademicSimilarityFileValidator.php',
        '/Support/AcademicSimilarityTextExtractor.php',
        '/Support/AcademicSimilarityStorage.php',
        '/Support/ExtractionLimits.php',
    ];
    foreach ($files as $file) {
        $path = $base . $file;
        if (file_exists($path)) {
            require_once $path;
        }
    }
})();

// ── Render helper ────────────────────────────────────────────────
function academic_similarity_render(string $template, array $data = []): string
{
    $data += [
        'active_nav' => '',
        'settings_section' => '',
    ];

    return app()->render('modules/academic-similarity/' . ltrim($template, '/'), $data);
}

// ── DB helper ────────────────────────────────────────────────────
function academic_similarity_db(): \Ikabud\Kernel\Contracts\ModuleDB
{
    return module()->db();
}

// ── Settings ─────────────────────────────────────────────────────
function academic_similarity_get_settings(string $tenantId): array
{
    $defaults = [
        'enabled' => '1',
        'exact_match_enabled' => '1',
        'near_match_enabled' => '1',
        'semantic_match_enabled' => '1',
        'semantic_provider' => 'token_overlap',
        'semantic_model_name' => 'token_overlap',
        'semantic_service_endpoint' => 'http://127.0.0.1:9003',
        'semantic_service_token_env' => 'SEMANTIC_SERVICE_TOKEN',
        'semantic_external_api_key_env' => 'SEMANTIC_API_KEY',
        'semantic_external_api_key' => '',
        'semantic_similarity_threshold' => '0.25',
        'semantic_report_threshold' => '0.70',
        'semantic_max_segments' => '500',
        'semantic_payload_policy' => 'segments_only',
        'semantic_health_visible' => '1',
        'cms_public_submission_enabled' => '1',
        'cms_submission_shortcode' => 'academic_similarity_submission',
        'cms_builder_block_enabled' => '1',
        'cms_default_submission_title' => 'Submit Document for Similarity Check',
        'similarity_threshold' => '70',
        'min_match_length' => '5',
        'processing_batch_size' => '10',
        'max_sources_per_comparison' => '100',
        'report_include_highlights' => '1',
        'report_include_source_breakdown' => '1',
        'auto_generate_reports' => '1',
        'notify_on_completion' => '0',
        'min_word_count' => '20',
        'max_word_count' => '50000',
        'max_file_size_mb' => '20',
        'fingerprint_shingle_size' => '5',
        'near_match_threshold' => '0.8',
        'retention_days' => '365',
        'allowed_extensions' => 'docx,pdf,txt',
        'public_results_enabled' => '1',
        'public_results_recent_limit' => '10',
        'public_results_show_scores' => '1',
        'public_results_show_match_count' => '1',
        'public_results_show_report_links' => '1',
        'public_results_allow_anonymous' => '0',
        'public_report_workspace_enabled' => '1',
        'public_report_download_enabled' => '1',
        'public_report_show_raw_score' => '1',
        'public_report_show_source_names' => '1',
        'public_report_show_full_document' => '1',
        'public_report_default_mode' => 'workspace',
        'internet_check_enabled' => '1',
        'internet_check_provider' => 'ai',
        'internet_check_api_key_env' => 'AISS_INTERNET_API_KEY',
        'internet_check_api_key' => '',
        'internet_search_backend' => 'serpapi',
        'internet_check_max_queries' => '3',
        'internet_check_max_sources' => '5',
        'internet_check_max_chars_per_source' => '12000',
        'internet_check_timeout' => '15',
        'internet_check_payload_policy' => 'snippets_only',
        'internet_check_auto_run_when_no_sources' => '1',
        'internet_check_allow_full_document_query' => '1',
        'internet_check_store_retrieved_text' => '1',
        'internet_check_seed_urls' => '',
        'internet_check_disclosure_visible' => '1',
        'report_ai_narrative_enabled' => '1',
    ];

    $db = academic_similarity_db();
    $stmt = $db->prepare("SELECT setting_key, setting_value FROM ac_similarity_settings WHERE tenant_id = :tid");
    $stmt->execute([':tid' => $tenantId]);
    $stored = [];
    while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
        $stored[$row['setting_key']] = $row['setting_value'];
    }

    $settings = array_merge($defaults, $stored);

    // Detect stale defaults version — log once per request per tenant
    $storedVersion = (string)($stored['_defaults_version'] ?? '000');
    if ($storedVersion !== ACADEMIC_SIMILARITY_DEFAULTS_VERSION) {
        if (function_exists('write_log')) {
            write_log(
                "AISS settings for tenant {$tenantId} are stale (stored={$storedVersion}, code=" . ACADEMIC_SIMILARITY_DEFAULTS_VERSION . "). Run migration 008 or re-save settings to reconcile.",
                'warning',
                ['tenant_id' => $tenantId, 'stored_version' => $storedVersion, 'code_version' => ACADEMIC_SIMILARITY_DEFAULTS_VERSION]
            );
        }
    }
    $settings = academic_similarity_decrypt_sensitive_settings($settings);
    foreach ([
        'semantic_external_api_key_env' => ['secret' => 'semantic_external_api_key', 'default' => 'SEMANTIC_API_KEY'],
        'internet_check_api_key_env' => ['secret' => 'internet_check_api_key', 'default' => 'AISS_INTERNET_API_KEY'],
    ] as $envKey => $secretSpec) {
        $envValue = trim((string)($settings[$envKey] ?? ''));
        $secretKey = $secretSpec['secret'];
        if (academic_similarity_looks_like_secret($envValue)) {
            if (trim((string)($settings[$secretKey] ?? '')) === '') {
                $settings[$secretKey] = $envValue;
            }
            $settings[$envKey] = $secretSpec['default'];
        }
    }
    foreach (academic_similarity_sensitive_setting_keys() as $secretKey) {
        $value = trim((string)($settings[$secretKey] ?? ''));
        $settings[$secretKey . '_configured'] = $value !== '' ? '1' : '0';
        $settings[$secretKey . '_masked'] = $value !== '' ? ('***' . substr($value, -4)) : '';
    }

    return $settings;
}

function academic_similarity_save_settings(string $tenantId, array $input): void
{
    $allowed = [
        'enabled', 'exact_match_enabled', 'near_match_enabled', 'semantic_match_enabled',
        'semantic_provider', 'semantic_model_name', 'semantic_service_endpoint',
        'semantic_service_token_env', 'semantic_external_api_key_env', 'semantic_external_api_key',
        'semantic_similarity_threshold', 'semantic_report_threshold', 'semantic_max_segments',
        'semantic_payload_policy', 'semantic_health_visible',
        'cms_public_submission_enabled', 'cms_submission_shortcode', 'cms_builder_block_enabled',
        'cms_default_submission_title', 'similarity_threshold', 'min_match_length',
        'processing_batch_size', 'max_sources_per_comparison', 'report_include_highlights',
        'report_include_source_breakdown', 'auto_generate_reports', 'notify_on_completion',
        'min_word_count', 'max_word_count', 'max_file_size_mb', 'max_upload_size',
        'allowed_file_types', 'fingerprint_shingle_size', 'near_match_threshold',
        'retention_days', 'allowed_extensions',
        'public_results_enabled', 'public_results_recent_limit',
        'public_results_show_scores', 'public_results_show_match_count',
        'public_results_show_report_links', 'public_results_allow_anonymous',
        'public_report_workspace_enabled', 'public_report_download_enabled',
        'public_report_show_raw_score', 'public_report_show_source_names',
        'public_report_show_full_document', 'public_report_default_mode',
        'internet_check_enabled', 'internet_check_provider', 'internet_check_api_key_env',
        'internet_check_api_key', 'internet_search_backend',
        'internet_check_max_queries', 'internet_check_max_sources',
        'internet_check_max_chars_per_source', 'internet_check_timeout',
        'internet_check_payload_policy',
        'internet_check_auto_run_when_no_sources', 'internet_check_allow_full_document_query',
        'internet_check_store_retrieved_text', 'internet_check_seed_urls',
        'internet_check_disclosure_visible',
        'report_ai_narrative_enabled',
        '_defaults_version',
    ];

    $existing = academic_similarity_get_raw_settings($tenantId);
    foreach ([
        'semantic_external_api_key_env' => ['secret' => 'semantic_external_api_key', 'default' => 'SEMANTIC_API_KEY'],
        'internet_check_api_key_env' => ['secret' => 'internet_check_api_key', 'default' => 'AISS_INTERNET_API_KEY'],
    ] as $envKey => $secretSpec) {
        $envValue = trim((string)($input[$envKey] ?? ''));
        if (academic_similarity_looks_like_secret($envValue)) {
            if (trim((string)($input[$secretSpec['secret']] ?? '')) === '') {
                $input[$secretSpec['secret']] = $envValue;
            }
            $input[$envKey] = $secretSpec['default'];
        }
    }
    $maskedSentinel = '***MASKED***';
    foreach (academic_similarity_sensitive_setting_keys() as $secretKey) {
        if (array_key_exists($secretKey, $input)) {
            $secret = trim((string)$input[$secretKey]);
            // Backward compat: also check legacy '***' prefix
            if ($secret === '' || $secret === $maskedSentinel || str_starts_with($secret, '***')) {
                unset($input[$secretKey]);
            }
        }
    }
    $input = academic_similarity_encrypt_sensitive_settings($input);

    $db = academic_similarity_db();
    $stmt = $db->prepare("
        INSERT INTO ac_similarity_settings (tenant_id, setting_key, setting_value, updated_at)
        VALUES (:tid, :key, :val, NOW())
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()
    ");

    foreach ($input as $key => $value) {
        if (!in_array($key, $allowed, true)) {
            continue;
        }
        if (in_array($key, academic_similarity_sensitive_setting_keys(), true) && (string)$value === '') {
            $value = (string)($existing[$key] ?? '');
        }
        $stmt->execute([':tid' => $tenantId, ':key' => $key, ':val' => (string)$value]);
    }

    academic_similarity_sync_semantic_service_module($input);
}

function academic_similarity_sync_semantic_service_module(array $settings): void
{
    if (($settings['semantic_match_enabled'] ?? null) !== '1') {
        return;
    }
    if (!function_exists('moduleTenantSettingsTenantId') || !function_exists('enableModuleForTenant')) {
        return;
    }

    $tenantId = moduleTenantSettingsTenantId();
    if ($tenantId === null || $tenantId <= 0) {
        return;
    }

    enableModuleForTenant('academic-similarity-semantic-service', (int)$tenantId);
}

function academic_similarity_looks_like_secret(string $value): bool
{
    $value = trim($value);
    if ($value === '') {
        return false;
    }
    if (preg_match('/^[A-Z][A-Z0-9_]*$/', $value) === 1) {
        return false;
    }
    if (preg_match('/^(gsk_|sk-|sk_|xai-|AIza|ya29\\.)/i', $value) === 1) {
        return true;
    }
    return strlen($value) >= 32 && preg_match('/[a-z]/', $value) === 1 && preg_match('/[A-Z]/', $value) === 1 && preg_match('/[0-9]/', $value) === 1;
}

function academic_similarity_get_raw_settings(string $tenantId): array
{
    $db = academic_similarity_db();
    $stmt = $db->prepare("SELECT setting_key, setting_value FROM ac_similarity_settings WHERE tenant_id = :tid");
    $stmt->execute([':tid' => $tenantId]);
    $settings = [];
    while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
        $settings[(string)$row['setting_key']] = (string)$row['setting_value'];
    }
    return $settings;
}

function academic_similarity_sensitive_setting_keys(): array
{
    return [
        'semantic_external_api_key',
        'internet_check_api_key',
    ];
}

function academic_similarity_encrypt_sensitive_settings(array $settings): array
{
    foreach (academic_similarity_sensitive_setting_keys() as $key) {
        if (!isset($settings[$key]) || !is_string($settings[$key]) || trim($settings[$key]) === '') {
            continue;
        }
        $value = trim($settings[$key]);
        $envelope = json_decode($value, true);
        if (is_array($envelope) && isset($envelope['ciphertext'], $envelope['iv'], $envelope['tag'])) {
            continue;
        }
        try {
            $settings[$key] = json_encode((new \Ikabud\Kernel\Crypto())->encryptString($value), JSON_UNESCAPED_SLASHES);
        } catch (\Throwable $e) {
            if (function_exists('write_log')) {
                write_log('AISS failed to encrypt sensitive setting', 'error', [
                    'key' => $key,
                    'error' => $e->getMessage(),
                ]);
            }
            $settings[$key] = '';
        }
    }
    return $settings;
}

function academic_similarity_decrypt_sensitive_settings(array $settings): array
{
    foreach (academic_similarity_sensitive_setting_keys() as $key) {
        $value = (string)($settings[$key] ?? '');
        if ($value === '' || $value[0] !== '{') {
            continue;
        }
        $envelope = json_decode($value, true);
        if (!is_array($envelope) || !isset($envelope['ciphertext'], $envelope['iv'], $envelope['tag'])) {
            continue;
        }
        try {
            $settings[$key] = (new \Ikabud\Kernel\Crypto())->decryptString(
                (string)$envelope['ciphertext'],
                (string)$envelope['iv'],
                (string)$envelope['tag'],
                isset($envelope['key_id']) ? (string)$envelope['key_id'] : null
            );
        } catch (\Throwable $e) {
            if (function_exists('write_log')) {
                write_log('AISS failed to decrypt sensitive setting', 'warning', [
                    'key' => $key,
                    'error' => $e->getMessage(),
                ]);
            }
            $settings[$key] = '';
        }
    }
    return $settings;
}

// ── Dashboard stats ──────────────────────────────────────────────
function academic_similarity_dashboard_stats(string $tenantId): array
{
    $db = academic_similarity_db();
    $stats = [
        'total_submissions' => 0,
        'pending_submissions' => 0,
        'processed_submissions' => 0,
        'total_sources' => 0,
        'total_matches' => 0,
        'recent_submissions' => [],
    ];

    try {
        $stmt = $db->prepare("SELECT COUNT(*) FROM ac_similarity_submissions WHERE tenant_id = :tid");
        $stmt->execute([':tid' => $tenantId]);
        $stats['total_submissions'] = (int)$stmt->fetchColumn();

        $stmt = $db->prepare("SELECT COUNT(*) FROM ac_similarity_submissions WHERE tenant_id = :tid AND status = 'pending'");
        $stmt->execute([':tid' => $tenantId]);
        $stats['pending_submissions'] = (int)$stmt->fetchColumn();

        $stmt = $db->prepare("SELECT COUNT(*) FROM ac_similarity_submissions WHERE tenant_id = :tid AND status = 'processed'");
        $stmt->execute([':tid' => $tenantId]);
        $stats['processed_submissions'] = (int)$stmt->fetchColumn();

        $stmt = $db->prepare("SELECT COUNT(*) FROM ac_similarity_sources WHERE tenant_id = :tid");
        $stmt->execute([':tid' => $tenantId]);
        $stats['total_sources'] = (int)$stmt->fetchColumn();

        $stmt = $db->prepare("SELECT COUNT(*) FROM ac_similarity_matches m JOIN ac_similarity_submissions s ON m.submission_id = s.id WHERE s.tenant_id = :tid");
        $stmt->execute([':tid' => $tenantId]);
        $stats['total_matches'] = (int)$stmt->fetchColumn();

        $stmt = $db->prepare("SELECT id AS submission_id, submission_title, status, word_count, created_at FROM ac_similarity_submissions WHERE tenant_id = :tid ORDER BY created_at DESC LIMIT 10");
        $stmt->execute([':tid' => $tenantId]);
        $stats['recent_submissions'] = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        write_log('Dashboard stats query failed: ' . $e->getMessage());
    }

    return $stats;
}

// ── Private storage path ─────────────────────────────────────────
function academic_similarity_storage_path(string $subpath = ''): string
{
    $base = __DIR__ . '/../../storage/academic_similarity';
    if (!is_dir($base)) {
        @mkdir($base, 0750, true);
    }
    if ($subpath === '') {
        return $base;
    }
    $dir = dirname($base . '/' . $subpath);
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    return $base . '/' . ltrim($subpath, '/');
}

// ── Capability handlers ──────────────────────────────────────────
function academic_similarity_capability_handlers(): array
{
    return [
        'academic_similarity.submit@1'       => 'ac_sim_cap_submit_1',
        'academic_similarity.check@1'        => 'ac_sim_cap_check_1',
        'academic_similarity.match.exact@1'  => 'ac_sim_cap_match_exact_1',
        'academic_similarity.match.near@1'   => 'ac_sim_cap_match_near_1',
        'academic_similarity.report.view@1'  => 'ac_sim_cap_report_view_1',
        'academic_similarity.review.exclude@1' => 'ac_sim_cap_review_exclude_1',
        'academic_similarity.semantic.compare@1' => 'ac_sim_cap_semantic_compare_1',
        'academic_similarity.internet.discover@1' => 'ac_sim_cap_internet_discover_1',
        'academic_similarity.context.analyze@1'  => 'ac_sim_cap_context_analyze_1',
        'academic_similarity.citation.analyze@1' => 'ac_sim_cap_citation_analyze_1',
        'academic_similarity.scholarship.profile@1' => 'ac_sim_cap_scholarship_profile_1',
        'academic_similarity.lineage.graph@1' => 'ac_sim_cap_lineage_graph_1',
        'academic_similarity.review.workflow.action@1' => 'ac_sim_cap_review_workflow_action_1',
    ];
}

function ac_sim_cap_submit_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    if (!is_array($payload)) {
        return ['ok' => false, 'error' => 'Invalid payload'];
    }
    $tenantId = $payload['_tenant_id'] ?? app()->tenant()->current() ?? '';
    $service = new \AcademicSimilaritySubmissionService($tenantId);
    return $service->create($payload);
}

function ac_sim_cap_check_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    if (!is_array($payload) || empty($payload['submission_id'])) {
        return ['ok' => false, 'error' => 'submission_id required'];
    }
    $tenantId = $payload['_tenant_id'] ?? app()->tenant()->current() ?? '';
    $pipeline = new \AcademicSimilarityPipelineService($tenantId);
    return $pipeline->processSubmission((int)$payload['submission_id']);
}

function ac_sim_cap_match_exact_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    if (!is_array($payload) || empty($payload['submission_id'])) {
        return ['ok' => false, 'error' => 'submission_id required'];
    }
    $tenantId = $payload['_tenant_id'] ?? app()->tenant()->current() ?? '';
    $matching = new \AcademicSimilarityMatchingService($tenantId);
    return $matching->runExactMatching((int)$payload['submission_id']);
}

function ac_sim_cap_match_near_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    if (!is_array($payload) || empty($payload['submission_id'])) {
        return ['ok' => false, 'error' => 'submission_id required'];
    }
    $tenantId = $payload['_tenant_id'] ?? app()->tenant()->current() ?? '';
    $matching = new \AcademicSimilarityMatchingService($tenantId);
    return $matching->runNearExactMatching((int)$payload['submission_id']);
}

function ac_sim_cap_report_view_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    if (!is_array($payload) || empty($payload['submission_id'])) {
        return ['ok' => false, 'error' => 'submission_id required'];
    }
    $tenantId = $payload['_tenant_id'] ?? app()->tenant()->current() ?? '';
    $reportService = new \AcademicSimilarityReportService($tenantId);
    return $reportService->get((int)$payload['submission_id']);
}

function ac_sim_cap_review_exclude_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    if (!is_array($payload) || empty($payload['match_id'])) {
        return ['ok' => false, 'error' => 'match_id required'];
    }
    $tenantId = $payload['_tenant_id'] ?? app()->tenant()->current() ?? '';
    $service = new \AcademicSimilarityReviewService($tenantId);
    return $service->excludeMatch(
        (int)$payload['match_id'],
        $payload['reason'] ?? '',
        $payload['note'] ?? ''
    );
}

function ac_sim_cap_semantic_compare_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    if (!is_array($payload)) {
        return ['ok' => false, 'error' => 'Invalid payload'];
    }

    // This handler is a fallback registered by the academic-similarity module
    // at priority 50. When the academic-similarity-semantic-service service-module
    // is enabled, its ServiceProxy registers at priority 100 and handles the
    // actual HTTP call to the Python service.
    //
    // If this handler is reached (service module disabled), semantic matching
    // is not available — return a clear error instead of silent false success.
    return ['ok' => false, 'error' => 'Semantic comparison service is not available. Enable the academic-similarity-semantic-service module.'];
}
function ac_sim_cap_citation_analyze_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    if (!is_array($payload) || empty($payload['submission_passage'])) {
        return ['ok' => false, 'error' => 'submission_passage is required'];
    }
    $tenantId = $payload['_tenant_id'] ?? app()->tenant()->current() ?? '';
    $service = new AcademicSimilarityCitationAnalysisService($tenantId);
    return $service->analyzePassage(
        $payload['submission_passage'],
        $payload['bibliography_text'] ?? null
    );
}

function ac_sim_cap_scholarship_profile_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    if (!is_array($payload) || empty($payload['submission_id'])) {
        return ['ok' => false, 'error' => 'submission_id is required'];
    }
    $tenantId = $payload['_tenant_id'] ?? app()->tenant()->current() ?? '';
    $service = new AcademicSimilarityScholarshipProfileService($tenantId);
    return $service->generateProfile((int)$payload['submission_id']);
}

function ac_sim_cap_lineage_graph_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    if (!is_array($payload) || empty($payload['submission_id'])) {
        return ['ok' => false, 'error' => 'submission_id is required'];
    }
    $tenantId = $payload['_tenant_id'] ?? app()->tenant()->current() ?? '';
    $service = new AcademicSimilarityKnowledgeLineageService($tenantId);
    $format = $payload['format'] ?? 'json';
    if ($format === 'mermaid') {
        return ['ok' => true, 'mermaid' => $service->renderMermaid((int)$payload['submission_id'])];
    }
    return $service->buildGraph((int)$payload['submission_id']);
}

function ac_sim_cap_review_workflow_action_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    if (!is_array($payload) || empty($payload['match_id']) || empty($payload['action'])) {
        return ['ok' => false, 'error' => 'match_id and action are required'];
    }
    $tenantId = $payload['_tenant_id'] ?? app()->tenant()->current() ?? '';
    $userId = (int)($payload['user_id'] ?? 0);
    $service = new AcademicSimilarityReviewWorkflowService($tenantId);
    return $service->performAction(
        (int)$payload['match_id'],
        $payload['action'],
        $userId,
        $payload['reason'] ?? '',
        $payload['classification'] ?? null
    );
}

function ac_sim_cap_internet_discover_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    if (!is_array($payload)) {
        return ['ok' => false, 'candidates' => [], 'error' => 'Invalid payload'];
    }
    return [
        'ok' => true,
        'candidates' => [],
        'disclosure' => 'No search provider configured. Add seed URLs in Settings → Internet Check, or install a search provider module.',
    ];
}

// ── CMS Admin Nav Injection ──────────────────────────────────────

app()->hooks()->on('cms.admin.nav_items', function (array $items): array {
    $baseUrl = rtrim((string)(defined('BASE_URL') ? BASE_URL : ''), '/');

    $items[] = [
        'label'    => 'Similarity',
        'section'  => true,
        'children' => [
            ['label' => 'Dashboard',  'url' => $baseUrl . '/admin/academic-similarity',              'icon' => '🔍', 'active_key' => 'ac_sim_dashboard'],
            ['label' => 'Submissions', 'url' => $baseUrl . '/admin/academic-similarity/submissions',   'icon' => '📄', 'active_key' => 'ac_sim_submissions'],
            ['label' => 'Sources',     'url' => $baseUrl . '/admin/academic-similarity/sources',      'icon' => '📚', 'active_key' => 'ac_sim_sources'],
            ['label' => 'Collections', 'url' => $baseUrl . '/admin/academic-similarity/collections',  'icon' => '📁', 'active_key' => 'ac_sim_collections'],
            ['label' => 'Reports',     'url' => $baseUrl . '/admin/academic-similarity/reports',      'icon' => '📊', 'active_key' => 'ac_sim_reports'],
            ['label' => 'Configuration', 'url' => $baseUrl . '/admin/academic-similarity/settings',   'icon' => '⚙️', 'active_key' => 'ac_sim_settings'],
            ['label' => 'Semantic Matching', 'url' => $baseUrl . '/admin/academic-similarity/settings/semantic', 'icon' => '🧠', 'active_key' => 'ac_sim_semantic'],
            ['label' => 'CMS Flow',     'url' => $baseUrl . '/admin/academic-similarity/settings/cms', 'icon' => '🧩', 'active_key' => 'ac_sim_cms'],
        ],
    ];

    return $items;
}, priority: 20);

// ── Public submission form renderer ─────────────────────────────
function academic_similarity_render_submission_form(array $options = []): string
{
    $settings = [];
    try {
        $settings = academic_similarity_get_settings((string)(app()->tenant()->current() ?? ''));
    } catch (\Throwable $e) {
        $settings = [];
    }
    if (($settings['cms_public_submission_enabled'] ?? '1') !== '1') {
        return '';
    }

    $title = $options['title'] ?? ($settings['cms_default_submission_title'] ?? 'Submit Document for Similarity Check');
    $showResults = ($settings['public_results_enabled'] ?? '1') === '1';
    $submitterUserId = \AcademicSimilarityUserResultService::getCurrentUserId();
    $isLoggedIn = $submitterUserId > 0;

    $html = '<div class="ac-sim-public-wrap" style="max-width:640px;margin:2rem auto">';

    // ── User Stats Panel (logged-in users only) ──
    if ($isLoggedIn && $showResults) {
        $resultService = new \AcademicSimilarityUserResultService((string)(app()->tenant()->current() ?? ''));
        try {
            $stats = $resultService->getSummaryStats($submitterUserId);
        } catch (\Throwable $e) {
            $stats = [];
        }
        $showScores = ($settings['public_results_show_scores'] ?? '1') === '1';
        $statCards = [];

        if (!empty($stats)) {
            $statCards[] = ['label' => 'Total', 'value' => (int)($stats['total_submissions'] ?? 0), 'color' => '#374151'];
            $statCards[] = ['label' => 'Processed', 'value' => (int)($stats['processed_count'] ?? 0), 'color' => '#059669'];
            $statCards[] = ['label' => 'Pending', 'value' => (int)($stats['pending_count'] ?? 0), 'color' => '#d97706'];
            $statCards[] = ['label' => 'Failed', 'value' => (int)($stats['failed_count'] ?? 0), 'color' => '#dc2626'];
            if ($showScores) {
                $statCards[] = ['label' => 'Avg Score', 'value' => number_format((float)($stats['avg_adjusted_score'] ?? 0), 1) . '%', 'color' => '#7c3aed'];
                $statCards[] = ['label' => 'Highest', 'value' => number_format((float)($stats['highest_adjusted_score'] ?? 0), 1) . '%', 'color' => '#2563eb'];
            }
        }

        if ($statCards !== []) {
            $html .= '<div id="ac-sim-stats" class="ac-sim-stats">';
            $html .= '<h4 style="font-size:0.95rem;font-weight:700;margin-bottom:0.75rem;color:#111827">Your Submission History</h4>';
            $html .= '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(90px,1fr));gap:8px;margin-bottom:1rem">';
            foreach ($statCards as $card) {
                $html .= '<div style="text-align:center;background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:8px 6px">';
                $html .= '<div style="font-size:1.25rem;font-weight:700;color:' . htmlspecialchars($card['color']) . '">' . htmlspecialchars((string)$card['value']) . '</div>';
                $html .= '<div style="font-size:0.65rem;text-transform:uppercase;color:#6b7280;letter-spacing:0.02em">' . htmlspecialchars($card['label']) . '</div>';
                $html .= '</div>';
            }
            $html .= '</div></div>';
        }

        // ── Recent Submissions Table ──
        $recentLimit = max(1, min(50, (int)($settings['public_results_recent_limit'] ?? 10)));
        $showMatchCount = ($settings['public_results_show_match_count'] ?? '1') === '1';
        $showReportLinks = ($settings['public_results_show_report_links'] ?? '1') === '1';
        try {
            $recentSubmissions = $resultService->getRecentSubmissions($submitterUserId, $recentLimit);
        } catch (\Throwable $e) {
            $recentSubmissions = [];
        }

        if ($recentSubmissions !== []) {
            $html .= '<div id="ac-sim-recent" style="margin-bottom:1.5rem;border:1px solid #e5e7eb;border-radius:10px;overflow:hidden">';
            $html .= '<div style="padding:10px 14px;background:#f9fafb;border-bottom:1px solid #e5e7eb;font-size:0.85rem;font-weight:600;color:#374151">Recent Submissions</div>';
            $html .= '<div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse;font-size:0.8rem">';
            $html .= '<thead><tr style="background:#f3f4f6">';
            $html .= '<th style="padding:8px 10px;text-align:left;font-weight:600;color:#6b7280">Title</th>';
            $html .= '<th style="padding:8px 10px;text-align:left;font-weight:600;color:#6b7280">Status</th>';
            if ($showScores) $html .= '<th style="padding:8px 10px;text-align:left;font-weight:600;color:#6b7280">Score</th>';
            if ($showMatchCount) $html .= '<th style="padding:8px 10px;text-align:left;font-weight:600;color:#6b7280">Matches</th>';
            $html .= '<th style="padding:8px 10px;text-align:left;font-weight:600;color:#6b7280">Date</th>';
            if ($showReportLinks) $html .= '<th style="padding:8px 10px;text-align:left;font-weight:600;color:#6b7280">Report</th>';
            $html .= '</tr></thead><tbody>';

            foreach ($recentSubmissions as $row) {
                $status = $row['status'] ?? 'pending';
                $statusColor = match ($status) {
                    'processed' => '#059669',
                    'processing' => '#d97706',
                    'failed' => '#dc2626',
                    default => '#6b7280',
                };
                $statusBg = match ($status) {
                    'processed' => '#ecfdf5',
                    'processing' => '#fffbeb',
                    'failed' => '#fef2f2',
                    default => '#f3f4f6',
                };
                $html .= '<tr style="border-top:1px solid #f3f4f6">';
                $html .= '<td style="padding:8px 10px;font-weight:500;color:#111827">' . htmlspecialchars(mb_substr($row['submission_title'] ?? '', 0, 40)) . '</td>';
                $html .= '<td style="padding:8px 10px"><span style="display:inline-block;padding:2px 8px;border-radius:999px;font-size:0.7rem;font-weight:600;background:' . $statusBg . ';color:' . $statusColor . '">' . htmlspecialchars($status) . '</span></td>';
                if ($showScores) {
                    $score = $row['adjusted_similarity_score'] !== null ? $row['adjusted_similarity_score'] : ($row['raw_similarity_score'] !== null ? $row['raw_similarity_score'] : null);
                    $val = $score !== null ? number_format((float)$score, 1) . '%' : html_entity_decode('&mdash;', ENT_QUOTES, 'UTF-8');
                    $html .= '<td style="padding:8px 10px;font-weight:600;color:#374151">' . htmlspecialchars((string)$val) . '</td>';
                }
                if ($showMatchCount) {
                    $html .= '<td style="padding:8px 10px;color:#6b7280">' . ((int)($row['matched_word_count'] ?? 0)) . '</td>';
                }
                $html .= '<td style="padding:8px 10px;color:#6b7280;white-space:nowrap">' . htmlspecialchars(mb_substr($row['submitted_at'] ?? '', 0, 10)) . '</td>';
                if ($showReportLinks && !empty($row['report_id'])) {
                    $html .= '<td style="padding:8px 10px"><a href="#" onclick="acSimViewReport(' . (int)$row['report_id'] . ',' . (int)$row['id'] . ');return false" style="color:#2563eb;font-weight:500;text-decoration:none">View</a></td>';
                } elseif ($showReportLinks) {
                    $html .= '<td style="padding:8px 10px;color:#d1d5db">' . html_entity_decode('&mdash;', ENT_QUOTES, 'UTF-8') . '</td>';
                }
                $html .= '</tr>';
            }
            $html .= '</tbody></table></div></div>';
        }
    }

    // ── Form ──
    if ($title) {
        $html .= '<h3 style="font-size:1.25rem;font-weight:700;margin-bottom:1rem">' . htmlspecialchars($title) . '</h3>';
    }

    $csrf = app()->csrfToken() ?? '';
    $html .= '<form id="ac-sim-form" class="space-y-4" onsubmit="return acSimPublicSubmit(event)" style="display:flex;flex-direction:column;gap:1rem">';
    $html .= '<input type="hidden" name="_token" value="' . htmlspecialchars($csrf) . '" />';
    $html .= '<input type="hidden" name="source_type" value="pasted" />';

    $html .= '<div><label style="display:block;font-weight:600;font-size:0.9rem;margin-bottom:0.25rem">Document Title *</label>';
    $html .= '<input type="text" name="submission_title" required style="width:100%;padding:0.6rem 0.75rem;border:1px solid #d1d5db;border-radius:0.5rem;font-size:0.9rem" placeholder="e.g. Research Paper" /></div>';

    $html .= '<div><label style="display:block;font-weight:600;font-size:0.9rem;margin-bottom:0.25rem">Your Name</label>';
    $html .= '<input type="text" name="author_name" style="width:100%;padding:0.6rem 0.75rem;border:1px solid #d1d5db;border-radius:0.5rem;font-size:0.9rem" placeholder="e.g. Juan Dela Cruz" /></div>';

    $html .= '<div><label style="font-weight:600;font-size:0.9rem;margin-bottom:0.25rem">Upload File</label>';
    $html .= '<input type="file" name="file" accept=".docx,.pdf,.txt" style="font-size:0.85rem" /></div>';

    $html .= '<div style="text-align:center;color:#9ca3af;font-size:0.8rem">' . html_entity_decode('&mdash;', ENT_QUOTES, 'UTF-8') . ' or ' . html_entity_decode('&mdash;', ENT_QUOTES, 'UTF-8') . '</div>';

    $html .= '<div><label style="display:block;font-weight:600;font-size:0.9rem;margin-bottom:0.25rem">Paste Text</label>';
    $html .= '<textarea name="pasted_text" rows="6" style="width:100%;padding:0.6rem 0.75rem;border:1px solid #d1d5db;border-radius:0.5rem;font-size:0.9rem" placeholder="Paste your document content..."></textarea></div>';

    $html .= '<button type="submit" style="width:100%;padding:0.75rem;background:#2563eb;color:#fff;font-weight:600;border:0;border-radius:0.5rem;cursor:pointer">Check Similarity</button>';
    $html .= '</form>';
    $html .= '<div id="ac-sim-result" style="margin-top:1rem;display:none"></div>';

    // ── JS: submission + results refresh ──
    $jsVars = "var acSimIsLoggedIn = " . ($isLoggedIn ? "true" : "false") . ";\n"
        . "var acSimShowResults = " . ($showResults ? "true" : "false") . ";\n"
        . "var acSimPollInterval = null;\n";

    $jsFuncs = <<< 'JSBLOCK'
function acSimPublicSubmit(e){e.preventDefault();var f=document.getElementById("ac-sim-form"),fd=new FormData(f),r=document.getElementById("ac-sim-result"),file=fd.get("file"),text=(fd.get("pasted_text")||"").toString().trim();fd.set("source_type",file&&file.name?"upload":"pasted");if(!file.name&&!text){r.style.display="block";r.style.color="#dc2626";r.innerHTML="Error: paste text or upload a file";return false}fd.set("submission_title",fd.get("submission_title")||"Untitled"),r.style.display="block",r.innerHTML="Submitting...",fetch("/api/v1/academic-similarity/public/submit",{method:"POST",body:fd}).then(function(x){return x.json()}).then(function(d){r.className="";if(d.ok){r.style.color="#059669";r.innerHTML="<strong>Submitted!</strong> Your document is being processed. Results will appear here when ready.";f.reset();if(acSimIsLoggedIn&&acSimShowResults){acSimRefreshResults();acSimStartPolling()}}else{r.style.color="#dc2626";r.innerHTML="Error: "+(d.error||"Unknown")}}).catch(function(e){r.style.color="#dc2626";r.innerHTML="Network error: "+e.message});return false}

function acSimRefreshResults(){if(!acSimIsLoggedIn||!acSimShowResults)return;fetch("/api/v1/academic-similarity/public/results").then(function(x){return x.json()}).then(function(d){if(d.ok&&d.stats){var s=d.stats;var cards="";cards+=acSimStatCard("Total",s.total_submissions,"#374151");cards+=acSimStatCard("Processed",s.processed_count,"#059669");cards+=acSimStatCard("Pending",s.pending_count,"#d97706");cards+=acSimStatCard("Failed",s.failed_count,"#dc2626");if(d.show_scores){cards+=acSimStatCard("Avg Score",(s.avg_adjusted_score||0)+"%","#7c3aed");cards+=acSimStatCard("Highest",(s.highest_adjusted_score||0)+"%","#2563eb")}var el=document.getElementById("ac-sim-stats");if(el)el.innerHTML="<h4 style=\"font-size:0.95rem;font-weight:700;margin-bottom:0.75rem;color:#111827\">Your Submission History</h4><div style=\"display:grid;grid-template-columns:repeat(auto-fill,minmax(90px,1fr));gap:8px;margin-bottom:1rem\">"+cards+"</div>";acSimBuildRecentTable(d)}}).catch(function(){})}

function acSimStatCard(l,v,c){return "<div style=\"text-align:center;background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:8px 6px\"><div style=\"font-size:1.25rem;font-weight:700;color:"+c+"\">"+v+"</div><div style=\"font-size:0.65rem;text-transform:uppercase;color:#6b7280;letter-spacing:0.02em\">"+l+"</div></div>"}

function acSimBuildRecentTable(d){var el=document.getElementById("ac-sim-recent");if(!el)return;var rows="";for(var i=0;i<d.recent.length;i++){var r=d.recent[i];var sc=r.status==="processed"?"#059669":r.status==="processing"?"#d97706":r.status==="failed"?"#dc2626":"#6b7280";var sb=r.status==="processed"?"#ecfdf5":r.status==="processing"?"#fffbeb":r.status==="failed"?"#fef2f2":"#f3f4f6";rows+="<tr style=\"border-top:1px solid #f3f4f6\">";rows+="<td style=\"padding:8px 10px;font-weight:500;color:#111827\">"+(r.submission_title||"").substring(0,40)+"</td>";rows+="<td style=\"padding:8px 10px\"><span style=\"display:inline-block;padding:2px 8px;border-radius:999px;font-size:0.7rem;font-weight:600;background:"+sb+";color:"+sc+"\">"+(r.status||"pending")+"</span></td>";if(d.show_scores){var sv=r.adjusted_similarity_score!==null?r.adjusted_similarity_score:r.raw_similarity_score;rows+="<td style=\"padding:8px 10px;font-weight:600;color:#374151\">"+(sv!==null?Number(sv).toFixed(1)+"%":"\u2014")+"</td>"}if(d.show_match_count){rows+="<td style=\"padding:8px 10px;color:#6b7280\">"+(r.matched_word_count||0)+"</td>"}rows+="<td style=\"padding:8px 10px;color:#6b7280;white-space:nowrap\">"+((r.submitted_at||"").substring(0,10))+"</td>";if(d.show_report_links&&r.report_id){rows+="<td style=\"padding:8px 10px\"><a href=\"#\" onclick=\"acSimViewReport("+r.report_id+","+r.id+");return false\" style=\"color:#2563eb;font-weight:500;text-decoration:none\">View</a></td>"}else if(d.show_report_links){rows+="<td style=\"padding:8px 10px;color:#d1d5db\">\u2014</td>"}rows+="</tr>"}el.innerHTML="<div style=\"padding:10px 14px;background:#f9fafb;border-bottom:1px solid #e5e7eb;font-size:0.85rem;font-weight:600;color:#374151\">Recent Submissions</div><div style=\"overflow-x:auto\"><table style=\"width:100%;border-collapse:collapse;font-size:0.8rem\"><thead><tr style=\"background:#f3f4f6\"><th style=\"padding:8px 10px;text-align:left;font-weight:600;color:#6b7280\">Title</th><th style=\"padding:8px 10px;text-align:left;font-weight:600;color:#6b7280\">Status</th>"+(d.show_scores?"<th style=\"padding:8px 10px;text-align:left;font-weight:600;color:#6b7280\">Score</th>":"")+(d.show_match_count?"<th style=\"padding:8px 10px;text-align:left;font-weight:600;color:#6b7280\">Matches</th>":"")+"<th style=\"padding:8px 10px;text-align:left;font-weight:600;color:#6b7280\">Date</th>"+(d.show_report_links?"<th style=\"padding:8px 10px;text-align:left;font-weight:600;color:#6b7280\">Report</th>":"")+"</tr></thead><tbody>"+rows+"</tbody></table></div>"}

function acSimStartPolling(){if(acSimPollInterval)clearInterval(acSimPollInterval);acSimPollInterval=setInterval(function(){acSimRefreshResults()},10000)}

function acSimViewReport(reportId,submissionId){fetch("/api/v1/academic-similarity/public/reports/"+submissionId).then(function(x){return x.json()}).then(function(d){if(d.ok){var r=document.getElementById("ac-sim-result");var out="<div style=\"background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:12px;font-size:0.85rem\">";out+="<h4 style=\"font-weight:700;font-size:1rem;margin:0 0 8px\">Report Summary</h4>";out+="<p><strong>Title:</strong> "+(d.submission.submission_title||"")+"</p>";out+="<p><strong>Status:</strong> "+(d.submission.status||"")+"</p>";if(d.submission.adjusted_similarity_score!==null){out+="<p><strong>Similarity Score:</strong> "+Number(d.submission.adjusted_similarity_score).toFixed(1)+"%</p>"}if(d.report){out+="<p><strong>Total Matches:</strong> "+(d.report.total_matches||0)+"</p>";out+="<p><strong>Generated:</strong> "+(d.report.generated_at||"")+"</p>"}out+="</div>";r.className="mt-6 p-4 rounded-lg border text-sm bg-gray-50 border-gray-200 text-gray-700";r.innerHTML=out;r.classList.remove("hidden");r.scrollIntoView({behavior:"smooth"})}}).catch(function(){})}
JSBLOCK;

    $html .= '<script>' . "\n" . $jsVars . $jsFuncs . "\n" . '</script>';
    $html .= '</div>';
    return $html;
}

// ── Shortcode: [academic_similarity_submission] ─────────────────
if (function_exists('app') && app() && method_exists(app(), 'hooks')) {
    app()->hooks()->on('cms.public.render_content', function (string $html, array $content = []): string {
        try {
            $settings = academic_similarity_get_settings((string)(app()->tenant()->current() ?? ''));
        } catch (\Throwable $e) {
            $settings = [];
        }
        $shortcode = trim((string)($settings['cms_submission_shortcode'] ?? 'academic_similarity_submission'));
        if ($shortcode === '') {
            $shortcode = 'academic_similarity_submission';
        }
        $pattern = '/\[' . preg_quote($shortcode, '/') . '([^\]]*)\]/i';
        return preg_replace_callback($pattern, static function (array $matches) use ($settings): string {
            $attrs = $matches[1] ?? '';
            $title = '';
            $mode = '';
            $showForm = '';
            $showHistory = '';
            $showReportViewer = '';

            if (preg_match('/title="([^"]*)"/i', $attrs, $m)) $title = $m[1];
            if (preg_match('/mode="([^"]*)"/i', $attrs, $m)) $mode = $m[1];
            if (preg_match('/show_form="([^"]*)"/i', $attrs, $m)) $showForm = $m[1];
            if (preg_match('/show_history="([^"]*)"/i', $attrs, $m)) $showHistory = $m[1];
            if (preg_match('/show_report_viewer="([^"]*)"/i', $attrs, $m)) $showReportViewer = $m[1];

            $workspaceEnabled = ($settings['public_report_workspace_enabled'] ?? '1') === '1';
            $isLoggedIn = \AcademicSimilarityUserResultService::getCurrentUserId() > 0;

            if ($mode === 'workspace' && $workspaceEnabled && $isLoggedIn) {
                return academic_similarity_render_submission_form([
                    'title' => $title,
                    'mode' => 'workspace',
                ]) . academic_similarity_render_workspace([
                    'show_form' => $showForm !== '0',
                    'show_history' => $showHistory !== '0',
                    'show_report_viewer' => $showReportViewer !== '0',
                ]);
            }

            return academic_similarity_render_submission_form(['title' => $title]);
        }, $html) ?? $html;
    }, 10);

    // ── Builder block type ─────────────────────────────────────
    app()->hooks()->on('cms.editor.block_types', function (array $blocks): array {
        try {
            $settings = academic_similarity_get_settings((string)(app()->tenant()->current() ?? ''));
        } catch (\Throwable $e) {
            $settings = [];
        }
        if (($settings['cms_builder_block_enabled'] ?? '1') === '1') {
            $blocks[] = [
                'type' => 'academic_similarity_submission',
                'label' => 'Similarity Check Form',
                'icon' => 'search',
                'fields' => [
                    ['key' => 'title', 'type' => 'text', 'label' => 'Form Title', 'default' => ($settings['cms_default_submission_title'] ?? 'Submit Document for Similarity Check')],
                ],
            ];
        }
        return $blocks;
    }, 10);

    // ── Builder block renderer ─────────────────────────────────
    app()->hooks()->on('cms.builder.renderers', function (array $map): array {
        $map['academic_similarity_submission'] = 'academic_similarity_render_submission_block';
        return $map;
    }, 10);
}

function academic_similarity_render_submission_block(array $props, array $style, array $attrs, string $children, array $node, array $context): string
{
    $title = (string)($props['title'] ?? '');
    return academic_similarity_render_submission_form(['title' => $title]);
}

/**
 * Render the public report workspace for logged-in users.
 */
function academic_similarity_render_workspace(array $options = []): string
{
    $showForm = $options['show_form'] ?? true;
    $showHistory = $options['show_history'] ?? true;
    $showReportViewer = $options['show_report_viewer'] ?? true;

    $html = '<div class="ac-sim-workspace-container" id="ac-sim-workspace-container">';

    // Hidden data for JS initialization
    $html .= '<script>
var acSimWorkspaceCfg = ' . json_encode([
        'show_form' => (bool)$showForm,
        'show_history' => (bool)$showHistory,
        'show_report_viewer' => (bool)$showReportViewer,
    ]) . ';
document.addEventListener("DOMContentLoaded", function () {
    if (typeof acSimInitWorkspace === "function") {
        acSimInitWorkspace();
    }
});
</script>';

    // Render the workspace template content via app()->render()
    try {
        $tenantId = (string)(app()->tenant()->current() ?? '');
        $settings = academic_similarity_get_settings($tenantId);
        $submitterUserId = \AcademicSimilarityUserResultService::getCurrentUserId();

        $html .= app()->render('modules/academic-similarity/public/workspace', [
            'show_form' => $showForm,
            'show_history' => $showHistory,
            'show_report_viewer' => $showReportViewer,
            'is_logged_in' => $submitterUserId > 0,
            'settings' => $settings,
        ]);
    } catch (\Throwable $e) {
        write_log('Failed to render workspace template: ' . $e->getMessage());
        $html .= '<div class="p-4 text-red-500 text-sm">Failed to load workspace. Please refresh the page.</div>';
    }

    // Highlight CSS
    $html .= '<style>
.hl-exact { background: #fecaca; border-bottom: 2px solid #dc2626; }
.hl-near { background: #fed7aa; border-bottom: 2px solid #ea580c; }
.hl-semantic { background: #fef08a; border-bottom: 2px solid #ca8a04; }
.hl-quote { background: #bfdbfe; border-bottom: 2px solid #2563eb; }
.hl-excluded { background: #e5e7eb; border-bottom: 2px solid #9ca3af; text-decoration: line-through; opacity: 0.6; }
.hl-stat { background: #ddd6fe; border-bottom: 2px solid #7c3aed; }
.hl-span { cursor: pointer; border-radius: 2px; padding: 0 1px; }
</style>';

    $html .= '</div>';
    return $html;
}
