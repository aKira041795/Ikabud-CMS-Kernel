<?php

/**
 * Connection Pool Manager
 *
 * Generic named database connection pool. Manages lazy creation, idle
 * validation, and auto-reconnect for ad-hoc named connections.
 *
 * RELATIONSHIP WITH DatabaseManager:
 *   DatabaseManager (kernel/Services/) handles app-level DB topology
 *   (primary, control, tenant connections with config resolution,
 *   encrypted passwords, SSL, retry logic). ConnectionPool is the
 *   lower-level generic pool that DatabaseManager can optionally use
 *   for its tenant connection mechanics.
 *
 * USE CASES:
 *   - Module-level ad-hoc connections (external APIs, reporting DBs)
 *   - CLI scripts needing a quick PDO without app boot
 *   - Tests requiring isolated connection pools
 *   - Tenant connections delegated through DatabaseManager
 *
 * @package Ikabud\Kernel\Database
 * @version 2.1.0
 */

namespace Ikabud\Kernel\Database;

use PDO;
use Exception;

class ConnectionPool
{
    private const IDLE_VALIDATION_SECONDS = 15;

    /** @var array<string, array{config: array, connection: PDO|null, last_used: int|null, last_verified: int|null}> */
    private array $pool = [];

    /** @var \Ikabud\Kernel\Services\DatabaseManager|null Optional delegate for tenant-aware connections */
    private $dbManager = null;

    /**
     * Optionally bind a DatabaseManager for tenant-aware connection resolution.
     * When set, get('tenant:N') delegates to DatabaseManager::dbForTenant().
     */
    public function setDatabaseManager(\Ikabud\Kernel\Services\DatabaseManager $mgr): void
    {
        $this->dbManager = $mgr;
    }

    /**
     * Register a named database configuration (lazy — no connection yet).
     *
     * For tenant connections, use register('tenant:N', $config) where N is
     * the tenant ID. The config array accepts:
     *   host, port, database, username, password, charset, driver
     */
    public function register(string $name, array $config): void
    {
        $this->pool[$name] = [
            'config' => [
                'driver'   => $config['driver']   ?? 'mysql',
                'host'     => $config['host']     ?? 'localhost',
                'port'     => $config['port']     ?? '3306',
                'database' => $config['database'] ?? '',
                'username' => $config['username'] ?? '',
                'password' => $config['password'] ?? '',
                'charset'  => $config['charset']  ?? 'utf8mb4',
            ],
            'connection'    => null,
            'last_used'     => null,
            'last_verified' => null,
        ];
    }

    /**
     * Check if a connection is registered.
     */
    public function has(string $name): bool
    {
        return isset($this->pool[$name]);
    }

    /**
     * Get a database connection by name (lazy-created, auto-reconnect).
     *
     * Delegates to DatabaseManager::dbForTenant() when:
     *   - The name matches 'tenant:N' and a DatabaseManager is bound.
     * For all other names, manages the connection directly.
     *
     * @param string $name Registered connection name
     * @return PDO|null Connection or null if registration missing / connection failed
     */
    public function get(string $name): ?PDO
    {
        // Delegate tenant connections to DatabaseManager when available
        if ($this->dbManager !== null && preg_match('/^tenant:(\d+)$/', $name, $m)) {
            return $this->dbManager->dbForTenant((int)$m[1]);
        }

        if (!isset($this->pool[$name])) {
            return null;
        }

        $entry = &$this->pool[$name];
        $now = time();

        // Return existing connection if still valid
        if ($entry['connection'] !== null) {
            $lastVerified = (int)($entry['last_verified'] ?? 0);
            if ($lastVerified > 0 && ($now - $lastVerified) < self::IDLE_VALIDATION_SECONDS) {
                $entry['last_used'] = $now;
                return $entry['connection'];
            }

            try {
                $entry['connection']->query('SELECT 1');
                $entry['last_used'] = $now;
                $entry['last_verified'] = $now;
                return $entry['connection'];
            } catch (Exception $e) {
                $entry['connection'] = null;
                $entry['last_verified'] = null;
            }
        }

        // Create new connection
        $c = $entry['config'];
        $dsn = sprintf(
            '%s:host=%s;port=%s;dbname=%s;charset=%s',
            $c['driver'],
            $c['host'],
            $c['port'],
            $c['database'],
            $c['charset']
        );

        try {
            $pdoClass = '\\Ikabud\\Kernel\\Database\\KernelPDO';
            $entry['connection'] = new $pdoClass($dsn, $c['username'], $c['password'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_TIMEOUT            => 5,
            ]);
            $entry['last_used']     = $now;
            $entry['last_verified'] = $now;
            return $entry['connection'];
        } catch (Exception $e) {
            error_log("[ConnectionPool] Failed to connect '{$name}': " . $e->getMessage());
            return null;
        }
    }

    /**
     * Close a specific connection (sets to null, keeps registration).
     */
    public function close(string $name): void
    {
        if (isset($this->pool[$name])) {
            $this->pool[$name]['connection'] = null;
        }
    }

    /**
     * Close all connections and clear the pool.
     */
    public function closeAll(): void
    {
        foreach ($this->pool as &$entry) {
            $entry['connection'] = null;
        }
        $this->pool = [];
    }

    /**
     * Get pool statistics.
     *
     * @return array{registered: int, active: int, names: string[]}
     */
    public function getStats(): array
    {
        $active = 0;
        $names = [];
        foreach ($this->pool as $name => &$entry) {
            if ($entry['connection'] !== null) {
                $active++;
            }
            $names[] = $name;
        }
        return [
            'registered' => count($this->pool),
            'active'     => $active,
            'names'      => $names,
        ];
    }
}
