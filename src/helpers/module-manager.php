<?php

declare(strict_types=1);

// ─── Paths ────────────────────────────────────────────────────────────────

function modulesPath(): string
{
    return BASE_PATH . '/modules';
}

function modulePathForId(string $moduleId): ?string
{
    $moduleId = trim($moduleId);
    if ($moduleId === '') {
        return null;
    }

    $modules = discoverModules();
    if (!isset($modules[$moduleId])) {
        return null;
    }

    $path = trim((string)($modules[$moduleId]['_path'] ?? ''));
    return $path !== '' ? $path : null;
}

function moduleManifestPathForId(string $moduleId): ?string
{
    $modulePath = modulePathForId($moduleId);
    if ($modulePath === null) {
        return null;
    }

    $manifestPath = rtrim($modulePath, '/') . '/module.json';
    return is_file($manifestPath) ? $manifestPath : null;
}

/**
 * Export a module's owned tables to a SQL file (INSERT statements) in storage/module-exports/.
 * Returns ['ok'=>true,'dir'=>'...','files'=>string[]] or ['ok'=>false,'error'=>'...']
 */
function exportModuleOwnedTables(string $moduleId, array $manifest, ?string $exportDir = null): array
{
    $tables = $manifest['owns_tables'] ?? [];
    if (!is_array($tables) || empty($tables)) {
        return ['ok' => true, 'dir' => $exportDir ?: '', 'files' => []];
    }

    $stamp = date('Ymd-His');
    $base = STORAGE_PATH . '/module-exports';
    $dir = $exportDir;
    if ($dir === null || $dir === '') {
        $dir = $base . '/' . $moduleId . '-' . $stamp;
    } elseif (!str_starts_with($dir, '/')) {
        $dir = BASE_PATH . '/' . ltrim($dir, '/');
    }

    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    $pdo = app()->db();
    $files = [];

    foreach ($tables as $table) {
        if (!is_string($table) || trim($table) === '') {
            continue;
        }
        $table = trim($table);

        try {
            $exists = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($table))->fetchColumn();
            if ($exists === false) {
                continue;
            }

            $colsStmt = $pdo->query("SHOW COLUMNS FROM `{$table}`");
            $cols = $colsStmt ? $colsStmt->fetchAll(PDO::FETCH_ASSOC) : [];
            $colNames = [];
            foreach ($cols as $c) {
                if (!is_array($c) || empty($c['Field'])) continue;
                $colNames[] = (string)$c['Field'];
            }
            if (empty($colNames)) {
                continue;
            }

            $outPath = rtrim($dir, '/') . '/' . $table . '.sql';
            $fh = fopen($outPath, 'wb');
            if ($fh === false) {
                return ['ok' => false, 'error' => "Cannot write export file: {$outPath}"];
            }

            fwrite($fh, "-- Export: {$table}\n");
            fwrite($fh, "-- Generated: " . date('c') . "\n\n");

            $select = $pdo->query("SELECT * FROM `{$table}`");
            if ($select) {
                while ($row = $select->fetch(PDO::FETCH_ASSOC)) {
                    if (!is_array($row)) continue;
                    $colsSql = implode(', ', array_map(fn($c) => '`' . str_replace('`', '``', $c) . '`', $colNames));
                    $vals = [];
                    foreach ($colNames as $c) {
                        $v = $row[$c] ?? null;
                        $vals[] = $v === null ? 'NULL' : $pdo->quote((string)$v);
                    }
                    $valsSql = implode(', ', $vals);
                    fwrite($fh, "INSERT INTO `{$table}` ({$colsSql}) VALUES ({$valsSql});\n");
                }
            }

            fclose($fh);
            $files[] = $outPath;
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => "Export failed for table '{$table}': {$e->getMessage()}"];
        }
    }

    return ['ok' => true, 'dir' => $dir, 'files' => $files];
}

function moduleRegistryPath(): string
{
    return STORAGE_PATH . '/modules.json';
}

/**
 * Return declared auth cookie names without triggering module enablement or tenant-setting resolution.
 * This is used from app()->user(), so it must stay bootstrap-safe and recursion-free.
 *
 * @return array<int, string>
 */
function declaredModuleAuthCookieNames(): array
{
    static $names = null;
    if (is_array($names)) {
        return $names;
    }

    $ttl = max(0, (int)($_ENV['MODULE_AUTH_COOKIE_CACHE_TTL'] ?? 300));
    if ($ttl > 0) {
        $cached = app()->cache()->get('kernel_bootstrap', 'module_auth_cookies:v2');
        if (is_array($cached) && isset($cached['names']) && is_array($cached['names'])) {
            $names = array_values(array_filter($cached['names'], fn($name) => is_string($name) && $name !== ''));
            return $names;
        }
    }

    $names = [];
    $dir = modulesPath();
    if (!is_dir($dir)) {
        return $names;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            static function (SplFileInfo $current): bool {
                $name = $current->getFilename();
                if ($name === '.' || $name === '..') {
                    return false;
                }
                if ($current->isDir() && preg_match('/\.bak_\d{8}_\d{6}$/', $name)) {
                    return false;
                }
                return true;
            }
        ),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile() || $file->getFilename() !== 'module.json') {
            continue;
        }

        $manifest = json_decode((string)file_get_contents($file->getPathname()), true);
        if (!is_array($manifest)) {
            continue;
        }

        $cookie = trim((string)($manifest['auth_cookie'] ?? ''));
        if ($cookie !== '' && !in_array($cookie, $names, true)) {
            $names[] = $cookie;
        }
    }

    sort($names);
    if ($ttl > 0) {
        app()->cache()->set('kernel_bootstrap', 'module_auth_cookies:v2', ['names' => $names], $ttl);
    }
    return $names;
}

function moduleTenantSettingsTable(): string
{
    return 'tenant_module_settings';
}

