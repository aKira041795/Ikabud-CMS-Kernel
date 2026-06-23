<?php
require __DIR__ . '/../bootstrap.php';

$module = app()->module('project-audit-ledger');
echo "Module loaded: " . ($module ? 'yes' : 'no') . "\n";

$mdb = $module->db();
echo "Module DB class: " . get_class($mdb) . "\n";
echo "Module DB database: " . $mdb->query("SELECT DATABASE()")->fetchColumn() . "\n";

// Test query
$rows = $mdb->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'pal_%' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
echo "pal_ tables in module DB: " . count($rows) . "\n";
foreach ($rows as $r) echo "  $r\n";
