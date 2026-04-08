<?php
$f = 'modules/wms/handlers.php';
$lines = file($f);
$out = [];
foreach ($lines as $l) {
    if (trim($l) === '') continue;
    $out[trim($l)] = true;
}
file_put_contents($f, implode("\n", array_keys($out)) . "\n");
