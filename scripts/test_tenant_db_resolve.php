<?php
require __DIR__ . '/../bootstrap.php';

// Simulate what happens during a web request for palsystem.test
// Force resolve tenant 502
app()->tenant()->setTenantId(502);

// Now check what database db() returns when a tenant is resolved
$db = app()->db();
echo "DB with tenant 502 resolved: " . $db->query("SELECT DATABASE()")->fetchColumn() . "\n";

// Check if pal_ tables exist
$tables = $db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'pal_%' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
echo "pal_ tables found: " . count($tables) . "\n";
