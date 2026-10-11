# Daily Ledger — business analysis on measured evidence

**Window:** 2026-09-01 → 2026-10-11 (41 days) · **Tenant:** 207 · **Basis:** OFFICIAL rows only
(`dl_rowIsProvisional()` false) unless a line says otherwise.
**Every figure below is produced by a query against `dl_daily_ledger`, `dl_branch_receivings`,
`dl_commissary_product_ledger`, `dl_production_runs` or `dl_cashier_withdrawals`; the command is
named next to each block so any number can be re-derived.**

---

## 1. The headline, and the first thing that must be said about it

| Figure | Value | Basis |
|---|---|---|
| Official sales value | **PHP 789,386** | `SUM(sales × price_snapshot)` where the row is official |
| Official sales volume | **86,267 units** | `SUM(dl_ledgerSalesQuantitySql())`, official rows |
| Inclusive volume | 102,615 units | same, provisional rows included |
| Provisional rows | **2,697 of 14,401 (18.7%)** | carries **16,348 units — 15.9% of volume** |
| Branches with any ledger row | 7 of 21 | |
| Branches with any OFFICIAL sales | **2 of 21** | branches 8 and 9 |
| Days with ledger rows | 41 | |

```
SELECT COUNT(*), SUM(CASE WHEN <provisional> THEN 1 ELSE 0 END),
       SUM(<qty>), SUM(CASE WHEN <provisional> THEN 0 ELSE <qty> END),
       SUM(CASE WHEN <provisional> THEN 0 ELSE <amount> END)
  FROM dl_daily_ledger dl LEFT JOIN dl_ledger_shift_status ss ...
```

**Provenance note, load-bearing for everything that follows.** The 16,348 provisional units are
**entirely one kind** — measured by exclusion type:

| Exclusion kind | Rows | Units |
|---|---|---|
| Ending counted but **shift never finalized** | **2,112** | **16,348** |
| Pending (no count entered yet) | 584 | 0 |
| Derived / zero-forced ending | 1 | 0 |

A pending row's quantity is `NULL`, so `SUM()` already ignored it — which is exactly why the old
"Official rows only" control, which filtered only `bal_end IS NULL`, changed the units total by
**zero**. The 16,348 units are not missing data: they are data that was **entered and not signed
off**. That is an operational habit, and it is the cheapest thing on this page to fix.

---

## 2. This is one branch's business, not a network

| Branch | Mode | Days | Products | Rows | Provisional | **Official units** | **Official value** |
|---|---|---|---|---|---|---|---|
| 8 Miputak | hybrid | 40 | 178 | 13,314 | 14.7% | **83,128** | **PHP 745,716** |
| 9 Rizal | hybrid | 5 | 178 | 990 | **64.4%** | 3,139 | PHP 43,669 |
| 10 General Luna | hybrid | 1 | 1 | 1 | 100% | 0 | 0 |
| 11 Obay | hybrid | 1 | 9 | 9 | 100% | 0 | 0 |
| 12 Katipunan | hybrid | 1 | 1 | 1 | 100% | 0 | 0 |
| 15 Fishport | hybrid | 2 | 6 | 7 | 100% | 0 | 0 |
| 18 RIZAL-COMMIS | self_managed (commissary) | 2 | 47 | 79 | 100% | 0 | 0 |

**Branch 8 is 94.5% of the official value.** Branches 10, 11, 12, 15 and 18 have **no official
sales at all** in a 41-day window — every row they have is provisional or unmetered.

**Business conclusions that follow, and the caveat on each:**
- No network-level statement in this module is a statement about the business. It is a statement
  about Miputak. A figure like "network conversion" describes one branch plus a rounding error.
- Branch 9's own figures rest mostly on unsigned work: of its **6,911 units with an ending, 3,772
  (54.6%) sit on unfinalized shifts**, and 64.4% of its rows are provisional. Its PHP 43,669 is the
  least reliable number on the page. Branch 8 is materially better: 12,576 of 95,704 units (13.1%).
- Five enabled branches recorded *something* and finalized **nothing**. Either they are dormant, or
  their operators are not closing shifts. The data cannot distinguish those two cases, and that
  distinction is the single most valuable thing to resolve next — see §7.

