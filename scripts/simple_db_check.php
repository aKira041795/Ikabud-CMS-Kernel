<?php
require __DIR__ . '/../bootstrap.php';

$host = $_ENV['DB_HOST'] ?? '127.0.0.1';
$dbname = $_ENV['DB_DATABASE'] ?? 'baronbakeshop';
$user = $_ENV['DB_USERNAME'] ?? 'root';
$pass = $_ENV['DB_PASSWORD'] ?? '';

echo "DB Config from env:\n";
echo "  Host: $host\n";
echo "  Database: $dbname\n";
echo "  User: $user\n";
echo "  Pass: " . (empty($pass) ? '(empty)' : '(set)') . "\n\n";

// Use app()->db() to query
$db = app()->db();
echo "Default DB via app()->db(): " . $db->query("SELECT DATABASE()")->fetchColumn() . "\n";

// Check app()->dbForTenant
try {
    $tdb = app()->dbForTenant(502);
    echo "Tenant 502 DB: " . $tdb->query("SELECT DATABASE()")->fetchColumn() . "\n";
} catch (Exception $e) {
    echo "Tenant 502 DB error: " . $e->getMessage() . "\n";
}

echo "\nDone.\n";
