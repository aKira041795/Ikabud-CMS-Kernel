<?php

declare(strict_types=1);

/**
 * Daily Ledger — DATA REMEDIATION PLANNER. Read-only: it decides nothing and changes nothing.
 *
 * Produces the exact, reviewable list of corrections for the three data defects found on 2026-11-19:
 *   A. unit-duplicate products (61% of absolute variance)
 *   B. negative balances with their CAUSE (113 products / -44,015 units)
 *   C. projection drift against the derivation
 *
 * Run:  php tools/plan-daily-ledger-remediation.php [--tenant=baronledger.test] [--out=path.md]
 * Applying corrections is deliberately NOT implemented here: each item needs a human decision (which
 * unit survives, what the true opening count is), and the owner's rule is that nothing may be clamped
 * or silently restated.
 */

$tenantHost = 'baronledger.test';
$outPath = null;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--tenant=')) {
        $tenantHost = substr($arg, 9);
    } elseif (str_starts_with($arg, '--out=')) {
        $outPath = substr($arg, 6);
    }
}

ob_start();
require_once __DIR__ . '/../tests/harness/TestHarness.php';
$harness = new TestHarness('plan-daily-ledger-remediation', TestHarness::MODE_INTEGRATION, $tenantHost);
ob_end_clean();
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../modules/daily-ledger/handlers.php';

$db = app()->db();
$today = dl_businessDate();
$lines = [];
$say = static function (string $text) use (&$lines): void {
    $lines[] = $text;
};

