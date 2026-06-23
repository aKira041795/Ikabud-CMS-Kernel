<?php
require __DIR__ . '/../bootstrap.php';
$db = app()->db();
$rows = $db->query("SELECT id, tenant_key, canonical_domain FROM kernel_tenants WHERE canonical_domain LIKE '%palsystem%' OR tenant_key LIKE '%pal%'")->fetchAll(PDO::FETCH_ASSOC);
if ($rows) {
    foreach ($rows as $r) {
        echo "Tenant {$r['id']}: {$r['tenant_key']} / {$r['canonical_domain']}\n";
    }
} else {
    echo "No palsystem tenant found in kernel_tenants\n";
    // Fall back: find any tenant
    $all = $db->query("SELECT id, tenant_key, canonical_domain FROM kernel_tenants LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($all as $r) {
        echo "  Tenant {$r['id']}: {$r['tenant_key']} / {$r['canonical_domain']}\n";
    }
}
