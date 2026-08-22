<?php
/**
 * Ikabud Kernel — Tenant Resolver
 * 
 * Determines the current tenant context for multi-tenant deployments.
 * When multi-tenancy is enabled (via config), the resolver identifies the
 * tenant from the request (subdomain, header, JWT claim, or session) and
 * makes it available to the query builder for automatic scoping.
 * 
 * Strategies (checked in order):
 *   1. JWT claim 'tenant_id'   — API requests carry it in the token
 *   2. HTTP header 'X-Tenant'  — explicit override (admin/service use)
 *   3. Subdomain               — shop1.bakeshop.com → tenant 'shop1'
 *   4. Session                  — stored after login
 *   5. Config default           — single-tenant fallback
 * 
 * When multi-tenancy is DISABLED (the default for Ikabud),
 * the resolver returns null — meaning no tenant scoping is applied.
 * This makes the system zero-friction for single-tenant deployments
 * while being ready for multi-tenant when the config flag is flipped.
 * 
 * Config (config/app.php):
 *   'multi_tenant' => [
 *       'enabled'  => false,        // flip to true to activate
 *       'strategy' => 'jwt',        // 'jwt', 'header', 'subdomain', 'config'
 *       'header'   => 'X-Tenant',   // for strategy=header
 *       'default'  => null,         // fallback tenant_id
 *       'column'   => 'tenant_id',  // DB column name
 *   ],
 * 
 * Usage:
 *   $resolver = new TenantResolver($config);
 *   $tenantId = $resolver->resolve($user);  // returns int|null
 * 
 * @package Ikabud\Kernel
 * @version 1.0.0
 */

namespace Ikabud\Kernel;

class TenantResolver
{
    private static ?TenantResolver $instance = null;
    /** @var array<string, array{value: array<string,mixed>|null, expires_at: int}> */
    private static array $controlHostCache = [];
    private static array $controlHostCacheMetrics = [
        'memory_hits' => 0,
        'memory_expired' => 0,
        'apcu_hits' => 0,
        'db_hits' => 0,
        'misses' => 0,
        'errors' => 0,
    ];

    private bool $enabled;
    private string $strategy;
    private string $header;
    private ?int $default;
    private string $column;
    private array $hostMap;
    private ?int $resolvedTenantId = null;
    private bool $resolved = false;

    public function __construct(array $config = [])
    {
        $mt = $config['multi_tenant'] ?? $config ?? [];
        $this->enabled  = (bool) ($mt['enabled'] ?? false);
        $this->strategy = (string) ($mt['strategy'] ?? 'jwt');
        $this->header   = (string) ($mt['header'] ?? 'X-Tenant');
        $this->default  = isset($mt['default']) ? (int) $mt['default'] : null;
        $this->column   = (string) ($mt['column'] ?? 'tenant_id');
        $this->hostMap  = is_array($mt['host_map'] ?? null) ? (array) $mt['host_map'] : [];
    }

    public static function getInstance(array $config = []): self
    {
        if (self::$instance === null) {
            self::$instance = new self($config);
        }
        return self::$instance;
    }

    /**
     * Is multi-tenancy enabled?
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get the DB column name used for tenant scoping.
     */
    public function column(): string
    {
        return $this->column;
    }

    /**
     * Resolve the current tenant ID.
     * Returns null if multi-tenancy is disabled or tenant cannot be determined.
     * 
     * @param array|null $user Current authenticated user (JWT payload)
     */
    public function resolve(?array $user = null): ?int
    {
        if (!$this->enabled) {
            return null;
        }

        if ($this->resolved) {
            return $this->resolvedTenantId;
        }

        $this->resolved = true;
        $this->resolvedTenantId = $this->doResolve($user);
        return $this->resolvedTenantId;
    }

    /**
     * Manually set the tenant ID (useful for CLI, tests, admin impersonation).
     */
    public function setTenantId(?int $id): void
    {
        $this->resolvedTenantId = $id;
        $this->resolved = true;
    }

    /**
     * Get the currently resolved tenant ID without re-resolving.
     */
    public function current(): ?int
    {
        return $this->resolvedTenantId;
    }

    /**
     * Reset resolution state (for tests).
     */
    public function reset(): void
    {
        $this->resolvedTenantId = null;
        $this->resolved = false;
    }

