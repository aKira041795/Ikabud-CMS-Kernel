<?php

declare(strict_types=1);

/**
 * Raised when a cashier withdrawal insert hits the DB-level dedup guard
 * (uq_dl_cw_dedup on dedup_hash). Carries HTTP 409 so the online handler can
 * reply with an idempotent duplicate response and the offline sync loop can
 * record a deterministic 'conflict' receipt (no retry, no double-apply).
 */
class DlDuplicateWithdrawalException extends \RuntimeException
{
    public function __construct(string $message = 'Duplicate withdrawal already recorded')
    {
        parent::__construct($message, 409);
    }
}

/**
 * True when a PDOException is a duplicate-entry (unique key) violation.
 * SQLSTATE 23000 with driver code 1062 (InnoDB).
 */
function dl_isDuplicateKeyError(\PDOException $e): bool
{
    $sqlState = (string)$e->getCode();
    $driverCode = (int)($e->errorInfo[1] ?? 0);
    return $sqlState === '23000' || $driverCode === 1062;
}

/**
 * The identity a withdrawal submission is deduplicated by.
 *
 * THE ROOT CAUSE THIS EXISTS FOR: the guard used to key on CONTENT, and content cannot
 * tell a replay apart from a legitimate repeat.
 *
 *   "this exact request arrived twice"            -> must be refused
 *   "27 more arrived, same reason, same person"   -> must be recorded
 *
 * Those two submissions are byte-identical without a per-submission key. Keying on content
 * therefore produced the same bug three times, each fixed by bolting another field into the
 * fingerprint (box-vs-pcs -> 057, AM-vs-PM -> 059) and re-hashing every stored row. It got
 * worse when a take-back was added: the correcting -27 is its OWN row, so the original +27
 * keeps owning its fingerprint and the same amount could never be recorded again that day.
 *
 * So the identity is the SUBMISSION, and absence of a key means "a new submission", not
 * "fall back to content". Callers that carry a real key (the modal, the offline queue) keep
 * full replay protection: the same key returns the cached response and, as a backstop,
 * produces the same fingerprint.
 *
 * Do NOT "fix" a blocked-but-legitimate entry by adding another content field to
 * dl_withdrawalDedupHash. That is the treadmill this replaces.
 */
function dl_withdrawalSubmissionId(string $idempotencyKey): string
{
    $key = trim($idempotencyKey);
    return $key !== '' ? $key : 'auto-' . bin2hex(random_bytes(8));
}

/**
 * Deterministic fingerprint of one cashier-withdrawal line, matching the
 * migration-052 SQL backfill byte-for-byte:
 *
 *   SHA1(CONCAT_WS('|', branch_id, product_id, ledger_date, withdrawal_type,
 *        COALESCE(reason_code,''), COALESCE(custom_reason,''),
 *        COALESCE(dr_number,''), COALESCE(target_branch_id,''),
 *        quantity, COALESCE(liable_user_id,'')))
 *
 * Callers must pass the values exactly as they are bound to the INSERT
 * (NULLs normalised to null — not '' — for custom_reason/dr_number/
 * target_branch_id/liable_user_id, and reason_code already defaulted to
 * 'manual_adjustment'). `?? ''` in the implode mirrors SQL COALESCE.
 *
 * `unit` ('pcs'|'box') is part of the fingerprint so that withdrawing a
 * whole box and withdrawing the same number of loose pieces are distinct
 * rows even though `quantity` stores the piece-equivalent for both.
 *
 * `shift` ('AM'|'PM') is part of the fingerprint for the same reason: the
 * ledger is shift-scoped, so the same adjustment legitimately exists on both
 * shifts of one date. Without it, recording 1 pc on PM after 1 pc was already
 * recorded on AM was rejected as a duplicate (the guard matches on content,
 * and the shift was not part of the content).
 *
 * `$nonce` is the SUBMISSION's identity (see dl_withdrawalSubmissionId) and is the only
 * thing that separates a replay from a legitimate repeat. The add paths must never pass ''
 * — an empty nonce is exactly the content-identity behaviour described above, which is the
 * bug this parameter exists to remove.
 *
 * The content fields stay in the fingerprint because stored rows and the migration-052
 * backfill are built from them, and because the EDIT path legitimately asks "does another
 * row already hold this content" — there, content IS the question. They are not, any more,
 * the identity of a submission.
 */
function dl_withdrawalDedupHash(int $branchId, int $productId, string $ledgerDate, string $type, ?string $reasonCode, ?string $customReason, ?string $drNumber, ?int $targetBranchId, int $qty, ?int $liableUserId, string $unit = 'pcs', ?string $shift = null, string $nonce = ''): string
{
    $parts = [
        (string)$branchId,
        (string)$productId,
        $ledgerDate,
        $type,
        (string)($reasonCode ?? ''),
        (string)($customReason ?? ''),
        (string)($drNumber ?? ''),
        (string)($targetBranchId ?? ''),
        (string)$qty,
        (string)($liableUserId ?? ''),
        (string)($unit === '' ? 'pcs' : $unit),
        (string)($shift ?? ''),
    ];
    // Appended ONLY when present, so a keyless hash stays byte-identical to the
    // migration-052 backfill (the edit path compares against stored fingerprints).
    if ($nonce !== '') {
        $parts[] = 'n=' . $nonce;
    }
    return sha1(implode('|', $parts));
}

/**
 * Resolve a withdrawal line's unit into the ledger's piece-equivalent.
 *
 * Ledger/variance math always counts PIECES (dl_cashier_withdrawals.quantity,
 * dl_daily_ledger.withdraw/addtl). A box line is converted here:
 *
 *   unit='pcs' -> quantity stays as entered, pack_qty = null
 *   unit='box' -> requires dl_products.pcs_per_pack > 0 for that product;
 *                 quantity = boxes * pcs_per_pack, pack_qty = boxes
 *
 * Returns ['quantity' => int pieces, 'unit' => string, 'pack_qty' => ?int].
 * Throws RuntimeException(code 422) on invalid unit or a box without a
 * configured pcs_per_pack.
 */
