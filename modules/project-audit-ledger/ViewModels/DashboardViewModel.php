<?php

declare(strict_types=1);

/**
 * DashboardViewModel — encapsulates all dashboard data-fetching logic.
 *
 * Replaces the ad-hoc query construction in palPageDashboard() with a single
 * testable, reusable view model. The handler now just resolves this model
 * and passes it to the template.
 */
class palDashboardViewModel
{
    private Ikabud\Kernel\Contracts\ModuleDB $db;
    private int $tenantId;

    public function __construct(Ikabud\Kernel\Contracts\ModuleDB $db, int $tenantId)
    {
        $this->db = $db;
        $this->tenantId = $tenantId;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'project_pipeline'     => $this->projectPipeline(),
            'financials'           => $this->financials(),
            'cash_flow'            => $this->cashFlow(),
            'pending_approvals'    => $this->pendingApprovals(),
            'low_stock'            => $this->lowStock(),
            'recent'               => $this->recentActivity(),
        ];
    }

    // ── Project Pipeline ──

    /** @return array<string,mixed> */
    private function projectPipeline(): array
    {
        $active = $this->db->prepare(
            "SELECT COUNT(*) FROM pal_projects WHERE tenant_id = :tid AND status IN ('approved','started','ongoing')"
        );
        $active->execute([':tid' => $this->tenantId]);

        $total = $this->db->prepare("SELECT COUNT(*) FROM pal_projects WHERE tenant_id = :tid");
        $total->execute([':tid' => $this->tenantId]);

        $byStatus = $this->db->prepare(
            "SELECT status, COUNT(*) AS cnt FROM pal_projects WHERE tenant_id = :tid GROUP BY status ORDER BY cnt DESC"
        );
        $byStatus->execute([':tid' => $this->tenantId]);

        return [
            'active'       => (int) $active->fetchColumn(),
            'total'        => (int) $total->fetchColumn(),
            'by_status'    => $byStatus->fetchAll(PDO::FETCH_ASSOC),
        ];
    }

    // ── Financials ──

    /** @return array<string,mixed> */
    private function financials(): array
    {
        // Contract value (active projects only)
        $contract = $this->db->prepare(
            "SELECT COALESCE(SUM(contract_amount), 0) FROM pal_projects
             WHERE tenant_id = :tid AND status NOT IN ('cancelled','closed')"
        );
        $contract->execute([':tid' => $this->tenantId]);
        $totalContract = (float) $contract->fetchColumn();

        // Approved expenses
        $expenses = $this->db->prepare(
            "SELECT COALESCE(SUM(amount), 0) FROM pal_expenses
             WHERE tenant_id = :tid AND status IN ('approved','paid')"
        );
        $expenses->execute([':tid' => $this->tenantId]);
        $totalExpenses = (float) $expenses->fetchColumn();

        // Fabrication budgeted (contract_amount × alloc_pct)
        $fabBudget = $this->db->prepare(
            "SELECT COALESCE(SUM(ROUND(contract_amount * COALESCE(fabrication_alloc_pct, 0) / 100, 2)), 0)
             FROM pal_projects WHERE tenant_id = :tid AND status NOT IN ('cancelled','closed')
             AND fabrication_alloc_pct > 0"
        );
        $fabBudget->execute([':tid' => $this->tenantId]);
        $totalFabBudget = (float) $fabBudget->fetchColumn();

        // Actual fabrication paid
        $fabPaid = $this->db->prepare(
            "SELECT COALESCE(SUM(paid_amount), 0) FROM pal_fabrication_weekly_dues WHERE tenant_id = :tid"
        );
        $fabPaid->execute([':tid' => $this->tenantId]);
        $totalFabPaid = (float) $fabPaid->fetchColumn();

        // Outstanding fabrication dues
        $fabDues = $this->db->prepare(
            "SELECT COALESCE(SUM(balance), 0) FROM pal_fabrication_weekly_dues
             WHERE tenant_id = :tid AND status NOT IN ('paid','waived')"
        );
        $fabDues->execute([':tid' => $this->tenantId]);
        $outstandingFabDues = (float) $fabDues->fetchColumn();

        // Approved collections
        $collected = $this->db->prepare(
            "SELECT COALESCE(SUM(amount), 0) FROM pal_collections
             WHERE tenant_id = :tid AND status = 'approved'"
        );
        $collected->execute([':tid' => $this->tenantId]);
        $totalCollected = (float) $collected->fetchColumn();

        // Outstanding receivables
        $outstanding = $this->db->prepare(
            "SELECT COALESCE(SUM(s.net_amount - COALESCE(
                (SELECT SUM(c.amount) FROM pal_collections c
                 WHERE c.sales_id = s.id AND c.status = 'approved'), 0)), 0)
             FROM pal_sales s WHERE s.tenant_id = :tid AND s.status NOT IN ('cancelled','voided')"
        );
        $outstanding->execute([':tid' => $this->tenantId]);
        $outstandingRecv = (float) $outstanding->fetchColumn();

        return [
            'contract_value'         => $totalContract,
            'expenses'               => $totalExpenses,
            'fabrication_budget'     => $totalFabBudget,
            'fabrication_paid'       => $totalFabPaid,
            'fabrication'            => $totalFabBudget, // main KPI uses budgeted
            'total_costs'            => $totalExpenses + $totalFabBudget,
            'collected'              => $totalCollected,
            'outstanding_receivables' => $outstandingRecv,
            'outstanding_fab_dues'   => $outstandingFabDues,
            'estimated_margin'       => $totalContract > 0
                ? round(($totalContract - $totalExpenses - $totalFabBudget) / $totalContract * 100, 1)
                : 0,
        ];
    }

    // ── Cash Flow (this month) ──

    /** @return array<string,mixed> */
    private function cashFlow(): array
    {
        $monthlyCol = $this->db->prepare(
            "SELECT COALESCE(SUM(amount), 0) FROM pal_collections
             WHERE tenant_id = :tid AND status = 'approved'
             AND MONTH(payment_date) = MONTH(CURRENT_DATE) AND YEAR(payment_date) = YEAR(CURRENT_DATE)"
        );
        $monthlyCol->execute([':tid' => $this->tenantId]);
        $collections = (float) $monthlyCol->fetchColumn();

        $monthlyExp = $this->db->prepare(
            "SELECT COALESCE(SUM(amount), 0) FROM pal_expenses
             WHERE tenant_id = :tid AND status IN ('approved','paid')
             AND MONTH(expense_date) = MONTH(CURRENT_DATE) AND YEAR(expense_date) = YEAR(CURRENT_DATE)"
        );
        $monthlyExp->execute([':tid' => $this->tenantId]);
        $expenses = (float) $monthlyExp->fetchColumn();

        // Monthly fabrication: actual paid_amount from weekly dues this month
        $monthlyFab = $this->db->prepare(
            "SELECT COALESCE(SUM(paid_amount), 0) FROM pal_fabrication_weekly_dues
             WHERE tenant_id = :tid
             AND MONTH(week_start) = MONTH(CURRENT_DATE) AND YEAR(week_start) = YEAR(CURRENT_DATE)"
        );
        $monthlyFab->execute([':tid' => $this->tenantId]);
        $fabrication = (float) $monthlyFab->fetchColumn();

        return [
            'collections' => $collections,
            'expenses'    => $expenses,
            'fabrication' => $fabrication,
            'net'         => $collections - $expenses - $fabrication,
        ];
    }

    // ── Pending Approvals ──

    /** @return array<string,mixed> */
    private function pendingApprovals(): array
    {
        $count = $this->db->prepare(
            "SELECT COUNT(*) FROM pal_approvals WHERE tenant_id = :tid AND decision = 'pending'"
        );
        $count->execute([':tid' => $this->tenantId]);

        $items = $this->db->prepare(
            "SELECT a.id, a.entity_type, a.submitted_by, a.submitted_at AS created_at,
                    CASE a.entity_type
                        WHEN 'expense' THEN (SELECT description FROM pal_expenses WHERE id = a.entity_id)
                        WHEN 'purchase' THEN (SELECT purchase_number FROM pal_purchases WHERE id = a.entity_id)
                        ELSE '\u2014' END AS description
             FROM pal_approvals a
             WHERE a.tenant_id = :tid AND a.decision = 'pending'
             ORDER BY a.submitted_at DESC LIMIT 10"
        );
        $items->execute([':tid' => $this->tenantId]);

        return [
            'count' => (int) $count->fetchColumn(),
            'items' => $items->fetchAll(PDO::FETCH_ASSOC),
        ];
    }

    // ── Low Stock ──

    /** @return array<string,mixed> */
    private function lowStock(): array
    {
        $stmt = $this->db->prepare(
            "SELECT m.name, m.material_code, b.quantity, m.reorder_level, u.name AS unit
             FROM pal_materials m
             JOIN pal_inventory_balances b ON m.id = b.material_id
             LEFT JOIN pal_units u ON m.unit_id = u.id
             WHERE m.tenant_id = :tid AND m.reorder_level IS NOT NULL
             AND b.quantity <= m.reorder_level
             ORDER BY (b.quantity / NULLIF(m.reorder_level, 0)) ASC
             LIMIT 10"
        );
        $stmt->execute([':tid' => $this->tenantId]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'count' => count($items),
            'items' => $items,
        ];
    }

    // ── Recent Activity ──

    /** @return array<string,mixed> */
    private function recentActivity(): array
    {
        $projects = $this->db->prepare(
            "SELECT id, project_id, title, status, contract_amount, created_at
             FROM pal_projects WHERE tenant_id = :tid ORDER BY created_at DESC LIMIT 5"
        );
        $projects->execute([':tid' => $this->tenantId]);

        $expenses = $this->db->prepare(
            "SELECT e.id, e.description, e.amount, e.expense_date, e.status, ec.name AS category_name
             FROM pal_expenses e LEFT JOIN pal_expense_categories ec ON e.category_id = ec.id
             WHERE e.tenant_id = :tid ORDER BY e.created_at DESC LIMIT 5"
        );
        $expenses->execute([':tid' => $this->tenantId]);

        $collections = $this->db->prepare(
            "SELECT c.id, c.collection_number, c.amount, c.payment_date, c.payment_method,
                    p.title AS project_title
             FROM pal_collections c LEFT JOIN pal_projects p ON c.project_id = p.id
             WHERE c.tenant_id = :tid ORDER BY c.created_at DESC LIMIT 5"
        );
        $collections->execute([':tid' => $this->tenantId]);

        return [
            'projects'    => $projects->fetchAll(PDO::FETCH_ASSOC),
            'expenses'    => $expenses->fetchAll(PDO::FETCH_ASSOC),
            'collections' => $collections->fetchAll(PDO::FETCH_ASSOC),
        ];
    }
}
