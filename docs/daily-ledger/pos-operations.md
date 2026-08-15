# Daily Ledger — POS Operations

The Point of Sale (POS) workflow is an **optional, bounded feature** of Daily Ledger. The manual
stock-ledger workflow is unchanged: POS never writes `beg_bal`, `addtl`, `withdraw`, or `bal_end`,
and never stores sales in `dl_daily_ledger.sales`.

## Enabling POS

- Settings → **Enable Point of Sale (POS)** (`pos_enabled`, default off).
- Settings → **POS Allowed Tenders** (`pos_allowed_tenders`, default `cash`; comma-separated, e.g. `cash,gcash,card`).
- Permissions (Settings → Role Permissions): `pos.sell`, `pos.void`, `pos.refund`, `pos.fallback`, `pos.report`.
  Defaults: cashiers get `pos.sell` only; supervisors and admins get all five. Void, refund, fallback,
  and historical reporting are role-gated to supervisor/admin regardless of grants.
- **Upgrading an existing deployment**: if the tenant already saved custom Role Permissions, the new
  POS permissions are **not** granted automatically — an admin must open Settings → Role Permissions
  and re-save to add `pos.sell`/`pos.void`/`pos.refund`/`pos.fallback`/`pos.report` to the relevant
  roles. Until then, authorized users will see "You are not authorized to use POS."

## Day sales mode (server-authoritative)

Each branch + business date has one mode stored in `dl_sales_day_modes`:

| Mode | Official sales |
|---|---|
| `manual` | Stock-derived: `max(0, beg_bal + addtl − withdraw − bal_end)` |
| `pos` | Completed POS sales minus refunds (voided excluded) |
| `fallback` | POS net **before** the checkpoint + manual segment **after** it |

- Before any activity, an authorized user picks **Use POS** or **Use Manual Ledger** on the POS screen.
- Manual locks after the first manual source-field save; POS locks after the first completed sale.
- The browser may request a mode; the server decides and persists it. `expected_version` optimistic
  concurrency rejects stale clients with `VERSION_CONFLICT` (409).
- Closed days reject mode changes, carts, checkout, void, refund, and fallback.

## Receipt lifecycle

1. Checkout validates products (active + branch-assigned), resolves prices server-side, recalculates
   all totals in integer cents, and rejects stale client prices (`STALE_PRICE`), unavailable products,
   insufficient cash, and wrong modes.
2. The sale, item snapshots, payments, receipt number, completion event, and audit record commit in
   **one transaction**. The receipt is returned only after commit — a success response means durable.
3. `client_operation_key` is unique per branch. A retry with the same key and payload replays the
   original receipt (`idempotent_replay: true`); the same key with a different payload is a 409 conflict.
4. Completed sales are immutable. Corrections:
   - **Void** (`pos.void`, supervisor/admin, reason required, before day close) — flips lifecycle to
     `voided`; lines and payments are preserved untouched.
   - **Refund** (`pos.refund`, supervisor/admin, reason required) — creates an append-only refund
     document (`sale_kind = 'refund'`) linked to the original sale using the original price snapshots;
     over-refunds are rejected per product.

## Fallback checkpoint (POS → Manual, mid-day)

- Requires `pos.fallback` (supervisor/admin), a reason, a **complete** physical count of every active
  branch product, and no open draft carts.
- The checkpoint (`dl_pos_fallback_checkpoints` + item rows with `addtl`/`withdraw` snapshots) and the
  mode switch persist in one transaction. Fallback is final: POS checkout is rejected for the rest of
  the day, and a second checkpoint for the same day conflicts.
- Manual segment after the checkpoint per product:
  `max(0, checkpoint_count + (addtl − addtl_snapshot) − (withdraw − withdraw_snapshot) − bal_end)`.

## Reconciliation and day close

- `dl_pos_salesSummary()` is the canonical summary used by the POS screen, sales report, and day close.
  It always returns `sales_source`, official total, POS total, calculated (stock) total, and variance —
  POS and stock-derived totals are **never added** for the same segment.
- Day close on a POS/fallback day additionally requires: no open draft carts, and — when POS and
  stock-derived totals differ — an explicit variance acknowledgment (`acknowledge_variance`), which is
  audit-logged. Variance never blocks silently and never alters completed transactions.

## Recovery from a failed checkout

- A network or server error means the sale is **not confirmed**. The cashier must verify (POS screen /
  POS Sales report) before retrying. Retrying with the same `client_operation_key` is always safe.
- There is no offline POS queue. Manual ledger field saves and the supported offline operations
  (stock adjustment, paper-DR receive) are queued behind the encrypted offline vault (`offline-vault.js`)
  when the device is enrolled, and are never used for payments.

## Admin reporting

- **POS Sales** (`/daily-ledger/admin/pos-sales`): branch/date/cashier/status/tender filters, receipt
  detail, void/refund actions (permission-gated), CSV export. Branch scoping matches the existing
  Daily Ledger accessible-branch rules.
- The **Sales** report labels the official source (`Stock-derived (manual ledger)`) and shows the
  POS-vs-calculated reconciliation panel when POS data exists for a single filtered day.
