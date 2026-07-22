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

echo "\n=== Academic Similarity — Segmentation ===\n";

// Test sentence splitting logic (the segmentation in PipelineService::runSegment
// uses preg_split('/(?<=[.!?])\s+/', ...))
$normalizer = new AcademicSimilarityNormalizationService('test-tenant');

// 1. Split text into sentences
$text = 'First sentence. Second sentence! Third sentence? Fourth.';
$sentences = preg_split('/(?<=[.!?])\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);
t('splits into 4 sentences', count($sentences) === 4, 'got: ' . count($sentences));
t('first sentence is correct', ($sentences[0] ?? '') === 'First sentence.', "got: '{$sentences[0]}'");
t('second sentence is correct', ($sentences[1] ?? '') === 'Second sentence!', "got: '{$sentences[1]}'");
t('third sentence is correct', ($sentences[2] ?? '') === 'Third sentence?', "got: '{$sentences[2]}'");
t('fourth sentence is correct', ($sentences[3] ?? '') === 'Fourth.', "got: '{$sentences[3]}'");

// 2. Handle abbreviations (Dr., Mr., etc.) — simple regex splits after Dr.
// because it sees ". " as a sentence boundary. More advanced abbreviation handling
// would require a dictionary. Documents current behavior.
$abbrText = 'Dr. Smith arrived. He was late.';
$abbrSentences = preg_split('/(?<=[.!?])\s+/', $abbrText, -1, PREG_SPLIT_NO_EMPTY);
t('abbreviation text splits into 3 segments (Dr. / Smith arrived. / He was late.)', count($abbrSentences) === 3, 'got: ' . count($abbrSentences) . ' sentences');

// 3. Single sentence (no punctuation)
$single = preg_split('/(?<=[.!?])\s+/', 'Hello world', -1, PREG_SPLIT_NO_EMPTY);
t('text without sentence boundaries is one segment', count($single) === 1, 'got: ' . count($single));

// 4. Empty text
$empty = preg_split('/(?<=[.!?])\s+/', '', -1, PREG_SPLIT_NO_EMPTY);
t('empty text produces empty array', count($empty) === 0, 'got: ' . count($empty));

// 5. Create AcademicSimilaritySegment objects and verify their properties
$seg1 = new AcademicSimilaritySegment([
    'index' => 0,
    'type' => 'sentence',
    'content' => 'First sentence.',
    'normalized_content' => 'first sentence',
    'word_count' => 2,
    'char_count' => 14,
    'original_start_offset' => 0,
    'original_end_offset' => 14,
    'normalized_start_offset' => 0,
    'normalized_end_offset' => 14,
    'is_quotation' => false,
    'is_bibliography' => false,
]);
t('segment index is set', $seg1->index === 0);
t('segment type is sentence', $seg1->type === 'sentence');
t('segment content is preserved', $seg1->content === 'First sentence.');
t('segment normalized content is set', $seg1->normalizedContent === 'first sentence');
t('segment word count is set', $seg1->wordCount === 2);
t('segment char count is set', $seg1->charCount === 14);

// 6. Segment with quotation flag
$segQuote = new AcademicSimilaritySegment([
    'index' => 1,
    'type' => 'sentence',
    'content' => '"Quoted text."',
    'normalized_content' => 'quoted text',
    'is_quotation' => true,
]);
t('quotation segment flag is true', $segQuote->isQuotation === true);
t('non-quotation segment flag is false', $seg1->isQuotation === false);

// 7. Segment with bibliography flag
$segBib = new AcademicSimilaritySegment([
    'index' => 2,
    'type' => 'sentence',
    'content' => 'References',
    'normalized_content' => 'references',
    'is_bibliography' => true,
]);
t('bibliography segment flag is true', $segBib->isBibliography === true);

// 8. Segment toArray preserves data
$arr = $seg1->toArray();
t('toArray returns array', is_array($arr));
t('toArray contains index key', isset($arr['index']) && $arr['index'] === 0);
t('toArray contains content key', isset($arr['content']) && $arr['content'] === 'First sentence.');
t('toArray contains offset keys', isset($arr['original_start_offset']) && isset($arr['original_end_offset']));

// 9. Multiple segments track offsets
$segments = [];
$offset = 0;
$testSentences = ['First part.', 'Second part.', 'Third part.'];
foreach ($testSentences as $i => $s) {
    $segments[] = new AcademicSimilaritySegment([
        'index' => $i,
        'type' => 'sentence',
        'content' => $s,
        'original_start_offset' => $offset,
        'original_end_offset' => $offset + strlen($s),
    ]);
    $offset += strlen($s) + 1;
}
t('first segment starts at offset 0', $segments[0]->originalStartOffset === 0);
t('second segment starts after first + space', $segments[1]->originalStartOffset === 12, "got: {$segments[1]->originalStartOffset}"); // "First part." = 11 chars + 1 space
t('third segment starts at correct offset', $segments[2]->originalStartOffset === 25, "got: {$segments[2]->originalStartOffset}");

// 10. Normalization of each segment
$normalizedSegments = [];
foreach ($testSentences as $i => $s) {
    $norm = $normalizer->normalizeForComparison($s);
    $normalizedSegments[] = new AcademicSimilaritySegment([
        'index' => $i,
        'type' => 'sentence',
        'content' => $s,
        'normalized_content' => $norm,
        'word_count' => str_word_count($norm),
    ]);
}
t('segment normalization strips punctuation', $normalizedSegments[0]->normalizedContent === 'first part', "got: '{$normalizedSegments[0]->normalizedContent}'");
t('normalized word count is correct', $normalizedSegments[0]->wordCount === 2);

// Check logs
$appLog = file_get_contents(__DIR__ . '/../storage/logs/app.log');
$errorLog = file_get_contents(__DIR__ . '/../storage/logs/error.log');
t('app.log has no critical entries', !str_contains($appLog, '[critical]'), trim($appLog));
t('error.log is empty', trim($errorLog) === '', trim($errorLog));

echo "\n── Results: {$pass} passed, {$fail} failed ──\n";
exit($fail > 0 ? 1 : 0);
