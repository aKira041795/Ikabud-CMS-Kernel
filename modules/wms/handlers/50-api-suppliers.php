<?php

declare(strict_types=1);

// ── Suppliers ──

function wmsApiSuppliersList(): void
{
    $user = wmsCurrentUser();
    $q = trim((string)($_GET['q'] ?? ''));
    $activeOnly = ($_GET['active'] ?? '1') !== '0';

    $sql = 'SELECT * FROM wms_suppliers WHERE 1=1';
    $params = [];
    if ($q !== '') {
        $sql .= ' AND (name LIKE :q OR code LIKE :q2 OR contact_person LIKE :q3)';
        $params[':q'] = '%' . $q . '%';
        $params[':q2'] = '%' . $q . '%';
        $params[':q3'] = '%' . $q . '%';
    }
    if ($activeOnly) $sql .= ' AND is_active = 1';
    $sql .= ' ORDER BY name ASC LIMIT 100';

    $rows = wmsDb()->query($sql, $params)->fetchAll(\PDO::FETCH_ASSOC);
    wmsJsonOk(['suppliers' => $rows]);
}

function wmsApiSupplierCreate(): void
{
    $user = wmsCurrentUser(['admin', 'supervisor']);
    $input = wmsInput();
    $code = trim((string)($input['code'] ?? ''));
    $name = trim((string)($input['name'] ?? ''));
    if ($code === '' || $name === '') wmsJsonError('Code and name are required.');

    $stmt = wmsDb()->query('SELECT id FROM wms_suppliers WHERE code = :code', [':code' => $code]);
    if ($stmt->fetch(\PDO::FETCH_ASSOC)) wmsJsonError('Supplier code already exists.');

    wmsDb()->execute(
        'INSERT INTO wms_suppliers (code, name, contact_person, email, phone, address, lead_time_days)
         VALUES (:code, :name, :cp, :email, :phone, :addr, :ltd)',
        [
            ':code' => $code,
            ':name' => $name,
            ':cp' => $input['contact_person'] ?? null,
            ':email' => $input['email'] ?? null,
            ':phone' => $input['phone'] ?? null,
            ':addr' => $input['address'] ?? null,
            ':ltd' => isset($input['lead_time_days']) ? (int)$input['lead_time_days'] : null,
        ]
    );
    wmsJsonOk(['supplier_id' => (int)wmsDb()->lastInsertId()], 201);
}

function wmsApiSupplierGet(array $params): void
{
    $user = wmsCurrentUser();
    $id = (int)($params['id'] ?? 0);
    $stmt = wmsDb()->query('SELECT * FROM wms_suppliers WHERE id = :id', [':id' => $id]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    if (!$row) wmsJsonError('Supplier not found.', 404);
    wmsJsonOk(['supplier' => $row]);
}

function wmsApiSupplierUpdate(array $params): void
{
    $user = wmsCurrentUser(['admin', 'supervisor']);
    $id = (int)($params['id'] ?? 0);

    $input = wmsInput();
    $fields = []; $vals = [':id' => $id];
    foreach (['code','name','contact_person','email','phone','address','lead_time_days','is_active'] as $f) {
        if (isset($input[$f])) { $fields[] = "$f = :$f"; $vals[":$f"] = $input[$f]; }
    }
    if (!empty($fields)) wmsDb()->execute('UPDATE wms_suppliers SET ' . implode(', ', $fields) . ' WHERE id = :id', $vals);
    wmsJsonOk(['supplier_id' => $id]);
}

function wmsApiSupplierDelete(array $params): void
{
    $user = wmsCurrentUser(['admin']);
    $id = (int)($params['id'] ?? 0);
    wmsDb()->execute('UPDATE wms_suppliers SET is_active = 0 WHERE id = :id', [':id' => $id]);
    wmsJsonOk(['supplier_id' => $id]);
}
