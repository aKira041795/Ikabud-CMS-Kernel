<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/src/helpers/manifest-validation.php';

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

$hasDiagnostic = static function (array $result, string $code): bool {
    foreach ($result['diagnostics'] ?? [] as $d) {
        if (($d['code'] ?? '') === $code) {
            return true;
        }
    }
    return false;
};

$base = [
    'id' => 'cms-akira-seo',
    'name' => 'CMS Akira SEO',
    'version' => '1.0.0',
    'author' => 'Ikabud',
    'description' => 'SEO extension for CMS Akira',
    'owns_tables' => [],
    'reads_tables' => [],
    'routes' => true,
    'capabilities' => ['exposes' => [], 'depends' => []],
    'events' => [],
];

$tempDir = sys_get_temp_dir() . '/ikabud-suite-contract-' . bin2hex(random_bytes(6));
mkdir($tempDir, 0777, true);
file_put_contents($tempDir . '/routes.php', "<?php\nreturn ['GET' => [], 'POST' => []];\n");

try {
    // ── suite-contract version is exposed additively ─────────────────────
    $result = validateModuleManifestV1($base, ['module_path' => $tempDir]);
    $assert(($result['suite_contract_version'] ?? '') === '2', 'result exposes suite contract version 2');
    $assert($result['ok'] === true, 'schema-v1 manifest without suite fields stays valid');

    // ── kind validation ──────────────────────────────────────────────────
    $badKind = $base;
    $badKind['kind'] = 'not-a-kind';
    $r = validateModuleManifestV1($badKind, ['module_path' => $tempDir]);
    $assert($r['ok'] === false, 'invalid kind is rejected');
    $assert($hasDiagnostic($r, 'suite_invalid_kind'), 'invalid kind cites suite_invalid_kind');

    // ── extension requires extends ───────────────────────────────────────
    $ext = $base;
    $ext['kind'] = 'extension';
    $r = validateModuleManifestV1($ext, ['module_path' => $tempDir]);
    $assert($r['ok'] === false, 'extension without extends is rejected');
    $assert($hasDiagnostic($r, 'suite_extends_required'), 'extension without extends cites suite_extends_required');

    $extOk = $base;
    $extOk['kind'] = 'extension';
    $extOk['extends'] = 'cms-akira-core';
    $extOk['suite'] = 'cms-akira';
    $r = validateModuleManifestV1($extOk, ['module_path' => $tempDir]);
    $assert($r['ok'] === true, 'extension with extends passes');
    $assert(moduleManifestKindFromManifest($extOk) === 'extension', 'kind resolver returns extension');

    // ── product-core requires suite ──────────────────────────────────────
    $core = $base;
    $core['id'] = 'cms-akira-core';
    $core['kind'] = 'product-core';
    $r = validateModuleManifestV1($core, ['module_path' => $tempDir]);
    $assert($r['ok'] === false, 'product-core without suite is rejected');
    $assert($hasDiagnostic($r, 'suite_core_requires_suite'), 'product-core without suite cites suite_core_requires_suite');

    $coreOk = $core;
    $coreOk['suite'] = 'cms-akira';
    $coreOk['extension_points'] = ['cms.sidebar', 'cms.settings.sections'];
    $r = validateModuleManifestV1($coreOk, ['module_path' => $tempDir]);
    $assert($r['ok'] === true, 'product-core with suite + extension_points passes');

    // ── profile requires installs ────────────────────────────────────────
    $profile = $base;
    $profile['id'] = 'cms-akira-profile-standard';
    $profile['kind'] = 'profile';
    $r = validateModuleManifestV1($profile, ['module_path' => $tempDir]);
    $assert($r['ok'] === false, 'profile without installs is rejected');
    $assert($hasDiagnostic($r, 'suite_profile_requires_installs'), 'profile without installs cites suite_profile_requires_installs');

    $profileOk = $profile;
    $profileOk['installs'] = ['cms-akira-core', 'cms-akira-editor'];
    $r = validateModuleManifestV1($profileOk, ['module_path' => $tempDir]);
    $assert($r['ok'] === true, 'profile with installs passes');
    $assert(moduleManifestKindFromManifest($profileOk) === 'profile', 'legacy kind inference detects profile from installs');

    // ── admin_contributions validation ───────────────────────────────────
    $ac = $base;
    $ac['admin_contributions'] = [['location' => 'sidebar', 'label' => 'SEO', 'route' => '/admin/cms/seo']];
    $r = validateModuleManifestV1($ac, ['module_path' => $tempDir]);
    $assert($r['ok'] === false, 'admin_contribution missing host is rejected');
    $assert($hasDiagnostic($r, 'suite_admin_contributions_field_missing'), 'missing host cites field-missing diagnostic');

    $acOk = $base;
    $acOk['admin_contributions'] = [[
        'host' => 'cms',
        'location' => 'sidebar',
        'group' => 'optimization',
        'label' => 'SEO',
        'icon' => 'search',
        'route' => '/admin/cms/seo',
        'permission' => 'cms.seo.manage',
        'order' => 60,
    ]];
    $r = validateModuleManifestV1($acOk, ['module_path' => $tempDir]);
    $assert($r['ok'] === true, 'valid admin_contribution passes');

    // ── uninstall policy validation ──────────────────────────────────────
    $un = $base;
    $un['uninstall'] = ['retain_data_by_default' => 'yes'];
    $r = validateModuleManifestV1($un, ['module_path' => $tempDir]);
    $assert($r['ok'] === false, 'non-boolean uninstall flag is rejected');
    $assert($hasDiagnostic($r, 'suite_uninstall_flag_invalid'), 'bad uninstall flag cites suite_uninstall_flag_invalid');

    $unOk = $base;
    $unOk['uninstall'] = [
        'disable_safe' => true,
        'retain_data_by_default' => true,
        'supports_data_export' => true,
        'requires_confirmation_to_drop_data' => true,
    ];
    $r = validateModuleManifestV1($unOk, ['module_path' => $tempDir]);
    $assert($r['ok'] === true, 'valid uninstall policy passes');

    // ── contributes validation ───────────────────────────────────────────
    $ct = $base;
    $ct['kind'] = 'extension';
    $ct['extends'] = 'cms-akira-core';
    $ct['suite'] = 'cms-akira';
    $ct['contributes'] = [['extension_point' => 'cms.sidebar']];
    $r = validateModuleManifestV1($ct, ['module_path' => $tempDir]);
    $assert($r['ok'] === false, 'contribution without provider is rejected');
    $assert($hasDiagnostic($r, 'suite_contributes_provider_missing'), 'missing provider cites suite_contributes_provider_missing');

    $ctOk = $ct;
    $ctOk['contributes'] = [['extension_point' => 'cms.sidebar', 'provider' => 'cms-akira-seo.nav@1']];
    $r = validateModuleManifestV1($ctOk, ['module_path' => $tempDir]);
    $assert($r['ok'] === true, 'valid contribution passes');

    // ── fleet validation ─────────────────────────────────────────────────
    $coreManifest = [
        'id' => 'cms-akira-core',
        'name' => 'Core',
        'version' => '1.0.0',
        'kind' => 'product-core',
        'suite' => 'cms-akira',
        'extension_points' => ['cms.sidebar', 'cms.settings.sections'],
    ];
    $seoManifest = [
        'id' => 'cms-akira-seo',
        'name' => 'SEO',
        'version' => '1.0.0',
        'kind' => 'extension',
        'extends' => 'cms-akira-core',
        'contributes' => [['extension_point' => 'cms.sidebar', 'provider' => 'cms-akira-seo.nav@1']],
        'admin_contributions' => [['host' => 'cms', 'location' => 'sidebar', 'label' => 'SEO', 'route' => '/admin/cms/seo']],
    ];
    $fleet = ['cms-akira-core' => $coreManifest, 'cms-akira-seo' => $seoManifest, 'cms' => ['id' => 'cms', 'name' => 'CMS', 'version' => '1.0.0']];
    $fleetDiags = validateModuleSuiteFleetV1($fleet);
    $fleetOk = array_filter($fleetDiags, static fn ($d) => ($d['severity'] ?? '') === 'fatal') === [];
    $assert($fleetOk, 'valid fleet passes fleet validation');

    $badFleet = $fleet;
    $badFleet['cms-akira-seo']['contributes'] = [['extension_point' => 'pal.case.actions', 'provider' => 'x@1']];
    $fleetDiags = validateModuleSuiteFleetV1($badFleet);
    $assert($hasDiagnostic(['diagnostics' => $fleetDiags], 'suite_fleet_extension_point_undeclared'), 'fleet rejects contribution to undeclared extension point');

    $badHostFleet = $fleet;
    $badHostFleet['cms-akira-seo']['admin_contributions'] = [['host' => 'ghost-shell', 'location' => 'sidebar', 'label' => 'SEO', 'route' => '/x']];
    $fleetDiags = validateModuleSuiteFleetV1($badHostFleet);
    $assert($hasDiagnostic(['diagnostics' => $fleetDiags], 'suite_fleet_contribution_host_missing'), 'fleet rejects contribution to unknown host');

    $missingExtendsFleet = $fleet;
    $missingExtendsFleet['cms-akira-seo']['extends'] = 'ghost-core';
    $fleetDiags = validateModuleSuiteFleetV1($missingExtendsFleet);
    $assert($hasDiagnostic(['diagnostics' => $fleetDiags], 'suite_fleet_extends_missing'), 'fleet rejects extends target that is absent');
} finally {
    @unlink($tempDir . '/routes.php');
    @rmdir($tempDir);
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
