#!/usr/bin/env bash
#
# Lane: sol-production-attribution-review
#
# ADVERSARIAL REVIEW + DESIGN - read-only. Sol must NOT commit or edit. Its job is to falsify the
# chair's finding and design the correct attribution. The chair's read may be wrong; the owner's
# standard is explicit: "i don't want to have stats that lie".
#
# Owner, verbatim:
#   "some branches have production, some supplied by a commissary
#    for me, a no dr delivery is a branch production unless specified where the source is (branch or
#    commissary)
#    therefore, calculating production can be based from source (own production or commissary).
#    deliveries to branches with a known source can be used to calculate the production efficiency.
#    review this thoroughly. i don't want to have stats that lie. Sol can help. discuss"
#
# Review lane, so no acceptance criterion to turn green:
#   --no-acceptance-gate="review/design lane: the deliverable is a falsification + design report"

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

LANE_MODEL_CHAIN="openai-codex/gpt-5.6-sol,openai-codex/gpt-5.6-terra,deepseek-v4-flash"

PROMPT="$(cat <<'PROMPT_EOF'
You are an ADVERSARIAL REVIEWER and SCHEMA ANALYST in the Ikabud repo at /var/www/html/applicationostest.
Read-only: do NOT commit, do NOT edit, do NOT fix. Report only.
PHP 8.5 local; production MySQL 5.7 - no window functions, no CTEs, no JSON_TABLE, no CHECK.
Local site http://baronledger.test, tenant 207 holds a copy of the production DB. READ-ONLY on data.

# THE OWNER'S REQUIREMENT
Production efficiency must be attributed BY SOURCE. Some branches produce their own goods; others are
supplied by a commissary. The owner's rule: a delivery with NO DR is branch production, unless the
source is stated. So production should be computed from the declared source (own production vs
commissary), and deliveries with a KNOWN source can support the efficiency calculation.
The owner's standard is unambiguous: "i don't want to have stats that lie".

# THE CHAIR'S FINDING - FALSIFY OR CONFIRM IT. Do not assume it is right.
The chair claims all three of the following, and you should attack each:

  C1. THE NEW PRODUCTION-EFFICIENCY VIEW IGNORES SOURCE ENTIRELY.
      `supply_source_type` appears ZERO times in modules/daily-ledger/helpers/reporting.php. The only
      matches for source-ish names there are at :1243-1246, a SELECT DISTINCT assigned_commissary_id
      used for branch-network SCOPING, not for attributing production.
      Verify by reading the aggregation that feeds the view (routine
      dl_productionEfficiencyAggregates or similarly named, around reporting.php:1981-2260).
      Then decide: for a branch whose product is supplied by a commissary, what does the view
      actually attribute to that branch, and is the resulting "yield"/"sell-through"/"wastage rate"
      meaningful, misleading, or meaningless?

  C2. THE PRODUCTION LEDGER IS EMPTY ON TENANT 207.
      `SELECT COUNT(*) FROM dl_commissary_product_ledger` returns 0; produced_qty and wastage_qty sum
      to 0. If true, every Produced / Wastage / Yield figure on the view is a STRUCTURAL zero, not a
      measured one - and a page reading "0 produced, 0% wastage" tells an owner "no waste", which is
      false in the opposite direction from the usual error. Confirm the count, and then judge how the
      view currently RENDERS that emptiness: does it say "no data", or does it present a rate?

  C3. THE SUPPLY RULES ARE EMPTY.
      `SELECT COUNT(*) FROM dl_branch_product_supply_rules` returns 0, so no branch-product declares
      local_production vs commissary. If true, production CANNOT be attributed by source today even
      though migration 023 provides supply_source_type. Confirm, and report what would have to be
      populated for the owner's model to work.

Also measure and report, because the owner's rule depends on it:
  - dl_branches: is_commissary and assigned_commissary_id distribution (the chair saw 19 non-commissary
    + 2 commissaries).
  - Where a delivery's DR number lives (migration 020 has dr_number VARCHAR(100) DEFAULT NULL) and how
    many deliveries on tenant 207 have a DR versus none. The owner's rule is that a no-DR delivery is
    branch production, so this ratio is the evidence for whether that rule is currently expressible.
  - Whether anything in the codebase ALREADY derives "local production" from a missing DR, or from
    is_commissary/assigned_commissary_id. If something does, the chair's C1 is wrong - say so.