function moduleTenantSettingsModeEnabled(): bool
{
    try {
        if (!(bool) app()->config('app.multi_tenant.enabled', false)) {
            return false;
        }

        // In CLI, only enable tenant-scoped settings if a host is explicitly set.
        if (PHP_SAPI === 'cli' && empty($_SERVER['HTTP_HOST'])) {
            return false;
        }

        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function moduleTenantSettingsTenantId(): ?int
{
    if (!moduleTenantSettingsModeEnabled()) {
        return null;
    }

    static $resolvingTenantId = false;

    try {
        $tenant = app()->tenant();
        $tenantId = $tenant->current();
        if ($tenantId === null && !$resolvingTenantId) {
            $resolvingTenantId = true;
            try {
                $tenantId = $tenant->resolve();
            } finally {
                $resolvingTenantId = false;
            }
        }
        if ($tenantId === null || $tenantId <= 0) {
            return null;
        }
        return (int) $tenantId;
    } catch (Throwable $e) {
        return null;
    }
}

function moduleTenantSettingsCanWriteExplicitTenant(int $tenantId): bool
{
    if ($tenantId <= 0) {
        return false;
    }

    $activeContext = kernel_request_context_get('_activeModuleContext');
    if (!is_object($activeContext) || !method_exists($activeContext, 'moduleId')) {
        return true;
    }

    $moduleId = (string)$activeContext->moduleId();
    $currentTenantId = moduleTenantSettingsTenantId();
    if ($currentTenantId !== null && $currentTenantId > 0 && $currentTenantId === $tenantId) {
        return true;
    }

    write_log('Blocked cross-tenant tenant_module_settings write from module context', 'warning', [
        'module' => $moduleId,
        'current_tenant_id' => $currentTenantId,
        'target_tenant_id' => $tenantId,
    ]);

    return false;
}

function moduleTenantSettingsCanReadExplicitTenant(string $moduleId, int $tenantId): bool
{
    if ($tenantId <= 0 || $moduleId === '') {
        return false;
    }

    $activeContext = kernel_request_context_get('_activeModuleContext');
    if (!is_object($activeContext) || !method_exists($activeContext, 'moduleId')) {
        return true;
    }

    $callerModuleId = (string)$activeContext->moduleId();
    $currentTenantId = moduleTenantSettingsTenantId();
    if ($currentTenantId !== null && $currentTenantId > 0 && $currentTenantId === $tenantId) {
        return true;
    }

    $allowedCrossTenantReaders = [
        'guidance' => ['ecommerce'],
    ];

    if (isset($allowedCrossTenantReaders[$callerModuleId]) && in_array($moduleId, $allowedCrossTenantReaders[$callerModuleId], true)) {
        return true;
    }

    write_log('Blocked cross-tenant tenant_module_settings read from module context', 'warning', [
        'module' => $callerModuleId,
        'target_module' => $moduleId,
        'current_tenant_id' => $currentTenantId,
        'target_tenant_id' => $tenantId,
    ]);

    return false;
}

function moduleTenantSettingsTableExists(PDO $db): bool
{
    try {
        $stmt = $db->query("SHOW TABLES LIKE '" . moduleTenantSettingsTable() . "'");
        return $stmt && $stmt->fetchColumn() !== false;
    } catch (Throwable $e) {
        return false;
    }
}

function moduleTenantSettingsEnsureTable(PDO $db): bool
{
    if (moduleTenantSettingsTableExists($db)) {
        return true;
    }

    try {
        $db->exec(
            'CREATE TABLE IF NOT EXISTS ' . moduleTenantSettingsTable() . ' ('
            . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, '
            . 'tenant_id INT UNSIGNED NOT NULL, '
            . 'module_id VARCHAR(100) NOT NULL, '
            . 'setting_key VARCHAR(120) NOT NULL, '
            . 'setting_value JSON NULL, '
            . 'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, '
            . 'updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, '
            . 'UNIQUE KEY uq_tenant_module_setting (tenant_id, module_id, setting_key), '
            . 'KEY idx_tenant_module (tenant_id, module_id), '
            . 'KEY idx_module_key (module_id, setting_key)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * @return array<string, mixed>
 */
function readTenantModuleSettings(string $moduleId): array
{
    $tenantId = moduleTenantSettingsTenantId();
    if ($tenantId === null || $moduleId === '') {
        return [];
    }

    // Use per-request cache if available (populated by preloadAllTenantModuleSettings)
    $cacheKey = '_tenant_module_settings_cache';
    if (isset($GLOBALS[$cacheKey]) && is_array($GLOBALS[$cacheKey])) {
        return $GLOBALS[$cacheKey][$moduleId] ?? [];
    }

    // Single-module fallback (rarely used after preload is in place)
    return _readTenantModuleSettingsSingle($moduleId, $tenantId);
}

/**
 * Preload ALL module settings for the current tenant in a single DB query.
 * Populates a per-request cache used by readTenantModuleSettings().
 */
function preloadAllTenantModuleSettings(): void
{
    $cacheKey = '_tenant_module_settings_cache';
    if (kernel_request_context_has($cacheKey)) {
        return; // Already loaded
    }

    $tenantId = moduleTenantSettingsTenantId();
    if ($tenantId === null) {
        kernel_request_context_set($cacheKey, []);
        return;
    }

    \Ikabud\Kernel\Database\KernelPDO::kernelEscalationEnter();
    try {
        $db = app()->db();
        if (!moduleTenantSettingsEnsureTable($db)) {
            kernel_request_context_set($cacheKey, []);
            return;
        }

        $stmt = $db->prepare(
            'SELECT module_id, setting_key, setting_value '
            . 'FROM ' . moduleTenantSettingsTable() . ' '
            . 'WHERE tenant_id = :tid'
        );
        $stmt->execute([':tid' => $tenantId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $cache = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $mid = trim((string)($row['module_id'] ?? ''));
            $key = trim((string)($row['setting_key'] ?? ''));
            if ($mid === '' || $key === '') continue;
            $raw = (string)($row['setting_value'] ?? 'null');
            $decoded = json_decode($raw, true);
            if (!isset($cache[$mid])) $cache[$mid] = [];
            $cache[$mid][$key] = (json_last_error() === JSON_ERROR_NONE) ? $decoded : $raw;
        }

        kernel_request_context_set($cacheKey, $cache);
    } catch (Throwable $e) {
        kernel_request_context_set($cacheKey, []);
    } finally {
        \Ikabud\Kernel\Database\KernelPDO::kernelEscalationLeave();
    }
}

/**
 * Invalidate the per-request module settings cache (after a save).
 */
function invalidateTenantModuleSettingsCache(): void
{
    kernel_request_context_delete('_tenant_module_settings_cache');
}

/**
 * Single-module DB read (no cache).
 * @internal
 */
function _readTenantModuleSettingsSingle(string $moduleId, int $tenantId, ?PDO $dbOverride = null): array
{
    \Ikabud\Kernel\Database\KernelPDO::kernelEscalationEnter();
    try {
        $db = $dbOverride ?? app()->db();
        if (!moduleTenantSettingsEnsureTable($db)) {
            return [];
        }

        $stmt = $db->prepare(
            'SELECT setting_key, setting_value '
            . 'FROM ' . moduleTenantSettingsTable() . ' '
            . 'WHERE tenant_id = :tid AND module_id = :mid'
        );
        $stmt->execute([
            ':tid' => $tenantId,
            ':mid' => $moduleId,
        ]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $settings = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $key = trim((string)($row['setting_key'] ?? ''));
            if ($key === '') continue;
            $raw = (string)($row['setting_value'] ?? 'null');
            $decoded = json_decode($raw, true);
            $settings[$key] = (json_last_error() === JSON_ERROR_NONE) ? $decoded : $raw;
        }

        return $settings;
    } catch (Throwable $e) {
        return [];
    } finally {
        \Ikabud\Kernel\Database\KernelPDO::kernelEscalationLeave();
    }
}

/**
 * @param array<string, mixed> $settings
 */
function saveTenantModuleSettings(string $moduleId, array $settings): bool
{
    $tenantId = moduleTenantSettingsTenantId();
    if ($tenantId === null || $moduleId === '' || empty($settings)) {
        return false;
    }

    // Kernel-level operation: bypass ModuleDB enforcement so any module
    // can persist its own settings without declaring tenant_module_settings.
    \Ikabud\Kernel\Database\KernelPDO::kernelEscalationEnter();
    try {
        $db = app()->db();
        if (!moduleTenantSettingsEnsureTable($db)) {
            return false;
        }

        $sql = 'INSERT INTO ' . moduleTenantSettingsTable() . ' '
            . '(tenant_id, module_id, setting_key, setting_value, created_at, updated_at) '
            . 'VALUES (:tid, :mid, :skey, :sval, NOW(), NOW()) '
            . 'ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()';
        $stmt = $db->prepare($sql);

        foreach ($settings as $key => $value) {
            $skey = trim((string)$key);
            if ($skey === '') {
                continue;
            }
            $stmt->execute([
                ':tid' => $tenantId,
                ':mid' => $moduleId,
                ':skey' => $skey,
                ':sval' => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]);
        }

        return true;
    } catch (Throwable $e) {
        return false;
    } finally {
        \Ikabud\Kernel\Database\KernelPDO::kernelEscalationLeave();
        invalidateTenantModuleSettingsCache();
    }
}

// ─── Superadmin cross-tenant read/write (explicit tenant_id) ──────────────

/**
 * Read module settings for an explicit tenant ID (superadmin use).
 * Unlike readTenantModuleSettings() this does NOT use the request's
 * implicit tenant context or the per-request cache.
 */
function readTenantModuleSettingsForTenant(string $moduleId, int $tenantId): array
{
    if ($moduleId === '' || $tenantId <= 0) {
        return [];
    }
    if (!moduleTenantSettingsCanReadExplicitTenant($moduleId, $tenantId)) {
        return [];
    }
    $db = app()->dbForTenant($tenantId);
    if ($db === null) {
        return [];
    }
    return _readTenantModuleSettingsSingle($moduleId, $tenantId, $db);
}

/**
 * Save module settings for an explicit tenant ID (superadmin use).
 */
function saveTenantModuleSettingsForTenant(string $moduleId, int $tenantId, array $settings): bool
{
    if ($tenantId <= 0 || $moduleId === '' || empty($settings)) {
        return false;
    }

    if (!moduleTenantSettingsCanWriteExplicitTenant($tenantId)) {
        return false;
    }

    \Ikabud\Kernel\Database\KernelPDO::kernelEscalationEnter();
    try {
        $db = app()->dbForTenant($tenantId);
        if ($db === null || !moduleTenantSettingsEnsureTable($db)) {
            return false;
        }

        $sql = 'INSERT INTO ' . moduleTenantSettingsTable() . ' '
            . '(tenant_id, module_id, setting_key, setting_value, created_at, updated_at) '
            . 'VALUES (:tid, :mid, :skey, :sval, NOW(), NOW()) '
            . 'ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()';
        $stmt = $db->prepare($sql);

        foreach ($settings as $key => $value) {
            $skey = trim((string)$key);
            if ($skey === '') {
                continue;
            }
            $stmt->execute([
                ':tid' => $tenantId,
                ':mid' => $moduleId,
                ':skey' => $skey,
                ':sval' => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]);
        }

        return true;
    } catch (Throwable $e) {
        return false;
    } finally {
        \Ikabud\Kernel\Database\KernelPDO::kernelEscalationLeave();
    }
}

/**
 * Get merged module settings for an explicit tenant ID (superadmin use).
 * Merges lifecycle-only keys from global registry with tenant DB overrides.
 */
function getModuleSettingsForTenant(string $moduleId, int $tenantId): array
{
    $registry = readModuleRegistry();
    $global = $registry[$moduleId]['settings'] ?? [];
    if (!is_array($global)) {
        $global = [];
    }

    $lifecycleKeys = ['allow_kernel_admin'];
    $safeGlobal = array_intersect_key($global, array_flip($lifecycleKeys));

    $tenant = readTenantModuleSettingsForTenant($moduleId, $tenantId);
    foreach (array_keys($tenant) as $tenantKey) {
        if (is_string($tenantKey) && str_starts_with($tenantKey, '_')) {
            unset($tenant[$tenantKey]);
        }
    }
    return array_merge($safeGlobal, $tenant);
}


require_once __DIR__ . '/module-catalog.php';
require_once __DIR__ . '/module-registry.php';
require_once __DIR__ . '/module-routes.php';

// ─── Discovery ────────────────────────────────────────────────────────────

/**
 * Discover ALL modules in modules/ directory (regardless of enabled state).
 * @return array<string, array<string, mixed>>
 */
function discoverModules(): array
{
    // Per-request cache: avoid repeated fs scans + DB queries
    if (isset($GLOBALS['_kernel_discovered_modules']) && is_array($GLOBALS['_kernel_discovered_modules'])) {
        return $GLOBALS['_kernel_discovered_modules'];
    }

    $dir = modulesPath();
    if (!is_dir($dir)) {
        return [];
    }

    $result = [];
    $manifestPaths = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            static function (SplFileInfo $current): bool {
                $name = $current->getFilename();
                if ($name === '.' || $name === '..') {
                    return false;
                }
                if ($current->isDir() && preg_match('/\.bak_\d{8}_\d{6}$/', $name)) {
                    return false;
                }
                return true;
            }
        ),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile() || $file->getFilename() !== 'module.json') {
            continue;
        }
        $manifestPaths[] = $file->getPathname();
    }

    sort($manifestPaths);

    foreach ($manifestPaths as $manifestPath) {
        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        if (!is_array($manifest) || empty($manifest['id'])) {
            continue;
        }

        $moduleId = (string)$manifest['id'];
        if (isset($result[$moduleId])) {
            if (function_exists('write_log')) {
                write_log('Duplicate module id discovered: ' . $moduleId . ' at ' . $manifestPath . ' (keeping first occurrence)', 'warning');
            }
            continue;
        }

        $manifest['_path'] = dirname($manifestPath);
        $manifest['_enabled'] = isModuleEnabled($moduleId);
        $result[$moduleId] = $manifest;

        // Register table ownership for ReadContractRegistry
        $owns = is_array($manifest['owns_tables'] ?? null) ? $manifest['owns_tables'] : [];
        $coOwns = is_array($manifest['co_owns_tables'] ?? null) ? $manifest['co_owns_tables'] : [];
        foreach (array_merge($owns, $coOwns) as $tableName) {
            if (is_string($tableName) && trim($tableName) !== '') {
                \Ikabud\Kernel\Contracts\ReadContractRegistry::getInstance()->registerTableOwner(trim($tableName), $moduleId);
            }
        }
    }

    $GLOBALS['_kernel_discovered_modules'] = $result;
    return $result;
}

/**
 * Get only enabled modules.
 * @return array<string, array<string, mixed>>
 */
function getEnabledModules(): array
{
    static $cached = null;
    if (is_array($cached)) {
        return $cached;
    }

    resetSkippedModules();

    $enabled = array_filter(discoverModules(), fn($m) => !empty($m['_enabled']));

    // Parse entity authorities and contracts across all enabled modules 
    // strictly during kernel boot.
    app()->entityAuthority()->reset();
    app()->syncContracts()->reset();
    foreach ($enabled as $id => $mod) {
        if (!empty($mod['entities']) && is_array($mod['entities'])) {
            foreach ($mod['entities'] as $eType => $eDef) {
                if (!empty($eDef['authority']) && $eDef['authority'] === true) {
                    app()->entityAuthority()->registerAuthority($eType, $id, $eDef);
                }
                if (!empty($eDef['sync_contracts']) && is_array($eDef['sync_contracts'])) {
                    foreach ($eDef['sync_contracts'] as $operation => $handlerStr) {
                        app()->syncContracts()->registerContract($eType, $id, $operation, $handlerStr);
                    }
                }
            }
        }
    }
    $declaredCapabilities = [];
    foreach ($enabled as $module) {
        $check = validateModuleCapabilities($module);
        if (empty($check['ok']) || empty($check['exposes']) || !is_array($check['exposes'])) {
            continue;
        }
        foreach ($check['exposes'] as $expose) {
            $capId = is_array($expose) ? (string)($expose['id'] ?? '') : '';
            if ($capId !== '') {
                $declaredCapabilities[$capId] = true;
            }
        }
    }
    // Request-time safety net: if a module declares capability dependencies that
    // are not currently satisfiable, skip loading it rather than breaking the kernel.
    $safe = [];
    foreach ($enabled as $id => $m) {
        // Attach registry settings (from storage/modules.json) to manifest for request-time policy decisions.
        $m['_settings'] = getModuleSettings((string)($m['id'] ?? $id));
        $m['_entitlement'] = moduleTenantEntitlementStatus((string)($m['id'] ?? $id));

        if (!empty($m['_entitlement']['required']) && empty($m['_entitlement']['allowed'])) {
            recordSkippedModule($id, 'tenant_entitlement_required', [
                'approval_status' => (string)($m['_entitlement']['approval_status'] ?? 'unmanaged'),
                'commercial_mode' => (string)($m['_entitlement']['commercial_mode'] ?? 'bundled'),
                'entitlement_status' => (string)($m['_entitlement']['entitlement_status'] ?? 'unknown'),
            ]);
            continue;
        }

        // Kernel version compatibility check
        $requiresKernel = isset($m['requires_kernel']) ? trim((string)$m['requires_kernel']) : '';
        if ($requiresKernel !== '') {
            $currentKernel = \Ikabud\Kernel\App::KERNEL_VERSION;
            if (version_compare($currentKernel, $requiresKernel, '<')) {
                recordSkippedModule($id, 'requires_kernel', [
                    'requires_kernel' => $requiresKernel,
                    'current_kernel' => $currentKernel,
                ]);
                write_log(
                    "Module '{$id}' requires kernel >= {$requiresKernel} but current is {$currentKernel} — skipped",
                    'warning',
                    ['module' => $id, 'requires_kernel' => $requiresKernel, 'current_kernel' => $currentKernel]
                );
                continue;
            }
        }

        $check = validateModuleCapabilities($m);
        if (!$check['ok']) {
            recordSkippedModule($id, 'invalid_capability_manifest', [
                'error' => (string)($check['error'] ?? 'unknown'),
            ]);
            write_log(
                "Module '{$id}' capability manifest invalid — skipped: " . ($check['error'] ?? 'unknown'),
                'warning',
                ['module' => $id]
            );
            continue;
        }

        $entityContextCheck = validateModuleEntityContexts($m);
        if (!$entityContextCheck['ok']) {
            recordSkippedModule($id, 'invalid_entity_context_manifest', [
                'error' => (string)($entityContextCheck['error'] ?? 'unknown'),
            ]);
            write_log(
                "Module '{$id}' entity context manifest invalid — skipped: " . ($entityContextCheck['error'] ?? 'unknown'),
                'warning',
                ['module' => $id]
            );
            continue;
        }

        $deps = $check['depends'] ?? [];
        if (!empty($deps)) {
            $missing = [];
            foreach ($deps as $capId) {
                if (!isset($declaredCapabilities[$capId]) && !app()->capabilities()->has($capId)) {
                    $missing[] = $capId;
                }
            }
            if (!empty($missing)) {
                recordSkippedModule($id, 'missing_capability_providers', [
                    'missing' => $missing,
                ]);
                write_log(
                    "Module '{$id}' missing capability providers — skipped",
                    'warning',
                    ['module' => $id, 'missing' => $missing]
                );
                continue;
            }
        }

        $safe[$id] = $m;
    }

    // Register read contracts and deprecated reads for enabled modules
    kernelRegisterModuleReadContracts($safe);

    $cached = $safe;
    return $cached;
}

/**
 * Return kernel migration files that are safe to run against tenant databases.
 * Control-plane schema must stay in the control DB only.
 *
 * @return array<int, string>
 */

require_once __DIR__ . '/module-migrations.php';

function validateModuleCapabilities(array $manifest): array
{
    $caps = $manifest['capabilities'] ?? null;
    if ($caps === null) {
        return ['ok' => true, 'exposes' => [], 'depends' => [], 'policy' => []];
    }
    if (!is_array($caps)) {
        return ['ok' => false, 'error' => 'capabilities must be an object'];
    }

    $exposes = $caps['exposes'] ?? [];
    $depends = $caps['depends'] ?? [];
    $policy = $caps['policy'] ?? [];

    if (!is_array($exposes) || !is_array($depends)) {
        return ['ok' => false, 'error' => 'capabilities.exposes and capabilities.depends must be arrays'];
    }

    foreach ($depends as $d) {
        if (!is_string($d) || !isValidCapabilityId($d)) {
            return ['ok' => false, 'error' => 'Invalid capabilities.depends entry'];
        }
    }

    foreach ($exposes as $e) {
        if (!is_array($e)) {
            return ['ok' => false, 'error' => 'capabilities.exposes entries must be objects'];
        }
        $id = $e['id'] ?? '';
        if (!is_string($id) || !isValidCapabilityId($id)) {
            return ['ok' => false, 'error' => 'Invalid capability expose id'];
        }
        $modes = $e['modes'] ?? ['first'];
        if (!is_array($modes)) {
            return ['ok' => false, 'error' => 'Capability expose modes must be an array'];
        }
        foreach ($modes as $mode) {
            if (!is_string($mode) || !in_array(strtolower($mode), ['first', 'pipeline', 'fanout'], true)) {
                return ['ok' => false, 'error' => 'Invalid capability mode'];
            }
        }
        $priority = $e['priority'] ?? 10;
        if (!is_int($priority) && !is_numeric($priority)) {
            return ['ok' => false, 'error' => 'Capability expose priority must be numeric'];
        }
        if (isset($e['schema']) && !is_array($e['schema'])) {
            return ['ok' => false, 'error' => 'Capability expose schema must be an object'];
        }
        if (isset($e['schema']) && is_array($e['schema'])) {
            $schema = $e['schema'];
            if (isset($schema['input']) && !is_array($schema['input'])) {
                return ['ok' => false, 'error' => 'Capability expose schema.input must be an object'];
            }
            if (isset($schema['output']) && !is_array($schema['output'])) {
                return ['ok' => false, 'error' => 'Capability expose schema.output must be an object'];
            }
        }
    }

    // Optional policy schema
    if ($policy !== [] && !is_array($policy)) {
        return ['ok' => false, 'error' => 'capabilities.policy must be an object'];
    }
    if (is_array($policy) && $policy !== []) {
        $default = $policy['default'] ?? [];
        $perCap = $policy['capabilities'] ?? [];

        if ($default !== [] && !is_array($default)) {
            return ['ok' => false, 'error' => 'capabilities.policy.default must be an object'];
        }
        if ($perCap !== [] && !is_array($perCap)) {
            return ['ok' => false, 'error' => 'capabilities.policy.capabilities must be an object'];
        }

        $validateProviderList = function ($v): bool {
            if ($v === null) return true;
            if (!is_array($v)) return false;
            foreach ($v as $p) {
                if (!is_string($p) || trim($p) === '') return false;
            }
            return true;
        };

        $validateCallerList = function ($v): bool {
            if ($v === null) return true;
            if (!is_array($v)) return false;
            foreach ($v as $c) {
                if (!is_string($c) || trim($c) === '') return false;
            }
            return true;
        };

        if (is_array($default)) {
            if (!$validateProviderList($default['allow_providers'] ?? null)) {
                return ['ok' => false, 'error' => 'capabilities.policy.default.allow_providers must be an array of strings'];
            }
            if (!$validateProviderList($default['deny_providers'] ?? null)) {
                return ['ok' => false, 'error' => 'capabilities.policy.default.deny_providers must be an array of strings'];
            }
            if (!$validateCallerList($default['allow_callers'] ?? null)) {
                return ['ok' => false, 'error' => 'capabilities.policy.default.allow_callers must be an array of strings'];
            }
            if (!$validateCallerList($default['deny_callers'] ?? null)) {
                return ['ok' => false, 'error' => 'capabilities.policy.default.deny_callers must be an array of strings'];
            }
        }

        if (is_array($perCap)) {
            foreach ($perCap as $capId => $rule) {
                if (!is_string($capId) || !isValidCapabilityId($capId)) {
                    return ['ok' => false, 'error' => 'capabilities.policy.capabilities keys must be valid capability ids'];
                }
                if (!is_array($rule)) {
                    return ['ok' => false, 'error' => 'capabilities.policy.capabilities entries must be objects'];
                }
                if (!$validateProviderList($rule['allow_providers'] ?? null)) {
                    return ['ok' => false, 'error' => 'capabilities.policy.capabilities.allow_providers must be an array of strings'];
                }
                if (!$validateProviderList($rule['deny_providers'] ?? null)) {
                    return ['ok' => false, 'error' => 'capabilities.policy.capabilities.deny_providers must be an array of strings'];
                }
                if (!$validateCallerList($rule['allow_callers'] ?? null)) {
                    return ['ok' => false, 'error' => 'capabilities.policy.capabilities.allow_callers must be an array of strings'];
                }
                if (!$validateCallerList($rule['deny_callers'] ?? null)) {
                    return ['ok' => false, 'error' => 'capabilities.policy.capabilities.deny_callers must be an array of strings'];
                }
            }
        }
    }

    return ['ok' => true, 'exposes' => $exposes, 'depends' => array_values($depends), 'policy' => is_array($policy) ? $policy : []];
}

/**
 * Validate the optional `auth_owned` block in a module.json manifest.
 *
 * The block declares that the module owns its own users table and opts
 * the module into the platform-wide trusted-provisioning + admin-recovery
 * pipelines (see kernel/Services/TenantProvisioner.php and
 * kernelHandleApiTenantAdminPasswordPush() in src/http/admin-handlers.php).
 *
 * Shape:
 *   {
 *     "users_table":                "bakeshop_users",          // required
 *     "username_column":            "username",                  // optional, default 'username'
 *     "email_column":               "email",                     // optional, default 'email'
 *     "password_column":            "password_hash",             // optional, default 'password_hash'
 *     "name_column":                "full_name",                 // optional, default 'full_name'
 *     "active_column":              "is_active",                 // optional, default 'is_active'
 *     "deleted_column":             null,                        // optional, default null
 *     "admin_roles":                ["admin"],                   // required, non-empty
 *     "default_admin_role":         "admin",                     // optional, default first admin_roles entry
 *     "requires_named_admin_on_provision": true,                 // optional, default false
 *     "blocked_password_hashes":    ["..."],                     // optional, sentinel hashes the auth provider must reject
 *     "touch_updated_at":           true                         // optional, default true (adds updated_at = NOW())
 *   }
 */
function validateAuthOwnedSpec(mixed $raw, bool $strictReservedRoles = false): array
{
    if (!is_array($raw)) {
        return ['ok' => false, 'error' => 'module.json field auth_owned must be an object'];
    }

    $identRegex = '/^[A-Za-z_][A-Za-z0-9_]*$/';
    $reservedKernelRoles = ['superadmin'];

    $usersTable = (string)($raw['users_table'] ?? '');
    if ($usersTable === '' || !preg_match($identRegex, $usersTable)) {
        return ['ok' => false, 'error' => 'module.json field auth_owned.users_table must be a valid identifier'];
    }

    foreach (['username_column', 'email_column', 'password_column', 'name_column', 'active_column', 'deleted_column'] as $colField) {
        if (!array_key_exists($colField, $raw) || $raw[$colField] === null) {
            continue;
        }
        if (!is_string($raw[$colField]) || !preg_match($identRegex, $raw[$colField])) {
            return ['ok' => false, 'error' => "module.json field auth_owned.{$colField} must be null or a valid column identifier"];
        }
    }

    $adminRoles = $raw['admin_roles'] ?? null;
    if (!is_array($adminRoles) || $adminRoles === []) {
        return ['ok' => false, 'error' => 'module.json field auth_owned.admin_roles must be a non-empty array of role strings'];
    }
    foreach ($adminRoles as $role) {
        if (!is_string($role) || trim($role) === '') {
            return ['ok' => false, 'error' => 'module.json field auth_owned.admin_roles must contain non-empty strings'];
        }

        $normalizedRole = trim($role);
        if ($strictReservedRoles && in_array($normalizedRole, $reservedKernelRoles, true)) {
            return ['ok' => false, 'error' => 'module.json field auth_owned.admin_roles must not contain reserved kernel roles'];
        }
    }

    if (array_key_exists('default_admin_role', $raw)) {
        if (!is_string($raw['default_admin_role']) || trim($raw['default_admin_role']) === '') {
            return ['ok' => false, 'error' => 'module.json field auth_owned.default_admin_role must be a non-empty string when provided'];
        }

        $defaultRole = trim($raw['default_admin_role']);
        if ($strictReservedRoles && in_array($defaultRole, $reservedKernelRoles, true)) {
            return ['ok' => false, 'error' => 'module.json field auth_owned.default_admin_role must not use a reserved kernel role'];
        }
    }

    if (array_key_exists('blocked_password_hashes', $raw)) {
        if (!is_array($raw['blocked_password_hashes'])) {
            return ['ok' => false, 'error' => 'module.json field auth_owned.blocked_password_hashes must be an array of strings'];
        }
        foreach ($raw['blocked_password_hashes'] as $hash) {
            if (!is_string($hash) || $hash === '') {
                return ['ok' => false, 'error' => 'module.json field auth_owned.blocked_password_hashes must contain non-empty strings'];
            }
        }
    }

    return ['ok' => true];
}

/**
 * Normalize an auth_owned spec into a deterministic shape with defaults applied.
 * The returned array uses only validated identifiers, so callers can safely
 * interpolate the values into prepared SQL fragments.
 */
function kernelNormalizeAuthOwnedSpec(string $moduleId, array $raw): array
{
    $adminRoles = array_values(array_filter(array_map(
        static fn($r) => is_string($r) ? trim($r) : '',
        $raw['admin_roles'] ?? []
    ), static fn($r) => $r !== ''));

    if ($adminRoles === []) {
        $adminRoles = ['admin'];
    }

    $defaultRole = isset($raw['default_admin_role']) && is_string($raw['default_admin_role']) && trim($raw['default_admin_role']) !== ''
        ? trim($raw['default_admin_role'])
        : $adminRoles[0];

    $blocked = [];
    if (isset($raw['blocked_password_hashes']) && is_array($raw['blocked_password_hashes'])) {
        foreach ($raw['blocked_password_hashes'] as $hash) {
            if (is_string($hash) && $hash !== '') {
                $blocked[] = $hash;
            }
        }
    }

    return [
        'module_id'                          => $moduleId,
        'users_table'                        => (string)$raw['users_table'],
        'username_column'                    => (string)($raw['username_column'] ?? 'username'),
        'email_column'                       => (string)($raw['email_column'] ?? 'email'),
        'password_column'                    => (string)($raw['password_column'] ?? 'password_hash'),
        'name_column'                        => (string)($raw['name_column'] ?? 'full_name'),
        'active_column'                      => isset($raw['active_column']) && $raw['active_column'] !== null ? (string)$raw['active_column'] : 'is_active',
        'deleted_column'                     => isset($raw['deleted_column']) && $raw['deleted_column'] !== null ? (string)$raw['deleted_column'] : null,
        'admin_roles'                        => $adminRoles,
        'default_admin_role'                 => $defaultRole,
        'requires_named_admin_on_provision'  => !empty($raw['requires_named_admin_on_provision']),
        'blocked_password_hashes'            => $blocked,
        'touch_updated_at'                   => array_key_exists('touch_updated_at', $raw) ? (bool)$raw['touch_updated_at'] : true,
    ];
}

/**
 * Discover all enabled modules that declare an `auth_owned` block and return
 * normalized specs keyed by module id.
 *
 * @return array<string, array<string, mixed>>
 */
function kernelAuthOwnedModules(): array
{
    static $cached = null;
    if (is_array($cached)) {
        return $cached;
    }

    $result = [];
    foreach (getEnabledModules() as $moduleId => $manifest) {
        if (!is_array($manifest)) {
            continue;
        }
        $raw = $manifest['auth_owned'] ?? null;
        if (!is_array($raw)) {
            continue;
        }
        $check = validateAuthOwnedSpec($raw);
        if (empty($check['ok'])) {
            if (function_exists('write_log')) {
                write_log(
                    'auth_owned manifest ignored for module ' . $moduleId . ': ' . (string)($check['error'] ?? 'invalid'),
                    'warning'
                );
            }
            continue;
        }
        $result[(string)$moduleId] = kernelNormalizeAuthOwnedSpec((string)$moduleId, $raw);
    }

    $cached = $result;
    return $result;
}

/**
 * Reset the kernelAuthOwnedModules() per-request cache. Intended for tests
 * that toggle module enablement mid-request.
 */
function kernelAuthOwnedModulesResetCache(): void
{
    static $resetClosure = null;
    // Reset the static $cached var inside kernelAuthOwnedModules() by
    // re-declaring it via reflection-free trick: call a sentinel that
    // re-initializes — but PHP has no native reset for function statics.
    // Tests should boot a fresh process; this no-op is kept for clarity.
    unset($resetClosure);
}

/**
 * Look up the auth_owned spec for a single module id, or null if the module
 * is not enabled or does not declare auth_owned.
 */
function kernelAuthOwnedSpecForModule(string $moduleId): ?array
{
    $all = kernelAuthOwnedModules();
    return $all[$moduleId] ?? null;
}

/**
 * Validate optional entity_contexts block in a module manifest.
 * Returns:
 *  - ['ok' => true, 'definitions' => array, 'extensions' => array, 'bindings' => array, 'capability_metadata' => array]
 *  - ['ok' => false, 'error' => '...']
 */
function validateModuleEntityContexts(array $manifest): array
{
    $raw = $manifest['entity_contexts'] ?? null;
    if ($raw === null) {
        return ['ok' => true, 'definitions' => [], 'extensions' => [], 'bindings' => [], 'capability_metadata' => []];
    }

    if (!is_array($raw)) {
        return ['ok' => false, 'error' => 'entity_contexts must be an object'];
    }

    $definitions = $raw['definitions'] ?? [];
    $extensions = $raw['extensions'] ?? [];
    $bindings = $raw['bindings'] ?? [];
    $capabilityMetadata = $raw['capability_metadata'] ?? [];

    foreach ([
        'entity_contexts.definitions' => $definitions,
        'entity_contexts.extensions' => $extensions,
        'entity_contexts.bindings' => $bindings,
        'entity_contexts.capability_metadata' => $capabilityMetadata,
    ] as $label => $value) {
        if (!is_array($value)) {
            return ['ok' => false, 'error' => $label . ' must be an array'];
        }
    }

    foreach ($definitions as $index => $definition) {
        if (!is_array($definition)) {
            return ['ok' => false, 'error' => "entity_contexts.definitions[{$index}] must be an object"];
        }

        $contextId = trim((string)($definition['id'] ?? ''));
        if (!isValidEntityContextId($contextId)) {
            return ['ok' => false, 'error' => "entity_contexts.definitions[{$index}].id must be a valid context id"];
        }
        if (isset($definition['label']) && !is_string($definition['label'])) {
            return ['ok' => false, 'error' => "entity_contexts.definitions[{$index}].label must be a string"];
        }
        if (isset($definition['priority']) && !is_numeric($definition['priority'])) {
            return ['ok' => false, 'error' => "entity_contexts.definitions[{$index}].priority must be numeric"];
        }
        if (isset($definition['meta']) && !is_array($definition['meta'])) {
            return ['ok' => false, 'error' => "entity_contexts.definitions[{$index}].meta must be an object"];
        }
        if (isset($definition['capabilities'])) {
            if (!is_array($definition['capabilities'])) {
                return ['ok' => false, 'error' => "entity_contexts.definitions[{$index}].capabilities must be an array"];
            }
            foreach ($definition['capabilities'] as $capabilityIndex => $capability) {
                if (is_string($capability) && isValidEntityCapabilityName($capability)) {
                    continue;
                }
                if (is_array($capability) && isValidEntityCapabilityName((string)($capability['id'] ?? ''))) {
                    continue;
                }

                return ['ok' => false, 'error' => "entity_contexts.definitions[{$index}].capabilities[{$capabilityIndex}] must reference a valid capability id"];
            }
        }
    }

    foreach ($extensions as $index => $extension) {
        if (!is_array($extension)) {
            return ['ok' => false, 'error' => "entity_contexts.extensions[{$index}] must be an object"];
        }

        $contextId = trim((string)($extension['context'] ?? ''));
        if (!isValidEntityContextId($contextId)) {
            return ['ok' => false, 'error' => "entity_contexts.extensions[{$index}].context must be a valid context id"];
        }
        if (isset($extension['label']) && !is_string($extension['label'])) {
            return ['ok' => false, 'error' => "entity_contexts.extensions[{$index}].label must be a string"];
        }
        if (isset($extension['priority']) && !is_numeric($extension['priority'])) {
            return ['ok' => false, 'error' => "entity_contexts.extensions[{$index}].priority must be numeric"];
        }
        if (isset($extension['meta']) && !is_array($extension['meta'])) {
            return ['ok' => false, 'error' => "entity_contexts.extensions[{$index}].meta must be an object"];
        }
        if (isset($extension['capabilities'])) {
            if (!is_array($extension['capabilities'])) {
                return ['ok' => false, 'error' => "entity_contexts.extensions[{$index}].capabilities must be an array"];
            }
            foreach ($extension['capabilities'] as $capabilityIndex => $capability) {
                if (is_string($capability) && isValidEntityCapabilityName($capability)) {
                    continue;
                }
                if (is_array($capability) && isValidEntityCapabilityName((string)($capability['id'] ?? ''))) {
                    continue;
                }

                return ['ok' => false, 'error' => "entity_contexts.extensions[{$index}].capabilities[{$capabilityIndex}] must reference a valid capability id"];
            }
        }
    }

    foreach ($bindings as $index => $binding) {
        if (!is_array($binding)) {
            return ['ok' => false, 'error' => "entity_contexts.bindings[{$index}] must be an object"];
        }

        $entityType = trim((string)($binding['entity_type'] ?? ''));
        if (!preg_match('/^[a-z][a-z0-9_\-]*$/', $entityType)) {
            return ['ok' => false, 'error' => "entity_contexts.bindings[{$index}].entity_type must be a valid entity type id"];
        }

        $base = trim((string)($binding['base'] ?? ''));
        if ($base !== '' && !isValidEntityContextId($base)) {
            return ['ok' => false, 'error' => "entity_contexts.bindings[{$index}].base must be a valid context id"];
        }
        if (isset($binding['priority']) && !is_numeric($binding['priority'])) {
            return ['ok' => false, 'error' => "entity_contexts.bindings[{$index}].priority must be numeric"];
        }
        if (isset($binding['overrides']) && !is_array($binding['overrides'])) {
            return ['ok' => false, 'error' => "entity_contexts.bindings[{$index}].overrides must be an object"];
        }
        if (isset($binding['extensions'])) {
            if (!is_array($binding['extensions'])) {
                return ['ok' => false, 'error' => "entity_contexts.bindings[{$index}].extensions must be an array"];
            }
            foreach ($binding['extensions'] as $extensionIndex => $extension) {
                if (!is_string($extension) || !isValidEntityContextId($extension)) {
                    return ['ok' => false, 'error' => "entity_contexts.bindings[{$index}].extensions[{$extensionIndex}] must be a valid context id"];
                }
            }
        }
    }

    foreach ($capabilityMetadata as $index => $metadata) {
        if (!is_array($metadata)) {
            return ['ok' => false, 'error' => "entity_contexts.capability_metadata[{$index}] must be an object"];
        }

        $capabilityId = trim((string)($metadata['id'] ?? ''));
        if (!isValidEntityCapabilityName($capabilityId)) {
            return ['ok' => false, 'error' => "entity_contexts.capability_metadata[{$index}].id must be a valid capability id"];
        }
        if (isset($metadata['label']) && !is_string($metadata['label'])) {
            return ['ok' => false, 'error' => "entity_contexts.capability_metadata[{$index}].label must be a string"];
        }
        if (isset($metadata['block']) && !is_string($metadata['block'])) {
            return ['ok' => false, 'error' => "entity_contexts.capability_metadata[{$index}].block must be a string"];
        }
        if (isset($metadata['priority']) && !is_numeric($metadata['priority'])) {
            return ['ok' => false, 'error' => "entity_contexts.capability_metadata[{$index}].priority must be numeric"];
        }
        if (isset($metadata['meta']) && !is_array($metadata['meta'])) {
            return ['ok' => false, 'error' => "entity_contexts.capability_metadata[{$index}].meta must be an object"];
        }
        if (!isset($metadata['customizer'])) {
            continue;
        }
        if (!is_array($metadata['customizer'])) {
            return ['ok' => false, 'error' => "entity_contexts.capability_metadata[{$index}].customizer must be an object"];
        }

        $customizer = $metadata['customizer'];
        if (isset($customizer['section']) && !is_array($customizer['section'])) {
            return ['ok' => false, 'error' => "entity_contexts.capability_metadata[{$index}].customizer.section must be an object"];
        }
        if (isset($customizer['fields'])) {
            if (!is_array($customizer['fields'])) {
                return ['ok' => false, 'error' => "entity_contexts.capability_metadata[{$index}].customizer.fields must be an array"];
            }
            foreach ($customizer['fields'] as $fieldIndex => $field) {
                if (!is_array($field)) {
                    return ['ok' => false, 'error' => "entity_contexts.capability_metadata[{$index}].customizer.fields[{$fieldIndex}] must be an object"];
                }
                if (!is_string($field['name'] ?? null) || trim((string)$field['name']) === '') {
                    return ['ok' => false, 'error' => "entity_contexts.capability_metadata[{$index}].customizer.fields[{$fieldIndex}].name must be a non-empty string"];
                }
                if (isset($field['label']) && !is_string($field['label'])) {
                    return ['ok' => false, 'error' => "entity_contexts.capability_metadata[{$index}].customizer.fields[{$fieldIndex}].label must be a string"];
                }
                if (isset($field['type']) && !is_string($field['type'])) {
                    return ['ok' => false, 'error' => "entity_contexts.capability_metadata[{$index}].customizer.fields[{$fieldIndex}].type must be a string"];
                }
                if (isset($field['priority']) && !is_numeric($field['priority'])) {
                    return ['ok' => false, 'error' => "entity_contexts.capability_metadata[{$index}].customizer.fields[{$fieldIndex}].priority must be numeric"];
                }
                if (isset($field['options']) && !is_array($field['options'])) {
                    return ['ok' => false, 'error' => "entity_contexts.capability_metadata[{$index}].customizer.fields[{$fieldIndex}].options must be an array"];
                }
                if (isset($field['visibility']) && !is_array($field['visibility'])) {
                    return ['ok' => false, 'error' => "entity_contexts.capability_metadata[{$index}].customizer.fields[{$fieldIndex}].visibility must be an object"];
                }
            }
        }
    }

    return [
        'ok' => true,
        'definitions' => array_values($definitions),
        'extensions' => array_values($extensions),
        'bindings' => array_values($bindings),
        'capability_metadata' => array_values($capabilityMetadata),
    ];
}

function isValidCapabilityId(string $capId): bool
{
    // contract.id@major (major is integer)
    // Segments: lowercase letter/digit/underscore; must start with a letter.
    return (bool) preg_match('/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)*@\d+$/', $capId);
}

function isValidEntityContextId(string $contextId): bool
{
    return (bool)preg_match('/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)*$/', trim($contextId));
}

function isValidEntityCapabilityName(string $capabilityId): bool
{
    return (bool)preg_match('/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)*$/', trim($capabilityId));
}

// ─── Module Context Accessor ──────────────────────────────────────────────

/** @var array<int, \Ikabud\Kernel\Contracts\ModuleContext|null> */
$_moduleContextStack = [];

/** @var array<string, \Ikabud\Kernel\Contracts\ModuleContext|null> */
$_moduleContextCache = [];

/** @var array<string, bool> */
$_loadedModuleHelpers = [];

/** @var array<string, bool> */
$_loadedModuleHandlers = [];

/** @var array<string, array<string, callable>> */
$_moduleRouteCallableRegistry = [];

/**
 * Clear cached module contexts so the next module() call rebuilds them
 * with a fresh PDO handle. Call after app()->reconnectDb() or reconnectDbForTenant().
 */
function invalidateModuleContextCache(?string $moduleId = null): void
{
    global $_moduleContextCache;
    if ($moduleId !== null) {
        unset($_moduleContextCache[trim($moduleId)]);
    } else {
        $_moduleContextCache = [];
    }
}

function moduleContextFor(string $moduleId): ?\Ikabud\Kernel\Contracts\ModuleContext
{
    global $_moduleContextCache;

    $moduleId = trim($moduleId);
    if ($moduleId === '') {
        return null;
    }

    if (array_key_exists($moduleId, $_moduleContextCache)) {
        return $_moduleContextCache[$moduleId];
    }

    $modules = discoverModules();
    if (!isset($modules[$moduleId]) || !is_array($modules[$moduleId])) {
        $_moduleContextCache[$moduleId] = null;
        return null;
    }

    $_moduleContextCache[$moduleId] = buildModuleContext($moduleId, $modules[$moduleId]);
    return $_moduleContextCache[$moduleId];
}

function moduleCurrentId(): ?string
{
    $ctx = module();
    return $ctx ? $ctx->moduleId() : null;
}

function modulePushContext(string|\Ikabud\Kernel\Contracts\ModuleContext $module): ?\Ikabud\Kernel\Contracts\ModuleContext
{
    $ctx = is_string($module) ? moduleContextFor($module) : $module;
    if (!$ctx) {
        return null;
    }

    kernel_request_context_push('_moduleContextStack', module());
    kernel_request_context_set('_activeModuleContext', $ctx);
    return $ctx;
}

function modulePopContext(): void
{
    $previous = kernel_request_context_pop('_moduleContextStack');
    kernel_request_context_set('_activeModuleContext', $previous);
}

function moduleWithContext(string|\Ikabud\Kernel\Contracts\ModuleContext $module, callable $callback): mixed
{
    $ctx = modulePushContext($module);
    try {
        return $callback($ctx);
    } finally {
        if ($ctx) {
            modulePopContext();
        }
    }
}

function loadModuleHelpers(array $module): void
{
    global $_loadedModuleHelpers;

    $moduleId = trim((string)($module['id'] ?? ''));
    if ($moduleId === '' || isset($_loadedModuleHelpers[$moduleId])) {
        return;
    }

    // Service modules declare capabilities but run externally — no PHP helpers to load.
    $moduleType = trim((string)($module['type'] ?? 'php-module'));
    if ($moduleType === 'service-module') {
        $_loadedModuleHelpers[$moduleId] = true;
        return;
    }

    $helpersFile = (string)($module['_path'] ?? '') . '/helpers.php';
    if (is_file($helpersFile)) {
        moduleWithContext($moduleId, static function () use ($helpersFile): void {
            require_once $helpersFile;
        });
    }

    // Auto-register auth-owned module user tables in the kernel auth table map.
    // Modules declaring auth_owned.users_table no longer need to call
    // app()->registerAuthTable() manually during bootstrap.
    $authOwned = $module['auth_owned'] ?? null;
    if (is_array($authOwned) && !empty($authOwned['users_table'])) {
        $usersTable = trim((string)$authOwned['users_table']);
        if ($usersTable !== '' && function_exists('app')) {
            try {
                app()->registerAuthTable($moduleId, $usersTable);
            } catch (\Throwable $e) {
                if (function_exists('write_log')) {
                    write_log("Failed to auto-register auth table for module '{$moduleId}': " . $e->getMessage(), 'warning');
                }
            }
        }
    }

    $_loadedModuleHelpers[$moduleId] = true;
}

function resolveModuleRouteCallable(string $moduleId, string $handlerKey, array $manifest): ?callable
{
    global $_loadedModuleHandlers, $_moduleRouteCallableRegistry;

    if (isset($_moduleRouteCallableRegistry[$moduleId][$handlerKey]) && is_callable($_moduleRouteCallableRegistry[$moduleId][$handlerKey])) {
        return $_moduleRouteCallableRegistry[$moduleId][$handlerKey];
    }

    $handlersFile = (string)($manifest['_path'] ?? '') . '/handlers.php';
    if (!is_file($handlersFile)) {
        return null;
    }

    if (!isset($_loadedModuleHandlers[$moduleId])) {
        $fnsBefore = get_defined_functions()['user'] ?? [];
        moduleWithContext($moduleId, static function () use ($handlersFile): void {
            require_once $handlersFile;
        });
        $fnsAfter = get_defined_functions()['user'] ?? [];
        $newFns = array_diff($fnsAfter, $fnsBefore);
        if (!empty($newFns)) {
            static $functionOwners = [];
            foreach ($newFns as $fn) {
                if (isset($functionOwners[$fn])) {
                    write_log(
                        "Function namespace collision: '{$fn}' already owned by module '{$functionOwners[$fn]}', "
                        . "module '{$moduleId}' attempted to redefine it",
                        'error',
                        ['module' => $moduleId, 'function' => $fn, 'owner' => $functionOwners[$fn]]
                    );
                } else {
                    $functionOwners[$fn] = $moduleId;
                }
            }
        }
        $_loadedModuleHandlers[$moduleId] = true;
    }

    $modulePrefix = preg_replace('/[^a-z0-9]+/i', '_', $moduleId);
    $handlersExportFn = $modulePrefix . '_route_handlers';
    if (function_exists($handlersExportFn)) {
        $resolvedHandlers = $handlersExportFn();
        if (is_array($resolvedHandlers)) {
            foreach ($resolvedHandlers as $key => $callable) {
                if (is_string($key) && is_callable($callable)) {
                    $_moduleRouteCallableRegistry[$moduleId][$key] = $callable;
                }
            }
        }
    }

    if (isset($_moduleRouteCallableRegistry[$moduleId][$handlerKey]) && is_callable($_moduleRouteCallableRegistry[$moduleId][$handlerKey])) {
        return $_moduleRouteCallableRegistry[$moduleId][$handlerKey];
    }

    if (!function_exists($handlerKey)) {
        return null;
    }

    $_moduleRouteCallableRegistry[$moduleId][$handlerKey] = static function (array $params = []) use ($handlerKey): void {
        $handlerKey($params);
    };

    return $_moduleRouteCallableRegistry[$moduleId][$handlerKey];
}

// ─── Module Context Accessor ──────────────────────────────────────────────

/** @var \Ikabud\Kernel\Contracts\ModuleContext|null Active module context during handler execution */
$_activeModuleContext = null;

/**
 * Get the current module context.
 * This is the contract-enforced gateway for module code.
 * Returns null if called outside a module handler.
 */
function module(?string $moduleId = null): ?\Ikabud\Kernel\Contracts\ModuleContext
{
    $activeContext = kernel_request_context_get('_activeModuleContext');

    if ($moduleId === null || trim($moduleId) === '') {
        return $activeContext instanceof \Ikabud\Kernel\Contracts\ModuleContext ? $activeContext : null;
    }

    $moduleId = trim($moduleId);
    if ($activeContext instanceof \Ikabud\Kernel\Contracts\ModuleContext && $activeContext->moduleId() === $moduleId) {
        return $activeContext;
    }

    return moduleContextFor($moduleId);
}

/**
 * Build a ModuleContext for a module, using its manifest declarations.
 *
 * Table ownership rules:
 *   owns_tables   → full CRUD (module's own tables)
 *   reads_tables  → SELECT only (kernel/shared tables the module needs to read)
 *
 * Backward compatibility:
 *   If owns_tables is not declared, falls back to requires_tables (legacy field)
 *   with a deprecation log. New modules MUST use owns_tables + reads_tables.
 */
function buildModuleContext(string $moduleId, array $manifest): \Ikabud\Kernel\Contracts\ModuleContext
{
    // Determine table ownership
    $ownsTables = $manifest['owns_tables'] ?? null;
    $coOwnsTables = is_array($manifest['co_owns_tables'] ?? null) ? $manifest['co_owns_tables'] : [];
    $readsTables = $manifest['reads_tables'] ?? [];

    if ($ownsTables === null) {
        // Legacy fallback: treat requires_tables as owns_tables (backward compat)
        $ownsTables = $manifest['requires_tables'] ?? [];
        if (!empty($ownsTables)) {
            // Only log once per request (static flag)
            static $legacyWarned = [];
            if (!isset($legacyWarned[$moduleId])) {
                write_log(
                    "Module '{$moduleId}' uses legacy 'requires_tables' — migrate to 'owns_tables' + 'reads_tables' in module.json",
                    'warning',
                    ['module' => $moduleId]
                );
                $legacyWarned[$moduleId] = true;
            }
        }
    }

    $scopedDb = new \Ikabud\Kernel\Contracts\ModuleDB(
        app()->db(),
        $moduleId,
        $ownsTables,
        $readsTables,
        $coOwnsTables
    );

    $manifest['_settings'] = getModuleSettings($moduleId);

    return new \Ikabud\Kernel\Contracts\ModuleContext(
        app(),
        $moduleId,
        $scopedDb,
        $manifest
    );
}

// ─── Handler Execution ────────────────────────────────────────────────────

/**
 * @param array<string, string> $params
 */
function executeModuleHandler(string $handler, array $params = []): void
{
    if (!str_contains($handler, ':')) {
        http_response_code(500);
        echo 'Invalid module handler format';
        return;
    }

    [$moduleId, $handlerKey] = explode(':', $handler, 2);
    $modules = getEnabledModules();

    if (!isset($modules[$moduleId])) {
        http_response_code(404);
        echo app()->render('pages/404.disyl', ['page_title' => 'Module Not Available']);
        return;
    }

    $requestUri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $isModuleLoginRoute = $requestUri === '/' . $moduleId . '/login'
        || $requestUri === '/' . $moduleId . '/auth/login'
        || $requestUri === '/' . $moduleId . '/logout';

    // ── Kernel admin opt-in gate ───────────────────────────────────
    // Policy: kernel admin cannot access module routes unless explicitly opted-in.
    $user = app()->user();
    $moduleCookieName = (string)($modules[$moduleId]['auth_cookie'] ?? '');
    if ($moduleCookieName !== '') {
        $moduleCookieToken = kernelCookie($moduleCookieName);
        if (is_string($moduleCookieToken) && $moduleCookieToken !== '') {
            try {
                $moduleCookieUser = app()->jwt()->verify($moduleCookieToken);
                if (is_array($moduleCookieUser) && (($moduleCookieUser['source'] ?? '') === $moduleId)) {
                    $user = $moduleCookieUser;
                }
            } catch (Throwable $ignored) {
            }
        }
    }
    $role = $user ? (string)($user['role'] ?? '') : '';
    $source = $user ? (string)($user['source'] ?? 'kernel') : '';
    if ($role === 'admin' && $source === 'kernel' && !$isModuleLoginRoute) {
        $settings = getModuleSettings($moduleId);
        $allowKernelAdmin = (bool)($settings['allow_kernel_admin'] ?? false);
        if (!$allowKernelAdmin) {
            $isApiRoute = \Ikabud\Kernel\Http\ContentNegotiator::isApiRoute();

            if (!headers_sent()) {
                http_response_code(403);
            }
            if ($isApiRoute) {
                header('Content-Type: application/json');
                echo json_encode(['ok' => false, 'error' => 'Module not opted-in for kernel admin']);
            } else {
                // Match kernel behavior: page requests redirect home.
                app()->redirect('/');
            }
            return;
        }
    }

    $routeCallable = resolveModuleRouteCallable($moduleId, $handlerKey, $modules[$moduleId]);
    if (!is_callable($routeCallable)) {
        http_response_code(500);
        echo 'Module handler not found';
        return;
    }

    // ── Kernel-enforced CSRF on state-mutating module routes ──────────
    // API routes (Bearer-authenticated) are exempt; browser form posts must pass.
    $requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $isModuleLogin = (bool)preg_match('#^/(?:admin/)?[a-zA-Z0-9\-]+/auth/login$#', $requestUri);
    $isApiRoute = \Ikabud\Kernel\Http\ContentNegotiator::isApiRoute();

    if ($requestMethod === 'POST' && $isModuleLogin) {
        \Ikabud\Kernel\Database\KernelPDO::kernelEscalationEnter();
        try {
            $loginRateLimit = kernelConsumeLoginRateLimit($moduleId);
        } finally {
            \Ikabud\Kernel\Database\KernelPDO::kernelEscalationLeave();
        }
        if (!empty($loginRateLimit['limited'])) {
            kernelEmitLoginRateLimitJson($loginRateLimit);
            modulePopContext();
            kernel_request_context_delete('_capability_call_context');
            return;
        }
    }

    // ── Build scoped ModuleContext ───────────────────────────────────
    $ctx = modulePushContext($moduleId);
    if (!$ctx) {
        http_response_code(500);
        echo 'Module context unavailable';
        return;
    }

    kernel_request_context_set('_capability_call_context', [
        'module' => $moduleId,
        'user' => $user,
        'request_id' => request_id(),
    ]);

    if (in_array($requestMethod, ['POST', 'PUT', 'DELETE'], true) && !$isApiRoute && !$isModuleLogin) {
        if (function_exists('write_log')) {
            write_log('executeModuleHandler: CSRF enforcement triggered', 'warning', [
                'module' => $moduleId,
                'method' => $requestMethod,
                'uri' => $requestUri,
                'is_api' => $isApiRoute,
                'is_login' => $isModuleLogin,
            ]);
        }
        app()->csrfEnforce();
    }

    // ── Default anti-spam gate for public module web APIs ─────────────
    // When the anti-spam module is enabled, future modules automatically
    // inherit rate limiting / keyword checks for unauthenticated web API
    // traffic unless tenant settings disable it.
    if (
        $isApiRoute
        && function_exists('antispamShouldProtectModuleApiRequest')
        && function_exists('antispamBuildRequestBodyText')
        && app()->capabilities()->has('antispam.check@1')
        && antispamShouldProtectModuleApiRequest($moduleId, is_array($user) ? $user : null, $requestUri, $requestMethod)
    ) {
        try {
            $antiSpamResult = app()->cap()->call('antispam.check@1', [
                'body' => antispamBuildRequestBodyText(app()->input()),
                'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            ], ['mode' => 'first']);

            if (is_array($antiSpamResult) && empty($antiSpamResult['pass'])) {
                $check = (string)($antiSpamResult['check'] ?? 'blocked');
                $detail = (string)($antiSpamResult['detail'] ?? 'Request blocked');
                $status = match ($check) {
                    'rate_limit' => 429,
                    'ip_block' => 403,
                    default => 422,
                };

                if (!headers_sent()) {
                    http_response_code($status);
                    header('Content-Type: application/json');
                }
                echo json_encode([
                    'ok' => false,
                    'error' => 'Request blocked by anti-spam',
                    'check' => $check,
                    'detail' => $detail,
                ]);
                modulePopContext();
                kernel_request_context_delete('_capability_call_context');
                return;
            }
        } catch (\Throwable $e) {
            write_log('Default anti-spam gate failed: ' . $e->getMessage(), 'warning', [
                'module' => $moduleId,
                'uri' => $requestUri,
                'method' => $requestMethod,
            ]);
        }
    }

    // ── Page-level cache: serve from cache if available ─────────────
    $pageCacheActive = function_exists('pageCacheShouldCache')
        && pageCacheShouldCache($requestUri, $moduleId);

    if ($pageCacheActive && function_exists('pageCacheServe') && pageCacheServe($requestUri)) {
        // pageCacheServe() already sends X-Page-Cache header + body
        modulePopContext();
        kernel_request_context_delete('_capability_call_context');
        return;
    }

    // ── Stampede protection: prevent concurrent rebuilds of the same page ──
    $pageCacheLock = null;
    if ($pageCacheActive && function_exists('pageCacheLockAcquire')) {
        $pageCacheLock = pageCacheLockAcquire($requestUri);
        if ($pageCacheLock === false) {
            // Another process is building this page — wait for cache
            if (function_exists('pageCacheLockWaitForCache')
                && pageCacheLockWaitForCache($requestUri)
                && pageCacheServe($requestUri)) {
                modulePopContext();
                kernel_request_context_delete('_capability_call_context');
                return;
            }
            $pageCacheLock = null; // Timeout — build without lock
        }
    }

    // ── Output-buffered, exception-safe handler execution ────────────
    // Prevents stray echo/print from corrupting responses and ensures
    // uncaught exceptions produce a clean error page, not a white screen.
    ob_start();
    try {
        $routeCallable($params);

        // ── Page-level cache: capture and store on cache-eligible requests ──
        if ($pageCacheActive && function_exists('pageCacheSet')) {
            $html = ob_get_clean();
            $responseCode = http_response_code();
            pageCacheSet($requestUri, $html, $moduleId, (int)$responseCode);
            if ($pageCacheLock) { pageCacheLockRelease($pageCacheLock); $pageCacheLock = null; }
            if (!headers_sent()) {
                header('X-Page-Cache: miss');
            }
            echo $html;
            // Release session lock after GET render so concurrent requests can proceed.
            if (function_exists('releaseSessionAfterRender')) { releaseSessionAfterRender(); }
        } else {
            ob_end_flush(); // success — send captured output
            // Release session lock after GET render so concurrent requests can proceed.
            if (function_exists('releaseSessionAfterRender')) { releaseSessionAfterRender(); }
        }
    } catch (\Throwable $e) {
        ob_end_clean(); // discard any partial output from the bad handler
        if ($pageCacheLock) { pageCacheLockRelease($pageCacheLock); $pageCacheLock = null; }

        write_log("Module handler '{$handler}' threw: " . $e->getMessage(), 'error', [
            'module'  => $moduleId,
            'handler' => $handlerKey,
            'file'    => $e->getFile(),
            'line'    => $e->getLine(),
            'trace'   => $e->getTraceAsString(),
        ]);

        if (!headers_sent()) {
            http_response_code(500);
        }

        // API routes get JSON error; page routes get rendered error page
        if (str_starts_with($requestUri, '/api/')) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'An internal module error occurred.']);
        } else {
            try {
                echo app()->render('pages/500.disyl', ['page_title' => 'Error']);
            } catch (\Throwable $_) {
                echo '<!DOCTYPE html><html><body><h1>Application Error</h1><p>An unexpected error occurred.</p></body></html>';
            }
        }
    } finally {
        modulePopContext();
        kernel_request_context_delete('_capability_call_context');
    }
}

// ─── Dynamic Navigation ───────────────────────────────────────────────────

/**
 * Build nav items from all enabled modules for the current user's role.
 * Returns flat array: [ ['label'=>..., 'url'=>..., 'icon'=>..., 'module'=>...], ... ]
 */
function getModuleNavItems(?string $role = null, ?array $user = null): array
{
    if ($user === null) {
        $resolvedUser = app()->user();
        $user = is_array($resolvedUser) ? $resolvedUser : null;
    }

    if ($role === null) {
        $role = $user ? (string)($user['role'] ?? '') : '';
    }
    if ($role === '') {
        return [];
    }

    $source = $user ? (string)($user['source'] ?? '') : '';
    $isKernelAdmin = $source === 'kernel' && $role === 'admin';
    $isKernelSuperadmin = $source === 'kernel' && $role === 'superadmin';

    // Kernel superadmin: settings-only role — no module navigation.
    // Return dedicated kernel nav and skip all module items.
    if ($isKernelSuperadmin) {
        return [
            ['label' => 'Feature Settings', 'url' => '/superadmin/settings', 'icon' => 'settings', 'module' => '_kernel', 'target' => null],
            ['label' => 'Performance',       'url' => '/superadmin/perf',     'icon' => 'chart',    'module' => '_kernel', 'target' => '_self'],
            ['label' => 'Cache',             'url' => '/superadmin/cache',    'icon' => 'database', 'module' => '_kernel', 'target' => '_self'],
            ['label' => 'Integrations',      'url' => '/kernel/integrations', 'icon' => 'git-merge', 'module' => '_kernel', 'target' => '_self'],
            ['label' => 'Workbench',         'url' => '/superadmin/workbench','icon' => 'terminal',  'module' => '_kernel', 'target' => null],
            ['label' => 'Profile',           'url' => '/admin/profile',       'icon' => 'user',     'module' => '_kernel', 'target' => null],
        ];
    }

    $navItems = [];
    foreach (getEnabledModules() as $module) {
        $moduleId = (string)($module['id'] ?? '');
        if ($moduleId === '') {
            continue;
        }

        // Kernel admin should not see module links unless the module opts in.
        if ($isKernelAdmin) {
            $settings = $module['_settings'] ?? [];
            $allowKernelAdmin = (bool)($settings['allow_kernel_admin'] ?? false);
            if (!$allowKernelAdmin) {
                continue;
            }
        }

        foreach ($module['nav'] ?? [] as $item) {
            $roles = $item['roles'] ?? [];
            if (in_array($role, $roles, true) || in_array('*', $roles, true)) {
                $rawUrl = $item['url'] ?? '#';
                // Kernel admin: keep absolute/admin/api/external URLs unchanged.
                // Only prefix legacy module-local paths (e.g. "/settings" -> "/module-id/settings").
                $isExternal = (bool)preg_match('#^(https?:)?//#', (string)$rawUrl);
                $isAdminPath = strpos((string)$rawUrl, '/admin/') === 0;
                $isApiPath = strpos((string)$rawUrl, '/api/') === 0;
                $isModulePath = strpos((string)$rawUrl, '/' . $moduleId) === 0;

                if ($isKernelAdmin && $rawUrl !== '#' && !$isExternal && !$isAdminPath && !$isApiPath && !$isModulePath) {
                    $rawUrl = '/' . $moduleId . (strpos((string)$rawUrl, '/') === 0 ? $rawUrl : '/' . $rawUrl);
                }
                $navItems[] = [
                    'label'  => $item['label'] ?? '',
                    'url'    => $rawUrl,
                    'icon'   => $item['icon'] ?? 'box',
                    'module' => $moduleId,
                    'target' => $item['target'] ?? null,
                ];
            }
        }
    }

    // Kernel-level nav: Modules page (always available to admin, even if no modules enabled)
    if ($isKernelAdmin) {
        $navItems[] = ['label' => '---', 'url' => '#', 'icon' => 'separator', 'module' => '_kernel'];
        $navItems[] = ['label' => 'Platform', 'url' => '/admin/platform', 'icon' => 'server', 'module' => '_kernel'];
        $navItems[] = ['label' => 'Profile', 'url' => '/admin/profile', 'icon' => 'user', 'module' => '_kernel'];
        $navItems[] = ['label' => 'Users', 'url' => '/admin/users', 'icon' => 'users', 'module' => '_kernel'];
        $navItems[] = ['label' => 'Triggers', 'url' => '/admin/kernel/triggers', 'icon' => 'bolt', 'module' => '_kernel'];
        // AI link: only show when the ai module is enabled and allows kernel admin access.
        $allEnabledMods = getEnabledModules();
        if (isset($allEnabledMods['ai'])) {
            $aiSettings = $allEnabledMods['ai']['_settings'] ?? [];
            if (!empty($aiSettings['allow_kernel_admin'])) {
                $navItems[] = ['label' => 'AI', 'url' => '/admin/ai', 'icon' => 'sparkles', 'module' => 'ai'];
            }
        }
        $navItems[] = ['label' => 'Tenants', 'url' => '/admin/tenants', 'icon' => 'building', 'module' => '_kernel'];
        $navItems[] = ['label' => 'Modules', 'url' => '/admin/modules', 'icon' => 'puzzle', 'module' => '_kernel'];
    }

    return $navItems;
}

/**
 * Get the first available URL for a role from enabled modules.
 * Used for smart home redirect when no hardcoded landing page exists.
 */
function getModuleHomeUrl(string $role, ?array $user = null): ?string
{
    $source = (string)($user['source'] ?? 'kernel');
    if ($source === 'kernel' && in_array($role, ['admin', 'superadmin'], true)) {
        return null;
    }

    $items = getModuleNavItems($role, $user);
    foreach ($items as $item) {
        $url = $item['url'] ?? '';
        if ($url !== '' && $url !== '#' && ($item['label'] ?? '') !== '---') {
            return $url;
        }
    }
    return null;
}

// ─── Kernel Hook Registration ─────────────────────────────────────────────
// The module-manager registers itself with the kernel hook system so the kernel
// never calls module functions directly. This is the bridge between kernel OS
// and userland modules.

/**
 * Register all module-manager hooks with the kernel.
 * Called once after the module-manager is loaded.
 */
function registerModuleManagerHooks(): void
{
    $hooks = app()->hooks();

    // kernel.nav_items: inject module navigation items for the current user
    $hooks->on('kernel.nav_items', function (array $items, ?array $user) {
        if (!$user) return $items;
        $role = (string)($user['role'] ?? '');
        return array_merge($items, getModuleNavItems($role));
    });

    // kernel.home_url: resolve the home URL for a role from modules
    $hooks->on('kernel.home_url', function (?string $url, string $role, ?array $user = null) {
        return $url ?? getModuleHomeUrl($role, $user);
    });

    // kernel.auth_cookie_names: allow modules to register additional auth cookie names
    // so kernel-level app()->user() can recognize module-authenticated sessions.
    $hooks->on('kernel.auth_cookie_names', function (array $names, string $defaultCookie) {
        foreach (declaredModuleAuthCookieNames() as $cookie) {
            if ($cookie !== $defaultCookie && !in_array($cookie, $names, true)) {
                $names[] = $cookie;
            }
        }
        return $names;
    });
}

// Auto-register when this file is loaded
registerModuleManagerHooks();

// ─── Module Installer ─────────────────────────────────────────────────────

/**
 * Validate a module.json manifest.
 * Returns ['ok' => true, 'manifest' => [...]] or ['ok' => false, 'error' => '...']
 */
function validateModuleManifest(string $path): array
{
    if (!is_file($path)) {
        return ['ok' => false, 'error' => 'module.json not found', 'error_code' => 'manifest_not_found'];
    }

    $manifest = json_decode((string) file_get_contents($path), true);
    if (!is_array($manifest)) {
        return ['ok' => false, 'error' => 'module.json is not valid JSON', 'error_code' => 'manifest_invalid_json'];
    }

    $required = ['id', 'name', 'version'];
    foreach ($required as $key) {
        if (empty($manifest[$key])) {
            return ['ok' => false, 'error' => "module.json missing required field: {$key}", 'error_code' => 'manifest_missing_required_field'];
        }
    }

    if (!is_string($manifest['id']) || trim($manifest['id']) === '') {
        return ['ok' => false, 'error' => 'module.json field id must be a non-empty string', 'error_code' => 'manifest_invalid_id'];
    }
    if (!is_string($manifest['name']) || trim($manifest['name']) === '') {
        return ['ok' => false, 'error' => 'module.json field name must be a non-empty string', 'error_code' => 'manifest_invalid_name'];
    }
    if (!is_string($manifest['version']) || trim($manifest['version']) === '') {
        return ['ok' => false, 'error' => 'module.json field version must be a non-empty string', 'error_code' => 'manifest_invalid_version'];
    }

    if (strlen($manifest['id']) > 64) {
        return ['ok' => false, 'error' => 'Module id must be at most 64 characters', 'error_code' => 'manifest_invalid_id'];
    }

    // id must be lowercase alphanumeric + hyphens
    if (!preg_match('/^[a-z0-9]([a-z0-9\-]*[a-z0-9])?$/', $manifest['id'])) {
        return ['ok' => false, 'error' => 'Module id must be lowercase alphanumeric with hyphens (e.g. "daily-ledger")', 'error_code' => 'manifest_invalid_id'];
    }

    // version must look like semver (allow prerelease/build suffixes)
    if (!preg_match('/^\d+\.\d+\.\d+(?:[-+][A-Za-z0-9.\-]+)?$/', $manifest['version'])) {
        return ['ok' => false, 'error' => 'module.json field version must follow semver format (e.g. 1.0.0)', 'error_code' => 'manifest_invalid_version'];
    }

    $validateTableList = static function (mixed $value, string $field): ?array {
        if ($value === null) {
            return null;
        }
        if (!is_array($value)) {
            return ['ok' => false, 'error' => "module.json field {$field} must be an array of table names", 'error_code' => 'manifest_invalid_table_list'];
        }
        foreach ($value as $table) {
            if (!is_string($table) || trim($table) === '') {
                return ['ok' => false, 'error' => "module.json field {$field} must only contain non-empty strings", 'error_code' => 'manifest_invalid_table_list'];
            }
            if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
                return ['ok' => false, 'error' => "module.json field {$field} contains invalid table name: {$table}", 'error_code' => 'manifest_invalid_table_list'];
            }
        }
        return null;
    };

    foreach (['owns_tables', 'co_owns_tables', 'reads_tables', 'requires_tables'] as $tableField) {
        $tableValidation = $validateTableList($manifest[$tableField] ?? null, $tableField);
        if (is_array($tableValidation) && empty($tableValidation['ok'])) {
            return $tableValidation;
        }
    }

    if (array_key_exists('auth_cookie', $manifest)) {
        if (!is_string($manifest['auth_cookie']) || trim($manifest['auth_cookie']) === '') {
            return ['ok' => false, 'error' => 'module.json field auth_cookie must be a non-empty string when provided', 'error_code' => 'manifest_invalid_auth_cookie'];
        }
        if (!preg_match('/^[A-Za-z0-9_\-]+$/', $manifest['auth_cookie'])) {
            return ['ok' => false, 'error' => 'module.json field auth_cookie contains invalid characters', 'error_code' => 'manifest_invalid_auth_cookie'];
        }
    }

    if (array_key_exists('auth_owned', $manifest)) {
        $authOwnedValidation = validateAuthOwnedSpec($manifest['auth_owned'], true);
        if (empty($authOwnedValidation['ok'])) {
            return [
                'ok' => false,
                'error' => (string)($authOwnedValidation['error'] ?? 'module.json field auth_owned is invalid'),
                'error_code' => 'manifest_invalid_auth_owned',
            ];
        }
    }

    if (array_key_exists('nav', $manifest)) {
        if (!is_array($manifest['nav'])) {
            return ['ok' => false, 'error' => 'module.json field nav must be an array', 'error_code' => 'manifest_invalid_nav'];
        }
        foreach ($manifest['nav'] as $idx => $item) {
            if (!is_array($item)) {
                return ['ok' => false, 'error' => "module.json nav[{$idx}] must be an object", 'error_code' => 'manifest_invalid_nav'];
            }
            if (array_key_exists('label', $item) && !is_string($item['label'])) {
                return ['ok' => false, 'error' => "module.json nav[{$idx}].label must be a string", 'error_code' => 'manifest_invalid_nav'];
            }
            if (array_key_exists('key', $item) && (!is_string($item['key']) || trim($item['key']) === '')) {
                return ['ok' => false, 'error' => "module.json nav[{$idx}].key must be a non-empty string", 'error_code' => 'manifest_invalid_nav'];
            }
            if (array_key_exists('url', $item) && !is_string($item['url'])) {
                return ['ok' => false, 'error' => "module.json nav[{$idx}].url must be a string", 'error_code' => 'manifest_invalid_nav'];
            }
            if (array_key_exists('description', $item) && !is_string($item['description'])) {
                return ['ok' => false, 'error' => "module.json nav[{$idx}].description must be a string", 'error_code' => 'manifest_invalid_nav'];
            }
            if (array_key_exists('roles', $item)) {
                if (!is_array($item['roles'])) {
                    return ['ok' => false, 'error' => "module.json nav[{$idx}].roles must be an array of strings", 'error_code' => 'manifest_invalid_nav'];
                }
                foreach ($item['roles'] as $role) {
                    if (!is_string($role) || trim($role) === '') {
                        return ['ok' => false, 'error' => "module.json nav[{$idx}].roles must contain non-empty strings", 'error_code' => 'manifest_invalid_nav'];
                    }
                }
            }

            $navUrl = trim((string)($item['url'] ?? ''));
            if (str_starts_with($navUrl, '/admin/ehr')) {
                if (!array_key_exists('key', $item) || trim((string)$item['key']) === '') {
                    return ['ok' => false, 'error' => "module.json nav[{$idx}].key is required for /admin/ehr sidebar items", 'error_code' => 'manifest_invalid_nav'];
                }
                if (!array_key_exists('description', $item) || trim((string)$item['description']) === '') {
                    return ['ok' => false, 'error' => "module.json nav[{$idx}].description is required for /admin/ehr sidebar items", 'error_code' => 'manifest_invalid_nav'];
                }
                if (!array_key_exists('roles', $item) || !is_array($item['roles']) || $item['roles'] === []) {
                    return ['ok' => false, 'error' => "module.json nav[{$idx}].roles is required for /admin/ehr sidebar items", 'error_code' => 'manifest_invalid_nav'];
                }
            }
        }
    }

    if (array_key_exists('navigation_dependencies', $manifest)) {
        if (!is_array($manifest['navigation_dependencies'])) {
            return ['ok' => false, 'error' => 'module.json field navigation_dependencies must be an array of module ids', 'error_code' => 'manifest_invalid_navigation_dependencies'];
        }
        $seenNavigationDependencies = [];
        foreach ($manifest['navigation_dependencies'] as $dependency) {
            if (!is_string($dependency) || !preg_match('/^[a-z0-9]([a-z0-9\-]*[a-z0-9])?$/', $dependency)) {
                return ['ok' => false, 'error' => 'module.json field navigation_dependencies must contain valid module ids', 'error_code' => 'manifest_invalid_navigation_dependencies'];
            }
            if ($dependency === $manifest['id']) {
                return ['ok' => false, 'error' => 'module.json field navigation_dependencies must not include the module itself', 'error_code' => 'manifest_invalid_navigation_dependencies'];
            }
            if (isset($seenNavigationDependencies[$dependency])) {
                return ['ok' => false, 'error' => "module.json field navigation_dependencies contains duplicate module id: {$dependency}", 'error_code' => 'manifest_invalid_navigation_dependencies'];
            }
            $seenNavigationDependencies[$dependency] = true;
        }
    }

    $entityContextValidation = validateModuleEntityContexts($manifest);
    if (empty($entityContextValidation['ok'])) {
        return [
            'ok' => false,
            'error' => (string)($entityContextValidation['error'] ?? 'module.json field entity_contexts is invalid'),
            'error_code' => 'manifest_invalid_entity_contexts',
        ];
    }

    return ['ok' => true, 'manifest' => $manifest];
}

/**
 * Collect internal navigation URLs declared by a module manifest and by
 * literal sidebar links in module-owned PHP/DiSyL files.
 *
 * @param array<string, mixed> $manifest
 * @return array<int, string>
 */
function moduleNavigationUrls(array $manifest): array
{
    $urls = [];
    $visit = static function (mixed $entry) use (&$visit, &$urls): void {
        if (!is_array($entry)) {
            return;
        }
        $url = trim((string)($entry['url'] ?? ''));
        if (str_starts_with($url, '/')) {
            $urls[] = $url;
        }
        foreach ((array)($entry['children'] ?? []) as $child) {
            $visit($child);
        }
    };
    foreach ((array)($manifest['nav'] ?? $manifest['sidebar'] ?? []) as $entry) {
        $visit($entry);
    }

    $moduleId = trim((string)($manifest['id'] ?? ''));
    $manifestPath = $moduleId !== '' ? moduleManifestPathForId($moduleId) : null;
    $modulePath = trim((string)($manifest['_path'] ?? ''));
    if ($modulePath === '' && is_string($manifestPath)) {
        $modulePath = dirname($manifestPath);
    }
    if ($modulePath !== '' && is_dir($modulePath)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($modulePath, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file->isFile() || !in_array(strtolower($file->getExtension()), ['php', 'disyl'], true)) {
                continue;
            }
            $source = @file_get_contents($file->getPathname());
            if (!is_string($source)) {
                continue;
            }
            preg_match_all('/[\'\"]url[\'\"]\s*=>\s*([\'\"])(\/admin\/[^\'\"]+)\1\s*[,\]]/', $source, $phpMatches);
            preg_match_all('/<a\b[^>]*\bhref\s*=\s*([\'\"])(\/[^\/\'\"][^\'\"]*)\1/i', $source, $hrefMatches);
            foreach (array_merge($phpMatches[2] ?? [], $hrefMatches[2] ?? []) as $url) {
                $urls[] = $url;
            }
        }
    }

    $urls = array_values(array_unique(array_map(static function (string $url): string {
        $path = parse_url($url, PHP_URL_PATH);
        return rtrim(is_string($path) ? $path : $url, '/') ?: '/';
    }, $urls)));
    sort($urls);
    return $urls;
}

