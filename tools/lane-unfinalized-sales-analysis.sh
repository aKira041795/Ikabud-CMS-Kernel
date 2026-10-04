#!/usr/bin/env bash
#
# Lane: unfinalized-sales-analysis
#
# Owner: "analyze this if this holds. i want the daily ledger to be also smart. this is for both
#         cashier and production sheets" + "use sol for analysis and fixes"
#
# ANALYIS ONLY. The three proposed rules are TESTABLE claims about the current code, so the
# deliverable is a grounded document backed by probe output - not an essay. This lane must not
# change product behaviour.
#
# Contract: .ai/daily-ledger-unfinalized-sales-semantics.contract.md
#
set -u
cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

# Sol leads: this is judgement work about what an inferred number MEANS, not mechanical edits.
LANE_MODEL_CHAIN="openai-codex/gpt-5.6-sol,deepseek-v4-flash,openai-codex/gpt-5.6-terra"

PROMPT="$(cat <<'PROMPT_EOF'
You are ANALYSING (not implementing) how unfinalized AM/PM shifts should affect sales and endings
in the Ikabud Daily Ledger, for the cashier sheet AND the production sheet.

STEP 1 - read the contract and answer every question in it:
  /var/www/html/applicationostest/.ai/daily-ledger-unfinalized-sales-semantics.contract.md
It contains the owner's three proposed rules verbatim, four facts the chair already MEASURED
(F1-F4, with file:line), the six questions Q1-Q6 you must answer, and the required deliverable.

THE OWNER'S THREE RULES (verbatim)
  1. if all cell rows are zero, safe to assume zero as final sales (this takes into account
     previous day carry over is zero)
  2. if there's qty other than zero but not finalized, meaning pending, set to zero as final
     sales, therefore ending is same as the data entered whether in beginning, additional -
     pullpout combined = 0 sales
  3. if there's data and next day beginning has entry other than zero, safe to assume next day
     (current day) beginning is the ending of the pending cell
And: "why is there a provisional data for sales? where was this derived?"

THE POINT OF THIS LANE
These are empirical claims about THIS codebase. Answer them with measurements, not reasoning about
what "should" happen. Concretely: write throwaway probes under /tmp that bootstrap the app the way
the tests do (see tests/daily-ledger/daily_ledger_production_controls_test.php for the pattern:
TestHarness MODE_INTEGRATION 'baronledger.test', app()->tenant()->setTenantId(207), then
modulePushContext('daily-ledger') and $ctx->db()), and MEASURE:
  - what sales is for a NULL ending vs a RECORDED zero ending, through dl_computeSalesValue and
    through the SQL mirror dl_ledgerSalesQuantitySql;
  - what an unfinalized PM row looks like after the report bucketing in
    modules/daily-ledger/helpers/reporting.php (:164-166) - status_label and bucket;
  - whether an AM row with an unfinalized shift can ever be labelled provisional, and why;
  - what the carry does when the preceding ending is NULL (dl_fetchCommissaryBeginningSuggestions
    and dl_saveCommissaryCarryForward ~:2392), because rule 3 depends on whether a next-day
    beginning is an independent count or a carried COPY.
Paste the ACTUAL command output into the doc's '## Evidence' section.

THE SPECIFIC THING NOT TO GET WRONG
Rule 2 proposes DEFINING the ending as beg + addtl - withdraw so that sales is 0. That is not the
same as "sales is zero" - it is a claim that unfinalized stock has no sales, which would attribute
real sales to stock still on hand. Rule 3's inference is sound only when the next-day beginning is
an INDEPENDENT physical count; when it was CARRIED it is a copy of the very ending that is missing,
which is circular. Establish, from what is recorded, how the two can be told apart - and if they
cannot, say so plainly and state what would have to be recorded to make it possible.

HARD CONSTRAINTS (a violation fails the lane)
  - ANALYSIS ONLY. Do NOT change anything under modules/, templates/, public/, src/, kernel/ or
    tests/. No migration, setting or seed. No "while I was there" fixes.
  - The ONLY file you may create/modify is
    docs/daily-ledger/unfinalized-shift-sales-semantics.md
  - Every factual claim must cite file:line or be backed by pasted probe output. "Should",
    "probably", "it is likely" are not findings - either measure it or label it as an open
    question with what evidence would settle it.
  - Do not recommend a change without stating what it would cost to verify.

STEP 2 - VERIFY
  a) the deliverable exists and is complete:
       test -f docs/daily-ledger/unfinalized-shift-sales-semantics.md \
         && [ "$(grep -cE '^### Q[1-6]' docs/daily-ledger/unfinalized-shift-sales-semantics.md)" -eq 6 ] \
         && grep -q '^## Evidence' docs/daily-ledger/unfinalized-shift-sales-semantics.md && echo DOC_OK
  b) scope: nothing but the doc changed
       git status --porcelain
     (the only entry you add must be the doc; pre-existing entries for this session may show as
      untracked tools/*.sh and modified tests - ignore those, but add NOTHING new yourself)

STEP 3 - report compactly
  status: PASS | FAIL | BLOCKED
  changed:
  verdict:        one line per rule 1/2/3 - HOLDS / HOLDS-WITH-CONDITIONS / DOES-NOT-HOLD, and why
  provisional:    where provisional sales come from, in one or two sentences with file:line
  recommendation: the smallest safe change and what it would cost to verify
  evidence:       the probe commands and their key output
  unresolved:     what you could not determine and what would settle it

If a rule cannot be evaluated without first changing product behaviour, say so and STOP there -
do not implement it.
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/unfinalized-sales-analysis
rc=$?
echo "lane: unfinalized-sales-analysis — completed by: ${LANE_MODEL_USED:-<none>} (rc=$rc)"
exit "$rc"
