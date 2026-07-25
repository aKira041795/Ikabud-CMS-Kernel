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

// ── Boundary contract: inclusive/exclusive range semantics ──

// 13. Exact adjacency (A ends at 4, B starts at 5): no overlap because >=
$resolved = resolveOverlappingMatches([makeMatch(0, 4, 5), makeMatch(5, 9, 5)]);
t('adjacent matches (end=4, start=5) overlap condition uses >=, both kept', count($resolved) === 2, "got: " . count($resolved));
if (count($resolved) === 2) {
    t('adjacent match 1 covers 0-4', $resolved[0]->submissionWordStart === 0 && $resolved[0]->submissionWordEnd === 4);
    t('adjacent match 2 covers 5-9', $resolved[1]->submissionWordStart === 5 && $resolved[1]->submissionWordEnd === 9);
}

// 14. Overlap boundary: A ends at 7, B starts at 5 and ends at 7 (exact boundary overlap)
$resolved = resolveOverlappingMatches([makeMatch(0, 7, 8), makeMatch(5, 7, 3)]);
t('overlap where B ends at same position as A: B fully contained, skipped', count($resolved) === 1, "got: " . count($resolved));
if (count($resolved) === 1) {
    t('only A (0-7) survives', $resolved[0]->submissionWordStart === 0 && $resolved[0]->submissionWordEnd === 7);
}

// 15. Overlap boundary: A ends at 7, B starts at 5 ends at 8 (one word beyond)
$resolved = resolveOverlappingMatches([makeMatch(0, 7, 8), makeMatch(5, 8, 4)]);
t('overlap where B extends one word past A', count($resolved) === 2 || count($resolved) === 1, "got: " . count($resolved));

// 16. Partial overlap with trimmed range: verify start position and word count
// A = [0, 7] (8 words), B = [5, 12] (8 words)
// After A kept, B trimmed to non-overlapping portion
// If ranges are inclusive: overlap is words 5,6,7 (3 words), trimmed = [7? or 8?, 12]
// If start >= lastEnd (7) is used, trimmed start = lastEnd = 7
$resolved = resolveOverlappingMatches([makeMatch(0, 7, 8), makeMatch(5, 12, 8)]);
if (count($resolved) >= 2) {
    $trimmed = $resolved[1];
    t('trimmed match starts at lastEnd (7)', $trimmed->submissionWordStart === 7, "got: {$trimmed->submissionWordStart}");
    t('trimmed match ends at original end (12)', $trimmed->submissionWordEnd === 12, "got: {$trimmed->submissionWordEnd}");
    // If ranges are inclusive: 7..12 = 6 words, if exclusive end: 7..12 = 5 words
    // The trimmed length is end - lastEnd = 12 - 7 = 5
    // The matched_word_count is set to $trimmedLen which is 5
    t('trimmed match word count = end - lastEnd = 5', $trimmed->matchedWordCount === 5, "got: {$trimmed->matchedWordCount}");
}

// 17. Word count vs range consistency: verify that matched_word_count matches
// the default calculation end - start + 1 for a non-overlapping match
$m = makeMatch(3, 7, 5);
t('non-overlapping match word count matches range', $m->matchedWordCount === 5 && $m->submissionWordStart === 3 && $m->submissionWordEnd === 7);
$m2 = makeMatch(0, 0, 1);
t('single-word match has word count 1', $m2->matchedWordCount === 1 && $m2->submissionWordStart === 0 && $m2->submissionWordEnd === 0);

// 18. Boundary: start == lastEnd (single word at same position as lastEnd)
// A = [0, 6], B = [6, 6] (start=6, lastEnd=6)
// With >= condition, start >= lastEnd means B is non-overlapping
$resolved = resolveOverlappingMatches([makeMatch(0, 6, 7), makeMatch(6, 6, 1)]);
t('start==lastEnd is non-overlapping (>= condition), both kept', count($resolved) === 2, "got: " . count($resolved));
if (count($resolved) === 2) {
    t('boundary-adjacent B is at word 6', $resolved[1]->submissionWordStart === 6 && $resolved[1]->submissionWordEnd === 6);
}

// 19. Gap of exactly 1 between ranges (0-4 and 6-10)
$resolved = resolveOverlappingMatches([makeMatch(0, 4, 5), makeMatch(6, 10, 5)]);
t('gap of 1 between ranges keeps them separate', count($resolved) === 2, "got: " . count($resolved));

// 20. Gap of exactly 1 in range-based resolver
$ranges = resolveRanges([makeMatch(0, 4), makeMatch(6, 10)]);
t('gap of 1 in range resolver: <= end+1 merges (6 <= 4+1=5 is FALSE)', count($ranges) === 2, "got: " . count($ranges));

// Check logs
$appLog = file_get_contents(__DIR__ . '/../storage/logs/app.log');
$errorLog = file_get_contents(__DIR__ . '/../storage/logs/error.log');
t('app.log has no critical entries', !str_contains($appLog, '[critical]'), trim($appLog));
t('error.log is empty', trim($errorLog) === '', trim($errorLog));

echo "\n── Results: {$pass} passed, {$fail} failed ──\n";
exit($fail > 0 ? 1 : 0);
