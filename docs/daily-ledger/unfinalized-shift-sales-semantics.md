# Unfinalized shift sales and ending semantics

This is an analysis of the current implementation, not a behaviour change. “Recorded zero” means an ending column contains `0`; “pending” means it contains SQL `NULL`. Those states are observably different in both sheets and at finalization (`modules/daily-ledger/handlers.php:2187-2205`, `modules/daily-ledger/handlers.php:4517-4535`, `modules/daily-ledger/handlers.php:16943-16963`).

### Q1 — Rule 1: all cells zero means sales zero

**Verdict: HOLDS-WITH-CONDITIONS.** It holds only when every relevant quantity was actually recorded, especially an ending of `0`, rather than merely rendered/defaulted as zero. `dl_computeSalesValue(0,0,0,0)` is zero, while a `NULL` ending returns `NULL`; the SQL expression has the same distinction (`modules/daily-ledger/handlers.php:2187-2205`; Evidence, “helper and SQL mirror”). Cashier rows render missing beginning/movement values with `COALESCE(...,0)` but preserve `bal_end` as nullable, so a screen full of visible zeroes is not evidence that an ending was counted (`modules/daily-ledger/handlers.php:5947-5955`).

The safe condition is therefore: a row exists; `beg_bal`, `addtl`, and `withdraw` are recorded/known zero; `bal_end IS NOT NULL` and equals zero; and any claimed previous-day zero is itself a recorded ending, not an absent row. Cashier PM finalization explicitly treats a missing row or `bal_end IS NULL` as incomplete (`modules/daily-ledger/handlers.php:4517-4535`). Production applies the equivalent gate to `actual_end_qty` (`modules/daily-ledger/handlers.php:16943-16963`).

If a pending all-zero-looking cashier row were changed from `NULL` to recorded `0`, its computed contribution would be zero, so **official and provisional unit/amount totals change by exactly 0 for that row**. Its semantic/report state would nevertheless change from `pending ending` to `official` for AM or `provisional` for open PM, and it would cease blocking finalization (bucketing: `modules/daily-ledger/helpers/reporting.php:164-168`; gate: `modules/daily-ledger/handlers.php:4517-4535`). If the zero assumption is factually wrong because stock or an ending count was omitted, the database contains no physical count from which to quantify the error; a physical count would settle it.

### Q2 — Rule 2: non-zero pending row gets sales zero and a derived ending

**Verdict: DOES-NOT-HOLD.** Current cashier behaviour deliberately keeps sales `NULL` when the ending is absent, and SQL aggregates therefore receive `NULL`, not zero (`modules/daily-ledger/handlers.php:2187-2213`; Evidence). Defining `ending = beginning + additional - withdrawal` is not merely displaying zero sales: it stores the book balance as if it were a physical count.

Consequences of doing that are measurable from the current formulas:

* **Variance and stock accuracy:** cashier variance recomputation skips a `NULL` ending. With the proposed derived ending, expected ending equals recorded ending, so neither an ending-over-supply variance nor a negative-raw-sales variance is raised (`modules/daily-ledger/handlers.php:4603-4612`, `modules/daily-ledger/handlers.php:4703-4717`). Production already distinguishes `book_balance = beg_qty + produced_qty - dispatched_qty - wastage_qty` from `actual_end_qty` (`modules/daily-ledger/handlers.php:2846-2851`). Its generated variance is `actual_end_qty - book_balance`, confirming that substituting book balance for actual count forces variance to zero (`modules/daily-ledger/database/migrations/064_add_production_sheet_balances.sql:5-6`, `modules/daily-ledger/database/migrations/064_add_production_sheet_balances.sql:39-42`).
* **Clamp:** real cashier sales uses `max/GREATEST(0, …)`. The proposal always supplies an ending that makes the raw result exactly zero, bypassing both positive sales and the evidence of an over-reported ending that the clamp would otherwise reduce to zero (helper/SQL: `modules/daily-ledger/handlers.php:2187-2205`; variance preserves the negative raw value: `modules/daily-ledger/handlers.php:4708-4717`; Evidence shows the clamp).
* **Carry:** the manufactured ending becomes eligible as a source. Cashier carry selects recorded AM/prior endings and creates a new row with that value (`modules/daily-ledger/handlers.php:5864-5896`). Production suggestions select only non-NULL `actual_end_qty`, and the carry validates against that source (`modules/daily-ledger/handlers.php:2334-2377`, `modules/daily-ledger/handlers.php:2432-2442`). Thus a derivation could become tomorrow's apparent observation.
* **Buckets:** an open PM row with a non-NULL derived ending remains provisional, but contributes zero; an AM row becomes official zero even when its shift is open. A NULL ending is instead labelled `pending ending` (not a numeric provisional sale) (`modules/daily-ledger/helpers/reporting.php:164-168`; Evidence).
* **Audit integrity/finalization:** both finalizers use ending non-nullness as proof of completeness (`modules/daily-ledger/handlers.php:4517-4535`, `modules/daily-ledger/handlers.php:16943-16963`). Writing a derivation into the count column therefore falsely satisfies a count gate. Production count writes are explicitly audited as `save_commissary_product_count` with `actual_end_qty` (`modules/daily-ledger/handlers.php:17375-17390`), so silently storing book balance through that path would describe a derivation as a count.

