#!/usr/bin/env php
<?php

/**
 * Tenant-explicit Daily Ledger scheduled report worker.
 *
 * Run hourly; cadence/deduplication is enforced from tenant-scoped archive
 * metadata:
 *   0 * * * * cd /path/to/ikabud && php modules/daily-ledger/cli/run-scheduled-reports.php --tenant=207 >> storage/logs/cron.log 2>&1
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This worker is CLI-only.\n");
    exit(1);
}

$basePath = dirname(__DIR__, 3);
require_once $basePath . '/src/helpers/cli-bootstrap.php';

$options = getopt('', ['tenant:', 'at::']);
$tenantId = max(0, (int)($options['tenant'] ?? 0));
if ($tenantId <= 0) {
    fwrite(STDERR, "Usage: php modules/daily-ledger/cli/run-scheduled-reports.php --tenant=<id> [--at=<ISO-8601>]\n");
    exit(2);
}

$lockPath = sys_get_temp_dir() . '/daily-ledger-report-worker-' . $tenantId . '.lock';
$lock = fopen($lockPath, 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "A scheduled-report worker is already running for tenant {$tenantId}.\n");
    exit(3);
}

try {
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_SERVER['SERVER_NAME'] = 'localhost';
    $_SERVER['REQUEST_URI'] = '/cli/daily-ledger/scheduled-reports';
    $app = kernelCliBootstrap($basePath);
    $app->tenant()->setTenantId($tenantId);
    $app->setUser([
        'id' => 0,
        'name' => 'Daily Ledger Scheduler',
        'username' => 'daily-ledger-scheduler',
        'role' => 'administrator',
        'source' => 'system',
    ]);
    require_once $basePath . '/src/helpers/module-manager.php';
    require_once $basePath . '/modules/daily-ledger/helpers.php';
    require_once $basePath . '/modules/daily-ledger/handlers.php';
    $context = modulePushContext('daily-ledger');
    if (!$context) {
        throw new RuntimeException('Daily Ledger module context is unavailable.');
    }
    \Ikabud\Kernel\Services\KernelExport::registerDefaults();
    $now = isset($options['at']) && is_string($options['at']) && $options['at'] !== ''
        ? new DateTimeImmutable($options['at'])
        : new DateTimeImmutable('now');
    $summary = dl_runScheduledReports($context->db(), $app->user() ?? [], $now);
    echo json_encode(['ok' => $summary['failed'] === 0, 'tenant_id' => $tenantId] + $summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($summary['failed'] === 0 ? 0 : 1);
} catch (Throwable $e) {
    fwrite(STDERR, "Scheduled report worker failed: {$e->getMessage()}\n");
    exit(1);
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
