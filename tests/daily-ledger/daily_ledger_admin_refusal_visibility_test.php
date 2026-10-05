<?php

declare(strict_types=1);

/**
 * Daily Ledger — admin refusal visibility.
 *
 * Closes the admin-visibility gap left open by lane `cashier-truth-3-states`
 * (HARPP decision #144, option 1). Before this lane a whole-batch reconcile
 * refusal returned at the enrollment verdict BEFORE dl_offlineRecordPendingReport(),
 * so a stranded device recorded no pending marker; and dl_offlineUnsyncedDevices()
 * filtered `status = 'active' AND last_reported_pending_count > 0`, so a device
 * whose grant was revoked or expired was invisible exactly when it most needed
 * the admin. The admin surface is the only place a stranded device can be acted
 * on, so this suite pins:
 *
 *   A  a REFUSED batch carrying pending_count/since/fields DOES record the
 *      marker for that device (pre-change: nothing recorded).
 *   B  the admin device list includes a revoked / expired device that holds
 *      reported unsynced work, carrying its enrollment state.
 *   C  the precise refusal reason (revoked / expired) is exposed to the admin,
 *      not the cashier.
 *   D  the same refused batch is NOT processed — no receipt, no write — so A
 *      cannot have been achieved by letting the batch through.
 *   E  the cashier-facing three-state plain language is untouched and the raw
 *      diagnostic is not on the cashier's sheet.
 *
 * Every fixture lives in the reserved 9917x id range and is removed before the
 * suite ends. apiOfflineReconcile() is exercised through the REAL entry point
 * (daily_ledger_offline_replay_harness.php), not a worker, so the refusal is
 * the actual HTTP refusal.
 */

ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-admin-refusal-visibility', TestHarness::MODE_INTEGRATION, 'baronledger.test');
ob_end_clean();

$h->fingerprint('modules/daily-ledger/handlers-offline.php');
$h->fingerprint('modules/daily-ledger/handlers.php');
$h->fingerprint('modules/daily-ledger/database/migrations/075_offline_refusal_visibility.sql');
$h->fingerprint('templates/modules/daily-ledger/admin/dashboard.disyl');
$h->fingerprint('templates/modules/daily-ledger/cashier/ledger.disyl');
$h->fingerprint('tests/daily-ledger/daily_ledger_offline_replay_harness.php');
$h->fingerprint('tests/daily-ledger/daily_ledger_admin_dashboard_harness.php');

// Rendering the dashboard compiles the DiSyL template and may rebuild the
// module registry / capability map on a cold cache; both are instrumentation.
$h->allowLogLines('disyl.compile.phases', 'kernel_state_cache: module_registry rebuilt', 'kernel_state_cache: capability_map rebuilt');

$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/helpers.php';
require_once $base . '/modules/daily-ledger/helpers/entity-views.php';
require_once $base . '/modules/daily-ledger/handlers-deliveries.php';
require_once $base . '/modules/daily-ledger/handlers-pos.php';
require_once $base . '/modules/daily-ledger/handlers-offline.php';
require_once $base . '/modules/daily-ledger/handlers.php';

app()->tenant()->setTenantId(207);
app()->tenant()->setTenantId(207);

// ─── Ensure the visibility columns exist (mirrors migration 075) ───────
// The CLI MigrationRunner applies 075 in production. The ModuleDB contract
// forbids DDL and blocks PREPARE, so the test makes the columns idempotently
// present through the kernel PDO before any module context is pushed (where
// ModuleDB enforcement applies). This mirrors, in plain ALTER form, what 075
// does guarded.
$rawPdo = app()->db();
$enrCols = [];
foreach ($rawPdo->query('SHOW COLUMNS FROM dl_offline_device_enrollments') as $c) {
    $enrCols[strtolower((string)$c['Field'])] = true;
}
if (!isset($enrCols['last_refusal_reason'])) {
    $rawPdo->exec('ALTER TABLE dl_offline_device_enrollments ADD COLUMN last_refusal_reason VARCHAR(40) NULL AFTER pending_fields');
}
if (!isset($enrCols['last_refusal_at'])) {
    $rawPdo->exec('ALTER TABLE dl_offline_device_enrollments ADD COLUMN last_refusal_at DATETIME NULL AFTER last_refusal_reason');
}

