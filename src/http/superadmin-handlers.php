<?php

declare(strict_types=1);

if (!function_exists('superadminAddModuleSource')) {
    function superadminAddModuleSource(array &$map, string $moduleId, string $source): void
    {
        $moduleId = trim($moduleId);
        $source = trim($source);
        if ($moduleId === '' || $source === '') {
            return;
        }

        if (!isset($map[$moduleId]) || !is_array($map[$moduleId])) {
            $map[$moduleId] = [];
        }
        $map[$moduleId][$source] = true;
    }
}

if (!function_exists('superadminModuleScopeLabel')) {
    function superadminModuleScopeLabel(array $sources): string
    {
        $priority = [
            'entry-module' => 'Entry module',
            'provisioning-plan' => 'Provisioned dependency',
            'dependency' => 'Dependency',
            'capability-provider' => 'Capability provider',
            'hook-addon' => 'Hook add-on',
            'data-addon' => 'Entry data add-on',
            'entry-addon' => 'Entry add-on',
            'installed-submodule' => 'Installed submodule',
            'tenant-entitlement' => 'Tenant entitlement',
            'tenant-override' => 'Tenant override',
            'tenant-settings' => 'Saved settings',
        ];

        foreach ($priority as $key => $label) {
            if (in_array($key, $sources, true)) {
                return $label;
            }
        }

        return 'Relevant';
    }
}

if (!function_exists('superadminModuleTouchesEntryData')) {
    function superadminModuleTouchesEntryData(array $manifest, string $entryModuleId): bool
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
}

if (!function_exists('superadminBuildDependencyClosure')) {
    /**
     * @return array<string, bool>
     */
    function superadminBuildDependencyClosure(array $allModules, string $entryModuleId): array
    {
        $entryModuleId = trim($entryModuleId);
        if ($entryModuleId === '' || !isset($allModules[$entryModuleId])) {
            return [];
        }

        $selected = [$entryModuleId => true];
        $queue = [$entryModuleId];

        while ($queue !== []) {
            $current = array_shift($queue);
            if (!is_string($current) || !isset($allModules[$current])) {
                continue;
            }

            foreach (($allModules[$current]['depends'] ?? []) as $depModuleId) {
                $depModuleId = trim((string)$depModuleId);
                if ($depModuleId === '' || isset($selected[$depModuleId]) || !isset($allModules[$depModuleId])) {
                    continue;
                }

                $selected[$depModuleId] = true;
                $queue[] = $depModuleId;
            }
        }

        return $selected;
    }
}

if (!function_exists('superadminTenantRelevantModuleMap')) {
    /**
     * @return array<string, array<string, bool>>
     */
    function superadminTenantRelevantModuleMap(array $allModules, string $selectedEntryModule, ?int $selectedTenantId = null): array
    {
        $selectedEntryModule = trim($selectedEntryModule);
        $relevant = [];
        $dependencyClosure = superadminBuildDependencyClosure($allModules, $selectedEntryModule);

        if ($selectedEntryModule !== '' && isset($allModules[$selectedEntryModule])) {
            superadminAddModuleSource($relevant, $selectedEntryModule, 'entry-module');
        }

        foreach (array_keys($dependencyClosure) as $moduleId) {
            if ($moduleId === $selectedEntryModule) {
                continue;
            }
            superadminAddModuleSource($relevant, $moduleId, 'dependency');
        }

        if ($selectedEntryModule !== '') {
            foreach (tenantProvisionModulePlan($selectedEntryModule) as $plannedModuleId) {
                superadminAddModuleSource($relevant, (string)$plannedModuleId, 'provisioning-plan');
            }
        }

        foreach ($allModules as $moduleId => $manifest) {
            $allowCallers = moduleCatalogCapabilityAllowCallers($manifest);
            if (!empty($allowCallers) && !empty(array_intersect($allowCallers, array_keys($dependencyClosure)))) {
                superadminAddModuleSource($relevant, (string)$moduleId, 'capability-provider');
            }

            foreach (($manifest['hooks'] ?? []) as $hookName) {
                $hookName = trim((string)$hookName);
                if ($selectedEntryModule !== '' && $hookName !== '' && str_starts_with($hookName, $selectedEntryModule . '.')) {
                    superadminAddModuleSource($relevant, (string)$moduleId, 'hook-addon');
                    break;
                }
            }

            $depends = array_map('trim', (array)($manifest['depends'] ?? []));
            if ($selectedEntryModule !== '' && empty($manifest['auth_cookie']) && in_array($selectedEntryModule, $depends, true)) {
                superadminAddModuleSource($relevant, (string)$moduleId, 'entry-addon');
            }

            if ($selectedEntryModule !== '' && empty($manifest['auth_cookie']) && superadminModuleTouchesEntryData($manifest, $selectedEntryModule)) {
                superadminAddModuleSource($relevant, (string)$moduleId, 'data-addon');
            }
        }

        if ($selectedTenantId !== null && $selectedTenantId > 0) {
            $cmsSettings = readTenantModuleSettingsForTenant('cms', $selectedTenantId);
            $installedSubModules = [];
            foreach (($cmsSettings['_installed_submodules'] ?? []) as $moduleId) {
                $moduleId = trim((string)$moduleId);
                if ($moduleId === '') {
                    continue;
                }
                $installedSubModules[$moduleId] = true;
            }
            foreach (array_keys($installedSubModules) as $moduleId) {
                superadminAddModuleSource($relevant, $moduleId, 'installed-submodule');
            }

            foreach ($allModules as $moduleId => $manifest) {
                $entitlement = moduleTenantEntitlementStatus((string)$moduleId, $selectedTenantId);
                if (!empty($entitlement['catalog_managed']) && !empty($entitlement['required']) && !empty($entitlement['allowed'])) {
                    superadminAddModuleSource($relevant, (string)$moduleId, 'tenant-entitlement');
                }

                $tenantSettings = readTenantModuleSettingsForTenant((string)$moduleId, $selectedTenantId);
                $tenantDataSettings = $tenantSettings;
                foreach (array_keys($tenantDataSettings) as $tenantSettingKey) {
                    if (is_string($tenantSettingKey) && str_starts_with($tenantSettingKey, '_')) {
                        unset($tenantDataSettings[$tenantSettingKey]);
                    }
                }
                if (!empty($tenantDataSettings)) {
                    superadminAddModuleSource($relevant, (string)$moduleId, 'tenant-settings');
                }
                if (array_key_exists('_module_enabled', $tenantSettings) && !empty($tenantSettings['_module_enabled'])) {
                    superadminAddModuleSource($relevant, (string)$moduleId, 'tenant-override');
                }
            }
        }

        return $relevant;
    }
}

