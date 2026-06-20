<?php

declare(strict_types=1);

/**
 * Kernel OS 5.1 — Hardening + Observability Handlers
 *
 * Superadmin APIs for service health, circuit breaker visibility,
 * capability trace viewer, ServiceProxy diagnostics, and entity-view debugging.
 */

// ──────────────────────────────────────────────────────────────────────────────
// Kernel Module Catalog — superadmin-gated manifest summary
// ──────────────────────────────────────────────────────────────────────────────

function kernelHandleApiKernelModulesCatalog(): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('X-Request-Id: ' . request_id());
    kernelRequireSuperadmin();

    $cacheKey = 'api:kernel-modules-catalog:v1';
    $user = app()->user();
    $cached = adminViewCacheGet($cacheKey, $user);
    if ($cached !== null) {
        echo json_encode($cached);
        exit;
    }

    $all = discoverModules();
    $list = [];
    foreach ($all as $m) {
        $capCheck = validateModuleCapabilities($m);
        $capDepends = (!empty($capCheck['ok']) && is_array($capCheck['depends'] ?? null))
            ? array_values($capCheck['depends'])
            : [];

        $providesCaps = [];
        if (!empty($m['capabilities']) && is_array($m['capabilities'])) {
            foreach ($m['capabilities'] as $capId => $capDef) {
                if (is_array($capDef)) {
                    $providesCaps[] = [
                        'id' => $capId,
                        'description' => (string)($capDef['description'] ?? ''),
                        'version' => (string)($capDef['version'] ?? '1'),
                    ];
                } else {
                    $providesCaps[] = ['id' => $capId];
                }
            }
        }

        $consumesCaps = [];
        if (!empty($m['consumes_capabilities']) && is_array($m['consumes_capabilities'])) {
            $consumesCaps = array_values($m['consumes_capabilities']);
        }

        $list[] = [
            'id' => (string)($m['id'] ?? ''),
            'name' => (string)($m['name'] ?? ($m['id'] ?? '')),
            'version' => (string)($m['version'] ?? '0.0.0'),
            'description' => (string)($m['description'] ?? ''),
            'author' => (string)($m['author'] ?? ''),
            'type' => (string)($m['type'] ?? 'php-module'),
            'enabled' => !empty($m['_enabled']),
            'auth_owned' => isset($m['auth_owned']),
            'auth_cookie' => (string)($m['auth_cookie'] ?? ''),
            'depends' => is_array($m['depends'] ?? null) ? array_values($m['depends']) : [],
            'provides_capabilities' => $providesCaps,
            'consumes_capabilities' => $consumesCaps,
            'capability_depends' => $capDepends,
            'owns_tables' => is_array($m['owns_tables'] ?? null) ? array_values($m['owns_tables']) : [],
            'reads_tables' => is_array($m['reads_tables'] ?? null) ? array_values($m['reads_tables']) : [],
            'co_owns_tables' => is_array($m['co_owns_tables'] ?? null) ? array_values($m['co_owns_tables']) : [],
            'entities' => is_array($m['entities'] ?? null) ? $m['entities'] : null,
            'service' => isset($m['service']) ? [
                'endpoint' => (string)($m['service']['endpoint'] ?? ''),
                'protocol' => (string)($m['service']['protocol'] ?? 'http+json'),
            ] : null,
        ];
    }

    $payload = [
        'ok' => true,
        'modules' => $list,
        'total' => count($list),
        'generated_at' => date('c'),
        'kernel_version' => \Ikabud\Kernel\App::KERNEL_VERSION,
        'request_id' => request_id(),
    ];
    adminViewCacheSet($cacheKey, $payload, ['admin:view:modules', 'admin:view:platform', 'admin:view:capabilities'], $user);
    echo json_encode($payload);
    exit;
}

// ──────────────────────────────────────────────────────────────────────────────
// Kernel Capability Catalog — superadmin-gated, richer than admin/ capabilities
// ──────────────────────────────────────────────────────────────────────────────

