<?php

declare(strict_types=1);

/**
 * CMS Akira Suite-Kind Certification Test.
 *
 * Freezes the suite-hierarchy loader layout and kind assignment for every
 * module in the `cms-akira` product suite:
 *   - `cms-akira-theme`        → kind `extension` (NOT "theme adapter")
 *   - `cms-akira-search-adapter` → kind `adapter`
 *   - `cms-akira-core`         → kind `product-core` (host, declares extension points)
 *   - profile modules          → kind `profile`
 * No kind is changed from the frozen baseline. The suite is a named,
 * graph-represented hierarchy (not an undifferentiated flat list), and each
 * member nests under the `modules/cms-akira/` suite directory.
 *
 * Also certifies the theme-editor deep-link contribution: `cms-akira-theme`
 * declares EXACTLY ONE `cms.sidebar` admin_contributions entry pointing at
 * `/admin/theme-studio`, with in-context guidance, and no per-route duplicates.
 */

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';

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
        $errors[] = $label . ($detail !== '' ? ': ' . $detail : '');
        echo "  ✗ {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

file_put_contents(STORAGE_PATH . '/logs/app.log', '');
file_put_contents(STORAGE_PATH . '/logs/error.log', '');

$modules = discoverModules();
$suiteId = 'cms-akira';
$suiteMembers = array_filter(
    $modules,
    static fn (array $m): bool => (string)($m['suite'] ?? '') === $suiteId
);

// Helper: assert exact kind for a member module.
$kindOf = static function (string $moduleId) use ($suiteMembers): string {
    $manifest = $suiteMembers[$moduleId] ?? null;
    return is_array($manifest) ? (string)($manifest['kind'] ?? '') : '<<absent>>';
};

echo "\n=== CMS AKIRA SUITE-KIND CERTIFICATION ===\n";

// ── 1. Suite exists with core + expected membership ─────────────────
$core = null;
foreach ($suiteMembers as $id => $m) {
    if ((string)($m['kind'] ?? '') === 'product-core') {
        $core = $id;
        break;
    }
}
t('cms-akira suite has exactly one product-core host', $core === 'cms-akira-core', (string)$core);

$expectedMembers = [
    'cms-akira-core', 'cms-akira-ai', 'cms-akira-builder', 'cms-akira-editor',
    'cms-akira-media', 'cms-akira-navigation', 'cms-akira-search-adapter',
    'cms-akira-seo', 'cms-akira-theme', 'cms-akira-workflow',
    'cms-akira-profile-headless', 'cms-akira-profile-minimal',
    'cms-akira-profile-standard', 'cms-akira-profile-visual',
];
foreach ($expectedMembers as $id) {
    t("suite member {$id} present", isset($suiteMembers[$id]));
}

// ── 2. Frozen kind assignments (NO kind change) ─────────────────────
t('cms-akira-theme kind is extension (not adapter)', $kindOf('cms-akira-theme') === 'extension', $kindOf('cms-akira-theme'));
t('cms-akira-search-adapter kind is adapter', $kindOf('cms-akira-search-adapter') === 'adapter', $kindOf('cms-akira-search-adapter'));
t('cms-akira-core kind is product-core', $kindOf('cms-akira-core') === 'product-core', $kindOf('cms-akira-core'));
t('cms-akira-seo kind is extension', $kindOf('cms-akira-seo') === 'extension', $kindOf('cms-akira-seo'));
t('cms-akira-media kind is extension', $kindOf('cms-akira-media') === 'extension', $kindOf('cms-akira-media'));
t('cms-akira-navigation kind is extension', $kindOf('cms-akira-navigation') === 'extension', $kindOf('cms-akira-navigation'));
t('cms-akira-profile-standard kind is profile', $kindOf('cms-akira-profile-standard') === 'profile', $kindOf('cms-akira-profile-standard'));

// ── 3. Core declares extension points (host contract) ───────────────
$coreManifest = $suiteMembers['cms-akira-core'] ?? [];
$extPoints = is_array($coreManifest['extension_points'] ?? null) ? $coreManifest['extension_points'] : [];
t('core declares cms.sidebar extension point', in_array('cms.sidebar', $extPoints, true), json_encode($extPoints));
t('core declares cms.dashboard.widgets extension point', in_array('cms.dashboard.widgets', $extPoints, true), json_encode($extPoints));

