<?php
declare(strict_types=1);

class AcademicSimilarityPipelineService
{
    private string $tenantId;
    private \Ikabud\Kernel\Contracts\ModuleDB $db;
    private AcademicSimilarityProcessingJobRepository $jobRepo;
    private AcademicSimilaritySubmissionRepository $subRepo;
    private AcademicSimilaritySourceRepository $sourceRepo;
    private AcademicSimilarityMatchRepository $matchRepo;

    /** @var array<string, int> Stage priority ordering */
    private const STAGE_ORDER = [
        'extract'          => 1,
        'normalize'        => 2,
        'segment'          => 3,
        'internet_discovery' => 4,
        'fingerprint'      => 5,
        'candidate_search' => 6,
        'exact_match'      => 7,
        'near_match'       => 8,
        'semantic_match'   => 9,
        'score'            => 10,
        'report'           => 11,
    ];

    public function __construct(string $tenantId) {
        $this->tenantId = $tenantId;
        $this->db = academic_similarity_db();
        $this->jobRepo = new AcademicSimilarityProcessingJobRepository($tenantId);
        $this->subRepo = new AcademicSimilaritySubmissionRepository($tenantId);
        $this->sourceRepo = new AcademicSimilaritySourceRepository($tenantId);
        $this->matchRepo = new AcademicSimilarityMatchRepository($tenantId);
    }

    /**
     * Run the full processing pipeline for a submission.
     * Each stage is executed in order. If a stage is already completed,
     * it is skipped (idempotent).
     *
     * @param int $submissionId
     * @return array{ok: bool, stages: array<string, array{status: string, result: mixed}>, error?: string}
     */
    public function processSubmission(int $submissionId): array
    {
        $stages = [
            'extract',
            'normalize',
            'segment',
            'internet_discovery',
            'fingerprint',
            'candidate_search',
            'exact_match',
            'near_match',
            'semantic_match',
            'score',
            'report',
        ];

        $results = [];

        foreach ($stages as $stage) {
            $stageResult = $this->runStage($submissionId, $stage);
            $results[$stage] = $stageResult;

            if ($stageResult['status'] === 'failed') {
                $error = "Pipeline failed at stage '{$stage}': " . ($stageResult['error'] ?? 'Unknown error');
                $this->subRepo->updateStatus($submissionId, 'failed', $error);
                return [
                    'ok' => false,
                    'stages' => $results,
                    'error' => $error,
                ];
            }
        }

        $this->subRepo->updateStatus($submissionId, 'processed');
        return [
            'ok' => true,
            'stages' => $results,
        ];
    }

    /**
     * Process a single pipeline stage job by its job ID.
     *
     * @param int $jobId
     * @return array{ok: bool, status: string, result?: mixed, error?: string}
     */
    public function processJob(int $jobId): array
    {
        $job = $this->jobRepo->findById($jobId);

        if ($job === null) {
            return ['ok' => false, 'status' => 'failed', 'error' => "Job #{$jobId} not found"];
        }

        $submissionId = (int)$job['submission_id'];
        $jobType = $job['job_type'];

        // Mark as running
        $this->jobRepo->updateStatus($jobId, 'running');

        try {
            $result = $this->executeStage($submissionId, $jobType);
            $this->jobRepo->updateStatus($jobId, 'completed');
            return ['ok' => true, 'status' => 'completed', 'result' => $result];
        } catch (\Throwable $e) {
            $errorMsg = $e->getMessage();
            $this->jobRepo->updateStatus($jobId, 'failed', $errorMsg);
            return ['ok' => false, 'status' => 'failed', 'error' => $errorMsg];
        }
    }

