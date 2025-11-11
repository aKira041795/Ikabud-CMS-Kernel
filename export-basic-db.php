#!/usr/bin/env php
<?php
/**
 * Ikabud Kernel - Basic Database Exporter
 * 
 * Exports database schema and basic user data
 * 
 * Usage:
 *   php export-basic-db.php [output-file]
 * 
 * Example:
 *   php export-basic-db.php database/basic-data.sql
 */

$outputFile = $argv[1] ?? 'database/basic-data.sql';
$sourceDir = dirname(__FILE__);

echo "Ikabud Kernel - Database Exporter\n";
echo "==================================\n\n";

// Load .env file
$envFile = $sourceDir . '/.env';
if (!file_exists($envFile)) {
    echo "✗ Error: .env file not found\n";
    echo "  Please configure your database first\n";
    exit(1);
}

// Parse .env file manually to handle quotes and special characters
$envVars = [];
$lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
foreach ($lines as $line) {
    $line = trim($line);
    
    // Skip comments
    if (empty($line) || $line[0] === '#') {
        continue;
    }
    
    // Parse KEY=VALUE
    if (strpos($line, '=') !== false) {
        list($key, $value) = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        
        // Remove quotes
        $value = trim($value, '"\'');
        
        $envVars[$key] = $value;
    }
}

$dbHost = $envVars['DB_HOST'] ?? 'localhost';
$dbPort = $envVars['DB_PORT'] ?? '3306';
$dbName = $envVars['DB_DATABASE'] ?? 'ikabud_kernel';
$dbUser = $envVars['DB_USERNAME'] ?? '';
$dbPass = $envVars['DB_PASSWORD'] ?? '';

if (empty($dbUser)) {
    echo "✗ Error: Database credentials not configured\n";
    exit(1);
}

echo "Database: $dbName\n";
echo "Host: $dbHost:$dbPort\n";
echo "Output: $outputFile\n\n";

try {
    // Connect to database
    $dsn = "mysql:host=$dbHost;port=$dbPort;dbname=$dbName;charset=utf8mb4";
    $pdo = new PDO($dsn, $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "✓ Connected to database\n";
    
    $dumpContent = "-- Ikabud Kernel Basic Database Dump\n";
    $dumpContent .= "-- Created: " . date('Y-m-d H:i:s') . "\n";
    $dumpContent .= "-- Database: $dbName\n";
    $dumpContent .= "-- Contains: Complete schema + User data only\n";
    $dumpContent .= "--\n";
    $dumpContent .= "-- This dump includes:\n";
    $dumpContent .= "--   - All table structures\n";
    $dumpContent .= "--   - User data (for initial admin setup)\n";
    $dumpContent .= "--\n";
    $dumpContent .= "-- This dump excludes:\n";
    $dumpContent .= "--   - Instance data\n";
    $dumpContent .= "--   - Process data\n";
    $dumpContent .= "--   - Cache data\n";
    $dumpContent .= "--   - Log data\n\n";
    
    $dumpContent .= "SET FOREIGN_KEY_CHECKS=0;\n";
    $dumpContent .= "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n";
    $dumpContent .= "SET time_zone = '+00:00';\n\n";
    
    // Get all tables
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    
    echo "✓ Found " . count($tables) . " tables\n\n";
    
    foreach ($tables as $table) {
        echo "  Processing: $table\n";
        
        // Get CREATE TABLE statement
        $createStmt = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_ASSOC);
        $dumpContent .= "-- --------------------------------------------------------\n";
        $dumpContent .= "-- Table structure for table `$table`\n";
        $dumpContent .= "-- --------------------------------------------------------\n\n";
        $dumpContent .= "DROP TABLE IF EXISTS `$table`;\n";
        $dumpContent .= $createStmt['Create Table'] . ";\n\n";
        
        // Only export data for users table
        if ($table === 'users') {
            $rows = $pdo->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
            
            if (!empty($rows)) {
                $dumpContent .= "-- --------------------------------------------------------\n";
                $dumpContent .= "-- Dumping data for table `$table`\n";
                $dumpContent .= "-- --------------------------------------------------------\n\n";
                
                foreach ($rows as $row) {
                    $columns = array_keys($row);
                    $values = array_values($row);
                    
                    // Escape values
                    $escapedValues = array_map(function($val) use ($pdo) {
                        return $val === null ? 'NULL' : $pdo->quote($val);
                    }, $values);
                    
                    $dumpContent .= "INSERT INTO `$table` (`" . implode('`, `', $columns) . "`) VALUES (";
                    $dumpContent .= implode(', ', $escapedValues);
                    $dumpContent .= ");\n";
                }
                
                $dumpContent .= "\n";
                echo "    ✓ Exported " . count($rows) . " user(s)\n";
            }
        } else {
            echo "    ℹ Structure only (no data)\n";
        }
    }
    
    $dumpContent .= "SET FOREIGN_KEY_CHECKS=1;\n";
    $dumpContent .= "\n-- End of dump\n";
    
    // Ensure output directory exists
    $outputDir = dirname($outputFile);
    if (!file_exists($outputDir)) {
        mkdir($outputDir, 0755, true);
    }
    
    // Write to file
    file_put_contents($outputFile, $dumpContent);
    
    $fileSize = filesize($outputFile);
    $fileSizeKB = round($fileSize / 1024, 2);
    
    echo "\n";
    echo "╔════════════════════════════════════════════════════════════════╗\n";
    echo "║                                                                ║\n";
    echo "║              Database Export Complete!                         ║\n";
    echo "║                                                                ║\n";
    echo "╚════════════════════════════════════════════════════════════════╝\n";
    echo "\n";
    echo "Output file: $outputFile\n";
    echo "File size: $fileSizeKB KB\n";
    echo "Tables: " . count($tables) . "\n";
    echo "\n";
    echo "✓ Ready to use for distribution!\n";
    echo "\n";
    
} catch (PDOException $e) {
    echo "✗ Database error: " . $e->getMessage() . "\n";
    exit(1);
}
