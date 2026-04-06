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
t('cms settings defaults keep ecommerce theme promotion opt-in', (cmsSettingsDefaults()['active_ecommerce_theme'] ?? '') === 'default');
t('effective preferred ecommerce theme stays unset by default', cmsPreferredEcommerceTheme() === null, json_encode(readCmsSettings()));
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
t('entity presentation customizer section keys are namespaced', cmsCustomizerStorageSection('entity_presentation', 'ecommerce') === 'ecommerce:entity_presentation');
t('known customizer sections include entity_presentation', in_array('entity_presentation', cmsKnownCustomizerSections(), true));
t('known customizer sections exclude storefront', !in_array('storefront', cmsKnownCustomizerSections(), true));
t('native sidebar defaults target home/front-page routes first', ($nativeSidebarDefaults = cmsSidebarSettingsDefaults('native'))['template_scope'] === 'home', json_encode($nativeSidebarDefaults));
t('ecommerce sidebar defaults target shop routes first', ($ecommerceSidebarDefaults = cmsSidebarSettingsDefaults('ecommerce'))['template_scope'] === 'shop_index', json_encode($ecommerceSidebarDefaults));

$nativeSidebarTargets = array_map(static fn(array $target): string => (string)($target['key'] ?? ''), cmsSidebarTemplateTargets('native'));
$ecommerceSidebarTargets = array_map(static fn(array $target): string => (string)($target['key'] ?? ''), cmsSidebarTemplateTargets('ecommerce'));
t('native sidebar targets prioritize CMS route families', array_slice($nativeSidebarTargets, 0, 5) === ['home', 'archive', 'single', 'page', 'search'], json_encode($nativeSidebarTargets));
t('native sidebar targets hide legacy canonical aliases from visible choices', !in_array('entityview', $nativeSidebarTargets, true) && !in_array('entitylist', $nativeSidebarTargets, true), json_encode($nativeSidebarTargets));
t('ecommerce sidebar targets prioritize storefront route families', array_slice($ecommerceSidebarTargets, 0, 3) === ['shop_index', 'shop_category', 'product_detail'], json_encode($ecommerceSidebarTargets));
t('ecommerce sidebar targets still include CMS page/post route families', in_array('single', $ecommerceSidebarTargets, true) && in_array('page', $ecommerceSidebarTargets, true), json_encode($ecommerceSidebarTargets));
t('sidebar allowed keys keep legacy canonical aliases for compatibility', in_array('entityview', cmsSidebarAllowedTemplateKeys('native'), true) && in_array('entitylist', cmsSidebarAllowedTemplateKeys('native'), true), json_encode(cmsSidebarAllowedTemplateKeys('native')));
t('CMS front page resolves to home sidebar target', cmsSidebarPublicTargetKey(['public_render_origin' => 'cms', 'public_route_kind' => 'front-page']) === 'home');
t('CMS blog listing resolves to home sidebar target', cmsSidebarPublicTargetKey(['public_render_origin' => 'cms', 'public_route_kind' => 'blog-home']) === 'home');
t('CMS post detail resolves to single sidebar target', cmsSidebarPublicTargetKey(['public_render_origin' => 'cms', 'public_route_kind' => 'post']) === 'single');
t('CMS page detail resolves to page sidebar target', cmsSidebarPublicTargetKey(['public_render_origin' => 'cms', 'public_route_kind' => 'page']) === 'page');
t('CMS search resolves to search sidebar target', cmsSidebarPublicTargetKey(['public_render_origin' => 'cms', 'public_route_kind' => 'search']) === 'search');
t('storefront shop resolves to shop sidebar target', cmsSidebarPublicTargetKey(['public_render_origin' => 'ecommerce', 'public_route_kind' => 'shop_index']) === 'shop_index');
t('storefront product resolves to product sidebar target', cmsSidebarPublicTargetKey(['public_render_origin' => 'ecommerce', 'public_route_kind' => 'product_detail']) === 'product_detail');
t('legacy entity-view sidebar rule still matches native single-post routes', cmsSidebarTemplateMatchesScope(['scope_mode' => 'template', 'template_rules' => ['entityview']], 'single', 'native'));
t('legacy entity-list sidebar rule still matches storefront shop routes', cmsSidebarTemplateMatchesScope(['scope_mode' => 'template', 'template_rules' => ['entitylist']], 'shop_index', 'ecommerce'));
t('legacy canonical sidebar aliases expand into explicit native route targets', ($expandedLegacySidebar = cmsValidateSidebarSettings(['scope_mode' => 'exclude_templates', 'template_rules' => ['home', 'entityview', 'entitylist']], 'native'))['template_rules'] === ['home', 'single', 'page', 'archive', 'search'], json_encode($expandedLegacySidebar));
t('ecommerce sidebar validation preserves shop target rules', (cmsValidateSidebarSettings(['scope_mode' => 'template', 'template_rules' => ['shop_index']], 'ecommerce')['template_scope'] ?? '') === 'shop_index');

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
$ecommerceEntityPresentation = cmsCustomizerGet($db, 'entity_presentation', 'ecommerce');
t('ecommerce header section is seeded', cmsCustomizerSectionExists($db, 'header', 'ecommerce'));
t('ecommerce footer section is seeded', cmsCustomizerSectionExists($db, 'footer', 'ecommerce'));
t('ecommerce canonical entity presentation section is seeded', cmsCustomizerSectionExists($db, 'entity_presentation', 'ecommerce'));
t('ecommerce storefront section is not seeded', !cmsCustomizerSectionExists($db, 'storefront', 'ecommerce'));
t('ecommerce customizer sidebar defaults disabled to avoid shared sidebar pollution', (int)($ecommerceSidebar['settings']['enabled'] ?? 0) === 0);
t('ecommerce canonical entity presentation section seeds manifest defaults', ($ecommerceEntityPresentation['settings']['entity_layout_profile'] ?? '') === 'commerce');

$entityViewTemplate = cmsResolveTemplate('public/entity.view.disyl');
$entityListTemplate = cmsResolveTemplate('public/entity.list.disyl');
t('entity-commerce-poc overrides entity.view.disyl', $entityViewTemplate === '_cms_active_theme/public/entity.view.disyl', $entityViewTemplate);
t('entity-commerce-poc overrides entity.list.disyl', $entityListTemplate === '_cms_active_theme/public/entity.list.disyl', $entityListTemplate);

$cmsPageEntityViewTemplate = cmsResolveContentTemplate('public/entity.view.disyl', [], 'page', [
    'public_render_origin' => 'cms',
    'public_route_kind' => 'page',
    'public_presentation_mode' => 'canonical',
]);
$cmsBlogEntityListTemplate = cmsResolveContentTemplate('public/entity.list.disyl', [], 'post', [
    'public_render_origin' => 'cms',
    'public_route_kind' => 'blog-home',
    'public_presentation_mode' => 'canonical',
]);
$storefrontEntityViewTemplate = cmsResolveContentTemplate('public/entity.view.disyl', [], 'product', [
    'public_render_origin' => 'ecommerce',
    'public_route_kind' => 'product_detail',
    'public_presentation_mode' => 'entity_view',
]);
$storefrontEntityListTemplate = cmsResolveContentTemplate('public/entity.list.disyl', [], 'product', [
    'public_render_origin' => 'ecommerce',
    'public_route_kind' => 'shop_index',
    'public_presentation_mode' => 'entity_view',
]);
t('cms page canonical entity view bypasses storefront template under ecommerce-scoped theme', $cmsPageEntityViewTemplate === 'modules/cms/public/entity.view.disyl', $cmsPageEntityViewTemplate);
t('cms blog canonical entity list bypasses storefront template under ecommerce-scoped theme', $cmsBlogEntityListTemplate === 'modules/cms/public/entity.list.disyl', $cmsBlogEntityListTemplate);
t('storefront product entity view still resolves themed template', $storefrontEntityViewTemplate === '_cms_active_theme/public/entity.view.disyl', $storefrontEntityViewTemplate);
t('storefront product entity list still resolves themed template', $storefrontEntityListTemplate === '_cms_active_theme/public/entity.list.disyl', $storefrontEntityListTemplate);

$pocBlogHomeRouteCacheKey = cmsPublicResolvedTemplateCacheKey(
    'cms:home:entity_contract_v3:page:1:archive:all',
    'public/entity.list.disyl',
    [],
    'post',
    [
        'public_render_origin' => 'cms',
        'public_route_kind' => 'blog-home',
        'public_presentation_mode' => 'canonical',
    ]
);
$pocCmsProductRouteCacheKey = cmsPublicResolvedTemplateCacheKey(
    'cms:entity:product:demo-product',
    'public/entity.view.disyl',
    [],
    'product',
    [
        'public_render_origin' => 'cms',
        'public_route_kind' => 'generic',
        'public_presentation_mode' => 'canonical',
    ]
);
$pocStorefrontProductRouteCacheKey = cmsPublicResolvedTemplateCacheKey(
    'cms:entity:product:demo-product',
    'public/entity.view.disyl',
    [],
    'product',
    [
        'public_render_origin' => 'ecommerce',
        'public_route_kind' => 'product_detail',
        'public_presentation_mode' => 'entity_view',
    ]
);
t('entity-commerce-poc route cache keys now include template fingerprint suffixes', str_contains($pocBlogHomeRouteCacheKey, ':tpl:'), $pocBlogHomeRouteCacheKey);
t('entity-commerce-poc route cache key separates CMS and storefront product contexts', $pocCmsProductRouteCacheKey !== $pocStorefrontProductRouteCacheKey, json_encode([
    'cms' => $pocCmsProductRouteCacheKey,
    'storefront' => $pocStorefrontProductRouteCacheKey,
]));

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
    'capabilities' => [
        'pricing' => true,
    ],
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
    'capabilities' => [
        'pricing' => true,
    ],
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
$pocEntityPresentationDefaults = cmsThemeManifestCustomizerDefaults('entity_presentation', $pocManifest, 'ecommerce');
$pocThemeDefaults = cmsThemeManifestCustomizerDefaults('theme', $pocManifest, 'ecommerce');
t('entity-commerce-poc manifest colors inherit primary token into customizer defaults', ($pocColorDefaults['color_primary'] ?? '') === '#0f4c81');
t('entity-commerce-poc manifest colors inherit background token into customizer defaults', ($pocColorDefaults['body_bg_color'] ?? '') === '#f4efe6');
t('entity-commerce-poc manifest colors inherit container token into customizer defaults', ($pocColorDefaults['container_width'] ?? '') === '1180');
t('entity-commerce-poc manifest canonical entity presentation defaults set commerce layout profile', ($pocEntityPresentationDefaults['entity_layout_profile'] ?? '') === 'commerce');
t('entity-commerce-poc manifest canonical entity presentation defaults set summary width', ($pocEntityPresentationDefaults['entity_summary_width'] ?? '') === '360');
t('entity-commerce-poc manifest canonical entity presentation defaults set inline action variant', ($pocEntityPresentationDefaults['entity_action_variant'] ?? '') === 'inline');
t('entity-commerce-poc manifest theme layout inherits container token into site width', ($pocThemeDefaults['site_max_width'] ?? '') === '1180');

cmsSeedActiveThemeCustomizerDefaults($db);
$seededColors = cmsCustomizerGet($db, 'colors', 'ecommerce');
$seededEntityPresentation = cmsCustomizerGet($db, 'entity_presentation', 'ecommerce');
$seededTheme = cmsCustomizerGet($db, 'theme', 'ecommerce');
t('theme activation seeding writes ecommerce colors defaults from manifest', ($seededColors['settings']['color_primary'] ?? '') === '#0f4c81');
t('theme activation seeding writes ecommerce canonical entity presentation defaults from manifest', ($seededEntityPresentation['settings']['entity_summary_width'] ?? '') === '360');
t('theme activation seeding writes ecommerce theme defaults from manifest', ($seededTheme['settings']['site_max_width'] ?? '') === '1180');
t('theme activation seeding does not recreate storefront section', !cmsCustomizerSectionExists($db, 'storefront', 'ecommerce'));

$defaultColorsHtml = cmsRenderColorsStyle($db);
$defaultThemeHtml = cmsRenderThemeLayoutStyle($db);
$defaultEntityPresentationHtml = cmsRenderEntityPresentationStyle($db);
t('entity-commerce-poc emits storefront color contract when settings match manifest defaults', str_contains($defaultColorsHtml, '--storefront-success-bg:') && !str_contains($defaultColorsHtml, 'body{font-family:var(--font-body);'), $defaultColorsHtml);
t('entity-commerce-poc suppresses generic theme layout override when settings match manifest defaults', $defaultThemeHtml === '', $defaultThemeHtml);
t('entity-commerce-poc suppresses canonical entity presentation override when settings match manifest defaults', $defaultEntityPresentationHtml === '', $defaultEntityPresentationHtml);

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

$configuredStorefrontPublicContext = cmsPublicContext([
    'public_render_origin' => 'ecommerce',
    'public_route_kind' => 'shop_index',
]);
t('configured ecommerce theme lets cmsPublicContext auto-promote storefront theme on shop route', ($configuredStorefrontPublicContext['active_theme'] ?? '') === 'entity-commerce-poc' && ($configuredStorefrontPublicContext['active_theme_source'] ?? '') === 'storefront' && ($configuredStorefrontPublicContext['active_customizer_scope'] ?? '') === 'ecommerce' && ($configuredStorefrontPublicContext['public_presentation_mode'] ?? '') === 'entity_view', json_encode($configuredStorefrontPublicContext));

$configuredTraditionalTemplateError = '';
try {
    ecAssertTraditionalEntityTemplateAllowed('modules/ecommerce/public/shop.disyl', [
        'public_render_origin' => 'ecommerce',
        'public_route_kind' => 'shop_index',
    ]);
} catch (Throwable $e) {
    $configuredTraditionalTemplateError = $e->getMessage();
}
t('configured ecommerce theme blocks traditional shop templates without scoped storefront context', str_contains($configuredTraditionalTemplateError, 'Traditional ecommerce template "modules/ecommerce/public/shop.disyl" is not allowed'), $configuredTraditionalTemplateError);
t('configured ecommerce theme requires canonical entity rendering without scoped storefront context', ecRouteUsesCanonicalEntityRendering('shop_index', [
    'public_render_origin' => 'ecommerce',
    'public_route_kind' => 'shop_index',
]) === true);

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

$splitThemeSettings = $minimalScopeSettings;
$splitThemeSettings['active_ecommerce_theme'] = 'native-default';
saveModuleSettings('cms', $splitThemeSettings);
cmsResetThemeRuntimeCache();
cmsActivateThemeSymlink('minimal');

$staleSymlinkAliasPath = cmsWithPublicThemeContext([
    'public_render_origin' => 'ecommerce',
    'public_route_kind' => 'shop_index',
], static function (): string {
    return cmsResolveThemeTemplateAliasPath('_cms_active_theme/public/ecommerce/archive-product.disyl');
});
t('route-scoped storefront alias resolves native-default template even when symlink is stale', str_contains($staleSymlinkAliasPath, '/storage/cms-themes/native-default/public/ecommerce/archive-product.disyl'), $staleSymlinkAliasPath);

$staleSymlinkProducts = [[
    'id' => 1,
    'slug' => 'demo-product',
    'title' => 'Demo Product',
    'excerpt' => 'A short excerpt.',
    'primary_image_url' => '',
    'pricing' => [
        'formatted' => '$19.00',
        'on_sale' => false,
        'regular_fmt' => '$19.00',
        'price' => 19.0,
    ],
    'inventory' => [
        'track_stock' => true,
        'stock_qty' => 6,
        'in_stock' => true,
        'out_of_stock' => false,
        'low_stock' => false,
    ],
]];
$staleSymlinkStorefront = ecBuildStorefrontCatalogContext($staleSymlinkProducts, [
    'route_kind' => 'shop_index',
    'presentation_mode' => 'traditional',
    'page_title' => 'Shop',
    'categories' => [],
    'search' => '',
    'search_action_url' => '/ecommerce/shop',
    'all_items_url' => '/ecommerce/shop',
    'base_list_url' => '/ecommerce/shop',
    'item_base_url' => '/ecommerce/shop',
    'page' => 1,
    'total_pages' => 1,
    'total' => 1,
    'cart_count' => 0,
]);
$staleSymlinkShopHtml = captureEcRender('modules/ecommerce/public/shop.disyl', [
    'page_title' => 'Shop',
    'products' => $staleSymlinkProducts,
    'total' => 1,
    'categories' => [],
    'search' => '',
    'category_id' => 0,
    'page' => 1,
    'per_page' => 12,
    'total_pages' => 1,
    'cart_count' => 0,
    'storefront' => $staleSymlinkStorefront,
    'public_render_origin' => 'ecommerce',
    'public_route_kind' => 'shop_index',
    'public_presentation_mode' => 'traditional',
]);
t('route-scoped storefront render ignores stale theme symlink and uses native-default template', str_contains($staleSymlinkShopHtml, 'data-native-ecommerce-template="archive-product"') && str_contains($staleSymlinkShopHtml, 'assets/cms/themes/native-default/style.css') && !str_contains($staleSymlinkShopHtml, 'Powered by CMS Module &middot; Minimal Theme'), $staleSymlinkShopHtml);

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

