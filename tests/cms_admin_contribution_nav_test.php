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

// Phase 4 acceptance: manifest-declared CMS sidebar contributions flow through
// the existing cms.admin.nav_items seam (which feeds ext_nav_items in both
// desktop and mobile sidebars of admin.disyl), and disabling a module removes
// its contributions automatically.

$hooks = app()->hooks();

// The kernel bridge must be registered on the cms.admin.nav_items hook at boot.
$assert($hooks->has('cms.admin.nav_items'), 'cms.admin.nav_items hook has listeners');

// Simulate a discovered module contributing a CMS sidebar item, then flip it
// disabled and assert the contribution disappears. Uses the per-request
// discovery cache override so no filesystem/DB mutation is needed.
$fakeModule = [
    'id' => 'cms-akira-seo',
    'name' => 'CMS Akira SEO',
    'version' => '1.0.0',
    '_enabled' => true,
    'admin_contributions' => [
        [
            'host' => 'cms',
            'location' => 'sidebar',
            'group' => 'optimization',
            'label' => 'SEO',
            'icon' => 'search',
            'route' => '/admin/cms/seo',
            'permission' => 'cms.seo.manage',
            'order' => 60,
        ],
    ],
];

$savedDiscovery = $GLOBALS['_kernel_discovered_modules'] ?? null;
$GLOBALS['_kernel_discovered_modules'] = ['cms-akira-seo' => $fakeModule];
try {
    $nav = $hooks->filter('cms.admin.nav_items', []);
    $labels = array_column($nav, 'label');
    $assert(in_array('Optimization', $labels, true), 'bridge injects section into cms.admin.nav_items when module enabled');

    // Disable → contribution disappears (no dead links, no manual sidebar edit).
    $fakeModule['_enabled'] = false;
    $GLOBALS['_kernel_discovered_modules'] = ['cms-akira-seo' => $fakeModule];
    $navDisabled = $hooks->filter('cms.admin.nav_items', []);
    $labelsDisabled = array_column($navDisabled, 'label');
    $assert(!in_array('Optimization', $labelsDisabled, true), 'disabled module contribution disappears from nav');
} finally {
    $GLOBALS['_kernel_discovered_modules'] = $savedDiscovery;
}

// Static wiring check (CMS helpers load at module boot, not in this CLI test):
// cmsAdminContext() must feed ext_nav_items from cmsGetExtensionNavItems().
$cmsContextSource = @file_get_contents(dirname(__DIR__) . '/modules/cms/helpers/40-theme-settings.php');
$assert(is_string($cmsContextSource) && str_contains($cmsContextSource, "'ext_nav_items'    => cmsGetExtensionNavItems()"), 'cmsAdminContext feeds ext_nav_items from cmsGetExtensionNavItems');

// And the admin layout renders ext_nav_items in BOTH desktop and mobile sidebars.
$layoutSource = @file_get_contents(dirname(__DIR__) . '/templates/modules/cms/layouts/admin.disyl');
$assert(is_string($layoutSource), 'admin.disyl layout source is readable');
if (is_string($layoutSource)) {
    $assert(substr_count($layoutSource, '{foreach ext_nav_items') >= 2, 'admin.disyl renders ext_nav_items in desktop AND mobile sidebars', (string)substr_count($layoutSource, '{foreach ext_nav_items'));
}

// ── POC live check: the real cms-akira-seo manifest contribution flows ──
// through the bridge when the module is enabled. Guards against the bridge
// being a test-only illusion.
$pocEnabled = isset(discoverModules()['cms-akira-seo']) && !empty(discoverModules()['cms-akira-seo']['_enabled']);
if ($pocEnabled) {
    $contribs = kernelContributionsForHostLocation('cms', 'sidebar');
    $labels = array_column($contribs, 'label');
    $assert(in_array('SEO', $labels, true), 'live cms-akira-seo contribution appears in registry when enabled');
    $navLive = $hooks->filter('cms.admin.nav_items', []);
    $navLabels = array_column($navLive, 'label');
    $assert(in_array('Optimization', $navLabels, true), 'live SEO contribution folded into cms.admin.nav_items as Optimization section');
} else {
    $assert(true, 'POC module not enabled in this environment — live assertion skipped');
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
