# Multistore Storefront & Pricing — Post-Commit Findings

**Commit:** `5383937` — "Fix multistore storefront context and pricing"  
**Date:** 2026-04-14  
**Scope:** 14 files across CMS handlers, ecommerce helpers/handlers, templates  
**Test baseline:** 121 tests — 110 passed, 11 failed — logs clean

---

## Architecture Compliance

**Verdict: PASS — no kernel violations.**

All 14 changed files comply with:

- Module boundary enforcement (`owns_tables` / `reads_tables`)
- Handler dispatch via `module-id:functionName`
- Cross-module calls guarded with `function_exists()` (CMS→ecommerce)
- Database access through `ecDb()` (ModuleDB), not raw `app()->db()`
- Cross-module writes via `moduleWithContext()` escalation
- Settings access through `ecSettings()` / `readTenantModuleSettings()`
- No DDL from modules, no tenant isolation breaches

Two acceptable `app()->db()` uses were found and documented:
1. `information_schema` checks in `helpers/30-catalog.php` (schema metadata, not tenant data)
2. `rate_limits` kernel table in `handlers/86-api-checkout.php` (kernel infrastructure)

---

## Regression Failures (from commit 5383937)

### R1. Cart currency resolution overrides storefront selection — CRITICAL

**Test:** `ecommerce_multi_currency_test` (5 of 9 assertions fail)  
**File:** `modules/ecommerce/helpers/10-cart.php` — `ecCartResolveTargetCurrencyForProduct()`

**Problem:** New store-level currency fallback resolves to the store's default currency instead of honoring the customer's selected storefront currency. Cart stays USD when customer selected EUR.

**Expected flow:** storefront session currency → store default → product base → global default  
**Actual flow:** store default → product base → global default (session currency skipped)

**Fix:** Check storefront session currency first in `ecCartResolveTargetCurrencyForProduct()`, fall back to store currency only when no explicit selection exists.

**Verify:** All 9 assertions in `ecommerce_multi_currency_test` pass.

---

### R2. Bundle children re-priced at individual rates — HIGH

**Test:** `ecommerce_bundle_products_test` (2 of 8 assertions fail)  
**File:** `modules/ecommerce/helpers/40-pricing.php` — `ecEnforceCurrentPrices()` / `ecAuthoritativeCartItemPricing()`

**Problem:** `ecAuthoritativeCartItemPricing()` re-fetches product price for every cart item, including bundle children. Bundle children should keep their parent-allocated price ($35 each), but they're re-priced at their standalone rate ($40), giving subtotal=$120 instead of $110.

**Fix:** Check for `bundle_parent_id` in the cart item. Skip authoritative re-pricing for bundle children — their price is owned by the bundle parent.

**Verify:** All 8 assertions in `ecommerce_bundle_products_test` pass.

---

### R3. Booking snapshot persistence broken — HIGH

**Test:** `ecommerce_phase6_features_test` (3 of 16 assertions fail)  
**Files:** `modules/ecommerce/helpers/10-cart.php` — `ecCartPrepareItem()`

**Problem:** The new store-override logic in `ecCartPrepareItem()` runs after `ecCartPrepareExtendedItemData()` sets booking data. Store overrides overwrite or reset `options_json`, causing `snapshot_json` to be null in `ec_order_items`. Cascading effect: `has_booking: false`, booking badges missing on order listings.

**Failing assertions:**
- Pending appointments before payment → empty
- Line item `snapshot_json` → null
- Customer order listing `has_bookings` badge → false

**Fix:** Preserve booking/add-on data set by `ecCartPrepareExtendedItemData()` after store override application. Store overrides should only modify price fields, not overwrite snapshot or options data.

**Verify:** All 16 assertions in `ecommerce_phase6_features_test` pass.

---

### R4. Tax-inclusive back-calculation uses wrong baseline — HIGH

**Test:** `ecommerce_tax_engine_test` (1 of 9 assertions fail)  
**File:** `modules/ecommerce/helpers/40-pricing.php`

**Problem:** Store pricing overrides applied before inclusive-tax back-calculation change the baseline price. Test sets price=€112 inclusive at 12% tax, expects tax=€12.00 (112 ÷ 1.12 × 0.12). Actual: tax=€10.71 — consistent with a baseline of €100 instead of €112.

**Fix:** Ensure the tax engine receives the final customer-facing price (after all overrides) as the inclusive baseline. Either apply store overrides at cart-add time so they're already reflected in `price_snapshot`, or ensure `ecCalculateTotals()` uses `price_snapshot` (which should already include overrides) rather than re-fetching.

**Verify:** All 9 assertions in `ecommerce_tax_engine_test` pass.

---

### R5. Recently viewed template text mismatch — LOW

**Test:** `ecommerce_recently_viewed_test` (1 of 9 assertions fail)  
**File:** `templates/modules/cms/public/blocks/ecommerce-recently-viewed.block.disyl`

**Problem:** Test expects the heading text "Jump back into products you looked at during this session." but current template uses different copy.

**Fix:** Update test assertion to match current template text, or restore original copy if the change was unintentional.

