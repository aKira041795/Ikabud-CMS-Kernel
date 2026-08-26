<?php
declare(strict_types=1);

/**
 * Academic Thesis Evaluation — deterministic evaluation-case report contract.
 *
 * Run: php modules/academic_thesis_evaluation/tests/AcademicThesisEvaluationReportContractTest.php
 */

require_once __DIR__ . '/../../../bootstrap.php';
require_once __DIR__ . '/../helpers.php';

$pass = 0;
$fail = 0;
$errors = [];

function t(string $label, bool $ok, string $detail = ''): void {
    global $pass, $fail, $errors;
    if ($ok) { $pass++; echo "  \033[32m✓\033[0m {$label}\n"; return; }
    $fail++; $errors[] = $label . ($detail !== '' ? ': ' . $detail : '');
    echo "  \033[31m✗\033[0m {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

function mustThrow(callable $fn, string $contains): bool {
    try {
        $fn();
    } catch (\Throwable $e) {
        return str_contains($e->getMessage(), $contains);
    }
    return false;
}

@file_put_contents(STORAGE_PATH . '/logs/app.log', '');
@file_put_contents(STORAGE_PATH . '/logs/error.log', '');

$tenantId = 'aiss.test';
$db = ate_db($tenantId);
$caseId = 0;
$profileId = 0;
$rubricTemplateId = 0;
$manuscriptIds = [];
$criteria = [];
$stageIds = [];
$snapshotIds = [];
$decisionIds = [];
$assignmentIds = [];
$responseIds = [];
$revisionIds = [];
$auditIds = [];
$suffix = 'report_contract_' . bin2hex(random_bytes(4));
$profileCode = $suffix . '_profile';
$rubricCode = $suffix . '_rubric';

echo "\n=== ATE Report Contract ===\n\n";

$deleteIds = static function (string $table, array $ids) use ($db, $tenantId): void {
    foreach ($ids as $id) {
        $db->execute("DELETE FROM {$table} WHERE id = :id AND tenant_id = :tid", [':id' => (int)$id, ':tid' => $tenantId]);
    }
};

$cleanup = static function () use ($db, $tenantId, &$caseId, &$profileId, &$rubricTemplateId, &$manuscriptIds, &$criteria, &$stageIds, &$snapshotIds, &$decisionIds, &$assignmentIds, &$responseIds, &$revisionIds, &$auditIds, $deleteIds): void {
    $deleteIds('ate_audit_events', $auditIds);
    $deleteIds('ate_evidence_review_decisions', $decisionIds);
    $deleteIds('ate_aiss_evidence_snapshots', $snapshotIds);
    $deleteIds('ate_revision_requests', $revisionIds);
    $deleteIds('ate_rubric_responses', $responseIds);
    $deleteIds('ate_reviewer_assignments', $assignmentIds);
    $deleteIds('ate_workflow_stages', $stageIds);
    $deleteIds('ate_manuscript_versions', $manuscriptIds);
    if ($caseId > 0) {
        $db->execute("DELETE FROM ate_evaluation_cases WHERE id = :id AND tenant_id = :tid", [':id' => $caseId, ':tid' => $tenantId]);
    }
    foreach ($criteria as $criterionId) {
        $db->execute("DELETE FROM ate_rubric_criteria WHERE id = :id", [':id' => (int)$criterionId]);
    }
    if ($rubricTemplateId > 0) {
        $db->execute("DELETE FROM ate_rubric_templates WHERE id = :id AND tenant_id = :tid", [':id' => $rubricTemplateId, ':tid' => $tenantId]);
    }
    if ($profileId > 0) {
        $db->execute("DELETE FROM ate_evaluation_profiles WHERE id = :id AND tenant_id = :tid", [':id' => $profileId, ':tid' => $tenantId]);
    }
};

$workflow = json_encode(['code' => $profileCode, 'stages' => []], JSON_THROW_ON_ERROR);
$stmt = $db->prepare("INSERT INTO ate_evaluation_profiles (tenant_id, code, name, degree_level, version, status, workflow_definition, created_at, updated_at)
    VALUES (:tid, :code, 'Report Contract', 'masters', '1.0', 'active', :def, '2026-01-01 00:00:00', '2026-01-01 00:00:00')");
$stmt->execute([':tid' => $tenantId, ':code' => $profileCode, ':def' => $workflow]);
$profileId = (int)$db->lastInsertId();

$stmt = $db->prepare("INSERT INTO ate_evaluation_cases (tenant_id, profile_id, submission_owner_id, title, current_stage, status, submitted_at, created_at, updated_at)
    VALUES (:tid, :profile, 501, 'Deterministic Report Contract', 'submission', 'submitted', '2026-01-02 00:00:00', '2026-01-02 00:00:00', '2026-01-02 00:00:00')");
$stmt->execute([':tid' => $tenantId, ':profile' => $profileId]);
$caseId = (int)$db->lastInsertId();

foreach ([[2, 'hash-v2', '2026-01-04 00:00:00'], [1, 'hash-v1', '2026-01-03 00:00:00']] as [$version, $hash, $created]) {
    $stmt = $db->prepare("INSERT INTO ate_manuscript_versions (tenant_id, evaluation_case_id, version_number, file_reference, file_hash, submitted_by, is_revision, created_at)
        VALUES (:tid, :cid, :version, :ref, :hash, 501, :rev, :created)");
    $stmt->execute([
        ':tid' => $tenantId,
        ':cid' => $caseId,
        ':version' => $version,
        ':ref' => '/tmp/report-contract-v' . $version . '.pdf',
        ':hash' => $hash,
        ':rev' => $version === 2 ? 1 : 0,
        ':created' => $created,
    ]);
    $manuscriptIds[$version] = (int)$db->lastInsertId();
}
$db->execute("UPDATE ate_evaluation_cases SET active_manuscript_version_id = :mid WHERE id = :cid AND tenant_id = :tid", [
    ':mid' => $manuscriptIds[2],
    ':cid' => $caseId,
    ':tid' => $tenantId,
]);

foreach ([
    ['administrative_validation', 'completed', 'validated', '2026-01-07 00:00:00'],
    ['submission', 'completed', 'submitted', '2026-01-06 00:00:00'],
] as [$stage, $status, $outcome, $created]) {
    $stmt = $db->prepare("INSERT INTO ate_workflow_stages (tenant_id, evaluation_case_id, stage_code, stage_order, status, assigned_role, outcome, created_at, updated_at)
        VALUES (:tid, :cid, :stage, 1, :status, 'graduate_coordinator', :outcome, :created, :updated)");
    $stmt->execute([':tid' => $tenantId, ':cid' => $caseId, ':stage' => $stage, ':status' => $status, ':outcome' => $outcome, ':created' => $created, ':updated' => $created]);
    $stageIds[] = (int)$db->lastInsertId();
}

$disabledMaturity = ['aiss_integration' => 'disabled_by_tenant', 'textual_matching' => 'unavailable'];
$aissMaturity = [
    'textual_matching' => 'stable',
    'semantic_resemblance' => 'beta',
    'citation_detection' => 'experimental',
    'context_analysis' => 'unavailable',
];
$stmt = $db->prepare("INSERT INTO ate_aiss_evidence_snapshots (tenant_id, evaluation_case_id, manuscript_version_id, aiss_submission_id, capability_version, evidence_version, textual_result, maturity_metadata, capability_warnings, generated_at, generated_by, source_hash)
    VALUES (:tid, :cid, :mid, NULL, 'old', '1.0', NULL, :maturity, :warnings, '2026-01-08 00:00:00', 1, 'src-old')");
$stmt->execute([':tid' => $tenantId, ':cid' => $caseId, ':mid' => $manuscriptIds[1], ':maturity' => json_encode($disabledMaturity, JSON_THROW_ON_ERROR), ':warnings' => json_encode(['disabled'], JSON_THROW_ON_ERROR)]);
$oldSnapshotId = (int)$db->lastInsertId();
$snapshotIds[] = $oldSnapshotId;

$stmt = $db->prepare("INSERT INTO ate_aiss_evidence_snapshots (tenant_id, evaluation_case_id, manuscript_version_id, aiss_submission_id, capability_version, evidence_version, textual_result, maturity_metadata, capability_warnings, generated_at, generated_by, source_hash)
    VALUES (:tid, :cid, :mid, 99, '2', '1.1', :textual, :maturity, :warnings, '2026-01-09 00:00:00', 1, 'src-new')");
$stmt->execute([
    ':tid' => $tenantId,
    ':cid' => $caseId,
    ':mid' => $manuscriptIds[2],
    ':textual' => json_encode(['raw_score' => 1.5], JSON_THROW_ON_ERROR),
    ':maturity' => json_encode($aissMaturity, JSON_THROW_ON_ERROR),
    ':warnings' => json_encode([], JSON_THROW_ON_ERROR),
]);
$newSnapshotId = (int)$db->lastInsertId();
$snapshotIds[] = $newSnapshotId;

foreach ([[$newSnapshotId, 'later', 'confirm'], [$oldSnapshotId, 'earlier', 'acknowledge']] as [$snapshotId, $machine, $action]) {
    $stmt = $db->prepare("INSERT INTO ate_evidence_review_decisions (tenant_id, evaluation_case_id, evidence_snapshot_id, machine_relationship, reviewer_relationship, reviewer_action, reviewer_reason, reviewer_id, confirmed_at)
        VALUES (:tid, :cid, :sid, :machine, 'reviewed', :action, 'reason', 11, NOW())");
    $stmt->execute([':tid' => $tenantId, ':cid' => $caseId, ':sid' => $snapshotId, ':machine' => $machine, ':action' => $action]);
    $decisionIds[] = (int)$db->lastInsertId();
}

$stmt = $db->prepare("INSERT INTO ate_rubric_templates (tenant_id, code, name, version, degree_level, status, total_weight, created_by, created_at, updated_at)
    VALUES (:tid, :code, 'Report Contract Rubric', '1.0', 'masters', 'active', 100, 1, NOW(), NOW())");
$stmt->execute([':tid' => $tenantId, ':code' => $rubricCode]);
$rubricTemplateId = (int)$db->lastInsertId();
foreach ([['b', 'Beta', 60], ['a', 'Alpha', 40]] as [$code, $label, $weight]) {
    $stmt = $db->prepare("INSERT INTO ate_rubric_criteria (rubric_template_id, code, label, weight, sort_order)
        VALUES (:rid, :code, :label, :weight, :sort)");
    $stmt->execute([':rid' => $rubricTemplateId, ':code' => $code, ':label' => $label, ':weight' => $weight, ':sort' => $weight]);
    $criteria[$code] = (int)$db->lastInsertId();
}
foreach ([[10, 'panel_member'], [2, 'panel_member'], [1, 'adviser']] as [$reviewerId, $role]) {
    $stmt = $db->prepare("INSERT INTO ate_reviewer_assignments (tenant_id, evaluation_case_id, reviewer_id, reviewer_role, assignment_type, status, assigned_at)
        VALUES (:tid, :cid, :reviewer, :role, 'primary', 'completed', NOW())");
    $stmt->execute([':tid' => $tenantId, ':cid' => $caseId, ':reviewer' => $reviewerId, ':role' => $role]);
    $assignmentId = (int)$db->lastInsertId();
    $assignmentIds[] = $assignmentId;
    foreach ([['b', 88.0, 'second'], ['a', 92.0, 'first']] as [$code, $score, $comment]) {
        $stmt = $db->prepare("INSERT INTO ate_rubric_responses (tenant_id, evaluation_case_id, manuscript_version_id, reviewer_assignment_id, criterion_id, score, comment, created_at, updated_at)
            VALUES (:tid, :cid, :mid, :assignment, :criterion, :score, :comment, NOW(), NOW())");
        $stmt->execute([':tid' => $tenantId, ':cid' => $caseId, ':mid' => $manuscriptIds[2], ':assignment' => $assignmentId, ':criterion' => $criteria[$code], ':score' => $score, ':comment' => $comment]);
        $responseIds[] = (int)$db->lastInsertId();
    }
}

foreach ([['major', 'open', '2026-01-11 00:00:00'], ['minor', 'resolved', '2026-01-10 00:00:00']] as [$severity, $status, $created]) {
    $stmt = $db->prepare("INSERT INTO ate_revision_requests (tenant_id, evaluation_case_id, category, severity, instruction, status, created_by, created_at, updated_at)
        VALUES (:tid, :cid, 'methodology', :severity, 'Revise methodology', :status, 1, :created, :updated)");
    $stmt->execute([':tid' => $tenantId, ':cid' => $caseId, ':severity' => $severity, ':status' => $status, ':created' => $created, ':updated' => $created]);
    $revisionIds[] = (int)$db->lastInsertId();
}

foreach ([['later_action', '2026-01-13 00:00:00'], ['earlier_action', '2026-01-12 00:00:00']] as [$action, $created]) {
    $stmt = $db->prepare("INSERT INTO ate_audit_events (tenant_id, case_id, actor_id, actor_role, action, created_at)
        VALUES (:tid, :cid, 1, 'admin', :action, :created)");
    $stmt->execute([':tid' => $tenantId, ':cid' => $caseId, ':action' => $action, ':created' => $created]);
    $auditIds[] = (int)$db->lastInsertId();
}

$service = new AcademicThesisReportService($tenantId);
$first = $service->generateEvaluationReport($caseId);
$second = $service->generateEvaluationReport($caseId);
t('report ok', ($first['ok'] ?? false) === true, $first['error'] ?? '');
t('repeated payloads are identical', $first === $second);

$data = $first['data'] ?? [];
t('report schema id/version present', ($data['report_schema']['id'] ?? '') === 'academic_thesis_evaluation.evaluation_case_report' && ($data['report_schema']['version'] ?? '') === '1.0');
t('analysis engine records module version 0.2.0', ($data['analysis_profile']['engine']['version'] ?? '') === '0.2.0');
t('latest AISS data controls mode', ($data['analysis_profile']['mode'] ?? '') === 'aiss_assisted');
t('historical disabled snapshot does not override AISS evidence', empty($data['analysis_profile']['reason'] ?? null));
t('capabilities come from latest AISS-data snapshot', ($data['analysis_profile']['capabilities']['textual_matching']['version'] ?? '') === '2');
t('generated_at defaults to latest snapshot as UTC', ($data['analysis_profile']['generated_at'] ?? '') === '2026-01-09T00:00:00+00:00');
t('manuscripts sorted by version then id', array_column($data['manuscript_versions'] ?? [], 'version') === [1, 2]);
t('snapshots sorted ascending', array_column($data['evidence_snapshots'] ?? [], 'snapshot_id') === [$oldSnapshotId, $newSnapshotId]);
t('stages sorted by created_at then id', array_column($data['stages_completed'] ?? [], 'stage') === ['submission', 'administrative_validation']);
t('revisions sorted by created_at then id', array_column($data['revisions'] ?? [], 'status') === ['resolved', 'open']);
t('audit trail sorted by created_at then id', array_column($data['audit_trail'] ?? [], 'action') === ['earlier_action', 'later_action']);
t('decisions sorted by id, not confirmed_at', array_column($data['evidence_decisions'] ?? [], 'machine_relationship') === ['later', 'earlier']);
t('reviewers sorted 1,2,10', array_column($data['rubric_summary']['reviewer_summaries'] ?? [], 'reviewer_id') === [1, 2, 10]);
t('rubric scores sorted by criterion', array_column($data['rubric_summary']['reviewer_summaries'][0]['scores'] ?? [], 'criterion') === ['Alpha', 'Beta']);

$digest = $data['content_digest'] ?? [];
$recomputed = AcademicThesisReportService::contentDigestForData($data);
t('digest recomputes after removing content_digest', $digest === $recomputed, json_encode([$digest, $recomputed]));
$mutated = $data;
$mutated['case']['title'] = 'Changed';
t('digest changes when included data changes', AcademicThesisReportService::contentDigestForData($mutated)['value'] !== ($digest['value'] ?? ''));

$precisionBefore = ini_get('serialize_precision');
ini_set('serialize_precision', '17');
$canonicalOne = AcademicThesisReportService::canonicalJson(['z' => 1, 'a' => ['float' => 1.5, 'b' => true]]);
ini_set('serialize_precision', '-1');
$canonicalTwo = AcademicThesisReportService::canonicalJson(['a' => ['b' => true, 'float' => 1.5], 'z' => 1]);
ini_set('serialize_precision', (string)$precisionBefore);
t('canonical bytes sort associative keys and ignore serialize_precision drift', $canonicalOne === $canonicalTwo);

$providerA = (new AcademicThesisReportService($tenantId, static fn(array $case, array $snapshots): string => '2026-01-09T08:00:00+08:00'))->generateEvaluationReport($caseId);
$providerB = (new AcademicThesisReportService($tenantId, static fn(array $case, array $snapshots): string => '2026-01-09T00:00:00Z'))->generateEvaluationReport($caseId);
t('equivalent provider offsets normalize identically', ($providerA['data']['analysis_profile']['generated_at'] ?? '') === ($providerB['data']['analysis_profile']['generated_at'] ?? ''));
t('malformed provider timestamp fails', mustThrow(static fn() => (new AcademicThesisReportService($tenantId, static fn(): string => '2026-01-09 00:00:00'))->generateEvaluationReport($caseId), 'invalid timestamp format'));

$notFound = $service->generateEvaluationReport(999999999);
t('not-found response preserved', $notFound === ['ok' => false, 'error' => 'Evaluation case not found']);

$ref = new \ReflectionClass(AcademicThesisReportService::class);
$sortRows = $ref->getMethod('sortRows');
$sortRows->setAccessible(true);
t('synthetic NULL timestamp fails', mustThrow(static fn() => $sortRows->invoke($service, [['id' => 1, 'generated_at' => null]], [
    ['field' => 'generated_at', 'type' => 'timestamp', 'nullable' => false],
    ['field' => 'id', 'type' => 'number', 'nullable' => false],
], 'synthetic_snapshots'), 'generated_at'));
t('synthetic invalid timestamp fails', mustThrow(static fn() => $sortRows->invoke($service, [['id' => 1, 'generated_at' => '2026-13-99 00:00:00']], [
    ['field' => 'generated_at', 'type' => 'timestamp', 'nullable' => false],
    ['field' => 'id', 'type' => 'number', 'nullable' => false],
], 'synthetic_snapshots'), 'non-calendar'));
t('synthetic missing id fails', mustThrow(static fn() => $sortRows->invoke($service, [['generated_at' => '2026-01-01 00:00:00']], [
    ['field' => 'generated_at', 'type' => 'timestamp', 'nullable' => false],
    ['field' => 'id', 'type' => 'number', 'nullable' => false],
], 'synthetic_snapshots'), 'Missing synthetic_snapshots.id'));

$manifest = json_decode((string)file_get_contents(__DIR__ . '/../module.json'), true);
$exposed = array_column($manifest['capabilities']['exposes'] ?? [], 'id');
$handlers = academic_thesis_evaluation_capability_handlers();
t('manifest exposes no report capability', !in_array('academic_thesis_evaluation.report.generate@1', $exposed, true));
t('handler map exposes no report capability', !array_key_exists('academic_thesis_evaluation.report.generate@1', $handlers));

$routes = require __DIR__ . '/../routes.php';
$handlerSrc = (string)file_get_contents(__DIR__ . '/../handlers.php');
t('admin report route remains the v1 endpoint', ($routes['GET']['/api/v1/thesis-evaluation/cases/{id}/report'] ?? '') === 'academic-thesis-evaluation:apiGetReport');
t('report handler still uses ate_require_admin', str_contains($handlerSrc, 'function apiGetReport') && str_contains($handlerSrc, 'ate_require_admin($ctx)'));

$schema = json_decode((string)file_get_contents(__DIR__ . '/../docs/evaluation_case_report-1.0.schema.json'), true);
t('schema document declares draft-07', ($schema['$schema'] ?? '') === 'http://json-schema.org/draft-07/schema#');

$cleanup();

echo "\n" . str_repeat('─', 50) . "\n";
echo "  PASS: {$pass}  FAIL: {$fail}\n";
if ($errors !== []) {
    echo "\nFailed:\n";
    foreach ($errors as $e) { echo "  • {$e}\n"; }
}

exit($fail > 0 ? 1 : 0);
