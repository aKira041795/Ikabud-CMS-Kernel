<?php

declare(strict_types=1);

function wmsApiUsersList(array $params = []): void
{
    wmsResponseGuard(function (): void {
        wmsRequireStaff(['admin']);
        wmsJsonOk(['data' => wmsFetchAll(
            'SELECT id, username, email, full_name, role, is_active, created_at FROM wms_users ORDER BY full_name ASC'
        )]);
    });
}

function wmsApiUserCreate(array $params = []): void
{
    wmsResponseGuard(function (): void {
        $actor = wmsRequireStaff(['admin']);
        $username = wmsSanitizeString(wmsInput('username', ''), 100);
        $email = wmsSanitizeString(wmsInput('email', ''), 255);
        $password = (string)wmsInput('password', '');
        $fullName = wmsSanitizeString(wmsInput('full_name', ''), 255);
        $role = wmsSanitizeString(wmsInput('role', 'viewer'), 20);

        if ($username === '' || $email === '' || $password === '' || $fullName === '') {
            wmsJsonError('Username, email, password, and full name are required.', 422);
        }
        if (!in_array($role, ['admin', 'supervisor', 'viewer'], true)) {
            wmsJsonError('Invalid role.', 422);
        }
        if (strlen($password) < 8) {
            wmsJsonError('Password must be at least 8 characters.', 422);
        }

        $exists = wmsFetchOne('SELECT id FROM wms_users WHERE username = ? OR email = ? LIMIT 1', [$username, $email]);
        if ($exists !== null) {
            wmsJsonError('Username or email already exists.', 409);
        }

        wmsDb()->execute(
            'INSERT INTO wms_users (username, email, password_hash, full_name, role, is_active, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, 1, NOW(), NOW())',
            [$username, $email, password_hash($password, PASSWORD_BCRYPT), $fullName, $role]
        );
        $id = (int)wmsDb()->lastInsertId();
        wmsAudit('wms.user.created', 'wms_users', (string)$id, null, ['username' => $username, 'role' => $role, 'actor_id' => (int)($actor['id'] ?? 0)]);
        wmsJsonOk(['id' => $id], 201);
    });
}

function wmsApiUserUpdate(array $params = []): void
{
    wmsResponseGuard(function () use ($params): void {
        $actor = wmsRequireStaff(['admin']);
        $id = (int)($params['id'] ?? 0);
        $existing = wmsFetchOne('SELECT * FROM wms_users WHERE id = ? LIMIT 1', [$id]);
        if ($existing === null) {
            wmsJsonError('User not found.', 404);
        }

        $role = wmsSanitizeString(wmsInput('role', $existing['role'] ?? 'viewer'), 20);
        if (!in_array($role, ['admin', 'supervisor', 'viewer'], true)) {
            wmsJsonError('Invalid role.', 422);
        }

        $newPassword = (string)wmsInput('password', '');
        $passwordHash = (string)$existing['password_hash'];
        if ($newPassword !== '') {
            if (strlen($newPassword) < 8) {
                wmsJsonError('Password must be at least 8 characters.', 422);
            }
            $passwordHash = password_hash($newPassword, PASSWORD_BCRYPT);
        }

        wmsDb()->execute(
            'UPDATE wms_users SET full_name = ?, email = ?, role = ?, is_active = ?, password_hash = ?, updated_at = NOW() WHERE id = ?',
            [
                wmsSanitizeString(wmsInput('full_name', $existing['full_name'] ?? ''), 255),
                wmsSanitizeString(wmsInput('email', $existing['email'] ?? ''), 255),
                $role,
                (int)(bool)wmsInput('is_active', $existing['is_active'] ?? 1),
                $passwordHash,
                $id,
            ]
        );
        wmsAudit('wms.user.updated', 'wms_users', (string)$id, $existing, ['actor_id' => (int)($actor['id'] ?? 0)]);
        wmsJsonOk(['id' => $id]);
    });
}

function wmsApiUserDelete(array $params = []): void
{
    wmsResponseGuard(function () use ($params): void {
        $actor = wmsRequireStaff(['admin']);
        $id = (int)($params['id'] ?? 0);
        $existing = wmsFetchOne('SELECT id, username FROM wms_users WHERE id = ? LIMIT 1', [$id]);
        if ($existing === null) {
            wmsJsonError('User not found.', 404);
        }
        if ((int)($actor['id'] ?? 0) === $id) {
            wmsJsonError('Cannot delete your own account.', 409);
        }
        wmsDb()->execute('UPDATE wms_users SET is_active = 0, updated_at = NOW() WHERE id = ?', [$id]);
        wmsAudit('wms.user.deactivated', 'wms_users', (string)$id, $existing, ['actor_id' => (int)($actor['id'] ?? 0)]);
        wmsJsonOk(['id' => $id]);
    });
}
