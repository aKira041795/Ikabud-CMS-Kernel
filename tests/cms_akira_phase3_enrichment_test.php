<?php

declare(strict_types=1);

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'akiracms.test';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/';

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../modules/cms-akira/cms-akira-core/helpers.php';
require_once __DIR__ . '/../modules/cms-akira/cms-akira-seo/helpers.php';
require_once __DIR__ . '/../modules/cms-akira/cms-akira-ai/helpers.php';

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
registerHandlers('cms-akira-seo', cms_akira_seo_capability_handlers());
registerHandlers('cms-akira-ai', cms_akira_ai_capability_handlers());

$payload = [
    'title' => 'Akira Phase Three Enrichment',
    'slug' => 'akira-phase-three-enrichment',
    'excerpt' => 'Phase three provider extraction baseline for SEO and AI modules.',
    'body' => '<p>Phase three content body.</p>',
];

$seoModule = 'cms-akira-seo';
$aiModule = 'cms-akira-ai';
$seoWasEnabled = isModuleEnabled($seoModule);
$aiWasEnabled = isModuleEnabled($aiModule);

try {
    enableModule($seoModule);
    enableModule($aiModule);

    $providerResult = app()->cap()->call('akira.content.enrich@1', $payload);
    t('akira.content.enrich@1 succeeds with providers enabled', ($providerResult['ok'] ?? false) === true);
    t('provider mode seo=provider when module enabled', (($providerResult['data']['provider_mode']['seo'] ?? '') === 'provider'));
    t('provider mode ai=provider when module enabled', (($providerResult['data']['provider_mode']['ai'] ?? '') === 'provider'));
    t('seo provider marker present when enabled', (($providerResult['data']['seo']['provider'] ?? '') === 'cms-akira-seo'));
    t('ai provider marker present when enabled', (($providerResult['data']['ai']['provider'] ?? '') === 'cms-akira-ai'));

    disableModule($seoModule);
    disableModule($aiModule);

    $fallbackResult = app()->cap()->call('akira.content.enrich@1', $payload);
    t('akira.content.enrich@1 succeeds with providers disabled', ($fallbackResult['ok'] ?? false) === true);
    t('fallback mode seo=fallback when module disabled', (($fallbackResult['data']['provider_mode']['seo'] ?? '') === 'fallback'));
    t('fallback mode ai=fallback when module disabled', (($fallbackResult['data']['provider_mode']['ai'] ?? '') === 'fallback'));
    t('seo fallback omits provider marker', !isset($fallbackResult['data']['seo']['provider']));
    t('ai fallback omits provider marker', !isset($fallbackResult['data']['ai']['provider']));
} finally {
    if ($seoWasEnabled) {
        enableModule($seoModule);
    } else {
        disableModule($seoModule);
    }

    if ($aiWasEnabled) {
        enableModule($aiModule);
    } else {
        disableModule($aiModule);
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
