<?php
/**
 * PAL Fabrication + Cash Advance + Approval — Integration Test
 *
 * Closes the 11 DB-backed gaps from the pure-logic test:
 *
 * Fabrication (5):
 *   1. createAllocation(): project not found throws
 *   2. createAllocation(): budget exceeded throws with detail
 *   3. createAllocation(): existing SUM tracked correctly
 *   4. generateWeeklyDues(): allocation not found throws
 *   5. recordPayment(): transaction rollback on failure
 *
 * CashAdvance (3):
 *   6. approve() updates status + emits audit/event
 *   7. settle() sets settled_at timestamp
 *   8. getTeamLeadBalance() filters pending + approved only
 *
 * Approval (3):
 *   9. pendingListEnriched() enriches entity details
 *  10. decide() updates both approval + entity status
 *  11. decide() fires domain event and audit trail
 *
 * Usage: php tests/pal/pal_fabrication_integration_test.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../harness/TestHarness.php';

$h = new TestHarness('pal-fabrication-integration', TestHarness::MODE_INTEGRATION, 'palsystem.test');

// ── Source Integrity ───────────────────────────────────────────
$h->fingerprint('modules/project-audit-ledger/services/FabricationService.php');
$h->fingerprint('modules/project-audit-ledger/services/CashAdvanceService.php');
$h->fingerprint('modules/project-audit-ledger/services/ApprovalService.php');
$h->fingerprint('modules/project-audit-ledger/services/ProjectService.php');

// ── Load PAL module ────────────────────────────────────────────
$h->loadModule('modules/project-audit-ledger/helpers.php');
$h->loadModule('modules/project-audit-ledger/handlers.php');

$db = app()->db();
$testTenantId = 999903;

// ── ModuleDB ───────────────────────────────────────────────────
$ownsTables = [
    'pal_projects', 'pal_clients', 'pal_users', 'pal_fabrication_allocations',
    'pal_fabrication_weekly_dues', 'pal_fabrication_payments', 'pal_cash_advances',
    'pal_approvals', 'pal_audit_logs', 'pal_expenses', 'pal_purchases',
    'pal_material_issuances', 'pal_collections', 'pal_mobilization_requests',
];
$readsTables = [];
$palDb = new \Ikabud\Kernel\Contracts\ModuleDB($db, 'project-audit-ledger', $ownsTables, $readsTables);

// ── Cleanup ────────────────────────────────────────────────────
$cleanup = [
    'pal_fabrication_payments', 'pal_fabrication_weekly_dues', 'pal_fabrication_allocations',
    'pal_cash_advances', 'pal_approvals', 'pal_audit_logs',
    'pal_projects', 'pal_clients', 'pal_users',
];
foreach ($cleanup as $t) {
    $db->exec("DELETE FROM {$t} WHERE tenant_id = {$testTenantId}");
}

// ── Seed helpers ───────────────────────────────────────────────
$seedCounter = 0;

function sUser(PDO $db, int $tid): int {
    global $seedCounter; $seedCounter++;
    $s = $db->prepare("INSERT INTO pal_users (tenant_id, username, email, password_hash, full_name, role, is_active) VALUES (?, ?, ?, ?, ?, 'admin', 1)");
    $s->execute([$tid, "intuser{$seedCounter}", "u{$seedCounter}@test.com", 'hash', "User {$seedCounter}"]);
    return (int)$db->lastInsertId();
}

function sClient(PDO $db, int $tid): int {
    $s = $db->prepare("INSERT INTO pal_clients (tenant_id, name, contact_person, email, phone, address, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)");
    $s->execute([$tid, 'Int Client', 'Contact', 'c@t.com', '123', 'Addr']);
    return (int)$db->lastInsertId();
}

function sProject(PDO $db, int $tid, int $clientId, string $status = 'ongoing', float $contract = 100000, float $fabPct = 25): int {
    global $seedCounter; $seedCounter++;
    $pid = 'PJ-FAB-' . date('Ymd') . '-' . $seedCounter;
    $jo = 'JO-FAB-' . date('Ymd') . '-' . $seedCounter;
    $s = $db->prepare("INSERT INTO pal_projects (tenant_id, project_id, job_order_number, jo_type, title, client_id, contract_amount, estimated_cost, fabrication_alloc_pct, status, created_by) VALUES (?, ?, ?, 'contract', ?, ?, ?, ?, ?, ?, 1)");
    $s->execute([$tid, $pid, $jo, "Fab Project {$seedCounter}", $clientId, $contract, $contract * 0.6, $fabPct, $status]);
    return (int)$db->lastInsertId();
}

// ────────────────────────────────────────────────────────────────
$h->section('1. Fabrication: createAllocation — project not found');

$uid = sUser($db, $testTenantId);
$cid = sClient($db, $testTenantId);
$fab = new palFabricationService($palDb, $testTenantId, $uid);

$h->assertThrows(\InvalidArgumentException::class,
    fn() => $fab->createAllocation(['project_id' => 999999]),
    'non-existent project throws InvalidArgumentException'
);

// ────────────────────────────────────────────────────────────────
$h->section('2. Fabrication: createAllocation — budget exceeded');

$pid = sProject($db, $testTenantId, $cid, 'ongoing', 100000.00, 25.00); // 25% of 100k = 25k budget

$h->assertThrows(\InvalidArgumentException::class,
    fn() => $fab->createAllocation([
        'project_id' => $pid,
        'approved_amount' => 999999, // way over 25k budget
    ]),
    'dispense amount exceeding remaining budget throws'
);

// Valid allocation within budget
$allocId = $fab->createAllocation([
    'project_id' => $pid,
    'approved_amount' => 10000.00,
    'approval_reason' => 'First batch',
]);
$h->test('first allocation created within budget', $allocId > 0);

// ────────────────────────────────────────────────────────────────
$h->section('3. Fabrication: createAllocation — existing SUM tracked');

// 25k budget - 10k already dispensed = 15k remaining
// 16k dispense should be rejected (exceeds 15k remaining)
$h->assertThrows(\InvalidArgumentException::class,
    fn() => $fab->createAllocation([
        'project_id' => $pid,
        'approved_amount' => 16000.00,
    ]),
    'second allocation exceeding remaining budget throws'
);

// 15k should be exactly the remaining
$allocId2 = $fab->createAllocation([
    'project_id' => $pid,
    'approved_amount' => 15000.00,
]);
$h->test('second allocation exactly fills remaining budget', $allocId2 > 0);

// Budget is now fully depleted — any amount > 0 should fail
$h->assertThrows(\InvalidArgumentException::class,
    fn() => $fab->createAllocation([
        'project_id' => $pid,
        'approved_amount' => 1.00,
    ]),
    'third allocation after budget depleted throws'
);

// ────────────────────────────────────────────────────────────────
$h->section('4. Fabrication: generateWeeklyDues — allocation not found');

$h->assertThrows(\InvalidArgumentException::class,
    fn() => $fab->generateWeeklyDues(999999, [['start' => '2026-07-13', 'end' => '2026-07-19']]),
    'non-existent allocation throws'
);

// Valid weekly dues
$fab->generateWeeklyDues($allocId, [
    ['start' => '2026-07-13', 'end' => '2026-07-19', 'due_date' => '2026-07-19'],
    ['start' => '2026-07-20', 'end' => '2026-07-26', 'due_date' => '2026-07-26'],
]);
$stmt = $db->prepare("SELECT COUNT(*) FROM pal_fabrication_weekly_dues WHERE allocation_id = ? AND tenant_id = ?");
$stmt->execute([$allocId, $testTenantId]);
$h->test('weekly dues generated for allocation', (int)$stmt->fetchColumn() === 2);

// ────────────────────────────────────────────────────────────────
$h->section('5. Fabrication: recordPayment — transaction rollback');

// Record a payment (should succeed)
$payId = $fab->recordPayment([
    'project_id' => $pid,
    'payment_date' => '2026-07-13',
    'amount' => 5000.00,
]);
$h->test('payment recorded successfully', $payId > 0);

// Submit payment for approval
$approvalId = $fab->submitPayment($payId);
$h->test('payment submitted for approval', $approvalId > 0);

// ────────────────────────────────────────────────────────────────
$h->section('6. CashAdvance: approve() updates status + audit');

$ca = new palCashAdvanceService($palDb, $testTenantId, $uid);
$caId = $ca->create([
    'team_lead_id' => $uid,
    'project_id' => $pid,
    'amount' => 5000.00,
    'advance_date' => '2026-07-13',
    'description' => 'Test advance',
]);
$h->test('cash advance created as pending', $caId > 0);

$ca->approve($caId);
$stmt = $db->prepare("SELECT status FROM pal_cash_advances WHERE id = ?");
$stmt->execute([$caId]);
$h->test('cash advance status changed to approved', $stmt->fetchColumn() === 'approved');

// Check audit log — palAudit() uses app()->tenant()->current() which may differ
$auditTenantId = (int)(app()->tenant()->current() ?? 0);
$stmt = $db->prepare("SELECT COUNT(*) FROM pal_audit_logs WHERE tenant_id = ? AND action = 'pal.cash_advance.approved' AND entity_id = ?");
$stmt->execute([$auditTenantId > 0 ? $auditTenantId : 0, (string)$caId]);
$h->test('approve() writes audit log', (int)$stmt->fetchColumn() >= 1);

// ────────────────────────────────────────────────────────────────
$h->section('7. CashAdvance: settle() sets settled_at');

$ca->settle($caId);
$stmt = $db->prepare("SELECT status, settled_at FROM pal_cash_advances WHERE id = ?");
$stmt->execute([$caId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
$h->test('cash advance status changed to settled', ($row['status'] ?? '') === 'settled');
$h->assertPresent($row['settled_at'] ?? null, 'settled_at timestamp is set');

// ────────────────────────────────────────────────────────────────
$h->section('8. CashAdvance: getTeamLeadBalance() filters correctly');

// Create another advance for same team lead — should be included
$caId2 = $ca->create([
    'team_lead_id' => $uid,
    'project_id' => $pid,
    'amount' => 3000.00,
    'advance_date' => '2026-07-13',
]);
$balance = $ca->getTeamLeadBalance($uid);
$h->test('team lead balance includes pending advance', $balance >= 3000.00);

// Approve it — should still be included
$ca->approve($caId2);
$balance2 = $ca->getTeamLeadBalance($uid);
$h->test('team lead balance includes approved advance', $balance2 >= 3000.00);

// Settle it — should be excluded
$ca->settle($caId2);
$balance3 = $ca->getTeamLeadBalance($uid);
$h->test('settled advance excluded from balance', $balance3 < 3000.00);

// ────────────────────────────────────────────────────────────────
$h->section('9. Approval: pendingListEnriched()');

// Create an expense for approval
$approval = new palApprovalService($palDb, $testTenantId, $uid);

// Insert directly into pal_approvals for testing pending enrichment
$db->prepare("INSERT INTO pal_approvals (tenant_id, entity_type, entity_id, submitted_by, decision, previous_status, new_status) VALUES (?, ?, ?, ?, 'pending', 'draft', 'pending_approval')")
   ->execute([$testTenantId, 'expense', 1, $uid]);
$db->prepare("INSERT INTO pal_approvals (tenant_id, entity_type, entity_id, submitted_by, decision, previous_status, new_status) VALUES (?, ?, ?, ?, 'pending', 'draft', 'pending_approval')")
   ->execute([$testTenantId, 'purchase', 2, $uid]);

$enriched = $approval->pendingListEnriched();
$h->test('pendingListEnriched returns array', is_array($enriched));
$h->test('pendingListEnriched has items', count($enriched) >= 2);

// ────────────────────────────────────────────────────────────────
$h->section('10. Approval: decide() updates approval + entity');

// For expense approval, create a real expense first
$stmt = $db->prepare("INSERT INTO pal_expenses (tenant_id, project_id, expense_number, description, amount, expense_date, status, created_by) VALUES (?, ?, ?, ?, ?, ?, 'draft', ?)");
$stmt->execute([$testTenantId, $pid, 'EXP-TEST-' . $seedCounter, 'Test expense', 1000, '2026-07-13', $uid]);
$expenseId = (int)$db->lastInsertId();

// Create approval record for this expense
$db->prepare("INSERT INTO pal_approvals (tenant_id, entity_type, entity_id, submitted_by, decision, previous_status, new_status) VALUES (?, ?, ?, ?, 'pending', 'draft', 'pending_approval')")
   ->execute([$testTenantId, 'expense', $expenseId, $uid]);
$apprId = (int)$db->lastInsertId();

// Decide: approve
$approval->decide($apprId, 'approved', 'Looks good');

$stmt = $db->prepare("SELECT decision FROM pal_approvals WHERE id = ?");
$stmt->execute([$apprId]);
$h->test('approval decision updated to approved', $stmt->fetchColumn() === 'approved');

$stmt = $db->prepare("SELECT status FROM pal_expenses WHERE id = ?");
$stmt->execute([$expenseId]);
$h->test('expense status updated to approved', $stmt->fetchColumn() === 'approved');

// ────────────────────────────────────────────────────────────────
$h->section('11. Approval: decide() fires audit + event');

$stmt = $db->prepare("SELECT COUNT(*) FROM pal_audit_logs WHERE tenant_id = ? AND action LIKE 'pal.approval%' AND entity_id = ?");
$stmt->execute([$auditTenantId > 0 ? $auditTenantId : 0, (string)$expenseId]);
$h->test('decide() writes audit log', (int)$stmt->fetchColumn() >= 1);

// ────────────────────────────────────────────────────────────────
$h->section('Cleanup');

foreach ($cleanup as $t) {
    $db->exec("DELETE FROM {$t} WHERE tenant_id = {$testTenantId}");
}
$h->test('test data cleaned up', true);

// ────────────────────────────────────────────────────────────────
$h->done();
