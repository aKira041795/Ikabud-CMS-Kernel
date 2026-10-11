verdict: PARTIALLY_CONFIRMED

per-claim:
  C1:
    verdict: CONFIRMED
    command:
      ```bash
      rg -n "supply_source_type|local_production|default_supply_mode|is_commissary|assigned_commissary_id|dr_number" \
        modules/daily-ledger/helpers/reporting.php
      ```
    output:
      ```text
      1243: SELECT DISTINCT assigned_commissary_id
      1246: AND assigned_commissary_id IS NOT NULL
      ```
    command:
      ```php
      // kernelCliBootstrap + tenant 207 + modulePushContext('daily-ledger')
      dl_productionEfficiencyData($db, [
          'accessible_branch_ids' => $allBranchIds,
          'branch_id' => 0,
          'date_from' => '2026-09-01',
          'date_to' => '2026-10-11',
          'product_id' => 0,
          'shift' => '',
      ]);
      ```
    output:
      ```json
      {
        "produced":8828,
        "sold":102615,
        "wasted":119,
        "sell_through_pct":1162.38,
        "wastage_rate_pct":1.35,
        "shrinkage_pct":-1020.25,
        "yield_pct":null,
        "production_min_date":"2026-10-08",
        "production_max_date":"2026-10-11",
        "production_days":4,
        "products":178,
        "product_exceeds":31,
        "sold_zero_production":94
      }
      ```
    reasoning: |
      `dl_productionEfficiencyData()` never resolves `supply_source_type`,
      `default_supply_mode`, delivery origin, or DR provenance.

      Its production query selects `dl_commissary_product_ledger` rows where
      `commissary_branch_id IN (selected branch IDs)`. Its sales query independently
      selects `dl_daily_ledger` where `branch_id IN (selected branch IDs)`. Both are
      then merged only by product ID.

      Consequently:
      - Branch 8 (Miputak, hybrid, assigned to commissary 18) renders 95,704 sold,
        zero produced and zero wastage.
      - Branch 9 (Rizal, hybrid, assigned to 18) renders 6,911 sold, zero produced
        and zero wastage.
      - Commissary 18 renders 8,828 produced, 119 wasted, zero sold.
      - “All branches” combines retail sales with commissary output and reports
        1,162.38% sell-through and -1,020.25% shrinkage.

      At branch scope the rates are generally meaningless/not computable because the
      source output is excluded. At all-branch scope they are misleading because
      unrelated destination sales and source production are combined without source
      attribution. The 1.35% wastage rate is arithmetically valid for commissary 18,
      but the page does not state that source scope.

      Source resolution does exist elsewhere:
      `handlers-deliveries.php::dl_resolveProductSupplySource()` uses active
      branch-product rules, then `default_supply_mode` and
      `assigned_commissary_id`; same-location eligibility also checks
      `is_commissary`. That does not falsify C1 because the reporting aggregation
      never calls or reproduces that logic.

  C2:
    verdict: FALSIFIED
    command:
      ```sql
      SELECT COUNT(*) AS rows_n,
             COALESCE(SUM(produced_qty),0) AS produced,
             COALESCE(SUM(wastage_qty),0) AS wasted,
             COALESCE(SUM(dispatched_qty),0) AS dispatched,
             MIN(ledger_date), MAX(ledger_date),
             COUNT(DISTINCT ledger_date) AS days
        FROM dl_commissary_product_ledger;
      ```
    output:
      ```json
      {
        "rows_n":447,
        "produced":"8828",
        "wasted":"119",
        "dispatched":"12547",
        "min_date":"2026-10-08",
        "max_date":"2026-10-11",
        "days":4
      }
      ```
    reasoning: |
      The production ledger is not empty on the current tenant-207 copy. The chair's
      count of zero is stale or was taken before these rows existed.

      Rendering also contradicts the chair's “0% wastage” assertion. When produced is
      zero, the template still displays headline quantity cards as “Produced 0 pcs”
      and “Wastage 0 pcs”, but the wastage, sell-through and shrinkage rates render
      `n/a`, not 0%. A sales-only scope therefore avoids a false 0% rate, while still
      exposing potentially deceptive structural-zero quantity cards.

  C3:
    verdict: CONFIRMED
    command:
      ```sql
      SELECT COUNT(*) AS rows_n, SUM(is_active = 1) AS active_n
        FROM dl_branch_product_supply_rules;
      ```
    output:
      ```json
      {"rows_n":0,"active_n":null}
      ```
    reasoning: |
      There are no product-specific declarations. Branch defaults do exist, so the
      operational delivery resolver can classify simple `self_managed` and
      `commissary_supplied` branches. It cannot truthfully distinguish which products
      are locally produced versus commissary-supplied at the eleven `hybrid`
      branches.

      The required population is one active rule for every relevant hybrid
      branch-product, and preferably every active branch-product:
      - `supply_source_type='commissary'`, with `source_id` equal to the producing
        commissary;
      - `local_production`, with the local producing branch identified;
      - `direct_purchase` or `manual` only where that is the actual source.

