<?php

declare(strict_types=1);

/**
 * Daily Ledger — delivery variances are cross-shift and must stay visible.
 *
 * Chair decision (see .ai/variance-shift-decision-contract.md): a delivery
 * sent-vs-received variance is a cross-shift event — the goods are produced in
 * one shift and received into another — so the flag keeps shift = NULL and is
 * never bucketed. The defect this suite pins is VISIBILITY:
 *
 *   AC1  the admin variance filter is `(vf.shift = :shift OR vf.shift IS NULL)`,
 *        so a delivery flag appears under an AM filter AND under a PM filter;
 *        the old `vf.shift = :shift` predicate hid it from both.
 *   AC2  the aggregate counts of the shift-scoped kinds (overnight/handoff/
 *        ending/sales) under a shift filter are byte-identical under the old
 *        and the new predicate.
 *   AC3  the rendered delivery row shows both shift facts, labelled, with a
 *        NULL rendered as the words "not recorded" (never a blank, never 0).
 *   AC4  the writer still stores shift = NULL and names kind = 'delivery'
 *        (the column default is 'overnight' — a latent hazard).
 *   AC5  no delivery flag is backfilled and no production handoff flag appears.
 *
 * Tenant 207 (baronledger). Every fixture is synthetic and removed in finally,
 * with the removal proven by re-querying row counts.
 */

ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-delivery-variance-visibility', TestHarness::MODE_INTEGRATION, 'baronledger.test');
ob_end_clean();

$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/helpers.php';
require_once $base . '/modules/daily-ledger/handlers-deliveries.php';
require_once $base . '/modules/daily-ledger/handlers.php';

$h->fingerprint('modules/daily-ledger/handlers.php');
$h->fingerprint('templates/modules/daily-ledger/admin/variances.disyl');
$h->fingerprint('tests/daily-ledger/daily_ledger_preserve_cashier_variance_harness.php');
$h->allowLogLines('disyl.compile.phases');

app()->tenant()->setTenantId(207);
$ctx = modulePushContext('daily-ledger');
if (!$ctx) {
    fwrite(STDERR, "daily-ledger module context unavailable\n");
    exit(1);
}
$db = $ctx->db();

$commissary = 99751;
$destination = 99752;
$productA = 99751;
$deliveryShifted = 997851;   // has production_shift + received_shift
$deliveryNull = 997852;      // both shift facts NULL
$dateShifted = '2035-04-10';
$dateNull = '2035-04-11';
$flashShifted = 'AM';
$receiveShifted = 'PM';
$payloadFile = tempnam(sys_get_temp_dir(), 'dl-dv-vis-');

$countFixtures = static function () use ($db, $commissary, $destination, $productA, $deliveryShifted, $deliveryNull): array {
    $branches = "{$commissary},{$destination}";
    $deliveries = "{$deliveryShifted},{$deliveryNull}";
    return [
        'branches'            => (int)$db->query("SELECT COUNT(*) FROM dl_branches WHERE id IN ({$branches})")->fetchColumn(),
        'products'            => (int)$db->query("SELECT COUNT(*) FROM dl_products WHERE id = {$productA}")->fetchColumn(),
        'branch_products'     => (int)$db->query("SELECT COUNT(*) FROM dl_branch_products WHERE product_id = {$productA} OR branch_id IN ({$branches})")->fetchColumn(),
        'deliveries'          => (int)$db->query("SELECT COUNT(*) FROM dl_deliveries WHERE id IN ({$deliveries})")->fetchColumn(),
        'delivery_items'      => (int)$db->query("SELECT COUNT(*) FROM dl_delivery_items WHERE delivery_id IN ({$deliveries})")->fetchColumn(),
        'receivings'          => (int)$db->query("SELECT COUNT(*) FROM dl_branch_receivings WHERE branch_id IN ({$branches})")->fetchColumn(),
        'receiving_items'     => (int)$db->query("SELECT COUNT(*) FROM dl_branch_receiving_items WHERE product_id = {$productA}")->fetchColumn(),
        'variance_flags'      => (int)$db->query("SELECT COUNT(*) FROM dl_variance_flags WHERE branch_id IN ({$branches}) OR product_id = {$productA}")->fetchColumn(),
        'notifications'       => (int)$db->query("SELECT COUNT(*) FROM dl_integrity_notifications WHERE branch_id IN ({$branches}) OR aggregate_key LIKE 'delivery-variance-%'")->fetchColumn(),
        'notification_recips' => (int)$db->query("SELECT COUNT(*) FROM dl_integrity_notification_recipients WHERE notification_id IN (SELECT id FROM dl_integrity_notifications WHERE branch_id IN ({$branches}) OR aggregate_key LIKE 'delivery-variance-%')")->fetchColumn(),
    ];
};

