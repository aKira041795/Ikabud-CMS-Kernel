<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/security.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../src/helpers/email.php';
require_once __DIR__ . '/../src/helpers/updates.php';


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

/**
 * Release PHP session write lock for safe GET/HEAD requests after render.
 * This allows concurrent subsequent requests to proceed instead of being blocked.
 * Mutating requests (POST/PUT/DELETE) keep the lock until exit/redirect.
 */
function releaseSessionAfterRender(): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method === 'GET' || $method === 'HEAD') {
        release_session_lock_if_active();
    }
}

function capability_cache_path(string $filename): string
{
    return rtrim(defined('STORAGE_PATH') ? STORAGE_PATH : __DIR__, '/') . '/cache/' . ltrim($filename, '/');
}

function load_capability_cache(string $filename): array
{
    $path = capability_cache_path($filename);
    if (!is_file($path)) {
        return [];
    }
    $raw = @file_get_contents($path);
    $decoded = $raw ? json_decode($raw, true) : null;
    return is_array($decoded) ? $decoded : [];
}

function save_capability_cache(string $filename, array $data): void
{
    $path = capability_cache_path($filename);
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    @file_put_contents($path, json_encode($data), LOCK_EX);
}

function adminViewCacheTtl(): int
{
    return max(0, (int)($_ENV['ADMIN_VIEW_CACHE_TTL'] ?? 20));
}

function adminViewCacheInstance(): string
{
    $tid = app()->tenant()->current();
    return 'admin_view_t' . ($tid ?? 0);
}

function adminViewCacheScopedKey(string $key, ?array $user = null): string
{
    $role = (string)($user['role'] ?? 'guest');
    $source = (string)($user['source'] ?? 'none');
    return $key . '|role:' . $role . '|source:' . $source;
}

function adminViewCacheGet(string $key, ?array $user = null): ?array
{
    if (adminViewCacheTtl() <= 0) {
        return null;
    }

    $scopedKey = adminViewCacheScopedKey($key, $user);
    $hit = app()->cache()->get(adminViewCacheInstance(), $scopedKey);
    if (!is_array($hit)) {
        return null;
    }

    $payload = $hit['payload'] ?? null;
    return is_array($payload) ? $payload : null;
}

function adminViewCacheSet(string $key, array $payload, array $tags = [], ?array $user = null): void
{
    $ttl = adminViewCacheTtl();
    if ($ttl <= 0) {
        return;
    }

    $scopedKey = adminViewCacheScopedKey($key, $user);
    app()->cache()->setWithTags(
        adminViewCacheInstance(),
        $scopedKey,
        ['payload' => $payload],
        $tags,
        $ttl
    );
}

function adminViewCacheInvalidate(array $tags): void
{
    if (empty($tags)) {
        return;
    }
    app()->cache()->clearByTags(adminViewCacheInstance(), array_values(array_unique($tags)));
}

function listTenantEntryModuleOptions(): array
{
    $modules = discoverModules();
    $enabled = getEnabledModules();
    $options = [];
    foreach ($modules as $module) {
        if (!is_array($module)) {
            continue;
        }
        $moduleId = trim((string)($module['id'] ?? ''));
        if ($moduleId === '') {
            continue;
        }
        $options[] = [
            'id' => $moduleId,
            'name' => (string)($module['name'] ?? $moduleId),
            'enabled' => !empty($module['_enabled']),
            'loadable' => isset($enabled[$moduleId]),
        ];
    }

    usort($options, static function (array $left, array $right): int {
        return strcmp($left['name'], $right['name']);
    });

    return $options;
}

function normalizeTenantEntryModuleId($value, bool $requireLoadable = false): array
{
    $entryModuleId = trim((string)$value);
    if ($entryModuleId === '') {
        return ['ok' => true, 'value' => null, 'error' => null];
    }

    $optionsById = [];
    foreach (listTenantEntryModuleOptions() as $option) {
        $optionId = (string)($option['id'] ?? '');
        if ($optionId === '') {
            continue;
        }
        $optionsById[$optionId] = $option;
    }

    if (!isset($optionsById[$entryModuleId])) {
        return ['ok' => false, 'value' => null, 'error' => 'invalid_entry_module_id'];
    }
    if ($requireLoadable && empty($optionsById[$entryModuleId]['loadable'])) {
        return ['ok' => false, 'value' => null, 'error' => 'entry_module_not_loadable'];
    }

    return ['ok' => true, 'value' => $entryModuleId, 'error' => null];
}

