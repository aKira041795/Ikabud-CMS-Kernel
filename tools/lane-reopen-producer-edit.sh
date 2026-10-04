#!/usr/bin/env bash
#
# Lane: reopen-producer-edit
#
# Implements .ai/daily-ledger-reopen-producer-edit.contract.md:
# a day an admin DELIBERATELY reopened must be editable by the branch's
# production user (production_in_charge) without holding production.override,
# because the admin's reopen IS the authorisation - the same model the cashier
# ledger uses, where reopening lifts the lock.
#
# MEASURED DEFECT (2026-10-04): the production user is shown an ENABLED cell
# (commissary.disyl:1488 disables #production-actual-* only when
# shift_status == 'finalized') and the server answers
#   422 "production.override permission is required to change a recorded count."
# UI and server disagree about the same state.
#
set -u
cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

# COST POLICY: implementation is DeepSeek's role (Codex = architect/review), and the chain
# is ordered for cost - but the chain's default first entry is Sol. Reorder to put the cheap
# implementation model first while KEEPING three models, so a single exhausted provider still
# cannot end the work (the defect tools/model-chain.txt exists to prevent).
LANE_MODEL_CHAIN="deepseek-v4-flash,openai-codex/gpt-5.6-sol,openai-codex/gpt-5.6-terra"

PROMPT="$(cat <<'PROMPT_EOF'
You are implementing ONE bounded change in the Ikabud Daily Ledger module.

STEP 1 - read the contract and follow it exactly:
  /var/www/html/applicationostest/.ai/daily-ledger-reopen-producer-edit.contract.md
It contains the measured defect, the root cause, the rulings R1-R6, the allowed scope and
the prohibition list. Do not deviate from it.

THE DEFECT, measured on the live site (tenant 207, branch 18, date 2026-10-03):
  admin:    finalize PM 200 -> Close Day 200 {"day_status":"closed"} -> reopen -> day "open"
            then save actual 200 {"row":{"actual_end_qty":41}}        <- admin works
  producer: production_in_charge assigned to branch 18, the SAME reopened day,
            the cell is rendered ENABLED, but the save returns
            422 {"ok":false,"error":"production.override permission is required to
                            change a recorded count."}
  production.override is held by admin ONLY in this tenant.

WHY IT MATTERS: the owner requires that after an admin reopens a closed day, the branch's
production user can update the entries - exactly as the cashier ledger allows the cashier
to edit once the day is reopened. Right now they cannot, and the UI misleads them into
trying.

WHAT TO CHANGE - minimal, two guards only, both in modules/daily-ledger/handlers.php:
  ~17236  the beg_qty guard        ('... required to change a recorded beginning.')
  ~17277  the actual_end_qty guard ('... required to change a recorded count.')

Add the deliberate-reopen exemption per R1-R3: the refusal no longer applies when
  (a) the day was DELIBERATELY reopened by an admin, i.e.
      dl_ledger_day_status.reopened_at IS NOT NULL for that branch_id + ledger_date, AND
  (b) the shift is not finalized.
Use the EXISTING helper dl_shiftIsFinalized($db, $branchId, $date, $shift) (handlers.php:4459)
and the existing resolved $shift from the input. Do NOT invent a new column, setting,
permission, or state helper.

HARD CONSTRAINTS (a violation fails the lane):
  - Do NOT remove or soften the production.override requirement for a day that was NOT
    deliberately reopened. The code at :17232-17235 cites spec S7b §3 and that requirement
    is deliberate. Preserve it VERBATIM, including the message strings.
  - Do NOT touch any other guard, the cashier path, the admin path, templates, migrations,
    module.json, settings or seeds.
  - Do NOT change dl_auditProductionLedgerChange(...) calls - the before/after diff, reason
    and actor must still be recorded on every change. That audit trail is what makes this
    exemption safe.
  - No refactor of the enclosing handler. Keep the diff small.
  - Do NOT edit or weaken the test specs.

Note that handlers.php:17232-17235 already computed $alreadyRecorded for the beg path and
:17276 computes $beforeCount for the count path - reuse what is there.

STEP 2 - VERIFY, in this order, and do not claim success without the output:
  a) php -l modules/daily-ledger/handlers.php
  b) regression, must stay 20/20 green:
       php tests/daily-ledger/daily_ledger_production_controls_test.php
  c) acceptance (the real endpoint, real roles) - this FAILS before your change and must
     pass after it:
       cd /var/www/html/applicationostest && APP_URL=http://baronledger.test \
         npx playwright test tests/browser/daily-ledger-reopen-edit.spec.js --reporter=line
     PASS looks like: "1 passed" and the evidence file /tmp/reopen-edit-evidence.jsonl
     containing {"step":"save","label":"producer",...,"status":200}.
     The spec is self-contained: it finalizes PM, closes the day, reopens it, then edits as
     admin and as the producer. Chromium, 1 worker, takes several minutes.
  d) check BOTH logs for new errors: storage/logs/app.log and storage/logs/error.log

STEP 3 - report, compactly:
  status: PASS | FAIL | BLOCKED
  changed:
  implementation_summary:
  verification:   (paste the actual commands and their outcome)
  logs:
  risks / unresolved:

If the deliberate reopen does NOT leave the shift open, STOP and report BLOCKED with the
query output - do NOT widen the exemption to make the test pass. A lane that reports BLOCKED
with a precise reason is doing its job. Never edit the spec to obtain a green result.
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/reopen-producer-edit
rc=$?
echo "lane: reopen-producer-edit — completed by: ${LANE_MODEL_USED:-<none>} (rc=$rc)"
exit "$rc"
