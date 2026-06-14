<?php
/**
 * Security helpers — thin wrappers that delegate to kernel methods.
 * 
 * These exist so that legacy code calling csrfToken() / csrfField() / csrfEnforce()
 * still works, but the actual implementation lives in the kernel (App.php).
 */

declare(strict_types=1);

function csrfToken(): string
{
    return app()->csrfToken();
}

function csrfField(): string
{
    return app()->csrfField();
}

function csrfEnforce(): void
{
    app()->csrfEnforce();
}

function csrf_verify(): void
{
    app()->csrfEnforce();
}

function clearAuthCookie(string $cookieName): void
{
    setcookie($cookieName, '', [
        'expires' => time() - 3600,
        'path' => '/',
        'httponly' => true,
        'secure' => is_https(),
        'samesite' => config('cookie.samesite', 'Strict'),
    ]);
}

/**
 * CSP nonce for inline script/style tags.
 *
 * Generates a per-request nonce value suitable for use as
 * `<script nonce="{csp_nonce}">` in DiSyL templates.
 *
 * When CSP_NONCE_MODE is enabled, the nonce is included in the
 * Content-Security-Policy header and 'unsafe-inline' is removed
 * from script-src. Templates must carry the matching nonce
 * attribute on every inline <script> tag.
 *
 * Migration path:
 *   1. Set CSP_NONCE_MODE=false (default) — current behavior
 *   2. Add nonce="{csp_nonce}" to all inline <script> tags
 *   3. Set CSP_NONCE_MODE=true — nonce enforces, unsafe-inline removed
 */
function csp_nonce(): string
{
    static $nonce = null;
    if ($nonce === null) {
        try {
            $nonce = bin2hex(random_bytes(16));
        } catch (\Throwable $e) {
            $nonce = bin2hex(uniqid('', true) . random_int(0, PHP_INT_MAX));
        }
    }
    return $nonce;
}

/**
 * Whether CSP nonce enforcement mode is active.
 * Controlled by CSP_NONCE_MODE env var (default: false).
 */
function csp_nonce_mode_enabled(): bool
{
    static $enabled = null;
    if ($enabled === null) {
        $raw = $_ENV['CSP_NONCE_MODE'] ?? $_ENV['APP_CSP_NONCE_MODE'] ?? '';
        $enabled = filter_var($raw, FILTER_VALIDATE_BOOLEAN);
    }
    return $enabled;
}
