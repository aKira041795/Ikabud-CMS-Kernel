<?php

declare(strict_types=1);

use Ikabud\Kernel\Contracts\ModuleContext;

function wmsResponseGuard(callable $callback): void
{
    try {
        $callback();
    } catch (\DomainException $e) {
        wmsJsonError($e->getMessage() !== '' ? $e->getMessage() : 'Forbidden', 403);
    } catch (\InvalidArgumentException $e) {
        wmsJsonError($e->getMessage(), 422);
    } catch (\Throwable $e) {
        if (function_exists('write_log')) {
            write_log('wms handler error: ' . $e->getMessage(), 'error');
        }
        wmsJsonError('Unexpected server error.', 500);
    }
}

function wmsEnforceCsrf(): void
{
    app()->csrfEnforce();
}

function wmsCurrentUser(array $roles = ['admin', 'supervisor', 'viewer']): array
{
    $user = app()->user();
    if (!$user) {
        wmsClearAuthCookie();
        app()->redirect('/wms/login');
    }

    if (wmsIsKernelSuperadmin($user)) {
        return $user;
    }

    if (!wmsIsModuleUser($user)) {
        throw new \DomainException('Forbidden');
    }

    $userId = (int)($user['id'] ?? 0);
    $record = wmsAuthenticatedUserRecord($userId);
    if (!is_array($record) || (int)($record['is_active'] ?? 0) !== 1) {
        wmsRejectStaleSession();
    }

    $user['id'] = (int)($record['id'] ?? $userId);
    $user['username'] = (string)($record['username'] ?? ($user['username'] ?? ''));
    $user['email'] = (string)($record['email'] ?? ($user['email'] ?? ''));
    $user['full_name'] = (string)($record['full_name'] ?? ($user['full_name'] ?? ''));
    $user['role'] = (string)($record['role'] ?? ($user['role'] ?? ''));

    $role = (string)($user['role'] ?? '');
    if (!in_array($role, $roles, true)) {
        throw new \DomainException('Forbidden');
    }

    return $user;
}

function wmsIsKernelSuperadmin(array $user): bool
{
    return ($user['role'] ?? '') === 'superadmin' && ($user['source'] ?? '') === 'kernel';
}

function wmsIsModuleUser(array $user): bool
{
    $source = $user['source'] ?? '';
    return $source === 'wms';
}

function wmsAuthenticatedUserRecord(int $userId): ?array
{
    try {
        $stmt = wmsDb()->prepare(
            'SELECT id, username, email, full_name, role, is_active FROM wms_users WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $userId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    } catch (\Throwable $e) {
        return null;
    }
}

function wmsRejectStaleSession(): void
{
    wmsClearAuthCookie();
    unset($_SESSION['wms_user']);
    app()->redirect('http://wmstest.test/wms/login');
}
