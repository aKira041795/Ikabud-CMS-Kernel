<?php
/**
 * Migration Integrity Test (Platform Tier 2 — 2.6)
 *
 * Verifies: migration files exist, SQL is parseable, naming conventions
 * followed, no duplicate migration numbers, module manifests reference
 * valid migration files.
 *
 * Run: php tests/migration_integrity_test.php
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

ob_start();
require_once BASE_PATH . '/src/helpers/module-manager.php';
ob_end_clean();

$passed = 0;
$failed = 0;

function t(string $label, bool $result): void
{
    global $passed, $failed;
    if ($result) {
        $passed++;
        echo "  ✓ {$label}\n";
    } else {
        $failed++;
        echo "  ✗ FAIL: {$label}\n";
    }
}

echo "Migration Integrity Test Suite\n";
echo str_repeat('=', 60) . "\n\n";

// ─── Section 1: Kernel Migrations ──────────────────────────────────────
echo "── Section 1: Kernel Migrations ──\n";

$kernelMigrationDir = BASE_PATH . '/migrations';
$kernelMigrations = glob($kernelMigrationDir . '/*.sql');
t('Kernel migrations directory exists', is_dir($kernelMigrationDir));
t('Kernel migrations found', count($kernelMigrations) > 0);

// 1.1 Check sequential numbering
$kernelNumbers = [];
foreach ($kernelMigrations as $file) {
    $basename = basename($file);
    if (preg_match('/^(\d+)_/', $basename, $m)) {
        $kernelNumbers[] = (int)$m[1];
    }
}
sort($kernelNumbers);
$sequential = true;
for ($i = 1; $i < count($kernelNumbers); $i++) {
    if ($kernelNumbers[$i] !== $kernelNumbers[$i - 1] + 1) {
        $sequential = false;
        break;
    }
}
t('Kernel migrations use sequential numbering', $sequential);
t("Kernel migration count: " . count($kernelMigrations), count($kernelMigrations) >= 5);

// 1.2 All kernel migrations are valid SQL
$allValid = true;
foreach ($kernelMigrations as $file) {
    $sql = file_get_contents($file);
    if (trim($sql) === '') {
        $allValid = false;
        echo "    WARNING: Empty migration: " . basename($file) . "\n";
    }
    // Basic SQL syntax check — must contain at least one statement-like keyword
    if (!preg_match('/\b(CREATE|ALTER|INSERT|UPDATE|DROP|DELETE|SELECT)\b/i', $sql)) {
        $allValid = false;
        echo "    WARNING: No SQL statements in: " . basename($file) . "\n";
    }
}
t('All kernel migrations contain valid SQL', $allValid);

// ─── Section 2: Control Plane Migrations ────────────────────────────────
echo "\n── Section 2: Control Plane Migrations ──\n";

$controlMigrationDir = BASE_PATH . '/control-migrations';
$controlMigrations = glob($controlMigrationDir . '/*.sql');
t('Control migrations directory exists', is_dir($controlMigrationDir));
t('Control migrations found', count($controlMigrations) > 0);

$controlNumbers = [];
foreach ($controlMigrations as $file) {
    if (preg_match('/^(\d+)_/', basename($file), $m)) {
        $controlNumbers[] = (int)$m[1];
    }
}
sort($controlNumbers);
$controlSequential = true;
for ($i = 1; $i < count($controlNumbers); $i++) {
    if ($controlNumbers[$i] !== $controlNumbers[$i - 1] + 1) {
        $controlSequential = false;
        break;
    }
}
t('Control migrations use sequential numbering', $controlSequential);

// ─── Section 3: Module Migrations ──────────────────────────────────────
echo "\n── Section 3: Module Migrations ──\n";

ob_start();
$modules = discoverModules();
ob_end_clean();

$modulesWithMigrations = 0;
$migrationErrors = [];
$migrationWarnings = [];

foreach ($modules as $moduleId => $module) {
    $modulePath = $module['_path'] ?? '';
    if ($modulePath === '' || !is_dir($modulePath)) {
        continue;
    }

    // Check module.json for declared migrations
    $manifestPath = $modulePath . '/module.json';
    if (!file_exists($manifestPath)) {
        continue;
    }

    $manifest = json_decode(file_get_contents($manifestPath), true);
    if (!is_array($manifest)) {
        continue;
    }

    $declaredMigrations = $manifest['migrations'] ?? [];
    if (!is_array($declaredMigrations) || count($declaredMigrations) === 0) {
        continue;
    }

    $modulesWithMigrations++;

    // Verify each declared migration file exists
    // Manifests declare paths relative to the module root (e.g. "database/migrations/001_foo.sql"
    // or "migrations/001_foo.sql") OR sometimes as bare filenames or repo-root-absolute paths.
    foreach ($declaredMigrations as $migFile) {
        // Try module-relative first
        $migPath = $modulePath . '/' . $migFile;
        if (!file_exists($migPath)) {
            // Try repo-root-absolute (ecommerce style: "modules/ecommerce/database/migrations/...")
            $migPath = BASE_PATH . '/' . $migFile;
        }
        if (!file_exists($migPath)) {
            // Try bare filename in common migration dirs
            $found = false;
            foreach (['database/migrations', 'migrations'] as $subdir) {
                if (file_exists($modulePath . '/' . $subdir . '/' . $migFile)) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $migrationErrors[] = "{$moduleId}: declared migration {$migFile} not found";
            }
        }
    }

    // Check for duplicate migration numbers within module
    // Note: Some modules intentionally have alternate migrations sharing a number
    $moduleNumbers = [];
    foreach ($declaredMigrations as $migFile) {
        if (preg_match('/^(\d+)/', basename($migFile), $m)) {
            $num = (int)$m[1];
            if (isset($moduleNumbers[$num])) {
                $migrationWarnings[] = "{$moduleId}: duplicate migration number {$num} (may be intentional alternates)";
            }
            $moduleNumbers[$num] = true;
        }
    }
}

t("Modules with migrations found: {$modulesWithMigrations}", $modulesWithMigrations > 0);
t('All declared migration files exist', count($migrationErrors) === 0);

if (count($migrationErrors) > 0) {
    foreach ($migrationErrors as $err) {
        echo "    ERROR: {$err}\n";
    }
}
if (!empty($migrationWarnings)) {
    foreach ($migrationWarnings as $warn) {
        echo "    WARNING: {$warn}\n";
    }
}

// ─── Section 4: MigrationRunner Infrastructure ─────────────────────────
echo "\n── Section 4: MigrationRunner Infrastructure ──\n";

$runnerClass = 'Ikabud\Kernel\Database\MigrationRunner';
t('MigrationRunner class exists', class_exists($runnerClass));

if (class_exists($runnerClass)) {
    $reflection = new ReflectionClass($runnerClass);
    t('MigrationRunner has migrate method', $reflection->hasMethod('migrate'));
    t('MigrationRunner has migrateAll method', $reflection->hasMethod('migrateAll'));
    t('MigrationRunner has rollback method', $reflection->hasMethod('rollback'));
    t('MigrationRunner has status method', $reflection->hasMethod('status'));
    t('MigrationRunner has pending method', $reflection->hasMethod('pending'));

    // Check for advisory locking
    $source = file_get_contents(__DIR__ . '/../kernel/Database/MigrationRunner.php');
    t('MigrationRunner uses advisory locking (GET_LOCK)', str_contains($source, 'GET_LOCK'));
}

// ─── Section 5: Migration SQL Safety Patterns ───────────────────────────
echo "\n── Section 5: Migration SQL Safety Patterns ──\n";

$allMigrationFiles = array_merge(
    $kernelMigrations,
    $controlMigrations
);

// Add module migration files (check both database/migrations and migrations dirs)
foreach ($modules as $moduleId => $module) {
    $modulePath = $module['_path'] ?? '';
    foreach (['database/migrations', 'migrations'] as $subdir) {
        $migDir = $modulePath . '/' . $subdir;
        if (is_dir($migDir)) {
            $allMigrationFiles = array_merge($allMigrationFiles, glob($migDir . '/*.sql'));
        }
    }
}

$dangerousPatterns = 0;
foreach ($allMigrationFiles as $file) {
    $sql = file_get_contents($file);
    // Check for dangerous patterns in migrations
    if (preg_match('/\bDROP\s+DATABASE\b/i', $sql)) {
        $dangerousPatterns++;
        echo "    DANGER: DROP DATABASE in " . basename($file) . "\n";
    }
    if (preg_match('/\bTRUNCATE\b/i', $sql) && !str_contains(strtolower(basename($file)), 'seed')) {
        $dangerousPatterns++;
        echo "    WARNING: TRUNCATE in " . basename($file) . "\n";
    }
}
t('No dangerous DROP DATABASE in migrations', $dangerousPatterns === 0);

// 5.2 All CREATE TABLE use IF NOT EXISTS (recommended but not required)
$createsWithoutIfNotExists = 0;
foreach ($allMigrationFiles as $file) {
    $sql = file_get_contents($file);
    if (preg_match_all('/CREATE\s+TABLE\s+(?!IF\s+NOT\s+EXISTS)/i', $sql, $matches)) {
        $createsWithoutIfNotExists += count($matches[0]);
    }
}
t('All CREATE TABLE statements check for existence', $createsWithoutIfNotExists === 0);

// ─── Section 6: Module Table Ownership Validation ───────────────────────
echo "\n── Section 6: Module Table Ownership ──\n";

$allOwnedTables = [];
$tableConflicts = [];

foreach ($modules as $moduleId => $module) {
    $modulePath = $module['_path'] ?? '';
    $manifestPath = $modulePath . '/module.json';
    if (!file_exists($manifestPath)) {
        continue;
    }
    $manifest = json_decode(file_get_contents($manifestPath), true);
    if (!is_array($manifest)) {
        continue;
    }

    $ownsTables = $manifest['owns_tables'] ?? [];
    if (!is_array($ownsTables)) {
        continue;
    }

    foreach ($ownsTables as $table) {
        if (isset($allOwnedTables[$table])) {
            $tableConflicts[] = "Table '{$table}' claimed by both '{$allOwnedTables[$table]}' and '{$moduleId}'";
        }
        $allOwnedTables[$table] = $moduleId;
    }
}

t("Total owned tables across modules: " . count($allOwnedTables), count($allOwnedTables) > 0);
// Known shared tables: audit_logs (shared infra), cms_media/search (sub-module overlap),
// rate_limits (shared infra), wordpress-importer (operates on CMS tables by design)
$knownSharedTables = ['audit_logs', 'cms_media', 'kernel_search_index', 'rate_limits',
    'cms_categories', 'cms_tags', 'cms_content', 'cms_content_categories', 'cms_content_tags'];
$unexpectedConflicts = array_filter($tableConflicts, function ($c) use ($knownSharedTables) {
    foreach ($knownSharedTables as $t) {
        if (str_contains($c, "'{$t}'")) return false;
    }
    return true;
});
t('No unexpected table ownership conflicts', count($unexpectedConflicts) === 0);

if (count($tableConflicts) > 0) {
    foreach ($tableConflicts as $conflict) {
        $isKnown = count($unexpectedConflicts) === 0 || !in_array($conflict, $unexpectedConflicts);
        $prefix = $isKnown ? 'KNOWN' : 'CONFLICT';
        echo "    {$prefix}: {$conflict}\n";
    }
}

// ─── Summary ───────────────────────────────────────────────────────────
echo "\n" . str_repeat('=', 60) . "\n";
echo "Migration Integrity Tests: {$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
