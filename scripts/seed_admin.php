<?php
require __DIR__ . '/../bootstrap.php';
$_SERVER['HTTP_HOST'] = 'palsystem.test';
$router = new \Ikabud\Kernel\Http\TenantEntryRouter();
$router->rewriteUri('/');
app()->tenant()->setTenantId(502);
$db = app()->db();

// Create admin user with correct column names
$password = password_hash('admin123', PASSWORD_BCRYPT);
$stmt = $db->prepare("INSERT IGNORE INTO pal_users (tenant_id, username, password_hash, full_name, email, role, is_active) VALUES (502, 'admin', :pw, 'Administrator', 'admin@palsystem.test', 'admin', 1)");
$stmt->execute([':pw' => $password]);
echo "Admin user created: admin / admin123\n";

// Check all tables
$tables = $db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'pal_%' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
echo "\nAll pal_ tables (" . count($tables) . "):\n";
foreach ($tables as $t) echo "  $t\n";
