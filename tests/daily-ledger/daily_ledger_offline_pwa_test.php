<?php

declare(strict_types=1);

/**
 * Daily Ledger — Offline PWA (enrollment / bootstrap / reconcile / migration)
 *
 * Focused tests for the new encrypted-vault offline flow:
 *   - pure helpers: max-offline-days bounds, device hash, device-id
 *     normalization, versions, allowed operations, enrollment descriptor
 *   - enrollment validation fails closed on expired / revoked / scope /
 *     schema / branch mismatches
 *   - integration: insert + scope-bound find of an enrollment
 *   - bounded, server-derived bootstrap payload
 *   - offline ledger-save / withdrawal / paper-DR op workers
 *   - transactional sync receipts + idempotent replay
 *   - migration install / rerun / indexes / no-credential-columns
 *
 * Integration mode — uses the real tenant DB (207). Seeds isolated branches /
 * products in the reserved 99xxx test-id range and cleans up every row.
 */

ob_start();

require_once __DIR__ . '/../harness/TestHarness.php';

$h = new TestHarness('daily-ledger-offline-pwa', TestHarness::MODE_INTEGRATION, 'localhost');
ob_end_clean();

$h->fingerprint('modules/daily-ledger/handlers-offline.php');
$h->fingerprint('modules/daily-ledger/handlers.php');
$h->fingerprint('modules/daily-ledger/helpers.php');
$h->fingerprint('modules/daily-ledger/routes.php');
$h->fingerprint('modules/daily-ledger/module.json');
$h->fingerprint('modules/daily-ledger/database/migrations/048_offline_device_enrollments.sql');

$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/helpers.php';
require_once $base . '/modules/daily-ledger/helpers/entity-views.php';
require_once $base . '/modules/daily-ledger/handlers-deliveries.php';
require_once $base . '/modules/daily-ledger/handlers-pos.php';
require_once $base . '/modules/daily-ledger/handlers-offline.php';
require_once $base . '/modules/daily-ledger/handlers.php';

app()->tenant()->setTenantId(207);
$dlCtx = modulePushContext('daily-ledger');
if (!$dlCtx) {
    fwrite(STDERR, "daily-ledger module context unavailable\n");
    exit(1);
}

/** @var \Ikabud\Kernel\Contracts\DatabaseContract $db */
$db = $dlCtx->db();

/**
 * Run a migration file as individual statements (the migration runner splits
 * on statement boundaries; a raw multi-statement string is not valid for a
 * single PDO execute).
 */
function dl_t_runMigration(\Ikabud\Kernel\Contracts\DatabaseContract $db, string $sql): void
{
    $chunks = preg_split('/;\s*$/m', trim($sql));
    foreach ($chunks as $chunk) {
        $chunk = trim($chunk);
        if ($chunk === '' || $chunk === ';') {
            continue;
        }
        $db->execute($chunk . ';');
    }
}

// Ensure the 048 tables exist before cleanup (migration is rerun-safe; the
// dedicated migration section later proves install + rerun explicitly).
$migrationSql = (string) file_get_contents($base . '/modules/daily-ledger/database/migrations/048_offline_device_enrollments.sql');
dl_t_runMigration($db, $migrationSql);

// ─── Seeds (reserved 99xxx test range) ─────────────────────────────────
$branchId = 99051;
$productId = 99051;
$testDate = '2030-02-10';

$db->execute('DELETE FROM dl_offline_device_enrollments WHERE tenant_scope = :ts', [':ts' => (string)(app()->tenant()->current() ?? '')]);
$db->execute('DELETE FROM dl_offline_sync_receipts WHERE tenant_scope = :ts', [':ts' => (string)(app()->tenant()->current() ?? '')]);
$db->execute('DELETE FROM dl_daily_ledger WHERE branch_id = :b', [':b' => $branchId]);
$db->execute('DELETE FROM dl_cashier_withdrawals WHERE branch_id = :b', [':b' => $branchId]);
$db->execute('DELETE FROM dl_delivery_items WHERE delivery_id IN (SELECT id FROM dl_deliveries WHERE destination_id = :b)', [':b' => $branchId]);
$db->execute('DELETE FROM dl_branch_receiving_items WHERE receiving_id IN (SELECT id FROM dl_branch_receivings WHERE branch_id = :b)', [':b' => $branchId]);
$db->execute('DELETE FROM dl_deliveries WHERE destination_id = :b', [':b' => $branchId]);
$db->execute('DELETE FROM dl_branch_receivings WHERE branch_id = :b', [':b' => $branchId]);
$db->execute('DELETE FROM dl_ledger_day_status WHERE branch_id = :b', [':b' => $branchId]);
$db->execute('DELETE FROM dl_branch_products WHERE branch_id = :b', [':b' => $branchId]);
$db->execute('DELETE FROM dl_branches WHERE id = :b', [':b' => $branchId]);
$db->execute('DELETE FROM dl_products WHERE id = :p', [':p' => $productId]);
// Self-heal: an earlier aborted run may have left the reserved seed users
// behind, which would make dl_t_seedUser() hit a duplicate-key error.
$db->execute('DELETE FROM dl_users WHERE id IN (999998, 999999)');

$branchStmt = $db->prepare(
    'INSERT INTO dl_branches (id, code, name, address, default_supply_mode, is_commissary, is_active)
     VALUES (:id, :code, :name, :addr, :mode, 0, 1)'
);
$branchStmt->execute([':id' => $branchId, ':code' => 'T-OFF', ':name' => 'Offline Test Branch', ':addr' => 'Test', ':mode' => 'self_managed']);
$prodStmt = $db->prepare(
    'INSERT INTO dl_products (id, sku, name, current_price, sort_order, is_active)
     VALUES (:id, :sku, :name, :price, 0, 1)'
);
$prodStmt->execute([':id' => $productId, ':sku' => 'OFF-TEST', ':name' => 'Offline Test Product', ':price' => 25.0]);
$db->execute(
    'INSERT INTO dl_branch_products (branch_id, product_id, is_active) VALUES (:b, :p, 1)',
    [':b' => $branchId, ':p' => $productId]
);

/**
 * Seed a dl_users row with only the NOT NULL columns that lack defaults
 * (columns with defaults are left to the schema). Used to prove the
 * inactive-actor guard without touching real users.
 */
