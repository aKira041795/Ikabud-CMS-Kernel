<?php
/**
 * Functional coverage for ARK's provider-side sidebar targeting gate.
 *
 * Run: php tests/ark_sidebar_targeting_test.php
 * Falsify against another provider: ARK_PROVIDER_PATH=/path/to/provider.php php tests/ark_sidebar_targeting_test.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/bootstrap.php';
require_once $root . '/src/helpers/module-manager.php';
require_once $root . '/modules/cms/helpers.php';

$providerPath = getenv('ARK_PROVIDER_PATH') ?: $root . '/storage/cms-themes/ark/src/ArkCustomizerProvider.php';
require_once $providerPath;

use Ikabud\Kernel\Contracts\ThemeCustomizationScope;
use Ikabud\Kernel\Contracts\ThemeRenderContext;
use Ikabud\Themes\Ark\ArkCustomizerProvider;

$passed = 0;
$failed = 0;

function sidebarTargetingAssert(bool $condition, string $label, string $detail = ''): void
{
    global $passed, $failed;

    if ($condition) {
        ++$passed;
        echo "  PASS: {$label}\n";
        return;
    }

    ++$failed;
    echo "  FAIL: {$label}" . ($detail !== '' ? " ({$detail})" : '') . "\n";
}

function sidebarTargetingContext(array $sidebar, string $kind): ThemeRenderContext
{
    return new ThemeRenderContext(
        theme: 'ark',
        scope: new ThemeCustomizationScope(themeSlug: 'ark'),
        settings: ['colors' => [], 'sidebar' => $sidebar],
        tokens: [],
        site: ['title' => "Li'l Juanita", 'tagline' => 'the kitty', 'url' => 'http://cmsnew.test'],
        navigation: [],
        entityContext: ['kind' => $kind],
        slotContributions: [],
    );
}

$provider = new ArkCustomizerProvider();

$mustHideSettings = [
    'enabled' => 1,
    'scope_mode' => 'template',
    'template_scope' => 'page',
    'template_rules' => [],
];
$hidden = $provider->transformContext(sidebarTargetingContext($mustHideSettings, 'search'));
sidebarTargetingAssert(
    (int)($hidden->settings['sidebar']['enabled'] ?? 1) === 0,
    'template mode hides the sidebar when the route does not match',
    'enabled=' . var_export($hidden->settings['sidebar']['enabled'] ?? null, true)
);

$kept = $provider->transformContext(sidebarTargetingContext($mustHideSettings, 'page'));
sidebarTargetingAssert(
    $kept->settings['sidebar'] === $mustHideSettings,
    'template mode keeps the sidebar unchanged when the route matches'
);

$generalSettings = [
    'enabled' => 1,
    'scope_mode' => 'general',
    'template_scope' => 'page',
    'template_rules' => [],
];
$general = $provider->transformContext(sidebarTargetingContext($generalSettings, 'search'));
sidebarTargetingAssert(
    $general->settings['sidebar'] === $generalSettings,
    'general mode is a no-op even when the template target differs'
);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
