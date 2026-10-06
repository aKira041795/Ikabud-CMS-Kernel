#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Daily Ledger — G1/G3 runtime harness.
 *
 * Runs the REAL products CSV import endpoint (apiProductsImportCsv) in a clean
 * subprocess so the uploaded-file path is exercised exactly as the web surface
 * would. The payload file is JSON:  { "csv": "Name,Price,SKU\n..." }
 *
 * Modes: import_csv
 *
 * stdout is the JSON the endpoint would have returned.
 */

$basePath = dirname(__DIR__, 2);
require_once $basePath . '/src/helpers/cli-bootstrap.php';
$mode = (string)($argv[1] ?? '');
$payloadFile = (string)($argv[2] ?? '');
if ($mode !== 'import_csv' || !is_file($payloadFile)) {
    exit(2);
}

$_SERVER['HTTP_HOST'] = $_SERVER['SERVER_NAME'] = 'baronledger.test';
$_SERVER['REQUEST_METHOD'] = 'POST';
$app = kernelCliBootstrap($basePath);
$app->tenant()->setTenantId(207);
require_once $basePath . '/src/helpers/module-manager.php';
require_once $basePath . '/modules/daily-ledger/helpers.php';
require_once $basePath . '/modules/daily-ledger/handlers-deliveries.php';
require_once $basePath . '/modules/daily-ledger/handlers.php';
modulePushContext('daily-ledger');

$payload = json_decode((string)file_get_contents($payloadFile), true);
if (!is_array($payload)) {
    exit(3);
}

$_COOKIE[dlCookieName()] = app()->jwt()->generate([
    'sub' => 'admin:1',
    'id' => 1,
    'username' => 'admin-harness',
    'name' => 'Admin Harness',
    'role' => 'admin',
    'source' => 'daily-ledger',
]);

$tmpPath = tempnam(sys_get_temp_dir(), 'dl-import-');
file_put_contents($tmpPath, (string)($payload['csv'] ?? ''));
$_FILES['csv_file'] = [
    'name' => 'products.csv',
    'type' => 'text/csv',
    'tmp_name' => $tmpPath,
    'error' => UPLOAD_ERR_OK,
    'size' => (int)filesize($tmpPath),
];

apiProductsImportCsv();
