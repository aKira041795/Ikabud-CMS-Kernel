#!/usr/bin/env bash
#
# Lane: branch-visibility-B-picker  (REWRITTEN — placement now specified by the owner)
#
# OWNER QUESTION/DECISION (2026-10-06): "were do I see the product assignment per branch view? what do you
# plan? as a tab in Products view?" -> YES: the per-branch assignment view is a TAB IN THE PRODUCTS VIEW.
# The first draft of this brief left placement to the lane's judgement ("your call"); it was stopped before
# writing any UI so the placement could be fixed first.
#
# Depends on Slice A (2cbbd9bc) and A2 (c59f4132), both shipped and chair-verified:
#   dl_setBranchProductActive() / dl_setProductActive()  - audited primitives
#   dl_branchProductUnassignmentBlockers()               - refuses per Option A (today / prev-while-open)
#   dl_productOlderOpenDayWarnings()                     - OLDER unfinished days: INFORMATION, never a block
#   write-side validation rejects writes for unassigned pairs
#   assignment_mode ENUM('all_active','specific') DEFAULT 'all_active' on dl_products
#
# CHAIR'S BROWSER BASELINE (shiela_baina / shielab123, APP_URL=http://baronledger.test):
#   /daily-ledger/admin/products -> 200, 182 tbody rows, shows "{n} branches"
#   /daily-ledger/admin/branches -> 200, 19 tbody rows, lists the commissary
# Both MUST still render after this slice.
#
# INHERITED PARTIAL WORK — READ THIS. The previous run of this lane was killed mid-flight and had already
# written ~247 lines of BACKEND helpers into modules/daily-ledger/handlers.php:
#   dl_bulkAssignBranchProductsCore(), dl_applyProductAssignmentMode(), dl_normalizeAssignmentMode(),
#   dl_targetBranchIdsForAssignmentMode(), dl_assignmentOlderOpenDayWarnings()
# It was never reviewed or tested (it was killed before reaching the oracle), and it has NO routes and NO UI.
# You OWN that code now: read each function against the contract, keep it if correct, rewrite or delete it if
# not, and make the oracle exercise it. Say in your report which of those functions you kept vs changed.

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

LANE_MODEL_CHAIN="deepseek-v4-flash,openai-codex/gpt-5.6-terra,openai-codex/gpt-5.6-sol"

PROMPT="$(cat <<'PROMPT_EOF'
You are implementing SLICE B (the picker) in the Ikabud repo at /var/www/html/applicationostest. PHP 8.5
local; production is MySQL 5.7 and no 5.7 server exists locally — label any 5.7 claim INSPECTION-ONLY.

