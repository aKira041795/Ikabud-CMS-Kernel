<?php
declare(strict_types=1);

require_once __DIR__ . '/../modules/academic_similarity/src/Services/AcademicSimilarityInternetDiscoveryService.php';
require_once __DIR__ . '/../modules/academic_similarity/src/Validators/AcademicSimilarityFileValidator.php';

$pass = 0;
$fail = 0;

function aissQueryTest(string $label, bool $condition): void
{
    global $pass, $fail;
    if ($condition) {
        $pass++;
        echo "PASS: {$label}\n";
        return;
    }

    $fail++;
    echo "FAIL: {$label}\n";
}

$service = new AcademicSimilarityInternetDiscoveryService('582');
$queries = $service->buildQueries(
    ['submission_title' => "Master's Thesis--Psychosocial Factors"],
    "😶‍🌫️ Research Paper PSYCHOSOCIAL FACTORS INFLUENCING THE MENTAL HEALTH OF GENERATION Z\n"
        . "Abstract\nPsychosocial factors influence the mental health and lived experiences of Generation Z. "
        . "The research examines social support, echo chambers, coping strategies, and guidance counseling. "
        . "Psychosocial factors influence mental health outcomes. Social support shapes coping strategies. "
        . "Echo chambers affect lived experiences. Rather than assuming outcomes, the researcher will examine evidence.\n"
        . "Introduction\nThe study explores these relationships.",
    [
        'internet_check_provider' => 'seed_urls',
        'internet_check_max_queries' => '5',
        'internet_check_allow_full_document_query' => '1',
    ]
);

aissQueryTest('deterministic fallback returns bounded queries', count($queries) >= 2 && count($queries) <= 5);
aissQueryTest('queries contain no emoji cover-page marker', !str_contains(implode(' ', $queries), '😶'));
aissQueryTest('ambiguous bigrams are never standalone queries', !in_array('Echo Chambers', $queries, true)
    && !in_array('Rather Than', $queries, true)
    && !in_array('Lived Experiences', $queries, true)
    && !in_array('Researcher Will', $queries, true));
aissQueryTest('fallback phrases retain thesis context', count(array_filter(
    $queries,
    static fn(string $query): bool => str_contains(strtolower($query), 'psychosocial')
)) >= 1);

$aiManifest = json_decode((string)file_get_contents(__DIR__ . '/../modules/ai/module.json'), true);
$textPolicy = $aiManifest['capabilities']['policy']['capabilities']['ai.text.generate@1']['allow_callers'] ?? [];
aissQueryTest('AI text generation policy allows academic-similarity', in_array('academic-similarity', $textPolicy, true));

$aissManifest = json_decode((string)file_get_contents(__DIR__ . '/../modules/academic_similarity/module.json'), true);
$engineSetting = null;
foreach (($aissManifest['settings_fields'] ?? []) as $setting) {
    if (($setting['key'] ?? '') === 'internet_search_engine') {
        $engineSetting = $setting;
        break;
    }
}
aissQueryTest('AISS defaults SerpAPI to Google Scholar', ($engineSetting['default'] ?? '') === 'google_scholar');

$queryModeSetting = null;
foreach (($aissManifest['settings_fields'] ?? []) as $setting) {
    if (($setting['key'] ?? '') === 'internet_query_generation_mode') {
        $queryModeSetting = $setting;
        break;
    }
}
aissQueryTest(
    'AISS defaults query generation to local-only mode',
    ($queryModeSetting['default'] ?? '') === 'local'
);

$temporaryFile = tempnam(sys_get_temp_dir(), 'aiss_test_');
file_put_contents($temporaryFile, str_repeat('Academic similarity capability upload content. ', 20));
$validator = new AcademicSimilarityFileValidator();
$file = [
    'tmp_name' => $temporaryFile,
    'name' => 'manuscript.txt',
    'size' => filesize($temporaryFile),
    'error' => UPLOAD_ERR_OK,
];
$untrusted = $validator->validate($file, ['max_file_size_mb' => '1']);
$trusted = $validator->validate($file, ['max_file_size_mb' => '1'], true);
aissQueryTest('ordinary local paths are rejected as browser uploads', empty($untrusted['ok']));
aissQueryTest('trusted capability temp files retain full file validation', !empty($trusted['ok']));
@unlink($temporaryFile);

$internetCheckSource = (string)file_get_contents(
    __DIR__ . '/../modules/academic_similarity/src/Services/AcademicSimilarityInternetCheckService.php'
);
aissQueryTest(
    'zero discovered candidates are an explicit coverage failure',
    str_contains($internetCheckSource, 'No academic source candidates were discovered; similarity coverage is incomplete')
        && str_contains($internetCheckSource, "'ok' => \$imported > 0")
);

$pipelineSource = (string)file_get_contents(
    __DIR__ . '/../modules/academic_similarity/src/Services/AcademicSimilarityPipelineService.php'
);
$ateAdapterSource = (string)file_get_contents(
    __DIR__ . '/../modules/academic_thesis_evaluation/src/Services/AcademicThesisAissAdapter.php'
);
aissQueryTest(
    'AISS check can skip external semantic text processing',
    str_contains($pipelineSource, 'external_text_processing_allowed')
        && str_contains($pipelineSource, "\$stage !== 'semantic_match'")
);
aissQueryTest(
    'ATE denies external text processing when it invokes AISS',
    str_contains($ateAdapterSource, "'external_text_processing_allowed' => false")
);
$ateManifest = json_decode(
    (string)file_get_contents(__DIR__ . '/../modules/academic_thesis_evaluation/module.json'),
    true
);
$ateConsumes = array_column($ateManifest['capabilities']['consumes'] ?? [], 'id');
aissQueryTest(
    'ATE declares the AISS capability contracts it consumes',
    in_array('academic_similarity.submit@1', $ateConsumes, true)
        && in_array('academic_similarity.check@1', $ateConsumes, true)
        && in_array('academic_similarity.report.view@1', $ateConsumes, true)
);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
