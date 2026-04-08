<?php
$f = 'modules/wms/helpers/20-stock.php';
$c = file_get_contents($f);
$c = str_replace(
    "\$settings = wmsSettings();\n    \$threshold = \$threshold ?? (int)(\$settings['low_stock_threshold'] ?? 10);",
    "\$threshold = \$threshold ?? wmsConfigGet('low_stock_threshold', 10);",
    $c
);
$c = str_replace(
    "\$settings = wmsSettings();\n        \$allowNegative = (bool)(\$settings['allow_negative_stock'] ?? false);",
    "\$allowNegative = (bool)wmsConfigGet('system.allow_negative_stock', false);",
    $c
);
file_put_contents($f, $c);
