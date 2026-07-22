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

echo "\n=== Academic Similarity — Overlap Resolution ===\n";

// Test the overlap resolution algorithm used in ScoringService::resolveOverlapRanges
// and MatchingService::resolveOverlaps

// Helper: resolve overlap ranges (same algorithm as ScoringService)
function resolveRanges(array $matches): array {
    if (empty($matches)) return [];
    $ranges = [];
    foreach ($matches as $m) {
        $ranges[] = ['start' => $m->submissionWordStart, 'end' => $m->submissionWordEnd];
    }
    usort($ranges, function (array $a, array $b) {
        if ($a['start'] !== $b['start']) return $a['start'] - $b['start'];
        return $b['end'] - $a['end'];
    });
    $merged = [];
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
    return $merged;
}

// Helper: resolve overlapping match results (same algorithm as MatchingService::resolveOverlaps)
function resolveOverlappingMatches(array $matches): array {
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

// Helper to make a match result quickly
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

// ── Range-based overlap resolution (ScoringService style) ──

// 1. Non-overlapping ranges
$ranges = resolveRanges([makeMatch(0, 4), makeMatch(10, 14)]);
t('non-overlapping ranges produce 2 merged ranges', count($ranges) === 2, "got: " . count($ranges));

// 2. Overlapping ranges (0-7 and 5-12)
$ranges = resolveRanges([makeMatch(0, 7), makeMatch(5, 12)]);
t('overlapping ranges merge into one', count($ranges) === 1, "got: " . count($ranges));
t('merged range covers full span', $ranges[0]['start'] === 0 && $ranges[0]['end'] === 12, "got: {$ranges[0]['start']}-{$ranges[0]['end']}");

// 3. Fully nested ranges (one within another)
$ranges = resolveRanges([makeMatch(0, 20), makeMatch(5, 10)]);
t('nested ranges merge into parent span', $ranges[0]['start'] === 0 && $ranges[0]['end'] === 20, "got: {$ranges[0]['start']}-{$ranges[0]['end']}");

// 4. Adjacent ranges (0-4 and 5-9)
$ranges = resolveRanges([makeMatch(0, 4), makeMatch(5, 9)]);
t('adjacent ranges merge into one (<= end+1)', count($ranges) === 1, "got: " . count($ranges));
t('adjacent merged range is 0-9', $ranges[0]['start'] === 0 && $ranges[0]['end'] === 9, "got: {$ranges[0]['start']}-{$ranges[0]['end']}");

// 5. Gap between ranges (0-4 and 10-14)
$ranges = resolveRanges([makeMatch(0, 4), makeMatch(10, 14)]);
t('gap between ranges keeps them separate', count($ranges) === 2, "got: " . count($ranges));

// 6. Empty matches
$ranges = resolveRanges([]);
t('empty matches produce empty resolved ranges', count($ranges) === 0);

// 7. Multiple overlapping ranges
$ranges = resolveRanges([makeMatch(0, 3), makeMatch(2, 7), makeMatch(5, 10), makeMatch(15, 20)]);
t('multiple overlapping ranges merge', count($ranges) === 2, "got: " . count($ranges));

// ── Match-object overlap resolution (MatchingService style) ──

// 8. Non-overlapping matches preserved
$resolved = resolveOverlappingMatches([makeMatch(0, 4, 5), makeMatch(10, 14, 5)]);
t('non-overlapping matches: both preserved', count($resolved) === 2, "got: " . count($resolved));

// 9. Overlapping matches: longer wins
$resolved = resolveOverlappingMatches([makeMatch(0, 4, 5), makeMatch(0, 9, 10)]);
t('overlapping matches resolve to one (longer wins)', count($resolved) === 1, "got: " . count($resolved));
t('longer match covers full range', $resolved[0]->submissionWordEnd === 9, "got: {$resolved[0]->submissionWordEnd}");

// 10. Nested matches (fully contained)
$resolved = resolveOverlappingMatches([makeMatch(0, 20, 21), makeMatch(5, 10, 6)]);
t('nested match is skipped', count($resolved) === 1, "got: " . count($resolved));

// 11. Partial overlap: not fully contained
$resolved = resolveOverlappingMatches([makeMatch(0, 7, 8), makeMatch(5, 12, 8)]);
t('partial overlap produces resolved matches', count($resolved) >= 1);

// 12. No overlap: sorted by start
$resolved = resolveOverlappingMatches([makeMatch(15, 19, 5), makeMatch(0, 4, 5)]);
t('ranges sorted by start position', $resolved[0]->submissionWordStart === 0, "got: {$resolved[0]->submissionWordStart}");

// Check logs
$appLog = file_get_contents(__DIR__ . '/../storage/logs/app.log');
$errorLog = file_get_contents(__DIR__ . '/../storage/logs/error.log');
t('app.log has no critical entries', !str_contains($appLog, '[critical]'), trim($appLog));
t('error.log is empty', trim($errorLog) === '', trim($errorLog));

echo "\n── Results: {$pass} passed, {$fail} failed ──\n";
exit($fail > 0 ? 1 : 0);
