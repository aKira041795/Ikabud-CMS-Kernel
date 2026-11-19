---
description: "Daily Ledger invariants settled by owner direction — who may add stock, what needs a DR, what needs a liable person, and the three dispatch surfaces. Each rule carries the measured failure that produced it."
applyTo: "modules/daily-ledger/**/*.php templates/modules/daily-ledger/**/*.disyl tests/daily-ledger/**/*.php"
---
# Daily Ledger invariants

These were decided by the owner and then verified live. Do not re-derive them from the code's older
comments — several of those comments were the thing being corrected.

## Add stock without a paper DR — ONE switch, both roles

`dl_canAddStockWithoutPaperDr()` is the only gate. Admin always; **cashier and supervisor both follow
`dl_branches.paperless_dr_enabled`**; `production_in_charge` never (their story is production).
`apiSaveCashierWithdrawals`, the offline replay, the auto-DR paper receive and the UI flag
(`paperless_dr_allowed`) all read this one function, so a second gate re-opens a dead form: the modal
used to offer "Add Stock" to a role the server refused, and the cashier only found out on Save.

## Two "no DR" stories that must never merge

| | **No paper DR** (`paperless_dr_enabled`) | **Own production** (`has_production` + per-product `allow_own_production` / "Makes it") |
|---|---|---|
| Story | stock already on hand, never written down (missed / undercounted) | the branch MADE the item, so no supplier document exists |
| Scope | whole branch | product × branch |
| Enables | `adjustment_add` | `own_production` |

Neither grants the other. `own_production` must never consult `paperless_dr_enabled`; a test asserts
this in both directions.

## What a stock adjustment requires

- **DR:** only for `reason_code = 'unrecorded_delivery'` (`dl_reasonRequiresDr`). Every other reason,
  including quantity corrections, needs none.
- **Charge to (liable):** **optional** (owner 2026-11-18). `dl_adjustmentAddNeedsLiable()` returns
  false and the modal's `needsLiable()` returns false. Requiring it turned a quantity fix into an
  accusation; the field records who a shortage is billed to when somebody says so.

## Three dispatch surfaces — name the right one

| Control | Page | Endpoint | Who |
|---|---|---|---|
| **"New Delivery (DR)"** | commissary sheet | `/api/v1/commissary/dispatch` | commissary (incl. `production_in_charge`) |
| **"Open Send to Branch"** | branch ledger | `/api/v1/cashier/ledger/dispatch` | branch staff |
| **"New Delivery"** | admin Deliveries page | `/api/v1/deliveries/create` | admin recovery only |

Never tell a commissary user to use "Send to Branch" — their control is "New Delivery (DR)", and it
is the one that carries the DR.

## Two traps that cost real time here

- **A class the template does not define fails silently.** In `commissary.disyl` only `.btn`,
  `.btn-sm`, `.btn-primary`, `.btn-outline`, `.btn-accent`, `.btn-danger` exist — `btn-secondary` and
  `card-footer` render with no colour and no padding. Verify with computed style, not by finding the
  class in markup.
- **Role pages and their APIs must agree.** A page let through while its endpoints 403 produces a
  rendered refusal inside the page; an endpoint left off an allowlist makes a working screen dead.
  Both were measured here.