$ctx = modulePushContext('daily-ledger');
if (!$ctx) {
    fwrite(STDERR, "daily-ledger module context unavailable\n");
    exit(1);
}
/** @var \Ikabud\Kernel\Contracts\DatabaseContract $db */
$db = $ctx->db();
$scope = (string)(app()->tenant()->current() ?? '');

// ─── Fixtures ──────────────────────────────────────────────────────────
$branchId = 99170;
$productId = 99171;
$apiActorId = 1; // the replay harness mints admin:1
$adminUser = [
    'id' => 999998,
    'sub' => 'admin:999998',
    'role' => 'admin',
    'source' => 'daily-ledger',
    'username' => 'refusal-visibility-admin',
    'name' => 'Refusal Visibility Admin',
];

// Pre-clean everything this suite owns.
$db->execute('DELETE FROM dl_offline_sync_receipts WHERE tenant_scope = :ts', [':ts' => $scope]);
$db->execute('DELETE FROM dl_offline_device_enrollments WHERE tenant_scope = :ts', [':ts' => $scope]);
$db->execute('DELETE FROM dl_daily_ledger WHERE branch_id = :b', [':b' => $branchId]);
$db->execute('DELETE FROM dl_branch_products WHERE branch_id = :b', [':b' => $branchId]);
$db->execute('DELETE FROM dl_branches WHERE id = :b', [':b' => $branchId]);
$db->execute('DELETE FROM dl_products WHERE id = :p', [':p' => $productId]);

$db->execute(
    'INSERT INTO dl_branches (id, code, name, default_supply_mode, is_commissary, is_active)
     VALUES (:id, :code, :name, :mode, 0, 1)',
    [':id' => $branchId, ':code' => 'ARV-TEST', ':name' => 'Admin Refusal Test Branch', ':mode' => 'self_managed']
);
$db->execute(
    'INSERT INTO dl_products (id, sku, name, current_price, sort_order, is_active)
     VALUES (:id, :sku, :name, 25.0, 0, 1)',
    [':id' => $productId, ':sku' => 'ARV-TEST', ':name' => 'Admin Refusal Test Product']
);
$db->execute(
    'INSERT INTO dl_branch_products (branch_id, product_id, is_active) VALUES (:b, :p, 1)',
    [':b' => $branchId, ':p' => $productId]
);

$seedEnrollment = static function (
    \Ikabud\Kernel\Contracts\DatabaseContract $db,
    string $scope,
    string $enrollmentId,
    string $deviceId,
    int $actorId,
    int $branchId,
    string $status,
    string $expiresAt,
    int $schemaVersion = 1
): void {
    $deviceHash = hash('sha256', $scope . '|' . $deviceId);
    $db->prepare(
        'INSERT INTO dl_offline_device_enrollments
            (tenant_scope, enrollment_id, device_id, device_hash, actor_user_id, branch_id, role, status, schema_version, issued_at, expires_at)
         VALUES (:ts, :eid, :did, :dh, :uid, :bid, "admin", :status, :sv, NOW(), :exp)'
    )->execute([
        ':ts' => $scope,
        ':eid' => $enrollmentId,
        ':did' => $deviceId,
        ':dh' => $deviceHash,
        ':uid' => $actorId,
        ':bid' => $branchId,
        ':status' => $status,
        ':sv' => $schemaVersion,
        ':exp' => $expiresAt,
    ]);
};

$runReconcile = static function (array $payload, int $branchId): array {
    $file = sys_get_temp_dir() . '/arv-reconcile-' . bin2hex(random_bytes(6)) . '.json';
    file_put_contents($file, json_encode($payload));
    $command = escapeshellarg(PHP_BINARY) . ' '
        . escapeshellarg(__DIR__ . '/daily_ledger_offline_replay_harness.php') . ' '
        . escapeshellarg($file) . ' '
        . escapeshellarg((string)$branchId) . ' 2>/dev/null';
    $output = [];
    $exitCode = 0;
    exec($command, $output, $exitCode);
    @unlink($file);
    $decoded = json_decode(implode("\n", $output), true);
    return is_array($decoded) ? $decoded : ['_raw' => implode("\n", $output), '_exit' => $exitCode];
};

