<?php

declare(strict_types=1);

/**
 * Daily Ledger — the variance queue lands on OPEN findings, and notes save.
 *
 * Owner direction 2026-11-16 (client concern #3, Sir Ryan):
 *   - a variance that is already settled must disappear from the queue;
 *   - a KIND whose rows are all settled must disappear from the kind picker;
 *   - notes must save, including on a delivery finding that was already decided.
 *
 * This suite proves the three things end-to-end through the real page and the
 * real update handler:
 *   AC1  the default page shows unreviewed + investigated and NOT corrected;
 *        status=corrected and status=all remain reachable.
 *   AC2  the kind picker only offers kinds visible under the active filter.
 *   AC3  a note-only save stores the words and leaves status/reviewer untouched;
 *        a present-and-empty note clears; a note on a settled delivery row saves;
 *        the generic endpoint still refuses to SETTLE a delivery variance.
 *
 * Tenant 207. Fixtures are synthetic and removed in finally.
 */

ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-variance-queue-and-notes', TestHarness::MODE_INTEGRATION, 'baronledger.test');
ob_end_clean();

$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/helpers.php';
require_once $base . '/modules/daily-ledger/handlers-deliveries.php';
require_once $base . '/modules/daily-ledger/handlers.php';

$h->fingerprint('modules/daily-ledger/handlers.php');
$h->fingerprint('templates/modules/daily-ledger/admin/variances.disyl');

app()->tenant()->setTenantId(207);
$ctx = modulePushContext('daily-ledger');
if (!$ctx) {
    fwrite(STDERR, "daily-ledger module context unavailable\n");
    exit(1);
}
$db = $ctx->db();

$branch = 99871;
$product = 99871;
$date = '2035-05-01';
$payloadFile = tempnam(sys_get_temp_dir(), 'dl-vq-');

$cleanup = static function () use ($db, $branch, $product, $date): void {
    $db->execute('DELETE FROM dl_variance_flags WHERE branch_id = :b OR product_id = :p', [':b' => $branch, ':p' => $product]);
    $db->execute('DELETE FROM dl_daily_ledger WHERE branch_id = :b', [':b' => $branch]);
    $db->execute('DELETE FROM dl_ledger_shift_status WHERE branch_id = :b', [':b' => $branch]);
    $db->execute('DELETE FROM dl_branch_products WHERE branch_id = :b', [':b' => $branch]);
    $db->execute('DELETE FROM dl_branches WHERE id = :b', [':b' => $branch]);
    $db->execute('DELETE FROM dl_products WHERE id = :p', [':p' => $product]);
};

$runHarness = static function (string $mode, array $payload) use ($payloadFile): array {
    $payload['role'] = $payload['role'] ?? 'admin';
    file_put_contents($payloadFile, json_encode($payload, JSON_THROW_ON_ERROR));
    $out = [];
    $code = 0;
    exec(
        escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/daily_ledger_preserve_cashier_variance_harness.php')
        . ' ' . escapeshellarg($mode) . ' ' . escapeshellarg($payloadFile) . ' 2>/dev/null',
        $out,
        $code
    );
    $raw = implode("\n", $out);
    $json = json_decode($raw, true);
    return ['code' => $code, 'raw' => $raw, 'json' => is_array($json) ? $json : null];
};

$cleanup();
$insert = $db->prepare(
    'INSERT INTO dl_variance_flags
        (branch_id, product_id, ledger_date, kind, shift, variance, resolution_status, is_reviewed, review_note)
     VALUES (:b, :p, :d, :kind, :shift, :var, :status, :reviewed, :note)'
);

