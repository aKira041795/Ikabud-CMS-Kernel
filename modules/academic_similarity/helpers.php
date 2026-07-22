<?php
declare(strict_types=1);

/**
 * Academic Similarity — helpers, capability handlers, and bootstrap.
 */

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
        '/Services/AcademicSimilaritySubmissionService.php',
        '/Services/AcademicSimilarityPipelineService.php',
        '/Services/AcademicSimilaritySourceService.php',
        '/Services/AcademicSimilarityReportService.php',
        '/Services/AcademicSimilarityReviewService.php',
        '/Services/AcademicSimilarityNormalizationService.php',
        '/Services/AcademicSimilarityFingerprintService.php',
        '/Services/AcademicSimilarityMatchingService.php',
        '/Services/AcademicSimilarityScoringService.php',
        '/Services/AcademicSimilarityQuotaService.php',
        '/Services/AcademicSimilaritySemanticService.php',
        '/ValueObjects/AcademicSimilarityNormalizedText.php',
        '/ValueObjects/AcademicSimilaritySegment.php',
        '/ValueObjects/AcademicSimilarityFingerprint.php',
        '/ValueObjects/AcademicSimilarityMatchResult.php',
        '/Jobs/AcademicSimilarityProcessJob.php',
        '/Reports/AcademicSimilarityReportGenerator.php',
        '/Policies/AcademicSimilarityTenantPolicy.php',
        '/Policies/AcademicSimilarityQuotaPolicy.php',
        '/Validators/AcademicSimilarityFileValidator.php',
        '/Support/AcademicSimilarityTextExtractor.php',
        '/Support/AcademicSimilarityStorage.php',
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
        'semantic_match_enabled' => '0',
        'semantic_provider' => 'token_overlap',
        'semantic_model_name' => 'token_overlap',
        'semantic_service_endpoint' => 'http://127.0.0.1:9003',
        'semantic_service_token_env' => 'SEMANTIC_SERVICE_TOKEN',
        'semantic_external_api_key_env' => 'SEMANTIC_API_KEY',
        'semantic_similarity_threshold' => '0.70',
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
    ];

    $db = academic_similarity_db();
    $stmt = $db->prepare("SELECT setting_key, setting_value FROM ac_similarity_settings WHERE tenant_id = :tid");
    $stmt->execute([':tid' => $tenantId]);
    $stored = [];
    while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
        $stored[$row['setting_key']] = $row['setting_value'];
    }

    return array_merge($defaults, $stored);
}

function academic_similarity_save_settings(string $tenantId, array $input): void
{
    $allowed = [
        'enabled', 'exact_match_enabled', 'near_match_enabled', 'semantic_match_enabled',
        'semantic_provider', 'semantic_model_name', 'semantic_service_endpoint',
        'semantic_service_token_env', 'semantic_external_api_key_env',
        'semantic_similarity_threshold', 'semantic_max_segments',
        'semantic_payload_policy', 'semantic_health_visible',
        'cms_public_submission_enabled', 'cms_submission_shortcode', 'cms_builder_block_enabled',
        'cms_default_submission_title', 'similarity_threshold', 'min_match_length',
        'processing_batch_size', 'max_sources_per_comparison', 'report_include_highlights',
        'report_include_source_breakdown', 'auto_generate_reports', 'notify_on_completion',
        'min_word_count', 'max_word_count', 'max_file_size_mb', 'max_upload_size',
        'allowed_file_types', 'fingerprint_shingle_size', 'near_match_threshold',
        'retention_days', 'allowed_extensions',
    ];

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
        $stmt->execute([':tid' => $tenantId, ':key' => $key, ':val' => (string)$value]);
    }
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

        $stmt = $db->prepare("SELECT COUNT(*) FROM ac_similarity_matches m JOIN ac_similarity_submissions s ON m.submission_id = s.submission_id WHERE s.tenant_id = :tid");
        $stmt->execute([':tid' => $tenantId]);
        $stats['total_matches'] = (int)$stmt->fetchColumn();

        $stmt = $db->prepare("SELECT submission_id, submission_title, status, word_count, created_at FROM ac_similarity_submissions WHERE tenant_id = :tid ORDER BY created_at DESC LIMIT 10");
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
    $html = '<div class="ac-sim-public-wrap" style="max-width:640px;margin:2rem auto">';
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

    $html .= '<div style="text-align:center;color:#9ca3af;font-size:0.8rem">— or —</div>';

    $html .= '<div><label style="display:block;font-weight:600;font-size:0.9rem;margin-bottom:0.25rem">Paste Text</label>';
    $html .= '<textarea name="pasted_text" rows="6" style="width:100%;padding:0.6rem 0.75rem;border:1px solid #d1d5db;border-radius:0.5rem;font-size:0.9rem" placeholder="Paste your document content..."></textarea></div>';

    $html .= '<button type="submit" style="width:100%;padding:0.75rem;background:#2563eb;color:#fff;font-weight:600;border:0;border-radius:0.5rem;cursor:pointer">Check Similarity</button>';
    $html .= '</form>';
    $html .= '<div id="ac-sim-result" style="margin-top:1rem;display:none"></div>';

    $html .= '<script>
function acSimPublicSubmit(e){e.preventDefault();var f=document.getElementById("ac-sim-form"),fd=new FormData(f),r=document.getElementById("ac-sim-result"),file=fd.get("file"),text=(fd.get("pasted_text")||"").toString().trim();fd.set("source_type",file&&file.name?"upload":"pasted");if(!file.name&&!text){r.style.display="block";r.style.color="#dc2626";r.innerHTML="Error: paste text or upload a file";return false}fd.set("submission_title",fd.get("submission_title")||"Untitled"),r.style.display="block",r.innerHTML="Submitting...",fetch("/api/v1/academic-similarity/public/submit",{method:"POST",body:fd}).then(function(x){return x.json()}).then(function(d){r.className="";if(d.ok){r.style.color="#059669";r.innerHTML="<strong>Submitted!</strong> Reference ID: "+d.submission_id;f.reset()}else{r.style.color="#dc2626";r.innerHTML="Error: "+(d.error||"Unknown")}}).catch(function(e){r.style.color="#dc2626";r.innerHTML="Network error: "+e.message});return false}
</script>';
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
        $pattern = '/\[' . preg_quote($shortcode, '/') . '(?:\s+title="([^"]*)")?\s*\]/i';
        return preg_replace_callback($pattern, static function (array $matches): string {
            $title = $matches[1] ?? '';
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