$cleanup = static function () use ($db, $commissary, $destination, $productA, $deliveryShifted, $deliveryNull, $dateShifted, $dateNull): void {
    $branches = "{$commissary},{$destination}";
    $deliveries = "{$deliveryShifted},{$deliveryNull}";
    $dates = "'{$dateShifted}','{$dateNull}'";
    $db->execute("DELETE FROM dl_integrity_notification_recipients WHERE notification_id IN (SELECT id FROM dl_integrity_notifications WHERE branch_id IN ({$branches}) OR aggregate_key LIKE 'delivery-variance-%')");
    $db->execute("DELETE FROM dl_integrity_notifications WHERE branch_id IN ({$branches}) OR aggregate_key LIKE 'delivery-variance-%'");
    $db->execute("DELETE FROM audit_logs WHERE module='daily-ledger' AND branch_id IN ({$branches})");
    $db->execute("DELETE FROM dl_delivery_variance_flags WHERE delivery_id IN ({$deliveries}) OR product_id = {$productA}");
    $db->execute("DELETE FROM dl_variance_flags WHERE branch_id IN ({$branches}) OR product_id = {$productA}");
    $db->execute("DELETE FROM dl_branch_receiving_items WHERE receiving_id IN (SELECT id FROM dl_branch_receivings WHERE branch_id IN ({$branches}))");
    $db->execute("DELETE FROM dl_branch_receivings WHERE branch_id IN ({$branches}) OR delivery_id IN ({$deliveries})");
    $db->execute("DELETE FROM dl_delivery_ledger_effects WHERE delivery_id IN ({$deliveries})");
    $db->execute("DELETE FROM dl_delivery_items WHERE delivery_id IN ({$deliveries}) OR product_id = {$productA}");
    $db->execute("DELETE FROM dl_deliveries WHERE id IN ({$deliveries}) OR destination_id IN ({$branches}) OR origin_id IN ({$branches})");
    $db->execute("DELETE FROM dl_ledger_shift_status WHERE branch_id IN ({$branches}) AND ledger_date IN ({$dates})");
    $db->execute("DELETE FROM dl_daily_ledger WHERE branch_id IN ({$branches}) AND product_id = {$productA}");
    $db->execute("DELETE FROM dl_commissary_product_ledger WHERE commissary_branch_id IN ({$branches}) AND product_id = {$productA}");
    $db->execute("DELETE FROM dl_branch_products WHERE branch_id IN ({$branches}) OR product_id = {$productA}");
    $db->execute("DELETE FROM dl_products WHERE id = {$productA}");
    $db->execute("DELETE FROM dl_branches WHERE id IN ({$branches})");
};

$runPage = static function (array $params) use ($payloadFile): string {
    // handleAdminVariances() reads app()->input(); with a JSON content type
    // that is the decoded request body, so the filter params must travel in
    // `body` (the harness's `get` array is ignored for JSON requests).
    file_put_contents($payloadFile, json_encode(['role' => 'admin', 'body' => $params, 'get' => $params], JSON_THROW_ON_ERROR));
    $out = [];
    $code = 0;
    exec(
        escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/daily_ledger_preserve_cashier_variance_harness.php')
        . ' variances-page ' . escapeshellarg($payloadFile) . ' 2>/dev/null',
        $out,
        $code
    );
    return implode("\n", $out);
};

/** Pull the rendered chunk for one delivery flag id. */
$deliveryChunk = static function (string $html, int $flagId): string {
    $needle = 'data-delivery-variance="' . $flagId . '"';
    $start = strpos($html, $needle);
    if ($start === false) {
        return '';
    }
    $next = strpos($html, 'data-delivery-variance="', $start + strlen($needle));
    return $next === false ? substr($html, $start) : substr($html, $start, $next - $start);
};

$emit = static function (string $label, $data): void {
    echo '    [measured] ' . $label . ' = ' . json_encode($data, JSON_UNESCAPED_UNICODE) . PHP_EOL;
};

$h->section('Source: the predicate and the two shift joins');

$handlersSrc = (string)file_get_contents($base . '/modules/daily-ledger/handlers.php');
$tplSrc = (string)file_get_contents($base . '/templates/modules/daily-ledger/admin/variances.disyl');

