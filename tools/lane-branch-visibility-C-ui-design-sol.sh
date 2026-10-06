#!/usr/bin/env bash
#
# Lane: branch-visibility-C-ui-design-sol
#
# OWNER (2026-10-06): "are you using Sol to set the UI, it is strong in this area."
# Honest answer at the time was NO — the implementation chain led with deepseek-v4-flash, so Sol was only a
# last-resort fallback. This lane is the correction: Sol LEADS a design-led pass over the ALREADY-BUILT picker
# (commit 6f1d506f), reviewing the real rendered artefact and implementing the improvements.
#
# Sol leading is deliberate: the functional slice is done and verified; what remains is design judgement —
# layout, hierarchy, density, interaction, accessibility — which is the higher-value reasoning tier.
#
# ARTEFACTS TO REVIEW (sol has no vision, so these stand in for the screenshot):
#   /tmp/chair-assignment-tab.html                        <- the REAL rendered markup (182 products, 320KB)
#   templates/modules/daily-ledger/admin/products.disyl   <- source (assignment tab + product modal)
#   tests/browser/daily-ledger-branch-product-visibility.spec.js <- the chair's read-only browser probe
#   /tmp/chair-assignment-tab.png                         <- screenshot exists but cannot be read by a text model
#
# CHAIR'S EYES ON THE RENDERED PAGE (branch 8 / Miputak, the owner's account):
#   Tabs:            [ Products ] [ Show in Branches ]   (Show in Branches active, indigo underline)
#   Filter row:      branch dropdown "DPL-MP1 - Miputak" | search "Type to filter..." |
#                    "182 product(s) shown"  "182 ticked"
#   Status strip:    "Editing products for DPL-MP1 - Miputak"
#   Information box: "Information: older unfinished days" / "These days are already stalled and do not block
#                    saving. You may want to look at them later." / "144 product(s) with older unfinished days"
#   Bulk controls:   [ Tick all shown ]  [ Untick all shown ]
#   Table:           SHOW | SKU | PRODUCT | CATEGORY   (182 rows, one scroll, all checked by default)
#
# MEASURED BROWSER EVIDENCE (chair, read-only, 8 passed): 19 branch options; 182 checkboxes; all 182 CHECKED
# for branch 8 (0 hidden pairs exist today); the ticked counter reads "182" on first paint; client-side search
# narrows 182 -> 1; switching branches keeps ?tab=assignment; the Branches page deep-links into the same tab.

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

# SOL LEADS (owner: strong at UI). terra is the strong fallback; flash last.
LANE_MODEL_CHAIN="openai-codex/gpt-5.6-sol,openai-codex/gpt-5.6-terra,deepseek-v4-flash"

PROMPT="$(cat <<'PROMPT_EOF'
You are doing a DESIGN-LED pass over a working, verified feature in the Ikabud repo at
/var/www/html/applicationostest. The owner specifically asked for your judgement on the UI. Implement your
improvements — this is not review-only.

PHP 8.5 local; production is MySQL 5.7 and no 5.7 server exists locally (label any 5.7 claim INSPECTION-ONLY).
You cannot see images, so the rendered markup at /tmp/chair-assignment-tab.html IS your view of the page;
read it, and read the section of templates/modules/daily-ledger/admin/products.disyl that renders the
assignment tab. The chair's description of the rendered layout is in the header of this lane's script.

## THE JOB THIS UI EXISTS TO DO (design against this, not against aesthetics in the abstract)
An admin wants to RETIRE products a branch no longer carries, so the cashier ledger and the production sheet
get shorter and more current. Two measured reasons this matters:
  - every branch currently lists all 182 products (transport 3458 = 182 x 19, 0 hidden), so the lists carry
    every discontinued item;
  - the production sheet's manual PM finalize requires a PM ending for EVERY assigned product even at zero
    movement, so removing irrelevant products directly cuts the operator's per-shift entry work.
The picker must make "retire these N products from this branch" fast, obvious, and hard to get wrong.

## FIXED — the owner has decided these. Do NOT change them.
- The placement: a TAB in the Products view. Keep the tab label EXACTLY "Show in Branches" (owner confirmed).
- The behaviour: checked = shown in that branch; ONE all-or-nothing save.
- The removal guard and its Option A scope (today, or previous day while open). Older open days are
  INFORMATION and must never block. Keep that block, worded as information.
