<?php

declare(strict_types=1);

function wmsUsersListData(): array
{
    return wmsFetchAll(
        'SELECT id, username, email, phone, full_name, role, is_active, created_at
         FROM wms_users
         ORDER BY full_name ASC'
    );
}

function wmsUserAccountRecord(int $id): ?array
{
    if ($id <= 0) {
        return null;
    }

    return wmsFetchOne(
        'SELECT id, username, email, phone, full_name, role, is_active, created_at, updated_at
         FROM wms_users
         WHERE id = ?
         LIMIT 1',
        [$id]
    );
}

function wmsUserFindOrFail(int $id): array
{
    $existing = wmsFetchOne('SELECT * FROM wms_users WHERE id = ? LIMIT 1', [$id]);
    if ($existing === null) {
        throw new RuntimeException('User not found.');
    }

    return $existing;
}

function wmsUserValidateRole(string $role): string
{
    if (!in_array($role, ['admin', 'supervisor', 'viewer'], true)) {
        throw new RuntimeException('Invalid role.');
    }

    return $role;
}

function wmsUserSanitizeEmail(mixed $value): string
{
    $email = strtolower(wmsSanitizeString($value, 255));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('A valid email address is required.');
    }

    return $email;
}

function wmsUserSanitizePhone(mixed $value): ?string
{
    $phone = wmsSanitizeString($value, 50);
    return $phone !== '' ? $phone : null;
}

function wmsUserAssertUnique(string $username, string $email, ?int $excludeId = null): void
{
    $params = [$username, $email];
    $sql = 'SELECT id FROM wms_users WHERE (username = ? OR email = ?)';

    if ($excludeId !== null && $excludeId > 0) {
        $sql .= ' AND id <> ?';
        $params[] = $excludeId;
    }

    $sql .= ' LIMIT 1';
    $exists = wmsFetchOne($sql, $params);
    if ($exists !== null) {
        throw new RuntimeException('Username or email already exists.');
    }
}

function wmsUserCreateRecord(array $input, array $actor): int
{
    $username = wmsSanitizeString($input['username'] ?? '', 100);
    $email = wmsUserSanitizeEmail($input['email'] ?? '');
    $password = (string)($input['password'] ?? '');
    $fullName = wmsSanitizeString($input['full_name'] ?? '', 255);
    $phone = wmsUserSanitizePhone($input['phone'] ?? '');
    $role = wmsUserValidateRole(wmsSanitizeString($input['role'] ?? 'viewer', 20));

    if ($username === '' || $password === '' || $fullName === '') {
        throw new RuntimeException('Username, email, password, and full name are required.');
    }
    if (strlen($password) < 8) {
        throw new RuntimeException('Password must be at least 8 characters.');
    }

    wmsUserAssertUnique($username, $email);

    wmsDb()->execute(
        'INSERT INTO wms_users (username, email, phone, password_hash, full_name, role, is_active, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, 1, NOW(), NOW())',
        [$username, $email, $phone, password_hash($password, PASSWORD_BCRYPT), $fullName, $role]
    );

    $id = (int)wmsDb()->lastInsertId();
    wmsAudit('wms.user.created', 'wms_users', (string)$id, null, [
        'username' => $username,
        'role' => $role,
        'actor_id' => (int)($actor['id'] ?? 0),
    ]);

    return $id;
}

