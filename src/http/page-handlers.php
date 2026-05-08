<?php

declare(strict_types=1);

if (!function_exists('kernelHandlePageLogin')) {
    function kernelHandlePageLogin(): void
    {
        $loginStartedAt = microtime(true);
        $kernelJwtCookie = (string)config('app.jwt.cookie', 'token');
        $hasAuthHint = isset($_SERVER['HTTP_AUTHORIZATION']) || isset($_COOKIE[$kernelJwtCookie]);
        if (!$hasAuthHint) {
            foreach (array_keys($_COOKIE ?? []) as $cookieName) {
                if (stripos((string)$cookieName, 'token') !== false) {
                    $hasAuthHint = true;
                    break;
                }
            }
        }

        $entryModuleId = 'kernel';
        $loginTenantId = app()->tenant()->current();
        if ($loginTenantId !== null && function_exists('tenantEntryModuleIdForTenant')) {
            $resolvedEntryModuleId = tenantEntryModuleIdForTenant((int)$loginTenantId);
            if (is_string($resolvedEntryModuleId) && $resolvedEntryModuleId !== '') {
                $entryModuleId = $resolvedEntryModuleId;
            }
        }

        if ($hasAuthHint) {
            $loginUser = app()->user();
            if ($loginUser) {
                $loginHome = kernelResolveAuthenticatedHomeRedirect($loginUser, true) ?? '/';
                log_timing('kernel.login.path', $loginStartedAt, [
                    'phase' => 'redirect_authenticated',
                    'tenant_id' => $loginTenantId,
                    'entry_module_id' => $entryModuleId,
                    'cache_hit' => false,
                ]);
                app()->redirect($loginHome);
                return;
            }
        }

        $ctxBuildStart = microtime(true);
        $loginContext = [
            'page_title' => 'Sign In',
        ];
        if ($entryModuleId === 'wms' && function_exists('wmsLoginPageContext')) {
            $loginContext = wmsLoginPageContext();
        } elseif ($entryModuleId === 'bakeshop' && function_exists('bakeshopLoginPageContext')) {
            $loginContext = bakeshopLoginPageContext();
        }
        $ctxBuildMs = round((microtime(true) - $ctxBuildStart) * 1000, 2);

        // Cache key includes entry module (login UI varies by entry point)
        $cacheKey = 'kernel:login:html:' . $entryModuleId;

        if (extension_loaded('apcu') && apcu_enabled()) {
            $cachedHtml = apcu_fetch($cacheKey);
            if (is_string($cachedHtml) && $cachedHtml !== '') {
                log_timing('kernel.login.path', $loginStartedAt, [
                    'phase' => 'cache_hit',
                    'tenant_id' => $loginTenantId,
                    'entry_module_id' => $entryModuleId,
                    'cache_hit' => true,
                    'cache_key' => $cacheKey,
                    'ctx_build_ms' => $ctxBuildMs,
                ]);
                echo $cachedHtml;
                return;
            }
        }

        $renderStart = microtime(true);
        $html = app()->render('pages/login.disyl', $loginContext);
        $renderMs = round((microtime(true) - $renderStart) * 1000, 2);
        if (extension_loaded('apcu') && apcu_enabled()) {
            apcu_store($cacheKey, $html, 60);  // 60-second TTL for higher hit rate under concurrency
        }

        log_timing('kernel.login.path', $loginStartedAt, [
            'phase' => 'render',
            'tenant_id' => $loginTenantId,
            'entry_module_id' => $entryModuleId,
            'cache_hit' => false,
            'cache_key' => $cacheKey,
            'ctx_build_ms' => $ctxBuildMs,
            'render_ms' => $renderMs,
            'html_bytes' => strlen($html),
        ]);

        echo $html;
    }
}

