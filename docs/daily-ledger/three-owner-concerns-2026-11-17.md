# Three owner concerns — flow study and status (2026-11-17)

Owner's concerns, verbatim intent:

1. Add **OWN PRODUCTION** to commissaries so they have one entry, the same as branches.
2. A **consignee return to Baron needs no DR number** — the DR cannot be demanded from the account.
3. A **commissary dispatch that was keyed on the wrong product** must be correctable, and the
   correction must reach the branch that received it, without the branch re-keying anything.

---

## Concern 2 — consignee return without a DR ✅ DELIVERED

**Flow measured.** Two clients post to `/api/v1/deliveries/consignee-return`:

| Client | Sends `branch_id` | Needed a DR? |
|---|---|---|
| `admin/consignee-dispatch-report.disyl` (inline Save) | no | no |
| `cashier/receive_modal.disyl` (`submitReturn`) | yes | **yes** |

`apiRecordConsigneeReturn()` (`modules/daily-ledger/handlers-deliveries.php:2129`) refused with
HTTP 422 *"A Delivery Receipt number is required when returned stock enters a branch."* whenever
`branch_id > 0` and the DR was blank.

**Why removing it is safe:** `$drNumber` was **never persisted** in this path — it appeared in
exactly two places, the guard and the echoed response. `dl_recordConsigneeReturn()` does not take
it. Nothing downstream keyed on it, so this is a rule removal, not a data-model change.

**Shipped:** the guard is gone; the DR is still accepted when supplied, and is echoed only when
present. The cashier modal no longer lists the slip number in `returnMissing()` and omits
`dr_number` from the payload when blank, so a blank field can never look like a real receipt.
Acceptance: `tests/daily_ledger_consignee_return_no_dr_test.php` (6 assertions, failed on the
pre-change tree, passes now).

**Deliberately NOT changed:** the DR input stays on the form. Baron may still have one for some
returns, and deleting the field would remove information that is genuinely known in those cases.

**Follow-up:** the field's label still reads as mandatory. A live HTTP round-trip with
`branch_id` and no DR is also still unproven — see "verification debt" below.

---

## Concern 1 — OWN PRODUCTION on a commissary

**The server already permits it.** `dl_branchHasProduction()`
(`handlers-deliveries.php:106`) returns true when `is_commissary = 1` **or** `has_production = 1`,
and fails open. Both the online path (`apiSaveCashierWithdrawals`) and the offline replay path
(`handlers-offline.php:632`) gate `own_production` on that same function.

