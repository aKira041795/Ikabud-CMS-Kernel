<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../modules/cms/helpers.php';
require_once __DIR__ . '/../modules/cms-akira-core/helpers.php';

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
            // Handler may already be registered in repeated local runs.
        }
    }
}

file_put_contents(STORAGE_PATH . '/logs/app.log', '');
file_put_contents(STORAGE_PATH . '/logs/error.log', '');

registerHandlers('cms', cms_capability_handlers());
registerHandlers('cms-akira-core', cms_akira_core_capability_handlers());

$contracts = cacProviderBoundaryContracts();
t('provider boundary includes MediaGateway', isset($contracts['MediaGateway']));
t('provider boundary includes IdentityResolver', isset($contracts['IdentityResolver']));
t('provider boundary includes LegacyCmsContentAdapter', isset($contracts['LegacyCmsContentAdapter']));

$adapter = cacLegacyCmsContentAdapter();
t('legacy content adapter implements interface', $adapter instanceof CacLegacyCmsContentAdapterInterface);

$badGet = app()->cap()->call('akira.content.get@1', []);
t('akira.content.get@1 preserves id validation', ($badGet['error'] ?? '') === 'id is required');

$badCreate = app()->cap()->call('akira.content.create@1', []);
t('akira.content.create@1 preserves title validation', ($badCreate['error'] ?? '') === 'title is required');

$badUpdate = app()->cap()->call('akira.content.update@1', 'bad-payload');
t('akira.content.update@1 preserves payload validation', ($badUpdate['error'] ?? '') === 'payload must be an object');

$pdo = app()->db();
$authorId = (int)($pdo->query("SELECT id FROM cms_users WHERE is_active = 1 ORDER BY id ASC LIMIT 1")->fetchColumn() ?: 0);
if ($authorId <= 0) {
    echo "FATAL: no active cms_users row found for adapter contract test\n";
    exit(1);
}

$token = bin2hex(random_bytes(4));
$slug = 'akira-adapter-' . $token;

$create = app()->cap()->call('akira.content.create@1', [
    'title' => 'Akira Adapter Create ' . $token,
    'slug' => $slug,
    'body' => '<p>adapter test</p>',
    'type' => 'post',
    'status' => 'draft',
    'author_id' => $authorId,
]);
t('akira.content.create@1 call succeeds', ($create['ok'] ?? false) === true);

$contentId = (int)($create['id'] ?? 0);
t('akira.content.create@1 returns content id', $contentId > 0);

$get = app()->cap()->call('akira.content.get@1', ['id' => $contentId]);
t('akira.content.get@1 call succeeds', ($get['ok'] ?? false) === true);
t('akira.content.get@1 returns created content id', (int)($get['data']['id'] ?? 0) === $contentId);

$list = app()->cap()->call('akira.content.list@1', ['limit' => 5]);
t('akira.content.list@1 call succeeds', ($list['ok'] ?? false) === true);
t('akira.content.list@1 returns array data', is_array($list['data'] ?? null));

$update = app()->cap()->call('akira.content.update@1', [
    'id' => $contentId,
    'title' => 'Akira Adapter Updated ' . $token,
    'slug' => $slug,
    'body' => '<p>adapter updated</p>',
    'status' => 'published',
]);
t('akira.content.update@1 call succeeds', ($update['ok'] ?? false) === true);

$row = $pdo->query("SELECT title FROM cms_content WHERE id = {$contentId} LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];
t('akira.content.update@1 persisted updated title', ($row['title'] ?? '') === ('Akira Adapter Updated ' . $token));

if ($contentId > 0) {
    $pdo->exec("DELETE FROM cms_content_meta WHERE content_id = {$contentId}");
    $pdo->exec("DELETE FROM cms_content WHERE id = {$contentId}");
}

echo "\n{$pass} passed, {$fail} failed\n";
if ($errors !== []) {
    echo "Failures:\n";
    foreach ($errors as $error) {
        echo " - {$error}\n";
    }
}

exit($fail === 0 ? 0 : 1);
