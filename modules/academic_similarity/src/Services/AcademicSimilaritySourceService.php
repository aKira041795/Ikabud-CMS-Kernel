<?php
declare(strict_types=1);

class AcademicSimilaritySourceService
{
    private string $tenantId;
    private \Ikabud\Kernel\Contracts\ModuleDB $db;

    private AcademicSimilaritySourceRepository $sourceRepo;
    private AcademicSimilarityAuditRepository $auditRepo;
    private AcademicSimilarityFileValidator $fileValidator;
    private AcademicSimilarityStorage $storage;
    private AcademicSimilarityTextExtractor $extractor;

    public function __construct(string $tenantId)
    {
        $this->tenantId = $tenantId;
        $this->db = academic_similarity_db();
        $this->sourceRepo = new AcademicSimilaritySourceRepository($tenantId);
        $this->auditRepo = new AcademicSimilarityAuditRepository($tenantId);
        $this->fileValidator = new AcademicSimilarityFileValidator();
        $this->storage = new AcademicSimilarityStorage();
        $this->extractor = new AcademicSimilarityTextExtractor();
    }

    /**
     * Create a new source document (reference for comparison).
     *
     * @param array $input Expects: institution_id, title, source_type, classification,
     *                     author, collection_id, file (for upload), pasted_text (for pasted),
     *                     metadata_json, actor_id, actor_name
     * @return array{ok: bool, source_id?: int, error?: string}
     */
    public function create(array $input): array
    {
        $institutionId = (int)($input['institution_id'] ?? 0);
        $title = trim((string)($input['title'] ?? ''));
        $sourceType = trim((string)($input['source_type'] ?? ''));
        $classification = trim((string)($input['classification'] ?? 'published'));

        if ($institutionId <= 0) {
            return ['ok' => false, 'error' => 'Valid institution_id is required'];
        }
        if ($title === '') {
            return ['ok' => false, 'error' => 'title is required'];
        }
        if (!in_array($sourceType, ['upload', 'pasted'], true)) {
            return ['ok' => false, 'error' => 'source_type must be "upload" or "pasted"'];
        }

        $settings = academic_similarity_get_settings($this->tenantId);

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

            $validation = $this->fileValidator->validate($file, $settings);
            if (!($validation['ok'] ?? false)) {
                return ['ok' => false, 'error' => $validation['error'] ?? 'File validation failed'];
            }

            try {
                $fileData = $this->storage->storeUploadedFile($file);
                $fileData['original_filename'] = $file['name'] ?? 'upload.bin';
                $fileData['mime_type'] = $this->fileValidator->detectMimeType($file['tmp_name']);
            } catch (\Throwable $e) {
                return ['ok' => false, 'error' => 'File storage failed: ' . $e->getMessage()];
            }

            $fullPath = $this->storage->getFullPath($fileData['storage_path']);
            try {
                $extraction = $this->extractor->extract($fullPath, $fileData['mime_type']);
                $extractedText = $extraction['text'];
                $fileData['file_size_bytes'] = $fileData['file_size_bytes'] ?: (int)($file['size'] ?? 0);
            } catch (\Throwable $e) {
                $this->storage->deleteFile($fileData['storage_path']);
                return ['ok' => false, 'error' => 'Text extraction failed: ' . $e->getMessage()];
            }
        } else {
            $extractedText = trim((string)($input['pasted_text'] ?? ''));
            if ($extractedText === '') {
                return ['ok' => false, 'error' => 'pasted_text is required when source_type is "pasted"'];
            }
            $fileData['mime_type'] = 'text/plain';
        }

        // Validate word count
        $wordCount = str_word_count($extractedText);
        $minWords = (int)($settings['min_word_count'] ?? 20);
        $maxWords = (int)($settings['max_word_count'] ?? 50000);