function dl_resolveWithdrawalLineUnit(\Ikabud\Kernel\Contracts\ModuleDB $db, int $productId, int $quantity, $unit): array
{
    $unit = strtolower(trim((string)($unit ?? 'pcs')));
    $unit = in_array($unit, ['pcs', 'box'], true) ? $unit : 'pcs';

    if ($unit === 'pcs') {
        return ['quantity' => max(0, $quantity), 'unit' => 'pcs', 'pack_qty' => null];
    }

    $stmt = $db->prepare('SELECT pcs_per_pack FROM dl_products WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $productId]);
    $pcsPerPack = (int)$stmt->fetchColumn();

    if ($pcsPerPack <= 0) {
        throw new \RuntimeException('This product is not configured for box withdrawals. Ask an admin to set its pieces-per-box (pcs_per_pack).', 422);
    }

    $boxes = max(0, $quantity);
    return ['quantity' => $boxes * $pcsPerPack, 'unit' => 'box', 'pack_qty' => $boxes];
}

/**
 * Reconcile `dl_daily_ledger.addtl` against the evidence that is supposed to
 * produce it, so a ballooned figure becomes visible instead of silent.
 *
 * `addtl` ACCUMULATES - unlike beg_bal/bal_end it is not a counted value - and
 * nothing has ever checked it against its sources. A retry that is not byte
 * identical to the original is not a duplicate to the dedup index, so it lands as
 * a NEW row: the amount inflates with no error and no evidence trail. That is the
 * mechanism behind an Addt'l figure nobody can explain.
 *
 * Compared at DAY level (AM + PM summed) ON PURPOSE. Receivings are not
 * shift-scoped (dl_branch_receivings carries received_ledger_date and no shift), so
 * a per-shift comparison would report discrepancies that are only an attribution
 * artefact. Better a slightly coarser answer that is true than a precise one that
 * cries wolf.
 *
 * Sources summed, and nothing else:
 *   - posted receivings into this branch (dl_branch_receiving_items.quantity_received)
 *   - cashier `adjustment_add` rows (the only cashier-side writer of addtl)
 *   - non-reversed production OUTPUT movements
 *
 * So a non-zero `difference` means "not explained by these sources", NOT
 * necessarily "wrong". Other paths can move addtl (edited deliveries, corrections
 * to a receiving), and this function deliberately does not guess at them: it
 * reports the disagreement and leaves the judgement to a human. Read it as a
 * triage list - largest first - not as a verdict.
 *
 * @return array<int, array<string, mixed>> Rows with product/date, each source,
 *         the recorded total and the difference. Empty when everything reconciles.
 */
function dl_reconcileAddtl(\Ikabud\Kernel\Contracts\DatabaseContract $db, int $branchId, string $fromDate, string $toDate, int $limit = 200): array
{
    if ($branchId <= 0 || $fromDate === '' || $toDate === '') {
        return [];
    }
    $limit = max(1, min(1000, $limit));

    $stmt = $db->prepare(
        "SELECT dl.product_id,
                p.name AS product_name,
                dl.ledger_date,
                SUM(dl.addtl) AS recorded,
                COALESCE(rcv.qty, 0) AS receiving_qty,
                COALESCE(adj.qty, 0) AS adjustment_qty,
                COALESCE(prd.qty, 0) AS production_qty,
                SUM(dl.addtl)
                    - (COALESCE(rcv.qty, 0) + COALESCE(adj.qty, 0) + COALESCE(prd.qty, 0)) AS difference
           FROM dl_daily_ledger dl
           LEFT JOIN dl_products p ON p.id = dl.product_id
           LEFT JOIN (
                SELECT bri.product_id AS product_id, br.received_ledger_date AS ledger_date,
                       SUM(bri.quantity_received) AS qty
                  FROM dl_branch_receivings br
                  INNER JOIN dl_branch_receiving_items bri ON bri.receiving_id = br.id
                 WHERE br.branch_id = :rcv_bid AND br.status = 'posted'
                   AND br.received_ledger_date BETWEEN :rcv_from AND :rcv_to
                 GROUP BY bri.product_id, br.received_ledger_date
           ) rcv ON rcv.product_id = dl.product_id AND rcv.ledger_date = dl.ledger_date
           LEFT JOIN (
                SELECT cw.product_id AS product_id, cw.ledger_date AS ledger_date,
                       SUM(cw.quantity) AS qty
                  FROM dl_cashier_withdrawals cw
                 WHERE cw.branch_id = :adj_bid AND cw.withdrawal_type = 'adjustment_add'
                   AND cw.ledger_date BETWEEN :adj_from AND :adj_to
                 GROUP BY cw.product_id, cw.ledger_date
           ) adj ON adj.product_id = dl.product_id AND adj.ledger_date = dl.ledger_date
           LEFT JOIN (
                SELECT pm.product_id AS product_id, pm.ledger_date AS ledger_date,
                       SUM(pm.quantity) AS qty
                  FROM dl_production_movements pm
                 WHERE pm.destination_branch_id = :prd_bid AND pm.movement_type = 'output'
                   AND pm.ledger_date BETWEEN :prd_from AND :prd_to
                   AND NOT EXISTS (
                        SELECT 1 FROM dl_production_movements r
                         WHERE r.reference_movement_id = pm.id AND r.movement_type = 'reverse'
                   )
                 GROUP BY pm.product_id, pm.ledger_date
           ) prd ON prd.product_id = dl.product_id AND prd.ledger_date = dl.ledger_date
          WHERE dl.branch_id = :led_bid
            AND dl.ledger_date BETWEEN :led_from AND :led_to
          GROUP BY dl.product_id, p.name, dl.ledger_date, rcv.qty, adj.qty, prd.qty
         HAVING difference <> 0
          ORDER BY ABS(difference) DESC, dl.ledger_date DESC
          LIMIT {$limit}"
    );
    $stmt->execute([
        ':rcv_bid' => $branchId, ':rcv_from' => $fromDate, ':rcv_to' => $toDate,
        ':adj_bid' => $branchId, ':adj_from' => $fromDate, ':adj_to' => $toDate,
        ':prd_bid' => $branchId, ':prd_from' => $fromDate, ':prd_to' => $toDate,
        ':led_bid' => $branchId, ':led_from' => $fromDate, ':led_to' => $toDate,
    ]);

    return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
}

/**
 * Whether a withdrawal type accepts a negative quantity (a reduction).
 *
 * Two types do, for the same reason — an amount is on the ledger that should
 * not be:
 *   - `correction`    reduces `withdraw` (the row sums into it)
 *   - `adjustment_add` reduces `addtl` (the row moves it by a delta)
 *
 * `addtl` has no direct-edit cell in the ledger, so a negative Add Stock is the
 * ONLY way an operator can take back additional stock that was recorded too
 * high. Keeping the rule here means the create path, the offline replay and the
 * modal all agree on it instead of each re-deriving it.
 */
function dl_withdrawalTypeAllowsNegative(string $type): bool
{
    return in_array($type, ['correction', 'adjustment_add'], true);
}

/**
 * Resolve a withdrawal line for a given withdrawal type, allowing negative
 * quantities on the types that reduce an amount (see
 * dl_withdrawalTypeAllowsNegative — `correction` for `withdraw`, `adjustment_add`
 * for `addtl`). A negative is always piece-based (unit pcs, no pack conversion):
 * "-2 boxes" has no unambiguous meaning, and the ledger counts pieces. Positive
 * quantities resolve normally (pcs or box) via dl_resolveWithdrawalLineUnit.
 *
 * @return array{quantity:int, unit:string, pack_qty:?int}
 * @throws \RuntimeException code 422 when a negative qty is used on a type that
 *         does not allow one.
 */
function dl_resolveWithdrawalLineForType(\Ikabud\Kernel\Contracts\ModuleDB $db, int $productId, int $quantity, $unit, string $type): array
{
    if ($quantity < 0) {
        if (!dl_withdrawalTypeAllowsNegative($type)) {
            throw new \RuntimeException('Negative quantities are only allowed on Correction and Add Stock entries (use a minus sign, e.g. -3, to reduce an amount recorded too high).', 422);
        }
        return ['quantity' => $quantity, 'unit' => 'pcs', 'pack_qty' => null];
    }
    return dl_resolveWithdrawalLineUnit($db, $productId, $quantity, $unit);
}

/**
 * Reason codes accepted for a withdrawal / stock-adjustment line.
 * Kept in one place so the create, edit and offline-replay paths agree.
 */
function dl_allowedWithdrawalReasons(): array
{
    return ['spoilage', 'staff_meal', 'sampling', 'testing', 'promo', 'donation', 'damage', 'manual_adjustment', 'encoder_omission', 'other'];
}

/**
 * Whether an Add Stock (adjustment_add) entry has to name a liable person.
 *
 * Add Stock normally resolves a shortage, so the stock has to be charged to
 * somebody. `encoder_omission` is different: the stock was never lost, the
 * entry was simply not recorded, so there is nothing to charge and no liable
 * person is recorded (the flow stores liable_user_id = NULL).
 *
 * A NEGATIVE Add Stock (taking back an amount recorded too high) is the same
 * kind of mistake, so it is also an encoder omission and charges nobody — the
 * amount was keyed wrong, not lost. The rule is direction-independent on
 * purpose: it is the reason that decides, not the sign.
 */
function dl_adjustmentAddNeedsLiable(?string $reasonCode): bool
{
    return $reasonCode !== 'encoder_omission';
}

/**
 * Display name for a module user id, or null when the id is unknown/absent.
 *
 * A charge records who it is billed to by id, so the name is looked up here both
 * when the charge is written (to snapshot it, migration 060) and when an older
 * row that has no snapshot is rendered.
 */
function dl_userDisplayNameById($db, ?int $userId): ?string
{
    $userId = (int)($userId ?? 0);
    if ($userId <= 0) {
        return null;
    }

    try {
        $stmt = $db->prepare(
            "SELECT COALESCE(NULLIF(full_name, ''), username, CONCAT('User #', id)) AS name
               FROM dl_users WHERE id = :id LIMIT 1"
        );
        $stmt->execute([':id' => $userId]);
        $name = $stmt->fetchColumn();
        return $name !== false && $name !== null && $name !== '' ? (string)$name : null;
    } catch (\Throwable $e) {
        return null;
    }
}

/**
 * People who can be charged for missing stock — the "Charge to" list in Stock
 * Adjustment.
 *
 * Cashiers come first because the person being charged for a shift is normally
 * the cashier who worked it, and they are the ones an operator looks for. They
 * are scoped through dl_user_branches so a branch never offers another branch's
 * cashier. The branch-independent roles (production in charge, supervisor,
 * admin) follow, and can be charged from any branch.
 *
 * @return array<int, array{id:int, name:string, role:string, shift:?string, label:string}>
 */
function dl_liablePersonsForBranch($db, int $branchId): array
{
    $stmt = $db->prepare(
        "SELECT u.id,
                COALESCE(NULLIF(u.full_name, ''), u.username, CONCAT('User #', u.id)) AS name,
                u.role,
                u.shift
           FROM dl_users u
          WHERE u.is_active = 1
            AND u.deleted_at IS NULL
            AND (
                 u.role IN ('production_in_charge', 'supervisor', 'admin')
                 OR (u.role = 'cashier' AND EXISTS (
                        SELECT 1 FROM dl_user_branches ub
                         WHERE ub.user_id = u.id AND ub.branch_id = :bid
                    ))
            )
          ORDER BY (u.role = 'cashier') DESC,
                   (u.role = 'production_in_charge') DESC,
                   name ASC"
    );
    $stmt->execute([':bid' => $branchId]);

    $persons = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $id = (int)$row['id'];
        $name = (string)$row['name'];
        $role = (string)$row['role'];
        $shiftRaw = (string)($row['shift'] ?? '');
        $shift = in_array($shiftRaw, ['AM', 'PM'], true) ? $shiftRaw : null;
        // Keep name/role for existing consumers; label is what the dropdown shows.
        $persons[] = [
            'id' => $id,
            'name' => $name,
            'role' => $role,
            'shift' => $shift,
            'label' => $name . ' (' . $role . ($shift !== null ? ' · ' . $shift : '') . ')',
        ];
    }

    return $persons;
}

