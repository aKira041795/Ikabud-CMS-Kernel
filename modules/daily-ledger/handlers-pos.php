<?php

declare(strict_types=1);

/**
 * Daily Ledger — POS (Point of Sale) service + handlers.
 *
 * Optional, bounded feature. The manual stock-ledger workflow is untouched:
 * POS never writes beg_bal/addtl/withdraw/bal_end and never stores sales in
 * dl_daily_ledger.sales. Money is handled as integer cents (BIGINT) — never
 * binary floats. Completed sales are append-only evidence; corrections are
 * linked void/refund documents.
 *
 * Pure helpers (money math, mode state machine predicates, refund validation,
 * fallback segment math) are DB-free and unit-testable. DB services take the
 * ModuleDB connection explicitly. HTTP handlers stay thin and map domain
 * errors to 403/409/422 instead of generic 500s.
 */

// ═══════════════════════════════════════════════════════════════════════
// Pure money helpers (integer cents)
// ═══════════════════════════════════════════════════════════════════════

/**
 * Parse a decimal money value (string|int|float) into integer cents,
 * rounding half-up. Returns null for non-numeric input.
 */
function dl_pos_toCents($value): ?int
{
    if (is_int($value)) {
        return $value * 100;
    }
    if (is_float($value) || is_string($value)) {
        $normalized = trim((string)$value);
        if ($normalized === '' || !is_numeric($normalized)) {
            return null;
        }
        // round() is decimal-correct at 2dp for values parsed from strings.
        return (int)round(((float)$normalized) * 100, 0, PHP_ROUND_HALF_UP);
    }
    return null;
}

/** Format integer cents as a fixed 2-decimal string ("123.45"). */
function dl_pos_formatCents(int $cents): string
{
    $sign = $cents < 0 ? '-' : '';
    $abs = abs($cents);
    return $sign . intdiv($abs, 100) . '.' . str_pad((string)($abs % 100), 2, '0', STR_PAD_LEFT);
}

/** Integer cents → float for templates (display only; never persisted). */
function dl_pos_centsToFloat(int $cents): float
{
    return $cents / 100;
}

/**
 * Line total in cents: max(0, qty * unit - lineDiscount).
 * Rejects negative quantity or negative unit price via InvalidArgumentException.
 */
function dl_pos_lineTotalCents(int $qty, int $unitPriceCents, int $lineDiscountCents = 0): int
{
    if ($qty <= 0) {
        throw new \InvalidArgumentException('Quantity must be a positive integer.');
    }
    if ($unitPriceCents < 0) {
        throw new \InvalidArgumentException('Unit price cannot be negative.');
    }
    if ($lineDiscountCents < 0) {
        throw new \InvalidArgumentException('Line discount cannot be negative.');
    }
    return max(0, $qty * $unitPriceCents - $lineDiscountCents);
}

/**
 * Order totals in cents from validated lines.
 * Each line: ['quantity'=>int, 'unit_price_cents'=>int, 'line_discount_cents'=>int, 'tax_cents'=>int]
 * Returns ['subtotal','line_discount','order_discount','tax','total'] (all cents).
 */
function dl_pos_orderTotals(array $lines, int $orderDiscountCents = 0): array
{
    if ($lines === []) {
        throw new \InvalidArgumentException('Cart must contain at least one line.');
    }
    if ($orderDiscountCents < 0) {
        throw new \InvalidArgumentException('Order discount cannot be negative.');
    }

    $subtotal = 0;
    $lineDiscount = 0;
    $tax = 0;
    foreach ($lines as $line) {
        $qty = (int)($line['quantity'] ?? 0);
        $unit = (int)($line['unit_price_cents'] ?? 0);
        $ld = (int)($line['line_discount_cents'] ?? 0);
        $lt = (int)($line['tax_cents'] ?? 0);
        if ($lt < 0) {
            throw new \InvalidArgumentException('Line tax cannot be negative.');
        }
        $subtotal += dl_pos_lineTotalCents($qty, $unit, $ld);
        $lineDiscount += $ld;
        $tax += $lt;
    }

    $discount = $lineDiscount + $orderDiscountCents;
    $total = max(0, $subtotal - $orderDiscountCents + $tax);

    return [
        'subtotal' => $subtotal,
        'line_discount' => $lineDiscount,
        'order_discount' => $orderDiscountCents,
        'discount' => $discount,
        'tax' => $tax,
        'total' => $total,
    ];
}

/** Change due in cents — never negative. */
function dl_pos_computeChangeCents(int $tenderedCents, int $dueCents): int
{
    return max(0, $tenderedCents - $dueCents);
}

/**
 * Split tendered amounts across payments for a due total.
 * Cash-like tenders may over-tender (change); non-cash must apply exactly.
 * $payments: [['method'=>string,'tendered_cents'=>int], ...]
 * Returns normalized ['method','tendered_cents','applied_cents','change_cents'] rows.
 * Throws InvalidArgumentException on insufficient or invalid tender.
 */
function dl_pos_allocatePayments(array $payments, int $dueCents, array $allowedTenders): array
{
    if ($payments === []) {
        throw new \InvalidArgumentException('At least one payment is required.');
    }
    if ($dueCents < 0) {
        throw new \InvalidArgumentException('Due amount cannot be negative.');
    }

    $remaining = $dueCents;
    $out = [];
    $count = count($payments);
    foreach ($payments as $i => $payment) {
        $method = strtolower(trim((string)($payment['method'] ?? '')));
        if ($method === '' || !in_array($method, $allowedTenders, true)) {
            throw new \InvalidArgumentException('Tender method "' . $method . '" is not allowed.');
        }
        $tendered = (int)($payment['tendered_cents'] ?? 0);
        if ($tendered <= 0) {
            throw new \InvalidArgumentException('Tendered amount must be positive.');
        }

        $isLast = ($i === $count - 1);
        if ($method === 'cash') {
            // Cash may over-tender; applied is capped at the remaining due.
            $applied = min($tendered, $remaining);
            if ($isLast && $tendered < $remaining) {
                throw new \InvalidArgumentException('Insufficient cash tendered.');
            }
        } else {
            // Non-cash tenders apply exactly what was tendered.
            $applied = $tendered;
            if ($applied > $remaining) {
                throw new \InvalidArgumentException('Non-cash tender exceeds the remaining balance.');
            }
        }

        $out[] = [
            'method' => $method,
            'tendered_cents' => $tendered,
            'applied_cents' => $applied,
            'change_cents' => dl_pos_computeChangeCents($tendered, $applied),
            'reference' => substr(trim((string)($payment['reference'] ?? '')), 0, 120),
        ];
        $remaining -= $applied;
    }

    if ($remaining > 0) {
        throw new \InvalidArgumentException('Payments do not cover the total due.');
    }

    return $out;
}

/** Parse the pos_allowed_tenders setting into a normalized list. */
function dl_pos_parseAllowedTenders($raw): array
{
    if (is_string($raw)) {
        $raw = explode(',', $raw);
    }
    if (!is_array($raw)) {
        return ['cash'];
    }
    $out = [];
    foreach ($raw as $method) {
        $method = strtolower(trim((string)$method));
        if ($method !== '' && preg_match('/^[a-z][a-z0-9_\-]{0,29}$/', $method)) {
            $out[$method] = true;
        }
    }
    $list = array_keys($out);
    return $list !== [] ? $list : ['cash'];
}

// ═══════════════════════════════════════════════════════════════════════
// Mode state machine (pure predicates)
// ═══════════════════════════════════════════════════════════════════════

/** Normalize a requested mode; null when invalid. */
function dl_pos_normalizeMode($mode): ?string
{
    $mode = strtolower(trim((string)$mode));
    return in_array($mode, ['manual', 'pos', 'fallback'], true) ? $mode : null;
}

/**
 * Decide whether a mode selection is allowed. Returns an error code string
 * (stable, machine-readable) or null when the selection is allowed.
 *
 * Rules:
 * - No recorded mode: manual allowed with no POS activity; pos allowed with
 *   no manual activity.
 * - Same-mode reselect is idempotent (allowed).
 * - manual → pos only when there is no manual activity yet.
 * - pos → manual is rejected (mid-day switch requires the fallback checkpoint).
 * - fallback is terminal: nothing may be selected after it.
 */
function dl_pos_modeSelectionError(?string $currentMode, string $requestedMode, bool $hasManualActivity, bool $hasPosActivity): ?string
{
    if ($requestedMode === 'fallback') {
        return 'FALLBACK_REQUIRES_CHECKPOINT';
    }

    if ($currentMode === null) {
        if ($requestedMode === 'manual' && $hasPosActivity) {
            return 'MODE_LOCKED_POS_ACTIVITY';
        }
        if ($requestedMode === 'pos' && $hasManualActivity) {
            return 'MODE_LOCKED_MANUAL_ACTIVITY';
        }
        return null;
    }

    if ($currentMode === 'fallback') {
        return 'MODE_FALLBACK_FINAL';
    }

    if ($currentMode === $requestedMode) {
        return null; // idempotent re-select
    }

    if ($currentMode === 'manual' && $requestedMode === 'pos') {
        return $hasManualActivity ? 'MODE_LOCKED_MANUAL_ACTIVITY' : null;
    }

    // pos → manual (or any other unhandled transition)
    return 'MODE_SWITCH_REQUIRES_FALLBACK';
}

/**
 * Post-checkpoint manual segment quantity for one product:
 * max(0, checkpointCount + addtlDelta - withdrawDelta - balEnd).
 */
function dl_pos_computeFallbackSegmentQty(int $checkpointCount, int $addtlDelta, int $withdrawDelta, int $balEnd): int
{
    return max(0, $checkpointCount + $addtlDelta - $withdrawDelta - $balEnd);
}

/**
 * Validate refund request lines against the original sale items and the
 * quantities already refunded. Returns an error message or null.
 *
 * $originalItems: product_id => original quantity
 * $refundedSoFar: product_id => quantity already refunded
 * $requestLines:  [['product_id'=>int,'quantity'=>int], ...]
 */
function dl_pos_validateRefundLines(array $originalItems, array $refundedSoFar, array $requestLines): ?string
{
    if ($requestLines === []) {
        return 'Refund must contain at least one line.';
    }
    $requested = [];
    foreach ($requestLines as $line) {
        $pid = (int)($line['product_id'] ?? 0);
        $qty = (int)($line['quantity'] ?? 0);
        if ($pid <= 0 || $qty <= 0) {
            return 'Refund lines require a product and a positive quantity.';
        }
        $requested[$pid] = ($requested[$pid] ?? 0) + $qty;
    }
    foreach ($requested as $pid => $qty) {
        $original = (int)($originalItems[$pid] ?? 0);
        if ($original <= 0) {
            return 'Product #' . $pid . ' is not part of the original sale.';
        }
        $already = (int)($refundedSoFar[$pid] ?? 0);
        if ($already + $qty > $original) {
            return 'Refund for product #' . $pid . ' exceeds the unrefunded quantity.';
        }
    }
    return null;
}

// ═══════════════════════════════════════════════════════════════════════
// Feature + permission helpers
// ═══════════════════════════════════════════════════════════════════════

function dl_isPosEnabled(): bool
{
    $settings = dlModuleSettings();
    return dl_settingToBool($settings['pos_enabled'] ?? false);
}

function dl_pos_allowedTenders(): array
{
    $settings = dlModuleSettings();
    return dl_pos_parseAllowedTenders($settings['pos_allowed_tenders'] ?? 'cash');
}

/**
 * POS permission check: role gate + fine-grained role_permissions grant.
 * pos.sell is available to cashiers; void/refund/fallback/report require
 * supervisor or admin authority.
 */
function dl_pos_userCan(array $user, string $permission): bool
{
    $role = (string)($user['role'] ?? '');
    $roleGate = match ($permission) {
        'pos.sell' => in_array($role, ['cashier', 'supervisor', 'admin'], true),
        'pos.void', 'pos.refund', 'pos.fallback', 'pos.report' => in_array($role, ['supervisor', 'admin'], true),
        default => false,
    };
    if (!$roleGate) {
        return false;
    }
    return dl_roleHasPermission($role, $permission);
}

// ═══════════════════════════════════════════════════════════════════════
// DB accessors (ModuleDB-typed; no HTTP)
// ═══════════════════════════════════════════════════════════════════════

