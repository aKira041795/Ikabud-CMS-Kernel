<?php
declare(strict_types=1);

class AcademicSimilarityInternetCheckService
{
    private string $tenantId;
    private \Ikabud\Kernel\Contracts\ModuleDB $db;
    private AcademicSimilaritySubmissionRepository $submissionRepo;
    private AcademicSimilarityInternetSearchRunRepository $runRepo;
    private AcademicSimilarityInternetSourceRepository $sourceRepo;
    private AcademicSimilarityInternetDiscoveryService $discovery;
    private AcademicSimilarityInternetSourceIngestionService $ingestion;

    public function __construct(string $tenantId)
    {
        $this->tenantId = $tenantId;
        $this->db = academic_similarity_db();
        $this->submissionRepo = new AcademicSimilaritySubmissionRepository($tenantId);
        $this->runRepo = new AcademicSimilarityInternetSearchRunRepository($tenantId);
        $this->sourceRepo = new AcademicSimilarityInternetSourceRepository($tenantId);
        $this->discovery = new AcademicSimilarityInternetDiscoveryService($tenantId);
        $this->ingestion = new AcademicSimilarityInternetSourceIngestionService($tenantId);
    }

    /**
     * Dispatch internet check as an async kernel job.
     */
    public function dispatchAsync(int $submissionId): array
    {
        // Dedup guard: skip if a run is already pending for this submission
        if ($this->runRepo->hasPendingRun($submissionId)) {
            return ['ok' => false, 'status' => 'skipped', 'reason' => 'Internet check already pending for this submission'];
        }

        if (function_exists('kernelDispatchJob')) {
            $jobId = kernelDispatchJob(
                'academic-similarity:academicSimilarityInternetCheckHandler',
                [
                    'submission_id' => $submissionId,
                    'tenant_id' => $this->tenantId,
                ],
                'default',
                30,
                3
            );
            if ($jobId > 0) {
                return ['ok' => true, 'status' => 'queued', 'job_id' => $jobId];
            }
        }
        // Fallback: run synchronously
        return $this->runForSubmission($submissionId, true);
    }

    /**
     * Check circuit breaker state from DB settings.
     * Returns true if the breaker is open (calls should be skipped).
     */
    private function breakerIsOpen(): bool
    {
        $stmt = $this->db->prepare(
            "SELECT setting_value FROM ac_similarity_settings
             WHERE tenant_id = :tid AND setting_key = 'internet_check_breaker_state'"
        );
        $stmt->execute([':tid' => $this->tenantId]);
        $raw = (string)($stmt->fetchColumn() ?: '');
        if ($raw === '') {
            return false;
        }
        $state = json_decode($raw, true);
        if (!is_array($state)) {
            return false;
        }
        $failures = (int)($state['failures'] ?? 0);
        $openedAt = (int)($state['opened_at'] ?? 0);
        if ($failures < 3) {
            return false;
        }
        // Open for 5 minutes
        if (time() - $openedAt < 300) {
            return true;
        }
        // Reset after cooldown
        $this->breakerReset();
        return false;
    }

    /**
     * Record a breaker failure.
     */
    private function breakerRecordFailure(): void
    {
        $stmt = $this->db->prepare(
            "SELECT setting_value FROM ac_similarity_settings
             WHERE tenant_id = :tid AND setting_key = 'internet_check_breaker_state'"
        );
        $stmt->execute([':tid' => $this->tenantId]);
        $raw = (string)($stmt->fetchColumn() ?: '');
        $state = json_decode($raw, true);
        $failures = is_array($state) ? ((int)($state['failures'] ?? 0) + 1) : 1;
        $state = ['failures' => $failures, 'opened_at' => time()];

        $this->db->prepare(
            "INSERT INTO ac_similarity_settings (tenant_id, setting_key, setting_value)
             VALUES (:tid, 'internet_check_breaker_state', :val)
             ON DUPLICATE KEY UPDATE setting_value = :val2"
        )->execute([':tid' => $this->tenantId, ':val' => json_encode($state), ':val2' => json_encode($state)]);
    }

