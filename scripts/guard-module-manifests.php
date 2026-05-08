<?php

declare(strict_types=1);

/**
 * Guard script: validate each module manifest under the modules directory.
 *
 * Checks performed:
 * 1) JSON/schema validation via validateModuleManifest()
 * 2) Capabilities block validation via validateModuleCapabilities()
 * 3) Optional sanity check: folder name should match manifest id
 * 4) Informational duplicate capability expose detection across modules
 *
 * Usage:
 *   php scripts/guard-module-manifests.php
 *   php scripts/guard-module-manifests.php --strict
 *   php scripts/guard-module-manifests.php --json
 */

$basePath = dirname(__DIR__);
$options = array_slice($_SERVER['argv'] ?? [], 1);
$strict = in_array('--strict', $options, true);
$jsonOutput = in_array('--json', $options, true);

require_once $basePath . '/bootstrap.php';
if (!function_exists('validateModuleManifest') || !function_exists('validateModuleCapabilities')) {
    require_once $basePath . '/src/helpers/module-manager.php';
}

$modulesDir = $basePath . '/modules';
if (!is_dir($modulesDir)) {
    fwrite(STDERR, "ERROR: modules directory not found: {$modulesDir}\n");
    exit(2);
}

$entries = scandir($modulesDir);
if ($entries === false) {
    fwrite(STDERR, "ERROR: unable to read modules directory: {$modulesDir}\n");
    exit(2);
}

$checked = 0;
$warnings = 0;
$errors = [];
$results = [];
$exposedCapabilities = [];

