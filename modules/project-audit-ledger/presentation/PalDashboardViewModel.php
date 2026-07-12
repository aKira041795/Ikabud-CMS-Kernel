<?php

declare(strict_types=1);

namespace Ikabud\Modules\ProjectAuditLedger\Presentation;

/**
 * PalDashboardViewModel — encapsulates all dashboard data for the template.
 *
 * Replaces ad-hoc query construction in palPageDashboard() with a typed,
 * testable view model. The handler resolves this model and passes
 * toTemplateContext() to the template.
 */
final readonly class PalDashboardViewModel implements TemplateViewModel
{
    /** @param array<string,mixed> $data Aggregated dashboard data from services */
    public function __construct(
        private array $data,
    ) {}

    /** @return array<string,mixed> */
    public function toTemplateContext(): array
    {
        return [
            'project_pipeline'  => $this->data['project_pipeline'] ?? [],
            'financials'        => $this->data['financials'] ?? [],
            'cash_flow'         => $this->data['cash_flow'] ?? [],
            'pending_approvals' => $this->data['pending_approvals'] ?? [],
            'low_stock'         => $this->data['low_stock'] ?? [],
            'recent_activity'   => $this->data['recent'] ?? [],
        ];
    }

    /**
     * Build from services (replaces inline queries in the handler).
     */
    public static function fromServices(
        \Ikabud\Kernel\Contracts\ModuleDB $db,
        int $tenantId,
    ): self {
        return new self([
            'project_pipeline'  => self::projectPipeline($db, $tenantId),
            'financials'        => self::financials($db, $tenantId),
            'cash_flow'         => self::cashFlow($db, $tenantId),
            'pending_approvals' => self::pendingApprovals($db, $tenantId),
            'low_stock'         => self::lowStock($db, $tenantId),
            'recent'            => self::recentActivity($db, $tenantId),
        ]);
    }

    /** @return array<string,mixed> */
    private static function projectPipeline(\Ikabud\Kernel\Contracts\ModuleDB $db, int $tenantId): array
    {
        $active = $db->prepare(
            "SELECT COUNT(*) FROM pal_projects WHERE tenant_id = :tid AND status IN ('approved','started','ongoing')"
        );
        $active->execute([':tid' => $tenantId]);

        $total = $db->prepare("SELECT COUNT(*) FROM pal_projects WHERE tenant_id = :tid");
        $total->execute([':tid' => $tenantId]);

        $byStatus = $db->prepare(
            "SELECT status, COUNT(*) AS cnt FROM pal_projects WHERE tenant_id = :tid GROUP BY status ORDER BY cnt DESC"
        );
        $byStatus->execute([':tid' => $tenantId]);

        return [
            'active'    => (int)$active->fetchColumn(),
            'total'     => (int)$total->fetchColumn(),
            'by_status' => $byStatus->fetchAll(\PDO::FETCH_ASSOC),
        ];
    }

    /** @return array<string,mixed> */
    private static function financials(\Ikabud\Kernel\Contracts\ModuleDB $db, int $tenantId): array
    {
        $contract = $db->prepare(
            "SELECT COALESCE(SUM(contract_amount), 0) FROM pal_projects
             WHERE tenant_id = :tid AND status NOT IN ('cancelled','closed')"
        );
        $contract->execute([':tid' => $tenantId]);
        $totalContract = (float)$contract->fetchColumn();

        $expenses = $db->prepare(
            "SELECT COALESCE(SUM(amount), 0) FROM pal_expenses
             WHERE tenant_id = :tid AND status IN ('approved','paid')"
        );
        $expenses->execute([':tid' => $tenantId]);
        $totalExpenses = (float)$expenses->fetchColumn();

        // Fabrication budget: contract_amount × allocation percentage
        $fabBudget = $db->prepare(
            "SELECT COALESCE(SUM(ROUND(contract_amount * COALESCE(fabrication_alloc_pct, 0) / 100, 2)), 0)
             FROM pal_projects WHERE tenant_id = :tid AND status NOT IN ('cancelled','closed')
             AND fabrication_alloc_pct > 0"
        );
        $fabBudget->execute([':tid' => $tenantId]);
        $totalFabBudget = (float)$fabBudget->fetchColumn();

        // Actual fabrication paid
        $fabPaid = $db->prepare(
            "SELECT COALESCE(SUM(paid_amount), 0) FROM pal_fabrication_weekly_dues WHERE tenant_id = :tid"
        );
        $fabPaid->execute([':tid' => $tenantId]);
        $totalFabPaid = (float)$fabPaid->fetchColumn();

        // Outstanding fabrication dues
        $fabDues = $db->prepare(
            "SELECT COALESCE(SUM(balance), 0) FROM pal_fabrication_weekly_dues
             WHERE tenant_id = :tid AND balance > 0"
        );
        $fabDues->execute([':tid' => $tenantId]);
        $outstandingFabDues = (float)$fabDues->fetchColumn();

        // Total costs (expenses + fabrication paid)
        $totalCosts = $totalExpenses + $totalFabPaid;

        // Receivables
        $collected = $db->prepare(
            "SELECT COALESCE(SUM(amount), 0) FROM pal_collections WHERE tenant_id = :tid AND status = 'completed'"
        );
        $collected->execute([':tid' => $tenantId]);
        $totalCollected = (float)$collected->fetchColumn();

        $outstanding = $db->prepare(
            "SELECT COALESCE(SUM(balance), 0) FROM pal_receivables WHERE tenant_id = :tid AND status != 'paid'"
        );
        $outstanding->execute([':tid' => $tenantId]);
        $outstandingReceivables = (float)$outstanding->fetchColumn();

        return [
            'contract_value'          => $totalContract,
            'expenses'                => $totalExpenses,
            'fabrication'             => $totalFabPaid,
            'fabrication_budget'      => $totalFabBudget,
            'fabrication_paid'        => $totalFabPaid,
            'total_costs'             => $totalCosts,
            'collected'               => $totalCollected,
            'outstanding_receivables'  => $outstandingReceivables,
            'outstanding_fab_dues'    => $outstandingFabDues,
        ];
    }

    /** @return array<string,mixed> */
    private static function cashFlow(\Ikabud\Kernel\Contracts\ModuleDB $db, int $tenantId): array
    {
        $monthStart = date('Y-m-01');

        $collections = $db->prepare(
            "SELECT COALESCE(SUM(amount), 0) FROM pal_collections
             WHERE tenant_id = :tid AND status = 'completed' AND collection_date >= :month_start"
        );
        $collections->execute([':tid' => $tenantId, ':month_start' => $monthStart]);
        $monthlyCollections = (float)$collections->fetchColumn();

        $expenses = $db->prepare(
            "SELECT COALESCE(SUM(amount), 0) FROM pal_expenses
             WHERE tenant_id = :tid AND status IN ('approved','paid') AND expense_date >= :month_start"
        );
        $expenses->execute([':tid' => $tenantId, ':month_start' => $monthStart]);
        $monthlyExpenses = (float)$expenses->fetchColumn();

        $fabrication = $db->prepare(
            "SELECT COALESCE(SUM(paid_amount), 0) FROM pal_fabrication_weekly_dues
             WHERE tenant_id = :tid AND payment_date >= :month_start"
        );
        $fabrication->execute([':tid' => $tenantId, ':month_start' => $monthStart]);
        $monthlyFabrication = (float)$fabrication->fetchColumn();

        $totalOutflow = $monthlyExpenses + $monthlyFabrication;
        $netFlow = $monthlyCollections - $totalOutflow;

        return [
            'collections'  => $monthlyCollections,
            'expenses'     => $monthlyExpenses,
            'fabrication'  => $monthlyFabrication,
            'total_outflow' => $totalOutflow,
            'net'          => $netFlow,
        ];
    }

    /** @return array<int, array<string,mixed>> */
    private static function pendingApprovals(\Ikabud\Kernel\Contracts\ModuleDB $db, int $tenantId): array
    {
        $stmt = $db->prepare(
            "SELECT entity_type, entity_id, submitted_by, submitted_at
             FROM pal_approvals WHERE tenant_id = :tid AND status = 'pending'
             ORDER BY submitted_at DESC LIMIT 10"
        );
        $stmt->execute([':tid' => $tenantId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<int, array<string,mixed>> */
    private static function lowStock(\Ikabud\Kernel\Contracts\ModuleDB $db, int $tenantId): array
    {
        $stmt = $db->prepare(
            "SELECT name, quantity, reorder_level FROM pal_materials
             WHERE tenant_id = :tid AND quantity <= reorder_level
             ORDER BY quantity ASC LIMIT 5"
        );
        $stmt->execute([':tid' => $tenantId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<int, array<string,mixed>> */
    private static function recentActivity(\Ikabud\Kernel\Contracts\ModuleDB $db, int $tenantId): array
    {
        $stmt = $db->prepare(
            "SELECT action, entity_type, entity_id, created_at, user_name
             FROM audit_logs WHERE tenant_id = :tid
             ORDER BY created_at DESC LIMIT 10"
        );
        $stmt->execute([':tid' => $tenantId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }
}