measurements:
  requested_period: 2026-09-01 through 2026-10-11 (41 days)

  branches_by_supply_mode:
    command:
      ```sql
      SELECT is_commissary, default_supply_mode,
             IF(assigned_commissary_id IS NULL,'NULL','SET') AS assigned_state,
             COUNT(*) AS n
        FROM dl_branches
       GROUP BY is_commissary, default_supply_mode, assigned_state;
      ```
    output:
      ```text
      non-commissary / commissary_supplied / assigned: 6
      non-commissary / hybrid              / assigned: 11
      non-commissary / self_managed        / unassigned: 2
      commissary     / self_managed        / unassigned: 2
      total: 21 = 19 non-commissaries + 2 commissaries
      ```
    detail: |
      Commissary 18 supplies the eleven hybrid Dipolog/Dapitan branches.
      Commissary 21 supplies six `commissary_supplied` Pagadian branches.
      Branches 27 and 28 are unassigned `self_managed` branches.

  DR_presence_ratio:
    location: |
      Formal delivery DR: `dl_deliveries.dr_number` from migration 024.
      Legacy withdrawal/delivery DR: `dl_cashier_withdrawals.dr_number` from
      migration 020. Receipts mirror it in `dl_branch_receivings.dr_number`.
    command:
      ```sql
      SELECT COUNT(*) AS rows_n,
             SUM(NULLIF(TRIM(dr_number),'') IS NOT NULL) AS with_dr,
             SUM(NULLIF(TRIM(dr_number),'') IS NULL) AS without_dr
        FROM dl_deliveries;
      ```
    output:
      ```text
      all posted deliveries: 442
      with DR:                213 (48.19%)
      without DR:             229 (51.81%)
      ```
    requested_period_output:
      ```text
      deliveries: 424
      with DR:    195, quantity 112,197
      without DR: 229, quantity   3,592
      ```
    provenance_finding: |
      All 229 no-DR deliveries explicitly say `origin_type='commissary'`,
      `origin_id=18`. They therefore fall under the owner's “unless the source is
      stated” exception and must not be reclassified as branch production.

      Of the 213 with-DR deliveries:
      - 134 say `origin_type='commissary'`; 132 omit `origin_id`, but every one is
        inferable through the destination's `assigned_commissary_id`;
      - 79 say `origin_type='branch'` and have an origin ID.

      Thus all 442 formal deliveries are source-attributable from explicit or
      inferable provenance, despite 132 lacking a direct origin ID.

      The legacy `dl_cashier_withdrawals` table has zero
      `withdrawal_type='delivery'` rows, so formal deliveries are the operative
      evidence here.

  sold_greater_than_produced:
    command_basis: |
      Sold uses the exact expression used by the view:
      `GREATEST(0, beg_bal + addtl - withdraw - bal_end)`, excluding NULL endings.
    per_branch:
      ```text
      Branch 8 Miputak: 115 product rows sold > produced;
                        all 115 have sold > 0 and branch production = 0.
      Branch 9 Rizal:    96 product rows sold > produced;
                        all 96 have sold > 0 and branch production = 0.
      Commissary 18:      0 product rows sold > produced; it has no recorded sales.
      Total branch-product violations: 211.
      ```
    all_scope_product_merge:
      ```text
      178 product rows
      125 mathematically have sold > produced
       94 have sold > 0 and produced = 0
       31 are marked by the view's `sell_through_exceeds` flag
      ```
    interpretation: |
      This is a strong fingerprint of C1: retail branches hold sales while their
      supplying commissary holds production. The all-scope merge hides branch/source
      identity and flags only rows with a positive denominator; the 94 sold-with-zero
      production rows become `n/a` rather than an exceeds warning.

      The owner's statement is valid as a production-lot conservation invariant:
      sales attributable to a lot cannot exceed that lot's output. It is not valid
      for arbitrary calendar-period flows. Sales may legitimately exceed production
      recorded in the same period when opening stock or prior-period production is
      sold. It is also expected under the present defect when branch sales are
      compared with branch-local production despite commissary supply.

  production_window:
    command:
      ```sql
      SELECT commissary_branch_id, ledger_date, COUNT(*) AS rows_n,
             SUM(produced_qty) AS produced, SUM(wastage_qty) AS wasted
        FROM dl_commissary_product_ledger
       WHERE ledger_date BETWEEN '2026-09-01' AND '2026-10-11'
       GROUP BY commissary_branch_id, ledger_date;
      ```
    output:
      ```text
      source 18, 2026-10-08: 181 rows,   23 produced,  0 wasted
      source 18, 2026-10-09:  91 rows, 7133 produced, 64 wasted
      source 18, 2026-10-10: 150 rows, 1672 produced, 55 wasted
      source 18, 2026-10-11:  25 rows,    0 produced,  0 wasted
      ```
    summary: |
      Only source branch 18 has production rows: four covered days, of which three
      have positive output. Every other branch has zero production-row days.

      Full-period sales / production:
      102,615 / 8,828 = 1,162.38%.

      Restricting network sales to the four production-row days:
      14,747 / 8,828 = 167.05%.

      Restricting further to matching source/product/days:
      14,095 / 8,828 = 159.66%.

      Rule 5 improves comparability but does not restore the invariant. Opening
      inventory, sparse/incomplete production capture, and source/destination
      attribution remain material.

  row_counts_every_table_relied_on:
    ```text
    dl_branches:                       21
    dl_products:                      178
    dl_branch_product_supply_rules:     0
    dl_commissary_product_ledger:      447
    dl_production_runs:                 41
    dl_production_movements:            42
    dl_daily_ledger:                15,588
    dl_deliveries:                     442
    dl_delivery_items:               3,209
    dl_branch_receivings:              156
    dl_branch_receiving_items:       2,433
    dl_cashier_withdrawals:           1,730
      pullout rows:                     558
      legacy delivery rows:              0
    ```

