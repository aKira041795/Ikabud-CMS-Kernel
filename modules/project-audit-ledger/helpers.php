<?php

declare(strict_types=1);

require_once __DIR__ . '/testing/ScenarioCapabilityProvider.php';

app()->registerAuthTable('project-audit-ledger', 'pal_users');

/**
 * Register all capability handlers for the project-audit-ledger module.
 * Uses the standard naming convention: {module_prefix}_capability_handlers()
 * Module prefix derived from 'project-audit-ledger' → 'project_audit_ledger'
 */
function project_audit_ledger_capability_handlers(): array
{
    return [
        'kernel.auth.authenticate@1' => 'pal_cap_kernel_auth_authenticate_1',
        'workbench.scenario.describe@1' => 'pal_cap_workbench_scenario_describe_1',
        'workbench.scenario.seed@1' => 'pal_cap_workbench_scenario_seed_1',
        'workbench.scenario.verify@1' => 'pal_cap_workbench_scenario_verify_1',
        'workbench.scenario.cleanup@1' => 'pal_cap_workbench_scenario_cleanup_1',
        'pal.read@1' => 'pal_cap_read_1',
        'pal.manage@1' => 'pal_cap_manage_1',
        'pal.projects.read@1' => 'pal_cap_projects_read_1',
        'pal.projects.write@1' => 'pal_cap_projects_write_1',
        'pal.expenses.read@1' => 'pal_cap_expenses_read_1',
        'pal.expenses.write@1' => 'pal_cap_expenses_write_1',
        'pal.inventory.read@1' => 'pal_cap_inventory_read_1',
        'pal.inventory.write@1' => 'pal_cap_inventory_write_1',
        'pal.purchases.read@1' => 'pal_cap_purchases_read_1',
        'pal.purchases.write@1' => 'pal_cap_purchases_write_1',
        'pal.sales.read@1' => 'pal_cap_sales_read_1',
        'pal.sales.write@1' => 'pal_cap_sales_write_1',
        'pal.collections.read@1' => 'pal_cap_collections_read_1',
        'pal.collections.write@1' => 'pal_cap_collections_write_1',
        'pal.fabrication.read@1' => 'pal_cap_fabrication_read_1',
        'pal.fabrication.write@1' => 'pal_cap_fabrication_write_1',
        'pal.approvals.read@1' => 'pal_cap_approvals_read_1',
        'pal.approvals.write@1' => 'pal_cap_approvals_write_1',
        'pal.reports.read@1' => 'pal_cap_reports_read_1',
        'pal.audit.read@1' => 'pal_cap_audit_read_1',
        'pal.settings.read@1' => 'pal_cap_settings_read_1',
        'pal.settings.write@1' => 'pal_cap_settings_write_1',
        'pal.users.manage@1' => 'pal_cap_users_manage_1',
        'pal.quotations.read@1' => 'pal_cap_quotations_read_1',
        'pal.quotations.write@1' => 'pal_cap_quotations_write_1',
        'pal.quotations.convert@1' => 'pal_cap_quotations_convert_1',
        'pal.sales.items.manage@1' => 'pal_cap_sales_items_manage_1',
        'pal.cash_advances.read@1' => 'pal_cap_cash_advances_read_1',
        'pal.cash_advances.write@1' => 'pal_cap_cash_advances_write_1',
        'pal.mobilization.read@1' => 'pal_cap_mobilization_read_1',
        'pal.mobilization.write@1' => 'pal_cap_mobilization_write_1',
        'entity.list.pal_project@1' => 'pal_cap_entity_list_project_1',
        'entity.get.pal_project@1' => 'pal_cap_entity_get_project_1',
        'entity.list.pal_expense@1' => 'pal_cap_entity_list_expense_1',
        'entity.get.pal_expense@1' => 'pal_cap_entity_get_expense_1',
        'entity.list.pal_material@1' => 'pal_cap_entity_list_material_1',
        'entity.get.pal_material@1' => 'pal_cap_entity_get_material_1',
        'entity.list.pal_purchase@1' => 'pal_cap_entity_list_purchase_1',
        'entity.get.pal_purchase@1' => 'pal_cap_entity_get_purchase_1',
        'entity.list.pal_sale@1' => 'pal_cap_entity_list_sale_1',
        'entity.get.pal_sale@1' => 'pal_cap_entity_get_sale_1',
        'entity.list.pal_collection@1' => 'pal_cap_entity_list_collection_1',
        'entity.list.pal_fabrication_due@1' => 'pal_cap_entity_list_fabrication_due_1',
        'entity.list.pal_audit_log@1' => 'pal_cap_entity_list_audit_log_1',
        'entity.list.pal_client@1' => 'pal_cap_entity_list_client_1',
        'entity.get.pal_client@1' => 'pal_cap_entity_get_client_1',
        'entity.list.pal_supplier@1' => 'pal_cap_entity_list_supplier_1',
        'entity.get.pal_supplier@1' => 'pal_cap_entity_get_supplier_1',
        'entity.list.pal_issuance@1' => 'pal_cap_entity_list_issuance_1',
        'entity.get.pal_issuance@1' => 'pal_cap_entity_get_issuance_1',
        'entity.list.pal_material_return@1' => 'pal_cap_entity_list_material_return_1',
        'entity.list.pal_quotation@1' => 'pal_cap_entity_list_quotation_1',
        'entity.get.pal_quotation@1' => 'pal_cap_entity_get_quotation_1',
        'entity.list.pal_quotation_item@1' => 'pal_cap_entity_list_quotation_item_1',
        'entity.list.pal_sale_item@1' => 'pal_cap_entity_list_sale_item_1',
        'entity.list.pal_cash_advance@1' => 'pal_cap_entity_list_cash_advance_1',
        'entity.list.pal_mobilization@1' => 'pal_cap_entity_list_mobilization_1',
    ];
}

function pal_cap_workbench_scenario_describe_1(array $args): array { return palWorkbenchScenarioDescribe($args); }
function pal_cap_workbench_scenario_seed_1(array $args): array { return palWorkbenchScenarioSeed($args); }
function pal_cap_workbench_scenario_verify_1(array $args): array { return palWorkbenchScenarioVerify($args); }
function pal_cap_workbench_scenario_cleanup_1(array $args): array { return palWorkbenchScenarioCleanup($args); }

// ── Role-based capability handlers ──

function pal_cap_kernel_auth_authenticate_1(array $args): array
{
    return palAuthLogin($args['username'] ?? '', $args['password'] ?? '');
}

/**
 * Helper: check if current user has one of the allowed roles.
 * Returns ['ok' => true, 'data' => null] or denies with ['ok' => false].
 */
function palCapCheck(array $allowedRoles): array
{
    try {
        $user = palCurrentUser($allowedRoles);
        return ['ok' => true, 'data' => ['user_id' => (int)$user['id'], 'role' => $user['role']]];
    } catch (Throwable) {
        return ['ok' => false, 'error' => 'Forbidden'];
    }
}

function pal_cap_read_1(array $args): array
{
    // Basic read — any authenticated module user
    return palCapCheck(['admin', 'supervisor', 'encoder']);
}

function pal_cap_manage_1(array $args): array
{
    // Full management — admin only
    return palCapCheck(['admin']);
}

function pal_cap_projects_read_1(array $args): array
{
    return palCapCheck(['admin', 'supervisor', 'encoder']);
}

function pal_cap_projects_write_1(array $args): array
{
    return palCapCheck(['admin', 'supervisor']);
}

function pal_cap_expenses_read_1(array $args): array
{
    return palCapCheck(['admin', 'supervisor', 'encoder']);
}

