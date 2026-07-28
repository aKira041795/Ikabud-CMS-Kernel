<?php
declare(strict_types=1);

/**
 * AISS — Context Analysis Service (Phase 2)
 *
 * Extends the semantic service from numeric comparison to structured
 * contextual interpretation. Uses the semantic service's comparison
 * results to classify the relationship between passages according to
 * the evidence taxonomy.
 *
 * This service is bound by strict AI governance rules:
 * 1. It must not declare plagiarism.
 * 2. It must distinguish textual overlap from conceptual relationship.
 * 3. It must inspect section and citation context.
 * 4. It must provide evidence for every classification.
 * 5. It must expose uncertainty.
 * 6. It must allow reviewer override.
 * 7. It must preserve original model output for audit.
 * 8. It must record model, provider, prompt version, and configuration.
 * 9. A provider failure must never block deterministic checking.
 * 10. Institution policy, not the model, determines escalation.
 *
 * @see AcademicSimilarityEvidenceTaxonomy for canonical enum values
 */

class AcademicSimilarityContextAnalysisService
{
    private string $tenantId;
    private AcademicSimilaritySemanticService $semantic;

    public function __construct(string $tenantId)
    {
        $this->tenantId = $tenantId;
        $this->semantic = new AcademicSimilaritySemanticService($tenantId);
    }

    /**
     * Analyze the relationship between a submission passage and a source passage.
     *
     * Returns structured contextual evidence including relationship type,
     * scholarly relationship, attribution status, confidence, and evidence.
     *
     * Input:
     *   submission_passage: string  — The passage from the submission
     *   source_passage: string      — The matching passage from the source
     *   surrounding_context: array  — Optional surrounding text for context
     *   citation_context: string    — Optional citation context near the passage
     *   document_sections: array    — Optional section names for each document
     *
     * Output:
     *   relationship: string           — Context relationship enum value
     *   scholarly_relationship: string — Scholarly relationship enum value
     *   attribution_status: string     — Attribution status enum value
     *   confidence: float              — Confidence in the classification (0-1)
     *   evidence: array<string>        — Human-readable evidence statements
     *   limitations: array<string>     — Known limitations of the analysis
     *   recommended_review: string     — Recommended reviewer action
     *
     * @param array $input {
     *     @type string $submission_passage
     *     @type string $source_passage
     *     @type array  $surrounding_context Optional
     *     @type string $citation_context Optional
     *     @type array  $document_sections Optional
     * }
     * @return array
     */
    public function analyze(array $input): array
    {
        $submissionPassage = trim((string)($input['submission_passage'] ?? ''));
        $sourcePassage = trim((string)($input['source_passage'] ?? ''));

        if ($submissionPassage === '' || $sourcePassage === '') {
            return $this->errorResult('Both submission_passage and source_passage are required');
        }

        // Step 1: Run semantic comparison if available
        $semanticScore = 0.0;
        $semanticResult = null;

        try {
            $semanticResult = $this->semantic->compare(
                [$submissionPassage],
                [$sourcePassage],
                ['threshold' => 0.15]  // Low threshold for candidate discovery
            );
            if (!empty($semanticResult['comparisons'][0]['similarity_score'])) {
                $semanticScore = (float)$semanticResult['comparisons'][0]['similarity_score'];
            }
        } catch (\Throwable $e) {
            // Provider failure must never block — use fallback heuristics
            write_log('Context analysis semantic call failed, using fallback: ' . $e->getMessage());
        }

        // Step 2: Classify context relationship based on semantic score + heuristics
        $relationship = $this->classifyContextRelationship($submissionPassage, $sourcePassage, $semanticScore);

        // Step 3: Determine scholarly relationship
        $scholarlyRelationship = $this->classifyScholarlyRelationship(
            $submissionPassage,
            $sourcePassage,
            $relationship,
            $input['citation_context'] ?? '',
            $input['document_sections'] ?? []
        );

        // Step 4: Determine attribution status
        $attributionStatus = $this->classifyAttributionStatus(
            $relationship,
            $input['citation_context'] ?? '',
            $submissionPassage
        );

        // Step 5: Generate evidence statements
        $evidence = $this->generateEvidence($relationship, $scholarlyRelationship, $attributionStatus, $semanticScore);

        // Step 6: Identify limitations
        $limitations = $this->identifyLimitations($submissionPassage, $sourcePassage, $semanticScore);

        // Step 7: Recommended reviewer action
        $recommendedReview = $this->recommendReviewerAction($relationship, $attributionStatus, $semanticScore);

        return [
            'ok' => true,
            'relationship' => $relationship,
            'scholarly_relationship' => $scholarlyRelationship,
            'attribution_status' => $attributionStatus,
            'confidence' => round(min(1.0, max(0.0, $semanticScore * 0.6 + 0.4)), 4),
            'evidence' => $evidence,
            'limitations' => $limitations,
            'recommended_review' => $recommendedReview,
            'semantic_score' => round($semanticScore, 4),
        ];
    }

    // ── Classification heuristics ─────────────────────────────────

