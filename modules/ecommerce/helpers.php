<?php

declare(strict_types=1);

// ── Ecommerce Module — Helper Loader ────────────────────────────────
// Loaded by module-manager when ecommerce module is active.

require_once __DIR__ . '/helpers/00-init.php';
require_once __DIR__ . '/helpers/05-render-contracts.php';
require_once __DIR__ . '/helpers/08-currency.php';
require_once __DIR__ . '/helpers/10-cart.php';
require_once __DIR__ . '/helpers/20-orders.php';
require_once __DIR__ . '/helpers/22-returns.php';
require_once __DIR__ . '/helpers/30-products.php';
require_once __DIR__ . '/helpers/32-attributes.php';
require_once __DIR__ . '/helpers/33-recently-viewed.php';
require_once __DIR__ . '/helpers/34-comparison.php';
require_once __DIR__ . '/helpers/40-pricing.php';
require_once __DIR__ . '/helpers/50-reports.php';
require_once __DIR__ . '/helpers/55-digital-licenses.php';
require_once __DIR__ . '/helpers/58-email-templates.php';
require_once __DIR__ . '/helpers/59-abandoned-carts.php';
require_once __DIR__ . '/helpers/56-order-notifications.php';
require_once __DIR__ . '/helpers/57-customer-notifications.php';
require_once __DIR__ . '/helpers/58-outbound-webhooks.php';
require_once __DIR__ . '/helpers/60-pos.php';
require_once __DIR__ . '/helpers/65-customers.php';
require_once __DIR__ . '/helpers/66-import-export.php';
require_once __DIR__ . '/helpers/75-reviews.php';
require_once __DIR__ . '/helpers/70-payment-gateways.php';
require_once __DIR__ . '/helpers/71-gateway-paymongo.php';
require_once __DIR__ . '/helpers/72-gateway-stripe.php';
require_once __DIR__ . '/helpers/73-gateway-paypal.php';
require_once __DIR__ . '/helpers/85-subscriptions.php';

function ecommerce_capability_handlers(): array
{
	return [
		'cms.cart.add@1' => 'ec_cap_cms_cart_add_1',
		'ecommerce.products.list@1' => 'ec_cap_products_list_1',
		'ecommerce.products.get@1' => 'ec_cap_products_get_1',
		'ecommerce.product.upsert@1' => 'ec_cap_product_upsert_1',
		'ecommerce.cart.get@1' => 'ec_cap_cart_get_1',
		'ecommerce.orders.create@1' => 'ec_cap_orders_create_1',
		'ecommerce.orders.get@1' => 'ec_cap_orders_get_1',
		'ecommerce.orders.status.sync@1' => 'ec_cap_orders_status_sync_1',
		'ecommerce.orders.tracking.sync@1' => 'ec_cap_orders_tracking_sync_1',
		'ecommerce.orders.payment.sync@1' => 'ec_cap_orders_payment_sync_1',
	];
}

function ec_cap_cms_cart_add_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
	return [
		'ok' => true,
		'action_url' => rtrim((string)(defined('BASE_URL') ? BASE_URL : ''), '/') . '/api/v1/ecommerce/cart/add',
	];
}
