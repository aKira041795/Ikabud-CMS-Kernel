<?php
declare(strict_types=1);

/**
 * AISS — Scholarship Profile Service (Phase 5)
 *
 * Produces document-level interpretive profiles describing how a submission
 * engages with its sources. The profile is NOT a single percentage — it is
 * a multi-dimensional assessment using supporting observations and confidence
 * levels.
 *
 * Dimensions:
 *   - Synthesis        — Combining multiple sources into new arguments
 *   - Methodological   — Dependence on established methods
 *   - Theoretical      — Dependence on established theory
 *   - Citation         — Completeness and accuracy of citations
 *   - Independence     — Level of independent contribution
 *   - Replication      - Reproducing prior work
 *   - Extension        - Building on prior work in new directions
 *   - Critical         - Critical engagement with sources
 *   - Originality      - Indicators of original contribution
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
     * Generate a scholarship profile for a submission.
     *
     * Analyzes all matches, their context relationships, scholarly relationships,
     * and attribution statuses to produce a structured profile.
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
        if (empty($matches)) {
            return $this->emptyProfile($submission);
        }

        // Analyze match aggregates
        $totalMatches = count($matches);
        $byContextRel = [];
        $byScholarlyRel = [];
        $byAttribution = [];
        $byEvidenceType = [];
        $modelProviders = [];

        foreach ($matches as $match) {
            $contextRel = $match['context_relationship'] ?? $match['machine_context_relationship'] ?? 'uncertain';
            $scholarlyRel = $match['scholarly_relationship'] ?? $match['machine_scholarly_relationship'] ?? 'uncertain';
            $attributionStatus = $match['attribution_status'] ?? $match['machine_attribution_status'] ?? 'unable_to_determine';
            $evidenceType = $match['match_type'] ?? 'unknown';
            $modelProvider = $match['model_provider'] ?? null;

            $byContextRel[$contextRel] = ($byContextRel[$contextRel] ?? 0) + 1;
            $byScholarlyRel[$scholarlyRel] = ($byScholarlyRel[$scholarlyRel] ?? 0) + 1;
            $byAttribution[$attributionStatus] = ($byAttribution[$attributionStatus] ?? 0) + 1;
            $byEvidenceType[$evidenceType] = ($byEvidenceType[$evidenceType] ?? 0) + 1;
            if ($modelProvider !== null) {
                $modelProviders[$modelProvider] = ($modelProviders[$modelProvider] ?? 0) + 1;
            }
        }

        // Calculate dimensional scores (0-100)
        $dimensions = $this->calculateDimensions($byContextRel, $byScholarlyRel, $byAttribution, $totalMatches);

        // Generate narrative summary
        $summary = $this->generateSummary($dimensions, $byScholarlyRel, $byAttribution, $totalMatches);

        return [
            'ok' => true,
            'profile' => [
                'submission_id' => $submissionId,
                'submission_title' => $submission['submission_title'] ?? '',
                'total_matches' => $totalMatches,
                'dimensions' => $dimensions,
                'relationship_summary' => [
                    'context_relationships' => $byContextRel,
                    'scholarly_relationships' => $byScholarlyRel,
                    'attribution_statuses' => $byAttribution,
                    'evidence_types' => $byEvidenceType,
                ],
                'model_providers' => array_keys($modelProviders),
                'summary' => $summary,
                'generated_at' => date('Y-m-d H:i:s'),
            ],
        ];
    }

    /**
     * Calculate dimensional scores.
     */
    private function calculateDimensions(array $byContext, array $byScholarly, array $byAttribution, int $total): array
    {
        $dimensions = [];

        // Synthesis — multiple scholarly synthesis classifications
        $synthesisCount = ($byScholarly[AcademicSimilarityEvidenceTaxonomy::SCHOLARLY_SYNTHESIS] ?? 0)
            + ($byScholarly[AcademicSimilarityEvidenceTaxonomy::SCHOLARLY_PARAPHRASE] ?? 0);
        $dimensions['synthesis'] = $total > 0
            ? round(($synthesisCount / $total) * 100, 1)
            : 0;

        // Methodological dependence — standard method classifications
        $methodCount = $byScholarly[AcademicSimilarityEvidenceTaxonomy::SCHOLARLY_STANDARD_METHOD] ?? 0;
        $dimensions['methodological_dependence'] = $total > 0
            ? round(($methodCount / $total) * 100, 1)
            : 0;

        // Attribution completeness — citations present and supported
        $attributedCount = ($byAttribution[AcademicSimilarityEvidenceTaxonomy::ATTRIBUTION_PRESENT_SUPPORTED] ?? 0)
            + ($byAttribution[AcademicSimilarityEvidenceTaxonomy::ATTRIBUTION_NOT_REQUIRED] ?? 0);
        $totalAttribution = array_sum($byAttribution);
        $dimensions['attribution_completeness'] = $totalAttribution > 0
            ? round(($attributedCount / $totalAttribution) * 100, 1)
            : 0;

        // Attribution concerns — missing or incomplete attributions
        $concernCount = ($byAttribution[AcademicSimilarityEvidenceTaxonomy::ATTRIBUTION_MISSING] ?? 0)
            + ($byAttribution[AcademicSimilarityEvidenceTaxonomy::ATTRIBUTION_PRESENT_INCOMPLETE] ?? 0)
            + ($byAttribution[AcademicSimilarityEvidenceTaxonomy::ATTRIBUTION_PRESENT_MISMATCHED] ?? 0);
        $dimensions['attribution_concerns'] = $totalAttribution > 0
            ? round(($concernCount / $totalAttribution) * 100, 1)
            : 0;

        // Independent contribution — independent agreement or topic-only
        $independentCount = ($byScholarly[AcademicSimilarityEvidenceTaxonomy::SCHOLARLY_INDEPENDENT_AGREEMENT] ?? 0)
            + ($byContext[AcademicSimilarityEvidenceTaxonomy::CONTEXT_TOPIC_ONLY] ?? 0);
        $dimensions['independent_engagement'] = $total > 0
            ? round(($independentCount / $total) * 100, 1)
            : 0;

        // Extension — building on prior work
        $extensionCount = ($byScholarly[AcademicSimilarityEvidenceTaxonomy::SCHOLARLY_EXTENSION] ?? 0)
            + ($byScholarly[AcademicSimilarityEvidenceTaxonomy::SCHOLARLY_REFINEMENT] ?? 0)
            + ($byScholarly[AcademicSimilarityEvidenceTaxonomy::SCHOLARLY_TRANSLATION] ?? 0);
        $dimensions['extension'] = $total > 0
            ? round(($extensionCount / $total) * 100, 1)
            : 0;

        // Critical engagement — critique or contradiction
        $criticalCount = $byScholarly[AcademicSimilarityEvidenceTaxonomy::SCHOLARLY_CRITIQUE] ?? 0;
        $dimensions['critical_engagement'] = $total > 0
            ? round(($criticalCount / $total) * 100, 1)
            : 0;

        // Possible unattributed reuse
        $unattributedCount = $byScholarly[AcademicSimilarityEvidenceTaxonomy::SCHOLARLY_POSSIBLE_UNATTRIBUTED_REUSE] ?? 0;
        $dimensions['possible_unattributed_reuse'] = $total > 0
            ? round(($unattributedCount / $total) * 100, 1)
            : 0;

        return $dimensions;
    }

    /**
     * Generate a human-readable narrative summary.
     */
    private function generateSummary(array $dimensions, array $byScholarly, array $byAttribution, int $totalMatches): array
    {
        $statements = [];

        // Synthesis
        if ($dimensions['synthesis'] > 40) {
            $statements[] = 'The submission primarily synthesizes prior work, combining multiple sources into new arguments.';
        } elseif ($dimensions['synthesis'] > 15) {
            $statements[] = 'The submission shows moderate synthesis of source material.';
        }

        // Method
        if ($dimensions['methodological_dependence'] > 30) {
            $statements[] = 'The methodology follows established studies in the field.';
        }

        // Attribution
        if ($dimensions['attribution_completeness'] > 70) {
            $statements[] = 'Citations are generally complete and well-supported.';
        } elseif ($dimensions['attribution_completeness'] < 30 && $totalMatches > 3) {
            $statements[] = 'Several passages may need clearer attribution.';
        }

        if ($dimensions['attribution_concerns'] > 30) {
            $statements[] = 'There are ' . round($dimensions['attribution_concerns'], 0) . '% of passages with potential attribution concerns that may require review.';
        }

        // Independent engagement
        if ($dimensions['independent_engagement'] > 50) {
            $statements[] = 'The submission demonstrates substantial independent engagement with the material.';
        }

        // Unattributed reuse
        if ($dimensions['possible_unattributed_reuse'] > 20) {
            $statements[] = 'Some passages may need review for possible unattributed reuse.';
        } elseif ($dimensions['possible_unattributed_reuse'] > 0) {
            $statements[] = 'A small number of passages may benefit from clearer attribution.';
        }

        // Critical engagement
        if ($dimensions['critical_engagement'] > 0) {
            $statements[] = 'The submission engages critically with sources, suggesting original analytical work.';
        }

        // Extension
        if ($dimensions['extension'] > 20) {
            $statements[] = 'The proposed work represents an extension or application of existing ideas into a new context.';
        }

        // Overall verbatim
        if (($byScholarly[AcademicSimilarityEvidenceTaxonomy::SCHOLARLY_POSSIBLE_UNATTRIBUTED_REUSE] ?? 0) === 0
            && ($byScholarly[AcademicSimilarityEvidenceTaxonomy::SCHOLARLY_INSUFFICIENT_ATTRIBUTION] ?? 0) === 0
            && $totalMatches > 0) {
            $statements[] = 'No substantial verbatim reproduction without attribution was detected.';
        }

        if (empty($statements)) {
            $statements[] = 'The evidence does not produce a strong directional profile. Manual review is recommended.';
        }

        return $statements;
    }

    /**
     * Return an empty profile when no matches exist.
     */
    private function emptyProfile(array $submission): array
    {
        return [
            'ok' => true,
            'profile' => [
                'submission_id' => $submission['id'] ?? 0,
                'submission_title' => $submission['submission_title'] ?? '',
                'total_matches' => 0,
                'dimensions' => [
                    'synthesis' => 0,
                    'methodological_dependence' => 0,
                    'attribution_completeness' => 0,
                    'attribution_concerns' => 0,
                    'independent_engagement' => 0,
                    'extension' => 0,
                    'critical_engagement' => 0,
                    'possible_unattributed_reuse' => 0,
                ],
                'relationship_summary' => [
                    'context_relationships' => [],
                    'scholarly_relationships' => [],
                    'attribution_statuses' => [],
                    'evidence_types' => [],
                ],
                'model_providers' => [],
                'summary' => ['No evidence data available to produce a scholarship profile.'],
                'generated_at' => date('Y-m-d H:i:s'),
            ],
        ];
    }
}
