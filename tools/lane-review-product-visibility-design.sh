#!/usr/bin/env bash
#
# Lane: review-product-visibility-design
#
# OWNER REQUEST (2026-10-06, verbatim):
#   "the client is wanting to have the production products list be customizeable. right now, it uses the
#    cashier ledger's list of products. my idea: add at products edit the ability to filter a product to
#    show only in production, show in both cashier and production or hide in production or cashier.
#    goal, no breaking of what is working but enhance the feature. review. use Sol for comprehensive review"
#
# This is a DESIGN REVIEW lane. Nothing is implemented yet. Sol owns architecture/review. The chair has
# already done recon and written the draft contract; the value here is finding what the chair MISSED,
# especially consumers of the shared product universe that a naive filter would break.
#
# Sol first (explicitly requested); terra is the strong fallback; flash last.

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

LANE_MODEL_CHAIN="openai-codex/gpt-5.6-sol,openai-codex/gpt-5.6-terra,deepseek-v4-flash"

PROMPT="$(cat <<'PROMPT_EOF'
You are reviewing a DESIGN for an accounting-facing ledger module in the Ikabud repo at
/var/www/html/applicationostest (PHP 8.5 locally, production is MySQL 5.7 on shared hosting; no 5.7
server is available locally, so 5.7 claims are inspection-only and must be labelled as such).

READ FIRST, in this order:
  1. The draft contract: .ai/dl-production-product-visibility.contract.md
  2. The two shared product lists and the product edit path in modules/daily-ledger/handlers.php:
       dl_fetchActiveProductsForProduction()   ~line 663
       dl_fetchProductionSheetProducts()       ~line 715
       dl_fetchCashierLedgerRows()             ~line 6427 (search for it; line numbers drift)
       dl_shiftMissingEndings()                ~line 4994
       dl_autoCarryBeginnings()                ~line 6336
       apiCreateProduct()                      ~line 14812
       apiUpdateProduct()                      ~line 14913
       handleAdminSales()                      ~line 10800 (it contains a derived-UNION driving set)
  3. templates/modules/daily-ledger/admin/products.disyl and commissary.disyl
  4. modules/daily-ledger/helpers/ and modules/daily-ledger/handlers-offline.php

## Context you must not lose
The admin Sales view was changed TODAY (commits 32f2e431 then 7eda56e3) specifically so that a product
can no longer be INVISIBLE to the admin while its recorded money stays in the totals. Its driving set is a
derived UNION of (active product/assignment pairs) and (any pair holding a ledger row in range). Any
proposal that filters the ADMIN view by a presentation flag re-opens that defect by a different door. The
contract says do not filter it — attack that decision if you think it is wrong, but do not silently ignore
the history.

## Your job — be comprehensive, and falsify
A. Verify or refute EVERY row of the contract's "Enforcement points" table against the real code. For each:
   does the proposed filter belong there, and what breaks if it is applied — or omitted?
B. ENUMERATE every consumer of the shared product universe that the table MISSES. Grep for the
   `dl_products` x `dl_branch_products` join pattern, for `p.is_active = 1`, and for callers of each list
   function. Candidates to check and rule in or out explicitly, with file:line:
     - the day-close gate and the variance recompute/freeze path
     - the production "filler"/completeness sweep that records 0 endings for products lacking one
     - the offline bootstrap payload and any cached product list on a device
     - the carry (dl_autoCarryBeginnings) for BOTH the cashier and production side
     - product dropdowns and filters on the admin pages (e.g. dl_reportFilterProducts)
     - the print/PDF daily sheet paths
     - scheduled exports / any CLI or cron path
     - the POS path, if it also enumerates products
     - any OTHER module that reads dl_products (grep outside modules/daily-ledger too)
C. Adjudicate risk R1 (stranded ending) properly. This is the one most likely to break production:
   a product hidden from the PRODUCTION sheet whose row already exists, bears activity, and has no ending,
   still blocks PM finalize under dl_shiftMissingEndings(), yet the production user can no longer see the
   row to complete it. Specify the smallest correct resolution and the assertion that would pin it.
D. Decide the DATA MODEL as architect: one 4-state column vs two booleans, and `dl_products` (global) vs
   `dl_branch_products` (per branch). Justify against what the code has to do, and state what the client
   loses by not having per-branch control.
E. State the NO-BREAK default strategy explicitly: what guarantees that every existing product, row,
   report and total is unchanged on the day this ships, and how that would be measured.
F. Name what could REGRESS that a test would have to pin, and give the minimal test set (name each case).

## Rules
- Read-only. Do NOT modify, create, or delete any file. Do NOT implement anything.
- Label EVERY finding as (a) MEASURED, (b) REASONED, or (c) COULD NOT CHECK. Do not present a reasoned
  claim as a measured one.
- Cite file:line for every code claim. If you cannot find a consumer you suspected, say so plainly.
- Prefer precision to volume. No padding, no restating the contract back.

## Report format
  VERDICT: SOUND | SOUND_WITH_CHANGES | UNSOUND
  ENFORCEMENT TABLE: confirmed / corrected / added rows (with file:line)
  MISSED CONSUMERS: list, each with file:line and the required decision
  R1 RESOLUTION: the smallest correct fix + the assertion that pins it
  DATA MODEL: your recommendation + why
  NO-BREAK DEFAULT: how it is guaranteed and measured
  REGRESSION TESTS: the minimal named set
  BLOCKERS: anything that must be decided by the owner before implementation
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/review-product-visibility-design
rc=$?
echo "lane: review-product-visibility-design — completed by: ${LANE_MODEL_USED:-<none>} (rc=$rc)"
exit "$rc"
