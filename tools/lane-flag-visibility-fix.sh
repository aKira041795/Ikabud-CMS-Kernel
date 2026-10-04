#!/usr/bin/env bash
#
# Lane: flag-visibility-fix
#
# CORRECTS the ordering the first revision could not know it needed (contract R6, added after
# that revision reported BLOCKED).
#
# MEASURED BASELINE on this tree - ONE assertion fails, and it names the defect exactly:
#   'the render that flags the day also notifies on that render (no reload required)'
#   {"flag_now_on_row":true,"banner_shown":false}
# The flag IS written; the render that wrote it shows NO warning. The admin only sees the banner
# after a reload, i.e. "notified" degrades into "notified next time".
#
# The first revision read pm_flag BEFORE dl_maybeAutoFinalizeCommissaryPmShift(). It did that to
# work around a broken oracle fixture (which the chair has now fixed by pinning the fixture with
# the evaluator's own reopened_at exemption), so the early read has no remaining justification.
#
set -u
cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

LANE_MODEL_CHAIN="deepseek-v4-flash,openai-codex/gpt-5.6-sol,openai-codex/gpt-5.6-terra"

PROMPT="$(cat <<'PROMPT_EOF'
You are CORRECTING one ordering defect in the Ikabud Daily Ledger module. The rest of the
feature is already correct and passing - do not rewrite it.

STEP 1 - read the contract, especially the NEW ruling R6:
  /var/www/html/applicationostest/.ai/daily-ledger-flag-visibility.contract.md

WHAT ALREADY WORKS (40 of 41 oracle assertions pass): the pm_flag render var, the
id="production-pm-flag" role="status" banner, the wording, the non-admin gate, and the rule that
a finalized shift never shows it. Leave all of that alone.

THE ONE DEFECT
In handleAdminCommissary, pm_flag is computed BEFORE the request-triggered evaluator:

    dl_maybeAutoFinalizeCommissaryPmShift($sheetSourceBranchId, $rawDate, dl_getActorUserId($user));
    ...  <- pm_flag is read somewhere ABOVE this call

Move the pm_flag computation to AFTER that call (R6). Reason, measured: the evaluator runs ON
RENDER and can FLAG the day. Reading the flag before it means the very render that flags a day
shows NO warning, and the admin sees the banner only after a reload.
Evidence from the oracle on the current tree:
    'the render that flags the day also notifies on that render (no reload required)'
    {"flag_now_on_row":true,"banner_shown":false}

That is the ONLY failing assertion. Do not "fix" it in any other way - not by changing the
evaluator, not by touching the template, not by touching the test.

HARD CONSTRAINTS
  - Do NOT change dl_maybeAutoFinalizeCommissaryPmShift or any other evaluator, guard, write
    path, the completeness gate, the reopen or the finalize (R3).
  - Do NOT touch the cashier ledger, its template or its handler.
  - Do NOT change the banner markup, its id, its wording or its role gate - those are asserted
    and passing.
  - Do NOT edit, skip or weaken ANY test.

STEP 2 - VERIFY, in this order, and paste the actual output:
  a) php -l modules/daily-ledger/handlers.php
  b) THE PRIMARY ORACLE. It reads 40/41 now; it must read 41/41:
       php tests/daily-ledger/daily_ledger_production_controls_test.php
  c) THE OWNER'S PRINCIPLE MUST STILL HOLD (a flag must never block entry):
       cd /var/www/html/applicationostest && APP_URL=http://baronledger.test \
         npx playwright test tests/browser/daily-ledger-nextday-entry.spec.js --reporter=line
     must be "1 passed". If it fails, you have blocked entry - report BLOCKED.
  d) REGRESSION
       APP_URL=http://baronledger.test npx playwright test tests/browser/daily-ledger-reopen-edit.spec.js --reporter=line
       php tests/daily-ledger/daily_ledger_production_shift_access_test.php     # 14/14
       php tests/daily-ledger/daily_ledger_production_sheet_test.php            # 53/53
       php tools/disyl-conformance-check.php
  e) Both logs: storage/logs/app.log and storage/logs/error.log - no new errors.

STEP 3 - report compactly:
  status: PASS | FAIL | BLOCKED
  changed:
  implementation_summary:
  verification:  (the actual commands and their outcome)
  logs:
  risks / unresolved:

Never edit a test to obtain a green result.
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/flag-visibility-fix
rc=$?
echo "lane: flag-visibility-fix — completed by: ${LANE_MODEL_USED:-<none>} (rc=$rc)"
exit "$rc"
