<?php
$f = 'modules/wms/handlers.php';
$c = file_get_contents($f);
$c .= "\nrequire_once __DIR__ . '/handlers/120-api-configs.php';\nrequire_once __DIR__ . '/handlers/130-api-onboarding.php';\n";
file_put_contents($f, $c);
