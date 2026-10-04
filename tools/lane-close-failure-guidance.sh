#!/usr/bin/env bash
#
# Lane: close-failure-guidance
#
# Ports the cashier ledger's PM-close FAILURE GUIDANCE to the production sheet
# (owner-flagged parity gap). Contract:
#   .ai/daily-ledger-close-failure-guidance.contract.md
#
# MEASURED BASELINE on this tree (both criteria fail, for the right reasons):
#   spec:  422 {"ok":false} with missing_products ABSENT (the handler joins up to 20
#          product names into the message string instead of returning them), and no
#          persistent panel exists at all.
#   oracle: 29/31 - the predicate seam is missing and the panel is absent.
#
set -u
cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

# COST POLICY: implementation belongs to DeepSeek; three models kept for fallback depth.
LANE_MODEL_CHAIN="deepseek-v4-flash,openai-codex/gpt-5.6-sol,openai-codex/gpt-5.6-terra"

PROMPT="$(cat <<'PROMPT_EOF'
You are implementing one bounded change in the Ikabud Daily Ledger module.

STEP 1 - read the contract and follow it exactly, including every ruling R1-R7:
  /var/www/html/applicationostest/.ai/daily-ledger-close-failure-guidance.contract.md

WHAT THE OWNER FLAGGED
The cashier ledger has close-failure guidance the production sheet lacks, and the owner told us
to use the cashier ledger as the reference for production daily-sheet issues.

  1. THE PERSISTENT PANEL. cashier/templates/.../ledger.disyl:153 +
     :2129-2160 -> a hidden <div id="finalize-pm-result" role="alert"> that becomes visible on
     a failed close and lists up to 50 blocking products.
     The production sheet (templates/modules/daily-ledger/admin/commissary.disyl:2147
     finalizeProductionPm) shows ONLY a toast, and the server concatenates up to 20 product
     names into the message string (handlers.php:16896) so the detail is lost when the toast
     fades.

  2. THE PRIOR-PENDING-DAY BANNER. cashier ledger.disyl:145-150, render var handlers.php:6156,
     computed at handlers.php:6007-6015. The production sheet has nothing (grep count 0), so a
     production user gets no in-product signal that the previous business day is still open
     because its PM was never finalized.

READ R1 BEFORE YOU TOUCH THE QUERY - it is the trap in this task:
  dl_shiftMissingEndings() reads dl_daily_ledger.bal_end. The production path reads
  dl_commissary_product_ledger.actual_end_qty. They are DIFFERENT TABLES. Replacing the
  production query with the shared helper would silently change WHICH products block the close
  - a completeness-gate change disguised as reuse. KEEP the production query; change only its
  PROJECTION (p.id AS product_id, p.name, p.sku), drop the LIMIT 20, and structure its RESULT.

THE CHANGES
A. modules/daily-ledger/handlers.php :: apiFinalizeProductionPmShift (~16885-16899)
   Replace the hand-rolled LIMIT-20 SELECT + `throw new RuntimeException('Record PM ACTUAL BAL
   ...' . implode(', ', $names), 422)` with the cashier's response shape (R2), same tables and
   same conditions, ordered p.sort_order, p.name:
     422 {ok:false, code:'PM_ENDING_MISSING',
          error:'N active product(s) are missing a PM ending count.',
          missing_products:[{product_id,name,sku}, ...]}
   An EMPTY list still finalizes exactly as before. The success path and the audit row are
   unchanged.

B. same file :: handleAdminCommissary
   Add the seam required by R5 verbatim:
     function dl_priorPendingPmDay($db, int $branchId, string $today, string $viewedDate): ?string
   and expose 'prior_pending_day' => (the date ? ['date' => $date] : null), gated on the
   sheet's own roles - NOT on $role === 'cashier' as the cashier does.

C. templates/modules/daily-ledger/admin/commissary.disyl :: productionJson (~2229)
   Attach the response body to the thrown Error (err.body = body; err.status = response.status)
   so the panel can read missing_products. Additive only - existing callers read .message and
   must behave exactly as before (R3).

D. same template :: add the panel div with the cashier's own class/role (R4), and in
   finalizeProductionPm() CLEAR it at the start of every attempt, then on failure render the
   message plus up to 50 names inside <ul class="mt-2 list-disc pl-5 text-xs">. Keep the toast
   and the button re-enable in finally.

E. same template :: render the prior-pending banner (R6) with the cashier's wording, linking to
   {base_url}/daily-ledger/admin/commissary?date={prior_pending_day.date}&commissary_id={sheet_source_branch_id}&shift=PM

HARD CONSTRAINTS (a violation fails the lane):
  - Do NOT touch the cashier ledger, its template or its handler.
  - Do NOT replace the production missing-endings query with dl_shiftMissingEndings (R1).
  - Do NOT change the completeness gate, the success path, the already-finalized early return,
    or any other guard/handler/route.
  - No new permission, column, setting or migration.
  - Do NOT edit, skip or weaken ANY test, including the two spec files and the oracle.

STEP 2 - VERIFY, in this order, and paste the actual output:
  a) php -l modules/daily-ledger/handlers.php
  b) THE PRIMARY ORACLE. It reads 29/31 on this tree now; it must read 36/36 after your change:
       php tests/daily-ledger/daily_ledger_production_controls_test.php
     The section 'PM-close failure guidance' asserts the prior-pending rule's truth table
     (yesterday open + PM unfinalized / closed / PM finalized / viewing that same date /
     no day row) and that the rendered sheet carries the role="alert" panel.
  c) END TO END against the real endpoint and the real UI - currently 2 failed:
       cd /var/www/html/applicationostest && APP_URL=http://baronledger.test \
         npx playwright test tests/browser/daily-ledger-close-failure-guidance.spec.js --reporter=line
     must become "2 passed". 2026-09-01 is deliberately before branch 18's data range
     (2026-10-01..04) so the close ALWAYS refuses and the test cannot mutate state.
  d) REGRESSION - all must hold:
       php tests/daily-ledger/daily_ledger_production_shift_access_test.php     # 14/14
       php tests/daily-ledger/daily_ledger_production_sheet_test.php            # 53/53
       APP_URL=http://baronledger.test npx playwright test tests/browser/daily-ledger-reopen-edit.spec.js --reporter=line
         # must stay 1 passed - do not regress the reopen fix
  e) Both logs: storage/logs/app.log and storage/logs/error.log - no new errors.

STEP 3 - report compactly:
  status: PASS | FAIL | BLOCKED
  changed:
  implementation_summary:
  verification:  (the actual commands and their outcome)
  logs:
  risks / unresolved:

If the production and cashier missing-endings sets turn out to disagree in a way you cannot
reconcile without touching the cashier, STOP and report BLOCKED with the query output - do NOT
change the cashier and do NOT relax the gate. Never edit a test to obtain a green result.
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/close-failure-guidance
rc=$?
echo "lane: close-failure-guidance — completed by: ${LANE_MODEL_USED:-<none>} (rc=$rc)"
exit "$rc"
