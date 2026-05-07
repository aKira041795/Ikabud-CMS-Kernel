<?php

declare(strict_types=1);

function bakeshopResponseGuard(callable $callback): void
{
    try {
        $callback();
    } catch (DomainException $e) {
        bakeshopJsonError($e->getMessage() !== '' ? $e->getMessage() : 'Forbidden', 403);
    } catch (InvalidArgumentException $e) {
        bakeshopJsonError($e->getMessage(), 422);
    } catch (Throwable $e) {
        if (function_exists('write_log')) {
            write_log('bakeshop handler error: ' . $e->getMessage(), 'error');
        }

        try {
            bakeshopCtx()->log('bakeshop handler error: ' . $e->getMessage(), 'error');
        } catch (Throwable $ignored) {
        }

        bakeshopJsonError('Unexpected server error.', 500);
    }
}

function bakeshopEnforceCsrf(): void
{
    app()->csrfEnforce();
}

function bakeshopPermissionActions(): array
{
    return [
        'bakeshop.read',
        'bakeshop.manage',
    ];
}

function bakeshopDefaultRolePermissions(): array
{
    return [
        'admin' => [
            'bakeshop.read',
            'bakeshop.manage',
        ],
        'supervisor' => [
            'bakeshop.read',
            'bakeshop.manage',
        ],
    ];
}

function bakeshopNormalizeRolePermissionsInput(mixed $raw): array
{
    $defaults = bakeshopDefaultRolePermissions();

    if (is_string($raw) && trim($raw) !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $raw = $decoded;
        }
    }

    if (!is_array($raw)) {
        return $defaults;
    }

    $allowedActions = array_flip(bakeshopPermissionActions());
    $result = [
        'admin' => $defaults['admin'],
        'supervisor' => $defaults['supervisor'],
    ];
    foreach ($defaults as $role => $defaultPermissions) {
        if ($role === 'admin') {
            continue;
        }

        $values = $raw[$role] ?? $defaultPermissions;
        if (!is_array($values)) {
            $values = $defaultPermissions;
        }

        $clean = [];
        foreach ($values as $permission) {
            $permission = trim((string)$permission);
            if ($permission !== '' && isset($allowedActions[$permission])) {
                $clean[$permission] = true;
            }
        }

        $result[$role] = array_keys($clean);
    }

    return $result;
}

function bakeshopRolePermissions(): array
{
    $settings = bakeshopSettings();
    $raw = $settings['role_permissions'] ?? null;
    $cacheInstance = 'bakeshop_role_permissions_' . preg_replace('/[^A-Za-z0-9_-]/', '_', (string)(app()->tenant()->current() ?? 'global'));
    $rawKey = is_scalar($raw) || $raw === null
        ? (string)$raw
        : json_encode($raw, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $cacheKey = 'role_permissions_' . md5($rawKey);

    try {
        $cached = app()->cache()->get($cacheInstance, $cacheKey);
        if (is_array($cached)
            && array_key_exists('admin', $cached)
            && array_key_exists('supervisor', $cached)) {
            unset($cached['_cache_expires_at']);
            return $cached;
        }
    } catch (Throwable $ignored) {
    }

    $normalized = bakeshopNormalizeRolePermissionsInput($raw);

    try {
        app()->cache()->set($cacheInstance, $cacheKey, $normalized, 300);
    } catch (Throwable $ignored) {
    }

    return $normalized;
}

function bakeshopSaveRolePermissions(mixed $raw): array
{
    $oldPermissions = bakeshopRolePermissions();
    $normalized = bakeshopNormalizeRolePermissionsInput($raw);

    saveModuleSettings('bakeshop', [
        'role_permissions' => json_encode($normalized, JSON_UNESCAPED_SLASHES),
    ]);

    try {
        $cacheInstance = 'bakeshop_role_permissions_' . preg_replace('/[^A-Za-z0-9_-]/', '_', (string)(app()->tenant()->current() ?? 'global'));
        app()->cache()->clear($cacheInstance);
    } catch (Throwable $ignored) {
    }

    bakeshopAudit(
        'bakeshop.settings.role_permissions.updated',
        null,
        'module_settings',
        'bakeshop',
        ['role_permissions' => $oldPermissions],
        ['role_permissions' => $normalized]
    );

    return $normalized;
}

function bakeshopRoleHasPermission(string $role, string $permission): bool
{
    $permissions = bakeshopRolePermissions();
    return in_array($permission, $permissions[$role] ?? [], true);
}

function bakeshopCurrentUser(?string $permission = null, array $roles = ['admin', 'supervisor']): array
{
    $user = bakeshopCtx()->requireAuth();

    if (bakeshopIsKernelSuperadmin($user)) {
        return $user;
    }

    if (!bakeshopIsModuleUser($user)) {
        throw new DomainException('Forbidden');
    }

    $userId = (int)($user['id'] ?? 0);
    $record = bakeshopAuthenticatedUserRecord($userId);
    if (!is_array($record) || (int)($record['is_active'] ?? 0) !== 1) {
        bakeshopRejectStaleSession();
    }

    if (bakeshopSupportsTokenVersion() && array_key_exists('token_version', $user)) {
        $sessionTokenVersion = (int)($user['token_version'] ?? 0);
        $currentTokenVersion = (int)($record['token_version'] ?? 0);
        if ($sessionTokenVersion !== $currentTokenVersion) {
            bakeshopRejectStaleSession();
        }
    }

    $user['id'] = (int)($record['id'] ?? $userId);
    $user['username'] = (string)($record['username'] ?? ($user['username'] ?? ''));
    $user['email'] = (string)($record['email'] ?? ($user['email'] ?? ''));
    $user['full_name'] = (string)($record['full_name'] ?? ($user['full_name'] ?? ''));
    $user['name'] = (string)($record['full_name'] ?? ($user['name'] ?? $user['username'] ?? ''));
    $user['role'] = (string)($record['role'] ?? ($user['role'] ?? ''));
    if (bakeshopSupportsTokenVersion()) {
        $user['token_version'] = (int)($record['token_version'] ?? ($user['token_version'] ?? 0));
    }

    $role = (string)($user['role'] ?? '');
    if (!in_array($role, $roles, true)) {
        throw new DomainException('Forbidden');
    }

    if ($permission === null || $permission === '') {
        return $user;
    }

    if (!bakeshopRoleHasPermission($role, $permission)) {
        throw new DomainException('Forbidden');
    }

    return $user;
}

function bakeshopTableRows(string $table, string $orderBy = 'id'): array
{
    $allowed = [
        'bakeshop_products',
        'bakeshop_ingredients',
        'bakeshop_product_recipe',
        'bakeshop_deliveries',
        'bakeshop_production_runs',
    ];

    if (!in_array($table, $allowed, true)) {
        throw new \InvalidArgumentException('Unsupported bakeshop table: ' . $table);
    }

    $sql = sprintf('SELECT * FROM `%s` ORDER BY `%s` DESC LIMIT 50', $table, $orderBy);
    $stmt = bakeshopDb()->query($sql);
    return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

function bakeshopNotImplemented(string $feature): void
{
    bakeshopJsonError($feature . ' is not implemented yet.', 501);
}