function moduleRoutePatternMatchesPath(string $route, string $path): bool
{
    $route = rtrim((string)(parse_url($route, PHP_URL_PATH) ?: $route), '/') ?: '/';
    $path = rtrim((string)(parse_url($path, PHP_URL_PATH) ?: $path), '/') ?: '/';
    $path = preg_replace('/\{[^}]+\}|:[A-Za-z_][A-Za-z0-9_]*/', '1', $path) ?? $path;
    $quoted = preg_quote($route, '#');
    $pattern = preg_replace('/\\\\\{[^}]+\\\\\}|\\:[A-Za-z_][A-Za-z0-9_]*/', '[^/]+', $quoted) ?? $quoted;
    return preg_match('#^' . $pattern . '$#', $path) === 1;
}

/**
 * @param array<string, mixed> $manifest
 * @param array<string, array<string, mixed>>|null $installedModules
 * @return array{ok: bool, missing: array<int, string>, undeclared_dependencies: array<string, array<int, string>>, checked: int, detail: string}
 */
function validateModuleNavigationRoutes(array $manifest, ?array $installedModules = null): array
{
    $urls = moduleNavigationUrls($manifest);
    if ($urls === []) {
        return ['ok' => true, 'missing' => [], 'undeclared_dependencies' => [], 'checked' => 0, 'detail' => 'No internal navigation URLs declared'];
    }

    $moduleId = trim((string)($manifest['id'] ?? ''));
    $manifestPath = $moduleId !== '' ? moduleManifestPathForId($moduleId) : null;
    $modulePath = trim((string)($manifest['_path'] ?? ''));
    if ($modulePath === '' && is_string($manifestPath)) {
        $modulePath = dirname($manifestPath);
    }
    $routesFile = $modulePath !== '' ? $modulePath . '/routes.php' : '';
    $routes = $routesFile !== '' && is_file($routesFile) ? require $routesFile : [];
    $routeOwners = [
        $moduleId => is_array($routes) && is_array($routes['GET'] ?? null)
            ? array_map('strval', array_keys($routes['GET']))
            : [],
    ];
    // Shell modules may intentionally link to pages owned by companion modules
    // (for example, the EHR shell links to scheduling and encounters routes).
    // Certification validates against the complete installed route registry.
    foreach ($installedModules ?? discoverModules() as $candidateId => $candidate) {
        $candidateId = (string)($candidate['id'] ?? $candidateId);
        $candidatePath = trim((string)($candidate['_path'] ?? ''));
        $candidateRoutesFile = $candidatePath !== '' ? $candidatePath . '/routes.php' : '';
        if ($candidateRoutesFile === '' || $candidateRoutesFile === $routesFile || !is_file($candidateRoutesFile)) {
            continue;
        }
        $candidateRoutes = require $candidateRoutesFile;
        if (is_array($candidateRoutes) && is_array($candidateRoutes['GET'] ?? null)) {
            $routeOwners[$candidateId] = array_map('strval', array_keys($candidateRoutes['GET']));
        }
    }

    $missing = [];
    $undeclaredDependencies = [];
    $allowedDependencies = array_fill_keys(array_map('strval', (array)($manifest['navigation_dependencies'] ?? [])), true);
    foreach ($urls as $url) {
        $owners = [];
        foreach ($routeOwners as $ownerId => $getRoutes) {
            foreach ($getRoutes as $route) {
                if (moduleRoutePatternMatchesPath($route, $url)) {
                    $owners[] = $ownerId;
                    break;
                }
            }
        }
        if ($owners === []) {
            $missing[] = $url;
            continue;
        }
        if (in_array($moduleId, $owners, true)) {
            continue;
        }
        $declaredOwner = array_filter($owners, static fn(string $owner): bool => isset($allowedDependencies[$owner]));
        if ($declaredOwner === []) {
            $undeclaredDependencies[$url] = array_values(array_unique($owners));
        }
    }

    $ok = $missing === [] && $undeclaredDependencies === [];
    if ($missing !== []) {
        $detail = 'Missing GET route(s): ' . implode(', ', $missing);
    } elseif ($undeclaredDependencies !== []) {
        $parts = [];
        foreach ($undeclaredDependencies as $url => $owners) {
            $parts[] = $url . ' (owned by ' . implode('|', $owners) . ')';
        }
        $detail = 'Undeclared navigation dependency: ' . implode(', ', $parts);
    } else {
        $dependencyCount = count($allowedDependencies);
        $detail = count($urls) . ' navigation URL(s) resolve with explicit ownership'
            . ($dependencyCount > 0 ? " ({$dependencyCount} navigation dependencies declared)" : '');
    }

    return [
        'ok' => $ok,
        'missing' => $missing,
        'undeclared_dependencies' => $undeclaredDependencies,
        'checked' => count($urls),
        'detail' => $detail,
    ];
}

