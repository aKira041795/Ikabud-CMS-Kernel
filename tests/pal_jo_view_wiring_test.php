<?php

declare(strict_types=1);

$pass = 0;
$fail = 0;

function ok(bool $condition, string $label): void
{
    global $pass, $fail;
    if ($condition) {
        $pass++;
        echo "  OK {$label}\n";
        return;
    }
    $fail++;
    echo "  FAIL {$label}\n";
}

function src(string $path): string
{
    $content = file_get_contents(__DIR__ . '/../' . $path);
    if ($content === false) {
        throw new RuntimeException("Unable to read {$path}");
    }
    return $content;
}

echo "PAL JO View Wiring\n";
echo str_repeat('=', 60) . "\n";

$helpers = src('modules/project-audit-ledger/helpers.php');
$dashboardVm = src('modules/project-audit-ledger/presentation/PalDashboardViewModel.php');
$approvalService = src('modules/project-audit-ledger/services/ApprovalService.php');
$teamLead = src('modules/project-audit-ledger/handlers/53-team-lead.php');
$audit = src('modules/project-audit-ledger/handlers/65-audit.php');

ok(str_contains($helpers, 'p.job_order_number'), 'Entity list capabilities expose JO numbers');
ok(str_contains($helpers, 'c.tenant_id = p.tenant_id'), 'Project entity client joins are tenant-scoped');
ok(str_contains($helpers, 'pr.job_order_number'), 'Purchase entity capability exposes linked JO number');
ok(str_contains($helpers, 'p.job_order_number FROM pal_mobilization_requests'), 'Mobilization entity capability exposes linked JO number');
ok(str_contains($helpers, "'project'                => '/admin/project-audit-ledger/projects'"), 'Audit URL helper links project alias rows');
ok(str_contains($helpers, "'mobilization'           => '/admin/project-audit-ledger/mobilization'"), 'Audit URL helper links mobilization alias rows');

ok(str_contains($dashboardVm, "status IN ('approved','started','ongoing')"), 'Dashboard active count uses live JO statuses');
ok(str_contains($dashboardVm, 'job_order_number, title, status'), 'Dashboard recent projects loader exposes JO number');

ok(str_contains($approvalService, "approved_by = :rv, approved_at = NOW()"), 'Central approval path stamps mobilization approved_by/approved_at');
ok(str_contains($teamLead, "throw new RuntimeException('No pending mobilization approval row was updated.')"), 'Direct mobilization approval rolls back on missing approval sync');
ok(!str_contains($teamLead, "WHERE fabrication_team_lead_id = :tlid AND tenant_id = :tid\n          AND status IN"), 'Team lead project dropdowns include all assigned projects regardless of status');
ok(str_contains($teamLead, 'SELECT 1 FROM pal_projects WHERE id = :pid AND tenant_id = :tid AND fabrication_team_lead_id = :tlid LIMIT 1'), 'Team lead request APIs validate selected project assignment');
ok(str_contains($audit, "\$lookupTable = match (\$table)"), 'Audit trail resolves project/mobilization aliases through real PAL tables');

$selectorHandlers = [
    'modules/project-audit-ledger/handlers/25-expenses.php',
    'modules/project-audit-ledger/handlers/30-purchases.php',
    'modules/project-audit-ledger/handlers/40-issuance.php',
    'modules/project-audit-ledger/handlers/45-fabrication.php',
    'modules/project-audit-ledger/handlers/50-sales.php',
    'modules/project-audit-ledger/handlers/52-quotations.php',
    'modules/project-audit-ledger/handlers/57-cash-advances.php',
    'modules/project-audit-ledger/handlers/59-bom.php',
];
foreach ($selectorHandlers as $path) {
    ok(str_contains(src($path), 'job_order_number'), "{$path} project selector/query exposes JO number");
}

$templates = [
    'modules/project-audit-ledger/templates/project-audit-ledger/pages/expense-form.disyl',
    'modules/project-audit-ledger/templates/project-audit-ledger/pages/purchase-form.disyl',
    'modules/project-audit-ledger/templates/project-audit-ledger/pages/issuance-form.disyl',
    'modules/project-audit-ledger/templates/project-audit-ledger/pages/material-return-form.disyl',
    'modules/project-audit-ledger/templates/project-audit-ledger/pages/sales-form.disyl',
    'modules/project-audit-ledger/templates/project-audit-ledger/pages/collection-form.disyl',
    'modules/project-audit-ledger/templates/project-audit-ledger/pages/bill-of-materials.disyl',
    'modules/project-audit-ledger/templates/project-audit-ledger/pages/fabrication-payment-form.disyl',
    'modules/project-audit-ledger/templates/project-audit-ledger/pages/cash-advance-form.disyl',
    'modules/project-audit-ledger/templates/project-audit-ledger/pages/quotation-form.disyl',
    'modules/project-audit-ledger/templates/project-audit-ledger/pages/team-lead-mobilization-list.disyl',
    'modules/project-audit-ledger/templates/project-audit-ledger/pages/dashboard.disyl',
];
foreach ($templates as $path) {
    ok(str_contains(src($path), 'job_order_number'), "{$path} displays JO number when project context is shown");
}

ok(str_contains(src('modules/project-audit-ledger/helpers/views/pal_project.disyl'), 'job_order_number'), 'Project entity view prefers JO number');
ok(str_contains(src('modules/project-audit-ledger/helpers/views/pal_purchase.disyl'), 'project_title'), 'Purchase entity view shows project context');
ok(str_contains(src('modules/project-audit-ledger/helpers/views/pal_expense.disyl'), 'project_title'), 'Expense entity view shows project context');
ok(str_contains(src('modules/project-audit-ledger/helpers/views/pal_mobilization.disyl'), 'job_order_number'), 'Mobilization entity view shows JO number');

echo str_repeat('=', 60) . "\n";
echo "Results: {$pass} passed, {$fail} failed\n";

if ($fail > 0) {
    exit(1);
}
