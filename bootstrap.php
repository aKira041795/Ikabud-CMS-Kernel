<?php

declare(strict_types=1);

use Ikabud\Kernel\App;

// Composer autoloader (optional)
$composerAutoload = __DIR__ . '/vendor/autoload.php';
if (file_exists($composerAutoload)) {
    require_once $composerAutoload;
}

// Base paths
define('BASE_PATH', __DIR__);
define('CONFIG_PATH', BASE_PATH . '/config');
define('SRC_PATH', BASE_PATH . '/src');
define('STORAGE_PATH', BASE_PATH . '/storage');
define('PUBLIC_PATH', BASE_PATH . '/public');
define('KERNEL_PATH', BASE_PATH . '/kernel');
define('TEMPLATES_PATH', BASE_PATH . '/templates');

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', STORAGE_PATH . '/logs/error.log');

// Load .env if available
if (file_exists(BASE_PATH . '/.env')) {
    $lines = file(BASE_PATH . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if ($line === '' || str_starts_with(trim($line), '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if ($key === '' || preg_match('/^[A-Z][A-Z0-9_]*$/', $key) !== 1) {
            continue;
        }
        // Support optional quoted values in .env.
        if (strlen($value) >= 2) {
            $first = $value[0];
            $last = $value[strlen($value) - 1];
            if (($first === '"' && $last === '"') || ($first === '\'' && $last === '\'')) {
                $value = substr($value, 1, -1);
                $value = str_replace(['\\"', "\\'", '\\\\'], ['"', "'", '\\'], $value);
            }
        }
        if (!isset($_ENV[$key])) {
            $_ENV[$key] = $value;
            putenv($key . '=' . $value);
        }
    }
}

date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'Asia/Manila');

$config = [
    'app' => require CONFIG_PATH . '/app.php',
    'database' => require CONFIG_PATH . '/database.php',
    'control_database' => is_file(CONFIG_PATH . '/control_database.php')
        ? require CONFIG_PATH . '/control_database.php'
        : require CONFIG_PATH . '/database.php',
];

/**
 * Request-scoped context store with legacy global mirroring for compatibility.
 * This lets kernel internals stop depending on ad hoc globals directly while
 * keeping older tests and module code working during the transition.
 *
 * @return array<string, mixed>
 */
function &kernel_request_context_store(): array
{
    static $context = [];
    return $context;
}

function kernel_request_context_has(string $key): bool
{
    $context = &kernel_request_context_store();
    if (array_key_exists($key, $context)) {
        return true;
    }
    // Security: never allow modules to spoof _kernel_ internal flags via $GLOBALS.
    if (str_starts_with($key, '_kernel_')) {
        return false;
    }
    return array_key_exists($key, $GLOBALS);
}

function kernel_request_context_get(string $key, mixed $default = null): mixed
{
    $context = &kernel_request_context_store();
    if (array_key_exists($key, $context)) {
        return $context[$key];
    }

    // Security: never allow modules to spoof _kernel_ internal flags via $GLOBALS.
    // These flags must only be set through kernel_request_context_set() which is
    // controlled by kernel code paths. Direct $GLOBALS writes bypass this control.
    if (str_starts_with($key, '_kernel_')) {
        return $default;
    }

    if (array_key_exists($key, $GLOBALS)) {
        $context[$key] = $GLOBALS[$key];
        return $context[$key];
    }

    return $default;
}

function kernel_request_context_set(string $key, mixed $value): mixed
{
    $context = &kernel_request_context_store();
    $context[$key] = $value;

    if ($key !== '' && $key[0] === '_') {
        $GLOBALS[$key] = $value;
    }

    return $value;
}

function kernel_request_context_delete(string $key): void
{
    $context = &kernel_request_context_store();
    unset($context[$key]);

    if (array_key_exists($key, $GLOBALS)) {
        unset($GLOBALS[$key]);
    }
}

/**
 * @return array<int, mixed>
 */
function kernel_request_context_push(string $key, mixed $value): array
{
    $stack = kernel_request_context_get($key, []);
    if (!is_array($stack)) {
        $stack = [];
    }

    $stack[] = $value;
    kernel_request_context_set($key, $stack);
    return $stack;
}

function kernel_request_context_pop(string $key, mixed $default = null): mixed
{
    $stack = kernel_request_context_get($key, []);
    if (!is_array($stack) || $stack === []) {
        return $default;
    }

    $value = array_pop($stack);
    kernel_request_context_set($key, $stack);
    return $value;
}

function kernel_csp_nonce(): string
{
    $nonce = kernel_request_context_get('_csp_nonce');
    if (is_string($nonce) && $nonce !== '') {
        return $nonce;
    }

    $nonce = base64_encode(random_bytes(16));
    return (string) kernel_request_context_set('_csp_nonce', $nonce);
}

function kernel_validate_redirect_target(string $target): string
{
    $target = trim($target);
    if ($target === '') {
        return '/';
    }

    $decoded = rawurldecode($target);
    if (preg_match('/[\x00-\x1F\x7F]/', $target) === 1 || preg_match('/[\r\n]/', $decoded) === 1) {
        throw new InvalidArgumentException('Invalid redirect target');
    }

    $parts = parse_url($target);
    if ($parts === false) {
        throw new InvalidArgumentException('Invalid redirect target');
    }

    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    $host = strtolower((string)($parts['host'] ?? ''));

    if ($host !== '' || $scheme !== '') {
        if ($scheme === '' || $host === '') {
            throw new InvalidArgumentException('Invalid redirect target');
        }

        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException('Invalid redirect target');
        }

        if (!kernel_is_allowed_redirect_origin($scheme, $host, isset($parts['port']) ? (int)$parts['port'] : null)) {
            throw new InvalidArgumentException('Invalid redirect target');
        }
    }

    return $target;
}

/**
 * Absolute redirects are only allowed for current/configured app origins.
 */
function kernel_is_allowed_redirect_origin(string $scheme, string $host, ?int $port = null): bool
{
    $targetHost = strtolower($host);
    if ($targetHost === '') {
        return false;
    }

    $allowedOrigins = [];

    $requestHost = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($requestHost !== '') {
        $parsedRequest = parse_url(request_scheme() . '://' . $requestHost);
        $requestOriginHost = strtolower((string)($parsedRequest['host'] ?? ''));
        if ($requestOriginHost !== '') {
            $requestPort = isset($parsedRequest['port']) ? (int)$parsedRequest['port'] : null;
            $allowedOrigins[] = [
                'scheme' => strtolower(request_scheme()),
                'host' => $requestOriginHost,
                'port' => $requestPort,
            ];

            if (should_enforce_https()) {
                $allowedOrigins[] = [
                    'scheme' => 'https',
                    'host' => $requestOriginHost,
                    'port' => $requestPort,
                ];
            }
        }
    }

    $configuredAppUrl = trim((string)config('app.url', ''));
    if ($configuredAppUrl !== '') {
        $parsedConfigured = parse_url($configuredAppUrl);
        if ($parsedConfigured !== false) {
            $configuredHost = strtolower((string)($parsedConfigured['host'] ?? ''));
            $configuredScheme = strtolower((string)($parsedConfigured['scheme'] ?? ''));
            if ($configuredHost !== '' && in_array($configuredScheme, ['http', 'https'], true)) {
                $allowedOrigins[] = [
                    'scheme' => $configuredScheme,
                    'host' => $configuredHost,
                    'port' => isset($parsedConfigured['port']) ? (int)$parsedConfigured['port'] : null,
                ];
            }
        }
    }

    foreach ($allowedOrigins as $origin) {
        if (($origin['scheme'] ?? '') !== strtolower($scheme)) {
            continue;
        }
        if (($origin['host'] ?? '') !== $targetHost) {
            continue;
        }
        if (($origin['port'] ?? null) !== $port) {
            continue;
        }
        return true;
    }

    return false;
}

/**
 * Emits a standardized JSON response and exits.
 * Handles headers, request correlation IDs, and proper status codes.
 */
function kernel_emit_json_response(mixed $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('X-Request-Id: ' . request_id());
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function kernel_emit_redirect_header(string $target, int $status = 302, string $headerName = 'Location'): string
{
    $safeTarget = kernel_validate_redirect_target($target);
    header($headerName . ': ' . $safeTarget, true, $status);
    return $safeTarget;
}

function request_id(): string
{
    $existing = kernel_request_context_get('_request_id');
    if (is_string($existing) && $existing !== '') {
        return $existing;
    }

    $incoming = trim((string)($_SERVER['HTTP_X_REQUEST_ID'] ?? ''));
    if ($incoming !== '' && preg_match('/^[A-Za-z0-9\-]{8,128}$/', $incoming)) {
        kernel_request_context_set('_request_id', $incoming);
        $_SERVER['REQUEST_ID'] = $incoming;
        return $incoming;
    }

    try {
        $generated = bin2hex(random_bytes(8));
    } catch (Throwable $e) {
        $generated = uniqid('req_', true);
    }

    kernel_request_context_set('_request_id', $generated);
    $_SERVER['REQUEST_ID'] = $generated;
    return $generated;
}

function is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }

    // Only trust X-Forwarded-* headers from configured trusted proxy IPs/CIDRs.
    // Set TRUSTED_PROXIES env var as a comma-separated list (e.g. "127.0.0.1,10.0.0.0/8").
    // If not set, proxy headers are IGNORED to prevent spoofing (fail-safe default).
    $trustedProxies = trim((string)($_ENV['TRUSTED_PROXIES'] ?? ''));
    if ($trustedProxies !== '') {
        $remoteAddr = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $isFromTrustedProxy = false;

        foreach (array_map('trim', explode(',', $trustedProxies)) as $entry) {
            if ($entry === '') {
                continue;
            }
            if (str_contains($entry, '/')) {
                // CIDR notation — do a simple IP-in-subnet check
                [$subnet, $bits] = explode('/', $entry, 2);
                $bits = (int)$bits;
                if (filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    $mask = $bits > 0 ? (~0 << (32 - $bits)) & 0xFFFFFFFF : 0;
                    if ((ip2long($remoteAddr) & $mask) === (ip2long($subnet) & $mask)) {
                        $isFromTrustedProxy = true;
                        break;
                    }
                }
            } elseif ($entry === $remoteAddr) {
                $isFromTrustedProxy = true;
                break;
            }
        }

        if ($isFromTrustedProxy) {
            $proto = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
            if ($proto === 'https') {
                return true;
            }

            $ssl = strtolower((string)($_SERVER['HTTP_X_FORWARDED_SSL'] ?? ''));
            if ($ssl === 'on') {
                return true;
            }

            $port = (string)($_SERVER['HTTP_X_FORWARDED_PORT'] ?? '');
            if ($port === '443') {
                return true;
            }

            $cfVisitor = (string)($_SERVER['HTTP_CF_VISITOR'] ?? '');
            if ($cfVisitor !== '' && str_contains($cfVisitor, 'https')) {
                return true;
            }
        }
    }

    return false;
}