/**
 * Validate a module manifest against the Phase 9 certification checklist.
 *
 * Returns an array of certification items with pass/fail status.
 * A module must pass ALL checks to be certified.
 *
 * @param array<string, mixed> $manifest
 * @return array{ok: bool, checks: array<int, array{check: string, passed: bool, detail: string}>, score: int, max: int}
 */
function validateModuleCertification(array $manifest): array
{
    $checks = [];
    $passed = 0;
    $total = 0;

    $moduleId = (string)($manifest['id'] ?? 'unknown');
    $type = trim((string)($manifest['type'] ?? 'module'));
    $isServiceModule = ($type === 'service-module');

    // C1: Basic identity
    $total++;
    $ok = !empty($manifest['id']) && !empty($manifest['name']) && !empty($manifest['version']);
    $checks[] = ['check' => 'C1: Identity', 'passed' => $ok, 'detail' => $ok ? "{$manifest['name']} v{$manifest['version']}" : 'Missing id, name, or version'];
    if ($ok) $passed++;

    // C2: Table ownership declared (skip for service-modules)
    $total++;
    $isServiceModule = ($type === 'service-module');
    if ($isServiceModule) {
        $checks[] = ['check' => 'C2: Table ownership', 'passed' => true, 'detail' => 'N/A for service-module'];
        $passed++;
    } else {
        $owns = is_array($manifest['owns_tables'] ?? null) && !empty($manifest['owns_tables']);
        $reads = is_array($manifest['reads_tables'] ?? null) && !empty($manifest['reads_tables']);
        $ok = $owns || $reads;
        $checks[] = ['check' => 'C2: Table ownership', 'passed' => $ok, 'detail' => $ok ? 'owns_tables or reads_tables declared' : 'No table ownership declared'];
        if ($ok) $passed++;
    }

    // C3: Capabilities exposed (declared capabilities key with exposes array — even if empty)
    $total++;
    $capsExposes = $manifest['capabilities']['exposes'] ?? null;
    // Accept: non-empty array OR explicitly declared empty array OR flat-array format
    $capsDeclared = is_array($capsExposes);
    $capsFlatFormat = is_array($manifest['capabilities'] ?? null) && !isset($manifest['capabilities']['exposes']);
    if ($capsFlatFormat) {
        $capsExposes = $manifest['capabilities'];
        $capsDeclared = is_array($capsExposes) && !empty($capsExposes);
    }
    $ok = $capsDeclared;
    $count = is_array($capsExposes) ? count($capsExposes) : 0;
    $checks[] = ['check' => 'C3: Capabilities', 'passed' => $ok, 'detail' => $ok ? ($count > 0 ? "{$count} capabilities exposed" : 'capabilities declared (none exposed)') : 'No capabilities declared'];
    if ($ok) $passed++;

    // C4: Events declared (accept empty array — module has declared it, just has none)
    $total++;
    $events = is_array($manifest['events'] ?? null);
    $ok = $events;
    $hasEvents = $events && !empty($manifest['events']);
    $checks[] = ['check' => 'C4: Events', 'passed' => $ok, 'detail' => $ok ? ($hasEvents ? count($manifest['events']) . ' events declared' : 'events key declared (none needed)') : 'No events declared'];
    if ($ok) $passed++;

    // C5: Routes declared (skip for service-modules)
    $total++;
    if ($isServiceModule) {
        $checks[] = ['check' => 'C5: Routes', 'passed' => true, 'detail' => 'N/A for service-module'];
        $passed++;
    } else {
        $routes = (is_array($manifest['routes'] ?? null) && !empty($manifest['routes'])) || !empty($manifest['routes']);
        $ok = $routes;
        $checks[] = ['check' => 'C5: Routes', 'passed' => $ok, 'detail' => $ok ? 'Routes declared' : 'No routes declared'];
        if ($ok) $passed++;
    }

    // C6: Migrations present (accept empty array, skip for service-modules)
    $total++;
    if ($isServiceModule) {
        $checks[] = ['check' => 'C6: Migrations', 'passed' => true, 'detail' => 'N/A for service-module'];
        $passed++;
    } else {
        $migrations = is_array($manifest['migrations'] ?? null);
        $hasMigrations = $migrations && !empty($manifest['migrations']);
        $ok = $migrations;
        $checks[] = ['check' => 'C6: Migrations', 'passed' => $ok, 'detail' => $ok ? ($hasMigrations ? count($manifest['migrations']) . ' migrations' : 'migrations key declared (none needed)') : 'No migrations declared'];
        if ($ok) $passed++;
    }

    // C7: Author declared
    $total++;
    $author = !empty($manifest['author']) && is_string($manifest['author']);
    $ok = $author;
    $checks[] = ['check' => 'C7: Author', 'passed' => $ok, 'detail' => $ok ? (string)$manifest['author'] : 'No author declared'];
    if ($ok) $passed++;

    // C8: Description
    $total++;
    $desc = !empty($manifest['description']) && is_string($manifest['description']);
    $ok = $desc;
    $checks[] = ['check' => 'C8: Description', 'passed' => $ok, 'detail' => $ok ? substr((string)$manifest['description'], 0, 60) . '...' : 'No description'];
    if ($ok) $passed++;

    // C9: Module type valid
    $total++;
    $validTypes = ['php-module', 'module', 'service-module'];
    $ok = in_array($type, $validTypes, true);
    $checks[] = ['check' => 'C9: Module type', 'passed' => $ok, 'detail' => $ok ? $type : "Invalid type: {$type}"];
    if ($ok) $passed++;

    // C10: Every declared/rendered internal navigation URL has a GET route.
    $total++;
    if ($isServiceModule) {
        $checks[] = ['check' => 'C10: Navigation routes', 'passed' => true, 'detail' => 'N/A for service-module'];
        $passed++;
    } else {
        $navigation = validateModuleNavigationRoutes($manifest);
        $ok = $navigation['ok'];
        $checks[] = ['check' => 'C10: Navigation routes', 'passed' => $ok, 'detail' => $navigation['detail']];
        if ($ok) $passed++;
    }

    // C11: Service-module endpoint (only if type=service-module)
    if ($type === 'service-module') {
        $total++;
        $endpoint = !empty($manifest['service']['endpoint']) && is_string($manifest['service']['endpoint']);
        $ok = $endpoint;
        $checks[] = ['check' => 'C11: Service endpoint', 'passed' => $ok, 'detail' => $ok ? (string)$manifest['service']['endpoint'] : 'No service endpoint declared'];
        if ($ok) $passed++;
    }

    return [
        'ok' => $passed === $total,
        'checks' => $checks,
        'score' => $passed,
        'max' => $total,
    ];
}

