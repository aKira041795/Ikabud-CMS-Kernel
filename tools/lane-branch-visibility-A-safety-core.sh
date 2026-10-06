#!/usr/bin/env bash
#
# Lane: branch-visibility-A-safety-core
#
# SLICE A of the per-branch product visibility feature. Owner approved all six decisions on 2026-10-06
# ("all items: Yes"). Contract: .ai/dl-branch-product-visibility.contract.md
#
# Slice A is deliberately INVISIBLE: no UI, no picker, no endpoint that an admin can reach. It exists
# because the Sol review (verdict UNSOUND) proved that a visibility toggle whose write paths ignore
# assignment is worse than no toggle — data keeps arriving for hidden products and silently strands
# unfinished rows. A MUST land before B is usable.
#
# What is already true (do not re-do, verified by the chair):
#   - Both sheet LIST queries already filter `bp.is_active = 1` on their own branch
#     (dl_fetchCashierLedgerRows handlers.php:6421, dl_fetchProductionSheetProducts handlers.php:715).
#   - Tenant 207 invariant: 182 products x 19 branches = 3458 active pairs, 0 hidden. So every guard
#     added here is a no-op on today's data and MUST NOT change any existing behaviour.
#   - The manual production PM finalize gate is STRICTER than the rest (handlers.php:17743-17751):
#     every ASSIGNED product needs a PM ending even with zero movement. Do not touch that gate here.

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

LANE_MODEL_CHAIN="deepseek-v4-flash,openai-codex/gpt-5.6-terra,openai-codex/gpt-5.6-sol"

PROMPT="$(cat <<'PROMPT_EOF'
You are implementing SLICE A (safety core) of a per-branch product-visibility feature in the Ikabud repo at
/var/www/html/applicationostest. PHP 8.5 local; PRODUCTION IS MYSQL 5.7 on shared hosting and no 5.7 server
exists locally — so any 5.7 claim you make is INSPECTION-ONLY and must be labelled as such.

READ FIRST:
  .ai/dl-branch-product-visibility.contract.md   (the approved contract + the six owner decisions)
  modules/daily-ledger/handlers.php:6421  dl_fetchCashierLedgerRows()
  modules/daily-ledger/handlers.php:715   dl_fetchProductionSheetProducts()
  modules/daily-ledger/handlers.php:4994  dl_shiftMissingEndings()
  modules/daily-ledger/handlers.php:8745-8810, 9140-9255, 6951-7040, 4371-4414   (write paths)
  modules/daily-ledger/handlers-offline.php:402-492   (offline replay)
  modules/daily-ledger/handlers.php:15234-15240  apiCreateBranch()
  modules/daily-ledger/database/migrations/075_offline_refusal_visibility.sql  (guarded-migration pattern)

## A1 — schema (guarded, additive, 5.7-safe, re-runnable)
Add to `dl_products`: `assignment_mode ENUM('all_active','specific') NOT NULL DEFAULT 'all_active'`.
Use the INFORMATION_SCHEMA + PREPARE guard pattern from migration 075 so re-running is harmless. Default
`all_active` is what preserves today's behaviour for all 182 existing products. No data backfill needed.

## A2 — the removal guard (pure functions, unit-testable, no UI)
  dl_branchProductUnassignmentBlockers($db, int $branchId, int $productId): array
Return the list of BLOCKING date+shift entries for hiding that pair. Blocking requires ALL of:
  - the row is on the CURRENT business date, or on an earlier date whose dl_ledger_day_status status is
    absent/'open' (a CLOSED day must NEVER block);
  - the row bears activity and its ending is missing.
Cashier ledger predicate:  bal_end IS NULL AND (beg_bal <> 0 OR addtl <> 0 OR withdraw <> 0)
Commissary ledger predicate: actual_end_qty IS NULL AND (beg_qty <> 0 OR produced_qty <> 0 OR
                             dispatched_qty <> 0 OR wastage_qty <> 0)
The commissary ledger is dl_commissary_product_ledger (see the gate at handlers.php:17743 for its shape).
Return every blocking date/shift, never just a count, and return [] when nothing blocks.
Also: dl_productDeactivationBlockers($db, int $productId): array — the SAME check across ALL branches, for
global `dl_products.is_active = 0`, which currently has NO guard at all (contract decision: same guard).

## A3 — write-side assignment validation (the core of this slice)
A write must be REJECTED, loudly and durably, when the (branch, product) pair is not active. Cover:
  1. cashier single-field save        handlers.php ~8745-8810
  2. cashier batch save               handlers.php ~9140-9255
  3. offline replay                   handlers-offline.php ~402-492
  4. cashier withdrawal lines         handlers.php ~6951-7040
  5. production movement              handlers.php ~4371-4414
