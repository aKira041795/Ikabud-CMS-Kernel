<?php
declare(strict_types=1);

class AcademicSimilaritySubmissionService
{
    private string $tenantId;
    private \Ikabud\Kernel\Contracts\ModuleDB $db;

    private AcademicSimilaritySubmissionRepository $submissionRepo;
    private AcademicSimilarityAuditRepository $auditRepo;
    private AcademicSimilarityProcessingJobRepository $jobRepo;
    private AcademicSimilarityUsageCounterRepository $usageRepo;
    private AcademicSimilarityQuotaService $quotaService;
    private AcademicSimilarityFileValidator $fileValidator;
    private AcademicSimilarityStorage $storage;
    private AcademicSimilarityTextExtractor $extractor;

    public function __construct(string $tenantId)
    {
        $this->tenantId = $tenantId;
        $this->db = academic_similarity_db();
        $this->submissionRepo = new AcademicSimilaritySubmissionRepository($tenantId);
        $this->auditRepo = new AcademicSimilarityAuditRepository($tenantId);
        $this->jobRepo = new AcademicSimilarityProcessingJobRepository($tenantId);
        $this->usageRepo = new AcademicSimilarityUsageCounterRepository($tenantId);
        $this->quotaService = new AcademicSimilarityQuotaService($tenantId);
        $this->fileValidator = new AcademicSimilarityFileValidator();
        $this->storage = new AcademicSimilarityStorage();
        $this->extractor = new AcademicSimilarityTextExtractor();
    }

