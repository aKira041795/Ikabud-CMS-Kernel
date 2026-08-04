<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';

$passed = 0;
$failed = 0;

$assert = static function (bool $condition, string $label) use (&$passed, &$failed): void {
    if ($condition) {
        $passed++;
        echo "PASS: {$label}\n";
        return;
    }
    $failed++;
    echo "FAIL: {$label}\n";
};

// ═══════════════════════════════════════════════════════════════════════
// E1: PAL extension evidence — proves the product suite + extension model
// is NOT CMS-specific. PAL Core + PAL Advanced Reporting demonstrate the
// same hierarchy/contribution/lifecycle mechanics with host "pal".
// ═══════════════════════════════════════════════════════════════════════

// Live discovery: the pal suite must resolve with core + extension.
$graph = moduleSuiteGraph();
$assert(isset($graph['pal']), 'live discovery exposes pal suite');
$assert(($graph['pal']['core'] ?? '') === 'pal-core', 'pal suite core resolves to pal-core');
$assert(($graph['pal']['name'] ?? '') === 'PAL', 'pal suite name comes from product block');
$assert(in_array('pal-advanced-reporting', $graph['pal']['extensions'] ?? [], true), 'pal-advanced-reporting listed as extension');
$assert(in_array('pal.report.providers', $graph['pal']['extension_points'] ?? [], true), 'pal.report.providers extension point exposed');
$assert(moduleSuiteForModule('pal-advanced-reporting') === 'pal', 'pal-advanced-reporting maps to pal suite');
$assert(moduleExtendsForModule('pal-advanced-reporting') === 'pal-core', 'pal-advanced-reporting extends pal-core');

// Contribution registry: the PAL sidebar contribution must resolve when the
// module is enabled (simulated fleet to avoid tenant DB state).
$palFleet = [
    'pal-core' => [
        'id' => 'pal-core',
        'name' => 'PAL Core',
        'version' => '1.0.0',
        '_enabled' => true,
        'kind' => 'product-core',
        'suite' => 'pal',
        'extension_points' => ['pal.sidebar', 'pal.report.providers'],
    ],
    'pal-advanced-reporting' => [
        'id' => 'pal-advanced-reporting',
        'name' => 'PAL Advanced Reporting',
        'version' => '1.0.0',
        '_enabled' => true,
        'kind' => 'extension',
        'extends' => 'pal-core',
        'admin_contributions' => [[
            'id' => 'pal-advanced-reporting.sidebar',
            'host' => 'pal-core',
            'location' => 'sidebar',
            'group' => 'reports',
            'label' => 'Advanced Reports',
            'icon' => 'chart',
            'route' => '/admin/pal/advanced-reports',
            'permission' => 'pal.reports.advanced.view',
            'order' => 40,
        ]],
    ],
];
$palContribs = kernelContributionsForHostLocation('pal-core', 'sidebar', $palFleet);
$assert(count($palContribs) === 1, 'PAL sidebar contribution resolves (non-CMS host)');
$assert(($palContribs[0]['label'] ?? '') === 'Advanced Reports', 'PAL contribution carries correct label');
$assert(($palContribs[0]['id'] ?? '') === 'pal-advanced-reporting.sidebar', 'PAL contribution uses stable id');

// Install gate: a PAL extension extending pal-core must pass.
$palInstall = [
    'id' => 'pal-advanced-reporting',
    'name' => 'PAL Advanced Reporting',
    'version' => '1.0.0',
    'kind' => 'extension',
    'suite' => 'pal',
    'extends' => 'pal-core',
    'admin_contributions' => [[
        'host' => 'pal-core',
        'location' => 'sidebar',
        'label' => 'Advanced Reports',
        'route' => '/admin/pal/advanced-reports',
    ]],
    'compatibility' => ['kernel' => '>=6.0.0', 'suite' => '>=1.0.0'],
];
$r = validateModuleSuiteContractForInstall($palInstall, $palFleet);
$assert(!empty($r['ok']), 'PAL extension passes install gate');

// Contribution to a PAL extension point the host did NOT declare → rejected.
$badPal = $palInstall;
$badPal['contributes'] = [['extension_point' => 'cms.editor.tools', 'provider' => 'x@1']];
$badFleet = $palFleet;
$badFleet['pal-core']['extension_points'] = ['pal.sidebar', 'pal.report.providers']; // no cms.editor.tools
$r = validateModuleSuiteContractForInstall($badPal, $badFleet);
$assert(empty($r['ok']), 'PAL extension contributing to undeclared point rejected');

// ═══════════════════════════════════════════════════════════════════════
// E2: Adapter evidence — search adapter is a swappable/disableable provider
// that needs no sidebar. Proves extensions are not limited to visible nav.
// ═══════════════════════════════════════════════════════════════════════

// Live: cms-akira-search-adapter is an adapter in the cms-akira suite.
$assert(moduleKindForModule('cms-akira-search-adapter') === 'adapter', 'search adapter classified as adapter');
$assert(moduleExtendsForModule('cms-akira-search-adapter') === 'cms-akira-core', 'search adapter extends cms-akira-core');
$assert(moduleSuiteForModule('cms-akira-search-adapter') === 'cms-akira', 'search adapter belongs to cms-akira suite');

// An adapter contributes no sidebar (provider-only). Its manifest must not
// declare admin_contributions.
$all = discoverModules();
$adapterManifest = $all['cms-akira-search-adapter'] ?? [];
$adapterAdminContribs = is_array($adapterManifest['admin_contributions'] ?? null) ? $adapterManifest['admin_contributions'] : [];
$assert($adapterAdminContribs === [], 'adapter declares no admin contribution (provider-only)');

// Disabling the adapter leaves the suite core operational and removes any
// provider contribution from the registry.
$adapterFleet = [
    'cms-akira-core' => [
        'id' => 'cms-akira-core',
        'name' => 'CMS Akira Core',
        'version' => '1.0.0',
        '_enabled' => true,
        'kind' => 'product-core',
        'suite' => 'cms-akira',
        'extension_points' => ['cms.sidebar', 'cms.content.processors'],
    ],
    'cms-akira-search-adapter' => [
        'id' => 'cms-akira-search-adapter',
        'name' => 'Search Adapter',
        'version' => '1.0.0',
        'suite' => 'cms-akira',
        '_enabled' => true,
        'kind' => 'adapter',
        'extends' => 'cms-akira-core',
        'contributes' => [['extension_point' => 'cms.content.processors', 'provider' => 'cms-akira-search-adapter.search-doc@1']],
    ],
];
$adapterGraph = moduleSuiteGraph($adapterFleet);
$assert(($adapterGraph['cms-akira']['core'] ?? '') === 'cms-akira-core', 'suite core remains after adapter present');
$adapterDisabled = $adapterFleet;
$adapterDisabled['cms-akira-search-adapter']['_enabled'] = false;
$adapterGraphDisabled = moduleSuiteGraph($adapterDisabled);
$assert(($adapterGraphDisabled['cms-akira']['core'] ?? '') === 'cms-akira-core', 'suite core survives adapter disable');
$assert(in_array('cms-akira-search-adapter', $adapterGraphDisabled['cms-akira']['adapters'] ?? [], true), 'disabled adapter remains a suite member (enablement is behavioral, not structural)');

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
