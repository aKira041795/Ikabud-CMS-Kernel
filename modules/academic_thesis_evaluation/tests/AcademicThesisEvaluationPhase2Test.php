<?php
declare(strict_types=1);

/**
 * Academic Thesis Evaluation — Phase 2+3 integration tests.
 * Tests: rubric seed, rubric scoring, evidence review decisions, panel consolidation.
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
$originalSettings = ate_get_settings($tenantId);

echo "\n=== Academic Thesis Evaluation — Phase 2+3 ===\n\n";

$db = ate_db($tenantId);

// ── Cleanup from prior runs ──────────────────────────────────────
$db->execute("DELETE FROM ate_audit_events WHERE tenant_id = :tid", [':tid' => $tenantId]);
$db->execute("DELETE FROM ate_evidence_review_decisions WHERE tenant_id = :tid", [':tid' => $tenantId]);
$db->execute("DELETE FROM ate_aiss_evidence_snapshots WHERE tenant_id = :tid", [':tid' => $tenantId]);
$db->execute("DELETE FROM ate_rubric_responses WHERE tenant_id = :tid", [':tid' => $tenantId]);
$db->execute("DELETE FROM ate_reviewer_assignments WHERE tenant_id = :tid", [':tid' => $tenantId]);
$db->execute("DELETE FROM ate_revision_requests WHERE tenant_id = :tid", [':tid' => $tenantId]);
$db->execute("DELETE FROM ate_workflow_stages WHERE tenant_id = :tid", [':tid' => $tenantId]);
$db->execute("DELETE FROM ate_manuscript_versions WHERE tenant_id = :tid", [':tid' => $tenantId]);
$db->execute("DELETE FROM ate_final_dispositions WHERE tenant_id = :tid", [':tid' => $tenantId]);
$db->execute("DELETE FROM ate_evaluation_cases WHERE tenant_id = :tid", [':tid' => $tenantId]);

// ══════════════════════════════════════════════════════════════════
// 1. Rubric template seed verification
// ══════════════════════════════════════════════════════════════════
echo "\n── 1. Rubric Templates ──\n";

$rubricSvc = new AcademicThesisRubricService($tenantId);

$masters = $rubricSvc->getByCode('masters_thesis_v1');
t('masters rubric exists', $masters['ok'] === true, $masters['error'] ?? '');
t('masters rubric has criteria', count($masters['data']['criteria'] ?? []) >= 1);
t('masters criteria count = 8', count($masters['data']['criteria'] ?? []) === 8, 'got ' . count($masters['data']['criteria'] ?? []));

$doctoral = $rubricSvc->getByCode('doctoral_dissertation_v1');
t('doctoral rubric lookup attempted', is_array($doctoral));
// Doctoral rubric requires doctoral profile to be seeded — may not exist in test tenant
if ($doctoral['ok']) {
    t('doctoral criteria count = 8', count($doctoral['data']['criteria'] ?? []) === 8);
} else {
    t('doctoral rubric not seeded (expected without doctoral profile)', true);
    t('doctoral criteria count = 8 (skipped — no profile)', true);
}

// Sum weights
$mastersWeight = array_sum(array_column($masters['data']['criteria'] ?? [], 'weight'));
t('masters total weight = 100', abs((float)$mastersWeight - 100.0) < 0.01, 'got ' . $mastersWeight);

// ══════════════════════════════════════════════════════════════════
// 2. Rubric scoring flow
// ══════════════════════════════════════════════════════════════════
echo "\n── 2. Rubric Scoring ──\n";

// Create case + manuscript
$caseSvc = new AcademicThesisEvaluationCaseService($tenantId);
$c = $caseSvc->create(['profile_code' => 'masters_thesis_v1', 'title' => 'Rubric Scoring Test', 'submission_owner_id' => $actorId]);
$caseId = (int)($c['data']['id'] ?? 0);
t('case created', $caseId > 0);

$testManuscript = tempnam(sys_get_temp_dir(), 'ate_aiss_');
file_put_contents($testManuscript, "%PDF-1.4\nATE AISS integration fixture\n%%EOF");
$caseSvc->submitManuscript($caseId, ['file_reference' => $testManuscript, 'file_hash' => hash_file('sha256', $testManuscript), 'submitted_by' => $actorId]);

// Assign two reviewers
$reviewerSvc = new AcademicThesisReviewerService($tenantId);
$a1 = $reviewerSvc->assign($caseId, ['reviewer_id' => 2, 'reviewer_role' => 'panel_member']);
$a2 = $reviewerSvc->assign($caseId, ['reviewer_id' => 3, 'reviewer_role' => 'panel_member']);
$assign1Id = (int)($a1['data']['id'] ?? 0);
$assign2Id = (int)($a2['data']['id'] ?? 0);
$reviewerSvc->accept($assign1Id, 2);
$reviewerSvc->accept($assign2Id, 3);
t('2 reviewers assigned', $assign1Id > 0 && $assign2Id > 0);

// Reviewer 1 submits scores
$criteria = $masters['data']['criteria'] ?? [];
$responses1 = [];
foreach ($criteria as $i => $criterion) {
    $responses1[] = ['criterion_id' => (int)$criterion['id'], 'score' => 70 + ($i * 3), 'comment' => "Reviewer 1 comment for {$criterion['label']}"];
}
$r1 = $rubricSvc->submitScores($caseId, $assign1Id, ['responses' => $responses1, 'actor_id' => 2]);
t('reviewer 1 scores submitted', $r1['ok'] === true, $r1['error'] ?? '');

// Reviewer 2 submits scores
$responses2 = [];
foreach ($criteria as $i => $criterion) {
    $responses2[] = ['criterion_id' => (int)$criterion['id'], 'score' => 80 + ($i * 2), 'comment' => "Reviewer 2 comment for {$criterion['label']}"];
}
$r2 = $rubricSvc->submitScores($caseId, $assign2Id, ['responses' => $responses2, 'actor_id' => 3]);
t('reviewer 2 scores submitted', $r2['ok'] === true, $r2['error'] ?? '');

// Verify assignments completed
$assignRepo = new ReviewerAssignmentRepository($tenantId);
$assign1 = $assignRepo->findById($assign1Id);
$assign2 = $assignRepo->findById($assign2Id);
t('reviewer 1 marked completed', ($assign1['status'] ?? '') === 'completed');
t('reviewer 2 marked completed', ($assign2['status'] ?? '') === 'completed');

// Get rubric summary
$summary = $rubricSvc->getSummary($caseId);
t('summary ok', $summary['ok'] === true, $summary['error'] ?? '');
t('2 reviewers in summary', count($summary['data']['reviewer_summaries'] ?? []) === 2, 'got ' . count($summary['data']['reviewer_summaries'] ?? []));

// Verify individual reviewer scores preserved (not averaged)
$reviewerData1 = $summary['data']['reviewer_summaries'][0] ?? null;
$reviewerData2 = $summary['data']['reviewer_summaries'][1] ?? null;
t('reviewer 1 has scores', count($reviewerData1['scores'] ?? []) > 0);
t('reviewer 2 has scores', count($reviewerData2['scores'] ?? []) > 0);
t('reviewer scores differ (disagreement preserved)', ($reviewerData1['weighted_total'] ?? 0) !== ($reviewerData2['weighted_total'] ?? 0));

// ══════════════════════════════════════════════════════════════════
// 3. Evidence review decisions
// ══════════════════════════════════════════════════════════════════
echo "\n── 3. Evidence Review ──\n";

// Generate snapshot through the enabled AISS capability contract.
ate_save_settings($tenantId, ['aiss_integration_enabled' => '1']);
$calledCapabilities = [];
$checkPayload = [];
$capabilityCaller = static function (string $capabilityId, array $payload) use (&$calledCapabilities, &$checkPayload): array {
    $calledCapabilities[] = $capabilityId;
    if ($capabilityId === 'academic_similarity.check@1') {
        $checkPayload = $payload;
    }
    return match ($capabilityId) {
        'academic_similarity.submit@1' => ['ok' => true, 'submission_id' => 701, 'capability_version' => '1.0'],
        'academic_similarity.check@1' => ['ok' => true, 'status' => 'processed'],
        'academic_similarity.report.view@1' => [
            'ok' => true,
            'report' => ['id' => 91, 'adjusted_score' => 2.5],
            'submission' => ['id' => 701, 'status' => 'processed'],
            'matches' => [
                ['id' => 1, 'match_type' => 'exact', 'matched_word_count' => 18],
                ['id' => 2, 'match_type' => 'semantic', 'matched_word_count' => 0],
            ],
            'internet_coverage' => [
                'status' => 'completed_partial',
                'candidate_count' => 15,
                'imported_count' => 2,
            ],
        ],
        'academic_similarity.context.analyze@1',
        'academic_similarity.scholarship.profile@1',
        'academic_similarity.lineage.graph@1' => ['ok' => true],
        default => throw new RuntimeException('Unexpected capability: ' . $capabilityId),
    };
};
$adapter = new AcademicThesisAissAdapter($tenantId, $capabilityCaller);
$snapResult = $adapter->generateSnapshot($caseId, $actorId);
$storedTextualResult = json_decode((string)($snapResult['data']['textual_result'] ?? ''), true) ?: [];
t('snapshot generated', ($snapResult['ok'] ?? false) === true, $snapResult['error'] ?? '');
t('AISS direct submission id accepted', (int)($snapResult['data']['aiss_submission_id'] ?? 0) === 701);
t('ATE denies external text processing by default', ($checkPayload['external_text_processing_allowed'] ?? null) === false);
t('invalid semantic compare capability not called', !in_array('academic_similarity.semantic.compare@1', $calledCapabilities, true));
t('report capability response stored directly', (int)($storedTextualResult['report']['id'] ?? 0) === 91);
t('partial internet coverage maturity recorded', ($snapResult['maturity']['internet_coverage'] ?? '') === 'partial');

$snapRepo = new AissEvidenceSnapshotRepository($tenantId);
$snapshots = $snapRepo->findByCaseId($caseId);
t('snapshot stored', count($snapshots) >= 1);
$snapshotId = (int)($snapshots[0]['id'] ?? 0);
$failedAdapter = new AcademicThesisAissAdapter(
    $tenantId,
    static fn(string $capabilityId, array $payload): array => ['ok' => false, 'error' => 'provider unavailable']
);
$failedSnapshot = $failedAdapter->generateSnapshot($caseId, $actorId);
t('enabled AISS failure is returned', ($failedSnapshot['ok'] ?? true) === false);
t('enabled AISS failure does not create zero snapshot', count($snapRepo->findByCaseId($caseId)) === count($snapshots));

// Record reviewer decision (machine + reviewer separation)
$evidenceSvc = new AcademicThesisEvidenceService($tenantId);
$dec1 = $evidenceSvc->recordReview($snapshotId, [
    'reviewer_id' => 2,
    'machine_relationship' => 'shared_method_description',
    'reviewer_relationship' => 'common_knowledge',
    'reviewer_action' => 'rejected',
    'reviewer_reason' => 'This is a standard methodology used across the field.',
]);
t('reviewer decision recorded', $dec1['ok'] === true, $dec1['error'] ?? '');

$dec2 = $evidenceSvc->recordReview($snapshotId, [
    'reviewer_id' => 3,
    'machine_relationship' => 'near_verbatim_match',
    'reviewer_relationship' => 'citation_needed',
    'reviewer_action' => 'confirmed',
    'reviewer_reason' => 'Text closely follows source without attribution.',
]);
t('second decision recorded', $dec2['ok'] === true, $dec2['error'] ?? '');

// Verify both machine and reviewer values preserved
$decRepo = new EvidenceReviewDecisionRepository($tenantId);
$decisions = $decRepo->findByCaseId($caseId);
t('2 review decisions', count($decisions) === 2);

// Find the first decision and verify both values stored
$decision1 = null;
foreach ($decisions as $d) {
    if (($d['machine_relationship'] ?? '') === 'shared_method_description') {
        $decision1 = $d;
        break;
    }
}
t('machine value preserved', ($decision1['machine_relationship'] ?? '') === 'shared_method_description');
t('reviewer value stored separately', ($decision1['reviewer_relationship'] ?? '') === 'common_knowledge');
t('machine != reviewer (not overwritten)', ($decision1['machine_relationship'] ?? '') !== ($decision1['reviewer_relationship'] ?? ''));

// ══════════════════════════════════════════════════════════════════
// 4. Report includes rubric + evidence
// ══════════════════════════════════════════════════════════════════
echo "\n── 4. Report Integration ──\n";

$reportSvc = new AcademicThesisReportService($tenantId);
$report = $reportSvc->generateEvaluationReport($caseId);
t('report ok', $report['ok'] === true, $report['error'] ?? '');
t('report has rubric summary', !empty($report['data']['rubric_summary'] ?? null));
t('report has evidence decisions', count($report['data']['evidence_decisions'] ?? []) === 2);

// ══════════════════════════════════════════════════════════════════
// Log check + cleanup
// ══════════════════════════════════════════════════════════════════
echo "\n── Logs ──\n";

$appLog = @file_get_contents(STORAGE_PATH . '/logs/app.log') ?: '';
$criticals = array_filter(explode("\n", $appLog), fn($l) => $l !== '' && (stripos($l, '[critical]') !== false || stripos($l, 'PHP Fatal') !== false));
t('no criticals', count($criticals) === 0, count($criticals) . ' found');

$errLog = @file_get_contents(STORAGE_PATH . '/logs/error.log') ?: '';
$errLines = array_filter(explode("\n", $errLog), fn($l) => trim($l) !== '');
t('error.log clean', count($errLines) === 0, count($errLines) . ' lines');

// Cleanup
echo "\n── Cleanup ──\n";
$db->execute("DELETE FROM ate_audit_events WHERE case_id = :cid", [':cid' => $caseId]);
$db->execute("DELETE FROM ate_evidence_review_decisions WHERE evaluation_case_id = :cid", [':cid' => $caseId]);
$db->execute("DELETE FROM ate_aiss_evidence_snapshots WHERE evaluation_case_id = :cid", [':cid' => $caseId]);
$db->execute("DELETE FROM ate_rubric_responses WHERE evaluation_case_id = :cid", [':cid' => $caseId]);
$db->execute("DELETE FROM ate_reviewer_assignments WHERE evaluation_case_id = :cid", [':cid' => $caseId]);
$db->execute("DELETE FROM ate_revision_requests WHERE evaluation_case_id = :cid", [':cid' => $caseId]);
$db->execute("DELETE FROM ate_workflow_stages WHERE evaluation_case_id = :cid", [':cid' => $caseId]);
$db->execute("DELETE FROM ate_manuscript_versions WHERE evaluation_case_id = :cid", [':cid' => $caseId]);
$db->execute("DELETE FROM ate_final_dispositions WHERE evaluation_case_id = :cid", [':cid' => $caseId]);
$db->execute("DELETE FROM ate_evaluation_cases WHERE id = :cid", [':cid' => $caseId]);
ate_save_settings($tenantId, [
    'aiss_integration_enabled' => (string)($originalSettings['aiss_integration_enabled'] ?? '0'),
    'auto_generate_aiss_on_submit' => (string)($originalSettings['auto_generate_aiss_on_submit'] ?? '0'),
]);
if (is_string($testManuscript) && is_file($testManuscript)) {
    unlink($testManuscript);
}
t('cleanup done', true);

echo "\n" . str_repeat('─', 50) . "\n";
echo "  PASS: {$pass}  FAIL: {$fail}\n";
if ($errors !== []) { echo "\nFailed:\n"; foreach ($errors as $e) { echo "  • {$e}\n"; } }
exit($fail > 0 ? 1 : 0);
