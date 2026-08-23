<?php

declare(strict_types=1);

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'cmsnew.test';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/daily-ledger/auth/login';

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';

use Ikabud\Kernel\Capabilities\CapabilityAuthorizationRegistry;
use Ikabud\Kernel\Database\KernelPDO;

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
    echo "  ✗ {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

function clearLogs(): void
{
    @file_put_contents(STORAGE_PATH . '/logs/app.log', '');
    @file_put_contents(STORAGE_PATH . '/logs/error.log', '');
}

function setModuleRequestContext(string $moduleId): void
{
    $ctx = module($moduleId);
    if (!$ctx) {
        throw new RuntimeException("Module context not found for {$moduleId}");
    }

    kernel_request_context_set('_activeModuleContext', $ctx);
    app()->setActiveModule($moduleId);
    KernelPDO::setActiveModule($moduleId);
}

function clearModuleRequestContext(): void
{
    kernel_request_context_set('_activeModuleContext', null);
    app()->clearActiveModule();
    KernelPDO::setActiveModule(null);
}

function seedRegistryVersionCache(string $cacheKey, ?int $value): void
{
    $ref = new ReflectionClass(CapabilityAuthorizationRegistry::class);
    $prop = $ref->getProperty('activeVersionCache');
    $cache = $prop->getValue();
    $cache[$cacheKey] = $value;
    $prop->setValue(null, $cache);
}

echo "=== Capability Authz Module Context Regression Test ===\n\n";

$db = app()->db();
$db->exec((string)file_get_contents(__DIR__ . '/../database/migrations/025_kernel_capability_authorization_policies.sql'));

$maxPolicyVersion = $db->query('SELECT MAX(policy_version) FROM capability_authorization_policies WHERE is_active = 1');
$currentMax = $maxPolicyVersion === false ? 0 : (int)($maxPolicyVersion->fetchColumn() ?: 0);
$policyVersion = max(910250, $currentMax + 1);
$db->prepare('DELETE FROM capability_authorization_policies WHERE policy_version = ?')->execute([$policyVersion]);
$db->prepare(
    'INSERT INTO capability_authorization_policies '
    . '(policy_version, capability_id, capability_version, provider, caller_module, allowed_roles, provider_activation_required, requires_protocol, is_active, updated_at) '
    . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
)->execute([
    $policyVersion,
    'proof_lane.ping',
    '1',
    'proof-lane',
    'daily-ledger',
    'admin',
    1,
    'v1',
    1,
]);
CapabilityAuthorizationRegistry::invalidate();

$registry = new CapabilityAuthorizationRegistry();
$ctx = [
    'capability_id' => 'proof_lane.ping',
    'capability_version' => '1',
    'provider' => 'proof-lane',
    'caller_module' => 'daily-ledger',
    'actor_role' => 'admin',
    'tenant_id' => 'tenant-proof',
    'provider_activation' => true,
    'explicit_provider' => 'proof-lane',
];

clearLogs();
clearModuleRequestContext();
try {
    setModuleRequestContext('daily-ledger');
    $resultWithModuleContext = $registry->authorize($ctx);
} finally {
    clearModuleRequestContext();
}

CapabilityAuthorizationRegistry::invalidate();
$resultWithoutModuleContext = $registry->authorize($ctx);

$appLog = (string)@file_get_contents(STORAGE_PATH . '/logs/app.log');
$errorLog = (string)@file_get_contents(STORAGE_PATH . '/logs/error.log');

$deniedPolicyLog = preg_match('/ModuleDB DENIED:.*capability_authorization_policies/s', $appLog) === 1;
$versionLookupFailureLog = str_contains($appLog, 'capability authorization registry version lookup failed');

$db->prepare('DELETE FROM capability_authorization_policies WHERE policy_version = ?')->execute([$policyVersion]);
CapabilityAuthorizationRegistry::invalidate();
clearModuleRequestContext();

t('authorize() allows governed policy lookup inside daily-ledger module context', ($resultWithModuleContext['allowed'] ?? false) === true, json_encode($resultWithModuleContext));
t('authorize() still allows the same policy without module context', ($resultWithoutModuleContext['allowed'] ?? false) === true, json_encode($resultWithoutModuleContext));
t('app.log contains no ModuleDB DENIED for capability_authorization_policies', !$deniedPolicyLog, $appLog);
t('app.log contains no registry version lookup failure', !$versionLookupFailureLog, $appLog);
t('error.log has no new entries', trim($errorLog) === '', $errorLog);

clearLogs();
CapabilityAuthorizationRegistry::invalidate();
$missingTableRegistry = new CapabilityAuthorizationRegistry($db);
$missingTableName = 'capability_authorization_policies_absent_25b';
$missingTableRestored = false;
$missingTableHasPolicy = null;
$missingTableProtocol = '__unset__';
$missingTableAuthorize = ['allowed' => null, 'reason' => 'not_run'];
$missingTableAuthorizeCtx = $ctx;
$missingTableAuthorizeCtx['capability_version'] = '2';
$missingTableAuthorizeCtx['policy_version'] = $policyVersion;

try {
    $db->exec("DROP TABLE IF EXISTS {$missingTableName}");
    $db->exec("RENAME TABLE capability_authorization_policies TO {$missingTableName}");

    $missingTableHasPolicy = $missingTableRegistry->hasPolicyFor('proof_lane.ping', '1', 'proof-lane');
    $missingTableProtocol = $missingTableRegistry->requiresProtocol('proof_lane.ping', '1', 'proof-lane');
    seedRegistryVersionCache('override:' . $policyVersion, $policyVersion);
    $missingTableAuthorize = $missingTableRegistry->authorize($missingTableAuthorizeCtx);
} finally {
    try {
        $db->exec("RENAME TABLE {$missingTableName} TO capability_authorization_policies");
        $missingTableRestored = true;
    } catch (Throwable $restoreError) {
    }
    CapabilityAuthorizationRegistry::invalidate();
}

$missingTableAppLog = (string)@file_get_contents(STORAGE_PATH . '/logs/app.log');
$missingTableErrorLog = (string)@file_get_contents(STORAGE_PATH . '/logs/error.log');
$missingTableVersionLookupFailureLog = str_contains($missingTableAppLog, 'capability authorization registry version lookup failed');

t('missing-table case restores capability_authorization_policies after probe', $missingTableRestored === true);
t('missing-table case makes hasPolicyFor() return false', $missingTableHasPolicy === false, var_export($missingTableHasPolicy, true));
t('missing-table case makes requiresProtocol() return null', $missingTableProtocol === null, var_export($missingTableProtocol, true));
t('missing-table case default-denies governed authorize() without exception', ($missingTableAuthorize['allowed'] ?? null) === false, json_encode($missingTableAuthorize));
t('missing-table case reports missing_policy_row', ($missingTableAuthorize['reason'] ?? null) === 'missing_policy_row', json_encode($missingTableAuthorize));
t('missing-table case logs no registry version lookup failure', !$missingTableVersionLookupFailureLog, $missingTableAppLog);
t('missing-table case writes no error.log entries', trim($missingTableErrorLog) === '', $missingTableErrorLog);

echo "\n=== Results: {$pass} passed, {$fail} failed ===\n";
exit($fail > 0 ? 1 : 0);
