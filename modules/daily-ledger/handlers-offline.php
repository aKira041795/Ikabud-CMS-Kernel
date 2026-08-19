<?php

declare(strict_types=1);

/**
 * Daily Ledger — Offline PWA enrollment / bootstrap / status / revoke /
 * reconcile.
 *
 * These are ADDITIVE, versioned browser-PWA endpoints. They never touch the
 * Android routes and never return the offline shell to APIs or unrelated
 * routes. All enrollment ownership claims (tenant, actor, branch, role,
 * shift, day status) are DERIVED server-side from the authenticated session
 * at enroll time and re-validated at every contact. The table stores device
 * hash / actor / branch / grant-version / issued-expiry-revoked / last-sync
 * only — never a PIN, wrapping key, or cloud credential.
 */

// ─── Enrollment helpers ───────────────────────────────────────────────

function dl_offlineMaxDays(): int
{
    $days = (int)(dlModuleSettings()['max_offline_days'] ?? 14);
    if ($days < 1) {
        $days = 1;
    }
    if ($days > 90) {
        $days = 90;
    }

    return $days;
}

function dl_offlineSchemaVersion(): int
{
    return 1;
}

function dl_offlineBootstrapVersion(): string
{
    return '1';
}

function dl_offlineGrantVersion(): int
{
    return 1;
}

/**
 * Approved offline operations. Everything else (day close/reopen, POS,
 * dispatch, delivery correction) stays online-only and the shell explains
 * why it is blocked.
 */
function dl_offlineAllowedOperations(): array
{
    return ['ledger_save', 'withdrawal', 'receive_paper_dr'];
}

function dl_offlineDeviceHash(string $tenantScope, string $deviceId): string
{
    return hash('sha256', (string)$tenantScope . '|' . trim((string)$deviceId));
}

function dl_offlineNormalizeDeviceId($value): string
{
    $deviceId = trim((string)($value ?? ''));
    if ($deviceId === '' || !preg_match('/^[A-Za-z0-9\-_]{8,64}$/', $deviceId)) {
        return '';
    }

    return substr($deviceId, 0, 64);
}

/**
 * Loads the enrollment row scoped to tenant + actor + device hash. Missing
 * or mismatched scope fails closed (returns null).
 */