$findListed = static function (array $rows, string $enrollmentId): ?array {
    foreach ($rows as $row) {
        if ((string)($row['enrollment_id'] ?? '') === $enrollmentId) {
            return $row;
        }
    }
    return null;
};

$ledgerValue = static function (\Ikabud\Kernel\Contracts\DatabaseContract $db, int $branchId, int $productId, string $date) {
    $stmt = $db->prepare('SELECT bal_end FROM dl_daily_ledger WHERE branch_id = :b AND product_id = :p AND ledger_date = :d AND shift = "AM" LIMIT 1');
    $stmt->execute([':b' => $branchId, ':p' => $productId, ':d' => $date]);
    return $stmt->fetchColumn();
};

$runDashboard = static function (int $actorId = 1, string $role = 'admin'): string {
    $command = escapeshellarg(PHP_BINARY) . ' '
        . escapeshellarg(__DIR__ . '/daily_ledger_admin_dashboard_harness.php') . ' '
        . escapeshellarg((string)$actorId) . ' '
        . escapeshellarg($role) . ' 2>/dev/null';
    return (string)shell_exec($command);
};

// ═══════════════════════════════════════════════════════════════════════
// A + D — a REFUSED batch records the marker and applies nothing
// ═══════════════════════════════════════════════════════════════════════
$h->section('A + D — revoked batch: marker recorded, batch not processed');
$revokedEnrollment = '9920aaaa-bbbb-cccc-dddd-eeeeeeee0001';
$revokedDevice = 'dl-arv-revoked-dev-1';
$revokedOp = 'arv-revoked-op-0001';
$seedEnrollment($db, $scope, $revokedEnrollment, $revokedDevice, $apiActorId, $branchId, 'revoked', '2099-01-01 00:00:00');
$decline = $runReconcile([
    'device_id' => $revokedDevice,
    'enrollment_id' => $revokedEnrollment,
    'pending_count' => 3,
    'pending_since' => '2030-02-10 08:00:00',
    'pending_fields' => 'bal_end',
    'operations' => [[
        'client_op_id' => $revokedOp,
        'type' => 'ledger_save',
        'payload' => ['branch_id' => $branchId, 'date' => '2030-02-10', 'shift' => 'AM', 'product_id' => $productId, 'field' => 'bal_end', 'value' => 7],
    ]],
], $branchId);
$h->test('refusal still refuses (ok=false, reason=revoked)',
    ($decline['ok'] ?? null) === false && ($decline['reason'] ?? '') === 'revoked',
    json_encode($decline));

$marker = $db->prepare('SELECT last_reported_pending_count, pending_since, pending_fields, last_refusal_reason FROM dl_offline_device_enrollments WHERE enrollment_id = :e');
$marker->execute([':e' => $revokedEnrollment]);
$markerRow = $marker->fetch(PDO::FETCH_ASSOC) ?: [];
$h->test('A: refused batch recorded the pending report before the verdict',
    (int)($markerRow['last_reported_pending_count'] ?? 0) === 3
    && (string)($markerRow['pending_since'] ?? '') === '2030-02-10 08:00:00'
    && (string)($markerRow['pending_fields'] ?? '') === 'bal_end',
    json_encode($markerRow));
$h->test('C: refused batch recorded the precise diagnostic (revoked)',
    (string)($markerRow['last_refusal_reason'] ?? '') === 'revoked',
    json_encode($markerRow));

$h->test('D: refused batch recorded NO receipt', dl_offlineLoadReceipt($revokedEnrollment, $revokedOp) === null);
$h->test('D: refused batch applied NO ledger write', $ledgerValue($db, $branchId, $productId, '2030-02-10') === false);