function pal_cap_expenses_write_1(array $args): array
{
    return palCapCheck(['admin', 'supervisor']);
}

function pal_cap_inventory_read_1(array $args): array
{
    return palCapCheck(['admin', 'supervisor', 'encoder']);
}

function pal_cap_inventory_write_1(array $args): array
{
    return palCapCheck(['admin', 'supervisor']);
}

function pal_cap_purchases_read_1(array $args): array
{
    return palCapCheck(['admin', 'supervisor', 'encoder']);
}

function pal_cap_purchases_write_1(array $args): array
{
    return palCapCheck(['admin', 'supervisor']);
}

function pal_cap_sales_read_1(array $args): array
{
    return palCapCheck(['admin', 'supervisor', 'encoder']);
}

function pal_cap_sales_write_1(array $args): array
{
    return palCapCheck(['admin', 'supervisor']);
}

function pal_cap_collections_read_1(array $args): array
{
    return palCapCheck(['admin', 'supervisor', 'encoder']);
}

function pal_cap_collections_write_1(array $args): array
{
    return palCapCheck(['admin', 'supervisor']);
}

function pal_cap_fabrication_read_1(array $args): array
{
    return palCapCheck(['admin', 'supervisor', 'encoder']);
}

function pal_cap_fabrication_write_1(array $args): array
{
    return palCapCheck(['admin', 'supervisor']);
}

function pal_cap_approvals_read_1(array $args): array
{
    return palCapCheck(['admin', 'supervisor']);
}

function pal_cap_approvals_write_1(array $args): array
{
    return palCapCheck(['admin', 'supervisor']);
}

function pal_cap_reports_read_1(array $args): array
{
    return palCapCheck(['admin', 'supervisor']);
}

function pal_cap_audit_read_1(array $args): array
{
    return palCapCheck(['admin']);
}

function pal_cap_settings_read_1(array $args): array
{
    return palCapCheck(['admin']);
}

function pal_cap_settings_write_1(array $args): array
{
    return palCapCheck(['admin']);
}

function pal_cap_users_manage_1(array $args): array
{
    return palCapCheck(['admin']);
}

// ── Entity view capability handlers ──