$h->test(
    'AC1 the shift filter admits NULL-shift rows: AND (vf.shift = :shift OR vf.shift IS NULL)',
    str_contains($handlersSrc, 'AND (vf.shift = :shift OR vf.shift IS NULL)')
);

$h->test(
    'AC1 the bare vf.shift = :shift predicate is gone from the admin scope',
    !preg_match('/\$whereScope \.= \' AND vf\.shift = :shift\';/', $handlersSrc)
);

$h->test(
    'AC3 the admin list joins both delivery shift sources under distinct aliases',
    str_contains($handlersSrc, 'LEFT JOIN dl_deliveries d ON d.id = vf.delivery_id')
    && str_contains($handlersSrc, 'LEFT JOIN dl_branch_receivings r ON r.id = vf.receiving_id')
    && str_contains($handlersSrc, 'd.production_shift AS delivery_production_shift')
    && str_contains($handlersSrc, 'r.received_shift AS delivery_received_shift')
);

$h->test(
    'AC3 the template labels the two shifts and never blanks a NULL',
    str_contains($tplSrc, 'Produced:')
    && str_contains($tplSrc, 'Received into:')
    && str_contains($tplSrc, 'not recorded')
);

$h->test(
    'AC4 the delivery writer still names kind = \'delivery\' and shift stays NULL',
    str_contains($handlersSrc, "VALUES (:bid, :pid, :d, 'delivery', NULL, :delivery, :rcv")
    && str_contains($handlersSrc, 'SET branch_id = :bid, product_id = :pid, ledger_date = :d, shift = NULL,')
);

$h->test(
    'AC4 the kind-default hazard is warned at the insert site',
    str_contains($handlersSrc, "dl_variance_flags.kind is NOT NULL with DEFAULT 'overnight'")
    && str_contains($handlersSrc, 'dl_upsertVarianceFlag() must NOT be used for')
);

$raiseStart = strpos($handlersSrc, 'function dl_raiseDeliveryVariance');
$raiseEnd = strpos($handlersSrc, 'function dl_raiseDailySheetCorrectionVariance');
$raiseBody = ($raiseStart !== false && $raiseEnd !== false) ? substr($handlersSrc, $raiseStart, $raiseEnd - $raiseStart) : '';
$h->test(
    'AC4 the delivery writer does not call dl_upsertVarianceFlag (it would coerce kind to overnight)',
    $raiseBody !== '' && !str_contains($raiseBody, 'dl_upsertVarianceFlag($db')
);
$h->test(
    'AC5 the delivery writer raises no production handoff flag',
    $raiseBody !== '' && !str_contains($raiseBody, "'handoff'")
);

$cleanup();
$tenantBefore = $countFixtures();

