<?php

declare(strict_types=1);

// ─── Paths ────────────────────────────────────────────────────────────────

function modulesPath(): string
{
    return BASE_PATH . '/modules';
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
        $cached = app()->cache()->get('kernel_bootstrap', 'module_auth_cookies:v1');
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

    foreach (scandir($dir) as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        if (preg_match('/\.bak_\d{8}_\d{6}$/', $entry)) {
            continue;
        }

        $manifestPath = $dir . '/' . $entry . '/module.json';
        if (!is_file($manifestPath)) {
            continue;
        }

        $manifest = json_decode((string)file_get_contents($manifestPath), true);
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
        app()->cache()->set('kernel_bootstrap', 'module_auth_cookies:v1', ['names' => $names], $ttl);
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
    if (isset($GLOBALS[$cacheKey])) {
        return; // Already loaded
    }

    $tenantId = moduleTenantSettingsTenantId();
    if ($tenantId === null) {
        $GLOBALS[$cacheKey] = [];
        return;
    }

    $GLOBALS['_kernel_db_unguarded'] = true;
    try {
        $db = app()->db();
        if (!moduleTenantSettingsEnsureTable($db)) {
            $GLOBALS[$cacheKey] = [];
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

        $GLOBALS[$cacheKey] = $cache;
    } catch (Throwable $e) {
        $GLOBALS[$cacheKey] = [];
    } finally {
        $GLOBALS['_kernel_db_unguarded'] = false;
    }
}

/**
 * Invalidate the per-request module settings cache (after a save).
 */
function invalidateTenantModuleSettingsCache(): void
{
    unset($GLOBALS['_tenant_module_settings_cache']);
}

/**
 * Single-module DB read (no cache).
 * @internal
 */
function _readTenantModuleSettingsSingle(string $moduleId, int $tenantId, ?PDO $dbOverride = null): array
{
    $GLOBALS['_kernel_db_unguarded'] = true;
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
        $GLOBALS['_kernel_db_unguarded'] = false;
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
    $GLOBALS['_kernel_db_unguarded'] = true;
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
        $GLOBALS['_kernel_db_unguarded'] = false;
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

    $GLOBALS['_kernel_db_unguarded'] = true;
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
        $GLOBALS['_kernel_db_unguarded'] = false;
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

// ─── Registry (enabled / disabled) ────────────────────────────────────────

function readModuleRegistry(): array
{
    $path = moduleRegistryPath();
    if (!is_file($path)) {
        return [];
    }
    $data = json_decode((string) file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

function writeModuleRegistry(array $registry): void
{
    $path = moduleRegistryPath();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    file_put_contents($path, json_encode($registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

function isModuleEnabled(string $moduleId): bool
{
    // In multi-tenant mode, check per-tenant override first.
    if (moduleTenantSettingsModeEnabled()) {
        $tenantId = moduleTenantSettingsTenantId();
        if ($tenantId !== null) {
            $tenantSettings = readTenantModuleSettings($moduleId);
            if (array_key_exists('_module_enabled', $tenantSettings)) {
                return (bool) $tenantSettings['_module_enabled'];
            }
        }
    }

    // Fall back to global registry.
    $registry = readModuleRegistry();
    if (empty($registry)) {
        return true;
    }
    return !empty($registry[$moduleId]['enabled']);
}

/**
 * Check if a module is enabled for an explicit tenant ID (superadmin use).
 * Checks per-tenant override first, then falls back to global registry.
 */
function isModuleEnabledForTenant(string $moduleId, int $tenantId): bool
{
    $tenantSettings = readTenantModuleSettingsForTenant($moduleId, $tenantId);
    if (array_key_exists('_module_enabled', $tenantSettings)) {
        return (bool) $tenantSettings['_module_enabled'];
    }

    // No per-tenant override — fall back to global registry.
    $registry = readModuleRegistry();
    if (empty($registry)) {
        return true;
    }
    return !empty($registry[$moduleId]['enabled']);
}

/**
 * Enable a module for an explicit tenant ID (superadmin use).
 */
function enableModuleForTenant(string $moduleId, int $tenantId): void
{
    saveTenantModuleSettingsForTenant($moduleId, $tenantId, ['_module_enabled' => true]);
}

/**
 * Disable a module for an explicit tenant ID (superadmin use).
 */
function disableModuleForTenant(string $moduleId, int $tenantId): void
{
    saveTenantModuleSettingsForTenant($moduleId, $tenantId, ['_module_enabled' => false]);
}

function enableModule(string $moduleId): void
{
    unset($GLOBALS['_kernel_discovered_modules']);

    // In multi-tenant mode, persist per-tenant override.
    if (moduleTenantSettingsModeEnabled()) {
        $tenantId = moduleTenantSettingsTenantId();
        if ($tenantId !== null) {
            saveTenantModuleSettings($moduleId, ['_module_enabled' => true]);
            return;
        }
        // Tenant mode active but tenant ID unresolved — refuse global fallback.
        write_log(
            "enableModule: tenant mode active but tenant ID unresolved — refusing global fallback for module '{$moduleId}'",
            'warning',
            ['module' => $moduleId]
        );
        return;
    }

    // Single-tenant / CLI: write to global registry.
    $registry = readModuleRegistry();
    $registry[$moduleId] = array_merge($registry[$moduleId] ?? [], [
        'enabled' => true,
        'enabled_at' => date('Y-m-d H:i:s'),
    ]);
    writeModuleRegistry($registry);
    kernelFlushCodeCaches();
}

function disableModule(string $moduleId): void
{
    unset($GLOBALS['_kernel_discovered_modules']);

    // In multi-tenant mode, persist per-tenant override.
    if (moduleTenantSettingsModeEnabled()) {
        $tenantId = moduleTenantSettingsTenantId();
        if ($tenantId !== null) {
            saveTenantModuleSettings($moduleId, ['_module_enabled' => false]);
            return;
        }
        // Tenant mode active but tenant ID unresolved — refuse global fallback.
        write_log(
            "disableModule: tenant mode active but tenant ID unresolved — refusing global fallback for module '{$moduleId}'",
            'warning',
            ['module' => $moduleId]
        );
        return;
    }

    // Single-tenant / CLI: write to global registry.
    $registry = readModuleRegistry();
    $registry[$moduleId] = array_merge($registry[$moduleId] ?? [], [
        'enabled' => false,
        'disabled_at' => date('Y-m-d H:i:s'),
    ]);
    writeModuleRegistry($registry);
    kernelFlushCodeCaches();
}

// ─── Module Settings ──────────────────────────────────────────────────────

/**
 * Read settings for a specific module.
 *
 * In multi-tenant mode, only kernel-lifecycle keys (e.g. allow_kernel_admin)
 * are read from the global registry; all other settings come from the
 * tenant-scoped DB table.  In single-tenant mode, the global registry is
 * the sole source.
 *
 * @return array<string, mixed>
 */
function getModuleSettings(string $moduleId): array
{
    $registry = readModuleRegistry();
    $global = $registry[$moduleId]['settings'] ?? [];
    if (!is_array($global)) {
        $global = [];
    }

    if (moduleTenantSettingsModeEnabled()) {
        // In multi-tenant mode, only allow lifecycle/admin keys from global.
        // Everything else must come from per-tenant storage so tenants
        // cannot see each other's settings.
        $lifecycleKeys = ['allow_kernel_admin'];
        $safeGlobal = array_intersect_key($global, array_flip($lifecycleKeys));

        $tenant = readTenantModuleSettings($moduleId);
        // Internal metadata keys are prefixed with "_" and must never leak
        // into module-facing settings payloads.
        foreach (array_keys($tenant) as $tenantKey) {
            if (is_string($tenantKey) && str_starts_with($tenantKey, '_')) {
                unset($tenant[$tenantKey]);
            }
        }
        return array_merge($safeGlobal, $tenant);
    }

    return $global;
}

/**
 * Save settings for a specific module into the registry.
 *
 * @param array<string, mixed> $settings
 */
function saveModuleSettings(string $moduleId, array $settings): void
{
    if (saveTenantModuleSettings($moduleId, $settings)) {
        return;
    }

    // Tenant mode is enabled but the tenant ID could not be resolved.
    // Do NOT fall through to global modules.json because that leaks
    // tenant-specific settings to every other tenant.
    if (moduleTenantSettingsModeEnabled()) {
        if (function_exists('write_log')) {
            write_log(
                "saveModuleSettings: tenant mode active but tenant ID unresolved — "
                . "refusing global fallback for module '{$moduleId}'",
                'warning',
                ['module' => $moduleId, 'keys' => array_keys($settings)]
            );
        }
        return;
    }

    // Single-tenant / non-tenant mode: persist to global registry.
    $registry = readModuleRegistry();
    $existing = [];
    if (isset($registry[$moduleId]['settings']) && is_array($registry[$moduleId]['settings'])) {
        $existing = $registry[$moduleId]['settings'];
    }
    $registry[$moduleId] = array_merge($registry[$moduleId] ?? [], [
        'settings' => array_merge($existing, $settings),
    ]);
    writeModuleRegistry($registry);
}

function moduleSettingsEditableInCurrentContext(): bool
{
    if (!moduleTenantSettingsModeEnabled()) {
        return true;
    }

    return moduleTenantSettingsTenantId() !== null;
}

/**
 * @return array<int, array<string, mixed>>
 */
function moduleEditableSettingsFields(array $manifest): array
{
    $fields = is_array($manifest['settings_fields'] ?? null) ? array_values($manifest['settings_fields']) : [];
    if (empty($fields)) {
        return [];
    }

    if (!moduleSettingsEditableInCurrentContext()) {
        return [];
    }

    return $fields;
}

// ─── Runtime Module Health State ──────────────────────────────────────────

/** @var array<string, array{module: string, reason: string, context: array<string, mixed>}> */
$GLOBALS['_kernel_skipped_modules'] = $GLOBALS['_kernel_skipped_modules'] ?? [];

function resetSkippedModules(): void
{
    $GLOBALS['_kernel_skipped_modules'] = [];
}

/**
 * @param array<string, mixed> $context
 */
function recordSkippedModule(string $moduleId, string $reason, array $context = []): void
{
    $GLOBALS['_kernel_skipped_modules'][$moduleId] = [
        'module' => $moduleId,
        'reason' => $reason,
        'context' => $context,
    ];
}

/**
 * @return array<string, array{module: string, reason: string, context: array<string, mixed>}>
 */
function getSkippedModules(): array
{
    $skipped = $GLOBALS['_kernel_skipped_modules'] ?? [];
    return is_array($skipped) ? $skipped : [];
}

function moduleIsLoadable(string $moduleId): bool
{
    $moduleId = trim($moduleId);
    if ($moduleId === '') {
        return false;
    }

    $enabled = getEnabledModules();
    return isset($enabled[$moduleId]);
}

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
    foreach (scandir($dir) as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        // Ignore installer-created backup directories (e.g. contact-form.bak_20260317_210719)
        // so they do not shadow the live module directory by reusing the same manifest id.
        if (preg_match('/\.bak_\d{8}_\d{6}$/', $entry)) {
            continue;
        }

        $manifestPath = $dir . '/' . $entry . '/module.json';
        if (!is_file($manifestPath)) {
            continue;
        }

        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        if (!is_array($manifest) || empty($manifest['id'])) {
            continue;
        }

        $manifest['_path'] = $dir . '/' . $entry;
        $manifest['_enabled'] = isModuleEnabled($manifest['id']);
        $result[$manifest['id']] = $manifest;
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
    $cached = $safe;
    return $cached;
}

/**
 * Return kernel migration files that are safe to run against tenant databases.
 * Control-plane schema must stay in the control DB only.
 *
 * @return array<int, string>
 */
function tenantSafeKernelMigrationFiles(): array
{
    $artifacts = [
        '001_kernel_events_and_triggers.sql' => BASE_PATH . '/migrations/001_kernel_events_and_triggers.sql',
        '006_kernel_workflow_tables.sql' => BASE_PATH . '/database/migrations/006_kernel_workflow_tables.sql',
        '007_kernel_runtime_tables.sql' => BASE_PATH . '/database/migrations/007_kernel_runtime_tables.sql',
    ];

    $files = [];
    foreach ($artifacts as $artifactName => $fullPath) {
        if (is_file($fullPath)) {
            $files[] = $artifactName;
        }
    }

    return $files;
}

/**
 * Build the module migration plan for a tenant database.
 *
 * Rules:
 * - If there is no entry module, keep legacy behavior and include all enabled modules.
 * - If an entry module exists, include:
 *   1. the entry module itself
 *   2. modules that expose capabilities consumed by the current closure
 *   3. modules that explicitly allow the entry/closure modules as callers
 *   4. modules that hook into the entry module namespace (for example `cms.*`)
 *
 * Only modules with declared migrations are returned.
 *
 * @return array<int, string>
 */
function tenantProvisionModulePlan(?string $entryModuleId): array
{
    $enabled = getEnabledModules();
    if (empty($enabled)) {
        return [];
    }

    $entryModuleId = trim((string)$entryModuleId);
    if ($entryModuleId === '') {
        $all = [];
        foreach ($enabled as $moduleId => $manifest) {
            if (!empty($manifest['migrations']) && is_array($manifest['migrations'])) {
                $all[] = (string)$moduleId;
            }
        }
        sort($all);
        return $all;
    }

    $exposesByCapability = [];
    foreach ($enabled as $moduleId => $manifest) {
        $exposes = $manifest['capabilities']['exposes'] ?? [];
        if (!is_array($exposes)) {
            continue;
        }
        foreach ($exposes as $expose) {
            if (!is_array($expose)) {
                continue;
            }
            $capabilityId = trim((string)($expose['id'] ?? ''));
            if ($capabilityId === '') {
                continue;
            }
            if (!isset($exposesByCapability[$capabilityId])) {
                $exposesByCapability[$capabilityId] = [];
            }
            $exposesByCapability[$capabilityId][] = (string)$moduleId;
        }
    }

    $selected = [$entryModuleId => true];
    $queue = [$entryModuleId];

    while (!empty($queue)) {
        $current = array_shift($queue);
        if (!is_string($current) || !isset($enabled[$current])) {
            continue;
        }

        $manifest = $enabled[$current];

        $depends = $manifest['capabilities']['depends'] ?? [];
        if (is_array($depends)) {
            foreach ($depends as $capabilityId) {
                $capabilityId = trim((string)$capabilityId);
                if ($capabilityId === '') {
                    continue;
                }
                foreach ($exposesByCapability[$capabilityId] ?? [] as $providerModuleId) {
                    if (!isset($selected[$providerModuleId])) {
                        $selected[$providerModuleId] = true;
                        $queue[] = $providerModuleId;
                    }
                }
            }
        }

        $legacyConsumes = $manifest['consumes'] ?? [];
        if (is_array($legacyConsumes)) {
            foreach ($legacyConsumes as $capabilityId) {
                $capabilityId = trim((string)$capabilityId);
                if ($capabilityId === '') {
                    continue;
                }
                foreach ($exposesByCapability[$capabilityId] ?? [] as $providerModuleId) {
                    if (!isset($selected[$providerModuleId])) {
                        $selected[$providerModuleId] = true;
                        $queue[] = $providerModuleId;
                    }
                }
            }
        }

        foreach ($enabled as $moduleId => $candidate) {
            if (isset($selected[$moduleId])) {
                continue;
            }

            $hooks = $candidate['hooks'] ?? [];
            if (is_array($hooks)) {
                foreach ($hooks as $hookName) {
                    $hookName = trim((string)$hookName);
                    if ($hookName !== '' && str_starts_with($hookName, $current . '.')) {
                        $selected[$moduleId] = true;
                        $queue[] = $moduleId;
                        continue 2;
                    }
                }
            }

            $policy = $candidate['capabilities']['policy']['capabilities'] ?? [];
            if (!is_array($policy)) {
                continue;
            }
            foreach ($policy as $rule) {
                if (!is_array($rule)) {
                    continue;
                }
                $allowCallers = $rule['allow_callers'] ?? [];
                if (!is_array($allowCallers)) {
                    continue;
                }
                foreach ($allowCallers as $caller) {
                    if ((string)$caller === $current) {
                        $selected[$moduleId] = true;
                        $queue[] = $moduleId;
                        continue 3;
                    }
                }
            }
        }
    }

    $planned = [];
    foreach (array_keys($selected) as $moduleId) {
        if (!isset($enabled[$moduleId])) {
            continue;
        }
        $manifest = $enabled[$moduleId];
        if (!empty($manifest['migrations']) && is_array($manifest['migrations'])) {
            $planned[] = (string)$moduleId;
        }
    }

    sort($planned);
    return $planned;
}

function tenantEntryModuleIdForTenant(int $tenantId): ?string
{
    if ($tenantId <= 0) {
        return null;
    }

    try {
        $stmt = app()->controlDb()->prepare('SELECT entry_module_id FROM kernel_tenants WHERE id = :tenant_id LIMIT 1');
        $stmt->execute([':tenant_id' => $tenantId]);
        $value = $stmt->fetchColumn();
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        return $value !== '' ? $value : null;
    } catch (Throwable $e) {
        return null;
    }
}

function tenantEnsureMigrationTrackingTable(PDO $db): void
{
    static $ensured = [];
    $key = spl_object_id($db);
    if (isset($ensured[$key])) {
        return;
    }
    $db->exec(
        'CREATE TABLE IF NOT EXISTS `_migrations` ('
        . 'id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, '
        . 'module VARCHAR(80) NOT NULL, '
        . 'migration VARCHAR(255) NOT NULL, '
        . 'batch INT UNSIGNED NOT NULL DEFAULT 1, '
        . 'executed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, '
        . 'UNIQUE KEY uq_module_migration (module, migration)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    $ensured[$key] = true;
}

function tenantAppliedModuleMigrations(PDO $db, string $moduleId): array
{
    tenantEnsureMigrationTrackingTable($db);

    try {
        $stmt = $db->prepare('SELECT migration FROM _migrations WHERE module = :module');
        $stmt->execute([':module' => $moduleId]);
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $applied = [];
        foreach ($rows as $row) {
            $name = trim((string)$row);
            if ($name !== '') {
                $applied[$name] = true;
            }
        }
        return $applied;
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Batch-load all applied migrations for all modules in one query.
 * Returns ['moduleId' => ['migration_name' => true, ...], ...].
 */
function tenantAllAppliedMigrations(PDO $db): array
{
    tenantEnsureMigrationTrackingTable($db);

    try {
        $stmt = $db->query('SELECT module, migration FROM _migrations');
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $result = [];
        foreach ($rows as $row) {
            $mod = trim((string)($row['module'] ?? ''));
            $mig = trim((string)($row['migration'] ?? ''));
            if ($mod !== '' && $mig !== '') {
                $result[$mod][$mig] = true;
            }
        }
        return $result;
    } catch (Throwable $e) {
        return [];
    }
}

function tenantRecordModuleMigration(PDO $db, string $moduleId, string $migrationName): void
{
    tenantEnsureMigrationTrackingTable($db);

    $batchStmt = $db->prepare('SELECT COALESCE(MAX(batch), 0) + 1 FROM _migrations WHERE module = :module');
    $batchStmt->execute([':module' => $moduleId]);
    $batch = (int)$batchStmt->fetchColumn();
    if ($batch <= 0) {
        $batch = 1;
    }

    $stmt = $db->prepare('INSERT IGNORE INTO _migrations (module, migration, batch) VALUES (:module, :migration, :batch)');
    $stmt->execute([
        ':module' => $moduleId,
        ':migration' => $migrationName,
        ':batch' => $batch,
    ]);
}

function tenantSyncKernelMigrations(PDO $db, ?array $preloadedApplied = null): array
{
    $artifacts = [
        '001_kernel_events_and_triggers.sql' => BASE_PATH . '/migrations/001_kernel_events_and_triggers.sql',
        '006_kernel_workflow_tables.sql' => BASE_PATH . '/database/migrations/006_kernel_workflow_tables.sql',
        '007_kernel_runtime_tables.sql' => BASE_PATH . '/database/migrations/007_kernel_runtime_tables.sql',
    ];

    $applied = $preloadedApplied !== null ? ($preloadedApplied['_kernel'] ?? []) : tenantAppliedModuleMigrations($db, '_kernel');
    $executed = [];

    foreach ($artifacts as $artifactName => $fullPath) {
        if (isset($applied[$artifactName]) || !is_file($fullPath)) {
            continue;
        }

        $sql = (string) file_get_contents($fullPath);
        if (trim($sql) === '') {
            continue;
        }

        $db->exec($sql);
        tenantRecordModuleMigration($db, '_kernel', $artifactName);
        $applied[$artifactName] = true;
        $executed[] = $artifactName;
    }

    return $executed;
}

function applyModuleSqlArtifacts(PDO $db, string $moduleId, string $manifestKey, ?array $manifest = null, string $trackingPrefix = '', ?array $preloadedApplied = null): array
{
    $moduleId = trim($moduleId);
    if ($moduleId === '') {
        return [];
    }

    if ($manifest === null) {
        $allModules = discoverModules();
        $manifest = $allModules[$moduleId] ?? null;
    }
    if (!is_array($manifest)) {
        return [];
    }

    $declared = $manifest[$manifestKey] ?? [];
    if (!is_array($declared) || $declared === []) {
        return [];
    }

    $modulePath = rtrim((string)($manifest['_path'] ?? ''), '/');
    if ($modulePath === '') {
        return [];
    }

    $applied = $preloadedApplied !== null ? ($preloadedApplied[$moduleId] ?? []) : tenantAppliedModuleMigrations($db, $moduleId);
    $executed = [];

    foreach ($declared as $artifactPath) {
        $artifactPath = ltrim((string)$artifactPath, '/');
        if ($artifactPath === '') {
            continue;
        }

        $artifactName = $trackingPrefix . basename($artifactPath);
        if (isset($applied[$artifactName])) {
            continue;
        }

        $fullPath = BASE_PATH . '/' . $artifactPath;
        if (!is_file($fullPath)) {
            $fullPath = $modulePath . '/' . $artifactPath;
        }
        if (!is_file($fullPath)) {
            continue;
        }

        $sql = (string)file_get_contents($fullPath);
        if (trim($sql) !== '') {
            $db->exec($sql);
        }

        tenantRecordModuleMigration($db, $moduleId, $artifactName);
        $applied[$artifactName] = true;
        $executed[] = $artifactName;
    }

    return $executed;
}

function tenantSyncModuleMigrations(PDO $db, string $moduleId, ?array $manifest = null, ?array $preloadedApplied = null): array
{
    return applyModuleSqlArtifacts($db, $moduleId, 'migrations', $manifest, '', $preloadedApplied);
}

function tenantSyncModuleSeeds(PDO $db, string $moduleId, ?array $manifest = null, ?array $preloadedApplied = null): array
{
    return applyModuleSqlArtifacts($db, $moduleId, 'seeds', $manifest, 'seed:', $preloadedApplied);
}

function syncTenantMigrationsForTenant(int $tenantId, ?string $entryModuleId = null): array
{
    if ($tenantId <= 0) {
        return ['ok' => false, 'error' => 'Invalid tenant ID'];
    }

    $db = app()->dbForTenant($tenantId);
    if ($db === null) {
        return ['ok' => false, 'error' => 'Tenant DB connection unavailable'];
    }

    $entryModuleId = $entryModuleId !== null ? trim($entryModuleId) : tenantEntryModuleIdForTenant($tenantId);
    $plannedModules = tenantProvisionModulePlan($entryModuleId !== '' ? $entryModuleId : null);
    $allModules = discoverModules();
    $results = [];

    try {
        // Batch-load all applied migrations in one query instead of per-module SELECTs
        $allApplied = tenantAllAppliedMigrations($db);

        $kernelApplied = tenantSyncKernelMigrations($db, $allApplied);
        if ($kernelApplied !== []) {
            $results['_kernel'] = $kernelApplied;
        }

        foreach ($plannedModules as $moduleId) {
            $manifest = $allModules[$moduleId] ?? null;
            if (!is_array($manifest)) {
                continue;
            }
            $executed = tenantSyncModuleMigrations($db, $moduleId, $manifest, $allApplied);
            $seeded = tenantSyncModuleSeeds($db, $moduleId, $manifest, $allApplied);
            $applied = array_merge($executed, $seeded);
            if ($applied !== []) {
                $results[$moduleId] = $applied;
            }
        }

        return [
            'ok' => true,
            'tenant_id' => $tenantId,
            'entry_module_id' => $entryModuleId !== '' ? $entryModuleId : null,
            'modules' => $results,
        ];
    } catch (Throwable $e) {
        return [
            'ok' => false,
            'error' => $e->getMessage(),
            'tenant_id' => $tenantId,
            'entry_module_id' => $entryModuleId !== '' ? $entryModuleId : null,
            'modules' => $results,
        ];
    }
}

function syncTenantMigrationsForCurrentRequest(): array
{
    static $done = null;
    if ($done !== null) {
        return $done;
    }

    if (!moduleTenantSettingsModeEnabled()) {
        $done = ['ok' => true, 'skipped' => 'tenant_mode_disabled'];
        return $done;
    }

    $tenantId = moduleTenantSettingsTenantId();
    if ($tenantId === null || $tenantId <= 0) {
        $done = ['ok' => true, 'skipped' => 'tenant_unresolved'];
        return $done;
    }

    $syncEnabledRaw = $_ENV['APP_REQUEST_TENANT_MIGRATION_SYNC'] ?? '1';
    $syncEnabled = filter_var($syncEnabledRaw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    if ($syncEnabled === false) {
        $done = ['ok' => true, 'skipped' => 'request_sync_disabled'];
        return $done;
    }

    $syncTtl = max(0, (int)($_ENV['APP_REQUEST_TENANT_MIGRATION_SYNC_TTL'] ?? 300));
    if (PHP_SAPI !== 'cli' && $syncTtl > 0) {
        $cacheKey = 'tenant_migration_sync:' . $tenantId;
        $cached = app()->cache()->get('kernel_tenant_request_sync', $cacheKey);
        if (is_array($cached) && !empty($cached['ok'])) {
            $done = [
                'ok' => true,
                'skipped' => 'recent_sync',
                'tenant_id' => $tenantId,
                'last_checked_at' => $cached['checked_at'] ?? null,
            ];
            return $done;
        }
    }

    $done = syncTenantMigrationsForTenant($tenantId);

    if (PHP_SAPI !== 'cli' && $syncTtl > 0 && !empty($done['ok'])) {
        app()->cache()->set(
            'kernel_tenant_request_sync',
            'tenant_migration_sync:' . $tenantId,
            [
                'ok' => true,
                'checked_at' => date('c'),
                'tenant_id' => $tenantId,
            ],
            $syncTtl
        );
    }

    return $done;
}

/**
 * Validate capabilities block in a module manifest.
 * Returns:
 *  - ['ok'=>true, 'exposes'=>array, 'depends'=>string[], 'policy'=>array]
 *  - ['ok'=>false, 'error'=>'...']
 */
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

function routePatternSegments(string $pattern): array
{
    $trimmed = trim($pattern, '/');
    if ($trimmed === '') {
        return [];
    }
    return array_values(array_filter(explode('/', $trimmed), static fn($seg) => $seg !== ''));
}

function routeSegmentIsDynamic(string $segment): bool
{
    return (bool) preg_match('/^\{[A-Za-z0-9_]+\}$/', $segment);
}

function routePatternsMayConflict(string $left, string $right): bool
{
    $leftSegments = routePatternSegments($left);
    $rightSegments = routePatternSegments($right);

    if (count($leftSegments) !== count($rightSegments)) {
        return false;
    }

    $hasDynamic = false;
    $segmentCount = count($leftSegments);
    for ($i = 0; $i < $segmentCount; $i++) {
        $l = $leftSegments[$i];
        $r = $rightSegments[$i];

        $lDynamic = routeSegmentIsDynamic($l);
        $rDynamic = routeSegmentIsDynamic($r);
        $hasDynamic = $hasDynamic || $lDynamic || $rDynamic;

        if (!$lDynamic && !$rDynamic && $l !== $r) {
            return false;
        }
    }

    return $hasDynamic;
}

function routePatternMatchPriority(string $pattern): array
{
    $segments = routePatternSegments($pattern);
    $segmentCount = count($segments);
    $staticCount = 0;
    $dynamicCount = 0;

    foreach ($segments as $segment) {
        if (routeSegmentIsDynamic($segment)) {
            $dynamicCount++;
        } else {
            $staticCount++;
        }
    }

    $typeRank = 2;
    if ($dynamicCount === 0) {
        $typeRank = 3;
    } elseif ($staticCount === 0) {
        $typeRank = 1;
    }

    return [$typeRank, $segmentCount, $staticCount, -$dynamicCount, $pattern];
}

function compareRoutePatternsForMatching(string $left, string $right): int
{
    $leftPriority = routePatternMatchPriority($left);
    $rightPriority = routePatternMatchPriority($right);

    $max = count($leftPriority);
    for ($i = 0; $i < $max; $i++) {
        $l = $leftPriority[$i];
        $r = $rightPriority[$i];
        if ($l === $r) {
            continue;
        }

        if ($i === $max - 1) {
            return strcmp((string)$l, (string)$r);
        }

        return $r <=> $l;
    }

    return 0;
}

// ─── Route Loading ────────────────────────────────────────────────────────

/**
 * Load routes from all ENABLED modules.
 * @param array<string, array<string, string>> $routes
 * @return array<string, array<string, string>>
 */
function loadModuleRoutes(array $routes): array
{
    $ambiguityMode = strtolower((string) config('app.modules.route_ambiguity_mode', 'warn'));

    // Track which module owns each route for conflict detection
    $routeOwners = [];
    $methodPatterns = [];
    foreach (['GET', 'POST', 'PUT', 'DELETE'] as $m) {
        $methodPatterns[$m] = [];
        foreach ($routes[$m] ?? [] as $pattern => $_) {
            $routeOwners[$m . ':' . $pattern] = '_kernel';
            $methodPatterns[$m][$pattern] = '_kernel';
        }
    }

    foreach (getEnabledModules() as $module) {
        loadModuleHelpers($module);

        // Register capability providers declared by the module.
        // Minimal v1 bridge: modules publish callables via helpers.php.
        // A module may expose:
        //  - $capability_handlers array in global scope (preferred)
        //  - or functions named: <moduleId>_cap_<sanitizedCapabilityId>
        $capCheck = validateModuleCapabilities($module);
        if (!empty($capCheck['ok']) && !empty($capCheck['exposes'])) {
            $moduleId = (string)($module['id'] ?? '');
            $policy = is_array($capCheck['policy'] ?? null) ? $capCheck['policy'] : [];
            $helpersFile = (string)($module['_path'] ?? '') . '/helpers.php';

            // Pull handler map if module provided one
            $handlersMap = [];
            $handlersMapOrigin = [
                'type' => 'none',
                'provider' => $moduleId,
                'module' => $moduleId,
                'file' => $helpersFile,
                'symbol' => null,
            ];
            $modulePrefix = preg_replace('/[^a-z0-9]+/i', '_', $moduleId);
            $handlersExportFn = $modulePrefix . '_capability_handlers';
            if (function_exists($handlersExportFn)) {
                $resolvedHandlersMap = $handlersExportFn();
                if (is_array($resolvedHandlersMap)) {
                    $handlersMap = $resolvedHandlersMap;
                    $handlersMapOrigin = [
                        'type' => 'export_function',
                        'provider' => $moduleId,
                        'module' => $moduleId,
                        'file' => $helpersFile,
                        'symbol' => $handlersExportFn,
                    ];
                }
            } elseif (!empty($module['capability_handlers']) && is_array($module['capability_handlers'])) {
                $handlersMap = $module['capability_handlers'];
                $handlersMapOrigin = [
                    'type' => 'module_handlers_map',
                    'provider' => $moduleId,
                    'module' => $moduleId,
                    'file' => $helpersFile,
                    'symbol' => 'capability_handlers',
                ];
            } elseif (isset($GLOBALS['capability_handlers']) && is_array($GLOBALS['capability_handlers'])) {
                // Backward-compatible: module may have declared a global $capability_handlers
                $handlersMap = $GLOBALS['capability_handlers'];
                $handlersMapOrigin = [
                    'type' => 'global_handlers_map',
                    'provider' => $moduleId,
                    'module' => $moduleId,
                    'file' => $helpersFile,
                    'symbol' => '$GLOBALS[capability_handlers]',
                ];
            }

            foreach ($capCheck['exposes'] as $exp) {
                $capId = (string)($exp['id'] ?? '');
                if ($capId === '') continue;

                $priority = (int)($exp['priority'] ?? 10);
                $modes = is_array($exp['modes'] ?? null) ? $exp['modes'] : ['first'];

                $callable = null;
                $schema = is_array($exp['schema'] ?? null) ? $exp['schema'] : null;
                $origin = $handlersMapOrigin;
                if (isset($handlersMap[$capId]) && is_callable($handlersMap[$capId])) {
                    $callable = $handlersMap[$capId];
                } else {
                    $san = preg_replace('/[^a-z0-9]+/i', '_', $capId);
                    $fn = $modulePrefix . '_cap_' . strtolower(trim((string)$san, '_'));
                    if (function_exists($fn)) {
                        $callable = $fn;
                        $origin = [
                            'type' => 'naming_convention',
                            'provider' => $moduleId,
                            'module' => $moduleId,
                            'file' => $helpersFile,
                            'symbol' => $fn,
                        ];
                    }
                }

                if ($callable && is_callable($callable)) {
                    $wrappedCallable = static function (mixed $payload, string $resolvedCapabilityId = '', string $providerId = '') use ($moduleId, $callable): mixed {
                        return moduleWithContext($moduleId, static function () use ($callable, $payload, $resolvedCapabilityId, $providerId): mixed {
                            return $callable($payload, $resolvedCapabilityId, $providerId);
                        });
                    };
                    app()->capabilities()->register(
                        $capId,
                        $moduleId,
                        $wrappedCallable,
                        $priority,
                        $modes,
                        ['policy' => $policy, 'schema' => $schema, 'origin' => array_merge($origin, ['capability' => $capId])]
                    );
                } else {
                    write_log(
                        "Module '{$moduleId}' declares capability '{$capId}' but no handler callable was found",
                        'warning',
                        ['module' => $moduleId, 'capability' => $capId]
                    );
                }
            }
        }

        $entityContextCheck = validateModuleEntityContexts($module);
        if (!empty($entityContextCheck['ok'])) {
            $moduleId = (string)($module['id'] ?? '');

            foreach (($entityContextCheck['definitions'] ?? []) as $definition) {
                if (!is_array($definition) || empty($definition['id'])) {
                    continue;
                }

                app()->entityContexts()->registerContext(
                    (string)$definition['id'],
                    $definition,
                    $moduleId,
                    (int)($definition['priority'] ?? 10)
                );
            }

            foreach (($entityContextCheck['extensions'] ?? []) as $extension) {
                if (!is_array($extension) || empty($extension['context'])) {
                    continue;
                }

                app()->entityContexts()->extendContext(
                    (string)$extension['context'],
                    $extension,
                    $moduleId,
                    (int)($extension['priority'] ?? 10)
                );
            }

            foreach (($entityContextCheck['bindings'] ?? []) as $binding) {
                if (!is_array($binding) || empty($binding['entity_type'])) {
                    continue;
                }

                app()->entityContexts()->bindEntityType(
                    (string)$binding['entity_type'],
                    $binding,
                    $moduleId,
                    (int)($binding['priority'] ?? 10)
                );
            }

            foreach (($entityContextCheck['capability_metadata'] ?? []) as $metadata) {
                if (!is_array($metadata) || empty($metadata['id'])) {
                    continue;
                }

                app()->entityContexts()->registerCapability(
                    (string)$metadata['id'],
                    $metadata,
                    $moduleId,
                    (int)($metadata['priority'] ?? 10)
                );
            }
        }

        $routesFile = $module['_path'] . '/routes.php';
        if (!is_file($routesFile)) {
            continue;
        }

        $moduleRoutes = require $routesFile;
        if (!is_array($moduleRoutes)) {
            continue;
        }

        $moduleId = $module['id'] ?? 'unknown';

        // Sync module-declared events[] into the kernel event registry (additive, non-fatal).
        if (function_exists('kernelRegisterModuleEvents')) {
            $declaredEvents = $module['events'] ?? null;
            if (is_array($declaredEvents)) {
                kernelRegisterModuleEvents((string)$moduleId, $declaredEvents);
            }
        }

        foreach (['GET', 'POST', 'PUT', 'DELETE'] as $method) {
            if (empty($moduleRoutes[$method]) || !is_array($moduleRoutes[$method])) {
                continue;
            }
            foreach ($moduleRoutes[$method] as $pattern => $handler) {
                $routeKey = $method . ':' . $pattern;
                $blockedByAmbiguity = false;

                // Lint for semantic ambiguity (e.g. /foo/{id} vs /foo/bar).
                foreach ($methodPatterns[$method] as $existingPattern => $owner) {
                    if ($existingPattern === $pattern) {
                        continue;
                    }
                    if (!routePatternsMayConflict($existingPattern, $pattern)) {
                        continue;
                    }

                    // The dispatcher sorts patterns via compareRoutePatternsForMatching()
                    // which ranks literal routes above parameterized ones. When two
                    // conflicting patterns have different priority (e.g. /foo/bar vs
                    // /foo/{id}), the more-specific pattern always wins — no real
                    // ambiguity exists, so skip the warning.
                    if (compareRoutePatternsForMatching($existingPattern, $pattern) !== 0) {
                        continue;
                    }

                    $context = [
                        'module' => $moduleId,
                        'method' => $method,
                        'pattern' => $pattern,
                        'existing_pattern' => $existingPattern,
                        'existing_owner' => $owner,
                        'mode' => $ambiguityMode,
                    ];

                    if ($ambiguityMode === 'block') {
                        write_log(
                            "Route ambiguity blocked: module '{$moduleId}' {$method} {$pattern} conflicts with '{$owner}' route {$existingPattern}",
                            'warning',
                            $context
                        );
                        $blockedByAmbiguity = true;
                        break;
                    }

                    write_log(
                        "Route ambiguity warning: module '{$moduleId}' registered {$method} {$pattern} which may conflict with '{$owner}' route {$existingPattern}",
                        'warning',
                        $context
                    );
                }

                if ($blockedByAmbiguity) {
                    continue;
                }

                if (isset($routeOwners[$routeKey])) {
                    // Conflict detected — reject and log
                    $owner = $routeOwners[$routeKey];
                    write_log(
                        "Route conflict: module '{$moduleId}' tried to register {$method} {$pattern} already owned by '{$owner}' — rejected",
                        'warning',
                        ['module' => $moduleId, 'method' => $method, 'pattern' => $pattern, 'owner' => $owner]
                    );
                    continue; // skip — do NOT overwrite
                }

                $routes[$method][$pattern] = $handler;
                $routeOwners[$routeKey] = $moduleId;
                $methodPatterns[$method][$pattern] = $moduleId;
            }
        }
    }

    // Flush all deferred event registrations in a single batch (1 cache check + 1 batch DB write)
    if (function_exists('kernelFlushPendingEventRegistrations')) {
        kernelFlushPendingEventRegistrations();
    }

    return $routes;
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
    global $_activeModuleContext, $_moduleContextStack;

    $ctx = is_string($module) ? moduleContextFor($module) : $module;
    if (!$ctx) {
        return null;
    }

    $_moduleContextStack[] = $_activeModuleContext;
    $_activeModuleContext = $ctx;
    return $ctx;
}

function modulePopContext(): void
{
    global $_activeModuleContext, $_moduleContextStack;

    $_activeModuleContext = array_pop($_moduleContextStack) ?? null;
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

    $helpersFile = (string)($module['_path'] ?? '') . '/helpers.php';
    if (is_file($helpersFile)) {
        moduleWithContext($moduleId, static function () use ($helpersFile): void {
            require_once $helpersFile;
        });
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
    global $_activeModuleContext;

    if ($moduleId === null || trim($moduleId) === '') {
        return $_activeModuleContext;
    }

    $moduleId = trim($moduleId);
    if ($_activeModuleContext && $_activeModuleContext->moduleId() === $moduleId) {
        return $_activeModuleContext;
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
        $readsTables
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
    global $_activeModuleContext;

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
            $isApiRoute = str_starts_with($requestUri, '/api/') || (bool)preg_match('#^/[a-zA-Z0-9\-]+/api/#', $requestUri);

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

    // ── Build scoped ModuleContext ───────────────────────────────────
    $ctx = modulePushContext($moduleId);
    if (!$ctx) {
        http_response_code(500);
        echo 'Module context unavailable';
        return;
    }

    $GLOBALS['_capability_call_context'] = [
        'module' => $moduleId,
        'user' => $user,
        'request_id' => request_id(),
    ];

    // ── Kernel-enforced CSRF on state-mutating module routes ──────────
    // API routes (Bearer-authenticated) are exempt; browser form posts must pass.
    $requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $isModuleLogin = (bool)preg_match('#^/[a-zA-Z0-9\-]+/auth/login$#', $requestUri);
    $isApiRoute = str_starts_with($requestUri, '/api/') || (bool)preg_match('#^/[a-zA-Z0-9\-]+/api/#', $requestUri);
    $isCacheSafePublicCartAdd = $requestUri === '/ecommerce/cart/add';

    if ($requestMethod === 'POST' && $isModuleLogin) {
        $loginRateLimit = kernelConsumeLoginRateLimit($moduleId);
        if (!empty($loginRateLimit['limited'])) {
            kernelEmitLoginRateLimitJson($loginRateLimit);
            modulePopContext();
            unset($GLOBALS['_capability_call_context']);
            return;
        }
    }

    if (in_array($requestMethod, ['POST', 'PUT', 'DELETE'], true) && !$isApiRoute && !$isModuleLogin && !$isCacheSafePublicCartAdd) {
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
                unset($GLOBALS['_capability_call_context']);
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

    // ── Output-buffered, exception-safe handler execution ────────────
    // Prevents stray echo/print from corrupting responses and ensures
    // uncaught exceptions produce a clean error page, not a white screen.
    ob_start();
    try {
        $routeCallable($params);
        ob_end_flush(); // success — send captured output
    } catch (\Throwable $e) {
        ob_end_clean(); // discard any partial output from the bad handler

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
        unset($GLOBALS['_capability_call_context']);
    }
}

// ─── Dynamic Navigation ───────────────────────────────────────────────────

/**
 * Build nav items from all enabled modules for the current user's role.
 * Returns flat array: [ ['label'=>..., 'url'=>..., 'icon'=>..., 'module'=>...], ... ]
 */
function getModuleNavItems(?string $role = null): array
{
    if ($role === null) {
        $user = app()->user();
        $role = $user ? (string)($user['role'] ?? '') : '';
    }
    if ($role === '') {
        return [];
    }

    // Kernel superadmin: settings-only role — no module navigation.
    // Return dedicated kernel nav and skip all module items.
    if ($role === 'superadmin') {
        return [
            ['label' => 'Feature Settings', 'url' => '/superadmin/settings', 'icon' => 'settings', 'module' => '_kernel', 'target' => null],
            ['label' => 'Profile',          'url' => '/admin/profile',       'icon' => 'user',     'module' => '_kernel', 'target' => null],
        ];
    }

    $navItems = [];
    foreach (getEnabledModules() as $module) {
        $moduleId = (string)($module['id'] ?? '');
        if ($moduleId === '') {
            continue;
        }

        // Kernel admin should not see module links unless the module opts in.
        if ($role === 'admin') {
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

                if ($role === 'admin' && $rawUrl !== '#' && !$isExternal && !$isAdminPath && !$isApiPath && !$isModulePath) {
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
    if ($role === 'admin') {
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

    $items = getModuleNavItems($role);
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

    foreach (['owns_tables', 'reads_tables', 'requires_tables'] as $tableField) {
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
            if (array_key_exists('url', $item) && !is_string($item['url'])) {
                return ['ok' => false, 'error' => "module.json nav[{$idx}].url must be a string", 'error_code' => 'manifest_invalid_nav'];
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
    $dir = modulesPath() . '/' . $moduleId;
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

    $manifestPath = modulesPath() . '/' . $moduleId . '/module.json';
    if (!is_file($manifestPath)) {
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

    $manifestPath = modulesPath() . '/' . $moduleId . '/module.json';
    if (!is_file($manifestPath)) {
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