function pal_cap_entity_list_project_1(array $args): array
{
    try {
        $db = palDb();
        $tid = (int)(app()->tenant()->current() ?? 0);
        $limit = min((int)($args['limit'] ?? 20), 100);
        $offset = (int)($args['offset'] ?? 0);
        $sortField = $args['sort']['field'] ?? 'p.created_at';
        $sortDir = strtoupper((string)($args['sort']['direction'] ?? 'DESC'));
        $sortDir = in_array($sortDir, ['ASC', 'DESC'], true) ? $sortDir : 'DESC';

        $count = $db->prepare("SELECT COUNT(*) FROM pal_projects p WHERE p.tenant_id = :tid");
        $count->execute([':tid' => $tid]);
        $total = (int)$count->fetchColumn();

        $stmt = $db->prepare("SELECT p.id, p.project_id, p.title, p.contract_amount, p.status, p.start_date, p.target_completion_date, p.created_at, c.name AS client_name FROM pal_projects p LEFT JOIN pal_clients c ON p.client_id = c.id WHERE p.tenant_id = :tid ORDER BY {$sortField} {$sortDir} LIMIT :lim OFFSET :off");
        $stmt->bindValue(':tid', $tid, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return ['ok' => true, 'rows' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total' => $total];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

function pal_cap_entity_get_project_1(array $args): array
{
    try {
        $db = palDb();
        $id = (int)($args['id'] ?? 0);
        $tid = (int)(app()->tenant()->current() ?? 0);
        $stmt = $db->prepare("SELECT p.*, c.name AS client_name, pt.name AS project_type_name, tl.name AS team_lead_name FROM pal_projects p LEFT JOIN pal_clients c ON p.client_id = c.id LEFT JOIN pal_project_types pt ON p.project_type_id = pt.id LEFT JOIN pal_team_leads tl ON p.fabrication_team_lead_id = tl.id WHERE p.id = :id AND p.tenant_id = :tid");
        $stmt->execute([':id' => $id, ':tid' => $tid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? ['ok' => true, 'data' => $row] : ['ok' => false, 'error' => 'Not found.'];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

function pal_cap_entity_list_expense_1(array $args): array
{
    try {
        $db = palDb();
        $tid = (int)(app()->tenant()->current() ?? 0);
        $limit = min((int)($args['limit'] ?? 20), 100);
        $offset = (int)($args['offset'] ?? 0);
        $sortDir = strtoupper((string)($args['sort']['direction'] ?? 'DESC'));
        $sortDir = in_array($sortDir, ['ASC', 'DESC'], true) ? $sortDir : 'DESC';

        $filters = $args['filters'] ?? [];
        $where = 'e.tenant_id = :tid';
        $params = [':tid' => $tid];
        if (isset($filters['project_id'])) {
            $where .= ' AND e.project_id = :project_id';
            $params[':project_id'] = (int)$filters['project_id'];
        }
        if (isset($filters['status'])) {
            $where .= ' AND e.status = :status';
            $params[':status'] = $filters['status'];
        }

        $countStmt = $db->prepare("SELECT COUNT(*) FROM pal_expenses e WHERE {$where}");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $stmt = $db->prepare("SELECT e.id, e.project_id, e.expense_number, e.expense_date, e.description, e.amount, e.status, e.created_at, ec.name AS category_name FROM pal_expenses e LEFT JOIN pal_expense_categories ec ON e.category_id = ec.id WHERE {$where} ORDER BY e.created_at {$sortDir} LIMIT :lim OFFSET :off");
        $stmt->bindValue(':tid', $tid, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        if (isset($filters['project_id'])) $stmt->bindValue(':project_id', (int)$filters['project_id'], PDO::PARAM_INT);
        if (isset($filters['status'])) $stmt->bindValue(':status', $filters['status'], PDO::PARAM_STR);
        $stmt->execute();
        return ['ok' => true, 'rows' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total' => $total];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

function pal_cap_entity_get_expense_1(array $args): array
{
    try {
        $db = palDb();
        $id = (int)($args['id'] ?? 0);
        $tid = (int)(app()->tenant()->current() ?? 0);
        $stmt = $db->prepare("SELECT e.*, ec.name AS category_name, p.title AS project_title FROM pal_expenses e LEFT JOIN pal_expense_categories ec ON e.category_id = ec.id LEFT JOIN pal_projects p ON e.project_id = p.id WHERE e.id = :id AND e.tenant_id = :tid");
        $stmt->execute([':id' => $id, ':tid' => $tid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? ['ok' => true, 'data' => $row] : ['ok' => false, 'error' => 'Not found.'];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

function pal_cap_entity_list_material_1(array $args): array
{
    try {
        $db = palDb();
        $tid = (int)(app()->tenant()->current() ?? 0);
        $limit = min((int)($args['limit'] ?? 20), 100);
        $offset = (int)($args['offset'] ?? 0);
        $sortDir = strtoupper((string)($args['sort']['direction'] ?? 'ASC'));
        $sortDir = in_array($sortDir, ['ASC', 'DESC'], true) ? $sortDir : 'ASC';

        $count = $db->prepare("SELECT COUNT(*) FROM pal_materials WHERE tenant_id = :tid");
        $count->execute([':tid' => $tid]);
        $total = (int)$count->fetchColumn();

        $stmt = $db->prepare("SELECT m.id, m.material_code, m.name, m.current_avg_cost, m.reorder_level, m.is_active, COALESCE(b.quantity, 0) AS stock_qty, mc.name AS category_name FROM pal_materials m LEFT JOIN pal_material_categories mc ON m.category_id = mc.id LEFT JOIN pal_inventory_balances b ON m.id = b.material_id WHERE m.tenant_id = :tid ORDER BY m.name {$sortDir} LIMIT :lim OFFSET :off");
        $stmt->bindValue(':tid', $tid, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return ['ok' => true, 'rows' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total' => $total];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

function pal_cap_entity_get_material_1(array $args): array
{
    try {
        $db = palDb();
        $id = (int)($args['id'] ?? 0);
        $tid = (int)(app()->tenant()->current() ?? 0);
        $stmt = $db->prepare("SELECT m.*, mc.name AS category_name, u.name AS unit_name, COALESCE(b.quantity, 0) AS stock_qty, COALESCE(NULLIF(b.avg_cost, 0), m.current_avg_cost) AS avg_cost FROM pal_materials m LEFT JOIN pal_material_categories mc ON m.category_id = mc.id LEFT JOIN pal_units u ON m.unit_id = u.id LEFT JOIN pal_inventory_balances b ON m.id = b.material_id WHERE m.id = :id AND m.tenant_id = :tid");
        $stmt->execute([':id' => $id, ':tid' => $tid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? ['ok' => true, 'data' => $row] : ['ok' => false, 'error' => 'Not found.'];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

function pal_cap_entity_list_purchase_1(array $args): array
{
    try {
        $db = palDb();
        $tid = (int)(app()->tenant()->current() ?? 0);
        $limit = min((int)($args['limit'] ?? 20), 100);
        $offset = (int)($args['offset'] ?? 0);
        $sortDir = strtoupper((string)($args['sort']['direction'] ?? 'DESC'));
        $sortDir = in_array($sortDir, ['ASC', 'DESC'], true) ? $sortDir : 'DESC';

        $count = $db->prepare("SELECT COUNT(*) FROM pal_purchases WHERE tenant_id = :tid");
        $count->execute([':tid' => $tid]);
        $total = (int)$count->fetchColumn();

        $stmt = $db->prepare("SELECT p.id, p.purchase_number, p.total_amount, p.status, p.purchase_date, p.created_at, s.name AS supplier_name FROM pal_purchases p LEFT JOIN pal_suppliers s ON p.supplier_id = s.id WHERE p.tenant_id = :tid ORDER BY p.created_at {$sortDir} LIMIT :lim OFFSET :off");
        $stmt->bindValue(':tid', $tid, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return ['ok' => true, 'rows' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total' => $total];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

function pal_cap_entity_get_purchase_1(array $args): array
{
    try {
        $db = palDb();
        $id = (int)($args['id'] ?? 0);
        $tid = (int)(app()->tenant()->current() ?? 0);
        $stmt = $db->prepare("SELECT p.*, s.name AS supplier_name, pr.title AS project_title FROM pal_purchases p LEFT JOIN pal_suppliers s ON p.supplier_id = s.id LEFT JOIN pal_projects pr ON p.project_id = pr.id WHERE p.id = :id AND p.tenant_id = :tid");
        $stmt->execute([':id' => $id, ':tid' => $tid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? ['ok' => true, 'data' => $row] : ['ok' => false, 'error' => 'Not found.'];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

function pal_cap_entity_list_sale_1(array $args): array
{
    try {
        $db = palDb();
        $tid = (int)(app()->tenant()->current() ?? 0);
        $limit = min((int)($args['limit'] ?? 20), 100);
        $offset = (int)($args['offset'] ?? 0);
        $sortDir = strtoupper((string)($args['sort']['direction'] ?? 'DESC'));
        $sortDir = in_array($sortDir, ['ASC', 'DESC'], true) ? $sortDir : 'DESC';

        $count = $db->prepare("SELECT COUNT(*) FROM pal_sales WHERE tenant_id = :tid");
        $count->execute([':tid' => $tid]);
        $total = (int)$count->fetchColumn();

        $stmt = $db->prepare("SELECT s.id, s.sales_number, s.gross_amount, s.discount_amount, s.tax_amount, s.net_amount, s.invoice_number, s.status, s.sales_date, s.created_at, p.title AS project_title, c.name AS client_name FROM pal_sales s LEFT JOIN pal_projects p ON s.project_id = p.id LEFT JOIN pal_clients c ON s.client_id = c.id WHERE s.tenant_id = :tid ORDER BY s.created_at {$sortDir} LIMIT :lim OFFSET :off");
        $stmt->bindValue(':tid', $tid, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return ['ok' => true, 'rows' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total' => $total];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

function pal_cap_entity_get_sale_1(array $args): array
{
    try {
        $db = palDb();
        $id = (int)($args['id'] ?? 0);
        $tid = (int)(app()->tenant()->current() ?? 0);
        $stmt = $db->prepare("SELECT s.*, p.title AS project_title, c.name AS client_name FROM pal_sales s LEFT JOIN pal_projects p ON s.project_id = p.id LEFT JOIN pal_clients c ON s.client_id = c.id WHERE s.id = :id AND s.tenant_id = :tid");
        $stmt->execute([':id' => $id, ':tid' => $tid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? ['ok' => true, 'data' => $row] : ['ok' => false, 'error' => 'Not found.'];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

function pal_cap_entity_list_collection_1(array $args): array
{
    try {
        $db = palDb();
        $tid = (int)(app()->tenant()->current() ?? 0);
        $limit = min((int)($args['limit'] ?? 20), 100);
        $offset = (int)($args['offset'] ?? 0);
        $sortDir = strtoupper((string)($args['sort']['direction'] ?? 'DESC'));
        $sortDir = in_array($sortDir, ['ASC', 'DESC'], true) ? $sortDir : 'DESC';

        $count = $db->prepare("SELECT COUNT(*) FROM pal_collections WHERE tenant_id = :tid");
        $count->execute([':tid' => $tid]);
        $total = (int)$count->fetchColumn();

        $stmt = $db->prepare("SELECT c.id, c.collection_number, c.amount, c.payment_method, c.reference_number, c.status, c.payment_date, c.created_at, COALESCE(s.invoice_number, s.sales_number) AS sales_number, p.title AS project_title, cl.name AS client_name FROM pal_collections c LEFT JOIN pal_sales s ON c.sales_id = s.id LEFT JOIN pal_projects p ON c.project_id = p.id LEFT JOIN pal_clients cl ON c.client_id = cl.id WHERE c.tenant_id = :tid ORDER BY c.created_at {$sortDir} LIMIT :lim OFFSET :off");
        $stmt->bindValue(':tid', $tid, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return ['ok' => true, 'rows' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total' => $total];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

function pal_cap_entity_list_fabrication_due_1(array $args): array
{
    try {
        $db = palDb();
        $tid = (int)(app()->tenant()->current() ?? 0);
        $limit = min((int)($args['limit'] ?? 20), 100);
        $offset = (int)($args['offset'] ?? 0);
        $sortDir = strtoupper((string)($args['sort']['direction'] ?? 'DESC'));
        $sortDir = in_array($sortDir, ['ASC', 'DESC'], true) ? $sortDir : 'DESC';

        $count = $db->prepare("SELECT COUNT(*) FROM pal_fabrication_weekly_dues WHERE tenant_id = :tid");
        $count->execute([':tid' => $tid]);
        $total = (int)$count->fetchColumn();

        $stmt = $db->prepare("SELECT d.id, d.due_date, d.amount_due, d.paid_amount, d.status, d.created_at, p.title AS project_title FROM pal_fabrication_weekly_dues d LEFT JOIN pal_projects p ON d.project_id = p.id WHERE d.tenant_id = :tid ORDER BY d.created_at {$sortDir} LIMIT :lim OFFSET :off");
        $stmt->bindValue(':tid', $tid, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return ['ok' => true, 'rows' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total' => $total];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

function pal_cap_entity_list_audit_log_1(array $args): array
{
    try {
        $db = palDb();
        $tid = (int)(app()->tenant()->current() ?? 0);
        $limit = min((int)($args['limit'] ?? 20), 100);
        $offset = (int)($args['offset'] ?? 0);
        $sortDir = strtoupper((string)($args['sort']['direction'] ?? 'DESC'));
        $sortDir = in_array($sortDir, ['ASC', 'DESC'], true) ? $sortDir : 'DESC';

        $count = $db->prepare("SELECT COUNT(*) FROM pal_audit_logs WHERE tenant_id = :tid");
        $count->execute([':tid' => $tid]);
        $total = (int)$count->fetchColumn();

        $stmt = $db->prepare("SELECT a.id, a.action, a.entity_type, a.entity_id, a.new_data, a.ip_address, a.created_at, u.full_name AS actor_name FROM pal_audit_logs a LEFT JOIN pal_users u ON a.actor_user_id = u.id WHERE a.tenant_id = :tid ORDER BY a.created_at {$sortDir} LIMIT :lim OFFSET :off");
        $stmt->bindValue(':tid', $tid, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return ['ok' => true, 'rows' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total' => $total];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

function pal_cap_entity_list_client_1(array $args): array
{
    try {
        $db = palDb();
        $tid = (int)(app()->tenant()->current() ?? 0);
        $limit = min((int)($args['limit'] ?? 20), 100);
        $offset = (int)($args['offset'] ?? 0);
        $sortDir = strtoupper((string)($args['sort']['direction'] ?? 'ASC'));
        $sortDir = in_array($sortDir, ['ASC', 'DESC'], true) ? $sortDir : 'ASC';
        $count = $db->prepare("SELECT COUNT(*) FROM pal_clients WHERE tenant_id = :tid");
        $count->execute([':tid' => $tid]);
        $total = (int)$count->fetchColumn();
        $stmt = $db->prepare("SELECT id, name, contact_person, email, phone, address, is_active FROM pal_clients WHERE tenant_id = :tid ORDER BY name {$sortDir} LIMIT :lim OFFSET :off");
        $stmt->bindValue(':tid', $tid, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return ['ok' => true, 'rows' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total' => $total];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

function pal_cap_entity_get_client_1(array $args): array
{
    try {
        $db = palDb();
        $id = (int)($args['id'] ?? 0);
        $tid = (int)(app()->tenant()->current() ?? 0);
        $stmt = $db->prepare("SELECT * FROM pal_clients WHERE id = :id AND tenant_id = :tid");
        $stmt->execute([':id' => $id, ':tid' => $tid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? ['ok' => true, 'data' => $row] : ['ok' => false, 'error' => 'Not found.'];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

function pal_cap_entity_list_supplier_1(array $args): array
{
    try {
        $db = palDb();
        $tid = (int)(app()->tenant()->current() ?? 0);
        $limit = min((int)($args['limit'] ?? 20), 100);
        $offset = (int)($args['offset'] ?? 0);
        $sortDir = strtoupper((string)($args['sort']['direction'] ?? 'ASC'));
        $sortDir = in_array($sortDir, ['ASC', 'DESC'], true) ? $sortDir : 'ASC';
        $count = $db->prepare("SELECT COUNT(*) FROM pal_suppliers WHERE tenant_id = :tid");
        $count->execute([':tid' => $tid]);
        $total = (int)$count->fetchColumn();
        $stmt = $db->prepare("SELECT id, name, contact_person, email, phone, address, payment_terms, is_active FROM pal_suppliers WHERE tenant_id = :tid ORDER BY name {$sortDir} LIMIT :lim OFFSET :off");
        $stmt->bindValue(':tid', $tid, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return ['ok' => true, 'rows' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total' => $total];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

function pal_cap_entity_get_supplier_1(array $args): array
{
    try {
        $db = palDb();
        $id = (int)($args['id'] ?? 0);
        $tid = (int)(app()->tenant()->current() ?? 0);
        $stmt = $db->prepare("SELECT * FROM pal_suppliers WHERE id = :id AND tenant_id = :tid");
        $stmt->execute([':id' => $id, ':tid' => $tid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? ['ok' => true, 'data' => $row] : ['ok' => false, 'error' => 'Not found.'];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

function pal_cap_entity_list_issuance_1(array $args): array
{
    try {
        $db = palDb();
        $tid = (int)(app()->tenant()->current() ?? 0);
        $limit = min((int)($args['limit'] ?? 20), 100);
        $offset = (int)($args['offset'] ?? 0);
        $sortDir = strtoupper((string)($args['sort']['direction'] ?? 'DESC'));
        $sortDir = in_array($sortDir, ['ASC', 'DESC'], true) ? $sortDir : 'DESC';
        $count = $db->prepare("SELECT COUNT(*) FROM pal_material_issuances WHERE tenant_id = :tid");
        $count->execute([':tid' => $tid]);
        $total = (int)$count->fetchColumn();
        $stmt = $db->prepare("SELECT i.id, i.issuance_number, i.status, i.created_at, p.title AS project_title, (SELECT COUNT(*) FROM pal_material_issuance_items ii WHERE ii.issuance_id = i.id) AS item_count FROM pal_material_issuances i LEFT JOIN pal_projects p ON i.project_id = p.id WHERE i.tenant_id = :tid ORDER BY i.created_at {$sortDir} LIMIT :lim OFFSET :off");
        $stmt->bindValue(':tid', $tid, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return ['ok' => true, 'rows' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total' => $total];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

function pal_cap_entity_get_issuance_1(array $args): array
{
    try {
        $db = palDb();
        $id = (int)($args['id'] ?? 0);
        $tid = (int)(app()->tenant()->current() ?? 0);
        $stmt = $db->prepare("SELECT i.*, p.title AS project_title FROM pal_material_issuances i LEFT JOIN pal_projects p ON i.project_id = p.id WHERE i.id = :id AND i.tenant_id = :tid");
        $stmt->execute([':id' => $id, ':tid' => $tid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? ['ok' => true, 'data' => $row] : ['ok' => false, 'error' => 'Not found.'];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

function pal_cap_entity_list_material_return_1(array $args): array
{
    try {
        $db = palDb();
        $tid = (int)(app()->tenant()->current() ?? 0);
        $limit = min((int)($args['limit'] ?? 20), 100);
        $offset = (int)($args['offset'] ?? 0);
        $sortDir = strtoupper((string)($args['sort']['direction'] ?? 'DESC'));
        $sortDir = in_array($sortDir, ['ASC', 'DESC'], true) ? $sortDir : 'DESC';
        $count = $db->prepare("SELECT COUNT(*) FROM pal_material_returns WHERE tenant_id = :tid");
        $count->execute([':tid' => $tid]);
        $total = (int)$count->fetchColumn();
        $stmt = $db->prepare("SELECT r.id, r.return_date, r.quantity_returned, r.condition, r.created_at, p.title AS project_title, m.name AS material_name, iss.issuance_number FROM pal_material_returns r LEFT JOIN pal_projects p ON r.project_id = p.id LEFT JOIN pal_materials m ON r.material_id = m.id LEFT JOIN pal_material_issuances iss ON r.issuance_id = iss.id WHERE r.tenant_id = :tid ORDER BY r.created_at {$sortDir} LIMIT :lim OFFSET :off");
        $stmt->bindValue(':tid', $tid, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return ['ok' => true, 'rows' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total' => $total];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

// ── URL and cookie helpers ──

function palBaseUrl(): string
{
    return rtrim((string)(defined('BASE_URL') ? BASE_URL : ''), '/');
}

function palExternalBaseUrl(): string
{
    return external_base_url((string)config('app.url', ''));
}

function palCookieName(): string
{
    return 'pal_token';
}

function palSetAuthCookie(string $token, int $expiresInSeconds = 86400): void
{
    if (headers_sent()) {
        return;
    }

    $expiry = time() + max(60, $expiresInSeconds);
    setcookie(palCookieName(), $token, [
        'expires' => $expiry,
        'path' => '/',
        'httponly' => true,
        'secure' => is_https(),
        'samesite' => config('cookie.samesite', 'Strict'),
    ]);
}

function palLoginPageContext(array $overrides = []): array
{
    $baseUrl = palBaseUrl();
    $settings = palSettings();

    return array_merge([
        'page_title' => 'Project Ledger — Sign In',
        'login_subtitle' => 'Project costing, inventory, and fabrication management',
        'login_username_label' => 'Username or Email',
        'login_endpoint' => $baseUrl . '/api/v1/project-audit-ledger/auth/login',
        'login_button_text' => 'Sign In',
        'login_loading_text' => 'Opening workspace...',
        'login_brand_html' => 'Project <span>Ledger</span>',
        'login_forgot_url' => $baseUrl . '/project-audit-ledger/forgot-password',
        'login_forgot_text' => 'Forgot password?',
        'login_helper_title' => 'First Time Here?',
        'login_helper_html' => '<p>Contact your system administrator for credentials.</p><ul><li>Admins can manage users, projects, and settings.</li><li>Supervisors review and approve transactions.</li><li>Encoders create project records and submit for approval.</li></ul>',
        'gui' => [
            'app_name' => 'Project Audit Ledger',
            'app_name_accent' => 'Project',
            'app_name_rest' => 'Ledger',
            'font_family' => 'Inter, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif',
            'color_primary' => '#2563eb',
            'color_primary_hover' => '#1d4ed8',
            'color_primary_light' => 'rgba(37, 99, 235, 0.18)',
            'color_bg' => '#f8fafc',
            'color_surface' => '#ffffff',
            'color_border' => '#e2e8f0',
            'color_text' => '#0f172a',
            'color_text_muted' => '#64748b',
        ],
    ], $overrides);
}

function palClearAuthCookie(): void
{
    if (headers_sent()) {
        return;
    }

    setcookie(palCookieName(), '', [
        'expires' => time() - 3600,
        'path' => '/',
        'httponly' => true,
        'secure' => is_https(),
        'samesite' => config('cookie.samesite', 'Strict'),
    ]);
}

// ── Runtime helpers ──

function palCtx(): \Ikabud\Kernel\Contracts\ModuleContext
{
    $ctx = module('project-audit-ledger');
    if ($ctx === null) {
        throw new RuntimeException('Project Audit Ledger module context not available.');
    }
    return $ctx;
}

function palDb(): Ikabud\Kernel\Contracts\ModuleDB
{
    // Auth-owned module: force tenant context to match the authenticated user.
    // Overrides host-based tenant resolution so queries always hit the correct
    // tenant database regardless of the request host.
    $sessionUser = $_SESSION['pal_user'] ?? null;
    if (is_array($sessionUser) && !empty($sessionUser['tenant_id'])) {
        $userTid = (int)$sessionUser['tenant_id'];
        $currentTid = app()->tenant()->current();
        if ($currentTid === null || $currentTid !== $userTid) {
            app()->tenant()->setTenantId($userTid);
        }
    }
    try {
        return palCtx()->db();
    } catch (Throwable $e) {
        // Fallback: module context not available (test/CLI context).
        // Return a properly configured ModuleDB so palAudit() and other
        // helpers don't crash. Table lists mirror module.json declarations.
        $pdo = app()->db();
        return new Ikabud\Kernel\Contracts\ModuleDB(
            $pdo,
            'project-audit-ledger',
            [   // owns_tables
                'pal_users', 'pal_password_resets', 'pal_projects', 'pal_project_items',
                'pal_project_types', 'pal_clients', 'pal_suppliers', 'pal_materials',
                'pal_material_categories', 'pal_units', 'pal_inventory_locations',
                'pal_inventory_movements', 'pal_inventory_balances', 'pal_purchases',
                'pal_purchase_items', 'pal_material_issuances', 'pal_material_issuance_items',
                'pal_material_returns', 'pal_expenses', 'pal_expense_categories',
                'pal_sales', 'pal_sale_items', 'pal_collections', 'pal_fabrication_allocations',
                'pal_fabrication_weekly_dues', 'pal_fabrication_payments', 'pal_team_leads',
                'pal_approvals', 'pal_attachments', 'pal_audit_logs', 'pal_report_exports',
                'pal_settings', 'pal_quotations', 'pal_quotation_items', 'pal_cash_advances',
                'pal_otp_codes', 'pal_mobilization_requests',
            ],
            [   // reads_tables
                'audit_logs', 'attendance_groups', 'attendance_group_members',
                'attendance_wage_users', 'employee_profiles', 'attendance_records',
            ]
        );
    }
}

/**
 * Build the shell context array for workbench:app_shell.
 * Lives in PHP to avoid DiSyL scale limits with deeply nested arrays.
 */
use Ikabud\ApplicationProfiles\ArkWorkbench\ApplicationShellViewModel;

function palBuildShellContext(array $ctx): array
{
    $user = $ctx['current_user'] ?? [];
    $pc   = $ctx['page_content'] ?? '';

    $shell = ApplicationShellViewModel::create()
        ->withAppName($ctx['pal_app_name'] ?? 'Project Audit Ledger')
        ->withLogoUrl(!empty($ctx['pal_logo_path']) ? '/' . $ctx['pal_logo_path'] : '')
        ->withUser($user)
        ->withPageTitle($ctx['page_title'] ?? '')
        ->withCurrentRoute($pc)
        ->withInspectMode(!empty($_GET['wb_inspect']));

    // Workbench preflight: infrastructure checks for deployments
    if (!empty($_GET['wb_inspect'])) {
        $palDir = STORAGE_PATH . '/private/pal';
        $shell->withPreflightData([
            'storage' => [
                'path'       => $palDir,
                'exists'     => is_dir($palDir),
                'writable'   => is_writable($palDir),
                'not_public' => !str_starts_with($palDir, $_SERVER['DOCUMENT_ROOT'] ?? ''),
            ],
            'php' => [
                'upload_max_filesize' => ini_get('upload_max_filesize'),
                'post_max_size'       => ini_get('post_max_size'),
                'mime_detection'      => function_exists('mime_content_type'),
            ],
            'module' => [
                'id'   => 'project-audit-ledger',
                'name' => $ctx['pal_app_name'] ?? 'Project Audit Ledger',
            ],
        ]);
    }

    // Overview
    $shell->addNavSection('Overview', [
        ['label' => 'Dashboard',     'url' => '/admin/project-audit-ledger',                  'icon_key' => '📊', 'routes' => 'dashboard'],
        ['label' => 'New Job Order', 'url' => '/admin/project-audit-ledger/projects/create', 'icon_key' => '➕', 'routes' => 'project-form'],
    ]);
    // Job Orders
    $shell->addNavSection('Job Orders', [
        ['label' => 'All Job Orders', 'url' => '/admin/project-audit-ledger/projects', 'icon_key' => '📋', 'routes' => ['projects-list', 'project-detail']],
        ['label' => 'Clients',       'url' => '/admin/project-audit-ledger/clients',  'icon_key' => '👤', 'routes' => ['clients-list', 'client-form', 'client-detail']],
        ['label' => 'Suppliers',     'url' => '/admin/project-audit-ledger/suppliers','icon_key' => '🏭', 'routes' => ['suppliers-list', 'supplier-form']],
    ]);
    // Sales & Billing
    $shell->addNavSection('Sales & Billing', [
        ['label' => 'Sales Invoices', 'url' => '/admin/project-audit-ledger/sales',       'icon_key' => '💰', 'routes' => ['sales-list', 'sales-form', 'sales-detail']],
        ['label' => 'Collections',    'url' => '/admin/project-audit-ledger/collections', 'icon_key' => '💵', 'routes' => ['collections-list', 'collection-form', 'collections-detail']],
        ['label' => 'Quotations',     'url' => '/admin/project-audit-ledger/quotations',  'icon_key' => '📝', 'routes' => ['quotations-list', 'quotation-form', 'quotation-detail']],
        ['label' => 'BOM',            'url' => '/admin/project-audit-ledger/bom',         'icon_key' => '📋', 'routes' => 'bill-of-materials'],
    ]);
    // Inventory & Procurement
    $shell->addNavSection('Inventory & Procurement', [
        ['label' => 'Inventory',       'url' => '/admin/project-audit-ledger/inventory',           'icon_key' => '📦', 'routes' => 'inventory-list'],
        ['label' => 'Stock Movements',  'url' => '/admin/project-audit-ledger/inventory/movements','icon_key' => '📤', 'routes' => 'movements-list'],
        ['label' => 'Purchases',        'url' => '/admin/project-audit-ledger/purchases',          'icon_key' => '🛒', 'routes' => ['purchases-list', 'purchase-form', 'purchase-detail']],
        ['label' => 'Issuances',        'url' => '/admin/project-audit-ledger/issuances',          'icon_key' => '📤', 'routes' => ['issuance-list', 'issuance-form', 'issuance-detail']],
        ['label' => 'Returns',          'url' => '/admin/project-audit-ledger/issuances/returns',  'icon_key' => '↩',  'routes' => 'material-return-list'],
        ['label' => 'Expenses',         'url' => '/admin/project-audit-ledger/expenses',           'icon_key' => '💳', 'routes' => ['expenses-list', 'expense-form', 'expense-detail']],
    ]);
    // Operations
    $shell->addNavSection('Operations', [
        ['label' => 'Fabrication',  'url' => '/admin/project-audit-ledger/fabrication/allocations','icon_key' => '🔧', 'routes' => 'fabrication-allocations'],
        ['label' => 'Mobilization', 'url' => '/admin/project-audit-ledger/mobilization',           'icon_key' => '🚛', 'routes' => 'mobilization-list'],
        ['label' => 'Cash Advances','url' => '/admin/project-audit-ledger/cash-advances',          'icon_key' => '💵', 'routes' => 'cash-advances-list'],
    ]);
    // Oversight
    $shell->addNavSection('Oversight', [
        ['label' => 'Approvals',  'url' => '/admin/project-audit-ledger/approvals',   'icon_key' => '✅', 'routes' => 'approval-queue'],
        ['label' => 'Reports',    'url' => '/admin/project-audit-ledger/reports',     'icon_key' => '📊', 'routes' => 'reports-center'],
        ['label' => 'Audit Trail','url' => '/admin/project-audit-ledger/audit-trail', 'icon_key' => '🔍', 'routes' => 'audit-trail'],
    ]);
    // Administration
    $shell->addNavSection('Administration', [
        ['label' => 'Settings','url' => '/admin/project-audit-ledger/settings','icon_key' => '⚙', 'routes' => 'settings-overview'],
        ['label' => 'Users',   'url' => '/admin/project-audit-ledger/users',   'icon_key' => '👥', 'routes' => 'users-list'],
    ]);

    $shell->addUserAction('Sign Out', '/api/v1/project-audit-ledger/auth/logout');

    $shell->addMobileNav('Home',      '/admin/project-audit-ledger',           '📊');
    $shell->addMobileNav('Projects',  '/admin/project-audit-ledger/projects',  '📋');
    $shell->addMobileNav('Sales',     '/admin/project-audit-ledger/sales',     '💰');
    $shell->addMobileNav('Inventory', '/admin/project-audit-ledger/inventory', '📦');
    $shell->addMobileNav('Approvals', '/admin/project-audit-ledger/approvals', '✅');

    $shell->addExtraStyle('/assets/pal/pal-ui.css');

    // Cache-bust via filemtime so updated assets aren't stuck in browser cache
    $palAssetVer = function (string $path): string {
        $full = dirname(__DIR__, 2) . '/public' . $path;
        $mtime = is_file($full) ? filemtime($full) : time();
        return $path . '?v=' . $mtime;
    };

    $shell->addExtraScript('https://unpkg.com/htmx.org@1.9.12');
    $shell->addExtraScript('https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js');
    $shell->addExtraScript($palAssetVer('/assets/pal/pal-routes.js'));
    $shell->addExtraScript($palAssetVer('/assets/pal/pal-core.js'));
    $shell->addExtraScript($palAssetVer('/assets/pal/pal-forms.js'));

    return $shell->toTemplateContext();
}

function palRender(string $template, array $context = []): void
{
    $settings = palSettings();
    $context['settings'] = $settings;
    $context['pal_app_name'] = $settings['app_name'] ?? 'Project Audit Ledger';
    $context['pal_logo_path'] = $settings['logo_path'] ?? '';

    // Build shell context in PHP (navigation, user display, etc.)
    $context['shell_ctx'] = palBuildShellContext($context);

    // Auto-render page body from individual template file
    $pageContent = $context['page_content'] ?? '';
    if ($pageContent !== '') {
        $pageTemplate = __DIR__ . '/templates/project-audit-ledger/pages/' . $pageContent . '.disyl';
        if (file_exists($pageTemplate)) {
            $context['page_body'] = app()->render($pageTemplate, $context);
        } else {
            $context['page_body'] = '<div class="bg-white rounded-xl shadow-sm border border-gray-200 p-8 text-center"><p class="text-gray-400 text-sm">Page template not found: ' . htmlspecialchars($pageContent, ENT_QUOTES, 'UTF-8') . '</p></div>';
        }
    }

    echo app()->render($template, $context);
}

function palJsonError(string $message, int $status = 422, array $extra = []): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode(array_merge(['ok' => false, 'error' => $message], $extra));
    exit;
}

function palAudit(string $action, ?int $actorUserId = null, ?string $entityType = null, ?string $entityId = null, mixed $oldData = null, mixed $newData = null): void
{
    try {
        $db = palDb();
        $stmt = $db->prepare(
            'INSERT INTO pal_audit_logs (tenant_id, actor_user_id, action, entity_type, entity_id, old_data, new_data, ip_address, user_agent, created_at)
             VALUES (:tenant_id, :actor_user_id, :action, :entity_type, :entity_id, :old_data, :new_data, :ip_address, :user_agent, NOW())'
        );
        $stmt->execute([
            ':tenant_id' => (int)(app()->tenant()->current() ?? 0),
            ':actor_user_id' => $actorUserId,
            ':action' => $action,
            ':entity_type' => $entityType,
            ':entity_id' => $entityId,
            ':old_data' => $oldData !== null ? json_encode($oldData) : null,
            ':new_data' => $newData !== null ? json_encode($newData) : null,
            ':ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            ':user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
        ]);
    } catch (Throwable $e) {
        if (function_exists('write_log')) {
            write_log('palAudit failed: ' . $e->getMessage(), 'error');
        }
    }
}

/**
 * Create a pal_approvals record and return its ID.
 */
function palCreateApproval(string $entityType, int $entityId, int $submittedBy, string $previousStatus, string $newStatus = 'pending_approval'): int
{
    $db = palDb();
    $stmt = $db->prepare(
        "INSERT INTO pal_approvals (tenant_id, entity_type, entity_id, submitted_by, previous_status, new_status, decision, escalation_level)
         VALUES (:t, :et, :eid, :sb, :ps, :ns, 'pending', 0)"
    );
    $stmt->execute([
        ':t' => (int)(app()->tenant()->current() ?? 0),
        ':et' => $entityType,
        ':eid' => $entityId,
        ':sb' => $submittedBy,
        ':ps' => $previousStatus,
        ':ns' => $newStatus,
    ]);
    return (int)$db->lastInsertId();
}

/**
 * Fire a domain event through the kernel event bus.
 */
function palFireEvent(string $event, array $payload = []): void
{
    try {
        if (function_exists('app') && ($a = app()) !== null && method_exists($a, 'events')) {
            $a->events()->fire($event, $payload, 'project-audit-ledger');
        }
    } catch (Throwable $e) {
        if (function_exists('write_log')) {
            write_log("palFireEvent failed: {$event} — " . $e->getMessage(), 'warning');
        }
    }
}

// ─────────────────────────────────────────────────────────────
// Entity URL Registry
// Single source of truth for entity-type → admin URL mapping.
// Used by approval queue, audit trail, and any view that links
// to entity detail pages from an entity_type + entity_id pair.
// ─────────────────────────────────────────────────────────────

/**
 * Maps an internal entity_type string (as stored in pal_approvals.entity_type
 * and pal_audit_logs.entity_type) to an admin URL prefix.
 *
 * Usage: palEntityUrl('expense', 42) → '/admin/project-audit-ledger/expenses/42'
 *        palEntityUrl('expense')     → '/admin/project-audit-ledger/expenses'
 */
function palEntityUrl(string $entityType, ?int $entityId = null): ?string
{
    $map = [
        'expense'             => '/admin/project-audit-ledger/expenses',
        'purchase'            => '/admin/project-audit-ledger/purchases',
        'issuance'            => '/admin/project-audit-ledger/material-issuance',
        'collection'          => '/admin/project-audit-ledger/collections',
        'fabrication_payment' => '/admin/project-audit-ledger/fabrication',
        'cash_advance'        => '/admin/project-audit-ledger/cash-advances',
        'mobilization'        => '/admin/project-audit-ledger/mobilization',
    ];

    $base = $map[$entityType] ?? null;
    if ($base === null) {
        return null;
    }

    return $entityId !== null ? "{$base}/{$entityId}" : $base;
}

/**
 * Maps an audit-log entity_type (prefixed with 'pal_') to an admin URL.
 * Covers the pal_audit_logs table which uses 'pal_projects', 'pal_expenses', etc.
 */
function palAuditEntityUrl(string $entityType, ?int $entityId = null): ?string
{
    $map = [
        'pal_projects'           => '/admin/project-audit-ledger/projects',
        'pal_clients'            => '/admin/project-audit-ledger/clients',
        'pal_expenses'           => '/admin/project-audit-ledger/expenses',
        'pal_purchases'          => '/admin/project-audit-ledger/purchases',
        'pal_sales'              => '/admin/project-audit-ledger/sales',
        'pal_collections'        => '/admin/project-audit-ledger/collections',
        'pal_material_issuances' => '/admin/project-audit-ledger/material-issuance',
        'pal_materials'          => '/admin/project-audit-ledger/inventory',
        'pal_suppliers'          => '/admin/project-audit-ledger/suppliers',
    ];

    $base = $map[$entityType] ?? null;
    if ($base === null) {
        return null;
    }

    return $entityId !== null ? "{$base}/{$entityId}" : $base;
}

/**
 * Returns a human-readable label for an entity type.
 */
function palEntityLabel(string $entityType): string
{
    $labels = [
        'expense'             => 'Expense',
        'purchase'            => 'Purchase',
        'issuance'            => 'Material Issuance',
        'collection'          => 'Payment',
        'fabrication_payment' => 'Fabrication Payment',
        'cash_advance'        => 'Cash Advance',
        'mobilization'        => 'Mobilization',
        'pal_projects'        => 'Job Order',
        'pal_clients'         => 'Client',
        'pal_expenses'        => 'Expense',
        'pal_purchases'       => 'Purchase',
        'pal_sales'           => 'Invoice',
        'pal_collections'     => 'Payment',
        'pal_material_issuances' => 'Material Issuance',
        'pal_materials'       => 'Material',
        'pal_suppliers'       => 'Supplier',
    ];

    return $labels[$entityType] ?? ucfirst(str_replace(['pal_', '_'], ['', ' '], $entityType));
}

function palSettings(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $cache = [];
    try {
        $db = palDb();
        $stmt = $db->query('SELECT setting_key, setting_value FROM pal_settings');
        if ($stmt) {
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $cache[$row['setting_key']] = $row['setting_value'];
            }
        }
    } catch (Throwable) {
    }

    return $cache;
}

function palIsKernelSuperadmin(?array $user): bool
{
    return is_array($user)
        && ($user['role'] ?? '') === 'superadmin'
        && ($user['source'] ?? '') === 'kernel';
}

function palIsModuleUser(?array $user): bool
{
    return is_array($user) && isset($user['source']) && $user['source'] === 'module';
}

function palAuthenticatedUserRecord(int $userId): ?array
{
    try {
        $db = palDb();
        $stmt = $db->prepare(
            'SELECT id, username, email, password_hash, full_name, role, is_active, token_version
             FROM pal_users WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    } catch (Throwable) {
        return null;
    }
}

function palSupportsTokenVersion(): bool
{
    return true;
}

function palRejectStaleSession(): void
{
    palClearAuthCookie();
    unset($_SESSION['pal_user']);
    app()->redirect(palBaseUrl() . '/project-audit-ledger/login');
}

// ── New Capability Handlers (Quotations, Sale Items, Cash Advances) ──

function pal_cap_quotations_read_1(array $args): array
{
    return palCapCheck(['admin', 'supervisor', 'encoder']);
}

function pal_cap_quotations_write_1(array $args): array
{
    return palCapCheck(['admin', 'supervisor']);
}

function pal_cap_quotations_convert_1(array $args): array
{
    return palCapCheck(['admin', 'supervisor']);
}

function pal_cap_sales_items_manage_1(array $args): array
{
    return palCapCheck(['admin', 'supervisor']);
}

function pal_cap_cash_advances_read_1(array $args): array
{
    return palCapCheck(['admin', 'supervisor']);
}

function pal_cap_cash_advances_write_1(array $args): array
{
    return palCapCheck(['admin', 'supervisor']);
}

function pal_cap_mobilization_read_1(array $args): array
{
    return palCapCheck(['admin', 'supervisor']);
}

function pal_cap_mobilization_write_1(array $args): array
{
    return palCapCheck(['admin']);
}

// ── Entity View Handlers for Quotations ──

function pal_cap_entity_list_quotation_1(array $args): array
{
    try {
        $db = palDb();
        $tid = (int)(app()->tenant()->current() ?? 0);
        $limit = min((int)($args['limit'] ?? 20), 100);
        $offset = (int)($args['offset'] ?? 0);
        $sortDir = strtoupper((string)($args['sort']['direction'] ?? 'DESC'));
        $sortDir = in_array($sortDir, ['ASC', 'DESC'], true) ? $sortDir : 'DESC';

        $count = $db->prepare("SELECT COUNT(*) FROM pal_quotations WHERE tenant_id = :tid");
        $count->execute([':tid' => $tid]);
        $total = (int)$count->fetchColumn();

        $stmt = $db->prepare("SELECT q.id, q.quotation_number, q.total_amount, q.scope_of_work, q.status, q.quotation_date, q.created_at, c.name AS client_name FROM pal_quotations q LEFT JOIN pal_clients c ON q.client_id = c.id WHERE q.tenant_id = :tid ORDER BY q.created_at {$sortDir} LIMIT :lim OFFSET :off");
        $stmt->bindValue(':tid', $tid, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return ['ok' => true, 'rows' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total' => $total];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

function pal_cap_entity_get_quotation_1(array $args): array
{
    try {
        $db = palDb();
        $id = (int)($args['id'] ?? 0);
        $tid = (int)(app()->tenant()->current() ?? 0);
        $stmt = $db->prepare("SELECT q.*, c.name AS client_name, p.title AS project_title FROM pal_quotations q LEFT JOIN pal_clients c ON q.client_id = c.id LEFT JOIN pal_projects p ON q.project_id = p.id WHERE q.id = :id AND q.tenant_id = :tid");
        $stmt->execute([':id' => $id, ':tid' => $tid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? ['ok' => true, 'data' => $row] : ['ok' => false, 'error' => 'Not found.'];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

function pal_cap_entity_list_quotation_item_1(array $args): array
{
    try {
        $db = palDb();
        $tid = (int)(app()->tenant()->current() ?? 0);
        $limit = min((int)($args['limit'] ?? 20), 100);
        $offset = (int)($args['offset'] ?? 0);
        $filters = $args['filters'] ?? [];

        $where = 'qi.tenant_id = :tid';
        $params = [':tid' => $tid];
        if (isset($filters['quotation_id'])) {
            $where .= ' AND qi.quotation_id = :qid';
            $params[':qid'] = (int)$filters['quotation_id'];
        }

        $countStmt = $db->prepare("SELECT COUNT(*) FROM pal_quotation_items qi WHERE {$where}");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $stmt = $db->prepare("SELECT qi.*, m.name AS material_name FROM pal_quotation_items qi LEFT JOIN pal_materials m ON qi.material_id = m.id WHERE {$where} ORDER BY qi.sort_order ASC LIMIT :lim OFFSET :off");
        $stmt->bindValue(':tid', $tid, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        if (isset($filters['quotation_id'])) $stmt->bindValue(':qid', (int)$filters['quotation_id'], PDO::PARAM_INT);
        $stmt->execute();
        return ['ok' => true, 'rows' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total' => $total];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

function pal_cap_entity_list_sale_item_1(array $args): array
{
    try {
        $db = palDb();
        $tid = (int)(app()->tenant()->current() ?? 0);
        $limit = min((int)($args['limit'] ?? 20), 100);
        $offset = (int)($args['offset'] ?? 0);
        $filters = $args['filters'] ?? [];

        $where = 'si.tenant_id = :tid';
        $params = [':tid' => $tid];
        if (isset($filters['sale_id'])) {
            $where .= ' AND si.sale_id = :sid';
            $params[':sid'] = (int)$filters['sale_id'];
        }

        $countStmt = $db->prepare("SELECT COUNT(*) FROM pal_sale_items si WHERE {$where}");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $stmt = $db->prepare("SELECT si.*, m.name AS material_name FROM pal_sale_items si LEFT JOIN pal_materials m ON si.material_id = m.id WHERE {$where} ORDER BY si.sort_order ASC LIMIT :lim OFFSET :off");
        $stmt->bindValue(':tid', $tid, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        if (isset($filters['sale_id'])) $stmt->bindValue(':sid', (int)$filters['sale_id'], PDO::PARAM_INT);
        $stmt->execute();
        return ['ok' => true, 'rows' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total' => $total];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

function pal_cap_entity_list_cash_advance_1(array $args): array
{
    try {
        $db = palDb();
        $tid = (int)(app()->tenant()->current() ?? 0);
        $limit = min((int)($args['limit'] ?? 20), 100);
        $offset = (int)($args['offset'] ?? 0);
        $sortDir = strtoupper((string)($args['sort']['direction'] ?? 'DESC'));
        $sortDir = in_array($sortDir, ['ASC', 'DESC'], true) ? $sortDir : 'DESC';

        $filters = $args['filters'] ?? [];
        $where = 'ca.tenant_id = :tid';
        $params = [':tid' => $tid];

        if (isset($filters['month']) && $filters['month'] !== '') {
            $where .= ' AND MONTH(ca.advance_date) = :month';
            $params[':month'] = str_pad((string)(int)$filters['month'], 2, '0', STR_PAD_LEFT);
        }
        if (isset($filters['year']) && $filters['year'] !== '') {
            $where .= ' AND YEAR(ca.advance_date) = :year';
            $params[':year'] = (int)$filters['year'];
        }

        $countStmt = $db->prepare("SELECT COUNT(*) FROM pal_cash_advances ca WHERE {$where}");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $stmt = $db->prepare("SELECT ca.*, tl.name AS team_lead_name, p.title AS project_title FROM pal_cash_advances ca LEFT JOIN pal_team_leads tl ON ca.team_lead_id = tl.id LEFT JOIN pal_projects p ON ca.project_id = p.id WHERE {$where} ORDER BY ca.advance_date {$sortDir} LIMIT :lim OFFSET :off");
        $stmt->bindValue(':tid', $tid, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        if (isset($filters['month']) && $filters['month'] !== '') $stmt->bindValue(':month', str_pad((string)(int)$filters['month'], 2, '0', STR_PAD_LEFT), PDO::PARAM_STR);
        if (isset($filters['year']) && $filters['year'] !== '') $stmt->bindValue(':year', (int)$filters['year'], PDO::PARAM_INT);
        $stmt->execute();
        return ['ok' => true, 'rows' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total' => $total];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

function pal_cap_entity_list_mobilization_1(array $args): array
{
    try {
        $db = palDb();
        $tid = (int)(app()->tenant()->current() ?? 0);
        $limit = min((int)($args['limit'] ?? 20), 100);
        $offset = (int)($args['offset'] ?? 0);
        $sortDir = strtoupper((string)($args['sort']['direction'] ?? 'DESC'));
        $sortDir = in_array($sortDir, ['ASC', 'DESC'], true) ? $sortDir : 'DESC';

        $count = $db->prepare("SELECT COUNT(*) FROM pal_mobilization_requests WHERE tenant_id = :tid");
        $count->execute([':tid' => $tid]);
        $total = (int)$count->fetchColumn();

        $stmt = $db->prepare("SELECT mr.*, tl.name AS team_lead_name, p.title AS project_title FROM pal_mobilization_requests mr LEFT JOIN pal_team_leads tl ON mr.team_lead_id = tl.id LEFT JOIN pal_projects p ON mr.project_id = p.id WHERE mr.tenant_id = :tid ORDER BY mr.created_at {$sortDir} LIMIT :lim OFFSET :off");
        $stmt->bindValue(':tid', $tid, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return ['ok' => true, 'rows' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total' => $total];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}
