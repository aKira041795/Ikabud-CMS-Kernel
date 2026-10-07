<?php
/**
 * Canonical entity-presentation bridge into {ikb_entity_list}.
 * Run: php tests/entity_presentation_renderer_bridge_test.php
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../modules/cms/helpers.php';

use Ikabud\Kernel\EntityContext\EntityViewResolver;

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

echo "\n=== ENTITY PRESENTATION RENDERER BRIDGE ===\n";

$resolver = EntityViewResolver::getInstance();
$resolver->reset();
$resolver->registerView('cms', 'card_grid', [
    'fields' => ['title', 'excerpt'],
    'role_fields' => ['title' => 'title', 'subtitle' => 'excerpt'],
]);

try {
    app()->capabilities()->register(
        'entity.list.cms',
        'entity-presentation-bridge-test',
        static fn(array $args): array => [
            'rows' => [[
                'title' => 'Bridge proof',
                'excerpt' => 'This deliberately long excerpt proves that the canonical per-type presentation reaches the renderer.',
            ]],
            'total' => 1,
        ],
        1000,
        ['first']
    );
} catch (Throwable $e) {
    // A repeated in-process run can retain the provider; its deterministic result is fine.
}

$global = cmsEntityPresentationSettingsDefaults();
$global['entity_list_card_density'] = 'compact';
$global['entity_list_excerpt_length'] = '80';
$withPostOverride = $global;
$withPostOverride['by_type'] = [
    'post' => [
        'entity_list_card_density' => 'airy',
        'entity_list_excerpt_length' => '40',
    ],
];

$template = '{ikb_entity_list source="cms.post" view="card_grid" /}';
$engine = app()->templates();
$compact = (string)$engine->renderString($template, ['entity_presentation_settings' => $global]);
$airy = (string)$engine->renderString($template, ['entity_presentation_settings' => $withPostOverride]);
$reverted = (string)$engine->renderString($template, ['entity_presentation_settings' => $global]);

$check($compact !== $airy, 'same list differs when only entity_presentation_settings differs');
$check(str_contains($airy, 'ikb-entity-list--density-airy'), 'post by_type density is emitted on the list wrapper');
$check(str_contains($compact, 'ikb-entity-list--density-compact'), 'global density is emitted without a post override');
$check($reverted === $compact, 'falsification: removing the override restores byte-identical markup');
$check(str_contains($airy, 'ikb-entity-card__body') && str_contains($airy, 'ikb-entity-card__title') && str_contains($airy, 'ikb-entity-card__excerpt'), 'card markup exposes the hooks targeted by presentation CSS');

$css = cmsRenderEntityPresentationCss(cmsEntityPresentationResolveForType($withPostOverride, 'post'));
$check(str_contains($css, '.ikb-entity-list--grid') && str_contains($css, '.ikb-entity-card__body'), 'generated presentation CSS targets emitted renderer markup');

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
