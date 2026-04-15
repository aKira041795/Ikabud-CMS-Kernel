<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────
// Ecommerce Module — POS Terminal Handler (handlers/70-pos.php)
// ─────────────────────────────────────────────────────────────────────────

/**
 * GET /ecommerce/pos  — full-screen POS terminal
 */
function ecPosTerminal(): void
{
    if (function_exists('ecPosEnabled') && !ecPosEnabled()) {
        http_response_code(404);
        exit;
    }

    $user = ecRequireAdmin();

    // Resolve currency: use store-aware helpers when available so that
    // per-store overrides take effect; fall back to global ecommerce settings.
    $currencySym  = function_exists('ecStoreAwareCurrencySymbol')
        ? ecStoreAwareCurrencySymbol()
        : (string)ecSettings('currency_symbol');
    $currencyCode = function_exists('ecStoreAwareCurrencyCode')
        ? ecStoreAwareCurrencyCode()
        : (string)ecSettings('currency');

    ecRender('modules/ecommerce/admin/pos.disyl', [
        'page_title'    => 'Point of Sale',
        'user'          => $user,
        'csrf_token'    => app()->csrfToken(),
        'ec_settings'   => ecSettings(),
        'currency_sym'  => $currencySym,
        'currency_code' => $currencyCode,
    ]);
}

/**
 * GET /ecommerce/store-admin/{id}/pos  — per-store POS terminal
 */
function ecStoreAdminPos(array $params = []): void
{
    if (function_exists('ecPosEnabled') && !ecPosEnabled()) {
        http_response_code(404);
        exit;
    }

    $storeId = (int)($params['id'] ?? 0);
    $user    = ecRequireStoreAccess($storeId);
    $store   = ecStoreAdminLoadStore($storeId);

    // Resolve currency from per-store settings, falling back to platform defaults.
    $storeSettings = function_exists('ecStoreSettingsArray') ? ecStoreSettingsArray($store) : [];
    $currencySym   = ($storeSettings['currency_symbol'] ?? '') !== ''
        ? (string)$storeSettings['currency_symbol']
        : (function_exists('ecStoreAwareCurrencySymbol') ? ecStoreAwareCurrencySymbol($store) : (string)ecSettings('currency_symbol'));
    $currencyCode  = ($storeSettings['currency'] ?? '') !== ''
        ? (string)$storeSettings['currency']
        : (function_exists('ecStoreAwareCurrencyCode') ? ecStoreAwareCurrencyCode($store) : (string)ecSettings('currency'));

    ecRender('modules/ecommerce/admin/pos.disyl', [
        'page_title'    => ($store['name'] ?? 'Store') . ' — Point of Sale',
        'user'          => $user,
        'store'         => $store,
        'csrf_token'    => app()->csrfToken(),
        'ec_settings'   => ecSettings(),
        'currency_sym'  => $currencySym,
        'currency_code' => $currencyCode,
    ]);
}
