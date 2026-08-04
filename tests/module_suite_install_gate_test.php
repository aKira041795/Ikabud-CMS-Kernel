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

// In-memory fleet simulating an installed product core.
$fleet = [
    'cms-akira-core' => [
        'id' => 'cms-akira-core',
        'name' => 'Core',
        'version' => '1.0.0',
        'kind' => 'product-core',
        'suite' => 'cms-akira',
        'extension_points' => ['cms.sidebar', 'cms.settings.sections'],
    ],
    'cms' => ['id' => 'cms', 'name' => 'CMS', 'version' => '1.0.0'],
];

// ── valid extension passes ───────────────────────────────────────────────
// G5 route-ownership resolves against the real on-disk cms-akira-seo module,
// so the contribution route must match one it actually registers.
$validExt = [
    'id' => 'cms-akira-seo',
    'name' => 'SEO',
    'version' => '1.0.0',
    'kind' => 'extension',
    'extends' => 'cms-akira-core',
    'contributes' => [['extension_point' => 'cms.sidebar', 'provider' => 'cms-akira-seo.nav@1']],
    'admin_contributions' => [['host' => 'cms', 'location' => 'sidebar', 'label' => 'SEO', 'route' => '/admin/cms-akira-seo']],
];
$r = validateModuleSuiteContractForInstall($validExt, $fleet);
$assert(!empty($r['ok']), 'valid extension passes install gate');
$assert(count($r['checks']) === 6, 'install gate runs 6 checks', (string)count($r['checks'] ?? []));

// ── extension without installed host is rejected ─────────────────────────
$orphan = $validExt;
$orphan['extends'] = 'ghost-core';
$r = validateModuleSuiteContractForInstall($orphan, $fleet);
$assert(empty($r['ok']), 'extension with missing host fails install gate');
$assert(($r['error_code'] ?? '') === 'module_suite_contract_failed', 'install gate failure carries error_code');

// ── extension contributes to undeclared point is rejected ────────────────
$badPoint = $validExt;
$badPoint['contributes'] = [['extension_point' => 'pal.case.actions', 'provider' => 'x@1']];
$r = validateModuleSuiteContractForInstall($badPoint, $fleet);
$assert(empty($r['ok']), 'contribution to undeclared point fails install gate');

// ── contribution to unknown host is rejected ─────────────────────────────
$badHost = $validExt;
$badHost['admin_contributions'] = [['host' => 'ghost-shell', 'location' => 'sidebar', 'label' => 'X', 'route' => '/x']];
$r = validateModuleSuiteContractForInstall($badHost, $fleet);
$assert(empty($r['ok']), 'contribution to unknown host fails install gate');

// ── contribution route not owned by module is rejected (G5) ──────────────
$badRoute = $validExt;
$badRoute['admin_contributions'] = [['host' => 'cms', 'location' => 'sidebar', 'label' => 'SEO', 'route' => '/admin/cms/not-registered']];
$r = validateModuleSuiteContractForInstall($badRoute, $fleet);
$assert(empty($r['ok']), 'contribution with unregistered route fails install gate');

// ── profile that installs itself is rejected ─────────────────────────────
$selfProfile = [
    'id' => 'cms-akira-profile-standard',
    'name' => 'Standard',
    'version' => '1.0.0',
    'kind' => 'profile',
    'installs' => ['cms-akira-profile-standard', 'cms-akira-core'],
];
$r = validateModuleSuiteContractForInstall($selfProfile, $fleet);
$assert(empty($r['ok']), 'self-installing profile fails install gate');

$goodProfile = $selfProfile;
$goodProfile['installs'] = ['cms-akira-core'];
$r = validateModuleSuiteContractForInstall($goodProfile, $fleet);
$assert(!empty($r['ok']), 'well-formed profile passes install gate');

// ── standalone/legacy module passes through ──────────────────────────────
$legacy = ['id' => 'daily-ledger', 'name' => 'Daily Ledger', 'version' => '1.0.0', 'owns_tables' => [], 'reads_tables' => []];
$r = validateModuleSuiteContractForInstall($legacy, $fleet);
$assert(!empty($r['ok']), 'legacy standalone module passes install gate');

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
