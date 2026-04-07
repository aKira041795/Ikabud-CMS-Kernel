<?php

declare(strict_types=1);

function wmsApiSuppliersList(array $params = []): void
{
    wmsResponseGuard(function (): void {
        wmsRequireStaff(['admin', 'supervisor', 'viewer']);
        $where = ['deleted_at IS NULL'];
        $bind = [];
        $q = wmsSanitizeString(wmsInput('q', ''), 120);
        if ($q !== '') {
            $where[] = '(name LIKE ? OR code LIKE ? OR contact_person LIKE ?)';
            $bind[] = wmsSqlLike($q);
            $bind[] = wmsSqlLike($q);
            $bind[] = wmsSqlLike($q);
        }
        if (wmsInput('active', '') !== '') {
            $where[] = 'is_active = ?';
            $bind[] = (int)(bool)wmsInput('active');
        }
        wmsJsonOk(['data' => wmsFetchAll(
            'SELECT * FROM wms_suppliers WHERE ' . implode(' AND ', $where) . ' ORDER BY name ASC',
            $bind
        )]);
    });
}

function wmsApiSupplierGet(array $params = []): void
{
    wmsResponseGuard(function () use ($params): void {
        wmsRequireStaff(['admin', 'supervisor', 'viewer']);
        $supplier = wmsFetchOne('SELECT * FROM wms_suppliers WHERE id = ? AND deleted_at IS NULL LIMIT 1', [(int)($params['id'] ?? 0)]);
        if ($supplier === null) {
            wmsJsonError('Supplier not found.', 404);
        }
        $supplier['recent_deliveries'] = wmsFetchAll(
            'SELECT id, reference_number, status, created_at FROM wms_deliveries WHERE supplier_id = ? AND deleted_at IS NULL ORDER BY created_at DESC LIMIT 10',
            [(int)$supplier['id']]
        );
        wmsJsonOk(['data' => $supplier]);
    });
}

function wmsApiSupplierCreate(array $params = []): void
{
    wmsResponseGuard(function (): void {
        $user = wmsRequireStaff(['admin', 'supervisor']);
        $code = wmsSanitizeString(wmsInput('code', ''), 50);
        $name = wmsSanitizeString(wmsInput('name', ''), 255);
        if ($code === '' || $name === '') {
            wmsJsonError('Code and name are required.', 422);
        }
        wmsDb()->execute(
            'INSERT INTO wms_suppliers (code, name, contact_person, email, phone, address, lead_time_days, is_active, meta, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
            [
                $code,
                $name,
                wmsSanitizeString(wmsInput('contact_person', ''), 255) ?: null,
                wmsSanitizeString(wmsInput('email', ''), 255) ?: null,
                wmsSanitizeString(wmsInput('phone', ''), 50) ?: null,
                wmsSanitizeString(wmsInput('address', ''), 5000) ?: null,
                wmsInput('lead_time_days', '') !== '' ? (int)wmsInput('lead_time_days') : null,
                (int)(bool)wmsInput('is_active', 1),
                ($meta = wmsJsonDecodeArray(wmsInput('meta', []))) !== [] ? json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            ]
        );
        $id = (int)wmsDb()->lastInsertId();
        wmsAudit('wms.supplier.created', 'wms_suppliers', (string)$id, null, ['code' => $code, 'name' => $name, 'actor_id' => (int)($user['id'] ?? 0)]);
        wmsJsonOk(['id' => $id], 201);
    });
}

function wmsApiSupplierUpdate(array $params = []): void
{
    wmsResponseGuard(function () use ($params): void {
        $user = wmsRequireStaff(['admin', 'supervisor']);
        $id = (int)($params['id'] ?? 0);
        $existing = wmsFetchOne('SELECT * FROM wms_suppliers WHERE id = ? AND deleted_at IS NULL LIMIT 1', [$id]);
        if ($existing === null) {
            wmsJsonError('Supplier not found.', 404);
        }
        wmsDb()->execute(
            'UPDATE wms_suppliers SET code = ?, name = ?, contact_person = ?, email = ?, phone = ?, address = ?, lead_time_days = ?, is_active = ?, updated_at = NOW() WHERE id = ?',
            [
                wmsSanitizeString(wmsInput('code', $existing['code'] ?? ''), 50),
                wmsSanitizeString(wmsInput('name', $existing['name'] ?? ''), 255),
                wmsSanitizeString(wmsInput('contact_person', $existing['contact_person'] ?? ''), 255) ?: null,
                wmsSanitizeString(wmsInput('email', $existing['email'] ?? ''), 255) ?: null,
                wmsSanitizeString(wmsInput('phone', $existing['phone'] ?? ''), 50) ?: null,
                wmsSanitizeString(wmsInput('address', $existing['address'] ?? ''), 5000) ?: null,
                wmsInput('lead_time_days', $existing['lead_time_days'] ?? '') !== '' ? (int)wmsInput('lead_time_days', $existing['lead_time_days']) : null,
                (int)(bool)wmsInput('is_active', $existing['is_active'] ?? 1),
                $id,
            ]
        );
        wmsAudit('wms.supplier.updated', 'wms_suppliers', (string)$id, $existing, ['actor_id' => (int)($user['id'] ?? 0)]);
        wmsJsonOk(['id' => $id]);
    });
}

function wmsApiSupplierDelete(array $params = []): void
{
    wmsResponseGuard(function () use ($params): void {
        $user = wmsRequireStaff(['admin']);
        $id = (int)($params['id'] ?? 0);
        $existing = wmsFetchOne('SELECT * FROM wms_suppliers WHERE id = ? AND deleted_at IS NULL LIMIT 1', [$id]);
        if ($existing === null) {
            wmsJsonError('Supplier not found.', 404);
        }
        wmsDb()->execute('UPDATE wms_suppliers SET deleted_at = NOW(), is_active = 0, updated_at = NOW() WHERE id = ?', [$id]);
        wmsAudit('wms.supplier.deleted', 'wms_suppliers', (string)$id, $existing, ['actor_id' => (int)($user['id'] ?? 0)]);
        wmsJsonOk(['id' => $id]);
    });
}
