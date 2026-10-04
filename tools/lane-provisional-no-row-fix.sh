#!/usr/bin/env bash
#
# Lane: provisional-no-row-fix
#
# Prevents a MATERIAL RESTATEMENT of the books before this work ships.
#
# The smart-settlement lane extracted dl_rowIsProvisional() correctly for the AM asymmetry, but it
# treats a MISSING dl_ledger_shift_status row as "not finalized". Measured on tenant 207:
#
#   shift=AM status=<no shift row>  rows=3149  units=20680   <-- would move official -> provisional
#   shift=AM status=finalized       rows=2432  units=16967
#   shift=PM status=finalized       rows=3306  units=27548
#   shift=PM status=open            rows=1988  units=17038   (already provisional before)
#   shift=PM status=<no shift row>  rows=46    units=1602    (already provisional before)
#
# A missing row is AMBIGUOUS: "never finalized" or "this shift was never tracked". 3,149 AM rows
# say the latter is at least as likely. Treating it as unfinalized moves 20,680 units of AM sales
# out of the OFFICIAL total on the strength of an ambiguity - history restated, not a bug fixed.
#
set -u
cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

LANE_MODEL_CHAIN="deepseek-v4-flash,openai-codex/gpt-5.6-sol,openai-codex/gpt-5.6-terra"

PROMPT="$(cat <<'PROMPT_EOF'
You are making ONE small correction to dl_rowIsProvisional() in the Ikabud Daily Ledger, to stop a
material restatement of official sales. Nothing else changes.

FILE: modules/daily-ledger/helpers/reporting.php

THE DEFECT
The extraction of dl_rowIsProvisional() (which correctly removed the PM-only special case) treats a
MISSING shift-status row as "not finalized":

    return (string)($row['shift_status'] ?? '') !== 'finalized';

For a row with no dl_ledger_shift_status row that is TRUE -> provisional. Measured on tenant 207:

    shift=AM status=<no shift row>  rows=3149  units=20680   <-- would move official -> provisional
    shift=PM status=<no shift row>  rows=46    units=1602

A missing row is AMBIGUOUS - it can mean "never finalized" OR "this shift was never tracked at
all". 3,149 AM rows (vs 46 PM) show the second reading is at least as likely. Treating it as
unfinalized moves 20,680 units of AM sales OUT of the official total. That is a restatement of
history on the strength of an ambiguity, and it must not ship.

REQUIRED BEHAVIOUR - a missing row is NOT evidence of anything, so the historical buckets are
PRESERVED EXACTLY. The fix then applies only where the data is unambiguous.

    function dl_rowIsProvisional(array $row): bool
    {
        if (($row['bal_end'] ?? null) === null) {
            return true;                                   // a missing ending is pending
        }

        $status = $row['shift_status'] ?? null;
        if ($status === null) {
            // No shift row is AMBIGUOUS: "never finalized" or "never tracked". Preserve the
            // historical bucketing exactly - PM was provisional, AM was official. Measured:
            // 3,149 AM rows / 20,680 units hang on this, versus 46 PM rows.
            return (string)($row['shift'] ?? '') === 'PM';
        }

        // A shift row that EXISTS and is not finalized is unambiguous, and the shift does not
        // matter: an unsigned AM is exactly as unsigned as an unsigned PM.
        return (string)$status !== 'finalized';
    }

Keep the docblock, and make the comment explain WHY the missing-row case is treated backwards
(i.e. that it preserves history rather than fixing it), so a future reader does not "correct" it
into the restatement.

HARD CONSTRAINTS
  - Change ONLY dl_rowIsProvisional() in modules/daily-ledger/helpers/reporting.php. Do not touch
    dl_settleUnfinalizedRow, dl_reportSalesData, any other helper, any guard, or any template.
  - Do NOT edit, skip or weaken ANY test.
  - Do NOT write anything derived into a counted column (bal_end / actual_end_qty).

STEP 2 - VERIFY, and paste the actual output:
  a) php -l modules/daily-ledger/helpers/reporting.php
  b) THE ORACLE. It reads 55/56 now (the AM no-shift-row assertion fails); it must read 56/56:
       php tests/daily-ledger/daily_ledger_production_controls_test.php
  c) PROVE NO ROW CHANGES BUCKET versus HEAD. Measure, do not assert - write a throwaway probe
     under /tmp that bootstraps the app the way the tests do
     (TestHarness MODE_INTEGRATION 'baronledger.test', app()->tenant()->setTenantId(207),
      modulePushContext('daily-ledger')) and, for every row of dl_daily_ledger joined to
     dl_ledger_shift_status, compare the OLD rule
         ($pending) || (shift==='PM' && shift_status !== 'finalized')
     against the NEW dl_rowIsProvisional($row), over the whole table. Report the count of rows
     where they DISAGREE. It must be 0, and you must paste the number.
     If it is not 0, STOP and report BLOCKED with the rows that disagree - do not proceed.
  d) REGRESSION
       php tests/daily-ledger/daily_ledger_production_sheet_test.php             # 53/53
       php tests/daily-ledger/daily_ledger_both_shifts_coverage_test.php         # 8/8
       php tests/daily-ledger/daily_ledger_addtl_correction_test.php             # 83/83
  e) Both logs: storage/logs/app.log and storage/logs/error.log - no new errors.

STEP 3 - report compactly:
  status: PASS | FAIL | BLOCKED
  changed:
  implementation_summary:
  bucket_disagreements_vs_HEAD:   <the measured number - must be 0>
  verification:  (the actual commands and their outcome)
  logs:
  risks / unresolved:

Never edit a test to obtain a green result.
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/provisional-no-row-fix
rc=$?
echo "lane: provisional-no-row-fix — completed by: ${LANE_MODEL_USED:-<none>} (rc=$rc)"
exit "$rc"
