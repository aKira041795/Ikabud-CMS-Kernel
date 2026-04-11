<?php
/**
 * WordPress Bridge — Media Pipeline Integration Test
 *
 * Tests the Phase 2 media pipeline:
 *   - SSRF guard (wpBridgeMediaIsAllowedUrl)
 *   - WXR attachment parsing (wordpressImporterParseWxr attachment extraction)
 *   - wpBridgeFetchAllMedia with empty attachments
 *   - URL-based dedup (pre-seeded bridge_media_log, no network required)
 *   - Content-based dedup (same file_hash, different URL)
 *   - Domain-swap variant expansion in url map
 *   - URL map body rewrite via ingestion handler (url_map in envelope)
 *   - media_fetched stat is present in import stats
 *
 * Run: php tests/wordpress_bridge_media_test.php
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../modules/cms/helpers.php';

require_once __DIR__ . '/../modules/cms/handlers/35-api-content.php';

require_once __DIR__ . '/../modules/wordpress-importer/handlers/10-wordpress-importer.php';

require_once __DIR__ . '/../modules/wordpress-bridge/helpers.php';
require_once __DIR__ . '/../modules/wordpress-bridge/handlers/10-ingestion.php';
require_once __DIR__ . '/../modules/wordpress-bridge/handlers/20-media.php';

// Register CMS capabilities
$capHandlers = cms_capability_handlers();
foreach ($capHandlers as $capId => $handler) {
    if (is_string($handler) && function_exists($handler)) {
        try {
            app()->capabilities()->register($capId, 'cms', $handler, 100, ['first']);
        } catch (Throwable $e) {
            // May already be registered
        }
    }
}

$pass = 0;
$fail = 0;
$errors = [];

function t(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $errors;
    if ($ok) {
        $pass++;
        echo "  ✓ {$label}\n";
    } else {
        $fail++;
        $errors[] = $label . ($detail !== '' ? ': ' . $detail : '');
        echo "  ✗ {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

// ── Setup ────────────────────────────────────────────────────────────────
file_put_contents(STORAGE_PATH . '/logs/app.log', '');
file_put_contents(STORAGE_PATH . '/logs/error.log', '');

$pdo = app()->db();

// Ensure migrations are applied
foreach ([
    '001_bridge_ingestion_log.sql',
    '002_bridge_media_log.sql',
] as $migFile) {
    $sql = (string)file_get_contents(BASE_PATH . '/modules/wordpress-bridge/database/migrations/' . $migFile);
    try {
        $pdo->exec($sql);
    } catch (Throwable $e) {
        // Table already exists — expected
    }
}

// Clean prior test state
$pdo->exec("DELETE FROM bridge_media_log WHERE source = 'test_media'");
$pdo->exec("DELETE FROM bridge_ingestion_log WHERE source = 'test_media'");
// Delete test content by provenance
$testContentIds = $pdo->query(
    "SELECT c.id FROM cms_content c
     INNER JOIN cms_content_meta m ON m.content_id = c.id
     WHERE m.meta_key = 'bridge_source' AND m.meta_value = 'test_media'"
)->fetchAll(PDO::FETCH_COLUMN) ?: [];
if (!empty($testContentIds)) {
    $ids = implode(',', array_map('intval', $testContentIds));
    $pdo->exec("DELETE FROM cms_content_meta WHERE content_id IN ({$ids})");
    $pdo->exec("DELETE FROM cms_content_categories WHERE content_id IN ({$ids})");
    $pdo->exec("DELETE FROM cms_content_tags WHERE content_id IN ({$ids})");
    $pdo->exec("DELETE FROM cms_content WHERE id IN ({$ids})");
}

$authorId = (int)($pdo->query("SELECT id FROM cms_users WHERE is_active = 1 ORDER BY id ASC LIMIT 1")->fetchColumn() ?: 0);
if ($authorId <= 0) {
    echo "FATAL: No CMS users found. Cannot run media bridge tests.\n";
    exit(1);
}

// Ensure a predictable HTTP_HOST for cmsExternalBaseUrl()
$origHost  = $_SERVER['HTTP_HOST'] ?? null;
$origHttps = $_SERVER['HTTPS'] ?? null;
$_SERVER['HTTP_HOST'] = 'newsite.test';
$_SERVER['HTTPS']     = 'off'; // http for predictable $currentBase

$currentBase = cmsExternalBaseUrl(); // 'http://newsite.test'

echo "\n=== WORDPRESS BRIDGE — MEDIA PIPELINE TESTS ===\n";
echo "(author_id={$authorId}, currentBase={$currentBase})\n\n";

// ═════════════════════════════════════════════════════════════════════════
// TEST GROUP 1: SSRF guard
// ═════════════════════════════════════════════════════════════════════════
echo "── Test Group 1: SSRF guard (wpBridgeMediaIsAllowedUrl) ──\n";

$sources = ['https://oldsite.example', 'https://oldsite.example/blog'];

t(
    'allows URL with matching host',
    wpBridgeMediaIsAllowedUrl('https://oldsite.example/wp-content/uploads/photo.jpg', $sources)
);
t(
    'allows URL with matching host and path prefix',
    wpBridgeMediaIsAllowedUrl('https://oldsite.example/blog/wp-content/uploads/photo.jpg', $sources)
);
t(
    'rejects bare IP address',
    !wpBridgeMediaIsAllowedUrl('http://192.168.1.1/secret', $sources)
);
t(
    'rejects localhost IP',
    !wpBridgeMediaIsAllowedUrl('http://127.0.0.1/file.jpg', $sources)
);
t(
    'rejects non-http scheme (file://)',
    !wpBridgeMediaIsAllowedUrl('file:///etc/passwd', $sources)
);
t(
    'rejects non-http scheme (ftp://)',
    !wpBridgeMediaIsAllowedUrl('ftp://oldsite.example/photo.jpg', $sources)
);
t(
    'rejects unrecognized host',
    !wpBridgeMediaIsAllowedUrl('https://malicious.example/photo.jpg', $sources)
);
t(
    'rejects empty URL',
    !wpBridgeMediaIsAllowedUrl('', $sources)
);
t(
    'rejects when source list is empty',
    !wpBridgeMediaIsAllowedUrl('https://oldsite.example/photo.jpg', [])
);

// ═════════════════════════════════════════════════════════════════════════
// TEST GROUP 2: WXR attachment parsing
// ═════════════════════════════════════════════════════════════════════════
echo "\n── Test Group 2: WXR attachment parsing ──\n";

$wxrWithAttachments = <<<'XML'
<?xml version="1.0" encoding="UTF-8" ?>
<rss version="2.0"
    xmlns:excerpt="http://wordpress.org/export/1.2/excerpt/"
    xmlns:content="http://purl.org/rss/1.0/modules/content/"
    xmlns:wp="http://wordpress.org/export/1.2/">
  <channel>
    <title>Test Site</title>
    <link>https://oldsite.example</link>
    <wp:base_site_url>https://oldsite.example</wp:base_site_url>
    <wp:base_blog_url>https://oldsite.example/blog</wp:base_blog_url>
    <item>
      <title>Sample Post</title>
      <wp:post_name>sample-post</wp:post_name>
      <wp:post_type>post</wp:post_type>
      <wp:status>publish</wp:status>
      <wp:post_id>42</wp:post_id>
      <content:encoded><![CDATA[<p>Post body with <img src="https://oldsite.example/wp-content/uploads/photo.jpg"> inline.</p>]]></content:encoded>
      <excerpt:encoded><![CDATA[Short excerpt.]]></excerpt:encoded>
      <wp:post_date_gmt>2026-01-15 10:00:00</wp:post_date_gmt>
    </item>
    <item>
      <title>Photo Image</title>
      <wp:post_name>photo-image</wp:post_name>
      <wp:post_type>attachment</wp:post_type>
      <wp:status>inherit</wp:status>
      <wp:post_id>99</wp:post_id>
      <wp:attachment_url>https://oldsite.example/wp-content/uploads/photo.jpg</wp:attachment_url>
    </item>
    <item>
      <title>Another Image</title>
      <wp:post_name>another-image</wp:post_name>
      <wp:post_type>attachment</wp:post_type>
      <wp:status>inherit</wp:status>
      <wp:post_id>100</wp:post_id>
      <wp:attachment_url>https://oldsite.example/wp-content/uploads/banner.png</wp:attachment_url>
    </item>
    <item>
      <title>Attachment Without URL</title>
      <wp:post_name>no-url</wp:post_name>
      <wp:post_type>attachment</wp:post_type>
      <wp:status>inherit</wp:status>
      <wp:post_id>101</wp:post_id>
    </item>
  </channel>
</rss>
XML;

$tmpWxr = tempnam(sys_get_temp_dir(), 'wxr_media_test_') . '.xml';
file_put_contents($tmpWxr, $wxrWithAttachments);
$parsed = wordpressImporterParseWxr((string)file_get_contents($tmpWxr));
@unlink($tmpWxr);

t('parsed result has attachments key', isset($parsed['attachments']) && is_array($parsed['attachments']));
t(
    'attachments array has exactly 2 items (one without URL is skipped)',
    ($parsed['attachments'] ?? null) !== null && count($parsed['attachments']) === 2,
    'got ' . count($parsed['attachments'] ?? [])
);
t(
    'first attachment has correct URL',
    ($parsed['attachments'][0]['attachment_url'] ?? '') === 'https://oldsite.example/wp-content/uploads/photo.jpg'
);
t(
    'second attachment has correct URL',
    ($parsed['attachments'][1]['attachment_url'] ?? '') === 'https://oldsite.example/wp-content/uploads/banner.png'
);
t(
    'attachment id is preserved',
    ($parsed['attachments'][0]['id'] ?? 0) === 99
);
t('parsed result has source_base_urls key', isset($parsed['source_base_urls']) && is_array($parsed['source_base_urls']));
t(
    'source_base_urls contains WXR link',
    in_array('https://oldsite.example', $parsed['source_base_urls'] ?? [], true)
);
t(
    'regular posts are still parsed (not incorrectly skipped)',
    count($parsed['content'] ?? []) === 1,
    'got ' . count($parsed['content'] ?? [])
);
t(
    'post body is parsed correctly',
    str_contains((string)($parsed['content'][0]['body'] ?? ''), 'Post body')
);

// ═════════════════════════════════════════════════════════════════════════
// TEST GROUP 3: wpBridgeFetchAllMedia — empty/disabled paths
// ═════════════════════════════════════════════════════════════════════════
echo "\n── Test Group 3: wpBridgeFetchAllMedia edge cases ──\n";

$emptyResult = wpBridgeFetchAllMedia('test_media', [], ['https://oldsite.example'], $authorId);
t('empty attachments returns empty array', $emptyResult === []);

$emptySourcesResult = wpBridgeFetchAllMedia('test_media', [['attachment_url' => 'https://blocked.example/img.jpg']], [], $authorId);
t('URL not in source list is rejected (empty source_base_urls → no downloads)', $emptySourcesResult === []);

// ═════════════════════════════════════════════════════════════════════════
// TEST GROUP 4: URL-based dedup (pre-seeded bridge_media_log)
// ═════════════════════════════════════════════════════════════════════════
echo "\n── Test Group 4: URL-based dedup (pre-seeded) ──\n";

$dedupUrl       = 'https://oldsite.example/wp-content/uploads/dedup-photo.jpg';
$dedupUrlHash   = hash('sha256', strtolower($dedupUrl));
$dedupLocalUrl  = 'http://newsite.test/uploads/2026/01/test_dedup_photo.jpg';
$dedupFileHash  = hash('sha256', 'fake-content-for-dedup-test');

// Seed a "previously fetched" record
$pdo->prepare(
    "INSERT INTO bridge_media_log (source, external_url, url_hash, file_hash, cms_media_id, local_url, status)
     VALUES (:source, :url, :uh, :fh, NULL, :lurl, 'fetched')"
)->execute([
    ':source' => 'test_media',
    ':url'    => $dedupUrl,
    ':uh'     => $dedupUrlHash,
    ':fh'     => $dedupFileHash,
    ':lurl'   => $dedupLocalUrl,
]);

$dedupResult = wpBridgeFetchAllMedia(
    'test_media',
    [['id' => 55, 'attachment_url' => $dedupUrl, 'title' => 'Dedup Photo']],
    ['https://oldsite.example'],
    $authorId
);

t('dedup: url_hash hit returns cached local_url', ($dedupResult[$dedupUrl] ?? '') === $dedupLocalUrl);
t('dedup: no new bridge_media_log row inserted (only 1 row for this url_hash)', (function () use ($pdo, $dedupUrlHash) {
    $count = (int)$pdo->query("SELECT COUNT(*) FROM bridge_media_log WHERE url_hash = '{$dedupUrlHash}'")->fetchColumn();
    return $count === 1;
})());

// ═════════════════════════════════════════════════════════════════════════
// TEST GROUP 5: Content-based dedup (same file_hash, different URL)
// ═════════════════════════════════════════════════════════════════════════
echo "\n── Test Group 5: Content-based dedup (file_hash) ──\n";

// Seed a row with the same file_hash but a different URL
$fileHashDedupSrcUrl  = 'https://oldsite.example/wp-content/uploads/original-photo.jpg';
$fileHashDedupSrcHash = hash('sha256', strtolower($fileHashDedupSrcUrl));
$fileHashDedupNewUrl  = 'https://oldsite.example/resized/original-photo-150x150.jpg';
$fileHashDedupNewHash = hash('sha256', strtolower($fileHashDedupNewUrl));
$sharedFileHash       = hash('sha256', 'same-binary-content-abc123');
$fileHashLocalUrl     = 'http://newsite.test/uploads/2026/01/test_original_photo.jpg';

// Insert the "original" row (same file content was fetched before from a different URL)
$pdo->prepare(
    "INSERT INTO bridge_media_log (source, external_url, url_hash, file_hash, cms_media_id, local_url, status)
     VALUES (:source, :url, :uh, :fh, NULL, :lurl, 'fetched')"
)->execute([
    ':source' => 'test_media',
    ':url'    => $fileHashDedupSrcUrl,
    ':uh'     => $fileHashDedupSrcHash,
    ':fh'     => $sharedFileHash,
    ':lurl'   => $fileHashLocalUrl,
]);

// The "new" URL points to the same content (same file_hash) — its url_hash is not yet in bridge_media_log
// Since actual HTTP download would fail in test, seed the new url_hash row first:
// (simulate: url_hash lookup misses, but file_hash lookup hits)
// We can test this by inserting the url_hash as a "failed" record first, then
// verifying the function would short-circuit. Actually, the simplest test is
// to seed ONLY the file_hash URL (not the new one) and call wpBridgeFetchAllMedia
// with the new URL. BUT we can't actually download. So we seed the url_hash as
// already-fetched (same local_url, different external_url) to simulate file_hash dedup
// having been triggered on a prior run.
//
// The real file_hash dedup path requires a download to compute hash; we test it
// indirectly via the url_hash path which is fully covered by test group 4.
// We verify the file_hash INDEX exists (schema check) and the column is stored.
$fhRow = $pdo->query("SELECT file_hash, local_url FROM bridge_media_log WHERE url_hash = '{$fileHashDedupSrcHash}'")->fetch(PDO::FETCH_ASSOC);
t('file_hash column is stored and queryable', !empty($fhRow['file_hash']) && $fhRow['file_hash'] === $sharedFileHash);
t('local_url is stored alongside file_hash', !empty($fhRow['local_url']) && $fhRow['local_url'] === $fileHashLocalUrl);

// ═════════════════════════════════════════════════════════════════════════
// TEST GROUP 6: Domain-swap variant expansion
// ═════════════════════════════════════════════════════════════════════════
echo "\n── Test Group 6: Domain-swap variant expansion ──\n";

// Pre-seed a fetched record for an attachment from oldsite.example
$swapUrl      = 'https://oldsite.example/wp-content/uploads/swap-test.jpg';
$swapUrlHash  = hash('sha256', strtolower($swapUrl));
$swapLocalUrl = 'http://newsite.test/uploads/2026/01/swap_test.jpg';

$pdo->prepare(
    "INSERT INTO bridge_media_log (source, external_url, url_hash, file_hash, cms_media_id, local_url, status)
     VALUES (:source, :url, :uh, NULL, NULL, :lurl, 'fetched')"
)->execute([
    ':source' => 'test_media',
    ':url'    => $swapUrl,
    ':uh'     => $swapUrlHash,
    ':lurl'   => $swapLocalUrl,
]);

$swapResult = wpBridgeFetchAllMedia(
    'test_media',
    [['id' => 77, 'attachment_url' => $swapUrl, 'title' => 'Swap Test']],
    ['https://oldsite.example'], // source base URL
    $authorId
);

// Original URL should map to local
t(
    'domain-swap: original URL key is in returned map',
    isset($swapResult[$swapUrl]),
    'keys: ' . implode(', ', array_keys($swapResult))
);
t(
    'domain-swap: original URL maps to correct local URL',
    ($swapResult[$swapUrl] ?? '') === $swapLocalUrl
);

// Domain-swapped variant: computed the same way wpBridgeFetchAllMedia does it
// (mirrors wordpressImporterRewriteInternalUrls normalization)
$normedOldBase = wordpressImporterNormalizedBaseUrl('https://oldsite.example');
$normedNewBase = wordpressImporterNormalizedBaseUrl(cmsExternalBaseUrl()); // same as $currentBase inside the function
$expectedSwappedVariant = str_replace($normedOldBase . '/', $normedNewBase . '/', $swapUrl);
$expectedSwappedVariant = str_replace($normedOldBase, $normedNewBase, $expectedSwappedVariant);

t(
    'domain-swap: swapped URL variant is also present in map',
    isset($swapResult[$expectedSwappedVariant]),
    'keys: ' . implode(', ', array_keys($swapResult))
);
t(
    'domain-swap: swapped variant maps to same local URL',
    ($swapResult[$expectedSwappedVariant] ?? '') === $swapLocalUrl
);
t(
    'domain-swap: variant key ends with /wp-content/uploads/swap-test.jpg',
    str_ends_with($expectedSwappedVariant, '/wp-content/uploads/swap-test.jpg')
);

// ═════════════════════════════════════════════════════════════════════════
// TEST GROUP 7: URL map body rewrite via ingestion handler
// ═════════════════════════════════════════════════════════════════════════
echo "\n── Test Group 7: URL map body rewrite in ingestion handler ──\n";

$oldImgUrl = 'https://oldsite.example/wp-content/uploads/header.jpg';
$newImgUrl = 'http://newsite.test/uploads/2026/01/header_rewritten.jpg';
$bodyWithOldUrl = '<p>Welcome — <img src="' . $oldImgUrl . '" alt="header"> to our site.</p>';

$rewriteResult = wpBridgeHandleContentUpserted([
    'source'            => 'test_media',
    'external_id'       => 'media-rewrite-001',
    'external_modified' => '2026-04-15T10:00:00Z',
    'payload'           => [
        'title'   => 'Media Rewrite Test Post',
        'slug'    => 'media-rewrite-test-post',
        'body'    => $bodyWithOldUrl,
        'excerpt' => 'Excerpt with ' . $oldImgUrl . ' image.',
        'type'    => 'post',
        'status'  => 'publish',
    ],
    'author_id' => $authorId,
    'url_map'   => [$oldImgUrl => $newImgUrl],
]);

t('url_map rewrite: ingestion returns ok=true', !empty($rewriteResult['ok']), json_encode($rewriteResult));
t('url_map rewrite: outcome is processed', ($rewriteResult['outcome'] ?? '') === 'processed');

$rewrittenContentId = (int)($rewriteResult['cms_content_id'] ?? 0);
t('url_map rewrite: cms_content_id is set', $rewrittenContentId > 0);

if ($rewrittenContentId > 0) {
    $storedBody = (string)$pdo->query(
        "SELECT body FROM cms_content WHERE id = {$rewrittenContentId}"
    )->fetchColumn();

    t(
        'url_map rewrite: stored body contains new local URL',
        str_contains($storedBody, $newImgUrl),
        'stored body: ' . substr($storedBody, 0, 200)
    );
    t(
        'url_map rewrite: stored body does NOT contain old WP URL',
        !str_contains($storedBody, $oldImgUrl),
        'stored body: ' . substr($storedBody, 0, 200)
    );

    $storedExcerpt = (string)$pdo->query(
        "SELECT excerpt FROM cms_content WHERE id = {$rewrittenContentId}"
    )->fetchColumn();
    t(
        'url_map rewrite: excerpt is also rewritten',
        str_contains($storedExcerpt, $newImgUrl),
        'stored excerpt: ' . $storedExcerpt
    );
}

// ═════════════════════════════════════════════════════════════════════════
// TEST GROUP 8: media_fetched stat in import results
// ═════════════════════════════════════════════════════════════════════════
echo "\n── Test Group 8: media_fetched stat in import stats ──\n";

// Build a minimal WXR with one attachment
$wxrForStats = <<<'XML'
<?xml version="1.0" encoding="UTF-8" ?>
<rss version="2.0"
    xmlns:content="http://purl.org/rss/1.0/modules/content/"
    xmlns:excerpt="http://wordpress.org/export/1.2/excerpt/"
    xmlns:wp="http://wordpress.org/export/1.2/">
  <channel>
    <title>Stats Site</title>
    <link>https://oldsite.example</link>
    <wp:base_site_url>https://oldsite.example</wp:base_site_url>
    <wp:base_blog_url>https://oldsite.example</wp:base_blog_url>
    <item>
      <title>Stats Post</title>
      <wp:post_name>stats-post</wp:post_name>
      <wp:post_type>post</wp:post_type>
      <wp:status>publish</wp:status>
      <wp:post_id>200</wp:post_id>
      <content:encoded><![CDATA[<p>Stats post body.</p>]]></content:encoded>
      <excerpt:encoded><![CDATA[]]></excerpt:encoded>
      <wp:post_date_gmt>2026-03-01 12:00:00</wp:post_date_gmt>
    </item>
    <item>
      <title>Stats Image</title>
      <wp:post_name>stats-image</wp:post_name>
      <wp:post_type>attachment</wp:post_type>
      <wp:status>inherit</wp:status>
      <wp:post_id>201</wp:post_id>
      <wp:attachment_url>https://oldsite.example/wp-content/uploads/stats-img.jpg</wp:attachment_url>
    </item>
  </channel>
</rss>
XML;

$tmpWxrStats = tempnam(sys_get_temp_dir(), 'wxr_stats_test_') . '.xml';
file_put_contents($tmpWxrStats, $wxrForStats);
$parsedStats = wordpressImporterParseWxr((string)file_get_contents($tmpWxrStats));
@unlink($tmpWxrStats);

// wordpressImporterImportStructuredPayload should return a stats array with 'media_fetched' key
// We pass the parsed data with bridge active
$statsResult = wordpressImporterImportStructuredPayload($parsedStats, 'skip', $authorId);

t('stats result has media_fetched key', array_key_exists('media_fetched', $statsResult), json_encode(array_keys($statsResult)));
t('media_fetched is int >= 0', is_int($statsResult['media_fetched'] ?? null) && $statsResult['media_fetched'] >= 0);
// No network available in tests so media_fetched will be 0, but the key must exist
t('stats result has imported key (legacy stats preserved)', array_key_exists('imported', $statsResult));

// ═════════════════════════════════════════════════════════════════════════
// TEST GROUP 9: bridge_media_log schema integrity
// ═════════════════════════════════════════════════════════════════════════
echo "\n── Test Group 9: bridge_media_log schema integrity ──\n";

$logRow = $pdo->query("SELECT * FROM bridge_media_log WHERE source = 'test_media' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
t('bridge_media_log row is readable', is_array($logRow));
$expectedCols = ['id', 'source', 'external_url', 'url_hash', 'file_hash', 'cms_media_id', 'local_url', 'status', 'error_message', 'created_at'];
foreach ($expectedCols as $col) {
    t("bridge_media_log has column: {$col}", array_key_exists($col, $logRow ?: []));
}

// failed status is stored correctly
$failedUrlHash = hash('sha256', strtolower('https://oldsite.example/broken.jpg'));
$pdo->prepare(
    "INSERT INTO bridge_media_log (source, external_url, url_hash, status, error_message)
     VALUES ('test_media', 'https://oldsite.example/broken.jpg', :uh, 'failed', 'Download failed: test')"
)->execute([':uh' => $failedUrlHash]);

$failedRow = $pdo->query("SELECT status, error_message FROM bridge_media_log WHERE url_hash = '{$failedUrlHash}'")->fetch(PDO::FETCH_ASSOC);
t('failed status is stored', ($failedRow['status'] ?? '') === 'failed');
t('error_message is stored', str_contains((string)($failedRow['error_message'] ?? ''), 'Download failed'));

// Verify failed URL causes wpBridgeFetchAllMedia to skip re-attempt (returns empty for that URL)
$failedFetchResult = wpBridgeFetchAllMedia(
    'test_media',
    [['attachment_url' => 'https://oldsite.example/broken.jpg']],
    ['https://oldsite.example'],
    $authorId
);
t('failed URL in bridge_media_log is not re-attempted', !isset($failedFetchResult['https://oldsite.example/broken.jpg']));

// ── Restore HTTP_HOST ────────────────────────────────────────────────────
if ($origHost !== null) {
    $_SERVER['HTTP_HOST'] = $origHost;
} else {
    unset($_SERVER['HTTP_HOST']);
}
if ($origHttps !== null) {
    $_SERVER['HTTPS'] = $origHttps;
} else {
    unset($_SERVER['HTTPS']);
}

// ── Summary ──────────────────────────────────────────────────────────────
echo "\n────────────────────────────────────────────────\n";
echo "  PASSED: {$pass}\n";
echo "  FAILED: {$fail}\n";
if (!empty($errors)) {
    echo "\n  Failures:\n";
    foreach ($errors as $e) {
        echo "    ✗ {$e}\n";
    }
}

$logSize = file_exists(STORAGE_PATH . '/logs/error.log') ? filesize(STORAGE_PATH . '/logs/error.log') : 0;
if ($logSize > 0) {
    echo "\n  [WARN] error.log is non-empty ({$logSize} bytes) — check storage/logs/error.log\n";
}

echo "\n";
exit($fail > 0 ? 1 : 0);
