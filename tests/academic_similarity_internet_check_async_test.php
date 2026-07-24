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

echo "=== Academic Similarity — Async Internet Check Contract ===\n";

$base = __DIR__ . '/../modules/academic_similarity';
$handlers = file_get_contents($base . '/handlers.php');
$check = file_get_contents($base . '/src/Services/AcademicSimilarityInternetCheckService.php');
$pipeline = file_get_contents($base . '/src/Services/AcademicSimilarityPipelineService.php');
$routes = file_get_contents($base . '/routes.php');
$aiHelpers = file_get_contents(__DIR__ . '/../modules/ai/helpers.php');

// dispatchAsync
aiss_it('dispatchAsync method exists', str_contains($check, 'function dispatchAsync'));
aiss_it('dispatchAsync dispatches to kernel queue', str_contains($check, 'kernelDispatchJob'));
aiss_it('dispatchAsync uses correct handler name', str_contains($check, 'academicSimilarityInternetCheckHandler'));
aiss_it('dispatchAsync falls back to runForSubmission', str_contains($check, "return \$this->runForSubmission(\$submissionId, true);"));

// Kernel job handler
aiss_it('async job handler function exists', str_contains($handlers, 'function academicSimilarityInternetCheckHandler'));
aiss_it('async handler calls runForSubmission', str_contains($handlers, '$service->runForSubmission($submissionId, true)'));
aiss_it('async handler calls runRecheckFromInternet on success', str_contains($handlers, '$pipeline->runRecheckFromInternet($submissionId)'));

// Polling endpoint
// Polling endpoint — verify no CSRF in the status handler
$statusFnPos = strpos($handlers, 'function apiInternetCheckStatus');
$statusBody = substr($handlers, $statusFnPos, strpos($handlers, 'function academicSimilarityInternetCheckHandler', $statusFnPos) - $statusFnPos);
$hasRoute = str_contains($routes, 'internet-check-status');
aiss_it('status polling route exists', $hasRoute);
aiss_it('status polling handler exists', $statusFnPos !== false);
aiss_it('status polling is GET (no CSRF enforce)', $statusFnPos !== false && !str_contains($statusBody, 'csrfEnforce'));
aiss_it('status polling returns status fields', str_contains($handlers, "'query_count'") && str_contains($handlers, "'candidate_count'") && str_contains($handlers, "'imported_count'"));

// Pipeline
aiss_it('runInternetDiscovery calls dispatchAsync', str_contains($pipeline, 'dispatchAsync($submissionId)'));
aiss_it('runRecheckFromInternet exists', str_contains($pipeline, 'function runRecheckFromInternet'));
aiss_it('recheck re-runs matching stages', str_contains($pipeline, "'candidate_search'") && str_contains($pipeline, "'exact_match'") && str_contains($pipeline, "'near_match'"));

// API handler force_sync
aiss_it('apiRunInternetCheck supports force_sync', str_contains($handlers, 'force_sync') && str_contains($handlers, 'dispatchAsync'));
aiss_it('apiRunInternetCheck has sync fallback path', str_contains($handlers, '$forceSync') && str_contains($handlers, 'runForSubmission($submissionId, true)'));

echo "\n── Results: {$pass} passed, {$fail} failed ──\n";
exit($fail > 0 ? 1 : 0);
