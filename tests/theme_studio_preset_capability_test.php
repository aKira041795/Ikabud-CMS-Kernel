<?php
/**
 * Theme Studio preset capability contract.
 * Run: php tests/theme_studio_preset_capability_test.php
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../modules/theme-studio/helpers.php';

use Ikabud\Kernel\Capabilities\CapabilityBus;
use Ikabud\Kernel\Capabilities\CapabilityException;
use Ikabud\Kernel\Capabilities\CapabilityRegistry;

$passed = 0;
$failed = 0;
$check = static function (bool $ok, string $label, string $detail = '') use (&$passed, &$failed): void {
    if ($ok) {
        ++$passed;
        echo "PASS: {$label}\n";
        return;
    }
    ++$failed;
    echo "FAIL: {$label}" . ($detail !== '' ? " ({$detail})" : '') . "\n";
};

$manifest = json_decode((string)file_get_contents(__DIR__ . '/../modules/theme-studio/module.json'), true);
$policy = is_array($manifest['capabilities']['policy'] ?? null) ? $manifest['capabilities']['policy'] : [];
$handlers = theme_studio_capability_handlers();
$originalActive = trim((string)(getModuleSettings('theme-studio')['active_preset'] ?? ''));

$registry = new CapabilityRegistry();
$bus = new CapabilityBus($registry);
foreach (['theme.presets.list@1', 'theme.preset.apply@1'] as $capabilityId) {
    $registry->register(
        $capabilityId,
        'theme-studio',
        $handlers[$capabilityId],
        10,
        ['first'],
        ['policy' => $policy]
    );
}

try {
    $unknown = $bus->call('theme.preset.apply@1', ['slug' => 'does-not-exist'], ['caller_module' => 'cms']);
    $check(empty($unknown['ok']) && ($unknown['code'] ?? '') === 'unknown_preset', 'unknown slug is rejected');
    $check(trim((string)(getModuleSettings('theme-studio')['active_preset'] ?? '')) === $originalActive, 'unknown slug does not mutate active preset');

    $hostile = $bus->call('theme.preset.apply@1', ['slug' => '../../corporate'], ['caller_module' => 'cms']);
    $check(empty($hostile['ok']) && ($hostile['code'] ?? '') === 'unknown_preset', 'hostile slug is rejected');

    $denied = false;
    try {
        $bus->call('theme.preset.apply@1', ['slug' => 'corporate'], ['caller_module' => 'untrusted-module']);
    } catch (CapabilityException $e) {
        $denied = true;
    }
    $check($denied, 'non-caller is denied by the capability bus');
    $check(trim((string)(getModuleSettings('theme-studio')['active_preset'] ?? '')) === $originalActive, 'denied caller does not mutate active preset');

    $applied = $bus->call('theme.preset.apply@1', ['slug' => 'corporate'], ['caller_module' => 'cms']);
    $check(!empty($applied['ok']) && ($applied['active_slug'] ?? '') === 'corporate', 'real preset is accepted');

    $listed = $bus->call('theme.presets.list@1', [], ['caller_module' => 'cms']);
    $corporate = array_values(array_filter($listed['presets'] ?? [], static fn(array $preset): bool => ($preset['slug'] ?? '') === 'corporate'));
    $check(count($corporate) === 1 && !empty($corporate[0]['active']), 'catalogue reports the active preset');
} finally {
    saveModuleSettings('theme-studio', ['active_preset' => $originalActive]);
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
