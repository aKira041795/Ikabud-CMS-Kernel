<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────
// Ecommerce Module — Bootstrap (helpers/00-init.php)
//
// Responsibilities:
//  1. Auto-page installation guard (once per tenant)
//  2. CMS admin nav injection via cms.admin.nav_items hook
//  3. CapabilityBus provider registration (pricing + inventory overrides)
//  4. Module CapabilityBus handlers (products.list, products.get, etc.)
// ─────────────────────────────────────────────────────────────────────────

// ── Core accessor helpers ────────────────────────────────────────────

/**
 * Returns the base URL for the current request (scheme + host).
 * Falls back to app.url config when HTTP_HOST is unavailable (e.g. CLI).
 */
function ecGetBaseUrl(): string
{
    return external_base_url((string)app()->config('app.url', ''));
}

function ecDb(): \Ikabud\Kernel\Contracts\ModuleDB
{
    $ctx = module('ecommerce');
    if (!$ctx) {
        throw new \RuntimeException('Ecommerce module context unavailable');
    }
    return $ctx->db();
}

function ecCtx(): \Ikabud\Kernel\Contracts\ModuleContext
{
    $ctx = module('ecommerce');
    if (!$ctx) {
        throw new \RuntimeException('Ecommerce module context unavailable');
    }
    return $ctx;
}

function ecIsPublicTemplate(string $template): bool
{
    return str_starts_with($template, 'modules/ecommerce/public/') || $template === 'pages/404.disyl';
}

function ecInferPublicRouteKind(string $template, array $context = []): string
{
    $routeKind = trim((string)($context['public_route_kind'] ?? $context['ecommerce_public_route'] ?? ''));
    if ($routeKind !== '') {
        return $routeKind;
    }

    return match ($template) {
        'modules/ecommerce/public/shop.disyl' => 'shop_index',
        'modules/ecommerce/public/product.disyl' => 'product_detail',
        'modules/ecommerce/public/cart.disyl' => 'cart',
        'modules/ecommerce/public/checkout.disyl' => 'checkout',
        'modules/ecommerce/public/order-confirmation.disyl' => 'order_confirmation',
        'modules/ecommerce/public/my-orders.disyl' => 'my_orders',
        'modules/ecommerce/public/order-detail.disyl' => 'order_detail',
        'pages/404.disyl' => 'not_found',
        default => 'generic',
    };
}

function ecNormalizePublicRouteKind(string $routeKind): string
{
    $routeKind = trim($routeKind);
    if ($routeKind === '') {
        $routeKind = 'generic';
    }

    if (function_exists('cmsNormalizeEcommercePublicRouteKind')) {
        $routeKind = cmsNormalizeEcommercePublicRouteKind($routeKind);
    }

    return $routeKind;
}

function ecPublicThemeTemplateCandidates(string $template, array $context = []): array
{
    if (!ecIsPublicTemplate($template) || $template === 'pages/404.disyl') {
        return [$template];
    }

    $routeKind = ecInferPublicRouteKind($template, $context);
    $themeCandidates = match ($routeKind) {
        'shop_index', 'shop_category' => [
            '_cms_active_theme/public/ecommerce/archive-product.disyl',
            '_cms_active_theme/public/ecommerce/shop.disyl',
        ],
        'product_detail' => [
            '_cms_active_theme/public/ecommerce/single-product.disyl',
            '_cms_active_theme/public/ecommerce/product.disyl',
        ],
        'cart' => ['_cms_active_theme/public/ecommerce/cart.disyl'],
        'checkout' => ['_cms_active_theme/public/ecommerce/checkout.disyl'],
        'order_confirmation' => [
            '_cms_active_theme/public/ecommerce/order-confirmation.disyl',
            '_cms_active_theme/public/ecommerce/thankyou.disyl',
        ],
        'my_orders' => ['_cms_active_theme/public/ecommerce/my-orders.disyl'],
        'order_detail' => ['_cms_active_theme/public/ecommerce/order-detail.disyl'],
        default => [],
    };

    return array_values(array_unique(array_merge($themeCandidates, [$template])));
}

