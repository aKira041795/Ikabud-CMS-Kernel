<?php
declare(strict_types=1);

/**
 * Tests for weighted scoring: contiguous bonus, match-type weights,
 * source diversity factor, and source breakdown.
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

echo "\n=== Academic Similarity — Weighted Scoring ===\n";

$scoring = new AcademicSimilarityScoringService('test');

// ── Weighted score: single exact match ──
echo "\n--- Weighted Score: Single Exact Match ---\n";

$match1 = new AcademicSimilarityMatchResult([
    'submission_id' => 1, 'source_id' => 10, 'match_type' => 'exact',
    'confidence' => 1.0, 'matched_word_count' => 50,
    'submission_word_range_start' => 0, 'submission_word_range_end' => 49,
    'source_word_range_start' => 0, 'source_word_range_end' => 49,
    'segment_match_count' => 1, 'evidence' => [],
]);

$weighted = $scoring->calculateWeightedScore([$match1], 100, false);
t('weighted score is positive for exact match', $weighted > 0, 'got: ' . $weighted);
t('weighted score <= 100%', $weighted <= 100.0, 'got: ' . $weighted);

// ── Weighted score: match-type weights ──
echo "\n--- Weighted Score: Match-Type Weights ---\n";

$exactMatch = new AcademicSimilarityMatchResult([
    'submission_id' => 2, 'source_id' => 20, 'match_type' => 'exact',
    'confidence' => 1.0, 'matched_word_count' => 20,
    'submission_word_range_start' => 0, 'submission_word_range_end' => 19,
    'source_word_range_start' => 0, 'source_word_range_end' => 19,
    'segment_match_count' => 1, 'evidence' => [],
]);
$nearMatch = new AcademicSimilarityMatchResult([
    'submission_id' => 2, 'source_id' => 20, 'match_type' => 'near-exact',
    'confidence' => 0.85, 'matched_word_count' => 20,
    'submission_word_range_start' => 20, 'submission_word_range_end' => 39,
    'source_word_range_start' => 0, 'source_word_range_end' => 19,
    'segment_match_count' => 1, 'evidence' => [],
]);

$exactScore = $scoring->calculateWeightedScore([$exactMatch], 200, false);
$nearScore = $scoring->calculateWeightedScore([$nearMatch], 200, false);
t('exact match scores higher than near-exact for same word count', $exactScore > $nearScore,
    'exact: ' . $exactScore . ', near: ' . $nearScore);

// ── Source breakdown ──
echo "\n--- Source Breakdown ---\n";

$multiSourceMatches = [
    new AcademicSimilarityMatchResult([
        'submission_id' => 3, 'source_id' => 30, 'match_type' => 'exact',
        'confidence' => 1.0, 'matched_word_count' => 30,
        'submission_word_range_start' => 0, 'submission_word_range_end' => 29,
        'source_word_range_start' => 0, 'source_word_range_end' => 29,
        'segment_match_count' => 1, 'evidence' => [],
    ]),
    new AcademicSimilarityMatchResult([
        'submission_id' => 3, 'source_id' => 31, 'match_type' => 'exact',
        'confidence' => 1.0, 'matched_word_count' => 20,
        'submission_word_range_start' => 30, 'submission_word_range_end' => 49,
        'source_word_range_start' => 0, 'source_word_range_end' => 19,
        'segment_match_count' => 1, 'evidence' => [],
    ]),
];

$breakdown = $scoring->buildSourceBreakdown($multiSourceMatches);
t('source breakdown has 2 entries', count($breakdown) === 2, 'got: ' . count($breakdown));
t('first source has more matched words', $breakdown[0]['matched_words'] >= $breakdown[1]['matched_words']);
t('source breakdown contains source_id', isset($breakdown[0]['source_id']));
t('source breakdown contains matched_words', isset($breakdown[0]['matched_words']));
t('source breakdown contains weighted_contribution', isset($breakdown[0]['weighted_contribution']));
t('source breakdown contains match_types', isset($breakdown[0]['match_types']));

// ── Weighted vs unweighted comparison ──
echo "\n--- Weighted vs Unweighted Comparison ---\n";

// Unweighted: each word counted once regardless of contiguity
$unweighted = $scoring->calculateUniqueCoverage([$match1], 100);
t('unweighted unique words matches input', $unweighted['unique_matched_words'] === 50);

// Weighted: contiguous 50-word run gets bonus
$weightedScore = $scoring->calculateWeightedScore([$match1], 100, false);
t('weighted score differs from unweighted for contiguous match',
    abs($weightedScore - 50.0) > 0.01,
    'weighted: ' . $weightedScore . ', unweighted would be 50');

// ── Empty / edge cases ──
echo "\n--- Edge Cases ---\n";

$emptyScore = $scoring->calculateWeightedScore([], 100, false);
t('empty matches give 0 weighted score', $emptyScore === 0.0);

$zeroWords = $scoring->calculateWeightedScore([$match1], 0, false);
t('zero eligible words gives 0 weighted score', $zeroWords === 0.0);

t('empty breakdown is empty array', $scoring->buildSourceBreakdown([]) === []);

// ── Log checks ──
$appLog = file_get_contents(__DIR__ . '/../storage/logs/app.log');
$errorLog = file_get_contents(__DIR__ . '/../storage/logs/error.log');
t('app.log has no critical entries', !str_contains($appLog, '[critical]'), trim($appLog));
t('error.log is empty', trim($errorLog) === '', trim($errorLog));

echo "\n── Results: {$pass} passed, {$fail} failed ──\n";
exit($fail > 0 ? 1 : 0);
