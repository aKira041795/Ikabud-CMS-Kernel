<?php
declare(strict_types=1);

/**
 * Academic Similarity — User Result Service.
 *
 * Assembles front-facing result statistics for a logged-in CMS user.
 * All queries are scoped to the current tenant and submitter user ID.
 * No admin-only data is exposed.
 */
class AcademicSimilarityUserResultService
{
    private string $tenantId;
    private \Ikabud\Kernel\Contracts\ModuleDB $db;
    private AcademicSimilaritySubmissionRepository $subRepo;
    private AcademicSimilarityReportRepository $reportRepo;

    public function __construct(string $tenantId)
    {
        $this->tenantId = $tenantId;
        $this->db = academic_similarity_db();
        $this->subRepo = new AcademicSimilaritySubmissionRepository($tenantId);
        $this->reportRepo = new AcademicSimilarityReportRepository($tenantId);
    }

    /**
     * Get summary stats for a logged-in submitter.
     *
     * @param int $submitterUserId
     * @return array{
     *     total_submissions: int,
     *     processed_count: int,
     *     pending_count: int,
     *     failed_count: int,
     *     avg_adjusted_score: float,
     *     highest_adjusted_score: float,
     *     total_matches: int,
     *     latest_report_date: string|null,
     * }
     */
    public function getSummaryStats(int $submitterUserId): array
    {
        $stats = [
            'total_submissions'     => 0,
            'processed_count'       => 0,
            'pending_count'         => 0,
            'failed_count'          => 0,
            'avg_adjusted_score'    => 0.0,
            'highest_adjusted_score' => 0.0,
            'total_matches'         => 0,
            'latest_report_date'    => null,
        ];

        try {
            $stmt = $this->db->prepare("
                SELECT
                    COUNT(*) AS total,
                    SUM(CASE WHEN status = 'processed' THEN 1 ELSE 0 END) AS processed,
                    SUM(CASE WHEN status IN ('pending','processing') THEN 1 ELSE 0 END) AS pending,
                    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed,
                    AVG(NULLIF(adjusted_similarity_score, -1)) AS avg_score,
                    MAX(adjusted_similarity_score) AS highest_score,
                    COALESCE(SUM(matched_word_count), 0) AS total_matched
                FROM ac_similarity_submissions
                WHERE tenant_id = :tid AND submitter_user_id = :uid
            ");
            $stmt->execute([':tid' => $this->tenantId, ':uid' => $submitterUserId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($row) {
                $stats['total_submissions'] = (int)($row['total'] ?? 0);
                $stats['processed_count'] = (int)($row['processed'] ?? 0);
                $stats['pending_count'] = (int)($row['pending'] ?? 0);
                $stats['failed_count'] = (int)($row['failed'] ?? 0);
                $stats['avg_adjusted_score'] = $row['avg_score'] !== null ? round((float)$row['avg_score'], 1) : 0.0;
                $stats['highest_adjusted_score'] = $row['highest_score'] !== null ? round((float)$row['highest_score'], 1) : 0.0;
                $stats['total_matches'] = (int)($row['total_matched'] ?? 0);
            }

            // Latest report date
            $rStmt = $this->db->prepare("
                SELECT MAX(r.generated_at) AS latest_date
                FROM ac_similarity_reports r
                JOIN ac_similarity_submissions s ON r.submission_id = s.id AND s.tenant_id = r.tenant_id
                WHERE s.tenant_id = :tid AND s.submitter_user_id = :uid
            ");
            $rStmt->execute([':tid' => $this->tenantId, ':uid' => $submitterUserId]);
            $rRow = $rStmt->fetch(\PDO::FETCH_ASSOC);
            if ($rRow && $rRow['latest_date'] !== null) {
                $stats['latest_report_date'] = $rRow['latest_date'];
            }
        } catch (\Throwable $e) {
            write_log('UserResultService::getSummaryStats failed: ' . $e->getMessage());
        }

        return $stats;
    }

    /**
     * Get recent submissions for a logged-in submitter.
     *
     * @param int $submitterUserId
     * @param int $limit
     * @return array
     */
    public function getRecentSubmissions(int $submitterUserId, int $limit = 10): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT s.id, s.submission_title, s.status, s.submitted_at, s.processed_at,
                       s.raw_similarity_score, s.adjusted_similarity_score,
                       s.matched_word_count, s.total_eligible_words, s.word_count,
                       s.processing_error,
                       r.id AS report_id, r.generated_at AS report_generated_at
                FROM ac_similarity_submissions s
                LEFT JOIN ac_similarity_reports r ON r.submission_id = s.id AND r.tenant_id = s.tenant_id
                WHERE s.tenant_id = :tid AND s.submitter_user_id = :uid
                ORDER BY s.created_at DESC
                LIMIT :lim
            ");
            $stmt->bindValue(':tid', $this->tenantId, \PDO::PARAM_STR);
            $stmt->bindValue(':uid', $submitterUserId, \PDO::PARAM_INT);
            $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            write_log('UserResultService::getRecentSubmissions failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get a single submission's safe report summary for a submitter.
     * Returns null if the submission does not belong to this user.
     *
     * @param int $submissionId
     * @param int $submitterUserId
     * @return array|null
     */
    public function getReportSummary(int $submissionId, int $submitterUserId): ?array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT s.id, s.submission_title, s.author_name, s.status,
                       s.submitted_at, s.processed_at,
                       s.raw_similarity_score, s.adjusted_similarity_score,
                       s.matched_word_count, s.total_eligible_words, s.word_count,
                       r.id AS report_id, r.generated_at AS report_generated_at,
                       r.raw_score, r.adjusted_score AS report_adjusted_score,
                       r.total_matches, r.total_excluded
                FROM ac_similarity_submissions s
                LEFT JOIN ac_similarity_reports r ON r.submission_id = s.id AND r.tenant_id = s.tenant_id
                WHERE s.id = :sid AND s.tenant_id = :tid AND s.submitter_user_id = :uid
                LIMIT 1
            ");
            $stmt->execute([
                ':sid' => $submissionId,
                ':tid' => $this->tenantId,
                ':uid' => $submitterUserId,
            ]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (\Throwable $e) {
            write_log('UserResultService::getReportSummary failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Get the authenticated user from the current request.
     * Returns null if not logged in.
     *
     * @return array|null
     */
    public static function getCurrentUser(): ?array
    {
        try {
            $user = app()->user();
            if (!is_array($user) || empty($user)) {
                return null;
            }
            return $user;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Get the submitter user ID from the current request.
     * Returns 0 if not logged in.
     */
    public static function getCurrentUserId(): int
    {
        $user = self::getCurrentUser();
        if ($user === null) {
            return 0;
        }
        return (int)($user['id'] ?? $user['sub'] ?? 0);
    }

    /**
     * Get the submitter source string from the current request.
     */
    public static function getCurrentUserSource(): string
    {
        $user = self::getCurrentUser();
        if ($user === null) {
            return '';
        }
        return (string)($user['source'] ?? '');
    }
}
