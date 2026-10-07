<?php
/**
 * CMS per-content-type entity presentation contract.
 * Run: php tests/cms_per_type_presentation_test.php
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../modules/cms/helpers.php';
require_once __DIR__ . '/../modules/cms/handlers.php';

$passed = 0;
$failed = 0;

$check = static function (bool $ok, string $label, string $detail = '') use (&$passed, &$failed): void {
    if ($ok) {
        ++$passed;
        echo "  ok   {$label}\n";
        return;
    }

    ++$failed;
    echo "  FAIL {$label}" . ($detail !== '' ? " :: {$detail}" : '') . "\n";
};

echo "\n=== CMS PER-TYPE PRESENTATION ===\n";

$defaults = cmsEntityPresentationSettingsDefaults();
$check(isset($defaults['by_type']) && $defaults['by_type'] === [], 'defaults add an empty by_type map');
$check(($defaults['entity_list_title_lines'] ?? null) === '2', 'existing global default remains unchanged');

$settings = $defaults;
$settings['entity_list_card_density'] = 'compact';
$settings['by_type'] = [
    'course' => [
        'entity_list_title_lines' => '3',
        'entity_list_card_density' => 'airy',
        'single_show_tags' => 0,
    ],
];

$course = cmsEntityPresentationResolveForType($settings, 'course');
$lesson = cmsEntityPresentationResolveForType($settings, 'lesson');
$expectedLesson = $settings;
unset($expectedLesson['by_type']);

$check(($course['entity_list_title_lines'] ?? null) === '3', 'resolver applies the matching type override');
$check(($course['entity_list_card_density'] ?? null) === 'airy', 'resolver merges overrides per key');
$check(($course['entity_list_excerpt_length'] ?? null) === '120', 'resolver retains unspecified global values');
$check(!array_key_exists('by_type', $course), 'resolver does not expose the by_type store');
$check($lesson === $expectedLesson, 'no override resolves byte-for-byte to global settings');
$check(cmsEntityPresentationResolveForType($settings, 'Course') === $expectedLesson, 'non-slug lookup falls back unchanged');

$validated = cmsValidateEntityPresentationSettings($settings);
$check(
    ($validated['by_type']['course']['entity_list_title_lines'] ?? null) === '3',
    'validation preserves a type override through round trip'
);
$sparseKeys = array_keys($validated['by_type']['course'] ?? []);
sort($sparseKeys);
$check(
    $sparseKeys === [
        'entity_list_card_density',
        'entity_list_title_lines',
        'single_show_tags',
    ],
    'validation keeps overrides sparse'
);
$check(($validated['by_type']['course']['single_show_tags'] ?? null) === 0, 'validation reuses global boolean coercion');

$hostile = cmsValidateEntityPresentationSettings([
    'by_type' => [
        '../course' => ['entity_list_title_lines' => '4'],
        'Course' => ['entity_list_title_lines' => '4'],
        str_repeat('a', 65) => ['entity_list_title_lines' => '4'],
        'lesson' => ['unknown_key' => 'value'],
        'product' => ['entity_list_title_lines' => '99'],
    ],
]);
$guarded = $hostile['by_type'] ?? [];
$check(!isset($guarded['../course']) && !isset($guarded['Course']), 'validation rejects unsafe and uppercase type keys');
$check(!isset($guarded[str_repeat('a', 65)]), 'validation rejects type keys over 64 characters');
$check(!isset($guarded['lesson']), 'validation drops entries with no recognized settings');
$check(($guarded['product']['entity_list_title_lines'] ?? null) === '4', 'type override values use global clamping rules');

$many = [];
for ($i = 0; $i < 70; ++$i) {
    $many['type-' . $i] = ['entity_list_title_lines' => '3'];
}
$capped = cmsValidateEntityPresentationSettings(['by_type' => $many])['by_type'] ?? [];
$check(count($capped) === 64, 'validation caps by_type at 64 entries', 'count=' . count($capped));

$courseConfig = cmsCanonicalEntityPresentationConfig($settings, ['content_type' => 'course']);
$lessonConfig = cmsCanonicalEntityPresentationConfig($settings, ['entity_type' => 'lesson']);
$check(($courseConfig['list_title_lines'] ?? null) === 3, 'canonical config resolves content_type');
$check(($lessonConfig['list_title_lines'] ?? null) === 2, 'canonical config leaves an unconfigured entity_type unchanged');

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