        if ($wordCount < $minWords) {
            if ($sourceType === 'upload' && $fileData['storage_path'] !== '') {
                $this->storage->deleteFile($fileData['storage_path']);
            }
            return ['ok' => false, 'error' => "Source must be at least {$minWords} words (extracted: {$wordCount})"];
        }
        if ($wordCount > $maxWords) {
            if ($sourceType === 'upload' && $fileData['storage_path'] !== '') {
                $this->storage->deleteFile($fileData['storage_path']);
            }
            return ['ok' => false, 'error' => "Source exceeds maximum of {$maxWords} words (extracted: {$wordCount})"];
        }

        $textHash = hash('sha256', $extractedText);

        // Check for duplicate by checksum
        if (!empty($fileData['checksum_sha256'])) {
            $existing = $this->sourceRepo->findByChecksum($fileData['checksum_sha256']);
            if ($existing !== null) {
                if ($sourceType === 'upload' && $fileData['storage_path'] !== '') {
                    $this->storage->deleteFile($fileData['storage_path']);
                }
                return [
                    'ok' => false,
                    'error' => 'A source with this exact file content already exists (ID: ' . $existing['id'] . ')',
                ];
            }
        }

        $sourceId = $this->sourceRepo->create([
            'institution_id' => $institutionId,
            'collection_id' => (int)($input['collection_id'] ?? 0),
            'title' => $title,
            'author' => $input['author'] ?? '',
            'source_type' => $sourceType,
            'classification' => $classification,
            'original_filename' => $fileData['original_filename'],
            'storage_path' => $fileData['storage_path'],
            'storage_name' => $fileData['storage_name'],
            'mime_type' => $fileData['mime_type'],
            'file_size_bytes' => $fileData['file_size_bytes'],
            'word_count' => $wordCount,
            'page_count' => 0,
            'checksum_sha256' => $fileData['checksum_sha256'],
            'text_hash_sha256' => $textHash,
            'metadata_json' => $input['metadata_json'] ?? null,
        ]);

        if ($sourceId <= 0) {
            if ($sourceType === 'upload' && $fileData['storage_path'] !== '') {
                $this->storage->deleteFile($fileData['storage_path']);
            }
            return ['ok' => false, 'error' => 'Failed to create source record'];
        }

        try {
            $this->indexSourceText($sourceId, $extractedText, $wordCount, $fileData['mime_type']);
        } catch (\Throwable $e) {
            $this->sourceRepo->updateIndexStatus($sourceId, 'failed', $e->getMessage());
            return ['ok' => false, 'error' => 'Source indexing failed: ' . $e->getMessage()];
        }

        // Record audit event
        try {
            $this->auditRepo->record(
                'source.created',
                (int)($input['actor_id'] ?? 0),
                $input['actor_name'] ?? 'system',
                'source',
                $sourceId,
                "Created source '{$title}' ({$wordCount} words, {$classification})",
                [
                    'institution_id' => $institutionId,
                    'source_type' => $sourceType,
                    'classification' => $classification,
                    'word_count' => $wordCount,
                ]
            );
        } catch (\Throwable $e) {
            write_log('Failed to record audit event for source ' . $sourceId . ': ' . $e->getMessage());
        }

