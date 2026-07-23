<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

$pass = 0;
$fail = 0;
function t(string $description, bool $condition, string $detail = ''): void {
    global $pass, $fail;
    if ($condition) { $pass++; echo "  ✅ {$description}\n"; }
    else { $fail++; echo "  ❌ {$description}" . ($detail ? " — {$detail}" : '') . "\n"; }
}

echo "\n=== Academic Similarity — CMS Configuration Wiring ===\n";

$base = __DIR__ . '/../modules/academic_similarity';
$helpers = file_get_contents($base . '/helpers.php');
$routes = file_get_contents($base . '/routes.php');
$settings = file_get_contents($base . '/templates/academic_similarity/settings.disyl');
$manifest = file_get_contents($base . '/module.json');
$semanticManifest = file_get_contents(__DIR__ . '/../modules/academic-similarity-semantic-service/module.json');
$globalSettings = file_get_contents(__DIR__ . '/../templates/academic_similarity/settings.disyl');
$schema = file_get_contents($base . '/migrations/001_academic_similarity_schema.sql');
$pipeline = file_get_contents($base . '/src/Services/AcademicSimilarityPipelineService.php');
$handlers = file_get_contents($base . '/handlers.php');
$extractor = file_get_contents($base . '/src/Support/AcademicSimilarityTextExtractor.php');
$reportService = file_get_contents($base . '/src/Services/AcademicSimilarityReportService.php');
$reportsView = file_get_contents($base . '/templates/academic_similarity/reports/index.disyl');
$sourceService = file_get_contents($base . '/src/Services/AcademicSimilaritySourceService.php');
$sourceRepository = file_get_contents($base . '/src/Repositories/AcademicSimilaritySourceRepository.php');
$superadminHandlers = file_get_contents(__DIR__ . '/../src/http/superadmin-handlers.php');

t('CMS nav hook registers Similarity section', str_contains($helpers, "cms.admin.nav_items") && str_contains($helpers, "'label'    => 'Similarity'"));
t('CMS nav includes semantic configuration link', str_contains($helpers, '/admin/academic-similarity/settings/semantic'));
t('CMS nav includes CMS flow configuration link', str_contains($helpers, '/admin/academic-similarity/settings/cms'));
t('settings page route exists', str_contains($routes, "'/admin/academic-similarity/settings'"));
t('admin settings POST route exists', str_contains($routes, "'/admin/academic-similarity/settings'") && str_contains($routes, 'apiSaveSettings'));
t('settings semantic subroute exists', str_contains($routes, '/admin/academic-similarity/settings/semantic'));
t('settings CMS subroute exists', str_contains($routes, '/admin/academic-similarity/settings/cms'));
t('settings subtab POST aliases exist', str_contains($routes, "'/admin/academic-similarity/settings/semantic'            => 'academic-similarity:apiSaveSettings'") && str_contains($routes, "'/admin/academic-similarity/settings/internet'            => 'academic-similarity:apiSaveSettings'") && str_contains($routes, "'/admin/academic-similarity/settings/cms'                 => 'academic-similarity:apiSaveSettings'"));
t('new submission route exists', str_contains($routes, '/admin/academic-similarity/submissions/new'));
t('upload view exists', file_exists($base . '/templates/academic_similarity/submissions/upload.disyl'));