/**
 * Returns a product's optional box size (pcs_per_pack) or null.
 */
function dl_productPcsPerPack(\Ikabud\Kernel\Contracts\ModuleDB $db, int $productId): ?int
{
    $stmt = $db->prepare('SELECT pcs_per_pack FROM dl_products WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $productId]);
    $val = $stmt->fetchColumn();
    $val = $val !== null && $val !== false ? (int)$val : 0;
    return $val > 0 ? $val : null;
}

/**
 * Mint the next auto-DR label for a production-branch receive.
 *
 * Format: AUTO-<dd/mm/yyyy>-<n> where n is the next sequence number for the
 * destination branch + delivery date. The leading `AUTO-` namespace keeps the
 * label from colliding with a cashier-typed paper DR during the exact-match
 * lookup in apiReceivePaperDelivery (handlers.php) — the lookup matches on
 * destination_id + dr_number, and real paper DRs never start with AUTO-.
 *
 * Caller must hold the destination day-status row lock (dl_lockDayStatusRow)
 * inside the open transaction so two concurrent receives on the same
 * branch/date cannot mint the same sequence number.
 */
function dl_mintAutoDrNumber(\Ikabud\Kernel\Contracts\ModuleDB $db, int $destinationBranchId, string $deliveryDate): string
{
    $stmt = $db->prepare(
        'SELECT COUNT(*)
           FROM dl_deliveries
          WHERE destination_type = "branch"
            AND destination_id = :bid
            AND delivery_date = :d
            AND dr_number LIKE "AUTO-%"'
    );
    $stmt->execute([':bid' => $destinationBranchId, ':d' => $deliveryDate]);
    $n = ((int)$stmt->fetchColumn()) + 1;

    return 'AUTO-' . date('d/m/Y', strtotime($deliveryDate)) . '-' . $n;
}

/**
 * True when the branch is an active production (commissary) site.
 */
function dl_branchIsProductionSite(\Ikabud\Kernel\Contracts\ModuleDB $db, int $branchId): bool
{
    $stmt = $db->prepare('SELECT is_commissary, is_active FROM dl_branches WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $branchId]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    return is_array($row) && (int)($row['is_commissary'] ?? 0) === 1 && (int)($row['is_active'] ?? 0) === 1;
}

/**
 * Returns the base URL for the Daily Ledger module.
 */
function dlGetBaseUrl(): string
{
    return '/daily-ledger';
}

function dlExternalBaseUrl(): string
{
    return external_base_url((string)config('app.url', ''));
}

function daily_ledger_capability_handlers(): array
{
    return [
        'kernel.auth.authenticate@1' => 'daily_ledger_cap_kernel_auth_authenticate_1',
        'entity.list.daily_ledger_entry@1' => 'dl_cap_entity_list_entry_1',
        'entity.get.daily_ledger_entry@1' => 'dl_cap_entity_get_entry_1',
        'export.daily_ledger_sales@1' => 'dl_cap_export_report_1',
        'export.daily_ledger_variances@1' => 'dl_cap_export_report_1',
        'export.daily_ledger_branch_summary@1' => 'dl_cap_export_report_1',
        'export.daily_ledger_month_end@1' => 'dl_cap_export_report_1',
        'export.daily_ledger_category_sales@1' => 'dl_cap_export_report_1',
        'export.daily_ledger_data_integrity@1' => 'dl_cap_export_report_1',
    ];
}

function dl_cap_export_report_1(array $input = []): array
{
    return ['ok' => true, 'governed_by' => 'daily-ledger', 'input' => $input];
}

function dlCtx(): \Ikabud\Kernel\Contracts\ModuleContext
{
    $ctx = module('daily-ledger');
    if (!$ctx) {
        throw new \RuntimeException('Module context unavailable');
    }

    return $ctx;
}

function dlInput(): array
{
    $input = dlCtx()->input();
    return is_array($input) ? $input : [];
}

function dlAppName(): string
{
    $settings = dlModuleSettings();
    $name = trim((string)($settings['app_name'] ?? ''));
    return $name !== '' ? $name : 'Daily Ledger';
}

function dlHumanizeToken(string $value): string
{
    $text = trim($value);
    if ($text === '') {
        return '';
    }

    return ucwords(str_replace(['_', '-'], ' ', $text));
}

function dlDeliveryDestinationTypeOptions(): array
{
    return [
        ['value' => 'branch', 'label' => 'Branch'],
        ['value' => 'selling_account', 'label' => 'Selling Account'],
        ['value' => 'own_account', 'label' => 'Own Account'],
        ['value' => 'reseller', 'label' => 'Reseller'],
        ['value' => 'customer', 'label' => 'Customer'],
        ['value' => 'event', 'label' => 'Event'],
        ['value' => 'wastage', 'label' => 'Wastage'],
        ['value' => 'internal_use', 'label' => 'Internal Use'],
        ['value' => 'adjustment', 'label' => 'Adjustment'],
    ];
}

function dlDeliveryStatusOptions(): array
{
    return [
        ['value' => 'draft', 'label' => 'Draft'],
        ['value' => 'posted', 'label' => 'Sent / Waiting'],
        ['value' => 'received', 'label' => 'Received'],
        ['value' => 'voided', 'label' => 'Cancelled'],
    ];
}

function dlDeliveryProvenanceStatusOptions(): array
{
    return [
        ['value' => 'paper_dr_pending', 'label' => 'Needs Check'],
        ['value' => 'accepted', 'label' => 'Verified'],
        ['value' => 'discrepant', 'label' => 'Discrepancy'],
    ];
}

function dlDeliveryStatusMeta(string $status): array
{
    return match ($status) {
        'draft' => ['label' => 'Draft', 'badge_classes' => 'bg-gray-50 text-gray-700 ring-gray-200'],
        'posted' => ['label' => 'Sent / Waiting', 'badge_classes' => 'bg-emerald-50 text-emerald-700 ring-emerald-200'],
        'received' => ['label' => 'Received', 'badge_classes' => 'bg-emerald-50 text-emerald-700 ring-emerald-200'],
        'voided' => ['label' => 'Cancelled', 'badge_classes' => 'bg-rose-50 text-rose-700 ring-rose-200'],
        default => ['label' => dlHumanizeToken($status), 'badge_classes' => 'bg-slate-50 text-slate-700 ring-slate-200'],
    };
}

function dlDeliveryProvenanceStatusMeta(string $status): array
{
    return match ($status) {
        'paper_dr_pending' => ['label' => 'Needs Check', 'badge_classes' => 'bg-amber-50 text-amber-700 ring-amber-200'],
        'accepted' => ['label' => 'Verified', 'badge_classes' => 'bg-emerald-50 text-emerald-700 ring-emerald-200'],
        'discrepant' => ['label' => 'Discrepancy', 'badge_classes' => 'bg-rose-50 text-rose-700 ring-rose-200'],
        default => ['label' => dlHumanizeToken($status), 'badge_classes' => 'bg-slate-50 text-slate-700 ring-slate-200'],
    };
}

function dlWithdrawalTypeMeta(string $type): array
{
    return match ($type) {
        'charge' => ['label' => 'Charge', 'badge_classes' => 'bg-amber-50 text-amber-700 ring-amber-200'],
        'pullout' => ['label' => 'Pullout', 'badge_classes' => 'bg-rose-50 text-rose-700 ring-rose-200'],
        'used' => ['label' => 'Used', 'badge_classes' => 'bg-indigo-50 text-indigo-700 ring-indigo-200'],
        'correction' => ['label' => 'Correction', 'badge_classes' => 'bg-orange-50 text-orange-700 ring-orange-200'],
        'adjustment_add' => ['label' => 'Add Stock', 'badge_classes' => 'bg-emerald-50 text-emerald-700 ring-emerald-200'],
        default => ['label' => dlHumanizeToken($type), 'badge_classes' => 'bg-slate-50 text-slate-700 ring-slate-200'],
    };
}

function dlProductionMovementTypeMeta(string $type): array
{
    return match ($type) {
        'output' => ['label' => 'Output', 'badge_classes' => 'bg-emerald-50 text-emerald-700 ring-emerald-200'],
        'reverse' => ['label' => 'Reverse', 'badge_classes' => 'bg-rose-50 text-rose-700 ring-rose-200'],
        'paper_dr_capture' => ['label' => 'Paper DR', 'badge_classes' => 'bg-amber-50 text-amber-800 ring-amber-300'],
        default => ['label' => dlHumanizeToken($type), 'badge_classes' => 'bg-slate-50 text-slate-700 ring-slate-200'],
    };
}

function dlProductionFlowModeLabel(string $flowMode): string
{
    return match ($flowMode) {
        'production' => 'Production',
        'commissary' => 'Commissary',
        'legacy' => 'Legacy',
        default => dlHumanizeToken($flowMode),
    };
}

function dlLoginPageContext(array $overrides = []): array
{
    $baseUrl = dlGetBaseUrl();
    $appName = dlAppName();
    $logoUrl = dlLogoUrl();
    $escapedAppName = htmlspecialchars($appName, ENT_QUOTES, 'UTF-8');
    $brandMarkHtml = $logoUrl !== ''
        ? '<img src="' . htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8') . '" alt="' . $escapedAppName . ' logo">'
        : '<span>DL</span>';

    return array_merge([
        'page_title' => $appName . ' Sign In',
        'app_name' => $appName,
        'logo_url' => $logoUrl,
        'favicon_url' => dlFaviconUrl(),
        'resolved_favicon_url' => dlResolvedFaviconUrl(),
        'brand_mark_html' => $brandMarkHtml,
        'login_logo_html' => $brandMarkHtml,
        'login_brand_text' => $appName,
        'login_brand_html' => $escapedAppName,
        'login_subtitle' => 'Sign in to continue',
        'login_username_label' => 'Username or Email',
        'login_full_name_label' => 'Full Name',
        'login_full_name_placeholder' => 'e.g. Juan Dela Cruz',
        'login_endpoint' => $baseUrl . '/auth/login',
        'login_button_text' => 'Sign In',
        'login_loading_text' => 'Signing in...',
        'login_forgot_url' => $baseUrl . '/forgot-password',
        'login_forgot_text' => 'Forgot password?',
        'gui' => [
            'app_name' => $appName,
            'app_name_accent' => $appName,
            'app_name_rest' => '',
            'font_url' => 'https://fonts.googleapis.com/css2?family=Fraunces:wght@600;700&family=Inter:wght@400;500;600;700&display=swap',
            'font_family' => 'Inter, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif',
            'color_primary' => '#b45309',
            'color_primary_hover' => '#92400e',
            'color_primary_light' => 'rgba(180, 83, 9, 0.18)',
            'color_bg' => 'linear-gradient(145deg, #fff7ed 0%, #fef3c7 46%, #fde68a 100%)',
            'color_surface' => 'rgba(255, 252, 247, 0.96)',
            'color_border' => '#f3d7a5',
            'color_text' => '#422006',
            'color_text_muted' => '#7c5a32',
            'css_overrides' => '.login-card{max-width:420px;border:1px solid rgba(180,83,9,.18);box-shadow:0 28px 80px rgba(120,53,15,.18)}.login-logo h1{font-family:"Fraunces", Georgia, serif;font-size:2.15rem;letter-spacing:-.04em}.login-logo p{max-width:30ch;margin:10px auto 0;font-size:14px;line-height:1.5}.login-mark{font-family:"Fraunces", Georgia, serif;font-size:24px;font-weight:700}.form-label{text-transform:uppercase;letter-spacing:.08em;font-size:11px}.form-input{background:rgba(255,255,255,.88)}.btn-login{box-shadow:0 14px 30px rgba(180,83,9,.22)}body::before{content:"";position:fixed;inset:0;background:radial-gradient(circle at top left, rgba(251,191,36,.22), transparent 36%),radial-gradient(circle at bottom right, rgba(217,119,6,.16), transparent 34%);pointer-events:none}',
        ],
    ], $overrides);
}

function dlDefaultFaviconUrl(): string
{
    return "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='8' fill='%23b45309'/%3E%3Cpath d='M8 7h10a5 5 0 010 10H8V7zm0 10h10a5 5 0 010 10H8V17z' fill='none' stroke='%23fff' stroke-width='2' stroke-linejoin='round'/%3E%3C/svg%3E";
}

function dlNormalizeBrandAssetUrl(mixed $value, string $label = 'Brand asset URL'): string
{
    $assetUrl = trim((string)$value);
    if ($assetUrl === '') {
        return '';
    }

    if (function_exists('mb_strlen') && mb_strlen($assetUrl) > 2048) {
        throw new InvalidArgumentException($label . ' must be 2048 characters or fewer.');
    }
    if (strlen($assetUrl) > 2048) {
        throw new InvalidArgumentException($label . ' must be 2048 characters or fewer.');
    }

    $scheme = strtolower((string)parse_url($assetUrl, PHP_URL_SCHEME));
    if ($scheme !== '' && !in_array($scheme, ['http', 'https'], true)) {
        throw new InvalidArgumentException($label . ' must use http, https, or a relative path.');
    }

    return $assetUrl;
}

function dlLogoUrl(): string
{
    try {
        return dlNormalizeBrandAssetUrl(dlModuleSettings()['logo_url'] ?? '', 'Logo URL');
    } catch (Throwable $ignored) {
        return '';
    }
}

function dlFaviconUrl(): string
{
    try {
        return dlNormalizeBrandAssetUrl(dlModuleSettings()['favicon_url'] ?? '', 'Favicon URL');
    } catch (Throwable $ignored) {
        return '';
    }
}

function dlResolvedFaviconUrl(): string
{
    $faviconUrl = dlFaviconUrl();
    return $faviconUrl !== '' ? $faviconUrl : dlDefaultFaviconUrl();
}

function dlBrandAssetUploadMaxBytes(): int
{
    if (function_exists('cmsMediaMaxUploadBytes')) {
        return max(262144, (int)cmsMediaMaxUploadBytes());
    }

    return 2 * 1024 * 1024;
}

function dlBrandAssetFallbackPath(): string
{
    $tenantId = app()->tenant()->current();
    $tenantSegment = $tenantId !== null ? ('/t' . preg_replace('/[^A-Za-z0-9_-]/', '', (string)$tenantId)) : '';
    return BASE_PATH . '/public/uploads/daily-ledger' . $tenantSegment;
}

function dlBrandAssetFallbackUrl(string $relativePath): string
{
    $tenantId = app()->tenant()->current();
    $tenantSegment = $tenantId !== null ? ('/t' . preg_replace('/[^A-Za-z0-9_-]/', '', (string)$tenantId)) : '';
    return '/uploads/daily-ledger' . $tenantSegment . '/' . ltrim($relativePath, '/');
}

/**
 * Shrink an uploaded branding asset to the size it is actually displayed at.
 *
 * These arrive at design/camera resolution - the live logo is 800x800 and 336KB -
 * but the largest place one is shown is 64px tall (max-h-16 on login; h-8 w-8 in
 * the app header). On shared hosting that gap is not cosmetic: measured from
 * outside the host, that one file took ~20s to transfer at ~18KB/s while the same
 * page's 7KB of HTML moved at ~2MB/s. Apache serves public/uploads directly (the
 * !-f rewrite condition keeps PHP out of the path), so neither application code
 * nor edge compression can help - and a PNG is already DEFLATE-compressed inside,
 * so gzip saves 1%. The bytes themselves have to get smaller, which means doing it
 * where they arrive. This also makes re-uploading the same oversized file heal
 * itself, for every tenant.
 *
 * Rewrites $path in place, preserving the source format. Returns null when nothing
 * was done. Never throws: a failed resize must not fail a valid upload.
 *
 * @return array{width:int,height:int,bytes:int,original_bytes:int}|null
 */
function dlDownscaleBrandAssetInPlace(string $path, string $mimeType, string $assetType): ?array
{
    if (!function_exists('imagecreatefrompng') || !function_exists('imagecopyresampled')) {
        return null;
    }

    // SVG is resolution-independent, and ICO is a multi-size container GD cannot
    // encode. GIF is skipped deliberately: GD flattens an animated GIF to one
    // frame, and silently killing an animation is worse than a slow logo.
    $loaders = [
        'image/jpeg' => 'imagecreatefromjpeg',
        'image/png'  => 'imagecreatefrompng',
        'image/webp' => 'imagecreatefromwebp',
    ];
    if (!isset($loaders[$mimeType]) || !function_exists($loaders[$mimeType])) {
        return null;
    }

    $info = @getimagesize($path);
    if (!is_array($info)) {
        return null;
    }
    $srcW = (int)($info[0] ?? 0);
    $srcH = (int)($info[1] ?? 0);
    if ($srcW <= 0 || $srcH <= 0) {
        return null;
    }

    // 3x the largest display (64px) covers the densest phone screen. A favicon has
    // the extra job of serving as an apple-touch-icon.
    $maxEdge = $assetType === 'favicon' ? 180 : 192;
    if (max($srcW, $srcH) <= $maxEdge) {
        return null; // already small enough - leave the bytes untouched
    }

    $scale = $maxEdge / max($srcW, $srcH);
    $dstW  = max(1, (int)round($srcW * $scale));
    $dstH  = max(1, (int)round($srcH * $scale));

    $src = @$loaders[$mimeType]($path);
    if (!$src) {
        return null;
    }
    $dst = imagecreatetruecolor($dstW, $dstH);
    if (!$dst) {
        return null;
    }

    // Logos are transparent far more often than not; without this the alpha channel
    // is composited onto black.
    if ($mimeType !== 'image/jpeg') {
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagefilledrectangle($dst, 0, 0, $dstW - 1, $dstH - 1, imagecolorallocatealpha($dst, 0, 0, 0, 127));
    }

    // Note: no imagedestroy() calls. It has been a no-op since PHP 8.0 and is
    // deprecated in 8.5, where it is enough to abort a request under this app's error
    // handling - the handles are freed when these locals go out of scope anyway.
    $resampled = imagecopyresampled($dst, $src, 0, 0, 0, 0, $dstW, $dstH, $srcW, $srcH);
    if (!$resampled) {
        return null;
    }

    // Encode to a sibling file first: a failed encode must not truncate the original.
    $staging = $path . '.resize-tmp';
    $encoded = match ($mimeType) {
        'image/jpeg' => @imagejpeg($dst, $staging, 88),
        'image/webp' => @imagewebp($dst, $staging, 88),
        default      => @imagepng($dst, $staging, 9),
    };

    $originalBytes = (int)@filesize($path);
    $newBytes      = (int)@filesize($staging);

    // Refuse to swap in a file that is not actually smaller; the point is bytes.
    if (!$encoded || $newBytes <= 0 || $newBytes >= $originalBytes || !@rename($staging, $path)) {
        @unlink($staging);
        return null;
    }

    return [
        'width' => $dstW,
        'height' => $dstH,
        'bytes' => $newBytes,
        'original_bytes' => $originalBytes,
    ];
}

function dlUploadBrandAsset(string $assetType, array $file): array
{
    $assetType = strtolower(trim($assetType));
    $labels = [
        'logo' => 'Logo',
        'favicon' => 'Favicon',
    ];
    if (!isset($labels[$assetType])) {
        throw new InvalidArgumentException('Unsupported branding asset type.');
    }

    if ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException('Upload a ' . strtolower($labels[$assetType]) . ' image first.');
    }

    $tmpPath = trim((string)($file['tmp_name'] ?? ''));
    if ($tmpPath === '' || !is_file($tmpPath)) {
        throw new InvalidArgumentException('Uploaded ' . strtolower($labels[$assetType]) . ' file is not available.');
    }

    if (PHP_SAPI !== 'cli' && function_exists('is_uploaded_file') && !is_uploaded_file($tmpPath)) {
        throw new InvalidArgumentException($labels[$assetType] . ' upload did not arrive through the HTTP upload pipeline.');
    }

    $originalName = trim((string)($file['name'] ?? ($assetType . '.png')));
    $declaredSize = (int)($file['size'] ?? 0);
    if ($declaredSize <= 0) {
        $declaredSize = (int)(@filesize($tmpPath) ?: 0);
    }
    if ($declaredSize <= 0) {
        throw new InvalidArgumentException('Uploaded ' . strtolower($labels[$assetType]) . ' file is empty.');
    }
    if ($declaredSize > dlBrandAssetUploadMaxBytes()) {
        throw new InvalidArgumentException('Uploaded ' . strtolower($labels[$assetType]) . ' file exceeds the maximum allowed size.');
    }

    $allowedMimeTypes = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/svg+xml',
    ];
    if ($assetType === 'favicon') {
        $allowedMimeTypes[] = 'image/x-icon';
        $allowedMimeTypes[] = 'image/vnd.microsoft.icon';
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = strtolower((string)($finfo->file($tmpPath) ?: ''));
    if ($mimeType === '' || !in_array($mimeType, $allowedMimeTypes, true)) {
        throw new InvalidArgumentException($labels[$assetType] . ' must be a JPG, PNG, GIF, WEBP, SVG, or ICO image.');
    }

    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];
    if ($assetType === 'favicon') {
        $allowedExtensions[] = 'ico';
    }
    if (!in_array($ext, $allowedExtensions, true)) {
        $ext = match ($mimeType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/svg+xml' => 'svg',
            'image/x-icon', 'image/vnd.microsoft.icon' => 'ico',
            default => 'png',
        };
    }

    $filename = $assetType . '_' . date('Ymd_His') . '_' . substr(bin2hex(random_bytes(4)), 0, 8) . '.' . $ext;
    $subDir = 'branding/' . date('Y') . '/' . date('m');
    $relativePath = $subDir . '/' . $filename;

    // Shrink before storing, so both destinations get the small copy. The size and
    // mime checks above deliberately ran against the ORIGINAL upload first, so this
    // cannot be used to slip an oversized file past the limit.
    $downscaled = dlDownscaleBrandAssetInPlace($tmpPath, $mimeType, $assetType);
    if (is_array($downscaled) && function_exists('write_log')) {
        write_log('daily-ledger branding asset downscaled for transfer', 'info', [
            'asset_type' => $assetType,
            'mime_type' => $mimeType,
            'from_bytes' => $downscaled['original_bytes'],
            'to_bytes' => $downscaled['bytes'],
            'dimensions' => $downscaled['width'] . 'x' . $downscaled['height'],
        ]);
    }

    $destinations = [];
    if (function_exists('cmsUploadsPath') && function_exists('cmsResolveUploadUrl')) {
        $destinations[] = [
            'dir' => cmsUploadsPath() . '/daily-ledger/' . $subDir,
            'path' => cmsUploadsPath() . '/daily-ledger/' . $subDir . '/' . $filename,
            'url' => cmsResolveUploadUrl('daily-ledger/' . $relativePath),
            'label' => 'cms',
        ];
    }
    $destinations[] = [
        'dir' => dlBrandAssetFallbackPath() . '/' . $subDir,
        'path' => dlBrandAssetFallbackPath() . '/' . $subDir . '/' . $filename,
        'url' => dlBrandAssetFallbackUrl($relativePath),
        'label' => 'fallback',
    ];

    $destinationPath = '';
    $assetUrl = '';
    $saved = false;
    foreach ($destinations as $destination) {
        if (!kernelEnsureDirectory($destination['dir'])) {
            continue;
        }
        if (!kernelCopyFile($tmpPath, $destination['path'])) {
            continue;
        }

        $destinationPath = $destination['path'];
        $assetUrl = $destination['url'];
        $saved = true;

        if ($destination['label'] === 'fallback' && function_exists('write_log')) {
            write_log('daily-ledger branding upload fell back to public module storage.', 'warning', [
                'asset_type' => $assetType,
                'relative_path' => $relativePath,
            ]);
        }
        break;
    }

    if (!$saved) {
        throw new InvalidArgumentException('Unable to save the uploaded ' . strtolower($labels[$assetType]) . '.');
    }

    if ($mimeType === 'image/svg+xml' && function_exists('cmsSanitizeSvgContent')) {
        $svg = (string)@file_get_contents($destinationPath);
        if ($svg !== '') {
            kernelWriteFile($destinationPath, cmsSanitizeSvgContent($svg));
        }
    }

    return [
        'asset_url' => $assetUrl,
        'absolute_path' => $destinationPath,
        'relative_path' => $relativePath,
        'mime_type' => $mimeType,
        'file_size' => (int)(@filesize($destinationPath) ?: $declaredSize),
    ];
}

