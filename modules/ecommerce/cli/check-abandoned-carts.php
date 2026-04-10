#!/usr/bin/env php
<?php

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

$basePath = dirname(__DIR__, 3);
require_once $basePath . '/src/helpers/cli-bootstrap.php';

try {
    kernelCliBootstrap($basePath);
    require_once $basePath . '/src/helpers/module-manager.php';
    require_once $basePath . '/modules/cms/helpers.php';
    require_once $basePath . '/modules/ecommerce/helpers.php';
    kernelCliRequireFunctions(['ecAbandonedCartProcessDueReminders']);
} catch (Throwable $e) {
    fwrite(STDERR, "Bootstrap failed: {$e->getMessage()}\n");
    exit(1);
}

$results = ecAbandonedCartProcessDueReminders(100);
$sent = count(array_filter($results, static fn(array $row): bool => !empty($row['ok'])));
$failed = count($results) - $sent;

echo '[' . date('Y-m-d H:i:s') . "] Abandoned cart check complete. Sent {$sent}, failed {$failed}.\n";
exit($failed > 0 ? 1 : 0);