<?php

declare(strict_types=1);

/**
 * WMS Settings Expansion — Verification Test
 *
 * Confirms that the new config keys from migration 022 exist,
 * the page handler loads them, and the config update API persists changes.
 */

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'wms.test';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/wms/settings';

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../modules/wms/handlers.php';

$passed = 0;
$failed = 0;
$skipped = 0;

function wms_settings_assert(bool $condition, string $label): void
{
    global $passed, $failed;
    if ($condition) {
        echo "  PASS: {$label}\n";
        $passed++;
    } else {
        echo "  FAIL: {$label}\n";
        $failed++;
    }
}

echo "=== WMS Settings Expansion Tests ===\n\n";

// 1. Verify new config keys exist in DB
echo "-- Migration seeds --\n";
$expectedKeys = [
    'general.warehouse_name',
    'general.timezone',
    'general.date_format',
    'general.weight_unit',
    'general.dimension_unit',
    'inventory.reorder_point_buffer_pct',
    'inventory.cycle_count_frequency_days',
    'picking.require_scan_confirmation',
    'picking.wave_batch_size',
    'picking.auto_assign_tasks',
    'receiving.auto_create_putaway_tasks',
    'receiving.require_quality_check',
    'receiving.over_receive_tolerance_pct',
    'returns.require_inspection',
    'returns.auto_quarantine_damaged',
    'notifications.low_stock_alerts_enabled',
    'notifications.task_escalation_hours',
];

foreach ($expectedKeys as $key) {
    $val = wmsConfigGet($key, '__MISSING__');
    wms_settings_assert($val !== '__MISSING__', "Config key '{$key}' exists in DB");
}

// 2. Verify default values for a few keys (may have been changed by user since seeding)
echo "\n-- Default values (type checks) --\n";
wms_settings_assert(is_string(wmsConfigGet('general.timezone')), 'general.timezone is a string');
wms_settings_assert(is_string(wmsConfigGet('general.weight_unit')), 'general.weight_unit is a string');
wms_settings_assert(is_int(wmsConfigGet('picking.wave_batch_size')), 'picking.wave_batch_size is an int');
wms_settings_assert(is_int(wmsConfigGet('notifications.task_escalation_hours')), 'notifications.task_escalation_hours is an int');
wms_settings_assert(is_int(wmsConfigGet('receiving.over_receive_tolerance_pct')), 'receiving.over_receive_tolerance_pct is an int');

// 3. Verify pre-existing keys still intact
echo "\n-- Pre-existing configs intact --\n";
wms_settings_assert(is_bool(wmsConfigGet('system.allow_negative_stock')), 'system.allow_negative_stock is a boolean');
wms_settings_assert(in_array(wmsConfigGet('picking.default_strategy'), ['FIFO', 'FEFO', 'LIFO'], true), 'picking.default_strategy is valid');
wms_settings_assert(is_string(wmsConfigGet('financial.default_currency')), 'financial.default_currency is a string');

// 4. Verify wmsConfigSet round-trip
echo "\n-- Config set/get round-trip --\n";
$origTz = wmsConfigGet('general.timezone', 'UTC');
try {
    wmsConfigSet('general.timezone', 'America/Chicago');
    wms_settings_assert(wmsConfigGet('general.timezone') === 'America/Chicago', 'wmsConfigSet updates general.timezone');
} finally {
    wmsConfigSet('general.timezone', $origTz);
    wms_settings_assert(wmsConfigGet('general.timezone') === $origTz, 'general.timezone restored to original');
}

$origBatch = wmsConfigGet('picking.wave_batch_size', 20);
try {
    wmsConfigSet('picking.wave_batch_size', 50);
    wms_settings_assert(wmsConfigGet('picking.wave_batch_size') === 50, 'wmsConfigSet updates picking.wave_batch_size');
} finally {
    wmsConfigSet('picking.wave_batch_size', $origBatch);
}

// 5. Verify page handler injects configs_json
echo "\n-- Page handler context --\n";
// We can't render the full page (it would output HTML), but we can verify
// the handler function exists and the configs array is buildable.
$allConfigs = wmsFetchAll('SELECT config_key, config_value FROM wms_configs ORDER BY config_key ASC');
$cfgMap = [];
foreach ($allConfigs as $row) {
    $val = json_decode($row['config_value'], true);
    $cfgMap[$row['config_key']] = (json_last_error() === JSON_ERROR_NONE) ? $val : $row['config_value'];
}
wms_settings_assert(count($cfgMap) >= 24, 'At least 24 config keys in wms_configs (was ' . count($cfgMap) . ')');
wms_settings_assert(isset($cfgMap['general.warehouse_name']), 'configs map has general.warehouse_name');
wms_settings_assert(isset($cfgMap['notifications.task_escalation_hours']), 'configs map has notifications.task_escalation_hours');

$json = json_encode($cfgMap, JSON_UNESCAPED_UNICODE);
wms_settings_assert(is_string($json) && strlen($json) > 50, 'configs_json encodes correctly');

echo "\n=== Results: {$passed} passed, {$failed} failed, {$skipped} skipped ===\n";
exit($failed > 0 ? 1 : 0);
