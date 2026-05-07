<?php

declare(strict_types=1);

function bakeshopUsersListData(): array
{
    return bakeshopDb()->query(
        'SELECT id, username, email, phone, full_name, role, is_active, created_at
         FROM bakeshop_users
         ORDER BY full_name ASC, username ASC'
    )?->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function bakeshopUserAccountRecord(int $id): ?array
{
    if ($id <= 0) {
        return null;
    }

    $stmt = bakeshopDb()->prepare(
        'SELECT id, username, email, phone, full_name, role, is_active, created_at, updated_at
         FROM bakeshop_users
         WHERE id = ?
         LIMIT 1'
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

function bakeshopUserFindOrFail(int $id): array
{
    $stmt = bakeshopDb()->prepare('SELECT * FROM bakeshop_users WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        throw new RuntimeException('User not found.');
    }

    return $row;
}

function bakeshopUserSanitizeString(mixed $value, int $maxLength): string
{
    $value = trim((string)$value);
    if ($maxLength > 0 && strlen($value) > $maxLength) {
        $value = substr($value, 0, $maxLength);
    }

    return $value;
}

function bakeshopUserValidateRole(string $role): string
{
    if (!in_array($role, ['admin', 'supervisor'], true)) {
        throw new RuntimeException('Invalid role.');
    }

    return $role;
}

function bakeshopUserSanitizeEmail(mixed $value): string
{
    $email = strtolower(bakeshopUserSanitizeString($value, 255));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('A valid email address is required.');
    }

    return $email;
}

function bakeshopUserSanitizePhone(mixed $value): ?string
{
    $phone = bakeshopUserSanitizeString($value, 50);
    return $phone !== '' ? $phone : null;
}

function bakeshopUserAssertUnique(string $username, string $email, ?int $excludeId = null): void
{
    $params = [$username, $email];
    $sql = 'SELECT id FROM bakeshop_users WHERE (username = ? OR email = ?)';
    if ($excludeId !== null && $excludeId > 0) {
        $sql .= ' AND id <> ?';
        $params[] = $excludeId;
    }

    $sql .= ' LIMIT 1';
    $stmt = bakeshopDb()->prepare($sql);
    $stmt->execute($params);
    if ($stmt->fetch(PDO::FETCH_ASSOC)) {
        throw new RuntimeException('Username or email already exists.');
    }
}

function bakeshopUserCreateRecord(array $input, array $actor): int
{
    $username = strtolower(bakeshopUserSanitizeString($input['username'] ?? '', 100));
    $email = bakeshopUserSanitizeEmail($input['email'] ?? '');
    $password = (string)($input['password'] ?? '');
    $fullName = bakeshopUserSanitizeString($input['full_name'] ?? '', 255);
    $phone = bakeshopUserSanitizePhone($input['phone'] ?? '');
    $role = bakeshopUserValidateRole(bakeshopUserSanitizeString($input['role'] ?? 'supervisor', 20));

    if ($username === '' || $fullName === '' || $password === '') {
        throw new RuntimeException('Username, email, password, and full name are required.');
    }
    if (strlen($password) < 8) {
        throw new RuntimeException('Password must be at least 8 characters.');
    }

    bakeshopUserAssertUnique($username, $email);

    bakeshopDb()->execute(
        'INSERT INTO bakeshop_users (username, email, phone, password_hash, full_name, role, is_active, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, 1, NOW(), NOW())',
        [$username, $email, $phone, password_hash($password, PASSWORD_BCRYPT), $fullName, $role]
    );

    $id = (int)bakeshopDb()->lastInsertId();
    bakeshopAudit('bakeshop.user.created', null, 'bakeshop_users', (string)$id, null, [
        'username' => $username,
        'role' => $role,
        'actor_id' => (int)($actor['id'] ?? 0),
    ]);

    return $id;
}

function bakeshopConsumePasswordChangeRateLimit(int $userId): array
{
    $scope = 'bakeshop.account_password.user_' . max(0, $userId);
    return kernelRateLimit($scope, 5, 900);
}

function bakeshopUserUpdateRecord(int $id, array $input, array $actor): void
{
    $existing = bakeshopUserFindOrFail($id);
    $fullName = bakeshopUserSanitizeString($input['full_name'] ?? ($existing['full_name'] ?? ''), 255);
    $email = bakeshopUserSanitizeEmail($input['email'] ?? ($existing['email'] ?? ''));
    $phone = array_key_exists('phone', $input)
        ? bakeshopUserSanitizePhone($input['phone'])
        : bakeshopUserSanitizePhone($existing['phone'] ?? '');
    $role = bakeshopUserValidateRole(bakeshopUserSanitizeString($input['role'] ?? ($existing['role'] ?? 'supervisor'), 20));
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

    bakeshopUserAssertUnique((string)($existing['username'] ?? ''), $email, $id);

    $passwordHash = (string)($existing['password_hash'] ?? '');
    if ($newPassword !== '') {
        $passwordHash = password_hash($newPassword, PASSWORD_BCRYPT);
    }

    $bumpTokenVersion = $newPassword !== '' || (int)($existing['is_active'] ?? 1) !== $isActive;
    $sql = 'UPDATE bakeshop_users
         SET full_name = ?, email = ?, phone = ?, role = ?, is_active = ?, password_hash = ?, updated_at = NOW()';
    if ($bumpTokenVersion && bakeshopSupportsTokenVersion()) {
        $sql .= ', token_version = COALESCE(token_version, 0) + 1';
    }
    $sql .= ' WHERE id = ?';

    bakeshopDb()->execute(
        $sql,
        [$fullName, $email, $phone, $role, $isActive, $passwordHash, $id]
    );

    bakeshopAudit('bakeshop.user.updated', null, 'bakeshop_users', (string)$id, $existing, [
        'actor_id' => (int)($actor['id'] ?? 0),
        'email' => $email,
        'phone' => $phone,
        'role' => $role,
        'is_active' => $isActive,
    ]);
}

function bakeshopUserDeactivate(int $id, array $actor): void
{
    $existing = bakeshopUserFindOrFail($id);
    if ((int)($actor['id'] ?? 0) === $id) {
        throw new RuntimeException('Cannot deactivate your own account.');
    }

    $sql = 'UPDATE bakeshop_users SET is_active = 0, updated_at = NOW()';
    if (bakeshopSupportsTokenVersion()) {
        $sql .= ', token_version = COALESCE(token_version, 0) + 1';
    }
    $sql .= ' WHERE id = ?';

    bakeshopDb()->execute($sql, [$id]);
    bakeshopAudit('bakeshop.user.deactivated', null, 'bakeshop_users', (string)$id, $existing, [
        'actor_id' => (int)($actor['id'] ?? 0),
    ]);
}

function bakeshopChangeOwnPassword(int $userId, string $currentPassword, string $newPassword, string $confirmPassword): void
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

    $existing = bakeshopUserFindOrFail($userId);
    if ((int)($existing['is_active'] ?? 0) !== 1) {
        throw new RuntimeException('Inactive accounts cannot change passwords.');
    }
    if (!password_verify($currentPassword, (string)($existing['password_hash'] ?? ''))) {
        throw new RuntimeException('Current password is incorrect.');
    }

    $sql = 'UPDATE bakeshop_users SET password_hash = ?, updated_at = NOW()';
    if (bakeshopSupportsTokenVersion()) {
        $sql .= ', token_version = COALESCE(token_version, 0) + 1';
    }
    $sql .= ' WHERE id = ?';

    bakeshopDb()->execute($sql, [password_hash($newPassword, PASSWORD_BCRYPT), $userId]);

    bakeshopAudit('bakeshop.user.password_changed', null, 'bakeshop_users', (string)$userId, null, [
        'actor_id' => $userId,
    ]);
}

function bakeshopPageUsers(array $params = []): void
{
    bakeshopResponseGuard(static function (): void {
        $user = bakeshopCurrentUser(null, ['admin']);
        $bootstrapOnboarding = bakeshopBootstrapOnboardingState();
        $users = bakeshopUsersListData();
        $activeUsers = array_values(array_filter($users, static fn (array $staff): bool => (int)($staff['is_active'] ?? 0) === 1));
        $inactiveUsers = array_values(array_filter($users, static fn (array $staff): bool => (int)($staff['is_active'] ?? 0) !== 1));
        echo bakeshopRender('pages/users.disyl', bakeshopPageContext($user, 'users', [
            'page_title' => 'Bakeshop Staff',
            'page_intro' => 'Create admins and supervisors, manage account status, and keep staff access inside the Bakeshop module.',
            'current_user_id' => (int)($user['id'] ?? 0),
            'bootstrap_onboarding' => $bootstrapOnboarding,
            'is_bootstrap_user' => bakeshopIsBootstrapUser($user),
            'users' => $users,
            'active_users' => $activeUsers,
            'inactive_users' => $inactiveUsers,
        ]));
    });
}

function bakeshopPageAccount(array $params = []): void
{
    bakeshopResponseGuard(static function (): void {
        $user = bakeshopCurrentUser(null, ['admin', 'supervisor']);
        if (!bakeshopIsModuleUser($user)) {
            throw new DomainException('Forbidden');
        }

        $account = bakeshopUserAccountRecord((int)($user['id'] ?? 0));
        if ($account === null) {
            throw new RuntimeException('Account not found.');
        }

        $bootstrapOnboarding = bakeshopBootstrapOnboardingState();

        echo bakeshopRender('pages/account.disyl', bakeshopPageContext($user, 'account', [
            'page_title' => 'My Bakeshop Account',
            'page_intro' => 'Review your Bakeshop profile, update your password, and complete bootstrap handoff tasks when needed.',
            'account' => $account,
            'bootstrap_onboarding' => $bootstrapOnboarding,
            'is_bootstrap_user' => bakeshopIsBootstrapUser($user),
        ]));
    });
}

function bakeshopApiUsersList(array $params = []): void
{
    bakeshopResponseGuard(static function (): void {
        bakeshopCurrentUser(null, ['admin']);
        bakeshopJsonOk(['users' => bakeshopUsersListData()]);
    });
}

function bakeshopApiUserCreate(array $params = []): void
{
    bakeshopResponseGuard(static function (): void {
        bakeshopEnforceCsrf();
        $actor = bakeshopCurrentUser(null, ['admin']);
        $id = bakeshopUserCreateRecord((array)bakeshopInput(), $actor);
        bakeshopJsonOk(['id' => $id], 201);
    });
}

function bakeshopApiUserUpdate(array $params = []): void
{
    bakeshopResponseGuard(static function () use ($params): void {
        bakeshopEnforceCsrf();
        $actor = bakeshopCurrentUser(null, ['admin']);
        $id = (int)($params['id'] ?? 0);
        bakeshopUserUpdateRecord($id, (array)bakeshopInput(), $actor);
        bakeshopJsonOk(['id' => $id]);
    });
}

function bakeshopApiUserDelete(array $params = []): void
{
    bakeshopResponseGuard(static function () use ($params): void {
        bakeshopEnforceCsrf();
        $actor = bakeshopCurrentUser(null, ['admin']);
        $id = (int)($params['id'] ?? 0);
        bakeshopUserDeactivate($id, $actor);
        bakeshopJsonOk(['id' => $id]);
    });
}

function bakeshopApiAccountPasswordUpdate(array $params = []): void
{
    bakeshopResponseGuard(static function (): void {
        bakeshopEnforceCsrf();
        $actor = bakeshopCurrentUser(null, ['admin', 'supervisor']);
        if (!bakeshopIsModuleUser($actor)) {
            throw new DomainException('Forbidden');
        }

        $rateLimit = bakeshopConsumePasswordChangeRateLimit((int)($actor['id'] ?? 0));
        if (!empty($rateLimit['limited'])) {
            $retryAfter = max(1, (int)($rateLimit['retry_after'] ?? 1));
            header('Retry-After: ' . $retryAfter);
            bakeshopJsonError('Too many password change attempts. Try again later.', 429, [
                'retry_after' => $retryAfter,
            ]);
            return;
        }

        $input = (array)bakeshopInput();
        bakeshopChangeOwnPassword(
            (int)($actor['id'] ?? 0),
            (string)($input['current_password'] ?? ''),
            (string)($input['new_password'] ?? ''),
            (string)($input['confirm_password'] ?? '')
        );

        bakeshopJsonOk(['message' => 'Password updated successfully.']);
    });
}