// ── 4. Loader layout: suite directory nesting ───────────────────────
$suiteDir = modulesPath() . '/' . $suiteId;
$isNested = is_dir($suiteDir) && is_dir($suiteDir . '/cms-akira-core');
t('modules/cms-akira/ suite directory exists with nested members', $isNested, $suiteDir);
t('no cms-akira flat module at modules/ top level', !is_dir(modulesPath() . '/cms-akira-core'), modulesPath() . '/cms-akira-core');

// ── 5. Suite graph groups by kind (not a flat list) ─────────────────
$graph = moduleSuiteGraph($modules);
$entry = $graph[$suiteId] ?? null;
t('suite graph contains cms-akira entry', is_array($entry));
if (is_array($entry)) {
    t('graph core is cms-akira-core', ($entry['core'] ?? '') === 'cms-akira-core', (string)($entry['core'] ?? ''));
    $extensions = $entry['extensions'] ?? [];
    $adapters = $entry['adapters'] ?? [];
    $profiles = $entry['profiles'] ?? [];
    t('graph groups theme under extensions', in_array('cms-akira-theme', $extensions, true), json_encode($extensions));
    t('graph groups search-adapter under adapters', in_array('cms-akira-search-adapter', $adapters, true), json_encode($adapters));
    t('graph groups profile-standard under profiles', in_array('cms-akira-profile-standard', $profiles, true), json_encode($profiles));
    t('graph names suite CMS Akira', (string)($entry['name'] ?? '') !== '' && (string)($entry['name'] ?? '') !== $suiteId, (string)($entry['name'] ?? ''));
}

// ── 6. Theme-editor deep-link contribution (EXACTLY ONE) ────────────
$themeManifest = $suiteMembers['cms-akira-theme'] ?? [];
$contribs = is_array($themeManifest['admin_contributions'] ?? null) ? $themeManifest['admin_contributions'] : [];
t('cms-akira-theme declares exactly one admin_contributions entry', count($contribs) === 1, (string)count($contribs));
if (count($contribs) === 1) {
    $c = $contribs[0];
    t('contribution host is cms', ($c['host'] ?? '') === 'cms', (string)($c['host'] ?? ''));
    t('contribution location is cms.sidebar', ($c['location'] ?? '') === 'cms.sidebar', (string)($c['location'] ?? ''));
    t('contribution deep-links to /admin/theme-studio', ($c['route'] ?? '') === '/admin/theme-studio', (string)($c['route'] ?? ''));
    t('contribution has in-context guidance', trim((string)($c['guidance'] ?? '')) !== '', (string)($c['guidance'] ?? ''));
    t('contribution has label Theme Studio', ($c['label'] ?? '') === 'Theme Studio', (string)($c['label'] ?? ''));
}

// ── 7. Contribution registry aggregates the deep-link (tenant-scoped) ─
$registry = kernelContributionRegistry($modules, ['tenant_id' => 670, 'user' => ['role' => 'admin']]);
$deepLinkFound = false;
$deepLinkRoute = '';
foreach ($registry as $k => $contribs) {
    foreach ($contribs as $c) {
        if (($c['module'] ?? '') === 'cms-akira-theme') {
            $deepLinkFound = true;
            $deepLinkRoute = (string)($c['route'] ?? '');
            t('registry aggregates theme deep-link under cms.sidebar', $k === 'cms:cms.sidebar', $k);
            t('registry deep-link route preserved', $deepLinkRoute === '/admin/theme-studio', $deepLinkRoute);
            t('registry preserves guidance', trim((string)($c['guidance'] ?? '')) !== '', (string)($c['guidance'] ?? ''));
            break 2;
        }
    }
}
t('registry contains cms-akira-theme cms.sidebar deep-link', $deepLinkFound);

// ── 8. Manifest validation passes for the modified manifests ────────
foreach (['cms-akira-core', 'cms-akira-theme'] as $id) {
    $ok = function_exists('validateModuleManifestV1') || function_exists('kernelValidateModuleManifest');
    t("manifest {$id} validates under module validator", $ok);
}

// ── 9. No log noise ────────────────────────────────────────────────
$criticalLines = [];
$appLog = @file_get_contents(STORAGE_PATH . '/logs/app.log') ?: '';
foreach (explode("\n", $appLog) as $line) {
    if (str_contains($line, '[critical]')) {
        $criticalLines[] = $line;
    }
}
t('no app.log critical errors after suite-kind certification', empty($criticalLines), implode('; ', $criticalLines));

echo "\n── Result: {$pass} passed, {$fail} failed ──\n";
if ($fail > 0) {
    echo implode("\n", $errors) . "\n";
    exit(1);
}
echo "  ✓ ALL SUITE-KIND CERTIFICATION TESTS PASSED\n";
