# Daily Ledger

Digital daily sales report encoding system — mirrors the traditional paper ledger. Auth-owned module with its own `dl_users` table and `admin` role. Includes an Android companion app for field data entry.

## Features

- Daily sales encoding with cashier attribution
- Production output tracking
- Inventory receive-stock and pullout/return flows
- Variance dashboards
- Cashier shift management
- Offline-capable mobile data entry (Android)
- Live deployment reset (full dl_* data wipe, preserves only current logged-in admin)
- Settings-based SQL backup (manual + auto-before-reset)

## Architecture

- **Auth-owned**: `dl_users` table, `admin` role, separate auth shell from CMS
- **Handler-based**: business logic in `handlers.php`, not exposed as kernel capabilities
- **40+ migrations**: incremental schema evolution with idempotent design
- **Android app**: standalone APK at `android/daily-ledger/`

## Key files

- Manifest: [`module.json`](module.json)
- Routes: [`routes.php`](routes.php)
- Handlers: [`handlers.php`](handlers.php)

## Documentation

Project-level docs: [`docs/daily-ledger/`](../../docs/daily-ledger/)

- Live deployment reset runbook: [`docs/daily-ledger/live-deployment-reset.md`](../../docs/daily-ledger/live-deployment-reset.md)
