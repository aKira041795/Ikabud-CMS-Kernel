<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';

$pass = 0;
$fail = 0;
$skip = 0;
$errors = [];

function t(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $errors;

    if ($ok) {
        $pass++;
        echo "  ✓ {$label}\n";
        return;
    }

    $fail++;
    $errors[] = $label . ($detail !== '' ? ': ' . $detail : '');
    echo "  ✗ {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

function s(string $label, string $detail = ''): void
{
    global $skip;

    $skip++;
    echo "  - {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

function rrmdir(string $path): void
{
    if (!is_dir($path)) {
        @unlink($path);
        return;
    }

    $items = scandir($path) ?: [];
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $child = $path . '/' . $item;
        if (is_dir($child)) {
            rrmdir($child);
            continue;
        }

        @unlink($child);
    }

    @rmdir($path);
}

function shellWithExitCode(string $command): array
{
    $output = shell_exec($command . '; printf "\n__EXIT:%s" "$?"');
    $output = is_string($output) ? $output : '';
    $exitCode = 1;

    if (preg_match('/__EXIT:(\d+)\s*$/', $output, $matches)) {
        $exitCode = (int)($matches[1] ?? 1);
        $output = preg_replace('/\n?__EXIT:\d+\s*$/', '', $output) ?? $output;
    }

    return [
        'exit_code' => $exitCode,
        'output' => $output,
    ];
}

file_put_contents(STORAGE_PATH . '/logs/app.log', '');
file_put_contents(STORAGE_PATH . '/logs/error.log', '');

echo "\n=== CLI TENANT MIGRATION SYNC ===\n";

$targets = tenantSeparateDatabaseMigrationTargets();
if ($targets === []) {
    s('No separate tenant databases configured', 'Skipping CLI tenant migration sync regression');
    exit(0);
}

$target = null;
$tenantDb = null;
foreach ($targets as $candidate) {
    if (!is_array($candidate)) {
        continue;
    }

    $candidateTenantId = (int)($candidate['tenant_id'] ?? 0);
    if ($candidateTenantId <= 0) {
        continue;
    }

    $candidateDb = app()->dbForTenant($candidateTenantId);
    if ($candidateDb instanceof PDO) {
        $target = $candidate;
        $tenantDb = $candidateDb;
        break;
    }
}

if (!is_array($target) || !$tenantDb instanceof PDO) {
    s('No reachable separate tenant databases found', 'Skipping CLI tenant migration sync regression');
    exit(0);
}

$tenantId = (int)($target['tenant_id'] ?? 0);
t('separate tenant DB connection is available', $tenantDb instanceof PDO, json_encode($target));

if (!$tenantDb instanceof PDO) {
    exit(1);
}

$suffix = substr(bin2hex(random_bytes(4)), 0, 8);
$moduleId = 'cli-tenant-migrate-' . $suffix;
$tableName = 'cli_tenant_migrate_' . $suffix;
$moduleDir = BASE_PATH . '/modules/' . $moduleId;
$migrationDir = $moduleDir . '/migrations';
$migrationFile = $migrationDir . '/001_create_cli_tenant_sync_marker.sql';
$secondMigrationFile = $migrationDir . '/002_add_cli_tenant_sync_note.sql';
$noteColumn = 'sync_note';
$baseDb = app()->db();
$controlDb = app()->controlDb();
$entryModuleId = trim((string)($target['entry_module_id'] ?? ''));
$hookName = $entryModuleId !== '' ? ($entryModuleId . '.test.sync') : '';
$tempNoEntryTenantId = 0;
$tempNoEntryTenantKey = 'cli-tenant-no-entry-' . $suffix;

try {
    if (!is_dir($migrationDir) && !mkdir($migrationDir, 0775, true) && !is_dir($migrationDir)) {
        throw new RuntimeException('Failed to create temporary module migration directory.');
    }

    $manifest = [
        'id' => $moduleId,
        'name' => 'CLI Tenant Migration Sync Test',
        'version' => '0.0.1',
        'migrations' => [
            'migrations/001_create_cli_tenant_sync_marker.sql',
            'migrations/002_add_cli_tenant_sync_note.sql',
        ],
        'depends' => $entryModuleId !== '' ? [$entryModuleId] : [],
        'hooks' => $hookName !== '' ? [$hookName] : [],
    ];

    file_put_contents(
        $moduleDir . '/module.json',
        json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
    );

    $sql = "CREATE TABLE IF NOT EXISTS `{$tableName}` ("
        . "id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, "
        . "created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP"
        . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;\n";
    file_put_contents($migrationFile, $sql);

    @unlink(STORAGE_PATH . '/modules.json');

    $baseDb->exec('DROP TABLE IF EXISTS `' . $tableName . '`');
    $tenantDb->exec('DROP TABLE IF EXISTS `' . $tableName . '`');

    $baseDb->exec('CREATE TABLE IF NOT EXISTS `_migrations` ('
        . 'id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, '
        . 'module VARCHAR(80) NOT NULL, '
        . 'migration VARCHAR(255) NOT NULL, '
        . 'batch INT UNSIGNED NOT NULL DEFAULT 1, '
        . 'executed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, '
        . 'UNIQUE KEY uq_module_migration (module, migration)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    tenantEnsureMigrationTrackingTable($tenantDb);

    $deleteStmt = $baseDb->prepare('DELETE FROM `_migrations` WHERE module = :module');
    $deleteStmt->execute([':module' => $moduleId]);
    $deleteStmt = $tenantDb->prepare('DELETE FROM `_migrations` WHERE module = :module');
    $deleteStmt->execute([':module' => $moduleId]);

    $command = 'php ' . escapeshellarg(BASE_PATH . '/ikabud') . ' migrate ' . escapeshellarg($moduleId) . ' 2>&1';
    $result = shellWithExitCode($command);
    $output = trim((string)($result['output'] ?? ''));

    t('CLI migrate exits successfully', (int)($result['exit_code'] ?? 1) === 0, $output);
    t('CLI output mentions separate tenant sync', str_contains($output, 'Syncing separate tenant databases'), $output);
    t('CLI output lists the tenant being synced', str_contains($output, '#' . $tenantId), $output);

    $baseTableExists = $baseDb->query("SHOW TABLES LIKE '{$tableName}'")->fetchColumn() !== false;
    $tenantDb = app()->reconnectDbForTenant($tenantId) ?? $tenantDb;
    $tenantTableExists = $tenantDb->query("SHOW TABLES LIKE '{$tableName}'")->fetchColumn() !== false;

    t('temporary migration ran on the primary app DB', $baseTableExists);
    t('temporary migration ran on the separate tenant DB', $tenantTableExists);

    $baseRow = $baseDb->prepare('SELECT migration FROM `_migrations` WHERE module = :module LIMIT 1');
    $baseRow->execute([':module' => $moduleId]);
    $tenantRow = $tenantDb->prepare('SELECT migration FROM `_migrations` WHERE module = :module LIMIT 1');
    $tenantRow->execute([':module' => $moduleId]);

    t('primary DB recorded the migration', (string)$baseRow->fetchColumn() === '001_create_cli_tenant_sync_marker.sql');
    t('tenant DB recorded the migration', (string)$tenantRow->fetchColumn() === '001_create_cli_tenant_sync_marker.sql');

    $sql = "ALTER TABLE `{$tableName}` ADD COLUMN `{$noteColumn}` VARCHAR(50) DEFAULT NULL;\n";
    file_put_contents($secondMigrationFile, $sql);

    $baseDb->exec('DROP TABLE IF EXISTS `' . $tableName . '`');

    $command = 'php ' . escapeshellarg(BASE_PATH . '/ikabud') . ' migrate ' . escapeshellarg($moduleId) . ' 2>&1';
    $result = shellWithExitCode($command);
    $output = trim((string)($result['output'] ?? ''));

    t('CLI migrate succeeds when base DB has stale module migration history', (int)($result['exit_code'] ?? 1) === 0, $output);
    t('CLI output warns that the base DB module migration was skipped', str_contains($output, 'Base DB migration skipped'), $output);
    t('CLI output still mentions separate tenant sync after stale base history', str_contains($output, 'Syncing separate tenant databases'), $output);

    $baseTableExists = $baseDb->query("SHOW TABLES LIKE '{$tableName}'")->fetchColumn() !== false;
    $tenantDb = app()->reconnectDbForTenant($tenantId) ?? $tenantDb;
    $tenantColumnExists = $tenantDb->query("SHOW COLUMNS FROM `{$tableName}` LIKE '{$noteColumn}'")->fetchColumn() !== false;

    t('stale base DB table remains absent after migrate fallback', !$baseTableExists);
    t('second migration still runs on the separate tenant DB', $tenantColumnExists);

    $baseSecondRow = $baseDb->prepare('SELECT migration FROM `_migrations` WHERE module = :module AND migration = :migration LIMIT 1');
    $baseSecondRow->execute([':module' => $moduleId, ':migration' => '002_add_cli_tenant_sync_note.sql']);
    $tenantSecondRow = $tenantDb->prepare('SELECT migration FROM `_migrations` WHERE module = :module AND migration = :migration LIMIT 1');
    $tenantSecondRow->execute([':module' => $moduleId, ':migration' => '002_add_cli_tenant_sync_note.sql']);

    t('primary DB does not record the stale fallback migration', $baseSecondRow->fetchColumn() === false);
    t('tenant DB records the second migration after fallback', (string)$tenantSecondRow->fetchColumn() === '002_add_cli_tenant_sync_note.sql');

    $insertTenant = $controlDb->prepare(
        'INSERT INTO kernel_tenants (tenant_key, status, entry_module_id) VALUES (:tenant_key, :status, NULL)'
    );
    $insertTenant->execute([
        ':tenant_key' => $tempNoEntryTenantKey,
        ':status' => 'active',
    ]);
    $tempNoEntryTenantId = (int)$controlDb->lastInsertId();

    $insertTenantDb = $controlDb->prepare(
        'INSERT INTO kernel_tenant_db_connections '
        . '(tenant_id, db_driver, db_host, db_port, db_name, db_user, db_pass, db_charset) '
        . 'VALUES (:tenant_id, :db_driver, :db_host, :db_port, :db_name, :db_user, :db_pass, :db_charset)'
    );
    $insertTenantDb->execute([
        ':tenant_id' => $tempNoEntryTenantId,
        ':db_driver' => 'mysql',
        ':db_host' => 'localhost',
        ':db_port' => '3306',
        ':db_name' => 'cli_tenant_skip_' . $suffix,
        ':db_user' => 'root',
        ':db_pass' => '',
        ':db_charset' => 'utf8mb4',
    ]);

    $command = 'php ' . escapeshellarg(BASE_PATH . '/ikabud') . ' migrate 2>&1';
    $result = shellWithExitCode($command);
    $output = trim((string)($result['output'] ?? ''));

    t('generic CLI migrate exits successfully with a no-entry separate tenant present', (int)($result['exit_code'] ?? 1) === 0, $output);
    t('generic CLI migrate lists the no-entry tenant', str_contains($output, '#' . $tempNoEntryTenantId . ' ' . $tempNoEntryTenantKey), $output);
    t('generic CLI migrate skips no-entry tenants without connection warnings', str_contains($output, 'No entry module; skipping tenant DB sync.'), $output);

    $appLog = @file_get_contents(STORAGE_PATH . '/logs/app.log') ?: '';
    $errorLog = @file_get_contents(STORAGE_PATH . '/logs/error.log') ?: '';
    t('no app.log critical errors during CLI sync', !str_contains($appLog, '[critical]'), trim($appLog));
    t('no PHP errors in error.log during CLI sync', trim($errorLog) === '', trim($errorLog));
} finally {
    try {
        $baseDb->exec('DROP TABLE IF EXISTS `' . $tableName . '`');
    } catch (Throwable $ignored) {
    }

    try {
        $tenantDb = app()->reconnectDbForTenant($tenantId) ?? $tenantDb;
        if ($tenantDb instanceof PDO) {
            $tenantDb->exec('DROP TABLE IF EXISTS `' . $tableName . '`');
            $stmt = $tenantDb->prepare('DELETE FROM `_migrations` WHERE module = :module');
            $stmt->execute([':module' => $moduleId]);
        }
    } catch (Throwable $ignored) {
    }

    try {
        $stmt = $baseDb->prepare('DELETE FROM `_migrations` WHERE module = :module');
        $stmt->execute([':module' => $moduleId]);
    } catch (Throwable $ignored) {
    }

    try {
        if ($tempNoEntryTenantId > 0) {
            $stmt = $controlDb->prepare('DELETE FROM kernel_tenant_db_connections WHERE tenant_id = :tenant_id');
            $stmt->execute([':tenant_id' => $tempNoEntryTenantId]);

            $stmt = $controlDb->prepare('DELETE FROM kernel_tenants WHERE id = :tenant_id');
            $stmt->execute([':tenant_id' => $tempNoEntryTenantId]);
        }
    } catch (Throwable $ignored) {
    }

    rrmdir($moduleDir);
    unset($GLOBALS['_kernel_discovered_modules']);
}

echo "\n══════════════════════════════════════════════════\n";
echo "  PASS: {$pass}  FAIL: {$fail}  SKIP: {$skip}\n";
echo "══════════════════════════════════════════════════\n";

if ($errors !== []) {
    echo "\nFailed tests:\n";
    foreach ($errors as $error) {
        echo "  - {$error}\n";
    }
}

exit($fail > 0 ? 1 : 0);