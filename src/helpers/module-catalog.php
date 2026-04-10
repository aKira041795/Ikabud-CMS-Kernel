<?php

declare(strict_types=1);

// ─── Control-plane catalog + tenant entitlements ─────────────────────────

function moduleCatalogTable(): string
{
    return 'kernel_module_catalog';
}

function moduleTenantEntitlementsTable(): string
{
    return 'kernel_tenant_module_entitlements';
}

function moduleAccessRequestsTable(): string
{
    return 'kernel_tenant_module_access_requests';
}

function invalidateModuleCatalogCache(): void
{
    unset(
        $GLOBALS['_kernel_module_catalog_cache'],
        $GLOBALS['_kernel_module_entitlement_cache'],
        $GLOBALS['_kernel_module_access_request_cache']
    );
}

function moduleControlPlaneEnsureCatalogTables(): bool
{
    static $ensured = null;
    if ($ensured !== null) {
        return $ensured;
    }

    try {
        $db = app()->controlDb();
        $db->exec(
            'CREATE TABLE IF NOT EXISTS ' . moduleCatalogTable() . ' ('
            . 'id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, '
            . 'module_id VARCHAR(100) NOT NULL, '
            . 'module_name VARCHAR(190) DEFAULT NULL, '
            . 'approved_version VARCHAR(60) DEFAULT NULL, '
            . 'checksum_sha256 CHAR(64) DEFAULT NULL, '
            . 'install_path VARCHAR(255) DEFAULT NULL, '
            . "source VARCHAR(40) NOT NULL DEFAULT 'admin_install', "
            . "approval_status VARCHAR(20) NOT NULL DEFAULT 'pending', "
            . "commercial_mode VARCHAR(20) NOT NULL DEFAULT 'free', "
            . 'origin_tenant_id INT UNSIGNED DEFAULT NULL, '
            . 'approved_by_user_id INT UNSIGNED DEFAULT NULL, '
            . 'approved_at DATETIME DEFAULT NULL, '
            . 'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, '
            . 'updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, '
            . 'UNIQUE KEY uk_module_id (module_id), '
            . 'KEY idx_approval_status (approval_status), '
            . 'KEY idx_commercial_mode (commercial_mode)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $db->exec(
            'CREATE TABLE IF NOT EXISTS ' . moduleTenantEntitlementsTable() . ' ('
            . 'id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, '
            . 'tenant_id INT UNSIGNED NOT NULL, '
            . 'module_id VARCHAR(100) NOT NULL, '
            . "status VARCHAR(20) NOT NULL DEFAULT 'active', "
            . "tier VARCHAR(40) NOT NULL DEFAULT 'free', "
            . "source VARCHAR(40) NOT NULL DEFAULT 'superadmin', "
            . 'granted_by_user_id INT UNSIGNED DEFAULT NULL, '
            . 'expires_at DATETIME DEFAULT NULL, '
            . 'metadata_json LONGTEXT DEFAULT NULL, '
            . 'granted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, '
            . 'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, '
            . 'updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, '
            . 'UNIQUE KEY uq_tenant_module (tenant_id, module_id), '
            . 'KEY idx_module_status (module_id, status), '
            . 'KEY idx_tenant_status (tenant_id, status), '
            . 'KEY idx_expires_at (expires_at)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $db->exec(
            'CREATE TABLE IF NOT EXISTS ' . moduleAccessRequestsTable() . ' ('
            . 'id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, '
            . 'tenant_id INT UNSIGNED NOT NULL, '
            . 'module_id VARCHAR(100) NOT NULL, '
            . "requested_mode VARCHAR(20) NOT NULL DEFAULT 'paid', "
            . "status VARCHAR(20) NOT NULL DEFAULT 'pending', "
            . 'request_notes TEXT DEFAULT NULL, '
            . 'license_ref VARCHAR(80) DEFAULT NULL, '
            . 'license_key_ciphertext LONGTEXT DEFAULT NULL, '
            . 'license_key_iv VARCHAR(255) DEFAULT NULL, '
            . 'license_key_tag VARCHAR(255) DEFAULT NULL, '
            . 'requested_by_user_id INT UNSIGNED DEFAULT NULL, '
            . 'reviewed_by_user_id INT UNSIGNED DEFAULT NULL, '
            . 'review_notes TEXT DEFAULT NULL, '
            . 'metadata_json LONGTEXT DEFAULT NULL, '
            . 'reviewed_at DATETIME DEFAULT NULL, '
            . 'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, '
            . 'updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, '
            . 'UNIQUE KEY uq_tenant_module_request (tenant_id, module_id), '
            . 'KEY idx_access_request_status (status), '
            . 'KEY idx_access_request_module_status (module_id, status)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $ensured = true;
        return true;
    } catch (Throwable $e) {
        $ensured = false;
        return false;
    }
}

/**
 * @return array<string, array<string, mixed>>
 */
function readModuleCatalogRegistry(): array
{
    $cacheKey = '_kernel_module_catalog_cache';
    if (isset($GLOBALS[$cacheKey]) && is_array($GLOBALS[$cacheKey])) {
        return $GLOBALS[$cacheKey];
    }

    if (!moduleControlPlaneEnsureCatalogTables()) {
        $GLOBALS[$cacheKey] = [];
        return [];
    }

    try {
        $stmt = app()->controlDb()->query(
            'SELECT module_id, module_name, approved_version, checksum_sha256, install_path, source, '
            . 'approval_status, commercial_mode, origin_tenant_id, approved_by_user_id, approved_at, '
            . 'created_at, updated_at '
            . 'FROM ' . moduleCatalogTable() . ' '
            . 'ORDER BY module_id ASC'
        );
        $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        $catalog = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $moduleId = trim((string)($row['module_id'] ?? ''));
            if ($moduleId === '') {
                continue;
            }
            $row['module_id'] = $moduleId;
            $catalog[$moduleId] = $row;
        }
        $GLOBALS[$cacheKey] = $catalog;
        return $catalog;
    } catch (Throwable $e) {
        $GLOBALS[$cacheKey] = [];
        return [];
    }
}