/** @return array<string,mixed>|null */
function dl_pos_getDayModeRow($db, int $branchId, string $date): ?array
{
    $stmt = $db->prepare('SELECT * FROM dl_sales_day_modes WHERE branch_id = :b AND ledger_date = :d LIMIT 1');
    $stmt->execute([':b' => $branchId, ':d' => $date]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

/** Manual activity = any ledger row for the day with encoded source values. */
function dl_pos_hasManualActivity($db, int $branchId, string $date): bool
{
    $stmt = $db->prepare(
        'SELECT 1 FROM dl_daily_ledger
          WHERE branch_id = :b AND ledger_date = :d
            AND (COALESCE(addtl,0) <> 0 OR COALESCE(withdraw,0) <> 0 OR COALESCE(bal_end,0) <> 0)
          LIMIT 1'
    );
    $stmt->execute([':b' => $branchId, ':d' => $date]);
    return (bool)$stmt->fetchColumn();
}

/** POS activity = any completed (or later lifecycle) sale for the day. */
function dl_pos_hasCompletedPosSale($db, int $branchId, string $date): bool
{
    $stmt = $db->prepare(
        "SELECT 1 FROM dl_pos_sales
          WHERE branch_id = :b AND ledger_date = :d AND status <> 'draft'
          LIMIT 1"
    );
    $stmt->execute([':b' => $branchId, ':d' => $date]);
    return (bool)$stmt->fetchColumn();
}

/** Open carts = draft sales for the day. */
function dl_pos_openDraftCount($db, int $branchId, string $date): int
{
    $stmt = $db->prepare(
        "SELECT COUNT(*) FROM dl_pos_sales
          WHERE branch_id = :b AND ledger_date = :d AND status = 'draft'"
    );
    $stmt->execute([':b' => $branchId, ':d' => $date]);
    return (int)$stmt->fetchColumn();
}

/**
 * Effective mode for a branch-day. Returns
 * ['mode' => 'manual'|'pos'|'fallback', 'row' => ?array, 'decided' => bool].
 * No recorded mode means undecided → behaves as manual (existing workflow).
 */
function dl_pos_dayMode($db, int $branchId, string $date): array
{
    $row = dl_pos_getDayModeRow($db, $branchId, $date);
    if (!is_array($row)) {
        return ['mode' => 'manual', 'row' => null, 'decided' => false];
    }
    return ['mode' => (string)$row['mode'], 'row' => $row, 'decided' => true];
}

/**
 * Record a mode selection transactionally. Returns
 * ['ok'=>bool, 'code'=>?string, 'error'=>?string, 'mode_row'=>?array].
 */
function dl_pos_selectMode($db, int $branchId, string $date, string $requestedMode, int $actorId, ?int $expectedVersion): array
{
    $requestedMode = dl_pos_normalizeMode($requestedMode) ?? '';
    if ($requestedMode === '') {
        return ['ok' => false, 'code' => 'INVALID_MODE', 'error' => 'Unknown sales mode.'];
    }

    $ownsTxn = !$db->inTransaction();
    if ($ownsTxn) {
        $db->beginTransaction();
    }
    try {
        // Day must be open; the day-status row lock serializes per branch+date.
        $dayStatus = dl_lockDayStatusRow($db, $branchId, $date);
        if ($dayStatus === 'closed') {
            if ($ownsTxn) { $db->rollBack(); }
            return ['ok' => false, 'code' => 'DAY_CLOSED', 'error' => 'This business date is closed.'];
        }

        $modeRow = dl_pos_getDayModeRow($db, $branchId, $date);
        if (is_array($modeRow)) {
            // Lock the mode row for the concurrency check.
            $lock = $db->prepare('SELECT version FROM dl_sales_day_modes WHERE id = :id FOR UPDATE');
            $lock->execute([':id' => (int)$modeRow['id']]);
            $modeRow['version'] = (int)$lock->fetchColumn();
        }

        $currentMode = is_array($modeRow) ? (string)$modeRow['mode'] : null;
        $errorCode = dl_pos_modeSelectionError(
            $currentMode,
            $requestedMode,
            dl_pos_hasManualActivity($db, $branchId, $date),
            dl_pos_hasCompletedPosSale($db, $branchId, $date)
        );
        if ($errorCode !== null) {
            if ($ownsTxn) { $db->rollBack(); }
            return ['ok' => false, 'code' => $errorCode, 'error' => 'Sales mode cannot be changed to ' . $requestedMode . '.'];
        }

        if (is_array($modeRow)) {
            if ($expectedVersion !== null && (int)$modeRow['version'] !== $expectedVersion) {
                if ($ownsTxn) { $db->rollBack(); }
                return ['ok' => false, 'code' => 'VERSION_CONFLICT', 'error' => 'The day mode changed; refresh and retry.'];
            }
            // Persist the transition (e.g. manual → pos before any activity). An
            // idempotent re-select of the same mode is a no-op.
            if ((string)$modeRow['mode'] !== $requestedMode) {
                $db->prepare(
                    "UPDATE dl_sales_day_modes
                        SET mode = :m, status = 'active', version = version + 1,
                            selected_by = :u, selected_at = NOW()
                      WHERE id = :id"
                )->execute([
                    ':m' => $requestedMode,
                    ':u' => $actorId > 0 ? $actorId : null,
                    ':id' => (int)$modeRow['id'],
                ]);
            }
        } else {
            $ins = $db->prepare(
                'INSERT INTO dl_sales_day_modes (branch_id, ledger_date, mode, status, version, selected_by, selected_at)
                 VALUES (:b, :d, :m, \'active\', 1, :u, NOW())'
            );
            $ins->execute([':b' => $branchId, ':d' => $date, ':m' => $requestedMode, ':u' => $actorId > 0 ? $actorId : null]);
        }

        dl_auditLog('pos_mode_select', $branchId, 'dl_sales_day_modes', "{$branchId}-{$date}", null, [
            'mode' => $requestedMode,
            'selected_by' => $actorId,
        ]);

        $fresh = dl_pos_getDayModeRow($db, $branchId, $date);
        if ($ownsTxn) { $db->commit(); }
        return ['ok' => true, 'code' => null, 'error' => null, 'mode_row' => $fresh];
    } catch (\Throwable $e) {
        if ($ownsTxn && $db->inTransaction()) { $db->rollBack(); }
        return ['ok' => false, 'code' => 'MODE_SELECT_FAILED', 'error' => 'Could not record the sales mode.'];
    }
}

// ═══════════════════════════════════════════════════════════════════════
// Checkout
// ═══════════════════════════════════════════════════════════════════════

/** Canonical request hash for idempotency comparison. */
function dl_pos_requestHash(array $lines, array $payments): string
{
    $normLines = [];
    foreach ($lines as $line) {
        $normLines[] = [
            'product_id' => (int)($line['product_id'] ?? 0),
            'quantity' => (int)($line['quantity'] ?? 0),
            'line_discount_cents' => (int)($line['line_discount_cents'] ?? 0),
            'tax_cents' => (int)($line['tax_cents'] ?? 0),
        ];
    }
    usort($normLines, static fn (array $a, array $b): int => $a['product_id'] <=> $b['product_id']);
    $normPayments = [];
    foreach ($payments as $payment) {
        $normPayments[] = [
            'method' => strtolower(trim((string)($payment['method'] ?? ''))),
            'tendered_cents' => (int)($payment['tendered_cents'] ?? 0),
        ];
    }
    return hash('sha256', json_encode(['lines' => $normLines, 'payments' => $normPayments]) ?: '');
}

/**
 * Load branch-active products with server-resolved prices (cents).
 * @return array<int,array{product_id:int,sku:string,name:string,unit_price_cents:int,price_group_id:?int}>
 */
function dl_pos_branchProducts($db, int $branchId, string $date): array
{
    $stmt = $db->prepare(
        'SELECT p.id AS product_id, p.sku, p.name
           FROM dl_branch_products bp
           INNER JOIN dl_products p ON p.id = bp.product_id
          WHERE bp.branch_id = :b AND bp.is_active = 1 AND p.is_active = 1
          ORDER BY p.sort_order, p.name'
    );
    $stmt->execute([':b' => $branchId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $priceGroupId = dl_branchPriceGroupId($branchId);
    $out = [];
    foreach ($rows as $row) {
        $pid = (int)$row['product_id'];
        $out[$pid] = [
            'product_id' => $pid,
            'sku' => (string)$row['sku'],
            'name' => (string)$row['name'],
            'unit_price_cents' => (int)round(dl_resolveProductPrice($pid, $priceGroupId, $date) * 100),
            'price_group_id' => $priceGroupId,
        ];
    }
    return $out;
}

/**
 * Complete a POS sale atomically. All validation is server-side; client
 * prices/totals are ignored (a mismatched client price is rejected as stale).
 *
 * $args: branch_id, ledger_date, cashier (user array), lines, payments,
 *        client_operation_key, expected_version (mode row version, optional).
 *
 * Returns ['ok'=>bool, 'code'=>?string, 'error'=>?string, 'status'=>int,
 *          'sale'=>?array, 'idempotent_replay'=>bool].
 */
function dl_pos_checkout($db, array $args): array
{
    $branchId = (int)($args['branch_id'] ?? 0);
    $date = (string)($args['ledger_date'] ?? '');
    $cashier = is_array($args['cashier'] ?? null) ? $args['cashier'] : [];
    $cashierId = dl_getActorUserId($cashier);
    $lines = is_array($args['lines'] ?? null) ? array_values($args['lines']) : [];
    $payments = is_array($args['payments'] ?? null) ? array_values($args['payments']) : [];
    $clientOpKey = substr(trim((string)($args['client_operation_key'] ?? '')), 0, 80);
    $expectedVersion = isset($args['expected_version']) && $args['expected_version'] !== null
        ? (int)$args['expected_version'] : null;

    if ($branchId <= 0 || $date === '' || $cashierId <= 0) {
        return ['ok' => false, 'code' => 'INVALID_CONTEXT', 'error' => 'Missing branch, date, or cashier.', 'status' => 422];
    }
    if ($clientOpKey === '') {
        return ['ok' => false, 'code' => 'IDEMPOTENCY_KEY_REQUIRED', 'error' => 'client_operation_key is required.', 'status' => 422];
    }
    if ($lines === []) {
        return ['ok' => false, 'code' => 'EMPTY_CART', 'error' => 'Cart must contain at least one line.', 'status' => 422];
    }

    $requestHash = dl_pos_requestHash($lines, $payments);

    $db->beginTransaction();
    try {
        // 1) Day must be open (row lock serializes the branch-day).
        if (dl_lockDayStatusRow($db, $branchId, $date) === 'closed') {
            $db->rollBack();
            return ['ok' => false, 'code' => 'DAY_CLOSED', 'error' => 'This business date is closed.', 'status' => 409];
        }

        // 2) Mode must be POS; fallback permanently blocks later checkout.
        $modeRow = dl_pos_getDayModeRow($db, $branchId, $date);
        if (!is_array($modeRow)) {
            $db->rollBack();
            return ['ok' => false, 'code' => 'MODE_NOT_SELECTED', 'error' => 'Select POS mode for this day first.', 'status' => 409];
        }
        $lock = $db->prepare('SELECT id, mode, status, version FROM dl_sales_day_modes WHERE id = :id FOR UPDATE');
        $lock->execute([':id' => (int)$modeRow['id']]);
        $modeRow = $lock->fetch(PDO::FETCH_ASSOC) ?: $modeRow;
        if ((string)$modeRow['mode'] !== 'pos') {
            $db->rollBack();
            return ['ok' => false, 'code' => 'POS_NOT_ACTIVE', 'error' => 'POS is not the active sales mode for this day.', 'status' => 409];
        }
        if ($expectedVersion !== null && (int)$modeRow['version'] !== $expectedVersion) {
            $db->rollBack();
            return ['ok' => false, 'code' => 'VERSION_CONFLICT', 'error' => 'The day mode changed; refresh and retry.', 'status' => 409];
        }

        // 3) Idempotency: same key + same payload replays the original receipt;
        //    same key + different payload is a conflict. Draft carts are mutable
        //    cart state (no committed request_hash) — the owning cashier may
        //    complete them with any payload.
        $idem = $db->prepare(
            'SELECT id, sale_uuid, receipt_no, request_hash, status, total_cents, cashier_id
               FROM dl_pos_sales WHERE branch_id = :b AND client_operation_key = :k LIMIT 1 FOR UPDATE'
        );
        $idem->execute([':b' => $branchId, ':k' => $clientOpKey]);
        $existing = $idem->fetch(PDO::FETCH_ASSOC);
        if (is_array($existing)) {
            if ((string)$existing['status'] === 'draft') {
                // Only the cashier who owns the draft may complete it, so receipt
                // attribution stays with the original cashier.
                if ((int)$existing['cashier_id'] !== $cashierId) {
                    $db->rollBack();
                    return ['ok' => false, 'code' => 'CART_OWNER_CONFLICT', 'error' => 'This cart belongs to another cashier.', 'status' => 409];
                }
                // Upgrade a saved draft cart into a completed sale below.
                $saleId = (int)$existing['id'];
            } else {
                // Committed sale — idempotency by key + payload hash.
                if ((string)$existing['request_hash'] !== $requestHash) {
                    $db->rollBack();
                    return ['ok' => false, 'code' => 'IDEMPOTENCY_CONFLICT', 'error' => 'This operation key was already used with a different cart.', 'status' => 409];
                }
                $sale = dl_pos_loadSale($db, (int)$existing['id']);
                $db->commit();
                return ['ok' => true, 'code' => null, 'error' => null, 'status' => 200, 'sale' => $sale, 'idempotent_replay' => true];
            }
        } else {
            $saleId = 0;
        }

        // 4) Server-side product + price resolution; reject stale/unavailable.
        $catalog = dl_pos_branchProducts($db, $branchId, $date);
        $validatedLines = [];
        $mergedQty = [];
        foreach ($lines as $line) {
            $pid = (int)($line['product_id'] ?? 0);
            $qty = (int)($line['quantity'] ?? 0);
            if ($pid <= 0 || $qty <= 0) {
                $db->rollBack();
                return ['ok' => false, 'code' => 'INVALID_LINE', 'error' => 'Each line requires a product and a positive quantity.', 'status' => 422];
            }
            if (!isset($catalog[$pid])) {
                $db->rollBack();
                return ['ok' => false, 'code' => 'PRODUCT_UNAVAILABLE', 'error' => 'Product #' . $pid . ' is not available at this branch.', 'status' => 422];
            }
            $mergedQty[$pid] = ($mergedQty[$pid] ?? 0) + $qty;
        }
        foreach ($mergedQty as $pid => $qty) {
            $product = $catalog[$pid];
            $validatedLines[$pid] = [
                'product_id' => $pid,
                'quantity' => $qty,
                'unit_price_cents' => $product['unit_price_cents'],
                'line_discount_cents' => 0,
                'tax_cents' => 0,
            ];
        }
        // Per-line client price check (stale price protection).
        foreach ($lines as $line) {
            $pid = (int)($line['product_id'] ?? 0);
            if ($pid > 0 && isset($line['unit_price'])) {
                $clientCents = dl_pos_toCents($line['unit_price']);
                if ($clientCents === null || $clientCents !== (int)$catalog[$pid]['unit_price_cents']) {
                    $db->rollBack();
                    return [
                        'ok' => false,
                        'code' => 'STALE_PRICE',
                        'error' => 'Price for ' . $catalog[$pid]['name'] . ' changed; refresh the cart.',
                        'status' => 422,
                        'current_price' => dl_pos_formatCents((int)$catalog[$pid]['unit_price_cents']),
                        'product_id' => $pid,
                    ];
                }
            }
        }
        // Optional per-line discounts/tax (cents, bounded by the line value).
        foreach ($lines as $line) {
            $pid = (int)($line['product_id'] ?? 0);
            if ($pid <= 0 || !isset($validatedLines[$pid])) { continue; }
            $ld = isset($line['line_discount_cents']) ? (int)$line['line_discount_cents'] : 0;
            $tx = isset($line['tax_cents']) ? (int)$line['tax_cents'] : 0;
            if ($ld < 0 || $tx < 0) {
                $db->rollBack();
                return ['ok' => false, 'code' => 'INVALID_LINE', 'error' => 'Discounts and tax cannot be negative.', 'status' => 422];
            }
            $validatedLines[$pid]['line_discount_cents'] += $ld;
            $validatedLines[$pid]['tax_cents'] += $tx;
        }
        $orderDiscountCents = isset($args['order_discount_cents']) ? (int)$args['order_discount_cents'] : 0;

        try {
            $totals = dl_pos_orderTotals(array_values($validatedLines), $orderDiscountCents);
            $allocated = dl_pos_allocatePayments($payments, $totals['total'], dl_pos_allowedTenders());
        } catch (\InvalidArgumentException $e) {
            $db->rollBack();
            return ['ok' => false, 'code' => 'PAYMENT_INVALID', 'error' => $e->getMessage(), 'status' => 422];
        }

        // 5) Receipt sequence: branch-scoped, serialized by the day-status lock.
        $receiptNo = '';
        if ($saleId > 0) {
            // Reuse the draft's receipt slot: assign now.
            $receiptNo = dl_pos_nextReceiptNo($db, $branchId, $date);
            $upd = $db->prepare(
                "UPDATE dl_pos_sales
                    SET status = 'completed', receipt_no = :rn, request_hash = :rh,
                        item_count = :ic, subtotal_cents = :sub, discount_cents = :disc,
                        tax_cents = :tax, total_cents = :tot, version = version + 1,
                        completed_at = NOW()
                  WHERE id = :id AND status = 'draft'"
            );
            $upd->execute([
                ':rn' => $receiptNo, ':rh' => $requestHash,
                ':ic' => count($validatedLines), ':sub' => $totals['subtotal'],
                ':disc' => $totals['discount'], ':tax' => $totals['tax'], ':tot' => $totals['total'],
                ':id' => $saleId,
            ]);
            if ($upd->rowCount() !== 1) {
                $db->rollBack();
                return ['ok' => false, 'code' => 'CART_STATE_CONFLICT', 'error' => 'The cart changed; refresh and retry.', 'status' => 409];
            }
            // Replace draft items with the validated snapshots.
            $db->prepare('DELETE FROM dl_pos_sale_items WHERE sale_id = :id')->execute([':id' => $saleId]);
            $db->prepare('DELETE FROM dl_pos_payments WHERE sale_id = :id')->execute([':id' => $saleId]);
        } else {
            $saleUuid = dl_generateMovementUuid();
            $receiptNo = dl_pos_nextReceiptNo($db, $branchId, $date);
            $ins = $db->prepare(
                "INSERT INTO dl_pos_sales
                    (sale_uuid, client_operation_key, request_hash, sale_kind, branch_id, ledger_date,
                     cashier_id, receipt_no, status, item_count, subtotal_cents, discount_cents,
                     tax_cents, total_cents, completed_at)
                 VALUES
                    (:uuid, :opk, :rh, 'sale', :b, :d, :cid, :rn, 'completed', :ic, :sub, :disc, :tax, :tot, NOW())"
            );
            $ins->execute([
                ':uuid' => $saleUuid, ':opk' => $clientOpKey, ':rh' => $requestHash,
                ':b' => $branchId, ':d' => $date, ':cid' => $cashierId, ':rn' => $receiptNo,
                ':ic' => count($validatedLines), ':sub' => $totals['subtotal'],
                ':disc' => $totals['discount'], ':tax' => $totals['tax'], ':tot' => $totals['total'],
            ]);
            $saleId = (int)$db->lastInsertId();
        }

        $itemStmt = $db->prepare(
            'INSERT INTO dl_pos_sale_items
                (sale_id, product_id, product_name, sku, price_group_id, unit_price_cents,
                 quantity, line_discount_cents, tax_cents, line_total_cents)
             VALUES
                (:sid, :pid, :name, :sku, :pg, :unit, :qty, :ld, :tx, :lt)'
        );
        foreach ($validatedLines as $pid => $line) {
            $product = $catalog[$pid];
            $itemStmt->execute([
                ':sid' => $saleId,
                ':pid' => $pid,
                ':name' => $product['name'],
                ':sku' => $product['sku'],
                ':pg' => $product['price_group_id'],
                ':unit' => $line['unit_price_cents'],
                ':qty' => $line['quantity'],
                ':ld' => $line['line_discount_cents'],
                ':tx' => $line['tax_cents'],
                ':lt' => dl_pos_lineTotalCents($line['quantity'], $line['unit_price_cents'], $line['line_discount_cents']) + $line['tax_cents'],
            ]);
        }

        $payStmt = $db->prepare(
            'INSERT INTO dl_pos_payments (sale_id, tender_method, amount_tendered_cents, amount_applied_cents, change_cents, reference)
             VALUES (:sid, :m, :t, :a, :c, :r)'
        );
        foreach ($allocated as $payment) {
            $payStmt->execute([
                ':sid' => $saleId,
                ':m' => $payment['method'],
                ':t' => $payment['tendered_cents'],
                ':a' => $payment['applied_cents'],
                ':c' => $payment['change_cents'],
                ':r' => $payment['reference'],
            ]);
        }

        dl_pos_recordEvent($db, $saleId, 'completed', $cashierId, (string)($cashier['role'] ?? ''), null, [
            'receipt_no' => $receiptNo,
            'total_cents' => $totals['total'],
        ]);

        // First completed sale locks the POS mode for the day.
        if ((string)$modeRow['status'] === 'active') {
            $db->prepare("UPDATE dl_sales_day_modes SET status = 'locked', version = version + 1 WHERE id = :id")
                ->execute([':id' => (int)$modeRow['id']]);
        }

        dl_auditLog('pos_sale_completed', $branchId, 'dl_pos_sales', (string)$saleId, null, [
            'receipt_no' => $receiptNo,
            'total_cents' => $totals['total'],
            'item_count' => count($validatedLines),
        ]);

        $sale = dl_pos_loadSale($db, $saleId);
        $db->commit();

        return ['ok' => true, 'code' => null, 'error' => null, 'status' => 200, 'sale' => $sale, 'idempotent_replay' => false];
    } catch (\Throwable $e) {
        if ($db->inTransaction()) { $db->rollBack(); }
        write_log('daily-ledger POS checkout failed: ' . $e->getMessage(), 'error', ['branch_id' => $branchId]);
        return ['ok' => false, 'code' => 'CHECKOUT_FAILED', 'error' => 'Checkout could not be completed.', 'status' => 500];
    }
}

/** Next receipt number for a branch-day (caller must hold the day lock). */
function dl_pos_nextReceiptNo($db, int $branchId, string $date): string
{
    $stmt = $db->prepare(
        "SELECT COUNT(*) FROM dl_pos_sales
          WHERE branch_id = :b AND ledger_date = :d AND status <> 'draft'"
    );
    $stmt->execute([':b' => $branchId, ':d' => $date]);
    $seq = ((int)$stmt->fetchColumn()) + 1;
    return sprintf('R%d-%s-%04d', $branchId, str_replace('-', '', $date), $seq);
}

/** Append an event row (inside the caller's transaction). */
function dl_pos_recordEvent($db, int $saleId, string $eventType, ?int $actorId, string $actorRole, ?string $reason, array $payload = []): void
{
    $db->prepare(
        'INSERT INTO dl_pos_sale_events (sale_id, event_type, actor_id, actor_role, reason, payload)
         VALUES (:sid, :t, :a, :r, :reason, :p)'
    )->execute([
        ':sid' => $saleId,
        ':t' => substr($eventType, 0, 40),
        ':a' => ($actorId !== null && $actorId > 0) ? $actorId : null,
        ':r' => substr($actorRole, 0, 40),
        ':reason' => $reason !== null && $reason !== '' ? substr($reason, 0, 255) : null,
        ':p' => $payload !== [] ? json_encode($payload) : null,
    ]);
}

/** Load a sale with items + payments for receipt/report rendering. */
function dl_pos_loadSale($db, int $saleId): ?array
{
    $stmt = $db->prepare(
        'SELECT s.*, u.full_name AS cashier_name, b.name AS branch_name
           FROM dl_pos_sales s
           LEFT JOIN dl_users u ON u.id = s.cashier_id
           LEFT JOIN dl_branches b ON b.id = s.branch_id
          WHERE s.id = :id LIMIT 1'
    );
    $stmt->execute([':id' => $saleId]);
    $sale = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($sale)) {
        return null;
    }

    $items = $db->prepare('SELECT * FROM dl_pos_sale_items WHERE sale_id = :id ORDER BY id');
    $items->execute([':id' => $saleId]);
    $sale['items'] = $items->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $payments = $db->prepare('SELECT * FROM dl_pos_payments WHERE sale_id = :id ORDER BY id');
    $payments->execute([':id' => $saleId]);
    $sale['payments'] = $payments->fetchAll(PDO::FETCH_ASSOC) ?: [];

    return $sale;
}

// ═══════════════════════════════════════════════════════════════════════
// Lifecycle: void + refund (append-only corrections)
// ═══════════════════════════════════════════════════════════════════════

/**
 * Void a completed sale before day close. Items and payments are preserved;
 * only lifecycle metadata is set. Returns ok/code/error/status.
 */
function dl_pos_voidSale($db, int $saleId, array $actor, string $reason): array
{
    $reason = trim($reason);
    if ($reason === '') {
        return ['ok' => false, 'code' => 'REASON_REQUIRED', 'error' => 'A void reason is required.', 'status' => 422];
    }
    $actorId = dl_getActorUserId($actor);

    $db->beginTransaction();
    try {
        $stmt = $db->prepare('SELECT * FROM dl_pos_sales WHERE id = :id LIMIT 1 FOR UPDATE');
        $stmt->execute([':id' => $saleId]);
        $sale = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($sale)) {
            $db->rollBack();
            return ['ok' => false, 'code' => 'NOT_FOUND', 'error' => 'Sale not found.', 'status' => 404];
        }
        if ((string)$sale['sale_kind'] !== 'sale') {
            $db->rollBack();
            return ['ok' => false, 'code' => 'NOT_VOIDABLE', 'error' => 'Refund documents cannot be voided.', 'status' => 422];
        }
        if ((string)$sale['status'] !== 'completed') {
            $db->rollBack();
            return ['ok' => false, 'code' => 'NOT_VOIDABLE', 'error' => 'Only completed sales can be voided.', 'status' => 422];
        }
        if (dl_lockDayStatusRow($db, (int)$sale['branch_id'], (string)$sale['ledger_date']) === 'closed') {
            $db->rollBack();
            return ['ok' => false, 'code' => 'DAY_CLOSED', 'error' => 'This business date is closed.', 'status' => 409];
        }

        $db->prepare(
            "UPDATE dl_pos_sales
                SET status = 'voided', voided_at = NOW(), voided_by = :uid, void_reason = :reason, version = version + 1
              WHERE id = :id AND status = 'completed'"
        )->execute([':uid' => $actorId > 0 ? $actorId : null, ':reason' => substr($reason, 0, 255), ':id' => $saleId]);

        dl_pos_recordEvent($db, $saleId, 'voided', $actorId, (string)($actor['role'] ?? ''), $reason, [
            'old_status' => 'completed',
            'new_status' => 'voided',
            'receipt_no' => (string)$sale['receipt_no'],
        ]);
        dl_auditLog('pos_sale_voided', (int)$sale['branch_id'], 'dl_pos_sales', (string)$saleId,
            ['status' => 'completed'], ['status' => 'voided'], $reason);

        $db->commit();
        return ['ok' => true, 'code' => null, 'error' => null, 'status' => 200, 'sale' => dl_pos_loadSale($db, $saleId)];
    } catch (\Throwable $e) {
        if ($db->inTransaction()) { $db->rollBack(); }
        write_log('daily-ledger POS void failed: ' . $e->getMessage(), 'error', ['sale_id' => $saleId]);
        return ['ok' => false, 'code' => 'VOID_FAILED', 'error' => 'Void could not be completed.', 'status' => 500];
    }
}

/**
 * Record an append-only refund document linked to the original sale.
 * $lines: [['product_id'=>int,'quantity'=>int], ...]
 */
function dl_pos_refundSale($db, int $saleId, array $actor, array $lines, string $reason, string $clientOpKey = ''): array
{
    $reason = trim($reason);
    if ($reason === '') {
        return ['ok' => false, 'code' => 'REASON_REQUIRED', 'error' => 'A refund reason is required.', 'status' => 422];
    }
    $actorId = dl_getActorUserId($actor);
    $clientOpKey = substr(trim($clientOpKey), 0, 80);

    $db->beginTransaction();
    try {
        $stmt = $db->prepare('SELECT * FROM dl_pos_sales WHERE id = :id LIMIT 1 FOR UPDATE');
        $stmt->execute([':id' => $saleId]);
        $sale = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($sale)) {
            $db->rollBack();
            return ['ok' => false, 'code' => 'NOT_FOUND', 'error' => 'Sale not found.', 'status' => 404];
        }
        if ((string)$sale['sale_kind'] !== 'sale' || !in_array((string)$sale['status'], ['completed', 'partially_refunded'], true)) {
            $db->rollBack();
            return ['ok' => false, 'code' => 'NOT_REFUNDABLE', 'error' => 'Only completed sales can be refunded.', 'status' => 422];
        }
        $branchId = (int)$sale['branch_id'];
        $date = (string)$sale['ledger_date'];
        if (dl_lockDayStatusRow($db, $branchId, $date) === 'closed') {
            $db->rollBack();
            return ['ok' => false, 'code' => 'DAY_CLOSED', 'error' => 'This business date is closed.', 'status' => 409];
        }

        // Idempotency for refund operations.
        if ($clientOpKey !== '') {
            $idem = $db->prepare(
                'SELECT id FROM dl_pos_sales WHERE branch_id = :b AND client_operation_key = :k LIMIT 1'
            );
            $idem->execute([':b' => $branchId, ':k' => $clientOpKey]);
            $existingId = $idem->fetchColumn();
            if ($existingId !== false) {
                $refund = dl_pos_loadSale($db, (int)$existingId);
                if ((int)($refund['refund_of_sale_id'] ?? 0) !== $saleId) {
                    $db->rollBack();
                    return ['ok' => false, 'code' => 'IDEMPOTENCY_CONFLICT', 'error' => 'This operation key was already used.', 'status' => 409];
                }
                $db->commit();
                return ['ok' => true, 'code' => null, 'error' => null, 'status' => 200, 'refund' => $refund, 'idempotent_replay' => true];
            }
        }

        $itemStmt = $db->prepare('SELECT * FROM dl_pos_sale_items WHERE sale_id = :id');
        $itemStmt->execute([':id' => $saleId]);
        $originalItems = [];
        $originalByProduct = [];
        foreach ($itemStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $item) {
            $pid = (int)$item['product_id'];
            $originalItems[$pid] = (int)$item['quantity'];
            $originalByProduct[$pid] = $item;
        }

        // Quantities already refunded by prior refund documents.
        $refundedSoFar = [];
        $priorStmt = $db->prepare(
            "SELECT i.product_id, SUM(i.quantity) AS qty
               FROM dl_pos_sales r
               INNER JOIN dl_pos_sale_items i ON i.sale_id = r.id
              WHERE r.refund_of_sale_id = :id AND r.sale_kind = 'refund' AND r.status <> 'voided'
              GROUP BY i.product_id"
        );
        $priorStmt->execute([':id' => $saleId]);
        foreach ($priorStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $refundedSoFar[(int)$row['product_id']] = (int)$row['qty'];
        }

        // Amount already refunded (defensive cap — refunds must never exceed
        // the original sale's remaining unrefunded money, even when the sale
        // carried an order discount that prorated the charged amount).
        $priorAmtStmt = $db->prepare(
            "SELECT COALESCE(SUM(r.total_cents),0) AS amt
               FROM dl_pos_sales r
              WHERE r.refund_of_sale_id = :id AND r.sale_kind = 'refund' AND r.status <> 'voided'"
        );
        $priorAmtStmt->execute([':id' => $saleId]);
        $priorRefundedCents = (int)$priorAmtStmt->fetchColumn();
        $originalTotalCents = (int)$sale['total_cents'];

        $validationError = dl_pos_validateRefundLines($originalItems, $refundedSoFar, $lines);
        if ($validationError !== null) {
            $db->rollBack();
            return ['ok' => false, 'code' => 'REFUND_INVALID', 'error' => $validationError, 'status' => 422];
        }

        // Build refund lines from ORIGINAL price snapshots (never current catalog).
        $refundLines = [];
        foreach ($lines as $line) {
            $pid = (int)$line['product_id'];
            $qty = (int)$line['quantity'];
            $src = $originalByProduct[$pid];
            $unit = (int)$src['unit_price_cents'];
            $refundLines[] = [
                'product_id' => $pid,
                'quantity' => $qty,
                'unit_price_cents' => $unit,
                'line_discount_cents' => 0,
                'tax_cents' => 0,
                'snapshot' => $src,
            ];
        }
        $totals = dl_pos_orderTotals(array_map(static function (array $l): array {
            return [
                'quantity' => $l['quantity'],
                'unit_price_cents' => $l['unit_price_cents'],
                'line_discount_cents' => 0,
                'tax_cents' => 0,
            ];
        }, $refundLines));

        // Money cap: never refund more than the original sale's remaining
        // unrefunded amount (protects against over-refund when the original
        // sale carried an order discount not represented on the lines).
        if ($totals['total'] > max(0, $originalTotalCents - $priorRefundedCents)) {
            $db->rollBack();
            return ['ok' => false, 'code' => 'REFUND_EXCEEDS_AMOUNT', 'error' => 'Refund amount exceeds the remaining unrefunded balance.', 'status' => 422];
        }

        $receiptNo = dl_pos_nextReceiptNo($db, $branchId, $date);
        $ins = $db->prepare(
            "INSERT INTO dl_pos_sales
                (sale_uuid, client_operation_key, request_hash, sale_kind, refund_of_sale_id, branch_id,
                 ledger_date, cashier_id, receipt_no, status, item_count, subtotal_cents, discount_cents,
                 tax_cents, total_cents, refund_reason, completed_at)
             VALUES
                (:uuid, :opk, '', 'refund', :rid, :b, :d, :cid, :rn, 'completed', :ic, :sub, 0, 0, :tot, :reason, NOW())"
        );
        $ins->execute([
            ':uuid' => dl_generateMovementUuid(),
            ':opk' => $clientOpKey !== '' ? $clientOpKey : ('refund-' . $saleId . '-' . bin2hex(random_bytes(6))),
            ':rid' => $saleId,
            ':b' => $branchId, ':d' => $date, ':cid' => $actorId > 0 ? $actorId : (int)$sale['cashier_id'],
            ':rn' => $receiptNo, ':ic' => count($refundLines),
            ':sub' => $totals['total'], ':tot' => $totals['total'],
            ':reason' => substr($reason, 0, 255),
        ]);
        $refundId = (int)$db->lastInsertId();

        $itemIns = $db->prepare(
            'INSERT INTO dl_pos_sale_items
                (sale_id, product_id, product_name, sku, price_group_id, unit_price_cents, quantity, line_total_cents)
             VALUES (:sid, :pid, :name, :sku, :pg, :unit, :qty, :lt)'
        );
        foreach ($refundLines as $line) {
            $src = $line['snapshot'];
            $itemIns->execute([
                ':sid' => $refundId,
                ':pid' => $line['product_id'],
                ':name' => (string)$src['product_name'],
                ':sku' => (string)$src['sku'],
                ':pg' => $src['price_group_id'] !== null ? (int)$src['price_group_id'] : null,
                ':unit' => $line['unit_price_cents'],
                ':qty' => $line['quantity'],
                ':lt' => $line['quantity'] * $line['unit_price_cents'],
            ]);
        }

        // Refund tender row (money going back out), mirroring the refund total.
        $db->prepare(
            "INSERT INTO dl_pos_payments (sale_id, tender_method, amount_tendered_cents, amount_applied_cents, change_cents, reference)
             VALUES (:sid, 'cash', :amt, :amt2, 0, 'refund')"
        )->execute([':sid' => $refundId, ':amt' => $totals['total'], ':amt2' => $totals['total']]);

        // Update the original sale lifecycle status (lines/payments untouched).
        $fullRefund = true;
        foreach ($originalItems as $pid => $origQty) {
            $after = (int)($refundedSoFar[$pid] ?? 0);
            foreach ($refundLines as $line) {
                if ($line['product_id'] === $pid) { $after += $line['quantity']; }
            }
            if ($after < $origQty) { $fullRefund = false; break; }
        }
        $newStatus = $fullRefund ? 'refunded' : 'partially_refunded';
        $db->prepare('UPDATE dl_pos_sales SET status = :st, version = version + 1 WHERE id = :id')
            ->execute([':st' => $newStatus, ':id' => $saleId]);

        dl_pos_recordEvent($db, $refundId, 'refund_issued', $actorId, (string)($actor['role'] ?? ''), $reason, [
            'refund_of' => $saleId,
            'original_receipt_no' => (string)$sale['receipt_no'],
            'total_cents' => $totals['total'],
        ]);
        dl_pos_recordEvent($db, $saleId, 'refund_recorded', $actorId, (string)($actor['role'] ?? ''), $reason, [
            'refund_sale_id' => $refundId,
            'old_status' => (string)$sale['status'],
            'new_status' => $newStatus,
        ]);
        dl_auditLog('pos_sale_refunded', $branchId, 'dl_pos_sales', (string)$saleId,
            ['status' => (string)$sale['status']], ['status' => $newStatus, 'refund_sale_id' => $refundId], $reason);

        $refund = dl_pos_loadSale($db, $refundId);
        $db->commit();
        return ['ok' => true, 'code' => null, 'error' => null, 'status' => 200, 'refund' => $refund, 'idempotent_replay' => false];
    } catch (\InvalidArgumentException $e) {
        if ($db->inTransaction()) { $db->rollBack(); }
        return ['ok' => false, 'code' => 'REFUND_INVALID', 'error' => $e->getMessage(), 'status' => 422];
    } catch (\Throwable $e) {
        if ($db->inTransaction()) { $db->rollBack(); }
        write_log('daily-ledger POS refund failed: ' . $e->getMessage(), 'error', ['sale_id' => $saleId]);
        return ['ok' => false, 'code' => 'REFUND_FAILED', 'error' => 'Refund could not be completed.', 'status' => 500];
    }
}

// ═══════════════════════════════════════════════════════════════════════
// Fallback checkpoint (POS → manual, supervisor-authorized)
// ═══════════════════════════════════════════════════════════════════════

/**
 * Record the physical checkpoint and switch the day to fallback, atomically.
 * $counts: product_id => physical count (must cover every active branch product).
 */
function dl_pos_recordFallbackCheckpoint($db, int $branchId, string $date, array $counts, string $reason, int $actorId, ?int $expectedVersion): array
{
    $reason = trim($reason);
    if ($reason === '') {
        return ['ok' => false, 'code' => 'REASON_REQUIRED', 'error' => 'A fallback reason is required.', 'status' => 422];
    }

    $db->beginTransaction();
    try {
        if (dl_lockDayStatusRow($db, $branchId, $date) === 'closed') {
            $db->rollBack();
            return ['ok' => false, 'code' => 'DAY_CLOSED', 'error' => 'This business date is closed.', 'status' => 409];
        }

        $modeRow = dl_pos_getDayModeRow($db, $branchId, $date);
        if (!is_array($modeRow)) {
            $db->rollBack();
            return ['ok' => false, 'code' => 'MODE_NOT_POS', 'error' => 'Fallback requires an active POS day.', 'status' => 409];
        }
        $lock = $db->prepare('SELECT id, mode, version FROM dl_sales_day_modes WHERE id = :id FOR UPDATE');
        $lock->execute([':id' => (int)$modeRow['id']]);
        $locked = $lock->fetch(PDO::FETCH_ASSOC) ?: $modeRow;
        if ((string)$locked['mode'] !== 'pos') {
            $db->rollBack();
            return ['ok' => false, 'code' => 'MODE_NOT_POS', 'error' => 'Fallback requires an active POS day.', 'status' => 409];
        }
        if ($expectedVersion !== null && (int)$locked['version'] !== $expectedVersion) {
            $db->rollBack();
            return ['ok' => false, 'code' => 'VERSION_CONFLICT', 'error' => 'The day mode changed; refresh and retry.', 'status' => 409];
        }

        if (dl_pos_openDraftCount($db, $branchId, $date) > 0) {
            $db->rollBack();
            return ['ok' => false, 'code' => 'OPEN_CARTS', 'error' => 'Complete or abandon open carts before fallback.', 'status' => 422];
        }

        // Complete coverage of every active branch product; no negative counts.
        $catalog = dl_pos_branchProducts($db, $branchId, $date);
        $normalizedCounts = [];
        foreach ($counts as $pid => $count) {
            $pid = (int)$pid;
            if (is_array($count)) {
                $count = $count['physical_count'] ?? $count['count'] ?? null;
            }
            if ($pid <= 0 || $count === null || !is_numeric($count)) {
                $db->rollBack();
                return ['ok' => false, 'code' => 'INVALID_COUNT', 'error' => 'Every product requires a physical count.', 'status' => 422];
            }
            $count = (int)$count;
            if ($count < 0) {
                $db->rollBack();
                return ['ok' => false, 'code' => 'INVALID_COUNT', 'error' => 'Physical counts cannot be negative.', 'status' => 422];
            }
            $normalizedCounts[$pid] = $count;
        }
        foreach ($catalog as $pid => $product) {
            if (!array_key_exists($pid, $normalizedCounts)) {
                $db->rollBack();
                return ['ok' => false, 'code' => 'INCOMPLETE_CHECKPOINT', 'error' => 'Missing physical count for ' . $product['name'] . '.', 'status' => 422];
            }
        }

        // Current ledger addtl/withdraw snapshots at the checkpoint instant.
        $ledgerStmt = $db->prepare(
            'SELECT product_id, addtl, withdraw FROM dl_daily_ledger WHERE branch_id = :b AND ledger_date = :d'
        );
        $ledgerStmt->execute([':b' => $branchId, ':d' => $date]);
        $ledgerRows = [];
        foreach ($ledgerStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $ledgerRows[(int)$row['product_id']] = $row;
        }

        $ins = $db->prepare(
            'INSERT INTO dl_pos_fallback_checkpoints (branch_id, ledger_date, reason, recorded_by, recorded_at)
             VALUES (:b, :d, :r, :u, NOW())'
        );
        $ins->execute([':b' => $branchId, ':d' => $date, ':r' => substr($reason, 0, 255), ':u' => $actorId]);
        $checkpointId = (int)$db->lastInsertId();

        $itemIns = $db->prepare(
            'INSERT INTO dl_pos_fallback_checkpoint_items (checkpoint_id, product_id, physical_count, addtl_snapshot, withdraw_snapshot)
             VALUES (:c, :p, :count, :addtl, :withdraw)'
        );
        foreach ($normalizedCounts as $pid => $count) {
            $ledger = $ledgerRows[$pid] ?? ['addtl' => 0, 'withdraw' => 0];
            $itemIns->execute([
                ':c' => $checkpointId,
                ':p' => $pid,
                ':count' => $count,
                ':addtl' => (int)($ledger['addtl'] ?? 0),
                ':withdraw' => (int)($ledger['withdraw'] ?? 0),
            ]);
        }

        $db->prepare(
            "UPDATE dl_sales_day_modes
                SET mode = 'fallback', status = 'locked', version = version + 1,
                    fallback_at = NOW(), fallback_by = :u, fallback_reason = :r
              WHERE id = :id"
        )->execute([':u' => $actorId, ':r' => substr($reason, 0, 255), ':id' => (int)$locked['id']]);

        dl_auditLog('pos_fallback_checkpoint', $branchId, 'dl_pos_fallback_checkpoints', (string)$checkpointId, null, [
            'ledger_date' => $date,
            'product_count' => count($normalizedCounts),
            'recorded_by' => $actorId,
        ], $reason);

        $db->commit();
        return ['ok' => true, 'code' => null, 'error' => null, 'status' => 200, 'checkpoint_id' => $checkpointId];
    } catch (\Throwable $e) {
        if ($db->inTransaction()) { $db->rollBack(); }
        // Unique (branch_id, ledger_date) on checkpoints makes a second attempt a conflict.
        if (str_contains($e->getMessage(), 'uq_dl_pos_checkpoint') || str_contains($e->getMessage(), '1062')) {
            return ['ok' => false, 'code' => 'FALLBACK_ALREADY_RECORDED', 'error' => 'A fallback checkpoint already exists for this day.', 'status' => 409];
        }
        write_log('daily-ledger POS fallback failed: ' . $e->getMessage(), 'error', ['branch_id' => $branchId]);
        return ['ok' => false, 'code' => 'FALLBACK_FAILED', 'error' => 'Fallback could not be recorded.', 'status' => 500];
    }
}

// ═══════════════════════════════════════════════════════════════════════
// Canonical sales summaries (manual | pos | fallback)
// ═══════════════════════════════════════════════════════════════════════

/**
 * Net completed POS totals for a branch-day (sales minus refunds; voided
 * excluded). $upTo limits to sales completed at or before that timestamp.
 * Returns ['qty'=>int, 'amount_cents'=>int].
 */
function dl_pos_netTotals($db, int $branchId, string $date, ?string $upTo = null): array
{
    // No derived table in FROM: ModuleDB's table-access parser splits FROM
    // clauses on commas (including commas inside parens) and would misread
    // aliases like `s.id` as undeclared tables. Aggregation happens in PHP.
    $sql = "SELECT s.sale_kind,
                   (SELECT COALESCE(SUM(si.quantity),0) FROM dl_pos_sale_items si WHERE si.sale_id = s.id) AS qty,
                   s.total_cents AS amount_cents
              FROM dl_pos_sales s
             WHERE s.branch_id = :b AND s.ledger_date = :d
               AND s.status IN ('completed','partially_refunded','refunded')";
    $bind = [':b' => $branchId, ':d' => $date];
    if ($upTo !== null) {
        $sql .= " AND s.completed_at <= :upto";
        $bind[':upto'] = $upTo;
    }

    $stmt = $db->prepare($sql);
    $stmt->execute($bind);
    $qty = 0;
    $amount = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $sign = ((string)$row['sale_kind'] === 'refund') ? -1 : 1;
        $qty += $sign * (int)$row['qty'];
        $amount += $sign * (int)$row['amount_cents'];
    }
    return ['qty' => $qty, 'amount_cents' => $amount];
}

/** Full-day stock-derived totals (independent physical reconciliation). */
function dl_pos_stockDerivedTotals($db, int $branchId, string $date): array
{
    $qtyExpr = dl_ledgerSalesQuantitySql('dl');
    $amtExpr = dl_ledgerSalesAmountSql('dl');
    $stmt = $db->prepare(
        'SELECT COALESCE(SUM(' . $qtyExpr . '),0) AS qty,
                COALESCE(SUM(' . $amtExpr . '),0) AS amt
           FROM dl_daily_ledger dl WHERE branch_id = :b AND ledger_date = :d'
    );
    $stmt->execute([':b' => $branchId, ':d' => $date]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['qty' => 0, 'amt' => 0];
    return ['qty' => (int)$row['qty'], 'amount_cents' => (int)round(((float)$row['amt']) * 100)];
}

/**
 * Post-checkpoint manual segment for a fallback day: per product,
 * qty = max(0, checkpoint_count + (addtl - addtl_snapshot) - (withdraw - withdraw_snapshot) - bal_end).
 * Returns ['qty'=>int, 'amount_cents'=>int, 'rows'=>array].
 */
function dl_pos_fallbackManualSegment($db, int $branchId, string $date): array
{
    $cpStmt = $db->prepare(
        'SELECT id, recorded_at FROM dl_pos_fallback_checkpoints WHERE branch_id = :b AND ledger_date = :d LIMIT 1'
    );
    $cpStmt->execute([':b' => $branchId, ':d' => $date]);
    $checkpoint = $cpStmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($checkpoint)) {
        return ['qty' => 0, 'amount_cents' => 0, 'rows' => []];
    }

    $itemStmt = $db->prepare('SELECT * FROM dl_pos_fallback_checkpoint_items WHERE checkpoint_id = :c');
    $itemStmt->execute([':c' => (int)$checkpoint['id']]);
    $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $ledgerStmt = $db->prepare(
        'SELECT product_id, addtl, withdraw, bal_end, price_snapshot
           FROM dl_daily_ledger WHERE branch_id = :b AND ledger_date = :d'
    );
    $ledgerStmt->execute([':b' => $branchId, ':d' => $date]);
    $ledger = [];
    foreach ($ledgerStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $ledger[(int)$row['product_id']] = $row;
    }

    $totalQty = 0;
    $totalCents = 0;
    $rows = [];
    foreach ($items as $item) {
        $pid = (int)$item['product_id'];
        $cur = $ledger[$pid] ?? ['addtl' => 0, 'withdraw' => 0, 'bal_end' => 0, 'price_snapshot' => 0];
        $qty = dl_pos_computeFallbackSegmentQty(
            (int)$item['physical_count'],
            (int)$cur['addtl'] - (int)$item['addtl_snapshot'],
            (int)$cur['withdraw'] - (int)$item['withdraw_snapshot'],
            (int)$cur['bal_end']
        );
        $priceCents = (int)round(((float)$cur['price_snapshot']) * 100);
        $totalQty += $qty;
        $totalCents += $qty * $priceCents;
        $rows[] = ['product_id' => $pid, 'qty' => $qty, 'amount_cents' => $qty * $priceCents];
    }

    return ['qty' => $totalQty, 'amount_cents' => $totalCents, 'rows' => $rows];
}

/**
 * Canonical branch-day sales summary. Never sums POS and stock-derived totals
 * for the same segment; exposes source, POS total, calculated total, variance.
 */
function dl_pos_salesSummary($db, int $branchId, string $date): array
{
    $mode = dl_pos_dayMode($db, $branchId, $date);
    $stock = dl_pos_stockDerivedTotals($db, $branchId, $date);
    $pos = dl_pos_netTotals($db, $branchId, $date);

    $summary = [
        'branch_id' => $branchId,
        'ledger_date' => $date,
        'sales_source' => $mode['mode'],
        'mode_decided' => $mode['decided'],
        'pos_qty' => $pos['qty'],
        'pos_amount' => dl_pos_centsToFloat($pos['amount_cents']),
        'calculated_qty' => $stock['qty'],
        'calculated_amount' => dl_pos_centsToFloat($stock['amount_cents']),
    ];

    if ($mode['mode'] === 'fallback' && is_array($mode['row'])) {
        $checkpointAt = (string)($mode['row']['fallback_at'] ?? '');
        $posBefore = dl_pos_netTotals($db, $branchId, $date, $checkpointAt !== '' ? $checkpointAt : null);
        $manualAfter = dl_pos_fallbackManualSegment($db, $branchId, $date);
        $officialQty = $posBefore['qty'] + $manualAfter['qty'];
        $officialCents = $posBefore['amount_cents'] + $manualAfter['amount_cents'];
        $summary['official_qty'] = $officialQty;
        $summary['official_amount'] = dl_pos_centsToFloat($officialCents);
        $summary['fallback'] = [
            'checkpoint_at' => $checkpointAt,
            'pos_before_qty' => $posBefore['qty'],
            'pos_before_amount' => dl_pos_centsToFloat($posBefore['amount_cents']),
            'manual_after_qty' => $manualAfter['qty'],
            'manual_after_amount' => dl_pos_centsToFloat($manualAfter['amount_cents']),
        ];
        // Variance compares the POS segment against the full-day physical count.
        $summary['variance_qty'] = $pos['qty'] - $stock['qty'];
        $summary['variance_amount'] = dl_pos_centsToFloat($pos['amount_cents'] - $stock['amount_cents']);
        return $summary;
    }

    if ($mode['mode'] === 'pos') {
        $summary['official_qty'] = $pos['qty'];
        $summary['official_amount'] = dl_pos_centsToFloat($pos['amount_cents']);
    } else {
        $summary['official_qty'] = $stock['qty'];
        $summary['official_amount'] = dl_pos_centsToFloat($stock['amount_cents']);
    }
    $summary['variance_qty'] = $pos['qty'] - $stock['qty'];
    $summary['variance_amount'] = dl_pos_centsToFloat($pos['amount_cents'] - $stock['amount_cents']);
    return $summary;
}

/**
 * Day-close precheck for POS/fallback days. Returns an error payload
 * (['ok'=>false, ...]) when the close must be paused, or null to proceed.
 * Material variance requires an explicit acknowledgment; it never silently
 * blocks and never alters completed transactions.
 */
function dl_pos_dayClosePrecheck($db, int $branchId, string $date, array $input): ?array
{
    if (!dl_isPosEnabled()) {
        return null;
    }
    $mode = dl_pos_dayMode($db, $branchId, $date);
    if (!in_array($mode['mode'], ['pos', 'fallback'], true) || !$mode['decided']) {
        return null;
    }

    if (dl_pos_openDraftCount($db, $branchId, $date) > 0) {
        return ['ok' => false, 'code' => 'POS_OPEN_CARTS', 'error' => 'Complete or abandon open POS carts before closing the day.'];
    }

    $summary = dl_pos_salesSummary($db, $branchId, $date);
    $varianceCents = (int)round(((float)$summary['variance_amount']) * 100);
    if ($varianceCents !== 0 && !dl_settingToBool($input['acknowledge_variance'] ?? false)) {
        return [
            'ok' => false,
            'code' => 'POS_VARIANCE_ACK_REQUIRED',
            'error' => 'POS and stock-derived sales differ. Review the variance and confirm to close.',
            'summary' => $summary,
        ];
    }

    if ($varianceCents !== 0) {
        dl_auditLog('pos_close_variance_acknowledged', $branchId, 'dl_sales_day_modes', "{$branchId}-{$date}", null, [
            'variance_amount' => $summary['variance_amount'],
            'variance_qty' => $summary['variance_qty'],
            'sales_source' => $summary['sales_source'],
        ]);
    }

    return null;
}

/** Stamp close metadata on the mode row (best effort; day status governs). */
function dl_pos_markModeClosed($db, int $branchId, string $date, int $actorId): void
{
    try {
        $db->prepare(
            'UPDATE dl_sales_day_modes SET closed_by = :u, closed_at = NOW() WHERE branch_id = :b AND ledger_date = :d'
        )->execute([':u' => $actorId > 0 ? $actorId : null, ':b' => $branchId, ':d' => $date]);
    } catch (\Throwable $e) {
        // Non-fatal
    }
}

// ═══════════════════════════════════════════════════════════════════════
// HTTP layer — shared guards
// ═══════════════════════════════════════════════════════════════════════

/**
 * Shared POS API guard. Returns
 * ['ctx'=>, 'db'=>, 'user'=>, 'branch_id'=>int, 'date'=>string] or null after
 * emitting the error response. POS APIs always operate on the server-resolved
 * business date — the client may never supply it.
 */
function dl_pos_apiGuard(array $roles, string $permission): ?array
{
    $ctx = module();
    if (!$ctx) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Module context unavailable']);
        return null;
    }
    if (!dl_isPosEnabled()) {
        $ctx->json(['ok' => false, 'code' => 'POS_DISABLED', 'error' => 'POS is not enabled.'], 403);
        return null;
    }
    $user = dlCurrentUser($roles);
    if (!dl_pos_userCan($user, $permission)) {
        $ctx->json(['ok' => false, 'code' => 'FORBIDDEN', 'error' => 'You are not authorized for this POS action.'], 403);
        return null;
    }
    $input = $ctx->input();
    $authResult = dl_authorizeBranch($user, $input);
    if ($authResult['branch_id'] < 0) {
        $ctx->json(['ok' => false, 'code' => 'BRANCH_DENIED', 'error' => 'Branch not authorized'], 403);
        return null;
    }
    $branchId = (int)$authResult['branch_id'];
    if ($branchId <= 0) {
        $ctx->json(['ok' => false, 'code' => 'NO_BRANCH', 'error' => 'No branch assigned.'], 422);
        return null;
    }
    dl_maybeAutoCloseBranchDay($branchId, dl_getActorUserId($user));

    return [
        'ctx' => $ctx,
        'db' => $ctx->db(),
        'user' => $user,
        'branch_id' => $branchId,
        'date' => dl_businessDate(),
        'input' => $input,
    ];
}

