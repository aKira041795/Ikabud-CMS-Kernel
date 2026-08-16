<?php
/**
 * CMS Module — Comprehensive CRUD Test
 * Tests all API endpoints with authenticated CMS user.
 * Run: php tests/cms_crud_test.php
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../modules/cms/helpers.php';
require_once __DIR__ . '/../modules/cms/handlers.php';

// Register CMS capability handlers for direct test execution.
$cmsManifest = json_decode((string)file_get_contents(__DIR__ . '/../modules/cms/module.json'), true);
$capabilityMeta = [];
foreach ((array)($cmsManifest['capabilities']['exposes'] ?? []) as $definition) {
    if (!is_array($definition)) {
        continue;
    }

    $capabilityId = trim((string)($definition['id'] ?? ''));
    if ($capabilityId === '') {
        continue;
    }

    $modes = $definition['modes'] ?? ['first'];
    $capabilityMeta[$capabilityId] = [
        'priority' => (int)($definition['priority'] ?? 100),
        'modes' => is_array($modes) && $modes !== [] ? $modes : ['first'],
    ];
}

foreach (cms_capability_handlers() as $capabilityId => $handler) {
    if (!is_string($handler) || !function_exists($handler)) {
        continue;
    }

    $meta = $capabilityMeta[$capabilityId] ?? ['priority' => 100, 'modes' => ['first']];
    try {
        app()->capabilities()->register(
            $capabilityId,
            'cms',
            $handler,
            (int)($meta['priority'] ?? 100),
            (array)($meta['modes'] ?? ['first'])
        );
    } catch (Throwable $e) {
        // Test bootstrap can run repeatedly in a dirty process; ignore duplicates.
    }
}

$db = app()->db();
$authUsername = 'cmsadmin';
$authEmail = 'admin@cms.local';
$authPassword = 'password';
$authPasswordHash = password_hash($authPassword, PASSWORD_DEFAULT);

$existingAuthStmt = $db->prepare('SELECT id FROM cms_users WHERE username = :username LIMIT 1');
$existingAuthStmt->execute([':username' => $authUsername]);
$existingAuthId = (int)($existingAuthStmt->fetchColumn() ?: 0);
if ($existingAuthId > 0) {
    $db->prepare(
        'UPDATE cms_users
         SET email = :email,
             password_hash = :password_hash,
             display_name = :display_name,
             role = :role,
             is_active = 1
         WHERE id = :id'
    )->execute([
        ':email' => $authEmail,
        ':password_hash' => $authPasswordHash,
        ':display_name' => 'CMS Admin',
        ':role' => 'administrator',
        ':id' => $existingAuthId,
    ]);
} else {
    $db->prepare(
        'INSERT INTO cms_users (username, email, password_hash, display_name, role, is_active, created_at)
         VALUES (:username, :email, :password_hash, :display_name, :role, 1, NOW())'
    )->execute([
        ':username' => $authUsername,
        ':email' => $authEmail,
        ':password_hash' => $authPasswordHash,
        ':display_name' => 'CMS Admin',
        ':role' => 'administrator',
    ]);
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
        $errors[] = $label . ($detail ? ": {$detail}" : '');
        echo "  ✗ {$label}" . ($detail ? " — {$detail}" : '') . "\n";
    }
}

// ── Clear logs ──────────────────────────────────────────────────────
file_put_contents(STORAGE_PATH . '/logs/app.log', '');
file_put_contents(STORAGE_PATH . '/logs/error.log', '');

// ── Auth pipeline ──────────────────────────────────────────────────
echo "\n=== AUTH PIPELINE ===\n";
$authResult = app()->cap()->call('kernel.auth.authenticate@1', [
    'username' => $authUsername,
    'password' => $authPassword,
], ['mode' => 'pipeline', 'strict_pipeline' => false]);

t('CMS login via pipeline returns array', is_array($authResult) && isset($authResult['user']));
t('Auth source is cms', ($authResult['source'] ?? '') === 'cms');
t('Auth user role is administrator', ($authResult['user']['role'] ?? '') === 'administrator');
t('Auth user has full_name', ($authResult['user']['full_name'] ?? '') !== '');

$cmsUserId = (int)($authResult['user']['id'] ?? 0);
t('Auth user id > 0', $cmsUserId > 0, "id={$cmsUserId}");

// ── CONTENT CRUD ───────────────────────────────────────────────────
echo "\n=== CONTENT CRUD ===\n";

// Create
$uuid = cmsUuid();
$slug = cmsSlugify('Test Post From Script');
$slug = cmsEnsureUniqueSlug($slug, 'post');
$db->prepare(
    "INSERT INTO cms_content (uuid, title, slug, body, excerpt, type, status, author_id, created_at)
     VALUES (:uuid, :title, :slug, :body, :excerpt, 'post', 'draft', :aid, NOW())"
)->execute([
    ':uuid' => $uuid, ':title' => 'Test Post From Script', ':slug' => $slug,
    ':body' => '<p>Test body</p>', ':excerpt' => 'Test excerpt', ':aid' => $cmsUserId,
]);
$contentId = (int)$db->lastInsertId();
t('Content create (INSERT)', $contentId > 0, "id={$contentId}");

// Read
$stmt = $db->prepare("SELECT * FROM cms_content WHERE id = :id AND deleted_at IS NULL");
$stmt->execute([':id' => $contentId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
t('Content read by id', is_array($row) && ($row['title'] ?? '') === 'Test Post From Script');
t('Content slug generated', ($row['slug'] ?? '') === $slug);
t('Content status is draft', ($row['status'] ?? '') === 'draft');

// Update title
$db->prepare("UPDATE cms_content SET title = :t, updated_at = NOW() WHERE id = :id")
   ->execute([':t' => 'Updated Title', ':id' => $contentId]);
$stmt = $db->prepare("SELECT title FROM cms_content WHERE id = :id");
$stmt->execute([':id' => $contentId]);
$updated = $stmt->fetchColumn();
t('Content update title', $updated === 'Updated Title');

// Update status to published
$db->prepare("UPDATE cms_content SET status = 'published', published_at = NOW(), updated_at = NOW() WHERE id = :id")
   ->execute([':id' => $contentId]);
$stmt = $db->prepare("SELECT status, published_at FROM cms_content WHERE id = :id");
$stmt->execute([':id' => $contentId]);
$pubRow = $stmt->fetch(PDO::FETCH_ASSOC);
t('Content publish', ($pubRow['status'] ?? '') === 'published');
t('Content published_at set', !empty($pubRow['published_at']));

// Phase 2: blocks_json storage + rendering
try {
    $blocks = [
        ['type' => 'heading', 'level' => 2, 'text' => 'Hello Blocks'],
        ['type' => 'paragraph', 'text' => "This is a paragraph."],
        ['type' => 'list', 'style' => 'ul', 'items' => ['One', 'Two']],
    ];
    $blocksJson = json_encode($blocks, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $db->prepare("UPDATE cms_content SET blocks_json = :bj WHERE id = :id")
       ->execute([':bj' => $blocksJson, ':id' => $contentId]);

    $stmt = $db->prepare("SELECT blocks_json, body FROM cms_content WHERE id = :id");
    $stmt->execute([':id' => $contentId]);
    $row2 = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    t('blocks_json saved', !empty($row2['blocks_json'] ?? ''));

    $rendered = cmsContentRenderedHtml(['blocks_json' => $row2['blocks_json'], 'body' => $row2['body'] ?? '']);
    t('blocks render produces HTML', is_string($rendered) && str_contains($rendered, 'Hello Blocks') && str_contains($rendered, '<ul>'));
} catch (Throwable $e) {
    t('blocks_json supported (migration applied)', false, $e->getMessage());
}

// Save meta
cmsSaveMeta($db, $contentId, ['seo_title' => 'SEO Title', 'seo_description' => 'SEO Desc']);
$metaStmt = $db->prepare("SELECT meta_value FROM cms_content_meta WHERE content_id = :cid AND meta_key = 'seo_title'");
$metaStmt->execute([':cid' => $contentId]);
t('Content meta save', $metaStmt->fetchColumn() === 'SEO Title');

// Update meta (ON DUPLICATE KEY)
cmsSaveMeta($db, $contentId, ['seo_title' => 'Updated SEO']);
$metaStmt->execute([':cid' => $contentId]);
t('Content meta update (upsert)', $metaStmt->fetchColumn() === 'Updated SEO');

// Soft delete (trash)
$db->prepare("UPDATE cms_content SET status = 'trash', deleted_at = NOW(), updated_at = NOW() WHERE id = :id")
   ->execute([':id' => $contentId]);
$stmt = $db->prepare("SELECT status, deleted_at FROM cms_content WHERE id = :id");
$stmt->execute([':id' => $contentId]);
$trashRow = $stmt->fetch(PDO::FETCH_ASSOC);
t('Content trash (soft delete)', ($trashRow['status'] ?? '') === 'trash' && !empty($trashRow['deleted_at']));

// Restore
$db->prepare("UPDATE cms_content SET status = 'draft', deleted_at = NULL, updated_at = NOW() WHERE id = :id")
   ->execute([':id' => $contentId]);
$stmt = $db->prepare("SELECT status, deleted_at FROM cms_content WHERE id = :id");
$stmt->execute([':id' => $contentId]);
$restoreRow = $stmt->fetch(PDO::FETCH_ASSOC);
t('Content restore', ($restoreRow['status'] ?? '') === 'draft' && $restoreRow['deleted_at'] === null);

// Unique slug enforcement
$slug2 = cmsEnsureUniqueSlug($slug, 'post');
t('Unique slug enforcement', $slug2 !== $slug, "original={$slug} new={$slug2}");

// ── MEDIA CRUD ─────────────────────────────────────────────────────
echo "\n=== MEDIA CRUD ===\n";

// Insert media record (simulate upload)
$mediaFilename = 'test_' . bin2hex(random_bytes(4)) . '.txt';
$mediaRelPath = 'test/' . $mediaFilename;
$uploadDir = cmsUploadsPath() . '/test';
if (!is_dir($uploadDir)) @mkdir($uploadDir, 0775, true);
file_put_contents($uploadDir . '/' . $mediaFilename, 'test content');

$db->prepare(
    "INSERT INTO cms_media (filename, original_name, mime_type, file_size, file_path, uploaded_by, created_at)
     VALUES (:f, :o, :m, :s, :p, :u, NOW())"
)->execute([
    ':f' => $mediaFilename, ':o' => 'test.txt', ':m' => 'text/plain',
    ':s' => 12, ':p' => $mediaRelPath, ':u' => $cmsUserId,
]);
$mediaId = (int)$db->lastInsertId();
t('Media create (INSERT)', $mediaId > 0, "id={$mediaId}");

// Read media
$stmt = $db->prepare("SELECT * FROM cms_media WHERE id = :id");
$stmt->execute([':id' => $mediaId]);
$mediaRow = $stmt->fetch(PDO::FETCH_ASSOC);
t('Media read by id', is_array($mediaRow) && ($mediaRow['original_name'] ?? '') === 'test.txt');
// cmsUploadsUrl() is tenant-aware (appends /t{tenant} when a tenant context is
// active — CI sets APP_TENANT_DEFAULT=1, local CLI has no tenant). Compute the
// expected URL from the same tenant prefix so the assertion is environment-stable.
$expectedMediaUrl = '/assets/modules/cms/uploads';
$mediaTenantId = app()->tenant()->current();
if ($mediaTenantId !== null) {
    $expectedMediaUrl .= '/t' . $mediaTenantId;
}
$expectedMediaUrl .= '/test/' . $mediaFilename;
t('Media URL helper', cmsUploadsUrl($mediaRelPath) === $expectedMediaUrl, 'got ' . cmsUploadsUrl($mediaRelPath));

// Delete media (file + DB)
$filePath = cmsUploadsPath() . '/' . $mediaRelPath;
t('Media file exists before delete', is_file($filePath));
$db->prepare("DELETE FROM cms_media WHERE id = :id")->execute([':id' => $mediaId]);
@unlink($filePath);
$checkStmt = $db->prepare("SELECT COUNT(*) FROM cms_media WHERE id = :id");
$checkStmt->execute([':id' => $mediaId]);
t('Media DB record deleted', (int)$checkStmt->fetchColumn() === 0);
t('Media file deleted', !is_file($filePath));

// ── USER CRUD ──────────────────────────────────────────────────────
echo "\n=== USER CRUD ===\n";

// Create user
$testUsername = 'testuser_' . substr(bin2hex(random_bytes(3)), 0, 6);
$testEmail = $testUsername . '@test.local';
$hash = password_hash('TestPass123', PASSWORD_DEFAULT);
$db->prepare(
    "INSERT INTO cms_users (username, email, password_hash, display_name, role, is_active, created_at)
     VALUES (:u, :e, :h, :d, 'author', 1, NOW())"
)->execute([':u' => $testUsername, ':e' => $testEmail, ':h' => $hash, ':d' => 'Test User']);
$newUserId = (int)$db->lastInsertId();
t('User create', $newUserId > 0, "id={$newUserId}");

// Read user
$stmt = $db->prepare("SELECT * FROM cms_users WHERE id = :id");
$stmt->execute([':id' => $newUserId]);
$userRow = $stmt->fetch(PDO::FETCH_ASSOC);
t('User read', ($userRow['username'] ?? '') === $testUsername);
t('User role is author', ($userRow['role'] ?? '') === 'author');
t('User is active', (int)($userRow['is_active'] ?? 0) === 1);

// Update user role
$db->prepare("UPDATE cms_users SET role = 'editor' WHERE id = :id")->execute([':id' => $newUserId]);
$stmt->execute([':id' => $newUserId]);
$updatedUser = $stmt->fetch(PDO::FETCH_ASSOC);
t('User update role to editor', ($updatedUser['role'] ?? '') === 'editor');

// Deactivate user
$db->prepare("UPDATE cms_users SET is_active = 0 WHERE id = :id")->execute([':id' => $newUserId]);
$stmt->execute([':id' => $newUserId]);
t('User deactivate', (int)$stmt->fetch(PDO::FETCH_ASSOC)['is_active'] === 0);

// Change password
$newHash = password_hash('NewPass456', PASSWORD_DEFAULT);
$db->prepare("UPDATE cms_users SET password_hash = :h WHERE id = :id")->execute([':h' => $newHash, ':id' => $newUserId]);
$stmt = $db->prepare("SELECT password_hash FROM cms_users WHERE id = :id");
$stmt->execute([':id' => $newUserId]);
t('User password change', password_verify('NewPass456', $stmt->fetchColumn()));

// Auth fails for deactivated user
$authResult2 = app()->cap()->call('kernel.auth.authenticate@1', [
    'username' => $testUsername,
    'password' => 'NewPass456',
], ['mode' => 'pipeline', 'strict_pipeline' => false]);
t('Deactivated user cannot login', $authResult2 === null);

// Reactivate and test login
$db->prepare("UPDATE cms_users SET is_active = 1 WHERE id = :id")->execute([':id' => $newUserId]);
$authResult3 = app()->cap()->call('kernel.auth.authenticate@1', [
    'username' => $testUsername,
    'password' => 'NewPass456',
], ['mode' => 'pipeline', 'strict_pipeline' => false]);
t('Reactivated user can login', is_array($authResult3) && ($authResult3['source'] ?? '') === 'cms');

// Cleanup test user
$db->prepare("DELETE FROM cms_users WHERE id = :id")->execute([':id' => $newUserId]);

// ── SETTINGS CRUD ──────────────────────────────────────────────────
echo "\n=== SETTINGS CRUD ===\n";

$oldSettings = getModuleSettings('cms');
saveModuleSettings('cms', ['site_title' => 'Test Site', 'posts_per_page' => '15']);
$s = getModuleSettings('cms');
t('Settings save', ($s['site_title'] ?? '') === 'Test Site');
t('Settings posts_per_page', ($s['posts_per_page'] ?? '') === '15');

// Update setting
saveModuleSettings('cms', ['site_title' => 'Updated Site']);
$s2 = getModuleSettings('cms');
t('Settings update (merge)', ($s2['site_title'] ?? '') === 'Updated Site');
t('Settings other values preserved', ($s2['posts_per_page'] ?? '') === '15');

// Restore original settings
saveModuleSettings('cms', $oldSettings);

// ── ROLE HELPERS ───────────────────────────────────────────────────
echo "\n=== ROLE HELPERS ===\n";
t('superadmin >= administrator', cmsRoleAtLeast('superadmin', 'administrator'));
t('administrator >= editor', cmsRoleAtLeast('administrator', 'editor'));
t('editor >= author', cmsRoleAtLeast('editor', 'author'));
t('author >= contributor', cmsRoleAtLeast('author', 'contributor'));
t('contributor >= subscriber', cmsRoleAtLeast('contributor', 'subscriber'));
t('subscriber NOT >= author', !cmsRoleAtLeast('subscriber', 'author'));
t('contributor NOT >= editor', !cmsRoleAtLeast('contributor', 'editor'));

// ── CAPABILITY HANDLERS ────────────────────────────────────────────
echo "\n=== CAPABILITY HANDLERS ===\n";

// cms.content.get@1
$getResult = cms_cap_cms_content_get_1(['id' => $contentId], 'cms.content.get@1', 'cms');
t('cap cms.content.get@1 returns ok', ($getResult['ok'] ?? false) === true);
t('cap cms.content.get@1 has data', isset($getResult['data']) && ($getResult['data']['id'] ?? 0) == $contentId);

// cms.content.list@1
$listResult = cms_cap_cms_content_list_1(['type' => 'post', 'status' => 'draft', 'limit' => 5], 'cms.content.list@1', 'cms');
t('cap cms.content.list@1 returns ok', ($listResult['ok'] ?? false) === true);
t('cap cms.content.list@1 has data array', is_array($listResult['data'] ?? null));

// cms.content.create@1
$createResult = cms_cap_cms_content_create_1([
    'title' => 'Cap Test Post',
    'type' => 'post',
    'body' => 'Created via capability',
    'author_id' => $cmsUserId,
], 'cms.content.create@1', 'cms');
t('cap cms.content.create@1 returns ok', ($createResult['ok'] ?? false) === true);
$capContentId = $createResult['id'] ?? 0;
t('cap cms.content.create@1 returns id', $capContentId > 0);

// cms.content.get@1 with invalid id
$getInvalid = cms_cap_cms_content_get_1(['id' => 999999], 'cms.content.get@1', 'cms');
t('cap cms.content.get@1 invalid id returns error', ($getInvalid['ok'] ?? true) === false);

// ── CLEANUP ────────────────────────────────────────────────────────
echo "\n=== CLEANUP ===\n";
$db->prepare("DELETE FROM cms_content_meta WHERE content_id IN (:id1, :id2)")->execute([':id1' => $contentId, ':id2' => $capContentId]);
$db->prepare("DELETE FROM cms_content WHERE id IN (:id1, :id2)")->execute([':id1' => $contentId, ':id2' => $capContentId]);
@rmdir(cmsUploadsPath() . '/test');
echo "  Cleaned up test data.\n";

// ── CHECK LOGS ─────────────────────────────────────────────────────
echo "\n=== LOG CHECK ===\n";
$appLog = file_get_contents(STORAGE_PATH . '/logs/app.log') ?: '';
$errLog = file_get_contents(STORAGE_PATH . '/logs/error.log') ?: '';

$appWarnings = array_filter(explode("\n", $appLog), fn($l) => str_contains($l, '[warning]') || str_contains($l, '[error]'));
$phpErrors = array_filter(explode("\n", $errLog), fn($l) => trim($l) !== '');

t('No app.log warnings/errors', empty($appWarnings), empty($appWarnings) ? '' : 'Found: ' . count($appWarnings));
t('No PHP errors in error.log', empty($phpErrors), empty($phpErrors) ? '' : 'Found: ' . count($phpErrors));

if (!empty($appWarnings)) {
    echo "\n  app.log warnings/errors:\n";
    foreach (array_slice($appWarnings, 0, 5) as $w) echo "    " . trim($w) . "\n";
}
if (!empty($phpErrors)) {
    echo "\n  error.log entries:\n";
    foreach (array_slice($phpErrors, 0, 5) as $e) echo "    " . trim($e) . "\n";
}

// ── SUMMARY ────────────────────────────────────────────────────────
echo "\n" . str_repeat('═', 50) . "\n";
echo "  PASS: {$pass}  FAIL: {$fail}\n";
echo str_repeat('═', 50) . "\n";

if (!empty($errors)) {
    echo "\n  Failed tests:\n";
    foreach ($errors as $e) echo "    ✗ {$e}\n";
}

exit($fail > 0 ? 1 : 0);
