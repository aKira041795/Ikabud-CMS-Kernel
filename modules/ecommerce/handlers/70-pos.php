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
    $user = ecRequireAdmin();

    ecRender('modules/ecommerce/admin/pos.disyl', [
        'page_title'    => 'Point of Sale',
        'user'          => $user,
        'csrf_token'    => csrf_token(),
        'ec_settings'   => ecSettings(),
        'currency_sym'  => ecSettings('currency_symbol', '$'),
    ]);
}