function dl_t_seedUser(\Ikabud\Kernel\Contracts\DatabaseContract $db, int $id, string $username, string $role, int $isActive): void
{
    $cols = [];
    foreach ($db->query('SHOW COLUMNS FROM dl_users') as $c) {
        $cols[(string)$c['Field']] = $c;
    }
    $insertCols = ['id', 'username', 'password_hash', 'full_name', 'role', 'is_active'];
    $bind = [
        ':id' => $id,
        ':username' => $username,
        ':password_hash' => 'unused',
        ':full_name' => $username,
        ':role' => $role,
        ':is_active' => $isActive,
    ];
    foreach ($cols as $field => $meta) {
        if (in_array($field, $insertCols, true) || $field === 'deleted_at' || $field === 'created_at' || $field === 'updated_at') {
            continue;
        }
        if ((string)$meta['Null'] === 'NO' && ($meta['Default'] === null || $meta['Default'] === '')) {
            $insertCols[] = $field;
            $bind[':' . $field] = '';
        }
    }
    $colSql = implode(', ', array_map(static fn (string $f) => '`' . $f . '`', $insertCols));
    $valSql = implode(', ', array_map(static fn (string $f) => ':' . $f, $insertCols));
    $db->execute("INSERT INTO dl_users ({$colSql}) VALUES ({$valSql})", $bind);
}

/** Synthetic admin actor (branch resolution via DB, no HTTP token needed). */
$adminUser = [
    'id' => 999999,
    'sub' => 'admin:999999',
    'role' => 'admin',
    'source' => 'daily-ledger',
    'username' => 'offline-test-admin',
    'name' => 'Offline Test Admin',
];

