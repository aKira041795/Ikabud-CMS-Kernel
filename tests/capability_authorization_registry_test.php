<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use Ikabud\Kernel\Capabilities\CapabilityAuthorizationRegistry;
use Ikabud\Kernel\Capabilities\CapabilityAuthorizationRegistryUnavailableException;
use Ikabud\Kernel\Capabilities\CapabilityBus;
use Ikabud\Kernel\Capabilities\CapabilityCallException;
use Ikabud\Kernel\Capabilities\CapabilityRegistry;
use Ikabud\Kernel\Capabilities\ServiceProxy;

$pass = 0;
$fail = 0;

@file_put_contents(__DIR__ . '/../storage/logs/error.log', '');
@file_put_contents(__DIR__ . '/../storage/logs/app.log', '');

function t(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  ✅ {$label}\n";
        return;
    }

    $fail++;
    echo "  ❌ {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

function expectFailure(string $label, callable $fn, string $contains = '', string $class = CapabilityCallException::class): void
{
    try {
        $fn();
        t($label, false, 'expected ' . $class);
    } catch (Throwable $e) {
        if (!($e instanceof $class)) {
            t($label, false, get_class($e) . ': ' . $e->getMessage());
            return;
        }
        t($label, $contains === '' || str_contains($e->getMessage(), $contains), $e->getMessage());
    }
}

function testDbConfig(): array
{
    return require __DIR__ . '/../config/database.php';
}

function testDb(): PDO
{
    $config = testDbConfig();
    return new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $config['host'], $config['port'], $config['database'], $config['charset']),
        $config['username'],
        $config['password'],
        $config['options']
    );
}

class ThrowingPolicyPdo extends PDO
{
    public function __construct()
    {
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        throw new PDOException('policy registry unavailable');
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        throw new PDOException('policy registry unavailable');
    }
}

echo "=== Capability Authorization Registry Tests ===\n\n";

$db = testDb();
$db->exec((string)file_get_contents(__DIR__ . '/../database/migrations/025_kernel_capability_authorization_policies.sql'));
$db->exec("DELETE FROM capability_authorization_policies WHERE policy_version >= 900100");
CapabilityAuthorizationRegistry::invalidate();

$registry = new CapabilityAuthorizationRegistry($db);

$allowed = $registry->authorize([
    'capability_id' => 'proof_lane.ping',
    'capability_version' => '1',
    'provider' => 'proof-lane',
    'caller_module' => 'kernel',
    'actor_role' => 'admin',
    'tenant_id' => 'tenant-proof',
    'provider_activation' => true,
    'explicit_provider' => 'proof-lane',
]);
t('seeded proof-lane policy allows correct caller/role/tenant/provider', ($allowed['allowed'] ?? false) === true, json_encode($allowed));

$disabledCaller = $registry->authorize([
    'capability_id' => 'proof_lane.ping',
    'capability_version' => '1',
    'provider' => 'proof-lane',
    'caller_module' => 'cms',
    'actor_role' => 'admin',
    'tenant_id' => 'tenant-proof',
    'provider_activation' => true,
]);
t('disabled caller denied', ($disabledCaller['allowed'] ?? true) === false && ($disabledCaller['reason'] ?? '') === 'disabled_caller', json_encode($disabledCaller));

$disabledProvider = $registry->authorize([
    'capability_id' => 'proof_lane.ping',
    'capability_version' => '1',
    'provider' => 'other-provider',
    'caller_module' => 'kernel',
    'actor_role' => 'admin',
    'tenant_id' => 'tenant-proof',
    'provider_activation' => true,
]);
t('disabled provider denied', ($disabledProvider['allowed'] ?? true) === false && ($disabledProvider['reason'] ?? '') === 'disabled_provider', json_encode($disabledProvider));

$unknownRole = $registry->authorize([
    'capability_id' => 'proof_lane.ping',
    'capability_version' => '1',
    'provider' => 'proof-lane',
    'caller_module' => 'kernel',
    'actor_role' => 'viewer',
    'tenant_id' => 'tenant-proof',
    'provider_activation' => true,
]);
t('unknown role denied', ($unknownRole['allowed'] ?? true) === false && ($unknownRole['reason'] ?? '') === 'unknown_role', json_encode($unknownRole));

$missingTenant = $registry->authorize([
    'capability_id' => 'proof_lane.ping',
    'capability_version' => '1',
    'provider' => 'proof-lane',
    'caller_module' => 'kernel',
    'actor_role' => 'admin',
    'tenant_id' => '',
    'provider_activation' => true,
]);
t('missing tenant denied', ($missingTenant['allowed'] ?? true) === false && ($missingTenant['reason'] ?? '') === 'missing_tenant', json_encode($missingTenant));

$versionMismatch = $registry->authorize([
    'capability_id' => 'proof_lane.ping',
    'capability_version' => '2',
    'provider' => 'proof-lane',
    'caller_module' => 'kernel',
    'actor_role' => 'admin',
    'tenant_id' => 'tenant-proof',
    'provider_activation' => true,
]);
t('version mismatch denied', ($versionMismatch['allowed'] ?? true) === false && ($versionMismatch['reason'] ?? '') === 'version_mismatch', json_encode($versionMismatch));

$explicitProvider = $registry->authorize([
    'capability_id' => 'proof_lane.ping',
    'capability_version' => '1',
    'provider' => 'proof-lane',
    'caller_module' => 'kernel',
    'actor_role' => 'admin',
    'tenant_id' => 'tenant-proof',
    'provider_activation' => true,
    'explicit_provider' => 'proof-lane',
]);
t('explicit provider matching row allowed', ($explicitProvider['allowed'] ?? false) === true, json_encode($explicitProvider));

