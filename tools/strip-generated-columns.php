#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Make an existing module backup importable by removing GENERATED columns from its INSERTs.
 *
 * Why: MySQL refuses ANY explicit value for a generated column --
 *   #3105 "The value specified for generated column 'x' in table 'y' is not allowed"
 * -- so a dump that lists one can never be imported. ModuleBackupService used `SELECT *`, so every
 * backup it produced before 2026-10-09 is affected wherever a table has a generated column. The fix
 * stops new dumps emitting them; this repairs dumps that already exist, preserving every row.
 *
 * Usage:
 *   php tools/strip-generated-columns.php <in.sql> <out.sql> [tenant_id]
 *
 * tenant_id defaults to 207 (baronledger). Generated columns are read from that tenant's live schema;
 * a table that does not exist there is copied through untouched, so the tool never guesses.
 *
 * Read-only with respect to the database. It only writes <out.sql>.
 */

$in = $argv[1] ?? '';
$out = $argv[2] ?? '';
$tenantId = isset($argv[3]) ? (int)$argv[3] : 207;

if ($in === '' || $out === '' || !is_file($in)) {
    fwrite(STDERR, "Usage: php tools/strip-generated-columns.php <in.sql> <out.sql> [tenant_id]\n");
    exit(1);
}

require_once __DIR__ . '/../bootstrap.php';
$pdo = app()->dbForTenant($tenantId);

/** @var array<string, list<string>> */
$generatedCache = [];
$generatedFor = static function (string $table) use ($pdo, &$generatedCache): array {
    if (array_key_exists($table, $generatedCache)) {
        return $generatedCache[$table];
    }
    $generated = [];
    try {
        foreach ($pdo->query('SHOW COLUMNS FROM `' . str_replace('`', '', $table) . '`') as $c) {
            // VIRTUAL/STORED GENERATED only. `DEFAULT_GENERATED` is what MySQL reports for an ordinary
            // `created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP` column, and dropping those would delete
            // real data from the repaired dump.
            if (preg_match('/\b(?:VIRTUAL|STORED)\s+GENERATED\b/i', (string)($c['Extra'] ?? '')) === 1) {
                $generated[] = (string)$c['Field'];
            }
        }
    } catch (\Throwable $e) {
        // Unknown table: leave it alone rather than risk mangling it.
        $generated = [];
    }

    return $generatedCache[$table] = $generated;
};

/**
 * Split "('a', 'b\'c'), ('d', NULL);" into tuples, honouring single-quoted strings.
 * The dumper escapes a quote as \' and a backslash as \\, so a backslash escapes the next char.
 *
 * @return list<string>
 */
$splitTuples = static function (string $body): array {
    $tuples = [];
    $depth = 0;
    $inString = false;
    $len = strlen($body);
    $start = -1;
    for ($i = 0; $i < $len; $i++) {
        $ch = $body[$i];
        if ($inString) {
            if ($ch === '\\') { $i++; continue; }
            if ($ch === "'") { $inString = false; }
            continue;
        }
        if ($ch === "'") { $inString = true; continue; }
        if ($ch === '(') {
            if ($depth === 0) { $start = $i; }
            $depth++;
            continue;
        }
        if ($ch === ')') {
            $depth--;
            if ($depth === 0 && $start >= 0) {
                $inner = substr($body, $start + 1, $i - $start - 1);
                $tuples[] = $inner;
                $start = -1;
            }
        }
    }

    return $tuples;
};

/** Split one tuple's values, honouring quoted strings. @return list<string> */
$splitValues = static function (string $tuple): array {
    $values = [];
    $current = '';
    $inString = false;
    $len = strlen($tuple);
    for ($i = 0; $i < $len; $i++) {
        $ch = $tuple[$i];
        if ($inString) {
            $current .= $ch;
            if ($ch === '\\') { if ($i + 1 < $len) { $current .= $tuple[++$i]; } continue; }
            if ($ch === "'") { $inString = false; }
            continue;
        }
        if ($ch === "'") { $inString = true; $current .= $ch; continue; }
        if ($ch === ',') { $values[] = trim($current); $current = ''; continue; }
        $current .= $ch;
    }
    $values[] = trim($current);

    return $values;
};

$inFh = fopen($in, 'rb');
$outFh = fopen($out, 'wb');
if (!is_resource($inFh) || !is_resource($outFh)) {
    fwrite(STDERR, "Cannot open input or output.\n");
    exit(1);
}

$stats = [];
$rewritten = 0;
$tablesTouched = [];

while (($line = fgets($inFh)) !== false) {
    if (!preg_match('/^INSERT INTO `([A-Za-z0-9_]+)` \(([^)]*)\) VALUES\s*$/', rtrim($line, "\r\n"), $m)) {
        fwrite($outFh, $line);
        continue;
    }

    $table = $m[1];
    $columns = array_map(static fn (string $c): string => trim($c, " `\t"), explode(',', $m[2]));

    // Accumulate the whole statement (tuples may span many lines) up to the terminating ';'.
    $body = '';
    while (($l = fgets($inFh)) !== false) {
        $body .= $l;
        if (preg_match('/;\s*$/', $l)) { break; }
    }

    $dropIndexes = [];
    foreach ($generatedFor($table) as $gen) {
        $idx = array_search($gen, $columns, true);
        if ($idx !== false) { $dropIndexes[] = (int)$idx; }
    }

    if ($dropIndexes === []) {
        fwrite($outFh, $line . $body);
        $stats[$table] = ($stats[$table] ?? 0) + 1;
        continue;
    }

    $keptColumns = [];
    foreach ($columns as $i => $c) {
        if (!in_array($i, $dropIndexes, true)) { $keptColumns[] = $c; }
    }

    $tuples = $splitTuples($body);
    $emitted = [];
    foreach ($tuples as $tuple) {
        $values = $splitValues($tuple);
        if (count($values) !== count($columns)) {
            throw new RuntimeException(
                "Column/value count mismatch in `{$table}`: " . count($columns) . ' columns, '
                . count($values) . ' values. Refusing to rewrite.'
            );
        }
        $kept = [];
        foreach ($values as $i => $v) {
            if (!in_array($i, $dropIndexes, true)) { $kept[] = $v; }
        }
        $emitted[] = '(' . implode(', ', $kept) . ')';
    }

    $quoted = implode(', ', array_map(static fn (string $c): string => '`' . $c . '`', $keptColumns));
    fwrite($outFh, 'INSERT INTO `' . $table . '` (' . $quoted . ") VALUES\n" . implode(",\n", $emitted) . ";\n");

    $rewritten++;
    $tablesTouched[$table] = ($tablesTouched[$table] ?? 0) + count($emitted);
}

fclose($inFh);
fclose($outFh);

echo "Stripped generated columns from {$rewritten} INSERT statement(s).\n";
foreach ($tablesTouched as $t => $rows) {
    echo '  ' . $t . ': ' . $rows . " row(s) preserved, dropped " . json_encode($generatedFor($t)) . "\n";
}
if ($tablesTouched === []) {
    echo "  (no statement listed a generated column -- the file was already importable)\n";
}
echo 'Wrote: ' . $out . "\n";
