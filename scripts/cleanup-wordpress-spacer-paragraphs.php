#!/usr/bin/env php
<?php

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

$basePath = dirname(__DIR__);
require_once $basePath . '/src/helpers/cli-bootstrap.php';

try {
    $app = kernelCliBootstrap($basePath);
    require_once $basePath . '/modules/cms/helpers.php';
    require_once $basePath . '/modules/tinymce/helpers.php';
    kernelCliRequireFunctions([
        'cmsEditorNormalizeHtml',
        'cmsEditorSanitizeHtml',
        'tinymceNormalizeHtml',
        'tinymceSanitizeHtml',
    ]);
} catch (Throwable $e) {
    fwrite(STDERR, 'Bootstrap failed: ' . $e->getMessage() . "\n");
    exit(1);
}

/**
 * @return array{tenantId:?int, allTenants:bool, apply:bool, batchSize:int, types:array<int, string>, help:bool}
 */
function cleanupScriptOptions(array $argv): array
{
    $options = [
        'tenantId' => null,
        'allTenants' => false,
        'apply' => false,
        'batchSize' => 200,
        'types' => ['post', 'page'],
        'help' => false,
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--help' || $arg === '-h') {
            $options['help'] = true;
            continue;
        }

        if ($arg === '--all-tenants') {
            $options['allTenants'] = true;
            continue;
        }

        if ($arg === '--apply') {
            $options['apply'] = true;
            continue;
        }

        if (str_starts_with($arg, '--tenant=')) {
            $tenantId = (int)substr($arg, strlen('--tenant='));
            if ($tenantId > 0) {
                $options['tenantId'] = $tenantId;
            }
            continue;
        }

        if (str_starts_with($arg, '--batch-size=')) {
            $batchSize = (int)substr($arg, strlen('--batch-size='));
            if ($batchSize > 0) {
                $options['batchSize'] = $batchSize;
            }
            continue;
        }

        if (str_starts_with($arg, '--types=')) {
            $types = array_values(array_filter(array_map(
                static fn(string $value): string => trim($value),
                explode(',', substr($arg, strlen('--types=')))
            )));
            if ($types !== []) {
                $options['types'] = $types;
            }
        }
    }

    return $options;
}

function cleanupScriptUsage(): string
{
    return implode("\n", [
        'Usage:',
        '  php scripts/cleanup-wordpress-spacer-paragraphs.php --tenant=TENANT_ID [--apply]',
        '  php scripts/cleanup-wordpress-spacer-paragraphs.php --all-tenants [--apply]',
        '',
        'Options:',
        '  --tenant=TENANT_ID  Clean a single tenant database by control-plane tenant ID.',
        '  --all-tenants       Scan all active tenants with configured DB connections.',
        '  --apply             Persist changes. Without this flag, the script runs in dry-run mode.',
        '  --batch-size=N      Scan rows in chunks. Default: 200.',
        '  --types=a,b         Content types to scan. Default: post,page.',
        '  --help              Show this help text.',
        '',
        'Notes:',
        '  - This script removes WordPress-style spacer paragraphs like <p>&nbsp;</p> and <p><br></p>.',
        '  - It uses the same CMS/TinyMCE normalization path as the import/save fix.',
    ]) . "\n";
}

/**
 * @return array<string, string>
 */
function cleanupScriptDotEnvValues(): array
{
    static $values = null;
    if (is_array($values)) {
        return $values;
    }

    $values = [];
    $envPath = dirname(__DIR__) . '/.env';
    if (!is_file($envPath)) {
        return $values;
    }

    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if ($key === '') {
            continue;
        }

        if (strlen($value) >= 2) {
            $first = $value[0];
            $last = $value[strlen($value) - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $value = substr($value, 1, -1);
                $value = str_replace(['\\"', "\\'", '\\\\'], ['"', "'", '\\'], $value);
            }
        }

        $values[$key] = $value;
    }

    return $values;
}

function cleanupScriptEnvValue(string $key, ?string $fallback = null): ?string
{
    $envValue = $_ENV[$key] ?? getenv($key);
    if (is_string($envValue) && trim($envValue) !== '') {
        return trim($envValue);
    }

    $fileValues = cleanupScriptDotEnvValues();
    if (isset($fileValues[$key]) && trim($fileValues[$key]) !== '') {
        return trim($fileValues[$key]);
    }

    return $fallback;
}

/**
 * @return array{driver:string,host:string,port:string,database:string,username:string,password:string,charset:string}
 */