function wmsUserUpdateRecord(int $id, array $input, array $actor): void
{
    $existing = wmsUserFindOrFail($id);
    $fullName = wmsSanitizeString($input['full_name'] ?? ($existing['full_name'] ?? ''), 255);
    $email = wmsUserSanitizeEmail($input['email'] ?? ($existing['email'] ?? ''));
    $phone = array_key_exists('phone', $input)
        ? wmsUserSanitizePhone($input['phone'])
        : wmsUserSanitizePhone($existing['phone'] ?? '');
    $role = wmsUserValidateRole(wmsSanitizeString($input['role'] ?? ($existing['role'] ?? 'viewer'), 20));
    $isActive = array_key_exists('is_active', $input)
        ? (int)(bool)$input['is_active']
        : (int)($existing['is_active'] ?? 1);
    $newPassword = (string)($input['password'] ?? '');

    if ($fullName === '') {
        throw new RuntimeException('Full name is required.');
    }
    if ((int)($actor['id'] ?? 0) === $id && $isActive !== 1) {
        throw new RuntimeException('Cannot deactivate your own account.');
    }
    if ($newPassword !== '' && strlen($newPassword) < 8) {
        throw new RuntimeException('Password must be at least 8 characters.');
    }

    wmsUserAssertUnique((string)($existing['username'] ?? ''), $email, $id);

    $passwordHash = (string)($existing['password_hash'] ?? '');
    if ($newPassword !== '') {
        $passwordHash = password_hash($newPassword, PASSWORD_BCRYPT);
    }

    wmsDb()->execute(
        'UPDATE wms_users
         SET full_name = ?, email = ?, phone = ?, role = ?, is_active = ?, password_hash = ?, updated_at = NOW()
         WHERE id = ?',
        [$fullName, $email, $phone, $role, $isActive, $passwordHash, $id]
    );

    wmsAudit('wms.user.updated', 'wms_users', (string)$id, $existing, [
        'actor_id' => (int)($actor['id'] ?? 0),
        'phone' => $phone,
        'role' => $role,
        'is_active' => $isActive,
    ]);
}

function wmsUserDeactivate(int $id, array $actor): void
{
    $existing = wmsFetchOne('SELECT id, username, is_active FROM wms_users WHERE id = ? LIMIT 1', [$id]);
    if ($existing === null) {
        throw new RuntimeException('User not found.');
    }
    if ((int)($actor['id'] ?? 0) === $id) {
        throw new RuntimeException('Cannot delete your own account.');
    }

    wmsDb()->execute('UPDATE wms_users SET is_active = 0, updated_at = NOW() WHERE id = ?', [$id]);
    wmsAudit('wms.user.deactivated', 'wms_users', (string)$id, $existing, ['actor_id' => (int)($actor['id'] ?? 0)]);
}

function wmsChangeOwnPassword(int $userId, string $currentPassword, string $newPassword, string $confirmPassword): void
{
    if ($userId <= 0) {
        throw new RuntimeException('Account not found.');
    }
    if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
        throw new RuntimeException('Current, new, and confirm password are required.');
    }
    if ($newPassword !== $confirmPassword) {
        throw new RuntimeException('Passwords do not match.');
    }
    if (strlen($newPassword) < 8) {
        throw new RuntimeException('Password must be at least 8 characters.');
    }

    $existing = wmsUserFindOrFail($userId);
    if ((int)($existing['is_active'] ?? 0) !== 1) {
        throw new RuntimeException('Inactive accounts cannot change passwords.');
    }
    if (!password_verify($currentPassword, (string)($existing['password_hash'] ?? ''))) {
        throw new RuntimeException('Current password is incorrect.');
    }

    wmsDb()->execute(
        'UPDATE wms_users SET password_hash = ?, updated_at = NOW() WHERE id = ?',
        [password_hash($newPassword, PASSWORD_BCRYPT), $userId]
    );

    wmsAudit('wms.user.password_changed', 'wms_users', (string)$userId, null, ['actor_id' => $userId]);
}

function wmsAccountAuthPayload(array $account): array
{
    $payload = [
        'sub' => 'wms:' . (int)($account['id'] ?? 0),
        'id' => (int)($account['id'] ?? 0),
        'username' => (string)($account['username'] ?? ''),
        'name' => (string)($account['full_name'] ?? ($account['username'] ?? '')),
        'email' => (string)($account['email'] ?? ''),
        'role' => (string)($account['role'] ?? 'viewer'),
        'source' => 'wms',
    ];

    $tenantId = app()->tenant()->current();
    if ($tenantId !== null) {
        $payload['tenant_id'] = $tenantId;
    }

    return $payload;
}

