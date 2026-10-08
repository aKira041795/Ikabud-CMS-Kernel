<?php
/**
 * Slice 2 acceptance probe (DRIVER) — consignee verification at admin Deliveries.
 *
 * A GATE: it must FAIL on the unchanged tree. It asserts OUTCOMES an operator observes, never
 * internal mechanisms. Each handler runs in a CHILD process because $ctx->json() exits the process;
 * a dead or empty child is treated as FAIL (fail closed), never as PASS.
 *
 *   A  PIN — the listing already returns consignee deliveries on the base tree (measured 2026-10-08:
 *      http 200, 1 row), so it cannot discriminate. It guards against a regression that drops them.
 *   B  DISCRIMINATING — an admin can mark a consignee delivery verified against the paper DR.
 *      Base tree: HTTP 422 "Only captured paper-DR deliveries can be checked here."
 *   C  PIN — verifying moves NO quantity (catches the tempting "verify = post" implementation)
 *   D  pin — the fixture leaves nothing behind
 */
declare(strict_types=1);

$basePath = '/var/www/html/applicationostest';
require_once $basePath . '/src/helpers/cli-bootstrap.php';

$results = [];
function probe(string $label, bool $ok, string $detail = ''): void {
    global $results;
    $results[] = [$label, $ok];
    printf("  %s %s%s\n", $ok ? 'PASS' : 'FAIL', $label, $detail !== '' ? "  ($detail)" : '');
}

$app = kernelCliBootstrap($basePath);
$app->tenant()->setTenantId(207);
require_once $basePath . '/src/helpers/module-manager.php';
require_once $basePath . '/modules/daily-ledger/helpers.php';
require_once $basePath . '/modules/daily-ledger/handlers-deliveries.php';
require_once $basePath . '/modules/daily-ledger/handlers.php';
modulePushContext('daily-ledger');

$db = app()->dbForTenant(207);
$TAG = 'S2GATE';
$CODE = $TAG . '-CONS-1';
$DR = $TAG . '-DR-1';
$DATE = date('Y-m-d');
$SOURCE_BRANCH = 8;      // DPL-MP1
$PRODUCT = (int)$db->query('SELECT id FROM dl_products ORDER BY id LIMIT 1')->fetchColumn();
if ($PRODUCT <= 0) { fwrite(STDERR, "no product in tenant 207\n"); exit(2); }

/** Run one handler in a child process; return [status, decoded-json|null]. A dead child => [0, null]. */
function callApi(string $mode, array $payload, string $basePath): array {
    $tmp = tempnam(sys_get_temp_dir(), 's2gate');
    file_put_contents($tmp, json_encode($payload, JSON_THROW_ON_ERROR));
    $cmd = sprintf('php %s %s %s 20 admin 2>/dev/null',
        escapeshellarg($basePath . '/tools/lane-consignee-slice2-child.php'),
        escapeshellarg($mode), escapeshellarg($tmp));
    $out = (string)shell_exec($cmd);
    @unlink($tmp);
    if (!preg_match('/__HTTP_STATUS__=(\d+)/', $out, $m)) {
        return [0, null];   // fail closed: no status marker means the child died
    }
    $body = trim((string)preg_replace('/\n?__HTTP_STATUS__=\d+\s*$/', '', $out));
    return [(int)$m[1], json_decode($body, true)];
}

// ---------------------------------------------------------------- fixture
$consigneeId = 0;
$deliveryId = 0;
$cleanup = static function () use ($db, $CODE, $DR, $TAG, &$consigneeId, &$deliveryId): void {
    $cid = (int)($consigneeId ?: $db->query("SELECT id FROM dl_consignees WHERE code = " . $db->quote($CODE))->fetchColumn());
    $did = (int)($deliveryId ?: $db->query("SELECT id FROM dl_deliveries WHERE dr_number = " . $db->quote($DR))->fetchColumn());
    if ($did > 0) {
        $db->exec("DELETE FROM dl_delivery_items WHERE delivery_id = $did");
        $db->exec("DELETE FROM dl_delivery_variance_flags WHERE delivery_id = $did");
        $db->exec("DELETE FROM dl_deliveries WHERE id = $did");
    }
    if ($cid > 0) {
        $db->exec("DELETE FROM dl_consignee_ledger WHERE consignee_id = $cid");
        $db->exec("DELETE FROM dl_consignees WHERE id = $cid");
    }
};
$cleanup();

