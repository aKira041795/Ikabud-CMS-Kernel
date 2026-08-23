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

// ── S4: stable contribution ids ──────────────────────────────────────────
$idFleet = [
    'mod-a' => ['id' => 'mod-a', 'name' => 'A', 'version' => '1.0.0', '_enabled' => true, 'admin_contributions' => [
        ['host' => 'cms', 'location' => 'sidebar', 'label' => 'A Nav', 'route' => '/admin/a'],
    ]],
    'mod-b' => ['id' => 'mod-b', 'name' => 'B', 'version' => '1.0.0', '_enabled' => true, 'admin_contributions' => [
        ['host' => 'cms', 'location' => 'sidebar', 'id' => 'mod-b.other', 'label' => 'B Nav', 'route' => '/admin/b'],
    ]],
    'mod-c' => ['id' => 'mod-c', 'name' => 'C', 'version' => '1.0.0', '_enabled' => true, 'admin_contributions' => [
        ['host' => 'cms', 'location' => 'sidebar', 'id' => 'mod-b.other', 'label' => 'C Dup', 'route' => '/admin/c'],
    ]],
];
$idRegistry = kernelContributionRegistry($idFleet);
$idSidebar = $idRegistry['cms:sidebar'] ?? [];
$idLabels = array_column($idSidebar, 'label');
$assert(count($idSidebar) === 2, 'duplicate contribution id drops the later entry', (string)count($idSidebar));
$assert(in_array('A Nav', $idLabels, true), 'first module contribution present');
$assert(in_array('B Nav', $idLabels, true), 'second module contribution present');
$assert(!in_array('C Dup', $idLabels, true), 'duplicate-id contribution rejected (first-wins)');
$assert(($idRegistry['_conflicts'][0]['module'] ?? '') === 'mod-c', 'duplicate conflict recorded with module id');

// ── S4: role-based contribution filtering ────────────────────────────────
$roleFleet = [
    'mod-r1' => ['id' => 'mod-r1', 'name' => 'R1', 'version' => '1.0.0', '_enabled' => true, 'admin_contributions' => [
        ['host' => 'cms', 'location' => 'sidebar', 'label' => 'Admin Only', 'route' => '/admin/r1', 'roles' => ['admin']],
    ]],
    'mod-r2' => ['id' => 'mod-r2', 'name' => 'R2', 'version' => '1.0.0', '_enabled' => true, 'admin_contributions' => [
        ['host' => 'cms', 'location' => 'sidebar', 'label' => 'Everyone', 'route' => '/admin/r2'],
    ]],
];
$adminCtx = ['user' => ['role' => 'admin']];
$adminNav = kernelContributionsForHostLocation('cms', 'sidebar', $roleFleet, $adminCtx);
$adminLabels = array_column($adminNav, 'label');
$assert(in_array('Admin Only', $adminLabels, true), 'admin role sees role-restricted contribution');
$assert(in_array('Everyone', $adminLabels, true), 'admin role sees unrestricted contribution');
$editorCtx = ['user' => ['role' => 'editor']];
$editorNav = kernelContributionsForHostLocation('cms', 'sidebar', $roleFleet, $editorCtx);
$editorLabels = array_column($editorNav, 'label');
$assert(!in_array('Admin Only', $editorLabels, true), 'non-admin role does not leak role-restricted contribution');
$assert(in_array('Everyone', $editorLabels, true), 'non-admin role sees unrestricted contribution');

// ── S4: tenant-level contribution filtering ──────────────────────────────
$tenantFleet = [
    'mod-t' => ['id' => 'mod-t', 'name' => 'T', 'version' => '1.0.0', '_enabled' => true, 'admin_contributions' => [
        ['host' => 'cms', 'location' => 'sidebar', 'label' => 'Tenant Surf', 'route' => '/admin/t'],
    ]],
];
// isModuleEnabledForTenant reads tenant settings; without a real tenant DB row
// the default-enabled path applies. We assert the context plumbing does not
// crash and that context tenant id resolution works.
$ctxTenant = kernelContributionContextTenantId(['tenant_id' => 42]);
$assert($ctxTenant === 42, 'context tenant_id resolves from explicit context');
$ctxTenantStr = kernelContributionContextTenantId(['tenant_id' => '7']);
$assert($ctxTenantStr === 7, 'context tenant_id resolves from numeric string');
$ctxTenantNone = kernelContributionContextTenantId([]);
$assert($ctxTenantNone === null || is_int($ctxTenantNone), 'no tenant context yields null or current tenant');
$tenantNav = kernelContributionsForHostLocation('cms', 'sidebar', $tenantFleet, ['tenant_id' => 42]);
$assert(is_array($tenantNav), 'tenant-context query returns array without error');

// ── host-prefixed location convention (cms.sidebar == host cms + loc sidebar) ──
// Both bare ('sidebar') and host-prefixed ('cms.sidebar') locations must match
// a query for host='cms', location='sidebar'. This closes the drift where a
// manifest declared 'cms.sidebar' but the CMS sidebar bridge queries 'sidebar'.
$hostPrefixFleet = [
    'cms-akira-theme' => [
        'id' => 'cms-akira-theme', 'name' => 'Theme', 'version' => '1.0.0', '_enabled' => true,
        'admin_contributions' => [
            ['id' => 'cms-akira-theme.sidebar', 'host' => 'cms', 'location' => 'cms.sidebar', 'label' => 'Theme Studio', 'route' => '/admin/theme-studio', 'order' => 60],
        ],
    ],
    'cms-akira-core' => [
        'id' => 'cms-akira-core', 'name' => 'Core', 'version' => '1.0.0', '_enabled' => true,
        'admin_contributions' => [
            ['id' => 'cms-akira-core.ark-panel', 'host' => 'cms', 'location' => 'dashboard.widgets', 'label' => 'ARK Status', 'route' => '/admin/ark-status', 'order' => 80],
        ],
    ],
];
$prefixedNav = kernelContributionsForHostLocation('cms', 'sidebar', $hostPrefixFleet);
$prefixedLabels = array_column($prefixedNav, 'label');
$assert(in_array('Theme Studio', $prefixedLabels, true), 'host-prefixed location cms.sidebar matches query (cms, sidebar)', json_encode($prefixedLabels));
$widgetNav = kernelContributionsForHostLocation('cms', 'dashboard.widgets', $hostPrefixFleet);
$widgetLabels = array_column($widgetNav, 'label');
$assert(in_array('ARK Status', $widgetLabels, true), 'bare location dashboard.widgets matches query (cms, dashboard.widgets)');
$wrongLoc = kernelContributionsForHostLocation('cms', 'dashboard', $hostPrefixFleet);
$assert(count($wrongLoc) === 0, 'query (cms, dashboard) does NOT match dashboard.widgets (no prefix false-positive)');

// ── live hook registration sanity ────────────────────────────────────────
$hooks = app()->hooks();
if (function_exists('cmsGetExtensionNavItems')) {
    $liveItems = cmsGetExtensionNavItems();
    $assert(is_array($liveItems), 'cmsGetExtensionNavItems returns array when CMS loaded');
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
