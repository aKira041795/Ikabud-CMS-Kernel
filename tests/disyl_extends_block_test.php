<?php
/**
 * R32 — DiSyL extends/block Inheritance Test
 *
 * Verifies template inheritance: parent→child→grandchild block overrides.
 *
 * Run: php tests/disyl_extends_block_test.php
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use Ikabud\Kernel\DiSyL\TemplateEngine;

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
        $errors[] = $label . ($detail ? ": {$detail}" : '');
        echo "  ✗ {$label}" . ($detail ? " — {$detail}" : '') . "\n";
    }
}

echo "=== DiSyL extends/block Inheritance ===\n";

// ── Setup temp template directory ──
$tmpDir = sys_get_temp_dir() . '/disyl_extends_test_' . getmypid();
@mkdir($tmpDir, 0755, true);
$cacheDir = $tmpDir . '/cache';
@mkdir($cacheDir, 0755, true);

$engine = new TemplateEngine($tmpDir, $cacheDir, false);

// ── Layout (root) ──
file_put_contents("{$tmpDir}/layout.disyl", '<html><head>{block head}Default Head{/block}</head><body>{block content}Default Content{/block}<footer>{block footer}Default Footer{/block}</footer></body></html>');

// ── Child: overrides content ──
file_put_contents("{$tmpDir}/page.disyl", '{extends "layout"}{block content}Page Content{/block}');

// ── Test 1: Basic extends ──
$out = $engine->render('page');
t('child overrides content block', str_contains($out, 'Page Content'));
t('child preserves parent head default', str_contains($out, 'Default Head'));
t('child preserves parent footer default', str_contains($out, 'Default Footer'));
t('output includes html structure', str_contains($out, '<html>') && str_contains($out, '</html>'));

// ── Grandchild: overrides content + head ──
file_put_contents("{$tmpDir}/subpage.disyl", '{extends "page"}{block head}Custom Head{/block}{block content}Subpage Content{/block}');

$out2 = $engine->render('subpage');
t('grandchild overrides head', str_contains($out2, 'Custom Head'));
t('grandchild overrides content', str_contains($out2, 'Subpage Content'));
t('grandchild preserves footer from root', str_contains($out2, 'Default Footer'));

// ── Test 3: Block with variables ──
file_put_contents("{$tmpDir}/dynamic.disyl", '{extends "layout"}{block content}Hello {name}!{/block}');

$out3 = $engine->render('dynamic', ['name' => 'World']);
t('block content resolves variables', str_contains($out3, 'Hello World!'));

// ── Test 4: Multiple blocks overridden ──
file_put_contents("{$tmpDir}/full-override.disyl", '{extends "layout"}{block head}FO Head{/block}{block content}FO Content{/block}{block footer}FO Footer{/block}');

$out4 = $engine->render('full-override');
t('all three blocks overridden', str_contains($out4, 'FO Head') && str_contains($out4, 'FO Content') && str_contains($out4, 'FO Footer'));
t('no default content remains', !str_contains($out4, 'Default'));

// ── Cleanup ──
$files = glob("{$tmpDir}/*");
foreach ($files as $f) {
    if (is_file($f)) unlink($f);
}
$cacheFiles = glob("{$cacheDir}/*");
foreach ($cacheFiles as $f) {
    if (is_file($f)) unlink($f);
}
@rmdir($cacheDir);
@rmdir($tmpDir);

// ── Summary ──
echo "\n{$pass} passed, {$fail} failed\n";
if (!empty($errors)) {
    echo "Failures:\n";
    foreach ($errors as $e) {
        echo "  - {$e}\n";
    }
}
exit($fail > 0 ? 1 : 0);
