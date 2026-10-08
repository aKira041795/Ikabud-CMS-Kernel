# ADR-005: One assortment per branch — a commissary's production list is its branch product list

## Status
Accepted (2026-10-09)

## Context

A commissary is **not a separate entity**: it is a branch with `dl_branches.is_commissary = 1`
(migration `022_branch_supply_mode.sql`). Assortment is held in one role-less table:

```
dl_branch_products(id, branch_id, product_id, is_active, created_at)
UNIQUE KEY uq_dl_branch_product (branch_id, product_id)
```

Two different surfaces read that single `is_active` flag:

- **retail / till list** — `dl_fetchCashierLedgerRows`, `dl_carryCashierBeginnings`,
  `dl_autoCarryBeginnings`, `apiGetLedgerRows`, `apiSaveLedgerBatch`, `dl_buildUsagePageData`
- **production sheet** — `dl_fetchProductionSheetProducts`, `dl_recordProductionAddition`,
  `dl_saveProductionOutputLedgerCell`, `dl_maybeAutoFinalizeCommissaryPmShift`,
  `apiFinalizeProductionPmShift`, `dl_shiftMissingEndings`

`dl_fetchProductionSheetProducts()` shows the join plainly:

```sql
FROM dl_products p
INNER JOIN dl_branch_products bp ON bp.product_id = p.id AND bp.is_active = 1
WHERE ... AND bp.branch_id = :bid
```

So for a branch that is *also* a commissary, one row and one flag decide both "we sell this here"
and "we produce this here". That is the coupling this ADR settles.

## Decision

**Keep one assortment. Do not add a role/channel dimension to `dl_branch_products`.**

`is_active` continues to mean *"this branch handles this product"*. The production sheet reads it as
its production list. `is_commissary` chooses which **surface** you are looking at, never which
assortment applies.

## Why — the evidence, not the theory

Measured against the live tenant (207 = baronledger, 2026-10-09):

- exactly **one** commissary: branch 18 `RIZAL-COMMIS`, with **178 active pairs and 0 hidden pairs**;
- branch 18 has **0** rows in `dl_daily_ledger` — it is **never used as a till** (branch 8 *Miputak*
  holds all 12,704);
- therefore the two surfaces have **never diverged in practice**, and nothing in the data expresses
  a need for the split.

## Why not the role dimension now

1. **No operator has asked for it.** The only case that needs it is a branch that is genuinely both
   a till *and* a production site, and wants to produce something its own outlet does not sell.
2. **Cost is measured, not guessed.** The ~7 production-side sites above must switch axis, plus the
   writer `dl_setBranchProductActive()`, plus the admin assignment picker, plus the branch
   self-management screen, plus a migration that must backfill or duplicate every existing pair row
   because `UNIQUE(branch_id, product_id)` becomes `UNIQUE(branch_id, product_id, channel)`.
3. **The "correct" existing home is worse.** `dl_branch_product_supply_rules` already carries a
   `supply_source_type` dimension and a `source_id`, so it looks like the natural production axis —
   but it holds **0 rows** and nothing writes it. Re-sourcing the sheet onto it would empty every
   production sheet until a backfill, and then **drift silently** on every new assignment (assign a
   product → no supply rule created → sheet never shows it). That is a new class of invisible bug,
   where one extra column is a known quantity.
4. **Do not "protect" a commissary by refusing the hide.** Today the *only* way to take a
   discontinued product off a commissary's sheet is `is_active = 0` on its pair row. Refusing that
   for commissary branches would remove the sole knob and make the sheet unable to shed a dead
   product — a worse defect than the coupling. `is_active` must keep working as the production axis
   until a second axis exists.

This is a deliberate non-build, recorded so the item is **closed rather than left open**.

## Trigger to revisit

Reopen when **both** hold:

- a branch is genuinely dual-role — `is_commissary = 1` **and** it has real till activity
  (`dl_daily_ledger` rows); and
- an operator asks to produce something that branch's own outlet does not sell.

## The shape to use when that trigger fires

One additional column, not a new table:

```sql
ALTER TABLE dl_branch_products ADD COLUMN production_enabled TINYINT(1) NOT NULL DEFAULT 1;
UPDATE dl_branch_products SET production_enabled = is_active;   -- content identical on deploy day
```

- production-side sites listed above switch `is_active` → `production_enabled`;
- retail-side sites keep `is_active` (so the existing admin knob remains the retail axis);
- the migration-time backfill makes the sheet byte-identical immediately after deploy, so the change
  ships with zero behaviour change and the two axes then move independently.

The backfill is what makes this safe: adding the column with a default alone would resurrect products
the admin had already hidden from a commissary sheet.
