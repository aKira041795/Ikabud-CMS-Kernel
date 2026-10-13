<?php

declare(strict_types=1);

/**
 * Production Daily Sheet ADDTL — the production user's correction path.
 *
 * Owner report (2026-10-13): "additional cannot be corrected … this is at
 * production user level. admin can always edit all." A positive addition was
 * the only write the modal allowed (quantity had to be one or more), so an
 * amount keyed too high could not be taken back by the production account —
 * while the branch cells on the same sheet already accept a signed correction.
 *
 * Each assertion names its revert failure:
 * - restoring `quantity <= 0` refusal removes the production correction path;
 * - removing the reason check lets an unattributed reduction through;
 * - removing the floor check (or clamping to zero) lets the stored column
 *   disagree with the figure the operator was shown;
 * - removing the client_op_id guard writes a replay twice;
 * - dropping the movement insert leaves no append-only trail (the original
 *   entry would be overwritten or the correction invisible in the log).
 *
 * The actor is a plain production_in_charge user. The correction consults NO
 * permission — the same rule the branch cell follows — so it works on tenants
 * that grant production.override to production staff and on tenants that do
 * not (production.override governs the in-place log edit, a separate path).
 */
ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-production-addition-correction', TestHarness::MODE_INTEGRATION, 'baronledger.test');
ob_end_clean();

$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/helpers.php';
require_once $base . '/modules/daily-ledger/handlers-deliveries.php';
require_once $base . '/modules/daily-ledger/handlers.php';
app()->tenant()->setTenantId(207);
$ctx = modulePushContext('daily-ledger');
$db = $ctx->db();

$branchId = 18;          // the Rizal commissary used by the sibling suites
$productId = 99885;
$userId = 99886;
$date = '2020-07-09';

$cleanup = static function () use ($db, $branchId, $productId, $userId, $date): void {
    $db->prepare('DELETE FROM audit_logs WHERE module = "daily-ledger" AND branch_id = :b AND (entity_id LIKE :p OR new_data LIKE :j)')
        ->execute([':b' => $branchId, ':p' => "%{$productId}%", ':j' => "%{$productId}%"]);
    $db->prepare('DELETE FROM dl_production_movements WHERE product_id = :p')->execute([':p' => $productId]);
    $db->prepare('DELETE FROM dl_production_runs WHERE product_id = :p')->execute([':p' => $productId]);
    $db->prepare('DELETE FROM dl_commissary_product_ledger WHERE product_id = :p')->execute([':p' => $productId]);
    $db->prepare('DELETE FROM dl_ledger_shift_status WHERE branch_id = :b AND ledger_date = :d')->execute([':b' => $branchId, ':d' => $date]);
    $db->prepare('DELETE FROM dl_branch_products WHERE product_id = :p')->execute([':p' => $productId]);
    $db->prepare('DELETE FROM dl_products WHERE id = :p')->execute([':p' => $productId]);
    $db->prepare('DELETE FROM dl_user_branches WHERE user_id = :u')->execute([':u' => $userId]);
    $db->prepare('DELETE FROM dl_users WHERE id = :u')->execute([':u' => $userId]);
};

