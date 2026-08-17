<?php

declare(strict_types=1);

function cmsMediaIsEditableImageMime(string $mime): bool
{
    return in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true);
}

function cmsMediaLoadImageResource(string $absolutePath, string $mime)
{
    switch ($mime) {
        case 'image/jpeg': return @imagecreatefromjpeg($absolutePath);
        case 'image/png': return @imagecreatefrompng($absolutePath);
        case 'image/gif': return @imagecreatefromgif($absolutePath);
        case 'image/webp': return @imagecreatefromwebp($absolutePath);
        default: return null;
    }
}

function cmsMediaSaveImageResource($image, string $absolutePath, string $mime): bool
{
    switch ($mime) {
        case 'image/jpeg': return (bool)@imagejpeg($image, $absolutePath, 88);
        case 'image/png': return (bool)@imagepng($image, $absolutePath, 6);
        case 'image/gif': return (bool)@imagegif($image, $absolutePath);
        case 'image/webp': return (bool)@imagewebp($image, $absolutePath, 88);
        default: return false;
    }
}

function cmsMediaCreateCanvas(int $width, int $height, string $mime)
{
    $canvas = imagecreatetruecolor($width, $height);
    if (in_array($mime, ['image/png', 'image/gif', 'image/webp'], true)) {
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
        imagefilledrectangle($canvas, 0, 0, $width, $height, $transparent);
    }
    return $canvas;
}

function cmsMediaFlipImageResource($image, int $mode): bool
{
    if (function_exists('imageflip')) {
        return (bool)imageflip($image, $mode);
    }

    $width = imagesx($image);
    $height = imagesy($image);
    $flipped = imagecreatetruecolor($width, $height);
    imagealphablending($flipped, false);
    imagesavealpha($flipped, true);
    $transparent = imagecolorallocatealpha($flipped, 0, 0, 0, 127);
    imagefilledrectangle($flipped, 0, 0, $width, $height, $transparent);

    if ($mode === (defined('IMG_FLIP_VERTICAL') ? IMG_FLIP_VERTICAL : 2)) {
        for ($y = 0; $y < $height; $y++) {
            imagecopy($flipped, $image, 0, $height - $y - 1, 0, $y, $width, 1);
        }
    } else {
        for ($x = 0; $x < $width; $x++) {
            imagecopy($flipped, $image, $width - $x - 1, 0, $x, 0, 1, $height);
        }
    }

    imagecopy($image, $flipped, 0, 0, 0, 0, $width, $height);
    kernelImageDestroy($flipped);
    return true;
}

function cmsMediaApplyImageEdit(string $absolutePath, string $mime, string $operation, array $options = []): bool
{
    if (!extension_loaded('gd') || !cmsMediaIsEditableImageMime($mime)) {
        return false;
    }

    $source = cmsMediaLoadImageResource($absolutePath, $mime);
    if (!$source) {
        return false;
    }

    $result = $source;
    $success = true;

    if ($operation === 'rotate_left' || $operation === 'rotate_right') {
        $degrees = $operation === 'rotate_left' ? 90 : -90;
        $bg = imagecolorallocatealpha($source, 0, 0, 0, 127);
        $rotated = imagerotate($source, $degrees, $bg);
        if (!$rotated) {
            kernelImageDestroy($source);
            return false;
        }
        imagealphablending($rotated, false);
        imagesavealpha($rotated, true);
        $result = $rotated;
    } elseif ($operation === 'flip_horizontal') {
        $mode = defined('IMG_FLIP_HORIZONTAL') ? IMG_FLIP_HORIZONTAL : 1;
        $success = cmsMediaFlipImageResource($source, $mode);
    } elseif ($operation === 'flip_vertical') {
        $mode = defined('IMG_FLIP_VERTICAL') ? IMG_FLIP_VERTICAL : 2;
        $success = cmsMediaFlipImageResource($source, $mode);
    } elseif ($operation === 'resize') {
        $targetWidth = max(1, (int)($options['width'] ?? 0));
        $targetHeight = max(1, (int)($options['height'] ?? 0));
        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        if ($targetWidth <= 0 || $targetHeight <= 0 || $sourceWidth <= 0 || $sourceHeight <= 0) {
            kernelImageDestroy($source);
            return false;
        }
        $resized = cmsMediaCreateCanvas($targetWidth, $targetHeight, $mime);
        $success = imagecopyresampled($resized, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $sourceWidth, $sourceHeight);
        if (!$success) {
            kernelImageDestroy($resized);
            kernelImageDestroy($source);
            return false;
        }
        $result = $resized;
    } elseif ($operation === 'crop') {
        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        $cropX = max(0, (int)($options['x'] ?? 0));
        $cropY = max(0, (int)($options['y'] ?? 0));
        $cropWidth = max(1, (int)($options['width'] ?? 0));
        $cropHeight = max(1, (int)($options['height'] ?? 0));
        $outputWidth = max(0, (int)($options['output_width'] ?? 0));
        $outputHeight = max(0, (int)($options['output_height'] ?? 0));

        if ($sourceWidth <= 0 || $sourceHeight <= 0 || $cropWidth <= 0 || $cropHeight <= 0) {
            kernelImageDestroy($source);
            return false;
        }

        if ($cropX >= $sourceWidth || $cropY >= $sourceHeight) {
            kernelImageDestroy($source);
            return false;
        }

        $cropWidth = min($cropWidth, $sourceWidth - $cropX);
        $cropHeight = min($cropHeight, $sourceHeight - $cropY);
        if ($cropWidth <= 0 || $cropHeight <= 0) {
            kernelImageDestroy($source);
            return false;
        }

        $cropped = cmsMediaCreateCanvas($cropWidth, $cropHeight, $mime);
        $success = imagecopy($cropped, $source, 0, 0, $cropX, $cropY, $cropWidth, $cropHeight);
        if (!$success) {
            kernelImageDestroy($cropped);
            kernelImageDestroy($source);
            return false;
        }

        if ($outputWidth > 0 && $outputHeight > 0 && ($outputWidth !== $cropWidth || $outputHeight !== $cropHeight)) {
            $resampled = cmsMediaCreateCanvas($outputWidth, $outputHeight, $mime);
            $success = imagecopyresampled($resampled, $cropped, 0, 0, 0, 0, $outputWidth, $outputHeight, $cropWidth, $cropHeight);
            kernelImageDestroy($cropped);
            if (!$success) {
                kernelImageDestroy($resampled);
                kernelImageDestroy($source);
                return false;
            }
            $cropped = $resampled;
        }

        $result = $cropped;
    } else {
        kernelImageDestroy($source);
        return false;
    }

    if (!$success) {
        kernelImageDestroy($source);
        return false;
    }

    $saved = cmsMediaSaveImageResource($result, $absolutePath, $mime);

    if ($result !== $source) {
        kernelImageDestroy($result);
    }
    kernelImageDestroy($source);

    return $saved;
}

// ── Theme Resolution ──────────────────────────────────────────────
//
// Theme = directory of DiSyL template overrides in storage/cms-themes/{slug}/
// Resolution order:
//   1. storage/cms-themes/{active_theme}/{template}.disyl
//   2. templates/modules/cms/{template}.disyl  (default)
//
// Only PUBLIC templates are themeable. Admin templates are never overridden.

/**
 * Get the filesystem path to the CMS themes directory.
 */
