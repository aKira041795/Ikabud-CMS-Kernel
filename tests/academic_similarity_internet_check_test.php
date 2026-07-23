<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

$pass = 0;
$fail = 0;
function aiss_it(string $description, bool $condition, string $detail = ''): void {
    global $pass, $fail;
    if ($condition) { $pass++; echo "✅ {$description}\n"; }
    else { $fail++; echo "❌ {$description}" . ($detail ? " — {$detail}" : '') . "\n"; }
}

echo "=== Academic Similarity — Internet Check Contract ===\n";

$base = __DIR__ . '/../modules/academic_similarity';
$helpers = file_get_contents($base . '/helpers.php');
$routes = file_get_contents($base . '/routes.php');
$handlers = file_get_contents($base . '/handlers.php');
$manifest = file_get_contents($base . '/module.json');
$migration = file_get_contents($base . '/migrations/006_academic_similarity_internet_sources.sql');
$pipeline = file_get_contents($base . '/src/Services/AcademicSimilarityPipelineService.php');
$discovery = file_get_contents($base . '/src/Services/AcademicSimilarityInternetDiscoveryService.php');
$check = file_get_contents($base . '/src/Services/AcademicSimilarityInternetCheckService.php');
$ingestion = file_get_contents($base . '/src/Services/AcademicSimilarityInternetSourceIngestionService.php');
$runRepo = file_get_contents($base . '/src/Repositories/AcademicSimilarityInternetSearchRunRepository.php');
$sourceRepo = file_get_contents($base . '/src/Repositories/AcademicSimilarityInternetSourceRepository.php');
$reportService = file_get_contents($base . '/src/Services/AcademicSimilarityReportService.php');
$publicView = file_get_contents($base . '/src/Services/AcademicSimilarityPublicReportViewService.php');
$settings = file_get_contents($base . '/templates/academic_similarity/settings.disyl');
$globalSettings = file_get_contents(__DIR__ . '/../templates/academic_similarity/settings.disyl');
$detail = file_get_contents($base . '/templates/academic_similarity/reports/detail.disyl');
$workspace = file_get_contents($base . '/templates/academic_similarity/public/workspace.disyl');

aiss_it('manifest owns internet provenance tables', str_contains($manifest, 'ac_similarity_internet_search_runs') && str_contains($manifest, 'ac_similarity_internet_sources'));
aiss_it('manifest registers internet migration', str_contains($manifest, '006_academic_similarity_internet_sources.sql'));
aiss_it('manifest exposes internet settings', str_contains($manifest, '"key": "internet_check_enabled"') && str_contains($manifest, '"key": "internet_check_provider"'));
aiss_it('migration creates search run table', str_contains($migration, 'CREATE TABLE IF NOT EXISTS ac_similarity_internet_search_runs'));
aiss_it('migration creates internet source table', str_contains($migration, 'CREATE TABLE IF NOT EXISTS ac_similarity_internet_sources'));
aiss_it('migration extends processing job enum', str_contains($migration, "'internet_discovery'"));
aiss_it('settings defaults disable internet check', str_contains($helpers, "'internet_check_enabled' => '0'"));
aiss_it('settings defaults use bounded snippets policy', str_contains($helpers, "'internet_check_payload_policy' => 'snippets_only'"));
aiss_it('settings allowlist includes provider limits', str_contains($helpers, "'internet_check_max_queries'") && str_contains($helpers, "'internet_check_max_sources'"));
aiss_it('settings UI has internet tab in module view', str_contains($settings, 'AI Internet Check') && str_contains($settings, 'internet_check_seed_urls'));
aiss_it('settings UI has internet tab in live view', str_contains($globalSettings, 'AI Internet Check') && str_contains($globalSettings, 'internet_check_provider'));
aiss_it('manual internet check route exists', str_contains($routes, "submissions/{id}/internet-check") && str_contains($routes, 'apiRunInternetCheck'));
aiss_it('settings internet subroute exists', str_contains($routes, '/admin/academic-similarity/settings/internet'));
aiss_it('handler exposes manual internet check', str_contains($handlers, 'function apiRunInternetCheck') && str_contains($handlers, 'runForSubmission($submissionId, true)'));
aiss_it('submission detail receives latest internet run', str_contains($handlers, 'latestRun($submissionId)') && str_contains($handlers, 'internet_check_enabled'));
aiss_it('pipeline inserts internet discovery before candidate search', strpos($pipeline, "'internet_discovery'") < strpos($pipeline, "'candidate_search'"));
aiss_it('pipeline internet stage is non-false-clean when disabled', str_contains($pipeline, 'Analysis is limited to tenant-indexed AISS sources'));
aiss_it('discovery builds bounded queries', str_contains($discovery, 'clipQuery') && str_contains($discovery, 'internet_check_allow_full_document_query'));
aiss_it('discovery supports provider capability', str_contains($discovery, 'academic_similarity.internet.discover@1'));
aiss_it('discovery supports configured seed URLs', str_contains($discovery, 'seedUrlCandidates') && str_contains($discovery, 'internet_check_seed_urls'));
aiss_it('fetch only allows http urls', str_contains($discovery, 'Only http/https URLs are allowed'));
aiss_it('internet check disabled returns limited corpus disclosure', str_contains($check, 'Internet checking is disabled') && str_contains($check, 'limitedCorpusDisclosure'));
aiss_it('internet check stores candidate provenance', str_contains($check, 'createCandidate') && str_contains($check, 'markImported'));
aiss_it('ingestion stores internet metadata', str_contains($ingestion, 'internet_discovered') && str_contains($ingestion, 'source_url'));
aiss_it('ingestion reuses source indexing pipeline', str_contains($ingestion, 'indexSourceText'));
aiss_it('run repository tenant scopes latest reads', str_contains($runRepo, 'tenant_id = :tid') && str_contains($runRepo, 'latestForSubmission'));
aiss_it('internet source repository tenant scopes source lookups', str_contains($sourceRepo, 'tenant_id = :tid') && str_contains($sourceRepo, 'findBySourceIds'));
aiss_it('report download labels internet sources', str_contains($reportService, 'Internet-discovered source') && str_contains($reportService, 'source_url'));
aiss_it('admin report detail has internet coverage section', str_contains($detail, 'Internet Source Coverage'));
aiss_it('public report model includes internet origin', str_contains($publicView, "source_origin'] = 'internet'"));
aiss_it('public workspace labels internet passages', str_contains($workspace, 'mp.source_origin ===') && str_contains($workspace, 'Internet'));

echo "── Results: {$pass} passed, {$fail} failed ──\n";
exit($fail > 0 ? 1 : 0);