/**
 * Resolve the client IP address with trusted-proxy awareness.
 *
 * Only trusts X-Forwarded-For when REMOTE_ADDR is in the TRUSTED_PROXIES
 * env var (comma-separated IPs/CIDRs, or "*" to trust all).
 * Without TRUSTED_PROXIES, always returns REMOTE_ADDR.
 */
function kernel_client_ip(): string
{
    $remoteAddr = (string)($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
    $forwarded  = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');

    if ($forwarded === '') {
        return $remoteAddr;
    }

    $trustedProxies = trim((string)($_ENV['TRUSTED_PROXIES'] ?? ''));
    if ($trustedProxies === '') {
        // No trusted proxies configured — do not trust forwarded headers.
        return $remoteAddr;
    }

    // Check if REMOTE_ADDR is a trusted proxy.
    $isTrusted = false;
    if ($trustedProxies === '*') {
        $isTrusted = true;
    } else {
        foreach (array_map('trim', explode(',', $trustedProxies)) as $entry) {
            if ($entry === '') {
                continue;
            }
            if (str_contains($entry, '/')) {
                [$subnet, $bits] = explode('/', $entry, 2);
                $bits = (int)$bits;
                if (filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    $mask = $bits > 0 ? (~0 << (32 - $bits)) & 0xFFFFFFFF : 0;
                    if ((ip2long($remoteAddr) & $mask) === (ip2long($subnet) & $mask)) {
                        $isTrusted = true;
                        break;
                    }
                }
            } elseif ($entry === $remoteAddr) {
                $isTrusted = true;
                break;
            }
        }
    }

    if (!$isTrusted) {
        return $remoteAddr;
    }

    // Trust the leftmost (client-originating) IP from X-Forwarded-For.
    $parts = explode(',', $forwarded);
    $ip = trim($parts[0]);
    if (filter_var($ip, FILTER_VALIDATE_IP)) {
        return $ip;
    }

    return $remoteAddr;
}

function request_scheme(): string
{
    return is_https() ? 'https' : 'http';
}

function kernel_request_base_path(?string $scriptName = null, ?string $appUrl = null): string
{
    $configured = trim((string)($appUrl ?? config('app.url', '')));
    $configuredPath = rtrim((string)parse_url($configured, PHP_URL_PATH), '/');
    if ($configuredPath !== '') {
        return $configuredPath;
    }

    // Allow hosting envs to force base path via Apache SetEnv in .htaccess.
    // IKABUD_BASE_PATH=/ means "no subfolder prefix".
    $envBasePath = trim((string)($_SERVER['IKABUD_BASE_PATH'] ?? $_ENV['IKABUD_BASE_PATH'] ?? ''));
    if ($envBasePath !== '') {
        $envBasePath = rtrim($envBasePath, '/');
        return ($envBasePath === '' || $envBasePath === '/') ? '' : $envBasePath;
    }

    $scriptPath = trim((string)($scriptName ?? ($_SERVER['SCRIPT_NAME'] ?? ($_SERVER['PHP_SELF'] ?? ''))));
    if ($scriptPath === '') {
        return '';
    }

    $scriptPath = str_replace('\\', '/', $scriptPath);
    $scriptPath = (string)(parse_url($scriptPath, PHP_URL_PATH) ?? $scriptPath);

    if (str_ends_with($scriptPath, '/index.php')) {
        $scriptPath = substr($scriptPath, 0, -strlen('/index.php'));
    }

    $scriptPath = rtrim($scriptPath, '/');

    if (str_ends_with($scriptPath, '/public')) {
        $scriptPath = substr($scriptPath, 0, -strlen('/public'));
    }

    $scriptPath = rtrim($scriptPath, '/');
    return ($scriptPath === '' || $scriptPath === '/') ? '' : $scriptPath;
}

function external_base_url(?string $appUrl = null): string
{
    $configured = trim((string)($appUrl ?? config('app.url', '')));
    $fallback = rtrim($configured, '/');
    $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '') {
        return $fallback;
    }

    $basePath = kernel_request_base_path(null, $configured);
    return rtrim(request_scheme() . '://' . $host . $basePath, '/');
}

function kernel_password_reset_policy(): array
{
    static $policy = null;
    if (is_array($policy)) {
        return $policy;
    }

    $policy = [
        'token_bytes' => 32,
        'token_ttl_minutes' => 30,
        'forgot_rate_limit_window_seconds' => 900,
        'forgot_rate_limit_ip_max' => 5,
        'forgot_rate_limit_identity_max' => 3,
        'reset_rate_limit_window_seconds' => 900,
        'reset_rate_limit_ip_max' => 5,
        'forgot_success_message' => 'If the account exists, a reset link has been sent.',
        'forgot_rate_limit_message' => 'Too many password reset requests. Please wait before trying again.',
        'reset_rate_limit_message' => 'Too many reset attempts. Please wait before trying again.',
        'invalid_token_message' => 'Reset link is invalid or expired.',
        'reset_success_message' => 'Password reset successful. You can now sign in.',
    ];

    return $policy;
}

function should_enforce_https(): bool
{
    if (PHP_SAPI === 'cli') {
        return false;
    }

    $env = $_ENV['APP_FORCE_HTTPS'] ?? null;
    if ($env !== null && $env !== '') {
        return filter_var($env, FILTER_VALIDATE_BOOLEAN);
    }

    if (strtolower((string)config('app.env', 'development')) !== 'development') {
        return true;
    }

    $configured = trim((string)config('app.url', ''));
    return strtolower((string)parse_url($configured, PHP_URL_SCHEME)) === 'https';
}

function capability_call_context(): ?array
{
    $ctx = kernel_request_context_get('_capability_call_context');
    return is_array($ctx) ? $ctx : null;
}