if (!function_exists('kernelHandlePageKernelIntegrations')) {
    function kernelHandlePageKernelIntegrations(): void
    {
        $user = app()->requireAuth();
        if (($user['role'] ?? '') !== 'superadmin' || ($user['source'] ?? '') !== 'kernel') {
            app()->redirect('/');
            return;
        }

        $db = app()->db();
        $integrations = $db->query('SELECT * FROM kernel_integrations ORDER BY created_at DESC')->fetchAll();
        $logs = $db->query('SELECT l.*, i.name as integration_name FROM kernel_integration_logs l LEFT JOIN kernel_integrations i ON i.id = l.integration_id ORDER BY l.created_at DESC LIMIT 100')->fetchAll();
        $eventsRows = $db->query(
            'SELECT module, event_key, description, available_vars FROM kernel_events ORDER BY module ASC, event_key ASC'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($eventsRows as &$eventRow) {
            if (!is_array($eventRow)) {
                continue;
            }
            $eventRow['available_vars'] = !empty($eventRow['available_vars'])
                ? (json_decode((string)$eventRow['available_vars'], true) ?: [])
                : [];
            $eventRow['available_vars_csv'] = !empty($eventRow['available_vars'])
                ? implode(',', array_map(static fn($value): string => (string)$value, (array)$eventRow['available_vars']))
                : '';
        }
        unset($eventRow);

        $capabilityInspect = app()->capabilities()->inspectAll();
        $capabilities = [];
        foreach ($capabilityInspect as $capabilityId => $definition) {
            if (is_string($capabilityId) && $capabilityId !== '') {
                $capabilities[] = [
                    'id' => $capabilityId,
                    'label' => $capabilityId,
                    'description' => is_array($definition) ? (string)($definition['description'] ?? '') : '',
                ];
                continue;
            }
            if (is_array($definition) && !empty($definition['id'])) {
                $capabilities[] = [
                    'id' => (string)$definition['id'],
                    'label' => (string)($definition['label'] ?? $definition['id']),
                    'description' => (string)($definition['description'] ?? ''),
                ];
            }
        }
        usort($capabilities, static fn(array $left, array $right): int => strcmp((string)$left['id'], (string)$right['id']));

        echo app()->render('pages/kernel-integrations.disyl', [
            'title' => 'Kernel Integrations',
            'user' => $user,
            'integrations' => $integrations,
            'logs' => $logs,
            'bridge_events' => $eventsRows,
            'bridge_capabilities' => $capabilities,
            'csrf_token' => app()->csrfToken(),
        ]);
    }
}

if (!function_exists('kernelHandlePageSuperadminPerf')) {
    function kernelHandlePageSuperadminPerf(): void
    {
        $user = app()->requireAuth();
        if (($user['role'] ?? '') !== 'superadmin' || ($user['source'] ?? '') !== 'kernel') {
            app()->redirect('/');
            return;
        }

        $perfData = [];
        $perfOverallStart = microtime(true);

        $t = microtime(true);
        try {
            app()->db()->query('SELECT 1');
            $perfData['db_ping_ms'] = round((microtime(true) - $t) * 1000, 2);
            $perfData['db_ok'] = true;
        } catch (Throwable $e) {
            $perfData['db_ping_ms'] = null;
            $perfData['db_ok'] = false;
        }

        $t = microtime(true);
        $perfDiscoveredModules = discoverModules();
        $perfData['module_discover_ms'] = round((microtime(true) - $t) * 1000, 2);
        $perfData['module_count'] = count($perfDiscoveredModules);

        $t = microtime(true);
        discoverModules(true);
        $perfData['module_discover_cold_ms'] = round((microtime(true) - $t) * 1000, 2);

        $t = microtime(true);
        preloadAllTenantModuleSettings();
        $perfData['settings_preload_ms'] = round((microtime(true) - $t) * 1000, 2);

        $t = microtime(true);
        $perfCacheOk = false;
        try {
            $perfCacheUri = '/__perf_probe_' . request_id() . '__';
            app()->cache()->set('_perf', $perfCacheUri, ['body' => 'ok', 'status' => 200, '_cache_expires_at' => time() + 10], 10);
            $perfCacheResult = app()->cache()->get('_perf', $perfCacheUri);
            $perfCacheOk = is_array($perfCacheResult) && ($perfCacheResult['body'] ?? '') === 'ok';
            app()->cache()->clear('_perf');
        } catch (Throwable $e) {
        }
        $perfData['cache_roundtrip_ms'] = round((microtime(true) - $t) * 1000, 2);
        $perfData['cache_ok'] = $perfCacheOk;

        $cacheStats = [];
        try {
            $cacheStats = app()->cache()->getStats();
        } catch (Throwable $e) {
            $cacheStats = [];
        }

        $cacheHits = (int)($cacheStats['hits'] ?? 0);
        $cacheMisses = (int)($cacheStats['misses'] ?? 0);
        $cacheBypasses = (int)($cacheStats['bypasses'] ?? 0);
        $cacheServed = $cacheHits + $cacheMisses;

        $perfData['cache_hit_rate_pct'] = $cacheServed > 0 ? round(($cacheHits / $cacheServed) * 100, 2) : 0.0;
        $perfData['cache_miss_rate_pct'] = $cacheServed > 0 ? round(($cacheMisses / $cacheServed) * 100, 2) : 0.0;
        $perfData['cache_bypass_rate_pct'] = ($cacheServed + $cacheBypasses) > 0
            ? round(($cacheBypasses / ($cacheServed + $cacheBypasses)) * 100, 2)
            : 0.0;
        $perfData['cache_cached_files'] = (int)($cacheStats['cached_files'] ?? 0);
        $perfData['cache_active_files'] = (int)($cacheStats['active_files'] ?? 0);
        $perfData['cache_expired_files'] = (int)($cacheStats['expired_files'] ?? 0);
        $perfData['cache_total_size_mb'] = (float)($cacheStats['total_size_mb'] ?? 0);
        $perfData['cache_apcu_entries'] = (int)($cacheStats['apcu_entries'] ?? 0);
        $perfData['cache_apcu_available'] = !empty($cacheStats['apcu_available']);

        $t = microtime(true);
        try {
            ob_start();
            app()->render('pages/login.disyl', ['page_title' => '__perf__', 'base_url' => external_base_url()]);
            ob_get_clean();
            $perfData['disyl_render_ms'] = round((microtime(true) - $t) * 1000, 2);
            $perfData['disyl_ok'] = true;
        } catch (Throwable $e) {
            ob_get_clean();
            $perfData['disyl_render_ms'] = null;
            $perfData['disyl_ok'] = false;
        }

        $perfData['total_ms'] = round((microtime(true) - $perfOverallStart) * 1000, 2);
        $perfData['php_version'] = PHP_VERSION;
        $perfData['peak_memory_kb'] = (int) round(memory_get_peak_usage(true) / 1024);
        $perfData['host'] = $_SERVER['HTTP_HOST'] ?? '';
        $perfData['timestamp'] = date('c');

        $perfRows = [
            ['DB ping (SELECT 1)', $perfData['db_ping_ms'], 'ms', $perfData['db_ok'] ? '' : 'FAIL'],
            ['Module discover (cached)', $perfData['module_discover_ms'], 'ms', ''],
            ['Module discover (cold)', $perfData['module_discover_cold_ms'], 'ms', ''],
            ['Settings preload', $perfData['settings_preload_ms'], 'ms', ''],
            ['Cache round-trip', $perfData['cache_roundtrip_ms'], 'ms', $perfData['cache_ok'] ? '' : 'FAIL'],
            ['Cache hit rate', $perfData['cache_hit_rate_pct'], '%', ''],
            ['Cache miss rate', $perfData['cache_miss_rate_pct'], '%', ''],
            ['Cache bypass rate', $perfData['cache_bypass_rate_pct'], '%', ''],
            ['Cache files (active/expired)', $perfData['cache_active_files'] . '/' . $perfData['cache_expired_files'], '', ''],
            ['Cache disk usage', $perfData['cache_total_size_mb'], 'MB', ''],
            ['APCu entries', $perfData['cache_apcu_entries'], $perfData['cache_apcu_available'] ? 'entries' : 'entries (APCu off)', ''],
            ['DiSyL render (login page)', $perfData['disyl_render_ms'], 'ms', $perfData['disyl_ok'] ? '' : 'FAIL'],
            ['Total wall time', $perfData['total_ms'], 'ms', ''],
            ['Peak memory', $perfData['peak_memory_kb'], 'KB', ''],
        ];

        $baseUrl = external_base_url();
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">';
        echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
        echo '<title>Server Performance &mdash; ' . htmlspecialchars((string)$perfData['host']) . '</title>';
        echo '<script src="https://cdn.tailwindcss.com"></script>';
        echo '</head><body class="bg-slate-100 min-h-screen font-sans">';
        echo '<div class="max-w-2xl mx-auto py-10 px-4">';
        echo '<div class="flex items-center justify-between mb-6">';
        echo '<div><h1 class="text-2xl font-bold text-slate-800">Server Performance</h1>';
        echo '<p class="text-sm text-slate-500 mt-1">' . htmlspecialchars((string)$perfData['host']) . ' &mdash; ' . htmlspecialchars((string)$perfData['timestamp']) . ' &mdash; PHP ' . htmlspecialchars((string)$perfData['php_version']) . '</p></div>';
        echo '<a href="' . htmlspecialchars($baseUrl) . '/superadmin/settings" class="text-sm text-sky-600 hover:underline">&larr; Back</a>';
        echo '</div>';
        echo '<div class="bg-white rounded-xl shadow overflow-hidden">';
        echo '<table class="w-full text-sm">';
        echo '<thead><tr class="bg-slate-50 text-slate-500 text-xs uppercase tracking-wide">';
        echo '<th class="px-5 py-3 text-left font-semibold">Probe</th><th class="px-5 py-3 text-right font-semibold">Result</th><th class="px-5 py-3 text-left font-semibold">Status</th>';
        echo '</tr></thead><tbody>';
        foreach ($perfRows as $index => [$label, $value, $unit, $flag]) {
            $bg = $index % 2 === 0 ? '' : 'bg-slate-50';
            $flagHtml = $flag === 'FAIL'
                ? '<span class="inline-block px-2 py-0.5 rounded text-xs font-semibold bg-red-100 text-red-700">FAIL</span>'
                : '<span class="inline-block px-2 py-0.5 rounded text-xs font-semibold bg-green-100 text-green-700">OK</span>';
            $valueStr = $value === null
                ? '<span class="text-red-500">error</span>'
                : '<span class="font-mono font-semibold">' . htmlspecialchars((string)$value) . '</span> <span class="text-slate-400">' . $unit . '</span>';
            echo '<tr class="' . $bg . ' border-t border-slate-100">';
            echo '<td class="px-5 py-3 text-slate-700">' . htmlspecialchars((string)$label) . '</td>';
            echo '<td class="px-5 py-3 text-right">' . $valueStr . '</td>';
            echo '<td class="px-5 py-3">' . $flagHtml . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
        echo '<p class="text-xs text-slate-400 mt-4 text-center">Reload the page to run another probe.</p>';
        echo '</div></body></html>';
    }
}
if (!function_exists('kernelHandlePageHome')) {
function kernelHandlePageHome(): void
{
    $user = app()->user();
    if (!$user) {
        app()->redirect('/login');
    }
    $homeRole = trim((string)($user['role'] ?? ''));
    $homeSource = trim((string)($user['source'] ?? 'kernel'));
    $homeUrl = kernelResolveAuthenticatedHomeRedirect($user, false);
    if ($homeUrl) {
        app()->redirect($homeUrl);
    } else {
        // No module landing page available — show kernel home with module status
        $enabledModules = array_values(getEnabledModules());
        $enabledNames = array_values(array_filter(array_map(function ($m) {
            $name = (string)($m['name'] ?? $m['id'] ?? '');
            return $name !== '' ? $name : null;
        }, $enabledModules)));

        $enabledCount = count($enabledNames);

        $accessibleNames = $enabledNames;
        if ($homeRole === 'admin' && $homeSource === 'kernel') {
            $accessibleNames = [];
            foreach ($enabledModules as $m) {
                $settings = is_array($m['_settings'] ?? null) ? $m['_settings'] : [];
                if (!empty($settings['allow_kernel_admin'])) {
                    $accessibleNames[] = (string)($m['name'] ?? $m['id'] ?? '');
                }
            }
        }

        echo app()->render('pages/home.disyl', [
            'page_title' => 'Home',
            'enabled_modules_count' => $enabledCount,
            'enabled_modules_names' => $enabledNames,
            'accessible_modules_count' => count($accessibleNames),
            'accessible_modules_names' => $accessibleNames,
        ]);
    }
    exit;
}
}

if (!function_exists('kernelHandlePageAdminProfile')) {
function kernelHandlePageAdminProfile(): void
{
    $user = app()->requireAuth();
    if (!in_array($user['role'] ?? '', ['admin', 'superadmin'], true)) {
        app()->redirect('/');
        exit;
    }
    echo app()->render('pages/admin-profile.disyl', [
        'page_title' => 'Profile',
    ]);
    exit;
}
}

if (!function_exists('kernelHandlePageAdminUsers')) {
function kernelHandlePageAdminUsers(): void
{
    $user = app()->requireAuth();
    if (($user['role'] ?? '') !== 'admin') {
        app()->redirect('/');
        exit;
    }

    $q = trim((string)($_GET['q'] ?? ''));
    $where = ["role IN ('admin','superadmin','manager','viewer')"]; 
    $bind = [];
    if ($q !== '') {
        $where[] = '(username LIKE :q OR full_name LIKE :q)';
        $bind[':q'] = '%' . $q . '%';
    }

    $stmt = app()->db()->prepare(
        'SELECT id, username, full_name, role, is_active, created_at
         FROM users
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY created_at DESC'
    );
    $stmt->execute($bind);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    echo app()->render('pages/admin-users.disyl', [
        'page_title' => 'Users',
        'users' => $users,
        'search' => $q,
    ]);
}
}

if (!function_exists('kernelHandlePageAdminPlatform')) {
function kernelHandlePageAdminPlatform(): void
{
    $user = app()->requireAuth();
    if (($user['role'] ?? '') !== 'admin') {
        app()->redirect('/');
        exit;
    }
    echo app()->render('pages/admin-platform.disyl', [
        'page_title' => 'Platform Dashboard',
    ]);
    exit;
}
}

if (!function_exists('kernelHandlePageAdminModules')) {
function kernelHandlePageAdminModules(): void
{
    $user = app()->requireAuth();
    if (($user['role'] ?? '') !== 'admin') {
        app()->redirect('/');
        exit;
    }

    $allModules = discoverModules();
    $moduleList = [];
    foreach ($allModules as $m) {
        $modSettings = getModuleSettings((string)($m['id'] ?? ''));
        $capCheck = validateModuleCapabilities($m);
        $capError = empty($capCheck['ok']) ? ($capCheck['error'] ?? 'Invalid capability manifest') : null;
        $capDepends = (!empty($capCheck['ok']) && is_array($capCheck['depends'] ?? null)) ? $capCheck['depends'] : [];
        $capExposes = (!empty($capCheck['ok']) && is_array($capCheck['exposes'] ?? null)) ? $capCheck['exposes'] : [];
        $capMissing = [];
        $routeCount = 0;
        $settingsUrl = '';

        $moduleId = (string)($m['id'] ?? '');
        $rf = ($m['_path'] ?? '') . '/routes.php';
        if ($moduleId !== '' && is_file($rf)) {
            $mr = require $rf;
            if (is_array($mr)) {
                foreach ($mr as $method => $routes_arr) {
                    if (!is_array($routes_arr)) {
                        continue;
                    }
                    $routeCount += count($routes_arr);

                    if ($settingsUrl === '' && strtoupper((string)$method) === 'GET') {
                        foreach ($routes_arr as $path => $handler) {
                            if (!is_string($path)) {
                                continue;
                            }
                            if (preg_match('#^/' . preg_quote($moduleId, '#') . '/admin/settings$#', $path)) {
                                $settingsUrl = $path;
                                break;
                            }
                        }
                    }
                }
            }
        }

        if ($capError === null) {
            foreach ($capDepends as $capId) {
                if (!app()->capabilities()->has((string)$capId)) {
                    $capMissing[] = (string)$capId;
                }
            }
        }

        $editableSettingsFields = moduleEditableSettingsFields($m);
        $settingsContextNotice = null;

        // Compute entity authority UI indicators
        $entitiesOwned = [];
        if (!empty($m['entities']) && is_array($m['entities'])) {
            foreach ($m['entities'] as $eType => $eDef) {
                if (!empty($eDef['authority']) && $eDef['authority'] === true) {
                    $entitiesOwned[] = $eType;
                }
            }
        }
        if (empty($editableSettingsFields) && !empty($m['settings_fields']) && moduleTenantSettingsModeEnabled()) {
            $settingsContextNotice = 'Feature settings are managed by the Superadmin on the tenant domain.';
        }

        $moduleList[] = [
            'id' => $m['id'],
            'name' => $m['name'] ?? $m['id'],
            'version' => $m['version'] ?? '0.0.0',
            'description' => $m['description'] ?? '',
            'author' => $m['author'] ?? '',
            'enabled' => !empty($m['_enabled']),
            'allow_kernel_admin' => (bool)($modSettings['allow_kernel_admin'] ?? false),
            'nav_count' => count($m['nav'] ?? []),
            'route_count' => $routeCount,
            'settings_url' => $settingsUrl,
            'settings_fields' => $editableSettingsFields,
            'settings' => is_array($modSettings) ? $modSettings : [],
            'settings_context_notice' => $settingsContextNotice,
            'capability_exposes_count' => is_array($capExposes) ? count($capExposes) : 0,
            'capability_depends_count' => is_array($capDepends) ? count($capDepends) : 0,
            'capability_missing_depends' => $capMissing,
            'capability_manifest_error' => $capError,
            'capability_ready_to_enable' => ($capError === null && empty($capMissing)),
            'entities_owned' => $entitiesOwned,
            'entities_owned_count' => count($entitiesOwned),
        ];
    }
    echo app()->render('pages/admin-modules.disyl', [
        'page_title' => 'Module Manager',
        'modules' => $moduleList,
    ]);
    exit;
}
}

if (!function_exists('kernelHandlePageAdminTenants')) {
function kernelHandlePageAdminTenants(): void
{
    $user = app()->requireAuth();
    if (($user['role'] ?? '') !== 'admin') {
        app()->redirect('/');
        exit;
    }
    $entryModuleOptions = listTenantEntryModuleOptions();
    echo app()->render('pages/admin-tenants.disyl', [
        'page_title' => 'Tenants',
        'entry_module_options_json' => json_encode($entryModuleOptions, JSON_UNESCAPED_SLASHES),
    ]);
    exit;
}
}

if (!function_exists('kernelHandlePageAdminKernelTriggers')) {
function kernelHandlePageAdminKernelTriggers(): void
{
    $user = app()->requireAuth();
    $role = (string)($user['role'] ?? '');
    $source = (string)($user['source'] ?? '');
    if ($role !== 'admin' && !($role === 'superadmin' && $source === 'kernel')) {
        app()->redirect('/');
        exit;
    }
    echo app()->render('pages/admin-kernel-triggers.disyl', [
        'page_title' => 'Kernel Triggers',
    ]);
    exit;
}
}

if (!function_exists('kernelHandlePageAdminAi')) {
function kernelHandlePageAdminAi(): void
{
    $user = app()->requireAuth();
    if (($user['role'] ?? '') !== 'admin') {
        app()->redirect('/');
        exit;
    }
    echo app()->render('pages/admin-ai.disyl', [
        'page_title' => 'AI',
    ]);
    exit;
}
}

if (!function_exists('kernelHandleApiAdminCheckUpdates')) {
function kernelHandleApiAdminCheckUpdates(): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('X-Request-Id: ' . request_id());
    $user = app()->user();
    if (!$user || !in_array($user['role'] ?? '', ['admin', 'superadmin'], true) || ($user['source'] ?? 'kernel') !== 'kernel') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Kernel admin only']);
        exit;
    }

    app()->csrfEnforce();
    $result = kernelUpdatesSyncCatalog($user);
    if (empty($result['ok'])) {
        http_response_code(422);
        echo json_encode($result, JSON_UNESCAPED_SLASHES);
        exit;
    }

    adminViewCacheInvalidate(['admin:view:platform']);
    $result['updates'] = kernelUpdatesBuildSummary();
    echo json_encode($result, JSON_UNESCAPED_SLASHES);
    exit;
}
}

