#!/usr/bin/env php
<?php
/**
 * Ikabud Kernel - Archive Creator
 * 
 * Creates a distributable ZIP archive with all necessary files
 * excluding instances and other runtime/temporary files
 * 
 * Usage:
 *   php create-archive.php [output-filename]
 * 
 * Example:
 *   php create-archive.php ikabud-kernel-1.0.0.zip
 */

// Configuration
$sourceDir = dirname(__FILE__);
$outputFile = $argv[1] ?? 'ikabud-kernel-' . date('Y-m-d') . '.zip';

// Ensure output file has .zip extension
if (!preg_match('/\.zip$/i', $outputFile)) {
    $outputFile .= '.zip';
}

// Full path for output file
$outputPath = $sourceDir . '/' . $outputFile;

// Files and directories to exclude
$excludePatterns = [
    // Runtime directories (but NOT shared-cores)
    'instances/*',
    'instances',
    'storage/cache/*',
    'storage/logs/*',
    'logs/*',
    'vendor/*',
    'node_modules/*',
    
    // Temporary files
    '*.tmp',
    '*.temp',
    '*.log',
    '*.swp',
    '*.swo',
    '*~',
    
    // IDE files
    '.vscode/*',
    '.idea/*',
    
    // Version control
    '.git/*',
    '.gitignore',
    
    // Environment files
    '.env',
    '.env.local',
    
    // Archives
    '*.zip',
    '*.tar.gz',
    '*.tar',
    
    // OS files
    '.DS_Store',
    'Thumbs.db',
    
    // This script itself
    'create-archive.php',
    
    // Release directory
    'releases/*',
];

// Directories to include (but keep empty)
$keepEmptyDirs = [
    'instances',
    'storage/cache',
    'storage/logs',
    'themes',
    'logs',
];

/**
 * Check if path should be excluded
 */
function shouldExclude($path, $excludePatterns) {
    $relativePath = str_replace(dirname(__FILE__) . '/', '', $path);
    
    foreach ($excludePatterns as $pattern) {
        // Convert glob pattern to regex
        $regex = str_replace(
            ['*', '?', '/'],
            ['.*', '.', '\/'],
            $pattern
        );
        $regex = '/^' . $regex . '$/';
        
        if (preg_match($regex, $relativePath)) {
            return true;
        }
        
        // Also check if parent directory matches
        $parts = explode('/', $relativePath);
        for ($i = 0; $i < count($parts); $i++) {
            $partial = implode('/', array_slice($parts, 0, $i + 1));
            if (preg_match($regex, $partial)) {
                return true;
            }
        }
    }
    
    return false;
}

/**
 * Recursively add files to ZIP
 */
function addFilesToZip($zip, $dir, $baseDir, $excludePatterns, &$fileCount, &$dirCount) {
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    
    foreach ($files as $file) {
        $filePath = $file->getRealPath();
        
        // Skip if getRealPath returns false (broken symlinks, etc)
        if ($filePath === false) {
            continue;
        }
        
        $relativePath = substr($filePath, strlen($baseDir) + 1);
        
        // Skip excluded files
        if (shouldExclude($filePath, $excludePatterns)) {
            continue;
        }
        
        // Skip empty paths
        if (empty($filePath) || empty($relativePath)) {
            continue;
        }
        
        if ($file->isDir()) {
            $zip->addEmptyDir($relativePath);
            $dirCount++;
        } else {
            $zip->addFile($filePath, $relativePath);
            $fileCount++;
        }
    }
}

/**
 * Create basic database dump with schema and user data
 */
function createBasicDatabaseDump($sourceDir) {
    // Load .env file if it exists
    $envFile = $sourceDir . '/.env';
    if (!file_exists($envFile)) {
        return false;
    }
    
    $envVars = parse_ini_file($envFile);
    if (!$envVars) {
        return false;
    }
    
    $dbHost = $envVars['DB_HOST'] ?? 'localhost';
    $dbPort = $envVars['DB_PORT'] ?? '3306';
    $dbName = $envVars['DB_DATABASE'] ?? 'ikabud_kernel';
    $dbUser = $envVars['DB_USERNAME'] ?? '';
    $dbPass = $envVars['DB_PASSWORD'] ?? '';
    
    if (empty($dbUser)) {
        return false;
    }
    
    try {
        // Connect to database
        $dsn = "mysql:host=$dbHost;port=$dbPort;dbname=$dbName;charset=utf8mb4";
        $pdo = new PDO($dsn, $dbUser, $dbPass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        $dumpContent = "-- Ikabud Kernel Basic Database Dump\n";
        $dumpContent .= "-- Created: " . date('Y-m-d H:i:s') . "\n";
        $dumpContent .= "-- Database: $dbName\n";
        $dumpContent .= "-- Contains: Schema + Basic User Data\n\n";
        
        $dumpContent .= "SET FOREIGN_KEY_CHECKS=0;\n\n";
        
        // Get all tables
        $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        
        foreach ($tables as $table) {
            // Get CREATE TABLE statement
            $createStmt = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_ASSOC);
            $dumpContent .= "-- Table: $table\n";
            $dumpContent .= "DROP TABLE IF EXISTS `$table`;\n";
            $dumpContent .= $createStmt['Create Table'] . ";\n\n";
            
            // Only export data for users table
            if ($table === 'users') {
                $rows = $pdo->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
                
                if (!empty($rows)) {
                    $dumpContent .= "-- Data for table: $table\n";
                    
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
                }
            }
        }
        
        $dumpContent .= "SET FOREIGN_KEY_CHECKS=1;\n";
        
        // Write to temp file
        $tempFile = sys_get_temp_dir() . '/ikabud-basic-dump-' . time() . '.sql';
        file_put_contents($tempFile, $dumpContent);
        
        return $tempFile;
        
    } catch (PDOException $e) {
        // Database not available, return false
        return false;
    }
}

