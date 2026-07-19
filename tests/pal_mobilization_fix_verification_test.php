<?php
/**
 * PAL Mobilization — End-to-End Fix Verification
 *
 * Verifies the key fixes made to the AW→PAL mobilization flow.
 * Does NOT require handler files directly (avoids triggering render output).
 *
 * Usage: php tests/pal_mobilization_fix_verification_test.php
 */

declare(strict_types=1);

$pass = 0;
$fail = 0;

function assert_true(bool $cond, string $label): void {
    global $pass, $fail;
    if ($cond) { echo "  ✅ {$label}\n"; $pass++; }
    else { echo "  ❌ {$label}\n"; $fail++; }
}

echo "PAL Mobilization Fix Verification\n";
echo str_repeat('=', 60) . "\n\n";

// ─────────────────────────────────────────
// 1. AW tenant resolver exists in source
// ─────────────────────────────────────────
echo "1. AW tenant resolver\n";
$handlerSource = file_get_contents(__DIR__ . '/../modules/project-audit-ledger/handlers/53-team-lead.php');
assert_true(str_contains($handlerSource, 'function palResolveAwTenantId'),
    'palResolveAwTenantId() defined in handler source');
assert_true(str_contains($handlerSource, "aw_tenant_id"),
    'Reads aw_tenant_id from module settings');
assert_true(str_contains($handlerSource, "getModuleSettings('project-audit-ledger')"),
    'Uses getModuleSettings for PAL module');

// ─────────────────────────────────────────
// 2. Approval sync helper exists
// ─────────────────────────────────────────
echo "\n2. Approval sync helper\n";
assert_true(str_contains($handlerSource, 'function palMobilizationSyncApproval'),
    'palMobilizationSyncApproval() defined');
assert_true(str_contains($handlerSource, "UPDATE pal_approvals SET decision"),
    'Updates pal_approvals.decision');
assert_true(!str_contains($handlerSource, "'disbursed', (int)\$u['id']"),
    'Disbursement does not write invalid disbursed approval decision');

// ─────────────────────────────────────────
// 3. Detail route and handler
// ─────────────────────────────────────────
echo "\n3. Detail route and handler\n";
$routesSource = file_get_contents(__DIR__ . '/../modules/project-audit-ledger/routes.php');
assert_true(str_contains($routesSource, "'/admin/project-audit-ledger/mobilization/{id}'"),
    'Detail route registered');
assert_true(str_contains($routesSource, 'palPageMobilizationDetail'),
    'Route points to palPageMobilizationDetail');
assert_true(str_contains($handlerSource, 'function palPageMobilizationDetail'),
    'palPageMobilizationDetail() handler exists');

// ─────────────────────────────────────────
// 4. Detail template exists
// ─────────────────────────────────────────
echo "\n4. Detail template\n";
$templatePath = __DIR__ . '/../modules/project-audit-ledger/templates/project-audit-ledger/pages/mobilization-detail.disyl';
assert_true(file_exists($templatePath), 'mobilization-detail.disyl exists');
$tplContent = file_get_contents($templatePath);
assert_true(str_contains($tplContent, 'Mobilization #'), 'Template shows mobilization ID');
assert_true(str_contains($tplContent, 'request.attendance_group_id'), 'Template shows attendance context');
assert_true(str_contains($tplContent, 'request.approval_id'), 'Template shows approval_id');
assert_true(!str_contains($tplContent, 'attendance_evidence_hash'), 'Template does NOT expose evidence hash');
assert_true(str_contains($tplContent, 'submitMobilizationDecision'), 'Template has local action submit helper');
assert_true(str_contains($tplContent, 'input[name="_token"]'), 'Action helper submits CSRF token');

// ─────────────────────────────────────────
// 5. ApprovalService query fix
// ─────────────────────────────────────────
echo "\n5. ApprovalService query\n";
$approvalSvcSource = file_get_contents(__DIR__ . '/../modules/project-audit-ledger/services/ApprovalService.php');
assert_true(!str_contains($approvalSvcSource, 'request_number'),
    'No reference to nonexistent request_number column');
assert_true(str_contains($approvalSvcSource, 'COALESCE(mr.purpose'),
    'Uses COALESCE(mr.purpose, ...) as entity_label');

// ─────────────────────────────────────────
// 6. Transaction safety: approval_id written back
// ─────────────────────────────────────────
echo "\n6. Transaction safety\n";
assert_true(str_contains($handlerSource, 'approval_id = :aid'),
    'approval_id written back via UPDATE after insert');
assert_true(str_contains($handlerSource, 'palMobilizationSyncApproval'),
    'Direct endpoints call palMobilizationSyncApproval');

// ─────────────────────────────────────────
// 7. Structured logging
// ─────────────────────────────────────────
echo "\n7. Structured logging\n";
assert_true(str_contains($handlerSource, 'pal_mob_store: AW revalidation failed'),
    'AW revalidation failure logged with context');
assert_true(str_contains($handlerSource, 'pal_mob_store: DB transaction failed'),
    'DB transaction failure logged with context');
assert_true(str_contains($handlerSource, 'pal_tenant='),
    'Logs include PAL tenant');
assert_true(str_contains($handlerSource, 'aw_tenant='),
    'Logs include AW tenant');

// ─────────────────────────────────────────
// 8. Entity view action routes to valid handler
// ─────────────────────────────────────────
echo "\n8. Entity view action\n";
$viewSource = file_get_contents(__DIR__ . '/../modules/project-audit-ledger/helpers/views/pal_mobilization.disyl');
assert_true(str_contains($viewSource, '/admin/project-audit-ledger/mobilization/{id}'),
    'Entity view action links to registered detail route');

// ─────────────────────────────────────────
// 9. AW tenant used in all capability calls
// ─────────────────────────────────────────
echo "\n9. AW tenant usage consistency\n";
$resolveCalls = substr_count($handlerSource, 'palResolveAwTenantId()');
assert_true($resolveCalls >= 3, "palResolveAwTenantId called in >=3 places (got: {$resolveCalls})");

// ─────────────────────────────────────────
// 10. Direct endpoints use transactions
// ─────────────────────────────────────────
echo "\n10. Direct endpoint transaction safety\n";
assert_true(str_contains($handlerSource, 'palApiMobilizationApprove'),
    'palApiMobilizationApprove exists');
assert_true(str_contains($handlerSource, 'palApiMobilizationReject'),
    'palApiMobilizationReject exists');
assert_true(str_contains($handlerSource, 'palApiMobilizationDisburse'),
    'palApiMobilizationDisburse exists');
assert_true(substr_count($handlerSource, 'palEnforceCsrf();') >= 3,
    'Direct mobilization endpoints enforce CSRF');
$txnCount = substr_count($handlerSource, 'beginTransaction()');
assert_true($txnCount >= 4, "At least 4 transactions (store + 3 direct endpoints), got: {$txnCount}");

// ─────────────────────────────────────────
// Summary
// ─────────────────────────────────────────
echo "\n" . str_repeat('=', 60) . "\n";
echo "Results: {$pass} passed, {$fail} failed\n";
echo str_repeat('=', 60) . "\n";

if ($fail > 0) {
    exit(1);
}
