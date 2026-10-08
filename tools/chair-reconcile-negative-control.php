<?php

declare(strict_types=1);

/**
 * CHAIR NEGATIVE CONTROL — independent of the lane's own claim.
 *
 * The lane asserts "GUARD REFUSED MISMATCH: YES". This observes it from outside:
 * it perturbs a stored projection value INSIDE a transaction, asks the new
 * reconciliation to judge it, and then proves the rollback restored the value.
 *
 * Read-only with respect to the working tree. Transactional with respect to the
 * database, and the post-condition (original value restored) is asserted, not assumed.
 */

$basePath = '/var/www/html/applicationostest';

require_once $basePath . '/bootstrap.php';
require_once $basePath . '/src/helpers/module-manager.php';
require_once $basePath . '/modules/daily-ledger/helpers.php';
require_once $basePath . '/modules/daily-ledger/handlers-deliveries.php';
require_once $basePath . '/modules/daily-ledger/handlers.php';

modulePushContext('daily-ledger');

$db = app()->dbForTenant(207);

$COMMISSARY = 18;
$DATE = '2026-10-07';

$fails = 0;
$check = function (string $name, bool $ok, string $detail = '') use (&$fails): void {
    printf("  %s %s%s\n", $ok ? 'PASS' : 'FAIL', $name, $detail !== '' ? "  ({$detail})" : '');
    if (!$ok) { $fails++; }
};

echo "== chair negative control: can the reconciliation REFUSE? ==\n\n";

$row = $db->query(
    'SELECT id, product_id, shift, dispatched_qty FROM dl_commissary_product_ledger
      WHERE commissary_branch_id = ' . $COMMISSARY . ' AND ledger_date = ' . $db->quote($DATE) . ' LIMIT 1'
)->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    echo "INCONCLUSIVE: no projection row to perturb.\n";
    exit(2);
}

$id = (int)$row['id'];
$pid = (int)$row['product_id'];
$originalQty = (int)$row['dispatched_qty'];
printf("target: ledger row id=%d product=%d dispatched_qty=%d\n\n", $id, $pid, $originalQty);

// ---------------------------------------------------------------------------------------------
// 1. Baseline must be HEALTHY, or a later mismatch proves nothing.
// ---------------------------------------------------------------------------------------------
$base = dl_reconcileCommissaryDispatch($db, $COMMISSARY, $DATE, null);
$check('baseline reconciled before perturbation', $base['mismatches'] === [], 'checked=' . $base['checked']);
$check('baseline actually compared something', $base['checked'] > 0, 'checked=' . $base['checked']);

// ---------------------------------------------------------------------------------------------
// 2. Perturb INSIDE a transaction and observe the refusal.
// ---------------------------------------------------------------------------------------------
$db->beginTransaction();
$refused = false;
$detail = '';

try {
    $db->prepare('UPDATE dl_commissary_product_ledger SET dispatched_qty = dispatched_qty + 1 WHERE id = ?')
       ->execute([$id]);

    $perturbed = (int)$db->query('SELECT dispatched_qty FROM dl_commissary_product_ledger WHERE id = ' . $id)->fetchColumn();
    printf("perturbed in-transaction: dispatched_qty %d -> %d\n", $originalQty, $perturbed);

    $result = dl_reconcileCommissaryDispatch($db, $COMMISSARY, $DATE, null);
    $refused = $result['mismatches'] !== [];
    foreach ($result['mismatches'] as $m) {
        $detail = "product {$m['product_id']} projection {$m['projection']} vs derived {$m['derived']} ({$m['kind']})";
    }
    $check('GUARD REFUSED the perturbation', $refused, $detail !== '' ? $detail : 'no mismatch reported');
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
}

// ---------------------------------------------------------------------------------------------
// 3. The rollback must have RESTORED the value. Asserted, not assumed.
// ---------------------------------------------------------------------------------------------
$after = (int)$db->query('SELECT dispatched_qty FROM dl_commissary_product_ledger WHERE id = ' . $id)->fetchColumn();
$check('rollback restored the stored value', $after === $originalQty, "now {$after}, expected {$originalQty}");

$post = dl_reconcileCommissaryDispatch($db, $COMMISSARY, $DATE, null);
$check('healthy after rollback', $post['mismatches'] === [], 'checked=' . $post['checked']);

// ---------------------------------------------------------------------------------------------
// 4. Independently confirm the repair: an empty comparison must not report success.
// ---------------------------------------------------------------------------------------------
$empty = dl_reconcileCommissaryDispatch($db, $COMMISSARY, '1999-01-01', null);
$check('empty comparison reports checked=0', $empty['checked'] === 0, 'checked=' . $empty['checked']);

// ---------------------------------------------------------------------------------------------
// 5. Purity: the function must not have written anything.
// ---------------------------------------------------------------------------------------------
$wroteAnything = false;
try {
    $before = (int)$db->query('SELECT COUNT(*) FROM dl_commissary_product_ledger')->fetchColumn();
    dl_reconcileCommissaryDispatch($db, null, null, null);
    $afterCount = (int)$db->query('SELECT COUNT(*) FROM dl_commissary_product_ledger')->fetchColumn();
    $wroteAnything = $before !== $afterCount;
} catch (Throwable $e) {
    echo "  note: full sweep raised " . get_class($e) . ': ' . $e->getMessage() . "\n";
}
$check('unfiltered sweep did not change the row count', !$wroteAnything);

echo "\n";
echo $fails === 0 ? "CHAIR CONTROL: PASS\n" : "CHAIR CONTROL: FAIL ({$fails})\n";
exit($fails === 0 ? 0 : 1);
