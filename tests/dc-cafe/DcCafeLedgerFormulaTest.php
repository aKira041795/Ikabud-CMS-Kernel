<?php
declare(strict_types=1);

require_once '/var/www/html/applicationostest/modules/dc-cafe/handlers-inventory.php';

$tests = [];
$failures = 0;
$check = static function (string $label, bool $ok, string $detail = '') use (&$tests, &$failures): void {
    $tests[] = [$label, $ok, $detail];
    if (!$ok) {
        $failures++;
    }
};

$basic = _dcInventoryDerivedMetrics(10.0, 5.0, 2.0, 4.0, 8.0, 4.0);
$check('10 + 5 - 2 - 4 = 9 calculated sales', abs((float) $basic['calculated_sales_qty'] - 9.0) < 0.001, json_encode($basic));
$check('Sales variance uses calculated minus POS', abs((float) $basic['sales_variance_qty'] - 1.0) < 0.001, json_encode($basic));
$check('Stock variance uses ending minus branch stock', abs((float) $basic['stock_variance_qty'] - 0.0) < 0.001, json_encode($basic));

$zeroEnding = _dcInventoryDerivedMetrics(2.5, 1.25, 0.5, 0.0, 1.0, 0.0);
$check('Recorded ending zero stays valid', abs((float) $zeroEnding['calculated_sales_qty'] - 3.25) < 0.001, json_encode($zeroEnding));

$decimal = _dcInventoryDerivedMetrics(1.75, 0.80, 0.30, 0.45, 1.20, 0.60);
$check('Decimal quantities retain precision', abs((float) $decimal['calculated_sales_qty'] - 1.80) < 0.001, json_encode($decimal));

$negative = _dcInventoryDerivedMetrics(1.0, 0.0, 0.0, 2.0, 0.0, 2.0);
$check('Ending larger than available yields negative calculated sales', abs((float) $negative['calculated_sales_qty'] + 1.0) < 0.001, json_encode($negative));

$pending = _dcInventoryDerivedMetrics(3.0, 1.0, 0.0, null, 2.0, 5.0);
$check('Pending ending keeps calculated sales unavailable', $pending['calculated_sales_qty'] === null && $pending['sales_variance_qty'] === null && $pending['stock_variance_qty'] === null, json_encode($pending));

foreach ($tests as [$label, $ok, $detail]) {
    echo ($ok ? 'OK' : 'FAIL') . ' ' . $label;
    if ($detail !== '') {
        echo ' :: ' . $detail;
    }
    echo PHP_EOL;
}
exit($failures === 0 ? 0 : 1);
