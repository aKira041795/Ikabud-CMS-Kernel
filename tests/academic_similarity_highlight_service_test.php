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

echo "\n=== Academic Similarity — Highlight Service ===\n";

// ── HighlightSpan Value Object ──

// 1. Default exact match span
$span = new AcademicSimilarityHighlightSpan([
    'submission_id' => 1,
    'source_id' => 10,
    'match_id' => 101,
    'side' => 'submission',
    'type' => 'exact',
    'confidence' => 1.0,
    'word_start' => 0,
    'word_end' => 9,
    'char_start' => 0,
    'char_end' => 49,
]);
t('span type is exact', $span->type === 'exact');
t('span cssToken defaults to hl-exact', $span->cssToken === 'hl-exact');
t('span label defaults to Exact Copy', $span->label === 'Exact Copy');
t('span precedence is 80 for exact', $span->precedence === 80);
t('span tooltip includes confidence', str_contains($span->tooltip, '100%'));
t('span tooltip includes word count', str_contains($span->tooltip, '10 words'));

// 2. Excluded match span
$excludedSpan = new AcademicSimilarityHighlightSpan([
    'submission_id' => 1,
    'match_id' => 102,
    'side' => 'submission',
    'type' => 'excluded',
    'confidence' => 1.0,
    'word_start' => 0,
    'word_end' => 4,
]);
t('excluded span precedence is 100', $excludedSpan->precedence === 100);
t('excluded span css is hl-excluded', $excludedSpan->cssToken === 'hl-excluded');
t('excluded span label is Excluded', $excludedSpan->label === 'Excluded');
t('excluded span has higher precedence than exact', $excludedSpan->precedence > $span->precedence);

// 3. Quotation span
$quoteSpan = new AcademicSimilarityHighlightSpan([
    'submission_id' => 1,
    'match_id' => 103,
    'side' => 'submission',
    'type' => 'quotation',
    'confidence' => 0.95,
    'word_start' => 10,
    'word_end' => 14,
]);
t('quotation precedence is 90', $quoteSpan->precedence === 90);
t('quotation css is hl-quote', $quoteSpan->cssToken === 'hl-quote');
t('quotation between excluded and exact', $quoteSpan->precedence < 100 && $quoteSpan->precedence > 80);

// 4. Near-exact span
$nearSpan = new AcademicSimilarityHighlightSpan([
    'submission_id' => 1,
    'match_id' => 104,
    'side' => 'submission',
    'type' => 'near-exact',
    'confidence' => 0.85,
    'word_start' => 5,
    'word_end' => 14,
]);
t('near-exact precedence is 70', $nearSpan->precedence === 70);
t('near-exact css is hl-near', $nearSpan->cssToken === 'hl-near');

// 5. Semantic span
$semSpan = new AcademicSimilarityHighlightSpan([
    'submission_id' => 1,
    'match_id' => 105,
    'side' => 'submission',
    'type' => 'semantic',
    'confidence' => 0.72,
    'word_start' => 20,
    'word_end' => 24,
]);
t('semantic precedence is 60', $semSpan->precedence === 60);

// 6. Statistical span
$statSpan = new AcademicSimilarityHighlightSpan([
    'submission_id' => 1,
    'match_id' => 106,
    'side' => 'submission',
    'type' => 'statistical',
    'confidence' => 0.55,
    'word_start' => 30,
    'word_end' => 34,
]);
t('statistical precedence is 50', $statSpan->precedence === 50);

// 7. Level detection by word count
$wordSpan = new AcademicSimilarityHighlightSpan([
    'submission_id' => 1, 'match_id' => 107, 'side' => 'submission',
    'type' => 'exact', 'word_start' => 0, 'word_end' => 1, 'level' => 'word',
]);
$phraseSpan = new AcademicSimilarityHighlightSpan([
    'submission_id' => 1, 'match_id' => 108, 'side' => 'submission',
    'type' => 'exact', 'word_start' => 0, 'word_end' => 6, 'level' => 'phrase',
]);
t('2-word span level is word', $wordSpan->level === 'word');
t('7-word span level is phrase', $phraseSpan->level === 'phrase');

// 8. toArray preserves data
$arr = $span->toArray();
t('toArray has submission_id', isset($arr['submission_id']));
t('toArray has type', isset($arr['type']));
t('toArray has css_token', isset($arr['css_token']));
t('toArray has tooltip', isset($arr['tooltip']));