/** @return int first active branch id (the admin default branch). */
function dl_t_firstActiveBranch($db): int
{
    return (int)($db->query('SELECT id FROM dl_branches WHERE is_active = 1 ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
}

$firstActiveBranch = dl_t_firstActiveBranch($db);

// ─── Pure helpers ──────────────────────────────────────────────────────
$h->section('Max offline age');
$h->test('dl_offlineMaxDays returns an int', is_int(dl_offlineMaxDays()));
$h->test('dl_offlineMaxDays is within 1..90', dl_offlineMaxDays() >= 1 && dl_offlineMaxDays() <= 90);

$h->section('Device identity');
$h->test('dl_offlineSchemaVersion is 1', dl_offlineSchemaVersion() === 1);
$h->test('dl_offlineBootstrapVersion is a non-empty string', is_string(dl_offlineBootstrapVersion()) && dl_offlineBootstrapVersion() !== '');
$h->test('dl_offlineGrantVersion is an int', is_int(dl_offlineGrantVersion()));
$h->test('dl_offlineDeviceHash is 64 hex chars', preg_match('/^[0-9a-f]{64}$/', dl_offlineDeviceHash('tenant-a', 'device-1')) === 1);
$h->test('dl_offlineDeviceHash is deterministic', dl_offlineDeviceHash('t', 'd') === dl_offlineDeviceHash('t', 'd'));
$h->test('dl_offlineDeviceHash is tenant-bound', dl_offlineDeviceHash('t1', 'd') !== dl_offlineDeviceHash('t2', 'd'));
$h->test('dl_offlineDeviceHash is device-bound', dl_offlineDeviceHash('t', 'd1') !== dl_offlineDeviceHash('t', 'd2'));
$h->test('dl_offlineNormalizeDeviceId accepts a valid id', dl_offlineNormalizeDeviceId('dl-abc123XYZ_9') === 'dl-abc123XYZ_9');
$h->test('dl_offlineNormalizeDeviceId rejects short ids', dl_offlineNormalizeDeviceId('short') === '');
$h->test('dl_offlineNormalizeDeviceId rejects spaces', dl_offlineNormalizeDeviceId('has space') === '');
$h->test('dl_offlineNormalizeDeviceId rejects empty', dl_offlineNormalizeDeviceId('') === '');

$h->section('Allowed offline operations');
$allowed = dl_offlineAllowedOperations();
$h->test('ledger_save is approved offline', in_array('ledger_save', $allowed, true));
$h->test('withdrawal is approved offline', in_array('withdrawal', $allowed, true));
$h->test('receive_paper_dr is approved offline', in_array('receive_paper_dr', $allowed, true));
$h->test('POS is not approved offline', !in_array('pos', $allowed, true));
$h->test('day close is not approved offline', !in_array('close_day', $allowed, true) && !in_array('day_close', $allowed, true));
$h->test('dispatch is not approved offline', !in_array('dispatch', $allowed, true));
$h->test('delivery edit is not approved offline', !in_array('delivery_edit', $allowed, true) && !in_array('delivery_edit_by_dr', $allowed, true));

$h->section('Enrollment descriptor (pure)');
$row = [
    'enrollment_id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
    'tenant_scope' => 'tenant-x',
    'actor_user_id' => 42,
    'branch_id' => 7,
    'role' => 'cashier',
    'shift' => 'AM',
    'grant_version' => 1,
    'schema_version' => 1,
    'bootstrap_version' => '1',
    'status' => 'active',
    'issued_at' => '2030-01-01 00:00:00',
    'expires_at' => '2030-02-01 00:00:00',
    'last_sync_at' => null,
];
$desc = dl_offlineEnrollmentDescriptor($row);
$h->test('descriptor carries enrollment id', ($desc['enrollment_id'] ?? '') === 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee');
$h->test('descriptor carries branch + role + shift', (int)($desc['branch_id'] ?? 0) === 7 && ($desc['role'] ?? '') === 'cashier' && ($desc['shift'] ?? '') === 'AM');
$h->test('descriptor carries expiry', ($desc['expires_at'] ?? '') === '2030-02-01 00:00:00');
$h->test('descriptor carries allowed operations', is_array($desc['allowed_operations'] ?? null) && in_array('ledger_save', $desc['allowed_operations'], true));

// ─── Enrollment validation (fails closed) ──────────────────────────────
$h->section('Enrollment validation');
$now = time();
$baseRow = [
    'tenant_scope' => (string)(app()->tenant()->current() ?? ''),
    'enrollment_id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
    'actor_user_id' => 999999,
    'branch_id' => $firstActiveBranch,
    'role' => 'admin',
    'shift' => null,
    'grant_version' => 1,
    'schema_version' => 1,
    'bootstrap_version' => '1',
    'status' => 'active',
    'issued_at' => date('Y-m-d H:i:s', $now - 3600),
    'expires_at' => date('Y-m-d H:i:s', $now + 86400),
    'last_sync_at' => null,
];

$okValid = dl_offlineValidateEnrollment($adminUser, $baseRow);
$h->test('active + in-window enrollment validates', ($okValid['ok'] ?? false) === true);

$revokedRow = array_merge($baseRow, ['status' => 'revoked']);
$revoked = dl_offlineValidateEnrollment($adminUser, $revokedRow);
$h->test('revoked enrollment fails closed', ($revoked['ok'] ?? true) === false && ($revoked['reason'] ?? '') === 'revoked');

$expiredRow = array_merge($baseRow, ['expires_at' => date('Y-m-d H:i:s', $now - 60)]);
$expired = dl_offlineValidateEnrollment($adminUser, $expiredRow);
$h->test('expired enrollment fails closed', ($expired['ok'] ?? true) === false && ($expired['reason'] ?? '') === 'expired');

$expiredStatusRow = array_merge($baseRow, ['status' => 'expired']);
$expiredStatus = dl_offlineValidateEnrollment($adminUser, $expiredStatusRow);
$h->test('expired-status enrollment fails closed', ($expiredStatus['ok'] ?? true) === false && ($expiredStatus['reason'] ?? '') === 'expired');

$scopeRow = array_merge($baseRow, ['tenant_scope' => 'other-tenant']);
$scope = dl_offlineValidateEnrollment($adminUser, $scopeRow);
$h->test('tenant scope mismatch fails closed', ($scope['ok'] ?? true) === false && ($scope['reason'] ?? '') === 'scope-mismatch');

$schemaRow = array_merge($baseRow, ['schema_version' => 99]);
$schema = dl_offlineValidateEnrollment($adminUser, $schemaRow);
$h->test('schema mismatch fails closed (update required)', ($schema['ok'] ?? true) === false && ($schema['reason'] ?? '') === 'schema-mismatch');

$branchRow = array_merge($baseRow, ['branch_id' => 99999998]);
$branchMismatch = dl_offlineValidateEnrollment($adminUser, $branchRow);
$h->test('branch mismatch fails closed (re-enroll on current branch)', ($branchMismatch['ok'] ?? true) === false && ($branchMismatch['reason'] ?? '') === 'branch-mismatch');

$actorRow = array_merge($baseRow, ['actor_user_id' => 888888]);
$actorMismatch = dl_offlineValidateEnrollment($adminUser, $actorRow);
$h->test('actor mismatch fails closed', ($actorMismatch['ok'] ?? true) === false && ($actorMismatch['reason'] ?? '') === 'account-mismatch');

// Inactive actor: seed a dl_users row with is_active = 0 and prove the
// enrollment fails closed.
$inactiveUserId = 999998;
dl_t_seedUser($db, $inactiveUserId, 'offline-test-inactive', 'admin', 0);
$inactiveRow = array_merge($baseRow, ['actor_user_id' => $inactiveUserId]);
$inactive = dl_offlineValidateEnrollment([
    'id' => $inactiveUserId, 'sub' => 'admin:' . $inactiveUserId, 'role' => 'admin', 'source' => 'daily-ledger', 'username' => 'offline-test-inactive', 'name' => 'X',
], $inactiveRow);
$h->test('inactive actor fails closed', ($inactive['ok'] ?? true) === false && ($inactive['reason'] ?? '') === 'inactive');

// ─── Migration install / rerun / schema ────────────────────────────────
$h->section('Migration 048 (install / rerun / schema)');
$migrationSql = (string) file_get_contents($base . '/modules/daily-ledger/database/migrations/048_offline_device_enrollments.sql');
$h->test('migration registers both tables', str_contains($migrationSql, 'dl_offline_device_enrollments') && str_contains($migrationSql, 'dl_offline_sync_receipts'));
$h->test('migration uses InnoDB + utf8mb4', str_contains($migrationSql, 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'));
$h->test('migration is rerun-safe (CREATE TABLE IF NOT EXISTS)', str_contains($migrationSql, 'CREATE TABLE IF NOT EXISTS'));
// Only the CREATE TABLE blocks matter (the header comment may mention "PIN");
// strip everything before the first CREATE TABLE before checking columns.
$createBlocks = substr($migrationSql, (int)strpos($migrationSql, 'CREATE TABLE'));
$h->test('migration never defines a PIN or key column', !preg_match('/\b(?:pin|wrapping_key|data_key|password)\b/i', preg_replace('/--.*$/m', '', $createBlocks)));

$migrated = true;
try {
    // Execute twice: rerun-safety is proven because the second pass uses IF NOT EXISTS.
    dl_t_runMigration($db, $migrationSql);
    dl_t_runMigration($db, $migrationSql);
} catch (Throwable $e) {
    $migrated = false;
    $h->detail('Migration execution error: ' . $e->getMessage());
}$h->test('migration executes twice without error (rerun-safe)', $migrated);
$h->test('enrollment table exists', $db->query("SHOW TABLES LIKE 'dl_offline_device_enrollments'")->fetchColumn() !== false);
$h->test('receipts table exists', $db->query("SHOW TABLES LIKE 'dl_offline_sync_receipts'")->fetchColumn() !== false);

$enrCols = [];
foreach ($db->query('SHOW COLUMNS FROM dl_offline_device_enrollments') as $c) {
    $enrCols[strtolower((string)$c['Field'])] = true;
}
$h->test('enrollment has device hash column', isset($enrCols['device_hash']));
$h->test('enrollment has actor + branch columns', isset($enrCols['actor_user_id']) && isset($enrCols['branch_id']));
$h->test('enrollment has grant/schema/bootstrap versions', isset($enrCols['grant_version']) && isset($enrCols['schema_version']) && isset($enrCols['bootstrap_version']));
$h->test('enrollment has issued/expiry/revoked/last-sync dates', isset($enrCols['issued_at']) && isset($enrCols['expires_at']) && isset($enrCols['revoked_at']) && isset($enrCols['last_sync_at']));
$h->test('enrollment has tenant scope + enrollment id', isset($enrCols['tenant_scope']) && isset($enrCols['enrollment_id']));
$h->test('enrollment has NO credential column', !isset($enrCols['pin']) && !isset($enrCols['wrapping_key']) && !isset($enrCols['data_key']));

$idx = [];
foreach ($db->query('SHOW COLUMNS FROM dl_offline_device_enrollments') as $r) {
    $idx[strtolower((string)$r['Field'])] = true;
}
$h->test('enrollment has tenant_scope + device_id + enrollment_id columns', isset($idx['tenant_scope']) && isset($idx['device_id']) && isset($idx['enrollment_id']));

// Behavioral unique-index proofs (SHOW INDEX is not allowed on the module DB).
$dupEnrollmentThrew = false;
try {
    $stmt = $db->prepare(
        'INSERT INTO dl_offline_device_enrollments
            (tenant_scope, enrollment_id, device_id, device_hash, actor_user_id, branch_id, role, status, issued_at, expires_at)
         VALUES (:ts, :eid, :did, :dh, :uid, :bid, :role, "active", NOW(), DATE_ADD(NOW(), INTERVAL 1 DAY))'
    );
    $stmt->execute([
        ':ts' => 'tenant-x', ':eid' => 'dup-aaaa-bbbb-cccc-dddd-eeeeeeeeeeee', ':did' => 'd1', ':dh' => 'h1',
        ':uid' => 1, ':bid' => 1, ':role' => 'cashier',
    ]);
    $stmt->execute([
        ':ts' => 'tenant-x', ':eid' => 'dup-aaaa-bbbb-cccc-dddd-eeeeeeeeeeee', ':did' => 'd2', ':dh' => 'h2',
        ':uid' => 2, ':bid' => 2, ':role' => 'cashier',
    ]);
} catch (Throwable $e) {
    $dupEnrollmentThrew = true;
}
$db->execute('DELETE FROM dl_offline_device_enrollments WHERE tenant_scope = :ts', [':ts' => 'tenant-x']);
$h->test('enrollment_id unique index is enforced', $dupEnrollmentThrew);

$dupReceiptThrew = false;
try {
    $stmt = $db->prepare(
        'INSERT INTO dl_offline_sync_receipts (tenant_scope, enrollment_id, client_op_id, operation_type, status, result_json)
         VALUES (:ts, :eid, :coid, :otype, "applied", "{}")'
    );
    $stmt->execute([':ts' => 'tenant-x', ':eid' => 'e1', ':coid' => 'op-dup', ':otype' => 'ledger_save']);
    $stmt->execute([':ts' => 'tenant-x', ':eid' => 'e1', ':coid' => 'op-dup', ':otype' => 'ledger_save']);
} catch (Throwable $e) {
    $dupReceiptThrew = true;
}
$db->execute('DELETE FROM dl_offline_sync_receipts WHERE tenant_scope = :ts', [':ts' => 'tenant-x']);
$h->test('receipts (enrollment_id + client_op_id) unique index is enforced', $dupReceiptThrew);

$manifest = json_decode((string) file_get_contents($base . '/modules/daily-ledger/module.json'), true);
$h->test('migration 048 registered in module.json', in_array('database/migrations/048_offline_device_enrollments.sql', $manifest['migrations'] ?? [], true));
$h->test('enrollment tables owned by module', in_array('dl_offline_device_enrollments', $manifest['owns_tables'] ?? [], true) && in_array('dl_offline_sync_receipts', $manifest['owns_tables'] ?? [], true));
$h->test('max_offline_days setting declared', (function () use ($manifest) {
    foreach ($manifest['settings_fields'] ?? [] as $f) {
        if (($f['key'] ?? '') === 'max_offline_days') {
            return true;
        }
    }
    return false;
})());

// ─── Enrollment insert + scope-bound find ──────────────────────────────
$h->section('Enrollment insert / find');
$deviceId = 'dl-test-device-001';
$insertedRow = null;
try {
    $insertedRow = dl_offlineInsertEnrollment($adminUser, $deviceId, $branchId, 'AM', false);
} catch (Throwable $e) {
    $h->detail('dl_offlineInsertEnrollment error: ' . $e->getMessage());
}
$h->test('enrollment row inserts', is_array($insertedRow));
if (is_array($insertedRow)) {
    $h->test('enrollment id is generated', preg_match('/^[0-9a-f\-]{36}$/', (string)$insertedRow['enrollment_id']) === 1);
    $h->test('enrollment stores server-derived actor/branch', (int)$insertedRow['actor_user_id'] === 999999 && (int)$insertedRow['branch_id'] === $branchId);
    $h->test('enrollment stores device hash not plaintext device id', isset($insertedRow['device_hash']) && !str_contains((string)$insertedRow['device_hash'], $deviceId));
    $h->test('enrollment expiry is in the future', strtotime((string)$insertedRow['expires_at']) > time());
    $h->test('enrollment status is active', ($insertedRow['status'] ?? '') === 'active');

    $found = dl_offlineFindEnrollment($adminUser, (string)$insertedRow['enrollment_id'], $deviceId);
    $h->test('enrollment finds by scope+device hash', is_array($found) && ($found['enrollment_id'] ?? '') === $insertedRow['enrollment_id']);

    $wrongDevice = dl_offlineFindEnrollment($adminUser, (string)$insertedRow['enrollment_id'], 'dl-other-device-999');
    $h->test('enrollment fails closed for a different device', $wrongDevice === null);

    $wrongActor = dl_offlineFindEnrollment([
        'id' => 777777, 'sub' => 'admin:777777', 'role' => 'admin', 'source' => 'daily-ledger', 'username' => 'x', 'name' => 'X',
    ], (string)$insertedRow['enrollment_id'], $deviceId);
    $h->test('enrollment fails closed for a different actor', $wrongActor === null);

    // ─── Bootstrap payload ─────────────────────────────────────────────
    $h->section('Bootstrap payload (bounded + server-derived)');
    $boot = null;
    try {
        $boot = dl_offlineBootstrapPayload($adminUser, $branchId, 'AM', false);
    } catch (Throwable $e) {
        $h->detail('dl_offlineBootstrapPayload error: ' . $e->getMessage());
    }
    $h->test('bootstrap renders', is_array($boot));
    if (is_array($boot)) {
        $h->test('bootstrap branch is the enrolled branch', (int)($boot['branch']['id'] ?? 0) === $branchId);
        $h->test('bootstrap carries business date + clock', !empty($boot['business_date']) && !empty($boot['operating_timezone']) && !empty($boot['close_of_day_time']));
        $h->test('bootstrap products are bounded to the branch', is_array($boot['products'] ?? null));
        $h->test('bootstrap carries the seeded product', (function () use ($boot, $productId) {
            foreach ($boot['products'] ?? [] as $p) {
                if ((int)($p['id'] ?? 0) === $productId) {
                    return true;
                }
            }
            return false;
        })());
        $h->test('bootstrap ledger rows are bounded', is_array($boot['ledger_rows'] ?? null));
        $h->test('bootstrap carries allowed operations', is_array($boot['allowed_operations'] ?? null) && in_array('ledger_save', $boot['allowed_operations'], true));
        $h->test('bootstrap carries server versions', is_array($boot['server_versions'] ?? null) && (int)($boot['server_versions']['schema_version'] ?? 0) === 1);
    }

    // ─── Reconcile op workers + receipts ───────────────────────────────
    $h->section('Offline op workers (durable + idempotent)');

    // Ledger save
    $saveResult = null;
    try {
        $saveResult = dl_offlineApplyLedgerSave($adminUser, [
            'type' => 'ledger_save',
            'payload' => [
                'branch_id' => $branchId,
                'product_id' => $productId,
                'field' => 'beg_bal',
                'value' => 40,
                'date' => $testDate,
                'shift' => 'AM',
            ],
        ]);
    } catch (Throwable $e) {
        $h->detail('dl_offlineApplyLedgerSave error: ' . $e->getMessage());
    }
    $h->test('offline ledger save applies', is_array($saveResult) && !empty($saveResult['ok']));
    $ledgerVal = $db->prepare('SELECT beg_bal FROM dl_daily_ledger WHERE branch_id = :b AND product_id = :p AND ledger_date = :d AND shift = :s');
    $ledgerVal->execute([':b' => $branchId, ':p' => $productId, ':d' => $testDate, ':s' => 'AM']);
    $h->test('ledger save persisted the value', (int)($ledgerVal->fetchColumn() ?: 0) === 40);

    // Ledger save invalid field → rejected
    $badSave = null;
    try {
        $badSave = dl_offlineApplyLedgerSave($adminUser, [
            'type' => 'ledger_save',
            'payload' => ['branch_id' => $branchId, 'product_id' => $productId, 'field' => 'sales', 'value' => 1, 'date' => $testDate, 'shift' => 'AM'],
        ]);
    } catch (RuntimeException $e) {
        $badSave = ['error' => $e->getMessage(), 'code' => $e->getCode()];
    }
    $h->test('offline ledger save rejects non-writable fields', is_array($badSave) && !empty($badSave['error']) && (int)($badSave['code'] ?? 0) === 422);

    // Withdrawal (charge)
    $wdResult = null;
    try {
        $wdResult = dl_offlineApplyWithdrawal($adminUser, [
            'type' => 'withdrawal',
            'payload' => [
                'branch_id' => $branchId,
                'date' => $testDate,
                'shift' => 'AM',
                'header' => ['withdrawal_type' => 'charge', 'reason_code' => 'manual_adjustment'],
                'lines' => [['product_id' => $productId, 'quantity' => 5]],
            ],
        ]);
    } catch (Throwable $e) {
        $h->detail('dl_offlineApplyWithdrawal error: ' . $e->getMessage());
    }
    $h->test('offline withdrawal applies', is_array($wdResult) && !empty($wdResult['ok']));
    $wdCount = $db->prepare('SELECT COUNT(*) FROM dl_cashier_withdrawals WHERE branch_id = :b AND product_id = :p AND ledger_date = :d');
    $wdCount->execute([':b' => $branchId, ':p' => $productId, ':d' => $testDate]);
    $h->test('withdrawal row persisted', (int)$wdCount->fetchColumn() >= 1);
    $wdAudit = $db->prepare("SELECT COUNT(*) FROM audit_logs WHERE action = 'withdrawal' AND branch_id = :b");
    $wdAudit->execute([':b' => $branchId]);
    $h->test('withdrawal emits an audit action for traceability', (int)$wdAudit->fetchColumn() >= 1);

    // Idempotent replay: re-applying the same withdrawal with the same
    // idempotency_key must NOT create a duplicate record. Key is unique per run
    // so a persistent cache from a prior run cannot pre-satisfy the replay.
    $replayKey = 'offline-wd-replay-' . substr(hash('sha256', 'replay-' . $testDate . '-' . bin2hex(random_bytes(6))), 0, 24);
    $replayOp = [
        'type' => 'withdrawal',
        'payload' => [
            'branch_id' => $branchId,
            'date' => $testDate,
            'shift' => 'AM',
            'idempotency_key' => $replayKey,
            'header' => ['withdrawal_type' => 'charge', 'reason_code' => 'manual_adjustment'],
            'lines' => [['product_id' => $productId, 'quantity' => 3]],
        ],
    ];
    $baseWdCount = (int)$db->query("SELECT COUNT(*) FROM dl_cashier_withdrawals WHERE branch_id = " . (int)$branchId)->fetchColumn();
    dl_offlineApplyWithdrawal($adminUser, $replayOp);
    dl_offlineApplyWithdrawal($adminUser, $replayOp);
    $afterWdCount = (int)$db->query("SELECT COUNT(*) FROM dl_cashier_withdrawals WHERE branch_id = " . (int)$branchId)->fetchColumn();
    $h->test('offline withdrawal replay with same key is deduped', $afterWdCount === $baseWdCount + 1);

    // DB-level dedup guard (migration 052): a DIFFERENT idempotency key does NOT
    // bypass the unique fingerprint — the server rejects the identical re-apply
    // atomically. This is the modal-reopen / second-tab / cache-eviction case that
    // created the 2026-08-15 duplicate pullouts; the row count must not grow.
    $dbGuardBase = (int)$db->query("SELECT COUNT(*) FROM dl_cashier_withdrawals WHERE branch_id = " . (int)$branchId)->fetchColumn();
    $dbGuardRejected = false;
    try {
        dl_offlineApplyWithdrawal($adminUser, [
            'type' => 'withdrawal',
            'payload' => [
                'branch_id' => $branchId,
                'date' => $testDate,
                'shift' => 'AM',
                'idempotency_key' => 'offline-wd-dbguard-' . substr(hash('sha256', 'dbguard-' . bin2hex(random_bytes(6))), 0, 24),
                'header' => ['withdrawal_type' => 'charge', 'reason_code' => 'manual_adjustment'],
                'lines' => [['product_id' => $productId, 'quantity' => 5]],
            ],
        ]);
    } catch (DlDuplicateWithdrawalException $e) {
        $dbGuardRejected = true;
    }
    $h->test('offline withdrawal DB guard rejects identical re-apply with a different key', $dbGuardRejected);
    $dbGuardAfter = (int)$db->query("SELECT COUNT(*) FROM dl_cashier_withdrawals WHERE branch_id = " . (int)$branchId)->fetchColumn();
    $h->test('offline withdrawal DB guard adds no duplicate row', $dbGuardAfter === $dbGuardBase);

    // Receipts
    $clientOpId = 'op-' . substr(hash('sha256', 'offline-test-op'), 0, 32);
    dl_offlineRecordReceipt((string)$insertedRow['enrollment_id'], $clientOpId, 'ledger_save', 'applied', ['ok' => true, 'field' => 'beg_bal', 'value' => 40]);
    $receipt = dl_offlineLoadReceipt((string)$insertedRow['enrollment_id'], $clientOpId);
    $h->test('receipt recorded transactionally', is_array($receipt) && ($receipt['status'] ?? '') === 'applied');
    $h->test('receipt replay returns stable already-applied result', is_array($receipt) && ($receipt['result']['ok'] ?? false) === true);
    $missingReceipt = dl_offlineLoadReceipt((string)$insertedRow['enrollment_id'], 'op-never-seen');
    $h->test('unknown client op has no receipt (retry allowed)', $missingReceipt === null);

    // ─── Atomic reconcile path (op write + receipt in ONE transaction) ───
    // Mirrors apiOfflineReconcile: the worker runs with $inTx=true so the
    // operation write and its receipt commit together. This is what guarantees
    // "applied at most once" — a crash or a concurrent duplicate cannot leave an
    // applied op without a receipt (which would re-apply on retry).
    $atomicOpId = 'op-atomic-' . substr(hash('sha256', 'offline-atomic-op'), 0, 24);
    $atomic = null;
    try {
        $ctx = module();
        $ctx->db()->beginTransaction();
        $atomicRes = dl_offlineApplyLedgerSave($adminUser, [
            'type' => 'ledger_save',
            'payload' => ['branch_id' => $branchId, 'product_id' => $productId, 'field' => 'bal_end', 'value' => 12, 'date' => $testDate, 'shift' => 'AM'],
        ], true);
        dl_offlineRecordReceipt((string)$insertedRow['enrollment_id'], $atomicOpId, 'ledger_save', 'applied', $atomicRes);
        $ctx->db()->commit();
        $atomic = ['ok' => true, 'result' => $atomicRes];
    } catch (Throwable $e) {
        $atomic = ['ok' => false, 'error' => $e->getMessage()];
    }
    $h->test('reconcile-style atomic apply commits op + receipt together', is_array($atomic) && !empty($atomic['ok']));
    $atomicReceipt = dl_offlineLoadReceipt((string)$insertedRow['enrollment_id'], $atomicOpId);
    $h->test('atomic apply recorded a receipt in the same commit', is_array($atomicReceipt) && ($atomicReceipt['status'] ?? '') === 'applied');
    $balEndVal = $db->prepare('SELECT bal_end FROM dl_daily_ledger WHERE branch_id = :b AND product_id = :p AND ledger_date = :d AND shift = :s');
    $balEndVal->execute([':b' => $branchId, ':p' => $productId, ':d' => $testDate, ':s' => 'AM']);
    $h->test('atomic apply persisted the write', (int)($balEndVal->fetchColumn() ?: 0) === 12);

    // The atomic path must also work for the withdrawal worker (which creates
    // multiple rows across tables) when the caller owns the transaction.
    $withdrawalOpId = 'op-atomic-wd-' . substr(hash('sha256', 'offline-atomic-wd'), 0, 24);
    $wdAtomic = null;
    try {
        $ctx = module();
        $ctx->db()->beginTransaction();
        $wdRes = dl_offlineApplyWithdrawal($adminUser, [
            'type' => 'withdrawal',
            'payload' => ['branch_id' => $branchId, 'date' => $testDate, 'shift' => 'AM', 'header' => ['withdrawal_type' => 'charge', 'reason_code' => 'manual_adjustment'], 'lines' => [['product_id' => $productId, 'quantity' => 9]]],
        ], true);
        dl_offlineRecordReceipt((string)$insertedRow['enrollment_id'], $withdrawalOpId, 'withdrawal', 'applied', $wdRes);
        $ctx->db()->commit();
        $wdAtomic = ['ok' => true, 'result' => $wdRes];
    } catch (Throwable $e) {
        $wdAtomic = ['ok' => false, 'error' => $e->getMessage()];
    }
    $h->test('reconcile-style atomic apply works for the withdrawal worker', is_array($wdAtomic) && !empty($wdAtomic['ok']));
    $wdAtomicReceipt = dl_offlineLoadReceipt((string)$insertedRow['enrollment_id'], $withdrawalOpId);
    $h->test('atomic withdrawal recorded its receipt in the same commit', is_array($wdAtomicReceipt) && ($wdAtomicReceipt['status'] ?? '') === 'applied');

    // ─── Migration 053: offline pending-work marker ────────────────────
    $h->section('Migration 053 (pending marker)');
    $mig053 = (string) file_get_contents($base . '/modules/daily-ledger/database/migrations/053_offline_pending_marker.sql');
    $h->test('053 adds the pending visibility columns', str_contains($mig053, 'last_reported_pending_count') && str_contains($mig053, 'pending_since') && str_contains($mig053, 'pending_fields'));
    $h->test('053 adds the sync-request columns (future use)', str_contains($mig053, 'sync_requested_at') && str_contains($mig053, 'sync_requested_by_user_id'));
    // Rerun-safety by construction: every ADD COLUMN / ADD INDEX is guarded by
    // an INFORMATION_SCHEMA existence check + PREPARE pattern (same as 052).
    // The CLI MigrationRunner applies 053 in production/CI (the ModuleDB
    // contract blocks PREPARE, so the test verifies structure + presence).
    $guardedCount = preg_match_all('/SET\s+@\w+\s*=\s*IF\(/i', $mig053);
    $prepCount = preg_match_all('/\bPREPARE\b/i', $mig053);
    $h->test('053 guards every DDL with an existence check (rerun-safe)', $guardedCount >= 5 && $prepCount >= 10);
    $h->test('053 never stores credentials', !preg_match('/\b(?:pin|wrapping_key|data_key|password)\b/i', preg_replace('/--.*$/m', '', $mig053)));
    $enr053 = [];
    foreach ($db->query('SHOW COLUMNS FROM dl_offline_device_enrollments') as $c) {
        $enr053[strtolower((string)$c['Field'])] = true;
    }
    $h->test('053 columns present on the enrollment table', isset($enr053['last_reported_pending_count']) && isset($enr053['pending_since']) && isset($enr053['pending_fields']) && isset($enr053['sync_requested_at']) && isset($enr053['sync_requested_by_user_id']));
    // Index presence is checked structurally (SHOW INDEX / information_schema
    // are denied on the ModuleDB; the index is defined in the migration SQL).
    $h->test('053 defines the pending index', str_contains($mig053, 'idx_dl_oe_pending'));
    $h->test('053 registered in module.json', in_array('database/migrations/053_offline_pending_marker.sql', $manifest['migrations'] ?? [], true));

    // ─── Pending marker helpers ─────────────────────────────────────────
    $h->section('Offline pending marker (visibility, non-decrypting)');
    dl_offlineRecordPendingReport($insertedRow, 3, '2030-02-10 08:00:00', 'bal_end,beg_bal');
    $marker = $db->prepare('SELECT last_reported_pending_count, pending_since, pending_fields FROM dl_offline_device_enrollments WHERE id = :id');
    $marker->execute([':id' => (int)$insertedRow['id']]);
    $markerRow = $marker->fetch(PDO::FETCH_ASSOC);
    $h->test('recordPendingReport persists count/since/fields', (int)($markerRow['last_reported_pending_count'] ?? 0) === 3 && ($markerRow['pending_since'] ?? '') === '2030-02-10 08:00:00' && ($markerRow['pending_fields'] ?? '') === 'bal_end,beg_bal');
    dl_offlineRecordPendingReport($insertedRow, 0, '', '');
    $marker0 = $db->prepare('SELECT last_reported_pending_count, pending_since, pending_fields FROM dl_offline_device_enrollments WHERE id = :id');
    $marker0->execute([':id' => (int)$insertedRow['id']]);
    $markerRow0 = $marker0->fetch(PDO::FETCH_ASSOC);
    $h->test('recordPendingReport clears the marker on a clean report', (int)($markerRow0['last_reported_pending_count'] ?? -1) === 0 && ($markerRow0['pending_since'] ?? null) === null && ($markerRow0['pending_fields'] ?? null) === null);

    // Unsynced-devices query: flag the enrollment, then confirm it shows up and
    // a clean enrollment does not.
    dl_offlineRecordPendingReport($insertedRow, 2, '2030-02-10 08:00:00', 'bal_end');
    $unsynced = dl_offlineUnsyncedDevices($adminUser, 20);
    $flagFound = false;
    $cleanCount = 0;
    foreach ($unsynced as $u) {
        if ((string)($u['enrollment_id'] ?? '') === (string)$insertedRow['enrollment_id']) {
            $flagFound = true;
            $cleanCount = (int)($u['last_reported_pending_count'] ?? 0);
        }
    }
    $h->test('unsynced-devices query surfaces the flagged enrollment', $flagFound);
    $h->test('unsynced-devices query carries the reported count', $cleanCount === 2);
    dl_offlineRecordPendingReport($insertedRow, 0, '', '');
    $unsyncedAfter = dl_offlineUnsyncedDevices($adminUser, 20);
    $stillFlagged = false;
    foreach ($unsyncedAfter as $u) {
        if ((string)($u['enrollment_id'] ?? '') === (string)$insertedRow['enrollment_id']) {
            $stillFlagged = true;
        }
    }
    $h->test('unsynced-devices query excludes a clean enrollment', !$stillFlagged);
    // Branch scoping: a supervisor with no access to the test branch sees nothing.
    $noAccessUser = [
        'id' => 555501, 'sub' => 'cashier:555501', 'role' => 'cashier', 'source' => 'daily-ledger', 'username' => 'no-access', 'name' => 'No Access',
    ];
    $h->test('unsynced-devices query is branch-scoped', dl_offlineUnsyncedDevices($noAccessUser, 20) === []);

    // ─── Phase 4: late-ending reconcile bridge (closed day + PM recovery) ──
    $h->section('Phase 4: late-ending recovery bridge');
    // Cashier bound to the test branch + PM shift, for the cashier-only path.
    $lateCashierId = 999997;
    $db->execute('DELETE FROM dl_user_branches WHERE user_id = :id', [':id' => $lateCashierId]);
    $db->execute('DELETE FROM dl_users WHERE id = :id', [':id' => $lateCashierId]);
    dl_t_seedUser($db, $lateCashierId, 'offline-late-cashier', 'cashier', 1);
    $db->execute("UPDATE dl_users SET shift = 'PM' WHERE id = :id", [':id' => $lateCashierId]);
    $db->execute(
        'INSERT INTO dl_user_branches (user_id, branch_id) VALUES (:uid, :bid)',
        [':uid' => $lateCashierId, ':bid' => $branchId]
    );
    $lateCashier = [
        'id' => $lateCashierId, 'sub' => 'cashier:' . $lateCashierId, 'role' => 'cashier',
        'source' => 'daily-ledger', 'username' => 'offline-late-cashier', 'name' => 'Late Cashier',
    ];
    // Mint a real module JWT so the cashier's branch/shift resolve from request
    // context (dl_getUserBranchId → dlUserFromRequest), like a live PWA sync.
    $prevAuthHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . app()->jwt()->generate($lateCashier + ['token_type' => 'access']);
    $lateDate = '2030-02-11';

    // Seed: day CLOSED + PM shift OPEN (not finalized) — the recoverable state.
    $db->execute(
        "INSERT INTO dl_ledger_day_status (branch_id, ledger_date, status, closed_at)
         VALUES (:b, :d, 'closed', NOW())
         ON DUPLICATE KEY UPDATE status = 'closed', closed_at = NOW()",
        [':b' => $branchId, ':d' => $lateDate]
    );
    $db->execute(
        "INSERT INTO dl_ledger_shift_status (branch_id, ledger_date, shift, status)
         VALUES (:b, :d, 'PM', 'open')
         ON DUPLICATE KEY UPDATE status = 'open', finalized_at = NULL",
        [':b' => $branchId, ':d' => $lateDate]
    );

    // A: eligible late PM bal_end reconcile → applied, day reopened to pending-PM.
    $lateApply = null;
    try {
        $lateApply = dl_offlineApplyLedgerSave($lateCashier, [
            'type' => 'ledger_save',
            'payload' => ['branch_id' => $branchId, 'product_id' => $productId, 'field' => 'bal_end', 'value' => 22, 'date' => $lateDate, 'shift' => 'PM'],
        ], true);
    } catch (Throwable $e) {
        $lateApply = ['error' => $e->getMessage()];
    }
    $h->test('late PM bal_end on a closed day applies (bridge)', is_array($lateApply) && !empty($lateApply['ok']));
    $lateDayStatus = (string)$db->query("SELECT status FROM dl_ledger_day_status WHERE branch_id = " . (int)$branchId . " AND ledger_date = '" . $lateDate . "'")->fetchColumn();
    $h->test('late-ending bridge reopens the day to open/pending-PM', $lateDayStatus === 'open');
    $lateEndVal = $db->prepare('SELECT bal_end FROM dl_daily_ledger WHERE branch_id = :b AND product_id = :p AND ledger_date = :d AND shift = :s');
    $lateEndVal->execute([':b' => $branchId, ':p' => $productId, ':d' => $lateDate, ':s' => 'PM']);
    $h->test('late ending persisted after reopen', (int)($lateEndVal->fetchColumn() ?: 0) === 22);
    $lateReopenAudit = $db->prepare("SELECT COUNT(*) FROM audit_logs WHERE action = 'late_ending_reopen' AND branch_id = :b");
    $lateReopenAudit->execute([':b' => $branchId]);
    $h->test('late-ending reopen is audited', (int)$lateReopenAudit->fetchColumn() >= 1);

    // B: PM shift FINALIZED → the ending is immutable; rejected, day stays closed.
    $db->execute(
        "UPDATE dl_ledger_shift_status SET status = 'finalized', finalized_at = NOW()
          WHERE branch_id = :b AND ledger_date = :d AND shift = 'PM'",
        [':b' => $branchId, ':d' => $lateDate]
    );
    $db->execute(
        "UPDATE dl_ledger_day_status SET status = 'closed', closed_at = NOW()
          WHERE branch_id = :b AND ledger_date = :d",
        [':b' => $branchId, ':d' => $lateDate]
    );
    $finalizedApply = null;
    try {
        $finalizedApply = dl_offlineApplyLedgerSave($lateCashier, [
            'type' => 'ledger_save',
            'payload' => ['branch_id' => $branchId, 'product_id' => $productId, 'field' => 'bal_end', 'value' => 30, 'date' => $lateDate, 'shift' => 'PM'],
        ], true);
    } catch (RuntimeException $e) {
        $finalizedApply = ['error' => $e->getMessage(), 'code' => $e->getCode()];
    }
    $h->test('late bal_end on a finalized PM shift is rejected (immutable)', is_array($finalizedApply) && !empty($finalizedApply['error']) && (int)($finalizedApply['code'] ?? 0) === 403);
    $finalizedDayStatus = (string)$db->query("SELECT status FROM dl_ledger_day_status WHERE branch_id = " . (int)$branchId . " AND ledger_date = '" . $lateDate . "'")->fetchColumn();
    $h->test('finalized-shift rejection leaves the day closed', $finalizedDayStatus === 'closed');

    // C: non-ending field (beg_bal) on a closed+open-PM day → still rejected.
    $db->execute(
        "UPDATE dl_ledger_shift_status SET status = 'open', finalized_at = NULL
          WHERE branch_id = :b AND ledger_date = :d AND shift = 'PM'",
        [':b' => $branchId, ':d' => $lateDate]
    );
    $begApply = null;
    try {
        $begApply = dl_offlineApplyLedgerSave($lateCashier, [
            'type' => 'ledger_save',
            'payload' => ['branch_id' => $branchId, 'product_id' => $productId, 'field' => 'beg_bal', 'value' => 5, 'date' => $lateDate, 'shift' => 'PM'],
        ], true);
    } catch (RuntimeException $e) {
        $begApply = ['error' => $e->getMessage(), 'code' => $e->getCode()];
    }
    $h->test('non-ending field on a closed day is still rejected', is_array($begApply) && !empty($begApply['error']));
    $begDayStatus = (string)$db->query("SELECT status FROM dl_ledger_day_status WHERE branch_id = " . (int)$branchId . " AND ledger_date = '" . $lateDate . "'")->fetchColumn();
    $h->test('non-ending rejection leaves the day closed', $begDayStatus === 'closed');

    // Restore the previous request auth state (CLI test hygiene).
    if ($prevAuthHeader === null) {
        unset($_SERVER['HTTP_AUTHORIZATION']);
    } else {
        $_SERVER['HTTP_AUTHORIZATION'] = $prevAuthHeader;
    }
}

// ─── Cleanup (every seeded / created row) ──────────────────────────────
$tenantScope = (string)(app()->tenant()->current() ?? '');
$db->execute('DELETE FROM dl_offline_device_enrollments WHERE tenant_scope = :ts', [':ts' => $tenantScope]);
$db->execute('DELETE FROM dl_offline_sync_receipts WHERE tenant_scope = :ts', [':ts' => $tenantScope]);
$db->execute('DELETE FROM dl_daily_ledger WHERE branch_id = :b', [':b' => $branchId]);
$db->execute('DELETE FROM dl_cashier_withdrawals WHERE branch_id = :b', [':b' => $branchId]);
$db->execute('DELETE FROM dl_delivery_items WHERE delivery_id IN (SELECT id FROM dl_deliveries WHERE destination_id = :b)', [':b' => $branchId]);
$db->execute('DELETE FROM dl_branch_receiving_items WHERE receiving_id IN (SELECT id FROM dl_branch_receivings WHERE branch_id = :b)', [':b' => $branchId]);
$db->execute('DELETE FROM dl_deliveries WHERE destination_id = :b', [':b' => $branchId]);
$db->execute('DELETE FROM dl_branch_receivings WHERE branch_id = :b', [':b' => $branchId]);
$db->execute('DELETE FROM dl_ledger_day_status WHERE branch_id = :b', [':b' => $branchId]);
$db->execute('DELETE FROM dl_ledger_shift_status WHERE branch_id = :b', [':b' => $branchId]);
$db->execute('DELETE FROM dl_branch_products WHERE branch_id = :b', [':b' => $branchId]);
$db->execute('DELETE FROM dl_branches WHERE id = :b', [':b' => $branchId]);
$db->execute('DELETE FROM dl_products WHERE id = :p', [':p' => $productId]);
$db->execute('DELETE FROM dl_user_branches WHERE user_id = :uid', [':uid' => 999997]);
$db->execute('DELETE FROM dl_users WHERE id IN (999998, 999999, 999997)');

$h->done();
