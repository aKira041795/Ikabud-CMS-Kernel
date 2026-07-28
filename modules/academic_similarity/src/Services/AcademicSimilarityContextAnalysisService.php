<?php
declare(strict_types=1);

/**
 * AISS — Context Analysis Service (Phase 2, experimental)
 *
 * Provides cautious contextual interpretation between two passages.
 *
 * This service is EXPERIMENTAL and is designed for reviewer assistance only.
 * It is NOT validated for institutional decisions. All outputs must be
 * reviewed by a qualified human before any academic integrity action.
 *
 * AI Governance Rules (non-negotiable):
 * 1. Must not declare plagiarism.
 * 2. Must distinguish textual overlap from conceptual relationship.
 * 3. Must inspect section and citation context.
 * 4. Must provide evidence for every classification.
 * 5. Must expose uncertainty.
 * 6. Must allow reviewer override.
 * 7. Must preserve original model output for audit.
 * 8. Must record model, provider, prompt version, and configuration.
 * 9. A provider failure must never block deterministic checking.
 * 10. Institution policy, not the model, determines escalation.
 *
 * Epistemic restraint rules:
 * - Do not map numeric scores to specific rhetorical relationships.
 * - Do not infer "same_claim" or "same_method" from similarity alone.
 * - Return relationship: uncertain with candidate_relationships list.
 * - When semantic service is unavailable, return uncertain with low confidence.
 * - Confidence must be categorical or evidence-component-based, not inflated.
 *
 * @see AcademicSimilarityEvidenceTaxonomy for canonical enum values
 *
 * @experimental This service produces candidate classifications only.
 *   All outputs require human review before any institutional action.
 */

class AcademicSimilarityContextAnalysisService
{
    private string $tenantId;
    private AcademicSimilaritySemanticService $semantic;

    /** Evidence strength labels (not a single inflated percentage) */
    private const STRENGTH_NONE     = 'none';
    private const STRENGTH_WEAK     = 'weak';
    private const STRENGTH_MODERATE = 'moderate';
    private const STRENGTH_STRONG   = 'strong';

    public function __construct(string $tenantId)
    {
        $this->tenantId = $tenantId;
        $this->semantic = new AcademicSimilaritySemanticService($tenantId);
    }

    /**
     * Analyze the relationship between two passages.
     *
     * Returns cautious, evidence-bound classifications. The primary output
     * is relationship: 'uncertain' with candidate_relationships listed.
     * A specific relationship is only assigned when supporting evidence
     * (section context, citation context, lexical signals) confirms it.
     *
     * @param array $input {
     *     @type string $submission_passage
     *     @type string $source_passage
     *     @type array  $surrounding_context Optional {submission_before, submission_after, source_before, source_after}
     *     @type string $citation_context Optional
     *     @type array  $document_sections Optional {submission, source}
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

        // Step 1: Run semantic comparison
        $semanticScore = null;
        $semanticAvailable = false;

        try {
            $semanticResult = $this->semantic->compare(
                [$submissionPassage],
                [$sourcePassage],
                ['threshold' => 0.15]
            );
            if (!empty($semanticResult['comparisons'][0]['similarity_score'])) {
                $semanticScore = (float)$semanticResult['comparisons'][0]['similarity_score'];
                $semanticAvailable = true;
            }
        } catch (\Throwable $e) {
            write_log('Context analysis semantic call failed: ' . $e->getMessage());
            // Provider failure must never block — return uncertain
        }

        // Step 2: Determine evidence strength from semantic score alone
        $evidenceStrength = $this->assessEvidenceStrength($semanticScore, $semanticAvailable);

        // Step 3: Gather signals from context
        $signals = $this->gatherSignals(
            $submissionPassage,
            $input['citation_context'] ?? '',
            $input['document_sections'] ?? []
        );

        // Step 4: Build candidate relationships (not a single forced classification)
        $candidates = $this->buildCandidates($evidenceStrength, $signals);

        // Step 5: Determine cautious attribution signals (not a definitive status)
        $attributionSignals = $this->assessAttributionSignals(
            $input['citation_context'] ?? '',
            $submissionPassage,
            $signals
        );

        // Step 6: Generate evidence statements
        $evidence = $this->generateEvidence($semanticScore, $evidenceStrength, $candidates, $signals);

        // Step 7: Identify limitations
        $limitations = $this->identifyLimitations($submissionPassage, $sourcePassage, $semanticAvailable);

        // Step 8: Recommended reviewer action
        $recommendedReview = $this->recommendReviewerAction($evidenceStrength, $signals);

        return [
            'ok' => true,
            'relationship' => AcademicSimilarityEvidenceTaxonomy::CONTEXT_UNCERTAIN,
            'candidate_relationships' => $candidates,
            'evidence_strength' => $evidenceStrength,
            'scholarly_signals' => $signals['scholarly'] ?? [],
            'attribution_signals' => $attributionSignals,
            'semantic_score' => $semanticScore !== null ? round($semanticScore, 4) : null,
            'semantic_available' => $semanticAvailable,
            'evidence' => $evidence,
            'limitations' => $limitations,
            'recommended_review' => $recommendedReview,
            '_experimental' => true,
            '_warning' => 'This analysis is experimental and not validated for institutional decisions. All outputs require human review.',
        ];
    }

    // ── Evidence strength (not a single confidence percentage) ────

    /**
     * Assess evidence strength from semantic comparison results.
     * Returns a categorical label, not an inflated percentage.
     */
    private function assessEvidenceStrength(?float $semScore, bool $semanticAvailable): string
    {
        if (!$semanticAvailable) {
            return self::STRENGTH_NONE;
        }
        if ($semScore === null || $semScore <= 0.0) {
            return self::STRENGTH_NONE;
        }
        if ($semScore >= 0.70) {
            return self::STRENGTH_STRONG;
        }
        if ($semScore >= 0.40) {
            return self::STRENGTH_MODERATE;
        }
        return self::STRENGTH_WEAK;
    }