/** Shape a loaded sale row into a receipt API payload (floats for display). */
function dl_pos_receiptPayload(array $sale): array
{
    $items = [];
    foreach ($sale['items'] ?? [] as $item) {
        $items[] = [
            'product_id' => (int)$item['product_id'],
            'name' => (string)$item['product_name'],
            'sku' => (string)$item['sku'],
            'quantity' => (int)$item['quantity'],
            'unit_price' => dl_pos_centsToFloat((int)$item['unit_price_cents']),
            'line_total' => dl_pos_centsToFloat((int)$item['line_total_cents']),
        ];
    }
    $payments = [];
    foreach ($sale['payments'] ?? [] as $payment) {
        $payments[] = [
            'method' => (string)$payment['tender_method'],
            'tendered' => dl_pos_centsToFloat((int)$payment['amount_tendered_cents']),
            'applied' => dl_pos_centsToFloat((int)$payment['amount_applied_cents']),
            'change' => dl_pos_centsToFloat((int)$payment['change_cents']),
        ];
    }
    return [
        'sale_id' => (int)$sale['id'],
        'sale_uuid' => (string)$sale['sale_uuid'],
        'sale_kind' => (string)$sale['sale_kind'],
        'status' => (string)$sale['status'],
        'receipt_no' => (string)$sale['receipt_no'],
        'branch_id' => (int)$sale['branch_id'],
        'branch_name' => (string)($sale['branch_name'] ?? ''),
        'ledger_date' => (string)$sale['ledger_date'],
        'cashier_name' => (string)($sale['cashier_name'] ?? ''),
        'subtotal' => dl_pos_centsToFloat((int)$sale['subtotal_cents']),
        'discount' => dl_pos_centsToFloat((int)$sale['discount_cents']),
        'tax' => dl_pos_centsToFloat((int)$sale['tax_cents']),
        'total' => dl_pos_centsToFloat((int)$sale['total_cents']),
        'completed_at' => (string)($sale['completed_at'] ?? ''),
        'items' => $items,
        'payments' => $payments,
    ];
}

