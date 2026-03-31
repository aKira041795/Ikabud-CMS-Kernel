<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';

$pass = 0;
$fail = 0;
$errors = [];

function t(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $errors;

    if ($ok) {
        $pass++;
        echo "  ✓ {$label}\n";
        return;
    }

    $fail++;
    $errors[] = $label . ($detail !== '' ? ': ' . $detail : '');
    echo "  ✗ {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

file_put_contents(STORAGE_PATH . '/logs/app.log', '');
file_put_contents(STORAGE_PATH . '/logs/error.log', '');

echo "\n=== RENDER CONTRACT REGISTRY ===\n";

$modules = discoverModules();
$moduleIds = [
    'ecommerce',
    'guidance',
    'gui-settings',
    'sms',
    'daily-ledger',
    'anti-spam',
    'ticketing',
    'wordpress-importer',
];

foreach ($moduleIds as $moduleId) {
    t($moduleId . ' module discovered', isset($modules[$moduleId]) && is_array($modules[$moduleId]));

    if (isset($modules[$moduleId]) && is_array($modules[$moduleId])) {
        loadModuleHelpers($modules[$moduleId]);
    }
}

$contracts = kernelRegisteredRenderContextContracts();
$profiles = kernelRegisteredRenderContextProfiles();
t('cms public profile registered', isset($profiles['cms_public']) && ($profiles['cms_public']['shell_schema_stack'] ?? []) === ['kernel.shell@1']);
t('commerce public profile registered', isset($profiles['commerce_public']) && ($profiles['commerce_public']['shell_schema_stack'] ?? []) === ['kernel.shell@1']);
t('reserved admin profile registered', isset($profiles['admin']) && ($profiles['admin']['status'] ?? '') === 'reserved');
t('ecommerce public shell contract registered', isset($contracts['ecommerce.public.shell']));
t('ecommerce catalog contract registered', isset($contracts['ecommerce.public.catalog']));
t('ecommerce order confirmation contract registered', isset($contracts['ecommerce.public.order.confirmation']));
t('ecommerce public shell contract stores schema metadata', ($contracts['ecommerce.public.shell']['schema_id'] ?? '') === 'ecommerce.public.shell@1' && ($contracts['ecommerce.public.shell']['profile_hint'] ?? '') === 'commerce_public');
t('ecommerce catalog contract stores schema metadata', ($contracts['ecommerce.public.catalog']['schema_id'] ?? '') === 'ecommerce.public.catalog@1' && ($contracts['ecommerce.public.catalog']['schema_version'] ?? 0) === 1 && ($contracts['ecommerce.public.catalog']['profile_hint'] ?? '') === 'commerce_public');
t('guidance page shell contract registered', isset($contracts['guidance.page.shell']));
t('gui settings admin contract registered', isset($contracts['gui-settings.admin.settings']));
t('sms log contract registered', isset($contracts['sms.page.log']));
t('daily ledger admin contract registered', isset($contracts['daily-ledger.admin.shell']));
t('anti-spam dashboard contract registered', isset($contracts['anti-spam.page.dashboard']));
t('ticketing public submit contract registered', isset($contracts['ticketing.page.public-submit']));
t('wordpress importer admin contract registered', isset($contracts['wordpress-importer.admin.import']));

echo "\n=== PREPARE CONTEXT ===\n";

$preparedCatalog = kernelPrepareRenderContext(
    'modules/ecommerce/public/shop.disyl',
    ecPublicRenderContext('modules/ecommerce/public/shop.disyl', [
        'page_title' => 'Catalog',
        'products' => [],
        'available_categories' => [],
        'search' => '',
        'category_id' => 0,
        'page' => 1,
        'total' => 0,
        'total_pages' => 1,
    ])
);

t('prepared catalog context infers the storefront route', ($preparedCatalog['storefront']['route']['kind'] ?? '') === 'shop_index');
t('prepared catalog context infers catalog page kind', ($preparedCatalog['storefront']['page']['kind'] ?? '') === 'catalog');
t('prepared catalog context initializes storefront filters', is_array($preparedCatalog['storefront']['filters'] ?? null));
t('prepared catalog context initializes storefront collection items', is_array($preparedCatalog['storefront']['collection']['items'] ?? null));
t('prepared catalog context reports commerce_public profile', ($preparedCatalog['render_profile_id'] ?? '') === 'commerce_public', json_encode($preparedCatalog['render_profile_id'] ?? null));
t('prepared catalog context reports schema stack in order', ($preparedCatalog['render_schema_stack'] ?? null) === ['kernel.shell@1', 'ecommerce.public.shell@1', 'ecommerce.public.catalog@1'], json_encode($preparedCatalog['render_schema_stack'] ?? null));

echo "\n=== ADDITIONAL MODULES ===\n";

$preparedGuidance = kernelPrepareRenderContext('modules/guidance/pages/login.disyl', [
    'page_title' => 'Guidance Login',
]);
t('guidance page shell fills default admin route metadata', ($preparedGuidance['base_url'] ?? '') === '/admin/guidance' && ($preparedGuidance['hour'] ?? null) === 0);

$preparedGuiSettings = kernelPrepareRenderContext('modules/gui-settings/settings.disyl', [
    'page_title' => 'GUI Settings',
    'settings' => [],
    'defaults' => [],
    'setting_keys' => [],
    'font_presets' => [],
    'color_presets' => [],
]);
t('gui settings contract preserves array-based preset collections', is_array($preparedGuiSettings['font_presets'] ?? null) && is_array($preparedGuiSettings['color_presets'] ?? null));

$preparedSms = kernelPrepareRenderContext('modules/sms/partials/log-table.disyl', [
    'logs' => [],
    'total' => 12,
    'page' => 2,
    'limit' => 50,
    'pages' => 4,
]);
t('sms log table contract keeps pagination metadata normalized', ($preparedSms['page'] ?? null) === 2 && ($preparedSms['limit'] ?? null) === 50 && ($preparedSms['pages'] ?? null) === 4);

$preparedDailyLedger = kernelPrepareRenderContext('modules/daily-ledger/cashier/ledger.disyl', [
    'page_title' => 'Ledger',
    'user_name' => 'Cashier',
    'user_role' => 'cashier',
    'current_page' => 'ledger',
    'base_url' => '/daily-ledger',
    'branch_id' => 1,
    'branch_name' => 'Main',
    'ledger_date' => '2026-03-30',
    'today' => '2026-03-30',
    'day_status' => 'open',
    'branches' => [],
    'is_cashier' => true,
]);
t('daily ledger contract fills cashier automation defaults', ($preparedDailyLedger['auto_close_enabled'] ?? null) === false && ($preparedDailyLedger['business_date_label'] ?? '') === '');

$preparedAntiSpam = kernelPrepareRenderContext('modules/anti-spam/pages/home.disyl', [
    'page_title' => 'Anti-Spam Dashboard',
    'stats' => [],
    'settings' => [],
    'recent_log' => [],
]);
t('anti-spam dashboard contract expands nested stats defaults', ($preparedAntiSpam['stats']['blocked_ips'] ?? null) === 0 && ($preparedAntiSpam['stats']['total_log'] ?? null) === 0);

$preparedTicketing = kernelPrepareRenderContext('modules/ticketing/public-submit.disyl', [
    'page_title' => 'Submit a Maintenance Request',
    'captcha_question' => '1 + 1',
    'captcha_token' => 'token',
    'base_url' => '/ticketing',
]);
t('ticketing public contract preserves form metadata', ($preparedTicketing['captcha_token'] ?? '') === 'token' && ($preparedTicketing['base_url'] ?? '') === '/ticketing');

t('wordpress importer prepare helper is available', function_exists('wordpressImporterPrepareRenderContext'));
if (function_exists('wordpressImporterPrepareRenderContext')) {
    $preparedWordPressImporter = wordpressImporterPrepareRenderContext('templates/admin/wordpress-importer.disyl', [
        'page_title' => 'WordPress Import',
    ]);
    t('wordpress importer render-string helper prepares admin template context', ($preparedWordPressImporter['page_title'] ?? '') === 'WordPress Import');
}

echo "\n=== FINALIZE RENDER ===\n";

$renderedShop = app()->render('modules/ecommerce/public/shop.disyl');
t('shop render receives normalized storefront route metadata', str_contains($renderedShop, 'data-storefront-route-kind="shop_index"') && str_contains($renderedShop, 'data-storefront-page-kind="catalog"'), $renderedShop);
t('shop render uses normalized empty-state defaults', str_contains($renderedShop, 'No products found.'), $renderedShop);

$renderedCart = app()->render('modules/ecommerce/public/cart.disyl');
t('cart render receives empty cart defaults', str_contains($renderedCart, 'Your cart is empty.') && str_contains($renderedCart, '/ecommerce/shop'), $renderedCart);

$renderedProduct = app()->render('modules/ecommerce/public/product.disyl');
t('product render receives normalized product detail metadata', str_contains($renderedProduct, 'data-storefront-route-kind="product_detail"') && str_contains($renderedProduct, 'data-storefront-page-kind="detail"'), $renderedProduct);

echo "\n=== MISMATCH LOGGING ===\n";

file_put_contents(STORAGE_PATH . '/logs/app.log', '');

$driftedCatalogContext = ecPublicRenderContext('modules/ecommerce/public/shop.disyl', [
    'page_title' => 'Catalog',
    'products' => 'bad-products',
    'available_categories' => [],
    'search' => '',
    'category_id' => 0,
    'page' => 1,
    'total' => 0,
    'total_pages' => 1,
]);

kernelPrepareRenderContext('modules/ecommerce/public/shop.disyl', $driftedCatalogContext);

$appLog = @file_get_contents(STORAGE_PATH . '/logs/app.log') ?: '';
$contractMismatchLines = array_values(array_filter(explode("\n", $appLog), static fn(string $line): bool => str_contains($line, 'ecommerce.render_context.contract_mismatch')));
t('prepare render context logs ecommerce mismatch events', !empty($contractMismatchLines), implode('; ', $contractMismatchLines));
t('prepare render context mismatch logs include profile/schema metadata', str_contains($appLog, '"render_profile_id":"commerce_public"') && str_contains($appLog, '"ecommerce.public.catalog@1"'), $appLog);

echo "\n=== STRICT MODE ===\n";

$strictEnv = array_key_exists('DISYL_RENDER_CONTRACT_STRICT', $_ENV) ? (string)$_ENV['DISYL_RENDER_CONTRACT_STRICT'] : null;
$_ENV['DISYL_RENDER_CONTRACT_STRICT'] = '1';

$strictThrew = false;
$strictMessage = '';
try {
    kernelPrepareRenderContext('modules/ecommerce/public/shop.disyl', $driftedCatalogContext);
} catch (RuntimeException $e) {
    $strictThrew = true;
    $strictMessage = $e->getMessage();
}

if ($strictEnv === null) {
    unset($_ENV['DISYL_RENDER_CONTRACT_STRICT']);
} else {
    $_ENV['DISYL_RENDER_CONTRACT_STRICT'] = $strictEnv;
}

t('strict mode fails fast on ecommerce contract drift', $strictThrew && str_contains($strictMessage, 'ecommerce.public.catalog'), $strictMessage);

echo "\n=== LOG CHECK ===\n";

$appLog = @file_get_contents(STORAGE_PATH . '/logs/app.log') ?: '';
$errLog = @file_get_contents(STORAGE_PATH . '/logs/error.log') ?: '';

$unexpectedAppErrors = array_values(array_filter(explode("\n", $appLog), static function (string $line): bool {
    if ($line === '') {
        return false;
    }

    if (str_contains($line, 'ecommerce.render_context.contract_mismatch')) {
        return false;
    }

    return str_contains($line, '[error]') || str_contains($line, '[warning]');
}));

$errLines = array_values(array_filter(explode("\n", $errLog), static function (string $line): bool {
    return trim($line) !== '' && !str_contains($line, 'Ikabud Cache:');
}));

t('no unexpected app.log errors', empty($unexpectedAppErrors), implode('; ', array_slice($unexpectedAppErrors, 0, 3)));
t('no PHP errors in error.log', empty($errLines), implode('; ', array_slice($errLines, 0, 3)));

echo "\n══════════════════════════════════════════════════\n";
echo "  PASS: {$pass}  FAIL: {$fail}\n";
echo "══════════════════════════════════════════════════\n";

if ($errors !== []) {
    echo "\nFailed tests:\n";
    foreach ($errors as $error) {
        echo "  - {$error}\n";
    }
}

exit($fail > 0 ? 1 : 0);