function dl_offlineFindEnrollment(array $user, string $enrollmentId, string $deviceId): ?array
{
    $ctx = module();
    if (!$ctx) {
        return null;
    }

    $tenantScope = (string)(app()->tenant()->current() ?? '');
    $actorId = dl_getActorUserId($user);
    $deviceHash = dl_offlineDeviceHash($tenantScope, $deviceId);

    $stmt = $ctx->db()->prepare(
        'SELECT * FROM dl_offline_device_enrollments
         WHERE tenant_scope = :ts AND enrollment_id = :eid AND actor_user_id = :uid AND device_hash = :dh
         LIMIT 1'
    );
    $stmt->execute([
        ':ts' => $tenantScope,
        ':eid' => $enrollmentId,
        ':uid' => $actorId,
        ':dh' => $deviceHash,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($row) && $row !== [] ? $row : null;
}

/**
 * Validates an enrollment before any offline write/sync is allowed.
 * Fails closed on tenant/schema mismatch, revocation, expiry, branch/account
 * drift, or inactive actor. Returns ['ok' => bool, ...].
 */
function dl_offlineValidateEnrollment(array $user, array $row): array
{
    $tenantScope = (string)(app()->tenant()->current() ?? '');
    if ((string)($row['tenant_scope'] ?? '') !== $tenantScope) {
        return ['ok' => false, 'error' => 'Offline enrollment belongs to another tenant.', 'reason' => 'scope-mismatch'];
    }

    if ((int)($row['schema_version'] ?? 0) !== dl_offlineSchemaVersion()) {
        return ['ok' => false, 'error' => 'Offline schema version mismatch — re-enroll from the online ledger.', 'reason' => 'schema-mismatch'];
    }

    $status = (string)($row['status'] ?? '');
    if ($status === 'revoked') {
        return ['ok' => false, 'error' => 'Offline access was revoked. Re-enroll online to continue.', 'reason' => 'revoked'];
    }
    if ($status === 'expired') {
        return ['ok' => false, 'error' => 'Offline access has expired. Re-enroll online to continue.', 'reason' => 'expired'];
    }

    $expiresAt = strtotime((string)($row['expires_at'] ?? ''));
    if ($expiresAt !== false && $expiresAt < time()) {
        return ['ok' => false, 'error' => 'Offline access has expired. Re-enroll online to continue.', 'reason' => 'expired'];
    }

    if ((int)($row['actor_user_id'] ?? 0) !== dl_getActorUserId($user)) {
        return ['ok' => false, 'error' => 'Account changed since enrollment. Re-enroll online.', 'reason' => 'account-mismatch'];
    }

    // Actor must still be active in the module's user table. A missing row is
    // tolerated here only because the authenticated session already validated
    // the actor; if the row exists and is inactive/deleted, fail closed.
    try {
        $ctx = module();
        if ($ctx) {
            $uStmt = $ctx->db()->prepare('SELECT is_active, deleted_at FROM dl_users WHERE id = :id LIMIT 1');
            $uStmt->execute([':id' => (int)$row['actor_user_id']]);
            $uRow = $uStmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($uRow) && $uRow !== [] && ((int)($uRow['is_active'] ?? 0) !== 1 || !empty($uRow['deleted_at']))) {
                return ['ok' => false, 'error' => 'Account is inactive. Re-enroll online.', 'reason' => 'inactive'];
            }
        }
    } catch (Throwable $e) {
        // Non-fatal; the auth layer already validated the session.
    }

    // Branch is re-derived from the authenticated session; the stored branch
    // is never trusted on its own. Branch changes require a new enrollment.
    $authResult = dl_authorizeBranch($user);
    if ($authResult['branch_id'] < 0 || $authResult['branch_id'] <= 0) {
        return ['ok' => false, 'error' => 'No authorized branch for offline access. Re-enroll online.', 'reason' => 'branch-mismatch'];
    }
    if ((int)$authResult['branch_id'] !== (int)($row['branch_id'] ?? 0)) {
        return ['ok' => false, 'error' => 'Branch changed since enrollment. Re-enroll on the current branch.', 'reason' => 'branch-mismatch'];
    }

    return ['ok' => true, 'enrollment' => $row];
}

function dl_offlineEnrollmentDescriptor(array $row): array
{
    $branchId = (int)($row['branch_id'] ?? 0);

    return [
        'enrollment_id' => (string)$row['enrollment_id'],
        'tenant_scope' => (string)$row['tenant_scope'],
        'actor_user_id' => (int)$row['actor_user_id'],
        'branch_id' => $branchId,
        'branch_name' => $branchId > 0 ? dl_getBranchName($branchId) : '',
        'role' => (string)$row['role'],
        'shift' => !empty($row['shift']) ? (string)$row['shift'] : null,
        'grant_version' => (int)$row['grant_version'],
        'schema_version' => (int)$row['schema_version'],
        'bootstrap_version' => (string)$row['bootstrap_version'],
        'status' => (string)$row['status'],
        'issued_at' => (string)$row['issued_at'],
        'expires_at' => (string)$row['expires_at'],
        'last_sync_at' => !empty($row['last_sync_at']) ? (string)$row['last_sync_at'] : null,
        'allowed_operations' => dl_offlineAllowedOperations(),
        'max_offline_days' => dl_offlineMaxDays(),
    ];
}

/**
 * Bounded reference/work snapshot for the offline vault. This is NOT a local
 * DB replica — only the data needed to render the cashier ledger offline.
 */
function dl_offlineBootstrapPayload(array $user, int $branchId, string $shift, bool $shiftBound): array
{
    $ctx = module();
    $db = $ctx ? $ctx->db() : null;
    $tenantScope = (string)(app()->tenant()->current() ?? '');
    $actorId = dl_getActorUserId($user);
    $role = (string)($user['role'] ?? '');
    $today = dl_businessDate();
    $clock = dl_operatingClockLabel();
    $dayStatus = $branchId > 0 ? dl_getDayStatus($branchId, $today) : 'open';

    $products = [];
    $ledgerRows = [];
    if ($branchId > 0 && $db) {
        try {
            $stmt = $db->prepare(
                'SELECT p.id, p.name, p.sku, p.current_price, p.product_category, p.output_unit_label
                   FROM dl_products p
                   INNER JOIN dl_branch_products bp ON bp.product_id = p.id AND bp.branch_id = :bid AND bp.is_active = 1
                  WHERE p.is_active = 1
                  ORDER BY p.sort_order, p.name'
            );
            $stmt->execute([':bid' => $branchId]);
            $products = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            $products = [];
        }

        try {
            $ledgerRows = dl_fetchCashierLedgerRows($db, $branchId, $today, $shift);
        } catch (Throwable $e) {
            $ledgerRows = [];
        }
    }

    $liablePersons = [];
    if ($db) {
        try {
            $liablePersons = $db->query(
                "SELECT id, COALESCE(NULLIF(full_name, ''), username, CONCAT('User #', id)) AS name, role
                   FROM dl_users
                  WHERE is_active = 1 AND deleted_at IS NULL
                    AND role IN ('production_in_charge', 'supervisor', 'admin')
                  ORDER BY role = 'production_in_charge' DESC, name ASC"
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            $liablePersons = [];
        }
    }

    return [
        'server_time' => date('c'),
        'server_timezone' => $clock['operating_timezone'],
        'tenant_scope' => $tenantScope,
        'actor_user_id' => $actorId,
        'role' => $role,
        'branch' => [
            'id' => $branchId,
            'name' => $branchId > 0 ? dl_getBranchName($branchId) : '',
        ],
        'business_date' => $today,
        'close_of_day_time' => $clock['close_of_day_time'],
        'auto_close_enabled' => (bool)$clock['auto_close_enabled'],
        'operating_timezone' => $clock['operating_timezone'],
        'operating_region' => $clock['operating_region'],
        'am_shift_cutoff' => dl_amShiftCutoff(),
        'day_status' => $dayStatus,
        'shift' => $shift,
        'shift_locked' => $shiftBound,
        'reference_only' => ($role === 'cashier' && false),
        'products' => $products,
        'ledger_rows' => $ledgerRows,
        'liable_persons' => $liablePersons,
        'formal_delivery_enabled' => dl_isFormalDeliveryEnabled(),
        'pos_enabled' => dl_isPosEnabled(),
        'can_pos_sell' => dl_isPosEnabled() && dl_pos_userCan($user, 'pos.sell'),
        'can_edit_delivery' => (function () use ($user) {
            $r = (string)($user['role'] ?? '');
            return in_array($r, ['supervisor', 'admin'], true) || dl_roleHasPermission($r, 'delivery.edit');
        })(),
        'allowed_operations' => dl_offlineAllowedOperations(),
        'server_versions' => [
            'schema_version' => dl_offlineSchemaVersion(),
            'bootstrap_version' => dl_offlineBootstrapVersion(),
            'grant_version' => dl_offlineGrantVersion(),
        ],
        'snapshot_at' => date('c'),
    ];
}

function dl_offlineInsertEnrollment(array $user, string $deviceId, int $branchId, string $shift, bool $shiftBound): array
{
    $ctx = module();
    if (!$ctx) {
        throw new RuntimeException('Module context unavailable');
    }

    $tenantScope = (string)(app()->tenant()->current() ?? '');
    $actorId = dl_getActorUserId($user);
    $role = (string)($user['role'] ?? '');
    $enrollmentId = dl_generateMovementUuid();
    $deviceHash = dl_offlineDeviceHash($tenantScope, $deviceId);
    $now = time();
    $maxDays = dl_offlineMaxDays();

    $stmt = $ctx->db()->prepare(
        'INSERT INTO dl_offline_device_enrollments
            (tenant_scope, enrollment_id, device_id, device_hash, actor_user_id, branch_id, role, shift,
             grant_version, schema_version, bootstrap_version, status, issued_at, expires_at)
         VALUES
            (:ts, :eid, :did, :dh, :uid, :bid, :role, :shift,
             :gv, :sv, :bv, "active", :issued, :expires)'
    );
    $stmt->execute([
        ':ts' => $tenantScope,
        ':eid' => $enrollmentId,
        ':did' => substr($deviceId, 0, 64),
        ':dh' => $deviceHash,
        ':uid' => $actorId,
        ':bid' => $branchId,
        ':role' => $role,
        ':shift' => $shiftBound ? $shift : null,
        ':gv' => dl_offlineGrantVersion(),
        ':sv' => dl_offlineSchemaVersion(),
        ':bv' => dl_offlineBootstrapVersion(),
        ':issued' => date('Y-m-d H:i:s', $now),
        ':expires' => date('Y-m-d H:i:s', $now + ($maxDays * 86400)),
    ]);

    $row = dl_offlineFindEnrollment($user, $enrollmentId, $deviceId);
    if ($row === null) {
        throw new RuntimeException('Failed to read back the enrollment record.');
    }

    return $row;
}

// ─── Operation workers (offline reconcile only; online handlers untouched) ──

/**
 * Ledger single-field save, mirroring apiSaveLedgerField exactly (allowlist,
 * value bounds, production-lock, reference-only, day-closed, upsert,
 * variance, recompute, audit).
 *
 * @throws RuntimeException with an HTTP status code (422/403/500) on failure
 */
/**
 * Whether a cashier's late PM ending (bal_end) may be recovered onto a CLOSED
 * day through the prior-pending-PM flow, instead of being hard-quarantined.
 *
 * FAIL-CLOSED: only while the PM shift is still recoverable (NOT finalized).
 * A finalized PM shift means every ending was recorded and locked — immutable,
 * so the late value must be rejected (never reopen a finalized shift). The day
 * must actually be closed. Only cashier actors reach this path.
 */
function dl_lateEndingReopenEligible(\Ikabud\Kernel\Contracts\DatabaseContract $db, int $branchId, string $date): bool
{
    if (dl_shiftIsFinalized($db, $branchId, $date, 'PM')) {
        return false;
    }
    if (dl_getDayStatus($branchId, $date) !== 'closed') {
        return false;
    }
    return true;
}

/**
 * Reopens a closed day into the open/pending-PM recovery state so a late PM
 * ending captured offline can be recorded. Official sales stay PROVISIONAL
 * (unfinalized) until the PM shift is finalized through the normal flow; the
 * day then re-closes at the next auto-close pass. Audited for traceability.
 *
 * MUST be called inside the caller's transaction (the day-status row is locked
 * by dl_lockDayStatusRow).
 */
function dl_reopenDayForLateEnding(\Ikabud\Kernel\Contracts\DatabaseContract $db, int $branchId, string $date, int $userId): void
{
    $stmt = $db->prepare(
        "UPDATE dl_ledger_day_status
            SET status = 'open', closed_by = NULL, closed_at = NULL, updated_at = CURRENT_TIMESTAMP
          WHERE branch_id = :bid AND ledger_date = :d AND status = 'closed'"
    );
    $stmt->execute([':bid' => $branchId, ':d' => $date]);
    if ($stmt->rowCount() > 0) {
        dl_auditLog('late_ending_reopen', $branchId, 'dl_ledger_day_status', "{$branchId}-{$date}", ['status' => 'closed'], [
            'status' => 'open',
            'source' => 'offline_reconcile_late_ending',
            'by_user_id' => $userId,
        ]);
    }
}

function dl_offlineApplyLedgerSave(array $user, array $op, bool $inTx = false): array
{
    $ctx = module();
    if (!$ctx) {
        throw new RuntimeException('Module context unavailable', 500);
    }

    $input = is_array($op['payload'] ?? null) ? $op['payload'] : [];
    $authResult = dl_authorizeBranch($user, $input);
    if ($authResult['branch_id'] < 0) {
        throw new RuntimeException('Branch not authorized', 403);
    }
    $branchId = $authResult['branch_id'];
    $productId = (int)($input['product_id'] ?? 0);
    $field = (string)($input['field'] ?? '');
    $rawValue = $input['value'] ?? null;
    $date = (string)($input['date'] ?? dl_businessDate());
    $shiftResolved = dl_resolveLedgerShift($user, $input);
    $shift = $shiftResolved['shift'];
    $userId = dl_getActorUserId($user);
    $role = (string)($user['role'] ?? '');

    if ($branchId) {
        dl_maybeAutoCloseBranchDay($branchId, $userId);
    }

    $fieldMap = ['beg_bal' => 'beg_bal', 'addtl' => 'addtl', 'withdraw' => 'withdraw', 'bal_end' => 'bal_end'];
    $column = dl_allowedColumn($field, $fieldMap);
    if ($column === null || !$branchId || !$productId || $userId <= 0) {
        throw new RuntimeException('Invalid input', 422);
    }
    // bal_end accepts null (uncounted ending); every other field is an int.
    if ($column === 'bal_end' && ($rawValue === null || $rawValue === '')) {
        $value = null;
    } elseif ($column === 'bal_end' && !is_numeric($rawValue)) {
        throw new RuntimeException('Value must be a number', 422);
    } else {
        $value = (int)$rawValue;
    }
    if ($value !== null && ($value < 0 || $value > 999999999)) {
        throw new RuntimeException('Value out of bounds', 422);
    }

    if ($role === 'cashier' && in_array($field, ['addtl', 'withdraw'], true)) {
        $movementType = $field === 'addtl' ? 'output' : 'withdrawal';
        $lockStmt = $ctx->db()->prepare(
            'SELECT COUNT(*) FROM dl_production_movements pm
             WHERE pm.destination_branch_id = :bid AND pm.product_id = :pid AND pm.ledger_date = :d
               AND pm.movement_type = :mtype
               AND NOT EXISTS (
                   SELECT 1 FROM dl_production_movements r
                   WHERE r.reference_movement_id = pm.id AND r.movement_type = :rev
               )'
        );
        $lockStmt->execute([
            ':bid' => $branchId,
            ':pid' => $productId,
            ':d' => $date,
            ':mtype' => $movementType,
            ':rev' => 'reverse',
        ]);
        if ((int)$lockStmt->fetchColumn() > 0) {
            throw new RuntimeException('Set by production — cannot override', 403);
        }
    }

    // Phase 4 late-ending bridge: a cashier's pending PM ending may be recovered
    // onto a closed day while the PM shift is still open (prior-pending-PM flow).
    $lateEndingEligible = ($role === 'cashier' && $column === 'bal_end' && $shift === 'PM'
        && dl_lateEndingReopenEligible($ctx->db(), $branchId, $date));

    if ($role === 'cashier' && !$lateEndingEligible
        && !dl_cashierMayEdit($branchId, $date, $shift, dl_businessDate(), dl_getDayStatus($branchId, $date))) {
        throw new RuntimeException('Reference only', 403);
    }

    if (!$inTx) {
        $ctx->db()->beginTransaction();
    }
    try {
        $dayStatus = dl_lockDayStatusRow($ctx->db(), $branchId, $date);
        if ($dayStatus === 'closed' && $role === 'cashier') {
            if ($lateEndingEligible) {
                // Re-verify under the day-status lock: the day/shift may have
                // changed between the pre-check and the lock (fail closed).
                if (dl_lateEndingReopenEligible($ctx->db(), $branchId, $date)) {
                    dl_reopenDayForLateEnding($ctx->db(), $branchId, $date, $userId);
                } else {
                    throw new RuntimeException('Day is closed', 403);
                }
            } else {
                throw new RuntimeException('Day is closed', 403);
            }
        }
        dl_assertShiftMutable($ctx->db(), $branchId, $date, $shift);

        $currentPrice = dl_resolveBranchProductPrice($branchId, $productId, $date);
        $oldStmt = $ctx->db()->prepare(
            "SELECT {$column} AS current_value FROM dl_daily_ledger WHERE branch_id = :bid AND product_id = :pid AND ledger_date = :d AND shift = :shift LIMIT 1 FOR UPDATE"
        );
        $oldStmt->execute([':bid' => $branchId, ':pid' => $productId, ':d' => $date, ':shift' => $shift]);
        $oldVal = $oldStmt->fetchColumn();

        $stmt = $ctx->db()->prepare(
            "INSERT INTO dl_daily_ledger (branch_id, product_id, ledger_date, shift, price_snapshot, {$column}, encoded_by, updated_by)
             VALUES (:bid, :pid, :d, :shift, :price, :val, :uid, :uid2)
             ON DUPLICATE KEY UPDATE {$column} = :val2, updated_by = :uid3, updated_at = CURRENT_TIMESTAMP"
        );
        $stmt->execute([
            ':bid' => $branchId,
            ':pid' => $productId,
            ':d' => $date,
            ':shift' => $shift,
            ':price' => $currentPrice,
            ':val' => $value,
            ':uid' => $userId,
            ':uid2' => $userId,
            ':val2' => $value,
            ':uid3' => $userId,
        ]);

        if ($field !== 'sales') {
            dl_recomputeSales($branchId, $productId, $date, $userId, $shift);
        }
        dl_recomputeVariancesForDay($branchId, $date);

        $oldAudit = $oldVal !== false ? ($oldVal !== null ? (int)$oldVal : null) : null;
        dl_auditLog(
            'field_update',
            $branchId,
            'dl_daily_ledger',
            "{$branchId}-{$productId}-{$date}-{$shift}",
            [$field => $oldAudit],
            [$field => $value]
        );

        if (!$inTx) {
            $ctx->db()->commit();
        }

        return ['ok' => true, 'field' => $field, 'value' => $value];
    } catch (\Throwable $e) {
        if (!$inTx && $ctx->db()->inTransaction()) {
            $ctx->db()->rollBack();
        }
        if ($e instanceof RuntimeException) {
            throw $e;
        }
        throw new RuntimeException('Save failed', 500);
    }
}

/**
 * Cashier stock adjustment / paper-DR withdrawal, mirroring
 * apiSaveCashierWithdrawals (incl. pullout-return-to-commissary).
 *
 * @throws RuntimeException with an HTTP status code on failure
 */
function dl_offlineApplyWithdrawal(array $user, array $op, bool $inTx = false): array
{
    $ctx = module();
    if (!$ctx) {
        throw new RuntimeException('Module context unavailable', 500);
    }

    $input = is_array($op['payload'] ?? null) ? $op['payload'] : [];
    $idempotencyKey = trim((string)($input['idempotency_key'] ?? ''));
    if ($idempotencyKey !== '') {
        // A retry of an already-applied withdrawal (same key) is a replay, not a
        // new submission — return the original response instead of double-applying.
        $cached = dl_loadIdempotentResponse('cashier_withdrawal', $idempotencyKey);
        if ($cached !== null) {
            return $cached;
        }
    }
    $authResult = dl_authorizeBranch($user, $input);
    if ($authResult['branch_id'] < 0) {
        throw new RuntimeException('Branch not authorized', 403);
    }
    $branchId = $authResult['branch_id'];
    if ($branchId <= 0) {
        throw new RuntimeException('Unable to resolve branch. Verify your branch assignment.', 422);
    }
    $date = (string)($input['date'] ?? date('Y-m-d'));
    $shiftResolved = dl_resolveLedgerShift($user, $input);
    $shift = $shiftResolved['shift'];
    $header = is_array($input['header'] ?? null) ? $input['header'] : [];
    $lines = is_array($input['lines'] ?? null) ? $input['lines'] : [];

    $type = (string)($header['withdrawal_type'] ?? 'charge');
    if (!in_array($type, ['charge', 'pullout', 'adjustment_add'], true)) {
        throw new RuntimeException('Invalid withdrawal type', 422);
    }
    $drNumber = !empty($header['dr_number']) ? (string)$header['dr_number'] : null;
    $targetBranchId = !empty($header['target_branch_id']) ? (int)$header['target_branch_id'] : null;
    $reasonCode = !empty($header['reason_code']) ? (string)$header['reason_code'] : null;
    $customReason = trim((string)($header['custom_reason'] ?? ''));
    $liableUserId = !empty($header['liable_user_id']) ? (int)$header['liable_user_id'] : null;
    $allowedReasons = ['spoilage', 'staff_meal', 'sampling', 'testing', 'promo', 'donation', 'damage', 'manual_adjustment', 'other'];
    if ($reasonCode !== null && !in_array($reasonCode, $allowedReasons, true)) {
        throw new RuntimeException('Invalid reason_code', 422);
    }
    if (in_array($type, ['charge', 'pullout', 'adjustment_add'], true) && $reasonCode === null) {
        $reasonCode = 'manual_adjustment';
    }
    if ($reasonCode === 'other' && $customReason === '') {
        throw new RuntimeException('A custom reason is required when reason is Other.', 422);
    }
    if ($customReason !== '' && mb_strlen($customReason) > 255) {
        throw new RuntimeException('Custom reason must be 255 characters or fewer.', 422);
    }
    if ($reasonCode !== 'other') {
        $customReason = '';
    }
    if ($type === 'adjustment_add' && $liableUserId === null) {
        throw new RuntimeException('adjustment_add requires a liable_user_id (charge to person).', 422);
    }

    $validLines = [];
    foreach ($lines as $l) {
        $pid = isset($l['product_id']) ? (int)$l['product_id'] : 0;
        $qty = isset($l['quantity']) ? max(0, (int)$l['quantity']) : 0;
        if ($pid > 0 && $qty > 0) {
            $validLines[] = ['product_id' => $pid, 'quantity' => $qty];
        }
    }
    if (count($validLines) === 0) {
        throw new RuntimeException('Add at least one product with a quantity greater than 0.', 422);
    }

    $role = (string)($user['role'] ?? '');
    $dayStatus = dl_getDayStatus($branchId, $date);
    if ($role === 'cashier' && !dl_cashierMayEdit($branchId, $date, $shift, dl_businessDate(), $dayStatus)) {
        throw new RuntimeException('Reference only', 403);
    }
    if ($dayStatus === 'closed' && $role === 'cashier') {
        throw new RuntimeException('Day is closed', 403);
    }

    $userId = dl_getActorUserId($user);
    $actorId = $userId;
    $totals = [];
    $returnDeliveryId = null;
    $returnReceivingId = null;

    if (!$inTx) {
        $ctx->db()->beginTransaction();
    }
    try {
        dl_assertShiftMutable($ctx->db(), $branchId, $date, $shift);
        $stmtIns = $ctx->db()->prepare(
            'INSERT INTO dl_cashier_withdrawals (branch_id, product_id, ledger_date, withdrawal_type, reason_code, custom_reason, dr_number, target_branch_id, quantity, encoded_by, liable_user_id, dedup_hash)
             VALUES (:bid, :pid, :d, :typ, :rc, :crc, :dr, :tbid, :qty, :uid, :luid, :dedup)'
        );
        $stmtSum = $ctx->db()->prepare(
            'SELECT COALESCE(SUM(quantity), 0) FROM dl_cashier_withdrawals
             WHERE branch_id = :bid AND product_id = :pid AND ledger_date = :d AND withdrawal_type <> :excludeType'
        );
        $stmtCheck = $ctx->db()->prepare(
            'SELECT id FROM dl_daily_ledger WHERE branch_id = :bid AND product_id = :pid AND ledger_date = :d AND shift = :shift FOR UPDATE'
        );
        $isAddtl = ($type === 'adjustment_add');
        if ($isAddtl) {
            $stmtUpd = $ctx->db()->prepare(
                'UPDATE dl_daily_ledger SET addtl = addtl + :qty, updated_by = :uid
                 WHERE branch_id = :bid AND product_id = :pid AND ledger_date = :d AND shift = :shift'
            );
            $stmtInit = $ctx->db()->prepare(
                'INSERT INTO dl_daily_ledger (branch_id, product_id, ledger_date, shift, price_snapshot, addtl, encoded_by, updated_by)
                 VALUES (:bid, :pid, :d, :shift, :prc, :qty, :uid_enc, :uid_upd)'
            );
        } else {
            $stmtUpd = $ctx->db()->prepare(
                'UPDATE dl_daily_ledger SET withdraw = :wdr, updated_by = :uid
                 WHERE branch_id = :bid AND product_id = :pid AND ledger_date = :d AND shift = :shift'
            );
            $stmtInit = $ctx->db()->prepare(
                'INSERT INTO dl_daily_ledger (branch_id, product_id, ledger_date, shift, price_snapshot, withdraw, encoded_by, updated_by)
                 VALUES (:bid, :pid, :d, :shift, :prc, :wdr, :uid_enc, :uid_upd)'
            );
        }

        foreach ($validLines as $line) {
            $pid = $line['product_id'];
            $qty = $line['quantity'];

            $dedupHash = dl_withdrawalDedupHash(
                $branchId, $pid, $date, $type,
                $reasonCode,
                $customReason !== '' ? $customReason : null,
                $drNumber,
                $targetBranchId,
                $qty,
                $liableUserId
            );

            try {
                $stmtIns->execute([
                    ':bid' => $branchId,
                    ':pid' => $pid,
                    ':d' => $date,
                    ':typ' => $type,
                    ':rc' => $reasonCode,
                    ':crc' => $customReason !== '' ? $customReason : null,
                    ':dr' => $drNumber,
                    ':tbid' => $targetBranchId,
                    ':qty' => $qty,
                    ':uid' => $userId,
                    ':luid' => $liableUserId,
                    ':dedup' => $dedupHash,
                ]);
            } catch (\PDOException $e) {
                if (dl_isDuplicateKeyError($e)) {
                    // DB-level dedup guard: identical line already recorded.
                    // Propagate as a 409 conflict — the sync loop records a
                    // deterministic 'conflict' receipt (quarantine, no retry).
                    throw new DlDuplicateWithdrawalException();
                }
                throw $e;
            }

            if ($isAddtl) {
                $stmtCheck->execute([':bid' => $branchId, ':pid' => $pid, ':d' => $date, ':shift' => $shift]);
                if ($stmtCheck->fetch()) {
                    $stmtUpd->execute([':qty' => $qty, ':uid' => $userId, ':bid' => $branchId, ':pid' => $pid, ':d' => $date, ':shift' => $shift]);
                } else {
                    $price = dl_resolveBranchProductPrice($branchId, $pid, $date);
                    $stmtInit->execute([':bid' => $branchId, ':pid' => $pid, ':d' => $date, ':shift' => $shift, ':prc' => $price, ':qty' => $qty, ':uid_enc' => $userId, ':uid_upd' => $userId]);
                }
                $totals[] = ['product_id' => $pid, 'addtl' => $qty];
            } else {
                $stmtSum->execute([':bid' => $branchId, ':pid' => $pid, ':d' => $date, ':excludeType' => 'adjustment_add']);
                $newTotal = (int)$stmtSum->fetchColumn();
                $stmtCheck->execute([':bid' => $branchId, ':pid' => $pid, ':d' => $date, ':shift' => $shift]);
                if ($stmtCheck->fetch()) {
                    $stmtUpd->execute([':wdr' => $newTotal, ':uid' => $userId, ':bid' => $branchId, ':pid' => $pid, ':d' => $date, ':shift' => $shift]);
                } else {
                    $price = dl_resolveBranchProductPrice($branchId, $pid, $date);
                    $stmtInit->execute([':bid' => $branchId, ':pid' => $pid, ':d' => $date, ':shift' => $shift, ':prc' => $price, ':wdr' => $newTotal, ':uid_enc' => $userId, ':uid_upd' => $userId]);
                }
                $totals[] = ['product_id' => $pid, 'total' => $newTotal];
            }
        }

        if ($type === 'pullout' && $targetBranchId !== null && dl_isFormalDeliveryEnabled()) {
            $commissaryCheck = $ctx->db()->prepare(
                'SELECT id, name FROM dl_branches WHERE id = :id AND is_commissary = 1 AND is_active = 1 LIMIT 1'
            );
            $commissaryCheck->execute([':id' => $targetBranchId]);
            $commissary = $commissaryCheck->fetch(PDO::FETCH_ASSOC);
            if ($commissary) {
                $effectiveUserId = $actorId > 0 ? $actorId : null;
                $returnDr = '[pullout-return-' . $date . '-' . $branchId . '-' . date('His') . ']';

                if ($branchId !== $targetBranchId) {
                    $priceGroupId = dl_defaultPriceGroupId();
                    $delIns = $ctx->db()->prepare(
                        'INSERT INTO dl_deliveries
                            (origin_type, origin_id, destination_type, destination_id, dr_number,
                             delivery_date, status, created_by, posted_by, posted_at, remarks)
                         VALUES (:ot, :oid, :dt, :did, :dr, :dd, "posted", :uid1, :uid2, NOW(), :remarks)'
                    );
                    $delIns->execute([
                        ':ot' => 'branch',
                        ':oid' => $branchId,
                        ':dt' => 'branch',
                        ':did' => $targetBranchId,
                        ':dr' => $returnDr,
                        ':dd' => $date,
                        ':uid1' => $effectiveUserId,
                        ':uid2' => $effectiveUserId,
                        ':remarks' => '[cashier-pullout-return]',
                    ]);
                    $returnDeliveryId = (int)$ctx->db()->lastInsertId();

                    $itemIns = $ctx->db()->prepare(
                        'INSERT INTO dl_delivery_items
                            (delivery_id, product_id, quantity, unit, unit_cost_snapshot, price_snapshot, price_group_id, remarks)
                         VALUES (:did, :pid, :qty, :unit, :cost, :price, :pg, :remarks)'
                    );
                    foreach ($validLines as $line) {
                        $itemIns->execute([
                            ':did' => $returnDeliveryId,
                            ':pid' => $line['product_id'],
                            ':qty' => $line['quantity'],
                            ':unit' => 'pcs',
                            ':cost' => 0,
                            ':price' => dl_resolveProductPrice((int)$line['product_id'], $priceGroupId, $date),
                            ':pg' => $priceGroupId,
                            ':remarks' => 'pullout_return:' . $branchId,
                        ]);
                    }
                    $returnReceivingId = dl_acceptFormalDelivery($ctx->db(), $targetBranchId, $returnDeliveryId, $actorId, $date, null, $shift);
                }

                $unsaleableReasons = ['spoilage', 'damage', 'staff_meal', 'sampling', 'testing', 'promo', 'donation'];
                $isSaleableReturn = $reasonCode === null || !in_array($reasonCode, $unsaleableReasons, true);
                foreach ($validLines as $line) {
                    $pid = (int)$line['product_id'];
                    $qty = (int)$line['quantity'];
                    if ($isSaleableReturn) {
                        dl_applyCommissaryProductLedgerDelta($ctx->db(), $targetBranchId, $pid, $date, $qty, 0, $actorId, 0);
                    } else {
                        dl_applyCommissaryProductLedgerDelta($ctx->db(), $targetBranchId, $pid, $date, 0, 0, $actorId, $qty);
                    }
                }

                dl_auditLog('create_delivery', $branchId, 'dl_deliveries', (string)($returnDeliveryId ?? 0), null, [
                    'dr_number' => $returnDr ?? '[self-managed-no-delivery]',
                    'status' => $returnDeliveryId ? 'posted' : 'ledger-only',
                    'source' => 'cashier_pullout_return',
                    'destination_commissary_id' => $targetBranchId,
                    'saleable' => $isSaleableReturn,
                    'items' => count($validLines),
                ]);
            }
        }

        dl_auditLog('withdrawal', $branchId, 'dl_cashier_withdrawals', "{$date}-{$shift}", null, [
            'withdrawal_type' => $type,
            'reason_code' => $reasonCode,
            'custom_reason' => $customReason !== '' ? $customReason : null,
            'dr_number' => $drNumber,
            'liable_user_id' => $liableUserId,
            'lines' => $totals,
        ]);

        if (!$inTx) {
            $ctx->db()->commit();
        }

        dl_recomputeVariancesForDay($branchId, $date);

        $response = ['ok' => true, 'totals' => $totals];
        if ($returnDeliveryId !== null) {
            $response['delivery_id'] = $returnDeliveryId;
            $response['receiving_id'] = $returnReceivingId;
        }
        if ($idempotencyKey !== '') {
            dl_storeIdempotentResponse('cashier_withdrawal', $idempotencyKey, $response, 86400);
        }

        return $response;
    } catch (\Throwable $e) {
        if (!$inTx && $ctx->db()->inTransaction()) {
            $ctx->db()->rollBack();
        }
        if ($e instanceof RuntimeException) {
            throw $e;
        }
        throw new RuntimeException('Database error', 500);
    }
}

/**
 * Paper-DR receive, mirroring apiReceivePaperDelivery exactly.
 *
 * @throws RuntimeException with an HTTP status code on failure
 */
function dl_offlineApplyReceivePaperDr(array $user, array $op, bool $inTx = false): array
{
    $ctx = module();
    if (!$ctx) {
        throw new RuntimeException('Module context unavailable', 500);
    }

    $input = is_array($op['payload'] ?? null) ? $op['payload'] : [];
    $authResult = dl_authorizeBranch($user, $input);
    if ($authResult['branch_id'] < 0) {
        throw new RuntimeException('Branch not authorized', 403);
    }
    $destinationBranchId = $authResult['branch_id'];
    $shiftResolved = dl_resolveLedgerShift($user, $input);
    $shift = $shiftResolved['shift'];
    $originType = (string)($input['origin_type'] ?? 'commissary');
    $originId = !empty($input['origin_id']) ? (int)$input['origin_id'] : null;
    $drNumber = trim((string)($input['dr_number'] ?? ''));
    $deliveryDate = (string)($input['delivery_date'] ?? dl_businessDate());
    $receiveDate = (string)($input['receive_date'] ?? dl_businessDate());
    $items = dl_normalizeDeliveryItems(is_array($input['items'] ?? null) ? $input['items'] : []);
    $actorId = dl_getActorUserId($user);
    $role = (string)($user['role'] ?? '');
    $isAdminUser = $role === 'admin' || dl_isKernelAdmin($user);

    if ($destinationBranchId <= 0) {
        throw new RuntimeException('Missing destination branch.', 422);
    }
    if (!in_array($originType, ['branch', 'commissary'], true)) {
        throw new RuntimeException('Invalid origin type.', 422);
    }
    if ($originType === 'branch' && (($originId ?? 0) <= 0 || $originId === $destinationBranchId)) {
        throw new RuntimeException('A different source branch is required.', 422);
    }
    if ($drNumber === '') {
        throw new RuntimeException('Paper DR number is required.', 422);
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $deliveryDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $receiveDate)) {
        throw new RuntimeException('Invalid date.', 422);
    }
    if ($items === []) {
        throw new RuntimeException('At least one item is required.', 422);
    }

    $businessDate = dl_businessDate();
    if ($role === 'cashier' && $receiveDate !== $businessDate) {
        throw new RuntimeException('Reference only', 403);
    }
    if ($originType === 'branch' && !$isAdminUser && $deliveryDate !== $businessDate) {
        throw new RuntimeException('Admin required for late branch paper DR capture', 403);
    }

    $receiveDayStatus = dl_getDayStatus($destinationBranchId, $receiveDate);
    if ($receiveDayStatus === 'closed' && !dl_roleHasPermission($role, 'ledger.override')) {
        throw new RuntimeException('Day is closed', 403);
    }
    if ($originType === 'branch' && $originId !== null) {
        $originDayStatus = dl_getDayStatus((int)$originId, $deliveryDate);
        if ($originDayStatus === 'closed' && !$isAdminUser) {
            throw new RuntimeException('Admin required for closed source-branch paper DR capture', 403);
        }
    }

    $findStmt = $ctx->db()->prepare(
        'SELECT id, status
           FROM dl_deliveries
          WHERE destination_type = :destination_type
            AND destination_id = :destination_id
            AND dr_number = :dr_number
            AND status <> "voided"
          ORDER BY id DESC
          LIMIT 1'
    );
    $findStmt->execute([
        ':destination_type' => 'branch',
        ':destination_id' => $destinationBranchId,
        ':dr_number' => $drNumber,
    ]);
    $existing = $findStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if (!$inTx) {
        $ctx->db()->beginTransaction();
    }
    try {
        if ($existing && dl_deliveryHasActiveReceivings($ctx->db(), (int)$existing['id'])) {
            throw new RuntimeException('This paper DR was already received.', 409);
        }

        $deliveryId = $existing ? (int)$existing['id'] : 0;
        if (!$existing) {
            $priceGroupId = dl_defaultPriceGroupId();
            $ins = $ctx->db()->prepare(
                'INSERT INTO dl_deliveries
                    (origin_type, origin_id, destination_type, destination_id, dr_number,
                     delivery_date, status, created_by, posted_by, posted_at, remarks, provenance_status)
                 VALUES (:ot, :oid, :dt, :did, :dr, :dd, "posted", :created_by, :posted_by, NOW(), :remarks, :provenance_status)'
            );
            $ins->execute([
                ':ot' => $originType,
                ':oid' => $originId,
                ':dt' => 'branch',
                ':did' => $destinationBranchId,
                ':dr' => $drNumber,
                ':dd' => $deliveryDate,
                ':created_by' => $actorId ?: null,
                ':posted_by' => $actorId ?: null,
                ':remarks' => dl_paperDrCaptureRemark(),
                ':provenance_status' => 'paper_dr_pending',
            ]);
            $deliveryId = (int)$ctx->db()->lastInsertId();

            $itemStmt = $ctx->db()->prepare(
                'INSERT INTO dl_delivery_items
                    (delivery_id, product_id, quantity, unit, unit_cost_snapshot, price_snapshot, price_group_id, remarks)
                 VALUES (:delivery_id, :product_id, :quantity, :unit, :unit_cost_snapshot, :price_snapshot, :price_group_id, :remarks)'
            );
            foreach ($items as $item) {
                $itemStmt->execute([
                    ':delivery_id' => $deliveryId,
                    ':product_id' => $item['product_id'],
                    ':quantity' => $item['quantity'],
                    ':unit' => $item['unit'],
                    ':unit_cost_snapshot' => $item['unit_cost_snapshot'],
                    ':price_snapshot' => dl_resolveProductPrice((int)$item['product_id'], $priceGroupId, $deliveryDate),
                    ':price_group_id' => $priceGroupId,
                    ':remarks' => $item['remarks'],
                ]);
                if ($originType === 'branch' && $originId !== null) {
                    dl_applyLedgerDelta((int)$originId, (int)$item['product_id'], $deliveryDate, (int)$item['quantity'], $actorId, 'withdraw', $shift);
                }
            }

            dl_auditLog('create_delivery', $originType === 'branch' ? (int)$originId : null, 'dl_deliveries', (string)$deliveryId, null, [
                'destination_type' => 'branch',
                'destination_id' => $destinationBranchId,
                'items' => count($items),
                'dr_number' => $drNumber,
                'status' => 'posted',
                'source' => 'captured_from_paper_dr',
            ]);
        } elseif ((string)$existing['status'] === 'draft') {
            $ctx->db()->prepare(
                'UPDATE dl_deliveries SET status = "posted", posted_by = :u, posted_at = NOW() WHERE id = :id'
            )->execute([':u' => $actorId ?: null, ':id' => $deliveryId]);
        }

        $receivingId = dl_acceptFormalDelivery($ctx->db(), $destinationBranchId, $deliveryId, $actorId, $receiveDate, null, $shift);
        if (!$inTx) {
            $ctx->db()->commit();
        }

        return ['ok' => true, 'delivery_id' => $deliveryId, 'receiving_id' => $receivingId];
    } catch (\Throwable $e) {
        if (!$inTx && $ctx->db()->inTransaction()) {
            $ctx->db()->rollBack();
        }
        if ($e instanceof RuntimeException) {
            throw $e;
        }
        throw new RuntimeException($e->getMessage(), 400);
    }
}

function dl_offlineRecordReceipt(string $enrollmentId, string $clientOpId, string $operationType, string $status, array $result): void
{
    $ctx = module();
    if (!$ctx) {
        return;
    }
    $tenantScope = (string)(app()->tenant()->current() ?? '');
    $stmt = $ctx->db()->prepare(
        'INSERT INTO dl_offline_sync_receipts (tenant_scope, enrollment_id, client_op_id, operation_type, status, result_json, applied_at)
         VALUES (:ts, :eid, :coid, :otype, :status, :result, CURRENT_TIMESTAMP)'
    );
    $stmt->execute([
        ':ts' => $tenantScope,
        ':eid' => $enrollmentId,
        ':coid' => $clientOpId,
        ':otype' => $operationType,
        ':status' => $status,
        ':result' => json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ]);
}

function dl_offlineLoadReceipt(string $enrollmentId, string $clientOpId): ?array
{
    $ctx = module();
    if (!$ctx) {
        return null;
    }
    $stmt = $ctx->db()->prepare(
        'SELECT operation_type, status, result_json, applied_at FROM dl_offline_sync_receipts
         WHERE enrollment_id = :eid AND client_op_id = :coid LIMIT 1'
    );
    $stmt->execute([':eid' => $enrollmentId, ':coid' => $clientOpId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row) || $row === []) {
        return null;
    }

    $result = json_decode((string)$row['result_json'], true);
    return [
        'operation_type' => (string)$row['operation_type'],
        'status' => (string)$row['status'],
        'result' => is_array($result) ? $result : ['ok' => true],
        'applied_at' => (string)$row['applied_at'],
    ];
}

/**
 * Persists a client-reported (non-decrypting) pending-work marker on the
 * enrollment row. This is a VISIBILITY signal only — the server DB stays the
 * single source of truth and never trusts it as a gate. The client counts its
 * own plaintext operation records (state=en 'pending') — ledger values, PINs,
 * and keys never leave the device.
 */
function dl_offlineRecordPendingReport(array $row, int $count, ?string $since = null, ?string $fields = null): void
{
    $ctx = module();
    if (!$ctx || (int)($row['id'] ?? 0) <= 0) {
        return;
    }
    $count = max(0, min(9999, $count));
    $since = (string)$since;
    if ($since !== '' && preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}/', $since)) {
        $since = substr($since, 0, 19);
    } else {
        $since = null; // empty/unknown → NULL so MySQL never sees '' for a DATETIME
    }
    $fields = ($fields !== null && $fields !== '') ? substr((string)$fields, 0, 255) : null;

    $stmt = $ctx->db()->prepare(
        'UPDATE dl_offline_device_enrollments
            SET last_reported_pending_count = :cnt,
                pending_since = :since,
                pending_fields = :fields,
                updated_at = CURRENT_TIMESTAMP
          WHERE id = :id'
    );
    $stmt->execute([
        ':cnt' => $count,
        ':since' => $since,
        ':fields' => $fields,
        ':id' => (int)$row['id'],
    ]);
}

