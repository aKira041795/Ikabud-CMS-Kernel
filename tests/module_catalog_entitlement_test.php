<?php

declare(strict_types=1);

chdir(__DIR__ . '/..');

require_once 'bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';

use Ikabud\Kernel\Database\MigrationRunner;

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

function cleanupCatalogFixture(PDO $db, string $moduleId): void
{
    $stmt = $db->prepare('DELETE FROM ' . moduleTenantEntitlementsTable() . ' WHERE module_id = :module_id');
    $stmt->execute([':module_id' => $moduleId]);

    $stmt = $db->prepare('DELETE FROM ' . moduleCatalogTable() . ' WHERE module_id = :module_id');
    $stmt->execute([':module_id' => $moduleId]);

    invalidateModuleCatalogCache();
}

file_put_contents(STORAGE_PATH . '/logs/app.log', '');
file_put_contents(STORAGE_PATH . '/logs/error.log', '');

echo "\n=== MODULE CATALOG ENTITLEMENTS ===\n";

$controlDb = app()->controlDb();
$runner = new MigrationRunner($controlDb);
$runner->migrate('_control');

$tenantId = (int)($controlDb->query("SELECT id FROM kernel_tenants WHERE status = 'active' ORDER BY id ASC LIMIT 1")->fetchColumn() ?: 0);
t('active tenant exists for entitlement tests', $tenantId > 0);

$freeModuleId = 'catalog-entitlement-test';
$freemiumModuleId = 'catalog-entitlement-freemium-test';
$paidModuleId = 'catalog-entitlement-paid-test';
$pendingModuleId = 'catalog-entitlement-pending-test';

cleanupCatalogFixture($controlDb, $freeModuleId);
cleanupCatalogFixture($controlDb, $freemiumModuleId);
cleanupCatalogFixture($controlDb, $paidModuleId);
cleanupCatalogFixture($controlDb, $pendingModuleId);

$freeCatalogOk = upsertModuleCatalogEntry($freeModuleId, [
    'module_name' => 'Catalog Entitlement Test',
    'approved_version' => '1.0.0',
    'install_path' => modulesPath() . '/' . $freeModuleId,
    'source' => 'test',
    'approval_status' => 'approved',
    'commercial_mode' => 'free',
]);
t('approved free catalog entry can be created', $freeCatalogOk);
t('free catalog entry is marked approved', moduleCatalogIsApproved($freeModuleId));

$missingStatus = moduleTenantEntitlementStatus($freeModuleId, $tenantId);
t('approved catalog module requires entitlement', !empty($missingStatus['required']));
t('approved catalog module starts without tenant access', empty($missingStatus['allowed']) && ($missingStatus['entitlement_status'] ?? '') === 'missing');

$autoGrantOk = ensureSelfServiceModuleEntitlementForTenant($freeModuleId, $tenantId, ['source' => 'test']);
t('free catalog module can self-grant missing entitlement', $autoGrantOk);

$activeStatus = moduleTenantEntitlementStatus($freeModuleId, $tenantId);
t('self-granted entitlement becomes active', !empty($activeStatus['allowed']) && ($activeStatus['entitlement_status'] ?? '') === 'active');

$revokeOk = revokeModuleEntitlementForTenant($freeModuleId, $tenantId, ['source' => 'test']);
t('revoking entitlement succeeds', $revokeOk);

$revokedStatus = moduleTenantEntitlementStatus($freeModuleId, $tenantId);
t('revoked entitlement blocks access', empty($revokedStatus['allowed']) && ($revokedStatus['entitlement_status'] ?? '') === 'revoked');

$noOverrideOk = ensureSelfServiceModuleEntitlementForTenant($freeModuleId, $tenantId, ['source' => 'test']);
t('self-service does not override revoked access', !$noOverrideOk);

$freemiumCatalogOk = upsertModuleCatalogEntry($freemiumModuleId, [
    'module_name' => 'Catalog Entitlement Freemium Test',
    'approved_version' => '1.0.0',
    'install_path' => modulesPath() . '/' . $freemiumModuleId,
    'source' => 'test',
    'approval_status' => 'approved',
    'commercial_mode' => 'freemium',
]);
t('approved freemium catalog entry can be created', $freemiumCatalogOk);

$freemiumAutoGrantOk = ensureSelfServiceModuleEntitlementForTenant($freemiumModuleId, $tenantId, ['source' => 'test']);
t('freemium catalog module self-grants base access', $freemiumAutoGrantOk);

$freemiumStatus = moduleTenantEntitlementStatus($freemiumModuleId, $tenantId);
t('freemium entitlement allows access', !empty($freemiumStatus['allowed']) && ($freemiumStatus['tier'] ?? '') === 'freemium');

$paidCatalogOk = upsertModuleCatalogEntry($paidModuleId, [
    'module_name' => 'Catalog Entitlement Paid Test',
    'approved_version' => '1.0.0',
    'install_path' => modulesPath() . '/' . $paidModuleId,
    'source' => 'test',
    'approval_status' => 'approved',
    'commercial_mode' => 'paid',
]);
t('approved paid catalog entry can be created', $paidCatalogOk);

$paidAutoGrantOk = ensureSelfServiceModuleEntitlementForTenant($paidModuleId, $tenantId, ['source' => 'test']);
t('paid catalog module does not self-grant access', !$paidAutoGrantOk);

$paidGrantOk = grantModuleEntitlementForTenant($paidModuleId, $tenantId, [
    'status' => 'trial',
    'tier' => 'paid',
    'source' => 'test',
]);
t('explicit paid entitlement can be granted', $paidGrantOk);

$paidStatus = moduleTenantEntitlementStatus($paidModuleId, $tenantId);
t('trial entitlement allows access', !empty($paidStatus['allowed']) && ($paidStatus['entitlement_status'] ?? '') === 'trial');

$pendingCatalogOk = upsertModuleCatalogEntry($pendingModuleId, [
    'module_name' => 'Catalog Entitlement Pending Test',
    'approved_version' => '1.0.0',
    'install_path' => modulesPath() . '/' . $pendingModuleId,
    'source' => 'cms_upload',
    'approval_status' => 'pending',
    'commercial_mode' => 'freemium',
    'origin_tenant_id' => $tenantId,
]);
t('pending catalog entry can be created', $pendingCatalogOk);
t('pending catalog entry is not approved yet', !moduleCatalogIsApproved($pendingModuleId));

$approvePendingOk = updateModuleCatalogApproval($pendingModuleId, 'approved', [
    'commercial_mode' => 'freemium',
    'approved_by_user_id' => 1,
]);
t('pending catalog entry can be approved', $approvePendingOk);
t('approved pending catalog entry becomes approved', moduleCatalogIsApproved($pendingModuleId));

$pendingApprovedStatus = moduleTenantEntitlementStatus($pendingModuleId, $tenantId);
t('approval grants origin tenant access automatically', !empty($pendingApprovedStatus['allowed']) && ($pendingApprovedStatus['tier'] ?? '') === 'freemium');

cleanupCatalogFixture($controlDb, $freeModuleId);
cleanupCatalogFixture($controlDb, $freemiumModuleId);
cleanupCatalogFixture($controlDb, $paidModuleId);
cleanupCatalogFixture($controlDb, $pendingModuleId);

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