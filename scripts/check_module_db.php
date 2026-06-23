<?php
require __DIR__ . '/../bootstrap.php';

// Simulate what the module handler does
$module = app()->module('project-audit-ledger');
$mdb = $module->db();

echo "Module DB database: " . $mdb->query("SELECT DATABASE()")->fetchColumn() . "\n";

// Check if there's a USE or schema switch happening
echo "Module host: " . $mdb->getAttribute(PDO::ATTR_CONNECTION_STATUS) . "\n";

// Check what default DB is
$defDb = app()->db()->query("SELECT DATABASE()")->fetchColumn();
echo "Default DB: $defDb\n";

// Try dbForTenant
$tDb = app()->dbForTenant(502);
echo "Tenant 502 DB: " . $tDb->query("SELECT DATABASE()")->fetchColumn() . "\n";
