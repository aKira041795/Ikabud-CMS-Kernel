<?php
declare(strict_types=1);

class AcademicSimilarityReportService
{
    private string $tenantId;
    private \Ikabud\Kernel\Contracts\ModuleDB $db;

    private AcademicSimilarityReportRepository $reportRepo;
    private AcademicSimilaritySubmissionRepository $submissionRepo;
    private AcademicSimilarityMatchRepository $matchRepo;
    private AcademicSimilaritySourceRepository $sourceRepo;
    private AcademicSimilarityAuditRepository $auditRepo;

    public function __construct(string $tenantId)
    {
        $this->tenantId = $tenantId;
        $this->db = academic_similarity_db();
        $this->reportRepo = new AcademicSimilarityReportRepository($tenantId);
        $this->submissionRepo = new AcademicSimilaritySubmissionRepository($tenantId);
        $this->matchRepo = new AcademicSimilarityMatchRepository($tenantId);
        $this->sourceRepo = new AcademicSimilaritySourceRepository($tenantId);
        $this->auditRepo = new AcademicSimilarityAuditRepository($tenantId);
    }

    /**
     * Get report data for a submission (view/download).
     *
     * @param int $submissionId
     * @return array{ok: bool, report?: array, matches?: array, submission?: array, error?: string}
     */
    public function get(int $submissionId): array
    {
        $submission = $this->submissionRepo->findById($submissionId);
        if ($submission === null) {
            return ['ok' => false, 'error' => 'Submission not found'];
        }

        $report = $this->reportRepo->findBySubmissionId($submissionId);
        $matches = $this->matchRepo->findBySubmissionId($submissionId);
        $internetStmt = $this->db->prepare(
            "SELECT id, provider, status, query_count, candidate_count, imported_count,
                    payload_policy, disclosure, error_message, completed_at
             FROM ac_similarity_internet_search_runs
             WHERE submission_id = :sid AND tenant_id = :tid
             ORDER BY id DESC
             LIMIT 1"
        );
        $internetStmt->execute([':sid' => $submissionId, ':tid' => $this->tenantId]);
        $internetCoverage = $internetStmt->fetch(\PDO::FETCH_ASSOC) ?: null;

        return [
            'ok' => true,
            'report' => $report,
            'matches' => $matches,
            'submission' => $submission,
            'internet_coverage' => $internetCoverage,
        ];
    }

    /**
     * Static helper to get a report for a submission by tenant ID.
     *
     * @param string $tenantId
     * @param int    $submissionId
     * @return array|null
     */
    public static function getForSubmission(string $tenantId, int $submissionId): ?array
    {
        $service = new self($tenantId);
        $result = $service->get($submissionId);
        return ($result['ok'] ?? false) ? $result['report'] : null;
    }

    /**
     * Find a report by its ID.
     */
    public function findById(int $id): ?array
    {
        return $this->reportRepo->findById($id);
    }

