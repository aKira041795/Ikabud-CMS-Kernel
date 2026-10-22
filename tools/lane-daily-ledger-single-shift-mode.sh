#!/usr/bin/env bash
#
# Lane: daily-ledger-single-shift-mode
#
# Client request 3 (2026-10-22): "add option for shift: whole day - there are branches where
# there's only one cashier shift." Owner decisions: branch mode (not a third ENUM), auto-close,
# pilots Fishport 15 / Bagting 17 / Minaog 16.
#
# Contract: .ai/daily-ledger-client-batch-2026-10-22.contract.md (REQUEST 3, R5-R7)
#
# MEASURED BASELINE on this tree - acceptance FAILS for the right reasons:
#   php tests/daily-ledger/daily_ledger_single_shift_mode_test.php -> 0/5 executed, exit 1
#   (behaviour section skipped until dl_branchSingleShiftMode + dl_isLastShiftOfDay exist)
#
set -u
cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

LANE_MODEL_CHAIN="openai-codex/gpt-5.6-sol,deepseek-v4-flash,openai-codex/gpt-5.6-terra"

PROMPT="$(cat <<'PROMPT_EOF'
You are implementing ONE bounded, additive change in the Ikabud Daily Ledger module.

STEP 1 - read the contract, REQUEST 3 and rulings R5-R7:
  /var/www/html/applicationostest/.ai/daily-ledger-client-batch-2026-10-22.contract.md

THE ACCEPTANCE TEST ALREADY EXISTS AND IS THE SPECIFICATION. It MUST NOT BE MODIFIED:
  tests/daily-ledger/daily_ledger_single_shift_mode_test.php
It currently fails with 0 executed assertions passing. Target is 13 passed / 0 failed, exit 0.
(The base run reports only 5 executed because the 8 behaviour assertions are skipped until the seams
exist. 5 is NOT the target.) If an assertion is wrong, STOP and report BLOCKED - do not edit the test.

THE MODEL (do not deviate)
A branch has ONE shift, not a third kind of shift. Rows stay shift='AM'. The mode only changes
(a) which shift writes resolve to, and (b) which shift counts as day-end for that branch.
Do NOT add a value to any `shift ENUM('AM','PM')`. Do NOT touch existing rows or indexes.

WHAT TO BUILD

1. SEAMS - modules/daily-ledger/handlers.php
   function dl_branchSingleShiftMode(int $branchId): bool
     - reads dl_branches.single_shift_mode (added by migration 094, DEFAULT 0)
     - returns FALSE on any read failure (fail open = two-shift = today's behaviour)
     - DO NOT MEMOISE (ruling R6 - the acceptance test flips the mode inside one request)
   function dl_isLastShiftOfDay(string $shift, int $branchId): bool
     - 'PM' -> TRUE always (historical PM rows must stay closeable)
     - 'AM' -> dl_branchSingleShiftMode($branchId)
     - anything else -> FALSE

2. SHIFT RESOLUTION - dl_resolveLedgerShift() in handlers.php
   Resolve the branch in this order (ruling R5): $input['branch_id'] when present and > 0, else
   dl_getUserBranchId(), else 0. Do NOT read $user['branch_id'] - that key does not exist here.
   When the branch is in single-shift mode, return shift 'AM' (bound unchanged) EVEN IF the caller
   explicitly asked for PM. Two-shift branches must behave exactly as before.

3. DAY CLOSE - dl_maybeAutoFinalizeCommissaryPmShift() in handlers.php
   Replace the hardcoded PM assumption with dl_isLastShiftOfDay($shift, $branchId) so a single-shift
   branch auto-closes on AM. Two-shift branches must still close on PM only.

4. REPORTING - modules/daily-ledger/helpers/reporting.php
   The shift-reconciliation fallback around line 257 hardcodes `shift = 'PM'` as day-end. Make it use
   dl_isLastShiftOfDay() so a single-shift branch reconciles against its AM row. Do not change the
   numbers a two-shift branch produces.

5. BRANCH ADMIN - templates/modules/daily-ledger/admin/branches.disyl + the branch save path
   Add a "Single cashier shift (whole day)" checkbox next to the Paper DR checkbox added in the
   previous lane, persisted like that one (FILTER_VALIDATE_BOOLEAN + FILTER_NULL_ON_FAILURE).
   RULING R7: the save path MUST refuse to change single_shift_mode while the branch has rows in
   dl_daily_ledger for the current business date, returning 422 with a clear message. Changing mode
   mid-day orphans the carry-forward chain. Include the column in the audit before/after SELECTs.

6. OFFLINE - modules/daily-ledger/handlers-offline.php
   Carry the branch mode (and the resolved single shift) in the bootstrap payload / window shift
   resolution so an offline client does not offer the other shift for a single-shift branch.

CONSTRAINTS (non-negotiable)
- ADDITIVE: default 0 means every existing branch keeps two-shift behaviour, untouched.
- Do not change the shift ENUM, the unique key, or any existing row.
- Do not touch paper-DR gating (already shipped in 98ac10b1), ledger writes, variance/provenance,
  or the commissary modal (request 2, a later lane).
- MySQL 5.7 compatible SQL only.
- php -l on every changed PHP file; `php ikabud disyl:lint` on brances.disyl.

VERIFY (report the actual commands and output)
  a) php tests/daily-ledger/daily_ledger_single_shift_mode_test.php     # 13 passed / 0 failed, exit 0
  b) php tests/daily-ledger/daily_ledger_paper_dr_branch_test.php       # must stay 14/14 (regression)
  c) php tests/daily-ledger/daily_ledger_official_start_test.php        # must stay 23/23
  d) php tests/daily-ledger/daily_ledger_b2b_accountability_test.php    # must stay 14/14
  e) php tests/daily-ledger/daily_ledger_both_shifts_coverage_test.php  # two-shift coverage unchanged
  f) php -l on every changed PHP file
  g) BOTH storage/logs/app.log and storage/logs/error.log after the runs
Known pre-existing, do NOT try to fix: admin_trace 66/67, branch_order 10/13, branch cell entry 28/30,
consignee activity 9/10, consignee audit 7/8.

Report compactly:
  status: PASS | FAIL | BLOCKED
  changed:
  implementation_summary:
  verification:  (commands + real output)
  logs:
  risks / unresolved:

If a two-shift branch would change behaviour, STOP and report BLOCKED - that is the one outcome this
change must never produce. Never edit a test to get green.
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/daily-ledger-single-shift-mode
rc=$?
echo "lane: daily-ledger-single-shift-mode — completed by: ${LANE_MODEL_USED:-<none>} (rc=$rc)"
exit "$rc"
