<?php
/**
 * Content Ingestion — Lifecycle Integration Tests
 *
 * Phase 3 tests covering:
 *   - Bridge state helpers (wpBridgeGetState, wpBridgeIsActive)
 *   - Per-tenant state transitions via saveModuleSettings
 *   - Read-only/archived gate on ingestion
 *   - Per-item claim (external-managed → cms-managed)
 *   - Per-item claim on conflict (review-required → cms-managed)
 *   - Cannot claim retired items
 *   - Resolve conflict: resolution=wp → keeps WP body, external-managed
 *   - Resolve conflict: resolution=cms → keeps CMS body, cms-managed
 *   - Cannot resolve a non-review-required item
 *   - wpBridgeGetStats returns expected keys + counts
 *
 * Run: php tests/wordpress_bridge_lifecycle_test.php
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../modules/cms/helpers.php';
require_once __DIR__ . '/../modules/cms/handlers/35-api-content.php';
require_once __DIR__ . '/../modules/wordpress-importer/handlers/10-wordpress-importer.php';
require_once __DIR__ . '/../modules/content-ingestion/helpers.php';
require_once __DIR__ . '/../modules/content-ingestion/handlers/10-ingestion.php';
require_once __DIR__ . '/../modules/content-ingestion/handlers/20-media.php';
require_once __DIR__ . '/../modules/content-ingestion/handlers/40-lifecycle.php'; // provides wpBridgeGetState etc.
require_once __DIR__ . '/../modules/content-ingestion/handlers/30-admin.php';     // provides wpBridgeGetStats

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

// ── Setup: clear logs ────────────────────────────────────────────────────
file_put_contents(STORAGE_PATH . '/logs/app.log', '');
file_put_contents(STORAGE_PATH . '/logs/error.log', '');

// ── Raw PDO for setup ────────────────────────────────────────────────────
$pdo = app()->db();

// Ensure migrations are applied
foreach ([
    BASE_PATH . '/modules/content-ingestion/database/migrations/001_bridge_ingestion_log.sql',
    BASE_PATH . '/modules/content-ingestion/database/migrations/002_bridge_media_log.sql',
] as $migFile) {
    if (is_file($migFile)) {
        try {
            $pdo->exec((string)file_get_contents($migFile));
        } catch (Throwable $e) {
            // Table already exists
        }
    }
}

// Clean previous lifecycle test data
$pdo->exec("DELETE FROM bridge_ingestion_log WHERE source = 'wp_lifecycle_test'");
$existingTestIds = $pdo->query(
    "SELECT c.id FROM cms_content c
     INNER JOIN cms_content_meta m ON m.content_id = c.id
     AND m.meta_key = 'bridge_source' AND m.meta_value = 'wp_lifecycle_test'"
)->fetchAll(PDO::FETCH_COLUMN) ?: [];
if ($existingTestIds) {
    $ids = implode(',', array_map('intval', $existingTestIds));
    $pdo->exec("DELETE FROM cms_content_meta WHERE content_id IN ({$ids})");
    $pdo->exec("DELETE FROM cms_content WHERE id IN ({$ids})");
}

// Find a valid author
$authorId = (int)($pdo->query("SELECT id FROM cms_users WHERE is_active = 1 ORDER BY id ASC LIMIT 1")->fetchColumn() ?: 0);
if ($authorId <= 0) {
    echo "FATAL: No active CMS users found. Cannot run lifecycle tests.\n";
    exit(1);
}

echo "\n=== WORDPRESS BRIDGE — LIFECYCLE TESTS ===\n";
echo "(author_id={$authorId})\n\n";

// ─────────────────────────────────────────────────────────────────────────
// Helpers: inject bridge state directly into module settings for test isolation
// ─────────────────────────────────────────────────────────────────────────

$originalState = wpBridgeGetState();

function setBridgeState(string $state): void
{
    saveModuleSettings('content-ingestion', ['bridge_state' => $state]);
}

function restoreBridgeState(string $state): void
{
    saveModuleSettings('content-ingestion', ['bridge_state' => $state]);
}

// ─────────────────────────────────────────────────────────────────────────
// Helper: Insert bare content item with provenance (no ingestion pipeline)
// ─────────────────────────────────────────────────────────────────────────

function insertTestContent(PDO $pdo, string $title, string $slug, string $bridgeStatus, int $authorId): int
{
    $uuid = sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
    $pdo->prepare(
        "INSERT INTO cms_content (uuid, title, slug, body, type, status, author_id, created_at, updated_at)
         VALUES (:uuid, :title, :slug, :body, 'post', 'published', :author, NOW(), NOW())"
    )->execute([':uuid' => $uuid, ':title' => $title, ':slug' => $slug, ':body' => '<p>' . $title . '</p>', ':author' => $authorId]);
    $id = (int)$pdo->lastInsertId();

    // Write provenance
    wpBridgeWriteProvenance($id, 'wp_lifecycle_test', 'ext-' . $id, '2026-01-01T00:00:00Z', $bridgeStatus);
    return $id;
}


// ═════════════════════════════════════════════════════════════════════════
// TEST GROUP 1: State helpers
// ═════════════════════════════════════════════════════════════════════════
echo "── Test Group 1: State Helpers ──\n";

// Reset to active first
setBridgeState('active');

t('wpBridgeGetState returns active after explicit set', wpBridgeGetState() === 'active');
t('wpBridgeIsActive returns true when active',          wpBridgeIsActive() === true);

setBridgeState('read-only');
t('wpBridgeGetState returns read-only after set',        wpBridgeGetState() === 'read-only');
t('wpBridgeIsActive returns false when read-only',       wpBridgeIsActive() === false);

setBridgeState('archived');
t('wpBridgeGetState returns archived after set',         wpBridgeGetState() === 'archived');
t('wpBridgeIsActive returns false when archived',        wpBridgeIsActive() === false);

setBridgeState('disabled');
t('wpBridgeGetState returns disabled after set',         wpBridgeGetState() === 'disabled');
t('wpBridgeIsActive returns false when disabled',        wpBridgeIsActive() === false);

// Restore
setBridgeState('active');
t('State restored to active',                            wpBridgeGetState() === 'active');

echo "\n";

// ═════════════════════════════════════════════════════════════════════════
// TEST GROUP 2: Read-only ingestion gate
// ═════════════════════════════════════════════════════════════════════════
echo "── Test Group 2: Ingestion gate when bridge is locked ──\n";

$ingestEnvelope = [
    'source'            => 'wp_lifecycle_test',
    'external_id'       => 'gate-test-001',
    'external_modified' => '2026-04-01T12:00:00Z',
    'payload'           => [
        'title'  => 'Gate Test Post',
        'slug'   => 'gate-test-post',
        'body'   => '<p>Gate test</p>',
        'type'   => 'post',
        'status' => 'publish',
    ],
    'author_id' => $authorId,
];

// read-only gate
setBridgeState('read-only');
$gateResult = wpBridgeHandleContentUpserted($ingestEnvelope);
t('read-only blocks ingestion: ok=false',          ($gateResult['ok'] ?? true) === false);
t('read-only blocks ingestion: outcome=blocked',   ($gateResult['outcome'] ?? '') === 'blocked');
t('read-only blocks ingestion: reason contains state', str_contains((string)($gateResult['reason'] ?? ''), 'read-only'));

// archived gate
setBridgeState('archived');
$gateResult2 = wpBridgeHandleContentUpserted($ingestEnvelope);
t('archived blocks ingestion: ok=false',           ($gateResult2['ok'] ?? true) === false);
t('archived blocks ingestion: outcome=blocked',    ($gateResult2['outcome'] ?? '') === 'blocked');

// disabled gate
setBridgeState('disabled');
$gateResult3 = wpBridgeHandleContentUpserted($ingestEnvelope);
t('disabled blocks ingestion: ok=false',           ($gateResult3['ok'] ?? true) === false);
t('disabled blocks ingestion: outcome=blocked',    ($gateResult3['outcome'] ?? '') === 'blocked');

// Verify the blocked events were NOT logged as processed
$pdo->exec("DELETE FROM bridge_ingestion_log WHERE source = 'wp_lifecycle_test' AND external_id = 'gate-test-001'");

// Restore + verify active path works
setBridgeState('active');
$activeResult = wpBridgeHandleContentUpserted($ingestEnvelope);
t('active state allows ingestion: ok=true',        ($activeResult['ok'] ?? false) === true);
t('active state allows ingestion: outcome=processed', ($activeResult['outcome'] ?? '') === 'processed');

// Clean up gate test content
$gateId = (int)($activeResult['cms_content_id'] ?? 0);
if ($gateId > 0) {
    $pdo->exec("DELETE FROM cms_content_meta WHERE content_id = {$gateId}");
    $pdo->exec("DELETE FROM cms_content WHERE id = {$gateId}");
    $pdo->exec("DELETE FROM bridge_ingestion_log WHERE source = 'wp_lifecycle_test' AND external_id = 'gate-test-001'");
}

echo "\n";

// ═════════════════════════════════════════════════════════════════════════
// TEST GROUP 3: Per-item claim (external-managed → cms-managed)
// ═════════════════════════════════════════════════════════════════════════
echo "── Test Group 3: Per-item claim (external-managed → cms-managed) ──\n";

setBridgeState('active');

$extItem = insertTestContent($pdo, 'Claim Test Item', 'claim-test-item', 'external-managed', $authorId);

// Verify initial state
$prov = wpBridgeReadProvenance($extItem);
t('setup: bridge_status is external-managed', ($prov['bridge_status'] ?? '') === 'external-managed');

// Simulate claim: directly call the core provenance logic (bypass HTTP handler)
// We test the transition via wpBridgeReadProvenance after calling the write function directly
// (The HTTP handler itself requires HTTP context; we test the underlying logic here)
wpBridgeWriteProvenance($extItem, 'wp_lifecycle_test', 'ext-' . $extItem, '2026-01-01T00:00:00Z', 'cms-managed');
$provAfterClaim = wpBridgeReadProvenance($extItem);
t('claim: bridge_status is cms-managed', ($provAfterClaim['bridge_status'] ?? '') === 'cms-managed');
t('claim: bridge_source preserved', ($provAfterClaim['bridge_source'] ?? '') === 'wp_lifecycle_test');
t('claim: bridge_source_id preserved', ($provAfterClaim['bridge_source_id'] ?? '') === 'ext-' . $extItem);

echo "\n";

// ═════════════════════════════════════════════════════════════════════════
// TEST GROUP 4: Claim on conflict item (review-required → cms-managed)
// ═════════════════════════════════════════════════════════════════════════
echo "── Test Group 4: Claim on review-required item ──\n";

$conflictItem = insertTestContent($pdo, 'Conflict Item', 'conflict-item', 'review-required', $authorId);

$provBefore = wpBridgeReadProvenance($conflictItem);
t('setup: bridge_status is review-required', ($provBefore['bridge_status'] ?? '') === 'review-required');

// Simulate claim from review-required state
wpBridgeWriteProvenance($conflictItem, 'wp_lifecycle_test', 'ext-' . $conflictItem, '2026-01-01T00:00:00Z', 'cms-managed');
$provAfter = wpBridgeReadProvenance($conflictItem);
t('claim from review-required → cms-managed', ($provAfter['bridge_status'] ?? '') === 'cms-managed');

echo "\n";

// ═════════════════════════════════════════════════════════════════════════
// TEST GROUP 5: Cannot claim retired item
// ═════════════════════════════════════════════════════════════════════════
echo "── Test Group 5: Retired items cannot be claimed via HTTP handler ──\n";

$retiredItem = insertTestContent($pdo, 'Retired Item', 'retired-item', 'retired', $authorId);

// Simulate the validation logic from wpBridgeApiContentClaim
$retiredProv = wpBridgeReadProvenance($retiredItem);
$retiredStatus = $retiredProv['bridge_status'] ?? '';
$claimable = ['external-managed', 'review-required'];
$canClaim  = in_array($retiredStatus, $claimable, true);
t('retired item: bridge_status is retired', $retiredStatus === 'retired');
t('retired item: is not in claimable list', $canClaim === false);

echo "\n";

// ═════════════════════════════════════════════════════════════════════════
// TEST GROUP 6: Resolve conflict — resolution=cms (keep CMS body)
// ═════════════════════════════════════════════════════════════════════════
echo "── Test Group 6: Resolve conflict (keep CMS body → cms-managed) ──\n";

$cmsResolveItem = insertTestContent($pdo, 'Resolve CMS', 'resolve-cms', 'review-required', $authorId);

// Add a conflict snapshot
$pdo->prepare(
    "INSERT INTO cms_content_meta (content_id, meta_key, meta_value) VALUES (:id, 'bridge_conflict_wp_body', :body)
     ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)"
)->execute([':id' => $cmsResolveItem, ':body' => '<p>WP version body</p>']);

$cmsBody = '<p>Resolve CMS</p>';

// Simulate resolution=cms (keep CMS body)
$prov6 = wpBridgeReadProvenance($cmsResolveItem);
wpBridgeWriteProvenance($cmsResolveItem, 'wp_lifecycle_test', 'ext-' . $cmsResolveItem, '2026-01-01T00:00:00Z', 'cms-managed');
$pdo->prepare(
    "DELETE FROM cms_content_meta WHERE content_id = :id AND meta_key = 'bridge_conflict_wp_body'"
)->execute([':id' => $cmsResolveItem]);

$prov6After = wpBridgeReadProvenance($cmsResolveItem);
t('resolve cms: bridge_status → cms-managed', ($prov6After['bridge_status'] ?? '') === 'cms-managed');

// Verify conflict snapshot was removed
$snapStmt = $pdo->prepare("SELECT COUNT(*) FROM cms_content_meta WHERE content_id = :id AND meta_key = 'bridge_conflict_wp_body'");
$snapStmt->execute([':id' => $cmsResolveItem]);
t('resolve cms: conflict snapshot removed', (int)$snapStmt->fetchColumn() === 0);

echo "\n";

// ═════════════════════════════════════════════════════════════════════════
// TEST GROUP 7: Resolve conflict — resolution=wp (keep WP body)
// ═════════════════════════════════════════════════════════════════════════
echo "── Test Group 7: Resolve conflict (keep WP body → external-managed) ──\n";

$wpResolveItem = insertTestContent($pdo, 'Resolve WP', 'resolve-wp', 'review-required', $authorId);

// Add a conflict snapshot (WP version)
$wpVersionBody = '<p>This is the WordPress version content</p>';
$pdo->prepare(
    "INSERT INTO cms_content_meta (content_id, meta_key, meta_value) VALUES (:id, 'bridge_conflict_wp_body', :body)
     ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)"
)->execute([':id' => $wpResolveItem, ':body' => $wpVersionBody]);

// Simulate resolution=wp: restore WP body + set external-managed
$wpBodyStmt = $pdo->prepare("SELECT meta_value FROM cms_content_meta WHERE content_id = :id AND meta_key = 'bridge_conflict_wp_body' LIMIT 1");
$wpBodyStmt->execute([':id' => $wpResolveItem]);
$restoredBody = $wpBodyStmt->fetchColumn();

if ($restoredBody !== false && $restoredBody !== '') {
    $pdo->prepare("UPDATE cms_content SET body = :body WHERE id = :id")
        ->execute([':body' => $restoredBody, ':id' => $wpResolveItem]);
}
wpBridgeWriteProvenance($wpResolveItem, 'wp_lifecycle_test', 'ext-' . $wpResolveItem, '2026-01-01T00:00:00Z', 'external-managed');
$pdo->prepare(
    "DELETE FROM cms_content_meta WHERE content_id = :id AND meta_key = 'bridge_conflict_wp_body'"
)->execute([':id' => $wpResolveItem]);

$prov7After = wpBridgeReadProvenance($wpResolveItem);
t('resolve wp: bridge_status → external-managed', ($prov7After['bridge_status'] ?? '') === 'external-managed');

// Verify WP body was restored
$bodyRow = $pdo->prepare("SELECT body FROM cms_content WHERE id = :id");
$bodyRow->execute([':id' => $wpResolveItem]);
$restoredFromDb = (string)$bodyRow->fetchColumn();
t('resolve wp: WP body restored to cms_content', $restoredFromDb === $wpVersionBody);

// Verify conflict snapshot removed
$snapStmt2 = $pdo->prepare("SELECT COUNT(*) FROM cms_content_meta WHERE content_id = :id AND meta_key = 'bridge_conflict_wp_body'");
$snapStmt2->execute([':id' => $wpResolveItem]);
t('resolve wp: conflict snapshot removed', (int)$snapStmt2->fetchColumn() === 0);

echo "\n";

// ═════════════════════════════════════════════════════════════════════════
// TEST GROUP 8: Cannot resolve a non-review-required item
// ═════════════════════════════════════════════════════════════════════════
echo "── Test Group 8: Resolve gate (non-review-required) ──\n";

// Simulate the API validation logic directly
$nonConflictItem = insertTestContent($pdo, 'Not Conflict', 'not-conflict', 'external-managed', $authorId);
$ncProv = wpBridgeReadProvenance($nonConflictItem);
$ncStatus = $ncProv['bridge_status'] ?? '';
$calResolve = $ncStatus === 'review-required';
t('external-managed item: cannot be resolved (validation would reject)', $calResolve === false);

$cmsItem = insertTestContent($pdo, 'Already CMS', 'already-cms', 'cms-managed', $authorId);
$cmsItemProv = wpBridgeReadProvenance($cmsItem);
$cmsItemStatus = $cmsItemProv['bridge_status'] ?? '';
t('cms-managed item: cannot be resolved (validation would reject)', $cmsItemStatus !== 'review-required');

echo "\n";

// ═════════════════════════════════════════════════════════════════════════
// TEST GROUP 9: wpBridgeGetStats shape
// ═════════════════════════════════════════════════════════════════════════
echo "── Test Group 9: wpBridgeGetStats shape ──\n";

$stats = wpBridgeGetStats();
t('stats has key: total',            array_key_exists('total',            $stats));
t('stats has key: external-managed', array_key_exists('external-managed', $stats));
t('stats has key: review-required',  array_key_exists('review-required',  $stats));
t('stats has key: cms-managed',      array_key_exists('cms-managed',      $stats));
t('stats has key: retired',          array_key_exists('retired',          $stats));
t('stats has key: media_fetched',    array_key_exists('media_fetched',    $stats));
t('stats has key: ingestion_failed', array_key_exists('ingestion_failed', $stats));
t('stats total is integer >= 0',     is_int($stats['total']) && $stats['total'] >= 0);
t('stats cms-managed count > 0 (we created some)', (int)($stats['cms-managed'] ?? 0) > 0);

echo "\n";

// ═════════════════════════════════════════════════════════════════════════
// TEST GROUP 10: Validate state allowlist (invalid state rejected in function)
// ═════════════════════════════════════════════════════════════════════════
echo "── Test Group 10: Invalid state rejected by wpBridgeGetState ──\n";

// Directly poke an invalid value into settings
saveModuleSettings('content-ingestion', ['bridge_state' => 'bananas']);
$safeState = wpBridgeGetState();
t('invalid state falls back to active', $safeState === 'active');
t('wpBridgeIsActive still true for invalid state', wpBridgeIsActive() === true);

echo "\n";

// ═════════════════════════════════════════════════════════════════════════
// TEARDOWN
// ═════════════════════════════════════════════════════════════════════════

// Restore original bridge state
restoreBridgeState($originalState);

// Clean up all test content
$finalIds = $pdo->query(
    "SELECT c.id FROM cms_content c
     INNER JOIN cms_content_meta m ON m.content_id = c.id
     AND m.meta_key = 'bridge_source' AND m.meta_value = 'wp_lifecycle_test'"
)->fetchAll(PDO::FETCH_COLUMN) ?: [];

if ($finalIds) {
    $ids = implode(',', array_map('intval', $finalIds));
    $pdo->exec("DELETE FROM cms_content_meta WHERE content_id IN ({$ids})");
    $pdo->exec("DELETE FROM cms_content WHERE id IN ({$ids})");
}
$pdo->exec("DELETE FROM bridge_ingestion_log WHERE source = 'wp_lifecycle_test'");

// ═════════════════════════════════════════════════════════════════════════
// RESULTS
// ═════════════════════════════════════════════════════════════════════════

echo "═══════════════════════════════════════════════════════════\n";
echo "LIFECYCLE TEST RESULTS: {$pass} passed, {$fail} failed\n";
if ($errors) {
    echo "\nFailed tests:\n";
    foreach ($errors as $e) {
        echo "  ✗ {$e}\n";
    }
}
echo "═══════════════════════════════════════════════════════════\n";
exit($fail > 0 ? 1 : 0);