function ecResolvePublicThemeTemplate(string $template, array $context = []): string
{
    $candidates = ecPublicThemeTemplateCandidates($template, $context);
    if (count($candidates) === 1) {
        return $template;
    }

    if (!function_exists('cmsActiveTheme')
        || !function_exists('cmsActiveThemeManifest')
        || !function_exists('cmsEnsureThemeSymlink')
        || !defined('CMS_THEME_SYMLINK')) {
        return $template;
    }

    if (cmsActiveTheme() === null) {
        return $template;
    }

    $manifest = cmsActiveThemeManifest();
    if (!empty($manifest['restrict_to_tokens'])) {
        return $template;
    }

    cmsEnsureThemeSymlink();

    foreach ($candidates as $candidate) {
        if (!str_starts_with($candidate, '_cms_active_theme/')) {
            continue;
        }

        $relativePath = substr($candidate, strlen('_cms_active_theme/'));
        if ($relativePath === false || $relativePath === '') {
            continue;
        }

        if (is_file((string)CMS_THEME_SYMLINK . '/' . $relativePath)) {
            return $candidate;
        }
    }

    return $template;
}

function ecResolvePublicPresentationMode(?string $routeKind = null, array $context = []): string
{
    $resolvedContext = $context;
    if ($routeKind !== null && $routeKind !== '') {
        $resolvedContext['public_route_kind'] = $routeKind;
    }

    if (function_exists('cmsEcommercePublicPresentationMode')) {
        return cmsEcommercePublicPresentationMode($resolvedContext);
    }

    return 'traditional';
}

function ecWithPublicThemeRouteContext(array $context, callable $callback): mixed
{
    if (function_exists('cmsWithPublicThemeContext')) {
        return cmsWithPublicThemeContext($context, $callback);
    }

    return $callback();
}

function ecRouteUsesCanonicalEntityRendering(?string $routeKind = null, array $context = []): bool
{
    $resolvedRouteKind = trim((string)($routeKind ?? ''));
    if ($resolvedRouteKind === '') {
        $resolvedRouteKind = trim((string)($context['public_route_kind'] ?? $context['ecommerce_public_route'] ?? 'generic'));
    }
    $resolvedRouteKind = ecNormalizePublicRouteKind($resolvedRouteKind);

    return ecResolvePublicPresentationMode($resolvedRouteKind, $context) === 'entity_view';
}

function ecAssertTraditionalEntityTemplateAllowed(string $template, array $context = []): void
{
    if (!ecIsPublicTemplate($template)) {
        return;
    }

    $routeKind = ecInferPublicRouteKind($template, $context);
    if (!in_array($routeKind, ['shop_index', 'shop_category', 'product_detail'], true)) {
        return;
    }

    if (!ecRouteUsesCanonicalEntityRendering($routeKind, $context)) {
        return;
    }

    throw new RuntimeException(
        'Traditional ecommerce template "' . $template . '" is not allowed for entity-view storefront route "' . $routeKind . '".'
    );
}

function ecDispatchCanonicalEntityRoute(string $handler, array $payload, array $context = []): bool
{
    $routeKind = trim((string)($context['public_route_kind'] ?? $payload['public_route_kind'] ?? $context['ecommerce_public_route'] ?? $payload['ecommerce_public_route'] ?? 'generic'));
    $routeKind = ecNormalizePublicRouteKind($routeKind);

    $resolvedContext = array_merge($context, $payload, ['public_route_kind' => $routeKind]);
    if (!ecRouteUsesCanonicalEntityRendering($routeKind, $resolvedContext)) {
        return false;
    }

    if (!function_exists('executeModuleHandler')) {
        throw new RuntimeException(
            'Canonical ecommerce entity route "' . $routeKind . '" requires executeModuleHandler() while entity-view mode is active.'
        );
    }

    executeModuleHandler($handler, array_merge($payload, [
        'public_route_kind' => $routeKind,
        'public_presentation_mode' => 'entity_view',
    ]));

    return true;
}

function ecPublicRenderContext(string $template, array $context = []): array
{
    if (!ecIsPublicTemplate($template)) {
        return $context;
    }

    $routeKind = ecNormalizePublicRouteKind(ecInferPublicRouteKind($template, $context));
    $presentationMode = ecResolvePublicPresentationMode($routeKind, $context);

    return array_merge($context, [
        'public_render_origin' => 'ecommerce',
        'public_route_kind' => $routeKind,
        'public_presentation_mode' => $presentationMode,
    ]);
}

