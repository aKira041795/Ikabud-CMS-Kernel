<?php

declare(strict_types=1);

function palPageDashboard(): void
{
    $user = palCurrentUser();
    $tid = (int)($user['tenant_id'] ?? 0);
    $db = palDb();

    // ── Project Pipeline ──
    $activeStmt = $db->prepare("SELECT COUNT(*) FROM pal_projects WHERE tenant_id = :tid AND status IN ('approved','in_progress')");
    $activeStmt->execute([':tid' => $tid]);
    $activeProjects = (int)$activeStmt->fetchColumn();

    $totalProjects = $db->prepare("SELECT COUNT(*) FROM pal_projects WHERE tenant_id = :tid");
    $totalProjects->execute([':tid' => $tid]);
    $totalProjectCount = (int)$totalProjects->fetchColumn();

    $projectStatuses = $db->prepare("SELECT status, COUNT(*) AS cnt FROM pal_projects WHERE tenant_id = :tid GROUP BY status ORDER BY cnt DESC");
    $projectStatuses->execute([':tid' => $tid]);
    $projectStatusBreakdown = $projectStatuses->fetchAll(\PDO::FETCH_ASSOC);

    // ── Financials ──
    // Total contract value (all projects)
    $contractVal = $db->prepare("SELECT COALESCE(SUM(contract_amount), 0) FROM pal_projects WHERE tenant_id = :tid AND status NOT IN ('cancelled','closed')");
    $contractVal->execute([':tid' => $tid]);
    $totalContractValue = (float)$contractVal->fetchColumn();

    // Total expenses (approved)
    $totalExp = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM pal_expenses WHERE tenant_id = :tid AND status IN ('approved','paid')");
    $totalExp->execute([':tid' => $tid]);
    $totalExpenses = (float)$totalExp->fetchColumn();

    // Total collected
    $totalCol = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM pal_collections WHERE tenant_id = :tid AND status = 'approved'");
    $totalCol->execute([':tid' => $tid]);
    $totalCollected = (float)$totalCol->fetchColumn();

    // Outstanding receivables (sales with unpaid balance)
    $outstanding = $db->prepare("SELECT COALESCE(SUM(s.net_amount - COALESCE((SELECT SUM(c.amount) FROM pal_collections c WHERE c.sales_id = s.id AND c.status = 'approved'), 0)), 0) FROM pal_sales s WHERE s.tenant_id = :tid AND s.status NOT IN ('cancelled','voided')");
    $outstanding->execute([':tid' => $tid]);
    $outstandingReceivables = (float)$outstanding->fetchColumn();

    // ── Cash Flow (this month) ──
    $monthlyCol = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM pal_collections WHERE tenant_id = :tid AND status = 'approved' AND MONTH(payment_date) = MONTH(CURRENT_DATE) AND YEAR(payment_date) = YEAR(CURRENT_DATE)");
    $monthlyCol->execute([':tid' => $tid]);
    $monthlyCollections = (float)$monthlyCol->fetchColumn();

    $monthlyExp = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM pal_expenses WHERE tenant_id = :tid AND status IN ('approved','paid') AND MONTH(expense_date) = MONTH(CURRENT_DATE) AND YEAR(expense_date) = YEAR(CURRENT_DATE)");
    $monthlyExp->execute([':tid' => $tid]);
    $monthlyExpenses = (float)$monthlyExp->fetchColumn();

    // ── Pending Approvals ──
    $pendingStmt = $db->prepare("SELECT COUNT(*) FROM pal_approvals WHERE tenant_id = :tid AND decision = 'pending'");
    $pendingStmt->execute([':tid' => $tid]);
    $pendingApprovals = (int)$pendingStmt->fetchColumn();

    // ── Low Stock Alerts ──
    $lowStockStmt = $db->prepare("
        SELECT m.name, m.material_code, b.quantity, m.reorder_level, u.name AS unit
        FROM pal_materials m
        JOIN pal_inventory_balances b ON m.id = b.material_id
        LEFT JOIN pal_units u ON m.unit_id = u.id
        WHERE m.tenant_id = :tid AND m.reorder_level IS NOT NULL AND b.quantity <= m.reorder_level
        ORDER BY (b.quantity / NULLIF(m.reorder_level, 0)) ASC
        LIMIT 10");
    $lowStockStmt->execute([':tid' => $tid]);
    $lowStockItems = $lowStockStmt->fetchAll(\PDO::FETCH_ASSOC);
    $lowStockCount = count($lowStockItems);

    // ── Recent Activity ──
    $recentProjects = $db->prepare("SELECT id, project_id, title, status, contract_amount, created_at FROM pal_projects WHERE tenant_id = :tid ORDER BY created_at DESC LIMIT 5");
    $recentProjects->execute([':tid' => $tid]);
    $recentProj = $recentProjects->fetchAll(\PDO::FETCH_ASSOC);

    $recentExpenses = $db->prepare("SELECT e.id, e.description, e.amount, e.expense_date, e.status, ec.name AS category_name FROM pal_expenses e LEFT JOIN pal_expense_categories ec ON e.category_id = ec.id WHERE e.tenant_id = :tid ORDER BY e.created_at DESC LIMIT 5");
    $recentExpenses->execute([':tid' => $tid]);
    $recentExp = $recentExpenses->fetchAll(\PDO::FETCH_ASSOC);

    $recentCollections = $db->prepare("SELECT c.id, c.collection_number, c.amount, c.payment_date, c.payment_method, p.title AS project_title FROM pal_collections c LEFT JOIN pal_projects p ON c.project_id = p.id WHERE c.tenant_id = :tid ORDER BY c.created_at DESC LIMIT 5");
    $recentCollections->execute([':tid' => $tid]);
    $recentColl = $recentCollections->fetchAll(\PDO::FETCH_ASSOC);

    // ── Pending Approvals Detail ──
    $pendingDetail = $db->prepare("
        SELECT a.id, a.approvable_type, a.requested_by, a.created_at,
               CASE WHEN a.approvable_type = 'expense' THEN (SELECT description FROM pal_expenses WHERE id = a.approvable_id)
                    WHEN a.approvable_type = 'purchase' THEN (SELECT purchase_number FROM pal_purchases WHERE id = a.approvable_id)
                    ELSE '—' END AS description
        FROM pal_approvals a
        WHERE a.tenant_id = :tid AND a.decision = 'pending'
        ORDER BY a.created_at DESC
        LIMIT 10");
    $pendingDetail->execute([':tid' => $tid]);
    $pendingItems = $pendingDetail->fetchAll(\PDO::FETCH_ASSOC);

    palRender(__DIR__ . '/../templates/project-audit-ledger/shell.disyl', [
        'current_user' => $user,
        'page_title' => 'Dashboard',
        'page_content' => 'dashboard',
        'dashboard' => [
            'active_projects' => $activeProjects,
            'total_projects' => $totalProjectCount,
            'project_status_breakdown' => $projectStatusBreakdown,
            'total_contract_value' => $totalContractValue,
            'total_expenses' => $totalExpenses,
            'total_collected' => $totalCollected,
            'outstanding_receivables' => $outstandingReceivables,
            'monthly_collections' => $monthlyCollections,
            'monthly_expenses' => $monthlyExpenses,
            'net_cash_flow' => $monthlyCollections - $monthlyExpenses,
            'pending_approvals' => $pendingApprovals,
            'low_stock_count' => $lowStockCount,
            'low_stock_items' => $lowStockItems,
            'recent_projects' => $recentProj,
            'recent_expenses' => $recentExp,
            'recent_collections' => $recentColl,
            'pending_approval_items' => $pendingItems,
            'est_profit' => max(0, $totalContractValue - $totalExpenses),
        ],
    ]);
}
