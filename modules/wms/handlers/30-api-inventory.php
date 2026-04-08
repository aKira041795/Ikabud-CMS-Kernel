<?php

declare(strict_types=1);

function wmsApiStockSnapshot(array $params = []): void
{
    wmsResponseGuard(function (): void {
        wmsRequireAnyRole('admin', 'supervisor', 'viewer');
        $warehouseId = (int)wmsInput('warehouse_id', 0);
        $filters = [
            'product_id' => (int)wmsInput('product_id', 0),
            'location_id' => (int)wmsInput('location_id', 0),
            'q' => wmsSanitizeString(wmsInput('q', ''), 120),
        ];
        wmsJsonOk(['data' => wmsStockSnapshot($warehouseId, $filters)]);
    });
}

function wmsApiStockLow(array $params = []): void
{
    wmsResponseGuard(function (): void {
        wmsRequireAnyRole('admin', 'supervisor', 'viewer');
        $warehouseId = (int)wmsInput('warehouse_id', 0);
        $threshold = wmsInput('threshold', null);
        $threshold = $threshold === null || $threshold === '' ? null : (int)$threshold;
        wmsJsonOk(['data' => wmsLowStockItems($warehouseId, $threshold)]);
    });
}

function wmsApiMovementsList(array $params = []): void
{
    wmsResponseGuard(function (): void {
        wmsRequireAnyRole('admin', 'supervisor', 'viewer');
        wmsJsonOk(['data' => wmsMovementsList([
            'product_id' => (int)wmsInput('product_id', 0),
            'warehouse_id' => (int)wmsInput('warehouse_id', 0),
            'movement_type' => wmsSanitizeString(wmsInput('movement_type', ''), 40),
            'date_from' => wmsSanitizeString(wmsInput('date_from', ''), 20),
            'date_to' => wmsSanitizeString(wmsInput('date_to', ''), 20),
        ])]);
    });
}

function wmsApiPutawayRulesList(array $params = []): void
{
    wmsResponseGuard(function (): void {
        wmsRequireAnyRole('admin', 'supervisor', 'viewer');
        $warehouseId = (int)wmsInput('warehouse_id', 0);
        $where = ['1=1'];
        $bind = [];
        if ($warehouseId > 0) {
            $where[] = 'r.warehouse_id = ?';
            $bind[] = $warehouseId;
        }
        wmsJsonOk(['data' => wmsFetchAll(
            'SELECT r.*, w.name AS warehouse_name, p.name AS product_name
             FROM wms_putaway_rules r
             INNER JOIN wms_warehouses w ON w.id = r.warehouse_id
             LEFT JOIN wms_products p ON p.id = r.product_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY r.priority DESC, r.id DESC',
            $bind
        )]);
    });
}

function wmsApiPutawayRuleCreate(array $params = []): void
{
    wmsResponseGuard(function (): void {
        wmsRequireAnyRole('admin', 'supervisor');
        $warehouseId = (int)wmsInput('warehouse_id', 0);
        if ($warehouseId <= 0) {
            wmsJsonError('Warehouse is required.', 422);
        }
        wmsDb()->execute(
            'INSERT INTO wms_putaway_rules (warehouse_id, product_id, product_type, preferred_zone, preferred_location_id, min_qty, max_qty, priority, is_active, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
            [
                $warehouseId,
                ($productId = (int)wmsInput('product_id', 0)) > 0 ? $productId : null,
                wmsSanitizeString(wmsInput('product_type', ''), 50) ?: null,
                wmsSanitizeString(wmsInput('preferred_zone', ''), 50) ?: null,
                ($locationId = (int)wmsInput('preferred_location_id', 0)) > 0 ? $locationId : null,
                wmsInput('min_qty', '') !== '' ? wmsNormalizeDecimal(wmsInput('min_qty')) : null,
                wmsInput('max_qty', '') !== '' ? wmsNormalizeDecimal(wmsInput('max_qty')) : null,
                (int)wmsInput('priority', 100),
                (int)(bool)wmsInput('is_active', 1),
            ]
        );
        wmsJsonOk(['id' => (int)wmsDb()->lastInsertId()], 201);
    });
}

function wmsApiPutawayRuleUpdate(array $params = []): void
{
    wmsResponseGuard(function () use ($params): void {
        wmsRequireAnyRole('admin', 'supervisor');
        $id = (int)($params['id'] ?? 0);
        $existing = wmsFetchOne('SELECT * FROM wms_putaway_rules WHERE id = ? LIMIT 1', [$id]);
        if ($existing === null) {
            wmsJsonError('Rule not found.', 404);
        }
        wmsDb()->execute(
            'UPDATE wms_putaway_rules SET warehouse_id = ?, product_id = ?, product_type = ?, preferred_zone = ?, preferred_location_id = ?, min_qty = ?, max_qty = ?, priority = ?, is_active = ?, updated_at = NOW() WHERE id = ?',
            [
                (int)wmsInput('warehouse_id', $existing['warehouse_id'] ?? 0),
                ($productId = (int)wmsInput('product_id', $existing['product_id'] ?? 0)) > 0 ? $productId : null,
                wmsSanitizeString(wmsInput('product_type', $existing['product_type'] ?? ''), 50) ?: null,
                wmsSanitizeString(wmsInput('preferred_zone', $existing['preferred_zone'] ?? ''), 50) ?: null,
                ($locationId = (int)wmsInput('preferred_location_id', $existing['preferred_location_id'] ?? 0)) > 0 ? $locationId : null,
                wmsInput('min_qty', $existing['min_qty'] ?? '') !== '' ? wmsNormalizeDecimal(wmsInput('min_qty', $existing['min_qty'])) : null,
                wmsInput('max_qty', $existing['max_qty'] ?? '') !== '' ? wmsNormalizeDecimal(wmsInput('max_qty', $existing['max_qty'])) : null,
                (int)wmsInput('priority', $existing['priority'] ?? 100),
                (int)(bool)wmsInput('is_active', $existing['is_active'] ?? 1),
                $id,
            ]
        );
        wmsJsonOk(['id' => $id]);
    });
}

