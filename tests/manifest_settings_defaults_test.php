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

function settingsFixtureFindings(array $fields): array
{
    $findings = [];
    $seenKeys = [];

    foreach ($fields as $index => $field) {
        if (!is_array($field)) {
            continue;
        }

        $key = trim((string)($field['key'] ?? ''));
        if ($key === '') {
            $findings[] = "empty_key:{$index}";
        } elseif (isset($seenKeys[$key])) {
            $findings[] = "duplicate_key:{$key}";
        } else {
            $seenKeys[$key] = true;
        }

        $type = trim((string)($field['type'] ?? ''));
        if ($type !== 'select' || !array_key_exists('default', $field) || !array_key_exists('options', $field) || !is_array($field['options'])) {
            continue;
        }

        // Option shapes observed in the fleet (2026-10-12): list-of-strings,
        // list-of-{value,label}, and map {value: label}. Accept map KEYS and string
        // values so a map-shaped declaration (sms_provider) whose default equals its key
        // is not a false positive; the shape vocabulary itself is a Gate 2 item.
        $allowedValues = [];
        foreach ($field['options'] as $optionKey => $option) {
            if (is_string($optionKey) && $optionKey !== '') {
                $allowedValues[$optionKey] = true;
            }
            if (is_string($option)) {
                $allowedValues[$option] = true;
            } elseif (is_array($option) && array_key_exists('value', $option)) {
                $allowedValues[(string)$option['value']] = true;
            }
        }

        if (!isset($allowedValues[(string)$field['default']])) {
            $findings[] = "select_default_not_in_options:{$key}";
        }
    }

    return $findings;
}

