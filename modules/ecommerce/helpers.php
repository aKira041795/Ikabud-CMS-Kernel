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
