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

    public function __construct(string $tenantId)
    {
        $this->tenantId = $tenantId;
        $this->caseRepo = new EvaluationCaseRepository($tenantId);
        $this->manuscriptRepo = new ManuscriptVersionRepository($tenantId);
        $this->snapshotRepo = new AissEvidenceSnapshotRepository($tenantId);
        $this->auditRepo = new AuditEventRepository($tenantId);
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

        // Attempt to access AISS via capability bus
        try {
            $caps = app()->capabilities();
            if (method_exists($caps, 'call')) {
                // 1. Submit to AISS
                $submitResult = $caps->call('academic_similarity.submit@1', [
                    '_tenant_id' => $this->tenantId,
                    'submission_title' => $case['title'],
                    'file_content' => base64_encode(
                        is_file($manuscript['file_reference'] ?? '') ? file_get_contents($manuscript['file_reference']) : ($manuscript['file_reference'] ?? '')
                    ),
                    'filename' => basename($manuscript['file_reference'] ?? 'manuscript.pdf'),
                    'source_type' => 'upload',
                ]);

                if (!empty($submitResult['ok']) && !empty($submitResult['data']['submission_id'])) {
                    $aissSubmissionId = (int)$submitResult['data']['submission_id'];
                    $capabilityVersion = $submitResult['data']['capability_version'] ?? '1.0';

                    // 2. Run similarity check
                    $caps->call('academic_similarity.check@1', [
                        '_tenant_id' => $this->tenantId,
                        'submission_id' => $aissSubmissionId,
                    ]);

                    // 3. Get report (textual matching)
                    $reportResult = $caps->call('academic_similarity.report.view@1', [
                        '_tenant_id' => $this->tenantId,
                        'submission_id' => $aissSubmissionId,
                    ]);

                    if (!empty($reportResult['ok'])) {
                        $textualResult = $reportResult['data'] ?? null;
                        $sourceHash = hash('sha256', json_encode($textualResult));
                        $maturityMetadata['textual_matching'] = 'stable';
                    }
                }

                // 4. Context analysis (experimental)
                try {
                    $contextResult = $caps->call('academic_similarity.context.analyze@1', [
                        '_tenant_id' => $this->tenantId,
                        'submission_id' => $aissSubmissionId,
                    ]);
                    $maturityMetadata['context_analysis'] = 'experimental';
                } catch (\Throwable $e) {
                    $warnings[] = 'Context analysis unavailable: ' . $e->getMessage();
                }

                // 5. Semantic comparison (experimental)
                try {
                    $semanticResult = $caps->call('academic_similarity.semantic.compare@1', [
                        '_tenant_id' => $this->tenantId,
                        'submission_id' => $aissSubmissionId,
                    ]);
                    $maturityMetadata['semantic_resemblance'] = 'experimental';
                } catch (\Throwable $e) {
                    $warnings[] = 'Semantic comparison unavailable: ' . $e->getMessage();
                }
            } else {
                $warnings[] = 'AISS capability bus not available — running in offline mode';
            }
        } catch (\Throwable $e) {
            $warnings[] = 'AISS capability bus not available: ' . $e->getMessage();
        }

        // Store snapshot (always, even with no AISS data)
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
}
