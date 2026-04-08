<?php
$f = 'modules/wms/routes.php';
$c = file_get_contents($f);
$c = str_replace(
    "        '/wms/settings' => 'wms:wmsPageSettings',",
    "        '/wms/settings' => 'wms:wmsPageSettings',\n        '/wms/onboarding' => 'wms:wmsPageOnboarding',\n        '/wms/diagnostics' => 'wms:wmsPageDiagnostics',",
    $c
);
file_put_contents($f, $c);
