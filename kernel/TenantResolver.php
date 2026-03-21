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
 * When multi-tenancy is DISABLED (the default for Baron Bakeshop),
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
        if ($this->strategy === 'header' || $this->strategy === 'auto') {
            $headerKey = 'HTTP_' . strtoupper(str_replace('-', '_', $this->header));
            if (!empty($_SERVER[$headerKey])) {
                return (int) $_SERVER[$headerKey];
            }
        }

        // Strategy 3: Control-plane host -> tenant mapping (production)
        if ($this->strategy === 'control_host' || $this->strategy === 'control' || $this->strategy === 'auto') {
            $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
            $host = strtolower(trim($host));
            if ($host !== '') {
                $host = preg_replace('/:\d+$/', '', $host) ?: $host;
                try {
                    $pdo = app()->controlDb();
                    $stmt = $pdo->prepare('SELECT tenant_id FROM kernel_tenant_domains WHERE domain = :d LIMIT 1');
                    $stmt->execute([':d' => $host]);
                    $tid = $stmt->fetchColumn();
                    if ($tid !== false && $tid !== null) {
                        return (int) $tid;
                    }
                } catch (\Throwable $e) {
                    // Best-effort: fall through to other strategies.
                }
            }
        }

        // Strategy 4: Host mapping
        if ($this->strategy === 'host' || $this->strategy === 'auto') {
            $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
            $host = strtolower(trim($host));
            if ($host !== '') {
                $host = preg_replace('/:\d+$/', '', $host) ?: $host;
                if (array_key_exists($host, $this->hostMap)) {
                    return (int) $this->hostMap[$host];
                }
            }
        }

        // Strategy 5: Subdomain
        if ($this->strategy === 'subdomain' || $this->strategy === 'auto') {
            $host = $_SERVER['HTTP_HOST'] ?? '';
            // Extract first subdomain segment: "shop1.bakeshop.com" → "shop1"
            $parts = explode('.', $host);
            if (count($parts) >= 3) {
                // The subdomain must map to a tenant_id — this requires a lookup table
                // For now, return null (implementors override doResolve or use a hook)
            }
        }

        // Strategy 6: Session
        if (isset($_SESSION['tenant_id'])) {
            return (int) $_SESSION['tenant_id'];
        }

        // Strategy 7: Config default
        return $this->default;
    }
}
