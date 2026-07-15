<?php

declare(strict_types=1);

require_once __DIR__ . '/harness/TestHarness.php';
require_once __DIR__ . '/../kernel/Contracts/ApplicationProfileProvider.php';
require_once __DIR__ . '/../kernel/Services/ApplicationProfileRegistry.php';
require_once __DIR__ . '/../kernel/Testing/WorkbenchContractValidator.php';
require_once __DIR__ . '/../storage/application-profiles/ark-workbench/src/ArkWorkbenchProvider.php';

$h = new TestHarness('workbench-profile-contract');

$h->fingerprint('storage/application-profiles/ark-workbench/profile.manifest.json');
$h->fingerprint('storage/application-profiles/ark-workbench/design-policy.json');
$h->fingerprint('storage/application-profiles/ark-workbench/customizer.schema.json');
$h->fingerprint('storage/application-profiles/ark-workbench/assets/asset-manifest.json');
$h->fingerprint('storage/application-profiles/ark-workbench/src/ArkWorkbenchProvider.php');
$h->fingerprint('kernel/Testing/WorkbenchContractValidator.php');

$base = dirname(__DIR__);
$profileRoot = $base . '/storage/application-profiles/ark-workbench';

$readJson = static function (string $path): array {
    $decoded = json_decode((string)file_get_contents($path), true);
    return is_array($decoded) ? $decoded : [];
};

$manifest = $readJson($profileRoot . '/profile.manifest.json');
$policy = $readJson($profileRoot . '/design-policy.json');
$customizer = $readJson($profileRoot . '/customizer.schema.json');
$assetManifest = $readJson($profileRoot . '/assets/asset-manifest.json');

$h->section('Provider registration');
$provider = new \Ikabud\ApplicationProfiles\ArkWorkbench\ArkWorkbenchProvider();
\Ikabud\Kernel\Services\ApplicationProfileRegistry::reset();
\Ikabud\Kernel\Services\ApplicationProfileRegistry::register($provider);
$h->assertSame('ark.workbench', $provider->id(), 'Provider id is ark.workbench');
$h->test('Provider is registered by canonical namespace', \Ikabud\Kernel\Services\ApplicationProfileRegistry::has('ark.workbench'));

$h->section('Manifest synchronization');
$manifestSections = $manifest['customizer']['sections'] ?? [];
$schemaSections = array_keys($customizer['sections'] ?? []);
sort($manifestSections);
sort($schemaSections);
$h->assertSame($schemaSections, $manifestSections, 'Customizer manifest sections match schema sections');

$manifestPolicy = $manifest['design_policy'] ?? [];
foreach (['configurable', 'locked', 'tone_contract', 'resolution_precedence'] as $key) {
    $h->assertSame($policy[$key] ?? null, $manifestPolicy[$key] ?? null, "Design policy {$key} matches manifest");
}

$profileComponentAssets = $manifest['assets']['components'] ?? [];
$assetScripts = array_keys($assetManifest['assets']['scripts'] ?? []);
$declaredScripts = [];
foreach ($profileComponentAssets as $scripts) {
    foreach ($scripts as $script) {
        $declaredScripts[] = basename((string)$script);
    }
}
$h->test(
    'Responsive table script is declared in profile manifest',
    in_array('assets/workbench-table.js', $profileComponentAssets['workbench:responsive_table'] ?? [], true)
);
$h->test(
    'Every component script declared by profile exists in asset manifest',
    count(array_diff($declaredScripts, $assetScripts)) === 0
);

$h->section('Contract validator coverage');
$validator = new \Ikabud\Kernel\Testing\WorkbenchContractValidator();
$validatorReflection = new ReflectionClass($validator);
$camelCase = static function (string $key): string {
    return str_replace(' ', '', ucwords(str_replace(['_', '.'], ' ', $key)));
};

foreach (glob($profileRoot . '/contracts/*.contract.json') ?: [] as $contractFile) {
    $contract = $readJson($contractFile);
    foreach (array_keys($contract['requirements'] ?? []) as $requirement) {
        $method = 'check' . $camelCase($requirement);
        $h->test(basename($contractFile) . " requirement {$requirement} is supported", $validatorReflection->hasMethod($method));
    }
}

$h->section('Validator behavior');
$statusHtml = '<span data-wb-component="status-badge" data-wb-tone="approved">Approved</span>';
$violations = $validator->validate('status-badge', $statusHtml, ['tone' => 'approved']);
$h->test('Invalid domain status tone is rejected', isset($violations['valid_tones']));

$comboboxHtml = '<input role="combobox" aria-expanded="false" aria-autocomplete="list">';
$violations = $validator->validate('combobox', $comboboxHtml, ['name' => 'client', 'options' => []]);
$h->test('Missing aria-owns is rejected for combobox', isset($violations['aria_owns']));

$summaryHtml = '<div data-wb-component="summary-card" data-wb-tone="success" data-wb-href="/x" role="link"><p>Total</p><p>123</p></div>';
$violations = $validator->validate('summary-card', $summaryHtml, [
    'label' => 'Total',
    'value' => '123',
    'tone' => 'success',
    'href' => '/x',
]);
$h->assertSame([], $violations, 'Valid summary card passes contract validation');

$emptyTableHtml = '<div data-wb-component="responsive-table"><table><thead><tr><th scope="col">Name</th></tr></thead><tbody><tr><td colspan="1">No items</td></tr></tbody></table></div>';
$violations = $validator->validate('responsive-table', $emptyTableHtml, ['state' => 'empty']);
$h->test('Empty responsive table row does not require entity id', !isset($violations['entity_rows_have_data_wb_entity_id']));

$h->done();
