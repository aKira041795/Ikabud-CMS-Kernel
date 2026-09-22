<?php

declare(strict_types=1);

/**
 * Daily Ledger — branding assets are stored at the size they are DISPLAYED at
 *
 * The live logo arrived at 800x800 / 336KB and is shown at 32px (h-8 w-8 in the app
 * header) and 64px (max-h-16 on the login page). On Bluehost that single file cost
 * ~20s of a page load — measured from outside the host, 336KB moved at ~18KB/s while
 * the same page's 7KB of HTML moved at ~2MB/s.
 *
 * Nothing outside the upload path could fix that, and two plausible fixes were
 * measured and rejected before this one was written:
 *   - PHP is NOT in the serving path. Apache answers /uploads/ directly because
 *     public/.htaccess rewrites only when the file does not exist (!-f), so the
 *     ultra-early static handler in public/index.php never sees these requests, and
 *     moving them into PHP would add boot cost rather than remove it.
 *   - Edge compression is worthless here. gzip on this PNG saves 1.0%
 *     (336205 -> 332934) because PNG is already DEFLATE-compressed internally.
 * So the bytes themselves have to shrink, at the boundary where they arrive.
 *
 * These tests pin the properties that make the resize safe to ship:
 *   1. It only ever SHRINKS, and only below the display-derived edge.
 *   2. It preserves format, aspect ratio and PNG alpha.
 *   3. It is a no-op (returns null) for anything it must not touch: SVG (vector),
 *      GIF (would lose animation), ICO (multi-size container), already-small images,
 *      and non-images.
 *   4. It never throws — a failed resize must not fail an otherwise valid upload.
 *   5. The upload path validates the ORIGINAL size before resizing, so shrinking
 *      cannot be used to slip an oversized upload past the limit.
 *
 * Integration mode — the module helpers register render-context contracts at load time,
 * so they must come in through the bootstrap rather than a bare require. Real GD, real
 * files, no database fixtures.
 */

ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';

$h = new TestHarness('daily-ledger-brand-asset-downscale', TestHarness::MODE_INTEGRATION, 'localhost');
ob_end_clean();
$h->fingerprint('modules/daily-ledger/helpers.php');

// The integration bootstrap does not pull in module helpers; the other daily-ledger
// suites load them explicitly. helpers.php registers render-context contracts at load
// time, so this require only works after the bootstrap above.
$base = $h->basePath();
require_once $base . '/modules/daily-ledger/helpers.php';

$tmpDir = sys_get_temp_dir() . '/dl-brand-asset-' . bin2hex(random_bytes(4));
@mkdir($tmpDir, 0700, true);

/**
 * Build a poorly-compressible image, like a real photo-derived logo.
 * Deterministic (fixed seed) so the suite reports the same numbers every run.
 */
$makePng = static function (string $path, int $w, int $h, bool $alpha = false): void {
    mt_srand(20260507);
    $im = imagecreatetruecolor($w, $h);
    if ($alpha) {
        imagealphablending($im, false);
        imagesavealpha($im, true);
        imagefilledrectangle($im, 0, 0, $w - 1, $h - 1, imagecolorallocatealpha($im, 0, 0, 0, 127));
        imagealphablending($im, true);
    }
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            imagesetpixel($im, $x, $y, imagecolorallocate($im, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255)));
        }
    }
    // A fully transparent corner, to prove alpha survives the resample.
    if ($alpha) {
        imagealphablending($im, false);
        imagefilledrectangle($im, 0, 0, 9, 9, imagecolorallocatealpha($im, 0, 0, 0, 127));
    }
    imagepng($im, $path, 0);
};

