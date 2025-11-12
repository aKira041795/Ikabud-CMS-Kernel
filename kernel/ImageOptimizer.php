<?php
/**
 * Image Optimizer
 * 
 * Handles automatic image optimization, format conversion, and responsive images
 */

namespace IkabudKernel\Core;

use Exception;

class ImageOptimizer
{
    private string $cacheDir;
    private array $config;
    private array $stats = [
        'optimized' => 0,
        'bytes_saved' => 0,
        'cache_hits' => 0
    ];
    
    // Supported formats
    private const FORMATS = ['jpeg', 'jpg', 'png', 'gif', 'webp', 'avif'];
    
    // Default quality settings
    private const DEFAULT_CONFIG = [
        'jpeg_quality' => 85,
        'png_compression' => 9,
        'webp_quality' => 85,
        'avif_quality' => 80,
        'enable_webp' => true,
        'enable_avif' => false, // Requires PHP 8.1+ with AVIF support
        'max_width' => 2560,
        'max_height' => 2560,
        'responsive_sizes' => [320, 640, 768, 1024, 1280, 1920],
        'lazy_load' => true
    ];
    
    public function __construct(array $config = [])
    {
        $this->config = array_merge(self::DEFAULT_CONFIG, $config);
        $this->cacheDir = dirname(__DIR__) . '/storage/images';
        
        // Ensure cache directory exists
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }
    }
    
    /**
     * Optimize image and return optimized path
     */
    public function optimize(string $imagePath, array $options = []): array
    {
        if (!file_exists($imagePath)) {
            throw new Exception("Image not found: {$imagePath}");
        }
        
        $imageInfo = getimagesize($imagePath);
        if ($imageInfo === false) {
            throw new Exception("Invalid image: {$imagePath}");
        }
        
        $originalSize = filesize($imagePath);
        $mimeType = $imageInfo['mime'];
        $format = $this->getFormatFromMime($mimeType);
        
        // Generate cache key
        $cacheKey = $this->getCacheKey($imagePath, $options);
        $cachedPath = $this->getCachedPath($cacheKey, $format);
        
        // Return cached if exists
        if (file_exists($cachedPath)) {
            $this->stats['cache_hits']++;
            return $this->buildResponse($cachedPath, $originalSize);
        }
        
        // Load image
        $image = $this->loadImage($imagePath, $format);
        if (!$image) {
            throw new Exception("Failed to load image: {$imagePath}");
        }
        
        // Resize if needed
        if ($options['width'] ?? null) {
            $image = $this->resize($image, $options['width'], $options['height'] ?? null);
        } elseif ($imageInfo[0] > $this->config['max_width'] || $imageInfo[1] > $this->config['max_height']) {
            $image = $this->resize($image, $this->config['max_width'], $this->config['max_height']);
        }
        
        // Save optimized image
        $optimizedPath = $this->saveOptimized($image, $cachedPath, $format);
        imagedestroy($image);
        
        // Generate WebP version if enabled
        $webpPath = null;
        if ($this->config['enable_webp'] && function_exists('imagewebp')) {
            $webpPath = $this->generateWebP($imagePath, $cacheKey);
        }
        
        // Generate AVIF version if enabled
        $avifPath = null;
        if ($this->config['enable_avif'] && function_exists('imageavif')) {
            $avifPath = $this->generateAVIF($imagePath, $cacheKey);
        }
        
        // Update stats
        $optimizedSize = filesize($optimizedPath);
        $this->stats['optimized']++;
        $this->stats['bytes_saved'] += ($originalSize - $optimizedSize);
        
        return $this->buildResponse($optimizedPath, $originalSize, $webpPath, $avifPath);
    }
    
    /**
     * Generate responsive image set
     */
    public function generateResponsive(string $imagePath): array
    {
        $variants = [];
        
        foreach ($this->config['responsive_sizes'] as $width) {
            try {
                $variants[$width] = $this->optimize($imagePath, ['width' => $width]);
            } catch (Exception $e) {
                error_log("Failed to generate {$width}px variant: " . $e->getMessage());
            }
        }
        
        return $variants;
    }
    
    /**
     * Generate HTML picture element with responsive images
     */
    public function generatePictureTag(string $imagePath, string $alt = '', array $attributes = []): string
    {
        $variants = $this->generateResponsive($imagePath);
        $original = $this->optimize($imagePath);
        
        $html = '<picture>';
        
        // Add WebP sources if available
        if (!empty($original['webp'])) {
            $html .= '<source type="image/webp" srcset="';
            $srcset = [];
            foreach ($variants as $width => $variant) {
                if (!empty($variant['webp'])) {
                    $srcset[] = $variant['webp'] . " {$width}w";
                }
            }
            $html .= implode(', ', $srcset) . '">';
        }
        
        // Add AVIF sources if available
        if (!empty($original['avif'])) {
            $html .= '<source type="image/avif" srcset="';
            $srcset = [];
            foreach ($variants as $width => $variant) {
                if (!empty($variant['avif'])) {
                    $srcset[] = $variant['avif'] . " {$width}w";
                }
            }
            $html .= implode(', ', $srcset) . '">';
        }
        
        // Add original format sources
        $html .= '<source srcset="';
        $srcset = [];
        foreach ($variants as $width => $variant) {
            $srcset[] = $variant['path'] . " {$width}w";
        }
        $html .= implode(', ', $srcset) . '">';
        
        // Fallback img tag
        $attrs = '';
        foreach ($attributes as $key => $value) {
            $attrs .= " {$key}=\"" . htmlspecialchars($value) . "\"";
        }
        
        $loading = $this->config['lazy_load'] ? ' loading="lazy"' : '';
        $html .= "<img src=\"{$original['path']}\" alt=\"" . htmlspecialchars($alt) . "\"{$loading}{$attrs}>";
        
        $html .= '</picture>';
        
        return $html;
    }
    
    /**
     * Load image from file
     */
    private function loadImage(string $path, string $format)
    {
        switch ($format) {
            case 'jpeg':
            case 'jpg':
                return imagecreatefromjpeg($path);
            case 'png':
                return imagecreatefrompng($path);
            case 'gif':
                return imagecreatefromgif($path);
            case 'webp':
                return imagecreatefromwebp($path);
            default:
                return false;
        }
    }
    
    /**
     * Resize image maintaining aspect ratio
     */
    private function resize($image, int $maxWidth, ?int $maxHeight = null)
    {
        $width = imagesx($image);
        $height = imagesy($image);
        
        if ($maxHeight === null) {
            $maxHeight = $maxWidth;
        }
        
        // Calculate new dimensions
        $ratio = min($maxWidth / $width, $maxHeight / $height);
        
        if ($ratio >= 1) {
            return $image; // No resize needed
        }
        
        $newWidth = (int)($width * $ratio);
        $newHeight = (int)($height * $ratio);
        
        // Create new image
        $resized = imagecreatetruecolor($newWidth, $newHeight);
        
        // Preserve transparency for PNG/GIF
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        
        // Resize
        imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        
        return $resized;
    }
    
    /**
     * Save optimized image
     */
    private function saveOptimized($image, string $path, string $format): string
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        
        switch ($format) {
            case 'jpeg':
            case 'jpg':
                imagejpeg($image, $path, $this->config['jpeg_quality']);
                break;
            case 'png':
                imagepng($image, $path, $this->config['png_compression']);
                break;
            case 'gif':
                imagegif($image, $path);
                break;
            case 'webp':
                imagewebp($image, $path, $this->config['webp_quality']);
                break;
        }
        
        return $path;
    }
    
    /**
     * Generate WebP version
     */
    private function generateWebP(string $originalPath, string $cacheKey): ?string
    {
        $webpPath = $this->getCachedPath($cacheKey, 'webp');
        
        if (file_exists($webpPath)) {
            return $webpPath;
        }
        
        $imageInfo = getimagesize($originalPath);
        $format = $this->getFormatFromMime($imageInfo['mime']);
        $image = $this->loadImage($originalPath, $format);
        
        if (!$image) {
            return null;
        }
        
        $dir = dirname($webpPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        
        imagewebp($image, $webpPath, $this->config['webp_quality']);
        imagedestroy($image);
        
        return $webpPath;
    }
    
    /**
     * Generate AVIF version
     */
    private function generateAVIF(string $originalPath, string $cacheKey): ?string
    {
        if (!function_exists('imageavif')) {
            return null;
        }
        
        $avifPath = $this->getCachedPath($cacheKey, 'avif');
        
        if (file_exists($avifPath)) {
            return $avifPath;
        }
        
        $imageInfo = getimagesize($originalPath);
        $format = $this->getFormatFromMime($imageInfo['mime']);
        $image = $this->loadImage($originalPath, $format);
        
        if (!$image) {
            return null;
        }
        
        $dir = dirname($avifPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        
        imageavif($image, $avifPath, $this->config['avif_quality']);
        imagedestroy($image);
        
        return $avifPath;
    }
    
    /**
     * Get cache key for image
     */
    private function getCacheKey(string $path, array $options): string
    {
        $key = $path . '_' . filemtime($path);
        if (!empty($options)) {
            $key .= '_' . md5(json_encode($options));
        }
        return md5($key);
    }
    
    /**
     * Get cached file path
     */
    private function getCachedPath(string $cacheKey, string $format): string
    {
        $subdir = substr($cacheKey, 0, 2);
        return $this->cacheDir . '/' . $subdir . '/' . $cacheKey . '.' . $format;
    }
    
    /**
     * Get format from MIME type
     */
    private function getFormatFromMime(string $mime): string
    {
        $map = [
            'image/jpeg' => 'jpeg',
            'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/avif' => 'avif'
        ];
        
        return $map[$mime] ?? 'jpeg';
    }
    
    /**
     * Build response array
     */
    private function buildResponse(string $path, int $originalSize, ?string $webpPath = null, ?string $avifPath = null): array
    {
        $optimizedSize = filesize($path);
        
        return [
            'path' => $path,
            'webp' => $webpPath,
            'avif' => $avifPath,
            'original_size' => $originalSize,
            'optimized_size' => $optimizedSize,
            'savings' => $originalSize - $optimizedSize,
            'savings_percent' => round((($originalSize - $optimizedSize) / $originalSize) * 100, 2)
        ];
    }
    
    /**
     * Get optimization statistics
     */
    public function getStats(): array
    {
        return $this->stats;
    }
    
    /**
     * Clear image cache
     */
    public function clearCache(): int
    {
        $count = 0;
        $files = glob($this->cacheDir . '/*/*.{jpg,jpeg,png,gif,webp,avif}', GLOB_BRACE);
        
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
                $count++;
            }
        }
        
        return $count;
    }
}
