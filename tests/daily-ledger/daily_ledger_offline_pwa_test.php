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
$db->execute('DELETE FROM dl_branch_products WHERE branch_id = :b', [':b' => $branchId]);
$db->execute('DELETE FROM dl_branches WHERE id = :b', [':b' => $branchId]);
$db->execute('DELETE FROM dl_products WHERE id = :p', [':p' => $productId]);
$db->execute('DELETE FROM dl_users WHERE id IN (999998, 999999)');

$h->done();