**So the gap is the surface, not the rule.** Own production is captured today from the cashier
ledger modal (`cashier/modal_patch.disyl`, type `own_production`, positive-only, reason
`self_production`, feeds the Addt'l cell). A commissary works in the **commissary sheet**
(`admin/commissary.disyl`), whose cell editor only offers dispatch-type entries.

### Verified 2026-11-17 — the commissary already records what it made ✅ NOT A DEFECT

The paragraph above was written from reading code, and it was **wrong about the outcome**. Checked
against the live database instead of the source:

| Branch | `dl_production_movements` | flow_mode | movement_type | last entry |
|---|---|---|---|---|
| 18 RIZAL-COMMIS (`is_commissary=1`) | **1,379** | `production` | `output` | **2026-11-16** |
| 21 Pagadian Commisary (`is_commissary=1`) | **1,327** | `production` | `output` | **2026-11-16** |

Both commissaries are recording production **daily, up to yesterday**. The path is the sheet's
**"Record Addition (ADDTL)"** button → `entity: 'production_addition'` →
`dl_recordProductionAddition()` → `dl_production_movements` with `movement_type='output'`.

The zero `own_production` **withdrawal** rows on both commissaries were a red herring: that type
lives in `dl_cashier_withdrawals`, the **branch cashier** mechanism. Commissaries never use it —
they are not routed to the cashier ledger at all (verified: a `production_in_charge` is refused
`/daily-ledger/ledger` with 403 and offered `/daily-ledger/admin/commissary` instead).

**Conclusion: two different mechanisms, both correct for their screen.**

| Screen | Mechanism | Table | Meaning |
|---|---|---|---|
| Branch cashier ledger | `own_production` withdrawal | `dl_cashier_withdrawals` | branch made stock that was not delivered in |
| Commissary sheet | `production_addition` → `output` | `dl_production_movements` | commissary recorded production output |

**Therefore nothing is built for concern 1.** Adding an `own_production` entry to the commissary
sheet would create a *second* way to say "we made this" on a screen that already has one, which is
the duplicate-mechanism defect the UI conventions forbid.

**What remains open (owner direction, not engineering):** whether the commissary's production
output should surface in the same **Addt'l** cell as a branch's own production so the two are
comparable on one report. That is a reporting question about the Addt'l cell's meaning, not a
missing entry point.

**Falsifier that reopens this:** a commissary recording production that `dl_production_movements`
does not capture — i.e. an owner report of made stock that is absent from that table after a
recorded entry. 

---

## Concern 3 — wrong product dispatched, correction must reach the branch

**What exists today is quantity-only and consignee-only.**
`dl_recordConsigneeDeliveryDiscrepancies()` (`handlers.php:3880`) compares *sent* against
*received*, writes paper evidence first, then appends one idempotent correction per item through
`dl_applyConsigneeLedgerDelta()`. It refuses a conflicting second correction, and a finalized
shift refuses it outright until reopened.

That machinery **cannot express the owner's case**, which is a **wrong product** (@480 keyed where
@330 was intended), not a wrong quantity. A product swap needs:
1. the original dispatch line reversed (product A back onto the commissary),
2. the correct line created (product B off the commissary, priced @330),
3. the branch's received line re-pointed from A to B with quantities preserved,
4. all of it append-only with a reversal record, never an overwrite.

**Why it is not a small change:** the branch receiving rows (`dl_branch_receiving_items`) were
written from the delivery at receive time. Re-pointing them is a stock correction on a *second*
branch that may already have its own finalized shift, and the branch-side ledger cell has already
been touched. Any implementation must therefore:
- refuse when the receiving branch's shift is finalized (same rule as every other mutation),
- post the reversal and the re-issue as two linked effects,
- leave the branch's *counts* untouched so the branch does nothing,
- and record provenance that names the operator and the original delivery.

**Recommended next step:** a bounded design pass first — the correction is a money-and-stock
mutation spanning two branches, which is exactly the class the repository rules say must not be
improvised inside an implementation lane.

### Update — owner confirmed the flow, and it is simpler than the first analysis

Owner: *"the receiving can correct the wrong item selected to the correct one … production data is
auto synced … notification shown at production dashboard … admin also notified thru the commi
dashboard."*

That is the right shape, and it is cheap **because production dispatch is already derived**:
`dl_commissary_product_ledger.dispatched_qty` is a read model fed from posted deliveries by
`dl_applyPostedDeliveryCommissaryLedger()` (`handlers.php:4488`). So "auto sync" is not a second
write — it is correcting the delivery item and re-running that one effect. No reconciliation job,
no dual write.

**Implemented by lane `dl-substitute-dispatch-item`** (`POST
/daily-ledger/api/v1/deliveries/substitute-item`, `apiSubstituteDeliveryItem`): admin/supervisor,
reason required, wrong product reversed and correct product credited on the receiving branch,
delivery/receiving items repointed, production effect re-applied, audited.
Acceptance `tests/daily_ledger_substitute_delivery_item_test.php` — 12/12.

**Open defect in that lane's output — FIXED 2026-11-17.** The lane **deleted**
`dl_delivery_ledger_effects` rows after reversing them, because
`uq_dl_delivery_ledger_effect_item (delivery_item_id)` allowed only ONE effect row per delivery
item — so a corrected re-application had nowhere to write. Deleting those rows destroyed the
applied/reversed audit trail.

Resolved in three parts:
1. **Migration 104** (`104_delivery_ledger_effect_append_only.sql`) drops that unique key and adds
   a non-unique `idx_dl_delivery_effect_item_status (delivery_item_id, effect_status)` so the
   lookup path survives. Idempotent via INFORMATION_SCHEMA, MySQL 5.7-safe, registered in
   `module.json`.