$cleanup();
try {
    $db->prepare('INSERT INTO dl_users (id, username, password_hash, full_name, role, shift, is_active) VALUES (:id, :username, :password, :name, "production_in_charge", "AM", 1)')
        ->execute([':id' => $userId, ':username' => 'fixture-addtl-corrector', ':password' => 'not-a-login-hash', ':name' => 'Fixture Addtl Corrector']);
    $db->prepare('INSERT INTO dl_user_branches (user_id, branch_id) VALUES (:u, :b)')
        ->execute([':u' => $userId, ':b' => $branchId]);
    $db->prepare('INSERT INTO dl_products (id, sku, name, current_price, sort_order, is_active) VALUES (:id, :sku, :name, 1, 99885, 1)')
        ->execute([':id' => $productId, ':sku' => 'FIX-ADDTCOR-99885', ':name' => 'Fixture Addtl Correction Product']);
    $db->prepare('INSERT INTO dl_branch_products (branch_id, product_id, is_active) VALUES (:b, :p, 1)')
        ->execute([':b' => $branchId, ':p' => $productId]);

    $user = [
        'id' => $userId,
        'sub' => 'production_in_charge:' . $userId,
        'role' => 'production_in_charge',
        'source' => 'daily-ledger',
        'full_name' => 'Fixture Addtl Corrector',
    ];

    $produced = static function (string $shift = 'AM') use ($db, $branchId, $productId, $date): int {
        $stmt = $db->prepare('SELECT produced_qty FROM dl_commissary_product_ledger WHERE commissary_branch_id = :b AND product_id = :p AND ledger_date = :d AND shift = :s');
        $stmt->execute([':b' => $branchId, ':p' => $productId, ':d' => $date, ':s' => $shift]);
        $value = $stmt->fetchColumn();
        return $value === false ? -1 : (int)$value;
    };
    $movementCount = static function () use ($db, $productId): int {
        return (int)$db->query('SELECT COUNT(*) FROM dl_production_movements WHERE product_id = ' . $productId)->fetchColumn();
    };
    $runYield = static function () use ($db, $branchId, $productId, $date): ?int {
        $stmt = $db->prepare('SELECT yield_qty FROM dl_production_runs WHERE ledger_date = :d AND product_id = :p AND destination_branch_id = :b ORDER BY id DESC LIMIT 1');
        $stmt->execute([':d' => $date, ':p' => $productId, ':b' => $branchId]);
        $value = $stmt->fetchColumn();
        return $value === false ? null : (int)$value;
    };
    $refusal = static function (callable $call): array {
        try {
            $call();
        } catch (RuntimeException $e) {
            return ['message' => $e->getMessage(), 'code' => $e->getCode()];
        }
        return ['message' => '', 'code' => 0];
    };

    // The correction must not depend on any permission: scope the assertion to
    // the function itself so a mention elsewhere in the file cannot satisfy it.
    $handlersSource = file_get_contents($base . '/modules/daily-ledger/handlers.php');
    $additionStart = strpos($handlersSource, 'function dl_recordProductionAddition(');
    $additionEnd = $additionStart === false ? false : strpos($handlersSource, "\n}\n", $additionStart);
    $additionBody = ($additionStart === false || $additionEnd === false)
        ? ''
        : substr($handlersSource, $additionStart, $additionEnd - $additionStart);
    $h->test(
        'the actor is in scope for this commissary',
        in_array($branchId, dl_accessibleBranchIds($user), true)
    );
    $h->test(
        'the correction consults no permission (the branch-cell rule; the in-place log edit stays the override path)',
        $additionBody !== ''
            && !str_contains($additionBody, 'dl_roleHasPermission')
            && !str_contains($additionBody, 'production.override')
            && str_contains($additionBody, 'Cannot reduce Additional below zero'),
        $additionBody === '' ? 'function body not located' : ''
    );

    // ── The additive path stays exactly as it was. ──────────────────────────
    $added = dl_recordProductionAddition($user, [
        'date' => $date,
        'shift' => 'AM',
        'commissary_branch_id' => $branchId,
        'product_id' => $productId,
        'quantity' => 10,
        'reason' => 'first batch',
        'submission_id' => 'fix-add-1',
    ]);
    $h->test('addition 10 still persists produced_qty', (int)$added['row']['produced_qty'] === 10 && $produced() === 10);
    $h->test('addition keeps the production-run yield capture', $runYield() === 10 && $movementCount() === 1);

    // ── The signed correction. ──────────────────────────────────────────────
    $reduced = dl_recordProductionAddition($user, [
        'date' => $date,
        'shift' => 'AM',
        'commissary_branch_id' => $branchId,
        'product_id' => $productId,
        'quantity' => -4,
        'reason' => 'keyed four too high',
        'submission_id' => 'fix-corr-1',
    ]);
    $h->test('a negative quantity reduces produced_qty (10 − 4 = 6)', (int)$reduced['row']['produced_qty'] === 6 && $produced() === 6);
    $h->test('the correction is appended as its own movement, not an overwrite', $movementCount() === 2);

    $latest = $db->prepare('SELECT client_op_id, movement_type, quantity, override_reason, reference_movement_id, shift, source_payload FROM dl_production_movements WHERE product_id = :p ORDER BY id DESC LIMIT 1');
    $latest->execute([':p' => $productId]);
    $latestRow = $latest->fetch(PDO::FETCH_ASSOC) ?: [];
    $payload = json_decode((string)($latestRow['source_payload'] ?? ''), true) ?: [];
    $h->test(
        'the correction row is a signed output with its reason, no reverse reference, correct shift',
        (int)($latestRow['quantity'] ?? 0) === -4
            && (string)($latestRow['movement_type'] ?? '') === 'output'
            && (string)($latestRow['override_reason'] ?? '') === 'keyed four too high'
            && $latestRow['reference_movement_id'] === null
            && (string)($latestRow['shift'] ?? '') === 'AM'
            && str_starts_with((string)($latestRow['client_op_id'] ?? ''), 'sheet-addtl-')
            && (int)($payload['before_produced'] ?? -1) === 10
            && (int)($payload['after_produced'] ?? -1) === 6,
        json_encode($latestRow)
    );

    $originalKept = $db->prepare('SELECT COUNT(*) FROM dl_production_movements WHERE product_id = :p AND quantity = 10 AND movement_type = "output"');
    $originalKept->execute([':p' => $productId]);
    $h->test('the original entry is never erased', (int)$originalKept->fetchColumn() === 1);
    $h->test('the production run mirrors the corrected level', $runYield() === 6);

    $log = dl_fetchProductionLedgerLog($db, $branchId, $date);
    $logCorrection = null;
    $logAddition = null;
    foreach ($log as $entry) {
        if ((int)$entry['product_id'] !== $productId || ($entry['field_key'] ?? '') !== 'produced_qty' || ($entry['source'] ?? '') !== 'movement') {
            continue;
        }
        if ((int)($entry['quantity'] ?? 0) === -4) {
            $logCorrection = $entry;
        }
        if ((int)($entry['quantity'] ?? 0) === 10) {
            $logAddition = $entry;
        }
    }
    $h->test(
        'the log reads: original entry 0 → 10, then correction 10 → 6 with its reason',
        $logAddition !== null && $logCorrection !== null
            && $logAddition['before_display'] === '0' && $logAddition['after_display'] === '10'
            && $logCorrection['before_display'] === '10' && $logCorrection['after_display'] === '6'
            && $logCorrection['reason'] === 'keyed four too high',
        json_encode(['correction' => $logCorrection])
    );

    // ── Replay, reason requirement, floor. ──────────────────────────────────
    $replay = dl_recordProductionAddition($user, [
        'date' => $date,
        'shift' => 'AM',
        'commissary_branch_id' => $branchId,
        'product_id' => $productId,
        'quantity' => -4,
        'reason' => 'keyed four too high',
        'submission_id' => 'fix-corr-1',
    ]);
    $h->test('a replayed correction returns duplicate and moves nothing', !empty($replay['duplicate']) && $produced() === 6 && $movementCount() === 2);

    $noReason = $refusal(static function () use ($user, $date, $branchId, $productId): void {
        dl_recordProductionAddition($user, [
            'date' => $date, 'shift' => 'AM', 'commissary_branch_id' => $branchId, 'product_id' => $productId,
            'quantity' => -2, 'reason' => '', 'submission_id' => 'fix-corr-no-reason',
        ]);
    });
    $h->test(
        'a reduction without a reason is refused (revert lets an unattributed reduction through)',
        $noReason['code'] === 422 && str_contains($noReason['message'], 'reason')
            && $produced() === 6 && $movementCount() === 2,
        json_encode($noReason)
    );

    $belowZero = $refusal(static function () use ($user, $date, $branchId, $productId): void {
        dl_recordProductionAddition($user, [
            'date' => $date, 'shift' => 'AM', 'commissary_branch_id' => $branchId, 'product_id' => $productId,
            'quantity' => -7, 'reason' => 'too much', 'submission_id' => 'fix-corr-below',
        ]);
    });
    $h->test(
        'a reduction below zero is refused with 422, never clamped (revert desyncs the stored column)',
        $belowZero['code'] === 422 && str_contains($belowZero['message'], 'below zero')
            && $produced() === 6 && $movementCount() === 2,
        json_encode($belowZero)
    );

    $toZero = dl_recordProductionAddition($user, [
        'date' => $date,
        'shift' => 'AM',
        'commissary_branch_id' => $branchId,
        'product_id' => $productId,
        'quantity' => -6,
        'reason' => 'wrong product entirely',
        'submission_id' => 'fix-corr-2',
    ]);
    $h->test('reaching exactly zero is allowed and drops the yield-only run row', (int)$toZero['row']['produced_qty'] === 0 && $runYield() === null && $movementCount() === 3);

    $atZero = $refusal(static function () use ($user, $date, $branchId, $productId): void {
        dl_recordProductionAddition($user, [
            'date' => $date, 'shift' => 'AM', 'commissary_branch_id' => $branchId, 'product_id' => $productId,
            'quantity' => -1, 'reason' => 'nothing left to take back', 'submission_id' => 'fix-corr-3',
        ]);
    });
    $h->test('a reduction against a zero ADDTL is refused', $atZero['code'] === 422 && $movementCount() === 3, json_encode($atZero));

    $zeroQuantity = $refusal(static function () use ($user, $date, $branchId, $productId): void {
        dl_recordProductionAddition($user, [
            'date' => $date, 'shift' => 'AM', 'commissary_branch_id' => $branchId, 'product_id' => $productId,
            'quantity' => 0, 'reason' => 'nothing', 'submission_id' => 'fix-zero',
        ]);
    });
    $h->test('a zero quantity stays invalid (the signed contract is non-zero)', str_contains($zeroQuantity['message'], 'Invalid production addition data'), json_encode($zeroQuantity));

    // ── The UI contract at both layers. ─────────────────────────────────────
    $template = file_get_contents($base . '/templates/modules/daily-ledger/admin/commissary.disyl');
    $handlers = file_get_contents($base . '/modules/daily-ledger/handlers.php');
    $h->test(
        'the modal explains the signed correction (revert leaves the operator with an add-only field)',
        str_contains($template, 'put a minus sign first')
            && str_contains($template, '(optional — required to reduce)')
            && str_contains($template, "A reason is required to reduce a recorded addition.")
            && str_contains($template, 'Addition reduced — correction logged')
    );
    $h->test(
        'the modal no longer pins the quantity to min="1"',
        !str_contains($template, 'min="1" step="1" id="production-addition-qty"'),
        'add-only input attribute still present'
    );
    $h->test(
        'the server carries the same rule (revert restores an add-only endpoint)',
        str_contains($handlers, 'A reason is required to reduce a recorded addition.')
            && str_contains($handlers, 'Cannot reduce Additional below zero')
            && str_contains($handlers, '$quantity === 0')
    );
} finally {
    $cleanup();
}

$h->done();
