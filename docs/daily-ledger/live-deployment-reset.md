# Daily Ledger Live Deployment Reset

## Purpose

Reset Daily Ledger module data on a live server while preserving only the currently logged-in admin account.

## What the reset does

The deployment reset action is available in Daily Ledger Settings.

- Endpoint path: `/daily-ledger/api/v1/admin/settings/permissions`
- Trigger payload:
  - `deployment_reset: true`
  - `deployment_reset_confirm: "RESET DAILY LEDGER DATA"`
  - `deployment_reset_dry_run: true|false`
- Required role: `admin`
- Confirmation phrase required: `RESET DAILY LEDGER DATA`
- Optional second safeguard phrase (enabled by default): `I UNDERSTAND THIS WILL DELETE ALL DAILY LEDGER DATA`

## Settings-based database backup

Daily Ledger Settings now includes a database backup panel.

- Manual backup button: **Generate Backup Now**
- Auto-backup toggle: **Auto backup before deployment reset**
- Optional user-account inclusion toggle: **Include user accounts in backup**
- Retention control: **Backup retention days (1-90)**

Backups are generated as SQL files in:

- `storage/backups/daily-ledger/`

Backup file naming:

- `dl-db-backup-YYYYMMDD-HHMMSS.sql`

Security behavior:

- Backup directory receives a deny-all `.htaccess` guard.
- Download requires authenticated admin access through:
  - `/daily-ledger/admin/settings/backup-download?file=<filename>`

When executed (non-dry-run), it wipes all Daily Ledger module tables (`dl_*`) and then restores exactly one account:

- The currently logged-in admin user in `dl_users`

This includes deleting test and live sales data, branches, and all other module records.

Legacy operational tables that are included in the wipe:

- `dl_delivery_variance_flags`
- `dl_branch_receiving_items`
- `dl_branch_receivings`
- `dl_delivery_items`
- `dl_deliveries`
- `dl_cashier_withdrawals`
- `dl_commissary_product_ledger`
- `dl_commissary_ledger`
- `dl_production_movements`
- `dl_production_runs`
- `dl_selling_account_ledger`
- `dl_variance_flags`
- `dl_daily_ledger`
- `dl_ledger_day_status`
- `dl_product_price_history`
- `dl_password_resets`

It preserves:

- The currently logged-in admin account only (restored after purge)
- Module settings (outside `dl_*` tables)
- Schema and migrations

## Bluehost shared-hosting safety

This implementation is compatible with Bluehost MySQL 5.7 constraints.

- Uses `DELETE` statements, not `TRUNCATE`
- Uses no CTEs and no window functions
- Uses simple transaction boundaries (`beginTransaction/commit/rollBack`)
- Checks table existence before deletion to tolerate partially migrated tenants

## Process-flow verification checklist

Run this before and after reset in a staging replica and once in production.

1. Authentication and role gate
- Confirm only Daily Ledger admin can access settings action.
- Confirm confirmation phrase is required.

2. Cashier flow
- Open ledger page, edit rows, save single/batch.
- Test stock adjustment modal.
- If formal delivery is enabled: test receive/dispatch entry points.

3. Supervisor flow
- Open dashboard, sales, variances, activity.
- Confirm branch-scoped views still load.

4. Admin flow
- Open products, branches, users, settings, deliveries, price groups.
- Confirm Settings page shows reset preview and execute controls.

5. Wiring checks
- Confirm nav active state for Price Groups route.
- Confirm user add/edit/delete/restore toasts show proper error/success variants.
- Confirm user uniqueness checks block duplicate username/email properly.

6. Reset dry run
- Run preview and record table row counts.

7. Reset execute
- Run non-dry-run reset.
- Verify expected operational tables are empty.
- Verify admin account can still log in.

8. Post-reset smoke
- Create one cashier transaction and one admin transaction.
- Confirm new rows are written without error.

## Logging and audit

- Reset actions are written to audit logs via `deployment_data_reset`.
- Failures are written to app logs with context (`daily-ledger deployment reset failed`).

## Rollback guidance

This action is destructive for operational rows. Use database backup restore for rollback.
