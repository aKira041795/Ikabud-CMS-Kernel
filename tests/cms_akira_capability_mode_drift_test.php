<?php

declare(strict_types=1);

/**
 * CMS Akira Capability-Mode Drift Test.
 *
 * Freezes per-capability provider `modes` exactly as declared in manifests:
 *   - `akira.search.document.build@1` (`cms-akira-search-adapter`) → `first`
 *     (NOT `fallback`). Any observed runtime fallback is a selection-failure
 *     `resolved_from: fallback` output, never a provider mode.
 *   - `akira.theme.resolve@1` (`cms-akira-theme`) → `first`.
 *
 * Guards against silent mode drift between the frozen contract and the actual
 * manifests (e.g. someone downgrading a provider to `fallback`).
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

// Read a capability's declared modes from a module manifest.
$modesOf = static function (string $moduleId, string $capabilityId) use ($modules): array {
    $manifest = $modules[$moduleId] ?? null;
    if (!is_array($manifest)) {
        return [];
    }
    $exposes = is_array($manifest['capabilities']['exposes'] ?? null)
        ? $manifest['capabilities']['exposes']
        : [];
    foreach ($exposes as $cap) {
        if ((string)($cap['id'] ?? '') === $capabilityId) {
            return is_array($cap['modes'] ?? null) ? array_values(array_map('strval', $cap['modes'])) : [];
        }
    }
    return [];
};

// Read a capability's declared depends from a module manifest.
$dependsOf = static function (string $moduleId) use ($modules): array {
    $manifest = $modules[$moduleId] ?? null;
    if (!is_array($manifest)) {
        return [];
    }
    return is_array($manifest['capabilities']['depends'] ?? null)
        ? array_values(array_map('strval', $manifest['capabilities']['depends']))
        : [];
};

echo "\n=== CMS AKIRA CAPABILITY-MODE DRIFT ===\n";

// ── 1. search-adapter: akira.search.document.build@1 mode first ─────
$searchModes = $modesOf('cms-akira-search-adapter', 'akira.search.document.build@1');
t(
    'akira.search.document.build@1 declared modes include first',
    in_array('first', $searchModes, true),
    json_encode($searchModes)
);
t(
    'akira.search.document.build@1 does NOT declare fallback mode',
    !in_array('fallback', $searchModes, true),
    json_encode($searchModes)
);

// ── 2. theme: akira.theme.resolve@1 mode first ──────────────────────
$themeModes = $modesOf('cms-akira-theme', 'akira.theme.resolve@1');
t(
    'akira.theme.resolve@1 declared modes include first',
    in_array('first', $themeModes, true),
    json_encode($themeModes)
);
t(
    'akira.theme.resolve@1 does NOT declare fallback mode',
    !in_array('fallback', $themeModes, true),
    json_encode($themeModes)
);

// ── 3. Frozen depends baseline (CURRENT — pre-implementation) ──────
// Per the freeze: akira.theme.resolve@1 currently depends ONLY on
// akira.content.get@1. The APPROVED TARGET (cms.themes.list@1 +
// theme.token.apply@1) is an implementation step gated by this baseline.
$themeDepends = $dependsOf('cms-akira-theme');
t(
    'theme CURRENT depends baseline recorded (frozen)',
    in_array('akira.content.get@1', $themeDepends, true),
    json_encode($themeDepends)
);

// ── 4. Declared modes flow into the runtime registry ────────────────
// CLI bootstrap does not auto-register module capability handlers (they are
// wired during HTTP boot). Register them here using the module's own declared
// modes so we can assert the manifest→registry contract: the runtime provider
// for each frozen capability carries `first`, never `fallback`.
$registerModuleCaps = static function (string $moduleId) use ($modules, $suiteMembers): void {
    if (!function_exists('loadModuleHelpers')) {
        return;
    }
    $manifest = $modules[$moduleId] ?? null;
    if (!is_array($manifest)) {
        return;
    }
    $exposes = is_array($manifest['capabilities']['exposes'] ?? null) ? $manifest['capabilities']['exposes'] : [];
    if ($exposes === []) {
        return;
    }
    loadModuleHelpers($manifest);
    $modulePrefix = preg_replace('/[^a-z0-9]+/i', '_', $moduleId);
    $exportFunction = $modulePrefix . '_capability_handlers';
    if (!function_exists($exportFunction)) {
        return;
    }
    $handlerMap = $exportFunction();
    if (!is_array($handlerMap)) {
        return;
    }
    $reg = app()->capabilities();
    foreach ($exposes as $expose) {
        $capId = (string)($expose['id'] ?? '');
        $modes = is_array($expose['modes'] ?? null) ? array_values(array_map('strval', $expose['modes'])) : ['first'];
        if ($capId === '' || !isset($handlerMap[$capId])) {
            continue;
        }
        $handler = $handlerMap[$capId];
        $priority = (int)($expose['priority'] ?? 50);
        $reg->register($capId, $moduleId, $handler, $priority, $modes);
    }
};

$registerModuleCaps('cms-akira-search-adapter');
$registerModuleCaps('cms-akira-theme');

$reg = app()->capabilities();
$searchEntry = $reg->inspect('akira.search.document.build@1');
$themeEntry = $reg->inspect('akira.theme.resolve@1');
if (is_array($searchEntry) && ($searchEntry['provider_count'] ?? 0) > 0) {
    $providerModes = [];
    foreach (is_array($searchEntry['providers'] ?? null) ? $searchEntry['providers'] : [] as $p) {
        if (str_contains((string)($p['provider'] ?? ''), 'cms-akira-search-adapter')) {
            $providerModes = is_array($p['modes'] ?? null) ? array_values(array_map('strval', $p['modes'])) : [];
        }
    }
    t(
        'registry search-adapter provider modes include first',
        in_array('first', $providerModes, true),
        json_encode($providerModes)
    );
    t(
        'registry search-adapter provider modes exclude fallback',
        !in_array('fallback', $providerModes, true),
        json_encode($providerModes)
    );
} else {
    t('registry search-adapter provider modes include first', false, 'no providers registered');
    t('registry search-adapter provider modes exclude fallback', false, 'no providers registered');
}

if (is_array($themeEntry) && ($themeEntry['provider_count'] ?? 0) > 0) {
    $themeModesAgg = [];
    foreach (is_array($themeEntry['providers'] ?? null) ? $themeEntry['providers'] : [] as $p) {
        if (str_contains((string)($p['provider'] ?? ''), 'cms-akira-theme')) {
            $themeModesAgg = is_array($p['modes'] ?? null) ? array_values(array_map('strval', $p['modes'])) : [];
        }
    }
    t(
        'registry theme provider modes include first',
        in_array('first', $themeModesAgg, true),
        json_encode($themeModesAgg)
    );
    t(
        'registry theme provider modes exclude fallback',
        !in_array('fallback', $themeModesAgg, true),
        json_encode($themeModesAgg)
    );
} else {
    t('registry theme provider modes include first', false, 'no providers registered');
    t('registry theme provider modes exclude fallback', false, 'no providers registered');
}

// ── 5. Provider handler exposes resolved_from fallback path (not mode) ─
// The akira.theme.resolve@1 handler has an explicit fallback branch that
// returns `resolved_from: fallback` on failure. That is a selection-failure
// output, NOT a provider mode. Assert the handler source carries the marker.
$themeHelpers = file_get_contents(BASE_PATH . '/modules/cms-akira/cms-akira-theme/helpers.php') ?: '';
t(
    'theme resolver has explicit resolved_from: fallback failure path',
    str_contains($themeHelpers, "'resolved_from' => 'fallback'"),
    'resolved_from fallback marker missing'
);
t(
    'theme resolver primary path resolved_from is cms',
    str_contains($themeHelpers, "'resolved_from' => 'cms'"),
    'resolved_from cms marker missing'
);

// ── 6. No log noise ────────────────────────────────────────────────
$criticalLines = [];
$appLog = @file_get_contents(STORAGE_PATH . '/logs/app.log') ?: '';
foreach (explode("\n", $appLog) as $line) {
    if (str_contains($line, '[critical]')) {
        $criticalLines[] = $line;
    }
}
t('no app.log critical errors after mode-drift certification', empty($criticalLines), implode('; ', $criticalLines));

echo "\n── Result: {$pass} passed, {$fail} failed ──\n";
if ($fail > 0) {
    echo implode("\n", $errors) . "\n";
    exit(1);
}
echo "  ✓ ALL CAPABILITY-MODE DRIFT TESTS PASSED\n";
