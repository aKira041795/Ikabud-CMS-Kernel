<?php

declare(strict_types=1);

function wmsApiProductsList(array $params = []): void
{
    wmsResponseGuard(function (): void {
        wmsRequireAnyRole('admin', 'supervisor', 'viewer');
        $filters = [
            'q' => wmsSanitizeString(wmsInput('q', ''), 120),
            'type' => wmsSanitizeString(wmsInput('type', ''), 50),
            'active' => wmsInput('active', ''),
        ];

        $where = ['deleted_at IS NULL'];
        $bind = [];
        if ($filters['q'] !== '') {
            $where[] = '(sku LIKE ? OR name LIKE ? OR barcode LIKE ?)';
            $bind[] = wmsSqlLike($filters['q']);
            $bind[] = wmsSqlLike($filters['q']);
            $bind[] = wmsSqlLike($filters['q']);
        }
        if ($filters['type'] !== '') {
            $where[] = 'product_type = ?';
            $bind[] = $filters['type'];
        }
        if ($filters['active'] !== '') {
            $where[] = 'is_active = ?';
            $bind[] = (int)(bool)$filters['active'];
        }

        wmsJsonOk([
            'data' => wmsFetchAll('SELECT * FROM wms_products WHERE ' . implode(' AND ', $where) . ' ORDER BY name ASC', $bind),
        ]);
    });
}

function wmsApiProductGet(array $params = []): void
{
    wmsResponseGuard(function () use ($params): void {
        wmsRequireAnyRole('admin', 'supervisor', 'viewer');
        $id = (int)($params['id'] ?? 0);
        $product = wmsFetchOne('SELECT * FROM wms_products WHERE id = ? AND deleted_at IS NULL LIMIT 1', [$id]);
        if ($product === null) {
            wmsJsonError('Product not found.', 404);
        }
        wmsJsonOk(['data' => $product]);
    });
}

