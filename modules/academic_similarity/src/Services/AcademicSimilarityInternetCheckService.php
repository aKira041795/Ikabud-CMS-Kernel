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

        if (!$force && ($settings['internet_check_auto_run_when_no_sources'] ?? '0') === '1' && $this->indexedSourceCount() > 0) {
            return ['ok' => true, 'status' => 'skipped', 'reason' => 'Indexed local sources are available'];
        }

        $text = $this->loadSubmissionText($submissionId);
        if ($text === '') {
            return ['ok' => true, 'status' => 'skipped', 'reason' => 'No extracted submission text available'];
        }

        $provider = (string)($settings['internet_check_provider'] ?? 'capability');
        $payloadPolicy = (string)($settings['internet_check_payload_policy'] ?? 'snippets_only');
        $queries = $this->discovery->buildQueries($submission, $text, $settings);
        $runId = $this->runRepo->create($submissionId, (int)($submission['institution_id'] ?? 0), $provider, $payloadPolicy, [
            'queries' => $queries,
            'full_document_query_allowed' => ($settings['internet_check_allow_full_document_query'] ?? '0') === '1',
        ]);

        if ($queries === []) {
            $this->runRepo->updateSummary($runId, 'skipped', 0, 0, 0, $this->limitedCorpusDisclosure(), 'No safe queries could be generated');
            return ['ok' => true, 'status' => 'skipped', 'search_run_id' => $runId, 'reason' => 'No safe queries could be generated'];
        }

        $discovered = $this->discovery->discover($queries, $settings);
        if (!($discovered['ok'] ?? false)) {
            $error = (string)($discovered['error'] ?? 'Internet discovery failed');
            $this->runRepo->updateSummary($runId, 'failed', count($queries), 0, 0, $this->partialDisclosure(), $error);
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

        $status = $imported > 0 ? ($errors === [] ? 'completed' : 'partial') : ($candidates === [] ? 'skipped' : 'failed');
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