function write_log(string $message, string $level = 'error', array $context = []): void
{
    if (!isset($context['request_id'])) {
        $context['request_id'] = request_id();
    }

    $logDir = STORAGE_PATH . '/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0775, true);
    }

    $logFormat = strtolower(trim((string)($_ENV['LOG_FORMAT'] ?? '')));

    if ($logFormat === 'json') {
        $tenantId = 0;
        try {
            if (function_exists('app')) {
                $tenantId = (int)(app()->tenantId ?? 0);
            }
        } catch (\Throwable $e) {
        }
        $entry = [
            'timestamp' => date('c'),
            'level' => $level,
            'message' => $message,
            'request_id' => $context['request_id'] ?? '',
            'tenant_id' => $tenantId,
        ];
        if ($context !== [] && $context !== ['request_id' => $entry['request_id']]) {
            $filtered = $context;
            unset($filtered['request_id']);
            if ($filtered !== []) {
                $entry['context'] = $filtered;
            }
        }
        $line = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    } else {
        $line = sprintf(
            "[%s] [%s] %s %s\n",
            date('Y-m-d H:i:s'),
            $level,
            $message,
            $context ? json_encode($context, JSON_UNESCAPED_SLASHES) : ''
        );
    }

    @file_put_contents($logDir . '/app.log', $line, FILE_APPEND | LOCK_EX);
}


    function kernelBuildRequestDispatchContext(string $method, string $uri, array $extra = []): array
    {
        $normalizedMethod = strtoupper(trim($method));
        if ($normalizedMethod === '') {
            $normalizedMethod = 'GET';
        }

        $normalizedUri = trim($uri);
        if ($normalizedUri === '') {
            $normalizedUri = '/';
        }

        $context = [
            'method' => $normalizedMethod,
            'uri' => $normalizedUri,
            'handled' => false,
            'redirect' => null,
            'redirect_status' => 302,
            'request_id' => request_id(),
            'is_api' => str_starts_with($normalizedUri, '/api/'),
            'is_htmx' => function_exists('app') ? app()->isHtmx() : false,
        ];

        if (function_exists('app')) {
            $context['user'] = app()->user();
        }

        $context = array_merge($context, $extra);
        kernel_request_context_set('_request_dispatch_context', $context);
        return $context;
    }

    function kernelCurrentRequestDispatchContext(): ?array
    {
        $context = kernel_request_context_get('_request_dispatch_context');
        return is_array($context) ? $context : null;
    }

    function kernelApplyRequestBeforeDispatch(array $context): array
    {
        if (!function_exists('app')) {
            kernel_request_context_set('_request_dispatch_context', $context);
            return $context;
        }

        $filtered = app()->hooks()->filter('kernel.request.before_dispatch', $context);
        if (!is_array($filtered)) {
            $filtered = $context;
        }

        if (!isset($filtered['method']) || !is_string($filtered['method']) || trim($filtered['method']) === '') {
            $filtered['method'] = (string)($context['method'] ?? 'GET');
        }
        if (!isset($filtered['uri']) || !is_string($filtered['uri']) || trim($filtered['uri']) === '') {
            $filtered['uri'] = (string)($context['uri'] ?? '/');
        }
        if (!array_key_exists('handled', $filtered)) {
            $filtered['handled'] = (bool)($context['handled'] ?? false);
        }
        if (!array_key_exists('redirect', $filtered)) {
            $filtered['redirect'] = $context['redirect'] ?? null;
        }
        if (!array_key_exists('redirect_status', $filtered)) {
            $filtered['redirect_status'] = (int)($context['redirect_status'] ?? 302);
        }

        $filtered['method'] = strtoupper(trim((string)$filtered['method']));
        $filtered['uri'] = trim((string)$filtered['uri']) !== '' ? (string)$filtered['uri'] : '/';
        $redirect = $filtered['redirect'] ?? null;
        $filtered['redirect'] = is_string($redirect) && trim($redirect) !== '' ? trim($redirect) : null;
        $filtered['redirect_status'] = max(300, min(399, (int)$filtered['redirect_status']));

        kernel_request_context_set('_request_dispatch_context', $filtered);
        return $filtered;
    }

    function kernelRequestDispatchPath(array $context): string
    {
        $uri = trim((string)($context['uri'] ?? '/'));
        if ($uri === '') {
            return '/';
        }

        $path = parse_url($uri, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return $uri;
        }

        return $path;
    }

    function kernelRequestDispatchRedirect(array $context, string $target, int $status = 302): array
    {
        $context['handled'] = true;
        $context['redirect'] = $target;
        $context['redirect_status'] = $status;
        return $context;
    }

    function kernelResolveStorePortalHomeRedirect(?array $user = null): ?string
    {
        if (!is_array($user)) {
            return null;
        }

        if (trim((string)($user['source'] ?? '')) !== 'cms') {
            return null;
        }

        $role = trim((string)($user['role'] ?? ''));
        if ($role !== '' && function_exists('cmsRoleAtLeast') && cmsRoleAtLeast($role, 'administrator')) {
            return null;
        }

        $userId = (int)($user['id'] ?? 0);
        if ($userId <= 0 || !function_exists('app')) {
            return null;
        }

        try {
            $db = null;
            if (function_exists('ecDb')) {
                $db = ecDb();
            }

            if (!$db instanceof PDO) {
                $tenantId = app()->tenant()->current();
                if ($tenantId === null || $tenantId <= 0) {
                    $tenantId = app()->tenant()->resolve($user);
                }
                if ($tenantId !== null && $tenantId > 0) {
                    $db = app()->dbForTenant((int)$tenantId);
                }
            }

            if (!$db instanceof PDO) {
                $db = app()->db();
            }

            $rows = $db->prepare(
                'SELECT su.store_id
                 FROM ec_store_users su
                 JOIN ec_stores s ON s.id = su.store_id
                 WHERE su.user_id = ?
                 ORDER BY FIELD(su.role, "owner", "manager", "supervisor"), s.name ASC
                 LIMIT 2'
            );
            $rows->execute([$userId]);
            $matches = $rows->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return null;
        }

        if ($matches === []) {
            return null;
        }

        if (count($matches) === 1) {
            return '/ecommerce/store-admin/' . (int)($matches[0]['store_id'] ?? 0);
        }

        return '/ecommerce/my-stores';
    }

    function kernelResolveAuthenticatedHomeRedirect(?array $user = null, bool $fallbackToRoot = false): ?string
    {
        if ($user === null && function_exists('app')) {
            $resolved = app()->user();
            $user = is_array($resolved) ? $resolved : null;
        }

        if (!is_array($user)) {
            return $fallbackToRoot ? '/' : null;
        }

        $role = trim((string)($user['role'] ?? ''));
        $source = trim((string)($user['source'] ?? ''));
        if ($role === 'superadmin' && $source === 'kernel') {
            return '/superadmin/settings';
        }

        $serviceContext = kernelResolveAuthenticatedServiceContext($user);
        if (is_array($serviceContext)) {
            $serviceUrl = trim((string)($serviceContext['url'] ?? ''));
            if ($serviceUrl !== '') {
                return $serviceUrl;
            }
        }

        $storePortalHome = kernelResolveStorePortalHomeRedirect($user);
        if (is_string($storePortalHome) && $storePortalHome !== '') {
            return $storePortalHome;
        }

        if (function_exists('app')) {
            $homeUrl = app()->hooks()->filter('kernel.home_url', null, $role, $user);
            if (is_string($homeUrl) && trim($homeUrl) !== '') {
                return trim($homeUrl);
            }
        }

        return $fallbackToRoot ? '/' : null;
    }

    function kernelResolveAuthenticatedServiceContext(?array $user = null): ?array
    {
        if ($user === null && function_exists('app')) {
            $resolved = app()->user();
            $user = is_array($resolved) ? $resolved : null;
        }

        if (!is_array($user)) {
            return null;
        }

        if (!function_exists('app')) {
            return null;
        }

        $resolved = app()->hooks()->filterNullable('kernel.user_service_context', null, $user);
        if (!is_array($resolved)) {
            return null;
        }

        $service = trim((string)($resolved['service'] ?? ''));
        $url = trim((string)($resolved['url'] ?? ''));
        if ($service === '' || $url === '') {
            return null;
        }

        return [
            'service' => $service,
            'url' => $url,
            'label' => trim((string)($resolved['label'] ?? '')),
            'source' => trim((string)($resolved['source'] ?? '')),
        ];
    }

    function kernelRegisterCoreRequestDispatchHooks(): void
    {
        static $registered = false;

        if ($registered || !function_exists('app')) {
            return;
        }
        $registered = true;

        app()->hooks()->on('kernel.request.before_dispatch', static function (array $context): array {
            $method = strtoupper((string)($context['method'] ?? 'GET'));
            if (!in_array($method, ['GET', 'HEAD'], true)) {
                return $context;
            }

            $path = kernelRequestDispatchPath($context);
            $user = is_array($context['user'] ?? null) ? $context['user'] : app()->user();

            if ($path === '/login') {
                $target = kernelResolveAuthenticatedHomeRedirect(is_array($user) ? $user : null, true);
                if ($target !== null && is_array($user)) {
                    return kernelRequestDispatchRedirect($context, $target);
                }
                return $context;
            }

            if ($path === '/' && !is_array($user)) {
                return kernelRequestDispatchRedirect($context, '/login');
            }

            if ($path === '/' && is_array($user)) {
                $target = kernelResolveAuthenticatedHomeRedirect($user, false);
                if ($target !== null) {
                    return kernelRequestDispatchRedirect($context, $target);
                }
            }

            return $context;
        }, -1000);
    }

function kernelLoginRateLimitMaxAttempts(): int
{
    $configured = null;
    if (function_exists('config')) {
        $configured = config('auth.login_rate_limit_max', null);
    }

    $raw = $_ENV['AUTH_LOGIN_RATE_LIMIT_MAX'] ?? $configured ?? 5;
    return max(1, (int)$raw);
}

function kernelLoginRateLimitWindowSeconds(): int
{
    $configured = null;
    if (function_exists('config')) {
        $configured = config('auth.login_rate_limit_window', null);
    }

    $raw = $_ENV['AUTH_LOGIN_RATE_LIMIT_WINDOW'] ?? $configured ?? 300;
    return max(1, (int)$raw);
}

function kernelLoginRateLimitIdentifier(?string $moduleId = null, ?string $ip = null): string
{
    $identifierParts = [];

    try {
        $tenantId = function_exists('app') ? app()->tenant()->current() : null;
    } catch (Throwable $ignored) {
        $tenantId = null;
    }

    if ($tenantId !== null) {
        $identifierParts[] = 't' . $tenantId;
    }

    $moduleId = trim((string)$moduleId);
    if ($moduleId !== '') {
        $identifierParts[] = 'module:' . $moduleId;
    }

    $resolvedIp = trim((string)($ip ?? ($_SERVER['REMOTE_ADDR'] ?? 'unknown')));
    $identifierParts[] = 'ip:' . ($resolvedIp !== '' ? $resolvedIp : 'unknown');

    return implode(':', $identifierParts);
}

/**
 * @return array<string, mixed>
 */
