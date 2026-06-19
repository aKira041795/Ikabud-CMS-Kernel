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

function ecTableExists(string $table): bool
{
    static $cache = [];
    $tid = app()->tenant()->current();
    if (!isset($cache[$tid])) {
        $cache[$tid] = [];
    }

    $table = trim($table);
    if ($table === '') {
        return false;
    }

    if (($cache[$tid][$table] ?? null) === true) {
        return $cache[$tid][$table];
    }

    try {
        $db = app()->db();
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?'
        );
        $stmt->execute([$table]);
        $cache[$tid][$table] = (bool)$stmt->fetchColumn();
    } catch (\Throwable $e) {
        $cache[$tid][$table] = false;
    }

    return $cache[$tid][$table];
}

function ecCmsSchemaReady(): bool
{
    return ecTableExists('cms_users')
        && ecTableExists('cms_content')
        && ecTableExists('cms_content_types');
}

function ecResolveUserServiceContext(array $user): ?array
{
    $userId = (int)($user['id'] ?? 0);
    if ($userId <= 0) {
        return null;
    }

    $primaryService = function_exists('cmsDetectPrimaryUserService') ? cmsDetectPrimaryUserService($userId) : null;
    if ($primaryService !== null && !in_array($primaryService, ['ecommerce', 'ecommerce-store'], true)) {
        return null;
    }

    if (function_exists('ecStoreHomePathForUser')) {
        $storeHome = ecStoreHomePathForUser($userId);
        if (is_string($storeHome) && $storeHome !== '') {
            return [
                'service' => 'ecommerce-store',
                'url' => $storeHome,
                'label' => 'Store Admin',
                'source' => $primaryService !== null ? 'binding' : 'store_membership',
            ];
        }
    }

    $role = trim((string)($user['role'] ?? ''));
    if ($primaryService === 'ecommerce' || $role === 'customer') {
        return [
            'service' => 'ecommerce',
            'url' => '/ecommerce/my-orders',
            'label' => 'My Orders',
            'source' => $primaryService !== null ? 'binding' : 'role',
        ];
    }

    return null;
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
        'modules/ecommerce/public/my-wishlist.disyl' => 'my_wishlist',
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
        'my_wishlist' => ['_cms_active_theme/public/ecommerce/my-wishlist.disyl'],
        'order_detail' => ['_cms_active_theme/public/ecommerce/order-detail.disyl'],
        default => [],
    };

    return array_values(array_unique(array_merge($themeCandidates, [$template])));
}

app()->hooks()->on('kernel.user_service_context', static function (?array $context, array $user): ?array {
    if (is_array($context)) {
        return $context;
    }

    return ecResolveUserServiceContext($user);
}, 10);

function ecResolvePublicThemeTemplate(string $template, array $context = []): string
{
    $candidates = ecPublicThemeTemplateCandidates($template, $context);
    if (count($candidates) === 1) {
        return $template;
    }

    if (!function_exists('cmsActiveTheme')
        || !function_exists('cmsActiveThemeManifest')
        || !function_exists('cmsActiveThemeTemplateExists')) {
        return $template;
    }

    if (cmsActiveTheme() === null) {
        return $template;
    }

    $manifest = cmsActiveThemeManifest();
    if (!empty($manifest['restrict_to_tokens'])) {
        return $template;
    }

    foreach ($candidates as $candidate) {
        if (!str_starts_with($candidate, '_cms_active_theme/')) {
            continue;
        }

        if (cmsActiveThemeTemplateExists($candidate)) {
            return $candidate;
        }
    }

    return $template;
}

/**
 * Resolve the public presentation mode for the given route kind.
 */
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

/**
 * Apply global platform currency to a render context, then override with per-store
 * currency when the context carries store_currency_code (from ecStorefrontRenderContext).
 *
 * This is used by ecRender for the traditional template path AND injected directly into
 * the CMS canonical entity-list and entity-view paths which never call ecRender, ensuring
 * {currency}, {currency_sym}, and ec_settings.currency_symbol are consistent everywhere.
 */
