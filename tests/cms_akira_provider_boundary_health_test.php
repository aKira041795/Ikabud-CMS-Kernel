<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../modules/cms-akira-core/helpers.php';
require_once __DIR__ . '/../modules/cms-akira-core/handlers.php';

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

function mapByProvider(array $rows): array
{
    $map = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $provider = (string)($row['provider'] ?? '');
        if ($provider !== '') {
            $map[$provider] = $row;
        }
    }
    return $map;
}

function registerAkiraCoreCapabilities(): void
{
    foreach (cms_akira_core_capability_handlers() as $capabilityId => $handlerFn) {
        if (!is_string($handlerFn) || !function_exists($handlerFn)) {
            continue;
        }
        try {
            app()->capabilities()->register($capabilityId, 'cms-akira-core', $handlerFn, 100, ['first']);
        } catch (Throwable $e) {
            // already registered is acceptable for repeat runs
        }
    }
}

file_put_contents(STORAGE_PATH . '/logs/app.log', '');
file_put_contents(STORAGE_PATH . '/logs/error.log', '');

registerAkiraCoreCapabilities();

$contracts = cacProviderBoundaryContracts();
t('provider boundary contract map includes WorkflowProvider', isset($contracts['WorkflowProvider']));
t('provider boundary contract map includes SearchIndexer', isset($contracts['SearchIndexer']));

$statusResult = app()->cap()->call('akira.providers.status@1', []);
t('akira.providers.status@1 returns ok', ($statusResult['ok'] ?? false) === true);

$rows = is_array($statusResult['data'] ?? null) ? $statusResult['data'] : [];
$providers = mapByProvider($rows);
t('provider status includes MediaGateway', isset($providers['MediaGateway']));
t('provider status includes EditorProvider', isset($providers['EditorProvider']));
t('provider status includes ThemeProvider', isset($providers['ThemeProvider']));
t('provider status includes IdentityResolver', isset($providers['IdentityResolver']));

$initialMediaMode = (string)($providers['MediaGateway']['mode'] ?? '');
$initialMediaEnabled = isset(discoverModules()['cms-akira-media']) ? isModuleEnabled('cms-akira-media') : false;
t('media gateway mode matches module state at startup', $initialMediaMode === ($initialMediaEnabled ? 'provider' : 'fallback'));
t('identity provider mode is core', (($providers['IdentityResolver']['mode'] ?? '') === 'core'));

$themeModuleId = 'cms-akira-theme';
$themeModuleExists = isset(discoverModules()[$themeModuleId]);
$themeWasEnabled = $themeModuleExists ? isModuleEnabled($themeModuleId) : false;

$mediaModuleId = 'cms-akira-media';
$mediaModuleExists = isset(discoverModules()[$mediaModuleId]);
$mediaWasEnabled = $mediaModuleExists ? isModuleEnabled($mediaModuleId) : false;

try {
    if ($themeModuleExists) {
        disableModule($themeModuleId);
        $fallback = app()->cap()->call('akira.providers.status@1', []);
        $fallbackProviders = mapByProvider(is_array($fallback['data'] ?? null) ? $fallback['data'] : []);
        t('theme provider enters fallback mode when module disabled', (($fallbackProviders['ThemeProvider']['mode'] ?? '') === 'fallback'));

        enableModule($themeModuleId);
        $enabled = app()->cap()->call('akira.providers.status@1', []);
        $enabledProviders = mapByProvider(is_array($enabled['data'] ?? null) ? $enabled['data'] : []);
        t('theme provider enters provider mode when module enabled', (($enabledProviders['ThemeProvider']['mode'] ?? '') === 'provider'));
    } else {
        t('theme module not discovered in this environment (skip)', true);
    }

    if ($mediaModuleExists) {
        disableModule($mediaModuleId);
        $fallback = app()->cap()->call('akira.providers.status@1', []);
        $fallbackProviders = mapByProvider(is_array($fallback['data'] ?? null) ? $fallback['data'] : []);
        t('media gateway enters fallback mode when module disabled', (($fallbackProviders['MediaGateway']['mode'] ?? '') === 'fallback'));

        enableModule($mediaModuleId);
        $enabled = app()->cap()->call('akira.providers.status@1', []);
        $enabledProviders = mapByProvider(is_array($enabled['data'] ?? null) ? $enabled['data'] : []);
        t('media gateway enters provider mode when module enabled', (($enabledProviders['MediaGateway']['mode'] ?? '') === 'provider'));
    } else {
        t('media module not discovered in this environment (skip)', true);
    }
} finally {
    if ($themeModuleExists) {
        if ($themeWasEnabled) {
            enableModule($themeModuleId);
        } else {
            disableModule($themeModuleId);
        }
    }

    if ($mediaModuleExists) {
        if ($mediaWasEnabled) {
            enableModule($mediaModuleId);
        } else {
            disableModule($mediaModuleId);
        }
    }
}

ob_start();
apiCmsAkiraCoreProvidersHealth();
$apiBody = (string)ob_get_clean();
$apiPayload = json_decode($apiBody, true);
t('providers health API returns ok=true', is_array($apiPayload) && (($apiPayload['ok'] ?? false) === true));
t('providers health API includes data array', is_array($apiPayload['data'] ?? null));

echo "\n{$pass} passed, {$fail} failed\n";
if ($errors !== []) {
    echo "Failures:\n";
    foreach ($errors as $error) {
        echo " - {$error}\n";
    }
}

exit($fail === 0 ? 0 : 1);
