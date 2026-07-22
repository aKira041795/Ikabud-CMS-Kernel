<?php
declare(strict_types=1);

class AcademicSimilarityMatchingService
{
    private string $tenantId;
    private \Ikabud\Kernel\Contracts\ModuleDB $db;
    private int $minMatchWordThreshold;

    public function __construct(string $tenantId, int $minMatchWordThreshold = 5) {
        $this->tenantId = $tenantId;
        $this->db = academic_similarity_db();
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
    public function runNearExactMatching(int $submissionId): array
    {
        $subFps = $this->loadFingerprints($submissionId, 'near', 'submission');

        if (empty($subFps)) {
            return ['ok' => true, 'matches' => 0, 'match_results' => []];
        }

        $candidates = $this->findCandidateSourcesFromFingerprints($subFps, 'near');

        if (empty($candidates)) {
            return ['ok' => true, 'matches' => 0, 'match_results' => []];
        }

        $allMatches = [];
        foreach ($candidates as $sourceId => $sourceInfo) {
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
     *
     * @param int $entityId
     * @param string $fingerprintType 'exact' or 'near'
     * @param string $entityType 'submission' or 'source'
     * @return array
     */
    private function loadFingerprints(int $entityId, string $fingerprintType, string $entityType): array
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
     * @return array<int, array>
     */
    private function findCandidateSourcesFromFingerprints(array $subFps, string $fingerprintType): array
    {
        $hashes = array_unique(array_column($subFps, 'shingle_hash'));

        if (empty($hashes)) {
            return [];
        }

        // Build placeholder list for IN clause
        $placeholders = [];
        $params = [':tid' => $this->tenantId, ':ft' => $fingerprintType];
        foreach ($hashes as $i => $hash) {
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
            ORDER BY hit_count DESC
        ");
        $stmt->execute($params);

        $candidates = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $sid = (int)$row['source_id'];
            $candidates[$sid] = [
                'source_id' => $sid,
                'fingerprint_hits' => (int)$row['hit_count'],
                'match_confidence' => (int)$row['hit_count'] / max(1, count($subFps)),
            ];
        }

        return $candidates;
    }

    /**
     * Compare a submission to a specific source, finding contiguous word-match runs.
     *
     * @param int $submissionId
     * @param int $sourceId
     * @param array $subFps
     * @param string $matchType
     * @return AcademicSimilarityMatchResult[]
     */
    private function compareSubmissionToSource(
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

        // Load segments for both sides to get normalized content
        $subSegments = $this->loadSegments($submissionId, 'submission');
        $srcSegments = $this->loadSegments($sourceId, 'source');

        // Build word-indexed segment content maps
        $subWords = $this->buildWordIndex($subSegments);
        $srcWords = $this->buildWordIndex($srcSegments);

        // Find matching submission fingerprint positions and map to source positions
        $subPositions = []; // submission word position -> source word position
        foreach ($subFps as $fp) {
            $hash = $fp['shingle_hash'];
            if (isset($srcByHash[$hash])) {
                // Take the first matching source fingerprint
                $srcFp = $srcByHash[$hash][0];
                $subWordPos = (int)$fp['word_position'];
                $srcWordPos = (int)$srcFp['word_position'];
                $subPositions[$subWordPos] = $srcWordPos;
            }
        }

        if (empty($subPositions)) {
            return [];
        }

        // Sort submission positions
        ksort($subPositions);

        // Merge contiguous runs
        $runs = [];
        $currentRun = null;

        foreach ($subPositions as $subPos => $srcPos) {
            if ($currentRun === null) {
                $currentRun = ['sub_start' => $subPos, 'sub_end' => $subPos, 'src_start' => $srcPos, 'src_end' => $srcPos, 'length' => 1];
            } else {
                // Check if this position is contiguous with the current run
                $expectedSub = $currentRun['sub_end'] + 1;
                $expectedSrc = $currentRun['src_end'] + 1;

                if ($subPos === $expectedSub && $srcPos === $expectedSrc) {
                    $currentRun['sub_end'] = $subPos;
                    $currentRun['src_end'] = $srcPos;
                    $currentRun['length']++;
                } else {
                    // End current run, start new
                    if ($currentRun['length'] >= $this->minMatchWordThreshold) {
                        $runs[] = $currentRun;
                    }
                    $currentRun = ['sub_start' => $subPos, 'sub_end' => $subPos, 'src_start' => $srcPos, 'src_end' => $srcPos, 'length' => 1];
                }
            }
        }

        // Don't forget the last run
        if ($currentRun !== null && $currentRun['length'] >= $this->minMatchWordThreshold) {
            $runs[] = $currentRun;
        }

        // Convert runs to MatchResult objects
        $results = [];
        foreach ($runs as $run) {
            // Build evidence
            $evidence = [];
            $subText = $this->extractWordRange($subWords, $run['sub_start'], $run['sub_end']);
            $srcText = $this->extractWordRange($srcWords, $run['src_start'], $run['src_end']);

            $evidence[] = [
                'submission_text' => $subText,
                'source_text' => $srcText,
                'submission_start_offset' => $run['sub_start'],
                'submission_end_offset' => $run['sub_end'],
                'source_start_offset' => $run['src_start'],
                'source_end_offset' => $run['src_end'],
            ];

            $confidence = $run['length'] / max(1, $run['length'] + 1); // Higher confidence for longer runs

            $results[] = new AcademicSimilarityMatchResult([
                'submission_id' => $submissionId,
                'source_id' => $sourceId,
                'match_type' => $matchType,
                'confidence' => min(1.0, $confidence + 0.5),
                'submission_segment_id' => null,
                'source_segment_id' => null,
                'matched_word_count' => $run['length'],
                'submission_word_range_start' => $run['sub_start'],
                'submission_word_range_end' => $run['sub_end'],
                'source_word_range_start' => $run['src_start'],
                'source_word_range_end' => $run['src_end'],
                'segment_match_count' => count($runs),
                'evidence' => $evidence,
            ]);
        }

        return $results;
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
}
