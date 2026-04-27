<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/handlers/00-bootstrap.php';
require_once __DIR__ . '/handlers/05-auth.php';
require_once __DIR__ . '/handlers/10-pages.php';
require_once __DIR__ . '/handlers/15-api-settings.php';
require_once __DIR__ . '/handlers/20-api-products-recipe.php';
require_once __DIR__ . '/handlers/30-api-deliveries.php';
require_once __DIR__ . '/handlers/40-api-production.php';
require_once __DIR__ . '/handlers/50-api-usage-report.php';
require_once __DIR__ . '/handlers/60-users.php';