function moduleInstallFailure(string $errorCode, string $error, array $extra = []): array
{
    return ['ok' => false, 'error_code' => $errorCode, 'error' => $error] + $extra;
}

/**
 * Install a module from a zip file.
 * Returns ['ok' => true, 'module_id' => '...'] or ['ok' => false, 'error' => '...']
 */
function installModuleFromZip(string $zipPath): array
{
    if (!is_file($zipPath)) {
        return moduleInstallFailure('zip_not_found', 'Zip file not found');
    }

    $signature = @file_get_contents($zipPath, false, null, 0, 4);
    $zipSignatures = ["PK\x03\x04", "PK\x05\x06", "PK\x07\x08"];
    if (!is_string($signature) || !in_array($signature, $zipSignatures, true)) {
        return moduleInstallFailure('zip_invalid_signature', 'Uploaded file is not a valid ZIP archive');
    }

    if (!class_exists('ZipArchive')) {
        return moduleInstallFailure('zip_extension_missing', 'PHP zip extension is required');
    }

    $zip = new \ZipArchive();
    if ($zip->open($zipPath) !== true) {
        return moduleInstallFailure('zip_open_failed', 'Cannot open zip file');
    }

    $maxEntries = 2000;
    $maxTotalUncompressedBytes = 200 * 1024 * 1024; // 200 MiB
    if ($zip->numFiles > $maxEntries) {
        $zip->close();
        return moduleInstallFailure('zip_too_many_entries', 'Zip contains too many entries');
    }

    // Find module.json in the zip (could be at root or inside a single top-level folder)
    $manifestIndex = null;
    $prefix = '';

    // Normalize + validate zip entry names before any extraction.
    // This blocks Zip Slip style traversal, absolute paths, and null-byte names.
    $sanitizeEntryName = static function (string $name): ?string {
        $name = str_replace('\\\\', '/', $name);
        if ($name === '' || str_contains($name, "\0")) {
            return null;
        }
        if (preg_match('/^[A-Za-z]:\//', $name)) {
            return null;
        }
        if (str_contains($name, ':')) {
            return null;
        }
        if (str_starts_with($name, '/')) {
            return null;
        }

        $parts = explode('/', $name);
        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                return null;
            }
        }

        return ltrim($name, '/');
    };

    $isSafeZipEntryType = static function (int $index, bool $isDirectory) use ($zip): bool {
        if (!method_exists($zip, 'getExternalAttributesIndex')) {
            return true;
        }

        $opsys = 0;
        $attr = 0;
        $ok = $zip->getExternalAttributesIndex($index, $opsys, $attr);
        if (!$ok) {
            return true;
        }

        // On Unix creators, upper 16 bits generally store st_mode.
        $mode = ($attr >> 16) & 0xF000;
        if ($mode === 0) {
            return true;
        }

        // Reject symbolic links explicitly.
        if ($mode === 0xA000) {
            return false;
        }

        if ($isDirectory) {
            return $mode === 0x4000;
        }

        // For file entries, allow regular files only.
        return $mode === 0x8000;
    };

    // Check root first
    if ($zip->locateName('module.json') !== false) {
        $manifestIndex = $zip->locateName('module.json');
        $prefix = '';
    } else {
        // Check for single top-level directory containing module.json
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $rawName = (string)$zip->getNameIndex($i);
            $name = $sanitizeEntryName($rawName);
            if ($name === null) {
                continue;
            }
            if (preg_match('#^([^/]+)/module\.json$#', $name, $m)) {
                $manifestIndex = $i;
                $prefix = $m[1] . '/';
                break;
            }
        }
    }

    if ($manifestIndex === null) {
        $zip->close();
        return moduleInstallFailure('manifest_not_found', 'Zip file does not contain module.json');
    }

    // Preflight all entries so malformed archives fail closed.
    $totalUncompressedBytes = 0;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $rawName = (string)$zip->getNameIndex($i);
        $name = $sanitizeEntryName($rawName);
        if ($name === null) {
            $zip->close();
            return moduleInstallFailure('zip_invalid_path', "Zip contains invalid entry path: {$rawName}");
        }

        if ($prefix !== '' && !str_starts_with($name, $prefix)) {
            $zip->close();
            return moduleInstallFailure('zip_outside_module_root', "Zip contains files outside module root: {$name}");
        }

        $relativeName = $prefix !== '' ? substr($name, strlen($prefix)) : $name;
        if ($relativeName === '') {
            continue;
        }

        $isDirectory = str_ends_with($relativeName, '/');
        if (!$isSafeZipEntryType($i, $isDirectory)) {
            $zip->close();
            return moduleInstallFailure('zip_unsupported_entry_type', "Zip contains unsupported entry type: {$name}");
        }

        if (!$isDirectory) {
            $normalizedRelativeName = ltrim(str_replace('\\\\', '/', $relativeName), '/');
            if ($normalizedRelativeName === '' || str_contains($normalizedRelativeName, "\0") || str_contains($normalizedRelativeName, '../')) {
                $zip->close();
                return moduleInstallFailure('zip_invalid_path', "Zip contains invalid file path: {$name}");
            }

            $stat = $zip->statIndex($i);
            if (is_array($stat)) {
                $entrySize = (int)($stat['size'] ?? 0);
                if ($entrySize < 0) {
                    $zip->close();
                    return moduleInstallFailure('zip_invalid_metadata', "Zip contains invalid entry metadata: {$name}");
                }
                $totalUncompressedBytes += $entrySize;
                if ($totalUncompressedBytes > $maxTotalUncompressedBytes) {
                    $zip->close();
                    return moduleInstallFailure('zip_size_limit_exceeded', 'Zip uncompressed size exceeds allowed limit');
                }
            }
        }
    }

    // Read and validate the manifest
    $manifestJson = $zip->getFromIndex($manifestIndex);
    $tempManifest = tempnam(sys_get_temp_dir(), 'mod_manifest_');
    file_put_contents($tempManifest, $manifestJson);
    $validation = validateModuleManifest($tempManifest);
    @unlink($tempManifest);

    if (!$validation['ok']) {
        $zip->close();
        return $validation + ['error_code' => $validation['error_code'] ?? 'manifest_validation_failed'];
    }

    $manifest = $validation['manifest'];
    $capabilityValidation = validateModuleCapabilities($manifest);
    if (empty($capabilityValidation['ok'])) {
        $zip->close();
        return moduleInstallFailure(
            'manifest_invalid_capabilities',
            (string)($capabilityValidation['error'] ?? 'module.json capabilities block is invalid')
        );
    }

    $moduleId = $manifest['id'];
    $targetDir = modulesPath() . '/' . $moduleId;
    $removeDirectory = static function (string $path): void {
        if (!is_dir($path)) {
            return;
        }

        $it = new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS);
        $files = new \RecursiveIteratorIterator($it, \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            if ($file->isDir()) {
                @rmdir($file->getRealPath());
            } else {
                @unlink($file->getRealPath());
            }
        }
        @rmdir($path);
    };

    // Safety: don't overwrite if already exists (use update flow instead)
    if (is_dir($targetDir)) {
        $zip->close();
        return moduleInstallFailure('module_already_exists', "Module '{$moduleId}' already exists. Remove it first or use update.");
    }

    // Extract
    @mkdir($targetDir, 0775, true);
    $targetRoot = realpath($targetDir);
    if ($targetRoot === false) {
        $zip->close();
        return moduleInstallFailure('target_dir_init_failed', 'Failed to initialize module target directory');
    }

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $rawName = (string)$zip->getNameIndex($i);
        $name = $sanitizeEntryName($rawName);
        if ($name === null) {
            $zip->close();
            $removeDirectory($targetDir);
            return moduleInstallFailure('zip_invalid_path', "Zip contains invalid entry path: {$rawName}");
        }

        // Strip the top-level prefix if present
        if ($prefix !== '') {
            if (!str_starts_with($name, $prefix)) {
                $zip->close();
                $removeDirectory($targetDir);
                return moduleInstallFailure('zip_outside_module_root', "Zip contains files outside module root: {$name}");
            }
            $relativeName = substr($name, strlen($prefix));
        } else {
            $relativeName = $name;
        }

        if ($relativeName === '' || str_ends_with($relativeName, '/')) {
            if (!$isSafeZipEntryType($i, true)) {
                $zip->close();
                $removeDirectory($targetDir);
                return moduleInstallFailure('zip_unsupported_entry_type', "Zip contains unsupported directory entry type: {$name}");
            }
            // Directory
            @mkdir($targetDir . '/' . $relativeName, 0775, true);
            continue;
        }

        if (!$isSafeZipEntryType($i, false)) {
            $zip->close();
            $removeDirectory($targetDir);
            return moduleInstallFailure('zip_unsupported_entry_type', "Zip contains unsupported file entry type: {$name}");
        }

        $relativeName = ltrim(str_replace('\\\\', '/', $relativeName), '/');
        if ($relativeName === '' || str_contains($relativeName, "\0") || str_contains($relativeName, '../')) {
            $zip->close();
            $removeDirectory($targetDir);
            return moduleInstallFailure('zip_invalid_path', "Zip contains invalid file path: {$name}");
        }

        $fullPath = $targetDir . '/' . $relativeName;
        if (str_starts_with($fullPath, $targetRoot . DIRECTORY_SEPARATOR) === false) {
            $zip->close();
            $removeDirectory($targetDir);
            return moduleInstallFailure('zip_outside_module_root', "Zip entry escapes module root: {$name}");
        }

        $dir = dirname($fullPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $realDir = realpath($dir);
        if ($realDir === false) {
            $zip->close();
            $removeDirectory($targetDir);
            return moduleInstallFailure('target_dir_resolution_failed', "Failed resolving extraction directory: {$name}");
        }
        if (!($realDir === $targetRoot || str_starts_with($realDir, $targetRoot . DIRECTORY_SEPARATOR))) {
            $zip->close();
            $removeDirectory($targetDir);
            return moduleInstallFailure('zip_outside_module_root', "Zip extraction directory escapes module root: {$name}");
        }

        $contents = $zip->getFromIndex($i);
        if ($contents === false || file_put_contents($fullPath, $contents) === false) {
            $zip->close();
            $removeDirectory($targetDir);
            return moduleInstallFailure('zip_extraction_failed', "Failed extracting file: {$name}");
        }
    }
    $zip->close();

    $installManifest = $manifest;
    $installManifest['_path'] = $targetDir;
    $certification = validateModuleCertification($installManifest);
    if (empty($certification['ok'])) {
        $removeDirectory($targetDir);
        $failedChecks = array_values(array_map(
            static fn(array $check): string => (string)$check['check'] . ': ' . (string)$check['detail'],
            array_filter($certification['checks'], static fn(array $check): bool => empty($check['passed']))
        ));
        return moduleInstallFailure(
            'module_not_certified',
            'Module failed production certification: ' . implode('; ', $failedChecks),
            ['certification' => $certification]
        );
    }

    // Auto-enable the newly installed module if capability dependencies are satisfiable.
    // If not satisfiable, install succeeds but module remains disabled.
    $capCheck = validateModuleCapabilities($manifest);
    if (!empty($capCheck['ok'])) {
        $missing = [];
        foreach (($capCheck['depends'] ?? []) as $capId) {
            if (!app()->capabilities()->has((string)$capId)) {
                $missing[] = (string)$capId;
            }
        }
        if (empty($missing)) {
            enableModule($moduleId);
            return ['ok' => true, 'module_id' => $moduleId, 'enabled' => true, 'manifest' => $manifest];
        }

        // Ensure it is explicitly disabled (in case a default-enabled registry is present)
        disableModule($moduleId);
        return [
            'ok' => true,
            'module_id' => $moduleId,
            'enabled' => false,
            'manifest' => $manifest,
            'warning' => 'Module installed but not enabled: missing required capability providers',
            'missing' => $missing,
        ];
    }

    // Invalid capability manifest: install but keep disabled.
    disableModule($moduleId);
    return [
        'ok' => true,
        'module_id' => $moduleId,
        'enabled' => false,
        'manifest' => $manifest,
        'warning' => 'Module installed but not enabled: invalid capability manifest',
        'error' => $capCheck['error'] ?? 'invalid',
    ];
}