A derived ending is usable only as a separately labelled, non-persisted **DERIVATION** (for example, “book/expected ending”), never as `bal_end` or `actual_end_qty`. Whether physical sales occurred while stock remained on hand cannot be determined from movements alone; an independent ending count is the evidence that settles it.

### Q3 — Rule 3: next non-zero beginning is the pending ending

**Verdict: HOLDS-WITH-CONDITIONS.** The inference is valid only when the next beginning is an independent physical observation and its provenance proves that. It is circular when the beginning was copied from the preceding ending.

For production, provenance is recoverable from retained audit data: a carried row's per-row `save_commissary_product_beg` audit payload includes `source: carry_forward`, whereas a normally entered beginning audit contains `beg_qty` without that source (`modules/daily-ledger/handlers.php:2463-2472`, `modules/daily-ledger/handlers.php:17315-17335`; Evidence shows both records). The ledger row itself has no provenance column; the audit must remain available. A preceding `NULL` ending is not suggested and cannot be carried: the probe omitted that product and the attempted batch was rejected (Evidence). Only positive values are accepted by the carry validator (`modules/daily-ledger/handlers.php:2432-2442`).

For cashier, automatic carry creates only a previously nonexistent row from a non-NULL positive ending (`modules/daily-ledger/handlers.php:5864-5905`) and writes one `auto_carry` audit containing only the first 50 product IDs (`modules/daily-ledger/handlers.php:5929-5938`). Therefore provenance **cannot be established reliably for every cashier beginning from the ledger row**, especially beyond that 50-product audit slice. To make the inference safe, the system would have to record per-row immutable provenance (`manual_count` versus `carry_forward`) and the source row/date/shift for both sheets.

Even with provenance, inference must be reversible: present the independent next beginning as a candidate for the missing prior ending, retain the original `NULL`, and require an explicit audited confirmation before writing a physical ending. A carried copy must never be used for the inference.

### Q4 — Does the same analysis apply to cashier and production?

The **NULL-versus-recorded-zero principle and the anti-circularity rule apply to both**, but the data models and “sales” meaning do not.

| Concern | Cashier sheet | Production sheet |
|---|---|---|
| Table/columns | `dl_daily_ledger`: `beg_bal`, `addtl`, `withdraw`, `bal_end`, stored/recomputed `sales` (`modules/daily-ledger/handlers.php:4418-4438`) | `dl_commissary_product_ledger`: `beg_qty`, `produced_qty`, `dispatched_qty`, `wastage_qty`, `actual_end_qty`, generated `calc_variance`; book balance is movement-derived (`modules/daily-ledger/handlers.php:2846-2851`, `modules/daily-ledger/database/migrations/064_add_production_sheet_balances.sql:39-42`) |
| Formula | sales = max(0, beg + addtl − withdraw − ending), or NULL if ending is NULL (`modules/daily-ledger/handlers.php:2187-2205`) | No cashier-style sales field. Inventory book balance = beg + produced − dispatched − wastage; variance compares actual ending to book balance (`modules/daily-ledger/handlers.php:2846-2851`) |
| Editors | Save endpoints admit cashier/supervisor/admin; cashier date access is current date plus a narrow prior-pending-PM window (`modules/daily-ledger/handlers.php:4570-4593`, `modules/daily-ledger/handlers.php:8269-8281`) | Production PM finalization admits admin/production-in-charge; beginning/count save handler also uses those production paths and enforces branch access (`modules/daily-ledger/handlers.php:16925-16936`, `modules/daily-ledger/handlers.php:17299-17314`) |
| Finalized gate | Every field save calls `dl_assertShiftMutable`; PM finalization requires every active product's `bal_end` (`modules/daily-ledger/handlers.php:8386-8401`, `modules/daily-ledger/handlers.php:9149-9172`) | Beginning and count saves call the same shift mutability guard; production PM finalization requires every active product's `actual_end_qty` (`modules/daily-ledger/handlers.php:2831-2836`, `modules/daily-ledger/handlers.php:2874-2882`, `modules/daily-ledger/handlers.php:16941-16966`) |

Thus Rules 1–3 can govern **ending evidence** in both sheets, but applying a “sales = 0” rule to production is a category error: production reports produced/dispatched/wastage/remaining totals, not cashier sales (`modules/daily-ledger/handlers.php:16275-16289`).

### Q5 — Why and where does provisional sales exist?

“Provisional” is derived at report time, not stored as a sales value. The report computes:

`pending || (shift === 'PM' && shift_status !== 'finalized')`

