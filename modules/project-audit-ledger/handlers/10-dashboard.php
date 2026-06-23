<?php

declare(strict_types=1);

function palPageDashboard(): void
{
    $user = palCurrentUser();
    $tid = (int)($user['tenant_id'] ?? 0);
    $db = palDb();

    // Aggregate KPIs
    $activeStmt = $db->prepare("SELECT COUNT(*) FROM pal_projects WHERE tenant_id = :tid AND status IN ('approved','in_progress')");
    $activeStmt->execute([':tid' => $tid]);
    $activeProjects = (int)$activeStmt->fetchColumn();

    $pendingStmt = $db->prepare("SELECT COUNT(*) FROM pal_approvals WHERE tenant_id = :tid AND decision = 'pending'");
    $pendingStmt->execute([':tid' => $tid]);
    $pendingApprovals = (int)$pendingStmt->fetchColumn();

    $monthlyStmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM pal_collections WHERE tenant_id = :tid AND status = 'approved' AND MONTH(created_at) = MONTH(CURRENT_DATE) AND YEAR(created_at) = YEAR(CURRENT_DATE)");
    $monthlyStmt->execute([':tid' => $tid]);
    $monthlySales = (float)$monthlyStmt->fetchColumn();

    $lowStockStmt = $db->prepare("SELECT COUNT(*) FROM pal_materials m JOIN pal_inventory_balances b ON m.id = b.material_id WHERE m.tenant_id = :tid AND m.reorder_level IS NOT NULL AND b.quantity <= m.reorder_level");
    $lowStockStmt->execute([':tid' => $tid]);
    $lowStockCount = (int)$lowStockStmt->fetchColumn();

    palRender(__DIR__ . '/../templates/project-audit-ledger/shell.disyl', [
        'current_user' => $user,
        'page_title' => 'Dashboard',
        'page_content' => 'dashboard',
        'dashboard' => [
            'active_projects' => $activeProjects,
            'pending_approvals' => $pendingApprovals,
            'monthly_sales' => $monthlySales,
            'low_stock' => $lowStockCount,
        ],
    ]);
}
