<?php
declare(strict_types=1);

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

echo "\n=== Academic Similarity — Report Highlighting Integration ===\n";

// Test that the report service, generator, and highlight service
// work together to produce highlighted reports.

// ── Report data model (simulated) ──

$submission = [
    'submission_id' => 1,
    'submission_title' => 'Test Research Paper',
    'author_name' => 'Jane Doe',
    'word_count' => 200,
    'status' => 'processed',
    'source_type' => 'upload',
    'original_filename' => 'paper.docx',
    'submitted_at' => '2026-07-22 10:00:00',
];

$reportRecord = [
    'id' => 100,
    'submission_id' => 1,
    'report_version' => '1.0.0',
    'match_engine_version' => '1.0.0',
    'raw_score' => 25.0,
    'adjusted_score' => 20.0,
    'total_matches' => 3,
    'total_excluded' => 1,
    'matched_word_count' => 50,
    'total_eligible_words' => 200,
    'generated_at' => '2026-07-22 10:05:00',
];

$matches = [
    [
        'id' => 1, 'submission_id' => 1, 'source_id' => 10,
        'match_type' => 'exact', 'match_confidence' => 1.0,
        'matched_word_count' => 25,
        'submission_word_range_start' => 0, 'submission_word_range_end' => 24,
        'source_word_range_start' => 0, 'source_word_range_end' => 24,
        'segment_match_count' => 1, 'is_excluded' => 0,
    ],
    [
        'id' => 2, 'submission_id' => 1, 'source_id' => 11,
        'match_type' => 'near-exact', 'match_confidence' => 0.82,
        'matched_word_count' => 15,
        'submission_word_range_start' => 30, 'submission_word_range_end' => 44,
        'source_word_range_start' => 10, 'source_word_range_end' => 24,
        'segment_match_count' => 1, 'is_excluded' => 0,
    ],
    [
        'id' => 3, 'submission_id' => 1, 'source_id' => 12,
        'match_type' => 'semantic', 'match_confidence' => 0.72,
        'matched_word_count' => 10,
        'submission_word_range_start' => 60, 'submission_word_range_end' => 69,
        'source_word_range_start' => 5, 'source_word_range_end' => 14,
        'segment_match_count' => 1, 'is_excluded' => 1,
    ],
];

$evidenceMap = [
    1 => [
        ['id' => 101, 'match_id' => 1,
         'submission_segment_text' => 'The quick brown fox jumps over the lazy dog near the riverbank',
         'source_segment_text' => 'The quick brown fox jumps over the lazy dog near the riverbank',
         'submission_start_offset' => 0, 'submission_end_offset' => 59,
         'source_start_offset' => 0, 'source_end_offset' => 59,
         'overlap_resolution_order' => 1],
    ],
    2 => [
        ['id' => 102, 'match_id' => 2,
         'submission_segment_text' => 'A slightly modified version of the text with some word changes',
         'source_segment_text' => 'The original version of the text with some different word choices',
         'submission_start_offset' => 120, 'submission_end_offset' => 180,
         'source_start_offset' => 80, 'source_end_offset' => 140,
         'overlap_resolution_order' => 1],
    ],
    3 => [
        ['id' => 103, 'match_id' => 3,
         'submission_segment_text' => 'Students who engage in regular study habits perform better academically',
         'source_segment_text' => 'Consistent study habits correlate with improved academic performance',
         'submission_start_offset' => 250, 'submission_end_offset' => 310,
         'source_start_offset' => 40, 'source_end_offset' => 100,
         'overlap_resolution_order' => 1],
    ],
];

// ── Highlight Service Integration ──

$service = new AcademicSimilarityHighlightService('test-tenant');
$sourceCache = [
    10 => ['id' => 10, 'title' => 'Source Document A', 'author' => 'Dr. Smith'],
    11 => ['id' => 11, 'title' => 'Reference Paper B', 'author' => 'Prof. Jones'],
    12 => ['id' => 12, 'title' => 'Journal Article C', 'author' => 'Dr. Brown'],
];

$highlightData = $service->buildSpans(1, $matches, $evidenceMap, $submission, $sourceCache);
$spans = $highlightData['spans'];
$legend = $highlightData['legend'];
$stats = $highlightData['stats'];

