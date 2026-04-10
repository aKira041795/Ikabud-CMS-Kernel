<?php

declare(strict_types=1);

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
    // If the module appears in the registry but has no explicit 'enabled' flag
    // (e.g. only settings were saved), treat it as enabled by default.
    if (!array_key_exists('enabled', $registry[$moduleId] ?? [])) {
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
    // If the module appears in the registry but has no explicit 'enabled' flag
    // (e.g. only settings were saved), treat it as enabled by default.
    if (!array_key_exists('enabled', $registry[$moduleId] ?? [])) {
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

