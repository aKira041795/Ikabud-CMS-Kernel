<?php
declare(strict_types=1);

class AcademicSimilarityTenantPolicy
{
    /**
     * Assert that the given tenant_id matches the current context.
     * Throws RuntimeException on mismatch.
     */
    public static function assertTenantScope(string $requestTenantId, string $contextTenantId): void
    {
        if ($requestTenantId !== $contextTenantId) {
            throw new \RuntimeException('Tenant scope mismatch: requested data from different tenant');
        }
    }

    /**
     * Assert that a resource belongs to the given tenant.
     * Returns the row or throws.
     */
    public static function assertResourceOwnership(string $table, string $idColumn, int $resourceId, string $tenantId): array
    {
        $db = academic_similarity_db();
        $stmt = $db->prepare("SELECT * FROM {$table} WHERE {$idColumn} = :id AND tenant_id = :tid");
        $stmt->execute([':id' => $resourceId, ':tid' => $tenantId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            throw new \RuntimeException('Resource not found or access denied');
        }
        return $row;
    }

    /**
     * Assert user has the required role for the given action.
     */
    public static function assertRole(string $requiredRole): void
    {
        $ctx = module();
        if (!$ctx) {
            throw new \RuntimeException('Module context unavailable');
        }
        $ctx->requireAnyRole(explode(',', $requiredRole));
    }
}