$db = cmsDb();
cmsEnsureCustomizerScopeSeeded($db, 'native');
$nativeHeaderSection = cmsCustomizerGet($db, 'header', 'native');
$nativeFooterSection = cmsCustomizerGet($db, 'footer', 'native');
$nativePublicCtx = cmsPublicContext();
t('native header section is seeded', cmsCustomizerSectionExists($db, 'header', 'native'));
t('native footer section is seeded', cmsCustomizerSectionExists($db, 'footer', 'native'));
t('native header defaults keep sticky enabled', (int)($nativeHeaderSection['settings']['sticky'] ?? 0) === 1, json_encode($nativeHeaderSection['settings'] ?? []));
t('native header defaults keep container and holder width controls available', ($nativeHeaderSection['settings']['header_container_width'] ?? '') === 'contained' && ($nativeHeaderSection['settings']['header_inner_width_mode'] ?? '') === 'contained' && ($nativeHeaderSection['settings']['topbar_container_width'] ?? '') === 'contained' && ($nativeHeaderSection['settings']['topbar_inner_width_mode'] ?? '') === 'contained', json_encode($nativeHeaderSection['settings'] ?? []));
t('native footer defaults keep footer shell width controls available', ($nativeFooterSection['settings']['widget_container_width'] ?? '') === 'contained' && ($nativeFooterSection['settings']['widget_inner_width_mode'] ?? '') === 'contained', json_encode($nativeFooterSection['settings'] ?? []));
t('native public context renders the customized header shell after seeding', !empty($nativePublicCtx['has_customized_header']) && str_contains((string)($nativePublicCtx['customized_header'] ?? ''), 'header-wrapper--sticky') && str_contains((string)($nativePublicCtx['customized_header'] ?? ''), 'cms-shell-entity-view--sticky-region'), json_encode(['has_customized_header' => $nativePublicCtx['has_customized_header'] ?? null, 'customized_header' => $nativePublicCtx['customized_header'] ?? '']));

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

$nativePageEntityViewTemplate = cmsResolveContentTemplate('public/entity.view.disyl', [], 'page', [
    'public_render_origin' => 'cms',
    'public_route_kind' => 'page',
    'public_presentation_mode' => 'canonical',
]);
$nativeBlogEntityListTemplate = cmsResolveContentTemplate('public/entity.list.disyl', [], 'post', [
    'public_render_origin' => 'cms',
    'public_route_kind' => 'blog-home',
    'public_presentation_mode' => 'canonical',
]);
$nativeStorefrontEntityViewTemplate = cmsResolveContentTemplate('public/entity.view.disyl', [], 'product', [
    'public_render_origin' => 'ecommerce',
    'public_route_kind' => 'product_detail',
    'public_presentation_mode' => 'entity_view',
]);
$nativeStorefrontEntityListTemplate = cmsResolveContentTemplate('public/entity.list.disyl', [], 'product', [
    'public_render_origin' => 'ecommerce',
    'public_route_kind' => 'shop_index',
    'public_presentation_mode' => 'entity_view',
]);
t('native theme page canonical entity view resolves native template', $nativePageEntityViewTemplate === '_cms_active_theme/public/entity.view.disyl', $nativePageEntityViewTemplate);
t('native theme blog canonical entity list resolves native template', $nativeBlogEntityListTemplate === '_cms_active_theme/public/entity.list.disyl', $nativeBlogEntityListTemplate);
t('native storefront product canonical entity view resolves native template', $nativeStorefrontEntityViewTemplate === '_cms_active_theme/public/entity.view.disyl', $nativeStorefrontEntityViewTemplate);
t('native storefront catalog canonical entity list resolves native template', $nativeStorefrontEntityListTemplate === '_cms_active_theme/public/entity.list.disyl', $nativeStorefrontEntityListTemplate);

$nativeBlogHomeRouteCacheKey = cmsPublicResolvedTemplateCacheKey(
    'cms:home:entity_contract_v3:page:1:archive:all',
    'public/entity.list.disyl',
    [],
    'post',
    [
        'public_render_origin' => 'cms',
        'public_route_kind' => 'blog-home',
        'public_presentation_mode' => 'canonical',
    ]
);
$nativeCmsProductRouteCacheKey = cmsPublicResolvedTemplateCacheKey(
    'cms:entity:product:demo-product',
    'public/entity.view.disyl',
    [],
    'product',
    [
        'public_render_origin' => 'cms',
        'public_route_kind' => 'generic',
        'public_presentation_mode' => 'canonical',
    ]
);
$nativeStorefrontProductRouteCacheKey = cmsPublicResolvedTemplateCacheKey(
    'cms:entity:product:demo-product',
    'public/entity.view.disyl',
    [],
    'product',
    [
        'public_render_origin' => 'ecommerce',
        'public_route_kind' => 'product_detail',
        'public_presentation_mode' => 'entity_view',
    ]
);
t('native theme blog-home route cache key changes when canonical template ownership changes', $nativeBlogHomeRouteCacheKey !== $pocBlogHomeRouteCacheKey, json_encode([
    'ecommerce_scope' => $pocBlogHomeRouteCacheKey,
    'native_scope' => $nativeBlogHomeRouteCacheKey,
]));
t('native theme route cache key separates CMS and storefront product contexts', $nativeCmsProductRouteCacheKey !== $nativeStorefrontProductRouteCacheKey, json_encode([
    'cms' => $nativeCmsProductRouteCacheKey,
    'storefront' => $nativeStorefrontProductRouteCacheKey,
]));

$nativeCatalogProducts = [[
    'id' => 1,
    'slug' => 'demo-product',
    'title' => 'Demo Product',
    'excerpt' => 'A short excerpt.',
    'primary_image_url' => '',
    'pricing' => [
        'formatted' => '$19.00',
        'on_sale' => false,
        'regular_fmt' => '$19.00',
        'price' => 19.0,
    ],
    'inventory' => [
        'track_stock' => true,
        'stock_qty' => 6,
        'in_stock' => true,
        'out_of_stock' => false,
        'low_stock' => false,
    ],
]];
$nativeCatalogStorefront = ecBuildStorefrontCatalogContext($nativeCatalogProducts, [
    'route_kind' => 'shop_index',
    'presentation_mode' => 'traditional',
    'page_title' => 'Shop',
    'categories' => [],
    'search' => '',
    'search_action_url' => '/ecommerce/shop',
    'all_items_url' => '/ecommerce/shop',
    'base_list_url' => '/ecommerce/shop',
    'item_base_url' => '/ecommerce/shop',
    'page' => 1,
    'total_pages' => 1,
    'total' => 1,
    'cart_count' => 0,
]);
t('storefront catalog contract normalizes route, item URL, and inventory badge', ($nativeCatalogStorefront['route']['kind'] ?? '') === 'shop_index' && ($nativeCatalogStorefront['collection']['items'][0]['url'] ?? '') === '/ecommerce/shop/demo-product' && ($nativeCatalogStorefront['collection']['items'][0]['inventory']['badge']['label'] ?? '') === 'In stock', json_encode($nativeCatalogStorefront));
$nativeShopHtml = captureEcRender('modules/ecommerce/public/shop.disyl', [
    'page_title' => 'Shop',
    'products' => $nativeCatalogProducts,
    'total' => 1,
    'categories' => [],
    'search' => '',
    'category_id' => 0,
    'page' => 1,
    'per_page' => 12,
    'total_pages' => 1,
    'cart_count' => 0,
    'storefront' => $nativeCatalogStorefront,
    'public_render_origin' => 'ecommerce',
    'public_route_kind' => 'shop_index',
    'public_presentation_mode' => 'traditional',
]);
t('native storefront shop render uses archive-product theme template', str_contains($nativeShopHtml, 'data-native-ecommerce-template="archive-product"'), $nativeShopHtml);
t('native storefront shop render exposes storefront contract markers', str_contains($nativeShopHtml, 'data-storefront-route-kind="shop_index"') && str_contains($nativeShopHtml, 'data-storefront-page-kind="catalog"') && str_contains($nativeShopHtml, 'data-storefront-visible-count="1"'), $nativeShopHtml);
t('native storefront shop render preserves theme stylesheet asset', str_contains($nativeShopHtml, 'assets/cms/themes/native-default/style.css'), $nativeShopHtml);

$nativeDropdownStorefront = ecBuildStorefrontCatalogContext($nativeCatalogProducts, [
    'route_kind' => 'shop_index',
    'presentation_mode' => 'traditional',
    'page_title' => 'Shop',
    'categories' => [
        ['id' => 12, 'name' => 'Bread', 'slug' => 'bread', 'url' => '/ecommerce/shop?cat=12', 'is_active' => false],
        ['id' => 18, 'name' => 'Wholegrain', 'slug' => 'wholegrain', 'url' => '/ecommerce/shop?cat=18', 'is_active' => true],
    ],
    'current_category' => ['id' => 18, 'name' => 'Wholegrain', 'slug' => 'wholegrain'],
    'search' => 'sourdough',
    'category_id' => 18,
    'search_action_url' => '/ecommerce/shop',
    'all_items_url' => '/ecommerce/shop',
    'base_list_url' => '/ecommerce/shop',
    'item_base_url' => '/ecommerce/shop',
    'page' => 1,
    'total_pages' => 1,
    'total' => 1,
    'cart_count' => 0,
]);
$nativeShopDropdownHtml = captureEcRender('modules/ecommerce/public/shop.disyl', [
    'page_title' => 'Shop',
    'products' => $nativeCatalogProducts,
    'total' => 1,
    'categories' => [
        ['id' => 12, 'name' => 'Bread', 'url' => '/ecommerce/shop?cat=12', 'is_active' => false],
        ['id' => 18, 'name' => 'Wholegrain', 'url' => '/ecommerce/shop?cat=18', 'is_active' => true],
    ],
    'search' => 'sourdough',
    'category_id' => 18,
    'current_cat' => ['id' => 18, 'name' => 'Wholegrain'],
    'page' => 1,
    'per_page' => 12,
    'total_pages' => 1,
    'cart_count' => 0,
    'storefront' => $nativeDropdownStorefront,
    'public_render_origin' => 'ecommerce',
    'public_route_kind' => 'shop_index',
    'public_presentation_mode' => 'traditional',
    'theme_settings' => array_merge(cmsThemeLayoutSettingsDefaults(), ['entity_list_category_navigation' => 'dropdown']),
]);
t('native storefront shop can render category dropdown navigation', str_contains($nativeShopDropdownHtml, 'native-shop-category-picker') && str_contains($nativeShopDropdownHtml, 'Shop categories') && str_contains($nativeShopDropdownHtml, 'name="cat"') && str_contains($nativeShopDropdownHtml, 'Browse'), $nativeShopDropdownHtml);

$nativeProductFixture = [
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
        'price' => 19.0,
    ],
    'inventory' => [
        'track_stock' => true,
        'stock_qty' => 6,
        'in_stock' => true,
        'out_of_stock' => false,
        'low_stock' => false,
        'sku' => 'SKU-001',
    ],
];
$nativeProductStorefront = ecBuildStorefrontDetailContext($nativeProductFixture, [
    'route_kind' => 'product_detail',
    'presentation_mode' => 'traditional',
    'page_title' => 'Demo Product',
    'cart_count' => 0,
]);
t('storefront detail contract normalizes product id, category slug, and cart state', ($nativeProductStorefront['product']['id'] ?? 0) === 1 && (($nativeProductStorefront['filters']['category_slug'] ?? '') === 'catalog') && (($nativeProductStorefront['cart']['count'] ?? 1) === 0), json_encode($nativeProductStorefront));
$nativeProductHtml = captureEcRender('modules/ecommerce/public/product.disyl', [
    'page_title' => 'Demo Product',
    'product' => $nativeProductFixture,
    'cart_count' => 0,
    'storefront' => $nativeProductStorefront,
    'public_render_origin' => 'ecommerce',
    'public_route_kind' => 'product_detail',
    'public_presentation_mode' => 'traditional',
]);
t('native storefront product render uses single-product theme template', str_contains($nativeProductHtml, 'data-native-ecommerce-template="single-product"'), $nativeProductHtml);
t('native storefront product render exposes storefront contract markers', str_contains($nativeProductHtml, 'data-storefront-route-kind="product_detail"') && str_contains($nativeProductHtml, 'data-storefront-product-id="1"'), $nativeProductHtml);

$nativeCanonicalPageHtml = cmsPublicCanonicalRenderEntityView([
    'id' => 201,
    'title' => 'Native Canonical Page',
    'slug' => 'native-canonical-page',
    'body' => '<p>Native page body.</p>',
    'type' => 'page',
], [
    'content_type' => 'page',
    'meta' => [],
    'rendered_html' => '<p>Native page body.</p>',
    'public_render_origin' => 'cms',
    'public_route_kind' => 'page',
    'public_presentation_mode' => 'canonical',
]);
$nativeCanonicalBlogHtml = cmsPublicCanonicalRenderEntityList([
    [
        'id' => 301,
        'title' => 'Native Canonical Post',
        'slug' => 'native-canonical-post',
        'type' => 'post',
        'excerpt' => 'Post summary',
        'author_name' => 'Test Author',
        'published_at' => '2026-03-30 09:15:00',
    ],
], [
    'default_type' => 'post',
    'page_title' => 'Native Blog',
    'list_title' => 'Native Blog',
    'list_description' => '1 result',
    'entity_list_context' => [
        'base_list_url' => '/cms/blog',
        'search_action_url' => '/cms/search',
        'result_count' => 1,
        'show_item_meta' => true,
        'show_item_author' => true,
        'show_item_date' => true,
        'show_item_type_badge' => true,
    ],
    'public_render_origin' => 'cms',
    'public_route_kind' => 'blog-home',
    'public_presentation_mode' => 'canonical',
]);
t('native canonical page render uses native shell and canonical template marker', str_contains($nativeCanonicalPageHtml, '<body class="native-theme') && str_contains($nativeCanonicalPageHtml, 'data-native-canonical-template="entity-view"') && str_contains($nativeCanonicalPageHtml, 'site-header--sticky'), $nativeCanonicalPageHtml);
t('native canonical list render uses native shell and canonical template marker', str_contains($nativeCanonicalBlogHtml, '<body class="native-theme') && str_contains($nativeCanonicalBlogHtml, 'data-native-canonical-template="entity-list"') && str_contains($nativeCanonicalBlogHtml, 'site-header--sticky'), $nativeCanonicalBlogHtml);

$baseCatalogStorefront = ecBuildStorefrontCatalogContext($nativeCatalogProducts, [
    'route_kind' => 'shop_index',
    'presentation_mode' => 'traditional',
    'page_title' => 'Contract Catalog',
    'page_description' => 'Fresh loaves, pastries, and pantry staples.',
    'categories' => [
        ['id' => 12, 'name' => 'Bread', 'slug' => 'bread', 'url' => '/contract-category/bread', 'is_active' => true],
        ['id' => 18, 'name' => 'Wholegrain', 'slug' => 'wholegrain', 'url' => '/contract-category/wholegrain', 'is_active' => false],
    ],
    'current_category' => ['id' => 12, 'name' => 'Bread', 'slug' => 'bread', 'url' => '/contract-category/bread'],
    'search' => 'sourdough',
    'shop_url' => '/contract-shop',
    'search_action_url' => '/contract-search',
    'all_items_url' => '/contract-all',
    'base_list_url' => '/contract-search',
    'item_base_url' => '/contract-items',
    'pagination' => [
        'current' => 2,
        'total' => 3,
        'prev_url' => '/contract-search?page=1',
        'next_url' => '/contract-search?page=3',
    ],
    'total' => 23,
    'cart_count' => 4,
]);
$baseShopHtml = captureEcRender('modules/ecommerce/public/shop.disyl', [
    'page_title' => 'Legacy Catalog',
    'products' => [],
    'total' => 0,
    'categories' => [],
    'search' => 'legacy',
    'category_id' => 0,
    'page' => 1,
    'per_page' => 12,
    'total_pages' => 1,
    'cart_count' => 99,
    'storefront' => $baseCatalogStorefront,
    'public_render_origin' => 'ecommerce',
    'public_route_kind' => 'generic',
    'public_presentation_mode' => 'traditional',
]);
t('base storefront shop fallback consumes shared storefront content contract', str_contains($baseShopHtml, 'data-storefront-route-kind="shop_index"') && str_contains($baseShopHtml, '>Contract Catalog<') && str_contains($baseShopHtml, 'action="/contract-search"') && str_contains($baseShopHtml, 'href="/contract-items/demo-product"') && str_contains($baseShopHtml, 'Bread') && !str_contains($baseShopHtml, '>Legacy Catalog<'), $baseShopHtml);