/**
 * Active enrollments (scoped to the actor's accessible branches) that last
 * reported unsynced work. Used by the admin dashboard to surface devices that
 * still hold data which has not reached the cloud, so "the cashier entered it"
 * is always actionable instead of silently stuck.
 */
function dl_offlineUnsyncedDevices(array $user, int $limit = 20): array
{
    $ctx = module();
    if (!$ctx) {
        return [];
    }
    $accessible = dl_accessibleBranchIds($user);
    if (count($accessible) === 0) {
        $accessible = [0];
    }
    $placeholders = implode(',', array_fill(0, count($accessible), '?'));
    $stmt = $ctx->db()->prepare(
        "SELECT e.id, e.enrollment_id, e.device_id, e.actor_user_id, e.branch_id,
                e.last_reported_pending_count, e.pending_since, e.pending_fields,
                e.last_sync_at, e.status, e.shift, e.expires_at,
                b.name AS branch_name,
                COALESCE(NULLIF(u.full_name, ''), u.username, CONCAT('User #', e.actor_user_id)) AS cashier_name
           FROM dl_offline_device_enrollments e
           LEFT JOIN dl_branches b ON b.id = e.branch_id
           LEFT JOIN dl_users u ON u.id = e.actor_user_id
          WHERE e.status = 'active'
            AND e.branch_id IN ({$placeholders})
            AND e.last_reported_pending_count > 0
          ORDER BY e.last_reported_pending_count DESC, e.pending_since ASC
          LIMIT " . (int)$limit
    );
    $stmt->execute($accessible);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

// ─── API handlers ─────────────────────────────────────────────────────

/**
 * POST /daily-ledger/api/v1/offline/enroll
 * Authenticated. One round trip returns the enrollment descriptor + bounded
 * bootstrap so the client can atomically activate the vault and reach
 * verified "Offline ready" without a manual reload.
 */
function apiOfflineEnroll(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        dlJson(['ok' => false, 'error' => 'Module context unavailable'], 500);
        return;
    }

    $user = dlCurrentUser(['cashier', 'supervisor', 'admin']);
    $input = $ctx->input();
    $deviceId = dl_offlineNormalizeDeviceId($input['device_id'] ?? '');
    if ($deviceId === '') {
        dlJson(['ok' => false, 'error' => 'A valid device_id is required.'], 422);
        return;
    }

    $authResult = dl_authorizeBranch($user, $input);
    if ($authResult['branch_id'] < 0 || $authResult['branch_id'] <= 0) {
        dlJson(['ok' => false, 'error' => 'No authorized branch to enroll.'], 403);
        return;
    }
    $branchId = $authResult['branch_id'];
    $shiftResolved = dl_resolveLedgerShift($user, $input);
    $shift = $shiftResolved['shift'];

    try {
        // Idempotent re-enrollment: if an active enrollment already exists for
        // this device+actor+tenant, return it (grant extension is handled by
        // issuing a fresh grant version).
        $existing = dl_offlineFindEnrollment($user, (string)($input['enrollment_id'] ?? ''), $deviceId);
        if ($existing === null) {
            $stmt = $ctx->db()->prepare(
                'SELECT * FROM dl_offline_device_enrollments
                 WHERE tenant_scope = :ts AND device_hash = :dh AND actor_user_id = :uid AND status = "active"
                 ORDER BY id DESC LIMIT 1'
            );
            $stmt->execute([
                ':ts' => (string)(app()->tenant()->current() ?? ''),
                ':dh' => dl_offlineDeviceHash((string)(app()->tenant()->current() ?? ''), $deviceId),
                ':uid' => dl_getActorUserId($user),
            ]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($row) && $row !== [] && (int)($row['branch_id'] ?? 0) === $branchId) {
                $existing = $row;
            }
        }

        if ($existing !== null) {
            $row = $existing;
        } else {
            $row = dl_offlineInsertEnrollment($user, $deviceId, $branchId, $shift, $shiftResolved['bound']);
        }
    } catch (Throwable $e) {
        write_log('daily-ledger offline enroll failed', 'error', [
            'message' => $e->getMessage(),
            'actor_role' => (string)($user['role'] ?? ''),
            'actor_source' => (string)($user['source'] ?? ''),
        ]);
        dlJson(['ok' => false, 'error' => 'Enrollment failed: ' . $e->getMessage()], 500);
        return;
    }

    dl_auditLog('offline_enroll', $branchId, 'dl_offline_device_enrollments', (string)$row['enrollment_id'], null, [
        'enrollment_id' => (string)$row['enrollment_id'],
        'branch_id' => $branchId,
        'device_hash_prefix' => substr((string)$row['device_hash'], 0, 12),
        'expires_at' => (string)$row['expires_at'],
        'role' => (string)($user['role'] ?? ''),
        'source' => (string)($user['source'] ?? ''),
    ]);

    dlJson([
        'ok' => true,
        'enrollment' => dl_offlineEnrollmentDescriptor($row),
        'bootstrap' => dl_offlineBootstrapPayload($user, $branchId, $shift, $shiftResolved['bound']),
    ]);
}

