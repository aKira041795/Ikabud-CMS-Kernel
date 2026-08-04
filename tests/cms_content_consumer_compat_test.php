<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../modules/cms/helpers.php';
require_once __DIR__ . '/../modules/content-ingestion/helpers.php';
require_once __DIR__ . '/../modules/content-ingestion/handlers/10-ingestion.php';
require_once __DIR__ . '/../modules/content-ingestion/handlers/40-lifecycle.php';

$pass = 0;
$fail = 0;
$errors = [];

function t(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $errors;
    if ($ok) {
        $pass++;
        echo "PASS: {$label}\n";
        return;
    }

    $fail++;
    $errors[] = $label . ($detail !== '' ? ": {$detail}" : '');
    echo "FAIL: {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

function registerCmsCapabilitiesForTest(): void
{
    $handlers = cms_capability_handlers();
    foreach ($handlers as $capabilityId => $handlerFn) {
        if (!is_string($handlerFn) || !function_exists($handlerFn)) {
            continue;
        }
        try {
            app()->capabilities()->register($capabilityId, 'cms', $handlerFn, 100, ['first']);
        } catch (Throwable $e) {
            // Already registered in this process; safe to continue.
        }
    }
}

function applyBridgeMigrations(PDO $pdo): void
{
    $migrationFiles = [
        BASE_PATH . '/modules/content-ingestion/database/migrations/001_bridge_ingestion_log.sql',
        BASE_PATH . '/modules/content-ingestion/database/migrations/002_bridge_media_log.sql',
    ];
    foreach ($migrationFiles as $file) {
        if (!is_file($file)) {
            continue;
        }
        try {
            $pdo->exec((string) file_get_contents($file));
        } catch (Throwable $e) {
            // Existing tables are expected in repeated local runs.
        }
    }
}

registerCmsCapabilitiesForTest();

file_put_contents(STORAGE_PATH . '/logs/app.log', '');
file_put_contents(STORAGE_PATH . '/logs/error.log', '');

$pdo = app()->db();
applyBridgeMigrations($pdo);

$themeModuleJson = BASE_PATH . '/modules/theme-studio/module.json';
$themeManifest = json_decode((string) file_get_contents($themeModuleJson), true);
$themeDependsCaps = $themeManifest['capabilities']['depends'] ?? [];

t(
    'theme-studio declares cms.content.get@1 dependency',
    is_array($themeDependsCaps) && in_array('cms.content.get@1', $themeDependsCaps, true)
);

$authorId = (int) ($pdo->query("SELECT id FROM cms_users WHERE is_active = 1 ORDER BY id ASC LIMIT 1")->fetchColumn() ?: 0);
if ($authorId <= 0) {
    echo "FATAL: no active cms_users row found for compatibility test\n";
    exit(1);
}

$token = bin2hex(random_bytes(4));
$source = 'compat_test';
$externalId = 'compat-' . $token;
$slug = 'compat-consumer-' . $token;

$pdo->exec("DELETE FROM bridge_ingestion_log WHERE source = '" . addslashes($source) . "'");

saveModuleSettings('content-ingestion', ['bridge_state' => 'active']);
t('bridge state is active for ingestion path', wpBridgeGetState() === 'active');

$createEnvelope = [
    'source' => $source,
    'external_id' => $externalId,
    'external_modified' => '2026-08-04T10:00:00Z',
    'payload' => [
        'title' => 'Compatibility Create ' . $token,
        'slug' => $slug,
        'body' => '<p>Create payload</p>',
        'excerpt' => 'Create excerpt',
        'type' => 'post',
        'status' => 'publish',
        'categories' => ['compat'],
        'tags' => ['phase1'],
    ],
    'author_id' => $authorId,
];

$createResult = wpBridgeHandleContentUpserted($createEnvelope);
t('content-ingestion create path returns ok=true', ($createResult['ok'] ?? false) === true);
t('content-ingestion create path action=create', ($createResult['action'] ?? '') === 'create');

$cmsContentId = (int) ($createResult['cms_content_id'] ?? 0);
t('content-ingestion create path returns cms_content_id', $cmsContentId > 0);

$getResult = app()->cap()->call('cms.content.get@1', ['id' => $cmsContentId]);
t('cms.content.get@1 callable for theme-studio dependency', ($getResult['ok'] ?? false) === true);
t('cms.content.get@1 returns object data shape', is_array($getResult['data'] ?? null));

$updateEnvelope = [
    'source' => $source,
    'external_id' => $externalId,
    'external_modified' => '2026-08-04T11:00:00Z',
    'payload' => [
        'title' => 'Compatibility Updated ' . $token,
        'slug' => $slug,
        'body' => '<p>Update payload</p>',
        'excerpt' => 'Updated excerpt',
        'type' => 'post',
        'status' => 'publish',
        'categories' => ['compat'],
        'tags' => ['phase1', 'update'],
    ],
    'author_id' => $authorId,
];

$updateResult = wpBridgeHandleContentUpserted($updateEnvelope);
t('content-ingestion update path returns ok=true', ($updateResult['ok'] ?? false) === true);
t('content-ingestion update path action=update', ($updateResult['action'] ?? '') === 'update');
t('content-ingestion update keeps same cms_content_id', (int) ($updateResult['cms_content_id'] ?? 0) === $cmsContentId);

$updatedRow = $pdo->query("SELECT title FROM cms_content WHERE id = {$cmsContentId} LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];
t('content-ingestion update persisted latest title', ($updatedRow['title'] ?? '') === ('Compatibility Updated ' . $token));

$logCount = (int) ($pdo->query("SELECT COUNT(*) FROM bridge_ingestion_log WHERE source = '" . addslashes($source) . "' AND external_id = '" . addslashes($externalId) . "'")->fetchColumn() ?: 0);
t('ingestion log contains create+update entries', $logCount >= 2);

if ($cmsContentId > 0) {
    $pdo->exec("DELETE FROM cms_content_meta WHERE content_id = {$cmsContentId}");
    $pdo->exec("DELETE FROM cms_content WHERE id = {$cmsContentId}");
}
$pdo->exec("DELETE FROM bridge_ingestion_log WHERE source = '" . addslashes($source) . "'");

echo "\n{$pass} passed, {$fail} failed\n";
if ($errors !== []) {
    echo "Failures:\n";
    foreach ($errors as $error) {
        echo " - {$error}\n";
    }
}

exit($fail === 0 ? 0 : 1);