Reject with a machine-readable code (e.g. 'PRODUCT_NOT_ASSIGNED') plus a human message naming the product
and branch. Do NOT silently drop the write and do NOT create a ledger row.
IMPORTANT — reuse, do not invent: the module ALREADY retains a refused cashier save rather than destroying
it (see commit 5e227c0d and the held/quarantine mechanism in the cashier sheet). Your rejection must surface
through that EXISTING path. Find it and reuse it. Only if no such path exists for a given write type may you
add minimal handling, and you must say so explicitly.

## A4 — apiCreateBranch() honours the mode
`apiCreateBranch()` currently assigns ALL active products to a new branch (handlers.php:15234-15240). Per
owner decision 1 it must assign ONLY products with `assignment_mode = 'all_active'`. Products explicitly set
to 'specific' must NOT be auto-assigned to a later branch.

## A5 — audit
Every unassignment / global deactivation attempt writes an audit row recording who, the pair, and whether it
was refused. Follow the existing dl_auditLog conventions.

## HARD CONSTRAINTS
- Slice A must change NOTHING observable on today's data. Tenant 207 has 0 hidden pairs and 0 inactive
  products, so every new guard must pass for every existing write. Prove it (see acceptance).
- Do NOT build the picker, the bulk endpoint, or any admin UI. That is Slice B.
- Do NOT change the sheet list queries, the carry, the close-order behaviour (commit 67255d4b), or
  handleAdminSales (commits 32f2e431/7eda56e3 — its UNION must keep showing any pair holding a ledger row).
- Do NOT touch public/daily-ledger/assets/ or sw.js in this slice (a change there requires a CACHE_VERSION
  bump; if you believe you must, STOP and report BLOCKED with the reason).
- Do NOT change dl_reportSalesData() or any report/export.
- MySQL 5.7-safe: no CTE, no window function, no JSON_TABLE, no LIMIT inside IN (subquery).
- Keep storage/logs/error.log empty.

## ORACLE — required, and it must DISCRIMINATE on the base tree
Extend/add tests under tests/daily-ledger/ (follow the existing TestHarness pattern; use a private fixture
branch, never real branch 8). Required cases:
  G1. a hidden pair (bp.is_active=0) with an activity-bearing, ending-less row on an OPEN day -> unassignment
      is REFUSED and the blocker list names that exact date+shift.
  G2. a closed day with the same row shape -> does NOT block (must be unassignable immediately).
  G3. an open day, row exists, zero activity, ending NULL -> does NOT block.
  G4. an open day, row exists, ending present -> does NOT block.
  G5. after entering the missing ending, the identical unassignment SUCCEEDS.
  G6. run G1-G5 for BOTH the cashier ledger and the commissary ledger predicates.
  G7. global deactivation is refused for the same conditions and permitted once completed.
  G8. WRITE VALIDATION, the discriminating case: for a hidden pair, the cashier batch save is REJECTED with
      PRODUCT_NOT_ASSIGNED and NO ledger row is created. (This FAILS on the base tree — the base accepts the
      write. This is the case that proves the slice does something.)
  G9. G8 repeated for single-field save, offline replay, withdrawal, and production movement.
  G10. apiCreateBranch() does NOT assign a 'specific' product, and DOES assign an 'all_active' one.
  G11. NO-OP PROOF on existing data: with every pair active (the real invariant), a normal cashier batch save
      and a normal production movement still SUCCEED unchanged.
State in your report which cases fail on the base tree and which pass; do not claim discrimination you did
not observe.

## ACCEPTANCE — run and report verbatim
    php tests/daily-ledger/daily_ledger_close_day_and_notify_test.php
    php tests/daily-ledger/daily_ledger_admin_sales_full_sheet_test.php
    php tests/daily-ledger/<your new test(s)>.php
    php -l modules/daily-ledger/handlers.php && php -l modules/daily-ledger/handlers-offline.php
    git --no-pager diff --stat
    php /tmp/chair-measure-branch-visibility.php
Then the daily-ledger suite via scripts/run-tests.php --dir=tests/daily-ledger, reporting pass counts and
distinguishing PRE-EXISTING failures (known: daily_ledger_dispatch_enforcement_test and
daily_ledger_preserve_cashier_variance_test, notification pollution; daily_ledger_delivery_record_authz_test
is flaky on log pollution) from new ones. Never reach green by weakening an assertion.

## Rules
- Smallest correct change. No refactor, no new dependency, no scope creep, no UI.
- Never weaken, skip, or delete an assertion to reach green.
- If write-side validation cannot be added without breaking a current legitimate write, STOP and report
  BLOCKED with the exact case rather than loosening the check to make tests pass.
- Report status PASS | PARTIAL | BLOCKED, files changed, tests added/changed, measured evidence, and any
  claim you could not verify.
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/branch-visibility-A-safety-core
rc=$?
echo "lane: branch-visibility-A-safety-core — completed by: ${LANE_MODEL_USED:-<none>} (rc=$rc)"
exit "$rc"
