<?php
/**
 * WordPress Bridge — Ingestion Pipeline Integration Test
 *
 * Tests the core spine: idempotency → normalize → capability write → provenance → log.
 * Uses messy data to validate real-world edge cases.
 *
 * Run: php tests/wordpress_bridge_ingestion_test.php
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../modules/cms/helpers.php';

// Load only the specific CMS handler file containing cmsSaveMeta
require_once __DIR__ . '/../modules/cms/handlers/35-api-content.php';

// Load wordpress-importer for normalization functions
require_once __DIR__ . '/../modules/wordpress-importer/handlers/10-wordpress-importer.php';

// Load bridge module helpers + handlers
require_once __DIR__ . '/../modules/wordpress-bridge/helpers.php';
require_once __DIR__ . '/../modules/wordpress-bridge/handlers/10-ingestion.php';

// Register CMS capabilities with the capability bus (normally done by module loader)
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

// ── Setup: clear logs ────────────────────────────────────────────────────
file_put_contents(STORAGE_PATH . '/logs/app.log', '');
file_put_contents(STORAGE_PATH . '/logs/error.log', '');

// ── Raw PDO for migration DDL + test setup (ModuleDB blocks DDL) ─────────
$pdo = app()->db();

// ── Ensure bridge_ingestion_log table exists ─────────────────────────────
$migrationSql = (string)file_get_contents(BASE_PATH . '/modules/wordpress-bridge/database/migrations/001_bridge_ingestion_log.sql');
try {
    $pdo->exec($migrationSql);
} catch (Throwable $e) {
    // Table may already exist
}

// ── Clean up any previous test data ──────────────────────────────────────
$pdo->exec("DELETE FROM bridge_ingestion_log WHERE source = 'wordpress_test'");
// Find and delete test content by provenance
$testContent = $pdo->query(
    "SELECT c.id FROM cms_content c
     INNER JOIN cms_content_meta m ON m.content_id = c.id
     AND m.meta_key = 'bridge_source' AND m.meta_value = 'wordpress_test'"
)->fetchAll(PDO::FETCH_COLUMN) ?: [];
if (!empty($testContent)) {
    $ids = implode(',', array_map('intval', $testContent));
    $pdo->exec("DELETE FROM cms_content_meta WHERE content_id IN ({$ids})");
    $pdo->exec("DELETE FROM cms_content_categories WHERE content_id IN ({$ids})");
    $pdo->exec("DELETE FROM cms_content_tags WHERE content_id IN ({$ids})");
    $pdo->exec("DELETE FROM cms_content WHERE id IN ({$ids})");
}

// ── Find a valid author ID ───────────────────────────────────────────────
$authorId = (int)($pdo->query("SELECT id FROM cms_users WHERE is_active = 1 ORDER BY id ASC LIMIT 1")->fetchColumn() ?: 0);
if ($authorId <= 0) {
    echo "FATAL: No CMS users found. Cannot run bridge tests.\n";
    exit(1);
}

echo "\n=== WORDPRESS BRIDGE — INGESTION PIPELINE TESTS ===\n";
echo "(author_id={$authorId})\n\n";

// ═════════════════════════════════════════════════════════════════════════
// TEST 1: Basic content ingestion (create path)
// ═════════════════════════════════════════════════════════════════════════
echo "── Test 1: Basic content creation ──\n";

$result1 = wpBridgeHandleContentUpserted([
    'source'            => 'wordpress_test',
    'external_id'       => '100',
    'external_modified' => '2026-04-10T09:00:00Z',
    'payload'           => [
        'title'  => 'Bridge Test Post One',
        'slug'   => 'bridge-test-post-one',
        'body'   => '<p>This is test content from the bridge pipeline.</p>',
        'excerpt'=> 'Test excerpt',
        'type'   => 'post',
        'status' => 'publish',
        'categories' => ['News', 'Updates'],
        'tags'       => ['test', 'bridge'],
    ],
    'author_id' => $authorId,
]);

t('create returns ok=true', !empty($result1['ok']));
t('create outcome is processed', ($result1['outcome'] ?? '') === 'processed');
t('create action is create', ($result1['action'] ?? '') === 'create');
t('create returns cms_content_id', ($result1['cms_content_id'] ?? 0) > 0);

$contentId1 = (int)($result1['cms_content_id'] ?? 0);

// Verify content exists in DB
if ($contentId1 > 0) {
    $stmt = $pdo->prepare("SELECT * FROM cms_content WHERE id = :id");
    $stmt->execute([':id' => $contentId1]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    t('content exists in cms_content', is_array($row));
    t('title matches', ($row['title'] ?? '') === 'Bridge Test Post One');
    t('status normalized to published', ($row['status'] ?? '') === 'published');
}

// Verify provenance metadata
if ($contentId1 > 0) {
    $provenance = wpBridgeReadProvenance($contentId1);
    t('provenance bridge_source is wordpress_test', ($provenance['bridge_source'] ?? '') === 'wordpress_test');
    t('provenance bridge_source_id is 100', ($provenance['bridge_source_id'] ?? '') === '100');
    t('provenance bridge_status is external-managed', ($provenance['bridge_status'] ?? '') === 'external-managed');
    t('provenance bridge_synced_at exists', isset($provenance['bridge_synced_at']));
}

// Verify ingestion log
$logStmt = $pdo->prepare("SELECT * FROM bridge_ingestion_log WHERE source = 'wordpress_test' AND external_id = '100' LIMIT 1");
$logStmt->execute();
$logRow = $logStmt->fetch(PDO::FETCH_ASSOC);
t('ingestion log entry exists', is_array($logRow));
t('ingestion log status is processed', ($logRow['status'] ?? '') === 'processed');
t('ingestion log has cms_content_id', (int)($logRow['cms_content_id'] ?? 0) === $contentId1);

echo "\n";

// ═════════════════════════════════════════════════════════════════════════
// TEST 2: Idempotency — re-ingest same event
// ═════════════════════════════════════════════════════════════════════════
echo "── Test 2: Idempotency (duplicate skip) ──\n";

$result2 = wpBridgeHandleContentUpserted([
    'source'            => 'wordpress_test',
    'external_id'       => '100',
    'external_modified' => '2026-04-10T09:00:00Z', // same timestamp
    'payload'           => [
        'title'  => 'Bridge Test Post One MODIFIED',
        'slug'   => 'bridge-test-post-one',
        'body'   => '<p>This should NOT be written — duplicate event.</p>',
        'type'   => 'post',
        'status' => 'publish',
    ],
    'author_id' => $authorId,
]);

t('duplicate returns ok=true', !empty($result2['ok']));
t('duplicate outcome is skipped', ($result2['outcome'] ?? '') === 'skipped');
t('duplicate reason is duplicate', ($result2['reason'] ?? '') === 'duplicate');

// Verify content was NOT modified
if ($contentId1 > 0) {
    $stmt = $pdo->prepare("SELECT title FROM cms_content WHERE id = :id");
    $stmt->execute([':id' => $contentId1]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    t('content title unchanged after duplicate', ($row['title'] ?? '') === 'Bridge Test Post One');
}

echo "\n";

// ═════════════════════════════════════════════════════════════════════════
// TEST 3: Idempotency — stale (older) event
// ═════════════════════════════════════════════════════════════════════════
echo "── Test 3: Idempotency (stale skip) ──\n";

$result3 = wpBridgeHandleContentUpserted([
    'source'            => 'wordpress_test',
    'external_id'       => '100',
    'external_modified' => '2026-04-09T08:00:00Z', // OLDER timestamp
    'payload'           => [
        'title'  => 'Bridge Test Post One STALE',
        'slug'   => 'bridge-test-post-one',
        'body'   => '<p>This should NOT be written — stale event.</p>',
        'type'   => 'post',
        'status' => 'publish',
    ],
    'author_id' => $authorId,
]);

t('stale returns ok=true', !empty($result3['ok']));
t('stale outcome is stale', ($result3['outcome'] ?? '') === 'stale');
t('stale reason is out-of-order', ($result3['reason'] ?? '') === 'out-of-order');

echo "\n";

// ═════════════════════════════════════════════════════════════════════════
// TEST 4: Update path — newer event for same external_id
// ═════════════════════════════════════════════════════════════════════════
echo "── Test 4: Update path (newer event) ──\n";

$result4 = wpBridgeHandleContentUpserted([
    'source'            => 'wordpress_test',
    'external_id'       => '100',
    'external_modified' => '2026-04-11T12:00:00Z', // NEWER timestamp
    'payload'           => [
        'title'  => 'Bridge Test Post One — Updated',
        'slug'   => 'bridge-test-post-one',
        'body'   => '<p>Updated content from bridge.</p>',
        'excerpt'=> 'Updated excerpt',
        'type'   => 'post',
        'status' => 'published',
    ],
    'author_id' => $authorId,
]);

t('update returns ok=true', !empty($result4['ok']));
t('update outcome is processed', ($result4['outcome'] ?? '') === 'processed');
t('update action is update', ($result4['action'] ?? '') === 'update');

// Verify content was updated
if ($contentId1 > 0) {
    $stmt = $pdo->prepare("SELECT title, body FROM cms_content WHERE id = :id");
    $stmt->execute([':id' => $contentId1]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    t('title updated', ($row['title'] ?? '') === 'Bridge Test Post One — Updated');
    t('body updated', str_contains(($row['body'] ?? ''), 'Updated content from bridge'));
}

// Verify provenance updated
if ($contentId1 > 0) {
    $provenance = wpBridgeReadProvenance($contentId1);
    t('provenance bridge_source_modified updated', ($provenance['bridge_source_modified'] ?? '') === '2026-04-11T12:00:00Z');
}

echo "\n";

// ═════════════════════════════════════════════════════════════════════════
// TEST 5: Messy data — missing slug, bad status, empty excerpt
// ═════════════════════════════════════════════════════════════════════════
echo "── Test 5: Messy data handling ──\n";

$result5 = wpBridgeHandleContentUpserted([
    'source'            => 'wordpress_test',
    'external_id'       => '200',
    'external_modified' => '2026-04-10T10:00:00Z',
    'payload'           => [
        'title'  => 'Messy Post — Special Characters & Ünïcödé!',
        'slug'   => '', // empty slug — should auto-generate
        'body'   => '<p>Content with <script>alert("xss")</script> and <b>bold</b></p>',
        'excerpt'=> '',
        'type'   => 'post',
        'status' => 'pending', // WP status that maps to 'pending_review'
    ],
    'author_id' => $authorId,
]);

t('messy create returns ok=true', !empty($result5['ok']));
t('messy create outcome is processed', ($result5['outcome'] ?? '') === 'processed');

$contentId5 = (int)($result5['cms_content_id'] ?? 0);
if ($contentId5 > 0) {
    $stmt = $pdo->prepare("SELECT * FROM cms_content WHERE id = :id");
    $stmt->execute([':id' => $contentId5]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    t('auto-generated slug is not empty', trim((string)($row['slug'] ?? '')) !== '');
    t('status normalized from pending to draft', ($row['status'] ?? '') === 'draft');
    t('body does not contain raw script tag', !str_contains(($row['body'] ?? ''), '<script>'));
}

echo "\n";

// ═════════════════════════════════════════════════════════════════════════
// TEST 6: Missing title — should fail gracefully
// ═════════════════════════════════════════════════════════════════════════
echo "── Test 6: Validation failure (missing title) ──\n";

$result6 = wpBridgeHandleContentUpserted([
    'source'            => 'wordpress_test',
    'external_id'       => '300',
    'external_modified' => '2026-04-10T11:00:00Z',
    'payload'           => [
        'title'  => '',
        'slug'   => 'no-title-post',
        'body'   => '<p>This post has no title.</p>',
        'type'   => 'post',
        'status' => 'draft',
    ],
    'author_id' => $authorId,
]);

t('missing title returns ok=false', empty($result6['ok']));
t('missing title outcome is failed', ($result6['outcome'] ?? '') === 'failed');
t('missing title error mentions title', str_contains(($result6['error'] ?? ''), 'title'));

// Verify it was logged as failed
$logStmt = $pdo->prepare("SELECT status FROM bridge_ingestion_log WHERE source = 'wordpress_test' AND external_id = '300' LIMIT 1");
$logStmt->execute();
$logRow = $logStmt->fetch(PDO::FETCH_ASSOC);
t('failed item logged in bridge_ingestion_log', ($logRow['status'] ?? '') === 'failed');

echo "\n";

// ═════════════════════════════════════════════════════════════════════════
// TEST 7: Second item — verify no slug collision
// ═════════════════════════════════════════════════════════════════════════
echo "── Test 7: Slug uniqueness ──\n";

$result7 = wpBridgeHandleContentUpserted([
    'source'            => 'wordpress_test',
    'external_id'       => '400',
    'external_modified' => '2026-04-10T12:00:00Z',
    'payload'           => [
        'title'  => 'Bridge Test Post One', // Same title as test 1
        'slug'   => 'bridge-test-post-one', // Same slug as test 1
        'body'   => '<p>Different post, same slug.</p>',
        'type'   => 'post',
        'status' => 'draft',
    ],
    'author_id' => $authorId,
]);

t('slug collision create returns ok=true', !empty($result7['ok']));
$contentId7 = (int)($result7['cms_content_id'] ?? 0);
if ($contentId7 > 0) {
    $stmt = $pdo->prepare("SELECT slug FROM cms_content WHERE id = :id");
    $stmt->execute([':id' => $contentId7]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    t('slug was de-duplicated (not same as original)', ($row['slug'] ?? '') !== 'bridge-test-post-one');
    t('slug starts with original base', str_starts_with(($row['slug'] ?? ''), 'bridge-test-post-one'));
}

echo "\n";

// ═════════════════════════════════════════════════════════════════════════
// TEST 8: Conflict detection — CMS-managed content should be skipped
// ═════════════════════════════════════════════════════════════════════════
echo "── Test 8: CMS-managed content skip ──\n";

// Create a content item and mark it as cms-managed
$result8a = wpBridgeHandleContentUpserted([
    'source'            => 'wordpress_test',
    'external_id'       => '500',
    'external_modified' => '2026-04-10T13:00:00Z',
    'payload'           => [
        'title'  => 'CMS Managed Post',
        'slug'   => 'cms-managed-post',
        'body'   => '<p>Original content.</p>',
        'type'   => 'post',
        'status' => 'draft',
    ],
    'author_id' => $authorId,
]);

$contentId8 = (int)($result8a['cms_content_id'] ?? 0);
if ($contentId8 > 0) {
    // Manually set bridge_status to cms-managed (simulates user claiming the item)
    wpBridgeWriteProvenance($contentId8, 'wordpress_test', '500', '2026-04-10T13:00:00Z', 'cms-managed');
}

$result8b = wpBridgeHandleContentUpserted([
    'source'            => 'wordpress_test',
    'external_id'       => '500',
    'external_modified' => '2026-04-11T14:00:00Z', // Newer — would normally update
    'payload'           => [
        'title'  => 'CMS Managed Post — WP Update',
        'slug'   => 'cms-managed-post',
        'body'   => '<p>This should NOT overwrite cms-managed content.</p>',
        'type'   => 'post',
        'status' => 'publish',
    ],
    'author_id' => $authorId,
]);

t('cms-managed skip returns ok=true', !empty($result8b['ok']));
t('cms-managed outcome is skipped', ($result8b['outcome'] ?? '') === 'skipped');
t('cms-managed reason is cms-managed', ($result8b['reason'] ?? '') === 'cms-managed');

// Verify title was NOT changed
if ($contentId8 > 0) {
    $stmt = $pdo->prepare("SELECT title FROM cms_content WHERE id = :id");
    $stmt->execute([':id' => $contentId8]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    t('cms-managed title unchanged', ($row['title'] ?? '') === 'CMS Managed Post');
}

echo "\n";

// ═════════════════════════════════════════════════════════════════════════
// SUMMARY
// ═════════════════════════════════════════════════════════════════════════

// ── Clean up test data ───────────────────────────────────────────────────
$testContentIds = $pdo->query(
    "SELECT c.id FROM cms_content c
     INNER JOIN cms_content_meta m ON m.content_id = c.id
     AND m.meta_key = 'bridge_source' AND m.meta_value = 'wordpress_test'"
)->fetchAll(PDO::FETCH_COLUMN) ?: [];
if (!empty($testContentIds)) {
    $ids = implode(',', array_map('intval', $testContentIds));
    $pdo->exec("DELETE FROM cms_content_meta WHERE content_id IN ({$ids})");
    $pdo->exec("DELETE FROM cms_content_categories WHERE content_id IN ({$ids})");
    $pdo->exec("DELETE FROM cms_content_tags WHERE content_id IN ({$ids})");
    $pdo->exec("DELETE FROM cms_content WHERE id IN ({$ids})");
}
$pdo->exec("DELETE FROM bridge_ingestion_log WHERE source = 'wordpress_test'");

echo "=== RESULTS: {$pass} passed, {$fail} failed ===\n";
if (!empty($errors)) {
    echo "\nFailed tests:\n";
    foreach ($errors as $err) {
        echo "  - {$err}\n";
    }
}

// Check logs for errors
$appLog = (string)file_get_contents(STORAGE_PATH . '/logs/app.log');
$errorLog = (string)file_get_contents(STORAGE_PATH . '/logs/error.log');
if (trim($errorLog) !== '') {
    echo "\n⚠ PHP error log is not empty:\n" . substr($errorLog, 0, 500) . "\n";
}

exit($fail > 0 ? 1 : 0);
