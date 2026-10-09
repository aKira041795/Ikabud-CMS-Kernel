#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Preflight a module backup against a target database BEFORE importing it.
 *
 * A backup is data-only and begins with `DELETE FROM <table>` per table, so an import that fails
 * part-way leaves the target emptied. This reports what would go wrong first, by comparing every
 * INSERT's column list with the target schema:
 *
 *   MISSING TABLE   the dump has rows for a table the target does not have  -> 1146
 *   MISSING COLUMN  the dump writes a column the target lacks               -> 1054
 *   NO DEFAULT      the target has a NOT NULL column the dump omits         -> 1364
 *   GENERATED       the dump writes a generated column                      -> 3105 (strip first)
 *
 * Usage:
 *   php tools/preflight-dump-against-schema.php <dump.sql> [tenant_id]
 *
 * Read-only: it never writes to the database.
 */

$dump = $argv[1] ?? '';
$tenantId = isset($argv[2]) ? (int)$argv[2] : 207;

if ($dump === '' || !is_file($dump)) {
    fwrite(STDERR, "Usage: php tools/preflight-dump-against-schema.php <dump.sql> [tenant_id]\n");
    exit(1);
}

require_once __DIR__ . '/../bootstrap.php';
$pdo = app()->dbForTenant($tenantId);

$dbName = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();

/** @var array<string, array{cols: array<string, array{nullable: bool, default: string|null, generated: bool}>, exists: bool}> */
$schema = [];
$schemaFor = static function (string $table) use ($pdo, &$schema): array {
    if (isset($schema[$table])) {
        return $schema[$table];
    }
    $cols = [];
    $exists = true;
    try {
        foreach ($pdo->query('SHOW COLUMNS FROM `' . str_replace('`', '', $table) . '`') as $c) {
            $extra = (string)($c['Extra'] ?? '');
            $cols[(string)$c['Field']] = [
                'nullable' => strtoupper((string)($c['Null'] ?? 'NO')) === 'YES',
                'default' => $c['Default'],
                'generated' => preg_match('/\b(?:VIRTUAL|STORED)\s+GENERATED\b/i', $extra) === 1,
            ];
        }
    } catch (\Throwable $e) {
        $exists = false;
    }
    return $schema[$table] = ['cols' => $cols, 'exists' => $exists];
};

/**
 * Count tuples in an INSERT body, honouring single-quoted strings so a value containing "),\n"
 * cannot inflate the count. The dumper escapes a quote as \' and a backslash as \\.
 */
$countTuples = static function (string $body): int {
    $depth = 0;
    $inString = false;
    $count = 0;
    $len = strlen($body);
    for ($i = 0; $i < $len; $i++) {
        $ch = $body[$i];
        if ($inString) {
            if ($ch === '\\') { $i++; continue; }
            if ($ch === "'") { $inString = false; }
            continue;
        }
        if ($ch === "'") { $inString = true; continue; }
        if ($ch === '(') { $depth++; continue; }
        if ($ch === ')') {
            $depth--;
            if ($depth === 0) { $count++; }
        }
    }
    return $count;
};

$tables = [];   // table => ['rows' => int, 'cols' => list<string>]
$fh = fopen($dump, 'rb');
while (($line = fgets($fh)) !== false) {
    if (preg_match('/^INSERT INTO `([A-Za-z0-9_]+)` \(([^)]*)\) VALUES\s*$/', rtrim($line, "\r\n"), $m) !== 1) {
        continue;
    }
    $table = $m[1];
    if (!isset($tables[$table])) {
        $tables[$table] = ['rows' => 0, 'cols' => array_map(static fn ($c) => trim($c, " `\t"), explode(',', $m[2]))];
    }
    $body = '';
    while (($l = fgets($fh)) !== false) {
        $body .= $l;
        if (preg_match('/;\s*$/', $l)) { break; }
    }
    $tables[$table]['rows'] += $countTuples($body);
}
fclose($fh);

echo "Target database: {$dbName} (tenant {$tenantId})\n";
echo 'Tables with rows in the dump: ' . count($tables) . "\n\n";

$problems = 0;
$totalRows = 0;

foreach ($tables as $table => $info) {
    $totalRows += $info['rows'];
    $local = $schemaFor($table);

    if (!$local['exists']) {
        echo "  MISSING TABLE   {$table} ({$info['rows']} rows) -- target has no such table\n";
        $problems++;
        continue;
    }

    $missing = array_values(array_diff($info['cols'], array_keys($local['cols'])));
    $generatedWritten = array_values(array_filter(
        $info['cols'],
        static fn (string $c): bool => ($local['cols'][$c]['generated'] ?? false) === true
    ));
    $omittedRequired = [];
    foreach ($local['cols'] as $name => $meta) {
        if ($meta['generated'] || in_array($name, $info['cols'], true)) { continue; }
        // A NOT NULL column with no default and no incoming value breaks the INSERT.
        if (!$meta['nullable'] && $meta['default'] === null) { $omittedRequired[] = $name; }
    }

    $issues = [];
    if ($missing !== []) { $issues[] = 'MISSING COLUMN ' . json_encode($missing) . ' -> 1054'; }
    if ($generatedWritten !== []) { $issues[] = 'GENERATED written ' . json_encode($generatedWritten) . ' -> 3105 (run tools/strip-generated-columns.php)'; }
    if ($omittedRequired !== []) { $issues[] = 'NOT NULL without default and not supplied ' . json_encode($omittedRequired) . ' -> 1364'; }

    if ($issues === []) {
        echo "  OK              {$table} ({$info['rows']} rows, " . count($info['cols']) . " columns)\n";
        continue;
    }

    $problems++;
    echo "  PROBLEM         {$table} ({$info['rows']} rows)\n";
    foreach ($issues as $issue) {
        echo "                    {$issue}\n";
    }
}

echo "\nTotal rows in dump: {$totalRows}\n";
echo $problems === 0
    ? "PREFLIGHT: OK -- every statement matches the target schema.\n"
    : "PREFLIGHT: {$problems} table(s) would fail. Fix those before importing.\n";

exit($problems === 0 ? 0 : 2);
