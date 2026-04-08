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
        wmsJsonOk(['data' => wmsTaskGetDetailed($id)]);
    });
}

function wmsApiTaskExceptionsList(array $params = []): void
{
    wmsResponseGuard(function (): void {
        wmsRequireStaff(['admin', 'supervisor', 'viewer']);
        $filters = wmsInput();
        wmsJsonOk(['data' => wmsTaskExceptionsList($filters)]);
    });
}

function wmsApiTaskCreate(array $params = []): void
{
    wmsResponseGuard(function (): void {
        wmsRequireStaff(['admin', 'supervisor']);
        $data = wmsInput();
        $id = wmsTaskCreate($data);
        wmsJsonOk(['id' => $id, 'data' => wmsTaskGetDetailed($id)]);
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
        wmsJsonOk(['id' => $id, 'data' => wmsTaskGetDetailed($id)]);
    });
}

function wmsApiTaskScanConfirm(array $params = []): void
{
    wmsResponseGuard(function () use ($params): void {
        $user = wmsRequireStaff(['admin', 'supervisor', 'viewer']);
        $id = (int)($params['id'] ?? 0);
        $payload = is_array(wmsInput()) ? wmsInput() : [];
        $result = wmsTaskScanConfirm($id, $payload, (int)$user['id']);
        wmsJsonOk($result);
    });
}

function wmsApiTaskAssign(array $params = []): void
{
    wmsResponseGuard(function () use ($params): void {
        wmsRequireStaff(['admin', 'supervisor']);
        $id = (int)($params['id'] ?? 0);
        $data = wmsInput();
        $assigneeId = isset($data['assigned_to']) && (int)$data['assigned_to'] > 0 ? (int)$data['assigned_to'] : null;
        if (wmsTaskFind($id) === null) {
            wmsJsonError('Task not found.', 404);
        }
        wmsDb()->execute('UPDATE wms_tasks SET assigned_to = ?, updated_at = NOW() WHERE id = ?', [$assigneeId, $id]);
        wmsJsonOk(['id' => $id, 'data' => wmsTaskGetDetailed($id)]);
    });
}

function wmsApiTaskExceptionResolve(array $params = []): void
{
    wmsResponseGuard(function () use ($params): void {
        $user = wmsRequireStaff(['admin', 'supervisor']);
        $id = (int)($params['id'] ?? 0);
        $resolutionNote = (string)wmsInput('resolution_note', 'Resolved from exception queue.');
        $exception = wmsTaskExceptionResolve($id, $resolutionNote, (int)$user['id']);
        wmsJsonOk(['data' => $exception]);
    });
}

function wmsApiTaskExceptionDisposition(array $params = []): void
{
    wmsResponseGuard(function () use ($params): void {
        $user = wmsRequireStaff(['admin', 'supervisor']);
        $id = (int)($params['id'] ?? 0);
        $payload = is_array(wmsInput()) ? wmsInput() : [];
        $dispositionType = wmsSanitizeString($payload['disposition_type'] ?? '', 50);
        if ($dispositionType === '') {
            wmsJsonError('Disposition type is required.', 422);
        }

        $exception = wmsTaskExceptionDisposition($id, $dispositionType, $payload, (int)$user['id']);
        wmsJsonOk(['data' => $exception]);
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