function cleanupScriptControlDbConfig(): array
{
    return [
        'driver' => cleanupScriptEnvValue('CONTROL_DB_DRIVER', cleanupScriptEnvValue('DB_DRIVER', 'mysql')) ?? 'mysql',
        'host' => cleanupScriptEnvValue('CONTROL_DB_HOST', cleanupScriptEnvValue('DB_HOST', '127.0.0.1')) ?? '127.0.0.1',
        'port' => cleanupScriptEnvValue('CONTROL_DB_PORT', cleanupScriptEnvValue('DB_PORT', '3306')) ?? '3306',
        'database' => cleanupScriptEnvValue('CONTROL_DB_DATABASE', cleanupScriptEnvValue('DB_DATABASE', '')) ?? '',
        'username' => cleanupScriptEnvValue('CONTROL_DB_USERNAME', cleanupScriptEnvValue('DB_USERNAME', '')) ?? '',
        'password' => cleanupScriptEnvValue('CONTROL_DB_PASSWORD', cleanupScriptEnvValue('DB_PASSWORD', '')) ?? '',
        'charset' => cleanupScriptEnvValue('CONTROL_DB_COLLATION', cleanupScriptEnvValue('DB_COLLATION', 'utf8mb4_unicode_ci')) !== null
            ? (cleanupScriptEnvValue('CONTROL_DB_CHARSET', cleanupScriptEnvValue('DB_CHARSET', 'utf8mb4')) ?? 'utf8mb4')
            : 'utf8mb4',
    ];
}