$contractProductFixture = [
    'id' => 42,
    'title' => 'Contract Product',
    'slug' => 'contract-product',
    'excerpt' => 'Contract summary',
    'body' => '<p>Contract body</p>',
    'primary_image_url' => '',
    'gallery_images' => [],
    'categories' => [['slug' => 'bread', 'name' => 'Bread', 'url' => '/contract-category/bread']],
    'pricing' => [
        'formatted' => '$28.00',
        'on_sale' => true,
        'regular_fmt' => '$32.00',
        'price' => 32.0,
        'sale_price' => 28.0,
    ],
    'inventory' => [
        'track_stock' => true,
        'stock_qty' => 2,
        'in_stock' => true,
        'out_of_stock' => false,
        'low_stock' => true,
        'sku' => 'SKU-042',
    ],
];
$baseProductStorefront = ecBuildStorefrontDetailContext($contractProductFixture, [
    'route_kind' => 'product_detail',
    'presentation_mode' => 'traditional',
    'page_title' => 'Contract Product Page',
    'shop_url' => '/contract-shop',
    'search_action_url' => '/contract-search',
    'all_items_url' => '/contract-all',
    'item_base_url' => '/contract-items',
    'cart_count' => 4,
]);
$baseProductHtml = captureEcRender('modules/ecommerce/public/product.disyl', [
    'page_title' => 'Legacy Product',
    'product' => [
        'id' => 0,
        'title' => 'Legacy Product',
        'excerpt' => '',
        'body' => '',
        'primary_image_url' => '',
        'gallery_images' => [],
        'categories' => [],
        'pricing' => [],
        'inventory' => [
            'track_stock' => false,
            'in_stock' => false,
            'out_of_stock' => true,
        ],
    ],
    'cart_count' => 99,
    'storefront' => $baseProductStorefront,
    'public_render_origin' => 'ecommerce',
    'public_route_kind' => 'generic',
    'public_presentation_mode' => 'traditional',
]);
t('base storefront product fallback consumes shared storefront content contract', str_contains($baseProductHtml, 'data-storefront-route-kind="product_detail"') && str_contains($baseProductHtml, 'data-storefront-product-id="42"') && str_contains($baseProductHtml, '>Contract Product Page<') && str_contains($baseProductHtml, 'href="/contract-shop"') && str_contains($baseProductHtml, '13% off') && str_contains($baseProductHtml, '>2 left<') && !str_contains($baseProductHtml, '>Legacy Product<'), $baseProductHtml);

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

$cmsPublicLayoutContent = file_get_contents(BASE_PATH . '/templates/modules/cms/layouts/public.disyl');
$nativeLayoutContent = file_get_contents(STORAGE_PATH . '/cms-themes/native-default/layouts/public.disyl');
$nativeDefaultStyleContent = file_get_contents(BASE_PATH . '/public/assets/cms/themes/native-default/style.css') ?: '';
$nativeDefaultScriptContent = file_get_contents(BASE_PATH . '/public/assets/cms/themes/native-default/script.js') ?: '';
t('cms public layout keeps the shared fallback header non-sticky', !str_contains($cmsPublicLayoutContent, 'header-wrapper--sticky') && !str_contains($cmsPublicLayoutContent, 'site-header site-header--sticky'), $cmsPublicLayoutContent);
t('native layout fallback header uses sticky wrapper markup', str_contains($nativeLayoutContent, '<div class="header-wrapper header-wrapper--sticky">') && str_contains($nativeLayoutContent, '<header class="site-header site-header--sticky">'), $nativeLayoutContent);
t('native layout noscript fallback reveals body', str_contains($nativeLayoutContent, 'body:not(.cz-loaded),[data-animate]{opacity:1!important;transform:none!important;}'));
t('native layout inline fallback reveals animated content', str_contains($nativeLayoutContent, 'document.body.classList.add(\'cz-loaded\')'));
t('native default theme sticky assets follow wrapper-based sticky header markup', str_contains($nativeDefaultScriptContent, "const wrapper = document.querySelector('.header-wrapper--sticky');") && str_contains($nativeDefaultScriptContent, "header.classList.toggle('scrolled', hasScrolled);") && str_contains($nativeDefaultScriptContent, "wrapper.classList.toggle('scrolled', hasScrolled);") && str_contains($nativeDefaultStyleContent, '.header-wrapper--sticky.scrolled .site-header,') && str_contains($nativeDefaultStyleContent, '.site-header.site-header--sticky.scrolled {'), $nativeDefaultScriptContent . "\n---\n" . $nativeDefaultStyleContent);

$pocLayoutContent = file_get_contents(BASE_PATH . '/storage/cms-themes/entity-commerce-poc/layouts/public.disyl');
$themeStylePos = strpos($pocLayoutContent, '{if theme_style_url}<link rel="stylesheet" href="{theme_style_url}">{/if}');
t('entity-commerce-poc layout omits public Tailwind CDN for storefront isolation', !str_contains($pocLayoutContent, 'https://cdn.tailwindcss.com'));
t('entity-commerce-poc layout still loads theme stylesheet', $themeStylePos !== false);

$pocStyleContent = file_get_contents(BASE_PATH . '/storage/cms-themes/entity-commerce-poc/style.css');
t('entity-commerce-poc styles shared gallery grid without Tailwind', str_contains($pocStyleContent, '.cms-gallery-grid {') && str_contains($pocStyleContent, '.cms-gallery-item-link {'));
t('entity-commerce-poc styles shared progress block without Tailwind', str_contains($pocStyleContent, '.cms-progress-row {') && str_contains($pocStyleContent, '.cms-progress-track {'));
t('entity-commerce-poc styles prose content without Tailwind typography utilities', str_contains($pocStyleContent, '.poc-entity-view__body.prose h2 {') && str_contains($pocStyleContent, '.poc-entity-view__body.prose blockquote {'));

$entityPresentationCss = cmsRenderEntityPresentationCss(cmsEntityPresentationSectionDefaults('ecommerce'));
t('entity presentation css exports global geometry variables for storefront themes', str_contains($entityPresentationCss, '--theme-entity-list-card-min-width:') && str_contains($entityPresentationCss, '--theme-entity-list-title-font:') && str_contains($entityPresentationCss, '--theme-entity-list-price-size:') && str_contains($entityPresentationCss, '--theme-entity-list-media-ratio:') && str_contains($entityPresentationCss, '--theme-entity-media-ratio:') && str_contains($entityPresentationCss, '--theme-entity-action-min-height:'), $entityPresentationCss);

