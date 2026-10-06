#!/usr/bin/env bash
#
# Lane: review-branch-product-visibility
#
# OWNER REQUEST (2026-10-06, verbatim):
#   "we can do per branch too. but the UI must be simple to use. a list of products then check boxes for
#    admin to select as show in branch. therefore, for a commissary, since it's treated as a branch, admin
#    can select products it shows. when adding products, per setup of the product modal, admin can also set
#    if shows in all branches or just a specific branch like the commissary. your decision. simple feature"
#
# REVIEW LANE — nothing is implemented. Sol owns architecture/review; the chair already did recon and wrote
# .ai/dl-branch-product-visibility.contract.md (v2). The value here is finding what the chair MISSED.
#
# NOTE: an earlier run of this lane reviewed a SUPERSEDED design (a new 4-state enum column on dl_products).
# It was stopped before producing findings. This brief is the corrected premise; if you find any text
# elsewhere in the repo still describing the enum-on-dl_products design, it is withdrawn — say so.

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

LANE_MODEL_CHAIN="openai-codex/gpt-5.6-sol,openai-codex/gpt-5.6-terra,deepseek-v4-flash"

PROMPT="$(cat <<'PROMPT_EOF'
You are reviewing a DESIGN for the daily-ledger module in the Ikabud repo at
/var/www/html/applicationostest (PHP 8.5 local; production is MySQL 5.7 on shared hosting and no 5.7
server exists locally, so any 5.7 claim is INSPECTION-ONLY and must be labelled as such).

READ FIRST:
  1. .ai/dl-branch-product-visibility.contract.md   <- the design under review (v2)
  2. The code it asserts about, and verify every assertion:
       dl_fetchCashierLedgerRows()        handlers.php ~6421
       dl_fetchProductionSheetProducts()  handlers.php ~715
       dl_fetchActiveProductsForProduction() handlers.php ~663 (cached 300s, tag dl_products)
       dl_shiftMissingEndings()           handlers.php ~4994
       dl_autoCarryBeginnings()           handlers.php ~6336
       apiCreateProduct()                 handlers.php ~14812 (assigns to ALL branches ~14869)
       apiUpdateProduct()                 handlers.php ~14913
       apiCreateBranch()                  handlers.php ~15237 (assigns ALL products)
       the products list branch_count subquery  handlers.php ~14296 and ~15913
       handleAdminSales()                 handlers.php ~10800 (derived-UNION driving set)
  3. templates/modules/daily-ledger/admin/products.disyl, branches.disyl, commissary.disyl
  4. modules/daily-ledger/helpers/ and modules/daily-ledger/handlers-offline.php

## The design in one line
Per-branch visibility ALREADY EXISTS as `dl_branch_products.is_active` for a (branch, product) pair, and both
sheets already filter on it, each scoped to its own branch. Production reads the COMMISSARY branch's
assignments; a cashier ledger reads its STORE branch's. So the feature is a UI/UX feature over existing data:
  D1 a per-branch product checkbox picker, and
  D2 a product-modal option "all active branches" (today's behaviour) vs specific branches.
NO new column is proposed. The owner's four states (production only / both / cashier only / neither) are
expressed by which branches a product is assigned to.

## Your job — be comprehensive, and falsify
A. TEST THE CENTRAL CLAIM: "zero query changes to the two sheets". Is it TRUE? Enumerate EVERY code path that
   enumerates products for a sheet, a dropdown, a print, an export, a cron/CLI job, the POS path, the offline
   bootstrap payload, or another module, and state for each whether it filters on `bp.is_active` and scopes to
   the right branch. List every path that does NOT, with file:line. A single unfiltered path means a product
   unassigned from a branch still shows up somewhere, which breaks the feature's promise.
B. R1 — THE GUARD. Unchecking a product for a branch silently (1) stops its activity-bearing, ending-less row
   from blocking PM finalize (because dl_shiftMissingEndings() INNER JOINs bp.is_active = 1) and (2) removes
   that row from the branch's sheet so no operator can complete it — a permanent silent hole. Specify the
   smallest correct guard: what exactly is refused or protected, scoped to which dates, with the precise
   admin-facing message, and the assertion that pins it. Also test the guard's own failure modes: a false
   refusal that blocks a legitimate unassignment (e.g. a long-discontinued product) is as bad as no guard.
C. Verify the FOUR STATES really are expressible, including the awkward ones: a product assigned to a store
   branch but NOT its commissary; a product unassigned from every branch; a product assigned to a commissary
   that a store's cashier ledger reads. State what happens to `branch_count` display and to the products page.
D. Verify the NO-BREAK DEFAULT is real: every existing pair is is_active=1 and every product is on every
   branch, so shipping this must change NOTHING observable until an admin acts. Say exactly how that would be
   MEASURED (which counts/totals/renderings must be identical before and after), not just asserted.
E. The product modal (D2): `apiCreateProduct()` currently assigns to all active branches and ignores input,
   and `apiUpdateProduct()` needs the same capability. Decide what "all branches" means for a branch created
   LATER (today apiCreateBranch() assigns all products, so a future branch gets everything) and whether
   selecting "specific branches" should be stored as intent or just as the resulting rows. Give the smallest
   data + API shape. Flag anything that makes re-editing a product lossy.
F. Cache and consistency: what must be invalidated when an assignment changes; is anything cached beyond the
   known 300s `dl_products` tag; and what an offline device already holding a product list will do after an
   assignment changes server-side.
G. Anything the owner will expect that this design does NOT deliver, and anything in the design that is more
   machinery than a "simple feature" needs.

## Rules
- Read-only. Do NOT modify, create, or delete any file. Do NOT implement.
- Label EVERY finding (a) MEASURED, (b) REASONED, or (c) COULD NOT CHECK. Never present a reasoned claim as
  measured. Cite file:line for every code claim.
- If you cannot find a consumer you suspected, say so plainly rather than implying you checked.
- Prefer precision to volume. Do not restate the contract back to me.

## Report format
  VERDICT: SOUND | SOUND_WITH_CHANGES | UNSOUND
  CENTRAL CLAIM (zero query changes): TRUE / FALSE + every offending path with file:line
  R1 GUARD: the smallest correct design + the assertion + its own false-refusal risk
  FOUR STATES: expressible? exceptions found
  NO-BREAK DEFAULT: how measured
  PRODUCT MODAL (D2): smallest data + API shape, and any lossiness
  CACHE / OFFLINE: required invalidations and the stale-device case
  MISSED BY THE DESIGN: list
  BLOCKERS: what must be decided by the owner before implementation
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/review-branch-product-visibility
rc=$?
echo "lane: review-branch-product-visibility — completed by: ${LANE_MODEL_USED:-<none>} (rc=$rc)"
exit "$rc"