    // ── Signal gathering ─────────────────────────────────────────

    /**
     * Gather evidence signals from passage context.
     * These are observations, not conclusions.
     */
    private function gatherSignals(string $passage, string $citationContext, array $docSections): array
    {
        $signals = [];

        // Citation signals
        $signals['citation_detected'] = $citationContext !== '';
        $signals['quotation_markers_detected'] = str_contains($passage, '"') || str_contains($passage, '""');

        // Section signals
        $subSection = strtolower((string)($docSections['submission'] ?? ''));
        $srcSection = strtolower((string)($docSections['source'] ?? ''));
        $signals['submission_section'] = $subSection;
        $signals['source_section'] = $srcSection;
        $signals['both_method_sections'] = in_array($subSection, ['methodology', 'methods', 'procedure', 'experimental'], true)
            && in_array($srcSection, ['methodology', 'methods', 'procedure', 'experimental'], true);

        // Length signals
        $signals['passage_length_sufficient'] = strlen($passage) >= 50;

        // Scholarly signals (cautious observations)
        $scholarly = [];
        if ($signals['quotation_markers_detected']) {
            $scholarly[] = 'quotation_markers_present';
        }
        if ($signals['citation_detected']) {
            $scholarly[] = 'nearby_citation_detected';
        }
        if ($signals['both_method_sections']) {
            $scholarly[] = 'shared_method_section_context';
        }
        $signals['scholarly'] = $scholarly;

        return $signals;
    }

    // ── Candidate relationships ──────────────────────────────────

    /**
     * Build candidate relationships that the evidence MAY support.
     * Does NOT assert any single relationship — returns possibilities.
     */
    private function buildCandidates(string $strength, array $signals): array
    {
        $candidates = [];

        if ($strength === self::STRENGTH_NONE) {
            return $candidates; // Empty — no evidence to support any candidate
        }

        // Semantic strength suggests resemblance level
        $resemblance = match ($strength) {
            self::STRENGTH_STRONG => 'strong_conceptual_resemblance',
            self::STRENGTH_MODERATE => 'moderate_conceptual_resemblance',
            self::STRENGTH_WEAK => 'weak_conceptual_resemblance',
            default => null,
        };
        if ($resemblance !== null) {
            $candidates[] = $resemblance;
        }

        // Section context may suggest method relationship
        if ($signals['both_method_sections'] ?? false) {
            $candidates[] = 'shared_method_description';
        }

        // Citation + quotation may suggest attributed use
        if (($signals['citation_detected'] ?? false) && ($signals['quotation_markers_detected'] ?? false)) {
            $candidates[] = 'possible_quotation';
        }

        return $candidates;
    }

    // ── Attribution signals (cautious) ────────────────────────────

    /**
     * Assess attribution signals. Returns observations, not definitive statuses.
     * Does NOT assert that attribution is "present and supported" — that
     * requires linking the citation to the source and verifying support.
     */
    private function assessAttributionSignals(string $citationContext, string $passage, array $signals): array
    {
        $result = [];

        if ($citationContext !== '') {
            $result['citation_detected'] = true;
            $result['citation_context_sample'] = mb_substr($citationContext, 0, 200);
        } else {
            $result['citation_detected'] = false;
        }

        $result['quotation_markers_detected'] = $signals['quotation_markers_detected'] ?? false;

        // Determine cautious status
        if (!$result['citation_detected']) {
            $result['status'] = 'citation_not_detected';
            $result['status_label'] = 'Citation not detected nearby';
            $result['caveat'] = 'Absence of detected citation does not confirm missing attribution — the source may be cited elsewhere in the document.';
        } elseif ($result['quotation_markers_detected']) {
            $result['status'] = 'citation_and_quotation_detected';
            $result['status_label'] = 'Citation and quotation markers detected';
            $result['caveat'] = 'A citation with quotation marks was detected, but the system has not verified that the cited source supports this specific passage.';
        } else {
            $result['status'] = 'citation_detected';
            $result['status_label'] = 'Citation detected nearby';
            $result['caveat'] = 'A citation reference was detected near this passage, but the system has not verified that it refers to this source or supports this claim.';
        }

        return $result;
    }

