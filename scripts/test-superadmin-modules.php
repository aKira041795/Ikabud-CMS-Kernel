<?php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

require __DIR__ . '/../bootstrap.php';
require SRC_PATH . '/helpers/module-manager.php';

echo "=== Discover modules with settings_fields ===\n";
$allModules = discoverModules();
echo "Total modules discovered: " . count($allModules) . "\n\n";

foreach ($allModules as $m) {
    $moduleId = (string)($m['id'] ?? '');
    $enabled = !empty($m['_enabled']);
    $fields = is_array($m['settings_fields'] ?? null) ? $m['settings_fields'] : [];
    echo "{$moduleId}: enabled=" . ($enabled ? 'yes' : 'no') . ", settings_fields=" . count($fields) . "\n";
    if (!empty($fields)) {
        foreach ($fields as $f) {
            echo "  - {$f['key']}: type={$f['type']}, label=" . ($f['label'] ?? 'n/a') . "\n";
        }
    }
}

echo "\n=== Modules that would appear on superadmin settings page ===\n";
$moduleList = [];
foreach ($allModules as $m) {
    $moduleId = (string)($m['id'] ?? '');
    if ($moduleId === '' || empty($m['_enabled'])) {
        echo "SKIP {$moduleId}: not enabled or no id\n";
        continue;
    }
    $fields = is_array($m['settings_fields'] ?? null) ? array_values($m['settings_fields']) : [];
    if (empty($fields)) {
        echo "SKIP {$moduleId}: no settings_fields\n";
        continue;
    }
    $modSettings = getModuleSettings($moduleId);
    echo "SHOW {$moduleId}: " . count($fields) . " fields, settings=" . json_encode($modSettings) . "\n";
    $moduleList[] = $moduleId;
}

echo "\n=== Result: " . count($moduleList) . " module(s) on settings page ===\n";
if (empty($moduleList)) {
    echo "PROBLEM: No modules with settings will be shown!\n";
} else {
    echo "Modules: " . implode(', ', $moduleList) . "\n";
}
