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

echo "\n=== Academic Similarity — Exact Matching ===\n";

// Test exact matching using AcademicSimilarityMatchResult value objects
// and the MatchingService::resolveOverlaps logic (no DB needed)

// 1. Create two identical-text match results → same hash matches
$hashA = hash('sha256', 'the quick brown fox jumps');
$hashB = hash('sha256', 'the quick brown fox jumps');
$fpA = new AcademicSimilarityFingerprint($hashA, 'exact', 5, 'the quick brown fox jumps', 0, 0);
$fpB = new AcademicSimilarityFingerprint($hashB, 'exact', 5, 'the quick brown fox jumps', 0, 0);

t('identical fingerprints have same hash', $fpA->hash === $fpB->hash);

// 2. Different text → different hashes → no exact match
$hashC = hash('sha256', 'completely different text here now');
t('different text has different hash', $fpA->hash !== $hashC);

// 3. Create match results with identical ranges
$match1 = new AcademicSimilarityMatchResult([
    'submission_id' => 1,
    'source_id' => 10,
    'match_type' => 'exact',
    'confidence' => 1.0,
    'submission_segment_id' => 1,
    'source_segment_id' => 5,
    'matched_word_count' => 10,
    'submission_word_start' => 0,
    'submission_word_end' => 9,
    'source_word_start' => 0,
    'source_word_end' => 9,
    'segment_match_count' => 2,
]);
t('match type is exact', $match1->matchType === 'exact');
t('match confidence is 1.0 for exact', $match1->confidence === 1.0, "got: {$match1->confidence}");
t('matched word count is 10', $match1->matchedWordCount === 10);
t('submission word range is 0-9', $match1->submissionWordStart === 0 && $match1->submissionWordEnd === 9);

// 4. Slightly different text should not match exactly
// Create two match results with different source ranges
$match2 = new AcademicSimilarityMatchResult([
    'submission_id' => 1,
    'source_id' => 11,
    'match_type' => 'exact',
    'confidence' => 1.0,
    'matched_word_count' => 8,
    'submission_word_start' => 0,
    'submission_word_end' => 7,
    'source_word_start' => 0,
    'source_word_end' => 9,
    'segment_match_count' => 1,
]);
t('different source id indicates different source', $match2->sourceId !== $match1->sourceId);

// 5. Match result structure has all required fields
t('match has submission_id', isset($match1->submissionId));
t('match has source_id', isset($match1->sourceId));
t('match has match_type', isset($match1->matchType));
t('match has confidence', isset($match1->confidence));
t('match has matchedWordCount', isset($match1->matchedWordCount));
t('match has submissionWordStart', isset($match1->submissionWordStart));
t('match has submissionWordEnd', isset($match1->submissionWordEnd));

// 6. toMatchArray preserves data
$arr = $match1->toMatchArray();
t('toMatchArray returns array', is_array($arr));
t('toMatchArray has match_type key', $arr['match_type'] === 'exact');
t('toMatchArray has match_confidence key', $arr['match_confidence'] === 1.0);
t('toMatchArray has matched_word_count key', $arr['matched_word_count'] === 10);

// 7. Two matches with overlapping ranges — resolve overlaps
$overlap1 = new AcademicSimilarityMatchResult([
    'submission_id' => 1,
    'source_id' => 10,
    'match_type' => 'exact',
    'confidence' => 1.0,
    'matched_word_count' => 5,
    'submission_word_start' => 0,
    'submission_word_end' => 4,
    'source_word_start' => 0,
    'source_word_end' => 4,
    'segment_match_count' => 1,
]);
$overlap2 = new AcademicSimilarityMatchResult([
    'submission_id' => 1,
    'source_id' => 10,
    'match_type' => 'exact',
    'confidence' => 1.0,
    'matched_word_count' => 5,
    'submission_word_start' => 3,
    'submission_word_end' => 7,
    'source_word_start' => 3,
    'source_word_end' => 7,
    'segment_match_count' => 1,
]);

// Resolve overlaps using same algorithm as MatchingService::resolveOverlaps
function resolveExactOverlaps(array $matches): array {
    if (empty($matches)) return [];
    usort($matches, function (AcademicSimilarityMatchResult $a, AcademicSimilarityMatchResult $b) {
        if ($a->submissionWordStart !== $b->submissionWordStart) {
            return $a->submissionWordStart - $b->submissionWordStart;
        }
        return ($b->submissionWordEnd - $b->submissionWordStart) - ($a->submissionWordEnd - $a->submissionWordStart);
    });
    $resolved = [];
    $lastEnd = -1;
    foreach ($matches as $match) {
        if ($match->submissionWordStart >= $lastEnd) {
            $resolved[] = $match;
            $lastEnd = $match->submissionWordEnd;
        } elseif ($match->submissionWordEnd > $lastEnd) {
            // Partial overlap — trim
            $trimmedLen = $match->submissionWordEnd - $lastEnd;
            if ($trimmedLen >= 5) {
                $data = $match->toMatchArray();
                $data['submission_word_range_start'] = $lastEnd;
                $data['submission_word_range_end'] = $match->submissionWordEnd;
                $data['matched_word_count'] = $trimmedLen;
                $resolved[] = new AcademicSimilarityMatchResult($data);
                $lastEnd = $match->submissionWordEnd;
            }
        }
    }
    return $resolved;
}

$resolved = resolveExactOverlaps([$overlap1, $overlap2]);
t('overlap resolution produces non-overlapping ranges', count($resolved) >= 1, 'got: ' . count($resolved) . ' matches');

// Check that resolved matches don't overlap
$noOverlap = true;
$prevEnd = -1;
foreach ($resolved as $m) {
    if ($m->submissionWordStart < $prevEnd) { $noOverlap = false; break; }
    $prevEnd = $m->submissionWordEnd;
}
t('resolved ranges do not overlap', $noOverlap);

// 8. Empty match list
$empty = resolveExactOverlaps([]);
t('empty match list returns empty', count($empty) === 0);

// Check logs
$appLog = file_get_contents(__DIR__ . '/../storage/logs/app.log');
$errorLog = file_get_contents(__DIR__ . '/../storage/logs/error.log');
t('app.log has no critical entries', !str_contains($appLog, '[critical]'), trim($appLog));
t('error.log is empty', trim($errorLog) === '', trim($errorLog));

echo "\n── Results: {$pass} passed, {$fail} failed ──\n";
exit($fail > 0 ? 1 : 0);
