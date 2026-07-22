<?php
declare(strict_types=1);

class AcademicSimilarityFingerprintService
{
    private string $tenantId;
    private \Ikabud\Kernel\Contracts\ModuleDB $db;

    public function __construct(string $tenantId) {
        $this->tenantId = $tenantId;
        $this->db = academic_similarity_db();
    }

    /**
     * Generate exact-match fingerprints from segments.
     * Each fingerprint is a sliding-window shingle hash (sha256) of N consecutive words.
     *
     * @param AcademicSimilaritySegment[] $segments
     * @param int $shingleSize Number of words per shingle (default 5)
     * @param int|null $sourceId
     * @param int|null $submissionId
     * @param int|null $textVersionId
     * @return AcademicSimilarityFingerprint[]
     */
    public function generateFingerprints(
        array $segments,
        int $shingleSize = 5,
        ?int $sourceId = null,
        ?int $submissionId = null,
        ?int $textVersionId = null
    ): array {
        $fingerprints = [];

        foreach ($segments as $segment) {
            $words = preg_split('/\s+/', trim($segment->normalizedContent));
            if ($words === false || count($words) < $shingleSize) {
                continue;
            }

            $wordCount = count($words);
            for ($i = 0; $i <= $wordCount - $shingleSize; $i++) {
                $shingleWords = array_slice($words, $i, $shingleSize);
                $shingleText = implode(' ', $shingleWords);
                $hash = hash('sha256', $shingleText);

                $fingerprints[] = new AcademicSimilarityFingerprint(
                    $hash,
                    'exact',
                    $shingleSize,
                    $shingleText,
                    $segment->index,
                    $i
                );
            }
        }

        return $fingerprints;
    }

    /**
     * Generate near-identical fingerprints from segments.
     * Canonicalizes each shingle by sorting words alphabetically before hashing,
     * making the fingerprint resilient to minor word reordering.
     *
     * @param AcademicSimilaritySegment[] $segments
     * @param int $shingleSize Number of words per shingle (default 5)
     * @param int|null $sourceId
     * @param int|null $submissionId
     * @param int|null $textVersionId
     * @return AcademicSimilarityFingerprint[]
     */
    public function generateNearFingerprints(
        array $segments,
        int $shingleSize = 5,
        ?int $sourceId = null,
        ?int $submissionId = null,
        ?int $textVersionId = null
    ): array {
        $fingerprints = [];

        foreach ($segments as $segment) {
            $words = preg_split('/\s+/', trim($segment->normalizedContent));
            if ($words === false || count($words) < $shingleSize) {
                continue;
            }

            $wordCount = count($words);
            for ($i = 0; $i <= $wordCount - $shingleSize; $i++) {
                $shingleWords = array_slice($words, $i, $shingleSize);
                $canonical = $shingleWords;
                sort($canonical);
                $shingleText = implode(' ', $shingleWords);
                $canonicalText = implode(' ', $canonical);
                $hash = hash('sha256', $canonicalText);

                $fingerprints[] = new AcademicSimilarityFingerprint(
                    $hash,
                    'near',
                    $shingleSize,
                    $shingleText,
                    $segment->index,
                    $i
                );
            }
        }

        return $fingerprints;
    }

    /**
     * Batch-save fingerprints to the database.
     * Inserts in chunks of 100 to avoid oversized queries.
     *
     * @param AcademicSimilarityFingerprint[] $fingerprints
     * @param string $tenantId
     * @param int|null $sourceId
     * @param int|null $submissionId
     * @param int|null $textVersionId
     */
    public function saveFingerprints(
        array $fingerprints,
        string $tenantId,
        ?int $sourceId = null,
        ?int $submissionId = null,
        ?int $textVersionId = null
    ): void {
        if (empty($fingerprints)) {
            return;
        }

        $chunks = array_chunk($fingerprints, 100);

        foreach ($chunks as $chunk) {
            $placeholders = [];
            $params = [];

            foreach ($chunk as $i => $fp) {
                $offset = $i * 8;
                $placeholders[] = '(:tid' . $offset . ', :src' . $offset . ', :sub' . $offset . ', :tv' . $offset . ', :ft' . $offset . ', :ss' . $offset . ', :sh' . $offset . ', :st' . $offset . ', :si' . $offset . ', :wp' . $offset . ')';
                $params[':tid' . $offset] = $tenantId;
                $params[':src' . $offset] = $sourceId;
                $params[':sub' . $offset] = $submissionId;
                $params[':tv' . $offset] = $textVersionId;
                $params[':ft' . $offset] = $fp->type;
                $params[':ss' . $offset] = $fp->shingleSize;
                $params[':sh' . $offset] = $fp->hash;
                $params[':st' . $offset] = $fp->shingleText;
                $params[':si' . $offset] = $fp->segmentIndex;
                $params[':wp' . $offset] = $fp->wordPosition;
            }

            $sql = "INSERT INTO ac_similarity_fingerprints
                    (tenant_id, source_id, submission_id, text_version_id, fingerprint_type, shingle_size, shingle_hash, shingle_text, segment_index, word_position)
                    VALUES " . implode(', ', $placeholders);

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
        }
    }
}
