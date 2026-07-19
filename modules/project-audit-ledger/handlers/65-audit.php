<?php

declare(strict_types=1);

function palPageAuditTrail(): void
{
    $u = palRequireRole('admin');
    $db = palDb();
    $tid = (int)($u['tenant_id'] ?? 0);

    $audit = $db->prepare(
        "SELECT a.*, actuser.full_name AS actor_name
         FROM pal_audit_logs a
         LEFT JOIN pal_users actuser ON a.actor_user_id = actuser.id
         WHERE a.tenant_id = :tid
         ORDER BY a.created_at DESC
         LIMIT 100"
    );
    $audit->execute([':tid' => $tid]);
    $rows = $audit->fetchAll(PDO::FETCH_ASSOC);

    // Collect entity references for batch name resolution
    $entityRefs = [];
    foreach ($rows as $r) {
        $et = $r['entity_type'] ?? '';
        $eid = $r['entity_id'] ?? '';
        if ($et !== '' && $eid !== '' && $et !== 'pal_users') {
            $entityRefs[$et][] = $eid;
        }
    }

    // Resolve entity names in batch
    $entityNames = [];
    foreach ($entityRefs as $table => $ids) {
        $ids = array_unique(array_map('intval', $ids));
        $ids = array_filter($ids, fn($v) => $v > 0);
        if (empty($ids)) continue;
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $nameCol = match ($table) {
            'pal_projects', 'project' => 'title',
            'pal_clients' => 'name',
            'pal_suppliers' => 'name',
            'pal_materials' => 'name',
            'pal_expenses', 'pal_expense_categories' => 'name',
            'pal_purchases' => 'purchase_number',
            'pal_sales' => 'sales_number',
            'pal_collections' => 'collection_number',
            'pal_material_issuances' => 'issuance_number',
            'pal_material_categories' => 'name',
            'pal_project_types' => 'name',
            'pal_units' => 'name',
            'pal_team_leads' => 'name',
            'pal_inventory_locations' => 'name',
            'pal_fabrication_allocations' => 'id',
            'pal_fabrication_weekly_dues' => 'id',
            'pal_fabrication_payments' => 'id',
            'pal_attachments' => 'original_filename',
            'pal_report_exports' => 'id',
            'pal_settings' => 'id',
            'pal_mobilization_requests', 'mobilization' => 'purpose',
            'pal_approvals' => 'entity_type',
            'pal_users' => 'full_name',
            'pal_receivables' => 'id',
            'pal_cash_advances' => 'id',
            default => null,
        };
        if ($nameCol === null) continue;
        try {
            $stmt = $db->prepare("SELECT id, {$nameCol} AS name FROM {$table} WHERE id IN ({$placeholders}) AND tenant_id = ?");
            $params = array_merge($ids, [$tid]);
            $stmt->execute($params);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $entityNames[$table . ':' . $row['id']] = $row['name'];
            }
        } catch (Throwable) {
            // Table may not exist or other transient error — skip gracefully
        }
    }

    // Format each log entry
    $actionLabels = [
        'pal.auth.login_failed' => ['label' => 'Login Failed', 'icon' => '🔒'],
        'pal.auth.login' => ['label' => 'Logged In', 'icon' => '🔓'],
        'pal.auth.forgot_password' => ['label' => 'Password Reset Requested', 'icon' => '🔑'],
        'pal.auth.password_reset' => ['label' => 'Password Reset', 'icon' => '🔑'],
        'pal.project.created' => ['label' => 'Project Created', 'icon' => '📁'],
        'pal.project.updated' => ['label' => 'Project Updated', 'icon' => '📝'],
        'pal.project.completed' => ['label' => 'Project Completed', 'icon' => '✅'],
        'pal.expense.created' => ['label' => 'Expense Recorded', 'icon' => '💳'],
        'pal.expense.updated' => ['label' => 'Expense Updated', 'icon' => '💳'],
        'pal.expense.submitted' => ['label' => 'Expense Submitted', 'icon' => '📤'],
        'pal.purchase.submitted' => ['label' => 'Purchase Submitted', 'icon' => '📤'],
        'pal.material.updated' => ['label' => 'Material Updated', 'icon' => '📦'],
        'pal.issuance.submitted' => ['label' => 'Issuance Submitted', 'icon' => '📤'],
        'pal.inventory.adjusted' => ['label' => 'Inventory Adjusted', 'icon' => '📊'],
        'pal.fabrication.allocation_created' => ['label' => 'Fabrication Allocation Created', 'icon' => '🔧'],
        'pal.fabrication.allocation_updated' => ['label' => 'Fabrication Allocation Updated', 'icon' => '🔧'],
        'pal.fabrication.payment_recorded' => ['label' => 'Fabrication Payment Recorded', 'icon' => '💰'],
        'pal.fabrication.payment_submitted' => ['label' => 'Fabrication Payment Submitted', 'icon' => '📤'],
        'pal.attachment.uploaded' => ['label' => 'Attachment Uploaded', 'icon' => '📎'],
        'pal.attachment.deleted' => ['label' => 'Attachment Deleted', 'icon' => '🗑️'],
        'pal.collection.recorded' => ['label' => 'Collection Recorded', 'icon' => '💵'],
        'pal.approval.approved' => ['label' => 'Approved', 'icon' => '✅'],
        'pal.approval.rejected' => ['label' => 'Rejected', 'icon' => '❌'],
        'pal.approval.returned' => ['label' => 'Returned for Revision', 'icon' => '↩️'],
        'pal.user.created' => ['label' => 'User Created', 'icon' => '👤'],
        'pal.user.updated' => ['label' => 'User Updated', 'icon' => '👤'],
        'pal.user.deactivated' => ['label' => 'User Deactivated', 'icon' => '🚫'],
        'pal.user.restored' => ['label' => 'User Restored', 'icon' => '✅'],
        'pal.settings.updated' => ['label' => 'Settings Updated', 'icon' => '⚙️'],
        'pal.report.generated' => ['label' => 'Report Generated', 'icon' => '📈'],
    ];

    $formatted = [];
    foreach ($rows as $r) {
        $action = $r['action'] ?? '';
        $actionInfo = $actionLabels[$action] ?? ['label' => $action, 'icon' => '📋'];

        // Build entity display
        $et = $r['entity_type'] ?? '';
        $eid = $r['entity_id'] ?? '';
        $entityDisplay = $et;
        $entityName = null;
        if ($et !== '' && $eid !== '') {
            $entityName = $entityNames[$et . ':' . $eid] ?? null;
            if ($entityName !== null) {
                $entityDisplay = $entityName;
            }
        }

        // Parse new_data JSON for details
        $details = '';
        $newData = $r['new_data'] ?? null;
        if ($newData !== null && $newData !== '') {
            $parsed = json_decode($newData, true);
            if (is_array($parsed)) {
                $parts = [];
                foreach ($parsed as $k => $v) {
                    if ($k === 'title' || $k === 'amount' || $k === 'status' || $k === 'type' || $k === 'format' || $k === 'username' || $k === 'filename' || $k === 'updated_fields') {
                        if ($k === 'updated_fields' && is_array($v)) {
                            $parts[] = 'Updated: ' . implode(', ', $v);
                        } elseif (is_scalar($v) && $v !== '' && $v !== null) {
                            $parts[] = ucfirst(str_replace('_', ' ', $k)) . ': ' . $v;
                        }
                    }
                }
                if (!empty($parts)) {
                    $details = implode(' | ', $parts);
                }
            }
        }

        $formatted[] = [
            'created_at' => $r['created_at'],
            'actor_name' => $r['actor_name'] ?? ('User #' . ($r['actor_user_id'] ?? '?')),
            'action_label' => $actionInfo['label'],
            'action_icon' => $actionInfo['icon'],
            'entity_display' => $entityDisplay,
            'entity_type' => $et,
            'entity_id' => $eid,
            'details' => $details,
        ];
    }

    $t = __DIR__ . '/../templates/project-audit-ledger/shell.disyl';
    palRender($t, [
        'current_user' => $u,
        'page_title' => 'Audit Trail',
        'page_content' => 'audit-trail',
        'audit_logs' => $formatted,
    ]);
}
