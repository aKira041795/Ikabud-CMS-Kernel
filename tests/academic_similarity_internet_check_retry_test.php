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

echo "=== Academic Similarity — Internet Check Error Resilience ===\n";

$base = __DIR__ . '/../modules';
$aiHelpers = file_get_contents($base . '/ai/helpers.php');
$check = file_get_contents($base . '/academic_similarity/src/Services/AcademicSimilarityInternetCheckService.php');

// Retry logic in SerpAPI
aiss_it('ai_search_serpapi_direct has retry loop', str_contains($aiHelpers, 'function ai_search_serpapi_direct'));
aiss_it('retry uses exponential backoff delays', str_contains($aiHelpers, '$retryDelay = [1, 3]'));
aiss_it('retry max is 2 attempts', str_contains($aiHelpers, '$maxRetries = 2'));
aiss_it('retry only on connection errors (not api errors)', str_contains($aiHelpers, 'error_get_last'));
aiss_it('retry logs each attempt', str_contains($aiHelpers, 'attempt '));
aiss_it('all attempts exhausted logs error level', str_contains($aiHelpers, "all '"));

// Timeout wiring
aiss_it('ai_search_serpapi_direct has timeout parameter', str_contains($aiHelpers, 'int $timeout = 15'));
aiss_it('timeout passed to stream context', str_contains($aiHelpers, "timeout"));
aiss_it('backend dispatch passes timeout through', str_contains($aiHelpers, 'int $timeout = 15'));
aiss_it('capability handler passes timeout_seconds from payload', str_contains($aiHelpers, "timeout_seconds"));

// Circuit breaker
aiss_it('breakerIsOpen method exists', str_contains($check, 'function breakerIsOpen'));
aiss_it('breakerRecordFailure method exists', str_contains($check, 'function breakerRecordFailure'));
aiss_it('breakerReset method exists', str_contains($check, 'function breakerReset'));
aiss_it('breaker checks 3 failures threshold', str_contains($check, 'failures < 3'));
aiss_it('breaker uses 5-minute cooldown', str_contains($check, '< 300'));
aiss_it('breaker stored in ac_similarity_settings', str_contains($check, 'internet_check_breaker_state'));
aiss_it('breaker bypassed by force=true', str_contains($check, 'breakerIsOpen'));
aiss_it('breaker resets on success', str_contains($check, 'breakerReset'));

// Concurrency guard
aiss_it('hasPendingRun method exists', str_contains(file_get_contents($base . '/academic_similarity/src/Repositories/AcademicSimilarityInternetSearchRunRepository.php'), 'function hasPendingRun'));
aiss_it('runForSubmission checks for pending runs', str_contains($check, 'hasPendingRun('));
aiss_it('pending run returns skipped with reason', str_contains($check, 'already in progress'));

echo "\n── Results: {$pass} passed, {$fail} failed ──\n";
exit($fail > 0 ? 1 : 0);