    /**
     * Classify the context relationship between two passages.
     *
     * Uses semantic score as primary signal with text-length heuristics
     * as fallback when the semantic service is unavailable.
     */
    private function classifyContextRelationship(string $subText, string $srcText, float $semScore): string
    {
        // High semantic similarity suggests same claim/argument
        if ($semScore >= 0.70) {
            return AcademicSimilarityEvidenceTaxonomy::CONTEXT_SAME_CLAIM;
        }
        if ($semScore >= 0.50) {
            return AcademicSimilarityEvidenceTaxonomy::CONTEXT_SAME_ARGUMENT;
        }
        if ($semScore >= 0.30) {
            return AcademicSimilarityEvidenceTaxonomy::CONTEXT_SAME_METHOD;
        }

        // Low semantic score but same topic
        if ($semScore > 0.0) {
            return AcademicSimilarityEvidenceTaxonomy::CONTEXT_TOPIC_ONLY;
        }

        // Semantic service unavailable — use length-based heuristic
        $subLen = strlen($subText);
        $srcLen = strlen($srcText);
        $ratio = $srcLen > 0 ? $subLen / $srcLen : 0;

        if ($ratio > 0.8 && $ratio < 1.2 && $subLen > 100) {
            return AcademicSimilarityEvidenceTaxonomy::CONTEXT_SAME_CLAIM;
        }
        if ($ratio > 0.5 && $subLen > 50) {
            return AcademicSimilarityEvidenceTaxonomy::CONTEXT_SAME_ARGUMENT;
        }

        return AcademicSimilarityEvidenceTaxonomy::CONTEXT_UNCERTAIN;
    }

    /**
     * Classify the scholarly relationship.
     */
    private function classifyScholarlyRelationship(
        string $subText,
        string $srcText,
        string $contextRel,
        string $citationContext,
        array $docSections
    ): string {
        // Check for quotation marks
        $hasQuotes = str_contains($subText, '"') || str_contains($subText, '""');
        $hasCitation = $citationContext !== '';

        // Standard method detection (methodology sections)
        $subSection = strtolower((string)($docSections['submission'] ?? ''));
        $srcSection = strtolower((string)($docSections['source'] ?? ''));
        $isMethodSection = in_array($subSection, ['methodology', 'methods', 'procedure', 'experimental'], true)
            || in_array($srcSection, ['methodology', 'methods', 'procedure', 'experimental'], true);

        if ($hasQuotes && $hasCitation) {
            return AcademicSimilarityEvidenceTaxonomy::SCHOLARLY_ATTRIBUTED_USE;
        }
        if ($isMethodSection && $contextRel === AcademicSimilarityEvidenceTaxonomy::CONTEXT_SAME_METHOD) {
            return AcademicSimilarityEvidenceTaxonomy::SCHOLARLY_STANDARD_METHOD;
        }
        if ($hasCitation && $contextRel === AcademicSimilarityEvidenceTaxonomy::CONTEXT_SAME_EVIDENCE) {
            return AcademicSimilarityEvidenceTaxonomy::SCHOLARLY_SYNTHESIS;
        }
        if ($hasCitation) {
            return AcademicSimilarityEvidenceTaxonomy::SCHOLARLY_PARAPHRASE;
        }
        if ($contextRel === AcademicSimilarityEvidenceTaxonomy::CONTEXT_TOPIC_ONLY) {
            return AcademicSimilarityEvidenceTaxonomy::SCHOLARLY_INDEPENDENT_AGREEMENT;
        }

        return AcademicSimilarityEvidenceTaxonomy::SCHOLARLY_INSUFFICIENT_ATTRIBUTION;
    }

    /**
     * Classify attribution status.
     */
    private function classifyAttributionStatus(string $contextRel, string $citationContext, string $subText): string
    {
        if ($citationContext === '') {
            return AcademicSimilarityEvidenceTaxonomy::ATTRIBUTION_MISSING;
        }
        if (str_contains($subText, '"') || str_contains($subText, '""')) {
            return AcademicSimilarityEvidenceTaxonomy::ATTRIBUTION_PRESENT_SUPPORTED;
        }
        if ($contextRel === AcademicSimilarityEvidenceTaxonomy::CONTEXT_TOPIC_ONLY) {
            return AcademicSimilarityEvidenceTaxonomy::ATTRIBUTION_NOT_REQUIRED;
        }

        return AcademicSimilarityEvidenceTaxonomy::ATTRIBUTION_PRESENT_INCOMPLETE;
    }

