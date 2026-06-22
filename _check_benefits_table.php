<?php
require_once __DIR__ . '/bootstrap.php';

// Check if we can find a tenant to test with
$tenantId = null;
$tid = app()->tenant()->current();
if ($tid) {
    $tenantId = (int)$tid;
} else {
    // Try to find the zapattendance tenant manually
    echo "No tenant in context\n";
    exit(1);
}

$db = app()->dbForTenant($tenantId);

// Check if the table exists
$stmt = $db->query("SHOW TABLES LIKE 'benefits_contribution_rates'");
if ($stmt->rowCount() > 0) {
    echo "Table exists.\n";
    $cnt = $db->query("SELECT COUNT(*) FROM benefits_contribution_rates")->fetchColumn();
    echo "Rows: $cnt\n";
} else {
    echo "Table DOES NOT exist.\n";
}

// Check for migration
$mig = $db->query("SELECT migration FROM _migrations WHERE migration LIKE '%benefit%' OR migration LIKE '%008%'");
$rows = $mig->fetchAll(PDO::FETCH_COLUMN);
echo "Migrations: " . count($rows) . "\n";
foreach ($rows as $r) echo "  $r\n";
