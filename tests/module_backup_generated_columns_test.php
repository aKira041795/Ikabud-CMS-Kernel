<?php

declare(strict_types=1);

/**
 * Verify the generated-column dump fix in both directions.
 *
 * Part 1 (scratch table, no live data touched): build the same INSERT two ways and show that
 *   OLD  (SELECT *, which lists GENERATED columns) is REFUSED by MySQL with 3105, and
 *   NEW  (the columns ModuleBackupService::dumpableColumns() returns) is ACCEPTED.
 * Part 2 (real module context): show dumpableColumns() excludes exactly the generated columns of
 *   dl_commissary_product_ledger -- which also proves SHOW COLUMNS is permitted through ModuleDB,
 *   since SHOW FULL COLUMNS and information_schema are not.
 */
ob_start();
require_once '/var/www/html/applicationostest/tests/harness/TestHarness.php';
$h = new TestHarness('module-backup-generated-columns', TestHarness::MODE_INTEGRATION, 'baronledger.test');
ob_end_clean();
$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';

// ── Part 1: scratch table ────────────────────────────────────────────────────
$raw = app()->db();
$raw->exec('DROP TABLE IF EXISTS zz_gc_probe');
$raw->exec(
    'CREATE TABLE zz_gc_probe (
        id INT UNSIGNED NOT NULL PRIMARY KEY,
        shift VARCHAR(2) NULL,
        qty INT NOT NULL DEFAULT 0,
        shift_key VARCHAR(2) GENERATED ALWAYS AS (COALESCE(shift, \'\')) STORED,
        derived INT GENERATED ALWAYS AS (qty + 1) STORED
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
);
$raw->exec("INSERT INTO zz_gc_probe (id, shift, qty) VALUES (1, 'PM', 5)");

try {
    $row = $raw->query('SELECT * FROM zz_gc_probe')->fetch(PDO::FETCH_ASSOC);

    // OLD behaviour: every column from SELECT *.
    $oldCols = array_keys($row);
    $oldSql = 'INSERT INTO `zz_gc_probe` (`' . implode('`, `', $oldCols) . '`) VALUES ('
        . implode(', ', array_map(static fn ($v) => $v === null ? 'NULL' : "'" . $v . "'", $row)) . ')';
    $oldError = '';
    try {
        $raw->exec('DELETE FROM zz_gc_probe');
        $raw->exec($oldSql);
    } catch (\Throwable $e) {
        $oldError = $e->getMessage();
    }
    $h->test(
        'OLD dump shape is REFUSED when a generated column is listed',
        $oldError !== '' && str_contains($oldError, '3105'),
        'columns=' . json_encode($oldCols) . ' error=' . substr($oldError, 0, 120)
    );

    // NEW behaviour: exclude generated columns (Extra contains GENERATED).
    $keep = [];
    foreach ($raw->query('SHOW COLUMNS FROM zz_gc_probe') as $c) {
        if (preg_match('/\b(?:VIRTUAL|STORED)\s+GENERATED\b/i', (string)($c['Extra'] ?? '')) !== 1) {
            $keep[] = (string)$c['Field'];
        }
    }
    $vals = array_map(static fn ($c) => $row[$c], $keep);
    $newSql = 'INSERT INTO `zz_gc_probe` (`' . implode('`, `', $keep) . '`) VALUES ('
        . implode(', ', array_map(static fn ($v) => $v === null ? 'NULL' : "'" . $v . "'", $vals)) . ')';
    $newError = '';
    try {
        $raw->exec('DELETE FROM zz_gc_probe');
        $raw->exec($newSql);
    } catch (\Throwable $e) {
        $newError = $e->getMessage();
    }
    $reloaded = $raw->query('SELECT id, shift, qty FROM zz_gc_probe')->fetch(PDO::FETCH_ASSOC);
    $h->test(
        'NEW dump shape is ACCEPTED and restores the row',
        $newError === '' && is_array($reloaded) && (int)$reloaded['id'] === 1
            && (string)$reloaded['shift'] === 'PM' && (int)$reloaded['qty'] === 5,
        'columns=' . json_encode($keep) . ' error=' . substr($newError, 0, 120) . ' row=' . json_encode($reloaded)
    );
} finally {
    $raw->exec('DROP TABLE IF EXISTS zz_gc_probe');
}
$h->test('scratch table cleaned up', true, 'zz_gc_probe dropped');

// ── Part 2: the real table, through the module DB guard ──────────────────────
app()->tenant()->setTenantId(207);
$ctx = modulePushContext('daily-ledger');
require_once $base . '/kernel/Services/ModuleBackupService.php';

// No setAccessible() call: it is a no-op since PHP 8.1 and emits a deprecation notice on newer builds.
$method = new ReflectionMethod(\Ikabud\Kernel\Services\ModuleBackupService::class, 'dumpableColumns');
$dumpable = $method->invoke(null, $ctx->db(), 'dl_commissary_product_ledger');

$generated = [];
foreach ($ctx->db()->query('SHOW COLUMNS FROM dl_commissary_product_ledger') as $c) {
    if (preg_match('/\b(?:VIRTUAL|STORED)\s+GENERATED\b/i', (string)($c['Extra'] ?? '')) === 1) {
        $generated[] = (string)$c['Field'];
    }
}

$h->test(
    'SHOW COLUMNS works through ModuleDB (the mechanism the fix relies on)',
    $generated !== [],
    'generated columns found via the module DB handle: ' . json_encode($generated)
);
$h->test(
    'dumpableColumns() keeps every real column and drops all 3 generated ones',
    $dumpable !== [] && array_intersect($generated, $dumpable) === [] && in_array('produced_qty', $dumpable, true)
        && !in_array('shift_key', $dumpable, true) && !in_array('remaining_qty', $dumpable, true)
        && !in_array('calc_variance', $dumpable, true),
    'generated=' . json_encode($generated) . ' dumpable=' . json_encode($dumpable)
);

// The must-allow case for the trap this fix fell into once: MySQL reports `DEFAULT_GENERATED` for an
// ordinary `created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP`, so a bare 'GENERATED' match drops real
// timestamp data from every backup. These columns must survive.
$dumpableProducts = $method->invoke(null, $ctx->db(), 'dl_products');
$h->test(
    'dumpableColumns() KEEPS DEFAULT_GENERATED timestamp columns (created_at / updated_at)',
    in_array('created_at', $dumpableProducts, true) && in_array('updated_at', $dumpableProducts, true),
    'dl_products dumpable=' . json_encode($dumpableProducts)
);

$h->done();
