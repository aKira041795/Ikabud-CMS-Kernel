# DC Cafe — POS ↔ Inventory Alignment Review & Design Contract

- **Date:** 2026-09-19
- **Tenant:** `dccafe.test` (tenant_id 583, `dccafe-001`, DB `dccafe`, store 1 = DC Blu)
- **Module:** `modules/dc-cafe/`
- **Method:** live browser session + DB verification + source review
- **Test accounts:** `admin` / `admin123` (store 1), `cashieraki` / `akira123` (store 2)

---

## 1. Verified working (live, with evidence)

| # | Behaviour | Evidence |
|---|---|---|
| 1 | Login (module-owned auth) | `admin` → `/dc-cafe/pos` |
| 2 | POS catalogue: categories, products, cart, qty, discount, voucher, customer, tenders | rendered + interactive |
| 3 | Soft-serve customizer (base / sauces / toppings / add-ons) with live total | CUDDLY ₱95 + Extra Caramel ₱20 = **₱115.00** |
| 4 | Server-authoritative pricing + client price-drift guard | `unit_price` mismatch → `price changed, refresh the cart` |
| 5 | Receive products → branch stock + `purchase` movement | 20 × BLUEBERRY → `on_hand_qty=20`, mv +20 |
| 6 | **POS sale → branch stock + `sale` movement** | sell 2 → `on_hand_qty 20→18`, mv −2 ref order 7 |
| 7 | Void = **journal-replay restore** (not recompute) | `dc_product_stock_movements` + `dc_inventory_movements` read back, `void_restore` written, status `voided` |
| 8 | Per-branch stock with optimistic lock | `dc_product_store_stock.version` |
| 9 | Inventory → Products tab: editable stock + reorder, LOW/OK | inputs held `0.00` / `10.00` (a11y snapshot showed blank — **false positive**, corrected) |
| 10 | Role gating admin/supervisor/auditor/cashier | cashier nav correctly omits Dashboard/Settings/Ingredients/Suppliers |
| 11 | Branch-scoped stock | `admin` → store 1 (DC Blu), `cashieraki` → store 2 (DC Main); each sees its own `dc_product_store_stock` |

**Baseline suites:** `dc-cafe-http` 84/84, `dc-cafe-settings` 32/32, `dc-cafe-ledger-formula` 7/7.

---

## 2. Defects found

### G1 — Box of doughnuts has no flavour selection · HIGH

A box is a flat product. Selling it records **nothing** about which doughnuts the customer chose.

