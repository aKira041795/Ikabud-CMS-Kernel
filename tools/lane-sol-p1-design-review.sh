#!/usr/bin/env bash
#
# Lane: sol-p1-design-review - ADVERSARIAL REVIEW OF A DESIGN, before implementation.
#
# Read-only. Sol must NOT commit or edit. Deliverable is a falsification + corrected design.
# This is the "before implementation" consultation point: a design error caught now is cheap,
# caught after ~400 lines it is not.
#
# Sol is available (checked with tools/model-availability.sh). Review lane, so no criterion to
# turn green: the deliverable IS the report.

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

LANE_MODEL_CHAIN="openai-codex/gpt-5.6-sol,openai-codex/gpt-5.6-terra,deepseek-v4-flash"

PROMPT="$(cat <<'PROMPT_EOF'
You are an ADVERSARIAL REVIEWER in the Ikabud repo at /var/www/html/applicationostest.
Read-only: do NOT commit, do NOT edit, do NOT fix code. Report only.
PHP 8.5 local; production MySQL 5.7 - no window functions, no CTEs, no JSON_TABLE, no CHECK.
Tenant 207 holds a copy of the production DB. READ-ONLY on data.

You reviewed this area already: docs/reviews/sol-production-attribution-2026-10-11.md is YOUR OWN
earlier review (C1 CONFIRMED: the production-efficiency view ignores source entirely and merges
commissary production with retail sales by product id). Read it first - it is your prior position.

# WHAT IS NEW SINCE YOUR REVIEW
The chair measured the data again and found a series YOUR review did not use:

  dl_daily_ledger.addtl  = stock moving INTO a branch (the "daily additional").
    Migration 007: "output movements -> addtl (stock delivered to branch)",
                   SET dl.addtl = GREATEST(0, t.net_output)  from dl_production_movements.
    Migration 024: "Only POSTED branch_receivings affect dl_daily_ledger.addtl."
    Ledger identity, exact per row: sales = GREATEST(0, beg_bal + addtl - withdraw - bal_end)

