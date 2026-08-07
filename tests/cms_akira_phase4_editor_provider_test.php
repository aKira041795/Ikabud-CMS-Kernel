<?php

declare(strict_types=1);

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'akiracms.test';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/';

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../modules/cms/helpers.php';
require_once __DIR__ . '/../modules/cms-akira/cms-akira-core/helpers.php';
require_once __DIR__ . '/../modules/cms-akira/cms-akira-editor/helpers.php';

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

function registerHandlers(string $provider, array $handlers): void
{
    foreach ($handlers as $capabilityId => $handlerFn) {
        if (!is_string($handlerFn) || !function_exists($handlerFn)) {
            continue;
        }
        try {
            app()->capabilities()->register($capabilityId, $provider, $handlerFn, 100, ['first']);
        } catch (Throwable $e) {
            // already registered in repeated runs
        }
    }
}

file_put_contents(STORAGE_PATH . '/logs/app.log', '');
file_put_contents(STORAGE_PATH . '/logs/error.log', '');

registerHandlers('cms-akira-core', cms_akira_core_capability_handlers());
registerHandlers('cms-akira-editor', cms_akira_editor_capability_handlers());
registerHandlers('cms', cms_capability_handlers());

$pdo = app()->db();
$authorId = (int)($pdo->query("SELECT id FROM cms_users WHERE is_active = 1 ORDER BY id ASC LIMIT 1")->fetchColumn() ?: 0);
if ($authorId <= 0) {
    echo "FATAL: no active cms_users row found for Phase 4 editor provider test\n";
    exit(1);
}

$token = bin2hex(random_bytes(4));

$editorModule = 'cms-akira-editor';
$editorWasEnabled = isModuleEnabled($editorModule);

try {
    enableModule($editorModule);

    $createdProvider = app()->cap()->call('akira.content.create@1', [
        'title' => 'Phase Four Editor Provider Content',
        'slug' => 'phase-four-editor-provider-content-' . $token,
        'status' => 'draft',
        'type' => 'post',
        'author_id' => $authorId,
        'body' => "Line A\r\n\r\n\r\n<script>alert('x');</script><p>Line B</p>",
    ]);

    t('create succeeds with editor module enabled', ($createdProvider['ok'] ?? false) === true);
    t('create reports provider mode when editor enabled', (($createdProvider['data']['provider_mode']['editor'] ?? '') === 'provider'));

    $createdId = (int)($createdProvider['data']['id'] ?? $createdProvider['id'] ?? 0);
    t('create returns content id', $createdId > 0);

    if ($createdId > 0) {
        $fetchedProvider = app()->cap()->call('akira.content.get@1', ['id' => $createdId]);
        $providerBody = (string)($fetchedProvider['data']['body'] ?? '');
        t('provider path strips script tag', stripos($providerBody, '<script') === false);
    }

    disableModule($editorModule);

    $createdFallback = app()->cap()->call('akira.content.create@1', [
        'title' => 'Phase Four Editor Fallback Content',
        'slug' => 'phase-four-editor-fallback-content-' . $token,
        'status' => 'draft',
        'type' => 'post',
        'author_id' => $authorId,
        'body' => "<script>alert('x');</script><p>Fallback Body</p>",
    ]);

    t('create succeeds with editor module disabled', ($createdFallback['ok'] ?? false) === true);
    t('create reports fallback mode when editor disabled', (($createdFallback['data']['provider_mode']['editor'] ?? '') === 'fallback'));

    $fallbackId = (int)($createdFallback['data']['id'] ?? $createdFallback['id'] ?? 0);
    t('fallback create returns content id', $fallbackId > 0);

    if ($fallbackId > 0) {
        $fetchedFallback = app()->cap()->call('akira.content.get@1', ['id' => $fallbackId]);
        $fallbackBody = (string)($fetchedFallback['data']['body'] ?? '');
        t('fallback path strips script tag', stripos($fallbackBody, '<script') === false);

        $updatedFallback = app()->cap()->call('akira.content.update@1', [
            'id' => $fallbackId,
            'body' => "<script>alert('y');</script><p>Updated Fallback Body</p>",
        ]);
        t('update succeeds with editor module disabled', ($updatedFallback['ok'] ?? false) === true);
        t('update reports fallback mode when editor disabled', (($updatedFallback['data']['provider_mode']['editor'] ?? '') === 'fallback'));
    }
} finally {
    if ($editorWasEnabled) {
        enableModule($editorModule);
    } else {
        disableModule($editorModule);
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
