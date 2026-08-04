<?php

declare(strict_types=1);

/**
 * Authoritative CLI entry point for module manifest schema-v1 validation.
 *
 * Usage: php scripts/guard-module-manifests.php [--strict] [--json]
 */

$basePath = dirname(__DIR__);
$options = array_slice($_SERVER['argv'] ?? [], 1);
$strict = in_array('--strict', $options, true);
$jsonOutput = in_array('--json', $options, true);

require_once $basePath . '/bootstrap.php';
require_once $basePath . '/src/helpers/manifest-validation.php';

$manifestFiles = array_values(array_filter(
    moduleManifestFilesV1($basePath . '/modules'),
    static fn (string $path): bool => preg_match('/\.bak_\d{8}_\d{6}\//', $path) !== 1
));

$results = [];
$diagnostics = [];
$moduleIds = [];
$ownedTables = [];
$coOwnedTables = [];
$exposedCapabilities = [];

foreach ($manifestFiles as $manifestPath) {
    $validation = validateModuleManifestForGuardV1($manifestPath);
    $manifest = is_array($validation['manifest'] ?? null) ? $validation['manifest'] : [];
    $moduleId = is_string($manifest['id'] ?? null) && trim($manifest['id']) !== ''
        ? trim($manifest['id'])
        : basename(dirname($manifestPath));
    $relativePath = str_replace($basePath . '/', '', $manifestPath);

    foreach ($validation['diagnostics'] ?? [] as $diagnostic) {
        $diagnostic['module'] = $moduleId;
        $diagnostic['path'] = $relativePath;
        $diagnostics[] = $diagnostic;
    }

    if (isset($moduleIds[$moduleId]) && $moduleIds[$moduleId] !== $manifestPath) {
        $diagnostic = moduleManifestDiagnostic(
            \Ikabud\Kernel\Contracts\DiagnosticSeverity::Fatal,
            'duplicate_module_id',
            'manifest.v1.fleet.unique-id',
            '/id',
            "Module id '{$moduleId}' is declared by more than one manifest.",
            'Assign a unique id to one module or remove the duplicate manifest.'
        );
        $diagnostic['module'] = $moduleId;
        $diagnostic['path'] = $relativePath;
        $diagnostics[] = $diagnostic;
    } else {
        $moduleIds[$moduleId] = $manifestPath;
    }

    foreach (is_array($manifest['owns_tables'] ?? null) ? $manifest['owns_tables'] : [] as $table) {
        if (isset($ownedTables[$table]) && $ownedTables[$table] !== $moduleId) {
            $diagnostic = moduleManifestDiagnostic(
                \Ikabud\Kernel\Contracts\DiagnosticSeverity::Fatal,
                'duplicate_table_owner',
                'manifest.v1.fleet.table-owner',
                '/owns_tables',
                "Table '{$table}' is already owned by module '{$ownedTables[$table]}'.",
                "Keep one canonical owner and declare intentional secondary access through co_owns_tables or reads_tables."
            );
            $diagnostic['module'] = $moduleId;
            $diagnostic['path'] = $relativePath;
            $diagnostics[] = $diagnostic;
        } else {
            $ownedTables[$table] = $moduleId;
        }
    }
    foreach (is_array($manifest['co_owns_tables'] ?? null) ? $manifest['co_owns_tables'] : [] as $table) {
        $coOwnedTables[$table][] = $moduleId;
    }

    $exposes = is_array($manifest['capabilities']['exposes'] ?? null) ? $manifest['capabilities']['exposes'] : [];
    foreach ($exposes as $expose) {
        if (!is_array($expose) || !is_string($expose['id'] ?? null)) {
            continue;
        }
        $capabilityId = $expose['id'];
        $modes = is_array($expose['modes'] ?? null) ? array_map('strtolower', $expose['modes']) : ['first'];
        if (isset($exposedCapabilities[$capabilityId]) && !in_array('pipeline', $modes, true)) {
            $diagnostic = moduleManifestDiagnostic(
                \Ikabud\Kernel\Contracts\DiagnosticSeverity::Advisory,
                'duplicate_capability_provider',
                'manifest.v1.fleet.capability-provider',
                '/capabilities/exposes',
                "Capability '{$capabilityId}' is also provided by '{$exposedCapabilities[$capabilityId]}'.",
                'Use pipeline mode for intentional multi-provider capabilities or remove the duplicate provider.'
            );
            $diagnostic['module'] = $moduleId;
            $diagnostic['path'] = $relativePath;
            $diagnostics[] = $diagnostic;
        }
        $exposedCapabilities[$capabilityId] = $moduleId;
    }

    $results[] = [
        'module' => $moduleId,
        'path' => $relativePath,
        'ok' => !empty($validation['ok']),
        'schema_version' => MODULE_MANIFEST_SCHEMA_VERSION,
        'exposes' => count($exposes),
        'depends' => count(is_array($manifest['capabilities']['depends'] ?? null) ? $manifest['capabilities']['depends'] : []),
    ];
}