misleading_stats:
  - figure: "All branches: 8,828 produced versus 102,615 sold"
    problem: Production belongs to source 18; sales belong primarily to branches 8 and 9. The populations are not source-attributed.
    likely_owner_conclusion: The business sold over eleven times what it produced.

  - figure: "Sell-through 1,162.38%"
    problem: Full-period retail sales are divided by four days of commissary output.
    likely_owner_conclusion: Impossible over-selling or extraordinarily efficient demand conversion.

  - figure: "Shrinkage -1,020.25%"
    problem: Sales, source output, dispatches and daily remaining values are not a matched stock flow or production cohort.
    likely_owner_conclusion: A huge unexplained stock gain occurred.

  - figure: "Miputak: 0 produced, 0 wastage"
    problem: The selected retail branch excludes its supplying source's production ledger.
    likely_owner_conclusion: The branch produced nothing and incurred no waste, rather than “this branch was supplied by commissary 18.”

  - figure: "Rizal: 0 produced, 0 wastage"
    problem: Same structural-zero attribution defect.
    likely_owner_conclusion: No production and no waste were measured.

  - figure: "Commissary 18: 0% sell-through"
    problem: Output is held at commissary 18 while sales occur at supplied retail branches.
    likely_owner_conclusion: None of the commissary's output sold.

  - figure: "Wastage rate 1.35%"
    problem: Numerically valid for source 18, but presented under an all-branch scope without naming the source.
    likely_owner_conclusion: Every selected branch had the same measured waste performance.

  - figure: "31 exceeds warnings"
    problem: There are 125 all-scope products with sold > produced; 94 zero-denominator cases suppress the warning and show `n/a`.
    likely_owner_conclusion: Only 31 products violate the relationship.

  - figure: "Net remaining" and "shrinkage"
    problem: `remaining_qty` is source production less dispatch and waste, while sold is destination retail depletion. Their subtraction is not a branch-level mass balance.
    likely_owner_conclusion: The residual represents physical shrinkage; it does not under the current joins.

  - figure: "Yield n/a"
    problem: Honest as rendered, but it also shows that no configured batch basis supports the headline production-efficiency claim.
    likely_owner_conclusion: No yield conclusion should be drawn; this one is correctly unavailable.