    /**
     * Run the extract stage for a submission.
     * Extracts text from the submission's uploaded file.
     *
     * @param int $submissionId
     * @return array{ok: bool, text_version_id?: int, text?: string, error?: string}
     */
    public function runExtract(int $submissionId): array
    {
        $submission = $this->subRepo->findById($submissionId);
        if ($submission === null) {
            return ['ok' => false, 'error' => "Submission #{$submissionId} not found"];
        }

        try {
            $extractor = new AcademicSimilarityTextExtractor();
            $storagePath = $submission['storage_path'] ?? '';
            $mimeType = $submission['mime_type'] ?? '';

            if (($submission['source_type'] ?? '') === 'pasted' || empty($storagePath)) {
                $tvStmt = $this->db->prepare("
                    SELECT id, extracted_text, word_count, page_count, extraction_method
                    FROM ac_similarity_text_versions
                    WHERE submission_id = :sid AND tenant_id = :tid AND text_type = 'submission'
                    ORDER BY id DESC LIMIT 1
                ");
                $tvStmt->execute([':sid' => $submissionId, ':tid' => $this->tenantId]);
                $textVersion = $tvStmt->fetch(\PDO::FETCH_ASSOC);
                if ($textVersion === false || trim((string)($textVersion['extracted_text'] ?? '')) === '') {
                    return ['ok' => false, 'error' => "No pasted text found for submission #{$submissionId}"];
                }
                $this->subRepo->updateStatus($submissionId, 'processing');
                return [
                    'ok' => true,
                    'text_version_id' => (int)$textVersion['id'],
                    'text' => (string)$textVersion['extracted_text'],
                    'word_count' => (int)($textVersion['word_count'] ?? 0),
                    'page_count' => (int)($textVersion['page_count'] ?? 1),
                    'method' => (string)($textVersion['extraction_method'] ?? 'pasted'),
                ];
            }

            $fullPath = $storagePath;
            if (!file_exists($fullPath)) {
                $storage = new AcademicSimilarityStorage();
                $fullPath = $storage->getFullPath($storagePath);
            }

            if (!file_exists($fullPath)) {
                return ['ok' => false, 'error' => "File not found at: {$storagePath}"];
            }

            $result = $extractor->extract($fullPath, $mimeType);
            $text = $result['text'];
            $pageCount = $result['page_count'];
            $method = $result['method'];
            $wordCount = str_word_count($text);
            $textHash = hash('sha256', $text);

            // Store text version
            $tvStmt = $this->db->prepare("
                INSERT INTO ac_similarity_text_versions
                    (tenant_id, submission_id, text_type, extracted_text, word_count, page_count, text_hash_sha256, extraction_method, extraction_version)
                VALUES
                    (:tid, :sid, 'submission', :text, :wcount, :pcount, :thash, :method, '1.0.0')
            ");
            $tvStmt->execute([
                ':tid' => $this->tenantId,
                ':sid' => $submissionId,
                ':text' => $text,
                ':wcount' => $wordCount,
                ':pcount' => $pageCount,
                ':thash' => $textHash,
                ':method' => $method,
            ]);

            $textVersionId = (int)$this->db->lastInsertId();

            // Update submission record
            $this->subRepo->updateStatus($submissionId, 'processing');

            return [
                'ok' => true,
                'text_version_id' => $textVersionId,
                'text' => $text,
                'word_count' => $wordCount,
                'page_count' => $pageCount,
                'method' => $method,
            ];
        } catch (\Throwable $e) {
            $this->subRepo->updateStatus($submissionId, 'failed', $e->getMessage());
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Run the normalize stage for a submission.
     * Normalizes the extracted text for fingerprint comparison.
     *
     * @param int $submissionId
     * @return array{ok: bool, normalized_text?: string, normalized_hash?: string, offset_map?: array, error?: string}
     */
    public function runNormalize(int $submissionId): array
    {
        // Find the text version for this submission
        $tvStmt = $this->db->prepare("
            SELECT * FROM ac_similarity_text_versions
            WHERE submission_id = :sid AND tenant_id = :tid AND text_type = 'submission'
            ORDER BY id DESC LIMIT 1
        ");
        $tvStmt->execute([':sid' => $submissionId, ':tid' => $this->tenantId]);
        $textVersion = $tvStmt->fetch(\PDO::FETCH_ASSOC);

        if ($textVersion === false || empty($textVersion['extracted_text'])) {
            return ['ok' => false, 'error' => "No extracted text found for submission #{$submissionId}"];
        }

        try {
            $normalizer = new AcademicSimilarityNormalizationService($this->tenantId);
            $normalized = $normalizer->normalize($textVersion['extracted_text']);

            // Update text version with normalized data
            $updateStmt = $this->db->prepare("
                UPDATE ac_similarity_text_versions
                SET normalized_text = :ntext,
                    normalized_word_count = :nwcount,
                    normalized_hash_sha256 = :nhash,
                    offset_map_json = :omap
                WHERE id = :id AND tenant_id = :tid
            ");
            $updateStmt->execute([
                ':ntext' => $normalized->normalizedText,
                ':nwcount' => $normalized->normalizedWordCount,
                ':nhash' => $normalized->textHash,
                ':omap' => json_encode($normalized->offsetMap),
                ':id' => (int)$textVersion['id'],
                ':tid' => $this->tenantId,
            ]);

            return [
                'ok' => true,
                'normalized_text' => $normalized->normalizedText,
                'normalized_hash' => $normalized->textHash,
                'offset_map' => $normalized->offsetMap,
                'text_version_id' => (int)$textVersion['id'],
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Run the segment stage for a submission.
     * Splits normalized text into segments (sentences/paragraphs).
     *
     * @param int $submissionId
     * @return array{ok: bool, segment_count?: int, error?: string}
     */
    public function runSegment(int $submissionId): array
    {
        $tvStmt = $this->db->prepare("
            SELECT * FROM ac_similarity_text_versions
            WHERE submission_id = :sid AND tenant_id = :tid AND text_type = 'submission'
            ORDER BY id DESC LIMIT 1
        ");
        $tvStmt->execute([':sid' => $submissionId, ':tid' => $this->tenantId]);
        $textVersion = $tvStmt->fetch(\PDO::FETCH_ASSOC);

        if ($textVersion === false) {
            return ['ok' => false, 'error' => "No text version found for submission #{$submissionId}"];
        }

        $normalizedText = $textVersion['normalized_text'] ?? $textVersion['extracted_text'];
        if (empty($normalizedText)) {
            return ['ok' => false, 'error' => "No text to segment for submission #{$submissionId}"];
        }

        try {
            $normalizer = new AcademicSimilarityNormalizationService($this->tenantId);
            $segments = [];

            // Split into sentences by common sentence boundaries
            $sentences = preg_split('/(?<=[.!?])\s+/', $normalizedText, -1, PREG_SPLIT_NO_EMPTY);
            if ($sentences === false) {
                $sentences = [$normalizedText];
            }

            $origOffset = 0;
            $normOffset = 0;

            foreach ($sentences as $index => $sentence) {
                $sentence = trim($sentence);
                if (empty($sentence)) {
                    continue;
                }

                $isQuote = $normalizer->isQuotation($sentence);
                $isBib = $normalizer->isBibliographyLine($sentence);
                $sentenceNorm = $normalizer->normalizeForComparison($sentence);
                $wordCount = str_word_count($sentence);
                $charCount = strlen($sentence);
                $sentenceLen = strlen($sentence);

                $segment = new AcademicSimilaritySegment([
                    'index' => $index,
                    'type' => 'sentence',
                    'content' => $sentence,
                    'normalized_content' => $sentenceNorm,
                    'word_count' => $wordCount,
                    'char_count' => $charCount,
                    'original_start_offset' => $origOffset,
                    'original_end_offset' => $origOffset + $charCount,
                    'normalized_start_offset' => $normOffset,
                    'normalized_end_offset' => $normOffset + strlen($sentenceNorm),
                    'is_quotation' => $isQuote,
                    'is_bibliography' => $isBib,
                ]);

                $segments[] = $segment;
                $origOffset += $charCount + 1; // +1 for the split whitespace
                $normOffset += strlen($sentenceNorm) + 1;
            }

            // Insert segments into database
            $insertStmt = $this->db->prepare("
                INSERT INTO ac_similarity_segments
                    (tenant_id, text_version_id, submission_id, segment_type, segment_index,
                     content, normalized_content, word_count, char_count,
                     original_start_offset, original_end_offset,
                     normalized_start_offset, normalized_end_offset,
                     is_quotation, is_bibliography)
                VALUES
                    (:tid, :tvid, :sid, :stype, :sidx,
                     :content, :ncontent, :wcount, :ccount,
                     :osoff, :oeoff,
                     :nsoff, :neoff,
                     :isq, :isb)
            ");

            foreach ($segments as $seg) {
                $insertStmt->execute([
                    ':tid' => $this->tenantId,
                    ':tvid' => (int)$textVersion['id'],
                    ':sid' => $submissionId,
                    ':stype' => $seg->type,
                    ':sidx' => $seg->index,
                    ':content' => $seg->content,
                    ':ncontent' => $seg->normalizedContent,
                    ':wcount' => $seg->wordCount,
                    ':ccount' => $seg->charCount,
                    ':osoff' => $seg->originalStartOffset,
                    ':oeoff' => $seg->originalEndOffset,
                    ':nsoff' => $seg->normalizedStartOffset,
                    ':neoff' => $seg->normalizedEndOffset,
                    ':isq' => $seg->isQuotation ? 1 : 0,
                    ':isb' => $seg->isBibliography ? 1 : 0,
                ]);
            }

            return [
                'ok' => true,
                'segment_count' => count($segments),
                'text_version_id' => (int)$textVersion['id'],
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Run the fingerprint stage for a submission.
     * Generates and stores both exact and near fingerprints.
     *
     * @param int $submissionId
     * @return array{ok: bool, exact_count?: int, near_count?: int, error?: string}
     */
    public function runFingerprint(int $submissionId): array
    {
        // Load segments for this submission
        $segStmt = $this->db->prepare("
            SELECT * FROM ac_similarity_segments
            WHERE submission_id = :sid AND tenant_id = :tid
            ORDER BY segment_index ASC
        ");
        $segStmt->execute([':sid' => $submissionId, ':tid' => $this->tenantId]);
        $segRows = $segStmt->fetchAll(\PDO::FETCH_ASSOC);

        if (empty($segRows)) {
            return ['ok' => false, 'error' => "No segments found for submission #{$submissionId}"];
        }

        // Find the text version
        $tvStmt = $this->db->prepare("
            SELECT id FROM ac_similarity_text_versions
            WHERE submission_id = :sid AND tenant_id = :tid AND text_type = 'submission'
            ORDER BY id DESC LIMIT 1
        ");
        $tvStmt->execute([':sid' => $submissionId, ':tid' => $this->tenantId]);
        $tv = $tvStmt->fetch(\PDO::FETCH_ASSOC);
        $textVersionId = $tv ? (int)$tv['id'] : null;

        // Convert rows to AcademicSimilaritySegment objects
        $segments = [];
        foreach ($segRows as $row) {
            $segments[] = new AcademicSimilaritySegment($row);
        }

        try {
            $fpService = new AcademicSimilarityFingerprintService($this->tenantId);

            // Get shingle size from settings
            $settings = academic_similarity_get_settings($this->tenantId);
            $shingleSize = (int)($settings['fingerprint_shingle_size'] ?? 5);

            // Generate exact fingerprints
            $exactFps = $fpService->generateFingerprints(
                $segments,
                $shingleSize,
                null,
                $submissionId,
                $textVersionId
            );

            // Generate near fingerprints
            $nearFps = $fpService->generateNearFingerprints(
                $segments,
                $shingleSize,
                null,
                $submissionId,
                $textVersionId
            );

            // Delete existing fingerprints for this submission (idempotent)
            $delStmt = $this->db->prepare("
                DELETE FROM ac_similarity_fingerprints
                WHERE submission_id = :sid AND tenant_id = :tid
            ");
            $delStmt->execute([':sid' => $submissionId, ':tid' => $this->tenantId]);

            // Save fingerprints
            $fpService->saveFingerprints($exactFps, $this->tenantId, null, $submissionId, $textVersionId);
            $fpService->saveFingerprints($nearFps, $this->tenantId, null, $submissionId, $textVersionId);

            return [
                'ok' => true,
                'exact_count' => count($exactFps),
                'near_count' => count($nearFps),
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Run the candidate search stage for a submission.
     * Finds sources with matching fingerprints.
     *
     * @param int $submissionId
     * @return array{ok: bool, candidate_count?: int, candidates?: array, error?: string}
     */
    public function runCandidateSearch(int $submissionId): array
    {
        try {
            $matchingService = new AcademicSimilarityMatchingService($this->tenantId);
            $candidates = $matchingService->findCandidateSources($submissionId);

            // Store candidates in the candidate_sources table
            $delStmt = $this->db->prepare("
                DELETE FROM ac_similarity_candidate_sources
                WHERE submission_id = :sid AND tenant_id = :tid
            ");
            $delStmt->execute([':sid' => $submissionId, ':tid' => $this->tenantId]);

            $insertStmt = $this->db->prepare("
                INSERT INTO ac_similarity_candidate_sources
                    (tenant_id, submission_id, source_id, match_confidence, fingerprint_hits, status)
                VALUES
                    (:tid, :sid, :srcid, :conf, :hits, 'pending')
            ");

            foreach ($candidates as $candidate) {
                $insertStmt->execute([
                    ':tid' => $this->tenantId,
                    ':sid' => $submissionId,
                    ':srcid' => $candidate['source_id'],
                    ':conf' => $candidate['match_confidence'],
                    ':hits' => $candidate['fingerprint_hits'],
                ]);
            }

            return [
                'ok' => true,
                'candidate_count' => count($candidates),
                'candidates' => $candidates,
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Run the score stage for a submission.
     * Calculates similarity scores based on match results.
     *
     * @param int $submissionId
     * @return array{ok: bool, score?: array, error?: string}
     */
    public function runScore(int $submissionId): array
    {
        try {
            $scoringService = new AcademicSimilarityScoringService($this->tenantId);
            $score = $scoringService->calculateScore($submissionId);

            // Update submission with scores
            $this->subRepo->updateScore(
                $submissionId,
                $score['raw_score'],
                $score['adjusted_score'],
                $score['matched_word_count'],
                $score['total_eligible_words']
            );

            return [
                'ok' => true,
                'score' => $score,
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Run the report stage for a submission.
     * Creates a similarity report snapshot.
     *
     * @param int $submissionId
     * @return array{ok: bool, report_id?: int, error?: string}
     */
    public function runReport(int $submissionId): array
    {
        try {
            $submission = $this->subRepo->findById($submissionId);
            if ($submission === null) {
                return ['ok' => false, 'error' => "Submission #{$submissionId} not found"];
            }

            // Gather match data
            $matchRepo = new AcademicSimilarityMatchRepository($this->tenantId);
            $matches = $matchRepo->findActive($submissionId);
            $excludedMatches = $matchRepo->findExcluded($submissionId);

            // Calculate exclusion word deduction
            $excludedWords = 0;
            foreach ($excludedMatches as $em) {
                $excludedWords += (int)($em['matched_word_count'] ?? 0);
            }

            // Build report data
            $reportData = [
                'submission_id' => $submissionId,
                'submission_title' => $submission['submission_title'] ?? '',
                'author_name' => $submission['author_name'] ?? '',
                'raw_score' => (float)($submission['raw_similarity_score'] ?? 0),
                'adjusted_score' => (float)($submission['adjusted_similarity_score'] ?? 0),
                'total_matches' => count($matches),
                'total_excluded' => count($excludedMatches),
                'matched_word_count' => (int)($submission['matched_word_count'] ?? 0),
                'total_eligible_words' => (int)($submission['total_eligible_words'] ?? 0),
                'generated_at' => date('Y-m-d H:i:s'),
            ];

            $reportDataJson = json_encode($reportData);
            $reportChecksum = hash('sha256', $reportDataJson);

            $insertStmt = $this->db->prepare("
                INSERT INTO ac_similarity_reports
                    (tenant_id, submission_id, report_version, match_engine_version,
                     raw_score, adjusted_score, total_matches, total_excluded,
                     matched_word_count, total_eligible_words, exclusion_word_deduction,
                     report_checksum, report_format, report_data_json)
                VALUES
                    (:tid, :sid, '1.0.0', '1.0.0',
                     :raw, :adj, :tm, :te,
                     :mw, :tew, :ewd,
                     :rchk, 'json', :rdata)
            ");
            $insertStmt->execute([
                ':tid' => $this->tenantId,
                ':sid' => $submissionId,
                ':raw' => $reportData['raw_score'],
                ':adj' => $reportData['adjusted_score'],
                ':tm' => $reportData['total_matches'],
                ':te' => $reportData['total_excluded'],
                ':mw' => $reportData['matched_word_count'],
                ':tew' => $reportData['total_eligible_words'],
                ':ewd' => $excludedWords,
                ':rchk' => $reportChecksum,
                ':rdata' => $reportDataJson,
            ]);

            $reportId = (int)$this->db->lastInsertId();

            // ── AI Report Narrative (non-blocking, best-effort) ──
            $aiNarrative = $this->generateAiReportNarrative($submissionId, $reportData);
            if ($aiNarrative !== null) {
                $this->db->prepare("UPDATE ac_similarity_reports SET report_ai_narrative = :n WHERE id = :id")
                    ->execute([':n' => $aiNarrative, ':id' => $reportId]);
            }

            // Mark submission as processed
            $this->subRepo->updateStatus($submissionId, 'processed');

            return [
                'ok' => true,
                'report_id' => $reportId,
                'report_data' => $reportData,
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    // ── Private helpers ──────────────────────────────────────────

    /**
     * Run a single pipeline stage, creating/updating a processing job record.
     * Skips if the stage is already completed (idempotent).
     *
     * @param int $submissionId
     * @param string $stage
     * @return array{status: string, result?: mixed, error?: string}
     */
    private function runStage(int $submissionId, string $stage): array
    {
        // Check if this stage is already completed
        $existingJobs = $this->jobRepo->findBySubmissionId($submissionId);
        foreach ($existingJobs as $job) {
            if ($job['job_type'] === $stage && $job['status'] === 'completed') {
                return ['status' => 'skipped', 'result' => 'Already completed'];
            }
        }

        // Create a job record
        $priority = self::STAGE_ORDER[$stage] ?? 0;
        $jobId = $this->jobRepo->create([
            'submission_id' => $submissionId,
            'job_type' => $stage,
            'status' => 'running',
            'priority' => $priority,
            'idempotency_key' => $stage . '_' . $submissionId . '_' . date('YmdHis'),
        ]);

        try {
            // Mark as running
            $this->jobRepo->updateStatus($jobId, 'running');

            // Execute the stage
            $result = $this->executeStage($submissionId, $stage);
            if (is_array($result) && array_key_exists('ok', $result) && !($result['ok'] ?? false)) {
                throw new \RuntimeException((string)($result['error'] ?? "Stage {$stage} failed"));
            }

            // Mark as completed
            $this->jobRepo->updateStatus($jobId, 'completed');

            return ['status' => 'completed', 'result' => $result];
        } catch (\Throwable $e) {
            $errorMsg = $e->getMessage();
            $this->jobRepo->updateStatus($jobId, 'failed', $errorMsg);
            return ['status' => 'failed', 'error' => $errorMsg];
        }
    }

    /**
     * Execute a single pipeline stage by name.
     *
     * @param int $submissionId
     * @param string $stage
     * @return mixed
     * @throws \RuntimeException
     */
    private function executeStage(int $submissionId, string $stage): mixed
    {
        return match ($stage) {
            'extract' => $this->runExtract($submissionId),
            'normalize' => $this->runNormalize($submissionId),
            'segment' => $this->runSegment($submissionId),
            'internet_discovery' => $this->runInternetDiscovery($submissionId),
            'fingerprint' => $this->runFingerprint($submissionId),
            'candidate_search' => $this->runCandidateSearch($submissionId),
            'exact_match' => $this->runExactMatchStage($submissionId),
            'near_match' => $this->runNearMatchStage($submissionId),
            'semantic_match' => $this->runSemanticMatchStage($submissionId),
            'score' => $this->runScore($submissionId),
            'report' => $this->runReport($submissionId),
            default => throw new \RuntimeException("Unknown pipeline stage: {$stage}"),
        };
    }

    private function runInternetDiscovery(int $submissionId): array
    {
        $settings = academic_similarity_get_settings($this->tenantId);
        if (($settings['internet_check_enabled'] ?? '0') !== '1') {
            return [
                'ok' => true,
                'internet_status' => 'skipped',
                'reason' => 'Internet checking is disabled',
                'disclosure' => 'Analysis is limited to tenant-indexed AISS sources.',
            ];
        }

        if (($settings['internet_check_auto_run_when_no_sources'] ?? '0') !== '1') {
            return ['ok' => true, 'internet_status' => 'skipped', 'reason' => 'Automatic internet checking is disabled'];
        }

        $service = new AcademicSimilarityInternetCheckService($this->tenantId);
        $result = $service->runForSubmission($submissionId, false);
        return [
            'ok' => true,
            'internet_status' => $result['status'] ?? 'unknown',
            'search_run_id' => $result['search_run_id'] ?? null,
            'candidate_count' => $result['candidate_count'] ?? 0,
            'imported_count' => $result['imported_count'] ?? 0,
            'disclosure' => $result['disclosure'] ?? null,
            'error' => $result['error'] ?? null,
        ];
    }

    /**
     * Run the exact match stage.
     */
    private function runExactMatchStage(int $submissionId): array
    {
        $matchingService = new AcademicSimilarityMatchingService($this->tenantId);
        $result = $matchingService->runExactMatching($submissionId);

        if ($result['ok'] && !empty($result['match_results'])) {
            $stored = $matchingService->storeMatches($result['match_results'], $this->tenantId);
            return [
                'ok' => true,
                'matches_found' => $result['matches'],
                'matches_stored' => $stored,
            ];
        }

        return [
            'ok' => true,
            'matches_found' => 0,
            'matches_stored' => 0,
        ];
    }

    /**
     * Run the near-exact match stage.
     */
    private function runNearMatchStage(int $submissionId): array
    {
        $matchingService = new AcademicSimilarityMatchingService($this->tenantId);
        $result = $matchingService->runNearExactMatching($submissionId);

        if ($result['ok'] && !empty($result['match_results'])) {
            $stored = $matchingService->storeMatches($result['match_results'], $this->tenantId);
            return [
                'ok' => true,
                'matches_found' => $result['matches'],
                'matches_stored' => $stored,
            ];
        }

        return [
            'ok' => true,
            'matches_found' => 0,
            'matches_stored' => 0,
        ];
    }

    /**
     * Run optional semantic matching after deterministic candidate retrieval.
     */
    private function runSemanticMatchStage(int $submissionId): array
    {
        $submission = $this->subRepo->findById($submissionId);
        if ($submission === null) {
            return ['ok' => true, 'semantic_status' => 'skipped', 'reason' => "Submission #{$submissionId} not found"];
        }

        $institutionId = (int)($submission['institution_id'] ?? 0);
        $semantic = new AcademicSimilaritySemanticService($this->tenantId);
        $availability = $semantic->isAvailable($institutionId);
        if (!($availability['ok'] ?? false)) {
            return [
                'ok' => true,
                'semantic_status' => 'skipped',
                'reason' => $availability['error'] ?? 'Semantic matching is unavailable',
                'gates' => $availability['gates'] ?? [],
            ];
        }

        $settings = academic_similarity_get_settings($this->tenantId);
        $submissionSegments = $this->loadSubmissionSegmentsForSemantic($submissionId);
        $sourceBundle = $this->loadCandidateSourceSegmentsForSemantic($submissionId);
        if ($sourceBundle['texts'] === []) {
            $sourceBundle = $this->loadIndexedSourceSegmentsForSemantic((int)($settings['max_sources_per_comparison'] ?? 100));
        }
        if ($submissionSegments === [] || $sourceBundle['texts'] === []) {
            return ['ok' => true, 'semantic_status' => 'skipped', 'reason' => 'No semantic source segments available'];
        }

        $result = $semantic->compare(
            array_column($submissionSegments, 'normalized_content'),
            $sourceBundle['texts'],
            [
                'institution_id' => $institutionId,
                'provider' => (string)($settings['semantic_provider'] ?? 'token_overlap'),
                'modelName' => (string)($settings['semantic_model_name'] ?? 'token_overlap'),
                'threshold' => (float)($settings['semantic_similarity_threshold'] ?? 0.70),
            ]
        );

        if (!($result['ok'] ?? false)) {
            return ['ok' => true, 'semantic_status' => 'failed', 'error' => $result['error'] ?? 'Semantic matching failed'];
        }

        $threshold = (float)($settings['semantic_similarity_threshold'] ?? 0.70);
        $matches = [];
        foreach (($result['comparisons'] ?? []) as $comparison) {
            $score = (float)($comparison['similarity_score'] ?? 0);
            if ($score < $threshold || empty($comparison['above_threshold'])) {
                continue;
            }

            $submissionIndex = (int)($comparison['submission_segment_index'] ?? -1);
            $sourceIndex = (int)($comparison['source_segment_index'] ?? -1);
            $submissionSegment = $submissionSegments[$submissionIndex] ?? null;
            $sourceSegment = $sourceBundle['segments'][$sourceIndex] ?? null;
            if (!is_array($submissionSegment) || !is_array($sourceSegment)) {
                continue;
            }

            $matches[] = new AcademicSimilarityMatchResult([
                'submission_id' => $submissionId,
                'source_id' => (int)($sourceSegment['source_id'] ?? 0),
                'match_type' => 'semantic',
                'confidence' => $score,
                'submission_segment_id' => (int)($submissionSegment['id'] ?? 0),
                'source_segment_id' => (int)($sourceSegment['id'] ?? 0),
                'matched_word_count' => (int)($submissionSegment['word_count'] ?? 0),
                'submission_word_range_start' => (int)($submissionSegment['original_start_offset'] ?? 0),
                'submission_word_range_end' => (int)($submissionSegment['original_end_offset'] ?? 0),
                'source_word_range_start' => (int)($sourceSegment['original_start_offset'] ?? 0),
                'source_word_range_end' => (int)($sourceSegment['original_end_offset'] ?? 0),
                'segment_match_count' => 1,
                'evidence' => [[
                    'submission_text' => (string)($submissionSegment['content'] ?? ''),
                    'source_text' => (string)($sourceSegment['content'] ?? ''),
                    'submission_start_offset' => (int)($submissionSegment['original_start_offset'] ?? 0),
                    'submission_end_offset' => (int)($submissionSegment['original_end_offset'] ?? 0),
                    'source_start_offset' => (int)($sourceSegment['original_start_offset'] ?? 0),
                    'source_end_offset' => (int)($sourceSegment['original_end_offset'] ?? 0),
                ]],
            ]);
        }

        $stored = 0;
        if ($matches !== []) {
            $matchingService = new AcademicSimilarityMatchingService($this->tenantId);
            $stored = $matchingService->storeMatches($matches, $this->tenantId);
        }

        return [
            'ok' => true,
            'semantic_status' => 'completed',
            'comparisons' => count($result['comparisons'] ?? []),
            'matches_stored' => $stored,
            'model' => $result['model'] ?? [],
            'summary' => $result['summary'] ?? [],
        ];
    }

    private function loadSubmissionSegmentsForSemantic(int $submissionId): array
    {
        $stmt = $this->db->prepare("
            SELECT *
            FROM ac_similarity_segments
            WHERE submission_id = :sid
              AND tenant_id = :tid
              AND segment_type = 'sentence'
              AND normalized_content <> ''
            ORDER BY segment_index ASC
        ");
        $stmt->execute([':sid' => $submissionId, ':tid' => $this->tenantId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function loadCandidateSourceSegmentsForSemantic(int $submissionId): array
    {
        $stmt = $this->db->prepare("
            SELECT seg.*
            FROM ac_similarity_candidate_sources cs
            JOIN ac_similarity_segments seg
              ON seg.source_id = cs.source_id
             AND seg.tenant_id = cs.tenant_id
            WHERE cs.submission_id = :sid
              AND cs.tenant_id = :tid
              AND seg.segment_type = 'sentence'
              AND seg.normalized_content <> ''
            ORDER BY cs.fingerprint_hits DESC, seg.source_id ASC, seg.segment_index ASC
        ");
        $stmt->execute([':sid' => $submissionId, ':tid' => $this->tenantId]);
        $segments = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return [
            'segments' => $segments,
            'texts' => array_map(static fn(array $row): string => (string)($row['normalized_content'] ?? ''), $segments),
        ];
    }

    private function loadIndexedSourceSegmentsForSemantic(int $maxSources): array
    {
        $maxSources = max(1, min(500, $maxSources));
        $stmt = $this->db->prepare("
            SELECT seg.*
            FROM ac_similarity_segments seg
            JOIN ac_similarity_sources src
              ON src.id = seg.source_id
             AND src.tenant_id = seg.tenant_id
            WHERE seg.tenant_id = :tid
              AND seg.segment_type = 'sentence'
              AND seg.normalized_content <> ''
              AND src.is_indexed = 1
              AND src.indexing_status = 'indexed'
            ORDER BY src.indexed_at DESC, seg.source_id ASC, seg.segment_index ASC
            LIMIT {$maxSources}
        ");
        $stmt->execute([':tid' => $this->tenantId]);
        $segments = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return [
            'segments' => $segments,
            'texts' => array_map(static fn(array $row): string => (string)($row['normalized_content'] ?? ''), $segments),
        ];
    }

    /**
     * Generate an AI-powered plain-language summary of the similarity report.
     * Best-effort: returns null silently if AI is unavailable.
     */
    private function generateAiReportNarrative(int $submissionId, array $reportData): ?string
    {
        try {
            $settings = academic_similarity_get_settings($this->tenantId);
            if (($settings['report_ai_narrative_enabled'] ?? '1') !== '1') {
                return null;
            }
            if (!app()->capabilities()->has('ai.text.generate@1')) {
                return null;
            }

            $title = (string)($reportData['submission_title'] ?? 'Untitled');
            // Sanitize: truncate and strip control characters to prevent prompt injection
            $title = mb_substr(preg_replace('/[\x00-\x1f\x7f]/u', '', $title), 0, 200);
            $rawScore = number_format((float)($reportData['raw_score'] ?? 0), 1);
            $adjScore = number_format((float)($reportData['adjusted_score'] ?? 0), 1);
            $matchCount = (int)($reportData['total_matches'] ?? 0);
            $matchedWords = (int)($reportData['matched_word_count'] ?? 0);
            $eligibleWords = (int)($reportData['total_eligible_words'] ?? 0);

            $prompt = "Summarize this academic similarity report in 2-3 sentences for an integrity reviewer. "
                . "Submission: \"{$title}\". "
                . "Adjusted similarity score: {$adjScore}%. "
                . "Matches found: {$matchCount}. "
                . "Matched words: {$matchedWords} of {$eligibleWords} eligible words. "
                . "Be factual and concise. Do not make judgments about intent.";

            $result = app()->cap()->call('ai.text.generate@1', [
                'messages' => [
                    ['role' => 'system', 'content' => 'You are an academic integrity assistant. Write factual, concise summaries.'],
                    ['role' => 'user', 'content' => $prompt],
                ],
                'temperature' => 0.3,
                'json' => false,
                'timeout_ms' => 8000,
            ], ['caller_module' => 'academic-similarity']);

            if (!empty($result['ok']) && !empty($result['content'])) {
                return trim((string)$result['content']);
            }
        } catch (\Throwable $e) {
            // Silent fallback — AI narrative is optional
            if (function_exists('write_log')) {
                write_log('AISS AI report narrative generation failed: ' . $e->getMessage(), 'warning');
            }
        }

        return null;
    }
}
