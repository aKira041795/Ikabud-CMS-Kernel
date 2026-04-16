<?php

declare(strict_types=1);

// ── Ecommerce Module — Handler Concern Loader ────────────────────────
// Keep file order stable to preserve bootstrap side effects.
// Naming: NNN-concern.php matching route concern groups.

require_once __DIR__ . '/handlers/00-bootstrap.php';
require_once __DIR__ . '/handlers/10-public-shop.php';
require_once __DIR__ . '/handlers/15-public-cart.php';
require_once __DIR__ . '/handlers/18-public-compare.php';
require_once __DIR__ . '/handlers/19-public-wishlist.php';
require_once __DIR__ . '/handlers/20-public-checkout.php';
require_once __DIR__ . '/handlers/25-public-orders.php';
require_once __DIR__ . '/handlers/26-public-bookings.php';
require_once __DIR__ . '/handlers/30-admin-dashboard.php';
require_once __DIR__ . '/handlers/35-admin-products.php';
require_once __DIR__ . '/handlers/40-admin-orders.php';
require_once __DIR__ . '/handlers/45-admin-categories.php';
require_once __DIR__ . '/handlers/50-admin-settings.php';
require_once __DIR__ . '/handlers/52-admin-email-templates.php';
require_once __DIR__ . '/handlers/55-admin-reports.php';
require_once __DIR__ . '/handlers/56-admin-inventory.php';
require_once __DIR__ . '/handlers/57-admin-inventory-csv.php';
require_once __DIR__ . '/handlers/60-admin-coupons.php';
require_once __DIR__ . '/handlers/65-admin-customers.php';
require_once __DIR__ . '/handlers/66-admin-import-export.php';
require_once __DIR__ . '/handlers/67-admin-reviews.php';
require_once __DIR__ . '/handlers/68-admin-webhooks.php';
require_once __DIR__ . '/handlers/69-admin-abandoned-carts.php';
require_once __DIR__ . '/handlers/70-pos.php';
require_once __DIR__ . '/handlers/71-admin-memberships-loyalty.php';
require_once __DIR__ . '/handlers/72-admin-stores.php';
require_once __DIR__ . '/handlers/73-public-stores.php';
require_once __DIR__ . '/handlers/74-store-admin-access.php';
require_once __DIR__ . '/handlers/80-api-products.php';
require_once __DIR__ . '/handlers/82-api-cart.php';
require_once __DIR__ . '/handlers/83-api-abandoned-carts.php';
require_once __DIR__ . '/handlers/84-api-orders.php';
require_once __DIR__ . '/handlers/86-api-checkout.php';
require_once __DIR__ . '/handlers/87-payment-gateway.php';
require_once __DIR__ . '/handlers/88-api-reports.php';
require_once __DIR__ . '/handlers/90-api-coupons.php';
require_once __DIR__ . '/handlers/92-api-pos.php';
require_once __DIR__ . '/handlers/94-api-reviews.php';
