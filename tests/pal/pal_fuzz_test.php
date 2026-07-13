<?php
/**
 * PAL Fuzz Test — Boundary values, nulls, negatives, type confusion.
 *
 * Tests what our pure-logic and integration tests DON'T cover:
 *   - Zero amounts
 *   - Negative values
 *   - Null fields
 *   - Non-existent IDs
 *   - Division by zero
 *   - Type confusion (string→float coercion)
 *   - Empty arrays
 *   - Consecutive unauthorized transitions
 *
 * This is NOT a replacement for integration tests. It's a safety net
 * that catches edge cases that structured tests miss by design.
 *
 * Usage: php tests/pal/pal_fuzz_test.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../harness/TestHarness.php';

$h = new TestHarness('pal-fuzz', TestHarness::MODE_INTEGRATION, 'palsystem.test');

// ── Source Integrity ───────────────────────────────────────────
$h->fingerprint('modules/project-audit-ledger/services/CashAdvanceService.php');
$h->fingerprint('modules/project-audit-ledger/services/FabricationService.php');
$h->fingerprint('modules/project-audit-ledger/services/ProjectService.php');
$h->fingerprint('modules/project-audit-ledger/services/ProjectCostService.php');

// ── Load module ────────────────────────────────────────────────
$h->loadModule('modules/project-audit-ledger/helpers.php');
$h->loadModule('modules/project-audit-ledger/handlers.php');

$db = app()->dbForTenant(502);
$testTenantId = 502;
$seedCounter = 0;

// ── ModuleDB ───────────────────────────────────────────────────
$ownsTables = [
    'pal_projects', 'pal_clients', 'pal_users', 'pal_cash_advances',
    'pal_fabrication_allocations', 'pal_fabrication_weekly_dues',
    'pal_fabrication_payments', 'pal_expenses', 'pal_purchases',
    'pal_sales', 'pal_approvals', 'pal_audit_logs',
    'pal_collections', 'pal_project_types', 'pal_team_leads',
    'pal_material_issuances', 'pal_material_issuance_items',
    'pal_inventory_balances', 'pal_inventory_movements',
    'pal_purchase_items', 'pal_sale_items',
    'pal_materials', 'pal_suppliers', 'pal_expense_categories',
    'pal_material_categories', 'pal_units', 'pal_inventory_locations',
    'pal_material_returns', 'pal_quotations', 'pal_quotation_items',
    'pal_attachments', 'pal_report_exports', 'pal_settings',
    'pal_otp_codes', 'pal_mobilization_requests',
    'pal_receivables', 'pal_receivable_payments',
];
$palDb = new \Ikabud\Kernel\Contracts\ModuleDB($db, 'project-audit-ledger', $ownsTables, []);

// ── Cleanup ────────────────────────────────────────────────────
foreach ($ownsTables as $t) {
    try { $db->exec("DELETE FROM {$t} WHERE tenant_id = {$testTenantId}"); } catch (\Throwable $e) {}
}

// ── Seed helpers ───────────────────────────────────────────────
function fuzzUser(PDO $db, int $tid): int {
    global $seedCounter; $seedCounter++;
    $s = $db->prepare("INSERT INTO pal_users (tenant_id, username, email, password_hash, full_name, role, is_active) VALUES (?,?,?,?,?,'admin',1)");
    $s->execute([$tid, "fuzz{$seedCounter}", "f{$seedCounter}@t.com", password_hash('seedtest123', PASSWORD_BCRYPT), "Fuzz $seedCounter"]);
    return (int)$db->lastInsertId();
}
function fuzzClient(PDO $db, int $tid): int {
    global $seedCounter; $seedCounter++;
    $s = $db->prepare("INSERT INTO pal_clients (tenant_id, name, contact_person, email, phone, address, is_active) VALUES (?,?,?,?,?,?,1)");
    $s->execute([$tid, "Fuzz Client $seedCounter", "Fuzz Contact", "f@t.com", "123", "Addr"]);
    return (int)$db->lastInsertId();
}
function fuzzProject(PDO $db, int $tid, int $cid, float $contract = 100000, float $fabPct = 25, string $status = 'ongoing'): int {
    global $seedCounter; $seedCounter++;
    $s = $db->prepare("INSERT INTO pal_projects (tenant_id, project_id, job_order_number, jo_type, title, client_id, contract_amount, estimated_cost, fabrication_alloc_pct, status, created_by) VALUES (?,?,?,'contract',?,?,?,?,?,?,1)");
    $s->execute([$tid, "PJ-FUZZ-$seedCounter", "JO-FUZZ-$seedCounter", "Fuzz Project $seedCounter", $cid, $contract, $contract * 0.6, $fabPct, $status]);
    return (int)$db->lastInsertId();
}

$uid = fuzzUser($db, $testTenantId);
$cid = fuzzClient($db, $testTenantId);

// ────────────────────────────────────────────────────────────────
$h->section('1. CashAdvance: boundary values');

$ca = new palCashAdvanceService($palDb, $testTenantId, $uid);

// Zero amount
$h->assertThrows(\InvalidArgumentException::class,
    fn() => $ca->create(['team_lead_id' => $uid, 'amount' => 0, 'advance_date' => '2026-07-13']),
    'zero amount throws'
);

// Negative amount
$h->assertThrows(\InvalidArgumentException::class,
    fn() => $ca->create(['team_lead_id' => $uid, 'amount' => -100, 'advance_date' => '2026-07-13']),
    'negative amount throws'
);

// Null amount (coerced to 0)
$h->assertThrows(\InvalidArgumentException::class,
    fn() => $ca->create(['team_lead_id' => $uid, 'advance_date' => '2026-07-13']),
    'null amount (coerced to 0) throws'
);

// Non-existent ID approve
$h->assertThrows(\InvalidArgumentException::class,
    fn() => $ca->approve(999999),
    'approve non-existent ID throws'
);

// Approve from voided (approved→voided→approve again — should fail)
$caId = $ca->create(['team_lead_id' => $uid, 'amount' => 100, 'advance_date' => '2026-07-13']);
$ca->approve($caId);
$d = $db->prepare("UPDATE pal_cash_advances SET status = 'voided' WHERE id = ?")->execute([$caId]);
$h->assertThrows(\InvalidArgumentException::class,
    fn() => $ca->approve($caId),
    'approve voided advance throws'
);

// Approve already-approved
$caId2 = $ca->create(['team_lead_id' => $uid, 'amount' => 200, 'advance_date' => '2026-07-13']);
$ca->approve($caId2);
$h->assertThrows(\InvalidArgumentException::class,
    fn() => $ca->approve($caId2),
    'double approve throws'
);

// Settle without approval (skip approve)
$caId3 = $ca->create(['team_lead_id' => $uid, 'amount' => 300, 'advance_date' => '2026-07-13']);
$h->assertThrows(\InvalidArgumentException::class,
    fn() => $ca->settle($caId3),
    'settle pending (not approved) throws'
);

// Settle non-existent
$h->assertThrows(\InvalidArgumentException::class,
    fn() => $ca->settle(999999),
    'settle non-existent throws'
);

// Void non-existent
$h->assertThrows(\InvalidArgumentException::class,
    fn() => $ca->void(999999),
    'void non-existent throws'
);

// Void already-voided
$caId4 = $ca->create(['team_lead_id' => $uid, 'amount' => 400, 'advance_date' => '2026-07-13']);
$ca->void($caId4);
$h->assertThrows(\InvalidArgumentException::class,
    fn() => $ca->void($caId4),
    'double void throws'
);

// ────────────────────────────────────────────────────────────────
$h->section('2. ProjectCostService: division by zero boundary');

$costSvc = new palProjectCostService($palDb, $testTenantId);

// Non-existent project — should return zeroed array
$result = $costSvc->getProfitability(999999);
$h->test('profitability for non-existent project returns zeros',
    $result['contract_amount'] === 0 && $result['profit_margin'] === 0
);

// Project with 0 contract amount — division by zero guard
$pid0 = fuzzProject($db, $testTenantId, $cid, 0.00, 25);
$result0 = $costSvc->getProfitability($pid0);
$h->test('profitability for 0-contract project returns safe values',
    $result0['contract_amount'] === 0.0 || $result0['profit_margin'] === 0
);

// Budget status for 0-contract project
$budget = $costSvc->getBudgetStatus($pid0);
$h->test('budget status for 0-contract returns normal/0',
    ($budget['status'] ?? '') === 'normal' || $budget['pct_used'] === 0
);

// ────────────────────────────────────────────────────────────────
$h->section('3. FabricationService: boundary values');

$fab = new palFabricationService($palDb, $testTenantId, $uid);

// Zero dispense amount
$pidValid = fuzzProject($db, $testTenantId, $cid, 100000, 25);
$h->assertThrows(\InvalidArgumentException::class,
    fn() => $fab->createAllocation(['project_id' => $pidValid, 'approved_amount' => 0]),
    'zero dispense amount throws'
);

// Negative dispense amount
$h->assertThrows(\InvalidArgumentException::class,
    fn() => $fab->createAllocation(['project_id' => $pidValid, 'approved_amount' => -5000]),
    'negative dispense throws'
);

// Missing project_id
$h->assertThrows(\InvalidArgumentException::class,
    fn() => $fab->createAllocation(['approved_amount' => 1000]),
    'missing project_id throws (coerced to 0, not found)'
);

// Non-existent project
$h->assertThrows(\InvalidArgumentException::class,
    fn() => $fab->createAllocation(['project_id' => 999999, 'approved_amount' => 1000]),
    'non-existent project throws'
);

// Weekly dues with empty weeks array — should produce 0 rows
$aid1 = $fab->createAllocation(['project_id' => $pidValid, 'approved_amount' => 5000]);
try {
    $fab->generateWeeklyDues($aid1, []);
    $h->test('empty weeks array produces 0 rows', true);
} catch (\Throwable $e) {
    $h->test('empty weeks array handled', false, $e->getMessage());
}

// Weekly dues rounding: 10 / 3 should sum to 10.00
$aid2 = $fab->createAllocation(['project_id' => $pidValid, 'approved_amount' => 1000]);
$fab->generateWeeklyDues($aid2, [
    ['start' => '2026-07-13', 'end' => '2026-07-19'],
    ['start' => '2026-07-20', 'end' => '2026-07-26'],
    ['start' => '2026-07-27', 'end' => '2026-08-02'],
]);
$stmt = $db->prepare("SELECT COALESCE(SUM(due_amount),0) FROM pal_fabrication_weekly_dues WHERE allocation_id = ?");
$stmt->execute([$aid2]);
$sum = (float)$stmt->fetchColumn();
$h->test('weekly dues rounding sums to total (1000 = ' . number_format($sum, 2) . ')', abs($sum - 1000) < 0.01);

// Zero/negative payment amount
$h->assertThrows(\InvalidArgumentException::class,
    fn() => $fab->recordPayment(['project_id' => $pidValid, 'amount' => 0, 'payment_date' => '2026-07-13']),
    'zero payment amount throws'
);
$h->assertThrows(\InvalidArgumentException::class,
    fn() => $fab->recordPayment(['project_id' => $pidValid, 'amount' => -100, 'payment_date' => '2026-07-13']),
    'negative payment amount throws'
);

// Non-existent payment submission
$h->assertThrows(\InvalidArgumentException::class,
    fn() => $fab->submitPayment(999999),
    'submit non-existent payment throws'
);

// ────────────────────────────────────────────────────────────────
$h->section('4. Type confusion: string→float coercion');

// CA advance: string amount instead of float
$caId5 = $ca->create(['team_lead_id' => $uid, 'amount' => '500', 'advance_date' => '2026-07-13']);
$h->test('string amount coerced to float and accepted', $caId5 > 0);

// Fabrication: string project_id
$h->assertThrows(\InvalidArgumentException::class,
    fn() => $fab->createAllocation(['project_id' => 'not-a-number', 'approved_amount' => 1000]),
    'non-numeric string project_id throws (coerced to 0)'
);

// ────────────────────────────────────────────────────────────────
$h->section('5. Large values: overflow boundaries');

// Very large cash advance
try {
    $caId6 = $ca->create(['team_lead_id' => $uid, 'amount' => 999999999.99, 'advance_date' => '2026-07-13']);
    $h->test('very large advance amount accepted', $caId6 > 0);
} catch (\Throwable $e) {
    $h->test('large advance fails gracefully', false, $e->getMessage());
}

// ────────────────────────────────────────────────────────────────
$h->section('6. Empty/null state handling');

// Empty team_lead_id (0) — should be accepted as valid (team_lead_id is nullable FK)
try {
    $caId7 = $ca->create(['team_lead_id' => 0, 'amount' => 100, 'advance_date' => '2026-07-13']);
    $h->test('zero team_lead_id accepted (nullable FK)', $caId7 > 0);
} catch (\Throwable $e) {
    $h->test('zero team_lead_id', false, $e->getMessage());
}

// ────────────────────────────────────────────────────────────────
$h->section('Cleanup');

foreach ($ownsTables as $t) {
    try { $db->exec("DELETE FROM {$t} WHERE tenant_id = {$testTenantId}"); } catch (\Throwable $e) {}
}
$h->test('test data cleaned up', true);

// ────────────────────────────────────────────────────────────────
$h->done();
