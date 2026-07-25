<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Adapters;

use Ikabud\Kernel\Contracts\TenantDatabase;

/**
 * AppTenantDatabase — adapts App::db() to the TenantDatabase contract.
 *
 * Step 2 of the App decomposition roadmap. This adapter wraps the
 * existing App singleton behind the narrow TenantDatabase interface.
 * Services can type-hint TenantDatabase instead of calling app()->db().
 *
 * @package Ikabud\Kernel\Adapters
 */
final class AppTenantDatabase implements TenantDatabase
{
    private \Ikabud\Kernel\App $app;

    public function __construct(?\Ikabud\Kernel\App $app = null)
    {
        $this->app = $app ?? \Ikabud\Kernel\App::getInstance();
    }

    public function prepare(string $sql): \PDOStatement
    {
        return $this->app->db()->prepare($sql);
    }

    public function query(string $sql, array $params = []): \PDOStatement
    {
        $stmt = $this->app->db()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public function execute(string $sql, array $params = []): bool
    {
        $stmt = $this->app->db()->prepare($sql);
        return $stmt->execute($params);
    }

    public function lastInsertId(): string
    {
        return $this->app->db()->lastInsertId();
    }

    public function dbForTenant(int $tenantId): ?\PDO
    {
        return $this->app->dbForTenant($tenantId);
    }

    public function reconnect(): \PDO
    {
        return $this->app->reconnectDb();
    }

    public function reconnectForTenant(int $tenantId): ?\PDO
    {
        return $this->app->reconnectDbForTenant($tenantId);
    }

    public function beginTransaction(): bool
    {
        return $this->app->db()->beginTransaction();
    }

    public function commit(): bool
    {
        return $this->app->db()->commit();
    }

    public function rollBack(): bool
    {
        return $this->app->db()->rollBack();
    }

    public function inTransaction(): bool
    {
        return $this->app->db()->inTransaction();
    }
}