function q(PDO $db, string $sql, array $bind = []): array
{
    $stmt = $db->prepare($sql);
    $stmt->execute($bind);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$say('# Daily Ledger — data remediation plan');
$say('');
$say('Generated: ' . date('Y-m-d H:i:s') . ' against tenant `' . $tenantHost . '`, business date ' . $today . '.');
$say('');
$say('READ-ONLY. Nothing in this plan has been applied. Each item needs a human decision, and the rule');
$say('you set is that no balance is clamped and no history is silently restated.');
$say('');

// ── A. Unit duplicates ─────────────────────────────────────────────────────────────
$say('## A. Unit-duplicate products (61% of all absolute variance)');
$say('');
$products = q($db, 'SELECT id, name, sku, product_category, is_active FROM dl_products ORDER BY name');
$groups = [];
foreach ($products as $p) {
    // Normalise: drop casings, punctuation, the "@price" tail and unit words, so
    // "TOASTED MAMON @15" and "Toasted Mamon by PCS@10" land in the same bucket.
    $key = strtolower((string)$p['name']);
    $key = preg_replace('/@\s*[\d.,]+/u', ' ', $key);
    $key = preg_replace('/\b(by|pc|pcs|per|kilos|kg|s)\b/u', ' ', $key);
    $key = preg_replace('/[^a-z0-9 ]+/', ' ', $key);
    $key = trim(preg_replace('/\s+/', ' ', $key));
    if ($key === '') {
        continue;
    }
    $groups[$key][] = $p;
}
$dupeCount = 0;
foreach ($groups as $key => $members) {
    if (count($members) < 2) {
        continue;
    }
    $dupeCount++;
    $say('### `' . $key . '` — ' . count($members) . ' products');
    foreach ($members as $m) {
        $pid = (int)$m['id'];
        $produced = (int)($db->query("SELECT COALESCE(SUM(produced_qty),0) FROM dl_commissary_product_ledger WHERE product_id = {$pid}")->fetchColumn() ?: 0);
        $dispatched = (int)($db->query("SELECT COALESCE(SUM(dispatched_qty),0) FROM dl_commissary_product_ledger WHERE product_id = {$pid}")->fetchColumn() ?: 0);
        $variance = (int)($db->query("SELECT COALESCE(SUM(ABS(calc_variance)),0) FROM dl_commissary_product_ledger WHERE product_id = {$pid}")->fetchColumn() ?: 0);
        $say(sprintf('- `#%d` %s (sku `%s`, %s, %s) — produced %s, dispatched %s, absolute variance %s',
            $pid, (string)$m['name'], (string)($m['sku'] ?? ''), (string)$m['product_category'],
            (int)$m['is_active'] === 1 ? 'active' : 'inactive',
            number_format($produced), number_format($dispatched), number_format($variance)));
    }
    $say('- **Decision needed:** which product is the real one. The other is deactivated and its history is');
    $say('  mapped, never deleted — deleting it would erase the very evidence used to find this.');
    $say('');
}
if ($dupeCount === 0) {
    $say('No candidate groups found.');
    $say('');
}

// ── B. Negative balances ───────────────────────────────────────────────────────────
$say('## B. Negative balances — with the cause, not just the number');
$say('');
$hubs = q($db, 'SELECT id, name FROM dl_branches WHERE is_commissary = 1 AND is_active = 1 ORDER BY id');
$hubIds = array_map(static fn(array $r): int => (int)$r['id'], $hubs);
$matrix = dl_commissaryOnHandByProduct($db, $hubIds, $today);
$negatives = 0;
$totalUnits = 0;
$buckets = ['no production ever' => 0, 'production exists' => 0];
$rowsOut = [];
foreach ($matrix as $hubId => $byProduct) {
    foreach ($byProduct as $productId => $row) {
        $onHand = (int)($row['on_hand'] ?? 0);
        if ($onHand >= 0) {
            continue;
        }
        $negatives++;
        $totalUnits += $onHand;
        $produced = (int)($db->query("SELECT COALESCE(SUM(produced_qty),0) FROM dl_commissary_product_ledger
                                       WHERE product_id = {$productId} AND commissary_branch_id = {$hubId}")->fetchColumn() ?: 0);
        $dispatched = (int)($db->query("SELECT COALESCE(SUM(dispatched_qty),0) FROM dl_commissary_product_ledger
                                         WHERE product_id = {$productId} AND commissary_branch_id = {$hubId}")->fetchColumn() ?: 0);
        $firstNeg = (string)($db->query("SELECT MIN(ledger_date) FROM dl_commissary_product_ledger
                                          WHERE product_id = {$productId} AND commissary_branch_id = {$hubId} AND beg_qty < 0")->fetchColumn() ?: '');
        $name = (string)($db->query("SELECT name FROM dl_products WHERE id = {$productId}")->fetchColumn() ?: ('#' . $productId));
        $cause = $produced === 0 ? 'no production ever' : 'production exists';
        $buckets[$cause]++;
        $rowsOut[] = [$onHand, $hubId, $productId, $name, $produced, $dispatched, $firstNeg, $cause];
    }
}
usort($rowsOut, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
$say(sprintf('**%d products across %d hub(s), totalling %s units.**', $negatives, count($hubIds), number_format($totalUnits)));
$say('');
$say('| on hand | hub | product | produced ever | dispatched ever | first negative | cause | action |');
$say('|---|---|---|---|---|---|---|---|');
foreach (array_slice($rowsOut, 0, 25) as $r) {
    $say(sprintf('| %s | #%d | %s (#%d) | %s | %s | %s | %s | %s |',
        number_format($r[0]), $r[1], $r[2] === null ? '' : (string)$r[3], $r[2],
        number_format($r[4]), number_format($r[5]), $r[6] !== '' ? $r[6] : '—', $r[7],
        $r[7] === 'no production ever' ? 'physical count or receipt' : 'count, then correct the over-dispatch'));
}
$say('');
$say('Cause split: ' . $buckets['no production ever'] . ' product(s) never had a single production entry — these');
$say('are bought-in goods with no receipt path, so the only truthful correction is a physical count or a');
$say('recorded receipt. ' . $buckets['production exists'] . ' have production history, so their negatives come from');
$say('dispatching more than was produced/received and need the receiving side checked first.');
$say('');

// ── C. Projection drift ────────────────────────────────────────────────────────────
$say('## C. Projection drift against the derivation');
$say('');
foreach ($hubs as $hub) {
    $res = dl_reconcileCommissaryDispatch($db, (int)$hub['id'], null, null);
    $say(sprintf('- **%s (#%d):** %s buckets compared, **%s mismatched**, %s uncomparable, %s unrecorded.',
        (string)$hub['name'], (int)$hub['id'],
        number_format((int)($res['checked'] ?? 0)), number_format(count($res['mismatches'] ?? [])),
        number_format(count($res['uncomparable'] ?? [])), number_format(count($res['unrecorded'] ?? []))));
}
$say('');
$say('Drift is repaired by recomputing the projection FROM the derivation for the affected buckets —');
$say('never by editing the deliveries. The derivation (posted delivery items) is the evidence.');

$plan = implode("\n", $lines) . "\n";
$outPath = $outPath ?: __DIR__ . '/../.ai/audit/remediation-plan.md';
@mkdir(dirname($outPath), 0755, true);
file_put_contents($outPath, $plan);

echo $plan;
echo "\nwritten to: {$outPath}\n";
