<?php

declare(strict_types=1);

/**
 * Probe: what do the daily-ledger tables pay for on every write?
 *
 * The size audit shows `dl_daily_ledger` at 2.172 MB data against 3.578 MB
 * index - the index is 1.65x the data. For a write-heavy table every secondary
 * index is maintained on every save, so index count IS the write-amplification
 * factor. This probe reports:
 *
 *   1. every index on the hot tables, with its columns IN ORDER;
 *   2. exact-duplicate indexes and prefix-redundant ones (an index whose column
 *      list is a prefix of another is maintained on every write and needed by
 *      nothing that the longer one does not already serve);
 *   3. per-table fragmentation (data_free), which is what OPTIMIZE reclaims;
 *   4. per-table write volume from performance_schema, where it is enabled, so
 *      "which table absorbs the concurrent branch writes" is measured rather
 *      than guessed.
 *
 * Same method rules as probe-db-size-audit.php: plain PDO outside module
 * context (information_schema is denied under module context), EVERY column
 * aliased because MySQL 8 returns information_schema names in UPPERCASE, and a
 * TSV written because the terminal truncates wide output.
 *
 * Read-only: SELECT only.
 *
 *   php docs/reviews/baronledger-db-audit-2026-10-10/probe-index-audit.php
 */

require __DIR__ . '/../../../bootstrap.php';

$tenantId = 207;

$st = app()->db()->prepare('SELECT * FROM kernel_tenant_db_connections WHERE tenant_id = :tid');
$st->execute(['tid' => $tenantId]);
$conn = $st->fetch(PDO::FETCH_ASSOC) ?: [];

$dbName = (string)($conn['db_name'] ?? '');
$dbHost = (string)($conn['db_host'] ?? (getenv('DB_HOST') ?: 'localhost'));
$dbPort = (string)($conn['db_port'] ?? '3306');
$dbUser = (string)($conn['db_user'] ?? (getenv('DB_USERNAME') ?: 'root'));
$pass = getenv('DB_PASSWORD');
$pass = $pass === false ? '' : $pass;

if ($dbName === '') {
    fwrite(STDERR, "No db_name resolved for tenant {$tenantId}\n");
    exit(1);
}