try {
    // ─── Fixture: one shifted delivery and one with both shifts unknown ───
    $db->prepare('INSERT INTO dl_branches (id,code,name,is_commissary,is_active) VALUES (?,?,?,1,1)')
        ->execute([$commissary, 'DV-COMM', 'DV Commissary']);
    $db->prepare('INSERT INTO dl_branches (id,code,name,is_commissary,assigned_commissary_id,default_supply_mode,is_active) VALUES (?,?,?,0,?,"commissary_supplied",1)')
        ->execute([$destination, 'DV-DEST', 'DV Destination', $commissary]);
    $db->prepare('INSERT INTO dl_products (id,sku,name,current_price,is_active) VALUES (?,?,?,10,1)')
        ->execute([$productA, 'DV-P', 'DV Product']);
    $db->prepare('INSERT INTO dl_branch_products (branch_id,product_id,is_active) VALUES (?,?,1)')
        ->execute([$commissary, $productA]);
    $db->prepare('INSERT INTO dl_commissary_product_ledger (commissary_branch_id,product_id,ledger_date,beg_qty,produced_qty,dispatched_qty,actual_end_qty) VALUES (?,?,?,30,30,20,40)')
        ->execute([$commissary, $productA, $dateShifted]);

    $db->prepare('INSERT INTO dl_deliveries (id,origin_type,origin_id,destination_type,destination_id,dr_number,delivery_date,production_shift,status,receipt_required) VALUES (?,"commissary",?,"branch",?,?,?,?,"posted",1)')
        ->execute([$deliveryShifted, $commissary, $destination, 'DV-DR-SHIFT', $dateShifted, $flashShifted]);
    $db->prepare('INSERT INTO dl_delivery_items (delivery_id,product_id,quantity,unit,price_snapshot) VALUES (?,?,10,"pcs",10)')
        ->execute([$deliveryShifted, $productA]);
    $db->prepare('INSERT INTO dl_deliveries (id,origin_type,origin_id,destination_type,destination_id,dr_number,delivery_date,production_shift,status,receipt_required) VALUES (?,"commissary",?,"branch",?,?,?,NULL,"posted",1)')
        ->execute([$deliveryNull, $commissary, $destination, 'DV-DR-NULL', $dateNull]);
    $db->prepare('INSERT INTO dl_delivery_items (delivery_id,product_id,quantity,unit,price_snapshot) VALUES (?,?,10,"pcs",10)')
        ->execute([$deliveryNull, $productA]);

    // Receiving shift is persisted by the real acceptance path.
    $receivingShifted = dl_acceptFormalDelivery($db, $destination, $deliveryShifted, 1, $dateShifted, [$productA => 4], $receiveShifted);
    $receivingNull = dl_acceptFormalDelivery($db, $destination, $deliveryNull, 1, $dateNull, [$productA => 4], 'AM');
    // The NULL-shift fixture: this delivery was recorded before shifts were
    // captured. Force both facts back to NULL so the renderer's "not recorded"
    // branch is exercised for real (no backfill exists to fill them).
    $db->prepare('UPDATE dl_deliveries SET production_shift = NULL WHERE id = ?')->execute([$deliveryNull]);
    $db->prepare('UPDATE dl_branch_receivings SET received_shift = NULL WHERE id = ?')->execute([$receivingNull]);

    // Raise the real delivery variance: production sent 10, cashier counted 4.
    $flagShifted = dl_raiseDeliveryVariance($db, $deliveryShifted, $receivingShifted, $productA, 10, 4, 1);
    $flagNull = dl_raiseDeliveryVariance($db, $deliveryNull, $receivingNull, $productA, 10, 4, 1);
    $flagShifted = (int)$flagShifted;
    $flagNull = (int)$flagNull;

    $rowShifted = $db->query("SELECT kind, shift, delivery_id, receiving_id, sent_qty, received_qty, variance FROM dl_variance_flags WHERE id = {$flagShifted}")->fetch(PDO::FETCH_ASSOC) ?: [];
    $rowNull = $db->query("SELECT kind, shift, delivery_id, receiving_id FROM dl_variance_flags WHERE id = {$flagNull}")->fetch(PDO::FETCH_ASSOC) ?: [];
    $shiftFactsShifted = $db->query("SELECT d.production_shift, r.received_shift FROM dl_deliveries d JOIN dl_branch_receivings r ON r.id = {$receivingShifted} WHERE d.id = {$deliveryShifted}")->fetch(PDO::FETCH_ASSOC) ?: [];
    $shiftFactsNull = $db->query("SELECT d.production_shift, r.received_shift FROM dl_deliveries d JOIN dl_branch_receivings r ON r.id = {$receivingNull} WHERE d.id = {$deliveryNull}")->fetch(PDO::FETCH_ASSOC) ?: [];

    $emit('delivery flag (shifted)', $rowShifted);
    $emit('delivery flag (NULL shifts)', $rowNull);
    $emit('joined shift facts (shifted)', $shiftFactsShifted);
    $emit('joined shift facts (NULL)', $shiftFactsNull);

    $h->section('AC1 — the cross-shift delivery flag is visible under both filters');

    $pageAm = $runPage(['branch_id' => $destination, 'date_from' => $dateShifted, 'date_to' => $dateShifted, 'shift' => 'AM']);
    $pagePm = $runPage(['branch_id' => $destination, 'date_from' => $dateShifted, 'date_to' => $dateShifted, 'shift' => 'PM']);

    $amHasFlag = str_contains($pageAm, 'data-delivery-variance="' . $flagShifted . '"');
    $pmHasFlag = str_contains($pagePm, 'data-delivery-variance="' . $flagShifted . '"');
    $emit('flag ' . $flagShifted . ' visible under AM filter', $amHasFlag);
    $emit('flag ' . $flagShifted . ' visible under PM filter', $pmHasFlag);
    $emit('AM page contains the flag id', str_contains($pageAm, (string)$flagShifted));
    $emit('PM page contains the flag id', str_contains($pagePm, (string)$flagShifted));

    $h->test('AC1 delivery flag is visible under an AM shift filter (old predicate hid it)', $amHasFlag);
    $h->test('AC1 delivery flag is visible under a PM shift filter (old predicate hid it)', $pmHasFlag);
    $h->test('AC1 the AM and PM pages are the same finding, not two different rows', str_contains($pageAm, 'Sent 10') && str_contains($pagePm, 'Sent 10'));

    $h->section('AC3 — both shifts render, and NULL reads "not recorded"');

    $chunkShifted = $deliveryChunk($pageAm, $flagShifted);
    $chunkNull = $deliveryChunk($runPage(['branch_id' => $destination, 'date_from' => $dateNull, 'date_to' => $dateNull, 'shift' => 'AM']), $flagNull);
    $emit('rendered chunk (shifted)', $chunkShifted);
    $emit('rendered chunk (NULL)', $chunkNull);

    $h->test(
        'AC3 shifted delivery renders "Produced: AM" and "Received into: PM"',
        str_contains($chunkShifted, 'Produced: AM') && str_contains($chunkShifted, 'Received into: PM')
    );
    $h->test(
        'AC3 NULL delivery renders "Produced: not recorded" and "Received into: not recorded"',
        str_contains($chunkNull, 'Produced: not recorded') && str_contains($chunkNull, 'Received into: not recorded')
    );
    $h->test(
        'AC3 the NULL row never renders a blank or a zero for a shift fact',
        !preg_match('/Produced:\s*(?:&mdash;|—|0|<)/u', $chunkNull)
        && !preg_match('/Received into:\s*(?:&mdash;|—|0|<)/u', $chunkNull)
    );

    $h->section('AC2 — shift-scoped kind counts are unchanged by the predicate');

    $scopeSql = 'FROM dl_variance_flags vf
            INNER JOIN dl_products p ON p.id = vf.product_id
            INNER JOIN dl_branches b ON b.id = vf.branch_id';
    $kinds = "kind IN ('overnight','handoff','ending','sales')";
    $counts = [];
    foreach (['AM', 'PM'] as $shift) {
        foreach (['old' => 'vf.shift = :shift', 'new' => '(vf.shift = :shift OR vf.shift IS NULL)'] as $label => $pred) {
            $stmt = $db->prepare("SELECT vf.kind, COUNT(*) AS c {$scopeSql} WHERE {$pred} AND vf.{$kinds} GROUP BY vf.kind ORDER BY vf.kind");
            $stmt->execute([':shift' => $shift]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                $counts[$shift][$label][(string)$r['kind']] = (int)$r['c'];
            }
        }
    }
    $emit('shift-scoped counts old vs new', $counts);
    $same = true;
    foreach (['AM', 'PM'] as $shift) {
        foreach (['overnight', 'handoff', 'ending', 'sales'] as $kind) {
            $old = $counts[$shift]['old'][$kind] ?? 0;
            $new = $counts[$shift]['new'][$kind] ?? 0;
            if ($old !== $new) {
                $same = false;
            }
        }
    }
    $h->test('AC2 every overnight/handoff/ending/sales count is identical old vs new predicate', $same);

    // A NULL shift is only ever legitimate on a delivery flag. If any other
    // kind had a NULL shift, the widened predicate would pull it into a shift
    // tally it does not belong to. Assert that cannot happen.
    $nullNonDelivery = (int)$db->query("SELECT COUNT(*) FROM dl_variance_flags WHERE shift IS NULL AND kind <> 'delivery'")->fetchColumn();
    $nullDelivery = (int)$db->query("SELECT COUNT(*) FROM dl_variance_flags WHERE shift IS NULL AND kind = 'delivery'")->fetchColumn();
    $emit('NULL-shift non-delivery flags', $nullNonDelivery);
    $emit('NULL-shift delivery flags (fixtures)', $nullDelivery);
    $h->test('AC2 the widened predicate cannot admit a shift-scoped kind: no non-delivery NULL shift exists', $nullNonDelivery === 0);
} finally {
    $cleanup();
}

$tenantAfter = $countFixtures();
$emit('fixture counts before', $tenantBefore);
$emit('fixture counts after cleanup', $tenantAfter);

$h->section('Cleanup — every fixture row is gone');

$fixtureKeys = array_keys($tenantBefore);
$fixtureClean = true;
foreach ($fixtureKeys as $key) {
    if (($tenantBefore[$key] ?? 0) !== ($tenantAfter[$key] ?? 0)) {
        $fixtureClean = false;
        $emit('cleanup mismatch ' . $key, ['before' => $tenantBefore[$key], 'after' => $tenantAfter[$key]]);
    }
}
$h->test('every fixture table is back to its before count', $fixtureClean);
$h->test('no fixture branches/products remain', ($tenantAfter['branches'] ?? 0) === 0 && ($tenantAfter['products'] ?? 0) === 0);
$h->test('the delivery variance flags created by this suite are deleted', ($tenantAfter['variance_flags'] ?? -1) === 0);

$h->done();
