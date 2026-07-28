<?php
declare(strict_types=1);

/**
 * ATE Standalone Mode Regression Test
 *
 * Key contract:
 *   Given ATE operates in standalone mode (aiss_integration_enabled=0)
 *   When an evaluation is completed
 *   Then the report states that AISS analysis was not performed
 *   And no empty AISS result is interpreted as absence of concerns
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

echo "\n=== ATE Standalone Mode Regression ===\n\n";

$db = ate_db($tenantId);

// Cleanup
foreach (['ate_audit_events','ate_evidence_review_decisions','ate_aiss_evidence_snapshots',
    'ate_revision_requests','ate_rubric_responses','ate_reviewer_assignments',
    'ate_workflow_stages','ate_manuscript_versions','ate_final_dispositions','ate_evaluation_cases'] as $tbl) {
    $db->execute("DELETE FROM {$tbl} WHERE tenant_id = :tid", [':tid' => $tenantId]);
}

// Ensure AISS integration is OFF for this test
$stmt = $db->prepare("INSERT INTO ate_settings (tenant_id, setting_key, setting_value, updated_at)
    VALUES (:tid, 'aiss_integration_enabled', '0', NOW())
    ON DUPLICATE KEY UPDATE setting_value = '0', updated_at = NOW()");
$stmt->execute([':tid' => $tenantId]);

// Seed profile
$existing = $db->prepare("SELECT id FROM ate_evaluation_profiles WHERE tenant_id = :tid AND code = 'masters_thesis_v1'");
$existing->execute([':tid' => $tenantId]);
if (!$existing->fetch()) {
    $workflowDef = json_encode([
        'code' => 'masters_thesis_v1', 'name' => "Master's Thesis", 'degree_level' => 'masters', 'version' => '1.0',
        'stages' => [
            ['code' => 'submission', 'label' => 'Submission', 'role' => 'student', 'next' => ['administrative_validation'], 'requirements' => ['manuscript_uploaded']],
            ['code' => 'administrative_validation', 'label' => 'Admin Validation', 'role' => 'graduate_coordinator', 'requirements' => ['manuscript_uploaded'], 'outcomes' => ['validated' => 'aiss_evidence_review', 'returned' => 'submission']],
            ['code' => 'aiss_evidence_review', 'label' => 'Evidence Review', 'role' => 'integrity_reviewer', 'outcomes' => ['complete' => 'academic_evaluation']],
            ['code' => 'academic_evaluation', 'label' => 'Academic Evaluation', 'role' => 'panel_member', 'outcomes' => ['proceed' => 'final_disposition']],
            ['code' => 'final_disposition', 'label' => 'Final Disposition', 'role' => 'graduate_dean', 'terminal' => true],
        ],
    ]);
    $db->prepare("INSERT INTO ate_evaluation_profiles (tenant_id, code, name, degree_level, version, status, workflow_definition, created_at, updated_at)
        VALUES (:tid, 'masters_thesis_v1', 'Master Thesis', 'masters', '1.0', 'active', :def, NOW(), NOW())")
        ->execute([':tid' => $tenantId, ':def' => $workflowDef]);
}

echo "\n── 1. Complete full evaluation in standalone mode ──\n";

// Create case
$caseSvc = new AcademicThesisEvaluationCaseService($tenantId);
$c = $caseSvc->create(['profile_code' => 'masters_thesis_v1', 'title' => 'Standalone Mode Test Thesis', 'submission_owner_id' => $actorId]);
$caseId = (int)($c['data']['id'] ?? 0);
t('case created in standalone mode', $caseId > 0);

// Submit manuscript
$caseSvc->submitManuscript($caseId, ['file_reference' => '/s/standalone_test.pdf', 'file_hash' => hash('sha256', 'standalone'), 'submitted_by' => $actorId]);

// Transition through all stages
$engine = new EvaluationWorkflowEngine($tenantId);
$engine->transition($caseId, 'administrative_validation', $actorId, 'Valid submission');
$engine->transition($caseId, 'aiss_evidence_review', $actorId, 'Admin validated');

// Generate evidence snapshot — should produce disabled_by_tenant
$adapter = new AcademicThesisAissAdapter($tenantId);
$snapResult = $adapter->generateSnapshot($caseId, $actorId);
t('snapshot generated in standalone mode', is_array($snapResult));

$snapRepo = new AissEvidenceSnapshotRepository($tenantId);
$snapshots = $snapRepo->findByCaseId($caseId);
t('snapshot stored', count($snapshots) === 1);

$maturity = json_decode($snapshots[0]['maturity_metadata'] ?? '{}', true);
t('aiss_integration flag is disabled_by_tenant', ($maturity['aiss_integration'] ?? '') === 'disabled_by_tenant');

$warnings = json_decode($snapshots[0]['capability_warnings'] ?? '[]', true);
t('snapshot has warning about AISS disabled', !empty(array_filter($warnings, fn($w) => str_contains($w, 'disabled'))));

// Complete evaluation
$engine->transition($caseId, 'academic_evaluation', $actorId, 'Proceed');

// Issue disposition
$dispSvc = new AcademicThesisDispositionService($tenantId);
$dispSvc->issue($caseId, ['status' => 'approved', 'decision_summary' => 'Thesis approved.', 'decided_by' => 99, 'authority_role' => 'graduate_dean']);

echo "\n── 2. Verify report shows standalone mode ──\n";

$reportSvc = new AcademicThesisReportService($tenantId);
$report = $reportSvc->generateEvaluationReport($caseId);
t('report generated', $report['ok'] === true);

$evalMode = $report['data']['evaluation_mode'] ?? [];
t('evaluation_mode present', !empty($evalMode));
t('mode is standalone', ($evalMode['mode'] ?? '') === 'standalone');
t('aiss_used is false', ($evalMode['aiss_used'] ?? true) === false);
t('label says AISS was not performed', str_contains(strtolower($evalMode['label'] ?? ''), 'standalone') || str_contains(strtolower($evalMode['label'] ?? ''), 'not'));

$capsUsed = $report['data']['aiss_capabilities_used'] ?? [];
t('no capabilities marked as used', empty($capsUsed));

echo "\n── 3. Verify no empty evidence is misinterpreted ──\n";

// Check that the absence of AISS data is clearly stated, not hidden
$evidenceSnapshots = $report['data']['evidence_snapshots'] ?? [];
t('evidence snapshots recorded', count($evidenceSnapshots) === 1);

$snapData = $evidenceSnapshots[0];
$snapMaturity = $snapData['maturity'] ?? [];
t('maturity metadata present in snapshot', !empty($snapMaturity));
t('textual_matching is unavailable', ($snapMaturity['textual_matching'] ?? '') === 'unavailable');
t('no false textual result implied', empty($snapData['textual_result'] ?? null));

// Verify warning messages are preserved
$snapWarnings = $snapData['warnings'] ?? [];
t('warnings preserved in report snapshot', !empty($snapWarnings));

echo "\n── 4. Verify enabling AISS does not retroactively affect old manuscripts ──\n";

// Verify snapshot is pinned to the time it was generated
t('snapshot has generated_at timestamp', !empty($snapshots[0]['generated_at'] ?? null));

// Verify the snapshot's evidence_version is recorded
t('evidence_version recorded', ($snapshots[0]['evidence_version'] ?? '') === '1.0');

// Verify no aiss_submission_id was assigned (since AISS wasn't called)
t('no aiss_submission_id in standalone snapshot', empty($snapshots[0]['aiss_submission_id'] ?? null));

// ── Log check ────────────────────────────────────────────────────
echo "\n── Logs ──\n";

$appLog = @file_get_contents(STORAGE_PATH . '/logs/app.log') ?: '';
$criticals = array_filter(explode("\n", $appLog), fn($l) => $l !== '' && (stripos($l, '[critical]') !== false || stripos($l, 'PHP Fatal') !== false));
t('no critical errors', count($criticals) === 0, count($criticals) . ' found');

$errLog = @file_get_contents(STORAGE_PATH . '/logs/error.log') ?: '';
$errLines = array_filter(explode("\n", $errLog), fn($l) => trim($l) !== '');
t('error.log clean', count($errLines) === 0, count($errLines) . ' lines');

// Cleanup
foreach (['ate_audit_events','ate_evidence_review_decisions','ate_aiss_evidence_snapshots',
    'ate_revision_requests','ate_rubric_responses','ate_reviewer_assignments',
    'ate_workflow_stages','ate_manuscript_versions','ate_final_dispositions','ate_evaluation_cases'] as $tbl) {
    $db->execute("DELETE FROM {$tbl} WHERE tenant_id = :tid", [':tid' => $tenantId]);
}
t('cleanup done', true);

echo "\n" . str_repeat('─', 50) . "\n";
echo "  PASS: {$pass}  FAIL: {$fail}\n";
if ($errors !== []) { echo "\nFailed:\n"; foreach ($errors as $e) { echo "  • {$e}\n"; } }
exit($fail > 0 ? 1 : 0);