if (!function_exists('superadminModuleEnablementState')) {
    /**
     * @return array<string, mixed>
     */
    function superadminModuleEnablementState(string $moduleId, ?int $tenantId = null): array
    {
        $moduleId = trim($moduleId);
        $registry = readModuleRegistry();
        $globalEntry = is_array($registry[$moduleId] ?? null) ? $registry[$moduleId] : [];
        $hasGlobalFlag = array_key_exists('enabled', $globalEntry);
        $runtimeDefaultEnabled = moduleRegistryDefaultEnabledState($moduleId, $tenantId !== null && $tenantId > 0 ? $tenantId : null);
        $globalEnabled = $hasGlobalFlag ? !empty($globalEntry['enabled']) : $runtimeDefaultEnabled;

        $tenantSettings = ($tenantId !== null && $tenantId > 0)
            ? readTenantModuleSettingsForTenant($moduleId, $tenantId)
            : [];
        $hasTenantOverride = array_key_exists('_module_enabled', $tenantSettings);
        $tenantEnabled = $hasTenantOverride ? (bool)$tenantSettings['_module_enabled'] : $globalEnabled;
        $runtimeEnabled = ($tenantId !== null && $tenantId > 0)
            ? isModuleEnabledForTenant($moduleId, $tenantId)
            : isModuleEnabled($moduleId);

        $source = 'runtime_default';
        $label = $runtimeDefaultEnabled ? 'Runtime default on' : 'Runtime default off';
        if ($hasTenantOverride) {
            $source = 'tenant_override';
            $label = $tenantEnabled ? 'Tenant override on' : 'Tenant override off';
        } elseif ($hasGlobalFlag) {
            $source = 'global_registry';
            $label = $globalEnabled ? 'Global registry on' : 'Global registry off';
        }

        return [
            'runtime_enabled' => $runtimeEnabled,
            'effective_enabled' => $tenantEnabled,
            'has_tenant_override' => $hasTenantOverride,
            'has_global_flag' => $hasGlobalFlag,
            'source' => $source,
            'source_label' => $label,
            'tenant_override' => $hasTenantOverride ? (bool)$tenantSettings['_module_enabled'] : null,
            'global_enabled' => $hasGlobalFlag ? (bool)$globalEntry['enabled'] : null,
        ];
    }
}