function wmsApiProductCreate(array $params = []): void
{
    wmsResponseGuard(function (): void {
        $user = wmsRequireAnyRole('admin', 'supervisor');
        $sku = wmsSanitizeString(wmsInput('sku', ''), 100);
        $name = wmsSanitizeString(wmsInput('name', ''), 255);
        if ($sku === '' || $name === '') {
            wmsJsonError('SKU and name are required.', 422);
        }

        wmsDb()->execute(
            'INSERT INTO wms_products (sku, barcode, name, description, unit, product_type, weight, dimensions, is_batch_tracked, meta, is_active, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
            [
                $sku,
                wmsSanitizeString(wmsInput('barcode', ''), 100) ?: null,
                $name,
                wmsSanitizeString(wmsInput('description', ''), 5000) ?: null,
                wmsSanitizeString(wmsInput('unit', 'pcs'), 50),
                wmsSanitizeString(wmsInput('product_type', 'physical'), 50),
                wmsInput('weight', null) !== null && wmsInput('weight', '') !== '' ? wmsNormalizeDecimal(wmsInput('weight')) : null,
                ($dims = wmsJsonDecodeArray(wmsInput('dimensions', []))) !== [] ? json_encode($dims, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                (int)(bool)wmsInput('is_batch_tracked', 0),
                ($meta = wmsJsonDecodeArray(wmsInput('meta', []))) !== [] ? json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                (int)(bool)wmsInput('is_active', 1),
            ]
        );

        $id = (int)wmsDb()->lastInsertId();
        wmsAudit('wms.product.created', 'wms_products', (string)$id, null, ['id' => $id, 'sku' => $sku, 'name' => $name, 'actor_id' => (int)($user['id'] ?? 0)]);
        wmsJsonOk(['id' => $id], 201);
    });
}

function wmsApiProductUpdate(array $params = []): void
{
    wmsResponseGuard(function () use ($params): void {
        $user = wmsRequireAnyRole('admin', 'supervisor');
        $id = (int)($params['id'] ?? 0);
        $existing = wmsFetchOne('SELECT * FROM wms_products WHERE id = ? AND deleted_at IS NULL LIMIT 1', [$id]);
        if ($existing === null) {
            wmsJsonError('Product not found.', 404);
        }

        wmsDb()->execute(
            'UPDATE wms_products SET sku = ?, barcode = ?, name = ?, description = ?, unit = ?, product_type = ?, weight = ?, dimensions = ?, is_batch_tracked = ?, meta = ?, is_active = ?, updated_at = NOW() WHERE id = ?',
            [
                wmsSanitizeString(wmsInput('sku', $existing['sku'] ?? ''), 100),
                wmsSanitizeString(wmsInput('barcode', $existing['barcode'] ?? ''), 100) ?: null,
                wmsSanitizeString(wmsInput('name', $existing['name'] ?? ''), 255),
                wmsSanitizeString(wmsInput('description', $existing['description'] ?? ''), 5000) ?: null,
                wmsSanitizeString(wmsInput('unit', $existing['unit'] ?? 'pcs'), 50),
                wmsSanitizeString(wmsInput('product_type', $existing['product_type'] ?? 'physical'), 50),
                wmsInput('weight', $existing['weight'] ?? null) !== null && wmsInput('weight', $existing['weight'] ?? '') !== '' ? wmsNormalizeDecimal(wmsInput('weight', $existing['weight'])) : null,
                ($dims = wmsJsonDecodeArray(wmsInput('dimensions', $existing['dimensions'] ?? []))) !== [] ? json_encode($dims, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                (int)(bool)wmsInput('is_batch_tracked', $existing['is_batch_tracked'] ?? 0),
                ($meta = wmsJsonDecodeArray(wmsInput('meta', $existing['meta'] ?? []))) !== [] ? json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                (int)(bool)wmsInput('is_active', $existing['is_active'] ?? 1),
                $id,
            ]
        );

        wmsAudit('wms.product.updated', 'wms_products', (string)$id, $existing, ['actor_id' => (int)($user['id'] ?? 0)]);
        wmsJsonOk(['id' => $id]);
    });
}

function wmsApiProductDelete(array $params = []): void
{
    wmsResponseGuard(function () use ($params): void {
        $user = wmsRequireAnyRole('admin');
        $id = (int)($params['id'] ?? 0);
        $existing = wmsFetchOne('SELECT * FROM wms_products WHERE id = ? AND deleted_at IS NULL LIMIT 1', [$id]);
        if ($existing === null) {
            wmsJsonError('Product not found.', 404);
        }
        wmsDb()->execute('UPDATE wms_products SET deleted_at = NOW(), is_active = 0, updated_at = NOW() WHERE id = ?', [$id]);
        wmsAudit('wms.product.deleted', 'wms_products', (string)$id, $existing, ['actor_id' => (int)($user['id'] ?? 0)]);
        wmsJsonOk(['id' => $id]);
    });
}

function wmsApiWarehousesList(array $params = []): void
{
    wmsResponseGuard(function (): void {
        wmsRequireAnyRole('admin', 'supervisor', 'viewer');
        wmsJsonOk(['data' => wmsFetchAll('SELECT * FROM wms_warehouses WHERE deleted_at IS NULL ORDER BY name ASC')]);
    });
}

function wmsApiWarehouseGet(array $params = []): void
{
    wmsResponseGuard(function () use ($params): void {
        wmsRequireAnyRole('admin', 'supervisor', 'viewer');
        $warehouse = wmsFetchOne('SELECT * FROM wms_warehouses WHERE id = ? AND deleted_at IS NULL LIMIT 1', [(int)($params['id'] ?? 0)]);
        if ($warehouse === null) {
            wmsJsonError('Warehouse not found.', 404);
        }
        wmsJsonOk(['data' => $warehouse]);
    });
}

function wmsApiWarehouseCreate(array $params = []): void
{
    wmsResponseGuard(function (): void {
        wmsRequireAnyRole('admin', 'supervisor');
        $code = wmsSanitizeString(wmsInput('code', ''), 50);
        $name = wmsSanitizeString(wmsInput('name', ''), 255);
        if ($code === '' || $name === '') {
            wmsJsonError('Code and name are required.', 422);
        }
        wmsDb()->execute(
            'INSERT INTO wms_warehouses (code, name, address, contact_info, is_active, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, NOW(), NOW())',
            [
                $code,
                $name,
                wmsSanitizeString(wmsInput('address', ''), 5000) ?: null,
                ($contact = wmsJsonDecodeArray(wmsInput('contact_info', []))) !== [] ? json_encode($contact, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                (int)(bool)wmsInput('is_active', 1),
            ]
        );
        wmsJsonOk(['id' => (int)wmsDb()->lastInsertId()], 201);
    });
}

function wmsApiWarehouseUpdate(array $params = []): void
{
    wmsResponseGuard(function () use ($params): void {
        wmsRequireAnyRole('admin', 'supervisor');
        $id = (int)($params['id'] ?? 0);
        $existing = wmsFetchOne('SELECT * FROM wms_warehouses WHERE id = ? AND deleted_at IS NULL LIMIT 1', [$id]);
        if ($existing === null) {
            wmsJsonError('Warehouse not found.', 404);
        }
        wmsDb()->execute(
            'UPDATE wms_warehouses SET code = ?, name = ?, address = ?, contact_info = ?, is_active = ?, updated_at = NOW() WHERE id = ?',
            [
                wmsSanitizeString(wmsInput('code', $existing['code'] ?? ''), 50),
                wmsSanitizeString(wmsInput('name', $existing['name'] ?? ''), 255),
                wmsSanitizeString(wmsInput('address', $existing['address'] ?? ''), 5000) ?: null,
                ($contact = wmsJsonDecodeArray(wmsInput('contact_info', $existing['contact_info'] ?? []))) !== [] ? json_encode($contact, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                (int)(bool)wmsInput('is_active', $existing['is_active'] ?? 1),
                $id,
            ]
        );
        wmsJsonOk(['id' => $id]);
    });
}

function wmsApiLocationsList(array $params = []): void
{
    wmsResponseGuard(function (): void {
        wmsRequireAnyRole('admin', 'supervisor', 'viewer');
        $where = ['deleted_at IS NULL'];
        $params = [];
        $warehouseId = (int)wmsInput('warehouse_id', 0);
        if ($warehouseId > 0) {
            $where[] = 'warehouse_id = ?';
            $params[] = $warehouseId;
        }
        $parentId = (int)wmsInput('parent_id', 0);
        if ($parentId > 0) {
            $where[] = 'parent_id = ?';
            $params[] = $parentId;
        }
        $type = wmsSanitizeString(wmsInput('type', ''), 30);
        if ($type !== '') {
            $where[] = 'type = ?';
            $params[] = $type;
        }
        wmsJsonOk(['data' => wmsFetchAll('SELECT * FROM wms_locations WHERE ' . implode(' AND ', $where) . ' ORDER BY code ASC', $params)]);
    });
}

function wmsApiLocationGet(array $params = []): void
{
    wmsResponseGuard(function () use ($params): void {
        wmsRequireAnyRole('admin', 'supervisor', 'viewer');
        $location = wmsFetchOne('SELECT * FROM wms_locations WHERE id = ? AND deleted_at IS NULL LIMIT 1', [(int)($params['id'] ?? 0)]);
        if ($location === null) {
            wmsJsonError('Location not found.', 404);
        }
        wmsJsonOk(['data' => $location]);
    });
}

function wmsApiLocationChildren(array $params = []): void
{
    wmsResponseGuard(function () use ($params): void {
        wmsRequireAnyRole('admin', 'supervisor', 'viewer');
        wmsJsonOk(['data' => wmsFetchAll('SELECT * FROM wms_locations WHERE parent_id = ? AND deleted_at IS NULL ORDER BY code ASC', [(int)($params['id'] ?? 0)])]);
    });
}

function wmsApiLocationCreate(array $params = []): void
{
    wmsResponseGuard(function (): void {
        wmsRequireAnyRole('admin', 'supervisor');
        $warehouseId = (int)wmsInput('warehouse_id', 0);
        $code = wmsSanitizeString(wmsInput('code', ''), 100);
        $name = wmsSanitizeString(wmsInput('name', ''), 255);
        if ($warehouseId <= 0 || $code === '' || $name === '') {
            wmsJsonError('Warehouse, code, and name are required.', 422);
        }
        wmsDb()->execute(
            'INSERT INTO wms_locations (warehouse_id, parent_id, code, name, type, capacity, capacity_unit, sort_order, is_active, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
            [
                $warehouseId,
                ($parentId = (int)wmsInput('parent_id', 0)) > 0 ? $parentId : null,
                $code,
                $name,
                wmsSanitizeString(wmsInput('type', 'bin'), 20),
                wmsInput('capacity', '') !== '' ? wmsNormalizeDecimal(wmsInput('capacity')) : null,
                wmsSanitizeString(wmsInput('capacity_unit', ''), 50) ?: null,
                (int)wmsInput('sort_order', 0),
                (int)(bool)wmsInput('is_active', 1),
            ]
        );
        wmsJsonOk(['id' => (int)wmsDb()->lastInsertId()], 201);
    });
}

function wmsApiLocationUpdate(array $params = []): void
{
    wmsResponseGuard(function () use ($params): void {
        wmsRequireAnyRole('admin', 'supervisor');
        $id = (int)($params['id'] ?? 0);
        $existing = wmsFetchOne('SELECT * FROM wms_locations WHERE id = ? AND deleted_at IS NULL LIMIT 1', [$id]);
        if ($existing === null) {
            wmsJsonError('Location not found.', 404);
        }
        wmsDb()->execute(
            'UPDATE wms_locations SET warehouse_id = ?, parent_id = ?, code = ?, name = ?, type = ?, capacity = ?, capacity_unit = ?, sort_order = ?, is_active = ?, updated_at = NOW() WHERE id = ?',
            [
                (int)wmsInput('warehouse_id', $existing['warehouse_id'] ?? 0),
                ($parentId = (int)wmsInput('parent_id', $existing['parent_id'] ?? 0)) > 0 ? $parentId : null,
                wmsSanitizeString(wmsInput('code', $existing['code'] ?? ''), 100),
                wmsSanitizeString(wmsInput('name', $existing['name'] ?? ''), 255),
                wmsSanitizeString(wmsInput('type', $existing['type'] ?? 'bin'), 20),
                wmsInput('capacity', $existing['capacity'] ?? '') !== '' ? wmsNormalizeDecimal(wmsInput('capacity', $existing['capacity'])) : null,
                wmsSanitizeString(wmsInput('capacity_unit', $existing['capacity_unit'] ?? ''), 50) ?: null,
                (int)wmsInput('sort_order', $existing['sort_order'] ?? 0),
                (int)(bool)wmsInput('is_active', $existing['is_active'] ?? 1),
                $id,
            ]
        );
        wmsJsonOk(['id' => $id]);
    });
}

function wmsApiBatchesList(array $params = []): void
{
    wmsResponseGuard(function (): void {
        wmsRequireAnyRole('admin', 'supervisor', 'viewer');
        $where = ['1=1'];
        $params = [];
        $productId = (int)wmsInput('product_id', 0);
        if ($productId > 0) {
            $where[] = 'b.product_id = ?';
            $params[] = $productId;
        }
        $expiresBefore = wmsSanitizeString(wmsInput('expires_before', ''), 20);
        if ($expiresBefore !== '') {
            $where[] = 'b.expires_at <= ?';
            $params[] = $expiresBefore;
        }
        wmsJsonOk(['data' => wmsFetchAll(
            'SELECT b.*, p.sku, p.name AS product_name FROM wms_batches b INNER JOIN wms_products p ON p.id = b.product_id WHERE ' . implode(' AND ', $where) . ' ORDER BY b.expires_at ASC, b.batch_number ASC',
            $params
        )]);
    });
}

function wmsApiBatchGet(array $params = []): void
{
    wmsResponseGuard(function () use ($params): void {
        wmsRequireAnyRole('admin', 'supervisor', 'viewer');
        $id = (int)($params['id'] ?? 0);
        $batch = wmsFetchOne('SELECT b.*, p.sku, p.name AS product_name FROM wms_batches b INNER JOIN wms_products p ON p.id = b.product_id WHERE b.id = ? LIMIT 1', [$id]);
        if ($batch === null) {
            wmsJsonError('Batch not found.', 404);
        }
        $batch['stock'] = wmsFetchAll('SELECT * FROM wms_stocks WHERE batch_id = ? ORDER BY warehouse_id ASC, location_id ASC', [$id]);
        wmsJsonOk(['data' => $batch]);
    });
}

function wmsApiBatchCreate(array $params = []): void
{
    wmsResponseGuard(function (): void {
        wmsRequireAnyRole('admin', 'supervisor');
        $productId = (int)wmsInput('product_id', 0);
        $batchNumber = wmsSanitizeString(wmsInput('batch_number', ''), 100);
        if ($productId <= 0 || $batchNumber === '') {
            wmsJsonError('Product and batch number are required.', 422);
        }
        wmsDb()->execute(
            'INSERT INTO wms_batches (product_id, batch_number, lot_number, manufactured_at, expires_at, meta, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())',
            [
                $productId,
                $batchNumber,
                wmsSanitizeString(wmsInput('lot_number', ''), 100) ?: null,
                wmsSanitizeString(wmsInput('manufactured_at', ''), 20) ?: null,
                wmsSanitizeString(wmsInput('expires_at', ''), 20) ?: null,
                ($meta = wmsJsonDecodeArray(wmsInput('meta', []))) !== [] ? json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            ]
        );
        wmsJsonOk(['id' => (int)wmsDb()->lastInsertId()], 201);
    });
}
