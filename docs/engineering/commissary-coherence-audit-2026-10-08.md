# Commissary coherence audit — one owner per number

**Date:** 2026-10-08 · **Scope:** the five Commissary tabs (`Daily Sheet`, `Inventory`, `Deliveries`,
`Pullouts`, `Summary`) and the tables they read.
**Trigger:** owner observation — *"these commissary tabs … are not well meshed/integrated, wired with
each other."* **Verdict: the observation is correct, but the cause is not carelessness — it is an
unverified read model plus a formula expressed three times.**

---

## 1. What the audit found

### 1.1 The same word means two different sources

| tab | "dispatched" is read from |
|---|---|
| **Daily Sheet** | `dl_deliveries` + `dl_delivery_items`, filtered `origin_type='commissary'`, `destination_type='branch'`, `status='posted'` (`handlers.php:857-888`) |
| **Inventory** | `dl_commissary_product_ledger.dispatched_qty` — a denormalised projection (`handlers.php:18980` query) |

Both are real and both are maintained. **Nothing asserts they agree.** That is the whole of the
non-meshing: not two broken sources, but two independent derivations with no reconciliation.

### 1.2 The balance formula exists in THREE places

```
beg + produced − dispatched − wastage
```

| where | form |
|---|---|
| `dl_commissary_product_ledger.calc_variance` | DB **generated column** (migration 064) |
| Inventory tab | re-expressed in its `SELECT` (`handlers.php:18980` block) |
| Daily Sheet | re-expressed in **PHP** (`book_balance`, `handlers.php:19330`) |

A formula written three times has three chances to drift. It already has: `handlers.php:19326-19329`
records a past occasion when the sheet's suggestion over-read while the variance stayed correct —
*"which read as a contradiction on screen."*

### 1.3 The architecture is actually sound — the gap is verification

This is **not** an accident to be ripped out:

- `dl_production_movements` — production write model (with `movement_uuid`, `client_op_id` for idempotency)
- `dl_deliveries` / `dl_delivery_items` — dispatch write model
- `dl_commissary_product_ledger` — **read model / projection** (`beg_qty, produced_qty, dispatched_qty,
  wastage_qty, actual_end_qty, calc_variance`)
- `dl_delivery_ledger_effects`, `dl_consignee_ledger_effects` — **effects ledgers** carrying
  `effect_status` (`applied`/`reversed`) and `reverse_before_*` / `reverse_after_*` snapshots

Reversal is handled properly: voiding a delivery calls `dl_reversePostedDeliveryCommissaryLedger()`
(`handlers.php:529, 593, 1233`), which reverses the projection and records the before/after quantities
(`handlers.php:3603`).

**So the projection is a deliberate read model with a reversal path. The defect is that no check exists
that the read model still equals the write model.**

### 1.4 Twelve tables carry overlapping quantity numbers

```
dl_daily_ledger              beg_bal, addtl, withdraw, bal_end, sales
dl_consignee_ledger          beg_bal, addtl, withdraw
dl_commissary_product_ledger beg_qty, produced_qty, dispatched_qty, wastage_qty, actual_end_qty, calc_variance
dl_commissary_ledger         beg_bal, calc_variance            (RAW MATERIALS — see §3)
dl_selling_account_ledger    beg_qty                            (B2B selling accounts)
dl_production_movements      quantity
dl_delivery_items            quantity
dl_delivery_ledger_effects   quantity
dl_consignee_ledger_effects  quantity
dl_cashier_withdrawals       quantity
dl_pos_sale_items            quantity
dl_variance_flags            addtl, withdraw
```

Not all of these are duplication — several are legitimately different subjects (POS sales, cashier
withdrawals, B2B accounts). The audit's point is that **"quantity" appears on twelve tables and nothing
states which one owns which number.**

---

## 2. One owner per number — the map this audit exists to produce

| number | authoritative owner | read by | re-derived by |
|---|---|---|---|
| **Produced** | `dl_production_movements` | projection, Daily Sheet | — |
| **Dispatched** | `dl_deliveries` + `dl_delivery_items` (`status='posted'`) | Daily Sheet (matrix) | **projection** (`dispatched_qty`) → Inventory |
| **Wastage** | `dl_production_movements` (wastage movements) | Daily Sheet | **projection** (`wastage_qty`) → Inventory |
| **BEG** | `dl_commissary_product_ledger.beg_qty` **or** `dl_daily_ledger.beg_bal` — *ambiguous, see §4* | both tabs | `dl_fetchCommissaryBeginningSuggestions()` |
| **Ending / Actual balance** | `dl_commissary_product_ledger.actual_end_qty` | Inventory, Daily Sheet | — |
| **Variance** | `dl_commissary_product_ledger.calc_variance` (generated) | Inventory, Daily Sheet | **PHP re-expression** (Daily Sheet) |
| **Consignee custody** | `dl_consignee_ledger` | Consignees sub-tab | — |
| **Branch stock** | `dl_daily_ledger` | branch ledger | — |