if (!function_exists('kernelHandlePageSuperadminSettings')) {
    function kernelHandlePageSuperadminSettings(): void
    {
    $user = app()->requireAuth();
    if (($user['role'] ?? '') !== 'superadmin' || ($user['source'] ?? '') !== 'kernel') {
        app()->redirect('/');
        exit;
    }

    // ── Tenant scoping ──────────────────────────────────────────
    $multiTenant = moduleTenantSettingsModeEnabled();
    $tenants = [];
    $selectedTenantId = null;
    if ($multiTenant) {
        try {
            $tStmt = app()->controlDb()->query(
                'SELECT t.id, t.tenant_key, t.status, t.entry_module_id, '
                . 'GROUP_CONCAT(d.domain ORDER BY d.domain SEPARATOR \', \') AS domains '
                . 'FROM kernel_tenants t '
                . 'LEFT JOIN kernel_tenant_domains d ON d.tenant_id = t.id '
                . 'WHERE t.status = \'active\' '
                . 'GROUP BY t.id ORDER BY t.id ASC'
            );
            $tenants = $tStmt ? ($tStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        } catch (Throwable $e) {
            $tenants = [];
        }

        $rawTid = $_GET['tenant_id'] ?? '';
        if (ctype_digit((string)$rawTid) && (int)$rawTid > 0) {
            // Validate against the fetched list
            foreach ($tenants as $t) {
                if ((int)$t['id'] === (int)$rawTid) {
                    $selectedTenantId = (int)$rawTid;
                    break;
                }
            }
        }
        // Default to first tenant if none selected
        if ($selectedTenantId === null && !empty($tenants)) {
            $selectedTenantId = (int)$tenants[0]['id'];
        }
    }

    $tenantLabelsById = [];
    foreach ($tenants as $tenantRow) {
        if (!is_array($tenantRow)) {
            continue;
        }
        $tenantLabel = ($tenantRow['tenant_key'] ?? 'Tenant ' . $tenantRow['id'])
            . (!empty($tenantRow['domains']) ? ' (' . $tenantRow['domains'] . ')' : '');
        $tenantLabelsById[(int)$tenantRow['id']] = $tenantLabel;
    }

    $allModules = discoverModules();
    $catalogEntries = [];
    foreach (readModuleCatalogRegistry() as $catalogModuleId => $catalogEntry) {
        if (!is_array($catalogEntry)) {
            continue;
        }

        $approvalStatus = strtolower(trim((string)($catalogEntry['approval_status'] ?? 'pending')));
        $originTenantId = (int)($catalogEntry['origin_tenant_id'] ?? 0);
        $manifest = $allModules[$catalogModuleId] ?? [];

        $catalogEntries[] = [
            'id' => $catalogModuleId,
            'name' => (string)($manifest['name'] ?? $catalogEntry['module_name'] ?? $catalogModuleId),
            'version' => (string)($manifest['version'] ?? $catalogEntry['approved_version'] ?? '—'),
            'approval_status' => $approvalStatus,
            'commercial_mode' => (string)($catalogEntry['commercial_mode'] ?? 'free'),
            'source' => (string)($catalogEntry['source'] ?? ''),
            'origin_tenant_id' => $originTenantId,
            'origin_tenant_label' => $tenantLabelsById[$originTenantId] ?? ($originTenantId > 0 ? 'Tenant ' . $originTenantId : ''),
            'exists_on_disk' => isset($allModules[$catalogModuleId]),
            'approved_at' => (string)($catalogEntry['approved_at'] ?? ''),
        ];
    }
    usort($catalogEntries, static function (array $left, array $right): int {
        $priority = ['pending' => 0, 'approved' => 1, 'rejected' => 2];
        $leftPriority = $priority[(string)($left['approval_status'] ?? 'pending')] ?? 3;
        $rightPriority = $priority[(string)($right['approval_status'] ?? 'pending')] ?? 3;
        if ($leftPriority !== $rightPriority) {
            return $leftPriority <=> $rightPriority;
        }
        return strcasecmp((string)($left['name'] ?? ''), (string)($right['name'] ?? ''));
    });

    $accessRequests = [];
    foreach (readModuleAccessRequests() as $requestRow) {
        if (!is_array($requestRow)) {
            continue;
        }

        $requestModuleId = trim((string)($requestRow['module_id'] ?? ''));
        $requestTenantId = (int)($requestRow['tenant_id'] ?? 0);
        if ($requestModuleId === '' || $requestTenantId <= 0) {
            continue;
        }

        $manifest = $allModules[$requestModuleId] ?? [];
        $catalogEntry = moduleCatalogEntry($requestModuleId) ?? [];
        $requestMetadata = is_array($requestRow['metadata'] ?? null) ? $requestRow['metadata'] : [];
        $licenseActivation = is_array($requestMetadata['license_activation'] ?? null) ? $requestMetadata['license_activation'] : [];
        $activationStatus = trim((string)($licenseActivation['status'] ?? ''));
        if ($activationStatus === '' && is_array($licenseActivation['result'] ?? null)) {
            $activationStatus = trim((string)($licenseActivation['result']['status'] ?? ''));
        }
        $activationProvider = trim((string)($licenseActivation['provider'] ?? ''));
        if ($activationProvider === '' && is_array($licenseActivation['result'] ?? null)) {
            $activationProvider = trim((string)($licenseActivation['result']['provider'] ?? ''));
        }
        $activationError = trim((string)($licenseActivation['error'] ?? ''));
        if ($activationError === '' && is_array($licenseActivation['result'] ?? null)) {
            $activationError = trim((string)($licenseActivation['result']['error'] ?? ''));
        }
        $activationAt = trim((string)($licenseActivation['activated_at'] ?? ''));
        if ($activationAt === '' && is_array($licenseActivation['result'] ?? null)) {
            $activationAt = trim((string)($licenseActivation['result']['activated_at'] ?? ''));
        }
        $accessRequests[] = [
            'id' => (int)($requestRow['id'] ?? 0),
            'module_id' => $requestModuleId,
            'module_name' => (string)($manifest['name'] ?? $catalogEntry['module_name'] ?? $requestModuleId),
            'tenant_id' => $requestTenantId,
            'tenant_label' => $tenantLabelsById[$requestTenantId] ?? ('Tenant ' . $requestTenantId),
            'requested_mode' => (string)($requestRow['requested_mode'] ?? ($catalogEntry['commercial_mode'] ?? 'paid')),
            'status' => strtolower(trim((string)($requestRow['status'] ?? 'pending'))),
            'request_notes' => (string)($requestRow['request_notes'] ?? ''),
            'license_ref' => (string)($requestRow['license_ref'] ?? ''),
            'has_license_key' => !empty($requestRow['has_license_key']),
            'review_notes' => (string)($requestRow['review_notes'] ?? ''),
            'created_at' => (string)($requestRow['created_at'] ?? ''),
            'reviewed_at' => (string)($requestRow['reviewed_at'] ?? ''),
            'activation_status' => $activationStatus,
            'activation_provider' => $activationProvider,
            'activation_error' => $activationError,
            'activation_at' => $activationAt,
        ];
    }
    usort($accessRequests, static function (array $left, array $right): int {
        $priority = ['pending' => 0, 'approved' => 1, 'rejected' => 2];
        $leftPriority = $priority[(string)($left['status'] ?? 'pending')] ?? 3;
        $rightPriority = $priority[(string)($right['status'] ?? 'pending')] ?? 3;
        if ($leftPriority !== $rightPriority) {
            return $leftPriority <=> $rightPriority;
        }

        return strcmp((string)($right['created_at'] ?? ''), (string)($left['created_at'] ?? ''));
    });

    // ── Build tenant-relevant module whitelist ───────────────────
    $tenantRelevantModules = null;
    $selectedEntryModule = '';
    if ($multiTenant && $selectedTenantId !== null) {
        foreach ($tenants as $t) {
            $eModule = trim((string)($t['entry_module_id'] ?? ''));
            if ((int)$t['id'] === $selectedTenantId) {
                $selectedEntryModule = $eModule;
                break;
            }
        }

        $tenantRelevantModules = superadminTenantRelevantModuleMap($allModules, $selectedEntryModule, $selectedTenantId);
    }

    // Check if selected tenant has a working DB connection
    $tenantDbOk = true;
    if ($multiTenant && $selectedTenantId !== null) {
        try {
            $tenantDbOk = (app()->dbForTenant($selectedTenantId) !== null);
        } catch (Throwable $e) {
            $tenantDbOk = false;
        }
    }

    $moduleList = [];
    foreach ($allModules as $m) {
        $moduleId = (string)($m['id'] ?? '');
        if ($moduleId === '') {
            continue;
        }

        // In multi-tenant mode, check if module is relevant for the selected tenant
        if ($multiTenant && $selectedTenantId !== null && is_array($tenantRelevantModules)) {
            if (!isset($tenantRelevantModules[$moduleId])) {
                continue;
            }
        }

        $enablement = superadminModuleEnablementState($moduleId, $multiTenant ? $selectedTenantId : null);
        $scopeSources = is_array($tenantRelevantModules[$moduleId] ?? null)
            ? array_keys($tenantRelevantModules[$moduleId])
            : [];

        $catalogEntry = moduleCatalogEntry($moduleId);
        $entitlement = [
            'catalog_managed' => is_array($catalogEntry),
            'required' => false,
            'allowed' => true,
            'approval_status' => is_array($catalogEntry) ? (string)($catalogEntry['approval_status'] ?? 'pending') : 'unmanaged',
            'commercial_mode' => is_array($catalogEntry) ? (string)($catalogEntry['commercial_mode'] ?? 'free') : 'bundled',
            'entitlement_status' => 'not_required',
            'reason' => '',
        ];
        if ($multiTenant && $selectedTenantId !== null) {
            $entitlement = moduleTenantEntitlementStatus($moduleId, $selectedTenantId);
        }

        $manifest = $m;
        $fields = is_array($manifest['settings_fields'] ?? null) ? array_values($manifest['settings_fields']) : [];
        $hasFields = !empty($fields);

        // Render field data whenever the tenant can manage the module settings.
        $renderedFields = [];
        if ($hasFields && $tenantDbOk) {
            // Read settings: tenant-scoped or global
            if ($multiTenant && $selectedTenantId !== null) {
                $modSettings = getModuleSettingsForTenant($moduleId, $selectedTenantId);
            } else {
                $modSettings = getModuleSettings($moduleId);
            }

            foreach ($fields as $field) {
                $key = (string)($field['key'] ?? '');
                if ($key === '') continue;
                $type = strtolower(trim((string)($field['type'] ?? 'text')));
                $currentValue = array_key_exists($key, $modSettings)
                    ? $modSettings[$key]
                    : ($field['default'] ?? '');
                $isCheckbox = in_array($type, ['checkbox', 'bool', 'boolean'], true);
                $isSelect = ($type === 'select');
                $inputType = in_array($type, ['number', 'int', 'integer'], true) ? 'number' : ($type === 'email' ? 'email' : 'text');

                $options = [];
                if ($isSelect && is_array($field['options'] ?? null)) {
                    foreach ($field['options'] as $opt) {
                        if (is_string($opt)) {
                            $options[] = [
                                'value' => $opt,
                                'label' => $opt,
                                'selected' => ((string)$currentValue === $opt),
                            ];
                        } elseif (is_array($opt)) {
                            $options[] = [
                                'value' => (string)($opt['value'] ?? ''),
                                'label' => (string)($opt['label'] ?? $opt['value'] ?? ''),
                                'selected' => ((string)$currentValue === (string)($opt['value'] ?? '')),
                            ];
                        }
                    }
                }

                $renderedFields[] = [
                    'key' => $key,
                    'label' => (string)($field['label'] ?? $key),
                    'description' => (string)($field['description'] ?? ''),
                    'type' => $type,
                    'is_checkbox' => $isCheckbox,
                    'is_select' => $isSelect,
                    'is_text' => (!$isCheckbox && !$isSelect),
                    'input_type' => $inputType,
                    'current_value' => $isCheckbox ? '' : (string)$currentValue,
                    'is_checked' => $isCheckbox && !empty($currentValue),
                    'options' => $options,
                ];
            }
        }

        $settingsUrl = '';
        if ($hasFields) {
            $rf = ($m['_path'] ?? '') . '/routes.php';
            if (is_file($rf)) {
                $mr = require $rf;
                if (is_array($mr)) {
                    foreach ($mr as $rmethod => $routes_arr) {
                        if (!is_array($routes_arr) || strtoupper((string)$rmethod) !== 'GET') continue;
                        foreach ($routes_arr as $path => $handler) {
                            if (is_string($path) && preg_match('#^/' . preg_quote($moduleId, '#') . '/admin/settings$#', $path)) {
                                $settingsUrl = $path;
                                break 2;
                            }
                        }
                    }
                }
            }
        }

        $moduleList[] = [
            'id' => $moduleId,
            'name' => $m['name'] ?? $moduleId,
            'version' => $m['version'] ?? '0.0.0',
            'description' => $m['description'] ?? '',
            'fields' => $renderedFields,
            'settings_url' => $settingsUrl,
            'is_enabled' => !empty($enablement['runtime_enabled']),
            'has_fields' => $hasFields,
            'catalog_managed' => !empty($entitlement['catalog_managed']),
            'catalog_status' => (string)($entitlement['approval_status'] ?? 'unmanaged'),
            'commercial_mode' => (string)($entitlement['commercial_mode'] ?? 'bundled'),
            'entitlement_required' => !empty($entitlement['required']),
            'entitlement_allowed' => !empty($entitlement['allowed']),
            'entitlement_status' => (string)($entitlement['entitlement_status'] ?? 'not_required'),
            'entitlement_reason' => (string)($entitlement['reason'] ?? ''),
            'scope_sources' => $scopeSources,
            'scope_label' => superadminModuleScopeLabel($scopeSources),
            'enablement_source' => (string)($enablement['source'] ?? 'runtime_default'),
            'enablement_source_label' => (string)($enablement['source_label'] ?? 'Runtime default'),
            'has_tenant_override' => !empty($enablement['has_tenant_override']),
            'has_global_flag' => !empty($enablement['has_global_flag']),
        ];
    }

    // Build tenant list for template (pre-compute selected flag)
    $tenantOptions = [];
    $selectedTenantLabel = '';
    foreach ($tenants as $t) {
        $label = ($t['tenant_key'] ?? 'Tenant ' . $t['id'])
            . ($t['domains'] ? ' (' . $t['domains'] . ')' : '');
        $isSel = ((int)$t['id'] === $selectedTenantId);
        if ($isSel) {
            $selectedTenantLabel = $label;
        }
        $tenantOptions[] = [
            'id' => (int)$t['id'],
            'label' => $label,
            'entry_module' => (string)($t['entry_module_id'] ?? ''),
            'selected' => $isSel,
        ];
    }

    // ── CMS admin shell context ────────────────────────────────
    $baseUrl = rtrim((string)(defined('BASE_URL') ? BASE_URL : ''), '/');
    $userName = (string)($user['full_name'] ?? $user['username'] ?? $user['name'] ?? 'Superadmin');
    $userRole = (($user['source'] ?? '') === 'kernel' && ($user['role'] ?? '') === 'admin')
        ? 'Kernel Admin'
        : ucfirst($user['role'] ?? 'Superadmin');

    echo app()->render('pages/superadmin-settings.disyl', [
        'page_title' => 'Feature Settings',
        'cms_user_display' => $userName,
        'cms_user_role' => $userRole,
        'current_page' => 'settings',
        'breadcrumbs' => [
            ['label' => 'Dashboard', 'url' => $baseUrl . '/cms/admin'],
            ['label' => 'Feature Settings'],
        ],
        'modules' => $moduleList,
        'catalog_entries' => $catalogEntries,
        'catalog_pending_count' => count(array_filter($catalogEntries, static fn(array $entry): bool => (string)($entry['approval_status'] ?? '') === 'pending')),
        'access_requests' => $accessRequests,
        'access_requests_json' => json_encode($accessRequests, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'access_request_pending_count' => count(array_filter($accessRequests, static fn(array $request): bool => (string)($request['status'] ?? '') === 'pending')),
        'multi_tenant' => $multiTenant,
        'tenants' => $tenantOptions,
        'selected_tenant_id' => $selectedTenantId ?? 0,
        'selected_tenant_label' => $selectedTenantLabel,
        'module_count' => count($moduleList),
        'tenant_db_ok' => $tenantDbOk ?? true,
    ]);
    exit;
    }
}

if (!function_exists('kernelHandleApiSuperadminModules')) {
    function kernelHandleApiSuperadminModules(): void
    {
    header('Content-Type: application/json; charset=utf-8');
    header('X-Request-Id: ' . request_id());
    $user = app()->user();
    if (!$user || ($user['role'] ?? '') !== 'superadmin' || ($user['source'] ?? '') !== 'kernel') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Superadmin only']);
        exit;
    }
    $allModules = discoverModules();
    $out = [];
    foreach ($allModules as $m) {
        $moduleId = (string)($m['id'] ?? '');
        if ($moduleId === '' || empty($m['_enabled'])) continue;
        $fields = is_array($m['settings_fields'] ?? null) ? array_values($m['settings_fields']) : [];
        if (empty($fields)) continue;
        $settings = getModuleSettings($moduleId);
        $out[] = [
            'id' => $moduleId,
            'name' => $m['name'] ?? $moduleId,
            'settings_fields' => $fields,
            'settings' => is_array($settings) ? $settings : [],
        ];
    }
    echo json_encode(['ok' => true, 'modules' => $out]);
    exit;
    }
}

if (!function_exists('kernelHandleApiSuperadminUpdateModuleSettings')) {
    function kernelHandleApiSuperadminUpdateModuleSettings(): void
    {
    header('Content-Type: application/json; charset=utf-8');
    header('X-Request-Id: ' . request_id());
    $user = app()->user();
    if (!$user || ($user['role'] ?? '') !== 'superadmin' || ($user['source'] ?? '') !== 'kernel') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Superadmin only']);
        exit;
    }
    app()->csrfEnforce();

    $input = app()->input();
    $modId = trim((string)($input['module_id'] ?? ''));
    $settingsIn = $input['settings'] ?? null;
    if ($modId === '' || !is_array($settingsIn)) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'module_id and settings are required']);
        exit;
    }

    // ── Tenant scoping ──────────────────────────────────────────
    $saTenantId = null;
    $saMultiTenant = moduleTenantSettingsModeEnabled();
    if ($saMultiTenant) {
        $rawTid = $input['tenant_id'] ?? '';
        if (!ctype_digit((string)$rawTid) || (int)$rawTid <= 0) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'tenant_id is required in multi-tenant mode']);
            exit;
        }
        $saTenantId = (int)$rawTid;
        // Validate tenant exists
        try {
            $tCheck = app()->controlDb()->prepare(
                'SELECT id FROM kernel_tenants WHERE id = :tid AND status = \'active\' LIMIT 1'
            );
            $tCheck->execute([':tid' => $saTenantId]);
            if (!$tCheck->fetchColumn()) {
                http_response_code(404);
                echo json_encode(['ok' => false, 'error' => 'Tenant not found']);
                exit;
            }
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Could not verify tenant']);
            exit;
        }
    }

    $allMods = discoverModules();
    if (!isset($allMods[$modId]) || empty($allMods[$modId]['_enabled'])) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Module not found or disabled']);
        exit;
    }

    $manifest = $allMods[$modId];
    $fields = is_array($manifest['settings_fields'] ?? null) ? $manifest['settings_fields'] : [];
    $allowedKeys = [];
    foreach ($fields as $field) {
        if (!is_array($field)) continue;
        $key = trim((string)($field['key'] ?? ''));
        if ($key !== '') $allowedKeys[$key] = $field;
    }

    if ($saMultiTenant && $saTenantId !== null) {
        $oldSettings = getModuleSettingsForTenant($modId, $saTenantId);
    } else {
        $oldSettings = getModuleSettings($modId);
    }
    $newSettings = $oldSettings;

    // Superadmin can only change declared settings_fields. NOT allow_kernel_admin.
    foreach ($allowedKeys as $key => $field) {
        if (!array_key_exists($key, $settingsIn)) continue;
        $type = strtolower(trim((string)($field['type'] ?? 'text')));
        $raw = $settingsIn[$key];
        if ($type === 'checkbox' || $type === 'bool' || $type === 'boolean') {
            $newSettings[$key] = (bool)$raw;
            continue;
        }
        if ($type === 'number' || $type === 'int' || $type === 'integer') {
            $newSettings[$key] = (string)(0 + (float)$raw);
            continue;
        }
        if ($type === 'select' && is_array($field['options'] ?? null)) {
            $allowedValues = [];
            foreach ($field['options'] as $opt) {
                if (is_string($opt)) {
                    $allowedValues[$opt] = true;
                } elseif (is_array($opt) && array_key_exists('value', $opt)) {
                    $allowedValues[(string)$opt['value']] = true;
                }
            }
            $val = (string)$raw;
            if (!empty($allowedValues) && !isset($allowedValues[$val])) continue;
            $newSettings[$key] = $val;
            continue;
        }
        $newSettings[$key] = trim((string)$raw);
    }

    if ($saMultiTenant && $saTenantId !== null) {
        saveTenantModuleSettingsForTenant($modId, $saTenantId, $newSettings);
    } else {
        saveModuleSettings($modId, $newSettings);
    }

    try {
        app()->cap()->call('kernel.audit.record@1', [
            'module' => '_kernel',
            'action' => 'superadmin.module.settings.update',
            'entity_type' => 'module',
            'entity_id' => $modId,
            'old_data' => ['settings' => $oldSettings, 'tenant_id' => $saTenantId],
            'new_data' => ['settings' => $newSettings, 'tenant_id' => $saTenantId],
        ], ['mode' => 'first']);
    } catch (Throwable $e) {}

    adminViewCacheInvalidate(['admin:view:modules', 'admin:view:platform', 'admin:view:capabilities']);
    echo json_encode(['ok' => true, 'module_id' => $modId, 'settings' => $newSettings]);
    exit;
    }
}