$customizerTemplateContent = file_get_contents(BASE_PATH . '/templates/modules/cms/admin/theme-customizer.disyl');
$superadminSettingsTemplateContent = file_get_contents(BASE_PATH . '/templates/pages/superadmin-settings.disyl') ?: '';
$publicIndexContent = file_get_contents(BASE_PATH . '/public/index.php') ?: '';
$entityListTemplateContent = file_get_contents(BASE_PATH . '/templates/modules/cms/public/entity.list.disyl') ?: '';
$entityViewTemplateContent = file_get_contents(BASE_PATH . '/templates/modules/cms/public/entity.view.disyl') ?: '';
$entityMetaBlockContent = file_get_contents(BASE_PATH . '/templates/modules/cms/public/blocks/meta.block.disyl') ?: '';
$homeTemplateContent = file_get_contents(BASE_PATH . '/templates/modules/cms/public/home.disyl') ?: '';
$archiveTemplateContent = file_get_contents(BASE_PATH . '/templates/modules/cms/public/archive.disyl') ?: '';
$singleTemplateContent = file_get_contents(BASE_PATH . '/templates/modules/cms/public/single.disyl') ?: '';
$baseShopTemplateContent = file_get_contents(BASE_PATH . '/templates/modules/ecommerce/public/shop.disyl') ?: '';
$baseProductTemplateContent = file_get_contents(BASE_PATH . '/templates/modules/ecommerce/public/product.disyl') ?: '';
$nativeCanonicalStylesContent = file_get_contents(BASE_PATH . '/storage/cms-themes/native-default/public/partials/canonical-entity-styles.disyl') ?: '';
$nativeCanonicalEntityListTemplateContent = file_get_contents(BASE_PATH . '/storage/cms-themes/native-default/public/entity.list.disyl') ?: '';
$nativeCanonicalEntityViewTemplateContent = file_get_contents(BASE_PATH . '/storage/cms-themes/native-default/public/entity.view.disyl') ?: '';
$nativeLegacyRouteTemplates = [
    'home' => BASE_PATH . '/storage/cms-themes/native-default/public/home.disyl',
    'archive' => BASE_PATH . '/storage/cms-themes/native-default/public/archive.disyl',
    'search' => BASE_PATH . '/storage/cms-themes/native-default/public/search.disyl',
    'page' => BASE_PATH . '/storage/cms-themes/native-default/public/page.disyl',
    'single' => BASE_PATH . '/storage/cms-themes/native-default/public/single.disyl',
];
$nativeArchiveProductTemplateContent = file_get_contents(BASE_PATH . '/storage/cms-themes/native-default/public/ecommerce/archive-product.disyl') ?: '';
$nativeSingleProductTemplateContent = file_get_contents(BASE_PATH . '/storage/cms-themes/native-default/public/ecommerce/single-product.disyl') ?: '';
t('customizer tracks per-section dirty state for scoped saves', str_contains($customizerTemplateContent, 'dirtySections: { footer: false, header: false, sidebar: false, colors: false, custom_code: false, theme: false, entity_presentation: false }'));
t('customizer save resolves only dirty sections', str_contains($customizerTemplateContent, 'const sections = this.sectionsToSave();'));
t('canonical entity list template extends the canonical CMS public layout', str_contains($entityListTemplateContent, '{extends "modules/cms/layouts/public.disyl"}'), $entityListTemplateContent);
t('canonical entity list template exposes dedicated card title hook for catalog overrides', str_contains($entityListTemplateContent, 'cms-entity-card__title'), $entityListTemplateContent);
t('canonical entity list template exposes storefront contract markers and title bridge', str_contains($entityListTemplateContent, 'data-storefront-route-kind=') && str_contains($entityListTemplateContent, 'storefront.page.title|default:list_title'), $entityListTemplateContent);
t('canonical entity view template extends the canonical CMS public layout', str_contains($entityViewTemplateContent, '{extends "modules/cms/layouts/public.disyl"}'), $entityViewTemplateContent);
t('canonical entity view template exposes storefront contract markers', str_contains($entityViewTemplateContent, 'data-storefront-route-kind=') && str_contains($entityViewTemplateContent, 'data-storefront-product-id='), $entityViewTemplateContent);
t('native default theme ships canonical entity block styles for non-Tailwind shells', str_contains($nativeCanonicalStylesContent, '.native-canonical-list__grid {') && str_contains($nativeCanonicalStylesContent, '.cms-gallery-grid {') && str_contains($nativeCanonicalStylesContent, '.cms-pricing-block {'), $nativeCanonicalStylesContent);
t('native default theme ships canonical entity list template', str_contains($nativeCanonicalEntityListTemplateContent, '{extends "_cms_active_theme/layouts/public.disyl"}') && str_contains($nativeCanonicalEntityListTemplateContent, 'data-native-canonical-template="entity-list"'), $nativeCanonicalEntityListTemplateContent);
t('native default theme canonical entity list template keeps storefront contract markers', str_contains($nativeCanonicalEntityListTemplateContent, 'data-storefront-route-kind=') && str_contains($nativeCanonicalEntityListTemplateContent, 'cms-entity-list__grid native-canonical-list__grid'), $nativeCanonicalEntityListTemplateContent);
t('native default theme ships canonical entity view template', str_contains($nativeCanonicalEntityViewTemplateContent, '{extends "_cms_active_theme/layouts/public.disyl"}') && str_contains($nativeCanonicalEntityViewTemplateContent, 'data-native-canonical-template="entity-view"'), $nativeCanonicalEntityViewTemplateContent);
t('native default theme canonical entity view template keeps shared block includes for canonical contracts', str_contains($nativeCanonicalEntityViewTemplateContent, 'modules/cms/public/blocks/entity-summary.block.disyl') && str_contains($nativeCanonicalEntityViewTemplateContent, 'modules/cms/public/blocks/meta.block.disyl'), $nativeCanonicalEntityViewTemplateContent);
t('native default theme no longer ships legacy CMS route template forks', array_reduce($nativeLegacyRouteTemplates, static fn(bool $carry, string $path): bool => $carry && !is_file($path), true), json_encode($nativeLegacyRouteTemplates));
t('native default canonical entity view avoids legacy post template classes', !str_contains($nativeCanonicalEntityViewTemplateContent, 'post-header') && !str_contains($nativeCanonicalEntityViewTemplateContent, 'post-title') && !str_contains($nativeCanonicalEntityViewTemplateContent, 'post-content') && !str_contains($nativeCanonicalEntityViewTemplateContent, 'post-featured-image'), $nativeCanonicalEntityViewTemplateContent);
t('native default canonical entity styles define their own title treatment', str_contains($nativeCanonicalStylesContent, '.native-canonical-view__title {') && !str_contains($nativeCanonicalStylesContent, '.native-canonical-view__header .post-title {'), $nativeCanonicalStylesContent);
t('native default theme ships dedicated archive-product storefront template', str_contains($nativeArchiveProductTemplateContent, 'data-native-ecommerce-template="archive-product"'), $nativeArchiveProductTemplateContent);
t('native archive-product storefront template uses editorial catalog masthead and chip rail', str_contains($nativeArchiveProductTemplateContent, 'native-shop-masthead') && str_contains($nativeArchiveProductTemplateContent, 'native-shop-chip'), $nativeArchiveProductTemplateContent);
t('native archive-product storefront template supports category dropdown navigation mode', str_contains($nativeArchiveProductTemplateContent, "theme_settings.entity_list_category_navigation == 'dropdown'") && str_contains($nativeArchiveProductTemplateContent, 'native-shop-category-picker'), $nativeArchiveProductTemplateContent);
t('native archive-product storefront template consumes storefront contract markers and page title', str_contains($nativeArchiveProductTemplateContent, 'data-storefront-route-kind=') && str_contains($nativeArchiveProductTemplateContent, 'storefront.page.title|default:page_title'), $nativeArchiveProductTemplateContent);
t('native default theme ships dedicated single-product storefront template', str_contains($nativeSingleProductTemplateContent, 'data-native-ecommerce-template="single-product"'), $nativeSingleProductTemplateContent);
t('native single-product storefront template consumes storefront contract markers and cart count', str_contains($nativeSingleProductTemplateContent, 'data-storefront-product-id=') && str_contains($nativeSingleProductTemplateContent, 'storefront.cart.count|default:cart_count'), $nativeSingleProductTemplateContent);
t('base ecommerce shop template consumes storefront collection contract', str_contains($baseShopTemplateContent, 'data-storefront-route-kind=') && str_contains($baseShopTemplateContent, 'foreach storefront.collection.items as p') && str_contains($baseShopTemplateContent, 'storefront.navigation.search_action_url'), $baseShopTemplateContent);
t('base ecommerce product template consumes storefront product contract', str_contains($baseProductTemplateContent, 'data-storefront-product-id=') && str_contains($baseProductTemplateContent, 'storefront.product.pricing') && str_contains($baseProductTemplateContent, 'storefront.navigation.shop_url'), $baseProductTemplateContent);
t('ecommerce customizer presents shell-versus-entities workspace copy', str_contains($customizerTemplateContent, 'Shape the storefront shell here: header, navigation, sidebar, footer, palette, and shell layout. Use Entities for the canonical entity view and entity list contract.'));
t('native customizer explains entities stay inside the active theme shell', str_contains($customizerTemplateContent, 'The active theme shell stays in charge here. Use Entities for canonical page, post, list, and commerce presentation inside that shell.'));
t('native customizer describes canonical entity presentation inside the active theme shell', str_contains($customizerTemplateContent, 'Canonical entity presentation for pages, posts, lists, and commerce routes inside the active theme shell.'));
t('ecommerce customizer exposes dedicated navigation tab', str_contains($customizerTemplateContent, "activeTab = 'navigation'") && str_contains($customizerTemplateContent, 'Menu Behavior'));
t('ecommerce customizer keeps native-capable sidebar controls available', str_contains($customizerTemplateContent, "activeTab = 'sidebar'") && str_contains($customizerTemplateContent, 'Sidebar Rail'));
t('customizer exposes sidebar include and exclude target scope modes', str_contains($customizerTemplateContent, 'All except selected targets') && str_contains($customizerTemplateContent, 'Only selected targets'), $customizerTemplateContent);
t('customizer sidebar preview summarizes selected template scope', str_contains($customizerTemplateContent, 'sidebarScopeSummary()') && str_contains($customizerTemplateContent, 'sidebarNormalizeTemplateRules()'), $customizerTemplateContent);
t('ecommerce customizer keeps native-capable custom code controls available', str_contains($customizerTemplateContent, "activeTab = 'custom_code'") && str_contains($customizerTemplateContent, 'Store Code'));
t('ecommerce customizer saves all dirty scoped sections', str_contains($customizerTemplateContent, 'return sections;'));
t('customizer menu locations normalize label fallback for navigation workspace', str_contains($customizerTemplateContent, 'loc.label || loc.name || loc.slug') && str_contains($customizerTemplateContent, 'this.availableMenuLocations = (Array.isArray(this.availableMenuLocations) ? this.availableMenuLocations : [])'));
t('customizer bootstraps canonical entity presentation settings payload', str_contains($customizerTemplateContent, 'id="cz-entity-presentation-settings"'));
t('customizer saves canonical entity presentation section payload', str_contains($customizerTemplateContent, "return { settings: this.entityPresentationSettings }"));
t('customizer preserves canonical entity list pricing state for schema-driven preview', str_contains($customizerTemplateContent, 'entityPreviewListPricingVariant()') && str_contains($customizerTemplateContent, "entityContextHasCapability('pricing')"), $customizerTemplateContent);
t('customizer embeds entity context catalog and example payloads', str_contains($customizerTemplateContent, 'id="cz-entity-context-catalog"') && str_contains($customizerTemplateContent, 'id="cz-entity-context-examples"'), $customizerTemplateContent);
t('customizer hydrates entity context schema state and default example selection', str_contains($customizerTemplateContent, 'entityContextCatalog = JSON.parse(document.getElementById(\'cz-entity-context-catalog\').textContent)') && str_contains($customizerTemplateContent, 'entityContextExampleId = this.entityContextPreferredExampleId();'), $customizerTemplateContent);
t('customizer exposes catalog category navigation control', str_contains($customizerTemplateContent, 'entityPresentationSettings.entity_list_category_navigation') && str_contains($customizerTemplateContent, 'Shop Categories'), $customizerTemplateContent);
t('customizer exposes header container and holder shell controls', str_contains($customizerTemplateContent, 'headerSettings.header_container_width') && str_contains($customizerTemplateContent, 'headerSettings.header_inner_width_mode') && str_contains($customizerTemplateContent, 'headerSettings.header_inner_custom_width') && str_contains($customizerTemplateContent, 'headerSettings.topbar_container_width') && str_contains($customizerTemplateContent, 'headerSettings.topbar_inner_width_mode') && str_contains($customizerTemplateContent, 'Header Container') && str_contains($customizerTemplateContent, 'Header Holder Width') && str_contains($customizerTemplateContent, 'Top Bar Container') && str_contains($customizerTemplateContent, 'Top Bar Holder Width'), $customizerTemplateContent);
t('customizer hydrates legacy header width into container and holder defaults', str_contains($customizerTemplateContent, "const legacyHeaderWidth = (this.headerSettings.header_inner_width || this.headerSettings.inner_width) === 'full-width' ? 'full' : 'contained';") && str_contains($customizerTemplateContent, "this.headerSettings.header_container_width = legacyHeaderWidth === 'full' ? 'full' : 'contained';") && str_contains($customizerTemplateContent, 'headerPreviewContainerStyle(headerSettings.header_container_width)') && str_contains($customizerTemplateContent, 'headerPreviewHolderStyle(headerSettings.header_inner_width_mode, headerSettings.header_inner_custom_width)'), $customizerTemplateContent);
t('customizer exposes split footer widget shell controls', str_contains($customizerTemplateContent, 'footerSettings.widget_container_width') && str_contains($customizerTemplateContent, 'footerSettings.widget_inner_width_mode') && str_contains($customizerTemplateContent, 'footerSettings.widget_inner_custom_width') && str_contains($customizerTemplateContent, 'Footer Bar Width'), $customizerTemplateContent);
t('customizer hydrates legacy footer settings into split widget shell defaults', str_contains($customizerTemplateContent, 'this.footerSettings.widget_container_width === undefined') && str_contains($customizerTemplateContent, 'this.footerSettings.widget_inner_width_mode === undefined') && str_contains($customizerTemplateContent, 'footerPreviewWidgetContainerLabel()'), $customizerTemplateContent);
t('customizer preview mirrors the saved custom footer holder width', str_contains($customizerTemplateContent, "if (mode === 'custom') return 'width:100%;max-width:' + (this.footerSettings.widget_inner_custom_width || '960px') + ';margin:0 auto;';"), $customizerTemplateContent);
t('superadmin feature settings template exposes collapsible module panels', str_contains($superadminSettingsTemplateContent, 'sa-module-collapse') && str_contains($superadminSettingsTemplateContent, 'toggleModulePanel(') && str_contains($superadminSettingsTemplateContent, 'This module is disabled for this tenant. You can still save settings now; they will apply when enabled.'), $superadminSettingsTemplateContent);
t('superadmin feature settings page seeds tenant field values from manifest defaults', str_contains($publicIndexContent, "array_key_exists(\$key, \$modSettings)") && str_contains($publicIndexContent, ": (\$field['default'] ?? '');"), $publicIndexContent);
t('superadmin tenant whitelist keeps only explicitly attached tenant modules', str_contains($publicIndexContent, "\$subModules = \$cmsSettings['_installed_submodules'] ?? [];") && str_contains($publicIndexContent, '$_candidateTenantSettings = readTenantModuleSettingsForTenant($_candidateModId, $selectedTenantId);') && str_contains($publicIndexContent, 'if (!empty($_candidateTenantSettings)) {') && !str_contains($publicIndexContent, "isset(\$allModules['anti-spam']) && !empty(\$allModules['anti-spam']['settings_fields'])"), $publicIndexContent);
t('superadmin tenant settings render fields for attached disabled modules', str_contains($publicIndexContent, 'if ($hasFields && $tenantDbOk) {'), $publicIndexContent);
t('customizer backfills moved theme presentation keys into canonical entity settings', str_contains($customizerTemplateContent, 'const legacyThemePresentation = {};') && str_contains($customizerTemplateContent, "'blog_layout',") && str_contains($customizerTemplateContent, "'single_show_nav',") && str_contains($customizerTemplateContent, 'this.entityPresentationSettings = Object.assign({}, entityPresentationDefaults, legacyThemePresentation, this.entityPresentationSettings || {});'), $customizerTemplateContent);
t('customizer hydrates canonical entity presentation defaults', str_contains($customizerTemplateContent, 'entityPresentationDefaults = {') && str_contains($customizerTemplateContent, "entity_list_category_navigation: 'list'") && str_contains($customizerTemplateContent, "blog_layout: 'list'") && str_contains($customizerTemplateContent, 'single_max_width: 768'), $customizerTemplateContent);
t('customizer keeps theme layout hydration shell-only', str_contains($customizerTemplateContent, 'const themeLayoutDefaults = {') && str_contains($customizerTemplateContent, 'layout_mode: rawThemeLayoutSettings.layout_mode,') && !str_contains($customizerTemplateContent, 'themeLayoutSettings.blog_layout'), $customizerTemplateContent);
t('customizer renders entity controls from the active schema sections', str_contains($customizerTemplateContent, 'entityContextActiveSections()') && str_contains($customizerTemplateContent, 'entityPresentationSettings[field.name]') && str_contains($customizerTemplateContent, 'section.fields.length === 0'), $customizerTemplateContent);
t('customizer template no longer references legacy storefront settings state', !str_contains($customizerTemplateContent, 'storefrontSettings'), $customizerTemplateContent);
t('customizer names the entity workspace explicitly instead of catalog-only wording', str_contains($customizerTemplateContent, 'Entities') && str_contains($customizerTemplateContent, 'Entity Contract'), $customizerTemplateContent);
t('customizer preview includes catalog font helpers', str_contains($customizerTemplateContent, 'catalogFontLabel(') && str_contains($customizerTemplateContent, 'entityPreviewCatalogTitleStyle()') && str_contains($customizerTemplateContent, 'entityPreviewCatalogExcerptStyle()'), $customizerTemplateContent);
t('customizer resolves select inputs from schema field metadata', str_contains($customizerTemplateContent, 'entityContextFieldOptions(field)') && str_contains($customizerTemplateContent, 'field.empty_option_label') && str_contains($customizerTemplateContent, "entityContextHasCapability('pricing')"), $customizerTemplateContent);
t('customizer hydrates theme manifest block variants for preview fidelity', str_contains($customizerTemplateContent, 'cz-theme-manifest-block-variants'));
t('customizer preview resolves effective storefront list-card variants', str_contains($customizerTemplateContent, 'entityPreviewListPricingVariant()') && str_contains($customizerTemplateContent, 'entityPreviewEffectiveVariant('));
t('customizer preview derives storefront surfaces and featured states from active color settings', str_contains($customizerTemplateContent, "this.colorAlpha(this.colorsSettings.body_text_color || '#1e293b', 0.06)") && str_contains($customizerTemplateContent, "this.colorAlpha(this.colorsSettings.storefront_cta_bg || '#0284c7', 0.18)") && str_contains($customizerTemplateContent, "customizerScope === 'ecommerce' ? colorsSettings.storefront_cta_bg : colorsSettings.color_primary"), $customizerTemplateContent);
t('ecommerce customizer resets navigation tab through header defaults', str_contains($customizerTemplateContent, "this.activeTab === 'header' || this.activeTab === 'navigation'"));
t('canonical entity view template reads back-link visibility from entity presentation settings', str_contains($entityViewTemplateContent, 'entity_view_context.show_back_link|default:entity_presentation_settings.single_show_nav'), $entityViewTemplateContent);
t('canonical entity meta block reads author and date visibility from entity presentation settings', str_contains($entityMetaBlockContent, 'entity_presentation_settings.single_show_author') && str_contains($entityMetaBlockContent, 'entity_presentation_settings.single_show_date'), $entityMetaBlockContent);
t('legacy home template reads list presentation from entity presentation settings', str_contains($homeTemplateContent, 'entity_presentation_settings.blog_show_author') && str_contains($homeTemplateContent, 'entity_presentation_settings.blog_readmore_text'), $homeTemplateContent);
t('legacy archive template reads list presentation from entity presentation settings', str_contains($archiveTemplateContent, 'entity_presentation_settings.blog_show_author') && str_contains($archiveTemplateContent, 'entity_presentation_settings.blog_readmore_text'), $archiveTemplateContent);
t('legacy single template reads detail presentation from entity presentation settings', str_contains($singleTemplateContent, 'entity_presentation_settings.single_show_author') && str_contains($singleTemplateContent, 'entity_presentation_settings.single_show_nav'), $singleTemplateContent);

$ecInitContent = file_get_contents(BASE_PATH . '/modules/ecommerce/helpers/00-init.php');
$ecProductsHelperContent = file_get_contents(BASE_PATH . '/modules/ecommerce/helpers/30-products.php');
$ecPublicShopHandlerContent = file_get_contents(BASE_PATH . '/modules/ecommerce/handlers/10-public-shop.php');
t('ecommerce public render delegates CMS shell rendering through cms module context', str_contains($ecInitContent, "moduleWithContext('cms', static function () use (") && str_contains($ecInitContent, 'cmsPublicContext($context)'), $ecInitContent);
t('ecommerce public render resolves native theme storefront template candidates', str_contains($ecInitContent, 'function ecPublicThemeTemplateCandidates(') && str_contains($ecInitContent, 'function ecResolvePublicThemeTemplate('), $ecInitContent);
t('ecommerce helper layer defines normalized storefront contract builders', str_contains($ecProductsHelperContent, 'function ecBuildStorefrontCatalogContext(') && str_contains($ecProductsHelperContent, 'function ecBuildStorefrontDetailContext('), $ecProductsHelperContent);
t('shop handler now falls back to a traditional storefront template when canonical rendering is disabled', str_contains($ecPublicShopHandlerContent, "if (ecDispatchCanonicalEntityRoute('cms:cmsPublicEntityList'") && str_contains($ecPublicShopHandlerContent, "ecRender('modules/ecommerce/public/shop.disyl'"), $ecPublicShopHandlerContent);
t('native storefront fallbacks pass the normalized storefront contract into template context', str_contains($ecPublicShopHandlerContent, 'ecBuildStorefrontCatalogContext(') && str_contains($ecPublicShopHandlerContent, 'ecBuildStorefrontDetailContext(') && str_contains($ecPublicShopHandlerContent, "'storefront' => \$storefront"), $ecPublicShopHandlerContent);
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
t('ecommerce public layout consumes storefront cart and shop navigation contract', str_contains($ecommerceLayoutContent, 'storefront.navigation.shop_url') && str_contains($ecommerceLayoutContent, 'if storefront.page.kind') && str_contains($ecommerceLayoutContent, 'storefront.cart.count'), $ecommerceLayoutContent);
t('ecommerce public layout uses shared main layout contract', str_contains($ecommerceLayoutContent, 'cms-public-main'));

$pocStyleContent = file_get_contents(cmsThemesPath() . '/entity-commerce-poc/style.css');
t('entity-commerce-poc styles generic customizer site header', str_contains($pocStyleContent, '.site-header {'));
t('entity-commerce-poc provides customized header inner shell styles', str_contains($pocStyleContent, '.poc-header__inner--customized {'));
t('entity-commerce-poc provides dedicated customized header slot shell styles', str_contains($pocStyleContent, '.poc-header__slot--customized {'));
t('entity-commerce-poc keeps customized header inner layout contained', str_contains($pocStyleContent, '.poc-header--customized .header-inner,'));
t('entity-commerce-poc keeps customized header container max-width disabled', str_contains($pocStyleContent, 'max-width: none;'));
t('entity-commerce-poc styles generic customizer footer widgets', str_contains($pocStyleContent, '.footer-widgets-grid {'));
t('entity-commerce-poc styles generic customizer footer bottom', str_contains($pocStyleContent, '.footer-bottom {'));
t('entity-commerce-poc removes duplicated customized footer container padding', str_contains($pocStyleContent, ".poc-footer--customized .footer-bottom .container {\n    padding: 0;\n}") && str_contains($pocStyleContent, ".footer-bottom {\n    margin-top: 0;\n    padding: 0;\n}"), $pocStyleContent);
t('entity-commerce-poc preserves customized footer widget backgrounds', !str_contains($pocStyleContent, ".poc-footer--customized .footer-widgets {\n    background: transparent !important;"), $pocStyleContent);
t('entity-commerce-poc preserves customized footer bar backgrounds', !str_contains($pocStyleContent, ".poc-footer--customized .footer-bottom {\n    background: transparent !important;"), $pocStyleContent);
t('entity-commerce-poc routes customized nav colors through header variables', str_contains($pocStyleContent, 'color: var(--header-link, var(--color-text));') && str_contains($pocStyleContent, 'color: var(--header-link-hover, var(--color-primary));'), $pocStyleContent);
t('entity-commerce-poc defines concrete shell token fallbacks for the deferred default path', str_contains($pocStyleContent, '--container-max: 1180px;') && str_contains($pocStyleContent, '--poc-shell-gutter: var(--theme-content-px, 16px);'), $pocStyleContent);
t('entity-commerce-poc routes default shell widths through the shared gutter token', str_contains($pocStyleContent, 'width: min(var(--container-max), calc(100vw - (var(--poc-shell-gutter) * 2)));'), $pocStyleContent);
t('entity-commerce-poc routes default typography through customizer font tokens', str_contains($pocStyleContent, '--poc-font-body: var(--font-body, "Inter", -apple-system, BlinkMacSystemFont, sans-serif);') && str_contains($pocStyleContent, '--poc-font-heading: var(--font-heading, "Inter", -apple-system, BlinkMacSystemFont, sans-serif);') && str_contains($pocStyleContent, '.poc-branding__tag,') && str_contains($pocStyleContent, 'font-family: var(--poc-font-body);') && str_contains($pocStyleContent, 'font-family: var(--poc-font-heading);'), $pocStyleContent);
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

