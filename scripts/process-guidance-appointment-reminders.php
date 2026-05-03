<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';

$modules = discoverModules();
$guidance = $modules['guidance'] ?? null;
if (!is_array($guidance)) {
    fwrite(STDERR, "Guidance module manifest not found.\n");
    exit(1);
}

loadModuleHelpers($guidance);
moduleWithContext('guidance', static function () use ($guidance): void {
    require_once (string)($guidance['_path'] ?? '') . '/handlers.php';
});

$controlDb = app()->controlDb();
$stmt = $controlDb->query(
    "SELECT id FROM kernel_tenants WHERE status = 'active' AND entry_module_id = 'guidance' ORDER BY id ASC"
);
$tenantIds = array_map(static fn(array $row): int => (int)($row['id'] ?? 0), $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : []);
$tenantIds = array_values(array_filter($tenantIds, static fn(int $tenantId): bool => $tenantId > 0));

$originalTenantId = app()->tenant()->current();
$summary = [
    'tenants' => 0,
    'due' => 0,
    'sent' => 0,
    'failed' => 0,
    'results' => [],
];

try {
    foreach ($tenantIds as $tenantId) {
        app()->tenant()->setTenantId($tenantId);
        app()->reconnectDb();
        invalidateModuleContextCache('guidance');

        $result = moduleWithContext('guidance', static function (): array {
            return guidanceProcessAppointmentReminders(guidanceDb());
        });

        $summary['tenants']++;
        $summary['due'] += (int)($result['due'] ?? 0);
        $summary['sent'] += (int)($result['sent'] ?? 0);
        $summary['failed'] += (int)($result['failed'] ?? 0);
        $summary['results'][] = ['tenant_id' => $tenantId] + $result;
    }
} finally {
    app()->tenant()->setTenantId($originalTenantId);
    app()->reconnectDb();
    invalidateModuleContextCache('guidance');
}

echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;