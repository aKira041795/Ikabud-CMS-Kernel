# Ecommerce

Full-featured ecommerce module — products, cart, checkout, orders, POS, inventory, categories, coupons, sales reports, subscriptions, and multi-store support.

## Features

- **Product catalog**: variants, categories, tags, SEO metadata, grouped products, external products
- **Cart & checkout**: guest and logged-in flows, coupon/discount engine, multi-currency
- **Orders**: full lifecycle (pending → paid → fulfilled → shipped → delivered), refunds, returns
- **POS**: point-of-sale interface for brick-and-mortar operations
- **Subscriptions**: recurring billing, membership management
- **Multi-store**: per-store product/category isolation with shared customer base
- **Marketplace** (tenant-scoped): vendor management, commission splitting
- **Inventory**: stock tracking, low-stock alerts, WMS integration bridge
- **Shipments**: tracking, table-rate shipping, per-store shipping rules
- **Reports**: sales, inventory, customer analytics

## Key files

- Manifest: [`module.json`](module.json)
- Routes: [`routes.php`](routes.php)
- Handlers (38): [`handlers.php`](handlers.php)
- Helpers (41): [`helpers.php`](helpers.php)

## Dependencies

- `cms` — admin UI integration, entity views
- `wms` — inventory/fulfillment bridge (optional)

## Documentation

Project-level docs: [`docs/ecommerce/`](../../docs/ecommerce/)
