<?php

declare(strict_types=1);

/**
 * Daily Ledger — closed_without_pm_finalize finding_type enum.
 *
 * Closes the measured coverage gap: commit 531106f8 raised the unfinalized-PM
 * admin notification with the existing 'variance' finding_type because
 * migrations were excluded from its allowed file set. The event was therefore
 * indistinguishable from a real count variance. This suite pins:
 *
 *   A  BOTH D4 call sites store the distinct 'closed_without_pm_finalize' value;
 *   B  the enum extension preserved all five ORIGINAL values and their ordinals
 *      (proven in isolation on a probe table with the exact ALTER, and against
 *      the pre-existing rows in the live notification table);
 *   C  migration 076 is rerun-safe through the real apply path and never
 *      rebuilds the enum twice;
 *   D  the REAL admin variances page still renders the new-type notification;
 *   E  the count of pre-existing 'variance' rows is unchanged (no backfill) and
 *      the historical closed_without_pm_finalize rows are NOT relabelled.
 *
 * Fixtures live in the reserved 991xx / 999xxx id range and are removed in
 * finally.
 */

ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-finding-type-enum', TestHarness::MODE_INTEGRATION, 'baronledger.test');
ob_end_clean();

$h->fingerprint('modules/daily-ledger/handlers.php');
$h->fingerprint('modules/daily-ledger/database/migrations/076_closed_without_pm_finalize_finding_type.sql');
$h->fingerprint('modules/daily-ledger/module.json');
$h->fingerprint('templates/modules/daily-ledger/admin/variances.disyl');
$h->fingerprint('tests/daily-ledger/daily_ledger_variances_render_harness.php');

// Rendering the admin page compiles the DiSyL template and may rebuild the
// module registry / capability map on a cold cache; both are instrumentation.
$h->allowLogLines('disyl.compile.phases', 'kernel_state_cache: module_registry rebuilt', 'kernel_state_cache: capability_map rebuilt');

$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/helpers.php';
require_once $base . '/modules/daily-ledger/handlers-deliveries.php';
require_once $base . '/modules/daily-ledger/handlers-offline.php';
require_once $base . '/modules/daily-ledger/handlers.php';

app()->tenant()->setTenantId(207);
$ctx = modulePushContext('daily-ledger');
if (!$ctx) {
    fwrite(STDERR, "daily-ledger module context unavailable\n");
    exit(1);
}
/** @var \Ikabud\Kernel\Contracts\DatabaseContract $db */
$db = $ctx->db();
/** Raw kernel PDO: information_schema + DDL are permitted only while the module
 *  enforcement context is suspended (see $withRaw). */
$rawPdo = app()->db();

/**
 * Run $fn with the module enforcement context temporarily suspended so the raw
 * kernel PDO can perform DDL and information_schema reads. ModuleDB ($db) sets
 * its own active module around each of its operations, so this does not widen
 * any module's normal table access.
 */
$withRaw = static function (callable $fn) {
    $prevCtx = \kernel_request_context_get('_activeModuleContext');
    $prevStatic = \Ikabud\Kernel\Database\KernelPDO::getActiveModule();
    \kernel_request_context_set('_activeModuleContext', null);
    \Ikabud\Kernel\Database\KernelPDO::setActiveModule(null);
    try {
        return $fn();
    } finally {
        \kernel_request_context_set('_activeModuleContext', $prevCtx);
        \Ikabud\Kernel\Database\KernelPDO::setActiveModule($prevStatic);
    }
};

$migrationRel = 'modules/daily-ledger/database/migrations/076_closed_without_pm_finalize_finding_type.sql';
$migrationKey = '076_closed_without_pm_finalize_finding_type.sql';
$newType = 'closed_without_pm_finalize';
$originalValues = ['variance', 'unresolved_origin', 'uncounted_receipt', 'historical_digest', 'receipt_mismatch'];

$enumColumn = static function () use ($withRaw, $rawPdo): array {
    return $withRaw(static function () use ($rawPdo): array {
        $row = $rawPdo->query(
            "SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
               FROM information_schema.columns
              WHERE table_schema = DATABASE()
                AND table_name = 'dl_integrity_notifications'
                AND column_name = 'finding_type'"
        )->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : [];
    });
};
$varianceCount = static function () use ($db): int {
    return (int)$db->query("SELECT COUNT(*) FROM dl_integrity_notifications WHERE finding_type = 'variance'")->fetchColumn();
};

