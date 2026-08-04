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

// ── normalization ────────────────────────────────────────────────────────
$raw = [
    'host' => 'cms',
    'location' => 'sidebar',
    'group' => 'optimization',
    'label' => 'SEO',
    'icon' => 'search',
    'route' => '/admin/cms/seo',
    'permission' => 'cms.seo.manage',
    'order' => 60,
];
$normalized = kernelContributionNormalize($raw, 'cms-akira-seo');
$assert($normalized['host'] === 'cms', 'normalize keeps host');
$assert($normalized['label'] === 'SEO', 'normalize keeps label');
$assert($normalized['order'] === 60, 'normalize keeps order');
$assert($normalized['module'] === 'cms-akira-seo', 'normalize stamps module id');
$assert(kernelContributionNormalize(['host' => 'cms', 'location' => 'x', 'label' => 'A', 'route' => '/a'], 'm')['icon'] === '', 'normalize defaults icon to empty');

// ── registry aggregation ─────────────────────────────────────────────────
// Use an in-memory module map: two enabled modules contribute to host cms
// sidebar with ordering, one disabled module must be excluded.
$fleet = [
    'cms-akira-seo' => [
        'id' => 'cms-akira-seo',
        'name' => 'SEO',
        'version' => '1.0.0',
        '_enabled' => true,
        'admin_contributions' => [
            ['host' => 'cms', 'location' => 'sidebar', 'group' => 'optimization', 'label' => 'SEO', 'route' => '/admin/cms/seo', 'order' => 60],
        ],
    ],
    'cms-akira-workflow' => [
        'id' => 'cms-akira-workflow',
        'name' => 'Workflow',
        'version' => '1.0.0',
        '_enabled' => true,
        'admin_contributions' => [
            ['host' => 'cms', 'location' => 'sidebar', 'label' => 'Workflow', 'route' => '/admin/cms/workflow', 'order' => 10],
        ],
    ],
    'cms-akira-disabled' => [
        'id' => 'cms-akira-disabled',
        'name' => 'Disabled',
        'version' => '1.0.0',
        '_enabled' => false,
        'admin_contributions' => [
            ['host' => 'cms', 'location' => 'sidebar', 'label' => 'Hidden', 'route' => '/admin/cms/hidden'],
        ],
    ],
];

$registry = kernelContributionRegistry($fleet);
$assert(isset($registry['cms:sidebar']), 'registry aggregates cms:sidebar key');
$assert(!isset($registry['cms:other']), 'registry does not invent locations');
$sidebar = $registry['cms:sidebar'];
$assert(count($sidebar) === 2, 'disabled module contributes nothing', (string)count($sidebar));
$assert($sidebar[0]['label'] === 'Workflow', 'contributions sorted by order (10 before 60)');
$assert($sidebar[1]['label'] === 'SEO', 'contributions sorted by order (60 after 10)');

// ── host/location queries ────────────────────────────────────────────────
// These query live discovery (no in-memory override), so only check shape.
$hostContribs = kernelContributionsForHost('cms', 'sidebar');
$assert(is_array($hostContribs), 'kernelContributionsForHost returns array');
$locContribs = kernelContributionsForHostLocation('cms', 'sidebar');
$assert(is_array($locContribs), 'kernelContributionsForHostLocation returns array');

// ── bridge folds manifest contributions into cms.admin.nav_items ─────────
$bridge = kernelContributionBridgeCmsNavItems($fleet);
$result = $bridge([]);
$assert(is_array($result), 'bridge returns array');
$labels = array_column($result, 'label');
$assert(in_array('Workflow', $labels, true), 'bridge folds ungrouped contribution as flat item');
$assert(in_array('Optimization', $labels, true), 'bridge creates section for grouped contribution');
$sectionIdx = array_search('Optimization', $labels, true);
$section = $result[$sectionIdx] ?? null;
$assert(is_array($section) && !empty($section['section']), 'bridge marks grouped item as section');
$assert(is_array($section['children'] ?? null) && ($section['children'][0]['label'] ?? '') === 'SEO', 'bridge nests SEO as child of Optimization section');

// ── live hook registration sanity ────────────────────────────────────────
$hooks = app()->hooks();
if (function_exists('cmsGetExtensionNavItems')) {
    $liveItems = cmsGetExtensionNavItems();
    $assert(is_array($liveItems), 'cmsGetExtensionNavItems returns array when CMS loaded');
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