    /**
     * Search reports with optional submission ID filter.
     *
     * @param int $submissionId
     * @param int $page
     * @param int $perPage
     * @return array
     */
    public function search(int $submissionId = 0, int $page = 1, int $perPage = 50, string $search = '', string $sort = 'newest'): array
    {
        $conditions = ['r.tenant_id = :tid'];
        $params = [':tid' => $this->tenantId];

        if ($submissionId > 0) {
            $conditions[] = 'r.submission_id = :sid';
            $params[':sid'] = $submissionId;
        }
        if ($search !== '') {
            $conditions[] = '(s.submission_title LIKE :search_title OR s.author_name LIKE :search_author)';
            $params[':search_title'] = '%' . $search . '%';
            $params[':search_author'] = '%' . $search . '%';
        }

        $where = implode(' AND ', $conditions);
        $offset = ($page - 1) * $perPage;
        $orderBy = match ($sort) {
            'oldest' => 'r.generated_at ASC, r.id ASC',
            'score_high' => 'COALESCE(r.adjusted_score, r.raw_score, 0) DESC, r.generated_at DESC',
            'score_low' => 'COALESCE(r.adjusted_score, r.raw_score, 0) ASC, r.generated_at DESC',
            default => 'r.generated_at DESC, r.id DESC',
        };

        $stmt = $this->db->prepare(
            "SELECT r.*,
                    r.id AS report_id,
                    r.raw_score AS raw_similarity_score,
                    r.adjusted_score AS adjusted_similarity_score,
                    r.total_matches AS match_count,
                    COALESCE(NULLIF(r.total_eligible_words, 0), s.word_count, 0) AS eligible_word_count,
                    s.submission_title,
                    s.author_name,
                    s.word_count as submission_word_count
             FROM ac_similarity_reports r
             LEFT JOIN ac_similarity_submissions s ON r.submission_id = s.id AND s.tenant_id = r.tenant_id
             WHERE {$where}
             ORDER BY {$orderBy}
             LIMIT {$perPage} OFFSET {$offset}"
        );
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function stats(int $submissionId = 0, string $search = ''): array
    {
        $conditions = ['r.tenant_id = :tid'];
        $params = [':tid' => $this->tenantId];

        if ($submissionId > 0) {
            $conditions[] = 'r.submission_id = :sid';
            $params[':sid'] = $submissionId;
        }
        if ($search !== '') {
            $conditions[] = '(s.submission_title LIKE :search_title OR s.author_name LIKE :search_author)';
            $params[':search_title'] = '%' . $search . '%';
            $params[':search_author'] = '%' . $search . '%';
        }

        $where = implode(' AND ', $conditions);
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) AS total_reports,
                    AVG(r.raw_score) AS avg_raw_score,
                    AVG(r.adjusted_score) AS avg_adjusted_score,
                    MAX(r.adjusted_score) AS highest_adjusted_score,
                    SUM(r.total_matches) AS total_matches,
                    SUM(r.matched_word_count) AS matched_word_count,
                    SUM(COALESCE(NULLIF(r.total_eligible_words, 0), s.word_count, 0)) AS eligible_word_count,
                    SUM(CASE WHEN COALESCE(r.adjusted_score, r.raw_score, 0) >= 50 THEN 1 ELSE 0 END) AS high_risk_reports,
                    SUM(CASE WHEN DATE(r.generated_at) = CURDATE() THEN 1 ELSE 0 END) AS generated_today
             FROM ac_similarity_reports r
             LEFT JOIN ac_similarity_submissions s ON r.submission_id = s.id AND s.tenant_id = r.tenant_id
             WHERE {$where}"
        );
        $stmt->execute($params);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];

        return [
            'total_reports' => (int)($row['total_reports'] ?? 0),
            'avg_raw_score' => round((float)($row['avg_raw_score'] ?? 0), 1),
            'avg_adjusted_score' => round((float)($row['avg_adjusted_score'] ?? 0), 1),
            'highest_adjusted_score' => round((float)($row['highest_adjusted_score'] ?? 0), 1),
            'total_matches' => (int)($row['total_matches'] ?? 0),
            'matched_word_count' => (int)($row['matched_word_count'] ?? 0),
            'eligible_word_count' => (int)($row['eligible_word_count'] ?? 0),
            'high_risk_reports' => (int)($row['high_risk_reports'] ?? 0),
            'generated_today' => (int)($row['generated_today'] ?? 0),
        ];
    }

    /**
     * Generate a report from match data for a submission.
     *
     * @param int $submissionId
     * @return array{ok: bool, report_id?: int, error?: string}
     */
    public function generate(int $submissionId): array
    {
        $submission = $this->submissionRepo->findById($submissionId);
        if ($submission === null) {
            return ['ok' => false, 'error' => 'Submission not found'];
        }

        $matches = $this->matchRepo->findBySubmissionId($submissionId);
        $activeMatches = array_values(array_filter($matches, fn(array $m): bool => ($m['is_excluded'] ?? 0) == 0));
        $excludedMatches = array_values(array_filter($matches, fn(array $m): bool => ($m['is_excluded'] ?? 0) == 1));

        $totalMatchedWords = 0;
        $totalEligibleWords = (int)($submission['word_count'] ?? 0);
        $excludedWordDeduction = 0;

        foreach ($activeMatches as $match) {
            $totalMatchedWords += (int)($match['matched_word_count'] ?? 0);
        }
        foreach ($excludedMatches as $match) {
            $excludedWordDeduction += (int)($match['matched_word_count'] ?? 0);
        }

        $rawScore = $totalEligibleWords > 0
            ? round(($totalMatchedWords / $totalEligibleWords) * 100, 2)
            : 0.0;

        $adjustedScore = $totalEligibleWords > 0
            ? round((max(0, $totalMatchedWords) / $totalEligibleWords) * 100, 2)
            : 0.0;

        $reportData = [
            'submission_id' => $submissionId,
            'total_matches' => count($activeMatches),
            'total_excluded' => count($excludedMatches),
            'matched_word_count' => $totalMatchedWords,
            'total_eligible_words' => $totalEligibleWords,
            'raw_score' => $rawScore,
            'adjusted_score' => $adjustedScore,
            'exclusion_word_deduction' => $excludedWordDeduction,
            'report_version' => '1.0.0',
            'match_engine_version' => '1.0.0',
            'semantic_model_version' => null,
            'report_format' => 'html',
            'report_data_json' => [
                'generated_at' => date('c'),
                'match_summary' => [
                    'total_matches' => count($activeMatches),
                    'total_excluded' => count($excludedMatches),
                    'matched_word_count' => $totalMatchedWords,
                    'total_eligible_words' => $totalEligibleWords,
                    'raw_score' => $rawScore,
                    'adjusted_score' => $adjustedScore,
                ],
                'matches' => array_map(function (array $m): array {
                    return [
                        'match_id' => (int)$m['id'],
                        'source_id' => (int)($m['source_id'] ?? 0),
                        'match_type' => $m['match_type'] ?? '',
                        'match_confidence' => (float)($m['match_confidence'] ?? 0),
                        'matched_word_count' => (int)($m['matched_word_count'] ?? 0),
                        'is_excluded' => (bool)($m['is_excluded'] ?? false),
                    ];
                }, $matches),
            ],
        ];

        // Compute checksum over the report data
        $reportChecksum = hash('sha256', json_encode($reportData));

        $reportId = $this->reportRepo->create([
            'submission_id' => $submissionId,
            'report_version' => $reportData['report_version'],
            'match_engine_version' => $reportData['match_engine_version'],
            'semantic_model_version' => $reportData['semantic_model_version'],
            'raw_score' => $rawScore,
            'adjusted_score' => $adjustedScore,
            'total_matches' => $reportData['total_matches'],
            'total_excluded' => $reportData['total_excluded'],
            'matched_word_count' => $totalMatchedWords,
            'total_eligible_words' => $totalEligibleWords,
            'exclusion_word_deduction' => $excludedWordDeduction,
            'report_checksum' => $reportChecksum,
            'report_format' => $reportData['report_format'],
            'report_data_json' => $reportData['report_data_json'],
        ]);

        // Update submission with scores
        $this->submissionRepo->updateScore($submissionId, $rawScore, $adjustedScore, $totalMatchedWords, $totalEligibleWords);

        // Record audit
        try {
            $this->auditRepo->record(
                'report.generated',
                0,
                'system',
                'report',
                $reportId,
                "Generated report for submission #{$submissionId} (raw: {$rawScore}%, adjusted: {$adjustedScore}%)",
                [
                    'submission_id' => $submissionId,
                    'raw_score' => $rawScore,
                    'adjusted_score' => $adjustedScore,
                    'total_matches' => $reportData['total_matches'],
                    'total_excluded' => $reportData['total_excluded'],
                ]
            );
        } catch (\Throwable $e) {
            write_log('Failed to record audit event for report ' . $reportId . ': ' . $e->getMessage());
        }

        return [
            'ok' => true,
            'report_id' => $reportId,
        ];
    }

    /**
     * Download a report as HTML or JSON.
     * Outputs the report content and terminates.
     *
     * @param int $reportId
     */
    public function download(int $reportId): void
    {
        $report = $this->reportRepo->findById($reportId);
        if ($report === null) {
            http_response_code(404);
            echo 'Report not found';
            return;
        }

        $format = (string)($_GET['format'] ?? 'pdf');

        if ($format === 'json') {
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="similarity-report-' . $reportId . '.json"');

            $output = [
                'report_id' => (int)$report['id'],
                'submission_id' => (int)$report['submission_id'],
                'generated_at' => $report['generated_at'] ?? null,
                'raw_score' => isset($report['raw_score']) ? (float)$report['raw_score'] : null,
                'adjusted_score' => isset($report['adjusted_score']) ? (float)$report['adjusted_score'] : null,
                'total_matches' => (int)($report['total_matches'] ?? 0),
                'total_excluded' => (int)($report['total_excluded'] ?? 0),
                'matched_word_count' => (int)($report['matched_word_count'] ?? 0),
                'total_eligible_words' => (int)($report['total_eligible_words'] ?? 0),
                'report_data' => !empty($report['report_data_json'])
                    ? (is_string($report['report_data_json']) ? json_decode($report['report_data_json'], true) : $report['report_data_json'])
                    : null,
            ];

            echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            return;
        }

        $submission = $this->submissionRepo->findById((int)$report['submission_id']);
        $matches = $this->matchRepo->findBySubmissionId((int)$report['submission_id']);
        $sourceCache = [];
        $evidenceCache = [];
        foreach ($matches as $match) {
            $sourceId = (int)($match['source_id'] ?? 0);
            if ($sourceId > 0 && !isset($sourceCache[$sourceId])) {
                $sourceCache[$sourceId] = $this->sourceRepo->findById($sourceId) ?? [];
            }
            $matchId = (int)($match['id'] ?? 0);
            if ($matchId > 0) {
                $evidenceCache[$matchId] = $this->matchRepo->getEvidence($matchId);
            }
        }
        $internetBySource = [];
        try {
            $internetBySource = (new AcademicSimilarityInternetSourceRepository($this->tenantId))->findBySourceIds(array_keys($sourceCache));
        } catch (\Throwable $exception) {
            if (function_exists('write_log')) {
                write_log('warning', 'AISS internet source provenance unavailable for report export', [
                    'report_id' => $reportId,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        $title = htmlspecialchars($submission['submission_title'] ?? 'Similarity Report');
        $rawScore = isset($report['raw_score']) ? round((float)$report['raw_score'], 2) : 'N/A';
        $adjustedScore = isset($report['adjusted_score']) ? round((float)$report['adjusted_score'], 2) : 'N/A';

        if ($format === 'html') {
            header('Content-Type: text/html; charset=utf-8');
            header('Content-Disposition: attachment; filename="similarity-report-' . $reportId . '.html"');
        } else {
            ob_start();
        }

        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' . $title . ' — Similarity Report</title>';
        echo '<style>
body{font-family:sans-serif;max-width:960px;margin:2rem auto;padding:0 1rem;color:#333;}
h1{color:#1a1a2e;border-bottom:2px solid #e0e0e0;padding-bottom:.5rem;}
.score{font-size:1.5rem;font-weight:bold;margin:1rem 0;}
.score .raw{color:#c0392b;}
.score .adjusted{color:#27ae60;}
table{width:100%;border-collapse:collapse;margin:1rem 0;}
th,td{text-align:left;padding:.5rem;border-bottom:1px solid #ddd;}
th{background:#f5f5f5;}
.match-excluded{color:#999;text-decoration:line-through;}
.summary{display:grid;grid-template-columns:repeat(4,1fr);gap:.75rem;margin:1rem 0;}
.summary div{border:1px solid #ddd;background:#f8f9fa;padding:.75rem;}
.label{font-size:.75rem;color:#666;text-transform:uppercase;}
.value{font-size:1.25rem;font-weight:bold;margin-top:.25rem;}
.issue{page-break-inside:avoid;border:1px solid #ddd;margin:1rem 0;padding:1rem;border-radius:6px;}
.issue h3{margin:.25rem 0 .75rem 0;}
.issue-meta{color:#666;font-size:.85rem;margin-bottom:.75rem;}
.excerpt{border-left:4px solid #c0392b;background:#fff5f5;padding:.75rem;margin:.5rem 0;white-space:pre-wrap;}
.source-excerpt{border-left-color:#2563eb;background:#eff6ff;}
.footer{color:#999;font-size:.85rem;border-top:1px solid #eee;padding-top:1rem;margin-top:2rem;}
</style></head><body>';
        echo '<h1>Similarity Report: ' . htmlspecialchars($title) . '</h1>';
        echo '<div class="score">';
        echo 'Raw Score: <span class="raw">' . $rawScore . '%</span><br>';
        echo 'Adjusted Score: <span class="adjusted">' . $adjustedScore . '%</span>';
        echo '</div>';
        echo '<div class="summary">';
        echo '<div><span class="label">Matches</span><p class="value">' . (int)($report['total_matches'] ?? count($matches)) . '</p></div>';
        echo '<div><span class="label">Matched Words</span><p class="value">' . (int)($report['matched_word_count'] ?? 0) . '</p></div>';
        echo '<div><span class="label">Eligible Words</span><p class="value">' . (int)($report['total_eligible_words'] ?? ($submission['word_count'] ?? 0)) . '</p></div>';
        echo '<div><span class="label">Excluded</span><p class="value">' . (int)($report['total_excluded'] ?? 0) . '</p></div>';
        echo '</div>';

        echo '<table><thead><tr>
<th>#</th><th>Source</th><th>Type</th><th>Confidence</th><th>Matched Words</th><th>Status</th>
</tr></thead><tbody>';
        foreach ($matches as $i => $match) {
            $sourceId = (int)($match['source_id'] ?? 0);
            $source = $sourceCache[$sourceId] ?? [];
            $sourceTitle = (string)($source['title'] ?? ('Source #' . $sourceId));
            $internet = $internetBySource[$sourceId] ?? null;
            $class = ($match['is_excluded'] ?? 0) ? ' class="match-excluded"' : '';
            echo '<tr' . $class . '>';
            echo '<td>' . ($i + 1) . '</td>';
            echo '<td>' . htmlspecialchars($sourceTitle) . ($internet ? '<br><small>Internet-discovered</small>' : '') . '</td>';
            echo '<td>' . htmlspecialchars($match['match_type'] ?? '') . '</td>';
            echo '<td>' . round(((float)($match['match_confidence'] ?? 0)) * 100, 1) . '%</td>';
            echo '<td>' . (int)($match['matched_word_count'] ?? 0) . '</td>';
            echo '<td>' . (($match['is_excluded'] ?? 0) ? 'Excluded' : 'Active') . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';

        echo '<h2>Issue Passages</h2>';
        if (empty($matches)) {
            echo '<p>No matched passages were recorded for this report.</p>';
        }
        foreach ($matches as $i => $match) {
            $matchId = (int)($match['id'] ?? 0);
            $sourceId = (int)($match['source_id'] ?? 0);
            $source = $sourceCache[$sourceId] ?? [];
            $sourceTitle = (string)($source['title'] ?? ('Source #' . $sourceId));
            $internet = $internetBySource[$sourceId] ?? null;
            $evidenceRows = $evidenceCache[$matchId] ?? [];
            echo '<div class="issue">';
            echo '<h3>Issue ' . ($i + 1) . ': ' . htmlspecialchars(ucwords(str_replace('-', ' ', (string)($match['match_type'] ?? 'match')))) . '</h3>';
            echo '<div class="issue-meta">Source: <strong>' . htmlspecialchars($sourceTitle) . '</strong>';
            if (!empty($source['author'])) {
                echo ' by ' . htmlspecialchars((string)$source['author']);
            }
            if ($internet) {
                echo ' | Internet-discovered source';
                if (!empty($internet['source_url'])) {
                    echo '<br>URL: ' . htmlspecialchars((string)$internet['source_url']);
                }
                if (!empty($internet['retrieved_at'])) {
                    echo '<br>Retrieved: ' . htmlspecialchars((string)$internet['retrieved_at']);
                }
            }
            echo ' | Confidence: ' . round(((float)($match['match_confidence'] ?? 0)) * 100, 1) . '%';
            echo ' | Matched words: ' . (int)($match['matched_word_count'] ?? 0);
            echo (($match['is_excluded'] ?? 0) ? ' | Status: Excluded' : ' | Status: Active') . '</div>';

            if (empty($evidenceRows)) {
                echo '<p>No passage evidence was stored for this match.</p>';
            }
            foreach ($evidenceRows as $evidence) {
                echo '<div class="label">Submission content with issue</div>';
                echo '<div class="excerpt">' . nl2br(htmlspecialchars((string)($evidence['submission_segment_text'] ?? ''))) . '</div>';
                echo '<div class="label">Matched source content</div>';
                echo '<div class="excerpt source-excerpt">' . nl2br(htmlspecialchars((string)($evidence['source_segment_text'] ?? ''))) . '</div>';
            }
            echo '</div>';
        }

        echo '<div class="footer">';
        echo 'Report ID: ' . $reportId . ' | ';
        echo 'Generated: ' . htmlspecialchars($report['generated_at'] ?? 'N/A') . ' | ';
        echo 'Matched Words: ' . (int)($report['matched_word_count'] ?? 0) . ' / ' . (int)($report['total_eligible_words'] ?? 0);
        echo '</div>';
        echo '</body></html>';

        if ($format !== 'html') {
            $html = (string)ob_get_clean();
            $dompdf = new \Dompdf\Dompdf(['isRemoteEnabled' => false]);
            $dompdf->loadHtml($html);
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->render();
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="similarity-report-' . $reportId . '.pdf"');
            echo $dompdf->output();
        }
    }
}
