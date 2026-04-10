<?php

declare(strict_types=1);

define('EC_SESSION_SELECTED_CURRENCY_KEY', 'ec_selected_currency');

function ecCurrencyCatalog(): array
{
    return [
        'USD' => ['symbol' => '$'],
        'PHP' => ['symbol' => 'PHP '],
        'EUR' => ['symbol' => 'EUR '],
        'GBP' => ['symbol' => 'GBP '],
        'AUD' => ['symbol' => 'AUD '],
        'CAD' => ['symbol' => 'CAD '],
        'SGD' => ['symbol' => 'SGD '],
    ];
}

function ecCurrencyNormalizeCode(mixed $code): string
{
    return strtoupper(trim((string)$code));
}

function ecStoreBaseCurrencyCode(): string
{
    $code = ecCurrencyNormalizeCode(ecSettings('currency'));
    return $code !== '' ? $code : 'USD';
}

function ecCurrencySymbolFor(string $currencyCode): string
{
    $currencyCode = ecCurrencyNormalizeCode($currencyCode);
    if ($currencyCode === '') {
        $currencyCode = ecStoreBaseCurrencyCode();
    }

    if ($currencyCode === ecStoreBaseCurrencyCode()) {
        $configured = trim((string)ecSettings('currency_symbol'));
        if ($configured !== '') {
            return $configured;
        }
    }

    $catalog = ecCurrencyCatalog();
    return (string)($catalog[$currencyCode]['symbol'] ?? ($currencyCode . ' '));
}

function ecCurrencyEnabledCodes(): array
{
    $baseCurrency = ecStoreBaseCurrencyCode();
    $raw = trim((string)ecSettings('enabled_currencies'));
    $codes = [];

    if ($raw !== '') {
        foreach (preg_split('/[\s,]+/', $raw) ?: [] as $candidate) {
            $normalized = ecCurrencyNormalizeCode($candidate);
            if ($normalized === '' || in_array($normalized, $codes, true)) {
                continue;
            }
            $codes[] = $normalized;
        }
    }

    if (!in_array($baseCurrency, $codes, true)) {
        array_unshift($codes, $baseCurrency);
    }

    return array_values(array_unique(array_filter($codes, static fn(string $code): bool => $code !== '')));
}

function ecCurrencyExchangeRateMap(): array
{
    $baseCurrency = ecStoreBaseCurrencyCode();
    $rates = [$baseCurrency => 1.0];
    $raw = trim((string)ecSettings('currency_exchange_rates'));

    if ($raw === '') {
        return $rates;
    }

    $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        $parts = preg_split('/[|=]/', $line) ?: [];
        $code = ecCurrencyNormalizeCode($parts[0] ?? '');
        $rate = isset($parts[1]) && is_numeric(trim((string)$parts[1])) ? (float)trim((string)$parts[1]) : 0.0;
        if ($code === '' || $rate <= 0) {
            continue;
        }

        $rates[$code] = $rate;
    }

    return $rates;
}

function ecCurrencyContextForCode(?string $currencyCode = null): array
{
    $baseCurrency = ecStoreBaseCurrencyCode();
    $enabledCurrencies = ecCurrencyEnabledCodes();
    $rates = ecCurrencyExchangeRateMap();

    $currencyCode = ecCurrencyNormalizeCode($currencyCode ?? $baseCurrency);
    if ($currencyCode === '' || !in_array($currencyCode, $enabledCurrencies, true) || !isset($rates[$currencyCode])) {
        $currencyCode = $baseCurrency;
    }

    $available = [];
    foreach ($enabledCurrencies as $enabledCurrency) {
        if (!isset($rates[$enabledCurrency])) {
            continue;
        }

        $available[] = [
            'code' => $enabledCurrency,
            'symbol' => ecCurrencySymbolFor($enabledCurrency),
            'is_current' => $enabledCurrency === $currencyCode,
            'is_base' => $enabledCurrency === $baseCurrency,
        ];
    }

    return [
        'code' => $currencyCode,
        'symbol' => ecCurrencySymbolFor($currencyCode),
        'base_code' => $baseCurrency,
        'base_symbol' => ecCurrencySymbolFor($baseCurrency),
        'rate' => (float)($rates[$currencyCode] ?? 1.0),
        'is_base' => $currencyCode === $baseCurrency,
        'available' => $available,
        'selector_enabled' => count($available) > 1,
    ];
}