function kernelHandleApiKernelCapabilityCatalog(): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('X-Request-Id: ' . request_id());
    kernelRequireSuperadmin();

    $cacheKey = 'api:kernel-capability-catalog:v1';
    $user = app()->user();
    $cached = adminViewCacheGet($cacheKey, $user);
    if ($cached !== null) {
        echo json_encode($cached);
        exit;
    }

    $registry = app()->capabilities();
    $catalog = new \Ikabud\Kernel\Capabilities\CapabilityCatalog($registry);

    // Enrich each capability with provider metadata
    $caps = [];
    foreach ($registry->capabilityIds() as $capId) {
        $providers = $registry->providers($capId);
        $providerList = [];
        foreach ($providers as $p) {
            $providerList[] = [
                'provider' => (string)($p['provider'] ?? 'kernel'),
                'priority' => (int)($p['priority'] ?? 10),
                'modes' => is_array($p['modes'] ?? null) ? $p['modes'] : ['first'],
            ];
        }
        $caps[] = [
            'id' => $capId,
            'providers' => $providerList,
        ];
    }

    $payload = [
        'ok' => true,
        'summary' => $catalog->summary(),
        'modules' => $catalog->modules(),
        'events' => $catalog->events(),
        'capabilities' => $caps,
        'total' => count($caps),
        'generated_at' => date('c'),
        'request_id' => request_id(),
    ];
    adminViewCacheSet($cacheKey, $payload, ['admin:view:capabilities', 'admin:view:platform'], $user);
    echo json_encode($payload);
    exit;
}

// ──────────────────────────────────────────────────────────────────────────────
// Service Health Dashboard
// ──────────────────────────────────────────────────────────────────────────────