---

## 3. Where the money is — concentration is healthy, so the issue is not demand spread

| Rank | Product | Official value | Cumulative |
|---|---|---|---|
| 1 | BURGER/HOTDOG ORD. (PC) | PHP 88,880 | 11.3% |
| 2 | CHIFFON BIG | PHP 46,830 | 17.2% |
| 3 | MONGO/PEANUT/COCO/MAYO | PHP 42,495 | 22.6% |
| 4 | SLICED BREAD W/ SQUASH Big | PHP 33,605 | 26.8% |
| 5 | WHEAT BREAD | PHP 30,798 | 30.7% |

- **50% of official value sits in 12 products (10.2% of the 118 that sold).**
- **80% of value sits in 30 products (25.4%).**
- The remaining 88 products share 20%.

This is a textbook Pareto curve. **The commercial read is that a small, stable core carries the
business** — so the leverage is in availability and loss of those 30 products, not in assortment
breadth. It also means a single stock-out of product #1 (11.3% of value) costs more than the whole
bottom half of the catalogue combined.

*(`SELECT p.id, SUM(official amount) ... GROUP BY p.id ORDER BY amount DESC` then cumulative in PHP.)*

---

## 4. Loss — the largest controllable number, and the one the pages do not total

Recorded pullouts, by reason class (`dl_cashier_withdrawals.where withdrawal_type='pullout'`):

| Reason | Movements | Units | Classification |
|---|---|---|---|
| **spoilage** | 400 | **6,187** | Wastage — real loss |
| manual_adjustment | 69 | 1,006 | Returned saleable / correction |
| promo | 13 | 386 | Consumed at branch |
| other | 32 | 257 | Returned saleable |
| staff_meal | 5 | 50 | Consumed at branch |
| donation | 6 | 32 | Consumed at branch |
| encoder_omission | 1 | 5 | Returned saleable |
| damage | 2 | 2 | Wastage — real loss |

**6,189 units of recorded spoilage/damage against 86,267 official units sold = 7.2% of sales volume
lost to spoilage.** 400 separate movements, so this is a habitual, repeated loss rather than one
incident.

**The trap this exposes:** the production-efficiency view reports a **1.35% wastage rate** (119 units
wasted of 8,828 produced). Both numbers are correct and they describe different things — that 1.35%
is the *commissary's producer-side* loss, while 6,187 is *branch-side* spoilage recorded as pullouts.
The efficiency page explains the distinction in a note, but **no page states the 7.2%**, which is the
figure an owner would act on. Anyone reading only the efficiency panel would conclude loss is
negligible when it is roughly five times the number shown.

---

## 5. Production cannot yet be measured against sales — and the page now says so

| Figure | Value |
|---|---|
| Production ledger coverage | **4 days** (2026-10-08 → 10-11), branch 18 only |
| Rows / of which positive output / counted | 447 / 69 / 312 |
| Produced | 8,828 units |
| Dispatched | 12,547 units |
| Wasted | 119 units (1.35% of produced) |
| Production runs | 41 runs on **19 products**, 2026-10-08 → 10-10 |

Production recording began **2026-10-08**; sales span **41 days**. The consequence is concrete:

- **Produced (8,828) cannot be compared with sold (86,267).** They sit in different windows and
  different populations. Before this work that comparison produced a "sell-through" of
  1,162.38%; it is now structurally impossible for the page to show it.
- **Received stock (100,632) is not explained by recorded production (8,828).** The stock that moved
  in September was produced before production recording started. So the 41-day inflow figure is real
  but its *origin* is only partly traceable inside this window.
- The **1.35% wastage rate rests on 3 output days**. It is a measurement, not a trend, and it should
  not be quoted as one yet.

---

## 6. Stock flow, and a control signal worth attention

Posted receipts in the window (`dl_branch_receivings` + items, `status='posted'`):

