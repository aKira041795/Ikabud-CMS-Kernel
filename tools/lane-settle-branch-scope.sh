#!/usr/bin/env bash
#
# Lane: settle-branch-scope
#
# Closes an authorization gap in a NEW write path.
#
# The settle/verify/revert services check the actor's ROLE but not their BRANCHES, while every other
# write path in this module checks dl_accessibleBranchIds before writing
# (handlers.php:2792, :3431, :3538, :3745).
#
# The branch arrives from the CLIENT (the sheet posts the branch it displays), so a supervisor or
# production user can craft a POST naming another branch and write derived endings into a ledger
# they are not assigned to. MEASURED:
#   admin id=20                 accessible=11  branch8=YES  branch18=YES
#   production_in_charge id=27  accessible=1   branch8=no   branch18=YES
# and the oracle currently reports refused=false for a producer settling branch 8.
#
set -u
cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

LANE_MODEL_CHAIN="deepseek-v4-flash,openai-codex/gpt-5.6-sol,openai-codex/gpt-5.6-terra"

PROMPT="$(cat <<'PROMPT_EOF'
You are closing ONE authorization gap in the Ikabud Daily Ledger. Nothing else.

FILE: modules/daily-ledger/handlers.php
FUNCTIONS: dl_settlePendingEndingsForShift, dl_verifySettledEndingsForShift, dl_revertSettledEndingsForShift

THE GAP
Each of the three checks the actor's ROLE but never their BRANCH. The branch is supplied by the
client (the sheet posts the branch it is displaying), so a supervisor or production_in_charge can
craft a POST naming a branch they are not assigned to and write derived endings into that branch's
ledger.

MEASURED:
  admin id=20                 accessible=11 branches, includes 8 and 18
  production_in_charge id=27  accessible=1  branch (18 only); branch 8 is NOT accessible
  oracle: 'settle is refused for a branch the actor cannot access' currently reports refused=false

Every other write path in this module already guards this way - copy the house pattern exactly:
    if (!in_array($branchId, dl_accessibleBranchIds($actor), true)) {
        throw new \RuntimeException('<the module's existing wording for this case>', 403);
    }
Look at handlers.php:2792, :3431, :3538 or :3745 for the exact idiom and message style, and place the
check BEFORE any write and immediately after the existing role check.

Apply it to ALL THREE services for consistency - verify and revert are admin-only and an admin
reaches every branch, so the check is a no-op for them but keeps the three paths uniform. Confirm
that claim rather than assuming it: the oracle's admin cases must still pass.

HARD CONSTRAINTS
  - Change ONLY the guards in those three functions. Do NOT touch the ladder, the migration, the
    templates, routes, or any service SEMANTICS (what is written, the rungs, the audit rows).
  - Do NOT weaken or reorder the existing role checks - the branch check is ADDITIONAL.
  - Do NOT edit, skip or weaken ANY test.

STEP 2 - VERIFY, and paste the actual output:
  a) php -l modules/daily-ledger/handlers.php
  b) THE ORACLE. It reads 69/70 now; it must read 70/70:
       php tests/daily-ledger/daily_ledger_production_controls_test.php
     The rest of the suite must stay green - in particular the admin cases on the fixture branch,
     which prove the new check does not lock out a legitimate actor.
  c) THE END-TO-END SPEC must still pass (admin settles and reverts on branch 8, which an admin CAN
     access - if the new guard breaks this, the guard is wrong, not the spec):
       cd /var/www/html/applicationostest && APP_URL=http://baronledger.test \
         npx playwright test tests/browser/daily-ledger-settled-endings.spec.js --reporter=line
     must be "2 passed".
  d) REGRESSION
       php tests/daily-ledger/daily_ledger_production_sheet_test.php             # 53/53
       php tests/daily-ledger/daily_ledger_both_shifts_coverage_test.php         # 8/8
       php tests/daily-ledger/daily_ledger_addtl_correction_test.php             # 83/83
  e) Both logs - no new errors.

STEP 3 - report compactly:
  status: PASS | FAIL | BLOCKED
  changed:
  implementation_summary:  (quote the guard you added, and say where each check sits relative to
                            the role check)
  verification:  (the actual commands and their outcome)
  logs:
  risks / unresolved:

Never edit a test to obtain a green result.
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/settle-branch-scope
rc=$?
echo "lane: settle-branch-scope — completed by: ${LANE_MODEL_USED:-<none>} (rc=$rc)"
exit "$rc"