Measured on tenant 207, 2026-09-01..2026-10-11 (the period the view defaults to):

  per branch (sold uses the module's own dl_ledgerSalesQuantitySql(), NULL when bal_end IS NULL):
    bid= 8 Miputak   hybrid, assigned commissary 18 : addtl=104210 withdraw=10627 sold= 95704  -> 91.84%
    bid= 9 Rizal     hybrid, assigned commissary 18 : addtl= 16125 withdraw= 9982 sold=  6911  -> 42.86%
    bid= 11 Obay     hybrid, assigned commissary 18 : addtl=   626 withdraw=   37 sold=     0  ->  0.00%
    bid= 10,12,15    hybrid, assigned commissary 18 : addtl=     0 (no stock in recorded)
    bid= 18 RIZAL-COMMIS (is_commissary=1, self_managed) : addtl=513 withdraw=2609 sold=0
  network totals: addtl=121474  withdraw=23378  sold=102615  -> sold/addtl = 84.47%

  dl_commissary_product_ledger (ALL rows, all time, all branches):
    ONLY branch 18 has rows. 447 rows, 4 days (2026-10-08..2026-10-11).
    SUM(beg_qty)=77896  SUM(produced_qty)=8828  SUM(dispatched_qty)=12547  SUM(wastage_qty)=119
    SUM(remaining_qty) = -3838     <-- the GENERATED column, produced-dispatch-waste, NEGATIVE
    beg+produced-dispatched-wasted = 74058   <-- the book balance the generated column omits

  dl_branch_product_supply_rules: 0 rows (your C3 - still true).
  dl_branches: 2 commissaries (18, 21), 11 hybrid assigned to 18, 6 commissary_supplied
    assigned to 21, 2 self_managed unassigned (27, 28). 21 total.
  posted receivings in period: branch 8 qty 83783, branch 9 qty 15710, branch 11 qty 626,
    branch 18 qty 513. Note addtl(121474) > receivings(100632) by ~20800 - unreconciled.
  posted deliveries: origin 'commissary' with origin_id NULL -> branch 8, 107 docs, qty 83302;
    origin 'commissary'/18 -> many destinations, small qty; 229 no-DR docs all origin_type
    'commissary' origin_id 18 (so they are NOT local production under the owner's exception).
  dl_production_movements in period: only destination 18, 42 'output' rows, qty 8434.

# THE DESIGN THE CHAIR PROPOSES TO IMPLEMENT. YOUR JOB IS TO FALSIFY IT, NOT TO BLESS IT.
Split the single blended page into two explicitly-scoped panels and delete the blended ratio.

  PANEL A - SOURCE PRODUCTION, producers in scope ONLY.
    A "producer" branch = is_commissary=1 OR (default_supply_mode='self_managed' AND
    assigned_commissary_id IS NULL). Source = dl_commissary_product_ledger, restricted to those
    branch ids. Metrics: beg, produced, dispatched, wasted, closing = beg+produced-dispatched-wasted,
    yield% (produced/theoretical output on the batch basis, already implemented), wastage% =
    wasted/produced, day coverage. If NO producer is in scope: render a named state
    ("no producing branch in this scope; supplied by <name>"), never numeric zeros.

  PANEL B - DESTINATION STOCK CONVERSION, all branches in scope.
    Source = dl_daily_ledger only. Metrics: stock received (SUM addtl), sold, withdrawn,
    closing stock (SUM bal_end), and CONVERSION = sold / received. Renamed from "sell-through",
    because it is conversion of received stock, not production efficiency.

  DELETED: the headline "Sell-through % = sold/produced" (currently 1162.38% at all-branch scope)
  and "Shrinkage % = (produced - sold - wasted - remaining)/produced" (currently -1020.25%).
  Rationale: they divide destination sales by source production over windows that do not match,
  and remaining_qty omits opening stock so it is negative.

  PER-PRODUCT TABLE: each row carries a source class - producer | supplied:<branch name> | unknown -
  and shows only the ratio valid for that class. A supplied row must never render "0 produced".

  THE OWNER'S STATED MODEL, verbatim 2026-10-11:
    "the very first ledger entry of beginning and additional is a starting point. then moving
     forward, daily additional data is the production data. we have just started encoding
     production in october for commissary. i hand off to you how to properly arrive at a truthful
     stats, not necessarily accurate but useful and near true"
  So the owner himself points at addtl as the production data, and asks for truthful-and-useful
  rather than precise. The owner's other rule: "units sold cannot be more than the production".

# ATTACK THESE SPECIFIC POINTS. For each, state FALSIFIED / CONFIRMED / PARTIALLY and give evidence.

  Q1. Is addtl a legitimate "production-in" series, or is it contaminated? It exceeds posted
      receivings by ~20800 in the period, and its migration-007 definition comes from
      dl_production_movements (which only exist for destination 18 in this period). Find where
      branch 8's addtl is actually WRITTEN in the current code (not the migration): search the
      handlers for writes to dl_daily_ledger.addtl, and say whether the column is currently a
      trustworthy inflow series or a legacy artefact. If it is unreliable, the chair's Panel B
      denominator is wrong and you must say what to use instead.
  Q2. Does Panel B reintroduce a version of the defect you already found? At all-branch scope it
      would show 102615/121474 = 84.47%. Is that figure, presented as "conversion of stock
      received into sales", honest - or does it still blend a commissary's dispatch (branch 8 gets
      delivered stock) with a branch's retail sales in a way that misleads? Be specific.
  Q3. Panel A restricts production to producers. Is the "producer" predicate above right? Consider
      branch 8 = hybrid with its own addtl of 104210 - the owner says a no-DR delivery is local
      production, and branch 8 is 'hybrid'. Does excluding hybrids from producers hide real local
      production? What does the measured data say about whether hybrids produce?
  Q4. The chair deletes the blended sell-through and shrinkage. Is deleting them correct, or does
      that remove a signal the owner needs? If something must replace them, name the exact formula
      and its denominator, and say which table each side comes from.
  Q5. What must the page state so that no number on it can be read as more than it is? Give the
      exact states and where each renders.
  Q6. The chair intends to write the acceptance criterion RED on the unchanged tree FIRST. Write
      the checks you would use - falsifiable one-liners over tenant-207 data or over the rendered
      page - that FAIL today and PASS only when the page stops lying. At least one must fail
      because of the 1162.38% figure and one because a supplied branch renders 0 produced.

# METHOD
- Read your own prior report first: docs/reviews/sol-production-attribution-2026-10-11.md.
- Read the current implementation: modules/daily-ledger/helpers/reporting.php dl_productionEfficiencyData()
  (~line 2174), handleAdminProductionEfficiency() in handlers.php (~13382), and
  templates/modules/daily-ledger/admin/production-efficiency.disyl.
- Read migrations 007, 011, 016, 020, 024, 037, 064 before asserting column semantics.
- Query tenant 207 with the CLI-bootstrap pattern in tools/acceptance-production-efficiency.php.
  A ready-made probe is at /tmp/probe_p1.php and /tmp/probe_p2.php - read them, they are read-only.
- Never write. Never claim a number you did not obtain from a command whose output you show.

# REPORT - exactly this shape
verdict: CONFIRMED | PARTIALLY_CONFIRMED | FALSIFIED   (of the chair's design)
per-question: Q1..Q6 with evidence
addtl_write_path: the exact code path that writes dl_daily_ledger.addtl today, with file:line
corrected_design: your version of the model, as a precise diff-level specification
acceptance_checks: the falsifiable checks, one line each
weakest_claim: the part of your own report you would least defend
unreached: what you could not determine
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/lane-sol-p1-design-review
