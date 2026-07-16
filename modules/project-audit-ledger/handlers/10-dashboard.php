<?php

declare(strict_types=1);

use Ikabud\Modules\ProjectAuditLedger\Presentation\PalDashboardViewModel;

function palPageDashboard(): void
{
    $user = palCurrentUser();
    $tid = (int)($user['tenant_id'] ?? 0);

    // Use the new typed view model — all metrics are real, no hardcoded zeros
    $vm = PalDashboardViewModel::fromServices(palDb(), $tid);
    $ctx = $vm->toTemplateContext();

    $pp = $ctx['project_pipeline'];
    $fin = $ctx['financials'];
    $cf = $ctx['cash_flow'];
    $pa = $ctx['pending_approvals'];
    $ls = $ctx['low_stock'];
    $rec = $ctx['recent_projects'];

    palRender(__DIR__ . '/../templates/project-audit-ledger/shell.disyl', [
        'current_user' => $user,
        'page_title'   => 'Dashboard',
        'page_content' => 'dashboard',
        'dashboard'    => [
            'active_projects'             => $pp['active'] ?? 0,
            'total_projects'              => $pp['total'] ?? 0,
            'project_status_breakdown'    => $pp['by_status'] ?? [],
            'total_contract_value'        => $fin['contract_value'] ?? 0,
            'total_expenses'              => $fin['expenses'] ?? 0,
            'total_fabrication'           => $fin['fabrication'] ?? 0,
            'total_fabrication_budget'    => $fin['fabrication_budget'] ?? 0,
            'total_fabrication_paid'      => $fin['fabrication_paid'] ?? 0,
            'total_costs'                 => $fin['total_costs'] ?? 0,
            'total_collected'             => $fin['collected'] ?? 0,
            'outstanding_receivables'     => $fin['outstanding_receivables'] ?? 0,
            'outstanding_fabrication_dues'=> $fin['outstanding_fab_dues'] ?? 0,
            'monthly_collections'         => $cf['collections'] ?? 0,
            'monthly_expenses'            => $cf['expenses'] ?? 0,
            'monthly_fabrication'         => $cf['fabrication'] ?? 0,
            'monthly_total_outflow'       => $cf['total_outflow'] ?? 0,
            'net_cash_flow'               => $cf['net'] ?? 0,
            'pending_approvals'           => count($pa),
            'low_stock_count'             => count($ls),
            'low_stock_items'             => $ls,
            'recent_projects'             => $rec,
            'recent_expenses'             => $ctx['recent_expenses'] ?? [],
            'recent_collections'          => $ctx['recent_collections'] ?? [],
            'pending_approval_items'      => $pa,
            'est_profit'                  => max(0, ($fin['contract_value'] ?? 0) - ($fin['total_costs'] ?? 0)),
        ],
    ]);
}