t('settings template has semantic section', str_contains($settings, 'Semantic Matching Service'));
t('settings template has CMS flow section', str_contains($settings, 'CMS Publishing Flow'));
t('settings template exposes provider', str_contains($settings, 'semantic_provider'));
t('settings template exposes top save button', str_contains($settings, 'form="aiss-settings-form"') && str_contains($settings, 'Save Settings'));
t('settings save button uses high contrast color', str_contains($settings, 'bg-emerald-600') && str_contains($settings, 'font-bold'));
t('settings form has stable submit id', str_contains($settings, 'id="aiss-settings-form"'));
t('settings form preserves active section', str_contains($settings, 'name="settings_section" value="{settings_section}"'));
t('settings template exposes Groq provider option', str_contains($settings, 'value="groq"'));
t('settings template exposes model name', str_contains($settings, 'semantic_model_name'));
t('settings template exposes Python service endpoint', str_contains($settings, 'semantic_service_endpoint'));
t('settings template exposes service token env var', str_contains($settings, 'semantic_service_token_env'));
t('settings template exposes external AI API key env var', str_contains($settings, 'semantic_external_api_key_env'));
t('settings template exposes encrypted semantic API key field', str_contains($settings, 'name="semantic_external_api_key"') && str_contains($settings, 'Stored encrypted at rest'));
t('settings template exposes payload policy', str_contains($settings, 'semantic_payload_policy'));
t('settings template exposes public shortcode toggle', str_contains($settings, 'cms_public_submission_enabled'));
t('settings template exposes builder block toggle', str_contains($settings, 'cms_builder_block_enabled'));
t('global settings view is installed for runtime rendering', str_contains($globalSettings, 'Semantic Matching Service'));
t('global settings view exposes top save button', str_contains($globalSettings, 'form="aiss-settings-form"') && str_contains($globalSettings, 'Save Settings'));
t('global settings save button uses high contrast color', str_contains($globalSettings, 'bg-emerald-600') && str_contains($globalSettings, 'font-bold'));
t('global settings view form has stable submit id', str_contains($globalSettings, 'id="aiss-settings-form"'));
t('global settings form preserves active section', str_contains($globalSettings, 'name="settings_section" value="{settings_section}"'));
t('AISS manifest declares settings table ownership', str_contains($manifest, 'ac_similarity_settings'));
t('AISS manifest exposes semantic provider setting', str_contains($manifest, '"key": "semantic_provider"'));
t('AISS manifest exposes Groq provider option', str_contains($manifest, '"value": "groq"'));
t('AISS manifest exposes semantic Python endpoint setting', str_contains($manifest, '"key": "semantic_service_endpoint"'));
t('AISS manifest exposes semantic service token env setting', str_contains($manifest, '"key": "semantic_service_token_env"'));
t('AISS manifest exposes encrypted semantic API key setting', str_contains($manifest, '"key": "semantic_external_api_key"') && str_contains($manifest, '"type": "password"'));
t('AISS manifest exposes CMS shortcode setting', str_contains($manifest, '"key": "cms_submission_shortcode"'));
t('AISS manifest exposes internet runtime settings used by CMS', str_contains($manifest, '"key": "internet_check_auto_run_when_no_sources"') && str_contains($manifest, '"key": "internet_check_max_chars_per_source"') && str_contains($manifest, '"key": "internet_check_seed_urls"'));
t('AISS manifest exposes public report workspace settings used by CMS', str_contains($manifest, '"key": "public_report_workspace_enabled"') && str_contains($manifest, '"key": "public_report_download_enabled"') && str_contains($manifest, '"key": "public_report_show_source_names"'));
t('semantic service manifest exposes endpoint configuration', str_contains($semanticManifest, '"key": "service_endpoint"'));
t('semantic service manifest exposes backend configuration', str_contains($semanticManifest, '"key": "semantic_embedding_backend"'));
t('semantic service manifest exposes external API key env setting', str_contains($semanticManifest, '"key": "semantic_external_api_key_env"'));
t('semantic service manifest exposes Groq backend option', str_contains($semanticManifest, '"value": "groq"'));