    /**
     * Create a new submission with validation, storage, text extraction, and job queuing.
     *
     * @param array $input Expects: institution_id, submission_title, source_type,
     *                     author_name, author_identifier, file (for upload),
     *                     pasted_text (for pasted), idempotency_key (optional)
     * @return array{ok: bool, submission_id?: int, error?: string}
     */
    public function create(array $input): array
    {
        // 1. Validate required fields
        $institutionId = (int)($input['institution_id'] ?? 0);
        $submissionTitle = trim((string)($input['submission_title'] ?? ''));
        $sourceType = trim((string)($input['source_type'] ?? ''));

        if ($institutionId <= 0) {
            return ['ok' => false, 'error' => 'Valid institution_id is required'];
        }
        if ($submissionTitle === '') {
            return ['ok' => false, 'error' => 'submission_title is required'];
        }
        if (!in_array($sourceType, ['upload', 'pasted'], true)) {
            return ['ok' => false, 'error' => 'source_type must be "upload" or "pasted"'];
        }

        $settings = academic_similarity_get_settings($this->tenantId);

        // 2. Extract text and file metadata based on source type
        $extractedText = '';
        $fileData = [
            'original_filename' => '',
            'storage_path' => '',
            'storage_name' => '',
            'mime_type' => '',
            'file_size_bytes' => 0,
            'checksum_sha256' => '',
        ];

        if ($sourceType === 'upload') {
            $file = $input['file'] ?? null;
            if (!is_array($file) || empty($file['tmp_name'])) {
                return ['ok' => false, 'error' => 'Uploaded file is required when source_type is "upload"'];
            }

            // Validate file
            $validation = $this->fileValidator->validate($file, $settings);
            if (!($validation['ok'] ?? false)) {
                return ['ok' => false, 'error' => $validation['error'] ?? 'File validation failed'];
            }

            // Store file
            try {
                $fileData = $this->storage->storeUploadedFile($file);
                $fileData['original_filename'] = $file['name'] ?? 'upload.bin';
                $fileData['mime_type'] = $this->fileValidator->detectMimeType($file['tmp_name']);
            } catch (\Throwable $e) {
                return ['ok' => false, 'error' => 'File storage failed: ' . $e->getMessage()];
            }

            // Extract text
            $fullPath = $this->storage->getFullPath($fileData['storage_path']);
            try {
                $extraction = $this->extractor->extract($fullPath, $fileData['mime_type']);
                $extractedText = $extraction['text'];
                $fileData['file_size_bytes'] = $fileData['file_size_bytes'] ?: (int)($file['size'] ?? 0);
            } catch (\Throwable $e) {
                // Clean up stored file on extraction failure
                $this->storage->deleteFile($fileData['storage_path']);
                return ['ok' => false, 'error' => 'Text extraction failed: ' . $e->getMessage()];
            }
        } else {
            // source_type === 'pasted'
            $extractedText = trim((string)($input['pasted_text'] ?? ''));
            if ($extractedText === '') {
                return ['ok' => false, 'error' => 'pasted_text is required when source_type is "pasted"'];
            }
            $fileData['mime_type'] = 'text/plain';
        }

        // 3. Validate word count against settings
        $wordCount = $this->countWords($extractedText);
        $minWords = (int)($settings['min_word_count'] ?? 20);
        $maxWords = (int)($settings['max_word_count'] ?? 50000);

        if ($wordCount < $minWords) {
            // Clean up stored file if uploaded
            if ($sourceType === 'upload' && $fileData['storage_path'] !== '') {
                $this->storage->deleteFile($fileData['storage_path']);
            }
            return ['ok' => false, 'error' => "Submission must be at least {$minWords} words (extracted: {$wordCount})"];
        }
        if ($wordCount > $maxWords) {
            if ($sourceType === 'upload' && $fileData['storage_path'] !== '') {
                $this->storage->deleteFile($fileData['storage_path']);
            }
            return ['ok' => false, 'error' => "Submission exceeds maximum of {$maxWords} words (extracted: {$wordCount})"];
        }

        // 4. Check quota
        $quota = $this->quotaService->checkQuota($institutionId, 'submissions');
        if (!($quota['ok'] ?? false)) {
            if ($sourceType === 'upload' && $fileData['storage_path'] !== '') {
                $this->storage->deleteFile($fileData['storage_path']);
            }
            return ['ok' => false, 'error' => $quota['error'] ?? 'Submission quota exceeded'];
        }

        // Compute text hash
        $textHash = hash('sha256', $extractedText);

        // 5. Create submission DB record
        $submissionId = $this->submissionRepo->create([
            'institution_id' => $institutionId,
            'submission_title' => $submissionTitle,
            'author_name' => $input['author_name'] ?? '',
            'author_identifier' => $input['author_identifier'] ?? '',
            'submitter_user_id' => (int)($input['submitter_user_id'] ?? 0),
            'submitter_source' => (string)($input['submitter_source'] ?? ''),
            'source_type' => $sourceType,
            'original_filename' => $fileData['original_filename'],
            'storage_path' => $fileData['storage_path'],
            'storage_name' => $fileData['storage_name'],
            'mime_type' => $fileData['mime_type'],
            'file_size_bytes' => $fileData['file_size_bytes'],
            'word_count' => $wordCount,
            'page_count' => 0,
            'checksum_sha256' => $fileData['checksum_sha256'],
            'text_hash_sha256' => $textHash,
            'idempotency_key' => $input['idempotency_key'] ?? '',
        ]);

        if ($submissionId <= 0) {
            if ($sourceType === 'upload' && $fileData['storage_path'] !== '') {
                $this->storage->deleteFile($fileData['storage_path']);
            }
            return ['ok' => false, 'error' => 'Failed to create submission record'];
        }

        // 6. Create text_version record
        try {
            $this->createTextVersion($submissionId, $extractedText, $wordCount, $fileData['mime_type'], $sourceType);
        } catch (\Throwable $e) {
            write_log('Failed to create text_version for submission ' . $submissionId . ': ' . $e->getMessage());
            if ($sourceType === 'upload' && $fileData['storage_path'] !== '') {
                $this->storage->deleteFile($fileData['storage_path']);
            }
            $this->submissionRepo->delete($submissionId);
            return ['ok' => false, 'error' => 'Failed to persist extracted submission text'];
        }

        // 7. Queue processing job (starts with 'extract' stage)
        try {
            $this->jobRepo->create([
                'submission_id' => $submissionId,
                'job_type' => 'extract',
                'status' => 'pending',
                'priority' => 1,
                'idempotency_key' => 'proc_' . $submissionId . '_' . ($input['idempotency_key'] ?? ''),
                'retry_count' => 0,
                'retry_max' => 3,
            ]);
        } catch (\Throwable $e) {
            write_log('Failed to queue processing job for submission ' . $submissionId . ': ' . $e->getMessage());
        }

        // 8. Record audit event
        try {
            $this->auditRepo->record(
                'submission.created',
                (int)($input['actor_id'] ?? 0),
                $input['actor_name'] ?? 'system',
                'submission',
                $submissionId,
                "Created submission '{$submissionTitle}' ({$wordCount} words, {$sourceType})",
                [
                    'institution_id' => $institutionId,
                    'source_type' => $sourceType,
                    'word_count' => $wordCount,
                    'file_size_bytes' => $fileData['file_size_bytes'],
                ]
            );
        } catch (\Throwable $e) {
            write_log('Failed to record audit event for submission ' . $submissionId . ': ' . $e->getMessage());
        }

        // 9. Increment usage counter
        try {
            $this->usageRepo->increment('submissions', $institutionId, 1);
        } catch (\Throwable $e) {
            write_log('Failed to increment usage counter: ' . $e->getMessage());
        }

        return [
            'ok' => true,
            'submission_id' => $submissionId,
        ];
    }

