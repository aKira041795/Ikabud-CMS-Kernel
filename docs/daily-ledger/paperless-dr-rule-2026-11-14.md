# Paperless DR rule — 2026-11-14

Owner's settlement of the no-DR question, in two lines:

1. **A product the branch makes** (marked own-produced for that branch) needs no DR.
2. **Every other product cannot be set without a DR** — it was delivered, so it is received
   against the paper DR. Paperless DR therefore cannot be done.

The reason it had to become a rule rather than a preference: *"the no DR has been abused by
cashiers. instead of encoding the DR number, they use that to make their encoding faster."*

## What the data showed before the change

Measured on tenant 207 over the previous 60 days.

| signal | count |
|---|---|
| paper DRs captured (`[captured-from-paper-dr]` deliveries) | 590 |
| cashier stock-adds with no DR (`adjustment_add` + `own_production`) | 15,902 |
| …of the cashier `adjustment_add` entries filed as `encoder_omission` | 8,187 |
| cashier `[auto-dr-production]` receipts (minted DR numbers) | 238 from 17 cashiers |
| branches with `paperless_dr_enabled` = 1 | every branch with volume |
| busiest single cashier | 1,602 entries in 30 days |

Two conclusions:

- The per-branch switch shipped on 2026-10-22 gated nothing, because it defaulted to allowing and
  nobody switched it off. A control that must be armed is not a control.
- `encoder_omission` is a *delivery being back-dated*. The delivery had a DR; the honest correction
  is to receive it against that DR, not to conjure stock through an adjustment.

## The rule as implemented

| action | cashier | supervisor | admin |
|---|---|---|---|
| Own production, product flagged own-produced | **allowed** | allowed | allowed |
| Own production, product not flagged | refused (`OWN_PRODUCTION_PRODUCT_DISABLED`) | refused | refused |
| Add Stock (`adjustment_add`) | **refused** (`PAPERLESS_DR_DISABLED`) | allowed if branch switch on | allowed |
| Receive with "No paper DR — auto-generate" | **refused** (`PAPERLESS_DR_DISABLED`) | allowed if branch switch on | allowed |
| Receive with a paper DR number | allowed | allowed | allowed |

- `dl_canAddStockWithoutPaperDr()` is the single authority (`handlers-deliveries.php`). It is now a
  **role** answer first: cashier never, supervisor follows the branch switch, admin always.
- Enforced in **both** write paths. `handlers-offline.php` previously had *no* paperless gate at
  all — a queued Add Stock replayed straight past the online rule.
- `own_production` is no longer gated by the paperless switch. It is scoped per product instead,
  which is the only thing that can make it honest: a branch that bakes has no paper DR to encode.
- Cashier modals are deliberately **unchanged** (owner: *"it's done as a filter when set by admin"*).
  The refusal message is the guidance, and the Own production picker is already filtered by the
  same flag.
- Admin screens now say what the two checkboxes mean: **In ledger** (`is_active`) versus
  **Own produced here** / "Makes it" (`allow_own_production`). Identical-looking labels are why the
  admin had to ask what they were hiding.

No migration: this is a behaviour change on existing columns.

## Verified

Real HTTP as cashier #28 (ARLYN PISAN, branch 9), real endpoints, real session:

| probe | result |
|---|---|
| Add Stock | 403 `PAPERLESS_DR_DISABLED` with the DR/own-production guidance |
| Own production (no DR) | 200, wrote `addtl` |
| Receive, auto-DR | 403 `PAPERLESS_DR_DISABLED` |
| Receive, no number | 422 "Paper DR number is required." |
| Receive, paper DR number | 200, delivery created, ledger applied |

Both probe writes were reversed and verified gone (`dl_deliveries`, `dl_delivery_items`,
`dl_branch_receivings`, `dl_delivery_ledger_effects`, `dl_cashier_withdrawals`, recomputed `addtl`).

Suites: `daily_ledger_paperless_dr_role_test` 15/15 · own-production eligibility 13/13 ·
own production 35/35 · authz 84/84 · DiSyL lint 713/713 · both logs empty.

## Known gap — the next door

Own production is now the **only** DR-free lane and it has **no ceiling**. A branch that flags a
product can book any quantity with nothing to check it against; there are already 7,655 cashier
own-production entries in 60 days versus 590 paper DRs. If the volume simply moves there, the abuse
has changed doors rather than stopped — the same pattern as 2026-10-22 (switch added) → 2026-10-23
(auto-DR found as the second door).

Bounding own production by the quantity the branch recorded producing that day is the follow-up.
Deliberately not done here, because it changes what a branch must record daily.

Second, smaller gap: `allow_own_production` defaults to 1, so every branch currently claims its whole
catalogue (branch 8 claims 198 products). The rule refuses nothing extra until admins untick what the
branch does not make — this needs telling to the client plainly.

## Falsifiers

- If a branch genuinely alternates which products it makes by day or schedule, a per-product flag is
  the wrong shape and needs a schedule source instead.
- If the measured rate of paperless stock-in does **not** fall once admins trim their product lists,
  the rule is not the binding constraint and the next door is the real one.
