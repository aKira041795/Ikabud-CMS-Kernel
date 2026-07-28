<?php
declare(strict_types=1);

/**
 * Generates evaluation reports: reviewer reports, student revision reports, final evaluation reports.
 */
class AcademicThesisReportService
{
    private string $tenantId;

    public function __construct(string $tenantId)
    {
        $this->tenantId = $tenantId;
    }

    public function generateEvaluationReport(int $caseId): array
    {
        $caseRepo = new EvaluationCaseRepository($this->tenantId);
        $case = $caseRepo->findById($caseId);
        if (!$case) {
            return ['ok' => false, 'error' => 'Evaluation case not found'];
        }

        // Gather all data
        $stageRepo = new EvaluationStageRepository($this->tenantId);
        $stages = $stageRepo->findByCaseId($caseId);

        $manuscriptRepo = new ManuscriptVersionRepository($this->tenantId);
        $manuscripts = $manuscriptRepo->findByCaseId($caseId);

        $snapshotRepo = new AissEvidenceSnapshotRepository($this->tenantId);
        $snapshots = $snapshotRepo->findByCaseId($caseId);

        $decisionRepo = new EvidenceReviewDecisionRepository($this->tenantId);
        $decisions = $decisionRepo->findByCaseId($caseId);

        $rubricService = new AcademicThesisRubricService($this->tenantId);
        $rubricSummary = $rubricService->getSummary($caseId);

        $revisionRepo = new RevisionRequestRepository($this->tenantId);
        $revisions = $revisionRepo->findByCaseId($caseId);

        $dispositionRepo = new FinalDispositionRepository($this->tenantId);
        $disposition = $dispositionRepo->findByCaseId($caseId);

        $auditRepo = new AuditEventRepository($this->tenantId);
        $auditTrail = $auditRepo->findByCase($caseId);

        $report = [
            'case' => $case,
            'analysis_profile' => $this->buildAnalysisProfile($snapshots),
            'stages_completed' => array_map(function ($s) {
                return [
                    'stage' => $s['stage_code'],
                    'status' => $s['status'],
                    'outcome' => $s['outcome'],
                    'completed_at' => $s['completed_at'],
                ];
            }, $stages),
            'manuscript_versions' => array_map(function ($m) {
                return [
                    'version' => $m['version_number'],
                    'file_hash' => $m['file_hash'],
                    'submitted_at' => $m['created_at'],
                    'is_revision' => (bool)$m['is_revision'],
                ];
            }, $manuscripts),
            'evidence_snapshots' => array_map(function ($s) {
                return [
                    'snapshot_id' => $s['id'],
                    'version' => $s['evidence_version'],
                    'maturity' => json_decode($s['maturity_metadata'] ?? '{}', true),
                    'warnings' => json_decode($s['capability_warnings'] ?? '[]', true),
                    'generated_at' => $s['generated_at'],
                ];
            }, $snapshots),
            'evidence_decisions' => array_map(function ($d) {
                return [
                    'machine_relationship' => $d['machine_relationship'],
                    'reviewer_relationship' => $d['reviewer_relationship'],
                    'action' => $d['reviewer_action'],
                    'reason' => $d['reviewer_reason'],
                ];
            }, $decisions),
            'rubric_summary' => $rubricSummary['ok'] ? ($rubricSummary['data'] ?? []) : [],
            'revisions' => array_map(function ($r) {
                return [
                    'category' => $r['category'],
                    'severity' => $r['severity'],
                    'status' => $r['status'],
                    'instruction' => $r['instruction'],
                ];
            }, $revisions),
            'disposition' => $disposition ? [
                'status' => $disposition['status'],
                'summary' => $disposition['decision_summary'],
                'conditions' => $disposition['conditions'],
                'effective_date' => $disposition['effective_date'],
                'decided_by' => $disposition['decided_by'],
                'authority_role' => $disposition['authority_role'],
            ] : null,
            'audit_trail' => array_map(function ($a) {
                return [
                    'action' => $a['action'],
                    'actor_role' => $a['actor_role'],
                    'created_at' => $a['created_at'],
                ];
            }, $auditTrail),
        ];

        return ['ok' => true, 'data' => $report];
    }

