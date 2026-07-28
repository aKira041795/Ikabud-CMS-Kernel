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
}