READ FIRST:
  .ai/dl-branch-product-visibility.contract.md   (approved contract, owner decisions, Option A amendment)
  modules/daily-ledger/handlers.php   the inherited helpers listed above, plus
                                      apiCreateProduct() ~14812, apiUpdateProduct() ~14913, apiCreateBranch(),
                                      handleAdminProducts(), handleAdminBranches()
  modules/daily-ledger/routes.php     (existing declarative style)
  templates/modules/daily-ledger/admin/products.disyl   (the product list + the edit-product modal)
  templates/modules/daily-ledger/admin/branches.disyl
  tests/browser/daily-ledger-branch-product-visibility.spec.js  (the CHAIR's baseline spec)

## B1 — PLACEMENT IS SPECIFIED. Do not improvise a different home.
The per-branch assignment view is a TAB IN THE PRODUCTS VIEW:

  - `/daily-ledger/admin/products` gains two tabs, server-rendered as LINKS (no JS framework):
        [ Products ]            <- the EXISTING product list, behaviour unchanged (182 rows)
        [ Show in Branches ]    <- the NEW per-branch assignment picker
    Drive them with a query parameter, e.g. `?tab=assignment`, so tabs are linkable and back/forward work.
    With NO `tab` parameter the page MUST render exactly the existing product list as it does today.
  - The assignment tab, all on ONE screen:
        1. a BRANCH selector (dropdown) — the branch being edited is always visible;
        2. a SEARCH box filtering by name/SKU, client-side (182 products must not require scrolling to
           find one; a picker that reintroduces scrolling defeats the owner's stated goal);
        3. a checklist of that branch's active products — checked = shown in that branch;
        4. ONE explicit save, ALL-OR-NOTHING;
        5. the older-open-day INFORMATION from dl_productOlderOpenDayWarnings() — clearly information,
           never an error and never a block.
  - Switching branches uses the selector; the admin must not navigate away and back.
  - DEEP LINK for the other mental model ("what does this branch show?"): add ONE link per branch row on
    `branches.disyl` to the SAME tab with that branch preselected, e.g.
    `/daily-ledger/admin/products?tab=assignment&branch_id={id}`. ONE page, two entry points — do NOT build
    a second picker.
  - On refusal, name the exact blocking date+shift for EACH refused product. Never a bare count.
  - Keep it lightweight: server-rendered + small inline JS preferred. If you add a file under
    public/daily-ledger/assets/, you MUST bump CACHE_VERSION in public/daily-ledger/sw.js (the PWA precaches
    that directory cache-first) — and say so in your report.

## B2 — the product modal (owner decision 1)
`apiCreateProduct()` currently assigns every new product to ALL active branches and ignores any input. Add the
owner's option: "show in all active branches" (today's behaviour) OR specific branches, persisted as
`dl_products.assignment_mode` ('all_active' | 'specific').
  - A MISSING option MUST behave as 'all_active' — backward compatible for existing callers and imports.
  - Apply on CREATE and on UPDATE.
  - LOSSLESSNESS IS A DEFECT: the edit modal currently receives only a branch count (products.disyl:129). It
    must receive the product's current `assignment_mode` AND its assigned branch IDs, so opening an edit and
    saving cannot silently rewrite assignments. Prove it.
  - `apiCreateBranch()` already assigns only 'all_active' products (Slice A) — do not change it.

## HARD CONSTRAINTS
- Do NOT change the two sheet LIST queries, the carry, the close-order path (67255d4b), handleAdminSales
  (32f2e431/7eda56e3), or dl_reportSalesData. The admin Sales view must NOT gain an assignment filter: its
  UNION must keep showing any pair holding a ledger row so recorded money can never vanish.
- Do NOT change the guard's semantics, the Option A window, the row predicates, or the migration.
- Do NOT build Slice C (production dispatch destination cells, delivery picker, usage page, generic
  production-products API, trace/report dropdowns) or Slice D (CSV import). Out of scope.
- Do NOT touch public/daily-ledger/assets/ or sw.js unless you bump CACHE_VERSION.
- /daily-ledger/admin/products (182 rows) and /daily-ledger/admin/branches (19 rows) must keep working.
- MySQL 5.7-safe SQL: no CTE, no window function, no JSON_TABLE, no LIMIT inside IN (subquery).
- Keep storage/logs/error.log empty.

## ORACLE — required (TestHarness pattern, private fixture branches, never real branch 8)
  P1. assigning a product to a branch activates the pair and that branch's sheet list then includes it.
  P2. unassigning removes it from that branch's list and leaves other branches untouched.
  P3. a bulk save containing ONE refused removal applies NOTHING (all-or-nothing) — prove the other intended
      changes did not land.
  P4. a refusal names the blocking date+shift (not a bare count).
  P5. a product with ONLY older unfinished rows is still unassignable, and the warning is returned.
  P6. create with 'all_active' assigns all active branches; 'specific' assigns exactly the given branches; a
      MISSING mode behaves as 'all_active'.
  P7. update changing only price/active flag does NOT alter assignments (the losslessness guard).
  P8. a later-created branch does NOT receive 'specific' products — reference A2's existing G10 rather than
      duplicating it, and say so.
State which cases FAIL on the base tree (commit c59f4132). Do not claim discrimination you did not observe.

## BROWSER VERIFICATION (TARGETED ONLY — the full suite times out and is not your job)
Extend tests/browser/daily-ledger-branch-product-visibility.spec.js with picker assertions, then run ONLY it:
    APP_URL=http://baronledger.test npx playwright test tests/browser/daily-ledger-branch-product-visibility.spec.js --reporter=line --retries=0
Login is shiela_baina / shielab123. Assert: the Products tab still lists products; the assignment tab opens,
renders a branch selector, a product checklist and a search box; typing in search filters the list; the
existing baseline assertions still pass.
CRITICAL: the browser run must NOT mutate the real tenant's assignments. Stay read-only — do not save a
checkbox change against the live tenant. A spec that changes real assignments is a defect, not a test.

## ACCEPTANCE — run and report verbatim
    php tests/daily-ledger/daily_ledger_branch_product_visibility_test.php
    php tests/daily-ledger/daily_ledger_close_day_and_notify_test.php
    php tests/daily-ledger/daily_ledger_admin_sales_full_sheet_test.php
    php tests/daily-ledger/<your new test(s)>.php
    php -l <each changed php file>
    git --no-pager diff --stat
    php /tmp/chair-verify-slice-a2.php      (must still AGREE, 0 blocked)
    APP_URL=http://baronledger.test npx playwright test tests/browser/daily-ledger-branch-product-visibility.spec.js --reporter=line --retries=0
Then: php scripts/run-tests.php --dir=tests/daily-ledger, separating PRE-EXISTING failures (known log/
notification pollution: daily_ledger_dispatch_enforcement_test, daily_ledger_preserve_cashier_variance_test,
flaky daily_ledger_delivery_record_authz_test) from new ones.

## Rules
- Smallest correct change. No refactor, no new dependency, no scope creep.
- Never weaken, skip, or delete an assertion to reach green.
- If a requirement conflicts with a HARD CONSTRAINT, STOP and report BLOCKED with the reason.
- Report status PASS | PARTIAL | BLOCKED; files changed (incl. any CACHE_VERSION bump); which inherited
  helpers you kept vs changed; tests added/changed; the exact browser output; anything you could not verify.
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/branch-visibility-B-picker
rc=$?
echo "lane: branch-visibility-B-picker — completed by: ${LANE_MODEL_USED:-<none>} (rc=$rc)"
exit "$rc"