/**
 * Main execution
 */
echo "\n";
echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║                                                                ║\n";
echo "║          Ikabud Kernel - Archive Creator                      ║\n";
echo "║                                                                ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n";
echo "\n";

echo "Creating archive: $outputFile\n";
echo "Source directory: $sourceDir\n";
echo "\n";

// Check if ZIP extension is available
if (!class_exists('ZipArchive')) {
    echo "✗ Error: ZIP extension not available\n";
    echo "  Please install php-zip extension\n";
    exit(1);
}

// Remove existing archive if it exists
if (file_exists($outputPath)) {
    echo "⚠ Removing existing archive...\n";
    unlink($outputPath);
}

// Create ZIP archive
$zip = new ZipArchive();
$result = $zip->open($outputPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

if ($result !== true) {
    echo "✗ Error: Could not create ZIP archive\n";
    exit(1);
}

echo "▶ Adding files to archive...\n";

$fileCount = 0;
$dirCount = 0;

// Add root files
$rootFiles = [
    'README.md',
    'INSTALL.md',
    'REQUIREMENTS.md',
    'QUICK_START.md',
    'CHANGELOG.md',
    'CONTRIBUTING.md',
    'LICENSE',
    'PACKAGE_INFO.md',
    'INSTALLATION_PACKAGE_SUMMARY.txt',
    'composer.json',
    'composer.lock',
    '.env.example',
    'ikabud',
    'install.sh',
    'install.php',
];

foreach ($rootFiles as $file) {
    $filePath = $sourceDir . '/' . $file;
    if (file_exists($filePath)) {
        $zip->addFile($filePath, $file);
        $fileCount++;
        echo "  ✓ Added: $file\n";
    }
}

// Add directories
$directories = [
    'api',
    'bin',
    'cms',
    'database',
    'docs',
    'dsl',
    'kernel',
    'public',
    'templates',
    'shared-cores',
    'admin/dist',
];

foreach ($directories as $dir) {
    $dirPath = $sourceDir . '/' . $dir;
    if (is_dir($dirPath)) {
        echo "  ▶ Processing: $dir/\n";
        addFilesToZip($zip, $dirPath, $sourceDir, $excludePatterns, $fileCount, $dirCount);
    }
}

// Add empty directories with .gitkeep
foreach ($keepEmptyDirs as $dir) {
    $zip->addEmptyDir($dir);
    $zip->addFromString($dir . '/.gitkeep', '');
    $dirCount++;
    echo "  ✓ Created empty: $dir/\n";
}

// Create basic database dump
echo "  ▶ Creating database dump...\n";
$dbDumpFile = createBasicDatabaseDump($sourceDir);
if ($dbDumpFile && file_exists($dbDumpFile)) {
    $zip->addFile($dbDumpFile, 'database/basic-data.sql');
    $fileCount++;
    echo "  ✓ Added: database/basic-data.sql\n";
    // Clean up temp file
    @unlink($dbDumpFile);
} else {
    echo "  ⚠ Could not create database dump (optional)\n";
}

// Add archive comment
$comment = "Ikabud Kernel Distribution Archive\n";
$comment .= "Version: 1.0.0\n";
$comment .= "Created: " . date('Y-m-d H:i:s') . "\n";
$comment .= "Files: $fileCount\n";
$comment .= "Directories: $dirCount\n";
$zip->setArchiveComment($comment);

// Close the archive
$zip->close();

// Get file size
$fileSize = filesize($outputPath);
$fileSizeMB = round($fileSize / 1024 / 1024, 2);

echo "\n";
echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║                                                                ║\n";
echo "║              Archive Created Successfully!                     ║\n";
echo "║                                                                ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n";
echo "\n";
echo "Archive Details:\n";
echo "  File: $outputFile\n";
echo "  Size: $fileSizeMB MB ($fileSize bytes)\n";
echo "  Files: $fileCount\n";
echo "  Directories: $dirCount\n";
echo "\n";
echo "Included:\n";
echo "  ✓ shared-cores/ directory (CMS cores)\n";
echo "  ✓ database/basic-data.sql (schema + user data)\n";
echo "\n";
echo "Excluded:\n";
echo "  - instances/ directory (runtime data)\n";
echo "  - vendor/ directory (install via composer)\n";
echo "  - node_modules/ directory\n";
echo "  - .env files (security)\n";
echo "  - Log files\n";
echo "  - Cache files\n";
echo "  - IDE files\n";
echo "\n";
echo "Next Steps:\n";
echo "  1. Test the archive by extracting it\n";
echo "  2. Run 'composer install' after extraction\n";
echo "  3. Copy .env.example to .env and configure\n";
echo "  4. Run install.php or install.sh\n";
echo "\n";
echo "✓ Archive ready for distribution!\n";
echo "\n";