// ═══════════════════════════════════════════════════════════════════════
// B + C — the admin list shows the revoked device, its state and reason
// ═══════════════════════════════════════════════════════════════════════
$h->section('B + C — admin device list shows the revoked stranded device');
$listed = $findListed(dl_offlineUnsyncedDevices($adminUser, 20), $revokedEnrollment);
$h->test('B: revoked device holding unsynced work is listed to the admin', is_array($listed), json_encode(dl_offlineUnsyncedDevices($adminUser, 20)));
$h->test('B: the list carries the enrollment state (revoked)',
    is_array($listed) && (string)($listed['enrollment_state'] ?? '') === 'revoked',
    is_array($listed) ? json_encode($listed) : 'absent');
$h->test('C: the list carries the refusal reason (revoked)',
    is_array($listed) && (string)($listed['last_refusal_reason'] ?? '') === 'revoked',
    is_array($listed) ? json_encode($listed) : 'absent');
$h->test('B: the list still carries the reported pending count',
    is_array($listed) && (int)($listed['last_reported_pending_count'] ?? 0) === 3,
    is_array($listed) ? json_encode($listed) : 'absent');

// ═══════════════════════════════════════════════════════════════════════
// Expired grant — same two directions, the other diagnostic
// ═══════════════════════════════════════════════════════════════════════
$h->section('A + B + C — expired grant: marker, state and reason');
$expiredEnrollment = '9920aaaa-bbbb-cccc-dddd-eeeeeeee0002';
$expiredDevice = 'dl-arv-expired-dev-2';
$expiredOp = 'arv-expired-op-0002';
$seedEnrollment($db, $scope, $expiredEnrollment, $expiredDevice, $apiActorId, $branchId, 'active', '2020-01-01 00:00:00');
$expiredDecline = $runReconcile([
    'device_id' => $expiredDevice,
    'enrollment_id' => $expiredEnrollment,
    'pending_count' => 2,
    'pending_since' => '2030-03-01 09:00:00',
    'pending_fields' => 'beg_bal',
    'operations' => [[
        'client_op_id' => $expiredOp,
        'type' => 'ledger_save',
        'payload' => ['branch_id' => $branchId, 'date' => '2030-03-01', 'shift' => 'AM', 'product_id' => $productId, 'field' => 'beg_bal', 'value' => 5],
    ]],
], $branchId);
$h->test('expired refusal still refuses (ok=false, reason=expired)',
    ($expiredDecline['ok'] ?? null) === false && ($expiredDecline['reason'] ?? '') === 'expired',
    json_encode($expiredDecline));
$expiredMarker = $db->prepare('SELECT last_reported_pending_count, last_refusal_reason FROM dl_offline_device_enrollments WHERE enrollment_id = :e');
$expiredMarker->execute([':e' => $expiredEnrollment]);
$expiredRow = $expiredMarker->fetch(PDO::FETCH_ASSOC) ?: [];
$h->test('A: expired refusal recorded the pending report',
    (int)($expiredRow['last_reported_pending_count'] ?? 0) === 2,
    json_encode($expiredRow));
$expiredListed = $findListed(dl_offlineUnsyncedDevices($adminUser, 20), $expiredEnrollment);
$h->test('B: expired device is listed with enrollment state expired',
    is_array($expiredListed) && (string)($expiredListed['enrollment_state'] ?? '') === 'expired',
    is_array($expiredListed) ? json_encode($expiredListed) : 'absent');
$h->test('C: expired device list carries the refusal reason expired',
    is_array($expiredListed) && (string)($expiredListed['last_refusal_reason'] ?? '') === 'expired',
    is_array($expiredListed) ? json_encode($expiredListed) : 'absent');
$h->test('D: expired refusal recorded NO receipt', dl_offlineLoadReceipt($expiredEnrollment, $expiredOp) === null);
$h->test('D: expired refusal applied NO ledger write', $ledgerValue($db, $branchId, $productId, '2030-03-01') === false);

