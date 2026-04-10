<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/security.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../src/helpers/email.php';
require_once __DIR__ . '/../src/helpers/updates.php';
require_once __DIR__ . '/../src/http/request-bootstrap.php';
require_once __DIR__ . '/../src/http/capability-cache.php';
require_once __DIR__ . '/../src/http/admin-view-cache.php';
require_once __DIR__ . '/../src/http/tenant-entry-modules.php';
require_once __DIR__ . '/../src/http/core-routes.php';
require_once __DIR__ . '/../src/http/admin-handlers.php';


if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => is_https(),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

register_shutdown_function('kernelFireShutdownHooks');

if (should_enforce_https() && !is_https()) {
    $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($host !== '') {
        $target = 'https://' . $host . ($_SERVER['REQUEST_URI'] ?? '/');
        kernel_emit_redirect_header($target, 301);
        exit;
    }
}

(new \Ikabud\Kernel\Http\SecurityHeaders())->apply();
header('X-Request-Id: ' . request_id());

// ── CORS for API consumers (Android app, external clients) ──────────
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$uri_check = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if (str_starts_with($uri_check, '/api/')) {
    $allowedRaw = trim((string)($_ENV['CORS_ORIGINS'] ?? ''));
    $allowedOrigins = array_values(array_filter(array_map('trim', explode(',', $allowedRaw))));
    // Only apply CORS when an explicit Origin is present and is allowlisted.
    // Never emit '*' while also allowing credentials.
    if ($origin !== '' && in_array($origin, $allowedOrigins, true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, Accept, X-Requested-With');
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Max-Age: 86400');
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
// HEAD should behave like GET for routing purposes
if ($method === 'HEAD') {
    $method = 'GET';
}
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$uri = rawurldecode($uri);
$uri = rtrim($uri, '/');
$uri = $uri === '' ? '/' : $uri;

// ── Module static assets ─────────────────────────────────────────
// Convention: /assets/modules/<moduleId>/<path> maps to modules/<moduleId>/assets/<path>
if ($method === 'GET' && preg_match('#^/assets/modules/([a-zA-Z0-9\-]+)/(.+)$#', $uri, $m)) {
    $modId = (string)$m[1];
    $rel = (string)$m[2];

    // Basic traversal hardening
    $rel = ltrim($rel, '/');
    if ($rel === '' || str_contains($rel, '..') || str_contains($rel, '\\')) {
        http_response_code(404);
        exit;
    }

    $assetPath = BASE_PATH . '/modules/' . $modId . '/assets/' . $rel;
    $real = realpath($assetPath);
    $root = realpath(BASE_PATH . '/modules/' . $modId . '/assets');

    if ($real === false || $root === false || !str_starts_with($real, $root) || !is_file($real)) {
        http_response_code(404);
        exit;
    }

    $ext = strtolower(pathinfo($real, PATHINFO_EXTENSION));
    $types = [
        'css' => 'text/css; charset=utf-8',
        'js' => 'application/javascript; charset=utf-8',
        'mjs' => 'application/javascript; charset=utf-8',
        'json' => 'application/json; charset=utf-8',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'svg' => 'image/svg+xml',
        'webp' => 'image/webp',
        'ico' => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
        'otf' => 'font/otf',
    ];
    $ctype = $types[$ext] ?? 'application/octet-stream';

    $mtime = (int) @filemtime($real);
    $etag = 'W/"' . sha1($real . '|' . $mtime . '|' . (string) @filesize($real)) . '"';
    header('ETag: ' . $etag);
    header('Cache-Control: public, max-age=3600');
    if (!empty($_SERVER['HTTP_IF_NONE_MATCH']) && trim((string)$_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
        http_response_code(304);
        exit;
    }

    header('Content-Type: ' . $ctype);
    header('Content-Length: ' . (string) filesize($real));
    readfile($real);
    exit;
}

// ── Public static assets fallback ────────────────────────────────
// Shared-hosting installs may rewrite root-domain requests into index.php from
// a parent directory, which prevents Apache from serving files that physically
// live under public/assets/. Serve them here when the request still arrives at
// the front controller.
if ($method === 'GET' && preg_match('#^/assets/(.+)$#', $uri, $m)) {
    $rel = ltrim((string)$m[1], '/');
    if ($rel === '' || str_contains($rel, '..') || str_contains($rel, '\\')) {
        http_response_code(404);
        exit;
    }

    $assetPath = BASE_PATH . '/public/assets/' . $rel;
    $real = realpath($assetPath);
    $root = realpath(BASE_PATH . '/public/assets');

    if ($real === false || $root === false || !str_starts_with($real, $root) || !is_file($real)) {
        http_response_code(404);
        exit;
    }

    $ext = strtolower(pathinfo($real, PATHINFO_EXTENSION));
    $types = [
        'css' => 'text/css; charset=utf-8',
        'js' => 'application/javascript; charset=utf-8',
        'mjs' => 'application/javascript; charset=utf-8',
        'json' => 'application/json; charset=utf-8',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'svg' => 'image/svg+xml',
        'webp' => 'image/webp',
        'ico' => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
        'otf' => 'font/otf',
    ];
    $ctype = $types[$ext] ?? 'application/octet-stream';

    $mtime = (int) @filemtime($real);
    $etag = 'W/"' . sha1($real . '|' . $mtime . '|' . (string) @filesize($real)) . '"';
    header('ETag: ' . $etag);
    header('Cache-Control: public, max-age=3600');
    if (!empty($_SERVER['HTTP_IF_NONE_MATCH']) && trim((string)$_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
        http_response_code(304);
        exit;
    }

    header('Content-Type: ' . $ctype);
    header('Content-Length: ' . (string) filesize($real));
    readfile($real);
    exit;
}

$basePath = kernel_request_base_path();
if ($basePath !== '' && ($uri === $basePath || str_starts_with($uri, $basePath . '/'))) {
    $uri = substr($uri, strlen($basePath));
    $uri = $uri === '' ? '/' : $uri;
}

// ── Multi-tenant entry module routing (kernel router helper) ─────
try {
    $router = new \Ikabud\Kernel\Http\TenantEntryRouter();
    $uri = $router->rewriteUri($uri);
} catch (Throwable $ignored) {
}

// ── Canonical domain enforcement ──────────────────────────────────
// When a tenant designates a canonical_domain, any request arriving on
// a different domain (e.g. cms.ikabudkernel.com vs ikabudkernel.com)
// is 301-redirected before session data, links, or cookies are emitted.
if (!empty($_SERVER['IK_CANONICAL_DOMAIN']) && PHP_SAPI !== 'cli') {
    $canonicalHost = strtolower(trim((string)$_SERVER['IK_CANONICAL_DOMAIN']));
    if ($canonicalHost !== '') {
        $target = request_scheme() . '://' . $canonicalHost . ($_SERVER['REQUEST_URI'] ?? '/');
        kernel_emit_redirect_header($target, 301);
        exit;
    }
}

// If the resolved tenant is suspended, show maintenance mode.
// This is intentionally done after the router has resolved tenant_id from host.
if (!empty($_SERVER['IK_TENANT_SUSPENDED'])) {
    http_response_code(503);
    echo app()->render('pages/maintenance.disyl', ['page_title' => 'Maintenance']);
    exit;
}

if (!empty($_SERVER['IK_ENTRY_MODULE_UNAVAILABLE'])) {
    http_response_code(503);
    echo app()->render('pages/entry-module-unavailable.disyl', [
        'page_title' => 'Tenant Unavailable',
        'entry_module_id' => (string)($_SERVER['IK_ENTRY_MODULE_ID'] ?? ''),
    ]);
    exit;
}

if (!empty($_SERVER['IK_FAST_404'])) {
    http_response_code(404);
    echo app()->render('pages/404.disyl', ['page_title' => 'Not Found']);
    exit;
}

$routes = kernelCoreRoutes();

// Batch-load all tenant module settings in 1 query (avoids N+1 per module)
try {
    syncTenantMigrationsForCurrentRequest();
} catch (Throwable $e) {
    write_log('Tenant migration sync failed during request bootstrap: ' . $e->getMessage(), 'error', [
        'host' => (string)($_SERVER['HTTP_HOST'] ?? ''),
        'uri' => $uri,
    ]);
}

preloadAllTenantModuleSettings();

$routes = loadModuleRoutes($routes);
kernelRegisterCoreRequestDispatchHooks();

$dispatchContext = kernelApplyRequestBeforeDispatch(kernelBuildRequestDispatchContext($method, $uri));
$method = strtoupper((string)($dispatchContext['method'] ?? $method));
if ($method === 'HEAD') {
    $method = 'GET';
}

$uriCandidate = trim((string)($dispatchContext['uri'] ?? $uri));
$uriPath = parse_url($uriCandidate, PHP_URL_PATH);
$uri = rawurldecode(($uriPath === false || $uriPath === null || $uriPath === '') ? $uriCandidate : $uriPath);
$uri = rtrim($uri, '/');
$uri = $uri === '' ? '/' : $uri;

$dispatchRedirect = $dispatchContext['redirect'] ?? null;
if (is_string($dispatchRedirect) && $dispatchRedirect !== '') {
    app()->redirect($dispatchRedirect, (int)($dispatchContext['redirect_status'] ?? 302));
}

if (!empty($dispatchContext['handled'])) {
    exit;
}

$routePatterns = array_keys($routes[$method] ?? []);
usort($routePatterns, 'compareRoutePatternsForMatching');

$handler = null;
$params = [];
foreach ($routePatterns as $pattern) {
    $candidate = $routes[$method][$pattern];
    $regex = preg_replace('/\{(\w+)\}/', '(?P<$1>[^/]+)', $pattern);
    $regex = '#^' . $regex . '$#';

    if (preg_match($regex, $uri, $matches)) {
        $handler = $candidate;
        foreach ($matches as $key => $value) {
            if (is_string($key)) {
                $params[$key] = $value;
            }
        }
        break;
    }
}

if ($handler === null) {
    http_response_code(404);
    echo app()->render('pages/404.disyl', ['page_title' => 'Not Found']);
    exit;
}

if (str_contains($handler, ':')) {
    executeModuleHandler($handler, $params);
    exit;
}

switch ($handler) {
    case 'pageHome':
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

    case 'apiTenantCreate':
        kernelHandleApiTenantCreate();
        exit;

    case 'apiTenantEntryModuleSet':
        kernelHandleApiTenantEntryModuleSet();
        exit;

    case 'apiTenantDomainAdd':
        kernelHandleApiTenantDomainAdd();
        exit;

    case 'apiTenantDomainRemove':
        kernelHandleApiTenantDomainRemove();
        exit;

    case 'apiTenantCanonicalDomainSet':
        kernelHandleApiTenantCanonicalDomainSet();
        exit;

    case 'apiTenantDbUpsert':
        kernelHandleApiTenantDbUpsert();
        exit;

    case 'apiTenantStatusSet':
        kernelHandleApiTenantStatusSet();
        exit;

    case 'apiTenantAdminEmailPush':
        kernelHandleApiTenantAdminEmailPush();
        exit;

    case 'apiTenantDelete':
        kernelHandleApiTenantDelete();
        exit;

    case 'apiAiSettingsGet':
        header('Content-Type: application/json; charset=utf-8');
        header('X-Request-Id: ' . request_id());
        $user = app()->user();
        if (!$user || ($user['role'] ?? '') !== 'admin') {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Admin only']);
            exit;
        }

        $s = getModuleSettings('ai');
        if (!is_array($s)) $s = [];

        $apiKey = trim((string)($s['openai_api_key'] ?? ''));
        $masked = $apiKey !== '' ? ('***' . substr($apiKey, -4)) : '';

        $groqKey = trim((string)($s['groq_api_key'] ?? ''));
        $groqMasked = $groqKey !== '' ? ('***' . substr($groqKey, -4)) : '';

        $searchKey = trim((string)($s['search_grounding_api_key'] ?? ''));
        $searchKeyMasked = $searchKey !== '' ? ('***' . substr($searchKey, -4)) : '';

        echo json_encode([
            'ok' => true,
            'settings' => [
                'provider' => (string)($s['provider'] ?? 'openai'),
                'tier' => (string)($s['tier'] ?? 'free'),
                'openai_model_free' => (string)($s['openai_model_free'] ?? 'gpt-4o-mini'),
                'openai_model_paid' => (string)($s['openai_model_paid'] ?? 'gpt-4o'),
                'openai_model' => (string)($s['openai_model'] ?? ''),
                'ollama_base_url' => (string)($s['ollama_base_url'] ?? 'http://localhost:11434'),
                'ollama_model_free' => (string)($s['ollama_model_free'] ?? 'llama3.2:3b'),
                'ollama_model_paid' => (string)($s['ollama_model_paid'] ?? 'llama3.1:8b'),
                'ollama_model' => (string)($s['ollama_model'] ?? ''),
                'groq_model_free' => (string)($s['groq_model_free'] ?? 'llama-3.1-8b-instant'),
                'groq_model_paid' => (string)($s['groq_model_paid'] ?? 'llama-3.3-70b-versatile'),
                'groq_model' => (string)($s['groq_model'] ?? ''),
                'openai_api_key_masked' => $masked,
                'openai_api_key_set' => $apiKey !== '',
                'groq_api_key_masked' => $groqMasked,
                'groq_api_key_set' => $groqKey !== '',
                'search_grounding_provider' => (string)($s['search_grounding_provider'] ?? ''),
                'search_grounding_key_masked' => $searchKeyMasked,
                'search_grounding_key_set' => $searchKey !== '',
                'search_grounding_max_results' => max(1, min(10, (int)($s['search_grounding_max_results'] ?? 5))),
            ],
        ]);
        exit;

    case 'apiAiSettingsSave':
        header('Content-Type: application/json; charset=utf-8');
        header('X-Request-Id: ' . request_id());
        $user = app()->user();
        if (!$user || ($user['role'] ?? '') !== 'admin') {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Admin only']);
            exit;
        }

        app()->csrfEnforce();

        $input = app()->input();
        $settingsIn = $input['settings'] ?? null;
        if (!is_array($settingsIn)) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'settings is required']);
            exit;
        }

        $old = getModuleSettings('ai');
        if (!is_array($old)) $old = [];
        $new = $old;

        if (array_key_exists('provider', $settingsIn)) {
            $p = trim((string)$settingsIn['provider']);
            if (in_array($p, ['openai', 'ollama', 'groq'], true)) {
                $new['provider'] = $p;
            }
        }
        if (array_key_exists('tier', $settingsIn)) {
            $tier = trim((string)$settingsIn['tier']);
            if (in_array($tier, ['free', 'paid', 'custom'], true)) {
                $new['tier'] = $tier;
            }
        }

        foreach (['openai_model_free','openai_model_paid','openai_model','ollama_base_url','ollama_model_free','ollama_model_paid','ollama_model','groq_model_free','groq_model_paid','groq_model'] as $k) {
            if (array_key_exists($k, $settingsIn)) {
                $new[$k] = trim((string)$settingsIn[$k]);
            }
        }

        // API key handling: accept openai_api_key only if non-empty; otherwise keep old.
        if (array_key_exists('openai_api_key', $settingsIn)) {
            $k = trim((string)$settingsIn['openai_api_key']);
            if ($k !== '') {
                $new['openai_api_key'] = $k;
            }
        }

        // Groq API key handling (same: non-empty updates)
        if (array_key_exists('groq_api_key', $settingsIn)) {
            $k = trim((string)$settingsIn['groq_api_key']);
            if ($k !== '') {
                $new['groq_api_key'] = $k;
            }
        }

        // Search grounding provider and settings
        if (array_key_exists('search_grounding_provider', $settingsIn)) {
            $sp = trim((string)$settingsIn['search_grounding_provider']);
            if (in_array($sp, ['', 'brave', 'tavily', 'serper'], true)) {
                $new['search_grounding_provider'] = $sp;
            }
        }
        if (array_key_exists('search_grounding_api_key', $settingsIn)) {
            $sk = trim((string)$settingsIn['search_grounding_api_key']);
            if ($sk !== '') {
                $new['search_grounding_api_key'] = $sk;
            }
        }
        if (array_key_exists('search_grounding_max_results', $settingsIn)) {
            $new['search_grounding_max_results'] = max(1, min(10, (int)$settingsIn['search_grounding_max_results']));
        }

        saveModuleSettings('ai', $new);
        adminViewCacheInvalidate(['admin:view:modules', 'admin:view:platform']);

        echo json_encode(['ok' => true]);
        exit;

    case 'pageLogin':
        $loginUser = app()->user();
        if ($loginUser) {
            $loginHome = kernelResolveAuthenticatedHomeRedirect($loginUser, true) ?? '/';
            app()->redirect($loginHome);
            exit;
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
        break;

    case 'pageSuperadminPerf':
        $user = app()->requireAuth();
        if (($user['role'] ?? '') !== 'superadmin' || ($user['source'] ?? '') !== 'kernel') {
            app()->redirect('/');
            exit;
        }

        $perfData = [];
        $perfOverallStart = microtime(true);

        $t = microtime(true);
        try { app()->db()->query('SELECT 1'); $perfData['db_ping_ms'] = round((microtime(true) - $t) * 1000, 2); $perfData['db_ok'] = true; }
        catch (Throwable $e) { $perfData['db_ping_ms'] = null; $perfData['db_ok'] = false; }

        $t = microtime(true); $perfDiscMods = discoverModules();
        $perfData['module_discover_ms'] = round((microtime(true) - $t) * 1000, 2);
        $perfData['module_count'] = count($perfDiscMods);

        $t = microtime(true); discoverModules(true);
        $perfData['module_discover_cold_ms'] = round((microtime(true) - $t) * 1000, 2);

        $t = microtime(true); preloadAllTenantModuleSettings();
        $perfData['settings_preload_ms'] = round((microtime(true) - $t) * 1000, 2);

        $t = microtime(true); $perfCacheOk = false;
        try {
            $perfCacheUri = '/__perf_probe_' . request_id() . '__';
            app()->cache()->set('_perf', $perfCacheUri, ['body' => 'ok', 'status' => 200, '_cache_expires_at' => time() + 10], 10);
            $perfCacheResult = app()->cache()->get('_perf', $perfCacheUri);
            $perfCacheOk = is_array($perfCacheResult) && ($perfCacheResult['body'] ?? '') === 'ok';
            app()->cache()->clear('_perf');
        } catch (Throwable $e) {}
        $perfData['cache_roundtrip_ms'] = round((microtime(true) - $t) * 1000, 2);
        $perfData['cache_ok'] = $perfCacheOk;

        $t = microtime(true);
        try {
            ob_start();
            app()->render('pages/login.disyl', ['page_title' => '__perf__', 'base_url' => external_base_url()]);
            ob_get_clean();
            $perfData['disyl_render_ms'] = round((microtime(true) - $t) * 1000, 2);
            $perfData['disyl_ok'] = true;
        } catch (Throwable $e) { ob_get_clean(); $perfData['disyl_render_ms'] = null; $perfData['disyl_ok'] = false; }

        $perfData['total_ms'] = round((microtime(true) - $perfOverallStart) * 1000, 2);
        $perfData['php_version'] = PHP_VERSION;
        $perfData['peak_memory_kb'] = (int) round(memory_get_peak_usage(true) / 1024);
        $perfData['host'] = $_SERVER['HTTP_HOST'] ?? '';
        $perfData['timestamp'] = date('c');

        $perfRows = [
            ['DB ping (SELECT 1)',         $perfData['db_ping_ms'],              'ms', $perfData['db_ok'] ? '' : 'FAIL'],
            ['Module discover (cached)',   $perfData['module_discover_ms'],      'ms', ''],
            ['Module discover (cold)',     $perfData['module_discover_cold_ms'], 'ms', ''],
            ['Settings preload',           $perfData['settings_preload_ms'],     'ms', ''],
            ['Cache round-trip',          $perfData['cache_roundtrip_ms'],      'ms', $perfData['cache_ok'] ? '' : 'FAIL'],
            ['DiSyL render (login page)', $perfData['disyl_render_ms'],         'ms', $perfData['disyl_ok'] ? '' : 'FAIL'],
            ['Total wall time',           $perfData['total_ms'],                'ms', ''],
            ['Peak memory',               $perfData['peak_memory_kb'],          'KB', ''],
        ];

        $baseUrl = external_base_url();
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">';
        echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
        echo '<title>Server Performance &mdash; ' . htmlspecialchars($perfData['host']) . '</title>';
        echo '<script src="https://cdn.tailwindcss.com"></script>';
        echo '</head><body class="bg-slate-100 min-h-screen font-sans">';
        echo '<div class="max-w-2xl mx-auto py-10 px-4">';
        echo '<div class="flex items-center justify-between mb-6">';
        echo '<div><h1 class="text-2xl font-bold text-slate-800">Server Performance</h1>';
        echo '<p class="text-sm text-slate-500 mt-1">' . htmlspecialchars($perfData['host']) . ' &mdash; ' . htmlspecialchars($perfData['timestamp']) . ' &mdash; PHP ' . htmlspecialchars($perfData['php_version']) . '</p></div>';
        echo '<a href="' . htmlspecialchars($baseUrl) . '/superadmin/settings" class="text-sm text-sky-600 hover:underline">&larr; Back</a>';
        echo '</div>';
        echo '<div class="bg-white rounded-xl shadow overflow-hidden">';
        echo '<table class="w-full text-sm">';
        echo '<thead><tr class="bg-slate-50 text-slate-500 text-xs uppercase tracking-wide">';
        echo '<th class="px-5 py-3 text-left font-semibold">Probe</th><th class="px-5 py-3 text-right font-semibold">Result</th><th class="px-5 py-3 text-left font-semibold">Status</th>';
        echo '</tr></thead><tbody>';
        foreach ($perfRows as $i => [$label, $value, $unit, $flag]) {
            $bg = $i % 2 === 0 ? '' : 'bg-slate-50';
            $flagHtml = $flag === 'FAIL'
                ? '<span class="inline-block px-2 py-0.5 rounded text-xs font-semibold bg-red-100 text-red-700">FAIL</span>'
                : '<span class="inline-block px-2 py-0.5 rounded text-xs font-semibold bg-green-100 text-green-700">OK</span>';
            $valueStr = $value === null ? '<span class="text-red-500">error</span>' : '<span class="font-mono font-semibold">' . htmlspecialchars((string)$value) . '</span> <span class="text-slate-400">' . $unit . '</span>';
            echo '<tr class="' . $bg . ' border-t border-slate-100">';
            echo '<td class="px-5 py-3 text-slate-700">' . htmlspecialchars($label) . '</td>';
            echo '<td class="px-5 py-3 text-right">' . $valueStr . '</td>';
            echo '<td class="px-5 py-3">' . $flagHtml . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
        echo '<p class="text-xs text-slate-400 mt-4 text-center">Reload the page to run another probe.</p>';
        echo '</div></body></html>';
        exit;

    case 'pageKernelIntegrations':
        $user = app()->requireAuth();
        if (($user['role'] ?? '') !== 'superadmin' || ($user['source'] ?? '') !== 'kernel') {
            app()->redirect('/');
            exit;
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
        exit;
    
    case 'apiKernelIntegrations':
        header('Content-Type: application/json; charset=utf-8');
        header('X-Request-Id: ' . request_id());
        $user = app()->requireAuth();
        if (!in_array($user['role'] ?? '', ['admin', 'superadmin'], true) || ($user['source'] ?? '') !== 'kernel') {
            app()->json(['ok' => false, 'error' => 'Forbidden', 'request_id' => request_id()], 403);
            exit;
        }
        $db = app()->db();
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $bridgeAudit = static function (string $action, ?string $entityId = null, mixed $oldData = null, mixed $newData = null): void {
            try {
                app()->cap()->call('kernel.audit.record@1', [
                    'module' => '_kernel',
                    'action' => $action,
                    'entity_type' => 'kernel_integration',
                    'entity_id' => $entityId,
                    'old_data' => $oldData,
                    'new_data' => $newData,
                ]);
            } catch (Throwable $e) {
                write_log('kernel integration audit failed: ' . $e->getMessage(), 'warning', [
                    'module' => '_kernel',
                    'action' => $action,
                    'entity_id' => $entityId,
                    'request_id' => request_id(),
                ]);
            }
        };

        if ($method === 'GET') {
            $catalog = new \Ikabud\Kernel\ControlPlane\IntegrationCatalog(
                $db,
                new \Ikabud\Kernel\Capabilities\CapabilityCatalog(app()->capabilities())
            );
            app()->json([
                'ok' => true,
                'summary' => $catalog->summary(),
                'integrations' => $catalog->integrations(),
                'logs' => $catalog->logs(),
                'request_id' => request_id(),
            ]);
            exit;
        }

        if (in_array($method, ['POST', 'DELETE'], true)) {
            app()->csrfEnforce();
        }

        if ($method === 'POST') {
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            $action = (string)($input['_action'] ?? 'create');

            if ($action === 'validate') {
                $validation = \Ikabud\Kernel\IntegrationBridge::validateDefinition($input);
                $statusCode = !empty($validation['ok']) ? 200 : 422;
                app()->json([
                    'ok' => !empty($validation['ok']),
                    'errors' => array_values(array_filter($validation['errors'] ?? [], static fn(mixed $value): bool => is_string($value) && $value !== '')),
                    'resolved_capability' => $validation['resolved_capability'] ?? null,
                    'available_vars' => $validation['available_vars'] ?? [],
                    'mapping_vars' => $validation['mapping_vars'] ?? [],
                    'version_lock' => $validation['normalized']['version_lock'] ?? null,
                    'request_id' => request_id(),
                ], $statusCode);
                exit;
            }

            if ($action === 'toggle') {
                $id = (int)($input['id'] ?? 0);
                if ($id <= 0) { app()->json(['ok' => false, 'error' => 'Missing id', 'request_id' => request_id()], 400); exit; }
                $existingStmt = $db->prepare('SELECT * FROM kernel_integrations WHERE id = ? LIMIT 1');
                $existingStmt->execute([$id]);
                $existing = $existingStmt->fetch();
                if (!$existing) {
                    app()->json(['ok' => false, 'error' => 'Bridge not found', 'request_id' => request_id()], 404);
                    exit;
                }
                $stmt = $db->prepare('UPDATE kernel_integrations SET is_active = NOT is_active, updated_at = NOW() WHERE id = ?');
                $stmt->execute([$id]);
                $toggled = !empty($existing['is_active']) ? 0 : 1;
                $bridgeAudit('kernel.integration.toggle', (string)$id, $existing, ['is_active' => $toggled]);
                app()->json(['ok' => true, 'is_active' => $toggled, 'request_id' => request_id()]);
                exit;
            }

            
            if ($action === 'apply_mode') {
                $mode = (string)($input['mode'] ?? '');
                if (!in_array($mode, ['wms_authoritative_products', 'ecommerce_authoritative_products', 'decoupled'], true)) {
                    app()->json(['ok' => false, 'error' => 'Invalid integration mode', 'request_id' => request_id()], 400);
                    exit;
                }

                if (!function_exists('ecSyncWmsFulfillmentBridges') || !function_exists('ecSyncWmsProductAuthorityBridges')) {
                    app()->json(['ok' => false, 'error' => 'Ecommerce bridge helpers are unavailable', 'request_id' => request_id()], 500);
                    exit;
                }

                $db->beginTransaction();
                try {
                    \Ikabud\Kernel\IntegrationBridge::deleteBridgesByNames([
                        'WMS ↔ Ecommerce Order Sync',
                        'WMS ↔ Ecommerce Order Cancel',
                        'WMS ↔ Ecommerce Stock Alert',
                        'WMS → Ecommerce Product Update',
                        'Ecommerce → WMS Product Update',
                    ]);
                    $db->prepare("DELETE FROM kernel_integrations WHERE integration_mode IN ('wms_authoritative_products', 'ecommerce_authoritative_products')")
                        ->execute();

                    $bridgeIds = [];
                    if ($mode === 'decoupled') {
                        ecSyncWmsFulfillmentBridges(false);
                        ecSyncWmsProductAuthorityBridges(null);
                    } else {
                        $bridgeIds = array_merge(
                            ecSyncWmsFulfillmentBridges(true, $mode),
                            ecSyncWmsProductAuthorityBridges($mode)
                        );
                    }

                    $currentEcommerceSettings = getModuleSettings('ecommerce');
                    saveModuleSettings('ecommerce', array_merge(
                        is_array($currentEcommerceSettings) ? $currentEcommerceSettings : [],
                        ['wms_fulfillment_bridge_enabled' => $mode !== 'decoupled']
                    ));

                    $bridgeAudit('kernel.integration.apply_mode', null, null, [
                        'mode' => $mode,
                        'bridge_ids' => $bridgeIds,
                    ]);
                    $db->commit();
                    app()->json(['ok' => true, 'mode' => $mode, 'bridge_ids' => $bridgeIds, 'request_id' => request_id()]);
                } catch (\Throwable $e) {
                    $db->rollBack();
                    write_log('Failed to apply mode: ' . $e->getMessage(), 'error');
                    app()->json(['ok' => false, 'error' => $e->getMessage(), 'request_id' => request_id()], 500);
                }
                exit;
            }

            if ($action === 'promote') {
                // Convert a bridge to a full EventTrigger rule
                $id = (int)($input['id'] ?? 0);
                if ($id <= 0) { app()->json(['ok' => false, 'error' => 'Missing id', 'request_id' => request_id()], 400); exit; }
                $row = $db->prepare('SELECT * FROM kernel_integrations WHERE id = ?');
                $row->execute([$id]);
                $intg = $row->fetch();
                if (!$intg) { app()->json(['ok' => false, 'error' => 'Bridge not found', 'request_id' => request_id()], 404); exit; }

                // Build Disyl-style template from mapping_json dot-notation
                $mapping = json_decode((string)($intg['mapping_json'] ?? '{}'), true) ?: [];
                $tplParts = [];
                foreach ($mapping as $k => $v) {
                    // Convert {{dot.notation}} → {dot_notation} Disyl-style
                    $converted = is_string($v)
                        ? preg_replace_callback('/\{\{([^}]+)\}\}/', function ($m) {
                            return '{' . str_replace('.', '_', trim($m[1])) . '}';
                        }, $v)
                        : json_encode($v);
                    $tplParts[] = '"' . addslashes($k) . '":"' . addslashes((string)$converted) . '"';
                }
                $tpl = '{' . implode(',', $tplParts) . '}';

                if (function_exists('kernelTriggerSave')) {
                    $result = kernelTriggerSave([
                        'module'         => 'kernel',
                        'event_key'      => (string)($intg['trigger_event'] ?? ''),
                        'capability_id'  => (string)($intg['target_capability'] ?? ''),
                        'is_enabled'     => 1,
                        'priority'       => 100,
                        'template'       => $tpl,
                        'max_per_minute' => null,
                        'retry_count'    => 0,
                        'timeout_ms'     => 5000,
                        'meta'           => null,
                    ]);

                    if (!empty($result['ok'])) {
                        // Mark original bridge with event_source=promoted so it's visually distinguishable
                        $db->prepare('UPDATE kernel_integrations SET event_source = ?, updated_at = NOW() WHERE id = ?')
                           ->execute(['promoted', $id]);
                        $bridgeAudit('kernel.integration.promote', (string)$id, $intg, ['event_source' => 'promoted', 'trigger_id' => $result['id'] ?? null]);
                        app()->json(['ok' => true, 'trigger_id' => $result['id'] ?? null, 'request_id' => request_id()]);
                    } else {
                        app()->json(['ok' => false, 'error' => $result['error'] ?? 'Failed to save trigger', 'request_id' => request_id()], 500);
                    }
                } else {
                    app()->json(['ok' => false, 'error' => 'kernelTriggerSave not available', 'request_id' => request_id()], 500);
                }
                exit;
            }

            // Default: create new bridge
            if (!isset($input['name'], $input['trigger_event'], $input['target_capability'], $input['mapping_json'])) {
                app()->json(['ok' => false, 'error' => 'Missing required fields (name, trigger_event, target_capability, mapping_json)', 'request_id' => request_id()], 400);
                exit;
            }

            $name = trim((string)$input['name']);
            $triggerEvent = trim((string)$input['trigger_event']);
            $targetCap = trim((string)$input['target_capability']);
            if ($name === '' || $triggerEvent === '' || $targetCap === '') {
                app()->json(['ok' => false, 'error' => 'Name, trigger_event, and target_capability must be non-empty.', 'request_id' => request_id()], 422);
                exit;
            }

            $mappingInput = $input['mapping_json'];
            if (is_string($mappingInput)) {
                $mappingJson = trim($mappingInput);
                $decodedMapping = json_decode($mappingJson, true);
            } elseif (is_array($mappingInput)) {
                $decodedMapping = $mappingInput;
                $mappingJson = json_encode($mappingInput, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            } else {
                $decodedMapping = null;
                $mappingJson = '';
            }

            // Phase 3 — validate mapping_json is valid JSON
            if (!is_array($decodedMapping) || $mappingJson === '' || (function_exists('array_is_list') ? array_is_list($decodedMapping) : array_keys($decodedMapping) === range(0, count($decodedMapping) - 1))) {
                app()->json(['ok' => false, 'error' => 'mapping_json must be a valid JSON object.', 'request_id' => request_id()], 400);
                exit;
            }

            $validation = \Ikabud\Kernel\IntegrationBridge::validateDefinition(array_merge($input, [
                'mapping_json' => $decodedMapping,
                'event_source' => (string)($input['event_source'] ?? 'eventbus'),
            ]));
            if (empty($validation['ok'])) {
                app()->json([
                    'ok' => false,
                    'error' => implode(' ', array_values(array_filter($validation['errors'] ?? [], static fn(mixed $value): bool => is_string($value) && $value !== ''))),
                    'errors' => $validation['errors'] ?? [],
                    'request_id' => request_id(),
                ], 422);
                exit;
            }

            $normalized = is_array($validation['normalized'] ?? null) ? $validation['normalized'] : [];
            $resolvedCap = (string)($validation['resolved_capability'] ?? '');
            $existingRowsStmt = $db->prepare('SELECT id, target_capability FROM kernel_integrations WHERE trigger_event = ?');
            $existingRowsStmt->execute([$triggerEvent]);
            $existingRows = $existingRowsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $existingId = 0;
            foreach ($existingRows as $existingRow) {
                $existingTarget = trim((string)($existingRow['target_capability'] ?? ''));
                if ($existingTarget === '') {
                    continue;
                }
                if ((string)app()->capabilities()->resolve($existingTarget) === $resolvedCap) {
                    $existingId = (int)($existingRow['id'] ?? 0);
                    break;
                }
            }
            if ($existingId > 0) {
                app()->json(['ok' => false, 'error' => 'A bridge for this event and capability already exists.', 'id' => $existingId, 'request_id' => request_id()], 409);
                exit;
            }

            $versionLock = $normalized['version_lock'] ?? null;
            $eventSource = (string)($normalized['event_source'] ?? 'eventbus');
            $mappingJson = (string)($normalized['mapping_json'] ?? $mappingJson);

            try {
                $stmt = $db->prepare(
                    'INSERT INTO kernel_integrations (name, trigger_event, target_capability, mapping_json, is_active, event_source, version_lock) '
                    . 'VALUES (?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([
                    (string)($normalized['name'] ?? $name),
                    $triggerEvent,
                    (string)($normalized['target_capability'] ?? $targetCap),
                    $mappingJson,
                    (int)($normalized['is_active'] ?? (isset($input['is_active']) ? (int)$input['is_active'] : 1)),
                    $eventSource,
                    is_string($versionLock) && $versionLock !== '' ? $versionLock : null,
                ]);
            } catch (Throwable $e) {
                $message = str_contains(strtolower($e->getMessage()), 'duplicate') || str_contains(strtolower($e->getMessage()), 'unique')
                    ? 'A bridge for this event and capability already exists.'
                    : 'Failed to create bridge.';
                app()->json(['ok' => false, 'error' => $message, 'request_id' => request_id()], 409);
                exit;
            }
            $newId = (int)$db->lastInsertId();
            $bridgeAudit('kernel.integration.create', (string)$newId, null, [
                'name' => (string)($normalized['name'] ?? $name),
                'trigger_event' => $triggerEvent,
                'target_capability' => (string)($normalized['target_capability'] ?? $targetCap),
                'event_source' => $eventSource,
                'version_lock' => is_string($versionLock) && $versionLock !== '' ? $versionLock : null,
            ]);
            app()->json(['ok' => true, 'id' => $newId, 'request_id' => request_id()]);
            exit;
        }

        if ($method === 'DELETE') {
            $id = (int)($_GET['id'] ?? 0);
            if ($id > 0) {
                $existingStmt = $db->prepare('SELECT * FROM kernel_integrations WHERE id = ? LIMIT 1');
                $existingStmt->execute([$id]);
                $existing = $existingStmt->fetch();
                $db->prepare('DELETE FROM kernel_integrations WHERE id = ?')->execute([$id]);
                if ($existing) {
                    $bridgeAudit('kernel.integration.delete', (string)$id, $existing, null);
                }
            }
            app()->json(['ok' => true, 'request_id' => request_id()]);
            exit;
        }

        app()->json(['ok' => false, 'error' => 'Method not allowed', 'request_id' => request_id()], 405);
        exit;

    case 'pageSuperadminSettings':
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

    case 'apiSuperadminModules':
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

    case 'apiSuperadminUpdateModuleSettings':
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

    case 'apiSuperadminPerf':
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

    case 'apiSuperadminUpdateModuleCatalog':
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

    case 'apiSuperadminReviewModuleAccessRequest':
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

    case 'apiSuperadminSetModuleEntitlement':
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

    case 'apiSuperadminToggleModule':
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

    case 'pageAdminProfile':
        $user = app()->requireAuth();
        if (!in_array($user['role'] ?? '', ['admin', 'superadmin'], true)) {
            app()->redirect('/');
            exit;
        }
        echo app()->render('pages/admin-profile.disyl', [
            'page_title' => 'Profile',
        ]);
        exit;

    case 'pageAdminUsers':
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
        break;

    case 'authLogin':
        header('Content-Type: application/json; charset=utf-8');
        header('X-Request-Id: ' . request_id());

        $loginRateLimit = kernelConsumeLoginRateLimit();
        if (!empty($loginRateLimit['limited'])) {
            kernelEmitLoginRateLimitJson($loginRateLimit);
            exit;
        }

        $input = app()->input();
        $username = trim((string) ($input['username'] ?? ''));
        $password = (string) ($input['password'] ?? '');

        if ($username === '' || $password === '') {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Username and password are required.']);
            exit;
        }

        $authRow = null;
        $authSource = null;

        // Capability-based authentication pipeline.
        // Providers return: ['user'=>array, 'source'=>string] or null.
        try {
            $authResult = app()->cap()->call('kernel.auth.authenticate@1', [
                'username' => $username,
                'password' => $password,
            ], ['mode' => 'pipeline', 'strict_pipeline' => false]);

            if (is_array($authResult) && isset($authResult['user']) && is_array($authResult['user'])) {
                $authRow = $authResult['user'];
                $authSource = (string)($authResult['source'] ?? '');
            }
        } catch (\Ikabud\Kernel\Capabilities\CapabilityNotFoundException $e) {
            http_response_code(401);
            echo json_encode(['ok' => false, 'error' => 'Invalid username or password.']);
            exit;
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Authentication temporarily unavailable.']);
            exit;
        }

        if (!is_array($authRow)) {
            http_response_code(401);
            echo json_encode(['ok' => false, 'error' => 'Invalid username or password.']);
            exit;
        }

        $role = (string) ($authRow['role'] ?? '');
        $idInt = (int) ($authRow['id'] ?? 0);
        // Preserve module-provided subject to avoid ID collisions with kernel users.id
        // (e.g. daily-ledger cashiers/supervisors use sub like cashier:3 with id=0)
        $sub = (string)($authRow['sub'] ?? '');
        if ($sub === '') {
            $sub = $authSource === 'kernel' ? (string) $idInt : ($role . ':' . $idInt);
        }

        $payload = [
            'sub' => $sub,
            'id' => $idInt,
            'username' => $authRow['username'],
            'name' => $authRow['full_name'],
            'email' => $authRow['email'] ?? '',
            'role' => $role,
            'source' => $authSource,
        ];

        // Bind JWT to current tenant when multi-tenancy is active
        $resolvedTid = app()->tenant()->current();
        if ($resolvedTid !== null) {
            $payload['tenant_id'] = $resolvedTid;
        }

        $token = app()->jwt()->generate($payload);
        $cookieName = config('app.cookie_name', 'app_token');
        $expiry = time() + (int) config('app.jwt.expiration', 86400);
        setcookie($cookieName, $token, [
            'expires' => $expiry,
            'path' => '/',
            'httponly' => true,
            'secure' => is_https(),
            'samesite' => config('cookie.samesite', 'Strict'),
        ]);
        app()->csrfRotate(true);

        // API clients (Accept: application/json) get token + refresh_token in body
        $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
        if (str_contains($accept, 'application/json')) {
            $response = [
                'ok' => true,
                'token' => $token,
                'expires_in' => (int) config('app.jwt.expiration', 14400),
                'user' => [
                    'id' => $idInt,
                    'username' => (string) ($authRow['username'] ?? ''),
                    'name' => (string) ($authRow['full_name'] ?? ''),
                    'role' => $role,
                ],
            ];

            // Refresh tokens are kernel-user only. Module-authenticated users receive JWT only.
            if ($authSource === 'kernel') {
                $refreshToken = bin2hex(random_bytes(32));
                $refreshHash = hash('sha256', $refreshToken);
                $refreshExpiry = date('Y-m-d H:i:s', strtotime('+30 days'));
                try {
                    $rtStmt = app()->db()->prepare(
                        'INSERT INTO refresh_tokens (user_id, token_hash, expires_at) VALUES (:user_id, :token_hash, :expires_at)'
                    );
                    $rtStmt->execute([
                        ':user_id' => $idInt,
                        ':token_hash' => $refreshHash,
                        ':expires_at' => $refreshExpiry,
                    ]);
                    $response['refresh_token'] = $refreshToken;
                    $response['refresh_expires_in'] = 30 * 86400;
                } catch (Throwable $e) {
                    // Non-fatal: login succeeds without refresh token
                }
            }
            echo json_encode($response);
            exit;
        }

        $loginRedirect = kernelResolveAuthenticatedHomeRedirect($payload, true) ?? '/';
        echo json_encode(['ok' => true, 'redirect' => $loginRedirect]);
        exit;

    case 'authRefresh':
        header('Content-Type: application/json; charset=utf-8');
        header('X-Request-Id: ' . request_id());
        $input = app()->input();
        $refreshToken = trim((string) ($input['refresh_token'] ?? ''));

        if ($refreshToken === '') {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'refresh_token is required.']);
            exit;
        }

        $tokenHash = hash('sha256', $refreshToken);
        try {
            $stmt = app()->db()->prepare(
                'SELECT rt.id, rt.user_id, rt.expires_at, rt.revoked,
                        u.username, u.full_name, u.role, u.is_active
                 FROM refresh_tokens rt
                 INNER JOIN users u ON u.id = rt.user_id
                 WHERE rt.token_hash = :token_hash
                 LIMIT 1'
            );
            $stmt->execute([':token_hash' => $tokenHash]);
            $rtRow = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Database error.']);
            exit;
        }

        if (!is_array($rtRow)) {
            http_response_code(401);
            echo json_encode(['ok' => false, 'error' => 'Invalid refresh token.']);
            exit;
        }

        if ($rtRow['revoked'] || $rtRow['expires_at'] <= date('Y-m-d H:i:s') || !$rtRow['is_active']) {
            http_response_code(401);
            echo json_encode(['ok' => false, 'error' => 'Refresh token expired or revoked.']);
            exit;
        }

        // Revoke old refresh token (rotation)
        $revokeStmt = app()->db()->prepare('UPDATE refresh_tokens SET revoked = 1 WHERE id = :id');
        $revokeStmt->execute([':id' => (int) $rtRow['id']]);

        // Issue new JWT
        $payload = [
            'sub' => (string) $rtRow['user_id'],
            'id' => (int) $rtRow['user_id'],
            'username' => $rtRow['username'],
            'name' => $rtRow['full_name'],
            'role' => $rtRow['role'],
            'source' => 'kernel',
        ];

        // Bind JWT to current tenant when multi-tenancy is active
        $resolvedTid = app()->tenant()->current();
        if ($resolvedTid !== null) {
            $payload['tenant_id'] = $resolvedTid;
        }

        $newToken = app()->jwt()->generate($payload);

        // Issue new refresh token (rotation)
        $newRefreshToken = bin2hex(random_bytes(32));
        $newRefreshHash = hash('sha256', $newRefreshToken);
        $refreshExpiry = date('Y-m-d H:i:s', strtotime('+30 days'));
        $insertStmt = app()->db()->prepare(
            'INSERT INTO refresh_tokens (user_id, token_hash, expires_at) VALUES (:user_id, :token_hash, :expires_at)'
        );
        $insertStmt->execute([
            ':user_id' => (int) $rtRow['user_id'],
            ':token_hash' => $newRefreshHash,
            ':expires_at' => $refreshExpiry,
        ]);

        echo json_encode([
            'ok' => true,
            'token' => $newToken,
            'refresh_token' => $newRefreshToken,
            'expires_in' => (int) config('app.jwt.expiration', 14400),
            'refresh_expires_in' => 30 * 86400,
        ]);
        exit;

    case 'authLogout':
        $logoutUser = app()->user();
        $logoutInput = app()->input();
        $presentedRefreshToken = trim((string)($logoutInput['refresh_token'] ?? ''));

        try {
            if (is_array($logoutUser) && (($logoutUser['source'] ?? 'kernel') === 'kernel')) {
                $logoutUserId = (int)($logoutUser['id'] ?? 0);
                if ($logoutUserId > 0) {
                    $revokeStmt = app()->db()->prepare('UPDATE refresh_tokens SET revoked = 1 WHERE user_id = :user_id AND revoked = 0');
                    $revokeStmt->execute([':user_id' => $logoutUserId]);
                }
            } elseif ($presentedRefreshToken !== '') {
                $revokeStmt = app()->db()->prepare('UPDATE refresh_tokens SET revoked = 1 WHERE token_hash = :token_hash');
                $revokeStmt->execute([':token_hash' => hash('sha256', $presentedRefreshToken)]);
            }
        } catch (Throwable $e) {
            write_log('authLogout refresh-token revoke failed: ' . $e->getMessage(), 'warning');
        }

        $cookieName = config('app.cookie_name', 'app_token');
        clearAuthCookie($cookieName);
        app()->csrfRotate(true);

        // API clients get JSON instead of redirect
        $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
        if (str_contains($accept, 'application/json')) {
            header('Content-Type: application/json; charset=utf-8');
        header('X-Request-Id: ' . request_id());
            echo json_encode(['ok' => true]);
            exit;
        }

        // If logout was initiated from a module UI (e.g. CMS), send the user back
        // to that module's login page instead of the kernel OS login.
        $ref = strtolower((string)($_SERVER['HTTP_REFERER'] ?? ''));
        if ($ref !== '' && str_contains($ref, '/cms')) {
            app()->redirect('/cms/login');
        }

        app()->redirect('/login');

    case 'apiMe':
        header('Content-Type: application/json; charset=utf-8');
        header('X-Request-Id: ' . request_id());
        $user = app()->user();
        if (!$user) {
            http_response_code(401);
            echo json_encode(['ok' => false, 'error' => 'Authentication required.']);
            exit;
        }

        $meRole = (string) ($user['role'] ?? '');
        echo json_encode([
            'ok' => true,
            'user' => [
                'id' => (int) ($user['id'] ?? 0),
                'username' => (string) ($user['username'] ?? ''),
                'name' => (string) ($user['name'] ?? ''),
                'role' => $meRole,
            ],
        ]);
        exit;

    case 'apiAuditLog':
        header('Content-Type: application/json; charset=utf-8');
        header('X-Request-Id: ' . request_id());
        $user = app()->user();
        if (!$user) {
            http_response_code(401);
            echo json_encode(['ok' => false, 'error' => 'Authentication required.']);
            exit;
        }

        // Only kernel admin or superadmin can view audit log
        $auditRole = (string) ($user['role'] ?? '');
        if (!in_array($auditRole, ['admin', 'superadmin'], true)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Only admin and superadmin can view audit logs.']);
            exit;
        }

        $auditInput = app()->input();
        $auditWhere = ['1=1'];
        $auditBind = [];

        // Filter: module
        if (!empty($auditInput['module'])) {
            $auditWhere[] = 'a.module = :module';
            $auditBind[':module'] = (string) $auditInput['module'];
        }
        // Filter: branch_id
        if (!empty($auditInput['branch_id'])) {
            $auditWhere[] = 'a.branch_id = :branch_id';
            $auditBind[':branch_id'] = (int) $auditInput['branch_id'];
        }
        // Filter: actor_id
        if (!empty($auditInput['actor_id'])) {
            $auditWhere[] = 'a.actor_user_id = :actor_id';
            $auditBind[':actor_id'] = (int) $auditInput['actor_id'];
        }
        // Filter: date_from
        if (!empty($auditInput['date_from'])) {
            $auditWhere[] = 'a.created_at >= :date_from';
            $auditBind[':date_from'] = (string) $auditInput['date_from'] . ' 00:00:00';
        }
        // Filter: date_to
        if (!empty($auditInput['date_to'])) {
            $auditWhere[] = 'a.created_at <= :date_to';
            $auditBind[':date_to'] = (string) $auditInput['date_to'] . ' 23:59:59';
        }

        $auditLimit = max(1, min(500, (int) ($auditInput['limit'] ?? 50)));
        $auditOffset = max(0, (int) ($auditInput['offset'] ?? 0));

        $auditSql = 'SELECT a.id, a.module, a.actor_user_id, u.username AS actor_username,
                            a.branch_id, a.action, a.entity_type, a.entity_id,
                            a.old_data, a.new_data, a.metadata_json, a.created_at
                     FROM audit_logs a
                     LEFT JOIN users u ON u.id = a.actor_user_id
                     WHERE ' . implode(' AND ', $auditWhere) . '
                     ORDER BY a.created_at DESC
                     LIMIT ' . $auditLimit . ' OFFSET ' . $auditOffset;

        try {
            $auditStmt = app()->db()->prepare($auditSql);
            $auditStmt->execute($auditBind);
            $auditRows = $auditStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            // Decode JSON fields
            foreach ($auditRows as &$aRow) {
                $aRow['old_data'] = $aRow['old_data'] ? json_decode($aRow['old_data'], true) : null;
                $aRow['new_data'] = $aRow['new_data'] ? json_decode($aRow['new_data'], true) : null;
                $aRow['metadata'] = $aRow['metadata_json'] ? json_decode($aRow['metadata_json'], true) : null;
                unset($aRow['metadata_json']);
            }
            unset($aRow);

            // Count total for pagination
            $countSql = 'SELECT COUNT(*) FROM audit_logs a WHERE ' . implode(' AND ', $auditWhere);
            $countStmt = app()->db()->prepare($countSql);
            $countStmt->execute($auditBind);
            $auditTotal = (int) $countStmt->fetchColumn();

            echo json_encode([
                'ok' => true,
                'entries' => $auditRows,
                'pagination' => [
                    'total' => $auditTotal,
                    'limit' => $auditLimit,
                    'offset' => $auditOffset,
                    'has_more' => ($auditOffset + $auditLimit) < $auditTotal,
                ],
            ]);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Failed to query audit logs.']);
        }
        exit;

    case 'pageAdminPlatform':
        $user = app()->requireAuth();
        if (($user['role'] ?? '') !== 'admin') {
            app()->redirect('/');
            exit;
        }
        echo app()->render('pages/admin-platform.disyl', [
            'page_title' => 'Platform Dashboard',
        ]);
        exit;

    case 'pageAdminModules':
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

    case 'pageAdminTenants':
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

    case 'pageAdminKernelTriggers':
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

    case 'pageAdminAi':
        $user = app()->requireAuth();
        if (($user['role'] ?? '') !== 'admin') {
            app()->redirect('/');
            exit;
        }
        echo app()->render('pages/admin-ai.disyl', [
            'page_title' => 'AI',
        ]);
        exit;

    case 'apiListModules':
        header('Content-Type: application/json; charset=utf-8');
        header('X-Request-Id: ' . request_id());
        $user = app()->user();
        if (!$user || ($user['role'] ?? '') !== 'admin') {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Admin only']);
            exit;
        }

        $cacheKey = 'api:list-modules:v1';
        $cached = adminViewCacheGet($cacheKey, $user);
        if ($cached !== null) {
            echo json_encode($cached);
            exit;
        }

        $all = discoverModules();
        $list = [];
        foreach ($all as $m) {
            $capsBlock = is_array($m['capabilities'] ?? null) ? $m['capabilities'] : [];
            $dependsList = [];
            if (!empty($capsBlock)) {
                $capCheck = validateModuleCapabilities($m);
                if (!empty($capCheck['ok'])) {
                    $dependsList = array_values($capCheck['depends'] ?? []);
                }
            }
            $modSettings = getModuleSettings((string)($m['id'] ?? ''));
            $list[] = [
                'id' => $m['id'],
                'name' => $m['name'] ?? $m['id'],
                'version' => $m['version'] ?? '0.0.0',
                'description' => $m['description'] ?? '',
                'author' => $m['author'] ?? '',
                'enabled' => !empty($m['_enabled']),
                'depends' => $dependsList,
                'settings_fields' => is_array($m['settings_fields'] ?? null) ? array_values($m['settings_fields']) : [],
                'settings' => is_array($modSettings) ? $modSettings : [],
            ];
        }

        $payload = ['ok' => true, 'modules' => $list];
        adminViewCacheSet($cacheKey, $payload, ['admin:view:modules', 'admin:view:platform'], $user);
        echo json_encode($payload);
        exit;

    case 'apiModulesHealth':
        header('Content-Type: application/json; charset=utf-8');
        header('X-Request-Id: ' . request_id());
        $user = app()->user();
        if (!$user || ($user['role'] ?? '') !== 'admin') {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Admin only']);
            exit;
        }

        $cacheKey = 'api:modules-health:v1';
        $cached = adminViewCacheGet($cacheKey, $user);
        if ($cached !== null) {
            echo json_encode($cached);
            exit;
        }

        $all = discoverModules();
        $enabled = getEnabledModules();
        $skipped = getSkippedModules();
        $out = [];
        foreach ($all as $m) {
            if (!is_array($m)) {
                continue;
            }
            $moduleId = (string)($m['id'] ?? '');
            if ($moduleId === '') {
                continue;
            }

            $capCheck = validateModuleCapabilities($m);
            $capOk = !empty($capCheck['ok']);
            $capError = $capOk ? null : (string)($capCheck['error'] ?? 'Invalid capability manifest');
            $depends = ($capOk && is_array($capCheck['depends'] ?? null)) ? array_values($capCheck['depends']) : [];
            $missing = [];
            if ($capOk && !empty($depends)) {
                foreach ($depends as $capId) {
                    if (is_string($capId) && $capId !== '' && !app()->capabilities()->has($capId)) {
                        $missing[] = $capId;
                    }
                }
            }

            $ownsTables = is_array($m['owns_tables'] ?? null) ? array_values($m['owns_tables']) : [];
            $readsTables = is_array($m['reads_tables'] ?? null) ? array_values($m['reads_tables']) : [];
            $requiresTables = is_array($m['requires_tables'] ?? null) ? array_values($m['requires_tables']) : [];
            $usesLegacyRequiresTables = empty($ownsTables) && !empty($requiresTables);

            $settings = getModuleSettings($moduleId);
            $allowKernelAdmin = (bool)(is_array($settings) ? ($settings['allow_kernel_admin'] ?? false) : false);
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

            $out[] = [
                'id' => $moduleId,
                'enabled' => !empty($m['_enabled']),
                'loadable' => isset($enabled[$moduleId]),
                'skip_reason' => $skipped[$moduleId]['reason'] ?? null,
                'skip_context' => $skipped[$moduleId]['context'] ?? null,
                'version' => (string)($m['version'] ?? '0.0.0'),
                'capability_manifest_ok' => $capOk,
                'capability_manifest_error' => $capError,
                'capability_depends' => $depends,
                'capability_missing_depends' => $missing,
                'uses_legacy_requires_tables' => $usesLegacyRequiresTables,
                'owns_tables_count' => count($ownsTables),
                'reads_tables_count' => count($readsTables),
                'allow_kernel_admin' => $allowKernelAdmin,
                'settings_fields' => $editableSettingsFields,
                'settings_context_notice' => $settingsContextNotice,
                'settings' => is_array($settings) ? $settings : [],
            ];
        }

        $payload = [
            'ok' => true,
            'modules' => $out,
            'skipped_modules' => array_values($skipped),
            'request_id' => request_id(),
        ];
        adminViewCacheSet($cacheKey, $payload, ['admin:view:modules', 'admin:view:platform', 'admin:view:capabilities'], $user);
        echo json_encode($payload);
        exit;

    case 'apiTenantsList':
        header('Content-Type: application/json; charset=utf-8');
        header('X-Request-Id: ' . request_id());
        $user = app()->user();
        if (!$user || ($user['role'] ?? '') !== 'admin') {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Admin only']);
            exit;
        }

        $cacheKey = 'api:tenants-list:v1';
        $cached = adminViewCacheGet($cacheKey, $user);
        if ($cached !== null) {
            echo json_encode($cached);
            exit;
        }

        try {
            $tStmt = app()->controlDb()->query(
                'SELECT id, tenant_key, status, entry_module_id, admin_email, created_at, updated_at '
                . 'FROM kernel_tenants '
                . 'ORDER BY id DESC'
            );
            $tenants = $tStmt ? ($tStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

            $dStmt = app()->controlDb()->query(
                'SELECT tenant_id, domain FROM kernel_tenant_domains ORDER BY tenant_id ASC, domain ASC'
            );
            $domainsRows = $dStmt ? ($dStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
            $domainsByTenant = [];
            foreach ($domainsRows as $dr) {
                if (!is_array($dr)) continue;
                $tid = (int)($dr['tenant_id'] ?? 0);
                $dom = strtolower(trim((string)($dr['domain'] ?? '')));
                if ($tid <= 0 || $dom === '') continue;
                if (!isset($domainsByTenant[$tid])) $domainsByTenant[$tid] = [];
                $domainsByTenant[$tid][] = $dom;
            }

            $cStmt = app()->controlDb()->query(
                'SELECT tenant_id, db_host, db_name, db_user, db_pass, db_pass_ciphertext, db_pass_iv, db_pass_tag '
                . 'FROM kernel_tenant_db_connections'
            );
            $connRows = $cStmt ? ($cStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
            $connByTenant = [];
            foreach ($connRows as $cr) {
                if (!is_array($cr)) continue;
                $tid = (int)($cr['tenant_id'] ?? 0);
                if ($tid <= 0) continue;
                $connByTenant[$tid] = $cr;
            }

            $entryModuleOptions = listTenantEntryModuleOptions();
            $out = [];
            foreach ($tenants as $t) {
                if (!is_array($t)) continue;
                $tid = (int)($t['id'] ?? 0);
                $conn = $connByTenant[$tid] ?? null;
                $dbConfigured = false;
                $dbInfo = null;
                if (is_array($conn)) {
                    $dbConfigured = !empty($conn['db_host']) && !empty($conn['db_name']) && !empty($conn['db_user'])
                        && (
                            !empty($conn['db_pass_ciphertext'])
                            || !empty($conn['db_pass'])
                            || (!empty($conn['db_pass_iv']) && !empty($conn['db_pass_tag']))
                        );

                    if (!empty($conn['db_host']) || !empty($conn['db_name']) || !empty($conn['db_user'])) {
                        $dbInfo = [
                            'db_host' => (string)($conn['db_host'] ?? ''),
                            'db_name' => (string)($conn['db_name'] ?? ''),
                            'db_user' => (string)($conn['db_user'] ?? ''),
                        ];
                    }
                }

                $out[] = [
                    'id' => $tid,
                    'tenant_key' => (string)($t['tenant_key'] ?? ''),
                    'status' => (string)($t['status'] ?? 'active'),
                    'entry_module_id' => $t['entry_module_id'] !== null ? (string)$t['entry_module_id'] : null,
                    'admin_email' => $t['admin_email'] !== null ? (string)$t['admin_email'] : null,
                    'domains' => array_values(array_unique($domainsByTenant[$tid] ?? [])),
                    'db_configured' => $dbConfigured,
                    'db' => $dbInfo,
                ];
            }

            $payload = [
                'ok' => true,
                'tenants' => $out,
                'entry_module_options' => $entryModuleOptions,
                'request_id' => request_id(),
            ];
            adminViewCacheSet($cacheKey, $payload, ['admin:view:tenants', 'admin:view:platform'], $user);
            echo json_encode($payload);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Failed to load tenants']);
        }
        exit;

    case 'apiListCapabilities':
        header('Content-Type: application/json; charset=utf-8');
        header('X-Request-Id: ' . request_id());
        $user = app()->user();
        $role = (string)($user['role'] ?? '');
        $source = (string)($user['source'] ?? '');
        if (!$user || ($role !== 'admin' && !($role === 'superadmin' && $source === 'kernel'))) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Admin or superadmin only']);
            exit;
        }

        $cacheKey = 'api:list-capabilities:v2';
        $cached = adminViewCacheGet($cacheKey, $user);
        if ($cached !== null) {
            echo json_encode($cached);
            exit;
        }

        $catalog = new \Ikabud\Kernel\Capabilities\CapabilityCatalog(app()->capabilities());
        $payload = [
            'ok' => true,
            'summary' => $catalog->summary(),
            'modules' => $catalog->modules(),
            'events' => $catalog->events(),
            'capabilities' => $catalog->inspectAll(),
            'request_id' => request_id(),
        ];
        adminViewCacheSet($cacheKey, $payload, ['admin:view:capabilities', 'admin:view:platform'], $user);
        echo json_encode($payload);
        exit;

    case 'apiKernelEventsList':
        header('Content-Type: application/json; charset=utf-8');
        header('X-Request-Id: ' . request_id());
        $user = app()->user();
        $role = (string)($user['role'] ?? '');
        $source = (string)($user['source'] ?? '');
        if (!$user || ($role !== 'admin' && !($role === 'superadmin' && $source === 'kernel'))) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Admin or superadmin only']);
            exit;
        }

        $catalog = new \Ikabud\Kernel\ControlPlane\IntegrationCatalog(
            app()->db(),
            new \Ikabud\Kernel\Capabilities\CapabilityCatalog(app()->capabilities())
        );
        echo json_encode([
            'ok' => true,
            'summary' => $catalog->summary(),
            'events' => $catalog->events(),
            'request_id' => request_id(),
        ]);
        exit;

    case 'apiKernelTriggersList':
        header('Content-Type: application/json; charset=utf-8');
        header('X-Request-Id: ' . request_id());
        $user = app()->user();
        $role = (string)($user['role'] ?? '');
        $source = (string)($user['source'] ?? '');
        if (!$user || ($role !== 'admin' && !($role === 'superadmin' && $source === 'kernel'))) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Admin or superadmin only']);
            exit;
        }

        $catalog = new \Ikabud\Kernel\ControlPlane\IntegrationCatalog(
            app()->db(),
            new \Ikabud\Kernel\Capabilities\CapabilityCatalog(app()->capabilities())
        );
        echo json_encode([
            'ok' => true,
            'summary' => $catalog->summary(),
            'triggers' => $catalog->triggers(),
            'request_id' => request_id(),
        ]);
        exit;

    case 'apiKernelTriggerExecutionsList':
        header('Content-Type: application/json; charset=utf-8');
        header('X-Request-Id: ' . request_id());
        $user = app()->user();
        $role = (string)($user['role'] ?? '');
        $source = (string)($user['source'] ?? '');
        if (!$user || ($role !== 'admin' && !($role === 'superadmin' && $source === 'kernel'))) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Admin or superadmin only']);
            exit;
        }

        $catalog = new \Ikabud\Kernel\ControlPlane\IntegrationCatalog(
            app()->db(),
            new \Ikabud\Kernel\Capabilities\CapabilityCatalog(app()->capabilities())
        );
        $filters = [
            'module' => $_GET['module'] ?? null,
            'event_key' => $_GET['event_key'] ?? null,
            'capability_id' => $_GET['capability_id'] ?? null,
            'status' => $_GET['status'] ?? null,
            'correlation_id' => $_GET['correlation_id'] ?? null,
            'request_id' => $_GET['request_id'] ?? null,
            'external_reference' => $_GET['external_reference'] ?? null,
            'trigger_id' => $_GET['trigger_id'] ?? null,
        ];
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;

        echo json_encode([
            'ok' => true,
            'summary' => $catalog->summary(),
            'executions' => $catalog->executions($filters, $limit),
            'request_id' => request_id(),
        ]);
        exit;

    case 'apiKernelTriggerSave':
        header('Content-Type: application/json; charset=utf-8');
        header('X-Request-Id: ' . request_id());
        $user = app()->user();
        $role = (string)($user['role'] ?? '');
        $source = (string)($user['source'] ?? '');
        if (!$user || ($role !== 'admin' && !($role === 'superadmin' && $source === 'kernel'))) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Admin or superadmin only']);
            exit;
        }

        app()->csrfEnforce();

        $input = app()->input();
        $module = trim((string)($input['module'] ?? ''));
        $eventKey = trim((string)($input['event_key'] ?? ''));
        $capId = trim((string)($input['capability_id'] ?? ''));
        $provider = isset($input['provider']) ? trim((string)$input['provider']) : null;
        $isEnabled = !empty($input['is_enabled']);

        $priority = isset($input['priority']) ? (int)$input['priority'] : null;
        $template = isset($input['template']) ? (string)$input['template'] : null;
        $maxPerMinute = ($input['max_per_minute'] ?? null);
        $maxPerMinute = ($maxPerMinute === '' || $maxPerMinute === null) ? null : (int)$maxPerMinute;
        $retryCount = isset($input['retry_count']) ? (int)$input['retry_count'] : null;
        $timeoutMs = isset($input['timeout_ms']) ? (int)$input['timeout_ms'] : null;

        $meta = $input['meta'] ?? null;
        if ($meta !== null && !is_array($meta)) {
            $meta = null;
        }

        if ($module === '' || $eventKey === '' || $capId === '') {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'module, event_key, capability_id are required']);
            exit;
        }

        $updatedBy = (int)($user['id'] ?? 0);
        $ok = kernelTriggerSave(
            $module,
            $eventKey,
            $capId,
            $isEnabled,
            $template,
            $meta,
            $updatedBy,
            $priority,
            $maxPerMinute,
            $retryCount,
            $timeoutMs,
            $provider
        );

        if (!$ok) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Failed to save trigger']);
            exit;
        }

        adminViewCacheInvalidate(['admin:view:platform']);
        echo json_encode(['ok' => true]);
        exit;

    case 'apiKernelTriggerDelete':
        header('Content-Type: application/json; charset=utf-8');
        header('X-Request-Id: ' . request_id());
        $user = app()->user();
        $role = (string)($user['role'] ?? '');
        $source = (string)($user['source'] ?? '');
        if (!$user || ($role !== 'admin' && !($role === 'superadmin' && $source === 'kernel'))) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Admin or superadmin only']);
            exit;
        }

        app()->csrfEnforce();

        $input = app()->input();
        $triggerId = isset($input['id']) ? (int)$input['id'] : 0;
        if ($triggerId <= 0) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'id is required']);
            exit;
        }

        try {
            $stmt = app()->db()->prepare('DELETE FROM kernel_event_triggers WHERE id = ?');
            $stmt->execute([$triggerId]);
            adminViewCacheInvalidate(['admin:view:platform']);
            echo json_encode(['ok' => true]);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Failed to delete trigger']);
        }
        exit;

    case 'apiKernelTriggersSuggest':
        header('Content-Type: application/json; charset=utf-8');
        header('X-Request-Id: ' . request_id());
        $user = app()->user();
        $role = (string)($user['role'] ?? '');
        $source = (string)($user['source'] ?? '');
        if (!$user || ($role !== 'admin' && !($role === 'superadmin' && $source === 'kernel'))) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Admin or superadmin only']);
            exit;
        }

        // Review-first: suggestions are not saved; only returned to UI.
        app()->csrfEnforce();

        $input = app()->input();
        $module = trim((string)($input['module'] ?? ''));
        $eventKey = trim((string)($input['event_key'] ?? ''));
        if ($module === '' || $eventKey === '') {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'module and event_key are required']);
            exit;
        }

        // Load event registry row (optional but preferred)
        $eventRow = [
            'module' => $module,
            'event_key' => $eventKey,
            'description' => '',
            'available_vars' => [],
        ];
        try {
            $stmt = app()->db()->prepare('SELECT module, event_key, description, available_vars FROM kernel_events WHERE module = ? AND event_key = ? LIMIT 1');
            $stmt->execute([$module, $eventKey]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) {
                $eventRow['description'] = (string)($row['description'] ?? '');
                $eventRow['available_vars'] = !empty($row['available_vars']) ? (json_decode((string)$row['available_vars'], true) ?: []) : [];
            }
        } catch (Throwable $e) {
            // non-fatal
        }

        // Load existing triggers for that event
        $existing = [];
        try {
            $stmt = app()->db()->prepare(
                'SELECT id, module, event_key, capability_id, provider, is_enabled, priority, template, max_per_minute, retry_count, timeout_ms, meta '
                . 'FROM kernel_event_triggers '
                . 'WHERE module = ? AND event_key = ? '
                . 'ORDER BY priority ASC, id ASC'
            );
            $stmt->execute([$module, $eventKey]);
            $existing = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($existing as &$t) {
                if (!is_array($t)) continue;
                $t['is_enabled'] = (int)($t['is_enabled'] ?? 0);
                $t['priority'] = (int)($t['priority'] ?? 100);
                $t['max_per_minute'] = $t['max_per_minute'] !== null ? (int)$t['max_per_minute'] : null;
                $t['retry_count'] = (int)($t['retry_count'] ?? 0);
                $t['timeout_ms'] = (int)($t['timeout_ms'] ?? 5000);
                $t['meta'] = !empty($t['meta']) ? (json_decode((string)$t['meta'], true) ?: []) : [];
            }
            unset($t);
        } catch (Throwable $e) {
        }

        // Available capabilities: ids only (lightweight)
        // Guardrail: only pass trigger-safe capabilities to the model.
        // (Avoids nonsense suggestions like kernel.auth.require@1.)
        $availableCaps = [];
        $allowedCaps = [
            'sms.send@1',
            'email.send@1',
            'kernel.audit.record@1',
        ];
        try {
            $allCaps = app()->capabilities()->capabilityIds();
            $availableCaps = [];
            foreach ($allowedCaps as $c) {
                if (in_array($c, $allCaps, true)) {
                    $availableCaps[] = $c;
                }
            }
        } catch (Throwable $e) {
        }

        // Call AI capability provider
        try {
            $res = app()->cap()->call('ai.capability.suggest@1', [
                'event' => $eventRow,
                'existing_triggers' => $existing,
                'available_capabilities' => $availableCaps,
            ], ['caller' => '_kernel']);

            if (!is_array($res)) {
                http_response_code(500);
                echo json_encode(['ok' => false, 'error' => 'AI provider returned invalid result']);
                exit;
            }

            if (empty($res['ok'])) {
                http_response_code(422);
                echo json_encode(['ok' => false, 'error' => (string)($res['error'] ?? 'AI suggestion failed')]);
                exit;
            }

            echo json_encode([
                'ok' => true,
                'suggestions' => $res['suggestions'] ?? [],
                'provider' => $res['provider'] ?? null,
                'allowed_capabilities' => $availableCaps,
            ]);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'AI capability call failed']);
        }
        exit;

    case 'apiCapabilityMetrics':
        header('Content-Type: application/json; charset=utf-8');
        header('X-Request-Id: ' . request_id());
        $user = app()->user();
        if (!$user || ($user['role'] ?? '') !== 'admin') {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Admin only']);
            exit;
        }
        $metrics = load_capability_cache('capability_metrics.json');
        echo json_encode(['ok' => true, 'metrics' => $metrics, 'request_id' => request_id()]);
        exit;

    case 'apiCapabilityBreakers':
        header('Content-Type: application/json; charset=utf-8');
        header('X-Request-Id: ' . request_id());
        $user = app()->user();
        if (!$user || ($user['role'] ?? '') !== 'admin') {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Admin only']);
            exit;
        }
        $breakers = load_capability_cache('capability_breakers.json');
        echo json_encode(['ok' => true, 'breakers' => $breakers, 'request_id' => request_id()]);
        exit;

    case 'apiCapabilityBreakersReset':
        header('Content-Type: application/json; charset=utf-8');
        header('X-Request-Id: ' . request_id());
        $user = app()->user();
        if (!$user || ($user['role'] ?? '') !== 'admin') {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Admin only']);
            exit;
        }
        $input = app()->input();
        $capabilityId = trim((string)($input['capability_id'] ?? ''));
        $providerId = trim((string)($input['provider_id'] ?? ''));
        $breakers = load_capability_cache('capability_breakers.json');
        $cleared = 0;
        if ($capabilityId !== '' && $providerId !== '') {
            $key = $capabilityId . '|' . $providerId;
            if (isset($breakers[$key])) {
                unset($breakers[$key]);
                $cleared = 1;
            }
        } else {
            $cleared = is_array($breakers) ? count($breakers) : 0;
            $breakers = [];
        }
        save_capability_cache('capability_breakers.json', $breakers);
        echo json_encode(['ok' => true, 'cleared' => $cleared, 'request_id' => request_id()]);
        exit;

    case 'apiCacheHealth':
        header('Content-Type: application/json; charset=utf-8');
        header('X-Request-Id: ' . request_id());
        $user = app()->user();
        if (!$user || ($user['role'] ?? '') !== 'admin') {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Admin only']);
            exit;
        }

        $tenantInfo = \Ikabud\Kernel\TenantResolver::controlHostCacheMetrics();
        $cacheStats = app()->cache()->getStats();

        echo json_encode([
            'ok' => true,
            'cache' => $cacheStats,
            'tenant_host_lookup_cache' => $tenantInfo,
            'request_id' => request_id(),
            'generated_at' => gmdate('c'),
        ]);
        exit;

    case 'apiCacheClear':
        header('Content-Type: application/json; charset=utf-8');
        header('X-Request-Id: ' . request_id());
        $user = app()->user();
        if (!$user || ($user['role'] ?? '') !== 'admin') {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Admin only']);
            exit;
        }

        app()->csrfEnforce();

        $result = app()->cache()->clearAll();
        $tenantHostCache = ['memory_cleared' => 0, 'apcu_cleared' => 0];
        if (class_exists('Ikabud\\Kernel\\TenantResolver') && method_exists('Ikabud\\Kernel\\TenantResolver', 'clearControlHostCache')) {
            $tenantHostCache = \Ikabud\Kernel\TenantResolver::clearControlHostCache();
        }

        echo json_encode([
            'ok' => true,
            'cleared' => (int)($result['cleared'] ?? 0),
            'errors' => is_array($result['errors'] ?? null) ? $result['errors'] : [],
            'tenant_host_lookup_cache' => $tenantHostCache,
            'request_id' => request_id(),
            'generated_at' => gmdate('c'),
        ]);
        exit;

    case 'apiUpdateCapabilityPolicy':
        header('Content-Type: application/json; charset=utf-8');
        header('X-Request-Id: ' . request_id());
        $user = app()->user();
        if (!$user || ($user['role'] ?? '') !== 'admin') {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Admin only']);
            exit;
        }

            app()->csrfEnforce();

        $input = app()->input();
        $providerId = trim((string)($input['provider_module_id'] ?? ''));
        $capabilityId = trim((string)($input['capability_id'] ?? ''));
        $allowCallers = $input['allow_callers'] ?? [];

        if ($providerId === '' || $capabilityId === '' || !is_array($allowCallers)) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'provider_module_id, capability_id and allow_callers[] are required']);
            exit;
        }

        $result = updateModuleCapabilityPolicy($providerId, $capabilityId, $allowCallers);
        if (empty($result['ok'])) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => $result['error'] ?? 'Failed to update capability policy']);
            exit;
        }

        adminViewCacheInvalidate(['admin:view:capabilities', 'admin:view:platform', 'admin:view:modules']);
        echo json_encode(['ok' => true] + $result + ['request_id' => request_id()]);
        exit;

    case 'apiUpdateModuleDepends':
        header('Content-Type: application/json; charset=utf-8');
        header('X-Request-Id: ' . request_id());
        $user = app()->user();
        if (!$user || ($user['role'] ?? '') !== 'admin') {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Admin only']);
            exit;
        }

            app()->csrfEnforce();

        $input = app()->input();
        $moduleId = trim((string)($input['module_id'] ?? ''));
        $depends = $input['depends'] ?? [];

        if ($moduleId === '' || !is_array($depends)) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'module_id and depends[] are required']);
            exit;
        }

        $result = updateModuleCapabilityDepends($moduleId, $depends);
        if (empty($result['ok'])) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => $result['error'] ?? 'Failed to update capability dependencies']);
            exit;
        }

        adminViewCacheInvalidate(['admin:view:capabilities', 'admin:view:platform', 'admin:view:modules']);
        echo json_encode(['ok' => true] + $result + ['request_id' => request_id()]);
        exit;

    case 'apiInstallModule':
        header('Content-Type: application/json; charset=utf-8');
        header('X-Request-Id: ' . request_id());
        $user = app()->user();
        if (!$user || ($user['role'] ?? '') !== 'admin') {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Admin only']);
            exit;
        }

            app()->csrfEnforce();

        $upload = $_FILES['module_zip'] ?? null;
        if (!is_array($upload)) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error_code' => 'upload_missing', 'error' => 'Upload a zip file as module_zip', 'request_id' => request_id()]);
            exit;
        }

        $uploadError = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($uploadError !== UPLOAD_ERR_OK) {
            $uploadErrors = [
                UPLOAD_ERR_INI_SIZE => 'Uploaded file exceeds server upload_max_filesize',
                UPLOAD_ERR_FORM_SIZE => 'Uploaded file exceeds form MAX_FILE_SIZE',
                UPLOAD_ERR_PARTIAL => 'Uploaded file was only partially uploaded',
                UPLOAD_ERR_NO_FILE => 'No file was uploaded',
                UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary upload folder',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write uploaded file to disk',
                UPLOAD_ERR_EXTENSION => 'Upload stopped by a PHP extension',
            ];
            $msg = $uploadErrors[$uploadError] ?? 'Upload failed';
            http_response_code(422);
            echo json_encode(['ok' => false, 'error_code' => 'upload_failed', 'error' => $msg, 'request_id' => request_id()]);
            exit;
        }

        $tmpPath = (string)($upload['tmp_name'] ?? '');
        if ($tmpPath === '' || !is_file($tmpPath)) {
            write_log('Module install rejected', 'warning', [
                'source' => 'apiInstallModule',
                'error_code' => 'upload_tmp_missing',
                'actor_id' => $user['id'] ?? null,
                'actor_role' => $user['role'] ?? null,
            ]);
            http_response_code(422);
            echo json_encode(['ok' => false, 'error_code' => 'upload_tmp_missing', 'error' => 'Uploaded file is not available on disk', 'request_id' => request_id()]);
            exit;
        }

        if (function_exists('is_uploaded_file') && !is_uploaded_file($tmpPath)) {
            write_log('Module install rejected', 'warning', [
                'source' => 'apiInstallModule',
                'error_code' => 'upload_not_http_upload',
                'actor_id' => $user['id'] ?? null,
                'actor_role' => $user['role'] ?? null,
            ]);
            http_response_code(422);
            echo json_encode(['ok' => false, 'error_code' => 'upload_not_http_upload', 'error' => 'Upload did not arrive through the HTTP upload pipeline', 'request_id' => request_id()]);
            exit;
        }

        $uploadName = (string)($upload['name'] ?? 'unknown.zip');
        $uploadSize = (int)($upload['size'] ?? 0);
        if ($uploadSize <= 0) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error_code' => 'upload_empty', 'error' => 'Uploaded zip is empty', 'request_id' => request_id()]);
            exit;
        }

        $ext = strtolower((string)pathinfo($uploadName, PATHINFO_EXTENSION));
        if ($ext !== 'zip') {
            write_log('Module install rejected', 'warning', [
                'source' => 'apiInstallModule',
                'error_code' => 'upload_invalid_extension',
                'upload_name' => $uploadName,
                'upload_size' => $uploadSize,
                'actor_id' => $user['id'] ?? null,
                'actor_role' => $user['role'] ?? null,
            ]);
            http_response_code(422);
            echo json_encode(['ok' => false, 'error_code' => 'upload_invalid_extension', 'error' => 'Only .zip module packages are supported', 'request_id' => request_id()]);
            exit;
        }

        $uploadMime = '';
        if (class_exists('finfo')) {
            try {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $uploadMime = (string)$finfo->file($tmpPath);
            } catch (Throwable $ignored) {
                $uploadMime = '';
            }
        }

        $allowedZipMimes = [
            'application/zip',
            'application/x-zip',
            'application/x-zip-compressed',
            'application/octet-stream',
        ];
        if ($uploadMime !== '' && !in_array($uploadMime, $allowedZipMimes, true)) {
            write_log('Module install rejected', 'warning', [
                'source' => 'apiInstallModule',
                'error_code' => 'upload_invalid_mime',
                'upload_name' => $uploadName,
                'upload_size' => $uploadSize,
                'upload_mime' => $uploadMime,
                'actor_id' => $user['id'] ?? null,
                'actor_role' => $user['role'] ?? null,
            ]);
            http_response_code(422);
            echo json_encode(['ok' => false, 'error_code' => 'upload_invalid_mime', 'error' => 'Uploaded file MIME type is not a ZIP archive', 'request_id' => request_id()]);
            exit;
        }

        write_log('Module install requested', 'info', [
            'source' => 'apiInstallModule',
            'upload_name' => $uploadName,
            'upload_size' => $uploadSize,
            'upload_mime' => $uploadMime,
            'actor_id' => $user['id'] ?? null,
            'actor_role' => $user['role'] ?? null,
        ]);

        $result = installModuleFromZip($tmpPath);

        if (!empty($result['ok']) && is_array($result['manifest'] ?? null)) {
            $moduleInstallPath = modulesPath() . '/' . trim((string)($result['module_id'] ?? ''));
            $catalogOk = registerApprovedModuleCatalogInstall(
                $result['manifest'],
                $moduleInstallPath,
                $tmpPath,
                [
                    'source' => 'admin_install',
                    'approved_by_user_id' => (int)($user['id'] ?? 0),
                ]
            );
            if (!$catalogOk) {
                write_log('Module catalog registration failed after install', 'warning', [
                    'source' => 'apiInstallModule',
                    'module_id' => $result['module_id'] ?? null,
                    'actor_id' => $user['id'] ?? null,
                ]);
            }
        }

        write_log(
            'Module install completed',
            !empty($result['ok']) ? 'info' : 'warning',
            [
                'source' => 'apiInstallModule',
                'upload_name' => $uploadName,
                'upload_size' => $uploadSize,
                'module_id' => $result['module_id'] ?? null,
                'enabled' => $result['enabled'] ?? null,
                'ok' => !empty($result['ok']),
                'error' => $result['error'] ?? null,
                'actor_id' => $user['id'] ?? null,
                'actor_role' => $user['role'] ?? null,
            ]
        );

        if (!empty($result['ok'])) {
            adminViewCacheInvalidate(['admin:view:modules', 'admin:view:platform', 'admin:view:capabilities']);
        }
        http_response_code($result['ok'] ? 200 : 422);
        echo json_encode($result + ['request_id' => request_id()]);
        exit;

    case 'apiEnableModule':
        header('Content-Type: application/json; charset=utf-8');
        header('X-Request-Id: ' . request_id());
        $user = app()->user();
        if (!$user || ($user['role'] ?? '') !== 'admin') {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Admin only']);
            exit;
        }

            app()->csrfEnforce();

        $modInput = app()->input();
        $modId = trim((string)($modInput['module_id'] ?? ''));
        $allMods = discoverModules();
        if (!isset($allMods[$modId])) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Module not found']);
            exit;
        }

        // Enable-time capability validation: refuse enabling modules with unsatisfied required capabilities.
        $capCheck = validateModuleCapabilities($allMods[$modId]);
        if (empty($capCheck['ok'])) {
            http_response_code(422);
            echo json_encode([
                'ok' => false,
                'error_code' => 'manifest_invalid_capabilities',
                'error' => $capCheck['error'] ?? 'Invalid capability manifest',
                'request_id' => request_id(),
            ]);
            exit;
        }
        $missing = [];
        foreach (($capCheck['depends'] ?? []) as $capId) {
            if (!app()->capabilities()->has((string)$capId)) {
                $missing[] = (string)$capId;
            }
        }
        if (!empty($missing)) {
            http_response_code(422);
            echo json_encode([
                'ok' => false,
                'error_code' => 'missing_capability_providers',
                'error' => 'Missing required capability providers',
                'missing' => $missing,
                'request_id' => request_id(),
            ]);
            exit;
        }

        if (moduleTenantSettingsModeEnabled()) {
            $eTenantId = moduleTenantSettingsTenantId();
            if ($eTenantId !== null) {
                enableModuleForTenant($modId, $eTenantId);
            } else {
                // No tenant resolved (main domain): write directly to global registry.
                $eReg = readModuleRegistry();
                $eReg[$modId] = array_merge($eReg[$modId] ?? [], ['enabled' => true, 'enabled_at' => date('Y-m-d H:i:s')]);
                writeModuleRegistry($eReg);
                kernelFlushCodeCaches();
            }
        } else {
            enableModule($modId);
        }
        adminViewCacheInvalidate(['admin:view:modules', 'admin:view:platform', 'admin:view:capabilities']);
        echo json_encode(['ok' => true, 'module_id' => $modId, 'enabled' => true, 'request_id' => request_id()]);
        exit;

    case 'apiDisableModule':
        header('Content-Type: application/json; charset=utf-8');
        header('X-Request-Id: ' . request_id());
        $user = app()->user();
        if (!$user || ($user['role'] ?? '') !== 'admin') {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Admin only']);
            exit;
        }

            app()->csrfEnforce();

        $modInput = app()->input();
        $modId = trim((string)($modInput['module_id'] ?? ''));
        $allMods = discoverModules();
        if (!isset($allMods[$modId])) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Module not found']);
            exit;
        }
        if (moduleTenantSettingsModeEnabled()) {
            $dTenantId = moduleTenantSettingsTenantId();
            if ($dTenantId !== null) {
                disableModuleForTenant($modId, $dTenantId);
            } else {
                // No tenant resolved (main domain): write directly to global registry.
                $dReg = readModuleRegistry();
                $dReg[$modId] = array_merge($dReg[$modId] ?? [], ['enabled' => false, 'disabled_at' => date('Y-m-d H:i:s')]);
                writeModuleRegistry($dReg);
                kernelFlushCodeCaches();
            }
        } else {
            disableModule($modId);
        }
        adminViewCacheInvalidate(['admin:view:modules', 'admin:view:platform', 'admin:view:capabilities']);
        echo json_encode(['ok' => true, 'module_id' => $modId, 'enabled' => false]);
        exit;

    case 'apiUpdateModuleSettings':
        header('Content-Type: application/json; charset=utf-8');
        header('X-Request-Id: ' . request_id());
        $user = app()->user();
        if (!$user || ($user['role'] ?? '') !== 'admin') {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Admin only']);
            exit;
        }

        // Kernel-enforced CSRF (this endpoint is called from the browser)
        app()->csrfEnforce();

        $input = app()->input();
        $modId = trim((string)($input['module_id'] ?? ''));
        $settingsIn = $input['settings'] ?? null;
        if ($modId === '' || !is_array($settingsIn)) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'module_id and settings are required']);
            exit;
        }

        $allMods = discoverModules();
        if (!isset($allMods[$modId])) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Module not found']);
            exit;
        }

        $oldSettings = getModuleSettings($modId);
        $newSettings = $oldSettings;

        $manifest = $allMods[$modId] ?? [];
        $fields = is_array($manifest['settings_fields'] ?? null) ? $manifest['settings_fields'] : [];
        $allowedKeys = [];
        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }
            $key = trim((string)($field['key'] ?? ''));
            if ($key !== '') {
                $allowedKeys[$key] = $field;
            }
        }

        if (array_key_exists('allow_kernel_admin', $settingsIn)) {
            $newSettings['allow_kernel_admin'] = (bool)$settingsIn['allow_kernel_admin'];
        }

        foreach ($allowedKeys as $key => $field) {
            if (!array_key_exists($key, $settingsIn)) {
                continue;
            }

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
                if (!empty($allowedValues) && !isset($allowedValues[$val])) {
                    continue;
                }
                $newSettings[$key] = $val;
                continue;
            }

            $newSettings[$key] = trim((string)$raw);
        }

        $tenantScopedKeys = array_values(array_filter(array_keys($settingsIn), static function ($key) {
            return $key !== 'allow_kernel_admin';
        }));

        if (moduleTenantSettingsModeEnabled() && moduleTenantSettingsTenantId() === null && !empty($tenantScopedKeys)) {
            http_response_code(422);
            echo json_encode([
                'ok' => false,
                'error' => 'This module\'s settings are tenant-scoped. Open the tenant domain and configure them there.',
                'module_id' => $modId,
                'keys' => $tenantScopedKeys,
            ]);
            exit;
        }

        // allow_kernel_admin is a kernel-lifecycle key — always write to global registry
        // regardless of tenant mode so it is never silently dropped.
        if (array_key_exists('allow_kernel_admin', $settingsIn)) {
            $akaReg = readModuleRegistry();
            $akaReg[$modId]['settings'] = array_merge($akaReg[$modId]['settings'] ?? [], [
                'allow_kernel_admin' => (bool)$settingsIn['allow_kernel_admin'],
            ]);
            writeModuleRegistry($akaReg);
            // Remove from $newSettings so saveModuleSettings() doesn't attempt it again.
            unset($newSettings['allow_kernel_admin']);
        }

        // Only call saveModuleSettings for remaining (tenant-scoped) keys.
        if (!empty(array_diff_key($newSettings, $oldSettings)) || !empty($tenantScopedKeys)) {
            saveModuleSettings($modId, $newSettings);
        }

        // Best-effort audit log
        try {
            app()->cap()->call('kernel.audit.record@1', [
                'module' => '_kernel',
                'action' => 'module.settings.update',
                'entity_type' => 'module',
                'entity_id' => $modId,
                'old_data' => ['settings' => $oldSettings],
                'new_data' => ['settings' => $newSettings],
            ], ['mode' => 'first']);
        } catch (Throwable $e) {
            // ignore
        }

        adminViewCacheInvalidate(['admin:view:modules', 'admin:view:platform']);
        echo json_encode(['ok' => true, 'module_id' => $modId, 'settings' => $newSettings]);
        exit;

    case 'apiAdminCreateUser':
        header('Content-Type: application/json; charset=utf-8');
        header('X-Request-Id: ' . request_id());
        $user = app()->user();
        if (!$user || ($user['role'] ?? '') !== 'admin') {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Admin only']);
            exit;
        }

        $input    = app()->input();
        $username = trim((string)($input['username'] ?? ''));
        $password = (string)($input['password'] ?? '');
        $fullName = trim((string)($input['full_name'] ?? ''));
        $role     = (string)($input['role'] ?? 'viewer');
        $branchId = (int)($input['branch_id'] ?? 0);

        if ($username === '' || $password === '' || $fullName === '') {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'All fields required']);
            exit;
        }

        if (!in_array($role, ['admin', 'superadmin', 'manager', 'viewer'], true)) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Invalid role']);
            exit;
        }

        // Kernel OS users are limited to kernel-managed roles only.

        try {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            app()->db()->prepare(
                'INSERT INTO users (username, password_hash, full_name, role) VALUES (:u, :p, :n, :r)'
            )->execute([':u' => $username, ':p' => $hash, ':n' => $fullName, ':r' => $role]);

            $newUserId = (int)app()->db()->lastInsertId();

            echo json_encode(['ok' => true, 'user_id' => $newUserId]);
        } catch (Throwable $e) {
            write_log('kernel admin create user failed: ' . $e->getMessage(), 'error', [
                'username' => $username,
                'role' => $role,
            ]);
            if (str_contains($e->getMessage(), 'Duplicate entry')) {
                http_response_code(409);
                echo json_encode(['ok' => false, 'error' => 'Username already exists']);
            } else {
                http_response_code(500);
                echo json_encode(['ok' => false, 'error' => 'Failed to create user']);
            }
        }
        exit;

    case 'apiAdminUpdateUser':
        header('Content-Type: application/json; charset=utf-8');
        header('X-Request-Id: ' . request_id());
        $user = app()->user();
        if (!$user || ($user['role'] ?? '') !== 'admin') {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Admin only']);
            exit;
        }

        $input    = app()->input();
        $editId   = (int)($input['user_id'] ?? 0);
        $fullName = trim((string)($input['full_name'] ?? ''));
        $role     = (string)($input['role'] ?? '');
        $isActive = (int)($input['is_active'] ?? 1);
        $password = (string)($input['password'] ?? '');
        $branchId = (int)($input['branch_id'] ?? 0);

        if (!$editId || $fullName === '') {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Invalid input']);
            exit;
        }

        try {
            // Prevent role changes for the currently logged-in admin.
            $currentUserId = (int) ($user['id'] ?? 0);
            if ($currentUserId === $editId) {
                $dbRoleStmt = app()->db()->prepare('SELECT role FROM users WHERE id = :id LIMIT 1');
                $dbRoleStmt->execute([':id' => $editId]);
                $dbRole = (string) ($dbRoleStmt->fetchColumn() ?: '');
                if ($dbRole !== '') {
                    $role = $dbRole;
                }
            }

            if (!in_array($role, ['admin', 'superadmin', 'manager', 'viewer'], true)) {
                http_response_code(422);
                echo json_encode(['ok' => false, 'error' => 'Invalid role']);
                exit;
            }

            $sql = 'UPDATE users SET full_name = :name, role = :role, is_active = :active';
            $bind = [':name' => $fullName, ':role' => $role, ':active' => $isActive, ':id' => $editId];

            if ($password !== '') {
                $sql .= ', password_hash = :pass';
                $bind[':pass'] = password_hash($password, PASSWORD_BCRYPT);
            }
            $sql .= ' WHERE id = :id';

            app()->db()->prepare($sql)->execute($bind);

            // user_branches is no longer kernel-managed (daily-ledger owns branch assignments)

            echo json_encode(['ok' => true]);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Failed to update user']);
        }
        exit;

    case 'apiAdminUpdateProfile':
        header('Content-Type: application/json; charset=utf-8');
        header('X-Request-Id: ' . request_id());
        try {
            $user = app()->user();
            if (!$user || !in_array($user['role'] ?? '', ['admin', 'superadmin'], true)) {
                http_response_code(403);
                echo json_encode(['ok' => false, 'error' => 'Admin only']);
                exit;
            }
            if (isset($input['csrf_token']) && !isset($input['_token'])) {
                $_POST['_token'] = (string)$input['csrf_token'];
            }
            app()->csrfEnforce();

            $fullName = trim((string)($input['full_name'] ?? ''));
            $password = (string)($input['password'] ?? '');
            $editId   = (int)($user['id'] ?? 0);

            if ($editId <= 0 || $fullName === '') {
                http_response_code(422);
                echo json_encode(['ok' => false, 'error' => 'Invalid input']);
                exit;
            }

            $sql = 'UPDATE users SET full_name = :name';
            $bind = [':name' => $fullName, ':id' => $editId];
            if ($password !== '') {
                $sql .= ', password_hash = :pass';
                $bind[':pass'] = password_hash($password, PASSWORD_BCRYPT);
            }
            $sql .= ' WHERE id = :id AND role = \'admin\'';
            $stmt = app()->db()->prepare($sql);
            $stmt->execute($bind);
            $affected = (int)$stmt->rowCount();

            if ($affected <= 0) {
                app()->log('apiAdminUpdateProfile updated 0 rows', 'warning', [
                    'user_id' => $editId,
                ]);
            }

            if (isset($_SESSION['user']) && is_array($_SESSION['user'])) {
                $_SESSION['user']['full_name'] = $fullName;
                $_SESSION['user']['name'] = $fullName;
            }

            // Refresh kernel cached user (App caches currentUser per request)
            app()->setUser(array_merge((array)$user, [
                'full_name' => $fullName,
                'name' => $fullName,
            ]));
            app()->csrfRotate(true);

            // Re-issue auth cookie JWT so subsequent page loads show updated name.
            $newPayload = (array)$user;
            $newPayload['name'] = $fullName;
            $newPayload['full_name'] = $fullName;

            // Preserve tenant_id binding in re-issued token
            $resolvedTid = app()->tenant()->current();
            if ($resolvedTid !== null && !isset($newPayload['tenant_id'])) {
                $newPayload['tenant_id'] = $resolvedTid;
            }

            $newToken = app()->jwt()->generate($newPayload);
            $cookieName = config('app.cookie_name', 'app_token');
            $expiry = time() + (int) config('app.jwt.expiration', 86400);
            setcookie($cookieName, $newToken, [
                'expires' => $expiry,
                'path' => '/',
                'httponly' => true,
                'secure' => is_https(),
                'samesite' => config('cookie.samesite', 'Strict'),
            ]);

            echo json_encode(['ok' => true]);
        } catch (Throwable $e) {
            app()->log('apiAdminUpdateProfile failed: ' . $e->getMessage(), 'error', [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Failed to update profile']);
        }
        exit;

    case 'apiPlatform':
        header('Content-Type: application/json; charset=utf-8');
        header('X-Request-Id: ' . request_id());
        $user = app()->user();
        if (!$user || ($user['role'] ?? '') !== 'admin') {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Admin only']);
            exit;
        }

        $cacheKey = 'api:platform:v2';
        $cached = adminViewCacheGet($cacheKey, $user);
        if ($cached !== null) {
            echo json_encode($cached);
            exit;
        }

        if (kernelUpdatesAutoSyncOnPlatformEnabled()) {
            kernelUpdatesMaybeAutoSync($user);
        }

        $platformId = app()->platformIdentity();
        $skippedModules = array_values(getSkippedModules());
        $routeAmbiguityMode = (string) config('app.modules.route_ambiguity_mode', 'warn');

        // Modules
        $allModules = discoverModules();
        $enabledModules = [];
        $disabledModules = [];
        foreach ($allModules as $m) {
            $entry = [
                'id' => (string)($m['id'] ?? ''),
                'name' => (string)($m['name'] ?? $m['id'] ?? ''),
                'version' => (string)($m['version'] ?? '0.0.0'),
                'status' => $m['status'] ?? null,
                'requires_kernel' => $m['requires_kernel'] ?? null,
            ];
            if (!empty($m['_enabled'])) {
                $enabledModules[] = $entry;
            } else {
                $disabledModules[] = $entry;
            }
        }

        $capabilityCatalog = new \Ikabud\Kernel\Capabilities\CapabilityCatalog(app()->capabilities(), $allModules);
        $integrationCatalog = new \Ikabud\Kernel\ControlPlane\IntegrationCatalog(app()->db(), $capabilityCatalog);

        // Capabilities (count only for summary — full list via /api/v1/admin/capabilities)
        $capSummary = [];
        foreach ($capabilityCatalog->inspectAll() as $capability) {
            $capSummary[] = [
                'id' => (string)($capability['id'] ?? ''),
                'provider_count' => (int)($capability['provider_count'] ?? 0),
                'declared_provider_count' => (int)($capability['declared_provider_count'] ?? 0),
                'runtime_registered' => !empty($capability['runtime_registered']),
                'effective_schema_mode' => $capability['effective_schema_mode'] ?? null,
            ];
        }
        $integrationSummary = $integrationCatalog->summary();
        $eventsCount = (int)($integrationSummary['event_count'] ?? 0);
        $triggersTotal = (int)($integrationSummary['trigger_count'] ?? 0);
        $triggersEnabled = (int)($integrationSummary['active_trigger_count'] ?? 0);

        // Health summary + per-capability health
        $health = app()->cap()->healthAll();
        $healthSummary = [
            'total_calls' => 0,
            'total_errors' => 0,
            'breakers_open' => 0,
        ];
        $healthByCapability = [];
        foreach ($health as $h) {
            $healthSummary['total_calls'] += (int)($h['count'] ?? 0);
            $healthSummary['total_errors'] += (int)($h['errors'] ?? 0);
            if (!empty($h['breaker_open'])) {
                $healthSummary['breakers_open']++;
            }
            $healthByCapability[] = $h;
        }

        // Glossary — plain-English labels for capabilities/events/terms
        $glossary = app()->glossary();

        $recentExecutions = $integrationCatalog->executions([], 20);
        $traces = array_map(static function (array $execution): array {
            $status = trim((string)($execution['status'] ?? 'unknown'));

            return [
                '_timestamp' => $execution['created_at'] ?? '',
                'ok' => $status === 'success',
                'status' => $status,
                'event' => $execution['event_key'] ?? '',
                'capability' => $execution['resolved_capability'] ?? ($execution['capability_id'] ?? ''),
                'capability_id' => $execution['capability_id'] ?? '',
                'trigger_id' => $execution['trigger_id'] ?? null,
                'correlation_id' => $execution['correlation_id'] ?? null,
                'request_id' => $execution['request_id'] ?? null,
                'external_reference' => $execution['external_reference'] ?? null,
                'duration_ms' => $execution['duration_ms'] ?? 0,
                'module' => $execution['module'] ?? '',
                'error' => $execution['error_message'] ?? null,
            ];
        }, $recentExecutions);
        $traceTimelines = $integrationCatalog->timelines([], 8, 80);

        $payload = [
            'ok' => true,
            'platform' => $platformId,
            'updates' => kernelUpdatesBuildSummary(),
            'modules' => [
                'enabled_count' => count($enabledModules),
                'disabled_count' => count($disabledModules),
                'skipped_count' => count($skippedModules),
                'enabled' => $enabledModules,
                'disabled' => $disabledModules,
                'skipped' => $skippedModules,
            ],
            'capabilities' => [
                'count' => count($capSummary),
                'entries' => $capSummary,
            ],
            'events' => ['count' => $eventsCount],
            'triggers' => [
                'total' => $triggersTotal,
                'enabled' => $triggersEnabled,
                'executions' => (int)($integrationSummary['trigger_execution_count'] ?? 0),
                'timelines' => count($traceTimelines),
            ],
            'traces' => $traces,
            'trace_timelines' => $traceTimelines,
            'glossary' => $glossary,
            'health' => $healthSummary,
            'runtime' => [
                'route_ambiguity_mode' => $routeAmbiguityMode,
                'skipped_modules_count' => count($skippedModules),
            ],
            'request_id' => request_id(),
            'generated_at' => gmdate('c'),
        ];
        adminViewCacheSet($cacheKey, $payload, ['admin:view:platform', 'admin:view:modules', 'admin:view:capabilities'], $user);
        echo json_encode($payload, JSON_UNESCAPED_SLASHES);
        exit;

    case 'apiAdminCheckUpdates':
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

    case 'apiHealth':
        $identity = app()->platformIdentity();
        $skippedModules = array_values(getSkippedModules());
        app()->json([
            'ok' => true,
            'app' => $identity['app']['name'] ?? config('app.name', 'Ikabud'),
            'kernel_version' => $identity['kernel']['version'] ?? '0.0.0',
            'kernel_codename' => $identity['kernel']['codename'] ?? '',
            'modules' => [
                'skipped_count' => count($skippedModules),
            ],
            'time' => gmdate('c'),
        ]);
        break;

    default:
        http_response_code(500);
        echo 'Unknown handler';
        break;
}