if (should_enforce_https() && !is_https()) {
    $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($host !== '') {
        $target = 'https://' . $host . ($_SERVER['REQUEST_URI'] ?? '/');
        header('Location: ' . $target, true, 301);
        exit;
    }
}

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
if (is_https()) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}
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

$basePath = rtrim((string) parse_url((string) config('app.url', ''), PHP_URL_PATH), '/');
if ($basePath !== '' && str_starts_with($uri, $basePath)) {
    $uri = substr($uri, strlen($basePath));
    $uri = $uri === '' ? '/' : $uri;
}

// ── Multi-tenant entry module routing (kernel router helper) ─────
try {
    $router = new \Ikabud\Kernel\Http\TenantEntryRouter();
    $uri = $router->rewriteUri($uri);
} catch (Throwable $ignored) {
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

$routes = [
    'GET' => [
        '/' => 'pageHome',
        '/login' => 'pageLogin',
        '/auth/logout' => 'authLogout',
        '/api/v1/auth/logout' => 'authLogout',
        '/api/v1/health' => 'apiHealth',
        '/api/v1/platform' => 'apiPlatform',
        '/api/v1/me' => 'apiMe',
        '/api/v1/audit-log' => 'apiAuditLog',
        '/admin/profile' => 'pageAdminProfile',
        '/admin/users' => 'pageAdminUsers',
        '/admin/modules' => 'pageAdminModules',
        '/admin/tenants' => 'pageAdminTenants',
        '/admin/kernel/triggers' => 'pageAdminKernelTriggers',
        '/admin/platform' => 'pageAdminPlatform',
        '/admin/ai' => 'pageAdminAi',
        '/superadmin/settings' => 'pageSuperadminSettings',
        '/api/v1/superadmin/modules' => 'apiSuperadminModules',
        '/api/v1/admin/modules' => 'apiListModules',
        '/api/v1/admin/modules/health' => 'apiModulesHealth',
        '/api/v1/admin/capabilities' => 'apiListCapabilities',
        '/api/v1/admin/capabilities/metrics' => 'apiCapabilityMetrics',
        '/api/v1/admin/capabilities/breakers' => 'apiCapabilityBreakers',
        '/api/v1/admin/cache/health' => 'apiCacheHealth',
        '/api/v1/admin/kernel/events' => 'apiKernelEventsList',
        '/api/v1/admin/kernel/triggers' => 'apiKernelTriggersList',
        '/api/v1/admin/ai/settings' => 'apiAiSettingsGet',
        '/api/v1/admin/tenants' => 'apiTenantsList',
    ],
    'POST' => [
        '/auth/login' => 'authLogin',
        '/api/v1/auth/login' => 'authLogin',
        '/api/v1/auth/refresh' => 'authRefresh',
        '/api/v1/admin/modules/install' => 'apiInstallModule',
        '/api/v1/admin/modules/enable' => 'apiEnableModule',
        '/api/v1/admin/modules/disable' => 'apiDisableModule',
        '/api/v1/admin/modules/settings' => 'apiUpdateModuleSettings',
        '/api/v1/superadmin/modules/settings' => 'apiSuperadminUpdateModuleSettings',
        '/api/v1/superadmin/modules/toggle' => 'apiSuperadminToggleModule',
        '/api/v1/admin/ai/settings' => 'apiAiSettingsSave',
        '/api/v1/admin/cache/clear' => 'apiCacheClear',
        '/api/v1/admin/updates/check' => 'apiAdminCheckUpdates',
        '/api/v1/admin/profile/update' => 'apiAdminUpdateProfile',
        '/api/v1/admin/users' => 'apiAdminCreateUser',
        '/api/v1/admin/users/update' => 'apiAdminUpdateUser',
        '/api/v1/admin/capabilities/breakers/reset' => 'apiCapabilityBreakersReset',
        '/api/v1/admin/capabilities/policy' => 'apiUpdateCapabilityPolicy',
        '/api/v1/admin/modules/depends' => 'apiUpdateModuleDepends',
        '/api/v1/admin/kernel/triggers/save' => 'apiKernelTriggerSave',
        '/api/v1/admin/kernel/triggers/delete' => 'apiKernelTriggerDelete',
        '/api/v1/admin/kernel/triggers/suggest' => 'apiKernelTriggersSuggest',
        '/api/v1/admin/tenants/create' => 'apiTenantCreate',
        '/api/v1/admin/tenants/entry-module' => 'apiTenantEntryModuleSet',
        '/api/v1/admin/tenants/domain/add' => 'apiTenantDomainAdd',
        '/api/v1/admin/tenants/domain/remove' => 'apiTenantDomainRemove',
        '/api/v1/admin/tenants/db/upsert' => 'apiTenantDbUpsert',
        '/api/v1/admin/tenants/status' => 'apiTenantStatusSet',
    ],
    'PUT' => [],
    'DELETE' => [],
];

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
            header('Location: /login');
            exit;
        }
        $homeRole = (string)($user['role'] ?? '');
        if ($homeRole === 'superadmin' && (string)($user['source'] ?? '') === 'kernel') {
            app()->redirect('/superadmin/settings');
            exit;
        }
        $homeUrl = app()->hooks()->filter('kernel.home_url', null, $homeRole, $user);
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
            if ($homeRole === 'admin' && (string)($user['source'] ?? 'kernel') === 'kernel') {
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
        header('Content-Type: application/json');
        $user = app()->user();
        if (!$user || ($user['role'] ?? '') !== 'admin') {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Admin only']);
            exit;
        }
        app()->csrfEnforce();

        $input = app()->input();
        $tenantKey = strtolower(trim((string)($input['tenant_key'] ?? '')));
        $domain = strtolower(trim((string)($input['domain'] ?? '')));
        $entryModuleNorm = normalizeTenantEntryModuleId($input['entry_module_id'] ?? '', true);
        $entryModuleId = $entryModuleNorm['value'];

        if ($tenantKey === '' || !preg_match('/^[a-z0-9]([a-z0-9\-]*[a-z0-9])?$/', $tenantKey)) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Invalid tenant_key']);
            exit;
        }
        if ($domain === '' || !preg_match('/^[a-z0-9\-\.]+$/', $domain)) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Invalid domain']);
            exit;
        }
        if (empty($entryModuleNorm['ok'])) {
            http_response_code(422);
            $entryModuleError = ($entryModuleNorm['error'] ?? '') === 'entry_module_not_loadable'
                ? 'Entry module must be enabled and loadable'
                : 'Invalid entry_module_id';
            echo json_encode(['ok' => false, 'error' => $entryModuleError, 'error_code' => $entryModuleNorm['error']]);
            exit;
        }

        $pdo = app()->controlDb();
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare('INSERT INTO kernel_tenants (tenant_key, status, entry_module_id) VALUES (:k, :s, :e)');
            $stmt->execute([':k' => $tenantKey, ':s' => 'active', ':e' => $entryModuleId]);
            $tenantId = (int)$pdo->lastInsertId();
            if ($tenantId <= 0) {
                throw new RuntimeException('Failed to create tenant');
            }

            $dStmt = $pdo->prepare('INSERT INTO kernel_tenant_domains (tenant_id, domain) VALUES (:tid, :d)');
            $dStmt->execute([':tid' => $tenantId, ':d' => $domain]);

            $pdo->commit();
            adminViewCacheInvalidate(['admin:view:tenants', 'admin:view:platform']);
            echo json_encode(['ok' => true, 'tenant_id' => $tenantId]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Failed to create tenant']);
        }
        exit;

    case 'apiTenantEntryModuleSet':
        header('Content-Type: application/json');
        $user = app()->user();
        if (!$user || ($user['role'] ?? '') !== 'admin') {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Admin only']);
            exit;
        }
        app()->csrfEnforce();

        $input = app()->input();
        $tenantId = (int)($input['tenant_id'] ?? 0);
        $entryModuleNorm = normalizeTenantEntryModuleId($input['entry_module_id'] ?? '', true);
        $entryModuleId = $entryModuleNorm['value'];

        if ($tenantId <= 0) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'tenant_id is required']);
            exit;
        }
        if (empty($entryModuleNorm['ok'])) {
            http_response_code(422);
            $entryModuleError = ($entryModuleNorm['error'] ?? '') === 'entry_module_not_loadable'
                ? 'Entry module must be enabled and loadable'
                : 'Invalid entry_module_id';
            echo json_encode(['ok' => false, 'error' => $entryModuleError, 'error_code' => $entryModuleNorm['error']]);
            exit;
        }

        try {
            $stmt = app()->controlDb()->prepare('UPDATE kernel_tenants SET entry_module_id = :entry_module_id, updated_at = NOW() WHERE id = :tenant_id');
            $stmt->bindValue(':entry_module_id', $entryModuleId, $entryModuleId === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $stmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
            $stmt->execute();

            if ($stmt->rowCount() === 0) {
                $existsStmt = app()->controlDb()->prepare('SELECT id FROM kernel_tenants WHERE id = :tenant_id LIMIT 1');
                $existsStmt->execute([':tenant_id' => $tenantId]);
                if (!$existsStmt->fetchColumn()) {
                    http_response_code(404);
                    echo json_encode(['ok' => false, 'error' => 'Tenant not found']);
                    exit;
                }
            }

            $sync = syncTenantMigrationsForTenant($tenantId, $entryModuleId);
            if (empty($sync['ok'])) {
                http_response_code(500);
                echo json_encode([
                    'ok' => false,
                    'error' => 'Tenant entry module updated, but tenant migrations failed to synchronize',
                    'details' => $sync['error'] ?? 'Unknown error',
                    'tenant_id' => $tenantId,
                ]);
                exit;
            }

            adminViewCacheInvalidate(['admin:view:tenants', 'admin:view:platform', 'admin:view:modules']);
            echo json_encode(['ok' => true, 'tenant_id' => $tenantId, 'entry_module_id' => $entryModuleId, 'migration_sync' => $sync, 'request_id' => request_id()]);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Failed to update tenant entry module']);
        }
        exit;

    case 'apiTenantDomainAdd':
        header('Content-Type: application/json');
        $user = app()->user();
        if (!$user || ($user['role'] ?? '') !== 'admin') {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Admin only']);
            exit;
        }
        app()->csrfEnforce();

        $input = app()->input();
        $tenantId = (int)($input['tenant_id'] ?? 0);
        $domain = strtolower(trim((string)($input['domain'] ?? '')));
        if ($tenantId <= 0 || $domain === '' || !preg_match('/^[a-z0-9\-\.]+$/', $domain)) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'tenant_id and valid domain are required']);
            exit;
        }

        try {
            $stmt = app()->controlDb()->prepare('INSERT INTO kernel_tenant_domains (tenant_id, domain) VALUES (:tid, :d)');
            $stmt->execute([':tid' => $tenantId, ':d' => $domain]);
            adminViewCacheInvalidate(['admin:view:tenants', 'admin:view:platform']);
            echo json_encode(['ok' => true]);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Failed to add domain']);
        }
        exit;

    case 'apiTenantDomainRemove':
        header('Content-Type: application/json');
        $user = app()->user();
        if (!$user || ($user['role'] ?? '') !== 'admin') {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Admin only']);
            exit;
        }
        app()->csrfEnforce();

        $input = app()->input();
        $tenantId = (int)($input['tenant_id'] ?? 0);
        $domain = strtolower(trim((string)($input['domain'] ?? '')));
        if ($tenantId <= 0 || $domain === '') {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'tenant_id and domain are required']);
            exit;
        }

        try {
            $stmt = app()->controlDb()->prepare('DELETE FROM kernel_tenant_domains WHERE tenant_id = :tid AND domain = :d');
            $stmt->execute([':tid' => $tenantId, ':d' => $domain]);
            adminViewCacheInvalidate(['admin:view:tenants', 'admin:view:platform']);
            echo json_encode(['ok' => true]);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Failed to remove domain']);
        }
        exit;

    case 'apiTenantDbUpsert':
        header('Content-Type: application/json');
        $user = app()->user();
        if (!$user || ($user['role'] ?? '') !== 'admin') {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Admin only']);
            exit;
        }
        app()->csrfEnforce();

        $input = app()->input();
        $tenantId = (int)($input['tenant_id'] ?? 0);
        $dbHost = trim((string)($input['db_host'] ?? ''));
        $dbPort = trim((string)($input['db_port'] ?? '3306'));
        $dbName = trim((string)($input['db_name'] ?? ''));
        $dbUser = trim((string)($input['db_user'] ?? ''));
        $dbPass = (string)($input['db_pass'] ?? '');

        if ($tenantId <= 0 || $dbHost === '' || $dbName === '' || $dbUser === '') {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'tenant_id, db_host, db_name, db_user are required']);
            exit;
        }
        if ($dbPort === '' || !preg_match('/^[0-9]{2,5}$/', $dbPort)) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Invalid db_port']);
            exit;
        }

        $pdo = app()->controlDb();
        try {
            $pdo->beginTransaction();

            $sel = $pdo->prepare('SELECT db_pass_ciphertext, db_pass_iv, db_pass_tag FROM kernel_tenant_db_connections WHERE tenant_id = :tid LIMIT 1');
            $sel->execute([':tid' => $tenantId]);
            $existing = $sel->fetch(PDO::FETCH_ASSOC);
            if (!is_array($existing)) {
                $existing = ['db_pass_ciphertext' => null, 'db_pass_iv' => null, 'db_pass_tag' => null];
            }

            $cipher = $existing['db_pass_ciphertext'] ?? null;
            $iv = $existing['db_pass_iv'] ?? null;
            $tag = $existing['db_pass_tag'] ?? null;

            if (trim($dbPass) !== '') {
                $crypto = new \Ikabud\Kernel\Crypto();
                $enc = $crypto->encryptString($dbPass);
                $cipher = $enc['ciphertext'] ?? null;
                $iv = $enc['iv'] ?? null;
                $tag = $enc['tag'] ?? null;
            }

            $stmt = $pdo->prepare(
                'INSERT INTO kernel_tenant_db_connections '
                . '(tenant_id, db_driver, db_host, db_port, db_name, db_user, db_pass, db_charset, db_pass_ciphertext, db_pass_iv, db_pass_tag) '
                . 'VALUES (:tid, :drv, :host, :port, :name, :user, NULL, :charset, :cipher, :iv, :tag) '
                . 'ON DUPLICATE KEY UPDATE '
                . 'db_driver = VALUES(db_driver), '
                . 'db_host = VALUES(db_host), '
                . 'db_port = VALUES(db_port), '
                . 'db_name = VALUES(db_name), '
                . 'db_user = VALUES(db_user), '
                . 'db_pass = NULL, '
                . 'db_charset = VALUES(db_charset), '
                . 'db_pass_ciphertext = :cipher_u, '
                . 'db_pass_iv = :iv_u, '
                . 'db_pass_tag = :tag_u'
            );

            $bind = [
                ':tid' => $tenantId,
                ':drv' => 'mysql',
                ':host' => $dbHost,
                ':port' => $dbPort,
                ':name' => $dbName,
                ':user' => $dbUser,
                ':charset' => 'utf8mb4',
                ':cipher' => $cipher,
                ':iv' => $iv,
                ':tag' => $tag,
                ':cipher_u' => $cipher,
                ':iv_u' => $iv,
                ':tag_u' => $tag,
            ];
            $stmt->execute($bind);

            $pdo->commit();

            $sync = syncTenantMigrationsForTenant($tenantId);
            if (empty($sync['ok'])) {
                http_response_code(500);
                echo json_encode([
                    'ok' => false,
                    'error' => 'Tenant DB connection saved, but tenant migrations failed to synchronize',
                    'details' => $sync['error'] ?? 'Unknown error',
                    'tenant_id' => $tenantId,
                ]);
                exit;
            }

            adminViewCacheInvalidate(['admin:view:tenants', 'admin:view:platform']);
            echo json_encode(['ok' => true, 'migration_sync' => $sync]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            try {
                if (function_exists('write_log')) {
                    write_log('error', 'apiTenantDbUpsert failed', [
                        'tenant_id' => $tenantId,
                        'message' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                } else {
                    error_log('apiTenantDbUpsert failed: ' . $e->getMessage());
                }
            } catch (Throwable $ignored) {
            }
            http_response_code(500);
            $debug = !empty($_ENV['APP_DEBUG']) || !empty($GLOBALS['config']['app']['debug'] ?? null);
            echo json_encode([
                'ok' => false,
                'error' => $debug ? ('Failed to save DB connection: ' . $e->getMessage()) : 'Failed to save DB connection',
            ]);
        }
        exit;

    case 'apiTenantStatusSet':
        header('Content-Type: application/json');
        $user = app()->user();
        if (!$user || ($user['role'] ?? '') !== 'admin') {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Admin only']);
            exit;
        }
        app()->csrfEnforce();

        $input = app()->input();
        $tenantId = (int)($input['tenant_id'] ?? 0);
        $status = strtolower(trim((string)($input['status'] ?? '')));
        if ($tenantId <= 0 || !in_array($status, ['active', 'suspended'], true)) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'tenant_id and valid status are required']);
            exit;
        }

        try {
            $stmt = app()->controlDb()->prepare('UPDATE kernel_tenants SET status = :s, updated_at = NOW() WHERE id = :tid');
            $stmt->execute([':s' => $status, ':tid' => $tenantId]);
            adminViewCacheInvalidate(['admin:view:tenants', 'admin:view:platform']);
            echo json_encode(['ok' => true]);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Failed to update tenant status']);
        }
        exit;

    case 'apiAiSettingsGet':
        header('Content-Type: application/json');
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
        header('Content-Type: application/json');
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
        if (app()->user()) {
            $loginUser = app()->user();
            $loginRole = (string)($loginUser['role'] ?? '');
            if ($loginRole === 'superadmin' && (string)($loginUser['source'] ?? '') === 'kernel') {
                app()->redirect('/superadmin/settings');
                exit;
            }
            $loginHome = app()->hooks()->filter('kernel.home_url', null, $loginRole, $loginUser) ?? '/';
            app()->redirect($loginHome);
            exit;
        }
        echo app()->render('pages/login.disyl', [
            'page_title' => 'Sign In',
        ]);
        break;

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

        $allModules = discoverModules();

        // ── Build tenant-relevant module whitelist ───────────────────
        $tenantRelevantModules = null;
        $selectedEntryModule = '';
        if ($multiTenant && $selectedTenantId !== null) {
            $tenantRelevantModules = [];

            // Find entry_module_id for the selected tenant
            foreach ($tenants as $t) {
                if ((int)$t['id'] === $selectedTenantId) {
                    $selectedEntryModule = trim((string)($t['entry_module_id'] ?? ''));
                    break;
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

            // Include only modules that already have tenant-specific state.
            foreach ($allModules as $_candidateMod) {
                $_candidateModId = (string)($_candidateMod['id'] ?? '');
                if ($_candidateModId === '') {
                    continue;
                }
                if (isset($tenantRelevantModules[$_candidateModId])) {
                    continue;
                }

                $_candidateTenantSettings = readTenantModuleSettingsForTenant($_candidateModId, $selectedTenantId);
                if (!empty($_candidateTenantSettings)) {
                    $tenantRelevantModules[$_candidateModId] = true;
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
            if ($multiTenant && $selectedTenantId !== null) {
                // If we have a whitelist, skip modules not in it
                if ($tenantRelevantModules !== null && !isset($tenantRelevantModules[$moduleId])) {
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
            'multi_tenant' => $multiTenant,
            'tenants' => $tenantOptions,
            'selected_tenant_id' => $selectedTenantId ?? 0,
            'selected_tenant_label' => $selectedTenantLabel,
            'module_count' => count($moduleList),
            'tenant_db_ok' => $tenantDbOk ?? true,
        ]);
        exit;

    case 'apiSuperadminModules':
        header('Content-Type: application/json');
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
        header('Content-Type: application/json');
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

    case 'apiSuperadminToggleModule':
        header('Content-Type: application/json');
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
        header('Content-Type: application/json');

        // Rate limit: 10 attempts per 5 minutes per (tenant, IP)
        $loginIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $loginTid = app()->tenant()->current();
        $loginRateId = ($loginTid !== null ? 't' . $loginTid . ':' : '') . 'ip:' . $loginIp;
        try {
            $rlStmt = app()->db()->prepare(
                'SELECT attempts, window_start FROM rate_limits WHERE identifier = :id AND action = :action LIMIT 1'
            );
            $rlStmt->execute([':id' => $loginRateId, ':action' => 'login']);
            $rlRow = $rlStmt->fetch(PDO::FETCH_ASSOC);
            $rlCutoff = date('Y-m-d H:i:s', time() - 300);

            if (is_array($rlRow) && $rlRow['window_start'] >= $rlCutoff && (int) $rlRow['attempts'] >= 10) {
                $rlRetry = 300 - (time() - strtotime($rlRow['window_start']));
                header('Retry-After: ' . max(1, $rlRetry));
                http_response_code(429);
                echo json_encode(['ok' => false, 'error' => 'Too many login attempts. Try again later.', 'retry_after' => max(1, $rlRetry)]);
                exit;
            }

            // Increment or insert
            app()->db()->prepare(
                'INSERT INTO rate_limits (identifier, action, attempts, window_start)
                 VALUES (:id, :action, 1, CURRENT_TIMESTAMP)
                 ON DUPLICATE KEY UPDATE
                     attempts = IF(window_start >= :cutoff, attempts + 1, 1),
                     window_start = IF(window_start >= :cutoff2, window_start, CURRENT_TIMESTAMP)'
            )->execute([':id' => $loginRateId, ':action' => 'login', ':cutoff' => $rlCutoff, ':cutoff2' => $rlCutoff]);
        } catch (Throwable $e) {
            // Non-fatal: allow login if rate_limits table doesn't exist yet
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

        // Redirect based on role — kernel hook resolves landing page from modules
        if ($role === 'superadmin' && $authSource === 'kernel') {
            echo json_encode(['ok' => true, 'redirect' => '/superadmin/settings']);
            exit;
        }
        $loginRedirect = app()->hooks()->filter('kernel.home_url', null, $role, $payload) ?? '/';
        echo json_encode(['ok' => true, 'redirect' => $loginRedirect]);
        exit;

    case 'authRefresh':
        header('Content-Type: application/json');
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

        // API clients get JSON instead of redirect
        $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
        if (str_contains($accept, 'application/json')) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => true]);
            exit;
        }

        // If logout was initiated from a module UI (e.g. CMS), send the user back
        // to that module's login page instead of the kernel OS login.
        $ref = strtolower((string)($_SERVER['HTTP_REFERER'] ?? ''));
        if ($ref !== '' && str_contains($ref, '/cms')) {
            header('Location: /cms/login');
            exit;
        }

        header('Location: /login');
        exit;

    case 'apiMe':
        header('Content-Type: application/json');
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
        header('Content-Type: application/json');
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
        if (($user['role'] ?? '') !== 'admin') {
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
        header('Content-Type: application/json');
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
        header('Content-Type: application/json');
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
        header('Content-Type: application/json');
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
                'SELECT id, tenant_key, status, entry_module_id, created_at, updated_at '
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
        header('Content-Type: application/json');
        $user = app()->user();
        if (!$user || ($user['role'] ?? '') !== 'admin') {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Admin only']);
            exit;
        }

        $cacheKey = 'api:list-capabilities:v1';
        $cached = adminViewCacheGet($cacheKey, $user);
        if ($cached !== null) {
            echo json_encode($cached);
            exit;
        }

        $out = app()->capabilities()->inspectAll();
        $payload = ['ok' => true, 'capabilities' => $out, 'request_id' => request_id()];
        adminViewCacheSet($cacheKey, $payload, ['admin:view:capabilities', 'admin:view:platform'], $user);
        echo json_encode($payload);
        exit;

    case 'apiKernelEventsList':
        header('Content-Type: application/json');
        $user = app()->user();
        if (!$user || ($user['role'] ?? '') !== 'admin') {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Admin only']);
            exit;
        }

        try {
            $stmt = app()->db()->query(
                'SELECT module, event_key, description, available_vars, updated_at, created_at '
                . 'FROM kernel_events '
                . 'ORDER BY module ASC, event_key ASC'
            );
            $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
            foreach ($rows as &$r) {
                if (!is_array($r)) continue;
                $r['available_vars'] = !empty($r['available_vars']) ? (json_decode((string)$r['available_vars'], true) ?: []) : [];
            }
            unset($r);
            echo json_encode(['ok' => true, 'events' => $rows]);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Failed to load events']);
        }
        exit;

    case 'apiKernelTriggersList':
        header('Content-Type: application/json');
        $user = app()->user();
        if (!$user || ($user['role'] ?? '') !== 'admin') {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Admin only']);
            exit;
        }

        try {
            $stmt = app()->db()->query(
                'SELECT t.id, t.module, t.event_key, t.capability_id, t.provider, t.is_enabled, t.priority, '
                . 't.template, t.max_per_minute, t.retry_count, t.timeout_ms, t.meta, t.updated_by, t.updated_at, t.created_at, '
                . 'e.description AS event_description '
                . 'FROM kernel_event_triggers t '
                . 'LEFT JOIN kernel_events e ON e.module = t.module AND e.event_key = t.event_key '
                . 'ORDER BY t.module ASC, t.event_key ASC, t.priority ASC, t.id ASC'
            );
            $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
            foreach ($rows as &$r) {
                if (!is_array($r)) continue;
                $r['is_enabled'] = (int)($r['is_enabled'] ?? 0);
                $r['priority'] = (int)($r['priority'] ?? 100);
                $r['max_per_minute'] = $r['max_per_minute'] !== null ? (int)$r['max_per_minute'] : null;
                $r['retry_count'] = (int)($r['retry_count'] ?? 0);
                $r['timeout_ms'] = (int)($r['timeout_ms'] ?? 5000);
                $r['meta'] = !empty($r['meta']) ? (json_decode((string)$r['meta'], true) ?: []) : [];
            }
            unset($r);
            echo json_encode(['ok' => true, 'triggers' => $rows]);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Failed to load triggers']);
        }
        exit;

    case 'apiKernelTriggerSave':
        header('Content-Type: application/json');
        $user = app()->user();
        if (!$user || ($user['role'] ?? '') !== 'admin') {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Admin only']);
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
        header('Content-Type: application/json');
        $user = app()->user();
        if (!$user || ($user['role'] ?? '') !== 'admin') {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Admin only']);
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
        header('Content-Type: application/json');
        $user = app()->user();
        if (!$user || ($user['role'] ?? '') !== 'admin') {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Admin only']);
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
        header('Content-Type: application/json');
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
        header('Content-Type: application/json');
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
        header('Content-Type: application/json');
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
        header('Content-Type: application/json');
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
        header('Content-Type: application/json');
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
        header('Content-Type: application/json');
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
        header('Content-Type: application/json');
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
        header('Content-Type: application/json');
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
        header('Content-Type: application/json');
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
        header('Content-Type: application/json');
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
        header('Content-Type: application/json');
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
        header('Content-Type: application/json');
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
        header('Content-Type: application/json');
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
        header('Content-Type: application/json');
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
        header('Content-Type: application/json');
        $user = app()->user();
        if (!$user || ($user['role'] ?? '') !== 'admin') {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Admin only']);
            exit;
        }

        $cacheKey = 'api:platform:v1';
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

        // Capabilities (count only for summary — full list via /api/v1/admin/capabilities)
        $capIds = app()->capabilities()->capabilityIds();
        $capSummary = [];
        foreach ($capIds as $cid) {
            $provs = app()->capabilities()->providers($cid);
            $capSummary[] = [
                'id' => $cid,
                'provider_count' => count($provs),
                'effective_schema_mode' => app()->cap()->resolveSchemaMode($cid),
            ];
        }

        // Events count
        $eventsCount = 0;
        try {
            $stmt = app()->db()->query('SELECT COUNT(*) FROM kernel_events');
            $eventsCount = $stmt ? (int)$stmt->fetchColumn() : 0;
        } catch (Throwable $e) {
        }

        // Triggers count
        $triggersTotal = 0;
        $triggersEnabled = 0;
        try {
            $stmt = app()->db()->query('SELECT COUNT(*) as total, SUM(is_enabled) as enabled FROM kernel_event_triggers');
            $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
            if (is_array($row)) {
                $triggersTotal = (int)($row['total'] ?? 0);
                $triggersEnabled = (int)($row['enabled'] ?? 0);
            }
        } catch (Throwable $e) {
        }

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

        // Recent trigger traces from app.log (last 20)
        $traces = [];
        try {
            $logPath = STORAGE_PATH . '/logs/app.log';
            if (is_file($logPath)) {
                $lines = @file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
                $traceLines = array_filter($lines, fn($l) => str_contains($l, 'trigger.execution'));
                $traceLines = array_slice(array_values($traceLines), -20);
                foreach ($traceLines as $line) {
                    $jsonStart = strpos($line, '{');
                    if ($jsonStart !== false) {
                        $json = json_decode(substr($line, $jsonStart), true);
                        if (is_array($json)) {
                            // Extract timestamp from log line prefix
                            $ts = '';
                            if (preg_match('/^\[([^\]]+)\]/', $line, $tsMatch)) {
                                $ts = $tsMatch[1];
                            }
                            $json['_timestamp'] = $ts;
                            $traces[] = $json;
                        }
                    }
                }
                $traces = array_reverse($traces);
            }
        } catch (Throwable $e) {
        }

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
            ],
            'traces' => $traces,
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
        header('Content-Type: application/json');
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
