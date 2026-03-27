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

function request_id(): string
{
    if (isset($GLOBALS['_request_id']) && is_string($GLOBALS['_request_id']) && $GLOBALS['_request_id'] !== '') {
        return $GLOBALS['_request_id'];
    }

    $incoming = trim((string)($_SERVER['HTTP_X_REQUEST_ID'] ?? ''));
    if ($incoming !== '' && preg_match('/^[A-Za-z0-9\-]{8,128}$/', $incoming)) {
        $GLOBALS['_request_id'] = $incoming;
        $_SERVER['REQUEST_ID'] = $incoming;
        return $incoming;
    }

    try {
        $generated = bin2hex(random_bytes(8));
    } catch (Throwable $e) {
        $generated = uniqid('req_', true);
    }

    $GLOBALS['_request_id'] = $generated;
    $_SERVER['REQUEST_ID'] = $generated;
    return $generated;
}

function is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }

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

    return false;
}

function request_scheme(): string
{
    return is_https() ? 'https' : 'http';
}

function external_base_url(?string $appUrl = null): string
{
    $configured = trim((string)($appUrl ?? config('app.url', '')));
    $fallback = rtrim($configured, '/');
    $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '') {
        return $fallback;
    }

    $basePath = rtrim((string)parse_url($configured, PHP_URL_PATH), '/');
    return rtrim(request_scheme() . '://' . $host . $basePath, '/');
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
    $ctx = $GLOBALS['_capability_call_context'] ?? null;
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

    $line = sprintf(
        "[%s] [%s] %s %s\n",
        date('Y-m-d H:i:s'),
        $level,
        $message,
        $context ? json_encode($context, JSON_UNESCAPED_SLASHES) : ''
    );
    @file_put_contents($logDir . '/app.log', $line, FILE_APPEND | LOCK_EX);
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
    // Never leak stack traces or internal paths to the client
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

    return @mkdir($path, $mode, true);
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
