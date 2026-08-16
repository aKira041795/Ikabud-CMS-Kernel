<?php

declare(strict_types=1);

/**
 * Kernel user administration helpers for tenant entry modules.
 *
 * The kernel `users` table is kernel-owned; no module may claim it in
 * owns_tables (duplicate-owner guard) and the manifest guard rejects
 * co_owns_tables without a module canonical owner. Tenant entry modules that
 * administer kernel users (create accounts, reset passwords, toggle active)
 * therefore reach the table through kernel-escalated access — the same
 * mechanism src/helpers uses for tenant_module_settings / audit_logs (see
 * preloadAllTenantModuleSettings and syncTenantMigrationsForTenant).
 *
 * Only narrow, tenant-scoped operations are exposed. Callers must never pass
 * arbitrary SQL here; this is NOT a general sandbox-bypass runner.
 */

use Ikabud\Kernel\Database\KernelPDO;

/**
 * Raw tenant DB (kernel escalation context must be entered by the caller).
 */
function kernelUsersTenantDb(int $tenantId): \PDO
{
    $db = app()->dbForTenant($tenantId);
    if (!$db instanceof \PDO) {
        throw new \RuntimeException('Kernel users DB unavailable for tenant ' . $tenantId);
    }

    return $db;
}

/**
 * List kernel users for a tenant (tenant DB holds the kernel users table).
 *
 * @return array<int, array<string, mixed>>
 */
function kernelUsersList(int $tenantId): array
{
    KernelPDO::kernelEscalationEnter();
    try {
        return kernelUsersTenantDb($tenantId)
            ->query(
                'SELECT id, username, email, full_name, role, is_active, created_at '
                . 'FROM users ORDER BY username ASC'
            )
            ->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } finally {
        KernelPDO::kernelEscalationLeave();
    }
}

/**
 * Whether a kernel user with the given username exists.
 */
function kernelUserExistsByUsername(int $tenantId, string $username): bool
{
    KernelPDO::kernelEscalationEnter();
    try {
        $stmt = kernelUsersTenantDb($tenantId)->prepare('SELECT id FROM users WHERE username = :u LIMIT 1');
        $stmt->execute([':u' => $username]);

        return $stmt->fetchColumn() !== false;
    } finally {
        KernelPDO::kernelEscalationLeave();
    }
}

/**
 * Whether a kernel user with the given id exists.
 */
function kernelUserExists(int $tenantId, int $userId): bool
{
    KernelPDO::kernelEscalationEnter();
    try {
        $stmt = kernelUsersTenantDb($tenantId)->prepare('SELECT id FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $userId]);

        return $stmt->fetchColumn() !== false;
    } finally {
        KernelPDO::kernelEscalationLeave();
    }
}

/**
 * Create a kernel user and return its id.
 */
function kernelUserCreate(
    int $tenantId,
    string $username,
    ?string $email,
    string $passwordHash,
    string $fullName,
    string $role,
    int $isActive
): int {
    KernelPDO::kernelEscalationEnter();
    try {
        $db = kernelUsersTenantDb($tenantId);
        $stmt = $db->prepare(
            'INSERT INTO users (username, email, password_hash, full_name, role, is_active, token_version) '
            . 'VALUES (:u, :e, :p, :n, :r, :a, 0)'
        );
        $stmt->execute([
            ':u' => $username,
            ':e' => $email,
            ':p' => $passwordHash,
            ':n' => $fullName,
            ':r' => $role,
            ':a' => $isActive,
        ]);

        return (int)$db->lastInsertId();
    } finally {
        KernelPDO::kernelEscalationLeave();
    }
}

/**
 * Reset a kernel user's password (bumps token_version to revoke live sessions).
 */
function kernelUserSetPassword(int $tenantId, int $userId, string $passwordHash): void
{
    KernelPDO::kernelEscalationEnter();
    try {
        kernelUsersTenantDb($tenantId)
            ->prepare('UPDATE users SET password_hash = :p, token_version = token_version + 1 WHERE id = :id')
            ->execute([':p' => $passwordHash, ':id' => $userId]);
    } finally {
        KernelPDO::kernelEscalationLeave();
    }
}

/**
 * Update a kernel user's display full name ("First Last").
 */
function kernelUserSetFullName(int $tenantId, int $userId, string $fullName): void
{
    KernelPDO::kernelEscalationEnter();
    try {
        kernelUsersTenantDb($tenantId)
            ->prepare('UPDATE users SET full_name = :n WHERE id = :id')
            ->execute([':n' => mb_substr($fullName, 0, 100), ':id' => $userId]);
    } finally {
        KernelPDO::kernelEscalationLeave();
    }
}

/**
 * Update a kernel user's email address (nullable).
 */
function kernelUserSetEmail(int $tenantId, int $userId, ?string $email): void
{
    KernelPDO::kernelEscalationEnter();
    try {
        kernelUsersTenantDb($tenantId)
            ->prepare('UPDATE users SET email = :e WHERE id = :id')
            ->execute([':e' => $email, ':id' => $userId]);
    } finally {
        KernelPDO::kernelEscalationLeave();
    }
}

/**
 * Enable/disable a kernel user (bumps token_version to revoke live sessions).
 */
function kernelUserSetActive(int $tenantId, int $userId, bool $active): void
{
    KernelPDO::kernelEscalationEnter();
    try {
        kernelUsersTenantDb($tenantId)
            ->prepare('UPDATE users SET is_active = :a, token_version = token_version + 1 WHERE id = :id')
            ->execute([':a' => $active ? 1 : 0, ':id' => $userId]);
    } finally {
        KernelPDO::kernelEscalationLeave();
    }
}

/**
 * Resolve a kernel user's username (for audit target labels).
 */
function kernelUserUsername(int $tenantId, int $userId): ?string
{
    KernelPDO::kernelEscalationEnter();
    try {
        $stmt = kernelUsersTenantDb($tenantId)->prepare('SELECT username FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $userId]);
        $value = $stmt->fetchColumn();

        return is_string($value) ? $value : null;
    } finally {
        KernelPDO::kernelEscalationLeave();
    }
}
