<?php

declare(strict_types=1);

/**
 * Build a stable cache identity without querying the database.
 *
 * @param array<string, mixed> $config
 */
function kernelTableEnsureCacheDatabaseIdentity(array $config): string
{
    $identity = [
        'driver' => strtolower(trim((string)($config['driver'] ?? 'mysql'))),
        'host' => strtolower(trim((string)($config['host'] ?? 'localhost'))),
        'port' => trim((string)($config['port'] ?? '3306')),
        'database' => strtolower(trim((string)($config['database'] ?? $config['db_name'] ?? ''))),
    ];

    return hash('sha256', json_encode($identity, JSON_UNESCAPED_SLASHES));
}

function kernelTableEnsureCacheDirectory(): string
{
    $override = trim((string)(getenv('KERNEL_TABLE_ENSURE_CACHE_DIR') ?: ''));
    return $override !== '' ? $override : dirname(__DIR__, 2) . '/storage/cache/table-ensure';
}

/**
 * Load the database's guard map once per request. A false return means the
 * store is unavailable, so callers must run their DDL.
 *
 * @return array<string, int>|false
 */
function kernelTableEnsureCacheLoad(string $databaseIdentity): array|false
{
    $requestKey = 'kernel_table_ensure_cache_' . $databaseIdentity;
    if (array_key_exists($requestKey, $GLOBALS)) {
        return $GLOBALS[$requestKey];
    }

    $useApcu = function_exists('apcu_enabled') && apcu_enabled();
    $cacheKey = 'kernel.table_ensure.v1.' . $databaseIdentity;
    if ($useApcu) {
        try {
            $value = apcu_fetch($cacheKey, $success);
            if ($success && !is_array($value)) {
                return $GLOBALS[$requestKey] = false;
            }
            return $GLOBALS[$requestKey] = ($success ? $value : []);
        } catch (Throwable $e) {
            return $GLOBALS[$requestKey] = false;
        }
    }

    $directory = kernelTableEnsureCacheDirectory();
    if (!is_dir($directory) && !@mkdir($directory, 0770, true) && !is_dir($directory)) {
        return $GLOBALS[$requestKey] = false;
    }
    if (!is_readable($directory) || !is_writable($directory)) {
        return $GLOBALS[$requestKey] = false;
    }

    $path = $directory . '/' . $databaseIdentity . '.json';
    if (!is_file($path)) {
        return $GLOBALS[$requestKey] = [];
    }
    $json = @file_get_contents($path);
    if (!is_string($json)) {
        return $GLOBALS[$requestKey] = false;
    }
    $value = json_decode($json, true);
    if (!is_array($value)) {
        return $GLOBALS[$requestKey] = false;
    }

    $guards = [];
    foreach ($value as $guard => $timestamp) {
        if (is_string($guard) && is_numeric($timestamp)) {
            $guards[$guard] = (int)$timestamp;
        }
    }
    return $GLOBALS[$requestKey] = $guards;
}

function kernelTableEnsureCacheShouldRun(
    string $databaseIdentity,
    string $guard,
    bool $force = false,
    int $ttlSeconds = 300
): bool {
    if ($force || $databaseIdentity === '' || $guard === '') {
        return true;
    }

    $guards = kernelTableEnsureCacheLoad($databaseIdentity);
    if ($guards === false) {
        return true;
    }

    $ensuredAt = (int)($guards[$guard] ?? 0);
    return $ensuredAt <= 0 || $ensuredAt < time() - max(1, $ttlSeconds);
}

/** Record success. Failure deliberately leaves the guard cold. */
function kernelTableEnsureCacheMarkEnsured(string $databaseIdentity, string $guard): bool
{
    if ($databaseIdentity === '' || $guard === '') {
        return false;
    }

    $requestKey = 'kernel_table_ensure_cache_' . $databaseIdentity;
    $guards = kernelTableEnsureCacheLoad($databaseIdentity);
    if ($guards === false) {
        return false;
    }
    $guards[$guard] = time();

    $useApcu = function_exists('apcu_enabled') && apcu_enabled();
    try {
        $written = $useApcu
            ? apcu_store('kernel.table_ensure.v1.' . $databaseIdentity, $guards, 300)
            : kernelWriteFile(
                kernelTableEnsureCacheDirectory() . '/' . $databaseIdentity . '.json',
                (string)json_encode($guards, JSON_UNESCAPED_SLASHES)
            );
    } catch (Throwable $e) {
        $written = false;
    }

    if (!$written) {
        return false;
    }
    $GLOBALS[$requestKey] = $guards;
    return true;
}
