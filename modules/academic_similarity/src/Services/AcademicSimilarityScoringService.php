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

        // Separate textual (exact + near-exact) from semantic matches
        $textualMatches = array_filter($matchResults, fn($m) => in_array($m->matchType, ['exact', 'near-exact'], true));
        $semanticMatches = array_filter($matchResults, fn($m) => $m->matchType === 'semantic');

        // Load semantic report threshold from settings
        $semanticReportThreshold = 0.70;
        try {
            $settings = academic_similarity_get_settings($this->tenantId);
            $semanticReportThreshold = (float)($settings['semantic_report_threshold'] ?? 0.70);
        } catch (\Throwable $e) {
            // fallback to default
        }

        // Only semantic matches at or above report threshold count towards score
        $highConfidenceSemantic = array_filter($semanticMatches, fn($m) => $m->confidence >= $semanticReportThreshold);
        $lowConfidenceSemantic = array_filter($semanticMatches, fn($m) => $m->confidence < $semanticReportThreshold);

        // Textual score (unique word coverage — exact + near-exact ONLY).
        // Semantic matches, regardless of confidence, never enter textual coverage.
        $textualCoverage = $this->calculateUniqueCoverage($textualMatches, $totalEligibleWords);

        // Build source breakdown for weighted scoring
        $sourceBreakdown = $this->buildSourceBreakdown($matchResults);

        // Weighted scores (all match types — kept for backward compat)
        $weightedRaw = $this->calculateWeightedScore($matchResults, $totalEligibleWords, false);
        $weightedAdjusted = $this->calculateWeightedScore($matchResults, $totalEligibleWords, true);

        // Semantic resemblance score (semantic-only, weighted)
        $semanticResemblance = $this->calculateSemanticResemblanceScore($highConfidenceSemantic, $totalEligibleWords);

        // Handle excluded matches for unweighted adjusted score
        $excludedMatches = $matchRepo->findExcluded($submissionId);
        $excludedRanges = [];
        foreach ($excludedMatches as $em) {
            $excludedRanges[] = [
                'start' => (int)$em['submission_word_range_start'],
                'end' => (int)$em['submission_word_range_end'],
            ];
        }

        // Adjusted textual coverage — exact + near-exact only, minus exclusions
        $activeTextualResults = [];
        foreach ($textualMatches as $mr) {
            $excluded = false;
            foreach ($excludedRanges as $er) {
                if ($mr->submissionWordStart === $er['start'] && $mr->submissionWordEnd === $er['end']) {
                    $excluded = true;
                    break;
                }
            }
            if (!$excluded) {
                $activeTextualResults[] = $mr;
            }
        }

        $adjustedCoverage = $this->calculateUniqueCoverage($activeTextualResults, $totalEligibleWords);

        $textualOverlapScore = $totalEligibleWords > 0
            ? round(($textualCoverage['unique_matched_words'] / $totalEligibleWords) * 100, 2)
            : 0.0;

        $adjustedScore = $totalEligibleWords > 0
            ? round(($adjustedCoverage['unique_matched_words'] / $totalEligibleWords) * 100, 2)
            : 0.0;

        // Reviewer Attention Level — categorical, not additive.
        // Build evidence profile from match-level signals when available.
        $evidenceProfile = [
            'quotation_count' => $this->countMatchByType($matchResults, 'quotation'),
            'exclusion_count' => count($excludedMatches),
            'method_section_count' => 0, // Would require section metadata
            'citation_count' => 0,       // Would require citation analysis
        ];
        $attentionLevel = $this->calculateReviewerAttentionLevel(
            $textualOverlapScore,
            $highConfidenceSemantic,
            $lowConfidenceSemantic,
            $totalEligibleWords,
            $evidenceProfile
        );

        return [
            'raw_score' => $textualOverlapScore,
            'adjusted_score' => $adjustedScore,
            'textual_overlap_score' => $textualOverlapScore,
            'weighted_raw_score' => round($weightedRaw, 2),
            'weighted_adjusted_score' => round($weightedAdjusted, 2),
            'semantic_resemblance_score' => round($semanticResemblance, 2),
            'reviewer_attention_level' => $attentionLevel,
            'semantic_strong_relationships' => count($highConfidenceSemantic),
            'semantic_weak_relationships' => count($lowConfidenceSemantic),
            'matched_word_count' => $adjustedCoverage['unique_matched_words'],
            'total_eligible_words' => $totalEligibleWords,
            'source_breakdown' => $sourceBreakdown,
            '_experimental' => true,
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

    // ── Semantic resemblance scoring (separate from textual) ───────

    /**
     * Calculate semantic resemblance score from high-confidence semantic matches only.
     *
     * This is a standalone score representing topic-level similarity, NOT
     * textual copying. It should be displayed separately from the textual
     * similarity score in reports.
     *
     * Formula:
     *   semantic_resemblance = Σ(match_word_count × 0.2) / total_words × 100
     *
     * Uses a fixed 0.2 weight (semantic type weight) and no contiguous bonus
     * or diversity factor, since semantic matches are segment-level properties
     * rather than word-level exact runs.
     *
     * @param AcademicSimilarityMatchResult[] $semanticMatches High-confidence semantic matches only
     * @param int $totalEligibleWords
     * @return float
     */
    public function calculateSemanticResemblanceScore(array $semanticMatches, int $totalEligibleWords): float
    {
        if ($totalEligibleWords <= 0 || empty($semanticMatches)) {
            return 0.0;
        }

        $totalWeight = 0.0;
        foreach ($semanticMatches as $match) {
            $totalWeight += $match->matchedWordCount * 0.2;
        }

        // Cap at 100 to prevent inflation from overlapping semantic matches
        return min(100.0, ($totalWeight / $totalEligibleWords) * 100);
    }

    // ── Reviewer Attention Level (categorical, not additive) ──────

    /**
     * Calculate a categorical attention level based on transparent evidence rules.
     *
     * This replaces the additive combined_score. It is NOT a percentage — it
     * is a risk-ranking indicator for reviewer triage.
     *
     * Rules engine is now evidence-category-aware when $evidenceProfile is provided.
     * When evidence signals (quotation, citation, section) are available, they
     * can downgrade attention that would otherwise be driven by raw score alone.
     *
     * @param float $textualScore
     * @param array $highConfidenceSemantic
     * @param array $lowConfidenceSemantic
     * @param int $totalEligibleWords
     * @param array|null $evidenceProfile Optional array with signal keys:
     *   quotation_count, citation_count, method_section_count, exclusion_count
     * @return array{level: string, label: string, reasons: array}
     */
    public function calculateReviewerAttentionLevel(
        float $textualScore,
        array $highConfidenceSemantic,
        array $lowConfidenceSemantic,
        int $totalEligibleWords,
        ?array $evidenceProfile = null
    ): array {
        $reasons = [];
        $highCount = count($highConfidenceSemantic);
        $lowCount = count($lowConfidenceSemantic);

        // Extract evidence signals if available
        $quotationCount = (int)($evidenceProfile['quotation_count'] ?? 0);
        $citationCount = (int)($evidenceProfile['citation_count'] ?? 0);
        $methodSectionCount = (int)($evidenceProfile['method_section_count'] ?? 0);
        $exclusionCount = (int)($evidenceProfile['exclusion_count'] ?? 0);

        // None — no evidence at all
        if ($textualScore <= 0 && $highCount === 0 && $lowCount === 0) {
            return [
                'level' => 'none',
                'label' => 'None',
                'reasons' => ['No reportable evidence detected.'],
            ];
        }

        // Determine effective textual score after considering evidence signals.
        // Quotations with citations should reduce concern (they're attributed).
        $effectiveTextualScore = $textualScore;
        if ($quotationCount > 0 && $citationCount > 0 && $effectiveTextualScore > 0) {
            $effectiveTextualScore = max(0, $effectiveTextualScore - ($quotationCount * 2));
            $reasons[] = $quotationCount . ' passage(s) with quotation markers and citations detected — these may represent properly attributed use.';
        }

        // Method section passages are typically less concerning
        if ($methodSectionCount > 0 && $effectiveTextualScore > 0) {
            $effectiveTextualScore = max(0, $effectiveTextualScore - ($methodSectionCount * 1));
            if ($methodSectionCount >= 2) {
                $reasons[] = $methodSectionCount . ' passage(s) in methodology sections — may represent shared methodological description.';
            }
        }

        // High triggers (using effective score where evidence signals are available)
        if ($effectiveTextualScore > 25) {
            $reasons[] = 'Substantial exact or near-exact overlap (' . round($effectiveTextualScore, 1) . '% textual)';
        } elseif ($textualScore > 25 && $effectiveTextualScore <= 25) {
            $reasons[] = 'Textual overlap is ' . $textualScore . '% but evidence signals (quotations, citations) reduce the attention level. Reviewer verification recommended.';
        }

        if ($textualScore > 0 && $textualScore <= 25 && $highCount >= 3) {
            $reasons[] = $highCount . ' strong contextual matches with exact overlap';
        }

        // Moderate triggers
        if ($textualScore > 10 && $textualScore <= 25) {
            $reasons[] = 'Repeated near-exact overlap (' . $textualScore . '% textual)';
        }
        if ($textualScore > 0 && $textualScore <= 10 && $highCount >= 2) {
            $reasons[] = $highCount . ' strong contextual matches with some exact overlap';
        }
        if ($highCount >= 5) {
            $reasons[] = $highCount . ' strong contextual relationships detected';
        }

        // Low triggers
        if ($textualScore > 0 && $textualScore <= 10 && $highCount === 0) {
            $reasons[] = 'Minor exact overlap (' . $textualScore . '%) with no strong contextual matches';
        }
        if ($highCount > 0 && $highCount < 5 && $textualScore <= 10) {
            $reasons[] = $highCount . ' strong contextual relationship(s), low textual overlap';
        }
        if ($lowCount > 0 && $highCount === 0 && $textualScore <= 0) {
            $reasons[] = $lowCount . ' weak topical relationship(s) only — no textual overlap';
        }

        // Exclusion info
        if ($exclusionCount > 0 && $textualScore > 0) {
            $reasons[] = $exclusionCount . ' match(es) have been excluded by reviewer — textual score already reflects exclusions.';
        }

        // Determine level using effective score when available
        $scoreForLevel = ($quotationCount > 0 || $methodSectionCount > 0) ? $effectiveTextualScore : $textualScore;

        if ($scoreForLevel > 25 || ($textualScore > 0 && $highCount >= 3)) {
            $level = 'high';
            $label = 'High';
        } elseif ($scoreForLevel > 10 || $highCount >= 5 || ($textualScore > 0 && $highCount >= 2)) {
            $level = 'moderate';
            $label = 'Moderate';
        } elseif ($textualScore > 0 || $highCount > 0 || $lowCount > 0) {
            $level = 'low';
            $label = 'Low';
        } else {
            $level = 'none';
            $label = 'None';
        }

        if (empty($reasons)) {
            $reasons[] = 'No specific triggers matched the configured threshold rules.';
        }

        return [
            'level' => $level,
            'label' => $label,
            'reasons' => $reasons,
        ];
    }

    /**
     * Count matches of a specific type.
     */
    private function countMatchByType(array $matchResults, string $type): int
    {
        $count = 0;
        foreach ($matchResults as $m) {
            if ($m->matchType === $type) {
                $count++;
            }
        }
        return $count;
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
