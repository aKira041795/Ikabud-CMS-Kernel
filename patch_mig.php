<?php
$f = 'modules/wms/database/migrations/017_wms_phase6_financials.sql';
$c = file_get_contents($f);
$c = str_replace(
    "    supplier_id INT UNSIGNED NOT NULL,\n    status ENUM",
    "    supplier_id INT UNSIGNED NOT NULL,\n    warehouse_id INT UNSIGNED NOT NULL,\n    status ENUM",
    $c
);
file_put_contents($f, $c);