function dl_arePriceGroupsEnabled(): bool
{
    $settings = dlModuleSettings();
    return dl_settingToBool($settings['price_groups_enabled'] ?? true);
}

function dl_areSellingAccountsEnabled(): bool
{
    $settings = dlModuleSettings();
    return dl_settingToBool($settings['selling_accounts_enabled'] ?? false);
}

function dl_defaultPriceGroupId(): ?int
{
    $ctx = module();
    if (!$ctx) {
        return null;
    }

    static $cached = null;
    if ($cached !== null) {
        return $cached ?: null;
    }

    $row = $ctx->db()->query('SELECT id FROM dl_price_groups WHERE is_default = 1 AND is_active = 1 LIMIT 1')
        ->fetch(PDO::FETCH_ASSOC);
    $cached = $row ? (int)$row['id'] : 0;
    return $cached ?: null;
}

function dl_branchPriceGroupId(int $branchId): ?int
{
    $ctx = module();
    if (!$ctx || $branchId <= 0) {
        return dl_defaultPriceGroupId();
    }

    static $cache = [];
    if (array_key_exists($branchId, $cache)) {
        return $cache[$branchId];
    }

    $stmt = $ctx->db()->prepare('SELECT price_group_id FROM dl_branches WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $branchId]);
    $value = $stmt->fetchColumn();
    $cache[$branchId] = ($value !== false && $value !== null) ? (int)$value : dl_defaultPriceGroupId();
    return $cache[$branchId];
}

function dl_resolveBranchProductPrice(int $branchId, int $productId, ?string $atDate = null): float
{
    return dl_resolveProductPrice($productId, dl_branchPriceGroupId($branchId), $atDate);
}

/**
 * Return the date-bounded base-price expression for a trusted product alias.
 */
function dl_effectivePriceSql(string $productAlias = 'p', string $atParam = ':dl_eff_at'): string
{
    $allowedAliases = ['p'];
    if (!in_array($productAlias, $allowedAliases, true)) {
        throw new \InvalidArgumentException('Unsupported product SQL alias');
    }
    if (!preg_match('/^:[A-Za-z_][A-Za-z0-9_]*$/', $atParam)) {
        throw new \InvalidArgumentException('Invalid effective-price date parameter');
    }

    return 'COALESCE((SELECT ph.price FROM dl_product_price_history ph'
        . ' WHERE ph.product_id = ' . $productAlias . '.id'
        . ' AND ph.effective_at < DATE_ADD(' . $atParam . ', INTERVAL 1 DAY)'
        . ' ORDER BY ph.effective_at DESC, ph.id DESC LIMIT 1), '
        . $productAlias . '.current_price)';
}

/**
 * Lazily synchronize the denormalized current price with the price effective on a date.
 */
function dl_promoteCurrentPrices(?string $atDate = null): int
{
    $ctx = module();
    if (!$ctx) {
        return 0;
    }

    $atDate = $atDate ?: (function_exists('dl_businessDate') ? dl_businessDate() : date('Y-m-d'));
    $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $atDate);
    $errors = \DateTimeImmutable::getLastErrors();
    if (!$date || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
        || $date->format('Y-m-d') !== $atDate) {
        throw new \InvalidArgumentException('Promotion date must use YYYY-MM-DD format');
    }

    $setPrice = dl_effectivePriceSql('p', ':dl_promote_set_at');
    $wherePrice = dl_effectivePriceSql('p', ':dl_promote_where_at');
    $stmt = $ctx->db()->prepare(
        'UPDATE dl_products p SET current_price = ' . $setPrice
        . ' WHERE NOT (p.current_price <=> ' . $wherePrice . ')'
    );
    $stmt->execute([
        ':dl_promote_set_at' => $atDate,
        ':dl_promote_where_at' => $atDate,
    ]);
    $changed = (int)$stmt->rowCount();
    if ($changed > 0) {
        app()->cache()->clearByTags('daily-ledger', ['dl_products']);
    }
    return $changed;
}

