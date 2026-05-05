# Daily Ledger — Inventory Spec (Technical Reference)

This document describes the schema, formulas, feature flags, audit events, and HTTP endpoints for the Phase A-F inventory model plus the later cashier-dispatch and paper-DR review workflows.

## 1. Feature Flag Matrix
All flags live under `tenant_module_settings` for `module_id = 'daily-ledger'`. Defaults are declared in `modules/daily-ledger/module.json` → `settings_fields`.

| Setting key | Default | Purpose |
| --- | --- | --- |
| `formal_delivery_workflow_enabled` | `"0"` | Toggles the formal delivery + receiving workflow. When ON, branch stock moves through deliveries, receivings, cashier dispatch, and paper DR capture instead of the old delivery-in-withdrawal shortcut. |
| `selling_accounts_enabled` | `"0"` | Exposes Phase D selling-account management UI and APIs. Existing data is unaffected when OFF. |
| `price_groups_enabled` | `"1"` | Master switch for Phase C price groups. ON by default because resolution falls back to `current_price`. |

Helpers: `dl_isFormalDeliveryEnabled()`, `dl_areSellingAccountsEnabled()`, `dl_arePriceGroupsEnabled()`.

## 2. Schema Changes
Migrations `022`–`033` under `modules/daily-ledger/database/migrations/`.

| Table | Purpose |
| --- | --- |
| `dl_branches` (altered) | New columns `default_supply_mode`, `assigned_commissary_id`, `is_commissary`. |
| `dl_branch_product_supply_rules` | Per-product supply override (`product_id`, `branch_id`, `supply_mode`, `commissary_branch_id`). |
| `dl_deliveries` | Header for a commissary→branch delivery (status: `draft`/`posted`/`voided`). |
| `dl_delivery_items` | Line items with `quantity`, `unit`, `price_snapshot`. |
| `dl_branch_receivings` | Header for branch acceptance of a delivery. |
| `dl_branch_receiving_items` | Line items with `quantity_received` and `selling_price_snapshot`. |
| `dl_delivery_variance_flags` | Auto-recorded variance rows when received ≠ sent. |
| `dl_price_groups` | Named price tiers (`Default`, `Mall Kiosk`, etc.). |
| `dl_product_prices` | (`product_id` × `price_group_id`) with effective_from/effective_to and `selling_price`. |
| `dl_branch_price_groups` | Maps a branch (or selling account) to a price group. |
| `dl_selling_accounts` | Sub-ledgers attached to a branch (consignment, kiosk). |
| `dl_selling_account_daily_ledger` | Per-day ledger row for a selling account. |
| `dl_selling_account_day_status` | `open`/`closed` status per (account × date). |

Additional column-level changes used by the current workflow:

| Table | Added columns | Purpose |
| --- | --- | --- |
| `dl_production_runs` | `dr_number` | Ties branch-directed production runs to a delivery receipt. |
| `dl_cashier_withdrawals` | `reason_code`, `received_qty` | Keeps adjustments classified and stores receiving-related quantity metadata from the migration wave. |
| `dl_deliveries` | `provenance_status`, `provenance_reviewed_by`, `provenance_reviewed_at`, `provenance_review_note` | Tracks paper DR exception review state. |

## 3. Formulas
### Branch ledger (regular, per product per day)
```
sales       = beg_bal + addtl − withdraw − bal_end          (units)
sales_value = sales × price_snapshot                         (currency)
```
Implemented in `dl_recomputeSales()` and surfaced via `dl_branchConsolidatedSummary()`.

### Selling account ledger (per product per day)
```
sold_qty     = beg_qty + delivered_qty − return_qty − end_qty
gross_amount = sold_qty × price_snapshot
```
Implemented in `dl_postDeliveryToSellingAccount()` (delivered) and `dl_recomputeSellingAccountLedger()` (sold/gross).

### Branch consolidated summary
```
regular_sales          = Σ (sales × price_snapshot)            from dl_daily_ledger
selling_accounts_total = Σ gross_amount                         from dl_selling_account_daily_ledger
total                  = regular_sales + selling_accounts_total
```
Returned as `{ regular_sales, selling_accounts_total, total, selling_accounts: [...] }`.

### Variance flag
```
variance = received_qty − sent_qty   (negative ⇒ short delivery)
```
Recorded once per (`delivery_id`, `product_id`) by `dl_recordReceivingVariances()`.

## 4. Resolution Helpers
| Helper | Returns |
| --- | --- |
| `dl_resolveProductSupplySource(int $branchId, int $productId)` | `['supply_mode' => …, 'commissary_branch_id' => …]` honoring per-product override → branch default. |
| `dl_defaultPriceGroupId()` | Lowest-id active price group, or `null`. |
| `dl_resolveProductPrice(int $productId, ?int $priceGroupId, ?string $atDate)` | Effective `selling_price` (price group window) or `current_price` fallback. |
| `dl_branchConsolidatedSummary(int $branchId, string $date)` | Consolidated totals for the branch. |
| `dl_isPaperDrCapturedDelivery(array $delivery)` | Detects deliveries that were created from a branch-side paper DR exception path. |
| `dl_findPaperCapturedCommissaryDelivery(...)` | Reuses an already-captured paper DR delivery instead of duplicating it during later commissary sync. |