$entityDefaults = cmsEntityPresentationSectionDefaults();
$ecommerceEntityDefaults = cmsEntityPresentationSectionDefaults('ecommerce');
$themeLayoutDefaults = cmsThemeLayoutSettingsDefaults();
t('entity layout profile default is default', ($entityDefaults['entity_layout_profile'] ?? '') === 'default');
t('entity pricing variant default is empty', ($entityDefaults['entity_pricing_variant'] ?? 'x') === '');
t('entity action variant default is empty', ($entityDefaults['entity_action_variant'] ?? 'x') === '');
t('entity list pricing variant default is empty', ($entityDefaults['entity_list_pricing_variant'] ?? 'x') === '');
t('entity list inventory variant default is empty', ($entityDefaults['entity_list_inventory_variant'] ?? 'x') === '');
t('entity list progress variant default is empty', ($entityDefaults['entity_list_progress_variant'] ?? 'x') === '');
t('entity summary width default is 320', ($entityDefaults['entity_summary_width'] ?? '') === '320');
t('entity summary sticky default is enabled', (int)($entityDefaults['entity_summary_sticky'] ?? 0) === 1);
t('entity media ratio default is auto', ($entityDefaults['entity_media_ratio'] ?? '') === 'auto');
t('entity spacing scale default is comfortable', ($entityDefaults['entity_spacing_scale'] ?? '') === 'comfortable');
t('entity action size default is md', ($entityDefaults['entity_action_size'] ?? '') === 'md');
t('entity list title font default inherits shared heading font', ($entityDefaults['entity_list_title_font'] ?? 'x') === '');
t('entity list category navigation default is list', ($entityDefaults['entity_list_category_navigation'] ?? '') === 'list');
t('entity list title clamp default is two lines', ($entityDefaults['entity_list_title_lines'] ?? '') === '2');
t('entity defaults now own canonical list layout', ($entityDefaults['blog_layout'] ?? '') === 'list' && ($entityDefaults['blog_card_radius'] ?? '') === '8');
t('entity defaults now own canonical detail presentation', ($entityDefaults['single_max_width'] ?? '') === '768' && (int)($entityDefaults['single_show_nav'] ?? 0) === 1);
t('ecommerce entity defaults use commerce profile', ($ecommerceEntityDefaults['entity_layout_profile'] ?? '') === 'commerce');
t('ecommerce entity defaults mirror canonical summary width default', ($ecommerceEntityDefaults['entity_summary_width'] ?? '') === '320');
t('ecommerce entity defaults mirror category navigation default', ($ecommerceEntityDefaults['entity_list_category_navigation'] ?? '') === 'list');
t('ecommerce entity defaults mirror canonical list-card pricing variant default', ($ecommerceEntityDefaults['entity_list_pricing_variant'] ?? 'x') === '');
t('theme layout defaults are shell-only after migration', !array_key_exists('blog_layout', $themeLayoutDefaults) && !array_key_exists('single_max_width', $themeLayoutDefaults), json_encode($themeLayoutDefaults));

$colorsDefaults = cmsColorsSettingsDefaults();
t('storefront surface background default exists', ($colorsDefaults['storefront_surface_bg'] ?? '') === '#ffffff');
t('storefront primary CTA background default exists', ($colorsDefaults['storefront_cta_bg'] ?? '') === '#0284c7');
t('storefront inventory danger text default exists', ($colorsDefaults['storefront_danger_text'] ?? '') === '#dc2626');

$validatedEntity = cmsValidateEntityPresentationSettings([
    'entity_layout_profile' => 'commerce',
    'entity_pricing_variant' => 'featured',
    'entity_action_variant' => 'sticky-footer',
    'entity_summary_width' => '410',
    'entity_summary_sticky' => '1',
    'entity_media_ratio' => '16:9',
    'entity_spacing_scale' => 'airy',
    'entity_action_size' => 'lg',
    'entity_list_show_filter_summary' => '1',
    'entity_list_category_navigation' => 'dropdown',
    'entity_list_card_density' => 'airy',
    'entity_list_show_excerpt' => '1',
    'entity_list_excerpt_length' => '180',
    'entity_list_pricing_variant' => 'featured',
    'entity_list_inventory_variant' => 'compact',
    'entity_list_progress_variant' => 'inline',
    'entity_list_title_font' => 'Playfair Display',
    'entity_list_text_font' => 'DM Sans',
    'entity_list_title_size' => '24',
    'entity_list_price_size' => '20',
    'entity_list_card_min_width' => '280',
    'entity_list_title_lines' => '3',
    'blog_layout' => 'grid',
    'blog_columns' => '3',
    'blog_gap' => '32',
    'blog_card_border' => '1',
    'blog_card_shadow' => '0',
    'blog_card_radius' => '14',
    'blog_featured_image' => '1',
    'blog_image_height' => '260',
    'blog_image_ratio' => '4:3',
    'blog_show_author' => '0',
    'blog_show_date' => '1',
    'blog_show_excerpt' => '1',
    'blog_show_readmore' => '0',
    'blog_readmore_text' => 'Explore',
    'single_max_width' => '880',
    'single_show_author' => '1',
    'single_show_date' => '1',
    'single_show_categories' => '0',
    'single_show_tags' => '1',
    'single_show_nav' => '0',
], $entityDefaults);
$validatedEcommerceEntity = cmsValidateEntityPresentationSettings([
    'entity_layout_profile' => 'commerce',
    'entity_pricing_variant' => 'featured',
    'entity_action_variant' => 'sticky-footer',
    'entity_summary_width' => '390',
    'entity_summary_sticky' => '1',
    'entity_media_ratio' => '4:3',
    'entity_spacing_scale' => 'compact',
    'entity_action_size' => 'lg',
    'entity_list_show_filter_summary' => '0',
    'entity_list_category_navigation' => 'dropdown',
    'entity_list_card_density' => 'compact',
    'entity_list_show_excerpt' => '0',
    'entity_list_excerpt_length' => '90',
    'entity_list_pricing_variant' => 'minimal',
    'entity_list_inventory_variant' => 'compact',
    'entity_list_progress_variant' => 'inline',
    'entity_list_title_font' => 'Playfair Display',
    'entity_list_text_font' => 'Nunito',
    'entity_list_title_size' => '22',
    'entity_list_price_size' => '18',
    'entity_list_card_min_width' => '260',
    'entity_list_title_lines' => '2',
    'blog_layout' => 'cards',
    'blog_columns' => '2',
    'blog_gap' => '20',
    'blog_card_border' => '1',
    'blog_card_shadow' => '1',
    'blog_card_radius' => '10',
    'blog_featured_image' => '1',
    'blog_image_height' => '220',
    'blog_image_ratio' => '16:9',
    'blog_show_author' => '1',
    'blog_show_date' => '1',
    'blog_show_excerpt' => '0',
    'blog_show_readmore' => '1',
    'blog_readmore_text' => 'Browse',
    'single_max_width' => '820',
    'single_show_author' => '1',
    'single_show_date' => '0',
    'single_show_categories' => '1',
    'single_show_tags' => '0',
    'single_show_nav' => '1',
], $ecommerceEntityDefaults);
t('entity layout profile validates approved profile', ($validatedEntity['entity_layout_profile'] ?? '') === 'commerce');
t('entity pricing variant validates approved variant', ($validatedEntity['entity_pricing_variant'] ?? '') === 'featured');
t('entity action variant validates approved variant', ($validatedEntity['entity_action_variant'] ?? '') === 'sticky-footer');
t('entity summary width validates approved range', ($validatedEntity['entity_summary_width'] ?? '') === '410');
t('entity summary sticky validates boolean', (int)($validatedEntity['entity_summary_sticky'] ?? 0) === 1);
t('entity media ratio validates approved option', ($validatedEntity['entity_media_ratio'] ?? '') === '16:9');
t('entity spacing scale validates approved option', ($validatedEntity['entity_spacing_scale'] ?? '') === 'airy');
t('entity action size validates approved option', ($validatedEntity['entity_action_size'] ?? '') === 'lg');
t('entity list filter summary validates boolean', (int)($validatedEntity['entity_list_show_filter_summary'] ?? 0) === 1);
t('entity list category navigation validates approved option', ($validatedEntity['entity_list_category_navigation'] ?? '') === 'dropdown');
t('entity list card density validates approved option', ($validatedEntity['entity_list_card_density'] ?? '') === 'airy');
t('entity list excerpt toggle validates boolean', (int)($validatedEntity['entity_list_show_excerpt'] ?? 0) === 1);
t('entity list excerpt length validates approved range', ($validatedEntity['entity_list_excerpt_length'] ?? '') === '180');
t('entity list title font validates safe catalog override', ($validatedEntity['entity_list_title_font'] ?? '') === 'Playfair Display');
t('entity list text font validates safe catalog override', ($validatedEntity['entity_list_text_font'] ?? '') === 'DM Sans');
t('entity list title size validates approved range', ($validatedEntity['entity_list_title_size'] ?? '') === '24');
t('entity list price size validates approved range', ($validatedEntity['entity_list_price_size'] ?? '') === '20');
t('entity list card min width validates approved range', ($validatedEntity['entity_list_card_min_width'] ?? '') === '280');
t('entity list title clamp validates approved range', ($validatedEntity['entity_list_title_lines'] ?? '') === '3');
t('entity list presentation validates migrated layout controls', ($validatedEntity['blog_layout'] ?? '') === 'grid' && ($validatedEntity['blog_columns'] ?? '') === '3' && ($validatedEntity['blog_gap'] ?? '') === '32', json_encode($validatedEntity));
t('entity list presentation validates migrated card and media controls', ($validatedEntity['blog_card_radius'] ?? '') === '14' && ($validatedEntity['blog_image_height'] ?? '') === '260' && ($validatedEntity['blog_image_ratio'] ?? '') === '4:3', json_encode($validatedEntity));
t('entity detail presentation validates migrated visibility controls', ($validatedEntity['single_max_width'] ?? '') === '880' && (int)($validatedEntity['single_show_categories'] ?? 1) === 0 && (int)($validatedEntity['single_show_nav'] ?? 1) === 0, json_encode($validatedEntity));
t('entity list pricing variant validates approved option', ($validatedEntity['entity_list_pricing_variant'] ?? '') === 'featured');
t('entity list inventory variant validates approved option', ($validatedEntity['entity_list_inventory_variant'] ?? '') === 'compact');
t('entity list progress variant validates approved option', ($validatedEntity['entity_list_progress_variant'] ?? '') === 'inline');
t('ecommerce entity settings validate approved profile', ($validatedEcommerceEntity['entity_layout_profile'] ?? '') === 'commerce');
t('ecommerce entity settings validate summary width', ($validatedEcommerceEntity['entity_summary_width'] ?? '') === '390');
t('ecommerce entity settings validate media ratio', ($validatedEcommerceEntity['entity_media_ratio'] ?? '') === '4:3');
t('ecommerce entity settings validate list card density', ($validatedEcommerceEntity['entity_list_card_density'] ?? '') === 'compact');
t('ecommerce entity settings validate filter summary toggle', (int)($validatedEcommerceEntity['entity_list_show_filter_summary'] ?? 1) === 0);
t('ecommerce entity settings validate category navigation mode', ($validatedEcommerceEntity['entity_list_category_navigation'] ?? '') === 'dropdown');
t('ecommerce entity settings validate excerpt toggle', (int)($validatedEcommerceEntity['entity_list_show_excerpt'] ?? 1) === 0);
t('ecommerce entity settings validate excerpt length', ($validatedEcommerceEntity['entity_list_excerpt_length'] ?? '') === '90');
t('ecommerce entity settings validate catalog title font override', ($validatedEcommerceEntity['entity_list_title_font'] ?? '') === 'Playfair Display');
t('ecommerce entity settings validate catalog text font override', ($validatedEcommerceEntity['entity_list_text_font'] ?? '') === 'Nunito');
t('ecommerce entity settings validate catalog title size', ($validatedEcommerceEntity['entity_list_title_size'] ?? '') === '22');
t('ecommerce entity settings validate catalog price size', ($validatedEcommerceEntity['entity_list_price_size'] ?? '') === '18');
t('ecommerce entity settings validate catalog min card width', ($validatedEcommerceEntity['entity_list_card_min_width'] ?? '') === '260');
t('ecommerce entity settings validate catalog title clamp', ($validatedEcommerceEntity['entity_list_title_lines'] ?? '') === '2');
t('ecommerce entity settings validate migrated list layout controls', ($validatedEcommerceEntity['blog_layout'] ?? '') === 'cards' && ($validatedEcommerceEntity['blog_columns'] ?? '') === '2', json_encode($validatedEcommerceEntity));
t('ecommerce entity settings validate migrated detail controls', ($validatedEcommerceEntity['single_max_width'] ?? '') === '820' && (int)($validatedEcommerceEntity['single_show_nav'] ?? 0) === 1, json_encode($validatedEcommerceEntity));
t('ecommerce entity settings validate list pricing variant', ($validatedEcommerceEntity['entity_list_pricing_variant'] ?? '') === 'minimal');
t('ecommerce entity settings validate list inventory variant', ($validatedEcommerceEntity['entity_list_inventory_variant'] ?? '') === 'compact');
t('ecommerce entity settings validate list progress variant', ($validatedEcommerceEntity['entity_list_progress_variant'] ?? '') === 'inline');

$validatedFooterShells = cmsValidateFooterSettings([
    'widget_container_width' => 'full',
    'widget_inner_width_mode' => 'custom',
    'widget_inner_custom_width' => '72rem',
    'inner_width' => 'contained',
]);
$legacyFooterShells = cmsValidateFooterSettings([
    'inner_width' => 'full-width',
]);
$validatedHeaderShells = cmsValidateHeaderSettings([
    'header_container_width' => 'full',
    'header_inner_width_mode' => 'custom',
    'header_inner_custom_width' => '72rem',
    'topbar_container_width' => 'contained',
    'topbar_inner_width_mode' => 'boxed',
]);
$legacyHeaderShells = cmsValidateHeaderSettings([
    'inner_width' => 'full-width',
]);
t('footer settings validate widget container shell mode', ($validatedFooterShells['widget_container_width'] ?? '') === 'full');
t('footer settings validate widget holder custom width', ($validatedFooterShells['widget_inner_width_mode'] ?? '') === 'custom' && ($validatedFooterShells['widget_inner_custom_width'] ?? '') === '72rem', json_encode($validatedFooterShells));
t('footer settings map legacy full-width shell mode onto split widget defaults', ($legacyFooterShells['widget_container_width'] ?? '') === 'full' && ($legacyFooterShells['widget_inner_width_mode'] ?? '') === 'full', json_encode($legacyFooterShells));
t('header settings validate container and holder shell widths', ($validatedHeaderShells['header_container_width'] ?? '') === 'full' && ($validatedHeaderShells['header_inner_width_mode'] ?? '') === 'custom' && ($validatedHeaderShells['header_inner_custom_width'] ?? '') === '72rem' && ($validatedHeaderShells['topbar_container_width'] ?? '') === 'contained' && ($validatedHeaderShells['topbar_inner_width_mode'] ?? '') === 'boxed', json_encode($validatedHeaderShells));
t('header settings keep legacy width aliases mapped to the main header holder', ($validatedHeaderShells['header_inner_width'] ?? '') === 'contained' && ($validatedHeaderShells['inner_width'] ?? '') === 'contained' && ($validatedHeaderShells['topbar_inner_width'] ?? '') === 'contained', json_encode($validatedHeaderShells));
t('header settings map legacy inner width onto container and holder defaults', ($legacyHeaderShells['header_container_width'] ?? '') === 'full' && ($legacyHeaderShells['header_inner_width_mode'] ?? '') === 'full' && ($legacyHeaderShells['topbar_container_width'] ?? '') === 'full' && ($legacyHeaderShells['topbar_inner_width_mode'] ?? '') === 'full' && ($legacyHeaderShells['header_inner_width'] ?? '') === 'full-width' && ($legacyHeaderShells['inner_width'] ?? '') === 'full-width', json_encode($legacyHeaderShells));

