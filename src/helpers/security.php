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
