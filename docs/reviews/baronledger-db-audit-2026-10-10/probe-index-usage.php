<?php

declare(strict_types=1);

/**
 * Probe: which indexes are actually READ on a live schema?
 *
 * The structural audit (probe-index-audit.php) finds indexes that are maintained
 * on every write and can never be chosen by the optimizer because another index
 * already covers them. That is a safe class to drop on structure alone.
 *
 * This probe answers the harder question - which indexes are read at all - from
 * performance_schema, so the decision is evidence rather than reasoning.
 *
 * TWO CAVEATS THAT DECIDE WHETHER THE OUTPUT MEANS ANYTHING:
 *
 *   1. Counters are cumulative SINCE SERVER START and reset on restart. The
 *      uptime is printed first for exactly that reason; a counter over a few
 *      hours of quiet use is not evidence about a month of live traffic.
 *   2. A zero read count is NOT automatically a drop candidate:
 *        - indexes that back a FOREIGN KEY are legitimately unread on the child
 *          side. MySQL uses them when the PARENT row is updated or deleted, and
 *          the counter for that access does not appear here. Dropping one fails
 *          with error 1553 unless another index leads with the same column.
 *        - an index with zero reads on a staging copy can be the main path on
 *          production.
 *
 * So: run it against the schema whose traffic you are deciding about, and only
 * act on zero-read indexes that are neither FK-supporting nor unique.
 *
 * The blank index name in the output is performance_schema's own row-aggregate
 * (INDEX_NAME IS NULL) - it is the sum over all indexes, not an index.
 *
 *   php probe-index-usage.php            # all dl_ tables
 *   php probe-index-usage.php dl_daily_ledger
 *
 * Read-only: SELECT only.
 */

require __DIR__ . '/../../../bootstrap.php';

$tenantId = (int)($argv[2] ?? 207);
$onlyTable = (string)($argv[1] ?? '');

$st = app()->db()->prepare('SELECT * FROM kernel_tenant_db_connections WHERE tenant_id = :t');
$st->execute(['t' => $tenantId]);
$conn = $st->fetch(PDO::FETCH_ASSOC) ?: [];

$dbName = (string)($conn['db_name'] ?? '');
if ($dbName === '') {
    fwrite(STDERR, "no schema resolved for tenant {$tenantId}\n");
    exit(1);
}

$pdo = new PDO(
    sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        (string)($conn['db_host'] ?? 'localhost'),
        (string)($conn['db_port'] ?? '3306'),
        $dbName),
    (string)($conn['db_user'] ?? (getenv('DB_USERNAME') ?: 'root')),
    (string)(getenv('DB_PASSWORD') ?: ''),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$uptime = $pdo->query('SHOW GLOBAL STATUS LIKE "Uptime"')->fetch();
$hours = round(((int)($uptime['Value'] ?? 0)) / 3600, 1);
echo "schema: {$dbName}   counters since server start: {$hours} h\n";
echo "A zero read count is a QUESTION, not an answer - see the caveats in this file's header.\n\n";

$sql = "SELECT object_name AS tname, index_name AS iname, count_read AS r, count_write AS w
        FROM performance_schema.table_io_waits_summary_by_index_usage
        WHERE object_schema = ?
          AND object_name LIKE 'dl\\_%'
          " . ($onlyTable !== '' ? 'AND object_name = ?' : '') . "
        ORDER BY object_name, count_read DESC";
$params = [$dbName];
if ($onlyTable !== '') {
    $params[] = $onlyTable;
}

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
} catch (Throwable $e) {
    fwrite(STDERR, "performance_schema unavailable: " . $e->getMessage() . "\n");
    exit(1);
}

$rows = $stmt->fetchAll();
if ($rows === []) {
    echo "no per-index counters - performance_schema may be disabled for this server.\n";
    exit(0);
}

$current = null;
foreach ($rows as $row) {
    $table = (string)$row['tname'];
    if ($table !== $current) {
        if ($current !== null) {
            echo "\n";
        }
        echo $table . "\n";
        $current = $table;
    }
    $indexName = $row['iname'];
    if ($indexName === null) {
        printf("   %-34s reads %12s   writes %12s   (table aggregate)\n",
            '(all indexes)', number_format((int)$row['r']), number_format((int)$row['w']));
        continue;
    }
    printf("   %-34s reads %12s   writes %12s%s\n",
        (string)$indexName,
        number_format((int)$row['r']),
        number_format((int)$row['w']),
        ((int)$row['r'] === 0 ? '   <- never read (check FK + uniqueness before acting)' : '')
    );
}
