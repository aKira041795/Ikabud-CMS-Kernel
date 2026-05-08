<?php

declare(strict_types=1);

/**
 * Smoke test for the TinyMCE module.
 *
 * Verifies:
 *   - manifest validates
 *   - declares the tinymce.assets.get@1 + tinymce.config.get@1 capabilities
 *   - module loads without warnings
 *
 * Run from repo root: php tests/tinymce_module_smoke_test.php
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

echo "\n=== TINYMCE MODULE SMOKE ===\n\n";

$check = validateModuleManifest(dirname(__DIR__) . '/modules/tinymce/module.json');
t('manifest validates', !empty($check['ok']), (string)($check['error'] ?? ''));

$manifest = $check['manifest'] ?? [];
t('manifest id is tinymce', ($manifest['id'] ?? '') === 'tinymce');
t('owns no tables (pure service)', ($manifest['owns_tables'] ?? null) === []);

$exposedIds = array_column($manifest['capabilities']['exposes'] ?? [], 'id');
t('declares tinymce.assets.get@1', in_array('tinymce.assets.get@1', $exposedIds, true));
t('declares tinymce.config.get@1', in_array('tinymce.config.get@1', $exposedIds, true));

$modules = discoverModules();
t('tinymce is discoverable', isset($modules['tinymce']));

echo "\n══════════════════════════════════════════════════\n";
echo "  PASS: {$pass}  FAIL: {$fail}\n";
echo "══════════════════════════════════════════════════\n";
exit($fail > 0 ? 1 : 0);
