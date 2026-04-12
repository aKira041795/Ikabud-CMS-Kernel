<?php

declare(strict_types=1);

$_SERVER['HTTP_HOST'] = 'cmsnew.test';
$_SERVER['REQUEST_URI'] = '/';

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../modules/cms/helpers.php';
require_once __DIR__ . '/../modules/ecommerce/helpers.php';
require_once __DIR__ . '/../modules/anti-spam/helpers.php';
require_once __DIR__ . '/../modules/ai/helpers.php';
require_once __DIR__ . '/../modules/daily-ledger/handlers.php';
require_once __DIR__ . '/../modules/sms/helpers/sms-gateway.php';
require_once __DIR__ . '/../modules/ticketing/handlers.php';
require_once __DIR__ . '/../modules/gui-settings/helpers.php';
require_once __DIR__ . '/../modules/contact-form/helpers.php';
require_once __DIR__ . '/../modules/guidance/handlers.php';

$pass = 0;
$fail = 0;
$errors = [];

function t(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $errors;
    if ($ok) {
        $pass++;
        echo "  ✓ {$label}\n";
    } else {
        $fail++;
        $errors[] = $label . ($detail !== '' ? ": {$detail}" : '');
        echo "  ✗ {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

function manifestDefaults(string $moduleId): array
{
    $manifest = discoverModules()[$moduleId] ?? [];
    $fields = is_array($manifest['settings_fields'] ?? null) ? $manifest['settings_fields'] : [];
    $defaults = [];

    foreach ($fields as $field) {
        if (!is_array($field)) {
            continue;
        }

        $key = trim((string)($field['key'] ?? ''));
        if ($key === '' || !array_key_exists('default', $field)) {
            continue;
        }

        $defaults[$key] = $field['default'];
    }

    return $defaults;
}

function restoreTenantModuleSetting(string $moduleId, string $key, bool $hadOriginal, mixed $originalValue): void
{
    $tenantId = moduleTenantSettingsTenantId();
    if ($tenantId === null) {
        return;
    }

    \Ikabud\Kernel\Database\KernelPDO::kernelEscalationEnter();
    try {
        $db = app()->db();
        $table = moduleTenantSettingsTable();
        if (!$hadOriginal) {
            $stmt = $db->prepare("DELETE FROM {$table} WHERE tenant_id = :tid AND module_id = :mid AND setting_key = :skey");
            $stmt->execute([':tid' => $tenantId, ':mid' => $moduleId, ':skey' => $key]);
            return;
        }

        $stmt = $db->prepare(
            "INSERT INTO {$table} (tenant_id, module_id, setting_key, setting_value, created_at, updated_at)\n"
            . "VALUES (:tid, :mid, :skey, :sval, NOW(), NOW())\n"
            . "ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()"
        );
        $stmt->execute([
            ':tid' => $tenantId,
            ':mid' => $moduleId,
            ':skey' => $key,
            ':sval' => json_encode($originalValue, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
    } finally {
        \Ikabud\Kernel\Database\KernelPDO::kernelEscalationLeave();
        invalidateTenantModuleSettingsCache();
        $tid = $tenantId ?? 0;
        $GLOBALS['cms_settings_cached_t' . $tid] = false;
    }
}

file_put_contents(STORAGE_PATH . '/logs/app.log', '');
file_put_contents(STORAGE_PATH . '/logs/error.log', '');

echo "\n=== MANIFEST DEFAULT CONTRACT ===\n";

$contracts = [
    'ecommerce' => ['fn' => 'ecSettingsDefaults'],
    'anti-spam' => ['fn' => 'antispamDefaultSettings'],
    'ai' => ['fn' => 'aiSettingsDefaults'],
    'daily-ledger' => ['fn' => 'dlSettingsDefaults'],
    'sms' => ['fn' => 'smsSettingsDefaults'],
    'ticketing' => ['fn' => 'tkSettingsDefaults'],
    'gui-settings' => ['fn' => 'guiSettingsDefaults'],
    'contact-form' => ['fn' => 'contactFormSettingsDefaults'],
    'cms' => ['fn' => 'cmsSettingsDefaults'],
    'guidance' => ['fn' => 'guidanceSettingsDefaults'],
];

foreach ($contracts as $moduleId => $meta) {
    $expected = manifestDefaults($moduleId);
    $actual = $meta['fn']();

    t("{$moduleId} manifest has default-bearing settings_fields", $expected !== [], 'no manifest defaults found');
    t("{$moduleId} default keys match manifest", array_keys($actual) === array_keys($expected));
    t("{$moduleId} default values match manifest", $actual == $expected);
}

echo "\n=== MERGED OVERRIDES ===\n";

$contactOriginal = readTenantModuleSettings('contact-form');
$contactHadOriginal = array_key_exists('success_message', $contactOriginal);
$contactOriginalValue = $contactOriginal['success_message'] ?? null;
saveTenantModuleSettings('contact-form', ['success_message' => 'Session test override']);
$contactSettings = contactFormGetSettings();
t('contact-form override beats manifest default', ($contactSettings['success_message'] ?? '') === 'Session test override');
restoreTenantModuleSetting('contact-form', 'success_message', $contactHadOriginal, $contactOriginalValue);

$guidanceSettings = guidanceGetAllSettings();
t('guidance settings reader exposes manifest defaults without gm_settings dependency', ($guidanceSettings['app_timezone'] ?? '') === (guidanceSettingsDefaults()['app_timezone'] ?? ''));

$appLog = @file_get_contents(STORAGE_PATH . '/logs/app.log') ?: '';
$errorLog = @file_get_contents(STORAGE_PATH . '/logs/error.log') ?: '';
t('no app.log critical errors', !str_contains($appLog, '[critical]'));
t('no PHP errors in error.log', trim($errorLog) === '', trim($errorLog));

echo "\n══════════════════════════════════════════════════\n";
echo "  PASS: {$pass}  FAIL: {$fail}\n";
echo "══════════════════════════════════════════════════\n";

if ($errors !== []) {
    echo "\nFailed tests:\n";
    foreach ($errors as $error) {
        echo "  - {$error}\n";
    }
}

exit($fail > 0 ? 1 : 0);