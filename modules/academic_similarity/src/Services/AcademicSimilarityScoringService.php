<?php
declare(strict_types=1);

class AcademicSimilarityScoringService
{
    private string $tenantId;
    private \Ikabud\Kernel\Contracts\ModuleDB $db;

    public function __construct(string $tenantId) {
        $this->tenantId = $tenantId;
        $this->db = academic_similarity_db();
    }

    /**
     * Calculate similarity score for a submission based on unique eligible word coverage.
     *
     * @param int $submissionId
     * @return array{raw_score: float, adjusted_score: float, matched_word_count: int, total_eligible_words: int}
     */
    public function calculateScore(int $submissionId): array
    {
        // Load submission to get total eligible words
        $subRepo = new AcademicSimilaritySubmissionRepository($this->tenantId);
        $submission = $subRepo->findById($submissionId);

        if ($submission === null) {
            return [
                'raw_score' => 0.0,
                'adjusted_score' => 0.0,
                'matched_word_count' => 0,
                'total_eligible_words' => 0,
            ];
        }

        $totalEligibleWords = (int)($submission['total_eligible_words'] ?? 0);
        if ($totalEligibleWords <= 0) {
            // Fall back to submission word count
            $totalEligibleWords = (int)($submission['word_count'] ?? 0);
        }

        // Load all active (non-excluded) matches for this submission
        $matchRepo = new AcademicSimilarityMatchRepository($this->tenantId);
        $matches = $matchRepo->findActive($submissionId);

        // Convert to AcademicSimilarityMatchResult objects
        $matchResults = [];
        foreach ($matches as $match) {
            $evidence = $matchRepo->getEvidence((int)$match['id']);
            $matchResults[] = new AcademicSimilarityMatchResult([
                'submission_id' => (int)$match['submission_id'],
                'source_id' => (int)$match['source_id'],
                'match_type' => $match['match_type'],
                'confidence' => (float)$match['match_confidence'],
                'submission_segment_id' => isset($match['submission_segment_id']) ? (int)$match['submission_segment_id'] : null,
                'source_segment_id' => isset($match['source_segment_id']) ? (int)$match['source_segment_id'] : null,
                'matched_word_count' => (int)$match['matched_word_count'],
                'submission_word_range_start' => (int)$match['submission_word_range_start'],
                'submission_word_range_end' => (int)$match['submission_word_range_end'],
                'source_word_range_start' => (int)$match['source_word_range_start'],
                'source_word_range_end' => (int)$match['source_word_range_end'],
                'segment_match_count' => (int)$match['segment_match_count'],
                'evidence' => $evidence,
            ]);
        }

        // Calculate raw coverage (including all matches)
        $rawCoverage = $this->calculateUniqueCoverage($matchResults, $totalEligibleWords);

        // Load excluded matches and calculate deductions
        $excludedMatches = $matchRepo->findExcluded($submissionId);
        $excludedResults = [];
        foreach ($excludedMatches as $match) {
            $excludedResults[] = new AcademicSimilarityMatchResult([
                'submission_id' => (int)$match['submission_id'],
                'source_id' => (int)$match['source_id'],
                'match_type' => $match['match_type'],
                'confidence' => (float)$match['match_confidence'],
                'matched_word_count' => (int)$match['matched_word_count'],
                'submission_word_range_start' => (int)$match['submission_word_range_start'],
                'submission_word_range_end' => (int)$match['submission_word_range_end'],
                'source_word_range_start' => (int)$match['source_word_range_start'],
                'source_word_range_end' => (int)$match['source_word_range_end'],
                'segment_match_count' => (int)$match['segment_match_count'],
                'evidence' => [],
            ]);
        }

        // Calculate adjusted coverage (excluding excluded matches)
        // We need to compute coverage WITHOUT the excluded matches' word ranges
        $activeMatchResults = [];
        foreach ($matchResults as $mr) {
            $isExcluded = false;
            foreach ($excludedResults as $er) {
                if ($mr->submissionWordStart === $er->submissionWordStart
                    && $mr->submissionWordEnd === $er->submissionWordEnd) {
                    $isExcluded = true;
                    break;
                }
            }
            if (!$isExcluded) {
                $activeMatchResults[] = $mr;
            }
        }

        $adjustedCoverage = $this->calculateUniqueCoverage($activeMatchResults, $totalEligibleWords);

        // Raw score = (unique matched eligible words / total eligible words) * 100
        $rawScore = $totalEligibleWords > 0
            ? round(($rawCoverage['unique_matched_words'] / $totalEligibleWords) * 100, 2)
            : 0.0;

        // Adjusted score = recalculated after applying exclusions
        $adjustedScore = $totalEligibleWords > 0
            ? round(($adjustedCoverage['unique_matched_words'] / $totalEligibleWords) * 100, 2)
            : 0.0;

        return [
            'raw_score' => $rawScore,
            'adjusted_score' => $adjustedScore,
            'matched_word_count' => $adjustedCoverage['unique_matched_words'],
            'total_eligible_words' => $totalEligibleWords,
        ];
    }

    /**
     * Calculate unique word coverage from match results, resolving overlaps.
     * Each matched word position is counted only once, regardless of how many
     * matches cover it.
     *
     * @param AcademicSimilarityMatchResult[] $matches
     * @param int $totalEligibleWords
     * @return array{unique_matched_words: int, total_eligible_words: int, coverage_percent: float}
     */
    public function calculateUniqueCoverage(array $matches, int $totalEligibleWords): array
    {
        $ranges = $this->resolveOverlapRanges($matches);

        $uniqueWords = 0;
        foreach ($ranges as $range) {
            $uniqueWords += ($range['end'] - $range['start'] + 1);
        }

        $uniqueWords = min($uniqueWords, $totalEligibleWords);

        return [
            'unique_matched_words' => $uniqueWords,
            'total_eligible_words' => $totalEligibleWords,
            'coverage_percent' => $totalEligibleWords > 0
                ? round(($uniqueWords / $totalEligibleWords) * 100, 2)
                : 0.0,
        ];
    }

    /**
     * Resolve overlapping word ranges from match results into a set of
     * non-overlapping ranges for scoring. If multiple matches cover the same
     * word range, the word is counted only once.
     *
     * @param AcademicSimilarityMatchResult[] $matches
     * @return array<int, array{start: int, end: int}>
     */
    public function resolveOverlapRanges(array $matches): array
    {
        if (empty($matches)) {
            return [];
        }

        // Collect all word ranges
        $ranges = [];
        foreach ($matches as $match) {
            $ranges[] = [
                'start' => $match->submissionWordStart,
                'end' => $match->submissionWordEnd,
            ];
        }

        // Sort by start, then by end descending
        usort($ranges, function (array $a, array $b) {
            if ($a['start'] !== $b['start']) {
                return $a['start'] - $b['start'];
            }
            return $b['end'] - $a['end'];
        });

        // Merge overlapping/adjacent ranges
        $merged = [];
        $current = $ranges[0];

        for ($i = 1, $n = count($ranges); $i < $n; $i++) {
            $next = $ranges[$i];
            if ($next['start'] <= $current['end'] + 1) {
                // Overlap or adjacent — extend current
                $current['end'] = max($current['end'], $next['end']);
            } else {
                $merged[] = $current;
                $current = $next;
            }
        }
        $merged[] = $current;

        return $merged;
    }
}