foreach ($entries as $entry) {
    if ($entry === '.' || $entry === '..') {
        continue;
    }

    $modulePath = $modulesDir . '/' . $entry;
    if (!is_dir($modulePath)) {
        continue;
    }

    if (preg_match('/\.bak_\d{8}_\d{6}$/', $entry)) {
        continue;
    }

    $manifestPath = $modulePath . '/module.json';
    if (!is_file($manifestPath)) {
        continue;
    }

    $checked++;

    $manifestCheck = validateModuleManifest($manifestPath);
    if (empty($manifestCheck['ok'])) {
        $code = (string)($manifestCheck['error_code'] ?? 'manifest_invalid');
        $msg = (string)($manifestCheck['error'] ?? 'Unknown manifest validation error');
        $errors[] = "[ERROR] {$entry}: {$code} - {$msg}";
        $results[] = [
            'module' => $entry,
            'ok' => false,
            'error_code' => $code,
            'error' => $msg,
        ];
        continue;
    }

    $manifest = is_array($manifestCheck['manifest'] ?? null) ? $manifestCheck['manifest'] : [];
    $moduleId = (string)($manifest['id'] ?? '');

    if ($moduleId !== '' && $moduleId !== $entry) {
        $warnings++;
        $warningLine = "[WARN]  {$entry}: folder name differs from manifest id '{$moduleId}'";
        if ($strict) {
            $errors[] = str_replace('[WARN]', '[ERROR]', $warningLine);
        } elseif (!$jsonOutput) {
            fwrite(STDOUT, $warningLine . "\n");
        }
    }

    $capsCheck = validateModuleCapabilities($manifest);
    if (empty($capsCheck['ok'])) {
        $msg = (string)($capsCheck['error'] ?? 'Invalid capabilities block');
        $errors[] = "[ERROR] {$entry}: capabilities_invalid - {$msg}";
        $results[] = [
            'module' => $entry,
            'ok' => false,
            'error_code' => 'capabilities_invalid',
            'error' => $msg,
        ];
        continue;
    }

    $exposesCount = is_array($capsCheck['exposes'] ?? null) ? count($capsCheck['exposes']) : 0;
    $dependsCount = is_array($capsCheck['depends'] ?? null) ? count($capsCheck['depends']) : 0;
    foreach (($capsCheck['exposes'] ?? []) as $expose) {
        $capabilityId = is_array($expose) ? (string)($expose['id'] ?? '') : '';
        if ($capabilityId === '') {
            continue;
        }
        // Pipeline-mode capabilities are intentionally multi-provider; skip duplicate-warning.
        $modes = is_array($expose) && isset($expose['modes']) && is_array($expose['modes'])
            ? array_map('strval', $expose['modes'])
            : [];
        $isPipeline = in_array('pipeline', $modes, true);
        if (isset($exposedCapabilities[$capabilityId]) && $exposedCapabilities[$capabilityId] !== $entry && !$isPipeline) {
            $warnings++;
            if (!$jsonOutput) {
                fwrite(
                    STDOUT,
                    "[WARN]  {$entry}: duplicate capability expose '{$capabilityId}' also provided by {$exposedCapabilities[$capabilityId]}\n"
                );
            }
        }
        $exposedCapabilities[$capabilityId] = $entry;
    }

    // Phase 6 additions: routes file existence + owns_tables collision detection
    $routesFile = isset($manifest['routes']) ? (string)$manifest['routes'] : '';
    if ($routesFile !== '') {
        $routesPath = $modulePath . '/' . ltrim($routesFile, '/');
        if (!is_file($routesPath)) {
            $errors[] = "[ERROR] {$entry}: routes_file_missing - declared routes file '{$routesFile}' not found";
            $results[] = [
                'module' => $entry,
                'ok' => false,
                'error_code' => 'routes_file_missing',
                'error' => "declared routes file '{$routesFile}' not found",
            ];
            continue;
        }
    }

    $ownsTables = is_array($manifest['owns_tables'] ?? null) ? $manifest['owns_tables'] : [];
    $coOwnsTables = is_array($manifest['co_owns_tables'] ?? null) ? $manifest['co_owns_tables'] : [];
    if (!isset($GLOBALS['__guard_owned_tables'])) {
        $GLOBALS['__guard_owned_tables'] = [];
    }
    if (!isset($GLOBALS['__guard_co_owned_tables'])) {
        $GLOBALS['__guard_co_owned_tables'] = [];
    }
    foreach ($ownsTables as $table) {
        $tableName = is_string($table) ? trim($table) : '';
        if ($tableName === '') {
            continue;
        }
        if (isset($GLOBALS['__guard_owned_tables'][$tableName]) && $GLOBALS['__guard_owned_tables'][$tableName] !== $entry) {
            $other = $GLOBALS['__guard_owned_tables'][$tableName];
            $line = "[ERROR] {$entry}: table '{$tableName}' already declared owned by {$other}; declare 'co_owns_tables' instead for non-canonical co-ownership";
            $errors[] = $line;
        } else {
            $GLOBALS['__guard_owned_tables'][$tableName] = $entry;
        }
    }
    foreach ($coOwnsTables as $table) {
        $tableName = is_string($table) ? trim($table) : '';
        if ($tableName === '') {
            continue;
        }
        if (!isset($GLOBALS['__guard_owned_tables'][$tableName])) {
            // Co-owner without canonical owner — defer until end (canonical may load later).
            // Tracking only; final reconciliation happens after the discovery loop.
        }
        $GLOBALS['__guard_co_owned_tables'][$tableName][] = $entry;
    }

    $version = (string)($manifest['version'] ?? '');
    if ($version === '' || !preg_match('/^\d+\.\d+\.\d+(-[A-Za-z0-9.]+)?$/', $version)) {
        $warnings++;
        if (!$jsonOutput) {
            fwrite(STDOUT, "[WARN]  {$entry}: non-semver version '{$version}'\n");
        }
    }

    $results[] = [
        'module' => $entry,
        'ok' => true,
        'exposes' => $exposesCount,
        'depends' => $dependsCount,
    ];

    if (!$jsonOutput) {
        fwrite(STDOUT, "[OK]    {$entry}: manifest + capabilities valid (exposes={$exposesCount}, depends={$dependsCount})\n");
    }
}

if ($jsonOutput) {
    echo json_encode([
        'ok' => empty($errors),
        'strict' => $strict,
        'checked' => $checked,
        'warnings' => $warnings,
        'results' => $results,
        'errors' => $errors,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit(empty($errors) ? 0 : 1);
}

fwrite(STDOUT, "\nChecked {$checked} module manifest(s).\n");

if (!empty($errors)) {
    fwrite(STDERR, "\nGuard failed with " . count($errors) . " error(s):\n");
    foreach ($errors as $line) {
        fwrite(STDERR, $line . "\n");
    }
    exit(1);
}

fwrite(STDOUT, "Guard passed with {$warnings} warning(s).\n");
exit(0);
