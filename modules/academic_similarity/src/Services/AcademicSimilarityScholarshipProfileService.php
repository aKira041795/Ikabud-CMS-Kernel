<?php
declare(strict_types=1);

/**
 * AISS — Scholarship Profile Service (Phase 5, experimental)
 *
 * Produces document-level evidence distributions describing how a submission
 * relates to its sources. The profile reports OBSERVED EVIDENCE DISTRIBUTIONS
 * — what the system detected among analyzed passages — NOT an assessment of
 * the entire submission or its scholarly quality.
 *
 * The profile is NOT a percentage. All counts are bounded by the set of
 * detected and classified passages, which may represent only a fraction of
 * the full document.
 *
 * @experimental This service is not validated for institutional decisions.
 *   All outputs require qualified human review. Do not use profile
 *   dimensions or narrative statements as evidence of misconduct or
 *   scholarly quality in isolation.
 *
 * @see AcademicSimilarityEvidenceTaxonomy for classification values
 */

class AcademicSimilarityScholarshipProfileService
{
    private string $tenantId;
    private AcademicSimilarityMatchRepository $matchRepo;
    private AcademicSimilaritySubmissionRepository $submissionRepo;
    private AcademicSimilarityReportRepository $reportRepo;

    public function __construct(string $tenantId)
    {
        $this->tenantId = $tenantId;
        $this->matchRepo = new AcademicSimilarityMatchRepository($tenantId);
        $this->submissionRepo = new AcademicSimilaritySubmissionRepository($tenantId);
        $this->reportRepo = new AcademicSimilarityReportRepository($tenantId);
    }

    /**
     * Generate an observed evidence distribution profile for a submission.
     *
     * Returns counts of how analyzed passages distribute across evidence
     * categories. All numbers are bounded by the detected passage set —
     * they do NOT represent the full document.
     *
     * @param int $submissionId
     * @return array{
     *   ok: bool,
     *   profile?: array,
     *   error?: string,
     * }
     */
    public function generateProfile(int $submissionId): array
    {
        $submission = $this->submissionRepo->findById($submissionId);
        if (!$submission) {
            return ['ok' => false, 'error' => 'Submission not found'];
        }

        $matches = $this->matchRepo->findBySubmissionId($submissionId);
        $totalEligibleWords = (int)($submission['total_eligible_words'] ?? $submission['word_count'] ?? 0);

        if (empty($matches)) {
            return $this->emptyProfile($submission, $totalEligibleWords);
        }

        // Analyze match aggregates
        $totalMatches = count($matches);
        $byContextRel = [];
        $byScholarlyRel = [];
        $byAttribution = [];
        $byEvidenceType = [];

        foreach ($matches as $match) {
            $contextRel = $match['context_relationship'] ?? $match['machine_context_relationship'] ?? 'uncertain';
            $scholarlyRel = $match['scholarly_relationship'] ?? $match['machine_scholarly_relationship'] ?? 'uncertain';
            $attributionStatus = $match['attribution_status'] ?? $match['machine_attribution_status'] ?? 'unable_to_determine';
            $evidenceType = $match['match_type'] ?? 'unknown';

            $byContextRel[$contextRel] = ($byContextRel[$contextRel] ?? 0) + 1;
            $byScholarlyRel[$scholarlyRel] = ($byScholarlyRel[$scholarlyRel] ?? 0) + 1;
            $byAttribution[$attributionStatus] = ($byAttribution[$attributionStatus] ?? 0) + 1;
            $byEvidenceType[$evidenceType] = ($byEvidenceType[$evidenceType] ?? 0) + 1;
        }

        // Observed evidence distribution (NOT scores — just counts)
        $evidenceDistribution = $this->calculateEvidenceDistribution($byContextRel, $byScholarlyRel, $byAttribution, $totalMatches);

        // Generate evidence-bounded narrative
        $summary = $this->generateSummary($evidenceDistribution, $byScholarlyRel, $byAttribution, $totalMatches, $totalEligibleWords);

        return [
            'ok' => true,
            'profile' => [
                'submission_id' => $submissionId,
                'submission_title' => $submission['submission_title'] ?? '',
                'total_matches' => $totalMatches,
                'total_eligible_words' => $totalEligibleWords,
                'observed_evidence_distribution' => $evidenceDistribution,
                'relationship_summary' => [
                    'context_relationships' => $byContextRel,
                    'scholarly_relationships' => $byScholarlyRel,
                    'attribution_statuses' => $byAttribution,
                    'evidence_types' => $byEvidenceType,
                ],
                'summary' => $summary,
                'generated_at' => date('Y-m-d H:i:s'),
                '_experimental' => true,
                '_warning' => 'This profile shows the distribution of classified evidence among analyzed passages only. It is not an assessment of the full submission. Not validated for institutional decisions.',
            ],
        ];
    }

