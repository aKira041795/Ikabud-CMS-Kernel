<?php
/**
 * Connection Pool Manager
 * 
 * Manages named database connections with lazy creation and validation.
 * The primary connection is managed by App::db(). This pool is available
 * for modules or features that need additional database connections.
 * 
 * NOTE: This class has been superseded by DatabaseManager (kernel/Services/)
 * which provides APCu caching, SSL support, encrypted password decryption,
 * retry logic with exponential backoff, and unified connection lifecycle.
 * ConnectionPool remains for legacy test compatibility.
 *
 * @package Ikabud\Kernel\Database
 * @version 2.0.0
 * @deprecated Use Ikabud\Kernel\Services\DatabaseManager instead
 */

namespace Ikabud\Kernel\Database;

use PDO;
use Exception;

class ConnectionPool
{
    private const IDLE_VALIDATION_SECONDS = 15;

    /** @var array Connection pool storage */
    private array $pool = [];

    /**
     * Scope connection name by tenant when multi-tenancy is enabled.
     */
    private function scopedName(string $name): string
    {
        if (!function_exists('app')) {
            return $name;
        }

        try {
            $mt = app()->config('app.multi_tenant', []);
            $enabled = false;
            if (is_array($mt) && !empty($mt['enabled'])) {
                $enabled = true;
            }
            if (!$enabled) {
                $enabled = !empty($_ENV['APP_MULTI_TENANT_ENABLED']);
            }
            if (!$enabled) {
                return $name;
            }

            $tenantId = app()->tenant()->current();
            if ($tenantId === null) {
                $tenantId = app()->tenant()->resolve(app()->user());
            }
            if ($tenantId === null || $tenantId <= 0) {
                return $name;
            }

            return 't' . (string)$tenantId . ':' . $name;
        } catch (\Throwable $e) {
            return $name;
        }
    }
    
    /**
     * Register a named database configuration (lazy - no connection yet)
     */
    public function register(string $name, array $config): void
    {
        $name = $this->scopedName($name);
        $this->pool[$name] = [
            'config' => [
                'host' => $config['host'] ?? 'localhost',
                'port' => $config['port'] ?? '3306',
                'database' => $config['database'] ?? '',
                'username' => $config['username'] ?? '',
                'password' => $config['password'] ?? '',
                'charset' => $config['charset'] ?? 'utf8mb4',
            ],
            'connection' => null,
            'last_used' => null,
            'last_verified' => null,
        ];
    }
    
    /**
     * Check if a connection is registered
     */
    public function has(string $name): bool
    {
        $name = $this->scopedName($name);
        return isset($this->pool[$name]);
    }
    
    /**
     * Get a database connection by name (lazy-created, auto-reconnect)
     */
    public function get(string $name): ?PDO
    {
        $name = $this->scopedName($name);
        if (!isset($this->pool[$name])) {
            return null;
        }
        
        $pool = &$this->pool[$name];
        $now = time();
        
        // Return existing connection if valid
        if ($pool['connection'] !== null) {
            $lastVerified = (int)($pool['last_verified'] ?? 0);
            if ($lastVerified > 0 && ($now - $lastVerified) < self::IDLE_VALIDATION_SECONDS) {
                $pool['last_used'] = $now;
                return $pool['connection'];
            }

            try {
                $pool['connection']->query('SELECT 1');
                $pool['last_used'] = $now;
                $pool['last_verified'] = $now;
                return $pool['connection'];
            } catch (Exception $e) {
                $pool['connection'] = null;
                $pool['last_verified'] = null;
            }
        }
        
        // Create new connection
        $config = $pool['config'];
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            $config['host'],
            $config['port'],
            $config['database'],
            $config['charset']
        );
        
        try {
            $pdoClass = '\\Ikabud\\Kernel\\Database\\KernelPDO';
            $pool['connection'] = new $pdoClass($dsn, $config['username'], $config['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_TIMEOUT => 5,
            ]);
            $pool['last_used'] = $now;
            $pool['last_verified'] = $now;
            return $pool['connection'];
        } catch (Exception $e) {
            error_log("[ConnectionPool] Failed to connect '{$name}': " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Close a specific connection
     */
    public function close(string $name): void
    {
        $name = $this->scopedName($name);
        if (isset($this->pool[$name])) {
            $this->pool[$name]['connection'] = null;
        }
    }
    
    /**
     * Close all connections
     */
    public function closeAll(): void
    {
        foreach ($this->pool as &$pool) {
            $pool['connection'] = null;
        }
        $this->pool = [];
    }
    
    /**
     * Get pool statistics
     */
    public function getStats(): array
    {
        $active = 0;
        foreach ($this->pool as $pool) {
            if ($pool['connection'] !== null) {
                $active++;
            }
        }
        
        return [
            'registered' => count($this->pool),
            'active' => $active,
        ];
    }
}
