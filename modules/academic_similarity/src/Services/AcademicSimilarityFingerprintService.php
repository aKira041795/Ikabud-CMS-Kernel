<?php
declare(strict_types=1);

/**
 * Generates and stores shingle fingerprints for similarity matching.
 *
 * Supports multi-layer fingerprinting (short/medium/long shingle sizes)
 * with winnowing for storage-efficient medium+ layers, following
 * industry practices from Turnitin (multi-size shingling) and
 * Stanford Moss (winnowing).
 */
class AcademicSimilarityFingerprintService
{
    private string $tenantId;
    private \Ikabud\Kernel\Contracts\ModuleDB $db;

    /** @var array<string, int> Shingle level → word count mapping */
    private const SHINGLE_SIZES = [
        'short'  => 3,
        'medium' => 7,
        'long'   => 20,
    ];

    /** Winnowing window multiplier: window = N × shingle_size */
    private const WINNOW_WINDOW_MULTIPLIER = 4;

    /** Levels that are winnowed (medium+ only — short keeps all for recall) */
    private const WINNOWED_LEVELS = ['medium', 'long'];

    public function __construct(string $tenantId) {
        $this->tenantId = $tenantId;
        $this->db = academic_similarity_db();
    }

    // ── Public API ──────────────────────────────────────────────

    /**
     * Generate multi-layer fingerprints from segments.
     * Produces short (3-word), medium (7-word), and long (20-word)
     * shingles. Medium+ layers are winnowed for storage efficiency.
     *
     * @param AcademicSimilaritySegment[] $segments
     * @param int|null $sourceId
     * @param int|null $submissionId
     * @param int|null $textVersionId
     * @return AcademicSimilarityFingerprint[]
     */
    public function generateFingerprints(
        array $segments,
        ?int $sourceId = null,
        ?int $submissionId = null,
        ?int $textVersionId = null
    ): array {
        $allFingerprints = [];

        foreach (self::SHINGLE_SIZES as $level => $shingleSize) {
            $levelFps = $this->generateForLevel($segments, $level, $shingleSize, 'exact', $sourceId, $submissionId);

            if (in_array($level, self::WINNOWED_LEVELS, true)) {
                $levelFps = $this->winnow($levelFps, $shingleSize);
            }

            $allFingerprints = array_merge($allFingerprints, $levelFps);
        }

        return $allFingerprints;
    }

    /**
     * Generate near-identical (near) fingerprints from segments.
     * Canonicalizes each shingle by sorting words alphabetically before hashing.
     * Only medium and long layers — near matching at short shingle size has
     * excessive false positives.
     *
     * @param AcademicSimilaritySegment[] $segments
     * @param int|null $sourceId
     * @param int|null $submissionId
     * @param int|null $textVersionId
     * @return AcademicSimilarityFingerprint[]
     */
    public function generateNearFingerprints(
        array $segments,
        ?int $sourceId = null,
        ?int $submissionId = null,
        ?int $textVersionId = null
    ): array {
        $allFingerprints = [];

        // Near matching: medium (7) and long (20) only — short is too noisy
        $nearLevels = ['medium', 'long'];
        foreach ($nearLevels as $level) {
            $shingleSize = self::SHINGLE_SIZES[$level];
            $levelFps = $this->generateForLevel($segments, $level, $shingleSize, 'near', $sourceId, $submissionId);

            if (in_array($level, self::WINNOWED_LEVELS, true)) {
                $levelFps = $this->winnow($levelFps, $shingleSize);
            }

            $allFingerprints = array_merge($allFingerprints, $levelFps);
        }

        return $allFingerprints;
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
                $offset = $i * 9;
                $placeholders[] = '(:tid' . $offset . ', :src' . $offset . ', :sub' . $offset . ', :tv' . $offset . ', :ft' . $offset . ', :sl' . $offset . ', :ss' . $offset . ', :sh' . $offset . ', :st' . $offset . ', :si' . $offset . ', :wp' . $offset . ')';
                $params[':tid' . $offset] = $tenantId;
                $params[':src' . $offset] = $sourceId;
                $params[':sub' . $offset] = $submissionId;
                $params[':tv' . $offset] = $textVersionId;
                $params[':ft' . $offset] = $fp->type;
                $params[':sl' . $offset] = $fp->shingleLevel;
                $params[':ss' . $offset] = $fp->shingleSize;
                $params[':sh' . $offset] = $fp->hash;
                $params[':st' . $offset] = $fp->shingleText;
                $params[':si' . $offset] = $fp->segmentIndex;
                $params[':wp' . $offset] = $fp->wordPosition;
            }