/**
 * GET /daily-ledger/api/v1/offline/bootstrap
 * Authenticated. Refreshes the bounded bootstrap (e.g. after a reconnect
 * validates the enrollment, before a sync, or after a schema bump).
 */
function apiOfflineBootstrap(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        dlJson(['ok' => false, 'error' => 'Module context unavailable'], 500);
        return;
    }

    $user = dlCurrentUser(['cashier', 'supervisor', 'admin']);
    $input = $ctx->input();
    $deviceId = dl_offlineNormalizeDeviceId($input['device_id'] ?? '');
    $enrollmentId = trim((string)($input['enrollment_id'] ?? ''));
    if ($deviceId === '' || $enrollmentId === '') {
        dlJson(['ok' => false, 'error' => 'enrollment_id and device_id are required.'], 422);
        return;
    }

    $row = dl_offlineFindEnrollment($user, $enrollmentId, $deviceId);
    if ($row === null) {
        dlJson(['ok' => false, 'error' => 'Offline enrollment not found.'], 404);
        return;
    }
    $valid = dl_offlineValidateEnrollment($user, $row);
    if (!$valid['ok']) {
        dlJson(['ok' => false, 'error' => $valid['error'], 'reason' => $valid['reason']], 403);
        return;
    }

    $shiftResolved = dl_resolveLedgerShift($user, $input);
    dlJson([
        'ok' => true,
        'enrollment' => dl_offlineEnrollmentDescriptor($row),
        'bootstrap' => dl_offlineBootstrapPayload($user, (int)$row['branch_id'], $shiftResolved['shift'], $shiftResolved['bound']),
    ]);
}