try {
    $db->execute(
        'INSERT INTO dl_branches (id, code, name, address, default_supply_mode, is_commissary, is_active)
         VALUES (:id, :code, :name, :addr, :mode, 0, 1)',
        [':id' => $branch, ':code' => 'VQ-TEST', ':name' => 'Variance Queue Test', ':addr' => 'Test', ':mode' => 'self_managed']
    );
    $db->execute(
        'INSERT INTO dl_products (id, sku, name, current_price, sort_order, is_active)
         VALUES (:id, :sku, :name, 10, 0, 1)',
        [':id' => $product, ':sku' => 'VQ-TEST', ':name' => 'Variance Queue Product']
    );
    $db->execute('INSERT INTO dl_branch_products (branch_id, product_id, is_active) VALUES (:b, :p, 1)', [':b' => $branch, ':p' => $product]);

    $openId = 0;
    $investigatedId = 0;
    $settledId = 0;
    $deliverySettledId = 0;
    $deliveryOpenId = 0;

    $insert->execute([':b' => $branch, ':p' => $product, ':d' => $date, ':kind' => 'sales', ':shift' => 'AM', ':var' => -5, ':status' => 'unreviewed', ':reviewed' => 0, ':note' => null]);
    $openId = (int)$db->lastInsertId();
    $insert->execute([':b' => $branch, ':p' => $product, ':d' => $date, ':kind' => 'sales', ':shift' => 'PM', ':var' => -2, ':status' => 'investigated', ':reviewed' => 1, ':note' => 'looking']);
    $investigatedId = (int)$db->lastInsertId();
    $insert->execute([':b' => $branch, ':p' => $product, ':d' => $date, ':kind' => 'ending', ':shift' => 'AM', ':var' => 4, ':status' => 'corrected', ':reviewed' => 1, ':note' => 'settled']);
    $settledId = (int)$db->lastInsertId();
    $insert->execute([':b' => $branch, ':p' => $product, ':d' => $date, ':kind' => 'delivery', ':shift' => null, ':var' => -3, ':status' => 'corrected', ':reviewed' => 1, ':note' => 'decided']);
    $deliverySettledId = (int)$db->lastInsertId();
    $insert->execute([':b' => $branch, ':p' => $product, ':d' => $date, ':kind' => 'delivery', ':shift' => null, ':var' => -2, ':status' => 'unreviewed', ':reviewed' => 0, ':note' => null]);
    $deliveryOpenId = (int)$db->lastInsertId();

    // ─── AC1/AC2 — the default queue is OPEN only ──────────────────────
    $h->section('Queue — settled findings and settled kinds are out of the way');

    $baseParams = ['branch_id' => $branch, 'date_from' => $date, 'date_to' => $date, 'view' => 'list'];
    $default = $runHarness('variances-page', ['body' => $baseParams, 'get' => $baseParams])['raw'];
    $correctedOnly = $runHarness('variances-page', ['body' => $baseParams + ['status' => 'corrected'], 'get' => $baseParams + ['status' => 'corrected']])['raw'];
    $allPage = $runHarness('variances-page', ['body' => $baseParams + ['status' => 'all'], 'get' => $baseParams + ['status' => 'all']])['raw'];

    $h->test('the default page lists an unreviewed flag', str_contains($default, 'Flag #' . $openId));
    $h->test('the default page lists an investigated flag', str_contains($default, 'Flag #' . $investigatedId));
    $h->test('the default page does NOT list a settled flag', !str_contains($default, 'Flag #' . $settledId));
    $h->test('the default page does NOT list a settled delivery flag', !str_contains($default, 'Flag #' . $deliverySettledId));
    $h->test('the default page still lists an open delivery flag', str_contains($default, 'Flag #' . $deliveryOpenId));
    $h->test('status=corrected reaches the settled rows', str_contains($correctedOnly, 'Flag #' . $settledId));
    $h->test('status=all reaches every row', str_contains($allPage, 'Flag #' . $settledId) && str_contains($allPage, 'Flag #' . $openId));

    $h->test('the kind picker offers a kind that has an open row', str_contains($default, 'Negative raw sales'));
    $h->test('the kind picker hides a kind whose rows are all settled', !str_contains($default, 'Ending &gt; supply'));
    $h->test('the kind picker still offers the settled kind when it has open rows of its own', str_contains($default, 'Delivery sent vs received'));
    $h->test('the default pills mark Open active, not All', str_contains($default, 'variance-filter-pill--active') && str_contains($default, 'Open <span class="variance-filter-pill-count">'));

    // ─── AC3 — notes save without touching status ──────────────────────
    $h->section('Notes — saved as words, never as a status change');

    $save = $runHarness('variance', ['body' => ['variance_id' => $openId, 'review_note' => 'first note']]);
    $row = $db->query("SELECT resolution_status, review_note FROM dl_variance_flags WHERE id = {$openId}")->fetch(PDO::FETCH_ASSOC) ?: [];
    $h->test('a note-only save succeeds', !empty($save['json']['ok']), $save['raw']);
    $h->test('the note is stored', ($row['review_note'] ?? null) === 'first note', (string)($row['review_note'] ?? 'null'));
    $h->test('the status is untouched by a note save', ($row['resolution_status'] ?? '') === 'unreviewed');

    $clear = $runHarness('variance', ['body' => ['variance_id' => $openId, 'review_note' => '']]);
    $row = $db->query("SELECT resolution_status, review_note FROM dl_variance_flags WHERE id = {$openId}")->fetch(PDO::FETCH_ASSOC) ?: [];
    $h->test('an empty note clears it', !empty($clear['json']['ok']) && array_key_exists('review_note', $row) && $row['review_note'] === null);
    $h->test('clearing the note also leaves the status alone', ($row['resolution_status'] ?? '') === 'unreviewed');

    $settledNote = $runHarness('variance', ['body' => ['variance_id' => $deliverySettledId, 'status' => 'corrected', 'review_note' => 'edited after decision']]);
    $row = $db->query("SELECT resolution_status, review_note FROM dl_variance_flags WHERE id = {$deliverySettledId}")->fetch(PDO::FETCH_ASSOC) ?: [];
    $h->test('a note can still be saved on an already-corrected delivery row', !empty($settledNote['json']['ok']), $settledNote['raw']);
    $h->test('the settled delivery row keeps its corrected status', ($row['resolution_status'] ?? '') === 'corrected');
    $h->test('the settled delivery note is stored', ($row['review_note'] ?? '') === 'edited after decision');

    $refuse = $runHarness('variance', ['body' => ['variance_id' => $deliveryOpenId, 'status' => 'corrected']]);
    $row = $db->query("SELECT resolution_status FROM dl_variance_flags WHERE id = {$deliveryOpenId}")->fetch(PDO::FETCH_ASSOC) ?: [];
    $h->test('the generic endpoint still refuses to SETTLE an open delivery variance', empty($refuse['json']['ok']));
    $h->test('the refused settle left the delivery variance open', ($row['resolution_status'] ?? '') === 'unreviewed');

    // Source pins: the default and the visible-kind counts are in one place.
    $handlersSrc = (string)file_get_contents($base . '/modules/daily-ledger/handlers.php');
    $h->test('the handler defaults an absent status to open', str_contains($handlersSrc, "if (\$statusFilter === '') { \$statusFilter = 'open'; }"));
    $h->test('open is a real SQL filter over unreviewed + investigated', str_contains($handlersSrc, "AND vf.resolution_status IN ('unreviewed','investigated')"));
    $h->test('the kind picker is driven by the status-filtered counts', str_contains($handlersSrc, '$visibleKindCounts'));
    $h->test('a variance update is authorized by the BRANCH, not only the role',
        str_contains($handlersSrc, '$authorizedBranches = array_map(\'intval\', dl_accessibleBranchIds($user));'));
} finally {
    $cleanup();
    @unlink($payloadFile);
}

$left = (int)$db->query("SELECT COUNT(*) FROM dl_variance_flags WHERE branch_id = {$branch}")->fetchColumn();
$h->test('cleanup removed the variance fixtures', $left === 0);

$h->done();
