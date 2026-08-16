<?php

declare(strict_types=1);

/**
 * Bootstrap kernel runtime for CLI/operator scripts with explicit errors.
 */
function kernelCliBootstrap(?string $basePath = null): \Ikabud\Kernel\App
{
    if (php_sapi_name() !== 'cli') {
        throw new RuntimeException('This script must be run from CLI.');
    }

    $resolvedBasePath = $basePath !== null && $basePath !== ''
        ? rtrim($basePath, '/\\')
        : dirname(__DIR__, 2);

    $bootstrapPath = $resolvedBasePath . '/bootstrap.php';
    if (!is_file($bootstrapPath)) {
        throw new RuntimeException("bootstrap.php not found at expected path: {$bootstrapPath}");
    }

    // bootstrap.php historically assigns $config in the including scope while
    // app() reads it from globals. CLI includes run inside this function, so
    // promote the returned/local configuration just like the test harness.
    global $config;
    $returned = require_once $bootstrapPath;
    if ((!is_array($config) || !isset($config['database'])) && is_array($returned)) {
        $config = $returned;
    }

    if (!function_exists('app')) {
        throw new RuntimeException('Kernel bootstrap loaded but app() helper is unavailable. Ensure bootstrap.php initializes correctly.');
    }

    return app();
}

/**
 * Assert required helper functions are available with actionable guidance.
 *
 * @param array<int, string> $requiredFunctions
 */
function kernelCliRequireFunctions(array $requiredFunctions): void
{
    foreach ($requiredFunctions as $fn) {
        if (!is_string($fn) || trim($fn) === '') {
            continue;
        }
        if (!function_exists($fn)) {
            throw new RuntimeException(
                "Required helper '{$fn}' is not available. Ensure the module helper file is required before running this script."
            );
        }
    }
}
