<?php

declare(strict_types=1);

use PDO;
use Throwable;

function wmsTaskTypes(): array
{
    return ['putaway', 'pick', 'transfer', 'count', 'replenish'];
}

function wmsTaskStatuses(): array
{
    return ['pending', 'in_progress', 'completed', 'cancelled'];
}

function wmsTaskCreate(array $data): int
{
    $type = wmsSanitizeString($data['task_type'] ?? '', 50);
    if (!in_array($type, wmsTaskTypes(), true)) {
        throw new RuntimeException('Invalid task type.');
    }

    $warehouseId = wmsRequirePositiveId((int)($data['warehouse_id'] ?? 0), 'Warehouse ID');
    
    $priority = (int)($data['priority'] ?? 50);
    $status = wmsSanitizeString($data['status'] ?? 'pending', 50);
    
    $db = wmsDb();
    $db->execute(
        'INSERT INTO wms_tasks (warehouse_id, task_type, status, priority, reference_type, reference_id, assigned_to, due_at, notes, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())',
        [
            $warehouseId,
            $type,
            $status,
            $priority,
            isset($data['reference_type']) ? wmsSanitizeString($data['reference_type'], 50) : null,
            isset($data['reference_id']) ? (int)$data['reference_id'] : null,
            isset($data['assigned_to']) ? (int)$data['assigned_to'] : null,
            isset($data['due_at']) ? wmsSanitizeString($data['due_at'], 50) : null,
            isset($data['notes']) ? wmsSanitizeString($data['notes'], 2000) : null
        ]
    );

    return (int)$db->lastInsertId();
}

function wmsTasksList(array $filters = []): array
{
    $where = ['1=1'];
    $params = [];

    $warehouseId = (int)($filters['warehouse_id'] ?? 0);
    if ($warehouseId > 0) {
        $where[] = 'warehouse_id = ?';
        $params[] = $warehouseId;
    }

    $status = trim((string)($filters['status'] ?? ''));
    if ($status !== '') {
        $where[] = 'status = ?';
        $params[] = $status;
    }
    
    $type = trim((string)($filters['task_type'] ?? ''));
    if ($type !== '') {
        $where[] = 'task_type = ?';
        $params[] = $type;
    }

    $assignedTo = (int)($filters['assigned_to'] ?? 0);
    if ($assignedTo > 0) {
        $where[] = 'assigned_to = ?';
        $params[] = $assignedTo;
    }

    return wmsFetchAll(
        'SELECT * FROM wms_tasks WHERE ' . implode(' AND ', $where) . ' ORDER BY priority ASC, created_at ASC LIMIT 500',
        $params
    );
}

function wmsTaskUpdateStatus(int $taskId, string $status, ?int $actorUserId = null): void
{
    if (!in_array($status, wmsTaskStatuses(), true)) {
        throw new RuntimeException('Invalid task status.');
    }

    $task = wmsFetchOne('SELECT * FROM wms_tasks WHERE id = ? LIMIT 1 FOR UPDATE', [$taskId]);
    if ($task === null) {
        throw new RuntimeException('Task not found.');
    }

    $updates = ['status = ?', 'updated_at = NOW()'];
    $params = [$status];

    if ($status === 'in_progress' && $task['started_at'] === null) {
        $updates[] = 'started_at = NOW()';
    } elseif ($status === 'completed' || $status === 'cancelled') {
        $updates[] = 'completed_at = NOW()';
    }

    if ($actorUserId !== null) {
        $updates[] = 'assigned_to = ?';
        $params[] = $actorUserId;
    }
    
    $params[] = $taskId;

    wmsDb()->execute('UPDATE wms_tasks SET ' . implode(', ', $updates) . ' WHERE id = ?', $params);
}

function wmsGenerateReplenishmentTasks(int $warehouseId): int
{
    // Auto-replenishment logic: internal bulk storage to picking bins
    // Simple naive implementation: find products below reorder_point, 
    // identify bulk stock vs picking stock, create transfer task.
    
    $products = wmsFetchAll(
        'SELECT p.id, p.reorder_point, p.safety_stock, s.qty_available 
         FROM wms_products p 
         JOIN (
            SELECT product_id, SUM(qty_available) as qty_available 
            FROM wms_stocks 
            WHERE warehouse_id = ? 
            GROUP BY product_id
         ) s ON s.product_id = p.id
         WHERE p.reorder_point > 0 AND s.qty_available <= p.reorder_point AND p.deleted_at IS NULL',
        [$warehouseId]
    );

    $count = 0;
    foreach ($products as $p) {
        // Create a 'replenish' task to move inventory
        $targetQty = max(0, $p['safety_stock'] - $p['qty_available']);
        if ($targetQty > 0) {
            wmsTaskCreate([
                'warehouse_id' => $warehouseId,
                'task_type' => 'replenish',
                'priority' => 10, // high priority for replenishment
                'notes' => 'Auto-replenishment generated. Target qty: ' . $targetQty . ' for product ID: ' . $p['id']
            ]);
            $count++;
        }
    }
    return $count;
}

function wms_cap_wms_replenishment_suggest_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    $warehouseId = (int)($payload['warehouse_id'] ?? 0);
    if ($warehouseId <= 0) {
        return ['ok' => false, 'error' => 'Warehouse ID required'];
    }

    $created = wmsGenerateReplenishmentTasks($warehouseId);
    return ['ok' => true, 'tasks_created' => $created];
}
