<?php
declare(strict_types=1);

/**
 * Academic Similarity — Public Report View Service.
 *
 * Assembles a safe, user-scoped report view model for the front-facing
 * shortcode workspace. All queries verify submitter_user_id ownership.
 * No admin-only data is exposed.
 */
class AcademicSimilarityPublicReportViewService
{
    private string $tenantId;
    private \Ikabud\Kernel\Contracts\ModuleDB $db;
    private AcademicSimilaritySubmissionRepository $subRepo;
    private AcademicSimilarityReportRepository $reportRepo;
    private AcademicSimilarityMatchRepository $matchRepo;
    private AcademicSimilaritySourceRepository $sourceRepo;

    public function __construct(string $tenantId)
    {
        $this->tenantId = $tenantId;
        $this->db = academic_similarity_db();
        $this->subRepo = new AcademicSimilaritySubmissionRepository($tenantId);
        $this->reportRepo = new AcademicSimilarityReportRepository($tenantId);
        $this->matchRepo = new AcademicSimilarityMatchRepository($tenantId);
        $this->sourceRepo = new AcademicSimilaritySourceRepository($tenantId);
    }

    /**
     * Get the full public report view model for a submission owned by the user.
     * Returns null if the submission doesn't exist or doesn't belong to the user.
     */
    public function getView(int $submissionId, int $submitterUserId, bool $showSourceNames = true): ?array
    {
        if ($submissionId <= 0 || $submitterUserId <= 0) {
            return null;
        }

        $submission = $this->subRepo->findById($submissionId);
        if ($submission === null) {
            return null;
        }

        // Ownership check at service level
        $ownerId = (int)($submission['submitter_user_id'] ?? 0);
        if ($ownerId <= 0 || $ownerId !== $submitterUserId) {
            return null;
        }

        $report = $this->reportRepo->findBySubmissionId($submissionId);
        $matches = $this->matchRepo->findBySubmissionId($submissionId);

        // Build source cache
        $sourceCache = [];
        foreach ($matches as $match) {
            $sid = (int)($match['source_id'] ?? 0);
            if ($sid > 0 && !isset($sourceCache[$sid])) {
                $src = $this->sourceRepo->findById($sid);
                if ($src) {
                    $sourceCache[$sid] = $src;
                }
            }
        }
        $internetBySource = [];
        try {
            $internetBySource = (new AcademicSimilarityInternetSourceRepository($this->tenantId))->findBySourceIds(array_keys($sourceCache));
        } catch (\Throwable $e) {
            write_log('PublicReportViewService: failed to load internet provenance: ' . $e->getMessage());
        }
        if (!$showSourceNames) {
            foreach ($sourceCache as &$src) {
                $src['title'] = 'Matched Source';
                $src['author'] = '';
            }
            unset($src);
        }

        // Build evidence map
        $evidenceMap = [];
        foreach ($matches as $match) {
            $mid = (int)($match['id'] ?? 0);
            $evidenceMap[$mid] = $this->matchRepo->getEvidence($mid);
        }

        // Load text for highlighted rendering
        $submissionText = '';
        $sourceTexts = [];
        try {
            $tvStmt = $this->db->prepare(
                "SELECT extracted_text FROM ac_similarity_text_versions WHERE submission_id = :sid AND tenant_id = :tid AND text_type = 'submission' ORDER BY id DESC LIMIT 1"
            );
            $tvStmt->execute([':sid' => $submissionId, ':tid' => $this->tenantId]);
            $tv = $tvStmt->fetch(\PDO::FETCH_ASSOC);
            $submissionText = $tv['extracted_text'] ?? '';

            foreach ($sourceCache as $sid => $src) {
                $sStmt = $this->db->prepare(
                    "SELECT extracted_text FROM ac_similarity_text_versions WHERE source_id = :sid AND tenant_id = :tid AND text_type = 'source' ORDER BY id DESC LIMIT 1"
                );
                $sStmt->execute([':sid' => $sid, ':tid' => $this->tenantId]);
                $sTv = $sStmt->fetch(\PDO::FETCH_ASSOC);
                if ($sTv) {
                    $sourceTexts[$sid] = $sTv['extracted_text'];
                }
            }
        } catch (\Throwable $e) {
            write_log('PublicReportViewService: failed to load text versions: ' . $e->getMessage());
        }

        // Build highlights
        $highlightService = new AcademicSimilarityHighlightService($this->tenantId);
        $highlightData = $highlightService->buildSpans($submissionId, $matches, $evidenceMap, $submission, $sourceCache);
        $spans = $highlightData['spans'];
        $highlightStats = $highlightData['stats'];
        $legend = $highlightData['legend'];

        $highlightedHtml = $highlightService->renderHighlightedText($submissionText, $spans);
        $sourcePanels = $highlightService->renderSourcePanels($spans, $sourceTexts);
        $matchedPassages = $highlightService->assembleMatchedPassages($spans, $matches, $evidenceMap);
        foreach ($matchedPassages as &$passage) {
            $sourceId = (int)($passage['source_id'] ?? 0);
            if ($sourceId > 0 && isset($internetBySource[$sourceId])) {
                $passage['source_origin'] = 'internet';
                $passage['source_url'] = $showSourceNames ? (string)($internetBySource[$sourceId]['source_url'] ?? '') : '';
                $passage['retrieved_at'] = (string)($internetBySource[$sourceId]['retrieved_at'] ?? '');
            } else {
                $passage['source_origin'] = 'local';
            }
        }
        unset($passage);

        // Safe submission metadata
        $viewModel = [
            'submission' => [
                'id'                => (int)($submission['id'] ?? 0),
                'submission_title'  => $submission['submission_title'] ?? '',
                'author_name'       => $submission['author_name'] ?? '',
                'filename'          => $submission['original_filename'] ?? '',
                'status'            => $submission['status'] ?? 'pending',
                'submitted_at'      => $submission['submitted_at'] ?? '',
                'processed_at'      => $submission['processed_at'] ?? null,
                'word_count'        => (int)($submission['word_count'] ?? 0),
                'source_type'       => $submission['source_type'] ?? '',
            ],
            'analysis' => [
                'raw_score'             => $submission['raw_similarity_score'] !== null ? (float)$submission['raw_similarity_score'] : null,
                'adjusted_score'        => $submission['adjusted_similarity_score'] !== null ? (float)$submission['adjusted_similarity_score'] : null,
                'match_count'           => count($matches),
                'active_match_count'    => count(array_filter($matches, fn(array $m): bool => empty($m['is_excluded']))),
                'excluded_match_count'  => count(array_filter($matches, fn(array $m): bool => !empty($m['is_excluded']))),
                'matched_word_count'    => (int)($submission['matched_word_count'] ?? 0),
                'total_eligible_words'  => (int)($submission['total_eligible_words'] ?? 0),
                'source_count'          => count($sourceCache),
                'highlighted_span_count' => $highlightStats['submission_spans'] ?? 0,
            ],
            'highlights' => [
                'highlighted_html' => $highlightedHtml,
                'highlight_legend' => $legend,
                'highlight_stats'  => $highlightStats,
                'source_panels'    => $sourcePanels,
                'matched_passages' => $matchedPassages,
            ],
            'report' => $report !== null ? [
                'id'             => (int)$report['id'],
                'generated_at'   => $report['generated_at'] ?? null,
                'raw_score'      => isset($report['raw_score']) ? (float)$report['raw_score'] : null,
                'adjusted_score' => isset($report['adjusted_score']) ? (float)$report['adjusted_score'] : null,
                'total_matches'  => (int)($report['total_matches'] ?? 0),
                'format'         => $report['report_format'] ?? 'html',
            ] : null,
            'download' => [
                'can_download' => $report !== null,
                'url'          => $report !== null
                    ? '/api/v1/academic-similarity/public/reports/' . (int)($submission['id'] ?? 0) . '/download'
                    : null,
                'generated_at' => $report['generated_at'] ?? null,
            ],
            'source_count' => count($sourceCache),
        ];

        // Add semantic scores if available
        if (isset($submission['semantic_similarity_score']) && $submission['semantic_similarity_score'] !== null) {
            $viewModel['analysis']['semantic_score'] = (float)$submission['semantic_similarity_score'];
        }
        if (isset($submission['bayesian_risk_score']) && $submission['bayesian_risk_score'] !== null) {
            $viewModel['analysis']['bayesian_score'] = (float)$submission['bayesian_risk_score'];
        }
        if (isset($submission['statistical_score']) && $submission['statistical_score'] !== null) {
            $viewModel['analysis']['statistical_score'] = (float)$submission['statistical_score'];
        }

        return $viewModel;
    }

