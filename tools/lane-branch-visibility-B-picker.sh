#!/usr/bin/env bash
#
# Lane: branch-visibility-B-picker
#
# SLICE B — the user-visible feature. Owner's words (2026-10-06, verbatim):
#   "the UI must be simple to use. a list of products then check boxes for admin to select as show in branch.
#    therefore, for a commissary, since it's treated as a branch, admin can select products it shows. when
#    adding products, per setup of the product modal, admin can also set if shows in all branches or just a
#    specific branch like the commissary. your decision. simple feature"
#
# Depends on Slice A (2cbbd9bc) and A2 (c59f4132) which are shipped and verified:
#   - dl_setBranchProductActive() / dl_setProductActive() are the audited primitives to call.
#   - dl_branchProductUnassignmentBlockers() refuses per Option A (today, or previous day while open).
#   - dl_productOlderOpenDayWarnings() returns OLDER unfinished days as INFORMATION (never a refusal).
#   - Write-side validation already rejects writes for unassigned pairs.
#   - assignment_mode ENUM('all_active','specific') DEFAULT 'all_active' exists on dl_products.
#
# CHAIR'S BROWSER BASELINE (credentials shiela_baina / shielab123, APP_URL=http://baronledger.test):
#   /daily-ledger/admin/products  -> HTTP 200, 182 tbody rows, shows "{n} branches", Active/Inactive badges
#   /daily-ledger/admin/branches  -> HTTP 200, 19 tbody rows, lists the commissary
# Those two surfaces MUST still render after this slice.

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

LANE_MODEL_CHAIN="deepseek-v4-flash,openai-codex/gpt-5.6-terra,openai-codex/gpt-5.6-sol"

PROMPT="$(cat <<'PROMPT_EOF'
You are implementing SLICE B (the picker) in the Ikabud repo at /var/www/html/applicationostest. PHP 8.5
local; production is MySQL 5.7 and no 5.7 server exists locally — label any 5.7 claim INSPECTION-ONLY.

READ FIRST (Slice A/A2 are shipped and verified — reuse, do not reinvent):
  .ai/dl-branch-product-visibility.contract.md   (approved contract + owner decisions + Option A amendment)
  modules/daily-ledger/handlers.php   dl_setBranchProductActive(), dl_setProductActive(),
                                      dl_branchProductUnassignmentBlockers(),
                                      dl_productOlderOpenDayWarnings(),
                                      apiCreateProduct() ~14812, apiUpdateProduct() ~14913,
                                      apiCreateBranch() ~15237, handleAdminProducts(), handleAdminBranches()
  modules/daily-ledger/routes.php     (add routes in the existing declarative style)
  templates/modules/daily-ledger/admin/products.disyl  (the edit-product modal lives here)
  templates/modules/daily-ledger/admin/branches.disyl
  tests/browser/daily-ledger-branch-product-visibility.spec.js  (the CHAIR's baseline spec — extend ONLY if
      you add picker assertions; do not delete the existing baseline assertions)

## B1 — the picker (the whole point: SIMPLE to use)
A per-branch product assignment surface: "a list of products then check boxes for admin to select as show in
branch". Requirements:
  - Reachable in at most 2 clicks from the daily-ledger admin area; the most natural home is the Branches page
    (assignment is per branch), e.g. a "Products" action per branch row. Your call, but the BRANCH being edited
    must always be visible on screen.
  - ONE branch selector so an admin can switch branches without navigating away.
  - A SEARCH box. 182 products must not require scrolling to find one — a picker that reintroduces scrolling
    defeats the owner's stated goal. Filter client-side on name/SKU; do not round-trip per keystroke.
  - A checklist of the branch's active products; checked = shown in that branch.
  - ONE explicit save. The save is ALL-OR-NOTHING: if any removal is refused, nothing is applied.
  - On refusal, name the exact blocking date+shift for each refused product (the guard returns them). Do not
    reduce a refusal to a count or a generic message.
  - Render dl_productOlderOpenDayWarnings() as INFORMATION (clearly not an error): older unfinished days the
    admin may want to look at later. It must never block the save.
  - Keep it lightweight: server-rendered page + a small amount of inline JS is preferred. If you must add a
    file under public/daily-ledger/assets/, you MUST bump CACHE_VERSION in public/daily-ledger/sw.js (the PWA
    precaches that directory cache-first); say so in your report.

## B2 — the product modal (owner decision 1)
`apiCreateProduct()` currently assigns every new product to ALL active branches (handlers.php:14869) and
ignores any input. Add the owner's option: "show in all active branches" (today's behaviour) OR specific
branches, persisted as `dl_products.assignment_mode` ('all_active' | 'specific').
  - A MISSING option MUST default to 'all_active' (backward compatible for the existing API callers/imports).
  - Apply it on CREATE and on UPDATE.
  - `apiUpdateProduct()` already accepts `is_active`; keep that and add the assignment option beside it.
  - LOADINESS IS A DEFECT: the edit modal currently receives only a branch count (products.disyl:129). It must
    be given the product's current `assignment_mode` AND its assigned branch IDs, so opening and saving an edit
    cannot silently rewrite assignments. Prove this.
  - `apiCreateBranch()` already assigns only 'all_active' products (Slice A) — do not change it.

