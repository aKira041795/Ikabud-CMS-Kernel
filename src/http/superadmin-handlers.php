<?php

declare(strict_types=1);

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
        $tenantRelevantModules = [];
        $knownEntryModules = [];

        // Find entry_module_id for the selected tenant and collect all known entry modules
        foreach ($tenants as $t) {
            $eModule = trim((string)($t['entry_module_id'] ?? ''));
            if ($eModule !== '') {
                $knownEntryModules[$eModule] = true;
            }
            if ((int)$t['id'] === $selectedTenantId) {
                $selectedEntryModule = $eModule;
            }
        }

        if ($selectedEntryModule !== '') {
            $tenantRelevantModules[$selectedEntryModule] = true;

            // For CMS tenants, add _installed_submodules
            if ($selectedEntryModule === 'cms') {
                $cmsSettings = readTenantModuleSettingsForTenant('cms', $selectedTenantId);
                $subModules = $cmsSettings['_installed_submodules'] ?? [];
                if (is_array($subModules)) {
                    foreach ($subModules as $sub) {
                        $sub = trim((string)$sub);
                        if ($sub !== '') {
                            $tenantRelevantModules[$sub] = true;
                        }
                    }
                }
            }
        }

        // Include modules that have explicit entitlements or are explicitly enabled in settings.
        foreach ($allModules as $_candidateMod) {
            $_candidateModId = (string)($_candidateMod['id'] ?? '');
            if ($_candidateModId === '') {
                continue;
            }
            if (isset($tenantRelevantModules[$_candidateModId])) {
                continue;
            }

            // If it explicitly depends on the tenant's entry module, it is a related add-on and should always be visible to configure.
            $deps = $_candidateMod['depends'] ?? [];
            if (is_array($deps) && $selectedEntryModule !== '' && in_array($selectedEntryModule, $deps, true)) {
                $tenantRelevantModules[$_candidateModId] = true;
                continue;
            }

            // If it has NO dependencies, NO auth_cookie, and is NEVER used as an entry module, it is a global utility (like gui-settings or anti-spam).
            // Per rules, global utilities are visually bundled with 'cms' ONLY, as it is the core environment that uses them.
            if ($selectedEntryModule === 'cms' && empty($deps) && empty($_candidateMod['auth_cookie']) && !isset($knownEntryModules[$_candidateModId])) {
                $tenantRelevantModules[$_candidateModId] = true;
                continue;
            }

            $entitlement = moduleTenantEntitlementStatus($_candidateModId, $selectedTenantId);
            // If it is catalog managed and the tenant is explicitly entitled to it
            if (!empty($entitlement['catalog_managed']) && !empty($entitlement['allowed']) && !empty($entitlement['required'])) {
                $tenantRelevantModules[$_candidateModId] = true;
                continue;
            }

            // Or if it lacks catalog management but has been explicitly enabled in DB, we retain it to allow configuration.
            $_candidateTenantSettings = readTenantModuleSettingsForTenant($_candidateModId, $selectedTenantId);
            if (!empty($_candidateTenantSettings)) {
                $explicitlyEnabled = false;
                if (isset($_candidateTenantSettings['_module_enabled'])) {
                    $explicitlyEnabled = (bool)$_candidateTenantSettings['_module_enabled'];
                } elseif (isset($_candidateTenantSettings['_enabled'])) {
                    $explicitlyEnabled = (bool)$_candidateTenantSettings['_enabled'];
                }
                if ($explicitlyEnabled) {
                    $tenantRelevantModules[$_candidateModId] = true;
                }
            }
        }
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

        // Determine enabled state
        $isEnabled = true;
        if ($multiTenant && $selectedTenantId !== null) {
            $isEnabled = isModuleEnabledForTenant($moduleId, $selectedTenantId);
        } else {
            $isEnabled = !empty($m['_enabled']);
        }

        if ($moduleId === 'anti-spam' && !empty($m['_enabled'])) {
            $isEnabled = true;
        }

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
            'is_enabled' => $isEnabled,
            'has_fields' => $hasFields,
            'catalog_managed' => !empty($entitlement['catalog_managed']),
            'catalog_status' => (string)($entitlement['approval_status'] ?? 'unmanaged'),
            'commercial_mode' => (string)($entitlement['commercial_mode'] ?? 'bundled'),
            'entitlement_required' => !empty($entitlement['required']),
            'entitlement_allowed' => !empty($entitlement['allowed']),
            'entitlement_status' => (string)($entitlement['entitlement_status'] ?? 'not_required'),
            'entitlement_reason' => (string)($entitlement['reason'] ?? ''),
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

    echo app()->render('pages/superadmin-settings.disyl', [
        'page_title' => 'Feature Settings',
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