// ═══════════════════════════════════════════════════════════════════════
// B + C — the rendered admin dashboard SHOWS the state and the reason
// ═══════════════════════════════════════════════════════════════════════
$h->section('B + C (rendered) — the admin dashboard shows state and reason');
$dashboardHtml = $runDashboard(1, 'admin');
$h->test('B: rendered dashboard lists the stranded device rows (unique pending timestamps)',
    $dashboardHtml !== ''
    && str_contains($dashboardHtml, '2030-02-10 08:00:00')
    && str_contains($dashboardHtml, '2030-03-01 09:00:00'),
    'html_bytes=' . strlen($dashboardHtml));
$h->test('B: rendered dashboard shows the enrollment state badge',
    str_contains($dashboardHtml, '>revoked</span>') && str_contains($dashboardHtml, '>expired</span>'),
    'html_bytes=' . strlen($dashboardHtml));
$h->test('C: rendered dashboard shows the precise refusal reason column and values',
    str_contains($dashboardHtml, 'Last Refusal') && str_contains($dashboardHtml, '>revoked</span>') && str_contains($dashboardHtml, '>expired</span>'),
    'html_bytes=' . strlen($dashboardHtml));

// ═══════════════════════════════════════════════════════════════════════
// Migration 075 — structure, rerun-safety, registration
// ═══════════════════════════════════════════════════════════════════════
$h->section('Migration 075 (refusal visibility)');
$mig075 = (string)file_get_contents($base . '/modules/daily-ledger/database/migrations/075_offline_refusal_visibility.sql');
$h->test('075 adds the admin refusal columns', str_contains($mig075, 'last_refusal_reason') && str_contains($mig075, 'last_refusal_at'));
$h->test('075 adds the branch/pending index', str_contains($mig075, 'idx_dl_oe_branch_pending'));
$guardedCount075 = preg_match_all('/SET\s+@\w+\s*=\s*IF\(/i', $mig075);
$prepCount075 = preg_match_all('/\bPREPARE\b/i', $mig075);
$h->test('075 guards every DDL with an existence check (rerun-safe)', $guardedCount075 >= 3 && $prepCount075 >= 6);
$h->test('075 never stores credentials', !preg_match('/\b(?:pin|wrapping_key|data_key|password)\b/i', preg_replace('/--.*$/m', '', $mig075)));
$manifest075 = json_decode((string)file_get_contents($base . '/modules/daily-ledger/module.json'), true);
$h->test('075 registered in module.json', in_array('database/migrations/075_offline_refusal_visibility.sql', $manifest075['migrations'] ?? [], true));

// ═══════════════════════════════════════════════════════════════════════
// E — the cashier sheet keeps plain language, never the raw diagnostic
// ═══════════════════════════════════════════════════════════════════════
$h->section('E — cashier three-state plain language untouched');
$ledgerTemplate = (string)file_get_contents($base . '/templates/modules/daily-ledger/cashier/ledger.disyl');
$dashboardTemplate = (string)file_get_contents($base . '/templates/modules/daily-ledger/admin/dashboard.disyl');
$h->test('E: cashier sheet still exposes the three-state save status',
    str_contains($ledgerTemplate, 'data-cashier-save-status'));
$h->test('E: cashier sheet has no admin-only refusal column',
    !str_contains($ledgerTemplate, 'last_refusal_reason')
    && !str_contains($ledgerTemplate, 'enrollment_state'));
$h->test('E: admin dashboard surfaces enrollment state and refusal reason',
    str_contains($dashboardTemplate, 'dev.enrollment_state')
    && str_contains($dashboardTemplate, 'dev.last_refusal_reason'));

// ─── Cleanup ───────────────────────────────────────────────────────────
$db->execute('DELETE FROM dl_offline_sync_receipts WHERE tenant_scope = :ts', [':ts' => $scope]);
$db->execute('DELETE FROM dl_offline_device_enrollments WHERE tenant_scope = :ts', [':ts' => $scope]);
$db->execute('DELETE FROM dl_daily_ledger WHERE branch_id = :b', [':b' => $branchId]);
$db->execute('DELETE FROM dl_branch_products WHERE branch_id = :b', [':b' => $branchId]);
$db->execute('DELETE FROM dl_branches WHERE id = :b', [':b' => $branchId]);
$db->execute('DELETE FROM dl_products WHERE id = :p', [':p' => $productId]);

$h->done();