// ═══════════════════════════════════════════════════════════════════════
// HTTP API handlers
// ═══════════════════════════════════════════════════════════════════════

/** GET /daily-ledger/api/v1/pos/state — mode, products, tenders, draft cart. */
function apiPosState(array $params = []): void
{
    $guard = dl_pos_apiGuard(['cashier', 'supervisor', 'admin'], 'pos.sell');
    if ($guard === null) { return; }
    $db = $guard['db'];
    $branchId = $guard['branch_id'];
    $date = $guard['date'];

    $mode = dl_pos_dayMode($db, $branchId, $date);
    $products = array_values(dl_pos_branchProducts($db, $branchId, $date));
    foreach ($products as &$product) {
        $product['unit_price'] = dl_pos_centsToFloat($product['unit_price_cents']);
        unset($product['unit_price_cents']);
    }
    unset($product);

    $draft = null;
    $draftStmt = $db->prepare(
        "SELECT id FROM dl_pos_sales
          WHERE branch_id = :b AND ledger_date = :d AND cashier_id = :c AND status = 'draft'
          ORDER BY id DESC LIMIT 1"
    );
    $draftStmt->execute([':b' => $branchId, ':d' => $date, ':c' => dl_getActorUserId($guard['user'])]);
    $draftId = $draftStmt->fetchColumn();
    if ($draftId !== false) {
        $draft = dl_pos_loadSale($db, (int)$draftId);
    }

    $guard['ctx']->json([
        'ok' => true,
        'mode' => $mode['mode'],
        'mode_decided' => $mode['decided'],
        'mode_version' => is_array($mode['row']) ? (int)$mode['row']['version'] : 0,
        'day_status' => dl_getDayStatus($branchId, $date),
        'ledger_date' => $date,
        'products' => $products,
        'allowed_tenders' => dl_pos_allowedTenders(),
        'draft_cart' => $draft !== null ? dl_pos_receiptPayload($draft) : null,
        'permissions' => [
            'sell' => true,
            'void' => dl_pos_userCan($guard['user'], 'pos.void'),
            'refund' => dl_pos_userCan($guard['user'], 'pos.refund'),
            'fallback' => dl_pos_userCan($guard['user'], 'pos.fallback'),
            'report' => dl_pos_userCan($guard['user'], 'pos.report'),
        ],
    ]);
}

