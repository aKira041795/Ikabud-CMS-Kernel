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
    $otherModuleList = [];
    foreach ($allModules as $m) {
        // Skip service-modules in the settings UI — they are managed by the kernel
        $moduleType = trim((string)($m['type'] ?? 'php-module'));
        if ($moduleType === 'service-module') {
            continue;
        }

        $moduleId = (string)($m['id'] ?? '');
        if ($moduleId === '') {
            continue;
        }

        // Determine relevance: in multi-tenant mode, non-relevant modules go to other list
        $isRelevant = true;
        if ($multiTenant && $selectedTenantId !== null && is_array($tenantRelevantModules)) {
            $isRelevant = isset($tenantRelevantModules[$moduleId]);
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

        $entry = [
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

        if ($isRelevant) {
            $moduleList[] = $entry;
        } else {
            $otherModuleList[] = $entry;
        }
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

    echo app()->render('pages/superadmin-settings.disyl', array_merge(
        kernelAdminContext($user, 'settings'),
        [
            'page_title' => 'Feature Settings',
            'breadcrumbs' => [
                ['label' => 'Platform', 'url' => '/admin/platform'],
                ['label' => 'Feature Settings'],
            ],
            'modules' => $moduleList,
            'other_modules' => $otherModuleList,
            'other_module_count' => count($otherModuleList),
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
        ],
    ));
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

// ────────────────────────────────────────────────────────────────
// ARK Workbench — superadmin developer observability
// ────────────────────────────────────────────────────────────────

if (!function_exists('workbenchDiscoverTestFiles')) {
    /**
     * Discover valid test files under tests/ for the Workbench test registry.
     * This is the SINGLE source of truth for which files the Workbench may execute.
     * Both the page handler and trigger-test API use this to prevent arbitrary execution.
     *
     * @return array<int, array{module: string, file: string, path: string, realpath: string}>
     */
    function workbenchDiscoverTestFiles(): array
    {
        $tests = [];
        $projectRoot = dirname(__DIR__, 2);
        $testBase = $projectRoot . '/tests';
        $skipDirs = ['harness', 'browser', 'ai', 'test_results', 'bench'];

        $testSubdirs = glob($testBase . '/*', GLOB_ONLYDIR) ?: [];
        foreach ($testSubdirs as $subdir) {
            $dir = basename($subdir);
            if (in_array($dir, $skipDirs, true)) continue;
            $testFiles = glob($subdir . '/*_test.php') ?: [];
            foreach ($testFiles as $tf) {
                $base = basename($tf);
                if (str_contains($base, '_seed_') || str_contains($base, '_interactive')) continue;
                $resolved = realpath($tf);
                if ($resolved === false) continue;
                $tests[] = [
                    'module'   => $dir,
                    'file'     => $base,
                    'path'     => 'tests/' . $dir . '/' . $base,
                    'realpath' => $resolved,
                ];
            }
        }
        return $tests;
    }
}

/**
 * Check whether the Workbench is allowed to execute tests on this environment.
 * Returns true if test execution is permitted (development or IKABUD_DEV_WORKBENCH=true).
 */
if (!function_exists('workbenchExecutionAllowed')) {
    function workbenchExecutionAllowed(): bool
    {
        $env = app()->config('app.env', 'production');
        if ($env !== 'production') {
            return true;
        }
        // Explicit opt-in overrides production block
        $devEnv = trim((string)($_ENV['IKABUD_DEV_WORKBENCH'] ?? $_SERVER['IKABUD_DEV_WORKBENCH'] ?? ''));
        if (filter_var($devEnv, FILTER_VALIDATE_BOOL)) {
            return true;
        }
        return false;
    }
}

if (!function_exists('kernelHandlePageSuperadminWorkbench')) {
    function kernelHandlePageSuperadminWorkbench(): void
    {
        $user = app()->requireAuth();
        if (($user['role'] ?? '') !== 'superadmin' || ($user['source'] ?? '') !== 'kernel') {
            app()->redirect('/');
            exit;
        }

        // API Keys
        $apiKeys = [];
        try {
            $akAuth = new \Ikabud\Kernel\Services\ApiKeyAuth(app()->db());
            if ($akAuth->tableExists()) {
                $apiKeys = $akAuth->listKeys(0);
            }
        } catch (\Throwable $e) {
            $apiKeys = [];
        }

        // AI Models
        $models = [];
        $aiModuleEnabled = false;
        $aiProvidersSettings = [];
        $aiSettings = [];
        $allModules = discoverModules();
        if (isset($allModules['ai']) && !empty($allModules['ai']['_enabled'])) {
            $aiModuleEnabled = true;
            // Read from global registry first, then overlay tenant-specific settings.
            // In multi-tenant mode, getModuleSettings() only returns lifecycle keys from
            // global + tenant-specific storage. AI provider config is global infrastructure,
            // so we always merge in the global settings. This makes the Workbench page show
            // the correct values regardless of tenant context.
            $aiSettings = getModuleSettings('ai');
            if (function_exists('readModuleRegistry')) {
                $globalReg = readModuleRegistry();
                $globalAiSettings = $globalReg['ai']['settings'] ?? [];
                if (is_array($globalAiSettings)) {
                    $aiSettings = array_merge($globalAiSettings, $aiSettings);
                }
            }
            $providers = [
                'openai'      => ['name' => 'OpenAI',          'key_setting' => 'openai_api_key',          'model_free' => 'openai_model_free',  'model_paid' => 'openai_model_paid',  'model_custom' => 'openai_model'],
                'groq'        => ['name' => 'Groq',            'key_setting' => 'groq_api_key',            'model_free' => 'groq_model_free',   'model_paid' => 'groq_model_paid',   'model_custom' => 'groq_model'],
                'gemini'      => ['name' => 'Gemini',          'key_setting' => 'gemini_api_key',          'model_free' => 'gemini_model_free',  'model_paid' => 'gemini_model_paid',  'model_custom' => 'gemini_model'],
                'mistral'     => ['name' => 'Mistral',         'key_setting' => 'mistral_api_key',         'model_free' => 'mistral_model_free', 'model_paid' => 'mistral_model_paid', 'model_custom' => 'mistral_model'],
                'cerebras'    => ['name' => 'Cerebras',        'key_setting' => 'cerebras_api_key',        'model_free' => 'cerebras_model_free','model_paid' => 'cerebras_model_paid','model_custom' => 'cerebras_model'],
                'openrouter'  => ['name' => 'OpenRouter',      'key_setting' => 'openrouter_api_key',      'model_free' => 'openrouter_model_free','model_paid' => 'openrouter_model_paid','model_custom' => 'openrouter_model'],
                'ollama'      => ['name' => 'Ollama (local)',  'key_setting' => 'ollama_base_url',         'model_free' => 'ollama_model_free', 'model_paid' => 'ollama_model_paid', 'model_custom' => 'ollama_model'],
            ];
            foreach ($providers as $pid => $pinfo) {
                $rawKey = trim((string)($aiSettings[$pinfo['key_setting']] ?? ''));
                $hasKey = $rawKey !== '';
                $models[] = [
                    'provider'       => $pinfo['name'],
                    'provider_id'    => $pid,
                    'has_key'        => $hasKey,
                    'key_setting'    => $pinfo['key_setting'],
                    'model_free'     => (string)($aiSettings[$pinfo['model_free']] ?? ''),
                    'model_paid'     => (string)($aiSettings[$pinfo['model_paid']] ?? ''),
                    'model_custom'   => (string)($aiSettings[$pinfo['model_custom']] ?? ''),
                    'model_free_key' => $pinfo['model_free'],
                    'model_paid_key' => $pinfo['model_paid'],
                    'model_custom_key' => $pinfo['model_custom'],
                ];
            }
            $aiProvidersSettings = json_encode($models, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        // Test Results
        $testResults = [];
        $resultsDir = dirname(__DIR__, 2) . '/test_results';
        if (is_dir($resultsDir)) {
            $files = glob($resultsDir . '/*.json');
            if (is_array($files)) {
                rsort($files);
                foreach (array_slice($files, 0, 30) as $f) {
                    $content = @json_decode((string)file_get_contents($f), true);
                    if (is_array($content)) {
                        $summary = $content['summary'] ?? [];
                        $testResults[] = [
                            'file'     => basename($f),
                            'suite'    => $content['suite'] ?? basename($f, '.json'),
                            'passed'   => (int)($summary['passed'] ?? 0),
                            'failed'   => (int)($summary['failed'] ?? 0),
                            'total'    => (int)($summary['total'] ?? 0),
                            'gaps'     => count($content['gaps'] ?? []),
                            'elapsed'  => (float)($summary['elapsed_ms'] ?? $content['elapsed_ms'] ?? 0),
                            'finished' => (string)($content['finished'] ?? ''),
                        ];
                    }
                }
            }
        }

        // Discoverable tests — dynamically scan tests/ for *_test.php files
        $discoverableTests = workbenchDiscoverTestFiles();

        echo app()->render('pages/superadmin-workbench.disyl', array_merge(
            kernelAdminContext($user, 'workbench'),
            [
                'page_title'         => 'ARK Workbench',
                'api_keys'           => $apiKeys,
                'api_key_count'      => count($apiKeys),
                'models'             => $models,
                'ai_module_enabled'  => $aiModuleEnabled,
                'ai_providers_json'  => $aiProvidersSettings,
                'test_results'       => $testResults,
                'test_results_count' => count($testResults),
                'discoverable_tests' => $discoverableTests,
                'discoverable_count' => count($discoverableTests),
                'workbench_execution_allowed' => workbenchExecutionAllowed(),
                'workbench_ai_enabled' => (bool)($aiSettings['workbench_ai_enabled'] ?? false),
                'workbench_ai_provider' => (string)($aiSettings['workbench_ai_provider'] ?? $aiSettings['provider'] ?? ''),
                'workbench_ai_tier' => (string)($aiSettings['workbench_ai_tier'] ?? 'free'),
                'workbench_ai_model' => (string)($aiSettings['workbench_ai_model'] ?? ''),
                'workbench_ai_timeout_ms' => (int)($aiSettings['workbench_ai_timeout_ms'] ?? 15000),
                'workbench_ai_max_tokens' => (int)($aiSettings['workbench_ai_max_tokens'] ?? 2000),
            ]
        ));
        exit;
    }
}

if (!function_exists('kernelHandleApiSuperadminWorkbenchKeys')) {
    function kernelHandleApiSuperadminWorkbenchKeys(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('X-Request-Id: ' . request_id());
        $user = app()->user();
        if (!$user || ($user['role'] ?? '') !== 'superadmin' || ($user['source'] ?? '') !== 'kernel') {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Superadmin only']);
            exit;
        }
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if ($method === 'GET') {
            try {
                $akAuth = new \Ikabud\Kernel\Services\ApiKeyAuth(app()->db());
                if (!$akAuth->tableExists()) {
                    echo json_encode(['ok' => true, 'keys' => []]);
                    exit;
                }
                $keys = $akAuth->listKeys(0);
                echo json_encode(['ok' => true, 'keys' => $keys]);
            } catch (\Throwable $e) {
                http_response_code(500);
                echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
            }
            exit;
        }
        if ($method === 'POST') {
            app()->csrfEnforce();
            $input = app()->input();
            $action = trim((string)($input['action'] ?? 'create'));
            try {
                $akAuth = new \Ikabud\Kernel\Services\ApiKeyAuth(app()->db());
                if (!$akAuth->tableExists()) {
                    http_response_code(500);
                    echo json_encode(['ok' => false, 'error' => 'API keys table not found']);
                    exit;
                }
                if ($action === 'revoke') {
                    $keyId = (int)($input['id'] ?? 0);
                    if ($keyId <= 0) {
                        http_response_code(400);
                        echo json_encode(['ok' => false, 'error' => 'Invalid key ID']);
                        exit;
                    }
                    $ok = $akAuth->revokeKey($keyId, 0);
                    echo json_encode(['ok' => $ok, 'revoked' => $ok]);
                    exit;
                }
                $name = trim((string)($input['name'] ?? ''));
                if ($name === '') {
                    http_response_code(400);
                    echo json_encode(['ok' => false, 'error' => 'Key name required']);
                    exit;
                }
                $scopes = is_array($input['scopes'] ?? null) ? $input['scopes'] : [];
                $scopes = array_values(array_filter(array_map('trim', array_map('strval', $scopes))));
                $result = $akAuth->createKey(0, $name, $scopes, [
                    'created_by' => (int)($user['id'] ?? 0),
                ]);
                echo json_encode([
                    'ok'     => true,
                    'key'    => $result['key'],
                    'prefix' => $result['prefix'],
                    'name'   => $result['name'],
                    'id'     => $result['id'],
                ]);
            } catch (\Throwable $e) {
                http_response_code(500);
                echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
            }
            exit;
        }
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
        exit;
    }
}

if (!function_exists('kernelHandleApiSuperadminWorkbenchTestResults')) {
    function kernelHandleApiSuperadminWorkbenchTestResults(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('X-Request-Id: ' . request_id());
        $user = app()->user();
        if (!$user || ($user['role'] ?? '') !== 'superadmin' || ($user['source'] ?? '') !== 'kernel') {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Superadmin only']);
            exit;
        }
        $suite = trim((string)($_GET['suite'] ?? ''));
        // Sanitize suite name to prevent path traversal
        $suite = basename($suite);
        if ($suite !== '' && !preg_match('/^[a-zA-Z0-9_.-]+$/', $suite)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Invalid suite name']);
            exit;
        }
        $resultsDir = dirname(__DIR__, 2) . '/test_results';
        $file = $resultsDir . '/' . ($suite !== '' ? $suite . '.json' : 'discover-summary.json');
        if (!is_file($file)) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Results not found']);
            exit;
        }
        $content = json_decode((string)file_get_contents($file), true);
        echo json_encode(['ok' => true, 'results' => $content], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (!function_exists('kernelHandleApiSuperadminWorkbenchTriggerTests')) {
    function kernelHandleApiSuperadminWorkbenchTriggerTests(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('X-Request-Id: ' . request_id());
        $user = app()->user();
        if (!$user || ($user['role'] ?? '') !== 'superadmin' || ($user['source'] ?? '') !== 'kernel') {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Superadmin only']);
            exit;
        }

        // ── Production gate: no test execution unless explicitly opted in ──
        if (!workbenchExecutionAllowed()) {
            http_response_code(403);
            echo json_encode([
                'ok' => false,
                'error' => 'Test execution is disabled in production. Set IKABUD_DEV_WORKBENCH=true env var to enable.',
            ]);
            exit;
        }

        app()->csrfEnforce();
        $input = app()->input();
        $target = trim((string)($input['target'] ?? 'all'));
        $projectRoot = dirname(__DIR__, 2);

        $cmd = null;
        if ($target === 'ark-hybrid') {
            $moduleId = trim((string)($input['module'] ?? ''));
            $gate = trim((string)($input['gate'] ?? 'critical'));
            if (!preg_match('/^[A-Za-z0-9_-]+$/', $moduleId)) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'A valid module id is required']);
                exit;
            }
            if (!in_array($gate, ['critical', 'major', 'off'], true)) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'Invalid ARK gate']);
                exit;
            }
            $modules = discoverModules();
            if (!isset($modules[$moduleId]) || empty($modules[$moduleId]['_enabled'])) {
                http_response_code(404);
                echo json_encode(['ok' => false, 'error' => 'Enabled module not found: ' . $moduleId]);
                exit;
            }
            $launcher = $projectRoot . '/tests/browser/run-workbench.js';
            if (!is_file($launcher)) {
                http_response_code(500);
                echo json_encode(['ok' => false, 'error' => 'ARK Hybrid launcher is unavailable']);
                exit;
            }
            $cmd = 'node ' . escapeshellarg($launcher)
                . ' --module=' . escapeshellarg($moduleId)
                . ' --gate=' . escapeshellarg($gate) . ' 2>&1';
        } elseif ($target === 'module') {
            // Run all PHP tests for a specific module
            $moduleId = trim((string)($input['module'] ?? ''));
            if (!preg_match('/^[A-Za-z0-9_-]+$/', $moduleId)) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'A valid module id is required']);
                exit;
            }
            $modules = discoverModules();
            if (!isset($modules[$moduleId]) || empty($modules[$moduleId]['_enabled'])) {
                http_response_code(404);
                echo json_encode(['ok' => false, 'error' => 'Enabled module not found: ' . $moduleId]);
                exit;
            }
            $discoverRunner = $projectRoot . '/tests/discover.php';
            if (!is_file($discoverRunner)) {
                http_response_code(500);
                echo json_encode(['ok' => false, 'error' => 'Test discover runner is unavailable']);
                exit;
            }
            $cmd = 'php ' . escapeshellarg($discoverRunner)
                . ' --module=' . escapeshellarg($moduleId) . ' 2>&1';
        }

        if ($cmd === null) {
            // ── Build the authoritative PHP test registry ──
            $registry = workbenchDiscoverTestFiles();
            $registryByPath = [];
            $registryRealpaths = [];
            foreach ($registry as $entry) {
                $registryByPath[$entry['path']] = $entry;
                $registryRealpaths[$entry['realpath']] = $entry;
            }

        @file_put_contents($projectRoot . '/storage/logs/app.log', '');
        @file_put_contents($projectRoot . '/storage/logs/error.log', '');

            if ($target === 'all') {
                $cmd = 'php ' . escapeshellarg($projectRoot . '/tests/discover.php') . ' 2>&1';
            } else {
            // ── Registry validation ──
            // Step 1: Check if target matches a registry entry by relative path
            $targetClean = ltrim($target, '/');
            if (isset($registryByPath[$targetClean])) {
                $validPath = $registryByPath[$targetClean]['realpath'];
            } else {
                // Step 2: Resolve to absolute path and check against registry realpaths
                $resolved = realpath($projectRoot . '/' . $targetClean);
                if ($resolved === false) {
                    http_response_code(400);
                    echo json_encode(['ok' => false, 'error' => 'Test file not found: ' . $target]);
                    exit;
                }
                // Step 3: Verify the resolved path is under the tests/ directory
                $testsDir = realpath($projectRoot . '/tests');
                if ($testsDir === false || strpos($resolved, $testsDir) !== 0) {
                    http_response_code(403);
                    echo json_encode(['ok' => false, 'error' => 'Executable path must be under tests/ directory']);
                    exit;
                }
                // Step 4: Verify path is in the registry (authorized test)
                if (!isset($registryRealpaths[$resolved])) {
                    http_response_code(403);
                    echo json_encode(['ok' => false, 'error' => 'Test file is not in the authorized test registry']);
                    exit;
                }
                $validPath = $resolved;
            }

            if (!is_file($validPath)) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'Test file not found: ' . $target]);
                exit;
            }
                $cmd = 'php ' . escapeshellarg($validPath) . ' 2>&1';
            }
        }

        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($cmd, $descriptors, $pipes, $projectRoot);
        if (!is_resource($process)) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Failed to start test process']);
            exit;
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        $output = $stdout . $stderr;
        $appLog = $projectRoot . '/storage/logs/app.log';
        $errorLog = $projectRoot . '/storage/logs/error.log';
        $appLogSize = is_file($appLog) ? filesize($appLog) : 0;
        $errorLogSize = is_file($errorLog) ? filesize($errorLog) : 0;
        echo json_encode([
            'ok'             => $exitCode === 0,
            'exit_code'      => $exitCode,
            'output'         => $output,
            'app_log_bytes'  => $appLogSize,
            'error_log_bytes'=> $errorLogSize,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (!function_exists('kernelHandleApiSuperadminWorkbenchAiSettings')) {
    function kernelHandleApiSuperadminWorkbenchAiSettings(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('X-Request-Id: ' . request_id());
        $user = app()->user();
        if (!$user || ($user['role'] ?? '') !== 'superadmin' || ($user['source'] ?? '') !== 'kernel') {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Superadmin only']);
            exit;
        }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            http_response_code(405);
            echo json_encode(['ok' => false, 'error' => 'POST required']);
            exit;
        }
        app()->csrfEnforce();

        $input = app()->input();
        if (($input['scope'] ?? '') === 'workbench_policy') {
            $provider = trim((string)($input['provider'] ?? ''));
            $validProviders = ['', 'openai', 'groq', 'gemini', 'mistral', 'cerebras', 'openrouter', 'ollama'];
            $tier = trim((string)($input['tier'] ?? 'free'));
            if (!in_array($provider, $validProviders, true) || !in_array($tier, ['free', 'paid', 'custom'], true)) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'Invalid Workbench AI policy']);
                exit;
            }
            $registry = readModuleRegistry();
            $settings = is_array($registry['ai']['settings'] ?? null) ? $registry['ai']['settings'] : [];
            $settings['workbench_ai_enabled'] = filter_var($input['enabled'] ?? false, FILTER_VALIDATE_BOOL);
            $settings['workbench_ai_provider'] = $provider;
            $settings['workbench_ai_tier'] = $tier;
            $settings['workbench_ai_model'] = trim((string)($input['model'] ?? ''));
            $settings['workbench_ai_timeout_ms'] = max(1000, min(60000, (int)($input['timeout_ms'] ?? 15000)));
            $settings['workbench_ai_max_tokens'] = max(256, min(8000, (int)($input['max_tokens'] ?? 2000)));
            $settings['workbench_ai_max_evidence_bytes'] = max(4096, min(131072, (int)($input['max_evidence_bytes'] ?? 32768)));
            $registry['ai']['settings'] = $settings;
            writeModuleRegistry($registry);
            echo json_encode(['ok' => true, 'message' => 'Workbench AI policy saved', 'policy' => $settings]);
            exit;
        }
        $providerId = trim((string)($input['provider'] ?? ''));
        $apiKey = trim((string)($input['api_key'] ?? ''));
        $modelFree = trim((string)($input['model_free'] ?? ''));
        $modelPaid = trim((string)($input['model_paid'] ?? ''));
        $modelCustom = trim((string)($input['model_custom'] ?? ''));

        if ($providerId === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Provider ID required']);
            exit;
        }

        // Validate provider
        $validProviders = ['openai', 'groq', 'gemini', 'mistral', 'cerebras', 'openrouter', 'ollama'];
        if (!in_array($providerId, $validProviders, true)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Invalid provider']);
            exit;
        }

        $allModules = discoverModules();
        if (!isset($allModules['ai']) || empty($allModules['ai']['_enabled'])) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'AI module is not enabled']);
            exit;
        }

        // Read current settings, merge with new values
        $current = getModuleSettings('ai');
        $sensitiveKeys = function_exists('aiSensitiveKeyNames') ? aiSensitiveKeyNames() : [];

        // Determine the setting keys for this provider
        $keySetting = $providerId === 'ollama' ? 'ollama_base_url' : $providerId . '_api_key';
        $freeSetting = $providerId . '_model_free';
        $paidSetting = $providerId . '_model_paid';
        $customSetting = $providerId . '_model';

        // Update fields — only overwrite if provided (API key can be left blank to keep)
        if ($apiKey !== '') {
            $current[$keySetting] = $apiKey;
        }
        if ($modelFree !== '') {
            $current[$freeSetting] = $modelFree;
        }
        if ($modelPaid !== '') {
            $current[$paidSetting] = $modelPaid;
        }
        if ($modelCustom !== '') {
            $current[$customSetting] = $modelCustom;
        }

        // Encrypt sensitive keys before saving
        if (function_exists('aiEncryptSensitiveSettings')) {
            $current = aiEncryptSensitiveSettings($current);
        }

        // Save directly to global registry.
        // We CANNOT use saveModuleSettings() here because in multi-tenant mode
        // with no tenant context (superadmin at /superadmin/workbench), it refuses
        // to save globally — it only writes to tenant-scoped storage.
        // AI provider settings (API keys, models) are global infrastructure, not
        // per-tenant, so they belong in the global module registry.
        $registry = null;
        if (function_exists('readModuleRegistry') && function_exists('writeModuleRegistry')) {
            $registry = readModuleRegistry();
            $existing = [];
            if (isset($registry['ai']['settings']) && is_array($registry['ai']['settings'])) {
                $existing = $registry['ai']['settings'];
            }
            $registry['ai'] = array_merge($registry['ai'] ?? [], [
                'settings' => array_merge($existing, $current),
            ]);
            writeModuleRegistry($registry);
        } else {
            // Fallback: use saveModuleSettings (may not persist in tenant mode)
            saveModuleSettings('ai', $current);
        }

        echo json_encode(['ok' => true, 'message' => ucfirst($providerId) . ' settings saved']);
        exit;
    }
}

