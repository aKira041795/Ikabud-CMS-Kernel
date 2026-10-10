<?php
declare(strict_types=1);

/**
 * Where has the baronledger daily-ledger database actually spent its 60 MB?
 *
 * Context: the module's own backup dump reached 41 MB for what the owner describes as ~3 months, one
 * branch, two months of real data, plus a commissary added in October. Every other tenant schema with dl_
 * tables is 1-3 MB, so this one is ~20x its peers.
 *
 * Method notes:
 * - information_schema is queried over a PLAIN PDO, outside the kernel's module context. Under module
 *   context the kernel denies the system schema outright (measured 2026-10-08), and a swallowed throw there
 *   once made a gate report "absent" for columns that existed.
 * - information_schema's table_rows is an InnoDB ESTIMATE and can be badly wrong, so exact COUNT(*) is run
 *   per table too. Both are printed; where they disagree, the estimate is the unreliable one.
 * - The full report is written to a TSV as well as stdout, because this terminal compresses wide table
 *   output and silently truncates columns.
 *
 *   php docs/reviews/baronledger-db-audit-2026-10-10/probe-db-size-audit.php
 */

require __DIR__ . '/../../../bootstrap.php';

$outDir = __DIR__;
$tsv = $outDir . '/table-sizes.tsv';

$tenantId = 207; // baron-001, entry module daily-ledger

// ---- Resolve the tenant's database from the control plane (no assumptions about the name).
$st = app()->db()->prepare('SELECT * FROM kernel_tenant_db_connections WHERE tenant_id = :tid');
$st->execute(['tid' => $tenantId]);
$conn = $st->fetch(PDO::FETCH_ASSOC) ?: [];

$dbName = (string)($conn['db_name'] ?? '');
$dbHost = (string)($conn['db_host'] ?? (getenv('DB_HOST') ?: 'localhost'));
$dbPort = (string)($conn['db_port'] ?? '3306');
$dbUser = (string)($conn['db_user'] ?? (getenv('DB_USERNAME') ?: 'root'));

echo "tenant {$tenantId} (baron-001, entry module daily-ledger)\n";
echo "  db_host : {$dbHost}:{$dbPort}\n";
echo "  db_name : {$dbName}\n";
echo "  db_user : {$dbUser}\n";
echo '  db_pass : ' . (($conn['db_pass_ciphertext'] ?? '') !== '' ? '*** encrypted (using local .env credentials instead)' : '(empty)') . "\n\n";

if ($dbName === '') {
    fwrite(STDERR, "No db_name on the connection record — cannot target a schema.\n");
    exit(1);
}

// ---- Plain connection, outside module context.
$pass = getenv('DB_PASSWORD');
$pass = $pass === false ? '' : $pass;
$pdo = new PDO(
    "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4",
    $dbUser,
    $pass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

// ---- Per-table size + exact row count.
//
// EVERY column is aliased on purpose. MySQL 8's information_schema exposes column names in UPPERCASE
// (TABLE_NAME, DATA_LENGTH, ...), so reading unaliased keys yields null/empty and the whole report silently
// becomes zeros — which is exactly what the first run of this probe produced, and it also made every
// COUNT(*) fail because the table name was an empty identifier. Aliasing removes the dependency on case.
$sql = "SELECT table_name AS tname, engine AS eng, table_rows AS est_rows,
               data_length AS dl, index_length AS il, data_free AS df
        FROM information_schema.tables
        WHERE table_schema = :db ORDER BY (data_length + index_length) DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute(['db' => $dbName]);
$tables = $stmt->fetchAll();

// Self-check: an empty first table name means the read did not work. Fail loudly instead of reporting zeros.
if ($tables !== [] && trim((string)($tables[0]['tname'] ?? '')) === '') {
    fwrite(STDERR, "ABORT: table names came back empty — the information_schema read is not working.\n");
    fwrite(STDERR, 'first row keys: ' . implode(', ', array_keys($tables[0])) . "\n");
    exit(1);
}
if ($tables === []) {
    fwrite(STDERR, "ABORT: no tables found in schema '{$dbName}'.\n");
    exit(1);
}

$totalData = 0;
$totalIndex = 0;
$totalFree = 0;
$rows = [];
foreach ($tables as $t) {
    $name = (string)$t['tname'];
    $data = (int)$t['dl'];
    $index = (int)$t['il'];
    $totalData += $data;
    $totalIndex += $index;
    $totalFree += (int)$t['df'];

    $exact = null;
    try {
        $exact = (int)$pdo->query('SELECT COUNT(*) FROM `' . str_replace('`', '``', $name) . '`')->fetchColumn();
    } catch (Throwable $e) {
        $exact = null; // view or permission issue: report as unknown rather than 0
    }

    $rows[] = [
        'table' => $name,
        'engine' => $t['eng'] ?? '?',
        'exact_rows' => $exact,
        'est_rows' => (int)$t['est_rows'],
        'data_mb' => round($data / 1048576, 3),
        'index_mb' => round($index / 1048576, 3),
        'total_mb' => round(($data + $index) / 1048576, 3),
        'free_mb' => round(((int)$t['df']) / 1048576, 3),
        'avg_row_bytes' => ($exact !== null && $exact > 0 && $data > 0) ? (int)($data / $exact) : null,
    ];
}

// ---- Report.
$fh = fopen($tsv, 'w');
fputcsv($fh, array_keys($rows[0]), "\t");
foreach ($rows as $r) {
    fputcsv($fh, $r, "\t");
}
fclose($fh);

printf("schema totals: %.2f MB data + %.2f MB index = %.2f MB over %d tables\n",
    $totalData / 1048576, $totalIndex / 1048576, ($totalData + $totalIndex) / 1048576, count($rows));
printf("reclaimable free space (data_free): %.2f MB\n\n", $totalFree / 1048576);

printf("%-42s %10s %10s %9s %9s %9s\n", 'table', 'exact_rows', 'est_rows', 'data_mb', 'index_mb', 'avg_row_B');
printf("%s\n", str_repeat('-', 96));
$shown = 0;
$cumulative = 0;
foreach ($rows as $r) {
    if ($shown++ >= 25) {
        break;
    }
    $cumulative += $r['total_mb'];
    printf(
        "%-42s %10s %10s %9.3f %9.3f %9s\n",
        $r['table'],
        $r['exact_rows'] === null ? '?' : number_format($r['exact_rows']),
        number_format($r['est_rows']),
        $r['data_mb'],
        $r['index_mb'],
        $r['avg_row_bytes'] === null ? '-' : number_format($r['avg_row_bytes'])
    );
}
printf("\ntop 25 tables = %.2f MB of %.2f MB (%.1f%%)\n",
    $cumulative, ($totalData + $totalIndex) / 1048576,
    ($totalData + $totalIndex) > 0 ? $cumulative / (($totalData + $totalIndex) / 1048576) * 100 : 0);
echo "full report: {$tsv}\n";
