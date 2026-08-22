<?php

declare(strict_types=1);

/**
 * CMS Akira Frozen Depends Matrix Test.
 *
 * Asserts the two frozen dependency matrices from the CMS Akira contract:
 *
 * CURRENT baseline (verbatim manifests, pre-implementation):
 *   - akira.theme.resolve@1 (`cms-akira-theme`) depends ONLY on
 *     `akira.content.get@1`.
 *   - akira.search.document.build@1 (`cms-akira-search-adapter`) mode `first`.
 *
 * APPROVED TARGET (implementation goal, gated by drift test):
 *   - akira.theme.resolve@1 depends = exactly `cms.themes.list@1` +
 *     `theme.token.apply@1` (no wildcard).
 *
 * This test records the CURRENT baseline as the authoritative freeze fact.
 * When the target rewiring lands, the CURRENT section must be updated in the
 * same change (frozen-baseline discipline) — a drift here means the freeze
 * contract was violated.
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

$dependsOf = static function (string $moduleId) use ($modules): array {
    $manifest = $modules[$moduleId] ?? null;
    if (!is_array($manifest)) {
        return [];
    }
    return is_array($manifest['capabilities']['depends'] ?? null)
        ? array_values(array_map('strval', $manifest['capabilities']['depends']))
        : [];
};

$exposesOf = static function (string $moduleId) use ($modules): array {
    $manifest = $modules[$moduleId] ?? null;
    if (!is_array($manifest)) {
        return [];
    }
    $exposes = is_array($manifest['capabilities']['exposes'] ?? null) ? $manifest['capabilities']['exposes'] : [];
    $out = [];
    foreach ($exposes as $cap) {
        $out[(string)($cap['id'] ?? '')] = [
            'modes' => is_array($cap['modes'] ?? null) ? array_values(array_map('strval', $cap['modes'])) : [],
            'priority' => (int)($cap['priority'] ?? 0),
        ];
    }
    return $out;
};

echo "\n=== CMS AKIRA FROZEN DEPENDS MATRIX ===\n";

// ── 1. CURRENT baseline: theme depends only on akira.content.get@1 ──
$themeDepends = $dependsOf('cms-akira-theme');
t(
    'CURRENT: theme capability depends contains akira.content.get@1',
    in_array('akira.content.get@1', $themeDepends, true),
    json_encode($themeDepends)
);
t(
    'CURRENT: theme capability does not yet depend on cms.themes.list@1',
    !in_array('cms.themes.list@1', $themeDepends, true),
    json_encode($themeDepends)
);
t(
    'CURRENT: theme capability does not yet depend on theme.token.apply@1',
    !in_array('theme.token.apply@1', $themeDepends, true),
    json_encode($themeDepends)
);

// ── 2. CURRENT baseline: theme capability modes first ───────────────
$themeExposes = $exposesOf('cms-akira-theme');
$themeModes = $themeExposes['akira.theme.resolve@1']['modes'] ?? [];
t(
    'CURRENT: akira.theme.resolve@1 declared modes include first',
    in_array('first', $themeModes, true),
    json_encode($themeModes)
);
t(
    'CURRENT: akira.theme.resolve@1 does not declare fallback',
    !in_array('fallback', $themeModes, true),
    json_encode($themeModes)
);

// ── 3. CURRENT baseline: search-adapter mode first ──────────────────
$searchExposes = $exposesOf('cms-akira-search-adapter');
$searchModes = $searchExposes['akira.search.document.build@1']['modes'] ?? [];
t(
    'CURRENT: akira.search.document.build@1 mode first',
    in_array('first', $searchModes, true),
    json_encode($searchModes)
);
t(
    'CURRENT: akira.search.document.build@1 does not declare fallback',
    !in_array('fallback', $searchModes, true),
    json_encode($searchModes)
);

// ── 4. APPROVED TARGET capability availability (dependencies exist) ─
// The target rewires theme depends onto capabilities that must exist in the
// fleet. Assert both are declared by their owning modules (CMS + Theme Studio).
$cmsExposes = $exposesOf('cms');
$tsExposes = $exposesOf('theme-studio');
t(
    'TARGET: cms.themes.list@1 exists in CMS exposes',
    isset($cmsExposes['cms.themes.list@1']),
    'cms.themes.list@1 missing from CMS exposes'
);
t(
    'TARGET: theme.token.apply@1 exists in Theme Studio exposes',
    isset($tsExposes['theme.token.apply@1']),
    'theme.token.apply@1 missing from theme-studio exposes'
);

// ── 5. Media/navigation/SEO TARGET deps are already wired (slice 1) ─
$mediaDepends = $dependsOf('cms-akira-media');
$navDepends = $dependsOf('cms-akira-navigation');
$seoDepends = $dependsOf('cms-akira-seo');
t('TARGET: cms-akira-media depends on cms.media.get@1', in_array('cms.media.get@1', $mediaDepends, true), json_encode($mediaDepends));
t('TARGET: cms-akira-navigation depends on cms.menus.get@1', in_array('cms.menus.get@1', $navDepends, true), json_encode($navDepends));
t('TARGET: cms-akira-navigation depends on cms.menus.tree@1', in_array('cms.menus.tree@1', $navDepends, true), json_encode($navDepends));
t('TARGET: cms-akira-seo depends on cms.seo.resolve@1', in_array('cms.seo.resolve@1', $seoDepends, true), json_encode($seoDepends));

// ── 6. No wildcard capability depends in any akira module ───────────
$wildcards = [];
foreach ($modules as $moduleId => $manifest) {
    if ((string)($manifest['suite'] ?? '') !== 'cms-akira') {
        continue;
    }
    foreach ($dependsOf($moduleId) as $dep) {
        if (str_contains($dep, '*')) {
            $wildcards[] = $moduleId . ' → ' . $dep;
        }
    }
}
t('no wildcard capability depends across cms-akira suite', $wildcards === [], implode('; ', $wildcards));

// ── 7. No log noise ────────────────────────────────────────────────
$criticalLines = [];
$appLog = @file_get_contents(STORAGE_PATH . '/logs/app.log') ?: '';
foreach (explode("\n", $appLog) as $line) {
    if (str_contains($line, '[critical]')) {
        $criticalLines[] = $line;
    }
}
t('no app.log critical errors after depends-matrix certification', empty($criticalLines), implode('; ', $criticalLines));

echo "\n── Result: {$pass} passed, {$fail} failed ──\n";
if ($fail > 0) {
    echo implode("\n", $errors) . "\n";
    exit(1);
}
echo "  ✓ ALL FROZEN DEPENDS MATRIX TESTS PASSED\n";