/**
 * GET /daily-ledger/api/v1/offline/status
 * Authenticated. Used at next contact to detect expiry/revocation before any
 * sync; returns the enrollment descriptor + latest server versions.
 */
function apiOfflineStatus(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        dlJson(['ok' => false, 'error' => 'Module context unavailable'], 500);
        return;
    }

    $user = dlCurrentUser(['cashier', 'supervisor', 'admin']);
    $input = $ctx->input();
    $deviceId = dl_offlineNormalizeDeviceId($input['device_id'] ?? '');
    $enrollmentId = trim((string)($input['enrollment_id'] ?? ''));
    if ($deviceId === '' || $enrollmentId === '') {
        dlJson(['ok' => false, 'error' => 'enrollment_id and device_id are required.'], 422);
        return;
    }

    $row = dl_offlineFindEnrollment($user, $enrollmentId, $deviceId);
    if ($row === null) {
        dlJson(['ok' => false, 'error' => 'Offline enrollment not found.', 'reason' => 'not-found'], 404);
        return;
    }
    $valid = dl_offlineValidateEnrollment($user, $row);
    if (!$valid['ok']) {
        dlJson(['ok' => false, 'error' => $valid['error'], 'reason' => $valid['reason']], 403);
        return;
    }

    // Visibility marker: persist the client-reported non-decrypting pending
    // summary so admins can see devices that still hold unsynced work.
    if (isset($input['pending_count'])) {
        dl_offlineRecordPendingReport(
            $row,
            (int)$input['pending_count'],
            (string)($input['pending_since'] ?? ''),
            (string)($input['pending_fields'] ?? '')
        );
    }

    dlJson([
        'ok' => true,
        'enrollment' => dl_offlineEnrollmentDescriptor($row),
        'server_versions' => [
            'schema_version' => dl_offlineSchemaVersion(),
            'bootstrap_version' => dl_offlineBootstrapVersion(),
            'grant_version' => dl_offlineGrantVersion(),
        ],
        'server_time' => date('c'),
    ]);
}