    /**
     * Reset circuit breaker on success.
     */
    private function breakerReset(): void
    {
        $this->db->prepare(
            "DELETE FROM ac_similarity_settings
             WHERE tenant_id = :tid AND setting_key = 'internet_check_breaker_state'"
        )->execute([':tid' => $this->tenantId]);
    }

    public function runForSubmission(int $submissionId, bool $force = false): array
    {
        $settings = academic_similarity_get_settings($this->tenantId);
        if (($settings['internet_check_enabled'] ?? '0') !== '1') {
            return [
                'ok' => true,
                'status' => 'skipped',
                'reason' => 'Internet checking is disabled',
                'disclosure' => $this->limitedCorpusDisclosure(),
            ];
        }

        $submission = $this->submissionRepo->findById($submissionId);
        if ($submission === null) {
            return ['ok' => false, 'status' => 'failed', 'error' => 'Submission not found'];
        }

        // Auto-run when enabled — always attempt internet discovery regardless
        // of existing local sources. Local sources may be topically unrelated
        // to the new submission (e.g., seed URLs for different subjects).
        if (!$force && ($settings['internet_check_auto_run_when_no_sources'] ?? '0') !== '1') {
            return ['ok' => true, 'status' => 'skipped', 'reason' => 'Auto-run disabled; only local sources were checked'];
        }

        // Circuit breaker: skip if 3 consecutive failures in last 5 minutes.
        // Manual run ($force=true) bypasses the breaker.
        if (!$force && $this->breakerIsOpen()) {
            if (function_exists('write_log')) {
                write_log("AISS internet check skipped for submission #{$submissionId}: circuit breaker open", 'warning');
            }
            return ['ok' => true, 'status' => 'skipped', 'reason' => 'Circuit breaker open; internet discovery temporarily suspended after repeated failures'];
        }

        // Concurrency guard with DB-level locking: wrap check + create in a transaction
        // to prevent TOCTOU race between hasPendingRun() and create().
        $this->db->beginTransaction();
        try {
            if ($this->runRepo->hasPendingRun($submissionId)) {
                $this->db->rollBack();
                return ['ok' => true, 'status' => 'skipped', 'reason' => 'An internet check is already in progress for this submission'];
            }

            $text = $this->loadSubmissionText($submissionId);
            if ($text === '') {
                $this->db->rollBack();
                return ['ok' => true, 'status' => 'skipped', 'reason' => 'No extracted submission text available'];
            }

            $provider = (string)($settings['internet_check_provider'] ?? 'capability');
            $payloadPolicy = (string)($settings['internet_check_payload_policy'] ?? 'snippets_only');
            $queries = $this->discovery->buildQueries($submission, $text, $settings);
            $runId = $this->runRepo->create($submissionId, (int)($submission['institution_id'] ?? 0), $provider, $payloadPolicy, [
                'queries' => $queries,
                'full_document_query_allowed' => ($settings['internet_check_allow_full_document_query'] ?? '0') === '1',
            ]);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        if ($queries === []) {
            $this->runRepo->updateSummary($runId, 'skipped', 0, 0, 0, $this->limitedCorpusDisclosure(), 'No safe queries could be generated');
            return ['ok' => true, 'status' => 'skipped', 'search_run_id' => $runId, 'reason' => 'No safe queries could be generated'];
        }

        $discovered = $this->discovery->discover($queries, $settings);
        if (!($discovered['ok'] ?? false)) {
            $error = (string)($discovered['error'] ?? 'Internet discovery failed');
            $this->runRepo->updateSummary($runId, 'failed', count($queries), 0, 0, $this->partialDisclosure(), $error);
            $this->breakerRecordFailure();
            return ['ok' => false, 'status' => 'failed', 'search_run_id' => $runId, 'error' => $error];
        }

        $candidates = $discovered['candidates'] ?? [];
        if (($settings['internet_check_store_retrieved_text'] ?? '1') !== '1') {
            $this->runRepo->updateSummary($runId, 'skipped', count($queries), count($candidates), 0, $this->partialDisclosure(), 'Retrieved text storage is disabled; source evidence was not imported');
            return [
                'ok' => true,
                'status' => 'skipped',
                'search_run_id' => $runId,
                'queries' => $queries,
                'candidate_count' => count($candidates),
                'imported_count' => 0,
                'reason' => 'Retrieved text storage is disabled; source evidence was not imported',
                'disclosure' => $this->partialDisclosure(),
            ];
        }

        $imported = 0;
        $errors = [];
        $maxChars = max(1000, min(100000, (int)($settings['internet_check_max_chars_per_source'] ?? 12000)));
        foreach ($candidates as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }
            $candidateId = $this->sourceRepo->createCandidate($runId, $submissionId, $candidate);
            $url = (string)($candidate['url'] ?? '');
            $fetched = $this->discovery->fetchText($url, $maxChars);
            if (!($fetched['ok'] ?? false)) {
                $error = (string)($fetched['error'] ?? 'Fetch failed');
                $this->sourceRepo->markFailed($candidateId, $error);
                $errors[] = $url . ': ' . $error;
                continue;
            }

            $text = (string)($fetched['text'] ?? '');
            $this->sourceRepo->markRetrieved($candidateId, $text, strlen($text));
            $ingested = $this->ingestion->ingest((int)($submission['institution_id'] ?? 0), $candidate, $text, $maxChars);
            if (!($ingested['ok'] ?? false)) {
                $error = (string)($ingested['error'] ?? 'Ingestion failed');
                $this->sourceRepo->markFailed($candidateId, $error);
                $errors[] = $url . ': ' . $error;
                continue;
            }

            $this->sourceRepo->markImported($candidateId, (int)$ingested['source_id']);
            $imported++;
        }

        // Phase 4: improved status granularity
        if ($imported > 0) {
            $status = ($errors === []) ? 'completed' : 'completed_partial';
            $this->breakerReset(); // Success resets breaker
        } elseif (count($candidates) > 0) {
            $status = 'completed_none'; // Found candidates but none were importable
            $this->breakerRecordFailure();
        } else {
            $status = 'skipped';
        }
        $errorText = $errors === [] ? '' : implode('; ', array_slice($errors, 0, 5));
        $this->runRepo->updateSummary($runId, $status, count($queries), count($candidates), $imported, $this->coverageDisclosure($provider, count($queries), count($candidates)), $errorText);

        return [
            'ok' => $status !== 'failed',
            'status' => $status,
            'search_run_id' => $runId,
            'queries' => $queries,
            'candidate_count' => count($candidates),
            'imported_count' => $imported,
            'errors' => $errors,
            'disclosure' => $this->coverageDisclosure($provider, count($queries), count($candidates)),
        ];
    }

