<?php
/**
 * SlotRegistry integration test — verifies the slot contribution
 * and resolution pipeline works end-to-end.
 *
 * Usage: php tests/slot_registry_test.php
 */

declare(strict_types=1);

// Bootstrap
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

function assert_count(int $expected, array $actual, string $label): void
{
    global $passed, $failed;
    if (count($actual) === $expected) {
        echo "  PASS: {$label} (count={$expected})\n";
        $passed++;
    } else {
        echo "  FAIL: {$label} (expected {$expected}, got " . count($actual) . ")\n";
        $failed++;
    }
}

// ── Test 1: Component ikb_slot is registered in ComponentRegistry ──
echo "Test 1: ikb_slot component registration\n";
$slotComponent = ComponentRegistry::get('ikb_slot');
assert_true($slotComponent !== null, 'ikb_slot is registered');
assert_true(($slotComponent['category'] ?? '') === 'structural', 'ikb_slot category = structural');
assert_true(($slotComponent['attributes']['name']['required'] ?? false) === true, 'ikb_slot name attribute is required');

// ── Test 2: Register and resolve a slot contribution ──
echo "\nTest 2: Register and resolve slot contribution\n";
SlotRegistry::reset();

SlotRegistry::register('content.after', [
    'id' => 'test.related',
    'component' => 'ikb_entity_list',
    'attrs' => ['source' => 'cms_post.recent', 'view' => 'card_grid', 'limit' => '3'],
    'priority' => 10,
    'conditions' => [
        'entity_type' => 'cms.post',
        'view' => 'detail',
    ],
]);

$matched = SlotRegistry::resolve('content.after', [
    'entity_type' => 'cms.post',
    'view' => 'detail',
]);
assert_count(1, $matched, 'resolved with matching conditions');
assert_true(($matched[0]['id'] ?? '') === 'test.related', 'matched contribution has correct id');

// ── Test 3: Conditions prevent non-matching context ──
echo "\nTest 3: Conditions filter non-matching context\n";
$noMatch = SlotRegistry::resolve('content.after', [
    'entity_type' => 'ecommerce.product',
    'view' => 'detail',
]);
assert_count(0, $noMatch, 'no match for different entity_type');

$noMatch2 = SlotRegistry::resolve('content.after', [
    'entity_type' => 'cms.post',
    'view' => 'list',
]);
assert_count(0, $noMatch2, 'no match for different view');

// ── Test 4: Empty slot returns empty ──
echo "\nTest 4: Empty slot resolution\n";
$empty = SlotRegistry::resolve('nonexistent.slot', []);
assert_count(0, $empty, 'nonexistent slot returns empty');

// ── Test 5: Priority ordering ──
echo "\nTest 5: Priority ordering\n";
SlotRegistry::reset();

SlotRegistry::register('footer.main', [
    'id' => 'footer.high',
    'component' => 'ikb_text',
    'priority' => 5,
]);
SlotRegistry::register('footer.main', [
    'id' => 'footer.low',
    'component' => 'ikb_text',
    'priority' => 15,
]);
SlotRegistry::register('footer.main', [
    'id' => 'footer.medium',
    'component' => 'ikb_text',
    'priority' => 10,
]);

$footer = SlotRegistry::resolve('footer.main', []);
assert_count(3, $footer, 'all footer contributions resolved');
assert_true($footer[0]['id'] === 'footer.high', 'priority 5 resolves first');
assert_true($footer[1]['id'] === 'footer.medium', 'priority 10 resolves second');
assert_true($footer[2]['id'] === 'footer.low', 'priority 15 resolves third');

// ── Test 6: Role-based conditions ──
echo "\nTest 6: Role-based conditions\n";
SlotRegistry::reset();

SlotRegistry::register('sidebar.primary', [
    'id' => 'admin.panel',
    'component' => 'ikb_panel',
    'conditions' => ['role' => 'administrator'],
]);

$adminCtx = SlotRegistry::resolve('sidebar.primary', ['role' => 'administrator']);
assert_count(1, $adminCtx, 'admin sees admin panel');

$guestCtx = SlotRegistry::resolve('sidebar.primary', ['role' => 'guest']);
assert_count(0, $guestCtx, 'guest does not see admin panel');

// ── Test 7: Duplicate registration is idempotent ──
echo "\nTest 7: Duplicate registration\n";
SlotRegistry::reset();

SlotRegistry::register('hero', [
    'id' => 'site.hero',
    'component' => 'ikb_section',
    'priority' => 10,
]);
SlotRegistry::register('hero', [
    'id' => 'site.hero', // same id
    'component' => 'ikb_section',
    'priority' => 10,
]);
$hero = SlotRegistry::resolve('hero', []);
assert_count(1, $hero, 'duplicate registration does not add twice');

// ── Test 8: All registered slots ──
echo "\nTest 8: SlotRegistry inspection\n";
SlotRegistry::reset();

SlotRegistry::register('content.before', ['id' => 'a', 'component' => 'ikb_text']);
SlotRegistry::register('content.after', ['id' => 'b', 'component' => 'ikb_text']);

$all = SlotRegistry::all();
assert_true(isset($all['content.before']), 'content.before in all()');
assert_true(isset($all['content.after']), 'content.after in all()');

// ── Summary ──
echo "\n==============================\n";
echo "Results: {$passed} passed, {$failed} failed\n";
echo "==============================\n";

exit($failed > 0 ? 1 : 0);