function kernelConsumeLoginRateLimit(?string $moduleId = null, ?int $maxAttempts = null, ?int $windowSeconds = null): array
{
    $maxAttempts = max(1, (int)($maxAttempts ?? kernelLoginRateLimitMaxAttempts()));
    $windowSeconds = max(1, (int)($windowSeconds ?? kernelLoginRateLimitWindowSeconds()));
    $identifier = kernelLoginRateLimitIdentifier($moduleId);
    $action = 'login';

    try {
        \Ikabud\Kernel\Database\KernelPDO::kernelEscalationEnter();
        try {
            $db = app()->db();
            $cutoff = date('Y-m-d H:i:s', time() - $windowSeconds);

            $db->prepare(
                'INSERT INTO rate_limits (identifier, action, attempts, window_start)
                 VALUES (:id, :action, 1, CURRENT_TIMESTAMP)
                 ON DUPLICATE KEY UPDATE
                     attempts = IF(window_start >= :cutoff, attempts + 1, 1),
                     window_start = IF(window_start >= :cutoff2, window_start, CURRENT_TIMESTAMP)'
            )->execute([
                ':id' => $identifier,
                ':action' => $action,
                ':cutoff' => $cutoff,
                ':cutoff2' => $cutoff,
            ]);

            $statement = $db->prepare(
                'SELECT attempts, window_start FROM rate_limits WHERE identifier = :id AND action = :action LIMIT 1'
            );
            $statement->execute([':id' => $identifier, ':action' => $action]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
        } finally {
            \Ikabud\Kernel\Database\KernelPDO::kernelEscalationLeave();
        }

        if (is_array($row) && ($row['window_start'] ?? '') >= $cutoff && (int)($row['attempts'] ?? 0) > $maxAttempts) {
            $retryAfter = max(1, $windowSeconds - (time() - strtotime((string)$row['window_start'])));
            write_log('auth.login_rate_limited', 'warning', [
                'identifier' => $identifier,
                'module_id' => trim((string)$moduleId),
                'action' => $action,
                'max_attempts' => $maxAttempts,
                'window_seconds' => $windowSeconds,
                'retry_after' => $retryAfter,
            ]);

            return [
                'limited' => true,
                'retry_after' => $retryAfter,
                'identifier' => $identifier,
                'module_id' => trim((string)$moduleId),
                'action' => $action,
                'max_attempts' => $maxAttempts,
                'window_seconds' => $windowSeconds,
                'enforced' => true,
            ];
        }
    } catch (Throwable $ignored) {
        return [
            'limited' => false,
            'retry_after' => 0,
            'identifier' => $identifier,
            'module_id' => trim((string)$moduleId),
            'action' => $action,
            'max_attempts' => $maxAttempts,
            'window_seconds' => $windowSeconds,
            'enforced' => false,
        ];
    }

    return [
        'limited' => false,
        'retry_after' => 0,
        'identifier' => $identifier,
        'module_id' => trim((string)$moduleId),
        'action' => $action,
        'max_attempts' => $maxAttempts,
        'window_seconds' => $windowSeconds,
        'enforced' => true,
    ];
}

function kernelActiveProductIntegrationMode(bool $refresh = false): string
{
    if (!$refresh) {
        $cached = kernel_request_context_get('_kernel_active_product_integration_mode', null);
        if (is_string($cached)) {
            return $cached;
        }
    }

    try {
        \Ikabud\Kernel\Database\KernelPDO::kernelEscalationEnter();
        try {
            $stmt = app()->db()->prepare(
                "SELECT integration_mode
                 FROM kernel_integrations
                 WHERE is_active = 1
                   AND integration_mode IN ('wms_authoritative_products', 'ecommerce_authoritative_products')
                 ORDER BY updated_at DESC, id DESC
                 LIMIT 1"
            );
            $stmt->execute();
            $mode = trim((string)($stmt->fetchColumn() ?: ''));
        } finally {
            \Ikabud\Kernel\Database\KernelPDO::kernelEscalationLeave();
        }
    } catch (Throwable $e) {
        $mode = '';
    }

    kernel_request_context_set('_kernel_active_product_integration_mode', $mode);

    return $mode;
}

function kernelEmitLoginRateLimitJson(array $rateLimit, string $message = 'Too many login attempts. Try again later.'): void
{
    $retryAfter = max(1, (int)($rateLimit['retry_after'] ?? 1));
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('Retry-After: ' . $retryAfter);
        header('X-Request-Id: ' . request_id());
        http_response_code(429);
    }

    echo json_encode([
        'ok' => false,
        'error' => $message,
        'retry_after' => $retryAfter,
    ]);
}

/**
 * Generic rate limiter for any action. Uses the same rate_limits table as login rate limiting.
 *
 * @param string $action     Action identifier (e.g. 'password_reset', 'coupon_apply')
 * @param int    $maxAttempts Max attempts within the window
 * @param int    $windowSeconds Time window in seconds
 * @param string|null $ip    Override IP (defaults to REMOTE_ADDR)
 * @return array {limited: bool, retry_after: int, enforced: bool}
 */
function kernelRateLimit(string $action, int $maxAttempts = 5, int $windowSeconds = 3600, ?string $ip = null): array
{
    $ip = $ip ?? ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    $tenantId = 0;
    try { $tenantId = (int)(app()->tenantId ?? 0); } catch (\Throwable $e) {}
    $identifier = "t{$tenantId}:{$action}:ip:{$ip}";

    try {
        $db = app()->db();
        $cutoff = date('Y-m-d H:i:s', time() - $windowSeconds);

        $db->prepare(
            'INSERT INTO rate_limits (identifier, action, attempts, window_start)
             VALUES (:id, :action, 1, CURRENT_TIMESTAMP)
             ON DUPLICATE KEY UPDATE
                 attempts = IF(window_start >= :cutoff, attempts + 1, 1),
                 window_start = IF(window_start >= :cutoff2, window_start, CURRENT_TIMESTAMP)'
        )->execute([
            ':id' => $identifier,
            ':action' => $action,
            ':cutoff' => $cutoff,
            ':cutoff2' => $cutoff,
        ]);

        $stmt = $db->prepare('SELECT attempts, window_start FROM rate_limits WHERE identifier = :id AND action = :action LIMIT 1');
        $stmt->execute([':id' => $identifier, ':action' => $action]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (is_array($row) && ($row['window_start'] ?? '') >= $cutoff && (int)($row['attempts'] ?? 0) > $maxAttempts) {
            $retryAfter = max(1, $windowSeconds - (time() - strtotime((string)$row['window_start'])));
            write_log("rate_limit.{$action}", 'warning', [
                'identifier' => $identifier,
                'action' => $action,
                'max_attempts' => $maxAttempts,
                'window_seconds' => $windowSeconds,
                'retry_after' => $retryAfter,
            ]);
            return ['limited' => true, 'retry_after' => $retryAfter, 'enforced' => true];
        }
    } catch (\Throwable $e) {
        return ['limited' => false, 'retry_after' => 0, 'enforced' => false];
    }

    return ['limited' => false, 'retry_after' => 0, 'enforced' => true];
}

/**
 * Emit a 429 JSON response for rate-limited requests.
 */
function kernelEmitRateLimitJson(array $rateLimit, string $message = 'Too many requests. Try again later.'): void
{
    $retryAfter = max(1, (int)($rateLimit['retry_after'] ?? 1));
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('Retry-After: ' . $retryAfter);
        header('X-Request-Id: ' . request_id());
        http_response_code(429);
    }
    echo json_encode(['ok' => false, 'error' => $message, 'retry_after' => $retryAfter]);
}

// ── Job Queue Infrastructure ─────────────────────────────────────────

/**
 * Dispatch a job to the kernel job queue.
 *
 * @param string $handler  Callable reference: 'functionName' or 'module:functionName'
 * @param array  $payload  JSON-serializable data passed to the handler
 * @param string $queue    Queue name (default: 'default')
 * @param int    $delaySeconds  Delay before job becomes available
 * @param int    $maxAttempts   Max retry attempts
 * @return int  Inserted job ID (0 on failure)
 */
function kernelDispatchJob(string $handler, array $payload = [], string $queue = 'default', int $delaySeconds = 0, int $maxAttempts = 3): int
{
    try {
        \Ikabud\Kernel\Database\KernelPDO::kernelEscalationEnter();
        $db = app()->db();
        $availableAt = $delaySeconds > 0
            ? date('Y-m-d H:i:s', time() + $delaySeconds)
            : date('Y-m-d H:i:s');

        $stmt = $db->prepare(
            'INSERT INTO kernel_jobs (queue, handler, payload_json, max_attempts, available_at, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())'
        );
        $stmt->execute([
            $queue,
            $handler,
            json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            max(1, $maxAttempts),
            $availableAt,
        ]);

        return (int)$db->lastInsertId();
    } catch (\Throwable $e) {
        write_log('kernelDispatchJob failed: ' . $e->getMessage(), 'error', [
            'handler' => $handler,
            'queue' => $queue,
        ]);
        return 0;
    } finally {
        \Ikabud\Kernel\Database\KernelPDO::kernelEscalationLeave();
    }
}

/**
 * Claim and process the next available job from a queue.
 * Uses SELECT…FOR UPDATE SKIP LOCKED for safe concurrent worker support.
 *
 * @return array|null  Processed job row or null if queue is empty
 */
function kernelProcessNextJob(string $queue = 'default', int $lockTimeoutSeconds = 300): ?array
{
    try {
        $db = app()->db();
        $now = date('Y-m-d H:i:s');

        // Claim a job atomically
        $db->beginTransaction();

        $stmt = $db->prepare(
            'SELECT id, handler, payload_json, attempts, max_attempts
               FROM kernel_jobs
              WHERE queue = ?
                AND available_at <= ?
                AND reserved_at IS NULL
                AND failed_at IS NULL
              ORDER BY available_at ASC, id ASC
              LIMIT 1
              FOR UPDATE SKIP LOCKED'
        );
        $stmt->execute([$queue, $now]);
        $job = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$job) {
            $db->commit();
            return null;
        }

        $jobId = (int)$job['id'];
        $upd = $db->prepare('UPDATE kernel_jobs SET reserved_at = ?, attempts = attempts + 1 WHERE id = ?');
        $upd->execute([$now, $jobId]);
        $db->commit();

        // Execute the handler
        $handler = trim((string)$job['handler']);
        $payload = json_decode((string)$job['payload_json'], true) ?: [];
        $error = null;

        try {
            kernelJobInvokeHandler($handler, $payload);
        } catch (\Throwable $e) {
            $error = substr($e->getMessage(), 0, 4000);
        }

        if ($error === null) {
            // Success — remove from queue
            $del = $db->prepare('DELETE FROM kernel_jobs WHERE id = ?');
            $del->execute([$jobId]);
            return array_merge($job, ['status' => 'completed', 'error' => null]);
        }

        // Failure — check attempts
        $attempts = (int)$job['attempts'] + 1;
        $maxAttempts = (int)$job['max_attempts'];

        if ($attempts >= $maxAttempts) {
            // Move to failed_jobs
            $ins = $db->prepare(
                'INSERT INTO kernel_failed_jobs (queue, handler, payload_json, attempts, failed_at, error)
                 VALUES (?, ?, ?, ?, NOW(), ?)'
            );
            $ins->execute([$queue, $handler, (string)$job['payload_json'], $attempts, $error]);
            $del = $db->prepare('DELETE FROM kernel_jobs WHERE id = ?');
            $del->execute([$jobId]);
            write_log('Job permanently failed after ' . $attempts . ' attempts', 'error', [
                'job_id' => $jobId,
                'handler' => $handler,
                'error' => $error,
            ]);
            return array_merge($job, ['status' => 'failed', 'error' => $error]);
        }

        // Retry with exponential backoff: 30s, 120s, 480s…
        $retryDelay = (int)(30 * pow(4, $attempts - 1));
        $nextAvailable = date('Y-m-d H:i:s', time() + $retryDelay);
        $retry = $db->prepare('UPDATE kernel_jobs SET reserved_at = NULL, available_at = ?, failed_at = NULL, error = ? WHERE id = ?');
        $retry->execute([$nextAvailable, $error, $jobId]);

        write_log('Job failed, retrying in ' . $retryDelay . 's (attempt ' . $attempts . '/' . $maxAttempts . ')', 'warning', [
            'job_id' => $jobId,
            'handler' => $handler,
            'error' => $error,
        ]);

        return array_merge($job, ['status' => 'retrying', 'error' => $error]);
    } catch (\Throwable $e) {
        if (isset($db) && $db->inTransaction()) {
            $db->rollBack();
        }
        write_log('kernelProcessNextJob error: ' . $e->getMessage(), 'error', ['queue' => $queue]);
        return null;
    }
}