$db->prepare('INSERT INTO dl_consignees (code, name, assigned_commissary_id, is_active) VALUES (?,?,?,1)')
   ->execute([$CODE, $TAG . ' Gate Consignee', 18]);
$consigneeId = (int)$db->lastInsertId();

$db->prepare('INSERT INTO dl_deliveries
    (origin_type, origin_id, destination_type, destination_id, consignee_id, dr_number,
     delivery_date, production_shift, status, provenance_status, remarks, created_by, posted_by, posted_at)
    VALUES ("branch", ?, "consignee", NULL, ?, ?, ?, "AM", "posted", "paper_dr_pending", "[cashier-dispatch]", ?, ?, NOW())')
   ->execute([$SOURCE_BRANCH, $consigneeId, $DR, $DATE, 19, 19]);
$deliveryId = (int)$db->lastInsertId();

$db->prepare('INSERT INTO dl_delivery_items (delivery_id, product_id, quantity, unit, unit_cost_snapshot, price_snapshot, price_group_id, remarks)
              VALUES (?,?,?,?,0,0,1,"[cashier-dispatch]")')
   ->execute([$deliveryId, $PRODUCT, 7, 'pcs']);

$db->prepare('INSERT INTO dl_consignee_ledger (consignee_id, product_id, ledger_date, shift, price_snapshot, beg_bal, addtl, withdraw, encoded_by)
              VALUES (?,?,?,"AM",0,0,7,0,19)')
   ->execute([$consigneeId, $PRODUCT, $DATE]);

$ledgerBefore = (string)$db->query("SELECT COALESCE(SUM(addtl - withdraw),0) FROM dl_consignee_ledger WHERE consignee_id = $consigneeId")->fetchColumn();

echo "== slice 2 acceptance gate ==\n";

// A. the listing can surface consignee deliveries
[$aStatus, $aJson] = callApi('list', ['destination_type' => 'consignee'], $basePath);
$listed = false;
foreach ((array)($aJson['deliveries'] ?? []) as $row) {
    if ((int)($row['id'] ?? 0) === $deliveryId) { $listed = true; }
}
probe('A pin: the admin Deliveries listing returns consignee deliveries', $listed,
      'http=' . $aStatus . ' rows=' . count((array)($aJson['deliveries'] ?? [])));

// B. an admin can verify a consignee delivery against the paper DR
[$bStatus, $bJson] = callApi('review', ['delivery_id' => $deliveryId, 'action' => 'accepted', 'note' => 'paper DR seen'], $basePath);
$afterStatus = (string)$db->query("SELECT provenance_status FROM dl_deliveries WHERE id = $deliveryId")->fetchColumn();
$reviewedBy  = (string)$db->query("SELECT COALESCE(provenance_reviewed_by,0) FROM dl_deliveries WHERE id = $deliveryId")->fetchColumn();
probe('B an admin can mark a consignee delivery verified (paper DR evidence)',
      ($bJson['ok'] ?? false) === true && $afterStatus === 'accepted' && (int)$reviewedBy > 0,
      'http=' . $bStatus . ' err=' . (string)($bJson['error'] ?? '-') . ' prov=' . $afterStatus);

// C. PIN - verification must not move a single quantity
$ledgerAfter = (string)$db->query("SELECT COALESCE(SUM(addtl - withdraw),0) FROM dl_consignee_ledger WHERE consignee_id = $consigneeId")->fetchColumn();
probe('C pin: verifying posts nothing to the consignee ledger', $ledgerBefore === $ledgerAfter,
      "before=$ledgerBefore after=$ledgerAfter");

$cleanup();
$leaked = (int)$db->query("SELECT COUNT(*) FROM dl_consignees WHERE code LIKE '" . $TAG . "%'")->fetchColumn()
        + (int)$db->query("SELECT COUNT(*) FROM dl_deliveries WHERE dr_number LIKE '" . $TAG . "%'")->fetchColumn();
probe('D pin: fixture cleaned up (no rows left behind)', $leaked === 0, "leaked=$leaked");

$failed = array_values(array_filter($results, static fn(array $r): bool => !$r[1]));
if ($failed === []) { echo "PASS: consignee deliveries can be verified at admin Deliveries as evidence only\n"; exit(0); }
echo 'FAILED: ' . implode(' | ', array_map(static fn(array $r): string => $r[0], $failed)) . "\n";
exit(1);
