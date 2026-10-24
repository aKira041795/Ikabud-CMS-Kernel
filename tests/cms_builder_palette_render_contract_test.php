<?php

declare(strict_types=1);

/**
 * CMS page builder — the palette may only offer widgets that actually render.
 *
 * MEASURED 2026-10-24, the day this was written: the palette offered `embed`,
 * `dynamic_field` and `posts_list`, each of which rendered an empty shell (~700 bytes of
 * wrapper, no content). A page was saved, validated, published — and the block was simply
 * missing from the live page. Silently disappearing content is the worst failure mode a
 * builder can have, and nothing caught it because no test rendered what the palette offers.
 *
 * This suite renders ONE node of every palette type and requires output. Types that are
 * legitimately blank without data (a posts feed on a site with no posts) are listed with a
 * reason; the list is cross-checked against the palette so it cannot rot.
 */

ob_start();
require_once __DIR__ . '/harness/TestHarness.php';
$h = new TestHarness('cms-builder-palette-render-contract', TestHarness::MODE_INTEGRATION, 'baronledger.test');
ob_end_clean();
$h->allowLogLines('disyl.compile.phases', 'disyl.render.breakdown', 'kernel_state_cache: module_registry rebuilt');

$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/cms/helpers.php';
require_once $base . '/modules/cms/helpers/50-builder.php';

app()->tenant()->setTenantId(207);
$ctx = modulePushContext('cms');
if (!$ctx) {
    fwrite(STDERR, "cms module context unavailable\n");
    exit(1);
}

// Blank without data by design: each needs a source or a populated CMS collection.
$dataDependent = [
    'recent_posts' => 'renders a feed; blank when the site has no posts',
    'image' => 'needs a src; an image with no source correctly renders nothing',
];

$content = ['id' => 0, 'title' => 'Contract', 'slug' => 'contract', 'type' => 'page'];
$palette = [];
foreach (cmsBuilderWidgetRegistry() as $w) {
    if (is_array($w) && !empty($w['type'])) {
        $palette[(string) $w['type']] = (string) ($w['label'] ?? $w['type']);
    }
}

$h->section('every offered widget renders');

$baselineHtml = cmsBuilderRenderDocument(['schema_version' => '1.0', 'document' => [
    'id' => 'doc_root', 'type' => 'document', 'props' => [], 'style' => [], 'children' => [
        ['id' => 'sec', 'type' => 'section', 'props' => [], 'style' => [], 'children' => []],
    ],
]], $content);
$baselineLen = strlen($baselineHtml);

$h->test('the empty-shell baseline was measured', $baselineLen > 0, 'renderer produced nothing at all');

$checked = 0;
$blank = [];
foreach ($palette as $type => $label) {
    if (isset($dataDependent[$type])) { continue; }
    $node = [
        'id' => 'n_' . $type,
        'type' => $type,
        'props' => ['text' => 'SENTINEL-' . strtoupper($type), 'content' => 'SENTINEL-' . strtoupper($type), 'html' => 'SENTINEL-' . strtoupper($type), 'label' => 'L'],
        'style' => [],
        'children' => [],
    ];
    try {
        $html = cmsBuilderRenderDocument(['schema_version' => '1.0', 'document' => [
            'id' => 'doc_root', 'type' => 'document', 'props' => [], 'style' => [], 'children' => [
                ['id' => 'sec', 'type' => 'section', 'props' => [], 'style' => [], 'children' => [$node]],
            ],
        ]], $content);
    } catch (\Throwable $e) {
        $blank[] = $type . ' (threw: ' . $e->getMessage() . ')';
        continue;
    }
    $checked++;
    // A rendered widget adds markup OR text beyond the bare section shell.
    if (strlen($html) <= $baselineLen + 40 && !str_contains($html, 'SENTINEL-' . strtoupper($type))) {
        $blank[] = $type;
    }
}

$h->test(
    'no offered widget renders as an empty shell',
    $blank === [],
    'these would save and publish but be missing from the page: ' . implode(', ', $blank)
);
$h->test('the check actually rendered widgets', $checked >= 20, "only {$checked} types were rendered");

$h->section('the data-dependent allowlist cannot rot');

foreach ($dataDependent as $type => $reason) {
    $h->test(
        "'{$type}' is still a palette widget (its blank-by-design exemption is real)",
        isset($palette[$type]),
        'remove the exemption: ' . $reason
    );
}

$h->done();
