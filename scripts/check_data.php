<?php
require __DIR__ . '/../bootstrap.php';
app()->tenant()->setTenantId(502);
$db = app()->db();
// Check users
$users = $db->query("SELECT id, username, role FROM pal_users WHERE tenant_id = 502 LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
echo "Users in pal_users (tenant 502): " . count($users) . "\n";
foreach ($users as $u) echo "  id={$u['id']} user={$u['username']} role={$u['role']}\n";

// Check projects
$projects = $db->query("SELECT id, title, contract_amount FROM pal_projects WHERE tenant_id = 502 LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
echo "\nProjects: " . count($projects) . "\n";
foreach ($projects as $p) echo "  id={$p['id']} title={$p['title']} amount={$p['contract_amount']}\n";
