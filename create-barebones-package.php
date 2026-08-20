#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Ikabud — Bare-Bones Kernel + DiSyL Installation Package
 *
 * Produces a single ZIP "installation package" in the style of the Bluehost
 * upgrade kit, but containing ONLY the Kernel OS + DiSyL engine. No modules.
 *
 * The package contains:
 *   - application-kernel-barebones-<ts>.zip   (kernel + DiSyL code archive)
 *   - db/app-install.sql                      (base schema + kernel migrations)
 *   - db/control-install.sql                  (control-plane migrations)
 *   - db/tenant-install.sql                   (tenant-safe kernel migrations)
 *   - README-BAREBONES.txt
 *
 * Usage:
 *   php create-barebones-package.php [output-filename.zip]
 *
 * Default output: kernel-barebones-install-YYYYMMDD-HHmmss.zip in the project root.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

if (!class_exists('ZipArchive')) {
    fwrite(STDERR, "Error: ZipArchive extension is required.\n");
    exit(1);
}

$root = realpath(__DIR__);
if ($root === false) {
    fwrite(STDERR, "Error: Cannot resolve project root.\n");
    exit(1);
}

$timestamp = date('Ymd-His');
$outputName = $argv[1] ?? ('kernel-barebones-install-' . $timestamp . '.zip');
$outputPath = str_starts_with($outputName, '/') ? $outputName : ($root . '/' . $outputName);

$tempRoot = rtrim(sys_get_temp_dir(), '/') . '/ikabud-barebones-' . bin2hex(random_bytes(4));
$packageRoot = $tempRoot . '/package';
$dbDir = $packageRoot . '/db';

$mkdirs = [$tempRoot, $packageRoot, $dbDir];
foreach ($mkdirs as $dir) {
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        fwrite(STDERR, "Error: Cannot create directory {$dir}\n");
        exit(1);
    }
}

echo "Building bare-bones kernel + DiSyL installation package...\n";

$codeArchiveName = 'application-kernel-barebones-' . $timestamp . '.zip';
$codeArchivePath = $tempRoot . '/' . $codeArchiveName;
$archiveCmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/create-barebones-archive.php') . ' ' . escapeshellarg($codeArchivePath);
passthru($archiveCmd, $archiveExitCode);
if ($archiveExitCode !== 0 || !is_file($codeArchivePath)) {
    fwrite(STDERR, "Error: Failed to create bare-bones code archive.\n");
    exit(1);
}

// ── SQL bundles (kernel only — no module migrations) ─────────────────────
// app-install mirrors `php ikabud migrate:base` + `php ikabud migrate`:
//   base app schema (database/migrations) minus the destructive fresh-install
//   script and the late module-table hardening, plus kernel migrations (migrations/).
$appSqlFiles = array_merge(
    collectMigrationFiles($root, 'database/migrations', [
        '004_bluehost_install_no_create_db.sql',
        'bluehost-username-case-sensitive.sql',
    ]),
    collectMigrationFiles($root, 'migrations')
);

// control-install mirrors `php ikabud migrate:control`.
$controlSqlFiles = collectMigrationFiles($root, 'control-migrations');

// tenant-install: tenant-safe kernel migrations for separate tenant databases.
$tenantSqlFiles = [
    'migrations/001_kernel_events_and_triggers.sql',
    'database/migrations/006_kernel_workflow_tables.sql',
    'database/migrations/007_kernel_runtime_tables.sql',
    'database/migrations/009_add_superadmin_role.sql',
];

$appSqlPath = $dbDir . '/app-install.sql';
$controlSqlPath = $dbDir . '/control-install.sql';
$tenantSqlPath = $dbDir . '/tenant-install.sql';

file_put_contents($appSqlPath, buildSqlBundle($root, $appSqlFiles, 'Application DB install bundle (bare-bones kernel)'));
file_put_contents($controlSqlPath, buildSqlBundle($root, $controlSqlFiles, 'Control DB install bundle (bare-bones kernel)'));
file_put_contents($tenantSqlPath, buildSqlBundle($root, $tenantSqlFiles, 'Tenant DB install bundle (bare-bones kernel)'));

$readme = buildReadme($codeArchiveName);
file_put_contents($packageRoot . '/README-BAREBONES.txt', $readme);

copy($codeArchivePath, $packageRoot . '/' . $codeArchiveName);

