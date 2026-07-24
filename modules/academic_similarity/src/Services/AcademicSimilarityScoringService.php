<?php
declare(strict_types=1);

/**
 * Scores submissions for similarity, supporting both the existing
 * unique-word-coverage method and a new weighted scoring method.
 *
 * Weighted scoring follows industry practices from Turnitin (contiguous
 * run weighting), Ouriginal (source diversity factor), and Grammarly
 * (match-type weights): exact=1.0, near-exact=0.85, text-level=0.4.
 */
class AcademicSimilarityScoringService
{
    private string $tenantId;
    private ?\Ikabud\Kernel\Contracts\ModuleDB $db = null;

    /** Match type → weight multiplier */
    private const TYPE_WEIGHTS = [
        'exact'      => 1.0,
        'near-exact'  => 0.85,
        'semantic'    => 0.2,
        'text-level'  => 0.4,
    ];

    public function __construct(string $tenantId) {
        $this->tenantId = $tenantId;
        try {
            $this->db = academic_similarity_db();
        } catch (\Throwable $e) {
            $this->db = null; // Allow construction without DB for in-memory scoring
        }
    }

    /**
     * Calculate similarity score for a submission based on unique eligible word coverage.
     * Returns both the existing (unweighted) and new weighted scores.
     *
     * @param int $submissionId
     * @return array{
     *   raw_score: float, adjusted_score: float,
     *   weighted_raw_score: float, weighted_adjusted_score: float,
     *   matched_word_count: int, total_eligible_words: int,
     *   source_breakdown: array
     * }
     */
    public function calculateScore(int $submissionId): array
    {
        $subRepo = new AcademicSimilaritySubmissionRepository($this->tenantId);
        $submission = $subRepo->findById($submissionId);

        if ($submission === null) {
            return [
                'raw_score' => 0.0, 'adjusted_score' => 0.0,
                'weighted_raw_score' => 0.0, 'weighted_adjusted_score' => 0.0,
                'matched_word_count' => 0, 'total_eligible_words' => 0,
                'source_breakdown' => [],
            ];
        }

        $totalEligibleWords = (int)($submission['total_eligible_words'] ?? 0);
        if ($totalEligibleWords <= 0) {
            $totalEligibleWords = (int)($submission['word_count'] ?? 0);
        }

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

        // Existing unweighted scores
        $rawCoverage = $this->calculateUniqueCoverage($matchResults, $totalEligibleWords);

        // Build source breakdown for weighted scoring
        $sourceBreakdown = $this->buildSourceBreakdown($matchResults);

        // Weighted scores
        $weightedRaw = $this->calculateWeightedScore($matchResults, $totalEligibleWords, false);
        $weightedAdjusted = $this->calculateWeightedScore($matchResults, $totalEligibleWords, true);

        // Handle excluded matches for unweighted adjusted score
        $excludedMatches = $matchRepo->findExcluded($submissionId);
        $excludedRanges = [];
        foreach ($excludedMatches as $em) {
            $excludedRanges[] = [
                'start' => (int)$em['submission_word_range_start'],
                'end' => (int)$em['submission_word_range_end'],
            ];
        }

        $activeMatchResults = [];
        foreach ($matchResults as $mr) {
            $excluded = false;
            foreach ($excludedRanges as $er) {
                if ($mr->submissionWordStart === $er['start'] && $mr->submissionWordEnd === $er['end']) {
                    $excluded = true;
                    break;
                }
            }
            if (!$excluded) {
                $activeMatchResults[] = $mr;
            }
        }

        $adjustedCoverage = $this->calculateUniqueCoverage($activeMatchResults, $totalEligibleWords);

        $rawScore = $totalEligibleWords > 0
            ? round(($rawCoverage['unique_matched_words'] / $totalEligibleWords) * 100, 2)
            : 0.0;

        $adjustedScore = $totalEligibleWords > 0
            ? round(($adjustedCoverage['unique_matched_words'] / $totalEligibleWords) * 100, 2)
            : 0.0;

        return [
            'raw_score' => $rawScore,
            'adjusted_score' => $adjustedScore,
            'weighted_raw_score' => round($weightedRaw, 2),
            'weighted_adjusted_score' => round($weightedAdjusted, 2),
            'matched_word_count' => $adjustedCoverage['unique_matched_words'],
            'total_eligible_words' => $totalEligibleWords,
            'source_breakdown' => $sourceBreakdown,
        ];
    }

