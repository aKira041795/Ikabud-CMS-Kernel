# Own production — scoped eligibility + legitimate receipt (decision record)

Date: 2026-11-14 · Session: own-production-scope · Consult: `.ai/consult/own-production-scope.md`

## Client / owner input (verbatim, translated)

- Client: *"About Own production — can I hide some products? Because the others use it since they no
  longer use a DR; it should be only for what THEY produced THAT DAY."*
- Owner: *"we have the active/inactive toggle already, but it also hides it from the cashier ledger —
  it should hide it only from the Own production choices."*
- Owner: *"what can we do with the no-DR feature, instead of being abused, it can be used for proper
  receiving data feed to admin."*

## Verified facts (measured, not assumed)

| Fact | Evidence |
|---|---|
| `dl_branch_products.is_active` is the ONLY per-branch product visibility flag | `dl_fetchCashierLedgerRows()` reads `dl_branch_products WHERE is_active = 1` |
| Own production has NO product list of its own — it scrapes the ledger rows | `modal_patch.disyl loadProducts()`: `document.querySelectorAll('tr[data-product-id]')` |
| So one `is_active` flip removes the product from ledger, Daily Sheet, dispatch, receive AND own production | same query + same DOM scrape |
| No product-level production flag exists | only `dl_branches.has_production` (branch-level) |
| `dl_deliveries.origin_type` = `enum('commissary','branch','supplier','manual')` | SHOW COLUMNS, tenant 207 |
| `dl_branch_receiving_items.supply_origin` ALREADY has `local_production`, and the supply resolver maps `self_managed → local_production` | `dl_supplyOriginForProduct()` handlers-deliveries.php |
| Every transfer/variance query filters `origin_type IN ('branch','commissary')` | `dl_pendingDeliveryIssues()`, variance queries |
| No-DR types are already gated for cashiers per branch | `dl_canAddStockWithoutPaperDr()` handlers-deliveries.php:57, used at handlers.php:9801 and :11747 |
| Own production writes only `dl_daily_ledger.addtl` + a `dl_cashier_withdrawals` row — no receipt, no delivery | `apiSaveCashierWithdrawals`, `$isAddtl` branch |

## Consultations

- **ChatGPT chair** (`.ai/consult/own-production-scope.md`): approve a persistent per-branch/product
  `allow_own_production` flag, a dedicated production-choice feed, and a first-class local-production
  receipt posting through the existing stock-ledger path. Defer daily production plans, new
  dashboards, generic stock frameworks. Reject `adjustment_add` as an alternative receiving channel,
  fake DR references, hiding historical records, and auto-interpreting paperless deliveries as local
  production. Its open question: *can `dl_deliveries` represent a self-produced source without
  implying an inter-location transfer?*
- **Claude red-team**: requested, not delivered — the free account is at its message cap. Not retried
  (policy: a limit state is waited out, never hammered).

## Decisions

### D1 — Hide/unhide grain: permanent per-branch-per-product eligibility flag

`dl_branch_products.allow_own_production TINYINT(1) NOT NULL DEFAULT 1`, enforced on the SERVER, with
its own audited setter. **Not** a per-day production plan.

- Why permanent: the client's *"that day"* refers to the **quantity entered that day**, not to a
  day-scoped catalogue. The producible set changes rarely (seasonal items). A per-day plan would make
  the first own-production entry of every day depend on someone setting up a plan — a new blocking
  step in front of a 3-tap action, and its empty start-of-day state is exactly what pushes people to
  `adjustment_add`.
- Why default **1**: deploying must not silently remove a capability that branches use today. The
  admin sets the exceptions.
- Falsifier: if the branch alternates products by day of week/schedule, revisit with a schedule source.
- Historical rows are never rewritten when the flag changes: the flag governs what may be entered
  now, not what was recorded before.

### D2 — The no-DR path becomes a real receipt, not a fake transfer

Add `origin_type = 'local_production'` to `dl_deliveries`, with `origin_id = destination_id = the
producing branch`, `dr_number = NULL`, `receipt_required = 0`, and a receiving line stamped
`supply_origin = 'local_production'` (a value the schema already carries).

- Why this answers the chair's open question: a production receipt is NOT an inter-location transfer,
  and because every existing transfer/variance query filters `origin_type IN ('branch','commissary')`,
  the new value is invisible to them **by construction** rather than by remembering to exclude it.
- Why not a separate table: `dl_delivery_items` + `dl_branch_receiving_items` already carry everything
  a production receipt needs (per-line quantity, supply origin, receiving user), and reusing them lets
  the SAME variance/review machinery apply without a second reconciliation model.
- Reconciliation: the receipt is compared to `dl_commissary_product_ledger.produced_qty` /
  `dl_production_runs` so "produced but never dispatched" and "produced only on paper" become visible.

### Explicit rejections (from the chair, adopted)

- `adjustment_add` must not become the fallback receiving channel: the existing
  `dl_canAddStockWithoutPaperDr()` gate stays, and the new eligibility check must not open a side door.
- No fake `dr_number`, no synthesised sender branch.
- No rewriting/hiding of historical entries when eligibility changes.

## Status

- D1 → **READY_FOR_IMPLEMENTATION** (spec: `docs/engineering/own-production-eligibility-spec.md`)
- D2 → **DESIGNED**, implement after D1 lands and the client has used the hide flow for a week.
