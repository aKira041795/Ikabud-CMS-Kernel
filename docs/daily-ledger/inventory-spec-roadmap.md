# Daily Ledger — Inventory Spec Roadmap

Tracks the multi-phase delivery of the inventory-spec wave (module version `1.1.0`). Updated 2026-05-03.

## Status legend
- ✅ Complete & verified
- 🟡 Partially complete
- ⬜ Not started
- 🔁 Deferred / out of current scope

---

## Phase Overview

| Phase | Theme | Status |
| --- | --- | --- |
| A | Branch supply mode + per-product overrides | ✅ |
| B | Formal deliveries + branch receivings | ✅ |
| C | Cashier withdrawal reason codes | ✅ |
| D | Price groups + product price resolution | ✅ |
| E | Selling accounts + per-day ledger | ✅ |
| F | Consolidated branch summary + variance flags | ✅ |
| G | Manifest, docs, tests, audit hooks | ✅ |
| H (future) | Disyl admin UI for new domains | ⬜ |
| I (future) | Supervisor variance dashboard polish | ⬜ |
| J (future) | Android app surfacing of selling accounts | ⬜ |
| K | Android withdrawal reason codes UI | ✅ |

---

## Phase A — Branch Supply Mode ✅
**Goal:** declare how each branch sources stock and let individual products override the branch default.

Delivered:
- Migration `022_branch_supply_mode.sql` adds `default_supply_mode`, `assigned_commissary_id`, `is_commissary` to `dl_branches`.
- Migration `023_branch_product_supply_rules.sql` creates `dl_branch_product_supply_rules`.
- Helper `dl_resolveProductSupplySource(branchId, productId)` (override → branch default → null).
- `apiCreateBranch` / `apiUpdateBranch` accept and audit the new fields (`branch_supply_mode_changed`, `product_supply_source_changed`).

Verified by: `tests/daily_ledger_inventory_spec_test.php` cases _branch default maps to commissary_, _per-product override beats branch default_.

## Phase B — Formal Deliveries + Receivings ✅
**Goal:** two-step paper trail (commissary delivery → branch receiving) feature-flagged behind `formal_delivery_workflow_enabled`.

Delivered:
- Migration `024_deliveries_and_receivings.sql` (`dl_deliveries`, `dl_delivery_items`, `dl_branch_receivings`, `dl_branch_receiving_items`, `dl_delivery_variance_flags`).
- Handlers in `modules/daily-ledger/handlers-deliveries.php` covering create/post/void for both sides.
- `dl_recordReceivingVariances()` writes one row per (delivery, product) when received ≠ sent.
- Posting a receiving feeds `dl_daily_ledger.addtl` via `dl_applyLedgerDelta()`; voids reverse cleanly.
- Audit events: `delivery_created/posted/voided`, `receiving_created/posted/voided`.

Verified by: integration test cases _variance flag recorded for short delivery_, _branch ledger addtl reflects received qty (95)_.

## Phase C — Withdrawal Reason Codes ✅
**Goal:** classify cashier withdrawals so supervisors can triage variances faster.

Delivered:
- Migration `025_cashier_withdrawal_reason.sql` adds `reason_code` to `dl_cashier_withdrawals`.
- `apiSaveCashierWithdrawals` validates reason against the allowed enum (`spoilage`, `staff_meal`, `sampling`, `testing`, `promo`, `donation`, `damage`, `manual_adjustment`, `other`) and audits via `cashier_withdrawal_reason_set`.

Verified by: integration test case _cashier withdrawal persists reason_code_.

## Phase D — Price Groups + Product Prices ✅
**Goal:** support tiered pricing (default, mall kiosk, wholesale, etc.) with effective windows.

Delivered:
- Migration `026_price_groups.sql` (`dl_price_groups`, `dl_product_prices`, `dl_branch_price_groups`).
- Helpers `dl_defaultPriceGroupId()` and `dl_resolveProductPrice()` (effective window → `current_price` fallback).
- CRUD handlers + audit events `price_group_created/changed`, `product_price_changed`.
- Master flag `price_groups_enabled` (default ON; resolution always falls back safely).

Verified by: integration test cases _default group resolves to current_price snapshot_, _mall group resolves to channel price_, _unmapped product falls back to current_price_.

Root-cause fix shipped during this phase:
- `kernel/Contracts/ModuleDB.php::extractTables()` regex hardened to `\b(?:FROM|JOIN)` so columns ending in `_from` (e.g. `ORDER BY effective_from DESC`) are not parsed as a FROM clause.

