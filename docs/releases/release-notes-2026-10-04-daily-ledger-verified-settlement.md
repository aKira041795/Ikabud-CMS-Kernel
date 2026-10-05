# Daily Ledger — Governed Day Lifecycle, Verified Settlement & Provisional Sales

> **Released:** 2026-10-04
> **Theme:** Entry is never blocked by an unfinalized day. Derived numbers are quarantined, not counted, and can only enter the ledger through a tagged, reversible, human-verified settle step.
> **Scope:** `modules/daily-ledger` (cashier + production/commissary sheets, overview analytics, reporting)
> **Previous:** [Kernel 6.2 / DiSyL 4.8 — Proof Program](release-notes-2026-08-23-kernel-6.2-disyl-4.8-proof-program.md)

---

## Executive Summary

This release closes a set of day-lifecycle gaps in the Daily Ledger and, more importantly, fixes a class of *silent number restatement* in the reports.

Before it, a closed day could not be reopened for correction, a day with no prior closing could not be advanced, a failed PM close surfaced only as a toast, and the reporting layer could move historical rows between the official and provisional totals without anything recording that it had done so. The last of these was the dangerous one: it changed published figures while the row's own label said otherwise.

**Delivered release facts:**
- a closed day can be reopened by an admin and then edited by that admin, or updated by the production user assigned to the branch
- a day may be advanced even when the prior day is unfinalized — entry is unblocked, and the gap is flagged to the admin and the operator instead of being refused
- unfinalized AM/PM shifts settle into counted sales only through `settle → verify → revert`, each transition tagged with provenance and each change audited
- two duplicate-guard suites that had asserted a deliberately superseded contract since 2026-09-22 now assert the current one: a *repeat* is recorded, a *replay* is refused
- pending data is **marked, never monetised**: a provisional money figure no longer surfaces on any admin surface, and every row whose figures are not final is visibly marked at the row
- `tests/daily-ledger/daily_ledger_production_controls_test.php` — 70 assertions covering the whole lifecycle — is the oracle for this release

---

## What's New

### 1. Reopen a closed day and correct it

`dl_deliberateReopenUnlocksEntryEdit()` gates entry editing on four conditions together:

1. the day status is `open`
2. `reopened_at IS NOT NULL` — the day was reopened deliberately, not merely left open
3. the shift is not finalized
4. `dl_accessibleBranchIds($actor)` contains the branch

Entry remains refused unless the reopen was deliberate, so a day that was never closed cannot be edited through this path. Every reopen writes an audit row.

Guard sites: `modules/daily-ledger/handlers.php` (beginning-qty and actual-ending-qty write paths).

### 2. Advance to the next day without a prior closing

`dl_priorPendingPmDay()` resolves the previous business date when it is still open and its PM shift is unfinalized, and exposes it to the template as `prior_pending_day`. The sheet renders a link to the outstanding day and a `#production-pm-flag` banner rather than blocking the next day's entry.

The rule the owner set and this release implements: **data entry is not hampered; the gap is flagged and notified.**

### 3. Close failures are explained, not toasted

`apiFinalizeProductionPmShift` no longer sets the finalized flag when active products are missing an ending. It returns:

```json
{
  "ok": false,
  "code": "PM_ENDING_MISSING",
  "error": "N active product(s) are missing a PM ending count.",
  "missing_products": [{ "product_id": 1, "name": "...", "sku": "..." }]
}
```

The client renders that list in a persistent panel, so an operator sees *which* products block the close instead of a message that disappears.

### 4. Settlement ladder — derived endings are quarantined, never counted

`dl_settleUnfinalizedRow()` is a pure function over the row's state:

| rung | condition | outcome |
|---|---|---|
| 1 | ending already recorded | left alone |
| 2 | movements exist | derived ending from movements |
| 3 | no movements | zero forced |

A settled row is written with `end_source` provenance (`derived-from-movements` / `zero-forced`), `end_settled_at`, and the derived ending is excluded from counted variance by the rewritten STORED generated column `calc_variance` on `dl_commissary_product_ledger`:

```sql
CASE WHEN end_source IN ('derived-from-movements','zero-forced') THEN NULL
     ELSE actual_end_qty - (...) END
```

Migration `074_add_ending_provenance.sql` adds `end_source`, `end_settled_at`, `end_verified_by`, `end_verified_at` to `dl_daily_ledger` and `dl_commissary_product_ledger`. It is guarded and rerun-safe, and deliberately backfills nothing.

### 5. `settle → verify → revert` — the owner's finality control

The owner's insight that resolved the irreversibility problem: **move the derived value, but let an admin verify it for finality.**

- `dl_settlePendingEndingsForShift($db, $branchId, $date, $shift, array $actor)` — `admin|supervisor|production_in_charge`
- `dl_verifySettledEndingsForShift(...)` — **`admin` only**
- `dl_revertSettledEndingsForShift(...)` — `admin|supervisor|production_in_charge`