function ecRender(string $template, array $context = []): void
{
    if (!array_key_exists('cart_count', $context)) {
        $cart = ecCartGet();
        $context['cart_count'] = (int)($cart['totals']['item_count'] ?? 0);
    }

    if (!array_key_exists('ec_settings', $context)) {
        $context['ec_settings'] = ecSettings();
    }

    if (!array_key_exists('base_url', $context)) {
        $context['base_url'] = ecGetBaseUrl();
    }

    if (!array_key_exists('year', $context)) {
        $context['year'] = date('Y');
    }

    $context = ecPublicRenderContext($template, $context);
    $isPublicTemplate = ecIsPublicTemplate($template);
    ecAssertTraditionalEntityTemplateAllowed($template, $context);
    $render = static function () use ($template, $context, $isPublicTemplate): void {
        if ($isPublicTemplate && function_exists('cmsPublicContext') && function_exists('cmsRenderThemeAwareTemplate')) {
            $html = moduleWithContext('cms', static function () use ($template, $context): string {
                $renderContext = cmsPublicContext($context);
                $renderContext = kernelPrepareRenderContext($template, $renderContext);
                $resolvedTemplate = ecResolvePublicThemeTemplate($template, $renderContext);
                return cmsRenderThemeAwareTemplate($resolvedTemplate, $renderContext);
            });
            echo $html;
            return;
        }

        echo ecCtx()->render($template, kernelPrepareRenderContext($template, $context));
    };

    if ($isPublicTemplate && function_exists('cmsWithPublicThemeContext')) {
        cmsWithPublicThemeContext($context, $render);
        return;
    }

    $render();
}

function ecHasCmsCategoryTaxonomy(): bool
{
    static $hasTaxonomy = null;
    if ($hasTaxonomy !== null) {
        return $hasTaxonomy;
    }

    try {
        ecDb()->query('SELECT taxonomy FROM cms_categories WHERE 1 = 0');
        $hasTaxonomy = true;
    } catch (\Throwable $e) {
        $hasTaxonomy = false;
    }

    return $hasTaxonomy;
}

function ecCmsCategorySelectSql(string $columns = 'id, name, slug', string $orderBy = 'name ASC'): string
{
    $sql = "SELECT {$columns} FROM cms_categories";
    if (ecHasCmsCategoryTaxonomy()) {
        $sql .= " WHERE taxonomy = 'product' OR taxonomy IS NULL";
    }
    $sql .= " ORDER BY {$orderBy}";
    return $sql;
}

/**
 * Parse JSON/form input from the current request.
 */
function ecInput(?string $key = null, mixed $default = null): mixed
{
    return ecCtx()->input($key, $default);
}

/**
 * Module settings — reads tenant-scoped ecommerce settings.
 */
function ecSettingsDefaults(): array
{
    static $defaults = null;
    if ($defaults !== null) {
        return $defaults;
    }

    $defaults = [];
    $modules = discoverModules();
    $manifest = $modules['ecommerce'] ?? [];
    $fields = is_array($manifest['settings_fields'] ?? null) ? $manifest['settings_fields'] : [];

    foreach ($fields as $field) {
        if (!is_array($field)) {
            continue;
        }

        $key = trim((string)($field['key'] ?? ''));
        if ($key === '' || !array_key_exists('default', $field)) {
            continue;
        }

        $defaults[$key] = $field['default'];
    }

    return $defaults;
}

function ecSettings(?string $key = null, mixed $default = null): mixed
{
    static $cache = null;
    if ($cache === null) {
        $cache = array_merge(ecSettingsDefaults(), getModuleSettings('ecommerce'));
    }
    if ($key === null) {
        return $cache;
    }
    return $cache[$key] ?? $default;
}

// ── Auto-page installation ───────────────────────────────────────────

/**
 * Called once per request when module is active.
 * Creates the 5 storefront CMS pages on first enable for this tenant.
 */
function ecMaybeInstallPages(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $settings = readTenantModuleSettings('ecommerce');

    // Ensure 'product' content type exists (idempotent, runs once per tenant).
    if (empty($settings['_product_type_registered'])) {
        try {
            moduleWithContext('cms', static function (): void {
                $db = app()->db();
                $db->execute(
                    "INSERT INTO cms_content_types (slug, label, icon, supports, is_active, sort_order)
                     VALUES ('product', 'Products', 'shopping-bag',
                             '[\"title\",\"body\",\"excerpt\",\"featured_image\",\"slug\"]', 1, 50)
                     ON DUPLICATE KEY UPDATE is_active = 1"
                );
            });
            saveTenantModuleSettings('ecommerce', ['_product_type_registered' => true]);
        } catch (\Throwable $e) {
            // Non-fatal — shop falls back to ecommerce handler
        }
    }

    if (!empty($settings['_pages_installed'])) {
        return;
    }

    $installPages = static function (): void {
        ecInstallPages();
        saveTenantModuleSettings('ecommerce', ['_pages_installed' => true]);
    };

    $tenantId = app()->tenant()->current();
    $maxAttempts = 2;
    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        try {
            $installPages();
            return;
        } catch (\Throwable $e) {
            if (!dbConnectionLost($e) || $attempt >= $maxAttempts) {
                write_log('ecMaybeInstallPages failed: ' . $e->getMessage(), 'warning', [
                    'module' => 'ecommerce',
                    'attempt' => $attempt,
                    'tenant_id' => $tenantId,
                ]);
                return;
            }

            try {
                app()->reconnectDb();
                invalidateTenantModuleSettingsCache();
                if (function_exists('invalidateModuleContextCache')) {
                    invalidateModuleContextCache('cms');
                }
                write_log('ecMaybeInstallPages retrying after DB reconnect', 'info', [
                    'module' => 'ecommerce',
                    'attempt' => $attempt,
                    'tenant_id' => $tenantId,
                ]);
            } catch (\Throwable $reconnectError) {
                write_log('ecMaybeInstallPages reconnect failed: ' . $reconnectError->getMessage(), 'warning', [
                    'module' => 'ecommerce',
                    'attempt' => $attempt,
                    'tenant_id' => $tenantId,
                ]);
                return;
            }
        }
    }
}