## Phase E — Selling Accounts ✅
**Goal:** sub-ledgers under a branch (consignment carts, kiosks, resellers), feature-flagged behind `selling_accounts_enabled` (default OFF).

Delivered:
- Migrations `027_selling_accounts.sql`, `028_selling_account_ledger.sql`, `029_selling_account_day_status.sql`.
- Formula: `sold_qty = beg_qty + delivered_qty − return_qty − end_qty`; `gross_amount = sold_qty × price_snapshot`.
- Helpers: `dl_postDeliveryToSellingAccount()`, `dl_recomputeSellingAccountLedger()`, day open/close.
- Audit events: `selling_account_created/changed`, `selling_account_ledger_update`, `selling_account_day_closed/reopened`.

Verified by: integration test cases _selling account created_, _delivery applied to selling-account ledger_, _initial sold_qty = 100_, _initial gross = 7500_, _end_qty=30 recomputes sold_qty to 70_, _gross drops to 5250_.

Root-cause fix shipped during this phase:
- `dl_resolveProductPrice()` rewrote duplicate `:d` named placeholders to `:d1` / `:d2` because KernelPDO runs with emulation off (HY093 otherwise).

## Phase F — Consolidated Summary + Variance Surfacing ✅
**Goal:** one card combining regular ledger + selling-account totals, plus delivery variance breadcrumbs for supervisors.

Delivered:
- `dl_branchConsolidatedSummary($branchId, $date)` returning `{regular_sales, selling_accounts_total, total, selling_accounts: [...]}`.
- Variance flags surfaced via `GET /deliveries/{id}` and `GET /receivings/{id}`.

Verified by: integration test cases _summary regular_sales = 4750_, _summary selling_accounts_total = 5250.00_, _summary lists the test selling account_.

## Phase G — Manifest, Docs, Tests, Audit Hooks ✅
**Goal:** declare ownership, ship docs/tests, wire all audit hooks.

Delivered:
- `modules/daily-ledger/module.json` bumped to `1.1.0`; 11 new `owns_tables`; 8 new migrations registered; 3 new `settings_fields` (`formal_delivery_workflow_enabled` "0", `selling_accounts_enabled` "0", `price_groups_enabled` "1").
- All audit events (Phases A–F) integrated through the standard module audit channel.
- Test [tests/daily_ledger_inventory_spec_test.php](tests/daily_ledger_inventory_spec_test.php) — **24 pass / 0 fail**, error.log empty.
- Manifest defaults test [tests/manifest_settings_defaults_test.php](tests/manifest_settings_defaults_test.php) — **34 pass / 0 fail**.
- Docs:
  - User-facing sections 5–10 added in [docs/daily-ledger/user-guide.md](docs/daily-ledger/user-guide.md).
  - Technical reference [docs/daily-ledger/inventory-spec.md](docs/daily-ledger/inventory-spec.md).
  - This roadmap.
- Repo memory `/memories/repo/daily-ledger-inventory-spec-2026-05.md` records tenant choice (`baronledger.test`), `modulePushContext` requirement for test scripts, kernel regex fix, and PDO duplicate-placeholder gotcha.

---

## Out-of-Scope / Deferred 🔁

- **Disyl admin UI.** New routes and handlers exist; corresponding admin templates for price-groups, selling-accounts, and the consolidated summary card are deferred to Phase H. Until then admins can use the JSON APIs directly.
- **Supervisor dashboard polish.** Variance flags can be queried through `GET /deliveries/{id}` and `GET /receivings/{id}`. A dedicated dashboard view is Phase I.
- **Android surfacing.** Mobile cashier app continues to operate against legacy ledger endpoints; selling-account sync is Phase J.

---

## Evolution Notes

- **Original plan** treated the inventory spec as a single sprint. It was split into Phases A–G to ship incrementally and let each migration land + be exercised before the next was layered on.
- Two issues that surfaced during Phase G were fixed at the root rather than worked around in handlers:
  1. `ModuleDB::extractTables()` mis-parsed `effective_from DESC` as a FROM clause → fixed kernel-side with a `\b` boundary.
  2. KernelPDO (emulation off) rejected duplicate named placeholders → query rewritten to use distinct names.
- Tenant selection for tests moved from `cmsnew.test` (CMS-only, not entitled to daily-ledger) to `baronledger.test` (tenant `baron-001`, daily-ledger entitled), avoiding spurious schema-missing errors.
