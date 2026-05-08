<?php

declare(strict_types=1);

/**
 * Smoke test for the SMS module manifest + capability surface.
 *
 * Verifies:
 *   - module.json is discoverable and validates
 *   - declares the canonical sms.send@1 capability expose
 *   - render-context contract for sms.page.log is registered after helpers load
 *
 * Run from repo root: php tests/sms_module_smoke_test.php
 */

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';

$pass = 0;
$fail = 0;

function t(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  ✓ {$label}\n";
    } else {
        $fail++;
        echo "  ✗ {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

echo "\n=== SMS MODULE SMOKE ===\n\n";

$manifestPath = dirname(__DIR__) . '/modules/sms/module.json';
t('manifest file present', is_file($manifestPath));

$check = validateModuleManifest($manifestPath);
t('manifest validates', !empty($check['ok']), (string)($check['error'] ?? ''));

$manifest = $check['manifest'] ?? [];
t('manifest id is sms', ($manifest['id'] ?? '') === 'sms');

$exposes = $manifest['capabilities']['exposes'] ?? [];
$exposedIds = array_column(is_array($exposes) ? $exposes : [], 'id');
t('exposes a sms send capability (sms.send@1)', in_array('sms.send@1', $exposedIds, true));

$modules = discoverModules();
t('sms module is discoverable', isset($modules['sms']));

if (isset($modules['sms']) && is_array($modules['sms'])) {
    loadModuleHelpers($modules['sms']);
    $contracts = function_exists('kernelRegisteredRenderContextContracts')
        ? kernelRegisteredRenderContextContracts()
        : [];
    t('sms.page.log render contract registered', isset($contracts['sms.page.log']));
}

echo "\n══════════════════════════════════════════════════\n";
echo "  PASS: {$pass}  FAIL: {$fail}\n";
echo "══════════════════════════════════════════════════\n";
exit($fail > 0 ? 1 : 0);