## HARD CONSTRAINTS
- Do NOT change the two sheet LIST queries, the carry, the close-order path (67255d4b), handleAdminSales
  (32f2e431/7eda56e3), or dl_reportSalesData. No assignment filter may be added to the admin Sales view: its
  UNION must keep showing any pair holding a ledger row so recorded money can never vanish.
- Do NOT change the guard's semantics or the Option A window, the row predicates, or the migration.
- Do NOT build the Slice C surfaces (production dispatch destination cells, delivery picker, usage page,
  generic production-products API, trace/report dropdowns) or the Slice D CSV import. Out of scope.
- Do NOT touch public/daily-ledger/assets/ or sw.js unless you bump CACHE_VERSION (see B1).
- The existing pages must keep working: /daily-ledger/admin/products (182 rows) and /daily-ledger/admin/branches
  (19 rows) must still render.
- MySQL 5.7-safe SQL: no CTE, no window function, no JSON_TABLE, no LIMIT inside IN (subquery).
- Keep storage/logs/error.log empty.

## ORACLE — required
Extend/add tests under tests/daily-ledger/ (TestHarness pattern; private fixture branches, never real branch 8):
  P1. assigning a product to a branch creates/activates the pair and the branch's sheet list then includes it.
  P2. unassigning it removes it from that branch's sheet list and leaves other branches untouched.
  P3. a bulk save containing ONE refused removal applies NOTHING (all-or-nothing) — prove the other intended
      changes did not land.
  P4. a refusal names the blocking date+shift (not a bare count).
  P5. the older-open-day warning is returned and is NOT treated as a refusal (a product with ONLY older
      unfinished rows is still unassignable).
  P6. create with mode 'all_active' assigns all active branches; create with 'specific' assigns exactly the
      given branches; a MISSING mode behaves as 'all_active'.
  P7. update: changing only the price/active flag does NOT alter assignments (the lossiness guard).
  P8. a later-created branch does NOT receive 'specific' products (apiCreateBranch, already covered by A2's
      G10 — reference it rather than duplicating, and say so).
State which cases FAIL on the base tree (commit c59f4132). Do not claim discrimination you did not observe.

## BROWSER VERIFICATION (targeted ONLY — the full suite times out and is not your job)
Extend tests/browser/daily-ledger-branch-product-visibility.spec.js with picker assertions, then run ONLY it:
    APP_URL=http://baronledger.test npx playwright test \
        tests/browser/daily-ledger-branch-product-visibility.spec.js --reporter=line --retries=0
Login is shiela_baina / shielab123. Assert: the picker opens, the product list renders with checkboxes, the
search box filters, and the existing baseline assertions still pass. DO NOT run the whole browser suite.
IMPORTANT: the browser run must NOT mutate the real tenant's assignments. If your spec needs to toggle a
checkbox, either reload without saving, or tolerate that the assertion is read-only. A test that changes real
assignments is a defect, not a test.

## ACCEPTANCE — run and report verbatim
    php tests/daily-ledger/daily_ledger_branch_product_visibility_test.php
    php tests/daily-ledger/daily_ledger_close_day_and_notify_test.php
    php tests/daily-ledger/daily_ledger_admin_sales_full_sheet_test.php
    php tests/daily-ledger/<your new test(s)>.php
    php -l <each changed php file>
    git --no-pager diff --stat
    php /tmp/chair-verify-slice-a2.php        (must still AGREE and print 0 blocked)
    APP_URL=http://baronledger.test npx playwright test tests/browser/daily-ledger-branch-product-visibility.spec.js --reporter=line --retries=0
Then: php scripts/run-tests.php --dir=tests/daily-ledger, separating PRE-EXISTING failures (known log/
notification pollution: daily_ledger_dispatch_enforcement_test, daily_ledger_preserve_cashier_variance_test,
flaky daily_ledger_delivery_record_authz_test) from new ones.

## Rules
- Smallest correct change. No refactor, no new dependency, no scope creep.
- Never weaken, skip, or delete an assertion to reach green.
- If a requirement cannot be met without changing a HARD CONSTRAINT, STOP and report BLOCKED with the reason.
- Report status PASS | PARTIAL | BLOCKED, files changed (incl. any CACHE_VERSION bump), tests added/changed,
  the exact browser output, and any claim you could not verify.
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/branch-visibility-B-picker
rc=$?
echo "lane: branch-visibility-B-picker — completed by: ${LANE_MODEL_USED:-<none>} (rc=$rc)"
exit "$rc"