function dl_resolveBaseProductPrice(int $productId, ?string $atDate = null): float
{
    $ctx = module();
    if (!$ctx) {
        return 0.0;
    }

    $atDate = $atDate ?: date('Y-m-d');
    $stmt = $ctx->db()->prepare(
        'SELECT price FROM dl_product_price_history
          WHERE product_id = :p AND effective_at < DATE_ADD(:d, INTERVAL 1 DAY)
          ORDER BY effective_at DESC, id DESC
          LIMIT 1'
    );
    $stmt->execute([':p' => $productId, ':d' => $atDate]);
    $price = $stmt->fetchColumn();
    if ($price !== false && $price !== null) {
        return (float)$price;
    }

    $stmt = $ctx->db()->prepare('SELECT current_price FROM dl_products WHERE id = :p');
    $stmt->execute([':p' => $productId]);
    return (float)($stmt->fetchColumn() ?: 0.0);
}

function dl_resolveProductPrice(int $productId, ?int $priceGroupId = null, ?string $atDate = null): float
{
    $ctx = module();
    if (!$ctx) {
        return 0.0;
    }

    $atDate = $atDate ?: date('Y-m-d');
    $priceGroupId = $priceGroupId ?: dl_defaultPriceGroupId();

    if (dl_arePriceGroupsEnabled() && $priceGroupId !== null) {
        $stmt = $ctx->db()->prepare(
            'SELECT selling_price FROM dl_product_prices
              WHERE product_id = :p AND price_group_id = :g AND is_active = 1
                AND effective_from <= :d1
                AND (effective_to IS NULL OR effective_to >= :d2)
              ORDER BY effective_from DESC
              LIMIT 1'
        );
        $stmt->execute([':p' => $productId, ':g' => $priceGroupId, ':d1' => $atDate, ':d2' => $atDate]);
        $price = $stmt->fetchColumn();
        if ($price !== false && $price !== null) {
            return (float)$price;
        }
    }

    return dl_resolveBaseProductPrice($productId, $atDate);
}

