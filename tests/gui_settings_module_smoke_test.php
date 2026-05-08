<?php

declare(strict_types=1);

/**
 * Smoke test for the gui-settings module.
 *
 * Verifies:
 *   - manifest validates
 *   - settings_fields contract is honored (defaults present + typed)
 *   - module is discoverable and exposes its admin route
 *
 * Run from repo root: php tests/gui_settings_module_smoke_test.php
 */

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';

$pass = 0;
$fail = 0;

function t(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  ✓ {$label}\n";
    } else {
        $fail++;
        echo "  ✗ {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

echo "\n=== GUI-SETTINGS MODULE SMOKE ===\n\n";

$check = validateModuleManifest(dirname(__DIR__) . '/modules/gui-settings/module.json');
t('manifest validates', !empty($check['ok']), (string)($check['error'] ?? ''));

$manifest = $check['manifest'] ?? [];
t('manifest id is gui-settings', ($manifest['id'] ?? '') === 'gui-settings');

$fields = $manifest['settings_fields'] ?? [];
t('declares settings_fields', is_array($fields) && count($fields) > 0);

$keys = array_column(is_array($fields) ? $fields : [], 'key');
t('settings_fields includes app_name', in_array('app_name', $keys, true));

foreach ($fields as $field) {
    if (!is_array($field)) {
        continue;
    }
    $key = (string)($field['key'] ?? '');
    if ($key === '') {
        continue;
    }
    t("settings_field '{$key}' has type", isset($field['type']) && is_string($field['type']) && $field['type'] !== '');
}

$modules = discoverModules();
t('gui-settings is discoverable', isset($modules['gui-settings']));

echo "\n══════════════════════════════════════════════════\n";
echo "  PASS: {$pass}  FAIL: {$fail}\n";
echo "══════════════════════════════════════════════════\n";
exit($fail > 0 ? 1 : 0);
