<?php
/**
 * The two page-cache layers must agree on the key, the file name, and the header contract.
 *
 * THE DEFECT THIS GUARDS (measured 2026-10-11): the pre-bootstrap fast path built its key as
 * `'page:' . $origin . ':' . md5($raw)` while the canonical writer pageCacheKey() produced
 * `'page:v2:' . $origin . ':' . md5($raw)`. The two could never match, so the fast path never
 * found an entry the writer had produced and that whole cache was DEAD - it silently degraded to
 * one wasted md5 + stat per request instead of skipping the kernel boot. It had been dead long
 * enough for its flush-marker handling to rot unnoticed, which is why activating it also required
 * restoring the security headers.
 *
 * A cross-layer contract like this cannot be exercised end to end from a unit test (the fast path
 * is a self-contained pre-bootstrap script that exits on hit), so the assertions below are a mix
 * of functional checks and explicit source-contract checks. The contract checks are deliberate:
 * here the string IS the interface.
 *
 * Run: php tests/fast_path_cache_contract_test.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);

ob_start();
require $root . '/bootstrap.php';
require_once BASE_PATH . '/src/helpers/page-cache.php';
ob_end_clean();

$passed = 0;
$failed = 0;
function fpcAssert(string $label, bool $condition, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        echo "PASS: {$label}\n";
        $passed++;
        return;
    }
    echo "FAIL: {$label}" . ($detail !== '' ? "\n      {$detail}" : '') . "\n";
    $failed++;
}

echo "=== FAST-PATH CACHE CONTRACT ===\n\n";

$fastPathFile = BASE_PATH . '/src/helpers/fast-path-cache.php';
$fastSource = is_file($fastPathFile) ? (string)file_get_contents($fastPathFile) : '';
fpcAssert('the fast-path source is readable', $fastSource !== '');

// ── 1. Key format agreement ───────────────────────────────────────────────────
echo "\n--- key format ---\n";

$keyPrefix = 'page:v2:';
$canonical = pageCacheKey('/contract-test');
fpcAssert(
    'pageCacheKey() still uses the documented prefix',
    str_starts_with($canonical, $keyPrefix),
    "got: {$canonical}"
);
fpcAssert(
    'the fast path builds its key with the SAME prefix as pageCacheKey()',
    str_contains($fastSource, "'" . $keyPrefix . "' . \$origin"),
    'fast path does not use the canonical prefix - the caches cannot match'
);
fpcAssert(
    'the fast path no longer uses the stale bare "page:" prefix',
    !preg_match("/\\\$cacheKey = 'page:'/", $fastSource),
    'found the stale key form that made the fast path dead'
);
fpcAssert(
    'the fast path hashes the entry file name the same way the writer does',
    str_contains($fastSource, "md5(\$cacheKey) . '.cache'"),
    'entry file naming diverged'
);

// ── 2. Header contract ────────────────────────────────────────────────────────
echo "\n--- header contract ---\n";

// SecurityHeaders derives its policy from the request, so give the test a real-looking request.
// Without REQUEST_URI it has nothing to classify and returns an empty policy.
$_SERVER['REQUEST_URI'] = '/contract-test';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_METHOD'] = 'GET';

fpcAssert(
    'the security header policy is available in this context',
    pageCacheResponseHeaders() !== [],
    'pageCacheResponseHeaders() returned nothing - the header contract cannot be checked'
);

$policy = pageCacheResponseHeaders();
$hasCsp = false;
$hasFrame = false;
$newlines = 0;
foreach ($policy as $header) {
    if (is_string($header) && str_starts_with($header, 'Content-Security-Policy:')) {
        $hasCsp = true;
    }
    if (is_string($header) && str_starts_with($header, 'X-Frame-Options:')) {
        $hasFrame = true;
    }
    if (is_string($header) && (str_contains($header, "\n") || str_contains($header, "\r"))) {
        $newlines++;
    }
}
fpcAssert('the policy carries Content-Security-Policy', $hasCsp, 'no CSP in the header policy');
fpcAssert('the policy carries X-Frame-Options', $hasFrame, 'no X-Frame-Options in the header policy');
fpcAssert('no header in the policy can inject a second header line', $newlines === 0, "{$newlines} offending header(s)");

// What the WRITER attaches is asserted at source level rather than by a cache round trip: the
// cache round trip depends on CLI request state that does not reproduce the FPM path. The
// end-to-end proof is the live response, which was measured serving CSP + X-Frame-Options +
// Referrer-Policy with HTML byte-identical to the kernel render.
fpcAssert(
    'pageCacheSet() attaches the header policy to every entry it writes',
    str_contains((string)file_get_contents(BASE_PATH . '/src/helpers/page-cache.php'), "'headers' => pageCacheResponseHeaders(),"),
    'the writer does not attach a header policy - the fast path would serve a page without CSP'
);

fpcAssert(
    'the fast path replays the stored headers',
    str_contains($fastSource, 'foreach ($entryHeaders as $entryHeader)') && str_contains($fastSource, 'header($entryHeader)'),
    'headers are not replayed'
);
fpcAssert(
    'the fast path refuses an entry that carries no header policy',
    preg_match('/\$entryHeaders === \[\]\s*\)\s*\{\s*return;/s', $fastSource) === 1,
    'an entry without headers would be served without CSP'
);

// ── 3. Kill switch ────────────────────────────────────────────────────────────
echo "\n--- kill switch ---\n";

fpcAssert(
    'the fast path can be disabled from configuration',
    str_contains($fastSource, 'PAGE_CACHE_FAST_PATH=') && str_contains($fastSource, 'if ($fastPathDisabled)'),
    'no kill switch found'
);

// ── 4. The fast path must not serve an expired entry ──────────────────────────
echo "\n--- freshness ---\n";

fpcAssert(
    'the fast path validates entry expiry',
    str_contains($fastSource, "_cache_expires_at"),
    'no expiry check'
);
fpcAssert(
    'the fast path honours the flush marker',
    str_contains($fastSource, 'filemtime($flushFile)'),
    'no flush-marker check'
);



echo "\n============================================================\n";
echo "Fast-path cache contract tests: {$passed} passed, {$failed} failed\n";
echo "============================================================\n";

exit($failed === 0 ? 0 : 1);
