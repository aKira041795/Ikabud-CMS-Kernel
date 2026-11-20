<?php

declare(strict_types=1);

/**
 * Daily Ledger — INTEGRITY CHECK. Read-only. Writes nothing but its own report.
 *
 * WHY: every defect found this week was discovered by a person noticing something odd — a negative
 * number, a variance too large, a movement a branch did not make. The checks that would have caught them
 * existed only as one-off probes. This runs them together, exits non-zero when something regressed, and
 * leaves a dated report, so the next gap surfaces in a day instead of in a complaint.
 *
 *   php tools/daily-ledger-integrity-check.php [--tenant=host] [--out=path] [--quiet]
 *
 * Exit codes: 0 = clean, 1 = something needs attention, 2 = could not run.
 * It never writes to the ledger, never adjusts a balance, and never "fixes" anything.
 */

$tenantHost = 'baronledger.test';
$outPath = null;
$quiet = false;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--tenant=')) {
        $tenantHost = substr($arg, 9);
    } elseif (str_starts_with($arg, '--out=')) {
        $outPath = substr($arg, 6);
    } elseif ($arg === '--quiet') {
        $quiet = true;
    }
}

ob_start();
require_once __DIR__ . '/../tests/harness/TestHarness.php';
$harness = new TestHarness('daily-ledger-integrity-check', TestHarness::MODE_INTEGRATION, $tenantHost);
ob_end_clean();
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../modules/daily-ledger/handlers.php';

$db = app()->db();
$findings = [];
$notes = [];
$add = static function (string $key, string $status, string $detail) use (&$findings): void {
    $findings[$key] = ['status' => $status, 'detail' => $detail];
};

// 0. Test residue — suites insert their synthetic branches ACTIVE, so they walk into the checks
//    below and print as if they were hubs. Measured 2026-11-20: `reconcile_hub_99162`,
//    `reconcile_hub_99601` and `reconcile_hub_997125` were in this report, and 55 fixture-shaped
//    branches were still active. Reporting them AS residue is the point: a suite that forgets to
//    clean up should be visible, not disguised as a branch with a reconciliation problem.
try {
    $residue = $db->query(
        "SELECT COUNT(*) FROM dl_branches
          WHERE is_active = 1
            AND (code LIKE 'T-%' OR code LIKE 'OFF-%' OR code LIKE 'PC-%'
                 OR code LIKE 'GAP-%' OR code LIKE 'BOUND-%' OR code LIKE 'AF-%'
                 OR code LIKE 'AGG-%' OR code LIKE 'GATE-%')"
    )->fetchColumn();
    $residue = (int)$residue;
    $add('test_residue', $residue > 0 ? 'ATTENTION' : 'OK',
        $residue . ' active branch(es) carry a test-fixture code prefix. '
        . 'They are test scaffolding, not businesses: deactivate them so they stop appearing as hubs, '
        . 'and fix the suite that left them active.');
} catch (Throwable $e) {
    $add('test_residue', 'INFO', 'not checked: ' . $e->getMessage());
}

// 1. Negative balances — the symptom that took seven weeks to be believed.
$hubs = array_map('intval', $db->query(
    'SELECT id FROM dl_branches WHERE is_commissary = 1 AND is_active = 1'
)->fetchAll(PDO::FETCH_COLUMN) ?: []);
$matrix = dl_commissaryOnHandByProduct($db, $hubs, dl_businessDate());
$negativeProducts = 0;
$negativeUnits = 0;
foreach ($matrix as $byProduct) {
    foreach ($byProduct as $row) {
        $onHand = (int)($row['on_hand'] ?? 0);
        if ($onHand < 0) {
            $negativeProducts++;
            $negativeUnits += $onHand;
        }
    }
}
$add('negative_balances', $negativeProducts > 0 ? 'ATTENTION' : 'OK',
    $negativeProducts . ' product(s), ' . number_format($negativeUnits) . ' units. '
    . 'A negative is never a variance; it means more left than was recorded arriving.');

// 2. Count coverage — a variance can only come from a counted line.
$coverage = $db->query(
    'SELECT SUM(actual_end_qty IS NOT NULL AND end_source IS NULL) counted,
            SUM(actual_end_qty IS NULL) uncounted, COUNT(*) total
       FROM dl_commissary_product_ledger'
)->fetch(PDO::FETCH_ASSOC) ?: [];
$total = max(1, (int)($coverage['total'] ?? 0));
$countedPct = (int)round(((int)($coverage['counted'] ?? 0) / $total) * 100);
$add('count_coverage', $countedPct >= 80 ? 'OK' : 'ATTENTION',
    $countedPct . '% of ' . number_format($total) . ' lines were physically counted; '
    . number_format((int)($coverage['uncounted'] ?? 0)) . ' were never counted at all.');

// 3. Projection drift — the app's dispatch figure against the delivery records.
foreach ($hubs as $hubId) {
    $res = dl_reconcileCommissaryDispatch($db, $hubId, null, null);
    $mismatched = count($res['mismatches'] ?? []);
    $add('reconcile_hub_' . $hubId, $mismatched > 0 ? 'ATTENTION' : 'OK',
        $mismatched . ' of ' . number_format((int)($res['checked'] ?? 0))
        . ' buckets disagree with the posted delivery items.');
}

// 4. New deviation events in the last 24 hours — if the guards are working, these stay quiet.
$events = $db->query(
    "SELECT action, COUNT(*) n FROM audit_logs
      WHERE module = 'daily-ledger'
        AND action IN ('commissary_dispatch_shortfall','commissary_negative_beginning','opening_position_reconciled')
        AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
      GROUP BY action"
)->fetchAll(PDO::FETCH_ASSOC) ?: [];
$eventText = $events === [] ? 'none' : implode(', ', array_map(
    static fn(array $r): string => $r['action'] . '=' . (int)$r['n'],
    $events
));
$add('deviation_events_24h', $events === [] ? 'OK' : 'INFO',
    $eventText . '. A shortfall or negative carry firing means a path still lets stock leave without a receipt.');

$lines = [];
$lines[] = '# Daily Ledger integrity check — ' . date('Y-m-d H:i');
$lines[] = '';
$lines[] = 'Tenant `' . $tenantHost . '`, business date ' . dl_businessDate() . '. READ-ONLY.';
$lines[] = '';
$lines[] = '| check | status | detail |';
$lines[] = '|---|---|---|';
$attention = 0;
foreach ($findings as $key => $finding) {
    if ($finding['status'] === 'ATTENTION') {
        $attention++;
    }
    $lines[] = '| ' . $key . ' | ' . $finding['status'] . ' | ' . str_replace('|', '/', $finding['detail']) . ' |';
}
$lines[] = '';
$lines[] = $attention === 0
    ? 'Nothing needs attention.'
    : $attention . ' check(s) need attention. Nothing in this report changed any data.';

$report = implode("\n", $lines) . "\n";
$outPath = $outPath ?: __DIR__ . '/../.ai/audit/integrity-' . date('Y-m-d') . '.md';
@mkdir(dirname($outPath), 0755, true);
file_put_contents($outPath, $report);

if (!$quiet) {
    echo $report;
    echo "\nreport: {$outPath}\n";
}
exit($attention > 0 ? 1 : 0);