    /**
     * Calculate observed evidence distribution.
     * Returns counts and proportions among DETECTED passages only.
     * These are NOT scores for the full document.
     */
    private function calculateEvidenceDistribution(array $byContext, array $byScholarly, array $byAttribution, int $total): array
    {
        $distribution = [];

        // Among detected passages, how many relate to synthesis/paraphrase
        $synthesisCount = ($byScholarly[AcademicSimilarityEvidenceTaxonomy::SCHOLARLY_SYNTHESIS] ?? 0)
            + ($byScholarly[AcademicSimilarityEvidenceTaxonomy::SCHOLARLY_PARAPHRASE] ?? 0);
        $distribution['synthesis_related_evidence'] = $synthesisCount;

        // Shared method description (NOT "standard method" — that requires reviewer)
        $methodCount = ($byScholarly[AcademicSimilarityEvidenceTaxonomy::SCHOLARLY_SHARED_METHOD_DESCRIPTION] ?? 0)
            + ($byScholarly[AcademicSimilarityEvidenceTaxonomy::SCHOLARLY_STANDARD_METHOD] ?? 0);
        $distribution['shared_method_description_evidence'] = $methodCount;

        // Citation/attribution observations among passages
        $citationDetected = ($byAttribution[AcademicSimilarityEvidenceTaxonomy::ATTR_CITATION_DETECTED] ?? 0)
            + ($byAttribution[AcademicSimilarityEvidenceTaxonomy::ATTR_CITATION_AND_QUOTATION] ?? 0)
            + ($byAttribution[AcademicSimilarityEvidenceTaxonomy::ATTR_CITATION_UNRESOLVED] ?? 0);
        $citationNotDetected = $byAttribution[AcademicSimilarityEvidenceTaxonomy::ATTR_CITATION_NOT_DETECTED] ?? 0;
        $attributionNeedsReview = $byAttribution[AcademicSimilarityEvidenceTaxonomy::ATTR_ATTRIBUTION_NEEDS_REVIEW] ?? 0;

        $distribution['passages_with_nearby_citation'] = $citationDetected;
        $distribution['passages_without_detected_citation'] = $citationNotDetected;
        $distribution['attribution_needs_review'] = $attributionNeedsReview;

        // Shared topic (replaces "independent engagement")
        $sharedTopicCount = ($byScholarly[AcademicSimilarityEvidenceTaxonomy::SCHOLARLY_SHARED_TOPIC_ONLY] ?? 0)
            + ($byContext[AcademicSimilarityEvidenceTaxonomy::CONTEXT_TOPIC_ONLY] ?? 0);
        $distribution['shared_topic_only_evidence'] = $sharedTopicCount;

        // Extension evidence
        $extensionCount = ($byScholarly[AcademicSimilarityEvidenceTaxonomy::SCHOLARLY_EXTENSION] ?? 0)
            + ($byScholarly[AcademicSimilarityEvidenceTaxonomy::SCHOLARLY_REFINEMENT] ?? 0)
            + ($byScholarly[AcademicSimilarityEvidenceTaxonomy::SCHOLARLY_TRANSLATION] ?? 0);
        $distribution['extension_related_evidence'] = $extensionCount;

        // Critical engagement evidence
        $criticalCount = $byScholarly[AcademicSimilarityEvidenceTaxonomy::SCHOLARLY_CRITIQUE] ?? 0;
        $distribution['critical_engagement_evidence'] = $criticalCount;

        // Possible unattributed reuse
        $unattributedCount = $byScholarly[AcademicSimilarityEvidenceTaxonomy::SCHOLARLY_POSSIBLE_UNATTRIBUTED_REUSE] ?? 0;
        $distribution['possible_unattributed_reuse_evidence'] = $unattributedCount;

        // Unclassified
        $unclassifiedCount = $byScholarly['uncertain'] ?? 0;
        $distribution['unclassified_evidence'] = $unclassifiedCount;

        $distribution['total_classified_passages'] = $total;

        return $distribution;
    }

