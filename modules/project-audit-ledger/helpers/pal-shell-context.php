<?php

declare(strict_types=1);

/**
 * palShellContext — builds the ApplicationShellViewModel data for PAL.
 *
 * Replaces the hardcoded navigation in shell.disyl with structured data
 * consumed by workbench:app_shell.
 *
 * @param array $user Current user from palCurrentUser()
 * @param string $pageContent Active page_content identifier
 * @return array<string,mixed> Shell context for workbench:app_shell
 */
function palShellContext(array $user, string $pageContent = ''): array
{
    $pc = $pageContent;

    return [
        'application_name' => $user['pal_app_name'] ?? 'Project Audit Ledger',
        'logo_url'         => !empty($user['pal_logo_path']) ? '/' . $user['pal_logo_path'] : null,
        'user_display'     => $user['full_name'] ?? '',
        'page_title'       => '', // set per-page

        'navigation_sections' => [
            [
                'label'            => 'Overview',
                'collapsed_default' => false,
                'items' => [
                    ['label' => 'Dashboard',    'url' => '/admin/project-audit-ledger',                    'icon_key' => '📊', 'is_active' => $pc === 'dashboard'],
                    ['label' => 'New Job Order', 'url' => '/admin/project-audit-ledger/projects/create',   'icon_key' => '➕', 'is_active' => $pc === 'project-form'],
                ],
            ],
            [
                'label'            => 'Job Orders',
                'collapsed_default' => false,
                'items' => [
                    ['label' => 'All Job Orders', 'url' => '/admin/project-audit-ledger/projects',  'icon_key' => '📋', 'is_active' => $pc === 'project-list'],
                    ['label' => 'Clients',         'url' => '/admin/project-audit-ledger/clients',   'icon_key' => '👤', 'is_active' => $pc === 'client-list' || $pc === 'client-form'],
                    ['label' => 'Suppliers',       'url' => '/admin/project-audit-ledger/suppliers', 'icon_key' => '🏭', 'is_active' => $pc === 'supplier-list' || $pc === 'supplier-form'],
                ],
            ],
            [
                'label'            => 'Sales & Billing',
                'collapsed_default' => false,
                'items' => [
                    ['label' => 'Sales Invoices',  'url' => '/admin/project-audit-ledger/sales',       'icon_key' => '💰', 'is_active' => $pc === 'sales-list' || $pc === 'sales-form'],
                    ['label' => 'Collections',     'url' => '/admin/project-audit-ledger/collections', 'icon_key' => '💵', 'is_active' => $pc === 'collection-list' || $pc === 'collection-form'],
                    ['label' => 'Quotations',      'url' => '/admin/project-audit-ledger/quotations',  'icon_key' => '📝', 'is_active' => $pc === 'quotation-list' || $pc === 'quotation-form'],
                    ['label' => 'Bill of Materials','url' => '/admin/project-audit-ledger/bom',         'icon_key' => '📋', 'is_active' => $pc === 'bom-list' || $pc === 'bom-form'],
                ],
            ],
            [
                'label'            => 'Inventory & Procurement',
                'collapsed_default' => false,
                'items' => [
                    ['label' => 'Inventory',        'url' => '/admin/project-audit-ledger/inventory',           'icon_key' => '📦', 'is_active' => $pc === 'inventory-list'],
                    ['label' => 'Stock Movements',   'url' => '/admin/project-audit-ledger/inventory/movements', 'icon_key' => '📤', 'is_active' => $pc === 'movement-list'],
                    ['label' => 'Purchases',         'url' => '/admin/project-audit-ledger/purchases',          'icon_key' => '🛒', 'is_active' => $pc === 'purchase-list' || $pc === 'purchase-form'],
                    ['label' => 'Material Issuances','url' => '/admin/project-audit-ledger/issuances',          'icon_key' => '📤', 'is_active' => $pc === 'issuance-list'],
                    ['label' => 'Material Returns',  'url' => '/admin/project-audit-ledger/issuances/returns',  'icon_key' => '↩',  'is_active' => $pc === 'material-return-list'],
                ],
            ],
            [
                'label'            => 'Operations',
                'collapsed_default' => false,
                'items' => [
                    ['label' => 'Expenses',             'url' => '/admin/project-audit-ledger/expenses',              'icon_key' => '💳', 'is_active' => $pc === 'expense-list' || $pc === 'expense-form'],
                    ['label' => 'Cash Advances',        'url' => '/admin/project-audit-ledger/cash-advances',         'icon_key' => '💵', 'is_active' => $pc === 'cash-advances-list'],
                    ['label' => 'Fabrication Allocations','url' => '/admin/project-audit-ledger/fabrication/allocations','icon_key' => '🔧', 'is_active' => $pc === 'fabrication-allocations'],
                    ['label' => 'Mobilization',         'url' => '/admin/project-audit-ledger/mobilization',           'icon_key' => '🚛', 'is_active' => $pc === 'mobilization-list'],
                ],
            ],
            [
                'label'            => 'Oversight',
                'collapsed_default' => false,
                'items' => [
                    ['label' => 'Approvals', 'url' => '/admin/project-audit-ledger/approvals',   'icon_key' => '✅', 'is_active' => $pc === 'approval-queue'],
                    ['label' => 'Reports',   'url' => '/admin/project-audit-ledger/reports',     'icon_key' => '📊', 'is_active' => $pc === 'reports-center'],
                    ['label' => 'Audit Trail','url' => '/admin/project-audit-ledger/audit-trail','icon_key' => '🔍', 'is_active' => $pc === 'audit-trail'],
                ],
            ],
            [
                'label'            => 'Administration',
                'collapsed_default' => false,
                'items' => [
                    ['label' => 'Settings', 'url' => '/admin/project-audit-ledger/settings', 'icon_key' => '⚙', 'is_active' => $pc === 'settings-overview'],
                    ['label' => 'Users',    'url' => '/admin/project-audit-ledger/users',    'icon_key' => '👥', 'is_active' => $pc === 'users-list'],
                ],
            ],
        ],

        'user_actions' => [
            ['label' => 'Sign Out', 'url' => '/api/v1/project-audit-ledger/logout', 'icon_key' => '🚪'],
        ],

        'mobile_navigation' => [
            ['label' => 'Home',      'url' => '/admin/project-audit-ledger',                  'icon_key' => '📊', 'is_active' => $pc === 'dashboard'],
            ['label' => 'Projects',  'url' => '/admin/project-audit-ledger/projects',         'icon_key' => '📋', 'is_active' => $pc === 'project-list'],
            ['label' => 'Sales',     'url' => '/admin/project-audit-ledger/sales',            'icon_key' => '💰', 'is_active' => $pc === 'sales-list'],
            ['label' => 'Inventory', 'url' => '/admin/project-audit-ledger/inventory',        'icon_key' => '📦', 'is_active' => $pc === 'inventory-list'],
            ['label' => 'Approvals', 'url' => '/admin/project-audit-ledger/approvals',        'icon_key' => '✅', 'is_active' => $pc === 'approval-queue'],
        ],

        'extra_styles' => [
            '/assets/pal/pal-ui.css',
        ],

        'extra_scripts' => [
            'https://cdn.tailwindcss.com',
            'https://unpkg.com/htmx.org@1.9.12',
            'https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js',
            '/assets/pal/pal-core.js',
            '/assets/pal/pal-forms.js',
            '/assets/pal/pal-routes.js',
        ],
    ];
}
