<?php
declare(strict_types=1);

use Ikabud\Kernel\Database\KernelPDO;

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-registry.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../src/helpers/module-migrations.php';

function ehrBootstrapUsage(): void
{
    echo "EHR Bootstrap\n\n";
    echo "Usage:\n";
    echo "  php scripts/ehr-bootstrap.php [--modules=a,b,c] [--tenant=ID] [--no-migrate] [--dry-run]\n\n";
    echo "Options:\n";
    echo "  --modules=LIST   Comma-separated module IDs to enable and migrate.\n";
    echo "  --tenant=ID      Run migrations against a specific tenant DB. Default: primary DB.\n";
    echo "  --no-migrate     Enable modules in storage/modules.json but skip SQL migration sync.\n";
    echo "  --dry-run        Print the plan without writing registry changes or running migrations.\n";
    echo "  --help           Show this message.\n\n";
    echo "Default modules:\n";
    echo "  ehr-core, patient-registry, encounters, clinical-notes, orders, results, prescriptions, documents, privacy-consent, scheduling, audit, reporting, billing-bridge\n";
}

function ehrBootstrapParseOptions(array $argv): array
{
    $opts = [
        'modules' => null,
        'tenant' => null,
        'migrate' => true,
        'dry_run' => false,
        'help' => false,
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--help' || $arg === '-h') {
            $opts['help'] = true;
            continue;
        }
        if ($arg === '--no-migrate') {
            $opts['migrate'] = false;
            continue;
        }
        if ($arg === '--dry-run') {
            $opts['dry_run'] = true;
            continue;
        }
        if (str_starts_with($arg, '--tenant=')) {
            $opts['tenant'] = max(1, (int)substr($arg, strlen('--tenant=')));
            continue;
        }
        if (str_starts_with($arg, '--modules=')) {
            $raw = substr($arg, strlen('--modules='));
            $opts['modules'] = array_values(array_filter(array_map(
                static fn(string $value): string => trim($value),
                explode(',', $raw)
            ), static fn(string $value): bool => $value !== ''));
            continue;
        }
    }

    return $opts;
}

function ehrBootstrapDefaultModules(): array
{
    return [
        'ehr-core',
        'patient-registry',
        'encounters',
        'clinical-notes',
        'orders',
        'results',
        'prescriptions',
        'documents',
        'privacy-consent',
        'scheduling',
        'audit',
        'reporting',
        'billing-bridge',
    ];
}

function ehrBootstrapResolveModules(?array $requestedModules): array
{
    $available = discoverModules();
    $requested = $requestedModules !== null && $requestedModules !== []
        ? $requestedModules
        : ehrBootstrapDefaultModules();

    $resolved = [];
    $missing = [];
    foreach ($requested as $moduleId) {
        if (isset($available[$moduleId])) {
            $resolved[] = $moduleId;
        } else {
            $missing[] = $moduleId;
        }
    }

    return [$resolved, $missing];
}

function ehrBootstrapEnableModules(array $moduleIds): array
{
    $registry = readModuleRegistry();
    $changed = [];
    foreach ($moduleIds as $moduleId) {
        $entry = $registry[$moduleId] ?? [];
        if (!is_array($entry)) {
            $entry = [];
        }
        if (!empty($entry['enabled'])) {
            $registry[$moduleId] = $entry;
            continue;
        }
        $entry['enabled'] = true;
        $registry[$moduleId] = $entry;
        $changed[] = $moduleId;
    }
    writeModuleRegistry($registry);
    return $changed;
}

function ehrBootstrapSyncMigrations(PDO $db, array $moduleIds): array
{
    $allModules = discoverModules();
    $allApplied = tenantAllAppliedMigrations($db);
    $results = [];

    KernelPDO::kernelEscalationEnter();
    try {
        $kernelApplied = tenantSyncKernelMigrations($db, $allApplied);
        if ($kernelApplied !== []) {
            $results['_kernel'] = $kernelApplied;
        }

        foreach ($moduleIds as $moduleId) {
            $manifest = $allModules[$moduleId] ?? null;
            if (!is_array($manifest)) {
                continue;
            }
            $executed = tenantSyncModuleMigrations($db, $moduleId, $manifest, $allApplied);
            if ($executed !== []) {
                $results[$moduleId] = $executed;
            }
        }
    } finally {
        KernelPDO::kernelEscalationLeave();
    }

    return $results;
}

$options = ehrBootstrapParseOptions($argv);
if ($options['help']) {
    ehrBootstrapUsage();
    exit(0);
}

[$modules, $missingModules] = ehrBootstrapResolveModules($options['modules']);
if ($modules === []) {
    fwrite(STDERR, "No EHR modules resolved.\n");
    if ($missingModules !== []) {
        fwrite(STDERR, 'Missing: ' . implode(', ', $missingModules) . "\n");
    }
    exit(1);
}

echo "EHR bootstrap plan\n";
echo 'Modules: ' . implode(', ', $modules) . "\n";
if ($missingModules !== []) {
    echo 'Missing modules skipped: ' . implode(', ', $missingModules) . "\n";
}
echo 'Target DB: ' . ($options['tenant'] !== null ? ('tenant ' . $options['tenant']) : 'primary') . "\n";
echo 'Migrate: ' . ($options['migrate'] ? 'yes' : 'no') . "\n";

if ($options['dry_run']) {
    echo "Dry run only. No changes written.\n";
    exit(0);
}

$changed = ehrBootstrapEnableModules($modules);
if ($changed !== []) {
    echo 'Enabled modules: ' . implode(', ', $changed) . "\n";
} else {
    echo "All selected modules were already enabled.\n";
}

if (!$options['migrate']) {
    echo "Module enablement complete. Migration sync skipped.\n";
    exit(0);
}

$db = $options['tenant'] !== null ? app()->dbForTenant((int)$options['tenant']) : app()->db();
if (!$db instanceof PDO) {
    fwrite(STDERR, "Database connection unavailable for selected target.\n");
    exit(1);
}

try {
    $results = ehrBootstrapSyncMigrations($db, $modules);
} catch (Throwable $e) {
    fwrite(STDERR, 'Migration sync failed: ' . $e->getMessage() . "\n");
    exit(1);
}

if ($results === []) {
    echo "No new migrations were applied.\n";
    exit(0);
}

echo "Applied migrations:\n";
foreach ($results as $moduleId => $artifacts) {
    echo '  - ' . $moduleId . ': ' . implode(', ', $artifacts) . "\n";
}

echo "EHR bootstrap complete.\n";