<?php
declare(strict_types=1);

/**
 * Academic Thesis Evaluation — Phase 1 integration tests.
 * Plain PHP test — run: php modules/academic_thesis_evaluation/tests/AcademicThesisEvaluationPhase1Test.php
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

@file_put_contents(STORAGE_PATH . '/logs/app.log', '');
@file_put_contents(STORAGE_PATH . '/logs/error.log', '');

$tenantId = 'aiss.test';
$actorId = 1;
$caseId = 0;
$versionId = 0;

echo "\n=== Academic Thesis Evaluation — Phase 1 ===\n\n";

$db = ate_db($tenantId);

// ── Cleanup from prior runs ──────────────────────────────────────
$db->execute("DELETE FROM ate_audit_events WHERE tenant_id = :tid", [':tid' => $tenantId]);
$db->execute("DELETE FROM ate_evidence_review_decisions WHERE tenant_id = :tid", [':tid' => $tenantId]);
$db->execute("DELETE FROM ate_aiss_evidence_snapshots WHERE tenant_id = :tid", [':tid' => $tenantId]);
$db->execute("DELETE FROM ate_revision_requests WHERE tenant_id = :tid", [':tid' => $tenantId]);
$db->execute("DELETE FROM ate_rubric_responses WHERE tenant_id = :tid", [':tid' => $tenantId]);
$db->execute("DELETE FROM ate_reviewer_assignments WHERE tenant_id = :tid", [':tid' => $tenantId]);
$db->execute("DELETE FROM ate_workflow_stages WHERE tenant_id = :tid", [':tid' => $tenantId]);
$db->execute("DELETE FROM ate_manuscript_versions WHERE tenant_id = :tid", [':tid' => $tenantId]);
$db->execute("DELETE FROM ate_final_dispositions WHERE tenant_id = :tid", [':tid' => $tenantId]);
$db->execute("DELETE FROM ate_evaluation_cases WHERE tenant_id = :tid", [':tid' => $tenantId]);

// ── Seed profile ─────────────────────────────────────────────────
$workflowDef = json_encode([
    'code' => 'masters_thesis_v1', 'name' => "Master's Thesis Evaluation",
    'degree_level' => 'masters', 'version' => '1.0',
    'stages' => [
        ['code' => 'submission', 'label' => 'Submission', 'role' => 'student', 'next' => ['administrative_validation'], 'requirements' => ['manuscript_uploaded']],
        ['code' => 'administrative_validation', 'label' => 'Admin Validation', 'role' => 'graduate_coordinator', 'requirements' => ['manuscript_uploaded'], 'outcomes' => ['validated' => 'aiss_evidence_review', 'returned' => 'submission', 'rejected' => 'final_disposition']],
        ['code' => 'aiss_evidence_review', 'label' => 'AISS Evidence Review', 'role' => 'integrity_reviewer', 'requirements' => ['evidence_snapshot_saved'], 'outcomes' => ['complete' => 'academic_evaluation']],
        ['code' => 'academic_evaluation', 'label' => 'Academic Evaluation', 'role' => 'panel_member', 'requirements' => ['rubric_submitted'], 'outcomes' => ['proceed' => 'revision', 'not_approved' => 'final_disposition']],
        ['code' => 'revision', 'label' => 'Revision', 'role' => 'student', 'requirements' => ['revised_manuscript_uploaded'], 'outcomes' => ['resubmit' => 'aiss_evidence_review', 'final_review' => 'final_disposition']],
        ['code' => 'final_disposition', 'label' => 'Final Disposition', 'role' => 'graduate_dean', 'terminal' => true],
    ],
]);

$existing = $db->prepare("SELECT id FROM ate_evaluation_profiles WHERE tenant_id = :tid AND code = 'masters_thesis_v1'");
$existing->execute([':tid' => $tenantId]);
if (!$existing->fetch()) {
    $db->prepare("INSERT INTO ate_evaluation_profiles (tenant_id, code, name, degree_level, version, status, workflow_definition, created_at, updated_at)
        VALUES (:tid, :code, :name, :level, :ver, 'active', :def, NOW(), NOW())")->execute([
        ':tid' => $tenantId, ':code' => 'masters_thesis_v1', ':name' => "Master's Thesis Evaluation",
        ':level' => 'masters', ':ver' => '1.0', ':def' => $workflowDef,
    ]);
}
t('profile seeded', true);

// ══════════════════════════════════════════════════════════════════
// 1. Create case
// ══════════════════════════════════════════════════════════════════
echo "\n── 1. Create Case ──\n";

$svc = new AcademicThesisEvaluationCaseService($tenantId);
$r = $svc->create([
    'profile_code' => 'masters_thesis_v1',
    'title' => 'ML in Thesis Evaluation',
    'submission_owner_id' => $actorId,
    'student_number' => 'TST-0001',
    'research_category' => 'Computer Science',
]);

t('case ok', $r['ok'] === true, $r['error'] ?? '');
t('case has id', ($r['data']['id'] ?? 0) > 0);
t('stage=submission', ($r['data']['current_stage'] ?? '') === 'submission');
t('status=submitted', ($r['data']['status'] ?? '') === 'submitted');
$caseId = (int)($r['data']['id'] ?? 0);

$stages = (new EvaluationStageRepository($tenantId))->findByCaseId($caseId);
t('stage instance exists', count($stages) === 1);

$audit = (new AuditEventRepository($tenantId))->findByCase($caseId);
t('audit recorded', count($audit) >= 1);
t('audit=case_created', ($audit[0]['action'] ?? '') === 'case_created');

// ══════════════════════════════════════════════════════════════════
// 2. Submit manuscript
// ══════════════════════════════════════════════════════════════════
echo "\n── 2. Submit Manuscript ──\n";

$r = $svc->submitManuscript($caseId, [
    'file_reference' => '/storage/test/manuscript_v1.pdf',
    'file_hash' => hash('sha256', 'test content v1'),
    'word_count' => 15000,
    'submitted_by' => $actorId,
]);
t('manuscript ok', $r['ok'] === true, $r['error'] ?? '');
t('version=1', ($r['data']['version_number'] ?? 0) === 1);
t('not revision', ($r['data']['is_revision'] ?? 1) === 0);
$versionId = (int)($r['data']['id'] ?? 0);

$case = (new EvaluationCaseRepository($tenantId))->findById($caseId);
t('active_ms set', ($case['active_manuscript_version_id'] ?? 0) == $versionId);

// ══════════════════════════════════════════════════════════════════
// 3. Stage transition
// ══════════════════════════════════════════════════════════════════
echo "\n── 3. Stage Transition ──\n";

$engine = new EvaluationWorkflowEngine($tenantId);
$tr = $engine->transition($caseId, 'administrative_validation', $actorId, 'Admin review');
t('transition ok', $tr->ok, $tr->error ?? '');
t('stage=admin_validation', $tr->stage === 'administrative_validation');

$case = (new EvaluationCaseRepository($tenantId))->findById($caseId);
t('case stage updated', ($case['current_stage'] ?? '') === 'administrative_validation');
t('case in_review', ($case['status'] ?? '') === 'in_review');

$stages = (new EvaluationStageRepository($tenantId))->findByCaseId($caseId);
t('2 stages', count($stages) === 2);
t('1st completed', ($stages[0]['status'] ?? '') === 'completed');
t('2nd active', ($stages[1]['status'] ?? '') === 'active');

// ══════════════════════════════════════════════════════════════════
// 4. Invalid transition
// ══════════════════════════════════════════════════════════════════
echo "\n── 4. Invalid Transition ──\n";

$bad = $engine->transition($caseId, 'academic_evaluation', $actorId, 'skip');
t('rejected', $bad->ok === false);
t('says Cannot transition', str_contains($bad->error ?? '', 'Cannot transition'));

// ══════════════════════════════════════════════════════════════════
// 5. Evidence snapshot
// ══════════════════════════════════════════════════════════════════
echo "\n── 5. Evidence Snapshot ──\n";

$adapter = new AcademicThesisAissAdapter($tenantId);
$snap = $adapter->generateSnapshot($caseId, $actorId);
t('snapshot returns array', is_array($snap));

$snapshots = (new AissEvidenceSnapshotRepository($tenantId))->findByCaseId($caseId);
t('snapshot stored', count($snapshots) >= 1);

// ══════════════════════════════════════════════════════════════════
// 6. Manuscript immutability
// ══════════════════════════════════════════════════════════════════
echo "\n── 6. Manuscript Immutability ──\n";

$r2 = $svc->submitManuscript($caseId, [
    'file_reference' => '/storage/test/manuscript_v2.pdf',
    'file_hash' => hash('sha256', 'revised v2'),
    'submitted_by' => $actorId,
]);
t('revision ok', $r2['ok'] === true, $r2['error'] ?? '');
t('version=2', ($r2['data']['version_number'] ?? 0) === 2);
t('is revision', ($r2['data']['is_revision'] ?? 0) === 1);

$msRepo = new ManuscriptVersionRepository($tenantId);
$v1 = $msRepo->findById($versionId);
t('v1 still accessible', $v1 !== null);
t('v1 file unchanged', ($v1['file_reference'] ?? '') === '/storage/test/manuscript_v1.pdf');
t('v1 not revision', ($v1['is_revision'] ?? 1) === 0);
t('2 versions total', count($msRepo->findByCaseId($caseId)) === 2);

// ══════════════════════════════════════════════════════════════════
// 7. Reviewer assignment
// ══════════════════════════════════════════════════════════════════
echo "\n── 7. Reviewer Assignment ──\n";

$revSvc = new AcademicThesisReviewerService($tenantId);
$a = $revSvc->assign($caseId, ['reviewer_id' => 2, 'reviewer_role' => 'panel_member']);
t('assigned', $a['ok'] === true, $a['error'] ?? '');
t('pending', ($a['data']['status'] ?? '') === 'pending');
$assignId = (int)($a['data']['id'] ?? 0);

$acc = $revSvc->accept($assignId, 2);
t('accepted', $acc['ok'] === true, $acc['error'] ?? '');

$assign = (new ReviewerAssignmentRepository($tenantId))->findById($assignId);
t('now accepted', ($assign['status'] ?? '') === 'accepted');

$badAcc = $revSvc->accept($assignId, 999);
t('wrong user blocked', $badAcc['ok'] === false);

// ══════════════════════════════════════════════════════════════════
// 8. Disposition authority
// ══════════════════════════════════════════════════════════════════
echo "\n── 8. Disposition Authority ──\n";

$disp = new AcademicThesisDispositionService($tenantId);
$badDisp = $disp->issue($caseId, ['status' => 'approved', 'decided_by' => $actorId, 'authority_role' => 'student']);
t('student blocked', $badDisp['ok'] === false);
t('not authorized', str_contains($badDisp['error'] ?? '', 'not authorized'));

$goodDisp = $disp->issue($caseId, ['status' => 'approved', 'decision_summary' => 'Meets requirements.', 'decided_by' => 99, 'authority_role' => 'graduate_dean']);
t('dean can issue', $goodDisp['ok'] === true, $goodDisp['error'] ?? '');

$dispRecord = (new FinalDispositionRepository($tenantId))->findByCaseId($caseId);
t('disposition stored', $dispRecord !== null);
t('role=graduate_dean', ($dispRecord['authority_role'] ?? '') === 'graduate_dean');

$dup = $disp->issue($caseId, ['status' => 'not_approved', 'decided_by' => 99, 'authority_role' => 'graduate_dean']);
t('duplicate blocked', $dup['ok'] === false);
t('already issued', str_contains($dup['error'] ?? '', 'already issued'));

// ══════════════════════════════════════════════════════════════════
// 9. Revision workflow (fresh case)
// ══════════════════════════════════════════════════════════════════
echo "\n── 9. Revision Workflow ──\n";

$c2 = $svc->create(['profile_code' => 'masters_thesis_v1', 'title' => 'Revision Test', 'submission_owner_id' => $actorId]);
$revCaseId = (int)($c2['data']['id'] ?? 0);
t('revision case created', $revCaseId > 0);

$svc->submitManuscript($revCaseId, ['file_reference' => '/s/r1.pdf', 'file_hash' => hash('sha256', 'r1'), 'submitted_by' => $actorId]);

$revReq = new AcademicThesisRevisionService($tenantId);
$rr = $revReq->createRequest($revCaseId, ['category' => 'citation', 'severity' => 'major', 'instruction' => 'Add citations.', 'created_by' => 2]);
t('revision request created', $rr['ok'] === true, $rr['error'] ?? '');
$revId = (int)($rr['data']['id'] ?? 0);
t('rev id > 0', $revId > 0);

$rev = (new RevisionRequestRepository($tenantId))->findById($revId);
t('category=citation', ($rev['category'] ?? '') === 'citation');
t('severity=major', ($rev['severity'] ?? '') === 'major');
t('status=open', ($rev['status'] ?? '') === 'open');

$r3 = $svc->submitManuscript($revCaseId, ['file_reference' => '/s/r2.pdf', 'file_hash' => hash('sha256', 'r2'), 'submitted_by' => $actorId]);
$newVid = (int)($r3['data']['id'] ?? 0);

$resolve = $revReq->resolve($revId, ['resolved_in_version_id' => $newVid, 'resolved_by' => $actorId]);
t('revision resolved', $resolve['ok'] === true, $resolve['error'] ?? '');

$resolved = (new RevisionRequestRepository($tenantId))->findById($revId);
t('now resolved', ($resolved['status'] ?? '') === 'resolved');
t('version linked', ($resolved['resolved_in_version_id'] ?? 0) == $newVid);

// ══════════════════════════════════════════════════════════════════
// 10. Unresolved blocks approval
// ══════════════════════════════════════════════════════════════════
echo "\n── 10. Unresolved Blocks Approval ──\n";

$c3 = $svc->create(['profile_code' => 'masters_thesis_v1', 'title' => 'Block Test', 'submission_owner_id' => $actorId]);
$blockCaseId = (int)($c3['data']['id'] ?? 0);
$svc->submitManuscript($blockCaseId, ['file_reference' => '/s/b1.pdf', 'file_hash' => hash('sha256', 'b1'), 'submitted_by' => $actorId]);
$revReq->createRequest($blockCaseId, ['category' => 'methodology', 'severity' => 'critical', 'instruction' => 'Rewrite methodology.', 'created_by' => 2]);

$unresolved = (new RevisionRequestRepository($tenantId))->countUnresolved($blockCaseId);
t('unresolved > 0', $unresolved > 0);

$blocked = $disp->issue($blockCaseId, ['status' => 'approved', 'decided_by' => 99, 'authority_role' => 'graduate_dean']);
t('approval blocked', $blocked['ok'] === false);
t('mentions unresolved', str_contains($blocked['error'] ?? '', 'unresolved revision'));

// ══════════════════════════════════════════════════════════════════
// 11. Cross-tenant isolation
// ══════════════════════════════════════════════════════════════════
echo "\n── 11. Cross-Tenant Isolation ──\n";

$other = null;
try {
    $other = (new EvaluationCaseRepository('other_tenant'))->findById($caseId);
} catch (\Throwable $e) {
    // Cross-tenant access may fail entirely (tables don't exist in other tenant's DB)
}
t('not visible cross-tenant', $other === null);

// ══════════════════════════════════════════════════════════════════
// 12. Report
// ══════════════════════════════════════════════════════════════════
echo "\n── 12. Report ──\n";

$report = (new AcademicThesisReportService($tenantId))->generateEvaluationReport($caseId);
t('report ok', $report['ok'] === true, $report['error'] ?? '');
t('has case', isset($report['data']['case']));
t('has stages', isset($report['data']['stages_completed']));
t('has manuscripts', isset($report['data']['manuscript_versions']));
t('has disposition', isset($report['data']['disposition']));
t('has audit', isset($report['data']['audit_trail']));

// ── Log check ────────────────────────────────────────────────────
echo "\n── Logs ──\n";

$appLog = @file_get_contents(STORAGE_PATH . '/logs/app.log') ?: '';
$errLog = @file_get_contents(STORAGE_PATH . '/logs/error.log') ?: '';

$criticals = array_filter(explode("\n", $appLog), fn($l) => $l !== '' && (stripos($l, '[critical]') !== false || stripos($l, 'PHP Fatal') !== false || stripos($l, 'PHP Parse') !== false));
t('no criticals in app.log', count($criticals) === 0, count($criticals) . ' found');

$errLines = array_filter(explode("\n", $errLog), fn($l) => trim($l) !== '');
t('error.log clean', count($errLines) === 0, count($errLines) . ' lines');

// ── Cleanup ──────────────────────────────────────────────────────
echo "\n── Cleanup ──\n";

foreach ([$caseId, $revCaseId, $blockCaseId] as $cid) {
    if ($cid <= 0) continue;
    $db->execute("DELETE FROM ate_audit_events WHERE case_id = :cid", [':cid' => $cid]);
    $db->execute("DELETE FROM ate_evidence_review_decisions WHERE evaluation_case_id = :cid", [':cid' => $cid]);
    $db->execute("DELETE FROM ate_aiss_evidence_snapshots WHERE evaluation_case_id = :cid", [':cid' => $cid]);
    $db->execute("DELETE FROM ate_revision_requests WHERE evaluation_case_id = :cid", [':cid' => $cid]);
    $db->execute("DELETE FROM ate_rubric_responses WHERE evaluation_case_id = :cid", [':cid' => $cid]);
    $db->execute("DELETE FROM ate_reviewer_assignments WHERE evaluation_case_id = :cid", [':cid' => $cid]);
    $db->execute("DELETE FROM ate_workflow_stages WHERE evaluation_case_id = :cid", [':cid' => $cid]);
    $db->execute("DELETE FROM ate_manuscript_versions WHERE evaluation_case_id = :cid", [':cid' => $cid]);
    $db->execute("DELETE FROM ate_final_dispositions WHERE evaluation_case_id = :cid", [':cid' => $cid]);
}
$db->execute("DELETE FROM ate_evaluation_cases WHERE tenant_id = :tid", [':tid' => $tenantId]);
t('cleanup done', true);

// ── Result ───────────────────────────────────────────────────────
echo "\n" . str_repeat('─', 50) . "\n";
echo "  PASS: {$pass}  FAIL: {$fail}\n";

if ($errors !== []) {
    echo "\nFailed:\n";
    foreach ($errors as $e) { echo "  • {$e}\n"; }
}

exit($fail > 0 ? 1 : 0);