// 9. Custom CSS and label override
$customSpan = new AcademicSimilarityHighlightSpan([
    'submission_id' => 1, 'match_id' => 109, 'side' => 'submission',
    'type' => 'exact',
    'css_token' => 'my-custom-css',
    'label' => 'Custom Label',
]);
t('custom css token is respected', $customSpan->cssToken === 'my-custom-css');
t('custom label is respected', $customSpan->label === 'Custom Label');

// ── HighlightService ──

$service = new AcademicSimilarityHighlightService('test-tenant');
t('HighlightService can be instantiated', $service instanceof AcademicSimilarityHighlightService);

// 10. buildSpans from match rows (no evidence)
$matches = [
    ['id' => 1, 'submission_id' => 1, 'source_id' => 10, 'match_type' => 'exact',
     'match_confidence' => 1.0, 'matched_word_count' => 5,
     'submission_word_range_start' => 0, 'submission_word_range_end' => 4,
     'source_word_range_start' => 0, 'source_word_range_end' => 4,
     'segment_match_count' => 1, 'is_excluded' => 0],
    ['id' => 2, 'submission_id' => 1, 'source_id' => 11, 'match_type' => 'near-exact',
     'match_confidence' => 0.85, 'matched_word_count' => 8,
     'submission_word_range_start' => 10, 'submission_word_range_end' => 17,
     'source_word_range_start' => 5, 'source_word_range_end' => 12,
     'segment_match_count' => 1, 'is_excluded' => 0],
];
$result = $service->buildSpans(1, $matches);
$spans = $result['spans'];
$legend = $result['legend'];
$stats = $result['stats'];

t('buildSpans returns spans array', is_array($spans));
t('buildSpans returns non-empty spans', count($spans) > 0);
t('buildSpans returns legend', count($legend) > 0);
t('buildSpans returns stats', isset($stats['total_spans']));
t('legend has exact entry', count(array_filter($legend, fn(array $l): bool => $l['type'] === 'exact')) > 0);
t('legend has near-exact entry', count(array_filter($legend, fn(array $l): bool => $l['type'] === 'near-exact')) > 0);

// 11. Source-side spans created when word ranges exist
$noSourceMatch = ['id' => 3, 'submission_id' => 1, 'source_id' => 12, 'match_type' => 'exact',
    'match_confidence' => 1.0, 'matched_word_count' => 3,
    'submission_word_range_start' => 0, 'submission_word_range_end' => 2,
    'source_word_range_start' => 0, 'source_word_range_end' => 0,  // zero range = no source span
    'segment_match_count' => 1, 'is_excluded' => 0];
$result2 = $service->buildSpans(1, [$noSourceMatch]);
$subSpans = array_filter($result2['spans'], fn(AcademicSimilarityHighlightSpan $s): bool => $s->side === 'submission');
$srcSpans = array_filter($result2['spans'], fn(AcademicSimilarityHighlightSpan $s): bool => $s->side === 'source');
t('submission span created for match', count($subSpans) === 1);
t('no source span when source word range is zero', count($srcSpans) === 0);

// 12. Source span created when source word ranges exist
$withSource = ['id' => 4, 'submission_id' => 1, 'source_id' => 13, 'match_type' => 'near-exact',
    'match_confidence' => 0.9, 'matched_word_count' => 5,
    'submission_word_range_start' => 0, 'submission_word_range_end' => 4,
    'source_word_range_start' => 10, 'source_word_range_end' => 14,
    'segment_match_count' => 1, 'is_excluded' => 0];
$result3 = $service->buildSpans(1, [$withSource]);
$srcSpans3 = array_values(array_filter($result3['spans'], fn(AcademicSimilarityHighlightSpan $s): bool => $s->side === 'source'));
t('source span created when word ranges exist', count($srcSpans3) === 1);
t('source span type matches match type', $srcSpans3[0]->type === 'near-exact');

// 13. Excluded match uses excluded type and precedence
$excludedMatch = ['id' => 5, 'submission_id' => 1, 'source_id' => 14, 'match_type' => 'exact',
    'match_confidence' => 1.0, 'matched_word_count' => 5,
    'submission_word_range_start' => 0, 'submission_word_range_end' => 4,
    'source_word_range_start' => 0, 'source_word_range_end' => 4,
    'segment_match_count' => 1, 'is_excluded' => 1];
