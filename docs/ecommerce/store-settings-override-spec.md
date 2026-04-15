# Store Settings Override Specification

## Overview

The ecommerce module uses a **two-tier settings architecture** where every configurable setting has a resolution chain:

```
Store Override → Global Default → Manifest Default
```

This document specifies which settings are overridable at the store level, how the resolution works, and the implementation contracts.

## Resolution Function

All store-aware setting reads go through `ecStoreAwareSetting()`:

```php
ecStoreAwareSetting(string $key, array|int|null $store = null, mixed $default = null): mixed
```

1. If `$store` is provided and its `settings_json` contains `$key` with a non-empty value → return store value
2. Else → return `ecSettings($key)` (global tenant setting)
3. If global is also empty → return `$default`

Convenience wrappers exist for common patterns:
- `ecStoreAwareCurrencySymbol($store)` — currency symbol with store → global → '$' fallback

## Store-Overridable Fields

### Already Implemented (Phase C)

| Field Key | Type | UI Location | Description |
|-----------|------|-------------|-------------|
| `currency` | string | Store Settings → Store-Level | ISO 4217 currency code |
| `currency_symbol` | string | Store Settings → Store-Level | Display symbol (e.g., ₱, $, €) |
| `timezone` | string | Store Settings → Store-Level | PHP timezone identifier |
| `tax_rate` | string | Store Settings → Store-Level | Tax percentage |
| `checkout_note` | string | Store Settings → Store-Level | Note shown at checkout |
| `shipping_mode` | enum(flat,table) | Store Settings → Shipping | Shipping calculation mode |
| `shipping_label` | string | Store Settings → Shipping | Shipping method display name |
| `shipping_carrier` | string | Store Settings → Shipping | Default carrier name |
| `shipping_estimated_days` | string | Store Settings → Shipping | Estimated delivery days text |
| `shipping_default_country` | string | Store Settings → Shipping | Default shipping country |
| `shipping_flat_rate` | float | Store Settings → Shipping | Flat shipping rate |
| `shipping_free_above` | float | Store Settings → Shipping | Free shipping threshold |
| `shipping_table_rate_rules` | text | Store Settings → Shipping | Pipe-delimited rate rules |
| `storefront_theme` | enum(orange,indigo,emerald,rose) | Store Settings → Storefront | Color theme |
| `store_banner_mode` | enum(show,hide) | Store Settings → Storefront | Banner display mode |
| `store_banner_*` | various | Store Settings → Storefront | Banner headline, subtext, image, CTA |
| `social_links_mode` | enum(custom,hide) | Store Settings → Storefront | Social links display mode |
| `social_*` | string | Store Settings → Storefront | Social media URLs |
| `store_hours_mode` | enum(custom,hide) | Store Settings → Storefront | Operating hours mode |
| `store_hours` | json | Store Settings → Storefront | Per-day hours schedule |

### New Fields (This Spec)

| Field Key | Type | UI Location | Description | Global Default |
|-----------|------|-------------|-------------|----------------|
| `products_per_page` | int (4-100) | Store Settings → Catalog & Display | Products shown per page | `ecSettings('products_per_page')` |
| `shop_page_title` | string | Store Settings → Catalog & Display | Store's shop page heading | `ecSettings('shop_page_title')` |
| `guest_checkout` | bool (1/0) | Store Settings → Checkout | Allow guest checkout | `ecSettings('guest_checkout')` |
| `payment_method_label` | string | Store Settings → Checkout | Payment method display name | `ecSettings('payment_method_label')` |
| `require_account_for_digital` | bool (1/0) | Store Settings → Checkout | Require account for digital products | `ecSettings('require_account_for_digital')` |
| `low_stock_threshold` | int (0-999) | Store Settings → Inventory | Low stock warning threshold | `ecSettings('low_stock_threshold')` |
| `order_number_prefix` | string | Store Settings → Orders | Per-store order number prefix | `ecSettings('order_number_prefix')` |

## Override Mode Semantics

For all fields:
- **Empty/blank value** = "Use global default" (no override)
- **Explicit value** = "Override global for this store"

For boolean fields stored as `'1'`/`'0'`:
- **'1'** = enabled (override)
- **'0'** = disabled (override)
- **'' (empty)** = inherit global

For mode fields (`store_banner_mode`, `social_links_mode`, `store_hours_mode`):
- **'' (empty)** = inherit global
- **'show'/'custom'** = use store-specific values
- **'hide'** = suppress (override with nothing)

## Consumer Contract

### Public Handlers
All public handlers that resolve display settings MUST use store context when available:

| Handler | Store Context Source | Settings Consumed |
|---------|---------------------|-------------------|
| `ecPublicShop()` | `ecPublicStorefrontRequestedStoreFilter()` | products_per_page, shop_page_title |
| `ecPublicCategory()` | `ecPublicStorefrontRequestedStoreFilter()` | products_per_page, shop_page_title |
| `ecPublicStorePage()` | `$store` (route param) | products_per_page |
| `ecPublicCheckout()` | `ecCartResolvedStore()` | guest_checkout, payment_method_label, require_account_for_digital |
| `ecApiCheckout()` | `ecCartResolvedStore()` | guest_checkout, require_account_for_digital |
| `ecProductList()` | `$filters['store_id']` | currency, currency_symbol, low_stock_threshold |

### Admin Views
Store-admin views already correctly use `ecStoreAdminContext()` which resolves currency per-store. Admin-level views use global settings (correct — they show cross-store data).

### Order Snapshots
Orders snapshot currency at checkout time into `ec_orders.currency_symbol`. Order display always prefers the snapshot; global fallback is only for legacy orders without snapshots.

## Implementation Checklist

- [x] `ecStoreAwareSetting()` in `helpers/38-stores.php`
- [x] `ecStoreAwareCurrencySymbol()` in `helpers/38-stores.php`
- [x] Expand `ecStoreSettingsJsonFromInput()` for new fields
- [x] Update store-admin settings template with new field sections
- [x] Fix `handlers/10-public-shop.php` — use store-aware products_per_page, shop_page_title
- [x] Fix `handlers/20-public-checkout.php` — use store-aware checkout settings
- [x] Fix `handlers/73-public-stores.php` — use store-aware products_per_page
- [x] Fix `handlers/80-api-products.php` — use store-aware products_per_page, shop_page_title
- [x] Fix `handlers/86-api-checkout.php` — use store-aware guest_checkout, require_account_for_digital
- [x] Fix `helpers/30-catalog.php` — thread store context through currency resolution
- [x] Fix `helpers/59-abandoned-carts.php` — use stored currency on records