$cleanup = static function () use ($tmpDir): void {
    foreach (glob($tmpDir . '/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($tmpDir);
};

$h->section('The live case: an 800x800 photo-like logo');

$big = $tmpDir . '/big.png';
$makePng($big, 800, 800);
$beforeBytes = (int)filesize($big);
$beforeDim = getimagesize($big);

$h->test('fixture reproduces the live shape (800x800, over 100KB)', $beforeDim[0] === 800 && $beforeDim[1] === 800 && $beforeBytes > 102400, $beforeBytes . ' bytes');

$result = dlDownscaleBrandAssetInPlace($big, 'image/png', 'logo');
$afterDim = getimagesize($big);
$afterBytes = (int)filesize($big);

$h->test('returns a report when it resizes', is_array($result), var_export($result, true));
$h->test('shrank the wide edge to the 192px display target', $afterDim[0] === 192 && $afterDim[1] === 192, $afterDim[0] . 'x' . $afterDim[1]);
$h->test('kept the format as PNG', ($afterDim['mime'] ?? '') === 'image/png', (string)($afterDim['mime'] ?? ''));
$h->test('reduced the stored bytes', $afterBytes < $beforeBytes, $beforeBytes . ' -> ' . $afterBytes . ' bytes (' . round($beforeBytes / max(1, $afterBytes), 1) . 'x)');
$h->test('reported the bytes it actually wrote', ($result['bytes'] ?? 0) === $afterBytes && ($result['original_bytes'] ?? 0) === $beforeBytes, json_encode($result));
$h->test('left no staging file behind', !file_exists($big . '.resize-tmp'));

$h->section('Idempotency and the shrink-only guarantee');

$h->test('a second pass is a no-op', dlDownscaleBrandAssetInPlace($big, 'image/png', 'logo') === null);
$h->test('the no-op changed nothing', (int)filesize($big) === $afterBytes, (string)filesize($big));

$small = $tmpDir . '/small.png';
$makePng($small, 64, 64);
$smallBytes = (int)filesize($small);
$h->test('an already-small image is left alone', dlDownscaleBrandAssetInPlace($small, 'image/png', 'logo') === null);
$h->test('and its bytes are untouched', (int)filesize($small) === $smallBytes, $smallBytes . ' bytes');

$tiny = $tmpDir . '/tiny.png';
$makePng($tiny, 100, 50);
$tinyDim = getimagesize($tiny);
dlDownscaleBrandAssetInPlace($tiny, 'image/png', 'logo');
$tinyAfter = getimagesize($tiny);
$h->test('never enlarges a small image', $tinyAfter[0] === $tinyDim[0] && $tinyAfter[1] === $tinyDim[1], $tinyAfter[0] . 'x' . $tinyAfter[1]);

$h->section('Aspect ratio and asset-type targets');

$wide = $tmpDir . '/wide.png';
$makePng($wide, 800, 400);
dlDownscaleBrandAssetInPlace($wide, 'image/png', 'logo');
$wideDim = getimagesize($wide);
$h->test('preserves the 2:1 aspect ratio at 192x96', $wideDim[0] === 192 && $wideDim[1] === 96, $wideDim[0] . 'x' . $wideDim[1]);

$fav = $tmpDir . '/fav.png';
$makePng($fav, 800, 800);
dlDownscaleBrandAssetInPlace($fav, 'image/png', 'favicon');
$favDim = getimagesize($fav);
$h->test('a favicon targets 180px, not the 192px logo edge', $favDim[0] === 180 && $favDim[1] === 180, $favDim[0] . 'x' . $favDim[1]);

$h->section('Alpha survives the resample');

$alpha = $tmpDir . '/alpha.png';
$makePng($alpha, 800, 800, true);
dlDownscaleBrandAssetInPlace($alpha, 'image/png', 'logo');
$alphaIm = imagecreatefrompng($alpha);
$aw = imagesx($alphaIm);
$cornerAlpha = (imagecolorat($alphaIm, 0, 0) >> 24) & 0x7F;
$opaqueAlpha = (imagecolorat($alphaIm, intdiv($aw, 2), intdiv($aw, 2)) >> 24) & 0x7F;
$h->test('the transparent corner is still transparent', $cornerAlpha === 127, 'alpha=' . $cornerAlpha);
$h->test('the opaque middle is still opaque', $opaqueAlpha === 0, 'alpha=' . $opaqueAlpha);

$h->section('Formats it must not touch');

$svg = $tmpDir . '/logo.svg';
file_put_contents($svg, '<svg xmlns="http://www.w3.org/2000/svg" width="800" height="800"><rect width="800" height="800"/></svg>');
$svgBytes = (int)filesize($svg);
$h->test('SVG is left alone (vector, no raster size)', dlDownscaleBrandAssetInPlace($svg, 'image/svg+xml', 'logo') === null);
$h->test('and the SVG bytes are untouched', (int)filesize($svg) === $svgBytes);

$gif = $tmpDir . '/logo.gif';
$gifIm = imagecreatetruecolor(800, 800);
imagefilledrectangle($gifIm, 0, 0, 799, 799, imagecolorallocate($gifIm, 10, 20, 30));
imagegif($gifIm, $gif);
$gifBytes = (int)filesize($gif);
$h->test('GIF is left alone (GD would flatten animation)', dlDownscaleBrandAssetInPlace($gif, 'image/gif', 'logo') === null);
$h->test('and the GIF bytes are untouched', (int)filesize($gif) === $gifBytes);

$ico = $tmpDir . '/favicon.ico';
file_put_contents($ico, str_repeat("\x00", 2048));
$h->test('ICO is left alone (multi-size container)', dlDownscaleBrandAssetInPlace($ico, 'image/x-icon', 'favicon') === null);

$junk = $tmpDir . '/not-an-image.png';
file_put_contents($junk, 'this is plainly not a PNG');
$junkBytes = (int)filesize($junk);
$threw = false;
try {
    $junkResult = dlDownscaleBrandAssetInPlace($junk, 'image/png', 'logo');
} catch (Throwable $e) {
    $threw = true;
    $junkResult = null;
}
$h->test('a corrupt image does not throw', !$threw);
$h->test('a corrupt image returns null and is untouched', $junkResult === null && (int)filesize($junk) === $junkBytes);

$missing = $tmpDir . '/does-not-exist.png';
$h->test('a missing file returns null without throwing', dlDownscaleBrandAssetInPlace($missing, 'image/png', 'logo') === null);

$h->section('Other raster formats');

$jpg = $tmpDir . '/big.jpg';
$jpgIm = imagecreatetruecolor(800, 600);
for ($y = 0; $y < 600; $y++) {
    for ($x = 0; $x < 800; $x++) {
        imagesetpixel($jpgIm, $x, $y, imagecolorallocate($jpgIm, ($x * 3) % 256, ($y * 5) % 256, ($x + $y) % 256));
    }
}
imagejpeg($jpgIm, $jpg, 100);
$jpgBefore = (int)filesize($jpg);
dlDownscaleBrandAssetInPlace($jpg, 'image/jpeg', 'logo');
$jpgDim = getimagesize($jpg);
$h->test('JPEG is resized and keeps its format', $jpgDim[0] === 192 && ($jpgDim['mime'] ?? '') === 'image/jpeg', $jpgDim[0] . 'x' . $jpgDim[1] . ' ' . ($jpgDim['mime'] ?? ''));
$h->test('JPEG got smaller', (int)filesize($jpg) < $jpgBefore, $jpgBefore . ' -> ' . filesize($jpg) . ' bytes');

if (function_exists('imagewebp')) {
    $webp = $tmpDir . '/big.webp';
    $webpIm = imagecreatetruecolor(800, 800);
    imagefilledrectangle($webpIm, 0, 0, 799, 799, imagecolorallocate($webpIm, 200, 120, 40));
    imagewebp($webpIm, $webp, 100);
    $webpBefore = (int)filesize($webp);
    dlDownscaleBrandAssetInPlace($webp, 'image/webp', 'logo');
    $webpDim = getimagesize($webp);
    $h->test('WebP is resized and keeps its format', $webpDim[0] === 192 && ($webpDim['mime'] ?? '') === 'image/webp', $webpDim[0] . 'x' . $webpDim[1] . ' ' . ($webpDim['mime'] ?? ''));
    $h->test('WebP got smaller', (int)filesize($webp) < $webpBefore, $webpBefore . ' -> ' . filesize($webp) . ' bytes');
}

$h->section('The upload path uses it, after validating the original');

$helpersSrc = (string)file_get_contents($h->basePath() . '/modules/daily-ledger/helpers.php');

// Scope every source assertion to the function body: the doc comment above the helper
// names it, so a whole-file search would match the comment rather than the call.
$uploadStart = strpos($helpersSrc, 'function dlUploadBrandAsset(');
$uploadEnd = $uploadStart === false ? false : strpos($helpersSrc, "\nfunction ", $uploadStart + 10);
$uploadBody = ($uploadStart === false || $uploadEnd === false) ? '' : substr($helpersSrc, $uploadStart, $uploadEnd - $uploadStart);

$h->test('dlUploadBrandAsset locates its own body for inspection', $uploadBody !== '');
$h->test('the upload path calls the downscaler', str_contains($uploadBody, 'dlDownscaleBrandAssetInPlace($tmpPath, $mimeType, $assetType)'));
$h->test('the downscale runs before the destination loop copies the file', strpos($uploadBody, 'dlDownscaleBrandAssetInPlace(') < strpos($uploadBody, '$destinations = [];'));
$h->test(
    'the original size limit is checked before the resize, so shrinking cannot bypass it',
    strpos($uploadBody, 'exceeds the maximum allowed size') < strpos($uploadBody, 'dlDownscaleBrandAssetInPlace(')
);

$h->section('What the resize is worth on the live link');

$h->test(
    'the resized logo is a small fraction of the original transfer',
    $afterBytes < $beforeBytes / 5,
    $beforeBytes . ' -> ' . $afterBytes . ' bytes (' . round($beforeBytes / max(1, $afterBytes), 1) . 'x smaller; at the measured ~18KB/s that is ~'
        . round($beforeBytes / 18432, 1) . 's down to ~' . round($afterBytes / 18432, 2) . 's)'
);

$cleanup();
$h->done();