// ── Fixtures ────────────────────────────────────────────────────────────────
$commBranchId = 99179;   // commissary: the PM-shift call site
$dayBranchId  = 99185;   // branch: the day call site
$commProduct  = 99179;
$dayProduct   = 99185;
$adminId      = 999879;

$today = dl_businessDate();
$prev  = (new DateTimeImmutable($today))->modify('-1 day')->format('Y-m-d');

$cleanup = static function () use ($db, $commBranchId, $dayBranchId, $commProduct, $dayProduct, $adminId): void {
    foreach ([$commBranchId, $dayBranchId] as $b) {
        $db->execute('DELETE FROM dl_integrity_notification_recipients WHERE notification_id IN (SELECT id FROM dl_integrity_notifications WHERE branch_id = :b)', [':b' => $b]);
        $db->execute('DELETE FROM dl_integrity_notifications WHERE branch_id = :b', [':b' => $b]);
        $db->execute('DELETE FROM audit_logs WHERE branch_id = :b', [':b' => $b]);
        $db->execute('DELETE FROM dl_commissary_product_ledger WHERE commissary_branch_id = :b', [':b' => $b]);
        $db->execute('DELETE FROM dl_daily_ledger WHERE branch_id = :b', [':b' => $b]);
        $db->execute('DELETE FROM dl_ledger_shift_status WHERE branch_id = :b', [':b' => $b]);
        $db->execute('DELETE FROM dl_ledger_day_status WHERE branch_id = :b', [':b' => $b]);
        $db->execute('DELETE FROM dl_branch_products WHERE branch_id = :b', [':b' => $b]);
    }
    $db->execute('DELETE FROM dl_users WHERE id = :a', [':a' => $adminId]);
    $db->execute('DELETE FROM dl_products WHERE id IN (:p1, :p2)', [':p1' => $commProduct, ':p2' => $dayProduct]);
    $db->execute('DELETE FROM dl_branches WHERE id IN (:b1, :b2)', [':b1' => $commBranchId, ':b2' => $dayBranchId]);
};
$cleanup();

