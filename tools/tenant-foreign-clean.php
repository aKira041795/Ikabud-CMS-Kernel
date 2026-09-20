<?php
declare(strict_types=1);

/**
 * Find and remove tables in a tenant database that belong to no module that tenant has.
 *
 * The rule:
 *   - a tenant database holds the kernel's shared tables (by design, in every tenant DB);
 *   - plus the tables of the modules that tenant actually has — its entry module, the
 *     modules in that module's provisioning plan, anything with a row in the tenant's own
 *     tenant_module_settings, and anything it holds a live entitlement for.
 *   - A table belonging to none of those was written there by something that did not
 *     belong. Live causes: `migrate <module>` fanning a module out to every tenant DB
 *     (guarded since 2026-09-20 in tenantModuleIsInProvisionPlan), and test suites that
 *     provision a module into whatever database the harness binds.
 *
 * Ownership is resolved from the migrations themselves, by walking up from each migration
 * file to the nearest module.json and reading its id. Reading the module out of the path
 * is wrong: modules/healthcare/ is a suite folder and modules/healthcare/documents/ is the
 * module, so a path-based read attributes the tenant's own tables to the suite.
 *
 * Usage (from the project root):
 *   php tools/tenant-foreign-clean.php                          # scan, read-only
 *   php tools/tenant-foreign-clean.php --clean --confirm        # dump, then remove
 *   php tools/tenant-foreign-clean.php --tenant=583 --clean --confirm
 *
 * --clean dumps every tenant it is about to change first, verifies each table appears in
 * the dump, and only then removes. A database that cannot be dumped is left alone.
 */

require __DIR__ . '/../bootstrap.php';
foreach ([
    'src/helpers/module-manager.php',
    'src/helpers/module-migrations.php',
    'src/helpers/module-catalog.php',
    'src/helpers/module-registry.php',
] as $rel) {
    $abs = __DIR__ . '/../' . $rel;
    if (is_file($abs)) { require_once $abs; }
}

$root = realpath(__DIR__ . '/..') ?: (__DIR__ . '/..');
$onlyTenant = 0;
foreach ($argv as $a) {
    if (str_starts_with($a, '--tenant=')) { $onlyTenant = (int) substr($a, 9); }
}
$doClean = in_array('--clean', $argv, true);
$confirmed = in_array('--confirm', $argv, true);

/** Nearest module.json above a migration file; its id is the module. */
function moduleIdForPath(string $path, string $root): ?string
{
    $dir = dirname($path);
    while (strlen($dir) > strlen($root) && str_starts_with($dir, $root)) {
        if (is_file($dir . '/module.json')) {
            $json = json_decode((string) @file_get_contents($dir . '/module.json'), true);
            $id = is_array($json) ? trim((string) ($json['id'] ?? '')) : '';
            if ($id !== '') { return $id; }
        }
        $parent = dirname($dir);
        if ($parent === $dir) { break; }
        $dir = $parent;
    }
    return null;
}

// ── table => owning module, from the migrations ────────────────────
$owner = [];
foreach ([
    $root . '/migrations/*.sql', $root . '/migrations/*/*.sql',
    $root . '/control-migrations/*.sql', $root . '/database/migrations/*.sql',
    $root . '/modules/*/database/migrations/*.sql',
    $root . '/modules/*/*/database/migrations/*.sql',
    $root . '/modules/*/*/*/database/migrations/*.sql',
] as $pattern) {
    foreach (glob($pattern) ?: [] as $file) {
        $sql = (string) @file_get_contents($file);
        if (!preg_match_all('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([A-Za-z0-9_]+)`?/i', $sql, $m)) {
            continue;
        }
        $module = moduleIdForPath($file, $root) ?? 'kernel';
        foreach ($m[1] as $table) {
            $owner[strtolower((string) $table)] ??= $module;
        }
    }
}
if ($owner === []) {
    fwrite(STDERR, "no migrations read — refusing to guess\n");
    exit(2);
}

$kernelOwned = [];
foreach ($owner as $table => $module) {
    if ($module === 'kernel' || str_starts_with((string) $table, 'kernel_')) {
        $kernelOwned[(string) $table] = true;
    }
}

