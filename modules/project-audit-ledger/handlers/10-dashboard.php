<?php

declare(strict_types=1);

function palPageDashboard(): void
{
    $user = palCurrentUser();
    $tid = (int)($user['tenant_id'] ?? 0);

    $vm = new palDashboardViewModel(palDb(), $tid);
    $d = $vm->toArray();

    palRender(__DIR__ . '/../templates/project-audit-ledger/shell.disyl', [
        'current_user' => $user,
        'page_title'   => 'Dashboard',
        'page_content' => 'dashboard',
        'dashboard'    => [
            'active_projects'             => $d['project_pipeline']['active'],
            'total_projects'              => $d['project_pipeline']['total'],
            'project_status_breakdown'    => $d['project_pipeline']['by_status'],
            'total_contract_value'        => $d['financials']['contract_value'],
            'total_expenses'              => $d['financials']['expenses'],
            'total_fabrication'           => $d['financials']['fabrication'],
            'total_fabrication_budget'    => $d['financials']['fabrication_budget'],
            'total_fabrication_paid'      => $d['financials']['fabrication_paid'],
            'total_costs'                 => $d['financials']['total_costs'],
            'total_collected'             => $d['financials']['collected'],
            'outstanding_receivables'     => $d['financials']['outstanding_receivables'],
            'outstanding_fabrication_dues'=> $d['financials']['outstanding_fab_dues'],
            'monthly_collections'         => $d['cash_flow']['collections'],
            'monthly_expenses'            => $d['cash_flow']['expenses'],
            'monthly_fabrication'         => $d['cash_flow']['fabrication'],
            'monthly_total_outflow'       => $d['cash_flow']['expenses'] + $d['cash_flow']['fabrication'],
            'net_cash_flow'               => $d['cash_flow']['net'],
            'pending_approvals'           => $d['pending_approvals']['count'],
            'low_stock_count'             => $d['low_stock']['count'],
            'low_stock_items'             => $d['low_stock']['items'],
            'recent_projects'             => $d['recent']['projects'],
            'recent_expenses'             => $d['recent']['expenses'],
            'recent_collections'          => $d['recent']['collections'],
            'pending_approval_items'      => $d['pending_approvals']['items'],
            'est_profit'                  => max(0, $d['financials']['contract_value'] - $d['financials']['expenses'] - $d['financials']['fabrication']),
        ],
    ]);
}