function wmsRefreshOwnAuthSession(array $account): void
{
    $token = app()->jwt()->generate(wmsAccountAuthPayload($account));
    wmsSetAuthCookie($token, (int)config('app.jwt.expiration', 86400));
}

function wmsUpdateOwnAccount(int $userId, array $input): array
{
    $existing = wmsUserFindOrFail($userId);
    if ((int)($existing['is_active'] ?? 0) !== 1) {
        throw new RuntimeException('Inactive accounts cannot be updated.');
    }

    $fullName = wmsSanitizeString($input['full_name'] ?? ($existing['full_name'] ?? ''), 255);
    $email = wmsUserSanitizeEmail($input['email'] ?? ($existing['email'] ?? ''));
    $phone = array_key_exists('phone', $input)
        ? wmsUserSanitizePhone($input['phone'])
        : wmsUserSanitizePhone($existing['phone'] ?? '');

    if ($fullName === '') {
        throw new RuntimeException('Full name is required.');
    }

    wmsUserAssertUnique((string)($existing['username'] ?? ''), $email, $userId);

    wmsDb()->execute(
        'UPDATE wms_users SET full_name = ?, email = ?, phone = ?, updated_at = NOW() WHERE id = ?',
        [$fullName, $email, $phone, $userId]
    );

    $updated = wmsUserAccountRecord($userId);
    if ($updated === null) {
        throw new RuntimeException('Account not found.');
    }

    wmsAudit('wms.account.updated', 'wms_users', (string)$userId, $existing, [
        'actor_id' => $userId,
        'full_name' => $fullName,
        'email' => $email,
        'phone' => $phone,
    ]);

    wmsRefreshOwnAuthSession($updated);

    return $updated;
}

function wmsApiUsersList(array $params = []): void
{
    wmsResponseGuard(function (): void {
        wmsRequireAnyRole('admin');
        wmsJsonOk(['data' => wmsUsersListData()]);
    });
}

function wmsApiUserCreate(array $params = []): void
{
    wmsResponseGuard(function (): void {
        $actor = wmsRequireAnyRole('admin');
        $id = wmsUserCreateRecord((array)wmsInput(), $actor);
        wmsJsonOk(['id' => $id], 201);
    });
}

function wmsApiUserUpdate(array $params = []): void
{
    wmsResponseGuard(function () use ($params): void {
        $actor = wmsRequireAnyRole('admin');
        $id = (int)($params['id'] ?? 0);
        wmsUserUpdateRecord($id, (array)wmsInput(), $actor);
        wmsJsonOk(['id' => $id]);
    });
}

function wmsApiUserDelete(array $params = []): void
{
    wmsResponseGuard(function () use ($params): void {
        $actor = wmsRequireAnyRole('admin');
        $id = (int)($params['id'] ?? 0);
        wmsUserDeactivate($id, $actor);
        wmsJsonOk(['id' => $id]);
    });
}

function wmsApiAccountPasswordUpdate(array $params = []): void
{
    wmsResponseGuard(function (): void {
        $actor = wmsRequireAnyRole('admin', 'supervisor', 'viewer');
        $input = (array)wmsInput();

        wmsChangeOwnPassword(
            (int)($actor['id'] ?? 0),
            (string)($input['current_password'] ?? ''),
            (string)($input['new_password'] ?? ''),
            (string)($input['confirm_password'] ?? '')
        );

        wmsJsonOk(['message' => 'Password updated successfully.']);
    });
}

function wmsApiAccountUpdate(array $params = []): void
{
    wmsResponseGuard(function (): void {
        $actor = wmsRequireAnyRole('admin', 'supervisor', 'viewer');
        $account = wmsUpdateOwnAccount((int)($actor['id'] ?? 0), (array)wmsInput());

        wmsJsonOk([
            'message' => 'Account updated successfully.',
            'account' => $account,
        ]);
    });
}