then labels a NULL ending `pending ending`, otherwise labels that open PM row `provisional`, and sends it to the provisional totals (`modules/daily-ledger/helpers/reporting.php:126-168`). The probe measured an open PM row with recorded ending/sales as `provisional`, a pending PM as `pending ending`, and an open AM row as `official` (Evidence). Official/provisional totals are kept separate in aggregate SQL too (`modules/daily-ledger/handlers.php:10171-10178`, `modules/daily-ledger/handlers.php:10205-10213`). This prevents an unsigned PM result from inflating official sales while retaining it for operational visibility.

The AM asymmetry is literal: shift status is consulted only when `shift === 'PM'` (`modules/daily-ledger/helpers/reporting.php:164-166`). Comments call this “unfinalized manual PM” (`modules/daily-ledger/handlers.php:10171-10178`), but no cited code or probe establishes the product rationale for excluding unfinalized AM. Whether that is deliberate policy or a gap is an **open product question**; a requirement or change-history decision for AM finalization would settle it.

### Q6 — Minimal safe recommendation

1. **Do now without inventing numbers:** retain `NULL` endings as pending; accept zero sales only from an explicitly recorded zero ending; continue to show book/expected ending as a read-only derivation. Never overwrite `bal_end`, `actual_end_qty`, or an independently typed next beginning. This is the semantics already enforced by the formulas and finalization gates (`modules/daily-ledger/handlers.php:2187-2205`, `modules/daily-ledger/handlers.php:4517-4535`, `modules/daily-ledger/handlers.php:16943-16963`). Verification cost for leaving it unchanged is the existing integration surface plus the probe below: no product change.
2. **Smallest useful product change:** add a read-only “expected ending / sales pending” hint to both sheets for non-zero rows whose ending is NULL. It computes the movement balance but does not save it or satisfy finalization. Verification cost: two rendering/browser assertions (cashier and production), two integration assertions that no ledger/audit write occurs, and regression assertions that finalization still reports the missing ending and report totals/labels remain unchanged.
3. **Before implementing Rule 3 automatically:** add per-row beginning provenance and source identity to both models/audits; cashier's current batch audit is insufficient for all products (`modules/daily-ledger/handlers.php:5929-5938`). Verification cost: a migration/backfill policy, save/carry integration tests for both sheets, audit-retention tests, and tests proving manual values are never overwritten. Historical rows without provenance must remain “unknown,” not inferred.

No current evidence supports changing product behaviour to Rules 1–3 beyond those conditions.

## Evidence

Probe command (throwaway file outside the repository):

```console
$ php /tmp/probe_unfinalized_sales.php
== helper and SQL mirror ==
{"helper_null_end":null,"helper_recorded_zero_end":11,"helper_overreported_end_clamped":0}
== report bucketing ==
{"shift":"AM","product_id":99870,"bal_end":4,"sales":6,"shift_status":"open","status_label":"official"}
{"shift":"PM","product_id":99870,"bal_end":4,"sales":6,"shift_status":"open","status_label":"provisional"}
{"shift":"PM","product_id":99871,"bal_end":null,"sales":null,"shift_status":"open","status_label":"pending ending"}
totals={"official_units":6,"official_amount":60,"provisional_units":6,"provisional_amount":60}
sql_null=NULL
sql_recorded_zero=11
sql_overreported_clamped=0
== production NULL carry and provenance ==
suggestions_with_p1_NULL={"99871":8}
carry_from_NULL="Carry rows no longer match the preceding positive endings; nothing was changed."
valid_carry={"carried":1,"duplicate":false}
{"action":"save_commissary_product_beg","entity_id":"99870-99871-2034-07-02-AM","new_data":"{\"source\": \"carry_forward\", \"beg_qty\": 8, \"idempotency_key\": \"probe-valid-carry-99870\"}"}
{"action":"carry_commissary_beginnings","entity_id":"probe-valid-carry-99870","new_data":"{\"date\": \"2034-07-02\", \"rows\": 1, \"shift\": \"AM\", \"idempotency_key\": \"probe-valid-carry-99870\"}"}
{"action":"save_commissary_product_beg","entity_id":"99870-99870-2034-07-02-AM","new_data":"{\"beg_qty\": 7}"}

══════════════════════════════════════
  RESULTS
  0/0 passed
  Assertions: 0

  Suite: probe-unfinalized-sales
  Time: 0.75s
══════════════════════════════════════
```

The probe bootstrapped `TestHarness::MODE_INTEGRATION` with `baronledger.test`, selected tenant 207, pushed the `daily-ledger` module context, used `$ctx->db()`, created isolated fixture IDs, and removed them in `finally`. It directly called both sales helpers, queried the SQL mirror against persisted rows, called `dl_reportSalesData`, called `dl_fetchCommissaryBeginningSuggestions`, attempted a NULL-source carry, and compared carried/manual audit payloads.

Remaining empirical limit: the probe establishes current mechanics, not whether management intends AM-open sales to be official. That requires an owner decision or historical requirement identifying the intended AM lifecycle.
