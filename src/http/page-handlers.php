<?php

declare(strict_types=1);

if (!function_exists('kernelHandlePageLogin')) {
    function kernelHandlePageLogin(): void
    {
        $loginUser = app()->user();
        if ($loginUser) {
            $loginHome = kernelResolveAuthenticatedHomeRedirect($loginUser, true) ?? '/';
            app()->redirect($loginHome);
            return;
        }

        $loginContext = [
            'page_title' => 'Sign In',
        ];
        $loginTenantId = app()->tenant()->current();
        if ($loginTenantId !== null && function_exists('tenantEntryModuleIdForTenant')) {
            $entryModuleId = tenantEntryModuleIdForTenant((int)$loginTenantId);
            if ($entryModuleId === 'wms' && function_exists('wmsLoginPageContext')) {
                $loginContext = wmsLoginPageContext();
            }
        }

        echo app()->render('pages/login.disyl', $loginContext);
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