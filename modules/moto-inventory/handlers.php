<?php

declare(strict_types=1);

/**
 * Moto Inventory — Handler loader.
 *
 * Capability handlers live in helpers.php (loaded before handler files so the
 * module manager can resolve them at registration time). Handlers below are
 * split by domain and only contain request validation + service calls.
 */

require_once __DIR__ . '/helpers.php';

require_once __DIR__ . '/handlers/00-bootstrap.php';
require_once __DIR__ . '/handlers/10-pages.php';
require_once __DIR__ . '/handlers/20-api-catalog.php';
require_once __DIR__ . '/handlers/30-api-stock.php';
require_once __DIR__ . '/handlers/40-api-sales.php';
require_once __DIR__ . '/handlers/50-api-import-export.php';
require_once __DIR__ . '/handlers/55-api-import-templates.php';
require_once __DIR__ . '/handlers/60-api-reports-audit.php';
require_once __DIR__ . '/handlers/70-api-users.php';
