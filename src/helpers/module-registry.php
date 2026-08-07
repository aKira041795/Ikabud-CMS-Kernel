<?php

declare(strict_types=1);

// ─── Registry (enabled / disabled) ────────────────────────────────────────

function readModuleRegistry(): array
{
    // Per-request cache: the registry only changes on module enable/disable.
    static $cached = null;
    if (is_array($cached)) {
        return $cached;
    }

    // Cross-request APCu cache. Bluehost-safe: gracefully falls back to the
    // disk file when APCu is unavailable. Invalidated on write so module
    // enable/disable changes are picked up immediately.
    $apcuKey = 'ikabud:module_registry_v1';
    if (function_exists('apcu_fetch') && ini_get('apc.enabled')) {
        $reg = apcu_fetch($apcuKey, $hit);
        if ($hit && is_array($reg)) {
            $cached = $reg;
            return $reg;
        }
    }

    $path = moduleRegistryPath();
    if (!is_file($path)) {
        return [];
    }
    $data = json_decode((string) file_get_contents($path), true);
    $registry = is_array($data) ? $data : [];

    if (function_exists('apcu_store') && ini_get('apc.enabled')) {
        apcu_store($apcuKey, $registry, 3600);
    }
    $cached = $registry;
    return $registry;
}

function writeModuleRegistry(array $registry): void
{
    $path = moduleRegistryPath();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    file_put_contents($path, json_encode($registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);

    // Invalidate the cached registry so module enable/disable takes effect immediately.
    if (function_exists('apcu_delete')) {
        apcu_delete('ikabud:module_registry_v1');
    }
}

/**
 * Read module manifests without consulting runtime enablement state.
 *
 * @return array<string, array<string, mixed>>
 */
function moduleRegistryRawModuleManifests(): array
{
    static $cache = null;
    if (is_array($cache)) {
        return $cache;
    }

    $dir = modulesPath();
    if (!is_dir($dir)) {
        $cache = [];
        return $cache;
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
        if (!is_array($manifest)) {
            continue;
        }

        $moduleId = trim((string)($manifest['id'] ?? ''));
        if ($moduleId === '') {
            continue;
        }
        if (isset($result[$moduleId])) {
            if (function_exists('write_log')) {
                write_log('Duplicate module id discovered in registry scan: ' . $moduleId . ' at ' . $manifestPath . ' (keeping first occurrence)', 'warning');
            }
            continue;
        }

        $manifest['_path'] = dirname($manifestPath);
        $result[$moduleId] = $manifest;
    }

    $cache = $result;
    return $cache;
}

function moduleRegistryModuleTouchesEntryData(array $manifest, string $entryModuleId): bool
{
    $entryModuleId = trim($entryModuleId);
    if ($entryModuleId === '') {
        return false;
    }

    $prefix = $entryModuleId . '_';
    foreach (['owns_tables', 'reads_tables'] as $key) {
        foreach (($manifest[$key] ?? []) as $tableName) {
            $tableName = trim((string)$tableName);
            if ($tableName !== '' && str_starts_with($tableName, $prefix)) {
                return true;
            }
        }
    }

    return false;
}

/**
 * @return array{saved_settings: array<string, bool>, installed_submodules: array<string, bool>}
 */
function moduleRegistryTenantSettingSignals(int $tenantId): array
{
    static $cache = [];
    if (isset($cache[$tenantId]) && is_array($cache[$tenantId])) {
        return $cache[$tenantId];
    }

    $signals = [
        'saved_settings' => [],
        'installed_submodules' => [],
    ];

    if ($tenantId <= 0) {
        $cache[$tenantId] = $signals;
        return $signals;
    }

    try {
        $db = app()->dbForTenant($tenantId);
        if ($db === null || !moduleTenantSettingsEnsureTable($db)) {
            $cache[$tenantId] = $signals;
            return $signals;
        }

        $stmt = $db->prepare(
            'SELECT module_id, setting_key, setting_value '
            . 'FROM ' . moduleTenantSettingsTable() . ' '
            . 'WHERE tenant_id = :tid'
        );
        $stmt->execute([':tid' => $tenantId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $moduleId = trim((string)($row['module_id'] ?? ''));
            $settingKey = trim((string)($row['setting_key'] ?? ''));
            if ($moduleId === '' || $settingKey === '') {
                continue;
            }

            if ($moduleId === 'cms' && $settingKey === '_installed_submodules') {
                $decoded = json_decode((string)($row['setting_value'] ?? 'null'), true);
                if (is_array($decoded)) {
                    foreach ($decoded as $installedModuleId) {
                        $installedModuleId = trim((string)$installedModuleId);
                        if ($installedModuleId !== '') {
                            $signals['installed_submodules'][$installedModuleId] = true;
                        }
                    }
                }
                continue;
            }

            if (!str_starts_with($settingKey, '_')) {
                $signals['saved_settings'][$moduleId] = true;
            }
        }
    } catch (Throwable $e) {
        $cache[$tenantId] = $signals;
        return $signals;
    }

    $cache[$tenantId] = $signals;
    return $signals;
}

/**
 * @return array<string, bool>
 */
function moduleRegistryRuntimeDefaultModulesForTenant(int $tenantId): array
{
    static $cache = [];
    if (isset($cache[$tenantId]) && is_array($cache[$tenantId])) {
        return $cache[$tenantId];
    }

    $allModules = moduleRegistryRawModuleManifests();
    if ($tenantId <= 0 || $allModules === []) {
        $cache[$tenantId] = [];
        return $cache[$tenantId];
    }

    $entryModuleId = tenantEntryModuleIdForTenant($tenantId);
    if ($entryModuleId === null || $entryModuleId === '' || !isset($allModules[$entryModuleId])) {
        $cache[$tenantId] = [];
        return $cache[$tenantId];
    }

    $exposesByCapability = [];
    foreach ($allModules as $moduleId => $manifest) {
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
            $exposesByCapability[$capabilityId][] = $moduleId;
        }
    }

    $selected = [$entryModuleId => true];
    $queue = [$entryModuleId];

    while ($queue !== []) {
        $current = array_shift($queue);
        if (!is_string($current) || !isset($allModules[$current])) {
            continue;
        }

        $manifest = $allModules[$current];

        foreach (($manifest['depends'] ?? []) as $depModuleId) {
            $depModuleId = trim((string)$depModuleId);
            if ($depModuleId !== '' && isset($allModules[$depModuleId]) && !isset($selected[$depModuleId])) {
                $selected[$depModuleId] = true;
                $queue[] = $depModuleId;
            }
        }

        foreach (['depends', 'consumes'] as $capabilityKey) {
            $capabilityRefs = $manifest['capabilities'][$capabilityKey] ?? $manifest[$capabilityKey] ?? [];
            if (!is_array($capabilityRefs)) {
                continue;
            }
            foreach ($capabilityRefs as $capabilityRef) {
                if (is_array($capabilityRef)) {
                    $capabilityRef = $capabilityRef['id'] ?? '';
                }
                $capabilityId = trim((string)$capabilityRef);
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

        foreach ($allModules as $moduleId => $candidate) {
            if (isset($selected[$moduleId])) {
                continue;
            }

            $allowCallers = moduleCatalogCapabilityAllowCallers($candidate);
            if ($allowCallers !== [] && in_array($current, $allowCallers, true)) {
                $selected[$moduleId] = true;
                $queue[] = $moduleId;
                continue;
            }

            foreach (($candidate['hooks'] ?? []) as $hookName) {
                $hookName = trim((string)$hookName);
                if ($hookName !== '' && str_starts_with($hookName, $current . '.')) {
                    $selected[$moduleId] = true;
                    $queue[] = $moduleId;
                    continue 2;
                }
            }
        }
    }

    if (isset($allModules['anti-spam'])) {
        $selected['anti-spam'] = true;
    }

    $changed = true;
    while ($changed) {
        $changed = false;
        foreach ($allModules as $moduleId => $candidate) {
            if (isset($selected[$moduleId])) {
                continue;
            }
            $moduleDepends = $candidate['depends'] ?? [];
            if (!is_array($moduleDepends)) {
                continue;
            }
            foreach ($moduleDepends as $depModuleId) {
                $depModuleId = trim((string)$depModuleId);
                if ($depModuleId !== '' && isset($selected[$depModuleId])) {
                    $selected[$moduleId] = true;
                    $changed = true;
                    break;
                }
            }
        }
    }

    foreach ($allModules as $moduleId => $manifest) {
        if (!empty($manifest['auth_cookie'])) {
            continue;
        }
        if (moduleRegistryModuleTouchesEntryData($manifest, $entryModuleId)) {
            $selected[$moduleId] = true;
        }
    }

    $signals = moduleRegistryTenantSettingSignals($tenantId);
    foreach (array_keys($signals['saved_settings']) as $moduleId) {
        if (isset($allModules[$moduleId])) {
            $selected[$moduleId] = true;
        }
    }
    foreach (array_keys($signals['installed_submodules']) as $moduleId) {
        if (isset($allModules[$moduleId])) {
            $selected[$moduleId] = true;
        }
    }

    foreach (array_keys($allModules) as $moduleId) {
        $entitlement = moduleTenantEntitlementStatus($moduleId, $tenantId);
        if (!empty($entitlement['catalog_managed']) && !empty($entitlement['required']) && !empty($entitlement['allowed'])) {
            $selected[$moduleId] = true;
        }
    }

    $cache[$tenantId] = $selected;
    return $cache[$tenantId];
}

/**
 * Resolve the default enabled state for a module.
 *
 * NOTE: This function duplicates logic from getEnabledModules() (module-manager.php)
 * and tenantProvisionModulePlan(). Changes to dependency-closure or capability
 * resolution logic should be applied consistently across all three.
 *
 * @see getEnabledModules()
 * @see tenantProvisionModulePlan()
 */
function moduleRegistryDefaultEnabledState(string $moduleId, ?int $tenantId = null): bool
{
    $moduleId = trim($moduleId);
    if ($moduleId === '') {
        return false;
    }

    if ($tenantId === null) {
        if (!moduleTenantSettingsModeEnabled()) {
            return true;
        }
        $tenantId = moduleTenantSettingsTenantId();
        if ($tenantId === null || $tenantId <= 0) {
            return true;
        }
    }

    $allModules = moduleRegistryRawModuleManifests();
    if (!isset($allModules[$moduleId])) {
        return false;
    }

    $entryModuleId = tenantEntryModuleIdForTenant($tenantId);
    if ($entryModuleId === $moduleId) {
        return true;
    }
    if ($entryModuleId === null || $entryModuleId === '' || !isset($allModules[$entryModuleId])) {
        return true;
    }

    $defaults = moduleRegistryRuntimeDefaultModulesForTenant($tenantId);
    return !empty($defaults[$moduleId]);
}

/**
 * Compute the NARROW "always-active" set for a tenant — the modules that
 * participate by default without explicit tenant activation.
 *
 * Activation Before Participation: presence/saved-settings must NOT grant
 * participation. Only the entry module's hard dependency spine participates
 * by default:
 *   - the entry module itself;
 *   - a profile's declared `installs` bundle (when the entry is a profile);
 *   - the transitive module-level `depends` closure;
 *   - providers of the capabilities the entry chain hard-requires
 *     (`capabilities.depends`).
 *
 * Unlike moduleRegistryRuntimeDefaultModulesForTenant() (used for code
 * loading / isModuleEnabled), this set deliberately EXCLUDES saved-settings
 * signals, installed-submodule signals, catalog entitlements, allow-caller
 * matches, and hook-name matches — those make a module load, not active.
 *
 * @param int $tenantId
 * @return array<string,bool> map of module id => true
 */
function moduleRegistryAlwaysActiveForTenant(int $tenantId): array
{
    static $cache = [];
    if (isset($cache[$tenantId]) && is_array($cache[$tenantId])) {
        return $cache[$tenantId];
    }

    $allModules = moduleRegistryRawModuleManifests();
    if ($tenantId <= 0 || $allModules === []) {
        $cache[$tenantId] = [];
        return $cache[$tenantId];
    }

    $entryModuleId = tenantEntryModuleIdForTenant($tenantId);
    if ($entryModuleId === null || $entryModuleId === '' || !isset($allModules[$entryModuleId])) {
        $cache[$tenantId] = [];
        return $cache[$tenantId];
    }

    $exposesByCapability = [];
    foreach ($allModules as $moduleId => $manifest) {
        $exposes = $manifest['capabilities']['exposes'] ?? [];
        if (!is_array($exposes)) { continue; }
        foreach ($exposes as $expose) {
            if (!is_array($expose)) { continue; }
            $capabilityId = trim((string)($expose['id'] ?? ''));
            if ($capabilityId === '') { continue; }
            if (!isset($exposesByCapability[$capabilityId])) { $exposesByCapability[$capabilityId] = []; }
            $exposesByCapability[$capabilityId][] = $moduleId;
        }
    }

    $selected = [];
    $queue = [$entryModuleId];

    // A profile's `installs` bundle participates by default.
    $entryManifest = $allModules[$entryModuleId] ?? [];
    if (!empty($entryManifest['kind']) && $entryManifest['kind'] === 'profile') {
        $installs = $entryManifest['installs'] ?? [];
        if (is_array($installs)) {
            foreach ($installs as $installedId) {
                $installedId = trim((string)$installedId);
                if ($installedId !== '' && isset($allModules[$installedId]) && !isset($selected[$installedId])) {
                    $selected[$installedId] = true;
                    $queue[] = $installedId;
                }
            }
        }
    }

    while ($queue !== []) {
        $current = array_shift($queue);
        if (!is_string($current) || !isset($allModules[$current])) { continue; }
        if (!isset($selected[$current])) {
            $selected[$current] = true;
        }

        $manifest = $allModules[$current];

        // Hard module-level depends.
        foreach (($manifest['depends'] ?? []) as $depModuleId) {
            $depModuleId = trim((string)$depModuleId);
            if ($depModuleId !== '' && isset($allModules[$depModuleId]) && !isset($selected[$depModuleId])) {
                $selected[$depModuleId] = true;
                $queue[] = $depModuleId;
            }
        }

        // Capability depends → provider modules the chain hard-requires.
        $capRefs = $manifest['capabilities']['depends'] ?? [];
        if (!is_array($capRefs)) { $capRefs = []; }
        foreach ($capRefs as $capRef) {
            $capabilityId = is_array($capRef) ? (string)($capRef['id'] ?? '') : (string)$capRef;
            $capabilityId = trim($capabilityId);
            if ($capabilityId === '') { continue; }
            foreach ($exposesByCapability[$capabilityId] ?? [] as $providerModuleId) {
                if (!isset($selected[$providerModuleId])) {
                    $selected[$providerModuleId] = true;
                    $queue[] = $providerModuleId;
                }
            }
        }
    }

    $cache[$tenantId] = $selected;
    return $selected;
}

/**
 * Activation Before Participation — the kernel invariant that gates
 * capability dispatch, hook participation, route accessibility, and
 * UI-contribution rendering behind explicit tenant activation.
 *
 * A module is "active" (operational) for a tenant when:
 *   - It is the entry module or in the entry module's runtime dependency
 *     closure (always-active — cannot be deactivated without breaking the
 *     entry module).
 *   - The tenant admin has explicitly activated it (saved _module_enabled:
 *     true in tenant module settings).
 *
 * A module that is discovered, installed, and enabled but NOT in the
 * runtime defaults and NOT explicitly activated is "installed but inactive"
 * — its helpers and capabilities are loaded, but it MUST NOT participate
 * in the host application's UI, hooks, or operational surfaces.
 *
 * Deactivation writes _module_enabled: false — data is retained.
 *
 * @param string $moduleId
 * @param int|null $tenantId explicit tenant id (superadmin use)
 * @return bool true when the module is operational for the tenant
 */
function moduleIsActive(string $moduleId, ?int $tenantId = null): bool
{
    $moduleId = trim($moduleId);
    if ($moduleId === '') {
        return false;
    }

    // The module must be enabled at all. A disabled module's helpers are not
    // loaded and its capabilities are not registered.
    if (!isModuleEnabled($moduleId)) {
        return false;
    }

    // Single-tenant / no tenant-context: all enabled modules are active
    // (backward-compatible default for development and legacy setups).
    if ($tenantId === null) {
        if (!moduleTenantSettingsModeEnabled()) {
            return true;
        }
        $tenantId = moduleTenantSettingsTenantId();
        if ($tenantId === null || $tenantId <= 0) {
            return true;
        }
    }

    $allModules = moduleRegistryRawModuleManifests();
    if (!isset($allModules[$moduleId])) {
        return false;
    }

    // Entry module and its hard dependency closure are always active.
    // Note: this deliberately uses the NARROW always-active set (entry
    // spine + profile installs + hard depends) — NOT
    // moduleRegistryRuntimeDefaultModulesForTenant(), which also pulls in
    // modules via saved-settings / catalog / hook signals. Those signals
    // make a module load, but they do not grant activation.
    $alwaysActive = moduleRegistryAlwaysActiveForTenant($tenantId);
    if (!empty($alwaysActive[$moduleId])) {
        return true;
    }

    // Check explicit tenant-level activation.
    // The _module_enabled key is set by enableModule() / disableModule() or
    // the CMS Modules page Activate / Deactivate operations.
    if ($tenantId !== null && $tenantId > 0) {
        $tenantSettings = readTenantModuleSettingsForTenant($moduleId, $tenantId);
    } else {
        $tenantSettings = readTenantModuleSettings($moduleId);
    }

    if (array_key_exists('_module_enabled', $tenantSettings)) {
        return (bool) $tenantSettings['_module_enabled'];
    }

    // No explicit activation → installed but inactive.
    // (This is the critical difference from isModuleEnabled, which defaults
    // to true via moduleRegistryDefaultEnabledState.)
    return false;
}

function isModuleEnabled(string $moduleId): bool
{
    // In multi-tenant mode, check per-tenant override first.
    if (moduleTenantSettingsModeEnabled()) {
        $tenantId = moduleTenantSettingsTenantId();
        if ($tenantId !== null) {
            $entryModuleId = function_exists('tenantEntryModuleIdForTenant') ? trim((string) tenantEntryModuleIdForTenant($tenantId)) : '';
            if ($entryModuleId !== '' && $moduleId === $entryModuleId) {
                return true;
            }
            $tenantSettings = readTenantModuleSettings($moduleId);
            if (array_key_exists('_module_enabled', $tenantSettings)) {
                return (bool) $tenantSettings['_module_enabled'];
            }
        }
    }

    $registry = readModuleRegistry();
    if (array_key_exists('enabled', $registry[$moduleId] ?? [])) {
        return !empty($registry[$moduleId]['enabled']);
    }

    return moduleRegistryDefaultEnabledState($moduleId);
}

/**
 * Check if a module is enabled for an explicit tenant ID (superadmin use).
 * Checks per-tenant override first, then falls back to global registry.
 */
function isModuleEnabledForTenant(string $moduleId, int $tenantId): bool
{
    $entryModuleId = function_exists('tenantEntryModuleIdForTenant') ? trim((string) tenantEntryModuleIdForTenant($tenantId)) : '';
    if ($entryModuleId !== '' && $moduleId === $entryModuleId) {
        return true;
    }

    $tenantSettings = readTenantModuleSettingsForTenant($moduleId, $tenantId);
    if (array_key_exists('_module_enabled', $tenantSettings)) {
        return (bool) $tenantSettings['_module_enabled'];
    }

    $registry = readModuleRegistry();
    if (array_key_exists('enabled', $registry[$moduleId] ?? [])) {
        return !empty($registry[$moduleId]['enabled']);
    }

    return moduleRegistryDefaultEnabledState($moduleId, $tenantId);
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