- The deep link from the Branches page into this same tab.
- STABLE SELECTORS — the chair's independent read-only probe depends on these; if you rename one, update
  tests/browser/daily-ledger-branch-product-visibility.spec.js so it still passes:
    [data-products-tabs] [data-tab-products] [data-tab-assignment] [data-branch-products]
    #picker-table #picker-branch #picker-search #picker-checked-count #picker-visible-count #picker-save
    input[data-product-check]  (one per product)  #picker-warning  #picker-refusal
  Adding new hooks is fine. An implementer editing a probe so it agrees with new code destroys its
  independence, so treat renaming as a last resort and say why.

## REQUIRED IMPROVEMENTS (owner-endorsed direction)
1. A STATE FILTER beside the search: All / Shown here / Hidden here. Searching finds one product by name; the
   state filter is what makes "show me everything hidden from this branch" one action. That is the cleanup
   task.
2. A way to get from a product row to THAT PRODUCT's own view (the edit modal, which already carries
   assignment mode + branch checkboxes). This answers the other question — "which branches show this
   product?" — without building a products x branches matrix (19 x 182 cells is the scrolling problem
   inverted). Keep it one click.
3. Assess, and change if warranted, the bulk controls "Tick all shown" / "Untick all shown". Consider the
   footgun: with a search filter active these act on the FILTERED set, so an admin can silently untick 182
   products believing they acted on 4. Whatever you decide, the consequence of a bulk action must be
   unambiguous ON SCREEN before it is committed, and say so in your design note.

## YOUR JUDGEMENT — the part the owner asked you for
- Information hierarchy and density for 182 rows in one scroll. Is search + state filter + bulk controls
  enough, or is something else needed (category filter/sort/grouping/virtualisation)? Justify with the admin's
  task, and do not add machinery the task does not need.
- Whether CATEGORY belongs in the table, and whether the visible columns are the right set.
- The refusal presentation: a refusal must name the exact blocking date+shift PER product (the guard returns
  them). Make that scannable when several products are refused at once.
- Empty states (a search matching nothing; a branch with no products).
- Accessibility: label/control association, keyboard operation of 182 checkboxes, the counter and information
  block announced sensibly, colour not the only signal.
- Whether the "Information: older unfinished days" wording actually tells an admin what to DO about it.
- Any misleading affordance, dead control, or accidental data-loss path you find while reading the markup.

## HARD CONSTRAINTS
- Templates + minimal inline JS only. Do NOT change the guard, the Option A window, the row predicates, the
  write-side validation, the enforcement points, or any SQL semantics. This is a presentation pass.
- Do NOT add an assignment filter to the admin Sales view, and do NOT touch the two sheet list queries, the
  carry, the close-order path, or dl_reportSalesData.
- Do NOT touch public/daily-ledger/assets/ or sw.js without bumping CACHE_VERSION, and say so if you do.
- The Products tab must keep rendering its existing 182-row list unchanged.
- The browser probe must remain READ-ONLY against the live tenant: never click save, never change a checkbox
  and submit. A spec that mutates real assignments is a defect.
- MySQL 5.7-safe if you touch SQL (you should not need to): no CTE, window function, JSON_TABLE, or LIMIT in
  an IN (subquery). Keep storage/logs/error.log empty.

## ACCEPTANCE — run and report verbatim
    APP_URL=http://baronledger.test npx playwright test tests/browser/daily-ledger-branch-product-visibility.spec.js --reporter=line --retries=0
    php tests/daily-ledger/daily_ledger_branch_product_picker_test.php
    php tests/daily-ledger/daily_ledger_branch_product_visibility_test.php
    php tests/daily-ledger/daily_ledger_close_day_and_notify_test.php
    php tests/daily-ledger/daily_ledger_admin_sales_full_sheet_test.php
    php -l <each changed php file>
    git --no-pager diff --stat
    php /tmp/chair-verify-slice-a2.php
Then php scripts/run-tests.php --dir=tests/daily-ledger, separating PRE-EXISTING failures (known log/
notification pollution: daily_ledger_dispatch_enforcement_test, daily_ledger_preserve_cashier_variance_test,
flaky daily_ledger_delivery_record_authz_test) from new ones.

## REPORT — keep it tight
  STATUS: PASS | PARTIAL | BLOCKED
  DESIGN NOTE: what you changed and WHY, in terms of the admin's task (5-10 bullets, not prose)
  WHAT YOU DELIBERATELY DID NOT ADD and why (the owner values restraint)
  SELECTORS: confirm every stable selector above still resolves, or say exactly which you changed and that you
             updated the spec
  EVIDENCE: the verbatim acceptance output
  UNVERIFIED: anything you could not check
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/branch-visibility-C-ui-design
rc=$?
echo "lane: branch-visibility-C-ui-design-sol — completed by: ${LANE_MODEL_USED:-<none>} (rc=$rc)"
exit "$rc"