function ecApplyPublicCurrencyContext(array $context): array
{
    // 1. Apply global platform currency (the baseline)
    if (function_exists('ecCurrentCurrencyContext')) {
        $currencyContext = ecCurrentCurrencyContext();
        $context['ec_currency'] = $currencyContext;
        $context['currency'] = (string)($currencyContext['code'] ?? '');
        $context['currency_sym'] = (string)($currencyContext['symbol'] ?? '');

        $settings = is_array($context['ec_settings'] ?? null) ? $context['ec_settings'] : [];
        $settings['currency'] = (string)($currencyContext['code'] ?? ($settings['currency'] ?? ''));
        $settings['currency_symbol'] = (string)($currencyContext['symbol'] ?? ($settings['currency_symbol'] ?? ''));
        $context['ec_settings'] = $settings;
    }

    // 2. Per-store override: when ecStorefrontRenderContext has set store_currency_code,
    //    override the global defaults so templates see the correct per-store symbol.
    //    Does not affect cart/order math — display/presentation only.
    if (!empty($context['store_currency_code'])) {
        $context['currency'] = (string)$context['store_currency_code'];
        $storeSym = (string)($context['store_currency_sym'] ?? '');
        if ($storeSym !== '') {
            $context['currency_sym'] = $storeSym;
        }
        $settings = is_array($context['ec_settings'] ?? null) ? $context['ec_settings'] : [];
        $settings['currency'] = $context['currency'];
        if ($storeSym !== '') {
            $settings['currency_symbol'] = $storeSym;
        }
        $context['ec_settings'] = $settings;
    }

    return $context;
}

