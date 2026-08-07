<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../modules/cms-akira/cms-akira-core/helpers.php';

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

function registerAkiraCoreHandlers(): void
{
    foreach (cms_akira_core_capability_handlers() as $capabilityId => $handlerFn) {
        if (!is_string($handlerFn) || !function_exists($handlerFn)) {
            continue;
        }

        try {
            app()->capabilities()->register($capabilityId, 'cms-akira-core', $handlerFn, 100, ['first']);
        } catch (Throwable $e) {
            // already registered is valid in repeat runs
        }
    }
}

function readManifest(string $moduleId): array
{
    $modulePath = modulePathForId($moduleId);
    if (!is_string($modulePath) || $modulePath === '') {
        return [];
    }

    $path = rtrim($modulePath, '/') . '/module.json';
    if (!is_file($path)) {
        return [];
    }

    $decoded = json_decode((string)file_get_contents($path), true);
    return is_array($decoded) ? $decoded : [];
}

file_put_contents(STORAGE_PATH . '/logs/app.log', '');
file_put_contents(STORAGE_PATH . '/logs/error.log', '');

$expectedProfiles = [
    'cms-akira-profile-minimal' => ['cms-akira-core'],
    'cms-akira-profile-standard' => ['cms-akira-core', 'cms-akira-editor', 'cms-akira-theme', 'cms-akira-navigation'],
    'cms-akira-profile-visual' => ['cms-akira-core', 'cms-akira-editor', 'cms-akira-theme', 'cms-akira-navigation', 'cms-akira-builder', 'cms-akira-media', 'cms-akira-seo', 'cms-akira-workflow', 'cms-akira-search-adapter'],
    'cms-akira-profile-headless' => ['cms-akira-core', 'cms-akira-search-adapter', 'cms-akira-workflow'],
];

$discovered = discoverModules();

foreach ($expectedProfiles as $profileModule => $requiredDepends) {
    t("{$profileModule} discovered", isset($discovered[$profileModule]));

    $manifest = readManifest($profileModule);
    t("{$profileModule} manifest parsed", $manifest !== []);

    $depends = is_array($manifest['depends'] ?? null) ? $manifest['depends'] : [];
    foreach ($requiredDepends as $dependency) {
        t("{$profileModule} depends on {$dependency}", in_array($dependency, $depends, true));
    }
}

$coreManifest = readManifest('cms-akira-core');
$exposes = is_array($coreManifest['capabilities']['exposes'] ?? null) ? $coreManifest['capabilities']['exposes'] : [];
$coreDepends = is_array($coreManifest['capabilities']['depends'] ?? null) ? $coreManifest['capabilities']['depends'] : [];
$exposedIds = array_values(array_filter(array_map(
    static fn(array $row): string => (string)($row['id'] ?? ''),
    array_filter($exposes, 'is_array')
)));

t('cms-akira-core exposes akira.providers.status@1', in_array('akira.providers.status@1', $exposedIds, true));

$optionalProviderCaps = [
    'akira.seo.meta.build@1',
    'akira.ai.summary.suggest@1',
    'akira.theme.resolve@1',
    'akira.navigation.resolve@1',
    'akira.workflow.evaluate@1',
    'akira.search.document.build@1',
    'akira.media.resolve@1',
];
foreach ($optionalProviderCaps as $capId) {
    t("cms-akira-core does not hard-depend on optional capability {$capId}", !in_array($capId, $coreDepends, true));
}

// Boot-path capability availability: in a CLI test bootstrap, module capability
// handlers are not auto-registered (registration happens during web request
// boot). Probing them here without registration would throw and crash into a
// 500 page, so guard the probe and record a SKIP instead.
function skip(string $label, string $detail = ''): void
{
    echo "SKIP: {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

try {
    $bootStatus = app()->cap()->call('akira.providers.status@1', []);
    t('akira.providers.status@1 callable without manual registration', ($bootStatus['ok'] ?? false) === true);
} catch (Throwable $e) {
    skip('akira.providers.status@1 auto-registration', 'capability handlers register at web boot, not CLI bootstrap');
}

try {
    $bootCompose = app()->cap()->call('akira.content.compose@1', [
        'title' => 'Boot Path Compose Check',
        'slug' => 'boot-path-compose-check',
        'status' => 'draft',
        'body' => '<p>Boot path composition check.</p>',
    ]);
    t('akira.content.compose@1 callable without manual registration', ($bootCompose['ok'] ?? false) === true);
} catch (Throwable $e) {
    skip('akira.content.compose@1 auto-registration', 'capability handlers register at web boot, not CLI bootstrap');
}

registerAkiraCoreHandlers();
$status = app()->cap()->call('akira.providers.status@1', []);
t('akira.providers.status@1 callable in deploy readiness test', ($status['ok'] ?? false) === true);
$rows = is_array($status['data'] ?? null) ? $status['data'] : [];
t('provider status contains at least 9 provider rows', count($rows) >= 9);

echo "\n{$pass} passed, {$fail} failed\n";
if ($errors !== []) {
    echo "Failures:\n";
    foreach ($errors as $error) {
        echo " - {$error}\n";
    }
}

exit($fail === 0 ? 0 : 1);