    public function latestRun(int $submissionId): ?array
    {
        return $this->runRepo->latestForSubmission($submissionId);
    }

    private function loadSubmissionText(int $submissionId): string
    {
        $stmt = $this->db->prepare(
            "SELECT extracted_text FROM ac_similarity_text_versions
             WHERE tenant_id = :tid AND submission_id = :sid AND text_type = 'submission'
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([':tid' => $this->tenantId, ':sid' => $submissionId]);
        return (string)($stmt->fetchColumn() ?: '');
    }

    private function indexedSourceCount(): int
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM ac_similarity_sources WHERE tenant_id = :tid AND is_indexed = 1");
        $stmt->execute([':tid' => $this->tenantId]);
        return (int)$stmt->fetchColumn();
    }

    private function coverageDisclosure(string $provider, int $queryCount, int $candidateCount): string
    {
        return "Internet-assisted check used provider {$provider} with {$queryCount} bounded query seed(s) and {$candidateCount} candidate source(s). This is not a comprehensive internet search.";
    }

    private function limitedCorpusDisclosure(): string
    {
        return 'Internet-assisted checking is disabled; analysis is limited to tenant-indexed AISS sources.';
    }

    private function partialDisclosure(): string
    {
        return 'Internet-assisted checking could not complete; results are partial and must not be interpreted as a clean originality decision.';
    }
}
