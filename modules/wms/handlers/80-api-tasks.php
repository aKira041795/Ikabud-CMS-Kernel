<?php

declare(strict_types=1);

function wmsApiTasksList(array $params = []): void
{
    wmsResponseGuard(function (): void {
        wmsRequireStaff(['admin', 'supervisor', 'viewer']);
        $filters = wmsInput();
        wmsJsonOk(['data' => wmsTasksList($filters)]);
    });
}

function wmsApiTaskGet(array $params = []): void
{
    wmsResponseGuard(function () use ($params): void {
        wmsRequireStaff(['admin', 'supervisor', 'viewer']);
        $id = (int)($params['id'] ?? 0);
        $task = wmsFetchOne('SELECT * FROM wms_tasks WHERE id = ? LIMIT 1', [$id]);
        if ($task === null) {
            wmsJsonError('Task not found', 404);
        }
        wmsJsonOk(['data' => $task]);
    });
}

function wmsApiTaskCreate(array $params = []): void
{
    wmsResponseGuard(function (): void {
        wmsRequireStaff(['admin', 'supervisor']);
        $data = wmsInput();
        $id = wmsTaskCreate($data);
        wmsJsonOk(['id' => $id]);
    });
}

function wmsApiTaskUpdateStatus(array $params = []): void
{
    wmsResponseGuard(function () use ($params): void {
        $user = wmsRequireStaff(['admin', 'supervisor', 'viewer']);
        $id = (int)($params['id'] ?? 0);
        $data = wmsInput();
        $status = (string)($data['status'] ?? '');
        wmsTaskUpdateStatus($id, $status, (int)$user['id']);
        wmsJsonOk(['id' => $id]);
    });
}

function wmsApiTaskAssign(array $params = []): void
{
    wmsResponseGuard(function () use ($params): void {
        wmsRequireStaff(['admin', 'supervisor']);
        $id = (int)($params['id'] ?? 0);
        $data = wmsInput();
        $assigneeId = isset($data['assigned_to']) ? (int)$data['assigned_to'] : null;
        wmsDb()->execute('UPDATE wms_tasks SET assigned_to = ?, updated_at = NOW() WHERE id = ?', [$assigneeId, $id]);
        wmsJsonOk(['id' => $id]);
    });
}

function wmsApiTaskGenerateReplenishments(array $params = []): void
{
    wmsResponseGuard(function (): void {
        wmsRequireStaff(['admin', 'supervisor']);
        $data = wmsInput();
        $warehouseId = (int)($data['warehouse_id'] ?? 0);
        if ($warehouseId <= 0) {
            wmsJsonError('Warehouse ID is required.');
        }
        $count = wmsGenerateReplenishmentTasks($warehouseId);
        wmsJsonOk(['tasks_created' => $count]);
    });
}

