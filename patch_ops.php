<?php
$f = 'modules/wms/helpers/30-operations.php';
$c = file_get_contents($f);
$c = str_replace(
    "\$strategy = strtolower((string)(wmsSettings()['picking_strategy'] ?? 'fefo'));",
    "\$strategy = strtolower((string)wmsConfigGet('picking.default_strategy', 'fefo'));",
    $c
);
file_put_contents($f, $c);