if (!function_exists('kernelHandleApiHealth')) {
function kernelHandleApiHealth(): void
{
    $healthStartedAt = microtime(true);
    $tenantId = null;
    $entryModuleId = null;
    try {
        $tenantId = app()->tenant()->current();
        if ($tenantId !== null && function_exists('tenantEntryModuleIdForTenant')) {
            $entryModuleId = tenantEntryModuleIdForTenant((int)$tenantId);
        }
    } catch (Throwable $ignored) {
    }

    if (extension_loaded('apcu') && function_exists('apcu_enabled') && apcu_enabled()) {
        $cacheKey = 'kernel:api_health:payload';
        $cached = apcu_fetch($cacheKey, $hit);
        if ($hit && is_array($cached)) {
            log_timing('kernel.api.health.path', $healthStartedAt, [
                'phase' => 'cache_hit',
                'tenant_id' => $tenantId,
                'entry_module_id' => $entryModuleId,
                'cache_hit' => true,
                'cache_key' => $cacheKey,
            ]);
            app()->json($cached);
            return;
        }
    }

    $identityStartedAt = microtime(true);
    $identity = app()->platformIdentity();
    $identityMs = round((microtime(true) - $identityStartedAt) * 1000, 2);
    $skippedModules = array_values(getSkippedModules());
    $payload = [
        'ok' => true,
        'app' => $identity['app']['name'] ?? config('app.name', 'Ikabud'),
        'kernel_version' => $identity['kernel']['version'] ?? '0.0.0',
        'kernel_codename' => $identity['kernel']['codename'] ?? '',
        'modules' => [
            'skipped_count' => count($skippedModules),
        ],
        'time' => gmdate('c'),
    ];

    if (extension_loaded('apcu') && function_exists('apcu_enabled') && apcu_enabled()) {
        // Very short TTL: enough to collapse bursts, short enough to stay fresh.
        apcu_store('kernel:api_health:payload', $payload, 2);
    }

    log_timing('kernel.api.health.path', $healthStartedAt, [
        'phase' => 'render',
        'tenant_id' => $tenantId,
        'entry_module_id' => $entryModuleId,
        'cache_hit' => false,
        'identity_ms' => $identityMs,
    ]);

    app()->json($payload);
}
}

