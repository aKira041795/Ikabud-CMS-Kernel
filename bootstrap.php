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

function kernelApplyRenderContextMetadata(array $context, string $template, ?array $matchedContracts = null): array
{
    $contracts = is_array($matchedContracts) ? $matchedContracts : kernelMatchedRenderContextContracts($template);
    $profileId = kernelResolveRenderContextProfileId($template, $context, $contracts);
    $schemaStack = kernelResolveRenderContextSchemaStack($template, $context, $contracts, $profileId);

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
    $contracts = kernelMatchedRenderContextContracts($template);
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
            $context = $normalize($context, $template, $missingKeys, $typeMismatches);
        }

        $missingKeys = array_values(array_unique(array_filter(array_map('strval', $missingKeys), static fn(string $key): bool => $key !== '')));
        if ($typeMismatches !== []) {
            ksort($typeMismatches);
        }

        if ($missingKeys === [] && $typeMismatches === []) {
            continue;
        }

        $entry = [
            'template' => $template,
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
