<?php
declare(strict_types=1);

/**
 * Academic Similarity — Semantic Capability Contract Test.
 *
 * Tests the PHP-side semantic service client and capability contract
 * without requiring a running Python service.
 *
 * Coverage areas:
 * - Default settings (semantic_match_enabled defaults to 1)
 * - Input validation (empty segments, oversized payloads)
 * - Availability gates (setting, capability registration, plan)
 * - Health check graceful degradation (service off = ok=false)
 * - Capability handler fallback (clear error when service-module disabled)
 * - Handler map completeness (7 capabilities registered)
 * - Service module manifest structure
 * - Python app.py existence and wire protocol compliance
 * - API docs completeness
 * - Schema model_profiles table
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../modules/academic_similarity/helpers.php';

$pass = 0;
$fail = 0;
function t(string $description, bool $condition, string $detail = ''): void {
    global $pass, $fail;
    if ($condition) { $pass++; echo "  ✅ {$description}\n"; }
    else { $fail++; echo "  ❌ {$description}" . ($detail ? " — {$detail}" : '') . "\n"; }
}

file_put_contents(__DIR__ . '/../storage/logs/app.log', '');
file_put_contents(__DIR__ . '/../storage/logs/error.log', '');

// Clear any residual output buffering from bootstrap
while (ob_get_level() > 0) { @ob_end_clean(); }

echo "\n=== Academic Similarity — Semantic Capability Contract ===\n";

// ── Service Availability Gates (no DB dependency) ──

// Check default in source code (not DB-dependent)
$helpersFile = file_get_contents(__DIR__ . '/../modules/academic_similarity/helpers.php');
$hasDefault = str_contains($helpersFile, "'semantic_match_enabled' => '1'");
$alsoDefault = str_contains($helpersFile, "'semantic_match_enabled' => '0'");
t('semantic_match_enabled defaults to 1 in source', $hasDefault && !$alsoDefault);

// ── SemanticService: Constructor & Structure ──

$semService = new AcademicSimilaritySemanticService('test-tenant');
t('SemanticService can be instantiated', $semService instanceof AcademicSimilaritySemanticService);

// ── Input Validation ──

// Empty submission_segments (will fail at setting gate, not input validation gate)
// Must check compare returns reasonable error
$result = $semService->compare([], ['source segment']);
t('compare with empty submission returns error', !($result['ok'] ?? true));
t('error message is non-empty', !empty($result['error']));

// Empty source_segments
$result = $semService->compare(['submission segment'], []);
t('compare with empty source returns error', !($result['ok'] ?? true));

// Too many segments
$manySegments = array_fill(0, 600, 'segment text');
$result = $semService->compare($manySegments, ['source segment']);
t('rejects oversized submission_segments (600 > 500)', !($result['ok'] ?? true));

$result = $semService->compare(['submission segment'], $manySegments);
t('rejects oversized source_segments (600 > 500)', !($result['ok'] ?? true));

// ── SemanticService::isAvailable() gates ──

$availability = $semService->isAvailable();
t('isAvailable returns array with gates', isset($availability['gates']));
t('isAvailable has setting_enabled gate', array_key_exists('setting_enabled', $availability['gates']));
t('isAvailable has capability_registered gate', array_key_exists('capability_registered', $availability['gates']));
t('isAvailable has service_reachable gate', array_key_exists('service_reachable', $availability['gates']));
t('isAvailable has plan_enabled gate', array_key_exists('plan_enabled', $availability['gates']));

// ── SemanticService::health() — offline behavior ──

$health = $semService->health();
// When the semantic service module is not loaded, health returns error gracefully
$healthOk = !($health['ok'] ?? true);
t('health check handles unavailable service gracefully', $healthOk, 'got ok: ' . ($health['ok'] ?? 'not set'));
t('health check returns error message on unavailable service', !empty($health['error']), 'got error: ' . ($health['error'] ?? ''));

// ── Capability Handler Function ──

t('ac_sim_cap_semantic_compare_1 function exists', function_exists('ac_sim_cap_semantic_compare_1'));

// Verify it returns a clear error (not silent payload passthrough)
$capResult = ac_sim_cap_semantic_compare_1(['test' => 'value'], 'academic_similarity.semantic.compare@1', 'academic-similarity');
t('capability handler returns array', is_array($capResult));
t('capability handler returns error (service not available)', !($capResult['ok'] ?? true), 'got ok: ' . ($capResult['ok'] ?? 'not set'));
t('capability handler error is descriptive', str_contains($capResult['error'] ?? '', 'service') || str_contains($capResult['error'] ?? '', 'module'), 'got: ' . ($capResult['error'] ?? ''));

// Rejects non-array payload
$capResult = ac_sim_cap_semantic_compare_1('not an array', 'academic_similarity.semantic.compare@1', 'academic-similarity');
t('capability handler rejects non-array payload', ($capResult['ok'] ?? true) === false || !empty($capResult['error']), 'got ok=' . ($capResult['ok'] ?? 'not set'));

// ── Capability Handler Map ──

$handlerMap = academic_similarity_capability_handlers();
t('capability handler map includes semantic.compare@1', isset($handlerMap['academic_similarity.semantic.compare@1']));
t('semantic handler maps to correct function', $handlerMap['academic_similarity.semantic.compare@1'] === 'ac_sim_cap_semantic_compare_1');
t('handler map includes at least the original 8 capabilities', count($handlerMap) >= 8, 'got: ' . count($handlerMap));

// ── Quota Service Integration ──

// QuotaService requires module context — check class methods via reflection instead
t('QuotaService class exists', class_exists('AcademicSimilarityQuotaService'));
t('QuotaService has checkQuota method', method_exists('AcademicSimilarityQuotaService', 'checkQuota'));
t('QuotaService has getSubscription method', method_exists('AcademicSimilarityQuotaService', 'getSubscription'));
t('QuotaService has getUsage method', method_exists('AcademicSimilarityQuotaService', 'getUsage'));
t('QuotaService has checkLimits method', method_exists('AcademicSimilarityQuotaService', 'checkLimits'));

// ── Usage Counter Support ──

// UsageCounterRepository requires module context — test class existence instead
t('UsageCounterRepository class exists', class_exists('AcademicSimilarityUsageCounterRepository'));
t('UsageCounterRepository has increment method', method_exists('AcademicSimilarityUsageCounterRepository', 'increment'));
t('UsageCounterRepository has getMonthlyCount method', method_exists('AcademicSimilarityUsageCounterRepository', 'getMonthlyCount'));

// ── Model Profile Integration ──

// The ac_similarity_model_profiles table schema supports semantic models
// This is tested via schema inspection
$schemaPath = __DIR__ . '/../modules/academic_similarity/migrations/001_academic_similarity_schema.sql';
$schema = file_get_contents($schemaPath);
t('schema has ac_similarity_model_profiles table', str_contains($schema, 'ac_similarity_model_profiles'));
t('schema has model_name column', str_contains($schema, 'model_name'));
t('schema has provider column', str_contains($schema, 'provider'));
t('schema has model_version column', str_contains($schema, 'model_version'));
t('schema has embedding_dimensions column', str_contains($schema, 'embedding_dimensions'));
t('schema has cost_per_1k_tokens column', str_contains($schema, 'cost_per_1k_tokens'));

// ── Service Module Manifest ──

$manifestPath = __DIR__ . '/../modules/academic-similarity-semantic-service/module.json';
t('semantic service module.json exists', file_exists($manifestPath));
$manifest = json_decode(file_get_contents($manifestPath), true);
t('semantic service module.json is valid JSON', $manifest !== null);
t('semantic service is type service-module', ($manifest['type'] ?? '') === 'service-module');
t('semantic service has service.endpoint', !empty($manifest['service']['endpoint'] ?? ''));
t('semantic service exposes semantic.compare@1', $manifest['capabilities']['exposes'][0]['id'] === 'academic_similarity.semantic.compare@1');
t('semantic service exposes semantic.health@1', $manifest['capabilities']['exposes'][1]['id'] === 'academic_similarity.semantic.health@1');
t('semantic service has auth token_env', ($manifest['service']['auth']['token_env'] ?? '') === 'SEMANTIC_SERVICE_TOKEN');

// ── Python Service App Exists ──

$pythonAppPath = __DIR__ . '/../modules/academic-similarity-semantic-service/service/app.py';
t('Python service app.py exists', file_exists($pythonAppPath));
$pythonApp = file_get_contents($pythonAppPath);
t('app.py implements /capability/call endpoint', str_contains($pythonApp, '/capability/call'));
t('app.py implements /health endpoint', str_contains($pythonApp, '/health'));
t('app.py has capability handler map', str_contains($pythonApp, 'CAPABILITY_HANDLERS'));
t('app.py has auth token validation', str_contains($pythonApp, 'AUTH_TOKEN'));
t('app.py has semantic.compare@1 handler', str_contains($pythonApp, 'semantic.compare@1'));
t('app.py accepts payload Groq API key', str_contains($pythonApp, 'model_profile.get("api_key"') && str_contains($pythonApp, 'api_key or os.environ.get("SEMANTIC_API_KEY")'));

// ── Requirements File ──

$reqPath = __DIR__ . '/../modules/academic-similarity-semantic-service/service/requirements.txt';
t('requirements.txt exists', file_exists($reqPath));

// ── API Docs ──

$docsPath = __DIR__ . '/../modules/academic-similarity-semantic-service/docs/api.md';
t('API docs exist', file_exists($docsPath));
$apiDocs = file_get_contents($docsPath);
t('API docs document semantic.compare@1', str_contains($apiDocs, 'semantic.compare@1'));
t('API docs document health endpoint', str_contains($apiDocs, 'health'));
t('API docs document error codes', str_contains($apiDocs, 'Error Codes'));

// ── Known Limitations Updated ──

$limitationsPath = __DIR__ . '/../modules/academic_similarity/docs/known-limitations.md';
if (file_exists($limitationsPath)) {
    $knownLimits = file_get_contents($limitationsPath);
    t('known-limitations mentions semantic service', str_contains($knownLimits, 'semantic') || str_contains($knownLimits, 'Semantic'));
}

// Check logs
$appLog = file_get_contents(__DIR__ . '/../storage/logs/app.log');
$errorLog = file_get_contents(__DIR__ . '/../storage/logs/error.log');
t('app.log has no critical entries', !str_contains($appLog, '[critical]'), trim($appLog));
t('error.log is empty', trim($errorLog) === '', trim($errorLog));

echo "\n── Results: {$pass} passed, {$fail} failed ──\n";
exit($fail > 0 ? 1 : 0);