if (!function_exists('kernelHandleApiSuperadminPerf')) {
    function kernelHandleApiSuperadminPerf(): void
    {
    header('Content-Type: application/json; charset=utf-8');
    header('X-Request-Id: ' . request_id());
    header('Cache-Control: no-store');
    $user = app()->user();
    if (!$user || ($user['role'] ?? '') !== 'superadmin' || ($user['source'] ?? '') !== 'kernel') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Superadmin only']);
        exit;
    }

    $perfResults = [];
    $perfOverall = microtime(true);

    // ── 1. DB round-trip ─────────────────────────────────────
    $t = microtime(true);
    try {
        app()->db()->query('SELECT 1');
        $perfResults['db_ping_ms'] = round((microtime(true) - $t) * 1000, 2);
        $perfResults['db_ok'] = true;
    } catch (Throwable $e) {
        $perfResults['db_ping_ms'] = null;
        $perfResults['db_ok'] = false;
    }

    // ── 2. Module discovery (cached) ──────────────────────────
    $t = microtime(true);
    $perfMods = discoverModules();
    $perfResults['module_discover_ms'] = round((microtime(true) - $t) * 1000, 2);
    $perfResults['module_count'] = count($perfMods);

    // ── 3. Module discovery (cold — bypass cache) ─────────────
    $t = microtime(true);
    discoverModules(true);
    $perfResults['module_discover_cold_ms'] = round((microtime(true) - $t) * 1000, 2);

    // ── 4. Settings preload ───────────────────────────────────
    $t = microtime(true);
    preloadAllTenantModuleSettings();
    $perfResults['settings_preload_ms'] = round((microtime(true) - $t) * 1000, 2);

    // ── 5. Cache read/write round trip ────────────────────────
    $t = microtime(true);
    $perfCacheOk = false;
    try {
        $perfCacheUri = '/__perf_probe_' . request_id() . '__';
        app()->cache()->set('_perf', $perfCacheUri, ['body' => 'ok', 'status' => 200, '_cache_expires_at' => time() + 10], 10);
        $cacheProbeResult = app()->cache()->get('_perf', $perfCacheUri);
        $perfCacheOk = is_array($cacheProbeResult) && ($cacheProbeResult['body'] ?? '') === 'ok';
        app()->cache()->clear('_perf');
    } catch (Throwable $e) {}
    $perfResults['cache_roundtrip_ms'] = round((microtime(true) - $t) * 1000, 2);
    $perfResults['cache_ok'] = $perfCacheOk;

    // ── 5b. Cache metrics snapshot ────────────────────────────
    try {
        $cacheStats = app()->cache()->getStats();
        $cacheInstances = app()->cache()->listInstances();

        $hits = (int)($cacheStats['hits'] ?? 0);
        $misses = (int)($cacheStats['misses'] ?? 0);
        $bypasses = (int)($cacheStats['bypasses'] ?? 0);
        $served = $hits + $misses;
        $total = $served + $bypasses;

        $perfResults['cache_metrics'] = [
            'hits' => $hits,
            'misses' => $misses,
            'bypasses' => $bypasses,
            'served_requests' => $served,
            'total_tracked_requests' => $total,
            'hit_rate_pct' => $served > 0 ? round(($hits / $served) * 100, 2) : 0.0,
            'miss_rate_pct' => $served > 0 ? round(($misses / $served) * 100, 2) : 0.0,
            'bypass_rate_pct' => $total > 0 ? round(($bypasses / $total) * 100, 2) : 0.0,
            'cached_files' => (int)($cacheStats['cached_files'] ?? 0),
            'active_files' => (int)($cacheStats['active_files'] ?? 0),
            'expired_files' => (int)($cacheStats['expired_files'] ?? 0),
            'total_size_mb' => (float)($cacheStats['total_size_mb'] ?? 0),
            'apcu_available' => !empty($cacheStats['apcu_available']),
            'apcu_entries' => (int)($cacheStats['apcu_entries'] ?? 0),
            'apcu_memory_bytes' => (int)($cacheStats['apcu_memory_bytes'] ?? 0),
            'instances' => $cacheInstances,
        ];
    } catch (Throwable $e) {
        $perfResults['cache_metrics'] = [
            'error' => $e->getMessage(),
        ];
    }

    // ── 6. DiSyL template render ──────────────────────────────
    $t = microtime(true);
    try {
        ob_start();
        app()->render('pages/login.disyl', ['page_title' => '__perf_probe__', 'base_url' => external_base_url()]);
        ob_get_clean();
        $perfResults['disyl_render_login_ms'] = round((microtime(true) - $t) * 1000, 2);
        $perfResults['disyl_ok'] = true;
    } catch (Throwable $e) {
        ob_get_clean();
        $perfResults['disyl_render_login_ms'] = null;
        $perfResults['disyl_ok'] = false;
        $perfResults['disyl_error'] = $e->getMessage();
    }

    $perfResults['total_ms'] = round((microtime(true) - $perfOverall) * 1000, 2);
    $perfResults['php_version'] = PHP_VERSION;
    $perfResults['peak_memory_kb'] = (int) round(memory_get_peak_usage(true) / 1024);
    $perfResults['timestamp'] = date('c');
    $perfResults['host'] = $_SERVER['HTTP_HOST'] ?? '';

    echo json_encode(['ok' => true, 'perf' => $perfResults], JSON_PRETTY_PRINT);
    exit;
    }
}