$kernel = app()->db();
$targets = $kernel->query(
    'SELECT t.id, t.tenant_key, t.entry_module_id, c.db_name
     FROM kernel_tenants t
     INNER JOIN kernel_tenant_db_connections c ON c.tenant_id = t.id
     ORDER BY t.id'
)->fetchAll(PDO::FETCH_ASSOC);

if ($onlyTenant > 0) {
    $targets = array_values(array_filter($targets, static fn(array $r): bool => (int) $r['id'] === $onlyTenant));
}

echo "table names attributed to a module: " . count($owner) . "\n";
echo "tenants examined: " . count($targets) . "\n\n";

$totals = ['tables' => 0, 'rows' => 0, 'databases' => 0];
$work = [];

foreach ($targets as $row) {
    $tenantId = (int) $row['id'];
    $entry = trim((string) $row['entry_module_id']);
    $dbName = (string) $row['db_name'];
    $quoted = str_replace('`', '``', $dbName);

    // What this tenant legitimately has.
    $legit = ['kernel' => true, 'control' => true];
    if ($entry !== '') { $legit[$entry] = true; }
    try {
        foreach (tenantProvisionModulePlan($entry) as $m) { $legit[(string) $m] = true; }
    } catch (Throwable $e) {}

    try {
        $st = $kernel->prepare('SELECT module_id, status FROM kernel_tenant_module_entitlements WHERE tenant_id = ?');
        $st->execute([$tenantId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (in_array((string) ($r['status'] ?? ''), ['active', 'trialing', 'grace'], true)) {
                $legit[(string) $r['module_id']] = true;
            }
        }
    } catch (Throwable $e) {}

    try {
        $st = $kernel->prepare('SELECT COUNT(*) c FROM information_schema.tables WHERE table_schema = ? AND table_name = \'tenant_module_settings\'');
        $st->execute([$dbName]);
        if ((int) $st->fetch()['c'] > 0) {
            foreach ($kernel->query("SELECT DISTINCT module_id FROM `{$quoted}`.tenant_module_settings") as $r) {
                $legit[(string) $r['module_id']] = true;
            }
        }
    } catch (Throwable $e) {}

    $st = $kernel->prepare('SELECT table_name t, table_type ty FROM information_schema.tables WHERE table_schema = ? ORDER BY table_name');
    $st->execute([$dbName]);

    $byModule = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $t) {
        $name = strtolower((string) $t['t']);
        if (isset($kernelOwned[$name])) { continue; }
        $module = $owner[$name] ?? null;
        if ($module === null || isset($legit[$module])) { continue; }
        $byModule[$module][] = ['t' => (string) $t['t'], 'view' => stripos((string) $t['ty'], 'VIEW') !== false];
    }

    if ($byModule === []) { continue; }

    $n = 0;
    $rows = 0;
    foreach ($byModule as $module => $list) {
        foreach ($list as $x) {
            $n++;
            try {
                $rows += (int) $kernel->query("SELECT COUNT(*) c FROM `{$quoted}`.`" . str_replace('`', '``', $x['t']) . '`')->fetch()['c'];
            } catch (Throwable $e) {}
        }
    }

    $totals['tables'] += $n;
    $totals['rows'] += $rows;
    $totals['databases']++;

    printf("#%-6d %-22s db=%-24s %4d foreign table(s), %6d row(s)\n",
        $tenantId, substr((string) $row['tenant_key'], 0, 22), substr($dbName, 0, 24), $n, $rows);
    foreach ($byModule as $module => $list) {
        printf("         %-28s %3d\n", $module, count($list));
    }

    $work[$dbName] = ['tenant_id' => $tenantId, 'pdo' => app()->dbForTenant($tenantId), 'modules' => $byModule];
}

echo "\n" . str_repeat('=', 84) . "\n";
printf("TOTAL: %d table(s) in %d database(s), holding %d row(s)\n",
    $totals['tables'], $totals['databases'], $totals['rows']);
echo str_repeat('=', 84) . "\n";

