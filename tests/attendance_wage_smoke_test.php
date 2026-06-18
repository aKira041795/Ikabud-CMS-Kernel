<?php

declare(strict_types=1);

/**
 * Smoke test for the Attendance & Wage module.
 *
 * Verifies:
 *   - manifest is valid JSON and parses
 *   - module.json id matches folder name
 *
 * Run from repo root: php tests/tests/attendance_wage_smoke_test.php
 */

$basePath = dirname(__DIR__);
require_once $basePath . '/bootstrap.php';
require_once $basePath . '/src/helpers/module-manager.php';

$manifestPath = $basePath . '/modules/attendance-wage/module.json';
$check = validateModuleManifest($manifestPath);

if (empty($check['ok'])) {
    fwrite(STDERR, "FAIL: manifest invalid - " . ($check['error'] ?? 'unknown') . "
");
    exit(1);
}

$manifest = $check['manifest'] ?? [];
if (($manifest['id'] ?? '') !== 'attendance-wage') {
    fwrite(STDERR, "FAIL: manifest id mismatch
");
    exit(1);
}

echo "PASS: attendance-wage smoke test
";
exit(0);