/** POST /daily-ledger/api/v1/pos/mode/select */
function apiPosSelectMode(array $params = []): void
{
    $guard = dl_pos_apiGuard(['cashier', 'supervisor', 'admin'], 'pos.sell');
    if ($guard === null) { return; }
    $input = $guard['input'];

    $mode = dl_pos_normalizeMode($input['mode'] ?? '');
    if ($mode === null || $mode === 'fallback') {
        $guard['ctx']->json(['ok' => false, 'code' => 'INVALID_MODE', 'error' => 'Mode must be manual or pos.'], 422);
        return;
    }
    $expectedVersion = isset($input['expected_version']) && $input['expected_version'] !== ''
        ? (int)$input['expected_version'] : null;

    $result = dl_pos_selectMode(
        $guard['db'], $guard['branch_id'], $guard['date'], $mode,
        dl_getActorUserId($guard['user']), $expectedVersion
    );
    if (!$result['ok']) {
        $status = in_array($result['code'], ['DAY_CLOSED', 'VERSION_CONFLICT', 'MODE_FALLBACK_FINAL', 'MODE_LOCKED_MANUAL_ACTIVITY', 'MODE_LOCKED_POS_ACTIVITY', 'MODE_SWITCH_REQUIRES_FALLBACK'], true) ? 409 : 422;
        $guard['ctx']->json(['ok' => false, 'code' => $result['code'], 'error' => $result['error']], $status);
        return;
    }
    $row = $result['mode_row'] ?? [];
    $guard['ctx']->json(['ok' => true, 'mode' => (string)($row['mode'] ?? $mode), 'mode_version' => (int)($row['version'] ?? 1)]);
}

