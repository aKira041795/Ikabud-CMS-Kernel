#!/usr/bin/env bash
#
# Lane: smart-settlement
#
# Owner: "i want the daily ledger to be also smart. this is for both cashier and production
#         sheets" - the three rules, with the chair's two caveats ACCEPTED ("your flagged points
#         are accepted. i agree").
#
# Contract: .ai/daily-ledger-smart-settlement.contract.md (rulings R1-R9, all measured evidence
# in M1-M5).
#
# MODEL: flash first. The judgement is already made and recorded in the contract; this is
# implementation. Sol led the previous lane and HUNG for its full 40-minute budget producing
# nothing - the fallback chain only fires on an EXIT, so a hang burns the whole lane. Keep the
# chain for fallback depth, but do not put the slow model first on a task this well specified.
#
set -u
cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

LANE_MODEL_CHAIN="deepseek-v4-flash,openai-codex/gpt-5.6-sol,openai-codex/gpt-5.6-terra"

PROMPT="$(cat <<'PROMPT_EOF'
You are implementing the "smart settlement" of unfinalized AM/PM shifts in the Ikabud Daily
Ledger. The analysis is DONE and MEASURED - it is in the contract. Your job is the code.

STEP 1 - read the contract and follow it exactly, including every ruling R1-R9:
  /var/www/html/applicationostest/.ai/daily-ledger-smart-settlement.contract.md
The measured evidence is in M1-M5. Do not re-derive it; do not "improve" the design.

WHAT TO BUILD - two pure predicates plus ONE piece of wiring.

A. modules/daily-ledger/handlers.php :: dl_settleUnfinalizedRow (R1, R8)
   EXACT signature and rung strings (the oracle asserts the strings literally):
     function dl_settleUnfinalizedRow(
         ?int $countedEnd, bool $shiftFinalized, int $movements,
         ?int $nextBeginning, bool $nextBeginningIsIndependent
     ): array   // ['rung' => string, 'ending' => ?int, 'sales' => ?int, 'official' => bool]

   Rungs, in precedence order, and THERE IS NO SIXTH RUNG:
     'counted'                 $countedEnd !== null && $shiftFinalized
                                 ending = $countedEnd; sales = max(0, $movements - $countedEnd); official = true
     'counted-unsigned'        $countedEnd !== null && !$shiftFinalized
                                 same numbers; official = false
     'derived-next-beginning'  $countedEnd === null && $movements !== 0
                                 && $nextBeginning !== null && $nextBeginningIsIndependent
                                 ending = $nextBeginning; sales = max(0, $movements - $nextBeginning); official = false
     'derived-from-movements'  $countedEnd === null && $movements !== 0
                                 ending = $movements; sales = 0; official = false
     'zero-forced'             $countedEnd === null && $movements === 0
                                 ending = 0; sales = 0; official = false

   CRITICAL: a RECORDED ZERO ($countedEnd === 0) is a COUNT, not a missing value. Only null
   reaches the derived rungs. And the circularity guard is the point of the whole design: when
   $nextBeginningIsIndependent is FALSE the rung MUST NOT be 'derived-next-beginning' - it falls
   through to 'derived-from-movements'. Add a comment saying that a carried beginning is a COPY of
   the very ending being estimated, so using it would be estimating a value from itself.

B. modules/daily-ledger/helpers/reporting.php :: dl_rowIsProvisional (R5, R9)
     /** Is this ledger row signed off, or merely provisional? AM and PM alike. */
     function dl_rowIsProvisional(array $row): bool
   TRUE when the row's 'bal_end' is null (pending), OR when the row's shift is NOT finalized -
   for ANY shift, not only PM. Then REPLACE the inline expression at reporting.php:163-164 with a
   call to it, so the report cannot drift from the predicate.
   MEASURED DEFECT it fixes: today an AM row with a recorded ending on an unfinalized AM shift
   satisfies neither clause and is bucketed OFFICIAL - unsigned sales in the official total.

C. WIRING (R3, R6) - this must not be dead code. In dl_reportSalesData(), a row whose shift is not
   finalized must be bucketed by its SETTLED value rather than by the raw stored sales, using the
   ladder and the caller's OWN movement invariant:
     cashier (dl_daily_ledger):    movements = COALESCE(beg_bal,0) + COALESCE(addtl,0) - COALESCE(withdraw,0)
     production (dl_commissary_product_ledger): BEG + ADD/L - TOTAL - WASTAGE   (its own header formula)
   Do NOT unify those two formulas (R6) - pass the movements in.

HARD CONSTRAINTS (a violation fails the lane)
  - R2, THE MOST IMPORTANT ONE: NEVER write a derived ending into a counted column. No UPDATE of
    dl_daily_ledger.bal_end or dl_commissary_product_ledger.actual_end_qty from any rung. The
    ladder is COMPUTED. If you write an inferred number into a counted column, the books rest on
    an assumption nobody can identify afterwards.
  - R3: only the 'counted' rung is official. No derived value may enter the official total.
  - R7: no migration, no new setting, no new column, no new permission.
  - Do NOT touch the save paths, any guard, the auto-close, the reopen, the finalize, or the
    templates other than the reporting helper above.
  - Do NOT edit, skip or weaken ANY test.
  - Do NOT remove the max(0, ...) clamp.

STEP 2 - VERIFY, in this order, and paste the actual output:
  a) php -l modules/daily-ledger/handlers.php
     php -l modules/daily-ledger/helpers/reporting.php
  b) THE PRIMARY ORACLE. It reads 41/43 now (the 11 detail assertions are parked behind the two
     missing seams); it must read 54/54:
       php tests/daily-ledger/daily_ledger_production_controls_test.php
     The section 'settling a shift nobody finalized' asserts the whole ladder truth table,
     including the circularity guard and that a recorded zero is a count. The provisional section
     asserts that an unfinalized AM shift is provisional.
  c) REGRESSION - all must hold:
       php tests/daily-ledger/daily_ledger_production_sheet_test.php             # 53/53
       php tests/daily-ledger/daily_ledger_production_beg_carry_test.php         # 12/12
       php tests/daily-ledger/daily_ledger_both_shifts_coverage_test.php         # 8/8
       php tests/daily-ledger/daily_ledger_daily_sheet_log_evidence_test.php     # 17/17
       php tests/daily-ledger/daily_ledger_addtl_correction_test.php             # 83/83
       php tools/disyl-conformance-check.php
  d) BOTH SHEETS STILL WORK END TO END (a derived label must never block entry):
       cd /var/www/html/applicationostest && APP_URL=http://baronledger.test \
         npx playwright test tests/browser/daily-ledger-nextday-entry.spec.js --reporter=line
       APP_URL=http://baronledger.test npx playwright test tests/browser/daily-ledger-reopen-edit.spec.js --reporter=line
       APP_URL=http://baronledger.test npx playwright test tests/browser/daily-ledger-close-failure-guidance.spec.js --reporter=line
     must be 1 passed, 1 passed, 2 passed.
  e) Both logs: storage/logs/app.log and storage/logs/error.log - no new errors.

STEP 3 - report compactly:
  status: PASS | FAIL | BLOCKED
  changed:
  implementation_summary:  (say explicitly where the ladder is CONSUMED, so it is not dead code)
  verification:  (the actual commands and their outcome)
  logs:
  risks / unresolved:

Never edit a test to obtain a green result. If satisfying the oracle would require writing a
derived value into a counted column, STOP and report BLOCKED.
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/smart-settlement
rc=$?
echo "lane: smart-settlement — completed by: ${LANE_MODEL_USED:-<none>} (rc=$rc)"
exit "$rc"
