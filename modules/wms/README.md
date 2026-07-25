# Warehouse Management System (WMS)

ERP-lite warehouse management with movement-ledger accounting, barcode scanning, and ecommerce integration. Auth-owned module — manages `wms_users` table with `admin` role.

## Features

- **Catalog management**: products, SKUs, variants, categories, supplier catalog
- **Movement ledger**: receipt, put-away, transfer, pick, pack, ship — all movements tracked with weighted-average costing
- **Inbound**: purchase orders, receiving, quality inspection, put-away
- **Outbound**: picklists, packing, shipping, delivery tracking
- **Fulfillment**: order-to-ship workflow with ecommerce bridge
- **Inventory**: stock levels, physical counts, adjustments, batch/lot tracking
- **Supplier management**: vendor profiles, pricing, lead times
- **Advanced operations**: transfers between warehouses, cycle counting, ABC analysis

## Key files

- Manifest: [`module.json`](module.json)
- Routes: [`routes.php`](routes.php)
- Handlers (8): [`handlers.php`](handlers.php)
- Migrations (8): [`migrations/`](migrations/)
- Templates (20): [`templates/`](templates/)

## Dependencies

- `ecommerce` — fulfillment/order bridge (optional)
