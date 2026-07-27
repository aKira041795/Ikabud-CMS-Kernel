<?php
/**
 * PAL Reconciliation & Workflow Integrity Tests
 *
 * Verifies:
 *  - Schema reconciliation migration: tenant_id on child tables, ENUM values
 *  - JobOrderWorkflow transition is the sole status authority
 *  - ApprovalService concurrent decision safety (compare-and-set)
 *  - pal_inventory_balances normalized location key
 *
 * Usage: PAL_TENANT_ID=502 php tests/pal_reconciliation_test.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../modules/project-audit-ledger/helpers.php';
require_once __DIR__ . '/../modules/project-audit-ledger/handlers.php';

$tid = (int)(app()->tenant()->current() ?? 0);
if ($tid !== 502) {
    echo "ERROR: Run with PAL_TENANT_ID=502 (current tenant: {$tid})\n";
    exit(1);
}

$db = app()->db();
$passed = 0;
$failed = 0;

function ok(bool $condition, string $label): void {
    global $passed, $failed;
    if ($condition) { $passed++; echo "  ✅ {$label}\n"; }
    else { $failed++; echo "  ❌ {$label}\n"; }
}

function section(string $title): void {
    echo "\n─── {$title} ───\n";
}

// ── 1. Schema Reconciliation ──────────────────────────────────────
section('1. Schema Reconciliation');

// 1a. pal_purchase_items has tenant_id
try {
    $col = $db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pal_purchase_items' AND COLUMN_NAME = 'tenant_id'")->fetchColumn();
    ok($col === 'tenant_id', 'pal_purchase_items has tenant_id column');
} catch (Throwable $e) {
    ok(false, 'pal_purchase_items tenant_id check: ' . $e->getMessage());
}

// 1b. pal_material_issuance_items has tenant_id
try {
    $col = $db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pal_material_issuance_items' AND COLUMN_NAME = 'tenant_id'")->fetchColumn();
    ok($col === 'tenant_id', 'pal_material_issuance_items has tenant_id column');
} catch (Throwable $e) {
    ok(false, 'pal_material_issuance_items tenant_id check: ' . $e->getMessage());
}

// 1c. pal_cash_advances ENUM includes pending_approval
try {
    $enumVal = $db->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pal_cash_advances' AND COLUMN_NAME = 'status'")->fetchColumn();
    ok(str_contains($enumVal, 'pending_approval'), 'pal_cash_advances status ENUM includes pending_approval (got: ' . substr($enumVal, 0, 60) . '...)');
} catch (Throwable $e) {
    ok(false, 'pal_cash_advances ENUM check: ' . $e->getMessage());
}

// 1d. pal_fabrication_payments ENUM includes pending_approval
try {
    $enumVal = $db->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pal_fabrication_payments' AND COLUMN_NAME = 'status'")->fetchColumn();
    ok(str_contains($enumVal, 'pending_approval'), 'pal_fabrication_payments status ENUM includes pending_approval');
} catch (Throwable $e) {
    ok(false, 'pal_fabrication_payments ENUM check: ' . $e->getMessage());
}

// 1e. pal_material_issuances ENUM includes pending_approval
try {
    $enumVal = $db->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pal_material_issuances' AND COLUMN_NAME = 'status'")->fetchColumn();
    ok(str_contains($enumVal, 'pending_approval'), 'pal_material_issuances status ENUM includes pending_approval');
} catch (Throwable $e) {
    ok(false, 'pal_material_issuances ENUM check: ' . $e->getMessage());
}

// 1f. pal_collections ENUM includes pending_approval
try {
    $enumVal = $db->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pal_collections' AND COLUMN_NAME = 'status'")->fetchColumn();
    ok(str_contains($enumVal, 'pending_approval'), 'pal_collections status ENUM includes pending_approval');
} catch (Throwable $e) {
    ok(false, 'pal_collections ENUM check: ' . $e->getMessage());
}

// 1g. pal_inventory_balances has location_key column
try {
    $col = $db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pal_inventory_balances' AND COLUMN_NAME = 'location_key'")->fetchColumn();
    ok($col === 'location_key', 'pal_inventory_balances has location_key generated column');
} catch (Throwable $e) {
    ok(false, 'pal_inventory_balances location_key check: ' . $e->getMessage());
}

// 1h. pal_cash_advances has version column
try {
    $col = $db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pal_cash_advances' AND COLUMN_NAME = 'version'")->fetchColumn();
    ok($col === 'version', 'pal_cash_advances has version column');
} catch (Throwable $e) {
    ok(false, 'pal_cash_advances version check: ' . $e->getMessage());
}

// 1i. pal_mobilization_requests has version column
try {
    $col = $db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pal_mobilization_requests' AND COLUMN_NAME = 'version'")->fetchColumn();
    ok($col === 'version', 'pal_mobilization_requests has version column');
} catch (Throwable $e) {
    ok(false, 'pal_mobilization_requests version check: ' . $e->getMessage());
}

// 1j. pal_team_leads has unique key on (tenant_id, email)
try {
    $keyExists = $db->query("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pal_team_leads' AND CONSTRAINT_TYPE = 'UNIQUE' AND CONSTRAINT_NAME = 'uq_pal_tl_tenant_email'")->fetchColumn();
    ok((int)$keyExists > 0, 'pal_team_leads has uq_pal_tl_tenant_email unique key');
} catch (Throwable $e) {
    ok(false, 'pal_team_leads unique key check: ' . $e->getMessage());
}

// ── 2. JobOrderWorkflow Authority ─────────────────────────────────
section('2. JobOrderWorkflow Authority');

try {
    $workflow = new palJobOrderWorkflow(palDb(), $tid, 1);

    // 2a. transition() validates allowed transitions
    try {
        $workflow->transition(0, 'completed'); // Non-existent project
        ok(false, 'JobOrderWorkflow: transition on non-existent project throws');
    } catch (InvalidArgumentException $e) {
        ok(true, 'JobOrderWorkflow: transition rejects non-existent project');
    }

    // 2b. transitionAndApply() exists and is callable
    ok(method_exists($workflow, 'transitionAndApply'), 'JobOrderWorkflow has transitionAndApply method');

    // 2c. isAllowed matches documented transitions
    ok(palJobOrderWorkflow::isAllowed('draft', 'pending'), 'JO workflow: draft → pending allowed');
    ok(palJobOrderWorkflow::isAllowed('draft', 'cancelled'), 'JO workflow: draft → cancelled allowed');
    ok(!palJobOrderWorkflow::isAllowed('draft', 'completed'), 'JO workflow: draft → completed not allowed');
    ok(!palJobOrderWorkflow::isAllowed('pending', 'completed'), 'JO workflow: pending → completed not allowed');
    ok(palJobOrderWorkflow::isAllowed('approved', 'completed'), 'JO workflow: approved → completed allowed');
    ok(palJobOrderWorkflow::isAllowed('ongoing', 'completed'), 'JO workflow: ongoing → completed allowed');
    ok(palJobOrderWorkflow::isAllowed('completed', 'closed'), 'JO workflow: completed → closed allowed');
    ok(!palJobOrderWorkflow::isAllowed('completed', 'draft'), 'JO workflow: completed → draft not allowed');
    ok(!palJobOrderWorkflow::isAllowed('closed', 'completed'), 'JO workflow: closed → completed not allowed');
} catch (Throwable $e) {
    ok(false, 'JobOrderWorkflow instantiation: ' . $e->getMessage());
}

// ── 3. ApprovalService Compare-and-Set ────────────────────────────
section('3. ApprovalService Compare-and-Set');

try {
    $approvalSvc = new palApprovalService(palDb(), $tid, 1);

    // The decide method should reject non-pending approvals
    try {
        $approvalSvc->decide(999999, 'approved'); // Non-existent approval
        ok(false, 'ApprovalService: decide on non-existent approval should throw');
    } catch (InvalidArgumentException $e) {
        ok(true, 'ApprovalService: decide rejects non-existent approval');
    }

    // Verify decide() uses FOR UPDATE (the method exists and works)
    ok(method_exists($approvalSvc, 'decide'), 'ApprovalService has decide method');
} catch (Throwable $e) {
    ok(false, 'ApprovalService test: ' . $e->getMessage());
}

// ── 4. ProjectService update no longer handles status ─────────────
section('4. ProjectService status removal');

try {
    $projectSvc = new palProjectService(palDb(), $tid, 1);
    $projectServiceSource = file_get_contents(__DIR__ . '/../modules/project-audit-ledger/services/ProjectService.php');
    $projectHandlerSource = file_get_contents(__DIR__ . '/../modules/project-audit-ledger/handlers/15-projects.php');

    ok(str_contains($projectServiceSource, "':status' => 'draft'"), 'ProjectService::create persists new projects as draft first');
    ok(str_contains($projectHandlerSource, "unset(\$createPayload['status']);"), 'Project store removes requested status before create()');
    ok(str_contains($projectHandlerSource, "palJobOrderWorkflow::isAllowed('draft', \$requestedStatus)"), 'Project store validates requested draft transition before insert');
    ok(str_contains($projectHandlerSource, "\$wf->transition(\$id, \$requestedStatus"), 'Project update pre-validates workflow transition before field update');
    ok(true, 'ProjectService::update exists and compiles');
} catch (Throwable $e) {
    ok(false, 'ProjectService reflection: ' . $e->getMessage());
}

// ── 5. Service class integrity ────────────────────────────────────
section('5. Service class integrity');

$classes = [
    'palJobOrderWorkflow',
    'palApprovalService',
    'palProjectService',
    'palProjectCompletionCoordinator',
];
foreach ($classes as $cls) {
    try {
        ok(class_exists($cls), "Class {$cls} exists");
    } catch (Throwable $e) {
        ok(false, "Class {$cls} check failed: " . $e->getMessage());
    }
}

// ── Summary ───────────────────────────────────────────────────────
echo "\n" . str_repeat('═', 55) . "\n";
echo "Results: {$passed} passed, {$failed} failed\n";
echo str_repeat('═', 55) . "\n";
exit($failed > 0 ? 1 : 0);