if (!function_exists('kernelHandleApiSuperadminWorkbenchRuns')) {
    /** Read a Workbench JSON artifact without allowing callers to choose a path. */
    function workbenchReadJsonArtifact(string $path): ?array
    {
        if (!is_file($path)) return null;
        $value = @json_decode((string)file_get_contents($path), true);
        return is_array($value) ? $value : null;
    }

    /**
     * Correlate the current ARK engines by their canonical run id.
     *
     * @return array<string, array<string, mixed>>
     */
    function workbenchHybridRuns(string $root): array
    {
        $locations = [
            'reporter' => [$root . '/test_results/browser/runs', 'manifest.json'],
            'analyst' => [$root . '/test_results/analyst', 'system-analyst-report.json'],
            'comprehension' => [$root . '/test_results/ai/runs', 'comprehension-report.json'],
            'scenario' => [$root . '/test_results/scenarios', 'scenario-run.json'],
            'intelligence' => [$root . '/test_results/browser/runs', 'pattern-intelligence.json'],
        ];
        $runs = [];
        foreach ($locations as $engine => [$base, $filename]) {
            foreach (glob($base . '/*/' . $filename) ?: [] as $path) {
                $runId = basename(dirname($path));
                if (!preg_match('/^[A-Za-z0-9._-]+$/', $runId)) continue;
                $artifact = workbenchReadJsonArtifact($path);
                if ($artifact === null) continue;
                $runs[$runId] ??= ['run_id' => $runId, 'artifacts' => []];
                $runs[$runId]['artifacts'][$engine] = $artifact;
                $runs[$runId]['artifact_files'][$engine] = $path;
            }
        }
        foreach (glob($root . '/storage/workbench/runs/*.json') ?: [] as $path) {
            $runId = basename($path, '.json');
            if (!preg_match('/^[A-Za-z0-9._-]+$/', $runId)) continue;
            $artifact = workbenchReadJsonArtifact($path);
            if ($artifact === null || ($artifact['run_id'] ?? null) !== $runId) continue;
            $runs[$runId] ??= ['run_id' => $runId, 'artifacts' => []];
            $runs[$runId]['artifacts']['contract'] = $artifact;
            $runs[$runId]['artifact_files']['contract'] = $path;
        }

        foreach ($runs as $runId => &$run) {
            $analyst = $run['artifacts']['analyst'] ?? [];
            $reporter = $run['artifacts']['reporter'] ?? [];
            $comprehension = $run['artifacts']['comprehension'] ?? [];
            $scenario = $run['artifacts']['scenario'] ?? [];
            $intelligence = $run['artifacts']['intelligence'] ?? [];
            $contract = $run['artifacts']['contract'] ?? [];
            $analysis = $comprehension['analysis'] ?? [];
            $module = (string)($contract['module'] ?? $reporter['module'] ?? $analyst['module'] ?? $analysis['module'] ?? ($scenario['scenario']['module'] ?? $intelligence['module'] ?? 'unknown'));
            $finished = (string)($contract['finished_at'] ?? $scenario['finished_at'] ?? $intelligence['generated_at'] ?? $analyst['generated_at'] ?? $comprehension['generated_at'] ?? '');
            $issues = (array)($analyst['issues'] ?? []);
            $critical = count(array_filter($issues, static fn($issue) => ($issue['severity'] ?? '') === 'critical'));
            $major = count(array_filter($issues, static fn($issue) => ($issue['severity'] ?? '') === 'major'));
            $gatePassed = (bool)($analyst['ux_evolution']['gate']['passed'] ?? true);
            $scenarioOk = !isset($scenario['status']) || $scenario['status'] === 'completed';
            $hasBreakpoint = ($analysis['breakpoint'] ?? null) !== null;
            $suiteTotals = ['passed' => 0, 'failed' => 0, 'skipped' => 0, 'timed_out' => 0, 'interrupted' => 0, 'total' => 0];
            foreach ((array)($reporter['suites'] ?? []) as $suite) {
                foreach ($suiteTotals as $key => $_) $suiteTotals[$key] += (int)($suite[$key] ?? 0);
            }
            if (($reporter['suites'] ?? []) === [] && $contract !== []) {
                foreach ((array)($contract['executions'] ?? []) as $execution) {
                    $suiteTotals['total']++;
                    if (!empty($execution['timed_out'])) {
                        $suiteTotals['timed_out']++;
                    } elseif ((int)($execution['exit_code'] ?? 1) === 0) {
                        $suiteTotals['passed']++;
                    } else {
                        $suiteTotals['failed']++;
                    }
                }
                if (($contract['outcome'] ?? '') === 'blocked' && $suiteTotals['total'] === 0) {
                    $suiteTotals['failed'] = 1;
                    $suiteTotals['total'] = 1;
                }
            }
            $run += [
                'module' => $module,
                'finished' => $finished,
                'issues' => count($issues),
                'critical' => $critical,
                'major' => $major,
                'ux_score' => $analyst['ux_evolution']['score'] ?? null,
                'ux_gate_passed' => $gatePassed,
                'comprehension_score' => $analysis['coverage_score']['overall_score'] ?? null,
                'breakpoint' => $analysis['breakpoint'] ?? null,
                'scenario_status' => $scenario['status'] ?? null,
                'cleanup_clean' => $scenario['cleanup_result']['clean'] ?? null,
                'summary' => $suiteTotals,
                'status' => ($suiteTotals['failed'] > 0 || $suiteTotals['timed_out'] > 0 || $suiteTotals['interrupted'] > 0 || $critical > 0 || !$gatePassed || !$scenarioOk || $hasBreakpoint || ($contract !== [] && ($contract['outcome'] ?? 'failed') !== 'passed')) ? 'failed' : 'passed',
            ];
        }
        unset($run);
        return $runs;
    }

    function workbenchHybridRunDetail(string $root, string $runId): ?array
    {
        if (!preg_match('/^[A-Za-z0-9._-]+$/', $runId)) return null;
        $runs = workbenchHybridRuns($root);
        if (!isset($runs[$runId])) return null;
        unset($runs[$runId]['artifact_files']);
        return $runs[$runId];
    }

    function workbenchRecursiveSpecCount(string $directory): int
    {
        if (!is_dir($directory)) return 0;
        $count = 0;
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.spec.js')) $count++;
        }
        return $count;
    }

    function workbenchObservedClaimedRoutes(array $testContract, array $analyst): int
    {
        $claimed = array_merge((array)($testContract['routes_claimed']['GET'] ?? []), (array)($testContract['routes_claimed']['POST'] ?? []));
        $observed = [];
        foreach ((array)($analyst['pages'] ?? []) as $page) {
            $path = (string)(parse_url((string)($page['url'] ?? ''), PHP_URL_PATH) ?: '');
            if ($path !== '') $observed[$path] = true;
        }
        $matched = 0;
        foreach ($claimed as $route) {
            $route = (string)(is_array($route) ? ($route['path'] ?? $route['route'] ?? '') : $route);
            if ($route === '') continue;
            $pattern = preg_quote($route, '#');
            $pattern = preg_replace('#\\\\\{[^}]+\\\\\}|:[A-Za-z_][A-Za-z0-9_]*#', '[^/]+', $pattern);
            foreach (array_keys($observed) as $path) {
                if (preg_match('#^' . $pattern . '$#', $path)) { $matched++; break; }
            }
        }
        return $matched;
    }

    function kernelHandleApiSuperadminWorkbenchRuns(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('X-Request-Id: ' . request_id());
        $user = app()->user();
        if (!$user || ($user['role'] ?? '') !== 'superadmin' || ($user['source'] ?? '') !== 'kernel') {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Superadmin only']);
            exit;
        }

        $resultsDir = dirname(__DIR__, 2) . '/test_results';
        $runs = [];

        foreach (workbenchHybridRuns(dirname(__DIR__, 2)) as $hybrid) {
            $runs[] = [
                'file' => 'run:' . $hybrid['run_id'],
                'run_id' => $hybrid['run_id'],
                'suite' => $hybrid['module'] . ' / ' . $hybrid['run_id'],
                'module' => $hybrid['module'],
                'type' => isset($hybrid['artifacts']['contract']) && count($hybrid['artifacts']) === 1 ? 'ark-contract' : 'ark-hybrid',
                'started' => (string)($hybrid['artifacts']['contract']['started_at'] ?? ''),
                'finished' => $hybrid['finished'],
                'elapsed_ms' => 0,
                'passed' => (int)($hybrid['summary']['passed'] ?? 0),
                'failed' => (int)($hybrid['summary']['failed'] ?? 0),
                'timed_out' => (int)($hybrid['summary']['timed_out'] ?? 0),
                'interrupted' => (int)($hybrid['summary']['interrupted'] ?? 0),
                'skipped' => (int)($hybrid['summary']['skipped'] ?? 0),
                'total' => (int)($hybrid['summary']['total'] ?? 0),
                'gate_failed' => $hybrid['status'] === 'failed',
                'gaps' => $hybrid['issues'],
                'issues' => $hybrid['issues'],
                'artifacts' => array_keys($hybrid['artifacts']),
                'ux_score' => $hybrid['ux_score'],
                'comprehension_score' => $hybrid['comprehension_score'],
                'scenario_status' => $hybrid['scenario_status'],
            ];
        }

        // Collect PHP test results
        if (is_dir($resultsDir)) {
            $files = glob($resultsDir . '/*.json');
            if (is_array($files)) {
                rsort($files);
                foreach (array_slice($files, 0, 50) as $f) {
                    $content = @json_decode((string)file_get_contents($f), true);
                    if (!is_array($content)) continue;
                    $summary = $content['summary'] ?? [];
                    $fingerprints = $content['source_fingerprints'] ?? [];
                    $gaps = $content['gaps'] ?? [];
                    $type = 'php';
                    if (str_contains($f, 'browser/')) $type = 'browser';
                    if (str_contains($f, 'ai/')) $type = 'ai';

                    $runs[] = [
                        'file'       => basename($f),
                        'suite'      => $content['suite'] ?? basename($f, '.json'),
                        'type'       => $type,
                        'started'    => (string)($content['started'] ?? ''),
                        'finished'   => (string)($content['finished'] ?? ''),
                        'elapsed_ms' => (float)($summary['elapsed_ms'] ?? $content['elapsed_ms'] ?? 0),
                        'passed'     => (int)($summary['passed'] ?? 0),
                        'failed'     => (int)($summary['failed'] ?? 0),
                        'skipped'    => (int)($summary['skipped'] ?? 0),
                        'total'      => (int)($summary['total'] ?? 0),
                        'gaps'       => count($gaps),
                        'fingerprints' => count($fingerprints),
                        'exit_code'  => (int)($summary['exit_code'] ?? -1),
                    ];
                }
            }
        }

        // Collect browser test results
        $browserDir = $resultsDir . '/browser';
        if (is_dir($browserDir)) {
            $browserFiles = glob($browserDir . '/*.json');
            if (is_array($browserFiles)) {
                rsort($browserFiles);
                foreach ($browserFiles as $f) {
                    $content = @json_decode((string)file_get_contents($f), true);
                    if (!is_array($content)) continue;
                    if (basename($f) === 'manifest.json' || basename($f) === 'issue-report.json') continue;
                    $summary = $content['summary'] ?? [];
                    $browserName = '';
                    if (preg_match('/--(\w+)\.json$/', basename($f), $bm)) {
                        $browserName = $bm[1];
                    }
                    $runs[] = [
                        'file'       => 'browser/' . basename($f),
                        'suite'      => $content['suite'] ?? basename($f, '.json'),
                        'type'       => 'browser',
                        'browser'    => $browserName,
                        'started'    => (string)($content['started'] ?? ''),
                        'finished'   => (string)($content['finished'] ?? ''),
                        'elapsed_ms' => 0,
                        'passed'     => (int)($summary['passed'] ?? 0),
                        'failed'     => (int)($summary['failed'] ?? 0),
                        'skipped'    => (int)($summary['skipped'] ?? 0),
                        'total'      => (int)($summary['total'] ?? 0),
                        'gaps'       => count($content['gaps'] ?? []),
                        'fingerprints' => count($content['source_fingerprints'] ?? []),
                        'issues'     => count($content['issues'] ?? []),
                    ];
                }
            }
        }

        // Sort by finished time descending
        usort($runs, function ($a, $b) {
            return strcmp($b['finished'] ?? '', $a['finished'] ?? '');
        });

        echo json_encode(['ok' => true, 'runs' => $runs, 'total' => count($runs)]);
        exit;
    }
}