if (!function_exists('kernelHandleApiSuperadminUpdateModuleCatalog')) {
    function kernelHandleApiSuperadminUpdateModuleCatalog(): void
    {
    header('Content-Type: application/json; charset=utf-8');
    header('X-Request-Id: ' . request_id());
    $user = app()->user();
    if (!$user || ($user['role'] ?? '') !== 'superadmin' || ($user['source'] ?? '') !== 'kernel') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Forbidden']);
        exit;
    }

    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) {
        $body = [];
    }
    $csrfToken = $body['_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!is_string($csrfToken) || $csrfToken === '' || !hash_equals(app()->csrfToken(), $csrfToken)) {
        http_response_code(419);
        echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
        exit;
    }

    $modId = trim((string)($body['module_id'] ?? ''));
    $approvalStatus = strtolower(trim((string)($body['approval_status'] ?? 'pending')));
    $commercialMode = strtolower(trim((string)($body['commercial_mode'] ?? 'free')));
    if ($modId === '' || !in_array($approvalStatus, ['pending', 'approved', 'rejected'], true)) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'module_id and a valid approval_status are required']);
        exit;
    }
    if (!in_array($commercialMode, ['free', 'freemium', 'paid'], true)) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'commercial_mode must be free, freemium, or paid']);
        exit;
    }

    $existingCatalog = moduleCatalogEntry($modId);
    if (!is_array($existingCatalog)) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Catalog entry not found']);
        exit;
    }

    if ($approvalStatus === 'approved') {
        $allMods = discoverModules();
        if (!isset($allMods[$modId])) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Module must exist on disk before it can be approved']);
            exit;
        }
    }

    $ok = updateModuleCatalogApproval($modId, $approvalStatus, [
        'commercial_mode' => $commercialMode,
        'approved_by_user_id' => (int)($user['id'] ?? 0),
        'metadata' => ['via' => 'apiSuperadminUpdateModuleCatalog'],
    ]);
    if (!$ok) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Failed to update module catalog entry']);
        exit;
    }

    $updatedCatalog = moduleCatalogEntry($modId);
    try {
        app()->cap()->call('kernel.audit.record@1', [
            'module' => '_kernel',
            'action' => 'superadmin.module.catalog.update',
            'entity_type' => 'module',
            'entity_id' => $modId,
            'old_data' => $existingCatalog,
            'new_data' => $updatedCatalog,
        ], ['mode' => 'first']);
    } catch (Throwable $e) {}

    kernelFlushCodeCaches();
    adminViewCacheInvalidate(['admin:view:modules', 'admin:view:platform', 'admin:view:capabilities']);
    echo json_encode(['ok' => true, 'module_id' => $modId, 'catalog' => $updatedCatalog]);
    exit;
    }
}