if (!$doClean) {
    echo "\nscan only — nothing changed. Add --clean --confirm to dump and remove.\n";
    exit($totals['tables'] > 0 ? 1 : 0);
}
if (!$confirmed) {
    fwrite(STDERR, "\n--clean given without --confirm — nothing removed.\n");
    exit(2);
}

$dir = $root . '/storage/backups';
if (!is_dir($dir)) { mkdir($dir, 0775, true); }

$removed = 0;
$failed = [];
$idx = 1;

foreach ($work as $dbName => $info) {
    /** @var PDO $pdo */
    $pdo = $info['pdo'];
    $flat = [];
    foreach ($info['modules'] as $module => $list) {
        foreach ($list as $x) { $flat[] = $x; }
    }

    $path = $dir . '/tenant-' . $dbName . '-foreign-' . date('Ymd-His') . '-' . $idx . '.sql';
    $fh = fopen($path, 'wb');
    if ($fh === false) {
        $failed[] = $dbName . ': cannot write dump — left untouched';
        continue;
    }

    fwrite($fh, "-- foreign tables in {$dbName} (tenant #{$info['tenant_id']}), " . date('c') . "\n");
    fwrite($fh, "SET FOREIGN_KEY_CHECKS = 0;\n\n");

    $dumped = [];
    foreach ($flat as $x) {
        if ($x['view']) { continue; }
        $name = $x['t'];
        $esc = str_replace('`', '``', $name);
        try {
            $create = $pdo->query("SHOW CREATE TABLE `{$esc}`")->fetch(PDO::FETCH_ASSOC);
            $ddl = (string) ($create['Create Table'] ?? '');
            if ($ddl === '') { continue; }
            fwrite($fh, "DROP TABLE IF EXISTS `{$name}`;\n{$ddl};\n");
            foreach ($pdo->query("SELECT * FROM `{$esc}`") as $r) {
                $cols = implode(', ', array_map(static fn(string $c): string => '`' . $c . '`', array_keys($r)));
                $vals = implode(', ', array_map(
                    static fn($v): string => $v === null ? 'NULL' : $pdo->quote((string) $v),
                    array_values($r)
                ));
                fwrite($fh, "INSERT INTO `{$name}` ({$cols}) VALUES ({$vals});\n");
            }
            fwrite($fh, "\n");
            $dumped[] = $name;
        } catch (Throwable $e) {
            // Cannot read it, so cannot promise a restore: leave it in place.
            $failed[] = $dbName . '.' . $name . ': not dumped, left in place';
        }
    }
    fwrite($fh, "SET FOREIGN_KEY_CHECKS = 1;\n");
    fclose($fh);

    // Only remove what the dump is known to contain.
    $body = (string) file_get_contents($path);
    $safe = array_values(array_filter($dumped, static fn(string $t): bool => str_contains($body, '`' . $t . '`')));
    if (count($safe) !== count($dumped)) {
        $failed[] = $dbName . ': dump incomplete — nothing removed from this database';
        continue;
    }

    echo "\n{$dbName}: dumping " . count($safe) . " table(s) then removing\n";
    try {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($flat as $x) {
            $name = $x['t'];
            if ($x['view']) {
                try { $pdo->exec('DROP VIEW IF EXISTS `' . str_replace('`', '``', $name) . '`'); } catch (Throwable $e) {}
                continue;
            }
            if (!in_array($name, $safe, true)) { continue; }
            try {
                $pdo->exec('DROP TABLE IF EXISTS `' . str_replace('`', '``', $name) . '`');
                $removed++;
            } catch (Throwable $e) {
                $failed[] = $dbName . '.' . $name . ': ' . substr($e->getMessage(), 0, 70);
            }
        }
    } finally {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
    echo "  removed; backup {$path}\n";
    $idx++;
}

echo "\n" . str_repeat('=', 84) . "\n";
echo "removed {$removed} table(s)\n";
if ($failed !== []) {
    echo "\nnot removed:\n";
    foreach (array_slice($failed, 0, 20) as $f) { echo '  ' . $f . "\n"; }
    if (count($failed) > 20) { echo '  … ' . (count($failed) - 20) . " more\n"; }
}
exit(0);
