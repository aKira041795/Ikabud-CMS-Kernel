<?php

declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';


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
$h->test(
    'Tailwind bundle is declared in the profile asset manifest',
    isset($assetManifest['assets']['styles']['workbench-tailwind.css'])
);

$h->section('Published asset parity');
foreach (['workbench.css', 'workbench-core.js', 'workbench-tailwind.css', 'workbench-tailwind.src.css'] as $asset) {
    $publicAsset = $base . '/public/assets/workbench/' . $asset;
    $profileAsset = $profileRoot . '/assets/' . $asset;
    $h->test("{$asset} is published identically", hash_file('sha256', $publicAsset) === hash_file('sha256', $profileAsset));
}

$h->section('PAL Workbench wiring');
$renderer = (string)file_get_contents($base . '/kernel/EntityContext/DefaultEntityRenderer.php');
$runtime = (string)file_get_contents($base . '/public/assets/workbench/workbench-core.js');
$tailwindConfig = (string)file_get_contents($base . '/tailwind.config.js');
$tailwindCss = (string)file_get_contents($base . '/public/assets/workbench/workbench-tailwind.css');
$h->test('Entity renderer exposes Workbench preset', str_contains($renderer, "'workbench' => ["));
$h->test('Workbench tables carry responsive classes', str_contains($renderer, 'wb-table wb-table--sticky'));
$h->test('Entity table cells include server-rendered labels', str_contains($renderer, 'data-label="'));
$h->test('Dynamic Workbench controls are observed', str_contains($runtime, 'new MutationObserver'));
$h->test('Tailwind scans the kernel entity renderer', str_contains($tailwindConfig, './kernel/EntityContext/DefaultEntityRenderer.php'));
$h->test('Compiled Tailwind contains brand utilities', str_contains($tailwindCss, '.text-brand-700'));

$palTemplates = [];
$palTemplateIterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
    $base . '/modules/project-audit-ledger/templates',
    FilesystemIterator::SKIP_DOTS
));
foreach ($palTemplateIterator as $templateFile) {
    if ($templateFile->isFile() && $templateFile->getExtension() === 'disyl') {
        $palTemplates[] = $templateFile->getPathname();
    }
}
$tailwindEntityLists = [];
$unwiredEntityLists = [];
foreach ($palTemplates as $template) {
    $contents = (string)file_get_contents($template);
    if (str_contains($contents, 'ikb_entity_list') && str_contains($contents, 'use="tailwind"')) {
        $tailwindEntityLists[] = $template;
    }
    if (preg_match('/\{ikb_entity_list\b(?![^}]*\buse="workbench")/s', $contents)) {
        $unwiredEntityLists[] = $template;
    }
}
$h->assertSame([], $tailwindEntityLists, 'PAL entity lists do not select the Tailwind renderer preset');
$h->assertSame([], $unwiredEntityLists, 'PAL entity lists explicitly select the Workbench renderer preset');

$legacyFormControls = [];
$unwiredFormControls = [];
foreach ($palTemplates as $template) {
    $contents = (string)file_get_contents($template);
    if (preg_match('/class="[^"]*(?:w-full px-[23] py|block text-(?:xs|sm) font-medium)/', $contents)) {
        $legacyFormControls[] = $template;
    }
    preg_match_all('/<(input|select|textarea)\b[^>]*>/s', $contents, $controls);
    foreach ($controls[0] ?? [] as $control) {
        if (preg_match('/type="(?:hidden|checkbox|radio|button|submit|reset)"/', $control)) {
            continue;
        }
        if (!preg_match('/class="[^"]*\bwb-(?:input|select|textarea|table-control|file-input)\b/', $control)) {
            $unwiredFormControls[] = basename($template) . ': ' . substr(preg_replace('/\s+/', ' ', $control), 0, 100);
        }
    }
}
$h->assertSame([], array_values(array_unique($legacyFormControls)), 'PAL templates contain no legacy standard-control signatures');
$h->assertSame([], $unwiredFormControls, 'PAL standard controls explicitly use Workbench form primitives');
$clientForm = (string)file_get_contents($base . '/modules/project-audit-ledger/templates/project-audit-ledger/pages/client-form.disyl');
$supplierForm = (string)file_get_contents($base . '/modules/project-audit-ledger/templates/project-audit-ledger/pages/supplier-form.disyl');
$workbenchCss = (string)file_get_contents($base . '/public/assets/workbench/workbench.css');
$h->test('Client form has padded panel body', str_contains($clientForm, 'class="wb-panel__body"'));
$h->test('Client form has responsive field spacing', str_contains($clientForm, 'wb-form-grid wb-form-grid--2'));
$h->test('Supplier form has padded panel body', str_contains($supplierForm, 'class="wb-panel__body"'));
$h->test('Supplier form has responsive field spacing', str_contains($supplierForm, 'wb-form-grid wb-form-grid--2'));
$h->test('Unpadded panels protect direct form inset', str_contains($workbenchCss, '.wb-panel:not(.p-4):not(.p-6) > form:not(.wb-panel__body)'));
$h->test('Workbench controls use border-box sizing inside form grids', str_contains($workbenchCss, 'box-sizing: border-box;'));
$cliSource = (string)file_get_contents($base . '/ikabud');
$h->test('DiSyL cache clear includes compiled template cache', str_contains($cliSource, "STORAGE_PATH . '/cache/compiled'"));

