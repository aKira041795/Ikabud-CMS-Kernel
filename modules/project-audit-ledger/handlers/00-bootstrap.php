<?php

declare(strict_types=1);

use Ikabud\Kernel\Contracts\ModuleContext;

function palResponseGuard(callable $callback): void
{
    try {
        $callback();
    } catch (DomainException $e) {
        palJsonError($e->getMessage() !== '' ? $e->getMessage() : 'Forbidden', 403);
    } catch (InvalidArgumentException $e) {
        palJsonError($e->getMessage(), 422);
    } catch (Throwable $e) {
        if (function_exists('write_log')) {
            write_log('pal handler error: ' . $e->getMessage(), 'error');
        }
        try {
            palCtx()->log('pal handler error: ' . $e->getMessage(), 'error');
        } catch (Throwable $ignored) {
        }
        palJsonError('Unexpected server error.', 500);
    }
}

function palEnforceCsrf(): void
{
    app()->csrfEnforce();
}

function palCurrentUser(array $roles = ['admin', 'supervisor', 'encoder']): array
{
    $user = palCtx()->requireAuth();

    if (palIsKernelSuperadmin($user)) {
        return $user;
    }

    if (!palIsModuleUser($user)) {
        throw new DomainException('Forbidden');
    }

    $userId = (int)($user['id'] ?? 0);
    $record = palAuthenticatedUserRecord($userId);
    if (!is_array($record) || (int)($record['is_active'] ?? 0) !== 1) {
        palRejectStaleSession();
    }

    if (palSupportsTokenVersion()) {
        $sessionTokenVersion = (int)($user['token_version'] ?? 0);
        $currentTokenVersion = (int)($record['token_version'] ?? 0);
        if ($sessionTokenVersion !== $currentTokenVersion) {
            palRejectStaleSession();
        }
    }

    $user['id'] = (int)($record['id'] ?? $userId);
    $user['username'] = (string)($record['username'] ?? ($user['username'] ?? ''));
    $user['email'] = (string)($record['email'] ?? ($user['email'] ?? ''));
    $user['full_name'] = (string)($record['full_name'] ?? ($user['full_name'] ?? ''));
    $user['name'] = (string)($record['full_name'] ?? ($user['name'] ?? $user['username'] ?? ''));
    $user['role'] = (string)($record['role'] ?? ($user['role'] ?? ''));
    if (palSupportsTokenVersion()) {
        $user['token_version'] = (int)($record['token_version'] ?? ($user['token_version'] ?? 0));
    }

    $role = (string)($user['role'] ?? '');
    if (!in_array($role, $roles, true)) {
        throw new DomainException('Forbidden');
    }

    return $user;
}

function palRequireRole(string $role): array
{
    return palCurrentUser([$role]);
}