/**
 * POST /daily-ledger/api/v1/offline/revoke
 * Authenticated. Revokes the enrollment for this device (used by
 * "Remove offline access"). Pending work is NOT silently deleted — the
 * client is expected to surface a warning before calling this.
 */
function apiOfflineRevoke(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        dlJson(['ok' => false, 'error' => 'Module context unavailable'], 500);
        return;
    }

    $user = dlCurrentUser(['cashier', 'supervisor', 'admin']);
    $input = $ctx->input();
    $deviceId = dl_offlineNormalizeDeviceId($input['device_id'] ?? '');
    $enrollmentId = trim((string)($input['enrollment_id'] ?? ''));
    if ($deviceId === '' || $enrollmentId === '') {
        dlJson(['ok' => false, 'error' => 'enrollment_id and device_id are required.'], 422);
        return;
    }

    $row = dl_offlineFindEnrollment($user, $enrollmentId, $deviceId);
    if ($row === null) {
        dlJson(['ok' => false, 'error' => 'Offline enrollment not found.'], 404);
        return;
    }

    $actorId = dl_getActorUserId($user);
    $stmt = $ctx->db()->prepare(
        'UPDATE dl_offline_device_enrollments
            SET status = "revoked", revoked_at = CURRENT_TIMESTAMP, revoked_by_user_id = :uid, revoked_reason = :reason, updated_at = CURRENT_TIMESTAMP
          WHERE id = :id'
    );
    $stmt->execute([
        ':uid' => $actorId > 0 ? $actorId : null,
        ':reason' => 'device_removal',
        ':id' => (int)$row['id'],
    ]);

    dl_auditLog('offline_revoke', (int)$row['branch_id'], 'dl_offline_device_enrollments', (string)$row['enrollment_id'], null, [
        'enrollment_id' => (string)$row['enrollment_id'],
        'branch_id' => (int)$row['branch_id'],
        'device_hash_prefix' => substr((string)$row['device_hash'], 0, 12),
        'role' => (string)($user['role'] ?? ''),
        'source' => (string)($user['source'] ?? ''),
    ]);

    dlJson(['ok' => true, 'revoked' => true, 'enrollment_id' => $enrollmentId]);
}