function cleanupScriptOpenPdo(array $config): PDO
{
    $dsn = sprintf(
        '%s:host=%s;port=%s;dbname=%s;charset=%s',
        $config['driver'],
        $config['host'],
        $config['port'],
        $config['database'],
        $config['charset']
    );

    return new PDO($dsn, $config['username'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

function cleanupScriptControlDb(): PDO
{
    static $controlDb = null;
    if ($controlDb instanceof PDO) {
        return $controlDb;
    }

    $config = cleanupScriptControlDbConfig();
    $controlDb = cleanupScriptOpenPdo($config);
    return $controlDb;
}

/**
 * @return array<int, array{id:int, tenant_key:string, status:string}>
 */
function cleanupScriptResolveTargets(PDO $controlDb, array $options): array
{
    if (!empty($options['tenantId'])) {
        $stmt = $controlDb->prepare(
            'SELECT t.id, t.tenant_key, t.status\n'
            . 'FROM kernel_tenants t\n'
            . 'INNER JOIN kernel_tenant_db_connections c ON c.tenant_id = t.id\n'
            . 'WHERE t.id = :tenant_id\n'
            . 'LIMIT 1'
        );
        $stmt->execute([':tenant_id' => (int)$options['tenantId']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? [[
            'id' => (int)$row['id'],
            'tenant_key' => (string)$row['tenant_key'],
            'status' => (string)$row['status'],
        ]] : [];
    }

    if (!empty($options['allTenants'])) {
        $stmt = $controlDb->query(
            "SELECT t.id, t.tenant_key, t.status\n"
            . "FROM kernel_tenants t\n"
            . "INNER JOIN kernel_tenant_db_connections c ON c.tenant_id = t.id\n"
            . "WHERE t.status = 'active'\n"
            . "ORDER BY t.id ASC"
        );

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map(static function (array $row): array {
            return [
                'id' => (int)$row['id'],
                'tenant_key' => (string)$row['tenant_key'],
                'status' => (string)$row['status'],
            ];
        }, $rows);
    }

    return [];
}

function cleanupScriptTenantDb(PDO $controlDb, int $tenantId): PDO
{
    static $tenantPool = [];
    if (isset($tenantPool[$tenantId]) && $tenantPool[$tenantId] instanceof PDO) {
        return $tenantPool[$tenantId];
    }

    $stmt = $controlDb->prepare(
        'SELECT db_driver, db_host, db_port, db_name, db_user, db_pass, db_charset, '
        . 'db_pass_ciphertext, db_pass_iv, db_pass_tag '
        . 'FROM kernel_tenant_db_connections WHERE tenant_id = :tenant_id LIMIT 1'
    );
    $stmt->execute([':tenant_id' => $tenantId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        throw new RuntimeException('Tenant DB connection row not found.');
    }

    $password = (string)($row['db_pass'] ?? '');
    $ciphertext = (string)($row['db_pass_ciphertext'] ?? '');
    $iv = (string)($row['db_pass_iv'] ?? '');
    $tag = (string)($row['db_pass_tag'] ?? '');
    if ($ciphertext !== '' && $iv !== '' && $tag !== '') {
        $crypto = new \Ikabud\Kernel\Crypto();
        $password = $crypto->decryptString($ciphertext, $iv, $tag);
    }

    $tenantPool[$tenantId] = cleanupScriptOpenPdo([
        'driver' => (string)($row['db_driver'] ?? 'mysql'),
        'host' => (string)($row['db_host'] ?? '127.0.0.1'),
        'port' => (string)($row['db_port'] ?? '3306'),
        'database' => (string)($row['db_name'] ?? ''),
        'username' => (string)($row['db_user'] ?? ''),
        'password' => $password,
        'charset' => (string)($row['db_charset'] ?? 'utf8mb4'),
    ]);

    return $tenantPool[$tenantId];
}

function cleanupScriptNormalizeBody(string $body): string
{
    $normalized = cmsEditorNormalizeHtml($body, 'cms.content');
    $sanitized = cmsEditorSanitizeHtml($normalized, 'cms.content');

    if ($sanitized !== '') {
        return $sanitized;
    }

    return tinymceSanitizeHtml(tinymceNormalizeHtml($body));
}

/**
 * @param array<int, string> $types
 * @return array{scanned:int, changed:int, updated:int}
 */
function cleanupScriptProcessTenant(PDO $db, array $types, int $batchSize, bool $apply): array
{
    $lastId = 0;
    $scanned = 0;
    $changed = 0;
    $updated = 0;

    $placeholders = implode(', ', array_fill(0, count($types), '?'));
    $selectSql = "SELECT id, title, slug, type, body\n"
        . "FROM cms_content\n"
        . "WHERE deleted_at IS NULL\n"
        . "  AND type IN ({$placeholders})\n"
        . "  AND id > ?\n"
        . "ORDER BY id ASC\n"
        . "LIMIT {$batchSize}";
    $selectStmt = $db->prepare($selectSql);
    $updateStmt = $db->prepare(
        'UPDATE cms_content SET body = :body, updated_at = NOW() WHERE id = :id LIMIT 1'
    );

    while (true) {
        $params = array_merge($types, [$lastId]);
        $selectStmt->execute($params);
        $rows = $selectStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($rows === []) {
            break;
        }

        foreach ($rows as $row) {
            $lastId = (int)$row['id'];
            $scanned++;
            $currentBody = (string)($row['body'] ?? '');
            $cleanedBody = cleanupScriptNormalizeBody($currentBody);

            if ($cleanedBody === $currentBody) {
                continue;
            }

            $changed++;
            if (!$apply) {
                continue;
            }

            $updateStmt->execute([
                ':body' => $cleanedBody,
                ':id' => $lastId,
            ]);
            $updated++;
        }
    }

    return [
        'scanned' => $scanned,
        'changed' => $changed,
        'updated' => $updated,
    ];
}

$options = cleanupScriptOptions($argv);

if (!empty($options['help'])) {
    echo cleanupScriptUsage();
    exit(0);
}

if ((int)!empty($options['tenantId']) + (int)!empty($options['allTenants']) !== 1) {
    fwrite(STDERR, cleanupScriptUsage());
    exit(1);
}

$controlDb = cleanupScriptControlDb();
$targets = cleanupScriptResolveTargets($controlDb, $options);
if ($targets === []) {
    fwrite(STDERR, "No matching tenant targets found.\n");
    exit(1);
}

$mode = !empty($options['apply']) ? 'APPLY' : 'DRY-RUN';
$startedAt = date('Y-m-d H:i:s');
echo "[{$startedAt}] WordPress spacer cleanup starting in {$mode} mode.\n";

$overall = [
    'tenants' => 0,
    'scanned' => 0,
    'changed' => 0,
    'updated' => 0,
    'failures' => 0,
];

foreach ($targets as $target) {
    $tenantId = (int)$target['id'];
    $tenantKey = (string)$target['tenant_key'];
    echo "  Tenant {$tenantId} ({$tenantKey})...\n";

    try {
        $tenantDb = cleanupScriptTenantDb($controlDb, $tenantId);

        $stats = cleanupScriptProcessTenant(
            $tenantDb,
            $options['types'],
            (int)$options['batchSize'],
            !empty($options['apply'])
        );

        $overall['tenants']++;
        $overall['scanned'] += $stats['scanned'];
        $overall['changed'] += $stats['changed'];
        $overall['updated'] += $stats['updated'];

        echo '    scanned=' . $stats['scanned']
            . ', changed=' . $stats['changed']
            . ', updated=' . $stats['updated'] . "\n";
    } catch (Throwable $e) {
        $overall['failures']++;
        fwrite(STDERR, '    FAILED: ' . $e->getMessage() . "\n");
    }
}

$finishedAt = date('Y-m-d H:i:s');
echo "[{$finishedAt}] Done. tenants={$overall['tenants']}, scanned={$overall['scanned']}, changed={$overall['changed']}, updated={$overall['updated']}, failures={$overall['failures']}\n";

exit($overall['failures'] > 0 ? 1 : 0);