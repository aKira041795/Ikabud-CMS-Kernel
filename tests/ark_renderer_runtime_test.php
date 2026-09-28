<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';

spl_autoload_register(static function (string $class) use ($root): void {
    $prefix = 'Ikabud\\Kernel\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $path = $root . '/kernel/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

use Ikabud\Kernel\DiSyL\ComponentRegistry;
use Ikabud\Kernel\DiSyL\TemplateEngine;
use Ikabud\Kernel\Services\ArkRendererResolver;

$passed = 0;
$failed = 0;
$check = static function (bool $condition, string $label) use (&$passed, &$failed): void {
    if ($condition) {
        ++$passed;
        echo "  \xE2\x9C\x93 {$label}\n";
        return;
    }
    ++$failed;
    echo "  \xE2\x9C\x97 {$label}\n";
};

$themesPath = $root . '/storage/cms-themes';
$fixtureSlug = 'ark-renderer-fixture';
$cachePath = sys_get_temp_dir() . '/ark_renderer_runtime_' . getmypid();
$engine = new TemplateEngine($root . '/templates', $cachePath, false);
$resolver = new ArkRendererResolver($themesPath, $engine);

$list = $resolver->resolve('entity.list.post', $fixtureSlug);
$detail = $resolver->resolve('entity.detail.post', $fixtureSlug);
$check($list !== null && $list['renderer'] === 'article-grid' && $list['target'] === 'public/article-grid.disyl', 'list view resolves article-grid template');
$check($detail !== null && $detail['renderer'] === 'article-page' && $detail['target'] === 'public/article-page.disyl', 'detail view resolves article-page template');
$check($list !== null && $list['type'] === 'template' && is_file((string)$list['path']), 'resolved template exists');
$check($resolver->resolve('entity.list.product', $fixtureSlug) === null, 'unknown view is a miss');
$check(
    $resolver->resolve('entity.list.post', 'missing-theme') === null
    && $resolver->resolve('entity.list.post') === null,
    'missing theme or explicit slug is a miss'
);

$tmpThemes = sys_get_temp_dir() . '/ark_renderer_themes_' . getmypid();
@mkdir($tmpThemes . '/missing-registry', 0777, true);
@mkdir($tmpThemes . '/malformed', 0777, true);
file_put_contents($tmpThemes . '/malformed/renderer-registry.json', '{not json');
$tmpResolver = new ArkRendererResolver($tmpThemes, $engine);
$check($tmpResolver->resolve('entity.list.post', 'missing-registry') === null, 'missing registry is a miss');
$check($tmpResolver->resolve('entity.list.post', 'malformed') === null, 'malformed registry is a miss');
@mkdir($tmpThemes . '/malformed-mapping', 0777, true);
file_put_contents($tmpThemes . '/malformed-mapping/renderer-registry.json', json_encode([
    'renderers' => ['entity.list.post' => ['template' => ['not-a-path']]],
]));
$check($tmpResolver->resolve('entity.list.post', 'malformed-mapping') === null, 'malformed mapping is a miss');

@mkdir($tmpThemes . '/aliases/public/blocks', 0777, true);
file_put_contents($tmpThemes . '/aliases/public/blocks/article-grid.block.disyl', 'grid');
file_put_contents($tmpThemes . '/aliases/public/article-page.disyl', 'page');
file_put_contents($tmpThemes . '/aliases/renderer-registry.json', json_encode([
    'renderers' => [
        'entity.list.post' => ['template' => 'ark.blocks.article-grid'],
        'entity.detail.post' => ['template' => 'ark.layouts.article-page'],
    ],
]));
$aliasList = $tmpResolver->resolve('entity.list.post', 'aliases');
$aliasDetail = $tmpResolver->resolve('entity.detail.post', 'aliases');
$check($aliasList !== null && $aliasList['target'] === 'public/blocks/article-grid.block.disyl', 'ARK block alias resolves by validator path rule');
$check($aliasDetail !== null && $aliasDetail['target'] === 'public/article-page.disyl', 'ARK layout alias resolves by validator path rule');

@mkdir($tmpThemes . '/component', 0777, true);
file_put_contents($tmpThemes . '/component/renderer-registry.json', json_encode([
    'renderers' => [
        'entity.list.post' => ['renders_as_component' => 'ikb_ark_fixture', 'controls' => ['tone'], 'context_keys' => ['label']],
        '*' => ['renders_as_component' => 'ikb_card', 'controls' => ['tone'], 'context_keys' => ['label']],
    ],
], JSON_PRETTY_PRINT));
$check($tmpResolver->resolve('entity.list.post', 'component') === null, 'unregistered component is a miss');
ComponentRegistry::register('ikb_ark_fixture', ['description' => 'runtime fixture']);
$engine->registerComponent('ikb_ark_fixture', static function (array $attrs, string $children, array $context): string {
    return '<strong data-ark-component="fixture">' . htmlspecialchars((string)($context['label'] ?? ''), ENT_QUOTES, 'UTF-8') . '</strong>';
});
$component = $tmpResolver->resolve('entity.list.post', 'component');
$check($component !== null && $component['type'] === 'component' && $component['target'] === 'ikb_ark_fixture', 'registered component resolves');
$check($tmpResolver->resolve('entity.detail.post', 'component') === null, 'wildcard renderer is never selected');

$html = $resolver->render('entity.list.post', ['posts' => [['title' => 'First'], ['title' => 'Second']]], $fixtureSlug);
$check(is_string($html) && str_contains($html, 'data-ark-renderer="article-grid"') && str_contains($html, 'First'), 'opt-in template render produces HTML');
$componentHtml = $tmpResolver->render('entity.list.post', ['label' => 'Selected'], 'component');
$check($componentHtml === '<strong data-ark-component="fixture">Selected</strong>', 'opt-in component render produces HTML');
$check($resolver->render('entity.list.product', [], $fixtureSlug) === null, 'opt-in render miss is null');

$componentRendererSource = (string)file_get_contents($root . '/kernel/DiSyL/Component/ComponentRenderer.php');
$defaultRendererSource = (string)file_get_contents($root . '/kernel/EntityContext/DefaultEntityRenderer.php');
$check(str_contains($componentRendererSource, "'ikb_entity_list' => \$this->renderEntityListViaService")
    && str_contains($componentRendererSource, "'ikb_entity_detail' => \$this->renderEntityDetailViaService")
    && !str_contains($componentRendererSource, 'ArkRendererResolver')
    && !str_contains($defaultRendererSource, 'ArkRendererResolver'), 'default entity dispatch remains independent of opt-in ARK selection');

$remove = static function (string $path) use (&$remove): void {
    if (!is_dir($path)) {
        @unlink($path);
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            $remove($path . '/' . $entry);
        }
    }
    @rmdir($path);
};
$remove($tmpThemes);
$remove($cachePath);

echo "\nARK renderer runtime: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