t('settings defaults include semantic provider', str_contains($helpers, "'semantic_provider' => 'token_overlap'"));
t('settings defaults include semantic service endpoint', str_contains($helpers, "'semantic_service_endpoint' => 'http://127.0.0.1:9003'"));
t('settings allowlist includes semantic external API key env', str_contains($helpers, "'semantic_external_api_key_env'"));
t('settings allowlist includes encrypted semantic API key', str_contains($helpers, "'semantic_external_api_key'"));
t('settings helper encrypts AISS sensitive keys', str_contains($helpers, 'academic_similarity_encrypt_sensitive_settings') && str_contains($helpers, 'new \\Ikabud\\Kernel\\Crypto()'));
t('settings helper converts misplaced API keys from env fields', str_contains($helpers, 'academic_similarity_looks_like_secret') && str_contains($helpers, "'semantic_external_api_key_env' => ['secret' => 'semantic_external_api_key'"));
t('settings allowlist includes semantic max segments', str_contains($helpers, "'semantic_max_segments'"));
t('settings allowlist includes CMS shortcode', str_contains($helpers, "'cms_submission_shortcode'"));
t('settings save normalizes visible public report checkboxes', str_contains($handlers, "'public_report_workspace_enabled'") && str_contains($handlers, "'public_report_show_full_document'"));
t('settings save redirects back to active settings tab', str_contains($handlers, '$validSections') && str_contains($handlers, "\$target .= '/' . \$section"));
t('superadmin AISS feature save syncs runtime settings table', str_contains($superadminHandlers, 'superadminSyncAcademicSimilarityRuntimeSettings') && str_contains($superadminHandlers, 'ac_similarity_settings') && str_contains($superadminHandlers, "\$modId === 'academic-similarity'"));
t('superadmin AISS secret save encrypts runtime keys', str_contains($superadminHandlers, 'semantic_external_api_key') && str_contains($superadminHandlers, 'encryptString($secret)'));
t('settings save enables semantic service module when semantic matching is on', str_contains($helpers, 'academic_similarity_sync_semantic_service_module') && str_contains($helpers, "enableModuleForTenant('academic-similarity-semantic-service'"));
t('superadmin AISS sync enables semantic service module when semantic matching is on', str_contains($superadminHandlers, "enableModuleForTenant('academic-similarity-semantic-service'"));
t('semantic service passes encrypted admin key server-side', str_contains($pipeline, 'semantic_external_api_key') || str_contains(file_get_contents($base . '/src/Services/AcademicSimilaritySemanticService.php'), "'api_key'"));
t('CMS shortcode renderer reads configured shortcode', str_contains($helpers, 'cms_submission_shortcode') && str_contains($helpers, 'preg_quote($shortcode'));
t('CMS public form submits source_type', str_contains($helpers, 'name="source_type"') && str_contains($helpers, 'fd.set("source_type"'));
t('CMS builder block honors enable setting', str_contains($helpers, 'cms_builder_block_enabled') && str_contains($helpers, "=== '1'"));
t('public submission resolves default active institution', str_contains($handlers, 'ac_similarity_institutions') && str_contains($handlers, 'No active Similarity institution'));
t('public submit normalizes pasted source type', str_contains($handlers, '$pastedText') && str_contains($handlers, "\$input['source_type'] = 'pasted'"));
t('reports handler passes search and sort controls', str_contains($handlers, '$reportService->search($submissionId, 1, 50, $search, $sort)') && str_contains($handlers, 'current_sort'));
t('reports service aliases score and match columns', str_contains($reportService, 'raw_similarity_score') && str_contains($reportService, 'adjusted_similarity_score') && str_contains($reportService, 'match_count'));
t('reports service provides aggregate stats', str_contains($reportService, 'function stats') && str_contains($reportService, 'avg_adjusted_score') && str_contains($reportService, 'high_risk_reports'));
t('reports service falls back to submission words for eligible totals', str_contains($reportService, 'NULLIF(r.total_eligible_words, 0)') && str_contains($reportService, 's.word_count'));
t('reports view renders stats summary', str_contains($reportsView, 'report_stats.total_reports') && str_contains($reportsView, 'Avg Adjusted') && str_contains($reportsView, 'Eligible Words'));
t('reports view renders corrected score fields', str_contains($reportsView, 'report.raw_similarity_score|default:0') && str_contains($reportsView, 'report.adjusted_similarity_score|default:0') && str_contains($reportsView, 'report.match_count|default:0'));
t('reports view renders submission word counts', str_contains($reportsView, 'report.submission_word_count|default:0') && str_contains($reportsView, 'matched words'));
t('source service indexes source text versions and fingerprints', str_contains($sourceService, 'indexSourceText') && str_contains($sourceService, 'source_id, text_type') && str_contains($sourceService, 'saveFingerprints($exact'));
t('source repository avoids duplicate status placeholders', str_contains($sourceRepository, ':status_indexed') && str_contains($sourceRepository, ':status_finished'));

t('PDF extractor uses pdftotext when available', str_contains($extractor, 'extractPdfWithPdftotext') && str_contains($extractor, 'pdftotext'));
$submissionService = file_get_contents($base . '/src/Services/AcademicSimilaritySubmissionService.php');
t('submission word count is Unicode aware', str_contains($submissionService, 'preg_match_all') && str_contains($submissionService, '\\p{L}'));
t('submission text versions use current schema', str_contains($submissionService, "text_type, extracted_text") && !str_contains($submissionService, 'version_number'));
t('schema supports semantic_match processing job', str_contains($schema, "'semantic_match'"));
t('pipeline includes semantic_match stage', str_contains($pipeline, "'semantic_match'"));
t('pipeline executes semantic stage before score', strpos($pipeline, "'semantic_match'") < strpos($pipeline, "'score'"));
t('pipeline has runSemanticMatchStage', str_contains($pipeline, 'runSemanticMatchStage'));
t('pipeline semantic stage calls AcademicSimilaritySemanticService', str_contains($pipeline, 'new AcademicSimilaritySemanticService'));
t('pipeline semantic stage stores semantic match type', str_contains($pipeline, "'match_type' => 'semantic'"));
t('pipeline fails stages that return ok false', str_contains($pipeline, "array_key_exists('ok', \$result)") && str_contains($pipeline, 'throw new \\RuntimeException'));
t('pipeline semantic can fall back to indexed source segments', str_contains($pipeline, 'loadIndexedSourceSegmentsForSemantic') && str_contains($pipeline, 'src.is_indexed = 1'));

echo "\n── Results: {$pass} passed, {$fail} failed ──\n";
exit($fail > 0 ? 1 : 0);