    /**
     * Generate evidence-bounded narrative summary.
     *
     * Every statement identifies the denominator (how many passages were
     * analyzed) and binds conclusions to what the system observed.
     */
    private function generateSummary(array $dist, array $byScholarly, array $byAttribution, int $totalMatches, int $totalEligibleWords): array
    {
        $statements = [];
        $denominator = "Among {$totalMatches} detected passage(s) out of {$totalEligibleWords} eligible words";

        // Synthesis
        if ($dist['synthesis_related_evidence'] > 0) {
            $count = $dist['synthesis_related_evidence'];
            $statements[] = "{$denominator}, {$count} show(s) synthesis or paraphrase patterns — the passage restates or combines source material in different words.";
        }

        // Method description
        if ($dist['shared_method_description_evidence'] > 0) {
            $count = $dist['shared_method_description_evidence'];
            $statements[] = "{$denominator}, {$count} passage(s) describe(s) a method also found in the source. Whether this constitutes a standard method in the discipline requires reviewer confirmation.";
        }

        // Attribution
        if ($dist['passages_with_nearby_citation'] > 0 && $dist['passages_without_detected_citation'] > 0) {
            $statements[] = "{$denominator}, {$dist['passages_with_nearby_citation']} have nearby citation signals and {$dist['passages_without_detected_citation']} do not. Citation presence does not confirm that the source supports the specific claim.";
        } elseif ($dist['passages_with_nearby_citation'] > 0) {
            $statements[] = "{$denominator}, all detected passages have nearby citation signals. The system has not verified that each citation refers to the matching source or supports the claim.";
        } elseif ($dist['passages_without_detected_citation'] > 0) {
            $statements[] = "{$denominator}, none have detected nearby citations. The source may still be cited elsewhere in the document.";
        }

        if ($dist['attribution_needs_review'] > 0) {
            $statements[] = "{$dist['attribution_needs_review']} passage(s) flagged for attribution review — citation signals are ambiguous or incomplete.";
        }

        // Shared topic
        if ($dist['shared_topic_only_evidence'] > 0) {
            $statements[] = "{$denominator}, {$dist['shared_topic_only_evidence']} show(s) shared topic overlap with low textual resemblance. This does not indicate copying — the passages may independently discuss similar subject matter.";
        }

        // Unattributed reuse
        if ($dist['possible_unattributed_reuse_evidence'] > 0) {
            $statements[] = "{$denominator}, {$dist['possible_unattributed_reuse_evidence']} passage(s) were classified as possibly unattributed reuse. Reviewer verification is required.";
        }

        // Critical engagement
        if ($dist['critical_engagement_evidence'] > 0) {
            $statements[] = "{$denominator}, {$dist['critical_engagement_evidence']} passage(s) show(s) critical engagement with source material.";
        }

        if (empty($statements)) {
            $statements[] = "{$denominator}, the detected evidence does not produce a strong directional pattern. Manual review is recommended.";
        }

        return $statements;
    }

    /**
     * Return an empty profile when no matches exist.
     */
    private function emptyProfile(array $submission, int $totalEligibleWords): array
    {
        return [
            'ok' => true,
            'profile' => [
                'submission_id' => $submission['id'] ?? 0,
                'submission_title' => $submission['submission_title'] ?? '',
                'total_matches' => 0,
                'total_eligible_words' => $totalEligibleWords,
                'observed_evidence_distribution' => [
                    'synthesis_related_evidence' => 0,
                    'shared_method_description_evidence' => 0,
                    'passages_with_nearby_citation' => 0,
                    'passages_without_detected_citation' => 0,
                    'attribution_needs_review' => 0,
                    'shared_topic_only_evidence' => 0,
                    'extension_related_evidence' => 0,
                    'critical_engagement_evidence' => 0,
                    'possible_unattributed_reuse_evidence' => 0,
                    'unclassified_evidence' => 0,
                    'total_classified_passages' => 0,
                ],
                'relationship_summary' => [
                    'context_relationships' => [],
                    'scholarly_relationships' => [],
                    'attribution_statuses' => [],
                    'evidence_types' => [],
                ],
                'summary' => ['No evidence data available to produce a scholarship profile.'],
                'generated_at' => date('Y-m-d H:i:s'),
                '_experimental' => true,
                '_warning' => 'This profile shows the distribution of classified evidence among analyzed passages only. It is not an assessment of the full submission.',
            ],
        ];
    }
}