| Origin | Docs | Days | Units |
|---|---|---|---|
| commissary (origin_id not stated) | 114 | 35 | 88,640 |
| commissary 18 | 8 | 2 | 8,288 |
| branch 18 | 3 | 2 | 2,609 |
| branch 9 | 5 | 3 | 852 |
| branch 15 / 8 / 10 / 11 | 9 | 7 | 243 |
| **Total received** | **139** | | **100,632 units** |

- **96.4% of received stock is commissary-sourced** (96,928 units); 3.7% is branch-to-branch transfer.
  **Zero receipts are local production.** So on this tenant, in this window, no branch produced
  anything it recorded — which is exactly why the old page's "Miputak: 0 produced" was an
  attribution lie rather than a measurement.
- **Add Stock adjustments: 678 rows, 24,176 units — 24% of the inflow.** Almost a quarter of stock
  entering the ledger entered as an operator correction rather than a delivery. That is a
  reconciliation ratio, not a performance one, and it is the number to watch: it says how much of
  the ledger's arrival data is manual.
- 114 receipts carry no `origin_id`. They are attributable because the destination's
  `assigned_commissary_id` identifies the source, and 229 no-DR deliveries explicitly declare
  `origin_type='commissary', origin_id=18`: **the owner's "no DR means local production" rule never
  fires on this tenant.**

---

## 7. What this data cannot answer — and what would be needed

Named explicitly, because an analysis that does not say this invites false confidence.

| Question an owner will ask | Answerable? | What is missing |
|---|---|---|
| Which products are profitable? | **No — and not close** | `dl_delivery_items.unit_cost_snapshot` and `dl_branch_receiving_items.unit_cost_snapshot` exist, but **every row is 0 or NULL**: measured, **0 of 3,209 delivery items and 0 of 2,433 receiving items carry a cost** (max value 0.00). `dl_products` carries no cost field, and several write paths insert a literal `0` (`handlers.php:4820, 4976`). So the schema has the *column* for cost and none of the *data*. **Margin is not computable, and cannot be made computable by a reporting change** — it needs cost capture at receiving. |
| How much did each branch make? | Partly | Value is computable for the 2 branches with official rows; the other 5 have none, and 19 enabled branches have no ledger rows at all. |
| Is production getting more or less efficient? | **Not yet** | 4 days of production data. Needs weeks of consistent entry before a trend exists. |
| Which production run produced the units that spoiled? | **No** | No lot/batch link from a retail sale or pullout to a production run. Sol reached the same conclusion independently ("unreachable: no immutable lot-level link"). This is a schema gap, not a reporting gap. |
| Was the September stock produced in-house or bought in? | **No** | Production recording starts 2026-10-08; anything received before that has no in-window production origin. |
| Are the five silent branches dormant or un-finalized? | **No** | Requires either finalization discipline or an operational signal outside this schema. This is the highest-value gap. |
| What is the real spoilage cause? | Partly | 400 spoilage pullouts are recorded with a reason but no cause, batch, or product-life field. |

---

## 8. What changed in the software, and why it matters to a reader of these numbers

The three defects this analysis works around were all **the page asserting something the data did not
support**:

1. **A blended efficiency ratio.** Destination sales were divided by source production over
   non-matching windows: 1,162.38% sell-through and −1,020.25% shrinkage. The two panels are now
   separate and are never divided by one another.
2. **A supplying branch shown as producing nothing.** Branch 8 was rendered "Produced 0 pcs" when the
   fact is that it is **supplied by RIZAL-COMMIS**. It now says so.
3. **An "Official rows only" control that removed nothing.** Measured: the units total was 102,615
   with it on or off. It now removes the 2,697 provisional rows and states the 16,348 units it
   excluded.

**The standing rule for anyone reading this module:** money is official-only, and units are
official-only when the basis control is on. If a figure is provisional-inclusive the page says so and
says by how much. If a denominator has no records, the page names the gap instead of printing a
number. Every one of those behaviours is held by an acceptance criterion that was proven RED before
the change and green after.

---

## 9. The three things to act on

1. **Finalize shifts.** 16,348 units (15.9%) and 2,112 rows sit on shifts that were counted but
   never signed off; branch 9 is 54.6% of its volume in that state and five branches are at 100%. Every analysis in this document is bounded by that, and
   it is the cheapest fix available — it is an operational habit, not a schema change.