function dlRender(string $template, array $context = []): string
{
    if (!array_key_exists('app_name', $context)) {
        $context['app_name'] = dlAppName();
    }
    if (!array_key_exists('logo_url', $context)) {
        $context['logo_url'] = dlLogoUrl();
    }
    if (!array_key_exists('favicon_url', $context)) {
        $context['favicon_url'] = dlFaviconUrl();
    }
    if (!array_key_exists('resolved_favicon_url', $context)) {
        $context['resolved_favicon_url'] = dlResolvedFaviconUrl();
    }
    // Always supply layout-level feature flags so the sidebar can gate links
    // consistently across every admin page, even handlers that do not explicitly
    // pass them in. Existing values in $context win.
    if (function_exists('dl_layoutFlags')) {
        foreach (dl_layoutFlags() as $k => $v) {
            if (!array_key_exists($k, $context)) {
                $context[$k] = $v;
            }
        }
    }
    // Surface the authenticated user's real name + username to the shared top
    // nav. Handlers may override explicitly; otherwise derive from the JWT
    // payload so the cashier's entered full name shows beside the branch-shift
    // username. Non-fatal: on failure the layout falls back to user_name/role.
    $context = dl_navUserContext($context);
    return dlCtx()->render($template, kernelPrepareRenderContext($template, $context));
}

/**
 * Inject top-nav display identity (user_username / user_full_name) into a
 * render context, derived from the authenticated daily-ledger JWT payload.
 * Existing context values win; safe to call on unauthenticated pages (login).
 */
