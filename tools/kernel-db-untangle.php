<?php

declare(strict_types=1);

/**
 * Kernel-database untangle: find the module tables that do not belong there.
 *
 * The kernel database is the control plane. Module tables live in their tenant's own
 * database, so any module table here is a leftover from the single-database layout.
 *
 * Classification is by the KERNEL'S OWN MIGRATIONS, deliberately not by each module's
 * `owns_tables`: only 36 manifests declare that key, so classifying from it left plainly
 * module-owned tables (ehr_users, ehr_admissions, bakeshop_ingredient_usage,
 * cms_theme_registry) on the keep side. Anything the kernel's own migrations do not
 * create is not the kernel's.
 *
 * Usage:
 *   php tools/kernel-db-untangle.php --scan          # report; changes nothing
 *   php tools/kernel-db-untangle.php --dump          # scan + dump the module tables
 *   php tools/kernel-db-untangle.php --rename        # move them aside (reversible)
 *   php tools/kernel-db-untangle.php --drop-orphans  # drop the set-aside tables
 */

require_once __DIR__ . '/../bootstrap.php';

$mode = $argv[1] ?? '--scan';
$db = app()->db();
$database = (string) $db->query('SELECT DATABASE()')->fetchColumn();
$projectRoot = dirname(__DIR__);
$orphanPrefix = 'tmp_orphan_';

/**
 * Table names the kernel's own migrations create. These, and only these, are the
 * kernel's to keep.
 *
 * @return array<string, string> table name => migration file it came from
 */
function kernelOwnedTables(string $projectRoot): array
{
    $files = array_merge(
        glob($projectRoot . '/control-migrations/*.sql') ?: [],
        glob($projectRoot . '/migrations/*.sql') ?: [],
        glob($projectRoot . '/migrations/*/*.sql') ?: []
    );

    $tables = [];
    foreach ($files as $file) {
        $sql = (string) @file_get_contents($file);
        if (preg_match_all('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([A-Za-z0-9_]+)`?/i', $sql, $m)) {
            foreach ($m[1] as $name) {
                $tables[strtolower($name)] = basename($file);
            }
        }
    }

    // The kernel owns its own users and its migration ledger regardless of which
    // migration created them.
    foreach (['users', '_migrations', 'sessions'] as $always) {
        $tables[$always] ??= 'kernel';
    }

    return $tables;
}

$existing = array_map('strtolower', $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN));
$kernel = kernelOwnedTables($projectRoot);

$moduleTables = [];
$alreadyAside = [];
$keep = [];
foreach ($existing as $t) {
    if (str_starts_with($t, $orphanPrefix)) {
        $alreadyAside[] = $t;
    } elseif (isset($kernel[$t])) {
        $keep[] = $t;
    } else {
        $moduleTables[] = $t;
    }
}

sort($moduleTables);

$rows = 0;
$sizes = [];
foreach ($moduleTables as $t) {
    $sizes[$t] = (int) $db->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
    $rows += $sizes[$t];
}

echo "database: {$database}\n";
echo "tables total: " . count($existing) . "\n";
echo "  kernel-owned (KEEP): " . count($keep) . "\n";
echo "  module leftovers (REMOVE): " . count($moduleTables) . " ({$rows} rows)\n";
echo "  already set aside: " . count($alreadyAside) . "\n";

// Only the read-only modes may stop here. `--drop-orphans` has nothing left to remove by
// definition — it removes what an earlier `--rename` set aside — and an early exit here
// (as this had) makes it silently do nothing while reporting success.
if ($moduleTables === [] && in_array($mode, ['--scan', '--dump'], true)) {
    echo "\nNothing to do — the kernel database holds no module tables.\n";
    exit(0);
}

echo "\nmodule tables by prefix:\n";
$byPrefix = [];
foreach ($moduleTables as $t) {
    $p = preg_split('/_/', $t)[0] ?? $t;
    $byPrefix[$p] = ($byPrefix[$p] ?? 0) + 1;
}
arsort($byPrefix);
$shown = 0;
foreach ($byPrefix as $p => $n) {
    echo "  " . str_pad($p, 22) . $n . "\n";
    if (++$shown >= 14) {
        echo "  … " . (count($byPrefix) - $shown) . " more prefix(es)\n";
        break;
    }
}

// Guard: nothing the kernel's own migrations created may be in the removal set.
$leak = array_intersect($moduleTables, array_keys($kernel));
echo "\nkernel tables in the removal set: " . count($leak) . ($leak ? ' (' . implode(', ', $leak) . ')' : '') . "\n";

if ($mode === '--scan') {
    echo "\n(scan only — nothing changed)\n";
    exit(0);
}

if ($mode === '--dump') {
    $dir = $projectRoot . '/storage/backups';
    if (!is_dir($dir) && !mkdir($dir, 0777, true)) {
        fwrite(STDERR, "cannot create {$dir}\n");
        exit(1);
    }
    $path = $dir . '/kernel-db-module-tables-' . date('Ymd-His') . '.sql';
    $fh = fopen($path, 'w');
    if ($fh === false) {
        fwrite(STDERR, "cannot write {$path}\n");
        exit(1);
    }

    fwrite($fh, "-- Module tables found in the kernel database ({$database})\n");
    fwrite($fh, "-- Taken " . date('c') . " before removal. Reload with:\n");
    fwrite($fh, "--   mysql <database> < " . basename($path) . "\n");
    fwrite($fh, "SET FOREIGN_KEY_CHECKS=0;\n");

    $dumped = 0;
    foreach ($moduleTables as $t) {
        $create = $db->query("SHOW CREATE TABLE `{$t}`")->fetch(PDO::FETCH_NUM);
        fwrite($fh, "\nDROP TABLE IF EXISTS `{$t}`;\n" . (string) ($create[1] ?? '') . ";\n");

        $stmt = $db->query("SELECT * FROM `{$t}`");
        $cols = null;
        while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            if ($cols === null) {
                $cols = '`' . implode('`, `', array_keys($row)) . '`';
            }
            $values = [];
            foreach ($row as $v) {
                $values[] = $v === null ? 'NULL' : $db->quote((string) $v);
            }
            fwrite($fh, "INSERT INTO `{$t}` ({$cols}) VALUES (" . implode(', ', $values) . ");\n");
        }
        $dumped++;
    }

    fwrite($fh, "SET FOREIGN_KEY_CHECKS=1;\n");
    fclose($fh);

    echo "\ndumped {$dumped} table(s) to " . $path . "\n";
    echo "size: " . number_format((float) filesize($path) / 1024, 1) . " KB\n";
    exit(0);
}

if ($mode === '--rename') {
    $renamed = 0;
    foreach ($moduleTables as $t) {
        // Reversible in one statement; dropping 6,000+ rows is not.
        $db->exec("RENAME TABLE `{$t}` TO `{$orphanPrefix}{$t}`");
        $renamed++;
    }
    echo "\nrenamed {$renamed} table(s) to {$orphanPrefix}*\n";
    echo "(the app should be unaffected — these serve no tenant; verify before dropping)\n";
    exit(0);
}

if ($mode === '--drop-orphans') {
    $db->exec('SET FOREIGN_KEY_CHECKS=0');
    $n = 0;
    foreach ($alreadyAside as $t) {
        $db->exec("DROP TABLE `{$t}`");
        $n++;
    }
    $db->exec('SET FOREIGN_KEY_CHECKS=1');
    echo "\ndropped {$n} set-aside table(s)\n";
    exit(0);
}

fwrite(STDERR, "unknown mode: {$mode}\n");
exit(1);