/** POST /daily-ledger/api/v1/pos/cart/save — upsert the cashier's draft cart. */
function apiPosSaveCart(array $params = []): void
{
    $guard = dl_pos_apiGuard(['cashier', 'supervisor', 'admin'], 'pos.sell');
    if ($guard === null) { return; }
    $db = $guard['db'];
    $branchId = $guard['branch_id'];
    $date = $guard['date'];
    $input = $guard['input'];
    $userId = dl_getActorUserId($guard['user']);

    $clientOpKey = substr(trim((string)($input['client_operation_key'] ?? '')), 0, 80);
    $lines = is_array($input['lines'] ?? null) ? array_values($input['lines']) : [];
    if ($clientOpKey === '') {
        $guard['ctx']->json(['ok' => false, 'code' => 'IDEMPOTENCY_KEY_REQUIRED', 'error' => 'client_operation_key is required.'], 422);
        return;
    }
    if (dl_getDayStatus($branchId, $date) === 'closed') {
        $guard['ctx']->json(['ok' => false, 'code' => 'DAY_CLOSED', 'error' => 'This business date is closed.'], 409);
        return;
    }

    $catalog = dl_pos_branchProducts($db, $branchId, $date);
    $db->beginTransaction();
    try {
        $stmt = $db->prepare(
            'SELECT id, status FROM dl_pos_sales WHERE branch_id = :b AND client_operation_key = :k LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([':b' => $branchId, ':k' => $clientOpKey]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($existing) && (string)$existing['status'] !== 'draft') {
            $db->rollBack();
            $guard['ctx']->json(['ok' => false, 'code' => 'CART_STATE_CONFLICT', 'error' => 'This cart was already completed.'], 409);
            return;
        }

        if (is_array($existing)) {
            $saleId = (int)$existing['id'];
            $db->prepare('DELETE FROM dl_pos_sale_items WHERE sale_id = :id')->execute([':id' => $saleId]);
        } else {
            $db->prepare(
                "INSERT INTO dl_pos_sales (sale_uuid, client_operation_key, branch_id, ledger_date, cashier_id, receipt_no, status)
                 VALUES (:uuid, :k, :b, :d, :c, :rn, 'draft')"
            )->execute([
                ':uuid' => dl_generateMovementUuid(),
                ':k' => $clientOpKey,
                ':b' => $branchId, ':d' => $date, ':c' => $userId,
                ':rn' => 'DRAFT-' . substr(md5($clientOpKey), 0, 12),
            ]);
            $saleId = (int)$db->lastInsertId();
        }

        $itemIns = $db->prepare(
            'INSERT INTO dl_pos_sale_items (sale_id, product_id, product_name, sku, price_group_id, unit_price_cents, quantity, line_total_cents)
             VALUES (:sid, :pid, :name, :sku, :pg, :unit, :qty, :lt)'
        );
        foreach ($lines as $line) {
            $pid = (int)($line['product_id'] ?? 0);
            $qty = (int)($line['quantity'] ?? 0);
            if ($pid <= 0 || $qty <= 0) { continue; }
            if (!isset($catalog[$pid])) {
                $db->rollBack();
                $guard['ctx']->json(['ok' => false, 'code' => 'PRODUCT_UNAVAILABLE', 'error' => 'Product #' . $pid . ' is not available at this branch.'], 422);
                return;
            }
            $product = $catalog[$pid];
            $itemIns->execute([
                ':sid' => $saleId, ':pid' => $pid,
                ':name' => $product['name'], ':sku' => $product['sku'], ':pg' => $product['price_group_id'],
                ':unit' => $product['unit_price_cents'], ':qty' => $qty,
                ':lt' => $product['unit_price_cents'] * $qty,
            ]);
        }
        $db->commit();
        $guard['ctx']->json(['ok' => true, 'cart_id' => $saleId]);
    } catch (\Throwable $e) {
        if ($db->inTransaction()) { $db->rollBack(); }
        write_log('daily-ledger POS cart save failed: ' . $e->getMessage(), 'error', ['branch_id' => $branchId]);
        $guard['ctx']->json(['ok' => false, 'code' => 'CART_SAVE_FAILED', 'error' => 'Cart could not be saved.'], 500);
    }
}