if (!function_exists('kernelHandleApiSuperadminReviewModuleAccessRequest')) {
    function kernelHandleApiSuperadminReviewModuleAccessRequest(): void
    {
    header('Content-Type: application/json; charset=utf-8');
    header('X-Request-Id: ' . request_id());
    $user = app()->user();
    if (!$user || ($user['role'] ?? '') !== 'superadmin' || ($user['source'] ?? '') !== 'kernel') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Forbidden']);
        exit;
    }

    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) {
        $body = [];
    }
    $csrfToken = $body['_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!is_string($csrfToken) || $csrfToken === '' || !hash_equals(app()->csrfToken(), $csrfToken)) {
        http_response_code(419);
        echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
        exit;
    }

    $requestId = isset($body['request_id']) ? (int)$body['request_id'] : 0;
    $requestStatus = strtolower(trim((string)($body['status'] ?? '')));
    $reviewNotes = trim((string)($body['review_notes'] ?? ''));
    if ($requestId <= 0 || !in_array($requestStatus, ['approved', 'rejected'], true)) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'request_id and a valid status are required']);
        exit;
    }

    $existingRequest = moduleAccessRequestById($requestId);
    if (!is_array($existingRequest)) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Access request not found']);
        exit;
    }

    $reviewResult = reviewModuleAccessRequest($requestId, $requestStatus, [
        'reviewed_by_user_id' => (int)($user['id'] ?? 0),
        'review_notes' => $reviewNotes,
        'source' => 'superadmin_access_request_review',
        'license_provider' => (string)($body['license_provider'] ?? ''),
    ]);
    if (empty($reviewResult['ok'])) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => (string)($reviewResult['error'] ?? 'Failed to review access request')]);
        exit;
    }

    try {
        app()->cap()->call('kernel.audit.record@1', [
            'module' => '_kernel',
            'action' => 'superadmin.module.access_request.review',
            'entity_type' => 'module_access_request',
            'entity_id' => (string)$requestId,
            'old_data' => $existingRequest,
            'new_data' => $reviewResult['request'] ?? null,
        ], ['mode' => 'first']);
    } catch (Throwable $e) {}

    kernelFlushCodeCaches();
    adminViewCacheInvalidate(['admin:view:modules', 'admin:view:platform', 'admin:view:capabilities']);
    echo json_encode([
        'ok' => true,
        'request' => $reviewResult['request'] ?? null,
        'entitlement' => $reviewResult['entitlement'] ?? null,
        'license_activation' => $reviewResult['activation'] ?? null,
    ]);
    exit;
    }
}