## 5. Audit Events
Emitted via the standard module audit channel. Each entry stores `actor_id`, `subject_id`, and a JSON payload of the change.

| Event | When |
| --- | --- |
| `branch_supply_mode_changed` | Admin edits a branch's `default_supply_mode` or `assigned_commissary_id`. |
| `product_supply_source_changed` | Per-product supply override created/updated/deleted. |
| `create_delivery` | Cashier dispatch or paper DR capture creates an already-posted delivery. |
| `delivery_created` / `delivery_posted` / `delivery_voided` | Lifecycle of `dl_deliveries`. |
| `create_receiving` | Branch-side receive flow posts the receiving directly from the cashier surface. |
| `receiving_created` / `receiving_posted` / `receiving_voided` | Lifecycle of `dl_branch_receivings`. |
| `review_delivery_provenance` | Admin, supervisor, or Production In-charge reviews a paper DR exception. |
| `price_group_created` / `price_group_changed` | Price group CRUD. |
| `product_price_changed` | Insert/update of a `dl_product_prices` row. |
| `selling_account_created` / `selling_account_changed` | Selling account CRUD. |
| `selling_account_ledger_update` | Any recompute of a selling-account day. |
| `selling_account_day_closed` / `selling_account_day_reopened` | Per-day open/close transitions. |
| `cashier_withdrawal_reason_set` | Withdrawal saved with a non-empty `reason_code`. |

## 6. HTTP API (under `/daily-ledger/api/v1/`)
All routes require an authenticated daily-ledger user; superadmin bypass applies as elsewhere in the kernel.

### Reads (GET)
* `cashier/ledger/incoming-deliveries?branch_id=&date=` — lists posted deliveries that the branch can receive.
* `branches/{id}/supply` — resolved supply source for the branch's products.
* `branches/{id}/consolidated-summary?date=YYYY-MM-DD` — combined ledger + selling-account totals.
* `deliveries?destination_id=&destination_type=&status=&provenance_status=` — list deliveries. `status=received` is derived from active receivings, not only from the raw delivery row.
* `deliveries/{id}` — delivery detail with items + receiving status.
* `receivings/{id}` — receiving detail with variance rows.
* `price-groups` — list groups + product price overrides.
* `selling-accounts?branch_id=` — list selling accounts.
* `selling-accounts/{id}/ledger?date=` — day rows for an account.

### Writes (POST)
* `cashier/ledger/dispatch` — creates a posted branch-to-branch delivery from the cashier page.
* `cashier/ledger/receive-delivery` — receives an existing delivery into branch stock.
* `cashier/ledger/receive-paper-dr` — captures a missing delivery from paper DR and receives it in one action.
* `deliveries/create` / `deliveries/{id}/post` / `deliveries/{id}/void`
* `deliveries/review-provenance` — review a paper DR exception (`accepted`, `discrepant`, `reopen`). Production In-charge is limited to `accepted`.
* `receivings/create` / `receivings/{id}/post` / `receivings/{id}/void`
* `price-groups/create` / `price-groups/{id}/update`
* `product-prices/upsert`
* `branch-price-groups/upsert`
* `selling-accounts/create` / `selling-accounts/{id}/update`
* `selling-accounts/{id}/ledger/upsert`
* `selling-accounts/{id}/day/close` / `selling-accounts/{id}/day/reopen`

Handler implementations live in `modules/daily-ledger/handlers-deliveries.php`; route map in `modules/daily-ledger/routes.php`.

## 7. Test Coverage
* `tests/daily_ledger_inventory_spec_test.php` — end-to-end Phase A–F integration test against the live `applicationostest` DB (tenant `baronledger.test`). Covers supply resolution, price-group fallback, selling-account formula, delivery → receiving → variance flow, consolidated summary, cashier reason code, and feature-flag defaults.
* `tests/manifest_settings_defaults_test.php` — verifies the three new settings keys are declared with their documented defaults.
* `tests/daily_ledger_full_process_test.php` — regression coverage for the live daily-ledger operational flow, including delivery/receiving behavior that now depends on dedicated dispatch and paper DR exception handling.

## 8. Migration Notes
* Migrations are idempotent (`IF NOT EXISTS` guards). Re-running `./ikabud migrate daily-ledger` is safe.
* On tenants where daily-ledger is **not** entitled (e.g., the `applicationos` CMS tenant), the per-tenant migration step is skipped with a "Module not in tenant plan" message — apply migrations to the host DB instead via `./ikabud migrate daily-ledger`.
* The kernel `ModuleDB::extractTables()` parser was hardened in this wave to use `\bFROM\b` so columns ending in `_from` (e.g. `effective_from DESC`) are no longer mis-parsed as a `FROM` clause.
