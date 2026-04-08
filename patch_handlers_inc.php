<?php
$f = 'modules/wms/handlers.php';
$c = file_get_contents($f);
$c = str_replace(
    "require __DIR__ . '/handlers/110-api-events.php';",
    "require __DIR__ . '/handlers/110-api-events.php';\nrequire __DIR__ . '/handlers/120-api-configs.php';\nrequire __DIR__ . '/handlers/130-api-onboarding.php';",
    $c
);
file_put_contents($f, $c);
