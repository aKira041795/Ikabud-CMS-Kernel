#!/usr/bin/env bash
#
# Lane: sol-analytics-review - ADVERSARIAL REVIEW of the data filtering, computation logic and
# statistical soundness of the daily-ledger analytics, after P1 (source/destination split), P2
# (forecast per branch) and the official-only basis.
#
# Read-only. Sol must NOT commit or edit. The deliverable is a falsification report with evidence.
#
# Owner: "have this tested by you and sol if data filtering, logic in computations, stats analysis do
# hold. i need a business analysis that is production grade, useful, relevant, with data output based
# from evidences".
#
# Review lane, so no acceptance criterion to turn green.

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

LANE_MODEL_CHAIN="openai-codex/gpt-5.6-sol,openai-codex/gpt-5.6-terra,deepseek-v4-flash"

PROMPT="$(cat <<'PROMPT_EOF'
You are an ADVERSARIAL REVIEWER in the Ikabud repo at /var/www/html/applicationostest.
Read-only: do NOT commit, do NOT edit, do NOT fix. Report only, with evidence.
PHP 8.5 local; production MySQL 5.7 - no window functions, no CTEs, no JSON_TABLE, no CHECK.
Tenant 207 holds a copy of the production DB. READ-ONLY on data. Probe with the CLI-bootstrap
pattern used in tools/acceptance-truthful-production-stats.php.

You reviewed this area twice already; read your own prior work first:
  docs/reviews/sol-production-attribution-2026-10-11.md   (C1 CONFIRMED - source-blind aggregation)
  /tmp/lane-sol-p1-design-review-openai-codex_gpt-5.6-sol.log  (falsified the `addtl` premise)
Those reviews shaped the changes under review. Your job now is to attack the RESULT, not to defend it.

# WHAT LANDED IN THREE COMMITS
  84b63284  P1 source/destination split on /daily-ledger/admin/production-efficiency
  d4510115  official-only basis on the Overview and Production Efficiency views
  817db757  P2 forecast per branch, every product with movement
Read `git show --stat` for each and then the diffs for:
  modules/daily-ledger/helpers/reporting.php   (dl_efficiencySupplySources, dl_productionEfficiencyData,
    dl_forecastDemand, dl_forecastRows, dl_officialOnlySqlPredicate, dl_provisionalExclusion)
  modules/daily-ledger/handlers.php            (handleAdminProductionEfficiency, handleAdminForecast)
  templates/modules/daily-ledger/admin/production-efficiency.disyl, forecast.disyl, overview.disyl

# THE CLAIMS YOU MUST TEST. Attack each; do not restate them.

## A. THE OFFICIAL-ONLY BASIS (newest, least reviewed)
  1. The basis is dl_provisionalSqlExpr() - the SQL twin of dl_rowIsProvisional(). Verify they are
     still in LOCKSTEP. Read both, then find rows where the SQL twin and the PHP predicate would
     disagree (NULL end_source, a missing dl_ledger_shift_status row, shift='AM' vs 'PM', a shift row
     present but not finalized). Query for any actual disagreement on tenant 207 and report the count.
     The predicate is written `COALESCE(expr,0) = 0` because `NOT (expr)` is NULL when end_source is
     NULL. Check whether any OTHER predicate in the module negates dl_provisionalSqlExpr, or wraps it
     in a way that reintroduces the NULL trap.
  2. Is "official" the right population for a STATISTICS view? Money on these pages was already
     official-only; units were inclusive. Verify the money rule really is official-only in
     dl_overviewProductTotals / dl_overviewBranchProductTotals / dl_overviewOfficialSalesTotal.
     Then judge: with money official-only and units now official-only by default, what can an owner
     no longer see? Name the operational signal that disappears (say, how many units are stuck
     unfinalized) and say whether the page still discloses it anywhere.
  3. dl_provisionalExclusion() reports rows + units excluded. Verify its number against an independent
     computation per branch. Does it reconcile with (inclusive units - official units) for the
     efficiency view AND for the overview? Any branch where it does not?