function ecRender(string $template, array $context = []): void
{
    if (!array_key_exists('cart_count', $context)) {
        $cart = ecCartGet();
        $context['cart_count'] = (int)($cart['totals']['item_count'] ?? 0);
    }

    if (!array_key_exists('wishlist_count', $context)) {
        $context['wishlist_count'] = function_exists('ecWishlistCount') ? ecWishlistCount() : 0;
    }

    if (!array_key_exists('ec_settings', $context)) {
        $context['ec_settings'] = ecSettings();
    }

    if (!array_key_exists('base_url', $context)) {
        $context['base_url'] = ecGetBaseUrl();
    }

    if (!array_key_exists('user', $context)) {
        $context['user'] = app()->user();
    }

    if (!array_key_exists('year', $context)) {
        $context['year'] = date('Y');
    }

    if (!array_key_exists('public_customer_is_logged_in', $context)) {
        $user = is_array($context['user'] ?? null) ? $context['user'] : null;
        $role = strtolower(trim((string)($user['role'] ?? '')));

        // Try all display-name-equivalent fields.
        // JWT payload uses 'name'; DB rows use 'display_name'; some use first/last.
        $displayName = trim((string)($user['display_name'] ?? ''));
        if ($displayName === '') {
            $displayName = trim((string)($user['name'] ?? ''));
        }
        if ($displayName === '') {
            $displayName = trim((string)(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')));
        }
        if ($displayName === '') {
            $displayName = trim((string)($user['username'] ?? ''));
        }
        if ($displayName === '') {
            $displayName = 'Customer';
        }

        // Email: JWT payload does not carry email; look it up from DB for CMS users.
        $email = trim((string)($user['email'] ?? ''));
        if ($email === '' && ($user['source'] ?? '') === 'cms' && !empty($user['id'])) {
            try {
                $emailRow = ecDb()->query(
                    'SELECT email FROM cms_users WHERE id = ? LIMIT 1',
                    [(int)$user['id']]
                )->fetch(\PDO::FETCH_ASSOC);
                $email = trim((string)($emailRow['email'] ?? ''));
            } catch (\Throwable $e) {
                // non-fatal
            }
        }

        $isPublicCmsUser = $user !== null && (($user['source'] ?? '') === 'cms' || in_array($role, ['subscriber', 'customer', 'editor', 'administrator'], true));
        $context['public_customer_is_logged_in'] = $isPublicCmsUser;
        $context['public_customer_display_name'] = $displayName;
        $context['public_customer_email'] = $email;
        $context['public_customer_orders_url'] = rtrim((string)$context['base_url'], '/') . '/ecommerce/my-orders';
        $context['public_customer_wishlist_url'] = rtrim((string)$context['base_url'], '/') . '/ecommerce/my-wishlist';
        $context['public_customer_login_url'] = rtrim((string)$context['base_url'], '/') . '/cms/login?redirect=' . urlencode('/ecommerce/my-orders');
        $context['public_customer_admin_url'] = rtrim((string)$context['base_url'], '/') . '/cms/admin';
        $context['public_customer_has_admin_access'] = $isPublicCmsUser && in_array($role, ['editor', 'administrator', 'superadmin'], true);
    }

    $context = ecPublicRenderContext($template, $context);
    $isPublicTemplate = ecIsPublicTemplate($template);
    if ($isPublicTemplate) {
        $context = ecApplyPublicCurrencyContext($context);
    }

    // Always check presentation modes for traditional-style entity endpoints if cms isn't managing it upstream
    // (cart, checkout etc are exempt as CMS builder has no explicit "cart route" in phase 1)
    if ($isPublicTemplate && defined('CMS_API_VERSION') && function_exists('cmsResolveEcommerceThemePolicy')) {
         // Some endpoints implicitly map to ecommerce features like `shop_index`
         // If a specific route requires `entity_view`, we throw rather than render traditional HTML
         ecAssertTraditionalEntityTemplateAllowed($template, $context);
    }
    
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
    static $hasTaxonomy = [];
    $tid = app()->tenant()->current();
    if (array_key_exists($tid, $hasTaxonomy)) {
        return $hasTaxonomy[$tid];
    }

    try {
        ecDb()->query('SELECT taxonomy FROM cms_categories WHERE 1 = 0');
        $hasTaxonomy[$tid] = true;
    } catch (\Throwable $e) {
        $hasTaxonomy[$tid] = false;
    }

    return $hasTaxonomy[$tid];
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
    static $cache = [];
    static $generation = null;
    $currentGeneration = (int)($GLOBALS['__ec_settings_cache_generation'] ?? 0);
    if ($generation !== $currentGeneration) {
        $cache = [];
        $generation = $currentGeneration;
    }
    $tid = app()->tenant()->current();
    if (!array_key_exists($tid, $cache)) {
        $cache[$tid] = array_merge(ecSettingsDefaults(), getModuleSettings('ecommerce'));
    }
    if ($key === null) {
        return $cache[$tid];
    }
    return $cache[$tid][$key] ?? $default;
}

function ecSettingsResetCache(): void
{
    $GLOBALS['__ec_settings_cache_generation'] = (int)($GLOBALS['__ec_settings_cache_generation'] ?? 0) + 1;
}

// ── Auto-page installation ───────────────────────────────────────────

/**
 * Called once per request when module is active.
 * Creates the 5 storefront CMS pages on first enable for this tenant.
 */
function ecMaybeInstallPages(): void
{
    static $done = [];
    $tid = app()->tenant()->current();
    if (!empty($done[$tid])) {
        return;
    }
    $done[$tid] = true;

    $settings = readTenantModuleSettings('ecommerce');

    if (!ecCmsSchemaReady()) {
        return;
    }

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
        $db = function_exists('cmsDb') ? cmsDb() : app()->db();
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
            ['label' => 'Customers', 'url' => $baseUrl . '/ecommerce/admin/customers', 'icon' => '👥', 'active_key' => 'ec_customers'],
            ['label' => 'Reports',    'url' => $baseUrl . '/ecommerce/admin/reports',    'icon' => '📈', 'active_key' => 'ec_reports'],
            ['label' => 'Inventory',  'url' => $baseUrl . '/ecommerce/admin/inventory',  'icon' => '📦', 'active_key' => 'ec_inventory'],
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

    return ecProductInventory($entityId);
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

// ── Entity-View Capabilities ──────────────────────────────────────────

function ec_capability_handlers_entity(): array
{
    return [
        'entity.list.ecommerce_product@1' => 'ec_cap_entity_list_product_1',
        'entity.get.ecommerce_product@1' => 'ec_cap_entity_get_product_1',
        'entity.list.ecommerce_order@1' => 'ec_cap_entity_list_order_1',
        'entity.get.ecommerce_order@1' => 'ec_cap_entity_get_order_1',
    ];
}

function ec_cap_entity_list_product_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    $limit = min((int)($payload['limit'] ?? 20), 100);
    $qualifier = (string)($payload['qualifier'] ?? '');
    $filter = "c.type = 'product'";
    if ($qualifier === 'featured') { $filter .= ' AND (SELECT 1 FROM cms_content_meta WHERE content_id = c.id AND meta_key = \'_is_featured\' AND meta_value = \'1\' LIMIT 1) IS NOT NULL'; }
    try {
        $db = cmsDb();
        $stmt = $db->query("SELECT c.id, c.title AS name, (SELECT meta_value FROM cms_content_meta WHERE content_id = c.id AND meta_key = '_price' LIMIT 1) AS price, (SELECT meta_value FROM cms_content_meta WHERE content_id = c.id AND meta_key = '_stock_status' LIMIT 1) AS stock_status, c.created_at, (SELECT m.file_path FROM cms_content_media cm JOIN cms_media m ON m.id = cm.media_id WHERE cm.content_id = c.id AND cm.is_featured = 1 LIMIT 1) AS image FROM cms_content c WHERE {$filter} AND c.status = 'published' AND c.deleted_at IS NULL ORDER BY c.created_at DESC LIMIT {$limit}");
        $rows = $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
        $countStmt = $db->query("SELECT COUNT(*) FROM cms_content c WHERE {$filter} AND c.status = 'published' AND c.deleted_at IS NULL");
        $total = $countStmt ? (int)$countStmt->fetchColumn() : count($rows);
        return ['rows' => $rows, 'total' => $total];
    } catch (\Throwable $e) {
        if (\function_exists('write_log')) { \write_log('entity.list.ecommerce_product: ' . $e->getMessage(), 'warning'); }
        return ['rows' => [], 'total' => 0, 'error' => $e->getMessage()];
    }
}

function ec_cap_entity_get_product_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    $id = (int)($payload['id'] ?? ($payload['entity_id'] ?? 0));
    if ($id <= 0) return [];
    try {
        $db = cmsDb();
        $stmt = $db->prepare("SELECT c.*, (SELECT meta_value FROM cms_content_meta WHERE content_id = c.id AND meta_key = '_price' LIMIT 1) AS price, (SELECT meta_value FROM cms_content_meta WHERE content_id = c.id AND meta_key = '_stock_status' LIMIT 1) AS stock_status, (SELECT m.file_path FROM cms_content_media cm JOIN cms_media m ON m.id = cm.media_id WHERE cm.content_id = c.id AND cm.is_featured = 1 LIMIT 1) AS image FROM cms_content c WHERE c.id = :id AND c.type = 'product' AND c.status = 'published' AND c.deleted_at IS NULL LIMIT 1");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return is_array($row) ? $row : [];
    } catch (\Throwable $e) {
        return [];
    }
}

function ec_cap_entity_list_order_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    $limit = min((int)($payload['limit'] ?? 15), 100);
    try {
        $db = ecDb();
        $stmt = $db->query("SELECT id, order_number, status, total, created_at FROM ec_orders ORDER BY created_at DESC LIMIT {$limit}");
        $rows = $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
        $countStmt = $db->query('SELECT COUNT(*) FROM ec_orders');
        $total = $countStmt ? (int)$countStmt->fetchColumn() : count($rows);
        return ['rows' => $rows, 'total' => $total];
    } catch (\Throwable $e) {
        if (\function_exists('write_log')) { \write_log('entity.list.ecommerce_order: ' . $e->getMessage(), 'warning'); }
        return ['rows' => [], 'total' => 0, 'error' => $e->getMessage()];
    }
}

function ec_cap_entity_get_order_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    $id = (int)($payload['id'] ?? ($payload['entity_id'] ?? 0));
    if ($id <= 0) return [];
    try {
        $db = ecDb();
        $stmt = $db->prepare('SELECT * FROM ec_orders WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($row)) return [];
        $itemsStmt = $db->prepare('SELECT * FROM ec_order_items WHERE order_id = :oid');
        $itemsStmt->execute([':oid' => $id]);
        $row['items'] = $itemsStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        return $row;
    } catch (\Throwable $e) {
        return [];
    }
}

// Run auto-page install on module load
ecMaybeInstallPages();
