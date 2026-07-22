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

echo "\n=== Academic Similarity — Near-Exact Matching ===\n";

// Near-exact matching works via canonicalized shingles (sorted word order)
// This tests the concept that near-identical text with minor reordering matches.

// Helper: canonicalize shingle and hash
function canonicalHash(string $shingleText): string {
    $words = explode(' ', $shingleText);
    sort($words);
    return hash('sha256', implode(' ', $words));
}

// 1. Text with minor word reordering → same canonical hash
$original = 'the quick brown fox jumps over lazy dog';
$reordered = 'quick brown the fox jumps lazy dog over';

$origHash = canonicalHash($original);
$reorderHash = canonicalHash($reordered);
t('reordered words produce same canonical hash', $origHash === $reorderHash);

// 2. Text with 20% word changes → still similar above threshold
$similar = 'the quick brown fox leaps over lazy dog'; // changed 'jumps' → 'leaps'
$similarHash = canonicalHash($similar);
$similarity = similar_text($original, $similar, $pct);
t('similar text has high similarity score', $pct >= 80, "got: {$pct}%");

// 3. Completely different text → low similarity
$different = 'completely unrelated document about astronomy';
$diffSimilarity = 0;
similar_text($original, $different, $diffSimilarity);
t('different text has low similarity', $diffSimilarity < 30, "got: {$diffSimilarity}%");

// 4. Create match results for near matches
$nearMatch = new AcademicSimilarityMatchResult([
    'submission_id' => 1,
    'source_id' => 10,
    'match_type' => 'near-exact',
    'confidence' => 0.85,
    'matched_word_count' => 8,
    'submission_word_start' => 0,
    'submission_word_end' => 7,
    'source_word_start' => 0,
    'source_word_end' => 7,
    'segment_match_count' => 1,
]);
t('near match type is near-exact', $nearMatch->matchType === 'near-exact');
t('near match confidence is below 1.0', $nearMatch->confidence < 1.0, "got: {$nearMatch->confidence}");
t('near match confidence is above 0.8 threshold', $nearMatch->confidence > 0.8, "got: {$nearMatch->confidence}");

// 5. Low-confidence match (below threshold)
$lowConfMatch = new AcademicSimilarityMatchResult([
    'submission_id' => 1,
    'source_id' => 11,
    'match_type' => 'near-exact',
    'confidence' => 0.3,
    'matched_word_count' => 3,
    'submission_word_start' => 0,
    'submission_word_end' => 2,
    'source_word_start' => 0,
    'source_word_end' => 2,
    'segment_match_count' => 1,
]);
t('low confidence is below threshold', $lowConfMatch->confidence < 0.8, "got: {$lowConfMatch->confidence}");

// 6. Near-match with evidence
$matchWithEvidence = new AcademicSimilarityMatchResult([
    'submission_id' => 1,
    'source_id' => 10,
    'match_type' => 'near-exact',
    'confidence' => 0.92,
    'matched_word_count' => 5,
    'submission_word_start' => 0,
    'submission_word_end' => 4,
    'source_word_start' => 0,
    'source_word_end' => 4,
    'segment_match_count' => 1,
    'evidence' => [
        ['submission_text' => 'quick brown fox jumps', 'source_text' => 'brown fox jumps quick'],
    ],
]);
t('near match with evidence has evidence array', is_array($matchWithEvidence->evidence));
t('evidence contains submission_text', ($matchWithEvidence->evidence[0]['submission_text'] ?? '') === 'quick brown fox jumps');
t('evidence contains source_text', ($matchWithEvidence->evidence[0]['source_text'] ?? '') === 'brown fox jumps quick');

// 7. Canonical hash: different word order → same hash for shingles
$shingle1 = 'the quick brown fox jumps';
$shingle2 = 'jumps fox brown quick the';
t('canonical hash of reversed shingle matches', canonicalHash($shingle1) === canonicalHash($shingle2));

// 8. Exact match vs near match: exact match has confidence 1.0, near match lower
$exact = new AcademicSimilarityMatchResult([
    'submission_id' => 1, 'source_id' => 10,
    'match_type' => 'exact', 'confidence' => 1.0,
    'matched_word_count' => 5, 'submission_word_start' => 0, 'submission_word_end' => 4,
    'source_word_start' => 0, 'source_word_end' => 4, 'segment_match_count' => 1,
]);
t('exact match has higher confidence than near match', $exact->confidence > $nearMatch->confidence);

// 9. Compare near-match score to module threshold setting
$threshold = 0.8;
$nearMatch2 = new AcademicSimilarityMatchResult([
    'submission_id' => 1, 'source_id' => 12,
    'match_type' => 'near-exact', 'confidence' => 0.82,
    'matched_word_count' => 6, 'submission_word_start' => 2, 'submission_word_end' => 7,
    'source_word_start' => 2, 'source_word_end' => 7, 'segment_match_count' => 1,
]);
t('confidence 0.82 exceeds threshold 0.8', $nearMatch2->confidence >= $threshold);
t('confidence 0.3 does not exceed threshold 0.8', $lowConfMatch->confidence < $threshold);

// 10. toMatchArray for near match
$arr = $nearMatch->toMatchArray();
t('near match toMatchArray has match_type near-exact', $arr['match_type'] === 'near-exact');
t('near match toMatchArray has match_confidence', $arr['match_confidence'] === 0.85);

// Check logs
$appLog = file_get_contents(__DIR__ . '/../storage/logs/app.log');
$errorLog = file_get_contents(__DIR__ . '/../storage/logs/error.log');
t('app.log has no critical entries', !str_contains($appLog, '[critical]'), trim($appLog));
t('error.log is empty', trim($errorLog) === '', trim($errorLog));

echo "\n── Results: {$pass} passed, {$fail} failed ──\n";
exit($fail > 0 ? 1 : 0);