design:
  1. attribution_rule:
     precedence:
       1. Use an active `dl_branch_product_supply_rules` row: `branch_id`, `product_id`, `supply_source_type`, `source_id`.
       2. Otherwise use `dl_branches.default_supply_mode` plus `assigned_commissary_id`.
       3. For an actual movement, explicit delivery provenance (`origin_type`, `origin_id`, `resolved_origin_id`, or an unambiguous assigned commissary) overrides a DR-based inference.
       4. If no source is stated and a delivery has no DR, apply the owner's default: local production by the destination branch.
       5. If there is neither declaration nor delivery evidence, classify it as `source_unknown`, not silently local.
     default_argument: |
       “No DR means local” is safe only for a source-less no-DR movement. Applying
       local production to every undeclared branch-product would falsely convert
       commissary dispatches, purchases, and legacy unknowns into branch output.
       That would violate “stats that lie.” The cost of an `unknown` default is
       reduced coverage; the cost of a wrong local default is fabricated production.
       Coverage loss is the honest choice.

  2. ratio_sources:
     local_production_branch:
       produced: |
         Measured local output from production runs/movements and the applicable
         finished-goods product ledger for that producing branch. Avoid double
         counting bridged run, movement and CPL records.
       wasted: |
         Producer-side `dl_commissary_product_ledger.wastage_qty`, supplemented only
         by deduplicated wastage-classified movements tied to that source.
       sold: |
         `dl_daily_ledger` sales for that same branch/product and matched production
         window or attributable production cohort.

     commissary_supplied:
       produced: |
         Output of the identified source commissary from
         `dl_commissary_product_ledger`, never copied onto or charged to each
         destination branch.
       wasted: |
         Producer-side waste belongs to the commissary. Branch spoilage is a
         separate destination loss from classified pullouts/returns.
       sold: |
         For source production efficiency, aggregate sales across destinations
         attributed to that commissary and product. At a single retail branch,
         show received-versus-sold/remaining performance, not
         branch-produced-versus-sold.
       prohibition: |
         A branch must never receive the whole commissary output as its own
         production denominator. If production cannot be allocated to deliveries
         or lots, report source/network efficiency and branch sell-through as
         separate metrics.

     direct_purchase_or_manual:
       behavior: |
         Do not compute production yield without producer-output evidence. Show
         received/sold/waste metrics and an explicit non-production source state.

  3. DR_rule:
     - `dl_deliveries.dr_number` is the canonical formal delivery DR; legacy DR is in `dl_cashier_withdrawals.dr_number`.
     - A stated source wins regardless of DR presence. Tenant 207's 229 no-DR deliveries explicitly come from commissary 18.
     - A source-less no-DR movement defaults to local production under the owner's rule and must retain an `inferred_no_dr_local` provenance state.
     - A DR with known source establishes source and delivery/availability timing, but delivery quantity is dispatch or receipt—not automatically production, because opening stock can be dispatched.
     - DR-backed quantities can define a branch received-stock window. They can enter production only when linked to a production run/movement or explicitly accepted as a labelled production proxy.
     - Unknown-source deliveries remain `source_unknown` and are excluded from efficiency ratios.

  4. missing_data_states:
     - `measured`: source, output and matching window are known.
     - `measured_zero`: a covered source/product/day explicitly records zero output or waste.
     - `partial_coverage`: some requested days or products have production evidence and others do not.
     - `not_recorded`: no production record exists; render “Not recorded,” never numeric zero.
     - `source_unknown`: movement exists but producer cannot be attributed.
     - `not_applicable`: direct-purchase/manual product has no production-efficiency measure.
     - `proxy_inferred`: no-DR source-less movement treated as local under the owner's rule.
     - `not_computable`: denominator, batch basis, or matched window is absent.

     display_rule: |
       When a window has no production evidence, Produced, Wastage, Yield,
       Sell-through-against-production and Shrinkage must show `Not recorded`/`n/a`.
       Numeric `0` or `0%` is permitted only for a measured-zero state.

  5. implementation_constraints:
     - Scope comparisons by source, product and known-production days—not merely the global minimum/maximum production dates.
     - Distinguish row-covered days from positive-output days.
     - Preserve opening stock or use production lots if enforcing sold ≤ production.
     - If only calendar-period flows are available, compare sold against available stock (`opening + local output + attributable receipts`) and do not label the result a production-lot invariant.
     - Keep source/network production efficiency separate from destination-branch stock sell-through.

