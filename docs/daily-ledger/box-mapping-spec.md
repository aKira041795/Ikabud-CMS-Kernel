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

## Blocking dependency

**The unit-duplicate twins must be merged first.** `TOASTED MAMON @15` vs `Toasted Mamon by PCS@10` are the
same item in two units; a mapping attached to the twin that never sells deducts nothing, and the real one
looks like it consumes no boxes. That is the open 6-group decision.

### Owner rule (2026-11-19): "twin item, if one is inactive, then the active one is true"

Applied to the six groups actually present:

| group | members (active / inactive) | outcome |
|---|---|---|
| nutri cookies | #187 active, #227 **inactive** | **RESOLVED by the rule** — #187 is the true one |
| toasted mamon | #129 active, #224 active | **decision needed** — this is the 61%-of-variance pair |
| cookies cream | #45 active, #212 active | decision needed |
| toasted bread | #119, #121, #232 all active | decision needed (3-way) |
| meat bun | #219, #220, #221 all active | decision needed (3-way) |
| ube supreme | #209, #210 both active | decision needed |

So the rule settles **1 of 6**. The other five have every twin still active, which is exactly why the
duplication produced variance instead of disappearing: nothing ever marked one as superseded.

The rule is still worth encoding as a guard rather than a one-off tidy-up: **when a twin is deactivated, the
packaging mapping must follow it**, and the mapping screen must refuse to attach a box to a product that has
an active twin — otherwise the box silently follows the twin that never sells.

## Why this is not built yet

The write lands inside the branch balance derivation — the same numbers stabilised today (cashier carry
99.43% correct, zero negative endings across 261,515 rows). A half-built schema-plus-derivation change at
the end of a long session is the exact risk that produced the defects being fixed. Storage and the Products
tab are safe to build first; the deduction step wants a fresh pass with the mapping data in place.