t('integration: buildSpans returns spans', is_array($spans) && count($spans) > 0);
t('integration: buildSpans returns legend', count($legend) > 0);
t('integration: legend has exact entry', count(array_filter($legend, fn(array $l): bool => $l['type'] === 'exact')) > 0);
t('integration: legend has near-exact entry', count(array_filter($legend, fn(array $l): bool => $l['type'] === 'near-exact')) > 0);
t('integration: legend has excluded entry', count(array_filter($legend, fn(array $l): bool => $l['type'] === 'excluded')) > 0);

// Verify span metadata from source cache
$exactSpans = array_values(array_filter($spans, fn(AcademicSimilarityHighlightSpan $s): bool => $s->matchId === 1));
t('integration: exact match span has source title', $exactSpans[0]->sourceTitle === 'Source Document A');

// ── Render highlighted text ──

$testText = 'The quick brown fox jumps over the lazy dog near the riverbank. ';
$testText .= 'This is a unique sentence that does not match any source. ';
$testText .= 'A slightly modified version of the text with some word changes. ';
$testText .= 'Another unmatched sentence for padding the document. ';
$testText .= 'Students who engage in regular study habits perform better academically.';

$html = $service->renderHighlightedText($testText, $spans);
t('integration: rendered HTML is non-empty', $html !== '');
t('integration: rendered HTML contains mark tags', str_contains($html, '<mark'));
t('integration: rendered HTML escapes text', str_contains($html, 'The quick brown fox'));
t('integration: rendered HTML has hl-exact class', str_contains($html, 'hl-exact'));
t('integration: rendered HTML has hl-near class', str_contains($html, 'hl-near'));

// ── Assemble matched passages ──

$passages = $service->assembleMatchedPassages($spans, $matches, $evidenceMap);
t('integration: passages array correct length', count($passages) === 3, 'got: ' . count($passages));
t('integration: passage has submission text', $passages[0]['submission_text'] !== '');
t('integration: passage has source text', $passages[0]['source_text'] !== '');
t('integration: passage has highlight_labels', count($passages[0]['highlight_labels']) > 0);

// Verify excluded match data
$excludedPassage = array_values(array_filter($passages, fn(array $p): bool => $p['id'] === 3));
t('integration: excluded passage has is_excluded=true', $excludedPassage[0]['is_excluded'] === true, 'got: ' . ($excludedPassage[0]['is_excluded'] ? 'true' : 'false'));

// ── Report Generator buildHtml with highlight data ──

$reportData = [
    'submission' => $submission,
    'scores' => ['raw_score' => 25.0, 'adjusted_score' => 20.0],
    'total_matches' => 3,
    'total_excluded' => 1,
    'source_breakdown' => [],
    'matches' => [],
    'generated_at' => date('Y-m-d H:i:s'),
];

$generator = new AcademicSimilarityReportGenerator('test-tenant');
$highlightForDownload = [
    'legend' => $legend,
    'highlighted_html' => $html,
    'source_panels' => $service->renderSourcePanels($spans, []),
];

$downloadHtml = $generator->buildHtml($reportData, $highlightForDownload);
t('integration: download HTML contains score', str_contains($downloadHtml, '25.0'));
t('integration: download HTML contains highlighted text', str_contains($downloadHtml, '<mark'));
t('integration: download HTML contains legend', str_contains($downloadHtml, 'hl-legend'));
t('integration: download HTML contains disclaimer', str_contains($downloadHtml, 'academic misconduct'));
t('integration: download HTML footer has engine info', str_contains($downloadHtml, 'v1.0.0'));

// ── XSS Safety ──

$xssText = '<script>alert("xss")</script>';
$xssSpans = [
    new AcademicSimilarityHighlightSpan([
        'submission_id' => 1, 'match_id' => 99, 'side' => 'submission',
        'type' => 'exact', 'word_start' => 0, 'word_end' => 0,
        'precedence' => 80, 'css_token' => 'hl-exact', 'label' => 'Exact Copy',
    ]),
];
$xssHtml = $service->renderHighlightedText($xssText, $xssSpans);
t('XSS: script tags are escaped', !str_contains($xssHtml, '<script>'));
t('XSS: HTML entities are encoded', str_contains($xssHtml, '&lt;script&gt;') || str_contains($xssHtml, '&lt;'));

// Check logs
$appLog = file_get_contents(__DIR__ . '/../storage/logs/app.log');
$errorLog = file_get_contents(__DIR__ . '/../storage/logs/error.log');
t('app.log has no critical entries', !str_contains($appLog, '[critical]'), trim($appLog));
t('error.log is empty', trim($errorLog) === '', trim($errorLog));

echo "\n── Results: {$pass} passed, {$fail} failed ──\n";
exit($fail > 0 ? 1 : 0);