**Rows with two owners are the risk surface.** Today that is *Dispatched*, *Wastage*, *BEG* and
*Variance*.

---

## 3. Naming collision — not a duplicate definition

Two different tables each own a `calc_variance`, with **different types and different subjects**:

| table | type | formula | subject |
|---|---|---|---|
| `dl_commissary_ledger` | `decimal(10,3)` | `actual_end_bal − (beg_bal + delivery_qty − used_qty)` | **raw materials** |
| `dl_commissary_product_ledger` | `int` | `actual_end_qty − (beg_qty + produced_qty − dispatched_qty − wastage_qty)` | **finished products** |

These are correct for their own tables. The problem is purely the shared name: a reader cannot tell from
the identifier which subject a variance belongs to. Worth renaming, not rewriting.

**This audit's own correction:** an initial reading of the migrations concluded "two formulas for the
same column", which was wrong — they are different tables. Confirmed against the live schema, not the
migration text.

---

## 4. Open ambiguity — BEG

`BEG` on the Daily Sheet comes from `dl_fetchCommissaryBeginningSuggestions()` (the carry from the
previous ending), while `dl_commissary_product_ledger.beg_qty` also stores a beginning. Whether these are
the same value in all cases (e.g. after a shift change, a reopen, or a derived ending) is **not
established by this audit** and should be resolved before any consolidation work.

Note `handlers.php:19302-19304`: *"An absent row is an unrecorded zero. Keep the preceding ending in its
own data attribute; rendering must never apply or persist the carry."* That is a deliberate separation of
*suggestion* from *recorded* — it must not be collapsed.

---

## 5. Recommendation

**Do not consolidate the tabs first.** The tabs are a symptom; the missing owner is the cause. Order:

1. **Add a reconciliation assertion** — a test/diagnostic that, for a given commissary and date, compares
   `dl_commissary_product_ledger` against the same figures derived from the movement tables
   (`dl_production_movements` + `dl_deliveries`). It should FAIL loudly on any difference. This is the
   single highest-value item: it converts "nothing asserts they agree" into "a disagreement is
   impossible to miss", and it would have caught the historic on-screen contradiction.
2. **Resolve the `BEG` ambiguity** (§4) — one owner, written down.
3. **Collapse the three formula expressions to one** — make the Daily Sheet and Inventory both read the
   projection's computed value, or both derive from movements. Pick one; do not keep three.
4. **Rename the raw-materials variance** (§3) so the two subjects are distinguishable by name.
5. **Only then** consider meshing the tabs (shared filters, cross-links, a single balance concept).

Steps 1–4 are small, bounded, and testable. Step 5 is the one that risks the owner's UI, and it is the
one to do LAST, not first.

---

## 5a. Addendum — production movements have no business date

Measured from the live schema (read-only):

`dl_production_movements` columns:
```
id, movement_uuid, client_op_id,
movement_type  enum('withdrawal','output','reverse')
flow_mode      enum('legacy','production','commissary')   default 'production'
destination_branch_id, product_id, shift enum('AM','PM'),
quantity, dr_number, override_reason, reference_movement_id,
source_payload json, created_by_id, created_by_role, created_at
```

**There is no `ledger_date`.** The projection (`dl_commissary_product_ledger`) is keyed on
`(commissary_branch_id, product_id, ledger_date, shift)`, so its day buckets must come from somewhere
other than the movement itself — `created_at`, or the request context at write time.

**Why this matters for reconciliation (item 1):** a reconciliation that groups movements by business day
cannot do so from the movement row alone. If a movement is entered late against a previous business day,
the movement and the projection can be bucketed into different days. This must be resolved as part of
item 1, not assumed away.

**Also noted, NOT verified:** `movement_type` includes `withdrawal`, which may mean a dispatch writes both
a `dl_deliveries` row *and* a `dl_production_movements` withdrawal row — two records of one movement.
If so, the reconciliation must join them rather than count them twice. This was **not** confirmed: it
would require tracing the dispatch write path, and both tables are empty in the current test data.

