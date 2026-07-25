# Inventory Scanner

Standalone barcode-based inventory scanning system with an offline-capable Android mobile app. Auth-owned module — manages `is_users` table with `admin` role.

## Features

- Barcode scanning via mobile device camera
- Offline-capable operation with sync when connectivity resumes
- Product lookup by barcode
- Stock count and adjustment
- Scan history with audit trail

## Capabilities

| Capability | Purpose |
|-----------|---------|
| `inventory-scanner.scan.lookup@1` | Look up product by barcode |
| `inventory-scanner.scan.save@1` | Save a scan result |
| `inventory-scanner.products.list@1` | List available products |
| `inventory-scanner.scan.sync@1` | Sync offline scans |
| `kernel.auth.authenticate@1` | Authenticate scanner users |

## Key files

- Manifest: [`module.json`](module.json)
- Handlers: [`handlers.php`](handlers.php)
- Android app: [`../../android/inventory-scanner/`](../../android/inventory-scanner/)
