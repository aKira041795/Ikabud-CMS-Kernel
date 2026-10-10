<?php

declare(strict_types=1);

/**
 * Read-only topology snapshot for the area rollout.
 *
 * Run this ON THE HOST THAT OWNS THE DATA. It writes nothing and changes nothing; it exists so
 * the canonical-area backfill can be verified against the real values, including rows an
 * administrator added while the plan was being written.
 *
 * Purpose: the area rollout introduces a canonical `dl_areas` table, but `dl_branches.area` is
 * free text typed by hand today. Before any backfill, we need to see the DISTINCT values actually
 * in use, and whether any two of them are the same area typed differently ("Pagadian" vs
 * "Pagadian " vs "Pagadian City"). Auto-creating a canonical area per distinct string would turn a
 * typo into a permanent region, so this reports near-duplicates for a human decision instead.
 *
 * Usage:
 *   php modules/daily-ledger/cli/snapshot-area-topology.php [--database=baronledger] [--json=path]
 */

$opts = ['database' => 'baronledger', 'json' => ''];
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--database=')) {
        $opts['database'] = substr($arg, strlen('--database='));
    } elseif (str_starts_with($arg, '--json=')) {
        $opts['json'] = substr($arg, strlen('--json='));
    } else {
        fwrite(STDERR, "unknown argument: {$arg}\n");
        exit(2);
    }
}

// Read .env from the repository root, the same way the load harness does.
$root = dirname(__DIR__, 3);
$env = [];
if (is_readable($root . '/.env')) {
    foreach (file($root . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        $env[trim($k)] = trim($v, " \t\"'");
    }
}

try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $env['DB_HOST'] ?? 'localhost', (int) ($env['DB_PORT'] ?? 3306), $opts['database']),
        $env['DB_USERNAME'] ?? 'root',
        $env['DB_PASSWORD'] ?? '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (Throwable $e) {
    fwrite(STDERR, "cannot connect to {$opts['database']}: {$e->getMessage()}\n");
    exit(1);
}

$report = ['database' => $opts['database'], 'generated_at' => date('c')];

echo "AREA TOPOLOGY SNAPSHOT — database: {$opts['database']}\n";
echo str_repeat('=', 78), "\n\n";

// ── Distinct area values exactly as stored ────────────────────────────────────
echo "DISTINCT area values AS STORED (this is what a backfill must map)\n";
$raw = $pdo->query(
    'SELECT area, COUNT(*) AS n, SUM(is_commissary = 1) AS commissaries'
    . ' FROM dl_branches GROUP BY area ORDER BY area IS NULL, area'
)->fetchAll();
foreach ($raw as $r) {
    printf("  %-24s branches=%-4s commissaries=%s\n", $r['area'] === null ? '(NULL)' : '"' . $r['area'] . '"', $r['n'], $r['commissaries']);
}
$report['area_values_raw'] = $raw;

// ── Near-duplicates: same value after trim/lower/collapse ─────────────────────
echo "\nNEAR-DUPLICATE area values (same after normalisation — decide by hand, do NOT auto-merge)\n";
$normalizationRows = $pdo->query(
    "SELECT DISTINCT area FROM dl_branches WHERE area IS NOT NULL AND TRIM(area) <> ''"
    . " UNION SELECT DISTINCT area FROM dl_consignees WHERE area IS NOT NULL AND TRIM(area) <> ''"
)->fetchAll();
$norm = [];
foreach ($normalizationRows as $r) {
    $key = strtolower(preg_replace('/\s+/', ' ', trim((string) $r['area'])) ?? '');
    $norm[$key][] = (string) $r['area'];
}
$dupes = array_filter($norm, static fn(array $v): bool => count(array_unique($v)) > 1);
if ($dupes === []) {
    echo "  none — every stored value is already distinct when normalised\n";
} else {
    foreach ($dupes as $key => $variants) {
        printf("  \"%s\"  <- %s\n", $key, implode(' | ', array_map(static fn($v) => '"' . $v . '"', $variants)));
    }
}
$report['area_near_duplicates'] = $dupes;

// ── Canonical-area compatibility phase verification ───────────────────────────
$hasAreas = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'dl_areas'")->fetchColumn() === 1;
if ($hasAreas) {
    echo "\nCANONICAL AREA MAPPING (legacy text retained during compatibility phase)\n";
    $canonical = $pdo->query(
        'SELECT a.id, a.code, a.name,'
        . ' (SELECT COUNT(*) FROM dl_branches b WHERE b.area_id = a.id) AS branches,'
        . ' (SELECT COUNT(*) FROM dl_consignees c WHERE c.area_id = a.id) AS consignees'
        . ' FROM dl_areas a ORDER BY a.sort_order, a.name'
    )->fetchAll();
    foreach ($canonical as $area) {
        printf("  %-12s %-20s branches=%-4s consignees=%s\n", $area['code'], $area['name'], $area['branches'], $area['consignees']);
    }
    $unmapped = (int)$pdo->query(
        "SELECT (SELECT COUNT(*) FROM dl_branches WHERE area IS NOT NULL AND TRIM(area) <> '' AND area_id IS NULL)"
        . " + (SELECT COUNT(*) FROM dl_consignees WHERE area IS NOT NULL AND TRIM(area) <> '' AND area_id IS NULL)"
    )->fetchColumn();
    printf("  nonempty legacy values without canonical id: %d\n", $unmapped);
    $report['canonical_areas'] = $canonical;
    $report['canonical_unmapped_nonempty'] = $unmapped;
}

