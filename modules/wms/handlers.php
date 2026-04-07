<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/handlers/00-bootstrap.php';
require_once __DIR__ . '/handlers/05-auth.php';
require_once __DIR__ . '/handlers/10-pages.php';
require_once __DIR__ . '/handlers/20-api-catalog.php';
require_once __DIR__ . '/handlers/30-api-inventory.php';
require_once __DIR__ . '/handlers/40-api-operations.php';
require_once __DIR__ . '/handlers/50-api-suppliers.php';
require_once __DIR__ . '/handlers/60-api-returns.php';
require_once __DIR__ . '/handlers/70-api-users.php';
require_once __DIR__ . '/handlers/80-api-tasks.php';
