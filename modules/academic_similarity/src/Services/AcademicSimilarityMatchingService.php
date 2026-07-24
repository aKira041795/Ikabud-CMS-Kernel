<?php
declare(strict_types=1);

class AcademicSimilarityMatchingService
{
    private string $tenantId;
    private ?\Ikabud\Kernel\Contracts\ModuleDB $db = null;
    private int $minMatchWordThreshold;

    public function __construct(string $tenantId, int $minMatchWordThreshold = 5) {
        $this->tenantId = $tenantId;
        try {
            $this->db = academic_similarity_db();
        } catch (\Throwable $e) {
            $this->db = null;
        }
        $this->minMatchWordThreshold = $minMatchWordThreshold;
    }

    /**
     * Run exact-match comparison for a submission against all source fingerprints.
     *
     * @param int $submissionId
     * @return array{ok: bool, matches: int, match_results: AcademicSimilarityMatchResult[]}
     */
    public function runExactMatching(int $submissionId): array
    {
        // 1. Load submission fingerprints (exact type)
        $subFps = $this->loadFingerprints($submissionId, 'exact', 'submission');

        if (empty($subFps)) {
            return ['ok' => true, 'matches' => 0, 'match_results' => []];
        }

        // 2. Find candidate sources via matching fingerprints
        $candidates = $this->findCandidateSourcesFromFingerprints($subFps, 'exact');

        // Also include internet-discovered sources (may have no fingerprint overlap
        // with original text — different authors, same topic)
        foreach ($this->findInternetDiscoveredSourceCandidates($submissionId) as $sid => $info) {
            if (!isset($candidates[$sid])) {
                $candidates[$sid] = $info;
            }
        }

        if (empty($candidates)) {
            return ['ok' => true, 'matches' => 0, 'match_results' => []];
        }

        // 3. For each candidate source, load segments and find contiguous match runs
        $allMatches = [];
        foreach ($candidates as $sourceId => $sourceInfo) {
            $sourceMatches = $this->compareSubmissionToSource(
                $submissionId,
                (int)$sourceId,
                $subFps,
                'exact'
            );
            $allMatches = array_merge($allMatches, $sourceMatches);
        }

        // 4. Resolve overlapping matches
        $resolved = $this->resolveOverlaps($allMatches);

        return ['ok' => true, 'matches' => count($resolved), 'match_results' => $resolved];
    }

    /**
     * Run near-exact-match comparison for a submission.
     *
     * @param int $submissionId
     * @return array{ok: bool, matches: int, match_results: AcademicSimilarityMatchResult[]}
     */
    public function runNearExactMatching(int $submissionId, array $excludeSourceIds = []): array
    {
        $subFps = $this->loadFingerprints($submissionId, 'near', 'submission');

        if (empty($subFps)) {
            return ['ok' => true, 'matches' => 0, 'match_results' => []];
        }

        $candidates = $this->findCandidateSourcesFromFingerprints($subFps, 'near');

        foreach ($this->findInternetDiscoveredSourceCandidates($submissionId) as $sid => $info) {
            if (!isset($candidates[$sid])) {
                $candidates[$sid] = $info;
            }
        }

        if (empty($candidates)) {
            return ['ok' => true, 'matches' => 0, 'match_results' => []];
        }

        $excludeLookup = array_flip($excludeSourceIds);
        $allMatches = [];
        foreach ($candidates as $sourceId => $sourceInfo) {
            // Skip sources already matched by the exact stage (prevents duplicate
            // text-level fallback matches when no fingerprint hits exist).
            if (isset($excludeLookup[(int)$sourceId])) {
                continue;
            }
            $sourceMatches = $this->compareSubmissionToSource(
                $submissionId,
                (int)$sourceId,
                $subFps,
                'near-exact'
            );
            $allMatches = array_merge($allMatches, $sourceMatches);
        }

        $resolved = $this->resolveOverlaps($allMatches);

        return ['ok' => true, 'matches' => count($resolved), 'match_results' => $resolved];
    }

