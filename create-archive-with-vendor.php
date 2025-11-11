#!/usr/bin/env php
<?php
/**
 * Ikabud Kernel - Complete Archive Creator (With Vendor)
 * 
 * Creates a distributable ZIP archive with ALL files including vendor dependencies
 * Perfect for shared hosting where composer is not available
 * 
 * Usage:
 *   php create-archive-with-vendor.php [output-filename]
 * 
 * Example:
 *   php create-archive-with-vendor.php ikabud-kernel-shared-hosting-v1.0.0.zip
 */

// Configuration
$sourceDir = dirname(__FILE__);
$outputFile = $argv[1] ?? 'ikabud-kernel-shared-hosting-' . date('Y-m-d') . '.zip';

// Ensure output file has .zip extension
if (!preg_match('/\.zip$/i', $outputFile)) {
    $outputFile .= '.zip';
}

// Full path for output file
$outputPath = $sourceDir . '/' . $outputFile;

// Files and directories to exclude
$excludePatterns = [
    // Runtime directories (but NOT shared-cores or vendor)
    'instances/*',
    'instances',
    'storage/cache/*',
    'storage/logs/*',
    'logs/*',
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
    'create-archive-with-vendor.php',
    
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
 * Main execution
 */
echo "\n";
echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║                                                                ║\n";
echo "║    Ikabud Kernel - Shared Hosting Archive Creator             ║\n";
echo "║    (Includes Vendor Dependencies)                              ║\n";
echo "║                                                                ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n";
echo "\n";

echo "Creating archive: $outputFile\n";
echo "Source directory: $sourceDir\n";
echo "\n";

// Check if vendor directory exists
$vendorDir = $sourceDir . '/vendor';
if (!is_dir($vendorDir)) {
    echo "⚠ WARNING: vendor/ directory not found!\n";
    echo "  Please run 'composer install' first to include dependencies.\n";
    echo "  Continue anyway? (yes/no): ";
    
    $answer = trim(fgets(STDIN));
    if (strtolower($answer) !== 'yes') {
        echo "Installation cancelled.\n";
        exit(1);
    }
}

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

// Add directories (INCLUDING vendor)
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
    'vendor',
    'admin/dist',
];

foreach ($directories as $dir) {
    $dirPath = $sourceDir . '/' . $dir;
    if (is_dir($dirPath)) {
        echo "  ▶ Processing: $dir/\n";
        addFilesToZip($zip, $dirPath, $sourceDir, $excludePatterns, $fileCount, $dirCount);
    } else {
        echo "  ⚠ Skipping: $dir/ (not found)\n";
    }
}

// Add empty directories with .gitkeep
foreach ($keepEmptyDirs as $dir) {
    $zip->addEmptyDir($dir);
    $zip->addFromString($dir . '/.gitkeep', '');
    $dirCount++;
    echo "  ✓ Created empty: $dir/\n";
}

// Add archive comment
$comment = "Ikabud Kernel Shared Hosting Distribution Archive\n";
$comment .= "Version: 1.0.0\n";
$comment .= "Created: " . date('Y-m-d H:i:s') . "\n";
$comment .= "Files: $fileCount\n";
$comment .= "Directories: $dirCount\n";
$comment .= "\n";
$comment .= "This archive includes vendor dependencies.\n";
$comment .= "No composer install required!\n";
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
echo "✅ SHARED HOSTING READY!\n";
echo "\n";
echo "Included:\n";
echo "  ✓ vendor/ directory (Composer dependencies)\n";
echo "  ✓ shared-cores/ directory (CMS cores)\n";
echo "  ✓ public/admin/ (Built admin UI)\n";
echo "  ✓ database/basic-data.sql (schema + user data)\n";
echo "\n";
echo "Excluded:\n";
echo "  - instances/ directory (runtime data)\n";
echo "  - node_modules/ directory\n";
echo "  - .env files (security)\n";
echo "  - Log files\n";
echo "  - Cache files\n";
echo "  - IDE files\n";
echo "\n";
echo "Installation on Shared Hosting:\n";
echo "  1. Upload and extract the ZIP file\n";
echo "  2. Copy .env.example to .env and configure\n";
echo "  3. Import database/basic-data.sql via phpMyAdmin\n";
echo "  4. Set permissions (chmod 775 storage instances logs themes)\n";
echo "  5. Access via web browser: http://yourdomain.com/install.php\n";
echo "\n";
echo "✓ No composer required! Ready to deploy!\n";
echo "\n";
