<?php
require __DIR__ . '/../bootstrap.php';

// Find the tenant
$db = app()->db();
$tenants = $db->query("SELECT id, tenant_key, canonical_domain FROM kernel_tenants ORDER BY id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
echo "All tenants:\n";
foreach ($tenants as $r) {
    echo "  {$r['id']}: {$r['tenant_key']} / {$r['canonical_domain']}\n";
}

// Check what database the pal-001 tenant actually uses
echo "\n--- Checking pal-001 tenant db ---\n";
try {
    $tdb = app()->dbForTenant(502);
    echo "Tenant 502 database: " . $tdb->query("SELECT DATABASE()")->fetchColumn() . "\n";
} catch (Exception $e) {
    echo "Error getting tenant DB: " . $e->getMessage() . "\n";
}

// Check what tables exist where
echo "\n--- Checking palsystem database ---\n";
try {
    $rows = $db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'palsystem' AND TABLE_NAME LIKE 'pal_%'")->fetchAll(PDO::FETCH_ASSOC);
    echo "Tables in palsystem: " . count($rows) . "\n";
    foreach ($rows as $r) {
        echo "  {$r['TABLE_NAME']}\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

echo "\n--- Checking cmsnewtest database ---\n";
try {
    $rows = $db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'cmsnewtest' AND TABLE_NAME LIKE 'pal_%'")->fetchAll(PDO::FETCH_ASSOC);
    echo "Tables in cmsnewtest: " . count($rows) . "\n";
    foreach ($rows as $r) {
        echo "  {$r['TABLE_NAME']}\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
