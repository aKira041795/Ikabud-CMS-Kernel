<?php
declare(strict_types=1);

class AcademicSimilarityFingerprint
{
    public readonly string $hash;
    public readonly string $type; // 'exact', 'near'
    public readonly int $shingleSize;
    public readonly string $shingleText;
    public readonly int $segmentIndex;
    public readonly int $wordPosition;

    public function __construct(string $hash, string $type, int $shingleSize, string $shingleText, int $segmentIndex, int $wordPosition) {
        $this->hash = $hash;
        $this->type = $type;
        $this->shingleSize = $shingleSize;
        $this->shingleText = $shingleText;
        $this->segmentIndex = $segmentIndex;
        $this->wordPosition = $wordPosition;
    }

    public function toArray(): array {
        return [
            'shingle_hash' => $this->hash,
            'fingerprint_type' => $this->type,
            'shingle_size' => $this->shingleSize,
            'shingle_text' => $this->shingleText,
            'segment_index' => $this->segmentIndex,
            'word_position' => $this->wordPosition,
        ];
    }
}
