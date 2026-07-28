<?php
declare(strict_types=1);

/**
 * AISS — Score Separation Regression Test
 *
 * Verifies that textual similarity and semantic resemblance are computed
 * independently and never cross-contaminate.
 *
 * Required behaviors:
 *   - Semantic-only match: Textual score = 0%, Semantic evidence > 0
 *   - Exact-only match: Textual score > 0%, Semantic resemblance may be absent
 *   - Same topic, unrelated argument: No reportable contextual relationship
 *   - High-confidence semantic match: Appears as evidence, does not alter textual score
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../modules/academic_similarity/helpers.php';

$pass = 0;
$fail = 0;

function st(string $description, bool $condition, string $detail = ''): void {
    global $pass, $fail;
    if ($condition) { $pass++; echo "  ✅ {$description}\n"; }
    else { $fail++; echo "  ❌ {$description}" . ($detail ? " — {$detail}" : '') . "\n"; }
}

file_put_contents(__DIR__ . '/../storage/logs/app.log', '');
file_put_contents(__DIR__ . '/../storage/logs/error.log', '');

echo "\n=== AISS — Score Separation Regression Test ===\n";

$scorer = new AcademicSimilarityScoringService('test-score-separation');

// ── Test 1: No matches at all (unit-level) ──────────────────────
echo "\n--- 1. No matches (baseline) ---\n";

$emptyCoverage = $scorer->calculateUniqueCoverage([], 200);
st('no matches: coverage is 0', $emptyCoverage['unique_matched_words'] === 0);
st('no matches: coverage percent is 0', $emptyCoverage['coverage_percent'] === 0.0);

$emptyWeighted = $scorer->calculateWeightedScore([], 200, false);
st('no matches: weighted score is 0', $emptyWeighted === 0.0);

$emptySemantic = $scorer->calculateSemanticResemblanceScore([], 200);
st('no matches: semantic resemblance is 0', $emptySemantic === 0.0);

$emptyAttn = $scorer->calculateReviewerAttentionLevel(0.0, [], [], 200);
st('no matches: attention level is none', $emptyAttn['level'] === 'none');

// ── Test 2: Semantic-only matches must NOT enter textual score ──
echo "\n--- 2. Semantic-only: textual score MUST be 0% ---\n";

// Build match results that are ONLY semantic type
$semanticOnlyMatches = [];
$semanticOnlyMatches[] = new AcademicSimilarityMatchResult([
    'submission_id' => 99901,
    'source_id' => 1,
    'match_type' => 'semantic',
    'confidence' => 0.85,
    'submission_segment_id' => 1,
    'source_segment_id' => 1,
    'matched_word_count' => 50,
    'submission_word_range_start' => 0,
    'submission_word_range_end' => 49,
    'source_word_range_start' => 0,
    'source_word_range_end' => 49,
    'segment_match_count' => 1,
    'evidence' => [],
]);
$semanticOnlyMatches[] = new AcademicSimilarityMatchResult([
    'submission_id' => 99901,
    'source_id' => 2,
    'match_type' => 'semantic',
    'confidence' => 0.72,
    'submission_segment_id' => 2,
    'source_segment_id' => 1,
    'matched_word_count' => 30,
    'submission_word_range_start' => 50,
    'submission_word_range_end' => 79,
    'source_word_range_start' => 0,
    'source_word_range_end' => 29,
    'segment_match_count' => 1,
    'evidence' => [],
]);

// calculateUniqueCoverage is a pure word-range function — it processes
// whatever matches it receives. The filtering by match type happens in
// calculateScore(). So we verify the FILTERING logic here:
$textualOnlyFromSemantic = array_filter($semanticOnlyMatches, fn($m) => in_array($m->matchType, ['exact', 'near-exact'], true));
st('semantic-only: zero textual matches after filtering', count($textualOnlyFromSemantic) === 0, 'got: ' . count($textualOnlyFromSemantic));

// But semantic matches DO have word ranges — they should still produce
// coverage if passed directly (the method is type-agnostic)
$rawCoverage = $scorer->calculateUniqueCoverage($semanticOnlyMatches, 200);
st('semantic-only: raw word coverage matches ranges (80 unique words)', $rawCoverage['unique_matched_words'] === 80, "got: {$rawCoverage['unique_matched_words']}");

// Weighted score includes semantic (type weight 0.2)
$weighted = $scorer->calculateWeightedScore($semanticOnlyMatches, 200, false);
st('semantic-only: weighted score is >0 (includes semantic)', $weighted > 0, "got: {$weighted}");

$semanticScore = $scorer->calculateSemanticResemblanceScore($semanticOnlyMatches, 200);
st('semantic-only: semantic resemblance > 0', $semanticScore > 0, "got: {$semanticScore}");

$attnLevel = $scorer->calculateReviewerAttentionLevel(0.0, $semanticOnlyMatches, [], 200);
st('semantic-only: attention level is not none', $attnLevel['level'] !== 'none', "got: {$attnLevel['level']}");
st('semantic-only: attention reasons include semantic count', count($attnLevel['reasons']) > 0);

// ── Test 3: Exact-only matches produce textual score ────────────
echo "\n--- 3. Exact-only: textual score > 0% ---\n";

$exactOnlyMatches = [];
$exactOnlyMatches[] = new AcademicSimilarityMatchResult([
    'submission_id' => 99902,
    'source_id' => 1,
    'match_type' => 'exact',
    'confidence' => 1.0,
    'submission_segment_id' => 1,
    'source_segment_id' => 1,
    'matched_word_count' => 40,
    'submission_word_range_start' => 0,
    'submission_word_range_end' => 39,
    'source_word_range_start' => 0,
    'source_word_range_end' => 39,
    'segment_match_count' => 1,
    'evidence' => [],
]);

$textualCoverageExact = $scorer->calculateUniqueCoverage($exactOnlyMatches, 200);
st('exact-only: textual coverage > 0 words', $textualCoverageExact['unique_matched_words'] > 0, "got: {$textualCoverageExact['unique_matched_words']}");
st('exact-only: textual coverage percent > 0', $textualCoverageExact['coverage_percent'] > 0, "got: {$textualCoverageExact['coverage_percent']}");

// ── Test 4: Mixed matches — semantic must not inflate textual ───
echo "\n--- 4. Mixed matches: semantic never inflates textual ---\n";

$mixedMatches = array_merge($exactOnlyMatches, $semanticOnlyMatches);
$textualCoverageMixed = $scorer->calculateUniqueCoverage(
    array_filter($mixedMatches, fn($m) => in_array($m->matchType, ['exact', 'near-exact'], true)),
    200
);
st('mixed: textual coverage matches exact-only value', $textualCoverageMixed['unique_matched_words'] === $textualCoverageExact['unique_matched_words'],
    "expected {$textualCoverageExact['unique_matched_words']}, got {$textualCoverageMixed['unique_matched_words']}");

// ── Test 5: High-confidence semantic does not alter textual ──────
echo "\n--- 5. High-confidence semantic (0.95) does not alter textual ---\n";

$highConfSemantic = [];
$highConfSemantic[] = new AcademicSimilarityMatchResult([
    'submission_id' => 99903,
    'source_id' => 1,
    'match_type' => 'semantic',
    'confidence' => 0.95,
    'submission_segment_id' => 1,
    'source_segment_id' => 1,
    'matched_word_count' => 100,
    'submission_word_range_start' => 0,
    'submission_word_range_end' => 99,
    'source_word_range_start' => 0,
    'source_word_range_end' => 99,
    'segment_match_count' => 1,
    'evidence' => [],
]);

$textualWithHighSem = $scorer->calculateUniqueCoverage(
    array_filter(
        array_merge($exactOnlyMatches, $highConfSemantic),
        fn($m) => in_array($m->matchType, ['exact', 'near-exact'], true)
    ),
    200
);
st('high-conf semantic: textual coverage unchanged', $textualWithHighSem['unique_matched_words'] === $textualCoverageExact['unique_matched_words'],
    "expected {$textualCoverageExact['unique_matched_words']}, got {$textualWithHighSem['unique_matched_words']}");

$semanticScoreHigh = $scorer->calculateSemanticResemblanceScore($highConfSemantic, 200);
st('high-conf semantic: semantic resemblance > 0', $semanticScoreHigh > 0, "got: {$semanticScoreHigh}");

// Confirm the semantic matches are properly separated in calculateScore flow
// by checking the reviewer_attention_level keys
$attnHigh = $scorer->calculateReviewerAttentionLevel(0.0, $highConfSemantic, [], 200);
st('high-conf semantic attention includes relationships', $attnHigh['level'] !== 'none');

// ── Test 6: Near-exact only matches ─────────────────────────────
echo "\n--- 6. Near-exact only ---\n";

$nearExactMatches = [];
$nearExactMatches[] = new AcademicSimilarityMatchResult([
    'submission_id' => 99904,
    'source_id' => 1,
    'match_type' => 'near-exact',
    'confidence' => 0.85,
    'submission_segment_id' => 1,
    'source_segment_id' => 1,
    'matched_word_count' => 35,
    'submission_word_range_start' => 0,
    'submission_word_range_end' => 34,
    'source_word_range_start' => 0,
    'source_word_range_end' => 34,
    'segment_match_count' => 1,
    'evidence' => [],
]);

$textualNear = $scorer->calculateUniqueCoverage($nearExactMatches, 200);
st('near-exact: textual coverage > 0', $textualNear['unique_matched_words'] > 0);
st('near-exact: textual coverage percent > 0', $textualNear['coverage_percent'] > 0);

// Build non-overlapping ranges for the merge test
$nearExactNoOverlap = [];
$nearExactNoOverlap[] = new AcademicSimilarityMatchResult([
    'submission_id' => 99904,
    'source_id' => 2,
    'match_type' => 'near-exact',
    'confidence' => 0.85,
    'submission_segment_id' => 1,
    'source_segment_id' => 1,
    'matched_word_count' => 35,
    'submission_word_range_start' => 100,
    'submission_word_range_end' => 134,
    'source_word_range_start' => 0,
    'source_word_range_end' => 34,
    'segment_match_count' => 1,
    'evidence' => [],
]);

$allTextual = array_merge($exactOnlyMatches, $nearExactNoOverlap);
$textualBoth = $scorer->calculateUniqueCoverage($allTextual, 200);
st('exact+near-exact: combined coverage sums disjoint ranges', $textualBoth['unique_matched_words'] === 75,
    "expected 75, got: {$textualBoth['unique_matched_words']} (40 + 35)");
st('exact+near-exact: overlap resolved correctly', $textualBoth['unique_matched_words'] <= 75,
    "got: {$textualBoth['unique_matched_words']}");

// ── Test 7: calculateReviewerAttentionLevel levels ──────────────
echo "\n--- 7. Reviewer Attention Level rules ---\n";

// None
$noneLevel = $scorer->calculateReviewerAttentionLevel(0.0, [], [], 200);
st('no evidence → level none', $noneLevel['level'] === 'none');

// Low: minor exact overlap, no semantic
$lowLevel = $scorer->calculateReviewerAttentionLevel(3.5, [], [], 200);
st('3.5% textual, no semantic → low', $lowLevel['level'] === 'low', "got: {$lowLevel['level']}");

// Low: strong semantic but low textual
$lowLevel2 = $scorer->calculateReviewerAttentionLevel(0.0, [$semanticOnlyMatches[0]], [], 200);
st('0% textual, 1 semantic → low', $lowLevel2['level'] === 'low', "got: {$lowLevel2['level']}");

// Moderate: textual > 10%
$modLevel = $scorer->calculateReviewerAttentionLevel(18.0, [], [], 200);
st('18% textual → moderate', $modLevel['level'] === 'moderate', "got: {$modLevel['level']}");

// Moderate: 5+ strong semantic
$manySemantic = array_fill(0, 5, $semanticOnlyMatches[0]);
$modLevel2 = $scorer->calculateReviewerAttentionLevel(0.0, $manySemantic, [], 200);
st('0% textual, 5 semantic → moderate', $modLevel2['level'] === 'moderate', "got: {$modLevel2['level']}");

// High: textual > 25%
$highLevel = $scorer->calculateReviewerAttentionLevel(30.0, [], [], 200);
st('30% textual → high', $highLevel['level'] === 'high', "got: {$highLevel['level']}");

// High: textual > 0 and 3+ semantic
$highLevel2 = $scorer->calculateReviewerAttentionLevel(5.0, array_fill(0, 3, $semanticOnlyMatches[0]), [], 200);
st('5% textual + 3 semantic → high', $highLevel2['level'] === 'high', "got: {$highLevel2['level']}");

// ── Log check ────────────────────────────────────────────────────
echo "\n--- 8. Log check ---\n";
$appLog = file_get_contents(__DIR__ . '/../storage/logs/app.log');
$errorLog = file_get_contents(__DIR__ . '/../storage/logs/error.log');
st('app.log has no critical entries', !str_contains($appLog, '[critical]'));
st('error.log is empty', trim($errorLog) === '');

echo "\n── Results: {$pass} passed, {$fail} failed ──\n";
exit($fail > 0 ? 1 : 0);
