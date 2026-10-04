#!/usr/bin/env bash
#
# Lane: reopen-producer-edit-fix
#
# CORRECTS the first revision, which is INCOMPLETE and opened a real hole.
#
# What the first revision did (modules/daily-ledger/handlers.php, two guards):
#   exempt when reopened_at IS NOT NULL AND the shift is not finalized.
#
# Why that is not enough - MEASURED on tenant 207, not reasoned:
#   apiCloseDay (handlers.php:9183) writes
#     ON DUPLICATE KEY UPDATE status='closed', closed_by=..., closed_at=...
#   so it NEVER CLEARS reopened_at; and it requires only the PM shift to be finalized, so it
#   never finalizes AM. "CLOSED day + stale reopened_at + AM shift still open" is therefore
#   reachable:
#     branch=18 date=2026-10-02 reopened_at=2026-10-04 14:14:27 shift=AM shift_status=open
#     branch=18 date=2026-10-01 reopened_at=2026-10-04 14:13:44 shift=AM shift_status=open
#   Both are CLOSED days that the first revision would now let a production_in_charge write
#   to. 10 closed days carry a stale reopened_at; AM is finalized on 230 closed days but left
#   open on 2.
#
# The fix is the contract's amended R2 (add the day-must-be-OPEN condition) plus R7 (extract
# the decision into the named predicate so its policy is assertable without a browser run).
#
set -u
cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

# COST POLICY: implementation belongs to DeepSeek; keep three models for fallback depth.
LANE_MODEL_CHAIN="deepseek-v4-flash,openai-codex/gpt-5.6-sol,openai-codex/gpt-5.6-terra"

PROMPT="$(cat <<'PROMPT_EOF'
You are CORRECTING an incomplete change in the Ikabud Daily Ledger module.

STEP 1 - read the contract, especially the AMENDED ruling R2 and the new ruling R7:
  /var/www/html/applicationostest/.ai/daily-ledger-reopen-producer-edit.contract.md
Follow it exactly. R2 was amended because its first revision was WRONG.

WHERE THINGS STAND
A first revision already changed the two recorded-entry guards in
modules/daily-ledger/handlers.php (~line 17236 beg_qty, ~line 17291 actual_end_qty). It
replaced each refusal with an exemption that fires when
    reopened_at IS NOT NULL  AND  $shift !== null  AND  the shift is not finalized.
That satisfies the owner's request but is NOT SAFE, and the hole is measured, not theoretical:

  apiCloseDay (handlers.php:9183) closes the day with
    ON DUPLICATE KEY UPDATE status='closed', closed_by=..., closed_at=...
  so it NEVER CLEARS reopened_at. It also only requires the PM shift to be finalized - it
  never finalizes AM. Therefore "CLOSED day + stale reopened_at + AM shift open" is reachable:
    branch=18 date=2026-10-02 reopened_at=2026-10-04 14:14:27 shift=AM shift_status=open
    branch=18 date=2026-10-01 reopened_at=2026-10-04 14:13:44 shift=AM shift_status=open
  With the first revision, a production_in_charge could write to those CLOSED days.

WHAT TO DO
1. Add the missing condition (R2a): the exemption applies ONLY while the day is CURRENTLY
   OPEN. Use the existing helper dl_getDayStatus($branchId, $date) (handlers.php:1857);
   it returns 'open' when no row exists.
2. Extract the decision into the named predicate required by R7 and call it from BOTH guards:

     function dl_deliberateReopenUnlocksEntryEdit($db, int $branchId, string $date, ?string $shift): bool

   It returns TRUE only when ALL of these hold:
     (a) dl_getDayStatus($branchId, $date) === 'open'
     (b) dl_ledger_day_status.reopened_at IS NOT NULL for that branch_id + ledger_date
     (c) $shift !== null and NOT dl_shiftIsFinalized($db, $branchId, $date, $shift)
   FALSE otherwise, INCLUDING when $shift is null.
   Give it the docblock quoted in R7.
3. Reduce both guards to (keeping the ORIGINAL message text VERBATIM):

     if ($alreadyRecorded && !dl_roleHasPermission($role, 'production.override')
         && !dl_deliberateReopenUnlocksEntryEdit($db, $commissaryBranchId, $date, $shift)) {
         throw new \RuntimeException('<the original message, unchanged>');
     }

   Remove the now-duplicated inline reopened_at reads the first revision added.

HARD CONSTRAINTS (a violation fails the lane):
  - Do NOT touch any other guard, the cashier path, the admin path, templates, migrations,
    module.json, settings or seeds.
  - Do NOT change dl_auditProductionLedgerChange(...) - the before/after diff, reason and
    actor must still be recorded on every change.
  - Do NOT weaken, skip or edit ANY test. Do not edit the oracle.
  - Reuse existing helpers (dl_getDayStatus, dl_shiftIsFinalized, dl_roleHasPermission).
    Add no column, setting, permission or other helper.

STEP 2 - VERIFY, in this order, and paste the actual output:
  a) php -l modules/daily-ledger/handlers.php
  b) THE ACCEPTANCE ORACLE. It FAILS on the current tree (20/21) because the predicate does
     not exist yet. It must be 21/21 after your change:
       php tests/daily-ledger/daily_ledger_production_controls_test.php
     The section 'recorded-entry edit on a reopened day' asserts the whole truth table,
     including the hole: a CLOSED day with a stale reopened_at and an unfinalized AM shift
     must return false.
  c) The owner's requirement must still work end to end (real endpoint, real roles):
       cd /var/www/html/applicationostest && APP_URL=http://baronledger.test \
         npx playwright test tests/browser/daily-ledger-reopen-edit.spec.js --reporter=line
     must be "1 passed", with /tmp/reopen-edit-evidence.jsonl showing
     {"step":"save","label":"producer",...,"status":200}. This spec is self-contained and
     takes well under a minute.
  d) Both logs: storage/logs/app.log and storage/logs/error.log - no new errors.

STEP 3 - report compactly:
  status: PASS | FAIL | BLOCKED
  changed:
  implementation_summary:
  verification:  (the actual commands and their outcome)
  logs:
  risks / unresolved:

If the oracle's closed-day assertion cannot be satisfied without breaking the owner's
requirement, STOP and report BLOCKED with the output - do NOT relax the day-open condition
and do NOT touch the test to obtain a green result.
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/reopen-producer-edit-fix
rc=$?
echo "lane: reopen-producer-edit-fix — completed by: ${LANE_MODEL_USED:-<none>} (rc=$rc)"
exit "$rc"
