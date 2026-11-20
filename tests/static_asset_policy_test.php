<?php

declare(strict_types=1);

/**
 * Static assets — ONE cache policy, decided by asset class, not by which code path served the file.
 *
 * MEASURED DEFECT (2026-11-20): the front controller had three static-file responders and each carried
 * its own MIME map plus its own Cache-Control number -
 *   - the ultra-early module-uploads responder: `public, max-age=86400, stale-while-revalidate=3600`
 *   - the module-assets responder:              `public, max-age=3600`
 *   - the public-assets fallback:               `public, max-age=3600`
 * while `public/.htaccess` said 3600 for code and 604800 for media. Four places, three answers. A
 * stylesheet's cache lifetime therefore depended on which path happened to serve that request, and the
 * daily-ledger browser Tailwind build (407 KB) was served under two different lifetimes depending on
 * whether Apache or PHP answered.
 *
 * Each assertion names what breaks if it regresses:
 * - code assets keep the one-hour ceiling: these URLs are NOT fingerprinted, so a longer max-age serves
 *   stale script after a deploy for exactly that long;
 * - media keeps a week: uploads are stored under a timestamped unique filename, so a replaced file
 *   arrives as a new URL and can never be served stale from this cache;
 * - an unknown extension resolves to NULL, not to octet-stream: `null` is what makes a responder fall
 *   through to the full kernel (which can 404 properly) instead of shipping a wrong Content-Type;
 * - the front controller carries no Cache-Control literal of its own: one policy, one home. If that
 *   assertion fails, someone has re-inlined a second policy.
 */

ob_start();
require_once __DIR__ . '/harness/TestHarness.php';
$h = new TestHarness('static-asset-policy', TestHarness::MODE_INTEGRATION, 'baronledger.test');
ob_end_clean();

require_once __DIR__ . '/../src/helpers/static-asset.php';

$passed = 0;
$failed = 0;
$check = static function (string $label, bool $ok, string $detail = '') use (&$passed, &$failed, $h): void {
    if ($ok) {
        $passed++;
        $h->test($label, true);
        return;
    }
    $failed++;
    $h->test($label, false, $detail);
};

// ── MIME types ───────────────────────────────────────────────────────
$check('css is text/css with a charset', kernelStaticAssetMimeType('css') === 'text/css; charset=utf-8');
$check('js is application/javascript with a charset', kernelStaticAssetMimeType('js') === 'application/javascript; charset=utf-8');
$check('mjs resolves like js', kernelStaticAssetMimeType('mjs') === kernelStaticAssetMimeType('js'));
$check('png is image/png', kernelStaticAssetMimeType('png') === 'image/png');
$check('svg is image/svg+xml', kernelStaticAssetMimeType('svg') === 'image/svg+xml');
$check('woff2 is font/woff2', kernelStaticAssetMimeType('woff2') === 'font/woff2');
$check('uppercase extension resolves (PNG)', kernelStaticAssetMimeType('PNG') === 'image/png');
$check('leading dot tolerates (.css)', kernelStaticAssetMimeType('.css') === 'text/css; charset=utf-8');
$check(
    'an unknown extension returns NULL so the caller falls through',
    kernelStaticAssetMimeType('php') === null && kernelStaticAssetMimeType('exe') === null && kernelStaticAssetMimeType('') === null
);

// ── Cache policy by asset class ──────────────────────────────────────
$code = ['css', 'js', 'mjs'];
foreach ($code as $ext) {
    $value = kernelStaticAssetCacheControl($ext);
    $check(
        'code asset ' . $ext . ' is public and capped at one hour',
        $value === 'public, max-age=3600',
        'got: ' . $value
    );
}

$media = ['png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'avif', 'ico', 'woff', 'woff2', 'ttf', 'otf', 'eot'];
foreach ($media as $ext) {
    $value = kernelStaticAssetCacheControl($ext);
    $check(
        'media asset ' . $ext . ' is public for a week',
        $value === 'public, max-age=604800',
        'got: ' . $value
    );
}

$other = ['pdf', 'json', 'txt', 'webmanifest'];
foreach ($other as $ext) {
    $value = kernelStaticAssetCacheControl($ext);
    $check(
        'other asset ' . $ext . ' gets a day plus a revalidating tail',
        $value === 'public, max-age=86400, stale-while-revalidate=3600',
        'got: ' . $value
    );
}

// Any new extension must land in a real policy, never an empty header.
foreach (['zip', 'csv', 'mp4', 'unknown'] as $ext) {
    $value = kernelStaticAssetCacheControl($ext);
    $check('unclassified extension ' . $ext . ' still gets a bounded policy', $value !== '' && str_starts_with($value, 'public, max-age='));
}

// ── The policy has exactly one home ──────────────────────────────────
$front = (string)file_get_contents(__DIR__ . '/../public/index.php');
$check(
    'the front controller no longer inlines a Cache-Control policy',
    substr_count($front, "header('Cache-Control:") === 0,
    'found ' . substr_count($front, "header('Cache-Control:") . ' inline header call(s)'
);
$check(
    'the front controller no longer carries its own MIME maps',
    substr_count($front, "=> 'image/png'") === 0,
    'found ' . substr_count($front, "=> 'image/png'") . " inline 'image/png' entry(ies)"
);
$check('the front controller uses the shared helper', substr_count($front, 'kernelStaticAssetServe(') >= 3);

// Serve() itself must refuse a missing file rather than emit a half-response.
$check('serve() refuses a nonexistent path', kernelStaticAssetServe(__DIR__ . '/no-such-asset-file.css') === false);

$h->done();
echo "\n" . str_repeat('─', 60) . "\n";
echo ($failed === 0 ? 'PASS' : 'FAIL') . ": {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
