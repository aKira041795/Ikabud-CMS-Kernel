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

// Stock that arrived during the shift. Before this was part of the equation a
// mid-shift delivery inflated Calculated Sales by exactly the quantity added,
// because the worksheet only knew about production.
$withAdditional = _dcInventoryDerivedMetrics(0.0, 0.0, 0.0, 22.0, 6.0, 22.0, 0.0, 28.0);
$check('A delivery is counted as additional stock', abs((float) $withAdditional['calculated_sales_qty'] - 6.0) < 0.001, json_encode($withAdditional));
$check('Additional stock reconciles the sale count exactly', abs((float) $withAdditional['sales_variance_qty']) < 0.001, json_encode($withAdditional));

$withoutAdditional = _dcInventoryDerivedMetrics(0.0, 0.0, 0.0, 22.0, 6.0, 22.0, 0.0, 0.0);
$check('Ignoring the delivery would overstate sales by the quantity added', abs((float) $withoutAdditional['calculated_sales_qty'] + 22.0) < 0.001, json_encode($withoutAdditional));

$defaulted = _dcInventoryDerivedMetrics(10.0, 5.0, 2.0, 4.0, 9.0, 4.0);
$check('Omitting additional keeps the previous behaviour', abs((float) $defaulted['calculated_sales_qty'] - 9.0) < 0.001, json_encode($defaulted));

$bothAdded = _dcInventoryDerivedMetrics(0.0, 5.0, 0.0, 3.0, 10.0, 3.0, 0.0, 8.0);
$check('Production and additional stock combine', abs((float) $bothAdded['calculated_sales_qty'] - 10.0) < 0.001, json_encode($bothAdded));

// A void reverses a sale, so it must never be treated as additional stock: the
// physical count already reflects the returned item. Start 20, sell 5, void 1,
// count 16 -> 4 real sales, and the worksheet must agree exactly.
$voided = _dcInventoryDerivedMetrics(20.0, 0.0, 0.0, 16.0, 4.0, 16.0, 0.0, 0.0);
$check('A voided sale leaves the equation balanced', abs((float) $voided['calculated_sales_qty'] - 4.0) < 0.001, json_encode($voided));
$check('A voided sale shows no sales variance', abs((float) $voided['sales_variance_qty']) < 0.001, json_encode($voided));

// Counting the void's stock restore as additional would invent a sale that
// never happened — this is the mistake the derivation deliberately avoids.
$voidAsAdditional = _dcInventoryDerivedMetrics(20.0, 0.0, 0.0, 16.0, 4.0, 16.0, 0.0, 1.0);
$check('Counting a void restore as additional would fabricate a sale',
    abs((float) $voidAsAdditional['sales_variance_qty'] - 1.0) < 0.001, json_encode($voidAsAdditional));

foreach ($tests as [$label, $ok, $detail]) {
    echo ($ok ? 'OK' : 'FAIL') . ' ' . $label;
    if ($detail !== '') {
        echo ' :: ' . $detail;
    }
    echo PHP_EOL;
}
exit($failures === 0 ? 0 : 1);