function ecInstallPages(): void
{
    $pages = [
        ['title' => 'Shop',               'slug' => 'shop',               'body' => ''],
        ['title' => 'Cart',               'slug' => 'cart',               'body' => ''],
        ['title' => 'Checkout',           'slug' => 'checkout',           'body' => ''],
        ['title' => 'Order Confirmation', 'slug' => 'order-confirmation', 'body' => ''],
        ['title' => 'My Orders',          'slug' => 'my-orders',          'body' => ''],
    ];

    moduleWithContext('cms', static function () use ($pages): void {
        $db = app()->db();
        $authorId = (int)$db->query('SELECT id FROM cms_users ORDER BY id ASC LIMIT 1')->fetchColumn();
        if ($authorId <= 0) {
            throw new \RuntimeException('No CMS author available for ecommerce page install');
        }

        foreach ($pages as $page) {
            $stmt = $db->prepare(
                "SELECT id FROM cms_content WHERE slug = :slug AND type = 'page' AND deleted_at IS NULL LIMIT 1"
            );
            $stmt->execute([':slug' => $page['slug']]);
            if ($stmt->fetchColumn()) {
                continue;
            }

            $insert = $db->prepare(
                "INSERT INTO cms_content (uuid, title, slug, body, type, status, author_id, created_at, updated_at)
                 VALUES (:uuid, :title, :slug, :body, 'page', 'published', :author_id, NOW(), NOW())"
            );
            $insert->execute([
                ':uuid' => function_exists('cmsUuid') ? cmsUuid() : bin2hex(random_bytes(16)),
                ':title' => $page['title'],
                ':slug' => $page['slug'],
                ':body' => $page['body'],
                ':author_id' => $authorId,
            ]);
        }

        // Register the 'product' content type so the CMS entity list can resolve it.
        // The entity list handler (cmsPublicEntityList) requires a matching active
        // row in cms_content_types for the type passed by ecPublicShop.
        try {
            $db->execute(
                "INSERT INTO cms_content_types (slug, label, icon, supports, is_active, sort_order)
                 VALUES ('product', 'Products', 'shopping-bag',
                         '[\"title\",\"body\",\"excerpt\",\"featured_image\",\"slug\"]', 1, 50)
                 ON DUPLICATE KEY UPDATE is_active = 1"
            );
        } catch (\Throwable $e) {
            // Non-fatal: shop will still work via fallback handler
        }
    });
}

// ── CMS Admin Nav Injection ──────────────────────────────────────────

app()->hooks()->on('cms.admin.nav_items', function (array $items): array {
    $baseUrl = rtrim((string)(defined('BASE_URL') ? BASE_URL : ''), '/');

    $items[] = [
        'label'    => 'Ecommerce',
        'section'  => true,
        'children' => [
            ['label' => 'Dashboard',  'url' => $baseUrl . '/ecommerce/admin',            'icon' => '📊', 'active_key' => 'ec_dashboard'],
            ['label' => 'Products',   'url' => $baseUrl . '/ecommerce/admin/products',   'icon' => '📦', 'active_key' => 'ec_products'],
            ['label' => 'Orders',     'url' => $baseUrl . '/ecommerce/admin/orders',     'icon' => '📋', 'active_key' => 'ec_orders'],
            ['label' => 'Categories', 'url' => $baseUrl . '/ecommerce/admin/categories', 'icon' => '🏷️', 'active_key' => 'ec_categories'],
            ['label' => 'Coupons',    'url' => $baseUrl . '/ecommerce/admin/coupons',    'icon' => '🎟️', 'active_key' => 'ec_coupons'],
            ['label' => 'Reports',    'url' => $baseUrl . '/ecommerce/admin/reports',    'icon' => '📈', 'active_key' => 'ec_reports'],
            ['label' => 'POS',        'url' => $baseUrl . '/ecommerce/pos',              'icon' => '🖥️', 'active_key' => 'ec_pos'],
            ['label' => 'Settings',   'url' => $baseUrl . '/ecommerce/admin/settings',   'icon' => '⚙️', 'active_key' => 'ec_settings'],
        ],
    ];
    return $items;
}, priority: 20);