    // ── Unweighted scoring (existing) ──────────────────────────────

    /**
     * Calculate unique word coverage from match results.
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
     * Resolve overlapping word ranges for scoring.
     */
    public function resolveOverlapRanges(array $matches): array
    {
        if (empty($matches)) return [];
        $ranges = [];
        foreach ($matches as $match) {
            $ranges[] = ['start' => $match->submissionWordStart, 'end' => $match->submissionWordEnd];
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

    // ── Weighted scoring (new) ────────────────────────────────────

    /**
     * Calculate weighted similarity score.
     *
     * Formula:
     *   weighted_score = Σ(match_weight × type_weight × diversity_factor) / total_words × 100
     *
     * Where:
     *   match_weight = word_count × contiguous_bonus
     *   contiguous_bonus = min(2.0, 1.0 + (run_length / 100))
     *   type_weight: exact=1.0, near-exact=0.85, text-level=0.4
     *   diversity_factor per source: min(0.8, 0.5 + (0.3 / max(1, source_count)))
     *
     * @param AcademicSimilarityMatchResult[] $matches
     * @param int $totalEligibleWords
     * @param bool $excluded If true, applies exclusion ranges
     * @return float
     */
    public function calculateWeightedScore(array $matches, int $totalEligibleWords, bool $excluded = false): float
    {
        if ($totalEligibleWords <= 0 || empty($matches)) {
            return 0.0;
        }

        // Group matches by source for diversity factor
        $bySource = [];
        foreach ($matches as $match) {
            $sid = $match->sourceId;
            if (!isset($bySource[$sid])) {
                $bySource[$sid] = [];
            }
            $bySource[$sid][] = $match;
        }

        $sourceCount = count($bySource);
        $diversityFactor = min(0.8, 0.5 + (0.3 / max(1, $sourceCount)));
        $totalWeight = 0.0;

        foreach ($bySource as $sid => $sourceMatches) {
            foreach ($sourceMatches as $match) {
                $runLength = $match->matchedWordCount;
                $contiguousBonus = min(2.0, 1.0 + ($runLength / 100));
                $matchWeight = $runLength * $contiguousBonus;

                $typeWeight = self::TYPE_WEIGHTS[$match->matchType] ?? 0.5;
                if ($match->matchType === 'near-exact' && $match->confidence < 0.6) {
                    $typeWeight *= 0.8; // Lower confidence near-exact gets reduced weight
                }

                $totalWeight += $matchWeight * $typeWeight;
            }
        }

        $weightedScore = ($totalWeight / $totalEligibleWords) * 100 * $diversityFactor;
        return min(100.0, $weightedScore);
    }

    /**
     * Build per-source breakdown for report display.
     *
     * @param AcademicSimilarityMatchResult[] $matches
     * @return array<int, array{source_id: int, matched_words: int, weighted_contribution: float, match_types: array}>
     */
    public function buildSourceBreakdown(array $matches): array
    {
        $bySource = [];
        foreach ($matches as $match) {
            $sid = $match->sourceId;
            if (!isset($bySource[$sid])) {
                $bySource[$sid] = ['total_words' => 0, 'weighted' => 0.0, 'types' => []];
            }
            $runLength = $match->matchedWordCount;
            $contiguousBonus = min(2.0, 1.0 + ($runLength / 100));
            $typeWeight = self::TYPE_WEIGHTS[$match->matchType] ?? 0.5;

            $bySource[$sid]['total_words'] += $runLength;
            $bySource[$sid]['weighted'] += $runLength * $contiguousBonus * $typeWeight;
            $bySource[$sid]['types'][$match->matchType] = ($bySource[$sid]['types'][$match->matchType] ?? 0) + $runLength;
        }

        $totalWeight = array_sum(array_column($bySource, 'weighted'));
        $breakdown = [];
        foreach ($bySource as $sid => $data) {
            $breakdown[] = [
                'source_id' => $sid,
                'matched_words' => $data['total_words'],
                'weighted_contribution' => $totalWeight > 0
                    ? round(($data['weighted'] / $totalWeight) * 100, 1)
                    : 0.0,
                'match_types' => $data['types'],
            ];
        }

        usort($breakdown, function (array $a, array $b) {
            return $b['matched_words'] - $a['matched_words'];
        });

        return $breakdown;
    }
}
