<?php
declare(strict_types=1);

class AcademicSimilarityReviewService
{
    private string $tenantId;
    private \Ikabud\Kernel\Contracts\ModuleDB $db;

    private AcademicSimilarityMatchRepository $matchRepo;
    private AcademicSimilarityAuditRepository $auditRepo;
    private AcademicSimilarityReportRepository $reportRepo;
    private AcademicSimilaritySubmissionRepository $submissionRepo;

    public function __construct(string $tenantId)
    {
        $this->tenantId = $tenantId;
        $this->db = academic_similarity_db();
        $this->matchRepo = new AcademicSimilarityMatchRepository($tenantId);
        $this->auditRepo = new AcademicSimilarityAuditRepository($tenantId);
        $this->reportRepo = new AcademicSimilarityReportRepository($tenantId);
        $this->submissionRepo = new AcademicSimilaritySubmissionRepository($tenantId);
    }

    /**
     * Exclude a match from similarity scoring.
     *
     * @param int    $matchId
     * @param string $reason  Exclusion reason (e.g. 'false_positive', 'quotation', 'bibliography')
     * @param string $note    Optional reviewer note
     * @return array{ok: bool, error?: string, match_id?: int, adjusted_score?: float}
     */
    public function excludeMatch(int $matchId, string $reason, string $note = ''): array
    {
        // 1. Load match and verify it belongs to this tenant
        $match = $this->matchRepo->findById($matchId);
        if ($match === null) {
            return ['ok' => false, 'error' => 'Match not found'];
        }

        if (($match['is_excluded'] ?? 0) == 1) {
            return ['ok' => false, 'error' => 'Match is already excluded'];
        }

        $submissionId = (int)($match['submission_id'] ?? 0);

        // 2. Get current submission score for history
        $submission = $this->submissionRepo->findById($submissionId);
        $previousScore = $submission !== null ? (float)($submission['adjusted_similarity_score'] ?? 0.0) : 0.0;

        $matchedWordCount = (int)($match['matched_word_count'] ?? 0);

        // 3. Record exclusion
        $stmt = $this->db->prepare(
            "INSERT INTO ac_similarity_exclusions (tenant_id, match_id, submission_id, reason, note, excluded_by_id, excluded_by, previous_score)
             VALUES (:tid, :mid, :sid, :reason, :note, :actor_id, :actor_name, :prev_score)"
        );
        $stmt->execute([
            ':tid' => $this->tenantId,
            ':mid' => $matchId,
            ':sid' => $submissionId,
            ':reason' => $reason,
            ':note' => $note,
            ':actor_id' => 0,
            ':actor_name' => 'system',
            ':prev_score' => $previousScore,
        ]);

        $exclusionId = (int)$this->db->lastInsertId();

        // 4. Mark match as excluded
        $stmt = $this->db->prepare(
            "UPDATE ac_similarity_matches SET is_excluded = 1, excluded_at = NOW(), exclusion_reason = :reason, exclusion_note = :note WHERE id = :mid AND tenant_id = :tid"
        );
        $stmt->execute([
            ':mid' => $matchId,
            ':tid' => $this->tenantId,
            ':reason' => $reason,
            ':note' => $note,
        ]);

        // 5. Record audit event
        try {
            $this->auditRepo->record(
                'review.excluded',
                0,
                'system',
                'match',
                $matchId,
                "Excluded match #{$matchId} from submission #{$submissionId} (reason: {$reason})",
                [
                    'submission_id' => $submissionId,
                    'reason' => $reason,
                    'note' => $note,
                    'excluded_word_count' => $matchedWordCount,
                    'previous_score' => $previousScore,
                ]
            );
        } catch (\Throwable $e) {
            write_log('Failed to record audit event for match exclusion ' . $matchId . ': ' . $e->getMessage());
        }

        // 6. Recalculate adjusted score
        $recalcResult = $this->recalculateScore($submissionId);
        $adjustedScore = $recalcResult['adjusted_score'] ?? 0.0;

        return [
            'ok' => true,
            'match_id' => $matchId,
            'exclusion_id' => $exclusionId,
            'adjusted_score' => $adjustedScore,
        ];
    }

    /**
     * Get all exclusions for a submission.
     *
     * @param int $submissionId
     * @return array
     */
    public function getExclusions(int $submissionId): array
    {
        $stmt = $this->db->prepare(
            "SELECT e.*, m.match_type, m.match_confidence, m.matched_word_count, m.source_id
             FROM ac_similarity_exclusions e
             LEFT JOIN ac_similarity_matches m ON e.match_id = m.id AND m.tenant_id = e.tenant_id
             WHERE e.submission_id = :sid AND e.tenant_id = :tid
             ORDER BY e.created_at DESC"
        );
        $stmt->execute([':sid' => $submissionId, ':tid' => $this->tenantId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Recalculate the adjusted similarity score after exclusions.
     *
     * @param int $submissionId
     * @return array{ok: bool, adjusted_score: float, excluded_word_count: int}
     */
    public function recalculateScore(int $submissionId): array
    {
        $submission = $this->submissionRepo->findById($submissionId);
        if ($submission === null) {
            return ['ok' => false, 'adjusted_score' => 0.0, 'excluded_word_count' => 0];
        }

        $totalEligibleWords = (int)($submission['word_count'] ?? 0);
        $activeMatches = $this->matchRepo->findActive($submissionId);

        $activeMatchedWords = 0;
        foreach ($activeMatches as $match) {
            $activeMatchedWords += (int)($match['matched_word_count'] ?? 0);
        }

        $adjustedScore = $totalEligibleWords > 0
            ? round(($activeMatchedWords / $totalEligibleWords) * 100, 2)
            : 0.0;

        // Get total excluded word count
        $excludedMatches = $this->matchRepo->findExcluded($submissionId);
        $excludedWordCount = 0;
        foreach ($excludedMatches as $match) {
            $excludedWordCount += (int)($match['matched_word_count'] ?? 0);
        }

        // Update submission with recalculated score
        $totalMatchedWords = (int)($submission['matched_word_count'] ?? 0);
        $this->submissionRepo->updateScore(
            $submissionId,
            (float)($submission['raw_similarity_score'] ?? $adjustedScore),
            $adjustedScore,
            $totalMatchedWords,
            $totalEligibleWords
        );

        // Update the latest report's adjusted score
        $report = $this->reportRepo->findBySubmissionId($submissionId);
        if ($report !== null) {
            $stmt = $this->db->prepare(
                "UPDATE ac_similarity_reports 
                 SET adjusted_score = :adj, total_excluded = :tex, exclusion_word_deduction = :ewd
                 WHERE id = :rid AND tenant_id = :tid"
            );
            $stmt->execute([
                ':adj' => $adjustedScore,
                ':tex' => count($excludedMatches),
                ':ewd' => $excludedWordCount,
                ':rid' => (int)$report['id'],
                ':tid' => $this->tenantId,
            ]);
        }

        return [
            'ok' => true,
            'adjusted_score' => $adjustedScore,
            'excluded_word_count' => $excludedWordCount,
        ];
    }
}