# TWO FURTHER OWNER RULES, added after the brief was written. Both are load-bearing.

## RULE 4 - UNITS SOLD CANNOT EXCEED PRODUCTION (the owner stated this as a fact, not a warning)
  "units sold cannot be more than the production"
Treat this as an INVARIANT to test, not a preference. The view currently carries warning flags
(sell_through_exceeds) for the case where sold > produced - so the code treats it as possible. Decide
which is true:
  - If the invariant genuinely holds once source is attributed correctly, then every place the view
    can show sold > produced is a BUG, and the warning is papering over it.
  - If it can legitimately hold (sales of stock produced in an earlier period, commissary-supplied
    stock, cross-period sales), say so with evidence and state the exact conditions.
The chair's hypothesis, which you should attack: sold > produced is the FINGERPRINT of the attribution
bug in C1 - a commissary-supplied branch records sales but no production of its own, so the ratio
inverts. Query it: per product and per branch, how many rows have sold > produced, and are those
branches commissary-supplied or local producers? That single measurement may settle C1.

## RULE 5 - SCOPE THE WINDOW TO DAYS WITH KNOWN PRODUCTION
  "the additonal data is even a good production info, to start with / if we scope data from days with
   know production starting"
The owner is prescribing a fix: restrict the efficiency computation to the days/period where production
is actually recorded, rather than comparing a full period of sales against sparse production. The
existing view already admits this problem in its own comment (world: "Measured on tenant 207:
production rows cover 4 days of a ...").
So measure and design:
  - how many days in the requested period have production rows, per branch;
  - what the ratios become if sales are restricted to those same days;
  - whether "additional data" (deliveries with a DR, and their absence) is usable as production
    information to start with, and if so exactly how it should enter the window;
  - what the view must show when the window contains no production at all.

# WHAT TO DESIGN (this is the other half of the deliverable)
Given what you find, specify the CORRECT model for attributing production, as a design note:
  1. The attribution rule per branch-product: which columns decide it, and what the default must be
     when nothing is declared. State the default explicitly and argue it - the owner says a no-DR
     delivery is branch production, so check whether "undeclared" should default to local production
     and what that costs if it is wrong.
  2. Which table each side of the efficiency ratio must come from, per source:
       local_production branch  -> ? produced, ? wasted, ? sold
       commissary-supplied      -> ? produced (whose output?), ? wasted, ? sold
     Be explicit that a commissary-supplied branch must not be charged with the commissary's output.
  3. Where the DR (or its absence) enters, and what happens for deliveries whose source is unknown.
  4. What the view must display when data is missing, so a structural zero can never be read as a
     measured zero. Name the states.
  5. The falsifiable acceptance checks a later implementation should fail against - one line each,
     stated as commands or renders, per the owner's "stats that lie" standard.

# METHOD
- Read the migrations for the real column names before asserting anything: 011, 016, 017, 020, 022,
  023, 037, 064 under modules/daily-ledger/database/migrations/.
- Query tenant 207 with the CLI-bootstrap pattern used in tools/acceptance-production-efficiency.php
  (kernelCliBootstrap + tenant 207 + modulePushContext). Never write.
- Where you disagree with the chair, say so plainly and show the command and its output. A chair
  finding that survives your attack is worth more than one you restate.

# REPORT - exactly this shape
verdict: CONFIRMED | PARTIALLY_CONFIRMED | FALSIFIED
per-claim:
  C1/C2/C3: verdict, the command you ran, its output, and your reasoning
measurements: branches by supply mode, DR presence ratio, row counts for every table you relied on
misleading_stats: every figure the current view can present that is not true, and what an owner would
  conclude from it
design: the attribution model, 1-5 above, with the default rule argued explicitly
acceptance_checks: the falsifiable checks a later implementation must satisfy
weakest_claim: the part of your own report you would least defend
unreached: what you could not determine, and what would be needed
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/lane-sol-production-attribution