## B. COMPUTATION LOGIC ON THE EFFICIENCY VIEW (P1)
  4. dl_efficiencySupplySources() classifies producer / supplied / mixed / unknown from production
     ROWS and receiving ORIGIN. Attack the predicate: a branch with production evidence but whose
     output is zero-priced or voided; a 'mixed' branch; a self_managed branch with no receipts. Is
     'mixed' reachable on tenant 207 at all, and if not, is that a finding about the data or a dead
     branch of the classifier?
  5. The panel separation: source production reads dl_commissary_product_ledger; stock movement reads
     dl_branch_receivings + dl_daily_ledger. Verify NO quantity is counted twice across the two panels
     and that neither panel silently includes the other's rows. In particular: branch 18 (the
     commissary) now appears in the stock-movement panel (received 513, sold 0) - is that honest, and
     does 'stock conversion 0.00%' at a commissary mislead?
  6. Closing stock = opening + produced - dispatched - wastage, and it renders a NAMED GAP when
     negative. Verify the arithmetic on tenant 207 and state whether the gap message is the right
     response to a genuinely unrecorded opening balance, or whether a better source for the opening
     exists in the schema (check migrations 064, 011, 036, 037 and dl_production_runs).
  7. yield_pct = produced / theoretical_output where theoretical_output = FLOOR(input/batch)*pieces.
     Probe whether the batch basis (migration 016) is populated for the products that are actually
     produced, and whether the resulting yield is meaningful or a ratio of unrelated units.

## C. FORECAST (P2)
  8. Rows are keyed on branch+product+shift, driven by official sales PLUS movement-only keys. Verify
     that a movement-only row cannot change any measured figure: it must contribute no daily sales
     and must not alter another row's average. Then judge whether a 0-average suggestion is the right
     output for a product with movement and no sales history.
  9. The stock offset is the branch's own latest counted ending (latest date, then latest shift).
     Attack the ordering: what happens when the latest date's PM ending is NULL but AM is counted, or
     when a branch's last counted day is weeks before the window ends? Is 'latest' the right choice
     versus 'latest within the window'? Query for actual cases on tenant 207.

## D. STATISTICAL SOUNDNESS - THE PART THAT MATTERS MOST TO THE OWNER
  10. For every headline figure now on the two pages, state: what population it describes, over what
      window, with what coverage, and what an owner would CONCLUDE from it. Then name any figure that
      is arithmetically true but business-misleading, and say exactly why.
  11. Name the questions this data CANNOT answer that an owner is likely to ask of it (cost, margin,
      per-lot attribution, spoilage by cause, demand seasonality). Be specific with the table/column
      that would be needed, or state that it does not exist.

# METHOD
- Measure. Never claim a number you did not obtain from a command whose output you show.
- Use a probe under /tmp; read the existing probes /tmp/probe_p1.php .. /tmp/probe_movement2.php and
  /tmp/probe_official.php first - they are read-only and already encode the queries.
- Note: the ModuleDB guard REJECTS derived tables (`FROM (SELECT ...) t`) - use GROUP BY + PHP
  aggregation instead, or you will get "Database access denied: accessed undeclared table".
- Where you disagree with a comment in the code, quote the comment and show the measurement.

# REPORT - exactly this shape
verdict: CONFIRMED | PARTIALLY_CONFIRMED | FALSIFIED   (of the analytics as production grade)
findings: one entry per numbered claim, each with verdict + command + output + reasoning
twin_lockstep: whether dl_provisionalSqlExpr and dl_rowIsProvisional agree, with the disagreement count
misleading_figures: every figure still on the pages that an owner could misread, and what they would conclude
unanswerable_questions: 11 above
weakest_claim: the part of your own report you would least defend
unreached: what you could not determine
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/lane-sol-analytics-review
