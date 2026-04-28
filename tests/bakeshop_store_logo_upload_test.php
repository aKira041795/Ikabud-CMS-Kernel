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
$uploadedAbsolutePath = '';
$sourceWidth = 0;
$sourceHeight = 0;

try {
    if ($tmpPath === false) {
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
        imagedestroy($image);
    } else {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9s1nXKQAAAAASUVORK5CYII=');
        if ($png === false) {
            throw new RuntimeException('Unable to create the temporary logo test file.');
        }
        file_put_contents($tmpPath, $png);
        $sourceWidth = 1;
        $sourceHeight = 1;
    }

    $result = bakeshopStoreLogoUpload([
        'name' => 'store-logo.png',
        'tmp_name' => $tmpPath,
        'error' => UPLOAD_ERR_OK,
        'size' => (int)filesize($tmpPath),
    ]);

    $uploadedAbsolutePath = (string)($result['absolute_path'] ?? '');
    btLogo('logo upload returns public url', ($result['store_logo_url'] ?? '') !== '', json_encode($result, JSON_UNESCAPED_SLASHES));
    btLogo('logo upload writes file to disk', $uploadedAbsolutePath !== '' && is_file($uploadedAbsolutePath), $uploadedAbsolutePath);
    btLogo('logo upload stores an image mime type', str_starts_with((string)($result['mime_type'] ?? ''), 'image/'), json_encode($result, JSON_UNESCAPED_SLASHES));
    btLogo('logo upload path is branding-scoped', str_contains((string)($result['relative_path'] ?? ''), 'branding/'), (string)($result['relative_path'] ?? ''));
    btLogo('logo upload reports stored dimensions', (int)($result['width'] ?? 0) > 0 && (int)($result['height'] ?? 0) > 0, json_encode($result, JSON_UNESCAPED_SLASHES));
    if (extension_loaded('gd')) {
        btLogo('logo upload normalizes oversized raster images', (int)($result['width'] ?? 0) <= bakeshopStoreLogoMaxDimension() && (int)($result['height'] ?? 0) <= bakeshopStoreLogoMaxDimension() && !empty($result['normalized']), json_encode($result, JSON_UNESCAPED_SLASHES));
    } else {
        btLogo('logo upload preserves small dimensions without GD', (int)($result['width'] ?? 0) === $sourceWidth && (int)($result['height'] ?? 0) === $sourceHeight, json_encode($result, JSON_UNESCAPED_SLASHES));
    }
} finally {
    if ($tmpPath !== false && is_file($tmpPath)) {
        @unlink($tmpPath);
    }
    if ($uploadedAbsolutePath !== '' && is_file($uploadedAbsolutePath)) {
        @unlink($uploadedAbsolutePath);
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