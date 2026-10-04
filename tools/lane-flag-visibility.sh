#!/usr/bin/env bash
#
# Lane: flag-visibility
#
# Owner principle: "our point is that data entry is not hampered rather flagged and notified
# to admin and user."
#
# The first half is already true and pinned by tests/browser/daily-ledger-nextday-entry.spec.js.
# The second half is NOT: dl_ledger_shift_status.pending_notified_at is written by the PM
# auto-close and reset on reopen, and - measured by repo-wide grep - READ BY NOTHING. The audit
# rows it writes do not surface either, because the admin-only management log filters to
# action='production_ledger_change'. So a day left unclosed was visible on no screen.
#
# Contract: .ai/daily-ledger-flag-visibility.contract.md
#
set -u
cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

# COST POLICY: implementation belongs to DeepSeek; three models kept for fallback depth.
LANE_MODEL_CHAIN="deepseek-v4-flash,openai-codex/gpt-5.6-sol,openai-codex/gpt-5.6-terra"

PROMPT="$(cat <<'PROMPT_EOF'
You are implementing one bounded change in the Ikabud Daily Ledger module.

STEP 1 - read the contract and follow it exactly, including rulings R1-R5:
  /var/www/html/applicationostest/.ai/daily-ledger-flag-visibility.contract.md

THE OWNER'S PRINCIPLE
  "our point is that data entry is not hampered rather flagged and notified to admin and user"
The "not hampered" half is already TRUE and pinned by a passing browser spec. The "notified"
half is not. Do not re-litigate the first half - do not add, move or strengthen any guard.

THE DEFECT (measured, not inferred)
  dl_ledger_shift_status.pending_notified_at is:
    - WRITTEN by the PM auto-close        (handlers.php:1631 and :1771)
    - RESET on a deliberate reopen        (handlers.php:9391)
    - READ BY NOTHING for display. The only other reference in the entire repo is the
      idempotency clause `AND pending_notified_at IS NULL` inside the UPDATE that sets it.
  The audit rows it writes (action 'auto_close_shift', status 'closed_without_pm_finalize') do
  not surface either: the admin-only management log filters to
    al.module = 'daily-ledger' AND al.action = 'production_ledger_change'   (handlers.php:~3666)
  and its gate is `$user['role'] === 'admin'` only (handlers.php:16246).
  So a day left unclosed is visible on NO screen, to NO role, for ANY date.

THE CHANGE - small and additive
A. modules/daily-ledger/handlers.php :: handleAdminCommissary
   Expose the flag as data (R4), e.g. 'pm_flag' => null | ['date' => <Y-m-d>, 'at' => <string>],
   computed from the PM shift row for the VIEWED date ($rawDate) and the viewed commissary.
   It is set ONLY when the PM shift has pending_notified_at IS NOT NULL AND its status is not
   'finalized'. Otherwise null. No new query pattern beyond what the handler already does; reuse
   dl_getShiftStatus().
B. templates/modules/daily-ledger/admin/commissary.disyl
   Render a persistent, NON-BLOCKING banner (R2) with EXACTLY this id and this wording:
     {if pm_flag}
     <div id="production-pm-flag" class="mb-4 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900" role="status">
         <span class="font-semibold">The PM shift for {pm_flag.date} was closed without finalizing.</span>
         <span class="ml-1 text-amber-800">The late ending counts are still outstanding.</span>
         <span class="ml-1 text-amber-800">Flagged {pm_flag.at}.</span>
     </div>
     {/if}
   The text MUST contain the date and the words "closed without finalizing" verbatim - the
   oracle asserts on that meaning. Render it for the sheet's own roles (admin, supervisor,
   production_in_charge); it must NOT be gated to admin, because BOTH the admin and the
   operator must be notified.

HARD CONSTRAINTS (a violation fails the lane):
  - R3: THIS IS A NOTIFICATION ONLY. Do not add, move or strengthen any guard. Do not change any
    write path, the completeness gate, the auto-close, the reopen or the finalize. The billing
    of this change is that entry stays UNHAMPERED and nothing locks.
  - Do NOT touch the cashier ledger, its template or its handler.
  - Do NOT change dl_priorPendingPmDay() or the prior-pending banner - this flag banner is an
    ADDITIONAL signal for a different condition.
  - No new column, setting, audit action, notification table or permission (R1).
  - Do NOT edit, skip or weaken ANY test.

STEP 2 - VERIFY, in this order, and paste the actual output:
  a) php -l modules/daily-ledger/handlers.php
  b) THE PRIMARY ORACLE. It reads 38/40 on this tree now; it must read 40/40 after your change:
       php tests/daily-ledger/daily_ledger_production_controls_test.php
     Section 'a day closed without finalizing is FLAGGED, not silent' asserts the banner names
     the date and the reason, that the OPERATOR sees it too, that an unflagged shift does not
     raise it, and that a FINALIZED shift never shows it even with a stale flag on the row.
  c) THE OWNER'S PRINCIPLE MUST STILL HOLD - run the spec that pins it:
       cd /var/www/html/applicationostest && APP_URL=http://baronledger.test \
         npx playwright test tests/browser/daily-ledger-nextday-entry.spec.js --reporter=line
     must stay "1 passed". If your change makes this fail, you have BLOCKED entry - stop and
     report BLOCKED with the output.
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

Never edit a test to obtain a green result. If you cannot satisfy the oracle without weakening
a guard or blocking entry, STOP and report BLOCKED.
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/flag-visibility
rc=$?
echo "lane: flag-visibility — completed by: ${LANE_MODEL_USED:-<none>} (rc=$rc)"
exit "$rc"
