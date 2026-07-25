<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Contracts;

/**
 * TenantDatabase — narrow database contract for kernel-level injection.
 *
 * Unlike DatabaseContract (which enforces module table ownership rules),
 * this interface provides direct tenant-scoped database access for kernel
 * services and infrastructure that operate outside module boundaries.
 *
 * Step 1 of the App decomposition roadmap: services should type-hint this
 * interface instead of calling app()->db().
 *
 * @package Ikabud\Kernel\Contracts
 */
interface TenantDatabase
{
    /**
     * Prepare a SQL statement for execution.
     */
    public function prepare(string $sql): \PDOStatement;

    /**
     * Execute a query and return the statement.
     *
     * @param array $params Positional (?) or named (:key) parameters
     */
    public function query(string $sql, array $params = []): \PDOStatement;

    /**
     * Execute a write statement (INSERT/UPDATE/DELETE).
     *
     * @param array $params Positional (?) or named (:key) parameters
     */
    public function execute(string $sql, array $params = []): bool;

    /**
     * Get the last inserted ID.
     */
    public function lastInsertId(): string;

    /**
     * Get a database connection for a specific tenant.
     */
    public function dbForTenant(int $tenantId): ?\PDO;

    /**
     * Reconnect the primary tenant database connection.
     */
    public function reconnect(): \PDO;

    /**
     * Reconnect the database connection for a specific tenant.
     */
    public function reconnectForTenant(int $tenantId): ?\PDO;
}
