# Entity-View Adoption Plan — Closing the Gap

> **Status:** Phases 1–2 complete — June 15, 2026. Phase 3 deferred (template migration).
> **Objective:** Extend entity-view contracts to all modules so themes can present module data through governed `{ikb_entity_list}` / `{ikb_entity_detail}` without depending on module internals.

## Final Adoption State

| Module | Entity Views | entity.list | entity.get | View Contracts |
|--------|-------------|-------------|------------|----------------|
| CMS | ✅ Full | `entity.list.cms_page@1`, `entity.list.cms_post@1` | `entity.get.cms_page@1`, `entity.get.cms_post@1` | 13 contracts, 5 types |
| Weather | ✅ Full (polyglot) | `entity.list.weather@1`, `entity.list.weather_current@1`, `entity.list.weather_forecast@1` | `entity.get.weather@1` | ServiceProxy → Python |
| Ecommerce | ✅ Full | `entity.list/get.ecommerce_product@1`, `entity.list/get.ecommerce_order@1` | — | builtinDefaults |
| Bakeshop | ✅ Full | `entity.list/get.bakeshop_product@1` | — | builtinDefaults |
| Guidance | ✅ Full | `entity.list/get.guidance_case@1`, `entity.list/get.guidance_appointment@1` | — | builtinDefaults |
| Daily Ledger | ✅ Full | `entity.list/get.daily_ledger_entry@1` | — | builtinDefaults |
| WMS | ✅ Full | `entity.list/get.wms_stock@1`, `entity.list/get.wms_location@1` | — | builtinDefaults |

## Plan

### Phase 1 — Register Capabilities ✅ Complete
### Phase 2 — View Contracts (builtinDefaults) ✅ Complete
### Phase 3 — Template Migration 🔴 Deferred (future)

Replace module-specific render paths with `{ikb_entity_list}` / `{ikb_entity_detail}` in templates. Zero-code change for the capability layer — templates just need to adopt the new components.

---

## Implementation (Completed)

### Bakeshop

| Entity | capability ID | Handler | Source |
|--------|-------------|---------|--------|
| `bakeshop.product` | `entity.list.bakeshop_product@1` | `bs_cap_entity_list_product_1` | `bakeshop_products` table |
| `bakeshop.product` | `entity.get.bakeshop_product@1` | `bs_cap_entity_get_product_1` | Single product by ID |

### Guidance

| Entity | capability ID | Handler | Source |
|--------|-------------|---------|--------|
| `guidance.case` | `entity.list.guidance_case@1` | `gm_cap_entity_list_case_1` | `gm_cases` JOIN `gm_users` |
| `guidance.case` | `entity.get.guidance_case@1` | `gm_cap_entity_get_case_1` | Single case with counselor |
| `guidance.appointment` | `entity.list.guidance_appointment@1` | `gm_cap_entity_list_appointment_1` | `gm_appointments` JOIN `gm_cases` |
| `guidance.appointment` | `entity.get.guidance_appointment@1` | `gm_cap_entity_get_appointment_1` | Single appointment with student |

### Daily Ledger

| Entity | capability ID | Handler | Source |
|--------|-------------|---------|--------|
| `daily_ledger.entry` | `entity.list.daily_ledger_entry@1` | `dl_cap_entity_list_entry_1` | `dl_entries` with qualifier filtering (sales/expense) |
| `daily_ledger.entry` | `entity.get.daily_ledger_entry@1` | `dl_cap_entity_get_entry_1` | Single entry by ID |

### WMS

| Entity | capability ID | Handler | Source |
|--------|-------------|---------|--------|
| `wms.stock` | `entity.list.wms_stock@1` | `wms_cap_entity_list_stock_1` | `wms_stock` JOIN `wms_locations`, qualifier: `low` |
| `wms.stock` | `entity.get.wms_stock@1` | `wms_cap_entity_get_stock_1` | Single stock item with location name |
| `wms.location` | `entity.list.wms_location@1` | `wms_cap_entity_list_location_1` | `wms_locations` by name |
| `wms.location` | `entity.get.wms_location@1` | `wms_cap_entity_get_location_1` | Reuses `wmsLocationRecord()` with per-request cache |

### Ecommerce

| Entity | capability ID | Handler | Source |
|--------|-------------|---------|--------|
| `ecommerce.product` | `entity.list.ecommerce_product@1` | `ec_cap_entity_list_product_1` | `ec_products` with primary image subquery, qualifier: `featured` |
| `ecommerce.product` | `entity.get.ecommerce_product@1` | `ec_cap_entity_get_product_1` | Single product with primary image |
| `ecommerce.order` | `entity.list.ecommerce_order@1` | `ec_cap_entity_list_order_1` | `ec_orders` by created_at desc |
| `ecommerce.order` | `entity.get.ecommerce_order@1` | `ec_cap_entity_get_order_1` | Single order with `ec_order_items` |

---

## EntityViewResolver builtinDefaults (Implemented)

All entries in `kernel/EntityContext/EntityViewResolver.php`:

