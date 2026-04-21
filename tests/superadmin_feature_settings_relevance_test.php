<?php

declare(strict_types=1);

chdir(__DIR__ . '/..');

require_once 'bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../src/http/superadmin-handlers.php';

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
        $errors[] = $label . ($detail !== '' ? ': ' . $detail : '');
        echo "  ✗ {$label}" . ($detail !== '' ? ' — ' . $detail : '') . "\n";
    }
}

echo "\n=== SUPERADMIN FEATURE SETTINGS RELEVANCE ===\n";

$controlDb = app()->controlDb();
$tenantId = (int)($controlDb->query("SELECT id FROM kernel_tenants WHERE status = 'active' AND entry_module_id = 'cms' ORDER BY id ASC LIMIT 1")->fetchColumn() ?: 0);
t('active CMS tenant exists for superadmin relevance test', $tenantId > 0);

$allModules = discoverModules();
$relevant = superadminTenantRelevantModuleMap($allModules, 'cms', $tenantId);

t('CMS tenant relevance includes AI provider', isset($relevant['ai']));
t('CMS tenant relevance includes TinyMCE provider', isset($relevant['tinymce']));
t('CMS tenant relevance includes contact-form hook add-on', isset($relevant['contact-form']));
t('CMS tenant relevance includes WordPress importer CMS data add-on', isset($relevant['wordpress-importer']));
t('CMS tenant relevance excludes daily-ledger standalone product', !isset($relevant['daily-ledger']));
t('CMS tenant relevance excludes SMS guidance helper', !isset($relevant['sms']));
t('CMS tenant relevance excludes GUI settings kernel utility', !isset($relevant['gui-settings']));

t('contact-form is in the CMS tenant runtime default set', moduleRegistryDefaultEnabledState('contact-form', $tenantId));
t('daily-ledger is outside the CMS tenant runtime default set', !moduleRegistryDefaultEnabledState('daily-ledger', $tenantId));

$enablement = superadminModuleEnablementState('__superadmin_runtime_default_fixture__', $tenantId);
t('unknown module defaults to runtime-default enablement source', ($enablement['source'] ?? '') === 'runtime_default', json_encode($enablement));
t('unknown module is runtime-disabled by default', empty($enablement['runtime_enabled']), json_encode($enablement));

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