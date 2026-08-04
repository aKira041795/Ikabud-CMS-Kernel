<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../modules/cms/helpers.php';

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

function registerCmsCaps(): void
{
    $handlers = cms_capability_handlers();
    foreach ($handlers as $capId => $fn) {
        if (!is_string($fn) || !function_exists($fn)) {
            continue;
        }
        try {
            app()->capabilities()->register($capId, 'cms', $fn, 100, ['first']);
        } catch (Throwable $e) {
            // Allow reruns where handlers are already present.
        }
    }
}

registerCmsCaps();

file_put_contents(STORAGE_PATH . '/logs/app.log', '');
file_put_contents(STORAGE_PATH . '/logs/error.log', '');

$pdo = app()->db();
$authorId = (int) ($pdo->query("SELECT id FROM cms_users WHERE is_active = 1 ORDER BY id ASC LIMIT 1")->fetchColumn() ?: 0);
if ($authorId <= 0) {
    echo "FATAL: no active cms_users row found for disablement test\n";
    exit(1);
}

$optionalModules = ['theme-studio', 'ai-orchestrator', 'workflow', 'tinymce'];
$moduleManifests = function_exists('moduleRegistryRawModuleManifests') ? moduleRegistryRawModuleManifests() : [];
$trackedStates = [];
$contentId = 0;

try {
    foreach ($optionalModules as $moduleId) {
        if (!isset($moduleManifests[$moduleId])) {
            t("optional module {$moduleId} not present in this workspace (skip)", true);
            continue;
        }

        $trackedStates[$moduleId] = isModuleEnabled($moduleId);
        disableModule($moduleId);
        t("{$moduleId} disabled for test", isModuleEnabled($moduleId) === false);
    }

    $token = bin2hex(random_bytes(4));
    $createPayload = [
        'title' => 'Disablement Baseline ' . $token,
        'slug' => 'disablement-baseline-' . $token,
        'body' => '<p>Disablement baseline</p>',
        'type' => 'post',
        'status' => 'draft',
        'author_id' => $authorId,
    ];

    $create = app()->cap()->call('cms.content.create@1', $createPayload);
    t('cms.content.create@1 works with optional modules disabled', ($create['ok'] ?? false) === true);

    $contentId = (int) ($create['id'] ?? 0);
    t('create returns content id', $contentId > 0);

    $get = app()->cap()->call('cms.content.get@1', ['id' => $contentId]);
    t('cms.content.get@1 works with optional modules disabled', ($get['ok'] ?? false) === true);
    t('cms.content.get@1 returns expected id', (int) (($get['data']['id'] ?? 0)) === $contentId);

    $list = app()->cap()->call('cms.content.list@1', ['limit' => 5]);
    t('cms.content.list@1 works with optional modules disabled', ($list['ok'] ?? false) === true);
    t('cms.content.list@1 returns array data shape', is_array($list['data'] ?? null));

    $update = app()->cap()->call('cms.content.update@1', [
        'id' => $contentId,
        'title' => 'Disablement Updated ' . $token,
        'slug' => 'disablement-baseline-' . $token,
        'body' => '<p>Disablement updated</p>',
        'status' => 'published',
    ]);
    t('cms.content.update@1 works with optional modules disabled', ($update['ok'] ?? false) === true);

    $row = $pdo->query("SELECT title FROM cms_content WHERE id = {$contentId} LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];
    t('update persists with optional modules disabled', ($row['title'] ?? '') === ('Disablement Updated ' . $token));
} finally {
    foreach ($trackedStates as $moduleId => $wasEnabled) {
        if ($wasEnabled) {
            enableModule($moduleId);
        } else {
            disableModule($moduleId);
        }
    }

    if ($contentId > 0) {
        $pdo->exec("DELETE FROM cms_content_meta WHERE content_id = {$contentId}");
        $pdo->exec("DELETE FROM cms_content WHERE id = {$contentId}");
    }
}

echo "\n{$pass} passed, {$fail} failed\n";
if ($errors !== []) {
    echo "Failures:\n";
    foreach ($errors as $error) {
        echo " - {$error}\n";
    }
}

exit($fail === 0 ? 0 : 1);
