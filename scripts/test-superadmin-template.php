<?php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

require __DIR__ . '/../bootstrap.php';
require SRC_PATH . '/helpers/module-manager.php';

// Simulate the pageSuperadminSettings handler data
$allModules = discoverModules();
$moduleList = [];
foreach ($allModules as $m) {
    $moduleId = (string)($m['id'] ?? '');
    if ($moduleId === '' || empty($m['_enabled'])) continue;
    $fields = is_array($m['settings_fields'] ?? null) ? array_values($m['settings_fields']) : [];
    if (empty($fields)) continue;
    $modSettings = getModuleSettings($moduleId);
    $settingsUrl = '';

    $renderedFields = [];
    foreach ($fields as $field) {
        $key = (string)($field['key'] ?? '');
        if ($key === '') continue;
        $type = strtolower(trim((string)($field['type'] ?? 'text')));
        $currentValue = $modSettings[$key] ?? '';
        $isCheckbox = in_array($type, ['checkbox', 'bool', 'boolean'], true);
        $isSelect = ($type === 'select');
        $inputType = in_array($type, ['number', 'int', 'integer'], true) ? 'number' : ($type === 'email' ? 'email' : 'text');

        $options = [];
        if ($isSelect && is_array($field['options'] ?? null)) {
            foreach ($field['options'] as $opt) {
                if (!is_array($opt)) continue;
                $options[] = [
                    'value' => (string)($opt['value'] ?? ''),
                    'label' => (string)($opt['label'] ?? $opt['value'] ?? ''),
                    'selected' => ((string)$currentValue === (string)($opt['value'] ?? '')),
                ];
            }
        }

        $renderedFields[] = [
            'key' => $key,
            'label' => (string)($field['label'] ?? $key),
            'description' => (string)($field['description'] ?? ''),
            'type' => $type,
            'is_checkbox' => $isCheckbox,
            'is_select' => $isSelect,
            'is_text' => (!$isCheckbox && !$isSelect),
            'input_type' => $inputType,
            'current_value' => $isCheckbox ? '' : (string)$currentValue,
            'is_checked' => $isCheckbox && !empty($currentValue),
            'options' => $options,
        ];
    }

    $moduleList[] = [
        'id' => $moduleId,
        'name' => $m['name'] ?? $moduleId,
        'version' => $m['version'] ?? '0.0.0',
        'description' => $m['description'] ?? '',
        'fields' => $renderedFields,
        'settings_url' => $settingsUrl,
    ];
}

echo "=== Module data check ===\n";
foreach ($moduleList as $mod) {
    echo "\nModule: {$mod['name']} (v{$mod['version']})\n";
    foreach ($mod['fields'] as $f) {
        echo "  Field: {$f['key']} | type={$f['type']} | is_checkbox=" . ($f['is_checkbox'] ? 'Y' : 'N')
           . " | is_select=" . ($f['is_select'] ? 'Y' : 'N')
           . " | is_text=" . ($f['is_text'] ? 'Y' : 'N')
           . " | checked=" . ($f['is_checked'] ? 'Y' : 'N')
           . " | value=\"{$f['current_value']}\"\n";
        if (!empty($f['options'])) {
            foreach ($f['options'] as $o) {
                echo "    Option: {$o['value']} ({$o['label']}) " . ($o['selected'] ? '[SELECTED]' : '') . "\n";
            }
        }
    }
}

echo "\n=== Template render test ===\n";
// Use the DiSyL engine to render
$app = (function() {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/superadmin/settings';
    $_SERVER['HTTP_HOST'] = 'baroninventory.test';
    return app();
})();

$html = $app->render('pages/superadmin-settings.disyl', [
    'page_title' => 'Feature Settings',
    'modules' => $moduleList,
]);

// Check for raw DiSyL tags that weren't rendered
if (preg_match('/\{assign\s/', $html)) {
    echo "FAIL: {assign} tags still in output!\n";
    exit(1);
}
if (preg_match('/\{mod\.settings\[/', $html)) {
    echo "FAIL: bracket notation still in output!\n";
    exit(1);
}
if (strpos($html, 'field.type | lower') !== false) {
    echo "FAIL: unprocessed filter expression in output!\n";
    exit(1);
}

// Check expected content is present
$checks = [
    'Enable Production Output' => strpos($html, 'Enable Production Output') !== false,
    'data-sa-key="production_output_enabled"' => strpos($html, 'data-sa-key="production_output_enabled"') !== false,
    'data-sa-type="checkbox"' => strpos($html, 'data-sa-type="checkbox"') !== false,
    'checked' => strpos($html, 'checked') !== false,
    'Recipient Email' => strpos($html, 'Recipient Email') !== false,
    'data-sa-key="recipient_email"' => strpos($html, 'data-sa-key="recipient_email"') !== false,
    'Feature Settings' => strpos($html, 'Feature Settings') !== false,
    'Simple Contact Form' => strpos($html, 'Simple Contact Form') !== false,
    'Daily Ledger' => strpos($html, 'Daily Ledger') !== false,
];

$allOk = true;
foreach ($checks as $label => $ok) {
    echo ($ok ? 'PASS' : 'FAIL') . ": {$label}\n";
    if (!$ok) $allOk = false;
}

if ($allOk) {
    echo "\n=== ALL CHECKS PASSED ===\n";
} else {
    echo "\n=== SOME CHECKS FAILED ===\n";
    // Show a snippet of the rendered HTML for debugging
    echo "\n--- HTML snippet (first 2000 chars) ---\n";
    echo substr($html, 0, 2000) . "\n";
}
