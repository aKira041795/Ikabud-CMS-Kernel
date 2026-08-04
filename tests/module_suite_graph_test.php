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

// In-memory module fleet simulating a product suite with core, extensions,
// adapters, and profiles — no filesystem or DB required.
$fleet = [
    'cms-akira-core' => [
        'id' => 'cms-akira-core',
        'name' => 'CMS Akira Core',
        'version' => '1.0.0',
        'kind' => 'product-core',
        'suite' => 'cms-akira',
        'product' => ['id' => 'cms-akira', 'name' => 'CMS Akira'],
        'extension_points' => ['cms.sidebar', 'cms.settings.sections', 'cms.content.processors'],
    ],
    'cms-akira-seo' => [
        'id' => 'cms-akira-seo',
        'name' => 'CMS Akira SEO',
        'version' => '1.0.0',
        'kind' => 'extension',
        'suite' => 'cms-akira',
        'extends' => 'cms-akira-core',
    ],
    'cms-akira-search-adapter' => [
        'id' => 'cms-akira-search-adapter',
        'name' => 'CMS Akira Search Adapter',
        'version' => '1.0.0',
        'kind' => 'adapter',
        'suite' => 'cms-akira',
        'extends' => 'cms-akira-core',
    ],
    'cms-akira-profile-standard' => [
        'id' => 'cms-akira-profile-standard',
        'name' => 'CMS Akira Standard',
        'version' => '1.0.0',
        'kind' => 'profile',
        'suite' => 'cms-akira',
        'installs' => ['cms-akira-core', 'cms-akira-editor', 'cms-akira-theme'],
    ],
    'pal-core' => [
        'id' => 'pal-core',
        'name' => 'PAL Core',
        'version' => '1.0.0',
        'kind' => 'product-core',
        'suite' => 'pal',
        'product' => ['id' => 'pal', 'name' => 'PAL'],
        'extension_points' => ['pal.sidebar', 'pal.case.actions'],
    ],
    'daily-ledger' => [
        'id' => 'daily-ledger',
        'name' => 'Daily Ledger',
        'version' => '1.0.0',
        // legacy: no kind/suite → standalone-application
    ],
];

// ── graph structure ──────────────────────────────────────────────────────
$graph = moduleSuiteGraph($fleet);

$assert(isset($graph['cms-akira']), 'cms-akira suite exists in graph');
$assert(isset($graph['pal']), 'pal suite exists in graph');
$assert(!isset($graph['daily-ledger']), 'legacy module without suite is not in graph');

$akira = $graph['cms-akira'];
$assert($akira['core'] === 'cms-akira-core', 'cms-akira core resolves to cms-akira-core');
$assert($akira['name'] === 'CMS Akira', 'cms-akira name comes from product block');
$assert(in_array('cms-akira-seo', $akira['extensions'], true), 'cms-akira-seo listed as extension');
$assert(in_array('cms-akira-search-adapter', $akira['adapters'], true), 'search adapter listed as adapter');
$assert(in_array('cms-akira-profile-standard', $akira['profiles'], true), 'standard profile listed as profile');
$assert(in_array('cms.sidebar', $akira['extension_points'], true), 'cms.sidebar in extension points');
$assert(in_array('cms.content.processors', $akira['extension_points'], true), 'cms.content.processors in extension points');
$assert(count($akira['modules']) === 4, 'cms-akira has 4 members');

$pal = $graph['pal'];
$assert($pal['core'] === 'pal-core', 'pal core resolves to pal-core');
$assert(in_array('pal.case.actions', $pal['extension_points'], true), 'pal.case.actions in extension points');

// ── helper APIs ──────────────────────────────────────────────────────────
$assert(moduleSuites($fleet) === ['cms-akira', 'pal'], 'moduleSuites lists both suites');
$assert(moduleSuiteMembers('cms-akira', $fleet) === ['cms-akira-core', 'cms-akira-profile-standard', 'cms-akira-search-adapter', 'cms-akira-seo'], 'moduleSuiteMembers returns sorted members', json_encode(moduleSuiteMembers('cms-akira', $fleet)));
$assert(moduleSuiteCore('cms-akira', $fleet) === 'cms-akira-core', 'moduleSuiteCore resolves core');
$assert(moduleSuiteCore('ghost', $fleet) === null, 'moduleSuiteCore returns null for unknown suite');
$assert(moduleSuiteExtensionPoints('cms-akira', $fleet) === ['cms.content.processors', 'cms.settings.sections', 'cms.sidebar'], 'moduleSuiteExtensionPoints returns sorted points', json_encode(moduleSuiteExtensionPoints('cms-akira', $fleet)));

// ── live discovery integration (real fleet, read-only) ──────────────────
// The POC manifests now declare kind/suite, so the live fleet must resolve
// the full cms-akira hierarchy.
$liveGraph = moduleSuiteGraph();
$assert(isset($liveGraph['cms-akira']), 'live discovery exposes cms-akira suite');
$assert($liveGraph['cms-akira']['core'] === 'cms-akira-core', 'live discovery resolves cms-akira core');
$assert($liveGraph['cms-akira']['name'] === 'CMS Akira', 'live discovery uses product block name');
$assert(in_array('cms-akira-seo', $liveGraph['cms-akira']['extensions'] ?? [], true), 'live discovery lists cms-akira-seo as extension');
$assert(in_array('cms-akira-search-adapter', $liveGraph['cms-akira']['adapters'] ?? [], true), 'live discovery lists search adapter as adapter');
$assert(in_array('cms-akira-profile-standard', $liveGraph['cms-akira']['profiles'] ?? [], true), 'live discovery lists standard profile');
$assert(in_array('cms.sidebar', $liveGraph['cms-akira']['extension_points'] ?? [], true), 'live discovery exposes cms.sidebar extension point');
$assert(moduleSuiteForModule('cms-akira-seo') === 'cms-akira', 'live discovery maps cms-akira-seo to suite');
$assert(moduleKindForModule('cms-akira-seo') === 'extension', 'live discovery classifies cms-akira-seo as extension');
$assert(moduleExtendsForModule('cms-akira-seo') === 'cms-akira-core', 'live discovery resolves cms-akira-seo extends target');

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
