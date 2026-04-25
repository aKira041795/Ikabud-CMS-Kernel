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
*Tip for all users: Keep your browser updated and do not close the window if a row is showing "Saving...". Wait for the green confirmation before navigating away.*

*Android operators: if the app reports that a day is `unknown` while offline, continue recording entries locally and allow the app to refresh status after the network returns.*