if (!function_exists('kernelHandleApiSuperadminSetModuleEntitlement')) {
    function kernelHandleApiSuperadminSetModuleEntitlement(): void
    {
    header('Content-Type: application/json; charset=utf-8');
    header('X-Request-Id: ' . request_id());
    $user = app()->user();
    if (!$user || ($user['role'] ?? '') !== 'superadmin' || ($user['source'] ?? '') !== 'kernel') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Forbidden']);
        exit;
    }

    if (!(bool) config('app.multi_tenant.enabled', false)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Tenant entitlements require multi-tenant mode']);
        exit;
    }

    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) {
        $body = [];
    }
    $csrfToken = $body['_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!is_string($csrfToken) || $csrfToken === '' || !hash_equals(app()->csrfToken(), $csrfToken)) {
        http_response_code(419);
        echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
        exit;
    }

    $modId = trim((string)($body['module_id'] ?? ''));
    $tenantId = isset($body['tenant_id']) ? (int)$body['tenant_id'] : 0;
    $entitled = (bool)($body['entitled'] ?? false);
    $requestedStatus = strtolower(trim((string)($body['status'] ?? ($entitled ? 'active' : 'revoked'))));
    $catalogTier = moduleCatalogCommercialMode($modId);
    if ($catalogTier === '') {
        $catalogTier = 'free';
    }
    $defaultTier = moduleCatalogDefaultEntitlementTier($modId, $catalogTier);
    $tier = trim((string)($body['tier'] ?? $defaultTier));
    $expiresAt = trim((string)($body['expires_at'] ?? ''));

    if ($modId === '' || $tenantId <= 0) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'module_id and tenant_id are required']);
        exit;
    }

    try {
        $tenantStmt = app()->controlDb()->prepare(
            'SELECT id FROM kernel_tenants WHERE id = :tenant_id AND status = \'active\' LIMIT 1'
        );
        $tenantStmt->execute([':tenant_id' => $tenantId]);
        if (!$tenantStmt->fetchColumn()) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Tenant not found']);
            exit;
        }
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Could not verify tenant']);
        exit;
    }

    $allMods = discoverModules();
    if (!isset($allMods[$modId])) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Module not found']);
        exit;
    }

    if (!moduleCatalogIsApproved($modId)) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Only approved catalog modules can be entitled per tenant']);
        exit;
    }

    $ok = false;
    $entitlement = null;
    $licenseActivation = ['ok' => true, 'status' => 'skipped', 'reason' => 'not_requested'];
    $pendingRequest = moduleLatestAccessRequestForTenant($modId, $tenantId);
    if ($entitled) {
        if (!in_array($requestedStatus, ['active', 'trial'], true)) {
            $requestedStatus = 'active';
        }
        if (is_array($pendingRequest) && (int)($pendingRequest['id'] ?? 0) > 0) {
            $reviewResult = reviewModuleAccessRequest((int)$pendingRequest['id'], 'approved', [
                'reviewed_by_user_id' => (int)($user['id'] ?? 0),
                'review_notes' => trim((string)($body['review_notes'] ?? 'Approved via entitlement grant')),
                'entitlement_status' => $requestedStatus,
                'tier' => $tier !== '' ? $tier : $defaultTier,
                'source' => 'superadmin',
                'license_provider' => (string)($body['license_provider'] ?? ''),
            ]);
            $ok = !empty($reviewResult['ok']);
            $entitlement = $reviewResult['entitlement'] ?? null;
            $licenseActivation = $reviewResult['activation'] ?? $licenseActivation;
        } else {
            $ok = grantModuleEntitlementForTenant($modId, $tenantId, [
                'status' => $requestedStatus,
                'tier' => $tier !== '' ? $tier : $defaultTier,
                'source' => 'superadmin',
                'granted_by_user_id' => (int)($user['id'] ?? 0),
                'expires_at' => $expiresAt,
                'metadata' => ['via' => 'apiSuperadminSetModuleEntitlement'],
            ]);
            if ($ok) {
                $licenseActivation = invokeModuleLicenseActivation([
                    'module_id' => $modId,
                    'tenant_id' => $tenantId,
                    'requested_mode' => $tier !== '' ? $tier : $catalogTier,
                    'commercial_mode' => $catalogTier,
                    'license_key' => trim((string)($body['license_key'] ?? '')),
                    'license_ref' => trim((string)($body['license_ref'] ?? '')),
                    'reviewed_by_user_id' => (int)($user['id'] ?? 0),
                    'source' => 'superadmin_entitlement_grant',
                ], [
                    'provider' => (string)($body['license_provider'] ?? ''),
                ]);
            }
        }
    } else {
        $ok = revokeModuleEntitlementForTenant($modId, $tenantId, [
            'tier' => $tier !== '' ? $tier : $defaultTier,
            'source' => 'superadmin',
            'granted_by_user_id' => (int)($user['id'] ?? 0),
            'metadata' => ['via' => 'apiSuperadminSetModuleEntitlement'],
        ]);
        if ($ok) {
            disableModuleForTenant($modId, $tenantId);
        }
    }

    if (!$ok) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Failed to update tenant entitlement']);
        exit;
    }

    if (!is_array($entitlement)) {
        $entitlement = moduleTenantEntitlementStatus($modId, $tenantId);
    }
    try {
        app()->cap()->call('kernel.audit.record@1', [
            'module' => '_kernel',
            'action' => $entitled ? 'superadmin.module.entitlement.grant' : 'superadmin.module.entitlement.revoke',
            'entity_type' => 'module',
            'entity_id' => $modId,
            'old_data' => ['tenant_id' => $tenantId, 'entitled' => !$entitled],
            'new_data' => ['tenant_id' => $tenantId, 'entitled' => $entitled, 'entitlement' => $entitlement],
        ], ['mode' => 'first']);
    } catch (Throwable $e) {}

    kernelFlushCodeCaches();
    adminViewCacheInvalidate(['admin:view:modules', 'admin:view:platform', 'admin:view:capabilities']);
    echo json_encode([
        'ok' => true,
        'module_id' => $modId,
        'tenant_id' => $tenantId,
        'entitlement' => $entitlement,
        'license_activation' => $licenseActivation,
    ]);
    exit;
    }
}

if (!function_exists('kernelHandleApiSuperadminToggleModule')) {
    function kernelHandleApiSuperadminToggleModule(): void
    {
    header('Content-Type: application/json; charset=utf-8');
    header('X-Request-Id: ' . request_id());
    $user = app()->user();
    if (!$user || ($user['role'] ?? '') !== 'superadmin' || ($user['source'] ?? '') !== 'kernel') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Forbidden']);
        exit;
    }
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) $body = [];
    $csrfToken = $body['_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!is_string($csrfToken) || $csrfToken === '' || !hash_equals(app()->csrfToken(), $csrfToken)) {
        http_response_code(419);
        echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
        exit;
    }

    $modId = trim((string)($body['module_id'] ?? ''));
    $enabled = (bool)($body['enabled'] ?? false);
    $toggleTenantId = isset($body['tenant_id']) ? (int)$body['tenant_id'] : null;

    if ($modId === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'module_id is required']);
        exit;
    }

    $toggleMultiTenant = (bool) config('app.multi_tenant.enabled', false);
    if ($toggleMultiTenant && $toggleTenantId === null) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'tenant_id is required']);
        exit;
    }

    // Verify tenant has a DB connection
    if ($toggleMultiTenant && $toggleTenantId !== null) {
        try {
            $tDb = app()->dbForTenant($toggleTenantId);
            if ($tDb === null) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'Tenant has no database connection configured']);
                exit;
            }
        } catch (Throwable $e) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Cannot connect to tenant database']);
            exit;
        }
    }

    // If enabling, validate the module exists
    if ($enabled) {
        $allMods = discoverModules();
        $targetMod = null;
        foreach ($allMods as $dm) {
            if (($dm['id'] ?? '') === $modId) { $targetMod = $dm; break; }
        }
        if ($targetMod === null) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Module not found']);
            exit;
        }

        if ($toggleMultiTenant && $toggleTenantId !== null) {
            $entitlement = moduleTenantEntitlementStatus($modId, $toggleTenantId);
            if (!empty($entitlement['required']) && empty($entitlement['allowed'])) {
                if (moduleCatalogModeAllowsSelfService((string)($entitlement['commercial_mode'] ?? '')) && ($entitlement['entitlement_status'] ?? '') === 'missing') {
                    ensureSelfServiceModuleEntitlementForTenant($modId, $toggleTenantId, [
                        'source' => 'superadmin_enable',
                        'granted_by_user_id' => (int)($user['id'] ?? 0),
                        'metadata' => ['via' => 'apiSuperadminToggleModule'],
                    ]);
                    $entitlement = moduleTenantEntitlementStatus($modId, $toggleTenantId);
                }

                if (!empty($entitlement['required']) && empty($entitlement['allowed'])) {
                    http_response_code(422);
                    echo json_encode([
                        'ok' => false,
                        'error' => 'Tenant is not entitled to enable this module',
                        'entitlement_status' => $entitlement['entitlement_status'] ?? 'unknown',
                        'commercial_mode' => $entitlement['commercial_mode'] ?? 'bundled',
                    ]);
                    exit;
                }
            }
        }
    }

    if ($toggleMultiTenant && $toggleTenantId !== null) {
        if ($enabled) {
            enableModuleForTenant($modId, $toggleTenantId);
        } else {
            disableModuleForTenant($modId, $toggleTenantId);
        }
    } else {
        if ($enabled) {
            enableModule($modId);
        } else {
            disableModule($modId);
        }
    }

    try {
        app()->cap()->call('kernel.audit.record@1', [
            'module' => '_kernel',
            'action' => $enabled ? 'superadmin.module.enable' : 'superadmin.module.disable',
            'entity_type' => 'module',
            'entity_id' => $modId,
            'old_data' => ['enabled' => !$enabled, 'tenant_id' => $toggleTenantId],
            'new_data' => ['enabled' => $enabled, 'tenant_id' => $toggleTenantId],
        ], ['mode' => 'first']);
    } catch (Throwable $e) {}

    kernelFlushCodeCaches();
    adminViewCacheInvalidate(['admin:view:modules', 'admin:view:platform', 'admin:view:capabilities']);
    echo json_encode(['ok' => true, 'module_id' => $modId, 'enabled' => $enabled]);
    exit;
    }
}

