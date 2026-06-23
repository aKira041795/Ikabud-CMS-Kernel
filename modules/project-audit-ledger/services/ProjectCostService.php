<?php

declare(strict_types=1);

/**
 * Domain service for project cost calculations.
 */
class palProjectCostService
{
    private Ikabud\Kernel\Contracts\ModuleDB $db;
    private int $tenantId;

    public function __construct(Ikabud\Kernel\Contracts\ModuleDB $db, int $tenantId)
    {
        $this->db = $db;
        $this->tenantId = $tenantId;
    }

    /**
     * Get full cost breakdown for a project.
     */
    public function getCostBreakdown(int $projectId): array
    {
        // Total approved expenses
        $totalExpenses = $this->sumApprovedExpenses($projectId);

        // Total material costs from issued items
        $totalMaterials = $this->sumIssuedMaterials($projectId);

        // Total approved purchases
        $totalPurchases = $this->sumApprovedPurchases($projectId);

        // Cost by category
        $expenseByCategory = $this->expensesByCategory($projectId);

        $totalCost = $totalExpenses + $totalMaterials + $totalPurchases;

        return [
            'total_cost' => $totalCost,
            'total_expenses' => $totalExpenses,
            'total_materials' => $totalMaterials,
            'total_purchases' => $totalPurchases,
            'expense_by_category' => $expenseByCategory,
        ];
    }

    /**
     * Calculate project profitability.
     */
    public function getProfitability(int $projectId): array
    {
        $project = $this->getProjectContractAndSales($projectId);
        if ($project === null) {
            return [
                'contract_amount' => 0,
                'net_sales' => 0,
                'total_cost' => 0,
                'estimated_profit' => 0,
                'profit_margin' => 0,
                'total_collected' => 0,
                'outstanding' => 0,
            ];
        }

        $cost = $this->getCostBreakdown($projectId);

        $netSales = (float)$project['net_sales'];
        $totalCost = $cost['total_cost'];

        $estimatedProfit = $netSales - $totalCost;
        $profitMargin = $netSales > 0 ? round(($estimatedProfit / $netSales) * 100, 2) : 0;

        return [
            'contract_amount' => (float)$project['contract_amount'],
            'net_sales' => $netSales,
            'total_cost' => $totalCost,
            'estimated_profit' => round($estimatedProfit, 2),
            'profit_margin' => $profitMargin,
            'total_collected' => (float)$project['total_collected'],
            'outstanding' => max(0, $netSales - (float)$project['total_collected']),
        ];
    }

    /**
     * Check budget threshold.
     */
    public function getBudgetStatus(int $projectId): array
    {
        $stmt = $this->db->prepare(
            'SELECT contract_amount, budget_warning_pct FROM pal_projects WHERE id = :id AND tenant_id = :tenant_id'
        );
        $stmt->execute([':id' => $projectId, ':tenant_id' => $this->tenantId]);
        $project = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$project || (float)$project['contract_amount'] <= 0) {
            return ['status' => 'normal', 'pct_used' => 0, 'remaining' => 0];
        }

        $cost = $this->getCostBreakdown($projectId);
        $contract = (float)$project['contract_amount'];
        $warningPct = (float)$project['budget_warning_pct'];
        $pctUsed = round(($cost['total_cost'] / $contract) * 100, 2);

        if ($pctUsed >= 100) {
            $status = 'over_budget';
        } elseif ($pctUsed >= $warningPct) {
            $status = 'near_budget';
        } else {
            $status = 'normal';
        }

        return [
            'status' => $status,
            'pct_used' => $pctUsed,
            'remaining' => round(max(0, $contract - $cost['total_cost']), 2),
            'contract_amount' => $contract,
            'warning_pct' => $warningPct,
        ];
    }

    private function sumApprovedExpenses(int $projectId): float
    {
        $stmt = $this->db->prepare(
            "SELECT COALESCE(SUM(amount), 0) FROM pal_expenses
             WHERE project_id = :project_id AND tenant_id = :tenant_id
             AND status = 'approved'"
        );
        $stmt->execute([':project_id' => $projectId, ':tenant_id' => $this->tenantId]);
        return (float)$stmt->fetchColumn();
    }

    private function sumApprovedPurchases(int $projectId): float
    {
        $stmt = $this->db->prepare(
            "SELECT COALESCE(SUM(total_amount), 0) FROM pal_purchases
             WHERE project_id = :project_id AND tenant_id = :tenant_id
             AND status = 'approved'"
        );
        $stmt->execute([':project_id' => $projectId, ':tenant_id' => $this->tenantId]);
        return (float)$stmt->fetchColumn();
    }

    private function sumIssuedMaterials(int $projectId): float
    {
        $stmt = $this->db->prepare(
            "SELECT COALESCE(SUM(mii.total_cost), 0)
             FROM pal_material_issuance_items mii
             JOIN pal_material_issuances mi ON mii.issuance_id = mi.id
             WHERE mi.project_id = :project_id AND mi.tenant_id = :tenant_id
             AND mi.status IN ('fully_issued', 'partially_issued')"
        );
        $stmt->execute([':project_id' => $projectId, ':tenant_id' => $this->tenantId]);
        return (float)$stmt->fetchColumn();
    }

    private function expensesByCategory(int $projectId): array
    {
        $stmt = $this->db->prepare(
            "SELECT ec.name AS category, COALESCE(SUM(e.amount), 0) AS total
             FROM pal_expenses e
             LEFT JOIN pal_expense_categories ec ON e.category_id = ec.id
             WHERE e.project_id = :project_id AND e.tenant_id = :tenant_id
             AND e.status = 'approved'
             GROUP BY ec.name
             ORDER BY total DESC"
        );
        $stmt->execute([':project_id' => $projectId, ':tenant_id' => $this->tenantId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function getProjectContractAndSales(int $projectId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT p.contract_amount,
                    COALESCE(s.net_sales, 0) AS net_sales,
                    COALESCE(c.total_collected, 0) AS total_collected
             FROM pal_projects p
             LEFT JOIN (
                 SELECT project_id, SUM(net_amount) AS net_sales
                 FROM pal_sales WHERE status IN ('issued', 'partially_paid', 'paid')
                 GROUP BY project_id
             ) s ON p.id = s.project_id
             LEFT JOIN (
                 SELECT project_id, SUM(amount) AS total_collected
                 FROM pal_collections WHERE status = 'approved'
                 GROUP BY project_id
             ) c ON p.id = c.project_id
             WHERE p.id = :id AND p.tenant_id = :tenant_id"
        );
        $stmt->execute([':id' => $projectId, ':tenant_id' => $this->tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }
}
