<?php
/**
 * The canonical page-builder render-parity corpus.
 *
 * ONE definition, two consumers, so they cannot drift apart:
 *   - tools/builder-parity-render.php       (static file:// page, no DB)
 *   - tools/cms-ensure-builder-page.php     (a live page on a real tenant)
 *
 * Deliberately small but layout-VARIED. A parity gate earns its place by
 * catching the failure classes that actually occurred, not by enumerating
 * widgets:
 *   row 33/67    — flex children must sit side by side, not stack (the spurious
 *                  max-width regression);
 *   full-bleed   — the band must reach the content edge, and the release must
 *                  cover a section that is ITSELF full-bleed, not only one that
 *                  contains a full-bleed descendant;
 *   nesting      — section > container > row > column, so a wrapper that swallows
 *                  layout participation becomes visible;
 *   leaf variety — so a node rendered at the wrong size is attributed to a type.
 *
 * Note: the fixture deliberately does NOT carry a `disyl` contract key. The
 * public render path (cmsPageBuilderRenderedHtml) prefers a document's `disyl`
 * contract and renders it through cmsRenderDiSyLDocument() — a DIFFERENT
 * renderer from the cmsRenderWidget_* registry this gate exists to compare.
 * Omitting it keeps both sides on the widget renderers, which is the comparison
 * that matters here.
 */
declare(strict_types=1);

function builderParityFixtureDocument(): array
{
    $node = static function (string $id, string $type, array $props = [], array $style = [], array $children = []): array {
        return ['id' => $id, 'type' => $type, 'props' => $props, 'style' => $style, 'children' => $children, 'meta' => []];
    };

    return [
        'schema_version' => 1,
        'document' => $node('doc_root', 'document', [], [], [
            $node('p_section', 'section', [], [], [
                $node('p_container', 'container', [], [], [
                    $node('p_intro', 'heading', ['text' => 'Parity intro', 'level' => 'h2']),
                    $node('p_row', 'row', [], [], [
                        $node('p_col_left', 'column', [], ['flexBasis' => '33%'], [
                            $node('p_text_left', 'text', ['content' => 'Left column body copy.']),
                        ]),
                        $node('p_col_right', 'column', [], ['flexBasis' => '67%'], [
                            $node('p_text_right', 'text', ['content' => 'Right column body copy.']),
                            $node('p_button', 'button', ['text' => 'Parity button', 'href' => '/']),
                        ]),
                    ]),
                    $node('p_divider', 'divider'),
                    $node('p_spacer', 'spacer'),
                    $node('p_icon_box', 'icon_box', ['title' => 'Parity icon box', 'description' => 'Body copy.']),
                ]),
            ]),
            $node('p_fullbleed', 'section', ['fullWidth' => true], [], [
                $node('p_fullbleed_text', 'text', ['content' => 'Full-bleed band.']),
            ]),
        ]),
    ];
}

/** Every node id the corpus declares, in document order. */
function builderParityFixtureIds(array $document): array
{
    $ids = [];
    $walk = static function (array $n) use (&$walk, &$ids): void {
        $ids[] = (string)($n['id'] ?? '');
        foreach ($n['children'] ?? [] as $child) {
            if (is_array($child)) {
                $walk($child);
            }
        }
    };
    $walk($document['document'] ?? []);
    return array_values(array_filter($ids, static fn(string $id): bool => $id !== ''));
}