2. **Capture cost if margin is wanted.** `unit_cost_snapshot` exists on `dl_delivery_items` and
   `dl_branch_receiving_items` but **every row is 0 or NULL** (measured: 0 of 3,209 and 0 of 2,433),
   and several write paths insert a literal `0`. Until cost is entered at receiving, the module can
   report volume and revenue but not profit — and no reporting change can fix that.
3. **Add lot attribution before attempting production-efficiency claims over a sales period.** With
   4 days of production data and no sale→run link, "production efficiency" is measurable only inside
   the production window. The page now says exactly that instead of implying more.

---

## 10. Independent adversarial review (Sol), and what it changed

Sol reviewed the analytics — full report at `docs/reviews/sol-analytics-review-2026-10-11.log` — with
verdict **FALSIFIED**: the analytics were **not** production grade at the time of review. Five of its
findings were real defects, each of which I reproduced with my own criterion before fixing it:

| Finding | What was wrong | Fixed |
|---|---|---|
| F2 | The Overview's per-branch units used the *pending* predicate while the **amount beside them** used the official one — two different populations in one card. It rendered **102,615 inclusive units labelled "official, finalized rows"** when the official figure is **86,267**. | ✅ units now 86,267; the card states its basis and the volume excluded |
| F6 | "Physical count 45,692" and "counted variance 5,465" were **sums of 312 daily count snapshots**. A closing count is a LEVEL, not a sum. Latest-per-product values: **22,216** and **3,957**. | ✅ `physical_end = 22,216` — matching Sol's independent figure exactly |
| F7 | Yield coverage read "0 of 178", counting every product with production, sales *or* receipts. Only **36** products produced. | ✅ denominator is the produced population (36) |
| F8 | **14 movement-only rows issued positive production suggestions** — a variance flag became expected demand on a zero average. | ✅ zero such rows; a row with no measured sales cannot be given a production number |
| F2b | The efficiency "Sold" card said "official + provisional sales units" while rendering the official figure. | ✅ the card states the basis it is actually rendering |

Sol also **CONFIRMED** the parts it could have broken: the SQL twin `dl_provisionalSqlExpr` and the PHP
predicate `dl_rowIsProvisional` **agree on all 14,401 rows** (0 disagreements — including NULL
`end_source`, missing shift rows, unfinished shifts and the derived ending); no other predicate
negates the twin in a way that reintroduces the NULL trap; the two efficiency panels do not double
count; and `dl_provisionalExclusion()` reconciles per branch.

### Still open — recorded, not papered over

These findings are **not** fixed. They are listed so no reader assumes otherwise.

1. **The forecast's "14-day" average divides only by days that HAVE official sales.** Most demand
   streams have 2, 5 or 11 sample days, not 14. Absent days are omitted rather than treated as
   no-sales or as unreported, so **the displayed average can overstate daily demand**. The row shows
   `sample_days`, but the window label implies more than the data supports. Resolving it needs a
   decision — absent days as zero-sales, or as missing reporting — and those describe different
   businesses that this data cannot currently tell apart.
2. **At network scope, received stock includes 3,704 units of internal branch-to-branch transfer**, so
   the conversion denominator is not purely external supply.
3. **Branch 18's "stock conversion 0.00%"** is arithmetically exact and operationally misleading: a
   commissary's role is not retail conversion, so a zero reads as failure when it is expected.
4. **Forecast stock has no as-of date.** It is the latest count *inside the history window*, so a valid
   count from the day before the window reads as zero while a 9-day-old count inside it is accepted.
   Two tenant-207 products are 9 days stale.
5. **`mixed` production/supply classification is a live branch with no data** on tenant 207, and
   classification is branch-wide rather than branch-product-wide, so a hybrid branch cannot be
   classified per product.
6. **Yield is entirely unavailable, not thinly covered:** all 41 production runs have
   `primary_input_qty = 0`, and **0 of the 36 produced products** has a batch basis configured. The
   page correctly renders `n/a`; making yield real requires run inputs and the migration-016 basis to
   be populated.