$pdo = new PDO(
    "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4",
    $dbUser,
    $pass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

echo "schema: {$dbName}\n\n";

// ---- Hot tables: the dl_ tables that actually carry rows.
$tablesSql = "SELECT table_name AS tname, table_rows AS est_rows, data_length AS dl,
                     index_length AS il, data_free AS df, auto_increment AS ai
              FROM information_schema.tables
              WHERE table_schema = :db AND table_name LIKE 'dl\\_%'
                AND (data_length + index_length) > 65536
              ORDER BY (data_length + index_length) DESC";
$tables = [];
$stmt = $pdo->prepare($tablesSql);
$stmt->execute(['db' => $dbName]);
foreach ($stmt->fetchAll() as $row) {
    $tables[(string)$row['tname']] = $row;
}

if ($tables === []) {
    fwrite(STDERR, "no dl_ tables above 64 KB\n");
    exit(1);
}

// ---- Indexes.
$in = implode(',', array_fill(0, count($tables), '?'));
$idxSql = "SELECT table_name AS tname, index_name AS iname, non_unique AS nonuniq,
                  seq_in_index AS seq, column_name AS col, cardinality AS card,
                  index_type AS itype, sub_part AS subpart
           FROM information_schema.statistics
           WHERE table_schema = ? AND table_name IN ({$in})
           ORDER BY table_name, index_name, seq_in_index";
$stmt = $pdo->prepare($idxSql);
$stmt->execute(array_merge([$dbName], array_keys($tables)));

/** @var array<string, array<string, array{cols: array<int,string>, uniq: bool, card: ?int}>> $indexes */
$indexes = [];
foreach ($stmt->fetchAll() as $row) {
    $t = (string)$row['tname'];
    $n = (string)$row['iname'];
    $indexes[$t] ??= [];
    $indexes[$t][$n] ??= ['cols' => [], 'uniq' => ((int)$row['nonuniq'] === 0), 'card' => null];
    $col = (string)$row['col'];
    if ($row['subpart'] !== null) {
        $col .= '(' . (int)$row['subpart'] . ')';
    }
    $indexes[$t][$n]['cols'][] = $col;
    $indexes[$t][$n]['card'] = $row['card'] === null ? null : (int)$row['card'];
}

// ---- Performance schema write volume, where enabled.
$writeStats = [];
try {
    $psSql = "SELECT object_name AS tname, count_write AS w, sum_timer_write AS tw
              FROM performance_schema.table_io_waits_summary_by_table
              WHERE object_schema = ? AND object_name LIKE 'dl\\_%'";
    $stmt = $pdo->prepare($psSql);
    $stmt->execute([$dbName]);
    foreach ($stmt->fetchAll() as $row) {
        $writeStats[(string)$row['tname']] = [
            'writes' => (int)$row['w'],
            'write_ms' => (float)$row['tw'] / 1e9,
        ];
    }
} catch (Throwable $e) {
    $writeStats = [];
}

$tsv = "table\tindex\tunique\tcolumns\tcardinality\tnote\n";
$flags = [];

foreach ($tables as $tableName => $meta) {
    $dataMb = (int)$meta['dl'] / 1048576;
    $indexMb = (int)$meta['il'] / 1048576;
    $freeMb = (int)$meta['df'] / 1048576;
    $ratio = (int)$meta['dl'] > 0 ? (int)$meta['il'] / (int)$meta['dl'] : 0;
    $tableIndexes = $indexes[$tableName] ?? [];
    $write = $writeStats[$tableName] ?? null;

    printf(
        "%-38s data %6.2f MB  index %6.2f MB  x%.2f  free %5.2f MB  idx %d%s\n",
        $tableName,
        $dataMb,
        $indexMb,
        $ratio,
        $freeMb,
        count($tableIndexes),
        $write !== null ? sprintf('  writes %s (%.0f ms)', number_format($write['writes']), $write['write_ms']) : ''
    );

    $seen = [];
    foreach ($tableIndexes as $indexName => $def) {
        $key = implode(',', $def['cols']);
        $note = '';
        if ($indexName !== 'PRIMARY' && isset($seen[$key])) {
            $note = 'EXACT DUPLICATE of ' . $seen[$key];
            $flags[] = "{$tableName}.{$indexName}: exact duplicate of {$seen[$key]} ({$key})";
        } elseif ($indexName !== 'PRIMARY') {
            $seen[$key] = $indexName;
        }
        printf("    %-28s %-46s%s%s\n",
            $indexName,
            $key,
            $def['uniq'] ? ' UNIQUE' : '',
            $note !== '' ? '  <- ' . $note : ''
        );
        $tsv .= sprintf("%s\t%s\t%s\t%s\t%s\t%s\n",
            $tableName, $indexName, $def['uniq'] ? 'yes' : 'no', $key,
            $def['card'] === null ? '' : (string)$def['card'], $note);
    }

    // Prefix-redundant detection: a non-unique index whose columns are a strict
    // prefix of another non-unique index on the same table.
    foreach ($tableIndexes as $a => $defA) {
        if ($a === 'PRIMARY' || $defA['uniq']) {
            continue;
        }
        foreach ($tableIndexes as $b => $defB) {
            if ($a === $b || $b === 'PRIMARY') {
                continue;
            }
            $ca = $defA['cols'];
            $cb = $defB['cols'];
            if (count($ca) >= count($cb)) {
                continue;
            }
            if (array_slice($cb, 0, count($ca)) === $ca) {
                $flags[] = "{$tableName}.{$a} (" . implode(',', $ca) . ") is a prefix of {$b} ("
                    . implode(',', $cb) . ') - maintained on every write';
                break;
            }
        }
    }
    echo "\n";
}

if ($flags !== []) {
    echo "redundancy candidates (review before dropping - a shorter index can still be the one chosen for a sort):\n";
    foreach (array_unique($flags) as $flag) {
        echo '  - ' . $flag . "\n";
    }
} else {
    echo "no exact-duplicate or prefix-redundant indexes found on these tables\n";
}

$path = __DIR__ . '/index-audit.tsv';
file_put_contents($path, $tsv);
echo "\nwrote {$path}\n";
