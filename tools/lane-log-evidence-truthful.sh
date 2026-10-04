#!/usr/bin/env bash
#
# Lane: log-evidence-truthful
#
# Owner: "fix the test so we can have a truthful test results" + "use sol for analysis and fixes"
#
# Makes tests/daily-ledger/daily_ledger_daily_sheet_log_evidence_test.php green for the RIGHT
# reason. Measured: both failures are stale hard-coded actor names, and the product code is
# correct - user 22 IS "Jorely Verano" and user 27 IS "Sheila Baina" in this tenant, while the
# assertions demand "Bernalisa Dywatco" and "Noah Omamalin".
#
# MODEL: Sol leads this lane (owner instruction). The task is test truthfulness - it needs
# judgement about what an assertion MEANS, not just mechanical edits.
#
set -u
cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

LANE_MODEL_CHAIN="openai-codex/gpt-5.6-sol,deepseek-v4-flash,openai-codex/gpt-5.6-terra"

PROMPT="$(cat <<'PROMPT_EOF'
You are making ONE test suite report TRUTHFUL results in the Ikabud Daily Ledger module.

STEP 1 - read the contract and follow it exactly, including rulings R1-R6:
  /var/www/html/applicationostest/.ai/daily-ledger-log-evidence-test-truthfulness.contract.md

THE SITUATION (measured by the chair - start from this, and verify it yourself)
tests/daily-ledger/daily_ledger_daily_sheet_log_evidence_test.php reads 15/17. Both failures are
the same class: STALE HARD-CODED USER NAMES. The product code is CORRECT.

Measured in tenant 207:
    user id 22 = cashier-miputakAM  full_name "Jorely Verano"
    user id 27 = prod-rizal        full_name "Sheila Baina"
    dl_branch_receivings id 123 has received_by = 22, received_at '2026-09-27 22:43:57'

  AC3 expects $realReceived[1] === 'Bernalisa Dywatco'; the render yields 'Jorely Verano'.
      Every other cell matches, and the source row agrees with the render.
  AC2/AC10 expects $received['who'] === 'Noah Omamalin'; its fixture inserts the crafted
      receiving with the literal received_by = 27. 'Noah Omamalin' exists ONLY in the test's
      own token payload - there is no dl_users row with that name in this tenant, and the
      fixture inserts no dl_users row, so the resolver returns user 27's current name.

So the literals drifted when the tenant's users were renamed. A rename must not break the
evidence log, which is why the resolver reads the CURRENT name.

WHAT TO DO
1. Replace each stale actor-name literal with the name RESOLVED FROM THE DATABASE for the user
   the row actually references (R2). This makes the assertion STRONGER: it still fails if the
   resolver names the wrong user, and it survives a rename.
2. Give AC2/AC10 a detail argument (json_encode of the actual entry) so a failure is legible -
   the whole point of the task is truthful results (R4).
3. Name the tenant host: the harness is constructed with 'localhost' while the docblock says
   tenant 207 / baron-001, and the harness reports
     'Suite did not name a tenant host (using localhost), so which database it read is unverified'
   Change it to 'baronledger.test' as the other daily-ledger suites do. If that changes any
   result, REPORT it - do not revert to 'localhost' to make things pass (R5).

HARD CONSTRAINTS (a violation fails the lane)
  - R1: do NOT touch modules/daily-ledger/handlers.php, the resolver, or any rendering. If you
    become convinced the CODE is wrong, STOP and report BLOCKED with the evidence - do not
    change a handler to satisfy a stale literal.
  - R3: do NOT weaken or delete any assertion. Not the actor-name check, not the
    draft-exclusion clause count(RECEIVED) === 1, not a timestamp/quantity/branch/reason check.
    Do not skip or comment out an assertion. The assertion count must not go DOWN.
  - R6: the ONLY file you may change is
    tests/daily-ledger/daily_ledger_daily_sheet_log_evidence_test.php
  - 'Noah Omamalin' also appears in the admin TOKEN payload ('name' => ...) which is NOT a DB
    row and must stay exactly as it is; only the DB-derived expectations change.

STEP 2 - VERIFY, in this order, and paste the actual output:
  a) The suite. It reads 15/17 now; it must read 17/17:
       php tests/daily-ledger/daily_ledger_daily_sheet_log_evidence_test.php
     It must ALSO no longer print the 'did not name a tenant host' gap line.
  b) php -l tests/daily-ledger/daily_ledger_daily_sheet_log_evidence_test.php
  c) PROVE IT IS A STRENGTHENING, with numbers:
       git show HEAD:tests/daily-ledger/daily_ledger_daily_sheet_log_evidence_test.php | grep -c '\$h->test('
       grep -c '\$h->test(' tests/daily-ledger/daily_ledger_daily_sheet_log_evidence_test.php
       grep -n 'dl_users' tests/daily-ledger/daily_ledger_daily_sheet_log_evidence_test.php
       git diff --stat -- tests/daily-ledger/daily_ledger_daily_sheet_log_evidence_test.php
     The first two numbers must satisfy: after >= before.
  d) REGRESSION
       php tests/daily-ledger/daily_ledger_production_controls_test.php          # 41/41
       php tests/daily-ledger/daily_ledger_production_sheet_test.php             # 53/53
  e) Both logs: storage/logs/app.log and storage/logs/error.log - no new errors.

STEP 3 - report compactly:
  status: PASS | FAIL | BLOCKED
  changed:
  implementation_summary:  (say explicitly WHY the literals were stale and what replaced them)
  verification:  (the actual commands and their outcome, including the before/after assertion counts)
  logs:
  risks / unresolved:

If the honest conclusion is that an assertion's EXPECTATION was wrong in a way that needs a
product change, STOP and report BLOCKED - do not force green.
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/log-evidence-truthful
rc=$?
echo "lane: log-evidence-truthful — completed by: ${LANE_MODEL_USED:-<none>} (rc=$rc)"
exit "$rc"