function dl_navUserContext(array $context): array
{
    if (array_key_exists('user_username', $context) && array_key_exists('user_full_name', $context)) {
        return $context;
    }
    try {
        $navUser = function_exists('dlUserFromRequest') ? dlUserFromRequest() : null;
        if (is_array($navUser)) {
            if (!array_key_exists('user_username', $context)) {
                $context['user_username'] = (string)($navUser['username'] ?? '');
            }
            if (!array_key_exists('user_full_name', $context)) {
                $navFullName = (string)($navUser['name'] ?? $navUser['full_name'] ?? '');
                if ($navFullName === '') {
                    $navFullName = (string)($navUser['username'] ?? '');
                }
                $context['user_full_name'] = $navFullName;
            }
        }
    } catch (Throwable $e) {
        // Ignore: top nav keeps existing user_name/user_role display.
    }
    return $context;
}

function dlNormalizeLoginRenderContext(array $context, string $template, array &$missingKeys = [], array &$typeMismatches = []): array
{
    return kernelApplyRenderContextShape($context, [
        'page_title' => 'Daily Ledger Sign In',
        'app_name' => 'Daily Ledger',
        'logo_url' => '',
        'favicon_url' => '',
        'resolved_favicon_url' => dlDefaultFaviconUrl(),
        'login_forgot_url' => '/daily-ledger/forgot-password',
        'login_username_label' => 'Username or Email',
        'login_full_name_label' => 'Full Name',
        'login_full_name_placeholder' => 'e.g. Juan Dela Cruz',
    ], ['page_title', 'app_name'], $missingKeys, $typeMismatches);
}

function dlNormalizeCashierLedgerRenderContext(array $context, string $template, array &$missingKeys = [], array &$typeMismatches = []): array
{
    return kernelApplyRenderContextShape($context, [
        'page_title' => '',
        'user_name' => '',
        'user_role' => '',
        'current_page' => '',
        'base_url' => '',
        'dl_token' => '',
        'branch_id' => 0,
        'branch_name' => '',
        'ledger_date' => '',
        'today' => '',
        'day_status' => '',
        'branches' => [],
        'is_cashier' => false,
        'reference_only' => false,
        'can_ledger_override' => false,
        'business_date_label' => '',
        'close_of_day_time' => '',
        'auto_close_enabled' => false,
        'operating_timezone' => '',
        'operating_region' => '',
        'all_branches' => [],
        'incoming_count' => 0,
        'formal_delivery_enabled' => false,
        'commissary_branch_id' => null,
        'commissary_branch_name' => null,
        'can_edit_delivery' => false,
    ], ['page_title', 'user_name', 'user_role', 'current_page', 'base_url', 'branch_id', 'branch_name', 'ledger_date', 'today', 'day_status', 'branches', 'is_cashier'], $missingKeys, $typeMismatches);
}

function dlNormalizeCashierRowsRenderContext(array $context, string $template, array &$missingKeys = [], array &$typeMismatches = []): array
{
    return kernelApplyRenderContextShape($context, [
        'rows' => [],
        'branch_id' => 0,
        'ledger_date' => '',
        'day_status' => '',
        // Optional: the viewed shift's lifecycle. Defaults to editable because the
        // API guard (dl_assertShiftMutable) is the real enforcement; this only keeps
        // the cell honest, so an absent value must not lock a shift with no reason.
        'shift_status' => 'open',
        'reference_only' => false,
    ], ['rows', 'branch_id', 'ledger_date', 'day_status'], $missingKeys, $typeMismatches);
}

function dlNormalizeAdminRenderContext(array $context, string $template, array &$missingKeys = [], array &$typeMismatches = []): array
{
    return kernelApplyRenderContextShape($context, [
        'page_title' => '',
        'app_name' => 'Daily Ledger',
        'user_name' => '',
        'user_role' => '',
        'current_page' => '',
        'base_url' => '',
        'dl_token' => '',
        'logo_url' => '',
        'favicon_url' => '',
        'resolved_favicon_url' => dlDefaultFaviconUrl(),
    ], ['page_title', 'app_name', 'user_name', 'user_role', 'current_page', 'base_url'], $missingKeys, $typeMismatches);
}

kernelRegisterRenderContextContract('daily-ledger.page.login', [
    'template' => 'modules/daily-ledger/pages/login.disyl',
    'priority' => 20,
    'normalize' => 'dlNormalizeLoginRenderContext',
    'log_event' => 'daily-ledger.render_context.contract_mismatch',
]);

kernelRegisterRenderContextContract('daily-ledger.cashier.ledger', [
    'template' => 'modules/daily-ledger/cashier/ledger.disyl',
    'priority' => 20,
    'normalize' => 'dlNormalizeCashierLedgerRenderContext',
    'log_event' => 'daily-ledger.render_context.contract_mismatch',
]);

kernelRegisterRenderContextContract('daily-ledger.cashier.rows', [
    'template' => 'modules/daily-ledger/cashier/partials/ledger-rows.disyl',
    'priority' => 20,
    'normalize' => 'dlNormalizeCashierRowsRenderContext',
    'log_event' => 'daily-ledger.render_context.contract_mismatch',
]);

function dlNormalizeCashierPosRenderContext(array $context, string $template, array &$missingKeys = [], array &$typeMismatches = []): array
{
    return kernelApplyRenderContextShape($context, [
        'page_title' => 'Point of Sale',
        'user_name' => '',
        'user_role' => '',
        'current_page' => 'pos',
        'base_url' => '',
        'dl_token' => '',
        'csrf_token' => '',
        'branch_id' => 0,
        'branch_name' => '',
        'ledger_date' => '',
        'day_status' => 'open',
        'pos_enabled' => false,
        'can_sell' => false,
        'can_void' => false,
        'can_refund' => false,
        'can_fallback' => false,
        'sales_mode' => 'manual',
        'mode_decided' => false,
        'mode_version' => 0,
        'summary' => null,
    ], ['page_title', 'user_name', 'user_role', 'current_page', 'base_url', 'branch_id', 'ledger_date', 'day_status'], $missingKeys, $typeMismatches);
}

function dlNormalizePosReceiptRenderContext(array $context, string $template, array &$missingKeys = [], array &$typeMismatches = []): array
{
    return kernelApplyRenderContextShape($context, [
        'page_title' => 'Receipt',
        'user_name' => '',
        'user_role' => '',
        'current_page' => 'pos',
        'base_url' => '',
        'dl_token' => '',
        'sale' => [],
    ], ['page_title', 'sale'], $missingKeys, $typeMismatches);
}

kernelRegisterRenderContextContract('daily-ledger.cashier.pos', [
    'template' => 'modules/daily-ledger/cashier/pos.disyl',
    'priority' => 20,
    'normalize' => 'dlNormalizeCashierPosRenderContext',
    'log_event' => 'daily-ledger.render_context.contract_mismatch',
]);

kernelRegisterRenderContextContract('daily-ledger.cashier.pos_receipt', [
    'template' => 'modules/daily-ledger/cashier/pos-receipt.disyl',
    'priority' => 20,
    'normalize' => 'dlNormalizePosReceiptRenderContext',
    'log_event' => 'daily-ledger.render_context.contract_mismatch',
]);

kernelRegisterRenderContextContract('daily-ledger.admin.shell', [
    'prefix' => 'modules/daily-ledger/admin/',
    'priority' => 20,
    'normalize' => 'dlNormalizeAdminRenderContext',
    'log_event' => 'daily-ledger.render_context.contract_mismatch',
]);

function dlRedirect(string $url, int $status = 302): void
{
    dlCtx()->redirect($url, $status);
}

function dlJson(array $data, int $status = 200): void
{
    dlCtx()->json($data, $status);
}

/**
 * Daily Ledger Module — helpers
 *
 * Module-local utilities only. Cross-module integration is via capability contracts.
 */

app()->hooks()->on('kernel.home_url', function (?string $url, string $role, ?array $user = null) {
    if (($user['source'] ?? null) !== 'daily-ledger') {
        return $url;
    }

    if ($role === 'cashier') {
        return '/daily-ledger/ledger';
    }

    if ($role === 'production_in_charge') {
        return '/daily-ledger/admin/production-output';
    }

    if ($role === 'viewer') {
        return '/daily-ledger/admin/overview';
    }

    if (in_array($role, ['admin', 'supervisor', 'auditor'], true)) {
        return '/daily-ledger/admin/dashboard';
    }

    return $url;
}, 80);

