<?php

declare(strict_types=1);

// PHP's built-in server reports the requested path as SCRIPT_NAME when a
// front-controller router is used. Normalize it to the production shape so
// kernel_request_base_path() does not treat every route as a deployment root.
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';
$_SERVER['IKABUD_BASE_PATH'] = '/';
require dirname(__DIR__, 2) . '/public/index.php';
