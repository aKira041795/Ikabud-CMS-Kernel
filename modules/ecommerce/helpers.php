<?php

declare(strict_types=1);

// ── Ecommerce Module — Helper Loader ────────────────────────────────
// Loaded by module-manager when ecommerce module is active.

require_once __DIR__ . '/helpers/00-init.php';
require_once __DIR__ . '/helpers/10-cart.php';
require_once __DIR__ . '/helpers/20-orders.php';
require_once __DIR__ . '/helpers/30-products.php';
require_once __DIR__ . '/helpers/40-pricing.php';
require_once __DIR__ . '/helpers/50-reports.php';
require_once __DIR__ . '/helpers/60-pos.php';

function ecommerce_capability_handlers(): array
{
	return [
		'ecommerce.products.list@1' => 'ec_cap_products_list_1',
		'ecommerce.products.get@1' => 'ec_cap_products_get_1',
		'ecommerce.cart.get@1' => 'ec_cap_cart_get_1',
		'ecommerce.orders.create@1' => 'ec_cap_orders_create_1',
		'ecommerce.orders.get@1' => 'ec_cap_orders_get_1',
	];
}
