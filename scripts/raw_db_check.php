<?php
// Direct PDO connection to check things
$host = getenv('DB_HOST') ?: '127.0.0.1';
$user = getenv('DB_USERNAME') ?: 'root';
$pass = getenv('DB_PASSWORD') ?: '';

try {
    $pdo = new PDO("mysql:host=$host", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
    
    echo "Default DB: " . $pdo->query("SELECT DATABASE()")->fetchColumn() . "\n";
    
    echo "\n=== Tables in palsystem ===\n";
    $rows = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'palsystem' AND TABLE_NAME LIKE 'pal_%' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
    echo count($rows) . " tables found\n";
    foreach ($rows as $r) echo "  $r\n";
    
    echo "\n=== Tables in cmsnewtest ===\n";
    $rows = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'cmsnewtest' AND TABLE_NAME LIKE 'pal_%' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
    echo count($rows) . " tables found\n";
    foreach ($rows as $r) echo "  $r\n";
    
    echo "\n=== Databases ===\n";
    $dbs = $pdo->query("SHOW DATABASES")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($dbs as $db) echo "  $db\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
