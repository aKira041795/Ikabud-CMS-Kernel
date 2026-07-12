<?php

declare(strict_types=1);

use Ikabud\Modules\ProjectAuditLedger\Presentation\PalDashboardViewModel;
use Ikabud\Modules\ProjectAuditLedger\Presentation\PalStatusPresenter;

function palPageDashboard(): void
{
    $user = palCurrentUser();
    $tid = (int)($user['tenant_id'] ?? 0);

    // Use the new typed view model
    $vm = PalDashboardViewModel::fromServices(palDb(), $tid);
    $ctx = $vm->toTemplateContext();

    // Bridge: produce legacy dashboard array for template compatibility
    $pp = $ctx['project_pipeline'];
    $fin = $ctx['financials'];
    $cf = $ctx['cash_flow'];
    $pa = $ctx['pending_approvals'];
    $ls = $ctx['low_stock'];
    $rec = $ctx['recent_activity'];

    palRender(__DIR__ . '/../templates/project-audit-ledger/shell.disyl', [
        'current_user' => $user,
        'page_title'   => 'Dashboard',
        'page_content' => 'dashboard',
        'dashboard'    => [
            'active_projects'             => $pp['active'] ?? 0,
            'total_projects'              => $pp['total'] ?? 0,
            'project_status_breakdown'    => $pp['by_status'] ?? [],
            'total_contract_value'        => $fin['total_contract'] ?? 0,
            'total_expenses'              => $fin['total_expenses'] ?? 0,
            'total_fabrication'           => 0,
            'total_fabrication_budget'    => 0,
            'total_fabrication_paid'      => 0,
            'total_costs'                 => $fin['total_expenses'] ?? 0,
            'total_collected'             => $cf['collected'] ?? 0,
            'outstanding_receivables'     => $cf['outstanding'] ?? 0,
            'outstanding_fabrication_dues'=> 0,
            'monthly_collections'         => $cf['collected'] ?? 0,
            'monthly_expenses'            => $fin['total_expenses'] ?? 0,
            'monthly_fabrication'         => 0,
            'monthly_total_outflow'       => $fin['total_expenses'] ?? 0,
            'net_cash_flow'               => ($cf['collected'] ?? 0) - ($fin['total_expenses'] ?? 0),
            'pending_approvals'           => count($pa),
            'low_stock_count'             => count($ls),
            'low_stock_items'             => $ls,
            'recent_projects'             => $rec,
            'recent_expenses'             => [],
            'recent_collections'          => [],
            'pending_approval_items'      => $pa,
            'est_profit'                  => max(0, ($fin['total_contract'] ?? 0) - ($fin['total_expenses'] ?? 0)),
        ],
    ]);
}