**Proof** — sold `DC DELIGHTS COMBO (6pcs)` (order #8):

```
dc_order_items: item 8 | product 38 | qty 1 | ₱255.00 | customizations = NULL
movements:      product 38  −1.00  (the box only)
components:     BLUEBERRY 18 · GLAZED 0 · CHOCO MM 0 · BOSTON CREME 0  ← untouched
```

No customizer opens on click (`is_variable = 0` for every box/bundle). Per-flavour inventory is therefore fiction: a flavour sold only inside boxes never decrements.

### G2 — Toppings & sauces have no price and no ingredient mapping · HIGH

```
dc_soft_serve_sauces   (7)  → sauce_id, name, is_active
dc_soft_serve_toppings (24) → topping_id, name, is_active
dc_soft_serve_addons   (16) → addon_id, name, price, type        ← only these are priced
dc_addon_ingredients   (9)  → only "Extra …" addons mapped
```

Choosing `MANGO` topping costs **₱0** and deducts **0**. Free stock loss, invisible in COGS.

### G3 — Base choice does not change the BOM · MEDIUM

`CUDDLY` has one BOM row (`FroYo Mix 0.200 L`) regardless of `FROYO` / `SOFT SERVE` / `MIX`. Selecting `SOFT SERVE` still deducts FroYo Mix.

### G4 — Meals have no composition · HIGH

Hot Meals are flat `has_stock = 1` products. `FRIED CHICKEN COMBO`, `BABY BACK RIBS COMBO`, `ALL-OUT MUNCH` are **combos in name only** — no main/side/drink structure, no choice, no per-component deduction.

### G5 — POS hard-blocks at zero stock; no deferred-receiving path · **CRITICAL**

**Observed (both layers of guard):**
```
Insufficient stock for BLUEBERRY CHEESECAKE: have 0, need 1     ← finished goods
Insufficient FroYo Mix: have 0, need 0.2                        ← BOM ingredient
```

Enforced by non-negotiable SQL guards:
```sql
UPDATE dc_product_store_stock SET on_hand_qty = on_hand_qty - ?
 WHERE product_id = ? AND store_id = ? AND on_hand_qty >= ?     -- rowCount 0 → throw
UPDATE dc_ingredients SET current_stock = current_stock - ?
 WHERE ingredient_id = ? AND current_stock >= ?                 -- rowCount 0 → throw
```

**There is no delivery document.** `dc_deliveries` does not exist. The "Deliveries" page is a bare quantity form — no supplier, **no DR/invoice number, no delivery date**. It writes `movement_type='purchase'`, `reference_type='supplier'` with no linkable document.

> **Operational reality:** goods arrive and are sold all day; the receiving entry is done late.
> Today that means the shelf is full while the system says zero → **the cafe cannot trade.**

### G6 — BOM-based products show no availability signal in POS · MEDIUM

```
x-show="p.has_stock && p.current_stock <= 5"
```
Soft-serve, cone and all BOM products (`has_stock = 0`) render **no badge at all**. The cashier only discovers the problem at checkout. Cards are never disabled (`@click="addToCart(p)"` is ungated).

### G7 — No idempotency key on order creation · MEDIUM

Actual checkout payload:
```json
{"store_id":1,"session_id":5,"payment_method_id":1,"amount_tendered":0,
 "discount_amount":0,"discount_reason":"","items":[{"product_id":30,"quantity":2,"unit_price":55}]}
```
No `client_operation_key`. A double-tap or network retry creates a **duplicate order and duplicate deduction**. (Contrast: daily-ledger POS has `dl_pos_sale_events` + unique `client_operation_key`.)

### G8 — Dual stock source (legacy vs branch) · MEDIUM

Receive and sale write **only** `dc_product_store_stock`. After receiving 20, `dc_products.current_stock` remained `0.00`. The legacy column is still read by the unused `_loadProductsById()` and zeroed by the reset service. Any future reader of the legacy column gets wrong data.

### G9 — POS config latency · LOW

Every POS config call logs `slow_request` at ~1.0–1.2 s (`products`, `soft-serve/bases|sauces|toppings|addons`, `payment-methods`), 6+ calls per page load.

---

## 3. Root cause

The module models **flat products only**.

```
dc_products ──1:1── stock      (has_stock → dc_product_store_stock)
            ──1:N── BOM        (dc_product_ingredients → dc_ingredients)
            ── customizer     (hard-coded to soft-serve: base/sauce/topping/addon)
```

Every requirement in this review is the **same missing concept**: *a sellable item composed of other stock-bearing things, with optional customer choice*.

| Case | Composition shape |
|---|---|
| Box of doughnuts | 6 **slots**, each → a doughnut product |
| Ice cream + toppings | fixed base ingredient + **optional priced** sauces/toppings/add-ons |
| Meal | fixed main + side, **choice group** for drink |

One model serves all three. The current design cannot express any of them.

---

## 4. Proposed target model

### 4.1 Composition (replaces the hard-coded customizer)

```
dc_products
  └─ composition_mode  ENUM('simple','recipe','bundle','slots')

dc_product_components            -- fixed parts, always consumed
  id, product_id, component_product_id NULL, ingredient_id NULL,
  quantity, applies_when_base NULL

dc_product_option_groups         -- customer choices
  id, product_id, group_key, label,
  kind ENUM('single','multi','slots'), min_select, max_select, slot_count,
  sort_order

dc_product_option_choices        -- one selectable option
  id, group_id, label, product_id NULL, ingredient_id NULL,
  price_delta, is_default, sort_order
```

**Sale-time resolution** (single pass, before the existing deduction loop):
1. Walk `dc_product_components` → add to `stockDeductions` / product decrements.
2. Walk selected choices → add the choice's `ingredient_id` / `product_id`, and add `price_delta` to unit price.
3. `slots` groups: `slot_count` selections required, each selection is a component.

**Mapping of the three cases**

| Case | Config |
|---|---|
| Box of doughnuts | `composition_mode='slots'`; group `kind='slots'`, `slot_count=6`, choices = doughnut products (category-scoped) |
| Ice cream + toppings | `composition_mode='recipe'`; components = base mix; groups = base(`single`), sauces(`multi`), toppings(`multi`), add-ons(`multi`) — each choice carries `ingredient_id` + `price_delta` |
| Meal | `composition_mode='bundle'`; components = main + side; group = drink(`single`) |

**Backfill:** soft-serve (5 products) → `recipe` + groups from the existing sauce/topping/addon tables; boxes → `slots`; meals → `bundle`. All existing customers keep working because `simple` remains the default.

### 4.2 Deferred receiving (solves G5)

Two phases — matching how the cafe actually works:

```
DELIVERY ARRIVES
   │
   ├─ Phase 1 · DOCKET   (fast, at drop-off — 30 seconds)
   │    supplier? DR no.? items + qty → posts stock immediately as DOCKETED
   │
   └─ Phase 2 · RECEIVING (later, whoever does inventory)
        confirm counts, correct variances, attach invoice → CONFIRMED
```

```
dc_deliveries
  delivery_id, store_id, supplier_id NULL, dr_number NULL, invoice_number NULL,
  delivered_at, docketed_by, docketed_at,
  status ENUM('docketed','received','reconciled','cancelled'),
  received_by NULL, received_at NULL, notes

dc_delivery_items
  id, delivery_id, product_id NULL, ingredient_id NULL,
  qty_docketed, qty_received NULL, unit_cost NULL
```

**Availability becomes:**
```
sellable = dc_product_store_stock.on_hand_qty + SUM(docketed-and-not-yet-received qty)
```

Stock is posted at docket time (`movement_type='purchase'`, `reference_type='delivery'`), so the existing deduction guards keep working unchanged and the cashier can sell what physically arrived. Phase 2 only *reconciles* — it never blocks selling.

**Emergency valve** (if dockets are not captured): per-tenant policy
`dc_settings.pos_stock_policy = strict | warn | allow_negative`
- `strict` — today's behaviour (block)
- `warn` — sell, log `oversell` event, flag the sale
- `allow_negative` — sell, allow negative `on_hand_qty`, surface in a daily exception report

Recommended: `warn` as the default once dockets exist; `allow_negative` only as a break-glass.

### 4.2a Box of doughnuts — standard set + substitution (the real operational pattern)

**Domain truth:** a box normally has a **fixed standard set** of doughnuts. The cashier/customer
only chooses when a standard type is **out of stock**. So substitution is the exception, not the flow.

**Key discovery:** `dc_order_items.parent_item_id` already exists and is documented for exactly this
("links addon line items to their parent product"). Reuse it — no new order table is needed.

```
dc_order_items
  item 100  product 38 (box)      qty 1  unit ₱255.00  parent NULL
  item 101  product 30 (BLUEBERRY) qty 1  unit  ₱0.00   parent 100   {"slot_no":1}
  item 102  product 15 (GLAZED)    qty 1  unit  ₱0.00   parent 100   {"slot_no":3,"substituted_from":23}
  item 103  product 19 (CHOCO MM)  qty 1  unit  ₱0.00   parent 100   {"slot_no":4}
  ...
```

Standard set is data:

```
dc_product_components            -- the STANDARD set per box
  id, product_id, slot_no, component_product_id, quantity,
  substitutable TINYINT DEFAULT 1,
  UNIQUE KEY (product_id, slot_no)
```

**Why `parent_item_id` is the right primitive — five things come free:**

| Need | Mechanism | New code |
|---|---|---|
| Component stock decrement | children are `has_stock=1` rows in `$orderItems` → existing deduction loop | **none** |
| Out-of-stock guard | existing per-item `$available < $qty` check | **none** |
| **Reconciliation balances** | `SUM(oi.quantity) GROUP BY product_id` already counts children | **none** |
| Void / restore | void replays movements, not order rows | **none** |
| Channel reporting | `parent_item_id IS NULL` = direct, `IS NOT NULL` = in box | **none** |

**Therefore the earlier "auto-set `pullout_qty`" idea is not needed, and neither is a
`consumption_channel` column on movements.** A box component is a *real sale line*, so the ledger
formula `Beginning + Production − Pullouts − Sales = Ending` balances by itself and
`pullout_qty` stays a purely human worksheet field.

**Three UX paths (operational simplicity):**

| Path | Trigger | Cashier effort |
|---|---|---|
| **A — normal (~90%)** | every standard slot in stock | **1 tap. No picker opens.** Standard set recorded automatically |
| **B — one type out** | a standard slot is short | picker auto-opens pre-filled; short slot flagged ⚠; tap **Swap** → in-stock alternatives. Block *Add to Order* while a slot is unresolved |
| **C — several out** | multiple slots short | same picker, swap each |

Design rule: **the picker opens only when it must.** Adding a complication-free box stays a single tap.

**Substitution pricing** — doughnuts span ₱10–₱55, boxes ₱165–₱570, so a swap can change value
(₱30 GLAZED → ₱55 NUTELLA BOMB is +₱25). Per-box `substitution_policy`:

- `charge_delta` *(default)* — the child line carries `unit_price = new − standard`; order total is correct and the surcharge is traceable
- `free` — no delta

The cashier never computes anything; the POS adds the difference.

**Blast radius (small, but real):**

- `templates/modules/dc-cafe/orders/detail.disyl` — `{for item in order.items}` would render child
  rows as ₱0.00 lines. Indent/group them under the parent.
- `apiExportSalesReportCsv` — a doughnut sold only in boxes reports `qty>0, revenue ₱0` (correct:
  revenue belongs to the box). Add a "Sold In Box" column so it isn't misread as free stock.
- `handlers.php:729` dashboard top-5 — children have `total_price = 0` so they rank last and the box
  dominates the ranking. Correct.

### 4.3 Inventory truth (solves G2/G3/G8)

- Give sauces/toppings `price` + an ingredient mapping (or fold them into `dc_product_option_choices`, which is cleaner and removes three near-duplicate tables).
- Base choice resolves the BOM via `dc_product_components.applies_when_base`.
- Deprecate `dc_products.current_stock`; make `dc_product_store_stock` the single source, then drop the column in a later migration.

---

## 5. Proposed delivery plan

### Phase 0 — Unblock trading *(smallest correct change)*
- [ ] `dc_settings.pos_stock_policy` (`strict` default → no behaviour change until flipped)
- [ ] POS: gate/flag OUT cards, show availability for BOM products too (G6)
- [ ] `client_operation_key` on order create + unique index (G7)
- **Acceptance:** duplicate POST with same key → one order. Policy `warn` → sale succeeds at 0 stock and is flagged.

### Phase 1 — Delivery docket *(solves the handover constraint)*
- [ ] `dc_deliveries` / `dc_delivery_items` migration
- [ ] Docket UI (supplier + DR no. + items) — reuse the receive form
- [ ] Phase-2 reconciliation screen (confirm / variance / cancel)
- [ ] `sellable = on_hand + docketed_pending` in POS + stock APIs
- **Acceptance:** docket 20 → sell 2 with zero confirmed stock → succeeds; reconcile → on_hand correct and movements balanced.

### Phase 2 — Composition model *(variable products)* — ✅ DELIVERED 2026-09-19

Built and verified end-to-end. **No new order tables and no enum change** — the box stays a single
order line and component consumption rides the existing movement journal.

| Artefact | What it does |
|---|---|
| `032_add_movement_consumption_channel.sql` | `consumption_channel ENUM('direct','bundle')` + `bundle_product_id` on `dc_product_stock_movements` |
| `033_create_box_composition.sql` | `slot_count` / `component_category_id` / `component_min_price` / `component_max_price` on `dc_products`; `dc_product_components` standard set |
| `034` / `035` | mark the 8 doughnut boxes: `slot_count` set, `has_stock = 0` |
| `helpers/boxes.php` | `dcBoxIsContainer`, `dcBoxDefinition`, `dcBoxResolveSelection` |
| `apiGetBoxOptions` | slot-picker data: standard set + band/category-filtered choices |
| `apiCreateOrder` | resolves slots → per-component stock check → marked deductions inside the order transaction |
| `_dcInventoryDerivedMetrics` | 7th param `$boxPulloutQty`, additive to the manual pullout |
| `apiGetReconciliation` | derives box pullout from the journal, excludes box containers from the worksheet |
| `ledger.disyl` | read-only `+N from boxes` under the **unchanged, still-editable** pullout input |

**Verified live (order #9, store 2, then voided):**

```
choose 2x BLUEBERRY + 4x GLAZED -> order #9 PHP 255.00 (single line, customizations.box.components)
stock      BLUEBERRY 6->4,  GLAZED 4->0,  box itself NOT deducted
movements  type=sale channel=bundle bundle_product_id=38   (both rows)
worksheet  begin 6 - box 2 - ending 4 = 0 calculated, POS 0, VARIANCE 0
void       BLUEBERRY ->6, GLAZED ->4, void_restore written, status=voided
```

**Operational paths, both confirmed:**
- **A (normal)** — every standard slot stocked → `requires_selection=false` → picker does **not** open, box lands in the cart in one tap with the standard set recorded.
- **B (shortage)** — a standard slot out → picker auto-opens pre-filled, short slots flagged "needs a swap", choices filtered to the box's band.

**Deliberately NOT done (DC Cafe commercial decisions):**
- price bands (`component_min_price` / `component_max_price`) are `NULL` → unconstrained until set
- standard flavour sets are empty → every box currently opens the picker

### Phase 2b — Substitution band *(pending DC Cafe input)*
The band is the mechanism preventing a premium flavour in a cheap box (and vice versa). Set
`component_min_price` / `component_max_price` per box, and seed `dc_product_components` with the
standard set. Once a standard set exists, path A applies and a configuration-free box is a single tap.

### Phase 3 — Inventory truth & reporting
- [ ] Sauces/toppings price + ingredient mapping; base→BOM
- [ ] Drop `dc_products.current_stock`
- [ ] Daily variance / unrecorded-stock report
- [ ] POS config latency (G9)

---

## 6. Evidence appendix

```
receive 20 BLUEBERRY  → dc_product_store_stock.on_hand_qty = 20   mv +20  purchase/supplier
sell     2 BLUEBERRY  →                            18            mv  −2  sale/order 7
sell     1 BOX(6pcs)  → box 10→9 only, customizations = NULL      components untouched
```

Test transactions #7 and #8 were removed after verification; `dccafe` is back to
`orders=1, items=1, movements=0, ingredient_movements=0, total_on_hand=0.00`.
Both `storage/logs/app.log` and `storage/logs/error.log` were checked — no new errors
(only pre-existing `slow_request` warnings).

---

## 7. Open items

- `dc-cafe` has no `dc_deliveries` table today, so "Deliveries" is a label, not an entity.
- Checkout payload has no idempotency key (G7).
- Branch scoping is real but implicit: `admin` (store 1) and `cashieraki` (store 2) see different
  stock for the same product. Decide whether a box sold at one branch may consume another
  branch's component stock (currently: no — deductions are branch-local).
- Every product currently sits at `on_hand_qty = 0` and every ingredient at `0`; no opening
  balance has ever been posted, so all doughnuts/meals render `OUT`.

---

## 8. Contract summary

```
task:
objective: align POS ↔ Inventory and support composed / variable products

scope:
  allowed:
    - modules/dc-cafe/** (migrations, handlers, helpers)
    - templates/modules/dc-cafe/**
    - tests/dc-cafe/**
  prohibited:
    - kernel changes for module-specific behaviour
    - cross-module DB access
    - weakening existing deduction guards without the policy flag

constraints:
  - MySQL 5.7 (no window functions, no CTEs, no JSON_TABLE)
  - ModuleDB: no derived tables in module queries
  - DiSyL: no inline {k: x ? a : b} object literals in <script>, no ?? in script blocks
  - Reuse ModuleBackupService / ModuleDataResetService

acceptance:
  - policy flag defaults to today's strict behaviour
  - duplicate order POST cannot double-deduct
  - box sale decrements the chosen components
  - meal sale decrements each component
  - priced topping applies price AND ingredient
  - docket postings are sellable before formal receiving

e2e_acceptance:
  - docket 20 → sell 2 → reconcile → stock and movements balanced
  - box of 6 → 6 flavour stocks decrement, order records all 6
  - void of each → journal-replay restores exactly

verification:
  - tests/dc-cafe/DcCafePosTest.php, DcCafeHttpTest.php
  - php -l, composer lint
  - Playwright POS ↔ inventory journey
  - both logs clean

risk:
  - composition model touches the checkout hot path (mitigate: simple path unchanged)
  - dropping current_stock is irreversible (do last, separate migration)

status: AWAITING_DIRECTOR_DECISION
```
