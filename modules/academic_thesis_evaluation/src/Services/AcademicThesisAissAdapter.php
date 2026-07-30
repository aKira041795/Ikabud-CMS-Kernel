<?php
declare(strict_types=1);

/**
 * Adapter for AISS integration through the capability bus.
 * Preserves raw AISS output, records maturity metadata, prevents AISS failure from blocking unrelated stages.
 */
class AcademicThesisAissAdapter
{
    private string $tenantId;
    private EvaluationCaseRepository $caseRepo;
    private ManuscriptVersionRepository $manuscriptRepo;
    private AissEvidenceSnapshotRepository $snapshotRepo;
    private AuditEventRepository $auditRepo;
    /** @var callable|null */
    private $capabilityCaller;

    public function __construct(string $tenantId, ?callable $capabilityCaller = null)
    {
        $this->tenantId = $tenantId;
        $this->caseRepo = new EvaluationCaseRepository($tenantId);
        $this->manuscriptRepo = new ManuscriptVersionRepository($tenantId);
        $this->snapshotRepo = new AissEvidenceSnapshotRepository($tenantId);
        $this->auditRepo = new AuditEventRepository($tenantId);
        $this->capabilityCaller = $capabilityCaller;
    }

    public function generateSnapshot(int $caseId, int $actorId): array
    {
        $case = $this->caseRepo->findById($caseId);
        if (!$case) {
            return ['ok' => false, 'error' => 'Evaluation case not found'];
        }

        $activeVersionId = (int)($case['active_manuscript_version_id'] ?? 0);
        if (!$activeVersionId) {
            return ['ok' => false, 'error' => 'No active manuscript version. Upload a manuscript first.'];
        }

        $manuscript = $this->manuscriptRepo->findById($activeVersionId);
        if (!$manuscript) {
            return ['ok' => false, 'error' => 'Manuscript version not found'];
        }

        $warnings = [];
        $maturityMetadata = [
            'textual_matching' => 'unavailable',
            'citation_detection' => 'unavailable',
            'context_analysis' => 'unavailable',
            'semantic_resemblance' => 'unavailable',
            'internet_coverage' => 'unavailable',
        ];
        $textualResult = null;
        $citationResult = null;
        $semanticResult = null;
        $contextResult = null;
        $scholarshipResult = null;
        $lineageResult = null;
        $aissSubmissionId = null;
        $capabilityVersion = null;
        $sourceHash = null;

        // Check if AISS integration is enabled for this tenant
        $settings = ate_get_settings($this->tenantId);
        $aissEnabled = ($settings['aiss_integration_enabled'] ?? '0') === '1';

        if (!$aissEnabled) {
            $warnings[] = 'AISS integration is disabled for this tenant (aiss_integration_enabled=0). Enable in Thesis Evaluation settings.';
            $maturityMetadata['aiss_integration'] = 'disabled_by_tenant';
        } else {
            try {
                $fileReference = (string)($manuscript['file_reference'] ?? '');
                if ($fileReference === '' || !is_file($fileReference)) {
                    return ['ok' => false, 'error' => 'Active manuscript file is unavailable; AISS analysis was not saved'];
                }

                $submitResult = $this->callCapability('academic_similarity.submit@1', [
                    '_tenant_id' => $this->tenantId,
                    'submission_title' => $case['title'],
                    'file_content' => base64_encode((string)file_get_contents($fileReference)),
                    'filename' => basename($fileReference),
                    'source_type' => 'upload',
                ]);

                if (empty($submitResult['ok']) || empty($submitResult['submission_id'])) {
                    return [
                        'ok' => false,
                        'error' => (string)($submitResult['error'] ?? 'AISS submission failed; no evidence snapshot was saved'),
                    ];
                }

                $aissSubmissionId = (int)$submitResult['submission_id'];
                $capabilityVersion = (string)($submitResult['capability_version'] ?? '1.0');
                $checkResult = $this->callCapability('academic_similarity.check@1', [
                    '_tenant_id' => $this->tenantId,
                    'submission_id' => $aissSubmissionId,
                    'external_text_processing_allowed' => false,
                ]);
                if (empty($checkResult['ok'])) {
                    return [
                        'ok' => false,
                        'error' => (string)($checkResult['error'] ?? 'AISS processing failed; no evidence snapshot was saved'),
                        'aiss_submission_id' => $aissSubmissionId,
                    ];
                }

                $reportResult = $this->callCapability('academic_similarity.report.view@1', [
                    '_tenant_id' => $this->tenantId,
                    'submission_id' => $aissSubmissionId,
                ]);
                if (empty($reportResult['ok'])) {
                    return [
                        'ok' => false,
                        'error' => (string)($reportResult['error'] ?? 'AISS report was unavailable; no evidence snapshot was saved'),
                        'aiss_submission_id' => $aissSubmissionId,
                    ];
                }

                $textualResult = $reportResult;
                $sourceHash = hash('sha256', (string)json_encode($textualResult));
                $maturityMetadata['textual_matching'] = 'stable';
                $internetCoverage = $reportResult['internet_coverage'] ?? null;
                if (is_array($internetCoverage)) {
                    $importedCount = (int)($internetCoverage['imported_count'] ?? 0);
                    $candidateCount = (int)($internetCoverage['candidate_count'] ?? 0);
                    $maturityMetadata['internet_coverage'] =
                        (($internetCoverage['status'] ?? '') === 'completed'
                            && $candidateCount > 0
                            && $importedCount === $candidateCount)
                            ? 'completed'
                            : ($importedCount > 0 ? 'partial' : 'incomplete');
                }

                $semanticMatches = array_values(array_filter(
                    is_array($reportResult['matches'] ?? null) ? $reportResult['matches'] : [],
                    static fn(array $match): bool => ($match['match_type'] ?? '') === 'semantic'
                ));
                if ($semanticMatches !== []) {
                    $semanticResult = ['ok' => true, 'matches' => $semanticMatches];
                    $maturityMetadata['semantic_resemblance'] = 'experimental';
                }

                try {
                    $contextResult = $this->callCapability('academic_similarity.context.analyze@1', [
                        '_tenant_id' => $this->tenantId,
                        'submission_id' => $aissSubmissionId,
                    ]);
                    $maturityMetadata['context_analysis'] = 'experimental';
                } catch (\Throwable $e) {
                    $warnings[] = 'Context analysis unavailable: ' . $e->getMessage();
                }

                try {
                    $scholarshipResult = $this->callCapability('academic_similarity.scholarship.profile@1', [
                        '_tenant_id' => $this->tenantId,
                        'submission_id' => $aissSubmissionId,
                    ]);
                } catch (\Throwable $e) {
                    $warnings[] = 'Scholarship profile unavailable: ' . $e->getMessage();
                }

                try {
                    $lineageResult = $this->callCapability('academic_similarity.lineage.graph@1', [
                        '_tenant_id' => $this->tenantId,
                        'submission_id' => $aissSubmissionId,
                        'format' => 'json',
                    ]);
                } catch (\Throwable $e) {
                    $warnings[] = 'Lineage graph unavailable: ' . $e->getMessage();
                }
            } catch (\Throwable $e) {
                return [
                    'ok' => false,
                    'error' => 'AISS capability call failed; no evidence snapshot was saved: ' . $e->getMessage(),
                    'aiss_submission_id' => $aissSubmissionId,
                ];
            }
        } // end if aissEnabled

        $snapshotId = $this->snapshotRepo->create([
            'evaluation_case_id' => $caseId,
            'manuscript_version_id' => $activeVersionId,
            'aiss_submission_id' => $aissSubmissionId,
            'capability_version' => $capabilityVersion,
            'evidence_version' => '1.0',
            'textual_result' => $textualResult,
            'citation_result' => $citationResult,
            'semantic_result' => $semanticResult,
            'context_result' => $contextResult,
            'scholarship_result' => $scholarshipResult,
            'lineage_result' => $lineageResult,
            'maturity_metadata' => $maturityMetadata,
            'capability_warnings' => $warnings,
            'generated_by' => $actorId,
            'source_hash' => $sourceHash,
        ]);

        $this->auditRepo->record([
            'case_id' => $caseId,
            'actor_id' => $actorId,
            'action' => 'aiss_snapshot_generated',
            'after_state' => ['snapshot_id' => $snapshotId, 'aiss_submission_id' => $aissSubmissionId],
        ]);

        $snapshot = $this->snapshotRepo->findById($snapshotId);
        return [
            'ok' => true,
            'data' => $snapshot,
            'warnings' => $warnings,
            'maturity' => $maturityMetadata,
        ];
    }

    private function callCapability(string $capabilityId, array $payload): array
    {
        if ($this->capabilityCaller !== null) {
            $result = ($this->capabilityCaller)($capabilityId, $payload);
        } else {
            $result = app()->cap()->call(
                $capabilityId,
                $payload,
                ['caller_module' => 'academic_thesis_evaluation']
            );
        }

        if (!is_array($result)) {
            throw new \RuntimeException("Capability {$capabilityId} returned an invalid response");
        }

        return $result;
    }
}
