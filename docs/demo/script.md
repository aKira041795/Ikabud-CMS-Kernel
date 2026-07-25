# Demo Walkthrough: Daily Ledger

This walkthrough uses the Daily Ledger module on a seeded tenant
(`baronbakeshop`) to demonstrate Ikabud's core platform capabilities.

---

## Step 1: Tenant Login

Open your browser to the Ikabud installation URL. You should see a login page
rendered by the tenant entry router.

```
URL: https://your-domain.com/login
```

Log in with the tenant admin credentials from your seed configuration.

The CI seed creates a `baronbakeshop` tenant. Use the email and password
configured in your tenant seed script or `.env` file.

**What to observe:** The login authenticates against the tenant's own user
table (not a shared global table). After login, the URL reflects the tenant
context.

---

## Step 2: Admin Dashboard

After login, you land on the admin dashboard. The sidebar shows available
modules. Only modules enabled for this tenant appear.

Look for:

- **Dashboard** — summary cards and recent activity
- **Daily Ledger** — the operational module we'll use
- **Users** — role and account management
- **Settings** — module and tenant configuration

**What to observe:** The sidebar and layout are rendered by DiSyL templates.
The interface is server-rendered with Alpine.js for interactivity.

---

## Step 3: Daily Ledger — Branch View

Navigate to **Daily Ledger** in the sidebar.

Select a branch from the branch selector. You should see the daily sales sheet:

- Beginning inventory
- Deliveries received
- Ending inventory
- Actual sales and expenses
- Calculated variances

**What to observe:** The ledger is a DiSyL-rendered form with auto-save.
As you type in a field and tab away, the row turns green briefly — this means
the data was saved to the database via an AJAX call.

Try entering a sales amount and moving to the next field. Reload the page.
The value persists.

---

## Step 4: Reports and Exports

Navigate to the **Reports** section within Daily Ledger.

Select a date range and generate a summary report. The report renders as an
HTML page. Click the export button to download:

- **CSV** — opens in spreadsheet software
- **PDF** — formatted document (A4, headers, pagination)

**What to observe:** The export pipeline (`KernelExport`) produces consistent
output regardless of which module calls it. The same export infrastructure
works for any entity type.

---

## Step 5: Audit History

Navigate to the audit log for a ledger entry (look for an "Audit" or "History"
link on the detail page).

You should see:

- Who created the record
- Who modified it and when
- What fields changed
- The previous and new values

**What to observe:** Audit events are fired through the kernel event bus
(`kernel.audit.record@1`). Any module can call this capability without
implementing its own audit logging.

---

## Step 6: Module Enable/Disable

Navigate to **Settings → Modules** (superadmin or tenant admin).

Find the **Daily Ledger** module in the module list.

- Toggle it to **disabled**
- Navigate back to the sidebar — Daily Ledger is gone
- Toggle it back to **enabled**
- Refresh — Daily Ledger is back

**What to observe:** Module enable/disable is enforced at the kernel level.
When disabled, the module's routes are not registered and its tables are not
accessible. No module can bypass this gate.

---

## Step 7: Backup Verification

If you have database access, verify the backup procedure works:

```bash
# Export the tenant database
mysqldump --single-transaction ikabud_tenant_1 > demo_backup.sql

# Verify the dump contains ledger data
grep -c "daily_ledger" demo_backup.sql

# Restore (simulate)
mysql ikabud_tenant_1 < demo_backup.sql
```

**What to observe:** All data is in standard MySQL tables. No proprietary
format. No encryption-at-rest in the application layer (except password hashes
and encrypted DB credentials). See the [Adopter Guide](../kernel/adopter-guide.md)
for full backup and restore procedures.

---

## Summary

In this walkthrough, you observed:

| Capability | Where it showed |
|---|---|
| Multi-tenant isolation | Tenant-specific login and data |
| DiSyL rendering | Admin interface, forms, reports |
| Auto-save | Ledger form persistence |
| Export pipeline | CSV and PDF report export |
| Audit logging | Record change history |
| Module governance | Enable/disable with route + table enforcement |
| Backup/restore | Standard MySQL dumps |

Next: Read the [Architecture Explainer](architecture-explainer.md) for a
concise overview of how these capabilities are implemented.
