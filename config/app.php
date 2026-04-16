<?php

declare(strict_types=1);

$appUrl = $_ENV['APP_URL'] ?? 'http://applicationos.test';
$cookieName = trim((string)($_ENV['APP_COOKIE_NAME'] ?? ''));
if ($cookieName === '') {
    $cookieHost = (string)(parse_url($appUrl, PHP_URL_HOST) ?? '');
    $cookieHost = strtolower($cookieHost);
    $cookieHost = preg_replace('/[^a-z0-9]+/', '_', $cookieHost) ?? '';
    $cookieHost = trim($cookieHost, '_');
    $cookieName = ($cookieHost !== '' ? $cookieHost : 'app') . '_token';
}

return [
    'name' => 'Application Kernel OS',
    'version' => '0.1.0',
    'env' => $_ENV['APP_ENV'] ?? 'development',
    'debug' => (bool) ($_ENV['APP_DEBUG'] ?? true),
    'url' => $appUrl,
    'timezone' => $_ENV['APP_TIMEZONE'] ?? 'Asia/Manila',

    'cookie_name' => $cookieName,

    'jwt' => [
        'secret' => $_ENV['JWT_SECRET'] ?? 'change-me-in-env',
        'expiration' => (int) ($_ENV['JWT_EXPIRATION'] ?? 14400),
    ],

    'auth' => [
        'login_rate_limit_max' => (int)($_ENV['AUTH_LOGIN_RATE_LIMIT_MAX'] ?? 10),
        'login_rate_limit_window' => (int)($_ENV['AUTH_LOGIN_RATE_LIMIT_WINDOW'] ?? 300),
    ],

    'capabilities' => [
        'timeout_ms' => (int) ($_ENV['CAP_TIMEOUT_MS'] ?? 2000),
        'retries' => (int) ($_ENV['CAP_RETRIES'] ?? 0),
        'retry_delay_ms' => (int) ($_ENV['CAP_RETRY_DELAY_MS'] ?? 100),
        'breaker_threshold' => (int) ($_ENV['CAP_BREAKER_THRESHOLD'] ?? 5),
        'breaker_window_sec' => (int) ($_ENV['CAP_BREAKER_WINDOW_SEC'] ?? 30),
        'breaker_cooldown_sec' => (int) ($_ENV['CAP_BREAKER_COOLDOWN_SEC'] ?? 60),
        'metrics_max_samples' => (int) ($_ENV['CAP_METRICS_MAX_SAMPLES'] ?? 200),
        'schema_validation_mode' => (string) ($_ENV['CAP_SCHEMA_MODE'] ?? 'warn'),
        'schema_modes' => [
            'kernel.audit.record@1' => 'enforce',
            'workflow.state.get@1' => 'enforce',
            'workflow.transition@1' => 'enforce',
        ],
    ],

    'modules' => [
        // Backward compatible default: eagerly load each enabled module's helpers.php
        // during route loading. Set APP_EAGER_MODULE_HELPERS=0 to experiment with
        // lazy helper loading patterns.
        'eager_helpers' => (bool) ($_ENV['APP_EAGER_MODULE_HELPERS'] ?? true),
        // warn  => log route ambiguities and continue registering
        // block => reject ambiguous dynamic/static route registrations
        'route_ambiguity_mode' => (string) ($_ENV['APP_ROUTE_AMBIGUITY_MODE'] ?? 'warn'),
    ],

    'multi_tenant' => [
        'enabled' => (bool) ($_ENV['APP_MULTI_TENANT_ENABLED'] ?? false),
        'strategy' => (string) ($_ENV['APP_TENANT_STRATEGY'] ?? 'control_host'),
        'header' => (string) ($_ENV['APP_TENANT_HEADER'] ?? 'X-Tenant'),
        'default' => isset($_ENV['APP_TENANT_DEFAULT']) && trim((string) $_ENV['APP_TENANT_DEFAULT']) !== ''
            ? (int) $_ENV['APP_TENANT_DEFAULT']
            : null,
        'column' => (string) ($_ENV['APP_TENANT_COLUMN'] ?? 'tenant_id'),
        'db_pool_max' => max(1, (int) ($_ENV['APP_TENANT_DB_POOL_MAX'] ?? 20)),
        'host_map' => [],
    ],

    'database' => [
        'idle_validation_seconds' => max(5, (int) ($_ENV['APP_DB_IDLE_VALIDATION_SECONDS'] ?? 60)),
    ],

    'cache' => [
        'log_invalidations' => filter_var($_ENV['APP_CACHE_LOG_INVALIDATIONS'] ?? false, FILTER_VALIDATE_BOOL),
    ],

    'disyl' => [
        // Cross-request APCu render cache TTL (seconds).
        // 0 = disabled (default). Handler-level caches are the authoritative
        // full-page caches; enable this only for fragment/partial rendering
        // or to smooth brief concurrent bursts on non-handler paths.
        'shared_output_ttl' => (int)($_ENV['DISYL_SHARED_OUTPUT_TTL'] ?? 0),
    ],

    'crypto' => [
        'control_db_enc_key' => $_ENV['CONTROL_DB_ENC_KEY'] ?? ($_ENV['APP_ENCRYPTION_KEY'] ?? null),
    ],

    'cookie' => [
        'samesite' => $_ENV['APP_COOKIE_SAMESITE'] ?? 'Strict',
    ],

    'updates' => [
        'enabled' => (bool) ($_ENV['APP_UPDATES_ENABLED'] ?? true),
        'github_repo' => trim((string) ($_ENV['APP_UPDATES_GITHUB_REPO'] ?? 'aKira041795/Ikabud-CMS-Kernel')),
        'github_branch' => trim((string) ($_ENV['APP_UPDATES_GITHUB_BRANCH'] ?? 'master')),
        'github_api_base' => rtrim((string) ($_ENV['APP_UPDATES_GITHUB_API_BASE'] ?? 'https://api.github.com'), '/'),
        'channel' => trim((string) ($_ENV['APP_UPDATES_CHANNEL'] ?? 'stable')),
        'timeout_seconds' => max(2, (int) ($_ENV['APP_UPDATES_TIMEOUT_SECONDS'] ?? 10)),
        'release_limit' => max(1, min(20, (int) ($_ENV['APP_UPDATES_RELEASE_LIMIT'] ?? 5))),
        'user_agent' => trim((string) ($_ENV['APP_UPDATES_USER_AGENT'] ?? 'Ikabud-Kernel-Updater/1.0')),
        'auto_check_interval_minutes' => max(1, (int) ($_ENV['APP_UPDATES_AUTO_CHECK_INTERVAL_MINUTES'] ?? 60)),
        'auto_sync_on_platform' => (bool) ($_ENV['APP_UPDATES_AUTO_SYNC_ON_PLATFORM'] ?? false),
    ],
];
