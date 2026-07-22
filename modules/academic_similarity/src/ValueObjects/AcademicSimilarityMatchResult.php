<?php
declare(strict_types=1);

class AcademicSimilarityMatchResult
{
    public readonly int $submissionId;
    public readonly int $sourceId;
    public readonly string $matchType;
    public readonly float $confidence;
    public readonly ?int $submissionSegmentId;
    public readonly ?int $sourceSegmentId;
    public readonly int $matchedWordCount;
    public readonly int $submissionWordStart;
    public readonly int $submissionWordEnd;
    public readonly int $sourceWordStart;
    public readonly int $sourceWordEnd;
    public readonly int $segmentMatchCount;
    public readonly array $evidence; // Array of evidence arrays

    public function __construct(array $data) {
        $this->submissionId = (int)($data['submission_id'] ?? 0);
        $this->sourceId = (int)($data['source_id'] ?? 0);
        $this->matchType = $data['match_type'] ?? 'exact';
        $this->confidence = (float)($data['confidence'] ?? 1.0);
        $this->submissionSegmentId = isset($data['submission_segment_id']) ? (int)$data['submission_segment_id'] : null;
        $this->sourceSegmentId = isset($data['source_segment_id']) ? (int)$data['source_segment_id'] : null;
        $this->matchedWordCount = (int)($data['matched_word_count'] ?? 0);
        $this->submissionWordStart = (int)($data['submission_word_start'] ?? $data['submission_word_range_start'] ?? 0);
        $this->submissionWordEnd = (int)($data['submission_word_end'] ?? $data['submission_word_range_end'] ?? 0);
        $this->sourceWordStart = (int)($data['source_word_start'] ?? $data['source_word_range_start'] ?? 0);
        $this->sourceWordEnd = (int)($data['source_word_end'] ?? $data['source_word_range_end'] ?? 0);
        $this->segmentMatchCount = (int)($data['segment_match_count'] ?? 0);
        $this->evidence = $data['evidence'] ?? [];
    }

    public function toMatchArray(): array {
        return [
            'submission_id' => $this->submissionId,
            'source_id' => $this->sourceId,
            'match_type' => $this->matchType,
            'match_confidence' => $this->confidence,
            'submission_segment_id' => $this->submissionSegmentId,
            'source_segment_id' => $this->sourceSegmentId,
            'matched_word_count' => $this->matchedWordCount,
            'submission_word_range_start' => $this->submissionWordStart,
            'submission_word_range_end' => $this->submissionWordEnd,
            'source_word_range_start' => $this->sourceWordStart,
            'source_word_range_end' => $this->sourceWordEnd,
            'segment_match_count' => $this->segmentMatchCount,
        ];
    }
}