if (!function_exists('kernelHandleApiSuperadminWorkbenchRunDetail')) {
    function kernelHandleApiSuperadminWorkbenchRunDetail(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('X-Request-Id: ' . request_id());
        $user = app()->user();
        if (!$user || ($user['role'] ?? '') !== 'superadmin' || ($user['source'] ?? '') !== 'kernel') {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Superadmin only']);
            exit;
        }

        $input = app()->input();
        $runId = trim((string)($input['run_id'] ?? ($_GET['run_id'] ?? '')));
        if ($runId !== '') {
            $detail = workbenchHybridRunDetail(dirname(__DIR__, 2), $runId);
            if ($detail === null) {
                http_response_code(404);
                echo json_encode(['ok' => false, 'error' => 'ARK run not found: ' . $runId]);
                exit;
            }
            $kind = isset($detail['artifacts']['contract']) && count($detail['artifacts']) === 1 ? 'ark-contract' : 'ark-hybrid';
            echo json_encode(['ok' => true, 'kind' => $kind, 'run' => $detail], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }
        $suite = trim((string)($input['suite'] ?? ($_GET['suite'] ?? '')));
        $suite = basename($suite);

        if ($suite === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Suite name required']);
            exit;
        }

        // Try main results dir first, then browser subdir
        $resultsDir = dirname(__DIR__, 2) . '/test_results';
        $paths = [
            $resultsDir . '/' . $suite . '.json',
            $resultsDir . '/browser/' . $suite . '.json',
            $resultsDir . '/ai/' . $suite . '.json',
        ];

        $content = null;
        foreach ($paths as $p) {
            if (is_file($p)) {
                $content = @json_decode((string)file_get_contents($p), true);
                if (is_array($content)) break;
            }
        }

        if (!$content) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Run not found: ' . $suite]);
            exit;
        }

        echo json_encode(['ok' => true, 'run' => $content], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (!function_exists('kernelHandleApiSuperadminWorkbenchIssues')) {
    function kernelHandleApiSuperadminWorkbenchIssues(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('X-Request-Id: ' . request_id());
        $user = app()->user();
        if (!$user || ($user['role'] ?? '') !== 'superadmin' || ($user['source'] ?? '') !== 'kernel') {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Superadmin only']);
            exit;
        }

        $resultsDir = dirname(__DIR__, 2) . '/test_results';

        // Load browser issue report
        $issueReportFile = $resultsDir . '/browser/issue-report.json';
        $issues = is_file($issueReportFile) ? @json_decode((string)file_get_contents($issueReportFile), true) : null;

        // Also scan all test_results JSON for failures/gaps
        $failureIssues = [];
        $allFiles = array_merge(
            glob($resultsDir . '/*.json') ?: [],
            glob($resultsDir . '/browser/*.json') ?: []
        );
        foreach ($allFiles as $f) {
            $content = @json_decode((string)file_get_contents($f), true);
            if (!is_array($content)) continue;
            $summary = $content['summary'] ?? [];
            if (($summary['failed'] ?? 0) > 0) {
                $failureIssues[] = [
                    'suite'     => $content['suite'] ?? basename($f, '.json'),
                    'kind'      => 'test-failure',
                    'severity'  => 'critical',
                    'detail'    => ($summary['failed'] ?? 0) . ' test(s) failed',
                    'where'     => basename($f),
                ];
            }
            foreach ($content['gaps'] ?? [] as $gap) {
                $failureIssues[] = [
                    'suite'    => $content['suite'] ?? basename($f, '.json'),
                    'kind'     => 'gap',
                    'severity' => 'minor',
                    'detail'   => is_string($gap) ? $gap : (json_encode($gap) ?: ''),
                    'where'    => basename($f),
                ];
            }
        }

        // Collect AI steward diagnosis
        $aiReportFile = $resultsDir . '/ai/steward-diagnosis.json';
        $aiDiagnosis = is_file($aiReportFile) ? @json_decode((string)file_get_contents($aiReportFile), true) : null;

        $allIssues = [];
        if ($issues && !empty($issues['issues'])) {
            $allIssues = $issues['issues'];
        }
        $allIssues = array_merge($allIssues, $failureIssues);

        // Current System Analyst and Comprehension artifacts are run-scoped.
        // Surface their evidence here instead of relying only on legacy flat files.
        $hybridRuns = workbenchHybridRuns(dirname(__DIR__, 2));
        uasort($hybridRuns, static fn($a, $b) => strcmp((string)($b['finished'] ?? ''), (string)($a['finished'] ?? '')));
        $currentArkDiagnosis = null;
        foreach (array_slice($hybridRuns, 0, 10, true) as $runId => $hybrid) {
            $intelligence = $hybrid['artifacts']['intelligence'] ?? [];
            if ($currentArkDiagnosis === null && $intelligence !== []) {
                $assessment = $intelligence['ai_assessment'] ?? [];
                $trace = $assessment['provider_trace'] ?? [];
                $claim = $assessment['claims'][0] ?? [];
                $currentArkDiagnosis = [
                    'schema' => $intelligence['schema'] ?? 'ark.pattern-intelligence.v1',
                    'run_id' => $runId,
                    'classification' => 'pattern-intelligence / ' . ($intelligence['conformance_verdict'] ?? 'unknown'),
                    'confidence' => (float)($intelligence['latent_quality']['confidence'] ?? $claim['confidence'] ?? 0),
                    'summary' => (string)($claim['text'] ?? ($intelligence['latent_quality']['verdict'] ?? 'ARK final evidence assembled')),
                    'evidence' => array_values((array)($intelligence['final_evidence']['sources'] ?? [])),
                    'suspected_files' => [],
                    'recommended_action' => 'Mode: ' . ($assessment['mode'] ?? 'deterministic') . '; provider: ' . ($trace['provider'] ?? 'none') . '; policy: ' . ($intelligence['effective_ai_policy']['fallback'] ?? 'default'),
                    'provider_trace' => $trace,
                    'claim_validation' => $assessment['validation'] ?? null,
                ];
            }
            foreach (($hybrid['artifacts']['analyst']['issues'] ?? []) as $issue) {
                $allIssues[] = [
                    'id' => $issue['fingerprint'] ?? null,
                    'run_id' => $runId,
                    'suite' => $hybrid['module'],
                    'module' => $hybrid['module'],
                    'kind' => $issue['kind'] ?? 'analyst-observation',
                    'severity' => $issue['severity'] ?? 'note',
                    'detail' => $issue['detail'] ?? '',
                    'where' => $issue['component'] ?? $issue['url'] ?? '',
                    'classification' => $issue['classification'] ?? null,
                    'confidence' => $issue['confidence'] ?? null,
                    'source_engine' => 'system-analyst',
                ];
            }
            $analysis = $hybrid['artifacts']['comprehension']['analysis'] ?? [];
            if (($analysis['breakpoint'] ?? null) !== null) {
                $allIssues[] = [
                    'run_id' => $runId,
                    'suite' => $hybrid['module'],
                    'module' => $hybrid['module'],
                    'kind' => 'comprehension-breakpoint',
                    'severity' => $analysis['root_cause_hypothesis']['severity'] ?? 'major',
                    'detail' => $analysis['root_cause_hypothesis']['summary'] ?? 'ARK Comprehension breakpoint',
                    'where' => $analysis['breakpoint'],
                    'confidence' => $analysis['confidence']['score'] ?? null,
                    'source_engine' => 'comprehension',
                ];
            }
        }
        $latestLearnedDiagnosis = null;

        $ledgerFile = dirname(__DIR__, 2) . '/kernel/Workbench/Issues/IssueLedger.php';
        if (is_file($ledgerFile)) {
            require_once $ledgerFile;
            try {
                $ledger = new \Ikabud\Kernel\Workbench\Issues\IssueLedger(dirname(__DIR__, 2) . '/storage/private/workbench/issues');
                foreach ($ledger->all() as $learnedIssue) {
                    $allIssues[] = [
                        'id' => $learnedIssue['id'], 'suite' => $learnedIssue['module_id'],
                        'kind' => $learnedIssue['category'], 'severity' => $learnedIssue['severity'],
                        'detail' => $learnedIssue['summary'], 'where' => $learnedIssue['failing_node'],
                        'state' => $learnedIssue['state'], 'occurrences' => count($learnedIssue['occurrences'] ?? []),
                    ];
                    $diagnoses = $learnedIssue['diagnoses'] ?? [];
                    if ($latestLearnedDiagnosis === null && $diagnoses !== []) {
                        $diagnosis = $diagnoses[count($diagnoses) - 1];
                        $hypothesis = $diagnosis['hypotheses'][0] ?? [];
                        $trace = $diagnosis['provider_trace'] ?? [];
                        $latestLearnedDiagnosis = [
                            'classification' => 'configured-ai',
                            'confidence' => (float)($hypothesis['confidence'] ?? 0),
                            'summary' => (string)($hypothesis['summary'] ?? ''),
                            'evidence' => array_values((array)($hypothesis['evidence_for'] ?? [])),
                            'suspected_files' => array_values((array)($hypothesis['suspected_nodes'] ?? [])),
                            'recommended_action' => isset($trace['fallback_reason']) && $trace['fallback_reason'] !== null
                                ? 'Heuristic fallback: ' . $trace['fallback_reason']
                                : 'Provider ' . ($trace['provider'] ?? '?') . ' / model ' . ($trace['model'] ?? '?'),
                            'provider_trace' => $trace,
                        ];
                    }
                }
            } catch (\Throwable $e) {
                // The file-based ledger is supplementary; legacy reports remain available.
            }
        }
        if ($currentArkDiagnosis !== null) $aiDiagnosis = $currentArkDiagnosis;
        elseif ($aiDiagnosis === null && $latestLearnedDiagnosis !== null) $aiDiagnosis = $latestLearnedDiagnosis;

        $severityCounts = [];
        $kindCounts = [];
        foreach ($allIssues as $issue) {
            $severity = (string)($issue['severity'] ?? 'note');
            $kind = (string)($issue['kind'] ?? 'issue');
            $severityCounts[$severity] = ($severityCounts[$severity] ?? 0) + 1;
            $kindCounts[$kind] = ($kindCounts[$kind] ?? 0) + 1;
        }

        echo json_encode([
            'ok' => true,
            'issues' => $allIssues,
            'total' => count($allIssues),
            'by_severity' => $severityCounts,
            'by_kind' => $kindCounts,
            'ai_diagnosis' => $aiDiagnosis,
        ]);
        exit;
    }
}

if (!function_exists('kernelHandleApiSuperadminWorkbenchModules')) {
    function kernelHandleApiSuperadminWorkbenchModules(): void
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
        $resultsDir = dirname(__DIR__, 2) . '/test_results';
        $contractsDir = dirname(__DIR__, 2) . '/tests/contracts/modules';

        $modulesData = [];
        foreach ($allModules as $moduleId => $manifest) {
            if (empty($manifest['_enabled'])) continue;
            $modPath = modulePathForId($moduleId) ?? '';
            if ($modPath === '') continue;

            $contractFile = $modPath . '/test-contract.json';
            $hasContract = is_file($contractFile);
            $contract = $hasContract ? @json_decode((string)file_get_contents($contractFile), true) : null;
            $testContract = is_array($contract) ? ($contract['test_contract'] ?? null) : null;

            // Count test files
            $phpTests = count(glob($modPath . '/tests/*_test.php') ?: []) + count(glob(dirname(__DIR__, 2) . '/tests/' . $moduleId . '/*_test.php') ?: []);
            $browserDir = dirname(__DIR__, 2) . '/tests/browser/modules/' . $moduleId;
            $browserTests = workbenchRecursiveSpecCount($browserDir);

            // Check last run status
            $lastRun = null;
            $runFiles = glob($resultsDir . '/' . $moduleId . '*.json') ?: [];
            if (!empty($runFiles)) {
                rsort($runFiles);
                $lastContent = @json_decode((string)file_get_contents($runFiles[0]), true);
                if (is_array($lastContent)) {
                    $summary = $lastContent['summary'] ?? [];
                    $lastRun = [
                        'file'      => basename($runFiles[0]),
                        'finished'  => (string)($lastContent['finished'] ?? ''),
                        'passed'    => (int)($summary['passed'] ?? 0),
                        'failed'    => (int)($summary['failed'] ?? 0),
                        'total'     => (int)($summary['total'] ?? 0),
                    ];
                }
            }

            foreach (workbenchHybridRuns(dirname(__DIR__, 2)) as $hybrid) {
                if (($hybrid['module'] ?? '') !== $moduleId) continue;
                if ($lastRun !== null && strcmp((string)$lastRun['finished'], (string)$hybrid['finished']) >= 0) continue;
                $lastRun = [
                    'file' => 'run:' . $hybrid['run_id'],
                    'run_id' => $hybrid['run_id'],
                    'finished' => $hybrid['finished'],
                    'passed' => $hybrid['status'] === 'passed' ? 1 : 0,
                    'failed' => $hybrid['status'] === 'failed' ? 1 : 0,
                    'total' => 1,
                    'type' => 'ark-hybrid',
                    'engines' => array_keys($hybrid['artifacts']),
                ];
            }

            $entry = [
                'module'        => $moduleId,
                'name'          => $manifest['name'] ?? $moduleId,
                'version'       => $manifest['version'] ?? '0.0.0',
                'has_contract'  => $hasContract,
                'php_tests'     => $phpTests,
                'browser_tests' => $browserTests,
                'last_run'      => $lastRun,
            ];
            if (function_exists('validateModuleCertification')) {
                $entry['certification'] = validateModuleCertification($manifest);
            }

            if ($testContract) {
                $entry['contract'] = [
                    'roles'        => $testContract['roles'] ?? [],
                    'page_families' => $testContract['page_families'] ?? [],
                    'workflows'    => array_keys($testContract['workflows'] ?? []),
                    'routes'       => (count($testContract['routes_claimed']['GET'] ?? []) + count($testContract['routes_claimed']['POST'] ?? [])),
                    'capabilities' => count($testContract['capabilities_claimed'] ?? []),
                    'required_tests' => $testContract['required_tests'] ?? [],
                ];
            }

            $modulesData[$moduleId] = $entry;
        }

        echo json_encode(['ok' => true, 'modules' => $modulesData, 'total' => count($modulesData)]);
        exit;
    }
}

if (!function_exists('kernelHandleApiSuperadminWorkbenchCoverage')) {
    function kernelHandleApiSuperadminWorkbenchCoverage(): void
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
        $resultsDir = dirname(__DIR__, 2) . '/test_results';

        $coverage = [];
        $hybridByModule = [];
        foreach (workbenchHybridRuns(dirname(__DIR__, 2)) as $hybrid) {
            $module = (string)($hybrid['module'] ?? '');
            if ($module === '') continue;
            if (!isset($hybridByModule[$module]) || strcmp((string)$hybrid['finished'], (string)$hybridByModule[$module]['finished']) > 0) {
                $hybridByModule[$module] = $hybrid;
            }
        }
        $totalRoutesClaimed = 0;
        $totalRoutesTested = 0;
        $totalCapsClaimed = 0;
        $totalCapsTested = 0;
        $totalWorkflowsClaimed = 0;
        $totalWorkflowTransitions = 0;
        $totalWorkflowTransitionsTested = 0;

        foreach ($allModules as $moduleId => $manifest) {
            if (empty($manifest['_enabled'])) continue;
            $modPath = modulePathForId($moduleId) ?? '';
            if ($modPath === '') continue;

            $contractFile = $modPath . '/test-contract.json';
            $contract = is_file($contractFile) ? @json_decode((string)file_get_contents($contractFile), true) : null;
            $testContract = is_array($contract) ? ($contract['test_contract'] ?? null) : null;

            if (!$testContract) continue;

            $routesClaimed = count($testContract['routes_claimed']['GET'] ?? []) + count($testContract['routes_claimed']['POST'] ?? []);
            $capsClaimed = count($testContract['capabilities_claimed'] ?? []);
            $workflowTransitions = 0;
            foreach (($testContract['workflows'] ?? []) as $states) {
                if (is_array($states)) {
                    $workflowTransitions += count($states);
                }
            }

            // Count actual test files that exist
            $phpTestFiles = $testContract['test_files']['php'] ?? [];
            $browserTestFiles = $testContract['test_files']['browser'] ?? [];
            $existingPhpTests = 0;
            foreach ($phpTestFiles as $tf) {
                if (is_file(dirname(__DIR__, 2) . '/' . $tf)) $existingPhpTests++;
            }
            $existingBrowserTests = 0;
            foreach ($browserTestFiles as $tf) {
                if (is_file(dirname(__DIR__, 2) . '/' . $tf)) $existingBrowserTests++;
            }

            // Page family coverage
            $pageFamilies = $testContract['page_families'] ?? [];
            $pageFamilyCoverage = [];
            foreach ($pageFamilies as $pf) {
                $pageFamilyCoverage[$pf] = 'untested';
            }

            // Check last run
            $runFiles = glob($resultsDir . '/' . $moduleId . '*.json') ?: [];
            $lastPassed = 0;
            $lastFailed = 0;
            if (!empty($runFiles)) {
                rsort($runFiles);
                $lastContent = @json_decode((string)file_get_contents($runFiles[0]), true);
                if (is_array($lastContent)) {
                    $summary = $lastContent['summary'] ?? [];
                    $lastPassed = (int)($summary['passed'] ?? 0);
                    $lastFailed = (int)($summary['failed'] ?? 0);
                }
            }

            $totalTests = $existingPhpTests + $existingBrowserTests;
            $hybrid = $hybridByModule[$moduleId] ?? null;
            $analyst = $hybrid['artifacts']['analyst'] ?? [];
            $analysis = $hybrid['artifacts']['comprehension']['analysis'] ?? [];
            $routesObserved = $hybrid ? workbenchObservedClaimedRoutes($testContract, $analyst) : 0;
            $action = (string)($analysis['action'] ?? '');
            $workflowObserved = ($action !== '' && $action !== 'all' && ($analysis['breakpoint'] ?? null) === null) ? 1 : 0;

            $entry = [
                'module' => $moduleId,
                'routes_claimed' => $routesClaimed,
                'routes_tested' => $routesObserved,
                'capabilities_claimed' => $capsClaimed,
                'capabilities_tested' => null,
                'capabilities_measured' => false,
                'workflow_transitions_claimed' => $workflowTransitions,
                'workflow_transitions_tested' => min($workflowTransitions, $workflowObserved),
                'php_test_files_existing' => $existingPhpTests,
                'php_test_files_claimed' => count($phpTestFiles),
                'browser_test_files_existing' => $existingBrowserTests,
                'browser_test_files_claimed' => count($browserTestFiles),
                'page_families' => $pageFamilyCoverage,
                'roles' => $testContract['roles'] ?? [],
                'last_passed' => $lastPassed,
                'last_failed' => $lastFailed,
                'targets' => $testContract['coverage_targets'] ?? [],
                'evidence_mode' => 'observed',
                'latest_run_id' => $hybrid['run_id'] ?? null,
                'analyst_coverage' => $analyst['coverage'] ?? null,
                'ux_score' => $analyst['ux_evolution']['score'] ?? null,
                'ux_gate_passed' => $analyst['ux_evolution']['gate']['passed'] ?? null,
                'comprehension_coverage' => $analysis['coverage_score'] ?? null,
                'note' => 'Counts are matched from run-scoped ARK evidence; unobserved claims remain zero.',
            ];

            $coverage[$moduleId] = $entry;
            $totalRoutesClaimed += $routesClaimed;
            $totalRoutesTested += $entry['routes_tested'];
            $totalCapsClaimed += $capsClaimed;
            $totalCapsTested += (int)($entry['capabilities_tested'] ?? 0);
            $totalWorkflowsClaimed += $workflowTransitions;
            $totalWorkflowTransitionsTested += $entry['workflow_transitions_tested'];
        }

        echo json_encode([
            'ok' => true,
            'coverage' => $coverage,
            'summary' => [
                'modules_with_contracts' => count($coverage),
                'total_routes_claimed' => $totalRoutesClaimed,
                'total_routes_tested' => $totalRoutesTested,
                'total_capabilities_claimed' => $totalCapsClaimed,
                'total_capabilities_tested' => $totalCapsTested,
                'total_workflow_transitions_claimed' => $totalWorkflowsClaimed,
                'total_workflow_transitions_tested' => $totalWorkflowTransitionsTested,
            ],
        ]);
        exit;
    }
}

if (!function_exists('kernelHandleApiSuperadminWorkbenchContracts')) {
    function kernelHandleApiSuperadminWorkbenchContracts(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('X-Request-Id: ' . request_id());
        $user = app()->user();
        if (!$user || ($user['role'] ?? '') !== 'superadmin' || ($user['source'] ?? '') !== 'kernel') {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Superadmin only']);
            exit;
        }

        $contractsDir = dirname(__DIR__, 2) . '/tests/contracts/modules';
        $summaryFile = $contractsDir . '/_summary.json';
        $summary = is_file($summaryFile) ? @json_decode((string)file_get_contents($summaryFile), true) : null;

        echo json_encode([
            'ok' => true,
            'summary' => $summary,
        ]);
        exit;
    }
}

if (!function_exists('kernelHandleApiSuperadminWorkbenchProcessMap')) {
    function kernelHandleApiSuperadminWorkbenchProcessMap(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('X-Request-Id: ' . request_id());
        $user = app()->user();
        if (!$user || ($user['role'] ?? '') !== 'superadmin' || ($user['source'] ?? '') !== 'kernel') {
            http_response_code(403); echo json_encode(['ok' => false, 'error' => 'Superadmin only']); exit;
        }
        $moduleId = trim((string)($_GET['module'] ?? 'project-audit-ledger'));
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $moduleId)) {
            http_response_code(400); echo json_encode(['ok' => false, 'error' => 'Invalid module id']); exit;
        }
        $root = dirname(__DIR__, 2);
        require_once $root . '/kernel/Workbench/Graph/ModuleGraph.php';
        require_once $root . '/kernel/Workbench/Graph/GraphBuilder.php';
        require_once $root . '/kernel/Workbench/Comprehension/Contracts/ModuleComprehensionProvider.php';
        require_once $root . '/kernel/Workbench/Comprehension/Contracts/EntityContract.php';
        require_once $root . '/kernel/Workbench/Comprehension/Contracts/WorkflowContract.php';
        require_once $root . '/kernel/Workbench/Comprehension/Contracts/ActionContract.php';
        require_once $root . '/kernel/Workbench/Comprehension/Contracts/EffectContract.php';
        require_once $root . '/kernel/Workbench/Comprehension/Contracts/SupportContracts.php';
        require_once $root . '/kernel/Workbench/Comprehension/PalComprehensionProvider.php';
        require_once $root . '/kernel/Workbench/Comprehension/ComprehensionProviderRegistry.php';
        $registry = new \Ikabud\Kernel\Workbench\Comprehension\ComprehensionProviderRegistry($root);
        if (!$registry->has($moduleId)) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => "No ARK Comprehension provider for '{$moduleId}'", 'available_modules' => $registry->modules()]);
            exit;
        }
        try {
            $provider = $registry->resolve($moduleId);
        } catch (\Throwable $e) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Provider could not be loaded: ' . $e->getMessage()]);
            exit;
        }
        $builder = new \Ikabud\Kernel\Workbench\Graph\GraphBuilder($provider, $moduleId);
        $graph = $builder->build();
        $planFile = $root . '/test_results/ai/test-plan.json';
        $plan = is_file($planFile) ? json_decode((string)file_get_contents($planFile), true) : null;
        echo json_encode([
            'ok' => true, 'module_id' => $moduleId, 'graph' => $graph->toArray($moduleId),
            'paths' => $builder->computePaths(), 'validation_errors' => $graph->validate(),
            'shadow_plan' => is_array($plan) ? $plan : null, 'available_modules' => $registry->modules(),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
}
