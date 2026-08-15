# Daily Ledger: User Guide

Welcome to the Daily Ledger system. This guide will help you understand how to use the system based on your role. The Daily Ledger is designed to track production, sales, and variances across our branches and commissary.

## 1. Cashier (Branch View)
As a Cashier, your main focus is on recording the daily operations of your specific branch.

**What you will see and do:**
* **Dashboard / Sales Entry:** You have access to your branch's daily sales sheet. This looks like a spreadsheet where you can log the beginning inventory, deliveries received, and ending inventory.
* **Separate stock actions:** When the formal delivery workflow is enabled, the action bar separates **Receive Delivery**, **New Dispatch**, and **New Adjustment** so branch transfers are no longer mixed into adjustment entries.
* **Recording Sales:** You will input actual sales amounts, cash deposits, and expenses. The system automatically calculates expected sales based on the inventory movement.
* **Receiving stock:** Normal incoming stock should be posted through **Receive Delivery**. Stock does not officially land in the branch just because another location encoded it.
* **Receiving by Paper DR:** If the actual paper DR arrived but the source branch or commissary did not encode the dispatch yet, you can use **Receive by Paper DR** to capture the missing delivery and receive it in one step.
* **Paper DR date limits:** Cashiers can only receive paper DR captures against the current business date. Late branch-origin paper DR captures are escalated to admin.
* **Adjustments only:** **New Adjustment** is for charge and pullout corrections only. It is no longer the place to encode deliveries.
* **Variances:** If there is a difference between your expected sales (based on inventory) and actual cash/remittances, the system logs this as an Over or Short (variance).
* **Automatic Saving:** As you type numbers into the ledger forms, they save automatically. You do not need to click a "Submit" button for every row!
* **Offline Work:** If the internet or server is unavailable, the Android app can continue working with locally cached data after you unlock it using your offline PIN.
* **Reconnect Behavior:** Offline entries are queued and synced automatically when the connection comes back. If the day cannot be closed because the app is offline, reconnect first and try again.

### Offline Access (browser PWA)

The online ledger now supports a token-free offline shell on the browser:

* **Enable offline access:** On the ledger page, choose **Enable offline access** while connected, set a 4–6 digit offline PIN, and confirm it. One action reaches a verified **Offline ready** state — no manual reload needed. The device stores an encrypted snapshot of your branch ledger plus your pending edits.
* **Offline shell:** Once offline, launching the installed Daily Ledger app or opening the ledger URL opens the offline PIN screen. Enter your PIN to unlock a read/edit view of your branch's current day. Your edits are saved securely on the device first, then synced when the connection returns.
* **What works offline:** Manual ledger field edits, **Stock Adjustment**, and **Receive by Paper DR** are queued locally and synced automatically on reconnect.
* **What stays online-only:** POS, **Day close**, **Day reopen**, **Send to Branch / dispatch**, and **Delivery correction** require connectivity. The offline shell explains why they are blocked.
* **Security:** The offline PIN never leaves the device, and the stored snapshot and queued changes are encrypted. Offline access expires after the configured **Max Offline Days**; after expiry or revocation you must re-enroll online.
* **Lock / remove:** **Lock** hides the offline view without deleting anything. **Remove offline access** warns if you have unsynced changes, revokes the enrollment, and clears that device's encrypted vault.

## 2. Production In-charge (Commissary Flow)
If you manage the bakery or commissary, your work now follows one operator flow instead of separate disconnected pages.

**What you will see and do:**
* **Default landing page:** After sign-in, you land on **Production Output**.
* **Flow order:** Your sidebar follows the operator sequence **Production Output -> Deliveries -> Production Withdrawal**.
* **Bread vs. Cake Tabs:** At the top of your production sheet, you can select the **"Bread Production"** or **"Cake Production"** tab. This keeps the long list organized. 
* **Branch + date first:** Start in **Production Output**, choose the destination branch, confirm the ledger date, and enter the branch DR number when the output is meant to create or update a branch delivery.
* **No flow-mode choice:** The production pages no longer ask you to choose a flow mode. Production output and production withdrawal both use the production flow automatically.
* **Upstream to downstream:** Use **Production Output** first, confirm the DR handoff in **Deliveries**, then use **Production Withdrawal** only after the same branch flow already has a matching delivery DR in the system.
* **Paper DR follow-up:** If a branch already captured a paper DR before production was encoded, the Deliveries page shows that delivery in the **Paper DR Check** queue. Production In-charge can mark it **Verified**. Admin and supervisors can also mark **Discrepancy** or **Reopen Check**.
* **Receiving detail access:** From the Deliveries page, Production In-charge can open the **Receiving** detail for received branch deliveries to confirm the sent and received quantities tied to that DR.
* **Auto-save:** The moment you finish typing in a box and click away, the row turns green briefly—this means it has successfully saved to the database.

## 3. Supervisor (Multi-Branch View)
As a Supervisor, your job is to oversee multiple branches and review their performance.

**What you will see and do:**
* **Branch Monitoring:** You can view the ledgers and daily reports for any branch under your supervision. 
* **Reviewing Variances:** You have access to the **Variances** list. This allows you to see which branches are frequently short or over their cash counts.
* **Auditing:** You can verify the beginning and ending inventories reported by cashiers against the delivery receipts sent from the Commissary.
* **Adjustments:** If a cashier made an honest mistake that locked out, supervisors have the authority to unlock or assist in correcting previous daily ledgers.

## 4. Administrator (Full Access)
The Administrator completely configures the system constraints, products, and personnel.