    /**
     * Find a submission by ID.
     */
    public function findById(int $id): ?array
    {
        return $this->submissionRepo->findById($id);
    }

    /**
     * Delete a submission and its associated storage files.
     */
    public function delete(int $id): void
    {
        $submission = $this->submissionRepo->findById($id);
        if ($submission === null) {
            return;
        }

        // Delete stored file if present
        if (!empty($submission['storage_path'])) {
            try {
                $this->storage->deleteFile($submission['storage_path']);
            } catch (\Throwable $e) {
                write_log('Failed to delete storage file for submission ' . $id . ': ' . $e->getMessage());
            }
        }

        // Delete related analysis records. The schema intentionally does not
        // declare cascades, so cleanup must stay explicit and tenant-scoped.
        $deleteStatements = [
            "DELETE me FROM ac_similarity_match_evidence me INNER JOIN ac_similarity_matches m ON m.id = me.match_id AND m.tenant_id = me.tenant_id WHERE m.submission_id = :sid AND m.tenant_id = :tid",
            "DELETE FROM ac_similarity_exclusions WHERE submission_id = :sid AND tenant_id = :tid",
            "DELETE FROM ac_similarity_reviews WHERE submission_id = :sid AND tenant_id = :tid",
            "DELETE FROM ac_similarity_reports WHERE submission_id = :sid AND tenant_id = :tid",
            "DELETE FROM ac_similarity_matches WHERE submission_id = :sid AND tenant_id = :tid",
            "DELETE FROM ac_similarity_candidate_sources WHERE submission_id = :sid AND tenant_id = :tid",
            "DELETE FROM ac_similarity_fingerprints WHERE submission_id = :sid AND tenant_id = :tid",
            "DELETE FROM ac_similarity_segments WHERE submission_id = :sid AND tenant_id = :tid",
            "DELETE FROM ac_similarity_files WHERE submission_id = :sid AND tenant_id = :tid",
            "DELETE FROM ac_similarity_text_versions WHERE submission_id = :sid AND tenant_id = :tid",
            "DELETE FROM ac_similarity_processing_jobs WHERE submission_id = :sid AND tenant_id = :tid",
        ];

        try {
            foreach ($deleteStatements as $sql) {
                $stmt = $this->db->prepare($sql);
                $stmt->execute([':sid' => $id, ':tid' => $this->tenantId]);
            }
        } catch (\Throwable $e) {
            write_log('Failed to delete related records for submission ' . $id . ': ' . $e->getMessage());
            throw $e;
        }

        // Delete the submission record
        $this->submissionRepo->delete($id);
    }

    private function countWords(string $text): int
    {
        $normalized = trim(strip_tags($text));
        if ($normalized === '') {
            return 0;
        }

        if (preg_match_all('/[\p{L}\p{N}]+(?:[\'’\-][\p{L}\p{N}]+)*/u', $normalized, $matches) !== false) {
            return count($matches[0]);
        }

        return str_word_count($normalized);
    }

    /**
     * Create a text_version record for the extracted text.
     */
    private function createTextVersion(int $submissionId, string $text, int $wordCount, string $mimeType, string $sourceType): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO ac_similarity_text_versions
                (tenant_id, submission_id, text_type, extracted_text, word_count, page_count,
                 text_hash_sha256, extraction_method, extraction_version)
             VALUES
                (:tid, :sid, 'submission', :text, :wcount, :pcount,
                 :thash, :method, '1.0.0')"
        );
        $stmt->execute([
            ':tid' => $this->tenantId,
            ':sid' => $submissionId,
            ':text' => $text,
            ':wcount' => $wordCount,
            ':pcount' => 1,
            ':thash' => hash('sha256', $text),
            ':method' => $sourceType === 'upload' ? 'file_extraction' : 'pasted',
        ]);
    }
}
