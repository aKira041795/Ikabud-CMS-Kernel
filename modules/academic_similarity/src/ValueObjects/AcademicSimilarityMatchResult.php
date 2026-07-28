<?php
declare(strict_types=1);

/**
 * AISS — Match Result Value Object
 *
 * Represents a single detected relationship between a submission passage
 * and a source passage. Supports the evidence taxonomy with machine and
 * reviewer classification separation.
 *
 * @see AcademicSimilarityEvidenceTaxonomy for canonical enum values
 */

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
    public readonly array $evidence;

    // Phase 1 — Evidence taxonomy fields
    public readonly ?string $contextRelationship;
    public readonly ?float $contextConfidence;
    public readonly ?string $contextExplanation;
    public readonly ?string $scholarlyRelationship;
    public readonly ?string $attributionStatus;

    // Machine-generated classification (never overwritten)
    public readonly ?string $machineContextRelationship;
    public readonly ?float $machineContextConfidence;
    public readonly ?string $machineContextExplanation;
    public readonly ?string $machineScholarlyRelationship;
    public readonly ?string $machineAttributionStatus;

    // Reviewer classification (separate from machine)
    public readonly ?string $reviewerClassification;
    public readonly ?string $reviewerDecision;
    public readonly ?string $reviewerReason;
    public readonly ?int $reviewedBy;
    public readonly ?string $reviewedAt;

    // Model provenance
    public readonly ?string $modelProvider;
    public readonly ?string $modelName;
    public readonly ?string $promptVersion;
    public readonly ?array $modelConfig;

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

        // Evidence taxonomy
        $this->contextRelationship = $data['context_relationship'] ?? null;
        $this->contextConfidence = isset($data['context_confidence']) ? (float)$data['context_confidence'] : null;
        $this->contextExplanation = $data['context_explanation'] ?? null;
        $this->scholarlyRelationship = $data['scholarly_relationship'] ?? null;
        $this->attributionStatus = $data['attribution_status'] ?? null;

        // Machine classification (preserved, never overwritten)
        $this->machineContextRelationship = $data['machine_context_relationship'] ?? null;
        $this->machineContextConfidence = isset($data['machine_context_confidence']) ? (float)$data['machine_context_confidence'] : null;
        $this->machineContextExplanation = $data['machine_context_explanation'] ?? null;
        $this->machineScholarlyRelationship = $data['machine_scholarly_relationship'] ?? null;
        $this->machineAttributionStatus = $data['machine_attribution_status'] ?? null;

        // Reviewer classification
        $this->reviewerClassification = $data['reviewer_classification'] ?? null;
        $this->reviewerDecision = $data['reviewer_decision'] ?? null;
        $this->reviewerReason = $data['reviewer_reason'] ?? null;
        $this->reviewedBy = isset($data['reviewed_by']) ? (int)$data['reviewed_by'] : null;
        $this->reviewedAt = $data['reviewed_at'] ?? null;

        // Model provenance
        $this->modelProvider = $data['model_provider'] ?? null;
        $this->modelName = $data['model_name'] ?? null;
        $this->promptVersion = $data['prompt_version'] ?? null;
        $this->modelConfig = isset($data['model_config_json']) && is_string($data['model_config_json'])
            ? json_decode($data['model_config_json'], true)
            : (isset($data['model_config']) && is_array($data['model_config']) ? $data['model_config'] : null);
    }

    /**
     * Get the evidence family for this match.
     */
    public function getEvidenceFamily(): string
    {
        return AcademicSimilarityEvidenceTaxonomy::getFamily($this->matchType);
    }

    /**
     * Whether this match belongs to the textual evidence family.
     */
    public function isTextual(): bool
    {
        return $this->getEvidenceFamily() === AcademicSimilarityEvidenceTaxonomy::FAMILY_TEXTUAL;
    }

    /**
     * Whether this match belongs to the contextual evidence family.
     */
    public function isContextual(): bool
    {
        return $this->getEvidenceFamily() === AcademicSimilarityEvidenceTaxonomy::FAMILY_CONTEXTUAL;
    }

    /**
     * Whether this match has been reviewed by a human.
     */
    public function isReviewed(): bool
    {
        return $this->reviewerDecision !== null || $this->reviewerClassification !== null;
    }

    /**
     * Convert to database insert array.
     */
    public function toMatchArray(): array {
        $arr = [
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

        // Include taxonomy fields if set
        if ($this->contextRelationship !== null) {
            $arr['context_relationship'] = $this->contextRelationship;
        }
        if ($this->contextConfidence !== null) {
            $arr['context_confidence'] = $this->contextConfidence;
        }
        if ($this->contextExplanation !== null) {
            $arr['context_explanation'] = $this->contextExplanation;
        }
        if ($this->scholarlyRelationship !== null) {
            $arr['scholarly_relationship'] = $this->scholarlyRelationship;
        }
        if ($this->attributionStatus !== null) {
            $arr['attribution_status'] = $this->attributionStatus;
        }

        // Model provenance
        if ($this->modelProvider !== null) {
            $arr['model_provider'] = $this->modelProvider;
        }
        if ($this->modelName !== null) {
            $arr['model_name'] = $this->modelName;
        }
        if ($this->promptVersion !== null) {
            $arr['prompt_version'] = $this->promptVersion;
        }

        return $arr;
    }
}
