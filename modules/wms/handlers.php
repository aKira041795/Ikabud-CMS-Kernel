<?php

declare(strict_types=1);

// Load shared helpers
require_once __DIR__ . '/helpers.php';

// Load entity view configs
if (is_dir(__DIR__ . '/helpers/views')) {
    \Ikabud\Kernel\DiSyL\TemplateEngine::loadViewConfigs(__DIR__ . '/helpers/views');
}

// Load handlers
require_once __DIR__ . '/handlers/00-bootstrap.php';
require_once __DIR__ . '/handlers/05-auth.php';
require_once __DIR__ . '/handlers/10-pages.php';
require_once __DIR__ . '/handlers/20-api-catalog.php';
require_once __DIR__ . '/handlers/50-api-suppliers.php';