/**
 * Invoke a job handler by reference string.
 * Supports: 'functionName' or 'module:functionName'
 */
function kernelJobInvokeHandler(string $handler, array $payload): void
{
    if (str_contains($handler, ':')) {
        [$moduleId, $functionName] = explode(':', $handler, 2);
        // Ensure module helpers are loaded via discoverModules + loadModuleHelpers
        if (function_exists('discoverModules') && function_exists('loadModuleHelpers')) {
            $modules = discoverModules();
            if (isset($modules[$moduleId])) {
                loadModuleHelpers($modules[$moduleId]);
            }
        }
        $handlersPath = BASE_PATH . '/modules/' . $moduleId . '/handlers.php';
        if (is_file($handlersPath)) {
            require_once $handlersPath;
        }
        if (!function_exists($functionName)) {
            throw new \RuntimeException("Job handler function '{$functionName}' not found for module '{$moduleId}'.");
        }
        $functionName($payload);
        return;
    }

    if (!function_exists($handler)) {
        throw new \RuntimeException("Job handler function '{$handler}' not found.");
    }
    $handler($payload);
}

/**
 * Run the queue worker loop (called from CLI).
 * Processes jobs until interrupted or --once flag is set.
 */
function kernelQueueWorker(string $queue = 'default', int $sleepSeconds = 3, bool $once = false): void
{
    write_log('Queue worker started', 'info', ['queue' => $queue, 'pid' => getmypid()]);

    while (true) {
        $result = kernelProcessNextJob($queue);

        if ($result !== null) {
            $status = $result['status'] ?? 'unknown';
            $handler = $result['handler'] ?? 'unknown';
            if (PHP_SAPI === 'cli') {
                echo date('[H:i:s]') . " [{$status}] {$handler}\n";
            }

            // Immediately try the next job
            continue;
        }

        if ($once) {
            break;
        }

        // No jobs available — sleep before polling again
        sleep($sleepSeconds);
    }
}

/**
 * Get queue statistics.
 */
function kernelJobQueueStats(string $queue = 'default'): array
{
    try {
        \Ikabud\Kernel\Database\KernelPDO::kernelEscalationEnter();
        $db = app()->db();
        $now = date('Y-m-d H:i:s');

        $s1 = $db->prepare('SELECT COUNT(*) FROM kernel_jobs WHERE queue = ? AND reserved_at IS NULL AND failed_at IS NULL AND available_at <= ?');
        $s1->execute([$queue, $now]);
        $pending = (int)$s1->fetchColumn();

        $s2 = $db->prepare('SELECT COUNT(*) FROM kernel_jobs WHERE queue = ? AND reserved_at IS NULL AND failed_at IS NULL AND available_at > ?');
        $s2->execute([$queue, $now]);
        $delayed = (int)$s2->fetchColumn();

        $s3 = $db->prepare('SELECT COUNT(*) FROM kernel_jobs WHERE queue = ? AND reserved_at IS NOT NULL');
        $s3->execute([$queue]);
        $reserved = (int)$s3->fetchColumn();

        $s4 = $db->prepare('SELECT COUNT(*) FROM kernel_failed_jobs WHERE queue = ?');
        $s4->execute([$queue]);
        $failed = (int)$s4->fetchColumn();

        return [
            'queue' => $queue,
            'pending' => $pending,
            'delayed' => $delayed,
            'reserved' => $reserved,
            'failed' => $failed,
        ];
    } catch (\Throwable $e) {
        return ['queue' => $queue, 'pending' => 0, 'delayed' => 0, 'reserved' => 0, 'failed' => 0, 'error' => $e->getMessage()];
    } finally {
        \Ikabud\Kernel\Database\KernelPDO::kernelEscalationLeave();
    }
}

// ── Schedule Infrastructure ──────────────────────────────────────────

/**
 * Check if a schedule frequency is due at the current minute.
 * Designed to be called once per minute via `* * * * * php ikabud schedule:run`.
 *
 * Supported frequencies: every_minute, every_5_minutes, every_15_minutes,
 * every_30_minutes, hourly, daily, weekly.
 */
function kernelScheduleIsDue(string $frequency): bool
{
    $minute = (int)date('i');
    $hour = (int)date('G');
    $dow = (int)date('w'); // 0 = Sunday

    return match ($frequency) {
        'every_minute' => true,
        'every_5_minutes' => $minute % 5 === 0,
        'every_15_minutes' => $minute % 15 === 0,
        'every_30_minutes' => $minute % 30 === 0,
        'hourly' => $minute === 0,
        'daily' => $hour === 0 && $minute === 0,
        'weekly' => $dow === 0 && $hour === 0 && $minute === 0,
        default => false,
    };
}

function release_session_lock_if_active(): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return false;
    }

    session_write_close();
    return true;
}

function finish_response_if_possible(): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }

    if (function_exists('fastcgi_finish_request')) {
        @fastcgi_finish_request();
        return;
    }

    // Apache mod_php fallback: tell the client the response is complete so it
    // disconnects immediately, even though the PHP process continues running.
    ignore_user_abort(true);

    // Collect any buffered output and send it with proper Content-Length +
    // Connection: close so the browser/client releases the request.
    $output = '';
    while (ob_get_level() > 0) {
        $output .= ob_get_clean();
    }

    if (!headers_sent()) {
        header('Connection: close');
        header('Content-Encoding: none');
        header('Content-Length: ' . strlen($output));
    }

    echo $output;
    @flush();
}

function timing_logs_enabled(string $envKey = 'APP_TIMING_LOGS'): bool
{
    $value = $_ENV[$envKey] ?? null;
    if ($value === null || $value === '') {
        return false;
    }

    return filter_var($value, FILTER_VALIDATE_BOOLEAN);
}

function timing_logs_threshold_ms(string $envKey = 'APP_TIMING_THRESHOLD_MS', int $default = 0): int
{
    $raw = $_ENV[$envKey] ?? null;
    if ($raw === null || $raw === '') {
        return $default;
    }

    return max(0, (int)$raw);
}

function log_timing(string $message, float $startTime, array $context = [], string $enableEnvKey = 'APP_TIMING_LOGS', string $thresholdEnvKey = 'APP_TIMING_THRESHOLD_MS'): ?float
{
    if (!timing_logs_enabled($enableEnvKey)) {
        return null;
    }

    $durationMs = round((microtime(true) - $startTime) * 1000, 2);
    $thresholdMs = timing_logs_threshold_ms($thresholdEnvKey, 0);
    if ($durationMs < $thresholdMs) {
        return $durationMs;
    }

    $context['duration_ms'] = $durationMs;
    write_log($message, 'info', $context);
    return $durationMs;
}

function dbConnectionLost(Throwable $e): bool
{
    $message = strtolower(trim($e->getMessage()));
    if ($message === '') {
        return false;
    }

    if (
        str_contains($message, 'server has gone away')
        || str_contains($message, 'lost connection to mysql server')
        || str_contains($message, 'error while sending')
        || str_contains($message, 'packets out of order')
        || str_contains($message, 'no connection to the server')
        || str_contains($message, 'is dead or not enabled')
        || str_contains($message, 'sqlstate[hy000]: general error: 2006')
        || str_contains($message, 'sqlstate[hy000]: general error: 2013')
    ) {
        return true;
    }

    return false;
}