Each runs in one transaction and writes exactly one audit row per changed row (`settle_derived_ending`, `verify_derived_ending`, `revert_derived_ending`). The settle UPDATE carries `WHERE id = :id AND <ending> IS NULL` and tests `rowCount()`, so a concurrent double-settle is a compare-and-swap, not a duplicate.

All three take an `array $actor` and check `dl_accessibleBranchIds($actor)` before writing. The branch arrives from the client, so without that check a supervisor could POST another branch's id and write derived endings into a ledger they are not assigned to.

### 6. Provisional vs official — one predicate, two consumers

`dl_rowIsProvisional(array $row)` in `helpers/reporting.php` is the single definition:

- `bal_end === null` → provisional
- `shift_status === null` → provisional **only when the shift is `PM`** — this preserves history: AM rows written before shift rows existed have a recorded ending and stay official (measured: 3,149 rows / 20,680 units)
- otherwise → `shift_status !== 'finalized'`

`dl_reportSalesData()` now derives the *bucket* from this same predicate as the row's `status_label`. It previously derived the bucket from the settlement ladder's `official` flag, which calls **any** unfinalized shift "not official". Those two disagree by design, so the bucket silently restated exactly the rows the predicate exists to protect. A full-suite run caught it (`daily_ledger_reporting_test` 73/77 → 76/76).

### 7. Two stale duplicate-guard oracles corrected

`daily_ledger_offline_pwa_test.php` and `daily_ledger_shift_target_test.php` asserted the **pre-`a971e41c`** duplicate-guard contract, in which the guard keyed on a withdrawal's *content*.

Commit `a971e41c` (2026-09-22) deliberately reversed that to *submission* identity, for a reason its message states plainly: content cannot distinguish a replay from a legitimate repeat — *"this request arrived twice"* and *"27 more arrived, same reason, same person"* are byte-identical without a per-submission key. It records that content-identity **"has produced this same bug three times: box-vs-pcs (057), AM-vs-PM (059), and now a taken-back entry keeping its claim"**, and it added permanence guards against a revert: a minted identity when no key is sent, a frozen fingerprint value, and a source assertion that *both* add paths use the minted identity.

Chronology (`git merge-base --is-ancestor`, not reading): `fa84a2e8` (2026-08-15, content guard) → `0889ac93` (2026-09-14, shift-aware) → `a971e41c` (2026-09-22, reversal). Neither suite is an ancestor of the reversal, so both had been red since 2026-09-22 — the **assertion** was stale, not the code.

Live data settles it: among 1,141 withdrawals in tenant `baronledger` there are **4 content-duplicate groups**, i.e. operators legitimately record identical withdrawals on the same day and shift. A content guard would reject behaviour that genuinely happens.

The two suites now assert the current contract — an identical line on a *new* submission is recorded; a replay of the *same* submission is refused — each carrying the reasoning inline so it cannot rot back, and each proven falsifiable by mutation. `daily_ledger_handlers_test.php` (229/229, **unmodified**) is the guard that keeps the deliberate design intact.

---

### 8. Pending data is marked, never monetised

Owner directive: *"the provisional sales amount should not surface again. what the admin sees is the actual, correct amount thus pending sales are not included. my point is, provisional sales amount confuses accounting."*

The authoritative amount was already correct — `dl_reportSalesData()` buckets rows with the canonical predicate, so the official total already excluded provisional. The defect was that a **second** money figure was put in front of the admin:

```
Provisional (pending ending / unfinalized PM): 6 PHP 150.00
```

which reads as revenue and invites double counting. It is now gone from every surface that carried it:

| surface | now says |
|---|---|
| Sales footer | `Pending (not counted yet): 6 units — no amount is shown because these figures are not counted in the official total.` |
| Dashboard (×3) | `+ N units pending (not counted yet) — no amount is shown …` and `+ N units pending (not counted)` per branch and per card |
| Reports header | `Pending (not counted yet): N units — no amount is shown …` |
| Reports row sets + `<th>` | `Pending Units (not counted)`; the provisional amount column is dropped |
| Report column definitions | `provisional_amount` removed, so the CSV columns lose it |
| PDF / export totals | `provisional_amount` unset, so a printed report cannot show it |

Each replacement states **why** no amount is shown, so the figure is not quietly re-added later.

Provisional **units** are deliberately kept and relabelled *not counted*: units are not money, and they tell an operator how much is outstanding. The provisional **bucket** stays in `helpers/reporting.php`, because the settlement workflow and the official totals depend on that classification — only its display was removed.

**Row markers.** `dl_salesRowStatusLabel()` is now the single labeller and delegates to `dl_rowIsProvisional()`, so a row's badge and the totals bucket cannot drift apart. The template previously re-derived its own narrower condition, which could **never** fire when the ending was missing — so **942 rows with `bal_end IS NULL`** were counted as provisional and carried no marker at all. Two decisions taken on review:

- **one badge per row** — the base rendered "Pending count" on both the shift and the sales cell, which reads as noise across a 70-row table. The shift cell carries the badge; the sales cell keeps its tint and flags itself with `title`/`aria-label` on the `<td>` itself, which is what a screen reader announces;
- **uncounted figures render an explicit `—`** — before, Bal End, Sales and Amount rendered *empty*, which reads as a rendering failure.

---

## Migration Notes

| migration | change | rerun-safe |
|---|---|---|
| `074_add_ending_provenance.sql` | provenance columns on `dl_daily_ledger`, `dl_commissary_product_ledger`; rewrites the STORED generated `calc_variance` | yes — guarded `information_schema` checks, no backfill |

Apply with:

```bash
php ikabud tenant:migrate <tenant_id|tenant_key|domain> daily-ledger
```

**Bluehost / MySQL 5.7 audit (measured 2026-10-04, this tree):**

| rule | result |
|---|---|
| window functions (`OVER()`, `ROW_NUMBER`, `RANK`, `LAG`, `LEAD`) | 1 match, a comment documenting the constraint — **0 real** |
| CTEs (`WITH … AS (`) | **0** |
| `JSON_TABLE` / `EXCEPT` / `INTERSECT` | 5 matches, all `@mysql57-compat` comments — **0 real** |
| `CREATE TABLE` with `ENGINE=InnoDB` | 510 statements vs 506 clauses; the 4 apparent gaps are 6 comment lines plus one `CREATE TABLE` executed against `sqlite::memory:` in `kernel/Workbench/Governance/AuthorityCensus.php` — **0 real gaps** |
| FK column types | not changed by this release (074 adds no foreign keys) |

---

## Verification

| surface | result |
|---|---|
| `daily_ledger_production_controls_test.php` | **70/70** — the lifecycle oracle |
| `daily_ledger_reporting_test.php` | 76/76 |
| `daily_ledger_overview_test.php` | 104/104 |
| `daily_ledger_shared_account_latest_holder_test.php` | 28/28 |
| `daily_ledger_routes_test.php` | 82/82 |
| `daily_ledger_receive_offline_guard_test.php` | 11/11 — includes a revert-failing case |
| `daily_ledger_offline_pwa_test.php` | 109/109 |
| `daily_ledger_shift_target_test.php` | 38/38 |
| `daily_ledger_handlers_test.php` | 229/229 — pins the submission-identity design; **unmodified** by this release |
| `php ikabud module:validate daily-ledger` | passed |
| migration 074 applied twice | second apply clean |

Browser journeys (Playwright, chromium):

| spec | covering |
|---|---|
| `daily-ledger-reopen-edit.spec.js` | reopen a closed day and edit it |
| `daily-ledger-nextday-entry.spec.js` | advance without a prior closing |
| `daily-ledger-close-failure-guidance.spec.js` | PM close with missing endings shows the list |
| `daily-ledger-settled-endings.spec.js` | settle → verify → revert through the UI |
| `daily-ledger-prior-ledger-link.spec.js` | the prior-pending-day link resolves |

Seed the browser environment before running them:

```bash
php database/seeds/browser_environment.php
```

---

## Known Limits

- **Pending units are shown but never monetised.** A row or bucket that is not counted yet contributes **no amount** to any display; it contributes only a unit count under a "not counted" label. If you want the unit figures gone too, that is a display change in the same three templates and nothing else.
- **A pending row shows `—` for Bal End, Sales and Amount.** That is deliberate: there is no counted figure to show. The row is still counted inside the *provisional* bucket, so the row count and the excluded-from-official explanation above the table are what tell you it is outstanding.
- **Withdrawal dedup is by submission identity, not by content** (`a971e41c`). A client that retries a withdrawal **without carrying a stable key will record a second row** — that is the deliberate contract, not a defect. Offline clients must carry the queued op's identity across retries; `dl_withdrawalSubmissionId()` mints one when a caller sends none, and the mint is per submission. Do not "fix" a blocked-but-legitimate entry by adding another content field to the fingerprint — that is the treadmill `a971e41c` replaced.
- **Settlement rung 3 is unwired.** The ladder has a "no movements → zero forced" rung, but the carry audit records a row *count*, not product ids, so there is no per-product candidate list to settle from. Rung 3 is implemented in the pure function and not reachable from the UI.
- **`calc_variance` is `NULL` for settled endings by design.** A settled row contributes to the provisional bucket; entering the official totals requires the admin verify step. This is the intended control, not a defect — but any external report reading `calc_variance` directly must handle `NULL`.
- **`dl_maybeAutoFinalizeCommissaryPmShift` runs on render.** It early-returns when `reopened_at` is set, so a reopened day is not silently re-finalized by viewing it. The coupling of a write to a read is pre-existing and unchanged by this release.
- **`apiCloseDay` never finalizes the AM shift and never clears `reopened_at`.** Pre-existing and unchanged; recorded here because the settlement ladder now makes the consequence visible.