function moduleCatalogEntryRefresh(string $moduleId): ?array
{
    if ($moduleId === '' || !moduleControlPlaneEnsureCatalogTables()) {
        return null;
    }

    $previousUnguarded = (bool)kernel_request_context_get('_kernel_db_unguarded', false);
    kernel_request_context_set('_kernel_db_unguarded', true);
    try {
        $stmt = app()->controlDb()->prepare(
            'SELECT module_id, module_name, approved_version, checksum_sha256, install_path, source, '
            . 'approval_status, commercial_mode, origin_tenant_id, approved_by_user_id, approved_at, '
            . 'created_at, updated_at '
            . 'FROM ' . moduleCatalogTable() . ' '
            . 'WHERE module_id = :module_id '
            . 'LIMIT 1'
        );
        $stmt->execute([':module_id' => $moduleId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        $row['module_id'] = $moduleId;
        $cacheKey = '_kernel_module_catalog_cache';
        $catalog = isset($GLOBALS[$cacheKey]) && is_array($GLOBALS[$cacheKey])
            ? $GLOBALS[$cacheKey]
            : [];
        $catalog[$moduleId] = $row;
        $GLOBALS[$cacheKey] = $catalog;

        return $row;
    } catch (Throwable $e) {
        return null;
    } finally {
        kernel_request_context_set('_kernel_db_unguarded', $previousUnguarded);
    }
}

function moduleCatalogEntry(string $moduleId): ?array
{
    $moduleId = trim($moduleId);
    if ($moduleId === '') {
        return null;
    }

    $catalog = readModuleCatalogRegistry();
    if (isset($catalog[$moduleId]) && is_array($catalog[$moduleId])) {
        return $catalog[$moduleId];
    }

    return moduleCatalogEntryRefresh($moduleId);
}

function moduleCatalogIsApproved(string $moduleId): bool
{
    $entry = moduleCatalogEntry($moduleId);
    if (!is_array($entry)) {
        return false;
    }

    return strtolower(trim((string)($entry['approval_status'] ?? ''))) === 'approved';
}

function moduleCatalogCommercialMode(string $moduleId): string
{
    $entry = moduleCatalogEntry($moduleId);
    if (!is_array($entry)) {
        return '';
    }

    return strtolower(trim((string)($entry['commercial_mode'] ?? 'free')));
}

function moduleCatalogModeAllowsSelfService(string $commercialMode): bool
{
    $commercialMode = strtolower(trim($commercialMode));
    return in_array($commercialMode, ['free', 'freemium'], true);
}

function moduleCatalogDefaultEntitlementTier(string $moduleId, ?string $commercialMode = null): string
{
    $tier = strtolower(trim((string)($commercialMode ?? moduleCatalogCommercialMode($moduleId))));
    if ($tier === 'freemium') {
        return 'free';
    }
    if ($tier === '') {
        return 'free';
    }

    return $tier;
}

function upsertModuleCatalogEntry(string $moduleId, array $data): bool
{
    $moduleId = trim($moduleId);
    if ($moduleId === '' || !moduleControlPlaneEnsureCatalogTables()) {
        return false;
    }

    $existing = moduleCatalogEntry($moduleId) ?? [];
    $moduleName = trim((string)($data['module_name'] ?? $existing['module_name'] ?? ''));
    $approvedVersion = trim((string)($data['approved_version'] ?? $existing['approved_version'] ?? ''));
    $checksum = strtolower(trim((string)($data['checksum_sha256'] ?? $existing['checksum_sha256'] ?? '')));
    $installPath = trim((string)($data['install_path'] ?? $existing['install_path'] ?? ''));
    $source = trim((string)($data['source'] ?? $existing['source'] ?? 'admin_install'));
    $approvalStatus = strtolower(trim((string)($data['approval_status'] ?? $existing['approval_status'] ?? 'pending')));
    $commercialMode = strtolower(trim((string)($data['commercial_mode'] ?? $existing['commercial_mode'] ?? 'free')));
    $originTenantId = isset($data['origin_tenant_id']) ? (int)$data['origin_tenant_id'] : (int)($existing['origin_tenant_id'] ?? 0);
    $approvedByUserId = isset($data['approved_by_user_id']) ? (int)$data['approved_by_user_id'] : (int)($existing['approved_by_user_id'] ?? 0);
    $approvedAt = trim((string)($data['approved_at'] ?? $existing['approved_at'] ?? ''));

    if ($approvalStatus === 'approved' && $approvedAt === '') {
        $approvedAt = date('Y-m-d H:i:s');
    }
    if ($commercialMode === '') {
        $commercialMode = 'free';
    }
    if ($source === '') {
        $source = 'admin_install';
    }

    try {
        $stmt = app()->controlDb()->prepare(
            'INSERT INTO ' . moduleCatalogTable() . ' '
            . '(module_id, module_name, approved_version, checksum_sha256, install_path, source, approval_status, '
            . 'commercial_mode, origin_tenant_id, approved_by_user_id, approved_at, created_at, updated_at) '
            . 'VALUES (:module_id, :module_name, :approved_version, :checksum_sha256, :install_path, :source, '
            . ':approval_status, :commercial_mode, :origin_tenant_id, :approved_by_user_id, :approved_at, NOW(), NOW()) '
            . 'ON DUPLICATE KEY UPDATE '
            . 'module_name = VALUES(module_name), '
            . 'approved_version = VALUES(approved_version), '
            . 'checksum_sha256 = VALUES(checksum_sha256), '
            . 'install_path = VALUES(install_path), '
            . 'source = VALUES(source), '
            . 'approval_status = VALUES(approval_status), '
            . 'commercial_mode = VALUES(commercial_mode), '
            . 'origin_tenant_id = VALUES(origin_tenant_id), '
            . 'approved_by_user_id = VALUES(approved_by_user_id), '
            . 'approved_at = VALUES(approved_at), '
            . 'updated_at = NOW()'
        );
        $stmt->execute([
            ':module_id' => $moduleId,
            ':module_name' => $moduleName !== '' ? $moduleName : null,
            ':approved_version' => $approvedVersion !== '' ? $approvedVersion : null,
            ':checksum_sha256' => $checksum !== '' ? $checksum : null,
            ':install_path' => $installPath !== '' ? $installPath : null,
            ':source' => $source,
            ':approval_status' => $approvalStatus,
            ':commercial_mode' => $commercialMode,
            ':origin_tenant_id' => $originTenantId > 0 ? $originTenantId : null,
            ':approved_by_user_id' => $approvedByUserId > 0 ? $approvedByUserId : null,
            ':approved_at' => $approvedAt !== '' ? $approvedAt : null,
        ]);
        invalidateModuleCatalogCache();
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function registerApprovedModuleCatalogInstall(array $manifest, string $installPath, string $zipPath = '', array $options = []): bool
{
    $moduleId = trim((string)($manifest['id'] ?? ''));
    if ($moduleId === '') {
        return false;
    }

    $checksum = '';
    if ($zipPath !== '' && is_file($zipPath)) {
        $hash = @hash_file('sha256', $zipPath);
        $checksum = is_string($hash) ? $hash : '';
    }

    return upsertModuleCatalogEntry($moduleId, [
        'module_name' => (string)($manifest['name'] ?? $moduleId),
        'approved_version' => (string)($manifest['version'] ?? ''),
        'checksum_sha256' => $checksum,
        'install_path' => $installPath,
        'source' => (string)($options['source'] ?? 'admin_install'),
        'approval_status' => 'approved',
        'commercial_mode' => (string)($options['commercial_mode'] ?? 'free'),
        'approved_by_user_id' => isset($options['approved_by_user_id']) ? (int)$options['approved_by_user_id'] : null,
        'approved_at' => (string)($options['approved_at'] ?? date('Y-m-d H:i:s')),
    ]);
}

function updateModuleCatalogApproval(string $moduleId, string $approvalStatus, array $options = []): bool
{
    $moduleId = trim($moduleId);
    $approvalStatus = strtolower(trim($approvalStatus));
    if ($moduleId === '' || !in_array($approvalStatus, ['pending', 'approved', 'rejected'], true)) {
        return false;
    }

    $existing = moduleCatalogEntry($moduleId);
    if (!is_array($existing)) {
        return false;
    }

    $commercialMode = strtolower(trim((string)($options['commercial_mode'] ?? $existing['commercial_mode'] ?? 'free')));
    if ($commercialMode === '') {
        $commercialMode = 'free';
    }

    $ok = upsertModuleCatalogEntry($moduleId, [
        'module_name' => (string)($options['module_name'] ?? $existing['module_name'] ?? $moduleId),
        'approved_version' => (string)($options['approved_version'] ?? $existing['approved_version'] ?? ''),
        'checksum_sha256' => (string)($options['checksum_sha256'] ?? $existing['checksum_sha256'] ?? ''),
        'install_path' => (string)($options['install_path'] ?? $existing['install_path'] ?? ''),
        'source' => (string)($options['source'] ?? $existing['source'] ?? 'admin_install'),
        'approval_status' => $approvalStatus,
        'commercial_mode' => $commercialMode,
        'origin_tenant_id' => isset($options['origin_tenant_id']) ? (int)$options['origin_tenant_id'] : (int)($existing['origin_tenant_id'] ?? 0),
        'approved_by_user_id' => isset($options['approved_by_user_id']) ? (int)$options['approved_by_user_id'] : ($approvalStatus === 'approved' ? (int)($existing['approved_by_user_id'] ?? 0) : null),
        'approved_at' => $approvalStatus === 'approved'
            ? (string)($options['approved_at'] ?? date('Y-m-d H:i:s'))
            : null,
    ]);

    if (!$ok) {
        return false;
    }

    if ($approvalStatus === 'approved') {
        $originTenantId = isset($options['origin_tenant_id']) ? (int)$options['origin_tenant_id'] : (int)($existing['origin_tenant_id'] ?? 0);
        if ($originTenantId > 0) {
            grantModuleEntitlementForTenant($moduleId, $originTenantId, [
                'status' => 'active',
                'tier' => moduleCatalogDefaultEntitlementTier($moduleId, $commercialMode),
                'source' => (string)($options['entitlement_source'] ?? 'catalog_approval'),
                'granted_by_user_id' => isset($options['approved_by_user_id']) ? (int)$options['approved_by_user_id'] : null,
                'metadata' => $options['metadata'] ?? ['via' => 'updateModuleCatalogApproval'],
            ]);
        }
    }

    return true;
}

/**
 * @return array<string, array<string, mixed>>
 */
function readTenantModuleEntitlementsForTenant(int $tenantId): array
{
    if ($tenantId <= 0) {
        return [];
    }

    $previousUnguarded = (bool)kernel_request_context_get('_kernel_db_unguarded', false);
    kernel_request_context_set('_kernel_db_unguarded', true);

    $cacheKey = '_kernel_module_entitlement_cache';
    $cache = $GLOBALS[$cacheKey] ?? [];
    if (is_array($cache) && isset($cache[$tenantId]) && is_array($cache[$tenantId])) {
        kernel_request_context_set('_kernel_db_unguarded', $previousUnguarded);
        return $cache[$tenantId];
    }

    if (!moduleControlPlaneEnsureCatalogTables()) {
        if (!isset($GLOBALS[$cacheKey]) || !is_array($GLOBALS[$cacheKey])) {
            $GLOBALS[$cacheKey] = [];
        }
        $GLOBALS[$cacheKey][$tenantId] = [];
        kernel_request_context_set('_kernel_db_unguarded', $previousUnguarded);
        return [];
    }

    try {
        $stmt = app()->controlDb()->prepare(
            'SELECT tenant_id, module_id, status, tier, source, granted_by_user_id, expires_at, metadata_json, '
            . 'granted_at, created_at, updated_at '
            . 'FROM ' . moduleTenantEntitlementsTable() . ' '
            . 'WHERE tenant_id = :tenant_id '
            . 'ORDER BY module_id ASC'
        );
        $stmt->execute([':tenant_id' => $tenantId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $entitlements = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $moduleId = trim((string)($row['module_id'] ?? ''));
            if ($moduleId === '') {
                continue;
            }
            $metadataRaw = (string)($row['metadata_json'] ?? '');
            $metadata = $metadataRaw !== '' ? json_decode($metadataRaw, true) : null;
            $row['module_id'] = $moduleId;
            $row['metadata'] = is_array($metadata) ? $metadata : [];
            $entitlements[$moduleId] = $row;
        }
        $cache[$tenantId] = $entitlements;
        $GLOBALS[$cacheKey] = $cache;
        return $entitlements;
    } catch (Throwable $e) {
        $cache[$tenantId] = [];
        $GLOBALS[$cacheKey] = $cache;
        return [];
    } finally {
        kernel_request_context_set('_kernel_db_unguarded', $previousUnguarded);
    }
}

function moduleTenantEntitlementRow(string $moduleId, int $tenantId): ?array
{
    $moduleId = trim($moduleId);
    if ($moduleId === '' || $tenantId <= 0) {
        return null;
    }

    $rows = readTenantModuleEntitlementsForTenant($tenantId);
    return $rows[$moduleId] ?? null;
}

function grantModuleEntitlementForTenant(string $moduleId, int $tenantId, array $options = []): bool
{
    $moduleId = trim($moduleId);
    if ($moduleId === '' || $tenantId <= 0 || !moduleControlPlaneEnsureCatalogTables()) {
        return false;
    }

    $existing = moduleTenantEntitlementRow($moduleId, $tenantId) ?? [];
    $catalogTier = moduleCatalogDefaultEntitlementTier($moduleId);
    $status = strtolower(trim((string)($options['status'] ?? $existing['status'] ?? 'active')));
    $tier = trim((string)($options['tier'] ?? $existing['tier'] ?? $catalogTier));
    $source = trim((string)($options['source'] ?? $existing['source'] ?? 'superadmin'));
    $grantedByUserId = isset($options['granted_by_user_id']) ? (int)$options['granted_by_user_id'] : (int)($existing['granted_by_user_id'] ?? 0);
    $expiresAt = trim((string)($options['expires_at'] ?? $existing['expires_at'] ?? ''));
    $metadata = $options['metadata'] ?? ($existing['metadata'] ?? []);
    $metadataJson = json_encode(is_array($metadata) ? $metadata : [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    if ($status === '') {
        $status = 'active';
    }
    if ($tier === '') {
        $tier = 'free';
    }
    if ($source === '') {
        $source = 'superadmin';
    }

    $previousUnguarded = (bool)kernel_request_context_get('_kernel_db_unguarded', false);
    kernel_request_context_set('_kernel_db_unguarded', true);
    try {
        $stmt = app()->controlDb()->prepare(
            'INSERT INTO ' . moduleTenantEntitlementsTable() . ' '
            . '(tenant_id, module_id, status, tier, source, granted_by_user_id, expires_at, metadata_json, granted_at, created_at, updated_at) '
            . 'VALUES (:tenant_id, :module_id, :status, :tier, :source, :granted_by_user_id, :expires_at, :metadata_json, NOW(), NOW(), NOW()) '
            . 'ON DUPLICATE KEY UPDATE '
            . 'status = VALUES(status), '
            . 'tier = VALUES(tier), '
            . 'source = VALUES(source), '
            . 'granted_by_user_id = VALUES(granted_by_user_id), '
            . 'expires_at = VALUES(expires_at), '
            . 'metadata_json = VALUES(metadata_json), '
            . 'granted_at = VALUES(granted_at), '
            . 'updated_at = NOW()'
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':module_id' => $moduleId,
            ':status' => $status,
            ':tier' => $tier,
            ':source' => $source,
            ':granted_by_user_id' => $grantedByUserId > 0 ? $grantedByUserId : null,
            ':expires_at' => $expiresAt !== '' ? $expiresAt : null,
            ':metadata_json' => $metadataJson !== false ? $metadataJson : null,
        ]);
        invalidateModuleCatalogCache();
        return true;
    } catch (Throwable $e) {
        return false;
    } finally {
        kernel_request_context_set('_kernel_db_unguarded', $previousUnguarded);
    }
}

function revokeModuleEntitlementForTenant(string $moduleId, int $tenantId, array $options = []): bool
{
    $catalogTier = moduleCatalogDefaultEntitlementTier($moduleId);

    return grantModuleEntitlementForTenant($moduleId, $tenantId, [
        'status' => 'revoked',
        'tier' => (string)($options['tier'] ?? $catalogTier),
        'source' => (string)($options['source'] ?? 'superadmin'),
        'granted_by_user_id' => isset($options['granted_by_user_id']) ? (int)$options['granted_by_user_id'] : null,
        'expires_at' => (string)($options['expires_at'] ?? ''),
        'metadata' => $options['metadata'] ?? [],
    ]);
}

function ensureSelfServiceModuleEntitlementForTenant(string $moduleId, int $tenantId, array $options = []): bool
{
    if ($tenantId <= 0 || !moduleCatalogIsApproved($moduleId)) {
        return false;
    }

    $status = moduleTenantEntitlementStatus($moduleId, $tenantId);
    if (!empty($status['allowed'])) {
        return true;
    }
    if (($status['entitlement_status'] ?? '') !== 'missing') {
        return false;
    }
    if (!moduleCatalogModeAllowsSelfService((string)($status['commercial_mode'] ?? ''))) {
        return false;
    }

    return grantModuleEntitlementForTenant($moduleId, $tenantId, [
        'status' => 'active',
        'tier' => moduleCatalogDefaultEntitlementTier($moduleId, (string)($status['commercial_mode'] ?? 'free')),
        'source' => (string)($options['source'] ?? 'self_service'),
        'granted_by_user_id' => isset($options['granted_by_user_id']) ? (int)$options['granted_by_user_id'] : null,
        'metadata' => $options['metadata'] ?? [],
    ]);
}

/**
 * @return array<string, mixed>
 */
function moduleTenantEntitlementStatus(string $moduleId, ?int $tenantId = null): array
{
    $moduleId = trim($moduleId);
    $entry = moduleCatalogEntry($moduleId);
    $approvalStatus = strtolower(trim((string)($entry['approval_status'] ?? '')));
    $commercialMode = strtolower(trim((string)($entry['commercial_mode'] ?? '')));
    $multiTenantEnabled = false;
    try {
        $multiTenantEnabled = (bool) app()->config('app.multi_tenant.enabled', false);
    } catch (Throwable $e) {
        $multiTenantEnabled = false;
    }

    $status = [
        'catalog_managed' => is_array($entry),
        'required' => false,
        'allowed' => true,
        'approval_status' => $approvalStatus !== '' ? $approvalStatus : 'unmanaged',
        'commercial_mode' => $commercialMode !== '' ? $commercialMode : 'bundled',
        'entitlement_status' => 'not_required',
        'tier' => null,
        'reason' => '',
    ];

    if (!$multiTenantEnabled || !$status['catalog_managed'] || $approvalStatus !== 'approved') {
        return $status;
    }

    if ($tenantId === null) {
        if (!moduleTenantSettingsModeEnabled()) {
            $status['required'] = true;
            $status['allowed'] = false;
            $status['entitlement_status'] = 'unknown';
            $status['reason'] = 'tenant_context_missing';
            return $status;
        }
        $tenantId = moduleTenantSettingsTenantId();
    }

    $status['required'] = true;
    if ($tenantId === null || $tenantId <= 0) {
        $status['allowed'] = false;
        $status['entitlement_status'] = 'unknown';
        $status['reason'] = 'tenant_context_missing';
        return $status;
    }

    $row = moduleTenantEntitlementRow($moduleId, $tenantId);
    if (!is_array($row)) {
        $status['allowed'] = false;
        $status['entitlement_status'] = 'missing';
        $status['reason'] = 'tenant_entitlement_missing';
        return $status;
    }

    $entitlementState = strtolower(trim((string)($row['status'] ?? 'active')));
    $expiresAt = trim((string)($row['expires_at'] ?? ''));
    if ($expiresAt !== '') {
        $expiresTs = strtotime($expiresAt);
        if ($expiresTs !== false && $expiresTs < time() && in_array($entitlementState, ['active', 'trial'], true)) {
            $entitlementState = 'expired';
        }
    }

    $status['tier'] = trim((string)($row['tier'] ?? ''));
    $status['entitlement_status'] = $entitlementState !== '' ? $entitlementState : 'unknown';
    $status['allowed'] = in_array($entitlementState, ['active', 'trial'], true);
    if (!$status['allowed']) {
        $status['reason'] = 'tenant_entitlement_' . $status['entitlement_status'];
    }

    return $status;
}

function moduleAccessRequestMaskLicenseKey(string $licenseKey): string
{
    $licenseKey = trim($licenseKey);
    if ($licenseKey === '') {
        return '';
    }

    if (strlen($licenseKey) <= 8) {
        return 'key-provided';
    }

    return substr($licenseKey, 0, 4) . '...' . substr($licenseKey, -4);
}

function moduleLicenseActivationSettingsKey(): string
{
    return '_license_activation';
}

function moduleLicenseActivationStateForTenant(string $moduleId, int $tenantId): array
{
    if ($moduleId === '' || $tenantId <= 0) {
        return [];
    }

    $settings = readTenantModuleSettingsForTenant($moduleId, $tenantId);
    $state = $settings[moduleLicenseActivationSettingsKey()] ?? [];
    return is_array($state) ? $state : [];
}

function kernelDefaultModuleLicenseActivationProvider(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    if (!is_array($payload)) {
        throw new RuntimeException('License activation payload must be an array.');
    }

    $moduleId = trim((string)($payload['module_id'] ?? ''));
    $tenantId = (int)($payload['tenant_id'] ?? 0);
    if ($moduleId === '' || $tenantId <= 0) {
        throw new RuntimeException('License activation requires module_id and tenant_id.');
    }

    $licenseKey = trim((string)($payload['license_key'] ?? ''));
    $licenseRef = trim((string)($payload['license_ref'] ?? ''));
    if ($licenseRef === '' && $licenseKey !== '') {
        $licenseRef = moduleAccessRequestMaskLicenseKey($licenseKey);
    }

    $previousState = moduleLicenseActivationStateForTenant($moduleId, $tenantId);
    $state = [
        'ok' => true,
        'status' => 'active',
        'provider' => $providerId !== '' ? $providerId : 'kernel',
        'capability_id' => $capabilityId !== '' ? $capabilityId : 'module.license.activate@1',
        'module_id' => $moduleId,
        'tenant_id' => $tenantId,
        'requested_mode' => trim((string)($payload['requested_mode'] ?? '')),
        'commercial_mode' => trim((string)($payload['commercial_mode'] ?? '')),
        'license_ref' => $licenseRef,
        'has_license_key' => $licenseKey !== '',
        'request_id' => isset($payload['request_id']) ? (int)$payload['request_id'] : null,
        'source' => trim((string)($payload['source'] ?? 'module_access_request')),
        'reviewed_by_user_id' => isset($payload['reviewed_by_user_id']) ? (int)$payload['reviewed_by_user_id'] : null,
        'requested_by_user_id' => isset($payload['requested_by_user_id']) ? (int)$payload['requested_by_user_id'] : null,
        'activated_at' => date('Y-m-d H:i:s'),
        'activation_count' => max(0, (int)($previousState['activation_count'] ?? 0)) + 1,
        'previous_status' => trim((string)($previousState['status'] ?? '')),
        'settings_key' => moduleLicenseActivationSettingsKey(),
    ];

    if (!saveTenantModuleSettingsForTenant($moduleId, $tenantId, [moduleLicenseActivationSettingsKey() => $state])) {
        throw new RuntimeException('Could not persist tenant license activation state.');
    }

    return $state;
}

/**
 * @return array{ciphertext:?string,iv:?string,tag:?string,error:?string}
 */
function moduleControlPlaneEncryptString(string $plaintext): array
{
    $plaintext = trim($plaintext);
    if ($plaintext === '') {
        return ['ciphertext' => null, 'iv' => null, 'tag' => null, 'error' => null];
    }

    try {
        $payload = (new \Ikabud\Kernel\Crypto())->encryptString($plaintext);
        return [
            'ciphertext' => (string)($payload['ciphertext'] ?? ''),
            'iv' => (string)($payload['iv'] ?? ''),
            'tag' => (string)($payload['tag'] ?? ''),
            'error' => null,
        ];
    } catch (Throwable $e) {
        return ['ciphertext' => null, 'iv' => null, 'tag' => null, 'error' => $e->getMessage()];
    }
}

function moduleControlPlaneDecryptString(?string $ciphertext, ?string $iv, ?string $tag): string
{
    $ciphertext = trim((string)$ciphertext);
    $iv = trim((string)$iv);
    $tag = trim((string)$tag);
    if ($ciphertext === '' || $iv === '' || $tag === '') {
        return '';
    }

    try {
        return (new \Ikabud\Kernel\Crypto())->decryptString($ciphertext, $iv, $tag);
    } catch (Throwable $e) {
        return '';
    }
}

/**
 * @return array<int, array<string, mixed>>
 */
function readModuleAccessRequests(): array
{
    $cacheKey = '_kernel_module_access_request_cache';
    if (isset($GLOBALS[$cacheKey]) && is_array($GLOBALS[$cacheKey])) {
        return $GLOBALS[$cacheKey];
    }

    $previousUnguarded = (bool)kernel_request_context_get('_kernel_db_unguarded', false);
    kernel_request_context_set('_kernel_db_unguarded', true);

    if (!moduleControlPlaneEnsureCatalogTables()) {
        $GLOBALS[$cacheKey] = [];
        kernel_request_context_set('_kernel_db_unguarded', $previousUnguarded);
        return [];
    }

    try {
        $stmt = app()->controlDb()->query(
            'SELECT id, tenant_id, module_id, requested_mode, status, request_notes, license_ref, '
            . 'license_key_ciphertext, license_key_iv, license_key_tag, requested_by_user_id, '
            . 'reviewed_by_user_id, review_notes, metadata_json, reviewed_at, created_at, updated_at '
            . 'FROM ' . moduleAccessRequestsTable() . ' '
            . 'ORDER BY '
            . "CASE status WHEN 'pending' THEN 0 WHEN 'approved' THEN 1 WHEN 'rejected' THEN 2 ELSE 3 END, "
            . 'updated_at DESC, created_at DESC'
        );
        $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        $requests = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $row['id'] = (int)($row['id'] ?? 0);
            $row['tenant_id'] = (int)($row['tenant_id'] ?? 0);
            $row['requested_by_user_id'] = isset($row['requested_by_user_id']) ? (int)$row['requested_by_user_id'] : null;
            $row['reviewed_by_user_id'] = isset($row['reviewed_by_user_id']) ? (int)$row['reviewed_by_user_id'] : null;

            $metadataRaw = (string)($row['metadata_json'] ?? '');
            $metadata = $metadataRaw !== '' ? json_decode($metadataRaw, true) : null;
            $row['metadata'] = is_array($metadata) ? $metadata : [];
            $row['has_license_key'] = trim((string)($row['license_key_ciphertext'] ?? '')) !== '';

            $requests[] = $row;
        }

        $GLOBALS[$cacheKey] = $requests;
        return $requests;
    } catch (Throwable $e) {
        $GLOBALS[$cacheKey] = [];
        return [];
    } finally {
        kernel_request_context_set('_kernel_db_unguarded', $previousUnguarded);
    }
}

function moduleAccessRequestById(int $requestId): ?array
{
    if ($requestId <= 0) {
        return null;
    }

    foreach (readModuleAccessRequests() as $request) {
        if ((int)($request['id'] ?? 0) === $requestId) {
            return $request;
        }
    }

    return null;
}

function moduleLatestAccessRequestForTenant(string $moduleId, int $tenantId): ?array
{
    $moduleId = trim($moduleId);
    if ($moduleId === '' || $tenantId <= 0) {
        return null;
    }

    foreach (readModuleAccessRequests() as $request) {
        if ((string)($request['module_id'] ?? '') !== $moduleId) {
            continue;
        }
        if ((int)($request['tenant_id'] ?? 0) !== $tenantId) {
            continue;
        }

        return $request;
    }

    return null;
}

function moduleAccessRequestLicenseKey(array $request): string
{
    return moduleControlPlaneDecryptString(
        (string)($request['license_key_ciphertext'] ?? ''),
        (string)($request['license_key_iv'] ?? ''),
        (string)($request['license_key_tag'] ?? '')
    );
}

/**
 * @return array<string, mixed>
 */
function submitModuleAccessRequestForTenant(string $moduleId, int $tenantId, array $options = []): array
{
    $moduleId = trim($moduleId);
    if ($moduleId === '' || $tenantId <= 0) {
        return ['ok' => false, 'error' => 'module_id and tenant_id are required'];
    }
    if (!moduleControlPlaneEnsureCatalogTables()) {
        return ['ok' => false, 'error' => 'Control-plane access request tables are unavailable'];
    }

    $catalogEntry = moduleCatalogEntry($moduleId);
    if (!is_array($catalogEntry) || strtolower(trim((string)($catalogEntry['approval_status'] ?? 'pending'))) !== 'approved') {
        return ['ok' => false, 'error' => 'Only approved catalog modules can be requested'];
    }

    $existing = moduleLatestAccessRequestForTenant($moduleId, $tenantId) ?? [];
    $requestedMode = strtolower(trim((string)($options['requested_mode'] ?? $catalogEntry['commercial_mode'] ?? $existing['requested_mode'] ?? 'paid')));
    if ($requestedMode === '') {
        $requestedMode = 'paid';
    }

    $requestNotes = trim((string)($options['request_notes'] ?? $existing['request_notes'] ?? ''));
    $licenseKey = trim((string)($options['license_key'] ?? ''));
    $licenseRef = trim((string)($options['license_ref'] ?? $existing['license_ref'] ?? ''));
    $requestedByUserId = isset($options['requested_by_user_id']) ? (int)$options['requested_by_user_id'] : (int)($existing['requested_by_user_id'] ?? 0);
    $metadata = $options['metadata'] ?? ($existing['metadata'] ?? []);
    $encrypted = [
        'ciphertext' => (string)($existing['license_key_ciphertext'] ?? ''),
        'iv' => (string)($existing['license_key_iv'] ?? ''),
        'tag' => (string)($existing['license_key_tag'] ?? ''),
        'error' => null,
    ];

    if ($licenseKey !== '') {
        $encrypted = moduleControlPlaneEncryptString($licenseKey);
        if (($encrypted['error'] ?? null) !== null) {
            return ['ok' => false, 'error' => 'Could not store the provided license key securely: ' . (string)$encrypted['error']];
        }
        if ($licenseRef === '') {
            $licenseRef = moduleAccessRequestMaskLicenseKey($licenseKey);
        }
    }

    $metadataJson = json_encode(is_array($metadata) ? $metadata : [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $previousUnguarded = (bool)kernel_request_context_get('_kernel_db_unguarded', false);
    kernel_request_context_set('_kernel_db_unguarded', true);

    try {
        $stmt = app()->controlDb()->prepare(
            'INSERT INTO ' . moduleAccessRequestsTable() . ' '
            . '(tenant_id, module_id, requested_mode, status, request_notes, license_ref, license_key_ciphertext, '
            . 'license_key_iv, license_key_tag, requested_by_user_id, reviewed_by_user_id, review_notes, metadata_json, reviewed_at, created_at, updated_at) '
            . 'VALUES (:tenant_id, :module_id, :requested_mode, :status, :request_notes, :license_ref, :license_key_ciphertext, '
            . ':license_key_iv, :license_key_tag, :requested_by_user_id, NULL, NULL, :metadata_json, NULL, NOW(), NOW()) '
            . 'ON DUPLICATE KEY UPDATE '
            . 'requested_mode = VALUES(requested_mode), '
            . 'status = VALUES(status), '
            . 'request_notes = VALUES(request_notes), '
            . 'license_ref = VALUES(license_ref), '
            . 'license_key_ciphertext = VALUES(license_key_ciphertext), '
            . 'license_key_iv = VALUES(license_key_iv), '
            . 'license_key_tag = VALUES(license_key_tag), '
            . 'requested_by_user_id = VALUES(requested_by_user_id), '
            . 'reviewed_by_user_id = NULL, '
            . 'review_notes = NULL, '
            . 'metadata_json = VALUES(metadata_json), '
            . 'reviewed_at = NULL, '
            . 'updated_at = NOW()'
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':module_id' => $moduleId,
            ':requested_mode' => $requestedMode,
            ':status' => 'pending',
            ':request_notes' => $requestNotes !== '' ? $requestNotes : null,
            ':license_ref' => $licenseRef !== '' ? $licenseRef : null,
            ':license_key_ciphertext' => ($encrypted['ciphertext'] ?? '') !== '' ? $encrypted['ciphertext'] : null,
            ':license_key_iv' => ($encrypted['iv'] ?? '') !== '' ? $encrypted['iv'] : null,
            ':license_key_tag' => ($encrypted['tag'] ?? '') !== '' ? $encrypted['tag'] : null,
            ':requested_by_user_id' => $requestedByUserId > 0 ? $requestedByUserId : null,
            ':metadata_json' => $metadataJson !== false ? $metadataJson : null,
        ]);
        invalidateModuleCatalogCache();

        return [
            'ok' => true,
            'request' => moduleLatestAccessRequestForTenant($moduleId, $tenantId),
        ];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    } finally {
        kernel_request_context_set('_kernel_db_unguarded', $previousUnguarded);
    }
}

/**
 * @return array<string, mixed>
 */
function invokeModuleLicenseActivation(array $payload, array $options = []): array
{
    $capabilityId = trim((string)($options['capability_id'] ?? 'module.license.activate@1'));
    if ($capabilityId === '') {
        return ['ok' => true, 'status' => 'skipped', 'reason' => 'missing_capability_id'];
    }

    $resolvedCapabilityId = app()->capabilities()->resolve($capabilityId);
    if (!app()->capabilities()->has($resolvedCapabilityId)) {
        return ['ok' => true, 'status' => 'skipped', 'reason' => 'capability_not_registered', 'capability_id' => $resolvedCapabilityId];
    }

    $callOptions = ['mode' => (string)($options['mode'] ?? 'first')];
    $provider = trim((string)($options['provider'] ?? ''));
    if ($provider !== '') {
        $providers = app()->capabilities()->providers($resolvedCapabilityId);
        $providerFound = false;
        foreach ($providers as $providerRow) {
            if ((string)($providerRow['provider'] ?? '') === $provider) {
                $providerFound = true;
                break;
            }
        }
        if (!$providerFound) {
            return ['ok' => true, 'status' => 'skipped', 'reason' => 'provider_not_registered', 'provider' => $provider, 'capability_id' => $resolvedCapabilityId];
        }
        $callOptions['provider'] = $provider;
    }

    try {
        $result = app()->cap()->call($resolvedCapabilityId, $payload, $callOptions);
        if (is_array($result)) {
            $normalized = array_merge([
                'ok' => true,
                'capability_id' => $resolvedCapabilityId,
                'invoke_status' => 'invoked',
            ], $result);
            if (($normalized['ok'] ?? true) === false) {
                return [
                    'ok' => false,
                    'status' => 'error',
                    'capability_id' => $resolvedCapabilityId,
                    'result' => $result,
                    'error' => (string)($normalized['error'] ?? 'Capability provider returned failure.'),
                ];
            }
            if (!isset($normalized['status']) || trim((string)$normalized['status']) === '') {
                $normalized['status'] = 'invoked';
            }
            return $normalized;
        }

        return ['ok' => true, 'status' => 'invoked', 'capability_id' => $resolvedCapabilityId, 'result' => $result];
    } catch (Throwable $e) {
        if (!empty($options['strict'])) {
            throw $e;
        }

        return [
            'ok' => false,
            'status' => 'error',
            'capability_id' => $resolvedCapabilityId,
            'error' => $e->getMessage(),
        ];
    }
}

/**
 * @return array<string, mixed>
 */
function reviewModuleAccessRequest(int $requestId, string $status, array $options = []): array
{
    $request = moduleAccessRequestById($requestId);
    $status = strtolower(trim($status));
    if (!is_array($request)) {
        return ['ok' => false, 'error' => 'Access request not found'];
    }
    if (!in_array($status, ['approved', 'rejected'], true)) {
        return ['ok' => false, 'error' => 'Invalid access request status'];
    }

    $moduleId = trim((string)($request['module_id'] ?? ''));
    $tenantId = (int)($request['tenant_id'] ?? 0);
    if ($moduleId === '' || $tenantId <= 0) {
        return ['ok' => false, 'error' => 'Access request is missing tenant or module data'];
    }

    $requestedMode = strtolower(trim((string)($request['requested_mode'] ?? moduleCatalogCommercialMode($moduleId))));
    if ($requestedMode === '') {
        $requestedMode = 'paid';
    }

    $reviewedByUserId = isset($options['reviewed_by_user_id']) ? (int)$options['reviewed_by_user_id'] : 0;
    $reviewNotes = trim((string)($options['review_notes'] ?? ''));
    $metadata = $request['metadata'] ?? [];
    if (!is_array($metadata)) {
        $metadata = [];
    }

    $activationResult = ['ok' => true, 'status' => 'skipped', 'reason' => 'not_applicable'];
    if ($status === 'approved') {
        $entitlementOk = grantModuleEntitlementForTenant($moduleId, $tenantId, [
            'status' => (string)($options['entitlement_status'] ?? 'active'),
            'tier' => (string)($options['tier'] ?? $requestedMode),
            'source' => (string)($options['source'] ?? 'access_request_review'),
            'granted_by_user_id' => $reviewedByUserId > 0 ? $reviewedByUserId : null,
            'metadata' => ['request_id' => $requestId, 'via' => 'reviewModuleAccessRequest'],
        ]);
        if (!$entitlementOk) {
            return ['ok' => false, 'error' => 'Failed to grant tenant entitlement'];
        }

        $activationPayload = [
            'module_id' => $moduleId,
            'tenant_id' => $tenantId,
            'requested_mode' => $requestedMode,
            'commercial_mode' => moduleCatalogCommercialMode($moduleId) ?: $requestedMode,
            'request_id' => $requestId,
            'request_notes' => (string)($request['request_notes'] ?? ''),
            'license_key' => moduleAccessRequestLicenseKey($request),
            'license_ref' => (string)($request['license_ref'] ?? ''),
            'requested_by_user_id' => (int)($request['requested_by_user_id'] ?? 0),
            'reviewed_by_user_id' => $reviewedByUserId,
            'source' => 'module_access_request',
        ];
        $activationResult = invokeModuleLicenseActivation($activationPayload, [
            'strict' => (bool)($options['strict_license_activation'] ?? false),
            'provider' => (string)($options['license_provider'] ?? ''),
        ]);
        $metadata['license_activation'] = $activationResult;
    }

    $metadata['last_review'] = [
        'status' => $status,
        'review_notes' => $reviewNotes,
        'reviewed_by_user_id' => $reviewedByUserId > 0 ? $reviewedByUserId : null,
        'reviewed_at' => date('Y-m-d H:i:s'),
    ];
    $metadataJson = json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $previousUnguarded = (bool)kernel_request_context_get('_kernel_db_unguarded', false);
    kernel_request_context_set('_kernel_db_unguarded', true);
    try {
        $stmt = app()->controlDb()->prepare(
            'UPDATE ' . moduleAccessRequestsTable() . ' '
            . 'SET status = :status, reviewed_by_user_id = :reviewed_by_user_id, review_notes = :review_notes, '
            . 'metadata_json = :metadata_json, reviewed_at = NOW(), updated_at = NOW() '
            . 'WHERE id = :id'
        );
        $stmt->execute([
            ':status' => $status,
            ':reviewed_by_user_id' => $reviewedByUserId > 0 ? $reviewedByUserId : null,
            ':review_notes' => $reviewNotes !== '' ? $reviewNotes : null,
            ':metadata_json' => $metadataJson !== false ? $metadataJson : null,
            ':id' => $requestId,
        ]);
        invalidateModuleCatalogCache();

        return [
            'ok' => true,
            'request' => moduleAccessRequestById($requestId),
            'activation' => $activationResult,
            'entitlement' => moduleTenantEntitlementStatus($moduleId, $tenantId),
        ];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    } finally {
        kernel_request_context_set('_kernel_db_unguarded', $previousUnguarded);
    }
}