$defaultDeny = $registry->authorize([
    'capability_id' => 'proof_lane.missing',
    'capability_version' => '1',
    'provider' => 'proof-lane',
    'caller_module' => 'kernel',
    'actor_role' => 'admin',
    'tenant_id' => 'tenant-proof',
    'provider_activation' => true,
]);
t('default deny for missing proof-lane policy row', ($defaultDeny['allowed'] ?? true) === false && ($defaultDeny['reason'] ?? '') === 'missing_policy_row', json_encode($defaultDeny));

$versionA = 900101;
$versionB = 900102;
$versionInactive = 900103;
$registry->seedPolicy([
    [
        'policy_version' => $versionA,
        'capability_id' => 'authz.versioned',
        'capability_version' => '1',
        'provider' => 'proof-lane',
        'caller_module' => 'kernel',
        'allowed_roles' => 'admin',
        'provider_activation_required' => 1,
        'requires_protocol' => 'v2',
        'is_active' => 1,
    ],
    [
        'policy_version' => $versionB,
        'capability_id' => 'authz.versioned',
        'capability_version' => '1',
        'provider' => 'proof-lane',
        'caller_module' => 'kernel',
        'allowed_roles' => 'editor',
        'provider_activation_required' => 1,
        'requires_protocol' => 'v2',
        'is_active' => 1,
    ],
    [
        'policy_version' => $versionInactive,
        'capability_id' => 'authz.versioned',
        'capability_version' => '1',
        'provider' => 'proof-lane',
        'caller_module' => 'kernel',
        'allowed_roles' => 'admin',
        'provider_activation_required' => 1,
        'requires_protocol' => 'v2',
        'is_active' => 0,
    ],
]);

$highestWins = $registry->authorize([
    'capability_id' => 'authz.versioned',
    'capability_version' => '1',
    'provider' => 'proof-lane',
    'caller_module' => 'kernel',
    'actor_role' => 'editor',
    'tenant_id' => 'tenant-proof',
    'provider_activation' => true,
]);
t('highest active policy version wins', ($highestWins['allowed'] ?? false) === true && (int)($highestWins['policy_version'] ?? 0) === $versionB, json_encode($highestWins));

$inactiveOverride = $registry->authorize([
    'capability_id' => 'authz.versioned',
    'capability_version' => '1',
    'provider' => 'proof-lane',
    'caller_module' => 'kernel',
    'actor_role' => 'admin',
    'tenant_id' => 'tenant-proof',
    'provider_activation' => true,
    'policy_version' => $versionInactive,
]);
t('override to inactive policy version denied', ($inactiveOverride['allowed'] ?? true) === false && ($inactiveOverride['reason'] ?? '') === 'inactive_policy_version_override', json_encode($inactiveOverride));

$busRegistry = new CapabilityRegistry();
$bus = new CapabilityBus($busRegistry);
$busRegistry->register('legacy.unregistered@1', 'kernel', fn(array $payload) => ['ok' => true, 'echo' => $payload['value'] ?? null], 10, ['first']);
$legacy = $bus->call('legacy.unregistered@1', ['value' => 'still-works'], ['caller' => ['module' => 'kernel', 'user' => ['id' => 1, 'role' => 'admin']], 'tenant_id' => 'tenant-proof']);
t('unregistered v1 capability still resolves through CapabilityBus', is_array($legacy) && ($legacy['echo'] ?? '') === 'still-works', json_encode($legacy));

CapabilityAuthorizationRegistry::invalidate();
try {
    (new CapabilityAuthorizationRegistry(new ThrowingPolicyPdo()))->authorize([
        'capability_id' => 'proof_lane.ping',
        'capability_version' => '1',
        'provider' => 'proof-lane',
        'caller_module' => 'kernel',
        'actor_role' => 'admin',
        'tenant_id' => 'tenant-proof',
        'provider_activation' => true,
    ]);
    t('db unavailable is fail-closed', false, 'expected exception');
} catch (Throwable $e) {
    t('db unavailable is fail-closed', $e instanceof CapabilityAuthorizationRegistryUnavailableException && str_contains($e->getMessage(), 'policy registry unavailable'), $e->getMessage());
}

$appLog = (string)@file_get_contents(__DIR__ . '/../storage/logs/app.log');
t('audit log entry written for authorize()', str_contains($appLog, 'capability.authz.decision'), $appLog);

$proxyV2 = new ServiceProxy([
    'endpoint' => 'http://127.0.0.1:9',
    'protocol' => 'http+json',
    'requires_protocol' => 'v2',
    'timeout_ms' => 1000,
]);
expectFailure('v1 ServiceProxy refuses v2-requiring capability', fn() => $proxyV2(['ping' => true], 'proof_lane.ping@1', 'proof-lane'), 'requires protocol v2');

$proxyV1 = new ServiceProxy([
    'endpoint' => 'http://unit.test',
    'protocol' => 'http+json',
    'timeout_ms' => 1000,
]);
$proxyV1->setHttpHandler(static fn(string $url, array $opts): array => ['status' => 200, 'body' => json_encode(['ok' => true, 'data' => ['url' => $url, 'seen' => true]], JSON_THROW_ON_ERROR)]);
$resultV1 = $proxyV1(['ping' => true], 'legacy.v1@1', 'proof-lane');
t('v1 ServiceProxy still dispatches v1 capability', is_array($resultV1) && ($resultV1['seen'] ?? false) === true, json_encode($resultV1));

$db->exec("DELETE FROM capability_authorization_policies WHERE policy_version >= 900100");
CapabilityAuthorizationRegistry::invalidate();

echo "\n=== Results: {$pass} passed, {$fail} failed ===\n";
exit($fail > 0 ? 1 : 0);