2. **`dl_applyPostedDeliveryCommissaryLedger()`** now skips only while an **`applied`** row
   exists, so a `reversed` row no longer blocks the corrected effect.
3. **`apiSubstituteDeliveryItem()`** reverses and re-applies through the shared helpers and
   **deletes nothing**.

Contract now 19/19 (`tests/daily_ledger_substitute_delivery_item_test.php`), including explicit
assertions that no `DELETE FROM dl_delivery_ledger_effects` exists, that the helper filters on
`effect_status = "applied"`, and that migration 104 is present and registered.

**Known limitation (accepted, not hidden):** the reversal helper is delivery-scoped, so a single
item substitution reverses and re-applies *every* line on that delivery. Other products net to
zero but produce extra ledger movements and audit rows. Narrowing it to one item needs a new
helper and is a separate bounded task.

**Still missing for the owner's full flow:** the two notifications (production dashboard + commi
dashboard). One durable notification record with a branch/audience scope is enough for both
surfaces — building two is the trap here.

### Receiving-modal wiring — DELIVERED 2026-11-17

The endpoint now has a caller, so it is no longer dead code:

- `incoming-deliveries` exposes **`delivery_id` on each received line** (non-formal paper-DR rows
  have none, which is honest — they have no delivery to re-point).
- Each line in the receiving modal carries a **`Wrong item?`** control. It opens a panel directly
  beneath that line — the same placement the variance note uses — holding: the product that should
  have been sent, the quantity, and a required reason.
- The panel states the consequence explicitly: *"Production updates automatically — do not adjust
  the commissary by hand."* That sentence exists to stop the double-adjustment this module has
  produced before.
- On save the modal reloads, so the corrected line shows the right product and the quantity math is
  re-read rather than assumed.

Verified: contract test 25/25, DiSyL lint over all 713 templates, and the modal-adjacent suites
(`receive_origin_default`, `consignee_cell_entry`, `button_feedback_contract`, `consignee_return_no_dr`)
all rc=0.

### Live E2E on baronledger.test — three defects the contract test could not see (2026-11-17)

Run with the seeded accounts (`prod-rizal` / `cashier-miputakAM`), RIZAL-COMMIS → Miputak.

| # | Symptom | Cause | Fix |
|---|---|---|---|
| 1 | cashier got **403 "This page is not open to your role"** | handler admitted `admin, supervisor` only | admit `cashier` and add `dl_authorizeBranch()` on the receiving branch — authority is per branch, not per role |
| 2 | **400 "Cannot reverse before an output/withdrawal exists for this date"** | it reversed branch `addtl` for stock the branch had never received | branch ledger moves only when `stockMoved` (a receiving exists with received qty); repointing always happens |
| 3 | **production double-counted**: product 17 stayed `dispatched=1` while 18 gained 1 | the Daily Sheet dispatch path writes the commissary ledger directly and creates **no** `dl_delivery_ledger_effects` row, so "reverse the effects" reversed nothing | when no effect row exists, mirror the dispatch movement for that line (`dl_applyCommissaryProductLedgerDelta`, negative) before re-applying |

Re-verified after the fixes with a second delivery (1000401, CHIFFON BIG ×2 → CHIFFON SMALL ×2):
commissary product 20 back to **0**, product 24 at **2**, one `applied` effect row, delivery line
repointed. Contract test now **28/28**, six daily-ledger suites rc=0, DiSyL lint clean.

**Test-data note:** delivery **1000400** was corrected during the run that PRECEDED fix #3, so
commissary 18 still carries an orphaned `dispatched=1` for product 17 on 2026-11-17 AM. The
delivery itself is correct (product 18). Left in place rather than SQL-patched, because adjusting a
ledger row by hand is exactly what this module forbids.

### Day lock → admin reopen → correct: PROVEN (2026-11-17)

The owner asked whether an admin can reopen a locked day and then correct. Verified end to end:

1. `dl_ledger_day_status(8, 2026-11-17) = closed` → cashier correction returns **409 "The branch day
   is closed; substitution refused."**
2. Admin `POST /daily-ledger/api/v1/admin/reopen-day {branch_id, date, reason}` →
   `{"ok":true,"day_status":"open"}`.
