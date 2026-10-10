<?php
/**
 * Page-builder render parity — the canvas and the public renderer must agree
 * on which widgets exist, and every registered renderer must be callable.
 *
 * Why this guard exists (stable contract §12, and chair consult 2026-10-10):
 * the public renderer is PHP (modules/cms/builder-renderers.php) while the
 * editor canvas is a PARALLEL React re-implementation
 * (builder-ui/src/builder/components/NodeRenderer.tsx). Nothing enforced that
 * the two agreed. A widget could be selectable in the panel, carry a PHP
 * renderer, and still be drawn as a bare <div> in the canvas — invisible to
 * the author while rendering correctly in public. `ai_block` was exactly that.
 *
 * This test asserts the cheap, reliable half of parity:
 *   (a) CMS component types, the PHP renderer registry, and the canvas
 *       dispatch table describe the SAME set of widgets;
 *   (b) every registered renderer is callable and returns a string without
 *       raising a PHP error — a registry entry naming a missing function is a
 *       fatal at render time and nothing else catches it;
 *   (c) the document render pipeline produces HTML for a composed document.
 *
 * It deliberately does NOT assert prop-level or visual equivalence: those need
 * a browser-computed-geometry harness (see docs/page-builder/…). A prop diff
 * across two languages produced false positives on the first attempt, and a
 * guard that cannot be trusted is worse than no guard.
 *
 * Run: php tests/builder_render_parity_test.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);

$rendererPath = $root . '/modules/cms/builder-renderers.php';
$typesPath    = $root . '/modules/cms/builder-ui/src/builder/core/types.ts';
$canvasPath   = $root . '/modules/cms/builder-ui/src/builder/components/NodeRenderer.tsx';

foreach ([$rendererPath, $typesPath, $canvasPath] as $required) {
    if (!is_file($required)) {
        fwrite(STDERR, "MISSING REQUIRED FILE: {$required}\n");
        exit(1);
    }
}

$passed = 0;
$failed = 0;
function prAssert(string $label, bool $condition, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        echo "PASS: {$label}\n";
        $passed++;
        return;
    }
    echo "FAIL: {$label}" . ($detail !== '' ? " :: {$detail}" : '') . "\n";
    $failed++;
}

// ── Read the two TypeScript contracts ────────────────────────────────────
$typesSource  = (string) file_get_contents($typesPath);
$canvasSource = (string) file_get_contents($canvasPath);

// (1) The CMS widget vocabulary: the union the editor, panel and canvas share.
if (preg_match('/export type CMSComponentType =([^;]+);/s', $typesSource, $m) !== 1) {
    fwrite(STDERR, "Could not locate `export type CMSComponentType` in {$typesPath}\n");
    exit(1);
}
preg_match_all("/'([a-z_]+)'/", $m[1], $typeHits);
$unionTypes = array_values(array_unique($typeHits[1]));
sort($unionTypes);

// (1b) The SELECTABLE widget registry, not just the type vocabulary. The union
// says which types may exist; CMS_COMPONENTS says which the insert panel offers.
// Asserting only the union would not prove "every widget the editor offers".
$componentsPath = $root . '/modules/cms/builder-ui/src/builder/core/components.ts';
if (!is_file($componentsPath)) {
    fwrite(STDERR, "MISSING REQUIRED FILE: {$componentsPath}\n");
    exit(1);
}
$componentsSource = (string) file_get_contents($componentsPath);
if (preg_match('/export const CMS_COMPONENTS[^=]*=\s*\[(.*?)\n\];/s', $componentsSource, $registryBlock) !== 1) {
    fwrite(STDERR, "Could not locate `export const CMS_COMPONENTS` in {$componentsPath}\n");
    exit(1);
}
preg_match_all("/^\s*type: '([a-z_]+)'/m", $registryBlock[1], $defHits);
$definedTypes = array_values(array_unique($defHits[1]));
sort($definedTypes);

// (2) The canvas dispatch table: which widget types NodeRenderer actually draws.
// Cases fall through, so a run of `case` labels is attributed to the first
// following `return <Component`. `default:` closes a run without attributing.
$canvasBlock = $canvasSource;
$switchPos = strrpos($canvasSource, 'switch (node.type) {');
if ($switchPos === false) {
    fwrite(STDERR, "Could not locate the widget dispatch switch in {$canvasPath}\n");
    exit(1);
}
$canvasBlock = substr($canvasSource, $switchPos);
$endPos = strpos($canvasBlock, "\n  };");
if ($endPos !== false) {
    $canvasBlock = substr($canvasBlock, 0, $endPos);
}
$canvasTypes = [];
$pending = [];
foreach (explode("\n", $canvasBlock) as $line) {
    if (preg_match("/case '([a-z_]+)':/", $line, $cm) === 1) {
        $pending[] = $cm[1];
        continue;
    }
    if (str_contains($line, 'default:')) {
        $pending = [];
        continue;
    }
    if (preg_match('/return <([A-Za-z0-9_]+)/', $line, $rm) === 1) {
        foreach ($pending as $type) {
            $canvasTypes[$type] = $rm[1];
        }
        $pending = [];
    }
}
$canvasTypeKeys = array_keys($canvasTypes);
sort($canvasTypeKeys);

// ── (a) Vocabulary agreement ─────────────────────────────────────────────
prAssert(
    'the CMS widget vocabulary is non-empty and parsed',
    count($unionTypes) > 0 && count($canvasTypeKeys) > 0,
    count($unionTypes) . ' types, ' . count($canvasTypeKeys) . ' dispatched'
);

$missingFromCanvas = array_values(array_diff($unionTypes, $canvasTypeKeys));
prAssert(
    'every CMS widget type the editor offers is drawn by the canvas',
    $missingFromCanvas === [],
    $missingFromCanvas === []
        ? count($unionTypes) . ' types all dispatched'
        : 'invisible in the canvas: ' . implode(', ', $missingFromCanvas)
);

$canvasOnly = array_values(array_diff($canvasTypeKeys, $unionTypes));
prAssert(
    'the canvas draws no widget type outside the CMS vocabulary',
    $canvasOnly === [],
    $canvasOnly === [] ? 'no strays' : 'undocumented in types.ts: ' . implode(', ', $canvasOnly)
);

prAssert(
    'the selectable component registry matches the CMS widget vocabulary',
    $definedTypes !== [] && $definedTypes === $unionTypes,
    $definedTypes === $unionTypes
        ? count($definedTypes) . ' definitions, vocabulary identical'
        : 'definition-only: ' . implode(', ', array_diff($definedTypes, $unionTypes))
            . ' | vocabulary-only: ' . implode(', ', array_diff($unionTypes, $definedTypes))
);

// ── Boot the app and take the live renderer registry ─────────────────────
ob_start();
require $root . '/bootstrap.php';
require_once BASE_PATH . '/src/helpers/module-manager.php';
$cmsPath = BASE_PATH . '/modules/cms';
foreach (['/helpers/50-builder.php', '/builder-renderers.php'] as $file) {
    if (file_exists($cmsPath . $file)) {
        require_once $cmsPath . $file;
    }
}
ob_end_clean();
unset($file);

if (!function_exists('cmsBuilderWidgetRenderers')) {
    fwrite(STDERR, "cmsBuilderWidgetRenderers() is not available after bootstrap\n");
    exit(1);
}

// ── Self-sufficiency: the render path must work from a COLD load ─────────
// This is not hypothetical. cmsBuilderRenderNode() calls
// cmsBuilderGetAnimationAttrs() on every node, but that helper is defined by
// helpers/00-bootstrap.php, which 50-builder.php did not load. A cold render
// therefore fataled — and this guard's own composed render below only passed
// because the renderer loop ran first and `entity_list` happened to pull
// helpers/00-bootstrap.php in as a side effect. An incidental include inside a
// loop is not a contract, so it is now asserted directly.
$coldDocument = [
    'schema_version' => 1,
    'document' => [
        'id' => 'cold_root', 'type' => 'document', 'props' => [], 'style' => [], 'meta' => [],
        'children' => [[
            'id' => 'cold_heading', 'type' => 'heading', 'props' => [], 'style' => [], 'meta' => [], 'children' => [],
        ]],
    ],
];
$coldHtml = '';
try {
    $coldHtml = cmsBuilderRenderDocument($coldDocument, ['is_editor_preview' => true]);
} catch (Throwable $e) {
    $coldHtml = 'THREW ' . get_class($e) . ': ' . $e->getMessage();
}
prAssert(
    'the render path renders from a cold load, before any renderer has run',
    str_contains($coldHtml, 'data-node-id="cold_heading"'),
    str_contains($coldHtml, 'THREW')
        ? $coldHtml
        : 'rendered ' . strlen($coldHtml) . ' bytes but without the node marker'
);

$registry = cmsBuilderWidgetRenderers();
prAssert(
    'the PHP renderer registry is non-empty',
    is_array($registry) && count($registry) > 0,
    count($registry) . ' entries'
);
ksort($registry);
$registryTypes = array_keys($registry);

$noRenderer = array_values(array_diff($unionTypes, $registryTypes));
prAssert(
    'every CMS widget type has a server renderer',
    $noRenderer === [],
    $noRenderer === [] ? count($unionTypes) . ' types all renderable' : implode(', ', $noRenderer)
);

$unadvertised = array_values(array_diff($registryTypes, $unionTypes));
prAssert(
    'no server renderer exists for a widget the editor cannot offer',
    $unadvertised === [],
    $unadvertised === [] ? 'registry and vocabulary agree' : implode(', ', $unadvertised)
);

// ── (b) Every registered renderer is callable and quiet ──────────────────
// A registry entry naming a missing function is a fatal at render time; a
// renderer that emits a warning is a defect the author only sees in the log.
$warnings = [];
set_error_handler(function (int $severity, string $message, string $file, int $line) use (&$warnings): bool {
    if (!(error_reporting() & $severity)) {
        return true;
    }
    $warnings[] = $message . ' @ ' . basename($file) . ':' . $line;
    return true;
});

$notCallable = [];
$notString = [];
foreach ($registry as $type => $fn) {
    if (!is_string($fn) || !function_exists($fn)) {
        $notCallable[] = $type . ' -> ' . (is_string($fn) ? $fn : gettype($fn));
        continue;
    }
    $before = count($warnings);
    try {
        $node = ['id' => 'parity_' . $type, 'type' => $type, 'props' => [], 'style' => [], 'children' => [], 'meta' => []];
        $out = $fn([], [], [], '', $node, ['is_editor_preview' => true]);
    } catch (Throwable $e) {
        $notString[] = $type . ' threw ' . get_class($e) . ': ' . $e->getMessage();
        restore_error_handler();
        set_error_handler(function (int $severity, string $message, string $file, int $line) use (&$warnings): bool {
            $warnings[] = $message . ' @ ' . basename($file) . ':' . $line;
            return true;
        });
        continue;
    }
    if (!is_string($out)) {
        $notString[] = $type . ' returned ' . gettype($out);
    }
    if (count($warnings) > $before) {
        $notString[] = $type . ' emitted: ' . implode(' | ', array_slice($warnings, $before));
        $warnings = array_slice($warnings, 0, $before);
    }
}
prAssert(
    'every registered renderer names an existing function',
    $notCallable === [],
    $notCallable === [] ? count($registry) . ' renderers resolved' : implode('; ', $notCallable)
);
prAssert(
    'every registered renderer is callable with empty props, returns a string, and stays quiet',
    $notString === [],
    $notString === [] ? 'no throws, no warnings' : implode('; ', $notString)
);

// ── (c) The composed document pipeline still produces HTML ───────────────
// One representative widget from each family, nested the way an author nests
// them, rendered through the single server entry point.
$families = [
    'heading', 'text', 'image', 'button', 'divider', 'spacer',
    'icon_box', 'tabs', 'accordion', 'testimonial', 'slideshow',
    'gallery', 'table', 'alert', 'anchor', 'posts_grid', 'entity_list',
    'pricing_table', 'countdown', 'star_rating', 'call_to_action',
    'flip_box', 'image_box', 'logo_grid', 'blockquote', 'toggle',
    'search_box', 'nav_menu', 'recent_posts', 'social_links',
    'contact_info', 'categories', 'tag_cloud', 'archives',
    'opening_hours', 'badge', 'stat_card', 'contact_card',
    'breadcrumbs', 'code_block', 'audio', 'html_embed', 'ai_block',
];

$children = [];
foreach ($families as $i => $family) {
    if (!isset($registry[$family])) {
        continue;
    }
    $children[] = [
        'id'    => 'parity_' . $family,
        'type'  => $family,
        'props' => [],
        'style' => [],
        'meta'  => [],
        'children' => [],
    ];
}

$document = [
    'schema_version' => 1,
    'document' => [
        'id'       => 'doc_root',
        'type'     => 'document',
        'props'    => [],
        'style'    => [],
        'meta'     => [],
        'children' => [[
            'id'       => 'parity_section',
            'type'     => 'section',
            'props'    => [],
            'style'    => [],
            'meta'     => [],
            'children' => [[
                'id'       => 'parity_container',
                'type'     => 'container',
                'props'    => [],
                'style'    => [],
                'meta'     => [],
                'children' => $children,
            ]],
        ]],
    ],
];

$renderWarningsBefore = count($warnings);
try {
    $html = cmsBuilderRenderDocument($document, ['is_editor_preview' => true]);
} catch (Throwable $e) {
    $html = 'THREW ' . get_class($e) . ': ' . $e->getMessage();
}
// The handler stays installed through the composed render: a warning raised by
// the pipeline itself is a defect, not just a warning raised by a bare renderer.
$renderWarnings = array_slice($warnings, $renderWarningsBefore);
restore_error_handler();

prAssert(
    'the document pipeline renders a composed document to HTML',
    is_string($html) && str_contains($html, 'cms-builder-document'),
    is_string($html) ? strlen($html) . ' bytes' : gettype($html)
);
prAssert(
    'the composed document render raises no PHP warning',
    $renderWarnings === [],
    $renderWarnings === [] ? 'quiet' : implode(' | ', $renderWarnings)
);
prAssert(
    'the composed render carries the node-id marker the editor keys on',
    str_contains($html, 'data-node-id'),
    'data-node-id attribute present or absent'
);

// ── (d) The node-ID join contract ────────────────────────────────────────
// A canvas-vs-public geometry harness joins on data-node-id, so the marker's
// reliability is a PREREQUISITE for that harness, not a detail (chair consult,
// 2026-10-10). The measured contract is:
//   sound      — every emitted marker belongs to a declared node; nothing invented;
//   unique     — no node is emitted twice;
//   complete   — every node that renders is marked, the root included (the editor
//                marks the root, so public output must too or the root is the one
//                node with no join key);
//   explicable — the only absentees are nodes the author deliberately excluded
//                from rendering, which is correct, not drift.
$identityDocument = [
    'schema_version' => 1,
    'document' => [
        'id' => 'id_root', 'type' => 'document', 'props' => [], 'style' => [], 'meta' => [],
        'children' => [[
            'id' => 'id_section', 'type' => 'section', 'props' => [], 'style' => [], 'meta' => [],
            'children' => [
                ['id' => 'id_visible', 'type' => 'text', 'props' => [], 'style' => [], 'meta' => [], 'children' => []],
                ['id' => 'id_hidden', 'type' => 'text', 'props' => ['visibility' => 'hidden'], 'style' => [], 'meta' => [], 'children' => []],
                ['id' => 'id_none', 'type' => 'text', 'props' => [], 'style' => ['display' => 'none'], 'meta' => [], 'children' => []],
            ],
        ]],
    ],
];
$identityHtml = cmsBuilderRenderDocument($identityDocument, ['is_editor_preview' => false]);
// The rendered bundle embeds responsive CSS whose SELECTORS are written as
// [data-node-id="…"], so counting markers over the raw string sees phantom
// nodes. Strip style blocks first: only the markup half is the join key.
$identityMarkup = (string) preg_replace('!<style\b[^>]*>.*?</style>!is', '', $identityHtml);
preg_match_all('/data-node-id="([^"]*)"/', $identityMarkup, $markerHits);
$emittedIds = array_values(array_filter($markerHits[1], static fn(string $id): bool => $id !== ''));
$declaredIds = ['id_root', 'id_section', 'id_visible', 'id_hidden', 'id_none'];

prAssert(
    'the document root carries a join key',
    in_array('id_root', $emittedIds, true),
    'emitted: ' . implode(', ', $emittedIds)
);
prAssert(
    'every emitted marker belongs to a declared node',
    array_diff($emittedIds, $declaredIds) === [],
    'invented: ' . implode(', ', array_diff($emittedIds, $declaredIds))
);
prAssert(
    'no node is emitted more than once',
    count($emittedIds) === count(array_unique($emittedIds)),
    'duplicates: ' . implode(', ', array_diff_assoc($emittedIds, array_unique($emittedIds)))
);
prAssert(
    'every node that renders is marked',
    in_array('id_visible', $emittedIds, true) && in_array('id_section', $emittedIds, true)
);
prAssert(
    'the only unmarked nodes are the deliberately excluded ones',
    array_values(array_diff($declaredIds, $emittedIds)) === ['id_hidden', 'id_none'],
    'absent: ' . implode(', ', array_diff($declaredIds, $emittedIds))
);

// ── (e) Inline-only elements must hold inline content only ───────────────
// The inline editor saves block-wrapped content: TinyMCE emits `<p>…</p>`. A
// heading or button label dropped in verbatim produced `<h2><p>…</p></h2>`, which
// is invalid — the parser hoists the paragraph out, leaving an EMPTY heading and
// the text as a stray paragraph beside it (reported 2026-10-10: a heading on a
// live page rendered as plain centred text, with no heading for a11y or SEO).
$inlineProbe = ['content' => '<p>First</p><p>Second</p>' . "\n"];
$headingHtml = cmsRenderWidget_heading(
    $inlineProbe + ['level' => 'h2'],
    [],
    ['class' => 'cms-builder-node cms-builder-node--heading'],
    '',
    ['id' => 'probe_heading', 'type' => 'heading'],
    []
);
$buttonHtml = cmsRenderWidget_button(
    $inlineProbe + ['href' => '/x'],
    [],
    ['class' => 'cms-builder-node cms-builder-node--button'],
    '',
    ['id' => 'probe_button', 'type' => 'button'],
    []
);

prAssert(
    'a heading never contains a block element',
    preg_match('/<h[1-6]\b[^>]*>(?:(?!<\/h[1-6]>).)*?<(?:p|div)\b/is', $headingHtml) !== 1
        && !str_contains($headingHtml, '<p>'),
    $headingHtml
);
prAssert(
    'a heading keeps its text (the node is not left empty)',
    preg_match('/<h[1-6]\b[^>]*>\s*\S/', $headingHtml) === 1,
    $headingHtml
);
prAssert(
    'paragraph-wrapped content is flattened, not run together',
    str_contains($headingHtml, 'First Second'),
    $headingHtml
);
prAssert(
    'a button label never contains an escaped block wrapper',
    !str_contains($buttonHtml, '&lt;p&gt;') && !str_contains($buttonHtml, '<p>'),
    $buttonHtml
);

echo "\nBuilder render parity: {$passed} passed, {$failed} failed\n";
echo "Scope: vocabulary + dispatch + registry agreement, renderer callability,\n";
echo "and composed-document rendering. Visual parity is NOT covered here.\n";
exit($failed === 0 ? 0 : 1);
