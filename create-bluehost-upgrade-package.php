#!/usr/bin/env php
<?php

declare(strict_types=1);

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
$outputName = $argv[1] ?? ('bluehost-upgrade-kit-' . $timestamp . '.zip');
$outputPath = str_starts_with($outputName, '/') ? $outputName : ($root . '/' . $outputName);

$tempRoot = rtrim(sys_get_temp_dir(), '/') . '/ikabud-bluehost-upgrade-' . bin2hex(random_bytes(4));
$packageRoot = $tempRoot . '/package';
$dbDir = $packageRoot . '/db';

$mkdirs = [$tempRoot, $packageRoot, $dbDir];
foreach ($mkdirs as $dir) {
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        fwrite(STDERR, "Error: Cannot create directory {$dir}\n");
        exit(1);
    }
}

echo "Building Bluehost upgrade kit...\n";

$codeArchiveName = 'application-kernel-os-' . $timestamp . '.zip';
$codeArchivePath = $tempRoot . '/' . $codeArchiveName;
$archiveCmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/create-bluehost-archive.php') . ' ' . escapeshellarg($codeArchivePath);
passthru($archiveCmd, $archiveExitCode);
if ($archiveExitCode !== 0 || !is_file($codeArchivePath)) {
    fwrite(STDERR, "Error: Failed to create deployment archive.\n");
    exit(1);
}

