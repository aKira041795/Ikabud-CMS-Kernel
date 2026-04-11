<?php

declare(strict_types=1);

require_once __DIR__ . '/handlers/10-ingestion.php';
require_once __DIR__ . '/handlers/20-media.php';
require_once __DIR__ . '/handlers/40-lifecycle.php'; // must load before 30-admin (dashboard calls wpBridgeGetState/wpBridgeGetStats)
require_once __DIR__ . '/handlers/30-admin.php';
