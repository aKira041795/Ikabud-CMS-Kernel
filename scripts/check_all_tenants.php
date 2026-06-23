<?php
require __DIR__ . '/../bootstrap.php';
$db = app()->db();
$all = $db->query("SELECT id, tenant_key, canonical_domain, db_connection FROM kernel_tenants WHERE id > 500 LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
echo "Tenants with ID > 500:\n";
foreach ($all as $r) {
    echo "  {$r['id']}: {$r['tenant_key']} / {$r['canonical_domain']} / db={$r['db_connection']}\n";
}
echo "\n---\n";
// Also check app() databases
echo "app()->db() database: " . $db->query("SELECT DATABASE()")->fetchColumn() . "\n";
