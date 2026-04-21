<?php

declare(strict_types=1);

chdir(__DIR__ . '/..');

require_once 'bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../modules/cms/helpers.php';
require_once __DIR__ . '/../modules/cms/handlers.php';

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

function rrmdir_if_exists(string $path): void
{
    if (!is_dir($path)) {
        return;
    }

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        if ($item->isDir()) {
            @rmdir($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }
    @rmdir($path);
}

function cleanupCmsCatalogFixture(PDO $controlDb, string $moduleId, int $tenantId, array $originalCmsSettings): void
{
    $stmt = $controlDb->prepare('DELETE FROM ' . moduleTenantEntitlementsTable() . ' WHERE module_id = :module_id');
    $stmt->execute([':module_id' => $moduleId]);

    $stmt = $controlDb->prepare('DELETE FROM ' . moduleCatalogTable() . ' WHERE module_id = :module_id');
    $stmt->execute([':module_id' => $moduleId]);

    saveTenantModuleSettingsForTenant('cms', $tenantId, [
        '_installed_submodules' => $originalCmsSettings['_installed_submodules'] ?? [],
    ]);
    saveTenantModuleSettingsForTenant($moduleId, $tenantId, ['_module_enabled' => false]);
    invalidateModuleCatalogCache();
    unset($GLOBALS['_kernel_discovered_modules']);
}

file_put_contents(STORAGE_PATH . '/logs/app.log', '');
file_put_contents(STORAGE_PATH . '/logs/error.log', '');

echo "\n=== CMS MODULE CATALOG INSTALL ===\n";

$controlDb = app()->controlDb();
$tenantId = (int)($controlDb->query("SELECT id FROM kernel_tenants WHERE status = 'active' ORDER BY id ASC LIMIT 1")->fetchColumn() ?: 0);
t('active tenant exists for CMS catalog test', $tenantId > 0);

$moduleId = 'cms-catalog-install-test';
$moduleDir = modulesPath() . '/' . $moduleId;
$bundledModuleId = 'cms-bundled-install-test';
$bundledModuleDir = modulesPath() . '/' . $bundledModuleId;
$originalCmsSettings = readTenantModuleSettingsForTenant('cms', $tenantId);

rrmdir_if_exists($moduleDir);
rrmdir_if_exists($bundledModuleDir);
cleanupCmsCatalogFixture($controlDb, $moduleId, $tenantId, $originalCmsSettings);
cleanupCmsCatalogFixture($controlDb, $bundledModuleId, $tenantId, $originalCmsSettings);

@mkdir($moduleDir, 0775, true);
file_put_contents($moduleDir . '/module.json', json_encode([
    'id' => $moduleId,
    'name' => 'CMS Catalog Install Test',
    'version' => '1.0.0',
    'description' => 'Catalog install fixture',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
file_put_contents($moduleDir . '/routes.php', "<?php\n\nreturn ['GET' => []];\n");
file_put_contents($moduleDir . '/handlers.php', "<?php\n");

unset($GLOBALS['_kernel_discovered_modules']);

$catalogOk = upsertModuleCatalogEntry($moduleId, [
    'module_name' => 'CMS Catalog Install Test',
    'approved_version' => '1.0.0',
    'install_path' => $moduleDir,
    'source' => 'cms_upload',
    'approval_status' => 'approved',
    'commercial_mode' => 'freemium',
]);
t('catalog entry for CMS install can be created', $catalogOk);

$catalogList = _cmsDiscoverCatalogModules($tenantId);
$catalogIds = array_column($catalogList, 'id');
t('approved catalog module appears in CMS catalog list', in_array($moduleId, $catalogIds, true));

$installResult = _cmsInstallCatalogModule($moduleId, $tenantId);
t('CMS catalog install succeeds', !empty($installResult['ok']), (string)($installResult['error'] ?? ''));

$registered = _cmsGetRegisteredSubModulesForTenant($tenantId);
t('CMS catalog install registers module for tenant', in_array($moduleId, $registered, true));
t('CMS catalog install enables module for tenant', isModuleEnabledForTenant($moduleId, $tenantId));

$catalogListAfter = _cmsDiscoverCatalogModules($tenantId);
$catalogIdsAfter = array_column($catalogListAfter, 'id');
t('installed catalog module disappears from available list', !in_array($moduleId, $catalogIdsAfter, true));

@mkdir($bundledModuleDir, 0775, true);
file_put_contents($bundledModuleDir . '/module.json', json_encode([
    'id' => $bundledModuleId,
    'name' => 'CMS Bundled Install Test',
    'version' => '1.0.0',
    'description' => 'Bundled host module fixture',
    'hooks' => ['cms.public.render_content'],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
file_put_contents($bundledModuleDir . '/routes.php', "<?php\n\nreturn ['GET' => []];\n");
file_put_contents($bundledModuleDir . '/handlers.php', "<?php\n");

unset($GLOBALS['_kernel_discovered_modules']);

$availableModules = _cmsDiscoverCatalogModules($tenantId);
$bundledModule = null;
foreach ($availableModules as $availableModule) {
    if (($availableModule['id'] ?? '') === $bundledModuleId) {
        $bundledModule = $availableModule;
        break;
    }
}

t('bundled host module appears in CMS available list', is_array($bundledModule));
t('bundled host module is classified as bundled', is_array($bundledModule) && ($bundledModule['availability_source'] ?? '') === 'bundled');
t('bundled host module can install without catalog approval', is_array($bundledModule) && !empty($bundledModule['can_install']));

$bundledInstallResult = _cmsInstallCatalogModule($bundledModuleId, $tenantId);
t('bundled host module install succeeds without catalog approval', !empty($bundledInstallResult['ok']), (string)($bundledInstallResult['error'] ?? ''));

$registered = _cmsGetRegisteredSubModulesForTenant($tenantId);
t('bundled host module registers for tenant', in_array($bundledModuleId, $registered, true));
t('bundled host module enables module for tenant', isModuleEnabledForTenant($bundledModuleId, $tenantId));

$availableModulesAfter = _cmsDiscoverCatalogModules($tenantId);
$availableIdsAfter = array_column($availableModulesAfter, 'id');
t('installed bundled host module disappears from available list', !in_array($bundledModuleId, $availableIdsAfter, true));

cleanupCmsCatalogFixture($controlDb, $moduleId, $tenantId, $originalCmsSettings);
cleanupCmsCatalogFixture($controlDb, $bundledModuleId, $tenantId, $originalCmsSettings);
rrmdir_if_exists($moduleDir);
rrmdir_if_exists($bundledModuleDir);

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