$shellTemplates = [
    $profileRoot . '/layouts/app-shell.disyl',
    $profileRoot . '/layouts/app-shell-mobile.disyl',
    $profileRoot . '/components/shell/app_shell.disyl',
];
foreach ($shellTemplates as $shellTemplate) {
    $contents = (string)file_get_contents($shellTemplate);
    $h->test(
        basename($shellTemplate) . ' loads Workbench CSS after utilities',
        strpos($contents, 'workbench-tailwind.css') < strpos($contents, 'workbench.css')
    );
}

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

// ═══════════════════════════════════════════════════════════════
// PAL UI Consistency Contract Checks
// ═══════════════════════════════════════════════════════════════
$h->section('PAL UI consistency contract');

// 1. Shell title ownership — no hard-coded <h1>{page_title}
$adminShell = (string)file_get_contents($base . '/modules/project-audit-ledger/templates/project-audit-ledger/shell.disyl');
$tlShell = (string)file_get_contents($base . '/modules/project-audit-ledger/templates/project-audit-ledger/team-lead-shell.disyl');
$h->test('Admin shell does not render own h1 page_title', !str_contains($adminShell, '<h1 class="text-2xl font-bold'));
$h->test('Team Lead shell does not render own h1 page_title', !str_contains($tlShell, '<h1 class="wb-page-header__title'));
$h->test('Admin shell wraps page_body in pal-page', str_contains($adminShell, 'class="pal-page"'));
$h->test('Team Lead shell wraps page_body in pal-page', str_contains($tlShell, 'class="pal-page"'));

// 2. pal-ui.css exists and is referenced
$palCss = $base . '/public/assets/pal/pal-ui.css';
$helpers = (string)file_get_contents($base . '/modules/project-audit-ledger/helpers.php');
$h->test('pal-ui.css file exists', file_exists($palCss));
$h->test('pal-ui.css is referenced in helpers.php', str_contains($helpers, 'pal-ui.css'));
$h->test('Team Lead shell references pal-ui.css', str_contains($tlShell, '/assets/pal/pal-ui.css'));
$palCssContent = (string)file_get_contents($palCss);
$h->test('pal-ui.css contains PAL page layout rules', str_contains($palCssContent, '.pal-page'));
$h->test('pal-ui.css contains modal dialog rules', str_contains($palCssContent, '.pal-modal'));

// 3. Team Lead CA list has valid <table> tag
$tlCaList = (string)file_get_contents($base . '/modules/project-audit-ledger/templates/project-audit-ledger/pages/team-lead-ca-list.disyl');
$h->test('team-lead-ca-list has valid <table> tag', str_contains($tlCaList, '<table'));

// 4. BOM does not use dark table header
$bom = (string)file_get_contents($base . '/modules/project-audit-ledger/templates/project-audit-ledger/pages/bill-of-materials.disyl');
$h->test('BOM table header does not use bg-gray-800', !str_contains($bom, 'bg-gray-800 text-white'));

// 5. Fabrication dues uses shared modal classes
$fabDues = (string)file_get_contents($base . '/modules/project-audit-ledger/templates/project-audit-ledger/pages/fabrication-dues.disyl');
$h->test('Fabrication dues modal uses pal-modal-overlay', str_contains($fabDues, 'pal-modal-overlay'));
$h->test('Fabrication dues modal has role="dialog"', str_contains($fabDues, 'role="dialog"'));

// 6. Team Lead fabrication uses CSS classes instead of inline colors
$tlFab = (string)file_get_contents($base . '/modules/project-audit-ledger/templates/project-audit-ledger/pages/team-lead-fabrication.disyl');
$h->test('Team Lead fabrication uses pal-text-positive/pal-text-negative',
    str_contains($tlFab, 'pal-text-positive') && str_contains($tlFab, 'pal-text-negative'));

// 7. Page templates do not contain <style> blocks (unless explicitly exempt)
// Exempt: standalone auth pages (no Workbench shell), email templates, shell (lightbox CSS)
$exemptStyleFiles = [
    'login.disyl', 'forgot-password.disyl', 'reset-password.disyl',
    'team-lead-login.disyl', 'team-lead-otp-verify.disyl',
    '_email_job_order.disyl', '_email_invoice.disyl',
    'shell.disyl', 'team-lead-shell.disyl',
    'invoice-print.disyl', 'project-print.disyl', 'quotation-print.disyl',
];
$styleBlockTemplates = [];
foreach ($palTemplates as $template) {
    $name = basename($template);
    if (in_array($name, $exemptStyleFiles, true)) continue;
    $contents = (string)file_get_contents($template);
    if (preg_match('/<style[^>]*>/i', $contents)) {
        $styleBlockTemplates[] = $name;
    }
}
$h->assertSame([], $styleBlockTemplates, 'Page templates contain no <style> blocks (exempt: standalone auth, email, shell)');

$h->done();
