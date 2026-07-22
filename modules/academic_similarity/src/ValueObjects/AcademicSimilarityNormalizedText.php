<?php
declare(strict_types=1);

class AcademicSimilarityNormalizedText
{
    public readonly string $originalText;
    public readonly string $normalizedText;
    public readonly int $originalWordCount;
    public readonly int $normalizedWordCount;
    /** @var array<int, int> Maps normalized char offset -> original char offset */
    public readonly array $offsetMap;
    public readonly string $textHash;

    public function __construct(string $originalText, string $normalizedText, array $offsetMap) {
        $this->originalText = $originalText;
        $this->normalizedText = $normalizedText;
        $this->offsetMap = $offsetMap;
        $this->originalWordCount = str_word_count($originalText);
        $this->normalizedWordCount = str_word_count($normalizedText);
        $this->textHash = hash('sha256', $normalizedText);
    }

    /** Map a normalized offset back to original offset */
    public function originalOffset(int $normalizedOffset): int {
        return $this->offsetMap[$normalizedOffset] ?? $normalizedOffset;
    }

    /** Get original text range from normalized range */
    public function originalRange(int $normStart, int $normEnd): array {
        return [
            'start' => $this->originalOffset($normStart),
            'end' => $this->originalOffset($normEnd),
        ];
    }
}
