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

// ── semver range matcher ─────────────────────────────────────────────────
$assert(kernelSemverRangeSatisfies('6.1.0', '>=6.0.0'), 'kernel 6.1.0 satisfies >=6.0.0');
$assert(!kernelSemverRangeSatisfies('5.4.0', '>=6.0.0'), 'kernel 5.4.0 fails >=6.0.0');
$assert(kernelSemverRangeSatisfies('6.1.0', '<7.0.0'), 'kernel 6.1.0 satisfies <7.0.0');
$assert(kernelSemverRangeSatisfies('6.1.0', '>=6.0.0 <7.0.0'), 'compound range >=6.0.0 <7.0.0 satisfied');
$assert(!kernelSemverRangeSatisfies('6.2.0', '>=6.0.0 <6.2.0'), 'compound range excludes 6.2.0');
$assert(kernelSemverRangeSatisfies('6.1.5', '^6.1'), 'caret ^6.1 satisfied by 6.1.5');
$assert(kernelSemverRangeSatisfies('6.2.0', '^6.1'), 'caret ^6.1 satisfied by 6.2.0 (<7.0.0)');
$assert(!kernelSemverRangeSatisfies('7.0.0', '^6.1'), 'caret ^6.1 excludes 7.0.0');
$assert(kernelSemverRangeSatisfies('6.1.9', '~6.1'), 'tilde ~6.1 satisfied by 6.1.9');
$assert(!kernelSemverRangeSatisfies('6.2.0', '~6.1'), 'tilde ~6.1 excludes 6.2.0');
$assert(kernelSemverRangeSatisfies('6.1.0', '6.1.0'), 'exact version match');
$assert(!kernelSemverRangeSatisfies('6.1.1', '6.1.0'), 'exact version mismatch');

// ── G6 compatibility gate in install flow ────────────────────────────────
$fleet = [
    'cms-akira-core' => [
        'id' => 'cms-akira-core',
        'name' => 'Core',
        'version' => '1.2.0',
        'kind' => 'product-core',
        'suite' => 'cms-akira',
        'extension_points' => ['cms.sidebar'],
    ],
    'cms' => ['id' => 'cms', 'name' => 'CMS', 'version' => '1.0.0'],
];

$baseExt = [
    'id' => 'cms-akira-seo',
    'name' => 'SEO',
    'version' => '1.0.0',
    'kind' => 'extension',
    'extends' => 'cms-akira-core',
    'admin_contributions' => [['host' => 'cms', 'location' => 'sidebar', 'label' => 'SEO', 'route' => '/admin/cms-akira-seo']],
];

// In-memory fleet without disk path → G5 skipped; G6 is what we test here.
$compatOk = $baseExt;
$compatOk['suite'] = 'cms-akira';
$compatOk['compatibility'] = ['kernel' => '>=6.0.0', 'suite' => '>=1.0.0'];
$r = validateModuleSuiteContractForInstall($compatOk, $fleet);
$assert(!empty($r['ok']), 'compatible kernel+suite passes install gate');

$badKernel = $baseExt;
$badKernel['compatibility'] = ['kernel' => '>=99.0.0'];
$r = validateModuleSuiteContractForInstall($badKernel, $fleet);
$assert(empty($r['ok']), 'incompatible kernel version rejected');
$assert(($r['error_code'] ?? '') === 'module_suite_contract_failed', 'compat rejection carries error_code');

$badSuite = $baseExt;
$badSuite['suite'] = 'cms-akira';
$badSuite['compatibility'] = ['suite' => '>=3.0.0']; // core suite version is 1.2.0
$r = validateModuleSuiteContractForInstall($badSuite, $fleet);
$assert(empty($r['ok']), 'incompatible host-suite version rejected');

$unknownSuite = $baseExt;
$unknownSuite['compatibility'] = ['suite' => '>=1.0.0'];
$unknownSuite['suite'] = 'ghost-suite';
$r = validateModuleSuiteContractForInstall($unknownSuite, $fleet);
$assert(empty($r['ok']), 'unknown suite version blocks compatibility evaluation');

$noCompat = $baseExt;
$r = validateModuleSuiteContractForInstall($noCompat, $fleet);
$assert(!empty($r['ok']), 'no compatibility declaration passes (no constraint)');

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
