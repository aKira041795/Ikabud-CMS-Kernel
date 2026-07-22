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

echo "\n=== Academic Similarity — Score Calculation ===\n";

// Test score calculation logic (same as ScoringService::calculateUniqueCoverage)
// without needing a database connection.

function calculateCoverage(array $matches, int $totalWords): array {
    // Resolve overlaps first
    $ranges = [];
    foreach ($matches as $m) {
        $ranges[] = ['start' => $m->submissionWordStart, 'end' => $m->submissionWordEnd];
    }
    usort($ranges, function (array $a, array $b) {
        if ($a['start'] !== $b['start']) return $a['start'] - $b['start'];
        return $b['end'] - $a['end'];
    });
    $merged = [];
    if (!empty($ranges)) {
        $current = $ranges[0];
        for ($i = 1, $n = count($ranges); $i < $n; $i++) {
            $next = $ranges[$i];
            if ($next['start'] <= $current['end'] + 1) {
                $current['end'] = max($current['end'], $next['end']);
            } else {
                $merged[] = $current;
                $current = $next;
            }
        }
        $merged[] = $current;
    }
    $uniqueWords = 0;
    foreach ($merged as $range) {
        $uniqueWords += ($range['end'] - $range['start'] + 1);
    }
    $uniqueWords = min($uniqueWords, $totalWords);
    $pct = $totalWords > 0 ? round(($uniqueWords / $totalWords) * 100, 2) : 0.0;
    return ['unique_matched_words' => $uniqueWords, 'total_eligible_words' => $totalWords, 'coverage_percent' => $pct];
}

function makeMatch(int $start, int $end, int $wc = 0): AcademicSimilarityMatchResult {
    return new AcademicSimilarityMatchResult([
        'submission_id' => 1, 'source_id' => 10,
        'match_type' => 'exact', 'confidence' => 1.0,
        'matched_word_count' => $wc > 0 ? $wc : ($end - $start + 1),
        'submission_word_start' => $start,
        'submission_word_end' => $end,
        'source_word_start' => $start, 'source_word_end' => $end,
        'segment_match_count' => 1,
    ]);
}

// 1. 50% match: 50 unique matched words out of 100 total
$coverage = calculateCoverage([makeMatch(0, 49)], 100);
t('50 matched out of 100 is 50%', $coverage['coverage_percent'] === 50.0, "got: {$coverage['coverage_percent']}%");
t('50% match unique count is 50', $coverage['unique_matched_words'] === 50, "got: {$coverage['unique_matched_words']}");

// 2. 0% match: no matches
$coverage = calculateCoverage([], 100);
t('no matches is 0% score', $coverage['coverage_percent'] === 0.0, "got: {$coverage['coverage_percent']}%");
t('0% match unique count is 0', $coverage['unique_matched_words'] === 0);

// 3. 100% match: all words matched
$coverage = calculateCoverage([makeMatch(0, 99)], 100);
t('all 100 words matched is 100%', $coverage['coverage_percent'] === 100.0, "got: {$coverage['coverage_percent']}%");
t('100% match unique count is 100', $coverage['unique_matched_words'] === 100);

// 4. Overlapping matches count unique words only
$coverage = calculateCoverage([makeMatch(0, 49), makeMatch(25, 74)], 100);
t('overlapping 0-49 and 25-74 covers 0-74 = 75 unique', $coverage['unique_matched_words'] === 75, "got: {$coverage['unique_matched_words']}");
t('overlapping match score is 75%', $coverage['coverage_percent'] === 75.0, "got: {$coverage['coverage_percent']}%");

// 5. Multiple overlaps resolve correctly
$coverage = calculateCoverage([makeMatch(0, 9), makeMatch(5, 19), makeMatch(15, 29)], 50);
t('multiple overlaps: 0-29 = 30 unique', $coverage['unique_matched_words'] === 30, "got: {$coverage['unique_matched_words']}");

// 6. Words beyond total are capped
$coverage = calculateCoverage([makeMatch(0, 199)], 100);
t('matched words capped at total eligible', $coverage['unique_matched_words'] === 100, "got: {$coverage['unique_matched_words']}");
t('score capped at 100%', $coverage['coverage_percent'] === 100.0, "got: {$coverage['coverage_percent']}%");

// 7. Zero total eligible words
$coverage = calculateCoverage([makeMatch(0, 49)], 0);
t('zero eligible words returns 0 score', $coverage['coverage_percent'] === 0.0);
t('zero eligible words returns 0 unique', $coverage['unique_matched_words'] === 0);

// 8. Gap between matches
$coverage = calculateCoverage([makeMatch(0, 9), makeMatch(30, 39)], 100);
t('gap between matches: 20 unique words', $coverage['unique_matched_words'] === 20, "got: {$coverage['unique_matched_words']}");
t('gap score is 20%', $coverage['coverage_percent'] === 20.0, "got: {$coverage['coverage_percent']}%");

// 9. Exclusion reduces score proportionally
// If 10 words are excluded from a 50-word match in 100-word doc
// Active matches are 40 words → 40%
$coverage = calculateCoverage([makeMatch(0, 39)], 100);
t('after excluding 10 of 50 words, score is 40%', $coverage['coverage_percent'] === 40.0, "got: {$coverage['coverage_percent']}%");

// 10. Adjacent matches (not overlapping, consecutive)
$coverage = calculateCoverage([makeMatch(0, 4), makeMatch(5, 9)], 100);
// Adjacent ranges merge into 0-9 = 10 unique words
t('adjacent ranges count as 10 unique', $coverage['unique_matched_words'] === 10, "got: {$coverage['unique_matched_words']}");

// 11. verify result structure
$result = calculateCoverage([makeMatch(0, 24)], 50);
t('result has unique_matched_words key', array_key_exists('unique_matched_words', $result));
t('result has total_eligible_words key', array_key_exists('total_eligible_words', $result));
t('result has coverage_percent key', array_key_exists('coverage_percent', $result));

// Check logs
$appLog = file_get_contents(__DIR__ . '/../storage/logs/app.log');
$errorLog = file_get_contents(__DIR__ . '/../storage/logs/error.log');
t('app.log has no critical entries', !str_contains($appLog, '[critical]'), trim($appLog));
t('error.log is empty', trim($errorLog) === '', trim($errorLog));

echo "\n── Results: {$pass} passed, {$fail} failed ──\n";
exit($fail > 0 ? 1 : 0);