// ── CapabilityBus providers ──────────────────────────────────────────
// Override CMS defaults with ecommerce-aware implementations at higher priority.

app()->capabilities()->register(
    'entity.capability.pricing.data@1',
    'ecommerce',
    'ec_cap_pricing_data_1',
    80,  // Higher than CMS default (50)
    ['first']
);

app()->capabilities()->register(
    'entity.capability.inventory.data@1',
    'ecommerce',
    'ec_cap_inventory_data_1',
    80,
    ['first']
);

// Own capabilities
// ── CapabilityBus handler functions ─────────────────────────────────

function ec_cap_pricing_data_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    if (!is_array($payload) || empty($payload['entity'])) {
        return [];
    }
    $entityId = (int)($payload['entity']['id'] ?? 0);
    if (!$entityId) {
        return [];
    }

    $config = [];
    try {
        $db = ecDb();
        $row = $db->query(
            "SELECT config FROM cms_entity_capabilities WHERE entity_id = ? AND capability_id = 'pricing' LIMIT 1",
            [$entityId]
        )->fetch(\PDO::FETCH_ASSOC);

        if ($row) {
            $config = (array)json_decode($row['config'] ?? '{}', true);
        }
    } catch (\Throwable $e) {
        return [];
    }

    $price     = isset($config['price'])      ? (float)$config['price']      : null;
    $salePrice = isset($config['sale_price']) ? (float)$config['sale_price'] : null;
    $currency  = $config['currency'] ?? ecSettings('currency');
    $symbol    = (string)ecSettings('currency_symbol');

    if ($price === null) {
        return ['price' => null, 'sale_price' => null, 'currency' => $currency, 'on_sale' => false, 'formatted' => null];
    }

    $onSale   = $salePrice !== null && $salePrice < $price;
    $active   = $onSale ? $salePrice : $price;

    return [
        'price'         => $price,
        'sale_price'    => $salePrice,
        'currency'      => $currency,
        'on_sale'       => $onSale,
        'formatted'     => $symbol . number_format($active, 2),
        'regular_fmt'   => $symbol . number_format($price, 2),
    ];
}

function ec_cap_inventory_data_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    if (!is_array($payload) || empty($payload['entity'])) {
        return [];
    }
    $entityId = (int)($payload['entity']['id'] ?? 0);
    if (!$entityId) {
        return [];
    }

    $config = [];
    try {
        $db = ecDb();
        $row = $db->query(
            "SELECT config FROM cms_entity_capabilities WHERE entity_id = ? AND capability_id = 'inventory' LIMIT 1",
            [$entityId]
        )->fetch(\PDO::FETCH_ASSOC);

        if ($row) {
            $config = (array)json_decode($row['config'] ?? '{}', true);
        }
    } catch (\Throwable $e) {
        return [];
    }

    $trackStock = (bool)($config['track_stock'] ?? true);
    $stockQty   = (int)($config['stock_qty']   ?? 0);
    $sku        = $config['sku'] ?? '';
    $threshold  = (int)ecSettings('low_stock_threshold');

    return [
        'track_stock' => $trackStock,
        'stock_qty'   => $stockQty,
        'sku'         => $sku,
        'in_stock'    => !$trackStock || $stockQty > 0,
        'out_of_stock' => $trackStock && $stockQty <= 0,
        'low_stock'   => $trackStock && $stockQty > 0 && $stockQty <= $threshold,
    ];
}

function ec_cap_products_list_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    $params = is_array($payload) ? $payload : [];
    return ecProductList($params);
}

function ec_cap_products_get_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    $id = (int)($payload['id'] ?? 0);
    return $id ? (ecProductGet($id) ?? []) : [];
}

function ec_cap_cart_get_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    return ecCartGet();
}

function ec_cap_orders_create_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    if (!is_array($payload)) {
        return [];
    }
    return ecOrderCreate($payload);
}

function ec_cap_orders_get_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    $id = (int)($payload['id'] ?? 0);
    return $id ? (ecOrderGet($id) ?? []) : [];
}

// Run auto-page install on module load
ecMaybeInstallPages();
