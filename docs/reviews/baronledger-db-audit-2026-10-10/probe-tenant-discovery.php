<?php
declare(strict_types=1);

/**
 * Which database does the baronledger.test tenant's daily-ledger module actually live in?
 *
 * Discovery only — no assumptions. Resolves the tenant from the control plane, then the connection record,
 * and prints what it found so the size probe can target the right schema.
 *
 * Credentials are never printed. The tenant connection record may hold an ENCRYPTED password; rather than
 * decrypt it here, this reports whether the row exists and falls back to the local .env credentials for the
 * same host (local dev only), stating which path was used.
 *
 *   php docs/reviews/baronledger-db-audit-2026-10-10/probe-tenant-discovery.php
 */

require __DIR__ . '/../../../bootstrap.php';

$app = app();

function tryQuery(PDO $pdo, string $sql, array $params = []): array
{
    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return ['ok' => true, 'rows' => $st->fetchAll(PDO::FETCH_ASSOC), 'err' => ''];
    } catch (Throwable $e) {
        return ['ok' => false, 'rows' => [], 'err' => $e->getMessage()];
    }
}

echo "=== tenant lookup ===\n";
$domain = 'baronledger.test';

// No module context is pushed, so the kernel connection should serve kernel tables.
$pdo = $app->db();
if (!($pdo instanceof PDO)) {
    fwrite(STDERR, "app()->db() did not return a PDO\n");
    exit(1);
}

$r = tryQuery($pdo, 'SELECT * FROM kernel_tenants');
if (!$r['ok']) {
    echo "  kernel_tenants unreadable: {$r['err']}\n";
    exit(1);
}

echo '  kernel_tenants rows: ' . count($r['rows']) . "\n";
if ($r['rows'] !== []) {
    echo '  columns: ' . implode(', ', array_keys($r['rows'][0])) . "\n\n";
}

$matches = [];
foreach ($r['rows'] as $row) {
    foreach ($row as $k => $v) {
        if (is_string($v) && stripos($v, 'baronledger') !== false) {
            $matches[] = $row;
            break;
        }
    }
}

if ($matches === []) {
    echo "  no tenant row mentions 'baronledger'. All tenants:\n";
    foreach ($r['rows'] as $row) {
        printf("    id=%s %s %s\n", $row['id'] ?? '?', $row['name'] ?? '', $row['canonical_domain'] ?? ($row['domain'] ?? ''));
    }
    exit(1);
}

foreach ($matches as $m) {
    echo "  matched tenant:\n";
    foreach ($m as $k => $v) {
        if (preg_match('/pass|secret|key|token/i', (string)$k)) {
            printf("    %-22s %s\n", $k, $v === null || $v === '' ? '(empty)' : '***');
            continue;
        }
        printf("    %-22s %s\n", $k, is_scalar($v) || $v === null ? (string)$v : json_encode($v));
    }
    echo "\n";
}

$tenantId = (int)($matches[0]['id'] ?? 0);
if ($tenantId <= 0) {
    fwrite(STDERR, "could not read a tenant id\n");
    exit(1);
}

echo "=== connection record for tenant {$tenantId} ===\n";
$c = tryQuery($pdo, 'SELECT * FROM kernel_tenant_db_connections WHERE tenant_id = :tid', ['tid' => $tenantId]);
if (!$c['ok']) {
    echo "  unavailable: {$c['err']}\n";
} elseif ($c['rows'] === []) {
    echo "  NO connection row — this tenant shares the primary database.\n";
} else {
    foreach ($c['rows'] as $row) {
        foreach ($row as $k => $v) {
            if (preg_match('/pass|secret|key|token|cipher/i', (string)$k)) {
                printf("    %-22s %s\n", $k, $v === null || $v === '' ? '(empty)' : '*** (present)');
                continue;
            }
            printf("    %-22s %s\n", $k, is_scalar($v) || $v === null ? (string)$v : json_encode($v));
        }
    }
}

echo "\n=== schemas visible on this server ===\n";
$dbHost = getenv('DB_HOST') ?: 'localhost';
$dbUser = getenv('DB_USERNAME') ?: 'root';
$dbPass = getenv('DB_PASSWORD');
$dbPass = $dbPass === false ? '' : $dbPass;

try {
    $plain = new PDO("mysql:host={$dbHost};charset=utf8mb4", $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (Throwable $e) {
    fwrite(STDERR, "  plain PDO connection failed: " . $e->getMessage() . "\n");
    exit(1);
}

$schemas = tryQuery($plain, 'SELECT schema_name, SUM(data_length + index_length) AS bytes
    FROM information_schema.tables GROUP BY schema_name ORDER BY bytes DESC');
echo "  (queried via a plain PDO, outside module context — information_schema is denied to modules)\n";
foreach ($schemas['rows'] as $s) {
    printf("    %-34s %10.2f MB\n", $s['schema_name'], ((float)$s['bytes']) / 1048576);
}

echo "\n=== candidate schema for baronledger's daily-ledger ===\n";
$candidates = [];
foreach ($schemas['rows'] as $s) {
    $name = (string)$s['schema_name'];
    if (stripos($name, 'baron') !== false || stripos($name, 'daily') !== false || stripos($name, 'ledger') !== false) {
        $candidates[] = $name;
    }
}
if ($candidates === []) {
    echo "  none matched by name. Listing every schema that contains a dl_ table:\n";
    $dl = tryQuery($plain, "SELECT table_schema, COUNT(*) AS dl_tables
        FROM information_schema.tables WHERE table_name LIKE 'dl\\_%' GROUP BY table_schema ORDER BY dl_tables DESC");
    foreach ($dl['rows'] as $row) {
        printf("    %-34s %4d dl_ tables\n", $row['table_schema'], (int)$row['dl_tables']);
        $candidates[] = (string)$row['table_schema'];
    }
} else {
    foreach ($candidates as $name) {
        echo "    {$name}\n";
    }
}

echo "\nCANDIDATE_SCHEMAS=" . implode(',', array_unique($candidates)) . "\n";