/**
 * Uninstall a module (remove files + disable).
 */
function uninstallModule(string $moduleId, array $options = []): array
{
    $dir = modulePathForId($moduleId) ?? '';
    if (!is_dir($dir)) {
        return ['ok' => false, 'error' => 'Module not found'];
    }

    $purge = !empty($options['purge']);
    $export = !empty($options['export']);
    $exportDir = is_string($options['export_dir'] ?? null) ? (string)$options['export_dir'] : null;

    $manifest = [];
    $manifestPath = $dir . '/module.json';
    if (is_file($manifestPath)) {
        $m = json_decode((string)file_get_contents($manifestPath), true);
        $manifest = is_array($m) ? $m : [];
    }

    // Disable first
    disableModule($moduleId);

    $exportResult = null;
    if ($purge && $export) {
        $exportResult = exportModuleOwnedTables($moduleId, $manifest, $exportDir);
        if (empty($exportResult['ok'])) {
            return $exportResult;
        }
    }

    if ($purge) {
        try {
            $pdo = app()->db();
            $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
            $tables = $manifest['owns_tables'] ?? [];

            $dropList = [];
            if (is_array($tables)) {
                foreach ($tables as $t) {
                    if (!is_string($t) || trim($t) === '') continue;
                    $dropList[] = trim($t);
                }
            }

            $dropList = array_values(array_unique($dropList));
            foreach ($dropList as $t) {
                if (!preg_match('/^[A-Za-z0-9_]+$/', $t)) {
                    continue;
                }
                $pdo->exec("DROP TABLE IF EXISTS `{$t}`");
            }

            // Remove migration tracking
            $stmt = $pdo->prepare("DELETE FROM _migrations WHERE module = ?");
            $stmt->execute([$moduleId]);
            $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        } catch (Throwable $e) {
            try {
                app()->db()->exec('SET FOREIGN_KEY_CHECKS=1');
            } catch (Throwable $e2) {
            }
            return ['ok' => false, 'error' => 'Purge failed: ' . $e->getMessage()];
        }
    }

    // Recursively remove
    $it = new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS);
    $files = new \RecursiveIteratorIterator($it, \RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        if ($file->isDir()) {
            @rmdir($file->getRealPath());
        } else {
            @unlink($file->getRealPath());
        }
    }
    @rmdir($dir);

    $res = ['ok' => true];
    if (is_array($exportResult)) {
        $res['export'] = $exportResult;
    }
    return $res;
}