/** POST /daily-ledger/api/v1/pos/cart/abandon — discard a draft cart. */
function apiPosAbandonCart(array $params = []): void
{
    $guard = dl_pos_apiGuard(['cashier', 'supervisor', 'admin'], 'pos.sell');
    if ($guard === null) { return; }
    $db = $guard['db'];
    $input = $guard['input'];

    $clientOpKey = substr(trim((string)($input['client_operation_key'] ?? '')), 0, 80);
    if ($clientOpKey === '') {
        $guard['ctx']->json(['ok' => false, 'code' => 'IDEMPOTENCY_KEY_REQUIRED', 'error' => 'client_operation_key is required.'], 422);
        return;
    }
    $stmt = $db->prepare(
        "SELECT id, status FROM dl_pos_sales WHERE branch_id = :b AND client_operation_key = :k LIMIT 1"
    );
    $stmt->execute([':b' => $guard['branch_id'], ':k' => $clientOpKey]);
    $cart = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($cart)) {
        $guard['ctx']->json(['ok' => true, 'abandoned' => false]);
        return;
    }
    if ((string)$cart['status'] !== 'draft') {
        $guard['ctx']->json(['ok' => false, 'code' => 'CART_STATE_CONFLICT', 'error' => 'Completed sales cannot be abandoned; use void or refund.'], 409);
        return;
    }
    // Draft carts are not business evidence — rows are removed on abandon.
    $db->beginTransaction();
    try {
        $db->prepare('DELETE FROM dl_pos_payments WHERE sale_id = :id')->execute([':id' => (int)$cart['id']]);
        $db->prepare('DELETE FROM dl_pos_sale_items WHERE sale_id = :id')->execute([':id' => (int)$cart['id']]);
        $db->prepare('DELETE FROM dl_pos_sale_events WHERE sale_id = :id')->execute([':id' => (int)$cart['id']]);
        $db->prepare("DELETE FROM dl_pos_sales WHERE id = :id AND status = 'draft'")->execute([':id' => (int)$cart['id']]);
        $db->commit();
    } catch (\Throwable $e) {
        if ($db->inTransaction()) { $db->rollBack(); }
        write_log('daily-ledger POS cart abandon failed: ' . $e->getMessage(), 'error', ['branch_id' => $guard['branch_id']]);
        $guard['ctx']->json(['ok' => false, 'code' => 'CART_ABANDON_FAILED', 'error' => 'Cart could not be abandoned.'], 500);
        return;
    }
    dl_auditLog('pos_cart_abandoned', $guard['branch_id'], 'dl_pos_sales', (string)$cart['id']);
    $guard['ctx']->json(['ok' => true, 'abandoned' => true]);
}

/** POST /daily-ledger/api/v1/pos/checkout */
function apiPosCheckout(array $params = []): void
{
    $guard = dl_pos_apiGuard(['cashier', 'supervisor', 'admin'], 'pos.sell');
    if ($guard === null) { return; }
    $input = $guard['input'];

    $result = dl_pos_checkout($guard['db'], [
        'branch_id' => $guard['branch_id'],
        'ledger_date' => $guard['date'],
        'cashier' => $guard['user'],
        'lines' => $input['lines'] ?? [],
        'payments' => $input['payments'] ?? [],
        'client_operation_key' => $input['client_operation_key'] ?? '',
        'expected_version' => $input['expected_version'] ?? null,
        'order_discount_cents' => $input['order_discount_cents'] ?? 0,
    ]);

    if (!$result['ok']) {
        $guard['ctx']->json(array_filter([
            'ok' => false,
            'code' => $result['code'],
            'error' => $result['error'],
            'current_price' => $result['current_price'] ?? null,
            'product_id' => $result['product_id'] ?? null,
        ], static fn ($v) => $v !== null), (int)($result['status'] ?? 422));
        return;
    }
    // Receipt is returned only after commit — a success response means durable.
    $guard['ctx']->json([
        'ok' => true,
        'idempotent_replay' => (bool)$result['idempotent_replay'],
        'receipt' => dl_pos_receiptPayload($result['sale']),
    ]);
}

/** POST /daily-ledger/api/v1/pos/sales/void */
function apiPosVoidSale(array $params = []): void
{
    $guard = dl_pos_apiGuard(['supervisor', 'admin'], 'pos.void');
    if ($guard === null) { return; }
    $input = $guard['input'];
    $saleId = (int)($input['sale_id'] ?? 0);
    if ($saleId <= 0) {
        $guard['ctx']->json(['ok' => false, 'code' => 'INVALID_SALE', 'error' => 'sale_id is required.'], 422);
        return;
    }
    // Branch scoping: the sale must belong to an accessible branch.
    $sale = dl_pos_loadSale($guard['db'], $saleId);
    if (!is_array($sale) || !in_array((int)$sale['branch_id'], dl_accessibleBranchIds($guard['user']), true)) {
        $guard['ctx']->json(['ok' => false, 'code' => 'NOT_FOUND', 'error' => 'Sale not found.'], 404);
        return;
    }
    $result = dl_pos_voidSale($guard['db'], $saleId, $guard['user'], (string)($input['reason'] ?? ''));
    if (!$result['ok']) {
        $guard['ctx']->json(['ok' => false, 'code' => $result['code'], 'error' => $result['error']], (int)$result['status']);
        return;
    }
    $guard['ctx']->json(['ok' => true, 'receipt' => dl_pos_receiptPayload($result['sale'])]);
}

/** POST /daily-ledger/api/v1/pos/sales/refund */
function apiPosRefundSale(array $params = []): void
{
    $guard = dl_pos_apiGuard(['supervisor', 'admin'], 'pos.refund');
    if ($guard === null) { return; }
    $input = $guard['input'];
    $saleId = (int)($input['sale_id'] ?? 0);
    $lines = is_array($input['lines'] ?? null) ? array_values($input['lines']) : [];
    if ($saleId <= 0) {
        $guard['ctx']->json(['ok' => false, 'code' => 'INVALID_SALE', 'error' => 'sale_id is required.'], 422);
        return;
    }
    $sale = dl_pos_loadSale($guard['db'], $saleId);
    if (!is_array($sale) || !in_array((int)$sale['branch_id'], dl_accessibleBranchIds($guard['user']), true)) {
        $guard['ctx']->json(['ok' => false, 'code' => 'NOT_FOUND', 'error' => 'Sale not found.'], 404);
        return;
    }
    $result = dl_pos_refundSale(
        $guard['db'], $saleId, $guard['user'], $lines,
        (string)($input['reason'] ?? ''), (string)($input['client_operation_key'] ?? '')
    );
    if (!$result['ok']) {
        $guard['ctx']->json(['ok' => false, 'code' => $result['code'], 'error' => $result['error']], (int)$result['status']);
        return;
    }
    $guard['ctx']->json([
        'ok' => true,
        'idempotent_replay' => (bool)($result['idempotent_replay'] ?? false),
        'refund' => dl_pos_receiptPayload($result['refund']),
    ]);
}

