<?php

declare(strict_types=1);

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'cmsnew.test';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/admin/bakeshop/settings';

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../modules/bakeshop/helpers.php';
require_once __DIR__ . '/../modules/bakeshop/handlers.php';

$pass = 0;
$fail = 0;
$errors = [];

function btLogo(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $errors;

    if ($ok) {
        $pass++;
        echo "  ✓ {$label}\n";
        return;
    }

    $fail++;
    $errors[] = $label . ($detail !== '' ? ': ' . $detail : '');
    echo "  ✗ {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

@file_put_contents(STORAGE_PATH . '/logs/app.log', '');
@file_put_contents(STORAGE_PATH . '/logs/error.log', '');

echo "\n=== BAKESHOP STORE LOGO UPLOAD TEST ===\n\n";

$tmpPath = tempnam(sys_get_temp_dir(), 'bakeshop-logo-');
$secondTmpPath = tempnam(sys_get_temp_dir(), 'bakeshop-logo-');
$quotaTmpPath = tempnam(sys_get_temp_dir(), 'bakeshop-logo-');
$uploadedAbsolutePath = '';
$quotaFillerPath = '';
$sourceWidth = 0;
$sourceHeight = 0;
$originalRemoteAddr = $_SERVER['REMOTE_ADDR'] ?? null;

try {
    if ($tmpPath === false) {
        throw new RuntimeException('Unable to create the temporary logo test file.');
    }
    if ($secondTmpPath === false || $quotaTmpPath === false) {
        throw new RuntimeException('Unable to create the temporary logo test file.');
    }

    if (extension_loaded('gd')) {
        $sourceWidth = 1600;
        $sourceHeight = 640;
        $image = imagecreatetruecolor($sourceWidth, $sourceHeight);
        $background = imagecolorallocate($image, 255, 255, 255);
        $accent = imagecolorallocate($image, 37, 99, 235);
        imagefilledrectangle($image, 0, 0, $sourceWidth, $sourceHeight, $background);
        imagefilledrectangle($image, 80, 120, 1520, 520, $accent);
        imagepng($image, $tmpPath);
        kernelImageDestroy($image);
    } else {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9s1nXKQAAAAASUVORK5CYII=');
        if ($png === false) {
            throw new RuntimeException('Unable to create the temporary logo test file.');
        }
        file_put_contents($tmpPath, $png);
        file_put_contents($secondTmpPath, $png);
        file_put_contents($quotaTmpPath, $png);
        $sourceWidth = 1;
        $sourceHeight = 1;
    }

    if (extension_loaded('gd')) {
        copy($tmpPath, $secondTmpPath);
        copy($tmpPath, $quotaTmpPath);
    }

    $_SERVER['REMOTE_ADDR'] = '203.0.113.' . (string)random_int(10, 200);

    try {
        bakeshopStoreLogoUpload([]);
        btLogo('failed upload does not consume the rate limit window', false, 'Expected InvalidArgumentException.');
    } catch (InvalidArgumentException $e) {
        btLogo('failed upload returns the expected validation error', str_contains(strtolower($e->getMessage()), 'upload a logo image first'), $e->getMessage());
    }

    $result = bakeshopStoreLogoUpload([
        'name' => 'store-logo.png',
        'tmp_name' => $tmpPath,
        'error' => UPLOAD_ERR_OK,
        'size' => (int)filesize($tmpPath),
    ]);

    btLogo('failed upload does not consume the rate limit window', ($result['store_logo_url'] ?? '') !== '', json_encode($result, JSON_UNESCAPED_SLASHES));

    $uploadedAbsolutePath = (string)($result['absolute_path'] ?? '');
    btLogo('logo upload returns public url', ($result['store_logo_url'] ?? '') !== '', json_encode($result, JSON_UNESCAPED_SLASHES));
    btLogo('logo upload writes file to disk', $uploadedAbsolutePath !== '' && is_file($uploadedAbsolutePath), $uploadedAbsolutePath);
    btLogo('logo upload stores an image mime type', str_starts_with((string)($result['mime_type'] ?? ''), 'image/'), json_encode($result, JSON_UNESCAPED_SLASHES));
    btLogo('logo upload path is branding-scoped', str_contains((string)($result['relative_path'] ?? ''), 'branding/'), (string)($result['relative_path'] ?? ''));
    btLogo('logo upload reports stored dimensions', (int)($result['width'] ?? 0) > 0 && (int)($result['height'] ?? 0) > 0, json_encode($result, JSON_UNESCAPED_SLASHES));
    $rateLimitAfterUpload = bakeshopStoreLogoUploadRateLimitState();
    btLogo('successful logo upload records the rate limit window', !empty($rateLimitAfterUpload['limited']), json_encode($rateLimitAfterUpload, JSON_UNESCAPED_SLASHES));
    if (extension_loaded('gd')) {
        btLogo('logo upload normalizes oversized raster images', (int)($result['width'] ?? 0) <= bakeshopStoreLogoMaxDimension() && (int)($result['height'] ?? 0) <= bakeshopStoreLogoMaxDimension() && !empty($result['normalized']), json_encode($result, JSON_UNESCAPED_SLASHES));
    } else {
        btLogo('logo upload preserves small dimensions without GD', (int)($result['width'] ?? 0) === $sourceWidth && (int)($result['height'] ?? 0) === $sourceHeight, json_encode($result, JSON_UNESCAPED_SLASHES));
    }

    try {
        bakeshopStoreLogoUpload([
            'name' => 'store-logo-second.png',
            'tmp_name' => $secondTmpPath,
            'error' => UPLOAD_ERR_OK,
            'size' => (int)filesize($secondTmpPath),
        ]);
        btLogo('logo upload rate limit rejects rapid second upload', false, 'Expected InvalidArgumentException.');
    } catch (InvalidArgumentException $e) {
        btLogo('logo upload rate limit rejects rapid second upload', str_contains(strtolower($e->getMessage()), 'one change per minute'), $e->getMessage());
    }

    $_SERVER['REMOTE_ADDR'] = '203.0.113.' . (string)random_int(201, 240);
    $quotaRoots = bakeshopStoreLogoStorageRoots();
    $quotaBase = $quotaRoots[0] ?? '';
    if ($quotaBase === '') {
        throw new RuntimeException('Unable to resolve the logo storage path for quota testing.');
    }
    $quotaFillerPath = rtrim($quotaBase, '/') . '/quota-test.bin';
    kernelEnsureDirectory(dirname($quotaFillerPath));
    $currentUsage = bakeshopStoreLogoCurrentUsageBytes();
    $quotaBytes = bakeshopStoreLogoTenantQuotaBytes();
    $fillerBytes = max(1, $quotaBytes - $currentUsage);
    $quotaHandle = fopen($quotaFillerPath, 'c+');
    if ($quotaHandle === false) {
        throw new RuntimeException('Unable to prepare quota filler file.');
    }
    ftruncate($quotaHandle, $fillerBytes);
    fclose($quotaHandle);

    try {
        bakeshopStoreLogoUpload([
            'name' => 'store-logo-quota.png',
            'tmp_name' => $quotaTmpPath,
            'error' => UPLOAD_ERR_OK,
            'size' => (int)filesize($quotaTmpPath),
        ]);
        btLogo('logo upload quota rejects oversized tenant usage', false, 'Expected InvalidArgumentException.');
    } catch (InvalidArgumentException $e) {
        btLogo('logo upload quota rejects oversized tenant usage', str_contains(strtolower($e->getMessage()), 'quota'), $e->getMessage());
    }
} finally {
    if ($tmpPath !== false && is_file($tmpPath)) {
        @unlink($tmpPath);
    }
    if ($secondTmpPath !== false && is_file($secondTmpPath)) {
        @unlink($secondTmpPath);
    }
    if ($quotaTmpPath !== false && is_file($quotaTmpPath)) {
        @unlink($quotaTmpPath);
    }
    if ($uploadedAbsolutePath !== '' && is_file($uploadedAbsolutePath)) {
        @unlink($uploadedAbsolutePath);
    }
    if ($quotaFillerPath !== '' && is_file($quotaFillerPath)) {
        @unlink($quotaFillerPath);
    }
    if ($originalRemoteAddr !== null) {
        $_SERVER['REMOTE_ADDR'] = $originalRemoteAddr;
    } else {
        unset($_SERVER['REMOTE_ADDR']);
    }
}

$appLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/app.log'));
$errorLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/error.log'));
btLogo('no app.log errors', $appLog === '' || !str_contains(strtolower($appLog), 'error'), $appLog);
btLogo('no error.log errors', $errorLog === '', $errorLog);

echo "\n" . str_repeat('─', 50) . "\n";
echo "  Result: {$pass} passed, {$fail} failed\n";
if ($errors !== []) {
    echo "\n  Failures:\n";
    foreach ($errors as $error) {
        echo "    • {$error}\n";
    }
}
echo "\n";

exit($fail > 0 ? 1 : 0);
