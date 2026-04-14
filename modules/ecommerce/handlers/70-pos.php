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

    ecRender('modules/ecommerce/admin/pos.disyl', [
        'page_title'    => 'Point of Sale',
        'user'          => $user,
        'csrf_token'    => app()->csrfToken(),
        'ec_settings'   => ecSettings(),
        'currency_sym'  => (string)ecSettings('currency_symbol'),
    ]);
}
