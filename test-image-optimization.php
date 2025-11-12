<?php
/**
 * Test Image Optimization - NO CMS Required
 * 
 * This script proves the kernel optimizes images independently
 */

require_once __DIR__ . '/kernel/Kernel.php';
require_once __DIR__ . '/kernel/Cache.php';
require_once __DIR__ . '/kernel/ImageOptimizer.php';
require_once __DIR__ . '/kernel/SyscallHandlers.php';
require_once __DIR__ . '/kernel/TransactionManager.php';
require_once __DIR__ . '/kernel/SecurityManager.php';
require_once __DIR__ . '/kernel/HealthMonitor.php';
require_once __DIR__ . '/kernel/ResourceManager.php';

use IkabudKernel\Core\Kernel;

echo "=== Ikabud Kernel Image Optimization Test ===\n";
echo "Testing WITHOUT loading any CMS...\n\n";

// Boot kernel (does NOT load WordPress/Drupal/Joomla)
try {
    Kernel::boot();
    echo "✓ Kernel booted successfully\n";
} catch (Exception $e) {
    echo "✗ Kernel boot failed: " . $e->getMessage() . "\n";
    exit(1);
}

// Find a test image from any instance
$testImage = null;
$instanceDirs = glob(__DIR__ . '/instances/*/wp-content/uploads/**/*.{jpg,jpeg,png}', GLOB_BRACE);

if (empty($instanceDirs)) {
    // Try Drupal
    $instanceDirs = glob(__DIR__ . '/instances/*/sites/default/files/**/*.{jpg,jpeg,png}', GLOB_BRACE);
}

if (!empty($instanceDirs)) {
    $testImage = $instanceDirs[0];
    echo "✓ Found test image: " . basename($testImage) . "\n";
} else {
    echo "✗ No test images found in instances\n";
    echo "Creating a test image...\n";
    
    // Create a simple test image
    $testImage = __DIR__ . '/storage/test-image.jpg';
    $img = imagecreatetruecolor(1920, 1080);
    $color = imagecolorallocate($img, 100, 150, 200);
    imagefilledrectangle($img, 0, 0, 1920, 1080, $color);
    imagejpeg($img, $testImage, 100); // Save at 100% quality (large file)
    imagedestroy($img);
    echo "✓ Created test image: test-image.jpg\n";
}

echo "\n--- Original Image ---\n";
echo "Path: {$testImage}\n";
echo "Size: " . round(filesize($testImage) / 1024, 2) . " KB\n";

$imageInfo = getimagesize($testImage);
echo "Dimensions: {$imageInfo[0]}x{$imageInfo[1]}\n";
echo "Type: {$imageInfo['mime']}\n";

echo "\n--- Optimizing via Kernel Syscall ---\n";
echo "NOTE: WordPress/Drupal NOT loaded!\n\n";

try {
    $startTime = microtime(true);
    
    // Call kernel syscall (NO CMS involved)
    $result = Kernel::syscall('image.optimize', [
        'path' => $testImage,
        'options' => ['width' => 1200]
    ]);
    
    $duration = (microtime(true) - $startTime) * 1000;
    
    echo "✓ Optimization complete in " . round($duration, 2) . "ms\n\n";
    
    echo "--- Results ---\n";
    echo "Optimized Path: {$result['path']}\n";
    echo "Original Size: " . round($result['original_size'] / 1024, 2) . " KB\n";
    echo "Optimized Size: " . round($result['optimized_size'] / 1024, 2) . " KB\n";
    echo "Savings: " . round($result['savings'] / 1024, 2) . " KB ({$result['savings_percent']}%)\n";
    
    if ($result['webp']) {
        $webpSize = filesize($result['webp']);
        echo "\nWebP Version: {$result['webp']}\n";
        echo "WebP Size: " . round($webpSize / 1024, 2) . " KB\n";
        echo "WebP Savings: " . round((($result['original_size'] - $webpSize) / $result['original_size']) * 100, 2) . "%\n";
    }
    
    if ($result['avif']) {
        $avifSize = filesize($result['avif']);
        echo "\nAVIF Version: {$result['avif']}\n";
        echo "AVIF Size: " . round($avifSize / 1024, 2) . " KB\n";
        echo "AVIF Savings: " . round((($result['original_size'] - $avifSize) / $result['original_size']) * 100, 2) . "%\n";
    }
    
    echo "\n--- Verification ---\n";
    echo "Optimized file exists: " . (file_exists($result['path']) ? '✓ YES' : '✗ NO') . "\n";
    echo "WebP file exists: " . (file_exists($result['webp']) ? '✓ YES' : '✗ NO') . "\n";
    
    echo "\n--- Test Responsive Generation ---\n";
    $responsive = Kernel::syscall('image.responsive', [
        'path' => $testImage
    ]);
    
    echo "Generated " . count($responsive) . " responsive variants:\n";
    foreach ($responsive as $width => $variant) {
        echo "  {$width}px: " . round($variant['optimized_size'] / 1024, 2) . " KB ({$variant['savings_percent']}% savings)\n";
    }
    
    echo "\n=== SUCCESS ===\n";
    echo "Image optimization works WITHOUT loading any CMS!\n";
    echo "Kernel operates independently at the syscall layer.\n";
    
} catch (Exception $e) {
    echo "✗ Optimization failed: " . $e->getMessage() . "\n";
    exit(1);
}
