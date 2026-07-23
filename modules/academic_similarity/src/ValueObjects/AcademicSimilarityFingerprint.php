<?php
declare(strict_types=1);

/**
 * Represents a single shingle fingerprint for similarity matching.
 *
 * Supports 3 shingle levels for multi-layer matching:
 * - 'short' (3 words) — high recall, catches short copied phrases
 * - 'medium' (7 words) — balanced precision/recall (current default behavior)
 * - 'long' (20+ words) — high precision, document-level identity
 */
class AcademicSimilarityFingerprint
{
    public readonly string $hash;
    public readonly string $type; // 'exact', 'near'
    public readonly int $shingleSize;
    public readonly string $shingleLevel; // 'short', 'medium', 'long'
    public readonly string $shingleText;
    public readonly int $segmentIndex;
    public readonly int $wordPosition;

    public function __construct(
        string $hash,
        string $type,
        int $shingleSize,
        string $shingleText,
        int $segmentIndex,
        int $wordPosition,
        string $shingleLevel = 'medium'
    ) {
        $this->hash = $hash;
        $this->type = $type;
        $this->shingleSize = $shingleSize;
        $this->shingleLevel = $shingleLevel;
        $this->shingleText = $shingleText;
        $this->segmentIndex = $segmentIndex;
        $this->wordPosition = $wordPosition;
    }

    public function toArray(): array {
        return [
            'shingle_hash' => $this->hash,
            'fingerprint_type' => $this->type,
            'shingle_size' => $this->shingleSize,
            'shingle_level' => $this->shingleLevel,
            'shingle_text' => $this->shingleText,
            'segment_index' => $this->segmentIndex,
            'word_position' => $this->wordPosition,
        ];
    }
}