/**
 * Update a provider module's capability policy (allow_callers) and validate.
 *
 * This edits module.json on disk in an atomic, validated way and does NOT
 * enable/disable the module. It is intended for admin APIs.
 */
function updateModuleCapabilityPolicy(string $moduleId, string $capabilityId, array $allowCallers): array
{
    $moduleId = trim($moduleId);
    $capabilityId = trim($capabilityId);
    if ($moduleId === '' || $capabilityId === '') {
        return ['ok' => false, 'error' => 'moduleId and capabilityId are required'];
    }

    $manifestPath = moduleManifestPathForId($moduleId);
    if ($manifestPath === null) {
        return ['ok' => false, 'error' => 'Module manifest not found'];
    }

    $raw = file_get_contents($manifestPath);
    $manifest = json_decode((string)$raw, true);
    if (!is_array($manifest)) {
        return ['ok' => false, 'error' => 'Module manifest is not valid JSON'];
    }

    // Normalise allowCallers to a clean list of module ids (lowercase, unique).
    $clean = [];
    foreach ($allowCallers as $id) {
        if (!is_string($id)) {
            continue;
        }
        $id = trim(strtolower($id));
        if ($id === '') {
            continue;
        }
        // Keep same id rules as manifest validation (lowercase, alnum, hyphen)
        if (!preg_match('/^[a-z0-9]([a-z0-9\-]*[a-z0-9])?$/', $id)) {
            continue;
        }
        $clean[$id] = true;
    }
    $allowCallers = array_keys($clean);

    // Safety guardrails for critical capabilities: never allow an admin to
    // accidentally lock out the provider itself or the kernel from calling
    // core kernel capabilities.
    //
    // For kernel.auth.authenticate@1 specifically, we always ensure that:
    // - the provider module (moduleId) can call its own auth provider
    // - the kernel can call it for OS/API login
    if ($capabilityId === 'kernel.auth.authenticate@1') {
        $allowCallers[] = strtolower($moduleId);
        $allowCallers[] = 'kernel';
        // De-duplicate while preserving normalised ids
        $allowCallers = array_values(array_unique($allowCallers));
    }

    if (!isset($manifest['capabilities']) || !is_array($manifest['capabilities'])) {
        $manifest['capabilities'] = [];
    }
    if (!isset($manifest['capabilities']['policy']) || !is_array($manifest['capabilities']['policy'])) {
        $manifest['capabilities']['policy'] = [];
    }
    if (!isset($manifest['capabilities']['policy']['capabilities']) || !is_array($manifest['capabilities']['policy']['capabilities'])) {
        $manifest['capabilities']['policy']['capabilities'] = [];
    }

    $capPolicies = $manifest['capabilities']['policy']['capabilities'];
    $capPolicy = $capPolicies[$capabilityId] ?? [];
    if (!is_array($capPolicy)) {
        $capPolicy = [];
    }

    $capPolicy['allow_callers'] = $allowCallers;
    $capPolicies[$capabilityId] = $capPolicy;
    $manifest['capabilities']['policy']['capabilities'] = $capPolicies;

    // Validate resulting capability manifest using existing validator.
    $capCheck = validateModuleCapabilities($manifest);
    if (empty($capCheck['ok'])) {
        return [
            'ok' => false,
            'error' => $capCheck['error'] ?? 'Capability manifest validation failed',
        ];
    }

    // Atomic write: write to temp file then rename over original.
    $tmpPath = $manifestPath . '.tmp';
    $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return ['ok' => false, 'error' => 'Failed to encode manifest JSON'];
    }
    if (file_put_contents($tmpPath, $json) === false) {
        return ['ok' => false, 'error' => 'Failed to write temporary manifest'];
    }
    if (!@rename($tmpPath, $manifestPath)) {
        @unlink($tmpPath);
        return ['ok' => false, 'error' => 'Failed to persist manifest changes'];
    }

    return ['ok' => true, 'module_id' => $moduleId, 'capability_id' => $capabilityId, 'allow_callers' => $allowCallers];
}

