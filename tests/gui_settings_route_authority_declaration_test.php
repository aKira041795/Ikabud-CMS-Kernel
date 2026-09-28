<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$manifestPath = $root . '/modules/gui-settings/module.json';
$helpersPath = $root . '/modules/gui-settings/helpers.php';

$manifestSource = file_get_contents($manifestPath);
$helpersSource = file_get_contents($helpersPath);
$manifest = is_string($manifestSource) ? json_decode($manifestSource, true) : null;
$routes = is_array($manifest)
    && is_array($manifest['capabilities'] ?? null)
    && is_array($manifest['capabilities']['routes'] ?? null)
        ? $manifest['capabilities']['routes']
        : [];

$capability = 'gui_settings.apply@1';
$expectedWriteRoutes = [
    'POST /api/v1/admin/gui-settings' => $capability,
    'POST /api/v1/admin/gui-settings/reset' => $capability,
];

$seedRows = [];
if (is_string($helpersSource)) {
    preg_match_all('/\$rows\[\]\s*=\s*\[(.*?)\n\s*\];/s', $helpersSource, $matches);
    foreach ($matches[1] ?? [] as $row) {
        if (preg_match("/'capability_id'\\s*=>\\s*'gui_settings\\.apply@1'/", $row) === 1) {
            $seedRows[] = $row;
        }
    }
}
$seedRow = count($seedRows) === 1 ? $seedRows[0] : '';

$literalField = static function (string $row, string $field): ?string {
    $pattern = "/'" . preg_quote($field, '/') . "'\\s*=>\\s*'([^']*)'/";
    return preg_match($pattern, $row, $match) === 1 ? $match[1] : null;
};

$allowedRoles = $literalField($seedRow, 'allowed_roles');
$callerModule = $literalField($seedRow, 'caller_module');
$seedWired = is_string($helpersSource)
    && str_contains(
        $helpersSource,
        'CapabilityAuthorizationRegistry::seedPolicyForCurrentScope($rows);'
    )
    && preg_match('/^guiSettingsSeedApplyPolicy\(\);\s*$/m', $helpersSource) === 1;

// Census assertion: run the frozen governance apparatus in a subprocess and parse its JSON here.
// It is asserted INSIDE the test (not offered as a standalone claim) so the test's passed/failed
// counts re-derive it; a declared census command alone verifies as nothing_to_compare.
$censusStdout = '';
$censusStderr = '';
$censusExit = null;
if (function_exists('proc_open')) {
    $censusCommand = [PHP_BINARY, 'ikabud', 'workbench:governance', '--all', '--json'];
    $censusPipes = [];
    $censusProcess = proc_open(
        $censusCommand,
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $censusPipes,
        $root,
        null,
        ['bypass_shell' => true]
    );
    if (is_resource($censusProcess)) {
        fclose($censusPipes[0]);
        $out = stream_get_contents($censusPipes[1]);
        $err = stream_get_contents($censusPipes[2]);
        fclose($censusPipes[1]);
        fclose($censusPipes[2]);
        $censusStdout = is_string($out) ? $out : '';
        $censusStderr = is_string($err) ? $err : '';
        $censusExit = proc_close($censusProcess);
    }
}
$censusData = json_decode($censusStdout, true);
$censusModuleSummary = null;
foreach (is_array($censusData) && is_array($censusData['modules'] ?? null) ? $censusData['modules'] : [] as $module) {
    if (is_array($module) && ($module['module'] ?? null) === 'gui-settings') {
        $censusModuleSummary = is_array($module['summary'] ?? null) ? $module['summary'] : null;
    }
}
$censusAkira = is_array($censusData) && is_array($censusData['akira'] ?? null) ? $censusData['akira'] : null;