    public static function normalizeHost(?string $host): string
    {
        $host = strtolower(trim((string) $host));
        if ($host === '') {
            return '';
        }

        return preg_replace('/:\d+$/', '', $host) ?: $host;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function lookupControlHostRecord(string $host): ?array
    {
        $host = self::normalizeHost($host);
        if ($host === '') {
            return null;
        }

        if (array_key_exists($host, self::$controlHostCache)) {
            $entry = self::$controlHostCache[$host];
            if (is_array($entry) && isset($entry['expires_at']) && $entry['expires_at'] > time()) {
                self::$controlHostCacheMetrics['memory_hits']++;
                return $entry['value'];
            }
            // Expired — fall through to refresh.
            self::$controlHostCacheMetrics['memory_expired']++;
            unset(self::$controlHostCache[$host]);
        }

        $apcuEnabled = function_exists('apcu_fetch') && function_exists('apcu_store') && ini_get('apc.enabled');
        $apcuKey = 'ikabud:tenant_host:' . sha1($host);
        if ($apcuEnabled) {
            $cached = apcu_fetch($apcuKey, $success);
            if ($success) {
                self::$controlHostCacheMetrics['apcu_hits']++;
                $value = is_array($cached) ? $cached : null;
                self::$controlHostCache[$host] = [
                    'value' => $value,
                    'expires_at' => time() + max(1, (int) ($_ENV['TENANT_HOST_CACHE_TTL'] ?? 30)),
                ];
                return $value;
            }
        }

        $result = null;
        try {
            $pdo = app()->controlDb();
            $stmt = $pdo->prepare(
                'SELECT td.tenant_id, t.entry_module_id, t.status, t.canonical_domain '
                . 'FROM kernel_tenant_domains td '
                . 'JOIN kernel_tenants t ON t.id = td.tenant_id '
                . 'WHERE td.domain = :d LIMIT 1'
            );
            $stmt->execute([':d' => $host]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (is_array($row)) {
                $result = $row;
                self::$controlHostCacheMetrics['db_hits']++;
            } else {
                self::$controlHostCacheMetrics['misses']++;
            }
        } catch (\Throwable $e) {
            $result = null;
            self::$controlHostCacheMetrics['errors']++;
        }

        self::$controlHostCache[$host] = [
            'value' => $result,
            'expires_at' => time() + max(1, (int) ($_ENV['TENANT_HOST_CACHE_TTL'] ?? 30)),
        ];

        if ($apcuEnabled) {
            $ttl = max(1, (int) ($_ENV['TENANT_HOST_CACHE_TTL'] ?? 30));
            apcu_store($apcuKey, $result, $ttl);
        }

        if (function_exists('write_log') && (($_ENV['TENANT_HOST_CACHE_LOG'] ?? '0') === '1')) {
            write_log('tenant.host.lookup', 'debug', [
                'host' => $host,
                'result' => is_array($result) ? 'hit' : 'miss',
                'metrics' => self::$controlHostCacheMetrics,
            ]);
        }

        return $result;
    }

    public static function controlHostCacheMetrics(): array
    {
        return [
            'metrics' => self::$controlHostCacheMetrics,
            'in_memory_hosts' => count(self::$controlHostCache),
            'apcu_enabled' => function_exists('apcu_fetch') && function_exists('apcu_store') && (bool)ini_get('apc.enabled'),
            'ttl_seconds' => max(1, (int) ($_ENV['TENANT_HOST_CACHE_TTL'] ?? 30)),
        ];
    }

    /**
     * Clear control-host lookup caches (in-memory + APCu).
     *
     * @return array{memory_cleared:int,apcu_cleared:int}
     */
    public static function clearControlHostCache(): array
    {
        $memoryCleared = count(self::$controlHostCache);
        self::$controlHostCache = [];

        $apcuCleared = 0;
        $apcuEnabled = function_exists('apcu_fetch') && function_exists('apcu_store') && (bool)ini_get('apc.enabled');
        if ($apcuEnabled && function_exists('apcu_cache_info')) {
            $info = apcu_cache_info();
            if (is_array($info) && isset($info['cache_list']) && is_array($info['cache_list'])) {
                foreach ($info['cache_list'] as $entry) {
                    $key = (string)($entry['info'] ?? '');
                    if ($key !== '' && str_starts_with($key, 'ikabud:tenant_host:')) {
                        if (apcu_delete($key)) {
                            $apcuCleared++;
                        }
                    }
                }
            }
        }

        return ['memory_cleared' => $memoryCleared, 'apcu_cleared' => $apcuCleared];
    }

    /**
     * Check whether a tenant ID is currently ACTIVE in the control plane.
     * Fail-closed: returns true ONLY for status 'active'. Everything else —
     * pending, provisioning, suspended, missing, or a control-DB failure
     * (cannot verify) — returns false, so an unverifiable tenant is never
     * resolved.
     *
     * Used to prevent stale/deactivated/pending tenant contexts from being
     * resolved via the host, header, or session strategies.
     */
    public static function tenantIsActive(int $tenantId): bool
    {
        if ($tenantId <= 0) {
            return false;
        }
        try {
            $pdo = app()->controlDb();
            $stmt = $pdo->prepare('SELECT status FROM kernel_tenants WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $tenantId]);
            $status = $stmt->fetch(\PDO::FETCH_COLUMN);
            if ($status === false) {
                return false; // tenant does not exist
            }
            return strtolower((string)$status) === 'active';
        } catch (\Throwable $e) {
            return false; // cannot verify — fail closed
        }
    }

    /**
     * Resolve a tenant ID from a tenant_key (used by the subdomain strategy).
     * Only active tenants are returned.
     */
    public static function lookupTenantIdByKey(string $key): ?int
    {
        $key = strtolower(trim($key));
        if ($key === '') {
            return null;
        }
        try {
            $pdo = app()->controlDb();
            $stmt = $pdo->prepare('SELECT id FROM kernel_tenants WHERE tenant_key = :k AND status = \'active\' LIMIT 1');
            $stmt->execute([':k' => $key]);
            $id = $stmt->fetch(\PDO::FETCH_COLUMN);
            return $id !== false ? (int) $id : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    // ── Internal ─────────────────────────────────────────────────────

    private function doResolve(?array $user): ?int
    {
        // Strategy 1: JWT claim
        if ($this->strategy === 'jwt' || $this->strategy === 'auto') {
            if ($user && isset($user['tenant_id'])) {
                return (int) $user['tenant_id'];
            }
        }

        // Strategy 2: HTTP header
        // Only accept X-Tenant header from superadmin (kernel-authenticated) callers.
        // This prevents low-privilege requests from overriding their own tenant context.
        if ($this->strategy === 'header' || $this->strategy === 'auto') {
            $headerKey = 'HTTP_' . strtoupper(str_replace('-', '_', $this->header));
            if (!empty($_SERVER[$headerKey])) {
                $isSuperadmin = is_array($user)
                    && ($user['role'] ?? '') === 'superadmin'
                    && ($user['source'] ?? '') === 'kernel';
                if ($isSuperadmin) {
                    $headerTenantId = (int) $_SERVER[$headerKey];
                    // Validate the header value points at a real, active tenant.
                    // Fail CLOSED: pending/provisioning/suspended tenants AND
                    // control-DB failures are all rejected.
                    if (self::tenantIsActive($headerTenantId) === true) {
                        return $headerTenantId;
                    }
                }
            }
        }

        // Strategy 3: Control-plane host -> tenant mapping (production)
        if ($this->strategy === 'control_host' || $this->strategy === 'control' || $this->strategy === 'auto') {
            $host = self::normalizeHost((string) ($_SERVER['HTTP_HOST'] ?? ''));
            if ($host !== '') {
                $row = self::lookupControlHostRecord($host);
                // Fail closed: only an active tenant may be resolved via host.
                if (is_array($row) && isset($row['tenant_id']) && self::tenantIsActive((int) $row['tenant_id']) === true) {
                    return (int) $row['tenant_id'];
                }
            }
        }

        // Strategy 4: Host mapping
        if ($this->strategy === 'host' || $this->strategy === 'auto') {
            $host = self::normalizeHost((string) ($_SERVER['HTTP_HOST'] ?? ''));
            if ($host !== '') {
                if (array_key_exists($host, $this->hostMap)) {
                    return (int) $this->hostMap[$host];
                }
            }
        }

        // Strategy 5: Subdomain
        if ($this->strategy === 'subdomain' || $this->strategy === 'auto') {
            $host = self::normalizeHost((string)($_SERVER['HTTP_HOST'] ?? ''));
            if ($host !== '') {
                // 5a. Try the full host via the control-plane domain mapping
                //     first (covers canonical_domain records like
                //     "shop1.bakeshop.com"). Fail closed on non-active tenants.
                $row = self::lookupControlHostRecord($host);
                if (is_array($row) && isset($row['tenant_id']) && self::tenantIsActive((int) $row['tenant_id']) === true) {
                    return (int) $row['tenant_id'];
                }
                // 5b. Fall back: treat the first subdomain segment as a
                //     tenant_key ("shop1.bakeshop.com" → "shop1"). Only
                //     active tenants are accepted.
                $parts = explode('.', $host);
                if (count($parts) >= 3) {
                    $tenantId = self::lookupTenantIdByKey($parts[0]);
                    if ($tenantId !== null) {
                        return $tenantId;
                    }
                }
            }
        }

        // Strategy 6: Session — validate the stored tenant is still active so
        // a deactivated/suspended/pending tenant can no longer be resolved from
        // the session. Fail CLOSED on control-DB failure.
        if (isset($_SESSION['tenant_id'])) {
            $sessionTenantId = (int) $_SESSION['tenant_id'];
            if (self::tenantIsActive($sessionTenantId) === true) {
                return $sessionTenantId;
            }
            // Known to be missing, non-active, or unverifiable — do not trust
            // the session.
            unset($_SESSION['tenant_id']);
        }

        // Strategy 7: Config default
        return $this->default;
    }
}