if (!function_exists('kernelHandlePageSuperadminCache')) {
    function kernelHandlePageSuperadminCache(): void
    {
        $user = app()->requireAuth();
        if (($user['role'] ?? '') !== 'superadmin' || ($user['source'] ?? '') !== 'kernel') {
            app()->redirect('/');
            return;
        }

        $snap = function_exists('kernelBuildCacheObservabilitySnapshot')
            ? kernelBuildCacheObservabilitySnapshot()
            : ['ok' => false, 'global' => [], 'instances' => [], 'fragments' => []];

        $baseUrl = external_base_url();
        $host = $_SERVER['HTTP_HOST'] ?? '';
        $jsSnap = htmlspecialchars(json_encode($snap, JSON_UNESCAPED_SLASHES) ?: '{}', ENT_QUOTES);

        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');

        $g = is_array($snap['global'] ?? null) ? $snap['global'] : [];
        $instances = is_array($snap['instances'] ?? null) ? $snap['instances'] : [];
        $frag = is_array($snap['fragments'] ?? null) ? $snap['fragments'] : [];

        echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">';
        echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
        echo '<title>Cache Observability &mdash; ' . htmlspecialchars((string)$host) . '</title>';
        echo '<script src="https://cdn.tailwindcss.com"></script>';
        echo '</head><body class="bg-slate-100 min-h-screen font-sans">';
        echo '<div class="max-w-5xl mx-auto py-10 px-4">';

        echo '<div class="flex items-center justify-between mb-6">';
        echo '<div><h1 class="text-2xl font-bold text-slate-800">Cache Observability</h1>';
        echo '<p class="text-sm text-slate-500 mt-1">' . htmlspecialchars((string)$host)
            . ' &mdash; ' . htmlspecialchars((string)($snap['timestamp'] ?? '')) . '</p></div>';
        echo '<a href="' . htmlspecialchars($baseUrl) . '/superadmin/settings" class="text-sm text-sky-600 hover:underline">&larr; Back</a>';
        echo '</div>';

        // Global summary tiles
        echo '<div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">';
        $tiles = [
            ['Hit rate',       (string)($g['hit_rate'] ?? '0%'), 'sky'],
            ['Hits',           number_format((int)($g['hits'] ?? 0)), 'emerald'],
            ['Misses',         number_format((int)($g['misses'] ?? 0)), 'amber'],
            ['Bypasses',       number_format((int)($g['bypasses'] ?? 0)), 'slate'],
            ['Active files',   number_format((int)($g['active_files'] ?? 0)), 'sky'],
            ['Expired files',  number_format((int)($g['expired_files'] ?? 0)), 'amber'],
            ['Disk used',      number_format((float)($g['total_size_mb'] ?? 0), 2) . ' / ' . (int)($g['max_size_mb'] ?? 0) . ' MB', 'slate'],
            ['APCu',           ((bool)($g['apcu_available'] ?? false) ? (number_format((int)($g['apcu_entries'] ?? 0)) . ' entries') : 'off'), 'slate'],
        ];
        foreach ($tiles as [$label, $value, $color]) {
            echo '<div class="bg-white rounded-xl shadow p-4">';
            echo '<div class="text-xs text-slate-500 uppercase tracking-wide">' . htmlspecialchars($label) . '</div>';
            echo '<div class="mt-1 text-lg font-semibold text-' . $color . '-700">' . htmlspecialchars($value) . '</div>';
            echo '</div>';
        }
        echo '</div>';

        // Per-instance table
        echo '<div class="bg-white rounded-xl shadow overflow-hidden mb-6">';
        echo '<div class="px-4 py-3 border-b border-slate-100 flex items-center justify-between">';
        echo '<h2 class="text-sm font-semibold text-slate-700">Cache Instances</h2>';
        echo '<button onclick="superadminCacheFlushAll()" class="text-xs px-3 py-1.5 rounded bg-rose-600 text-white hover:bg-rose-700">Flush All</button>';
        echo '</div>';
        echo '<table class="w-full text-sm">';
        echo '<thead class="bg-slate-50 text-slate-600"><tr>';
        echo '<th class="text-left px-4 py-2">Instance</th>';
        echo '<th class="text-right px-4 py-2">Files</th>';
        echo '<th class="text-right px-4 py-2">Size (MB)</th>';
        echo '<th class="text-right px-4 py-2">Tags</th>';
        echo '<th class="text-right px-4 py-2">Action</th>';
        echo '</tr></thead><tbody>';
        if (empty($instances)) {
            echo '<tr><td colspan="5" class="px-4 py-6 text-center text-slate-400">No cache instances yet.</td></tr>';
        } else {
            foreach ($instances as $row) {
                $id = (string)($row['id'] ?? '');
                echo '<tr class="border-t border-slate-100">';
                echo '<td class="px-4 py-2 font-mono text-slate-800">' . htmlspecialchars($id) . '</td>';
                echo '<td class="px-4 py-2 text-right">' . number_format((int)($row['files'] ?? 0)) . '</td>';
                echo '<td class="px-4 py-2 text-right">' . number_format((float)($row['size_mb'] ?? 0), 2) . '</td>';
                echo '<td class="px-4 py-2 text-right">' . number_format((int)($row['tag_count'] ?? 0)) . '</td>';
                echo '<td class="px-4 py-2 text-right">';
                echo '<button onclick="superadminCacheFlushInstance(' . htmlspecialchars(json_encode($id) ?: '""') . ')" class="text-xs px-2 py-1 rounded bg-slate-700 text-white hover:bg-slate-900">Flush</button>';
                echo '</td></tr>';
            }
        }
        echo '</tbody></table></div>';

        // Fragment store panel
        echo '<div class="bg-white rounded-xl shadow overflow-hidden">';
        echo '<div class="px-4 py-3 border-b border-slate-100 flex items-center justify-between">';
        echo '<h2 class="text-sm font-semibold text-slate-700">DiSyL 4.3 Fragment Cache</h2>';
        echo '<button onclick="superadminCacheFlushFragments()" class="text-xs px-3 py-1.5 rounded bg-rose-600 text-white hover:bg-rose-700">Flush Fragments (current tenant)</button>';
        echo '</div>';
        echo '<div class="grid grid-cols-3 gap-3 p-4 text-sm">';
        echo '<div><div class="text-xs text-slate-500 uppercase">Status</div><div class="mt-1 font-semibold ' . (!empty($frag['enabled']) ? 'text-emerald-700' : 'text-slate-500') . '">' . (!empty($frag['enabled']) ? 'enabled' : 'disabled') . '</div></div>';
        echo '<div><div class="text-xs text-slate-500 uppercase">Tenants with fragments</div><div class="mt-1 font-semibold">' . (int)($frag['tenants'] ?? 0) . '</div></div>';
        echo '<div><div class="text-xs text-slate-500 uppercase">Files / Size</div><div class="mt-1 font-semibold">' . number_format((int)($frag['files'] ?? 0)) . ' / ' . number_format((float)($frag['size_mb'] ?? 0), 2) . ' MB</div></div>';
        echo '</div></div>';

        // Snapshot data + JS
        echo '<script>';
        echo 'window.SUPERADMIN_CACHE_SNAPSHOT = ' . json_encode($snap, JSON_UNESCAPED_SLASHES) . ';';
        echo <<<'JS'
async function superadminCacheFlush(payload, confirmMsg) {
    if (confirmMsg && !confirm(confirmMsg)) return;
    try {
        const res = await fetch('/api/v1/superadmin/cache/flush', {
            method: 'POST', credentials: 'same-origin',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(payload),
        });
        const data = await res.json();
        if (!res.ok || !data.ok) { alert('Flush failed: ' + (data.error || res.status)); return; }
        location.reload();
    } catch (e) { alert('Flush error: ' + e.message); }
}
function superadminCacheFlushAll() { superadminCacheFlush({target: 'all'}, 'Flush ALL cache instances?'); }
function superadminCacheFlushInstance(id) { superadminCacheFlush({target: 'instance', instance_id: id}, 'Flush instance "' + id + '"?'); }
function superadminCacheFlushFragments() { superadminCacheFlush({target: 'fragments'}, 'Flush DiSyL fragment cache for current tenant?'); }
JS;
        echo '</script>';
        echo '</div></body></html>';
    }
}