function wmsApiPutawaySuggest(array $params = []): void
{
    wmsResponseGuard(function (): void {
        wmsRequireAnyRole('admin', 'supervisor', 'viewer');
        $productId = (int)wmsInput('product_id', 0);
        $warehouseId = (int)wmsInput('warehouse_id', 0);
        if ($productId <= 0 || $warehouseId <= 0) {
            wmsJsonError('Product and warehouse are required.', 422);
        }
        wmsJsonOk(['data' => wmsPutAwaySuggest($productId, $warehouseId)]);
    });
}

function wmsApiReportStockSnapshot(array $params = []): void
{
    wmsResponseGuard(function (): void {
        wmsRequireAnyRole('admin', 'supervisor', 'viewer');
        wmsJsonOk([
            'generated_at' => date('c'),
            'data' => wmsStockSnapshot((int)wmsInput('warehouse_id', 0), [
                'product_id' => (int)wmsInput('product_id', 0),
                'location_id' => (int)wmsInput('location_id', 0),
                'q' => wmsSanitizeString(wmsInput('q', ''), 120),
            ]),
        ]);
    });
}

function wmsApiReportMovementHistory(array $params = []): void
{
    wmsResponseGuard(function (): void {
        wmsRequireAnyRole('admin', 'supervisor', 'viewer');
        wmsJsonOk([
            'generated_at' => date('c'),
            'data' => wmsMovementsList([
                'product_id' => (int)wmsInput('product_id', 0),
                'warehouse_id' => (int)wmsInput('warehouse_id', 0),
                'movement_type' => wmsSanitizeString(wmsInput('movement_type', ''), 40),
                'date_from' => wmsSanitizeString(wmsInput('date_from', ''), 20),
                'date_to' => wmsSanitizeString(wmsInput('date_to', ''), 20),
            ]),
        ]);
    });
}

function wmsApiReportVelocity(array $params = []): void
{
    wmsResponseGuard(function (): void {
        wmsRequireAnyRole('admin', 'supervisor', 'viewer');
        $days = max(1, (int)wmsInput('days', 30));
        wmsJsonOk(['generated_at' => date('c'), 'data' => wmsVelocityReport($days)]);
    });
}

function wmsApiReportExpiry(array $params = []): void
{
    wmsResponseGuard(function (): void {
        wmsRequireAnyRole('admin', 'supervisor', 'viewer');
        $days = max(1, (int)wmsInput('days', 30));
        wmsJsonOk(['generated_at' => date('c'), 'data' => wmsExpiryReport($days)]);
    });
}

function wmsApiStockAdjust(array $params = []): void
{
    wmsResponseGuard(function (): void {
        $user = wmsRequireAnyRole('admin', 'supervisor');
        $productId = (int)wmsInput('product_id', 0);
        $warehouseId = (int)wmsInput('warehouse_id', 0);
        $locationId = (int)wmsInput('location_id', 0);
        $qty = wmsNormalizeDecimal(wmsInput('qty', 0));
        $reason = wmsSanitizeString(wmsInput('reason', ''), 500);

        if ($productId <= 0 || $warehouseId <= 0 || $locationId <= 0) {
            wmsJsonError('Product, warehouse, and location are required.', 422);
        }
        if ($qty == 0.0) {
            wmsJsonError('Adjustment quantity must not be zero.', 422);
        }
        if ($reason === '') {
            wmsJsonError('Reason for adjustment is required.', 422);
        }

        $movementId = wmsMovementCreate([
            'movement_type' => 'adjustment',
            'reference_type' => 'manual_adjustment',
            'product_id' => $productId,
            'warehouse_id' => $warehouseId,
            'location_id' => $locationId,
            'batch_id' => ($batchId = (int)wmsInput('batch_id', 0)) > 0 ? $batchId : null,
            'qty' => $qty,
            'notes' => $reason,
            'actor_user_id' => (int)($user['id'] ?? 0),
            'meta' => ['reason' => $reason],
        ]);

        wmsAudit('wms.stock.adjusted', 'wms_stocks', null, null, [
            'product_id' => $productId,
            'warehouse_id' => $warehouseId,
            'location_id' => $locationId,
            'qty' => $qty,
            'reason' => $reason,
            'actor_id' => (int)($user['id'] ?? 0),
        ]);
        wmsJsonOk(['movement_id' => $movementId]);
    });
}