    /**
     * Generate human-readable evidence statements.
     */
    private function generateEvidence(
        string $relationship,
        string $scholarlyRel,
        string $attributionStatus,
        float $semScore
    ): array {
        $evidence = [];

        $evidence[] = match ($relationship) {
            AcademicSimilarityEvidenceTaxonomy::CONTEXT_SAME_CLAIM => 'Both passages describe the same claim or proposition.',
            AcademicSimilarityEvidenceTaxonomy::CONTEXT_SAME_ARGUMENT => 'Both passages develop the same line of reasoning.',
            AcademicSimilarityEvidenceTaxonomy::CONTEXT_SAME_METHOD => 'Both passages describe the same methodology or procedure.',
            AcademicSimilarityEvidenceTaxonomy::CONTEXT_SAME_FRAMEWORK => 'Both passages use the same theoretical framework.',
            AcademicSimilarityEvidenceTaxonomy::CONTEXT_SAME_EVIDENCE => 'Both passages cite the same evidence or data.',
            AcademicSimilarityEvidenceTaxonomy::CONTEXT_SAME_CONCLUSION => 'Both passages reach the same conclusion.',
            AcademicSimilarityEvidenceTaxonomy::CONTEXT_TOPIC_ONLY => 'Both passages discuss the same topic but the wording differs substantially.',
            default => 'The relationship between these passages could not be conclusively determined.',
        };

        $evidence[] = match ($scholarlyRel) {
            AcademicSimilarityEvidenceTaxonomy::SCHOLARLY_ATTRIBUTED_USE => 'The material appears to be properly attributed.',
            AcademicSimilarityEvidenceTaxonomy::SCHOLARLY_STANDARD_METHOD => 'The methodology is standard for this field and may not require original wording.',
            AcademicSimilarityEvidenceTaxonomy::SCHOLARLY_SYNTHESIS => 'The passage synthesizes material from the cited source into a new argument.',
            AcademicSimilarityEvidenceTaxonomy::SCHOLARLY_PARAPHRASE => 'The passage restates the source material in different words.',
            AcademicSimilarityEvidenceTaxonomy::SCHOLARLY_INSUFFICIENT_ATTRIBUTION => 'A source relationship exists but attribution is insufficient.',
            AcademicSimilarityEvidenceTaxonomy::SCHOLARLY_POSSIBLE_UNATTRIBUTED_REUSE => 'The passage closely resembles the source without clear attribution.',
            AcademicSimilarityEvidenceTaxonomy::SCHOLARLY_INDEPENDENT_AGREEMENT => 'The passages independently reach similar conclusions — not necessarily copying.',
            default => 'The scholarly relationship requires human review.',
        };

        if ($semScore > 0) {
            $evidence[] = 'Semantic similarity score: ' . round($semScore * 100, 1) . '% — this measures conceptual overlap, not copied text.';
        }

        return $evidence;
    }

    /**
     * Identify limitations of the current analysis.
     */
    private function identifyLimitations(string $subText, string $srcText, float $semScore): array
    {
        $limitations = [];

        if ($semScore <= 0) {
            $limitations[] = 'Semantic comparison service was unavailable — classification uses length-based heuristics only.';
        }
        if (strlen($subText) < 50 || strlen($srcText) < 50) {
            $limitations[] = 'One or both passages are very short — classification confidence may be reduced.';
        }
        $limitations[] = 'The system cannot verify whether reuse was authorized by the source copyright holder.';
        $limitations[] = 'Citation context analysis is limited to nearby text — full bibliography not yet checked.';

        return $limitations;
    }

    /**
     * Recommend a reviewer action based on the analysis.
     */
    private function recommendReviewerAction(string $relationship, string $attributionStatus, float $semScore): string
    {
        if ($attributionStatus === AcademicSimilarityEvidenceTaxonomy::ATTRIBUTION_MISSING
            && in_array($relationship, [
                AcademicSimilarityEvidenceTaxonomy::CONTEXT_SAME_CLAIM,
                AcademicSimilarityEvidenceTaxonomy::CONTEXT_SAME_ARGUMENT,
                AcademicSimilarityEvidenceTaxonomy::CONTEXT_SAME_EVIDENCE,
                AcademicSimilarityEvidenceTaxonomy::CONTEXT_SAME_CONCLUSION,
            ], true)
        ) {
            return 'Confirm whether the source should be cited for this passage. The relationship is strong but no citation was detected nearby.';
        }

        if ($attributionStatus === AcademicSimilarityEvidenceTaxonomy::ATTRIBUTION_PRESENT_INCOMPLETE) {
            return 'Verify that the cited source fully supports the claim made in this passage. The citation is present but may be incomplete.';
        }

        if ($relationship === AcademicSimilarityEvidenceTaxonomy::CONTEXT_TOPIC_ONLY) {
            return 'Review the passage for contextual relevance. The topic-level overlap alone does not indicate a copying concern.';
        }

        return 'Review the passage and confirm the evidence classification matches your assessment.';
    }

    private function errorResult(string $message): array
    {
        return [
            'ok' => false,
            'error' => $message,
            'relationship' => AcademicSimilarityEvidenceTaxonomy::CONTEXT_UNCERTAIN,
            'scholarly_relationship' => AcademicSimilarityEvidenceTaxonomy::SCHOLARLY_UNCERTAIN,
            'attribution_status' => AcademicSimilarityEvidenceTaxonomy::ATTRIBUTION_UNABLE_TO_DETERMINE,
            'confidence' => 0.0,
            'evidence' => [],
            'limitations' => ['Analysis could not be completed: ' . $message],
            'recommended_review' => 'Manual review required — automated analysis could not classify this passage.',
        ];
    }
}
