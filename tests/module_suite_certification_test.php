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

$checkResult = static function (array $cert, string $checkName): array {
    foreach ($cert['checks'] ?? [] as $check) {
        if (($check['check'] ?? '') === $checkName) {
            return $check;
        }
    }
    return [];
};

// ── legacy module certifies with C12/C13 passing (lenient) ───────────────
$legacy = [
    'id' => 'daily-ledger',
    'name' => 'Daily Ledger',
    'version' => '1.0.0',
    'author' => 'Ikabud',
    'description' => 'Ledger module',
    'type' => 'module',
    'owns_tables' => [],
    'reads_tables' => [],
    'capabilities' => ['exposes' => [], 'depends' => []],
    'events' => [],
    'routes' => true,
    'migrations' => [],
];
$cert = validateModuleCertification($legacy);
$assert(!empty($cert['ok']), 'legacy module remains certifiable with C12/C13');
$c12 = $checkResult($cert, 'C12: Product suite contract');
$assert(($c12['passed'] ?? false) === true, 'C12 passes for legacy module');
$c13 = $checkResult($cert, 'C13: Admin contributions');
$assert(($c13['passed'] ?? false) === true, 'C13 passes for legacy module');
$assert(($c13['severity'] ?? '') === 'advisory', 'C13 is advisory severity when no contributions declared');

// ── well-formed suite module certifies ───────────────────────────────────
$ext = $legacy;
$ext['id'] = 'cms-akira-seo';
$ext['name'] = 'CMS Akira SEO';
$ext['kind'] = 'extension';
$ext['extends'] = 'cms-akira-core';
$ext['suite'] = 'cms-akira';
$ext['admin_contributions'] = [[
    'host' => 'cms',
    'location' => 'sidebar',
    'label' => 'SEO',
    'route' => '/admin/cms-akira-seo',
    'order' => 60,
]];
$cert = validateModuleCertification($ext);
$assert(!empty($cert['ok']), 'well-formed suite extension certifies');
$c12 = $checkResult($cert, 'C12: Product suite contract');
$assert(($c12['passed'] ?? false) === true, 'C12 passes for well-formed extension');
$c13 = $checkResult($cert, 'C13: Admin contributions');
$assert(($c13['passed'] ?? false) === true, 'C13 passes for valid contribution');
$assert(($c13['severity'] ?? '') === 'advisory', 'C13 is advisory when contributions exist');

// ── malformed suite contract fails certification ─────────────────────────
$bad = $ext;
$bad['kind'] = 'extension'; // extension without extends
unset($bad['extends']);
$cert = validateModuleCertification($bad);
$assert(empty($cert['ok']), 'extension without extends fails certification');
$c12 = $checkResult($cert, 'C12: Product suite contract');
$assert(($c12['passed'] ?? false) === false, 'C12 flags missing extends');

// ── malformed contribution is a suite-contract violation (blocks) ────────
// A contribution missing host/location fails C12 (strict suite contract),
// which is a cert blocker. C13 provides a softer advisory signal alongside.
$badContrib = $ext;
$badContrib['admin_contributions'] = [['label' => 'X']]; // missing host + route
$cert = validateModuleCertification($badContrib);
$assert(empty($cert['ok']), 'malformed contribution blocks certification via suite contract');
$c12 = $checkResult($cert, 'C12: Product suite contract');
$assert(($c12['passed'] ?? false) === false, 'C12 flags malformed contribution');
$c13 = $checkResult($cert, 'C13: Admin contributions');
$assert(($c13['passed'] ?? false) === false, 'C13 also flags malformed contribution');
$assert(($c13['severity'] ?? '') === 'advisory', 'C13 stays advisory for malformed contribution');

// ── product-core with suite + extension_points certifies ─────────────────
$core = $legacy;
$core['id'] = 'cms-akira-core';
$core['name'] = 'CMS Akira Core';
$core['kind'] = 'product-core';
$core['suite'] = 'cms-akira';
$core['extension_points'] = ['cms.sidebar', 'cms.settings.sections'];
$cert = validateModuleCertification($core);
$assert(!empty($cert['ok']), 'product-core certifies');

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