    /**
     * Check whether the user owns a submission.
     */
    public function userOwnsSubmission(int $submissionId, int $submitterUserId): bool
    {
        if ($submissionId <= 0 || $submitterUserId <= 0) {
            return false;
        }
        $submission = $this->subRepo->findById($submissionId);
        if ($submission === null) {
            return false;
        }
        return (int)($submission['submitter_user_id'] ?? 0) === $submitterUserId;
    }

    /**
     * Get safe submission metadata (no highlight content) for ownership verification.
     */
    public function getSubmissionMeta(int $submissionId, int $submitterUserId): ?array
    {
        if ($submissionId <= 0 || $submitterUserId <= 0) {
            return null;
        }
        $submission = $this->subRepo->findById($submissionId);
        if ($submission === null) {
            return null;
        }
        if ((int)($submission['submitter_user_id'] ?? 0) !== $submitterUserId) {
            return null;
        }
        return [
            'id'               => (int)$submission['id'],
            'submission_title' => $submission['submission_title'] ?? '',
            'status'           => $submission['status'] ?? 'pending',
            'submitted_at'     => $submission['submitted_at'] ?? '',
            'processed_at'     => $submission['processed_at'] ?? null,
            'word_count'       => (int)($submission['word_count'] ?? 0),
        ];
    }

    /**
     * Render the highlighted document HTML for use in JSON API responses.
     */
    public function renderHighlightedHtml(int $submissionId, int $submitterUserId): ?string
    {
        $view = $this->getView($submissionId, $submitterUserId);
        if ($view === null) {
            return null;
        }
        return $view['highlights']['highlighted_html'] ?? null;
    }
}
