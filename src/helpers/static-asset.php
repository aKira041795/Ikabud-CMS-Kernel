<?php

/**
 * ONE definition of how this application serves a static file.
 *
 * WHY THIS FILE EXISTS (measured 2026-11-20)
 * The front controller grew three separate static-file responders - module uploads (ultra-early, before
 * bootstrap), module assets, and the public-assets fallback - and each carried its own copy of the MIME
 * map and its own Cache-Control number: uploads said `max-age=86400, stale-while-revalidate=3600`, the
 * other two said `max-age=3600`, while `public/.htaccess` said 3600 for code and 604800 for media. So the
 * cache lifetime of a stylesheet depended on WHICH OF FOUR code paths happened to serve it, which is not
 * a policy, it is an accident.
 *
 * The rule this restores: an asset's caching is a property of the ASSET CLASS, decided in one place, and
 * every path that serves a file uses it. `public/.htaccess` mirrors these same numbers for files Apache
 * serves without reaching PHP (see the comment there); it cannot call this function, so the numbers are
 * stated there as a deliberate copy - change both together.
 *
 * The max-age for CODE is deliberately one hour: these URLs are not fingerprinted, so a long max-age
 * would serve stale script after a deploy for that long. Media gets a week because uploads are stored
 * with a timestamped unique filename (logo_20260507_212944_d1b0867a.png), so a replaced file arrives as
 * a new URL and can never be served stale from this cache.
 */

/**
 * The MIME type for an extension, or null when this application does not serve that type statically.
 *
 * `null` is meaningful: the callers use it to fall through to the full kernel (which can 404 properly or
 * produce the file itself) instead of guessing `application/octet-stream` and shipping a wrong Content-Type.
 */
function kernelStaticAssetMimeType(string $extension): ?string
{
    static $types = [
        'css'   => 'text/css; charset=utf-8',
        'js'    => 'application/javascript; charset=utf-8',
        'mjs'   => 'application/javascript; charset=utf-8',
        'json'  => 'application/json; charset=utf-8',
        'map'   => 'application/json; charset=utf-8',
        'png'   => 'image/png',
        'jpg'   => 'image/jpeg',
        'jpeg'  => 'image/jpeg',
        'gif'   => 'image/gif',
        'svg'   => 'image/svg+xml',
        'webp'  => 'image/webp',
        'avif'  => 'image/avif',
        'ico'   => 'image/x-icon',
        'pdf'   => 'application/pdf',
        'woff'  => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf'   => 'font/ttf',
        'otf'   => 'font/otf',
        'eot'   => 'application/vnd.ms-fontobject',
        'txt'   => 'text/plain; charset=utf-8',
        'webmanifest' => 'application/manifest+json',
    ];

    $extension = strtolower(ltrim($extension, '.'));
    return $types[$extension] ?? null;
}

/**
 * The Cache-Control value for a served file, by asset class.
 *
 * Kept as a function rather than an inline string so that the three responders cannot drift again, and
 * so a test can assert the policy without booting a request.
 */
function kernelStaticAssetCacheControl(string $extension): string
{
    $extension = strtolower(ltrim($extension, '.'));

    // Code: not fingerprinted, so bounded by deploy staleness rather than by convenience.
    if (in_array($extension, ['css', 'js', 'mjs'], true)) {
        return 'public, max-age=3600';
    }

    // Images and fonts: unique timestamped names on upload, so a week costs nothing in correctness.
    if (in_array($extension, ['png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'avif', 'ico', 'woff', 'woff2', 'ttf', 'otf', 'eot'], true)) {
        return 'public, max-age=604800';
    }

    // Everything else (pdf, json, txt, manifest): a day, with a revalidating tail so a long-idle
    // client refreshes in the background instead of blocking on it.
    return 'public, max-age=86400, stale-while-revalidate=3600';
}

/**
 * Emit a static file: ETag, Cache-Control, nosniff, 304 on a match, then the bytes.
 *
 * Returns false when the path is not a readable file, so the caller can fall through instead of
 * emitting a half-response. Exits on success and on 304 - the response is complete at that point.
 *
 * The ETag is weak and derived from path|mtime|size: on this shared host mtime alone can be preserved
 * by a deploy that keeps the timestamp, so the size is folded in as the cheap second signal.
 */
function kernelStaticAssetServe(string $realPath, ?string $extension = null, bool $contentTypeFallback = true): bool
{
    if ($realPath === '' || !is_file($realPath) || !is_readable($realPath)) {
        return false;
    }

    $extension = $extension !== null ? $extension : (string)pathinfo($realPath, PATHINFO_EXTENSION);
    $extension = strtolower(ltrim($extension, '.'));

    $contentType = kernelStaticAssetMimeType($extension);
    if ($contentType === null) {
        if (!$contentTypeFallback) {
            return false;
        }
        $contentType = 'application/octet-stream';
    }

    $mtime = (int)@filemtime($realPath);
    $size = (int)@filesize($realPath);
    $etag = 'W/"' . sha1($realPath . '|' . $mtime . '|' . $size) . '"';

    header('ETag: ' . $etag);
    header('Cache-Control: ' . kernelStaticAssetCacheControl($extension));
    header('X-Content-Type-Options: nosniff');

    $ifNoneMatch = isset($_SERVER['HTTP_IF_NONE_MATCH']) ? trim((string)$_SERVER['HTTP_IF_NONE_MATCH']) : '';
    if ($ifNoneMatch !== '' && $ifNoneMatch === $etag) {
        http_response_code(304);
        exit;
    }

    header('Content-Type: ' . $contentType);
    header('Content-Length: ' . $size);
    readfile($realPath);
    exit;
}
