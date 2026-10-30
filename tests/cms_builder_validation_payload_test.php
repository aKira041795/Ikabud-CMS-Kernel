<?php

/**
 * A builder save failure must say WHICH node is rejected, and a failed new page must
 * not be reported as created.
 *
 * THE DEFECT THIS GUARDS (reported 2026-10-30): "saving an edit with page builder:
 * validation failed" on /cms/admin/react-builder/23. The API answered
 *
 *     422 {"ok":false,"error":"validation_failed","issues":[...]}
 *
 * and the builder rendered `data.error` alone, so the operator saw a code with no
 * remedy. Worse, the NEW-page path `console.warn`ed a failed document save and still
 * showed "Page created" - a validation failure dressed as a success.
 *
 * The assertions below are OUTCOME-level: they ask whether a caller can act on the
 * response, not whether a particular string was written.
 *
 * Run: php tests/cms_builder_validation_payload_test.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);

ob_start();
require $root . '/bootstrap.php';
require_once BASE_PATH . '/src/helpers/module-manager.php';
require_once BASE_PATH . '/modules/cms/helpers.php';
ob_end_clean();

$passed = 0;
$failed = 0;
function bvAssert(string $label, bool $condition, string $detail = ''): void
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

// ── A document the validator rejects, the way the builder can produce one ──────
$badDocument = [
    'type' => 'document',
    'props' => [],
    'style' => [],
    'children' => [[
        'type' => 'section',
        'props' => [],
        'style' => [],
        'children' => [[
            'type' => 'column',
            'props' => [],
            'style' => [],
            'children' => [[
                // A column inside a column: not a legal child of `column` in any theme
                // that governs it, and never a legal child of `row` twice over.
                'type' => 'column',
                'props' => [],
                'style' => [],
                'children' => [],
            ]],
        ]],
    ]],
];

$validation = cmsBuilderValidateDocument($badDocument);
bvAssert('the sample document is rejected', empty($validation['ok']), json_encode($validation['issues'] ?? []));

$payload = cmsBuilderValidationFailure($validation);
bvAssert(
    'the failure payload keeps the machine code',
    ($payload['error'] ?? '') === 'validation_failed',
    json_encode($payload['error'] ?? null)
);
bvAssert(
    'the failure payload carries a readable message',
    is_string($payload['message'] ?? null) && trim((string)$payload['message']) !== '',
    json_encode($payload['message'] ?? null)
);
bvAssert(
    'the message names the rejected path, not just the code',
    str_contains((string)($payload['message'] ?? ''), 'children['),
    (string)($payload['message'] ?? '')
);
bvAssert(
    'the message quotes the rule and the offending type',
    str_contains((string)($payload['message'] ?? ''), 'not allowed inside')
        || str_contains((string)($payload['message'] ?? ''), 'requires one of these parents'),
    (string)($payload['message'] ?? '')
);
bvAssert(
    'every issue travels with the payload',
    ($payload['issues'] ?? []) === ($validation['issues'] ?? [])
        && count($payload['issues'] ?? []) > 0,
    json_encode($payload['issues'] ?? [])
);

// The count of remaining problems is part of the remedy: fixing one node must not
// look like the end of the list. Two separate branches, each with the same illegal
// column-inside-column, so the validator has to report both.
$nestedColumnSection = static fn (): array => [
    'type' => 'section',
    'props' => [],
    'style' => [],
    'children' => [[
        'type' => 'column',
        'props' => [],
        'style' => [],
        'children' => [['type' => 'column', 'props' => [], 'style' => [], 'children' => []]],
    ]],
];
$twoIssues = [
    'type' => 'document',
    'props' => [],
    'style' => [],
    'children' => [$nestedColumnSection(), $nestedColumnSection()],
];
$twoPayload = cmsBuilderValidationFailure(cmsBuilderValidateDocument($twoIssues));
bvAssert(
    'a second issue is acknowledged in the message',
    count($twoPayload['issues'] ?? []) > 1
        && str_contains((string)($twoPayload['message'] ?? ''), 'more issue'),
    (string)($twoPayload['message'] ?? '') . ' :: ' . count($twoPayload['issues'] ?? []) . ' issue(s)'
);

// A valid document must not be dressed as a failure.
$goodDocument = [
    'type' => 'document',
    'props' => [],
    'style' => [],
    'children' => [[
        'type' => 'section',
        'props' => [],
        'style' => [],
        'children' => [[
            'type' => 'heading',
            'props' => ['level' => 'h2', 'text' => 'Hello'],
            'style' => [],
            'children' => [],
        ]],
    ]],
];
$goodValidation = cmsBuilderValidateDocument($goodDocument);
bvAssert('a valid document still validates', !empty($goodValidation['ok']), json_encode($goodValidation['issues'] ?? []));

// ── legacy root-level blocks are wrapped, not refused ────────────────────────
// Measured on content 23 (theme entity-commerce-poc): `spacer` and `divider` sat directly
// on `document`, the theme allows only sections/containers/rows/columns/hero there, and
// every save answered 422 - the page could not be edited at all.
$themeRules = ['document' => ['allowed_children' => ['section', 'container', 'layout_container', 'row', 'column', 'hero']]];
$legacy = [
    'type' => 'document',
    'props' => [],
    'style' => [],
    'children' => [
        ['type' => 'section', 'id' => 's1', 'props' => [], 'style' => [], 'children' => []],
        ['type' => 'spacer', 'id' => 'sp1', 'props' => ['height' => 40], 'style' => [], 'children' => []],
        ['type' => 'heading', 'props' => ['text' => 'Hi'], 'style' => [], 'children' => []],
        ['type' => 'divider', 'id' => 'dv1', 'props' => [], 'style' => [], 'children' => []],
    ],
];
$repaired = cmsBuilderRepairTopLevelNodes($legacy, $themeRules);
$repairedChildren = $repaired['children'] ?? [];
bvAssert(
    'a legacy root-level spacer is wrapped instead of blocking the save',
    count($repairedChildren) === 4
        && ($repairedChildren[1]['type'] ?? '') === 'section'
        && ($repairedChildren[1]['children'][0]['type'] ?? '') === 'spacer',
    json_encode(array_map(static fn($c) => $c['type'] ?? '?', $repairedChildren))
);
bvAssert(
    'the wrapped block keeps its own content',
    ($repairedChildren[1]['children'][0]['props']['height'] ?? null) === 40,
    json_encode($repairedChildren[1] ?? null)
);
bvAssert(
    'order is preserved and nothing is deleted',
    array_map(static fn($c) => $c['type'] ?? '?', $repairedChildren) === ['section', 'section', 'section', 'section']
        && ($repairedChildren[2]['children'][0]['type'] ?? '') === 'heading'
        && ($repairedChildren[3]['children'][0]['type'] ?? '') === 'divider',
    json_encode(array_map(static fn($c) => $c['type'] ?? '?', $repairedChildren))
);
bvAssert(
    'an allowed root block is left exactly as it was',
    ($repairedChildren[0] ?? []) === $legacy['children'][0],
    json_encode($repairedChildren[0] ?? null)
);
bvAssert(
    'the wrapper gets a deterministic id, never a reused one',
    ($repairedChildren[1]['id'] ?? '') === 'wrap-sp1'
        && ($repairedChildren[2]['id'] ?? '') === 'wrap-2'
        && ($repairedChildren[3]['id'] ?? '') === 'wrap-dv1',
    json_encode([$repairedChildren[1]['id'] ?? null, $repairedChildren[2]['id'] ?? null, $repairedChildren[3]['id'] ?? null])
);
$permissive = cmsBuilderRepairTopLevelNodes($legacy, ['document' => ['allowed_children' => ['section', 'spacer', 'heading', 'divider']]]);
bvAssert(
    'a theme that permits the node is not restructured',
    $permissive === $legacy,
    'the repair must never touch a document the theme already allows'
);
bvAssert(
    'a theme that governs nothing is not restructured',
    cmsBuilderRepairTopLevelNodes($legacy, []) === $legacy,
    'a theme without root rules must keep its documents byte-identical'
);

// ── wiring ────────────────────────────────────────────────────────────────────
$apiSource = (string)@file_get_contents(BASE_PATH . '/modules/cms/handlers/20-api-builder.php');
$uiSource = (string)@file_get_contents(BASE_PATH . '/modules/cms/builder-ui/src/PageBuilder.tsx');
$toolPath = BASE_PATH . '/tools/cms-builder-validate.php';

bvAssert(
    'every builder 422 goes through the shared failure payload',
    substr_count($apiSource, 'cmsBuilderValidationFailure(') >= 3
        && !str_contains($apiSource, "echo json_encode(['ok' => false, 'error' => 'validation_failed', 'issues' => \$validation['issues']]);"),
    'an emitter still answers with a bare validation_failed code'
);
bvAssert(
    'publish carries the readable message too',
    str_contains($apiSource, "'error' => 'Document validation failed',") && str_contains($apiSource, "'message' => \$failure['message'],"),
    'publish would still report a code with no remedy'
);
bvAssert(
    'the builder shows the message instead of the code',
    str_contains($uiSource, 'data.message || data.error'),
    'the operator would see "validation_failed" again'
);
bvAssert(
    'the builder logs the full issue list for support',
    str_contains($uiSource, "console.warn('Builder validation issues', issues)"),
    'the per-node paths would be lost to whoever has to fix the document'
);
bvAssert(
    'a new page whose document failed is not reported as created',
    str_contains($uiSource, 'Page created, but its content was NOT saved')
        && !str_contains($uiSource, "console.warn('Builder document save returned:'"),
    'a validation failure would still be dressed as a success'
);
bvAssert(
    'the offline diagnostic exists for a document that lives only on the server',
    is_file($toolPath) && str_contains((string)@file_get_contents($toolPath), 'cmsBuilderValidateDocument'),
    'nothing can name the rejected node for a live tenant'
);
bvAssert(
    'every write path repairs legacy root-level blocks before validating',
    substr_count($apiSource, 'cmsBuilderRepairTopLevelNodes(') >= 4,
    'save, autosave, publish and preview must all survive a pre-theme-rule document'
);

echo "\n" . str_repeat('=', 38) . "\n";
echo "  RESULTS: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
