<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/handlers/entity-views.php';

// Load DiSyL entity view configs
if (is_dir(__DIR__ . '/helpers/views')) {
    \Ikabud\Kernel\DiSyL\TemplateEngine::loadViewConfigs(__DIR__ . '/helpers/views');
}
require_once __DIR__ . '/handlers/00-bootstrap.php';
require_once __DIR__ . '/handlers/05-auth.php';
require_once __DIR__ . '/handlers/10-pages.php';
require_once __DIR__ . '/handlers/15-api-settings.php';
require_once __DIR__ . '/handlers/20-api-products-recipe.php';
require_once __DIR__ . '/handlers/25-api-product-targets.php';
require_once __DIR__ . '/handlers/30-api-deliveries.php';
require_once __DIR__ . '/handlers/35-api-imports.php';
require_once __DIR__ . '/handlers/40-api-production.php';
require_once __DIR__ . '/handlers/45-api-inventory-adjustments.php';
require_once __DIR__ . '/handlers/50-api-usage-report.php';
require_once __DIR__ . '/handlers/55-api-dr-projection.php';
require_once __DIR__ . '/handlers/56-api-suggested-reorder.php';
require_once __DIR__ . '/handlers/60-users.php';