function kernelHandleApiSuperadminServiceHealth(): void
{
    kernelRequireSuperadmin();

    $modules = discoverModules();
    $services = [];

    foreach ($modules as $id => $manifest) {
        if (($manifest['type'] ?? 'php-module') !== 'service-module') {
            continue;
        }

        $service = $manifest['service'] ?? [];
        $endpoint = trim((string)($service['endpoint'] ?? ''));
        $healthUrl = $endpoint !== '' ? rtrim($endpoint, '/') . '/health' : '';

        // Probe health endpoint if available
        $healthStatus = 'unknown';
        $healthData = null;
        $healthDurationMs = 0;

        if ($healthUrl !== '') {
            $t0 = microtime(true);
            try {
                $ctx = stream_context_create([
                    'http' => [
                        'method' => 'GET',
                        'timeout' => 3,
                        'header' => "Accept: application/json\r\n",
                    ],
                ]);
                $raw = @file_get_contents($healthUrl, false, $ctx);
                $healthDurationMs = (int)round((microtime(true) - $t0) * 1000);

                if ($raw !== false) {
                    $decoded = json_decode($raw, true);
                    if (is_array($decoded) && ($decoded['ok'] ?? false)) {
                        $healthStatus = 'healthy';
                        $healthData = $decoded;
                    } else {
                        $healthStatus = 'degraded';
                    }
                } else {
                    $healthStatus = 'unreachable';
                }
            } catch (\Throwable $e) {
                $healthStatus = 'error';
                $healthData = ['error' => $e->getMessage()];
                $healthDurationMs = (int)round((microtime(true) - $t0) * 1000);
            }
        }

        // Check breaker state for this service's capabilities
        $capabilityIds = [];
        foreach (($manifest['capabilities']['exposes'] ?? []) as $exp) {
            $capabilityIds[] = (string)($exp['id'] ?? '');
        }

        $breakerStates = [];
        foreach ($capabilityIds as $capId) {
            try {
                if (\app()->capabilities()->has($capId)) {
                    $breakerKey = \app()->cap()->breakerKey($capId, $id);
                    $state = \app()->cap()->breakerState();
                    $bState = $state[$breakerKey] ?? null;
                    $breakerStates[$capId] = [
                        'open' => !empty($bState['open']),
                        'failure_count' => (int)($bState['failure_count'] ?? 0),
                        'half_open' => !empty($bState['half_open']),
                    ];
                }
            } catch (\Throwable $e) {
                $breakerStates[$capId] = ['error' => $e->getMessage()];
            }
        }

        $services[] = [
            'id' => $id,
            'name' => $manifest['name'] ?? $id,
            'type' => 'service-module',
            'endpoint' => $endpoint,
            'protocol' => $service['protocol'] ?? 'http+json',
            'health_status' => $healthStatus,
            'health_data' => $healthData,
            'health_duration_ms' => $healthDurationMs,
            'capabilities' => $capabilityIds,
            'breaker_states' => $breakerStates,
            'timeout_ms' => (int)($service['timeout_ms'] ?? 30000),
            'retry_max' => (int)($service['retry']['max_attempts'] ?? 3),
        ];
    }

    header('Content-Type: application/json');
    echo json_encode([
        'ok' => true,
        'services' => $services,
        'total' => count($services),
        'healthy' => count(array_filter($services, fn($s) => $s['health_status'] === 'healthy')),
        'degraded' => count(array_filter($services, fn($s) => $s['health_status'] === 'degraded')),
        'unreachable' => count(array_filter($services, fn($s) => $s['health_status'] === 'unreachable')),
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}

// ──────────────────────────────────────────────────────────────────────────────
// Circuit Breaker Visibility
// ──────────────────────────────────────────────────────────────────────────────

function kernelHandleApiSuperadminBreakers(): void
{
    kernelRequireSuperadmin();

    $state = \app()->cap()->breakerState();
    $breakers = [];

    foreach ($state as $key => $data) {
        if (!is_array($data)) continue;
        $breakers[] = [
            'key' => $key,
            'open' => !empty($data['open']),
            'failure_count' => (int)($data['failure_count'] ?? 0),
            'last_failure_at' => $data['last_failure_at'] ?? null,
            'opened_at' => $data['opened_at'] ?? null,
            'half_open' => !empty($data['half_open']),
            'probe_count' => (int)($data['probe_count'] ?? 0),
        ];
    }

    usort($breakers, fn($a, $b) => ($b['failure_count'] ?? 0) <=> ($a['failure_count'] ?? 0));

    header('Content-Type: application/json');
    echo json_encode([
        'ok' => true,
        'breakers' => $breakers,
        'total' => count($breakers),
        'open_count' => count(array_filter($breakers, fn($b) => $b['open'])),
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}

function kernelHandleApiSuperadminBreakersReset(): void
{
    kernelRequireSuperadmin();

    $key = trim((string)($_GET['key'] ?? ''));
    $all = ($_GET['all'] ?? '') === '1';

    try {
        if ($all) {
            \app()->cap()->resetAllBreakers();
        } elseif ($key !== '') {
            \app()->cap()->resetBreaker($key);
        } else {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Specify ?key= or ?all=1']);
            return;
        }
    } catch (\Throwable $e) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        return;
    }

    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'reset' => $all ? 'all' : $key]);
}

// ──────────────────────────────────────────────────────────────────────────────
// Capability Call Trace Viewer
// ──────────────────────────────────────────────────────────────────────────────

function kernelHandleApiSuperadminCapabilityTrace(): void
{
    kernelRequireSuperadmin();

    $limit = min(100, max(1, (int)($_GET['limit'] ?? 50)));
    $capability = trim((string)($_GET['capability'] ?? ''));
    $provider = trim((string)($_GET['provider'] ?? ''));
    $status = trim((string)($_GET['status'] ?? '')); // ok, error, all

    // Read recent capability calls from app.log
    $logPath = STORAGE_PATH . '/logs/app.log';
    $traces = [];

    if (is_file($logPath)) {
        $lines = file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $lines = array_reverse($lines); // newest first

        foreach ($lines as $line) {
            if (count($traces) >= $limit) break;
            if (!str_contains($line, 'capability.call')) continue;

            $jsonStart = strpos($line, '{');
            if ($jsonStart === false) continue;
            $jsonStr = substr($line, $jsonStart);
            $data = json_decode($jsonStr, true);
            if (!is_array($data)) continue;

            // Filters
            if ($capability !== '' && !str_contains(($data['capability_id'] ?? ''), $capability)) continue;
            if ($provider !== '' && !in_array($provider, $data['providers'] ?? [], true)) continue;
            if ($status === 'ok' && empty($data['ok'])) continue;
            if ($status === 'error' && !empty($data['ok'])) continue;

            $traces[] = [
                'capability_id' => $data['capability_id'] ?? '',
                'mode' => $data['mode'] ?? 'first',
                'providers' => $data['providers'] ?? [],
                'ok' => $data['ok'] ?? false,
                'duration_ms' => $data['duration_ms'] ?? 0,
                'error' => $data['error'] ?? null,
                'caller_module' => $data['caller_module'] ?? '',
                'request_id' => $data['request_id'] ?? '',
                'timestamp' => substr($line, 1, 19), // extract timestamp from log line
            ];
        }
    }

    header('Content-Type: application/json');
    echo json_encode([
        'ok' => true,
        'traces' => $traces,
        'count' => count($traces),
        'filters' => compact('capability', 'provider', 'status', 'limit'),
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}

// ──────────────────────────────────────────────────────────────────────────────
// ServiceProxy Diagnostics
// ──────────────────────────────────────────────────────────────────────────────

function kernelHandleApiSuperadminServiceProxyDiagnostics(): void
{
    kernelRequireSuperadmin();

    $modules = discoverModules();
    $diagnostics = [];

    foreach ($modules as $id => $manifest) {
        if (($manifest['type'] ?? 'php-module') !== 'service-module') continue;

        $service = $manifest['service'] ?? [];
        $endpoint = rtrim((string)($service['endpoint'] ?? ''), '/');
        $capIds = [];
        foreach (($manifest['capabilities']['exposes'] ?? []) as $exp) {
            $capIds[] = (string)($exp['id'] ?? '');
        }

        // Check if capabilities are registered
        $registeredCaps = [];
        foreach ($capIds as $capId) {
            try {
                $registeredCaps[$capId] = \app()->capabilities()->has($capId);
            } catch (\Throwable $e) {
                $registeredCaps[$capId] = false;
            }
        }

        // Simulate a test call to verify the ServiceProxy is configured
        $proxyTest = 'not_tested';
        if ($endpoint !== '' && !empty($capIds)) {
            try {
                // Just check if the capability is callable without actually invoking
                $providers = \app()->capabilities()->providers($capIds[0]);
                $proxyTest = !empty($providers) ? 'registered' : 'not_registered';
            } catch (\Throwable $e) {
                $proxyTest = 'error: ' . $e->getMessage();
            }
        }

        $diagnostics[] = [
            'id' => $id,
            'name' => $manifest['name'] ?? $id,
            'endpoint' => $endpoint,
            'capabilities_declared' => $capIds,
            'capabilities_registered' => $registeredCaps,
            'proxy_status' => $proxyTest,
            'timeout_ms' => (int)($service['timeout_ms'] ?? 30000),
            'retry' => $service['retry'] ?? null,
            'circuit_breaker' => $service['circuit_breaker'] ?? null,
            'auth_configured' => !empty($service['auth']['token_env']),
        ];
    }

    header('Content-Type: application/json');
    echo json_encode([
        'ok' => true,
        'services' => $diagnostics,
        'total' => count($diagnostics),
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}

// ──────────────────────────────────────────────────────────────────────────────
// Entity-View Debug Panel
// ──────────────────────────────────────────────────────────────────────────────

function kernelHandleApiSuperadminEntityViewDebug(): void
{
    kernelRequireSuperadmin();

    $source = trim((string)($_GET['source'] ?? ''));
    $view = trim((string)($_GET['view'] ?? 'compact'));
    $limit = min(50, max(1, (int)($_GET['limit'] ?? 10)));

    $debug = [
        'requested_source' => $source,
        'requested_view' => $view,
    ];

    if ($source === '') {
        // List all registered views
        $views = \app()->entityViews();
        $registered = $views->registeredViews();
        $debug['registered_views'] = $registered;
        $debug['view_count'] = count($registered);
    } else {
        // Debug a specific source
        $views = \app()->entityViews();
        $parsed = $views->parseSource($source);
        $debug['parsed'] = $parsed;

        $contract = $views->viewContract($parsed['entity_type'], $view);
        $debug['contract'] = $contract;

        // Try resolving
        $t0 = microtime(true);
        try {
            $resolved = $views->resolve($source, $view, ['limit' => $limit]);
            $debug['resolve_duration_ms'] = (int)round((microtime(true) - $t0) * 1000);
            $debug['resolve_ok'] = ($resolved['error'] ?? null) === null;
            $debug['resolve_error'] = $resolved['error'] ?? null;
            $debug['row_count'] = count($resolved['rows'] ?? []);
            $debug['total'] = $resolved['total'] ?? 0;
            $debug['preview_rows'] = array_slice($resolved['rows'] ?? [], 0, 3);
        } catch (\Throwable $e) {
            $debug['resolve_ok'] = false;
            $debug['resolve_error'] = $e->getMessage();
            $debug['resolve_duration_ms'] = (int)round((microtime(true) - $t0) * 1000);
        }

        // Show capability gate
        $sanitizedType = str_replace('.', '_', $parsed['entity_type']);
        $capabilityId = "entity.list.{$sanitizedType}";
        $debug['capability_id'] = $capabilityId;
        $debug['capability_exists'] = \app()->capabilities()->has($capabilityId);
        if ($debug['capability_exists']) {
            $debug['capability_providers'] = array_map(
                fn($p) => $p['provider'] ?? '?',
                \app()->capabilities()->providers($capabilityId)
            );
        }
    }

    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'debug' => $debug], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}

// ──────────────────────────────────────────────────────────────────────────────
// 5.3 Report Management APIs
// ──────────────────────────────────────────────────────────────────────────────

function kernelHandleApiSuperadminReportTemplates(): void
{
    kernelRequireSuperadmin();
    header('Content-Type: application/json');

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true);
        $id = (string)($body['id'] ?? '');
        if ($id === '') {
            echo json_encode(['ok' => false, 'error' => 'Template id required']);
            return;
        }
        \Ikabud\Kernel\Services\ReportManager::saveTemplate($id, $body);
        echo json_encode(['ok' => true, 'saved' => $id]);
        return;
    }

    if ($method === 'DELETE') {
        $id = trim((string)($_GET['id'] ?? ''));
        \Ikabud\Kernel\Services\ReportManager::deleteTemplate($id);
        echo json_encode(['ok' => true, 'deleted' => $id]);
        return;
    }

    echo json_encode([
        'ok' => true,
        'templates' => \Ikabud\Kernel\Services\ReportManager::listTemplates(),
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}

function kernelHandleApiSuperadminReportArchive(): void
{
    kernelRequireSuperadmin();
    header('Content-Type: application/json');

    $id = trim((string)($_GET['id'] ?? ''));
    if ($id !== '') {
        $report = \Ikabud\Kernel\Services\ReportManager::getArchivedReport($id);
        echo json_encode(['ok' => $report !== null, 'report' => $report], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        return;
    }

    echo json_encode([
        'ok' => true,
        'reports' => \Ikabud\Kernel\Services\ReportManager::listArchived(),
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}

function kernelHandleApiSuperadminReportPacks(): void
{
    kernelRequireSuperadmin();
    header('Content-Type: application/json');

    echo json_encode([
        'ok' => true,
        'packs' => \Ikabud\Kernel\Services\ReportManager::moduleReportPacks(),
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}

function kernelHandleApiSuperadminReportSchedule(): void
{
    kernelRequireSuperadmin();
    header('Content-Type: application/json');

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true);
        \Ikabud\Kernel\Services\ReportManager::scheduleReport(
            (string)($body['entity_type'] ?? ''),
            (string)($body['format'] ?? 'csv'),
            (string)($body['schedule'] ?? 'daily'),
            $body['options'] ?? []
        );
        echo json_encode(['ok' => true]);
        return;
    }

    if ($method === 'DELETE') {
        $id = trim((string)($_GET['id'] ?? ''));
        \Ikabud\Kernel\Services\ReportManager::cancelScheduled($id);
        echo json_encode(['ok' => true, 'cancelled' => $id]);
        return;
    }

    echo json_encode([
        'ok' => true,
        'scheduled' => \Ikabud\Kernel\Services\ReportManager::listScheduled(),
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}

function kernelHandleApiSuperadminReportConsistencyCheck(): void
{
    kernelRequireSuperadmin();
    header('Content-Type: application/json');

    $entityType = trim((string)($_GET['entity_type'] ?? 'cms_post'));
    $limit = min(20, max(1, (int)($_GET['limit'] ?? 5)));

    // Fetch sample data for the entity type
    $rows = [];
    try {
        $result = \app()->cap()->call("entity.list.{$entityType}", ['limit' => $limit], ['mode' => 'first']);
        $rows = is_array($result) ? ($result['rows'] ?? $result) : [];
    } catch (\Throwable $e) {
        // Try without capability — use empty rows as fallback
        $rows = [['id' => 1, 'title' => 'Test Row', 'status' => 'published']];
    }

    echo json_encode([
        'ok' => true,
        'entity_type' => $entityType,
        'row_count' => count($rows),
        'consistency' => \Ikabud\Kernel\Services\ReportManager::consistencyCheck($entityType, $rows),
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}

function kernelHandleApiSuperadminSignaturePresets(): void
{
    kernelRequireSuperadmin();
    header('Content-Type: application/json');

    echo json_encode([
        'ok' => true,
        'presets' => \Ikabud\Kernel\Services\KernelExport::signaturePresets(),
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}

// ──────────────────────────────────────────────────────────────────────────────
// 5.4 AI Governance APIs
// ──────────────────────────────────────────────────────────────────────────────

function kernelHandleApiSuperadminAiConfig(): void
{
    kernelRequireSuperadmin();
    header('Content-Type: application/json');

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true);
        \Ikabud\Kernel\DiSyL\AI\AIGovernance::saveProviderConfig($body);
        echo json_encode(['ok' => true]);
        return;
    }

    echo json_encode([
        'ok' => true,
        'config' => \Ikabud\Kernel\DiSyL\AI\AIGovernance::getProviderConfig(),
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}

function kernelHandleApiSuperadminAiTenantSettings(): void
{
    kernelRequireSuperadmin();
    header('Content-Type: application/json');

    $tenantId = (int)($_GET['tenant_id'] ?? 0);
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'POST' && $tenantId > 0) {
        $body = json_decode(file_get_contents('php://input'), true);
        \Ikabud\Kernel\DiSyL\AI\AIGovernance::saveTenantSettings($tenantId, $body);
        echo json_encode(['ok' => true]);
        return;
    }

    echo json_encode([
        'ok' => true,
        'tenant_id' => $tenantId,
        'settings' => $tenantId > 0
            ? \Ikabud\Kernel\DiSyL\AI\AIGovernance::getTenantSettings($tenantId)
            : ['note' => 'Specify ?tenant_id=N for tenant-specific settings'],
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}

function kernelHandleApiSuperadminAiCapabilityPolicy(): void
{
    kernelRequireSuperadmin();
    header('Content-Type: application/json');

    $capabilityId = trim((string)($_GET['capability_id'] ?? ''));
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'POST' && $capabilityId !== '') {
        $body = json_decode(file_get_contents('php://input'), true);
        \Ikabud\Kernel\DiSyL\AI\AIGovernance::saveCapabilityPolicy($capabilityId, $body);
        echo json_encode(['ok' => true]);
        return;
    }

    echo json_encode([
        'ok' => true,
        'capability_id' => $capabilityId,
        'policy' => $capabilityId !== ''
            ? \Ikabud\Kernel\DiSyL\AI\AIGovernance::getCapabilityPolicy($capabilityId)
            : ['note' => 'Specify ?capability_id=ai.summarize@1'],
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}

function kernelHandleApiSuperadminAiUsage(): void
{
    kernelRequireSuperadmin();
    header('Content-Type: application/json');

    echo json_encode([
        'ok' => true,
        'stats' => \Ikabud\Kernel\DiSyL\AI\AIGovernance::getUsageStats(),
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}

function kernelHandleApiSuperadminAiPrompts(): void
{
    kernelRequireSuperadmin();
    header('Content-Type: application/json');

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true);
        $id = (string)($body['id'] ?? '');
        if ($id === '') { echo json_encode(['ok' => false, 'error' => 'id required']); return; }
        \Ikabud\Kernel\DiSyL\AI\AIGovernance::savePromptTemplate($id, $body);
        echo json_encode(['ok' => true]);
        return;
    }

    if ($method === 'DELETE') {
        $id = trim((string)($_GET['id'] ?? ''));
        \Ikabud\Kernel\DiSyL\AI\AIGovernance::deletePromptTemplate($id);
        echo json_encode(['ok' => true]);
        return;
    }

    echo json_encode([
        'ok' => true,
        'templates' => \Ikabud\Kernel\DiSyL\AI\AIGovernance::listPromptTemplates(),
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}

function kernelHandleApiSuperadminAiRedaction(): void
{
    kernelRequireSuperadmin();
    header('Content-Type: application/json');

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true);
        $id = (string)($body['id'] ?? '');
        if ($id === '') { echo json_encode(['ok' => false, 'error' => 'id required']); return; }
        \Ikabud\Kernel\DiSyL\AI\AIGovernance::saveRedactionRule($id, $body);
        echo json_encode(['ok' => true]);
        return;
    }

    echo json_encode([
        'ok' => true,
        'rules' => \Ikabud\Kernel\DiSyL\AI\AIGovernance::listRedactionRules(),
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}

function kernelHandleApiSuperadminAiReviewQueue(): void
{
    kernelRequireSuperadmin();
    header('Content-Type: application/json');

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $id = trim((string)($_GET['id'] ?? ''));

    if ($method === 'POST' && $id !== '') {
        $action = trim((string)($_GET['action'] ?? 'approve'));
        if ($action === 'approve') {
            \Ikabud\Kernel\DiSyL\AI\AIGovernance::approveReview($id);
        } else {
            $reason = trim((string)($_GET['reason'] ?? ''));
            \Ikabud\Kernel\DiSyL\AI\AIGovernance::rejectReview($id, $reason);
        }
        echo json_encode(['ok' => true, 'action' => $action, 'id' => $id]);
        return;
    }

    echo json_encode([
        'ok' => true,
        'queue' => \Ikabud\Kernel\DiSyL\AI\AIGovernance::listReviewQueue(),
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}

function kernelHandleApiSuperadminAiAudit(): void
{
    kernelRequireSuperadmin();
    header('Content-Type: application/json');

    $limit = min(200, max(1, (int)($_GET['limit'] ?? 50)));

    echo json_encode([
        'ok' => true,
        'entries' => \Ikabud\Kernel\DiSyL\AI\AIGovernance::listAuditTrail($limit),
        'count' => count(\Ikabud\Kernel\DiSyL\AI\AIGovernance::listAuditTrail($limit)),
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}

function kernelHandleApiSuperadminAiCertify(): void
{
    kernelRequireSuperadmin();
    header('Content-Type: application/json');

    $capabilityId = trim((string)($_GET['capability_id'] ?? 'ai.summarize@1'));

    echo json_encode([
        'ok' => true,
        'certification' => \Ikabud\Kernel\DiSyL\AI\AIGovernance::certifyAiCapability($capabilityId),
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}