$result4 = $service->buildSpans(1, [$excludedMatch]);
$excSpans = array_values(array_filter($result4['spans'], fn(AcademicSimilarityHighlightSpan $s): bool => $s->side === 'submission' && $s->type === 'excluded'));
t('excluded match produces excluded spans', count($excSpans) > 0);
t('excluded span precedence is 100', $excSpans[0]->precedence === 100);

// 14. renderHighlightedText without spans
$html = $service->renderHighlightedText('Hello world test text.', []);
t('render without spans returns no-matches div', str_contains($html, 'hl-text--no-matches'));

// 15. renderHighlightedText with spans
$testSpans = [
    new AcademicSimilarityHighlightSpan([
        'submission_id' => 1, 'match_id' => 1, 'side' => 'submission',
        'type' => 'exact', 'word_start' => 0, 'word_end' => 1,
        'char_start' => 0, 'char_end' => 10,
        'precedence' => 80, 'css_token' => 'hl-exact', 'label' => 'Exact Copy',
        'tooltip' => 'Exact Copy — 100% confidence, 2 words',
    ]),
];
$html = $service->renderHighlightedText('hello world', $testSpans);
t('render with spans produces mark tags', str_contains($html, '<mark'));
t('render with spans uses hl-exact css', str_contains($html, 'hl-exact'));
t('render escapes text content', str_contains($html, 'hello') && str_contains($html, 'world'), 'html: ' . substr($html, 0, 100));
t('render includes data attributes', str_contains($html, 'data-type="exact"'));
t('render includes aria-label', str_contains($html, 'aria-label='));

// 16. renderHighlightedText escapes HTML in text
$html = $service->renderHighlightedText('<script>alert("xss")</script>', []);
t('render escapes HTML entities', str_contains($html, '&lt;script&gt;') || str_contains($html, '&lt;'));

// 17. assembleMatchedPassages
$passages = $service->assembleMatchedPassages($spans, $matches);
t('assembled passages count matches input', count($passages) === count($matches));
t('passage has match_type', isset($passages[0]['match_type']));
t('passage has submission text', isset($passages[0]['submission_text']));
t('passage has highlight_labels', is_array($passages[0]['highlight_labels']));

// 18. buildLegend
$stats4 = ['total_spans' => 1, 'type_breakdown' => ['exact' => 1], 'submission_spans' => 1, 'source_spans' => 0];
$legend4 = $service->buildLegend($stats4, $testSpans);
t('legend includes exact', count(array_filter($legend4, fn(array $l): bool => $l['type'] === 'exact')) > 0);
t('legend includes all types', count($legend4) === 6);

// 19. renderSourcePanels with no source text
$panels = $service->renderSourcePanels($result3['spans'], []);
t('source panels returns array', is_array($panels));

// 20. Overlap resolution: higher precedence wins
$overlapTest = [
    new AcademicSimilarityHighlightSpan([
        'submission_id' => 1, 'match_id' => 1, 'side' => 'submission',
        'type' => 'exact', 'word_start' => 0, 'word_end' => 9,
        'precedence' => 80, 'css_token' => 'hl-exact', 'label' => 'Exact Copy',
    ]),
    new AcademicSimilarityHighlightSpan([
        'submission_id' => 1, 'match_id' => 2, 'side' => 'submission',
        'type' => 'excluded', 'word_start' => 3, 'word_end' => 6,
        'precedence' => 100, 'css_token' => 'hl-excluded', 'label' => 'Excluded',
    ]),
];
$resolved = $service->resolveOverlaps($overlapTest);
$excTokens = array_map(fn(AcademicSimilarityHighlightSpan $s): string => $s->cssToken, $resolved);
t('overlap with excluded wins at position overlap', in_array('hl-excluded', $excTokens, true));
// At least the non-overlapping parts of exact remain
$exactTokens = array_filter($resolved, fn(AcademicSimilarityHighlightSpan $s): bool => $s->cssToken === 'hl-exact');
t('non-overlapping exact parts remain', count($exactTokens) > 0);

// Check logs
$appLog = file_get_contents(__DIR__ . '/../storage/logs/app.log');
$errorLog = file_get_contents(__DIR__ . '/../storage/logs/error.log');
t('app.log has no critical entries', !str_contains($appLog, '[critical]'), trim($appLog));
t('error.log is empty', trim($errorLog) === '', trim($errorLog));

echo "\n── Results: {$pass} passed, {$fail} failed ──\n";
exit($fail > 0 ? 1 : 0);