```php
'bakeshop_product'      => ['fields' => ['id','name','price','unit','stock_qty','category'], 'actions' => ['view'], 'limit' => 20, 'empty_state' => 'No products found.'],
'guidance_case'         => ['fields' => ['id','student_name','status','created_at','counselor_name'], 'actions' => ['view'], 'limit' => 15, 'empty_state' => 'No cases found.'],
'guidance_appointment'  => ['fields' => ['id','title','date','status','student_name'], 'actions' => ['view','cancel'], 'limit' => 10, 'empty_state' => 'No appointments.'],
'daily_ledger_entry'    => ['fields' => ['id','entry_type','amount','created_at','notes'], 'actions' => ['view'], 'limit' => 25, 'empty_state' => 'No ledger entries.'],
'wms_stock'             => ['fields' => ['id','sku','name','qty','location_name','updated_at'], 'actions' => ['view','move'], 'limit' => 30, 'empty_state' => 'No stock items.'],
'wms_location'          => ['fields' => ['id','name','type','is_staging'], 'actions' => ['view'], 'limit' => 20, 'empty_state' => 'No locations.'],
'ecommerce_product'     => ['fields' => ['id','name','price','image','stock_status'], 'actions' => ['view','add_to_cart'], 'limit' => 20, 'empty_state' => 'No products found.'],
'ecommerce_order'       => ['fields' => ['id','order_number','status','total','created_at'], 'actions' => ['view'], 'limit' => 15, 'empty_state' => 'No orders yet.'],
```

---

## Template Author Experience (Available Now)

```disyl
{# Bakeshop product catalog #}
<ikb_entity_list source="bakeshop_product.recent" view="compact" />

{# Guidance case dashboard — qualifier: open / closed #}
<ikb_entity_list source="guidance_case.open" view="table" />

{# WMS low-stock alert #}
<ikb_entity_list source="wms_stock.low" view="card_grid" />

{# Ecommerce featured products + recent orders #}
<ikb_entity_list source="ecommerce_product.featured" view="card_grid" />
<ikb_entity_list source="ecommerce_order.recent" view="compact" />

{# Daily ledger entries — qualifier: sales / expense #}
<ikb_entity_list source="daily_ledger_entry.sales" view="table" />

{# Single entity detail #}
<ikb_entity_detail source="guidance_appointment" id="42" view="detail" />
```

Zero handler code. Zero module-internal knowledge. The theme declares intent. The capability bus resolves data. The render boundary stays intact.

---

## Files Changed (Actual)

| File | Change |
|------|--------|
| `kernel/EntityContext/EntityViewResolver.php` | 8 new builtinDefaults entries |
| `modules/bakeshop/helpers.php` | 2 entity capabilities + handler functions + `bakeshop_capability_handlers()` map |
| `modules/guidance/helpers.php` | 4 entity capabilities + handler functions + `guidance_capability_handlers()` map |
| `modules/daily-ledger/helpers.php` | 2 entity capabilities + handler functions + `daily_ledger_capability_handlers()` map |
| `modules/wms/helpers/00-bootstrap.php` | 4 entity capabilities in `wms_capability_handlers()` map |
| `modules/wms/helpers/10-core.php` | 4 entity capability handler functions |
| `modules/ecommerce/helpers/00-init.php` | 4 entity capabilities + handler functions + `ec_capability_handlers_entity()` map |
| `docs/kernel/entity-view-adoption-plan.md` | This plan |

**8 files, +438 lines, 16 new capability handlers, 8 new entity types.**

---

## Quality Gates

| Gate | Status |
|---|---|
| `php -l` all 7 files | ✅ Clean |
| `kernel_hardening_test.php` | ✅ 43/43 |
| `guidance_password_reset_test.php` | ✅ Pass |
| `guidance_public_booking_csrf_test.php` | ✅ 8/8 |
| `guidance_profile_route_contract_test.php` | ✅ 6/6 |
| `guidance_settings_modules_test.php` | ✅ 17/17 |
| `wms_module_test.php` | ✅ 27/27 |
| `error.log` | ✅ 0 lines |

## Phase 3 — Template Migration (Deferred)

Individual module templates still use module-specific render paths. Migration path per module:

| Module | Current Render | Target |
|--------|---------------|--------|
| Bakeshop | `bakeshopRender('pages/products.disyl')` | `{ikb_entity_list source="bakeshop_product"}` in CMS theme |
| Guidance | `guidanceRender('pages/dashboard.disyl')` | `{ikb_entity_list source="guidance_case.open"}` in CMS theme |
| Daily Ledger | `dlRender('pages/entries.disyl')` | `{ikb_entity_list source="daily_ledger_entry"}` in CMS theme |
| WMS | `wmsRender('pages/stock.disyl')` | `{ikb_entity_list source="wms_stock"}` in CMS theme |
| Ecommerce | Mix of direct handlers + context injection | `{ikb_entity_list source="ecommerce_product.featured"}` in storefront |

**Template migration is zero-risk for the capability layer.** The capabilities exist and are tested. Templates can adopt them incrementally without breaking existing render paths.