            $sql = "INSERT INTO ac_similarity_fingerprints
                    (tenant_id, source_id, submission_id, text_version_id, fingerprint_type, shingle_level, shingle_size, shingle_hash, shingle_text, segment_index, word_position)
                    VALUES " . implode(', ', $placeholders);

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
        }
    }

    // ── Internal helpers ────────────────────────────────────────

    /**
     * Generate fingerprints for a single shingle level.
     */
    private function generateForLevel(
        array $segments,
        string $level,
        int $shingleSize,
        string $fingerprintType,
        ?int $sourceId = null,
        ?int $submissionId = null
    ): array {
        $fingerprints = [];
        $norm = ($level === 'short')
            ? new AcademicSimilarityNormalizationService($this->tenantId)
            : null;

        foreach ($segments as $segment) {
            $content = $segment->normalizedContent ?? $segment->content ?? '';
            $words = preg_split('/\s+/', trim($content));
            if ($words === false || count($words) < $shingleSize) {
                continue;
            }

            // Short level: apply stemming + stop-word removal before shingling
            if ($level === 'short' && $norm !== null) {
                $processed = [];
                foreach ($words as $w) {
                    $w = trim($w);
                    if ($w === '') continue;
                    if ($norm->isStopWord($w)) continue;
                    $processed[] = $norm->stem($w);
                }
                $words = $processed;
                if (count($words) < $shingleSize) {
                    continue;
                }
            }

            $wordCount = count($words);
            for ($i = 0; $i <= $wordCount - $shingleSize; $i++) {
                $shingleWords = array_slice($words, $i, $shingleSize);

                if ($fingerprintType === 'near') {
                    $canonical = $shingleWords;
                    sort($canonical);
                    $shingleText = implode(' ', $canonical);
                } else {
                    $shingleText = implode(' ', $shingleWords);
                }

                $hash = hash('sha256', $shingleText);

                $fingerprints[] = new AcademicSimilarityFingerprint(
                    $hash,
                    $fingerprintType,
                    $shingleSize,
                    implode(' ', array_slice(
                        preg_split('/\s+/', trim($segment->normalizedContent ?? $segment->content ?? '')),
                        $i, $shingleSize
                    )),
                    $segment->index,
                    $i,
                    $level
                );
            }
        }

        return $fingerprints;
    }

    /**
     * Winnowing: select a subset of fingerprints to reduce storage.
     *
     * For each sliding window of N = WINNOW_WINDOW_MULTIPLIER × shingleSize
     * shingles, keep only the shingle with the minimum hash value.
     * If multiple shingles in the window have the same minimum hash,
     * keep the rightmost occurrence.
     *
     * This reduces storage by ~75% for winnowed layers while preserving
     * match recall, following the Stanford Moss approach.
     *
     * @param AcademicSimilarityFingerprint[] $fingerprints Must be in document order
     * @param int $shingleSize
     * @return AcademicSimilarityFingerprint[]
     */
    private function winnow(array $fingerprints, int $shingleSize): array
    {
        $count = count($fingerprints);
        if ($count === 0) {
            return [];
        }

        $windowSize = self::WINNOW_WINDOW_MULTIPLIER * $shingleSize;
        if ($windowSize <= 0) {
            $windowSize = 20;
        }

        $selected = [];
        $selectedIndices = [];
        $minHash = null;
        $minPos = -1;

        for ($i = 0; $i < $count; $i++) {
            $hash = $fingerprints[$i]->hash;
            $hashInt = crc32($hash);

            if ($i < $windowSize) {
                // Build the first window
                if ($minHash === null || $hashInt <= $minHash) {
                    $minHash = $hashInt;
                    $minPos = $i;
                }
                if ($i === $windowSize - 1) {
                    $selectedIndices[$minPos] = true;
                }
            } else {
                // Slide the window: remove fingerprints[$i - windowSize], add fingerprints[$i]
                $removedHash = crc32($fingerprints[$i - $windowSize]->hash);
                $removedWasMin = ($i - $windowSize) === $minPos;

                if ($removedWasMin) {
                    // The minimum just left the window — find new minimum
                    $minHash = null;
                    $minPos = -1;
                    for ($j = $i - $windowSize + 1; $j <= $i; $j++) {
                        $h = crc32($fingerprints[$j]->hash);
                        if ($minHash === null || $h <= $minHash) {
                            $minHash = $h;
                            $minPos = $j;
                        }
                    }
                    $selectedIndices[$minPos] = true;
                } else {
                    // Compare new element against current minimum
                    if ($hashInt <= $minHash) {
                        $minHash = $hashInt;
                        $minPos = $i;
                        $selectedIndices[$minPos] = true;
                    }
                }
            }
        }

        foreach ($selectedIndices as $idx => $_) {
            $selected[] = $fingerprints[$idx];
        }

        return $selected;
    }
}