function daily_ledger_cap_kernel_auth_authenticate_1(mixed $payload, string $capabilityId = '', string $providerId = ''): ?array
{
    if (!is_array($payload)) {
        return null;
    }

    $username = trim((string)($payload['username'] ?? ''));
    $password = (string)($payload['password'] ?? '');
    if ($username === '' || $password === '') {
        return null;
    }

    // Username prefix policy: non-kernel providers must require @provider:username to avoid collisions.
    // Daily-ledger provider accepts only usernames prefixed with '@daily-ledger:'.
    $prefix = '@daily-ledger:';
    if (!str_starts_with($username, $prefix)) {
        return null;
    }
    $username = trim(substr($username, strlen($prefix)));
    if ($username === '') {
        return null;
    }

    $ctx = module('daily-ledger');
    if (!$ctx) {
        return null;
    }

    $row = dlFindActiveUserByIdentity($username);
    if (!is_array($row) || !password_verify($password, (string)($row['password_hash'] ?? ''))) {
        return null;
    }

    $id = (int)($row['id'] ?? 0);
    $role = (string)($row['role'] ?? '');
    if ($id <= 0 || !in_array($role, ['admin', 'supervisor', 'cashier', 'production_in_charge', 'auditor', 'viewer'], true)) {
        return null;
    }

    return [
        'user' => [
            // IMPORTANT: do not collide with kernel users.id (used by audit_logs FK).
            // The actual dl_users id is encoded in `sub` and parsed by dl_getActorUserId().
            'id' => 0,
            'sub' => $role . ':' . $id,
            'username' => (string)($row['username'] ?? ''),
            'full_name' => (string)($row['full_name'] ?? ''),
            'role' => $role,
        ],
        'source' => 'daily-ledger',
    ];
}

function dlFindActiveUserByIdentity(string $identity): ?array
{
    $identity = trim($identity);
    if ($identity === '') {
        return null;
    }

    $ctx = module('daily-ledger');
    if (!$ctx) {
        return null;
    }

    try {
        $stmt = $ctx->db()->prepare(
            "SELECT id, username, email, password_hash, full_name, role
             FROM dl_users
             WHERE (username = :username OR email = :email)
               AND is_active = 1
               AND deleted_at IS NULL
             LIMIT 1"
        );
        $stmt->execute([
            ':username' => $identity,
            ':email' => $identity,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    } catch (Throwable $e) {
        return null;
    }
}

// ─────────────────────────────────────────────────────────────────────────
// Daily Ledger — CSV Import / Export Helpers
// ─────────────────────────────────────────────────────────────────────────

function dlCsvResponse(string $filename, array $headers, array $rows): never
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . basename($filename) . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

    $stream = fopen('php://output', 'wb');
    if ($stream === false) {
        throw new RuntimeException('Unable to open CSV output stream.');
    }

    fputcsv($stream, $headers);
    foreach ($rows as $row) {
        $ordered = [];
        foreach ($headers as $header) {
            $ordered[] = $row[$header] ?? '';
        }
        fputcsv($stream, $ordered);
    }

    fclose($stream);
    exit;
}

function dlCsvNormalizeHeader(string $header): string
{
    $normalized = strtolower(trim($header));
    $normalized = preg_replace('/[^a-z0-9]+/', '_', $normalized) ?? '';
    return trim($normalized, '_');
}

function dlCsvRowsFromString(string $csvContent): array
{
    $csvContent = preg_replace('/^\xEF\xBB\xBF/', '', $csvContent) ?? $csvContent;
    $csvContent = trim($csvContent);
    if ($csvContent === '') {
        throw new RuntimeException('CSV content is required.');
    }

    $lines = preg_split('/\r\n|\n|\r/', $csvContent) ?: [];
    $headers = null;
    $rows = [];

    foreach ($lines as $line) {
        if (trim($line) === '') {
            continue;
        }

        // Pass the escape parameter explicitly (PHP 8.4 deprecates the default
        // `\` escape when omitted). ',', '"', '\\' preserves current behavior.
        $values = str_getcsv($line, ',', '"', '\\');
        if ($headers === null) {
            $headers = array_map(static fn(string $header): string => dlCsvNormalizeHeader($header), $values);
            continue;
        }

        // A data row may be SHORTER than the header (e.g. a missing name/price
        // cell) or LONGER (extra trailing column). array_pad() alone only fills
        // short rows, so a longer row would make array_combine() throw and abort
        // the whole import with a cryptic error. Normalize to exactly the header
        // count: short cells become null, extra cells are dropped — per-row
        // validation downstream then reports the real problem (e.g. missing
        // name/price) and skips only that row.
        $values = array_slice(array_pad($values, count($headers), null), 0, count($headers));
        $rows[] = array_combine($headers, array_map(
            static fn(mixed $value): mixed => is_string($value) ? trim($value) : $value,
            $values
        ));
    }

    if ($headers === null) {
        throw new RuntimeException('CSV header row is required.');
    }

    return $rows;
}

function dlImportReadUploadedCsv(string $field, int $maxBytes = 5242880): array
{
    $file = kernelUploadedFile($field);
    if (!is_array($file) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'status' => 422, 'error' => 'Upload a valid CSV file first.'];
    }

    $tmpPath = (string)($file['tmp_name'] ?? '');
    if ($tmpPath === '' || !is_file($tmpPath)) {
        return ['ok' => false, 'status' => 422, 'error' => 'Uploaded CSV file is not available.'];
    }

    if (PHP_SAPI !== 'cli' && function_exists('is_uploaded_file') && !is_uploaded_file($tmpPath)) {
        return ['ok' => false, 'status' => 422, 'error' => 'CSV upload did not arrive through the HTTP upload pipeline.'];
    }

    $size = (int)($file['size'] ?? 0);
    if ($size <= 0) {
        return ['ok' => false, 'status' => 422, 'error' => 'Uploaded CSV file is empty.'];
    }
    if ($size > $maxBytes) {
        return ['ok' => false, 'status' => 422, 'error' => 'Uploaded CSV file exceeds the maximum allowed size.'];
    }

    $raw = @file_get_contents($tmpPath);
    if (!is_string($raw) || trim($raw) === '') {
        return ['ok' => false, 'status' => 422, 'error' => 'Uploaded CSV file is empty.'];
    }

    return ['ok' => true, 'file' => $file, 'raw' => $raw];
}

function dlCsvNullableFloat(mixed $value): ?float
{
    if ($value === null) {
        return null;
    }

    $normalized = trim((string)$value);
    if ($normalized === '') {
        return null;
    }

    if (!is_numeric($normalized)) {
        throw new RuntimeException('Expected a numeric decimal value.');
    }

    return round((float)$normalized, 2);
}

function dlCsvNullableInt(mixed $value): ?int
{
    if ($value === null) {
        return null;
    }

    $normalized = trim((string)$value);
    if ($normalized === '') {
        return null;
    }

    if (!preg_match('/^-?\d+$/', $normalized)) {
        throw new RuntimeException('Expected an integer value.');
    }

    return (int)$normalized;
}

// ── Entity-View Capabilities ──────────────────────────────────────────

function dl_cap_entity_list_entry_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    $limit = min((int)($payload['limit'] ?? 25), 100);
    $qualifier = (string)($payload['qualifier'] ?? '');
    $typeFilter = '';
    if ($qualifier === 'sales') { $typeFilter = " AND entry_type = 'sale'"; }
    elseif ($qualifier === 'expense') { $typeFilter = " AND entry_type = 'expense'"; }
    try {
        $db = dlDb();
        $stmt = $db->query("SELECT id, entry_type, amount, notes, created_at, updated_at FROM dl_entries WHERE deleted_at IS NULL{$typeFilter} ORDER BY created_at DESC LIMIT {$limit}");
        $rows = $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
        $countStmt = $db->query("SELECT COUNT(*) FROM dl_entries WHERE deleted_at IS NULL{$typeFilter}");
        $total = $countStmt ? (int)$countStmt->fetchColumn() : count($rows);
        return ['rows' => $rows, 'total' => $total];
    } catch (\Throwable $e) {
        return ['rows' => [], 'total' => 0, 'error' => $e->getMessage()];
    }
}

function dl_cap_entity_get_entry_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    $id = (int)($payload['id'] ?? ($payload['entity_id'] ?? 0));
    if ($id <= 0) return [];
    try {
        $db = dlDb();
        $stmt = $db->prepare('SELECT * FROM dl_entries WHERE id = :id AND deleted_at IS NULL LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return is_array($row) ? $row : [];
    } catch (\Throwable $e) {
        return [];
    }
}