function kernelFireShutdownHooks(): void
{
    static $fired = false;

    if ($fired) {
        return;
    }
    $fired = true;

    if (!function_exists('app')) {
        return;
    }

    try {
        app()->hooks()->action('kernel.shutdown', app());
    } catch (Throwable $e) {
        if (function_exists('write_log')) {
            write_log('kernel.shutdown hook failed: ' . $e->getMessage(), 'error', [
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        }
    }
}

set_exception_handler(function (Throwable $e): void {
    write_log($e->getMessage(), 'critical', [
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString(),
    ]);
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }
    // Tier 1: attempt to render the styled 500 page via DiSyL. Guard carefully —
    // the exception may have occurred during bootstrapping so app() or the template
    // engine may itself be unavailable.
    if (function_exists('app') && function_exists('external_base_url')) {
        try {
            $html500 = app()->templates()->render('pages/500', [
                'base_url' => external_base_url(),
            ]);
            echo $html500;
            exit;
        } catch (Throwable $renderEx) {
            // Tier 1 failed — fall through to bare HTML. Log the render failure
            // only as a warning so the original critical error is not obscured.
            write_log('500 page render failed: ' . $renderEx->getMessage(), 'warning', [
                'file' => $renderEx->getFile(),
                'line' => $renderEx->getLine(),
            ]);
        }
    }
    // Tier 2: bare HTML fallback — never leaks stack traces or internal paths
    echo '<!DOCTYPE html><html><head><title>Error</title></head><body>'
       . '<h1>Application Error</h1><p>An unexpected error occurred. Please try again later.</p>'
       . '</body></html>';
    exit;
});

spl_autoload_register(static function (string $class): void {
    $kernelPrefix = 'Ikabud\\Kernel\\';
    if (strncmp($class, $kernelPrefix, strlen($kernelPrefix)) !== 0) {
        return;
    }

    $relative = substr($class, strlen($kernelPrefix));
    $path = KERNEL_PATH . '/' . str_replace('\\', '/', $relative) . '.php';
    if (file_exists($path)) {
        require_once $path;
    }
});

require_once KERNEL_PATH . '/EventTriggers.php';

function config(string $key, mixed $default = null): mixed
{
    global $config;

    $value = $config;
    foreach (explode('.', $key) as $segment) {
        if (!is_array($value) || !array_key_exists($segment, $value)) {
            return $default;
        }
        $value = $value[$segment];
    }

    return $value;
}

if (!defined('BASE_URL')) {
    define('BASE_URL', external_base_url());
}

function kernelReadJsonFile(string $path, array $default = []): array
{
    if ($path === '' || !is_file($path)) {
        return $default;
    }

    $raw = @file_get_contents($path);
    if (!is_string($raw) || $raw === '') {
        return $default;
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : $default;
}

function kernelEnsureDirectory(string $path, int $mode = 0775): bool
{
    if ($path === '') {
        return false;
    }

    if (is_dir($path)) {
        return true;
    }

    if (@mkdir($path, $mode, true)) {
        return true;
    }

    return is_dir($path);
}

function kernelDeletePath(string $path): bool
{
    if ($path === '') {
        return false;
    }

    if (is_link($path) || is_file($path)) {
        return @unlink($path);
    }

    if (is_dir($path)) {
        return @rmdir($path);
    }

    return false;
}

function kernelCopyFile(string $source, string $destination): bool
{
    if ($source === '' || $destination === '') {
        return false;
    }

    return @copy($source, $destination);
}

function kernelWriteFile(string $path, string $contents): bool
{
    if ($path === '') {
        return false;
    }

    return @file_put_contents($path, $contents, LOCK_EX) !== false;
}

/**
 * @return array<string, array<string, mixed>>
 */
function &kernelRenderContextProfileRegistry(): array
{
    static $profiles = [];
    return $profiles;
}

/**
 * @return array<string, array<string, mixed>>
 */
function kernelRegisteredRenderContextProfiles(): array
{
    return kernelRenderContextProfileRegistry();
}

/**
 * Register a render-context profile.
 *
 * Definition keys:
 * - shell_schema_stack?: string[]
 * - status?: string
 */
function kernelRegisterRenderContextProfile(string $profileId, array $definition = []): void
{
    $profileId = trim($profileId);
    if ($profileId === '') {
        throw new InvalidArgumentException('Render context profile id must not be empty.');
    }

    $shellSchemaStack = [];
    $rawShellSchemaStack = $definition['shell_schema_stack'] ?? [];
    if (is_array($rawShellSchemaStack)) {
        foreach ($rawShellSchemaStack as $schemaId) {
            $schemaId = trim((string)$schemaId);
            if ($schemaId !== '') {
                $shellSchemaStack[] = $schemaId;
            }
        }
    }

    $registry = &kernelRenderContextProfileRegistry();
    $registry[$profileId] = [
        'id' => $profileId,
        'shell_schema_stack' => array_values(array_unique($shellSchemaStack)),
        'status' => trim((string)($definition['status'] ?? 'active')) ?: 'active',
    ];
}

function kernelRenderContextProfileDefinition(string $profileId): ?array
{
    $profileId = trim($profileId);
    if ($profileId === '') {
        return null;
    }

    $registry = kernelRenderContextProfileRegistry();
    $definition = $registry[$profileId] ?? null;
    return is_array($definition) ? $definition : null;
}

kernelRegisterRenderContextProfile('cms_public', [
    'shell_schema_stack' => ['kernel.shell@1'],
]);

kernelRegisterRenderContextProfile('commerce_public', [
    'shell_schema_stack' => ['kernel.shell@1'],
]);

kernelRegisterRenderContextProfile('admin', [
    'status' => 'reserved',
]);

kernelRegisterRenderContextProfile('shell_only', [
    'status' => 'reserved',
]);

kernelRegisterRenderContextProfile('guidance_public', [
    'status' => 'reserved',
]);

/**
 * @return array<string, array<string, mixed>>
 */
function &kernelRenderContextContractRegistry(): array
{
    static $contracts = [];
    return $contracts;
}

/**
 * @return array<string, array<string, mixed>>
 */
function kernelRegisteredRenderContextContracts(): array
{
    return kernelRenderContextContractRegistry();
}

/**
 * Register a render-context contract for one or more templates.
 *
 * Definition keys:
 * - template?: string
 * - templates?: string[]
 * - prefix?: string
 * - prefixes?: string[]
 * - priority?: int (lower runs first)
 * - defaults?: array<string, mixed>
 * - required?: string[]
 * - normalize?: callable(array $context, string $template, array &$missingKeys, array &$typeMismatches): array
 * - schema_id?: string
 * - schema_version?: int
 * - profile_hint?: string
 * - log_event?: string
 */
function kernelRegisterRenderContextContract(string $contractId, array $definition): void
{
    $contractId = trim($contractId);
    if ($contractId === '') {
        throw new InvalidArgumentException('Render context contract id must not be empty.');
    }

    // Validate contract ID format: alphanumeric, dots, hyphens, underscores, colons, @ signs only.
    // Examples of valid IDs: 'cms.public.entity.view@1', 'ecommerce:shell', 'kernel.render.context@1'
    if (!preg_match('/^[a-zA-Z0-9._:@-]+$/', $contractId)) {
        throw new InvalidArgumentException("Render context contract id contains invalid characters: '{$contractId}'. Allowed: alphanumeric, dot, hyphen, underscore, colon, @.");
    }

    $templates = [];
    $template = trim((string)($definition['template'] ?? ''));
    if ($template !== '') {
        $templates[] = $template;
    }

    $rawTemplates = $definition['templates'] ?? [];
    if (is_array($rawTemplates)) {
        foreach ($rawTemplates as $candidate) {
            $candidate = trim((string)$candidate);
            if ($candidate !== '') {
                $templates[] = $candidate;
            }
        }
    }

    $prefixes = [];
    $prefix = trim((string)($definition['prefix'] ?? ''));
    if ($prefix !== '') {
        $prefixes[] = $prefix;
    }

    $rawPrefixes = $definition['prefixes'] ?? [];
    if (is_array($rawPrefixes)) {
        foreach ($rawPrefixes as $candidate) {
            $candidate = trim((string)$candidate);
            if ($candidate !== '') {
                $prefixes[] = $candidate;
            }
        }
    }

    $templates = array_values(array_unique($templates));
    $prefixes = array_values(array_unique($prefixes));
    if ($templates === [] && $prefixes === []) {
        throw new InvalidArgumentException('Render context contracts require at least one template or prefix matcher.');
    }

    $defaults = $definition['defaults'] ?? [];
    if (!is_array($defaults)) {
        $defaults = [];
    }

    $required = [];
    $rawRequired = $definition['required'] ?? [];
    if (is_array($rawRequired)) {
        foreach ($rawRequired as $key) {
            $key = trim((string)$key);
            if ($key !== '') {
                $required[] = $key;
            }
        }
    }

    $normalize = $definition['normalize'] ?? null;
    if ($normalize !== null && !is_callable($normalize)) {
        throw new InvalidArgumentException('Render context contract normalizer must be callable when provided.');
    }

    $schemaId = trim((string)($definition['schema_id'] ?? ''));
    $schemaVersion = isset($definition['schema_version']) ? (int)$definition['schema_version'] : 0;
    if ($schemaVersion <= 0 && $schemaId !== '' && preg_match('/@(\d+)$/', $schemaId, $matches) === 1) {
        $schemaVersion = (int)($matches[1] ?? 0);
    }

    $profileHint = trim((string)($definition['profile_hint'] ?? ''));
    if ($profileHint !== '' && kernelRenderContextProfileDefinition($profileHint) === null) {
        throw new InvalidArgumentException('Render context contract profile hint must reference a registered render context profile.');
    }

    $registry = &kernelRenderContextContractRegistry();
    $registry[$contractId] = [
        'id' => $contractId,
        'priority' => isset($definition['priority']) ? (int)$definition['priority'] : 100,
        'templates' => $templates,
        'prefixes' => $prefixes,
        'defaults' => $defaults,
        'required' => array_values(array_unique($required)),
        'normalize' => $normalize,
        'schema_id' => $schemaId,
        'schema_version' => $schemaVersion,
        'profile_hint' => $profileHint,
        'log_event' => trim((string)($definition['log_event'] ?? 'kernel.render_context.contract_mismatch')) ?: 'kernel.render_context.contract_mismatch',
    ];
}

function kernelRenderContextContractMatches(array $contract, string $template): bool
{
    foreach (($contract['templates'] ?? []) as $candidate) {
        if ($candidate === $template) {
            return true;
        }
    }

    foreach (($contract['prefixes'] ?? []) as $prefix) {
        if ($prefix !== '' && str_starts_with($template, $prefix)) {
            return true;
        }
    }

    return false;
}

/**
 * @return array<int, array<string, mixed>>
 */
function kernelMatchedRenderContextContracts(string $template): array
{
    $matched = [];
    foreach (kernelRenderContextContractRegistry() as $contract) {
        if (kernelRenderContextContractMatches($contract, $template)) {
            $matched[] = $contract;
        }
    }

    usort($matched, static function (array $left, array $right): int {
        $priorityCompare = ((int)($left['priority'] ?? 100)) <=> ((int)($right['priority'] ?? 100));
        if ($priorityCompare !== 0) {
            return $priorityCompare;
        }

        return strcmp((string)($left['id'] ?? ''), (string)($right['id'] ?? ''));
    });

    return $matched;
}

/**
 * @return string[]
 */
function kernelRenderContextProfileShellSchemaStack(string $profileId): array
{
    $definition = kernelRenderContextProfileDefinition($profileId);
    if ($definition === null) {
        return [];
    }

    $shellSchemaStack = $definition['shell_schema_stack'] ?? [];
    if (!is_array($shellSchemaStack)) {
        return [];
    }

    return array_values(array_filter(array_map(static fn(mixed $schemaId): string => trim((string)$schemaId), $shellSchemaStack), static fn(string $schemaId): bool => $schemaId !== ''));
}

function kernelResolveRenderContextProfileId(string $template, array $context = [], ?array $matchedContracts = null): string
{
    $contracts = is_array($matchedContracts) ? $matchedContracts : kernelMatchedRenderContextContracts($template);
    $profileHints = [];

    foreach ($contracts as $contract) {
        $profileHint = trim((string)($contract['profile_hint'] ?? ''));
        if ($profileHint !== '' && kernelRenderContextProfileDefinition($profileHint) !== null) {
            $profileHints[$profileHint] = true;
        }
    }

    if (count($profileHints) === 1) {
        $keys = array_keys($profileHints);
        return (string)($keys[0] ?? '');
    }

    return '';
}

/**
 * @return string[]
 */
function kernelResolveRenderContextSchemaStack(string $template, array $context = [], ?array $matchedContracts = null, ?string $profileId = null): array
{
    $contracts = is_array($matchedContracts) ? $matchedContracts : kernelMatchedRenderContextContracts($template);
    $profileId = $profileId ?? kernelResolveRenderContextProfileId($template, $context, $contracts);
    $stack = [];

    foreach (kernelRenderContextProfileShellSchemaStack($profileId) as $schemaId) {
        $schemaId = trim((string)$schemaId);
        if ($schemaId !== '') {
            $stack[$schemaId] = true;
        }
    }

    foreach ($contracts as $contract) {
        $schemaId = trim((string)($contract['schema_id'] ?? ''));
        if ($schemaId !== '') {
            $stack[$schemaId] = true;
        }
    }

    return array_keys($stack);
}

function kernelApplyResolvedRenderContextMetadata(array $context, string $profileId, array $schemaStack): array
{
    $context['render_profile_id'] = trim($profileId);
    $context['render_schema_stack'] = array_values(array_filter(array_map(static fn(mixed $schemaId): string => trim((string)$schemaId), $schemaStack), static fn(string $schemaId): bool => $schemaId !== ''));
    return $context;
}

/**
 * @return array<int, array<string, mixed>>
 */
function &kernelRenderTraceBuffer(): array
{
    static $traces = [];
    return $traces;
}

/**
 * @return array<int, array<string, mixed>>
 */
function kernelRecordedRenderTraces(): array
{
    return kernelRenderTraceBuffer();
}

function kernelLatestRenderTrace(): ?array
{
    $traces = kernelRenderTraceBuffer();
    if ($traces === []) {
        return null;
    }

    $trace = end($traces);
    return is_array($trace) ? $trace : null;
}

function kernelClearRenderTraces(): void
{
    $traces = &kernelRenderTraceBuffer();
    $traces = [];
}

function kernelRenderTraceLogsEnabled(): bool
{
    return timing_logs_enabled('APP_RENDER_TRACE_LOGS');
}

function kernelRenderTraceOutputMode(): string
{
    $raw = strtolower(trim((string)($_ENV['APP_RENDER_TRACE_OUTPUT'] ?? '')));
    if ($raw === '') {
        return '';
    }

    if (in_array($raw, ['1', 'true', 'yes', 'on'], true)) {
        return 'comment';
    }

    return in_array($raw, ['comment', 'header'], true) ? $raw : '';
}

function kernelRenderTraceOutputEnabled(): bool
{
    return kernelRenderTraceOutputMode() !== '';
}

function kernelRenderTraceCaptureEnabled(): bool
{
    return kernelRenderTraceLogsEnabled() || kernelRenderTraceOutputEnabled();
}

function kernelRenderTraceThemeSource(array $context): string
{
    $themeSource = trim((string)($context['active_theme_slug'] ?? ''));
    if ($themeSource !== '') {
        return $themeSource;
    }

    return trim((string)($context['theme_source'] ?? ''));
}

/**
 * @return array<int, array<string, mixed>>
 */
function kernelRenderTraceNormalizationActions(array $context): array
{
    $actions = $context['__render_trace_normalization_actions'] ?? [];
    if (!is_array($actions)) {
        return [];
    }

    return array_values(array_filter($actions, static fn(mixed $entry): bool => is_array($entry)));
}

function kernelAppendRenderTraceNormalizationAction(array $context, array $action): array
{
    if (!isset($context['__render_trace_normalization_actions']) || !is_array($context['__render_trace_normalization_actions'])) {
        $context['__render_trace_normalization_actions'] = [];
    }

    $context['__render_trace_normalization_actions'][] = $action;
    return $context;
}

function kernelResolveRenderContextContractTemplate(string $template, array $context = []): string
{
    foreach (['__render_contract_template', 'logical_contract_template', '__render_trace_contract_template'] as $key) {
        $contractTemplate = trim((string)($context[$key] ?? ''));
        if ($contractTemplate !== '') {
            return $contractTemplate;
        }
    }

    return $template;
}

function kernelRenderTraceContractTemplate(string $template, array $context): string
{
    return kernelResolveRenderContextContractTemplate($template, $context);
}

function kernelStripInternalRenderTraceContext(array $context): array
{
    unset($context['__render_trace_normalization_actions']);
    unset($context['__render_contract_template']);
    unset($context['logical_contract_template']);
    unset($context['__render_trace_contract_template']);
    return $context;
}

/**
 * @param array<int, array<string, mixed>> $matchedContracts
 * @param array<int, array<string, mixed>> $normalizationActions
 * @return array<string, mixed>
 */
function kernelBuildRenderTrace(string $template, string $contractTemplate, array $context, array $matchedContracts, array $normalizationActions, float $startedAt): array
{
    $matchedContractIds = [];
    $matchedSchemaIds = [];

    foreach ($matchedContracts as $contract) {
        $contractId = trim((string)($contract['id'] ?? ''));
        if ($contractId !== '') {
            $matchedContractIds[] = $contractId;
        }

        $schemaId = trim((string)($contract['schema_id'] ?? ''));
        if ($schemaId !== '') {
            $matchedSchemaIds[] = $schemaId;
        }
    }

    return [
        'request_id' => request_id(),
        'template' => $template,
        'contract_template' => $contractTemplate,
        'render_profile_id' => trim((string)($context['render_profile_id'] ?? '')),
        'render_schema_stack' => is_array($context['render_schema_stack'] ?? null) ? array_values($context['render_schema_stack']) : [],
        'matched_contract_ids' => array_values(array_unique($matchedContractIds)),
        'matched_schema_ids' => array_values(array_unique($matchedSchemaIds)),
        'normalization_actions' => $normalizationActions,
        'strict_mode' => kernelRenderContextContractStrictMode(),
        'public_render_origin' => trim((string)($context['public_render_origin'] ?? '')),
        'public_route_kind' => trim((string)($context['public_route_kind'] ?? '')),
        'public_presentation_mode' => trim((string)($context['public_presentation_mode'] ?? '')),
        'theme_source' => kernelRenderTraceThemeSource($context),
        'duration_ms' => round((microtime(true) - $startedAt) * 1000, 2),
    ];
}

function kernelRecordRenderTrace(array $trace): void
{
    $traces = &kernelRenderTraceBuffer();
    $traces[] = $trace;
    if (count($traces) > 100) {
        array_shift($traces);
    }

    if (kernelRenderTraceLogsEnabled()) {
        write_log('kernel.render_trace', 'info', $trace);
    }
}

function kernelRenderTraceComment(array $trace): string
{
    $summary = [
        'request_id' => (string)($trace['request_id'] ?? ''),
        'template' => (string)($trace['template'] ?? ''),
        'contract_template' => (string)($trace['contract_template'] ?? ''),
        'render_profile_id' => (string)($trace['render_profile_id'] ?? ''),
        'render_schema_stack' => is_array($trace['render_schema_stack'] ?? null) ? array_values($trace['render_schema_stack']) : [],
        'public_route_kind' => (string)($trace['public_route_kind'] ?? ''),
        'theme_source' => (string)($trace['theme_source'] ?? ''),
        'duration_ms' => $trace['duration_ms'] ?? 0,
    ];

    $json = json_encode($summary, JSON_UNESCAPED_SLASHES) ?: '{}';
    $json = str_replace('--', '\\u002d\\u002d', $json);
    return "\n<!-- render-trace {$json} -->";
}

function kernelApplyRenderTraceOutput(string $output, array $trace): string
{
    if (!kernelRenderTraceOutputEnabled()) {
        return $output;
    }

    $mode = kernelRenderTraceOutputMode();
    if ($mode === 'header') {
        if (!headers_sent()) {
            $profileId = trim((string)($trace['render_profile_id'] ?? ''));
            $schemaStack = is_array($trace['render_schema_stack'] ?? null) ? implode(',', $trace['render_schema_stack']) : '';
            if ($profileId !== '') {
                header('X-Render-Profile: ' . $profileId);
            }
            if ($schemaStack !== '') {
                header('X-Render-Schema-Stack: ' . $schemaStack);
            }
        }
        return $output;
    }

    if ($mode !== 'comment') {
        return $output;
    }

    $comment = kernelRenderTraceComment($trace);
    if (stripos($output, '</body>') !== false) {
        $patched = preg_replace('/<\/body>/i', $comment . "\n</body>", $output, 1);
        return is_string($patched) ? $patched : ($output . $comment);
    }

    return $output . $comment;
}

function kernelApplyRenderContextMetadata(array $context, string $template, ?array $matchedContracts = null): array
{
    $contractTemplate = kernelResolveRenderContextContractTemplate($template, $context);
    $contracts = is_array($matchedContracts) ? $matchedContracts : kernelMatchedRenderContextContracts($contractTemplate);
    $profileId = kernelResolveRenderContextProfileId($contractTemplate, $context, $contracts);
    $schemaStack = kernelResolveRenderContextSchemaStack($contractTemplate, $context, $contracts, $profileId);

    if ($profileId === '' && $schemaStack === []) {
        if (!array_key_exists('render_profile_id', $context)) {
            $context['render_profile_id'] = '';
        }
        if (!array_key_exists('render_schema_stack', $context)) {
            $context['render_schema_stack'] = [];
        }
        return $context;
    }

    return kernelApplyResolvedRenderContextMetadata($context, $profileId, $schemaStack);
}

function kernelRenderContextContractStrictMode(): bool
{
    foreach (['DISYL_RENDER_CONTRACT_STRICT', 'KERNEL_RENDER_CONTRACT_STRICT'] as $envKey) {
        $explicit = $_ENV[$envKey] ?? null;
        if (is_string($explicit) && $explicit !== '') {
            return filter_var($explicit, FILTER_VALIDATE_BOOLEAN);
        }
    }

    $ci = $_ENV['CI'] ?? null;
    if (is_string($ci) && $ci !== '') {
        return filter_var($ci, FILTER_VALIDATE_BOOLEAN);
    }

    if (function_exists('config')) {
        return strtolower((string)config('app.env', 'development')) === 'testing';
    }

    return false;
}

function kernelRenderContextContractMismatchMessage(string $template, string $contract, array $missingKeys, array $typeMismatches): string
{
    $parts = ['Render context contract mismatch for ' . $template . ' (' . $contract . ')'];
    if ($missingKeys !== []) {
        $parts[] = 'missing keys: ' . implode(', ', $missingKeys);
    }
    if ($typeMismatches !== []) {
        $pairs = [];
        foreach ($typeMismatches as $key => $type) {
            $pairs[] = $key . '=' . $type;
        }
        $parts[] = 'type mismatches: ' . implode(', ', $pairs);
    }

    return implode('; ', $parts);
}

function kernelApplyRenderContextShape(
    array $context,
    array $defaults,
    array $required = [],
    array &$missingKeys = [],
    array &$typeMismatches = [],
    string $pathPrefix = ''
): array {
    $requiredLookup = array_fill_keys(array_values(array_filter(array_map('strval', $required), static fn(string $key): bool => $key !== '')), true);

    foreach ($defaults as $key => $defaultValue) {
        $key = (string)$key;
        $path = $pathPrefix === '' ? $key : $pathPrefix . $key;

        if (!array_key_exists($key, $context)) {
            $context[$key] = $defaultValue;
            if (isset($requiredLookup[$key])) {
                $missingKeys[] = $path;
            }
            continue;
        }

        $value = $context[$key];
        if (is_array($defaultValue)) {
            if (!is_array($value)) {
                $context[$key] = $defaultValue;
                if (isset($requiredLookup[$key])) {
                    $typeMismatches[$path] = gettype($value);
                }
            }
            continue;
        }

        if (is_bool($defaultValue)) {
            if (!is_bool($value)) {
                if (isset($requiredLookup[$key])) {
                    $typeMismatches[$path] = gettype($value);
                }
                $context[$key] = (bool)$value;
            }
            continue;
        }

        if (is_int($defaultValue)) {
            if (!is_int($value)) {
                if (isset($requiredLookup[$key])) {
                    $typeMismatches[$path] = gettype($value);
                }
                $context[$key] = is_numeric($value) ? (int)$value : $defaultValue;
            }
            continue;
        }

        if (is_float($defaultValue)) {
            if (!is_float($value) && !is_int($value)) {
                if (isset($requiredLookup[$key])) {
                    $typeMismatches[$path] = gettype($value);
                }
                $context[$key] = is_numeric($value) ? (float)$value : $defaultValue;
                continue;
            }

            $context[$key] = (float)$value;
            continue;
        }

        if (!is_scalar($value) && $value !== null) {
            $context[$key] = $defaultValue;
            if (isset($requiredLookup[$key])) {
                $typeMismatches[$path] = gettype($value);
            }
            continue;
        }

        $context[$key] = (string)$value;
    }

    return $context;
}

function kernelNormalizeRenderContextContracts(array $context, string $template, ?array &$mismatches = null): array
{
    $contractTemplate = kernelResolveRenderContextContractTemplate($template, $context);
    $contracts = kernelMatchedRenderContextContracts($contractTemplate);
    if ($contracts === []) {
        return $context;
    }

    $context = kernelApplyRenderContextMetadata($context, $template, $contracts);

    $shouldLog = !empty($context['__render_contract_validate']);
    $collectMismatches = func_num_args() >= 3;
    if ($collectMismatches && !is_array($mismatches)) {
        $mismatches = [];
    }

    foreach ($contracts as $contract) {
        $missingKeys = [];
        $typeMismatches = [];

        $defaults = is_array($contract['defaults'] ?? null) ? $contract['defaults'] : [];
        if ($defaults !== []) {
            $context = kernelApplyRenderContextShape(
                $context,
                $defaults,
                is_array($contract['required'] ?? null) ? $contract['required'] : [],
                $missingKeys,
                $typeMismatches
            );
        }

        $normalize = $contract['normalize'] ?? null;
        if (is_callable($normalize)) {
            $context = $normalize($context, $contractTemplate, $missingKeys, $typeMismatches);
        }

        $missingKeys = array_values(array_unique(array_filter(array_map('strval', $missingKeys), static fn(string $key): bool => $key !== '')));
        if ($typeMismatches !== []) {
            ksort($typeMismatches);
        }

        if ($missingKeys === [] && $typeMismatches === []) {
            continue;
        }

        $context = kernelAppendRenderTraceNormalizationAction($context, [
            'source' => 'kernel_contract',
            'contract' => (string)($contract['id'] ?? ''),
            'schema_id' => trim((string)($contract['schema_id'] ?? '')),
            'missing_keys' => $missingKeys,
            'type_mismatches' => $typeMismatches,
        ]);

        $entry = [
            'template' => $template,
            'contract_template' => $contractTemplate,
            'contract' => (string)($contract['id'] ?? ''),
            'render_profile_id' => trim((string)($context['render_profile_id'] ?? '')),
            'render_schema_stack' => is_array($context['render_schema_stack'] ?? null) ? array_values($context['render_schema_stack']) : [],
            'missing_keys' => $missingKeys,
            'type_mismatches' => $typeMismatches,
        ];

        if ($shouldLog) {
            write_log('warn', (string)($contract['log_event'] ?? 'kernel.render_context.contract_mismatch'), $entry);
        }

        if ($collectMismatches) {
            $mismatches[] = $entry;
        }
    }

    return $context;
}

function kernelPrepareRenderContext(string $template, array $context = []): array
{
    if (trim((string)($context['__render_contract_template'] ?? '')) === '' && trim((string)($context['logical_contract_template'] ?? '')) === '' && trim((string)($context['__render_trace_contract_template'] ?? '')) === '') {
        $context['__render_contract_template'] = $template;
        $context['__render_trace_contract_template'] = $template;
    }

    $context['__render_contract_validate'] = true;
    $mismatches = [];
    $context = kernelNormalizeRenderContextContracts($context, $template, $mismatches);
    unset($context['__render_contract_validate']);

    if (kernelRenderContextContractStrictMode() && $mismatches !== []) {
        $firstMismatch = $mismatches[0];
        throw new RuntimeException(kernelRenderContextContractMismatchMessage(
            $template,
            (string)($firstMismatch['contract'] ?? ''),
            is_array($firstMismatch['missing_keys'] ?? null) ? $firstMismatch['missing_keys'] : [],
            is_array($firstMismatch['type_mismatches'] ?? null) ? $firstMismatch['type_mismatches'] : []
        ));
    }

    return $context;
}

/**
 * Flush PHP OPcache + realpath cache after on-disk code changes
 * (module enable/disable, theme install, deployments, etc.).
 */
function kernelFlushCodeCaches(): void
{
    clearstatcache(true);
    if (function_exists('opcache_reset')) {
        opcache_reset();
    }
}

function kernelUploadedFile(?string $key = null): mixed
{
    $files = $_FILES ?? [];
    if ($key === null) {
        return $files;
    }

    return $files[$key] ?? null;
}

function kernelCookie(?string $key = null, mixed $default = null): mixed
{
    $cookies = $_COOKIE ?? [];
    if ($key === null) {
        return $cookies;
    }

    return $cookies[$key] ?? $default;
}

function app(): App
{
    static $instance = null;
    if ($instance === null) {
        global $config;
        $instance = App::getInstance();
        $instance->boot(array_merge($config, [
            'paths' => [
                'base' => BASE_PATH,
                'templates' => TEMPLATES_PATH,
                'cache' => STORAGE_PATH . '/cache',
                'storage' => STORAGE_PATH,
            ],
        ]));
    }

    return $instance;
}

/**
 * Shorthand for the query builder, scoped to the current tenant (if any).
 * Usage: db()->table('products')->where('id', 1)->first();
 */
function db(): \Ikabud\Kernel\Database\QueryBuilder
{
    static $builder = null;
    if ($builder === null) {
        $tenantId = app()->tenant()->resolve(app()->user());
        $builder = new \Ikabud\Kernel\Database\QueryBuilder(app()->db(), $tenantId);
    }
    return $builder;
}

/**
 * Direct PDO helper for CLI/operator scripts.
 */
function kernelPdo(): PDO
{
    return app()->db();
}

// Backward-compatible CLI shim: some ad-hoc scripts expect $GLOBALS['pdo'].
if (PHP_SAPI === 'cli' && !isset($GLOBALS['pdo'])) {
    try {
        $GLOBALS['pdo'] = kernelPdo();
    } catch (Throwable $e) {
        // Keep bootstrap non-fatal; callers can still use app()->db() directly.
    }
}

return $config;