3. Cashier retries the **same** correction → **200 OK**.

Day status ended `open` (its original state), so the test left no residue.

Two further defects were found in this step:

- **Migration 104 could not apply at all**: `Cannot drop index 'uq_dl_delivery_ledger_effect_item':
  needed in a foreign key constraint`. InnoDB needs an index on the FK column, so the replacement
  index must be created **before** the unique key is dropped. Reordered, and the contract test now
  asserts the order.
- **Without 104 a delivery could only be corrected ONCE** — the second correction failed with
  `Duplicate entry '<item>' for key uq_dl_delivery_ledger_effect_item`. After applying 104 the same
  correction succeeded and left effect *7855 `reversed` (product 24)* beside effect *7860 `applied`
  (product 18)*, which is the append-only behaviour the design requires.

Apply with `php ikabud tenant:migrate <tenant> daily-ledger`. Contract test: **29/29**.

### Closing items (2026-11-17)

**Received-then-corrected path — PROVEN.** Dispatch PUTO CHIZ ×3 → cashier receives it → cashier
corrects to LECHON SMALL ×3. Result: branch `dl_daily_ledger` product 16 `addtl` back to **0**,
product 19 at **3**; receiving line and delivery line both repointed; one `applied` effect row.
This is the only path where branch stock actually moves, and it now has live evidence.

**Notification — DELIVERED through the existing store, not a new one.** The correction calls
`dl_raiseIntegrityNotification()` against the **commissary** branch with `production_in_charge`
added to the recipients. One row serves both surfaces the owner described:

| | |
|---|---|
| notification id | 2784516, type `delivery_item_substituted`, branch 18, count 1 |
| title | *Dispatch corrected at the receiving branch* |
| detail | `Delivery #1000405: PUTO CHIZ BIG @11 → LECHON SMALL @160 × 3. Reason: … Production figures were updated automatically; do not adjust the commissary by hand.` |
| recipients | `Ledger-Admin` (admin), `shiela_baina` (admin), `prod-rizal` (production_in_charge), `Prod_RizalPM` (production_in_charge) |
| rendered | confirmed on `/daily-ledger/admin/variances` while logged in as `prod-rizal`, title **and** both product names present |

Reusing the existing `variance` finding type was rejected: it would put stock corrections into the
variance review list and distort its counts. Migration **105** therefore appends
`delivery_item_substituted` to the `finding_type` ENUM (plain idempotent MODIFY, the 093/058 pattern).

**Deploy order for this feature is now two migrations: 104 then 105**, both before the code.

**Accepted collateral (owner decision):** historical mistakes were fixed by an admin editing the
cashier ledger and the production daily sheet by hand, and the activity log captures those edits. No
backfill is attempted for pre-fix corrections.

---

## Verification debt (stated, not hidden)

- Concern 2 is verified by a contract test, not a live HTTP round-trip with `branch_id` and no DR.
  That round-trip needs a tenant fixture and an authenticated cashier session.
- The modal's DR label still implies a requirement.
- No browser test covers "return without DR" yet.

## Harness observations from using it on real work

- **The first fully attributable ledger row now exists**:
  `dl-consignee-return-no-dr` → `run_id 20261117T162653-930722`, git head, three component
  hashes, **cost $0.00297 / 96,749 tokens**, and the model log retained at
  `.ai/runs/dl-consignee-return-no-dr-a1.model-a1-deepseek-v4-flash.log`. Before this change that
  row would have carried `cost_usd=0.0` and no artifact link.
- **Defect found by the dry run:** the hint store had four test fixtures leaked into it
  (`T lesson`, `C lesson`, `INJECTED-LESSON`, `budget is tight; verify early`) because the tests
  ran before `HARPP_EXPERIENCE_HINTS` was added to their environment. Removed. Lesson: a test
  that writes to a live store must set the override in `setUp`, not per-call.
- **Limitation exposed:** `trial new --component` accepts only the three auto-stamped harness
  components, so a *product* change like this one cannot pre-register a trial. The falsifier for
  product work is the acceptance test, not a metric trial; if trials should cover product changes
  the component vocabulary has to widen first.
