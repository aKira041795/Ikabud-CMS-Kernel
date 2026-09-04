# Daily Ledger — DR-less Production Receives, Box Withdrawals & Cashier Withdrawal Edit

**Status:** APPROVED (chair, 2026-09-04) — additive implementation; owner reviews later.
**Debate:** 3 rounds (chair draft → Code Reviewer critic `REVISIONS` → converged). Round artifacts in
`.ai/debate/`; session record in `/memories/session/dl-debate-contract.md`.

## Problem statements

1. **DR-less receive from a production branch.** A cashier receiving stock that physically came from a
   production-capable branch cannot record it when there is no paper DR and no pending dispatch — the
   Receive by Paper DR path hard-requires `dr_number` (`modules/daily-ledger/handlers.php:4485`,
   `receive_modal.disyl` JS validation). Requested: an auto DR `dd/mm/yyyy-n` so the movement is
   traceable in the delivery/activity log.
2. **Box-unit withdrawals.** Products like *crinkles* are sold per piece AND per box. A cashier Stock
   Adjustment (`modal_patch.disyl`) can only enter integer **Pcs** — there is no unit model, and the box
   (which arrived DR-less) has no DR to reference. `dl_cashier_withdrawals` has no `unit` column
   (migration 020); `dl_products` has no pack size (migrations 003/006).
3. **Cashier withdrawal edit on retry.** Withdrawals are INSERT-only (`apiSaveCashierWithdrawals`,
   `handlers.php:3647`); duplicate protection is client idempotency + DB `dedup_hash` UNIQUE
   (migration 052). A slow-internet retry that needs a quantity/type correction has no cashier path;
   `handleAdminWithdrawals` (`handlers.php:11153`) is read-only.

## Decisions (converged)

### D1 — Auto-DR production receive (additive, online-only)
- Eligibility: `origin_type='commissary'` (production site `is_commissary=1`), active, `origin_id != destination`.
- New `auto_dr=1` on the Receive-by-Paper-DR API: when set, `dr_number` optional → server mints
  `AUTO-dd/mm/yyyy-n` (namespaced to avoid collision with paper-DR lookup at `handlers.php:4518`).
  Counter = count of existing `AUTO-…` rows for destination+delivery_date + 1, minted in-tx after
  `dl_lockDayStatusRow` (`handlers.php:106`) FOR UPDATE.
- Creates posted `dl_deliveries` (origin_type `commissary`) + items + `dl_acceptFormalDelivery`
  receiving; `provenance_status='none'` (authoritative, **not** a paper-DR exception — no enum ALTER);
  `remarks='[auto-dr-production]'`. Credits destination `addtl` only; **no origin debit** (consistent
  with existing code that only debits origin when `origin_type='branch'`, `handlers.php:4569`).
- Audit `create_delivery` with `source='auto_dr_production'` + `dr_number`.
- UI: checkbox in Receive Stock → Paper DR section when origin is a production branch
  ("No paper DR — auto-generate"). Online-only (like delivery correction).

### D2 — Box/unit withdrawals (additive, behavior-neutral)
- Migration 054 (guarded `SET @sql=IF(...)` ALTERs, MySQL 5.7-safe):
  - `dl_products.pcs_per_pack INT UNSIGNED NULL` (NULL = not sold by box → unchanged behavior).
  - `dl_cashier_withdrawals.unit VARCHAR(10) NOT NULL DEFAULT 'pcs'`,
    `dl_cashier_withdrawals.pack_qty INT UNSIGNED NULL`.
