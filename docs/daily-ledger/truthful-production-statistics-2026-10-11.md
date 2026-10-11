# Truthful production statistics — the correct source, measured

**Owner direction (2026-10-11), verbatim:**
> "the very first ledger entry of beginning and additional is a starting point. then moving forward,
>  daily additional data is the production data. we have just started encoding production in october
>  for commissary. i hand off to you hopw to preproperly arrived at a truthful stats, not necessarily
>  accurate but useful and near true"

**Owner's standard: truthful, not necessarily accurate.** A statistic that is useful and near-true is
acceptable; one that lies is not.

---

## The finding: the efficiency view reads the wrong table

The production-efficiency view aggregates `dl_commissary_product_ledger.produced_qty`. On tenant 207
that table is **empty** (0 rows), so every Produced / Wastage / Yield figure it shows is a **structural
zero**. A page reading "0 produced, 0% wastage" tells an owner *"no waste"* — false in the **opposite**
direction from the usual error.

**The owner's model is already the schema's own model.** `dl_daily_ledger.addtl` is the *daily
additional* — stock moving **into** the branch. Migration 007 states it outright:

> "output movements → **addtl** (stock delivered to branch)"
> `SET dl.addtl = GREATEST(0, t.net_output)`

and the ledger identity in the same migration:

> `dl.sales = GREATEST(0, dl.beg_bal + dl.addtl - dl.withdraw - dl.bal_end)`

Migration 024 bounds where it comes from: *"Only POSTED branch_receivings affect `dl_daily_ledger.addtl`."*
So the source distinction the owner wants is **not** lost — it lives in the receiving: a receiving with
a **DR** is a transfer in; a receiving with **no DR** is branch production. That is exactly the owner's
rule, expressible today.

## Measured, tenant 207

    rows span 2026-08-15 -> 2026-10-11
    days with any ledger row: 53
    days with addtl > 0:      50        <- 50 of 53 days carry production-in
    total addtl units:        134,028

    month     days   addtl (in)   sold     withdrawn
    2026-10     11      36,466    26,214     15,067
    2026-09     30      85,008    76,401      8,311
    2026-08     12      12,554     5,952      3,723

**The owner's invariant holds everywhere:** sold < addtl in all three months. Sell-through across the
span is 108,567 / 134,028 ≈ **81%** — a real, plausible figure rather than an artefact of a sparse
window.

## The model to implement

    production-in per branch/product/day  = dl_daily_ledger.addtl
    sold                                  = dl_daily_ledger.sales
    withdrawn (leaves the branch)         = dl_daily_ledger.withdraw
    stock on hand at day end              = dl_daily_ledger.bal_end
    opening stock (the starting point)    = dl_daily_ledger.beg_bal / beg_qty

      Sell-through %  = sold / addtl * 100
      Loss %          = withdraw / addtl * 100
      Identity        = beg_bal + addtl - withdraw - bal_end = sales      (provable per row)

Because the identity is exact per row, the ratios can be **reconciled** rather than merely displayed —
which is what makes this truthful rather than approximately true.

## The window rule (owner: "scope data from days with know production")

Aggregate only over days that actually carry production-in (`addtl > 0`, or a posted receiving).
50 of 53 days qualify on tenant 207. Comparing a full period of sales against sparse production is what
produced the false ratios; restricting both sides to the same days is what makes them comparable.

## States the view must name, so a zero can never read as measured

- `no-production-days`  — the window contains no day with production-in: show *"no production recorded
  in this period"*, never a 0% rate.
- `partial-coverage`    — production covers fewer days than sales: state the day counts on both sides.
- `source-undeclared`   — a product with a sale but no declared supply source: say so, rather than
  attributing it to the branch.
- `exceeds`             — sold > addtl. The owner says this cannot happen; if it appears, it is a
  finding to surface, not a percentage to render.

## Why this satisfies "not necessarily accurate but useful and near true"

The figures are drawn from one measured series with an exact per-row identity, over days that actually
have data, with the coverage stated. They are near-true by construction and reconcilable by hand. The
alternative — a ratio computed against an empty table — is not near-true, it is meaningless, and it
would be believed because a percentage looks like a measurement.
