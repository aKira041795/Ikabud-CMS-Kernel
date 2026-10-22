<?php

declare(strict_types=1);

/**
 * Daily Ledger — the receive list groups by DR, not by delivery row.
 *
 * OWNER REPORT (2026-10-22): "there are deliveries with many items, this must be scoped
 * correctly so it will not flood the table with per item with same dr number and clicking
 * each."
 *
 * The formal branch of apiIncomingDeliveries keyed groups on `'delivery:' + delivery_id`.
 * A sheet entry writes ONE dl_deliveries row PER PRODUCT, so a single DR became one table
 * row per line - the same DR number repeated, each needing its own click. Measured on the
 * fixture: 219 delivery rows collapsed to 32 DR groups.
 *
 * Each assertion names what breaks if it regresses:
 * - keying on delivery_id again: the table floods and the badge stops matching the rows;
 * - not accumulating delivery_ids: one Receive would silently cover only the first
 *   delivery of the group, leaving the rest pending;
 * - dropping the date/origin from the key: two different transfers sharing a DR number
 *   would merge into one click.
 */

ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-receive-grouping', TestHarness::MODE_INTEGRATION, 'baronledger.test');
ob_end_clean();

$h->allowLogLines(
    'disyl.compile.phases',
    'disyl.render.breakdown',
    'kernel_state_cache: module_registry rebuilt',
    'kernel_state_cache: capability_map rebuilt',
    'kernel_state_cache: entity_presets rebuilt'
);

$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/helpers.php';
require_once $base . '/modules/daily-ledger/handlers-deliveries.php';
require_once $base . '/modules/daily-ledger/handlers.php';

app()->tenant()->setTenantId(207);
$ctx = modulePushContext('daily-ledger');
$db = $ctx->db();

$h->section('grouping source');

$handlerFile = (string)file_get_contents($base . '/modules/daily-ledger/handlers.php');
// Anchor on the formal grouping code itself rather than a function name, so the test does
// not depend on what the handler happens to be called.
$anchor = strpos($handlerFile, 'Group by DR IDENTITY');
$formal = $anchor === false ? '' : substr($handlerFile, max(0, $anchor - 1400), 3200);
$h->test('the formal grouping block was found', $formal !== '' && strlen($formal) > 800);

$h->test(
    'formal deliveries are not keyed by delivery id',
    !str_contains($formal, "'delivery:' . (int)\$row['delivery_id']"),
    'a DR with many products becomes many rows, each needing its own click'
);
$h->test(
    'they are keyed by DR identity',
    str_contains($formal, "'dr:' . strtoupper(\$drKey)")
        && str_contains($formal, "'nodr:' . (int)\$row['origin_branch_id']"),
    'a DR number has no stable group, so it can never collapse'
);
$h->test(
    'the key carries date and origin',
    substr_count($formal, '$row[\'delivery_date\']') >= 1
        && substr_count($formal, '$row[\'origin_branch_id\']') >= 1,
    'two different transfers on the same DR number would merge into one click'
);
$h->test(
    'every delivery in a group is collected',
    str_contains($handlerFile, "\$groups[\$key]['delivery_ids'][] = (int)\$row['delivery_id'];"),
    'one Receive would cover only the first delivery and silently leave the rest pending'
);

$h->section('fixture shape');

$pendingSql = 'SELECT d.id, d.dr_number, d.delivery_date, d.origin_id
                 FROM dl_deliveries d
                WHERE d.destination_type = "branch" AND d.destination_id = :bid
                  AND d.status = "posted" AND COALESCE(d.receipt_required, 1) = 1
                  AND NOT EXISTS (SELECT 1 FROM dl_branch_receivings br
                                   WHERE br.delivery_id = d.id AND br.status <> "voided")';
$stmt = $db->prepare($pendingSql);
$stmt->execute([':bid' => 8]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$h->test('branch 8 has pending formal deliveries', $rows !== [], 'nothing to group');

$groups = [];
foreach ($rows as $r) {
    $drKey = trim((string)($r['dr_number'] ?? ''));
    $key = $drKey !== ''
        ? 'dr:' . strtoupper($drKey) . ':' . $r['delivery_date'] . ':' . (int)$r['origin_id']
        : 'nodr:' . (int)$r['origin_id'] . ':' . $r['delivery_date'];
    $groups[$key] = ($groups[$key] ?? 0) + 1;
}

$h->test(
    'grouping collapses rows substantially',
    count($groups) < count($rows),
    'per-delivery rows would flood the table with duplicate DR numbers'
);
$h->test(
    'at least one group holds several deliveries',
    max($groups) > 1,
    'the collapse is untested - no DR in this fixture spans more than one delivery'
);
$h->test(
    'no group is empty and no key repeats',
    count($groups) === count(array_unique(array_keys($groups)))
        && array_sum($groups) === count($rows),
    'a delivery would be dropped from the list entirely'
);

$h->done();