// ── Supply integrity: the fields that contradict each other today ─────────────
echo "\nSUPPLY INTEGRITY (these are the fields the rollout must not break)\n";
$checks = [
    'assigned commissary but mode=self_managed' =>
        "SELECT COUNT(*) FROM dl_branches WHERE assigned_commissary_id IS NOT NULL AND default_supply_mode = 'self_managed'",
    'mode=commissary_supplied but NO assigned commissary' =>
        "SELECT COUNT(*) FROM dl_branches WHERE default_supply_mode = 'commissary_supplied' AND assigned_commissary_id IS NULL",
    'assigned commissary is itself inactive' =>
        'SELECT COUNT(*) FROM dl_branches b JOIN dl_branches c ON c.id = b.assigned_commissary_id WHERE c.is_active = 0',
    'assigned commissary is NOT flagged is_commissary' =>
        'SELECT COUNT(*) FROM dl_branches b JOIN dl_branches c ON c.id = b.assigned_commissary_id WHERE c.is_commissary = 0',
    'is_commissary but no branch supplies it (informational)' =>
        'SELECT COUNT(*) FROM dl_branches c WHERE c.is_commissary = 1 AND NOT EXISTS (SELECT 1 FROM dl_branches b WHERE b.assigned_commissary_id = c.id)',
];
foreach ($checks as $label => $sql) {
    printf("  %-56s %s\n", $label, (string) $pdo->query($sql)->fetchColumn());
    $report['supply_integrity'][$label] = (int) $pdo->query($sql)->fetchColumn();
}

// ── Cross-area supply (legitimate, but must be a deliberate choice) ────────────
echo "\nCROSS-AREA SUPPLY (allowed — recorded so it is a known decision, not an accident)\n";
$cross = $pdo->query(
    'SELECT b.code AS branch, b.area AS branch_area, c.code AS commissary, c.area AS commissary_area'
    . ' FROM dl_branches b JOIN dl_branches c ON c.id = b.assigned_commissary_id'
    . ' WHERE COALESCE(b.area, "") <> COALESCE(c.area, "")'
)->fetchAll();
foreach ($cross as $r) {
    printf("  %-16s (%s)  <-  %-16s (%s)\n", $r['branch'], $r['branch_area'], $r['commissary'], $r['commissary_area']);
}
printf("  total crossing areas: %d\n", count($cross));
$report['cross_area_supply'] = $cross;

// ── Staff binding ─────────────────────────────────────────────────────────────
echo "\nSTAFF BY ROLE AND BINDING\n";
$roles = $pdo->query(
    'SELECT u.role, COUNT(*) AS users,'
    . ' SUM((SELECT COUNT(*) FROM dl_user_branches ub WHERE ub.user_id = u.id) = 0) AS unbound'
    . ' FROM dl_users u WHERE u.deleted_at IS NULL GROUP BY u.role ORDER BY u.role'
)->fetchAll();
foreach ($roles as $r) {
    printf("  %-22s users=%-5s unbound=%s\n", $r['role'], $r['users'], $r['unbound']);
}
$report['staff_roles'] = $roles;

echo "\nBRANCHES CREATED IN THE LAST 7 DAYS (rows added while this plan was being written)\n";
$recent = $pdo->query(
    'SELECT id, code, area, is_commissary, default_supply_mode, assigned_commissary_id, created_at'
    . ' FROM dl_branches WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) ORDER BY created_at DESC'
)->fetchAll();
foreach ($recent as $r) {
    printf(
        "  %-8s %-16s area=%-14s commissary=%s mode=%-20s assigned=%-6s %s\n",
        $r['id'], $r['code'], (string) ($r['area'] ?? '—'), (int) $r['is_commissary'] === 1 ? 'YES' : 'no',
        $r['default_supply_mode'], $r['assigned_commissary_id'] ?? '—', $r['created_at']
    );
}
printf("  total: %d\n", count($recent));
$report['branches_last_7_days'] = $recent;

if ($opts['json'] !== '') {
    file_put_contents($opts['json'], json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    echo "\nJSON written to {$opts['json']}\n";
}

echo "\nNothing was written to the database by this script.\n";