**Verify:** All 9 assertions in `ecommerce_recently_viewed_test` pass.

---

### R6. Multi-store active detection under single store — MEDIUM

**Test:** `ecommerce_multistore_membership_loyalty_test` (1 of 38 assertions fail)

**Problem:** `ecStoreIsMultiStoreActive()` expected to return `false` with only one store, but test environment may have accumulated extra stores from prior test runs or migration seeds.

**Fix:** Investigate whether test setup or the default store migration seed creates additional active stores. Ensure `ecStoreIsMultiStoreActive()` correctly counts only tenant-scoped active stores.

**Verify:** All 38 assertions in `ecommerce_multistore_membership_loyalty_test` pass.

---

## Pre-existing Failures (not from this commit)

### P1. WMS payment collection → ecommerce sync — MEDIUM

**Test:** `integration_bridge_ecommerce_wms_test` (3 of 49 assertions fail)

`wmsOrderCollectPayment()` does not propagate payment status back to the ecommerce order. After WMS collects pay-on-delivery, `ec_orders.payment_status` stays `'pending'` instead of `'paid'`, and payment transaction stays `'pending'` instead of `'succeeded'`.

---

### P2. WMS low-stock report hydration — MEDIUM

**Test:** `ecommerce_wms_inventory_authority_test` (1 of 19 assertions fail)

`ecReportInventory()` does not overlay WMS-authoritative stock into the low-stock report. Query returns null for the product row when WMS integration mode is active.

---

### P3. WordPress bridge response contract — MEDIUM

**Test:** `wordpress_bridge_ingestion_test` (3 of 43 assertions fail)

`wpBridgeHandleContentUpserted()` omits `action`, `outcome`, and `reason` keys in skip/stale return paths. All return paths should include these keys for contract consistency.

---

### P4. WMS migration count — LOW

**Test:** `wms_module_test` (1 of 22 assertions fail)

Test hardcodes `=== 20` migrations but manifest now declares 22 (migrations 021 and 022 added in a prior phase). Update assertion.

---

### P5. CLI capability test — LOW

**Test:** `infrastructure_test` (1 of 88 assertions fail)

`php ikabud capability:test kernel.auth.authenticate@1 --with-modules` exits non-zero. Likely a module loading or capability registry issue during CLI bootstrap. May cascade from manifest inconsistencies.

---

## Convention Gaps Identified

### G1. No bundle-aware pricing guard

`ecEnforceCurrentPrices()` has no concept of "this item's price was set by a bundle parent." Bundle pricing semantics need an explicit contract: items with `bundle_parent_id` must not be re-priced by authoritative pricing.

**Action:** Add a `bundle_parent_id` check as a skip condition in `ecAuthoritativeCartItemPricing()`. Document in the ecommerce pricing conventions.

---

### G2. Currency resolution priority undocumented

The fallback order for cart item currency (storefront session → store default → product base → global default) is not documented anywhere. This caused R1 because store default was given priority over session selection.

**Action:** Document the canonical currency resolution chain. Add an inline comment in `ecCartResolveTargetCurrencyForProduct()` and a section in `docs/ecommerce/`.

---

### G3. Cart prepare phase ordering is fragile

`ecCartPrepareItem()` applies transformations sequentially (store override → booking validation → snapshot → pricing). Later steps can silently overwrite data from earlier steps (R3: store overrides clobbering booking data).

**Action:** Formalize cart prepare as distinct phases:
1. Product fetch + store override (price fields only)
2. Extended data (booking, add-ons, options)
3. Snapshot persistence
4. Price enforcement

Ensure each phase operates on designated fields without cross-contamination.

---

### G4. Test fixtures assume store-unaware products

Most test fixtures create products without store assignments (`store_id = null`). The new multi-store code must treat `store_id = null` as the default/fallback path, not as an edge case requiring special handling. Several regressions stem from null store_id products hitting store-resolution code paths that expect a valid store.

**Action:** Ensure all store-resolution functions (`ecCartResolvedStoreId()`, `ecCartResolveTargetCurrencyForProduct()`, `ecStoreApplyProductOverrides()`) have clean null/absent store_id fallbacks. Consider adding a dedicated "no-store" integration test.

---

## Implementation Order

| Priority | Item | Est. Assertions Fixed |
|----------|------|-----------------------|
| 1 | R1 — Currency resolution | 5 |
| 2 | R2 — Bundle pricing guard | 2 |
| 3 | R3 — Booking snapshot preservation | 3 |
| 4 | R4 — Tax-inclusive baseline | 1 |
| 5 | R6 — Multi-store active detection | 1 |
| 6 | R5 — Recently viewed text | 1 |
| 7 | P4 — WMS migration count | 1 |
| 8 | P3 — WordPress bridge contract | 3 |
| 9 | P1 — WMS payment sync | 3 |
| 10 | P2 — WMS low-stock hydration | 1 |
| 11 | P5 — CLI capability test | 1 |

**Total:** 22 failing assertions across 11 tests → target 0 failures.

---

*Reviewed by: [pending]*  
*Approved for implementation: [pending]*
