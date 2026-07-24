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

echo "=== Academic Similarity — Internet Check Backend Abstraction ===\n";

$base = __DIR__ . '/../modules';
$aiHelpers = file_get_contents($base . '/ai/helpers.php');
$discovery = file_get_contents($base . '/academic_similarity/src/Services/AcademicSimilarityInternetDiscoveryService.php');
$moduleJson = file_get_contents($base . '/academic_similarity/module.json');

// Backend dispatch function
aiss_it('ai_search_backend_dispatch exists', str_contains($aiHelpers, 'function ai_search_backend_dispatch'));
aiss_it('dispatch routes serpapi to serpapi_direct', str_contains($aiHelpers, "'serpapi' => ai_search_serpapi_direct"));
aiss_it('dispatch routes google_cse to stub', str_contains($aiHelpers, "'google_cse' => ai_search_google_cse_direct"));
aiss_it('dispatch routes bing to stub', str_contains($aiHelpers, "'bing' => ai_search_bing_direct"));

// Stub functions
aiss_it('google_cse stub exists', str_contains($aiHelpers, 'function ai_search_google_cse_direct'));
aiss_it('bing stub exists', str_contains($aiHelpers, 'function ai_search_bing_direct'));
aiss_it('stubs log warning on call', str_contains($aiHelpers, 'not yet implemented'));

// Unimplemented backend check
aiss_it('capability handler checks unimplemented backends', str_contains($aiHelpers, 'in_array'));
aiss_it('unimplemented backend returns disclosure', str_contains($aiHelpers, 'not yet implemented'));

// internet_search_backend wired from settings
aiss_it('discovery passes internet_search_backend to capability', str_contains($discovery, "'internet_search_backend'"));
aiss_it('discovery reads from settings', str_contains($discovery, "\$settings['internet_search_backend']"));

// Capability handler uses backend from payload only (not hardcoded)
aiss_it('capability handler reads backend from payload', str_contains($aiHelpers, "\$payload['internet_search_backend']"));

// module.json backend setting
aiss_it('module.json has internet_search_backend setting', str_contains($moduleJson, '"key": "internet_search_backend"'));
aiss_it('module.json backend default is serpapi', str_contains($moduleJson, '"default": "serpapi"') && strpos($moduleJson, '"key": "internet_search_backend"') < strpos($moduleJson, '"default": "serpapi"'));

echo "\n── Results: {$pass} passed, {$fail} failed ──\n";
exit($fail > 0 ? 1 : 0);