foreach ($coOwnedTables as $table => $modules) {
    if (!isset($ownedTables[$table])) {
        foreach ($modules as $moduleId) {
            $diagnostic = moduleManifestDiagnostic(
                \Ikabud\Kernel\Contracts\DiagnosticSeverity::Fatal,
                'co_owned_table_without_owner',
                'manifest.v1.fleet.co-owner',
                '/co_owns_tables',
                "Table '{$table}' has co-owner '{$moduleId}' but no canonical owner.",
                'Declare one module as canonical owner in owns_tables.'
            );
            $diagnostic['module'] = $moduleId;
            $diagnostic['path'] = str_replace($basePath . '/', '', $moduleIds[$moduleId] ?? '');
            $diagnostics[] = $diagnostic;
        }
    }
}

// Fleet-level product suite contract checks (schema-v2 layer): extends targets
// exist, contribution hosts exist, and contributed extension points are
// declared by the host. Builds a module-id-keyed manifest map from discovery.
$fleetManifests = [];
foreach ($manifestFiles as $manifestPath) {
    $validation = validateModuleManifestForGuardV1($manifestPath);
    $manifest = is_array($validation['manifest'] ?? null) ? $validation['manifest'] : [];
    $moduleId = is_string($manifest['id'] ?? null) && trim($manifest['id']) !== ''
        ? trim($manifest['id'])
        : basename(dirname($manifestPath));
    $fleetManifests[$moduleId] = $manifest;
}
if (function_exists('validateModuleSuiteFleetV1')) {
    foreach (validateModuleSuiteFleetV1($fleetManifests) as $suiteDiagnostic) {
        $suiteDiagnostic['path'] = str_replace($basePath . '/', '', $moduleIds[$suiteDiagnostic['module'] ?? ''] ?? '');
        $diagnostics[] = $suiteDiagnostic;
    }
}

$blockingSeverities = $strict ? ['fatal', 'cert_blocker'] : ['fatal'];
$blocking = array_values(array_filter($diagnostics, static fn (array $diagnostic): bool => in_array($diagnostic['severity'] ?? '', $blockingSeverities, true)));

if ($jsonOutput) {
    echo json_encode([
        'ok' => $blocking === [],
        'schema_version' => MODULE_MANIFEST_SCHEMA_VERSION,
        'authoritative' => true,
        'strict' => $strict,
        'checked' => count($manifestFiles),
        'results' => $results,
        'diagnostics' => $diagnostics,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit($blocking === [] ? 0 : 1);
}

foreach ($diagnostics as $diagnostic) {
    $severity = strtoupper((string)$diagnostic['severity']);
    $line = "[{$severity}] {$diagnostic['module']}: {$diagnostic['code']} ({$diagnostic['rule']}) - {$diagnostic['message']} Correction: {$diagnostic['correction']}";
    fwrite(in_array($diagnostic['severity'], $blockingSeverities, true) ? STDERR : STDOUT, $line . "\n");
}

fwrite(STDOUT, "Checked " . count($manifestFiles) . " module manifest(s) against schema v" . MODULE_MANIFEST_SCHEMA_VERSION . ".\n");
if ($blocking !== []) {
    fwrite(STDERR, 'Guard failed with ' . count($blocking) . " blocking diagnostic(s).\n");
    exit(1);
}

fwrite(STDOUT, "Guard passed.\n");
exit(0);