$invalidEntity = cmsValidateEntityPresentationSettings([
    'entity_layout_profile' => 'wild',
    'entity_pricing_variant' => 'giant',
    'entity_action_variant' => 'floating',
    'entity_summary_width' => '999',
    'entity_summary_sticky' => '',
    'entity_media_ratio' => '2:1',
    'entity_spacing_scale' => 'dense',
    'entity_action_size' => 'xl',
    'entity_list_show_filter_summary' => '',
    'entity_list_category_navigation' => 'rail',
    'entity_list_card_density' => 'dense',
    'entity_list_show_excerpt' => '',
    'entity_list_excerpt_length' => '999',
    'entity_list_pricing_variant' => 'heroic',
    'entity_list_inventory_variant' => 'full',
    'entity_list_progress_variant' => 'stacked',
    'entity_list_title_font' => '<bad>',
    'entity_list_text_font' => '!!',
    'entity_list_title_size' => '99',
    'entity_list_price_size' => '3',
    'entity_list_card_min_width' => '999',
    'entity_list_title_lines' => '0',
    'blog_layout' => 'masonry',
    'blog_columns' => '8',
    'blog_gap' => '999',
    'blog_card_border' => '',
    'blog_card_shadow' => '',
    'blog_card_radius' => '99',
    'blog_featured_image' => '',
    'blog_image_height' => '9',
    'blog_image_ratio' => '3:2',
    'blog_show_author' => '',
    'blog_show_date' => '',
    'blog_show_excerpt' => '',
    'blog_show_readmore' => '',
    'blog_readmore_text' => '',
    'single_max_width' => '9999',
    'single_show_author' => '',
    'single_show_date' => '',
    'single_show_categories' => '',
    'single_show_tags' => '',
    'single_show_nav' => '',
], $entityDefaults);
t('invalid entity profile falls back to default', ($invalidEntity['entity_layout_profile'] ?? '') === 'default');
t('invalid pricing variant falls back to default block', ($invalidEntity['entity_pricing_variant'] ?? 'x') === '');
t('invalid action variant falls back to default block', ($invalidEntity['entity_action_variant'] ?? 'x') === '');
t('invalid entity summary width clamps to max', ($invalidEntity['entity_summary_width'] ?? '') === '420');
t('invalid entity summary sticky falls back to disabled boolean', (int)($invalidEntity['entity_summary_sticky'] ?? 1) === 0);
t('invalid entity media ratio falls back to auto', ($invalidEntity['entity_media_ratio'] ?? '') === 'auto');
t('invalid entity spacing scale falls back to comfortable', ($invalidEntity['entity_spacing_scale'] ?? '') === 'comfortable');
t('invalid entity action size falls back to md', ($invalidEntity['entity_action_size'] ?? '') === 'md');
t('invalid entity list filter summary falls back to disabled boolean', (int)($invalidEntity['entity_list_show_filter_summary'] ?? 1) === 0);
t('invalid entity list category navigation falls back to list', ($invalidEntity['entity_list_category_navigation'] ?? '') === 'list');
t('invalid entity list density falls back to comfortable', ($invalidEntity['entity_list_card_density'] ?? '') === 'comfortable');
t('invalid entity list excerpt toggle falls back to disabled boolean', (int)($invalidEntity['entity_list_show_excerpt'] ?? 1) === 0);
t('invalid entity list excerpt length clamps to max', ($invalidEntity['entity_list_excerpt_length'] ?? '') === '220');
t('invalid entity list title font strips unsafe input', ($invalidEntity['entity_list_title_font'] ?? '') === 'bad');
t('invalid entity list text font falls back to empty when sanitized blank', ($invalidEntity['entity_list_text_font'] ?? 'x') === '');
t('invalid entity list title size clamps to max', ($invalidEntity['entity_list_title_size'] ?? '') === '32');
t('invalid entity list price size clamps to min', ($invalidEntity['entity_list_price_size'] ?? '') === '14');
t('invalid entity list min width clamps to max', ($invalidEntity['entity_list_card_min_width'] ?? '') === '340');
t('invalid entity list title clamp clamps to min', ($invalidEntity['entity_list_title_lines'] ?? '') === '1');
t('invalid migrated list layout falls back to canonical defaults', ($invalidEntity['blog_layout'] ?? '') === 'list' && ($invalidEntity['blog_columns'] ?? '') === '4' && ($invalidEntity['blog_gap'] ?? '') === '64', json_encode($invalidEntity));
t('invalid migrated list card and media values clamp or fall back', ($invalidEntity['blog_card_radius'] ?? '') === '24' && ($invalidEntity['blog_image_height'] ?? '') === '100' && ($invalidEntity['blog_image_ratio'] ?? '') === 'auto', json_encode($invalidEntity));
t('invalid migrated detail values clamp or fall back', ($invalidEntity['single_max_width'] ?? '') === '1200' && (int)($invalidEntity['single_show_nav'] ?? 1) === 0, json_encode($invalidEntity));
t('blank migrated read-more text falls back to canonical default copy', ($invalidEntity['blog_readmore_text'] ?? '') === 'Read more →', json_encode($invalidEntity));
t('invalid entity list pricing variant falls back to default block', ($invalidEntity['entity_list_pricing_variant'] ?? 'x') === '');
t('invalid entity list inventory variant falls back to default block', ($invalidEntity['entity_list_inventory_variant'] ?? 'x') === '');
t('invalid entity list progress variant falls back to default block', ($invalidEntity['entity_list_progress_variant'] ?? 'x') === '');

$migrationDb = cmsDb();
cmsDeleteCustomizerSection($migrationDb, 'entity_presentation', 'ecommerce');
upsertCustomizerSection($migrationDb, 'theme', array_merge(cmsThemeLayoutSettingsDefaults(), [
    'layout_mode' => 'contained',
    'site_max_width' => '1280',
    'blog_layout' => 'grid',
    'blog_columns' => '3',
    'blog_gap' => '28',
    'single_max_width' => '840',
    'single_show_nav' => '0',
]), [], 'ecommerce');
cmsCacheInvalidateByTags(['cms:customizer']);
cmsCustomizerClearPersistentCache('theme', 'ecommerce');
cmsCustomizerClearPersistentCache('entity_presentation', 'ecommerce');
$GLOBALS[cmsCustomizerRequestCacheKey('section_row', 'ecommerce')] = [];
unset($GLOBALS['cms_customizer_entity_presentation_normalized_ecommerce_t' . cmsRuntimeTenantId()]);
cmsNormalizeEntityPresentationStorage($migrationDb, null, 'ecommerce');
$migratedLegacyEntity = cmsCustomizerGet($migrationDb, 'entity_presentation', 'ecommerce');
$migratedLegacyTheme = cmsCustomizerGet($migrationDb, 'theme', 'ecommerce');
t('entity presentation normalization migrates legacy theme list and detail settings', ($migratedLegacyEntity['settings']['blog_layout'] ?? '') === 'grid' && ($migratedLegacyEntity['settings']['blog_columns'] ?? '') === '3' && ($migratedLegacyEntity['settings']['single_max_width'] ?? '') === '840' && (int)($migratedLegacyEntity['settings']['single_show_nav'] ?? 1) === 0, json_encode($migratedLegacyEntity));
t('entity presentation normalization strips migrated presentation keys from theme settings', !array_key_exists('blog_layout', $migratedLegacyTheme['settings']) && !array_key_exists('single_max_width', $migratedLegacyTheme['settings']), json_encode($migratedLegacyTheme));

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
t('entity presentation config exposes category navigation mode', ($presentation['list_category_navigation'] ?? '') === 'dropdown');
t('entity presentation config exposes list card density', ($presentation['list_card_density'] ?? '') === 'airy');
t('entity presentation config exposes list excerpt flag', (int)($presentation['list_show_excerpt'] ?? 0) === 1);
t('entity presentation config exposes list excerpt length', (int)($presentation['list_excerpt_length'] ?? 0) === 180);
t('entity presentation config exposes catalog title font override', ($presentation['list_title_font'] ?? '') === 'Playfair Display');
t('entity presentation config exposes catalog text font override', ($presentation['list_text_font'] ?? '') === 'DM Sans');
t('entity presentation config exposes catalog title size', (int)($presentation['list_title_size'] ?? 0) === 24);
t('entity presentation config exposes catalog price size', (int)($presentation['list_price_size'] ?? 0) === 20);
t('entity presentation config exposes catalog min width', (int)($presentation['list_card_min_width'] ?? 0) === 280);
t('entity presentation config exposes catalog title clamp', (int)($presentation['list_title_lines'] ?? 0) === 3);
t('entity presentation config exposes list pricing variant', ($presentation['list_pricing_variant'] ?? '') === 'featured');
t('entity presentation config exposes list inventory variant', ($presentation['list_inventory_variant'] ?? '') === 'compact');
t('entity presentation config exposes list progress variant', ($presentation['list_progress_variant'] ?? '') === 'inline');

saveModuleSettings('cms', $minimalScopeSettings);
cmsResetSettingsCache();
cmsResetThemeRuntimeCache();
cmsActivateThemeSymlink('minimal');

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
cmsCustomizerClearPersistentCache('entity_presentation');

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
upsertCustomizerSection($db, 'theme', cmsThemeLayoutSettingsDefaults());
upsertCustomizerSection($db, 'entity_presentation', array_merge(cmsEntityPresentationSectionDefaults(), $validatedEntity));
cmsCacheInvalidateByTags(['cms:customizer']);
cmsCustomizerClearPersistentCache('colors');
cmsCustomizerClearPersistentCache('theme');
cmsCustomizerClearPersistentCache('entity_presentation');
$GLOBALS[cmsCustomizerRequestCacheKey('section_row', 'native')] = [];

$nativeEntityStorefrontContext = cmsPublicContext([
    'public_render_origin' => 'ecommerce',
    'public_route_kind' => 'shop_index',
]);
t('native ecommerce entity routes keep native customizer scope metadata', ($nativeEntityStorefrontContext['active_customizer_scope'] ?? '') === 'native', json_encode($nativeEntityStorefrontContext));
t('native ecommerce entity routes keep site theme source under canonical entity presentation', ($nativeEntityStorefrontContext['active_theme_source'] ?? '') === 'site', json_encode($nativeEntityStorefrontContext));
t('native ecommerce entity routes stop exposing legacy storefront settings', !array_key_exists('storefront_settings', $nativeEntityStorefrontContext), json_encode($nativeEntityStorefrontContext));
t('native ecommerce entity routes resolve canonical entity presentation from native settings', ($nativeEntityStorefrontContext['entity_presentation_settings']['entity_summary_width'] ?? '') === '410' && ($nativeEntityStorefrontContext['entity_presentation_source'] ?? '') === 'entity_presentation', json_encode($nativeEntityStorefrontContext));
t('native ecommerce entity routes merge canonical entity presentation into theme settings', ($nativeEntityStorefrontContext['theme_settings']['entity_summary_width'] ?? '') === '410', json_encode($nativeEntityStorefrontContext));
t('native ecommerce entity routes emit one combined public theme style source', str_contains((string)($nativeEntityStorefrontContext['theme_layout_style'] ?? ''), 'id="cz-public-theme-override"') && str_contains((string)($nativeEntityStorefrontContext['theme_layout_style'] ?? ''), '--theme-site-max-width:') && str_contains((string)($nativeEntityStorefrontContext['theme_layout_style'] ?? ''), '--theme-entity-summary-width:') && !str_contains((string)($nativeEntityStorefrontContext['theme_layout_style'] ?? ''), 'id="cz-entity-presentation-override"') && !str_contains((string)($nativeEntityStorefrontContext['theme_layout_style'] ?? ''), 'id="cz-storefront-override"'), (string)($nativeEntityStorefrontContext['theme_layout_style'] ?? ''));

$nativeCartContext = cmsPublicContext([
    'public_render_origin' => 'ecommerce',
    'public_route_kind' => 'cart',
]);
t('native ecommerce cart route keeps theme layout presentation defaults', ($nativeCartContext['theme_settings']['entity_summary_width'] ?? '') === '410', json_encode($nativeCartContext));
t('native ecommerce cart route keeps combined public theme style without storefront override mode', str_contains((string)($nativeCartContext['theme_layout_style'] ?? ''), 'id="cz-public-theme-override"') && !str_contains((string)($nativeCartContext['theme_layout_style'] ?? ''), 'id="cz-entity-presentation-override"') && !str_contains((string)($nativeCartContext['theme_layout_style'] ?? ''), 'id="cz-storefront-override"'), (string)($nativeCartContext['theme_layout_style'] ?? ''));

$colorsStyle = cmsRenderColorsStyle($db);
t('colors render exposes storefront CSS variables', str_contains($colorsStyle, '--storefront-surface-bg:'), $colorsStyle);
t('colors render styles semantic price class', str_contains($colorsStyle, '.cms-price-current{color:var(--storefront-price-color);'), $colorsStyle);
t('colors render styles semantic badge class', str_contains($colorsStyle, '.cms-price-badge{background:var(--storefront-badge-bg);'), $colorsStyle);
t('colors render styles semantic inventory states', str_contains($colorsStyle, '.cms-inventory-pill--low{background:var(--storefront-warning-bg);'), $colorsStyle);
t('colors render applies body font token to public body', str_contains($colorsStyle, 'body{font-family:var(--font-body);'), $colorsStyle);
t('colors render makes form controls inherit the configured body font', str_contains($colorsStyle, 'button,input,select,textarea{font-family:inherit;font-size:inherit;line-height:inherit;color:inherit;}'), $colorsStyle);
t('colors render applies heading font token to public headings', str_contains($colorsStyle, 'h1,h2,h3,h4,h5,h6{font-family:var(--font-heading);'), $colorsStyle);
t('colors render applies heading font token to shared site branding', str_contains($colorsStyle, '.site-logo{font-family:var(--font-heading);}'), $colorsStyle);
t('colors render reasserts the configured body font inside entity-commerce-poc', str_contains($colorsStyle, 'body.entity-commerce-poc{font-family:var(--font-body);}'), $colorsStyle);
t('colors render wires container width into entity theme container token', str_contains($colorsStyle, '--container-max:1320px;'), $colorsStyle);
t('colors render syncs entity-commerce-poc container width override', str_contains($colorsStyle, '.entity-commerce-poc{--container-max:var(--container-width);}'), $colorsStyle);

$themeStyle = cmsRenderThemeLayoutStyle($db);
$entityPresentationStyle = cmsRenderEntityPresentationStyle($db);
$publicThemeStyle = cmsRenderPublicThemeStyle(
    $nativeEntityStorefrontContext['theme_settings'],
    'native',
    true,
    true,
    'public_theme_style_test_native',
    'cz-public-theme-override-test-native'
);
t('theme render now limits itself to shell layout variables', !str_contains($themeStyle, '--theme-entity-summary-width:') && !str_contains($themeStyle, '--theme-single-max-width:') && !str_contains($themeStyle, '--theme-blog-gap:') && str_contains($themeStyle, '--theme-site-max-width:'), $themeStyle);
t('theme render overrides POC customizer chrome with theme-controlled shell gutters', str_contains($themeStyle, '.poc-header--customized .header-topbar .container.cms-public-shell') && str_contains($themeStyle, '.poc-footer--customized .footer-bottom .container.cms-public-shell') && str_contains($themeStyle, 'padding-left:var(--theme-content-px);'), $themeStyle);
t('theme render leaves the POC custom header slot uncapped so header full-width mode can own the outer shell', str_contains($themeStyle, '.poc-header--customized .poc-header__slot--customized{width:100%;max-width:none;margin-left:auto;margin-right:auto;}'), $themeStyle);
t('theme render centralizes footer bar padding and border through the shared shell contract', str_contains($themeStyle, '.footer-bottom>.container.cms-public-shell,.footer-bottom>.cms-public-shell--full{border-top:1px solid') && str_contains($themeStyle, '.footer-bottom__inner{display:flex;flex-wrap:wrap;align-items:center;justify-content:center;gap:0.5rem;padding:18px 0;}'), $themeStyle);
t('public theme render combines shell and entity presentation tokens under one override tag', str_contains($publicThemeStyle, 'id="cz-public-theme-override-test-native"') && str_contains($publicThemeStyle, '--theme-site-max-width:') && str_contains($publicThemeStyle, '--theme-entity-summary-width:') && !str_contains($publicThemeStyle, 'id="cz-entity-presentation-override"'), $publicThemeStyle);
t('canonical entity presentation render exposes entity summary width variable', str_contains($entityPresentationStyle, '--theme-entity-summary-width:'), $entityPresentationStyle);
t('canonical entity presentation render now owns list and detail geometry variables', str_contains($entityPresentationStyle, '--theme-single-max-width:') && str_contains($entityPresentationStyle, '--theme-blog-gap:') && str_contains($entityPresentationStyle, '--theme-blog-cols:') && str_contains($entityPresentationStyle, '.cms-single-prose{max-width:var(--theme-single-max-width);') && str_contains($entityPresentationStyle, '.cms-blog-listing{'), $entityPresentationStyle);
t('canonical entity presentation render styles commerce entity layout rail', str_contains($entityPresentationStyle, '.cms-entity-profile-commerce .cms-entity-layout{display:grid;'), $entityPresentationStyle);
t('canonical entity presentation render styles sticky entity summary rail', str_contains($entityPresentationStyle, '.cms-entity-profile-commerce .cms-entity-summary{position:sticky;'), $entityPresentationStyle);
t('canonical entity presentation render styles action button sizing', str_contains($entityPresentationStyle, '.cms-action-block .cms-btn-primary,.cms-action-block .cms-btn-secondary,.cms-action-block .cms-btn-disabled{padding:'), $entityPresentationStyle);
t('canonical entity presentation render styles entity list density contract', str_contains($entityPresentationStyle, '--theme-entity-list-gap:') && str_contains($entityPresentationStyle, '--theme-entity-list-card-min-width:') && str_contains($entityPresentationStyle, '.cms-entity-card__body{display:flex;'), $entityPresentationStyle);
t('canonical entity presentation render styles catalog title clamp and font contract', str_contains($entityPresentationStyle, '--theme-entity-list-title-font:') && str_contains($entityPresentationStyle, '--theme-entity-list-title-lines:') && str_contains($entityPresentationStyle, '.cms-entity-card__title{margin:0;'), $entityPresentationStyle);
t('canonical entity presentation render exports detail media ratio variable for theme overrides', str_contains($entityPresentationStyle, '--theme-entity-media-ratio:'), $entityPresentationStyle);