    // ── Evidence generation ───────────────────────────────────────

    /**
     * Generate human-readable evidence statements.
     * Binds every statement to what the system actually observed.
     */
    private function generateEvidence(?float $semScore, string $strength, array $candidates, array $signals): array
    {
        $evidence = [];

        if ($strength !== self::STRENGTH_NONE && $semScore !== null) {
            $evidence[] = 'Semantic comparison score: ' . round($semScore * 100, 1) . '%. This measures conceptual overlap between passages, not rhetorical relationship or copied text.';
        }

        if ($strength === self::STRENGTH_NONE) {
            $evidence[] = 'No semantic comparison result was available. The system cannot determine a relationship between these passages.';
            return $evidence;
        }

        $evidence[] = match ($strength) {
            self::STRENGTH_STRONG => 'The passages show strong conceptual resemblance. The specific relationship (same claim, same argument, same method, etc.) cannot be determined from similarity alone and requires additional evidence.',
            self::STRENGTH_MODERATE => 'The passages show moderate conceptual resemblance. The relationship type is uncertain.',
            self::STRENGTH_WEAK => 'The passages show weak conceptual resemblance. They may share only broad topical overlap.',
            default => 'The relationship between these passages could not be conclusively determined.',
        };

        // Citation observations
        if ($signals['citation_detected'] ?? false) {
            $evidence[] = 'A citation reference was detected near the passage.';
        } else {
            $evidence[] = 'No citation was detected near this specific passage. The source may still be cited elsewhere in the document.';
        }

        if ($signals['quotation_markers_detected'] ?? false) {
            $evidence[] = 'Quotation markers were detected in the passage text.';
        }

        if ($signals['both_method_sections'] ?? false) {
            $evidence[] = 'Both passages appear in methodology-related sections, which may indicate shared methodological description rather than textual copying.';
        }

        return $evidence;
    }

    // ── Limitations ───────────────────────────────────────────────

    /**
     * Identify limitations of the current analysis.
     */
    private function identifyLimitations(string $subText, string $srcText, bool $semanticAvailable): array
    {
        $limitations = [
            'The system cannot verify whether reuse was authorized by the source copyright holder.',
            'The system cannot determine whether the passage represents common knowledge in the discipline.',
            'The system cannot verify whether a detected citation actually supports the claim made.',
            'Passage-level context analysis does not examine the full document or bibliography.',
        ];

        if (!$semanticAvailable) {
            $limitations[] = 'Semantic comparison service was unavailable — no evidence-based classification was possible.';
        }

        if (strlen($subText) < 50 || strlen($srcText) < 50) {
            $limitations[] = 'One or both passages are very short — the available text may be insufficient for meaningful analysis.';
        }

        return $limitations;
    }

    // ── Reviewer recommendation ───────────────────────────────────

    /**
     * Recommend a reviewer action based on available evidence.
     */
    private function recommendReviewerAction(string $strength, array $signals): string
    {
        if ($strength === self::STRENGTH_NONE) {
            return 'Manual review required — automated analysis could not classify this passage.';
        }

        $citationDetected = $signals['citation_detected'] ?? false;
        $quotesDetected = $signals['quotation_markers_detected'] ?? false;

        if ($strength === self::STRENGTH_STRONG && !$citationDetected) {
            return 'Review required. The passages show strong conceptual resemblance, but no citation was detected nearby. Confirm whether the source should be attributed.';
        }

        if ($strength === self::STRENGTH_STRONG && $citationDetected && !$quotesDetected) {
            return 'Review recommended. Strong conceptual resemblance with nearby citation but without quotation markers. Verify that the paraphrase is adequate and attribution is complete.';
        }

        if ($signals['both_method_sections'] ?? false) {
            return 'Review to confirm whether this is standard methodological description. If so, consider classifying as shared method description.';
        }

        return 'Review the passage and confirm the evidence classification matches your assessment.';
    }

    // ── Error result ──────────────────────────────────────────────

    private function errorResult(string $message): array
    {
        return [
            'ok' => false,
            'error' => $message,
            'relationship' => AcademicSimilarityEvidenceTaxonomy::CONTEXT_UNCERTAIN,
            'candidate_relationships' => [],
            'evidence_strength' => self::STRENGTH_NONE,
            'scholarly_signals' => [],
            'attribution_signals' => [
                'citation_detected' => false,
                'quotation_markers_detected' => false,
                'status' => 'unable_to_determine',
                'status_label' => 'Unable to determine',
                'caveat' => 'Analysis could not be completed.',
            ],
            'semantic_score' => null,
            'semantic_available' => false,
            'evidence' => [],
            'limitations' => ['Analysis could not be completed: ' . $message],
            'recommended_review' => 'Manual review required — automated analysis could not classify this passage.',
            '_experimental' => true,
            '_warning' => 'This analysis is experimental and not validated for institutional decisions. All outputs require human review.',
        ];
    }
}
