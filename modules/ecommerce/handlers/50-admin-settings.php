<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────
// Ecommerce Module — Admin Settings (handlers/50-admin-settings.php)
// ─────────────────────────────────────────────────────────────────────────

/**
 * GET  /admin/ecommerce/settings  — settings page
 * POST /admin/ecommerce/settings  — save settings
 */
function ecAdminSettings(): void
{
    $user = ecRequireAdmin();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_verify();
        $input = ecInput();

        $allowed = [
            'currency', 'currency_symbol', 'tax_rate', 'tax_inclusive',
            'low_stock_threshold', 'guest_checkout', 'payment_method_label',
            'order_number_prefix', 'shop_page_title', 'products_per_page',
        ];

        $settings = readTenantModuleSettings('ecommerce');
        foreach ($allowed as $key) {
            if (array_key_exists($key, $input)) {
                $settings[$key] = $input[$key];
            }
        }
        // Normalize booleans
        $settings['tax_inclusive']  = !empty($input['tax_inclusive'])  ? '1' : '0';
        $settings['guest_checkout'] = !empty($input['guest_checkout']) ? '1' : '0';

        saveTenantModuleSettings('ecommerce', $settings);

        $_SESSION['ec_message'] = ['type' => 'success', 'text' => 'Settings saved.'];
        header('Location: /admin/ecommerce/settings');
        exit;
    }

    $ctx = ecAdminContext($user, 'settings', [
        'message' => $_SESSION['ec_message'] ?? null,
    ]);
    unset($_SESSION['ec_message']);

    ecRender('modules/ecommerce/admin/settings.disyl', $ctx);
}
