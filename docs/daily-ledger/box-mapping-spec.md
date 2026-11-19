# Box mapping — design (agreed 2026-11-19)

Owner direction: *"at products, a new view for box mapping ... assigned to a product so that when a qty
shows how many were sold in a shift, the box mapped is also auto deducted at the branch."*

Agreed defaults: **1 cake = 1 box**, **one box per product**, **deduct from sales only** (not wastage),
mapping set **per product**.

## Why this exists

Boxes belong to branches (admin, 2026-11-19: *"PIC only produces products; boxes of any kind belong to the
branches"*). Today nothing links a cake to a box: there is no `box_product_id`, no packaging table, no
mapping of any kind. Selling 20 cakes does not move box stock by 20, so box inventory overstates what is
available and understates cost — the expected branch symptom is "we keep buying boxes but the app says we
have plenty".

## The one structural rule

**Derive the deduction, never increment it.** The cashier sheet is saved repeatedly — per cell, on blur, on
every correction. An increment (`withdraw + 1`) on each save would multiply boxes with every save cycle.
`dl_daily_ledger.sales` is already a stored value written by a recompute
(`dl_computeSalesValue()` → `UPDATE dl_daily_ledger SET sales = :sales`, handlers.php ~6998-7006). The box
usage is computed in that same motion and **set**, so a re-save is a no-op and a corrected sales figure
corrects the box with it.

## Build order

1. **Storage.** Mapping: one row per product → `box_product_id`, plus the per-row applied amount so the
   deduction can be reversed and re-set safely (`packaging_applied`). Migration in
   `modules/daily-ledger/database/migrations/`, ENGINE=InnoDB, utf8mb4, no FK to a table that may not exist
   yet. Register in `module.json` per migration-workflow convention.
2. **Products view.** A new tab beside "Show in Branches" (`templates/modules/daily-ledger/products.disyl`):
   product, current box, box picker (only products that are boxes), one save. This tab is also where a
   product becomes "a box" explicitly, replacing today's `category='other' AND name LIKE '%box%'` hack in
   `dl_isPackagingProduct()`.
3. **Deduction.** In the sales recompute, for each cake with a mapped box: compute `usage = sales × 1`, and
   apply `delta = usage − packaging_applied` to the box product's row for the same branch/date/shift,
   creating the row if absent, then store `packaging_applied = usage`. Audited
   (`packaging_auto_deducted`) with product, box, date, shift, usage, delta.
4. **Visible on the box row.** The branch must see "28 boxes used by cake sales" — not a silent subtraction.
5. **Short box never blocks a sale.** If the box is short, the sale stands and the box goes negative with a
   named shortfall event, exactly like the dispatch guard. Clamping would hide it; blocking would stop a till.

## Hard constraints (from the day's work)

- Closed days and frozen variance snapshots stay immutable — same rule as `dl_repriceProductLedgerRows()`.
- No clamping, no deleting, no silent restatement.
- Every write audited, in the same transaction, idempotent on re-run.

## Blocking dependency — RE-EXAMINED 2026-11-20: it was not the blocker it looked like

This section was written on the assumption that the name-similar products were duplicate twins to be
merged. Measured against `dl_products`, they are **price/size variants of one family**, each carrying its
own `current_price`:

| family | members (id · price · units sold last 90 d) |
|---|---|
| cookies & cream | #45 · 330 · 563  ·  #212 · 480 · 1 |
| meat bun | #219 · 15 · 0  ·  #220 · 25 · 0  ·  #221 · 28 · 644 |
| toasted bread | #119 · 6 · 1,926  ·  #121 · 9 · 10,119  ·  #232 · 15 · 19 |
| toasted mamon | #129 · 15 · 101  ·  #224 · 10 · 50 |
| ube supreme | #209 · 480 · 0  ·  #210 · 530 · 1 |

Only ONE group was a true duplicate, and it is already resolved: `Nutri-Cookies` (#227) is inactive while
`NUTRI COOKIES@18` (#187) sells — the owner's rule settled that one.

**Merging is therefore the wrong direction.** A 6-peso toasted bread and a 15-peso one are different
SKUs; applying the twin rule here would delete real price variants. Box mapping does not need the family
merged — it needs the box attached to each variant that actually sells, because different sizes may take
different boxes. Steps 1, 2 and 3 are all unblocked: the deduction targets one product id, and every
variant is a product id.

### Owner rule (2026-11-19): "twin item, if one is inactive, then the active one is true"

Kept for the record. Applied to the six name-similar groups it settled exactly one (nutri cookies) — now
explained: the other five were never duplicates, so there was nothing to settle. The rule still holds as
a guard worth encoding: **when a product is deactivated, its packaging mapping must follow**, and the
mapping screen must refuse to attach a box to an inactive product.

### Two data prerequisites found while re-examining

- `pcs_per_pack` is **NULL on every one of these rows**, so nothing says how many pieces a box holds. If a
  box holds 12 mamon, `usage = sales × 1` over-deducts 12×. The mapping needs an explicit units-per-box
  (default 1) rather than an assumed 1:1.
- `product_category` is inconsistent *within* a family: `TOASTED BREAD @6` and `@9` are `other` while
  `@15` is `bread`; `TOASTED MAMON @15` is `other` while `PCS@10` is `bread`. Anything grouping by
  category splits these families, so the "is this a box" flag (step 2) must not depend on it.

## Options for proceeding (evaluated 2026-11-20)

| option | what it means | verdict |
|---|---|---|
| **A. Per-product mapping** | one `box_product_id` per SKU; variants simply each get their box | **recommended** — matches the data, no schema join table, deduces per variant |
| **B. Box-centric mapping** | box → list of products it serves | converges with A: A can point several products at the same box. Only worth it if a box's *membership* must be managed from the box side |
| **C. Units-per-box ratio** | `usage = ceil(sales / units_per_box)` | **needed as a field in A**, not as a separate design — `pcs_per_pack` is NULL everywhere, so the ratio must be explicit on the mapping |
| **D. Bootstrap suggestions** | pre-fill candidate mappings from category+name, admin confirms | useful only after A exists: turns ~90 decisions into a review. Risky before A, because `product_category` is inconsistent within families |
| **E. Deduct from production instead of sales** | trigger at production output | rejected — owner direction is "when a qty shows how many were sold in a shift" |

## Why this is not built yet

The write lands inside the branch balance derivation — the same numbers stabilised today (cashier carry
99.43% correct, zero negative endings across 261,515 rows). A half-built schema-plus-derivation change at
the end of a long session is the exact risk that produced the defects being fixed. Storage and the Products
tab are safe to build first; the deduction step wants a fresh pass with the mapping data in place.

