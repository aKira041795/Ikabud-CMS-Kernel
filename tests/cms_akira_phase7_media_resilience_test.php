<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../modules/cms-akira-core/helpers.php';
require_once __DIR__ . '/../modules/cms-akira-media/helpers.php';

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
registerHandlers('cms-akira-media', cms_akira_media_capability_handlers());

$moduleId = 'cms-akira-media';
$wasEnabled = isModuleEnabled($moduleId);

$payload = [
    'title' => 'Akira Media Resilience Test',
    'slug' => 'akira-media-resilience-test',
    'status' => 'draft',
    'body' => '<p>Media resilience phase test.</p>',
    'featured_image_url' => 'https://cdn.example.test/images/cover.jpg',
    'featured_image_alt' => 'Cover image',
];

try {
    enableModule($moduleId);
    $providerResult = app()->cap()->call('akira.content.compose@1', $payload);

    t('compose succeeds when media module enabled', ($providerResult['ok'] ?? false) === true);
    t('media provider mode is provider when module enabled', (($providerResult['data']['provider_mode']['media'] ?? '') === 'provider'));
    t('media url is resolved by provider path', (($providerResult['data']['media']['url'] ?? '') === 'https://cdn.example.test/images/cover.jpg'));

    disableModule($moduleId);
    $fallbackResult = app()->cap()->call('akira.content.compose@1', $payload);

    t('compose succeeds when media module disabled', ($fallbackResult['ok'] ?? false) === true);
    t('media provider mode is fallback when module disabled', (($fallbackResult['data']['provider_mode']['media'] ?? '') === 'fallback'));
    t('media url remains available via fallback', (($fallbackResult['data']['media']['url'] ?? '') === 'https://cdn.example.test/images/cover.jpg'));
} finally {
    if ($wasEnabled) {
        enableModule($moduleId);
    } else {
        disableModule($moduleId);
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
