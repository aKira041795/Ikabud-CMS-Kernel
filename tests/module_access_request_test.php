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
        return;
    }

    $fail++;
    $errors[] = $label . ($detail !== '' ? ': ' . $detail : '');
    echo "  ✗ {$label}" . ($detail !== '' ? ' — ' . $detail : '') . "\n";
}

function cleanupAccessRequestFixture(PDO $controlDb, string $moduleId): void
{
    $stmt = $controlDb->prepare('DELETE FROM ' . moduleAccessRequestsTable() . ' WHERE module_id = :module_id');
    $stmt->execute([':module_id' => $moduleId]);

    $stmt = $controlDb->prepare('DELETE FROM ' . moduleTenantEntitlementsTable() . ' WHERE module_id = :module_id');
    $stmt->execute([':module_id' => $moduleId]);

    $stmt = $controlDb->prepare('DELETE FROM ' . moduleCatalogTable() . ' WHERE module_id = :module_id');
    $stmt->execute([':module_id' => $moduleId]);

    invalidateModuleCatalogCache();
}

file_put_contents(STORAGE_PATH . '/logs/app.log', '');
file_put_contents(STORAGE_PATH . '/logs/error.log', '');

echo "\n=== MODULE ACCESS REQUESTS ===\n";

$controlDb = app()->controlDb();
$runner = new MigrationRunner($controlDb);
$runner->migrate('_control');

$tenantId = (int)($controlDb->query("SELECT id FROM kernel_tenants WHERE status = 'active' ORDER BY id ASC LIMIT 1")->fetchColumn() ?: 0);
t('active tenant exists for access request tests', $tenantId > 0);

$moduleId = 'catalog-access-request-test';
cleanupAccessRequestFixture($controlDb, $moduleId);

$catalogOk = upsertModuleCatalogEntry($moduleId, [
    'module_name' => 'Catalog Access Request Test',
    'approved_version' => '1.0.0',
    'install_path' => modulesPath() . '/' . $moduleId,
    'source' => 'test',
    'approval_status' => 'approved',
    'commercial_mode' => 'paid',
]);
t('approved paid catalog entry can be created', $catalogOk);

$requestResult = submitModuleAccessRequestForTenant($moduleId, $tenantId, [
    'requested_mode' => 'paid',
    'request_notes' => 'Need pro activation for this tenant.',
    'license_key' => 'PRO-1234-ABCD-9999',
    'requested_by_user_id' => 99,
    'metadata' => ['via' => 'module_access_request_test'],
]);
t('tenant access request can be submitted', !empty($requestResult['ok']), (string)($requestResult['error'] ?? ''));

$request = moduleLatestAccessRequestForTenant($moduleId, $tenantId);
t('access request is stored', is_array($request));
t('access request starts pending', is_array($request) && (($request['status'] ?? '') === 'pending'));
t('access request stores masked license ref', is_array($request) && (($request['license_ref'] ?? '') === 'PRO-...9999'), is_array($request) ? (string)($request['license_ref'] ?? '') : '');
t('access request can decrypt stored license key', is_array($request) && moduleAccessRequestLicenseKey($request) === 'PRO-1234-ABCD-9999');

$reviewResult = reviewModuleAccessRequest((int)($request['id'] ?? 0), 'approved', [
    'reviewed_by_user_id' => 1,
    'review_notes' => 'Approved in test.',
]);
t('superadmin review can approve request', !empty($reviewResult['ok']), (string)($reviewResult['error'] ?? ''));

$approvedRequest = moduleLatestAccessRequestForTenant($moduleId, $tenantId);
$entitlement = moduleTenantEntitlementStatus($moduleId, $tenantId);
$activation = $reviewResult['activation'] ?? [];

t('approved request status is persisted', is_array($approvedRequest) && (($approvedRequest['status'] ?? '') === 'approved'));
t('approved request records reviewer', is_array($approvedRequest) && (int)($approvedRequest['reviewed_by_user_id'] ?? 0) === 1);
t('approved request grants tenant entitlement', !empty($entitlement['allowed']) && (($entitlement['entitlement_status'] ?? '') === 'active'));
t('approved request keeps paid tier', (($entitlement['tier'] ?? '') === 'paid'));
t('license activation hook is best-effort when no provider is registered', (($activation['status'] ?? '') === 'skipped'), is_array($activation) ? json_encode($activation) : '');

cleanupAccessRequestFixture($controlDb, $moduleId);

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