**What you will see and do:**
* **Product Catalog:** Manage all items sold. You explicitly categorize them into **Bread**, **Cake**, or **Other**. You can also assign their prices, sort order, and expected output parameters (e.g. how many pieces per batch). You can also bulk import/export these using CSV files!
* **Branch Management:** Add new stores, deactivate old ones, assign cashiers/supervisors to specific locations, mark a branch as a commissary, and assign that commissary to the branches it supplies.
* **User Management:** Create employee accounts and assign their exact role (Admin, Supervisor, Cashier, or Commissary). 
* **System Settings:** Configure global requirements like what time the daily ledger strictly cuts off.
* **Paper DR oversight:** Use the Deliveries page to review branch or commissary deliveries that were captured from paper DR before the source encoded them in the system. The user-facing statuses are **Needs Check**, **Verified**, and **Discrepancy**.
* **Complete Oversight:** Admins can view every single page—from the Commissary production sheets to the individual branch sales ledgers and system-wide variance reports.

---

## 5. Branch Supply Modes (Inventory Spec, Phase A)
Each branch declares how it sources stock. Configure on the **Branch Management** page:
* **`commissary_supplied`** — branch receives every product from an assigned commissary. Pick the commissary in the new "Assigned Commissary" dropdown.
* **`self_managed`** — branch produces or procures its own stock. No commissary required.
* **`hybrid`** — branch is mostly self-managed but pulls a few SKUs from a commissary. Use product-level overrides to pin exceptions.

A branch must first be flagged **Is Commissary** before it appears in the **Assigned Commissary** dropdown for other branches. The assigned commissary must be an active branch already marked as a commissary, and a branch cannot assign itself as its own commissary. Per-product overrides (`dl_branch_product_supply_rules`) take precedence over the branch default and let a single product use a different commissary or supply mode without changing branch settings.

## 6. Formal Delivery & Receiving Workflow (Phase B, feature-flagged)
When **Formal Delivery Workflow** is enabled in **System Settings → Daily Ledger** (default OFF), branch stock movements become an explicit delivery-and-receiving process:
1. **Production Output with DR** can create the pending delivery automatically when a run is branch-directed.
2. **Branch-to-branch movement** should be encoded through **New Dispatch**, not through cashier adjustments.
3. **Branch posts a receiving** through **Receive Delivery** once the goods physically arrive.
4. **Receive by Paper DR** is available when the paper DR exists but the source dispatch is still missing in the system.

Once posted, the receiving feeds the day's `dl_daily_ledger.addtl` for each product. Voided deliveries reverse the ledger contributions automatically.

Important operating rules:
* A branch-origin paper DR captured for a past business date requires admin.
* A branch-origin paper DR tied to a closed source day also requires admin.
* A paper DR capture creates a delivery with a pending **Paper DR Check** status until someone checks the exception.
* Production withdrawal under the formal workflow requires a DR number that already matches an existing branch delivery for the same downstream flow.

For concrete examples, see [Movement Scenarios](./movement-scenarios.md), which walks through a DR-backed commissary delivery, standalone branch movement, regular pricing, and mall pricing.

## 7. Price Groups (Phase C)
The **Price Groups** page lets admins define independent price tiers (e.g., "Default", "Mall Kiosk", "Wholesale"). For each (product × price group) row in **Product Prices** you set an effective date range and `selling_price`.
* If a branch (or selling account) is mapped to a price group via `dl_branch_price_groups`, the system uses that tier's price.
* If no group is mapped, the resolver falls back to the product's `current_price`.
Resolution is handled by `dl_resolveProductPrice()` and is applied on every delivery posting and ledger snapshot.

## 8. Selling Accounts (Phase D, feature-flagged)
When **Selling Accounts** is enabled (default OFF), a branch can host multiple consignment-style ledgers — for example a **Bread Cart** parked at a mall, or a **Reseller** sub-account. Each selling account:
* Has its own assigned commissary, price group, and per-day open/closed status.
* Tracks its own ledger rows (`dl_selling_account_daily_ledger`) using the formula:
  `sold_qty = beg_qty + delivered_qty − return_qty − end_qty` and `gross_amount = sold_qty × price_snapshot`.
* Closes day-by-day independently, audited via the `selling_account_day_closed` event.
Use the **Branch → Selling Accounts** tab to manage them.

## 9. Branch Consolidated Summary (Phase E)
The branch detail page now shows a **Consolidated Summary** card combining:
* **Regular ledger totals** (sum of `sales × price_snapshot` across `dl_daily_ledger`).
* **Selling account gross** (sum of `gross_amount` across the branch's selling accounts).
* A breakdown row for every active selling account.
This is computed by `dl_branchConsolidatedSummary($branchId, $date)`.

## 10. Cashier Withdrawal Reason Codes (Phase F)
When a cashier records an adjustment, the form requires a **Reason Code** — one of `spoilage`, `staff_meal`, `sampling`, `testing`, `promo`, `donation`, `damage`, `manual_adjustment`, or `other`. (Defaults to `manual_adjustment` if omitted.) The reason persists with the row and surfaces on supervisor variance reports for faster classification.

Delivery movement is no longer an adjustment type. Use **New Dispatch**, **Receive Delivery**, or **Receive by Paper DR** for stock transfers.

---
*Tip for all users: Keep your browser updated and do not close the window if a row is showing "Saving...". Wait for the green confirmation before navigating away.*

*Android operators: if the app reports that a day is `unknown` while offline, continue recording entries locally and allow the app to refresh status after the network returns.*