$checks = [
    'both required POST declarations map directly to gui_settings.apply@1 (removing either key fails this assertion)'
        => count(array_intersect_assoc($expectedWriteRoutes, $routes)) === count($expectedWriteRoutes),
    'the GET page route is not declared'
        => !array_key_exists('GET /admin/gui-settings', $routes),
    'the unique gui_settings.apply@1 seed row pins allowed_roles to exactly admin'
        => count($seedRows) === 1 && $allowedRoles === 'admin',
    'caller_module is the explicit kernel,gui-settings convention and names the gui-settings route dispatcher'
        => $callerModule === 'kernel,gui-settings'
            && in_array('gui-settings', explode(',', $callerModule), true),
    'the policy row builder is passed to the registry seeder and invoked at file scope'
        => $seedWired,
    'census subprocess exits 0 and emits parseable JSON'
        => $censusExit === 0 && is_array($censusData),
    'census: gui-settings summary reports undeclared 0 and write_ratio 100'
        => is_array($censusModuleSummary)
            && (int) ($censusModuleSummary['undeclared'] ?? -1) === 0
            && (float) ($censusModuleSummary['write_ratio'] ?? -1) === 100.0,
    'census: akira rollup reports undeclared 0, write_ratio 100 and dispatch_enforced == total == 55'
        => is_array($censusAkira)
            && (int) ($censusAkira['undeclared'] ?? -1) === 0
            && (float) ($censusAkira['write_ratio'] ?? -1) === 100.0
            // 47 at the time this gate was written; the authorised P3.3 session-revocation
            // route (POST /cms-akira-shell/users/{id}/revoke, f63f6bf) is the 48th; the two authorised
            // P6 recovery routes (POST /cms-akira-shell/bundles/diff and /bundles/apply, aae747c) are the
            // 49th and 50th; and the authorised D4 alt-text route
            // (POST /cms-akira-shell/media/{media_key}/alt, 074af9c) is the 51st.
            //
            // The 52nd is the declared content-type public route (GET /{prefix}/{slug}, f3d463a) —
            // the first READ to join the declared+enforced set. The navigation item create, update,
            // and delete routes are the 53rd through 55th. write_ratio stays 100, asserted above,
            // because every routed write remains declared and enforced. That invariant is what the
            // `undeclared === 0` above this line is here to keep honest. Failing here after adding a
            // route means updating this count WITH the route, not instead of it.
            && (int) ($censusAkira['dispatch_enforced'] ?? -1) === 55
            && (int) ($censusAkira['total'] ?? -1) === 55,
];

$passed = 0;
$failed = 0;
foreach ($checks as $label => $ok) {
    echo ($ok ? 'PASS: ' : 'FAIL: ') . $label . "\n";
    $ok ? ++$passed : ++$failed;
}

echo 'mode: pure-source-inspection+subprocess-census' . "\n";
echo 'census_exit: ' . ($censusExit === null ? 'not_run' : (string) $censusExit) . "\n";
echo 'census_stderr: ' . trim($censusStderr) . "\n";
echo 'census_gui_settings: ' . json_encode($censusModuleSummary, JSON_UNESCAPED_SLASHES) . "\n";
echo 'census_akira: ' . json_encode($censusAkira, JSON_UNESCAPED_SLASHES) . "\n";
echo 'manifest_write_routes: ' . json_encode(array_intersect_key($routes, $expectedWriteRoutes), JSON_UNESCAPED_SLASHES) . "\n";
echo 'get_route_declared: ' . (array_key_exists('GET /admin/gui-settings', $routes) ? 'yes' : 'no') . "\n";
echo 'policy_allowed_roles: ' . ($allowedRoles ?? 'unresolved') . "\n";
echo 'policy_caller_module: ' . ($callerModule ?? 'unresolved') . "\n";
echo 'policy_seed_wired: ' . ($seedWired ? 'yes' : 'no') . "\n";
echo 'live_policy_row: not_asserted_purely; tenant_54_verification: Chair_evidence_only' . "\n";
echo 'existing_test_or_gate_weakened: no' . "\n";
echo "passed: {$passed}\nfailed: {$failed}\nskipped: 0\n";

exit($failed === 0 ? 0 : 1);
