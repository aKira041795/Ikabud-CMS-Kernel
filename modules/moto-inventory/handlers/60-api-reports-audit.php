<?php

declare(strict_types=1);

/**
 * Moto Inventory — Reports, Audit, and Branch management API handlers.
 */

function motoApiProfit(array $params = []): void
{
    moto_api_guard(static function (): void {
        $ctx = moto_ctx();
        moto_require_permission($ctx, 'moto_inventory.view_profit');
        $requestedBranch = (int)($_GET['branch_id'] ?? 0) ?: null;
        $branch = moto_resolve_branch_scope($ctx, $requestedBranch, false);
        $filters = [
            'branch_id' => $branch,
            'range'     => (string)($_GET['range'] ?? 'custom'),
            'from'      => (string)($_GET['from'] ?? ''),
            'to'        => (string)($_GET['to'] ?? ''),
        ];
        moto_json_ok(SaleService::profit($ctx, $filters));
    });
}

function motoApiAudit(array $params = []): void
{
    moto_api_guard(static function (): void {
        $ctx = moto_ctx();
        moto_require_permission($ctx, 'moto_inventory.view_audit');
        $db = moto_db((int)$ctx['tenant_id']);
        $tid = (int)$ctx['tenant_id'];

        $where = ['a.tenant_id = :tid'];
        $paramsSql = [':tid' => $tid];

        $requestedBranch = (int)($_GET['branch_id'] ?? 0) ?: null;
        if ($requestedBranch > 0) {
            $branch = moto_resolve_branch_scope($ctx, $requestedBranch, false);
            if ($branch !== null) {
                $where[] = 'a.branch_id = :bid';
                $paramsSql[':bid'] = $branch;
            }
        } elseif (!$ctx['view_all_branches'] && $ctx['branch_ids'] !== []) {
            $ids = implode(',', array_map('intval', $ctx['branch_ids']));
            $where[] = 'a.branch_id IN (' . $ids . ')';
        } elseif (!$ctx['view_all_branches']) {
            $where[] = '1 = 0';
        }

        if (!empty($_GET['from'])) {
            $where[] = 'a.created_at >= :from';
            $paramsSql[':from'] = (string)$_GET['from'] . ' 00:00:00';
        }
        if (!empty($_GET['to'])) {
            $where[] = 'a.created_at <= :to';
            $paramsSql[':to'] = (string)$_GET['to'] . ' 23:59:59';
        }
        $limit = max(1, min(500, (int)($_GET['limit'] ?? 100)));
        $whereSql = implode(' AND ', $where);

        $stmt = $db->query(
            "SELECT a.id, a.branch_id, a.actor_user_id, a.actor_name, a.action, a.target_type, a.target_id,
                    a.request_id, a.idempotency_key, a.before_data, a.after_data, a.created_at,
                    b.name AS branch_name
             FROM moto_audit_log a
             LEFT JOIN moto_branches b ON b.id = a.branch_id
             WHERE {$whereSql}
             ORDER BY a.id DESC
             LIMIT {$limit}",
            $paramsSql
        );
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$row) {
            $row['before_data'] = $row['before_data'] !== null ? json_decode((string)$row['before_data'], true) : null;
            $row['after_data'] = $row['after_data'] !== null ? json_decode((string)$row['after_data'], true) : null;
        }
        unset($row);

        moto_json_ok(['audit' => $rows]);
    });
}

function motoApiBranchCreate(array $params = []): void
{
    moto_api_guard(static function (): void {
        $ctx = moto_ctx();
        moto_require_permission($ctx, 'moto_inventory.manage');
        $input = moto_input();
        $branchKey = strtolower(trim((string)($input['branch_key'] ?? '')));
        $name = trim((string)($input['name'] ?? ''));

        if ($branchKey === '' || !preg_match('/^[a-z0-9-]+$/', $branchKey)) {
            moto_json_error('Branch key must be lowercase letters, numbers, or dashes');
            return;
        }
        if ($name === '') {
            moto_json_error('Branch name is required');
            return;
        }

        $db = moto_db((int)$ctx['tenant_id']);
        $stmt = $db->prepare('SELECT id FROM moto_branches WHERE tenant_id = :tid AND branch_key = :key LIMIT 1');
        $stmt->execute([':tid' => (int)$ctx['tenant_id'], ':key' => $branchKey]);
        if ($stmt->fetchColumn() !== false) {
            moto_json_error('Branch key already exists');
            return;
        }

        $db->prepare('INSERT INTO moto_branches (tenant_id, branch_key, name) VALUES (:tid, :key, :name)')
            ->execute([':tid' => (int)$ctx['tenant_id'], ':key' => $branchKey, ':name' => $name]);
        $id = (int)$db->lastInsertId();

        moto_audit($ctx, 'moto_inventory.branch.created', 'moto_branch', (string)$id, null, ['branch_key' => $branchKey, 'name' => $name]);
        moto_json_ok(['id' => $id, 'branch_key' => $branchKey, 'name' => $name], 201);
    });
}

function motoApiBranchAssign(array $params = []): void
{
    moto_api_guard(static function () use ($params): void {
        $ctx = moto_ctx();
        moto_require_permission($ctx, 'moto_inventory.manage');
        $input = moto_input();
        $branchId = (int)$params['id'];
        $userId = (int)($input['user_id'] ?? 0);
        $assigned = !empty($input['assigned']);

        if ($userId <= 0) {
            moto_json_error('user_id is required');
            return;
        }

        $db = moto_db((int)$ctx['tenant_id']);
        $tid = (int)$ctx['tenant_id'];

        $stmt = $db->prepare('SELECT id FROM moto_branches WHERE tenant_id = :tid AND id = :bid LIMIT 1');
        $stmt->execute([':tid' => $tid, ':bid' => $branchId]);
        if ($stmt->fetchColumn() === false) {
            moto_json_error('Branch not found');
            return;
        }

        if ($assigned) {
            $db->prepare('INSERT IGNORE INTO moto_user_branches (tenant_id, user_id, branch_id) VALUES (:tid, :uid, :bid)')
                ->execute([':tid' => $tid, ':uid' => $userId, ':bid' => $branchId]);
        } else {
            $db->prepare('DELETE FROM moto_user_branches WHERE tenant_id = :tid AND user_id = :uid AND branch_id = :bid')
                ->execute([':tid' => $tid, ':uid' => $userId, ':bid' => $branchId]);
        }

        moto_audit($ctx, 'moto_inventory.branch.assignment.updated', 'moto_branch', (string)$branchId, null, [
            'user_id' => $userId, 'assigned' => $assigned,
        ], $branchId);

        moto_json_ok(['branch_id' => $branchId, 'user_id' => $userId, 'assigned' => $assigned]);
    });
}