function removeSettingsContractProbes(string $moduleId, array $keys): array
{
    $errors = [];
    foreach ($keys as $key) {
        try {
            // Reserved characterization keys never retain an original: exercise the DELETE branch.
            restoreTenantModuleSetting($moduleId, $key, false, null);
        } catch (Throwable $e) {
            $errors[] = "{$key}: {$e->getMessage()}";
        }
    }

    return $errors;
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

echo "\n=== SETTINGS CONTRACT CHARACTERIZATION ===\n";

$supportedSettingsTypes = ['text', 'textarea', 'email', 'time', 'number', 'select', 'checkbox', 'bool', 'password'];
$emptyKeyFindings = [];
$duplicateKeyFindings = [];
$selectDefaultFindings = [];
$typeFindings = [];
$missingDefaultCount = 0;
$selectMissingOptionsCount = 0;

foreach (discoverModules() as $moduleId => $manifest) {
    $fields = is_array($manifest['settings_fields'] ?? null) ? $manifest['settings_fields'] : [];
    if ($fields === []) {
        continue;
    }

    // The SAME detector the fixture self-tests exercise: fleet checks and crafted
    // fixtures must never diverge (Sol review 2026-10-12).
    foreach (settingsFixtureFindings($fields) as $finding) {
        if (str_starts_with($finding, 'empty_key:')) {
            $emptyKeyFindings[] = "{$moduleId}:{$finding}";
        } elseif (str_starts_with($finding, 'duplicate_key:')) {
            $duplicateKeyFindings[] = "{$moduleId}:{$finding}";
        } elseif (str_starts_with($finding, 'select_default_not_in_options:')) {
            $selectDefaultFindings[] = "{$moduleId}:{$finding}";
        }
    }

    foreach ($fields as $index => $field) {
        if (!is_array($field)) {
            $typeFindings[] = "{$moduleId}[{$index}]: non-array field";
            continue;
        }

        $key = trim((string)($field['key'] ?? ''));
        $type = trim((string)($field['type'] ?? ''));
        if (!in_array($type, $supportedSettingsTypes, true)) {
            $typeFindings[] = "{$moduleId}:{$key}=" . ($type === '' ? '(missing)' : $type);
        }
        if (!array_key_exists('default', $field)) {
            $missingDefaultCount++;
        }
        if ($type === 'select' && !array_key_exists('options', $field)) {
            $selectMissingOptionsCount++;
        }
    }
}

t('settings-bearing manifest keys are non-empty', $emptyKeyFindings === [], implode(', ', $emptyKeyFindings));
t('settings-bearing manifest keys are unique within each module', $duplicateKeyFindings === [], implode(', ', $duplicateKeyFindings));
t('select defaults fall inside their declared options where both exist', $selectDefaultFindings === [], implode(', ', $selectDefaultFindings));
t('all settings fields declare a supported type', $typeFindings === [], implode(', ', $typeFindings));
echo "  INFO: settings fields lacking a default: {$missingDefaultCount} (current fleet baseline: 21)\n";
echo "  INFO: select fields lacking options: {$selectMissingOptionsCount} (current fleet baseline: 14)\n";

$cleanFixture = [
    ['key' => 'title', 'type' => 'text', 'default' => 'Hello'],
    ['key' => 'mode', 'type' => 'select', 'default' => 'safe', 'options' => [
        ['value' => 'safe', 'label' => 'Safe'],
        'fast',
    ]],
];
$duplicateFixture = [
    ['key' => 'same', 'type' => 'text'],
    ['key' => 'same', 'type' => 'number'],
];
$emptyKeyFixture = [['key' => '   ', 'type' => 'text']];
$badSelectDefaultFixture = [[
    'key' => 'mode',
    'type' => 'select',
    'default' => 'missing',
    'options' => [['value' => 'safe', 'label' => 'Safe']],
]];

t('checker MUST-ALLOW clean fixture', settingsFixtureFindings($cleanFixture) === []);
t('checker MUST-REFUSE duplicate-key fixture', in_array('duplicate_key:same', settingsFixtureFindings($duplicateFixture), true));
t('checker MUST-REFUSE empty-key fixture', in_array('empty_key:0', settingsFixtureFindings($emptyKeyFixture), true));
t('checker MUST-REFUSE select-bad-default fixture', in_array('select_default_not_in_options:mode', settingsFixtureFindings($badSelectDefaultFixture), true));

$probeModuleId = '__g0_settings_contract';
$probeValues = [
    '__g0_false' => false,
    '__g0_zero' => 0,
    '__g0_empty_string' => '',
    '__g0_numeric_string' => '42',
    '__g0_null' => null,
];
$probeKeys = array_keys($probeValues);
// State preservation (Sol review 2026-10-12): record any pre-existing reserved keys BEFORE
// touching them; they are restored exactly in the finally block instead of being erased.
$existingProbeState = readTenantModuleSettings($probeModuleId);
$priorProbeState = [];
foreach ($probeKeys as $probeKey) {
    if (array_key_exists($probeKey, $existingProbeState)) {
        $priorProbeState[$probeKey] = $existingProbeState[$probeKey];
    }
}
t('reserved probe keys are absent before seeding', $priorProbeState === [], 'pre-existing reserved keys found: ' . implode(', ', array_keys($priorProbeState)));
$preCleanupErrors = removeSettingsContractProbes($probeModuleId, $probeKeys);
t('reserved probe cleanup is executable', $preCleanupErrors === [], implode('; ', $preCleanupErrors));

$cleanupErrors = [];
try {
    t('stored-value probes save successfully', saveTenantModuleSettings($probeModuleId, $probeValues));
    $storedProbeValues = readTenantModuleSettings($probeModuleId);
    t('stored JSON false remains boolean false and differs strictly from empty string and zero',
        array_key_exists('__g0_false', $storedProbeValues)
        && $storedProbeValues['__g0_false'] === false
        && $storedProbeValues['__g0_false'] !== ''
        && $storedProbeValues['__g0_false'] !== 0);
    t('stored JSON zero remains integer zero', array_key_exists('__g0_zero', $storedProbeValues) && $storedProbeValues['__g0_zero'] === 0);
    t('stored empty string remains an empty string', array_key_exists('__g0_empty_string', $storedProbeValues) && $storedProbeValues['__g0_empty_string'] === '');
    t('stored numeric string remains the string 42', array_key_exists('__g0_numeric_string', $storedProbeValues) && $storedProbeValues['__g0_numeric_string'] === '42');
    t('stored JSON null remains present with a null value', array_key_exists('__g0_null', $storedProbeValues) && $storedProbeValues['__g0_null'] === null);
} finally {
    $cleanupErrors = removeSettingsContractProbes($probeModuleId, $probeKeys);
    // State preservation: restore any pre-existing reserved keys exactly (normally none).
    foreach ($priorProbeState as $probeKey => $originalProbeValue) {
        restoreTenantModuleSetting($probeModuleId, $probeKey, true, $originalProbeValue);
    }
}

t('stored-value probe cleanup completed', $cleanupErrors === [], implode('; ', $cleanupErrors));
$afterProbeCleanup = readTenantModuleSettings($probeModuleId);
$probeKeysAreAbsent = true;
foreach ($probeKeys as $probeKey) {
    if (array_key_exists($probeKey, $afterProbeCleanup)) {
        $probeKeysAreAbsent = false;
        break;
    }
}
t('stored-value probe keys are absent after removal', $probeKeysAreAbsent);
echo "  INFO: absent (no row) and false (row containing JSON false) are different stored states.\n";

// The skip-empty merge rule (inherit semantics) must not let a blank override mask the
// effective value. Helper-level guard; falsified by removing the skip-empty rule.
$aiDefaults = aiSettingsDefaults();
$aiProbeKey = null;
foreach ($aiDefaults as $aiKey => $aiValue) {
    if (is_string($aiValue) && trim($aiValue) !== '' && !str_contains((string) $aiKey, 'key')) {
        $aiProbeKey = (string) $aiKey;
        break;
    }
}
if ($aiProbeKey !== null) {
    $aiOriginalSettings = readTenantModuleSettings('ai');
    $aiHadOriginal = array_key_exists($aiProbeKey, $aiOriginalSettings);
    $aiOriginalValue = $aiOriginalSettings[$aiProbeKey] ?? null;
    // Clean slate for the probe key so the assertion measures the skip-empty rule itself,
    // not the expected effect of clearing a real override. Measured 2026-10-12: the tenant
    // fixture holds ai.provider='groq'; writing '' over it falls back to the manifest
    // default — inherit semantics doing its job, recorded in the gate report.
    restoreTenantModuleSetting('ai', $aiProbeKey, false, null);
    $aiBaseline = aiResolvedSettings()[$aiProbeKey] ?? null;
    saveTenantModuleSettings('ai', [$aiProbeKey => '']);
    $aiAfterBlank = aiResolvedSettings()[$aiProbeKey] ?? null;
    t(
        "AI skip-empty: a blank override resolves to the fallback, not the blank ({$aiProbeKey})",
        $aiAfterBlank !== '' && $aiAfterBlank === $aiBaseline && $aiBaseline !== null,
        'baseline=' . json_encode($aiBaseline) . ' after=' . json_encode($aiAfterBlank)
    );
    restoreTenantModuleSetting('ai', $aiProbeKey, $aiHadOriginal, $aiOriginalValue);
} else {
    t('AI skip-empty guard: a non-empty non-secret default exists to probe', false, 'no candidate found');
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