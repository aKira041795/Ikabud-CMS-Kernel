<?php

declare(strict_types=1);

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'applicationos.test';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/kernel/integrations';

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';

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

echo "\n=== INTEGRATION BRIDGE VALIDATION ===\n";

$db = app()->db();
tenantSyncKernelMigrations($db);
loadModuleRoutes([
    'GET' => [],
    'POST' => [],
    'PUT' => [],
    'DELETE' => [],
]);

$suffix = substr(bin2hex(random_bytes(4)), 0, 8);
$bridgeName = 'test_bridge_validation_' . $suffix;

try {
    $validReserveBridge = \Ikabud\Kernel\IntegrationBridge::validateDefinition([
        'name' => 'Reserve Bridge ' . $suffix,
        'trigger_event' => 'ecommerce.order.created',
        'target_capability' => 'wms.stock.reserve@1',
        'mapping_json' => [
            'reference_type' => 'order',
            'reference_id' => '{{order.id}}',
            'items' => '{{order.items}}',
            'idempotency_key' => '{{idempotency_key}}',
        ],
    ]);

    t(
        'shared validator accepts a valid ecommerce to WMS bridge',
        !empty($validReserveBridge['ok']),
        json_encode($validReserveBridge['errors'] ?? [], JSON_UNESCAPED_SLASHES)
    );
    t(
        'shared validator defaults version lock for explicit capability versions',
        ($validReserveBridge['normalized']['version_lock'] ?? null) === 'wms.stock.reserve@1',
        json_encode($validReserveBridge['normalized'] ?? [], JSON_UNESCAPED_SLASHES)
    );

    $unknownVariableBridge = \Ikabud\Kernel\IntegrationBridge::validateDefinition([
        'name' => 'Unknown Variable Bridge ' . $suffix,
        'trigger_event' => 'ecommerce.order.created',
        'target_capability' => 'wms.stock.reserve@1',
        'mapping_json' => [
            'reference_type' => 'order',
            'reference_id' => '{{order.unknown_id}}',
        ],
    ]);

    t(
        'shared validator rejects unknown event mapping variables',
        empty($unknownVariableBridge['ok'])
            && str_contains(implode(' ', $unknownVariableBridge['errors'] ?? []), 'Unknown mapping variables'),
        json_encode($unknownVariableBridge['errors'] ?? [], JSON_UNESCAPED_SLASHES)
    );

    $schemaBridge = \Ikabud\Kernel\IntegrationBridge::validateDefinition([
        'name' => 'Schema Bridge ' . $suffix,
        'trigger_event' => 'wms.order.delivered',
        'target_capability' => 'ecommerce.orders.status.sync@1',
        'mapping_json' => [
            'order_id' => '{{wms_order_id}}',
        ],
    ]);

    t(
        'shared validator rejects mappings missing schema required keys',
        empty($schemaBridge['ok'])
            && str_contains(implode(' ', $schemaBridge['errors'] ?? []), 'mapping.status is required'),
        json_encode($schemaBridge['errors'] ?? [], JSON_UNESCAPED_SLASHES)
    );

    $policyBridge = \Ikabud\Kernel\IntegrationBridge::validateDefinition([
        'name' => 'Policy Bridge ' . $suffix,
        'trigger_event' => 'ecommerce.order.created',
        'target_capability' => 'ecommerce.orders.create@1',
        'mapping_json' => [],
    ]);

    t(
        'shared validator rejects target capabilities that deny kernel callers',
        empty($policyBridge['ok'])
            && str_contains(implode(' ', $policyBridge['errors'] ?? []), 'caller policy denies kernel bridge access'),
        json_encode($policyBridge['errors'] ?? [], JSON_UNESCAPED_SLASHES)
    );

    $versionLockBridge = \Ikabud\Kernel\IntegrationBridge::validateDefinition([
        'name' => 'Version Lock Bridge ' . $suffix,
        'trigger_event' => 'ecommerce.order.created',
        'target_capability' => 'wms.stock.reserve@1',
        'version_lock' => 'wms.stock.reserve@999',
        'mapping_json' => [
            'reference_type' => 'order',
            'reference_id' => '{{order.id}}',
        ],
    ]);

    t(
        'shared validator rejects mismatched version locks',
        empty($versionLockBridge['ok'])
            && str_contains(implode(' ', $versionLockBridge['errors'] ?? []), 'Version lock mismatch'),
        json_encode($versionLockBridge['errors'] ?? [], JSON_UNESCAPED_SLASHES)
    );

    $upsertId = \Ikabud\Kernel\IntegrationBridge::upsertBridge([
        'name' => $bridgeName,
        'trigger_event' => 'ecommerce.order.created',
        'target_capability' => 'wms.stock.reserve@1',
        'mapping' => [
            'reference_type' => 'order',
            'reference_id' => '{{order.id}}',
            'items' => '{{order.items}}',
        ],
    ]);
    $storedBridge = $db->prepare('SELECT version_lock FROM kernel_integrations WHERE id = ? LIMIT 1');
    $storedBridge->execute([$upsertId]);
    $storedVersionLock = (string)($storedBridge->fetchColumn() ?: '');

    t(
        'programmatic upsert persists shared version-lock normalization',
        $storedVersionLock === 'wms.stock.reserve@1',
        $storedVersionLock
    );

    $rejectedByUpsert = false;
    try {
        \Ikabud\Kernel\IntegrationBridge::upsertBridge([
            'name' => $bridgeName . '_invalid',
            'trigger_event' => 'ecommerce.order.created',
            'target_capability' => 'ecommerce.orders.create@1',
            'mapping' => [],
        ]);
    } catch (\InvalidArgumentException $e) {
        $rejectedByUpsert = str_contains($e->getMessage(), 'caller policy denies kernel bridge access');
    }

    t('programmatic upsert uses the shared bridge validator', $rejectedByUpsert);
} finally {
    $deleteLogs = $db->prepare('DELETE FROM kernel_integration_logs WHERE integration_id IN (SELECT id FROM kernel_integrations WHERE name LIKE ?)');
    $deleteLogs->execute([$bridgeName . '%']);
    $deleteBridges = $db->prepare('DELETE FROM kernel_integrations WHERE name LIKE ?');
    $deleteBridges->execute([$bridgeName . '%']);
}

echo "\nPassed: {$pass}, Failed: {$fail}\n";
if ($fail > 0) {
    echo implode("\n", $errors) . "\n";
    exit(1);
}

exit(0);