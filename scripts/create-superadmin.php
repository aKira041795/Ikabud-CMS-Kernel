<?php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

require __DIR__ . '/../bootstrap.php';

// Direct DB connection using config
$dbConfig = require CONFIG_PATH . '/database.php';
$dsn = "mysql:host={$dbConfig['host']};dbname={$dbConfig['database']};charset=utf8mb4";
$db = new PDO($dsn, $dbConfig['username'], $dbConfig['password'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

echo "DB connected\n";

// Check ENUM
$col = $db->query("SHOW COLUMNS FROM users WHERE Field = 'role'")->fetch(PDO::FETCH_ASSOC);
echo "Role column type: " . ($col['Type'] ?? 'unknown') . "\n";

// Check if superadmin user already exists
$check = $db->prepare('SELECT id, role FROM users WHERE username = :u');
$check->execute([':u' => 'superadmin']);
$existing = $check->fetch(PDO::FETCH_ASSOC);

if ($existing) {
    echo "Superadmin user already exists: id={$existing['id']} role={$existing['role']}\n";
} else {
    $hash = password_hash('superadmin123', PASSWORD_BCRYPT);
    $stmt = $db->prepare('INSERT INTO users (username, password_hash, full_name, role, is_active) VALUES (:u, :p, :fn, :r, 1)');
    $stmt->execute([':u' => 'superadmin', ':p' => $hash, ':fn' => 'Settings Admin', ':r' => 'superadmin']);
    echo "Created superadmin user, id=" . $db->lastInsertId() . "\n";
}
