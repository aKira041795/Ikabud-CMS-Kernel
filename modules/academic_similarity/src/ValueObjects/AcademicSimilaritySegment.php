<?php
declare(strict_types=1);

class AcademicSimilaritySegment
{
    public readonly int $index;
    public readonly string $type; // 'sentence', 'paragraph', 'page'
    public readonly string $content;
    public readonly string $normalizedContent;
    public readonly int $wordCount;
    public readonly int $charCount;
    public readonly int $originalStartOffset;
    public readonly int $originalEndOffset;
    public readonly int $normalizedStartOffset;
    public readonly int $normalizedEndOffset;
    public readonly ?int $pageId;
    public readonly bool $isQuotation;
    public readonly bool $isBibliography;

    public function __construct(array $data) {
        $this->index = (int)($data['index'] ?? 0);
        $this->type = $data['type'] ?? 'sentence';
        $this->content = $data['content'] ?? '';
        $this->normalizedContent = $data['normalized_content'] ?? $this->content;
        $this->wordCount = (int)($data['word_count'] ?? str_word_count($this->content));
        $this->charCount = (int)($data['char_count'] ?? strlen($this->content));
        $this->originalStartOffset = (int)($data['original_start_offset'] ?? 0);
        $this->originalEndOffset = (int)($data['original_end_offset'] ?? 0);
        $this->normalizedStartOffset = (int)($data['normalized_start_offset'] ?? 0);
        $this->normalizedEndOffset = (int)($data['normalized_end_offset'] ?? 0);
        $this->pageId = isset($data['page_id']) ? (int)$data['page_id'] : null;
        $this->isQuotation = (bool)($data['is_quotation'] ?? false);
        $this->isBibliography = (bool)($data['is_bibliography'] ?? false);
    }

    public function toArray(): array {
        return [
            'index' => $this->index,
            'type' => $this->type,
            'content' => $this->content,
            'normalized_content' => $this->normalizedContent,
            'word_count' => $this->wordCount,
            'char_count' => $this->charCount,
            'original_start_offset' => $this->originalStartOffset,
            'original_end_offset' => $this->originalEndOffset,
            'normalized_start_offset' => $this->normalizedStartOffset,
            'normalized_end_offset' => $this->normalizedEndOffset,
            'page_id' => $this->pageId,
            'is_quotation' => $this->isQuotation,
            'is_bibliography' => $this->isBibliography,
        ];
    }
}
