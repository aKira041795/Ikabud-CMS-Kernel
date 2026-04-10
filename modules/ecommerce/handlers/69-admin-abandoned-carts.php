<?php

declare(strict_types=1);

function ecAdminAbandonedCarts(): void
{
    $user = ecRequireAdmin();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_verify();
        $input = ecInput();
        $settings = getModuleSettings('ecommerce');
        $settings['abandoned_cart_enabled'] = !empty($input['abandoned_cart_enabled']);
        $settings['abandoned_cart_first_delay_hours'] = (string)max(1, (int)($input['abandoned_cart_first_delay_hours'] ?? 1));
        $settings['abandoned_cart_second_delay_hours'] = (string)max(1, (int)($input['abandoned_cart_second_delay_hours'] ?? 24));
        $settings['abandoned_cart_third_delay_hours'] = (string)max(1, (int)($input['abandoned_cart_third_delay_hours'] ?? 72));

        saveModuleSettings('ecommerce', $settings);
        invalidateTenantModuleSettingsCache();
        if (function_exists('ecSettingsResetCache')) {
            ecSettingsResetCache();
        }

        $_SESSION['ec_message'] = ['type' => 'success', 'text' => 'Abandoned cart settings saved.'];
        header('Location: /ecommerce/admin/abandoned-carts');
        exit;
    }

    $ctx = ecAdminContext($user, 'abandoned_carts', [
        'page_title' => 'Ecommerce — Abandoned Carts',
        'message' => $_SESSION['ec_message'] ?? null,
        'abandoned_cart_metrics' => ecAbandonedCartMetrics(),
        'abandoned_carts' => ecAbandonedCartList(75),
        'abandoned_cart_settings' => [
            'enabled' => (bool)ecSettings('abandoned_cart_enabled'),
            'first_delay_hours' => max(1, (int)ecSettings('abandoned_cart_first_delay_hours')),
            'second_delay_hours' => max(1, (int)ecSettings('abandoned_cart_second_delay_hours')),
            'third_delay_hours' => max(1, (int)ecSettings('abandoned_cart_third_delay_hours')),
        ],
        'abandoned_cart_email_templates_url' => ecGetBaseUrl() . '/ecommerce/admin/email-templates',
    ]);
    unset($_SESSION['ec_message']);

    ecRender('modules/ecommerce/admin/abandoned-carts.disyl', $ctx);

    if (function_exists('releaseSessionAfterRender')) {
        releaseSessionAfterRender();
    }
}