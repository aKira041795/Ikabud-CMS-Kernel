<?php
/**
 * A dependency must say whether it is REQUIRED, and the kernel must be able to
 * explain why it cannot be satisfied.
 *
 * WHY (chair consult, .ai/consult/kernel-ownership-capability.md, answer D/E):
 * `capabilities.depends` is a flat list of ids today. Nothing distinguishes "I
 * cannot work without this" from "I am better with this", and nothing explains
 * why a declared dependency is not satisfied - kernel/Capabilities/ has no
 * readiness or reason-code machinery at all. A parent module consuming another
 * parent module therefore has an undefined contract.
 *
 * The settled minimum, asserted here:
 *   - a dependency is {id, required}; legacy string entries mean required;
 *   - a dependency that cannot be satisfied is provider_unavailable or
 *     authorization_denied, never a silent "fine";
 *   - a missing REQUIRED dependency blocks; a missing OPTIONAL one only degrades;
 *   - readiness uses the same policy/authorization decisions as dispatch, so it
 *     cannot report ready for a call that would be denied;
 *   - install honours the distinction: a missing optional dependency must not
 *     stop a module being enabled.
 *
 * Run: php tests/module_dependency_readiness_test.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);

ob_start();
require $root . '/bootstrap.php';
require_once BASE_PATH . '/src/helpers/module-manager.php';
ob_end_clean();

$passed = 0;
$failed = 0;
function mdrAssert(string $label, bool $condition, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        echo "PASS: {$label}\n";
        $passed++;
        return;
    }
    echo "FAIL: {$label}" . ($detail !== '' ? " ({$detail})" : '') . "\n";
    $failed++;
}

function mdrRrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $file) {
        $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
    }
    @rmdir($dir);
}

function mdrSetDeps(string $manifestPath, array $depends): void
{
    $raw = @file_get_contents($manifestPath);
    $manifest = json_decode((string) $raw, true);
    if (!is_array($manifest)) {
        return;
    }
    $manifest['capabilities'] = is_array($manifest['capabilities'] ?? null) ? $manifest['capabilities'] : [];
    $manifest['capabilities']['depends'] = $depends;
    @file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

// ── Contract surface ───────────────────────────────────────────────────────
foreach (['moduleCapabilityRequirements', 'moduleDependencyReadiness'] as $fn) {
    mdrAssert("{$fn}() exists", function_exists($fn));
}
if (!function_exists('moduleCapabilityRequirements') || !function_exists('moduleDependencyReadiness')) {
    echo "\nResult: {$passed} passed, {$failed} failed\n";
    exit(1);
}

// ── Normalisation: requirement shape and legacy compatibility ────────────
$normalized = moduleCapabilityRequirements([
    'id' => 'probe',
    'capabilities' => ['depends' => [
        'legacy.plain@1',
        ['id' => 'object.required@1', 'required' => true],
        ['id' => 'object.optional@1', 'required' => false],
        ['id' => 'object.default@1'],
    ]],
]);
mdrAssert('normalisation returns every entry', count($normalized) === 4, (string) count($normalized));
mdrAssert('a legacy string dependency is required', ($normalized[0]['required'] ?? null) === true);
mdrAssert('an explicit required object stays required', ($normalized[1]['required'] ?? null) === true);
mdrAssert('an explicit optional object stays optional', ($normalized[2]['required'] ?? null) === false);
mdrAssert('an object without "required" defaults to required', ($normalized[3]['required'] ?? null) === true, 'omit required and you get the safe answer');
mdrAssert('ids are preserved', ($normalized[2]['id'] ?? '') === 'object.optional@1');

$invalid = moduleCapabilityRequirements(['id' => 'probe', 'capabilities' => ['depends' => [['nope' => 1]]]]);
mdrAssert('a malformed entry is dropped, not trusted', $invalid === [], json_encode($invalid));

// ── Readiness against the live capability registry ───────────────────────
// cms.content.get@1 exists and permits the `media` caller, so this is a
// genuinely satisfiable dependency on the real fleet.
$readyManifest = [
    'id' => 'probe-consumer',
    'capabilities' => ['depends' => [['id' => 'cms.content.get@1', 'required' => true]]],
];
$ready = moduleDependencyReadiness($readyManifest, ['caller_module' => 'media']);
mdrAssert('a satisfiable required dependency reports ready', ($ready['state'] ?? '') === 'ready', json_encode($ready['state'] ?? null));
mdrAssert('the requirement is reported ready', ($ready['requirements'][0]['state'] ?? '') === 'ready', json_encode($ready['requirements'][0] ?? null));

// A capability nobody exposes cannot be satisfied.
$missingRequired = moduleDependencyReadiness([
    'id' => 'probe-consumer',
    'capabilities' => ['depends' => [['id' => 'no.such.capability@1', 'required' => true]]],
]);
mdrAssert('a missing required dependency blocks', ($missingRequired['state'] ?? '') === 'blocked', json_encode($missingRequired['state'] ?? null));
mdrAssert('the reason is provider_unavailable', ($missingRequired['requirements'][0]['reason_code'] ?? '') === 'provider_unavailable', json_encode($missingRequired['requirements'][0] ?? null));

$missingOptional = moduleDependencyReadiness([
    'id' => 'probe-consumer',
    'capabilities' => ['depends' => [['id' => 'no.such.capability@1', 'required' => false]]],
]);
mdrAssert('a missing optional dependency only degrades', ($missingOptional['state'] ?? '') === 'degraded', json_encode($missingOptional['state'] ?? null));

// A caller outside the capability's allow_callers must be reported denied, not
// ready: introspection must not disagree with dispatch.
$deniedManifest = [
    'id' => 'bogus-consumer-module',
    'capabilities' => ['depends' => [['id' => 'cms.content.get@1', 'required' => true]]],
];
$denied = moduleDependencyReadiness($deniedManifest);
mdrAssert('an unauthorized caller is not reported ready', ($denied['state'] ?? '') !== 'ready', json_encode($denied['state'] ?? null));
mdrAssert(
    'the denial reason is authorization_denied',
    ($denied['requirements'][0]['reason_code'] ?? '') === 'authorization_denied',
    json_encode($denied['requirements'][0] ?? null)
);

$noDeps = moduleDependencyReadiness(['id' => 'probe-consumer']);
mdrAssert('a module with no dependencies is ready', ($noDeps['state'] ?? '') === 'ready', json_encode($noDeps['state'] ?? null));

// ── Install honours the distinction on real disk ─────────────────────────
$token = bin2hex(random_bytes(4));
$moduleId = 'moddep' . $token;
$moduleDir = BASE_PATH . '/modules/' . $moduleId;
$manifestPath = $moduleDir . '/module.json';
$templateDir = BASE_PATH . '/templates/modules/' . $moduleId;
$cli = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(BASE_PATH . '/ikabud');
$zipOptional = sys_get_temp_dir() . '/mdr_' . $token . '_opt.zip';
$zipRequired = sys_get_temp_dir() . '/mdr_' . $token . '_req.zip';

$cleanup = static function () use ($moduleId, $moduleDir, $templateDir, $zipOptional, $zipRequired): void {
    if (function_exists('uninstallModule')) {
        @uninstallModule($moduleId, ['force' => true]);
    }
    mdrRrmdir($moduleDir);
    foreach (glob($moduleDir . '.bak_*') ?: [] as $backup) {
        mdrRrmdir($backup);
    }
    mdrRrmdir($templateDir);
    @unlink(BASE_PATH . '/tests/' . str_replace('-', '_', $moduleId) . '_module_test.php');
    @unlink($zipOptional);
    @unlink($zipRequired);
};
$cleanup();
register_shutdown_function($cleanup);

$made = (int) shell_exec($cli . ' make:module ' . escapeshellarg($moduleId) . ' >/dev/null 2>&1; echo $?');
mdrAssert('scaffold succeeds', $made === 0 && is_file($manifestPath));
if (!is_file($manifestPath)) {
    echo "\nResult: {$passed} passed, {$failed} failed\n";
    exit(1);
}

// Package A declares an unsatisfiable OPTIONAL dependency.
mdrSetDeps($manifestPath, [['id' => 'no.such.capability@1', 'required' => false]]);
$pA = (int) shell_exec($cli . ' module:pack ' . escapeshellarg($moduleId) . ' ' . escapeshellarg($zipOptional) . ' >/dev/null 2>&1; echo $?');
mdrAssert('pack (optional dep) succeeds', $pA === 0 && is_file($zipOptional));

// Package B declares the same dependency as REQUIRED.
mdrSetDeps($manifestPath, [['id' => 'no.such.capability@1', 'required' => true]]);
$pB = (int) shell_exec($cli . ' module:pack ' . escapeshellarg($moduleId) . ' ' . escapeshellarg($zipRequired) . ' >/dev/null 2>&1; echo $?');
mdrAssert('pack (required dep) succeeds', $pB === 0 && is_file($zipRequired));

$un = (int) shell_exec($cli . ' module:uninstall ' . escapeshellarg($moduleId) . ' >/dev/null 2>&1; echo $?');
mdrAssert('scaffold removed', $un === 0 && !is_dir($moduleDir));
if (function_exists('kernelFlushCodeCaches')) {
    kernelFlushCodeCaches();
}

$installOptional = installModuleFromZip($zipOptional);
mdrAssert('a missing OPTIONAL dependency still installs', !empty($installOptional['ok']), json_encode($installOptional));
mdrAssert('a missing optional dependency still ENABLES the module', !empty($installOptional['enabled']), json_encode($installOptional['warning'] ?? ($installOptional['missing'] ?? null)));
$optionalWarned = !empty($installOptional['warning']) || !empty($installOptional['degraded']);
mdrAssert('the degradation is reported, not hidden', $optionalWarned, json_encode(array_keys($installOptional)));

@uninstallModule($moduleId, ['force' => true]);
if (function_exists('kernelFlushCodeCaches')) {
    kernelFlushCodeCaches();
}

$installRequired = installModuleFromZip($zipRequired);
mdrAssert('a module with a missing REQUIRED dependency still installs its files', !empty($installRequired['ok']), json_encode($installRequired));
mdrAssert('but stays DISABLED', empty($installRequired['enabled']), json_encode($installRequired['enabled'] ?? null));
mdrAssert('and says which dependency is missing', !empty($installRequired['missing'][0] ?? null) || !empty($installRequired['warning']), json_encode($installRequired['missing'] ?? ($installRequired['warning'] ?? null)));

echo "\nResult: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