    /**
     * Build a self-describing analysis profile — the authoritative provenance
     * record for how this evaluation was produced.
     *
     * Contract shape (schema_version 1.0):
     * {
     *   schema_version, engine: {id, version}, mode,
     *   extensions: { module_id: {enabled, version} },
     *   capabilities: { short_name: 'disabled' | {id, status, version} },
     *   label, reason?, generated_at
     * }
     */
    private function buildAnalysisProfile(array $snapshots): array
    {
        $base = [
            'schema_version' => '1.0',
            'engine' => [
                'id' => 'academic_thesis_evaluation',
                'version' => $this->getModuleVersion(),
            ],
            'mode' => 'standalone',
            'extensions' => [
                'academic_similarity' => ['enabled' => false, 'version' => null],
            ],
            'capabilities' => [
                'textual_matching' => 'disabled',
                'semantic_resemblance' => 'disabled',
                'citation_detection' => 'disabled',
                'context_analysis' => 'disabled',
            ],
            'label' => 'Standalone — No AISS analysis was performed',
            'generated_at' => date('c'),
        ];

        if (empty($snapshots)) {
            return $base;
        }

        $hasAissData = false;

        foreach ($snapshots as $s) {
            $maturity = json_decode($s['maturity_metadata'] ?? '{}', true);
            $aissIntegration = $maturity['aiss_integration'] ?? null;

            if ($aissIntegration === 'disabled_by_tenant') {
                $base['label'] = 'Standalone — AISS integration is disabled for this tenant';
                $base['reason'] = 'disabled_by_tenant';
                return $base;
            }

            if ($aissIntegration === 'standalone_mode') {
                $base['label'] = 'Standalone — AISS module is not available';
                $base['reason'] = 'standalone_mode';
                return $base;
            }

            // Populate capabilities from maturity metadata
            $capMap = [
                'textual_matching' => 'academic_similarity.textual.match@1',
                'semantic_resemblance' => 'academic_similarity.semantic.resemblance@1',
                'citation_detection' => 'academic_similarity.citation.analysis@1',
                'context_analysis' => 'academic_similarity.context.analysis@1',
            ];

            foreach ($capMap as $shortName => $capId) {
                $status = $maturity[$shortName] ?? 'unavailable';
                if (in_array($status, ['stable', 'beta', 'experimental'], true)) {
                    $base['capabilities'][$shortName] = [
                        'id' => $capId,
                        'status' => $status,
                        'version' => $s['capability_version'] ?? '1',
                    ];
                }
            }

            if (!empty($s['textual_result']) || !empty($s['aiss_submission_id'])) {
                $hasAissData = true;
            }
        }

        if ($hasAissData) {
            $base['mode'] = 'aiss_assisted';
            $base['extensions']['academic_similarity'] = [
                'enabled' => true,
                'version' => $this->getAissVersion(),
            ];
            $base['label'] = 'AISS-Assisted — Similarity and scholarship evidence was analyzed';
        }

        return $base;
    }

    private function getModuleVersion(): string
    {
        static $version = null;
        if ($version === null) {
            $json = @file_get_contents(__DIR__ . '/../../module.json');
            $data = $json ? json_decode($json, true) : [];
            $version = $data['version'] ?? '0.1.0';
        }
        return $version;
    }

    private function getAissVersion(): ?string
    {
        static $aissVersion = null;
        if ($aissVersion === null) {
            $json = @file_get_contents(__DIR__ . '/../../../academic_similarity/module.json');
            $data = $json ? json_decode($json, true) : [];
            $aissVersion = $data['version'] ?? null;
        }
        return $aissVersion;
    }
}