        return [
            'ok' => true,
            'source_id' => $sourceId,
        ];
    }

    /**
     * Find a source by ID.
     */
    public function findById(int $id): ?array
    {
        return $this->sourceRepo->findById($id);
    }

    /**
     * Re-index a source: re-extract text, re-normalize, re-fingerprint.
     *
     * @param int $id Source ID.
     * @return array{ok: bool, error?: string}
     */
    public function reindex(int $id): array
    {
        $source = $this->sourceRepo->findById($id);
        if ($source === null) {
            return ['ok' => false, 'error' => 'Source not found'];
        }

        if (empty($source['storage_path'])) {
            return ['ok' => false, 'error' => 'Source has no stored file to re-index'];
        }

        $fullPath = $this->storage->getFullPath($source['storage_path']);
        if (!file_exists($fullPath)) {
            return ['ok' => false, 'error' => 'Source file not found at storage path'];
        }

        try {
            $extraction = $this->extractor->extract($fullPath, $source['mime_type'] ?? 'text/plain');
            $extractedText = $extraction['text'];
            $wordCount = str_word_count($extractedText);
            $textHash = hash('sha256', $extractedText);

            // Update the source record with re-extracted data
            $stmt = $this->db->prepare(
                "UPDATE ac_similarity_sources 
                 SET word_count = :wcount, text_hash_sha256 = :thash, indexing_status = 'indexed', 
                     is_indexed = 1, indexed_at = NOW(), indexing_error = NULL
                 WHERE id = :id AND tenant_id = :tid"
            );
            $stmt->execute([
                ':wcount' => $wordCount,
                ':thash' => $textHash,
                ':id' => $id,
                ':tid' => $this->tenantId,
            ]);

            $this->indexSourceText($id, $extractedText, $wordCount, $source['mime_type'] ?? 'text/plain');

            // Record audit
            $this->auditRepo->record(
                'source.reindexed',
                0,
                'system',
                'source',
                $id,
                "Re-indexed source '{$source['title']}' ({$wordCount} words)",
                ['previous_word_count' => (int)($source['word_count'] ?? 0)]
            );

            return ['ok' => true];
        } catch (\Throwable $e) {
            $this->sourceRepo->updateIndexStatus($id, 'failed', $e->getMessage());
            return ['ok' => false, 'error' => 'Re-indexing failed: ' . $e->getMessage()];
        }
    }

    /**
     * Delete a source and its associated storage file.
     */
    public function delete(int $id): void
    {
        $source = $this->sourceRepo->findById($id);
        if ($source === null) {
            return;
        }

        if (!empty($source['storage_path'])) {
            try {
                $this->storage->deleteFile($source['storage_path']);
            } catch (\Throwable $e) {
                write_log('Failed to delete storage file for source ' . $id . ': ' . $e->getMessage());
            }
        }

        // Record audit before deletion
        try {
            $this->auditRepo->record(
                'source.deleted',
                0,
                'system',
                'source',
                $id,
                "Deleted source '{$source['title']}'",
                ['source_type' => $source['source_type'] ?? '']
            );
        } catch (\Throwable $e) {
            write_log('Failed to record audit event for source deletion ' . $id . ': ' . $e->getMessage());
        }

        $this->sourceRepo->delete($id);
    }

    public function indexSourceText(int $sourceId, string $text, int $wordCount, string $mimeType): void
    {
        $normalizer = new AcademicSimilarityNormalizationService($this->tenantId);
        $normalized = $normalizer->normalize($text);
        $normalizedText = $normalized->normalizedText;
        $settings = academic_similarity_get_settings($this->tenantId);
        $shingleSize = (int)($settings['fingerprint_shingle_size'] ?? 5);

        $this->db->beginTransaction();
        try {
            $this->db->prepare("DELETE FROM ac_similarity_fingerprints WHERE source_id = :sid AND tenant_id = :tid")
                ->execute([':sid' => $sourceId, ':tid' => $this->tenantId]);
            $this->db->prepare("DELETE FROM ac_similarity_segments WHERE source_id = :sid AND tenant_id = :tid")
                ->execute([':sid' => $sourceId, ':tid' => $this->tenantId]);
            $this->db->prepare("DELETE FROM ac_similarity_text_versions WHERE source_id = :sid AND tenant_id = :tid AND text_type = 'source'")
                ->execute([':sid' => $sourceId, ':tid' => $this->tenantId]);

            $safeText = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
            $safeNorm = mb_convert_encoding($normalizedText, 'UTF-8', 'UTF-8');
            $stmt = $this->db->prepare(
                "INSERT INTO ac_similarity_text_versions
                    (tenant_id, source_id, text_type, extracted_text, normalized_text, word_count,
                     normalized_word_count, text_hash_sha256, normalized_hash_sha256, offset_map_json,
                     extraction_method, extraction_version)
                 VALUES
                    (:tid, :sid, 'source', :text, :norm, :wcount,
                     :nwcount, :thash, :nhash, :offsets, :method, '1.0.0')"
            );
            $stmt->execute([
                ':tid' => $this->tenantId,
                ':sid' => $sourceId,
                ':text' => $safeText,
                ':norm' => $safeNorm,
                ':wcount' => $wordCount,
                ':nwcount' => str_word_count($safeNorm),
                ':thash' => hash('sha256', $safeText),
                ':nhash' => hash('sha256', $safeNorm),
                ':offsets' => json_encode($normalized->offsetMap),
                ':method' => $mimeType === 'application/pdf' ? 'pdf' : ($mimeType === 'text/plain' ? 'txt_plain' : 'upload'),
            ]);
            $textVersionId = (int)$this->db->lastInsertId();

            $segments = $this->buildSourceSegments($safeText, $normalizer);
            $insertStmt = $this->db->prepare(
                "INSERT INTO ac_similarity_segments
                    (tenant_id, text_version_id, source_id, segment_type, segment_index,
                     content, normalized_content, word_count, char_count,
                     original_start_offset, original_end_offset,
                     normalized_start_offset, normalized_end_offset,
                     is_quotation, is_bibliography)
                 VALUES
                    (:tid, :tvid, :sid, :stype, :sidx,
                     :content, :ncontent, :wcount, :ccount,
                     :osoff, :oeoff,
                     :nsoff, :neoff,
                     :isq, :isb)"
            );

            foreach ($segments as $seg) {
                $insertStmt->execute([
                    ':tid' => $this->tenantId,
                    ':tvid' => $textVersionId,
                    ':sid' => $sourceId,
                    ':stype' => $seg->type,
                    ':sidx' => $seg->index,
                    ':content' => mb_convert_encoding($seg->content, 'UTF-8', 'UTF-8'),
                    ':ncontent' => mb_convert_encoding($seg->normalizedContent, 'UTF-8', 'UTF-8'),
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

            $fingerprintService = new AcademicSimilarityFingerprintService($this->tenantId);
            $exact = $fingerprintService->generateFingerprints($segments, $sourceId, null, $textVersionId);
            $near = $fingerprintService->generateNearFingerprints($segments, $sourceId, null, $textVersionId);
            $fingerprintService->saveFingerprints($exact, $this->tenantId, $sourceId, null, $textVersionId);
            $fingerprintService->saveFingerprints($near, $this->tenantId, $sourceId, null, $textVersionId);

            $this->sourceRepo->updateIndexStatus($sourceId, 'indexed');
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * @return AcademicSimilaritySegment[]
     */
    private function buildSourceSegments(string $text, AcademicSimilarityNormalizationService $normalizer): array
    {
        $sentences = preg_split('/(?<=[.!?])\s+/', $text) ?: [];
        $segments = [];
        $origOffset = 0;
        $normOffset = 0;

        foreach ($sentences as $index => $sentence) {
            $sentence = trim($sentence);
            if ($sentence === '') {
                continue;
            }

            $normalized = $normalizer->normalizeForComparison($sentence);
            $charCount = strlen($sentence);
            $segments[] = new AcademicSimilaritySegment([
                'index' => $index,
                'type' => 'sentence',
                'content' => $sentence,
                'normalized_content' => $normalized,
                'word_count' => str_word_count($sentence),
                'char_count' => $charCount,
                'original_start_offset' => $origOffset,
                'original_end_offset' => $origOffset + $charCount,
                'normalized_start_offset' => $normOffset,
                'normalized_end_offset' => $normOffset + strlen($normalized),
                'is_quotation' => $normalizer->isQuotation($sentence),
                'is_bibliography' => $normalizer->isBibliographyLine($sentence),
            ]);

            $origOffset += $charCount + 1;
            $normOffset += strlen($normalized) + 1;
        }

        return $segments;
    }
}