/**
 * POST /daily-ledger/api/v1/offline/reconcile
 * Authenticated single-flight sync. Validates the enrollment (expiry /
 * revocation / scope / device) then processes an ordered batch of encrypted
 * operations. Each op is idempotent by client_op_id; a previously applied op
 * returns its stored result with duplicate=true and is never re-applied.
 * Deterministic rejections are recorded as receipts (state: rejected) and
 * never retried blindly; transport failures return no receipt so the client
 * retries with bounded backoff.
 */
function apiOfflineReconcile(array $params = []): void
{
    $ctx = module();
    if (!$ctx) {
        dlJson(['ok' => false, 'error' => 'Module context unavailable'], 500);
        return;
    }

    $user = dlCurrentUser(['cashier', 'supervisor', 'admin']);
    $input = $ctx->input();
    $deviceId = dl_offlineNormalizeDeviceId($input['device_id'] ?? '');
    $enrollmentId = trim((string)($input['enrollment_id'] ?? ''));
    if ($deviceId === '' || $enrollmentId === '') {
        dlJson(['ok' => false, 'error' => 'enrollment_id and device_id are required.'], 422);
        return;
    }

    $row = dl_offlineFindEnrollment($user, $enrollmentId, $deviceId);
    if ($row === null) {
        dlJson(['ok' => false, 'error' => 'Offline enrollment not found.', 'reason' => 'not-found'], 404);
        return;
    }
    $valid = dl_offlineValidateEnrollment($user, $row);
    if (!$valid['ok']) {
        dlJson(['ok' => false, 'error' => $valid['error'], 'reason' => $valid['reason']], 403);
        return;
    }

    $branchId = (int)$row['branch_id'];
    $operations = is_array($input['operations'] ?? null) ? $input['operations'] : [];
    // Bounded batch: never accept an unbounded replay.
    if (count($operations) > 200) {
        dlJson(['ok' => false, 'error' => 'Batch too large. Sync in smaller batches.'], 422);
        return;
    }

    // Visibility marker: record the client-reported non-decrypting pending
    // summary even if this batch is interrupted, so the admin dashboard can
    // show devices that still hold unsynced work.
    if (isset($input['pending_count'])) {
        dl_offlineRecordPendingReport(
            $row,
            (int)$input['pending_count'],
            (string)($input['pending_since'] ?? ''),
            (string)($input['pending_fields'] ?? '')
        );
    }

    $results = [];
    $failed = false;
    $syncTransactionFailed = false;

    foreach ($operations as $op) {
        $clientOpId = trim((string)($op['client_op_id'] ?? ''));
        $type = (string)($op['type'] ?? '');
        if ($clientOpId === '' || !preg_match('/^[A-Za-z0-9\-_]{8,96}$/', $clientOpId)) {
            $results[] = ['client_op_id' => $clientOpId, 'ok' => false, 'error' => 'Invalid client operation id', 'status' => 'rejected'];
            continue;
        }
        if (!in_array($type, dl_offlineAllowedOperations(), true)) {
            $results[] = ['client_op_id' => $clientOpId, 'ok' => false, 'error' => 'Operation type not allowed offline.', 'status' => 'rejected'];
            continue;
        }

        // Duplicate guard: a previously applied op returns its stored result.
        $receipt = dl_offlineLoadReceipt($enrollmentId, $clientOpId);
        if ($receipt !== null) {
            $results[] = [
                'client_op_id' => $clientOpId,
                'ok' => true,
                'duplicate' => true,
                'status' => $receipt['status'],
                'result' => $receipt['result'],
                'applied_at' => $receipt['applied_at'],
            ];
            continue;
        }

        try {
            // ONE transaction per operation: the operation write and its receipt
            // are atomic. If the receipt insert hits the (enrollment, client_op_id)
            // unique index because a concurrent batch already applied this op, the
            // whole transaction (including the op's writes) rolls back — no double
            // apply — and the stored receipt is returned as a stable duplicate.
            $ctx->db()->beginTransaction();
            try {
                if ($type === 'ledger_save') {
                    $result = dl_offlineApplyLedgerSave($user, $op, true);
                } elseif ($type === 'withdrawal') {
                    $result = dl_offlineApplyWithdrawal($user, $op, true);
                } else {
                    $result = dl_offlineApplyReceivePaperDr($user, $op, true);
                }
                dl_offlineRecordReceipt($enrollmentId, $clientOpId, $type, 'applied', $result);
                $ctx->db()->commit();
            } catch (Throwable $e) {
                if ($ctx->db()->inTransaction()) {
                    $ctx->db()->rollBack();
                }
                // A concurrent batch may have already applied this op between the
                // pre-check above and this commit. Return its stored result.
                $dupReceipt = dl_offlineLoadReceipt($enrollmentId, $clientOpId);
                if ($dupReceipt !== null) {
                    $results[] = [
                        'client_op_id' => $clientOpId,
                        'ok' => true,
                        'duplicate' => true,
                        'status' => $dupReceipt['status'],
                        'result' => $dupReceipt['result'],
                        'applied_at' => $dupReceipt['applied_at'],
                    ];
                    continue;
                }
                throw $e;
            }

            $results[] = ['client_op_id' => $clientOpId, 'ok' => true, 'status' => 'applied', 'result' => $result];
        } catch (RuntimeException $e) {
            $statusCode = (int)$e->getCode();
            $isClientError = $statusCode >= 400 && $statusCode < 500;
            $message = $e->getMessage();
            if ($isClientError) {
                // Deterministic rejection: record a rejected receipt so the op is
                // not retried blindly. Conflict (409) is recorded as conflict.
                $receiptStatus = $statusCode === 409 ? 'conflict' : 'rejected';
                try {
                    $ctx->db()->beginTransaction();
                    dl_offlineRecordReceipt($enrollmentId, $clientOpId, $type, $receiptStatus, ['ok' => false, 'error' => $message]);
                    $ctx->db()->commit();
                } catch (Throwable $e2) {
                    if ($ctx->db()->inTransaction()) {
                        $ctx->db()->rollBack();
                    }
                    $syncTransactionFailed = true;
                    $results[] = ['client_op_id' => $clientOpId, 'ok' => false, 'error' => 'Receipt storage failed.', 'status' => 'server_error'];
                    continue;
                }
                $results[] = ['client_op_id' => $clientOpId, 'ok' => false, 'error' => $message, 'status' => $receiptStatus];
            } else {
                // Transport/server failure: no receipt so the client retries later.
                $failed = true;
                $results[] = ['client_op_id' => $clientOpId, 'ok' => false, 'error' => $message, 'status' => 'server_error'];
                break;
            }
        } catch (Throwable $e) {
            write_log('daily-ledger offline reconcile op failed', 'error', [
                'message' => $e->getMessage(),
                'client_op_id' => $clientOpId,
                'type' => $type,
                'actor_role' => (string)($user['role'] ?? ''),
            ]);
            $failed = true;
            $results[] = ['client_op_id' => $clientOpId, 'ok' => false, 'error' => 'Server error', 'status' => 'server_error'];
            break;
        }
    }

    // Touch last_sync_at only when no transport failure interrupted the batch.
    if (!$failed && !$syncTransactionFailed) {
        $stmt = $ctx->db()->prepare(
            'UPDATE dl_offline_device_enrollments SET last_sync_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
        );
        $stmt->execute([':id' => (int)$row['id']]);
    }

    dl_auditLog('offline_reconcile', $branchId, 'dl_offline_device_enrollments', (string)$row['enrollment_id'], null, [
        'enrollment_id' => (string)$row['enrollment_id'],
        'batch_size' => count($operations),
        'applied' => count(array_filter($results, static fn ($r) => ($r['status'] ?? '') === 'applied')),
        'duplicates' => count(array_filter($results, static fn ($r) => !empty($r['duplicate']))),
        'rejected' => count(array_filter($results, static fn ($r) => ($r['status'] ?? '') === 'rejected' || ($r['status'] ?? '') === 'conflict')),
        'server_errors' => count(array_filter($results, static fn ($r) => ($r['status'] ?? '') === 'server_error')),
        'interrupted' => $failed || $syncTransactionFailed,
    ]);

    dlJson([
        'ok' => true,
        'enrollment_id' => $enrollmentId,
        'results' => $results,
        'interrupted' => $failed || $syncTransactionFailed,
        'server_versions' => [
            'schema_version' => dl_offlineSchemaVersion(),
            'bootstrap_version' => dl_offlineBootstrapVersion(),
            'grant_version' => dl_offlineGrantVersion(),
        ],
        'day_status' => dl_getDayStatus($branchId, dl_businessDate()),
        'server_time' => date('c'),
    ]);
}