/** POST /daily-ledger/api/v1/pos/fallback — supervisor physical checkpoint. */
function apiPosFallbackCheckpoint(array $params = []): void
{
    $guard = dl_pos_apiGuard(['supervisor', 'admin'], 'pos.fallback');
    if ($guard === null) { return; }
    $input = $guard['input'];

    $counts = is_array($input['counts'] ?? null) ? $input['counts'] : [];
    $expectedVersion = isset($input['expected_version']) && $input['expected_version'] !== ''
        ? (int)$input['expected_version'] : null;

    $result = dl_pos_recordFallbackCheckpoint(
        $guard['db'], $guard['branch_id'], $guard['date'], $counts,
        (string)($input['reason'] ?? ''), dl_getActorUserId($guard['user']), $expectedVersion
    );
    if (!$result['ok']) {
        $guard['ctx']->json(['ok' => false, 'code' => $result['code'], 'error' => $result['error']], (int)$result['status']);
        return;
    }
    $guard['ctx']->json(['ok' => true, 'checkpoint_id' => (int)$result['checkpoint_id'], 'mode' => 'fallback']);
}

/** GET /daily-ledger/api/v1/pos/sales — filtered list (pos.report). */
function apiPosSalesList(array $params = []): void
{
    $guard = dl_pos_apiGuard(['supervisor', 'admin'], 'pos.report');
    if ($guard === null) { return; }
    $guard['ctx']->json(['ok' => true, 'sales' => dl_pos_querySales($guard['db'], $guard['user'], $_GET)]);
}

/** GET /daily-ledger/api/v1/pos/sales/detail */
function apiPosSaleDetail(array $params = []): void
{
    $guard = dl_pos_apiGuard(['supervisor', 'admin'], 'pos.report');
    if ($guard === null) { return; }
    $saleId = (int)($_GET['sale_id'] ?? 0);
    $sale = $saleId > 0 ? dl_pos_loadSale($guard['db'], $saleId) : null;
    if (!is_array($sale) || !in_array((int)$sale['branch_id'], dl_accessibleBranchIds($guard['user']), true)) {
        $guard['ctx']->json(['ok' => false, 'code' => 'NOT_FOUND', 'error' => 'Sale not found.'], 404);
        return;
    }
    $events = $guard['db']->prepare('SELECT event_type, actor_role, reason, created_at FROM dl_pos_sale_events WHERE sale_id = :id ORDER BY id');
    $events->execute([':id' => $saleId]);
    $payload = dl_pos_receiptPayload($sale);
    $payload['events'] = $events->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $guard['ctx']->json(['ok' => true, 'sale' => $payload]);
}

/**
 * Shared filtered POS sales query (admin page, API, CSV export).
 * Enforces accessible-branch scoping for the actor.
 */
function dl_pos_querySales($db, array $user, array $filters): array
{
    $accessible = dl_accessibleBranchIds($user);
    if ($accessible === []) { $accessible = [0]; }
    $placeholders = implode(',', array_fill(0, count($accessible), '?'));

    $sql = "SELECT s.id, s.receipt_no, s.sale_kind, s.status, s.ledger_date, s.total_cents,
                   s.item_count, s.completed_at, b.name AS branch_name, u.full_name AS cashier_name,
                   (SELECT GROUP_CONCAT(p.tender_method) FROM dl_pos_payments p WHERE p.sale_id = s.id) AS tenders
              FROM dl_pos_sales s
              INNER JOIN dl_branches b ON b.id = s.branch_id
              LEFT JOIN dl_users u ON u.id = s.cashier_id
             WHERE s.branch_id IN ({$placeholders}) AND s.status <> 'draft'";
    $bind = $accessible;

    $branchId = (int)($filters['branch_id'] ?? 0);
    if ($branchId > 0 && in_array($branchId, $accessible, true)) {
        $sql .= ' AND s.branch_id = ?';
        $bind[] = $branchId;
    }
    foreach (['date_from' => '>=', 'date_to' => '<='] as $key => $op) {
        $value = trim((string)($filters[$key] ?? ''));
        if ($value !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            $sql .= " AND s.ledger_date {$op} ?";
            $bind[] = $value;
        }
    }
    $cashierId = (int)($filters['cashier_id'] ?? 0);
    if ($cashierId > 0) {
        $sql .= ' AND s.cashier_id = ?';
        $bind[] = $cashierId;
    }
    $status = strtolower(trim((string)($filters['status'] ?? '')));
    if (in_array($status, ['completed', 'voided', 'partially_refunded', 'refunded'], true)) {
        $sql .= ' AND s.status = ?';
        $bind[] = $status;
    }
    $tender = strtolower(trim((string)($filters['tender'] ?? '')));
    if ($tender !== '' && preg_match('/^[a-z0-9_\-]+$/', $tender)) {
        $sql .= ' AND EXISTS (SELECT 1 FROM dl_pos_payments tp WHERE tp.sale_id = s.id AND tp.tender_method = ?)';
        $bind[] = $tender;
    }
    $sql .= ' ORDER BY s.completed_at DESC, s.id DESC LIMIT 500';

    $stmt = $db->prepare($sql);
    $stmt->execute($bind);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$row) {
        $row['total'] = dl_pos_centsToFloat((int)$row['total_cents']);
        unset($row['total_cents']);
    }
    unset($row);
    return $rows;
}

// ═══════════════════════════════════════════════════════════════════════
// Page handlers
// ═══════════════════════════════════════════════════════════════════════

/** GET /daily-ledger/pos — cashier POS screen. */
function handleCashierPos(array $params = []): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); echo 'Module context unavailable'; return; }

    $user = dlCurrentUser(['cashier', 'supervisor', 'admin']);
    $role = (string)($user['role'] ?? '');
    $input = $ctx->input();
    $authResult = dl_authorizeBranch($user, $input);
    if ($authResult['branch_id'] < 0) {
        dl_denyBranch('Branch not authorized');
    }
    $branchId = (int)$authResult['branch_id'];
    $date = dl_businessDate();
    dl_maybeAutoCloseBranchDay($branchId, dl_getActorUserId($user));

    $posEnabled = dl_isPosEnabled();
    $canSell = $posEnabled && dl_pos_userCan($user, 'pos.sell');
    $db = $ctx->db();

    $mode = $branchId > 0 ? dl_pos_dayMode($db, $branchId, $date) : ['mode' => 'manual', 'row' => null, 'decided' => false];
    $summary = ($branchId > 0 && $posEnabled) ? dl_pos_salesSummary($db, $branchId, $date) : null;

    $userName = (string)($user['name'] ?? $user['full_name'] ?? $user['username'] ?? 'User');
    $clockLabel = dl_operatingClockLabel();
    echo dlRender('modules/daily-ledger/cashier/pos.disyl', [
        'page_title' => 'Point of Sale',
        'user_name' => $userName,
        'user_role' => $role,
        'current_page' => 'pos',
        'base_url' => dlGetBaseUrl(),
        'dl_token' => (string)kernelCookie(dlCookieName(), ''),
        'csrf_token' => app()->csrfToken(),
        'branch_id' => $branchId,
        'branch_name' => $branchId > 0 ? dl_getBranchName($branchId) : '',
        'ledger_date' => $date,
        'day_status' => $branchId > 0 ? dl_getDayStatus($branchId, $date) : 'open',
        'pos_enabled' => $posEnabled,
        'can_sell' => $canSell,
        'can_void' => dl_pos_userCan($user, 'pos.void'),
        'can_refund' => dl_pos_userCan($user, 'pos.refund'),
        'can_fallback' => dl_pos_userCan($user, 'pos.fallback'),
        'sales_mode' => $mode['mode'],
        'mode_decided' => $mode['decided'],
        'mode_version' => is_array($mode['row']) ? (int)$mode['row']['version'] : 0,
        'summary' => $summary,
        'business_date_label' => $clockLabel['business_date'],
        'close_of_day_time' => $clockLabel['close_of_day_time'],
        'auto_close_enabled' => $clockLabel['auto_close_enabled'],
        'operating_timezone' => $clockLabel['operating_timezone'],
        'operating_region' => $clockLabel['operating_region'],
    ]);
}

/** GET /daily-ledger/pos/receipt — printer-friendly receipt. */
function handlePosReceipt(array $params = []): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); echo 'Module context unavailable'; return; }

    $user = dlCurrentUser(['cashier', 'supervisor', 'admin']);
    $saleId = (int)($_GET['sale_id'] ?? 0);
    $sale = $saleId > 0 ? dl_pos_loadSale($ctx->db(), $saleId) : null;
    if (!is_array($sale) || !in_array((int)$sale['branch_id'], dl_accessibleBranchIds($user), true)) {
        http_response_code(404);
        echo 'Receipt not found';
        return;
    }

    echo dlRender('modules/daily-ledger/cashier/pos-receipt.disyl', [
        'page_title' => 'Receipt ' . (string)$sale['receipt_no'],
        'user_name' => (string)($user['name'] ?? $user['full_name'] ?? $user['username'] ?? 'User'),
        'user_role' => (string)($user['role'] ?? ''),
        'current_page' => 'pos',
        'base_url' => dlGetBaseUrl(),
        'dl_token' => (string)kernelCookie(dlCookieName(), ''),
        'sale' => dl_pos_receiptPayload($sale),
    ]);
}

/** GET /daily-ledger/admin/pos-sales — supervisor/admin POS reporting. */
function handleAdminPosSales(array $params = []): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); echo 'Module context unavailable'; return; }

    $user = dlCurrentUser(['admin', 'supervisor']);
    $role = (string)($user['role'] ?? '');
    if (!dl_pos_userCan($user, 'pos.report')) {
        http_response_code(403);
        echo 'Forbidden';
        return;
    }
    $input = $ctx->input();
    $db = $ctx->db();

    $accessible = dl_accessibleBranchIds($user);
    if ($accessible === []) { $accessible = [0]; }
    $placeholders = implode(',', array_fill(0, count($accessible), '?'));
    $branchStmt = $db->prepare("SELECT id, code, name FROM dl_branches WHERE is_active = 1 AND id IN ({$placeholders}) ORDER BY name");
    $branchStmt->execute($accessible);
    $branches = $branchStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $sales = dl_isPosEnabled() ? dl_pos_querySales($db, $user, is_array($input) ? $input : []) : [];

    $userName = (string)($user['name'] ?? $user['full_name'] ?? $user['username'] ?? 'User');
    $clockLabel = dl_operatingClockLabel();
    echo dlRender('modules/daily-ledger/admin/pos-sales.disyl', [
        'page_title' => 'POS Sales',
        'user_name' => $userName,
        'user_role' => $role,
        'current_page' => 'pos_sales',
        'base_url' => dlGetBaseUrl(),
        'dl_token' => (string)kernelCookie(dlCookieName(), ''),
        'branches' => $branches,
        'sales' => $sales,
        'pos_enabled' => dl_isPosEnabled(),
        'can_void' => dl_pos_userCan($user, 'pos.void'),
        'can_refund' => dl_pos_userCan($user, 'pos.refund'),
        'filter_branch_id' => (int)($input['branch_id'] ?? 0),
        'filter_date_from' => trim((string)($input['date_from'] ?? '')),
        'filter_date_to' => trim((string)($input['date_to'] ?? '')),
        'filter_status' => trim((string)($input['status'] ?? '')),
        'filter_tender' => trim((string)($input['tender'] ?? '')),
        'business_date_label' => $clockLabel['business_date'],
        'close_of_day_time' => $clockLabel['close_of_day_time'],
        'auto_close_enabled' => $clockLabel['auto_close_enabled'],
        'operating_timezone' => $clockLabel['operating_timezone'],
        'operating_region' => $clockLabel['operating_region'],
    ]);
}

/** GET /daily-ledger/admin/pos-sales/export — CSV export (pos.report). */
function handleAdminPosSalesExport(array $params = []): void
{
    $ctx = module();
    if (!$ctx) { http_response_code(500); return; }

    $user = dlCurrentUser(['admin', 'supervisor']);
    if (!dl_pos_userCan($user, 'pos.report')) {
        http_response_code(403);
        echo 'Forbidden';
        return;
    }
    $rows = dl_pos_querySales($ctx->db(), $user, $_GET);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="pos-sales-' . date('Ymd-His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Receipt No', 'Date', 'Branch', 'Cashier', 'Kind', 'Status', 'Items', 'Tenders', 'Total']);
    foreach ($rows as $row) {
        fputcsv($out, [
            $row['receipt_no'], $row['ledger_date'], $row['branch_name'], $row['cashier_name'],
            $row['sale_kind'], $row['status'], $row['item_count'], (string)($row['tenders'] ?? ''),
            number_format((float)$row['total'], 2, '.', ''),
        ]);
    }
    fclose($out);
}
