#!/usr/bin/env php
<?php

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    echo "This script must be run from the command line.\n";
    exit(1);
}

$basePath = dirname(__DIR__, 3);
require_once $basePath . '/src/helpers/cli-bootstrap.php';

try {
    kernelCliBootstrap($basePath);
    require_once $basePath . '/modules/cms/helpers.php';
    require_once $basePath . '/modules/cms/handlers.php';
    kernelCliRequireFunctions(['cmsAiAutomationExecuteDuePlans']);
} catch (Throwable $e) {
    fwrite(STDERR, 'Bootstrap failed: ' . $e->getMessage() . "\n");
    exit(1);
}

$now = date('Y-m-d H:i:s');
echo "[{$now}] CMS AI content automation check starting...\n";

try {
    $results = cmsAiAutomationExecuteDuePlans(10);
    if ($results === []) {
        echo "[{$now}] No due AI content plans found.\n";
        exit(0);
    }

    $failures = 0;
    foreach ($results as $result) {
        if (!empty($result['ok'])) {
            echo '  Generated content ' . (int)($result['content_id'] ?? 0) . ' from run ' . (int)($result['run_id'] ?? 0) . "\n";
            continue;
        }

        $failures++;
        echo '  FAILED run ' . (int)($result['run_id'] ?? 0) . ': ' . (string)($result['error'] ?? 'Unknown error') . "\n";
    }

    echo "[{$now}] Done. Processed " . count($results) . ' plan(s); failures=' . $failures . ".\n";
    exit($failures > 0 ? 1 : 0);
} catch (Throwable $e) {
    fwrite(STDERR, "[{$now}] FATAL: {$e->getMessage()}\n");
    write_log('CMS AI automation cron fatal error: ' . $e->getMessage(), 'critical', [
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);
    exit(1);
}