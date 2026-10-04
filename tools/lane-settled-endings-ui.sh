#!/usr/bin/env bash
#
# Lane: settled-endings-ui
#
# Owner: "proceed" - make the settle / admin-verify / revert lifecycle operable from the sheets.
# The services and endpoints exist (916c547b); this lane adds the control surface.
#
# Contract: .ai/daily-ledger-settled-endings-ui.contract.md (R1-R5).
#
set -u
cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

LANE_MODEL_CHAIN="deepseek-v4-flash,openai-codex/gpt-5.6-sol,openai-codex/gpt-5.6-terra"

PROMPT="$(cat <<'PROMPT_EOF'
You are adding the admin control surface for the settle / verify-for-finality / revert lifecycle in
the Ikabud Daily Ledger. The services already exist and are proven by 65 assertions - you are adding
PRESENTATION and PLUMBING only.

STEP 1 - read the contract and follow it exactly, including every ruling R1-R5:
  /var/www/html/applicationostest/.ai/daily-ledger-settled-endings-ui.contract.md

WHAT ALREADY EXISTS (do not change any of it - R4):
  dl_settlePendingEndingsForShift / dl_verifySettledEndingsForShift / dl_revertSettledEndingsForShift
  in modules/daily-ledger/handlers.php. They take ($db, $branchId, $date, $shift, array $actor, bool $production),
  enforce their own authorization (settle: admin/supervisor/production_in_charge; verify+revert: ADMIN
  ONLY), run in one transaction and audit each changed row. Historically end_source NULL means "a
  person counted this".

WHAT TO BUILD

R1. A render var 'settled_summary' exposed by BOTH sheet handlers:
      ['pending' => <int>, 'unverified' => <int>, 'verified' => <int>,
       'can_settle' => <bool>, 'can_verify' => <bool>, 'date' => ..., 'shift' => ..., 'branch_id' => ...]
    pending    = rows in the viewed shift whose ending IS NULL
    unverified = rows whose end_source is 'derived-from-movements' or 'zero-forced'
    verified   = rows whose end_verified_by IS NOT NULL
    can_settle = role in (admin, supervisor, production_in_charge)
    can_verify = role === 'admin'
    Count dl_daily_ledger for the cashier sheet and dl_commissary_product_ledger for the production
    sheet - each sheet counts its OWN table.

R2. THE PANEL, in BOTH templates
    (templates/modules/daily-ledger/cashier/ledger.disyl and
     templates/modules/daily-ledger/admin/commissary.disyl):
      <div id="settled-panel" data-pending="N" data-unverified="N" data-verified="N" ...>
        human-readable counts, then:
        <button id="settle-pending-endings">  only when can_settle && pending > 0
        <button id="verify-settled-endings">  only when can_verify && unverified > 0
        <button id="revert-settled-endings">  only when can_verify && unverified > 0
      </div>
    THE THREE data-* ATTRIBUTES ARE REQUIRED and must equal settled_summary exactly - they are how
    the counts are asserted.
    The panel renders whenever ANY of the three counts is > 0, for the sheet's roles; it may be
    omitted only when all three are 0.
    The verify/revert buttons must be ABSENT (not disabled) for a non-admin - that absence is
    asserted from the HTML.
    After a successful action, refresh the way the surrounding controls already do, then the panel
    reflects the new counts.

R3. ENDPOINTS. Wire the three services to thin POST handlers + routes in the existing
    'daily-ledger:functionName' style, passing the RESOLVED actor array plus the branch/date/shift of
    the sheet being DISPLAYED (the sheet source branch - never a client-supplied branch that could
    point elsewhere). Reuse the module's existing CSRF/auth write path; do not invent one.

HARD CONSTRAINTS
  - R4: do NOT touch the services' logic, the ladder, dl_settleUnfinalizedRow, dl_rowIsProvisional,
    the rung strings, the calc_variance generated expression, the migration or module.json.
  - R5: NO AUTOMATIC SETTLING. Settling happens only when a human clicks. Do not settle from a page
    render, a cron, the auto-close, or anything else.
  - Do NOT edit, skip or weaken ANY test.
  - DiSyL is a template language, not PHP: follow the conventions of the surrounding markup in each
    template, and remember a compiled template cache can serve a stale layout - the browser specs use
    normal URLs, so make sure changes are visible without a manual cache clear.

STEP 2 - VERIFY, in order, and paste the actual output:
  a) php -l on every PHP file you changed; and confirm both templates still render (their page loads
     are covered by the specs below).
  b) THE PRIMARY ORACLE. It reads 66/69 now (3 control-surface assertions fail); it must read 69/69:
       php tests/daily-ledger/daily_ledger_production_controls_test.php
     The section 'the settle/verify control surface' asserts the panel counts, that an ADMIN is
     offered the verify control while endings await verification, that a NON-ADMIN is not, and that
     nothing awaiting verification means no verify control.
  c) THE END-TO-END SPEC (admin settles pending endings, sees them await verification, then REVERTS
     them so the ledger is left as found). It fails now because #settled-panel does not exist; it
     must become "1 passed" plus the non-admin absence check:
       cd /var/www/html/applicationostest && APP_URL=http://baronledger.test \
         npx playwright test tests/browser/daily-ledger-settled-endings.spec.js --reporter=line
     If the counts do NOT return to their starting values after the revert, STOP and report BLOCKED -
     the spec must leave the ledger exactly as it found it.
  d) REGRESSION
       php tests/daily-ledger/daily_ledger_production_sheet_test.php             # 53/53
       php tests/daily-ledger/daily_ledger_both_shifts_coverage_test.php         # 8/8
       php tests/daily-ledger/daily_ledger_addtl_correction_test.php             # 83/83
       php tests/daily-ledger/daily_ledger_daily_sheet_log_evidence_test.php     # 17/17
       APP_URL=http://baronledger.test npx playwright test tests/browser/daily-ledger-nextday-entry.spec.js --reporter=line
       APP_URL=http://baronledger.test npx playwright test tests/browser/daily-ledger-reopen-edit.spec.js --reporter=line
       APP_URL=http://baronledger.test npx playwright test tests/browser/daily-ledger-close-failure-guidance.spec.js --reporter=line
     must be 1, 1 and 2 passed.
  e) php tools/disyl-conformance-check.php, and BOTH logs (storage/logs/app.log,
     storage/logs/error.log) - no new errors.

STEP 3 - report compactly:
  status: PASS | FAIL | BLOCKED
  changed:
  implementation_summary:  (say which handler and template render the panel for each sheet)
  verification:  (the actual commands and their outcome, including the settle/revert counts)
  logs:
  risks / unresolved:

Never edit a test to obtain a green result. If a control cannot be made absent for a non-admin, STOP
and report BLOCKED rather than shipping it disabled.
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/settled-endings-ui
rc=$?
echo "lane: settled-endings-ui — completed by: ${LANE_MODEL_USED:-<none>} (rc=$rc)"
exit "$rc"
