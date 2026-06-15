# Entity-View Adoption Plan — Closing the Gap

> **Status:** In progress — June 15, 2026
> **Objective:** Extend entity-view contracts to all modules so themes can present module data through governed `{ikb_entity_list}` / `{ikb_entity_detail}` without depending on module internals.

## Current State

| Module | Entity Views | entity.list | entity.get | View Contracts |
|--------|-------------|-------------|------------|----------------|
| CMS | ✅ Full | `entity.list.cms_page@1`, `entity.list.cms_post@1` | `entity.get.cms_page@1`, `entity.get.cms_post@1` | 13 contracts, 5 types |
| Weather | ✅ Full (polyglot) | `entity.list.weather@1`, `entity.list.weather_current@1`, `entity.list.weather_forecast@1` | `entity.get.weather@1` | ServiceProxy → Python |
| Ecommerce | 🟡 Partial | None registered | None registered | Falls back to builtinDefaults `products` |
| Bakeshop | 🔴 None | — | — | — |
| Guidance | 🔴 None | — | — | — |
| Daily Ledger | 🔴 None | — | — | — |
| WMS | 🔴 None | — | — | — |

## Plan

### Phase 1 — Register Capabilities (this session)

Each module gets two capabilities:

```
entity.list.{module_type}@1  → returns ['rows' => [...], 'total' => N]
entity.get.{module_type}@1   → returns single entity array
```

### Phase 2 — View Contracts

EntityViewResolver builtinDefaults extended with new entity types.

### Phase 3 — Template Migration (future)

Replace module-specific render paths with `{ikb_entity_list}` / `{ikb_entity_detail}` in templates.

---

## Implementation

### Bakeshop

| Entity | capability ID | Handler | Fields |
|--------|-------------|---------|--------|
| `bakeshop.product` | `entity.list.bakeshop_product@1` | `bs_cap_entity_list_product_1` | id, name, price, unit, stock_qty, category |
| `bakeshop.product` | `entity.get.bakeshop_product@1` | `bs_cap_entity_get_product_1` | Full product with recipe, inventory |

### Guidance

| Entity | capability ID | Handler | Fields |
|--------|-------------|---------|--------|
| `guidance.case` | `entity.list.guidance_case@1` | `gm_cap_entity_list_case_1` | id, student_name, status, created_at, counselor_name |
| `guidance.case` | `entity.get.guidance_case@1` | `gm_cap_entity_get_case_1` | Full case with appointments, notes |
| `guidance.appointment` | `entity.list.guidance_appointment@1` | `gm_cap_entity_list_appointment_1` | id, title, date, status, student_name |
| `guidance.appointment` | `entity.get.guidance_appointment@1` | `gm_cap_entity_get_appointment_1` | Full appointment detail |

### Daily Ledger

| Entity | capability ID | Handler | Fields |
|--------|-------------|---------|--------|
| `daily_ledger.entry` | `entity.list.daily_ledger_entry@1` | `dl_cap_entity_list_entry_1` | id, entry_type, amount, created_at, notes |
| `daily_ledger.entry` | `entity.get.daily_ledger_entry@1` | `dl_cap_entity_get_entry_1` | Full entry detail |

### WMS

| Entity | capability ID | Handler | Fields |
|--------|-------------|---------|--------|
| `wms.stock` | `entity.list.wms_stock@1` | `wms_cap_entity_list_stock_1` | id, sku, name, qty, location_name, updated_at |
| `wms.stock` | `entity.get.wms_stock@1` | `wms_cap_entity_get_stock_1` | Full stock item with movements |
| `wms.location` | `entity.list.wms_location@1` | `wms_cap_entity_list_location_1` | id, name, type, is_staging |
| `wms.location` | `entity.get.wms_location@1` | `wms_cap_entity_get_location_1` | Full location detail |

### Ecommerce (Gap Closure)

| Entity | capability ID | Handler | Fields |
|--------|-------------|---------|--------|
| `ecommerce.product` | `entity.list.ecommerce_product@1` | `ec_cap_entity_list_product_1` | id, name, price, image, stock_status |
| `ecommerce.product` | `entity.get.ecommerce_product@1` | `ec_cap_entity_get_product_1` | Full product with variants, images |
| `ecommerce.order` | `entity.list.ecommerce_order@1` | `ec_cap_entity_list_order_1` | id, order_number, status, total, created_at |
| `ecommerce.order` | `entity.get.ecommerce_order@1` | `ec_cap_entity_get_order_1` | Full order with items, customer |

---

## EntityViewResolver builtinDefaults (Extended)

```php
'bakeshop_product'      => ['fields' => ['id','name','price','unit','stock_qty','category'], 'actions' => ['view'], 'limit' => 20],
'guidance_case'         => ['fields' => ['id','student_name','status','created_at','counselor_name'], 'actions' => ['view'], 'limit' => 15],
'guidance_appointment'  => ['fields' => ['id','title','date','status','student_name'], 'actions' => ['view','cancel'], 'limit' => 10],
'daily_ledger_entry'    => ['fields' => ['id','entry_type','amount','created_at','notes'], 'actions' => ['view'], 'limit' => 25],
'wms_stock'             => ['fields' => ['id','sku','name','qty','location_name','updated_at'], 'actions' => ['view','move'], 'limit' => 30],
'wms_location'          => ['fields' => ['id','name','type','is_staging'], 'actions' => ['view'], 'limit' => 20],
'ecommerce_product'     => ['fields' => ['id','name','price','image','stock_status'], 'actions' => ['view','add_to_cart'], 'limit' => 20],
'ecommerce_order'       => ['fields' => ['id','order_number','status','total','created_at'], 'actions' => ['view'], 'limit' => 15],
```

---

## Template Author Experience (After Adoption)

```disyl
{# Bakeshop product catalog — no module-internal knowledge needed #}
<ikb_entity_list source="bakeshop_product.recent" view="compact" />

{# Guidance case dashboard #}
<ikb_entity_list source="guidance_case.open" view="table" />

{# WMS stock overview #}
<ikb_entity_list source="wms_stock.low" view="card_grid" />

{# Ecommerce order history #}
<ikb_entity_list source="ecommerce_order.recent" view="compact" />
```

Zero handler code. Zero module-internal knowledge. The theme just declares what data it wants.

## Files Changed (Expected)

| File | Change |
|------|--------|
| `kernel/EntityContext/EntityViewResolver.php` | 8 new builtinDefaults entries |
| `modules/bakeshop/helpers.php` | 2 entity capabilities + handler functions |
| `modules/bakeshop/module.json` | capabilities.exposes entries |
| `modules/guidance/helpers.php` | 4 entity capabilities + handler functions |
| `modules/guidance/module.json` | capabilities.exposes entries |
| `modules/daily-ledger/helpers.php` | 2 entity capabilities + handler functions |
| `modules/daily-ledger/module.json` | capabilities.exposes entries |
| `modules/wms/helpers/10-core.php` | 4 entity capabilities + handler functions |
| `modules/wms/module.json` | capabilities.exposes entries |
| `modules/ecommerce/helpers/00-init.php` | 4 entity capabilities + handler functions |
| `modules/ecommerce/module.json` | capabilities.exposes entries |
| `docs/kernel/entity-view-adoption-plan.md` | This plan |