// ── An upgrade must not overwrite the document root's .htaccess ──────────
// cPanel's MultiPHP Manager stores its PHP selection as an AddHandler line inside the document
// root's .htaccess. This kit ships a root .htaccess (rewrites + deny rules, no handler), so
// extracting it over a live install REMOVES cPanel's line and the site silently drops back to the
// account's default PHP version.
//
// Measured 2026-10-09: after a deploy the live host served 8.3.35 while cPanel still showed 8.5 —
// which is only possible if something inside the docroot overrode it. The cost was not subtle: the
// 8.3 profile caps OPcache at 7,963 keys / 128 MB and it sat FULL at 128/0, so the next request
// compiled 954 scripts and dispatch went 46.55 -> 138.16 ms. Re-selecting 8.5 in cPanel restored
// it, which is why this is fixed here rather than documented as a quirk.
//
// A FRESH install still needs the file (it carries the rewrite to public/), so
// create-bluehost-archive.php keeps shipping it. Only the upgrade path drops it.
$stripHtaccess = new ZipArchive();
if ($stripHtaccess->open($codeArchivePath) === true) {
    if ($stripHtaccess->locateName('.htaccess') !== false) {
        $stripHtaccess->deleteName('.htaccess');
        echo "  [strip] root .htaccess excluded — it is host-owned (see README-UPGRADE.txt)\n";
    }

    // Stamp WHICH build this package is, inside the package. Without it, "did the
    // deploy land?" is unanswerable from outside: twice on 2026-10-10 a deploy could
    // only be confirmed by logging in as the right role and observing a fix's effect,
    // and the two PHP-only commits that day left no outward marker at all. The
    // SecurityHeaders class emits this as X-App-Build, so one unauthenticated request
    // answers it. Written into the archive rather than the working tree so the repo
    // stays clean and a plain checkout simply has no marker.
    $commitSha = trim((string)@shell_exec('git -C ' . escapeshellarg($root) . ' rev-parse --short=12 HEAD 2>/dev/null'));
    if ($commitSha === '') {
        echo "  [build] git sha unavailable — X-App-Build will be omitted from this package\n";
    } else {
        $stripHtaccess->addFromString('config/build-info.json', json_encode([
            'commit' => $commitSha,
            'built_at' => gmdate('Ymd\THis\Z'),
            'package' => $codeArchiveName,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        echo "  [build] stamped config/build-info.json -> commit {$commitSha}\n";
    }

    $stripHtaccess->close();
} else {
    fwrite(STDERR, "Warning: could not re-open {$codeArchiveName} to exclude .htaccess.\n");
}

$appSqlFiles = [
    'migrations/001_kernel_events_and_triggers.sql',
    'migrations/004_remove_legacy_kernel_roles.sql',
    'migrations/005_add_update_catalog_tables.sql',
    'database/migrations/006_kernel_workflow_tables.sql',
    'database/migrations/007_kernel_runtime_tables.sql',
    'database/migrations/007_tenant_module_settings.sql',
    'database/migrations/008_drop_legacy_tables.sql',
    'database/migrations/009_add_superadmin_role.sql',
];

$controlSqlFiles = [
    'control-migrations/001_control_plane_tenants.sql',
    'control-migrations/002_control_plane_encrypt_db_pass.sql',
    'control-migrations/003_add_canonical_domain_to_tenants.sql',
    'control-migrations/004_control_plane_module_catalog.sql',
    'control-migrations/005_control_plane_module_access_requests.sql',
];

$moduleMigrationFiles = collectSqlFiles($root . '/modules', [
    '/database/migrations',
    '/migrations',
]);

$appSqlFiles = array_merge($appSqlFiles, $moduleMigrationFiles);

$tenantSqlFiles = [
    'migrations/001_kernel_events_and_triggers.sql',
    'database/migrations/006_kernel_workflow_tables.sql',
    'database/migrations/007_kernel_runtime_tables.sql',
    'database/migrations/009_add_superadmin_role.sql',
];
$tenantSqlFiles = array_merge($tenantSqlFiles, $moduleMigrationFiles);

$appSqlPath = $dbDir . '/app-upgrade.sql';
$controlSqlPath = $dbDir . '/control-upgrade.sql';
$tenantSqlPath = $dbDir . '/tenant-upgrade.sql';

file_put_contents($appSqlPath, buildSqlBundle($root, $appSqlFiles, 'Application DB upgrade bundle'));
file_put_contents($controlSqlPath, buildSqlBundle($root, $controlSqlFiles, 'Control DB upgrade bundle'));
file_put_contents($tenantSqlPath, buildSqlBundle($root, $tenantSqlFiles, 'Tenant DB upgrade bundle'));

$readme = buildReadme($codeArchiveName);
file_put_contents($packageRoot . '/README-UPGRADE.txt', $readme);

copy($codeArchivePath, $packageRoot . '/' . $codeArchiveName);

$zip = new ZipArchive();
$openResult = $zip->open($outputPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
if ($openResult !== true) {
    fwrite(STDERR, "Error: Could not create output zip (code {$openResult}).\n");
    exit(1);
}

addDirectoryToZip($zip, $packageRoot, $packageRoot);
$zip->close();

echo "Upgrade kit created: {$outputPath}\n";
echo "Included files:\n";
echo "  - {$codeArchiveName}\n";
echo "  - db/app-upgrade.sql\n";
echo "  - db/control-upgrade.sql\n";
echo "  - db/tenant-upgrade.sql\n";
echo "  - README-UPGRADE.txt\n";

deleteTree($tempRoot);
exit(0);

function collectSqlFiles(string $modulesRoot, array $suffixes): array
{
    if (!is_dir($modulesRoot)) {
        return [];
    }

    $files = [];
    $modules = scandir($modulesRoot) ?: [];
    foreach ($modules as $module) {
        if ($module === '.' || $module === '..') {
            continue;
        }
        if (preg_match('/\.bak(?:_|$)/i', (string) $module) === 1) {
            continue;
        }
        $modulePath = $modulesRoot . '/' . $module;
        if (!is_dir($modulePath)) {
            continue;
        }
        foreach ($suffixes as $suffix) {
            $dir = $modulePath . $suffix;
            if (!is_dir($dir)) {
                continue;
            }
            $entries = scandir($dir) ?: [];
            sort($entries);
            foreach ($entries as $entry) {
                if (!str_ends_with((string) $entry, '.sql')) {
                    continue;
                }
                $relative = ltrim(str_replace($modulesRoot . '/', 'modules/', $dir . '/' . $entry), '/');
                $files[] = $relative;
            }
        }
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
    $parts[] = '-- Purpose: additive Bluehost import to create missing tables,';
    $parts[] = '-- add newer columns/indexes, and preserve existing rows.';
    $parts[] = '-- Review before import. Some bundled reconciliations may drop';
    $parts[] = '-- obsolete legacy tables only after replacement tables exist.';
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
        'Ikabud Kernel OS - Bluehost Upgrade Kit',
        '========================================',
        '',
        'Contents',
        '--------',
        '- ' . $codeArchiveName . ' : code files to upload/extract over the existing install',
        '- db/app-upgrade.sql      : import into the primary application database',
        '- db/control-upgrade.sql  : import into the control database when multi-tenant mode is enabled',
        '- db/tenant-upgrade.sql   : import into each tenant database when tenants use separate databases',
        '',
        'Recommended order',
        '-----------------',
        'Before creating this package, run npm run build:tailwind:app and commit public/assets/tailwind/app.css.',
        '1. Back up files and all databases first.',
        '2. Import db/app-upgrade.sql into the live application DB.',
        '3. If multi-tenant is enabled, import db/control-upgrade.sql into the control DB.',
        '4. If tenants use separate databases, import db/tenant-upgrade.sql into each tenant DB.',
        '5. Upload/extract ' . $codeArchiveName . ' over the existing codebase.',
        '6. Preserve the live .env, storage/, and public/uploads/ directories.',
        '7. If Terminal/SSH is available, run php ikabud migrate and php ikabud migrate:control after deploy.',
        '8. Warm OPcache, once per host, immediately after the files land:',
        '     php scripts/warm-opcache.php --base=https://your-host.example.com',
        '   Do not skip this. Any PHP restart - this deploy, a host reboot, a PHP upgrade, an idle-pool',
        '   recycle - empties OPcache, and the next visitor then pays to have the framework recompiled',
        '   from source: measured 227.64 ms cold against 61.68 ms warm on identical code, a 3.7x',
        '   difference. That cost is currently paid by whichever customer arrives first.',
        '9. Test the kernel host and each tenant host, then review storage/logs/app.log and storage/logs/error.log.',
        '',
        'Notes',
        '-----',
        '- These SQL bundles are additive and intended to preserve existing rows.',
        '- Some legacy-reconciliation migrations may remove obsolete tables only after data is backfilled into canonical replacements.',
        '- Do not rerun public/lock.php as an upgrade path for an existing production install.',
        '- Do not replace the live .env with .env.example.',
        '- The root .htaccess is NOT in this kit, on purpose. cPanel stores its PHP version choice as an',
        '  AddHandler line inside it, so shipping the file would silently revert the site to the account',
        '  default PHP (measured 2026-10-09: 8.5 -> 8.3, which left OPcache full at 128 MB with 7,963',
        '  keys and cost 954 compiled scripts and 138 ms of dispatch on the next request). Do not copy it',
        '  by hand either. If the site is on the wrong PHP version after an upgrade, re-apply the version',
        '  in cPanel and confirm the probe reports the version you expect.',
        '- OPcache belongs to the PHP pool, not to the site, so run the warm-up once per distinct host. The',
        '  cache is shared by every worker in that pool, so one pass is enough - and warming from the CLI',
        '  would not help at all, which is why scripts/warm-opcache.php issues real HTTP requests.',
        '- Optional speed-up: appending DISYL_EXTENDS_COMPILED=1 to the live .env compiles {extends}-based',
        '  templates instead of rendering them on the interpreted pipeline (measured 1436ms -> 510ms on the',
        '  daily-ledger cashier ledger). It must be .env, since bootstrap.php loads it per request. After',
        '  enabling, confirm no disyl.compile.fallback lines appear in storage/logs/app.log; remove the line',
        '  to revert. Full detail: docs/kernel/disyl-development-workflow.md.',
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