function ecCurrencyConvertAmount(float $amount, string $fromCurrency, string $toCurrency): float
{
    $fromCurrency = ecCurrencyNormalizeCode($fromCurrency);
    $toCurrency = ecCurrencyNormalizeCode($toCurrency);
    if ($fromCurrency === '' || $toCurrency === '' || $fromCurrency === $toCurrency) {
        return round($amount, 2);
    }

    $baseCurrency = ecStoreBaseCurrencyCode();
    $rates = ecCurrencyExchangeRateMap();
    if (!isset($rates[$fromCurrency]) || !isset($rates[$toCurrency])) {
        return round($amount, 2);
    }

    $baseAmount = $fromCurrency === $baseCurrency
        ? $amount
        : ($amount / (float)$rates[$fromCurrency]);
    $converted = $toCurrency === $baseCurrency
        ? $baseAmount
        : ($baseAmount * (float)$rates[$toCurrency]);

    return round($converted, 2);
}

function ecCurrencyFormatAmount(float $amount, ?string $currencyCode = null, ?string $symbol = null): string
{
    $currencyCode = ecCurrencyNormalizeCode($currencyCode ?? ecStoreBaseCurrencyCode());
    $symbol = $symbol !== null && trim($symbol) !== '' ? $symbol : ecCurrencySymbolFor($currencyCode);

    return $symbol . number_format($amount, 2);
}