acceptance_checks:
  - ```sql
    SELECT COUNT(*) FROM dl_branch_product_supply_rules WHERE is_active=1;
    ```
    must be nonzero and cover every active product at each hybrid branch before hybrid efficiency is presented.

  - ```bash
    rg -n "supply_source_type|default_supply_mode|assigned_commissary_id" modules/daily-ledger/helpers/reporting.php
    ```
    must find source attribution in the efficiency path, not only scope expansion.

  - Render branch 8 for 2026-09-01..2026-10-11: it must say “Supplied by RIZAL-COMMIS” and must not render “0 produced” as measured branch output.

  - Render commissary 18/network: its 8,828 output must be associated with source 18 exactly once, not once per supplied branch.

  - Query no-DR deliveries: all rows with explicit `origin_type='commissary'` must remain commissary-sourced; none may be inferred as local production.

  - Insert-fixture/render test: a source-less no-DR delivery must resolve to `inferred_no_dr_local`; the same row with a stated commissary source must resolve to commissary.

  - A branch-product with no production evidence must render `Not recorded` or `n/a`, never `0 produced`, `0 wastage`, or `0% wastage`.

  - Source/product/day matching must exclude sales on days without production evidence and display the excluded-day count.

  - With tenant-207 data, a “known production days” view must report four row-covered days and three positive-output days for source 18.

  - A production-cohort report must fail if attributable cohort sales exceed cohort output; a calendar-period report must instead include opening stock and receipts before applying the invariant.

  - `sell_through_exceeds` must not be the mechanism that legitimizes an impossible cohort result; impossible results must fail validation or be marked attribution-incomplete.

  - Direct-purchase/manual products must render production efficiency as `not applicable`, not zero.

  - Totals by source must reconcile: summing source output must equal the underlying production ledger without duplicating output across destination branches.

weakest_claim:
  The 167.05% and 159.66% matched-window ratios are diagnostic rather than a final business ratio. They restrict sales to production-record days but do not trace opening inventory or individual production lots. They prove that date restriction alone is insufficient; they do not prove which specific sold units came from earlier production.

unreached:
  - No immutable lot-level link from a retail sale to a production run was found, so true cohort sell-through cannot be reconstructed from the inspected tables.
  - Product-specific source truth for the eleven hybrid branches cannot be determined from tenant 207 because the supply-rule table is empty.
  - The 132 with-DR commissary deliveries lacking direct `origin_id` are inferable from branch assignment, but confirming their historical paper source beyond that inference would require original DR documents or an owner-approved backfill.
  - Whether every zero CPL row represents an intentional measured zero or a pre-created placeholder requires entry-workflow metadata not present in the inspected rows.