$zip = new ZipArchive();
$openResult = $zip->open($outputPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
if ($openResult !== true) {
    fwrite(STDERR, "Error: Could not create output zip (code {$openResult}).\n");
    exit(1);
}

addDirectoryToZip($zip, $packageRoot, $packageRoot);
$zip->close();

echo "Bare-bones installation package created: {$outputPath}\n";
echo "Included files:\n";
echo "  - {$codeArchiveName}\n";
echo "  - db/app-install.sql\n";
echo "  - db/control-install.sql\n";
echo "  - db/tenant-install.sql\n";
echo "  - README-BAREBONES.txt\n";

deleteTree($tempRoot);
exit(0);

/**
 * Collect .sql files from a single kernel migration directory.
 *
 * @param array<int, string> $excludeBasenames Basenames to skip (e.g. destructive scripts).
 * @return array<int, string> Relative paths like 'migrations/001_...sql'.
 */
function collectMigrationFiles(string $root, string $dir, array $excludeBasenames = []): array
{
    $full = $root . '/' . ltrim($dir, '/');
    if (!is_dir($full)) {
        return [];
    }

    $files = [];
    foreach ((scandir($full) ?: []) as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        if (!str_ends_with((string) $entry, '.sql')) {
            continue;
        }
        if (in_array($entry, $excludeBasenames, true)) {
            continue;
        }
        $files[] = $dir . '/' . $entry;
    }

    sort($files);
    return array_values(array_unique($files));
}

function buildSqlBundle(string $root, array $relativePaths, string $title): string
{
    $parts = [];
    $parts[] = '-- ============================================================';
    $parts[] = '-- ' . $title;
    $parts[] = '-- Generated: ' . gmdate('c');
    $parts[] = '-- Purpose: fresh-install import to create the kernel schema.';
    $parts[] = '-- This bundle is additive (CREATE TABLE IF NOT EXISTS) and';
    $parts[] = '-- preserves existing rows where present. Review before import.';
    $parts[] = '-- ============================================================';
    $parts[] = 'SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";';
    $parts[] = 'SET FOREIGN_KEY_CHECKS = 0;';
    $parts[] = '';

    foreach ($relativePaths as $relativePath) {
        $fullPath = $root . '/' . ltrim($relativePath, '/');
        if (!is_file($fullPath)) {
            continue;
        }
        $sql = trim((string) file_get_contents($fullPath));
        if ($sql === '') {
            continue;
        }
        $sql = normalizeSqlSnippet($sql);
        $parts[] = '-- ------------------------------------------------------------';
        $parts[] = '-- Source: ' . $relativePath;
        $parts[] = '-- ------------------------------------------------------------';
        $parts[] = $sql;
        $parts[] = '';
    }

    $parts[] = 'SET FOREIGN_KEY_CHECKS = 1;';
    $parts[] = '';
    return implode("\n", $parts) . "\n";
}

function normalizeSqlSnippet(string $sql): string
{
    $sql = rtrim($sql);
    if ($sql === '') {
        return $sql;
    }

    $guardedAddColumns = buildGuardedAddColumnSql($sql);
    if ($guardedAddColumns !== null) {
        return $guardedAddColumns;
    }

    if (!preg_match('/;\s*$/', $sql)) {
        $sql .= ';';
    }

    return $sql;
}

function buildGuardedAddColumnSql(string $sql): ?string
{
    $trimmed = trim($sql);
    if (!preg_match('/^ALTER\s+TABLE\s+(`?[A-Za-z0-9_]+`?)\s+(.+)$/is', $trimmed, $matches)) {
        return null;
    }

    $tableExpr = trim((string) $matches[1]);
    $tableName = trim($tableExpr, '`');
    $operations = rtrim(trim((string) $matches[2]), ';');
    $clauses = splitSqlClauses($operations);
    if ($clauses === []) {
        return null;
    }

    $guarded = [];
    foreach ($clauses as $clause) {
        $clause = trim($clause);
        if (!preg_match('/^ADD\s+COLUMN\s+(`?[A-Za-z0-9_]+`?)/i', $clause, $columnMatches)) {
            return null;
        }

        $columnExpr = trim((string) $columnMatches[1]);
        $columnName = trim($columnExpr, '`');
        $escapedTableName = str_replace("'", "''", $tableName);
        $escapedColumnName = str_replace("'", "''", $columnName);
        $escapedClause = str_replace("'", "''", $clause);
        $guarded[] = implode("\n", [
            'SET @col_exists := (',
            '    SELECT COUNT(*) FROM information_schema.columns',
            "    WHERE table_schema = DATABASE() AND table_name = '{$escapedTableName}' AND column_name = '{$escapedColumnName}'",
            ');',
            "SET @sql := IF(@col_exists = 0, 'ALTER TABLE {$tableExpr} {$escapedClause}', 'SELECT 1');",
            'PREPARE stmt FROM @sql;',
            'EXECUTE stmt;',
            'DEALLOCATE PREPARE stmt;',
        ]);
    }

    return implode("\n\n", $guarded);
}

function splitSqlClauses(string $sql): array
{
    $clauses = [];
    $buffer = '';
    $depth = 0;
    $inSingleQuote = false;
    $inDoubleQuote = false;
    $length = strlen($sql);

    for ($index = 0; $index < $length; $index++) {
        $char = $sql[$index];
        $prev = $index > 0 ? $sql[$index - 1] : '';

        if ($char === "'" && !$inDoubleQuote && $prev !== '\\') {
            $inSingleQuote = !$inSingleQuote;
            $buffer .= $char;
            continue;
        }
        if ($char === '"' && !$inSingleQuote && $prev !== '\\') {
            $inDoubleQuote = !$inDoubleQuote;
            $buffer .= $char;
            continue;
        }
        if (!$inSingleQuote && !$inDoubleQuote) {
            if ($char === '(') {
                $depth++;
            } elseif ($char === ')' && $depth > 0) {
                $depth--;
            } elseif ($char === ',' && $depth === 0) {
                $clause = trim($buffer);
                if ($clause !== '') {
                    $clauses[] = $clause;
                }
                $buffer = '';
                continue;
            }
        }

        $buffer .= $char;
    }

    $tail = trim($buffer);
    if ($tail !== '') {
        $clauses[] = $tail;
    }

    return $clauses;
}

function buildReadme(string $codeArchiveName): string
{
    return implode("\n", [
        'Ikabud Kernel OS — Bare-Bones Installation Package (Kernel + DiSyL only)',
        '=======================================================================',
        '',
        'This package contains ONLY the Kernel OS and the DiSyL template engine.',
        'No application modules are included, EXCEPT the bundled GUI companion',
        'module (gui-settings) which ships with the bare kernel.',
        '',
        'Contents',
        '--------',
        '- ' . $codeArchiveName . ' : code files to upload/extract over a fresh web root',
        '- db/app-install.sql      : import into the primary application database',
        '- db/control-install.sql  : import into the control database when multi-tenant mode is enabled',
        '- db/tenant-install.sql   : import into each tenant database when tenants use separate databases',
        '',
        'Recommended order (fresh install)',
        '---------------------------------',
        '1. Create an empty database + DB user via cPanel and grant ALL privileges.',
        '2. Upload/extract ' . $codeArchiveName . ' into the web root (public_html/).',
        '3. Copy .env.example to .env and set DB credentials (or run the installer).',
        '4. Import db/app-install.sql into the application DB (phpMyAdmin or CLI).',
        '5. If multi-tenant is enabled, import db/control-install.sql into the control DB.',
        '6. If tenants use separate databases, import db/tenant-install.sql into each tenant DB.',
        '7. If Terminal/SSH is available, run instead:',
        '       php ikabud migrate:base   (base kernel schema)',
        '       php ikabud migrate        (kernel migrations)',
        '       php ikabud migrate:control (control-plane migrations)',
        '8. Navigate to https://yourdomain.com/lock.php once to create the admin user',
        '   and finalize .env, then DELETE public/lock.php (security requirement).',
        '9. Test the kernel host, then review storage/logs/app.log and storage/logs/error.log.',
        '',
        'What is NOT included',
        '--------------------',
        '- modules/ (all application modules, except the bundled gui-settings companion)',
        '- Module templates (templates/modules/ and module template folders)',
        '- Module migrations (modules/*/database/migrations and modules/*/migrations)',
        '- Module-owned web assets (public/admin, public/assets/<module>, public/uploads, ...)',
        '- database/seeds/ (seed data references module tables; the installer creates the admin)',
        '- scripts/, packages/, docs/, tests/, android/',
        '',
        'Bundled GUI companion',
        '---------------------',
        '- modules/gui-settings/ (GUI Settings) ships with the bare kernel.',
        '- Enable it from Admin -> Modules after install. It owns no tables',
        '  and requires no migrations.',
        '',
        'Adding modules later',
        '--------------------',
        '1. Copy the module folder into modules/.',
        '2. Run:  php ikabud migrate <module-id>   (creates its tables)',
        '3. Run:  php ikabud module:enable <module-id>',
        '4. Refresh storage/logs and test the module host.',
        '',
        'Notes',
        '-----',
        '- The bundled base schema (db/app-install.sql) is the historical kernel base',
        '  schema. It also creates a few legacy tables (e.g. branches, products) that',
        '  predate the module system; they are empty and harmless on a bare kernel.',
        '- Do not replace the live .env with .env.example.',
        '- Do not rerun public/lock.php on an already-installed production install.',
        '',
    ]) . "\n";
}

function addDirectoryToZip(ZipArchive $zip, string $directory, string $basePath): void
{
    $items = scandir($directory) ?: [];
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $fullPath = $directory . '/' . $item;
        $relativePath = ltrim(str_replace($basePath, '', $fullPath), '/');
        if (is_dir($fullPath)) {
            $zip->addEmptyDir($relativePath);
            addDirectoryToZip($zip, $fullPath, $basePath);
            continue;
        }
        $zip->addFile($fullPath, $relativePath);
    }
}

function deleteTree(string $path): void
{
    if (!file_exists($path)) {
        return;
    }
    if (is_file($path) || is_link($path)) {
        @unlink($path);
        return;
    }
    $items = scandir($path) ?: [];
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        deleteTree($path . '/' . $item);
    }
    @rmdir($path);
}
