<?php
/**
 * PAL Seed Script — Deterministic test data for browser lifecycle tests.
 *
 * Creates a complete test scenario with known identifiers and outputs
 * a JSON map of all created entities. Designed to be called from
 * Playwright via child_process.execSync().
 *
 * Usage:
 *   php tests/pal/pal_seed_lifecycle.php [--cleanup]
 *
 * Output (stdout):
 *   {"tenant_id":999908,"client_id":1,"project_id":2,"project_status":"ongoing",...}
 *
 * The output IDs can be used by Playwright tests to navigate directly
 * to specific entity pages without relying on .first() selectors.
 *
 * Exit code: 0 on success, 1 on failure.
 */

declare(strict_types=1);

// ── Bootstrap (same pattern as integration tests) ────────────
$_SERVER['HTTP_HOST'] = 'palsystem.test';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['SERVER_NAME'] = 'palsystem.test';

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../modules/project-audit-ledger/helpers.php';
require_once __DIR__ . '/../../modules/project-audit-ledger/handlers.php';

$isCleanup = in_array('--cleanup', $argv ?? [], true);

// ── Config ───────────────────────────────────────────────────
$isCleanup = in_array('--cleanup', $argv ?? [], true);
$tenantId = 999908;

// Support --tenant=N or PAL_TEST_TENANT env var for isolation
foreach ($argv ?? [] as $arg) {
    if (str_starts_with($arg, '--tenant=')) {
        $tenantId = (int) substr($arg, 9);
    }
}
if ($tenantId === 999908 && getenv('PAL_TEST_TENANT')) {
    $tenantId = (int) getenv('PAL_TEST_TENANT');
}

$ownsTables = [
    'pal_projects', 'pal_clients', 'pal_users', 'pal_expenses',
    'pal_approvals', 'pal_audit_logs', 'pal_sales', 'pal_sale_items',
    'pal_receivables', 'pal_receivable_payments', 'pal_project_items',
    'pal_fabrication_allocations', 'pal_fabrication_weekly_dues',
    'pal_fabrication_payments', 'pal_team_leads', 'pal_collections',
    'pal_settings', 'pal_project_types',
];
$db = app()->db();
$palDb = new \Ikabud\Kernel\Contracts\ModuleDB($db, 'project-audit-ledger', $ownsTables, []);

// ── Cleanup ──────────────────────────────────────────────────
$cleanupErrors = [];
foreach ($ownsTables as $t) {
    try {
        $db->prepare("DELETE FROM {$t} WHERE tenant_id = ?")->execute([$tenantId]);
    } catch (\Throwable $e) {
        $cleanupErrors[] = ['table' => $t, 'error' => $e->getMessage()];
    }
}

if ($cleanupErrors !== []) {
    fwrite(STDERR, json_encode([
        'ok' => false, 'action' => 'cleanup', 'tenant_id' => $tenantId,
        'errors' => $cleanupErrors,
    ], JSON_PRETTY_PRINT) . PHP_EOL);
    exit(1);
}

if ($isCleanup) {
    echo json_encode(['ok' => true, 'action' => 'cleanup', 'tenant_id' => $tenantId]) . "\n";
    exit(0);
}

// ── Seed counter for unique identifiers ──────────────────────
$sc = 0;
$prefix = 'LS-' . date('Ymd');

