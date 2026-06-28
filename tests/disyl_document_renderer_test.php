<?php
/**
 * DiSyL Document Renderer Tests — Phase 6
 *
 * Tests the conversion of DiSyL JSON component trees into rendered HTML
 * through the governed component system.
 *
 * Usage: php tests/disyl_document_renderer_test.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

// Load module manager and CMS builder helpers
require_once __DIR__ . '/../src/helpers/module-manager.php';

$cmsHelperDir = __DIR__ . '/../modules/cms/helpers';
foreach (['10-core.php', '50-builder.php'] as $helper) {
    require_once $cmsHelperDir . '/' . $helper;
}

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
        echo "  FAIL: {$label} — expected '{$needle}' in output\n";
        $failed++;
    }
}

// ── Test 1: Empty node returns empty ──
echo "Test 1: Empty node returns empty\n";
$result = cmsRenderDiSyLDocument([], []);
assert_true($result === '', 'empty node returns empty');

// ── Test 2: Simple component without children ──
echo "\nTest 2: Simple component without children\n";
$node = [
    'component' => 'ikb_section',
    'attrs' => ['padding_y' => 'lg'],
];
$result = cmsRenderDiSyLDocument($node, []);
// Should contain a <section> tag and the governed component class
assert_contains($result, '<section', 'ikb_section renders <section> tag');
assert_contains($result, 'py-8', 'padding_y=lg maps to py-8');

// ── Test 3: Nested component tree ──
echo "\nTest 3: Nested component tree\n";
$node = [
    'component' => 'ikb_section',
    'attrs' => ['padding' => 'large'],
    'children' => [
        [
            'component' => 'ikb_container',
            'attrs' => ['size' => 'medium'],
            'children' => [
                [
                    'component' => 'ikb_text',
                    'children' => 'Hello, DiSyL!',
                ],
            ],
        ],
    ],
];
$result = cmsRenderDiSyLDocument($node, []);
assert_contains($result, '<section', 'section wrapper');
assert_contains($result, '<div', 'container renders as div');
assert_contains($result, 'Hello, DiSyL!', 'nested text children rendered');

// ── Test 4: Build DiSyL template string ──
echo "\nTest 4: DiSyL template string builder\n";
$tmpl = cmsBuildDiSyLTemplateString('ikb_panel', ['tone' => 'muted', 'spacing' => 'lg'], 'Content');
assert_contains($tmpl, 'ikb_panel', 'component name in template');
assert_contains($tmpl, 'tone="muted"', 'tone attr in template');
assert_contains($tmpl, 'spacing="lg"', 'spacing attr in template');
assert_contains($tmpl, 'Content', 'children in template');
assert_contains($tmpl, '{/ikb_panel}', 'closing tag');

// Self-closing
$tmpl2 = cmsBuildDiSyLTemplateString('ikb_spinner', ['size' => 'md'], '');
assert_contains($tmpl2, '/}', 'self-closing tag when no children');

// ── Test 5: HTML attribute builder ──
echo "\nTest 5: HTML attribute builder\n";
$attrs = cmsBuildHtmlAttrs(['class' => 'foo bar', 'id' => 'test', 'hidden' => true]);
assert_contains($attrs, 'class="foo bar"', 'class attribute');
assert_contains($attrs, 'id="test"', 'id attribute');
assert_contains($attrs, 'hidden', 'boolean attribute');
assert_true(!str_contains($attrs, 'false'), 'no false values');

// ── Test 6: Fallback widget renderer ──
echo "\nTest 6: Fallback widget renderer\n";
$html = cmsRenderWidgetFromDiSyL('ikb_panel', ['tone' => 'muted', 'spacing' => 'lg'], 'Panel content');
assert_contains($html, 'ikb-widget', 'widget CSS class');
assert_contains($html, 'ikb-tone--muted', 'tone CSS class');
assert_contains($html, 'ikb-widget--panel', 'component CSS class');
assert_contains($html, 'Panel content', 'children preserved');

// ── Test 7: Complete DiSyL document with entity list ──
echo "\nTest 7: Complete DiSyL document\n";
$doc = [
    'component' => 'ikb_section',
    'attrs' => ['padding' => 'large', 'background' => 'gray'],
    'children' => [
        [
            'component' => 'ikb_container',
            'attrs' => ['size' => 'xlarge', 'center' => true],
            'children' => [
                [
                    'component' => 'ikb_entity_list',
                    'attrs' => ['source' => 'cms_post.recent', 'view' => 'card_grid', 'limit' => '6'],
                ],
            ],
        ],
    ],
];
$result = cmsRenderDiSyLDocument($doc, ['entity_type' => 'cms.post']);
assert_contains($result, 'py-12', 'padding=large renders py-12');
assert_contains($result, 'bg-gray-50', 'bg=gray renders bg-gray-50');

echo "\n==============================\n";
echo "Results: {$passed} passed, {$failed} failed\n";
echo "==============================\n";

exit($failed > 0 ? 1 : 0);