    /**
     * Find candidate sources that share fingerprints with the given submission.
     *
     * @param int $submissionId
     * @return array<int, array{source_id: int, fingerprint_hits: int, match_confidence: float}>
     */
    public function findCandidateSources(int $submissionId): array
    {
        $stmt = $this->db->prepare("
            SELECT sf.source_id, COUNT(*) AS fingerprint_hits,
                   COUNT(*) / (SELECT COUNT(*) FROM ac_similarity_fingerprints WHERE submission_id = :sid2 AND tenant_id = :tid2 AND fingerprint_type = 'exact') AS match_confidence
            FROM ac_similarity_fingerprints sf
            WHERE sf.shingle_hash IN (
                SELECT shingle_hash FROM ac_similarity_fingerprints
                WHERE submission_id = :sid AND tenant_id = :tid AND fingerprint_type = 'exact'
            )
            AND sf.fingerprint_type = 'exact'
            AND sf.source_id IS NOT NULL
            AND sf.tenant_id = :tid3
            GROUP BY sf.source_id
            ORDER BY fingerprint_hits DESC
        ");
        $stmt->execute([
            ':sid' => $submissionId,
            ':sid2' => $submissionId,
            ':tid' => $this->tenantId,
            ':tid2' => $this->tenantId,
            ':tid3' => $this->tenantId,
        ]);

        $candidates = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $candidates[(int)$row['source_id']] = [
                'source_id' => (int)$row['source_id'],
                'fingerprint_hits' => (int)$row['fingerprint_hits'],
                'match_confidence' => (float)$row['match_confidence'],
            ];
        }

        // Include internet-discovered sources as candidates even without
        // fingerprint hits — different authors writing about the same topic
        // rarely share identical 5-word shingles.
        $internetSources = $this->db->prepare("
            SELECT s.id AS source_id
            FROM ac_similarity_sources s
            JOIN ac_similarity_internet_sources i ON i.source_id = s.id AND i.submission_id = :isub
            WHERE s.tenant_id = :tid AND s.is_indexed = 1
        ");
        $internetSources->execute([
            ':isub' => $submissionId,
            ':tid' => $this->tenantId,
        ]);
        while ($row = $internetSources->fetch(\PDO::FETCH_ASSOC)) {
            $sid = (int)$row['source_id'];
            if (!isset($candidates[$sid])) {
                $candidates[$sid] = [
                    'source_id' => $sid,
                    'fingerprint_hits' => 0,
                    'match_confidence' => 0.0,
                ];
            }
        }

        return $candidates;
    }

    /**
     * Resolve overlapping match results, keeping the longest match for each word range.
     *
     * @param AcademicSimilarityMatchResult[] $matches
     * @return AcademicSimilarityMatchResult[]
     */
    public function resolveOverlaps(array $matches): array
    {
        if (empty($matches)) {
            return [];
        }

        // Sort by submission word start, then by length descending
        usort($matches, function (AcademicSimilarityMatchResult $a, AcademicSimilarityMatchResult $b) {
            if ($a->submissionWordStart !== $b->submissionWordStart) {
                return $a->submissionWordStart - $b->submissionWordStart;
            }
            // Longer matches first
            return ($b->submissionWordEnd - $b->submissionWordStart) - ($a->submissionWordEnd - $a->submissionWordStart);
        });

        $resolved = [];
        $lastEnd = -1;

        foreach ($matches as $match) {
            if ($match->submissionWordStart >= $lastEnd) {
                // No overlap — keep it
                $resolved[] = $match;
                $lastEnd = $match->submissionWordEnd;
            } elseif ($match->submissionWordEnd > $lastEnd) {
                // Partial overlap — trim to the non-overlapping portion if long enough
                $trimmedLen = $match->submissionWordEnd - $lastEnd;
                if ($trimmedLen >= $this->minMatchWordThreshold) {
                    // Create a new match result with the non-overlapping portion
                    $data = $match->toMatchArray();
                    $data['submission_word_range_start'] = $lastEnd;
                    $data['submission_word_range_end'] = $match->submissionWordEnd;
                    $data['matched_word_count'] = $trimmedLen;
                    $adjustedConfidence = $match->confidence * ($trimmedLen / $match->matchedWordCount);
                    $data['match_confidence'] = min(1.0, $adjustedConfidence);
                    $resolved[] = new AcademicSimilarityMatchResult($data);
                    $lastEnd = $match->submissionWordEnd;
                }
            }
            // Fully contained — skip
        }

        return $resolved;
    }

    /**
     * Store match results and their evidence in the database.
     *
     * @param array $matchResults Array of AcademicSimilarityMatchResult objects
     * @param string $tenantId
     * @return int Number of matches stored
     */
    public function storeMatches(array $matchResults, string $tenantId): int
    {
        $count = 0;

        foreach ($matchResults as $matchResult) {
            if (!$matchResult instanceof AcademicSimilarityMatchResult) {
                continue;
            }

            // Insert the match
            $matchData = $matchResult->toMatchArray();
            $matchData['tenant_id'] = $tenantId;

            $stmt = $this->db->prepare("
                INSERT INTO ac_similarity_matches
                    (tenant_id, submission_id, source_id, match_type, match_confidence,
                     submission_segment_id, source_segment_id, matched_word_count,
                     submission_word_range_start, submission_word_range_end,
                     source_word_range_start, source_word_range_end, segment_match_count)
                VALUES
                    (:tenant_id, :submission_id, :source_id, :match_type, :match_confidence,
                     :submission_segment_id, :source_segment_id, :matched_word_count,
                     :submission_word_range_start, :submission_word_range_end,
                     :source_word_range_start, :source_word_range_end, :segment_match_count)
            ");
            $stmt->execute([
                ':tenant_id' => $tenantId,
                ':submission_id' => $matchData['submission_id'],
                ':source_id' => $matchData['source_id'],
                ':match_type' => $matchData['match_type'],
                ':match_confidence' => $matchData['match_confidence'],
                ':submission_segment_id' => $matchData['submission_segment_id'],
                ':source_segment_id' => $matchData['source_segment_id'],
                ':matched_word_count' => $matchData['matched_word_count'],
                ':submission_word_range_start' => $matchData['submission_word_range_start'],
                ':submission_word_range_end' => $matchData['submission_word_range_end'],
                ':source_word_range_start' => $matchData['source_word_range_start'],
                ':source_word_range_end' => $matchData['source_word_range_end'],
                ':segment_match_count' => $matchData['segment_match_count'],
            ]);

            $matchId = (int)$this->db->lastInsertId();

            // Insert evidence records
            foreach ($matchResult->evidence as $order => $evidence) {
                $stmtEv = $this->db->prepare("
                    INSERT INTO ac_similarity_match_evidence
                        (tenant_id, match_id, submission_segment_text, source_segment_text,
                         submission_start_offset, submission_end_offset,
                         source_start_offset, source_end_offset, overlap_resolution_order)
                    VALUES
                        (:tenant_id, :match_id, :submission_segment_text, :source_segment_text,
                         :submission_start_offset, :submission_end_offset,
                         :source_start_offset, :source_end_offset, :overlap_resolution_order)
                ");
                $stmtEv->execute([
                    ':tenant_id' => $tenantId,
                    ':match_id' => $matchId,
                    ':submission_segment_text' => $evidence['submission_text'] ?? '',
                    ':source_segment_text' => $evidence['source_text'] ?? '',
                    ':submission_start_offset' => (int)($evidence['submission_start_offset'] ?? 0),
                    ':submission_end_offset' => (int)($evidence['submission_end_offset'] ?? 0),
                    ':source_start_offset' => (int)($evidence['source_start_offset'] ?? 0),
                    ':source_end_offset' => (int)($evidence['source_end_offset'] ?? 0),
                    ':overlap_resolution_order' => $order + 1,
                ]);
            }

            $count++;
        }

        return $count;
    }

    // ── Private helpers ──────────────────────────────────────────

    /**
     * Load fingerprints for a given entity.
     */
    public function loadFingerprints(int $entityId, string $fingerprintType, string $entityType): array
    {
        if ($entityType === 'submission') {
            $stmt = $this->db->prepare("
                SELECT * FROM ac_similarity_fingerprints
                WHERE submission_id = :eid AND tenant_id = :tid AND fingerprint_type = :ft
                ORDER BY segment_index, word_position
            ");
        } else {
            $stmt = $this->db->prepare("
                SELECT * FROM ac_similarity_fingerprints
                WHERE source_id = :eid AND tenant_id = :tid AND fingerprint_type = :ft
                ORDER BY segment_index, word_position
            ");
        }

        $stmt->execute([':eid' => $entityId, ':tid' => $this->tenantId, ':ft' => $fingerprintType]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Find candidate sources that share fingerprints with the submission.
     *
     * @param array $subFps
     * @param string $fingerprintType
     * @param int $minShared Minimum shared shingles to be considered a candidate (default 3 for short, 1 for medium+)
     * @param int $maxCandidates Maximum candidate sources to return (0 = unlimited)
     * @return array<int, array>
     */
    private function findCandidateSourcesFromFingerprints(array $subFps, string $fingerprintType, int $minShared = 1, int $maxCandidates = 5000): array
    {
        $hashes = array_unique(array_column($subFps, 'shingle_hash'));

        if (empty($hashes)) {
            return [];
        }

        // Process in chunks to avoid oversized queries
        $hashChunks = array_chunk($hashes, 500);
        $candidates = [];

        foreach ($hashChunks as $chunk) {
            $placeholders = [];
            $params = [':tid' => $this->tenantId, ':ft' => $fingerprintType];
            foreach ($chunk as $i => $hash) {
                $key = ':h' . $i;
                $placeholders[] = $key;
                $params[$key] = $hash;
            }

            $inClause = implode(', ', $placeholders);

            $stmt = $this->db->prepare("
                SELECT source_id, COUNT(*) AS hit_count
                FROM ac_similarity_fingerprints
                WHERE shingle_hash IN ({$inClause})
                  AND fingerprint_type = :ft
                  AND source_id IS NOT NULL
                  AND tenant_id = :tid
                GROUP BY source_id
                HAVING hit_count >= :min_shingles
                ORDER BY hit_count DESC
                " . ($maxCandidates > 0 ? "LIMIT {$maxCandidates}" : "") . "
            ");
            $stmt->execute($params + [':min_shingles' => $minShared]);

            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $sid = (int)$row['source_id'];
                if (!isset($candidates[$sid])) {
                    $candidates[$sid] = [
                        'source_id' => $sid,
                        'fingerprint_hits' => 0,
                        'match_confidence' => 0.0,
                    ];
                }
                $candidates[$sid]['fingerprint_hits'] += (int)$row['hit_count'];
            }
        }

        // Calculate confidence scores
        $totalSubFps = count($subFps);
        foreach ($candidates as $sid => $info) {
            $candidates[$sid]['match_confidence'] = $totalSubFps > 0
                ? $info['fingerprint_hits'] / $totalSubFps
                : 0.0;
        }

        // Sort by hit count descending, limit to max candidates
        usort($candidates, function (array $a, array $b) {
            return $b['fingerprint_hits'] - $a['fingerprint_hits'];
        });

        if ($maxCandidates > 0 && count($candidates) > $maxCandidates) {
            $candidates = array_slice($candidates, 0, $maxCandidates);
        }

        // Re-index by source_id
        $indexed = [];
        foreach ($candidates as $c) {
            $indexed[$c['source_id']] = $c;
        }

        return $indexed;
    }

    /**
     * Load internet-discovered source IDs for a submission so they can be
     * included as candidates even without fingerprint overlap.
     *
     * @return array<int, array{source_id: int, fingerprint_hits: int, match_confidence: float}>
     */
    private function findInternetDiscoveredSourceCandidates(int $submissionId): array
    {
        $stmt = $this->db->prepare("
            SELECT s.id AS source_id
            FROM ac_similarity_sources s
            JOIN ac_similarity_internet_sources i ON i.source_id = s.id AND i.submission_id = :sid
            WHERE s.tenant_id = :tid AND s.is_indexed = 1
        ");
        $stmt->execute([':sid' => $submissionId, ':tid' => $this->tenantId]);

        $candidates = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $sid = (int)$row['source_id'];
            $candidates[$sid] = [
                'source_id' => $sid,
                'fingerprint_hits' => 0,
                'match_confidence' => 0.0,
            ];
        }
        return $candidates;
    }

    /**
     * Compare a submission to a specific source, finding contiguous word-match runs.
     */
    public function compareSubmissionToSource(
        int $submissionId,
        int $sourceId,
        array $subFps,
        string $matchType
    ): array {
        // Load source fingerprints
        $srcFps = $this->loadFingerprints($sourceId, $matchType === 'near-exact' ? 'near' : 'exact', 'source');

        if (empty($srcFps)) {
            return [];
        }

        // Build hash lookup for source fingerprints
        $srcByHash = [];
        foreach ($srcFps as $fp) {
            $hash = $fp['shingle_hash'];
            if (!isset($srcByHash[$hash])) {
                $srcByHash[$hash] = [];
            }
            $srcByHash[$hash][] = $fp;
        }

        // Find matching submission fingerprint positions and map to source positions
        $subPositions = [];
        foreach ($subFps as $fp) {
            $hash = $fp['shingle_hash'];
            if (isset($srcByHash[$hash])) {
                $srcFp = $srcByHash[$hash][0];
                $subPositions[(int)$fp['word_position']] = (int)$srcFp['word_position'];
            }
        }

        // Fall back to text-level comparison for sources with no hash hits
        if (empty($subPositions)) {
            return $this->compareSubmissionToSourceByText($submissionId, $sourceId, $matchType);
        }

        // Load segments for both sides to get normalized content
        $subSegments = $this->loadSegments($submissionId, 'submission');
        $srcSegments = $this->loadSegments($sourceId, 'source');

        // Build word-indexed segment content maps
        $subWords = $this->buildWordIndex($subSegments);
        $srcWords = $this->buildWordIndex($srcSegments);

        // Sort submission positions
        ksort($subPositions);

        // Use Smith-Waterman local alignment for each fingerprint-hit region
        $results = [];
        $processed = []; // Track processed submission positions to avoid overlap

        // Group positions into regions (a fingerprint hit cluster)
        $regions = [];
        $currentRegion = null;
        foreach ($subPositions as $subPos => $srcPos) {
            if ($currentRegion === null) {
                $currentRegion = ['sub_start' => $subPos, 'sub_end' => $subPos, 'src_start' => $srcPos, 'src_end' => $srcPos];
            } else {
                $expectedSub = $currentRegion['sub_end'] + 1;
                $expectedSrc = $currentRegion['src_end'] + 1;
                if ($subPos === $expectedSub && $srcPos === $expectedSrc) {
                    $currentRegion['sub_end'] = $subPos;
                    $currentRegion['src_end'] = $srcPos;
                } else {
                    $regions[] = $currentRegion;
                    $currentRegion = ['sub_start' => $subPos, 'sub_end' => $subPos, 'src_start' => $srcPos, 'src_end' => $srcPos];
                }
            }
        }
        if ($currentRegion !== null) {
            $regions[] = $currentRegion;
        }

        // Run Smith-Waterman on each region's window
        $windowPadding = 200;
        foreach ($regions as $region) {
            $swResult = $this->smithWatermanAlignment(
                $subWords, $srcWords,
                $region,
                $windowPadding
            );

            if ($swResult !== null && $swResult['length'] >= $this->minMatchWordThreshold) {
                $subText = $this->extractWordRange($subWords, $swResult['sub_start'], $swResult['sub_end']);
                $srcText = $this->extractWordRange($srcWords, $swResult['src_start'], $swResult['src_end']);

                $evidence = [[
                    'submission_text' => $subText,
                    'source_text' => $srcText,
                    'submission_start_offset' => $swResult['sub_start'],
                    'submission_end_offset' => $swResult['sub_end'],
                    'source_start_offset' => $swResult['src_start'],
                    'source_end_offset' => $swResult['src_end'],
                ]];

                // Confidence from alignment quality
                $alignmentQuality = $swResult['alignment_score'] / max(1, $swResult['max_possible_score']);
                $confidence = min(1.0, max(0.1, $alignmentQuality));

                $results[] = new AcademicSimilarityMatchResult([
                    'submission_id' => $submissionId,
                    'source_id' => $sourceId,
                    'match_type' => $matchType,
                    'confidence' => $confidence,
                    'submission_segment_id' => null,
                    'source_segment_id' => null,
                    'matched_word_count' => $swResult['length'],
                    'submission_word_range_start' => $swResult['sub_start'],
                    'submission_word_range_end' => $swResult['sub_end'],
                    'source_word_range_start' => $swResult['src_start'],
                    'source_word_range_end' => $swResult['src_end'],
                    'segment_match_count' => count($regions),
                    'evidence' => $evidence,
                    'gap_count' => $swResult['gaps'],
                    'insertion_count' => $swResult['insertions'],
                ]);
            }
        }

        return $results;
    }

    /**
     * Smith-Waterman local alignment on a ±window word range.
     *
     * Implements affine-gap Smith-Waterman to find the optimal local alignment
     * between a submission word window and a source word window. Handles gaps,
     * insertions, and transpositions naturally — replacing the old position-
     * contiguity heuristic with proper sequence alignment.
     *
     * Scoring: match=+2, mismatch=-2, gap_open=-3, gap_extend=-1
     *
     * @param array $subWords Flat word index for submission
     * @param array $srcWords Flat word index for source
     * @param array $region ['sub_start','sub_end','src_start','src_end']
     * @param int $padding Extra words to include on each side of the region
     * @return array{sub_start: int, sub_end: int, src_start: int, src_end: int, length: int, alignment_score: float, max_possible_score: float, gaps: int, insertions: int}|null
     */
    public function smithWatermanAlignment(array $subWords, array $srcWords, array $region, int $padding = 200): ?array
    {
        $subTotal = count($subWords);
        $srcTotal = count($srcWords);

        // Extract windows with padding
        $swStart = max(0, $region['sub_start'] - $padding);
        $swEnd = min($subTotal - 1, $region['sub_end'] + $padding);
        $srcWinStart = max(0, $region['src_start'] - $padding);
        $srcWinEnd = min($srcTotal - 1, $region['src_end'] + $padding);

        $windowA = array_slice($subWords, $swStart, $swEnd - $swStart + 1);
        $windowB = array_slice($srcWords, $srcWinStart, $srcWinEnd - $srcWinStart + 1);

        $lenA = count($windowA);
        $lenB = count($windowB);

        if ($lenA < 3 || $lenB < 3) {
            return null;
        }

        // Smith-Waterman with affine gaps (Gotoh)
        $matchScore = 2;
        $mismatchScore = -2;
        $gapOpen = -3;
        $gapExtend = -1;

        // DP matrices: H = main, E = gap in A, F = gap in B
        $H = array_fill(0, $lenA + 1, array_fill(0, $lenB + 1, 0.0));
        $E = array_fill(0, $lenA + 1, array_fill(0, $lenB + 1, 0.0));
        $F = array_fill(0, $lenA + 1, array_fill(0, $lenB + 1, 0.0));

        $maxScore = 0.0;
        $maxI = 0;
        $maxJ = 0;

        for ($i = 1; $i <= $lenA; $i++) {
            for ($j = 1; $j <= $lenB; $j++) {
                // Match/mismatch score
                $isMatch = ($windowA[$i - 1] === $windowB[$j - 1]);
                $diagScore = $isMatch ? $matchScore : $mismatchScore;

                // Gap in A (submission word missing in source)
                $eOpen = $H[$i][$j - 1] + $gapOpen;
                $eExt = $E[$i][$j - 1] + $gapExtend;
                $E[$i][$j] = max($eOpen, $eExt);

                // Gap in B (source word missing in submission = insertion)
                $fOpen = $H[$i - 1][$j] + $gapOpen;
                $fExt = $F[$i - 1][$j] + $gapExtend;
                $F[$i][$j] = max($fOpen, $fExt);

                // Main cell
                $hDiag = $H[$i - 1][$j - 1] + $diagScore;
                $hE = $E[$i][$j];
                $hF = $F[$i][$j];
                $H[$i][$j] = max(0.0, $hDiag, $hE, $hF);

                if ($H[$i][$j] > $maxScore) {
                    $maxScore = $H[$i][$j];
                    $maxI = $i;
                    $maxJ = $j;
                }
            }
        }

        if ($maxScore <= 0) {
            return null;
        }

        // Traceback
        $i = $maxI;
        $j = $maxJ;
        $alignedLen = 0;
        $gaps = 0;
        $insertions = 0;

        while ($i > 0 && $j > 0 && $H[$i][$j] > 0) {
            $currentScore = $H[$i][$j];

            // Check which direction we came from
            $fromDiag = $H[$i - 1][$j - 1];
            $fromUp = $F[$i - 1][$j]; // Gap in B
            $fromLeft = $E[$i][$j - 1]; // Gap in A

            $isMatch = ($windowA[$i - 1] === $windowB[$j - 1]);
            $diagExpected = $fromDiag + ($isMatch ? $matchScore : $mismatchScore);

            if (abs($currentScore - $diagExpected) < 0.001) {
                // Diagonal move (match or mismatch)
                if (!$isMatch) {
                    $gaps++;
                }
                $alignedLen++;
                $i--;
                $j--;
            } elseif ($currentScore === $fromLeft + ($currentScore > $fromLeft ? $gapExtend : $gapOpen)) {
                // Gap in A (insertion in submission)
                $insertions++;
                $j--;
            } else {
                // Gap in B (deletion in submission)
                $gaps++;
                $i--;
            }
        }

        $alignedStartA = $i; // Position in window A where alignment starts
        $alignedStartB = $j;

        $maxPossibleScore = $alignedLen * $matchScore;

        return [
            'sub_start' => $swStart + $alignedStartA,
            'sub_end' => $swStart + $maxI - 1,
            'src_start' => $srcWinStart + $alignedStartB,
            'src_end' => $srcWinStart + $maxJ - 1,
            'length' => $alignedLen,
            'alignment_score' => $maxScore,
            'max_possible_score' => $maxPossibleScore > 0 ? $maxPossibleScore : 1.0,
            'gaps' => $gaps,
            'insertions' => $insertions,
        ];
    }

    /**
     * Load segments for a given entity.
     *
     * @param int $entityId
     * @param string $entityType 'submission' or 'source'
     * @return array
     */
    private function loadSegments(int $entityId, string $entityType): array
    {
        if ($entityType === 'submission') {
            $stmt = $this->db->prepare("
                SELECT * FROM ac_similarity_segments
                WHERE submission_id = :eid AND tenant_id = :tid
                ORDER BY segment_index ASC
            ");
        } else {
            $stmt = $this->db->prepare("
                SELECT * FROM ac_similarity_segments
                WHERE source_id = :eid AND tenant_id = :tid
                ORDER BY segment_index ASC
            ");
        }

        $stmt->execute([':eid' => $entityId, ':tid' => $this->tenantId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Build a flat word index from segments.
     *
     * @param array $segments
     * @return array List of words in order
     */
    private function buildWordIndex(array $segments): array
    {
        $words = [];
        foreach ($segments as $segment) {
            $segmentWords = preg_split('/\s+/', trim($segment['normalized_content'] ?? ''));
            if ($segmentWords !== false) {
                foreach ($segmentWords as $w) {
                    if ($w !== '') {
                        $words[] = $w;
                    }
                }
            }
        }
        return $words;
    }

    /**
     * Extract a range of words from the word index.
     *
     * @param array $words
     * @param int $start
     * @param int $end
     * @return string
     */
    private function extractWordRange(array $words, int $start, int $end): string
    {
        $slice = array_slice($words, $start, $end - $start + 1);
        return implode(' ', $slice);
    }

    /**
     * Text-level comparison fallback — used when fingerprint-based matching
     * finds 0 hash hits (common for internet-discovered sources where the
     * submission and source discuss the same topic in different words).
     *
     * Uses word-set Jaccard similarity on a sliding window to find regions
     * of topical overlap. Much looser than exact hash matching but catches
     * topic-level similarity that fingerprints miss.
     *
     * @return AcademicSimilarityMatchResult[]
     */
    private function compareSubmissionToSourceByText(
        int $submissionId,
        int $sourceId,
        string $matchType
    ): array {
        // Load normalized text for both sides
        $norm = new AcademicSimilarityNormalizationService($this->tenantId);

        $subText = $this->loadEntityText($submissionId, 'submission');
        $srcText = $this->loadEntityText($sourceId, 'source');

        if ($subText === '' || $srcText === '') {
            return [];
        }

        // Normalize for matching: lowercase + stemming + stop word removal
        $subNorm = $norm->normalizeForMatching($subText);
        $srcNorm = $norm->normalizeForMatching($srcText);

        $subWords = explode(' ', $subNorm);
        $srcWords = explode(' ', $srcNorm);

        $subCount = count($subWords);
        $srcCount = count($srcWords);

        if ($subCount < 10 || $srcCount < 10) {
            return [];
        }

        // Build source word set for fast Jaccard computation
        $srcWordSet = array_flip($srcWords);
        $srcSetSize = count($srcWordSet);

        // Sliding window over submission: compute Jaccard similarity
        // of each window against the entire source word set.
        $windowSize = max(10, min(50, intdiv($subCount, 3)));
        $threshold = 0.03; // 3% Jaccard — lenient for topic-level similarity between different authors
        $matches = [];
        $lastMatchEnd = -1;
        $runStart = -1;
        $runEnd = -1;
        $runWords = 0;

        for ($i = 0; $i <= $subCount - $windowSize; $i += max(1, intdiv($windowSize, 4))) {
            $windowWords = array_slice($subWords, $i, $windowSize);
            $windowSet = array_flip($windowWords);
            $windowSize2 = count($windowSet);

            // Intersection size
            $intersection = 0;
            foreach ($windowSet as $w => $_) {
                if (isset($srcWordSet[$w])) {
                    $intersection++;
                }
            }

            $union = $windowSize2 + $srcSetSize - $intersection;
            if ($union === 0) continue;
            $jaccard = $intersection / $union;

            if ($jaccard >= $threshold) {
                $matchEnd = $i + $windowSize;
                if ($i <= $lastMatchEnd + $windowSize) {
                    // Extend current run
                    $runEnd = $matchEnd;
                    $runWords += $intersection;
                } else {
                    // Save previous run if it has enough words
                    if ($runStart >= 0 && $runWords >= 3) {
                        $matches[] = $this->buildTextMatchResult(
                            $submissionId, $sourceId, $matchType,
                            $runStart, $runEnd, $runWords, $subWords
                        );
                    }
                    $runStart = $i;
                    $runEnd = $matchEnd;
                    $runWords = $intersection;
                }
                $lastMatchEnd = $matchEnd;
            }
        }

        // Don't forget the last run
        if ($runStart >= 0 && $runWords >= 3) {
            $matches[] = $this->buildTextMatchResult(
                $submissionId, $sourceId, $matchType,
                $runStart, $runEnd, $runWords, $subWords
            );
        }

        return $matches;
    }

    private function buildTextMatchResult(
        int $submissionId, int $sourceId, string $matchType,
        int $start, int $end, int $matchedWords, array $subWords
    ): AcademicSimilarityMatchResult {
        $evidence = [[
            'submission_text' => implode(' ', array_slice($subWords, $start, $end - $start)),
            'source_text' => '(topic-level similarity — different wording, same subject matter)',
            'submission_start_offset' => $start,
            'submission_end_offset' => $end,
            'source_start_offset' => 0,
            'source_end_offset' => 0,
        ]];

        return new AcademicSimilarityMatchResult([
            'submission_id' => $submissionId,
            'source_id' => $sourceId,
            'match_type' => 'text-level',
            'confidence' => 0.3,
            'submission_segment_id' => null,
            'source_segment_id' => null,
            'matched_word_count' => $matchedWords,
            'submission_word_range_start' => $start,
            'submission_word_range_end' => $end,
            'source_word_range_start' => 0,
            'source_word_range_end' => 0,
            'segment_match_count' => 1,
            'evidence' => $evidence,
        ]);
    }

    private function loadEntityText(int $entityId, string $entityType): string
    {
        if ($entityType === 'submission') {
            $stmt = $this->db->prepare("
                SELECT extracted_text FROM ac_similarity_text_versions
                WHERE submission_id = :eid AND tenant_id = :tid AND text_type = 'submission'
                ORDER BY id DESC LIMIT 1
            ");
        } else {
            $stmt = $this->db->prepare("
                SELECT extracted_text FROM ac_similarity_text_versions
                WHERE source_id = :eid AND tenant_id = :tid AND text_type = 'source'
                ORDER BY id DESC LIMIT 1
            ");
        }
        $stmt->execute([':eid' => $entityId, ':tid' => $this->tenantId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row['extracted_text'] ?? '';
    }
}
