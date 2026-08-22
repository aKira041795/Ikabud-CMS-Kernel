<?php

declare(strict_types=1);

// ── Products ──

function wmsApiProductsList(): void
{
    $user = wmsCurrentUser();
    $search = trim((string)($_GET['q'] ?? ''));
    $type = trim((string)($_GET['type'] ?? ''));
    $activeOnly = ($_GET['active'] ?? '1') !== '0';

    $sql = 'SELECT * FROM wms_products WHERE 1=1';
    $params = [];

    if ($search !== '') {
        $sql .= ' AND (name LIKE :q OR sku LIKE :q2 OR barcode LIKE :q3)';
        $params[':q'] = '%' . $search . '%';
        $params[':q2'] = '%' . $search . '%';
        $params[':q3'] = '%' . $search . '%';
    }
    if ($type !== '') {
        $sql .= ' AND product_type = :type';
        $params[':type'] = $type;
    }
    if ($activeOnly) {
        $sql .= ' AND is_active = 1';
    }
    $sql .= ' ORDER BY name ASC LIMIT 100';

    $rows = wmsDb()->query($sql, $params)->fetchAll(\PDO::FETCH_ASSOC);
    wmsJsonOk(['products' => $rows]);
}

function wmsApiProductGet(array $params): void
{
    $user = wmsCurrentUser();
    $id = (int)($params['id'] ?? 0);
    $stmt = wmsDb()->query('SELECT * FROM wms_products WHERE id = :id', [':id' => $id]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    if (!$row) wmsJsonError('Product not found.', 404);
    wmsJsonOk(['product' => $row]);
}

function wmsApiProductCreate(): void
{
    $user = wmsCurrentUser(['admin', 'supervisor']);
    $input = wmsInput();

    $sku = trim((string)($input['sku'] ?? ''));
    $name = trim((string)($input['name'] ?? ''));
    if ($sku === '' || $name === '') wmsJsonError('SKU and name are required.');

    $stmt = wmsDb()->query('SELECT id FROM wms_products WHERE sku = :sku', [':sku' => $sku]);
    if ($stmt->fetch(\PDO::FETCH_ASSOC)) wmsJsonError('SKU already exists.');

    $stmt = wmsDb()->prepare(
        'INSERT INTO wms_products (sku, barcode, name, description, unit, product_type, weight, dimensions, is_batch_tracked, reorder_point, safety_stock, meta)
         VALUES (:sku, :barcode, :name, :desc, :unit, :type, :weight, :dims, :batch, :reorder, :safety, :meta)'
    );
    $stmt->execute([
        ':sku' => $sku,
        ':barcode' => $input['barcode'] ?? null,
        ':name' => $name,
        ':desc' => $input['description'] ?? null,
        ':unit' => $input['unit'] ?? 'pcs',
        ':type' => $input['product_type'] ?? 'physical',
        ':weight' => $input['weight'] ?? null,
        ':dims' => !empty($input['dimensions']) ? json_encode($input['dimensions']) : null,
        ':batch' => (int)($input['is_batch_tracked'] ?? 0),
        ':reorder' => $input['reorder_point'] ?? null,
        ':safety' => $input['safety_stock'] ?? null,
        ':meta' => !empty($input['meta']) ? json_encode($input['meta']) : null,
    ]);
    $id = (int)wmsDb()->lastInsertId();

    wmsJsonOk(['product_id' => $id], 201);
}

function wmsApiProductUpdate(array $params): void
{
    $user = wmsCurrentUser(['admin', 'supervisor']);
    $id = (int)($params['id'] ?? 0);

    $input = wmsInput();
    $fields = []; $vals = [':id' => $id];
    foreach (['sku','barcode','name','description','unit','product_type','weight','is_batch_tracked','reorder_point','safety_stock'] as $f) {
        if (isset($input[$f])) { $fields[] = "$f = :$f"; $vals[":$f"] = $input[$f]; }
    }
    if (isset($input['dimensions'])) { $fields[] = 'dimensions = :dims'; $vals[':dims'] = json_encode($input['dimensions']); }
    if (isset($input['meta'])) { $fields[] = 'meta = :meta'; $vals[':meta'] = json_encode($input['meta']); }

    if (!empty($fields)) {
        wmsDb()->execute('UPDATE wms_products SET ' . implode(', ', $fields) . ' WHERE id = :id', $vals);
    }
    wmsJsonOk(['product_id' => $id]);
}

function wmsApiProductDelete(array $params): void
{
    $user = wmsCurrentUser(['admin']);
    $id = (int)($params['id'] ?? 0);
    wmsDb()->execute('UPDATE wms_products SET is_active = 0 WHERE id = :id', [':id' => $id]);
    wmsJsonOk(['product_id' => $id]);
}

// ── Warehouses ──

function wmsApiWarehousesList(): void
{
    $user = wmsCurrentUser();
    $activeOnly = ($_GET['active'] ?? '1') !== '0';
    $sql = 'SELECT * FROM wms_warehouses' . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY name ASC';
    $rows = wmsDb()->query($sql)->fetchAll(\PDO::FETCH_ASSOC);
    wmsJsonOk(['warehouses' => $rows]);
}

function wmsApiWarehouseCreate(): void
{
    $user = wmsCurrentUser(['admin']);
    $input = wmsInput();
    $code = trim((string)($input['code'] ?? ''));
    $name = trim((string)($input['name'] ?? ''));
    if ($code === '' || $name === '') wmsJsonError('Code and name are required.');

    $stmt = wmsDb()->query('SELECT id FROM wms_warehouses WHERE code = :code', [':code' => $code]);
    if ($stmt->fetch(\PDO::FETCH_ASSOC)) wmsJsonError('Warehouse code already exists.');

    wmsDb()->execute(
        'INSERT INTO wms_warehouses (code, name, address, contact_info) VALUES (:code, :name, :addr, :ci)',
        [':code' => $code, ':name' => $name, ':addr' => $input['address'] ?? null, ':ci' => !empty($input['contact_info']) ? json_encode($input['contact_info']) : null]
    );
    wmsJsonOk(['warehouse_id' => (int)wmsDb()->lastInsertId()], 201);
}

function wmsApiWarehouseGet(array $params): void
{
    $user = wmsCurrentUser();
    $id = (int)($params['id'] ?? 0);
    $stmt = wmsDb()->query('SELECT * FROM wms_warehouses WHERE id = :id', [':id' => $id]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    if (!$row) wmsJsonError('Warehouse not found.', 404);
    wmsJsonOk(['warehouse' => $row]);
}

function wmsApiWarehouseUpdate(array $params): void
{
    $user = wmsCurrentUser(['admin']);
    $id = (int)($params['id'] ?? 0);

    $input = wmsInput();
    $fields = []; $vals = [':id' => $id];
    foreach (['code','name','address','is_active'] as $f) {
        if (isset($input[$f])) { $fields[] = "$f = :$f"; $vals[":$f"] = $input[$f]; }
    }
    if (isset($input['contact_info'])) { $fields[] = 'contact_info = :ci'; $vals[':ci'] = json_encode($input['contact_info']); }
    if (!empty($fields)) wmsDb()->execute('UPDATE wms_warehouses SET ' . implode(', ', $fields) . ' WHERE id = :id', $vals);
    wmsJsonOk(['warehouse_id' => $id]);
}

// ── Locations ──

function wmsApiLocationsList(): void
{
    $user = wmsCurrentUser();
    $warehouseId = isset($_GET['warehouse_id']) ? (int)$_GET['warehouse_id'] : null;
    $type = trim((string)($_GET['type'] ?? ''));
    $parentId = isset($_GET['parent_id']) ? (int)$_GET['parent_id'] : null;

    $sql = 'SELECT l.*, p.code AS parent_code FROM wms_locations l LEFT JOIN wms_locations p ON l.parent_id = p.id WHERE 1=1';
    $params = [];
    if ($warehouseId) { $sql .= ' AND l.warehouse_id = :wid'; $params[':wid'] = $warehouseId; }
    if ($type !== '') { $sql .= ' AND l.type = :type'; $params[':type'] = $type; }
    if ($parentId !== null) { $sql .= ' AND l.parent_id = :pid'; $params[':pid'] = $parentId; }
    $sql .= ' ORDER BY l.sort_order ASC, l.code ASC LIMIT 500';

    $rows = wmsDb()->query($sql, $params)->fetchAll(\PDO::FETCH_ASSOC);
    wmsJsonOk(['locations' => $rows]);
}

function wmsApiLocationCreate(): void
{
    $user = wmsCurrentUser(['admin', 'supervisor']);
    $input = wmsInput();
    $code = trim((string)($input['code'] ?? ''));
    $name = trim((string)($input['name'] ?? ''));
    $warehouseId = (int)($input['warehouse_id'] ?? 0);
    if ($code === '' || $name === '' || !$warehouseId) wmsJsonError('Code, name, and warehouse_id are required.');

    wmsDb()->execute(
        'INSERT INTO wms_locations (warehouse_id, parent_id, code, name, type, capacity, capacity_unit, sort_order, is_staging)
         VALUES (:wid, :pid, :code, :name, :type, :cap, :capu, :so, :stag)',
        [
            ':wid' => $warehouseId,
            ':pid' => !empty($input['parent_id']) ? (int)$input['parent_id'] : null,
            ':code' => $code,
            ':name' => $name,
            ':type' => $input['type'] ?? 'bin',
            ':cap' => $input['capacity'] ?? null,
            ':capu' => $input['capacity_unit'] ?? null,
            ':so' => (int)($input['sort_order'] ?? 0),
            ':stag' => (int)($input['is_staging'] ?? 0),
        ]
    );
    wmsJsonOk(['location_id' => (int)wmsDb()->lastInsertId()], 201);
}

function wmsApiLocationGet(array $params): void
{
    $user = wmsCurrentUser();
    $id = (int)($params['id'] ?? 0);
    $stmt = wmsDb()->query(
        'SELECT l.*, p.code AS parent_code, w.name AS warehouse_name
         FROM wms_locations l
         LEFT JOIN wms_locations p ON l.parent_id = p.id
         LEFT JOIN wms_warehouses w ON l.warehouse_id = w.id
         WHERE l.id = :id',
        [':id' => $id]
    );
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    if (!$row) wmsJsonError('Location not found.', 404);
    wmsJsonOk(['location' => $row]);
}

function wmsApiLocationUpdate(array $params): void
{
    $user = wmsCurrentUser(['admin', 'supervisor']);
    $id = (int)($params['id'] ?? 0);

    $input = wmsInput();
    $fields = []; $vals = [':id' => $id];
    foreach (['code','name','type','capacity','capacity_unit','sort_order','is_active','is_staging','parent_id'] as $f) {
        if (isset($input[$f])) { $fields[] = "$f = :$f"; $vals[":$f"] = $input[$f]; }
    }
    if (!empty($fields)) wmsDb()->execute('UPDATE wms_locations SET ' . implode(', ', $fields) . ' WHERE id = :id', $vals);
    wmsJsonOk(['location_id' => $id]);
}

function wmsApiLocationChildren(array $params): void
{
    $user = wmsCurrentUser();
    $id = (int)($params['id'] ?? 0);
    $rows = wmsDb()->query(
        'SELECT * FROM wms_locations WHERE parent_id = :pid ORDER BY sort_order ASC, code ASC',
        [':pid' => $id]
    )->fetchAll(\PDO::FETCH_ASSOC);
    wmsJsonOk(['children' => $rows]);
}

// ── Batches ──

function wmsApiBatchesList(): void
{
    $user = wmsCurrentUser();
    $productId = isset($_GET['product_id']) ? (int)$_GET['product_id'] : null;
    $expiringWithin = isset($_GET['expiring_within_days']) ? (int)$_GET['expiring_within_days'] : null;

    $sql = 'SELECT b.*, p.name AS product_name, p.sku FROM wms_batches b LEFT JOIN wms_products p ON b.product_id = p.id WHERE 1=1';
    $params = [];
    if ($productId) { $sql .= ' AND b.product_id = :pid'; $params[':pid'] = $productId; }
    if ($expiringWithin) { $sql .= ' AND b.expires_at IS NOT NULL AND b.expires_at <= DATE_ADD(CURDATE(), INTERVAL :days DAY)'; $params[':days'] = $expiringWithin; }
    $sql .= ' ORDER BY b.created_at DESC LIMIT 200';

    $rows = wmsDb()->query($sql, $params)->fetchAll(\PDO::FETCH_ASSOC);
    wmsJsonOk(['batches' => $rows]);
}

function wmsApiBatchCreate(): void
{
    $user = wmsCurrentUser(['admin', 'supervisor']);
    $input = wmsInput();
    $productId = (int)($input['product_id'] ?? 0);
    $batchNumber = trim((string)($input['batch_number'] ?? ''));
    if (!$productId || $batchNumber === '') wmsJsonError('product_id and batch_number are required.');

    wmsDb()->execute(
        'INSERT INTO wms_batches (product_id, batch_number, lot_number, manufactured_at, expires_at)
         VALUES (:pid, :bn, :lot, :mfg, :exp)',
        [
            ':pid' => $productId,
            ':bn' => $batchNumber,
            ':lot' => $input['lot_number'] ?? null,
            ':mfg' => $input['manufactured_at'] ?? null,
            ':exp' => $input['expires_at'] ?? null,
        ]
    );
    wmsJsonOk(['batch_id' => (int)wmsDb()->lastInsertId()], 201);
}

function wmsApiBatchGet(array $params): void
{
    $user = wmsCurrentUser();
    $id = (int)($params['id'] ?? 0);
    $stmt = wmsDb()->query(
        'SELECT b.*, p.name AS product_name, p.sku FROM wms_batches b LEFT JOIN wms_products p ON b.product_id = p.id WHERE b.id = :id',
        [':id' => $id]
    );
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    if (!$row) wmsJsonError('Batch not found.', 404);
    wmsJsonOk(['batch' => $row]);
}

function wmsApiBatchUpdate(array $params): void
{
    $user = wmsCurrentUser(['admin', 'supervisor']);
    $id = (int)($params['id'] ?? 0);
    $input = wmsInput();

    $existing = wmsDb()->query('SELECT id FROM wms_batches WHERE id = :id LIMIT 1', [':id' => $id])->fetch(\PDO::FETCH_ASSOC);
    if (!$existing) wmsJsonError('Batch not found.', 404);

    $productId = (int)($input['product_id'] ?? 0);
    $batchNumber = trim((string)($input['batch_number'] ?? ''));
    if ($productId <= 0 || $batchNumber === '') wmsJsonError('product_id and batch_number are required.');

    wmsDb()->execute(
        'UPDATE wms_batches
         SET product_id = :pid, batch_number = :bn, lot_number = :lot,
             manufactured_at = :mfg, expires_at = :exp, updated_at = NOW()
         WHERE id = :id',
        [
            ':pid' => $productId,
            ':bn' => $batchNumber,
            ':lot' => isset($input['lot_number']) ? $input['lot_number'] : null,
            ':mfg' => isset($input['manufactured_at']) ? $input['manufactured_at'] : null,
            ':exp' => isset($input['expires_at']) ? $input['expires_at'] : null,
            ':id' => $id,
        ]
    );
    wmsJsonOk(['batch_id' => $id]);
}
