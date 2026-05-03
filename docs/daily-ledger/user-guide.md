# Daily Ledger: User Guide

Welcome to the Daily Ledger system. This guide will help you understand how to use the system based on your role. The Daily Ledger is designed to track production, sales, and variances across our branches and commissary.

## 1. Cashier (Branch View)
As a Cashier, your main focus is on recording the daily operations of your specific branch.

**What you will see and do:**
* **Dashboard / Sales Entry:** You have access to your branch's daily sales sheet. This looks like a spreadsheet where you can log the beginning inventory, deliveries received, and ending inventory.
* **Recording Sales:** You will input actual sales amounts, cash deposits, and expenses. The system automatically calculates expected sales based on the inventory movement.
* **Variances:** If there is a difference between your expected sales (based on inventory) and actual cash/remittances, the system logs this as an Over or Short (variance).
* **Automatic Saving:** As you type numbers into the ledger forms, they save automatically. You do not need to click a "Submit" button for every row!
* **Offline Work:** If the internet or server is unavailable, the Android app can continue working with locally cached data after you unlock it using your offline PIN.
* **Reconnect Behavior:** Offline entries are queued and synced automatically when the connection comes back. If the day cannot be closed because the app is offline, reconnect first and try again.

## 2. Production In-charge (Commissary View)
If you manage the bakery or commissary, you will be logging the total goods produced each day.

**What you will see and do:**
* **Commissary Interface:** Your main workspace is the Commissary page, which has been designed to look exactly like the physical paper clipboards you use.
* **Bread vs. Cake Tabs:** At the top of your production sheet, you can select the **"Bread Production"** or **"Cake Production"** tab. This keeps the long list organized. 
* **Global Baker & Branch:** Instead of typing your name on every single row, you just select the **Baker** and the destination **Branch** once at the very top of the page.
* **Entering Yields:** In the rows for each product, you simply enter your `Yield` (how much was made) and your `Kilo/Egg` inputs. 
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
* **Branch Management:** Add new stores, deactivate old ones, and assign cashiers/supervisors to specific locations.
* **User Management:** Create employee accounts and assign their exact role (Admin, Supervisor, Cashier, or Commissary). 
* **System Settings:** Configure global requirements like what time the daily ledger strictly cuts off.
* **Complete Oversight:** Admins can view every single page—from the Commissary production sheets to the individual branch sales ledgers and system-wide variance reports.

---

## 5. Branch Supply Modes (Inventory Spec, Phase A)
Each branch declares how it sources stock. Configure on the **Branch Management** page:
* **`commissary_supplied`** — branch receives every product from an assigned commissary. Pick the commissary in the new "Assigned Commissary" dropdown.
* **`self_managed`** — branch produces or procures its own stock. No commissary required.
* **`hybrid`** — branch is mostly self-managed but pulls a few SKUs from a commissary. Use product-level overrides to pin exceptions.

A branch can also be flagged **Is Commissary** to expose it as a supplier in the dropdown. Per-product overrides (`dl_branch_product_supply_rules`) take precedence over the branch default and let a single product use a different commissary or supply mode without changing branch settings.

## 6. Formal Delivery & Receiving Workflow (Phase B, feature-flagged)
When **Formal Delivery Workflow** is enabled in **System Settings → Daily Ledger** (default OFF), commissary deliveries become a two-step paper trail:
1. **Commissary creates a delivery** listing items, quantities, snapshot prices, and the destination branch.
2. **Branch posts a receiving** noting actual quantities received. Any mismatch between sent vs received is logged into `dl_delivery_variance_flags` automatically.

Once posted, the receiving feeds the day's `dl_daily_ledger.addtl` for each product. Voided deliveries reverse the ledger contributions automatically.

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
When a cashier records a withdrawal, the form requires a **Reason Code** — one of `spoilage`, `staff_meal`, `sampling`, `testing`, `promo`, `donation`, `damage`, `manual_adjustment`, or `other`. (Defaults to `manual_adjustment` if omitted.) The reason persists with the row and surfaces on supervisor variance reports for faster classification. Reason is not required when the withdrawal type is `delivery`.

---
*Tip for all users: Keep your browser updated and do not close the window if a row is showing "Saving...". Wait for the green confirmation before navigating away.*

*Android operators: if the app reports that a day is `unknown` while offline, continue recording entries locally and allow the app to refresh status after the network returns.*