// ════════════════════════════════════════════════════════════════════════
// CACHE OBSERVABILITY (kernel superadmin)
//
// Surfaces per-instance cache stats so kernel admins can see the impact of
// fragment / page caches without ssh-grepping. Read-only by default; flush
// actions are explicit POST endpoints.
// ════════════════════════════════════════════════════════════════════════

if (!function_exists('kernelHandleApiSuperadminCache')) {
    function kernelHandleApiSuperadminCache(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('X-Request-Id: ' . request_id());
        header('Cache-Control: no-store');

        $user = app()->user();
        if (!$user || ($user['role'] ?? '') !== 'superadmin' || ($user['source'] ?? '') !== 'kernel') {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Superadmin only']);
            return;
        }

        echo json_encode(kernelBuildCacheObservabilitySnapshot());
    }
}

if (!function_exists('kernelHandleApiSuperadminCacheFlush')) {
    function kernelHandleApiSuperadminCacheFlush(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('X-Request-Id: ' . request_id());
        header('Cache-Control: no-store');

        $user = app()->user();
        if (!$user || ($user['role'] ?? '') !== 'superadmin' || ($user['source'] ?? '') !== 'kernel') {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Superadmin only']);
            return;
        }

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            http_response_code(405);
            echo json_encode(['ok' => false, 'error' => 'POST required']);
            return;
        }

        $body = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($body)) { $body = []; }

        $target = (string)($body['target'] ?? '');     // 'instance' | 'all' | 'fragments'
        $instanceId = trim((string)($body['instance_id'] ?? ''));

        $cleared = 0;
        try {
            switch ($target) {
                case 'instance':
                    if ($instanceId === '') {
                        http_response_code(422);
                        echo json_encode(['ok' => false, 'error' => 'instance_id required']);
                        return;
                    }
                    if (!preg_match('/^[A-Za-z0-9_\-\.]+$/', $instanceId)) {
                        http_response_code(422);
                        echo json_encode(['ok' => false, 'error' => 'invalid instance_id']);
                        return;
                    }
                    $cleared = (int)app()->cache()->clear($instanceId);
                    break;

                case 'all':
                    $result = app()->cache()->clearAll();
                    $cleared = is_array($result) ? array_sum(array_map('intval', $result)) : (int)$result;
                    break;

                case 'fragments':
                    // DiSyL fragment store flush (per-tenant scope = current tenant).
                    if (class_exists(\Ikabud\Kernel\DiSyL\Cache\FragmentStore::class)) {
                        $tenantId = (string)(app()->tenant()->current() ?? '_global');
                        (new \Ikabud\Kernel\DiSyL\Cache\FragmentStore())->flushAll($tenantId);
                    }
                    $cleared = -1; // sentinel: flushAll doesn't return a count
                    break;

                default:
                    http_response_code(422);
                    echo json_encode(['ok' => false, 'error' => 'Unknown target']);
                    return;
            }
        } catch (\Throwable $e) {
            write_log('superadmin cache flush failed: ' . $e->getMessage(), 'error', [
                'target' => $target, 'instance_id' => $instanceId,
            ]);
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Flush failed']);
            return;
        }

        echo json_encode([
            'ok' => true,
            'target' => $target,
            'instance_id' => $instanceId !== '' ? $instanceId : null,
            'cleared' => $cleared,
        ]);
    }
}

if (!function_exists('kernelBuildCacheObservabilitySnapshot')) {
    /**
     * Build a JSON-serialisable snapshot of cache state for the dashboard.
     *
     * @return array<string,mixed>
     */
    function kernelBuildCacheObservabilitySnapshot(): array
    {
        $cache = app()->cache();
        $stats = [];
        try { $stats = $cache->getStats(); } catch (\Throwable $e) { $stats = []; }

        $instances = [];
        try { $instances = $cache->listInstances(); } catch (\Throwable $e) { $instances = []; }

        $instanceRows = [];
        foreach ($instances as $id => $info) {
            $info = is_array($info) ? $info : [];
            // Count tag-index files (granular invalidation tags actually written).
            $tagCount = 0;
            $instanceDir = STORAGE_PATH . '/cache/' . $id;
            if (is_dir($instanceDir)) {
                $tagFiles = glob($instanceDir . '/.tag_*.idx') ?: [];
                $tagCount = count($tagFiles);
            }
            $instanceRows[] = [
                'id'           => (string)$id,
                'files'        => (int)($info['files'] ?? 0),
                'size_bytes'   => (int)($info['size_bytes'] ?? 0),
                'size_mb'      => (float)($info['size_mb'] ?? 0),
                'tag_count'    => $tagCount,
            ];
        }
        usort($instanceRows, static fn($a, $b) => $b['size_bytes'] <=> $a['size_bytes']);

        // Fragment store (DiSyL 4.3) — file-backed, per-tenant scope.
        $fragments = ['files' => 0, 'size_bytes' => 0, 'tenants' => 0, 'enabled' => false];
        $fragRoot = STORAGE_PATH . '/cache/disyl-fragments';
        if (is_dir($fragRoot)) {
            $fragments['enabled'] = true;
            $tenantDirs = glob($fragRoot . '/*', GLOB_ONLYDIR) ?: [];
            $fragments['tenants'] = count($tenantDirs);
            foreach ($tenantDirs as $td) {
                $files = glob($td . '/*') ?: [];
                foreach ($files as $f) {
                    if (is_file($f)) {
                        $fragments['files']++;
                        $fragments['size_bytes'] += (int)@filesize($f);
                    }
                }
            }
            $fragments['size_mb'] = round($fragments['size_bytes'] / 1024 / 1024, 2);
        }

        return [
            'ok'        => true,
            'timestamp' => date('c'),
            'global'    => [
                'hits'             => (int)($stats['hits'] ?? 0),
                'misses'           => (int)($stats['misses'] ?? 0),
                'bypasses'         => (int)($stats['bypasses'] ?? 0),
                'errors'           => (int)($stats['errors'] ?? 0),
                'hit_rate'         => (string)($stats['hit_rate'] ?? '0%'),
                'cached_files'     => (int)($stats['cached_files'] ?? 0),
                'active_files'     => (int)($stats['active_files'] ?? 0),
                'expired_files'    => (int)($stats['expired_files'] ?? 0),
                'total_size_mb'    => (float)($stats['total_size_mb'] ?? 0),
                'max_size_mb'      => (int)($stats['max_size_mb'] ?? 0),
                'apcu_available'   => (bool)($stats['apcu_available'] ?? false),
                'apcu_entries'     => (int)($stats['apcu_entries'] ?? 0),
                'apcu_memory_mb'   => isset($stats['apcu_memory_bytes'])
                    ? round(((int)$stats['apcu_memory_bytes']) / 1024 / 1024, 2) : 0.0,
            ],
            'instances' => $instanceRows,
            'fragments' => $fragments,
        ];
    }
}
