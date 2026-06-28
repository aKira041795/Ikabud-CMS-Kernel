<?php
/**
 * Slot render test — verifies {ikb_slot} renders correctly
 * through the TemplateEngine.
 *
 * Usage: php tests/slot_render_test.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use Ikabud\Kernel\Services\SlotRegistry;
use Ikabud\Kernel\DiSyL\ComponentRegistry;

$passed = 0;
$failed = 0;

function assert_true(mixed $condition, string $label): void
{
    global $passed, $failed;
    if ($condition) {
        echo "  PASS: {$label}\n";
        $passed++;
    } else {
        echo "  FAIL: {$label}\n";
        $failed++;
    }
}

function assert_contains(string $haystack, string $needle, string $label): void
{
    global $passed, $failed;
    if (str_contains($haystack, $needle)) {
        echo "  PASS: {$label}\n";
        $passed++;
    } else {
        echo "  FAIL: {$label}\n  Expected '{$needle}' in output\n";
        $failed++;
    }
}

// ── Setup: Create a test template engine ──
$templateDir = sys_get_temp_dir() . '/disyl-slot-test-' . uniqid();
$cacheDir = $templateDir . '/cache';
@mkdir($templateDir . '/layouts', 0777, true);
@mkdir($cacheDir, 0777, true);

$engine = new Ikabud\Kernel\DiSyL\TemplateEngine($templateDir, $cacheDir, false);

// ── Test 1: Slot with no contributions renders children as fallback ──
echo "Test 1: Slot with no contributions renders children\n";
SlotRegistry::reset();
$result = $engine->renderString('{ikb_slot name="empty.slot"}Fallback content{/ikb_slot}', []);
assert_contains($result, 'Fallback content', 'fallback content rendered when no contributions');

// ── Test 2: Slot with contributions renders them ──
echo "\nTest 2: Slot with contributions renders them\n";
SlotRegistry::reset();
SlotRegistry::register('content.after', [
    'id' => 'test.block',
    'component' => 'ikb_panel',
    'attrs' => ['tone' => 'muted'],
    'children' => 'Contributed content',
    'priority' => 10,
    'conditions' => [
        'entity_type' => 'cms.post',
    ],
]);
$result = $engine->renderString('{ikb_slot name="content.after" /}', [
    'entity_type' => 'cms.post',
    'view' => 'detail',
]);
assert_contains($result, 'Contributed content', 'contributed content rendered');
assert_contains($result, 'ikb-panel', 'contributed component wrapper rendered');
assert_contains($result, 'bg-gray-50', 'ikb_panel tone=muted applied');

// ── Test 3: Slot with non-matching conditions renders nothing (only fallback children) ──
echo "\nTest 3: Slot with non-matching conditions renders nothing\n";
SlotRegistry::reset();
SlotRegistry::register('content.after', [
    'id' => 'test.block',
    'component' => 'ikb_panel',
    'attrs' => ['tone' => 'muted'],
    'children' => 'Contributed content',
    'priority' => 10,
    'conditions' => [
        'entity_type' => 'cms.post',
        'view' => 'detail',
    ],
]);
$result = $engine->renderString('{ikb_slot name="content.after" /}', [
    'entity_type' => 'ecommerce.product',
    'view' => 'list',
]);
// No contributions match, self-closing tag has no children, output should be empty
assert_true(trim($result) === '', 'non-matching conditions produce empty output');

// ── Test 4: Multiple contributions in priority order ──
echo "\nTest 4: Multiple contributions in priority order\n";
SlotRegistry::reset();

SlotRegistry::register('footer.main', [
    'id' => 'first',
    'component' => 'ikb_text',
    'children' => 'First',
    'priority' => 5,
]);
SlotRegistry::register('footer.main', [
    'id' => 'second',
    'component' => 'ikb_text',
    'children' => 'Second',
    'priority' => 10,
]);
$result = $engine->renderString('{ikb_slot name="footer.main" /}', []);
$firstPos = strpos($result, 'First');
$secondPos = strpos($result, 'Second');
assert_true($firstPos !== false && $secondPos !== false, 'both contributions rendered');
assert_true($firstPos < $secondPos, 'priority order preserved (First before Second)');

// ── Test 5: Component renders ikb_slot from file template ──
echo "\nTest 5: ikb_slot renders from template file\n";
SlotRegistry::reset();

// Create a test template that uses ikb_slot
$templateContent = '<div class="shell">{ikb_slot name="content.top" /}</div>';
file_put_contents($templateDir . '/layouts/test-slot.disyl', $templateContent);

SlotRegistry::register('content.top', [
    'id' => 'top.banner',
    'component' => 'ikb_alert',
    'attrs' => ['tone' => 'info'],
    'children' => 'Banner content',
    'priority' => 10,
]);

$result = $engine->render('layouts/test-slot.disyl', []);
assert_contains($result, 'Banner content', 'slot content rendered from file template');
assert_contains($result, '<div class="shell">', 'shell wrapper preserved');

echo "\n==============================\n";
echo "Results: {$passed} passed, {$failed} failed\n";
echo "==============================\n";

// Cleanup
$cleanup = function(string $dir): void {
    if (!is_dir($dir)) { return; }
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($files as $file) {
        $file->isDir() ? @rmdir($file->getRealPath()) : @unlink($file->getRealPath());
    }
    @rmdir($dir);
};
$cleanup($templateDir);

exit($failed > 0 ? 1 : 0);
