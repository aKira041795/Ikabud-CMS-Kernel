<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require SRC_PATH . '/helpers/module-manager.php';
require SRC_PATH . '/helpers/updates.php';

$result = kernelUpdatesSyncCatalog();
if (!empty($result['ok'])) {
    echo 'Update catalog synced from ' . kernelUpdatesRepo() . PHP_EOL;
    echo 'Kernel records: ' . (int) ($result['kernel_records'] ?? 0) . PHP_EOL;
    echo 'Module records: ' . (int) ($result['module_records'] ?? 0) . PHP_EOL;
    exit(0);
}

fwrite(STDERR, 'Update catalog sync failed: ' . (string) ($result['error'] ?? 'unknown error') . PHP_EOL);
exit(1);