function sUser(PDO $db, int $tid): int {
    global $sc; $sc++;
    $s = $db->prepare("INSERT INTO pal_users (tenant_id, username, email, password_hash, full_name, role, is_active) VALUES (?,?,?,?,?,'admin',1)");
    $s->execute([$tid, "lsc{$sc}", "ls{$sc}@seed.com", 'hash', "Lifecycle Seed $sc"]);
    return (int)$db->lastInsertId();
}
function sClient(PDO $db, int $tid): array {
    global $sc; $sc++;
    $name = "Lifecycle Client {$sc}";
    $s = $db->prepare("INSERT INTO pal_clients (tenant_id, name, contact_person, email, phone, address, is_active) VALUES (?,?,?,?,?,?,1)");
    $s->execute([$tid, $name, "Contact {$sc}", "lc{$sc}@seed.com", "0917{$sc}", "{$sc} Seed Street"]);
    return ['id' => (int)$db->lastInsertId(), 'name' => $name];
}
function sProject(PDO $db, int $tid, int $cid, string $st = 'ongoing', float $ca = 150000, float $fabPct = 25): array {
    global $sc; $sc++;
    $title = "Lifecycle Project {$sc}";
    $s = $db->prepare("INSERT INTO pal_projects (tenant_id, project_id, job_order_number, jo_type, title, client_id, contract_amount, estimated_cost, fabrication_alloc_pct, status, created_by) VALUES (?,?,?,'contract',?,?,?,?,?,?,1)");
    $s->execute([$tid, "PJ-{$prefix}-{$sc}", "JO-{$prefix}-{$sc}", $title, $cid, $ca, $ca*0.6, $fabPct, $st]);
    return ['id' => (int)$db->lastInsertId(), 'title' => $title, 'status' => $st];
}
function sExpense(PDO $db, int $tid, int $pid, float $amount = 25000): array {
    global $sc; $sc++;
    $s = $db->prepare("INSERT INTO pal_expenses (tenant_id, project_id, expense_number, description, amount, expense_date, status, created_by) VALUES (?,?,?,?,?,?,'draft',1)");
    $s->execute([$tid, $pid, "EXP-{$prefix}-{$sc}", "Seed expense {$sc}", $amount, date('Y-m-d')]);
    return ['id' => (int)$db->lastInsertId(), 'amount' => $amount];
}
function sApproval(PDO $db, int $tid, string $entityType, int $entityId, int $uid): int {
    $s = $db->prepare("INSERT INTO pal_approvals (tenant_id, entity_type, entity_id, submitted_by, decision, previous_status, new_status) VALUES (?,?,?,?,'pending','draft','pending_approval')");
    $s->execute([$tid, $entityType, $entityId, $uid]);
    return (int)$db->lastInsertId();
}

try {
    // ── Seed data ─────────────────────────────────────────────
    $uid = sUser($db, $tenantId);
    $client = sClient($db, $tenantId);
    $project = sProject($db, $tenantId, $client['id'], 'ongoing', 150000, 25);

    // Approve the project
    $wf = new palJobOrderWorkflow($palDb, $tenantId, $uid);
    $wf->transition($project['id'], 'completed', ['status' => 'ongoing', 'client_id' => $client['id']]);
    $wf->apply($project['id'], 'completed');

    // Create expense + approve it
    $expense = sExpense($db, $tenantId, $project['id'], 25000);
    $apprId = sApproval($db, $tenantId, 'expense', $expense['id'], $uid);
    $approvalSvc = new palApprovalService($palDb, $tenantId, $uid);
    $approvalSvc->decide($apprId, 'approved', 'Seed approval');

    // Create fabrication allocation + weekly dues
    $fab = new palFabricationService($palDb, $tenantId, $uid);
    $fabAllocId = $fab->createAllocation([
        'project_id' => $project['id'],
        'approved_amount' => 30000,
        'approval_reason' => 'Seed fabrication allocation',
    ]);
    $fab->generateWeeklyDues($fabAllocId, [
        ['start' => date('Y-m-d'), 'end' => date('Y-m-d', strtotime('+6 days'))],
        ['start' => date('Y-m-d', strtotime('+7 days')), 'end' => date('Y-m-d', strtotime('+13 days'))],
    ]);

    // Record a payment
    $payId = $fab->recordPayment([
        'project_id' => $project['id'],
        'amount' => 10000,
        'payment_date' => date('Y-m-d'),
    ]);
    $fab->submitPayment($payId);

    // ── Output ────────────────────────────────────────────────
    $output = [
        'ok' => true,
        'tenant_id' => $tenantId,
        'prefix' => $prefix,
        'user_id' => $uid,
        'client' => $client,
        'project' => $project,
        'expense' => $expense,
        'approval_id' => $apprId,
        'fabrication_allocation_id' => $fabAllocId,
        'payment_id' => $payId,
    ];

    echo json_encode($output, JSON_PRETTY_PRINT) . "\n";
    exit(0);

} catch (\Throwable $e) {
    $error = ['ok' => false, 'error' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()];
    echo json_encode($error) . "\n";
    exit(1);
}
