<?php
declare(strict_types=1);

/**
 * Tenant isolation policy — ensures all queries are scoped to the current tenant.
 */
class ThesisTenantPolicy
{
    private string $tenantId;

    public function __construct(string $tenantId)
    {
        $this->tenantId = $tenantId;
    }

    public function assertTenantMatch(string $resourceTenantId): void
    {
        if ($resourceTenantId !== $this->tenantId) {
            throw new \RuntimeException("Cross-tenant access denied: expected {$this->tenantId}, got {$resourceTenantId}");
        }
    }

    public function getTenantId(): string
    {
        return $this->tenantId;
    }
}
