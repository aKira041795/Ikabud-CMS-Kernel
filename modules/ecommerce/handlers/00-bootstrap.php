<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────
// Ecommerce Module — Handler Bootstrap (handlers/00-bootstrap.php)
//
// Provides:
//  ecRequireAdmin()       — require CMS admin role (administrator+)
//  ecRequireEditor()      — require editor role (editor+)
//  ecRequireCustomer()    — require logged-in cms user (any role) or redirect
//  ecAdminContext()       — build template context for admin pages
//  ecJsonOk()             — emit JSON success response and exit
//  ecJsonError()          — emit JSON error response and exit
// ─────────────────────────────────────────────────────────────────────────

/**
 * Require CMS administrator or superadmin. Redirects non-admins.
 */
function ecRequireAdmin(): array
{
    // Reuse CMS auth — ecommerce admin lives inside CMS admin chrome
    if (!function_exists('cmsRequireRole')) {
        http_response_code(503);
        echo 'CMS module required for ecommerce admin.';
        exit;
    }
    return cmsRequireRole('administrator');
}

/**
 * Require CMS editor or above.
 */
function ecRequireEditor(): array
{
    if (!function_exists('cmsRequireRole')) {
        http_response_code(503);
        exit;
    }
    return cmsRequireRole('editor');
}

/**
 * Require any logged-in CMS user (subscriber and above).
 * Used for "My Orders", account pages.
 */
function ecRequireCustomer(): array
{
    if (!function_exists('cmsRequireRole')) {
        http_response_code(503);
        exit;
    }
    return cmsRequireRole('subscriber');
}

/**
 * Build base context for ecommerce admin template pages.
 */
function ecAdminContext(array $user, string $currentPage, array $extra = []): array
{
    $ecSettings = ecSettings();
    return array_merge([
        'user'          => $user,
        'current_page'  => $currentPage,
        'page_title'    => 'Ecommerce — ' . ucfirst($currentPage),
        'base_url'      => rtrim((string)(app()->config('app.url', '')), '/'),
        'csrf_token'    => app()->csrfToken(),
        'csrf_field'    => app()->csrfField(),
        'ec_settings'   => $ecSettings,
        'currency'      => $ecSettings['currency']        ?? 'USD',
        'currency_sym'  => $ecSettings['currency_symbol'] ?? '$',
    ], $extra);
}

/**
 * Emit JSON success response and exit.
 */
function ecJsonOk(array $data = [], int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode(array_merge(['ok' => true], $data), JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Emit JSON error response and exit.
 */
function ecJsonError(string $message, int $status = 400, array $extra = []): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode(array_merge(['ok' => false, 'error' => $message], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}