**Test data state:** both `dl_production_movements` and `dl_commissary_product_ledger` have **no rows for
2026-10-07**, which is consistent with the sheet's own message *"No products with remaining stock. Record
production output first."* The reconciliation (item 1) will therefore need its own fixture rather than
relying on existing data.

---

## 6. What this audit did NOT establish

- Whether the projection is currently **in sync** — no reconciliation query has been run against live
  data. That is item 1 above, and it is deliberately first.
- The full read path of *Pullouts* and *Summary* (their tables were not traced).
- Whether `dl_variance_flags` (`addtl`/`withdraw` columns) shadows or duplicates the variance concept.
- MySQL 5.7 behaviour of any proposed reconciliation query — INSPECTION-ONLY (no local 5.7 server).

---

## 7. Addendum — corrections after the reconciliation was actually built (2026-10-08)

Item 1 (the reconciliation assertion) was built and the audit's own claims were tested against
**data** rather than **schema**. Two of them were wrong. Both errors had the same cause: reading
a table's *shape* and inferring its *role*.

### 7.1 Correction — tables named as write models are EMPTY

| table | this audit called it | actual rows (tenant 207) |
|---|---|---|
| `dl_production_movements` | "the production write model" (§1.3) | **0** |
| `dl_commissary_ledger` | owns the raw-materials `calc_variance` (§3) | **0** |
| `dl_production_runs` | — | **0** |

The live dispatch write model is `dl_deliveries` (**132**) + `dl_delivery_items` (**2195**).

**Consequence for the audit's recommended order:** a four-input reconciliation is *impossible*.
`beg`, `produced` and `wastage` have **no populated write model**, so they cannot be derived
independently. **Only `dispatched_qty` is reconcilable.** Item 1 was therefore delivered for
`dispatched_qty` alone, not for the whole balance.

Also settled: no `dl_production_movements` withdrawal row is written alongside a dispatch — that
table is empty, so the double-count risk flagged in §5a does not exist in this data.

### 7.2 Correction — item 4 (rename the raw-material `calc_variance`) is DROPPED

§3 said it was "worth renaming, not rewriting". It is not worth doing at all: the column lives on
`dl_commissary_ledger`, an **empty table nothing writes to**. Renaming a generated column on
MySQL 5.7, with no local 5.7 server to test against, is pure risk for a naming concern that does
not manifest (call sites already qualify it, e.g. `cpl.calc_variance`). The two names are
distinguishable by their table. **Item 4 is withdrawn.**

### 7.3 The projection is sparse here — and that is expected test state

Measured while building item 1:

- 43 dates carry posted deliveries; **only 1 has a projection row** (2026-10-07)
- **42 dates carry 95,437 units of departures with no projection row at all**
- only **1 of 132** posted deliveries ever produced a `dl_delivery_ledger_effects` row, so
  `dl_applyPostedDeliveryCommissaryLedger()` (6 call sites) is newly wired and the rest predate it

**Owner ruling (2026-10-08): do NOT backfill.** Tenant 207 is a **test server** and its database
is a **restore from a live backup**, so a sparse or absent projection is the normal condition of
test data rather than a production anomaly. The 95,437 figure must **not** be read as a defect in
production.

**Why this makes the `unrecorded` bucket load-bearing rather than tidy:** if the reconciliation
reported those rows as mismatches, then on a test DB restored from a live backup it would emit
~95k units of noise **on every restore**, and be distrusted or weakened within days — the same
fate the NULL-shift false positive would have met. The bucket is what makes the reconciliation
usable against test data at all.

**Consequence to expect:** on a *fresh* restore the projection may be entirely empty, giving
`checked = 0` and making the depletion gate FAIL with *"no projection rows to compare"*. That is
the intended behaviour — a run that compared nothing may not report success — and is exactly the
vacuous-pass defect repaired above. It is **not** a broken gate and must not be "fixed" by
restoring a `$rows === [] ||` escape.

### 7.4 §1.2 restated — the formula was in three places; the *consequence* was narrower

§1.2 counted `beg + produced − dispatched − wastage` in three places. Building item 1 showed the
third place (the Daily Sheet) is **not** a duplicate of the projection: the Sheet reads
`dl_commissaryDepartedQtyByProduct()` directly, so it is already correct even where the
projection is absent. Collapsing the three (recommended order item 3) is therefore a
**maintainability** change, not a correctness one — and it remains the lowest-value remaining
item.

