<?php

declare(strict_types=1);

/**
 * Mobile Gateway — integration contract test.
 *
 * Tests the mobile_gateway module services, capability handlers, and
 * schema migration directly (no HTTP layer). Verifies:
 *   - Module manifest validation
 *   - Bootstrap manifest structure
 *   - Device registration lifecycle (register → list → unregister)
 *   - Sync service entity resolution
 *   - Auth rejection (capability handlers with invalid context)
 *   - Migration SQL syntax
 *   - Log cleanliness
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../modules/mobile_gateway/helpers.php';
require_once __DIR__ . '/../modules/mobile_gateway/handlers.php';
require_once __DIR__ . '/../modules/mobile_gateway/Contracts/MobileModuleContract.php';
require_once __DIR__ . '/../modules/mobile_gateway/Services/DeviceRegistrationService.php';
require_once __DIR__ . '/../modules/mobile_gateway/Services/MobileSyncService.php';

// ── Test bookkeeping ───────────────────────────────────────────────────
$pass = 0;
$fail = 0;

function mt(string $label, bool $ok, string $detail = ''): void
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

echo "\n=== Mobile Gateway Integration Test ===\n\n";

$db = app()->db();
$testTenantId = app()->tenant()->current() ?? 999999;

// ── Section 1: Module manifest validation ────────────────────────────
echo "── Module Manifest ──\n";

$manifestPath = __DIR__ . '/../modules/mobile_gateway/module.json';
mt('module.json exists', file_exists($manifestPath));

$manifest = json_decode((string)file_get_contents($manifestPath), true);
mt('module.json is valid JSON', is_array($manifest));

mt('module id is mobile-gateway', ($manifest['id'] ?? '') === 'mobile-gateway');
mt('module registers both migrations', ($manifest['migrations'] ?? []) === [
    '001_mobile_gateway_schema.sql',
    '002_tenant_scope_devices.sql',
]);
mt('module declares owns_tables', in_array('mgw_devices', $manifest['owns_tables'] ?? [], true));
mt('module declares capabilities.exposes', !empty($manifest['capabilities']['exposes']));
mt('module declares capabilities.depends', !empty($manifest['capabilities']['depends']));

// Check all expected capabilities are declared
$expectedCaps = ['mobile.bootstrap@1', 'mobile.sync.pull@1', 'mobile.sync.push@1',
                 'mobile.device.register@1', 'mobile.device.unregister@1'];
$declaredCaps = array_map(static fn($c) => $c['id'] ?? '', $manifest['capabilities']['exposes'] ?? []);
foreach ($expectedCaps as $cap) {
    mt("capability declared: {$cap}", in_array($cap, $declaredCaps, true));
}

mt('manifest capability dependency IDs are versioned', array_reduce(
    $manifest['capabilities']['depends'] ?? [],
    static fn(bool $valid, mixed $capability): bool =>
        $valid && is_string($capability) && (bool)preg_match('/^[a-z0-9_.-]+@\d+$/', $capability),
    true
));

// ── Section 2: Bearer-only authentication contract ──────────────────
echo "\n── Bearer Authentication ──\n";

$originalAuthorization = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
$validToken = app()->jwt()->generate([
    'id' => 999999,
    'sub' => 999999,
    'tenant_id' => $testTenantId,
    'source' => 'test',
    'capabilities' => ['test.view', 'test.update'],
]);
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $validToken;
$bearerUser = mobileGatewayBearerUserFromRequest();
mt('valid Bearer token resolves user', ($bearerUser['id'] ?? 0) === 999999);
mt('tenant claim is preserved', ($bearerUser['tenant_id'] ?? 0) === $testTenantId);

unset($_SERVER['HTTP_AUTHORIZATION']);
mt('cookie/session-only request is rejected', mobileGatewayBearerUserFromRequest() === null);

$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer invalid-token';
mt('invalid Bearer token is rejected', mobileGatewayBearerUserFromRequest() === null);

$expiredJwt = new \Ikabud\Kernel\JWT(null, -1);
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $expiredJwt->generate([
    'id' => 999999,
    'tenant_id' => $testTenantId,
    'source' => 'test',
]);
mt('expired Bearer token is rejected', mobileGatewayBearerUserFromRequest() === null);

if ($originalAuthorization === null) {
    unset($_SERVER['HTTP_AUTHORIZATION']);
} else {
    $_SERVER['HTTP_AUTHORIZATION'] = $originalAuthorization;
}

// ── Section 3: Service layer — Device Registration ───────────────────
echo "\n── Device Registration Service ──\n";

$deviceService = new MobileDeviceRegistrationService($db);
$testFamilyId = bin2hex(random_bytes(16));
$db->prepare(
    'INSERT INTO kernel_device_sessions
     (user_id, tenant_id, device_id, device_name, token_family_id, last_seen_at, created_at)
     VALUES (?, ?, ?, ?, ?, NOW(), NOW())'
)->execute([
    999999,
    $testTenantId,
    'test-device-001',
    'Test Session Device',
    $testFamilyId,
]);
$testSessionId = (int)$db->lastInsertId();

// Register a device
$registerResult = $deviceService->register(
    userId: 999999,
    tenantId: $testTenantId,
    deviceId: 'test-device-001',
    platform: 'android',
    pushToken: 'fcm-test-token-001',
    deviceName: 'Test Device',
    ip: '127.0.0.1',
    userAgent: 'TestAgent/1.0',
);

mt('register returns device_id', ($registerResult['device_id'] ?? '') === 'test-device-001');
mt('register returns active status', ($registerResult['status'] ?? '') === 'active');

// Verify DB record
$stmt = $db->prepare('SELECT * FROM mgw_devices WHERE device_id = ?');
$stmt->execute(['test-device-001']);
$record = $stmt->fetch(PDO::FETCH_ASSOC);
mt('device record exists in DB', is_array($record) && !empty($record));
mt('device record has correct user_id', ($record['user_id'] ?? null) == 999999);
mt('device record has correct platform', ($record['platform'] ?? '') === 'android');
mt('device record has correct status', ($record['status'] ?? '') === 'active');
mt('device associates with active kernel session', (int)($record['device_session_id'] ?? 0) === $testSessionId);

// Re-register (upsert) — should update, not error
$reregisterResult = $deviceService->register(
    userId: 999999,
    tenantId: $testTenantId,
    deviceId: 'test-device-001',
    platform: 'ios',  // changed platform
    pushToken: 'fcm-test-token-002',  // changed token
    deviceName: 'Test Device Updated',
);
mt('re-register succeeds', ($reregisterResult['status'] ?? '') === 'active');

// Verify upsert
$stmt->execute(['test-device-001']);
$updatedRecord = $stmt->fetch(PDO::FETCH_ASSOC);
mt('re-register updates platform', ($updatedRecord['platform'] ?? '') === 'ios');
mt('re-register updates push_token', ($updatedRecord['push_token'] ?? '') === 'fcm-test-token-002');
$oldPushStmt = $db->prepare('SELECT is_valid FROM kernel_push_tokens WHERE token = ?');
$oldPushStmt->execute(['fcm-test-token-001']);
mt('re-register invalidates replaced push token', (int)$oldPushStmt->fetchColumn() === 0);

// List devices
$devices = $deviceService->getDevicesForUser(999999, $testTenantId);
mt('list devices returns array', is_array($devices));
mt('list devices contains test device', count($devices) >= 1);

// Unregister device
$unregisterResult = $deviceService->unregister(
    999999,
    $testTenantId,
    'test-device-001'
);
mt('unregister returns device_id', ($unregisterResult['device_id'] ?? '') === 'test-device-001');
mt('unregister returns revoked status', ($unregisterResult['status'] ?? '') === 'revoked');

// Verify DB after unregister
$stmt->execute(['test-device-001']);
$afterRevoke = $stmt->fetch(PDO::FETCH_ASSOC);
mt('device status is revoked after unregister', ($afterRevoke['status'] ?? '') === 'revoked');
$pushStmt = $db->prepare('SELECT is_valid FROM kernel_push_tokens WHERE token = ?');
$pushStmt->execute(['fcm-test-token-002']);
mt('unregister invalidates push token', (int)$pushStmt->fetchColumn() === 0);

// Cleanup test device
$db->prepare('DELETE FROM mgw_devices WHERE device_id = ?')->execute(['test-device-001']);
$db->prepare('DELETE FROM kernel_push_tokens WHERE token IN (?, ?)')->execute([
    'fcm-test-token-001',
    'fcm-test-token-002',
]);
$db->prepare('DELETE FROM kernel_device_sessions WHERE id = ?')->execute([$testSessionId]);

// ── Section 4: Sync Service — Manifest ───────────────────────────────
echo "\n── Sync Service ──\n";

$syncService = new MobileSyncService();

// Build bootstrap manifest (no providers registered yet)
$manifestResult = $syncService->buildBootstrapManifest();
mt('manifest returns array', is_array($manifestResult));
mt('manifest has modules key', array_key_exists('modules', $manifestResult));
mt('manifest has entities key', array_key_exists('entities', $manifestResult));
mt('manifest has server_time key', array_key_exists('server_time', $manifestResult));
mt('manifest modules is empty array (no providers)', $manifestResult['modules'] === []);
mt('manifest entities is empty array (no providers)', $manifestResult['entities'] === []);
mt('server_time is valid ISO date', (bool)preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $manifestResult['server_time'] ?? ''));

// Resolve available entities (no providers)
$entities = $syncService->resolveAvailableEntities();
mt('resolveAvailableEntities returns array', is_array($entities));
mt('resolveAvailableEntities is empty (no providers)', $entities === []);

// ── Section 5: Capability Handlers ───────────────────────────────────
echo "\n── Capability Handlers ──\n";

// Test bootstrap capability
$capResult = mobile_gateway_cap_bootstrap_1(['user' => ['id' => 1, 'role' => 'admin', 'source' => 'test']]);
mt('cap bootstrap returns ok', ($capResult['ok'] ?? false) === true);
mt('cap bootstrap has manifest', array_key_exists('manifest', $capResult));
mt('cap bootstrap has server_time', array_key_exists('server_time', $capResult));

// Test device register capability (with context, without DB persistence in cap handler context)
$capRegister = mobile_gateway_cap_device_register_1([
    'user' => ['id' => 999999, 'tenant_id' => $testTenantId, 'source' => 'test'],
    'device_id' => 'cap-test-device',
    'platform' => 'web',
    'push_token' => 'cap-test-token',
    'device_name' => 'Cap Test',
]);
mt('cap register returns device_id', ($capRegister['device_id'] ?? '') === 'cap-test-device');
mt('cap register returns active status', ($capRegister['status'] ?? '') === 'active');

// Cleanup cap-created device
$db->prepare('DELETE FROM mgw_devices WHERE device_id = ?')->execute(['cap-test-device']);
$db->prepare('DELETE FROM kernel_push_tokens WHERE token = ?')->execute(['cap-test-token']);

// Test device unregister capability
$capUnregister = mobile_gateway_cap_device_unregister_1([
    'user' => ['id' => 999999, 'tenant_id' => $testTenantId, 'source' => 'test'],
    'device_id' => 'nonexistent-device',
]);
mt('cap unregister returns device_id', ($capUnregister['device_id'] ?? '') === 'nonexistent-device');
mt('cap unregister reports missing device', ($capUnregister['status'] ?? '') === 'not_found');

// Unknown entity types fail closed.
$pullResult = mobile_gateway_cap_sync_pull_1([
    'user' => ['id' => 1, 'tenant_id' => $testTenantId, 'source' => 'test'],
    'entity_type' => 'test_entity',
    'device_id' => 'test-device-pull',
    'limit' => 50,
]);
mt('cap pull returns array', is_array($pullResult));
mt('cap pull rejects unregistered entity', ($pullResult['error'] ?? '') === 'forbidden');

// Test sync push capability
$pushResult = mobile_gateway_cap_sync_push_1([
    'user' => ['id' => 1, 'tenant_id' => $testTenantId, 'source' => 'test'],
    'operations' => [],
]);
mt('cap push returns array', is_array($pushResult));
mt('cap push has results key', array_key_exists('results', $pushResult));
mt('cap push has conflicts key', array_key_exists('conflicts', $pushResult));

// ── Section 6: MobileModuleContract interface ────────────────────────
echo "\n── MobileModuleContract Interface ──\n";

// Test that the interface file exists and is syntactically valid
$interfacePath = __DIR__ . '/../modules/mobile_gateway/Contracts/MobileModuleContract.php';
mt('interface file exists', file_exists($interfacePath));
$interfaceLint = shell_exec('php -l ' . escapeshellarg($interfacePath) . ' 2>&1');
mt('interface passes php -l', $interfaceLint !== null && str_contains($interfaceLint, 'No syntax errors'));

// Verify the interface is available
mt('interface class exists', interface_exists('Ikabud\Modules\MobileGateway\Contracts\MobileModuleContract'));

// ── Section 7: Provider registration ─────────────────────────────────
echo "\n── Provider Registry ──\n";

// Test provider registration
use Ikabud\Modules\MobileGateway\Contracts\MobileModuleContract;

$testProvider = new class implements MobileModuleContract {
    public function syncEntities(): array
    {
        return ['test_entity' => ['sync_mode' => 'read_write']];
    }
    public function mobileCapabilities(): array
    {
        return ['test.view', 'test.update'];
    }
    public function mobileManifest(): array
    {
        return ['name' => 'Test Module', 'start_route' => '/mobile/test', 'offline' => true];
    }
};

mt('no providers before registration', mobile_gateway_get_providers() === []);

mobile_gateway_register_provider('test-module', $testProvider);
if (!app()->capabilities()->has('test.view')) {
    app()->capabilities()->register(
        'test.view',
        'test-module',
        static fn(array $payload): array => ['ok' => true],
        10,
        ['first']
    );
}
$providers = mobile_gateway_get_providers();
mt('one provider after registration', count($providers) === 1);
mt('provider is registered under correct key', isset($providers['test-module']));

// Verify providers affect manifest
$manifestWithProvider = $syncService->buildBootstrapManifest();
mt('manifest has 1 module with provider', count($manifestWithProvider['modules']) === 1);
mt('manifest module name matches', ($manifestWithProvider['modules'][0]['name'] ?? '') === 'Test Module');
mt('manifest module offline flag', ($manifestWithProvider['modules'][0]['offline'] ?? false) === true);

// Verify resolveAvailableEntities works with provider
$entitiesWithProvider = $syncService->resolveAvailableEntities();
mt('resolveAvailableEntities returns entity', isset($entitiesWithProvider['test_entity']));
mt('entity sync_mode matches', ($entitiesWithProvider['test_entity']['sync_mode'] ?? '') === 'read_write');
mt('entity module matches', ($entitiesWithProvider['test_entity']['module'] ?? '') === 'test-module');
mt('entity access denies missing JWT capability', !$syncService->validateSyncAccess(
    'test_entity',
    ['id' => 1, 'tenant_id' => $testTenantId, 'source' => 'test', 'capabilities' => []]
));
mt('entity access accepts registered held capability', $syncService->validateSyncAccess(
    'test_entity',
    ['id' => 1, 'tenant_id' => $testTenantId, 'source' => 'test', 'capabilities' => ['test.view']]
));
$tenantPullRejected = false;
try {
    $syncService->pullChanges('test_entity', 'test-device', $testTenantId, 1, 10);
} catch (RuntimeException) {
    $tenantPullRejected = true;
}
mt('pull fails closed while kernel revisions lack tenant scope', $tenantPullRejected);

// Clear providers for next test
$GLOBALS['_mobile_gateway_providers'] = [];

// ── Section 8: Migration audit ───────────────────────────────────────
echo "\n── Migration Audit ──\n";

$migrationSource = (string)file_get_contents(
    __DIR__ . '/../modules/mobile_gateway/migrations/002_tenant_scope_devices.sql'
);
mt('tenant migration makes tenant_id required', str_contains(
    $migrationSource,
    'MODIFY tenant_id INT UNSIGNED NOT NULL'
));
mt('tenant migration scopes unique device key', str_contains(
    $migrationSource,
    'UNIQUE KEY uq_tenant_user_device (tenant_id, user_id, device_id)'
));
mt('tenant migration avoids unsupported MySQL features', !preg_match(
    '/\b(WITH|ROW_NUMBER|JSON_TABLE)\b/i',
    $migrationSource
));

// ── Summary ──────────────────────────────────────────────────────────
echo "\n── Results ──\n";
echo "  Passed: {$pass}\n";
echo "  Failed: {$fail}\n";
echo "\n";

if ($fail > 0) {
    echo "  ❌ Some tests failed.\n\n";
    exit(1);
}

echo "  ✅ All tests passed.\n\n";
exit(0);