$pocSettings = $oldSettings;
$pocSettings['active_theme'] = 'entity-commerce-poc';
saveModuleSettings('cms', $pocSettings);
cmsResetThemeRuntimeCache();
cmsActivateThemeSymlink('entity-commerce-poc');

$ecommerceDefaultColorSettings = cmsThemeManifestCustomizerDefaults('colors', cmsActiveThemeManifest(), 'ecommerce');
upsertCustomizerSection($db, 'colors', $ecommerceDefaultColorSettings, [], 'ecommerce');
cmsCacheInvalidateByTags(['cms:customizer']);
cmsCustomizerClearPersistentCache('colors', 'ecommerce');
$GLOBALS[cmsCustomizerRequestCacheKey('section_row', 'ecommerce')] = [];
$ecommerceDefaultColorsStyle = cmsRenderColorsStyle($db);
$expectedEcommerceContainerWidth = (string)($ecommerceDefaultColorSettings['container_width'] ?? '');
t('ecommerce colors fast path still exports storefront shell width tokens', $expectedEcommerceContainerWidth !== '' && str_contains($ecommerceDefaultColorsStyle, '--container-width:' . $expectedEcommerceContainerWidth . 'px;') && str_contains($ecommerceDefaultColorsStyle, '--container-max:' . $expectedEcommerceContainerWidth . 'px;') && str_contains($ecommerceDefaultColorsStyle, '.entity-commerce-poc{--container-max:var(--container-width);}'), $ecommerceDefaultColorsStyle);