/**
 * Update a caller module's capabilities.depends list and validate.
 *
 * This edits module.json on disk in an atomic, validated way and does NOT
 * enable/disable the module. It is intended for admin APIs.
 *
 * @param string   $moduleId Module id whose manifest should be updated
 * @param string[] $depends  List of capability ids the module depends on
 */
function updateModuleCapabilityDepends(string $moduleId, array $depends): array
{
    $moduleId = trim($moduleId);
    if ($moduleId === '') {
        return ['ok' => false, 'error' => 'moduleId is required'];
    }

    $manifestPath = moduleManifestPathForId($moduleId);
    if ($manifestPath === null) {
        return ['ok' => false, 'error' => 'Module manifest not found'];
    }

    $raw = file_get_contents($manifestPath);
    $manifest = json_decode((string)$raw, true);
    if (!is_array($manifest)) {
        return ['ok' => false, 'error' => 'Module manifest is not valid JSON'];
    }

    // Normalise depends to a clean list of capability ids.
    $clean = [];
    foreach ($depends as $capId) {
        if (!is_string($capId)) {
            continue;
        }
        $capId = trim($capId);
        if ($capId === '') {
            continue;
        }
        if (!isValidCapabilityId($capId)) {
            continue;
        }
        $clean[$capId] = true;
    }
    $depends = array_values(array_keys($clean));

    if (!isset($manifest['capabilities']) || !is_array($manifest['capabilities'])) {
        $manifest['capabilities'] = [];
    }
    $manifest['capabilities']['depends'] = $depends;

    // Validate resulting capability manifest using existing validator.
    $capCheck = validateModuleCapabilities($manifest);
    if (empty($capCheck['ok'])) {
        return [
            'ok' => false,
            'error' => $capCheck['error'] ?? 'Capability manifest validation failed',
        ];
    }

    // Atomic write: write to temp file then rename over original.
    $tmpPath = $manifestPath . '.tmp';
    $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return ['ok' => false, 'error' => 'Failed to encode manifest JSON'];
    }
    if (file_put_contents($tmpPath, $json) === false) {
        return ['ok' => false, 'error' => 'Failed to write temporary manifest'];
    }
    if (!@rename($tmpPath, $manifestPath)) {
        @unlink($tmpPath);
        return ['ok' => false, 'error' => 'Failed to persist manifest changes'];
    }

    return ['ok' => true, 'module_id' => $moduleId, 'depends' => $depends];
}

// ─── Read Contract Registry Integration ───────────────────────────────────

/**
 * Register read contracts and deprecated reads for all enabled modules.
 * Called from getEnabledModules() after capability validation passes.
 *
 * @param array<string, array<string, mixed>> $enabledModules
 */
function kernelRegisterModuleReadContracts(array $enabledModules): void
{
    $registry = \Ikabud\Kernel\Contracts\ReadContractRegistry::getInstance();

    \Ikabud\Kernel\Database\KernelPDO::kernelEscalationEnter();
    try {
        $db = app()->db();

        foreach ($enabledModules as $moduleId => $manifest) {
            // Register read contracts from reads_tables
            $readsTables = is_array($manifest['reads_tables'] ?? null) ? $manifest['reads_tables'] : [];
            foreach ($readsTables as $tableName) {
                if (is_string($tableName) && trim($tableName) !== '') {
                    $registry->registerReadContract($moduleId, trim($tableName), $db);
                }
            }

            // Register deprecated reads from reads_tables_deprecated
            $deprecatedReads = is_array($manifest['reads_tables_deprecated'] ?? null) ? $manifest['reads_tables_deprecated'] : [];
            foreach ($deprecatedReads as $tableName) {
                if (is_string($tableName) && trim($tableName) !== '') {
                    $registry->markDeprecatedRead($moduleId, trim($tableName));
                }
            }
        }
    } catch (\Throwable $e) {
        if (function_exists('write_log')) {
            write_log(
                'ReadContractRegistry: failed to register read contracts: ' . $e->getMessage(),
                'warning',
                ['exception' => get_class($e)]
            );
        }
    } finally {
        \Ikabud\Kernel\Database\KernelPDO::kernelEscalationLeave();
    }
}

/**
 * Check for schema drift in registered read contracts.
 * Called from loadModuleRoutes() after all modules are loaded.
 * Logs warnings for drift; does not throw or crash.
 */
function kernelCheckReadContractDrift(): void
{
    $registry = \Ikabud\Kernel\Contracts\ReadContractRegistry::getInstance();

    \Ikabud\Kernel\Database\KernelPDO::kernelEscalationEnter();
    try {
        $db = app()->db();
        $registry->checkDrift($db);
    } catch (\Throwable $e) {
        if (function_exists('write_log')) {
            write_log(
                'ReadContractRegistry: drift check failed: ' . $e->getMessage(),
                'warning',
                ['exception' => get_class($e)]
            );
        }
    } finally {
        \Ikabud\Kernel\Database\KernelPDO::kernelEscalationLeave();
    }
}