function ecCartHasStoredItemsRaw(): bool
{
    $user = app()->user();
    $userId = ($user && ($user['source'] ?? '') === 'cms') ? (int)$user['id'] : 0;
    if ($userId > 0) {
        try {
            $count = (int)ecDb()->query(
                'SELECT COUNT(*) FROM ec_cart_items ci INNER JOIN ec_carts c ON c.id = ci.cart_id WHERE c.user_id = ?',
                [$userId]
            )->fetchColumn();

            return $count > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    return !empty($_SESSION[EC_SESSION_CART_KEY] ?? []);
}

function ecCurrentCartCurrencyCode(): string
{
    $user = app()->user();
    $userId = ($user && ($user['source'] ?? '') === 'cms') ? (int)$user['id'] : 0;
    if ($userId > 0) {
        try {
            $currency = ecDb()->query(
                'SELECT ci.currency FROM ec_cart_items ci INNER JOIN ec_carts c ON c.id = ci.cart_id WHERE c.user_id = ? ORDER BY ci.id ASC LIMIT 1',
                [$userId]
            )->fetchColumn();
            $currency = ecCurrencyNormalizeCode($currency);
            if ($currency !== '') {
                return $currency;
            }
        } catch (\Throwable $e) {
        }
    }

    foreach ((array)($_SESSION[EC_SESSION_CART_KEY] ?? []) as $item) {
        $currency = ecCurrencyNormalizeCode($item['currency'] ?? '');
        if ($currency !== '') {
            return $currency;
        }
    }

    return ecCurrencyNormalizeCode($_SESSION[EC_SESSION_SELECTED_CURRENCY_KEY] ?? '') ?: ecStoreBaseCurrencyCode();
}

function ecResolveCartItemsCurrencyCode(array $items): string
{
    foreach ($items as $item) {
        $currency = ecCurrencyNormalizeCode($item['currency'] ?? '');
        if ($currency !== '') {
            return $currency;
        }
    }

    return ecCurrentCurrencyCode();
}

function ecRequestedCurrencyCode(): ?string
{
    $input = [];
    if (function_exists('ecInput')) {
        $input = array_merge($input, (array)ecInput());
    }
    $input = array_merge($input, (array)app()->input(), (array)$_GET, (array)$_POST, (array)$_REQUEST);

    $currency = ecCurrencyNormalizeCode($input['currency'] ?? '');
    if ($currency === '') {
        return null;
    }

    return in_array($currency, ecCurrencyEnabledCodes(), true) ? $currency : null;
}

function ecCurrentCurrencyCode(): string
{
    $resolved = kernel_request_context_get('_ec_current_currency_code', null);
    if (is_string($resolved) && $resolved !== '') {
        return $resolved;
    }

    $baseCurrency = ecStoreBaseCurrencyCode();
    $cartCurrency = ecCurrentCartCurrencyCode();
    $requestedCurrency = ecRequestedCurrencyCode();

    if ($requestedCurrency !== null) {
        if (ecCartHasStoredItemsRaw() && $cartCurrency !== '' && $cartCurrency !== $requestedCurrency) {
            $_SESSION['ec_message'] = [
                'type' => 'error',
                'text' => 'Clear your cart before switching currencies.',
            ];
            $_SESSION[EC_SESSION_SELECTED_CURRENCY_KEY] = $cartCurrency;
            kernel_request_context_set('_ec_current_currency_code', $cartCurrency);
            return $cartCurrency;
        }

        $_SESSION[EC_SESSION_SELECTED_CURRENCY_KEY] = $requestedCurrency;
        kernel_request_context_set('_ec_current_currency_code', $requestedCurrency);
        return $requestedCurrency;
    }

    if (ecCartHasStoredItemsRaw() && $cartCurrency !== '') {
        $_SESSION[EC_SESSION_SELECTED_CURRENCY_KEY] = $cartCurrency;
        kernel_request_context_set('_ec_current_currency_code', $cartCurrency);
        return $cartCurrency;
    }

    $sessionCurrency = ecCurrencyNormalizeCode($_SESSION[EC_SESSION_SELECTED_CURRENCY_KEY] ?? '');
    if ($sessionCurrency !== '' && in_array($sessionCurrency, ecCurrencyEnabledCodes(), true)) {
        kernel_request_context_set('_ec_current_currency_code', $sessionCurrency);
        return $sessionCurrency;
    }

    kernel_request_context_set('_ec_current_currency_code', $baseCurrency);
    return $baseCurrency;
}

function ecCurrencyResetRuntimeState(): void
{
    kernel_request_context_set('_ec_current_currency_code', null);
}

function ecCurrentCurrencyContext(): array
{
    return ecCurrencyContextForCode(ecCurrentCurrencyCode());
}

function ecCurrencyPresentPricing(array $pricing, ?string $targetCurrency = null): array
{
    $sourceCurrency = ecCurrencyNormalizeCode($pricing['currency'] ?? ecStoreBaseCurrencyCode()) ?: ecStoreBaseCurrencyCode();
    $targetCurrency = ecCurrencyNormalizeCode($targetCurrency ?? ecCurrentCurrencyCode()) ?: ecStoreBaseCurrencyCode();
    $price = array_key_exists('price', $pricing) && $pricing['price'] !== null ? (float)$pricing['price'] : null;
    $salePrice = array_key_exists('sale_price', $pricing) && $pricing['sale_price'] !== null ? (float)$pricing['sale_price'] : null;

    $convertedPrice = $price !== null ? ecCurrencyConvertAmount($price, $sourceCurrency, $targetCurrency) : null;
    $convertedSalePrice = $salePrice !== null ? ecCurrencyConvertAmount($salePrice, $sourceCurrency, $targetCurrency) : null;
    $onSale = $convertedPrice !== null
        && $convertedSalePrice !== null
        && $convertedSalePrice > 0
        && $convertedSalePrice < $convertedPrice;
    $activePrice = $onSale ? $convertedSalePrice : $convertedPrice;
    $symbol = ecCurrencySymbolFor($targetCurrency);

    return [
        'price' => $convertedPrice,
        'sale_price' => $convertedSalePrice,
        'active_price' => $activePrice,
        'currency' => $targetCurrency,
        'base_currency' => $sourceCurrency,
        'on_sale' => $onSale,
        'formatted' => $activePrice !== null ? ecCurrencyFormatAmount($activePrice, $targetCurrency, $symbol) : null,
        'regular_fmt' => $convertedPrice !== null ? ecCurrencyFormatAmount($convertedPrice, $targetCurrency, $symbol) : null,
    ];
}