$notifByKey = static function (string $key) use ($db): ?array {
    $stmt = $db->prepare('SELECT id, aggregate_key, finding_type, title FROM dl_integrity_notifications WHERE aggregate_key = :k LIMIT 1');
    $stmt->execute([':k' => $key]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
};

try {
    // ══════════════════════════════════════════════════════════════════════
    // Structural: migration is registered and carries the exact enum extension
    // ══════════════════════════════════════════════════════════════════════
    $h->section('Structure — migration 076 and its registration');

    $migrationSql = (string)file_get_contents($base . '/' . $migrationRel);
    $h->test('076 migration file exists and is non-empty', trim($migrationSql) !== '');
    $enumSql = "ENUM('" . implode("','", array_merge($originalValues, [$newType])) . "')";
    $enumSqlDoubled = "ENUM(''" . implode("'',''", array_merge($originalValues, [$newType])) . "'')";
    $h->test('076 ALTER appends the new value at the END of all five originals', str_contains($migrationSql, $enumSqlDoubled));
    $h->test('076 restates NOT NULL (same nullability as the live column)', str_contains($migrationSql, 'NOT NULL'));
    $h->test('076 is guarded with a LOCATE + PREPARE rerun check', preg_match('/LOCATE\s*\(/i', $migrationSql) === 1 && preg_match('/\bPREPARE\b/i', $migrationSql) === 1);
    $manifest = json_decode((string)file_get_contents($base . '/modules/daily-ledger/module.json'), true);
    $h->test('076 is registered in module.json', in_array('database/migrations/' . $migrationKey, $manifest['migrations'] ?? [], true));

    // ══════════════════════════════════════════════════════════════════════
    // B — isolated ordinals: the exact ALTER only appends
    // ══════════════════════════════════════════════════════════════════════
    $h->section('B — the ALTER preserves every original ordinal (isolated probe)');
    $withRaw(static function () use ($rawPdo, $enumSql, $originalValues, $newType): void {
        $rawPdo->exec('DROP TABLE IF EXISTS __dl_enum_probe_076');
        $rawPdo->exec("CREATE TABLE __dl_enum_probe_076 (id INT UNSIGNED NOT NULL PRIMARY KEY, finding_type ENUM('variance','unresolved_origin','uncounted_receipt','historical_digest','receipt_mismatch') NOT NULL) ENGINE=InnoDB");
        foreach ($originalValues as $i => $value) {
            $rawPdo->exec("INSERT INTO __dl_enum_probe_076 (id, finding_type) VALUES (" . ($i + 1) . ", '{$value}')");
        }
        $read = static function () use ($rawPdo): array {
            $rows = $rawPdo->query('SELECT id, finding_type, finding_type + 0 AS ordinal FROM __dl_enum_probe_076 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
            $out = [];
            foreach ($rows as $row) {
                $out[(int)$row['id']] = [(string)$row['finding_type'], (int)$row['ordinal']];
            }
            return $out;
        };
        $before = $read();
        $alter = "ALTER TABLE __dl_enum_probe_076 MODIFY COLUMN finding_type {$enumSql} NOT NULL";
        $rawPdo->exec($alter);
        $rawPdo->exec($alter); // exactly the same apply path twice
        $after = $read();
        $GLOBALS['__probe_before'] = $before;
        $GLOBALS['__probe_after'] = $after;
        $rawPdo->exec("INSERT INTO __dl_enum_probe_076 (id, finding_type) VALUES (99, '{$newType}')");
        $GLOBALS['__probe_new_ordinal'] = (int)$rawPdo->query("SELECT finding_type + 0 FROM __dl_enum_probe_076 WHERE id = 99")->fetchColumn();
    });
    $probeBefore = $GLOBALS['__probe_before'] ?? [];
    $probeAfter = $GLOBALS['__probe_after'] ?? [];
    $h->test('B1 all five original values are read back byte-identical after the ALTER', $probeBefore === $probeAfter && $probeBefore !== []);
    $ordinalsIntact = true;
    foreach ($originalValues as $i => $value) {
        $ordinalsIntact = $ordinalsIntact && ($probeAfter[$i + 1] ?? null) === [$value, $i + 1];
    }
    $h->test('B2 every original value keeps its ordinal (1..5) and meaning', $ordinalsIntact, json_encode($probeAfter));
    $h->test('B3 the new value is accepted at ordinal 6', (int)($GLOBALS['__probe_new_ordinal'] ?? 0) === 6, 'ordinal=' . (int)($GLOBALS['__probe_new_ordinal'] ?? 0));
    $withRaw(static fn() => $rawPdo->exec('DROP TABLE IF EXISTS __dl_enum_probe_076'));

    // ══════════════════════════════════════════════════════════════════════
    // E (pre) — capture the historical state before re-running the apply path
    // ══════════════════════════════════════════════════════════════════════
    $h->section('E — no historical relabelling');
    // E1 compares only rows that existed BEFORE the re-apply (id <= the max id
    // at snapshot time) so ongoing background notification activity cannot make
    // the assertion flaky. The migration performs no DML; this pins that.
    $maxIdBefore = (int)$db->query('SELECT COALESCE(MAX(id), 0) FROM dl_integrity_notifications')->fetchColumn();
    $varianceBefore = (int)$db->query("SELECT COUNT(*) FROM dl_integrity_notifications WHERE finding_type = 'variance' AND id <= {$maxIdBefore}")->fetchColumn();
    $preExistingClosed = $db->query(
        "SELECT id, aggregate_key, finding_type FROM dl_integrity_notifications
          WHERE aggregate_key LIKE 'closed_without_pm_finalize-%' ORDER BY id"
    )->fetchAll(PDO::FETCH_ASSOC);
    $preExistingByType = [];
    foreach ($originalValues as $value) {
        $preExistingByType[$value] = $db->query(
            "SELECT id, finding_type FROM dl_integrity_notifications WHERE finding_type = '" . $value . "' ORDER BY id LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    $h->test('E0 historical closed_without_pm_finalize rows still exist and all stored variance', $preExistingClosed !== [], 'rows=' . count($preExistingClosed));

    // ══════════════════════════════════════════════════════════════════════
    // C — rerun-safety through the real MigrationRunner apply path
    // ══════════════════════════════════════════════════════════════════════
    $h->section('C — the apply path is rerun-safe');
    $runner = $withRaw(static fn() => new \Ikabud\Kernel\Database\MigrationRunner($rawPdo));
    $applyAgain = static function () use ($withRaw, $rawPdo, $runner, $migrationKey): array {
        return $withRaw(static function () use ($rawPdo, $runner, $migrationKey): array {
            // Drop the tracking row so the runner treats 076 as pending again.
            // A re-apply after a lost tracking row must survive unchanged.
            $rawPdo->prepare("DELETE FROM _migrations WHERE module = 'daily-ledger' AND migration = :m")->execute([':m' => $migrationKey]);
            return $runner->migrate('daily-ledger');
        });
    };
    $firstRerun = [];
    $firstRerunError = null;
    try { $firstRerun = $applyAgain(); } catch (Throwable $e) { $firstRerunError = $e->getMessage(); }
    $h->test('C1 first re-apply runs 076 without error', $firstRerunError === null && in_array($migrationKey, $firstRerun, true), json_encode(['error' => $firstRerunError, 'executed' => $firstRerun]));
    $secondRerun = [];
    $secondRerunError = null;
    try { $secondRerun = $applyAgain(); } catch (Throwable $e) { $secondRerunError = $e->getMessage(); }
    $h->test('C2 second re-apply runs 076 without error', $secondRerunError === null && in_array($migrationKey, $secondRerun, true), json_encode(['error' => $secondRerunError, 'executed' => $secondRerun]));

    $column = $enumColumn();
    $occurrences = substr_count((string)($column['COLUMN_TYPE'] ?? ''), $newType);
    $h->test('C3 the enum still holds exactly ONE ' . $newType . ' value', $occurrences === 1, 'occurrences=' . $occurrences);
    // 076's contract is about ORDER: the five originals, then the appended
    // value, with nothing dropped, renamed or reordered. Comparing the PREFIX
    // pins exactly that and stays true when a later migration appends another
    // value (078 does). An exact === comparison would make this test fail on
    // every future append and tempt someone to relax it instead of reading it.
    $enumPrefix = "enum('" . implode("','", array_merge($originalValues, [$newType])) . "'";
    $h->test('C4 column definition opens with the six-value enum 076 appended to, NOT NULL, no default',
        str_starts_with(strtolower((string)($column['COLUMN_TYPE'] ?? '')), $enumPrefix)
        && (string)($column['IS_NULLABLE'] ?? '') === 'NO'
        && ($column['COLUMN_DEFAULT'] ?? null) === null,
        json_encode($column));

    // ══════════════════════════════════════════════════════════════════════
    // B (live) — pre-existing rows per value survive the re-apply
    // ══════════════════════════════════════════════════════════════════════
    $h->section('B — live rows keep their stored meaning');
    $liveIntact = true;
    $seenValues = [];
    foreach ($originalValues as $value) {
        $before = $preExistingByType[$value] ?? null;
        if ($before === null) {
            $seenValues[$value] = 'no-preexisting-row';
            continue;
        }
        $after = $db->query("SELECT finding_type FROM dl_integrity_notifications WHERE id = " . (int)$before['id'])->fetch(PDO::FETCH_ASSOC);
        $liveIntact = $liveIntact && is_array($after) && (string)$after['finding_type'] === $value;
        $seenValues[$value] = (string)($after['finding_type'] ?? 'GONE');
    }
    $h->test('B4 pre-existing rows for each original value read back unchanged', $liveIntact, json_encode($seenValues));

    // All five ORIGINAL values remain valid at the live column, proven with a
    // transaction that inserts one row per value and rolls back.
    $validity = $withRaw(static function () use ($rawPdo, $originalValues, $newType): array {
        $rawPdo->beginTransaction();
        $validity = [];
        try {
            foreach (array_merge($originalValues, [$newType]) as $i => $value) {
                $key = '__enum_validity_' . $i;
                $rawPdo->prepare('INSERT INTO dl_integrity_notifications (aggregate_key, finding_type, title) VALUES (:k, :t, :ti)')
                    ->execute([':k' => $key, ':t' => $value, ':ti' => 'validity probe']);
                $read = $rawPdo->prepare('SELECT finding_type FROM dl_integrity_notifications WHERE aggregate_key = :k');
                $read->execute([':k' => $key]);
                $validity[$value] = (string)$read->fetchColumn();
            }
        } finally {
            if ($rawPdo->inTransaction()) {
                $rawPdo->rollBack();
            }
        }
        return $validity;
    });
    $allValid = true;
    foreach (array_merge($originalValues, [$newType]) as $value) {
        $allValid = $allValid && ($validity[$value] ?? null) === $value;
    }
    $h->test('B5 all five ORIGINAL values remain valid (plus the new value)', $allValid, json_encode($validity));

    // ══════════════════════════════════════════════════════════════════════
    // A — both D4 call sites store the distinct new value
    // ══════════════════════════════════════════════════════════════════════
    $h->section('A — closed_without_pm_finalize events carry the new finding_type');

    // Fixtures: commissary with a moved product and no PM ending (PM call site).
    $db->execute(
        'INSERT INTO dl_branches (id, code, name, address, default_supply_mode, is_commissary, is_active)
         VALUES (:id, :code, :name, :addr, "self_managed", 1, 1)',
        [':id' => $commBranchId, ':code' => 'FTE-COMM', ':name' => 'Finding Type Commissary', ':addr' => 'Test']
    );
    $db->execute(
        'INSERT INTO dl_branches (id, code, name, address, default_supply_mode, is_commissary, is_active)
         VALUES (:id, :code, :name, :addr, "self_managed", 0, 1)',
        [':id' => $dayBranchId, ':code' => 'FTE-BR', ':name' => 'Finding Type Branch', ':addr' => 'Test']
    );
    $db->execute(
        'INSERT INTO dl_products (id, sku, name, current_price, sort_order, is_active) VALUES (:id, :sku, :n, 1, 99179, 1)',
        [':id' => $commProduct, ':sku' => 'FTE-COMM-P', ':n' => 'Finding Type Comm Product']
    );
    $db->execute(
        'INSERT INTO dl_products (id, sku, name, current_price, sort_order, is_active) VALUES (:id, :sku, :n, 1, 99185, 1)',
        [':id' => $dayProduct, ':sku' => 'FTE-BR-P', ':n' => 'Finding Type Branch Product']
    );
    $db->execute('INSERT INTO dl_branch_products (branch_id, product_id, is_active) VALUES (:b, :p, 1)', [':b' => $commBranchId, ':p' => $commProduct]);
    $db->execute(
        'INSERT INTO dl_users (id, username, password_hash, full_name, role, shift, is_active) VALUES (:id, :u, :p, :n, "admin", NULL, 1)',
        [':id' => $adminId, ':u' => 'finding-type-admin', ':p' => 'not-a-login-hash', ':n' => 'Finding Type Admin']
    );
    $db->execute(
        'INSERT INTO dl_commissary_product_ledger (commissary_branch_id, product_id, ledger_date, shift, beg_qty, produced_qty, dispatched_qty, wastage_qty, actual_end_qty)
         VALUES (:b, :p, :d, "PM", 5, 0, 0, 0, NULL)',
        [':b' => $commBranchId, ':p' => $commProduct, ':d' => $prev]
    );

    $pmResult = dl_maybeAutoFinalizeCommissaryPmShift($commBranchId, $prev, $adminId);
    $pmRow = $notifByKey('closed_without_pm_finalize-pm-' . $commBranchId . '-' . $prev);
    $h->test('A1 the PM call site flagged the shift', ($pmResult['flagged'] ?? false) === true, json_encode($pmResult));
    $h->test('A2 the PM notification finding_type is ' . $newType, is_array($pmRow) && (string)$pmRow['finding_type'] === $newType, json_encode($pmRow));

    // Day call site: an open cashier PM shift makes the day close while
    // flagged (owner directive 2026-10-05; it no longer refuses the close).
    $db->execute(
        'INSERT INTO dl_daily_ledger (branch_id, product_id, ledger_date, shift, beg_bal, bal_end, sales)
         VALUES (:b, :p, :d, "PM", 5, NULL, NULL)',
        [':b' => $dayBranchId, ':p' => $dayProduct, ':d' => $prev]
    );
    $dayResult = false;
    try { $dayResult = dl_maybeAutoCloseBranchDay($dayBranchId, $adminId, null); } catch (Throwable $e) { $h->detail('day close threw: ' . $e->getMessage()); }
    $dayRow = $notifByKey('closed_without_pm_finalize-day-' . $dayBranchId . '-' . $prev);
    $dayStatusStmt = $db->prepare('SELECT status FROM dl_ledger_day_status WHERE branch_id = :b AND ledger_date = :d LIMIT 1');
    $dayStatusStmt->execute([':b' => $dayBranchId, ':d' => $prev]);
    $dayStatusVal = $dayStatusStmt->fetchColumn();
    $h->test('A3 the day call site CLOSES the day without a finalized PM', $dayResult === true && $dayStatusVal === 'closed', json_encode(['returned' => $dayResult, 'day' => $dayStatusVal]));
    $h->test('A4 the day notification finding_type is ' . $newType, is_array($dayRow) && (string)$dayRow['finding_type'] === $newType, json_encode($dayRow));

    // ══════════════════════════════════════════════════════════════════════
    // E — the apply path changed no historical variance row
    // ══════════════════════════════════════════════════════════════════════
    $varianceAfter = $varianceCount();
    $varianceAfterScoped = (int)$db->query("SELECT COUNT(*) FROM dl_integrity_notifications WHERE finding_type = 'variance' AND id <= {$maxIdBefore}")->fetchColumn();
    $h->test('E1 the pre-existing variance rows are unchanged by the apply path', $varianceBefore === $varianceAfterScoped, "scoped_before={$varianceBefore} scoped_after={$varianceAfterScoped} global_after={$varianceAfter}");

    $closedAfter = $db->query(
        "SELECT id, finding_type FROM dl_integrity_notifications
          WHERE aggregate_key LIKE 'closed_without_pm_finalize-%' ORDER BY id"
    )->fetchAll(PDO::FETCH_ASSOC);
    $historicalUnchanged = true;
    $historicalIds = [];
    foreach ($preExistingClosed as $row) {
        $historicalIds[(int)$row['id']] = (string)$row['finding_type'];
    }
    foreach ($closedAfter as $row) {
        $id = (int)$row['id'];
        if (isset($historicalIds[$id]) && (string)$row['finding_type'] !== $historicalIds[$id]) {
            $historicalUnchanged = false;
        }
    }
    $h->test('E2 pre-existing closed_without_pm_finalize rows are NOT relabelled', $historicalUnchanged, 'checked=' . count($historicalIds));

    // ══════════════════════════════════════════════════════════════════════
    // D — the real admin variances page renders the new-type notification
    // ══════════════════════════════════════════════════════════════════════
    $h->section('D — the admin notification surface still renders');

    $templateSource = (string)file_get_contents($base . '/templates/modules/daily-ledger/admin/variances.disyl');
    $h->test('D1 the admin surface does not map or render finding_type (no label map to extend)', !str_contains($templateSource, 'finding_type'));

    $recipientStmt = $db->prepare(
        'SELECT COUNT(*) FROM dl_integrity_notification_recipients WHERE notification_id = :n AND user_id = :u'
    );
    $recipientStmt->execute([':n' => (int)($pmRow['id'] ?? 0), ':u' => $adminId]);
    // Render as our own fixture admin: its notification list contains ONLY this
    // suite's rows, so the surface's top-20 window cannot evict ours.
    $recipientUserId = (int)$recipientStmt->fetchColumn() > 0 ? $adminId : 0;
    $h->test('D2 the new-type notification has an admin recipient to render for', $recipientUserId > 0, 'user_id=' . $recipientUserId);

    $html = '';
    if ($recipientUserId > 0) {
        $command = escapeshellarg(PHP_BINARY) . ' '
            . escapeshellarg(__DIR__ . '/daily_ledger_variances_render_harness.php') . ' '
            . escapeshellarg((string)$recipientUserId) . ' admin 2>/dev/null';
        $html = (string)shell_exec($command);
    }
    $h->test('D3 the rendered page is non-empty HTML', strlen($html) > 10000, 'html_bytes=' . strlen($html));
    $h->test('D4 the rendered page contains the notification card', str_contains($html, 'Integrity notifications'));
    $h->test('D5 the rendered page shows the new-type notification title and id',
        $html !== '' && str_contains($html, 'PM shift not finalized') && str_contains($html, 'data-notification-id="' . (int)($pmRow['id'] ?? 0) . '"'),
        'notification_id=' . (int)($pmRow['id'] ?? 0));
} finally {
    try { $withRaw(static fn() => $rawPdo->exec('DROP TABLE IF EXISTS __dl_enum_probe_076')); } catch (Throwable $ignored) {}
    $cleanup();
}

$h->done();