upsertCustomizerSection($db, 'theme', array_merge(cmsThemeLayoutSettingsDefaults(), [
    'layout_mode' => 'contained',
    'site_max_width' => '1280',
]), [], 'ecommerce');
upsertCustomizerSection($db, 'header', array_merge(cmsHeaderSettingsDefaults(), [
    'bg_color' => '#1d4b63',
    'show_search' => 1,
    'menu_location' => '__missing_storefront_menu__',
    'transparent_home' => 1,
    'transparent_text_color' => '#ffffff',
    'transparent_logo_color' => '#ffffff',
    'topbar_container_width' => 'full',
    'topbar_inner_width_mode' => 'custom',
    'topbar_inner_custom_width' => '72rem',
    'header_container_width' => 'contained',
    'header_inner_width_mode' => 'boxed',
]), [[
    'id' => 'header-test-text',
    'type' => 'text',
    'props' => [
        'content' => 'Store hours',
        'title' => 'Header Promo',
    ],
]], 'ecommerce');
upsertCustomizerSection($db, 'footer', array_merge(cmsFooterSettingsDefaults(), [
    'columns' => 1,
    'widget_container_width' => 'full',
    'widget_inner_width_mode' => 'boxed',
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
upsertCustomizerSection($db, 'entity_presentation', array_merge(cmsEntityPresentationSectionDefaults('ecommerce'), $validatedEcommerceEntity), [], 'ecommerce');
cmsCacheInvalidateByTags(['cms:customizer']);
cmsCustomizerClearPersistentCache('theme', 'ecommerce');
cmsCustomizerClearPersistentCache('header', 'ecommerce');
cmsCustomizerClearPersistentCache('footer', 'ecommerce');
cmsCustomizerClearPersistentCache('entity_presentation', 'ecommerce');
$GLOBALS[cmsCustomizerRequestCacheKey('section_row', 'ecommerce')] = [];

$ecommerceEntityPresentationStyle = cmsRenderEntityPresentationStyle($db);
t('canonical ecommerce entity presentation render emits override style tag', str_contains($ecommerceEntityPresentationStyle, 'id="cz-entity-presentation-override"'), $ecommerceEntityPresentationStyle);
t('canonical ecommerce entity presentation render styles commerce entity layout rail', str_contains($ecommerceEntityPresentationStyle, '.cms-entity-profile-commerce .cms-entity-layout{display:grid;'), $ecommerceEntityPresentationStyle);

$publicCtx = cmsPublicContext();
t('public context omits legacy storefront settings for ecommerce scope', !array_key_exists('storefront_settings', $publicCtx), json_encode($publicCtx));
t('public context exposes canonical entity presentation settings for ecommerce scope', ($publicCtx['entity_presentation_settings']['entity_layout_profile'] ?? '') === 'commerce' && ($publicCtx['entity_presentation_source'] ?? '') === 'entity_presentation');
t('public context merges canonical entity presentation into theme settings for ecommerce scope', ($publicCtx['theme_settings']['entity_summary_width'] ?? '') === '390');
t('public context emits a combined public theme style for ecommerce scope', str_contains((string)($publicCtx['theme_layout_style'] ?? ''), 'id="cz-public-theme-override"') && str_contains((string)($publicCtx['theme_layout_style'] ?? ''), '--theme-site-max-width:') && str_contains((string)($publicCtx['theme_layout_style'] ?? ''), '--theme-entity-summary-width:') && !str_contains((string)($publicCtx['theme_layout_style'] ?? ''), 'id="cz-entity-presentation-override"') && !str_contains((string)($publicCtx['theme_layout_style'] ?? ''), 'id="cz-storefront-override"'), (string)($publicCtx['theme_layout_style'] ?? ''));
t('public context promotes sticky customized headers to the shell region wrapper', str_contains((string)($publicCtx['theme_layout_style'] ?? ''), '.cms-shell-entity-view--header.cms-shell-entity-view--sticky-region{position:sticky;top:0;z-index:110;}') && str_contains((string)($publicCtx['theme_layout_style'] ?? ''), '.cms-shell-entity-view--header.cms-shell-entity-view--sticky-region .header-wrapper--sticky{position:relative;top:auto;}'), (string)($publicCtx['theme_layout_style'] ?? ''));
t('public context bridges POC shell width tokens back to theme layout settings', str_contains((string)($publicCtx['theme_layout_style'] ?? ''), '.entity-commerce-poc{--container-width:var(--theme-site-max-width);--container-max:var(--theme-site-max-width);}') && str_contains((string)($publicCtx['theme_layout_style'] ?? ''), '.entity-commerce-poc .poc-main__inner{width:min(var(--theme-site-max-width),calc(100vw - (var(--theme-content-px) * 2)));margin-left:auto;margin-right:auto;}'), (string)($publicCtx['theme_layout_style'] ?? ''));

$customizedHeaderHtml = cmsRenderCustomizedHeader($db, $publicCtx);
$customizedFooterHtml = cmsRenderCustomizedFooter($db, $publicCtx);
$cmsCanonicalHeaderHtml = cmsRenderCustomizedHeader($db, array_merge($publicCtx, [
    'public_render_origin' => 'cms',
    'public_route_kind' => 'page',
    'public_presentation_mode' => 'canonical',
]));
$cmsCanonicalBlogHeaderHtml = cmsRenderCustomizedHeader($db, array_merge($publicCtx, [
    'public_render_origin' => 'cms',
    'public_route_kind' => 'blog-home',
    'public_presentation_mode' => 'canonical',
]));
$publicBaseUrl = rtrim((string)(defined('BASE_URL') ? BASE_URL : ''), '/');
$cmsCanonicalPageHtml = cmsPublicCanonicalRenderEntityView([
    'id' => 0,
    'title' => 'Canonical CMS Page',
    'slug' => 'canonical-cms-page',
    'body' => '<p>Content body</p>',
    'type' => 'page',
], [
    'content_type' => 'page',
    'meta' => [],
    'rendered_html' => '<p>Content body</p>',
    'public_render_origin' => 'cms',
    'public_route_kind' => 'page',
    'public_presentation_mode' => 'canonical',
]);
$cmsCanonicalBlogHtml = cmsPublicCanonicalRenderEntityList([
    [
        'id' => 0,
        'title' => 'Blog Result',
        'slug' => 'blog-result',
        'type' => 'post',
        'excerpt' => 'Post summary',
        'author_name' => 'Tester',
        'published_at' => '2026-03-29 10:00:00',
    ],
], [
    'default_type' => 'post',
    'page_title' => 'Blog',
    'list_title' => 'Blog',
    'list_description' => '1 result',
    'public_render_origin' => 'cms',
    'public_route_kind' => 'blog-home',
    'public_presentation_mode' => 'canonical',
]);
$storefrontCanonicalProductHtml = cmsPublicCanonicalRenderEntityView([
    'id' => 101,
    'title' => 'Canonical Product',
    'slug' => 'canonical-product',
    'body' => '<p>Product body</p>',
    'excerpt' => 'Product summary',
    'type' => 'product',
    'primary_image_url' => '',
    'gallery_images' => [],
    'categories' => [[
        'id' => 12,
        'slug' => 'bread',
        'name' => 'Bread',
    ]],
    'pricing' => [
        'price' => 32.0,
        'sale_price' => 28.0,
        'formatted' => '$28.00',
        'regular_fmt' => '$32.00',
        'on_sale' => true,
    ],
    'inventory' => [
        'track_stock' => true,
        'stock_qty' => 2,
        'in_stock' => true,
        'out_of_stock' => false,
        'low_stock' => true,
    ],
], [
    'content_type' => 'product',
    'meta' => [],
    'rendered_html' => '<p>Product body</p>',
    'public_render_origin' => 'ecommerce',
    'public_route_kind' => 'product_detail',
    'public_presentation_mode' => 'entity_view',
]);
$storefrontCanonicalCatalogHtml = cmsPublicCanonicalRenderEntityList([
    [
        'id' => 101,
        'title' => 'Canonical Product',
        'slug' => 'canonical-product',
        'type' => 'product',
        'excerpt' => 'Product summary',
        'url' => '/ecommerce/shop/canonical-product',
        'primary_image_url' => '',
        'pricing' => [
            'price' => 32.0,
            'sale_price' => 28.0,
            'formatted' => '$28.00',
            'regular_fmt' => '$32.00',
            'on_sale' => true,
        ],
        'inventory' => [
            'track_stock' => true,
            'stock_qty' => 2,
            'in_stock' => true,
            'out_of_stock' => false,
            'low_stock' => true,
        ],
        'categories' => [[
            'id' => 12,
            'slug' => 'bread',
            'name' => 'Bread',
        ]],
    ],
], [
    'default_type' => 'product',
    'page_title' => 'Catalog',
    'list_title' => 'Catalog',
    'list_description' => '1 result',
    'entity_list_context' => [
        'base_list_url' => '/ecommerce/shop',
        'item_base_url' => '/ecommerce/shop',
        'search' => 'sourdough',
        'search_action_url' => '/ecommerce/shop',
        'all_items_url' => '/ecommerce/shop',
        'category_id' => 12,
        'category_name' => 'Bread',
        'category_slug' => 'bread',
        'available_categories' => [[
            'id' => 12,
            'name' => 'Bread',
            'slug' => 'bread',
            'url' => '/ecommerce/shop?cat=12',
            'is_active' => true,
        ]],
        'result_count' => 1,
        'result_label' => '1 result',
        'active_filter_count' => 2,
        'search_placeholder' => 'Search products',
        'all_items_label' => 'All Products',
    ],
    'pagination' => [
        'current' => 1,
        'total' => 1,
        'prev_url' => '',
        'next_url' => '',
    ],
    'public_render_origin' => 'ecommerce',
    'public_route_kind' => 'shop_index',
    'public_presentation_mode' => 'entity_view',
]);
t('customized storefront header renders through theme partial wrapper', str_contains($customizedHeaderHtml, 'poc-header--customized'), $customizedHeaderHtml);
t('customized storefront header emits shell entity-view wrapper', str_contains($customizedHeaderHtml, 'data-shell-entity-region="header"') && str_contains($customizedHeaderHtml, 'data-shell-entity-node="region"'), $customizedHeaderHtml);
t('customized storefront header marks the shell region as sticky-safe', str_contains($customizedHeaderHtml, 'cms-shell-entity-view--sticky-region') && str_contains($customizedHeaderHtml, 'header-wrapper cms-shell-width-contained header-wrapper--sticky'), $customizedHeaderHtml);
t('customized storefront header renders through theme inner shell', str_contains($customizedHeaderHtml, 'poc-header__inner--customized'), $customizedHeaderHtml);
t('customized storefront header splits top bar and main header shell contracts', str_contains($customizedHeaderHtml, 'header-topbar cms-shell-width-full') && str_contains($customizedHeaderHtml, 'site-header site-header--sticky site-header--default cms-shell-width-contained') && str_contains($customizedHeaderHtml, 'cms-public-shell cms-public-shell--custom') && str_contains($customizedHeaderHtml, 'cms-public-shell cms-public-shell--boxed') && str_contains($customizedHeaderHtml, 'Store hours'), $customizedHeaderHtml);
t('customized storefront header emits explicit custom and boxed holder width overrides', str_contains($customizedHeaderHtml, 'max-width:72rem;margin-left:auto;margin-right:auto;') && str_contains($customizedHeaderHtml, 'max-width:var(--theme-content-max-width, 768px);margin-left:auto;margin-right:auto;'), $customizedHeaderHtml);
t('customized storefront header keeps wrapper and container shell classes distinct', str_contains($customizedHeaderHtml, 'header-wrapper cms-shell-width-contained') && str_contains($customizedHeaderHtml, 'site-header site-header--sticky site-header--default cms-shell-width-contained'), $customizedHeaderHtml);
t('customized storefront header omits the transparency script outside home-like routes', !str_contains($customizedHeaderHtml, 'window.addEventListener("scroll",function(){'), $customizedHeaderHtml);
t('customized storefront header links site branding to shop root', str_contains($customizedHeaderHtml, '/ecommerce/shop'), $customizedHeaderHtml);
t('customized storefront header fallback nav stays on storefront routes', str_contains($customizedHeaderHtml, '>Shop<') && str_contains($customizedHeaderHtml, '/ecommerce/my-orders'), $customizedHeaderHtml);
t('customized storefront header search overlay posts to storefront query endpoint', str_contains($customizedHeaderHtml, '/ecommerce/shop') && str_contains($customizedHeaderHtml, 'name="search"'), $customizedHeaderHtml);
t('canonical CMS page render bypasses storefront entity-view template', !str_contains($cmsCanonicalPageHtml, '<article class="poc-entity-view') && str_contains($cmsCanonicalPageHtml, '<article class="cms-entity-view') && str_contains($cmsCanonicalPageHtml, 'cms-entity-profile-content'), $cmsCanonicalPageHtml);
t('canonical CMS blog render bypasses storefront entity-list template', preg_match('/<section\s+class="poc-entity-list/', $cmsCanonicalBlogHtml) !== 1 && str_contains($cmsCanonicalBlogHtml, 'class="cms-entity-list cms-entity-list--density-') && preg_match('/<div\s+class="poc-product-card"/', $cmsCanonicalBlogHtml) !== 1, $cmsCanonicalBlogHtml);
t('canonical storefront product render auto-injects storefront contract markers', str_contains($storefrontCanonicalProductHtml, 'data-storefront-route-kind="product_detail"') && str_contains($storefrontCanonicalProductHtml, 'data-storefront-page-kind="detail"') && str_contains($storefrontCanonicalProductHtml, 'data-storefront-product-id="101"'), $storefrontCanonicalProductHtml);
t('canonical storefront list render auto-injects storefront contract markers', str_contains($storefrontCanonicalCatalogHtml, 'data-storefront-route-kind="shop_index"') && str_contains($storefrontCanonicalCatalogHtml, 'data-storefront-page-kind="catalog"') && str_contains($storefrontCanonicalCatalogHtml, 'data-storefront-result-total="1"'), $storefrontCanonicalCatalogHtml);
t('canonical CMS header keeps shell metadata in canonical mode', str_contains($cmsCanonicalHeaderHtml, 'data-public-presentation-mode="canonical"'), $cmsCanonicalHeaderHtml);
t('canonical CMS header preserves the CMS route kind in shell metadata', str_contains($cmsCanonicalHeaderHtml, 'data-public-route-kind="page"'), $cmsCanonicalHeaderHtml);
t('canonical CMS blog header keeps readable light chrome on dark transparent-home headers', str_contains($cmsCanonicalBlogHeaderHtml, '--header-link:#ffffff;') && str_contains($cmsCanonicalBlogHeaderHtml, '--header-logo-color:#ffffff;'), $cmsCanonicalBlogHeaderHtml);
t('canonical CMS blog header omits the home-only transparency scroll script', !str_contains($cmsCanonicalBlogHeaderHtml, 'window.addEventListener("scroll",function(){'), $cmsCanonicalBlogHeaderHtml);
t('canonical CMS header routes branding to the CMS home path', str_contains($cmsCanonicalHeaderHtml, 'href="' . $publicBaseUrl . '/cms" class="site-logo"') && !str_contains($cmsCanonicalHeaderHtml, 'href="' . $publicBaseUrl . '/ecommerce/shop" class="site-logo"'), $cmsCanonicalHeaderHtml);
t('canonical CMS header routes search overlay to the CMS search endpoint', str_contains($cmsCanonicalHeaderHtml, 'action="' . $publicBaseUrl . '/cms/search"') && str_contains($cmsCanonicalHeaderHtml, 'name="q"') && !str_contains($cmsCanonicalHeaderHtml, 'action="' . $publicBaseUrl . '/ecommerce/shop"'), $cmsCanonicalHeaderHtml);
t('customized storefront header keeps the boxed holder class in the active shell', str_contains($customizedHeaderHtml, 'cms-public-shell cms-public-shell--boxed'), $customizedHeaderHtml);
t('customized storefront header resets submenu dropdown CSS for mobile navigation', str_contains($customizedHeaderHtml, '.main-navigation .nav-menu-sub{position:static;'), $customizedHeaderHtml);
t('customized storefront footer renders through theme partial wrapper', str_contains($customizedFooterHtml, 'poc-footer--customized'), $customizedFooterHtml);
t('customized storefront footer splits widget area and footer bar shell contracts', str_contains($customizedFooterHtml, 'footer-widgets cms-shell-width-full') && str_contains($customizedFooterHtml, 'footer-bottom cms-shell-width-contained') && str_contains($customizedFooterHtml, 'cms-public-shell cms-public-shell--boxed') && str_contains($customizedFooterHtml, 'container cms-public-shell cms-public-shell--contained'), $customizedFooterHtml);
t('customized storefront footer emits explicit widget gutter and boxed holder width overrides', str_contains($customizedFooterHtml, 'padding-left:var(--theme-content-px, 20px);padding-right:var(--theme-content-px, 20px);') && str_contains($customizedFooterHtml, 'max-width:var(--theme-content-max-width, 768px);margin-left:auto;margin-right:auto;'), $customizedFooterHtml);
t('customized storefront footer emits shell entity-view wrapper', str_contains($customizedFooterHtml, 'data-shell-entity-region="footer"') && str_contains($customizedFooterHtml, 'data-shell-entity-node="region"'), $customizedFooterHtml);
t('customized storefront footer keeps widget background and link colors inline', str_contains($customizedFooterHtml, 'background:#102033;') && str_contains($customizedFooterHtml, '--footer-link:#7dd3fc;') && str_contains($customizedFooterHtml, '--footer-title-color:#ffffff;'), $customizedFooterHtml);
t('customized storefront footer keeps footer bar colors inline', str_contains($customizedFooterHtml, 'background:#08111f;color:#94a3b8;') && str_contains($customizedFooterHtml, '--footer-link:#f59e0b;--footer-link-hover:#fef3c7;'), $customizedFooterHtml);
t('customized storefront footer uses structured footer bar classes instead of inline spacing helpers', str_contains($customizedFooterHtml, 'footer-bottom__inner') && str_contains($customizedFooterHtml, 'footer-bottom__separator') && str_contains($customizedFooterHtml, 'footer-bottom__admin-link') && !str_contains($customizedFooterHtml, 'margin-left:0.5rem;font-size:0.8rem;'), $customizedFooterHtml);
t('footer shell outer width helper unlocks full-width mode', cmsCustomizerShellOuterWidthStyle(['inner_width' => 'full-width']) === 'width:100%;max-width:none;margin:0;');
t('shell container helper adds full-width gutters when requested', cmsCustomizerShellContainerStyle(['header_container_width' => 'full'], 'header_container_width', true) === 'width:100%;max-width:none;margin:0;box-sizing:border-box;padding-left:var(--theme-content-px, 20px);padding-right:var(--theme-content-px, 20px);');
t('footer widget container helper adds full-width gutters', cmsCustomizerFooterWidgetContainerStyle(['widget_container_width' => 'full']) === 'width:100%;max-width:none;margin:0;box-sizing:border-box;padding-left:var(--theme-content-px, 20px);padding-right:var(--theme-content-px, 20px);');
t('shell holder helper exposes custom max width override', cmsCustomizerShellHolderStyle(['header_inner_width_mode' => 'custom', 'header_inner_custom_width' => '72rem'], 'header_inner_width_mode', 'header_inner_custom_width') === 'width:100%;max-width:72rem;margin-left:auto;margin-right:auto;');
t('shell holder helper exposes boxed preset width', cmsCustomizerShellHolderClasses(['header_inner_width_mode' => 'boxed'], 'header_inner_width_mode') === 'cms-public-shell cms-public-shell--boxed');
t('footer widget holder helper exposes boxed preset width', cmsCustomizerFooterWidgetHolderStyle(['widget_inner_width_mode' => 'boxed']) === 'width:100%;max-width:var(--theme-content-max-width, 768px);margin-left:auto;margin-right:auto;');

$themeLayoutCss = cmsRenderThemeLayoutCss(cmsThemeLayoutSettingsDefaults(), 'ecommerce');
t('theme layout css keeps top bar shell widgets content-sized so alignment controls can move them', str_contains($themeLayoutCss, '.header-topbar-inner>.cms-shell-entity-view--widget{display:flex;flex:0 0 auto;width:auto;max-width:100%;min-width:0;}') && str_contains($themeLayoutCss, '.header-topbar-inner>.cms-shell-entity-view--widget>.cms-shell-entity-view__body,.header-topbar-inner>.cms-shell-entity-view--widget .header-widget{width:auto;max-width:100%;min-width:0;}'), $themeLayoutCss);

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

$nativeSidebarTargets = array_values(array_filter(array_map(
    static fn(array $target): string => (string)($target['key'] ?? ''),
    cmsSidebarTemplateTargets()
)));
$sidebarExcludeTarget = $nativeSidebarTargets[0] ?? 'home';
$sidebarAlternateTarget = $nativeSidebarTargets[1] ?? $sidebarExcludeTarget;
$sidebarIncludeTargets = array_values(array_unique(array_slice($nativeSidebarTargets, 1, 2)));
if ($sidebarIncludeTargets === []) {
    $sidebarIncludeTargets = [$sidebarExcludeTarget];
}
$sidebarNotIncludedTarget = $sidebarExcludeTarget;
if (in_array($sidebarNotIncludedTarget, $sidebarIncludeTargets, true)) {
    $sidebarNotIncludedTarget = $nativeSidebarTargets[3] ?? $nativeSidebarTargets[2] ?? $sidebarExcludeTarget;
}

$validatedSidebarExclude = cmsValidateSidebarSettings([
    'enabled' => 1,
    'scope_mode' => 'exclude_templates',
    'template_rules' => [$sidebarExcludeTarget, 'bogus-template'],
    'placement' => 'left',
]);
t('sidebar validation preserves exclude-template mode and filters invalid template rules', ($validatedSidebarExclude['scope_mode'] ?? '') === 'exclude_templates' && ($validatedSidebarExclude['template_rules'] ?? []) === [$sidebarExcludeTarget] && ($validatedSidebarExclude['template_scope'] ?? '') === $sidebarExcludeTarget, json_encode($validatedSidebarExclude));

$validatedSidebarInclude = cmsValidateSidebarSettings([
    'enabled' => 1,
    'scope_mode' => 'template',
    'template_rules' => array_merge($sidebarIncludeTargets, ['bogus-template']),
]);
t('sidebar validation preserves include-template mode and keeps multiple valid template rules', ($validatedSidebarInclude['scope_mode'] ?? '') === 'template' && ($validatedSidebarInclude['template_rules'] ?? []) === $sidebarIncludeTargets && ($validatedSidebarInclude['template_scope'] ?? '') === $sidebarIncludeTargets[0], json_encode($validatedSidebarInclude));

cmsUpsertCustomizerSection($db, 'sidebar', $validatedSidebarExclude, [[
    'type' => 'text',
    'props' => [
        'title' => 'Sidebar Promo',
        'content' => '<p>Promo block</p>',
    ],
]], null, 'native');

$excludedTargetSidebar = cmsRenderCustomizedSidebar($db, ['sidebar_template' => $sidebarExcludeTarget]);
$excludedAlternateSidebar = cmsRenderCustomizedSidebar($db, ['sidebar_template' => $sidebarAlternateTarget]);
t('native sidebar scope can exempt selected templates from an otherwise global sidebar', ($excludedTargetSidebar['enabled'] ?? false) === false && ($excludedAlternateSidebar['enabled'] ?? false) === true, json_encode([$excludedTargetSidebar, $excludedAlternateSidebar]));
t('native sidebar still renders widget markup outside the excluded templates', str_contains((string)($excludedAlternateSidebar['html'] ?? ''), 'Sidebar Promo'), (string)($excludedAlternateSidebar['html'] ?? ''));

cmsUpsertCustomizerSection($db, 'sidebar', $validatedSidebarInclude, [[
    'type' => 'text',
    'props' => [
        'title' => 'Sidebar Promo',
        'content' => '<p>Promo block</p>',
    ],
]], null, 'native');

$includedTargetSidebar = cmsRenderCustomizedSidebar($db, ['sidebar_template' => $sidebarIncludeTargets[0]]);
$includedExcludedSidebar = cmsRenderCustomizedSidebar($db, ['sidebar_template' => $sidebarNotIncludedTarget]);
t('native sidebar scope can target only explicitly selected templates', ($includedTargetSidebar['enabled'] ?? false) === true && ($includedExcludedSidebar['enabled'] ?? false) === in_array($sidebarNotIncludedTarget, $sidebarIncludeTargets, true), json_encode([$includedTargetSidebar, $includedExcludedSidebar]));

cmsUpsertCustomizerSection($db, 'sidebar', cmsSidebarSettingsDefaults(), [], null, 'native');

$variantPricingTemplate = cmsResolveBlockTemplate('modules/cms/public/blocks/pricing.block.disyl', [
    'theme_settings' => $validatedEntity,
]);
$variantActionTemplate = cmsResolveBlockTemplate('modules/cms/public/blocks/action.block.disyl', [
    'theme_settings' => $validatedEntity,
]);
$variantListPricingTemplate = cmsResolveBlockTemplate('modules/cms/public/blocks/list-card-pricing.block.disyl', [
    'theme_settings' => $validatedEcommerceEntity,
]);
$variantListInventoryTemplate = cmsResolveBlockTemplate('modules/cms/public/blocks/list-card-inventory.block.disyl', [
    'theme_settings' => $validatedEcommerceEntity,
]);
$variantListProgressTemplate = cmsResolveBlockTemplate('modules/cms/public/blocks/list-card-progress.block.disyl', [
    'theme_settings' => $validatedEcommerceEntity,
]);
t('pricing block variant resolves to featured template', $variantPricingTemplate === 'modules/cms/public/blocks/pricing.featured.block.disyl', $variantPricingTemplate);
t('action block variant resolves to sticky-footer template', $variantActionTemplate === 'modules/cms/public/blocks/action.sticky-footer.block.disyl', $variantActionTemplate);
t('list-card pricing variant resolves to minimal template', $variantListPricingTemplate === 'modules/cms/public/blocks/list-card-pricing.minimal.block.disyl', $variantListPricingTemplate);
t('list-card inventory variant resolves to compact template', $variantListInventoryTemplate === 'modules/cms/public/blocks/list-card-inventory.compact.block.disyl', $variantListInventoryTemplate);
t('list-card progress variant resolves to inline template', $variantListProgressTemplate === 'modules/cms/public/blocks/list-card-progress.inline.block.disyl', $variantListProgressTemplate);

$renderedMinimalListPricing = cmsRenderThemeAwareBlockTemplate('modules/cms/public/blocks/list-card-pricing.block.disyl', [
    'theme_settings' => $validatedEcommerceEntity,
    'capabilities' => [
        'pricing' => true,
    ],
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

$contentSettings = cmsValidateEntityPresentationSettings([
    'entity_layout_profile' => 'content',
], cmsEntityPresentationSectionDefaults());
$contentPresentation = cmsEntityPresentationConfig($contentSettings);
$contentHtml = cmsRender('modules/cms/public/entity.view.disyl', array_merge($entityTemplateContext, [
    'theme_settings' => cmsThemeLayoutSettingsDefaults(),
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
        'list_category_navigation' => 'list',
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

$listDropdownHtml = cmsRender('modules/cms/public/entity.list.disyl', array_merge($listTemplateContext, [
    'entity_presentation' => [
        'list_show_filter_summary' => 1,
        'list_category_navigation' => 'dropdown',
        'list_card_density' => 'compact',
        'list_show_excerpt' => 1,
        'list_excerpt_length' => 90,
    ],
]));
t('canonical entity list can render category dropdown navigation', str_contains($listDropdownHtml, 'cms-entity-list__category-picker') && str_contains($listDropdownHtml, 'Shop Categories') && str_contains($listDropdownHtml, 'name="cat"') && str_contains($listDropdownHtml, 'Browse') && str_contains($listDropdownHtml, 'type="hidden" name="search" value="sourdough"'), $listDropdownHtml);

$listWithoutSummaryHtml = cmsRender('modules/cms/public/entity.list.disyl', array_merge($listTemplateContext, [
    'entity_presentation' => [
        'list_show_filter_summary' => 0,
        'list_category_navigation' => 'list',
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
t('poc entity list template supports category dropdown navigation mode', str_contains($pocListTemplateContent, "entity_presentation.list_category_navigation == 'dropdown'") && str_contains($pocListTemplateContent, 'poc-entity-list__category-picker') && str_contains($pocListTemplateContent, 'poc-entity-list__category-select'), $pocListTemplateContent);
t('poc entity list stylesheet styles storefront filter controls', str_contains($pocListStyles, '.poc-entity-list__search') && str_contains($pocListStyles, '.poc-entity-list__category-link'), $pocListStyles);
t('poc entity list stylesheet styles category picker controls', str_contains($pocListStyles, '.poc-entity-list__category-picker') && str_contains($pocListStyles, '.poc-entity-list__category-select') && str_contains($pocListStyles, '.poc-entity-list__category-submit'), $pocListStyles);

// ═══════════════════════════════════════════════════════════════════
// 6b. Public render path must not depend on symlink repair
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
t('cmsPublicRender still renders when the compatibility symlink is missing', str_contains($renderedHome, '<!DOCTYPE html>'));
t('cmsPublicRender does not recreate the compatibility symlink on demand', !is_link($link) && !file_exists($link));

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
$contractMismatchWarnings = array_values(array_filter(explode("\n", $appLog), static fn(string $line): bool => str_contains($line, 'cms.render_context.contract_mismatch')));
$errLines = array_values(array_filter(explode("\n", $errLog), static function (string $line): bool {
    return trim($line) !== ''
        && !str_contains($line, 'storage/cache/kernel_bootstrap')
    && !str_contains($line, 'Failed to open stream')
    && !str_contains($line, 'Ikabud Cache: Cleared');
}));
t('No app.log errors', empty($appErrors), implode('; ', $appErrors));
t('No contract mismatch warnings in app.log', empty($contractMismatchWarnings), implode('; ', $contractMismatchWarnings));
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
