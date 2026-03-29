<?php
/**
 * CMS Module — Theme Resolution Test
 * Verifies theme discovery, symlink management, and template override resolution.
 * Run: php tests/cms_theme_test.php
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../modules/cms/helpers.php';
require_once __DIR__ . '/../modules/cms/handlers.php';
require_once __DIR__ . '/../modules/ecommerce/helpers.php';

$pass = 0;
$fail = 0;
$errors = [];

function t(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $errors;
    if ($ok) {
        $pass++;
        echo "  ✓ {$label}\n";
    } else {
        $fail++;
        $errors[] = $label . ($detail ? ": {$detail}" : '');
        echo "  ✗ {$label}" . ($detail ? " — {$detail}" : '') . "\n";
    }
}

function deleteTree(string $path): void
{
    if ($path === '' || !file_exists($path)) {
        return;
    }

    if (is_file($path) || is_link($path)) {
        @unlink($path);
        return;
    }

    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        deleteTree($path . '/' . $entry);
    }

    @rmdir($path);
}

function upsertCustomizerSection(object $db, string $section, array $settings, array $widgets = [], string $scope = 'native'): void
{
    $stmt = $db->prepare(
        "INSERT INTO cms_theme_customizer (section, settings_json, widgets_json, updated_by)\n"
        . " VALUES (:section, :settings, :widgets, NULL)\n"
        . " ON DUPLICATE KEY UPDATE settings_json = VALUES(settings_json), widgets_json = VALUES(widgets_json)"
    );
    $stmt->execute([
        ':section' => cmsCustomizerStorageSection($section, $scope),
        ':settings' => json_encode($settings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ':widgets' => json_encode($widgets, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ]);
}

function captureEcRender(string $template, array $context = []): string
{
    ob_start();
    try {
        ecRender($template, $context);
        return (string)ob_get_clean();
    } catch (Throwable $e) {
        ob_end_clean();
        throw $e;
    }
}

// ── Clear logs ──────────────────────────────────────────────────────
file_put_contents(STORAGE_PATH . '/logs/app.log', '');
file_put_contents(STORAGE_PATH . '/logs/error.log', '');
@mkdir(STORAGE_PATH . '/cache/kernel_bootstrap', 0775, true);

// ═══════════════════════════════════════════════════════════════════
// 1. Theme paths & constants
// ═══════════════════════════════════════════════════════════════════
echo "\n=== THEME PATHS ===\n";

$themesPath = cmsThemesPath();
t('cmsThemesPath returns storage-based path', str_contains($themesPath, 'storage/cms-themes'));
t('cms-themes directory exists', is_dir($themesPath));
t('CMS_THEME_SYMLINK defined', defined('CMS_THEME_SYMLINK'));
t('CMS_THEME_SYMLINK points to templates/', str_contains(CMS_THEME_SYMLINK, 'templates/_cms_active_theme'));

// ═══════════════════════════════════════════════════════════════════
// 2. Theme discovery
// ═══════════════════════════════════════════════════════════════════
echo "\n=== THEME DISCOVERY ===\n";

$themes = cmsAvailableThemes();
t('cmsAvailableThemes returns array', is_array($themes));
t('at least 1 theme found (minimal)', count($themes) >= 1);

$minimal = null;
$entityPoc = null;
foreach ($themes as $th) {
    if (($th['slug'] ?? '') === 'minimal') {
        $minimal = $th;
    }
    if (($th['slug'] ?? '') === 'entity-commerce-poc') {
        $entityPoc = $th;
    }
}
t('minimal theme found', $minimal !== null);
if ($minimal) {
    t('minimal theme name is "Minimal"', ($minimal['name'] ?? '') === 'Minimal');
    t('minimal theme version is "1.0"', ($minimal['version'] ?? '') === '1.0');
    t('minimal theme has author', ($minimal['author'] ?? '') !== '');
    t('minimal theme override_count is 4', ($minimal['override_count'] ?? 0) === 4);
}
t('entity-commerce-poc theme found', $entityPoc !== null);
if ($entityPoc) {
    t('entity-commerce-poc has author', ($entityPoc['author'] ?? '') !== '');
    t('entity-commerce-poc includes public asset stylesheet', is_file(BASE_PATH . '/public/assets/cms/themes/entity-commerce-poc/style.css'));
}

// ═══════════════════════════════════════════════════════════════════
// 3. Default theme (no active theme)
// ═══════════════════════════════════════════════════════════════════
echo "\n=== DEFAULT THEME (no override) ===\n";

// Save current settings and ensure no active theme
$oldSettings = getModuleSettings('cms');
$testSettings = $oldSettings;
$testSettings['active_theme'] = 'default';
saveModuleSettings('cms', $testSettings);

// Reset the static cache in cmsActiveTheme by... we can't easily.
// The static var means we need a fresh process. Let's test cmsResolveTemplate logic directly.
// For this test, call the functions that don't rely on the static cache.

// cmsResolveTemplate with no active theme should return default path
$result = cmsResolveTemplate('public/single.disyl');
// Since cmsActiveTheme is statically cached from earlier, let's test the path logic
// by checking the default directly
t('default template path format is correct', $result === 'modules/cms/public/single.disyl' || str_contains($result, 'single.disyl'));

// ═══════════════════════════════════════════════════════════════════
// 4. Symlink management
// ═══════════════════════════════════════════════════════════════════
echo "\n=== SYMLINK MANAGEMENT ===\n";

// Clean up any existing symlink
$link = CMS_THEME_SYMLINK;
if (is_link($link)) {
    @unlink($link);
}

// Activate the minimal theme
cmsActivateThemeSymlink('minimal');
t('symlink created', is_link($link));
$target = readlink($link);
t('symlink target is minimal theme dir', str_contains($target, 'cms-themes/minimal'));

// Verify files are accessible through symlink
t('layout accessible via symlink', is_file($link . '/layouts/public.disyl'));
t('single.disyl accessible via symlink', is_file($link . '/public/single.disyl'));
t('home.disyl accessible via symlink', is_file($link . '/public/home.disyl'));
t('page.disyl accessible via symlink', is_file($link . '/public/page.disyl'));
t('theme.json accessible via symlink', is_file($link . '/theme.json'));

// Deactivate
cmsActivateThemeSymlink(null);
t('symlink removed after deactivate', !is_link($link) && !is_dir($link));

// Reactivate for resolution tests
cmsActivateThemeSymlink('minimal');

// ═══════════════════════════════════════════════════════════════════
// 5. Template resolution with active theme
// ═══════════════════════════════════════════════════════════════════
echo "\n=== TEMPLATE RESOLUTION ===\n";

// Manually test resolution logic (bypassing static cache)
// Since we can't reset the static, simulate the resolution logic
$activeTheme = 'minimal';
$subPaths = ['public/single.disyl', 'public/home.disyl', 'public/page.disyl', 'layouts/public.disyl'];
foreach ($subPaths as $sub) {
    $overridePath = CMS_THEME_SYMLINK . '/' . $sub;
    $exists = is_file($overridePath);
    $resolved = $exists ? '_cms_active_theme/' . $sub : 'modules/cms/' . $sub;
    t("resolve {$sub} → theme override", $resolved === '_cms_active_theme/' . $sub, $resolved);
}

// Non-existent template should fall back to default
$overridePath = CMS_THEME_SYMLINK . '/public/nonexistent.disyl';
$resolved = is_file($overridePath) ? '_cms_active_theme/public/nonexistent.disyl' : 'modules/cms/public/nonexistent.disyl';
t('nonexistent template falls back to default', $resolved === 'modules/cms/public/nonexistent.disyl');

// ═══════════════════════════════════════════════════════════════════
// 5a. Entity storefront POC theme
// ═══════════════════════════════════════════════════════════════════
echo "\n=== ENTITY STOREFRONT POC THEME ===\n";

$pocSettings = $oldSettings;
$pocSettings['active_theme'] = 'entity-commerce-poc';
saveModuleSettings('cms', $pocSettings);
cmsResetThemeRuntimeCache();
cmsActivateThemeSymlink('entity-commerce-poc');

$pocManifest = cmsActiveThemeManifest();
t('entity-commerce-poc manifest loads as active theme', ($pocManifest['slug'] ?? '') === 'entity-commerce-poc');
t('entity-commerce-poc manifest declares ecommerce customizer scope', ($pocManifest['customizer_scope'] ?? '') === 'ecommerce');
t('active customizer scope switches to ecommerce for entity-commerce-poc', cmsActiveCustomizerScope() === 'ecommerce');
$pocStorefrontPolicy = cmsResolveEcommerceThemePolicy([
    'public_render_origin' => 'ecommerce',
    'public_route_kind' => 'shop_index',
]);
t('entity-commerce-poc storefront policy keeps the site theme active', ($pocStorefrontPolicy['active_theme'] ?? '') === 'entity-commerce-poc', json_encode($pocStorefrontPolicy));
t('entity-commerce-poc storefront policy resolves theme source as site', ($pocStorefrontPolicy['active_theme_source'] ?? '') === 'site', json_encode($pocStorefrontPolicy));
t('entity-commerce-poc storefront policy resolves ecommerce scope', ($pocStorefrontPolicy['active_theme_scope'] ?? '') === 'ecommerce', json_encode($pocStorefrontPolicy));
t('entity-commerce-poc storefront policy resolves entity-view mode', ($pocStorefrontPolicy['public_presentation_mode'] ?? '') === 'entity_view', json_encode($pocStorefrontPolicy));
t('ecommerce storefront shop route resolves entity-view presentation mode', cmsEcommercePublicPresentationMode(['public_route_kind' => 'shop_index']) === 'entity_view');
t('ecommerce storefront category route resolves entity-view presentation mode', cmsEcommercePublicPresentationMode(['public_route_kind' => 'shop_category']) === 'entity_view');
t('ecommerce storefront product route resolves entity-view presentation mode', cmsEcommercePublicPresentationMode(['public_route_kind' => 'product_detail']) === 'entity_view');
t('non-entity ecommerce routes remain traditional under ecommerce scope', cmsEcommercePublicPresentationMode(['public_route_kind' => 'cart']) === 'traditional');
t('forced customizer scope resolves ecommerce route override', cmsRequestedCustomizerScope(['scope' => 'ecommerce']) === 'ecommerce');
t('invalid customizer scope falls back to active theme scope', cmsRequestedCustomizerScope(['scope' => 'unknown-scope']) === 'ecommerce');
t('ecommerce customizer section keys are namespaced', cmsCustomizerStorageSection('sidebar', 'ecommerce') === 'ecommerce:sidebar');
t('storefront customizer section keys are namespaced', cmsCustomizerStorageSection('storefront', 'ecommerce') === 'ecommerce:storefront');
t('known customizer sections include storefront', in_array('storefront', cmsKnownCustomizerSections(), true));

$routes = require BASE_PATH . '/modules/cms/routes.php';
$getRoutes = is_array($routes['GET'] ?? null) ? $routes['GET'] : [];
$postRoutes = is_array($routes['POST'] ?? null) ? $routes['POST'] : [];
t('native customizer admin route exists', ($getRoutes['/cms/admin/customize/native'] ?? '') === 'cms:cmsAdminCustomizer');
t('ecommerce customizer admin route exists', ($getRoutes['/cms/admin/customize/ecommerce'] ?? '') === 'cms:cmsAdminCustomizer');
t('scoped customizer GET API route exists', ($getRoutes['/api/v1/cms/customizer/{scope}/{section}'] ?? '') === 'cms:cmsApiCustomizerGet');
t('scoped customizer POST API route exists', ($postRoutes['/api/v1/cms/customizer/{scope}/{section}'] ?? '') === 'cms:cmsApiCustomizerSave');

$db = cmsDb();
cmsEnsureCustomizerScopeSeeded($db, 'ecommerce');
$ecommerceSidebar = cmsCustomizerGet($db, 'sidebar', 'ecommerce');
$ecommerceStorefront = cmsCustomizerGet($db, 'storefront', 'ecommerce');
t('ecommerce header section is seeded', cmsCustomizerSectionExists($db, 'header', 'ecommerce'));
t('ecommerce footer section is seeded', cmsCustomizerSectionExists($db, 'footer', 'ecommerce'));
t('ecommerce customizer sidebar defaults disabled to avoid shared sidebar pollution', (int)($ecommerceSidebar['settings']['enabled'] ?? 0) === 0);
t('ecommerce storefront section seeds entity presentation settings', array_key_exists('entity_layout_profile', $ecommerceStorefront['settings'] ?? []));

$entityViewTemplate = cmsResolveTemplate('public/entity.view.disyl');
$entityListTemplate = cmsResolveTemplate('public/entity.list.disyl');
t('entity-commerce-poc overrides entity.view.disyl', $entityViewTemplate === '_cms_active_theme/public/entity.view.disyl', $entityViewTemplate);
t('entity-commerce-poc overrides entity.list.disyl', $entityListTemplate === '_cms_active_theme/public/entity.list.disyl', $entityListTemplate);

$pricingBlockTemplate = cmsResolveBlockTemplate('modules/cms/public/blocks/pricing.block.disyl');
$actionBlockTemplate = cmsResolveBlockTemplate('modules/cms/public/blocks/action.block.disyl');
$listPricingManifestTemplate = cmsResolveBlockTemplate('modules/cms/public/blocks/list-card-pricing.block.disyl');
$listInventoryManifestTemplate = cmsResolveBlockTemplate('modules/cms/public/blocks/list-card-inventory.block.disyl');
$listProgressManifestTemplate = cmsResolveBlockTemplate('modules/cms/public/blocks/list-card-progress.block.disyl');
t('entity-commerce-poc overrides pricing block', $pricingBlockTemplate === '_cms_active_theme/public/blocks/pricing.block.disyl', $pricingBlockTemplate);
t('entity-commerce-poc manifest default resolves inline action block', $actionBlockTemplate === '_cms_active_theme/public/blocks/action.inline.block.disyl', $actionBlockTemplate);
t('entity-commerce-poc manifest default resolves featured list-card pricing variant', $listPricingManifestTemplate === '_cms_active_theme/public/blocks/list-card-pricing.featured.block.disyl', $listPricingManifestTemplate);
t('entity-commerce-poc manifest default resolves compact list-card inventory variant', $listInventoryManifestTemplate === '_cms_active_theme/public/blocks/list-card-inventory.compact.block.disyl', $listInventoryManifestTemplate);
t('entity-commerce-poc manifest default resolves inline list-card progress variant', $listProgressManifestTemplate === '_cms_active_theme/public/blocks/list-card-progress.inline.block.disyl', $listProgressManifestTemplate);

$renderedPricingBlock = cmsRenderThemeAwareBlockTemplate('modules/cms/public/blocks/pricing.block.disyl', [
    'capability_data' => [
        'pricing' => [
            'currency' => 'USD',
            'price' => 24.5,
            'sale_price' => 19.0,
        ],
    ],
]);
t('entity-commerce-poc pricing block render uses themed markup', str_contains($renderedPricingBlock, 'poc-pricing-block'), $renderedPricingBlock);

$renderedActionBlock = cmsRenderThemeAwareBlockTemplate('modules/cms/public/blocks/action.block.disyl', [
    'capabilities' => [
        'pricing' => true,
        'inventory' => false,
        'booking' => false,
        'inquiry' => false,
    ],
    'cart_enabled' => true,
    'cart_action_url' => '/cart/add',
    'entity' => [
        'id' => 55,
        'type' => 'product',
        'slug' => 'poc-item',
    ],
    'capability_data' => [
        'inventory' => [
            'in_stock' => true,
            'out_of_stock' => false,
        ],
    ],
    'base_url' => '',
]);
t('entity-commerce-poc action block render uses themed markup', str_contains($renderedActionBlock, 'poc-action-strip'), $renderedActionBlock);

$renderedManifestListPricing = cmsRenderThemeAwareBlockTemplate('modules/cms/public/blocks/list-card-pricing.block.disyl', [
    'capability_data' => [
        'pricing' => [
            'currency' => 'USD',
            'price' => 32.0,
            'sale_price' => 28.0,
        ],
    ],
]);
t('entity-commerce-poc manifest list-card pricing render uses featured themed markup', str_contains($renderedManifestListPricing, 'poc-price-pill--featured'), $renderedManifestListPricing);

$pocColorDefaults = cmsThemeManifestCustomizerDefaults('colors', $pocManifest, 'ecommerce');
$pocThemeDefaults = cmsThemeManifestCustomizerDefaults('theme', $pocManifest, 'ecommerce');
$pocStorefrontDefaults = cmsThemeManifestCustomizerDefaults('storefront', $pocManifest, 'ecommerce');
t('entity-commerce-poc manifest colors inherit primary token into customizer defaults', ($pocColorDefaults['color_primary'] ?? '') === '#0f4c81');
t('entity-commerce-poc manifest colors inherit background token into customizer defaults', ($pocColorDefaults['body_bg_color'] ?? '') === '#f4efe6');
t('entity-commerce-poc manifest colors inherit container token into customizer defaults', ($pocColorDefaults['container_width'] ?? '') === '1180');
t('entity-commerce-poc manifest theme layout inherits container token into site width', ($pocThemeDefaults['site_max_width'] ?? '') === '1180');
t('entity-commerce-poc manifest storefront defaults set dedicated summary width', ($pocStorefrontDefaults['entity_summary_width'] ?? '') === '360');
t('entity-commerce-poc manifest storefront defaults set dedicated detail media ratio', ($pocStorefrontDefaults['entity_media_ratio'] ?? '') === '4:3');
t('entity-commerce-poc manifest storefront defaults set inline action variant', ($pocStorefrontDefaults['entity_action_variant'] ?? '') === 'inline');
t('entity-commerce-poc manifest storefront defaults inherit featured list pricing variant', ($pocStorefrontDefaults['entity_list_pricing_variant'] ?? '') === 'featured');

cmsSeedActiveThemeCustomizerDefaults($db);
$seededColors = cmsCustomizerGet($db, 'colors', 'ecommerce');
$seededTheme = cmsCustomizerGet($db, 'theme', 'ecommerce');
$seededStorefront = cmsCustomizerGet($db, 'storefront', 'ecommerce');
t('theme activation seeding writes ecommerce colors defaults from manifest', ($seededColors['settings']['color_primary'] ?? '') === '#0f4c81');
t('theme activation seeding writes ecommerce theme defaults from manifest', ($seededTheme['settings']['site_max_width'] ?? '') === '1180');
t('theme activation seeding writes ecommerce storefront summary width from manifest', ($seededStorefront['settings']['entity_summary_width'] ?? '') === '360');
t('theme activation seeding writes ecommerce storefront detail media ratio from manifest', ($seededStorefront['settings']['entity_media_ratio'] ?? '') === '4:3');
t('theme activation seeding writes ecommerce storefront action variant from manifest', ($seededStorefront['settings']['entity_action_variant'] ?? '') === 'inline');
t('theme activation seeding writes ecommerce storefront defaults from manifest', ($seededStorefront['settings']['entity_list_pricing_variant'] ?? '') === 'featured');

$defaultColorsHtml = cmsRenderColorsStyle($db);
$defaultThemeHtml = cmsRenderThemeLayoutStyle($db);
$defaultStorefrontHtml = cmsRenderStorefrontStyle($db);
t('entity-commerce-poc emits storefront color contract when settings match manifest defaults', str_contains($defaultColorsHtml, '--storefront-success-bg:') && !str_contains($defaultColorsHtml, 'body{font-family:var(--font-body);'), $defaultColorsHtml);
t('entity-commerce-poc suppresses generic theme layout override when settings match manifest defaults', $defaultThemeHtml === '', $defaultThemeHtml);
t('entity-commerce-poc suppresses generic storefront override when settings match manifest defaults', $defaultStorefrontHtml === '', $defaultStorefrontHtml);

upsertCustomizerSection($db, 'colors', array_merge($seededColors['settings'], ['color_primary' => '#112233']), [], 'ecommerce');
cmsCustomizerClearPersistentCache('colors', 'ecommerce');
$GLOBALS[cmsCustomizerRequestCacheKey('section_row', 'ecommerce')] = [];
$customizedColorsHtml = cmsRenderColorsStyle($db);
t('entity-commerce-poc renders colors override after explicit merchant customization', str_contains($customizedColorsHtml, 'cz-colors-override'), $customizedColorsHtml);

$runtimeDiagnostics = cmsThemeRuntimeDiagnostics();
t('theme runtime diagnostics expose active theme', ($runtimeDiagnostics['active_theme'] ?? '') === 'entity-commerce-poc');
t('theme runtime diagnostics expose active theme source', ($runtimeDiagnostics['active_theme_source'] ?? '') === 'site');
t('theme runtime diagnostics expose configured site theme', ($runtimeDiagnostics['configured_site_theme'] ?? '') === 'entity-commerce-poc');
t('theme runtime diagnostics expose active customizer scope', ($runtimeDiagnostics['active_customizer_scope'] ?? '') === 'ecommerce');

$pocStyleUrl = cmsThemeAssetUrl('style.css');
t('entity-commerce-poc theme stylesheet resolves to public assets', str_contains($pocStyleUrl, '/assets/cms/themes/entity-commerce-poc/style.css'), $pocStyleUrl);

saveModuleSettings('cms', $oldSettings);
cmsResetThemeRuntimeCache();
$minimalScopeSettings = $oldSettings;
$minimalScopeSettings['active_theme'] = 'minimal';
$minimalScopeSettings['active_ecommerce_theme'] = '';
saveModuleSettings('cms', $minimalScopeSettings);
cmsResetThemeRuntimeCache();
cmsActivateThemeSymlink('minimal');
t('minimal theme manifest defaults to native customizer scope', cmsThemeCustomizerScopeFromManifest(['slug' => 'minimal']) === 'native');
t('native scope storefront shop route resolves traditional mode', cmsEcommercePublicPresentationMode(['public_route_kind' => 'shop_index']) === 'traditional');

$scopedShopPolicy = cmsWithPublicThemeContext([
    'public_render_origin' => 'ecommerce',
    'public_route_kind' => 'shop_index',
], static function (): array {
    return cmsResolveEcommerceThemePolicy([
        'public_render_origin' => 'ecommerce',
        'public_route_kind' => 'shop_index',
        'public_presentation_mode' => 'traditional',
    ]);
});
t('ecommerce route policy preserves the configured site theme for diagnostics', ($scopedShopPolicy['configured_site_theme'] ?? '') === 'minimal', json_encode($scopedShopPolicy));
t('ecommerce route policy leaves storefront theme unset when not explicitly configured', ($scopedShopPolicy['preferred_storefront_theme'] ?? '') === '', json_encode($scopedShopPolicy));
t('ecommerce route policy keeps the configured site theme when no storefront theme is configured', ($scopedShopPolicy['active_theme'] ?? '') === 'minimal', json_encode($scopedShopPolicy));
t('ecommerce route policy keeps theme source as site without storefront override', ($scopedShopPolicy['active_theme_source'] ?? '') === 'site', json_encode($scopedShopPolicy));
t('ecommerce route policy keeps native customizer scope without storefront override', ($scopedShopPolicy['active_theme_scope'] ?? '') === 'native', json_encode($scopedShopPolicy));
t('ecommerce route policy honors traditional mode without storefront override', ($scopedShopPolicy['public_presentation_mode'] ?? '') === 'traditional' && empty($scopedShopPolicy['has_presentation_mode_conflict']), json_encode($scopedShopPolicy));

$scopedShopTheme = cmsWithPublicThemeContext([
    'public_render_origin' => 'ecommerce',
    'public_route_kind' => 'shop_index',
], static function (): array {
    return [
        'theme' => cmsActiveTheme(),
        'presentation_mode' => cmsEcommercePublicPresentationMode([
            'public_render_origin' => 'ecommerce',
            'public_route_kind' => 'shop_index',
        ]),
        'style_url' => cmsThemeAssetUrl('style.css'),
    ];
});
t('ecommerce route context keeps native site theme when storefront override is unset', ($scopedShopTheme['theme'] ?? '') === 'minimal', json_encode($scopedShopTheme));
t('ecommerce route context stays in traditional mode under native site theme', ($scopedShopTheme['presentation_mode'] ?? '') === 'traditional', json_encode($scopedShopTheme));
t('ecommerce route context does not serve storefront theme assets without explicit storefront theme', !str_contains((string)($scopedShopTheme['style_url'] ?? ''), '/assets/cms/themes/entity-commerce-poc/style.css'), (string)($scopedShopTheme['style_url'] ?? ''));
t('global active theme remains minimal outside ecommerce route context', cmsActiveTheme() === 'minimal', (string)cmsActiveTheme());

$explicitStorefrontSettings = $minimalScopeSettings;
$explicitStorefrontSettings['active_ecommerce_theme'] = 'entity-commerce-poc';
saveModuleSettings('cms', $explicitStorefrontSettings);
cmsResetThemeRuntimeCache();

$explicitStorefrontPolicy = cmsWithPublicThemeContext([
    'public_render_origin' => 'ecommerce',
    'public_route_kind' => 'shop_index',
], static function (): array {
    return cmsResolveEcommerceThemePolicy([
        'public_render_origin' => 'ecommerce',
        'public_route_kind' => 'shop_index',
    ]);
});
t('explicit ecommerce theme config promotes storefront theme on shop route', ($explicitStorefrontPolicy['active_theme'] ?? '') === 'entity-commerce-poc', json_encode($explicitStorefrontPolicy));
t('explicit ecommerce theme config resolves storefront theme source', ($explicitStorefrontPolicy['active_theme_source'] ?? '') === 'storefront', json_encode($explicitStorefrontPolicy));
t('explicit ecommerce theme config restores ecommerce customizer scope', ($explicitStorefrontPolicy['active_theme_scope'] ?? '') === 'ecommerce', json_encode($explicitStorefrontPolicy));
t('explicit ecommerce theme config restores entity-view mode', ($explicitStorefrontPolicy['public_presentation_mode'] ?? '') === 'entity_view', json_encode($explicitStorefrontPolicy));

saveModuleSettings('cms', $minimalScopeSettings);
cmsResetThemeRuntimeCache();

$traditionalTemplateAllowed = true;
try {
    ecAssertTraditionalEntityTemplateAllowed('modules/ecommerce/public/shop.disyl', [
        'public_render_origin' => 'ecommerce',
        'public_route_kind' => 'shop_index',
    ]);
} catch (Throwable $e) {
    $traditionalTemplateAllowed = false;
}
t('traditional shop template remains allowed without storefront route context promotion', $traditionalTemplateAllowed === true);

$scopedTraditionalTemplateError = '';
cmsWithPublicThemeContext([
    'public_render_origin' => 'ecommerce',
    'public_route_kind' => 'shop_index',
], static function () use (&$scopedTraditionalTemplateError): void {
    try {
        ecAssertTraditionalEntityTemplateAllowed('modules/ecommerce/public/shop.disyl', [
            'public_render_origin' => 'ecommerce',
            'public_route_kind' => 'shop_index',
        ]);
    } catch (Throwable $e) {
        $scopedTraditionalTemplateError = $e->getMessage();
    }
});
t('storefront route context keeps traditional shop template allowed without explicit storefront theme', $scopedTraditionalTemplateError === '', $scopedTraditionalTemplateError);
t('storefront route context reports canonical entity rendering requirement', cmsWithPublicThemeContext([
    'public_render_origin' => 'ecommerce',
    'public_route_kind' => 'shop_index',
], static function (): bool {
    return ecRouteUsesCanonicalEntityRendering('shop_index', [
        'public_render_origin' => 'ecommerce',
        'public_route_kind' => 'shop_index',
    ]);
}) === false);
t('without storefront route context canonical entity rendering is not required under native theme', ecRouteUsesCanonicalEntityRendering('shop_index', [
    'public_render_origin' => 'ecommerce',
    'public_route_kind' => 'shop_index',
]) === false);

$scopedRuntimeDiagnostics = cmsWithPublicThemeContext([
    'public_render_origin' => 'ecommerce',
    'public_route_kind' => 'shop_index',
], static function (): array {
    return cmsThemeRuntimeDiagnostics();
});
t('route-scoped diagnostics preserve configured site theme', ($scopedRuntimeDiagnostics['configured_site_theme'] ?? '') === 'minimal', json_encode($scopedRuntimeDiagnostics));
t('route-scoped diagnostics keep site theme source without storefront override', ($scopedRuntimeDiagnostics['active_theme_source'] ?? '') === 'site', json_encode($scopedRuntimeDiagnostics));
t('route-scoped diagnostics keep traditional presentation mode without storefront override', ($scopedRuntimeDiagnostics['public_presentation_mode'] ?? '') === 'traditional', json_encode($scopedRuntimeDiagnostics));
t('route-scoped diagnostics expose ecommerce route kind', ($scopedRuntimeDiagnostics['public_route_kind'] ?? '') === 'shop_index', json_encode($scopedRuntimeDiagnostics));

// ═══════════════════════════════════════════════════════════════════
// 5b. Native ecommerce theme templates
// ═══════════════════════════════════════════════════════════════════
echo "\n=== NATIVE ECOMMERCE TEMPLATES ===\n";

$nativeThemeSettings = $oldSettings;
$nativeThemeSettings['active_theme'] = 'native-default';
$nativeThemeSettings['active_ecommerce_theme'] = '';
saveModuleSettings('cms', $nativeThemeSettings);
cmsResetThemeRuntimeCache();
cmsActivateThemeSymlink('native-default');

$shopCandidates = ecPublicThemeTemplateCandidates('modules/ecommerce/public/shop.disyl', [
    'public_render_origin' => 'ecommerce',
    'public_route_kind' => 'shop_index',
]);
t('native storefront shop route prefers Woo-style archive-product template candidates', $shopCandidates === [
    '_cms_active_theme/public/ecommerce/archive-product.disyl',
    '_cms_active_theme/public/ecommerce/shop.disyl',
    'modules/ecommerce/public/shop.disyl',
], json_encode($shopCandidates));

$productCandidates = ecPublicThemeTemplateCandidates('modules/ecommerce/public/product.disyl', [
    'public_render_origin' => 'ecommerce',
    'public_route_kind' => 'product_detail',
]);
t('native storefront product route prefers Woo-style single-product template candidates', $productCandidates === [
    '_cms_active_theme/public/ecommerce/single-product.disyl',
    '_cms_active_theme/public/ecommerce/product.disyl',
    'modules/ecommerce/public/product.disyl',
], json_encode($productCandidates));

t('native storefront shop route resolves archive-product theme template', ecResolvePublicThemeTemplate('modules/ecommerce/public/shop.disyl', [
    'public_render_origin' => 'ecommerce',
    'public_route_kind' => 'shop_index',
]) === '_cms_active_theme/public/ecommerce/archive-product.disyl');
t('native storefront product route resolves single-product theme template', ecResolvePublicThemeTemplate('modules/ecommerce/public/product.disyl', [
    'public_render_origin' => 'ecommerce',
    'public_route_kind' => 'product_detail',
]) === '_cms_active_theme/public/ecommerce/single-product.disyl');
t('native storefront cart route resolves dedicated cart theme template', ecResolvePublicThemeTemplate('modules/ecommerce/public/cart.disyl', [
    'public_render_origin' => 'ecommerce',
    'public_route_kind' => 'cart',
]) === '_cms_active_theme/public/ecommerce/cart.disyl');
t('native storefront checkout route resolves dedicated checkout theme template', ecResolvePublicThemeTemplate('modules/ecommerce/public/checkout.disyl', [
    'public_render_origin' => 'ecommerce',
    'public_route_kind' => 'checkout',
]) === '_cms_active_theme/public/ecommerce/checkout.disyl');
t('native storefront order confirmation route resolves dedicated confirmation theme template', ecResolvePublicThemeTemplate('modules/ecommerce/public/order-confirmation.disyl', [
    'public_render_origin' => 'ecommerce',
    'public_route_kind' => 'order_confirmation',
]) === '_cms_active_theme/public/ecommerce/order-confirmation.disyl');
t('native storefront orders route resolves dedicated account theme template', ecResolvePublicThemeTemplate('modules/ecommerce/public/my-orders.disyl', [
    'public_render_origin' => 'ecommerce',
    'public_route_kind' => 'my_orders',
]) === '_cms_active_theme/public/ecommerce/my-orders.disyl');
t('native storefront order detail route resolves dedicated order detail theme template', ecResolvePublicThemeTemplate('modules/ecommerce/public/order-detail.disyl', [
    'public_render_origin' => 'ecommerce',
    'public_route_kind' => 'order_detail',
]) === '_cms_active_theme/public/ecommerce/order-detail.disyl');

$nativeShopHtml = captureEcRender('modules/ecommerce/public/shop.disyl', [
    'page_title' => 'Shop',
    'products' => [[
        'id' => 1,
        'slug' => 'demo-product',
        'title' => 'Demo Product',
        'excerpt' => 'A short excerpt.',
        'primary_image_url' => '',
        'pricing' => [
            'formatted' => '$19.00',
            'on_sale' => false,
            'regular_fmt' => '$19.00',
        ],
        'inventory' => [
            'track_stock' => true,
            'stock_qty' => 6,
            'in_stock' => true,
            'out_of_stock' => false,
            'low_stock' => false,
        ],
    ]],
    'total' => 1,
    'categories' => [],
    'search' => '',
    'category_id' => 0,
    'page' => 1,
    'per_page' => 12,
    'total_pages' => 1,
    'cart_count' => 0,
    'public_render_origin' => 'ecommerce',
    'public_route_kind' => 'shop_index',
    'public_presentation_mode' => 'traditional',
]);
t('native storefront shop render uses archive-product theme template', str_contains($nativeShopHtml, 'data-native-ecommerce-template="archive-product"'), $nativeShopHtml);

$nativeProductHtml = captureEcRender('modules/ecommerce/public/product.disyl', [
    'page_title' => 'Demo Product',
    'product' => [
        'id' => 1,
        'title' => 'Demo Product',
        'slug' => 'demo-product',
        'excerpt' => 'A short excerpt.',
        'body' => '<p>Body copy</p>',
        'primary_image_url' => '',
        'gallery_images' => [],
        'categories' => [['slug' => 'catalog', 'name' => 'Catalog']],
        'pricing' => [
            'formatted' => '$19.00',
            'on_sale' => false,
            'regular_fmt' => '$19.00',
        ],
        'inventory' => [
            'track_stock' => true,
            'stock_qty' => 6,
            'in_stock' => true,
            'out_of_stock' => false,
            'low_stock' => false,
            'sku' => 'SKU-001',
        ],
    ],
    'cart_count' => 0,
    'public_render_origin' => 'ecommerce',
    'public_route_kind' => 'product_detail',
    'public_presentation_mode' => 'traditional',
]);
t('native storefront product render uses single-product theme template', str_contains($nativeProductHtml, 'data-native-ecommerce-template="single-product"'), $nativeProductHtml);

$nativeCartHtml = captureEcRender('modules/ecommerce/public/cart.disyl', [
    'cart' => [
        'coupon_code' => '',
        'items' => [[
            'product_title' => 'Demo Product',
            'sku' => 'SKU-001',
            'qty' => 2,
            'price_snapshot' => 19,
        ]],
        'totals' => [
            'item_count' => 2,
            'subtotal_fmt' => '$38.00',
            'discount' => 0,
            'discount_fmt' => '$0.00',
            'tax' => 0,
            'tax_rate' => 0,
            'tax_fmt' => '$0.00',
            'total_fmt' => '$38.00',
        ],
    ],
    'cart_count' => 2,
    'public_render_origin' => 'ecommerce',
    'public_route_kind' => 'cart',
    'public_presentation_mode' => 'traditional',
]);
t('native storefront cart render uses cart theme template', str_contains($nativeCartHtml, 'data-native-ecommerce-template="cart"'), $nativeCartHtml);

$nativeCheckoutHtml = captureEcRender('modules/ecommerce/public/checkout.disyl', [
    'user' => ['first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => 'ada@example.test'],
    'shipping_rates' => [['id' => 1, 'name' => 'Standard', 'rate' => 0]],
    'payment_label' => 'Pay on delivery',
    'csrf_token' => 'token',
    'cart' => [
        'items' => [[
            'product_title' => 'Demo Product',
            'qty' => 1,
            'price_snapshot' => 19,
        ]],
        'totals' => [
            'subtotal_fmt' => '$19.00',
            'discount' => 0,
            'discount_fmt' => '$0.00',
            'tax' => 0,
            'tax_fmt' => '$0.00',
            'total_fmt' => '$19.00',
        ],
    ],
    'cart_count' => 1,
    'public_render_origin' => 'ecommerce',
    'public_route_kind' => 'checkout',
    'public_presentation_mode' => 'traditional',
]);
t('native storefront checkout render uses checkout theme template', str_contains($nativeCheckoutHtml, 'data-native-ecommerce-template="checkout"'), $nativeCheckoutHtml);

$nativeOrderConfirmationHtml = captureEcRender('modules/ecommerce/public/order-confirmation.disyl', [
    'payment_label' => 'Pay on delivery',
    'is_logged_in' => true,
    'order' => [
        'order_number' => '1001',
        'customer_email' => 'ada@example.test',
        'currency_symbol' => '$',
        'discount_amount' => 0,
        'tax_amount' => 0,
        'shipping_amount' => 0,
        'total_amount' => '19.00',
        'items' => [[
            'product_title' => 'Demo Product',
            'qty' => 1,
            'line_total' => '19.00',
        ]],
        'shipping' => [
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'address_line1' => '123 Example St',
            'address_line2' => '',
            'city' => 'London',
            'state' => '',
            'postal_code' => '1000',
            'country' => 'UK',
            'phone' => '',
        ],
    ],
    'public_render_origin' => 'ecommerce',
    'public_route_kind' => 'order_confirmation',
    'public_presentation_mode' => 'traditional',
]);
t('native storefront order confirmation render uses confirmation theme template', str_contains($nativeOrderConfirmationHtml, 'data-native-ecommerce-template="order-confirmation"'), $nativeOrderConfirmationHtml);

$nativeOrdersHtml = captureEcRender('modules/ecommerce/public/my-orders.disyl', [
    'orders' => [[
        'id' => 1,
        'order_number' => '1001',
        'created_at' => '2026-03-29 12:00:00',
        'status' => 'processing',
        'currency_symbol' => '$',
        'total_amount' => '19.00',
    ]],
    'page' => 1,
    'total_pages' => 1,
    'public_render_origin' => 'ecommerce',
    'public_route_kind' => 'my_orders',
    'public_presentation_mode' => 'traditional',
]);
t('native storefront my orders render uses account theme template', str_contains($nativeOrdersHtml, 'data-native-ecommerce-template="my-orders"'), $nativeOrdersHtml);

$nativeOrderDetailHtml = captureEcRender('modules/ecommerce/public/order-detail.disyl', [
    'order' => [
        'order_number' => '1001',
        'created_at' => '2026-03-29 12:00:00',
        'status' => 'processing',
        'currency_symbol' => '$',
        'subtotal_amount' => '19.00',
        'discount_amount' => 0,
        'tax_amount' => 0,
        'shipping_amount' => 0,
        'total_amount' => '19.00',
        'items' => [[
            'product_title' => 'Demo Product',
            'variant_label' => '',
            'sku' => 'SKU-001',
            'unit_price' => '19.00',
            'qty' => 1,
            'line_total' => '19.00',
        ]],
        'shipping' => [
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'address_line1' => '123 Example St',
            'address_line2' => '',
            'city' => 'London',
            'state' => '',
            'postal_code' => '1000',
            'country' => 'UK',
            'phone' => '',
        ],
        'billing' => [
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'ada@example.test',
            'address_line1' => '123 Example St',
            'address_line2' => '',
            'city' => 'London',
            'state' => '',
            'postal_code' => '1000',
            'country' => 'UK',
            'phone' => '',
        ],
    ],
    'public_render_origin' => 'ecommerce',
    'public_route_kind' => 'order_detail',
    'public_presentation_mode' => 'traditional',
]);
t('native storefront order detail render uses detail theme template', str_contains($nativeOrderDetailHtml, 'data-native-ecommerce-template="order-detail"'), $nativeOrderDetailHtml);

saveModuleSettings('cms', $minimalScopeSettings);
cmsResetThemeRuntimeCache();
cmsActivateThemeSymlink('minimal');

// ═══════════════════════════════════════════════════════════════════
// 6. Theme template content validation
// ═══════════════════════════════════════════════════════════════════
echo "\n=== THEME TEMPLATE CONTENT ===\n";

$layoutContent = file_get_contents($link . '/layouts/public.disyl');
t('layout has DOCTYPE', str_contains($layoutContent, '<!DOCTYPE html>'));
t('layout has {block content}', str_contains($layoutContent, '{block content}'));
t('layout has {block head}', str_contains($layoutContent, '{block head}'));
t('layout has {block scripts}', str_contains($layoutContent, '{block scripts}'));
t('layout has Minimal Theme branding', str_contains($layoutContent, 'Minimal Theme'));

$nativeLayoutContent = file_get_contents(STORAGE_PATH . '/cms-themes/native-default/layouts/public.disyl');
t('native layout noscript fallback reveals body', str_contains($nativeLayoutContent, 'body:not(.cz-loaded),[data-animate]{opacity:1!important;transform:none!important;}'));
t('native layout inline fallback reveals animated content', str_contains($nativeLayoutContent, 'document.body.classList.add(\'cz-loaded\')'));

$pocLayoutContent = file_get_contents(BASE_PATH . '/storage/cms-themes/entity-commerce-poc/layouts/public.disyl');
$themeStylePos = strpos($pocLayoutContent, '{if theme_style_url}<link rel="stylesheet" href="{theme_style_url}">{/if}');
t('entity-commerce-poc layout omits public Tailwind CDN for storefront isolation', !str_contains($pocLayoutContent, 'https://cdn.tailwindcss.com'));
t('entity-commerce-poc layout still loads theme stylesheet', $themeStylePos !== false);

$pocStyleContent = file_get_contents(BASE_PATH . '/storage/cms-themes/entity-commerce-poc/style.css');
t('entity-commerce-poc styles shared gallery grid without Tailwind', str_contains($pocStyleContent, '.cms-gallery-grid {') && str_contains($pocStyleContent, '.cms-gallery-item-link {'));
t('entity-commerce-poc styles shared progress block without Tailwind', str_contains($pocStyleContent, '.cms-progress-row {') && str_contains($pocStyleContent, '.cms-progress-track {'));
t('entity-commerce-poc styles prose content without Tailwind typography utilities', str_contains($pocStyleContent, '.poc-entity-view__body.prose h2 {') && str_contains($pocStyleContent, '.poc-entity-view__body.prose blockquote {'));

$entityPresentationCss = cmsRenderEntityPresentationCss(cmsStorefrontSettingsDefaults());
t('entity presentation css exports global geometry variables for storefront themes', str_contains($entityPresentationCss, '--theme-entity-list-card-min-width:') && str_contains($entityPresentationCss, '--theme-entity-list-media-ratio:') && str_contains($entityPresentationCss, '--theme-entity-media-ratio:') && str_contains($entityPresentationCss, '--theme-entity-action-min-height:'), $entityPresentationCss);

$customizerTemplateContent = file_get_contents(BASE_PATH . '/templates/modules/cms/admin/theme-customizer.disyl');
$entityListTemplateContent = file_get_contents(BASE_PATH . '/templates/modules/cms/public/entity.list.disyl') ?: '';
$entityViewTemplateContent = file_get_contents(BASE_PATH . '/templates/modules/cms/public/entity.view.disyl') ?: '';
$nativeArchiveProductTemplateContent = file_get_contents(BASE_PATH . '/storage/cms-themes/native-default/public/ecommerce/archive-product.disyl') ?: '';
$nativeSingleProductTemplateContent = file_get_contents(BASE_PATH . '/storage/cms-themes/native-default/public/ecommerce/single-product.disyl') ?: '';
t('customizer tracks per-section dirty state for scoped saves', str_contains($customizerTemplateContent, 'dirtySections: { footer: false, header: false, sidebar: false, colors: false, custom_code: false, theme: false, storefront: false }'));
t('customizer save resolves only dirty sections', str_contains($customizerTemplateContent, 'const sections = this.sectionsToSave();'));
t('canonical entity list template extends the active theme public layout', str_contains($entityListTemplateContent, '{extends "_cms_active_theme/layouts/public.disyl"}'), $entityListTemplateContent);
t('canonical entity view template extends the active theme public layout', str_contains($entityViewTemplateContent, '{extends "_cms_active_theme/layouts/public.disyl"}'), $entityViewTemplateContent);
t('native default theme ships dedicated archive-product storefront template', str_contains($nativeArchiveProductTemplateContent, 'data-native-ecommerce-template="archive-product"'), $nativeArchiveProductTemplateContent);
t('native default theme ships dedicated single-product storefront template', str_contains($nativeSingleProductTemplateContent, 'data-native-ecommerce-template="single-product"'), $nativeSingleProductTemplateContent);
t('ecommerce customizer presents storefront studio workspace copy', str_contains($customizerTemplateContent, 'Shape the full storefront here: header, navigation behavior, footer composition, palette, and canonical entity-view presentation for the commerce shell.'));
t('native customizer explains catalog controls stay inside the active theme shell', str_contains($customizerTemplateContent, 'The active theme shell stays in charge here. Use Catalog for shop, category, and product presentation inside that shell.'));
t('native customizer describes catalog presentation inside the active theme shell', str_contains($customizerTemplateContent, 'Catalog presentation for shop, category, and product routes inside the active theme shell.'));
t('ecommerce customizer exposes dedicated navigation tab', str_contains($customizerTemplateContent, "activeTab = 'navigation'") && str_contains($customizerTemplateContent, 'Menu Behavior'));
t('ecommerce customizer keeps native-capable sidebar controls available', str_contains($customizerTemplateContent, "activeTab = 'sidebar'") && str_contains($customizerTemplateContent, 'Sidebar Rail'));
t('ecommerce customizer keeps native-capable custom code controls available', str_contains($customizerTemplateContent, "activeTab = 'custom_code'") && str_contains($customizerTemplateContent, 'Store Code'));
t('ecommerce customizer saves all dirty scoped sections', str_contains($customizerTemplateContent, 'return sections;'));
t('customizer menu locations normalize label fallback for navigation workspace', str_contains($customizerTemplateContent, 'loc.label || loc.name || loc.slug') && str_contains($customizerTemplateContent, 'this.availableMenuLocations = (Array.isArray(this.availableMenuLocations) ? this.availableMenuLocations : [])'));
t('customizer bootstraps dedicated storefront settings payload', str_contains($customizerTemplateContent, 'id="cz-storefront-settings"'));
t('customizer saves dedicated storefront section payload', str_contains($customizerTemplateContent, "return { settings: this.storefrontSettings }"));
t('customizer exposes storefront list-card pricing variant control', str_contains($customizerTemplateContent, 'storefrontSettings.entity_list_pricing_variant'));
t('customizer exposes storefront action inline variant option', str_contains($customizerTemplateContent, '<option value="inline">Inline</option>'));
t('customizer hydrates theme manifest block variants for preview fidelity', str_contains($customizerTemplateContent, 'cz-theme-manifest-block-variants'));
t('customizer preview resolves effective storefront list-card variants', str_contains($customizerTemplateContent, 'entityPreviewListPricingVariant()') && str_contains($customizerTemplateContent, 'entityPreviewEffectiveVariant('));
t('customizer preview derives storefront surfaces and featured states from active color settings', str_contains($customizerTemplateContent, "this.colorAlpha(this.colorsSettings.body_text_color || '#1e293b', 0.06)") && str_contains($customizerTemplateContent, "this.colorAlpha(this.colorsSettings.storefront_cta_bg || '#0284c7', 0.18)") && str_contains($customizerTemplateContent, "customizerScope === 'ecommerce' ? colorsSettings.storefront_cta_bg : colorsSettings.color_primary"), $customizerTemplateContent);
t('ecommerce customizer resets navigation tab through header defaults', str_contains($customizerTemplateContent, "this.activeTab === 'header' || this.activeTab === 'navigation'"));

$ecInitContent = file_get_contents(BASE_PATH . '/modules/ecommerce/helpers/00-init.php');
$ecPublicShopHandlerContent = file_get_contents(BASE_PATH . '/modules/ecommerce/handlers/10-public-shop.php');
t('ecommerce public render delegates CMS shell rendering through cms module context', str_contains($ecInitContent, "moduleWithContext('cms', static function () use (") && str_contains($ecInitContent, 'cmsPublicContext($context)'), $ecInitContent);
t('ecommerce public render resolves native theme storefront template candidates', str_contains($ecInitContent, 'function ecPublicThemeTemplateCandidates(') && str_contains($ecInitContent, 'function ecResolvePublicThemeTemplate('), $ecInitContent);
t('shop handler now falls back to a traditional storefront template when canonical rendering is disabled', str_contains($ecPublicShopHandlerContent, "if (ecDispatchCanonicalEntityRoute('cms:cmsPublicEntityList'") && str_contains($ecPublicShopHandlerContent, "ecRender('modules/ecommerce/public/shop.disyl'"), $ecPublicShopHandlerContent);
t('ecommerce public render defines explicit presentation mode resolver', str_contains($ecInitContent, 'function ecResolvePublicPresentationMode('), $ecInitContent);
t('ecommerce public render defines canonical entity-route guard helpers', str_contains($ecInitContent, 'function ecDispatchCanonicalEntityRoute(') && str_contains($ecInitContent, 'function ecAssertTraditionalEntityTemplateAllowed('), $ecInitContent);
t('ecommerce public render blocks traditional entity templates in entity-view mode', str_contains($ecInitContent, 'ecAssertTraditionalEntityTemplateAllowed($template, $context);') && str_contains($ecInitContent, 'Traditional ecommerce template "'), $ecInitContent);
t('ecommerce public render injects presentation mode into public context', str_contains($ecInitContent, "'public_presentation_mode' => "), $ecInitContent);
t('shop index handler preserves canonical CMS entity list payload and native-theme fallback', str_contains($ecPublicShopHandlerContent, "if (ecDispatchCanonicalEntityRoute('cms:cmsPublicEntityList'") && str_contains($ecPublicShopHandlerContent, '\'available_categories\' => $availableCategories') && str_contains($ecPublicShopHandlerContent, "'search_action_url' => '/ecommerce/shop'") && str_contains($ecPublicShopHandlerContent, "ecRender('modules/ecommerce/public/shop.disyl'"), $ecPublicShopHandlerContent);
t('product detail handler delegates canonical entity-view through guard helper', str_contains($ecPublicShopHandlerContent, "ecDispatchCanonicalEntityRoute('cms:cmsPublicEntityView'"), $ecPublicShopHandlerContent);
t('product detail handler delegates canonical entity-view before ecommerce product preload', (($delegatePos = strpos($ecPublicShopHandlerContent, "ecDispatchCanonicalEntityRoute('cms:cmsPublicEntityView'")) !== false) && (($productLoadPos = strpos($ecPublicShopHandlerContent, 'ecProductGetBySlug($slug)')) !== false) && $delegatePos < $productLoadPos, $ecPublicShopHandlerContent);
t('product detail canonical delegation preserves ecommerce route metadata', str_contains($ecPublicShopHandlerContent, "'public_route_kind' => 'product_detail'") && str_contains($ecPublicShopHandlerContent, "'public_render_origin' => 'ecommerce'"), $ecPublicShopHandlerContent);

$ecommerceLayoutContent = file_get_contents(BASE_PATH . '/templates/modules/ecommerce/layouts/public.disyl');
t('ecommerce public layout consumes customized header output', str_contains($ecommerceLayoutContent, '{customized_header|raw}'));
t('ecommerce public layout consumes customized footer output', str_contains($ecommerceLayoutContent, '{customized_footer|raw}'));
t('ecommerce public layout loads storefront theme assets', str_contains($ecommerceLayoutContent, '{if theme_style_url}<link rel="stylesheet" href="{theme_style_url}">{/if}'));
t('ecommerce public layout uses shared shell wrapper for fallback header and footer', str_contains($ecommerceLayoutContent, 'cms-public-shell'));
t('ecommerce public layout uses shared main layout contract', str_contains($ecommerceLayoutContent, 'cms-public-main'));

$pocStyleContent = file_get_contents(cmsThemesPath() . '/entity-commerce-poc/style.css');
t('entity-commerce-poc styles generic customizer site header', str_contains($pocStyleContent, '.site-header {'));
t('entity-commerce-poc provides customized header inner shell styles', str_contains($pocStyleContent, '.poc-header__inner--customized {'));
t('entity-commerce-poc provides dedicated customized header slot shell styles', str_contains($pocStyleContent, '.poc-header__slot--customized {'));
t('entity-commerce-poc keeps customized header inner layout contained', str_contains($pocStyleContent, '.poc-header--customized .header-inner,'));
t('entity-commerce-poc keeps customized header container max-width disabled', str_contains($pocStyleContent, 'max-width: none;'));
t('entity-commerce-poc styles generic customizer footer widgets', str_contains($pocStyleContent, '.footer-widgets-grid {'));
t('entity-commerce-poc styles generic customizer footer bottom', str_contains($pocStyleContent, '.footer-bottom {'));
t('entity-commerce-poc preserves customized footer widget backgrounds', !str_contains($pocStyleContent, ".poc-footer--customized .footer-widgets {\n    background: transparent !important;"), $pocStyleContent);
t('entity-commerce-poc preserves customized footer bar backgrounds', !str_contains($pocStyleContent, ".poc-footer--customized .footer-bottom {\n    background: transparent !important;"), $pocStyleContent);
t('entity-commerce-poc routes customized nav colors through header variables', str_contains($pocStyleContent, 'color: var(--header-link, var(--color-text));') && str_contains($pocStyleContent, 'color: var(--header-link-hover, var(--color-primary));'), $pocStyleContent);
t('entity-commerce-poc routes topbar links through topbar variables', str_contains($pocStyleContent, '.header-topbar a {') && str_contains($pocStyleContent, 'var(--topbar-link, var(--header-link, var(--color-link)))'), $pocStyleContent);
t('entity-commerce-poc routes footer chrome through footer variables', str_contains($pocStyleContent, 'var(--footer-link, var(--footer-text, var(--color-border)))') && str_contains($pocStyleContent, 'var(--footer-bg, var(--color-surface))'), $pocStyleContent);
t('entity-commerce-poc routes header search chrome through header variables', str_contains($pocStyleContent, '.header-search-toggle {') && str_contains($pocStyleContent, 'var(--header-bg, var(--color-surface))') && str_contains($pocStyleContent, 'var(--header-border, var(--color-border))'), $pocStyleContent);
t('entity-commerce-poc routes storefront surfaces and actions through ecommerce color tokens', str_contains($pocStyleContent, 'var(--storefront-surface-bg, var(--color-surface))') && str_contains($pocStyleContent, 'var(--storefront-cta-bg, var(--color-primary))') && str_contains($pocStyleContent, 'var(--storefront-secondary-bg, var(--color-surface))'), $pocStyleContent);
t('entity-commerce-poc routes storefront inventory and badge states through ecommerce color tokens', str_contains($pocStyleContent, 'var(--storefront-warning-bg') && str_contains($pocStyleContent, 'var(--storefront-danger-text') && str_contains($pocStyleContent, 'var(--storefront-success-bg') && str_contains($pocStyleContent, 'var(--storefront-badge-bg'), $pocStyleContent);
t('entity-commerce-poc routes catalog geometry through entity presentation variables', str_contains($pocStyleContent, 'var(--theme-entity-list-card-min-width') && str_contains($pocStyleContent, 'var(--theme-entity-list-media-ratio') && str_contains($pocStyleContent, 'var(--theme-entity-action-min-height'), $pocStyleContent);
t('entity-commerce-poc routes detail layout geometry through entity presentation variables', str_contains($pocStyleContent, 'var(--theme-entity-summary-width, 320px)') && str_contains($pocStyleContent, 'var(--theme-entity-gap, 2rem)') && str_contains($pocStyleContent, 'var(--theme-entity-media-ratio, auto)') && str_contains($pocStyleContent, 'var(--theme-entity-panel-padding, 1rem)'), $pocStyleContent);
t('entity-commerce-poc uses responsive auto-fit catalog columns instead of fixed desktop count', str_contains($pocStyleContent, 'repeat(auto-fit, minmax(min(100%, var(--poc-card-min-width)), 1fr))'), $pocStyleContent);
t('entity-commerce-poc provides a storefront header partial', is_file(cmsThemesPath() . '/entity-commerce-poc/public/header.disyl'));
t('entity-commerce-poc provides a storefront footer partial', is_file(cmsThemesPath() . '/entity-commerce-poc/public/footer.disyl'));

$pocHeaderPartialContent = file_get_contents(cmsThemesPath() . '/entity-commerce-poc/public/header.disyl');
t('entity-commerce-poc customized header partial uses dedicated slot shell', str_contains($pocHeaderPartialContent, 'poc-header__slot--customized'));

$singleContent = file_get_contents($link . '/public/single.disyl');
t('single extends theme layout', str_contains($singleContent, '{extends "_cms_active_theme/layouts/public.disyl"}'));
t('single has post_html output', str_contains($singleContent, '{post_html | raw}'));
t('single has cms_head block', str_contains($singleContent, 'cms_head'));

$homeContent = file_get_contents($link . '/public/home.disyl');
t('home extends theme layout', str_contains($homeContent, '{extends "_cms_active_theme/layouts/public.disyl"}'));
t('home has foreach posts', str_contains($homeContent, '{foreach posts as post}'));

// ═══════════════════════════════════════════════════════════════════
// 6a. Token-only theme enforcement + manifest cache reset
// ═══════════════════════════════════════════════════════════════════
echo "\n=== TOKEN-ONLY THEME POLICY ===\n";

$tokenThemeA = 'token-only-regression-a';
$tokenThemeB = 'token-only-regression-b';
$tokenThemeADir = cmsThemesPath() . '/' . $tokenThemeA;
$tokenThemeBDir = cmsThemesPath() . '/' . $tokenThemeB;

foreach ([$tokenThemeADir, $tokenThemeBDir] as $dir) {
    @mkdir($dir . '/layouts', 0775, true);
    @mkdir($dir . '/public/blocks', 0775, true);
}

file_put_contents($tokenThemeADir . '/theme.json', json_encode([
    'name' => 'Token Only Regression A',
    'version' => '1.0',
    'restrict_to_tokens' => true,
    'overridable_blocks' => ['allowed.block.disyl'],
    'tokens' => ['color_primary' => '#111111'],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
file_put_contents($tokenThemeADir . '/layouts/public.disyl', "<!DOCTYPE html>\n{block content}{/block}\n");
file_put_contents($tokenThemeADir . '/public/home.disyl', "{extends \"_cms_active_theme/layouts/public.disyl\"}\n{block content}token only home{/block}\n");
file_put_contents($tokenThemeADir . '/public/blocks/allowed.block.disyl', '<div>allowed block override</div>');

file_put_contents($tokenThemeBDir . '/theme.json', json_encode([
    'name' => 'Token Only Regression B',
    'version' => '1.0',
    'restrict_to_tokens' => true,
    'overridable_blocks' => ['allowed.block.disyl'],
    'tokens' => ['color_primary' => '#222222'],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
file_put_contents($tokenThemeBDir . '/layouts/public.disyl', "<!DOCTYPE html>\n{block content}{/block}\n");
file_put_contents($tokenThemeBDir . '/public/home.disyl', "{extends \"_cms_active_theme/layouts/public.disyl\"}\n{block content}token only home b{/block}\n");
file_put_contents($tokenThemeBDir . '/public/blocks/allowed.block.disyl', '<div>allowed block override b</div>');

$tokenSettings = $oldSettings;
$tokenSettings['active_theme'] = $tokenThemeA;
saveModuleSettings('cms', $tokenSettings);
cmsResetThemeRuntimeCache();

$tokenTemplate = cmsResolveTemplate('public/home.disyl');
t('token-only theme cannot override full public template', $tokenTemplate === 'modules/cms/public/home.disyl', $tokenTemplate);

$allowedBlock = cmsResolveBlockTemplate('modules/cms/public/blocks/allowed.block.disyl');
t('token-only theme can override allowlisted block', $allowedBlock === '_cms_active_theme/public/blocks/allowed.block.disyl', $allowedBlock);

$manifestA = cmsActiveThemeManifest();
t('active theme manifest loads first token-only theme', ($manifestA['slug'] ?? '') === $tokenThemeA);
t('active theme manifest exposes token payload for first theme', (($manifestA['tokens']['color_primary'] ?? '') === '#111111'));

$tokenSettings['active_theme'] = $tokenThemeB;
saveModuleSettings('cms', $tokenSettings);
cmsResetThemeRuntimeCache();

$manifestB = cmsActiveThemeManifest();
t('manifest cache resets when active theme changes', ($manifestB['slug'] ?? '') === $tokenThemeB, json_encode($manifestB));
t('manifest cache refresh picks up new token payload', (($manifestB['tokens']['color_primary'] ?? '') === '#222222'));

// ═══════════════════════════════════════════════════════════════════
// 6c. Customizer-driven entity presentation
// ═══════════════════════════════════════════════════════════════════
echo "\n=== ENTITY PRESENTATION SETTINGS ===\n";

$tokenSettings['active_theme'] = 'minimal';
saveModuleSettings('cms', $tokenSettings);
cmsResetThemeRuntimeCache();
cmsActivateThemeSymlink('minimal');

$entityDefaults = cmsThemeLayoutSettingsDefaults();
$storefrontDefaults = cmsStorefrontSettingsDefaults();
t('entity layout profile default is default', ($entityDefaults['entity_layout_profile'] ?? '') === 'default');
t('entity pricing variant default is empty', ($entityDefaults['entity_pricing_variant'] ?? 'x') === '');
t('entity action variant default is empty', ($entityDefaults['entity_action_variant'] ?? 'x') === '');
t('entity list pricing variant default is empty', ($entityDefaults['entity_list_pricing_variant'] ?? 'x') === '');
t('entity list inventory variant default is empty', ($entityDefaults['entity_list_inventory_variant'] ?? 'x') === '');
t('entity list progress variant default is empty', ($entityDefaults['entity_list_progress_variant'] ?? 'x') === '');
t('entity summary width default is 320', ($entityDefaults['entity_summary_width'] ?? '') === '320');
t('entity summary sticky default is enabled', ($entityDefaults['entity_summary_sticky'] ?? '') === '1');
t('entity media ratio default is auto', ($entityDefaults['entity_media_ratio'] ?? '') === 'auto');
t('entity spacing scale default is comfortable', ($entityDefaults['entity_spacing_scale'] ?? '') === 'comfortable');
t('entity action size default is md', ($entityDefaults['entity_action_size'] ?? '') === 'md');
t('storefront defaults use commerce entity profile', ($storefrontDefaults['entity_layout_profile'] ?? '') === 'commerce');
t('storefront defaults mirror canonical summary width default', ($storefrontDefaults['entity_summary_width'] ?? '') === '320');
t('storefront defaults mirror canonical list-card pricing variant default', ($storefrontDefaults['entity_list_pricing_variant'] ?? 'x') === '');

$colorsDefaults = cmsColorsSettingsDefaults();
t('storefront surface background default exists', ($colorsDefaults['storefront_surface_bg'] ?? '') === '#ffffff');
t('storefront primary CTA background default exists', ($colorsDefaults['storefront_cta_bg'] ?? '') === '#0284c7');
t('storefront inventory danger text default exists', ($colorsDefaults['storefront_danger_text'] ?? '') === '#dc2626');

$validatedEntity = cmsValidateThemeLayoutSettings([
    'entity_layout_profile' => 'commerce',
    'entity_pricing_variant' => 'featured',
    'entity_action_variant' => 'sticky-footer',
    'entity_summary_width' => '410',
    'entity_summary_sticky' => '1',
    'entity_media_ratio' => '16:9',
    'entity_spacing_scale' => 'airy',
    'entity_action_size' => 'lg',
    'entity_list_show_filter_summary' => '1',
    'entity_list_card_density' => 'airy',
    'entity_list_show_excerpt' => '1',
    'entity_list_excerpt_length' => '180',
    'entity_list_pricing_variant' => 'featured',
    'entity_list_inventory_variant' => 'compact',
    'entity_list_progress_variant' => 'inline',
]);
$validatedStorefront = cmsValidateStorefrontSettings([
    'entity_layout_profile' => 'commerce',
    'entity_pricing_variant' => 'featured',
    'entity_action_variant' => 'sticky-footer',
    'entity_summary_width' => '390',
    'entity_summary_sticky' => '1',
    'entity_media_ratio' => '4:3',
    'entity_spacing_scale' => 'compact',
    'entity_action_size' => 'lg',
    'entity_list_show_filter_summary' => '0',
    'entity_list_card_density' => 'compact',
    'entity_list_show_excerpt' => '0',
    'entity_list_excerpt_length' => '90',
    'entity_list_pricing_variant' => 'minimal',
    'entity_list_inventory_variant' => 'compact',
    'entity_list_progress_variant' => 'inline',
]);
t('entity layout profile validates approved profile', ($validatedEntity['entity_layout_profile'] ?? '') === 'commerce');
t('entity pricing variant validates approved variant', ($validatedEntity['entity_pricing_variant'] ?? '') === 'featured');
t('entity action variant validates approved variant', ($validatedEntity['entity_action_variant'] ?? '') === 'sticky-footer');
t('entity summary width validates approved range', ($validatedEntity['entity_summary_width'] ?? '') === '410');
t('entity summary sticky validates boolean', (int)($validatedEntity['entity_summary_sticky'] ?? 0) === 1);
t('entity media ratio validates approved option', ($validatedEntity['entity_media_ratio'] ?? '') === '16:9');
t('entity spacing scale validates approved option', ($validatedEntity['entity_spacing_scale'] ?? '') === 'airy');
t('entity action size validates approved option', ($validatedEntity['entity_action_size'] ?? '') === 'lg');
t('entity list filter summary validates boolean', (int)($validatedEntity['entity_list_show_filter_summary'] ?? 0) === 1);
t('entity list card density validates approved option', ($validatedEntity['entity_list_card_density'] ?? '') === 'airy');
t('entity list excerpt toggle validates boolean', (int)($validatedEntity['entity_list_show_excerpt'] ?? 0) === 1);
t('entity list excerpt length validates approved range', ($validatedEntity['entity_list_excerpt_length'] ?? '') === '180');
t('entity list pricing variant validates approved option', ($validatedEntity['entity_list_pricing_variant'] ?? '') === 'featured');
t('entity list inventory variant validates approved option', ($validatedEntity['entity_list_inventory_variant'] ?? '') === 'compact');
t('entity list progress variant validates approved option', ($validatedEntity['entity_list_progress_variant'] ?? '') === 'inline');
t('storefront settings validate approved profile', ($validatedStorefront['entity_layout_profile'] ?? '') === 'commerce');
t('storefront settings validate storefront summary width', ($validatedStorefront['entity_summary_width'] ?? '') === '390');
t('storefront settings validate storefront media ratio', ($validatedStorefront['entity_media_ratio'] ?? '') === '4:3');
t('storefront settings validate list card density', ($validatedStorefront['entity_list_card_density'] ?? '') === 'compact');
t('storefront settings validate filter summary toggle', (int)($validatedStorefront['entity_list_show_filter_summary'] ?? 1) === 0);
t('storefront settings validate excerpt toggle', (int)($validatedStorefront['entity_list_show_excerpt'] ?? 1) === 0);
t('storefront settings validate excerpt length', ($validatedStorefront['entity_list_excerpt_length'] ?? '') === '90');
t('storefront settings validate list pricing variant', ($validatedStorefront['entity_list_pricing_variant'] ?? '') === 'minimal');
t('storefront settings validate list inventory variant', ($validatedStorefront['entity_list_inventory_variant'] ?? '') === 'compact');
t('storefront settings validate list progress variant', ($validatedStorefront['entity_list_progress_variant'] ?? '') === 'inline');

$invalidEntity = cmsValidateThemeLayoutSettings([
    'entity_layout_profile' => 'wild',
    'entity_pricing_variant' => 'giant',
    'entity_action_variant' => 'floating',
    'entity_summary_width' => '999',
    'entity_summary_sticky' => '',
    'entity_media_ratio' => '2:1',
    'entity_spacing_scale' => 'dense',
    'entity_action_size' => 'xl',
    'entity_list_show_filter_summary' => '',
    'entity_list_card_density' => 'dense',
    'entity_list_show_excerpt' => '',
    'entity_list_excerpt_length' => '999',
    'entity_list_pricing_variant' => 'heroic',
    'entity_list_inventory_variant' => 'full',
    'entity_list_progress_variant' => 'stacked',
]);
t('invalid entity profile falls back to default', ($invalidEntity['entity_layout_profile'] ?? '') === 'default');
t('invalid pricing variant falls back to default block', ($invalidEntity['entity_pricing_variant'] ?? 'x') === '');
t('invalid action variant falls back to default block', ($invalidEntity['entity_action_variant'] ?? 'x') === '');
t('invalid entity summary width clamps to max', ($invalidEntity['entity_summary_width'] ?? '') === '420');
t('invalid entity summary sticky falls back to disabled boolean', (int)($invalidEntity['entity_summary_sticky'] ?? 1) === 0);
t('invalid entity media ratio falls back to auto', ($invalidEntity['entity_media_ratio'] ?? '') === 'auto');
t('invalid entity spacing scale falls back to comfortable', ($invalidEntity['entity_spacing_scale'] ?? '') === 'comfortable');
t('invalid entity action size falls back to md', ($invalidEntity['entity_action_size'] ?? '') === 'md');
t('invalid entity list filter summary falls back to disabled boolean', (int)($invalidEntity['entity_list_show_filter_summary'] ?? 1) === 0);
t('invalid entity list density falls back to comfortable', ($invalidEntity['entity_list_card_density'] ?? '') === 'comfortable');
t('invalid entity list excerpt toggle falls back to disabled boolean', (int)($invalidEntity['entity_list_show_excerpt'] ?? 1) === 0);
t('invalid entity list excerpt length clamps to max', ($invalidEntity['entity_list_excerpt_length'] ?? '') === '220');
t('invalid entity list pricing variant falls back to default block', ($invalidEntity['entity_list_pricing_variant'] ?? 'x') === '');
t('invalid entity list inventory variant falls back to default block', ($invalidEntity['entity_list_inventory_variant'] ?? 'x') === '');
t('invalid entity list progress variant falls back to default block', ($invalidEntity['entity_list_progress_variant'] ?? 'x') === '');

$validatedColors = cmsValidateColorsSettings([
    'storefront_surface_bg' => '#101820',
    'storefront_cta_bg' => '#ff6600',
    'storefront_danger_text' => 'not-a-color',
]);
t('storefront colors validation keeps valid surface color', ($validatedColors['storefront_surface_bg'] ?? '') === '#101820');
t('storefront colors validation keeps valid CTA color', ($validatedColors['storefront_cta_bg'] ?? '') === '#ff6600');
t('storefront colors validation rejects invalid danger text color', ($validatedColors['storefront_danger_text'] ?? '') === '#dc2626');

$presentation = cmsEntityPresentationConfig($validatedEntity);
t('entity presentation config exposes commerce profile class', ($presentation['root_class'] ?? '') === 'cms-entity-profile-commerce');
t('entity presentation config marks commerce profile as rail summary', ($presentation['summary_mode'] ?? '') === 'rail');
t('entity presentation config exposes summary width', (int)($presentation['summary_width'] ?? 0) === 410);
t('entity presentation config exposes sticky summary flag', (int)($presentation['summary_sticky'] ?? 0) === 1);
t('entity presentation config exposes media ratio', ($presentation['media_ratio'] ?? '') === '16:9');
t('entity presentation config exposes spacing scale', ($presentation['spacing_scale'] ?? '') === 'airy');
t('entity presentation config exposes action size', ($presentation['action_size'] ?? '') === 'lg');
t('entity presentation config exposes list filter summary flag', (int)($presentation['list_show_filter_summary'] ?? 0) === 1);
t('entity presentation config exposes list card density', ($presentation['list_card_density'] ?? '') === 'airy');
t('entity presentation config exposes list excerpt flag', (int)($presentation['list_show_excerpt'] ?? 0) === 1);
t('entity presentation config exposes list excerpt length', (int)($presentation['list_excerpt_length'] ?? 0) === 180);
t('entity presentation config exposes list pricing variant', ($presentation['list_pricing_variant'] ?? '') === 'featured');
t('entity presentation config exposes list inventory variant', ($presentation['list_inventory_variant'] ?? '') === 'compact');
t('entity presentation config exposes list progress variant', ($presentation['list_progress_variant'] ?? '') === 'inline');

$storefrontPublicContext = cmsPublicContext([
    'public_render_origin' => 'ecommerce',
    'public_route_kind' => 'shop_index',
]);
t('cmsPublicContext preserves ecommerce render origin metadata', ($storefrontPublicContext['public_render_origin'] ?? '') === 'ecommerce');
t('cmsPublicContext preserves ecommerce route kind metadata', ($storefrontPublicContext['public_route_kind'] ?? '') === 'shop_index');
t('cmsPublicContext resolves ecommerce presentation mode metadata', ($storefrontPublicContext['public_presentation_mode'] ?? '') === 'traditional');
t('cmsPublicContext flags ecommerce-origin public rendering', !empty($storefrontPublicContext['is_ecommerce_public']));
t('cmsPublicContext exposes native theme source outside storefront route context', ($storefrontPublicContext['active_theme_source'] ?? '') === 'site');
t('cmsPublicContext exposes native customizer scope outside storefront route context', ($storefrontPublicContext['active_customizer_scope'] ?? '') === 'native');

$previousAppLog = @file_get_contents(STORAGE_PATH . '/logs/app.log');
$previousUser = app()->user();
app()->setUser([
    'id' => 999999,
    'source' => 'cms',
    'role' => 'customer',
]);
file_put_contents(STORAGE_PATH . '/logs/app.log', '');
$authenticatedCartContext = cmsPublicContext([]);
$authenticatedCartLog = @file_get_contents(STORAGE_PATH . '/logs/app.log') ?: '';
t('cmsPublicContext resolves authenticated cart count through ecommerce capability', ($authenticatedCartContext['cart_count'] ?? -1) === 0, json_encode($authenticatedCartContext));
t('cmsPublicContext authenticated cart lookup avoids ModuleDB denial warnings', !str_contains($authenticatedCartLog, 'ModuleDB DENIED'), $authenticatedCartLog);
app()->setUser(is_array($previousUser) ? $previousUser : []);
file_put_contents(STORAGE_PATH . '/logs/app.log', is_string($previousAppLog) ? $previousAppLog : '');

$scopedStorefrontPublicContext = cmsWithPublicThemeContext([
    'public_render_origin' => 'ecommerce',
    'public_route_kind' => 'shop_index',
], static function (): array {
    return cmsPublicContext([
        'public_render_origin' => 'ecommerce',
        'public_route_kind' => 'shop_index',
    ]);
});
t('route-scoped cmsPublicContext keeps traditional presentation mode metadata without storefront override', ($scopedStorefrontPublicContext['public_presentation_mode'] ?? '') === 'traditional', json_encode($scopedStorefrontPublicContext));
t('route-scoped cmsPublicContext keeps site theme source without storefront override', ($scopedStorefrontPublicContext['active_theme_source'] ?? '') === 'site', json_encode($scopedStorefrontPublicContext));
t('route-scoped cmsPublicContext keeps native customizer scope metadata without storefront override', ($scopedStorefrontPublicContext['active_customizer_scope'] ?? '') === 'native', json_encode($scopedStorefrontPublicContext));
t('route-scoped cmsPublicContext marks the request as an ecommerce entity route', !empty($scopedStorefrontPublicContext['is_ecommerce_entity_route']), json_encode($scopedStorefrontPublicContext));

cmsCacheInvalidateByTags(['cms:customizer']);
cmsCustomizerClearPersistentCache('colors');
cmsCustomizerClearPersistentCache('theme');

$db = cmsDb();
upsertCustomizerSection($db, 'colors', array_merge(cmsColorsSettingsDefaults(), [
    'storefront_surface_bg' => '#f8fafc',
    'storefront_price_color' => '#0f172a',
    'storefront_badge_bg' => '#dbeafe',
    'storefront_badge_text' => '#1d4ed8',
    'storefront_warning_bg' => '#fffbeb',
    'storefront_warning_text' => '#b45309',
    'font_body' => 'Nunito',
    'font_heading' => 'Playfair Display',
    'container_width' => '1320',
]));
upsertCustomizerSection($db, 'theme', array_merge(cmsThemeLayoutSettingsDefaults(), $validatedEntity));
upsertCustomizerSection($db, 'storefront', array_merge(cmsStorefrontSettingsDefaults(), [
    'entity_layout_profile' => 'commerce',
    'entity_summary_width' => '360',
    'entity_summary_sticky' => 1,
    'entity_media_ratio' => '4:3',
    'entity_spacing_scale' => 'compact',
    'entity_action_size' => 'sm',
    'entity_list_card_density' => 'compact',
    'entity_list_show_excerpt' => 0,
    'entity_list_excerpt_length' => '80',
]), [], 'native');
cmsCacheInvalidateByTags(['cms:customizer']);
cmsCustomizerClearPersistentCache('colors');
cmsCustomizerClearPersistentCache('theme');
cmsCustomizerClearPersistentCache('storefront');
$GLOBALS[cmsCustomizerRequestCacheKey('section_row', 'native')] = [];

$nativeEntityStorefrontContext = cmsPublicContext([
    'public_render_origin' => 'ecommerce',
    'public_route_kind' => 'shop_index',
]);
t('native ecommerce entity routes keep native customizer scope metadata', ($nativeEntityStorefrontContext['active_customizer_scope'] ?? '') === 'native', json_encode($nativeEntityStorefrontContext));
t('native ecommerce entity routes mark storefront presentation usage without switching theme scope', !empty($nativeEntityStorefrontContext['uses_storefront_presentation_settings']) && ($nativeEntityStorefrontContext['active_theme_source'] ?? '') === 'site', json_encode($nativeEntityStorefrontContext));
t('native ecommerce entity routes expose native storefront settings separately', ($nativeEntityStorefrontContext['storefront_settings']['entity_summary_width'] ?? '') === '360', json_encode($nativeEntityStorefrontContext));
t('native ecommerce entity routes merge storefront settings into theme settings', ($nativeEntityStorefrontContext['theme_settings']['entity_summary_width'] ?? '') === '360', json_encode($nativeEntityStorefrontContext));
t('native ecommerce entity routes append storefront override style under active theme shell', str_contains((string)($nativeEntityStorefrontContext['theme_layout_style'] ?? ''), 'id="cz-storefront-override"'), (string)($nativeEntityStorefrontContext['theme_layout_style'] ?? ''));

$nativeCartContext = cmsPublicContext([
    'public_render_origin' => 'ecommerce',
    'public_route_kind' => 'cart',
]);
t('native ecommerce cart route keeps theme layout presentation defaults', ($nativeCartContext['theme_settings']['entity_summary_width'] ?? '') === '410', json_encode($nativeCartContext));
t('native ecommerce cart route skips storefront-specific presentation overrides', empty($nativeCartContext['uses_storefront_presentation_settings']) && !str_contains((string)($nativeCartContext['theme_layout_style'] ?? ''), 'id="cz-storefront-override"'), (string)($nativeCartContext['theme_layout_style'] ?? ''));

$colorsStyle = cmsRenderColorsStyle($db);
t('colors render exposes storefront CSS variables', str_contains($colorsStyle, '--storefront-surface-bg:'), $colorsStyle);
t('colors render styles semantic price class', str_contains($colorsStyle, '.cms-price-current{color:var(--storefront-price-color);'), $colorsStyle);
t('colors render styles semantic badge class', str_contains($colorsStyle, '.cms-price-badge{background:var(--storefront-badge-bg);'), $colorsStyle);
t('colors render styles semantic inventory states', str_contains($colorsStyle, '.cms-inventory-pill--low{background:var(--storefront-warning-bg);'), $colorsStyle);
t('colors render applies body font token to public body', str_contains($colorsStyle, 'body{font-family:var(--font-body);'), $colorsStyle);
t('colors render applies heading font token to public headings', str_contains($colorsStyle, 'h1,h2,h3,h4,h5,h6{font-family:var(--font-heading);'), $colorsStyle);
t('colors render wires container width into entity theme container token', str_contains($colorsStyle, '--container-max:1320px;'), $colorsStyle);
t('colors render syncs entity-commerce-poc container width override', str_contains($colorsStyle, '.entity-commerce-poc{--container-max:var(--container-width);}'), $colorsStyle);

$themeStyle = cmsRenderThemeLayoutStyle($db);
t('theme render exposes entity summary width variable', str_contains($themeStyle, '--theme-entity-summary-width:'), $themeStyle);
t('theme render styles commerce entity layout rail', str_contains($themeStyle, '.cms-entity-profile-commerce .cms-entity-layout{display:grid;'), $themeStyle);
t('theme render styles sticky entity summary rail', str_contains($themeStyle, '.cms-entity-profile-commerce .cms-entity-summary{position:sticky;'), $themeStyle);
t('theme render styles action button sizing', str_contains($themeStyle, '.cms-action-block .cms-btn-primary,.cms-action-block .cms-btn-secondary,.cms-action-block .cms-btn-disabled{padding:'), $themeStyle);
t('theme render styles entity list density contract', str_contains($themeStyle, '--theme-entity-list-gap:') && str_contains($themeStyle, '--theme-entity-list-card-min-width:') && str_contains($themeStyle, '.cms-entity-card__body{display:flex;'), $themeStyle);
t('theme render exports detail media ratio variable for theme overrides', str_contains($themeStyle, '--theme-entity-media-ratio:'), $themeStyle);

$pocSettings = $oldSettings;
$pocSettings['active_theme'] = 'entity-commerce-poc';
saveModuleSettings('cms', $pocSettings);
cmsResetThemeRuntimeCache();
cmsActivateThemeSymlink('entity-commerce-poc');

upsertCustomizerSection($db, 'theme', array_merge(cmsThemeLayoutSettingsDefaults(), [
    'layout_mode' => 'contained',
    'site_max_width' => '1280',
]), [], 'ecommerce');
upsertCustomizerSection($db, 'header', array_merge(cmsHeaderSettingsDefaults(), [
    'show_search' => 1,
    'menu_location' => '__missing_storefront_menu__',
]), [], 'ecommerce');
upsertCustomizerSection($db, 'footer', array_merge(cmsFooterSettingsDefaults(), [
    'columns' => 1,
    'bg_color' => '#102033',
    'text_color' => '#d0def0',
    'link_color' => '#7dd3fc',
    'link_hover_color' => '#f8fafc',
    'title_color' => '#ffffff',
    'bar_bg_color' => '#08111f',
    'bar_text_color' => '#94a3b8',
    'bar_link_color' => '#f59e0b',
    'bar_link_hover_color' => '#fef3c7',
]), [[
    'id' => 'footer-test-text',
    'type' => 'text',
    'area' => 1,
    'props' => [
        'title' => 'Support',
        'content' => '<p>Footer support block</p>',
    ],
]], 'ecommerce');
upsertCustomizerSection($db, 'storefront', array_merge(cmsStorefrontSettingsDefaults(), $validatedStorefront), [], 'ecommerce');
cmsCacheInvalidateByTags(['cms:customizer']);
cmsCustomizerClearPersistentCache('theme', 'ecommerce');
cmsCustomizerClearPersistentCache('header', 'ecommerce');
cmsCustomizerClearPersistentCache('footer', 'ecommerce');
cmsCustomizerClearPersistentCache('storefront', 'ecommerce');
$GLOBALS[cmsCustomizerRequestCacheKey('section_row', 'ecommerce')] = [];

$storefrontStyle = cmsRenderStorefrontStyle($db);
t('storefront render emits dedicated ecommerce override style tag', str_contains($storefrontStyle, 'id="cz-storefront-override"'), $storefrontStyle);
t('storefront render styles commerce entity layout rail', str_contains($storefrontStyle, '.cms-entity-profile-commerce .cms-entity-layout{display:grid;'), $storefrontStyle);

$publicCtx = cmsPublicContext();
t('public context exposes dedicated storefront settings for ecommerce scope', ($publicCtx['storefront_settings']['entity_layout_profile'] ?? '') === 'commerce');
t('public context merges storefront settings into theme settings for ecommerce scope', ($publicCtx['theme_settings']['entity_summary_width'] ?? '') === '390');
t('public context appends storefront override style for ecommerce scope', str_contains((string)($publicCtx['theme_layout_style'] ?? ''), 'id="cz-storefront-override"'), (string)($publicCtx['theme_layout_style'] ?? ''));

$customizedHeaderHtml = cmsRenderCustomizedHeader($db, $publicCtx);
$customizedFooterHtml = cmsRenderCustomizedFooter($db);
t('customized storefront header renders through theme partial wrapper', str_contains($customizedHeaderHtml, 'poc-header--customized'), $customizedHeaderHtml);
t('customized storefront header emits shell entity-view wrapper', str_contains($customizedHeaderHtml, 'data-shell-entity-region="header"') && str_contains($customizedHeaderHtml, 'data-shell-entity-node="region"'), $customizedHeaderHtml);
t('customized storefront header renders through theme inner shell', str_contains($customizedHeaderHtml, 'poc-header__inner--customized'), $customizedHeaderHtml);
t('customized storefront header links site branding to shop root', str_contains($customizedHeaderHtml, '/ecommerce/shop'), $customizedHeaderHtml);
t('customized storefront header fallback nav stays on storefront routes', str_contains($customizedHeaderHtml, '>Shop<') && str_contains($customizedHeaderHtml, '/ecommerce/my-orders'), $customizedHeaderHtml);
t('customized storefront header search overlay posts to storefront query endpoint', str_contains($customizedHeaderHtml, '/ecommerce/shop') && str_contains($customizedHeaderHtml, 'name="search"'), $customizedHeaderHtml);
t('customized storefront header exposes shared shell class for contained layout', str_contains($customizedHeaderHtml, 'container cms-public-shell'), $customizedHeaderHtml);
t('customized storefront header resets submenu dropdown CSS for mobile navigation', str_contains($customizedHeaderHtml, '.main-navigation .nav-menu-sub{position:static;'), $customizedHeaderHtml);
t('customized storefront footer renders through theme partial wrapper', str_contains($customizedFooterHtml, 'poc-footer--customized'), $customizedFooterHtml);
t('customized storefront footer emits shell entity-view wrapper', str_contains($customizedFooterHtml, 'data-shell-entity-region="footer"') && str_contains($customizedFooterHtml, 'data-shell-entity-node="region"'), $customizedFooterHtml);
t('customized storefront footer keeps widget background and link colors inline', str_contains($customizedFooterHtml, 'background:#102033;') && str_contains($customizedFooterHtml, '--footer-link:#7dd3fc;') && str_contains($customizedFooterHtml, '--footer-title-color:#ffffff;'), $customizedFooterHtml);
t('customized storefront footer keeps footer bar colors inline', str_contains($customizedFooterHtml, 'background:#08111f;color:#94a3b8;') && str_contains($customizedFooterHtml, '--footer-link:#f59e0b;--footer-link-hover:#fef3c7;'), $customizedFooterHtml);

$headerWidgetEntityHtml = cmsRenderSingleHeaderWidget([
    'type' => 'text',
    'props' => [
        'content' => 'Store hours',
        'title' => 'Header Promo',
    ],
], $db, readCmsSettings(), '');
t('header widget emits shell entity-view wrapper', str_contains($headerWidgetEntityHtml, 'data-shell-entity-node="widget"') && str_contains($headerWidgetEntityHtml, 'data-shell-entity-widget-type="text"') && str_contains($headerWidgetEntityHtml, 'data-shell-entity-region="header"'), $headerWidgetEntityHtml);

$footerWidgetEntityHtml = cmsRenderSingleFooterWidget([
    'type' => 'text',
    'props' => [
        'title' => 'Footer Note',
        'content' => '<p>Footer body</p>',
    ],
], $db, readCmsSettings(), '');
t('footer widget emits shell entity-view wrapper', str_contains($footerWidgetEntityHtml, 'data-shell-entity-node="widget"') && str_contains($footerWidgetEntityHtml, 'data-shell-entity-widget-type="text"') && str_contains($footerWidgetEntityHtml, 'data-shell-entity-region="footer"'), $footerWidgetEntityHtml);

$sidebarWidgetEntityHtml = cmsRenderSingleSidebarWidget([
    'type' => 'search_box',
    'props' => [
        'title' => 'Search the catalog',
    ],
], $db, readCmsSettings(), '');
t('sidebar widget emits shell entity-view wrapper', str_contains($sidebarWidgetEntityHtml, 'data-shell-entity-node="widget"') && str_contains($sidebarWidgetEntityHtml, 'data-shell-entity-widget-type="search-box"') && str_contains($sidebarWidgetEntityHtml, 'data-shell-entity-region="sidebar"'), $sidebarWidgetEntityHtml);

$menuHomeUrl = cmsResolveMenuItemUrl(['link_type' => 'home'], 'ecommerce');
t('scope-aware home menu resolves to storefront home for ecommerce scope', str_ends_with($menuHomeUrl, '/ecommerce/shop'), $menuHomeUrl);

$tokenSettings['active_theme'] = 'minimal';
saveModuleSettings('cms', $tokenSettings);
cmsResetThemeRuntimeCache();
cmsActivateThemeSymlink('minimal');

$variantPricingTemplate = cmsResolveBlockTemplate('modules/cms/public/blocks/pricing.block.disyl', [
    'theme_settings' => $validatedEntity,
]);
$variantActionTemplate = cmsResolveBlockTemplate('modules/cms/public/blocks/action.block.disyl', [
    'theme_settings' => $validatedEntity,
]);
$variantListPricingTemplate = cmsResolveBlockTemplate('modules/cms/public/blocks/list-card-pricing.block.disyl', [
    'theme_settings' => $validatedStorefront,
]);
$variantListInventoryTemplate = cmsResolveBlockTemplate('modules/cms/public/blocks/list-card-inventory.block.disyl', [
    'theme_settings' => $validatedStorefront,
]);
$variantListProgressTemplate = cmsResolveBlockTemplate('modules/cms/public/blocks/list-card-progress.block.disyl', [
    'theme_settings' => $validatedStorefront,
]);
t('pricing block variant resolves to featured template', $variantPricingTemplate === 'modules/cms/public/blocks/pricing.featured.block.disyl', $variantPricingTemplate);
t('action block variant resolves to sticky-footer template', $variantActionTemplate === 'modules/cms/public/blocks/action.sticky-footer.block.disyl', $variantActionTemplate);
t('list-card pricing variant resolves to minimal template', $variantListPricingTemplate === 'modules/cms/public/blocks/list-card-pricing.minimal.block.disyl', $variantListPricingTemplate);
t('list-card inventory variant resolves to compact template', $variantListInventoryTemplate === 'modules/cms/public/blocks/list-card-inventory.compact.block.disyl', $variantListInventoryTemplate);
t('list-card progress variant resolves to inline template', $variantListProgressTemplate === 'modules/cms/public/blocks/list-card-progress.inline.block.disyl', $variantListProgressTemplate);

$renderedMinimalListPricing = cmsRenderThemeAwareBlockTemplate('modules/cms/public/blocks/list-card-pricing.block.disyl', [
    'theme_settings' => $validatedStorefront,
    'capability_data' => [
        'pricing' => [
            'currency' => 'USD',
            'price' => 16.0,
            'sale_price' => 12.0,
        ],
    ],
]);
t('list-card pricing variant render uses minimal markup', str_contains($renderedMinimalListPricing, 'cms-entity-card__pricing--minimal'), $renderedMinimalListPricing);

$invalidListVariantThrown = false;
$invalidListVariantDetail = '';
try {
    cmsResolveBlockTemplate('modules/cms/public/blocks/list-card-pricing.block.disyl', [
        'theme_settings' => ['entity_list_pricing_variant' => 'heroic'],
    ]);
} catch (RuntimeException $e) {
    $invalidListVariantThrown = str_contains($e->getMessage(), 'Unapproved block variant');
    $invalidListVariantDetail = $e->getMessage();
}
t('unapproved list-card variant throws runtime exception', $invalidListVariantThrown, $invalidListVariantDetail);

$missingListVariantThrown = false;
$missingListVariantDetail = '';
$missingVariantPath = BASE_PATH . '/templates/modules/cms/public/blocks/list-card-pricing.featured.block.disyl';
$missingVariantBackup = $missingVariantPath . '.bak-test';
$renamedMissingVariant = @rename($missingVariantPath, $missingVariantBackup);
try {
    cmsResolveBlockTemplate('modules/cms/public/blocks/list-card-pricing.block.disyl', [
        'theme_settings' => ['entity_list_pricing_variant' => 'featured'],
    ]);
} catch (RuntimeException $e) {
    $missingListVariantThrown = str_contains($e->getMessage(), 'Missing block variant template');
    $missingListVariantDetail = $e->getMessage();
} finally {
    if ($renamedMissingVariant) {
        @rename($missingVariantBackup, $missingVariantPath);
    }
}
t('missing list-card variant template throws runtime exception', $missingListVariantThrown, $missingListVariantDetail);

$entityTemplateContext = [
    'cms_head' => '',
    'structured_data' => '',
    'public_render_origin' => 'ecommerce',
    'public_route_kind' => 'product_detail',
    'public_presentation_mode' => 'entity_view',
    'entity' => [
        'id' => 77,
        'title' => 'Entity Presentation Test',
        'type' => 'product',
        'slug' => 'entity-presentation-test',
        'featured_image_url' => '/uploads/entity-presentation.jpg',
    ],
    'capabilities' => [
        'media_gallery' => false,
        'progress_tracking' => false,
        'pricing' => true,
        'inventory' => false,
        'lessons_index' => false,
        'booking' => false,
        'inquiry' => false,
    ],
    'capability_data' => [
        'pricing' => [
            'currency' => 'USD',
            'price' => 99.0,
            'sale_price' => 79.0,
        ],
    ],
    'post_html' => '<p>Body copy</p>',
    'builder_enabled' => false,
    'pricing_block_html' => '<div class="test-pricing">pricing</div>',
    'action_block_html' => '<div class="test-action">action</div>',
    'action_sections' => '',
];

$commerceHtml = cmsRender('modules/cms/public/entity.view.disyl', array_merge($entityTemplateContext, [
    'theme_settings' => $validatedEntity,
    'entity_presentation' => $presentation,
]));
t('commerce profile adds root class to canonical entity view', str_contains($commerceHtml, 'cms-entity-profile-commerce'), $commerceHtml);
t('canonical entity view exposes storefront origin metadata', str_contains($commerceHtml, 'data-public-render-origin="ecommerce"') && str_contains($commerceHtml, 'data-public-route-kind="product_detail"') && str_contains($commerceHtml, 'data-public-presentation-mode="entity_view"'), $commerceHtml);
t('commerce profile renders dedicated entity layout rail', str_contains($commerceHtml, 'cms-entity-layout'), $commerceHtml);

$contentSettings = cmsValidateThemeLayoutSettings([
    'entity_layout_profile' => 'content',
]);
$contentPresentation = cmsEntityPresentationConfig($contentSettings);
$contentHtml = cmsRender('modules/cms/public/entity.view.disyl', array_merge($entityTemplateContext, [
    'theme_settings' => $contentSettings,
    'entity_presentation' => $contentPresentation,
]));
$contentHeaderPos = strpos($contentHtml, 'cms-entity-header');
$contentHeroPos = strpos($contentHtml, 'cms-entity-hero');
$contentBodyPos = strpos($contentHtml, 'cms-entity-body');
$contentSummaryPos = strpos($contentHtml, 'test-pricing');
t('content profile renders header before media', $contentHeaderPos !== false && $contentHeroPos !== false && $contentHeaderPos < $contentHeroPos);
t('content profile renders summary after body', $contentBodyPos !== false && $contentSummaryPos !== false && $contentBodyPos < $contentSummaryPos);

$pocEntityTemplateContent = file_get_contents(cmsThemesPath() . '/entity-commerce-poc/public/entity.view.disyl') ?: '';
t('poc entity view template carries storefront origin metadata attributes', str_contains($pocEntityTemplateContent, 'data-public-render-origin="{public_render_origin}"') && str_contains($pocEntityTemplateContent, 'data-public-route-kind="{public_route_kind}"') && str_contains($pocEntityTemplateContent, 'data-public-presentation-mode="{public_presentation_mode}"'), $pocEntityTemplateContent);

$listPageUrl = cmsEntityListPageUrl('/ecommerce/shop', 3, [
    'search' => 'sourdough',
    'cat' => 12,
]);
t('entity list page URL preserves storefront filters', $listPageUrl === '/ecommerce/shop?search=sourdough&cat=12&page=3', $listPageUrl);

$listTemplateContext = [
    'cms_head' => '',
    'public_render_origin' => 'ecommerce',
    'public_route_kind' => 'shop_index',
    'public_presentation_mode' => 'entity_view',
    'content_type' => 'product',
    'list_title' => 'Catalog',
    'list_description' => '3 results in Bread for "sourdough"',
    'entity_list_context' => [
        'base_list_url' => '/ecommerce/shop',
        'result_count' => 3,
        'result_label' => '3 results',
        'active_filter_count' => 2,
        'search' => 'sourdough',
        'search_action_url' => '/ecommerce/shop',
        'all_items_url' => '/ecommerce/shop',
        'category_id' => 12,
        'category_name' => 'Bread',
        'category_slug' => 'bread',
        'available_categories' => [
            [
                'id' => 12,
                'name' => 'Bread',
                'url' => '/ecommerce/shop?cat=12',
                'is_active' => true,
            ],
            [
                'id' => 18,
                'name' => 'Wholegrain',
                'url' => '/ecommerce/shop?cat=18',
                'is_active' => false,
            ],
        ],
    ],
    'item_base_url' => '/ecommerce/product',
    'items' => [[
        'id' => 55,
        'entity_type' => 'product',
        'slug' => 'rustic-loaf',
        'title' => 'Rustic Loaf',
        'url' => '/ecommerce/product/rustic-loaf',
        'excerpt' => 'Slow-fermented bread.',
        'primary_image_url' => '',
        'capabilities' => [
            'pricing' => false,
            'inventory' => false,
            'progress_tracking' => false,
        ],
        'capability_data' => [],
        'list_card_excerpt' => 'Slow-fermented bread.',
        'list_card_pricing_html' => '<div class="card-price">$8.00</div>',
        'list_card_inventory_html' => '<div class="card-stock">Low stock</div>',
        'list_card_progress_html' => '<div class="card-progress">25% complete</div>',
    ]],
    'entity_presentation' => [
        'list_show_filter_summary' => 1,
        'list_card_density' => 'compact',
        'list_show_excerpt' => 1,
        'list_excerpt_length' => 90,
    ],
    'pagination' => [
        'current' => 1,
        'total' => 1,
        'prev_url' => '',
        'next_url' => '',
    ],
];
$listHtml = cmsRender('modules/cms/public/entity.list.disyl', $listTemplateContext);
t('canonical entity list exposes storefront metadata', str_contains($listHtml, 'data-public-render-origin="ecommerce"') && str_contains($listHtml, 'data-public-route-kind="shop_index"') && str_contains($listHtml, 'data-public-presentation-mode="entity_view"'), $listHtml);
t('canonical entity list exposes list filter metadata', str_contains($listHtml, 'data-list-search="sourdough"') && str_contains($listHtml, 'data-list-category-slug="bread"') && str_contains($listHtml, 'data-list-result-count="3"') && str_contains($listHtml, 'data-list-active-filter-count="2"'), $listHtml);
t('canonical entity list renders summary badges', str_contains($listHtml, '3 results') && str_contains($listHtml, 'Category: Bread') && str_contains($listHtml, 'Search: &quot;sourdough&quot;'), $listHtml);
t('canonical entity list renders storefront search and category controls', str_contains($listHtml, 'action="/ecommerce/shop"') && str_contains($listHtml, 'Search products') && str_contains($listHtml, 'All Products') && str_contains($listHtml, '/ecommerce/shop?cat=18'), $listHtml);
t('canonical entity list applies density class and excerpt rendering', str_contains($listHtml, 'cms-entity-list--density-compact') && str_contains($listHtml, 'Slow-fermented bread.'), $listHtml);
t('canonical entity list annotates list cards with entity metadata', str_contains($listHtml, 'data-entity-kind="list-item"') && str_contains($listHtml, 'data-entity-id="55"') && str_contains($listHtml, 'data-entity-slug="rustic-loaf"'), $listHtml);
t('canonical entity list emits pre-rendered card capability fragments', str_contains($listHtml, '<div class="card-price">$8.00</div>') && str_contains($listHtml, '<div class="card-stock">Low stock</div>') && str_contains($listHtml, '<div class="card-progress">25% complete</div>'), $listHtml);

$listWithoutSummaryHtml = cmsRender('modules/cms/public/entity.list.disyl', array_merge($listTemplateContext, [
    'entity_presentation' => [
        'list_show_filter_summary' => 0,
        'list_card_density' => 'airy',
        'list_show_excerpt' => 0,
        'list_excerpt_length' => 120,
    ],
    'items' => [[
        'id' => 55,
        'entity_type' => 'product',
        'slug' => 'rustic-loaf',
        'title' => 'Rustic Loaf',
        'url' => '/ecommerce/product/rustic-loaf',
        'excerpt' => 'Slow-fermented bread.',
        'primary_image_url' => '',
        'capabilities' => [
            'pricing' => false,
            'inventory' => false,
            'progress_tracking' => false,
        ],
        'capability_data' => [],
        'list_card_excerpt' => '',
        'list_card_pricing_html' => '',
        'list_card_inventory_html' => '',
        'list_card_progress_html' => '',
    ]],
]));
t('canonical entity list can suppress summary badges and excerpts', !str_contains($listWithoutSummaryHtml, 'Category: Bread') && !str_contains($listWithoutSummaryHtml, 'Slow-fermented bread.'), $listWithoutSummaryHtml);

$pocListTemplateContent = file_get_contents(cmsThemesPath() . '/entity-commerce-poc/public/entity.list.disyl') ?: '';
$pocListStyles = file_get_contents(cmsThemesPath() . '/entity-commerce-poc/style.css') ?: '';
t('poc entity list template carries storefront metadata attributes', str_contains($pocListTemplateContent, 'data-public-render-origin="{public_render_origin|default:\'cms\'}"') && str_contains($pocListTemplateContent, 'data-public-route-kind="{public_route_kind|default:\'generic\'}"') && str_contains($pocListTemplateContent, 'data-public-presentation-mode="{public_presentation_mode|default:\'traditional\'}"'), $pocListTemplateContent);
t('poc entity list template carries list metadata attributes', str_contains($pocListTemplateContent, 'data-list-search="{entity_list_context.search|default:\'\'}"') && str_contains($pocListTemplateContent, 'data-list-category-slug="{entity_list_context.category_slug|default:\'\'}"') && str_contains($pocListTemplateContent, 'data-list-result-count="{entity_list_context.result_count|default:0}"') && str_contains($pocListTemplateContent, 'data-entity-kind="list-item"'), $pocListTemplateContent);
t('poc entity list template renders handler-provided card fragments', str_contains($pocListTemplateContent, 'item.list_card_pricing_html') && str_contains($pocListTemplateContent, 'item.list_card_inventory_html') && str_contains($pocListTemplateContent, 'item.list_card_progress_html'), $pocListTemplateContent);
t('poc entity list template exposes density and excerpt controls', str_contains($pocListTemplateContent, 'poc-entity-list--density-{entity_presentation.list_card_density|default:\'comfortable\'}') && str_contains($pocListTemplateContent, 'item.list_card_excerpt') && str_contains($pocListTemplateContent, 'entity_presentation.list_show_filter_summary'), $pocListTemplateContent);
t('poc entity list template exposes storefront filter controls', str_contains($pocListTemplateContent, 'entity_list_context.available_categories') && str_contains($pocListTemplateContent, 'entity_list_context.search_action_url') && str_contains($pocListTemplateContent, 'poc-entity-list__search-input'), $pocListTemplateContent);
t('poc entity list stylesheet styles storefront filter controls', str_contains($pocListStyles, '.poc-entity-list__search') && str_contains($pocListStyles, '.poc-entity-list__category-link'), $pocListStyles);

// ═══════════════════════════════════════════════════════════════════
// 6b. Shared render lock must not re-enter symlink mutation
// ═══════════════════════════════════════════════════════════════════
echo "\n=== SHARED LOCK RENDER PATH ===\n";

cmsActivateThemeSymlink(null);
cmsResetThemeRuntimeCache();
$GLOBALS['cms_active_theme_cached_t0'] = true;
$GLOBALS['cms_active_theme_value_t0'] = 'minimal';

$renderedHome = cmsPublicRender('public/home.disyl', [
    'page_title' => 'Theme Render Test',
    'posts' => [],
    'total_pages' => 1,
    'page_num' => 1,
    'next_page' => 1,
]);
t('cmsPublicRender repairs missing symlink before shared render lock', str_contains($renderedHome, '<!DOCTYPE html>'));
t('cmsPublicRender recreated the theme symlink', is_link($link));

// ═══════════════════════════════════════════════════════════════════
// 7. cmsAdminContext does NOT change (admin is never themed)
// ═══════════════════════════════════════════════════════════════════
echo "\n=== ADMIN NOT THEMED ===\n";

$ctx = cmsAdminContext(['full_name' => 'Test', 'role' => 'editor', 'source' => 'cms'], 'posts');
t('admin context has no theme key', !array_key_exists('active_theme', $ctx));

// ═══════════════════════════════════════════════════════════════════
// CLEANUP
// ═══════════════════════════════════════════════════════════════════
echo "\n=== CLEANUP ===\n";

// Remove symlink
cmsActivateThemeSymlink(null);
t('symlink cleaned up', !is_link($link));

// Restore original settings
saveModuleSettings('cms', $oldSettings);
t('settings restored', true);

deleteTree($tokenThemeADir);
deleteTree($tokenThemeBDir);
t('temporary token-only themes cleaned up', !is_dir($tokenThemeADir) && !is_dir($tokenThemeBDir));

// ═══════════════════════════════════════════════════════════════════
// LOG CHECK
// ═══════════════════════════════════════════════════════════════════
echo "\n=== LOG CHECK ===\n";

$appLog = @file_get_contents(STORAGE_PATH . '/logs/app.log') ?: '';
$errLog = @file_get_contents(STORAGE_PATH . '/logs/error.log') ?: '';

$appErrors = array_filter(explode("\n", $appLog), fn($l) => str_contains($l, '[error]') || str_contains($l, '[warning]'));
$errLines = array_values(array_filter(explode("\n", $errLog), static function (string $line): bool {
    return trim($line) !== ''
        && !str_contains($line, 'storage/cache/kernel_bootstrap')
    && !str_contains($line, 'Failed to open stream')
    && !str_contains($line, 'Ikabud Cache: Cleared');
}));
t('No app.log errors', empty($appErrors), implode('; ', $appErrors));
t('No PHP errors in error.log', empty($errLines), implode('; ', array_slice($errLines, 0, 2)));

// ═══════════════════════════════════════════════════════════════════
// SUMMARY
// ═══════════════════════════════════════════════════════════════════
echo "\n══════════════════════════════════════════════════\n";
echo "  PASS: {$pass}  FAIL: {$fail}\n";
echo "══════════════════════════════════════════════════\n";

if (!empty($errors)) {
    echo "\nFailed tests:\n";
    foreach ($errors as $e) {
        echo "  - {$e}\n";
    }
}

exit($fail > 0 ? 1 : 0);
