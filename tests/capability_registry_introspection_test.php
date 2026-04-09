<?php
/**
 * Capability Registry Introspection Test (v2 Slice 1)
 * Run: php tests/capability_registry_introspection_test.php
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';

use Ikabud\Kernel\Capabilities\CapabilityCatalog;

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

file_put_contents(STORAGE_PATH . '/logs/app.log', '');
file_put_contents(STORAGE_PATH . '/logs/error.log', '');

echo "\n=== REGISTRY INTROSPECTION — KERNEL CAPABILITIES ===\n";

$registry = app()->capabilities();

// Kernel capabilities should be registered after boot
$capIds = $registry->capabilityIds();
t('kernel capabilities registered', count($capIds) >= 7, 'count=' . count($capIds));
t('has kernel.auth.user@1', $registry->has('kernel.auth.user@1'));
t('has kernel.auth.require@1', $registry->has('kernel.auth.require@1'));
t('has kernel.auth.authenticate@1', $registry->has('kernel.auth.authenticate@1'));
t('has kernel.audit.record@1', $registry->has('kernel.audit.record@1'));
t('has kernel.render.context@1', $registry->has('kernel.render.context@1'));
t('has kernel.http.request_context@1', $registry->has('kernel.http.request_context@1'));
t('has workflow.state.get@1', $registry->has('workflow.state.get@1'));
t('has workflow.transition@1', $registry->has('workflow.transition@1'));

echo "\n=== INSPECT SINGLE CAPABILITY ===\n";

$info = $registry->inspect('kernel.audit.record@1');
t('inspect returns id', ($info['id'] ?? '') === 'kernel.audit.record@1');
t('inspect returns base_id', ($info['base_id'] ?? '') === 'kernel.audit.record');
t('inspect returns major_version', ($info['major_version'] ?? null) === 1);
t('inspect returns latest_id', ($info['latest_id'] ?? '') === 'kernel.audit.record@1');
t('inspect returns is_latest=true', !empty($info['is_latest']));
t('inspect returns provider_count=1', ($info['provider_count'] ?? 0) === 1);
t('inspect requested_id is null for exact match', $info['requested_id'] === null);

$providers = $info['providers'] ?? [];
t('provider is kernel', is_array($providers[0] ?? null) && ($providers[0]['provider'] ?? '') === 'kernel');
t('provider has priority 1000', ($providers[0]['priority'] ?? 0) === 1000);
t('provider has modes', is_array($providers[0]['modes'] ?? null) && in_array('first', $providers[0]['modes'], true));

echo "\n=== INSPECT ORIGIN METADATA ===\n";

$origin = $providers[0]['origin'] ?? [];
t('origin type is kernel_boot', ($origin['type'] ?? '') === 'kernel_boot');
t('origin provider is kernel', ($origin['provider'] ?? '') === 'kernel');
t('origin file is kernel/App.php', ($origin['file'] ?? '') === 'kernel/App.php');
t('origin capability matches', ($origin['capability'] ?? '') === 'kernel.audit.record@1');

echo "\n=== INSPECT WORKFLOW CAPABILITY WITH SCHEMA ===\n";

$wfInfo = $registry->inspect('workflow.state.get@1');
t('workflow inspect returns id', ($wfInfo['id'] ?? '') === 'workflow.state.get@1');
$wfProviders = $wfInfo['providers'] ?? [];
t('workflow has kernel provider', !empty($wfProviders) && ($wfProviders[0]['provider'] ?? '') === 'kernel');
t('workflow has schema', $wfProviders[0]['schema'] !== null);
t('workflow has policy', $wfProviders[0]['policy'] !== null);
$wfOrigin = $wfProviders[0]['origin'] ?? [];
t('workflow origin type is kernel_boot', ($wfOrigin['type'] ?? '') === 'kernel_boot');

echo "\n=== INSPECT ALL ===\n";

$all = $registry->inspectAll();
t('inspectAll returns array', is_array($all));
t('inspectAll count matches capabilityIds', count($all) === count($capIds));

// Every entry should have required fields
$allOk = true;
foreach ($all as $entry) {
    if (!is_array($entry) || !isset($entry['id'], $entry['base_id'], $entry['major_version'], $entry['providers'])) {
        $allOk = false;
        break;
    }
}
t('all entries have required fields', $allOk);

echo "\n=== VERSION RESOLUTION ===\n";

$resolved = $registry->resolve('kernel.audit.record');
t('resolve without version finds latest', $resolved === 'kernel.audit.record@1');

$resolvedExact = $registry->resolve('kernel.audit.record@1');
t('resolve with exact version returns same', $resolvedExact === 'kernel.audit.record@1');

echo "\n=== INSPECT WITH VERSION RESOLUTION ===\n";

$infoByBase = $registry->inspect('kernel.audit.record');
t('inspect by base id resolves to @1', ($infoByBase['id'] ?? '') === 'kernel.audit.record@1');
t('inspect by base id sets requested_id', ($infoByBase['requested_id'] ?? '') === 'kernel.audit.record');

echo "\n=== CAPABILITY CATALOG (MANIFEST + RUNTIME) ===\n";

$catalog = new CapabilityCatalog($registry);
$catalogSummary = $catalog->summary();
t('catalog summary includes discovered modules', ($catalogSummary['module_count'] ?? 0) >= 1, 'count=' . ($catalogSummary['module_count'] ?? 0));
t('catalog summary includes declared capabilities', ($catalogSummary['declared_capability_count'] ?? 0) >= 1, 'count=' . ($catalogSummary['declared_capability_count'] ?? 0));

$declaredWmsCapability = $catalog->inspect('wms.order.create');
t('catalog resolves manifest-only base id before module load', ($declaredWmsCapability['id'] ?? '') === 'wms.order.create@1');
t('catalog shows wms manifest provider before module load', ($declaredWmsCapability['declared_provider_count'] ?? 0) >= 1);
t('catalog marks wms capability as not runtime-registered before module load', empty($declaredWmsCapability['runtime_registered']));

$declaredWmsProviderFound = false;
foreach (($declaredWmsCapability['declared_providers'] ?? []) as $provider) {
    if (($provider['module'] ?? '') === 'wms') {
        $declaredWmsProviderFound = true;
        break;
    }
}
t('catalog includes wms declared provider metadata', $declaredWmsProviderFound);

$wmsModule = $catalog->module('wms');
$wmsPaymentEventFound = false;
foreach (($wmsModule['events'] ?? []) as $event) {
    if (($event['key'] ?? '') === 'wms.order.payment_collected') {
        $wmsPaymentEventFound = true;
        break;
    }
}
t('catalog exposes wms payment-collected event metadata', $wmsPaymentEventFound);

echo "\n=== RENDER CONTEXT PIPELINE ===\n";

$hooks = app()->hooks();
$hooks->on('kernel.render_context', function (array $context, string $template): array {
    $context['__render_context_probe'] = $template;
    return $context;
}, 10);

$renderContext = app()->cap()->call('kernel.render.context@1', ['template' => 'pages/404.disyl']);
t('render context capability uses shared builder hook pipeline', ($renderContext['__render_context_probe'] ?? '') === 'pages/404.disyl');
t('render context capability returns base shell keys', isset($renderContext['base_url'], $renderContext['app_url'], $renderContext['gui']) && is_array($renderContext['gui'] ?? null));
$hooks->off('kernel.render_context');

$hooks->on('kernel.render_context.finalize', function (array $context, string $template): array {
    if ($template === 'pages/404.disyl') {
        $context['page_title'] = 'Render Finalize Probe';
    }
    return $context;
}, 10);

$rendered404 = app()->render('pages/404.disyl');
t('render finalize hook mutates the final merged render context', str_contains($rendered404, '<title>Render Finalize Probe'));
$hooks->off('kernel.render_context.finalize');

echo "\n=== MODULE PROVIDER ORIGIN (with modules loaded) ===\n";

// Load modules so we can test module origin metadata
loadModuleRoutes(['GET' => [], 'POST' => [], 'PUT' => [], 'DELETE' => []]);

$allAfterModules = $registry->inspectAll();
t('more capabilities after module load', count($allAfterModules) >= count($all));

$catalogAfterModules = new CapabilityCatalog($registry);
$runtimeWmsCapability = $catalogAfterModules->inspect('wms.order.create@1');
t('catalog marks wms capability as runtime-registered after module load', !empty($runtimeWmsCapability['runtime_registered']));
t('catalog keeps declared provider metadata after module load', ($runtimeWmsCapability['declared_provider_count'] ?? 0) >= 1);

// Find a module-provided capability
$moduleProviderFound = false;
$moduleOriginCorrect = false;
foreach ($allAfterModules as $cap) {
    foreach ($cap['providers'] ?? [] as $p) {
        if (($p['provider'] ?? '') !== 'kernel' && ($p['provider'] ?? '') !== '') {
            $moduleProviderFound = true;
            $origin = $p['origin'] ?? [];
            if (isset($origin['type']) && $origin['type'] !== '' && isset($origin['module'])) {
                $moduleOriginCorrect = true;
            }
            break 2;
        }
    }
}
t('found at least one module provider', $moduleProviderFound);
t('module provider has origin metadata', $moduleOriginCorrect);

echo "\n=== PER-CAPABILITY SCHEMA MODE (v2 Slice 2 prep) ===\n";

// Verify schema_validation_mode resolves from config
$bus = app()->cap();
// This just confirms the bus is wired correctly — actual enforcement tested separately
t('capability bus is available', $bus instanceof \Ikabud\Kernel\Capabilities\CapabilityBus);

echo "\n=== LOG CHECK ===\n";

$appLog = @file_get_contents(STORAGE_PATH . '/logs/app.log') ?: '';
$errLog = @file_get_contents(STORAGE_PATH . '/logs/error.log') ?: '';

$appErrors = array_filter(explode("\n", $appLog), fn($l) => str_contains($l, '[error]') && !str_contains($l, 'capability.call'));
t('No unexpected app.log errors', empty($appErrors), implode('; ', array_slice($appErrors, 0, 3)));

$errLines = array_filter(explode("\n", $errLog), function ($l) {
    $l = trim($l);
    if ($l === '') return false;
    if (str_contains($l, 'Ikabud Cache:')) return false;
    return true;
});
t('No PHP errors in error.log', empty($errLines), implode('; ', array_slice($errLines, 0, 3)));

echo "\n══════════════════════════════════════════════════\n";
echo "  PASS: {$pass}  FAIL: {$fail}\n";
echo "══════════════════════════════════════════════════\n";

if (!empty($errors)) {
    echo "\nFailed tests:\n";
    foreach ($errors as $e) {
        echo "  - {$e}\n";
    }
}

exit($fail > 0 ? 1 : 0);
