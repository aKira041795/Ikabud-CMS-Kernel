<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../modules/cms-akira-core/helpers.php';
require_once __DIR__ . '/../modules/cms-akira-theme/helpers.php';
require_once __DIR__ . '/../modules/cms-akira-navigation/helpers.php';
require_once __DIR__ . '/../modules/cms-akira-workflow/helpers.php';
require_once __DIR__ . '/../modules/cms-akira-search-adapter/helpers.php';
require_once __DIR__ . '/../modules/cms-akira-media/helpers.php';
require_once __DIR__ . '/../modules/cms-akira-seo/helpers.php';
require_once __DIR__ . '/../modules/cms-akira-ai/helpers.php';

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
registerHandlers('cms-akira-theme', cms_akira_theme_capability_handlers());
registerHandlers('cms-akira-navigation', cms_akira_navigation_capability_handlers());
registerHandlers('cms-akira-workflow', cms_akira_workflow_capability_handlers());
registerHandlers('cms-akira-search-adapter', cms_akira_search_adapter_capability_handlers());
registerHandlers('cms-akira-media', cms_akira_media_capability_handlers());
registerHandlers('cms-akira-seo', cms_akira_seo_capability_handlers());
registerHandlers('cms-akira-ai', cms_akira_ai_capability_handlers());

$modules = [
    'cms-akira-theme',
    'cms-akira-navigation',
    'cms-akira-workflow',
    'cms-akira-search-adapter',
    'cms-akira-media',
    'cms-akira-seo',
    'cms-akira-ai',
];

$prior = [];
foreach ($modules as $moduleId) {
    $prior[$moduleId] = isModuleEnabled($moduleId);
}

$payload = [
    'title' => 'Akira Compose Phase Five Six',
    'slug' => 'akira-compose-phase-five-six',
    'status' => 'draft',
    'excerpt' => 'Compose provider and fallback context test payload.',
    'body' => '<p>Compose provider test body.</p>',
];

try {
    foreach ($modules as $moduleId) {
        enableModule($moduleId);
    }

    $providerResult = app()->cap()->call('akira.content.compose@1', $payload);
    t('akira.content.compose@1 succeeds with all providers enabled', ($providerResult['ok'] ?? false) === true);
    t('provider mode theme=provider', (($providerResult['data']['provider_mode']['theme'] ?? '') === 'provider'));
    t('provider mode navigation=provider', (($providerResult['data']['provider_mode']['navigation'] ?? '') === 'provider'));
    t('provider mode workflow=provider', (($providerResult['data']['provider_mode']['workflow'] ?? '') === 'provider'));
    t('provider mode search=provider', (($providerResult['data']['provider_mode']['search'] ?? '') === 'provider'));
    t('provider mode media=provider', (($providerResult['data']['provider_mode']['media'] ?? '') === 'provider'));
    t('provider mode seo=provider', (($providerResult['data']['provider_mode']['seo'] ?? '') === 'provider'));
    t('provider mode ai=provider', (($providerResult['data']['provider_mode']['ai'] ?? '') === 'provider'));

    foreach ($modules as $moduleId) {
        disableModule($moduleId);
    }

    $fallbackResult = app()->cap()->call('akira.content.compose@1', $payload);
    t('akira.content.compose@1 succeeds with providers disabled', ($fallbackResult['ok'] ?? false) === true);
    t('fallback mode theme=fallback', (($fallbackResult['data']['provider_mode']['theme'] ?? '') === 'fallback'));
    t('fallback mode navigation=fallback', (($fallbackResult['data']['provider_mode']['navigation'] ?? '') === 'fallback'));
    t('fallback mode workflow=fallback', (($fallbackResult['data']['provider_mode']['workflow'] ?? '') === 'fallback'));
    t('fallback mode search=fallback', (($fallbackResult['data']['provider_mode']['search'] ?? '') === 'fallback'));
    t('fallback mode media=fallback', (($fallbackResult['data']['provider_mode']['media'] ?? '') === 'fallback'));
    t('fallback mode seo=fallback', (($fallbackResult['data']['provider_mode']['seo'] ?? '') === 'fallback'));
    t('fallback mode ai=fallback', (($fallbackResult['data']['provider_mode']['ai'] ?? '') === 'fallback'));
} finally {
    foreach ($modules as $moduleId) {
        if ($prior[$moduleId]) {
            enableModule($moduleId);
        } else {
            disableModule($moduleId);
        }
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
