<?php

declare(strict_types=1);

// ── Fast-path page cache: serve cached pages WITHOUT booting the kernel ──
// This runs before bootstrap.php / autoloader / module-manager / DB.
// On a cache hit the response is served in ~5–20 ms and PHP exits.
require_once __DIR__ . '/../src/helpers/fast-path-cache.php';

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/security.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../src/helpers/page-cache.php';
require_once __DIR__ . '/../src/helpers/email.php';
require_once __DIR__ . '/../src/helpers/updates.php';
require_once __DIR__ . '/../src/http/request-bootstrap.php';
require_once __DIR__ . '/../src/http/capability-cache.php';
require_once __DIR__ . '/../src/http/admin-view-cache.php';
require_once __DIR__ . '/../src/http/tenant-entry-modules.php';
require_once __DIR__ . '/../src/http/core-routes.php';
require_once __DIR__ . '/../src/http/admin-handlers.php';
require_once __DIR__ . '/../src/http/page-handlers.php';
require_once __DIR__ . '/../src/http/integration-handlers.php';
require_once __DIR__ . '/../src/http/superadmin-handlers.php';
require_once __DIR__ . '/../src/http/auth-handlers.php';


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

// ── Request timing: log slow requests (>1s) at shutdown ──────────────
register_shutdown_function(static function (): void {
    $startTime = $_SERVER['REQUEST_TIME_FLOAT'] ?? 0;
    if ($startTime <= 0) {
        return;
    }
    $duration = microtime(true) - (float)$startTime;
    $threshold = (float)($_ENV['SLOW_REQUEST_THRESHOLD'] ?? 1.0);
    if ($duration >= $threshold && function_exists('write_log')) {
        $requestUri = (string)($_SERVER['REQUEST_URI'] ?? '');
        $requestPath = parse_url($requestUri !== '' ? $requestUri : '/', PHP_URL_PATH) ?: '/';
        $host = (string)($_SERVER['HTTP_HOST'] ?? '');
        $tenantId = null;
        $entryModuleId = null;

        if (function_exists('app')) {
            try {
                $tenantId = app()->tenant()->current();
                if ($tenantId !== null && function_exists('tenantEntryModuleIdForTenant')) {
                    $entryModuleId = tenantEntryModuleIdForTenant((int)$tenantId);
                }
            } catch (Throwable $ignored) {
            }
        }

        $endpointTag = 'other';
        if ($requestPath === '/login' || $requestPath === '/wms/login') {
            $endpointTag = 'login';
        } elseif ($requestPath === '/api/v1/health' || $requestPath === '/api/v1/wms/health') {
            $endpointTag = 'health';
        }

        write_log('slow_request', 'warning', [
            'duration_ms' => round($duration * 1000, 1),
            'method' => $_SERVER['REQUEST_METHOD'] ?? '',
            'uri' => $requestUri,
            'path' => $requestPath,
            'host' => $host,
            'tenant_id' => $tenantId,
            'entry_module_id' => $entryModuleId,
            'endpoint_tag' => $endpointTag,
            'request_id' => function_exists('request_id') ? request_id() : null,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
        ]);
    }
});

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
        kernelHandlePageHome();
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

    case 'apiTenantAdminPasswordPush':
        kernelHandleApiTenantAdminPasswordPush();
        exit;

    case 'apiTenantSeedData':
        kernelHandleApiTenantSeedData();
        exit;

    case 'apiTenantDelete':
        kernelHandleApiTenantDelete();
        exit;

    case 'apiAiSettingsGet':
        kernelHandleApiAiSettingsGet();
        exit;

    case 'apiAiSettingsSave':
        kernelHandleApiAiSettingsSave();
        exit;

    case 'pageLogin':
        kernelHandlePageLogin();
        break;

    case 'pageSuperadminPerf':
        kernelHandlePageSuperadminPerf();
        exit;

    case 'pageKernelIntegrations':
        kernelHandlePageKernelIntegrations();
        exit;
    
    case 'apiKernelIntegrations':
        $kernelIntegrationsRawBody = file_get_contents('php://input');
        kernelHandleApiKernelIntegrations(is_string($kernelIntegrationsRawBody) ? $kernelIntegrationsRawBody : '');
        exit;

    case 'pageSuperadminSettings':
        kernelHandlePageSuperadminSettings();
        exit;

    case 'apiSuperadminModules':
        kernelHandleApiSuperadminModules();
        exit;

    case 'apiSuperadminUpdateModuleSettings':
        kernelHandleApiSuperadminUpdateModuleSettings();
        exit;

    case 'apiSuperadminPerf':
        kernelHandleApiSuperadminPerf();
        exit;

    case 'apiSuperadminUpdateModuleCatalog':
        kernelHandleApiSuperadminUpdateModuleCatalog();
        exit;

    case 'apiSuperadminReviewModuleAccessRequest':
        kernelHandleApiSuperadminReviewModuleAccessRequest();
        exit;

    case 'apiSuperadminSetModuleEntitlement':
        kernelHandleApiSuperadminSetModuleEntitlement();
        exit;

    case 'apiSuperadminToggleModule':
        kernelHandleApiSuperadminToggleModule();
        exit;

    case 'pageAdminProfile':
        kernelHandlePageAdminProfile();
        exit;
    case 'pageAdminUsers':
        kernelHandlePageAdminUsers();
        exit;
    case 'authLogin':
        kernelHandleAuthLogin();
        exit;
    case 'authRefresh':
        kernelHandleAuthRefresh();
        exit;
    case 'authLogout':
        kernelHandleAuthLogout();
        exit;
    case 'apiMe':
        kernelHandleApiMe();
        exit;
    case 'apiAuditLog':
        kernelHandleApiAuditLog();
        exit;
    case 'pageAdminPlatform':
        kernelHandlePageAdminPlatform();
        exit;
    case 'pageAdminModules':
        kernelHandlePageAdminModules();
        exit;
    case 'pageAdminTenants':
        kernelHandlePageAdminTenants();
        exit;
    case 'pageAdminKernelTriggers':
        kernelHandlePageAdminKernelTriggers();
        exit;
    case 'pageAdminAi':
        kernelHandlePageAdminAi();
        exit;
    case 'apiListModules':
        kernelHandleApiListModules();
        exit;
    case 'apiModulesHealth':
        kernelHandleApiModulesHealth();
        exit;
    case 'apiTenantsList':
        kernelHandleApiTenantsList();
        exit;
    case 'apiListCapabilities':
        kernelHandleApiListCapabilities();
        exit;
    case 'apiKernelEventsList':
        kernelHandleApiKernelEventsList();
        exit;
    case 'apiKernelTriggersList':
        kernelHandleApiKernelTriggersList();
        exit;
    case 'apiKernelTriggerExecutionsList':
        kernelHandleApiKernelTriggerExecutionsList();
        exit;
    case 'apiKernelTriggerSave':
        kernelHandleApiKernelTriggerSave();
        exit;
    case 'apiKernelTriggerDelete':
        kernelHandleApiKernelTriggerDelete();
        exit;
    case 'apiKernelTriggersSuggest':
        kernelHandleApiKernelTriggersSuggest();
        exit;
    case 'apiCapabilityMetrics':
        kernelHandleApiCapabilityMetrics();
        exit;
    case 'apiCapabilityBreakers':
        kernelHandleApiCapabilityBreakers();
        exit;
    case 'apiCapabilityBreakersReset':
        kernelHandleApiCapabilityBreakersReset();
        exit;
    case 'apiCacheHealth':
        kernelHandleApiCacheHealth();
        exit;
    case 'apiCacheClear':
        kernelHandleApiCacheClear();
        exit;
    case 'apiUpdateCapabilityPolicy':
        kernelHandleApiUpdateCapabilityPolicy();
        exit;
    case 'apiUpdateModuleDepends':
        kernelHandleApiUpdateModuleDepends();
        exit;
    case 'apiInstallModule':
        kernelHandleApiInstallModule();
        exit;
    case 'apiEnableModule':
        kernelHandleApiEnableModule();
        exit;
    case 'apiDisableModule':
        kernelHandleApiDisableModule();
        exit;
    case 'apiUpdateModuleSettings':
        kernelHandleApiUpdateModuleSettings();
        exit;
    case 'apiAdminCreateUser':
        kernelHandleApiAdminCreateUser();
        exit;
    case 'apiAdminUpdateUser':
        kernelHandleApiAdminUpdateUser();
        exit;
    case 'apiAdminUpdateProfile':
        kernelHandleApiAdminUpdateProfile();
        exit;
    case 'apiPlatform':
        kernelHandleApiPlatform();
        exit;
    case 'apiAdminCheckUpdates':
        kernelHandleApiAdminCheckUpdates();
        exit;
    case 'apiHealth':
        kernelHandleApiHealth();
        exit;
    default:
        http_response_code(500);
        echo 'Unknown handler';
        break;
}