- Amendment (review pass): **migration 055** adds `dl_cashier_withdrawals.shift VARCHAR(2) NULL`
  (AM/PM), recorded on new inserts (online + offline). `dl_daily_ledger` is shift-scoped, so the
  audited edit must recompute the ledger row of the shift the withdrawal was encoded under (legacy
  NULL rows fall back to the actor's resolved shift, best-effort). `dl_assertShiftMutable` + the
  today-list filter use the row's shift.
- `quantity` stays **pieces** (backward compat for ledger/variance math). A `unit='box'` line requires
  `pcs_per_pack > 0`; `quantity = box_qty × pcs_per_pack`; `pack_qty = box_qty` stored for provenance.
- `dl_withdrawalDedupHash` gains a `unit` argument (helpers.php:44 + offline copy) so "2 boxes" and
  "48 pcs" (equal pieces) are distinct rows. Existing rows keep hashes (column default, no backfill).
- Server accepts per-line `unit` in `apiSaveCashierWithdrawals` + offline mirror (`handlers-offline.php`).
  `unit='box'` with `pcs_per_pack` null → 422.
- UI: Withdrawal modal per-line unit select (Pcs default; Box enabled when the product has
  `pcs_per_pack`), product options carry `pcs_per_pack` via the ledger-row payload + product reference.
- Product admin (`apiCreateProduct`/`apiUpdateProduct`) accepts optional `pcs_per_pack`.

### D3 — Cashier edit of own withdrawal with audit (additive, online-only)
- New `POST /daily-ledger/api/v1/cashier/ledger/withdrawals/edit` → `apiUpdateCashierWithdrawal`.
- Authorization: cashier edits **own rows** (`encoded_by = actor`); admin/supervisor may edit any row
  in their accessible branch set. Window: `ledger_date == business date`, day open, shift mutable —
  in-tx re-check via `dl_lockDayStatusRow` + `dl_assertShiftMutable` + `dl_cashierMayEdit`.
- Editable types: `charge`, `pullout`, `adjustment_add` only.
- **Blocked:** rows with `received_at IS NOT NULL`; `pullout` rows with `target_branch_id` (the
  commissary-return chain at `handlers.php:3860-3995` would desync — admin corrects those instead).
- Recompute: `charge`/`pullout` → `withdraw = SUM(quantity WHERE type <> 'adjustment_add')` (existing
  path); `adjustment_add` → **delta** on the shift-scoped addtl row (`addtl -= old; addtl += new`), not
  a SUM recompute.
- `dedup_hash` recomputed after update; duplicate-key on `uq_dl_cw_dedup` → 409 + clear message.
- Audit `dl_auditLog('withdrawal_updated', branch, 'dl_cashier_withdrawals', rowId, oldRow, newRow)`;
  `dl_recomputeVariancesForDay` after.
- GET prefill: extend the existing withdrawal fetch payload (id, type, reason_code, custom_reason,
  dr_number, target_branch_id, quantity, liable_user_id, created_at).
- UI: small "Today's adjustments" edit affordance on the cashier ledger page (reviewed in review pass).
- Online-only (mirrors delivery correction); offline edits would require schema-version bump → excluded.

## Scope
- Additive only. No delete/void. Closed-day immutability preserved. No changes to existing INSERT
  semantics, no weakening of dedup/idempotency, no enum ALTERs, MySQL 5.7-safe (no window/CTE).
- Module files only: `modules/daily-ledger/` + cashier templates + one migration + one test file.

## Acceptance
- A1: cashier can receive from a production branch without a DR; delivery log + audit show `AUTO-dd/mm/yyyy-n`.
- A2: withdrawal modal can record a box; ledger math uses pieces; box vs pc rows are distinct (dedup).
- A3: cashier edits own today-open withdrawal; `withdrawal_updated` audit old→new; totals correct;
  closed-day / received / pullout-commissary rows rejected; duplicate hash update → 409.
- Tests: new integration suite; both `app.log` + `error.log` clean after run.

## Risk
- D1 origin-outflow reconciliation relies on production movements/commissary ledger recording origin
  outflow (same as existing commissary paper-DR semantics). If a site ships from a commissary without
  recording production, an admin audit remains required — documented limitation.
- D3 pullout-to-commissary edit intentionally excluded (v1); supervisor/admin reopen path covers it.
