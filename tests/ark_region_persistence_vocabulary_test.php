<?php
/**
 * ARK region persistence-vocabulary ratchet.
 *
 * Every section_settings.<key> consumed by an ARK region template must be
 * emitted by that region's CMS persistence validator. This complements
 * ark_declared_control_honour_test.php, which checks schema controls in the
 * opposite direction (declared control -> rendered output).
 *
 * Run: php tests/ark_region_persistence_vocabulary_test.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
// The sidebar validator asks module discovery only to extend its route allowlist.
// Empty discovery is sufficient for this key-contract test and keeps it isolated.
if (!function_exists('discoverModules')) {
    function discoverModules(): array
    {
        return [];
    }
}
require_once __DIR__ . '/../modules/cms/helpers.php';

$root = dirname(__DIR__);
$regionsPath = $root . '/storage/cms-themes/ark/templates/regions';
$passed = 0;
$failed = 0;

// Template-only defaults belong here only when a template deliberately consumes a
// non-persisted runtime value. Each entry requires a per-key justification comment.
$templateOnlyDefaults = [
    'header' => [],
    'footer' => [],
    'sidebar' => [],
];

echo "ARK region persistence vocabulary ratchet\n\n";

$templates = glob($regionsPath . '/*.disyl') ?: [];
sort($templates);
foreach ($templates as $templatePath) {
    $region = pathinfo($templatePath, PATHINFO_FILENAME);
    $template = (string)file_get_contents($templatePath);
    preg_match_all('/section_settings\.([a-z_][a-z0-9_]*)/', $template, $matches);
    $readKeys = array_values(array_unique($matches[1] ?? []));
    sort($readKeys);

    // Parse the validator rather than relying on a database or active tenant. A key is
    // producible when it belongs to the section's persisted defaults vocabulary and the
    // validator body explicitly handles that spelling (directly or in a key list).
    $validatorName = 'cmsValidate' . ucfirst($region) . 'Settings';
    $reflection = new ReflectionFunction($validatorName);
    $validatorLines = file((string)$reflection->getFileName());
    $validatorSource = implode('', array_slice(
        $validatorLines ?: [],
        $reflection->getStartLine() - 1,
        $reflection->getEndLine() - $reflection->getStartLine() + 1
    ));
    $defaultsName = 'cms' . ucfirst($region) . 'SettingsDefaults';
    $defaultsReflection = new ReflectionFunction($defaultsName);
    $defaultsLines = file((string)$defaultsReflection->getFileName());
    $defaultsSource = implode('', array_slice(
        $defaultsLines ?: [],
        $defaultsReflection->getStartLine() - 1,
        $defaultsReflection->getEndLine() - $defaultsReflection->getStartLine() + 1
    ));
    preg_match_all("/['\"]([a-z_][a-z0-9_]*)['\"]\\s*=>/", $defaultsSource, $defaultMatches);
    $persistedKeys = array_values(array_unique($defaultMatches[1] ?? []));
    $producedKeys = array_values(array_filter(
        $persistedKeys,
        static fn(string $key): bool => preg_match("/['\"]" . preg_quote($key, '/') . "['\"]/", $validatorSource) === 1
    ));
    $allowed = $templateOnlyDefaults[$region] ?? [];
    $missing = array_values(array_diff($readKeys, $producedKeys, array_keys($allowed)));
    sort($missing);

    if ($missing === []) {
        ++$passed;
        echo "  PASS: {$region} template reads only validator-produced keys\n";
        continue;
    }

    ++$failed;
    echo "  FAIL: {$region} template reads keys its validator cannot produce"
        . ' :: ' . implode(', ', $missing) . "\n";
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
